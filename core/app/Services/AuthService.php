<?php

declare (strict_types = 1);

namespace App\Services;

use App\Config\Env;
use App\Core\Http;
use App\Exceptions\HttpException;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use EUTools\Core\Security\PersistentSessionService;
use EUTools\Core\Security\SessionException;
use EUTools\Shared\Security\Csrf;
use EUTools\Shared\Security\SessionRuntime;
use PDO;
use Throwable;

final class AuthService
{
    private const DUMMY_PASSWORD_HASH =
        '$2y$12$9Vq6G8Y6F8cUxEYtVqQgNuKJMd6PqMGTmE3JO2iLZ2gjHVv2DnMDu';

    private ?int $coreApplicationId = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditService $audit,
        private readonly PersistentSessionService $persistentSessions
    ) {
    }

    /** @return array<string, mixed> */
    public function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            throw new HttpException(
                422,
                'invalid_credentials_format',
                'Correo o contraseña inválidos.'
            );
        }

        $maxAttempts = Env::int('LOGIN_MAX_ATTEMPTS', 5);
        $lockMinutes = Env::int('LOGIN_LOCK_MINUTES', 15);

        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $utc);

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'SELECT *
                 FROM users
                 WHERE email = :email
                 LIMIT 1
                 FOR UPDATE'
            );

            $statement->execute(['email' => $email]);
            $user = $statement->fetch();

            if (! is_array($user)) {
                password_verify($password, self::DUMMY_PASSWORD_HASH);

                $this->pdo->commit();

                $this->audit->safeRecord(
                    'auth.login.failed',
                    'user',
                    null,
                    null,
                    $this->getCoreApplicationId(),
                    [
                        'email'  => $email,
                        'reason' => 'not_found',
                    ]
                );

                throw new HttpException(
                    401,
                    'invalid_credentials',
                    'Correo o contraseña incorrectos.'
                );
            }

            $userId = (int) $user['id'];

            $lockedUntil = $user['locked_until'] !== null
                ? new DateTimeImmutable((string) $user['locked_until'], $utc)
                : null;

            if ($lockedUntil !== null && $lockedUntil > $now) {
                $this->pdo->commit();

                $this->audit->safeRecord(
                    'auth.login.blocked',
                    'user',
                    $userId,
                    null,
                    $this->getCoreApplicationId(),
                    ['locked_until' => $lockedUntil->format('Y-m-d H:i:s')]
                );

                throw new HttpException(
                    423,
                    'account_temporarily_locked',
                    'La cuenta está temporalmente bloqueada.'
                );
            }

            if ((string) $user['status'] !== 'active') {
                $status = (string) $user['status'];

                $this->pdo->commit();

                $this->audit->safeRecord(
                    'auth.login.denied',
                    'user',
                    $userId,
                    null,
                    $this->getCoreApplicationId(),
                    ['status' => $status]
                );

                throw new HttpException(
                    403,
                    'account_inactive',
                    'La cuenta no está activa.'
                );
            }

            if (! password_verify($password, (string) $user['password_hash'])) {
                $attempts       = (int) $user['failed_login_attempts'] + 1;
                $newLockedUntil = null;

                if ($attempts >= $maxAttempts) {
                    $newLockedUntil = $now
                        ->add(new DateInterval("PT{$lockMinutes}M"))
                        ->format('Y-m-d H:i:s');

                    $attempts = 0;
                }

                $update = $this->pdo->prepare(
                    'UPDATE users
                     SET failed_login_attempts = :attempts,
                         locked_until = :locked_until
                     WHERE id = :id'
                );

                $update->execute([
                    'attempts'     => $attempts,
                    'locked_until' => $newLockedUntil,
                    'id'           => $userId,
                ]);

                $this->pdo->commit();

                $this->audit->safeRecord(
                    'auth.login.failed',
                    'user',
                    $userId,
                    null,
                    $this->getCoreApplicationId(),
                    ['email' => $email]
                );

                throw new HttpException(
                    401,
                    'invalid_credentials',
                    'Correo o contraseña incorrectos.'
                );
            }

            if (password_needs_rehash(
                (string) $user['password_hash'],
                PASSWORD_DEFAULT
            )) {
                $rehash = $this->pdo->prepare(
                    'UPDATE users
                     SET password_hash = :hash
                     WHERE id = :id'
                );

                $rehash->execute([
                    'hash' => password_hash($password, PASSWORD_DEFAULT),
                    'id'   => $userId,
                ]);
            }

            $updateUser = $this->pdo->prepare(
                'UPDATE users
                 SET failed_login_attempts = 0,
                     locked_until = NULL,
                     last_login_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );

            $updateUser->execute(['id' => $userId]);

            $databaseSessionId = $this->insertDatabaseSession($userId, $now);

            $this->audit->record(
                'auth.login.success',
                'user',
                $userId,
                $userId,
                $this->getCoreApplicationId()
            );

            $this->pdo->commit();

            SessionRuntime::establish($userId, $databaseSessionId);
            Csrf::rotate();

            return $this->currentUser();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function currentUser(): array
    {
        try {
            $user = $this->persistentSessions
                ->requireCurrentIdentity();
        } catch (SessionException $exception) {
            throw new HttpException(
                $exception->status,
                $exception->errorCode,
                $exception->getMessage()
            );
        }

        $user['applications'] = $this->loadApplications(
            (int) $user['id']
        );

        return $user;
    }

    public function logout(bool $recordAudit = true): void
    {
        $auth = SessionRuntime::authData();

        if ($auth !== null) {
            $statement = $this->pdo->prepare(
                'UPDATE user_sessions
                 SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
                 WHERE id = :id
                   AND user_id = :user_id'
            );

            $statement->execute([
                'id'      => $auth['database_session_id'],
                'user_id' => $auth['user_id'],
            ]);

            if ($recordAudit) {
                $this->audit->safeRecord(
                    'auth.logout',
                    'user',
                    $auth['user_id'],
                    $auth['user_id'],
                    $this->getCoreApplicationId()
                );
            }
        }

        SessionRuntime::destroyLocal();
    }

    public static function validatePasswordStrength(
        string $password
    ): void {
        if (strlen($password) < 12) {
            throw new HttpException(
                422,
                'password_too_short',
                'La contraseña debe tener al menos 12 caracteres.'
            );
        }

        $classes = 0;

        $classes += preg_match('/[a-z]/', $password) === 1
            ? 1
            : 0;

        $classes += preg_match('/[A-Z]/', $password) === 1
            ? 1
            : 0;

        $classes += preg_match('/[0-9]/', $password) === 1
            ? 1
            : 0;

        $classes += preg_match('/[^a-zA-Z0-9]/', $password) === 1
            ? 1
            : 0;

        if ($classes < 3) {
            throw new HttpException(
                422,
                'password_too_weak',
                'La contraseña debe combinar al menos tres tipos: minúsculas, mayúsculas, números y símbolos.'
            );
        }
    }

    /**
     * Crea una sesión Core para un usuario que acaba de completar
     * una validación segura, por ejemplo una invitación.
     *
     * @return array<string, mixed>
     */
    public function establishSessionForUser(
        int $userId,
        string $auditAction = 'auth.session.created'
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                status
             FROM users
             WHERE id = :id
             LIMIT 1'
        );

        $statement->execute([
            'id' => $userId,
        ]);

        $user = $statement->fetch();

        if (
            ! is_array($user)
            || (string) $user['status'] !== 'active'
        ) {
            throw new HttpException(
                403,
                'account_inactive',
                'La cuenta no está activa.'
            );
        }

        $now = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        );

        $currentSession = SessionRuntime::authData();

        $this->pdo->beginTransaction();

        try {
            if ($currentSession !== null) {
                $revokeCurrent = $this->pdo->prepare(
                    'UPDATE user_sessions
                     SET revoked_at = COALESCE(
                         revoked_at,
                         UTC_TIMESTAMP()
                     )
                     WHERE id = :session_id
                       AND user_id = :user_id'
                );

                $revokeCurrent->execute([
                    'session_id' =>
                    $currentSession['database_session_id'],
                    'user_id'    =>
                    $currentSession['user_id'],
                ]);
            }

            $update = $this->pdo->prepare(
                'UPDATE users
                 SET last_login_at = UTC_TIMESTAMP(),
                     failed_login_attempts = 0,
                     locked_until = NULL
                 WHERE id = :id'
            );

            $update->execute([
                'id' => $userId,
            ]);

            $databaseSessionId = $this->insertDatabaseSession(
                $userId,
                $now
            );

            $this->audit->record(
                $auditAction,
                'user',
                $userId,
                $userId,
                $this->getCoreApplicationId()
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        SessionRuntime::establish(
            $userId,
            $databaseSessionId
        );

        Csrf::rotate();

        return $this->currentUser();
    }

    private function insertDatabaseSession(
        int $userId,
        DateTimeImmutable $now
    ): int {
        session_regenerate_id(true);

        $sessionHash = SessionRuntime::sessionHash();

        $expiresAt = $now
            ->add(
                new DateInterval(
                    'PT' . Env::int('SESSION_LIFETIME_MINUTES', 30) . 'M'
                )
            )
            ->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'INSERT INTO user_sessions (
                user_id,
                session_hash,
                ip_address,
                user_agent,
                last_activity_at,
                expires_at
             ) VALUES (
                :user_id,
                :session_hash,
                :ip_address,
                :user_agent,
                UTC_TIMESTAMP(),
                :expires_at
             )'
        );

        $statement->execute([
            'user_id'      => $userId,
            'session_hash' => $sessionHash,
            'ip_address'   => Http::clientIp(),
            'user_agent'   => Http::userAgent(),
            'expires_at'   => $expiresAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<int, array<string, mixed>> */
    private function loadApplications(int $userId): array
    {
        $applications = [];

        /*
         * Standard applications continue using user_application_roles.
         * Maintenance is excluded because its source of truth is now
         * maintenance_access_grants.
         */
        $accessStatement = $this->pdo->prepare(
            "SELECT
                a.id,
                a.code,
                a.name,
                a.base_path,
                a.sort_order,
                r.code AS role_code,
                r.name AS role_name
             FROM user_application_roles uar
             INNER JOIN applications a
                ON a.id = uar.application_id
               AND a.is_active = 1
             INNER JOIN application_roles r
                ON r.id = uar.role_id
               AND r.application_id = uar.application_id
               AND r.is_active = 1
             WHERE uar.user_id = :user_id
               AND uar.revoked_at IS NULL
               AND a.code <> 'maintenance'
               AND EXISTS (
                   SELECT 1
                   FROM role_permissions rp_access
                   INNER JOIN permissions p_access
                      ON p_access.id = rp_access.permission_id
                     AND p_access.application_id =
                         rp_access.application_id
                   WHERE rp_access.application_id =
                         uar.application_id
                     AND rp_access.role_id = uar.role_id
                     AND p_access.code = 'access'
               )
             ORDER BY a.sort_order, a.code"
        );

        $accessStatement->execute([
            'user_id' => $userId,
        ]);

        foreach ($accessStatement->fetchAll() as $row) {
            $code = (string) $row['code'];

            $applications[$code] = [
                'code'         => $code,
                'name'         => (string) $row['name'],
                'base_path'    => (string) $row['base_path'],
                'access_model' => 'single_role',
                'role'         => [
                    'code' => (string) $row['role_code'],
                    'name' => (string) $row['role_name'],
                ],
                'permissions'  => [],
                'grants'       => [],
                '_sort_order'  => (int) $row['sort_order'],
            ];
        }

        /*
         * Load permissions for standard applications.
         */
        if ($applications !== []) {
            $permissionsStatement = $this->pdo->prepare(
                "SELECT
                    a.code AS application_code,
                    p.code AS permission_code
                 FROM user_application_roles uar
                 INNER JOIN applications a
                    ON a.id = uar.application_id
                   AND a.is_active = 1
                 INNER JOIN application_roles r
                    ON r.id = uar.role_id
                   AND r.application_id = uar.application_id
                   AND r.is_active = 1
                 INNER JOIN role_permissions rp
                    ON rp.application_id = uar.application_id
                   AND rp.role_id = uar.role_id
                 INNER JOIN permissions p
                    ON p.id = rp.permission_id
                   AND p.application_id = uar.application_id
                 WHERE uar.user_id = :user_id
                   AND uar.revoked_at IS NULL
                   AND a.code <> 'maintenance'
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
                         AND p_access.code = 'access'
                   )
                 ORDER BY a.sort_order, p.code"
            );

            $permissionsStatement->execute([
                'user_id' => $userId,
            ]);

            foreach ($permissionsStatement->fetchAll() as $row) {
                $code = (string) $row['application_code'];

                if (! isset($applications[$code])) {
                    continue;
                }

                $applications[$code]['permissions'][] =
                (string) $row['permission_code'];
            }
        }

        /*
         * Maintenance access now comes from maintenance_access_grants.
         *
         * Each territorial grant retains its own permissions. We do not
         * merge operational permissions between locations because doing so
         * would allow one location's level to leak into another location.
         */
        $maintenanceStatement = $this->pdo->prepare(
            "SELECT
                mag.id AS grant_id,
                a.id AS application_id,
                a.code AS application_code,
                a.name AS application_name,
                a.base_path,
                a.sort_order,
                r.id AS role_id,
                r.code AS role_code,
                r.name AS role_name,
                mag.location_id,
                l.code AS location_code,
                l.name AS location_name
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
             ORDER BY
                CASE
                    WHEN mag.location_id IS NULL THEN 0
                    ELSE 1
                END,
                l.sort_order,
                l.name,
                mag.id"
        );

        $maintenanceStatement->execute([
            'user_id' => $userId,
        ]);

        $maintenanceRows = $maintenanceStatement->fetchAll();

        if ($maintenanceRows !== []) {
            $first = $maintenanceRows[0];

            $maintenance = [
                'code'         => 'maintenance',
                'name'         => (string) $first['application_name'],
                'base_path'    => (string) $first['base_path'],
                'access_model' => 'location_grants',
                'role'         => null,
                'permissions'  => [],
                'grants'       => [],
                '_sort_order'  => (int) $first['sort_order'],
            ];

            $roleSummaries = [];
            $grantIndexes  = [];
            $globalGrantId = null;

            foreach ($maintenanceRows as $row) {
                $grantId = (int) $row['grant_id'];

                $role = [
                    'code' => (string) $row['role_code'],
                    'name' => (string) $row['role_name'],
                ];

                $roleSummaries[$role['code']] = $role;

                $location = null;

                if ($row['location_id'] !== null) {
                    $location = [
                        'id'   => (int) $row['location_id'],
                        'code' => (string) $row['location_code'],
                        'name' => (string) $row['location_name'],
                    ];
                } else {
                    $globalGrantId = $grantId;
                }

                $maintenance['grants'][] = [
                    'id'          => $grantId,
                    'role'        => $role,
                    'location'    => $location,
                    'is_global'   => $location === null,
                    'permissions' => [],
                ];

                $grantIndexes[$grantId] =
                    array_key_last($maintenance['grants']);
            }

            /*
             * Load permissions independently for every Maintenance grant.
             */
            $grantPermissions = $this->pdo->prepare(
                "SELECT
                    mag.id AS grant_id,
                    p.code AS permission_code
                 FROM maintenance_access_grants mag
                 INNER JOIN applications a
                    ON a.id = mag.application_id
                   AND a.code = 'maintenance'
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

            $grantPermissions->execute([
                'user_id' => $userId,
            ]);

            foreach ($grantPermissions->fetchAll() as $row) {
                $grantId = (int) $row['grant_id'];

                if (! isset($grantIndexes[$grantId])) {
                    continue;
                }

                $index = $grantIndexes[$grantId];

                $maintenance['grants'][$index]['permissions'][] =
                (string) $row['permission_code'];
            }

            /*
             * Compatibility summary for the current authorization layer:
             *
             * - A GLOBAL grant can safely expose all of its permissions at
             *   application level.
             * - Territorial grants expose only "access" at application level.
             *   Their operational permissions stay attached to each grant
             *   until the location-aware authorization resolver is introduced.
             */
            if ($globalGrantId !== null) {
                $index = $grantIndexes[$globalGrantId];

                $maintenance['permissions'] =
                    $maintenance['grants'][$index]['permissions'];
            } else {
                $maintenance['permissions'] = ['access'];
            }

            if (count($roleSummaries) === 1) {
                $maintenance['role'] = reset($roleSummaries);
            }

            $applications['maintenance'] = $maintenance;
        }

        /*
         * Restore the catalogue order used by the Hub.
         */
        $result = array_values($applications);

        usort(
            $result,
            static fn(array $left, array $right): int =>
            ($left['_sort_order'] ?? 0)
            <=> ($right['_sort_order'] ?? 0)
        );

        foreach ($result as &$application) {
            unset($application['_sort_order']);
        }

        unset($application);

        return $result;
    }

    private function getCoreApplicationId(): ?int
    {
        if ($this->coreApplicationId !== null) {
            return $this->coreApplicationId;
        }

        $statement = $this->pdo->query(
            "SELECT id
             FROM applications
             WHERE code = 'core'
             LIMIT 1"
        );

        $id = $statement->fetchColumn();

        if ($id === false) {
            return null;
        }

        $this->coreApplicationId = (int) $id;

        return $this->coreApplicationId;
    }
}
