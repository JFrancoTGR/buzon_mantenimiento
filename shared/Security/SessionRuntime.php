<?php

declare(strict_types=1);

namespace EUTools\Shared\Security;

final class SessionRuntime
{
    /**
     * @param array{
     *   name:string,
     *   lifetime_minutes:int,
     *   secure_cookie:bool,
     *   same_site:string,
     *   cookie_path:string
     * } $config
     */
    public static function start(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetimeSeconds =
            max(1, $config['lifetime_minutes']) * 60;

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set(
            'session.cookie_secure',
            $config['secure_cookie'] ? '1' : '0'
        );
        ini_set(
            'session.gc_maxlifetime',
            (string) $lifetimeSeconds
        );
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');

        session_name($config['name']);

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $config['cookie_path'],
            'domain' => '',
            'secure' => $config['secure_cookie'],
            'httponly' => true,
            'samesite' => $config['same_site'],
        ]);

        session_start();

        if (!isset($_SESSION['_started_at'])) {
            $_SESSION['_started_at'] = time();
            $_SESSION['_last_regenerated_at'] = time();
        }
    }

    public static function establish(
        int $userId,
        int $databaseSessionId
    ): void {
        $_SESSION['auth'] = [
            'user_id' => $userId,
            'database_session_id' => $databaseSessionId,
            'authenticated_at' => time(),
        ];

        $_SESSION['_last_regenerated_at'] = time();
    }

    /**
     * @return array{
     *   user_id:int,
     *   database_session_id:int,
     *   authenticated_at:int
     * }|null
     */
    public static function authData(): ?array
    {
        $auth = $_SESSION['auth'] ?? null;

        if (!is_array($auth)) {
            return null;
        }

        if (
            !isset(
                $auth['user_id'],
                $auth['database_session_id'],
                $auth['authenticated_at']
            )
        ) {
            return null;
        }

        return [
            'user_id' => (int) $auth['user_id'],
            'database_session_id' =>
                (int) $auth['database_session_id'],
            'authenticated_at' =>
                (int) $auth['authenticated_at'],
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
