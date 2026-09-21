<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Core\Http;
use App\Exceptions\HttpException;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use EUTools\Shared\Mail\Mailer as SharedMailer;
use EUTools\Shared\Mail\MailMessage;
use PDO;
use Throwable;

final class RegistrationService
{
    private const CORE_APPLICATION_CODE = 'core';

    /** @var array<string, string> */
    private const PUBLIC_REGISTRATION_ROLES = [
        'maintenance' => 'reporter',
    ];

    private ?int $coreApplicationId = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditService $audit,
        private readonly SharedMailer $mailer,
        private readonly AuthService $auth
    ) {
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function register(array $input): array
    {
        $firstName = $this->normalizeName(
            (string) ($input['first_name'] ?? ''),
            80,
            'nombre'
        );
        $lastName = $this->normalizeName(
            (string) ($input['last_name'] ?? ''),
            120,
            'apellidos'
        );
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $confirmation = (string) ($input['password_confirmation'] ?? '');
        $privacyAccepted = filter_var(
            $input['privacy_accepted'] ?? false,
            FILTER_VALIDATE_BOOL
        );
        $honeypot = trim((string) ($input['company_website'] ?? ''));
        $applicationCode = $this->normalizeApplicationCode(
            (string) ($input['application'] ?? 'maintenance')
        );
        $locationCode = $this->normalizeLocationHint(
            (string) ($input['location'] ?? '')
        );

        if ($honeypot !== '') {
            $this->audit->safeRecord(
                'auth.registration.bot_trap',
                'user',
                null,
                null,
                $this->coreApplicationId(),
                ['email' => $email]
            );

            return [
                'registered' => true,
                'verification_required' => true,
                'email_sent' => true,
                'email_masked' => self::maskEmail($email),
            ];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new HttpException(
                422,
                'invalid_email',
                'Ingresa un correo electrónico válido.'
            );
        }

        if (!$privacyAccepted) {
            throw new HttpException(
                422,
                'privacy_required',
                'Debes aceptar el aviso de privacidad.'
            );
        }

        if ($password !== $confirmation) {
            throw new HttpException(
                422,
                'password_confirmation_mismatch',
                'La confirmación de contraseña no coincide.'
            );
        }

        AuthService::validatePasswordStrength($password);
        $this->assertRegistrationRateLimit(Http::clientIp());

        $publicAccess = $this->resolvePublicRegistrationAccess($applicationCode);

        $this->pdo->beginTransaction();

        try {
            $existingStatement = $this->pdo->prepare(
                'SELECT id, status
                 FROM users
                 WHERE email = :email
                 LIMIT 1
                 FOR UPDATE'
            );
            $existingStatement->execute(['email' => $email]);
            $existing = $existingStatement->fetch();

            if (is_array($existing)) {
                $this->pdo->rollBack();

                $this->audit->safeRecord(
                    'auth.registration.duplicate',
                    'user',
                    (int) $existing['id'],
                    null,
                    $this->coreApplicationId(),
                    [
                        'email' => $email,
                        'status' => (string) $existing['status'],
                    ]
                );

                throw new HttpException(
                    409,
                    'account_exists',
                    'Ya existe una cuenta asociada con este correo. Puedes iniciar sesión o reenviar la verificación.'
                );
            }

            $insertUser = $this->pdo->prepare(
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
                    :password_hash,
                    'pending',
                    0,
                    NULL
                 )"
            );
            $insertUser->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);

            $userId = (int) $this->pdo->lastInsertId();

            $assignRole = $this->pdo->prepare(
                'INSERT INTO user_application_roles (
                    user_id,
                    application_id,
                    role_id,
                    assigned_by_user_id
                 ) VALUES (
                    :user_id,
                    :application_id,
                    :role_id,
                    NULL
                 )'
            );
            $assignRole->execute([
                'user_id' => $userId,
                'application_id' => $publicAccess['application_id'],
                'role_id' => $publicAccess['role_id'],
            ]);

            $issuedToken = $this->issueToken($userId);

            $this->audit->record(
                'auth.registration.created',
                'user',
                $userId,
                null,
                $this->coreApplicationId(),
                null,
                [
                    'email' => $email,
                    'status' => 'pending',
                    'application' => $applicationCode,
                    'role' => $publicAccess['role_code'],
                    'location_hint' => $locationCode,
                ]
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $emailSent = $this->deliverVerification(
            $userId,
            $email,
            trim($firstName . ' ' . $lastName),
            (int) $issuedToken['token_id'],
            (string) $issuedToken['raw_token'],
            $applicationCode,
            $locationCode
        );

        return [
            'registered' => true,
            'verification_required' => true,
            'email_sent' => $emailSent,
            'email_masked' => self::maskEmail($email),
            'application' => $applicationCode,
            'location' => $locationCode !== null
                ? ['code' => $locationCode]
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function resendVerification(
        string $email,
        string $applicationCode = 'maintenance',
        ?string $locationCode = null
    ): array {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            return ['accepted' => true];
        }

        $applicationCode = $this->normalizeApplicationCode($applicationCode);
        $locationCode = $this->normalizeLocationHint((string) $locationCode);
        $publicAccess = $this->resolvePublicRegistrationAccess($applicationCode);

        $userStatement = $this->pdo->prepare(
            "SELECT u.id, u.first_name, u.last_name, u.email
             FROM users u
             INNER JOIN user_application_roles uar
                ON uar.user_id = u.id
               AND uar.application_id = :application_id
               AND uar.role_id = :role_id
               AND uar.revoked_at IS NULL
             WHERE u.email = :email
               AND u.status = 'pending'
               AND u.email_verified_at IS NULL
             LIMIT 1"
        );
        $userStatement->execute([
            'application_id' => $publicAccess['application_id'],
            'role_id' => $publicAccess['role_id'],
            'email' => $email,
        ]);
        $user = $userStatement->fetch();

        if (!is_array($user)) {
            $this->audit->safeRecord(
                'auth.email_verification.resend_ignored',
                'user',
                null,
                null,
                $this->coreApplicationId(),
                ['email' => $email]
            );

            return ['accepted' => true];
        }

        $userId = (int) $user['id'];
        $latestStatement = $this->pdo->prepare(
            'SELECT created_at, used_at, revoked_at
             FROM email_verification_tokens
             WHERE user_id = :user_id
             ORDER BY
                CASE WHEN used_at IS NULL AND revoked_at IS NULL THEN 0 ELSE 1 END,
                created_at DESC
             LIMIT 1'
        );
        $latestStatement->execute(['user_id' => $userId]);
        $latest = $latestStatement->fetch();

        $countStatement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM email_verification_tokens
             WHERE user_id = :user_id
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)'
        );
        $countStatement->execute(['user_id' => $userId]);

        $recentCount = (int) $countStatement->fetchColumn();
        $maxPerHour = Env::int('VERIFICATION_RESEND_MAX_PER_HOUR', 3);
        $cooldownMinutes = Env::int('VERIFICATION_RESEND_COOLDOWN_MINUTES', 10);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($recentCount >= $maxPerHour) {
            return ['accepted' => true];
        }

        if (
            is_array($latest)
            && $latest['used_at'] === null
            && $latest['revoked_at'] === null
        ) {
            $latestAt = new DateTimeImmutable(
                (string) $latest['created_at'],
                new DateTimeZone('UTC')
            );

            if ($latestAt->add(new DateInterval("PT{$cooldownMinutes}M")) > $now) {
                return ['accepted' => true];
            }
        }

        $this->pdo->beginTransaction();

        try {
            $issuedToken = $this->issueToken($userId);

            $this->audit->record(
                'auth.email_verification.resent',
                'user',
                $userId,
                null,
                $this->coreApplicationId(),
                null,
                [
                    'application' => $applicationCode,
                    'location_hint' => $locationCode,
                ]
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $this->deliverVerification(
            $userId,
            (string) $user['email'],
            trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
            (int) $issuedToken['token_id'],
            (string) $issuedToken['raw_token'],
            $applicationCode,
            $locationCode
        );

        return ['accepted' => true];
    }

    /** @return array<string, mixed> */
    public function verifyEmail(string $rawToken, ?string $next): array
    {
        $rawToken = trim($rawToken);

        if (strlen($rawToken) !== 64 || !ctype_xdigit($rawToken)) {
            throw new HttpException(
                422,
                'invalid_verification_token',
                'El enlace de verificación no es válido.'
            );
        }

        $tokenHash = hash('sha256', $rawToken);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'SELECT
                    evt.id,
                    evt.user_id,
                    evt.expires_at,
                    evt.used_at,
                    evt.revoked_at,
                    u.status,
                    u.email_verified_at
                 FROM email_verification_tokens evt
                 INNER JOIN users u ON u.id = evt.user_id
                 WHERE evt.token_hash = :token_hash
                 LIMIT 1
                 FOR UPDATE'
            );
            $statement->execute(['token_hash' => $tokenHash]);
            $row = $statement->fetch();

            $invalid = !is_array($row)
                || $row['used_at'] !== null
                || $row['revoked_at'] !== null
                || new DateTimeImmutable(
                    (string) $row['expires_at'],
                    new DateTimeZone('UTC')
                ) <= $now
                || (string) $row['status'] !== 'pending'
                || $row['email_verified_at'] !== null;

            if ($invalid) {
                throw new HttpException(
                    410,
                    'verification_token_expired',
                    'El enlace venció, ya fue utilizado o fue reemplazado. Solicita uno nuevo.'
                );
            }

            $userId = (int) $row['user_id'];

            $updateUser = $this->pdo->prepare(
                "UPDATE users
                 SET status = 'active',
                     email_verified_at = UTC_TIMESTAMP(),
                     failed_login_attempts = 0,
                     locked_until = NULL
                 WHERE id = :id
                   AND status = 'pending'"
            );
            $updateUser->execute(['id' => $userId]);

            $useToken = $this->pdo->prepare(
                'UPDATE email_verification_tokens
                 SET used_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $useToken->execute(['id' => (int) $row['id']]);

            $revokeOthers = $this->pdo->prepare(
                'UPDATE email_verification_tokens
                 SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
                 WHERE user_id = :user_id
                   AND id <> :token_id
                   AND used_at IS NULL'
            );
            $revokeOthers->execute([
                'user_id' => $userId,
                'token_id' => (int) $row['id'],
            ]);

            $this->audit->record(
                'auth.email.verified',
                'user',
                $userId,
                $userId,
                $this->coreApplicationId(),
                ['status' => 'pending', 'email_verified_at' => null],
                ['status' => 'active']
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $user = $this->auth->establishSessionForUser(
            $userId,
            'auth.session.email_verification'
        );

        return [
            'verified' => true,
            'user' => $user,
            'redirect_url' => $this->sanitizeNext($next),
        ];
    }

    /** @return array{application_id:int,role_id:int,role_code:string} */
    private function resolvePublicRegistrationAccess(string $applicationCode): array
    {
        $roleCode = self::PUBLIC_REGISTRATION_ROLES[$applicationCode] ?? null;

        if ($roleCode === null) {
            throw new HttpException(
                403,
                'public_registration_unavailable',
                'El registro público no está habilitado para esta aplicación.'
            );
        }

        $statement = $this->pdo->prepare(
            "SELECT
                a.id AS application_id,
                r.id AS role_id,
                r.code AS role_code
             FROM applications a
             INNER JOIN application_roles r
                ON r.application_id = a.id
               AND r.code = :role_code
               AND r.is_active = 1
             WHERE a.code = :application_code
               AND a.is_active = 1
               AND EXISTS (
                   SELECT 1
                   FROM role_permissions rp
                   INNER JOIN permissions p
                      ON p.id = rp.permission_id
                     AND p.application_id = rp.application_id
                   WHERE rp.application_id = a.id
                     AND rp.role_id = r.id
                     AND p.code = 'access'
               )
             LIMIT 1"
        );
        $statement->execute([
            'application_code' => $applicationCode,
            'role_code' => $roleCode,
        ]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new HttpException(
                503,
                'public_registration_not_configured',
                'El acceso inicial de esta aplicación no está configurado.'
            );
        }

        return [
            'application_id' => (int) $row['application_id'],
            'role_id' => (int) $row['role_id'],
            'role_code' => (string) $row['role_code'],
        ];
    }

    /** @return array{token_id:int,raw_token:string} */
    private function issueToken(int $userId): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->add(new DateInterval(
                'PT' . Env::int('EMAIL_VERIFICATION_TTL_MINUTES', 60) . 'M'
            ))
            ->format('Y-m-d H:i:s');

        $insert = $this->pdo->prepare(
            'INSERT INTO email_verification_tokens (
                user_id,
                token_hash,
                expires_at,
                requested_ip,
                user_agent
             ) VALUES (
                :user_id,
                :token_hash,
                :expires_at,
                :requested_ip,
                :user_agent
             )'
        );
        $insert->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'requested_ip' => Http::clientIp(),
            'user_agent' => Http::userAgent(),
        ]);

        return [
            'token_id' => (int) $this->pdo->lastInsertId(),
            'raw_token' => $rawToken,
        ];
    }

    private function deliverVerification(
        int $userId,
        string $email,
        string $fullName,
        int $tokenId,
        string $rawToken,
        string $applicationCode,
        ?string $locationCode
    ): bool {
        $ttlMinutes = Env::int('EMAIL_VERIFICATION_TTL_MINUTES', 60);
        $next = $this->buildNext($applicationCode, $locationCode);

        $verificationUrl = rtrim(Env::get('APP_URL'), '/')
            . '/verify-email?token=' . rawurlencode($rawToken)
            . '&next=' . rawurlencode($next);

        $message = new MailMessage(
            self::CORE_APPLICATION_CODE,
            'auth.email_verification.requested',
            'email-verification',
            $userId,
            $email,
            $fullName,
            [
                'verification_url' => $verificationUrl,
                'expires_minutes' => $ttlMinutes,
            ],
            'email_verification_token',
            $tokenId
        );

        try {
            $this->mailer->send($message);
            $this->finalizeVerificationTokenDelivery($userId, $tokenId, true);
            return true;
        } catch (Throwable $exception) {
            $this->finalizeVerificationTokenDelivery($userId, $tokenId, false);

            $this->audit->safeRecord(
                'auth.email_verification.delivery_failed',
                'user',
                $userId,
                null,
                $this->coreApplicationId(),
                ['email' => $email]
            );

            return false;
        }
    }

    private function finalizeVerificationTokenDelivery(
        int $userId,
        int $tokenId,
        bool $delivered
    ): void {
        try {
            if ($delivered) {
                $statement = $this->pdo->prepare(
                    'UPDATE email_verification_tokens
                     SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
                     WHERE user_id = :user_id
                       AND id <> :token_id
                       AND used_at IS NULL
                       AND revoked_at IS NULL'
                );
                $statement->execute([
                    'user_id' => $userId,
                    'token_id' => $tokenId,
                ]);
                return;
            }

            $statement = $this->pdo->prepare(
                'UPDATE email_verification_tokens
                 SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
                 WHERE id = :token_id
                   AND user_id = :user_id
                   AND used_at IS NULL
                   AND revoked_at IS NULL'
            );
            $statement->execute([
                'token_id' => $tokenId,
                'user_id' => $userId,
            ]);
        } catch (Throwable $exception) {
            $this->audit->safeRecord(
                'auth.email_verification.delivery_finalize_failed',
                'email_verification_token',
                $tokenId,
                $userId,
                $this->coreApplicationId(),
                ['delivered' => $delivered]
            );
        }
    }

    private function assertRegistrationRateLimit(?string $ipAddress): void
    {
        if ($ipAddress === null) {
            return;
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM email_verification_tokens
             WHERE requested_ip = :requested_ip
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)'
        );
        $statement->execute(['requested_ip' => $ipAddress]);

        if ((int) $statement->fetchColumn() >= Env::int('REGISTRATION_MAX_PER_IP_HOUR', 5)) {
            $this->audit->safeRecord(
                'auth.registration.rate_limited',
                'user',
                null,
                null,
                $this->coreApplicationId(),
                ['ip' => $ipAddress]
            );

            throw new HttpException(
                429,
                'registration_rate_limited',
                'Se alcanzó el límite temporal de registros. Intenta nuevamente más tarde.'
            );
        }
    }

    private function normalizeName(string $value, int $maxLength, string $label): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        $length = function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);

        if ($length < 2 || $length > $maxLength) {
            throw new HttpException(
                422,
                'invalid_name',
                "El campo {$label} no tiene una longitud válida."
            );
        }

        if (preg_match("/^[\\p{L}\\p{M} .'-]+$/u", $value) !== 1) {
            throw new HttpException(
                422,
                'invalid_name',
                "El campo {$label} contiene caracteres no permitidos."
            );
        }

        return $value;
    }

    private function normalizeApplicationCode(string $value): string
    {
        $value = strtolower(trim($value));

        if (!isset(self::PUBLIC_REGISTRATION_ROLES[$value])) {
            throw new HttpException(
                403,
                'public_registration_unavailable',
                'El registro público no está habilitado para esta aplicación.'
            );
        }

        return $value;
    }

    private function normalizeLocationHint(string $value): ?string
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return null;
        }

        return preg_match('/^[a-z0-9_]{1,50}$/', $value) === 1
            ? $value
            : null;
    }

    private function buildNext(string $applicationCode, ?string $locationCode): string
    {
        if ($applicationCode !== 'maintenance') {
            return '/';
        }

        $next = '/maintenance/new-ticket.html';

        if ($locationCode !== null) {
            $next .= '?location=' . rawurlencode($locationCode);
        }

        return $next;
    }

    private function sanitizeNext(?string $next): string
    {
        $next = trim((string) $next);

        if ($next === '') {
            return '/';
        }

        if (
            preg_match(
                '#^/maintenance/new-ticket\.html(?:\?location=[a-z0-9_]{1,50})?$#',
                $next
            ) === 1
        ) {
            return $next;
        }

        return '/';
    }

    private function coreApplicationId(): int
    {
        if ($this->coreApplicationId !== null) {
            return $this->coreApplicationId;
        }

        $statement = $this->pdo->prepare(
            'SELECT id
             FROM applications
             WHERE code = :code
             LIMIT 1'
        );
        $statement->execute(['code' => self::CORE_APPLICATION_CODE]);
        $id = $statement->fetchColumn();

        if ($id === false) {
            throw new HttpException(
                500,
                'core_application_missing',
                'La aplicación Core no está configurada.'
            );
        }

        return $this->coreApplicationId = (int) $id;
    }

    private static function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = substr($local, 0, min(2, strlen($local)));

        return $visible
            . str_repeat('*', max(3, strlen($local) - strlen($visible)))
            . '@'
            . $domain;
    }
}
