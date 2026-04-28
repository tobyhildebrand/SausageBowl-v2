<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database – PDO singleton.
 *
 * Usage:
 *   $pdo = DB::get();
 *   $stmt = $pdo->prepare('SELECT * FROM teams WHERE id = ?');
 *   $stmt->execute([$id]);
 */
class DB
{
    private static ?PDO $pdo = null;

    /** Returns the shared PDO connection, creating it on first call. */
    public static function get(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect();
        }

        return self::$pdo;
    }

    private static function connect(): PDO
    {
        $config = self::loadConfig();
        $db     = $config['db'];

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['name'],
            $db['charset']
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            return new PDO($dsn, $db['user'], $db['pass'], $options);
        } catch (PDOException $e) {
            // Do not expose credentials in the error message.
            throw new RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }

    private static function loadConfig(): array
    {
        $path = dirname(__DIR__, 2) . '/config/config.php';

        if (!file_exists($path)) {
            throw new RuntimeException(
                'config/config.php not found. Copy config/config.example.php and fill in your values.'
            );
        }

        return require $path;
    }

    // Prevent instantiation.
    private function __construct() {}
    private function __clone() {}
}
