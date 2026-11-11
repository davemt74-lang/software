<?php
declare(strict_types=1);

ini_set('zend.exception_ignore_args','1');
set_exception_handler(static function(Throwable $error):void{
    fwrite(STDERR,json_encode(['fixture_failure'=>true,'error_class'=>get_class($error),'public_code'=>(int)$error->getCode(),
        'driver_code'=>$error instanceof PDOException?(int)($error->errorInfo[1]??0):0,'file'=>basename($error->getFile()),'line'=>$error->getLine(),
        'seed_schema'=>str_starts_with($error->getMessage(),'Controlled ')?$error->getMessage():null],JSON_THROW_ON_ERROR).PHP_EOL);exit(1);
});
$dsn=(string)(getenv('VP3_MYSQL_TEST_DSN')?:getenv('VP3_TEST_MYSQL_DSN'));
if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('An explicit disposable native MySQL DSN is required.');
putenv('VP3_MEETING_ACTION_MYSQL_DSN='.$dsn);
putenv('VP3_MEETING_ACTION_MYSQL_USER='.(getenv('VP3_MYSQL_TEST_USER')?:getenv('VP3_TEST_MYSQL_USER')));
putenv('VP3_MEETING_ACTION_MYSQL_PASSWORD='.(getenv('VP3_MYSQL_TEST_PASSWORD')?:getenv('VP3_TEST_MYSQL_PASS')));
require __DIR__.'/agent-meeting-action-sources-v1.php';
