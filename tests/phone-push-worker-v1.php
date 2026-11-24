<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/vp3-push-worker-'.bin2hex(random_bytes(10));
mkdir($root.'/private',0700,true);define('STONEFELLOW_ROOT',$root);
$key=random_bytes(32);file_put_contents($root.'/private/ai-key.php','<?php return '.var_export(base64_encode($key),true).';');
register_shutdown_function(static function()use($root):void{unlink($root.'/private/ai-key.php');rmdir($root.'/private');rmdir($root);});
require_once __DIR__.'/phone-push-delivery-fixture-v1.php';
require_once dirname(__DIR__).'/includes/phone-push-worker-v1.php';
$ready=vp3_phone_push_transport_ready_v1();
if(PHP_VERSION_ID<80200){
    ppd_check(!$ready['available']&&$ready['code']==='runtime_requires_php82','worker bridge preserves PHP8.1 preflight guard');
    echo "PASS worker bridge runtime guard.\n";exit;
}
if(!$ready['available'])throw new RuntimeException('Worker fixture runtime unavailable: '.$ready['code']);
$GLOBALS['ppd_db']=$pdo=ppd_connect('sqlite::memory:');$now=time();$admin=['role'=>'admin'];
ppd_schema($pdo);vp3_phone_push_delivery_install_v1($pdo);vp3_phone_push_vapid_cache_install_v1($pdo);
vp3_phone_push_configure_v1($pdo,$admin,true,$now);
vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>true,'subject'=>'mailto:worker@example.test','revision'=>1],$now);
$make=static function(string $name,string $host='web.push.apple.com')use($pdo,$now,$key):array{
    $phone=ppd_make($pdo,1,$name);
    ppd_subscribe($pdo,$phone,$now,$key,['endpoint'=>'https://'.$host.'/Qworker_fixture_'.bin2hex(random_bytes(16)),
        'expirationTime'=>null,'keys'=>['p256dh'=>vp3_phone_push_keypair_v1()['public_key'],'auth'=>vp3_phone_push_b64_v1(random_bytes(16))]]);
    return $phone;
};
$prepare=static function(array $phone)use($pdo,$now):array{
    $device=ppd_row($pdo,$phone);
    $source=['type'=>'test','id'=>bin2hex(random_bytes(16)),'revision'=>1,'expires_epoch'=>$now+600];
    vp3_phone_push_delivery_enqueue_v1($pdo,$device,$source,$now,'vp3_phone_push_source_current_v1');
    $claim=vp3_phone_push_delivery_claim_v1($pdo,$now,'vp3_phone_push_source_current_v1');
    $send=vp3_phone_push_delivery_prepare_v1($pdo,$claim,$now,'vp3_phone_push_source_current_v1');
    if(!$send)throw new RuntimeException('Missing prepared fixture.');
    ppd_check(($send['vapid']['config_revision']??null)===(int)$claim['config_revision'],'core preparation supplies the exact private configuration revision');
    return $send;
};
$wires=[];
$exchange=static function(array $wire)use($pdo,&$wires):array{
    ppd_check(!$pdo->inTransaction(),'actual encrypted provider request starts outside database transactions');
    $wires[]=$wire;return ppd_accepted();
};
$dispatch=static function(array $send)use($pdo,$exchange):array{
    return vp3_phone_push_worker_send_v1($pdo,$send['subscription'],$send['vapid'],$send['payload'],$send['options'],$exchange);
};
$first=$make('First worker phone');$send=$prepare($first);$result=$dispatch($send);
ppd_check($result['state']==='accepted'&&$result['dispatched']&&count($wires)===1,'worker bridge sends one real-library encrypted request through the injected network boundary');
vp3_phone_push_delivery_finish_v1($pdo,$send['claim'],$result,time());
$replayed=$dispatch($send);
ppd_check(!$replayed['dispatched']&&count($wires)===1,'a completed prepared event cannot be dispatched again through the bridge');
$authorization=$wires[0]['headers']['Authorization'][0];
$origin='https://web.push.apple.com';
ppd_check(vp3_phone_push_transport_vapid_claims_v1($send['vapid'],$origin,$authorization,time())!==null,'wire Authorization passes actual ES256 signature and audience validation');
$cache=$pdo->query('SELECT * FROM vp3_phone_push_vapid_cache_v1')->fetch(PDO::FETCH_ASSOC);
ppd_check(!str_contains(json_encode($cache),$authorization)&&!str_contains(json_encode($cache),$send['vapid']['private_key']),'cache persists no plaintext Authorization or signing key');
$second=$make('Second worker phone');$send2=$prepare($second);$result2=$dispatch($send2);
ppd_check($result2['state']==='accepted'&&count($wires)===2&&$wires[1]['headers']['Authorization'][0]===$authorization,'separate queue events reuse the identical protected VAPID token');
vp3_phone_push_delivery_finish_v1($pdo,$send2['claim'],$result2,time());

