<?php
declare(strict_types=1);
require_once __DIR__.'/music-history-v1.php';
$dsn=getenv('MUSIC_TEST_MYSQL_DSN')?:'';
if($dsn===''){echo "SKIP: MUSIC_TEST_MYSQL_DSN is unset; native MySQL history acceptance was not run.\n";exit(0);}
$pdo=new MusicHistoryTestPDO($dsn,getenv('MUSIC_TEST_MYSQL_USER')?:'root',getenv('MUSIC_TEST_MYSQL_PASSWORD')?:'',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
]);
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||!preg_match('/\Avp3_music_test[a-zA-Z0-9_]*\z/',(string)$pdo->query('SELECT DATABASE()')->fetchColumn())){
    throw new RuntimeException('Use an empty disposable database named vp3_music_test or with that prefix.');
}
if($pdo->query('SHOW TABLES')->fetchColumn()!==false){
    if(getenv('MUSIC_HISTORY_RESET_TEST_DB')!=='1'||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='vp3_music_test'){
        throw new RuntimeException('Native MySQL history acceptance requires a fresh disposable database; the shared CI fixture reset must be explicitly enabled.');
    }
    // The dedicated CI database is deliberately reused after MC1. This explicit
    // fixture-only reset is never reachable from a service or application request.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try{
        foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
            if(!preg_match('/\A[a-zA-Z0-9_]+\z/D',$table))throw new RuntimeException('Unexpected fixture table name.');
            $pdo->exec('DROP TABLE `'.$table.'`');
        }
    }finally{$pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
}
// Native DATETIME receipt interpretation must not depend on the SQL connection timezone.
$pdo->exec("SET time_zone='+05:30'");
$count=music_history_suite_v1($pdo);
music_history_assert_v1(in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true),'native prepares remain enabled');
echo 'Music history native MySQL (real prepared statements, non-UTC SQL session): '.($count+1)." checks passed.\n";
