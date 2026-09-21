<?php

declare (strict_types = 1);

namespace App\Services;

use App\Exceptions\HttpException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class UserAdminService
{
    private ?int $coreApplicationId = null;

    private const STATUSES = [
        'active',
        'inactive',
        'blocked',
        'pending',
        'invited',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditService $audit,
        private readonly UserInvitationService $invitations
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

            if (! isset($applications[$code])) {
                $applications[$code] = [
                    'code'      => $code,
                    'name'      => (string) $row['application_name'],
                    'is_active' => (bool) $row['application_active'],
                    'roles'     => [],
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
            'statuses'          => self::STATUSES,
            'applications'      => array_values($applications),
            'can_manage_access' => AuthorizationService::hasPermission(
                $actor,
                'core',
                'access.manage'
            ),
        ];
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createInvitedUser(
        array $actor,
        array $payload
    ): array {
        AuthorizationService::requirePermission(
            $actor,
            'core',
            'user.manage'
        );

        $actorId = $this->actorId($actor);

        $firstName = trim(
            (string) ($payload['first_name'] ?? '')
        );

        $lastName = trim(
            (string) ($payload['last_name'] ?? '')
        );

        $email = strtolower(
            trim((string) ($payload['email'] ?? ''))
        );

        if (
            $firstName === ''
            || strlen($firstName) > 80
        ) {
            throw new HttpException(
                422,
                'invalid_first_name',
                'El nombre no es válido.'
            );
        }

        if (
            $lastName === ''
            || strlen($lastName) > 120
        ) {
            throw new HttpException(
                422,
                'invalid_last_name',
                'Los apellidos no son válidos.'
            );
        }

        if (
            strlen($email) > 190
            || ! filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new HttpException(
                422,
                'invalid_email',
                'El correo electrónico no es válido.'
            );
        }

        $applications = $this->normalizeApplications(
            $payload['applications'] ?? []
        );

        if ($applications !== []) {
            AuthorizationService::requirePermission(
                $actor,
                'core',
                'access.manage'
            );
        }

        $this->pdo->beginTransaction();

        try {
            $userId = $this->insertInvitedUser(
                $firstName,
                $lastName,
                $email
            );

            $assignedApplications =
            $this->assignApplicationsWithinTransaction(
                $userId,
                $actorId,
                $applications
            );

            $this->audit->record(
                'user.created',
                'user',
                $userId,
                $actorId,
                $this->coreApplicationId(),
                null,
                [
                    'email'  => $email,
                    'status' => 'invited',
                ]
            );

            $invitation =
            $this->invitations->issueWithinTransaction(
                $userId,
                $actorId
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $delivered = $this->invitations->deliver(
            $invitation
        );

        return [
            'id'                   => $userId,
            'first_name'           => $firstName,
            'last_name'            => $lastName,
            'full_name'            => trim(
                $firstName . ' ' . $lastName
            ),
            'email'                => $email,
            'status'               => 'invited',
            'applications'         => $assignedApplications,
            'invitation_delivered' => $delivered,
        ];
    }

/**
 * @param array<string, mixed> $actor
 * @return array<string, mixed>
 */
    public function resendInvitation(
        array $actor,
        int $userId
    ): array {
        AuthorizationService::requirePermission(
            $actor,
            'core',
            'user.manage'
        );

        $actorId = $this->actorId($actor);

        if ($userId < 1) {
            throw new HttpException(
                422,
                'invalid_user',
                'El usuario no es válido.'
            );
        }

        $this->pdo->beginTransaction();

        try {
            $invitation =
            $this->invitations->issueWithinTransaction(
                $userId,
                $actorId,
                'user.invitation.resend'
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $delivered = $this->invitations->deliver(
            $invitation
        );

        return [
            'user_id'              => $userId,
            'invitation_delivered' => $delivered,
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

        $search      = trim((string) ($filters['search'] ?? ''));
        $status      = strtolower(trim((string) ($filters['status'] ?? '')));
        $application = strtolower(
            trim((string) ($filters['application'] ?? ''))
        );

        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(
            100,
            max(10, (int) ($filters['per_page'] ?? 25))
        );

        if (
            $status !== ''
            && ! in_array($status, self::STATUSES, true)
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

        $where  = ['1 = 1'];
        $params = [];

        if ($search !== '') {
            $where[] = "(
        u.first_name LIKE :search_first_name
        OR u.last_name LIKE :search_last_name
        OR u.email LIKE :search_email
        OR CONCAT(u.first_name, ' ', u.last_name) LIKE :search_full_name
    )";

            $searchValue = '%' . $search . '%';

            $params['search_first_name'] = $searchValue;
            $params['search_last_name']  = $searchValue;
            $params['search_email']      = $searchValue;
            $params['search_full_name']  = $searchValue;
        }

        if ($status !== '') {
            $where[]          = 'u.status = :status';
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

        $total  = (int) $count->fetchColumn();
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
                'id'                => (int) $row['id'],
                'first_name'        => (string) $row['first_name'],
                'last_name'         => (string) $row['last_name'],
                'full_name'         => trim(
                    (string) $row['first_name']
                    . ' '
                    . (string) $row['last_name']
                ),
                'email'             => (string) $row['email'],
                'status'            => (string) $row['status'],
                'email_verified_at' => $row['email_verified_at'],
                'last_login_at'     => $row['last_login_at'],
                'created_at'        => $row['created_at'],
                'deactivated_at'    => $row['deactivated_at'],
                'applications'      => [],
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

                if (! isset($items[$userId])) {
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
            'items'      => array_values($items),
            'pagination' => [
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $total,
                'pages'    => max(
                    1,
                    (int) ceil($total / $perPage)
                ),
            ],
        ];
    }

    private function insertInvitedUser(
        string $firstName,
        string $lastName,
        string $email
    ): int {
        $statement = $this->pdo->prepare(
            'SELECT id
         FROM users
         WHERE email = :email
         LIMIT 1'
        );

        $statement->execute([
            'email' => $email,
        ]);

        if ($statement->fetchColumn() !== false) {
            throw new HttpException(
                409,
                'email_already_registered',
                'Ya existe una cuenta asociada con ese correo.'
            );
        }

        try {
            $insert = $this->pdo->prepare(
                "INSERT INTO users (
                first_name,
                last_name,
                email,
                password_hash,
                status,
                must_change_password,
                email_verified_at
             ) VALUES (
                :first_name,
                :last_name,
                :email,
                NULL,
                'invited',
                1,
                NULL
             )"
            );

            $insert->execute([
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'email'      => $email,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new HttpException(
                    409,
                    'email_already_registered',
                    'Ya existe una cuenta asociada con ese correo.'
                );
            }

            throw $exception;
        }

        return (int) $this->pdo->lastInsertId();
    }

/**
 * @param mixed $value
 * @return array<int, array{
 *   application_code:string,
 *   role_code:string
 * }>
 */
    private function normalizeApplications(
        mixed $value
    ): array {
        if ($value === null || $value === []) {
            return [];
        }

        if (! is_array($value)) {
            throw new HttpException(
                422,
                'invalid_applications',
                'La asignación de aplicaciones no es válida.'
            );
        }

        $normalized = [];
        $seen       = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                throw new HttpException(
                    422,
                    'invalid_applications',
                    'La asignación de aplicaciones no es válida.'
                );
            }

            $applicationCode = strtolower(
                trim(
                    (string) (
                        $item['application_code'] ?? ''
                    )
                )
            );

            $roleCode = strtolower(
                trim(
                    (string) (
                        $item['role_code'] ?? ''
                    )
                )
            );

            if (
                preg_match(
                    '/^[a-z0-9_]+$/',
                    $applicationCode
                ) !== 1
                || preg_match(
                    '/^[a-z0-9_]+$/',
                    $roleCode
                ) !== 1
            ) {
                throw new HttpException(
                    422,
                    'invalid_application_role',
                    'La aplicación o el rol seleccionado no es válido.'
                );
            }

            if (isset($seen[$applicationCode])) {
                throw new HttpException(
                    422,
                    'duplicate_application',
                    'No puedes asignar dos roles de la misma aplicación.'
                );
            }

            $seen[$applicationCode] = true;

            $normalized[] = [
                'application_code' => $applicationCode,
                'role_code'        => $roleCode,
            ];
        }

        return $normalized;
    }

/**
 * @param array<int, array{
 *   application_code:string,
 *   role_code:string
 * }> $applications
 *
 * @return array<int, array<string, mixed>>
 */
    private function assignApplicationsWithinTransaction(
        int $userId,
        int $actorId,
        array $applications
    ): array {
        if ($applications === []) {
            return [];
        }

        $resolve = $this->pdo->prepare(
            'SELECT
            a.id AS application_id,
            a.code AS application_code,
            a.name AS application_name,
            r.id AS role_id,
            r.code AS role_code,
            r.name AS role_name
         FROM applications a
         INNER JOIN application_roles r
            ON r.application_id = a.id
           AND r.code = :role_code
           AND r.is_active = 1
         WHERE a.code = :application_code
           AND a.is_active = 1
         LIMIT 1'
        );

        $insert = $this->pdo->prepare(
            'INSERT INTO user_application_roles (
            user_id,
            application_id,
            role_id,
            assigned_by_user_id
         ) VALUES (
            :user_id,
            :application_id,
            :role_id,
            :assigned_by_user_id
         )'
        );

        $result = [];

        foreach ($applications as $application) {
            $resolve->execute([
                'application_code' =>
                $application['application_code'],
                'role_code'        =>
                $application['role_code'],
            ]);

            $row = $resolve->fetch();

            if (! is_array($row)) {
                throw new HttpException(
                    422,
                    'invalid_application_role',
                    'La aplicación o el rol seleccionado no existe o está inactivo.'
                );
            }

            $applicationId =
            (int) $row['application_id'];

            $insert->execute([
                'user_id'             => $userId,
                'application_id'      => $applicationId,
                'role_id'             => (int) $row['role_id'],
                'assigned_by_user_id' => $actorId,
            ]);

            $this->audit->record(
                'user.access.assigned',
                'user',
                $userId,
                $actorId,
                $applicationId,
                null,
                [
                    'application_code' =>
                    (string) $row['application_code'],
                    'role_code'        =>
                    (string) $row['role_code'],
                ]
            );

            $result[] = [
                'code' =>
                (string) $row['application_code'],
                'name' =>
                (string) $row['application_name'],
                'role' => [
                    'code' =>
                    (string) $row['role_code'],
                    'name' =>
                    (string) $row['role_name'],
                ],
            ];
        }

        return $result;
    }

/** @param array<string, mixed> $actor */
    private function actorId(array $actor): int
    {
        $actorId = (int) ($actor['id'] ?? 0);

        if ($actorId < 1) {
            throw new HttpException(
                401,
                'authentication_required',
                'Debes iniciar sesión.'
            );
        }

        return $actorId;
    }

    private function coreApplicationId(): int
    {
        if ($this->coreApplicationId !== null) {
            return $this->coreApplicationId;
        }

        $statement = $this->pdo->query(
            "SELECT id
         FROM applications
         WHERE code = 'core'
           AND is_active = 1
         LIMIT 1"
        );

        $id = $statement->fetchColumn();

        if ($id === false) {
            throw new RuntimeException(
                'No existe la aplicación core activa.'
            );
        }

        $this->coreApplicationId = (int) $id;

        return $this->coreApplicationId;
    }
}
