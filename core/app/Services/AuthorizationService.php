<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\HttpException;

final class AuthorizationService
{
    /** @param array<string, mixed> $actor */
    public static function hasPermission(
        array $actor,
        string $applicationCode,
        string $permissionCode
    ): bool {
        $applications = $actor['applications'] ?? [];

        if (!is_array($applications)) {
            return false;
        }

        foreach ($applications as $application) {
            if (!is_array($application)) {
                continue;
            }

            if ((string) ($application['code'] ?? '') !== $applicationCode) {
                continue;
            }

            $permissions = $application['permissions'] ?? [];

            return is_array($permissions)
                && in_array($permissionCode, $permissions, true);
        }

        return false;
    }

    /** @param array<string, mixed> $actor */
    public static function requirePermission(
        array $actor,
        string $applicationCode,
        string $permissionCode
    ): void {
        if (self::hasPermission(
            $actor,
            $applicationCode,
            $permissionCode
        )) {
            return;
        }

        throw new HttpException(
            403,
            'permission_denied',
            'No tienes permisos suficientes para realizar esta operación.'
        );
    }
}