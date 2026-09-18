<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\Env;
use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = Env::get('DB_HOST');
        $port = Env::int('DB_PORT', 3306);
        $database = Env::get('DB_NAME');
        $charset = Env::get('DB_CHARSET', 'utf8mb4');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset
        );

        try {
            self::$connection = new PDO(
                $dsn,
                Env::get('DB_USER'),
                Env::get('DB_PASS'),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]
            );

            self::$connection->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            self::$connection->exec("SET time_zone = '+00:00'");
        } catch (PDOException $exception) {
            throw new RuntimeException('No fue posible conectar con MariaDB.', 0, $exception);
        }

        return self::$connection;
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
