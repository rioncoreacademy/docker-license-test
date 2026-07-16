<?php

class Database
{
    private static ?PDO $connection = null;

    public static function get(): PDO
    {
        if (self::$connection === null) {
            // Shared hosts (e.g. Hostinger) often can't set process env vars per-app,
            // so config.php (gitignored) is the fallback source of credentials there.
            $configFile = __DIR__ . '/../config.php';
            if (is_file($configFile)) {
                require_once $configFile;
            }

            $host = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : 'db');
            $name = getenv('DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : 'licensing');
            $user = getenv('DB_USER') ?: (defined('DB_USER') ? DB_USER : 'license_app');
            $pass = getenv('DB_PASS') ?: (defined('DB_PASS') ? DB_PASS : '');

            $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
            self::$connection = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        return self::$connection;
    }
}
