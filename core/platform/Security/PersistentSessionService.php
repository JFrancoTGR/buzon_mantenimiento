<?php

declare(strict_types=1);

namespace EUTools\Core\Security;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use EUTools\Shared\Security\SessionRuntime;
use PDO;

final class PersistentSessionService
{
    private readonly int $lifetimeMinutes;

    public function __construct(
        private readonly PDO $pdo,
        int $lifetimeMinutes = 30
    ) {
        $this->lifetimeMinutes = max(1, $lifetimeMinutes);
    }

    /**
     * @return array{
     *   id:int,
     *   first_name:string,
     *   last_name:string,
     *   full_name:string,
     *   email:string,
     *   status:string,
     *   must_change_password:bool,
     *   last_login_at:mixed
     * }
     */
    public function requireCurrentIdentity(): array
    {
        $auth = SessionRuntime::authData();

        if ($auth === null) {
            throw new SessionException(
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
            'user_id' => $auth['user_id'],
        ]);

        $row = $statement->fetch();
        $now = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        );

        $expiresAt = null;

        if (
            is_array($row)
            && is_string($row['expires_at'] ?? null)
            && $row['expires_at'] !== ''
        ) {
            $expiresAt = new DateTimeImmutable(
                $row['expires_at'],
                new DateTimeZone('UTC')
            );
        }

        $isInvalid =
            !is_array($row)
            || $row['revoked_at'] !== null
            || (string) ($row['status'] ?? '') !== 'active'
            || !hash_equals(
                (string) ($row['session_hash'] ?? ''),
                SessionRuntime::sessionHash()
            )
            || $expiresAt === null
            || $expiresAt <= $now;

        if ($isInvalid) {
            $this->invalidateCurrentSession(
                (int) $auth['database_session_id'],
                (int) $auth['user_id']
            );

            throw new SessionException(
                401,
                'session_expired',
                'La sesión expiró o fue revocada.'
            );
        }

        $this->touchSession(
            (int) $auth['database_session_id'],
            (int) $auth['user_id'],
            $now
        );

        return [
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
            'must_change_password' =>
                (bool) $row['must_change_password'],
            'last_login_at' => $row['last_login_at'],
        ];
    }

    private function touchSession(
        int $databaseSessionId,
        int $userId,
        DateTimeImmutable $now
    ): void {
        $newExpiry = $now
            ->add(
                new DateInterval(
                    'PT' . $this->lifetimeMinutes . 'M'
                )
            )
            ->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'UPDATE user_sessions
             SET last_activity_at = UTC_TIMESTAMP(),
                 expires_at = :expires_at
             WHERE id = :id
               AND user_id = :user_id
               AND revoked_at IS NULL'
        );

        $statement->execute([
            'expires_at' => $newExpiry,
            'id' => $databaseSessionId,
            'user_id' => $userId,
        ]);
    }

    private function invalidateCurrentSession(
        int $databaseSessionId,
        int $userId
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE user_sessions
             SET revoked_at = COALESCE(
                 revoked_at,
                 UTC_TIMESTAMP()
             )
             WHERE id = :id
               AND user_id = :user_id'
        );

        $statement->execute([
            'id' => $databaseSessionId,
            'user_id' => $userId,
        ]);

        SessionRuntime::destroyLocal();
    }
}
