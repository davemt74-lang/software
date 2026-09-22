#!/usr/bin/env php
<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__).'/includes/bootstrap.php';

$pdo=db();
if(!$pdo){
    fwrite(STDERR,"Database connection is unavailable.
");
    exit(1);
}

$key=trim((string)($argv[1]??''));
if($key===''){
    $key=gmdate('Y-m-d-H');
}

try{
    $run=client_release_automation_run_v170($pdo,'cli',0,false,$key);
    echo json_encode([
        'id'=>(int)($run['id']??0),
        'status'=>(string)($run['status']??'unknown'),
        'proposals_created'=>(int)($run['proposals_created']??0),
        'actions_executed'=>(int)($run['actions_executed']??0),
        'holds_created'=>(int)($run['holds_created']??0),
        'dry_run'=>(bool)($run['dry_run']??true),
        'automation_enabled'=>(bool)($run['automation_enabled']??false),
        'kill_switch'=>(bool)($run['kill_switch']??false),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit((string)($run['status']??'')==='completed_with_errors'?2:0);
}catch(Throwable $e){
    fwrite(STDERR,$e->getMessage().PHP_EOL);
    exit(1);
}
