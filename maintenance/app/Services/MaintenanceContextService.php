<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Exceptions\HttpException;
use EUTools\Shared\Security\SessionRuntime;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class MaintenanceContextService
{
    private const APPLICATION_CODE = 'maintenance';

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /** @return array<string, mixed> */
    public function currentUser(): array
    {
        $auth = SessionRuntime::authData();

        if ($auth === null) {
            throw new HttpException(
                401,
                'authentication_required',
                'Debes iniciar sesión.'
            );
        }

        $statement = $this->pdo->prepare(
            'SELECT
                u.id,
                u.first_name,
                u.last_name,
                u.email,
                u.status,
                u.must_change_password,
                u.last_login_at,
                us.id AS database_session_id,
                us.session_hash,
                us.expires_at,
                us.revoked_at
             FROM user_sessions us
             INNER JOIN users u
                ON u.id = us.user_id
             WHERE us.id = :session_id
               AND us.user_id = :user_id
             LIMIT 1'
        );

        $statement->execute([
            'session_id' => $auth['database_session_id'],
            'user_id' => $auth['user_id'],
        ]);

        $row = $statement->fetch();

        $now = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        );

        $isInvalid =
            !is_array($row)
            || $row['revoked_at'] !== null
            || (string) $row['status'] !== 'active'
            || !hash_equals(
                (string) ($row['session_hash'] ?? ''),
                SessionRuntime::sessionHash()
            )
            || new DateTimeImmutable(
                (string) $row['expires_at'],
                new DateTimeZone('UTC')
            ) <= $now;

        if ($isInvalid) {
            throw new HttpException(
                401,
                'session_expired',
                'La sesión expiró o fue revocada.'
            );
        }

        $this->touchSession(
            (int) $auth['database_session_id'],
            $now
        );

        $access = $this->loadMaintenanceAccess(
            (int) $auth['user_id']
        );

        if ($access === null) {
            throw new HttpException(
                403,
                'application_access_denied',
                'No tienes acceso a la Plataforma de Mantenimiento.'
            );
        }

        $permissions = $this->loadPermissions(
            (int) $auth['user_id'],
            (int) $access['application_id'],
            (int) $access['role_id']
        );

        return [
            'id' => (int) $row['id'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'full_name' => trim(
                (string) $row['first_name']
                . ' '
                . (string) $row['last_name']
            ),
            'email' => (string) $row['email'],
            'must_change_password' =>
                (bool) $row['must_change_password'],
            'last_login_at' => $row['last_login_at'],
            'roles' => [
                (string) $access['role_code'],
            ],
            'permissions' => $permissions,
        ];
    }

    private function touchSession(
        int $databaseSessionId,
        DateTimeImmutable $now
    ): void {
        $newExpiry = $now
            ->add(
                new DateInterval(
                    'PT'
                    . Env::int(
                        'SESSION_LIFETIME_MINUTES',
                        30
                    )
                    . 'M'
                )
            )
            ->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'UPDATE user_sessions
             SET last_activity_at = UTC_TIMESTAMP(),
                 expires_at = :expires_at
             WHERE id = :id
               AND revoked_at IS NULL'
        );

        $statement->execute([
            'expires_at' => $newExpiry,
            'id' => $databaseSessionId,
        ]);
    }

    /**
     * @return array{
     *   application_id:int,
     *   role_id:int,
     *   role_code:string
     * }|null
     */
    private function loadMaintenanceAccess(
        int $userId
    ): ?array {
        $statement = $this->pdo->prepare(
            'SELECT
                a.id AS application_id,
                r.id AS role_id,
                r.code AS role_code
             FROM user_application_roles uar
             INNER JOIN applications a
                ON a.id = uar.application_id
               AND a.code = :application_code
               AND a.is_active = 1
             INNER JOIN application_roles r
                ON r.id = uar.role_id
               AND r.application_id = uar.application_id
               AND r.is_active = 1
             WHERE uar.user_id = :user_id
               AND uar.revoked_at IS NULL
               AND EXISTS (
                   SELECT 1
                   FROM role_permissions rp_access
                   INNER JOIN permissions p_access
                      ON p_access.id =
                         rp_access.permission_id
                     AND p_access.application_id =
                         rp_access.application_id
                   WHERE rp_access.application_id =
                         uar.application_id
                     AND rp_access.role_id =
                         uar.role_id
                     AND p_access.code = \'access\'
               )
             LIMIT 1'
        );

        $statement->execute([
            'application_code' =>
                self::APPLICATION_CODE,
            'user_id' => $userId,
        ]);

        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'application_id' =>
                (int) $row['application_id'],
            'role_id' => (int) $row['role_id'],
            'role_code' => (string) $row['role_code'],
        ];
    }

    /** @return array<int, string> */
    private function loadPermissions(
        int $userId,
        int $applicationId,
        int $roleId
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT p.code
             FROM user_application_roles uar
             INNER JOIN role_permissions rp
                ON rp.application_id =
                   uar.application_id
               AND rp.role_id = uar.role_id
             INNER JOIN permissions p
                ON p.id = rp.permission_id
               AND p.application_id =
                   uar.application_id
             WHERE uar.user_id = :user_id
               AND uar.application_id =
                   :application_id
               AND uar.role_id = :role_id
               AND uar.revoked_at IS NULL
             ORDER BY p.code'
        );

        $statement->execute([
            'user_id' => $userId,
            'application_id' => $applicationId,
            'role_id' => $roleId,
        ]);

        return array_map(
            'strval',
            array_column(
                $statement->fetchAll(),
                'code'
            )
        );
    }
}