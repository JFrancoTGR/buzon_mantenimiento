<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException("No se encontró el archivo de entorno: {$path}");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException("No fue posible leer el archivo de entorno: {$path}");
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if ($key === '') {
                continue;
            }

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            self::$values[$key] = $value;
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    public static function get(string $key, ?string $default = null): string
    {
        $value = self::$values[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            if ($default !== null) {
                return $default;
            }

            throw new RuntimeException("Falta la variable de entorno requerida: {$key}");
        }

        return (string) $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::$values[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException("La variable {$key} debe ser un entero.");
        }

        return (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::$values[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));
        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new RuntimeException("La variable {$key} debe ser booleana."),
        };
    }
}
