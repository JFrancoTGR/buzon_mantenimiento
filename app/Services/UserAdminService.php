<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Exceptions\HttpException;
use PDO;
use Throwable;

final class UserAdminService
{
    private const ROLE_CODES = ['reporter', 'supervisor', 'director', 'administrator'];
    private const FILTER_STATUSES = ['active', 'inactive', 'blocked', 'pending', 'invited'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditService $audit,
        private readonly UserInvitationService $invitations
    ) {
    }

    /** @param array<string, mixed> $actor @return array<string, mixed> */
    public function context(array $actor): array
    {
        AuthorizationService::requirePermission($actor, 'user.manage');

        $roles = $this->pdo->query(
            "SELECT code, name, description
             FROM roles
             WHERE is_active = 1
               AND code IN ('reporter','supervisor','director','administrator')
             ORDER BY FIELD(code, 'reporter','supervisor','director','administrator')"
        )->fetchAll();

        return [
            'roles' => array_map(static fn(array $role): array => [
                'code' => (string) $role['code'],
                'name' => (string) $role['name'],
                'description' => $role['description'] !== null ? (string) $role['description'] : null,
            ], $roles),
            'statuses' => self::FILTER_STATUSES,
            'invitation_ttl_hours' => Env::int('USER_INVITATION_TTL_HOURS', 72),
        ];
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $filters @return array<string, mixed> */
    public function listUsers(array $actor, array $filters): array
    {
        AuthorizationService::requirePermission($actor, 'user.manage');

        $search = trim((string) ($filters['search'] ?? ''));
        $role = strtolower(trim((string) ($filters['role'] ?? '')));
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 25)));

        if ($role !== '' && !in_array($role, self::ROLE_CODES, true)) {
            throw new HttpException(422, 'invalid_role_filter', 'El filtro de rol no es válido.');
        }
        if ($status !== '' && !in_array($status, self::FILTER_STATUSES, true)) {
            throw new HttpException(422, 'invalid_status_filter', 'El filtro de estado no es válido.');
        }
        if ($search !== '' && strlen($search) > 190) {
            throw new HttpException(422, 'invalid_search', 'La búsqueda es demasiado larga.');
        }

        $where = ['1=1'];
        $params = [];
        if ($search !== '') {
            $where[] = "(u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search OR CONCAT(u.first_name, ' ', u.last_name) LIKE :search)";
            $params['search'] = '%' . $search . '%';
        }
        if ($role !== '') {
            $where[] = 'r.code = :role';
            $params['role'] = $role;
        }
        if ($status !== '') {
            $where[] = 'u.status = :status';
            $params['status'] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $count = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE {$whereSql}"
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT
                    u.id, u.first_name, u.last_name, u.email, u.status,
                    u.email_verified_at, u.last_login_at, u.created_at, u.deactivated_at,
                    r.code AS role_code, r.name AS role_name,
                    uit.id AS invitation_id, uit.expires_at AS invitation_expires_at,
                    uit.used_at AS invitation_used_at, uit.revoked_at AS invitation_revoked_at,
                    uit.created_at AS invitation_created_at
                FROM users u
                INNER JOIN user_roles ur ON ur.user_id = u.id
                INNER JOIN roles r ON r.id = ur.role_id
                LEFT JOIN user_invitation_tokens uit
                  ON uit.id = (
                      SELECT MAX(uit2.id)
                      FROM user_invitation_tokens uit2
                      WHERE uit2.user_id = u.id
                  )
                WHERE {$whereSql}
                ORDER BY u.created_at DESC, u.id DESC
                LIMIT {$perPage} OFFSET {$offset}";
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $items = $statement->fetchAll();

        return [
            'items' => array_map(fn(array $row): array => $this->presentUser($row), $items),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $input @return array<string, mixed> */
    public function createInvitedUser(array $actor, array $input): array
    {
        AuthorizationService::requirePermission($actor, 'user.manage');
        AuthorizationService::requirePermission($actor, 'role.manage');

        $firstName = $this->normalizeName((string) ($input['first_name'] ?? ''), 80, 'nombre');
        $lastName = $this->normalizeName((string) ($input['last_name'] ?? ''), 120, 'apellidos');
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $roleCode = strtolower(trim((string) ($input['role'] ?? '')));
        $actorId = (int) ($actor['id'] ?? 0);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new HttpException(422, 'invalid_email', 'Ingresa un correo electrónico válido.');
        }
        if (!in_array($roleCode, self::ROLE_CODES, true)) {
            throw new HttpException(422, 'invalid_role', 'Selecciona un rol válido.');
        }

        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare('SELECT id, status FROM users WHERE email = :email LIMIT 1 FOR UPDATE');
            $existing->execute(['email' => $email]);
            if (is_array($existing->fetch())) {
                throw new HttpException(409, 'account_exists', 'Ya existe una cuenta asociada con este correo electrónico.');
            }

            $role = $this->loadRole($roleCode);
            if ($role === null) {
                throw new HttpException(422, 'role_not_available', 'El rol seleccionado no está disponible.');
            }

            $insertUser = $this->pdo->prepare(
                "INSERT INTO users (
                    first_name, last_name, email, password_hash, status,
                    must_change_password, failed_login_attempts, locked_until,
                    email_verified_at, deactivated_at
                 ) VALUES (
                    :first_name, :last_name, :email, NULL, 'invited',
                    0, 0, NULL, NULL, NULL
                 )"
            );
            $insertUser->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
            ]);
            $userId = (int) $this->pdo->lastInsertId();

            $assignRole = $this->pdo->prepare(
                'INSERT INTO user_roles (user_id, role_id, assigned_by_user_id, assigned_at)
                 VALUES (:user_id, :role_id, :assigned_by_user_id, UTC_TIMESTAMP())'
            );
            $assignRole->execute([
                'user_id' => $userId,
                'role_id' => (int) $role['id'],
                'assigned_by_user_id' => $actorId,
            ]);

            $this->audit->record(
                'user.create',
                'user',
                $userId,
                $actorId,
                null,
                null,
                [
                    'email' => $email,
                    'status' => 'invited',
                    'role' => $roleCode,
                ]
            );

            $invitation = $this->invitations->issueWithinTransaction($userId, $actorId, 'user.invitation.create');
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $emailSent = $this->invitations->deliver($invitation);

        return [
            'user_id' => $userId,
            'status' => 'invited',
            'role' => $roleCode,
            'email_sent' => $emailSent,
            'invitation_expires_at' => (string) $invitation['expires_at'],
        ];
    }

    /** @param array<string, mixed> $actor @return array<string, mixed> */
    public function resendInvitation(array $actor, int $userId): array
    {
        AuthorizationService::requirePermission($actor, 'user.manage');
        $actorId = (int) ($actor['id'] ?? 0);

        $this->pdo->beginTransaction();
        try {
            $target = $this->loadUserForUpdate($userId);
            if ($target === null || (string) $target['status'] !== 'invited' || $target['password_hash'] !== null) {
                throw new HttpException(409, 'user_not_invitable', 'La cuenta ya no se encuentra pendiente de invitación.');
            }

            $this->assertInvitationResendLimit($userId);
            $invitation = $this->invitations->issueWithinTransaction($userId, $actorId, 'user.invitation.resend');
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'user_id' => $userId,
            'email_sent' => $this->invitations->deliver($invitation),
            'invitation_expires_at' => (string) $invitation['expires_at'],
        ];
    }

    /** @param array<string, mixed> $actor */
    public function revokeInvitation(array $actor, int $userId): array
    {
        AuthorizationService::requirePermission($actor, 'user.manage');
        $actorId = (int) ($actor['id'] ?? 0);

        $this->pdo->beginTransaction();
        try {
            $target = $this->loadUserForUpdate($userId);
            if ($target === null || (string) $target['status'] !== 'invited') {
                throw new HttpException(409, 'user_not_invitable', 'La cuenta no tiene una invitación administrativa pendiente.');
            }
            $revoked = $this->invitations->revokeWithinTransaction($userId, $actorId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return ['user_id' => $userId, 'tokens_revoked' => $revoked];
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $input */
    public function changeRole(array $actor, int $userId, array $input): array
    {
        AuthorizationService::requirePermission($actor, 'user.manage');
        AuthorizationService::requirePermission($actor, 'role.manage');
        $actorId = (int) ($actor['id'] ?? 0);
        $newRoleCode = strtolower(trim((string) ($input['role'] ?? '')));

        if (!in_array($newRoleCode, self::ROLE_CODES, true)) {
            throw new HttpException(422, 'invalid_role', 'Selecciona un rol válido.');
        }
        if ($userId === $actorId) {
            throw new HttpException(409, 'self_role_change_denied', 'No puedes modificar tu propio rol.');
        }

        $this->pdo->beginTransaction();
        try {
            $target = $this->loadUserForUpdate($userId);
            if ($target === null) {
                throw new HttpException(404, 'user_not_found', 'El usuario no existe.');
            }
            $currentRoleCode = (string) $target['role_code'];
            if ($currentRoleCode === $newRoleCode) {
                $this->pdo->commit();
                return ['user_id' => $userId, 'role' => $newRoleCode, 'changed' => false];
            }

            $newRole = $this->loadRole($newRoleCode);
            if ($newRole === null) {
                throw new HttpException(422, 'role_not_available', 'El rol seleccionado no está disponible.');
            }

            $this->assertRoleCanBeReleased($target, $newRoleCode);
            if ($currentRoleCode === 'administrator' && (string) $target['status'] === 'active') {
                $this->assertAnotherActiveAdministrator($userId);
            }

            $update = $this->pdo->prepare(
                'UPDATE user_roles
                 SET role_id = :role_id,
                     assigned_by_user_id = :actor_id,
                     assigned_at = UTC_TIMESTAMP()
                 WHERE user_id = :user_id'
            );
            $update->execute([
                'role_id' => (int) $newRole['id'],
                'actor_id' => $actorId,
                'user_id' => $userId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new HttpException(409, 'user_role_changed', 'El rol del usuario cambió durante la operación. Recarga el módulo.');
            }

            $this->revokeSessions($userId);
            $this->audit->record(
                'user.role.change',
                'user',
                $userId,
                $actorId,
                null,
                ['role' => $currentRoleCode],
                ['role' => $newRoleCode]
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return ['user_id' => $userId, 'role' => $newRoleCode, 'changed' => true];
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $input */
    public function changeStatus(array $actor, int $userId, array $input): array
    {
        AuthorizationService::requirePermission($actor, 'user.manage');
        $actorId = (int) ($actor['id'] ?? 0);
        $newStatus = strtolower(trim((string) ($input['status'] ?? '')));

        if (!in_array($newStatus, ['active', 'inactive'], true)) {
            throw new HttpException(422, 'invalid_account_status', 'Sólo se permite activar o desactivar una cuenta desde este módulo.');
        }
        if ($userId === $actorId && $newStatus !== 'active') {
            throw new HttpException(409, 'self_deactivation_denied', 'No puedes desactivar tu propia cuenta.');
        }

        $this->pdo->beginTransaction();
        try {
            $target = $this->loadUserForUpdate($userId);
            if ($target === null) {
                throw new HttpException(404, 'user_not_found', 'El usuario no existe.');
            }
            $oldStatus = (string) $target['status'];
            if ($oldStatus === $newStatus) {
                $this->pdo->commit();
                return ['user_id' => $userId, 'status' => $newStatus, 'changed' => false];
            }

            if (in_array($oldStatus, ['invited', 'pending'], true)) {
                throw new HttpException(409, 'account_activation_flow_required', 'Esta cuenta debe completar su flujo de invitación o verificación antes de cambiar de estado.');
            }
            if ($newStatus === 'active' && ($target['password_hash'] === null || $target['email_verified_at'] === null)) {
                throw new HttpException(409, 'account_credentials_incomplete', 'La cuenta no puede activarse porque aún no tiene credenciales verificadas.');
            }

            if ($newStatus === 'inactive') {
                $this->assertRoleCanBeReleased($target, null);
                if ((string) $target['role_code'] === 'administrator' && $oldStatus === 'active') {
                    $this->assertAnotherActiveAdministrator($userId);
                }
            }

            $update = $this->pdo->prepare(
                "UPDATE users
                 SET status = :status,
                     deactivated_at = CASE WHEN :status_for_date = 'inactive' THEN UTC_TIMESTAMP() ELSE NULL END,
                     failed_login_attempts = 0,
                     locked_until = NULL
                 WHERE id = :id"
            );
            $update->execute([
                'status' => $newStatus,
                'status_for_date' => $newStatus,
                'id' => $userId,
            ]);

            $this->revokeSessions($userId);
            $this->audit->record(
                $newStatus === 'active' ? 'user.activate' : 'user.deactivate',
                'user',
                $userId,
                $actorId,
                null,
                ['status' => $oldStatus],
                ['status' => $newStatus]
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return ['user_id' => $userId, 'status' => $newStatus, 'changed' => true];
    }

    /** @return array<string, mixed>|null */
    private function loadUserForUpdate(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.*, r.id AS role_id, r.code AS role_code, r.name AS role_name
             FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE u.id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function loadRole(string $roleCode): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, code, name
             FROM roles
             WHERE code = :code
               AND is_active = 1
               AND code IN ('reporter','supervisor','director','administrator')
             LIMIT 1"
        );
        $statement->execute(['code' => $roleCode]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $target */
    private function assertRoleCanBeReleased(array $target, ?string $newRoleCode): void
    {
        $currentRole = (string) $target['role_code'];
        $userId = (int) $target['id'];

        if ($currentRole === 'supervisor' && $newRoleCode !== 'supervisor') {
            $locations = $this->pdo->prepare(
                'SELECT COUNT(*) FROM locations WHERE is_active = 1 AND default_supervisor_user_id = :user_id'
            );
            $locations->execute(['user_id' => $userId]);
            if ((int) $locations->fetchColumn() > 0) {
                throw new HttpException(409, 'supervisor_in_use_by_location', 'El supervisor está configurado como responsable de una ubicación activa. Reasigna primero esas ubicaciones.');
            }

            $tickets = $this->pdo->prepare(
                "SELECT COUNT(*)
                 FROM tickets t
                 INNER JOIN ticket_statuses s ON s.id = t.current_status_id
                 WHERE t.supervisor_user_id = :user_id
                   AND s.code NOT IN ('closed','rejected')"
            );
            $tickets->execute(['user_id' => $userId]);
            if ((int) $tickets->fetchColumn() > 0) {
                throw new HttpException(409, 'supervisor_has_active_tickets', 'El supervisor conserva tickets activos. Finaliza o reasigna esos expedientes antes de modificar su rol o estado.');
            }
        }

        if ($currentRole === 'director' && $newRoleCode !== 'director') {
            $requests = $this->pdo->prepare(
                "SELECT COUNT(*)
                 FROM ticket_approval_requests ar
                 INNER JOIN approval_statuses aps ON aps.id = ar.status_id
                 WHERE ar.approver_user_id = :user_id
                   AND aps.code = 'pending'"
            );
            $requests->execute(['user_id' => $userId]);
            if ((int) $requests->fetchColumn() > 0) {
                throw new HttpException(409, 'director_has_pending_authorizations', 'El usuario tiene autorizaciones pendientes. Resuélvelas o reasígnalas antes de modificar su rol o estado.');
            }
        }
    }

    private function assertAnotherActiveAdministrator(int $excludedUserId): void
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE r.code = 'administrator'
               AND r.is_active = 1
               AND u.status = 'active'
               AND u.id <> :excluded_user_id"
        );
        $statement->execute(['excluded_user_id' => $excludedUserId]);
        if ((int) $statement->fetchColumn() < 1) {
            throw new HttpException(409, 'last_administrator_protected', 'La operación dejaría al sistema sin otro Administrator activo.');
        }
    }

    private function revokeSessions(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE user_sessions
             SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
             WHERE user_id = :user_id
               AND revoked_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);
    }

    private function assertInvitationResendLimit(int $userId): void
    {
        $latest = $this->pdo->prepare(
            'SELECT created_at
             FROM user_invitation_tokens
             WHERE user_id = :user_id
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $latest->execute(['user_id' => $userId]);
        $latestAt = $latest->fetchColumn();

        if (is_string($latestAt) && $latestAt !== '') {
            $cooldown = max(1, Env::int('USER_INVITATION_RESEND_COOLDOWN_MINUTES', 10));
            $check = $this->pdo->prepare(
                'SELECT TIMESTAMPDIFF(MINUTE, :created_at, UTC_TIMESTAMP())'
            );
            $check->execute(['created_at' => $latestAt]);
            if ((int) $check->fetchColumn() < $cooldown) {
                throw new HttpException(429, 'invitation_resend_cooldown', "Espera {$cooldown} minutos antes de reenviar otra invitación.");
            }
        }

        $count = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM user_invitation_tokens
             WHERE user_id = :user_id
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)'
        );
        $count->execute(['user_id' => $userId]);
        $max = max(1, Env::int('USER_INVITATION_RESEND_MAX_PER_HOUR', 5));
        if ((int) $count->fetchColumn() >= $max) {
            throw new HttpException(429, 'invitation_resend_hourly_limit', 'Se alcanzó el límite temporal de reenvíos para esta cuenta.');
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function presentUser(array $row): array
    {
        $invitation = null;
        if ($row['invitation_id'] !== null) {
            $invitationStatus = 'expired';
            if ($row['invitation_used_at'] !== null) {
                $invitationStatus = 'used';
            } elseif ($row['invitation_revoked_at'] !== null) {
                $invitationStatus = 'revoked';
            } elseif (strtotime((string) $row['invitation_expires_at'] . ' UTC') > time()) {
                $invitationStatus = 'pending';
            }
            $invitation = [
                'status' => $invitationStatus,
                'created_at' => $row['invitation_created_at'],
                'expires_at' => $row['invitation_expires_at'],
            ];
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
            'deactivated_at' => $row['deactivated_at'],
            'invitation' => $invitation,
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
