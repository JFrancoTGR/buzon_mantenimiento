<?php

declare (strict_types = 1);

namespace App\Services;

use App\Exceptions\HttpException;

final class AuthorizationService
{
    /**
     * Checks a permission that is valid globally, or at one
     * explicit Maintenance Location.
     *
     * With no Location, territorial grants are intentionally
     * ignored. This prevents a scoped permission from becoming
     * global accidentally.
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

            $grantPermissions =
            $grant['permissions'] ?? [];

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
     * Checks whether the user has a permission either globally
     * or in at least one active territorial grant.
     *
     * Use this only for feature availability / route gating.
     * Resource authorization must use hasPermission() with the
     * resource Location.
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
            $grantPermissions =
            $grant['permissions'] ?? [];

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
     * Returns the territorial Location IDs where the user has
     * the requested permission.
     *
     * A GLOBAL permission is intentionally not represented here.
     * Check hasPermission($user, $permission) first when the
     * distinction matters.
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

            $grantPermissions =
            $grant['permissions'] ?? [];

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
     * Route/feature-level gate. It proves that the permission
     * exists somewhere, not that it applies to a particular
     * ticket or Location.
     *
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
