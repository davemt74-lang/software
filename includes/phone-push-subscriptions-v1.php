<?php
declare(strict_types=1);

/** PS5C.3 enrollment only. No notification transport or arbitrary URL fetch. */
function vp3_phone_push_install_v1(PDO $pdo,string $options):void
{
    if($pdo->inTransaction())throw new LogicException('Install phone push outside transactions.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_push_config_v1 (
        id SMALLINT PRIMARY KEY,enrollment_enabled SMALLINT NOT NULL DEFAULT 0,
        public_key VARCHAR(90) NOT NULL DEFAULT '',private_cipher TEXT NULL,
        created_epoch BIGINT NOT NULL DEFAULT 0,updated_epoch BIGINT NOT NULL DEFAULT 0
    )".$options);
    $insert=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
    $pdo->exec($insert.' INTO vp3_phone_push_config_v1(id) VALUES(1)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_push_subscriptions_v1 (
        owner_user_id INT UNSIGNED NOT NULL,device_id CHAR(32) NOT NULL,
        revision BIGINT NOT NULL,state VARCHAR(16) NOT NULL,
        credential_hash CHAR(64) NULL,endpoint_hash CHAR(64) NULL UNIQUE,
        key_id CHAR(64) NULL,payload_hash CHAR(64) NULL,subscription_cipher TEXT NULL,
        expires_epoch BIGINT NULL,created_epoch BIGINT NOT NULL,updated_epoch BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,device_id),
        FOREIGN KEY(owner_user_id,device_id) REFERENCES vp3_phone_devices_v1(owner_user_id,id) ON DELETE CASCADE
    )".$options);
}
function vp3_phone_push_ready_v1(PDO $pdo):bool
{
    try{
        $pdo->query('SELECT id,enrollment_enabled,public_key,private_cipher FROM vp3_phone_push_config_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,device_id,revision,state,credential_hash,endpoint_hash,key_id,payload_hash,subscription_cipher,expires_epoch FROM vp3_phone_push_subscriptions_v1 WHERE 1=0');
        return true;
    }catch(PDOException){return false;}
}
function vp3_phone_push_need_v1(bool $ok,int $status=422):void
{if(!$ok)throw new RuntimeException('Notification subscription unavailable.', $status);}
function vp3_phone_push_b64_v1(string $raw):string{return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');}
function vp3_phone_push_decode_v1(mixed $encoded,int $length):?string
{
    if(!is_string($encoded)||strlen($encoded)>120||preg_match('/^[A-Za-z0-9_-]+$/D',$encoded)!==1)return null;
    $raw=base64_decode(strtr($encoded,'-_','+/'),true);
    return is_string($raw)&&strlen($raw)===$length&&hash_equals(vp3_phone_push_b64_v1($raw),$encoded)?$raw:null;
}
/** Validate actual P-256 public point with the platform crypto library. */
function vp3_phone_push_point_v1(mixed $encoded):bool
{
    $raw=vp3_phone_push_decode_v1($encoded,65);
    if($raw===null||$raw[0]!=="\x04"||!function_exists('openssl_pkey_get_public'))return false;
    // DER SubjectPublicKeyInfo for id-ecPublicKey / prime256v1.
    $der=hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$raw;
    $pem="-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n";
    $key=@openssl_pkey_get_public($pem);
    if($key===false)return false;
    $p=openssl_pkey_get_details($key);$ec=$p['ec']??[];
    return ($ec['curve_name']??null)==='prime256v1'&&is_string($ec['x']??null)&&is_string($ec['y']??null)
        &&hash_equals($raw,"\x04".str_pad($ec['x'],32,"\0",STR_PAD_LEFT).str_pad($ec['y'],32,"\0",STR_PAD_LEFT));
}
function vp3_phone_push_keypair_v1():array
{
    vp3_phone_push_need_v1(function_exists('openssl_pkey_new')&&defined('OPENSSL_KEYTYPE_EC'),503);
    $k=@openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
    vp3_phone_push_need_v1($k!==false,503);
    $details=openssl_pkey_get_details($k);$ec=$details['ec']??[];
    vp3_phone_push_need_v1(($ec['curve_name']??null)==='prime256v1'&&isset($ec['x'],$ec['y'],$ec['d']),503);
    $public=vp3_phone_push_b64_v1("\x04".str_pad($ec['x'],32,"\0",STR_PAD_LEFT).str_pad($ec['y'],32,"\0",STR_PAD_LEFT));
    $private=vp3_phone_push_b64_v1(str_pad($ec['d'],32,"\0",STR_PAD_LEFT));
    vp3_phone_push_need_v1(vp3_phone_push_point_v1($public)&&vp3_phone_push_decode_v1($private,32)!==null,503);
    return ['public_key'=>$public,'private_key'=>$private];
}
function vp3_phone_push_config_v1(PDO $pdo,bool $lock=false):?array
{
    if(!vp3_phone_push_ready_v1($pdo))return null;
    $q=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_push_config_v1 WHERE id=1'.($lock?vp3_phone_lock_v1($pdo):''),[]);
    $r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $r?:null;
}
/** Production reuses the existing protected AI credential key, never auto-recreates it. */
function vp3_phone_push_master_v1(?string $testKey=null):?string
{
    if($testKey!==null)return strlen($testKey)===32?$testKey:null;
    if(!function_exists('ai_master_key'))return null;
    try{$key=ai_master_key(false);return is_string($key)&&strlen($key)===32?$key:null;}catch(Throwable){return null;}
}
function vp3_phone_push_config_valid_v1(?array $c,?string $key):bool
{
    if(!$c||!$key||!vp3_phone_push_point_v1($c['public_key']??null)||!is_string($c['private_cipher']??null)||!function_exists('ai_decrypt_secret_with_key'))return false;
    try{
        $p=json_decode(ai_decrypt_secret_with_key($c['private_cipher'],$key),true);
        return is_array($p)&&($p['public_key']??null)===$c['public_key']&&vp3_phone_push_decode_v1($p['private_key']??null,32)!==null;
    }catch(Throwable){return false;}
}
function vp3_phone_push_configure_v1(PDO $pdo,array $admin,bool $enabled,int $now,?string $testKey=null):void
{
    vp3_phone_push_need_v1(has_permission('users.manage',$admin)&&(!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null),403);
    vp3_phone_push_need_v1(vp3_phone_push_ready_v1($pdo),503);
    vp3_phone_transaction_v1($pdo,function()use($pdo,$enabled,$now,$testKey){
        $c=vp3_phone_push_config_v1($pdo,true);vp3_phone_push_need_v1(is_array($c),503);
        if(!$enabled){
            vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_push_config_v1 SET enrollment_enabled=0,updated_epoch=? WHERE id=1',[$now]);
            return [];
        }
        $key=vp3_phone_push_master_v1($testKey);
        vp3_phone_push_need_v1($key!==null&&function_exists('ai_encrypt_secret_with_key'),503);
        if($c['public_key']===''&&empty($c['private_cipher'])){
            $p=vp3_phone_push_keypair_v1();
            $encrypted=ai_encrypt_secret_with_key(json_encode($p,JSON_THROW_ON_ERROR),$key);
            vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_push_config_v1 SET public_key=?,private_cipher=?,created_epoch=?,updated_epoch=?,enrollment_enabled=1 WHERE id=1',
                [$p['public_key'],$encrypted,$now,$now]);
        }else{
            // Never rotate a configured key implicitly, including after key loss.
            vp3_phone_push_need_v1(vp3_phone_push_config_valid_v1($c,$key),503);
            vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_push_config_v1 SET enrollment_enabled=1,updated_epoch=? WHERE id=1',[$now]);
        }
        return [];
    });
}
function vp3_phone_push_admin_status_v1(PDO $pdo):array
{
    $c=vp3_phone_push_config_v1($pdo);
    if(!$c)return ['state'=>'upgrade_required','enrollment_enabled'=>false,'registered_count'=>0,'delivery_ready'=>false];
    $valid=vp3_phone_push_config_valid_v1($c,vp3_phone_push_master_v1());
    $count=(int)$pdo->query("SELECT COUNT(*) FROM vp3_phone_push_subscriptions_v1 s JOIN vp3_phone_devices_v1 d ON d.id=s.device_id AND d.owner_user_id=s.owner_user_id AND d.token_hash=s.credential_hash WHERE s.state='registered' AND d.device_status='approved' AND d.expires_at>".time()." AND (s.expires_epoch IS NULL OR s.expires_epoch>".time().")")->fetchColumn();
    return ['state'=>$valid?'key_ready':($c['public_key']===''?'not_configured':'credential_unavailable'),
        'enrollment_enabled'=>$valid&&(int)$c['enrollment_enabled']===1,'registered_count'=>$count,'delivery_ready'=>false];
}
/** Only known provider HTTPS origins; no credentials, port overrides, fragments,
 * escaped controls, query params or IP literals. Sender must revalidate and
 * enforce public DNS/TLS/no redirects again in the separate transport phase.
 */
