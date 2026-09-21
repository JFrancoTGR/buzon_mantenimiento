<?php

declare (strict_types = 1);

namespace App\Services;

use App\Config\Env;
use App\Core\Http;
use App\Exceptions\HttpException;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use EUTools\Shared\Mail\Mailer as SharedMailer;
use EUTools\Shared\Mail\MailMessage;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

final class UserInvitationService
{
    private const TOKEN_HEX_LENGTH = 64;
    private const APPLICATION_CODE = 'core';

    private ?int $coreApplicationId = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditService $audit,
        private readonly SharedMailer $mailer,
        private readonly AuthService $auth
    ) {
    }

    /**
     * Emite una invitación dentro de una transacción ya abierta.
     *
     * @return array{
     *   token_id:int,
     *   user_id:int,
     *   recipient_email:string,
     *   recipient_name:string,
     *   raw_token:string,
     *   expires_at:string
     * }
     */
    public function issueWithinTransaction(
        int $userId,
        int $createdByUserId,
        string $auditAction = 'user.invitation.create'
    ): array {
        if (! $this->pdo->inTransaction()) {
            throw new LogicException(
                'La invitación debe emitirse dentro de una transacción activa.'
            );
        }

        $user = $this->loadInvitedUserForUpdate($userId);

        if ($user === null) {
            throw new HttpException(
                409,
                'user_not_invitable',
                'La cuenta no se encuentra en estado de invitación.'
            );
        }

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $ttlHours = max(
            1,
            Env::int('USER_INVITATION_TTL_HOURS', 72)
        );

        $expiresAt = (
            new DateTimeImmutable(
                'now',
                new DateTimeZone('UTC')
            )
        )
            ->add(new DateInterval("PT{$ttlHours}H"))
            ->format('Y-m-d H:i:s');

        $insert = $this->pdo->prepare(
            'INSERT INTO user_invitation_tokens (
                user_id,
                token_hash,
                expires_at,
                created_by_user_id,
                requested_ip,
                user_agent
             ) VALUES (
                :user_id,
                :token_hash,
                :expires_at,
                :created_by_user_id,
                :requested_ip,
                :user_agent
             )'
        );

        $insert->execute([
            'user_id'            => $userId,
            'token_hash'         => $tokenHash,
            'expires_at'         => $expiresAt,
            'created_by_user_id' => $createdByUserId,
            'requested_ip'       => Http::clientIp(),
            'user_agent'         => Http::userAgent(),
        ]);

        $tokenId = (int) $this->pdo->lastInsertId();

        $this->audit->record(
            $auditAction,
            'user_invitation_token',
            $tokenId,
            $createdByUserId,
            $this->coreApplicationId(),
            null,
            [
                'user_id'    => $userId,
                'expires_at' => $expiresAt,
            ]
        );

        return [
            'token_id'        => $tokenId,
            'user_id'         => $userId,
            'recipient_email' => (string) $user['email'],
            'recipient_name'  => trim(
                (string) $user['first_name']
                . ' '
                . (string) $user['last_name']
            ),
            'raw_token'       => $rawToken,
            'expires_at'      => $expiresAt,
        ];
    }

    /**
     * @param array<string, mixed> $invitation
     */
    public function deliver(array $invitation): bool
    {
        $tokenId = (int) ($invitation['token_id'] ?? 0);
        $userId  = (int) ($invitation['user_id'] ?? 0);

        if ($tokenId < 1 || $userId < 1) {
            throw new LogicException(
                'La invitación no contiene identificadores válidos.'
            );
        }

        $ttlHours = max(
            1,
            Env::int('USER_INVITATION_TTL_HOURS', 72)
        );

        $appUrl = rtrim(
            Env::get('APP_URL'),
            '/'
        );

        $invitationUrl = $appUrl
        . '/accept-invitation#token='
        . rawurlencode(
            (string) $invitation['raw_token']
        );

        $appTimezone = new DateTimeZone(
            Env::get(
                'APP_TIMEZONE',
                'America/Mexico_City'
            )
        );

        $expiresLocal = (
            new DateTimeImmutable(
                (string) $invitation['expires_at'],
                new DateTimeZone('UTC')
            )
        )
            ->setTimezone($appTimezone)
            ->format('d/m/Y H:i');

        $message = new MailMessage(
            self::APPLICATION_CODE,
            'auth.user_invitation.requested',
            'user-invitation',
            $userId,
            (string) $invitation['recipient_email'],
            (string) $invitation['recipient_name'],
            [
                'invitation_url' => $invitationUrl,
                'ttl_hours'      => $ttlHours,
                'expires_local'  => $expiresLocal,
            ],
            'user_invitation_token',
            $tokenId
        );

        try {
            $this->mailer->send($message);
        } catch (Throwable $exception) {
            try {
                $this->revokeCurrentToken(
                    $userId,
                    $tokenId
                );
            } catch (Throwable $revokeException) {
                $this->audit->safeRecord(
                    'user.invitation.delivery_recovery_failed',
                    'user_invitation_token',
                    $tokenId,
                    null,
                    $this->coreApplicationId(),
                    [
                        'user_id' => $userId,
                    ]
                );

                throw new RuntimeException(
                    'No fue posible asegurar el estado de la invitación fallida.',
                    0,
                    $revokeException
                );
            }

            $this->audit->safeRecord(
                'user.invitation.delivery_failed',
                'user_invitation_token',
                $tokenId,
                null,
                $this->coreApplicationId(),
                [
                    'user_id' => $userId,
                    'email'   => (string) (
                        $invitation['recipient_email'] ?? ''
                    ),
                ]
            );

            return false;
        }

        return $this->finalizeSuccessfulDelivery(
            $userId,
            $tokenId
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(string $rawToken): array
    {
        $tokenHash = $this->normalizeTokenHash($rawToken);

        $statement = $this->pdo->prepare(
            'SELECT
                uit.id,
                uit.user_id,
                uit.expires_at,
                uit.used_at,
                uit.revoked_at,
                u.first_name,
                u.email,
                u.status,
                u.password_hash,
                u.email_verified_at
             FROM user_invitation_tokens uit
             INNER JOIN users u
                ON u.id = uit.user_id
             WHERE uit.token_hash = :token_hash
             LIMIT 1'
        );

        $statement->execute([
            'token_hash' => $tokenHash,
        ]);

        $row = $statement->fetch();

        if (! $this->isUsableInvitation($row)) {
            throw new HttpException(
                410,
                'invitation_not_available',
                'Esta invitación ha expirado, ya fue utilizada o dejó de ser válida. Solicita una nueva invitación al administrador.'
            );
        }

        return [
            'valid'        => true,
            'first_name'   => (string) $row['first_name'],
            'email_masked' => self::maskEmail(
                (string) $row['email']
            ),
            'expires_at'   => (string) $row['expires_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function accept(
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

        AuthService::validatePasswordStrength(
            $password
        );

        $tokenHash = $this->normalizeTokenHash(
            $rawToken
        );

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'SELECT
                uit.id,
                uit.user_id,
                uit.expires_at,
                uit.used_at,
                uit.revoked_at,
                u.first_name,
                u.last_name,
                u.email,
                u.status,
                u.password_hash,
                u.email_verified_at
             FROM user_invitation_tokens uit
             INNER JOIN users u
                ON u.id = uit.user_id
             WHERE uit.token_hash = :token_hash
             LIMIT 1
             FOR UPDATE'
            );

            $statement->execute([
                'token_hash' => $tokenHash,
            ]);

            $row = $statement->fetch();

            if (! $this->isUsableInvitation($row)) {
                throw new HttpException(
                    410,
                    'invitation_not_available',
                    'Esta invitación ha expirado, ya fue utilizada o dejó de ser válida. Solicita una nueva invitación al administrador.'
                );
            }

            $userId  = (int) $row['user_id'];
            $tokenId = (int) $row['id'];

            $updateUser = $this->pdo->prepare(
                "UPDATE users
             SET password_hash = :password_hash,
                 status = 'active',
                 must_change_password = 0,
                 email_verified_at = UTC_TIMESTAMP(),
                 failed_login_attempts = 0,
                 locked_until = NULL,
                 deactivated_at = NULL
             WHERE id = :id
               AND status = 'invited'
               AND password_hash IS NULL
               AND email_verified_at IS NULL"
            );

            $updateUser->execute([
                'password_hash' =>
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),

                'id'            => $userId,
            ]);

            if ($updateUser->rowCount() !== 1) {
                throw new HttpException(
                    409,
                    'invitation_state_changed',
                    'La cuenta cambió de estado. Solicita una nueva invitación.'
                );
            }

            $consumeToken = $this->pdo->prepare(
                'UPDATE user_invitation_tokens
             SET used_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND used_at IS NULL
               AND revoked_at IS NULL'
            );

            $consumeToken->execute([
                'id' => $tokenId,
            ]);

            if ($consumeToken->rowCount() !== 1) {
                throw new HttpException(
                    409,
                    'invitation_state_changed',
                    'La invitación ya no se encuentra disponible.'
                );
            }

            $revokeOthers = $this->pdo->prepare(
                'UPDATE user_invitation_tokens
             SET revoked_at = COALESCE(
                 revoked_at,
                 UTC_TIMESTAMP()
             )
             WHERE user_id = :user_id
               AND id <> :token_id
               AND used_at IS NULL
               AND revoked_at IS NULL'
            );

            $revokeOthers->execute([
                'user_id'  => $userId,
                'token_id' => $tokenId,
            ]);

            $this->audit->record(
                'user.invitation.accept',
                'user',
                $userId,
                $userId,
                $this->coreApplicationId(),
                [
                    'status'            => 'invited',
                    'email_verified_at' => null,
                ],
                [
                    'status'            => 'active',
                    'email_verified_at' =>
                    'UTC_TIMESTAMP',
                ]
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
            'auth.session.user_invitation'
        );

        return [
            'activated'    => true,
            'user'         => $user,
            'redirect_url' => '/',
        ];
    }

    public function revokeWithinTransaction(
        int $userId,
        int $actorUserId
    ): int {
        if (! $this->pdo->inTransaction()) {
            throw new LogicException(
                'La revocación debe ejecutarse dentro de una transacción activa.'
            );
        }

        $statement = $this->pdo->prepare(
            'UPDATE user_invitation_tokens
             SET revoked_at = COALESCE(
                 revoked_at,
                 UTC_TIMESTAMP()
             )
             WHERE user_id = :user_id
               AND used_at IS NULL
               AND revoked_at IS NULL'
        );

        $statement->execute([
            'user_id' => $userId,
        ]);

        $count = $statement->rowCount();

        $this->audit->record(
            'user.invitation.revoke',
            'user',
            $userId,
            $actorUserId,
            $this->coreApplicationId(),
            null,
            [
                'tokens_revoked' => $count,
            ]
        );

        return $count;
    }

    private function finalizeSuccessfulDelivery(
        int $userId,
        int $tokenId
    ): bool {
        try {
            $statement = $this->pdo->prepare(
                'UPDATE user_invitation_tokens
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
                'user_id'  => $userId,
                'token_id' => $tokenId,
            ]);

            return true;
        } catch (Throwable $exception) {
            $this->audit->safeRecord(
                'user.invitation.delivery_finalize_failed',
                'user_invitation_token',
                $tokenId,
                null,
                $this->coreApplicationId(),
                [
                    'user_id'   => $userId,
                    'delivered' => true,
                ]
            );

            try {
                $this->revokeCurrentToken(
                    $userId,
                    $tokenId
                );
            } catch (Throwable $revokeException) {
                $this->audit->safeRecord(
                    'user.invitation.delivery_recovery_failed',
                    'user_invitation_token',
                    $tokenId,
                    null,
                    $this->coreApplicationId(),
                    [
                        'user_id' => $userId,
                    ]
                );

                throw new RuntimeException(
                    'No fue posible asegurar un único token de invitación válido.',
                    0,
                    $revokeException
                );
            }

            return false;
        }
    }

    private function revokeCurrentToken(
        int $userId,
        int $tokenId
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE user_invitation_tokens
         SET revoked_at = COALESCE(
             revoked_at,
             UTC_TIMESTAMP()
         )
         WHERE id = :token_id
           AND user_id = :user_id'
        );

        $statement->execute([
            'token_id' => $tokenId,
            'user_id'  => $userId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadInvitedUserForUpdate(
        int $userId
    ): ?array {
        $statement = $this->pdo->prepare(
            "SELECT
                id,
                first_name,
                last_name,
                email,
                status,
                password_hash,
                email_verified_at
             FROM users
             WHERE id = :id
               AND status = 'invited'
               AND password_hash IS NULL
               AND email_verified_at IS NULL
             LIMIT 1
             FOR UPDATE"
        );

        $statement->execute([
            'id' => $userId,
        ]);

        $row = $statement->fetch();

        return is_array($row)
            ? $row
            : null;
    }

    private function normalizeTokenHash(
        string $rawToken
    ): string {
        $rawToken = trim($rawToken);

        if (
            strlen($rawToken) !== self::TOKEN_HEX_LENGTH
            || ! ctype_xdigit($rawToken)
        ) {
            throw new HttpException(
                410,
                'invitation_not_available',
                'Esta invitación no es válida.'
            );
        }

        return hash('sha256', $rawToken);
    }

    private function isUsableInvitation(
        mixed $row
    ): bool {
        if (! is_array($row)) {
            return false;
        }

        if (
            $row['used_at'] !== null
            || $row['revoked_at'] !== null
        ) {
            return false;
        }

        if (
            (string) $row['status'] !== 'invited'
            || $row['password_hash'] !== null
            || $row['email_verified_at'] !== null
        ) {
            return false;
        }

        $expiresAt = new DateTimeImmutable(
            (string) $row['expires_at'],
            new DateTimeZone('UTC')
        );

        return $expiresAt > new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        );
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

    private static function maskEmail(
        string $email
    ): string {
        if (! str_contains($email, '@')) {
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
}
