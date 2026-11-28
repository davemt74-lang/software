<?php
declare(strict_types=1);
require_once __DIR__.'/music-preferences-v1.php';
$dsn=getenv('MUSIC_TEST_MYSQL_DSN')?:'';
if($dsn===''){echo "SKIP: MUSIC_TEST_MYSQL_DSN is unset; native MySQL preference acceptance was not run.\n";exit(0);}
$connection=['dsn'=>$dsn,'user'=>getenv('MUSIC_TEST_MYSQL_USER')?:'root','password'=>getenv('MUSIC_TEST_MYSQL_PASSWORD')?:''];
$pdo=new MusicPreferencesTestPDO($dsn,$connection['user'],$connection['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='vp3_music_test'){
    throw new RuntimeException('Use the dedicated disposable vp3_music_test database.');
}
if($pdo->query('SHOW TABLES')->fetchColumn()!==false){
    if(getenv('MUSIC_PREFERENCES_RESET_TEST_DB')!=='1')throw new RuntimeException('A fresh database or explicit dedicated-fixture reset is required.');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try{foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
        if(!preg_match('/\A[a-zA-Z0-9_]+\z/D',$table))throw new RuntimeException('Unexpected fixture table.');
        $pdo->exec('DROP TABLE `'.$table.'`');
    }}finally{$pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
}
$count=music_preferences_suite_v1($pdo,$connection);
music_preferences_assert_v1(in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true),'native prepares remain enabled');
echo 'Music preferences native MySQL (real prepared statements and independent request races): '.($count+1)." checks passed.\n";
