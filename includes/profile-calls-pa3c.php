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
    public function __construct(public readonly string $publicCode,string $message,public readonly int $httpStatus=409,public readonly int $retryAfterSeconds=0){parent::__construct($message,$httpStatus);}
}
function vp3_profile_call_error_pa3c(string $code='PROFILE_CALL_CONFLICT',int $status=409): never
{
    $text=match($code){
        'PROFILE_CALL_INVALID'=>'The call request is invalid.',
        'PROFILE_CALL_NOT_READY'=>'Profile calls are not available on this deployment.',
        'PROFILE_CALL_UNAVAILABLE'=>'This Profile call is not available.',
        'PROFILE_CALL_BUSY'=>'The owner already has a call or call cleanup in progress.',
        'PROFILE_CALL_IN_USE'=>'This call is already open in another tab.',
        'PROFILE_CALL_RATE_LIMITED'=>'Too many recent call requests. Wait before trying again.',
        'PROFILE_CALL_WORK_PENDING'=>'The call is preparing another media operation. Try joining again shortly.',
        'PROFILE_CALL_MEDIA_UNAVAILABLE'=>'The media service is temporarily unavailable. The call has not been joined.',
        'PROFILE_CALL_NOT_FOUND'=>'The Profile call was not found.',
        'PROFILE_CALL_DELIVERY_REQUIRED'=>'Open the current call card before continuing.',
        default=>'The call changed. Refresh its current state before continuing.',
    };
    throw new VP3ProfileCallException($code,$text,$status,$code==='PROFILE_CALL_RATE_LIMITED'?300:($code==='PROFILE_CALL_WORK_PENDING'?2:0));
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
        $pdo->query('SELECT id,owner_user_id,profile_agent_id,profile_session_id,conversation_id,visitor_user_id,credential_hash,authority_json,binding_hash,public_id,room_name,owner_identity,visitor_identity,provider_snapshot,provider_config_fingerprint,mode,offered_by,state,revision,offer_expires_at_ms,connect_until_ms,owner_client_hash,visitor_client_hash,owner_join_intent,visitor_join_intent,owner_authority_until_ms,visitor_authority_until_ms,provider_started_at_ms,provider_lease_token,provider_lease_kind,provider_lease_until_ms,revoke_before_sec,cleanup_not_before_ms,cleanup_run_id,cleanup_action_id,cleanup_generation,cleanup_revision,cleanup_proof_json,room_sid,provider_observed_at_ms,owner_connected,visitor_connected,created_at_ms,updated_at_ms FROM profile_calls_pa3c WHERE 1=0');
        $pdo->query('SELECT owner_user_id,active_call_id,revision,updated_at_ms FROM profile_call_slots_pa3c WHERE 1=0');
        $pdo->query('SELECT owner_user_id,actor_key,request_id,request_hash,call_id,operation,result_json,created_at_ms FROM profile_call_operations_pa3c WHERE 1=0');
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
        credential_hash CHAR(64) NOT NULL,authority_json TEXT NOT NULL,binding_hash CHAR(64) NOT NULL DEFAULT '',public_id CHAR(32) NOT NULL UNIQUE,room_name VARCHAR(100) NOT NULL UNIQUE,owner_identity VARCHAR(80) NOT NULL,visitor_identity VARCHAR(80) NOT NULL,
        provider_snapshot TEXT NOT NULL,provider_config_fingerprint CHAR(64) NOT NULL,mode VARCHAR(12) NOT NULL,offered_by VARCHAR(12) NOT NULL,state VARCHAR(20) NOT NULL,revision BIGINT NOT NULL DEFAULT 1,
        offer_expires_at_ms BIGINT NOT NULL,accepted_at_ms BIGINT NULL,connect_until_ms BIGINT NULL,ended_at_ms BIGINT NULL,handoff_epoch BIGINT NOT NULL DEFAULT 0,
        owner_client_hash CHAR(64) NOT NULL DEFAULT '',visitor_client_hash CHAR(64) NOT NULL DEFAULT '',owner_join_intent CHAR(32) NOT NULL DEFAULT '',visitor_join_intent CHAR(32) NOT NULL DEFAULT '',
        owner_authority_until_ms BIGINT NOT NULL DEFAULT 0,visitor_authority_until_ms BIGINT NOT NULL DEFAULT 0,owner_token_issued_at_ms BIGINT NOT NULL DEFAULT 0,visitor_token_issued_at_ms BIGINT NOT NULL DEFAULT 0,
        provider_started_at_ms BIGINT NOT NULL DEFAULT 0,provider_lease_token CHAR(32) NOT NULL DEFAULT '',provider_lease_kind VARCHAR(12) NOT NULL DEFAULT '',provider_lease_until_ms BIGINT NOT NULL DEFAULT 0,
        revoke_before_sec BIGINT NOT NULL DEFAULT 0,cleanup_not_before_ms BIGINT NOT NULL DEFAULT 0,cleanup_run_id BIGINT NULL,cleanup_action_id BIGINT NULL,cleanup_generation BIGINT NOT NULL DEFAULT 0,cleanup_revision BIGINT NOT NULL DEFAULT 0,cleanup_proof_json TEXT NULL,
        room_sid VARCHAR(100) NOT NULL DEFAULT '',provider_observed_at_ms BIGINT NOT NULL DEFAULT 0,owner_connected INTEGER NOT NULL DEFAULT 0,visitor_connected INTEGER NOT NULL DEFAULT 0,
        last_reason VARCHAR(40) NOT NULL DEFAULT '',created_at_ms BIGINT NOT NULL,updated_at_ms BIGINT NOT NULL
    )".$suffix);
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_call_operations_pa3c(owner_user_id BIGINT NOT NULL,actor_key CHAR(64) NOT NULL,request_id CHAR(32) NOT NULL,request_hash CHAR(64) NOT NULL,call_id BIGINT NOT NULL,operation VARCHAR(20) NOT NULL,result_json TEXT NULL,created_at_ms BIGINT NOT NULL,PRIMARY KEY(owner_user_id,actor_key,request_id))".$suffix);
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_call_deliveries_pa3c(delivery_id CHAR(32) PRIMARY KEY,owner_user_id BIGINT NOT NULL,call_id BIGINT NOT NULL,chat_conversation_id BIGINT NOT NULL,chat_agent_id BIGINT NOT NULL,chat_message_id BIGINT NOT NULL,created_at_ms BIGINT NOT NULL,UNIQUE(call_id,chat_conversation_id,chat_agent_id))".$suffix);
    // These additions also support an interrupted first installation of this release.
    foreach([['profile_calls_pa3c','binding_hash',"CHAR(64) NOT NULL DEFAULT ''"],['profile_calls_pa3c','provider_started_at_ms','BIGINT NOT NULL DEFAULT 0'],['profile_calls_pa3c','cleanup_generation','BIGINT NOT NULL DEFAULT 0'],['profile_calls_pa3c','cleanup_revision','BIGINT NOT NULL DEFAULT 0'],['profile_calls_pa3c','cleanup_proof_json','TEXT NULL'],['profile_call_operations_pa3c','result_json','TEXT NULL']] as [$table,$column,$definition]){
        try{$pdo->query('SELECT '.$column.' FROM '.$table.' WHERE 1=0');}catch(PDOException $e){$pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition);}
    }
    foreach([
        ['profile_calls_pa3c','idx_profile_calls_owner_state','owner_user_id,state,id'],
        ['profile_calls_pa3c','idx_profile_calls_conversation','owner_user_id,conversation_id,id'],
        ['profile_calls_pa3c','idx_profile_calls_work','state,updated_at_ms,id'],
        ['profile_calls_pa3c','idx_profile_calls_cleanup','owner_user_id,cleanup_run_id,cleanup_action_id'],
        ['profile_calls_pa3c','idx_profile_calls_admission','owner_user_id,created_at_ms'],
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
function vp3_profile_call_owner_busy_pa3c(PDO $pdo,int $owner): bool
{
    $slot=vp3_profile_call_slot_pa3c($pdo,$owner);if(!empty($slot['active_call_id']))return true;
    $q=$pdo->prepare("SELECT id FROM profile_calls_pa3c WHERE owner_user_id=? AND state='ending' LIMIT 1");$q->execute([$owner]);return(bool)$q->fetchColumn();
}
function vp3_profile_call_fingerprint_pa3c(array $row,array $keys): string
{
    $values=[];foreach($keys as $key)$values[$key]=(string)($row[$key]??'');return hash('sha256',json_encode($values,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
/** Reuse the installed social block ledger without invoking its schema-writing wrapper. */
function vp3_profile_call_block_check_pa3c(PDO $pdo,int $owner,int $visitor,bool $lock=false): void
{
    if($visitor<1)return;
    try{$q=$pdo->prepare('SELECT blocker_user_id,blocked_user_id FROM user_blocks WHERE (blocker_user_id=? AND blocked_user_id=?) OR (blocker_user_id=? AND blocked_user_id=?) LIMIT 1'.($lock?vp3_profile_handoff_lock_pa3($pdo):''));$q->execute([$owner,$visitor,$visitor,$owner]);$blocked=$q->fetch(PDO::FETCH_ASSOC);}catch(PDOException $e){vp3_profile_call_error_pa3c('PROFILE_CALL_NOT_READY',503);}
    if($blocked)vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
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
    $snapshot=['contract'=>'vp3.profile-call-authority.v1','profile_fingerprint'=>vp3_profile_call_fingerprint_pa3c($profile,['user_id','username','profile_agent_id']),
        'agent_fingerprint'=>vp3_profile_call_fingerprint_pa3c($agent,['id','owner_user_id']),'session_fingerprint'=>vp3_profile_call_fingerprint_pa3c($session,['id','owner_user_id','session_key']),'visitor_auth_revision'=>$visitorRevision];
    if($expected){
        foreach(['owner_user_id'=>$owner,'conversation_id'=>$cid,'profile_agent_id'=>$aid,'profile_session_id'=>$sid,'visitor_user_id'=>$visitor] as $key=>$value)if((int)($expected[$key]??-1)!==$value)vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
        if(!hash_equals((string)$expected['credential_hash'],$binding['credential_hash'])||!is_string($expected['authority_json'])||strlen($expected['authority_json'])>4096)vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
        $saved=json_decode($expected['authority_json'],true);if(!is_array($saved)||array_keys($saved)!==array_keys($snapshot))vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
        foreach($snapshot as $key=>$value)if(!is_string($saved[$key])||!hash_equals($saved[$key],$value))vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
    }
    vp3_profile_call_block_check_pa3c($pdo,$owner,$visitor,$lock);return compact('conversation','binding','profile','user','agent','session','snapshot','visitorRow');
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
function vp3_profile_call_binding_hash_pa3c(array $row): string
{
    return vp3_profile_call_fingerprint_pa3c($row,['id','owner_user_id','profile_agent_id','profile_session_id','conversation_id','visitor_user_id','credential_hash','authority_json','public_id','room_name','owner_identity','visitor_identity','provider_snapshot','provider_config_fingerprint','mode','offered_by','created_at_ms']);
}
function vp3_profile_call_integrity_pa3c(array $row): void
{
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)($row['binding_hash']??''))||!hash_equals($row['binding_hash'],vp3_profile_call_binding_hash_pa3c($row))||!preg_match('/^[a-f0-9]{32}$/D',(string)$row['public_id'])||$row['room_name']!=='vp3-profile-'.$row['public_id']||$row['owner_identity']!=='owner-'.$row['public_id']||$row['visitor_identity']!=='visitor-'.$row['public_id'])vp3_profile_call_error_pa3c();
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
    $reason=in_array($row['last_reason'],['offer_expired','declined','cancelled','ended','authority_revoked','configuration_changed','provider_unavailable','provider_unsafe','connection_timeout','heartbeat_expired','cleanup_pending','cleanup_failed','provider_settling','late_provider_result'],true)?$row['last_reason']:'';
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
        'can_end'=>$authorized&&$ongoing,'can_join'=>$authorized&&$ready,'can_return_to_agent'=>false,
        'dispatch_due'=>$expired||$row['state']==='preparing'||$row['state']==='ending'||($ready&&(int)$row['provider_observed_at_ms']<=$now-10000),
    ];
}
function vp3_profile_call_can_return_pa3c(PDO $pdo,array $row): bool
{
    if(!in_array($row['state'],['ending','ended','failed'],true)||(int)$row['handoff_epoch']<1)return false;
    $thread=vp3_profile_handoff_thread_pa3($pdo,(int)$row['owner_user_id'],(int)$row['conversation_id']);return $thread&&$thread['mode']==='owner'&&(int)$thread['epoch']===(int)$row['handoff_epoch'];
}
function vp3_profile_call_owner_card_pa3c(PDO $pdo,array $row,array $input,bool $authorized=true): array
{
    $out=vp3_profile_call_projection_pa3c($row,'owner',$authorized);
    $out['can_return_to_agent']=$authorized&&vp3_profile_call_can_return_pa3c($pdo,$row);
    $q=$pdo->prepare('SELECT s.identity_disclosed,s.visitor_user_id,u.display_name FROM profile_visit_sessions s LEFT JOIN users u ON u.id=? WHERE s.id=? AND s.owner_user_id=?');$q->execute([(int)$row['visitor_user_id'],(int)$row['profile_session_id'],(int)$row['owner_user_id']]);$session=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT display_name FROM user_agents WHERE id=? AND owner_user_id=?');$q->execute([(int)$row['profile_agent_id'],(int)$row['owner_user_id']]);$agent=$q->fetchColumn();
    $out['visitor_label']=(int)$row['visitor_user_id']>0&&(int)($session['visitor_user_id']??0)===(int)$row['visitor_user_id']&&!empty($session['identity_disclosed'])&&is_string($session['display_name']??null)?mb_substr(trim($session['display_name']),0,100):'Visitor';if($out['visitor_label']==='')$out['visitor_label']='Visitor';$out['agent_name']=is_string($agent)?mb_substr($agent,0,100):'Profile Agent';
    $out['chat_conversation_id']=(int)($input['chat_conversation_id']??0);$out['chat_agent_id']=(int)($input['chat_agent_id']??0);$out['chat_message_id']=0;$out['delivery_id']='';
    $q=$pdo->prepare('SELECT * FROM profile_call_deliveries_pa3c WHERE owner_user_id=? AND call_id=? AND chat_conversation_id=? AND chat_agent_id=?');$q->execute([(int)$row['owner_user_id'],(int)$row['id'],$out['chat_conversation_id'],$out['chat_agent_id']]);if($d=$q->fetch(PDO::FETCH_ASSOC)){$out['chat_message_id']=(int)$d['chat_message_id'];$out['delivery_id']=(string)$d['delivery_id'];}
    return $out;
}
function vp3_profile_call_reply_pa3c(PDO $pdo,array $row,string $role,array $input=[],string $request='',bool $duplicate=false): array
{
    $call=$role==='owner'?vp3_profile_call_owner_card_pa3c($pdo,$row,$input):vp3_profile_call_projection_pa3c($row,$role);$call['can_return_to_agent']=vp3_profile_call_can_return_pa3c($pdo,$row);
    return ['contract'=>VP3_PROFILE_CALL_PA3C,'call'=>$call,'call_id'=>(int)$row['id'],'revision'=>(int)$row['revision'],'conversation_id'=>(int)$row['conversation_id'],'request_id'=>$request,'duplicate'=>$duplicate];
}
function vp3_profile_call_visitor_state_pa3c(PDO $pdo,array $ctx,int $cid): array
{
    vp3_profile_call_require_pa3c($pdo);$cid=vp3_profile_call_id_pa3c($cid);$checked=vp3_profile_call_authorize_pa3c($pdo,'visitor',$ctx,$cid);$owner=$checked['owner'];
    $q=$pdo->prepare('SELECT * FROM profile_calls_pa3c WHERE owner_user_id=? AND conversation_id=? ORDER BY id DESC LIMIT 5');$q->execute([$owner,$cid]);$calls=[];$pending=false;
    $ready=vp3_profile_call_provider_ready_pa3c();
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$valid=true;try{vp3_profile_call_context_pa3c($pdo,$owner,$cid,false,$row);}catch(VP3ProfileCallException $e){$valid=false;}$card=vp3_profile_call_projection_pa3c($row,'visitor',$valid);$card['can_return_to_agent']=$valid&&vp3_profile_call_can_return_pa3c($pdo,$row);try{vp3_profile_call_ready_pa3c($row);}catch(VP3ProfileCallException $e){$card['can_accept']=false;$card['can_join']=false;if($valid)$card['reason']='configuration_changed';}$calls[]=$card;if(in_array($card['state'],['offered','preparing','ready','active','ending'],true))$pending=true;}
    $busy=vp3_profile_call_owner_busy_pa3c($pdo,$owner);$reachable=vp3_profile_handoff_reachable_pa3($pdo,$owner);
    $out=['contract'=>VP3_PROFILE_CALL_PA3C,'calls'=>$calls,'can_offer'=>($ready['ready']??false)===true&&!$pending&&!$busy&&$reachable,'reason'=>$busy?'owner_busy':(!$reachable?'owner_unavailable':(($ready['ready']??false)?'':(string)($ready['reason']??'not_configured'))),'server_now_ms'=>vp3_profile_call_now_pa3c(),'conversation_id'=>$cid];
    vp3_profile_call_authorize_pa3c($pdo,'visitor',$checked['auth'],$cid);return $out;
}
function vp3_profile_call_owner_list_pa3c(PDO $pdo,array $user,array $input=[]): array
{
    vp3_profile_call_require_pa3c($pdo);$chat=vp3_profile_call_id_pa3c($input['chat_conversation_id']??0,true);$aid=vp3_profile_call_id_pa3c($input['chat_agent_id']??0,true);$cid=vp3_profile_call_id_pa3c($input['conversation_id']??0,true);$live=vp3_profile_handoff_owner_pa3($pdo,$user,$chat,$aid);$owner=(int)$live['id'];
    $out=['contract'=>VP3_PROFILE_CALL_PA3C,'calls'=>[],'can_offer'=>false,'reason'=>'','server_now_ms'=>vp3_profile_call_now_pa3c(),'conversation_id'=>$cid];if(!$chat)return $out;
    $sql="SELECT c.* FROM profile_calls_pa3c c WHERE c.owner_user_id=? AND (c.state IN ('offered','preparing','ready','active','ending') OR EXISTS(SELECT 1 FROM profile_call_deliveries_pa3c d WHERE d.call_id=c.id AND d.owner_user_id=c.owner_user_id AND d.chat_conversation_id=? AND d.chat_agent_id=?))"; $params=[$owner,$chat,$aid];if($cid){$sql.=' AND c.conversation_id=?';$params[]=$cid;}$sql.=' ORDER BY c.id DESC LIMIT 20';$q=$pdo->prepare($sql);$q->execute($params);$pending=false;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$valid=true;try{vp3_profile_call_context_pa3c($pdo,$owner,(int)$row['conversation_id'],false,$row);}catch(VP3ProfileCallException $e){$valid=false;}$card=vp3_profile_call_owner_card_pa3c($pdo,$row,$input,$valid);try{vp3_profile_call_ready_pa3c($row);}catch(VP3ProfileCallException $e){$card['can_accept']=false;$card['can_join']=false;if($valid)$card['reason']='configuration_changed';}$out['calls'][]=$card;if((int)$row['conversation_id']===$cid&&in_array($card['state'],['offered','preparing','ready','active','ending'],true))$pending=true;}
    if($cid){try{vp3_profile_call_authorize_pa3c($pdo,'owner',$live,$cid,$input);vp3_profile_call_ready_pa3c();$busy=vp3_profile_call_owner_busy_pa3c($pdo,$owner);$out['can_offer']=!$pending&&!$busy;if($busy)$out['reason']='owner_busy';}catch(VP3ProfileCallException $e){$out['reason']='unavailable';}}
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
    $liveStatus=(string)($thread['live_status']??'none');if(in_array($liveStatus,['waiting','accepted'],true))$liveStatus='ended';
    vp3_profile_agent_response_token_v1($pdo,$cid,true);
    return vp3_profile_handoff_mode_pa3($pdo,$owner,$cid,'owner',$liveStatus,'auto');
}
function vp3_profile_call_cleanup_enqueue_pa3c(PDO $pdo,array $row): array
{
    if(!$pdo->inTransaction())throw new RuntimeException('Call closure requires a transaction.');
    if((int)($row['cleanup_run_id']??0)>0){vp3_profile_call_cleanup_job_pa3c($pdo,$row,true);return $row;}
    $owner=(int)$row['owner_user_id'];$generation=(int)$row['cleanup_generation']+1;$key='profile-call:'.$row['id'].':cleanup:'.$generation;$when=(int)$row['cleanup_not_before_ms']>vp3_profile_call_now_pa3c()?vp3_profile_handoff_date_pa3((int)$row['cleanup_not_before_ms']):null;
    $pdo->prepare("INSERT INTO agent_workflow_runs(owner_user_id,agent_id,workflow_type,origin,source_kind,source_key,source_hash,dedupe_key,title,goal,decision_summary,status,risk_level,requires_approval,approval_status,execution_target,capability_key,max_attempts,retry_backoff_seconds,timeout_seconds,next_attempt_at,current_action_id) VALUES(?,?,'profile_call_cleanup_pa3c','profile','profile_call_cleanup_pa3c',?,?,?,'Close Profile call','Revoke the exact human call participants and remove the bound room.','Cleanup is limited to the originally authorized room.','approved','low',0,'not_required','cloud','profile.call.cleanup',20,5,90,?,NULL)")->execute([$owner,(int)$row['profile_agent_id'],$key,sha1($key),hash('sha256',$key),$when]);$run=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO agent_workflow_actions(run_id,owner_user_id,sequence_no,action_key,action_type,label,summary,status,requires_approval,execution_target,capability_key,max_attempts,timeout_seconds,available_at) VALUES(?,?,1,?,'execute','Close Profile call','Revoke both participants and verify the exact room is absent.','queued',0,'cloud','profile.call.cleanup',20,90,?)")->execute([$run,$owner,$key,$when]);$action=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE profile_calls_pa3c SET cleanup_run_id=?,cleanup_action_id=?,cleanup_generation=?,cleanup_revision=CASE WHEN cleanup_revision=0 THEN 1 ELSE cleanup_revision END WHERE id=? AND owner_user_id=?')->execute([$run,$action,$generation,(int)$row['id'],$owner]);return vp3_profile_call_row_pa3c($pdo,(int)$row['id'],true)??throw new RuntimeException('Call cleanup was not saved.');
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
function vp3_profile_call_admission_pa3c(PDO $pdo,int $owner,string $role,string $credential): void
{
    // The owner slot serializes admission across every native conversation/tab.
    $now=vp3_profile_call_now_pa3c();$q=$pdo->prepare('SELECT offered_by,credential_hash,state,offer_expires_at_ms FROM profile_calls_pa3c WHERE owner_user_id=? AND created_at_ms>=?');$q->execute([$owner,$now-300000]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$principal=0;$outstanding=0;
    foreach($rows as $row){if($row['offered_by']===$role&&($role==='owner'||hash_equals($row['credential_hash'],$credential)))$principal++;if($row['state']==='offered'&&(int)$row['offer_expires_at_ms']>$now)$outstanding++;}
    if(count($rows)>=12||$principal>=($role==='owner'?6:3)||$outstanding>=5)vp3_profile_call_error_pa3c('PROFILE_CALL_RATE_LIMITED',429);
}
function vp3_profile_call_action_pa3c(PDO $pdo,string $role,array $auth,array $input): array
{
    vp3_profile_call_require_pa3c($pdo);$op=$input['operation']??null;if(!in_array($op,['offer','accept','decline','cancel','end','heartbeat','return_to_agent'],true))vp3_profile_call_error_pa3c('PROFILE_CALL_INVALID',422);
    $cid=vp3_profile_call_id_pa3c($input['conversation_id']??null);$id=$op==='offer'?0:vp3_profile_call_id_pa3c($input['call_id']??null);$revision=$op==='offer'?0:vp3_profile_call_id_pa3c($input['revision']??null);$request=$op==='heartbeat'?'':vp3_profile_call_token_pa3c($input['request_id']??null);
    $mode=$op==='offer'?($input['mode']??null):null;if($op==='offer'&&!in_array($mode,['audio','video'],true))vp3_profile_call_error_pa3c('PROFILE_CALL_INVALID',422);
    $checked=vp3_profile_call_authorize_pa3c($pdo,$role,$auth,$cid,$input);$owner=$checked['owner'];$auth=$checked['auth'];$actor=hash('sha256',$role.'|'.$owner.'|'.($role==='visitor'?$checked['context']['binding']['credential_hash']:'owner'));
    $hash=hash('sha256',json_encode([$role,$cid,$id,$revision,$op,$mode,(int)($input['chat_conversation_id']??0),(int)($input['chat_agent_id']??0)],JSON_THROW_ON_ERROR));
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$role,$auth,$input,$checked,$owner,$cid,$id,$revision,$request,$op,$mode,$actor,$hash){
        $slot=vp3_profile_call_slot_pa3c($pdo,$owner,true);vp3_profile_handoff_conversation_pa3($pdo,$owner,$cid,true);vp3_profile_handoff_thread_pa3($pdo,$owner,$cid,true);$now=vp3_profile_call_now_pa3c();
        if($request!==''){$q=$pdo->prepare('SELECT * FROM profile_call_operations_pa3c WHERE owner_user_id=? AND actor_key=? AND request_id=?');$q->execute([$owner,$actor,$request]);if($prior=$q->fetch(PDO::FETCH_ASSOC)){if(!hash_equals($prior['request_hash'],$hash))vp3_profile_call_error_pa3c();$row=vp3_profile_call_row_pa3c($pdo,(int)$prior['call_id'],true);if(!$row)vp3_profile_call_error_pa3c();vp3_profile_call_authorize_pa3c($pdo,$role,$auth,$cid,$input,$row,true);return vp3_profile_call_reply_pa3c($pdo,$row,$role,$input,$request,true);}}
        if($op==='offer'){
            vp3_profile_call_ready_pa3c();if(vp3_profile_call_owner_busy_pa3c($pdo,$owner))vp3_profile_call_error_pa3c('PROFILE_CALL_BUSY',409);
            vp3_profile_call_admission_pa3c($pdo,$owner,$role,$checked['context']['binding']['credential_hash']);
            if($role==='visitor'&&!vp3_profile_handoff_reachable_pa3($pdo,$owner))vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
            $q=$pdo->prepare("SELECT * FROM profile_calls_pa3c WHERE owner_user_id=? AND conversation_id=? AND state IN ('offered','preparing','ready','active','ending') ORDER BY id DESC".vp3_profile_handoff_lock_pa3($pdo));$q->execute([$owner,$cid]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $pending){if($pending['state']==='offered'&&$now>=(int)$pending['offer_expires_at_ms'])vp3_profile_call_end_locked_pa3c($pdo,$pending,'offer_expired');else vp3_profile_call_error_pa3c();}
            $context=vp3_profile_call_authorize_pa3c($pdo,$role,$auth,$cid,$input,null,true)['context'];$snapshot=vp3_profile_call_livekit_snapshot_pa3c();
            if(!is_string($snapshot['encrypted']??null)||$snapshot['encrypted']===''||strlen($snapshot['encrypted'])>32768||!preg_match('/^[a-f0-9]{64}$/D',(string)($snapshot['config_fingerprint']??'')))vp3_profile_call_error_pa3c('PROFILE_CALL_NOT_READY',503);
            $public=bin2hex(random_bytes(16));$conversation=$context['conversation'];
            $pdo->prepare("INSERT INTO profile_calls_pa3c(owner_user_id,profile_agent_id,profile_session_id,conversation_id,visitor_user_id,credential_hash,authority_json,public_id,room_name,owner_identity,visitor_identity,provider_snapshot,provider_config_fingerprint,mode,offered_by,state,offer_expires_at_ms,created_at_ms,updated_at_ms) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'offered',?,?,?)")->execute([$owner,(int)$conversation['profile_agent_id'],(int)$conversation['profile_session_id'],$cid,(int)($conversation['visitor_user_id']??0),$context['binding']['credential_hash'],json_encode($context['snapshot'],JSON_THROW_ON_ERROR),$public,'vp3-profile-'.$public,'owner-'.$public,'visitor-'.$public,$snapshot['encrypted'],$snapshot['config_fingerprint'],$mode,$role,$now+VP3_PROFILE_CALL_OFFER_MS_PA3C,$now,$now]);$row=vp3_profile_call_row_pa3c($pdo,(int)$pdo->lastInsertId(),true);
            $pdo->prepare('UPDATE profile_calls_pa3c SET binding_hash=? WHERE id=?')->execute([vp3_profile_call_binding_hash_pa3c($row),(int)$row['id']]);$row=vp3_profile_call_row_pa3c($pdo,(int)$row['id'],true);
        }else{
            $row=vp3_profile_call_row_pa3c($pdo,$id,true);if(!$row||(int)$row['owner_user_id']!==$owner||(int)$row['conversation_id']!==$cid)vp3_profile_call_error_pa3c('PROFILE_CALL_NOT_FOUND',404);
            vp3_profile_call_integrity_pa3c($row);
            vp3_profile_call_authorize_pa3c($pdo,$role,$auth,$cid,$input,$row,true);if($role==='owner')vp3_profile_call_delivery_check_pa3c($pdo,$row,$input);if((int)$row['revision']!==$revision)vp3_profile_call_error_pa3c();
            if(in_array($op,['accept','decline','cancel'],true)){
                if($row['state']!=='offered'||$now>=(int)$row['offer_expires_at_ms']||(($op==='cancel')!==($row['offered_by']===$role)))vp3_profile_call_error_pa3c();
                if($op==='accept'){
                    vp3_profile_call_ready_pa3c($row);if(vp3_profile_call_owner_busy_pa3c($pdo,$owner))vp3_profile_call_error_pa3c('PROFILE_CALL_BUSY',409);
                    $thread=vp3_profile_call_takeover_pa3c($pdo,$owner,$cid);
                    $pdo->prepare("UPDATE profile_calls_pa3c SET state='preparing',revision=revision+1,accepted_at_ms=?,connect_until_ms=?,handoff_epoch=?,last_reason='',updated_at_ms=? WHERE id=?")->execute([$now,$now+VP3_PROFILE_CALL_CONNECT_MS_PA3C,(int)$thread['epoch'],$now,$id]);
                    $pdo->prepare('UPDATE profile_call_slots_pa3c SET active_call_id=?,revision=revision+1,updated_at_ms=? WHERE owner_user_id=?')->execute([$id,$now,$owner]);
                }else $row=vp3_profile_call_end_locked_pa3c($pdo,$row,$op==='decline'?'declined':'cancelled');
            }elseif($op==='end'){
                if(!in_array($row['state'],['preparing','ready','active','ending'],true))vp3_profile_call_error_pa3c();if($row['state']!=='ending')$row=vp3_profile_call_end_locked_pa3c($pdo,$row,'ended');
            }elseif($op==='heartbeat'){
                vp3_profile_call_media_current_pa3c($pdo,$row,true);$instance=vp3_profile_call_token_pa3c($input['client_instance_id']??null);
                if(!in_array($row['state'],['ready','active'],true)||(int)$row[$role.'_authority_until_ms']<$now||!hash_equals($row[$role.'_client_hash'],hash('sha256',$instance)))vp3_profile_call_error_pa3c();
                $pdo->prepare('UPDATE profile_calls_pa3c SET '.$role.'_authority_until_ms=?,updated_at_ms=? WHERE id=?')->execute([$now+VP3_PROFILE_CALL_AUTH_MS_PA3C,$now,$id]);
            }elseif($op==='return_to_agent'){
                if(!vp3_profile_call_can_return_pa3c($pdo,$row))vp3_profile_call_error_pa3c();vp3_profile_agent_response_token_v1($pdo,$cid,true);vp3_profile_handoff_mode_pa3($pdo,$owner,$cid,'agent','ended','agent');$pdo->prepare('UPDATE profile_calls_pa3c SET revision=revision+1,updated_at_ms=? WHERE id=?')->execute([$now,$id]);
            }
            $row=vp3_profile_call_row_pa3c($pdo,$id,true);
        }
        if($request!=='')$pdo->prepare('INSERT INTO profile_call_operations_pa3c(owner_user_id,actor_key,request_id,request_hash,call_id,operation,created_at_ms) VALUES(?,?,?,?,?,?,?)')->execute([$owner,$actor,$request,$hash,(int)$row['id'],$op,$now]);
        $result=vp3_profile_call_reply_pa3c($pdo,$row,$role,$input,$request);vp3_profile_call_authorize_pa3c($pdo,$role,$auth,$cid,$input,$row,true);if($op==='offer')vp3_profile_call_ready_pa3c($row);if(in_array($op,['accept','heartbeat'],true))vp3_profile_call_media_current_pa3c($pdo,$row,true);return $result;
    });
}

