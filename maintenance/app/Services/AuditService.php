<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Http;
use PDO;
use RuntimeException;
use Throwable;

final class AuditService
{
    private const APPLICATION_CODE = 'maintenance';

    private ?int $applicationId = null;

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * El quinto argumento conserva temporalmente el contrato legacy
     * de Maintenance para no modificar todos los consumidores todavía.
     *
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public function record(
        string $actionCode,
        string $entityType,
        ?int $entityId = null,
        ?int $actorUserId = null,
        ?int $legacyTicketId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $requestId = null
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log (
                actor_user_id,
                application_id,
                action_code,
                entity_type,
                entity_id,
                request_id,
                ip_address,
                user_agent,
                old_values_json,
                new_values_json
            ) VALUES (
                :actor_user_id,
                :application_id,
                :action_code,
                :entity_type,
                :entity_id,
                :request_id,
                :ip_address,
                :user_agent,
                :old_values_json,
                :new_values_json
            )'
        );

        $statement->execute([
            'actor_user_id' => $actorUserId,
            'application_id' => $this->applicationId(),
            'action_code' => $actionCode,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'request_id' => $requestId ?? Http::requestId(),
            'ip_address' => Http::clientIp(),
            'user_agent' => Http::userAgent(),
            'old_values_json' => $oldValues === null
                ? null
                : json_encode(
                    $oldValues,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
                ),
            'new_values_json' => $newValues === null
                ? null
                : json_encode(
                    $newValues,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
                ),
        ]);
    }

    /**
     * @param array<string, mixed>|null $newValues
     */
    public function safeRecord(
        string $actionCode,
        string $entityType,
        ?int $entityId = null,
        ?int $actorUserId = null,
        ?array $newValues = null
    ): void {
        try {
            $this->record(
                $actionCode,
                $entityType,
                $entityId,
                $actorUserId,
                null,
                null,
                $newValues
            );
        } catch (Throwable $exception) {
            error_log(
                'No fue posible registrar auditoría Maintenance: '
                . $exception->getMessage()
            );
        }
    }

    private function applicationId(): int
    {
        if ($this->applicationId !== null) {
            return $this->applicationId;
        }

        $statement = $this->pdo->prepare(
            'SELECT id
             FROM applications
             WHERE code = :code
             LIMIT 1'
        );

        $statement->execute([
            'code' => self::APPLICATION_CODE,
        ]);

        $id = $statement->fetchColumn();

        if ($id === false) {
            throw new RuntimeException(
                'No existe la aplicación maintenance '
                . 'en el catálogo global.'
            );
        }

        $this->applicationId = (int) $id;

        return $this->applicationId;
    }
}