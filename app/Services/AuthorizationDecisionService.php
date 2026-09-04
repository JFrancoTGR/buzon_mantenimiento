<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Http;
use App\Exceptions\HttpException;
use PDO;
use Throwable;

final class AuthorizationDecisionService
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
    public function decide(array $user, array $input): array
    {
        $ticketId = filter_var($input['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        $approvalRequestId = filter_var($input['approval_request_id'] ?? null, FILTER_VALIDATE_INT);
        $rowVersion = filter_var($input['row_version'] ?? null, FILTER_VALIDATE_INT);
        $decision = trim((string) ($input['decision'] ?? ''));

        if (!is_int($ticketId) || $ticketId < 1) {
            throw new HttpException(404, 'ticket_not_found', 'El ticket no existe.');
        }
        if (!is_int($approvalRequestId) || $approvalRequestId < 1) {
            throw new HttpException(422, 'approval_request_required', 'La solicitud de autorización no es válida.');
        }
        if (!is_int($rowVersion) || $rowVersion < 1) {
            throw new HttpException(422, 'invalid_row_version', 'La versión del ticket no es válida.');
        }

        $decisionMap = [
            'approved' => [
                'permission' => 'ticket.authorize',
                'ticket_status' => 'authorized',
                'approval_status' => 'approved',
                'label' => 'Autorizado',
            ],
            'rejected' => [
                'permission' => 'ticket.reject',
                'ticket_status' => 'rejected',
                'approval_status' => 'rejected',
                'label' => 'Rechazado',
            ],
            'changes_requested' => [
                'permission' => 'ticket.request_changes',
                'ticket_status' => 'changes_requested',
                'approval_status' => 'changes_requested',
                'label' => 'Correcciones solicitadas',
            ],
        ];

        if (!isset($decisionMap[$decision])) {
            throw new HttpException(422, 'invalid_decision', 'Selecciona una decisión válida.');
        }

        $config = $decisionMap[$decision];
        AuthorizationService::requirePermission($user, $config['permission']);

        $comment = $this->normalizeComment((string) ($input['decision_comment'] ?? ''));
        $actorId = (int) ($user['id'] ?? 0);
        $requestId = Http::requestId();
        $approvedAmount = null;
        $recipients = [];

        $this->pdo->beginTransaction();
        try {
            $ticket = $this->loadTicketForUpdate($ticketId);
            $this->assertTicketAccess($user, $ticket);

            if ((int) $ticket['row_version'] !== $rowVersion) {
                throw new HttpException(409, 'ticket_changed', 'El ticket fue actualizado por otro usuario. Recarga el expediente antes de continuar.');
            }
            if ((string) $ticket['processing_route'] !== 'authorization_required') {
                throw new HttpException(422, 'authorization_route_required', 'Este ticket no pertenece a la ruta de autorización.');
            }
            if ((string) $ticket['status_code'] !== 'authorization_pending') {
                throw new HttpException(422, 'authorization_status_invalid', 'La solicitud solo puede resolverse mientras el ticket está pendiente de autorización.');
            }

            $approval = $this->loadPendingApprovalForUpdate($ticketId, $approvalRequestId);
            if ($approval === null) {
                throw new HttpException(409, 'approval_request_not_pending', 'La solicitud ya no está pendiente. Recarga el expediente.');
            }

            $this->assertDirectorControl($user, $ticket, $approval);

            $transition = $this->findTransition((int) $ticket['current_status_id'], $config['ticket_status']);
            AuthorizationService::requirePermission($user, (string) $transition['permission_code']);

            if ((bool) $transition['requires_supervisor'] && $ticket['supervisor_user_id'] === null) {
                throw new HttpException(422, 'supervisor_required', 'El ticket requiere un supervisor asignado.');
            }
            if ((bool) $transition['requires_director'] && $ticket['director_user_id'] === null) {
                throw new HttpException(422, 'director_required', 'El ticket requiere un responsable de Dirección.');
            }
            if ((bool) $transition['requires_quotation'] && !$this->quotationExists((int) $approval['quotation_id'])) {
                throw new HttpException(422, 'quotation_required', 'La solicitud requiere una cotización válida.');
            }

            if ($decision === 'approved') {
                $approvedAmount = $this->normalizeAmount((string) ($input['approved_amount'] ?? ''));
                if ($approvedAmount > (float) $approval['requested_amount']) {
                    throw new HttpException(422, 'approved_amount_exceeds_request', 'El importe autorizado no puede exceder el importe solicitado.');
                }
            }

            $approvalStatusId = $this->approvalStatusId($config['approval_status']);
            $updateApproval = $this->pdo->prepare(
                'UPDATE ticket_approval_requests
                 SET status_id = :status_id,
                     decided_by_user_id = :decided_by_user_id,
                     decision_comment = :decision_comment,
                     approved_amount = :approved_amount,
                     responded_at = UTC_TIMESTAMP(),
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id
                   AND ticket_id = :ticket_id
                   AND status_id = :pending_status_id'
            );
            $updateApproval->execute([
                'status_id' => $approvalStatusId,
                'decided_by_user_id' => $actorId,
                'decision_comment' => $comment,
                'approved_amount' => $approvedAmount !== null ? number_format($approvedAmount, 2, '.', '') : null,
                'id' => $approvalRequestId,
                'ticket_id' => $ticketId,
                'pending_status_id' => (int) $approval['status_id'],
            ]);
            if ($updateApproval->rowCount() !== 1) {
                throw new HttpException(409, 'approval_request_changed', 'La solicitud fue actualizada por otro usuario. Recarga el expediente.');
            }

            if ($decision === 'approved') {
                $quotationUpdate = $this->pdo->prepare(
                    'UPDATE ticket_quotations
                     SET status = \'approved\', updated_at = UTC_TIMESTAMP()
                     WHERE id = :quotation_id AND ticket_id = :ticket_id AND is_current = 1'
                );
                $quotationUpdate->execute([
                    'quotation_id' => (int) $approval['quotation_id'],
                    'ticket_id' => $ticketId,
                ]);
            }

            $previousOwnerId = $ticket['action_owner_user_id'] !== null ? (int) $ticket['action_owner_user_id'] : null;
            $newOwnerId = $decision === 'rejected'
                ? null
                : ($ticket['supervisor_user_id'] !== null ? (int) $ticket['supervisor_user_id'] : null);

            $authorizedAtExpression = $decision === 'approved' ? 'UTC_TIMESTAMP()' : 'authorized_at';
            $updateTicket = $this->pdo->prepare(
                'UPDATE tickets
                 SET current_status_id = :to_status_id,
                     action_owner_user_id = :action_owner_user_id,
                     authorized_at = ' . $authorizedAtExpression . ',
                     row_version = row_version + 1,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id
                   AND current_status_id = :from_status_id
                   AND row_version = :row_version'
            );
            $updateTicket->execute([
                'to_status_id' => (int) $transition['to_status_id'],
                'action_owner_user_id' => $newOwnerId,
                'id' => $ticketId,
                'from_status_id' => (int) $ticket['current_status_id'],
                'row_version' => $rowVersion,
            ]);
            if ($updateTicket->rowCount() !== 1) {
                throw new HttpException(409, 'ticket_changed', 'El ticket fue actualizado por otro usuario. Recarga el expediente.');
            }

            if ($previousOwnerId !== $newOwnerId) {
                $assignmentComment = match ($decision) {
                    'approved' => 'La autorización fue aprobada. El supervisor recupera la responsabilidad para iniciar la ejecución.',
                    'changes_requested' => 'Dirección solicitó cambios. El supervisor recupera la responsabilidad para preparar una nueva propuesta.',
                    default => 'La solicitud fue rechazada y el ticket queda sin una acción operativa pendiente.',
                };
                $this->insertAssignmentHistory(
                    $ticketId,
                    'action_owner',
                    $previousOwnerId,
                    $newOwnerId,
                    $actorId,
                    $assignmentComment
                );
            }

            $metadata = json_encode([
                'request_id' => $requestId,
                'approval_request_id' => $approvalRequestId,
                'quotation_id' => (int) $approval['quotation_id'],
                'decision' => $decision,
                'requested_amount' => (string) $approval['requested_amount'],
                'approved_amount' => $approvedAmount !== null ? number_format($approvedAmount, 2, '.', '') : null,
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

            $recipients = $this->decisionRecipients($ticket, $approval, $actorId);
            foreach ($recipients as $recipient) {
                $this->insertNotification(
                    (int) $recipient['id'],
                    $ticketId,
                    $actorId,
                    'ticket.authorization_decided',
                    (string) $ticket['folio'] . ' · ' . $config['label'],
                    $this->notificationMessage($decision, (string) $user['full_name']),
                    './ticket.html?id=' . $ticketId,
                    sprintf('ticket.authorization.decision:%d:%d:%s:%d', $ticketId, $approvalRequestId, $decision, (int) $recipient['id'])
                );
            }

            $this->audit->record(
                'ticket.authorization.decision',
                'ticket_approval_request',
                $approvalRequestId,
                $actorId,
                $ticketId,
                [
                    'approval_status' => 'pending',
                    'ticket_status' => (string) $ticket['status_code'],
                    'action_owner_user_id' => $previousOwnerId,
                    'row_version' => $rowVersion,
                ],
                [
                    'approval_status' => $config['approval_status'],
                    'ticket_status' => $config['ticket_status'],
                    'decision' => $decision,
                    'approved_amount' => $approvedAmount !== null ? number_format($approvedAmount, 2, '.', '') : null,
                    'action_owner_user_id' => $newOwnerId,
                    'row_version' => $rowVersion + 1,
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

        foreach ($recipients as $recipient) {
            try {
                $this->mailer->sendAuthorizationDecisionNotification(
                    $ticketId,
                    (int) $recipient['id'],
                    (string) $recipient['email'],
                    (string) $recipient['full_name'],
                    (string) $user['full_name'],
                    (string) $ticket['folio'],
                    (string) $ticket['title'],
                    $decision,
                    (string) $approval['requested_amount'],
                    $approvedAmount !== null ? number_format($approvedAmount, 2, '.', '') : null,
                    (string) $approval['currency'],
                    $comment
                );
            } catch (Throwable) {
                $this->audit->safeRecord(
                    'ticket.email.authorization_decision_failed',
                    'ticket_approval_request',
                    $approvalRequestId,
                    $actorId,
                    ['ticket_id' => $ticketId, 'recipient_user_id' => (int) $recipient['id'], 'decision' => $decision]
                );
            }
        }

        return [
            'approval_request_id' => $approvalRequestId,
            'decision' => $decision,
            'ticket_status' => $config['ticket_status'],
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
    private function loadPendingApprovalForUpdate(int $ticketId, int $requestId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ar.*, aps.code AS approval_status_code,
                    q.version_number, q.supplier_name, q.amount AS quotation_amount, q.currency
             FROM ticket_approval_requests ar
             INNER JOIN approval_statuses aps ON aps.id = ar.status_id
             INNER JOIN ticket_quotations q ON q.id = ar.quotation_id
             WHERE ar.id = :request_id
               AND ar.ticket_id = :ticket_id
               AND aps.code = \'pending\'
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['request_id' => $requestId, 'ticket_id' => $ticketId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $ticket @param array<string, mixed> $approval */
    private function assertDirectorControl(array $user, array $ticket, array $approval): void
    {
        $roles = $user['roles'] ?? [];
        $isDirector = is_array($roles) && in_array('director', $roles, true);
        $userId = (int) ($user['id'] ?? 0);

        if (
            !$isDirector
            || $userId < 1
            || $userId !== (int) ($ticket['director_user_id'] ?? 0)
            || $userId !== (int) ($ticket['action_owner_user_id'] ?? 0)
            || $userId !== (int) ($approval['approver_user_id'] ?? 0)
        ) {
            throw new HttpException(403, 'director_action_required', 'Solo el responsable de Dirección asignado puede resolver esta solicitud.');
        }
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
            throw new HttpException(422, 'invalid_transition', 'La decisión seleccionada no está permitida para el estado actual del ticket.');
        }
        return $row;
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

    private function quotationExists(int $quotationId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM ticket_quotations WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $quotationId]);
        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $ticket @param array<string, mixed> $approval @return array<int, array<string, mixed>> */
    private function decisionRecipients(array $ticket, array $approval, int $actorId): array
    {
        $ids = [];
        foreach ([
            $ticket['supervisor_user_id'] ?? null,
            $approval['requested_by_user_id'] ?? null,
        ] as $id) {
            if ($id !== null && (int) $id > 0 && (int) $id !== $actorId) {
                $ids[(int) $id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id, CONCAT_WS(' ', first_name, last_name) AS full_name, email
             FROM users
             WHERE status = 'active' AND id IN ({$placeholders})"
        );
        $statement->execute(array_keys($ids));

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'full_name' => trim((string) $row['full_name']),
            'email' => (string) $row['email'],
        ], $statement->fetchAll());
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

    private function notificationMessage(string $decision, string $directorName): string
    {
        return match ($decision) {
            'approved' => $directorName . ' autorizó la solicitud. El supervisor puede continuar con la ejecución.',
            'changes_requested' => $directorName . ' solicitó cambios a la propuesta. El supervisor debe preparar una nueva versión.',
            default => $directorName . ' rechazó la solicitud de autorización.',
        };
    }

    private function normalizeComment(string $value): string
    {
        $value = preg_replace('/\R/u', "\n", trim($value)) ?? trim($value);
        $length = $this->textLength($value);
        if ($length < 5) {
            throw new HttpException(422, 'decision_comment_required', 'Agrega un comentario de al menos 5 caracteres.');
        }
        if ($length > self::MAX_COMMENT_LENGTH) {
            throw new HttpException(422, 'decision_comment_too_long', 'El comentario no puede exceder 5000 caracteres.');
        }
        return $value;
    }

    private function normalizeAmount(string $value): float
    {
        $value = trim(str_replace([',', ' '], '', $value));
        if ($value === '' || !is_numeric($value)) {
            throw new HttpException(422, 'approved_amount_invalid', 'Indica un importe autorizado válido.');
        }
        $amount = round((float) $value, 2);
        if ($amount <= 0 || $amount > 999999999999.99) {
            throw new HttpException(422, 'approved_amount_invalid', 'El importe autorizado debe ser mayor a cero.');
        }
        return $amount;
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