/** All call state locks use the same order; provider work happens after this transaction ends. */
function vp3_profile_call_locked_pa3c(PDO $pdo,array $expected,bool $cleanup=false): array
{
    $owner=(int)$expected['owner_user_id'];$slot=vp3_profile_call_slot_pa3c($pdo,$owner,true);
    if($cleanup){$q=$pdo->prepare('SELECT id FROM profile_agent_conversations WHERE id=? AND owner_user_id=?'.vp3_profile_handoff_lock_pa3($pdo));$q->execute([(int)$expected['conversation_id'],$owner]);}
    else vp3_profile_handoff_conversation_pa3($pdo,$owner,(int)$expected['conversation_id'],true);
    vp3_profile_handoff_thread_pa3($pdo,$owner,(int)$expected['conversation_id'],true);$row=vp3_profile_call_row_pa3c($pdo,(int)$expected['id'],true);
    if(!$row||(int)$row['owner_user_id']!==$owner||!hash_equals((string)$expected['binding_hash'],(string)$row['binding_hash']))vp3_profile_call_error_pa3c();vp3_profile_call_integrity_pa3c($row);
    if((in_array($row['state'],['preparing','ready','active'],true)||($row['state']==='ending'&&!$cleanup))&&(int)($slot['active_call_id']??0)!==(int)$row['id'])vp3_profile_call_error_pa3c();return $row;
}
/** A late possible creation invalidates only this room's closure proof, never another accepted call. */
function vp3_profile_call_late_create_pa3c(PDO $pdo,array $expected,string $token): array
{
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$expected,$token){
        $row=vp3_profile_call_locked_pa3c($pdo,$expected,true);$owner=(int)$row['owner_user_id'];$now=vp3_profile_call_now_pa3c();$actor=hash('sha256','provider-cleanup|'.$owner.'|'.$row['binding_hash']);$hash=hash('sha256',$row['binding_hash'].'|'.$token);
        $q=$pdo->prepare('SELECT request_hash FROM profile_call_operations_pa3c WHERE owner_user_id=? AND actor_key=? AND request_id=?');$q->execute([$owner,$actor,$token]);$prior=$q->fetchColumn();if($prior!==false){if(!hash_equals((string)$prior,$hash))vp3_profile_call_error_pa3c();return $row;}
        $completed=false;if((int)$row['cleanup_run_id']>0){$job=vp3_profile_call_cleanup_job_pa3c($pdo,$row,true);$completed=$job['run']['status']==='completed'&&$job['action']['status']==='completed';}
        $settle=(string)$row['provider_lease_token']!==''&&$row['provider_lease_kind']!=='cleanup'?max($now,(int)$row['provider_lease_until_ms']+5000):$now;
        $pdo->prepare("UPDATE profile_calls_pa3c SET state='ending',revision=revision+1,ended_at_ms=NULL,owner_authority_until_ms=0,visitor_authority_until_ms=0,owner_connected=0,visitor_connected=0,cleanup_revision=cleanup_revision+1,cleanup_proof_json=NULL,cleanup_not_before_ms=?,revoke_before_sec=?,last_reason='late_provider_result',updated_at_ms=? WHERE id=?")->execute([$settle,max((int)$row['revoke_before_sec'],intdiv($now,1000)+1),$now,(int)$row['id']]);
        if($completed)$pdo->prepare('UPDATE profile_calls_pa3c SET cleanup_run_id=NULL,cleanup_action_id=NULL WHERE id=?')->execute([(int)$row['id']]);
        $slot=vp3_profile_call_slot_pa3c($pdo,$owner,true);if($slot['active_call_id']===null)$pdo->prepare('UPDATE profile_call_slots_pa3c SET active_call_id=?,revision=revision+1,updated_at_ms=? WHERE owner_user_id=?')->execute([(int)$row['id'],$now,$owner]);
        $row=vp3_profile_call_cleanup_enqueue_pa3c($pdo,vp3_profile_call_row_pa3c($pdo,(int)$row['id'],true));
        $pdo->prepare("INSERT INTO profile_call_operations_pa3c(owner_user_id,actor_key,request_id,request_hash,call_id,operation,created_at_ms) VALUES(?,?,?,?,?,'late_create',?)")->execute([$owner,$actor,$token,$hash,(int)$row['id'],$now]);return $row;
    });
}
function vp3_profile_call_expiry_reason_pa3c(array $row): string
{
    $now=vp3_profile_call_now_pa3c();if($row['state']==='offered'&&$now>=(int)$row['offer_expires_at_ms'])return 'offer_expired';
    if(!in_array($row['state'],['preparing','ready','active'],true))return '';
    foreach(['owner','visitor'] as $role)if((int)$row[$role.'_authority_until_ms']>0&&(int)$row[$role.'_authority_until_ms']<=$now)return 'heartbeat_expired';
    if($row['connect_until_ms']!==null&&$now>=(int)$row['connect_until_ms'])return 'connection_timeout';return '';
}
function vp3_profile_call_media_current_pa3c(PDO $pdo,array $row,bool $lock=false): void
{
    vp3_profile_call_integrity_pa3c($row);vp3_profile_call_context_pa3c($pdo,(int)$row['owner_user_id'],(int)$row['conversation_id'],$lock,$row);vp3_profile_call_ready_pa3c($row);
    $thread=vp3_profile_handoff_thread_pa3($pdo,(int)$row['owner_user_id'],(int)$row['conversation_id'],$lock);
    if(!$thread||$thread['mode']!=='owner'||vp3_profile_call_expiry_reason_pa3c($row)!=='')vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
    try{$settingsAllowed=vp3_profile_handoff_settings_allowed_pa3d($pdo,(int)$row['owner_user_id'],$lock);}catch(PDOException $error){vp3_profile_call_error_pa3c('PROFILE_CALL_NOT_READY',503);}
    if(!$settingsAllowed)vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);
    vp3_profile_call_block_check_pa3c($pdo,(int)$row['owner_user_id'],(int)$row['visitor_user_id'],$lock);
}
function vp3_profile_call_proof_pa3c(array $proof,array $row,int $started): array
{
    $now=vp3_profile_call_now_pa3c();$bad=static fn()=>vp3_profile_call_error_pa3c('PROFILE_CALL_PROVIDER_INVALID',502);
    if(!in_array($proof['status']??null,['ready','absent'],true)||($proof['room_name']??null)!==$row['room_name']||($proof['config_fingerprint']??null)!==$row['provider_config_fingerprint']||($proof['recording']??null)!==false||!is_bool($proof['owner_connected']??null)||!is_bool($proof['visitor_connected']??null)||!is_array($proof['participants']??null)||count($proof['participants'])>2||!is_int($proof['observed_at_ms']??null)||$proof['observed_at_ms']<$started||$proof['observed_at_ms']>$now+1000)$bad();
    if($proof['status']==='absent'){if(($proof['room_sid']??null)!==''||$proof['participants']||$proof['owner_connected']||$proof['visitor_connected'])$bad();return $proof;}
    if(!is_string($proof['room_sid']??null)||!preg_match('/^RM_[A-Za-z0-9_-]{1,90}$/D',$proof['room_sid'])||($row['room_sid']!==''&&!hash_equals($row['room_sid'],$proof['room_sid'])))$bad();
    $connected=['owner'=>false,'visitor'=>false];$seen=[];
    foreach($proof['participants'] as $participant){
        if(!is_array($participant)||!in_array($participant['identity']??null,[$row['owner_identity'],$row['visitor_identity']],true)||isset($seen[$participant['identity']])||!is_string($participant['sid']??null)||!preg_match('/^PA_[A-Za-z0-9_-]{1,90}$/D',$participant['sid'])||!in_array($participant['state']??null,['joining','joined','active'],true)||!is_bool($participant['microphone']??null)||!is_bool($participant['camera']??null)||($row['mode']==='audio'&&$participant['camera']))$bad();
        $seen[$participant['identity']]=true;$role=$participant['identity']===$row['owner_identity']?'owner':'visitor';$connected[$role]=in_array($participant['state'],['joined','active'],true);
    }
    if($connected['owner']!==$proof['owner_connected']||$connected['visitor']!==$proof['visitor_connected'])$bad();return $proof;
}
function vp3_profile_call_settled_pa3c(PDO $pdo,array $row,string $token): array
{
    if(!hash_equals((string)$row['provider_lease_token'],$token))return $row;$now=vp3_profile_call_now_pa3c();
    $pdo->prepare("UPDATE profile_calls_pa3c SET provider_lease_token='',provider_lease_kind='',provider_lease_until_ms=0,cleanup_not_before_ms=CASE WHEN state='ending' THEN ? ELSE cleanup_not_before_ms END WHERE id=?")->execute([$now,(int)$row['id']]);$row=vp3_profile_call_row_pa3c($pdo,(int)$row['id'],true);
    if($row['state']==='ending'&&(int)$row['cleanup_run_id']>0){
        $job=vp3_profile_call_cleanup_job_pa3c($pdo,$row,true);
        if($job['run']['status']==='approved'&&$job['action']['status']==='queued'){$when=vp3_profile_handoff_date_pa3($now);$pdo->prepare('UPDATE agent_workflow_runs SET next_attempt_at=? WHERE id=? AND owner_user_id=?')->execute([$when,(int)$row['cleanup_run_id'],(int)$row['owner_user_id']]);$pdo->prepare('UPDATE agent_workflow_actions SET available_at=? WHERE id=? AND owner_user_id=?')->execute([$when,(int)$row['cleanup_action_id'],(int)$row['owner_user_id']]);}
    }
    return $row;
}
function vp3_profile_call_work_pa3c(PDO $pdo,int $callId,?callable $provider=null): array
{
    vp3_profile_call_require_pa3c($pdo);if($pdo->inTransaction())throw new RuntimeException('Call provider work cannot run inside a transaction.');$initial=vp3_profile_call_row_pa3c($pdo,$callId);if(!$initial)vp3_profile_call_error_pa3c('PROFILE_CALL_NOT_FOUND',404);vp3_profile_call_integrity_pa3c($initial);
    $reserved=vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$initial){
        $row=vp3_profile_call_locked_pa3c($pdo,$initial,true);$now=vp3_profile_call_now_pa3c();$reason=vp3_profile_call_expiry_reason_pa3c($row);
        if($reason!=='')$row=vp3_profile_call_end_locked_pa3c($pdo,$row,$reason);
        if(in_array($row['state'],['preparing','ready','active'],true)){
            try{vp3_profile_call_media_current_pa3c($pdo,$row,true);}catch(VP3ProfileCallException $e){$reason=$e->publicCode==='PROFILE_CALL_NOT_READY'?'configuration_changed':'authority_revoked';$row=vp3_profile_call_end_locked_pa3c($pdo,$row,$reason);}
        }
        if(!in_array($row['state'],['preparing','ready','active'],true))return ['row'=>$row,'operation'=>''];
        if((string)$row['provider_lease_token']!==''&&(int)$row['provider_lease_until_ms']>$now)return ['row'=>$row,'operation'=>''];
        if($row['state']!=='preparing'&&(int)$row['provider_observed_at_ms']>$now-10000)return ['row'=>$row,'operation'=>''];
        if($row['last_reason']==='provider_unavailable'&&(int)$row['updated_at_ms']>$now-5000)return ['row'=>$row,'operation'=>''];
        $operation=$row['state']==='preparing'?'create':'inspect';$priorStarted=(int)$row['provider_started_at_ms'];$token=bin2hex(random_bytes(16));
        $pdo->prepare('UPDATE profile_calls_pa3c SET provider_lease_token=?,provider_lease_kind=?,provider_lease_until_ms=?,provider_started_at_ms=CASE WHEN provider_started_at_ms=0 THEN ? ELSE provider_started_at_ms END,updated_at_ms=? WHERE id=?')->execute([$token,$operation,$now+VP3_PROFILE_CALL_WORK_MS_PA3C,$now,$now,(int)$row['id']]);$row=vp3_profile_call_row_pa3c($pdo,(int)$row['id'],true);
        return ['row'=>$row,'operation'=>$operation,'inspect_first'=>$operation==='create'&&$priorStarted>0,'started'=>$now];
    });
    $row=$reserved['row'];if($row['state']==='ending')return vp3_profile_call_cleanup_dispatch_pa3c($pdo,$row,$provider);
    if($reserved['operation']==='')return ['contract'=>VP3_PROFILE_CALL_PA3C,'call_id'=>$callId,'state'=>$row['state'],'reason'=>'waiting'];
    $token=$row['provider_lease_token'];$options=['operation_deadline_ms'=>(int)$row['provider_lease_until_ms']];$proof=null;$createInvoked=false;
    try{
        if(vp3_profile_call_now_pa3c()>=(int)$row['provider_lease_until_ms'])vp3_profile_call_error_pa3c();
        if($reserved['inspect_first']){
            $proof=vp3_profile_call_proof_pa3c(vp3_profile_call_provider_pa3c('inspect',$row,$options,$provider),$row,$reserved['started']);
            if($proof['status']==='absent'){
                vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$row,$token){$current=vp3_profile_call_locked_pa3c($pdo,$row);if($current['state']!=='preparing'||(int)$current['revision']!==(int)$row['revision']||!hash_equals($current['provider_lease_token'],$token)||(int)$current['provider_lease_until_ms']<=vp3_profile_call_now_pa3c())vp3_profile_call_error_pa3c();vp3_profile_call_media_current_pa3c($pdo,$current,true);});
                $proof=null;
            }
        }
        if($proof===null){$createInvoked=$reserved['operation']==='create';$proof=vp3_profile_call_proof_pa3c(vp3_profile_call_provider_pa3c($reserved['operation'],$row,$options,$provider),$row,$reserved['started']);}
        $result=vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$row,$token,$proof,$createInvoked){
            $current=vp3_profile_call_locked_pa3c($pdo,$row,true);if(!hash_equals((string)$current['provider_lease_token'],$token))return $createInvoked?vp3_profile_call_late_create_pa3c($pdo,$row,$token):$current;
            if($current['state']==='ending')return vp3_profile_call_settled_pa3c($pdo,$current,$token);
            if(!in_array($current['state'],['preparing','ready','active'],true)||(int)$current['revision']!==(int)$row['revision']||(int)$current['provider_lease_until_ms']<=vp3_profile_call_now_pa3c())vp3_profile_call_error_pa3c();
            try{vp3_profile_call_media_current_pa3c($pdo,$current,true);}catch(VP3ProfileCallException $e){$current=vp3_profile_call_settled_pa3c($pdo,$current,$token);return vp3_profile_call_end_locked_pa3c($pdo,$current,$e->publicCode==='PROFILE_CALL_NOT_READY'?'configuration_changed':'authority_revoked');}
            $current=vp3_profile_call_settled_pa3c($pdo,$current,$token);if($proof['status']!=='ready')return vp3_profile_call_end_locked_pa3c($pdo,$current,'provider_unavailable');
            $state=$proof['owner_connected']&&$proof['visitor_connected']?'active':'ready';$changed=$current['state']!==$state;
            $pdo->prepare('UPDATE profile_calls_pa3c SET state=?,revision=revision+?,room_sid=?,provider_observed_at_ms=?,owner_connected=?,visitor_connected=?,connect_until_ms=CASE WHEN ?=1 THEN NULL ELSE connect_until_ms END,last_reason=\'\',updated_at_ms=? WHERE id=?')->execute([$state,$changed?1:0,$proof['room_sid'],$proof['observed_at_ms'],$proof['owner_connected']?1:0,$proof['visitor_connected']?1:0,$state==='active'?1:0,vp3_profile_call_now_pa3c(),(int)$current['id']]);
            $current=vp3_profile_call_row_pa3c($pdo,(int)$current['id'],true);vp3_profile_call_media_current_pa3c($pdo,$current,true);return $current;
        });
        return ['contract'=>VP3_PROFILE_CALL_PA3C,'call_id'=>$callId,'state'=>$result['state'],'reason'=>$result['state']==='ending'?'cleanup_pending':'verified'];
    }catch(Throwable $error){
        $permanent=$error instanceof VP3ProfileCallException||(property_exists($error,'retryable')&&$error->retryable===false);$reason=property_exists($error,'publicCode')&&$error->publicCode==='CALL_PROVIDER_CONFIGURATION_CHANGED'?'configuration_changed':($permanent?'provider_unsafe':'provider_unavailable');
        try{$final=vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$row,$token,$permanent,$reason,$createInvoked){$current=vp3_profile_call_locked_pa3c($pdo,$row,true);if(!hash_equals((string)$current['provider_lease_token'],$token))return $createInvoked?vp3_profile_call_late_create_pa3c($pdo,$row,$token):$current;$current=vp3_profile_call_settled_pa3c($pdo,$current,$token);if($current['state']==='ending')return $current;if($permanent)return vp3_profile_call_end_locked_pa3c($pdo,$current,$reason);$pdo->prepare('UPDATE profile_calls_pa3c SET last_reason=?,updated_at_ms=? WHERE id=?')->execute([$reason,vp3_profile_call_now_pa3c(),(int)$current['id']]);return vp3_profile_call_row_pa3c($pdo,(int)$current['id'],true);});}catch(Throwable $ignored){$final=vp3_profile_call_row_pa3c($pdo,$callId)??$row;}
        return ['contract'=>VP3_PROFILE_CALL_PA3C,'call_id'=>$callId,'state'=>$final['state'],'reason'=>$final['state']==='ending'?'cleanup_pending':$reason];
    }
}

