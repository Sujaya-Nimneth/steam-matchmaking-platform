<?php
declare(strict_types=1);

class DB
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/config.php';

            try {
                $pdo = new PDO('sqlite:' . $config['db_path'], null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                // SQLite disables referential integrity per connection by
                // default, which silently skips every ON DELETE CASCADE.
                $pdo->exec('PRAGMA foreign_keys = ON');
                $pdo->exec('PRAGMA journal_mode = WAL');
                $pdo->exec('PRAGMA busy_timeout = 5000');
                $pdo->exec('PRAGMA synchronous = NORMAL');

                self::$instance = $pdo;
            } catch (PDOException $e) {
                // Infrastructure throws; the web entry point's global error
                // handler turns this into a JSON 500 and CLI callers can
                // catch it themselves.
                throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
            }
        }

        return self::$instance;
    }
}
