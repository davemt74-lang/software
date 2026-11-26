<?php
declare(strict_types=1);

/** Never run this acceptance suite against an application/populated database. */
$dsn=getenv('MUSIC_TEST_MYSQL_DSN')?:'';
if($dsn===''){
    echo "MUSIC_PROVIDER_PLAYBACK_MYSQL_V1=SKIP MUSIC_TEST_MYSQL_DSN is not configured; no MySQL behavior was verified.\n";
    exit(0);
}
if(!str_starts_with($dsn,'mysql:')||!in_array('mysql',PDO::getAvailableDrivers(),true))throw new RuntimeException('Provider MySQL acceptance requires pdo_mysql and a mysql: DSN.');
$pdo=new PDO($dsn,getenv('MUSIC_TEST_MYSQL_USER')?:'',getenv('MUSIC_TEST_MYSQL_PASSWORD')?:'',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,
]);
$database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if(!preg_match('/\Avp3_music_test[a-zA-Z0-9_]*\z/',$database))throw new RuntimeException('Refusing provider fixture: database name must start with vp3_music_test.');
if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0)throw new RuntimeException('Refusing provider fixture: use an empty disposable test database.');
define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-provider-playback-mysql-'.bin2hex(random_bytes(6)));
define('MUSIC_PROVIDER_PLAYBACK_TEST_LIBRARY_ONLY',true);
$GLOBALS['musicSongTestConnection']=$pdo;
require __DIR__.'/music-provider-playback-v1.php';

try{
    provider_playback_test_run();
    provider_playback_test_equal(in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true),true,'Provider acceptance used emulated statements');
    provider_playback_test_equal(music_provider_v1_constraints_ready($pdo),true,'Native provider unique/FK integrity');
    echo "MUSIC_PROVIDER_PLAYBACK_MYSQL_V1=PASS native prepares and InnoDB constraints, migration, anonymous exact receipts, real trigger rollback/savepoints, pinned audio versions, range/usage quotas, pause timing and revocation\n";
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    // The preflight proved this disposable database empty before this test.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try{
        $tables=$pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        foreach(array_reverse($tables) as $table){
            if(!preg_match('/\A[a-zA-Z0-9_]+\z/',$table))throw new RuntimeException('Unexpected fixture table name.');
            $pdo->exec('DROP TABLE `'.$table.'`');
        }
    }finally{$pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
    music_listening_test_clean_files(STONEFELLOW_ROOT);
}
