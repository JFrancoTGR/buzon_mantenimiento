<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\HttpException;
use PDO;

final class UserAdminService
{
    private const STATUSES = [
        'active',
        'inactive',
        'blocked',
        'pending',
        'invited',
    ];

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /** @param array<string, mixed> $actor */
    public function context(array $actor): array
    {
        AuthorizationService::requirePermission(
            $actor,
            'core',
            'user.manage'
        );

        $rows = $this->pdo->query(
            "SELECT
                a.id AS application_id,
                a.code AS application_code,
                a.name AS application_name,
                a.is_active AS application_active,
                r.code AS role_code,
                r.name AS role_name
             FROM applications a
             LEFT JOIN application_roles r
                ON r.application_id = a.id
               AND r.is_active = 1
             ORDER BY a.sort_order, a.code, r.name"
        )->fetchAll();

        $applications = [];

        foreach ($rows as $row) {
            $code = (string) $row['application_code'];

            if (!isset($applications[$code])) {
                $applications[$code] = [
                    'code' => $code,
                    'name' => (string) $row['application_name'],
                    'is_active' => (bool) $row['application_active'],
                    'roles' => [],
                ];
            }

            if ($row['role_code'] !== null) {
                $applications[$code]['roles'][] = [
                    'code' => (string) $row['role_code'],
                    'name' => (string) $row['role_name'],
                ];
            }
        }

        return [
            'statuses' => self::STATUSES,
            'applications' => array_values($applications),
            'can_manage_access' => AuthorizationService::hasPermission(
                $actor,
                'core',
                'access.manage'
            ),
        ];
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $filters
     */
    public function listUsers(array $actor, array $filters): array
    {
        AuthorizationService::requirePermission(
            $actor,
            'core',
            'user.manage'
        );

        $search = trim((string) ($filters['search'] ?? ''));
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        $application = strtolower(
            trim((string) ($filters['application'] ?? ''))
        );

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(
            100,
            max(10, (int) ($filters['per_page'] ?? 25))
        );

        if (
            $status !== ''
            && !in_array($status, self::STATUSES, true)
        ) {
            throw new HttpException(
                422,
                'invalid_status_filter',
                'El filtro de estado no es válido.'
            );
        }

        if (strlen($search) > 190) {
            throw new HttpException(
                422,
                'invalid_search',
                'La búsqueda es demasiado larga.'
            );
        }

        if (
            $application !== ''
            && preg_match('/^[a-z0-9_]+$/', $application) !== 1
        ) {
            throw new HttpException(
                422,
                'invalid_application_filter',
                'El filtro de aplicación no es válido.'
            );
        }

        $where = ['1 = 1'];
        $params = [];

        if ($search !== '') {
            $where[] = "(
                u.first_name LIKE :search
                OR u.last_name LIKE :search
                OR u.email LIKE :search
                OR CONCAT(u.first_name, ' ', u.last_name) LIKE :search
            )";

            $params['search'] = '%' . $search . '%';
        }

        if ($status !== '') {
            $where[] = 'u.status = :status';
            $params['status'] = $status;
        }

        if ($application !== '') {
            $where[] = "EXISTS (
                SELECT 1
                FROM user_application_roles uar_filter
                INNER JOIN applications a_filter
                    ON a_filter.id = uar_filter.application_id
                WHERE uar_filter.user_id = u.id
                  AND uar_filter.revoked_at IS NULL
                  AND a_filter.code = :application
            )";

            $params['application'] = $application;
        }

        $whereSql = implode(' AND ', $where);

        $count = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM users u
             WHERE {$whereSql}"
        );

        $count->execute($params);

        $total = (int) $count->fetchColumn();
        $offset = ($page - 1) * $perPage;

        $statement = $this->pdo->prepare(
            "SELECT
                u.id,
                u.first_name,
                u.last_name,
                u.email,
                u.status,
                u.email_verified_at,
                u.last_login_at,
                u.created_at,
                u.deactivated_at
             FROM users u
             WHERE {$whereSql}
             ORDER BY u.created_at DESC, u.id DESC
             LIMIT {$perPage}
             OFFSET {$offset}"
        );

        $statement->execute($params);
        $rows = $statement->fetchAll();

        $items = [];

        foreach ($rows as $row) {
            $items[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'first_name' => (string) $row['first_name'],
                'last_name' => (string) $row['last_name'],
                'full_name' => trim(
                    (string) $row['first_name']
                    . ' '
                    . (string) $row['last_name']
                ),
                'email' => (string) $row['email'],
                'status' => (string) $row['status'],
                'email_verified_at' => $row['email_verified_at'],
                'last_login_at' => $row['last_login_at'],
                'created_at' => $row['created_at'],
                'deactivated_at' => $row['deactivated_at'],
                'applications' => [],
            ];
        }

        if ($items !== []) {
            $ids = array_keys($items);

            $placeholders = implode(
                ',',
                array_fill(0, count($ids), '?')
            );

            $assignments = $this->pdo->prepare(
                "SELECT
                    uar.user_id,
                    a.code AS application_code,
                    a.name AS application_name,
                    r.code AS role_code,
                    r.name AS role_name
                 FROM user_application_roles uar
                 INNER JOIN applications a
                    ON a.id = uar.application_id
                 INNER JOIN application_roles r
                    ON r.id = uar.role_id
                   AND r.application_id = uar.application_id
                 WHERE uar.revoked_at IS NULL
                   AND uar.user_id IN ({$placeholders})
                 ORDER BY
                    uar.user_id,
                    a.sort_order,
                    a.code"
            );

            $assignments->execute($ids);

            foreach ($assignments->fetchAll() as $assignment) {
                $userId = (int) $assignment['user_id'];

                if (!isset($items[$userId])) {
                    continue;
                }

                $items[$userId]['applications'][] = [
                    'code' => (string) $assignment['application_code'],
                    'name' => (string) $assignment['application_name'],
                    'role' => [
                        'code' => (string) $assignment['role_code'],
                        'name' => (string) $assignment['role_name'],
                    ],
                ];
            }
        }

        return [
            'items' => array_values($items),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(
                    1,
                    (int) ceil($total / $perPage)
                ),
            ],
        ];
    }
}