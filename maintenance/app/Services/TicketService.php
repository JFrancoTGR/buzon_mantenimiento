<?php

declare (strict_types = 1);

namespace App\Services;

use App\Core\Http;
use App\Exceptions\HttpException;
use finfo;
use PDO;
use RuntimeException;
use Throwable;

final class TicketService
{
    private const MAX_DESCRIPTION_LENGTH = 5000;
    private const MAX_IMAGE_PIXELS       = 40000000;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditService $audit,
        private readonly MailerService $mailer
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function context(array $user, ?string $locationCode): array
    {
        $locationsStatement = $this->pdo->query(
            "SELECT
                l.id,
                l.code,
                l.name,
                l.default_supervisor_user_id,
                CASE
                    WHEN u.id IS NOT NULL
                     AND u.status = 'active'
                     AND EXISTS (
                        SELECT 1
                        FROM user_application_roles uar
INNER JOIN applications a
    ON a.id = uar.application_id
   AND a.code = 'maintenance'
   AND a.is_active = 1
INNER JOIN application_roles r
    ON r.id = uar.role_id
   AND r.application_id = uar.application_id
   AND r.is_active = 1
WHERE uar.user_id = u.id
  AND uar.revoked_at IS NULL
  AND r.code = 'supervisor'
                     ) THEN 1
                    ELSE 0
                END AS has_active_supervisor
             FROM locations l
             LEFT JOIN users u ON u.id = l.default_supervisor_user_id
             WHERE l.is_active = 1
             ORDER BY l.sort_order, l.name"
        );

        $locations = array_map(
            static fn(array $row): array=> [
                'id'                    => (int) $row['id'],
                'code'                  => (string) $row['code'],
                'name'                  => (string) $row['name'],
                'has_active_supervisor' => (bool) $row['has_active_supervisor'],
            ],
            $locationsStatement->fetchAll()
        );

        $prioritiesStatement = $this->pdo->query(
            'SELECT id, code, name, weight, color_reference
             FROM ticket_priorities
             WHERE is_active = 1
             ORDER BY weight, name'
        );

        $priorities = array_map(
            static fn(array $row): array=> [
                'id'              => (int) $row['id'],
                'code'            => (string) $row['code'],
                'name'            => (string) $row['name'],
                'weight'          => (int) $row['weight'],
                'color_reference' => $row['color_reference'] !== null
                    ? (string) $row['color_reference']
                    : null,
            ],
            $prioritiesStatement->fetchAll()
        );

        $selectedLocation = null;
        if ($locationCode !== null && preg_match('/^[a-z0-9_]{1,50}$/', $locationCode) === 1) {
            foreach ($locations as $location) {
                if ($location['code'] === $locationCode) {
                    $selectedLocation = $location;
                    break;
                }
            }
        }

        $defaultPriority = $this->setting('ticket.default_priority', 'medium');
        $maxFiles        = max(1, min(20, (int) $this->setting('upload.evidence.max_files', '8')));
        $maxSizeMb       = max(1, min(25, (int) $this->setting('upload.evidence.max_size_mb', '8')));

        return [
            'reporter'           => [
                'id'        => (int) $user['id'],
                'full_name' => (string) $user['full_name'],
                'email'     => (string) $user['email'],
            ],
            'locations'          => $locations,
            'selected_location'  => $selectedLocation,
            'requested_location' => $locationCode,
            'priorities'         => $priorities,
            'default_priority'   => $defaultPriority,
            'upload'             => [
                'max_files'          => $maxFiles,
                'max_size_mb'        => $maxSizeMb,
                'allowed_mime_types' => ['image/jpeg', 'image/png'],
                'allowed_extensions' => ['jpg', 'jpeg', 'png'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    public function create(array $user, array $input, array $files): array
    {
        $reporterId  = (int) $user['id'];
        $title       = $this->normalizeRequiredText((string) ($input['title'] ?? ''), 5, 180, 'título');
        $description = $this->normalizeRequiredText(
            (string) ($input['description'] ?? ''),
            15,
            self::MAX_DESCRIPTION_LENGTH,
            'descripción'
        );
        $specificLocation = $this->normalizeRequiredText(
            (string) ($input['specific_location'] ?? ''),
            3,
            255,
            'zona del desperfecto'
        );
        $locationCode = trim((string) ($input['location'] ?? ''));
        $priorityCode = trim((string) ($input['priority'] ?? ''));

        if (preg_match('/^[a-z0-9_]{1,50}$/', $locationCode) !== 1) {
            throw new HttpException(422, 'invalid_location', 'Selecciona una ubicación válida.');
        }

        if (preg_match('/^[a-z0-9_]{1,40}$/', $priorityCode) !== 1) {
            throw new HttpException(422, 'invalid_priority', 'Selecciona una prioridad válida.');
        }

        $location         = $this->findActiveLocationWithSupervisor($locationCode);
        $priority         = $this->findActivePriority($priorityCode);
        $newStatusId      = $this->findStatusId('new');
        $validatedFiles   = $this->validateEvidenceFiles($files['evidence'] ?? null);
        $requestId        = Http::requestId();
        $movedFiles       = [];
        $storageDirectory = null;

        $supervisor = $location['supervisor'];
        if ($supervisor === null) {
            throw new HttpException(
                422,
                'supervisor_not_configured',
                'La ubicación seleccionada no tiene un Supervisor activo configurado.'
            );
        }

        $this->pdo->beginTransaction();

        try {
            $folio = $this->nextFolio();

            $insertTicket = $this->pdo->prepare(
                'INSERT INTO tickets (
                    folio,
                    title,
                    description,
                    reported_by_user_id,
                    location_id,
                    specific_location,
                    priority_id,
                    current_status_id,
                    supervisor_user_id,
                    director_user_id,
                    action_owner_user_id,
                    submitted_at,
                    row_version
                 ) VALUES (
                    :folio,
                    :title,
                    :description,
                    :reported_by_user_id,
                    :location_id,
                    :specific_location,
                    :priority_id,
                    :current_status_id,
                    :supervisor_user_id,
                    NULL,
                    :action_owner_user_id,
                    UTC_TIMESTAMP(),
                    1
                 )'
            );
            $insertTicket->execute([
                'folio'                => $folio,
                'title'                => $title,
                'description'          => $description,
                'reported_by_user_id'  => $reporterId,
                'location_id'          => (int) $location['id'],
                'specific_location'    => $specificLocation,
                'priority_id'          => (int) $priority['id'],
                'current_status_id'    => $newStatusId,
                'supervisor_user_id'   => $supervisor['id'] ?? null,
                'action_owner_user_id' => $supervisor['id'] ?? null,
            ]);
            $ticketId = (int) $this->pdo->lastInsertId();

            $insertStatus = $this->pdo->prepare(
                'INSERT INTO ticket_status_history (
                    ticket_id,
                    from_status_id,
                    to_status_id,
                    changed_by_user_id,
                    comment,
                    change_source,
                    metadata_json
                 ) VALUES (
                    :ticket_id,
                    NULL,
                    :to_status_id,
                    :changed_by_user_id,
                    :comment,
                    \'system\',
                    :metadata_json
                 )'
            );
            $insertStatus->execute([
                'ticket_id'          => $ticketId,
                'to_status_id'       => $newStatusId,
                'changed_by_user_id' => $reporterId,
                'comment'            => 'Reporte creado por el usuario.',
                'metadata_json'      => json_encode([
                    'location_code' => $location['code'],
                    'priority_code' => $priority['code'],
                    'request_id'    => $requestId,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);

            if ($supervisor !== null) {
                $this->insertAssignmentHistory(
                    $ticketId,
                    'supervisor',
                    (int) $supervisor['id'],
                    $reporterId,
                    'Asignación automática según la ubicación del reporte.'
                );
                $this->insertAssignmentHistory(
                    $ticketId,
                    'action_owner',
                    (int) $supervisor['id'],
                    $reporterId,
                    'Responsable inicial de revisar el nuevo reporte.'
                );
            }

            [$storageDirectory, $attachmentRows, $movedFiles] = $this->storeEvidenceFiles(
                $ticketId,
                $reporterId,
                $validatedFiles
            );

            foreach ($attachmentRows as $attachmentRow) {
                $this->audit->record(
                    'ticket.attachment.upload',
                    'ticket_attachment',
                    (int) $attachmentRow['id'],
                    $reporterId,
                    $ticketId,
                    null,
                    [
                        'attachment_type' => 'evidence',
                        'original_name'   => (string) $attachmentRow['original_name'],
                        'mime_type'       => (string) $attachmentRow['mime_type'],
                        'size_bytes'      => (int) $attachmentRow['size_bytes'],
                    ],
                    $requestId
                );
            }

            if ((int) $supervisor['id'] !== $reporterId) {
                $this->insertNotification(
                    (int) $supervisor['id'],
                    $ticketId,
                    $reporterId,
                    'ticket.created',
                    'Nuevo reporte asignado',
                    sprintf('%s · %s · %s', $folio, $location['name'], $specificLocation),
                    './ticket.html?id=' . $ticketId,
                    sprintf('ticket.created:%d:supervisor:%d', $ticketId, (int) $supervisor['id'])
                );
            }

            $this->audit->record(
                'ticket.create',
                'ticket',
                $ticketId,
                $reporterId,
                $ticketId,
                null,
                [
                    'folio'              => $folio,
                    'location_id'        => (int) $location['id'],
                    'location_code'      => $location['code'],
                    'specific_location'  => $specificLocation,
                    'priority_id'        => (int) $priority['id'],
                    'priority_code'      => $priority['code'],
                    'status'             => 'new',
                    'supervisor_user_id' => $supervisor['id'] ?? null,
                    'attachments'        => count($attachmentRows),
                ],
                $requestId
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->cleanupMovedFiles($movedFiles, $storageDirectory);
            throw $exception;
        }

        $emailResults = [
            'reporter'   => false,
            'supervisor' => false,
        ];

        try {
            $this->mailer->sendTicketCreatedConfirmationToReporter(
                $ticketId,
                $reporterId,
                (string) $user['email'],
                (string) $user['full_name'],
                $folio,
                $title,
                (string) $location['name'],
                $specificLocation,
                (string) $priority['name']
            );
            $emailResults['reporter'] = true;
        } catch (Throwable $exception) {
            $this->audit->safeRecord(
                'ticket.email.reporter_failed',
                'ticket',
                $ticketId,
                $reporterId,
                ['folio' => $folio]
            );
        }

        if ((int) $supervisor['id'] !== $reporterId) {
            try {
                $this->mailer->sendTicketCreatedToSupervisor(
                    $ticketId,
                    (int) $supervisor['id'],
                    (string) $supervisor['email'],
                    (string) $supervisor['full_name'],
                    (string) $user['full_name'],
                    $folio,
                    $title,
                    (string) $location['name'],
                    $specificLocation,
                    (string) $priority['name'],
                    count($validatedFiles)
                );
                $emailResults['supervisor'] = true;
            } catch (Throwable $exception) {
                $this->audit->safeRecord(
                    'ticket.email.supervisor_failed',
                    'ticket',
                    $ticketId,
                    $reporterId,
                    ['folio' => $folio, 'supervisor_user_id' => (int) $supervisor['id']]
                );
            }
        } else {
            // El mismo usuario creó el ticket y quedó como Supervisor:
            // la confirmación al reportante es suficiente.
            $emailResults['supervisor'] = true;
        }

        return [
            'ticket'         => [
                'id'                => $ticketId,
                'folio'             => $folio,
                'title'             => $title,
                'location'          => [
                    'id'                => (int) $location['id'],
                    'code'              => (string) $location['code'],
                    'name'              => (string) $location['name'],
                    'specific_location' => $specificLocation,
                ],
                'priority'          => [
                    'id'   => (int) $priority['id'],
                    'code' => (string) $priority['code'],
                    'name' => (string) $priority['name'],
                ],
                'status'            => [
                    'code' => 'new',
                    'name' => 'Nuevo',
                ],
                'supervisor'        => $supervisor === null ? null : [
                    'id'        => (int) $supervisor['id'],
                    'full_name' => (string) $supervisor['full_name'],
                ],
                'attachments_count' => count($validatedFiles),
            ],
            'email_delivery' => $emailResults,
            'warning'        => null,
        ];
    }

    /** @return array<string, mixed> */
    private function findActiveLocationWithSupervisor(string $code): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                l.id,
                l.code,
                l.name,
                l.default_supervisor_user_id,
                u.id AS supervisor_id,
                u.first_name AS supervisor_first_name,
                u.last_name AS supervisor_last_name,
                u.email AS supervisor_email,
                u.status AS supervisor_status,
                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM user_application_roles uar
INNER JOIN applications a
    ON a.id = uar.application_id
   AND a.code = 'maintenance'
   AND a.is_active = 1
INNER JOIN application_roles r
    ON r.id = uar.role_id
   AND r.application_id = uar.application_id
   AND r.is_active = 1
WHERE uar.user_id = u.id
  AND uar.revoked_at IS NULL
  AND r.code = 'supervisor'
                    ) THEN 1
                    ELSE 0
                END AS supervisor_has_role
             FROM locations l
             LEFT JOIN users u ON u.id = l.default_supervisor_user_id
             WHERE l.code = :code AND l.is_active = 1
             LIMIT 1"
        );
        $statement->execute(['code' => $code]);
        $row = $statement->fetch();

        if (! is_array($row)) {
            throw new HttpException(422, 'invalid_location', 'La ubicación seleccionada no está disponible.');
        }

        $supervisor = null;
        if (
            $row['supervisor_id'] !== null
            && (string) $row['supervisor_status'] === 'active'
            && (bool) $row['supervisor_has_role']
        ) {
            $supervisor = [
                'id'        => (int) $row['supervisor_id'],
                'full_name' => trim((string) $row['supervisor_first_name'] . ' ' . (string) $row['supervisor_last_name']),
                'email'     => (string) $row['supervisor_email'],
            ];
        }

        return [
            'id'         => (int) $row['id'],
            'code'       => (string) $row['code'],
            'name'       => (string) $row['name'],
            'supervisor' => $supervisor,
        ];
    }

    /** @return array<string, mixed> */
    private function findActivePriority(string $code): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, code, name, weight
             FROM ticket_priorities
             WHERE code = :code AND is_active = 1
             LIMIT 1'
        );
        $statement->execute(['code' => $code]);
        $row = $statement->fetch();

        if (! is_array($row)) {
            throw new HttpException(422, 'invalid_priority', 'La prioridad seleccionada no está disponible.');
        }

        return [
            'id'     => (int) $row['id'],
            'code'   => (string) $row['code'],
            'name'   => (string) $row['name'],
            'weight' => (int) $row['weight'],
        ];
    }

    private function findStatusId(string $code): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM ticket_statuses WHERE code = :code AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['code' => $code]);
        $id = $statement->fetchColumn();

