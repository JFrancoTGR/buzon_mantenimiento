<?php

declare(strict_types=1);

namespace EUTools\Shared\Security;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        $token = $_SESSION[self::SESSION_KEY] ?? null;

        if (
            !is_string($token)
            || strlen($token) < 32
        ) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    /** @param array<string, mixed> $input */
    public static function validate(
        array $input = []
    ): void {
        $provided =
            $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? ($input['csrf_token'] ?? null);

        $expected =
            $_SESSION[self::SESSION_KEY]
            ?? null;

        if (
            !is_string($provided)
            || !is_string($expected)
            || !hash_equals($expected, $provided)
        ) {
            throw new CsrfException(
                419,
                'csrf_invalid',
                'El token de seguridad es inválido o expiró.'
            );
        }
    }

    public static function rotate(): string
    {
        $_SESSION[self::SESSION_KEY] =
            bin2hex(random_bytes(32));

        return $_SESSION[self::SESSION_KEY];
    }
}