function vp3_profile_call_cleanup_job_pa3c(PDO $pdo,array $row,bool $lock=false): array
{
    vp3_profile_call_integrity_pa3c($row);$suffix=$lock?vp3_profile_handoff_lock_pa3($pdo):'';$owner=(int)$row['owner_user_id'];
    $q=$pdo->prepare('SELECT * FROM agent_workflow_runs WHERE id=? AND owner_user_id=?'.$suffix);$q->execute([(int)$row['cleanup_run_id'],$owner]);$run=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT * FROM agent_workflow_actions WHERE id=? AND run_id=? AND owner_user_id=?'.$suffix);$q->execute([(int)$row['cleanup_action_id'],(int)$row['cleanup_run_id'],$owner]);$action=$q->fetch(PDO::FETCH_ASSOC);$generation=(int)($row['cleanup_generation']??0);if($generation<1)vp3_profile_call_error_pa3c();$key='profile-call:'.$row['id'].':cleanup:'.$generation;
    if(!$run||!$action||(int)$run['agent_id']!==(int)$row['profile_agent_id']||(int)$run['requires_approval']!==0||$run['approval_status']!=='not_required'||(int)$action['requires_approval']!==0||(int)$action['sequence_no']!==1||(int)$run['max_attempts']!==20||(int)$action['max_attempts']!==20||(int)$run['timeout_seconds']!==90||(int)$action['timeout_seconds']!==90)vp3_profile_call_error_pa3c();
    foreach(['workflow_type'=>'profile_call_cleanup_pa3c','origin'=>'profile','source_kind'=>'profile_call_cleanup_pa3c','source_key'=>$key,'source_hash'=>sha1($key),'dedupe_key'=>hash('sha256',$key),'execution_target'=>'cloud','capability_key'=>'profile.call.cleanup'] as $field=>$value)if(!is_string($run[$field]??null)||!hash_equals($run[$field],$value))vp3_profile_call_error_pa3c();
    foreach(['action_key'=>$key,'action_type'=>'execute','execution_target'=>'cloud','capability_key'=>'profile.call.cleanup'] as $field=>$value)if(!is_string($action[$field]??null)||!hash_equals($action[$field],$value))vp3_profile_call_error_pa3c();
    return compact('run','action');
}
function vp3_profile_call_cleanup_lease_pa3c(PDO $pdo,array $row,array $claim): array
{
    $job=vp3_profile_call_cleanup_job_pa3c($pdo,$row,true);$r=$job['run'];$a=$job['action'];
    if($r['status']!=='executing'||$a['status']!=='executing'||(int)$r['current_action_id']!==(int)$row['cleanup_action_id']||(int)($claim['run_id']??0)!==(int)$row['cleanup_run_id']||(int)($claim['action']['id']??0)!==(int)$row['cleanup_action_id']||!is_string($claim['lease_token']??null)||!hash_equals((string)$r['lease_token'],$claim['lease_token'])||!hash_equals((string)$r['lease_owner'],(string)($claim['worker_id']??''))||(strtotime((string)$r['lease_expires_at'].' UTC')?:0)<time())vp3_profile_call_error_pa3c();return $job;
}
function vp3_profile_call_cleanup_dispatch_pa3c(PDO $pdo,array $row,?callable $provider=null): array
{
    if($row['state']!=='ending')return ['contract'=>VP3_PROFILE_CALL_PA3C,'call_id'=>(int)$row['id'],'state'=>$row['state'],'reason'=>'waiting'];
    $now=vp3_profile_call_now_pa3c();if((int)$row['cleanup_not_before_ms']>$now||((string)$row['provider_lease_token']!==''&&(int)$row['provider_lease_until_ms']>$now))return ['contract'=>VP3_PROFILE_CALL_PA3C,'call_id'=>(int)$row['id'],'state'=>'ending','reason'=>'provider_settling'];
    require_once __DIR__.'/agent-job-engine-v1900.php';$job=vp3_profile_call_cleanup_job_pa3c($pdo,$row);$user=['id'=>(int)$row['owner_user_id']];
    agent_job_recover_expired_v1900($pdo,$user,1,(int)$row['cleanup_run_id']);$job=vp3_profile_call_cleanup_job_pa3c($pdo,$row);
    if(!in_array($job['run']['status'],['approved','executing'],true))return ['contract'=>VP3_PROFILE_CALL_PA3C,'call_id'=>(int)$row['id'],'state'=>'ending','reason'=>'cleanup_pending'];
    $worker='profile-call:'.$row['owner_user_id'].':'.bin2hex(random_bytes(6));$claim=agent_job_claim_run_v1900($pdo,$user,(int)$row['cleanup_run_id'],'cloud',$worker,90);
    if(!$claim)return ['contract'=>VP3_PROFILE_CALL_PA3C,'call_id'=>(int)$row['id'],'state'=>'ending','reason'=>'cleanup_pending'];return vp3_profile_call_execute_claim_pa3c($pdo,$user,$claim,$provider);
}
function vp3_profile_call_cleanup_evidence_pa3c(array $row,?array $proof,bool $neverStarted,int $earliest=0): void
{
    $now=vp3_profile_call_now_pa3c();
    if($neverStarted){if($proof!==null||(int)$row['provider_started_at_ms']!==0||(int)$row['owner_token_issued_at_ms']!==0||(int)$row['visitor_token_issued_at_ms']!==0)vp3_profile_call_error_pa3c();return;}
    if(($proof['status']??null)!=='closed'||($proof['owner_revoked']??null)!==true||($proof['visitor_revoked']??null)!==true||($proof['room_absent']??null)!==true||!is_int($proof['revoked_before_sec']??null)||!is_int($proof['owner_revoked_before_sec']??null)||!is_int($proof['visitor_revoked_before_sec']??null)||$proof['revoked_before_sec']!==min($proof['owner_revoked_before_sec'],$proof['visitor_revoked_before_sec'])||$proof['revoked_before_sec']<(int)$row['revoke_before_sec']||max($proof['owner_revoked_before_sec'],$proof['visitor_revoked_before_sec'])>intdiv($now,1000)+55||!is_int($proof['observed_at_ms']??null)||$proof['observed_at_ms']<max($earliest,(int)$row['cleanup_not_before_ms'])||$proof['observed_at_ms']>$now+1000)vp3_profile_call_error_pa3c('PROFILE_CALL_PROVIDER_INVALID',502);
}
function vp3_profile_call_saved_cleanup_pa3c(array $row): ?array
{
    $json=$row['cleanup_proof_json']??null;if($json===null||$json==='')return null;
    if(!is_string($json)||strlen($json)>4096)vp3_profile_call_error_pa3c();$saved=json_decode($json,true);
    if(!is_array($saved)||($saved['contract']??null)!=='vp3.profile-call-cleanup-proof.v1'||($saved['binding_hash']??null)!==$row['binding_hash']||($saved['config_fingerprint']??null)!==$row['provider_config_fingerprint']||($saved['run_id']??null)!==(int)$row['cleanup_run_id']||($saved['action_id']??null)!==(int)$row['cleanup_action_id']||($saved['generation']??null)!==(int)$row['cleanup_generation']||($saved['revision']??null)!==(int)$row['cleanup_revision']||!in_array($saved['closure_basis']??null,['never_started','provider_confirmed'],true)||($saved['origin']??null)!==($saved['closure_basis']==='never_started'?'local_ledger':'livekit_cloud')||!array_key_exists('proof',$saved)||($saved['proof']!==null&&!is_array($saved['proof'])))vp3_profile_call_error_pa3c();
    vp3_profile_call_cleanup_evidence_pa3c($row,$saved['proof'],$saved['closure_basis']==='never_started');return $saved;
}
function vp3_profile_call_execute_claim_pa3c(PDO $pdo,array $user,array $claim,?callable $provider=null): array
{
    vp3_profile_call_require_pa3c($pdo);if($pdo->inTransaction())throw new RuntimeException('Call cleanup cannot invoke its provider inside a transaction.');$owner=(int)($user['id']??0);$q=$pdo->prepare('SELECT * FROM profile_calls_pa3c WHERE owner_user_id=? AND cleanup_run_id=? AND cleanup_action_id=?');$q->execute([$owner,(int)($claim['run_id']??0),(int)($claim['action']['id']??0)]);$initial=$q->fetch(PDO::FETCH_ASSOC);if(!$initial)vp3_profile_call_error_pa3c('PROFILE_CALL_NOT_FOUND',404);
    $row=null;$token=substr(hash('sha256',(string)($claim['lease_token']??'')),0,32);
    try{
        $row=vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$initial,$claim,$token){
            $row=vp3_profile_call_locked_pa3c($pdo,$initial,true);vp3_profile_call_cleanup_lease_pa3c($pdo,$row,$claim);$now=vp3_profile_call_now_pa3c();
            if($row['state']!=='ending'||(int)$row['cleanup_not_before_ms']>$now||((string)$row['provider_lease_token']!==''&&(int)$row['provider_lease_until_ms']>$now))vp3_profile_call_error_pa3c();
            $pdo->prepare("UPDATE profile_calls_pa3c SET provider_lease_token=?,provider_lease_kind='cleanup',provider_lease_until_ms=?,updated_at_ms=? WHERE id=?")->execute([$token,$now+VP3_PROFILE_CALL_WORK_MS_PA3C,$now,(int)$row['id']]);return vp3_profile_call_row_pa3c($pdo,(int)$row['id'],true);
        });
        $started=vp3_profile_call_now_pa3c();$saved=vp3_profile_call_saved_cleanup_pa3c($row);$neverStarted=$saved?$saved['closure_basis']==='never_started':((int)$row['provider_started_at_ms']===0&&(int)$row['owner_token_issued_at_ms']===0&&(int)$row['visitor_token_issued_at_ms']===0);
        $proof=$saved?$saved['proof']:($neverStarted?null:vp3_profile_call_provider_pa3c('cleanup',$row,['operation_deadline_ms'=>(int)$row['provider_lease_until_ms']],$provider));
        vp3_profile_call_cleanup_evidence_pa3c($row,$proof,$neverStarted,$saved?0:$started);
        if(!$saved){
            // Keep provider proof even if a later receipt write fails. An absent room on retry alone is not revocation evidence.
            $stored=['contract'=>'vp3.profile-call-cleanup-proof.v1','binding_hash'=>$row['binding_hash'],'config_fingerprint'=>$row['provider_config_fingerprint'],'run_id'=>(int)$row['cleanup_run_id'],'action_id'=>(int)$row['cleanup_action_id'],'generation'=>(int)$row['cleanup_generation'],'revision'=>(int)$row['cleanup_revision'],'closure_basis'=>$neverStarted?'never_started':'provider_confirmed','origin'=>$neverStarted?'local_ledger':'livekit_cloud','proof'=>$neverStarted?null:array_intersect_key($proof,array_flip(['status','owner_revoked','visitor_revoked','room_absent','revoked_before_sec','owner_revoked_before_sec','visitor_revoked_before_sec','observed_at_ms']))];
            vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$row,$claim,$token,$proof,$neverStarted,$stored){$current=vp3_profile_call_locked_pa3c($pdo,$row,true);vp3_profile_call_cleanup_lease_pa3c($pdo,$current,$claim);if($current['state']!=='ending'||(int)$current['cleanup_revision']!==(int)$row['cleanup_revision']||!hash_equals($current['provider_lease_token'],$token)||(int)$current['provider_lease_until_ms']<=vp3_profile_call_now_pa3c())vp3_profile_call_error_pa3c();vp3_profile_call_cleanup_evidence_pa3c($current,$proof,$neverStarted);$pdo->prepare('UPDATE profile_calls_pa3c SET cleanup_proof_json=? WHERE id=?')->execute([json_encode($stored,JSON_THROW_ON_ERROR),(int)$current['id']]);$current=vp3_profile_call_row_pa3c($pdo,(int)$current['id'],true);vp3_profile_call_integrity_pa3c($current);vp3_profile_call_cleanup_lease_pa3c($pdo,$current,$claim);vp3_profile_call_saved_cleanup_pa3c($current);});
        }
        return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$user,$row,$token,$claim,$proof,$neverStarted){
            $current=vp3_profile_call_locked_pa3c($pdo,$row,true);vp3_profile_call_cleanup_lease_pa3c($pdo,$current,$claim);$now=vp3_profile_call_now_pa3c();
            if($current['state']!=='ending'||(int)$current['cleanup_revision']!==(int)$row['cleanup_revision']||!hash_equals($current['provider_lease_token'],$token)||(int)$current['provider_lease_until_ms']<=$now||(int)$current['cleanup_not_before_ms']>$now||(!$neverStarted&&(int)$current['revoke_before_sec']>$proof['revoked_before_sec'])||($neverStarted&&((int)$current['provider_started_at_ms']!==0||(int)$current['owner_token_issued_at_ms']!==0||(int)$current['visitor_token_issued_at_ms']!==0)))vp3_profile_call_error_pa3c();
            $pdo->prepare("UPDATE profile_calls_pa3c SET state='ended',revision=revision+1,ended_at_ms=?,provider_lease_token='',provider_lease_kind='',provider_lease_until_ms=0,owner_connected=0,visitor_connected=0,updated_at_ms=? WHERE id=?")->execute([$now,$now,(int)$current['id']]);
            $pdo->prepare('UPDATE profile_call_slots_pa3c SET active_call_id=NULL,revision=revision+1,updated_at_ms=? WHERE owner_user_id=? AND active_call_id=?')->execute([$now,(int)$current['owner_user_id'],(int)$current['id']]);
            $receipt=agent_job_record_result_v1900($pdo,$user,(int)$current['cleanup_run_id'],(int)$current['cleanup_action_id'],(string)$claim['lease_token'],'profile-call:'.$current['id'].':closed:'.$current['cleanup_generation'],true,'Profile call closed.',[
                'call_id'=>(int)$current['id'],'cleanup_generation'=>(int)$current['cleanup_generation'],'closure_basis'=>$neverStarted?'never_started':'provider_confirmed','participants_revoked'=>!$neverStarted,'room_absent'=>!$neverStarted,'provider_not_started'=>$neverStarted,
            ],'',false);
            $final=vp3_profile_call_row_pa3c($pdo,(int)$current['id'],true);vp3_profile_call_integrity_pa3c($final);$slot=vp3_profile_call_slot_pa3c($pdo,(int)$current['owner_user_id'],true);$job=vp3_profile_call_cleanup_job_pa3c($pdo,$final,true);
            if($final['state']!=='ended'||(int)($slot['active_call_id']??0)===(int)$current['id']||$job['run']['status']!=='completed'||$job['action']['status']!=='completed')vp3_profile_call_error_pa3c();
            return ['contract'=>VP3_PROFILE_CALL_PA3C,'call_id'=>(int)$current['id'],'state'=>'ended','reason'=>'closed','receipt_id'=>(int)$receipt['receipt_id']];
        });
    }catch(Throwable $error){
        try{vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$initial,$row,$user,$claim,$token){$current=vp3_profile_call_locked_pa3c($pdo,$initial,true);vp3_profile_call_cleanup_lease_pa3c($pdo,$current,$claim);if($current['state']!=='ending')vp3_profile_call_error_pa3c();if($row&&hash_equals($current['provider_lease_token'],$token))$pdo->prepare("UPDATE profile_calls_pa3c SET provider_lease_token='',provider_lease_kind='',provider_lease_until_ms=0,last_reason='cleanup_failed',updated_at_ms=? WHERE id=?")->execute([vp3_profile_call_now_pa3c(),(int)$current['id']]);agent_job_record_result_v1900($pdo,$user,(int)$current['cleanup_run_id'],(int)$current['cleanup_action_id'],(string)$claim['lease_token'],'profile-call:'.$current['id'].':cleanup-failure:'.(int)($claim['action']['attempt_count']??0),false,'Profile call cleanup is still pending.',['call_id'=>(int)$current['id']],'profile_call_cleanup_pending',true);});}catch(Throwable $ignored){/* An uncertain provider/receipt result never releases the owner slot. */}
        return ['contract'=>VP3_PROFILE_CALL_PA3C,'call_id'=>(int)$initial['id'],'state'=>'ending','reason'=>'cleanup_pending'];
    }
}
function vp3_profile_call_configuration_changed_pa3c(PDO $pdo): array
{
    if(!vp3_profile_call_schema_ready_pa3c($pdo))return ['closed'=>0];$q=$pdo->query("SELECT * FROM profile_calls_pa3c WHERE state IN ('offered','preparing','ready','active') ORDER BY owner_user_id,id");$count=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $initial){vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$initial){$row=vp3_profile_call_locked_pa3c($pdo,$initial,true);vp3_profile_call_end_locked_pa3c($pdo,$row,'configuration_changed');});$count++;}return ['closed'=>$count];
}
function vp3_profile_call_runner_pa3c(PDO $pdo,int $limit=20,?callable $provider=null): array
{
    $out=['checked'=>0,'closed'=>0,'pending'=>0,'failed'=>0];if(!vp3_profile_call_schema_ready_pa3c($pdo))return $out;if($pdo->inTransaction())throw new RuntimeException('Call runner requires no active transaction.');$limit=max(1,min(100,$limit));$now=vp3_profile_call_now_pa3c();
    $when=vp3_profile_handoff_date_pa3($now);
    $q=$pdo->prepare("SELECT c.id FROM profile_calls_pa3c c LEFT JOIN agent_workflow_runs r ON r.id=c.cleanup_run_id AND r.owner_user_id=c.owner_user_id LEFT JOIN agent_workflow_actions a ON a.id=c.cleanup_action_id AND a.run_id=r.id AND a.owner_user_id=c.owner_user_id WHERE (c.provider_lease_token='' OR c.provider_lease_until_ms<=?) AND (
        (c.state='offered' AND c.offer_expires_at_ms<=?) OR
        (c.state='preparing' AND (c.last_reason<>'provider_unavailable' OR c.updated_at_ms<=?)) OR
        (c.state IN ('ready','active') AND (c.provider_observed_at_ms<=? OR (c.connect_until_ms IS NOT NULL AND c.connect_until_ms<=?) OR (c.owner_authority_until_ms>0 AND c.owner_authority_until_ms<=?) OR (c.visitor_authority_until_ms>0 AND c.visitor_authority_until_ms<=?))) OR
        (c.state='ending' AND c.cleanup_not_before_ms<=? AND ((r.status='approved' AND a.status='queued' AND (r.next_attempt_at IS NULL OR r.next_attempt_at<=?) AND (a.available_at IS NULL OR a.available_at<=?)) OR (r.status='executing' AND r.lease_expires_at<?)))
    ) ORDER BY c.updated_at_ms,c.id LIMIT ".$limit);$q->execute([$now,$now,$now-5000,$now-10000,$now,$now,$now,$now,$when,$when,$when]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$out['checked']++;try{$result=vp3_profile_call_work_pa3c($pdo,(int)$id,$provider);if(($result['state']??'')==='ended')$out['closed']++;else $out['pending']++;}catch(Throwable $e){$out['failed']++;}
        // A held or corrupt cleanup cannot permanently head-of-line block another owner's due call.
        $pdo->prepare("UPDATE profile_calls_pa3c SET updated_at_ms=? WHERE id=? AND state='ending' AND provider_lease_token=''")->execute([$now,(int)$id]);
    }
    return $out;
}
function vp3_profile_call_sweep_pa3c(PDO $pdo,int $limit=20,?callable $provider=null): array{return vp3_profile_call_runner_pa3c($pdo,$limit,$provider);}

