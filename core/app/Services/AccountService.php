<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Exceptions\HttpException;
use App\Security\SessionManager;
use EUTools\Shared\Mail\Mailer as SharedMailer;
use EUTools\Shared\Mail\MailMessage;
use PDO;
use Throwable;

final class AccountService
{
    private const APPLICATION_CODE = 'core';

    private ?int $coreApplicationId = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditService $audit,
        private readonly AuthService $auth,
        private readonly SharedMailer $mailer
    ) {
    }

    /** @return array<string, mixed> */
    public function profile(): array
    {
        $sessionUser = $this->auth->currentUser();

        return $this->loadProfile(
            (int) $sessionUser['id'],
            (array) ($sessionUser['applications'] ?? [])
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   changed:bool,
     *   profile:array<string,mixed>,
     *   email_notification_sent:?bool
     * }
     */
    public function updateProfile(array $input): array
    {
        $sessionUser = $this->auth->currentUser();
        $userId = (int) $sessionUser['id'];

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

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'SELECT id, first_name, last_name, email, status
                 FROM users
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE'
            );

            $statement->execute([
                'id' => $userId,
            ]);

            $current = $statement->fetch();

            if (
                !is_array($current)
                || (string) $current['status'] !== 'active'
            ) {
                throw new HttpException(
                    401,
                    'session_expired',
                    'La sesión expiró o fue revocada.'
                );
            }

            $oldValues = [
                'first_name' => (string) $current['first_name'],
                'last_name' => (string) $current['last_name'],
            ];

            $newValues = [
                'first_name' => $firstName,
                'last_name' => $lastName,
            ];

            if ($oldValues === $newValues) {
                $this->pdo->commit();

                return [
                    'changed' => false,
                    'profile' => $this->loadProfile(
                        $userId,
                        (array) ($sessionUser['applications'] ?? [])
                    ),
                    'email_notification_sent' => null,
                ];
            }

            $update = $this->pdo->prepare(
                'UPDATE users
                 SET first_name = :first_name,
                     last_name = :last_name
                 WHERE id = :id
                   AND status = \'active\''
            );

            $update->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'id' => $userId,
            ]);

            if ($update->rowCount() !== 1) {
                throw new HttpException(
                    409,
                    'profile_update_conflict',
                    'No fue posible actualizar el perfil. Recarga la página e intenta nuevamente.'
                );
            }

            $this->audit->record(
                'account.profile.update',
                'user',
                $userId,
                $userId,
                $this->coreApplicationId(),
                $oldValues,
                $newValues
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $profile = $this->loadProfile(
            $userId,
            (array) ($sessionUser['applications'] ?? [])
        );

        $emailSent = $this->sendProfileChangedNotification($profile);

        return [
            'changed' => true,
            'profile' => $profile,
            'email_notification_sent' => $emailSent,
        ];
    }

    /**
     * @return array{
     *   password_changed:bool,
     *   email_notification_sent:bool
     * }
     */
    public function changePassword(
        string $currentPassword,
        string $newPassword,
        string $confirmation
    ): array {
        $sessionUser = $this->auth->currentUser();
        $userId = (int) $sessionUser['id'];

        if ($newPassword !== $confirmation) {
            throw new HttpException(
                422,
                'password_confirmation_mismatch',
                'La confirmación de contraseña no coincide.'
            );
        }

        AuthService::validatePasswordStrength($newPassword);

        $statement = $this->pdo->prepare(
            'SELECT
                id,
                first_name,
                last_name,
                email,
                password_hash,
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
            !is_array($user)
            || (string) $user['status'] !== 'active'
            || !is_string($user['password_hash'])
            || $user['password_hash'] === ''
        ) {
            throw new HttpException(
                401,
                'session_expired',
                'La sesión expiró o fue revocada.'
            );
        }

        $storedHash = (string) $user['password_hash'];

        if (!password_verify($currentPassword, $storedHash)) {
            throw new HttpException(
                401,
                'current_password_invalid',
                'La contraseña actual no es correcta.'
            );
        }

        if (password_verify($newPassword, $storedHash)) {
            throw new HttpException(
                422,
                'password_reused',
                'La nueva contraseña debe ser diferente de la contraseña actual.'
            );
        }

        $this->pdo->beginTransaction();

        try {
            $update = $this->pdo->prepare(
                'UPDATE users
                 SET password_hash = :password_hash,
                     must_change_password = 0,
                     failed_login_attempts = 0,
                     locked_until = NULL
                 WHERE id = :id
                   AND status = \'active\''
            );

            $update->execute([
                'password_hash' => password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                ),
                'id' => $userId,
            ]);

            if ($update->rowCount() !== 1) {
                throw new HttpException(
                    409,
                    'password_update_conflict',
                    'No fue posible actualizar la contraseña.'
                );
            }

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
                'auth.password.change',
                'user',
                $userId,
                $userId,
                $this->coreApplicationId(),
                null,
                [
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

        SessionManager::destroyLocal();

        $recipientName = trim(
            (string) $user['first_name']
            . ' '
            . (string) $user['last_name']
        );

        $emailSent = $this->sendPasswordChangedNotification(
            $userId,
            (string) $user['email'],
            $recipientName
        );

        return [
            'password_changed' => true,
            'email_notification_sent' => $emailSent,
        ];
    }

    /**
     * @param array<int, array<string,mixed>> $applications
     * @return array<string, mixed>
     */
    private function loadProfile(
        int $userId,
        array $applications
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                first_name,
                last_name,
                email,
                status,
                email_verified_at,
                last_login_at,
                created_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );

        $statement->execute([
            'id' => $userId,
        ]);

        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new HttpException(
                404,
                'profile_not_found',
                'No fue posible cargar la información de la cuenta.'
            );
        }

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
            'email_verified_at' => $row['email_verified_at'],
            'last_login_at' => $row['last_login_at'],
            'created_at' => $row['created_at'],
            'applications' => $applications,
        ];
    }

    /** @param array<string,mixed> $profile */
    private function sendProfileChangedNotification(
        array $profile
    ): bool {
        try {
            $this->mailer->send(
                new MailMessage(
                    self::APPLICATION_CODE,
                    'account.profile.changed',
                    'profile-changed',
                    (int) $profile['id'],
                    (string) $profile['email'],
                    (string) $profile['full_name'],
                    [],
                    'user',
                    (int) $profile['id']
                )
            );

            return true;
        } catch (Throwable $exception) {
            $this->audit->safeRecord(
                'account.profile.change_notification_failed',
                'user',
                (int) $profile['id'],
                (int) $profile['id'],
                $this->coreApplicationId(),
                [
                    'email' => (string) $profile['email'],
                ]
            );

            return false;
        }
    }

    private function sendPasswordChangedNotification(
        int $userId,
        string $email,
        string $fullName
    ): bool {
        $loginUrl = rtrim(
            Env::get('APP_URL'),
            '/'
        ) . '/login';

        try {
            $this->mailer->send(
                new MailMessage(
                    self::APPLICATION_CODE,
                    'auth.password.changed',
                    'password-changed',
                    $userId,
                    $email,
                    $fullName,
                    [
                        'login_url' => $loginUrl,
                    ],
                    'user',
                    $userId
                )
            );

            return true;
        } catch (Throwable $exception) {
            $this->audit->safeRecord(
                'auth.password.change_notification_failed',
                'user',
                $userId,
                $userId,
                $this->coreApplicationId(),
                [
                    'email' => $email,
                ]
            );

            return false;
        }
    }

    private function normalizeName(
        string $value,
        int $maxLength,
        string $label
    ): string {
        $value = preg_replace(
            '/\s+/u',
            ' ',
            trim($value)
        ) ?? '';

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

        if (
            preg_match(
                "/^[\p{L}\p{M} .'-]+$/u",
                $value
            ) !== 1
        ) {
            throw new HttpException(
                422,
                'invalid_name',
                "El campo {$label} contiene caracteres no permitidos."
            );
        }

        return $value;
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