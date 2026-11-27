<?php
declare(strict_types=1);
require_once __DIR__.'/music-chat-library-v1.php';
$dsn=getenv('MUSIC_TEST_MYSQL_DSN')?:'';
if($dsn===''){echo "SKIP: MUSIC_TEST_MYSQL_DSN is unset; native MySQL acceptance was not run.\n";exit(0);}
$connection=['dsn'=>$dsn,'user'=>getenv('MUSIC_TEST_MYSQL_USER')?:'root','password'=>getenv('MUSIC_TEST_MYSQL_PASSWORD')?:''];
$pdo=new PDO($dsn,$connection['user'],$connection['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='vp3_music_test')throw new RuntimeException('Use the dedicated disposable vp3_music_test database.');
if($pdo->query('SHOW TABLES')->fetchColumn()!==false)throw new RuntimeException('Native MySQL acceptance requires a fresh disposable vp3_music_test database.');
$count=music_chat_library_suite_v1($pdo,$connection);
echo "Music chat library native MySQL (real prepared statements): {$count} checks passed.\n";
