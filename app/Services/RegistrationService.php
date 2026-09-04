<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Core\Http;
use App\Exceptions\HttpException;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class RegistrationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditService $audit,
        private readonly AuthService $auth,
        private readonly MailerService $mailer
    ) {
    }

    /** @return array<string, mixed> */
    public function context(?string $locationCode): array
    {
        return [
            'location' => $this->findActiveLocation($locationCode),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function register(array $input): array
    {
        $firstName = $this->normalizeName((string) ($input['first_name'] ?? ''), 80, 'nombre');
        $lastName = $this->normalizeName((string) ($input['last_name'] ?? ''), 120, 'apellidos');
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $confirmation = (string) ($input['password_confirmation'] ?? '');
        $privacyAccepted = filter_var($input['privacy_accepted'] ?? false, FILTER_VALIDATE_BOOL);
        $honeypot = trim((string) ($input['company_website'] ?? ''));
        $location = $this->findActiveLocation((string) ($input['location'] ?? ''));

        if ($honeypot !== '') {
            $this->audit->safeRecord('auth.registration.bot_trap', 'user', null, null, [
                'email' => $email,
            ]);

            return [
                'registered' => true,
                'verification_required' => true,
                'email_sent' => true,
                'email_masked' => self::maskEmail($email),
                'location' => $location,
            ];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new HttpException(422, 'invalid_email', 'Ingresa un correo electrónico válido.');
        }

        if (!$privacyAccepted) {
            throw new HttpException(422, 'privacy_required', 'Debes aceptar el aviso de privacidad.');
        }

        if ($password !== $confirmation) {
            throw new HttpException(422, 'password_confirmation_mismatch', 'La confirmación de contraseña no coincide.');
        }

        AuthService::validatePasswordStrength($password);
        $this->assertRegistrationRateLimit(Http::clientIp());
        $this->mailer->assertReady();

        $this->pdo->beginTransaction();

        try {
            $existingStatement = $this->pdo->prepare(
                'SELECT id, status FROM users WHERE email = :email LIMIT 1 FOR UPDATE'
            );
            $existingStatement->execute(['email' => $email]);
            $existing = $existingStatement->fetch();

            if (is_array($existing)) {
                $this->pdo->rollBack();
                $this->audit->safeRecord('auth.registration.duplicate', 'user', (int) $existing['id'], null, [
                    'email' => $email,
                    'status' => (string) $existing['status'],
                ]);
                throw new HttpException(
                    409,
                    'account_exists',
                    'Ya existe una cuenta asociada con este correo. Puedes iniciar sesión o reenviar la verificación.'
                );
            }

            $roleStatement = $this->pdo->prepare(
                "SELECT id FROM roles WHERE code = 'reporter' AND is_active = 1 LIMIT 1"
            );
            $roleStatement->execute();
            $roleId = $roleStatement->fetchColumn();

            if ($roleId === false) {
                throw new HttpException(500, 'reporter_role_missing', 'El rol de usuario reportante no está configurado.');
            }

            $insertUser = $this->pdo->prepare(
                'INSERT INTO users (
                    first_name, last_name, email, password_hash, status,
                    must_change_password, email_verified_at
                 ) VALUES (
                    :first_name, :last_name, :email, :password_hash, \'pending\', 0, NULL
                 )'
            );
            $insertUser->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $userId = (int) $this->pdo->lastInsertId();

            $assignRole = $this->pdo->prepare(
                'INSERT INTO user_roles (user_id, role_id, assigned_by_user_id)
                 VALUES (:user_id, :role_id, NULL)'
            );
            $assignRole->execute([
                'user_id' => $userId,
                'role_id' => (int) $roleId,
            ]);

            $token = $this->issueToken($userId, $location['id'] ?? null);

            $this->audit->record(
                'auth.registration.created',
                'user',
                $userId,
                null,
                null,
                null,
                [
                    'email' => $email,
                    'status' => 'pending',
                    'role' => 'reporter',
                    'location_code' => $location['code'] ?? null,
                ]
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $emailSent = true;
        try {
            $this->mailer->sendVerificationEmail(
                $userId,
                $email,
                trim($firstName . ' ' . $lastName),
                $token,
                $location
            );
        } catch (Throwable $exception) {
            $emailSent = false;
            $this->audit->safeRecord('auth.email_verification.delivery_failed', 'user', $userId, null, [
                'email' => $email,
            ]);
        }

        return [
            'registered' => true,
            'verification_required' => true,
            'email_sent' => $emailSent,
            'email_masked' => self::maskEmail($email),
            'location' => $location,
        ];
    }

    /** @return array<string, mixed> */
    public function resendVerification(string $email, ?string $locationCode): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            return ['accepted' => true];
        }

        $requestedLocation = $this->findActiveLocation($locationCode);
        $userStatement = $this->pdo->prepare(
            "SELECT id, first_name, last_name, email
             FROM users
             WHERE email = :email AND status = 'pending' AND email_verified_at IS NULL
             LIMIT 1"
        );
        $userStatement->execute(['email' => $email]);
        $user = $userStatement->fetch();

        if (!is_array($user)) {
            $this->audit->safeRecord('auth.email_verification.resend_ignored', 'user', null, null, ['email' => $email]);
            return ['accepted' => true];
        }

        $userId = (int) $user['id'];
        $latestStatement = $this->pdo->prepare(
            'SELECT evt.created_at, evt.location_id, l.code AS location_code, l.name AS location_name
             FROM email_verification_tokens evt
             LEFT JOIN locations l ON l.id = evt.location_id AND l.is_active = 1
             WHERE evt.user_id = :user_id
             ORDER BY evt.created_at DESC
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
            $this->audit->safeRecord('auth.email_verification.resend_limited', 'user', $userId, null, ['reason' => 'hourly_limit']);
            return ['accepted' => true];
        }

        if (is_array($latest)) {
            $latestAt = new DateTimeImmutable((string) $latest['created_at'], new DateTimeZone('UTC'));
            if ($latestAt->add(new DateInterval("PT{$cooldownMinutes}M")) > $now) {
                $this->audit->safeRecord('auth.email_verification.resend_limited', 'user', $userId, null, ['reason' => 'cooldown']);
                return ['accepted' => true];
            }
        }

        $location = $requestedLocation;
        if (
            $location === null
            && is_array($latest)
            && $latest['location_id'] !== null
            && $latest['location_code'] !== null
        ) {
            $location = [
                'id' => (int) $latest['location_id'],
                'code' => (string) $latest['location_code'],
                'name' => (string) $latest['location_name'],
            ];
        }

        try {
            $this->mailer->assertReady();
        } catch (Throwable $exception) {
            $this->audit->safeRecord('auth.email_verification.resend_unavailable', 'user', $userId, null);
            return ['accepted' => true];
        }

        $this->pdo->beginTransaction();

        try {
            $token = $this->issueToken($userId, $location['id'] ?? null);
            $this->audit->record(
                'auth.email_verification.resent',
                'user',
                $userId,
                null,
                null,
                null,
                ['location_code' => $location['code'] ?? null]
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        try {
            $this->mailer->sendVerificationEmail(
                $userId,
                (string) $user['email'],
                trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
                $token,
                $location
            );
        } catch (Throwable $exception) {
            $this->audit->safeRecord('auth.email_verification.delivery_failed', 'user', $userId, null, [
                'email' => $email,
                'resend' => true,
            ]);
        }

        return ['accepted' => true];
    }

    /** @return array<string, mixed> */
    public function verifyEmail(string $rawToken): array
    {
        $rawToken = trim($rawToken);
        if (strlen($rawToken) !== 64 || !ctype_xdigit($rawToken)) {
            throw new HttpException(422, 'invalid_verification_token', 'El enlace de verificación no es válido.');
        }

        $tokenHash = hash('sha256', $rawToken);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'SELECT
                    evt.id, evt.user_id, evt.location_id, evt.expires_at,
                    evt.used_at, evt.revoked_at,
                    u.status, u.email_verified_at,
                    l.code AS location_code, l.name AS location_name
                 FROM email_verification_tokens evt
                 INNER JOIN users u ON u.id = evt.user_id
                 LEFT JOIN locations l ON l.id = evt.location_id AND l.is_active = 1
                 WHERE evt.token_hash = :token_hash
                 LIMIT 1
                 FOR UPDATE'
            );
            $statement->execute(['token_hash' => $tokenHash]);
            $row = $statement->fetch();

            $invalid = !is_array($row)
                || $row['used_at'] !== null
                || $row['revoked_at'] !== null
                || new DateTimeImmutable((string) $row['expires_at'], new DateTimeZone('UTC')) <= $now
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
                 WHERE id = :id AND status = 'pending'"
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
                null,
                ['status' => 'pending', 'email_verified_at' => null],
                ['status' => 'active', 'location_code' => $row['location_code']]
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $user = $this->auth->establishSessionForUser($userId, 'auth.session.email_verification');
        $location = null;
        if ($row['location_id'] !== null && $row['location_code'] !== null) {
            $location = [
                'id' => (int) $row['location_id'],
                'code' => (string) $row['location_code'],
                'name' => (string) $row['location_name'],
            ];
        }

        $redirectUrl = './new-ticket.html';
        if ($location !== null) {
            $redirectUrl .= '?location=' . rawurlencode((string) $location['code']);
        }

        return [
            'verified' => true,
            'user' => $user,
            'location' => $location,
            'redirect_url' => $redirectUrl,
        ];
    }

    /** @return array{id:int,code:string,name:string}|null */
    private function findActiveLocation(?string $locationCode): ?array
    {
        $locationCode = strtolower(trim((string) $locationCode));
        if ($locationCode === '' || preg_match('/^[a-z0-9_]{1,50}$/', $locationCode) !== 1) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, code, name
             FROM locations
             WHERE code = :code AND is_active = 1
             LIMIT 1'
        );
        $statement->execute(['code' => $locationCode]);
        $location = $statement->fetch();

        if (!is_array($location)) {
            return null;
        }

        return [
            'id' => (int) $location['id'],
            'code' => (string) $location['code'],
            'name' => (string) $location['name'],
        ];
    }

    private function issueToken(int $userId, ?int $locationId): string
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->add(new DateInterval('PT' . Env::int('EMAIL_VERIFICATION_TTL_MINUTES', 60) . 'M'))
            ->format('Y-m-d H:i:s');

        $revoke = $this->pdo->prepare(
            'UPDATE email_verification_tokens
             SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
             WHERE user_id = :user_id
               AND used_at IS NULL
               AND revoked_at IS NULL'
        );
        $revoke->execute(['user_id' => $userId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO email_verification_tokens (
                user_id, location_id, token_hash, expires_at,
                requested_ip, user_agent
             ) VALUES (
                :user_id, :location_id, :token_hash, :expires_at,
                :requested_ip, :user_agent
             )'
        );
        $insert->execute([
            'user_id' => $userId,
            'location_id' => $locationId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'requested_ip' => Http::clientIp(),
            'user_agent' => Http::userAgent(),
        ]);

        return $rawToken;
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
        $count = (int) $statement->fetchColumn();

        if ($count >= Env::int('REGISTRATION_MAX_PER_IP_HOUR', 5)) {
            $this->audit->safeRecord('auth.registration.rate_limited', 'user', null, null, ['ip' => $ipAddress]);
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
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

        if ($length < 2 || $length > $maxLength) {
            throw new HttpException(422, 'invalid_name', "El campo {$label} no tiene una longitud válida.");
        }

        if (preg_match("/^[\\p{L}\\p{M} .'-]+$/u", $value) !== 1) {
            throw new HttpException(422, 'invalid_name', "El campo {$label} contiene caracteres no permitidos.");
        }

        return $value;
    }

    private static function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = substr($local, 0, min(2, strlen($local)));
        return $visible . str_repeat('*', max(3, strlen($local) - strlen($visible))) . '@' . $domain;
    }
}
