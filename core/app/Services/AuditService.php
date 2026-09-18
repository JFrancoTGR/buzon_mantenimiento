<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Http;
use PDO;
use Throwable;

final class AuditService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public function record(
        string $actionCode,
        string $entityType,
        ?int $entityId = null,
        ?int $actorUserId = null,
        ?int $applicationId = null,
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
            'application_id' => $applicationId,
            'action_code' => $actionCode,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'request_id' => $requestId ?? Http::requestId(),
            'ip_address' => Http::clientIp(),
            'user_agent' => Http::userAgent(),
            'old_values_json' => $oldValues === null
                ? null
                : json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'new_values_json' => $newValues === null
                ? null
                : json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /** @param array<string, mixed>|null $newValues */
    public function safeRecord(
        string $actionCode,
        string $entityType,
        ?int $entityId = null,
        ?int $actorUserId = null,
        ?int $applicationId = null,
        ?array $newValues = null
    ): void {
        try {
            $this->record(
                $actionCode,
                $entityType,
                $entityId,
                $actorUserId,
                $applicationId,
                null,
                $newValues
            );
        } catch (Throwable $exception) {
            error_log('No fue posible registrar auditoría Core: ' . $exception->getMessage());
        }
    }
}