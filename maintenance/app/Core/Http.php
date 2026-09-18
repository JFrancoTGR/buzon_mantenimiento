<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\HttpException;
use JsonException;

final class Http
{
    public static function requireMethod(string $method): void
    {
        $actual = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($actual !== strtoupper($method)) {
            throw new HttpException(405, 'method_not_allowed', 'Método HTTP no permitido.');
        }
    }

    /** @return array<string, mixed> */
    public static function jsonInput(int $maxBytes = 65536): array
    {
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > $maxBytes) {
            throw new HttpException(413, 'payload_too_large', 'El cuerpo de la solicitud es demasiado grande.');
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'invalid_json', 'El cuerpo JSON no es válido.');
        }

        if (!is_array($decoded)) {
            throw new HttpException(400, 'invalid_json', 'El cuerpo JSON debe ser un objeto.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : null;
    }

    public static function userAgent(): ?string
    {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return is_string($agent) && $agent !== '' ? substr($agent, 0, 500) : null;
    }

    public static function requestId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    public static function applySecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }
}
