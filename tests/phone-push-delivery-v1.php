<?php
declare(strict_types=1);

require_once __DIR__.'/phone-push-delivery-fixture-v1.php';

if(($argv[1]??'')==='client'){
    $GLOBALS['ppd_db']=$pdo=ppd_connect((string)getenv('VP3_PUSH_FIXTURE_DSN'));
    $key=hex2bin((string)getenv('VP3_PUSH_FIXTURE_KEY'));
    $job=json_decode((string)fgets(STDIN),true,16,JSON_THROW_ON_ERROR);
    echo "READY\n";fflush(STDOUT);if(trim((string)fgets(STDIN))!=='GO')exit(2);
    if($job['action']==='run'){
        $result=ppd_run($pdo,$job['now'],$key,static function(array $sub,array $vapid,string $payload)use($pdo):array{
            if($pdo->inTransaction())throw new LogicException('Transport held a transaction.');
            $p=json_decode($payload,true,8,JSON_THROW_ON_ERROR);
            vp3_phone_sql_v1($pdo,'INSERT INTO push_fixture_sends(event_ref,sends) VALUES(?,1)',[$p['event_ref']]);
            return ppd_accepted();
        },1);
    }else{ppd_disable($pdo,$job['device'],$job['now'],$key);$result=['disabled'=>true];}
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";exit;
}

$server=null;$schema=null;$file=null;
$configured=(string)getenv('VP3_TEST_MYSQL_DSN');
if($configured!==''){
    if(!str_starts_with($configured,'mysql:'))throw new RuntimeException('Expected an isolated native MySQL server DSN.');
    $serverDsn=preg_replace('/(?:^mysql:|;)dbname=[^;]*(?:;|$)/',str_starts_with(substr($configured,6),'dbname=')?'mysql:':';',$configured);
    $server=ppd_connect($serverDsn);$schema='vp3_phone_push_test_'.bin2hex(random_bytes(8));
    $server->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $dsn=rtrim($serverDsn,';').';dbname='.$schema;
}else{$file=tempnam(sys_get_temp_dir(),'vp3_phone_push_');$dsn='sqlite:'.$file;}

