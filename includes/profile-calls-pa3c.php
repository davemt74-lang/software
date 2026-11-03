<?php
declare(strict_types=1);

/** Human Profile calls. Media credentials never enter a Chat message or a job receipt. */
require_once __DIR__.'/profile-agent-handoff-pa3.php';
require_once __DIR__.'/profile-agent-boundary-v390.php';
require_once __DIR__.'/profile-agent-public-service-v110.php';

const VP3_PROFILE_CALL_PA3C='vp3.profile-call.v1';
const VP3_PROFILE_CALL_OFFER_MS_PA3C=60000;
const VP3_PROFILE_CALL_CONNECT_MS_PA3C=120000;
const VP3_PROFILE_CALL_AUTH_MS_PA3C=30000;
const VP3_PROFILE_CALL_WORK_MS_PA3C=90000;

final class VP3ProfileCallException extends RuntimeException
{
    public function __construct(public readonly string $publicCode,string $message,public readonly int $httpStatus=409){parent::__construct($message,$httpStatus);}
}
function vp3_profile_call_error_pa3c(string $code='PROFILE_CALL_CONFLICT',int $status=409): never
{
    $text=match($code){
        'PROFILE_CALL_INVALID'=>'The call request is invalid.',
        'PROFILE_CALL_NOT_READY'=>'Profile calls are not available on this deployment.',
        'PROFILE_CALL_UNAVAILABLE'=>'This Profile call is not available.',
        'PROFILE_CALL_BUSY'=>'The owner already has a call or call cleanup in progress.',
        'PROFILE_CALL_NOT_FOUND'=>'The Profile call was not found.',
        'PROFILE_CALL_DELIVERY_REQUIRED'=>'Open the current call card before continuing.',
        default=>'The call changed. Refresh its current state before continuing.',
    };
    throw new VP3ProfileCallException($code,$text,$status);
}
if(!function_exists('vp3_profile_call_now_pa3c')){function vp3_profile_call_now_pa3c(): int{return vp3_profile_handoff_now_pa3();}}
function vp3_profile_call_id_pa3c(mixed $value,bool $zero=false): int
{
    if(!is_int($value)&&!(is_string($value)&&preg_match('/^(0|[1-9][0-9]{0,14})$/D',$value)))vp3_profile_call_error_pa3c('PROFILE_CALL_INVALID',422);
    $id=(int)$value;if($id<($zero?0:1)||$id>9007199254740991)vp3_profile_call_error_pa3c('PROFILE_CALL_INVALID',422);return $id;
}
function vp3_profile_call_token_pa3c(mixed $value): string
{
    if(!is_string($value)||!preg_match('/^[a-f0-9]{32}$/D',$value))vp3_profile_call_error_pa3c('PROFILE_CALL_INVALID',422);return $value;
}
function vp3_profile_call_schema_ready_pa3c(PDO $pdo): bool
{
    try{
        $pdo->query('SELECT id,owner_user_id,profile_agent_id,profile_session_id,conversation_id,visitor_user_id,credential_hash,authority_json,public_id,room_name,owner_identity,visitor_identity,provider_snapshot,provider_config_fingerprint,mode,offered_by,state,revision,offer_expires_at_ms,connect_until_ms,owner_client_hash,visitor_client_hash,owner_join_intent,visitor_join_intent,owner_authority_until_ms,visitor_authority_until_ms,provider_lease_token,provider_lease_kind,provider_lease_until_ms,revoke_before_sec,cleanup_not_before_ms,cleanup_run_id,cleanup_action_id,room_sid,provider_observed_at_ms,owner_connected,visitor_connected,created_at_ms,updated_at_ms FROM profile_calls_pa3c WHERE 1=0');
        $pdo->query('SELECT owner_user_id,active_call_id,revision,updated_at_ms FROM profile_call_slots_pa3c WHERE 1=0');
        $pdo->query('SELECT owner_user_id,actor_key,request_id,request_hash,call_id,operation,created_at_ms FROM profile_call_operations_pa3c WHERE 1=0');
        $pdo->query('SELECT delivery_id,owner_user_id,call_id,chat_conversation_id,chat_agent_id,chat_message_id FROM profile_call_deliveries_pa3c WHERE 1=0');return true;
    }catch(Throwable $e){return false;}
}
function vp3_profile_call_ensure_schema_pa3c(PDO $pdo): void
{
    if($pdo->inTransaction()){if(vp3_profile_call_schema_ready_pa3c($pdo))return;throw new RuntimeException('Profile call schema must initialize outside a transaction.');}
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';$auto=$sqlite?'INTEGER PRIMARY KEY AUTOINCREMENT':'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';$suffix=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_call_slots_pa3c(owner_user_id BIGINT PRIMARY KEY,active_call_id BIGINT NULL,revision BIGINT NOT NULL DEFAULT 1,updated_at_ms BIGINT NOT NULL)".$suffix);
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_calls_pa3c(
        id $auto,owner_user_id BIGINT NOT NULL,profile_agent_id BIGINT NOT NULL,profile_session_id BIGINT NOT NULL,conversation_id BIGINT NOT NULL,visitor_user_id BIGINT NOT NULL DEFAULT 0,
        credential_hash CHAR(64) NOT NULL,authority_json TEXT NOT NULL,public_id CHAR(32) NOT NULL UNIQUE,room_name VARCHAR(100) NOT NULL UNIQUE,owner_identity VARCHAR(80) NOT NULL,visitor_identity VARCHAR(80) NOT NULL,
        provider_snapshot TEXT NOT NULL,provider_config_fingerprint CHAR(64) NOT NULL,mode VARCHAR(12) NOT NULL,offered_by VARCHAR(12) NOT NULL,state VARCHAR(20) NOT NULL,revision BIGINT NOT NULL DEFAULT 1,
        offer_expires_at_ms BIGINT NOT NULL,accepted_at_ms BIGINT NULL,connect_until_ms BIGINT NULL,ended_at_ms BIGINT NULL,handoff_epoch BIGINT NOT NULL DEFAULT 0,
        owner_client_hash CHAR(64) NOT NULL DEFAULT '',visitor_client_hash CHAR(64) NOT NULL DEFAULT '',owner_join_intent CHAR(32) NOT NULL DEFAULT '',visitor_join_intent CHAR(32) NOT NULL DEFAULT '',
        owner_authority_until_ms BIGINT NOT NULL DEFAULT 0,visitor_authority_until_ms BIGINT NOT NULL DEFAULT 0,owner_token_issued_at_ms BIGINT NOT NULL DEFAULT 0,visitor_token_issued_at_ms BIGINT NOT NULL DEFAULT 0,
        provider_lease_token CHAR(32) NOT NULL DEFAULT '',provider_lease_kind VARCHAR(12) NOT NULL DEFAULT '',provider_lease_until_ms BIGINT NOT NULL DEFAULT 0,
        revoke_before_sec BIGINT NOT NULL DEFAULT 0,cleanup_not_before_ms BIGINT NOT NULL DEFAULT 0,cleanup_run_id BIGINT NULL,cleanup_action_id BIGINT NULL,
        room_sid VARCHAR(100) NOT NULL DEFAULT '',provider_observed_at_ms BIGINT NOT NULL DEFAULT 0,owner_connected INTEGER NOT NULL DEFAULT 0,visitor_connected INTEGER NOT NULL DEFAULT 0,
        last_reason VARCHAR(40) NOT NULL DEFAULT '',created_at_ms BIGINT NOT NULL,updated_at_ms BIGINT NOT NULL
    )".$suffix);
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_call_operations_pa3c(owner_user_id BIGINT NOT NULL,actor_key CHAR(64) NOT NULL,request_id CHAR(32) NOT NULL,request_hash CHAR(64) NOT NULL,call_id BIGINT NOT NULL,operation VARCHAR(20) NOT NULL,created_at_ms BIGINT NOT NULL,PRIMARY KEY(owner_user_id,actor_key,request_id))".$suffix);
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_call_deliveries_pa3c(delivery_id CHAR(32) PRIMARY KEY,owner_user_id BIGINT NOT NULL,call_id BIGINT NOT NULL,chat_conversation_id BIGINT NOT NULL,chat_agent_id BIGINT NOT NULL,chat_message_id BIGINT NOT NULL,created_at_ms BIGINT NOT NULL,UNIQUE(call_id,chat_conversation_id,chat_agent_id))".$suffix);
    foreach([
        ['profile_calls_pa3c','idx_profile_calls_owner_state','owner_user_id,state,id'],
        ['profile_calls_pa3c','idx_profile_calls_conversation','owner_user_id,conversation_id,id'],
        ['profile_calls_pa3c','idx_profile_calls_work','state,updated_at_ms,id'],
        ['profile_calls_pa3c','idx_profile_calls_cleanup','owner_user_id,cleanup_run_id,cleanup_action_id'],
        ['profile_call_deliveries_pa3c','idx_profile_calls_chat','owner_user_id,chat_conversation_id,chat_agent_id,call_id'],
    ] as [$table,$name,$columns]){
        $q=$pdo->prepare($sqlite?"SELECT name FROM sqlite_master WHERE type='index' AND name=?":'SELECT INDEX_NAME FROM information_schema.statistics WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');$q->execute($sqlite?[$name]:[$table,$name]);if(!$q->fetchColumn())$pdo->exec('CREATE INDEX '.$name.' ON '.$table.'('.$columns.')');
    }
}
function vp3_profile_call_require_pa3c(PDO $pdo): void
{
    if(!function_exists('agent_job_engine_schema_ready_v1900'))require_once __DIR__.'/agent-job-engine-v1900.php';
    if(!vp3_profile_call_schema_ready_pa3c($pdo)||!vp3_profile_handoff_schema_ready_pa3($pdo)||!agent_job_engine_schema_ready_v1900($pdo))vp3_profile_call_error_pa3c('PROFILE_CALL_NOT_READY',503);
}
function vp3_profile_call_provider_ready_pa3c(): array
{
    if(!function_exists('vp3_profile_call_livekit_readiness_pa3c'))require_once __DIR__.'/profile-calls-livekit-pa3c.php';return vp3_profile_call_livekit_readiness_pa3c();
}
function vp3_profile_call_ready_pa3c(?array $row=null): array
{
    $ready=vp3_profile_call_provider_ready_pa3c();if(($ready['ready']??false)!==true||!preg_match('/^[a-f0-9]{64}$/D',(string)($ready['config_fingerprint']??'')))vp3_profile_call_error_pa3c('PROFILE_CALL_NOT_READY',503);
    if($row&&!hash_equals((string)$row['provider_config_fingerprint'],$ready['config_fingerprint']))vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);return $ready;
}
function vp3_profile_call_row_pa3c(PDO $pdo,int $id,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM profile_calls_pa3c WHERE id=?'.($lock?vp3_profile_handoff_lock_pa3($pdo):''));$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);return $r?:null;
}
function vp3_profile_call_slot_pa3c(PDO $pdo,int $owner,bool $lock=false): ?array
{
    if($lock){if(!$pdo->inTransaction())throw new RuntimeException('Call ownership requires a transaction.');$sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';$pdo->prepare($sql.' INTO profile_call_slots_pa3c(owner_user_id,active_call_id,revision,updated_at_ms) VALUES(?,NULL,1,?)')->execute([$owner,vp3_profile_call_now_pa3c()]);}
    $q=$pdo->prepare('SELECT * FROM profile_call_slots_pa3c WHERE owner_user_id=?'.($lock?vp3_profile_handoff_lock_pa3($pdo):''));$q->execute([$owner]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function vp3_profile_call_fingerprint_pa3c(array $row,array $keys): string
{
    $values=[];foreach($keys as $key)$values[$key]=(string)($row[$key]??'');return hash('sha256',json_encode($values,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
/** No message is needed: the existing hidden conversation binding authenticates a native call. */
function vp3_profile_call_context_pa3c(PDO $pdo,int $owner,int $cid,bool $lock=false,?array $expected=null): array
{
    $conversation=vp3_profile_handoff_conversation_pa3($pdo,$owner,$cid,$lock);
    $q=$pdo->prepare("SELECT context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type='system' ORDER BY id LIMIT 2");$q->execute([$cid]);$bindings=$q->fetchAll(PDO::FETCH_COLUMN);
    if(count($bindings)!==1||strlen((string)$bindings[0])>4096)vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
    $binding=json_decode((string)$bindings[0],true);if(!vp3_profile_agent_conversation_authority_valid_v1(is_array($binding)?$binding:null,$owner)||$binding['transport']!=='native')vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
    $aid=(int)$conversation['profile_agent_id'];$sid=(int)$conversation['profile_session_id'];$visitor=(int)($conversation['visitor_user_id']??0);
    if($aid<1||$sid<1||$visitor===$owner||(int)($binding['profile_agent_id']??0)!==$aid||(int)($binding['profile_session_id']??0)!==$sid||(int)$binding['visitor_user_id']!==$visitor)vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
    $suffix=$lock?vp3_profile_handoff_lock_pa3($pdo):'';
    $q=$pdo->prepare('SELECT p.*,u.display_name,u.role,u.is_active FROM user_profiles p INNER JOIN users u ON u.id=p.user_id WHERE p.user_id=?'.$suffix);$q->execute([$owner]);$profile=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?'.$suffix);$q->execute([$owner]);$user=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT * FROM user_agents WHERE id=? AND owner_user_id=?'.$suffix);$q->execute([$aid,$owner]);$agent=$q->fetch(PDO::FETCH_ASSOC);
    if(!$profile||!$user||empty($user['is_active'])||empty($profile['is_public'])||empty($profile['profile_agent_enabled'])||(int)($profile['profile_agent_id']??0)!==$aid||!$agent||empty($agent['is_active']))vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
    if(!function_exists('vp3_observation_personal_permission_v1'))require_once __DIR__.'/agent-observation-authority-v1.php';
    foreach(['profile_agent.access','profile_chat.access'] as $permission)if(!vp3_observation_personal_permission_v1($pdo,$user,$permission))vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
    $q=$pdo->prepare('SELECT * FROM profile_visit_sessions WHERE id=? AND owner_user_id=?'.$suffix);$q->execute([$sid,$owner]);$session=$q->fetch(PDO::FETCH_ASSOC);if(!$session)vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
    $visitorRevision='';$visitorRow=null;
    if($visitor>0){$q=$pdo->prepare('SELECT id,is_active,password_hash,display_name FROM users WHERE id=?'.$suffix);$q->execute([$visitor]);$visitorRow=$q->fetch(PDO::FETCH_ASSOC);if(!$visitorRow||empty($visitorRow['is_active']))vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);$visitorRevision=hash('sha256',$visitor.'|'.(string)$visitorRow['password_hash']);}
    $snapshot=['contract'=>'vp3.profile-call-authority.v1','profile_fingerprint'=>vp3_profile_call_fingerprint_pa3c($profile,['user_id','username','display_name','bio','profile_agent_instructions','profile_agent_greeting']),
        'agent_fingerprint'=>vp3_profile_call_fingerprint_pa3c($agent,['id','owner_user_id','display_name','is_active']),'session_fingerprint'=>vp3_profile_call_fingerprint_pa3c($session,['id','owner_user_id','session_key']),'visitor_auth_revision'=>$visitorRevision];
    if($expected){
        foreach(['owner_user_id'=>$owner,'conversation_id'=>$cid,'profile_agent_id'=>$aid,'profile_session_id'=>$sid,'visitor_user_id'=>$visitor] as $key=>$value)if((int)($expected[$key]??-1)!==$value)vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
        if(!hash_equals((string)$expected['credential_hash'],$binding['credential_hash'])||!is_string($expected['authority_json'])||strlen($expected['authority_json'])>4096)vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
        $saved=json_decode($expected['authority_json'],true);if(!is_array($saved)||array_keys($saved)!==array_keys($snapshot))vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
        foreach($snapshot as $key=>$value)if(!is_string($saved[$key])||!hash_equals($saved[$key],$value))vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
    }
    return compact('conversation','binding','profile','user','agent','session','snapshot','visitorRow');
}
function vp3_profile_call_authorize_pa3c(PDO $pdo,string $role,array $auth,int $cid,array $input=[],?array $row=null,bool $lock=false): array
{
    if($role==='visitor'){
        if(($auth['conversation_authority']['transport']??'')!=='native')vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
        $auth=vp3_profile_agent_public_live_v1($pdo,$auth,$lock);$owner=(int)$auth['owner_user_id'];
        if(!vp3_profile_agent_public_conversation_v390($pdo,$cid,$owner,(int)$auth['agent_id'],(int)$auth['session_id'],$lock,$auth['conversation_authority']))vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
    }elseif($role==='owner'){
        $chat=vp3_profile_call_id_pa3c($input['chat_conversation_id']??null);$aid=vp3_profile_call_id_pa3c($input['chat_agent_id']??0,true);
        $auth=vp3_profile_handoff_owner_pa3($pdo,$auth,$chat,$aid);$owner=(int)$auth['id'];
    }else vp3_profile_call_error_pa3c('PROFILE_CALL_INVALID',422);
    $context=vp3_profile_call_context_pa3c($pdo,$owner,$cid,$lock,$row);
    if($role==='visitor'&&!hash_equals($context['binding']['credential_hash'],$auth['conversation_authority']['credential_hash']))vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
    return ['owner'=>$owner,'auth'=>$auth,'context'=>$context];
}
function vp3_profile_call_delivery_check_pa3c(PDO $pdo,array $row,array $input): array
{
    $id=vp3_profile_call_token_pa3c($input['delivery_id']??null);$cid=vp3_profile_call_id_pa3c($input['chat_conversation_id']??null);$aid=vp3_profile_call_id_pa3c($input['chat_agent_id']??0,true);
    $q=$pdo->prepare('SELECT d.*,m.context_json FROM profile_call_deliveries_pa3c d INNER JOIN chat_messages m ON m.id=d.chat_message_id AND m.conversation_id=d.chat_conversation_id WHERE d.delivery_id=? AND d.owner_user_id=? AND d.call_id=? AND d.chat_conversation_id=? AND d.chat_agent_id=?');$q->execute([$id,(int)$row['owner_user_id'],(int)$row['id'],$cid,$aid]);$delivery=$q->fetch(PDO::FETCH_ASSOC);
    $marker=$delivery?json_decode((string)$delivery['context_json'],true):null;$marker=is_array($marker)?($marker['profile_call']??null):null;
    if(!$delivery||!is_array($marker)||($marker['contract']??null)!==VP3_PROFILE_CALL_PA3C||(int)($marker['call_id']??0)!==(int)$row['id']||(int)($marker['conversation_id']??0)!==(int)$row['conversation_id'])vp3_profile_call_error_pa3c('PROFILE_CALL_DELIVERY_REQUIRED',409);return $delivery;
}
function vp3_profile_call_binding_pa3c(array $row): array
{
    return ['call_id'=>(int)$row['id'],'public_id'=>(string)$row['public_id'],'room_name'=>(string)$row['room_name'],'owner_identity'=>(string)$row['owner_identity'],'visitor_identity'=>(string)$row['visitor_identity'],'mode'=>(string)$row['mode'],'provider_config_fingerprint'=>(string)$row['provider_config_fingerprint'],'provider_snapshot'=>(string)$row['provider_snapshot'],'room_sid'=>(string)$row['room_sid'],'revoke_before_sec'=>(int)$row['revoke_before_sec']];
}
function vp3_profile_call_provider_pa3c(string $operation,array $row,array $options=[],?callable $provider=null): array
{
    if(!function_exists('vp3_profile_call_livekit_provider_pa3c')&&!$provider)require_once __DIR__.'/profile-calls-livekit-pa3c.php';
    $result=($provider??'vp3_profile_call_livekit_provider_pa3c')($operation,vp3_profile_call_binding_pa3c($row),$options);if(!is_array($result))throw new RuntimeException('Call provider proof is unavailable.');return $result;
}
function vp3_profile_call_projection_pa3c(array $row,string $role,bool $authorized=true): array
{
    $now=vp3_profile_call_now_pa3c();$state=(string)$row['state'];$expired=$state==='offered'&&$now>=(int)$row['offer_expires_at_ms'];$ready=in_array($state,['ready','active'],true);$ongoing=in_array($state,['preparing','ready','active'],true);
    $fresh=(int)$row['provider_observed_at_ms']<=$now&&(int)$row['provider_observed_at_ms']>=$now-15000&&$ready;
    $reason=in_array($row['last_reason'],['offer_expired','declined','cancelled','ended','authority_revoked','configuration_changed','provider_unavailable','provider_unsafe','connection_timeout','heartbeat_expired','cleanup_pending','cleanup_failed','provider_settling'],true)?$row['last_reason']:'';
    if($expired){$state='expired';$reason='offer_expired';}elseif($state==='active'&&!$fresh){$state='ready';$reason='verification_pending';}
    if(!$authorized)$reason='authority_revoked';
    return [
        'contract'=>VP3_PROFILE_CALL_PA3C,'call_id'=>(int)$row['id'],'revision'=>(int)$row['revision'],'conversation_id'=>(int)$row['conversation_id'],
        'role'=>$role,'offered_by'=>(string)$row['offered_by'],'mode'=>(string)$row['mode'],'state'=>$state,'server_now_ms'=>$now,
        'offer_expires_at_ms'=>(int)$row['offer_expires_at_ms'],'connect_until_ms'=>$row['connect_until_ms']===null?null:(int)$row['connect_until_ms'],
        'authority_valid_until_ms'=>(int)$row[$role.'_authority_until_ms'],'provider_observed_at_ms'=>(int)$row['provider_observed_at_ms'],
        'connection_verified'=>$fresh,'owner_connected'=>$fresh&&(int)$row['owner_connected']===1,'visitor_connected'=>$fresh&&(int)$row['visitor_connected']===1,
        'cleanup_pending'=>$row['state']==='ending','reason'=>$reason,
        'can_accept'=>$authorized&&$state==='offered'&&$row['offered_by']!==$role,
        'can_decline'=>$authorized&&$state==='offered'&&$row['offered_by']!==$role,
        'can_cancel'=>$authorized&&$state==='offered'&&$row['offered_by']===$role,
        'can_end'=>$authorized&&$ongoing,'can_join'=>$authorized&&$ready,
        'dispatch_due'=>$expired||$row['state']==='preparing'||$row['state']==='ending'||($ready&&(int)$row['provider_observed_at_ms']<=$now-10000),
    ];
}
function vp3_profile_call_owner_card_pa3c(PDO $pdo,array $row,array $input,bool $authorized=true): array
{
    $out=vp3_profile_call_projection_pa3c($row,'owner',$authorized);
    $q=$pdo->prepare('SELECT s.identity_disclosed,u.display_name FROM profile_visit_sessions s LEFT JOIN users u ON u.id=s.visitor_user_id WHERE s.id=? AND s.owner_user_id=?');$q->execute([(int)$row['profile_session_id'],(int)$row['owner_user_id']]);$session=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT display_name FROM user_agents WHERE id=? AND owner_user_id=?');$q->execute([(int)$row['profile_agent_id'],(int)$row['owner_user_id']]);$agent=$q->fetchColumn();
    $out['visitor_label']=!empty($session['identity_disclosed'])&&is_string($session['display_name']??null)?mb_substr(trim($session['display_name']),0,100):'Visitor';if($out['visitor_label']==='')$out['visitor_label']='Visitor';$out['agent_name']=is_string($agent)?mb_substr($agent,0,100):'Profile Agent';
    $out['chat_conversation_id']=(int)($input['chat_conversation_id']??0);$out['chat_agent_id']=(int)($input['chat_agent_id']??0);$out['chat_message_id']=0;$out['delivery_id']='';
    $q=$pdo->prepare('SELECT * FROM profile_call_deliveries_pa3c WHERE owner_user_id=? AND call_id=? AND chat_conversation_id=? AND chat_agent_id=?');$q->execute([(int)$row['owner_user_id'],(int)$row['id'],$out['chat_conversation_id'],$out['chat_agent_id']]);if($d=$q->fetch(PDO::FETCH_ASSOC)){$out['chat_message_id']=(int)$d['chat_message_id'];$out['delivery_id']=(string)$d['delivery_id'];}
    return $out;
}
function vp3_profile_call_reply_pa3c(PDO $pdo,array $row,string $role,array $input=[],string $request='',bool $duplicate=false): array
{
    $call=$role==='owner'?vp3_profile_call_owner_card_pa3c($pdo,$row,$input):vp3_profile_call_projection_pa3c($row,$role);
    return ['contract'=>VP3_PROFILE_CALL_PA3C,'call'=>$call,'call_id'=>(int)$row['id'],'revision'=>(int)$row['revision'],'conversation_id'=>(int)$row['conversation_id'],'request_id'=>$request,'duplicate'=>$duplicate];
}
function vp3_profile_call_visitor_state_pa3c(PDO $pdo,array $ctx,int $cid): array
{
    vp3_profile_call_require_pa3c($pdo);$cid=vp3_profile_call_id_pa3c($cid);$checked=vp3_profile_call_authorize_pa3c($pdo,'visitor',$ctx,$cid);$owner=$checked['owner'];
    $q=$pdo->prepare('SELECT * FROM profile_calls_pa3c WHERE owner_user_id=? AND conversation_id=? ORDER BY id DESC LIMIT 5');$q->execute([$owner,$cid]);$calls=[];$pending=false;
    $ready=vp3_profile_call_provider_ready_pa3c();
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){vp3_profile_call_context_pa3c($pdo,$owner,$cid,false,$row);$card=vp3_profile_call_projection_pa3c($row,'visitor');$calls[]=$card;if(in_array($card['state'],['offered','preparing','ready','active','ending'],true))$pending=true;}
    $slot=vp3_profile_call_slot_pa3c($pdo,$owner);$busy=!empty($slot['active_call_id']);$reachable=vp3_profile_handoff_reachable_pa3($pdo,$owner);
    $out=['contract'=>VP3_PROFILE_CALL_PA3C,'calls'=>$calls,'can_offer'=>($ready['ready']??false)===true&&!$pending&&!$busy&&$reachable,'reason'=>$busy?'owner_busy':(!$reachable?'owner_unavailable':(($ready['ready']??false)?'':(string)($ready['reason']??'not_configured'))),'server_now_ms'=>vp3_profile_call_now_pa3c(),'conversation_id'=>$cid];
    vp3_profile_call_authorize_pa3c($pdo,'visitor',$checked['auth'],$cid);return $out;
}
function vp3_profile_call_owner_list_pa3c(PDO $pdo,array $user,array $input=[]): array
{
    vp3_profile_call_require_pa3c($pdo);$chat=vp3_profile_call_id_pa3c($input['chat_conversation_id']??0,true);$aid=vp3_profile_call_id_pa3c($input['chat_agent_id']??0,true);$cid=vp3_profile_call_id_pa3c($input['conversation_id']??0,true);$live=vp3_profile_handoff_owner_pa3($pdo,$user,$chat,$aid);$owner=(int)$live['id'];
    $out=['contract'=>VP3_PROFILE_CALL_PA3C,'calls'=>[],'can_offer'=>false,'reason'=>'','server_now_ms'=>vp3_profile_call_now_pa3c(),'conversation_id'=>$cid];if(!$chat)return $out;
    $sql="SELECT c.* FROM profile_calls_pa3c c WHERE c.owner_user_id=? AND (c.state IN ('offered','preparing','ready','active','ending') OR EXISTS(SELECT 1 FROM profile_call_deliveries_pa3c d WHERE d.call_id=c.id AND d.owner_user_id=c.owner_user_id AND d.chat_conversation_id=? AND d.chat_agent_id=?))"; $params=[$owner,$chat,$aid];if($cid){$sql.=' AND c.conversation_id=?';$params[]=$cid;}$sql.=' ORDER BY c.id DESC LIMIT 20';$q=$pdo->prepare($sql);$q->execute($params);$pending=false;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$valid=true;try{vp3_profile_call_context_pa3c($pdo,$owner,(int)$row['conversation_id'],false,$row);vp3_profile_call_ready_pa3c($row);}catch(VP3ProfileCallException $e){$valid=false;}$card=vp3_profile_call_owner_card_pa3c($pdo,$row,$input,$valid);$out['calls'][]=$card;if((int)$row['conversation_id']===$cid&&in_array($card['state'],['offered','preparing','ready','active','ending'],true))$pending=true;}
    if($cid){try{vp3_profile_call_authorize_pa3c($pdo,'owner',$live,$cid,$input);vp3_profile_call_ready_pa3c();$slot=vp3_profile_call_slot_pa3c($pdo,$owner);$out['can_offer']=!$pending&&empty($slot['active_call_id']);if(!empty($slot['active_call_id']))$out['reason']='owner_busy';}catch(VP3ProfileCallException $e){$out['reason']='unavailable';}}
    vp3_profile_handoff_owner_pa3($pdo,$live,$chat,$aid);return $out;
}
function vp3_profile_call_owner_delivery_pa3c(PDO $pdo,array $user,array $input): array
{
    vp3_profile_call_require_pa3c($pdo);$id=vp3_profile_call_id_pa3c($input['call_id']??null);$revision=vp3_profile_call_id_pa3c($input['revision']??null);$chat=vp3_profile_call_id_pa3c($input['chat_conversation_id']??null);$aid=vp3_profile_call_id_pa3c($input['chat_agent_id']??0,true);$live=vp3_profile_handoff_owner_pa3($pdo,$user,$chat,$aid);$owner=(int)$live['id'];
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$live,$owner,$id,$revision,$chat,$aid,$input){
        vp3_profile_call_slot_pa3c($pdo,$owner,true);$row=vp3_profile_call_row_pa3c($pdo,$id);if(!$row||(int)$row['owner_user_id']!==$owner)vp3_profile_call_error_pa3c('PROFILE_CALL_NOT_FOUND',404);
        vp3_profile_handoff_conversation_pa3($pdo,$owner,(int)$row['conversation_id'],true);$row=vp3_profile_call_row_pa3c($pdo,$id,true);if((int)$row['revision']!==$revision)vp3_profile_call_error_pa3c();
        $q=$pdo->prepare('SELECT * FROM profile_call_deliveries_pa3c WHERE call_id=? AND owner_user_id=? AND chat_conversation_id=? AND chat_agent_id=?');$q->execute([$id,$owner,$chat,$aid]);$delivery=$q->fetch(PDO::FETCH_ASSOC);
        if(!$delivery){$marker=['sources'=>[],'profile_call'=>['contract'=>VP3_PROFILE_CALL_PA3C,'call_id'=>$id,'revision'=>$revision,'conversation_id'=>(int)$row['conversation_id']]];$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at) VALUES(?,NULL,'assistant','Profile visitor call.',?,?)")->execute([$chat,json_encode($marker,JSON_THROW_ON_ERROR),vp3_profile_handoff_date_pa3()]);$message=(int)$pdo->lastInsertId();$deliveryId=bin2hex(random_bytes(16));$pdo->prepare('INSERT INTO profile_call_deliveries_pa3c(delivery_id,owner_user_id,call_id,chat_conversation_id,chat_agent_id,chat_message_id,created_at_ms) VALUES(?,?,?,?,?,?,?)')->execute([$deliveryId,$owner,$id,$chat,$aid,$message,vp3_profile_call_now_pa3c()]);$pdo->prepare('UPDATE chat_conversations SET updated_at=? WHERE id=? AND user_id=?')->execute([vp3_profile_handoff_date_pa3(),$chat,$owner]);$delivery=['delivery_id'=>$deliveryId,'chat_message_id'=>$message];}
        vp3_profile_call_delivery_check_pa3c($pdo,$row,$input+['delivery_id'=>$delivery['delivery_id']]);$result=vp3_profile_call_reply_pa3c($pdo,$row,'owner',$input);$result+=['delivery_id'=>(string)$delivery['delivery_id'],'chat_conversation_id'=>$chat,'chat_agent_id'=>$aid,'chat_message_id'=>(int)$delivery['chat_message_id'],'message_id'=>(int)$delivery['chat_message_id']];vp3_profile_handoff_owner_pa3($pdo,$live,$chat,$aid);return $result;
    });
}
/** Caller holds owner slot, public conversation and thread. This creates no visitor message or turn. */
function vp3_profile_call_takeover_pa3c(PDO $pdo,int $owner,int $cid): array
{
    $thread=vp3_profile_handoff_thread_pa3($pdo,$owner,$cid,true);
    if(!$thread){$pdo->prepare("INSERT INTO profile_agent_handoff_conversations_pa3(conversation_id,owner_user_id,mode,epoch,updated_at_ms) VALUES(?,?,'owner',1,?)")->execute([$cid,$owner,vp3_profile_call_now_pa3c()]);}
    return vp3_profile_handoff_mode_pa3($pdo,$owner,$cid,'owner');
}
function vp3_profile_call_cleanup_enqueue_pa3c(PDO $pdo,array $row): array
{
    if(!$pdo->inTransaction())throw new RuntimeException('Call closure requires a transaction.');
    if((int)($row['cleanup_run_id']??0)>0){vp3_profile_call_cleanup_job_pa3c($pdo,$row,true);return $row;}
    $owner=(int)$row['owner_user_id'];$key='profile-call:'.$row['id'].':cleanup';$when=(int)$row['cleanup_not_before_ms']>vp3_profile_call_now_pa3c()?vp3_profile_handoff_date_pa3((int)$row['cleanup_not_before_ms']):null;
    $pdo->prepare("INSERT INTO agent_workflow_runs(owner_user_id,agent_id,workflow_type,origin,source_kind,source_key,source_hash,dedupe_key,title,goal,decision_summary,status,risk_level,requires_approval,approval_status,execution_target,capability_key,max_attempts,retry_backoff_seconds,timeout_seconds,next_attempt_at,current_action_id) VALUES(?,?,'profile_call_cleanup_pa3c','profile','profile_call_cleanup_pa3c',?,?,?,'Close Profile call','Revoke the exact human call participants and remove the bound room.','Cleanup is limited to the originally authorized room.','approved','low',0,'not_required','cloud','profile.call.cleanup',20,5,90,?,NULL)")->execute([$owner,(int)$row['profile_agent_id'],$key,sha1($key),hash('sha256',$key),$when]);$run=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO agent_workflow_actions(run_id,owner_user_id,sequence_no,action_key,action_type,label,summary,status,requires_approval,execution_target,capability_key,max_attempts,timeout_seconds,available_at) VALUES(?,?,1,?,'execute','Close Profile call','Revoke both participants and verify the exact room is absent.','queued',0,'cloud','profile.call.cleanup',20,90,?)")->execute([$run,$owner,$key,$when]);$action=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE profile_calls_pa3c SET cleanup_run_id=?,cleanup_action_id=? WHERE id=? AND owner_user_id=?')->execute([$run,$action,(int)$row['id'],$owner]);return vp3_profile_call_row_pa3c($pdo,(int)$row['id'],true)??throw new RuntimeException('Call cleanup was not saved.');
}
function vp3_profile_call_end_locked_pa3c(PDO $pdo,array $row,string $reason='ended'): array
{
    if(in_array($row['state'],['ended','declined','cancelled','expired','failed'],true))return $row;
    $now=vp3_profile_call_now_pa3c();
    if($row['state']==='offered'){$state=in_array($reason,['declined','cancelled'],true)?$reason:($reason==='offer_expired'?'expired':'cancelled');$pdo->prepare('UPDATE profile_calls_pa3c SET state=?,revision=revision+1,last_reason=?,ended_at_ms=?,updated_at_ms=? WHERE id=?')->execute([$state,$reason,$now,$now,(int)$row['id']]);return vp3_profile_call_row_pa3c($pdo,(int)$row['id'],true);}
    $settle=(string)$row['provider_lease_token']!==''?max((int)$row['cleanup_not_before_ms'],(int)$row['provider_lease_until_ms']+5000):(int)$row['cleanup_not_before_ms'];
    $pdo->prepare("UPDATE profile_calls_pa3c SET state='ending',revision=revision+1,owner_authority_until_ms=0,visitor_authority_until_ms=0,owner_connected=0,visitor_connected=0,cleanup_not_before_ms=?,revoke_before_sec=?,last_reason=?,updated_at_ms=? WHERE id=? AND owner_user_id=?")->execute([$settle,max((int)$row['revoke_before_sec'],intdiv($now,1000)+1),$reason,$now,(int)$row['id'],(int)$row['owner_user_id']]);
    return vp3_profile_call_cleanup_enqueue_pa3c($pdo,vp3_profile_call_row_pa3c($pdo,(int)$row['id'],true));
}
function vp3_profile_call_visitor_action_pa3c(PDO $pdo,array $ctx,array $input): array{return vp3_profile_call_action_pa3c($pdo,'visitor',$ctx,$input);}
function vp3_profile_call_owner_action_pa3c(PDO $pdo,array $user,array $input): array{return vp3_profile_call_action_pa3c($pdo,'owner',$user,$input);}
function vp3_profile_call_action_pa3c(PDO $pdo,string $role,array $auth,array $input): array
{
    vp3_profile_call_require_pa3c($pdo);$op=$input['operation']??null;if(!in_array($op,['offer','accept','decline','cancel','end','heartbeat'],true))vp3_profile_call_error_pa3c('PROFILE_CALL_INVALID',422);
    $cid=vp3_profile_call_id_pa3c($input['conversation_id']??null);$id=$op==='offer'?0:vp3_profile_call_id_pa3c($input['call_id']??null);$revision=$op==='offer'?0:vp3_profile_call_id_pa3c($input['revision']??null);$request=$op==='heartbeat'?'':vp3_profile_call_token_pa3c($input['request_id']??null);
    $mode=$op==='offer'?($input['mode']??null):null;if($op==='offer'&&!in_array($mode,['audio','video'],true))vp3_profile_call_error_pa3c('PROFILE_CALL_INVALID',422);
    $checked=vp3_profile_call_authorize_pa3c($pdo,$role,$auth,$cid,$input);$owner=$checked['owner'];$auth=$checked['auth'];$actor=hash('sha256',$role.'|'.$owner.'|'.($role==='visitor'?$checked['context']['binding']['credential_hash']:'owner'));
    $hash=hash('sha256',json_encode([$role,$cid,$id,$revision,$op,$mode,(int)($input['chat_conversation_id']??0),(int)($input['chat_agent_id']??0)],JSON_THROW_ON_ERROR));
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$role,$auth,$input,$checked,$owner,$cid,$id,$revision,$request,$op,$mode,$actor,$hash){
        $slot=vp3_profile_call_slot_pa3c($pdo,$owner,true);vp3_profile_handoff_conversation_pa3($pdo,$owner,$cid,true);vp3_profile_handoff_thread_pa3($pdo,$owner,$cid,true);$now=vp3_profile_call_now_pa3c();
        if($request!==''){$q=$pdo->prepare('SELECT * FROM profile_call_operations_pa3c WHERE owner_user_id=? AND actor_key=? AND request_id=?');$q->execute([$owner,$actor,$request]);if($prior=$q->fetch(PDO::FETCH_ASSOC)){if(!hash_equals($prior['request_hash'],$hash))vp3_profile_call_error_pa3c();$row=vp3_profile_call_row_pa3c($pdo,(int)$prior['call_id'],true);if(!$row)vp3_profile_call_error_pa3c();vp3_profile_call_authorize_pa3c($pdo,$role,$auth,$cid,$input,$row,true);return vp3_profile_call_reply_pa3c($pdo,$row,$role,$input,$request,true);}}
        if($op==='offer'){
            vp3_profile_call_ready_pa3c();if(!empty($slot['active_call_id']))vp3_profile_call_error_pa3c('PROFILE_CALL_BUSY',409);
            if($role==='visitor'&&!vp3_profile_handoff_reachable_pa3($pdo,$owner))vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
            $q=$pdo->prepare("SELECT * FROM profile_calls_pa3c WHERE owner_user_id=? AND conversation_id=? AND state IN ('offered','preparing','ready','active','ending') ORDER BY id DESC".vp3_profile_handoff_lock_pa3($pdo));$q->execute([$owner,$cid]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $pending){if($pending['state']==='offered'&&$now>=(int)$pending['offer_expires_at_ms'])vp3_profile_call_end_locked_pa3c($pdo,$pending,'offer_expired');else vp3_profile_call_error_pa3c();}
            $context=vp3_profile_call_authorize_pa3c($pdo,$role,$auth,$cid,$input,null,true)['context'];$snapshot=vp3_profile_call_livekit_snapshot_pa3c();
            if(!is_string($snapshot['encrypted']??null)||$snapshot['encrypted']===''||strlen($snapshot['encrypted'])>32768||!preg_match('/^[a-f0-9]{64}$/D',(string)($snapshot['config_fingerprint']??'')))vp3_profile_call_error_pa3c('PROFILE_CALL_NOT_READY',503);
            $public=bin2hex(random_bytes(16));$conversation=$context['conversation'];
            $pdo->prepare("INSERT INTO profile_calls_pa3c(owner_user_id,profile_agent_id,profile_session_id,conversation_id,visitor_user_id,credential_hash,authority_json,public_id,room_name,owner_identity,visitor_identity,provider_snapshot,provider_config_fingerprint,mode,offered_by,state,offer_expires_at_ms,created_at_ms,updated_at_ms) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'offered',?,?,?)")->execute([$owner,(int)$conversation['profile_agent_id'],(int)$conversation['profile_session_id'],$cid,(int)($conversation['visitor_user_id']??0),$context['binding']['credential_hash'],json_encode($context['snapshot'],JSON_THROW_ON_ERROR),$public,'vp3-profile-'.$public,'owner-'.$public,'visitor-'.$public,$snapshot['encrypted'],$snapshot['config_fingerprint'],$mode,$role,$now+VP3_PROFILE_CALL_OFFER_MS_PA3C,$now,$now]);$row=vp3_profile_call_row_pa3c($pdo,(int)$pdo->lastInsertId(),true);
        }else{
            $row=vp3_profile_call_row_pa3c($pdo,$id,true);if(!$row||(int)$row['owner_user_id']!==$owner||(int)$row['conversation_id']!==$cid)vp3_profile_call_error_pa3c('PROFILE_CALL_NOT_FOUND',404);
            vp3_profile_call_authorize_pa3c($pdo,$role,$auth,$cid,$input,$row,true);if($role==='owner')vp3_profile_call_delivery_check_pa3c($pdo,$row,$input);if((int)$row['revision']!==$revision)vp3_profile_call_error_pa3c();
            if(in_array($op,['accept','decline','cancel'],true)){
                if($row['state']!=='offered'||$now>=(int)$row['offer_expires_at_ms']||(($op==='cancel')!==($row['offered_by']===$role)))vp3_profile_call_error_pa3c();
                if($op==='accept'){
                    vp3_profile_call_ready_pa3c($row);if(!empty($slot['active_call_id']))vp3_profile_call_error_pa3c('PROFILE_CALL_BUSY',409);
                    $thread=vp3_profile_call_takeover_pa3c($pdo,$owner,$cid);
                    $pdo->prepare("UPDATE profile_calls_pa3c SET state='preparing',revision=revision+1,accepted_at_ms=?,connect_until_ms=?,handoff_epoch=?,last_reason='',updated_at_ms=? WHERE id=?")->execute([$now,$now+VP3_PROFILE_CALL_CONNECT_MS_PA3C,(int)$thread['epoch'],$now,$id]);
                    $pdo->prepare('UPDATE profile_call_slots_pa3c SET active_call_id=?,revision=revision+1,updated_at_ms=? WHERE owner_user_id=?')->execute([$id,$now,$owner]);
                }else $row=vp3_profile_call_end_locked_pa3c($pdo,$row,$op==='decline'?'declined':'cancelled');
            }elseif($op==='end'){
                if(!in_array($row['state'],['preparing','ready','active','ending'],true))vp3_profile_call_error_pa3c();if($row['state']!=='ending')$row=vp3_profile_call_end_locked_pa3c($pdo,$row,'ended');
            }elseif($op==='heartbeat'){
                vp3_profile_call_ready_pa3c($row);$instance=vp3_profile_call_token_pa3c($input['client_instance_id']??null);
                if(!in_array($row['state'],['ready','active'],true)||(int)$row[$role.'_authority_until_ms']<$now||!hash_equals($row[$role.'_client_hash'],hash('sha256',$instance)))vp3_profile_call_error_pa3c();
                $pdo->prepare('UPDATE profile_calls_pa3c SET '.$role.'_authority_until_ms=?,updated_at_ms=? WHERE id=?')->execute([$now+VP3_PROFILE_CALL_AUTH_MS_PA3C,$now,$id]);
            }
            $row=vp3_profile_call_row_pa3c($pdo,$id,true);
        }
        if($request!=='')$pdo->prepare('INSERT INTO profile_call_operations_pa3c(owner_user_id,actor_key,request_id,request_hash,call_id,operation,created_at_ms) VALUES(?,?,?,?,?,?,?)')->execute([$owner,$actor,$request,$hash,(int)$row['id'],$op,$now]);
        $result=vp3_profile_call_reply_pa3c($pdo,$row,$role,$input,$request);vp3_profile_call_authorize_pa3c($pdo,$role,$auth,$cid,$input,$row,true);if(in_array($op,['offer','accept','heartbeat'],true))vp3_profile_call_ready_pa3c($row);return $result;
    });
}
