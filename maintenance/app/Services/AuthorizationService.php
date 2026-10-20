<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\HttpException;

final class AuthorizationService
{
    private const TICKET_VIEW_PERMISSIONS = [
        'ticket.view.all',
        'ticket.view.location',
        'ticket.view.assigned',
        'ticket.view.own',
    ];

    /**
     * Checks a permission globally or at one explicit Maintenance Location.
     *
     * Territorial permissions never become global implicitly.
     *
     * @param array<string, mixed> $user
     */
    public static function hasPermission(
        array $user,
        string $permission,
        ?int $locationId = null
    ): bool {
        $permissions = $user['permissions'] ?? [];

        if (
            is_array($permissions)
            && in_array($permission, $permissions, true)
        ) {
            return true;
        }

        if ($locationId === null || $locationId < 1) {
            return false;
        }

        foreach (self::grants($user) as $grant) {
            if ((bool) ($grant['is_global'] ?? false)) {
                continue;
            }

            $location = $grant['location'] ?? null;

            if (
                ! is_array($location)
                || (int) ($location['id'] ?? 0) !== $locationId
            ) {
                continue;
            }

            $grantPermissions = $grant['permissions'] ?? [];

            return is_array($grantPermissions)
                && in_array(
                    $permission,
                    $grantPermissions,
                    true
                );
        }

        return false;
    }

