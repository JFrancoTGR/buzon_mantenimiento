<?php

declare (strict_types = 1);

namespace App\Services;

use App\Config\Env;
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
                    'code'         => $code,
                    'name'         => (string) $row['application_name'],
                    'is_active'    => (bool) $row['application_active'],
                    'access_model' => $code === 'maintenance'
                        ? 'location_grants'
                        : 'single_role',
                    'roles'        => [],
                    'locations'    => [],
                ];
            }

            if ($row['role_code'] !== null) {
                $applications[$code]['roles'][] = [
                    'code' => (string) $row['role_code'],
                    'name' => (string) $row['role_name'],
                ];
            }
        }

        if (isset($applications['maintenance'])) {
            $locationRows = $this->pdo->query(
                "SELECT
                id,
                code,
                name
             FROM locations
             WHERE is_active = 1
             ORDER BY sort_order, name, id"
            )->fetchAll();

            foreach ($locationRows as $location) {
                $applications['maintenance']['locations'][] = [
                    'id'   => (int) $location['id'],
                    'code' => (string) $location['code'],
                    'name' => (string) $location['name'],
                ];
            }
        }

        return [
            'statuses'                           => self::STATUSES,
            'applications'                       => array_values($applications),
            'can_manage_access'                  => AuthorizationService::hasPermission(
                $actor,
                'core',
                'access.manage'
            ),
            'invitation_ttl_hours'               => max(
                1,
                Env::int('USER_INVITATION_TTL_HOURS', 72)
            ),
            'invitation_resend_cooldown_minutes' => max(
                1,
                Env::int(
                    'USER_INVITATION_RESEND_COOLDOWN_MINUTES',
                    10
                )
            ),
            'invitation_resend_max_per_hour'     => max(
                1,
                Env::int(
                    'USER_INVITATION_RESEND_MAX_PER_HOUR',
                    5
                )
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
            $this->assertInvitationResendLimit(
                $userId
            );

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
            if ($application === 'maintenance') {
                $where[] = "EXISTS (
            SELECT 1
            FROM maintenance_access_grants mag_filter
            INNER JOIN applications a_filter
                ON a_filter.id = mag_filter.application_id
            WHERE mag_filter.user_id = u.id
              AND mag_filter.revoked_at IS NULL
              AND a_filter.code = :application
        )";
            } else {
                $where[] = "EXISTS (
            SELECT 1
            FROM user_application_roles uar_filter
            INNER JOIN applications a_filter
                ON a_filter.id = uar_filter.application_id
            WHERE uar_filter.user_id = u.id
              AND uar_filter.revoked_at IS NULL
              AND a_filter.code = :application
        )";
            }

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
                u.deactivated_at,
                uit.id AS invitation_id,
                uit.created_at AS invitation_created_at,
                uit.expires_at AS invitation_expires_at,
                CASE
                    WHEN uit.id IS NULL THEN NULL
                    WHEN uit.used_at IS NOT NULL THEN 'used'
                    WHEN uit.revoked_at IS NOT NULL THEN 'revoked'
                    WHEN uit.expires_at <= UTC_TIMESTAMP() THEN 'expired'
                    ELSE 'pending'
                END AS invitation_status
             FROM users u
             LEFT JOIN user_invitation_tokens uit
                ON uit.id = COALESCE(
                    (
                        SELECT MAX(uit_active.id)
                        FROM user_invitation_tokens uit_active
                        WHERE uit_active.user_id = u.id
                          AND uit_active.used_at IS NULL
                          AND uit_active.revoked_at IS NULL
                          AND uit_active.expires_at > UTC_TIMESTAMP()
                    ),
                    (
                        SELECT MAX(uit_latest.id)
                        FROM user_invitation_tokens uit_latest
                        WHERE uit_latest.user_id = u.id
                    )
                )
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
                'invitation'        =>
                $row['invitation_id'] !== null
                    ? [
                    'status'     =>
                    (string) $row['invitation_status'],
                    'created_at' =>
                    $row['invitation_created_at'],
                    'expires_at' =>
                    $row['invitation_expires_at'],
                ]
                    : null,
                'applications'      => [],
            ];
        }

        if ($items !== []) {
            $ids = array_keys($items);

            $placeholders = implode(
                ',',
                array_fill(0, count($ids), '?')
            );

            /*
     * Generic application access.
     *
     * Maintenance is intentionally excluded because its authoritative
     * access source is maintenance_access_grants.
     */
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
           AND a.code <> 'maintenance'
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
                    'code'         => (string) $assignment['application_code'],
                    'name'         => (string) $assignment['application_name'],
                    'access_model' => 'single_role',
                    'role'         => [
                        'code' => (string) $assignment['role_code'],
                        'name' => (string) $assignment['role_name'],
                    ],
                    'grants'       => [],
                ];
            }

            /*
     * Maintenance access grants.
     */
            $maintenance = $this->pdo->prepare(
                "SELECT
            mag.id AS grant_id,
            mag.user_id,
            a.code AS application_code,
            a.name AS application_name,
            r.code AS role_code,
            r.name AS role_name,
            mag.location_id,
            l.code AS location_code,
            l.name AS location_name,
            l.is_active AS location_active,
            mag.source_type,
            mag.source_id,
            mag.granted_at
         FROM maintenance_access_grants mag
         INNER JOIN applications a
            ON a.id = mag.application_id
         INNER JOIN application_roles r
            ON r.id = mag.role_id
           AND r.application_id = mag.application_id
         LEFT JOIN locations l
            ON l.id = mag.location_id
         WHERE mag.revoked_at IS NULL
           AND a.code = 'maintenance'
           AND mag.user_id IN ({$placeholders})
         ORDER BY
            mag.user_id,
            CASE
                WHEN mag.location_id IS NULL THEN 0
                ELSE 1
            END,
            l.sort_order,
            l.name,
            mag.id"
            );

            $maintenance->execute($ids);

            $maintenanceByUser = [];

            foreach ($maintenance->fetchAll() as $grant) {
                $userId = (int) $grant['user_id'];

                if (! isset($items[$userId])) {
                    continue;
                }

                if (! isset($maintenanceByUser[$userId])) {
                    $maintenanceByUser[$userId] = [
                        'code'         => (string) $grant['application_code'],
                        'name'         => (string) $grant['application_name'],
                        'access_model' => 'location_grants',
                        'role'         => null,
                        'grants'       => [],
                        '_roles'       => [],
                    ];
                }

                $role = [
                    'code' => (string) $grant['role_code'],
                    'name' => (string) $grant['role_name'],
                ];

                $maintenanceByUser[$userId]['_roles'][
                    (string) $grant['role_code']
                ] = $role;

                $location = null;

                if ($grant['location_id'] !== null) {
                    $location = [
                        'id'        => (int) $grant['location_id'],
                        'code'      => (string) $grant['location_code'],
                        'name'      => (string) $grant['location_name'],
                        'is_active' => (bool) $grant['location_active'],
                    ];
                }

                $maintenanceByUser[$userId]['grants'][] = [
                    'id'          => (int) $grant['grant_id'],
                    'role'        => $role,
                    'location'    => $location,
                    'is_global'   => $grant['location_id'] === null,
                    'source_type' => (string) $grant['source_type'],
                    'source_id'   => $grant['source_id'] !== null
                        ? (int) $grant['source_id']
                        : null,
                    'granted_at'  => $grant['granted_at'],
                ];
            }

            foreach ($maintenanceByUser as $userId => $application) {
                /*
         * Backward-compatible summary for the current users.js.
         *
         * If all grants have the same role, expose that role at the
         * application level as well. Once mixed roles exist, role=null
         * and the new UI will render each grant individually.
         */
                if (count($application['_roles']) === 1) {
                    $application['role'] = reset(
                        $application['_roles']
                    );
                }

                unset($application['_roles']);

                $items[$userId]['applications'][] = $application;
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

    /**
     * @param array<string, mixed> $actor
     * @return array{user_id:int,tokens_revoked:int}
     */
    public function revokeInvitation(
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
            $target = $this->loadUserForUpdate($userId);

            if ($target === null) {
                throw new HttpException(
                    404,
                    'user_not_found',
                    'El usuario no existe.'
                );
            }

            if ((string) $target['status'] !== 'invited') {
                throw new HttpException(
                    409,
                    'user_not_invitable',
                    'La cuenta no tiene una invitación administrativa pendiente.'
                );
            }

            $revoked = $this->invitations->revokeWithinTransaction(
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

        return [
            'user_id'        => $userId,
            'tokens_revoked' => $revoked,
        ];
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $payload
     * @return array{user_id:int,status:string,changed:bool}
     */
    public function changeStatus(
        array $actor,
        int $userId,
        array $payload
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

        $newStatus = strtolower(
            trim((string) ($payload['status'] ?? ''))
        );

        if (! in_array(
            $newStatus,
            ['active', 'inactive'],
            true
        )) {
            throw new HttpException(
                422,
                'invalid_account_status',
                'Sólo se permite activar o desactivar una cuenta desde este módulo.'
            );
        }

        if (
            $userId === $actorId
            && $newStatus !== 'active'
        ) {
            $this->audit->safeRecord(
                'user.deactivate.denied',
                'user',
                $userId,
                $actorId,
                $this->coreApplicationId(),
                [
                    'reason'           => 'self_deactivation_denied',
                    'requested_status' => $newStatus,
                ]
            );

            throw new HttpException(
                409,
                'self_deactivation_denied',
                'No puedes desactivar tu propia cuenta.'
            );
        }

        $this->pdo->beginTransaction();

        try {
            $target = $this->loadUserForUpdate($userId);

            if ($target === null) {
                throw new HttpException(
                    404,
                    'user_not_found',
                    'El usuario no existe.'
                );
            }

            $oldStatus = (string) $target['status'];

            if ($oldStatus === $newStatus) {
                $this->pdo->commit();

                return [
                    'user_id' => $userId,
                    'status'  => $newStatus,
                    'changed' => false,
                ];
            }

            if (in_array(
                $oldStatus,
                ['pending', 'invited'],
                true
            )) {
                throw new HttpException(
                    409,
                    'account_activation_flow_required',
                    'Esta cuenta debe completar su flujo de invitación o verificación antes de cambiar de estado.'
                );
            }

            if (
                $newStatus === 'active'
                && (
                    ! is_string($target['password_hash'])
                    || (string) $target['password_hash'] === ''
                    || $target['email_verified_at'] === null
                )
            ) {
                throw new HttpException(
                    409,
                    'account_credentials_incomplete',
                    'La cuenta no puede activarse porque aún no tiene credenciales verificadas.'
                );
            }

            if (
                $newStatus === 'inactive'
                && $oldStatus === 'active'
                && $this->hasActiveCoreAdministratorAccess(
                    $userId
                )
            ) {
                $this->assertAnotherActiveCoreAdministrator(
                    $userId
                );
            }

            $update = $this->pdo->prepare(
                "UPDATE users
                 SET status = :status,
                     deactivated_at = CASE
                         WHEN :status_for_date = 'inactive'
                         THEN UTC_TIMESTAMP()
                         ELSE NULL
                     END,
                     failed_login_attempts = 0,
                     locked_until = NULL
                 WHERE id = :id"
            );

            $update->execute([
                'status'          => $newStatus,
                'status_for_date' => $newStatus,
                'id'              => $userId,
            ]);

            $this->revokeSessions($userId);

            $this->audit->record(
                $newStatus === 'active'
                    ? 'user.activate'
                    : 'user.deactivate',
                'user',
                $userId,
                $actorId,
                $this->coreApplicationId(),
                [
                    'status' => $oldStatus,
                ],
                [
                    'status' => $newStatus,
                ]
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if (
                $exception instanceof HttpException
                && $exception->errorCode ===
                'last_system_administrator_protected'
            ) {
                $this->audit->safeRecord(
                    'user.deactivate.denied',
                    'user',
                    $userId,
                    $actorId,
                    $this->coreApplicationId(),
                    [
                        'reason'           =>
                        'last_system_administrator_protected',
                        'requested_status' => $newStatus,
                    ]
                );
            }

            throw $exception;
        }

        return [
            'user_id' => $userId,
            'status'  => $newStatus,
            'changed' => true,
        ];
    }

    /**
     * Assigns, changes or revokes one application-scoped role.
     *
     * Contract:
     * - application_code is required.
     * - role_code is required as a key.
     * - role_code = null or "" revokes the application's access.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateApplicationAccess(
        array $actor,
        int $userId,
        array $payload
    ): array {
        AuthorizationService::requirePermission(
            $actor,
            'core',
            'user.manage'
        );

        AuthorizationService::requirePermission(
            $actor,
            'core',
            'access.manage'
        );

        $actorId = $this->actorId($actor);

        if ($userId < 1) {
            throw new HttpException(
                422,
                'invalid_user',
                'El usuario no es válido.'
            );
        }

        $applicationCode = strtolower(
            trim(
                (string) (
                    $payload['application_code'] ?? ''
                )
            )
        );

        if (
            preg_match(
                '/^[a-z0-9_]+$/',
                $applicationCode
            ) !== 1
        ) {
            throw new HttpException(
                422,
                'invalid_application',
                'La aplicación seleccionada no es válida.'
            );
        }

        if ($applicationCode === 'maintenance') {
            throw new HttpException(
                409,
                'maintenance_grants_required',
                'El acceso de Mantenimiento se administra mediante nivel y ubicación.'
            );
        }

        if (! array_key_exists('role_code', $payload)) {
            throw new HttpException(
                422,
                'missing_role_code',
                'Debes indicar el rol o solicitar explícitamente la revocación del acceso.'
            );
        }

        $roleCode = $payload['role_code'] === null
            ? null
            : strtolower(
            trim((string) $payload['role_code'])
        );

        if ($roleCode === '') {
            $roleCode = null;
        }

        if (
            $roleCode !== null
            && preg_match(
                '/^[a-z0-9_]+$/',
                $roleCode
            ) !== 1
        ) {
            throw new HttpException(
                422,
                'invalid_role',
                'El rol seleccionado no es válido.'
            );
        }

        $auditApplicationId   = null;
        $auditCurrentRoleCode = null;

        $this->pdo->beginTransaction();

        try {
            $target = $this->loadUserForUpdate($userId);

            if ($target === null) {
                throw new HttpException(
                    404,
                    'user_not_found',
                    'El usuario no existe.'
                );
            }

            $application = $this->loadApplication(
                $applicationCode
            );

            if ($application === null) {
                throw new HttpException(
                    404,
                    'application_not_found',
                    'La aplicación no existe.'
                );
            }

            $applicationId      = (int) $application['id'];
            $auditApplicationId = $applicationId;

            $current = $this->loadApplicationAccessForUpdate(
                $userId,
                $applicationId
            );

            $currentIsActive =
                $current !== null
                && $current['revoked_at'] === null;

            $currentRoleCode = $currentIsActive
                ? (string) $current['role_code']
                : null;

            $auditCurrentRoleCode = $currentRoleCode;

            if (
                $currentIsActive
                && $roleCode !== null
                && $currentRoleCode === $roleCode
            ) {
                $this->pdo->commit();

                return [
                    'user_id'     => $userId,
                    'application' => [
                        'code' => (string) $application['code'],
                        'name' => (string) $application['name'],
                    ],
                    'access'      => [
                        'role' => [
                            'code' => $currentRoleCode,
                            'name' => (string) $current['role_name'],
                        ],
                    ],
                    'changed'     => false,
                ];
            }

            if (
                $userId === $actorId
                && (string) $application['code'] === 'core'
            ) {
                throw new HttpException(
                    409,
                    'self_core_access_change_denied',
                    'No puedes modificar tu propio acceso administrativo al Core.'
                );
            }

            if ($roleCode === null) {
                if (! $currentIsActive) {
                    $this->pdo->commit();

                    return [
                        'user_id'     => $userId,
                        'application' => [
                            'code' => (string) $application['code'],
                            'name' => (string) $application['name'],
                        ],
                        'access'      => null,
                        'changed'     => false,
                    ];
                }

                if (
                    (string) $application['code'] === 'core'
                    && $currentRoleCode === 'system_administrator'
                    && (string) $target['status'] === 'active'
                ) {
                    $this->assertAnotherActiveCoreAdministrator(
                        $userId
                    );
                }

                $revoke = $this->pdo->prepare(
                    'UPDATE user_application_roles
                     SET revoked_by_user_id = :actor_id,
                         revoked_at = UTC_TIMESTAMP()
                     WHERE user_id = :user_id
                       AND application_id = :application_id
                       AND revoked_at IS NULL'
                );

                $revoke->execute([
                    'actor_id'       => $actorId,
                    'user_id'        => $userId,
                    'application_id' => $applicationId,
                ]);

                if ($revoke->rowCount() !== 1) {
                    throw new HttpException(
                        409,
                        'user_access_changed',
                        'El acceso del usuario cambió durante la operación. Recarga el módulo.'
                    );
                }

                $this->revokeSessions($userId);

                $this->audit->record(
                    'user.access.revoked',
                    'user',
                    $userId,
                    $actorId,
                    $applicationId,
                    [
                        'application_code' =>
                        (string) $application['code'],
                        'role_code'        => $currentRoleCode,
                    ],
                    [
                        'application_code' =>
                        (string) $application['code'],
                        'role_code'        => null,
                    ]
                );

                $this->pdo->commit();

                return [
                    'user_id'     => $userId,
                    'application' => [
                        'code' => (string) $application['code'],
                        'name' => (string) $application['name'],
                    ],
                    'access'      => null,
                    'changed'     => true,
                ];
            }

            if (! (bool) $application['is_active']) {
                throw new HttpException(
                    409,
                    'application_inactive',
                    'No puedes asignar acceso a una aplicación inactiva.'
                );
            }

            $role = $this->loadApplicationRole(
                $applicationId,
                $roleCode
            );

            if ($role === null) {
                throw new HttpException(
                    422,
                    'role_not_available',
                    'El rol seleccionado no existe, está inactivo o no pertenece a la aplicación.'
                );
            }

            if (
                (string) $application['code'] === 'core'
                && $currentIsActive
                && $currentRoleCode === 'system_administrator'
                && (string) $role['code'] !== 'system_administrator'
                && (string) $target['status'] === 'active'
            ) {
                $this->assertAnotherActiveCoreAdministrator(
                    $userId
                );
            }

            $upsert = $this->pdo->prepare(
                'INSERT INTO user_application_roles (
                    user_id,
                    application_id,
                    role_id,
                    assigned_by_user_id,
                    assigned_at,
                    revoked_by_user_id,
                    revoked_at
                 ) VALUES (
                    :user_id,
                    :application_id,
                    :role_id,
                    :assigned_by_user_id,
                    UTC_TIMESTAMP(),
                    NULL,
                    NULL
                 )
                 ON DUPLICATE KEY UPDATE
                    role_id = VALUES(role_id),
                    assigned_by_user_id =
                        VALUES(assigned_by_user_id),
                    assigned_at = UTC_TIMESTAMP(),
                    revoked_by_user_id = NULL,
                    revoked_at = NULL'
            );

            $upsert->execute([
                'user_id'             => $userId,
                'application_id'      => $applicationId,
                'role_id'             => (int) $role['id'],
                'assigned_by_user_id' => $actorId,
            ]);

            $this->revokeSessions($userId);

            $auditAction = $currentIsActive
                ? 'user.access.role_changed'
                : 'user.access.assigned';

            $this->audit->record(
                $auditAction,
                'user',
                $userId,
                $actorId,
                $applicationId,
                $currentIsActive
                    ? [
                    'application_code' =>
                    (string) $application['code'],
                    'role_code'        => $currentRoleCode,
                ]
                    : null,
                [
                    'application_code' =>
                    (string) $application['code'],
                    'role_code'        => (string) $role['code'],
                ]
            );

            $this->pdo->commit();

            return [
                'user_id'     => $userId,
                'application' => [
                    'code' => (string) $application['code'],
                    'name' => (string) $application['name'],
                ],
                'access'      => [
                    'role' => [
                        'code' => (string) $role['code'],
                        'name' => (string) $role['name'],
                    ],
                ],
                'changed'     => true,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if (
                $exception instanceof HttpException
                && in_array(
                    $exception->errorCode,
                    [
                        'self_core_access_change_denied',
                        'last_system_administrator_protected',
                    ],
                    true
                )
            ) {
                $this->audit->safeRecord(
                    'user.access.change_denied',
                    'user',
                    $userId,
                    $actorId,
                    $auditApplicationId,
                    [
                        'reason'              => $exception->errorCode,
                        'application_code'    => $applicationCode,
                        'current_role_code'   =>
                        $auditCurrentRoleCode,
                        'requested_role_code' => $roleCode,
                    ]
                );
            }

            throw $exception;
        }
    }private function insertInvitedUser(
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
 * @return array<int, array<string, mixed>>
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

            if (
                preg_match(
                    '/^[a-z0-9_]+$/',
                    $applicationCode
                ) !== 1
            ) {
                throw new HttpException(
                    422,
                    'invalid_application_role',
                    'La aplicación seleccionada no es válida.'
                );
            }

            if (isset($seen[$applicationCode])) {
                throw new HttpException(
                    422,
                    'duplicate_application',
                    'No puedes enviar dos configuraciones de la misma aplicación.'
                );
            }

            $seen[$applicationCode] = true;

            if ($applicationCode === 'maintenance') {
                $normalized[] = [
                    'application_code' => 'maintenance',
                    'role_code'        => null,
                    'grants'           => $this->normalizeMaintenanceGrants(
                        $item['grants'] ?? null,
                        false
                    ),
                ];

                continue;
            }

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
                    $roleCode
                ) !== 1
            ) {
                throw new HttpException(
                    422,
                    'invalid_application_role',
                    'La aplicación o el rol seleccionado no es válido.'
                );
            }

            $normalized[] = [
                'application_code' => $applicationCode,
                'role_code'        => $roleCode,
                'grants'           => [],
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     * @return array<int, array{
     *   role_code:string,
     *   location_id:?int
     * }>
     */
    private function normalizeMaintenanceGrants(
        mixed $value,
        bool $allowEmpty = true
    ): array {
        if (! is_array($value)) {
            throw new HttpException(
                422,
                'invalid_maintenance_grants',
                'La configuración de accesos de Mantenimiento no es válida.'
            );
        }

        if ($value === []) {
            if ($allowEmpty) {
                return [];
            }

            throw new HttpException(
                422,
                'maintenance_grants_required',
                'Debes indicar al menos un nivel y ubicación para Mantenimiento.'
            );
        }

        $normalized    = [];
        $seenLocations = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                throw new HttpException(
                    422,
                    'invalid_maintenance_grants',
                    'La configuración de accesos de Mantenimiento no es válida.'
                );
            }

            $roleCode = strtolower(
                trim((string) ($item['role_code'] ?? ''))
            );

            if (
                preg_match('/^[a-z0-9_]+$/', $roleCode)
                !== 1
            ) {
                throw new HttpException(
                    422,
                    'invalid_maintenance_role',
                    'El nivel seleccionado para Mantenimiento no es válido.'
                );
            }

            $rawLocationId = $item['location_id'] ?? null;

            if ($rawLocationId === null) {
                $locationId = null;
            } elseif (
                is_int($rawLocationId)
                || (
                    is_string($rawLocationId)
                    && ctype_digit($rawLocationId)
                )
            ) {
                $locationId = (int) $rawLocationId;

                if ($locationId < 1) {
                    throw new HttpException(
                        422,
                        'invalid_maintenance_location',
                        'La ubicación seleccionada no es válida.'
                    );
                }
            } else {
                throw new HttpException(
                    422,
                    'invalid_maintenance_location',
                    'La ubicación seleccionada no es válida.'
                );
            }

            $locationKey = $locationId === null
                ? 'global'
                : (string) $locationId;

            if (isset($seenLocations[$locationKey])) {
                throw new HttpException(
                    422,
                    'duplicate_maintenance_location',
                    'No puedes asignar dos niveles simultáneos a la misma ubicación.'
                );
            }

            $seenLocations[$locationKey] = true;

            $normalized[] = [
                'role_code'   => $roleCode,
                'location_id' => $locationId,
            ];
        }

        return $normalized;
    }

/**
 * @param array<int, array<string, mixed>> $applications
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
            if (
                $application['application_code']
                === 'maintenance'
            ) {
                $result[] =
                $this->assignMaintenanceGrantsWithinTransaction(
                    $userId,
                    $actorId,
                    $application['grants'] ?? []
                );

                continue;
            }

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
                'code'         =>
                (string) $row['application_code'],
                'name'         =>
                (string) $row['application_name'],
                'access_model' => 'single_role',
                'role'         => [
                    'code' =>
                    (string) $row['role_code'],
                    'name' =>
                    (string) $row['role_name'],
                ],
                'grants'       => [],
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array{
     *   role_code:string,
     *   location_id:?int
     * }> $grants
     *
     * @return array<string, mixed>
     */
    private function assignMaintenanceGrantsWithinTransaction(
        int $userId,
        int $actorId,
        array $grants
    ): array {
        $application = $this->loadApplication(
            'maintenance'
        );

        if (
            $application === null
            || ! (bool) $application['is_active']
        ) {
            throw new HttpException(
                409,
                'maintenance_application_unavailable',
                'La aplicación Mantenimiento no está disponible.'
            );
        }

        $applicationId = (int) $application['id'];

        $resolved = $this->resolveMaintenanceGrantDefinitions(
            $applicationId,
            $grants
        );

        $insert = $this->pdo->prepare(
            'INSERT INTO maintenance_access_grants (
            user_id,
            application_id,
            role_id,
            location_id,
            source_type,
            source_id,
            granted_by_user_id,
            granted_at
         ) VALUES (
            :user_id,
            :application_id,
            :role_id,
            :location_id,
            :source_type,
            NULL,
            :granted_by_user_id,
            UTC_TIMESTAMP()
         )'
        );

        $resultGrants = [];
        $summaryRoles = [];

        foreach ($resolved as $grant) {
            $insert->execute([
                'user_id'            => $userId,
                'application_id'     => $applicationId,
                'role_id'            => (int) $grant['role']['id'],
                'location_id'        =>
                $grant['location'] !== null
                    ? (int) $grant['location']['id']
                    : null,
                'source_type'        => 'invitation',
                'granted_by_user_id' => $actorId,
            ]);

            $grantId = (int) $this->pdo->lastInsertId();

            $role = [
                'code' => (string) $grant['role']['code'],
                'name' => (string) $grant['role']['name'],
            ];

            $location = null;

            if ($grant['location'] !== null) {
                $location = [
                    'id'        => (int) $grant['location']['id'],
                    'code'      => (string) $grant['location']['code'],
                    'name'      => (string) $grant['location']['name'],
                    'is_active' => true,
                ];
            }

            $this->audit->record(
                'grant.created',
                'maintenance_access_grant',
                $grantId,
                $actorId,
                $applicationId,
                null,
                [
                    'user_id'          => $userId,
                    'application_code' => 'maintenance',
                    'role_code'        => $role['code'],
                    'location_id'      =>
                    $location !== null
                        ? $location['id']
                        : null,
                    'source_type'      => 'invitation',
                ]
            );

            $summaryRoles[$role['code']] = $role;

            $resultGrants[] = [
                'id'          => $grantId,
                'role'        => $role,
                'location'    => $location,
                'is_global'   => $location === null,
                'source_type' => 'invitation',
                'source_id'   => null,
            ];
        }

        $summaryRole = null;

        if (count($summaryRoles) === 1) {
            $summaryRole = reset($summaryRoles);
        }

        return [
            'code'         => 'maintenance',
            'name'         => (string) $application['name'],
            'access_model' => 'location_grants',
            'role'         => $summaryRole,
            'grants'       => $resultGrants,
        ];
    }

    /**
     * @param array<int, array{
     *   role_code:string,
     *   location_id:?int
     * }> $grants
     *
     * @return array<int, array{
     *   role:array<string, mixed>,
     *   location:?array<string, mixed>
     * }>
     */
    private function resolveMaintenanceGrantDefinitions(
        int $applicationId,
        array $grants
    ): array {
        if ($grants === []) {
            throw new HttpException(
                422,
                'maintenance_grants_required',
                'Debes indicar al menos un acceso de Mantenimiento.'
            );
        }

        $resolved       = [];
        $hasGlobal      = false;
        $hasTerritorial = false;

        foreach ($grants as $grant) {
            $roleCode   = (string) $grant['role_code'];
            $locationId = $grant['location_id'];

            $role = $this->loadApplicationRole(
                $applicationId,
                $roleCode
            );

            if ($role === null) {
                throw new HttpException(
                    422,
                    'maintenance_role_not_available',
                    'El nivel seleccionado para Mantenimiento no existe o está inactivo.'
                );
            }

            if ($roleCode === 'administrator') {
                if ($locationId !== null) {
                    throw new HttpException(
                        422,
                        'maintenance_administrator_must_be_global',
                        'El nivel Administrador de Mantenimiento debe ser global.'
                    );
                }

                $hasGlobal = true;

                $resolved[] = [
                    'role'     => $role,
                    'location' => null,
                ];

                continue;
            }

            if ($locationId === null) {
                throw new HttpException(
                    422,
                    'maintenance_location_required',
                    'El nivel seleccionado requiere una ubicación.'
                );
            }

            $location = $this->loadActiveLocation(
                (int) $locationId
            );

            if ($location === null) {
                throw new HttpException(
                    422,
                    'maintenance_location_not_available',
                    'La ubicación seleccionada no existe o está inactiva.'
                );
            }

            $hasTerritorial = true;

            $resolved[] = [
                'role'     => $role,
                'location' => $location,
            ];
        }

        if ($hasGlobal && $hasTerritorial) {
            throw new HttpException(
                422,
                'maintenance_global_territorial_conflict',
                'Un acceso global no puede coexistir con accesos territoriales.'
            );
        }

        if ($hasGlobal && count($resolved) !== 1) {
            throw new HttpException(
                422,
                'maintenance_global_grant_must_be_unique',
                'El acceso global de Mantenimiento debe ser el único grant activo.'
            );
        }

        return $resolved;
    }

/** @param array<string, mixed> $actor */

    private function assertInvitationResendLimit(
        int $userId
    ): void {
        $latest = $this->pdo->prepare(
            'SELECT created_at
             FROM user_invitation_tokens
             WHERE user_id = :user_id
               AND used_at IS NULL
               AND revoked_at IS NULL
               AND expires_at > UTC_TIMESTAMP()
             ORDER BY created_at DESC
             LIMIT 1'
        );

        $latest->execute([
            'user_id' => $userId,
        ]);

        $latestAt = $latest->fetchColumn();

        if (
            is_string($latestAt)
            && $latestAt !== ''
        ) {
            $cooldownMinutes = max(
                1,
                Env::int(
                    'USER_INVITATION_RESEND_COOLDOWN_MINUTES',
                    10
                )
            );

            $age = $this->pdo->prepare(
                'SELECT TIMESTAMPDIFF(
                    SECOND,
                    :created_at,
                    UTC_TIMESTAMP()
                )'
            );

            $age->execute([
                'created_at' => $latestAt,
            ]);

            if (
                (int) $age->fetchColumn()
                < ($cooldownMinutes * 60)
            ) {
                throw new HttpException(
                    429,
                    'invitation_resend_cooldown',
                    "Espera {$cooldownMinutes} minutos antes de reenviar otra invitación."
                );
            }
        }

        $count = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM user_invitation_tokens
             WHERE user_id = :user_id
               AND created_at >= DATE_SUB(
                   UTC_TIMESTAMP(),
                   INTERVAL 1 HOUR
               )'
        );

        $count->execute([
            'user_id' => $userId,
        ]);

        $maxPerHour = max(
            1,
            Env::int(
                'USER_INVITATION_RESEND_MAX_PER_HOUR',
                5
            )
        );

        if (
            (int) $count->fetchColumn()
            >= $maxPerHour
        ) {
            throw new HttpException(
                429,
                'invitation_resend_hourly_limit',
                'Se alcanzó el límite temporal de reenvíos para esta cuenta.'
            );
        }
    }
    /** @return array<string, mixed>|null */
    private function loadUserForUpdate(
        int $userId
    ): ?array {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                status,
                password_hash,
                email_verified_at
             FROM users
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );

        $statement->execute([
            'id' => $userId,
        ]);

        $row = $statement->fetch();

        return is_array($row)
            ? $row
            : null;
    }

    /** @return array<string, mixed>|null */
    private function loadApplication(
        string $applicationCode
    ): ?array {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                code,
                name,
                is_active
             FROM applications
             WHERE code = :code
             LIMIT 1'
        );

        $statement->execute([
            'code' => $applicationCode,
        ]);

        $row = $statement->fetch();

        return is_array($row)
            ? $row
            : null;
    }

    /** @return array<string, mixed>|null */
    private function loadApplicationAccessForUpdate(
        int $userId,
        int $applicationId
    ): ?array {
        $statement = $this->pdo->prepare(
            'SELECT
                uar.role_id,
                uar.revoked_at,
                r.code AS role_code,
                r.name AS role_name
             FROM user_application_roles uar
             INNER JOIN application_roles r
                ON r.id = uar.role_id
               AND r.application_id = uar.application_id
             WHERE uar.user_id = :user_id
               AND uar.application_id = :application_id
             LIMIT 1
             FOR UPDATE'
        );

        $statement->execute([
            'user_id'        => $userId,
            'application_id' => $applicationId,
        ]);

        $row = $statement->fetch();

        return is_array($row)
            ? $row
            : null;
    }

    /** @return array<string, mixed>|null */
    private function loadApplicationRole(
        int $applicationId,
        string $roleCode
    ): ?array {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                code,
                name
             FROM application_roles
             WHERE application_id = :application_id
               AND code = :code
               AND is_active = 1
             LIMIT 1'
        );

        $statement->execute([
            'application_id' => $applicationId,
            'code'           => $roleCode,
        ]);

        $row = $statement->fetch();

        return is_array($row)
            ? $row
            : null;
    }

    /** @return array<string, mixed>|null */
    private function loadActiveLocation(
        int $locationId
    ): ?array {
        $statement = $this->pdo->prepare(
            'SELECT
            id,
            code,
            name
         FROM locations
         WHERE id = :id
           AND is_active = 1
         LIMIT 1'
        );

        $statement->execute([
            'id' => $locationId,
        ]);

        $row = $statement->fetch();

        return is_array($row)
            ? $row
            : null;
    }

    private function revokeSessions(
        int $userId
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE user_sessions
             SET revoked_at = COALESCE(
                 revoked_at,
                 UTC_TIMESTAMP()
             )
             WHERE user_id = :user_id
               AND revoked_at IS NULL'
        );

        $statement->execute([
            'user_id' => $userId,
        ]);
    }

    private function hasActiveCoreAdministratorAccess(
        int $userId
    ): bool {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM user_application_roles uar
             INNER JOIN applications a
                ON a.id = uar.application_id
               AND a.code = 'core'
             INNER JOIN application_roles r
                ON r.id = uar.role_id
               AND r.application_id = uar.application_id
               AND r.code = 'system_administrator'
             WHERE uar.user_id = :user_id
               AND uar.revoked_at IS NULL"
        );

        $statement->execute([
            'user_id' => $userId,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function assertAnotherActiveCoreAdministrator(
        int $excludedUserId
    ): void {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM users u
             INNER JOIN user_application_roles uar
                ON uar.user_id = u.id
               AND uar.revoked_at IS NULL
             INNER JOIN applications a
                ON a.id = uar.application_id
               AND a.code = 'core'
               AND a.is_active = 1
             INNER JOIN application_roles r
                ON r.id = uar.role_id
               AND r.application_id = uar.application_id
               AND r.code = 'system_administrator'
               AND r.is_active = 1
             WHERE u.status = 'active'
               AND u.id <> :excluded_user_id"
        );

        $statement->execute([
            'excluded_user_id' => $excludedUserId,
        ]);

        if ((int) $statement->fetchColumn() < 1) {
            throw new HttpException(
                409,
                'last_system_administrator_protected',
                'La operación dejaría a EU Tools sin otro administrador del sistema activo.'
            );
        }
    }private function actorId(array $actor): int
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
