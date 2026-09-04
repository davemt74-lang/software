<?php
declare(strict_types=1);

function db(): ?PDO
{
    static $pdo = false;

    if ($pdo !== false) {
        return $pdo instanceof PDO ? $pdo : null;
    }

    global $config;
    $dsn = trim((string)($config['db']['dsn'] ?? ''));
    if ($dsn === '') {
        $pdo = null;
        return null;
    }

    try {
        $pdo = new PDO(
            $dsn,
            (string)($config['db']['user'] ?? ''),
            (string)($config['db']['pass'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        return $pdo;
    } catch (Throwable $e) {
        error_log('Stonefellow DB connection failed: ' . $e->getMessage());
        $pdo = null;
        return null;
    }
}

function db_ready(): bool
{
    $pdo = db();
    if (!$pdo) {
        return false;
    }

    try {
        $pdo->query('SELECT 1 FROM settings LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