try{
    $GLOBALS['ppd_db']=$pdo=ppd_connect($dsn);$now=time();$key=random_bytes(32);$admin=['role'=>'admin'];
    putenv('VP3_PUSH_FIXTURE_DSN='.$dsn);putenv('VP3_PUSH_FIXTURE_KEY='.bin2hex($key));
    ppd_schema($pdo);
    ppd_check(!vp3_phone_push_delivery_ready_v1($pdo),'missing delivery migration is reported without implicit DDL');
    $before=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn():0;
    ppd_check(vp3_phone_push_delivery_status_v1($pdo,$now,$key)['state']==='upgrade_required','missing migration status remains usable');
    if($before)ppd_check($before===(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn(),'status does not create tables');
    vp3_phone_push_delivery_install_v1($pdo);vp3_phone_push_delivery_install_v1($pdo);
    ppd_check(vp3_phone_push_delivery_ready_v1($pdo),'delivery installer replays safely on the native driver');
    $pdo->exec('ALTER TABLE vp3_phone_push_delivery_config_v1 DROP COLUMN last_skipped_epoch');
    ppd_check(!vp3_phone_push_delivery_ready_v1($pdo),'partial delivery telemetry migration fails readiness without implicit repair');
    // A new upgrade request has a fresh connection/positive metadata cache.
    $GLOBALS['ppd_db']=$pdo=ppd_connect($dsn);
    vp3_phone_push_delivery_install_v1($pdo);
    ppd_check(vp3_phone_push_delivery_ready_v1($pdo),'explicit installer replays a partial telemetry upgrade');
    $status=vp3_phone_push_delivery_status_v1($pdo,$now,$key);
    ppd_check(!$status['enabled']&&!$status['ready']&&$status['revision']===1&&$status['worker']['state']==='never_seen','delivery defaults off without inventing worker health');
    ppd_reject(403,fn()=>vp3_phone_push_delivery_configure_v1($pdo,['id'=>1],['enabled'=>true,'revision'=>1,'subject'=>'mailto:ops@example.test'],$now,$key),'ordinary owner cannot enable site transport');
    ppd_reject(422,fn()=>vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>true,'revision'=>1,'subject'=>"mailto:ops@example.test\r\nX:1"],$now,$key),'invalid VAPID contact rejected');
    foreach(['https://localhost/push','https://ops.localhost/push','https://127.0.0.1/push','https://example.test:443/push','https://example.test/push?contact=ops','https://user@example.test/push','https://example.test/push#contact'] as $subject)
        ppd_check(!vp3_phone_push_delivery_subject_v1($subject),'admin and worker agree on rejected VAPID subject form');
    ppd_check(vp3_phone_push_delivery_subject_v1('https://example.test/phone-push')&&vp3_phone_push_delivery_subject_v1('mailto:ops@example.test'),'public HTTPS and mailto VAPID contacts are accepted consistently');
    ppd_reject(503,fn()=>vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>true,'revision'=>1,'subject'=>'mailto:ops@example.test'],$now,$key),'delivery cannot invent missing protected credentials');
    vp3_phone_push_configure_v1($pdo,$admin,true,$now,$key);
    vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>true,'revision'=>1,'subject'=>'mailto:ops@example.test'],$now,$key);
    $config=vp3_phone_push_delivery_config_v1($pdo);
    ppd_reject(409,fn()=>vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>false,'revision'=>1,'subject'=>''],$now,$key),'stale delivery editor rejected');
    vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>true,'revision'=>2,'subject'=>'mailto:ops@example.test'],$now,$key);
    ppd_check((int)vp3_phone_push_delivery_config_v1($pdo)['revision']===2,'same configuration does not advance revision');
    vp3_phone_push_delivery_heartbeat_v1($pdo,$now,['runtime'=>'8.1.34','transport_ready'=>false,'code'=>'runtime_requires_php82']);
    ppd_check(vp3_phone_push_delivery_status_v1($pdo,$now,$key)['state']==='transport_unavailable','observed incompatible worker does not claim readiness');
    vp3_phone_push_delivery_heartbeat_v1($pdo,$now+1,['runtime'=>'8.3.30','transport_ready'=>true,'code'=>'ready']);
    vp3_phone_push_delivery_heartbeat_v1($pdo,$now,['runtime'=>'8.1.34','transport_ready'=>false,'code'=>'runtime_requires_php82']);
    ppd_check(vp3_phone_push_delivery_status_v1($pdo,$now+1,$key)['ready'],'older heartbeat cannot overwrite newer compatible worker observation');
    $a=ppd_make($pdo,1,'First phone');$b=ppd_make($pdo,1,'Second phone');$other=ppd_make($pdo,2,'Other account phone');
    $subA=ppd_subscribe($pdo,$a,$now,$key);ppd_subscribe($pdo,$b,$now,$key);ppd_subscribe($pdo,$other,$now,$key);
    $rollbackId=bin2hex(random_bytes(16));
    try{
        vp3_phone_transaction_v1($pdo,static function()use($pdo,$a,$now,$key,$rollbackId):array{
            vp3_phone_account_v1($pdo,1,true);$d=vp3_phone_row_v1($pdo,1,$a['device']['id']);
            $source=ppd_source($pdo,$a,$now,'private_chat',$rollbackId);
            $e=vp3_phone_push_delivery_enqueue_v1($pdo,$d,$source,$now,'ppd_source_current',$key);
            ppd_check($pdo->inTransaction()&&$e['state']==='queued','enqueue joins the production source transaction under BEGIN IMMEDIATE/native PDO');
            throw new RuntimeException('Fixture source write rolled back.',499);
        });
    }catch(RuntimeException $e){if($e->getCode()!==499)throw $e;}
    ppd_check(!(int)vp3_phone_sql_v1($pdo,'SELECT COUNT(*) FROM push_fixture_sources WHERE source_id=?',[$rollbackId])->fetchColumn()
        &&!(int)vp3_phone_sql_v1($pdo,'SELECT COUNT(*) FROM vp3_phone_push_outbox_v1 WHERE source_id=?',[$rollbackId])->fetchColumn(),
        'source write and notification enqueue roll back together with no orphan event');
    vp3_phone_transaction_v1($pdo,static function()use($pdo,$now):array{
        vp3_phone_push_delivery_capacity_skipped_v1($pdo,$now);
        vp3_phone_push_delivery_capacity_skipped_v1($pdo,$now-1);
        return [];
    });
    $capacity=vp3_phone_push_delivery_status_v1($pdo,$now,$key);
    ppd_check($capacity['capacity_skipped_count']===2&&$capacity['last_skipped_epoch']===$now,'capacity skips have durable aggregate accounting with monotonic observation time');
    try{
        vp3_phone_transaction_v1($pdo,static function()use($pdo,$now):array{
            vp3_phone_push_delivery_capacity_skipped_v1($pdo,$now+1);
            throw new RuntimeException('Fixture capacity source rolled back.',499);
        });
    }catch(RuntimeException $e){if($e->getCode()!==499)throw $e;}
    ppd_check(vp3_phone_push_delivery_status_v1($pdo,$now,$key)['capacity_skipped_count']===2,'capacity accounting rolls back if its primary source does not commit');
    $test=ppd_source($pdo,$a,$now,'test',null,600);$queued=ppd_enqueue($pdo,$a,$test,$now,$key);
    $again=ppd_enqueue($pdo,$a,array_replace($test,['expires_epoch'=>$now+650]),$now+1,$key);
    ppd_check($queued['event_ref']===$again['event_ref']&&$again['expires_epoch']===$now+600,'same exact-device source is durable and expiry-stable on retry');
    $test2=ppd_source($pdo,$a,$now,'test',null,600);
    ppd_reject(429,fn()=>ppd_enqueue($pdo,$a,$test2,$now+1,$key),'new test has a per-device cooldown');
    $row=vp3_phone_push_delivery_event_v1($pdo,$queued['event_ref']);$stored=json_encode($row,JSON_THROW_ON_ERROR);
    ppd_check(!str_contains($stored,$subA['endpoint'])&&!str_contains($stored,$subA['keys']['auth'])&&!str_contains($stored,'subscription_cipher'),'outbox stores no subscription endpoint or encrypted secret copy');
    ppd_reject(403,fn()=>ppd_enqueue($pdo,$b,$test,$now,$key),'another phone of the same owner cannot receive this exact-device source');
    ppd_reject(403,fn()=>ppd_enqueue($pdo,$other,$test,$now,$key),'another account cannot receive the source');
    ppd_reject(422,fn()=>ppd_enqueue($pdo,$a,array_replace($test,['type'=>'voicemail']),$now,$key),'owner-role pairing does not authorize private owner voicemail');
    ppd_reject(422,fn()=>ppd_enqueue($pdo,$a,$test+['payload'=>'private text'],$now,$key),'caller cannot provide notification content');
    ppd_reject(404,fn()=>vp3_phone_push_delivery_resolve_v1($pdo,ppd_row($pdo,$a),$queued['event_ref'],$now,'ppd_source_current',$key),'queued unsent event cannot be forged into an opened notification');
    $payload=null;
    $run=ppd_run($pdo,$now+2,$key,static function(array $sub,array $vapid,string $raw,array $options)use($pdo,$subA,&$payload,$queued):array{
        ppd_check(!$pdo->inTransaction(),'provider network call has no database transaction');
        ppd_check($sub===$subA&&vp3_phone_push_point_v1($vapid['public_key'])&&isset($vapid['private_key'],$vapid['subject']),'bound subscription and VAPID secrets are decrypted only for transport');
        $payload=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        ppd_check(array_keys($payload)===['v','event_ref','receipt_token']&&$payload['v']===1&&$payload['event_ref']===$queued['event_ref'],'push contains only version, opaque event and event-only receipt capability');
        ppd_check($options['topic']===$queued['event_ref']&&$options['urgency']==='normal'&&$options['ttl']>0&&$options['ttl']<=3600,'provider TTL and stable topic are bounded by the event');
        return ppd_accepted();
    });
    $row=vp3_phone_push_delivery_event_v1($pdo,$queued['event_ref']);
    ppd_check($run['attempted']===1&&$row['state']==='accepted'&&(int)$row['provider_accepted_epoch']===$now+2&&!(int)$row['client_observed_epoch']&&!(int)$row['opened_epoch'],'provider acceptance does not claim device delivery or opening');
    ppd_check(!str_contains(json_encode($row),$payload['receipt_token'])&&hash_equals($row['receipt_hash'],hash('sha256',$payload['receipt_token'])),'receipt capability is hashed at rest');
    $receipt=['event_ref'=>$queued['event_ref'],'receipt_token'=>$payload['receipt_token'],'kind'=>'displayed'];
    ppd_reject(404,fn()=>vp3_phone_push_delivery_receipt_v1($pdo,array_replace($receipt,['receipt_token'=>vp3_phone_push_b64_v1(random_bytes(32))]),$now+3),'incorrect event capability rejected');
    ppd_reject(422,fn()=>vp3_phone_push_delivery_receipt_v1($pdo,$receipt+['device_id'=>$a['device']['id']],$now+3),'display capability has no arbitrary target field');
    ppd_reject(422,fn()=>vp3_phone_push_delivery_receipt_v1($pdo,array_replace($receipt,['kind'=>'opened']),$now+3),'display capability cannot record a user opening');
    ppd_check(vp3_phone_push_delivery_receipt_v1($pdo,$receipt,$now+3)===['ok'=>true],'display capability returns no private event or source data');
    vp3_phone_push_delivery_receipt_v1($pdo,$receipt,$now+4);
    ppd_check((int)vp3_phone_push_delivery_event_v1($pdo,$queued['event_ref'])['client_observed_epoch']===$now+3,'repeated display receipt is idempotent on native PDO');
    ppd_reject(404,fn()=>vp3_phone_push_delivery_resolve_v1($pdo,ppd_row($pdo,$b),$queued['event_ref'],$now+4,'ppd_source_current',$key),'authenticated second phone cannot open first phone notification');
    ppd_reject(404,fn()=>vp3_phone_push_delivery_resolve_v1($pdo,ppd_row($pdo,$a),$queued['event_ref'],$now+4,static fn()=>false,$key),'opening revalidates source independently of display receipt');
    $opened=vp3_phone_push_delivery_resolve_v1($pdo,ppd_row($pdo,$a),$queued['event_ref'],$now+4,'ppd_source_current',$key);
    ppd_check($opened['opened_epoch']===$now+4&&$opened['source']===$test&&$opened['subscription_revision']===1,'authenticated opening returns only the verified original source and distinct tap time');
    $view=vp3_phone_push_delivery_view_v1($pdo,ppd_row($pdo,$a),$now+4,$key);
    ppd_check($view['ready']&&$view['last_test']['event_ref']===$queued['event_ref']&&!isset($view['subject'],$view['last_test']['source']),'holder gets current-device redacted delivery/test status');
    ppd_check(!vp3_phone_push_delivery_view_v1($pdo,ppd_row($pdo,$a),$now+200,$key)['ready'],'stale worker health is not advertised as ready');
    ppd_reject(404,fn()=>vp3_phone_push_delivery_receipt_v1($pdo,$receipt,$now+600),'display capability expires with its event');
    $private=ppd_source($pdo,$a,$now,'private_chat','123');$offline=ppd_enqueue($pdo,$a,$private,$now+200,$key);
    ppd_check($offline['state']==='queued','durable source enqueue works while worker heartbeat is stale');
    vp3_phone_push_configure_v1($pdo,$admin,false,$now+201,$key);
    $run=ppd_run($pdo,$now+201,$key);
    ppd_check($run['attempted']===1&&vp3_phone_push_delivery_event_v1($pdo,$offline['event_ref'])['state']==='accepted','pausing new enrollment preserves delivery to existing registered grants');
    vp3_phone_push_configure_v1($pdo,$admin,true,$now+202,$key);

    // Two authority fences: never dispatch a grant withdrawn after the claim.
    $s=ppd_source($pdo,$b,$now,'agent_request',null,3600,7);$event=ppd_enqueue($pdo,$b,$s,$now,$key);
    $claim=vp3_phone_push_delivery_claim_v1($pdo,$now,'ppd_source_current',$key);
    ppd_disable($pdo,$b,$now+1,$key,false);
    ppd_check(vp3_phone_push_delivery_prepare_v1($pdo,$claim,$now+2,'ppd_source_current',$key)===null,'final send fence catches withdrawal even without eager cancellation hook');
    $row=vp3_phone_push_delivery_event_v1($pdo,$event['event_ref']);
    ppd_check($row['state']==='cancelled'&&(int)$row['attempts']===0,'withdrawn claim consumes no provider attempt');
    ppd_subscribe($pdo,$b,$now+2,$key);
    $s=ppd_source($pdo,$b,$now);$event=ppd_enqueue($pdo,$b,$s,$now,$key);$claim=vp3_phone_push_delivery_claim_v1($pdo,$now,'ppd_source_current',$key);
    ppd_subscribe($pdo,$b,$now+1,$key);
    ppd_check(vp3_phone_push_delivery_prepare_v1($pdo,$claim,$now+2,'ppd_source_current',$key)===null,'replacement subscription revision cancels an old claim');
    $s=ppd_source($pdo,$b,$now);$event=ppd_enqueue($pdo,$b,$s,$now,$key);$claim=vp3_phone_push_delivery_claim_v1($pdo,$now,'ppd_source_current',$key);
    vp3_phone_sql_v1($pdo,'UPDATE push_fixture_sources SET current_source=0 WHERE source_id=?',[$s['id']]);
    ppd_check(vp3_phone_push_delivery_prepare_v1($pdo,$claim,$now+1,'ppd_source_current',$key)===null,'canonical source withdrawal between claim and send is fenced');
    $s=ppd_source($pdo,$b,$now);$event=ppd_enqueue($pdo,$b,$s,$now,$key);$claim=vp3_phone_push_delivery_claim_v1($pdo,$now,'ppd_source_current',$key);
    $oldLease=$claim['lease_token'];$newClaim=vp3_phone_push_delivery_claim_v1($pdo,$now+61,'ppd_source_current',$key);
    ppd_check($newClaim['state']==='claimed'&&$newClaim['lease_token']!==$oldLease&&(int)$newClaim['attempts']===0,'lease recovery before dispatch safely reclaims without consuming an attempt');
    ppd_check(vp3_phone_push_delivery_prepare_v1($pdo,$claim,$now+62,'ppd_source_current',$key)===null,'old worker cannot use a replaced lease');
    $send=vp3_phone_push_delivery_prepare_v1($pdo,$newClaim,$now+62,'ppd_source_current',$key);
    $late=json_decode($send['payload'],true,8,JSON_THROW_ON_ERROR);
    $recovered=vp3_phone_push_delivery_claim_v1($pdo,$now+123,'ppd_source_current',$key);
    ppd_check($recovered['state']==='uncertain'&&(int)$recovered['attempts']===1,'crash after dispatch is uncertain and is never automatically resent');
    $lateFinish=vp3_phone_push_delivery_finish_v1($pdo,$send['claim'],ppd_accepted(),$now+124,$key);
    ppd_check($lateFinish['state']==='uncertain'&&!$lateFinish['provider_accepted_epoch'],'lost lease cannot manufacture a successful provider result');
    vp3_phone_push_delivery_receipt_v1($pdo,['event_ref'=>$late['event_ref'],'receipt_token'=>$late['receipt_token'],'kind'=>'displayed'],$now+124);
    $resolved=vp3_phone_push_delivery_resolve_v1($pdo,ppd_row($pdo,$b),$late['event_ref'],$now+125,'ppd_source_current',$key);
    ppd_check($resolved['state']==='uncertain'&&$resolved['client_observed_epoch']===$now+124&&$resolved['opened_epoch']===$now+125,'late client display and authenticated opening are recorded separately from unknown provider outcome');
    ppd_check(ppd_run($pdo,$now+126,$key)['attempted']===0,'uncertain dispatch does not reappear as sendable work');

    // Explicit negative provider responses may retry, with one stable tag/capability.
    $s=ppd_source($pdo,$b,$now);$event=ppd_enqueue($pdo,$b,$s,$now,$key);$firstPayload='';
    ppd_run($pdo,$now,$key,static function(array $sub,array $vapid,string $payload)use(&$firstPayload):array{
        $firstPayload=$payload;return ['state'=>'retry','code'=>'provider_busy','http_status'=>503,'retry_after'=>90,'dispatched'=>true];
    });
    $row=vp3_phone_push_delivery_event_v1($pdo,$event['event_ref']);
    ppd_check($row['state']==='retry'&&(int)$row['available_epoch']===$now+90&&ppd_run($pdo,$now+89,$key)['attempted']===0,'retry honors provider backoff without early attempts');
    $samePayload=false;ppd_run($pdo,$now+90,$key,static function(array $sub,array $vapid,string $payload)use(&$samePayload,$firstPayload):array{$samePayload=hash_equals($firstPayload,$payload);return ppd_accepted();});
    $row=vp3_phone_push_delivery_event_v1($pdo,$event['event_ref']);
    ppd_check($samePayload&&$row['state']==='accepted'&&(int)$row['attempts']===2,'safe retry keeps stable notification tag and event capability');
    $s=ppd_source($pdo,$b,$now);$event=ppd_enqueue($pdo,$b,$s,$now,$key);$retryPayload=null;
    ppd_run($pdo,$now,$key,static function(array $sub,array $vapid,string $payload)use(&$retryPayload):array{$retryPayload=json_decode($payload,true);return ['state'=>'retry','code'=>'provider_busy','http_status'=>503,'retry_after'=>0,'dispatched'=>true];});
    vp3_phone_push_delivery_receipt_v1($pdo,['event_ref'=>$retryPayload['event_ref'],'receipt_token'=>$retryPayload['receipt_token'],'kind'=>'displayed'],$now+1);
    ppd_check(ppd_run($pdo,$now+31,$key)['attempted']===0&&vp3_phone_push_delivery_event_v1($pdo,$event['event_ref'])['state']==='uncertain','late client receipt suppresses a scheduled retry without inventing provider acceptance');
    $event=ppd_enqueue($pdo,$b,ppd_source($pdo,$b,$now),$now,$key);
    ppd_run($pdo,$now,$key,static function(array $sub,array $vapid,string $payload)use($pdo,$now):array{
        $body=json_decode($payload,true,8,JSON_THROW_ON_ERROR);
        vp3_phone_push_delivery_receipt_v1($pdo,['event_ref'=>$body['event_ref'],'receipt_token'=>$body['receipt_token'],'kind'=>'displayed'],$now);
        return ['state'=>'retry','code'=>'provider_busy','http_status'=>503,'retry_after'=>30,'dispatched'=>true];
    });
    $row=vp3_phone_push_delivery_event_v1($pdo,$event['event_ref']);
    ppd_check($row['state']==='uncertain'&&$row['last_code']==='client_observed'&&(int)$row['client_observed_epoch']===$now
        &&!(int)$row['provider_accepted_epoch'],'receipt racing an in-flight negative provider response prevents scheduling a retry');
    $event=ppd_enqueue($pdo,$b,ppd_source($pdo,$b,$now),$now,$key);$between=null;
    ppd_run($pdo,$now,$key,static function(array $sub,array $vapid,string $payload)use(&$between):array{$between=json_decode($payload,true);return ['state'=>'retry','code'=>'provider_busy','http_status'=>503,'retry_after'=>0,'dispatched'=>true];});
    $retryClaim=vp3_phone_push_delivery_claim_v1($pdo,$now+30,'ppd_source_current',$key);
    vp3_phone_push_delivery_receipt_v1($pdo,['event_ref'=>$between['event_ref'],'receipt_token'=>$between['receipt_token'],'kind'=>'displayed'],$now+31);
    ppd_check(vp3_phone_push_delivery_prepare_v1($pdo,$retryClaim,$now+31,'ppd_source_current',$key)===null
        &&(int)vp3_phone_push_delivery_event_v1($pdo,$event['event_ref'])['attempts']===1,'receipt between retry claim and final send invalidates that lease without another attempt');
    $s=ppd_source($pdo,$b,$now);$event=ppd_enqueue($pdo,$b,$s,$now,$key);
    ppd_run($pdo,$now,$key,static function(){throw new RuntimeException('Simulated timeout includes secrets that must not be stored');});
    $row=vp3_phone_push_delivery_event_v1($pdo,$event['event_ref']);
    ppd_check($row['state']==='uncertain'&&$row['last_code']==='transport_outcome_unknown'&&!str_contains(json_encode($row),'Simulated'),'transport exception is sanitized and never treated as a retryable success');
    foreach([
        ['state'=>'accepted','code'=>'bad','http_status'=>0,'retry_after'=>0,'dispatched'=>true],
        ['state'=>'retry','code'=>'bad','http_status'=>0,'retry_after'=>0,'dispatched'=>true],
        ['state'=>'failed','code'=>'bad','http_status'=>408,'retry_after'=>0,'dispatched'=>true],
    ] as $bad)ppd_check(vp3_phone_push_delivery_result_v1($bad)['state']==='uncertain','malformed or ambiguous transport claim stays uncertain');
    ppd_check(vp3_phone_push_delivery_result_v1(['state'=>'failed','code'=>'provider_redirect_blocked','http_status'=>302,'retry_after'=>0,'dispatched'=>true])['state']==='failed','refused provider redirect is a terminal failure without follow or retry');
    $s=ppd_source($pdo,$b,$now,'private_chat',null,20);$event=ppd_enqueue($pdo,$b,$s,$now,$key);
    ppd_run($pdo,$now,$key,static fn()=>['state'=>'retry','code'=>'provider_busy','http_status'=>429,'retry_after'=>120,'dispatched'=>true]);
    ppd_check(vp3_phone_push_delivery_event_v1($pdo,$event['event_ref'])['state']==='expired','provider backoff past source lifetime expires instead of retrying early');
    $s=ppd_source($pdo,$b,$now,'private_chat',null,86400);$event=ppd_enqueue($pdo,$b,$s,$now,$key);$tick=$now;
    for($i=0;$i<5;$i++){
        ppd_run($pdo,$tick,$key,static fn()=>['state'=>'retry','code'=>'dns_unavailable','http_status'=>0,'retry_after'=>0,'dispatched'=>false]);
        $row=vp3_phone_push_delivery_event_v1($pdo,$event['event_ref']);$tick=(int)$row['available_epoch'];
    }
    ppd_check($row['state']==='failed'&&(int)$row['attempts']===5&&$row['last_code']==='attempt_limit','bounded retry budget cannot become an infinite send loop');

    // Revocation and handoff use actual existing device/registry mutations.
    $moving=ppd_make($pdo,1,'Moving phone');ppd_subscribe($pdo,$moving,$now,$key);
    $s=ppd_source($pdo,$moving,$now);$event=ppd_enqueue($pdo,$moving,$s,$now,$key);$claim=vp3_phone_push_delivery_claim_v1($pdo,$now,'ppd_source_current',$key);
    $d=$moving['device'];$offer=vp3_phone_device_v1($pdo,$moving['token'],'handoff_offer',['device_id'=>$d['id'],'generation'=>$d['generation'],'confirmed'=>true])['handoff_ticket'];
    $target=['code'=>$offer['code'],'claim_key'=>bin2hex(random_bytes(32)),'confirmed'=>true];
    $review=vp3_phone_handoff_target_v1($pdo,'','handoff_claim',$target,$now)['handoff'];
    vp3_phone_handoff_owner_v1($pdo,['id'=>1],'handoff_approve',['device_id'=>$d['id'],'generation'=>$d['generation'],'transfer_id'=>$offer['transfer_id'],'comparison_code'=>$review['comparison_code'],'confirmed'=>true],$now);
    $new=vp3_phone_handoff_target_v1($pdo,'','handoff_finish',$target,$now);
    ppd_check(vp3_phone_push_delivery_prepare_v1($pdo,$claim,$now+1,'ppd_source_current',$key)===null&&vp3_phone_push_delivery_event_v1($pdo,$event['event_ref'])['state']==='cancelled','installation handoff cancels old queue identity even when stable device ID is preserved');
    $revoked=ppd_make($pdo,1,'Revoked phone');ppd_subscribe($pdo,$revoked,$now,$key);
    $s=ppd_source($pdo,$revoked,$now);$event=ppd_enqueue($pdo,$revoked,$s,$now,$key);
    vp3_phone_owner_v1($pdo,1,'revoke',['request_id'=>bin2hex(random_bytes(16)),'device_id'=>$revoked['device']['id'],'generation'=>$revoked['device']['generation']]);
    ppd_check(ppd_run($pdo,$now+1,$key)['attempted']===0&&vp3_phone_push_delivery_event_v1($pdo,$event['event_ref'])['state']==='cancelled','owner revocation fences queued work without provider calls');

    $s=ppd_source($pdo,$b,$now);$gone=ppd_enqueue($pdo,$b,$s,$now,$key);
    $second=ppd_enqueue($pdo,$b,ppd_source($pdo,$b,$now),$now,$key);
    ppd_run($pdo,$now,$key,static fn()=>['state'=>'expired','code'=>'subscription_expired','http_status'=>410,'retry_after'=>0,'dispatched'=>true],1);
    $srow=vp3_phone_push_row_v1($pdo,ppd_row($pdo,$b));
    $states=[vp3_phone_push_delivery_event_v1($pdo,$gone['event_ref'])['state'],vp3_phone_push_delivery_event_v1($pdo,$second['event_ref'])['state']];sort($states);
    ppd_check($srow['state']==='off'&&$srow['subscription_cipher']===null&&$states===['cancelled','expired'],'410 erases only current expired subscription and cancels its remaining queue');
    ppd_subscribe($pdo,$b,$now+1,$key);
    $event=ppd_enqueue($pdo,$b,ppd_source($pdo,$b,$now),$now,$key);
    ppd_run($pdo,$now,$key,static function()use($pdo,$b,$now,$key):array{
        ppd_subscribe($pdo,$b,$now+1,$key);return ['state'=>'expired','code'=>'subscription_expired','http_status'=>410,'retry_after'=>0,'dispatched'=>true];
    });
    ppd_check(vp3_phone_push_row_v1($pdo,ppd_row($pdo,$b))['state']==='registered'&&vp3_phone_push_delivery_event_v1($pdo,$event['event_ref'])['state']==='cancelled','late 410 cannot revoke a replacement subscription');
    ppd_reject(404,fn()=>vp3_phone_push_delivery_receipt_v1($pdo,['event_ref'=>$late['event_ref'],'receipt_token'=>$late['receipt_token'],'kind'=>'displayed'],$now+130),'old event receipt cannot cross a subscription revision');
    $event=ppd_enqueue($pdo,$b,ppd_source($pdo,$b,$now),$now,$key);
    ppd_run($pdo,$now,$key,static function()use($pdo,$admin,$now,$key):array{
        $c=vp3_phone_push_delivery_config_v1($pdo);
        vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>false,'revision'=>(int)$c['revision'],'subject'=>$c['subject']],$now+1,$key);
        return ppd_accepted();
    });
    $row=vp3_phone_push_delivery_event_v1($pdo,$event['event_ref']);
    ppd_check($row['state']==='cancelled'&&(int)$row['provider_accepted_epoch']===$now&&$row['provider_state']==='accepted','disable during network keeps cancellation while recording actual provider acceptance separately');
    $c=vp3_phone_push_delivery_config_v1($pdo);
    vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>true,'revision'=>(int)$c['revision'],'subject'=>$c['subject']],$now+2,$key);
    ppd_check(ppd_run($pdo,$now+3,$key)['attempted']===0,'re-enabling delivery never revives the prior configuration queue');

    // Native independent connections race the exact same candidate.
    $event=ppd_enqueue($pdo,$b,ppd_source($pdo,$b,$now),$now,$key);
    $races=ppd_parallel([['action'=>'run','now'=>$now],['action'=>'run','now'=>$now]]);
    ppd_check(array_sum(array_column($races,'attempted'))===1&&(int)vp3_phone_push_delivery_event_v1($pdo,$event['event_ref'])['attempts']===1
        &&(int)vp3_phone_sql_v1($pdo,'SELECT COUNT(*) FROM push_fixture_sends WHERE event_ref=?',[$event['event_ref']])->fetchColumn()===1,'parallel workers send a given event at most once');
    $event=ppd_enqueue($pdo,$b,ppd_source($pdo,$b,$now),$now,$key);
    ppd_parallel([['action'=>'run','now'=>$now],['action'=>'disable','now'=>$now,'device'=>$b]]);
    $row=vp3_phone_push_delivery_event_v1($pdo,$event['event_ref']);$srow=vp3_phone_push_row_v1($pdo,ppd_row($pdo,$b));
    ppd_check($srow['state']==='off'&&in_array($row['state'],['accepted','cancelled'],true)&&(int)$row['attempts']<=1&&ppd_run($pdo,$now+61,$key)['attempted']===0,'parallel withdrawal cannot leave dispatchable work or resurrect a grant');
    ppd_subscribe($pdo,$b,$now+1,$key);

    // Bounded site/device test and queue budgets are independent of subscription revisions.
    $budget=ppd_make($pdo,2,'Budget phone');ppd_subscribe($pdo,$budget,$now,$key);
    $firstBudget=ppd_enqueue($pdo,$budget,ppd_source($pdo,$budget,$now,'test'),$now,$key);
    ppd_subscribe($pdo,$budget,$now+1,$key);
    ppd_reject(429,fn()=>ppd_enqueue($pdo,$budget,ppd_source($pdo,$budget,$now,'test'),$now+1,$key),'re-enrollment cannot evade test cooldown');
    for($i=1;$i<10;$i++)ppd_enqueue($pdo,$budget,ppd_source($pdo,$budget,$now+$i*30,'test'),$now+$i*30,$key);
    ppd_reject(429,fn()=>ppd_enqueue($pdo,$budget,ppd_source($pdo,$budget,$now+300,'test'),$now+300,$key),'ten tests per device per hour is enforced');
    $budget2=ppd_make($pdo,2,'Budget phone two');$budget3=ppd_make($pdo,2,'Budget phone three');$budget4=ppd_make($pdo,2,'Budget phone four');
    foreach([$budget2,$budget3,$budget4] as $phone)ppd_subscribe($pdo,$phone,$now,$key);
    foreach([$budget2,$budget3] as $phone)for($i=0;$i<10;$i++)ppd_enqueue($pdo,$phone,ppd_source($pdo,$phone,$now+$i*30,'test'),$now+$i*30,$key);
    ppd_reject(429,fn()=>ppd_enqueue($pdo,$budget4,ppd_source($pdo,$budget4,$now+300,'test'),$now+300,$key),'thirty tests per owner per hour is enforced across devices');
    for($i=0;$i<64;$i++)ppd_enqueue($pdo,$b,ppd_source($pdo,$b,$now),$now,$key);
    ppd_reject(429,fn()=>ppd_enqueue($pdo,$b,ppd_source($pdo,$b,$now),$now,$key),'pending outbox is bounded per device');
    vp3_phone_push_delivery_cancel_device_v1($pdo,ppd_row($pdo,$b),$now);
    ppd_check(vp3_phone_push_delivery_prune_v1($pdo,$now+9*86400,3)===3,'retention cleanup is explicitly bounded and native-driver compatible');
    $version=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?$pdo->query('SELECT VERSION()')->fetchColumn():$pdo->query('SELECT sqlite_version()')->fetchColumn();
    echo 'PASS '.$GLOBALS['ppd_checks'].' checks; driver='.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).'; version='.$version."\n";
}finally{
    unset($GLOBALS['ppd_db']);$pdo=null;
    if($server&&$schema)$server->exec('DROP DATABASE `'.$schema.'`');
    if($file!==null)@unlink($file);
    putenv('VP3_PUSH_FIXTURE_DSN');putenv('VP3_PUSH_FIXTURE_KEY');
}
