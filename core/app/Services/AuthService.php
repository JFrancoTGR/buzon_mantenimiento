<?php

declare (strict_types = 1);

namespace App\Services;

use App\Config\Env;
use App\Core\Http;
use App\Exceptions\HttpException;
use App\Security\SessionManager;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class AuthService
{
    private const DUMMY_PASSWORD_HASH =
        '$2y$12$9Vq6G8Y6F8cUxEYtVqQgNuKJMd6PqMGTmE3JO2iLZ2gjHVv2DnMDu';

    private ?int $coreApplicationId = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditService $audit
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

            SessionManager::establish($userId, $databaseSessionId);

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
        $auth = SessionManager::authData();

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
            'user_id'    => $auth['user_id'],
        ]);

        $row = $statement->fetch();

        $now = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        );

        $isInvalid =
        ! is_array($row)
        || $row['revoked_at'] !== null
        || (string) $row['status'] !== 'active'
        || ! hash_equals(
            (string) ($row['session_hash'] ?? ''),
            SessionManager::sessionHash()
        )
        || new DateTimeImmutable(
            (string) $row['expires_at'],
            new DateTimeZone('UTC')
        ) <= $now;

        if ($isInvalid) {
            $this->logout(false);

            throw new HttpException(
                401,
                'session_expired',
                'La sesión expiró o fue revocada.'
            );
        }

        $newExpiry = $now
            ->add(
                new DateInterval(
                    'PT' . Env::int('SESSION_LIFETIME_MINUTES', 30) . 'M'
                )
            )
            ->format('Y-m-d H:i:s');

        $touch = $this->pdo->prepare(
            'UPDATE user_sessions
             SET last_activity_at = UTC_TIMESTAMP(),
                 expires_at = :expires_at
             WHERE id = :id'
        );

        $touch->execute([
            'expires_at' => $newExpiry,
            'id'         => $auth['database_session_id'],
        ]);

        $applications = $this->loadApplications($auth['user_id']);

        return [
            'id'                   => (int) $row['id'],
            'first_name'           => (string) $row['first_name'],
            'last_name'            => (string) $row['last_name'],
            'full_name'            => trim(
                (string) $row['first_name'] . ' ' . (string) $row['last_name']
            ),
            'email'                => (string) $row['email'],
            'status'               => (string) $row['status'],
            'must_change_password' => (bool) $row['must_change_password'],
            'last_login_at'        => $row['last_login_at'],
            'applications'         => $applications,
        ];
    }

    public function logout(bool $recordAudit = true): void
    {
        $auth = SessionManager::authData();

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

        SessionManager::destroyLocal();
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

        $currentSession = SessionManager::authData();

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

            $databaseSessionId =
            $this->insertDatabaseSession(
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

        SessionManager::establish(
            $userId,
            $databaseSessionId
        );

        return $this->currentUser();
    }

    private function insertDatabaseSession(
        int $userId,
        DateTimeImmutable $now
    ): int {
        session_regenerate_id(true);

        $sessionHash = SessionManager::sessionHash();

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
        $accessStatement = $this->pdo->prepare(
            'SELECT
                a.id,
                a.code,
                a.name,
                a.base_path,
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
  AND EXISTS (
      SELECT 1
      FROM role_permissions rp_access
      INNER JOIN permissions p_access
          ON p_access.id = rp_access.permission_id
         AND p_access.application_id = rp_access.application_id
      WHERE rp_access.application_id = uar.application_id
        AND rp_access.role_id = uar.role_id
        AND p_access.code = \'access\'
  )
ORDER BY a.sort_order, a.code'
        );

        $accessStatement->execute(['user_id' => $userId]);

        $applications = [];
        $indexes      = [];

        foreach ($accessStatement->fetchAll() as $row) {
            $code = (string) $row['code'];

            $applications[] = [
                'code'        => $code,
                'name'        => (string) $row['name'],
                'base_path'   => (string) $row['base_path'],
                'role'        => [
                    'code' => (string) $row['role_code'],
                    'name' => (string) $row['role_name'],
                ],
                'permissions' => [],
            ];

            $indexes[$code] = array_key_last($applications);
        }

        if ($applications === []) {
            return [];
        }

        $permissionsStatement = $this->pdo->prepare(
            'SELECT
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
  AND EXISTS (
      SELECT 1
      FROM role_permissions rp_access
      INNER JOIN permissions p_access
          ON p_access.id = rp_access.permission_id
         AND p_access.application_id = rp_access.application_id
      WHERE rp_access.application_id = uar.application_id
        AND rp_access.role_id = uar.role_id
        AND p_access.code = \'access\'
  )
ORDER BY a.code, p.code'
        );

        $permissionsStatement->execute(['user_id' => $userId]);

        foreach ($permissionsStatement->fetchAll() as $row) {
            $applicationCode = (string) $row['application_code'];

            if (! array_key_exists($applicationCode, $indexes)) {
                continue;
            }

            $index = $indexes[$applicationCode];

            $applications[$index]['permissions'][] =
            (string) $row['permission_code'];
        }

        return $applications;
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