/** Explicit local Join only. Reserved timestamps cover even a minted credential whose response is lost. */
function vp3_profile_call_join_pa3c(PDO $pdo,string $role,array $auth,array $input,?callable $provider=null): array
{
    vp3_profile_call_require_pa3c($pdo);if($pdo->inTransaction())throw new RuntimeException('Call joining cannot invoke its provider inside a transaction.');
    if(!in_array($role,['owner','visitor'],true)||($input['microphone']??null)!==true||!is_bool($input['camera']??null))vp3_profile_call_error_pa3c('PROFILE_CALL_INVALID',422);
    $id=vp3_profile_call_id_pa3c($input['call_id']??null);$cid=vp3_profile_call_id_pa3c($input['conversation_id']??null);$revision=vp3_profile_call_id_pa3c($input['revision']??null);$request=vp3_profile_call_token_pa3c($input['request_id']??null);$instance=vp3_profile_call_token_pa3c($input['client_instance_id']??null);$instanceHash=hash('sha256',$instance);
    $checked=vp3_profile_call_authorize_pa3c($pdo,$role,$auth,$cid,$input);$auth=$checked['auth'];$owner=$checked['owner'];$actor=hash('sha256',$role.'|'.$owner.'|'.($role==='visitor'?$checked['context']['binding']['credential_hash']:'owner'));
    $hash=hash('sha256',json_encode([$role,$cid,$id,'join',$instanceHash,$input['microphone'],$input['camera'],(int)($input['chat_conversation_id']??0),(int)($input['chat_agent_id']??0)],JSON_THROW_ON_ERROR));
    $initial=vp3_profile_call_row_pa3c($pdo,$id);if(!$initial||(int)$initial['owner_user_id']!==$owner||(int)$initial['conversation_id']!==$cid)vp3_profile_call_error_pa3c('PROFILE_CALL_NOT_FOUND',404);
    $reserved=vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$role,$auth,$input,$owner,$id,$cid,$revision,$request,$instanceHash,$actor,$hash,$initial){
        $row=vp3_profile_call_locked_pa3c($pdo,$initial);vp3_profile_call_authorize_pa3c($pdo,$role,$auth,$cid,$input,$row,true);vp3_profile_call_media_current_pa3c($pdo,$row,true);if($role==='owner')vp3_profile_call_delivery_check_pa3c($pdo,$row,$input);
        if(!in_array($row['state'],['ready','active'],true)||$row['room_sid']===''||($input['camera']&&$row['mode']!=='video'))vp3_profile_call_error_pa3c();$now=vp3_profile_call_now_pa3c();
        if((string)$row[$role.'_client_hash']!==''&&!hash_equals($row[$role.'_client_hash'],$instanceHash))vp3_profile_call_error_pa3c('PROFILE_CALL_IN_USE',409);
        if((string)$row['provider_lease_token']!==''&&(int)$row['provider_lease_until_ms']>$now)vp3_profile_call_error_pa3c('PROFILE_CALL_WORK_PENDING',409);
        $q=$pdo->prepare('SELECT * FROM profile_call_operations_pa3c WHERE owner_user_id=? AND actor_key=? AND request_id=?');$q->execute([$owner,$actor,$request]);$prior=$q->fetch(PDO::FETCH_ASSOC);$duplicate=false;
        if($prior){
            if($prior['operation']!=='join'||(int)$prior['call_id']!==$id||!hash_equals($prior['request_hash'],$hash)||!is_string($prior['result_json'])||strlen($prior['result_json'])>2048)vp3_profile_call_error_pa3c();
            $saved=json_decode($prior['result_json'],true);if(!is_array($saved)||($saved['client_hash']??null)!==$instanceHash||($saved['camera']??null)!==$input['camera']||($saved['microphone']??null)!==true||!is_int($saved['issued_at_ms']??null)||!is_int($saved['expires_at_ms']??null)||$saved['expires_at_ms']<=$now||!is_string($saved['join_intent_id']??null)||!hash_equals($row[$role.'_join_intent'],$saved['join_intent_id']))vp3_profile_call_error_pa3c();$duplicate=true;
        }else{
            if((int)$row['revision']!==$revision||(int)$row[$role.'_token_issued_at_ms']>$now-5000)vp3_profile_call_error_pa3c();
            $intent=$row[$role.'_join_intent']!==''?$row[$role.'_join_intent']:bin2hex(random_bytes(16));$saved=['join_intent_id'=>$intent,'issued_at_ms'=>$now,'expires_at_ms'=>$now+60000,'client_hash'=>$instanceHash,'microphone'=>true,'camera'=>$input['camera']];
            $pdo->prepare('INSERT INTO profile_call_operations_pa3c(owner_user_id,actor_key,request_id,request_hash,call_id,operation,result_json,created_at_ms) VALUES(?,?,?,?,?,\'join\',?,?)')->execute([$owner,$actor,$request,$hash,$id,json_encode($saved,JSON_THROW_ON_ERROR),$now]);
        }
        $token=bin2hex(random_bytes(16));$pdo->prepare("UPDATE profile_calls_pa3c SET ".$role."_client_hash=?,".$role."_join_intent=?,".$role."_authority_until_ms=?,".$role."_token_issued_at_ms=?,revoke_before_sec=?,provider_lease_token=?,provider_lease_kind='token',provider_lease_until_ms=?,updated_at_ms=? WHERE id=?")->execute([$instanceHash,$saved['join_intent_id'],$now+VP3_PROFILE_CALL_AUTH_MS_PA3C,$saved['issued_at_ms'],max((int)$row['revoke_before_sec'],intdiv($saved['issued_at_ms'],1000)+1),$token,$now+VP3_PROFILE_CALL_WORK_MS_PA3C,$now,$id]);
        $row=vp3_profile_call_row_pa3c($pdo,$id,true);vp3_profile_call_authorize_pa3c($pdo,$role,$auth,$cid,$input,$row,true);vp3_profile_call_media_current_pa3c($pdo,$row,true);return compact('row','saved','duplicate');
    });
    $row=$reserved['row'];$token=$row['provider_lease_token'];$saved=$reserved['saved'];
    try{
        $options=['role'=>$role,'join_intent_id'=>$saved['join_intent_id'],'microphone'=>true,'camera'=>$input['camera'],'issued_at_ms'=>$saved['issued_at_ms'],'expires_at_ms'=>$saved['expires_at_ms'],'operation_deadline_ms'=>(int)$row['provider_lease_until_ms']];
        $proof=vp3_profile_call_provider_pa3c('token',$row,$options,$provider);
        if(!is_string($proof['server_url']??null)||!preg_match('#^wss://[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.livekit\.cloud$#D',$proof['server_url'])||!is_string($proof['participant_token']??null)||strlen($proof['participant_token'])<10||strlen($proof['participant_token'])>8192||preg_match('/[\x00-\x20\x7f]/',$proof['participant_token'])
            ||($proof['participant_identity']??null)!==$row[$role.'_identity']||($proof['peer_identity']??null)!==$row[($role==='owner'?'visitor':'owner').'_identity']||($proof['room_sid']??null)!==$row['room_sid']||($proof['config_fingerprint']??null)!==$row['provider_config_fingerprint']||($proof['issued_at_ms']??null)!==$saved['issued_at_ms']||($proof['expires_at_ms']??null)!==$saved['expires_at_ms'])vp3_profile_call_error_pa3c('PROFILE_CALL_PROVIDER_INVALID',502);
        return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$role,$auth,$input,$cid,$id,$request,$row,$token,$proof,$saved,$reserved,$instanceHash){
            $current=vp3_profile_call_locked_pa3c($pdo,$row);$now=vp3_profile_call_now_pa3c();
            if(!in_array($current['state'],['ready','active'],true)||!hash_equals($current['provider_lease_token'],$token)||(int)$current['provider_lease_until_ms']<=$now||!hash_equals($current[$role.'_client_hash'],$instanceHash)||!hash_equals($current[$role.'_join_intent'],$saved['join_intent_id'])||(int)$current[$role.'_authority_until_ms']<=$now||$saved['expires_at_ms']<=$now)vp3_profile_call_error_pa3c();
            vp3_profile_call_authorize_pa3c($pdo,$role,$auth,$cid,$input,$current,true);vp3_profile_call_media_current_pa3c($pdo,$current,true);if($role==='owner')vp3_profile_call_delivery_check_pa3c($pdo,$current,$input);
            $current=vp3_profile_call_settled_pa3c($pdo,$current,$token);$reply=vp3_profile_call_reply_pa3c($pdo,$current,$role,$input,$request,$reserved['duplicate']);
            $reply+=['role'=>$role,'join_intent_id'=>$saved['join_intent_id'],'client_instance_id'=>$input['client_instance_id'],'server_url'=>$proof['server_url'],'participant_token'=>$proof['participant_token'],'participant_identity'=>$proof['participant_identity'],'peer_identity'=>$proof['peer_identity'],'room_sid'=>$proof['room_sid'],'issued_at_ms'=>$proof['issued_at_ms'],'expires_at_ms'=>$proof['expires_at_ms'],'authority_valid_until_ms'=>(int)$current[$role.'_authority_until_ms'],'microphone'=>true,'camera'=>$input['camera']];
            // No token can leave this function until current native/owner authority and consent are checked again.
            vp3_profile_call_authorize_pa3c($pdo,$role,$auth,$cid,$input,$current,true);vp3_profile_call_media_current_pa3c($pdo,$current,true);return $reply;
        });
    }catch(Throwable $error){
        $permanent=$error instanceof VP3ProfileCallException||$error instanceof VP3ProfileAgentPublicException||(property_exists($error,'retryable')&&$error->retryable===false);
        $reason=property_exists($error,'publicCode')&&$error->publicCode==='CALL_PROVIDER_CONFIGURATION_CHANGED'?'configuration_changed':(($error instanceof VP3ProfileAgentPublicException||($error instanceof VP3ProfileCallException&&$error->httpStatus===403))?'authority_revoked':'provider_unsafe');
        try{vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$row,$token,$permanent,$reason){$current=vp3_profile_call_locked_pa3c($pdo,$row,true);if(!hash_equals((string)$current['provider_lease_token'],$token))return;$current=vp3_profile_call_settled_pa3c($pdo,$current,$token);if($permanent&&in_array($current['state'],['preparing','ready','active'],true))vp3_profile_call_end_locked_pa3c($pdo,$current,$reason);});}catch(Throwable $ignored){/* Reserved cutoff and owner slot remain durable for cleanup. */}
        if($error instanceof VP3ProfileCallException||$error instanceof VP3ProfileAgentPublicException)throw $error;vp3_profile_call_error_pa3c('PROFILE_CALL_MEDIA_UNAVAILABLE',503);
    }
}
