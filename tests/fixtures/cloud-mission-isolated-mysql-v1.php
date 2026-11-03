<?php
declare(strict_types=1);
$mysql=getenv('VP3_SECTION7_MYSQL_DSN');
if($mysql&&!getenv('VP3_CLOUD_SCHEDULE_DSN')){
    $dbName='vp3_web_missions_'.bin2hex(random_bytes(5));$admin=new PDO($mysql,'root',getenv('VP3_SECTION7_MYSQL_PASSWORD')?:'section7-fixture');$admin->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4');
    putenv('VP3_CLOUD_SCHEDULE_DSN='.preg_replace('/dbname=[^;]+/','dbname='.$dbName,$mysql));putenv('VP3_CLOUD_SCHEDULE_USER=root');putenv('VP3_CLOUD_SCHEDULE_PASSWORD='.(getenv('VP3_SECTION7_MYSQL_PASSWORD')?:'section7-fixture'));
    register_shutdown_function(static function()use($admin,$dbName){$admin->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');});
}
