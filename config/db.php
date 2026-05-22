<?php
// config/db.php
class DB {
    private static $instance = null;

    public static function getConnection() {
        if (self::$instance === null) {
            $config = require __DIR__ . '/config.php';
            try {
                self::$instance = new PDO('sqlite:' . $config['db_path']);
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                die("Database connection failed: " . $e->getMessage());
            }
        }
        return self::$instance;
    }
}