        if ($id === false) {
            throw new HttpException(500, 'status_missing', 'El estado inicial del ticket no está configurado.');
        }

        return (int) $id;
    }

    private function nextFolio(): string
    {
        $prefix = strtoupper(trim($this->setting('ticket.folio_prefix', 'MNT')));
        if (preg_match('/^[A-Z0-9-]{2,12}$/', $prefix) !== 1) {
            $prefix = 'MNT';
        }

        $year   = (int) gmdate('Y');
        $ensure = $this->pdo->prepare(
            'INSERT INTO folio_sequences (sequence_code, sequence_year, last_value)
             VALUES (:sequence_code, :sequence_year, 0)
             ON DUPLICATE KEY UPDATE last_value = last_value'
        );
        $ensure->execute([
            'sequence_code' => $prefix,
            'sequence_year' => $year,
        ]);

        $lock = $this->pdo->prepare(
            'SELECT last_value
             FROM folio_sequences
             WHERE sequence_code = :sequence_code AND sequence_year = :sequence_year
             FOR UPDATE'
        );
        $lock->execute([
            'sequence_code' => $prefix,
            'sequence_year' => $year,
        ]);
        $lastValue = $lock->fetchColumn();

        if ($lastValue === false) {
            throw new RuntimeException('No fue posible reservar la secuencia del folio.');
        }

        $nextValue = (int) $lastValue + 1;
        $update    = $this->pdo->prepare(
            'UPDATE folio_sequences
             SET last_value = :last_value, updated_at = UTC_TIMESTAMP()
             WHERE sequence_code = :sequence_code AND sequence_year = :sequence_year'
        );
        $update->execute([
            'last_value'    => $nextValue,
            'sequence_code' => $prefix,
            'sequence_year' => $year,
        ]);

        return sprintf('%s-%d-%06d', $prefix, $year, $nextValue);
    }

    private function insertAssignmentHistory(
        int $ticketId,
        string $assignmentType,
        int $newUserId,
        int $assignedByUserId,
        string $comment
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO ticket_assignment_history (
                ticket_id,
                assignment_type,
                previous_user_id,
                new_user_id,
                assigned_by_user_id,
                comment
             ) VALUES (
                :ticket_id,
                :assignment_type,
                NULL,
                :new_user_id,
                :assigned_by_user_id,
                :comment
             )'
        );
        $statement->execute([
            'ticket_id'           => $ticketId,
            'assignment_type'     => $assignmentType,
            'new_user_id'         => $newUserId,
            'assigned_by_user_id' => $assignedByUserId,
            'comment'             => $comment,
        ]);
    }

    private function insertNotification(
        int $userId,
        int $ticketId,
        int $actorUserId,
        string $type,
        string $title,
        string $message,
        string $actionUrl,
        string $deduplicationKey
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO notifications (
                user_id,
                ticket_id,
                actor_user_id,
                type,
                title,
                message,
                action_url,
                deduplication_key
             ) VALUES (
                :user_id,
                :ticket_id,
                :actor_user_id,
                :type,
                :title,
                :message,
                :action_url,
                :deduplication_key
             )'
        );
        $statement->execute([
            'user_id'           => $userId,
            'ticket_id'         => $ticketId,
            'actor_user_id'     => $actorUserId,
            'type'              => $type,
            'title'             => $title,
            'message'           => $message,
            'action_url'        => $actionUrl,
            'deduplication_key' => $deduplicationKey,
        ]);
    }

    /**
     * @param mixed $rawFiles
     * @return array<int, array<string, mixed>>
     */
    private function validateEvidenceFiles(mixed $rawFiles): array
    {
        $files        = $this->normalizeFilesArray($rawFiles);
        $maxFiles     = max(1, min(20, (int) $this->setting('upload.evidence.max_files', '8')));
        $maxSizeBytes = max(1, min(25, (int) $this->setting('upload.evidence.max_size_mb', '8'))) * 1024 * 1024;

        if ($files === []) {
            throw new HttpException(422, 'evidence_required', 'Agrega al menos una fotografía como evidencia.');
        }

        if (count($files) > $maxFiles) {
            throw new HttpException(422, 'too_many_files', "Puedes adjuntar como máximo {$maxFiles} fotografías.");
        }

        $finfo     = new finfo(FILEINFO_MIME_TYPE);
        $validated = [];
        $allowed   = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
        ];

        foreach ($files as $index => $file) {
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                throw new HttpException(
                    422,
                    'upload_failed',
                    $this->uploadErrorMessage($error, $index + 1)
                );
            }

            $tmpName = (string) ($file['tmp_name'] ?? '');
            $size    = (int) ($file['size'] ?? 0);
            if ($tmpName === '' || ! is_uploaded_file($tmpName)) {
                throw new HttpException(422, 'invalid_upload', 'Uno de los archivos no fue recibido correctamente.');
            }

            if ($size <= 0 || $size > $maxSizeBytes) {
                $maxSizeMb = (int) round($maxSizeBytes / 1024 / 1024);
                throw new HttpException(
                    422,
                    'file_too_large',
                    "Cada fotografía debe pesar como máximo {$maxSizeMb} MB."
                );
            }

            $mimeType = (string) $finfo->file($tmpName);
            if (! array_key_exists($mimeType, $allowed)) {
                throw new HttpException(422, 'invalid_file_type', 'Solo se permiten imágenes JPG, JPEG y PNG.');
            }

            $imageInfo = @getimagesize($tmpName);
            if (! is_array($imageInfo) || ! isset($imageInfo[0], $imageInfo[1])) {
                throw new HttpException(422, 'invalid_image', 'Uno de los archivos no es una imagen válida.');
            }

            $pixels = (int) $imageInfo[0] * (int) $imageInfo[1];
            if ($pixels <= 0 || $pixels > self::MAX_IMAGE_PIXELS) {
                throw new HttpException(422, 'image_dimensions_too_large', 'Una de las imágenes tiene dimensiones excesivas.');
            }

            $originalName = basename((string) ($file['name'] ?? 'evidencia.' . $allowed[$mimeType]));
            $originalName = trim($originalName) !== '' ? trim($originalName) : 'evidencia.' . $allowed[$mimeType];
            if ($this->textLength($originalName) > 255) {
                $originalName = $this->textSlice($originalName, 0, 240) . '.' . $allowed[$mimeType];
            }

            $validated[] = [
                'tmp_name'      => $tmpName,
                'size'          => $size,
                'mime_type'     => $mimeType,
                'extension'     => $allowed[$mimeType],
                'original_name' => $originalName,
                'width'         => (int) $imageInfo[0],
                'height'        => (int) $imageInfo[1],
            ];
        }

        return $validated;
    }

    /**
     * @param mixed $rawFiles
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFilesArray(mixed $rawFiles): array
    {
        if (! is_array($rawFiles) || ! isset($rawFiles['name'])) {
            return [];
        }

        if (! is_array($rawFiles['name'])) {
            return [(array) $rawFiles];
        }

        $files = [];
        foreach ($rawFiles['name'] as $index => $name) {
            $error = (int) ($rawFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE && trim((string) $name) === '') {
                continue;
            }

            $files[] = [
                'name'     => $name,
                'type'     => $rawFiles['type'][$index] ?? null,
                'tmp_name' => $rawFiles['tmp_name'][$index] ?? null,
                'error'    => $error,
                'size'     => $rawFiles['size'][$index] ?? 0,
            ];
        }

        return $files;
    }

    /**
     * @param array<int, array<string, mixed>> $validatedFiles
     * @return array{0:string,1:array<int, array<string, mixed>>,2:array<int,string>}
     */
    private function storeEvidenceFiles(int $ticketId, int $userId, array $validatedFiles): array
    {
        $relativeDirectory = sprintf('uploads/tickets/%s/%s/%d', gmdate('Y'), gmdate('m'), $ticketId);
        $absoluteDirectory = ROOT_PATH . '/storage/' . $relativeDirectory;

        if (! is_dir($absoluteDirectory) && ! mkdir($absoluteDirectory, 0770, true) && ! is_dir($absoluteDirectory)) {
            throw new RuntimeException('No fue posible preparar el almacenamiento de evidencias.');
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO ticket_attachments (
                ticket_id,
                comment_id,
                uploaded_by_user_id,
                attachment_type,
                original_name,
                stored_name,
                storage_disk,
                storage_path,
                mime_type,
                extension,
                size_bytes,
                sha256_hash
             ) VALUES (
                :ticket_id,
                NULL,
                :uploaded_by_user_id,
                \'evidence\',
                :original_name,
                :stored_name,
                \'local\',
                :storage_path,
                :mime_type,
                :extension,
                :size_bytes,
                :sha256_hash
             )'
        );

        $rows       = [];
        $movedFiles = [];

        try {
            foreach ($validatedFiles as $file) {
                $storedName   = bin2hex(random_bytes(20)) . '.' . $file['extension'];
                $absolutePath = $absoluteDirectory . '/' . $storedName;
                $relativePath = $relativeDirectory . '/' . $storedName;

                if (! move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
                    throw new RuntimeException('No fue posible guardar una de las fotografías.');
                }

                @chmod($absolutePath, 0660);
                $movedFiles[] = $absolutePath;
                $hash         = hash_file('sha256', $absolutePath);
                if ($hash === false) {
                    throw new RuntimeException('No fue posible calcular la integridad de una fotografía.');
                }

                $insert->execute([
                    'ticket_id'           => $ticketId,
                    'uploaded_by_user_id' => $userId,
                    'original_name'       => $file['original_name'],
                    'stored_name'         => $storedName,
                    'storage_path'        => $relativePath,
                    'mime_type'           => $file['mime_type'],
                    'extension'           => $file['extension'],
                    'size_bytes'          => $file['size'],
                    'sha256_hash'         => $hash,
                ]);

                $rows[] = [
                    'id'            => (int) $this->pdo->lastInsertId(),
                    'stored_name'   => $storedName,
                    'storage_path'  => $relativePath,
                    'original_name' => (string) $file['original_name'],
                    'mime_type'     => (string) $file['mime_type'],
                    'size_bytes'    => (int) $file['size'],
                ];
            }
        } catch (Throwable $exception) {
            $this->cleanupMovedFiles($movedFiles, $absoluteDirectory);
            throw $exception;
        }

        return [$absoluteDirectory, $rows, $movedFiles];
    }

    /** @param array<int, string> $movedFiles */
    private function cleanupMovedFiles(array $movedFiles, ?string $storageDirectory): void
    {
        foreach ($movedFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        if ($storageDirectory !== null && is_dir($storageDirectory)) {
            $entries = @scandir($storageDirectory);
            if (is_array($entries) && count($entries) <= 2) {
                @rmdir($storageDirectory);
            }
        }
    }

    private function setting(string $key, string $fallback): string
    {
        $statement = $this->pdo->prepare(
            'SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1'
        );
        $statement->execute(['setting_key' => $key]);
        $value = $statement->fetchColumn();
        return $value === false ? $fallback : (string) $value;
    }

    private function normalizeRequiredText(string $value, int $min, int $max, string $label): string
    {
        $value  = trim(preg_replace('/\R/u', "\n", $value) ?? $value);
        $length = $this->textLength($value);

        if ($length < $min) {
            throw new HttpException(422, 'field_too_short', "El campo {$label} debe tener al menos {$min} caracteres.");
        }

        if ($length > $max) {
            throw new HttpException(422, 'field_too_long', "El campo {$label} no puede superar {$max} caracteres.");
        }

        return $value;
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function textSlice(string $value, int $start, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, $start, $length, 'UTF-8')
            : substr($value, $start, $length);
    }

    private function uploadErrorMessage(int $error, int $position): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "La fotografía {$position} supera el tamaño permitido por el servidor.",
            UPLOAD_ERR_PARTIAL => "La fotografía {$position} se recibió de forma incompleta.",
            UPLOAD_ERR_NO_FILE => "No se recibió la fotografía {$position}.",
            UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene disponible el directorio temporal de cargas.',
            UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir una de las fotografías.',
            UPLOAD_ERR_EXTENSION => 'Una extensión del servidor detuvo la carga de una fotografía.',
            default => 'No fue posible procesar una de las fotografías.',
        };
    }
}