function vp3_phone_push_endpoint_v1(mixed $raw):?string
{
    if(!is_string($raw)||strlen($raw)<24||strlen($raw)>2048||preg_match('/[^\x21-\x7e]|\\\\|%0[0-9a-f]|%1[0-9a-f]|%7f/i',$raw))return null;
    $p=parse_url($raw);
    if(!is_array($p)||($p['scheme']??null)!=='https'||isset($p['user'],$p['pass'])||array_intersect(array_keys($p),['user','pass','port','query','fragment']))return null;
    $host=$p['host']??'';$path=$p['path']??'';
    if(!is_string($host)||!is_string($path)||$host!==strtolower($host)||!preg_match('#^/[A-Za-z0-9._~%/+=:-]+$#D',$path))return null;
    // Limit the first release to verified common browser push providers.
    $apple=$host==='web.push.apple.com'||preg_match('/^[a-z0-9-]+\.push\.apple\.com$/D',$host)===1;
    $fcm=$host==='fcm.googleapis.com'&&(str_starts_with($path,'/fcm/send/')||str_starts_with($path,'/wp/'));
    $mozilla=$host==='updates.push.services.mozilla.com'&&str_starts_with($path,'/wpush/');
    if(!$apple&&!$fcm&&!$mozilla)return null;
    if(str_contains($path,'//')||preg_match('~(?:^|/)(?:\.{1,2}|%2e(?:%2e)?)(?:/|$)~i',$path))return null;
    return 'https://'.$host.$path===$raw?$raw:null;
}
function vp3_phone_push_parse_v1(mixed $input,int $now):array
{
    vp3_phone_push_need_v1(is_array($input)&&!array_is_list($input)&&!array_diff(array_keys($input),['endpoint','expirationTime','keys']));
    $endpoint=vp3_phone_push_endpoint_v1($input['endpoint']??null);$keys=$input['keys']??null;$expiry=$input['expirationTime']??null;
    vp3_phone_push_need_v1($endpoint!==null&&is_array($keys)&&!array_is_list($keys)&&!array_diff(array_keys($keys),['p256dh','auth'])
        &&vp3_phone_push_point_v1($keys['p256dh']??null)&&vp3_phone_push_decode_v1($keys['auth']??null,16)!==null);
    vp3_phone_push_need_v1($expiry===null||(is_int($expiry)&&$expiry>=$now*1000+1000&&$expiry<=9007199254740991));
    return ['endpoint'=>$endpoint,'expirationTime'=>$expiry,'keys'=>['p256dh'=>$keys['p256dh'],'auth'=>$keys['auth']]];
}
function vp3_phone_push_row_v1(PDO $pdo,array $d,bool $lock=false):?array
{
    $q=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_push_subscriptions_v1 WHERE owner_user_id=? AND device_id=?'.($lock?vp3_phone_lock_v1($pdo):''),[(int)$d['owner_user_id'],$d['id']]);
    $s=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $s?:null;
}
function vp3_phone_push_view_v1(PDO $pdo,array $d,int $now,bool $holder=true,?string $testKey=null):?array
{
    if(!vp3_phone_push_ready_v1($pdo)||$d['device_status']!=='approved'||(int)$d['expires_at']<=$now)return null;
    $c=vp3_phone_push_config_v1($pdo);$s=vp3_phone_push_row_v1($pdo,$d);
    $valid=vp3_phone_push_config_valid_v1($c,vp3_phone_push_master_v1($testKey));
    $keyId=$valid?hash('sha256',$c['public_key']):'';
    $registered=$s&&$s['state']==='registered'&&is_string($s['credential_hash'])&&hash_equals($d['token_hash'],$s['credential_hash'])
        &&($s['expires_epoch']===null||(int)$s['expires_epoch']>$now)&&$valid&&$s['key_id']===$keyId;
    $out=['revision'=>(int)($s['revision']??0),'state'=>$registered?'registered':(($s&&$s['state']==='registered')?'unavailable':'off'),
        'enrollment_enabled'=>$valid&&(int)$c['enrollment_enabled']===1,'delivery_ready'=>false,
        'registered_epoch'=>$registered?(int)$s['updated_epoch']:0];
    if($holder&&$valid){
        $out['key_id']=$keyId;$out['application_server_key']=$c['public_key'];
        if($registered)$out['endpoint_hash']=$s['endpoint_hash'];
    }
    return $out;
}
/** Under the owner/device transaction. Revision advances independently of GPS. */
function vp3_phone_push_revoke_v1(PDO $pdo,array $d,int $now):void
{
    if(!vp3_phone_push_ready_v1($pdo))return;
    vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_push_subscriptions_v1 SET revision=revision+1,state='off',credential_hash=NULL,endpoint_hash=NULL,key_id=NULL,payload_hash=NULL,subscription_cipher=NULL,expires_epoch=NULL,updated_epoch=? WHERE owner_user_id=? AND device_id=? AND state<>'off'",
        [$now,(int)$d['owner_user_id'],$d['id']]);
}
function vp3_phone_push_holder_v1(PDO $pdo,array $d,string $action,array $input,int $now,?string $testKey=null):array
{
    vp3_phone_push_need_v1(vp3_phone_push_ready_v1($pdo),503);
    $fields=['device_id','subscription_revision','confirmed'];
    if($action==='push_register')$fields=array_merge($fields,['key_id','subscription']);
    vp3_phone_push_need_v1(in_array($action,['push_register','push_disable'],true)&&!array_diff(array_keys($input),$fields)&&($input['confirmed']??null)===true);
    vp3_phone_push_need_v1($d['device_status']==='approved'&&(int)$d['expires_at']>$now&&($input['device_id']??null)===$d['id'],403);
    $s=vp3_phone_push_row_v1($pdo,$d,true);$rev=(int)($s['revision']??0);
    vp3_phone_push_need_v1(is_int($input['subscription_revision']??null)&&$input['subscription_revision']===$rev&&$rev<9007199254740990,409);
    if($action==='push_disable'){
        if(!$s){vp3_phone_sql_v1($pdo,"INSERT INTO vp3_phone_push_subscriptions_v1(owner_user_id,device_id,revision,state,created_epoch,updated_epoch) VALUES(?,?,1,'off',?,?)",[(int)$d['owner_user_id'],$d['id'],$now,$now]);}
        elseif($s['state']==='off')vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_push_subscriptions_v1 SET revision=revision+1,updated_epoch=? WHERE owner_user_id=? AND device_id=?',[$now,(int)$d['owner_user_id'],$d['id']]);
        else vp3_phone_push_revoke_v1($pdo,$d,$now);
        return ['push'=>vp3_phone_push_view_v1($pdo,$d,$now,true,$testKey)];
    }
    $c=vp3_phone_push_config_v1($pdo,true);$key=vp3_phone_push_master_v1($testKey);
    vp3_phone_push_need_v1(vp3_phone_push_config_valid_v1($c,$key)&&(int)$c['enrollment_enabled']===1,503);
    $keyId=hash('sha256',$c['public_key']);vp3_phone_push_need_v1(($input['key_id']??null)===$keyId,409);
    $sub=vp3_phone_push_parse_v1($input['subscription']??null,$now);
    $endpointHash=hash('sha256',$sub['endpoint']);$payloadHash=hash('sha256',json_encode($sub,JSON_THROW_ON_ERROR));
    if($s&&$s['state']==='registered'&&$s['credential_hash']===$d['token_hash']&&$s['key_id']===$keyId&&$s['payload_hash']===$payloadHash)
        return ['push'=>vp3_phone_push_view_v1($pdo,$d,$now,true,$testKey)];
    // Endpoint ownership is global. Never silently steal another pairing's endpoint.
    $q=vp3_phone_sql_v1($pdo,'SELECT owner_user_id,device_id FROM vp3_phone_push_subscriptions_v1 WHERE endpoint_hash=?'.vp3_phone_lock_v1($pdo),[$endpointHash]);
    $other=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    vp3_phone_push_need_v1(!$other||((int)$other['owner_user_id']===(int)$d['owner_user_id']&&$other['device_id']===$d['id']),409);
    $next=$rev+1;$expires=$sub['expirationTime']===null?null:intdiv($sub['expirationTime'],1000);
    $payload=['owner_user_id'=>(int)$d['owner_user_id'],'device_id'=>$d['id'],'credential_hash'=>$d['token_hash'],'revision'=>$next,'key_id'=>$keyId,'subscription'=>$sub];
    $cipher=ai_encrypt_secret_with_key(json_encode($payload,JSON_THROW_ON_ERROR),$key);
    if(!$s){
        vp3_phone_sql_v1($pdo,"INSERT INTO vp3_phone_push_subscriptions_v1(owner_user_id,device_id,revision,state,credential_hash,endpoint_hash,key_id,payload_hash,subscription_cipher,expires_epoch,created_epoch,updated_epoch) VALUES(?,?,?,'registered',?,?,?,?,?,?,?,?)",
            [(int)$d['owner_user_id'],$d['id'],$next,$d['token_hash'],$endpointHash,$keyId,$payloadHash,$cipher,$expires,$now,$now]);
    }else{
        $q=vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_push_subscriptions_v1 SET revision=?,state='registered',credential_hash=?,endpoint_hash=?,key_id=?,payload_hash=?,subscription_cipher=?,expires_epoch=?,updated_epoch=? WHERE owner_user_id=? AND device_id=? AND revision=?",
            [$next,$d['token_hash'],$endpointHash,$keyId,$payloadHash,$cipher,$expires,$now,(int)$d['owner_user_id'],$d['id'],$rev]);
        vp3_phone_push_need_v1($q->rowCount()===1,409);
    }
    return ['push'=>vp3_phone_push_view_v1($pdo,$d,$now,true,$testKey)];
}
