<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Http;
use App\Exceptions\HttpException;
use PDO;
use Throwable;

final class AuthorizationRequestService
{
    private const MAX_COMMENT_LENGTH = 5000;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditService $audit,
        private readonly MailerService $mailer
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $user, array $input): array
    {
        AuthorizationService::requirePermission($user, 'ticket.request_authorization');
        AuthorizationService::requirePermission($user, 'ticket.assign.director');

        $ticketId = filter_var($input['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        $quotationId = filter_var($input['quotation_id'] ?? null, FILTER_VALIDATE_INT);
        $approverId = filter_var($input['approver_user_id'] ?? null, FILTER_VALIDATE_INT);
        $rowVersion = filter_var($input['row_version'] ?? null, FILTER_VALIDATE_INT);

        if (!is_int($ticketId) || $ticketId < 1) {
            throw new HttpException(404, 'ticket_not_found', 'El ticket no existe.');
        }
        if (!is_int($quotationId) || $quotationId < 1) {
            throw new HttpException(422, 'quotation_required', 'Selecciona una cotización vigente.');
        }
        if (!is_int($approverId) || $approverId < 1) {
            throw new HttpException(422, 'director_required', 'Selecciona al responsable de Dirección.');
        }
        if (!is_int($rowVersion) || $rowVersion < 1) {
            throw new HttpException(422, 'invalid_row_version', 'La versión del ticket no es válida.');
        }

        $comment = $this->normalizeComment((string) ($input['request_comment'] ?? ''));
        $requestedAmount = $this->normalizeAmount((string) ($input['requested_amount'] ?? ''));
        $actorId = (int) ($user['id'] ?? 0);
        $requestId = Http::requestId();
        $directorRecipient = null;

        $this->pdo->beginTransaction();
        try {
            $ticket = $this->loadTicketForUpdate($ticketId);
            $this->assertTicketAccess($user, $ticket);
            $this->assertSupervisorControl($user, $ticket);

            if ((int) $ticket['row_version'] !== $rowVersion) {
                throw new HttpException(409, 'ticket_changed', 'El ticket fue actualizado por otro usuario. Recarga el expediente antes de continuar.');
            }
            if ((string) $ticket['processing_route'] !== 'authorization_required') {
                throw new HttpException(422, 'authorization_route_required', 'Este ticket no pertenece a la ruta de cotización y autorización.');
            }
            if ((string) $ticket['status_code'] !== 'quotation_pending') {
                throw new HttpException(422, 'authorization_status_invalid', 'La autorización solo puede solicitarse desde Cotización pendiente.');
            }

            $quotation = $this->loadCurrentQuotationForUpdate($ticketId, $quotationId);
            if ($quotation === null) {
                throw new HttpException(422, 'quotation_not_current', 'La cotización seleccionada ya no es la versión vigente. Recarga el expediente.');
            }
            if ($requestedAmount > (float) $quotation['amount']) {
                throw new HttpException(422, 'requested_amount_exceeds_quotation', 'El importe solicitado no puede exceder el total de la cotización vigente.');
            }

            $director = $this->loadEligibleDirector($approverId);
            if ($director === null) {
                throw new HttpException(422, 'director_not_eligible', 'El usuario seleccionado no es un responsable activo de Dirección.');
            }

            if ($this->hasPendingApprovalRequest($ticketId)) {
                throw new HttpException(409, 'approval_request_exists', 'El ticket ya cuenta con una solicitud de autorización pendiente.');
            }

            $transition = $this->findTransition((int) $ticket['current_status_id'], 'authorization_pending');
            AuthorizationService::requirePermission($user, (string) $transition['permission_code']);

            if ($ticket['supervisor_user_id'] === null) {
                throw new HttpException(422, 'supervisor_required', 'El ticket requiere un supervisor asignado.');
            }
            if ((bool) $transition['requires_quotation'] && $quotation === null) {
                throw new HttpException(422, 'quotation_required', 'El ticket requiere una cotización vigente.');
            }

            $pendingStatusId = $this->approvalStatusId('pending');
            $supersedesRequestId = $this->latestChangesRequestedId($ticketId);

            $insertRequest = $this->pdo->prepare(
                'INSERT INTO ticket_approval_requests (
                    ticket_id, quotation_id, status_id,
                    requested_by_user_id, approver_user_id, supersedes_request_id,
                    request_comment, requested_amount
                 ) VALUES (
                    :ticket_id, :quotation_id, :status_id,
                    :requested_by_user_id, :approver_user_id, :supersedes_request_id,
                    :request_comment, :requested_amount
                 )'
            );
            $insertRequest->execute([
                'ticket_id' => $ticketId,
                'quotation_id' => $quotationId,
                'status_id' => $pendingStatusId,
                'requested_by_user_id' => $actorId,
                'approver_user_id' => $approverId,
                'supersedes_request_id' => $supersedesRequestId,
                'request_comment' => $comment,
                'requested_amount' => number_format($requestedAmount, 2, '.', ''),
            ]);
            $approvalRequestId = (int) $this->pdo->lastInsertId();

            $previousDirectorId = $ticket['director_user_id'] !== null ? (int) $ticket['director_user_id'] : null;
            $previousOwnerId = $ticket['action_owner_user_id'] !== null ? (int) $ticket['action_owner_user_id'] : null;

            $updateTicket = $this->pdo->prepare(
                'UPDATE tickets
                 SET director_user_id = :director_user_id,
                     action_owner_user_id = :action_owner_user_id,
                     current_status_id = :to_status_id,
                     row_version = row_version + 1,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id
                   AND current_status_id = :from_status_id
                   AND row_version = :row_version'
            );
            $updateTicket->execute([
                'director_user_id' => $approverId,
                'action_owner_user_id' => $approverId,
                'to_status_id' => (int) $transition['to_status_id'],
                'id' => $ticketId,
                'from_status_id' => (int) $ticket['current_status_id'],
                'row_version' => $rowVersion,
            ]);
            if ($updateTicket->rowCount() !== 1) {
                throw new HttpException(409, 'ticket_changed', 'El ticket fue actualizado por otro usuario. Recarga el expediente antes de continuar.');
            }

            if ($previousDirectorId !== $approverId) {
                $this->insertAssignmentHistory(
                    $ticketId,
                    'director',
                    $previousDirectorId,
                    $approverId,
                    $actorId,
                    'Asignación de Dirección al solicitar autorización.'
                );
            }
            if ($previousOwnerId !== $approverId) {
                $this->insertAssignmentHistory(
                    $ticketId,
                    'action_owner',
                    $previousOwnerId,
                    $approverId,
                    $actorId,
                    'Dirección queda responsable de revisar la solicitud de autorización.'
                );
            }

            $metadata = json_encode([
                'request_id' => $requestId,
                'approval_request_id' => $approvalRequestId,
                'quotation_id' => $quotationId,
                'quotation_version' => (int) $quotation['version_number'],
                'requested_amount' => number_format($requestedAmount, 2, '.', ''),
                'approver_user_id' => $approverId,
                'supersedes_request_id' => $supersedesRequestId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $history = $this->pdo->prepare(
                'INSERT INTO ticket_status_history (
                    ticket_id, from_status_id, to_status_id, changed_by_user_id,
                    comment, change_source, metadata_json
                 ) VALUES (
                    :ticket_id, :from_status_id, :to_status_id, :changed_by_user_id,
                    :comment, \'user\', :metadata_json
                 )'
            );
            $history->execute([
                'ticket_id' => $ticketId,
                'from_status_id' => (int) $ticket['current_status_id'],
                'to_status_id' => (int) $transition['to_status_id'],
                'changed_by_user_id' => $actorId,
                'comment' => $comment,
                'metadata_json' => $metadata,
            ]);

            if ($approverId !== $actorId) {
                $this->insertNotification(
                    $approverId,
                    $ticketId,
                    $actorId,
                    'ticket.authorization_requested',
                    'Autorización pendiente: ' . (string) $ticket['folio'],
                    'Se requiere tu revisión de la cotización versión ' . (int) $quotation['version_number'] . '.',
                    './ticket.html?id=' . $ticketId,
                    sprintf('ticket.authorization:%d:%d:%d', $ticketId, $approvalRequestId, $approverId)
                );
                $directorRecipient = $director;
            }



            $this->audit->record(
                'ticket.authorization.request',
                'ticket_approval_request',
                $approvalRequestId,
                $actorId,
                $ticketId,
                [
                    'status_code' => (string) $ticket['status_code'],
                    'director_user_id' => $previousDirectorId,
                    'action_owner_user_id' => $previousOwnerId,
                    'row_version' => $rowVersion,
                ],
                [
                    'approval_request_id' => $approvalRequestId,
                    'quotation_id' => $quotationId,
                    'quotation_version' => (int) $quotation['version_number'],
                    'requested_amount' => number_format($requestedAmount, 2, '.', ''),
                    'approver_user_id' => $approverId,
                    'status_code' => 'authorization_pending',
                    'row_version' => $rowVersion + 1,
                    'supersedes_request_id' => $supersedesRequestId,
                ],
                $requestId
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        if (is_array($directorRecipient)) {
            try {
                $this->mailer->sendAuthorizationRequestToDirector(
                    $ticketId,
                    (int) $directorRecipient['id'],
                    (string) $directorRecipient['email'],
                    (string) $directorRecipient['full_name'],
                    (string) $user['full_name'],
                    (string) $ticket['folio'],
                    (string) $ticket['title'],
                    (int) $quotation['version_number'],
                    (string) $quotation['supplier_name'],
                    number_format($requestedAmount, 2, '.', ''),
                    (string) $quotation['currency'],
                    $comment
                );
            } catch (Throwable) {
                $this->audit->safeRecord(
                    'ticket.email.authorization_request_failed',
                    'ticket_approval_request',
                    $approvalRequestId,
                    $actorId,
                    ['ticket_id' => $ticketId, 'recipient_user_id' => (int) $directorRecipient['id']]
                );
            }
        }



        return [
            'approval_request_id' => $approvalRequestId,
            'quotation_id' => $quotationId,
            'approver_user_id' => $approverId,
            'row_version' => $rowVersion + 1,
        ];
    }

    /** @return array<string, mixed> */
    private function loadTicketForUpdate(int $ticketId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.*, s.code AS status_code, s.name AS status_name
             FROM tickets t
             INNER JOIN ticket_statuses s ON s.id = t.current_status_id
             WHERE t.id = :id
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['id' => $ticketId]);
        $ticket = $statement->fetch();
        if (!is_array($ticket)) {
            throw new HttpException(404, 'ticket_not_found', 'El ticket no existe.');
        }
        return $ticket;
    }

    /** @return array<string, mixed>|null */
    private function loadCurrentQuotationForUpdate(int $ticketId, int $quotationId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, version_number, supplier_name, amount, currency, status, is_current
             FROM ticket_quotations
             WHERE id = :quotation_id
               AND ticket_id = :ticket_id
               AND is_current = 1
               AND status = \'current\'
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['quotation_id' => $quotationId, 'ticket_id' => $ticketId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function loadEligibleDirector(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT u.id, CONCAT_WS(" ", u.first_name, u.last_name) AS full_name, u.email
             FROM users u
INNER JOIN user_application_roles uar
    ON uar.user_id = u.id
   AND uar.revoked_at IS NULL
INNER JOIN applications a
    ON a.id = uar.application_id
   AND a.code = \'maintenance\'
   AND a.is_active = 1
INNER JOIN application_roles r
    ON r.id = uar.role_id
   AND r.application_id = uar.application_id
   AND r.is_active = 1
WHERE u.id = :user_id
  AND u.status = \'active\'
  AND r.code = \'director\'
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'full_name' => trim((string) $row['full_name']),
            'email' => (string) $row['email'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function loadActiveUser(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, CONCAT_WS(" ", first_name, last_name) AS full_name, email
             FROM users
             WHERE id = :id AND status = \'active\'
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'full_name' => trim((string) $row['full_name']),
            'email' => (string) $row['email'],
        ];
    }

    private function hasPendingApprovalRequest(int $ticketId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM ticket_approval_requests ar
             INNER JOIN approval_statuses s ON s.id = ar.status_id
             WHERE ar.ticket_id = :ticket_id AND s.code = \'pending\'
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['ticket_id' => $ticketId]);
        return $statement->fetchColumn() !== false;
    }

    private function latestChangesRequestedId(int $ticketId): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT ar.id
             FROM ticket_approval_requests ar
             INNER JOIN approval_statuses s ON s.id = ar.status_id
             WHERE ar.ticket_id = :ticket_id AND s.code = \'changes_requested\'
             ORDER BY ar.requested_at DESC, ar.id DESC
             LIMIT 1'
        );
        $statement->execute(['ticket_id' => $ticketId]);
        $id = $statement->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    private function approvalStatusId(string $code): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM approval_statuses WHERE code = :code LIMIT 1');
        $statement->execute(['code' => $code]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new HttpException(500, 'approval_catalog_missing', 'El catálogo de autorizaciones no está configurado correctamente.');
        }
        return (int) $id;
    }

    /** @return array<string, mixed> */
    private function findTransition(int $fromStatusId, string $toStatusCode): array
    {
        $statement = $this->pdo->prepare(
            'SELECT tr.*, p.code AS permission_code, ts.code AS to_status_code, ts.name AS to_status_name
             FROM ticket_status_transitions tr
             INNER JOIN permissions p ON p.id = tr.required_permission_id
             INNER JOIN ticket_statuses ts ON ts.id = tr.to_status_id AND ts.is_active = 1
             WHERE tr.from_status_id = :from_status_id
               AND ts.code = :to_status_code
               AND tr.is_active = 1
             LIMIT 1'
        );
        $statement->execute(['from_status_id' => $fromStatusId, 'to_status_code' => $toStatusCode]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new HttpException(422, 'invalid_transition', 'El cambio a Pendiente de autorización no está permitido.');
        }
        return $row;
    }

    private function insertAssignmentHistory(
        int $ticketId,
        string $type,
        ?int $previousUserId,
        ?int $newUserId,
        int $actorId,
        string $comment
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO ticket_assignment_history (
                ticket_id, assignment_type, previous_user_id, new_user_id, assigned_by_user_id, comment
             ) VALUES (
                :ticket_id, :assignment_type, :previous_user_id, :new_user_id, :assigned_by_user_id, :comment
             )'
        );
        $statement->execute([
            'ticket_id' => $ticketId,
            'assignment_type' => $type,
            'previous_user_id' => $previousUserId,
            'new_user_id' => $newUserId,
            'assigned_by_user_id' => $actorId,
            'comment' => $comment,
        ]);
    }

    private function insertNotification(
        int $userId,
        int $ticketId,
        int $actorId,
        string $type,
        string $title,
        string $message,
        string $url,
        string $key
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO notifications (
                user_id, ticket_id, actor_user_id, type, title, message, action_url, deduplication_key
             ) VALUES (
                :user_id, :ticket_id, :actor_user_id, :type, :title, :message, :action_url, :deduplication_key
             )'
        );
        $statement->execute([
            'user_id' => $userId,
            'ticket_id' => $ticketId,
            'actor_user_id' => $actorId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'action_url' => $url,
            'deduplication_key' => $key,
        ]);
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $ticket */
    private function assertTicketAccess(array $user, array $ticket): void
    {
        $permissions = is_array($user['permissions'] ?? null) ? $user['permissions'] : [];
        $userId = (int) ($user['id'] ?? 0);
        $allowed = in_array('ticket.view.all', $permissions, true)
            || (in_array('ticket.view.own', $permissions, true) && (int) $ticket['reported_by_user_id'] === $userId)
            || (
                in_array('ticket.view.assigned', $permissions, true)
                && in_array($userId, [
                    (int) ($ticket['supervisor_user_id'] ?? 0),
                    (int) ($ticket['director_user_id'] ?? 0),
                    (int) ($ticket['action_owner_user_id'] ?? 0),
                ], true)
            );
        if (!$allowed) {
            throw new HttpException(404, 'ticket_not_found', 'El ticket no existe.');
        }
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $ticket */
    private function assertSupervisorControl(array $user, array $ticket): void
    {
        $roles = $user['roles'] ?? [];
        if (!is_array($roles) || !in_array('supervisor', $roles, true)) {
            throw new HttpException(403, 'supervisor_role_required', 'Esta acción corresponde al perfil Supervisor.');
        }

        $userId = (int) ($user['id'] ?? 0);
        if (
            $userId < 1
            || $userId !== (int) ($ticket['supervisor_user_id'] ?? 0)
            || $userId !== (int) ($ticket['action_owner_user_id'] ?? 0)
        ) {
            throw new HttpException(403, 'supervisor_action_required', 'Solo el supervisor responsable puede solicitar la autorización del ticket.');
        }
    }

    private function normalizeComment(string $value): string
    {
        $value = preg_replace('/\R/u', "\n", trim($value)) ?? trim($value);
        $length = $this->textLength($value);
        if ($length < 5) {
            throw new HttpException(422, 'request_comment_required', 'Agrega una justificación de al menos 5 caracteres.');
        }
        if ($length > self::MAX_COMMENT_LENGTH) {
            throw new HttpException(422, 'request_comment_too_long', 'La justificación no puede exceder 5000 caracteres.');
        }
        return $value;
    }

    private function normalizeAmount(string $value): float
    {
        $value = trim(str_replace([',', ' '], '', $value));
        if ($value === '' || !is_numeric($value)) {
            throw new HttpException(422, 'requested_amount_invalid', 'Indica un importe solicitado válido.');
        }
        $amount = round((float) $value, 2);
        if ($amount <= 0 || $amount > 999999999999.99) {
            throw new HttpException(422, 'requested_amount_invalid', 'El importe solicitado debe ser mayor a cero.');
        }
        return $amount;
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
