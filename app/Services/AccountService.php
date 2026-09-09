<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\HttpException;
use PDO;
use Throwable;

final class AccountService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditService $audit,
        private readonly AuthService $auth
    ) {
    }

    /** @return array<string, mixed> */
    public function profile(): array
    {
        $sessionUser = $this->auth->currentUser();
        return $this->loadProfile((int) $sessionUser['id']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{changed:bool,profile:array<string,mixed>}
     */
    public function updateProfile(array $input): array
    {
        $sessionUser = $this->auth->currentUser();
        $userId = (int) $sessionUser['id'];

        $firstName = $this->normalizeName((string) ($input['first_name'] ?? ''), 80, 'nombre');
        $lastName = $this->normalizeName((string) ($input['last_name'] ?? ''), 120, 'apellidos');

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, first_name, last_name, status
                 FROM users
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE'
            );
            $statement->execute(['id' => $userId]);
            $current = $statement->fetch();

            if (!is_array($current) || (string) $current['status'] !== 'active') {
                throw new HttpException(401, 'session_expired', 'La sesión expiró o fue revocada.');
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
                    'profile' => $this->loadProfile($userId),
                ];
            }

            $update = $this->pdo->prepare(
                'UPDATE users
                 SET first_name = :first_name,
                     last_name = :last_name
                 WHERE id = :id'
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
                null,
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

        return [
            'changed' => true,
            'profile' => $this->loadProfile($userId),
        ];
    }

    /** @return array<string, mixed> */
    private function loadProfile(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                u.id,
                u.first_name,
                u.last_name,
                u.email,
                u.status,
                u.email_verified_at,
                u.last_login_at,
                u.created_at,
                r.code AS role_code,
                r.name AS role_name
             FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id AND r.is_active = 1
             WHERE u.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new HttpException(404, 'profile_not_found', 'No fue posible cargar la información de la cuenta.');
        }

        return [
            'id' => (int) $row['id'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'full_name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
            'email' => (string) $row['email'],
            'status' => (string) $row['status'],
            'role' => [
                'code' => (string) $row['role_code'],
                'name' => (string) $row['role_name'],
            ],
            'email_verified_at' => $row['email_verified_at'],
            'last_login_at' => $row['last_login_at'],
            'created_at' => $row['created_at'],
        ];
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
}
