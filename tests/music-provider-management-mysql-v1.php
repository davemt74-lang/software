<?php
declare(strict_types=1);

/** Guarded real MySQL execution; absence of a test DSN is explicitly not a pass. */
$dsn=getenv('MUSIC_TEST_MYSQL_DSN')?:'';
if($dsn===''){echo "MUSIC_PROVIDER_MANAGEMENT_MYSQL_V1=SKIP MUSIC_TEST_MYSQL_DSN is not configured; MySQL was not verified.\n";exit(0);}
if(!str_starts_with($dsn,'mysql:')||!in_array('mysql',PDO::getAvailableDrivers(),true))throw new RuntimeException('Use a MySQL DSN and native pdo_mysql.');
$pdo=new PDO($dsn,getenv('MUSIC_TEST_MYSQL_USER')?:'',getenv('MUSIC_TEST_MYSQL_PASSWORD')?:'',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,
]);
$database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if(!preg_match('/\Avp3_music_test[a-zA-Z0-9_]*\z/',$database))throw new RuntimeException('Refusing fixture: database name must start with vp3_music_test.');
if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0)throw new RuntimeException('Refusing fixture: database must be empty and disposable.');
define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-provider-management-mysql-'.bin2hex(random_bytes(6)));
define('MUSIC_PROVIDER_MANAGEMENT_TEST_LIBRARY_ONLY',true);
$GLOBALS['musicSongTestConnection']=$pdo;
require __DIR__.'/music-provider-management-v1.php';
try{
    music_provider_management_test_run();
    music_provider_management_test_equal(in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true),true,'Native prepared statements disabled');
    music_provider_management_test_equal(music_provider_v1_constraints_ready($pdo),true,'Provider unique/FK constraints are absent');
    echo "MUSIC_PROVIDER_MANAGEMENT_MYSQL_V1=PASS native MySQL schema/constraints, current workspace authority, verified origins, rotation, selection, immutable receipts and actual transaction rollback\n";
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try{
        $tables=$pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        foreach(array_reverse($tables) as $table){if(!preg_match('/\A[a-zA-Z0-9_]+\z/',$table))throw new RuntimeException('Unexpected fixture table.');$pdo->exec('DROP TABLE `'.$table.'`');}
    }finally{$pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
    music_listening_test_clean_files(STONEFELLOW_ROOT);
}
