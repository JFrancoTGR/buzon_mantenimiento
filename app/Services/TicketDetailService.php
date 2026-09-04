<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Http;
use App\Exceptions\HttpException;
use PDO;
use RuntimeException;
use Throwable;

final class TicketDetailService
{
    private const MAX_COMMENT_LENGTH = 3000;
    private const MAX_IMAGE_PIXELS = 40000000;
    private const ENABLED_TRANSITIONS = ['under_review'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditService $audit,
        private readonly MailerService $mailer
    ) {
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    public function detail(array $user, int $ticketId): array
    {
        $ticket = $this->loadTicket($ticketId);
        $this->assertTicketAccess($user, $ticket);

        $canViewQuotations = $this->canViewQuotations($user, $ticket);
        $canViewApprovals = $this->canViewApprovals($user, $ticket);
        $canRequestAuthorization = $this->canRequestAuthorization($user, $ticket);
        $isReporterView = TicketPresentationPolicy::isReporter($user);
        $formattedTicket = $this->formatTicket($ticket);
        $statusHistory = $this->loadStatusHistory($ticketId);

        if ($isReporterView) {
            $formattedTicket = $this->formatReporterTicket($formattedTicket);
            $statusHistory = $this->publicStatusHistory($statusHistory);
        }

        return [
            'ticket' => $formattedTicket,
            'attachments' => $this->loadAttachments($ticketId),
            'completion_evidence' => $this->loadCompletionEvidence($ticketId),
            'quotations' => $canViewQuotations ? $this->loadQuotations($ticketId) : [],
            'approval_requests' => $canViewApprovals ? $this->loadApprovalRequests($ticketId) : [],
            'eligible_directors' => $canRequestAuthorization ? $this->loadEligibleDirectors() : [],
            'comments' => $this->loadComments($ticketId),
            'status_history' => $statusHistory,
            'assignment_history' => $isReporterView ? [] : $this->loadAssignmentHistory($ticketId),
            'capabilities' => [
                'is_reporter_view' => $isReporterView,
                'can_comment' => AuthorizationService::hasPermission($user, 'ticket.comment'),
                'can_select_route' => $this->canSelectRoute($user, $ticket),
                'can_view_quotations' => $canViewQuotations,
                'can_upload_quotation' => $this->canUploadQuotation($user, $ticket),
                'can_view_approvals' => $canViewApprovals,
                'can_request_authorization' => $canRequestAuthorization,
                'can_authorize' => $this->canDecideAuthorization($user, $ticket, 'ticket.authorize'),
                'can_reject' => $this->canDecideAuthorization($user, $ticket, 'ticket.reject'),
                'can_request_changes' => $this->canDecideAuthorization($user, $ticket, 'ticket.request_changes'),
                'can_start_execution' => $this->canStartExecution($user, $ticket),
                'can_complete_work' => $this->canCompleteWork($user, $ticket),
                'allowed_transitions' => $this->allowedTransitions($user, $ticket),
            ],
        ];
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    public function addComment(array $user, int $ticketId, string $body): array
    {
        AuthorizationService::requirePermission($user, 'ticket.comment');
        $ticket = $this->loadTicket($ticketId);
        $this->assertTicketAccess($user, $ticket);
        $body = $this->normalizeComment($body);
        $requestId = Http::requestId();
        $actorId = (int) $user['id'];

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO ticket_comments (ticket_id, author_user_id, body, comment_type, is_system)
                 VALUES (:ticket_id, :author_user_id, :body, \'general\', 0)'
            );
            $insert->execute([
                'ticket_id' => $ticketId,
                'author_user_id' => $actorId,
                'body' => $body,
            ]);
            $commentId = (int) $this->pdo->lastInsertId();

            $recipients = $this->participantRecipients($ticket, $actorId);
            foreach ($recipients as $recipient) {
                $this->insertNotification(
                    (int) $recipient['id'],
                    $ticketId,
                    $actorId,
                    'ticket.comment_added',
                    'Nuevo comentario en ' . (string) $ticket['folio'],
                    (string) $user['full_name'] . ' agregó un comentario al reporte.',
                    './ticket.html?id=' . $ticketId,
                    sprintf('ticket.comment:%d:%d:%d', $ticketId, $commentId, (int) $recipient['id'])
                );
            }

            $this->audit->record(
                'ticket.comment.create',
                'ticket_comment',
                $commentId,
                $actorId,
                $ticketId,
                null,
                ['comment_type' => 'general', 'length' => $this->textLength($body)],
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
                $this->mailer->sendTicketCommentNotification(
                    $ticketId,
                    (int) $recipient['id'],
                    (string) $recipient['email'],
                    (string) $recipient['full_name'],
                    (string) $user['full_name'],
                    (string) $ticket['folio'],
                    (string) $ticket['title'],
                    $body
                );
            } catch (Throwable) {
                $this->audit->safeRecord(
                    'ticket.email.comment_failed',
                    'ticket',
                    $ticketId,
                    $actorId,
                    ['recipient_user_id' => (int) $recipient['id']]
                );
            }
        }

        return [
            'comment' => $this->loadComment($commentId),
            'notifications_created' => count($recipients),
        ];
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    public function transition(array $user, int $ticketId, string $toStatusCode, ?string $comment, int $rowVersion): array
    {
        if (!in_array($toStatusCode, self::ENABLED_TRANSITIONS, true)) {
            throw new HttpException(422, 'transition_not_available', 'Esta transición todavía no está disponible en la plataforma.');
        }

        $actorId = (int) $user['id'];
        $requestId = Http::requestId();
        $notificationRecipients = [];

        $this->pdo->beginTransaction();
        try {
            $ticket = $this->loadTicket($ticketId, true);
            $this->assertTicketAccess($user, $ticket);
            $this->assertOperationalAssignment($user, $ticket);

            if ((int) $ticket['row_version'] !== $rowVersion) {
                throw new HttpException(409, 'ticket_changed', 'El ticket fue actualizado por otro usuario. Recarga la página.');
            }

            $transition = $this->findTransition((int) $ticket['current_status_id'], $toStatusCode);
            AuthorizationService::requirePermission($user, (string) $transition['permission_code']);
            $this->validateTransitionRequirements($transition, $ticket);

            $normalizedComment = $comment === null ? '' : trim($comment);
            if ((bool) $transition['requires_comment'] && $normalizedComment === '') {
                throw new HttpException(422, 'comment_required', 'Agrega un comentario para realizar este cambio.');
            }
            if ($normalizedComment !== '') {
                $normalizedComment = $this->normalizeComment($normalizedComment);
            } else {
                $normalizedComment = null;
            }

            $update = $this->pdo->prepare(
                'UPDATE tickets
                 SET current_status_id = :to_status_id,
                     action_owner_user_id = :action_owner_user_id,
                     row_version = row_version + 1,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id
                   AND current_status_id = :from_status_id
                   AND row_version = :row_version'
            );
            $update->execute([
                'to_status_id' => (int) $transition['to_status_id'],
                'action_owner_user_id' => $ticket['supervisor_user_id'],
                'id' => $ticketId,
                'from_status_id' => (int) $ticket['current_status_id'],
                'row_version' => $rowVersion,
            ]);

            if ($update->rowCount() !== 1) {
                throw new HttpException(409, 'ticket_changed', 'El ticket fue actualizado por otro usuario. Recarga la página.');
            }

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
                'comment' => $normalizedComment,
                'metadata_json' => json_encode(
                    ['request_id' => $requestId],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
            ]);

            $notificationRecipients = $this->participantRecipients($ticket, $actorId, true);
            foreach ($notificationRecipients as $recipient) {
                $this->insertNotification(
                    (int) $recipient['id'],
                    $ticketId,
                    $actorId,
                    'ticket.status_changed',
                    (string) $ticket['folio'] . ' ahora está En gestión',
                    'El reporte se encuentra en revisión por el equipo responsable.',
                    './ticket.html?id=' . $ticketId,
                    sprintf('ticket.public.management:%d:%d', $ticketId, (int) $recipient['id'])
                );
            }

            $this->audit->record(
                'ticket.status.change',
                'ticket',
                $ticketId,
                $actorId,
                $ticketId,
                [
                    'status_id' => (int) $ticket['current_status_id'],
                    'status_code' => (string) $ticket['status_code'],
                    'row_version' => $rowVersion,
                ],
                [
                    'status_id' => (int) $transition['to_status_id'],
                    'status_code' => (string) $transition['to_status_code'],
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

        foreach ($notificationRecipients as $recipient) {
            try {
                $this->mailer->sendTicketStatusChangedNotification(
                    $ticketId,
                    (int) $recipient['id'],
                    (string) $recipient['email'],
                    (string) $recipient['full_name'],
                    (string) $user['full_name'],
                    (string) $ticket['folio'],
                    (string) $ticket['title'],
                    'En gestión',
                    null
                );
            } catch (Throwable) {
                $this->audit->safeRecord(
                    'ticket.email.status_failed',
                    'ticket',
                    $ticketId,
                    $actorId,
                    ['recipient_user_id' => (int) $recipient['id']]
                );
            }
        }

        return $this->detail($user, $ticketId);
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    public function startExecution(
        array $user,
        int $ticketId,
        ?string $comment,
        int $rowVersion
    ): array {
        $actorId = (int) ($user['id'] ?? 0);
        $requestId = Http::requestId();
        $notificationRecipients = [];
        $normalizedComment = $comment === null ? '' : trim($comment);
        $startedAt = null;

        $this->pdo->beginTransaction();
        try {
            $ticket = $this->loadTicket($ticketId, true);
            $this->assertTicketAccess($user, $ticket);
            $this->assertExecutionSupervisorControl($user, $ticket);
            AuthorizationService::requirePermission($user, 'ticket.change_status');

            if ((int) $ticket['row_version'] !== $rowVersion) {
                throw new HttpException(409, 'ticket_changed', 'El ticket fue actualizado por otro usuario. Recarga la página.');
            }
            if ((string) $ticket['status_code'] !== 'authorized') {
                throw new HttpException(422, 'execution_not_available', 'La ejecución solo puede iniciarse después de que Dirección autorice el trabajo.');
            }
            if ((string) ($ticket['processing_route'] ?? '') !== 'authorization_required') {
                throw new HttpException(422, 'authorization_route_required', 'Este inicio de ejecución corresponde únicamente a tickets con autorización.');
            }
            if ($ticket['authorized_at'] === null) {
                throw new HttpException(422, 'authorization_required', 'El ticket no tiene una autorización registrada.');
            }
            if ($ticket['started_at'] !== null) {
                throw new HttpException(409, 'execution_already_started', 'La ejecución de este ticket ya fue iniciada.');
            }
            if (!$this->hasApprovedAuthorizationForCurrentQuotation($ticketId)) {
                throw new HttpException(422, 'approved_authorization_required', 'No existe una autorización aprobada para la cotización vigente.');
            }

            $transition = $this->findTransition((int) $ticket['current_status_id'], 'in_progress');
            AuthorizationService::requirePermission($user, (string) $transition['permission_code']);
            $this->validateTransitionRequirements($transition, $ticket);

            if ((bool) $transition['requires_comment'] && $normalizedComment === '') {
                throw new HttpException(422, 'comment_required', 'Agrega una nota para iniciar la ejecución.');
            }
            $normalizedComment = $normalizedComment !== '' ? $this->normalizeComment($normalizedComment) : null;

            $update = $this->pdo->prepare(
                'UPDATE tickets
                 SET current_status_id = :to_status_id,
                     action_owner_user_id = :action_owner_user_id,
                     started_at = UTC_TIMESTAMP(),
                     row_version = row_version + 1,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id
                   AND current_status_id = :from_status_id
                   AND row_version = :row_version
                   AND started_at IS NULL'
            );
            $update->execute([
                'to_status_id' => (int) $transition['to_status_id'],
                'action_owner_user_id' => (int) $ticket['supervisor_user_id'],
                'id' => $ticketId,
                'from_status_id' => (int) $ticket['current_status_id'],
                'row_version' => $rowVersion,
            ]);

            if ($update->rowCount() !== 1) {
                throw new HttpException(409, 'ticket_changed', 'El ticket fue actualizado por otro usuario. Recarga la página.');
            }

            $startedStatement = $this->pdo->prepare('SELECT started_at FROM tickets WHERE id = :id LIMIT 1');
            $startedStatement->execute(['id' => $ticketId]);
            $startedAtValue = $startedStatement->fetchColumn();
            $startedAt = is_string($startedAtValue) ? $startedAtValue : null;

            $metadata = json_encode([
                'request_id' => $requestId,
                'processing_route' => 'authorization_required',
                'authorized_at' => (string) $ticket['authorized_at'],
                'started_at' => $startedAt,
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
                'comment' => $normalizedComment,
                'metadata_json' => $metadata,
            ]);

            $notificationRecipients = $this->participantRecipients($ticket, $actorId, true);
            foreach ($notificationRecipients as $recipient) {
                $this->insertNotification(
                    (int) $recipient['id'],
                    $ticketId,
                    $actorId,
                    'ticket.status_changed',
                    (string) $ticket['folio'] . ' ahora está En proceso',
                    'El trabajo reportado se encuentra en ejecución.',
                    './ticket.html?id=' . $ticketId,
                    sprintf('ticket.execution.start:%d:%d', $ticketId, (int) $recipient['id'])
                );
            }

            $this->audit->record(
                'ticket.execution.start',
                'ticket',
                $ticketId,
                $actorId,
                $ticketId,
                [
                    'status_id' => (int) $ticket['current_status_id'],
                    'status_code' => (string) $ticket['status_code'],
                    'started_at' => null,
                    'action_owner_user_id' => (int) $ticket['action_owner_user_id'],
                    'row_version' => $rowVersion,
                ],
                [
                    'status_id' => (int) $transition['to_status_id'],
                    'status_code' => (string) $transition['to_status_code'],
                    'started_at' => $startedAt,
                    'action_owner_user_id' => (int) $ticket['supervisor_user_id'],
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

        foreach ($notificationRecipients as $recipient) {
            try {
                $this->mailer->sendTicketStatusChangedNotification(
                    $ticketId,
                    (int) $recipient['id'],
                    (string) $recipient['email'],
                    (string) $recipient['full_name'],
                    (string) $user['full_name'],
                    (string) $ticket['folio'],
                    (string) $ticket['title'],
                    'En proceso',
                    null
                );
            } catch (Throwable) {
                $this->audit->safeRecord(
                    'ticket.email.execution_start_failed',
                    'ticket',
                    $ticketId,
                    $actorId,
                    ['recipient_user_id' => (int) $recipient['id']]
                );
            }
        }

        return $this->detail($user, $ticketId);
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    public function completeWork(
        array $user,
        int $ticketId,
        string $resolutionSummary,
        ?string $observations,
        mixed $rawFiles,
        int $rowVersion
    ): array {
        $actorId = (int) ($user['id'] ?? 0);
        $requestId = Http::requestId();
        $resolutionSummary = $this->normalizeCompletionSummary($resolutionSummary);
        $normalizedObservations = $observations === null ? '' : trim($observations);
        if ($normalizedObservations !== '') {
            $normalizedObservations = $this->normalizeComment($normalizedObservations);
        } else {
            $normalizedObservations = null;
        }

        $validatedFiles = $this->validateCompletionEvidenceFiles($rawFiles);
        $notificationRecipients = [];
        $movedFiles = [];
        $absoluteDirectory = null;
        $attachmentRows = [];
        $completionTimestamp = null;
        $completedTransition = null;
        $closedTransition = null;

        $this->pdo->beginTransaction();
        try {
            $ticket = $this->loadTicket($ticketId, true);
            $this->assertTicketAccess($user, $ticket);
            $this->assertCompletionSupervisorControl($user, $ticket);
            AuthorizationService::requirePermission($user, 'ticket.change_status');
            AuthorizationService::requirePermission($user, 'ticket.upload.evidence');

            if ((int) $ticket['row_version'] !== $rowVersion) {
                throw new HttpException(409, 'ticket_changed', 'El ticket fue actualizado por otro usuario. Recarga la página.');
            }
            if ((string) $ticket['status_code'] !== 'in_progress') {
                throw new HttpException(422, 'completion_not_available', 'Solo se puede terminar un trabajo que se encuentre En proceso.');
            }
            if ($ticket['started_at'] === null) {
                throw new HttpException(422, 'execution_not_started', 'El ticket no tiene una fecha de inicio de ejecución.');
            }
            if ($ticket['completed_at'] !== null || $ticket['closed_at'] !== null) {
                throw new HttpException(409, 'work_already_completed', 'Este trabajo ya fue marcado como terminado.');
            }

            $completedTransition = $this->findTransition((int) $ticket['current_status_id'], 'completed');
            AuthorizationService::requirePermission($user, (string) $completedTransition['permission_code']);
            $this->validateTransitionRequirements($completedTransition, $ticket);

            $closedTransition = $this->findTransition((int) $completedTransition['to_status_id'], 'closed');

            $timestampStatement = $this->pdo->query('SELECT UTC_TIMESTAMP()');
            $completionTimestamp = (string) $timestampStatement->fetchColumn();
            if ($completionTimestamp === '') {
                throw new RuntimeException('No fue posible obtener la fecha de terminación del servidor.');
            }

            [$absoluteDirectory, $attachmentRows, $movedFiles] = $this->storeCompletionEvidenceFiles(
                $ticketId,
                $actorId,
                $validatedFiles
            );

            $update = $this->pdo->prepare(
                'UPDATE tickets
                 SET current_status_id = :closed_status_id,
                     action_owner_user_id = NULL,
                     resolution_summary = :resolution_summary,
                     completed_at = :completed_at,
                     closed_at = :closed_at,
                     row_version = row_version + 1,
                     updated_at = :updated_at
                 WHERE id = :id
                   AND current_status_id = :from_status_id
                   AND row_version = :row_version
                   AND completed_at IS NULL
                   AND closed_at IS NULL'
            );
            $update->execute([
                'closed_status_id' => (int) $closedTransition['to_status_id'],
                'resolution_summary' => $resolutionSummary,
                'completed_at' => $completionTimestamp,
                'closed_at' => $completionTimestamp,
                'updated_at' => $completionTimestamp,
                'id' => $ticketId,
                'from_status_id' => (int) $ticket['current_status_id'],
                'row_version' => $rowVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new HttpException(409, 'ticket_changed', 'El ticket fue actualizado por otro usuario. Recarga la página.');
            }

            $historyComment = $normalizedObservations ?? $resolutionSummary;
            $completionHistory = $this->pdo->prepare(
                'INSERT INTO ticket_status_history (
                    ticket_id, from_status_id, to_status_id, changed_by_user_id,
                    comment, change_source, metadata_json
                 ) VALUES (
                    :ticket_id, :from_status_id, :to_status_id, :changed_by_user_id,
                    :comment, \'user\', :metadata_json
                 )'
            );
            $completionHistory->execute([
                'ticket_id' => $ticketId,
                'from_status_id' => (int) $ticket['current_status_id'],
                'to_status_id' => (int) $completedTransition['to_status_id'],
                'changed_by_user_id' => $actorId,
                'comment' => $historyComment,
                'metadata_json' => json_encode([
                    'request_id' => $requestId,
                    'processing_route' => $ticket['processing_route'],
                    'started_at' => (string) $ticket['started_at'],
                    'completed_at' => $completionTimestamp,
                    'completion_attachment_ids' => array_column($attachmentRows, 'id'),
                    'auto_close' => true,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);

            $closeHistory = $this->pdo->prepare(
                'INSERT INTO ticket_status_history (
                    ticket_id, from_status_id, to_status_id, changed_by_user_id,
                    comment, change_source, metadata_json
                 ) VALUES (
                    :ticket_id, :from_status_id, :to_status_id, :changed_by_user_id,
                    :comment, \'system\', :metadata_json
                 )'
            );
            $closeHistory->execute([
                'ticket_id' => $ticketId,
                'from_status_id' => (int) $completedTransition['to_status_id'],
                'to_status_id' => (int) $closedTransition['to_status_id'],
                'changed_by_user_id' => $actorId,
                'comment' => 'El sistema cerró automáticamente el ticket al registrarse la terminación del trabajo.',
                'metadata_json' => json_encode([
                    'request_id' => $requestId,
                    'completed_at' => $completionTimestamp,
                    'closed_at' => $completionTimestamp,
                    'automatic' => true,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);

            $notificationRecipients = $this->participantRecipients($ticket, $actorId, true);
            foreach ($notificationRecipients as $recipient) {
                $this->insertNotification(
                    (int) $recipient['id'],
                    $ticketId,
                    $actorId,
                    'ticket.work.completed',
                    (string) $ticket['folio'] . ' · Trabajo terminado',
                    (string) $user['full_name'] . ' registró la terminación del trabajo y las evidencias finales. El ticket fue cerrado.',
                    './ticket.html?id=' . $ticketId,
                    sprintf('ticket.work.complete:%d:%d', $ticketId, (int) $recipient['id'])
                );
            }

            $this->audit->record(
                'ticket.execution.complete',
                'ticket',
                $ticketId,
                $actorId,
                $ticketId,
                [
                    'status_id' => (int) $ticket['current_status_id'],
                    'status_code' => (string) $ticket['status_code'],
                    'resolution_summary' => $ticket['resolution_summary'],
                    'completed_at' => null,
                    'closed_at' => null,
                    'action_owner_user_id' => $ticket['action_owner_user_id'],
                    'row_version' => $rowVersion,
                ],
                [
                    'status_id' => (int) $closedTransition['to_status_id'],
                    'status_code' => (string) $closedTransition['to_status_code'],
                    'intermediate_status_id' => (int) $completedTransition['to_status_id'],
                    'intermediate_status_code' => (string) $completedTransition['to_status_code'],
                    'resolution_summary' => $resolutionSummary,
                    'completed_at' => $completionTimestamp,
                    'closed_at' => $completionTimestamp,
                    'action_owner_user_id' => null,
                    'completion_attachment_ids' => array_column($attachmentRows, 'id'),
                    'row_version' => $rowVersion + 1,
                ],
                $requestId
            );

            $this->audit->record(
                'ticket.system.close',
                'ticket',
                $ticketId,
                $actorId,
                $ticketId,
                [
                    'status_code' => (string) $completedTransition['to_status_code'],
                    'completed_at' => $completionTimestamp,
                ],
                [
                    'status_code' => (string) $closedTransition['to_status_code'],
                    'closed_at' => $completionTimestamp,
                    'action_owner_user_id' => null,
                ],
                $requestId
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->cleanupCompletionFiles($movedFiles, $absoluteDirectory);
            throw $exception;
        }

        foreach ($notificationRecipients as $recipient) {
            try {
                $this->mailer->sendTicketStatusChangedNotification(
                    $ticketId,
                    (int) $recipient['id'],
                    (string) $recipient['email'],
                    (string) $recipient['full_name'],
                    (string) $user['full_name'],
                    (string) $ticket['folio'],
                    (string) $ticket['title'],
                    (string) $completedTransition['to_status_name'],
                    $resolutionSummary
                );
            } catch (Throwable) {
                $this->audit->safeRecord(
                    'ticket.email.completion_failed',
                    'ticket',
                    $ticketId,
                    $actorId,
                    ['recipient_user_id' => (int) $recipient['id']]
                );
            }
        }

        return $this->detail($user, $ticketId);
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    public function selectRoute(
        array $user,
        int $ticketId,
        string $processingRoute,
        ?string $comment,
        int $rowVersion
    ): array {
        $routeMap = [
            'direct' => [
                'to_status' => 'in_progress',
                'label' => 'Atención directa',
                'notification' => 'El reporte será atendido directamente por el equipo responsable.',
            ],
            'authorization_required' => [
                'to_status' => 'quotation_pending',
                'label' => 'Cotización y autorización',
                'notification' => 'El reporte requiere cotización y autorización antes de iniciar el trabajo.',
            ],
        ];

        if (!array_key_exists($processingRoute, $routeMap)) {
            throw new HttpException(422, 'invalid_processing_route', 'La ruta de atención seleccionada no es válida.');
        }

        $actorId = (int) $user['id'];
        $requestId = Http::requestId();
        $notificationRecipients = [];
        $route = $routeMap[$processingRoute];
        $normalizedComment = $comment === null ? '' : trim($comment);

        $this->pdo->beginTransaction();
        try {
            $ticket = $this->loadTicket($ticketId, true);
            $this->assertTicketAccess($user, $ticket);
            $this->assertSupervisorControl($user, $ticket);
            AuthorizationService::requirePermission($user, 'ticket.change_status');

            if ((int) $ticket['row_version'] !== $rowVersion) {
                throw new HttpException(409, 'ticket_changed', 'El ticket fue actualizado por otro usuario. Recarga la página.');
            }
            if ((string) $ticket['status_code'] !== 'under_review') {
                throw new HttpException(422, 'route_not_available', 'La ruta de atención solo puede definirse mientras el ticket está En revisión.');
            }
            if ($ticket['processing_route'] !== null) {
                throw new HttpException(409, 'route_already_selected', 'La ruta de atención ya fue seleccionada. Recarga el expediente.');
            }

            $transition = $this->findTransition((int) $ticket['current_status_id'], (string) $route['to_status']);
            AuthorizationService::requirePermission($user, (string) $transition['permission_code']);
            $this->validateTransitionRequirements($transition, $ticket);

            if ((bool) $transition['requires_comment'] && $normalizedComment === '') {
                throw new HttpException(422, 'comment_required', 'Describe brevemente cómo se atenderá el trabajo.');
            }
            $normalizedComment = $normalizedComment !== '' ? $this->normalizeComment($normalizedComment) : null;

            $update = $this->pdo->prepare(
                'UPDATE tickets
                 SET processing_route = :processing_route,
                     current_status_id = :to_status_id,
                     action_owner_user_id = :action_owner_user_id,
                     started_at = CASE
                         WHEN :start_work = 1 THEN COALESCE(started_at, UTC_TIMESTAMP())
                         ELSE started_at
                     END,
                     row_version = row_version + 1,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id
                   AND current_status_id = :from_status_id
                   AND processing_route IS NULL
                   AND row_version = :row_version'
            );
            $update->execute([
                'processing_route' => $processingRoute,
                'to_status_id' => (int) $transition['to_status_id'],
                'action_owner_user_id' => $ticket['supervisor_user_id'],
                'start_work' => $processingRoute === 'direct' ? 1 : 0,
                'id' => $ticketId,
                'from_status_id' => (int) $ticket['current_status_id'],
                'row_version' => $rowVersion,
            ]);

            if ($update->rowCount() !== 1) {
                throw new HttpException(409, 'ticket_changed', 'El ticket fue actualizado por otro usuario. Recarga la página.');
            }

            $metadata = json_encode([
                'request_id' => $requestId,
                'processing_route' => $processingRoute,
                'route_label' => (string) $route['label'],
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
                'comment' => $normalizedComment,
                'metadata_json' => $metadata,
            ]);

            $notificationRecipients = $processingRoute === 'direct'
                ? $this->participantRecipients($ticket, $actorId, true)
                : [];
            foreach ($notificationRecipients as $recipient) {
                $this->insertNotification(
                    (int) $recipient['id'],
                    $ticketId,
                    $actorId,
                    'ticket.status_changed',
                    (string) $ticket['folio'] . ' ahora está En proceso',
                    'El trabajo reportado se encuentra en ejecución.',
                    './ticket.html?id=' . $ticketId,
                    sprintf('ticket.public.progress:%d:%d', $ticketId, (int) $recipient['id'])
                );
            }

            $this->audit->record(
                'ticket.route.select',
                'ticket',
                $ticketId,
                $actorId,
                $ticketId,
                [
                    'processing_route' => null,
                    'status_id' => (int) $ticket['current_status_id'],
                    'status_code' => (string) $ticket['status_code'],
                    'row_version' => $rowVersion,
                ],
                [
                    'processing_route' => $processingRoute,
                    'status_id' => (int) $transition['to_status_id'],
                    'status_code' => (string) $transition['to_status_code'],
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

        foreach ($notificationRecipients as $recipient) {
            try {
                $this->mailer->sendTicketStatusChangedNotification(
                    $ticketId,
                    (int) $recipient['id'],
                    (string) $recipient['email'],
                    (string) $recipient['full_name'],
                    (string) $user['full_name'],
                    (string) $ticket['folio'],
                    (string) $ticket['title'],
                    'En proceso',
                    null
                );
            } catch (Throwable) {
                $this->audit->safeRecord(
                    'ticket.email.route_failed',
                    'ticket',
                    $ticketId,
                    $actorId,
                    ['recipient_user_id' => (int) $recipient['id'], 'processing_route' => $processingRoute]
                );
            }
        }

        return $this->detail($user, $ticketId);
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    public function attachment(array $user, int $attachmentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                a.id, a.ticket_id, a.attachment_type, a.original_name, a.storage_disk, a.storage_path,
                a.mime_type, a.size_bytes, a.sha256_hash,
                t.reported_by_user_id, t.supervisor_user_id, t.director_user_id, t.action_owner_user_id
             FROM ticket_attachments a
             INNER JOIN tickets t ON t.id = a.ticket_id
             WHERE a.id = :id AND a.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $attachmentId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new HttpException(404, 'attachment_not_found', 'El archivo no existe.');
        }

        $this->assertTicketAccess($user, $row);
        if ((string) $row['attachment_type'] === 'quotation' && !$this->canViewQuotations($user, $row)) {
            throw new HttpException(404, 'attachment_not_found', 'El archivo no existe.');
        }
        if ((string) $row['storage_disk'] !== 'local') {
            throw new HttpException(501, 'storage_not_supported', 'El almacenamiento del archivo no está disponible.');
        }

        $storageRoot = realpath(ROOT_PATH . '/storage');
        $filePath = realpath(ROOT_PATH . '/storage/' . ltrim((string) $row['storage_path'], '/'));
        if ($storageRoot === false || $filePath === false || !str_starts_with($filePath, $storageRoot . DIRECTORY_SEPARATOR)) {
            throw new HttpException(404, 'attachment_not_found', 'El archivo no está disponible.');
        }
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new HttpException(404, 'attachment_not_found', 'El archivo no está disponible.');
        }

        return [
            'id' => (int) $row['id'],
            'ticket_id' => (int) $row['ticket_id'],
            'original_name' => (string) $row['original_name'],
            'mime_type' => (string) $row['mime_type'],
            'size_bytes' => (int) $row['size_bytes'],
            'sha256_hash' => (string) $row['sha256_hash'],
            'absolute_path' => $filePath,
        ];
    }

    /** @return array<string, mixed> */
    private function loadTicket(int $ticketId, bool $forUpdate = false): array
    {
        if ($ticketId < 1) {
            throw new HttpException(404, 'ticket_not_found', 'El ticket no existe.');
        }

        $sql = 'SELECT
                    t.*,
                    l.code AS location_code,
                    l.name AS location_name,
                    p.code AS priority_code,
                    p.name AS priority_name,
                    p.weight AS priority_weight,
                    s.code AS status_code,
                    s.name AS status_name,
                    s.lifecycle_group,
                    s.is_terminal,
                    CONCAT_WS(" ", reporter.first_name, reporter.last_name) AS reporter_name,
                    reporter.email AS reporter_email,
                    CONCAT_WS(" ", supervisor.first_name, supervisor.last_name) AS supervisor_name,
                    supervisor.email AS supervisor_email,
                    CONCAT_WS(" ", director.first_name, director.last_name) AS director_name,
                    director.email AS director_email,
                    CONCAT_WS(" ", owner.first_name, owner.last_name) AS action_owner_name,
                    owner.email AS action_owner_email
                FROM tickets t
                INNER JOIN locations l ON l.id = t.location_id
                INNER JOIN ticket_priorities p ON p.id = t.priority_id
                INNER JOIN ticket_statuses s ON s.id = t.current_status_id
                INNER JOIN users reporter ON reporter.id = t.reported_by_user_id
                LEFT JOIN users supervisor ON supervisor.id = t.supervisor_user_id
                LEFT JOIN users director ON director.id = t.director_user_id
                LEFT JOIN users owner ON owner.id = t.action_owner_user_id
                WHERE t.id = :id
                LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $ticketId]);
        $ticket = $statement->fetch();

        if (!is_array($ticket)) {
            throw new HttpException(404, 'ticket_not_found', 'El ticket no existe.');
        }

        return $ticket;
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
    private function assertOperationalAssignment(array $user, array $ticket): void
    {
        if (!$this->hasRole($user, 'supervisor')) {
            throw new HttpException(403, 'supervisor_role_required', 'Esta acción corresponde al perfil Supervisor.');
        }

        $userId = (int) ($user['id'] ?? 0);
        if (
            $userId < 1
            || $userId !== (int) ($ticket['supervisor_user_id'] ?? 0)
            || $userId !== (int) ($ticket['action_owner_user_id'] ?? 0)
        ) {
            throw new HttpException(403, 'ticket_not_assigned', 'Solo el supervisor responsable puede realizar esta acción.');
        }
    }

    /** @param array<string, mixed> $ticket @return array<string, mixed> */
    private function formatTicket(array $ticket): array
    {
        return [
            'id' => (int) $ticket['id'],
            'folio' => (string) $ticket['folio'],
            'title' => (string) $ticket['title'],
            'description' => (string) $ticket['description'],
            'specific_location' => (string) $ticket['specific_location'],
            'location' => [
                'id' => (int) $ticket['location_id'],
                'code' => (string) $ticket['location_code'],
                'name' => (string) $ticket['location_name'],
            ],
            'priority' => [
                'id' => (int) $ticket['priority_id'],
                'code' => (string) $ticket['priority_code'],
                'name' => (string) $ticket['priority_name'],
            ],
            'processing_route' => $ticket['processing_route'] !== null ? (string) $ticket['processing_route'] : null,
            'resolution_summary' => $ticket['resolution_summary'] !== null ? (string) $ticket['resolution_summary'] : null,
            'status' => [
                'id' => (int) $ticket['current_status_id'],
                'code' => (string) $ticket['status_code'],
                'name' => (string) $ticket['status_name'],
                'is_terminal' => (bool) $ticket['is_terminal'],
            ],
            'reporter' => $this->person($ticket['reported_by_user_id'], $ticket['reporter_name'], $ticket['reporter_email']),
            'supervisor' => $this->person($ticket['supervisor_user_id'], $ticket['supervisor_name'], $ticket['supervisor_email']),
            'director' => $this->person($ticket['director_user_id'], $ticket['director_name'], $ticket['director_email']),
            'action_owner' => $this->person($ticket['action_owner_user_id'], $ticket['action_owner_name'], $ticket['action_owner_email']),
            'submitted_at' => (string) $ticket['submitted_at'],
            'authorized_at' => $ticket['authorized_at'] !== null ? (string) $ticket['authorized_at'] : null,
            'started_at' => $ticket['started_at'] !== null ? (string) $ticket['started_at'] : null,
            'completed_at' => $ticket['completed_at'] !== null ? (string) $ticket['completed_at'] : null,
            'closed_at' => $ticket['closed_at'] !== null ? (string) $ticket['closed_at'] : null,
            'updated_at' => (string) $ticket['updated_at'],
            'row_version' => (int) $ticket['row_version'],
        ];
    }

    /** @param array<string, mixed> $ticket @return array<string, mixed> */
    private function formatReporterTicket(array $ticket): array
    {
        $publicStatus = TicketPresentationPolicy::publicStatus(
            (string) $ticket['status']['code'],
            (string) $ticket['status']['name'],
            (bool) $ticket['status']['is_terminal']
        );

        $ticket['status'] = $publicStatus;
        $ticket['processing_route'] = null;
        $ticket['processing_route_label'] = 'Gestión interna';
        $ticket['director'] = null;
        $ticket['action_owner'] = null;
        $ticket['authorized_at'] = null;

        return $ticket;
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @return array<int, array<string, mixed>>
     */
    private function publicStatusHistory(array $history): array
    {
        $result = [];
        foreach ($history as $item) {
            $to = TicketPresentationPolicy::publicStatus(
                (string) ($item['to_status']['code'] ?? ''),
                (string) ($item['to_status']['name'] ?? ''),
                false
            );
            $from = null;
            if (is_array($item['from_status'] ?? null)) {
                $from = TicketPresentationPolicy::publicStatus(
                    (string) ($item['from_status']['code'] ?? ''),
                    (string) ($item['from_status']['name'] ?? ''),
                    false
                );
            }

            if ($from !== null && $from['code'] === $to['code']) {
                continue;
            }

            $item['from_status'] = $from === null ? null : [
                'code' => $from['code'],
                'name' => $from['name'],
            ];
            $item['to_status'] = [
                'code' => $to['code'],
                'name' => $to['name'],
            ];
            // Los comentarios asociados a transiciones son parte del expediente interno.
            // El Reporter recibe los comentarios públicos desde ticket_comments.
            $item['comment'] = null;
            $result[] = $item;
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function person(mixed $id, mixed $name, mixed $email): ?array
    {
        if ($id === null) {
            return null;
        }
        return [
            'id' => (int) $id,
            'full_name' => trim((string) $name),
            'email' => (string) $email,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function loadAttachments(int $ticketId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, attachment_type, original_name, mime_type, size_bytes, created_at
             FROM ticket_attachments
             WHERE ticket_id = :ticket_id
               AND attachment_type = \'evidence\'
               AND deleted_at IS NULL
             ORDER BY created_at, id'
        );
        $statement->execute(['ticket_id' => $ticketId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'type' => (string) $row['attachment_type'],
            'original_name' => (string) $row['original_name'],
            'mime_type' => (string) $row['mime_type'],
            'size_bytes' => (int) $row['size_bytes'],
            'created_at' => (string) $row['created_at'],
            'url' => './api/tickets/attachment.php?id=' . (int) $row['id'],
            'download_url' => './api/tickets/attachment.php?id=' . (int) $row['id'] . '&download=1',
        ], $statement->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    private function loadCompletionEvidence(int $ticketId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, attachment_type, original_name, mime_type, size_bytes, created_at
             FROM ticket_attachments
             WHERE ticket_id = :ticket_id
               AND attachment_type = \'completion_evidence\'
               AND deleted_at IS NULL
             ORDER BY created_at, id'
        );
        $statement->execute(['ticket_id' => $ticketId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'type' => (string) $row['attachment_type'],
            'original_name' => (string) $row['original_name'],
            'mime_type' => (string) $row['mime_type'],
            'size_bytes' => (int) $row['size_bytes'],
            'created_at' => (string) $row['created_at'],
            'url' => './api/tickets/attachment.php?id=' . (int) $row['id'],
            'download_url' => './api/tickets/attachment.php?id=' . (int) $row['id'] . '&download=1',
        ], $statement->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    private function loadQuotations(int $ticketId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                q.id, q.attachment_id, q.previous_quotation_id, q.version_number,
                q.supplier_name, q.reference_number, q.amount, q.currency, q.valid_until,
                q.description, q.status, q.is_current, q.created_at, q.updated_at,
                a.original_name, a.mime_type, a.size_bytes, a.sha256_hash,
                u.id AS uploaded_by_id,
                CONCAT_WS(" ", u.first_name, u.last_name) AS uploaded_by_name,
                u.email AS uploaded_by_email
             FROM ticket_quotations q
             INNER JOIN ticket_attachments a
                ON a.id = q.attachment_id AND a.deleted_at IS NULL
             INNER JOIN users u ON u.id = q.uploaded_by_user_id
             WHERE q.ticket_id = :ticket_id
             ORDER BY q.version_number DESC, q.id DESC'
        );
        $statement->execute(['ticket_id' => $ticketId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'attachment_id' => (int) $row['attachment_id'],
            'previous_quotation_id' => $row['previous_quotation_id'] !== null ? (int) $row['previous_quotation_id'] : null,
            'version_number' => (int) $row['version_number'],
            'supplier_name' => (string) $row['supplier_name'],
            'reference_number' => $row['reference_number'] !== null ? (string) $row['reference_number'] : null,
            'amount' => (string) $row['amount'],
            'currency' => (string) $row['currency'],
            'valid_until' => $row['valid_until'] !== null ? (string) $row['valid_until'] : null,
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'status' => (string) $row['status'],
            'is_current' => (bool) $row['is_current'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'file' => [
                'original_name' => (string) $row['original_name'],
                'mime_type' => (string) $row['mime_type'],
                'size_bytes' => (int) $row['size_bytes'],
                'sha256_hash' => (string) $row['sha256_hash'],
                'url' => './api/tickets/attachment.php?id=' . (int) $row['attachment_id'],
                'download_url' => './api/tickets/attachment.php?id=' . (int) $row['attachment_id'] . '&download=1',
            ],
            'uploaded_by' => [
                'id' => (int) $row['uploaded_by_id'],
                'full_name' => trim((string) $row['uploaded_by_name']),
                'email' => (string) $row['uploaded_by_email'],
            ],
        ], $statement->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    private function loadApprovalRequests(int $ticketId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                ar.id, ar.quotation_id, ar.supersedes_request_id,
                ar.request_comment, ar.decision_comment,
                ar.requested_amount, ar.approved_amount,
                ar.requested_at, ar.responded_at, ar.created_at, ar.updated_at,
                aps.code AS approval_status_code, aps.name AS approval_status_name, aps.is_terminal AS approval_status_terminal,
                q.version_number AS quotation_version, q.supplier_name, q.amount AS quotation_amount, q.currency,
                requester.id AS requester_id,
                CONCAT_WS(" ", requester.first_name, requester.last_name) AS requester_name,
                requester.email AS requester_email,
                approver.id AS approver_id,
                CONCAT_WS(" ", approver.first_name, approver.last_name) AS approver_name,
                approver.email AS approver_email,
                decided.id AS decided_by_id,
                CONCAT_WS(" ", decided.first_name, decided.last_name) AS decided_by_name,
                decided.email AS decided_by_email
             FROM ticket_approval_requests ar
             INNER JOIN approval_statuses aps ON aps.id = ar.status_id
             INNER JOIN ticket_quotations q ON q.id = ar.quotation_id
             INNER JOIN users requester ON requester.id = ar.requested_by_user_id
             INNER JOIN users approver ON approver.id = ar.approver_user_id
             LEFT JOIN users decided ON decided.id = ar.decided_by_user_id
             WHERE ar.ticket_id = :ticket_id
             ORDER BY ar.requested_at DESC, ar.id DESC'
        );
        $statement->execute(['ticket_id' => $ticketId]);
        $rows = $statement->fetchAll();
        $displayNumbers = [];
        $displayNumber = 1;
        foreach (array_reverse($rows) as $row) {
            $displayNumbers[(int) $row['id']] = $displayNumber++;
        }

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'display_number' => $displayNumbers[(int) $row['id']] ?? 1,
            'quotation_id' => (int) $row['quotation_id'],
            'supersedes_request_id' => $row['supersedes_request_id'] !== null ? (int) $row['supersedes_request_id'] : null,
            'status' => [
                'code' => (string) $row['approval_status_code'],
                'name' => (string) $row['approval_status_name'],
                'is_terminal' => (bool) $row['approval_status_terminal'],
            ],
            'quotation' => [
                'id' => (int) $row['quotation_id'],
                'version_number' => (int) $row['quotation_version'],
                'supplier_name' => (string) $row['supplier_name'],
                'amount' => (string) $row['quotation_amount'],
                'currency' => (string) $row['currency'],
            ],
            'requested_by' => $this->person($row['requester_id'], $row['requester_name'], $row['requester_email']),
            'approver' => $this->person($row['approver_id'], $row['approver_name'], $row['approver_email']),
            'decided_by' => $this->person($row['decided_by_id'], $row['decided_by_name'], $row['decided_by_email']),
            'request_comment' => (string) $row['request_comment'],
            'decision_comment' => $row['decision_comment'] !== null ? (string) $row['decision_comment'] : null,
            'requested_amount' => (string) $row['requested_amount'],
            'approved_amount' => $row['approved_amount'] !== null ? (string) $row['approved_amount'] : null,
            'requested_at' => (string) $row['requested_at'],
            'responded_at' => $row['responded_at'] !== null ? (string) $row['responded_at'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ], $rows);
    }

    /** @return array<int, array<string, mixed>> */
    private function loadEligibleDirectors(): array
    {
        $statement = $this->pdo->query(
            'SELECT DISTINCT
                u.id,
                CONCAT_WS(" ", u.first_name, u.last_name) AS full_name,
                u.email
             FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE u.status = \'active\'
               AND r.code = \'director\'
               AND r.is_active = 1
             ORDER BY u.first_name, u.last_name, u.id'
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'full_name' => trim((string) $row['full_name']),
            'email' => (string) $row['email'],
        ], $statement->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    private function loadComments(int $ticketId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.body, c.comment_type, c.is_system, c.created_at, c.updated_at,
                    u.id AS author_id, CONCAT_WS(" ", u.first_name, u.last_name) AS author_name,
                    (
                        SELECT GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR 0x2C20)
                        FROM user_roles ur
                        INNER JOIN roles r ON r.id = ur.role_id AND r.is_active = 1
                        WHERE ur.user_id = u.id
                    ) AS author_roles
             FROM ticket_comments c
             INNER JOIN users u ON u.id = c.author_user_id
             WHERE c.ticket_id = :ticket_id AND c.deleted_at IS NULL
             ORDER BY c.created_at, c.id'
        );
        $statement->execute(['ticket_id' => $ticketId]);
        return array_map(fn (array $row): array => $this->formatComment($row), $statement->fetchAll());
    }

    /** @return array<string, mixed> */
    private function loadComment(int $commentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.body, c.comment_type, c.is_system, c.created_at, c.updated_at,
                    u.id AS author_id, CONCAT_WS(" ", u.first_name, u.last_name) AS author_name,
                    (
                        SELECT GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR 0x2C20)
                        FROM user_roles ur
                        INNER JOIN roles r ON r.id = ur.role_id AND r.is_active = 1
                        WHERE ur.user_id = u.id
                    ) AS author_roles
             FROM ticket_comments c
             INNER JOIN users u ON u.id = c.author_user_id
             WHERE c.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $commentId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('No fue posible recuperar el comentario creado.');
        }
        return $this->formatComment($row);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatComment(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'body' => (string) $row['body'],
            'type' => (string) $row['comment_type'],
            'is_system' => (bool) $row['is_system'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'author' => [
                'id' => (int) $row['author_id'],
                'full_name' => trim((string) $row['author_name']),
                'roles' => $row['author_roles'] !== null ? (string) $row['author_roles'] : '',
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function loadStatusHistory(int $ticketId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT h.id, h.comment, h.change_source, h.created_at,
                    fs.code AS from_code, fs.name AS from_name,
                    ts.code AS to_code, ts.name AS to_name,
                    u.id AS actor_id, CONCAT_WS(" ", u.first_name, u.last_name) AS actor_name
             FROM ticket_status_history h
             LEFT JOIN ticket_statuses fs ON fs.id = h.from_status_id
             INNER JOIN ticket_statuses ts ON ts.id = h.to_status_id
             INNER JOIN users u ON u.id = h.changed_by_user_id
             WHERE h.ticket_id = :ticket_id
             ORDER BY h.created_at, h.id'
        );
        $statement->execute(['ticket_id' => $ticketId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'from_status' => $row['from_code'] === null ? null : ['code' => (string) $row['from_code'], 'name' => (string) $row['from_name']],
            'to_status' => ['code' => (string) $row['to_code'], 'name' => (string) $row['to_name']],
            'actor' => ['id' => (int) $row['actor_id'], 'full_name' => trim((string) $row['actor_name'])],
            'comment' => $row['comment'] !== null ? (string) $row['comment'] : null,
            'source' => (string) $row['change_source'],
            'created_at' => (string) $row['created_at'],
        ], $statement->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    private function loadAssignmentHistory(int $ticketId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT h.id, h.assignment_type, h.comment, h.created_at,
                    prev_user.id AS previous_id, CONCAT_WS(" ", prev_user.first_name, prev_user.last_name) AS previous_name,
                    new_user.id AS new_id, CONCAT_WS(" ", new_user.first_name, new_user.last_name) AS new_name,
                    actor.id AS actor_id, CONCAT_WS(" ", actor.first_name, actor.last_name) AS actor_name
             FROM ticket_assignment_history h
             LEFT JOIN users prev_user ON prev_user.id = h.previous_user_id
             LEFT JOIN users new_user ON new_user.id = h.new_user_id
             INNER JOIN users actor ON actor.id = h.assigned_by_user_id
             WHERE h.ticket_id = :ticket_id
             ORDER BY h.created_at, h.id'
        );
        $statement->execute(['ticket_id' => $ticketId]);
        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'type' => (string) $row['assignment_type'],
            'previous_user' => $this->person($row['previous_id'], $row['previous_name'], ''),
            'new_user' => $this->person($row['new_id'], $row['new_name'], ''),
            'assigned_by' => $this->person($row['actor_id'], $row['actor_name'], ''),
            'comment' => $row['comment'] !== null ? (string) $row['comment'] : null,
            'created_at' => (string) $row['created_at'],
        ], $statement->fetchAll());
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $ticket @return array<int, array<string, mixed>> */
    private function allowedTransitions(array $user, array $ticket): array
    {
        if (!$this->isOperationalAssignee($user, $ticket)) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT tr.to_status_id, tr.requires_comment, tr.requires_quotation,
                    tr.requires_supervisor, tr.requires_director,
                    p.code AS permission_code,
                    ts.code AS to_status_code, ts.name AS to_status_name
             FROM ticket_status_transitions tr
             INNER JOIN permissions p ON p.id = tr.required_permission_id
             INNER JOIN ticket_statuses ts ON ts.id = tr.to_status_id AND ts.is_active = 1
             WHERE tr.from_status_id = :from_status_id AND tr.is_active = 1
             ORDER BY ts.sort_order'
        );
        $statement->execute(['from_status_id' => (int) $ticket['current_status_id']]);

        $permissions = is_array($user['permissions'] ?? null) ? $user['permissions'] : [];
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!in_array((string) $row['to_status_code'], self::ENABLED_TRANSITIONS, true)) {
                continue;
            }
            if (!in_array((string) $row['permission_code'], $permissions, true)) {
                continue;
            }
            if ((bool) $row['requires_supervisor'] && $ticket['supervisor_user_id'] === null) {
                continue;
            }
            if ((bool) $row['requires_director'] && $ticket['director_user_id'] === null) {
                continue;
            }
            if ((bool) $row['requires_quotation'] && !$this->hasCurrentQuotation((int) $ticket['id'])) {
                continue;
            }
            $result[] = [
                'to_status' => ['id' => (int) $row['to_status_id'], 'code' => (string) $row['to_status_code'], 'name' => (string) $row['to_status_name']],
                'permission' => (string) $row['permission_code'],
                'requires_comment' => (bool) $row['requires_comment'],
            ];
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function findTransition(int $fromStatusId, string $toStatusCode): array
    {
        $statement = $this->pdo->prepare(
            'SELECT tr.*, p.code AS permission_code,
                    ts.code AS to_status_code, ts.name AS to_status_name
             FROM ticket_status_transitions tr
             INNER JOIN permissions p ON p.id = tr.required_permission_id
             INNER JOIN ticket_statuses ts ON ts.id = tr.to_status_id AND ts.is_active = 1
             WHERE tr.from_status_id = :from_status_id
               AND ts.code = :to_status_code
               AND tr.is_active = 1
             LIMIT 1'
        );
        $statement->execute(['from_status_id' => $fromStatusId, 'to_status_code' => $toStatusCode]);
        $transition = $statement->fetch();
        if (!is_array($transition)) {
            throw new HttpException(422, 'invalid_transition', 'El cambio de estado no está permitido.');
        }
        return $transition;
    }

    /** @param array<string, mixed> $transition @param array<string, mixed> $ticket */
    private function validateTransitionRequirements(array $transition, array $ticket): void
    {
        if ((bool) $transition['requires_supervisor'] && $ticket['supervisor_user_id'] === null) {
            throw new HttpException(422, 'supervisor_required', 'El ticket requiere un supervisor asignado.');
        }
        if ((bool) $transition['requires_director'] && $ticket['director_user_id'] === null) {
            throw new HttpException(422, 'director_required', 'El ticket requiere un responsable de Dirección.');
        }
        if ((bool) $transition['requires_quotation'] && !$this->hasCurrentQuotation((int) $ticket['id'])) {
            throw new HttpException(422, 'quotation_required', 'El ticket requiere una cotización vigente.');
        }
    }

    private function hasCurrentQuotation(int $ticketId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM ticket_quotations WHERE ticket_id = :ticket_id AND is_current = 1 LIMIT 1'
        );
        $statement->execute(['ticket_id' => $ticketId]);
        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $ticket */
    private function canViewQuotations(array $user, array $ticket): bool
    {
        if (AuthorizationService::hasPermission($user, 'ticket.view.all')) {
            return true;
        }
        if (!AuthorizationService::hasPermission($user, 'ticket.view.assigned')) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);
        return $userId > 0 && in_array($userId, [
            (int) ($ticket['supervisor_user_id'] ?? 0),
            (int) ($ticket['director_user_id'] ?? 0),
            (int) ($ticket['action_owner_user_id'] ?? 0),
        ], true);
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $ticket */
    private function canUploadQuotation(array $user, array $ticket): bool
    {
        if (
            !in_array((string) ($ticket['status_code'] ?? ''), ['quotation_pending', 'changes_requested'], true)
            || (string) ($ticket['processing_route'] ?? '') !== 'authorization_required'
            || !AuthorizationService::hasPermission($user, 'ticket.upload.quotation')
            || !$this->hasRole($user, 'supervisor')
        ) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);
        return $userId > 0
            && $userId === (int) ($ticket['supervisor_user_id'] ?? 0)
            && $userId === (int) ($ticket['action_owner_user_id'] ?? 0);
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $ticket */
    private function canViewApprovals(array $user, array $ticket): bool
    {
        if (AuthorizationService::hasPermission($user, 'ticket.view.all')) {
            return true;
        }
        if (!AuthorizationService::hasPermission($user, 'ticket.view.assigned')) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);
        return $userId > 0 && in_array($userId, [
            (int) ($ticket['supervisor_user_id'] ?? 0),
            (int) ($ticket['director_user_id'] ?? 0),
            (int) ($ticket['action_owner_user_id'] ?? 0),
        ], true);
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $ticket */
    private function canRequestAuthorization(array $user, array $ticket): bool
    {
        if (
            (string) ($ticket['status_code'] ?? '') !== 'quotation_pending'
            || (string) ($ticket['processing_route'] ?? '') !== 'authorization_required'
            || !AuthorizationService::hasPermission($user, 'ticket.request_authorization')
            || !AuthorizationService::hasPermission($user, 'ticket.assign.director')
            || !$this->hasRole($user, 'supervisor')
            || !$this->hasCurrentQuotation((int) $ticket['id'])
        ) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);
        return $userId > 0
            && $userId === (int) ($ticket['supervisor_user_id'] ?? 0)
            && $userId === (int) ($ticket['action_owner_user_id'] ?? 0);
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $ticket */
    private function canDecideAuthorization(array $user, array $ticket, string $permission): bool
    {
        if (
            (string) ($ticket['status_code'] ?? '') !== 'authorization_pending'
            || !AuthorizationService::hasPermission($user, $permission)
            || !$this->hasRole($user, 'director')
        ) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);
        if (
            $userId < 1
            || $userId !== (int) ($ticket['director_user_id'] ?? 0)
            || $userId !== (int) ($ticket['action_owner_user_id'] ?? 0)
        ) {
            return false;
        }

        return $this->hasPendingApprovalRequest((int) $ticket['id'], $userId);
    }

    /** @param array<string, mixed> $user */
    private function hasRole(array $user, string $role): bool
    {
        $roles = $user['roles'] ?? [];
        return is_array($roles) && in_array($role, $roles, true);
    }

    private function hasPendingApprovalRequest(int $ticketId, ?int $approverUserId): bool
    {
        $sql = "SELECT 1
                FROM ticket_approval_requests ar
                INNER JOIN approval_statuses aps ON aps.id = ar.status_id
                WHERE ar.ticket_id = :ticket_id
                  AND aps.code = 'pending'";
        $params = ['ticket_id' => $ticketId];
        if ($approverUserId !== null) {
            $sql .= ' AND ar.approver_user_id = :approver_user_id';
            $params['approver_user_id'] = $approverUserId;
        }
        $sql .= ' LIMIT 1';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $ticket */
    private function canStartExecution(array $user, array $ticket): bool
    {
        if ((string) $ticket['status_code'] !== 'authorized') {
            return false;
        }
        if ((string) ($ticket['processing_route'] ?? '') !== 'authorization_required') {
            return false;
        }
        if ($ticket['authorized_at'] === null || $ticket['started_at'] !== null) {
            return false;
        }
        if (
            !AuthorizationService::hasPermission($user, 'ticket.change_status')
            || !$this->hasRole($user, 'supervisor')
        ) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);
        if (
            $userId < 1
            || $userId !== (int) ($ticket['supervisor_user_id'] ?? 0)
            || $userId !== (int) ($ticket['action_owner_user_id'] ?? 0)
        ) {
            return false;
        }

        return $this->hasApprovedAuthorizationForCurrentQuotation((int) $ticket['id']);
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $ticket */
    private function canCompleteWork(array $user, array $ticket): bool
    {
        if ((string) $ticket['status_code'] !== 'in_progress') {
            return false;
        }
        if ($ticket['started_at'] === null || $ticket['completed_at'] !== null) {
            return false;
        }
        if (!AuthorizationService::hasPermission($user, 'ticket.change_status')) {
            return false;
        }
        if (
            !AuthorizationService::hasPermission($user, 'ticket.upload.evidence')
            || !$this->hasRole($user, 'supervisor')
        ) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);
        return $userId > 0
            && $userId === (int) ($ticket['supervisor_user_id'] ?? 0)
            && $userId === (int) ($ticket['action_owner_user_id'] ?? 0);
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $ticket */
    private function assertCompletionSupervisorControl(array $user, array $ticket): void
    {
        if (!$this->hasRole($user, 'supervisor')) {
            throw new HttpException(403, 'supervisor_role_required', 'Esta acción corresponde al perfil Supervisor.');
        }

        $userId = (int) ($user['id'] ?? 0);
        if (
            $userId < 1
            || $userId !== (int) ($ticket['supervisor_user_id'] ?? 0)
            || $userId !== (int) ($ticket['action_owner_user_id'] ?? 0)
        ) {
            throw new HttpException(403, 'supervisor_completion_required', 'Solo el supervisor responsable puede registrar la terminación del trabajo.');
        }
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $ticket */
    private function assertExecutionSupervisorControl(array $user, array $ticket): void
    {
        if (!$this->hasRole($user, 'supervisor')) {
            throw new HttpException(403, 'supervisor_role_required', 'Esta acción corresponde al perfil Supervisor.');
        }

        $userId = (int) ($user['id'] ?? 0);
        if (
            $userId < 1
            || $userId !== (int) ($ticket['supervisor_user_id'] ?? 0)
            || $userId !== (int) ($ticket['action_owner_user_id'] ?? 0)
        ) {
            throw new HttpException(403, 'supervisor_execution_required', 'Solo el supervisor responsable puede iniciar la ejecución.');
        }
    }

    private function hasApprovedAuthorizationForCurrentQuotation(int $ticketId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM ticket_quotations q
             INNER JOIN ticket_approval_requests ar ON ar.quotation_id = q.id AND ar.ticket_id = q.ticket_id
             INNER JOIN approval_statuses aps ON aps.id = ar.status_id
             WHERE q.ticket_id = :ticket_id
               AND q.is_current = 1
               AND q.status = \'approved\'
               AND aps.code = \'approved\'
               AND ar.responded_at IS NOT NULL
             LIMIT 1'
        );
        $statement->execute(['ticket_id' => $ticketId]);
        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $ticket */
    private function canSelectRoute(array $user, array $ticket): bool
    {
        if ((string) $ticket['status_code'] !== 'under_review' || $ticket['processing_route'] !== null) {
            return false;
        }
        if (
            !AuthorizationService::hasPermission($user, 'ticket.change_status')
            || !$this->hasRole($user, 'supervisor')
        ) {
            return false;
        }
        $userId = (int) ($user['id'] ?? 0);
        return $userId > 0
            && $userId === (int) ($ticket['supervisor_user_id'] ?? 0)
            && $userId === (int) ($ticket['action_owner_user_id'] ?? 0);
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $ticket */
    private function assertSupervisorControl(array $user, array $ticket): void
    {
        if (!$this->hasRole($user, 'supervisor')) {
            throw new HttpException(403, 'supervisor_role_required', 'Esta acción corresponde al perfil Supervisor.');
        }

        $userId = (int) ($user['id'] ?? 0);
        if (
            $userId < 1
            || $userId !== (int) ($ticket['supervisor_user_id'] ?? 0)
            || $userId !== (int) ($ticket['action_owner_user_id'] ?? 0)
        ) {
            throw new HttpException(403, 'supervisor_action_required', 'Solo el supervisor responsable puede definir la ruta de atención.');
        }
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $ticket */
    private function isOperationalAssignee(array $user, array $ticket): bool
    {
        if (!$this->hasRole($user, 'supervisor')) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);
        return $userId > 0
            && $userId === (int) ($ticket['supervisor_user_id'] ?? 0)
            && $userId === (int) ($ticket['action_owner_user_id'] ?? 0);
    }

    /** @param array<string, mixed> $ticket @return array<int, array<string, mixed>> */
    private function participantRecipients(array $ticket, int $excludeUserId, bool $reporterOnly = false): array
    {
        $ids = $reporterOnly
            ? [(int) $ticket['reported_by_user_id']]
            : [
                (int) $ticket['reported_by_user_id'],
                (int) ($ticket['supervisor_user_id'] ?? 0),
                (int) ($ticket['director_user_id'] ?? 0),
                (int) ($ticket['action_owner_user_id'] ?? 0),
            ];
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0 && $id !== $excludeUserId)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id, CONCAT_WS(' ', first_name, last_name) AS full_name, email
             FROM users WHERE status = 'active' AND id IN ({$placeholders})"
        );
        $statement->execute($ids);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'full_name' => trim((string) $row['full_name']),
            'email' => (string) $row['email'],
        ], $statement->fetchAll());
    }

    private function insertNotification(int $userId, int $ticketId, int $actorId, string $type, string $title, string $message, string $url, string $key): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO notifications (user_id, ticket_id, actor_user_id, type, title, message, action_url, deduplication_key)
             VALUES (:user_id, :ticket_id, :actor_user_id, :type, :title, :message, :action_url, :deduplication_key)'
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

    private function normalizeCompletionSummary(string $value): string
    {
        $value = trim(preg_replace('/\R/u', "\n", $value) ?? $value);
        $length = $this->textLength($value);
        if ($length < 10) {
            throw new HttpException(422, 'resolution_summary_too_short', 'Describe la solución realizada con al menos 10 caracteres.');
        }
        if ($length > 5000) {
            throw new HttpException(422, 'resolution_summary_too_long', 'El resumen de solución no puede superar 5000 caracteres.');
        }
        return $value;
    }

    /** @param mixed $rawFiles @return array<int, array<string, mixed>> */
    private function validateCompletionEvidenceFiles(mixed $rawFiles): array
    {
        $files = $this->normalizeCompletionFilesArray($rawFiles);
        $maxFiles = max(1, min(20, (int) $this->completionSetting('upload.evidence.max_files', '8')));
        $maxSizeBytes = max(1, min(25, (int) $this->completionSetting('upload.evidence.max_size_mb', '8'))) * 1024 * 1024;
        if ($files === []) {
            throw new HttpException(422, 'completion_evidence_required', 'Agrega al menos una fotografía que demuestre el trabajo terminado.');
        }
        if (count($files) > $maxFiles) {
            throw new HttpException(422, 'too_many_completion_files', "Puedes adjuntar como máximo {$maxFiles} fotografías finales.");
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
        $validated = [];
        foreach ($files as $index => $file) {
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                throw new HttpException(422, 'completion_upload_failed', 'No fue posible recibir la fotografía final ' . ($index + 1) . '.');
            }
            $tmpName = (string) ($file['tmp_name'] ?? '');
            $size = (int) ($file['size'] ?? 0);
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                throw new HttpException(422, 'invalid_completion_upload', 'Uno de los archivos finales no fue recibido correctamente.');
            }
            if ($size <= 0 || $size > $maxSizeBytes) {
                $maxSizeMb = (int) round($maxSizeBytes / 1024 / 1024);
                throw new HttpException(422, 'completion_file_too_large', "Cada fotografía final debe pesar como máximo {$maxSizeMb} MB.");
            }
            $mimeType = (string) $finfo->file($tmpName);
            if (!isset($allowed[$mimeType])) {
                throw new HttpException(422, 'invalid_completion_file_type', 'Solo se permiten imágenes finales JPG, JPEG y PNG.');
            }
            $imageInfo = @getimagesize($tmpName);
            if (!is_array($imageInfo) || !isset($imageInfo[0], $imageInfo[1])) {
                throw new HttpException(422, 'invalid_completion_image', 'Uno de los archivos finales no es una imagen válida.');
            }
            $pixels = (int) $imageInfo[0] * (int) $imageInfo[1];
            if ($pixels <= 0 || $pixels > self::MAX_IMAGE_PIXELS) {
                throw new HttpException(422, 'completion_image_dimensions_too_large', 'Una de las imágenes finales tiene dimensiones excesivas.');
            }
            $originalName = basename((string) ($file['name'] ?? 'evidencia-final.' . $allowed[$mimeType]));
            if ($this->textLength($originalName) > 255) {
                $originalName = 'evidencia-final-' . ($index + 1) . '.' . $allowed[$mimeType];
            }
            $validated[] = [
                'tmp_name' => $tmpName,
                'size' => $size,
                'mime_type' => $mimeType,
                'extension' => $allowed[$mimeType],
                'original_name' => $originalName,
            ];
        }
        return $validated;
    }

    /** @param mixed $rawFiles @return array<int, array<string, mixed>> */
    private function normalizeCompletionFilesArray(mixed $rawFiles): array
    {
        if (!is_array($rawFiles) || !isset($rawFiles['name'])) {
            return [];
        }
        if (!is_array($rawFiles['name'])) {
            return [(array) $rawFiles];
        }
        $files = [];
        foreach ($rawFiles['name'] as $index => $name) {
            $error = (int) ($rawFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE && trim((string) $name) === '') {
                continue;
            }
            $files[] = [
                'name' => $name,
                'type' => $rawFiles['type'][$index] ?? null,
                'tmp_name' => $rawFiles['tmp_name'][$index] ?? null,
                'error' => $error,
                'size' => $rawFiles['size'][$index] ?? 0,
            ];
        }
        return $files;
    }

    /** @param array<int, array<string, mixed>> $validatedFiles @return array{0:string,1:array<int,array<string,mixed>>,2:array<int,string>} */
    private function storeCompletionEvidenceFiles(int $ticketId, int $userId, array $validatedFiles): array
    {
        $relativeDirectory = sprintf('uploads/tickets/%s/%s/%d/completion', gmdate('Y'), gmdate('m'), $ticketId);
        $absoluteDirectory = ROOT_PATH . '/storage/' . $relativeDirectory;
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0770, true) && !is_dir($absoluteDirectory)) {
            throw new RuntimeException('No fue posible preparar el almacenamiento de evidencias finales.');
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO ticket_attachments (
                ticket_id, comment_id, uploaded_by_user_id, attachment_type,
                original_name, stored_name, storage_disk, storage_path,
                mime_type, extension, size_bytes, sha256_hash
             ) VALUES (
                :ticket_id, NULL, :uploaded_by_user_id, \'completion_evidence\',
                :original_name, :stored_name, \'local\', :storage_path,
                :mime_type, :extension, :size_bytes, :sha256_hash
             )'
        );

        $rows = [];
        $movedFiles = [];
        foreach ($validatedFiles as $file) {
            $storedName = bin2hex(random_bytes(20)) . '.' . $file['extension'];
            $absolutePath = $absoluteDirectory . '/' . $storedName;
            $relativePath = $relativeDirectory . '/' . $storedName;
            if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
                $this->cleanupCompletionFiles($movedFiles, $absoluteDirectory);
                throw new RuntimeException('No fue posible guardar una de las fotografías finales.');
            }
            @chmod($absolutePath, 0660);
            $movedFiles[] = $absolutePath;
            $hash = hash_file('sha256', $absolutePath);
            if ($hash === false) {
                $this->cleanupCompletionFiles($movedFiles, $absoluteDirectory);
                throw new RuntimeException('No fue posible calcular la integridad de una fotografía final.');
            }
            $insert->execute([
                'ticket_id' => $ticketId,
                'uploaded_by_user_id' => $userId,
                'original_name' => $file['original_name'],
                'stored_name' => $storedName,
                'storage_path' => $relativePath,
                'mime_type' => $file['mime_type'],
                'extension' => $file['extension'],
                'size_bytes' => $file['size'],
                'sha256_hash' => $hash,
            ]);
            $rows[] = [
                'id' => (int) $this->pdo->lastInsertId(),
                'original_name' => (string) $file['original_name'],
                'storage_path' => $relativePath,
                'size_bytes' => (int) $file['size'],
            ];
        }
        return [$absoluteDirectory, $rows, $movedFiles];
    }

    /** @param array<int,string> $movedFiles */
    private function cleanupCompletionFiles(array $movedFiles, ?string $absoluteDirectory): void
    {
        foreach ($movedFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if ($absoluteDirectory !== null && is_dir($absoluteDirectory)) {
            $entries = @scandir($absoluteDirectory);
            if (is_array($entries) && count($entries) <= 2) {
                @rmdir($absoluteDirectory);
            }
        }
    }

    private function completionSetting(string $key, string $fallback): string
    {
        $statement = $this->pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();
        return $value === false ? $fallback : (string) $value;
    }

    private function normalizeComment(string $body): string
    {
        $body = preg_replace('/\R/u', "\n", trim($body)) ?? trim($body);
        $length = $this->textLength($body);
        if ($length < 2) {
            throw new HttpException(422, 'comment_too_short', 'El comentario debe contener al menos 2 caracteres.');
        }
        if ($length > self::MAX_COMMENT_LENGTH) {
            throw new HttpException(422, 'comment_too_long', 'El comentario no puede exceder 3000 caracteres.');
        }
        return $body;
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
