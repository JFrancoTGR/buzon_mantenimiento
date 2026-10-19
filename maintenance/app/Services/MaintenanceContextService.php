<?php

declare (strict_types = 1);

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

        $grants = $this->loadMaintenanceGrants($userId);

        if ($grants === []) {
            throw new HttpException(
                403,
                'application_access_denied',
                'No tienes acceso a la Plataforma de Mantenimiento.'
            );
        }

        $hasGlobal      = false;
        $hasTerritorial = false;
        $roles          = [];

        foreach ($grants as $grant) {
            $roleCode = (string) ($grant['role']['code'] ?? '');

            if ($roleCode !== '') {
                $roles[$roleCode] = true;
            }

            if ((bool) ($grant['is_global'] ?? false)) {
                $hasGlobal = true;
            } else {
                $hasTerritorial = true;
            }
        }

        if ($hasGlobal && $hasTerritorial) {
            throw new HttpException(
                409,
                'maintenance_access_inconsistent',
                'La configuración de acceso a Mantenimiento es inconsistente.'
            );
        }

        /*
         * Compatibility rule:
         *
         * - A GLOBAL grant can safely expose its complete permission set
         *   at user level.
         * - Territorial grants expose only "access" globally.
         *   Their operational permissions remain attached to each grant
         *   and must be evaluated with a Location context.
         */
        $permissions = ['access'];

        if ($hasGlobal) {
            $permissions = is_array($grants[0]['permissions'] ?? null)
                ? $grants[0]['permissions']
                : ['access'];
        }

        return [
            'id'                   => $userId,
            'first_name'           => (string) $identity['first_name'],
            'last_name'            => (string) $identity['last_name'],
            'full_name'            => (string) $identity['full_name'],
            'email'                => (string) $identity['email'],
            'must_change_password' =>
            (bool) $identity['must_change_password'],
            'last_login_at'        => $identity['last_login_at'],
            'access_model'         => 'location_grants',
            'roles'                => array_keys($roles),
            'permissions'          => array_values(
                array_unique(
                    array_map('strval', $permissions)
                )
            ),
            'grants'               => $grants,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function loadMaintenanceGrants(
        int $userId
    ): array {
        $statement = $this->pdo->prepare(
            "SELECT
                mag.id AS grant_id,
                mag.application_id,
                mag.role_id,
                r.code AS role_code,
                r.name AS role_name,
                mag.location_id,
                l.code AS location_code,
                l.name AS location_name,
                mag.source_type,
                mag.source_id,
                mag.granted_at
             FROM maintenance_access_grants mag
             INNER JOIN applications a
                ON a.id = mag.application_id
               AND a.code = :application_code
               AND a.is_active = 1
             INNER JOIN application_roles r
                ON r.id = mag.role_id
               AND r.application_id = mag.application_id
               AND r.is_active = 1
             LEFT JOIN locations l
                ON l.id = mag.location_id
             WHERE mag.user_id = :user_id
               AND mag.revoked_at IS NULL
               AND (
                   mag.location_id IS NULL
                   OR l.is_active = 1
               )
               AND EXISTS (
                   SELECT 1
                   FROM role_permissions rp_access
                   INNER JOIN permissions p_access
                      ON p_access.id = rp_access.permission_id
                     AND p_access.application_id =
                         rp_access.application_id
                   WHERE rp_access.application_id =
                         mag.application_id
                     AND rp_access.role_id = mag.role_id
                     AND p_access.code = 'access'
               )
             ORDER BY
                CASE
                    WHEN mag.location_id IS NULL THEN 0
                    ELSE 1
                END,
                l.sort_order,
                l.name,
                mag.id"
        );

        $statement->execute([
            'application_code' => self::APPLICATION_CODE,
            'user_id'          => $userId,
        ]);

        $rows = $statement->fetchAll();

        if ($rows === []) {
            return [];
        }

        $grants       = [];
        $grantIndexes = [];

        foreach ($rows as $row) {
            $grantId = (int) $row['grant_id'];

            $location = null;

            if ($row['location_id'] !== null) {
                $location = [
                    'id'   => (int) $row['location_id'],
                    'code' => (string) $row['location_code'],
                    'name' => (string) $row['location_name'],
                ];
            }

            $grants[] = [
                'id'             => $grantId,
                'application_id' =>
                (int) $row['application_id'],
                'role'           => [
                    'id'   => (int) $row['role_id'],
                    'code' => (string) $row['role_code'],
                    'name' => (string) $row['role_name'],
                ],
                'location'       => $location,
                'is_global'      => $location === null,
                'permissions'    => [],
                'source_type'    => (string) $row['source_type'],
                'source_id'      => $row['source_id'] !== null
                    ? (int) $row['source_id']
                    : null,
                'granted_at'     => (string) $row['granted_at'],
            ];

            $grantIndexes[$grantId] =
                array_key_last($grants);
        }

        $permissions = $this->pdo->prepare(
            "SELECT
                mag.id AS grant_id,
                p.code AS permission_code
             FROM maintenance_access_grants mag
             INNER JOIN applications a
                ON a.id = mag.application_id
               AND a.code = :application_code
               AND a.is_active = 1
             INNER JOIN application_roles r
                ON r.id = mag.role_id
               AND r.application_id = mag.application_id
               AND r.is_active = 1
             INNER JOIN role_permissions rp
                ON rp.application_id = mag.application_id
               AND rp.role_id = mag.role_id
             INNER JOIN permissions p
                ON p.id = rp.permission_id
               AND p.application_id = mag.application_id
             LEFT JOIN locations l
                ON l.id = mag.location_id
             WHERE mag.user_id = :user_id
               AND mag.revoked_at IS NULL
               AND (
                   mag.location_id IS NULL
                   OR l.is_active = 1
               )
             ORDER BY mag.id, p.code"
        );

        $permissions->execute([
            'application_code' => self::APPLICATION_CODE,
            'user_id'          => $userId,
        ]);

        foreach ($permissions->fetchAll() as $row) {
            $grantId = (int) $row['grant_id'];

            if (! isset($grantIndexes[$grantId])) {
                continue;
            }

            $index = $grantIndexes[$grantId];

            $grants[$index]['permissions'][] =
            (string) $row['permission_code'];
        }

        return $grants;
    }
}
