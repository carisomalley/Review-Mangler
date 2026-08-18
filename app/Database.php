<?php

namespace App;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $host = Env::require('DB_HOST');
            $name = Env::require('DB_NAME');
            $user = Env::require('DB_USER');
            $pass = Env::get('DB_PASS', '');

            $dsn = "mysql:host=$host;dbname=$name;charset=utf8mb4";
            try {
                self::$pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                // Never leak DSN/credentials in the error surfaced to a browser.
                error_log('DB connection failed: ' . $e->getMessage());
                throw new \RuntimeException('Database connection failed. Check the server error log.');
            }
        }
        return self::$pdo;
    }
}
