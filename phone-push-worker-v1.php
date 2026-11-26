<?php
declare(strict_types=1);

/** Dedicated, bounded Cloud cron worker. Never run notification I/O in a web request. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/phone-tracking-v1.php';
require_once __DIR__.'/includes/phone-push-transport-v1.php';
require_once __DIR__.'/includes/phone-push-worker-v1.php';

$pdo=db();
if(!$pdo instanceof PDO||!vp3_phone_push_delivery_ready_v1($pdo)){
    fwrite(STDERR,"Phone notification schema is unavailable.\n");exit(1);
}
$directory=STONEFELLOW_ROOT.'/private/phone-push-v1';
if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory)){
    fwrite(STDERR,"Private worker storage is unavailable.\n");exit(1);
}
$lock=fopen($directory.'/worker.lock','c+');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
@chmod($directory.'/worker.lock',0600);
$exit=1;
try{
    $transport=vp3_phone_push_transport_ready_v1();
    $health=['runtime'=>PHP_VERSION,'transport_ready'=>$transport['available'],'code'=>$transport['code']];
    vp3_phone_push_delivery_heartbeat_v1($pdo,time(),$health);
    if(!$transport['available']){
        // PHP 8.1 may host the web app, but cannot load the PHP 8.2+ sender.
        // Preflight failure leaves jobs and their attempt counters untouched.
        fwrite(STDERR,"Phone notification transport is unavailable: ".$transport['code'].".\n");
    }else{
        // Three requests per tick, at most eight seconds each after DNS. A
        // second cron cannot overlap this process; durable leases also protect
        // multiple hosts and recover a crash without repeating an unknown send.
        $send=static fn(array $subscription,array $vapid,string $payload,array $options):array=>vp3_phone_push_worker_send_v1($pdo,$subscription,$vapid,$payload,$options);
        $result=vp3_phone_push_delivery_run_v1($pdo,3,'vp3_phone_push_source_current_v1',$send,time());
        $result['pruned']=vp3_phone_push_delivery_prune_v1($pdo,time(),100);
        vp3_phone_push_delivery_heartbeat_v1($pdo,time(),$health);
        echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
        $exit=0;
    }
}catch(Throwable){
    fwrite(STDERR,"Phone notification worker did not complete.\n");
    try{vp3_phone_push_delivery_heartbeat_v1($pdo,time(),['runtime'=>PHP_VERSION,'transport_ready'=>false,'code'=>'worker_failed']);}catch(Throwable){}
}finally{flock($lock,LOCK_UN);fclose($lock);}
exit($exit);
