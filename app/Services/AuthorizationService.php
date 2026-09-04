<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\HttpException;

final class AuthorizationService
{
    /** @param array<string, mixed> $user */
    public static function hasPermission(array $user, string $permission): bool
    {
        $permissions = $user['permissions'] ?? [];
        return is_array($permissions) && in_array($permission, $permissions, true);
    }

    /** @param array<string, mixed> $user */
    public static function requirePermission(array $user, string $permission): void
    {
        if (!self::hasPermission($user, $permission)) {
            throw new HttpException(403, 'permission_denied', 'No tienes permiso para realizar esta acción.');
        }
    }

    /** @param array<string, mixed> $user */
    public static function requirePasswordChanged(array $user): void
    {
        if ((bool) ($user['must_change_password'] ?? false)) {
            throw new HttpException(
                428,
                'password_change_required',
                'Debes cambiar tu contraseña antes de continuar.'
            );
        }
    }
}
