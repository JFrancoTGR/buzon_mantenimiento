<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\Env;

final class SessionManager
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetimeSeconds = Env::int('SESSION_LIFETIME_MINUTES', 30) * 60;
        $secure = Env::bool('SESSION_SECURE_COOKIE', true);
        $sameSite = Env::get('SESSION_SAME_SITE', 'Lax');
        $cookiePath = Env::get('SESSION_COOKIE_PATH', '/');

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $secure ? '1' : '0');
        ini_set('session.gc_maxlifetime', (string) $lifetimeSeconds);
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');

        session_name(Env::get('SESSION_NAME', 'EUTOOLSSESSID'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath,
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);

        session_start();

        if (!isset($_SESSION['_started_at'])) {
            $_SESSION['_started_at'] = time();
            $_SESSION['_last_regenerated_at'] = time();
        }

        Csrf::token();
    }

    public static function establish(int $userId, int $databaseSessionId): void
    {
        $_SESSION['auth'] = [
            'user_id' => $userId,
            'database_session_id' => $databaseSessionId,
            'authenticated_at' => time(),
        ];
        $_SESSION['_last_regenerated_at'] = time();
        Csrf::rotate();
    }

    /** @return array{user_id:int,database_session_id:int,authenticated_at:int}|null */
    public static function authData(): ?array
    {
        $auth = $_SESSION['auth'] ?? null;
        if (!is_array($auth)) {
            return null;
        }

        if (!isset($auth['user_id'], $auth['database_session_id'], $auth['authenticated_at'])) {
            return null;
        }

        return [
            'user_id' => (int) $auth['user_id'],
            'database_session_id' => (int) $auth['database_session_id'],
            'authenticated_at' => (int) $auth['authenticated_at'],
        ];
    }

    public static function sessionHash(): string
    {
        return hash('sha256', session_id());
    }

    public static function destroyLocal(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }

        session_destroy();
    }
}
