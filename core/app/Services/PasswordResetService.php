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

final class PasswordResetService
{
    private const APPLICATION_CODE = 'core';

    private const GENERIC_MESSAGE =
        'Si existe una cuenta habilitada asociada con ese correo, recibirás un enlace para restablecer tu contraseña.';

    private ?int $coreApplicationId = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditService $audit,
        private readonly SharedMailer $mailer
    ) {
    }

    /** @return array{accepted:bool,message:string} */
    public function request(string $email): array
    {
        $email = strtolower(trim($email));

        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 190
        ) {
            throw new HttpException(
                422,
                'invalid_email',
                'Ingresa un correo electrónico válido.'
            );
        }

        $ipAddress = Http::clientIp();

        $this->assertIpRateLimit($ipAddress);

        $emailHash = hash('sha256', $email);

        $this->audit->safeRecord(
            'auth.password_reset.requested',
            'password_reset',
            null,
            null,
            $this->coreApplicationId(),
            [
                'email_hash' => $emailHash,
            ]
        );

        $userStatement = $this->pdo->prepare(
            'SELECT
                id,
                first_name,
                last_name,
                email,
                password_hash,
                status,
                email_verified_at
             FROM users
             WHERE email = :email
             LIMIT 1'
        );

        $userStatement->execute([
            'email' => $email,
        ]);

        $user = $userStatement->fetch();

        if (!$this->isEligibleUser($user)) {
            $this->audit->safeRecord(
                'auth.password_reset.request_ignored',
                'password_reset',
                null,
                null,
                $this->coreApplicationId(),
                [
                    'email_hash' => $emailHash,
                    'reason' => 'account_not_eligible',
                ]
            );

            return self::genericResponse();
        }

        $userId = (int) $user['id'];
        $issued = $this->issueToken($userId);

        if ($issued === null) {
            $this->audit->safeRecord(
                'auth.password_reset.request_limited',
                'user',
                $userId,
                null,
                $this->coreApplicationId(),
                [
                    'reason' => 'user_limit_or_cooldown',
                ]
            );

            return self::genericResponse();
        }

        $tokenId = (int) $issued['token_id'];
        $fullName = trim(
            (string) $user['first_name']
            . ' '
            . (string) $user['last_name']
        );

        try {
            $ttlMinutes = max(
                5,
                Env::int('PASSWORD_RESET_TTL_MINUTES', 30)
            );

            $resetUrl = rtrim(
                Env::get('APP_URL'),
                '/'
            )
                . '/reset-password#token='
                . rawurlencode((string) $issued['raw_token']);

            $this->mailer->send(
                new MailMessage(
                    self::APPLICATION_CODE,
                    'auth.password_reset.requested',
                    'password-reset-requested',
                    $userId,
                    (string) $user['email'],
                    $fullName,
                    [
                        'reset_url' => $resetUrl,
                        'ttl_minutes' => $ttlMinutes,
                    ],
                    'password_reset_token',
                    $tokenId
                )
            );

            $this->finalizeDelivery(
                $userId,
                $tokenId,
                true
            );

            $this->audit->safeRecord(
                'auth.password_reset.delivery_sent',
                'password_reset_token',
                $tokenId,
                null,
                $this->coreApplicationId(),
                [
                    'user_id' => $userId,
                ]
            );
        } catch (Throwable $exception) {
            $this->finalizeDelivery(
                $userId,
                $tokenId,
                false
            );

            $this->audit->safeRecord(
                'auth.password_reset.delivery_failed',
                'password_reset_token',
                $tokenId,
                null,
                $this->coreApplicationId(),
                [
                    'user_id' => $userId,
                ]
            );
        }

        return self::genericResponse();
    }

    /**
     * @return array{
     *   first_name:string,
     *   email_masked:string,
     *   expires_at:string
     * }
     */
    public function inspect(string $rawToken): array
    {
        $tokenHash = $this->normalizeToken($rawToken);

        $statement = $this->pdo->prepare(
            'SELECT
                prt.id,
                prt.user_id,
                prt.expires_at,
                prt.used_at,
                prt.revoked_at,
                u.first_name,
                u.email,
                u.password_hash,
                u.status,
                u.email_verified_at
             FROM password_reset_tokens prt
             INNER JOIN users u
                ON u.id = prt.user_id
             WHERE prt.token_hash = :token_hash
             LIMIT 1'
        );

        $statement->execute([
            'token_hash' => $tokenHash,
        ]);

        $row = $statement->fetch();

        if (!$this->isUsableResetRow($row)) {
            throw self::unavailableTokenException();
        }

        return [
            'first_name' => (string) $row['first_name'],
            'email_masked' => self::maskEmail(
                (string) $row['email']
            ),
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    /**
     * @return array{password_reset:bool,redirect_url:string}
     */
    public function reset(
        string $rawToken,
        string $password,
        string $confirmation
    ): array {
        if ($password !== $confirmation) {
            throw new HttpException(
                422,
                'password_confirmation_mismatch',
                'La confirmación de contraseña no coincide.'
            );
        }

        AuthService::validatePasswordStrength($password);

        $tokenHash = $this->normalizeToken($rawToken);

        $userId = 0;
        $recipientEmail = '';
        $recipientName = '';

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'SELECT
                    prt.id,
                    prt.user_id,
                    prt.expires_at,
                    prt.used_at,
                    prt.revoked_at,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.password_hash,
                    u.status,
                    u.email_verified_at
                 FROM password_reset_tokens prt
                 INNER JOIN users u
                    ON u.id = prt.user_id
                 WHERE prt.token_hash = :token_hash
                 LIMIT 1
                 FOR UPDATE'
            );

            $statement->execute([
                'token_hash' => $tokenHash,
            ]);

            $row = $statement->fetch();

            if (!$this->isUsableResetRow($row)) {
                throw self::unavailableTokenException();
            }

            $userId = (int) $row['user_id'];
            $recipientEmail = (string) $row['email'];
            $recipientName = trim(
                (string) $row['first_name']
                . ' '
                . (string) $row['last_name']
            );

            if (
                password_verify(
                    $password,
                    (string) $row['password_hash']
                )
            ) {
                throw new HttpException(
                    422,
                    'password_reused',
                    'La nueva contraseña debe ser diferente de la contraseña actual.'
                );
            }

            $updateUser = $this->pdo->prepare(
                'UPDATE users
                 SET password_hash = :password_hash,
                     must_change_password = 0,
                     failed_login_attempts = 0,
                     locked_until = NULL
                 WHERE id = :user_id
                   AND status = \'active\''
            );

            $updateUser->execute([
                'password_hash' => password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                'user_id' => $userId,
            ]);

            if ($updateUser->rowCount() !== 1) {
                throw self::unavailableTokenException();
            }

            $useToken = $this->pdo->prepare(
                'UPDATE password_reset_tokens
                 SET used_at = UTC_TIMESTAMP()
                 WHERE id = :token_id
                   AND user_id = :user_id
                   AND used_at IS NULL
                   AND revoked_at IS NULL'
            );

            $useToken->execute([
                'token_id' => (int) $row['id'],
                'user_id' => $userId,
            ]);

            if ($useToken->rowCount() !== 1) {
                throw self::unavailableTokenException();
            }

            $revokeOtherTokens = $this->pdo->prepare(
                'UPDATE password_reset_tokens
                 SET revoked_at = COALESCE(
                     revoked_at,
                     UTC_TIMESTAMP()
                 )
                 WHERE user_id = :user_id
                   AND id <> :token_id
                   AND used_at IS NULL
                   AND revoked_at IS NULL'
            );

            $revokeOtherTokens->execute([
                'user_id' => $userId,
                'token_id' => (int) $row['id'],
            ]);

            $revokeSessions = $this->pdo->prepare(
                'UPDATE user_sessions
                 SET revoked_at = COALESCE(
                     revoked_at,
                     UTC_TIMESTAMP()
                 )
                 WHERE user_id = :user_id
                   AND revoked_at IS NULL'
            );

            $revokeSessions->execute([
                'user_id' => $userId,
            ]);

            $this->audit->record(
                'auth.password_reset.completed',
                'user',
                $userId,
                $userId,
                $this->coreApplicationId(),
                null,
                [
                    'password_reset_token_id' => (int) $row['id'],
                    'sessions_revoked' => true,
                ]
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        try {
            $loginUrl = rtrim(
                Env::get('APP_URL'),
                '/'
            ) . '/login';

            $this->mailer->send(
                new MailMessage(
                    self::APPLICATION_CODE,
                    'auth.password_reset.completed',
                    'password-reset-completed',
                    $userId,
                    $recipientEmail,
                    $recipientName,
                    [
                        'login_url' => $loginUrl,
                    ],
                    'user',
                    $userId
                )
            );
        } catch (Throwable $exception) {
            $this->audit->safeRecord(
                'auth.password_reset.completion_email_failed',
                'user',
                $userId,
                $userId,
                $this->coreApplicationId()
            );
        }

        return [
            'password_reset' => true,
            'redirect_url' => '/login',
        ];
    }

    /** @return array{token_id:int,raw_token:string}|null */
    private function issueToken(int $userId): ?array
    {
        $this->pdo->beginTransaction();

        try {
            $lockUser = $this->pdo->prepare(
                'SELECT id
                 FROM users
                 WHERE id = :user_id
                   AND status = \'active\'
                 LIMIT 1
                 FOR UPDATE'
            );

            $lockUser->execute([
                'user_id' => $userId,
            ]);

            if (
                $lockUser->fetchColumn() === false
                || !$this->canIssueForUser($userId)
            ) {
                $this->pdo->commit();
                return null;
            }

            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);

            $ttlMinutes = max(
                5,
                Env::int('PASSWORD_RESET_TTL_MINUTES', 30)
            );

            $expiresAt = (
                new DateTimeImmutable(
                    'now',
                    new DateTimeZone('UTC')
                )
            )
                ->add(
                    new DateInterval(
                        "PT{$ttlMinutes}M"
                    )
                )
                ->format('Y-m-d H:i:s');

            $insert = $this->pdo->prepare(
                'INSERT INTO password_reset_tokens (
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

            $tokenId = (int) $this->pdo->lastInsertId();

            $this->audit->record(
                'auth.password_reset.token_issued',
                'password_reset_token',
                $tokenId,
                null,
                $this->coreApplicationId(),
                null,
                [
                    'user_id' => $userId,
                    'expires_at' => $expiresAt,
                ]
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        return [
            'token_id' => $tokenId,
            'raw_token' => $rawToken,
        ];
    }

    private function finalizeDelivery(
        int $userId,
        int $tokenId,
        bool $delivered
    ): void {
        try {
            if ($delivered) {
                $statement = $this->pdo->prepare(
                    'UPDATE password_reset_tokens
                     SET revoked_at = COALESCE(
                         revoked_at,
                         UTC_TIMESTAMP()
                     )
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
                'UPDATE password_reset_tokens
                 SET revoked_at = COALESCE(
                     revoked_at,
                     UTC_TIMESTAMP()
                 )
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
                'auth.password_reset.delivery_finalize_failed',
                'password_reset_token',
                $tokenId,
                null,
                $this->coreApplicationId(),
                [
                    'user_id' => $userId,
                    'delivered' => $delivered,
                ]
            );
        }
    }

    private function assertIpRateLimit(
        ?string $ipAddress
    ): void {
        if ($ipAddress === null || $ipAddress === '') {
            return;
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM audit_log
             WHERE action_code = \'auth.password_reset.requested\'
               AND ip_address = :ip_address
               AND created_at >= DATE_SUB(
                   UTC_TIMESTAMP(),
                   INTERVAL 1 HOUR
               )'
        );

        $statement->execute([
            'ip_address' => $ipAddress,
        ]);

        $maxPerHour = max(
            1,
            Env::int(
                'PASSWORD_RESET_MAX_PER_IP_HOUR',
                10
            )
        );

        if ((int) $statement->fetchColumn() >= $maxPerHour) {
            $this->audit->safeRecord(
                'auth.password_reset.ip_limited',
                'password_reset',
                null,
                null,
                $this->coreApplicationId(),
                [
                    'ip' => $ipAddress,
                ]
            );

            throw new HttpException(
                429,
                'password_reset_rate_limited',
                'Se alcanzó el límite temporal de solicitudes. Intenta nuevamente más tarde.'
            );
        }
    }

    private function canIssueForUser(
        int $userId
    ): bool {
        $countStatement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM email_log
             WHERE application_id = :application_id
               AND recipient_user_id = :user_id
               AND event_code = \'auth.password_reset.requested\'
               AND status = \'sent\'
               AND created_at >= DATE_SUB(
                   UTC_TIMESTAMP(),
                   INTERVAL 1 HOUR
               )'
        );

        $countStatement->execute([
            'application_id' => $this->coreApplicationId(),
            'user_id' => $userId,
        ]);

        $maxPerHour = max(
            1,
            Env::int(
                'PASSWORD_RESET_MAX_PER_USER_HOUR',
                3
            )
        );

        if (
            (int) $countStatement->fetchColumn()
            >= $maxPerHour
        ) {
            return false;
        }

        $latestStatement = $this->pdo->prepare(
            'SELECT created_at
             FROM password_reset_tokens
             WHERE user_id = :user_id
               AND used_at IS NULL
               AND revoked_at IS NULL
               AND expires_at > UTC_TIMESTAMP()
             ORDER BY created_at DESC
             LIMIT 1'
        );

        $latestStatement->execute([
            'user_id' => $userId,
        ]);

        $latestAt = $latestStatement->fetchColumn();

        if (
            !is_string($latestAt)
            || $latestAt === ''
        ) {
            return true;
        }

        $cooldownMinutes = max(
            1,
            Env::int(
                'PASSWORD_RESET_COOLDOWN_MINUTES',
                10
            )
        );

        $latest = new DateTimeImmutable(
            $latestAt,
            new DateTimeZone('UTC')
        );

        $now = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        );

        return $latest->add(
            new DateInterval(
                "PT{$cooldownMinutes}M"
            )
        ) <= $now;
    }

    /** @param mixed $row */
    private function isEligibleUser(
        mixed $row
    ): bool {
        return is_array($row)
            && (string) ($row['status'] ?? '') === 'active'
            && is_string($row['password_hash'] ?? null)
            && (string) $row['password_hash'] !== ''
            && $row['email_verified_at'] !== null;
    }

    /** @param mixed $row */
    private function isUsableResetRow(
        mixed $row
    ): bool {
        if (!is_array($row)) {
            return false;
        }

        if (
            $row['used_at'] !== null
            || $row['revoked_at'] !== null
            || (string) ($row['status'] ?? '') !== 'active'
            || !is_string($row['password_hash'] ?? null)
            || (string) $row['password_hash'] === ''
            || $row['email_verified_at'] === null
        ) {
            return false;
        }

        try {
            $expiresAt = new DateTimeImmutable(
                (string) $row['expires_at'],
                new DateTimeZone('UTC')
            );
        } catch (Throwable) {
            return false;
        }

        return $expiresAt > new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        );
    }

    private function normalizeToken(
        string $rawToken
    ): string {
        $rawToken = trim($rawToken);

        if (
            strlen($rawToken) !== 64
            || !ctype_xdigit($rawToken)
        ) {
            throw self::unavailableTokenException();
        }

        return hash(
            'sha256',
            strtolower($rawToken)
        );
    }

    private static function unavailableTokenException(): HttpException
    {
        return new HttpException(
            410,
            'password_reset_token_unavailable',
            'El enlace de recuperación venció, ya fue utilizado o dejó de ser válido. Solicita uno nuevo.'
        );
    }

    /** @return array{accepted:bool,message:string} */
    private static function genericResponse(): array
    {
        return [
            'accepted' => true,
            'message' => self::GENERIC_MESSAGE,
        ];
    }

    private static function maskEmail(
        string $email
    ): string {
        if (!str_contains($email, '@')) {
            return '***';
        }

        [$local, $domain] = explode(
            '@',
            $email,
            2
        );

        $visible = substr(
            $local,
            0,
            min(2, strlen($local))
        );

        return $visible
            . str_repeat(
                '*',
                max(
                    3,
                    strlen($local) - strlen($visible)
                )
            )
            . '@'
            . $domain;
    }

    private function coreApplicationId(): ?int
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