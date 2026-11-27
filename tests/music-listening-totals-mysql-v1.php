<?php
declare(strict_types=1);
/** Run the same aggregate/snapshot/large-history acceptance suite with native MySQL prepares. */
$dsn=getenv('MUSIC_TEST_MYSQL_DSN')?:'';
if($dsn===''){echo "MUSIC_LISTENING_TOTALS_MYSQL_V1=SKIP MUSIC_TEST_MYSQL_DSN is not configured; no MySQL behavior was verified.\n";exit(0);}
if(!str_starts_with($dsn,'mysql:')||!in_array('mysql',PDO::getAvailableDrivers(),true))throw new RuntimeException('Native MySQL PDO is required.');
$pdo=new PDO($dsn,getenv('MUSIC_TEST_MYSQL_USER')?:'',getenv('MUSIC_TEST_MYSQL_PASSWORD')?:'',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,
]);
$database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if(!preg_match('/^vp3_music_test[a-zA-Z0-9_]*$/D',$database))throw new RuntimeException('Use an isolated vp3_music_test database.');
if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0)throw new RuntimeException('Use an empty disposable database.');
define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-listening-totals-mysql-'.bin2hex(random_bytes(6)));
define('MUSIC_LISTENING_TOTALS_TEST_LIBRARY_ONLY',true);$GLOBALS['musicSongTestConnection']=$pdo;
require __DIR__.'/music-listening-totals-v1.php';
try{music_listening_totals_test_run();echo "MUSIC_LISTENING_TOTALS_MYSQL_V1=PASS native prepared statements, exact DECIMAL aggregates, read-only snapshots and large retained history\n";}
finally{
    unset($GLOBALS['musicAnalyticsQueryHook']);if($pdo->inTransaction())$pdo->rollBack();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try{foreach($pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN) as $table){
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Invalid fixture table.');$pdo->exec('DROP TABLE `'.$table.'`');
    }}finally{$pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
    music_listening_test_clean_files(STONEFELLOW_ROOT);
}
