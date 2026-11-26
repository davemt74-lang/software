<?php
declare(strict_types=1);

/** Native MySQL acceptance. Never run against a populated/application database. */
$dsn = getenv('MUSIC_TEST_MYSQL_DSN') ?: '';
if ($dsn === '') {
    echo "MUSIC_LISTENING_MYSQL_V1=SKIP MUSIC_TEST_MYSQL_DSN is not configured; no MySQL behavior was verified.\n";
    exit(0);
}
if (!str_starts_with($dsn,'mysql:')) throw new RuntimeException('The listening fixture requires a mysql: DSN.');
if (!in_array('mysql',PDO::getAvailableDrivers(),true)) throw new RuntimeException('The listening fixture requires pdo_mysql.');
$pdo = new PDO($dsn,getenv('MUSIC_TEST_MYSQL_USER') ?: '',getenv('MUSIC_TEST_MYSQL_PASSWORD') ?: '',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/\Avp3_music_test[a-zA-Z0-9_]*\z/',$database)) {
    throw new RuntimeException('Refusing listening fixture: database name must start with vp3_music_test.');
}
if ((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() !== 0) {
    throw new RuntimeException('Refusing listening fixture: use an empty disposable test database.');
}

define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-listening-mysql-'.bin2hex(random_bytes(6)));
define('MUSIC_LISTENING_TEST_LIBRARY_ONLY',true);
$GLOBALS['musicSongTestConnection'] = $pdo;
require __DIR__.'/music-listening-v1.php';

try {
    // Every service case runs unchanged against real native prepared statements,
    // InnoDB transactions, BIGINT UNSIGNED history FKs and production new DDL.
    music_listening_test_run();
    $foreignKeys = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN ('music_listening_sessions_v1','music_listening_receipts_v1')")->fetchColumn();
    music_song_test_check($foreignKeys>=2,'MySQL native listening FK constraints were not installed.');
    // PDO MySQL can return integer 0 for a disabled boolean driver attribute.
    // Accept only its two false representations; emulation must remain off.
    music_song_test_check(in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true),'MySQL fixture unexpectedly used emulated statements.');
    echo "MUSIC_LISTENING_MYSQL_V1=PASS native MySQL DDL/unsigned FKs, strict prepares, migration/rerun, timing bounds, exact receipts, sequence/account authority, actual trigger rollback/savepoints, history and asset-version preservation\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // The preflight proved this database empty; all tables now belong to this
    // disposable fixture, including tables created before an assertion failure.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        $tables = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_reverse($tables) as $table) {
            if (!preg_match('/\A[a-zA-Z0-9_]+\z/',$table)) throw new RuntimeException('Unexpected fixture table name.');
            $pdo->exec('DROP TABLE `'.$table.'`');
        }
    } finally { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); }
    music_listening_test_clean_files(STONEFELLOW_ROOT);
}
