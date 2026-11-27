<?php
declare(strict_types=1);
require dirname(__DIR__).'/phone-push-delivery-fixture-v1.php';
$GLOBALS['ppd_db']=$pdo=ppd_connect('sqlite::memory:');$now=time();$key=random_bytes(32);$admin=['role'=>'admin'];
ppd_schema($pdo);vp3_phone_push_configure_v1($pdo,$admin,true,$now,$key);
$phone=ppd_make($pdo,1,'Status fixture');$d=ppd_row($pdo,$phone);$cases=[];
$capture=static function(string $name,?string $scopeKey=null)use($pdo,$d,$now,$key,&$cases):void{
    $k=$scopeKey??$key;$status=vp3_phone_push_delivery_status_v1($pdo,$now,$k);
    $cases[]=['name'=>$name,'state'=>$status['state'],'device'=>['id'=>$d['id'],'status'=>'approved','push'=>vp3_phone_push_view_v1($pdo,$d,$now,true,$k)]];
};
$capture('missing_upgrade');
vp3_phone_push_delivery_install_v1($pdo);vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>true,'subject'=>'mailto:fixture@example.test','revision'=>1],$now,$key);
vp3_phone_push_delivery_heartbeat_v1($pdo,$now,['runtime'=>PHP_VERSION,'transport_ready'=>true,'code'=>'ready']);ppd_subscribe($pdo,$phone,$now,$key);
$capture('ready');$capture('credentials',random_bytes(32));
$source=ppd_source($pdo,$phone,$now-10,'test',bin2hex(random_bytes(16)),600);$event=ppd_enqueue($pdo,$phone,$source,$now-10,$key);ppd_run($pdo,$now-9,$key);
vp3_phone_push_delivery_resolve_v1($pdo,$d,$event['event_ref'],$now-8,'ppd_source_current',$key);
$pdo->prepare('UPDATE vp3_phone_push_delivery_config_v1 SET worker_epoch=?')->execute([$now-181]);$capture('stale_worker');
$pdo->prepare("UPDATE vp3_phone_push_delivery_config_v1 SET worker_epoch=?,worker_transport_ready=0,worker_code='runtime_requires_php82'")->execute([$now]);$capture('runtime');
echo json_encode($cases,JSON_THROW_ON_ERROR);