    /**
     * Checks whether a permission exists globally or in at least one
     * active territorial grant.
     *
     * Use this only for feature / route availability. Authorization of a
     * concrete resource must use hasPermission() with its Location.
     *
     * @param array<string, mixed> $user
     */
    public static function hasAnyPermission(
        array $user,
        string $permission
    ): bool {
        if (self::hasPermission($user, $permission)) {
            return true;
        }

        foreach (self::grants($user) as $grant) {
            $grantPermissions = $grant['permissions'] ?? [];

            if (
                is_array($grantPermissions)
                && in_array(
                    $permission,
                    $grantPermissions,
                    true
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns territorial Location IDs where a permission exists.
     *
     * GLOBAL permissions are intentionally not represented here.
     *
     * @param array<string, mixed> $user
     * @return array<int, int>
     */
    public static function locationIdsForPermission(
        array $user,
        string $permission
    ): array {
        $locationIds = [];

        foreach (self::grants($user) as $grant) {
            if ((bool) ($grant['is_global'] ?? false)) {
                continue;
            }

            $grantPermissions = $grant['permissions'] ?? [];

            if (
                ! is_array($grantPermissions)
                || ! in_array(
                    $permission,
                    $grantPermissions,
                    true
                )
            ) {
                continue;
            }

            $location = $grant['location'] ?? null;

            if (! is_array($location)) {
                continue;
            }

            $locationId = (int) ($location['id'] ?? 0);

            if ($locationId > 0) {
                $locationIds[$locationId] = $locationId;
            }
        }

        return array_values($locationIds);
    }

    /**
     * Returns territorial Location IDs where one role is active.
     *
     * GLOBAL grants are intentionally not expanded to every Location.
     * Current Maintenance invariants reserve GLOBAL for Administrator.
     *
     * @param array<string, mixed> $user
     * @return array<int, int>
     */
    public static function locationIdsForRole(
        array $user,
        string $roleCode
    ): array {
        $roleCode = trim($roleCode);

        if ($roleCode === '') {
            return [];
        }

        $locationIds = [];

        foreach (self::grants($user) as $grant) {
            if ((bool) ($grant['is_global'] ?? false)) {
                continue;
            }

            $role = $grant['role'] ?? null;

            if (
                ! is_array($role)
                || (string) ($role['code'] ?? '') !== $roleCode
            ) {
                continue;
            }

            $location = $grant['location'] ?? null;

            if (! is_array($location)) {
                continue;
            }

            $locationId = (int) ($location['id'] ?? 0);

            if ($locationId > 0) {
                $locationIds[$locationId] = $locationId;
            }
        }

        ksort($locationIds);

        return array_values($locationIds);
    }

    /**
     * Returns the effective Maintenance role at one Location.
     *
     * A GLOBAL grant applies to every Location.
     *
     * @param array<string, mixed> $user
     */
    public static function roleCodeAtLocation(
        array $user,
        int $locationId
    ): ?string {
        if ($locationId < 1) {
            return null;
        }

        foreach (self::grants($user) as $grant) {
            $role = $grant['role'] ?? null;

            if (! is_array($role)) {
                continue;
            }

            $roleCode = trim(
                (string) ($role['code'] ?? '')
            );

            if ($roleCode === '') {
                continue;
            }

            if ((bool) ($grant['is_global'] ?? false)) {
                return $roleCode;
            }

            $location = $grant['location'] ?? null;

            if (
                is_array($location)
                && (int) ($location['id'] ?? 0) === $locationId
            ) {
                return $roleCode;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function hasRoleAtLocation(
        array $user,
        string $roleCode,
        int $locationId
    ): bool {
        return self::roleCodeAtLocation(
            $user,
            $locationId
        ) === $roleCode;
    }

    /**
     * Resource-level ticket visibility.
     *
     * Ownership and assignment never bypass Location.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $ticket
     */
    public static function canViewTicket(
        array $user,
        array $ticket
    ): bool {
        $locationId = (int) ($ticket['location_id'] ?? 0);
        $userId = (int) ($user['id'] ?? 0);

        if ($locationId < 1 || $userId < 1) {
            return false;
        }

        if (
            self::hasPermission(
                $user,
                'ticket.view.all',
                $locationId
            )
            || self::hasPermission(
                $user,
                'ticket.view.location',
                $locationId
            )
        ) {
            return true;
        }

        if (
            self::hasPermission(
                $user,
                'ticket.view.own',
                $locationId
            )
            && (int) ($ticket['reported_by_user_id'] ?? 0)
                === $userId
        ) {
            return true;
        }

        if (
            self::hasPermission(
                $user,
                'ticket.view.assigned',
                $locationId
            )
            && in_array(
                $userId,
                [
                    (int) ($ticket['operator_user_id'] ?? 0),
                    (int) ($ticket['approver_user_id'] ?? 0),
                    (int) ($ticket['action_owner_user_id'] ?? 0),
                ],
                true
            )
        ) {
            return true;
        }

        return false;
    }

    /**
     * Route / feature-level ticket-view gate.
     *
     * @param array<string, mixed> $user
     */
    public static function hasAnyTicketViewPermission(
        array $user
    ): bool {
        foreach (self::TICKET_VIEW_PERMISSIONS as $permission) {
            if (self::hasAnyPermission($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns all territorial Locations participating in the user's
     * ticket-view scope.
     *
     * NULL means at least one view permission is GLOBAL.
     *
     * @param array<string, mixed> $user
     * @return array<int, int>|null
     */
    public static function ticketViewLocationIds(
        array $user
    ): ?array {
        foreach (self::TICKET_VIEW_PERMISSIONS as $permission) {
            if (self::hasPermission($user, $permission)) {
                return null;
            }
        }

        $locationIds = [];

        foreach (self::TICKET_VIEW_PERMISSIONS as $permission) {
            foreach (
                self::locationIdsForPermission(
                    $user,
                    $permission
                ) as $locationId
            ) {
                $locationIds[$locationId] = $locationId;
            }
        }

        ksort($locationIds);

        return array_values($locationIds);
    }

    /**
     * Builds a SQL predicate equivalent to canViewTicket().
     *
     * It is intended for ticket collections such as Dashboard and
     * OperatorService.
     *
     * @param array<string, mixed> $user
     * @return array{0:string,1:array<string, int|string>}
     */
    public static function ticketVisibilityScope(
        array $user,
        string $alias,
        string $prefix
    ): array {
        $userId = (int) ($user['id'] ?? 0);

        if ($userId < 1) {
            return ['0 = 1', []];
        }

        if (self::hasPermission($user, 'ticket.view.all')) {
            return ['1 = 1', []];
        }

        if (self::hasPermission($user, 'ticket.view.location')) {
            return ['1 = 1', []];
        }

        $clauses = [];
        $params = [];

        $fullLocationIds = array_values(
            array_unique(
                array_merge(
                    self::locationIdsForPermission(
                        $user,
                        'ticket.view.all'
                    ),
                    self::locationIdsForPermission(
                        $user,
                        'ticket.view.location'
                    )
                )
            )
        );

        if ($fullLocationIds !== []) {
            $clauses[] = self::locationInClause(
                $alias,
                $fullLocationIds,
                $prefix . '_location_view',
                $params
            );
        }

        if (self::hasPermission($user, 'ticket.view.own')) {
            $reporter = $prefix . '_reporter_global';

            $clauses[] = sprintf(
                '%s.reported_by_user_id = :%s',
                $alias,
                $reporter
            );

            $params[$reporter] = $userId;
        } else {
            $ownLocationIds = self::locationIdsForPermission(
                $user,
                'ticket.view.own'
            );

            if ($ownLocationIds !== []) {
                $reporter = $prefix . '_reporter_scoped';

                $locationClause = self::locationInClause(
                    $alias,
                    $ownLocationIds,
                    $prefix . '_own_location',
                    $params
                );

                $clauses[] = sprintf(
                    '(%1$s.reported_by_user_id = :%2$s AND %3$s)',
                    $alias,
                    $reporter,
                    $locationClause
                );

                $params[$reporter] = $userId;
            }
        }

        $assignedCondition = static function (
            string $alias,
            string $prefix,
            int $userId,
            array &$params
        ): string {
            $operator = $prefix . '_operator';
            $approver = $prefix . '_approver';
            $owner = $prefix . '_owner';

            $params[$operator] = $userId;
            $params[$approver] = $userId;
            $params[$owner] = $userId;

            return sprintf(
                '(%1$s.operator_user_id = :%2$s'
                . ' OR %1$s.approver_user_id = :%3$s'
                . ' OR %1$s.action_owner_user_id = :%4$s)',
                $alias,
                $operator,
                $approver,
                $owner
            );
        };

        if (self::hasPermission($user, 'ticket.view.assigned')) {
            $clauses[] = $assignedCondition(
                $alias,
                $prefix . '_assigned_global',
                $userId,
                $params
            );
        } else {
            $assignedLocationIds =
                self::locationIdsForPermission(
                    $user,
                    'ticket.view.assigned'
                );

            if ($assignedLocationIds !== []) {
                $assigned = $assignedCondition(
                    $alias,
                    $prefix . '_assigned_scoped',
                    $userId,
                    $params
                );

                $locationClause = self::locationInClause(
                    $alias,
                    $assignedLocationIds,
                    $prefix . '_assigned_location',
                    $params
                );

                $clauses[] = sprintf(
                    '(%s AND %s)',
                    $assigned,
                    $locationClause
                );
            }
        }

        if ($clauses === []) {
            return ['0 = 1', []];
        }

        return [
            '(' . implode(' OR ', $clauses) . ')',
            $params,
        ];
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function requirePermission(
        array $user,
        string $permission,
        ?int $locationId = null
    ): void {
        if (
            self::hasPermission(
                $user,
                $permission,
                $locationId
            )
        ) {
            return;
        }

        throw new HttpException(
            403,
            'permission_denied',
            'No tienes permiso para realizar esta acción.'
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function requireAnyPermission(
        array $user,
        string $permission
    ): void {
        if (self::hasAnyPermission($user, $permission)) {
            return;
        }

        throw new HttpException(
            403,
            'permission_denied',
            'No tienes permiso para realizar esta acción.'
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function requireTicketView(
        array $user
    ): void {
        if (self::hasAnyTicketViewPermission($user)) {
            return;
        }

        throw new HttpException(
            403,
            'permission_denied',
            'No tienes permiso para consultar tickets.'
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $ticket
     */
    public static function requireTicketAccess(
        array $user,
        array $ticket
    ): void {
        if (self::canViewTicket($user, $ticket)) {
            return;
        }

        throw new HttpException(
            403,
            'permission_denied',
            'No tienes permiso para consultar este ticket.'
        );
    }

    /** @param array<string, mixed> $user */
    public static function requirePasswordChanged(
        array $user
    ): void {
        if ((bool) ($user['must_change_password'] ?? false)) {
            throw new HttpException(
                428,
                'password_change_required',
                'Debes cambiar tu contraseña antes de continuar.'
            );
        }
    }

    /**
     * @param array<int, int> $locationIds
     * @param array<string, int|string> $params
     */
    private static function locationInClause(
        string $alias,
        array $locationIds,
        string $prefix,
        array &$params
    ): string {
        $placeholders = [];

        foreach (
            array_values(array_unique($locationIds))
            as $index => $locationId
        ) {
            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (int) $locationId;
        }

        if ($placeholders === []) {
            return '0 = 1';
        }

        return sprintf(
            '%s.location_id IN (%s)',
            $alias,
            implode(', ', $placeholders)
        );
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    private static function grants(array $user): array
    {
        $grants = $user['grants'] ?? [];

        if (! is_array($grants)) {
            return [];
        }

        return array_values(
            array_filter(
                $grants,
                'is_array'
            )
        );
    }
}
