<?php
declare(strict_types=1);

/** Request-local positive metadata only. Missing columns/tables remain retryable after DDL. */
function schema_metadata_state(PDO $pdo): object
{
    static $connections;
    $connections ??= new WeakMap();
    return $connections[$pdo] ??= (object)['tables'=>[], 'columns'=>[]];
}

function schema_metadata_table_exists(PDO $pdo, string $table): bool
{
    $state = schema_metadata_state($pdo);
    if (isset($state->tables[$table])) return true;
    try {
        $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "SELECT name FROM sqlite_master WHERE type='table' AND name=?"
            : 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$table]);
        if ($stmt->fetchColumn() !== false) return $state->tables[$table] = true;
    } catch (Throwable $e) {}
    return false;
}

function schema_metadata_column_exists(PDO $pdo, string $table, string $column): bool
{
    $state = schema_metadata_state($pdo);
    if (isset($state->columns[$table][$column])) return true;
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $rows = $pdo->query('PRAGMA table_info("'.str_replace('"','""',$table).'")')->fetchAll(PDO::FETCH_ASSOC);
            $columns = array_column($rows, 'name');
        } else {
            $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $stmt->execute([$table]);
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        foreach ($columns as $name) $state->columns[$table][(string)$name] = true;
        return isset($state->columns[$table][$column]);
    } catch (Throwable $e) { return false; }
}
