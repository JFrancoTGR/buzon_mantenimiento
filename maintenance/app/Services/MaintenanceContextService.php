<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\HttpException;
use EUTools\Core\Security\PersistentSessionService;
use EUTools\Core\Security\SessionException;
use PDO;

final class MaintenanceContextService
{
    private const APPLICATION_CODE = 'maintenance';

    public function __construct(
        private readonly PDO $pdo,
        private readonly PersistentSessionService $persistentSessions
    ) {
    }

    /** @return array<string, mixed> */
    public function currentUser(): array
    {
        try {
            $identity = $this->persistentSessions
                ->requireCurrentIdentity();
        } catch (SessionException $exception) {
            throw new HttpException(
                $exception->status,
                $exception->errorCode,
                $exception->getMessage()
            );
        }

        $userId = (int) $identity['id'];

        $access = $this->loadMaintenanceAccess(
            $userId
        );

        if ($access === null) {
            throw new HttpException(
                403,
                'application_access_denied',
                'No tienes acceso a la Plataforma de Mantenimiento.'
            );
        }

        $permissions = $this->loadPermissions(
            $userId,
            (int) $access['application_id'],
            (int) $access['role_id']
        );

        return [
            'id' => $userId,
            'first_name' => (string) $identity['first_name'],
            'last_name' => (string) $identity['last_name'],
            'full_name' => (string) $identity['full_name'],
            'email' => (string) $identity['email'],
            'must_change_password' =>
                (bool) $identity['must_change_password'],
            'last_login_at' => $identity['last_login_at'],
            'roles' => [
                (string) $access['role_code'],
            ],
            'permissions' => $permissions,
        ];
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