<?php

declare (strict_types = 1);

namespace App\Services;

use PDO;

final class HubService
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function listApplications(int $userId): array
    {
        $maintenanceAccess = $this->loadMaintenanceAccess($userId);

        $sql = <<<'SQL'
SELECT
    a.id,
    a.code,
    a.name,
    a.description,
    a.base_path,
    a.hub_status,
    a.hub_category,
    a.icon_key,
    a.sort_order,
    a.is_active,

    r.code AS role_code,
    r.name AS role_name,

    CASE
        WHEN
            uar.user_id IS NOT NULL
            AND r.id IS NOT NULL
            AND EXISTS (
                SELECT 1
                FROM role_permissions rp_access
                INNER JOIN permissions p_access
                    ON p_access.id = rp_access.permission_id
                   AND p_access.application_id = rp_access.application_id
                WHERE rp_access.application_id = a.id
                  AND rp_access.role_id = uar.role_id
                  AND p_access.code = 'access'
            )
        THEN 1
        ELSE 0
    END AS can_access

FROM applications a

LEFT JOIN user_application_roles uar
    ON uar.application_id = a.id
   AND uar.user_id = :user_id
   AND uar.revoked_at IS NULL
   AND a.code <> 'maintenance'

LEFT JOIN application_roles r
    ON r.id = uar.role_id
   AND r.application_id = a.id
   AND r.is_active = 1

WHERE a.show_in_hub = 1

ORDER BY a.sort_order, a.code
SQL;

        $statement = $this->pdo->prepare($sql);

        $statement->execute([
            'user_id' => $userId,
        ]);

        $applications = [];

        foreach ($statement->fetchAll() as $row) {
            $applicationCode = (string) $row['code'];

            if ($applicationCode === 'maintenance') {
                $canAccess = (bool) $maintenanceAccess['can_access'];
                $role      = $maintenanceAccess['role'];
            } else {
                $canAccess = (bool) $row['can_access'];
                $role      = null;

                if ($row['role_code'] !== null) {
                    $role = [
                        'code' => (string) $row['role_code'],
                        'name' => (string) $row['role_name'],
                    ];
                }
            }

            $canLaunch =
            $canAccess
            && (bool) $row['is_active']
            && (string) $row['hub_status'] === 'available';

            $applications[] = [
                'code'        => $applicationCode,
                'name'        => (string) $row['name'],
                'description' => $row['description'] !== null
                    ? (string) $row['description']
                    : null,
                'base_path'   => (string) $row['base_path'],
                'status'      => (string) $row['hub_status'],
                'category'    => $row['hub_category'] !== null
                    ? (string) $row['hub_category']
                    : null,
                'icon'        => $row['icon_key'] !== null
                    ? (string) $row['icon_key']
                    : null,
                'is_active'   => (bool) $row['is_active'],
                'can_access'  => $canAccess,
                'can_launch'  => $canLaunch,
                'role'        => $role,
            ];
        }

        return $applications;
    }

    /**
     * @return array{
     *     can_access: bool,
     *     role: array{code:string,name:string}|null
     * }
     */
    private function loadMaintenanceAccess(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                r.code AS role_code,
                r.name AS role_name
             FROM maintenance_access_grants mag
             INNER JOIN applications a
                ON a.id = mag.application_id
               AND a.code = 'maintenance'
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
             ORDER BY r.code"
        );

        $statement->execute([
            'user_id' => $userId,
        ]);

        $rows = $statement->fetchAll();

        if ($rows === []) {
            return [
                'can_access' => false,
                'role'       => null,
            ];
        }

        $roles = [];

        foreach ($rows as $row) {
            $roleCode = (string) $row['role_code'];

            $roles[$roleCode] = [
                'code' => $roleCode,
                'name' => (string) $row['role_name'],
            ];
        }

        $role = count($roles) === 1
            ? reset($roles)
            : null;

        return [
            'can_access' => true,
            'role'       => $role,
        ];
    }
}
