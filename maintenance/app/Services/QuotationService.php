<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Http;
use App\Exceptions\HttpException;
use PDO;
use RuntimeException;
use Throwable;

final class QuotationService
{
    private const DEFAULT_MAX_FILE_MB = 15;
    private const MAX_SUPPLIER_LENGTH = 180;
    private const MAX_REFERENCE_LENGTH = 100;
    private const MAX_DESCRIPTION_LENGTH = 5000;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditService $audit,
        private readonly MailerService $mailer
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    public function create(array $user, array $input, array $files): array
    {
        AuthorizationService::requirePermission($user, 'ticket.upload.quotation');

        $ticketId = filter_var($input['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        $rowVersion = filter_var($input['row_version'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($ticketId) || $ticketId < 1) {
            throw new HttpException(404, 'ticket_not_found', 'El ticket no existe.');
        }
        if (!is_int($rowVersion) || $rowVersion < 1) {
            throw new HttpException(422, 'invalid_row_version', 'La versión del ticket no es válida.');
        }

        $supplierName = $this->normalizeText((string) ($input['supplier_name'] ?? ''), self::MAX_SUPPLIER_LENGTH, true, 'supplier_name', 'Indica el proveedor de la cotización.');
        $referenceNumber = $this->normalizeText((string) ($input['reference_number'] ?? ''), self::MAX_REFERENCE_LENGTH, false, 'reference_number');
        $description = $this->normalizeText((string) ($input['description'] ?? ''), self::MAX_DESCRIPTION_LENGTH, true, 'description', 'Describe brevemente el trabajo cotizado.');
        $amount = $this->normalizeAmount((string) ($input['amount'] ?? ''));
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'MXN')));
        if ($currency !== 'MXN') {
            throw new HttpException(422, 'invalid_currency', 'Por ahora las cotizaciones deben registrarse en MXN.');
        }
        $validUntil = $this->normalizeDate((string) ($input['valid_until'] ?? ''));
        $maxFileMb = $this->quotationMaxFileMb();
        $file = $this->validatePdf($files['quotation_file'] ?? null, $maxFileMb);

        $actorId = (int) ($user['id'] ?? 0);
        $requestId = Http::requestId();
        $absoluteFilePath = null;
        $absoluteDirectory = null;
        $revisionTransition = null;
        $revisionComment = null;

        $this->pdo->beginTransaction();
        try {
            $ticket = $this->loadTicketForUpdate($ticketId);
            $this->assertTicketAccess($user, $ticket);
            $this->assertSupervisorControl($user, $ticket);

            if ((int) $ticket['row_version'] !== $rowVersion) {
                throw new HttpException(409, 'ticket_changed', 'El ticket fue actualizado por otro usuario. Recarga el expediente antes de continuar.');
            }
            if ((string) $ticket['processing_route'] !== 'authorization_required') {
                throw new HttpException(422, 'quotation_route_required', 'Este ticket no pertenece a la ruta de cotización y autorización.');
            }
            $statusCode = (string) $ticket['status_code'];
            if (!in_array($statusCode, ['quotation_pending', 'changes_requested'], true)) {
                throw new HttpException(422, 'quotation_status_invalid', 'Solo pueden cargarse cotizaciones cuando el ticket está en Cotización pendiente o cuando Dirección solicitó cambios.');
            }

            $isRevisionAfterChanges = $statusCode === 'changes_requested';
            if ($isRevisionAfterChanges) {
                AuthorizationService::requirePermission($user, 'ticket.change_status');
                $revisionTransition = $this->findTransition((int) $ticket['current_status_id'], 'quotation_pending');
                AuthorizationService::requirePermission($user, (string) $revisionTransition['permission_code']);
            }

            $current = $this->loadCurrentQuotationForUpdate($ticketId);
            $versionNumber = $this->nextVersionNumber($ticketId);

            [$absoluteDirectory, $absoluteFilePath, $storedName, $relativePath, $sha256] = $this->storePdf($ticketId, $file);

            $insertAttachment = $this->pdo->prepare(
                'INSERT INTO ticket_attachments (
                    ticket_id, comment_id, uploaded_by_user_id, attachment_type,
                    original_name, stored_name, storage_disk, storage_path,
                    mime_type, extension, size_bytes, sha256_hash
                 ) VALUES (
                    :ticket_id, NULL, :uploaded_by_user_id, \'quotation\',
                    :original_name, :stored_name, \'local\', :storage_path,
                    :mime_type, \'pdf\', :size_bytes, :sha256_hash
                 )'
            );
            $insertAttachment->execute([
                'ticket_id' => $ticketId,
                'uploaded_by_user_id' => $actorId,
                'original_name' => $file['original_name'],
                'stored_name' => $storedName,
                'storage_path' => $relativePath,
                'mime_type' => 'application/pdf',
                'size_bytes' => $file['size'],
                'sha256_hash' => $sha256,
            ]);
            $attachmentId = (int) $this->pdo->lastInsertId();

            if ($current !== null) {
                $replace = $this->pdo->prepare(
                    'UPDATE ticket_quotations
                     SET status = \'replaced\', is_current = 0, updated_at = UTC_TIMESTAMP()
                     WHERE id = :id AND ticket_id = :ticket_id AND is_current = 1'
                );
                $replace->execute([
                    'id' => (int) $current['id'],
                    'ticket_id' => $ticketId,
                ]);
            }

            $insertQuotation = $this->pdo->prepare(
                'INSERT INTO ticket_quotations (
                    ticket_id, attachment_id, previous_quotation_id, version_number,
                    supplier_name, reference_number, amount, currency, valid_until,
                    description, status, is_current, uploaded_by_user_id
                 ) VALUES (
                    :ticket_id, :attachment_id, :previous_quotation_id, :version_number,
                    :supplier_name, :reference_number, :amount, :currency, :valid_until,
                    :description, \'current\', 1, :uploaded_by_user_id
                 )'
            );
            $insertQuotation->execute([
                'ticket_id' => $ticketId,
                'attachment_id' => $attachmentId,
                'previous_quotation_id' => $current === null ? null : (int) $current['id'],
                'version_number' => $versionNumber,
                'supplier_name' => $supplierName,
                'reference_number' => $referenceNumber,
                'amount' => $amount,
                'currency' => $currency,
                'valid_until' => $validUntil,
                'description' => $description,
                'uploaded_by_user_id' => $actorId,
            ]);
            $quotationId = (int) $this->pdo->lastInsertId();

            if ($isRevisionAfterChanges) {
                $updateTicket = $this->pdo->prepare(
                    'UPDATE tickets
                     SET current_status_id = :to_status_id,
                         action_owner_user_id = :action_owner_user_id,
                         row_version = row_version + 1,
                         updated_at = UTC_TIMESTAMP()
                     WHERE id = :id
                       AND current_status_id = :from_status_id
                       AND row_version = :row_version'
                );
                $updateTicket->execute([
                    'to_status_id' => (int) $revisionTransition['to_status_id'],
                    'action_owner_user_id' => (int) $ticket['supervisor_user_id'],
                    'id' => $ticketId,
                    'from_status_id' => (int) $ticket['current_status_id'],
                    'row_version' => $rowVersion,
                ]);
            } else {
                $updateTicket = $this->pdo->prepare(
                    'UPDATE tickets
                     SET row_version = row_version + 1,
                         updated_at = UTC_TIMESTAMP()
                     WHERE id = :id AND row_version = :row_version'
                );
                $updateTicket->execute([
                    'id' => $ticketId,
                    'row_version' => $rowVersion,
                ]);
            }
            if ($updateTicket->rowCount() !== 1) {
                throw new HttpException(409, 'ticket_changed', 'El ticket fue actualizado por otro usuario. Recarga el expediente antes de continuar.');
            }

            if ($isRevisionAfterChanges) {
                $revisionComment = sprintf(
                    'Se cargó la versión %d de la cotización para atender los cambios solicitados por Dirección.',
                    $versionNumber
                );
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
                    'to_status_id' => (int) $revisionTransition['to_status_id'],
                    'changed_by_user_id' => $actorId,
                    'comment' => $revisionComment,
                    'metadata_json' => json_encode([
                        'request_id' => $requestId,
                        'quotation_id' => $quotationId,
                        'version_number' => $versionNumber,
                        'previous_quotation_id' => $current === null ? null : (int) $current['id'],
                        'reason' => 'changes_requested',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]);

            }

            $this->audit->record(
                'ticket.quotation.create',
                'ticket_quotation',
                $quotationId,
                $actorId,
                $ticketId,
                [
                    'ticket_status_code' => (string) $ticket['status_code'],
                    'current_quotation_id' => $current === null ? null : (int) $current['id'],
                    'version_number' => $current === null ? null : (int) $current['version_number'],
                    'quotation_status' => $current === null ? null : (string) $current['status'],
                    'row_version' => $rowVersion,
                ],
                [
                    'quotation_id' => $quotationId,
                    'attachment_id' => $attachmentId,
                    'version_number' => $versionNumber,
                    'supplier_name' => $supplierName,
                    'reference_number' => $referenceNumber,
                    'amount' => $amount,
                    'currency' => $currency,
                    'valid_until' => $validUntil,
                    'status' => 'current',
                    'ticket_status_code' => $isRevisionAfterChanges ? (string) $revisionTransition['to_status_code'] : (string) $ticket['status_code'],
                    'row_version' => $rowVersion + 1,
                ],
                $requestId
            );

            if ($isRevisionAfterChanges) {
                $this->audit->record(
                    'ticket.quotation.revision.submit',
                    'ticket',
                    $ticketId,
                    $actorId,
                    $ticketId,
                    [
                        'status_code' => (string) $ticket['status_code'],
                        'quotation_id' => $current === null ? null : (int) $current['id'],
                        'row_version' => $rowVersion,
                    ],
                    [
                        'status_code' => (string) $revisionTransition['to_status_code'],
                        'quotation_id' => $quotationId,
                        'version_number' => $versionNumber,
                        'action_owner_user_id' => (int) $ticket['supervisor_user_id'],
                        'row_version' => $rowVersion + 1,
                    ],
                    $requestId
                );
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (is_string($absoluteFilePath) && is_file($absoluteFilePath)) {
                @unlink($absoluteFilePath);
            }
            if (is_string($absoluteDirectory) && is_dir($absoluteDirectory)) {
                $entries = @scandir($absoluteDirectory);
                if (is_array($entries) && count($entries) <= 2) {
                    @rmdir($absoluteDirectory);
                }
            }
            throw $exception;
        }



        return [
            'quotation_id' => $quotationId,
            'attachment_id' => $attachmentId,
            'version_number' => $versionNumber,
            'status_changed' => $revisionTransition !== null,
            'status_code' => $revisionTransition !== null ? (string) $revisionTransition['to_status_code'] : (string) $ticket['status_code'],
            'row_version' => $rowVersion + 1,
        ];
    }

    /** @return array<string, mixed> */
    private function loadTicketForUpdate(int $ticketId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.*, s.code AS status_code
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
    private function loadCurrentQuotationForUpdate(int $ticketId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, version_number, status
             FROM ticket_quotations
             WHERE ticket_id = :ticket_id AND is_current = 1
             ORDER BY version_number DESC
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['ticket_id' => $ticketId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function nextVersionNumber(int $ticketId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(version_number), 0)
             FROM ticket_quotations
             WHERE ticket_id = :ticket_id'
        );
        $statement->execute(['ticket_id' => $ticketId]);
        return ((int) $statement->fetchColumn()) + 1;
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $ticket */
    private function assertTicketAccess(array $user, array $ticket): void
    {
        if (AuthorizationService::hasPermission($user, 'ticket.view.all')) {
            return;
        }

        $userId = (int) ($user['id'] ?? 0);
        $canViewOwn = AuthorizationService::hasPermission($user, 'ticket.view.own')
            && $userId === (int) ($ticket['reported_by_user_id'] ?? 0);
        $canViewAssigned = AuthorizationService::hasPermission($user, 'ticket.view.assigned')
            && in_array($userId, [
                (int) ($ticket['supervisor_user_id'] ?? 0),
                (int) ($ticket['director_user_id'] ?? 0),
                (int) ($ticket['action_owner_user_id'] ?? 0),
            ], true);

        if (!$canViewOwn && !$canViewAssigned) {
            throw new HttpException(403, 'ticket_access_denied', 'No tienes acceso a este ticket.');
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
            throw new HttpException(403, 'supervisor_action_required', 'Solo el supervisor responsable puede administrar las cotizaciones del ticket.');
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
        $statement->execute([
            'from_status_id' => $fromStatusId,
            'to_status_code' => $toStatusCode,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new HttpException(422, 'quotation_revision_transition_missing', 'No está configurado el regreso de Correcciones solicitadas a Cotización pendiente.');
        }
        return $row;
    }

    /** @return array<string, mixed>|null */
    private function loadActiveUser(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }
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

    /**
     * @param mixed $rawFile
     * @return array{tmp_name:string,original_name:string,size:int}
     */
    private function validatePdf(mixed $rawFile, int $maxFileMb): array
    {
        if (!is_array($rawFile)) {
            throw new HttpException(422, 'quotation_file_required', 'Adjunta el archivo PDF de la cotización.');
        }

        $error = (int) ($rawFile['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $message = match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El PDF excede el tamaño permitido por el servidor.',
                UPLOAD_ERR_NO_FILE => 'Adjunta el archivo PDF de la cotización.',
                default => 'No fue posible recibir el archivo PDF de la cotización.',
            };
            throw new HttpException(422, 'quotation_file_invalid', $message);
        }

        $tmpName = (string) ($rawFile['tmp_name'] ?? '');
        $originalName = trim((string) ($rawFile['name'] ?? 'cotizacion.pdf'));
        $size = (int) ($rawFile['size'] ?? 0);

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new HttpException(422, 'quotation_file_invalid', 'El archivo recibido no es una carga válida.');
        }
        $maxBytes = $maxFileMb * 1024 * 1024;
        if ($size < 1 || $size > $maxBytes) {
            throw new HttpException(422, 'quotation_file_size', 'El PDF debe pesar como máximo ' . $maxFileMb . ' MB.');
        }
        if ($this->textLength($originalName) > 255) {
            throw new HttpException(422, 'quotation_filename_too_long', 'El nombre del archivo es demasiado largo.');
        }
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new HttpException(422, 'quotation_file_type', 'La cotización debe adjuntarse en formato PDF.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);
        if ($mimeType !== 'application/pdf') {
            throw new HttpException(422, 'quotation_file_type', 'El contenido del archivo no corresponde a un PDF válido.');
        }

        $handle = fopen($tmpName, 'rb');
        if ($handle === false) {
            throw new HttpException(422, 'quotation_file_invalid', 'No fue posible validar el archivo PDF.');
        }
        $signature = fread($handle, 5);
        fclose($handle);
        if ($signature !== '%PDF-') {
            throw new HttpException(422, 'quotation_file_type', 'El archivo no contiene una firma PDF válida.');
        }

        return [
            'tmp_name' => $tmpName,
            'original_name' => $originalName === '' ? 'cotizacion.pdf' : $originalName,
            'size' => $size,
        ];
    }

    /**
     * @param array{tmp_name:string,original_name:string,size:int} $file
     * @return array{0:string,1:string,2:string,3:string,4:string}
     */
    private function storePdf(int $ticketId, array $file): array
    {
        $relativeDirectory = sprintf('uploads/tickets/%s/%s/%d/quotations', gmdate('Y'), gmdate('m'), $ticketId);
        $absoluteDirectory = ROOT_PATH . '/storage/' . $relativeDirectory;
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0770, true) && !is_dir($absoluteDirectory)) {
            throw new RuntimeException('No fue posible preparar el almacenamiento de cotizaciones.');
        }

        $storedName = bin2hex(random_bytes(20)) . '.pdf';
        $absolutePath = $absoluteDirectory . '/' . $storedName;
        $relativePath = $relativeDirectory . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
            throw new RuntimeException('No fue posible guardar el PDF de la cotización.');
        }
        @chmod($absolutePath, 0660);

        $hash = hash_file('sha256', $absolutePath);
        if ($hash === false) {
            @unlink($absolutePath);
            throw new RuntimeException('No fue posible calcular la integridad del PDF de la cotización.');
        }

        return [$absoluteDirectory, $absolutePath, $storedName, $relativePath, $hash];
    }

    private function quotationMaxFileMb(): int
    {
        $statement = $this->pdo->prepare(
            'SELECT setting_value
             FROM system_settings
             WHERE setting_key = \'upload.quotation.max_size_mb\'
             LIMIT 1'
        );
        $statement->execute();
        $value = filter_var($statement->fetchColumn(), FILTER_VALIDATE_INT);
        if (!is_int($value) || $value < 1 || $value > 100) {
            return self::DEFAULT_MAX_FILE_MB;
        }
        return $value;
    }

    private function normalizeAmount(string $value): string
    {
        $value = trim(str_replace([',', ' '], ['', ''], $value));
        if ($value === '' || preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $value) !== 1) {
            throw new HttpException(422, 'invalid_amount', 'Captura un importe válido con máximo dos decimales.');
        }

        [$integer, $decimals] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $decimals = str_pad($decimals, 2, '0');

        if ($integer === '0' && $decimals === '00') {
            throw new HttpException(422, 'invalid_amount', 'El importe de la cotización debe ser mayor a cero.');
        }
        if (strlen($integer) > 12) {
            throw new HttpException(422, 'invalid_amount', 'El importe excede el máximo permitido.');
        }

        return $integer . '.' . $decimals;
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new HttpException(422, 'invalid_valid_until', 'La fecha de vigencia no es válida.');
        }
        return $value;
    }

    private function normalizeText(string $value, int $maxLength, bool $required, string $fieldCode, ?string $requiredMessage = null): ?string
    {
        $value = preg_replace('/\R/u', "\n", trim($value)) ?? trim($value);
        if ($value === '') {
            if ($required) {
                throw new HttpException(422, $fieldCode . '_required', $requiredMessage ?? 'Completa los campos requeridos.');
            }
            return null;
        }
        if ($this->textLength($value) > $maxLength) {
            throw new HttpException(422, $fieldCode . '_too_long', 'Uno de los campos excede la longitud permitida.');
        }
        return $value;
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