$loopPhone=$make('Worker loop phone','loop.push.apple.com');
vp3_phone_push_delivery_enqueue_v1($pdo,ppd_row($pdo,$loopPhone),['type'=>'test','id'=>bin2hex(random_bytes(16)),
    'revision'=>1,'expires_epoch'=>$now+600],$now,'vp3_phone_push_source_current_v1');
$beforeLoop=count($wires);
$loop=vp3_phone_push_delivery_run_v1($pdo,1,'vp3_phone_push_source_current_v1',
    static fn(array $sub,array $vapid,string $payload,array $options):array=>vp3_phone_push_worker_send_v1($pdo,$sub,$vapid,$payload,$options,$exchange),$now);
ppd_check($loop['attempted']===1&&($loop['states']['accepted']??0)===1&&count($wires)===$beforeLoop+1,'real queue loop supplies the complete cache/crypto bridge contract');
$wires=array_slice($wires,0,2); // Later rejection cases retain the original two-send baseline.

// Simulate withdrawal during the cache operation, after queue preparation but
// before its return. The production bridge must perform its final fence later.
$third=$make('Revoked during token lookup','region1.push.apple.com');$send3=$prepare($third);
$id=$third['device']['id'];
$pdo->exec("CREATE TRIGGER ppw_revoke_after_cache AFTER INSERT ON vp3_phone_push_vapid_cache_v1 WHEN NEW.origin='https://region1.push.apple.com' BEGIN UPDATE vp3_phone_devices_v1 SET device_status='revoked' WHERE id=".$pdo->quote($id).'; END');
$blocked=$dispatch($send3);$pdo->exec('DROP TRIGGER ppw_revoke_after_cache');
ppd_check(!$blocked['dispatched']&&count($wires)===2&&$blocked['code']==='dispatch_authority_changed','withdrawal observed after cache return blocks the provider request');
ppd_check(vp3_phone_push_delivery_event_v1($pdo,$send3['claim']['event_ref'])['state']==='cancelled','late authority fence leaves the old event cancelled');

$fourth=$make('Source withdrawn during lookup','region2.push.apple.com');$device=ppd_row($pdo,$fourth);$id=$device['id'];$profile=bin2hex(random_bytes(16));$exchangeId=bin2hex(random_bytes(16));
vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_communication_profiles_v1(owner_user_id,device_id,profile_id,revision,owner_requests,holder_requests,updated_epoch) VALUES(?,?,?,1,1,1,?)',[1,$id,$profile,$now]);
vp3_phone_information_owner_v1($pdo,1,'information_create',['device_id'=>$id,'generation'=>(int)$device['generation'],'profile_id'=>$profile,'profile_revision'=>1,'exchange_id'=>$exchangeId,'kind'=>'question','message'=>'Synthetic private request']);
$claim4=vp3_phone_push_delivery_claim_v1($pdo,$now,'vp3_phone_push_source_current_v1');
$send4=vp3_phone_push_delivery_prepare_v1($pdo,$claim4,$now,'vp3_phone_push_source_current_v1');
ppd_check(($send4['vapid']['config_revision']??null)===(int)$claim4['config_revision'],'canonical request preparation includes the current cache configuration revision');
$pdo->exec("CREATE TRIGGER ppw_cancel_source_after_cache AFTER INSERT ON vp3_phone_push_vapid_cache_v1 WHEN NEW.origin='https://region2.push.apple.com' BEGIN UPDATE vp3_phone_information_v1 SET state='canceled',version=2 WHERE device_id=".$pdo->quote($id).' AND exchange_id='.$pdo->quote($exchangeId).'; END');
$blockedSource=$dispatch($send4);$pdo->exec('DROP TRIGGER ppw_cancel_source_after_cache');
ppd_check(!$blockedSource['dispatched']&&count($wires)===2,'canonical source withdrawal during token lookup blocks the provider request');
ppd_check(vp3_phone_push_delivery_event_v1($pdo,$claim4['event_ref'])['last_code']==='source_unavailable','source withdrawal is recorded separately from provider failure');

$config=vp3_phone_push_delivery_config_v1($pdo);
vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>true,'subject'=>'mailto:changed@example.test','revision'=>(int)$config['revision']],$now);
$fifth=$make('Deferred subject phone');$send5=$prepare($fifth);$deferred=$dispatch($send5);
ppd_check($deferred['state']==='retry'&&!$deferred['dispatched']&&$deferred['code']==='vapid_refresh_deferred'&&$deferred['retry_after']>0&&count($wires)===2,'changed contact inside the reuse hour defers safely before network');
$persisted=vp3_phone_push_delivery_finish_v1($pdo,$send5['claim'],$deferred,time());
ppd_check(in_array($persisted['state'],['retry','expired'],true)&&$persisted['provider_accepted_epoch']===0,'safe authorization deferral never invents provider acceptance');
echo 'PASS worker cache/crypto bridge '.$GLOBALS['ppd_checks']." checks; no live network.\n";
