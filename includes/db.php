<?php
/**
 * Central PDO database connection.
 * Every file that needs the DB should require this (usually indirectly via
 * includes/auth.php, which already requires it) and call getDbConnection().
 */

require_once __DIR__ . '/config.php';

function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . (defined('DB_PORT') ? DB_PORT : 3306) . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    return $pdo;
}
