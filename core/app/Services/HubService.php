<?php

declare(strict_types=1);

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
            $canAccess = (bool) $row['can_access'];

            $canLaunch =
                $canAccess
                && (bool) $row['is_active']
                && (string) $row['hub_status'] === 'available';

            $role = null;

            if ($row['role_code'] !== null) {
                $role = [
                    'code' => (string) $row['role_code'],
                    'name' => (string) $row['role_name'],
                ];
            }

            $applications[] = [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'description' => $row['description'] !== null
                    ? (string) $row['description']
                    : null,
                'base_path' => (string) $row['base_path'],
                'status' => (string) $row['hub_status'],
                'category' => $row['hub_category'] !== null
                    ? (string) $row['hub_category']
                    : null,
                'icon' => $row['icon_key'] !== null
                    ? (string) $row['icon_key']
                    : null,
                'is_active' => (bool) $row['is_active'],
                'can_access' => $canAccess,
                'can_launch' => $canLaunch,
                'role' => $role,
            ];
        }

        return $applications;
    }
}