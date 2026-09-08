<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Core\Http;
use App\Exceptions\HttpException;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PDO;
use Throwable;

final class UserInvitationService
{
    private const TOKEN_HEX_LENGTH = 64;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditService $audit,
        private readonly AuthService $auth,
        private readonly MailerService $mailer
    ) {
    }

    /**
     * Emite una invitación dentro de una transacción ya abierta.
     *
     * @return array{
     *   user_id:int,
     *   recipient_email:string,
     *   recipient_name:string,
     *   raw_token:string,
     *   expires_at:string,
     *   role_code:string
     * }
     */
    public function issueWithinTransaction(
        int $userId,
        int $createdByUserId,
        string $auditAction = 'user.invitation.create'
    ): array {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('La invitación debe emitirse dentro de una transacción activa.');
        }

        $user = $this->loadInvitedUserForUpdate($userId);
        if ($user === null) {
            throw new HttpException(409, 'user_not_invitable', 'La cuenta no se encuentra en estado de invitación.');
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->add(new DateInterval('PT' . Env::int('USER_INVITATION_TTL_HOURS', 72) . 'H'))
            ->format('Y-m-d H:i:s');

        $revoke = $this->pdo->prepare(
            'UPDATE user_invitation_tokens
             SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
             WHERE user_id = :user_id
               AND used_at IS NULL
               AND revoked_at IS NULL'
        );
        $revoke->execute(['user_id' => $userId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO user_invitation_tokens (
                user_id, token_hash, expires_at, created_by_user_id,
                requested_ip, user_agent
             ) VALUES (
                :user_id, :token_hash, :expires_at, :created_by_user_id,
                :requested_ip, :user_agent
             )'
        );
        $insert->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_by_user_id' => $createdByUserId,
            'requested_ip' => Http::clientIp(),
            'user_agent' => Http::userAgent(),
        ]);
        $tokenId = (int) $this->pdo->lastInsertId();

        $this->audit->record(
            $auditAction,
            'user_invitation_token',
            $tokenId,
            $createdByUserId,
            null,
            null,
            [
                'user_id' => $userId,
                'role' => (string) $user['role_code'],
                'expires_at' => $expiresAt,
            ]
        );

        return [
            'user_id' => $userId,
            'recipient_email' => (string) $user['email'],
            'recipient_name' => trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
            'raw_token' => $rawToken,
            'expires_at' => $expiresAt,
            'role_code' => (string) $user['role_code'],
        ];
    }

    /** @param array<string, mixed> $invitation */
    public function deliver(array $invitation): bool
    {
        try {
            $this->mailer->sendUserInvitationEmail(
                (int) $invitation['user_id'],
                (string) $invitation['recipient_email'],
                (string) $invitation['recipient_name'],
                (string) $invitation['raw_token'],
                (string) $invitation['expires_at']
            );
            return true;
        } catch (Throwable $exception) {
            $this->audit->safeRecord(
                'user.invitation.delivery_failed',
                'user',
                (int) $invitation['user_id'],
                null,
                ['email' => (string) $invitation['recipient_email']]
            );
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function inspect(string $rawToken): array
    {
        $tokenHash = $this->normalizeTokenHash($rawToken);
        $statement = $this->pdo->prepare(
            'SELECT
                uit.id, uit.user_id, uit.expires_at, uit.used_at, uit.revoked_at,
                u.first_name, u.email, u.status, u.password_hash, u.email_verified_at
             FROM user_invitation_tokens uit
             INNER JOIN users u ON u.id = uit.user_id
             WHERE uit.token_hash = :token_hash
             LIMIT 1'
        );
        $statement->execute(['token_hash' => $tokenHash]);
        $row = $statement->fetch();

        if (!$this->isUsableInvitation($row)) {
            throw new HttpException(
                410,
                'invitation_not_available',
                'Esta invitación ha expirado, ya fue utilizada o dejó de ser válida. Solicita una nueva invitación al administrador.'
            );
        }

        return [
            'valid' => true,
            'first_name' => (string) $row['first_name'],
            'email_masked' => self::maskEmail((string) $row['email']),
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    /** @return array<string, mixed> */
    public function accept(string $rawToken, string $password, string $confirmation): array
    {
        if ($password !== $confirmation) {
            throw new HttpException(422, 'password_confirmation_mismatch', 'La confirmación de contraseña no coincide.');
        }
        AuthService::validatePasswordStrength($password);
        $tokenHash = $this->normalizeTokenHash($rawToken);

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT
                    uit.id, uit.user_id, uit.expires_at, uit.used_at, uit.revoked_at,
                    u.first_name, u.last_name, u.email, u.status,
                    u.password_hash, u.email_verified_at
                 FROM user_invitation_tokens uit
                 INNER JOIN users u ON u.id = uit.user_id
                 WHERE uit.token_hash = :token_hash
                 LIMIT 1
                 FOR UPDATE'
            );
            $statement->execute(['token_hash' => $tokenHash]);
            $row = $statement->fetch();

            if (!$this->isUsableInvitation($row)) {
                throw new HttpException(
                    410,
                    'invitation_not_available',
                    'Esta invitación ha expirado, ya fue utilizada o dejó de ser válida. Solicita una nueva invitación al administrador.'
                );
            }

            $userId = (int) $row['user_id'];
            $update = $this->pdo->prepare(
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
            $update->execute([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $userId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new HttpException(409, 'invitation_state_changed', 'La cuenta cambió de estado. Solicita una nueva invitación.');
            }

            $consume = $this->pdo->prepare(
                'UPDATE user_invitation_tokens
                 SET used_at = UTC_TIMESTAMP()
                 WHERE id = :id
                   AND used_at IS NULL
                   AND revoked_at IS NULL'
            );
            $consume->execute(['id' => (int) $row['id']]);
            if ($consume->rowCount() !== 1) {
                throw new HttpException(409, 'invitation_state_changed', 'La invitación ya no se encuentra disponible.');
            }

            $revokeOthers = $this->pdo->prepare(
                'UPDATE user_invitation_tokens
                 SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
                 WHERE user_id = :user_id
                   AND id <> :token_id
                   AND used_at IS NULL
                   AND revoked_at IS NULL'
            );
            $revokeOthers->execute([
                'user_id' => $userId,
                'token_id' => (int) $row['id'],
            ]);

            $this->audit->record(
                'user.invitation.accept',
                'user',
                $userId,
                $userId,
                null,
                [
                    'status' => 'invited',
                    'email_verified_at' => null,
                ],
                [
                    'status' => 'active',
                    'email_verified_at' => 'UTC_TIMESTAMP',
                ]
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $user = $this->auth->establishSessionForUser($userId, 'auth.session.user_invitation');

        return [
            'activated' => true,
            'user' => $user,
            'redirect_url' => './dashboard.html',
        ];
    }

    public function revokeWithinTransaction(int $userId, int $actorUserId): int
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('La revocación debe ejecutarse dentro de una transacción activa.');
        }

        $statement = $this->pdo->prepare(
            'UPDATE user_invitation_tokens
             SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
             WHERE user_id = :user_id
               AND used_at IS NULL
               AND revoked_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);
        $count = $statement->rowCount();

        $this->audit->record(
            'user.invitation.revoke',
            'user',
            $userId,
            $actorUserId,
            null,
            null,
            ['tokens_revoked' => $count]
        );

        return $count;
    }

    /** @return array<string, mixed>|null */
    private function loadInvitedUserForUpdate(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT u.id, u.first_name, u.last_name, u.email, u.status,
                    u.password_hash, u.email_verified_at, r.code AS role_code
             FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id AND r.is_active = 1
             WHERE u.id = :id
               AND u.status = 'invited'
               AND u.password_hash IS NULL
               AND u.email_verified_at IS NULL
             LIMIT 1
             FOR UPDATE"
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function normalizeTokenHash(string $rawToken): string
    {
        $rawToken = trim($rawToken);
        if (strlen($rawToken) !== self::TOKEN_HEX_LENGTH || !ctype_xdigit($rawToken)) {
            throw new HttpException(410, 'invitation_not_available', 'Esta invitación no es válida.');
        }
        return hash('sha256', $rawToken);
    }

    /** @param mixed $row */
    private function isUsableInvitation(mixed $row): bool
    {
        if (!is_array($row)) {
            return false;
        }
        if ($row['used_at'] !== null || $row['revoked_at'] !== null) {
            return false;
        }
        if ((string) $row['status'] !== 'invited' || $row['password_hash'] !== null || $row['email_verified_at'] !== null) {
            return false;
        }

        $expiresAt = new DateTimeImmutable((string) $row['expires_at'], new DateTimeZone('UTC'));
        return $expiresAt > new DateTimeImmutable('now', new DateTimeZone('UTC'));
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
