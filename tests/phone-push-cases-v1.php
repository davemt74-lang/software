<?php
declare(strict_types=1);

/** Same registry contract on SQLite and MySQL; no carrier or push-service I/O. */
function vp3_test_phone_push_v1(PDO $pdo,int $owner,int $other,callable $assert):void
{
    require_once dirname(__DIR__).'/includes/ai-settings.php';
    $key=random_bytes(32);$now=time();
    $reject=static function(int $code,callable $fn,string $label)use($assert){
        try{$fn();}catch(RuntimeException $e){$assert($e->getCode()===$code,$label.' ('.$e->getCode().')');return;}
        $assert(false,$label);
    };
    $cmd=static fn()=>['request_id'=>bin2hex(random_bytes(16))];
    $make=static function(int $uid,string $name,bool $approve=true)use($pdo,$cmd){
        $i=vp3_phone_owner_v1($pdo,$uid,'invite',$cmd())['invite'];
        $r=vp3_phone_device_v1($pdo,'','claim',['invite'=>$i,'name'=>$name,'claim_key'=>bin2hex(random_bytes(32))]);
        if($approve)$r['device']=vp3_phone_owner_v1($pdo,$uid,'approve',$cmd()+['device_id'=>$r['device']['id'],'generation'=>$r['device']['generation'],'role'=>'guest','allow_location'=>true])['device'];
        return $r;
    };
    $assert(vp3_phone_push_ready_v1($pdo),'push migration installed');
    $config=vp3_phone_push_config_v1($pdo);
    $assert($config['public_key']===''&&(int)$config['enrollment_enabled']===0,'enrollment defaults off and no key generated on load');
    $reject(403,fn()=>vp3_phone_push_configure_v1($pdo,['id'=>$owner,'role'=>'fan'],true,$now,$key),'ordinary member cannot prepare site VAPID credentials');
    $admin=['role'=>'admin']; // Isolated fixture principal; the production API supplies current_user().
    vp3_phone_push_configure_v1($pdo,$admin,true,$now,$key);
    $config=vp3_phone_push_config_v1($pdo);
    $private=json_decode(ai_decrypt_secret_with_key($config['private_cipher'],$key),true,8,JSON_THROW_ON_ERROR);
    $assert(vp3_phone_push_point_v1($config['public_key'])&&vp3_phone_push_decode_v1($private['private_key'],32)!==null
        &&!str_contains($config['private_cipher'],$private['private_key']),'valid VAPID point and encrypted private key');
    vp3_phone_push_configure_v1($pdo,$admin,true,$now+1,$key);
    $assert(vp3_phone_push_config_v1($pdo)['public_key']===$config['public_key'],'repeat setup does not rotate public key');
    $reject(503,fn()=>vp3_phone_push_configure_v1($pdo,$admin,true,$now,random_bytes(32)),'lost credential key cannot silently rotate VAPID identity');
    $idKey=hash('sha256',$config['public_key']);
    $a=$make($owner,'Push phone A');$b=$make($other,'Push phone B');$pending=$make($owner,'Pending push',false);
    $row=static fn(array $r):array=>vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_devices_v1 WHERE id=?',[$r['device']['id']])->fetch(PDO::FETCH_ASSOC);
    $act=static function(array $r,string $op,array $fields)use($pdo,$row,$now,$key):array{
        return vp3_phone_transaction_v1($pdo,static function()use($pdo,$r,$op,$fields,$row,$now,$key){
            $d=$row($r);vp3_phone_account_v1($pdo,(int)$d['owner_user_id'],true);
            $d=vp3_phone_row_v1($pdo,(int)$d['owner_user_id'],$d['id']);
            if(!hash_equals($d['token_hash'],vp3_extension_hash_v2000($r['token'])))throw new RuntimeException('Old phone token',403);
            return vp3_phone_push_holder_v1($pdo,$d,$op,$fields,$now,$key);
        });
    };
    $point=vp3_phone_push_keypair_v1()['public_key'];$auth=vp3_phone_push_b64_v1(random_bytes(16));
    $sub=['endpoint'=>'https://web.push.apple.com/QfixtureA_'.bin2hex(random_bytes(16)),'expirationTime'=>null,'keys'=>['p256dh'=>$point,'auth'=>$auth]];
    $args=['device_id'=>$a['device']['id'],'subscription_revision'=>0,'confirmed'=>true,'key_id'=>$idKey,'subscription'=>$sub];
    foreach(['http://web.push.apple.com/token','https://127.0.0.1/token','https://web.push.apple.com.evil.test/token','https://evil.test@web.push.apple.com/token','https://web.push.apple.com:443/token','https://web.push.apple.com/token?redirect=x','https://web.push.apple.com/a/%2e%2e/token','https://web.push.apple.com/token%0a','https://fcm.googleapis.com/not-a-push-token'] as $invalid){
        $reject(422,fn()=>$act($a,'push_register',array_replace($args,['subscription'=>array_replace($sub,['endpoint'=>$invalid])])),'untrusted endpoint rejected');
    }
    $reject(422,fn()=>$act($a,'push_register',array_replace($args,['subscription'=>array_replace($sub,['keys'=>['p256dh'=>vp3_phone_push_b64_v1("\x04".str_repeat("\0",64)),'auth'=>$auth]])])),'invalid curve point rejected');
    $reject(422,fn()=>$act($a,'push_register',array_replace($args,['subscription'=>array_replace($sub,['expirationTime'=>$now*1000])])),'expired browser subscription rejected');
    $reject(422,fn()=>$act($a,'push_register',array_replace($args,['confirmed'=>false])),'explicit holder confirmation required');
    $reject(403,fn()=>$act($pending,'push_register',array_replace($args,['device_id'=>$pending['device']['id']])),'pending pairing cannot enroll');
    $reject(403,fn()=>$act($b,'push_register',$args),'another holder cannot target this device');
    $saved=$act($a,'push_register',$args)['push'];
    $assert($saved['state']==='registered'&&$saved['revision']===1&&!$saved['delivery_ready'],'save registers one device without claiming delivery');
    $stored=vp3_phone_push_row_v1($pdo,$row($a));
    $payload=json_decode(ai_decrypt_secret_with_key($stored['subscription_cipher'],$key),true,8,JSON_THROW_ON_ERROR);
    $assert($payload['subscription']===$sub&&$payload['device_id']===$a['device']['id']&&$payload['credential_hash']===$row($a)['token_hash']
        &&!str_contains(json_encode($stored,JSON_THROW_ON_ERROR),$sub['endpoint'])&&!str_contains(json_encode($stored,JSON_THROW_ON_ERROR),$auth),'endpoint and auth secret encrypted with exact device binding');
    $public=vp3_phone_push_view_v1($pdo,$row($a),$now,false,$key);
    $assert(!isset($public['endpoint_hash'],$public['application_server_key'],$public['key_id'])&&!str_contains(json_encode($public),$auth),'owner receives only redacted registration state');
    $reject(409,fn()=>$act($a,'push_register',$args),'stale subscription revision denied');
    $again=$act($a,'push_register',array_replace($args,['subscription_revision'=>1]))['push'];
    $assert($again['revision']===1,'same registration with current revision is idempotent');
    $reject(409,fn()=>$act($b,'push_register',array_replace($args,['device_id'=>$b['device']['id']])),'endpoint cannot be claimed by another account');
    $subB=array_replace($sub,['endpoint'=>'https://fcm.googleapis.com/fcm/send/fixtureB_'.bin2hex(random_bytes(12))]);
    $sb=$act($b,'push_register',array_replace($args,['device_id'=>$b['device']['id'],'subscription'=>$subB]))['push'];
    $assert($sb['state']==='registered','different phone has independent subscription');
    $d=$a['device'];$gps=vp3_phone_device_v1($pdo,$a['token'],'start',['device_id'=>$d['id'],'generation'=>$d['generation']])['device'];
    $assert($gps['generation']>$d['generation']&&vp3_phone_push_view_v1($pdo,$row($a),$now,true,$key)['revision']===1,'GPS generation does not invalidate push enrollment');
    $off=vp3_phone_device_v1($pdo,$a['token'],'stop',['device_id'=>$d['id'],'generation'=>$gps['generation']])['device'];
    $disabled=$act($a,'push_disable',['device_id'=>$d['id'],'subscription_revision'=>1,'confirmed'=>true])['push'];
    $assert($disabled['state']==='off'&&$disabled['revision']===2&&vp3_phone_push_row_v1($pdo,$row($a))['subscription_cipher']===null,'holder withdrawal erases material and advances revision');
    $reject(409,fn()=>$act($a,'push_register',array_replace($args,['subscription_revision'=>1])),'stale enrollment cannot resurrect withdrawn permission');
    $a3=$act($a,'push_register',array_replace($args,['subscription_revision'=>2]))['push'];
    $assert($a3['revision']===3,'fresh holder consent can re-enroll');
    vp3_phone_push_configure_v1($pdo,$admin,false,$now,$key);
    $reject(503,fn()=>$act($a,'push_register',array_replace($args,['subscription_revision'=>3])),'pause closes new enrollment without carrier IO');
    $assert(vp3_phone_push_view_v1($pdo,$row($b),$now,true,$key)['state']==='registered','pausing enrollment preserves independent saved grant');
    // Actual installation handoff must clear the old subscription.
    $o=vp3_phone_device_v1($pdo,$a['token'],'handoff_offer',['device_id'=>$d['id'],'generation'=>$off['generation'],'confirmed'=>true])['handoff_ticket'];
    $target=['code'=>$o['code'],'claim_key'=>bin2hex(random_bytes(32)),'confirmed'=>true];
    $review=vp3_phone_handoff_target_v1($pdo,'','handoff_claim',$target,time())['handoff'];
    vp3_phone_handoff_owner_v1($pdo,['id'=>$owner],'handoff_approve',['device_id'=>$d['id'],'generation'=>$off['generation'],'transfer_id'=>$o['transfer_id'],'comparison_code'=>$review['comparison_code'],'confirmed'=>true],time());
    $new=vp3_phone_handoff_target_v1($pdo,'','handoff_finish',$target,time());
    $after=vp3_phone_push_row_v1($pdo,$row($a));
    $assert($after['state']==='off'&&$after['revision']===4&&$after['endpoint_hash']===null&&$after['subscription_cipher']===null,'installation move invalidates previous subscription even with same device ID');
    $reject(403,fn()=>$act($a,'push_disable',['device_id'=>$d['id'],'subscription_revision'=>4,'confirmed'=>true]),'old installation credential rejected');
    vp3_phone_owner_v1($pdo,$owner,'revoke',$cmd()+['device_id'=>$d['id'],'generation'=>$new['device']['generation']]);
    vp3_phone_owner_v1($pdo,$other,'revoke',$cmd()+['device_id'=>$b['device']['id'],'generation'=>$b['device']['generation']]);
    $assert(vp3_phone_push_row_v1($pdo,$row($b))['subscription_cipher']===null,'owner revoke destroys stored endpoint material');
    vp3_phone_owner_v1($pdo,$owner,'deny',$cmd()+['device_id'=>$pending['device']['id'],'generation'=>$pending['device']['generation']]);
}
