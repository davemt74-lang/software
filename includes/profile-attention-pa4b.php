<?php
declare(strict_types=1);

/** Profile attention is a current projection of saved evidence, never a public action. */
const VP3_PROFILE_ATTENTION_PA4B='vp3.profile-attention.v1';
final class VP3ProfileAttentionException extends RuntimeException
{
    public function __construct(public readonly string $publicCode,int $status=409){parent::__construct(match($publicCode){
        'PROFILE_ATTENTION_INVALID'=>'Choose a valid attention action.',
        'PROFILE_ATTENTION_NOT_READY'=>'Profile attention needs the current database upgrade.',
        'PROFILE_ATTENTION_UNAVAILABLE'=>'This Profile attention is no longer available.',
        'PROFILE_ATTENTION_NOT_FOUND'=>'This Profile attention was not found.',
        'PROFILE_ATTENTION_READ_FAILED'=>'Profile attention could not be read. Try again.',
        default=>'This attention changed. Refresh it before continuing.',
    },$status);$this->httpStatus=$status;}
    public readonly int $httpStatus;
}
function vp3_profile_attention_error_pa4b(string $code='PROFILE_ATTENTION_CHANGED',int $status=409): never{throw new VP3ProfileAttentionException($code,$status);}
function vp3_profile_attention_dependencies_pa4b(): void
{
    if(!function_exists('profile_agent_ensure_schema'))require_once __DIR__.'/profile-agent.php';
    if(!function_exists('vp3_profile_agent_public_conversation_v390'))require_once __DIR__.'/profile-agent-boundary-v390.php';
    if(!function_exists('vp3_profile_handoff_owner_pa3'))require_once __DIR__.'/profile-agent-handoff-pa3.php';
}
function vp3_profile_attention_now_pa4b(): int{return function_exists('vp3_profile_handoff_now_pa3')?vp3_profile_handoff_now_pa3():(int)floor(microtime(true)*1000);}
function vp3_profile_attention_lock_pa4b(PDO $pdo): string{return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
function vp3_profile_attention_id_pa4b(mixed $value,bool $zero=false): int
{
    if(!is_int($value)&&!(is_string($value)&&preg_match('/^(0|[1-9][0-9]{0,14})$/D',$value)))vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_INVALID',422);
    $value=(int)$value;if($value<($zero?0:1)||$value>9007199254740991)vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_INVALID',422);return $value;
}
function vp3_profile_attention_token_pa4b(mixed $value): string
{
    if(!is_string($value)||!preg_match('/^[a-f0-9]{32}$/D',$value))vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_INVALID',422);return $value;
}
function vp3_profile_attention_atomic_pa4b(PDO $pdo,callable $run): mixed
{
    $owns=!$pdo->inTransaction();$save='attention_'.bin2hex(random_bytes(6));
    if($owns){if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$pdo->beginTransaction();}else $pdo->exec('SAVEPOINT '.$save);
    try{$out=$run();if($owns)$pdo->commit();else $pdo->exec('RELEASE SAVEPOINT '.$save);return $out;}catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();elseif(!$owns&&$pdo->inTransaction()){$pdo->exec('ROLLBACK TO SAVEPOINT '.$save);$pdo->exec('RELEASE SAVEPOINT '.$save);}throw $e;}
}
function vp3_profile_attention_has_column_pa4b(PDO $pdo,string $table,string $column): bool
{
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){$rows=$pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC);return in_array($column,array_column($rows,'name'),true);}
    $q=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$table,$column]);return(bool)$q->fetchColumn();
}
function vp3_profile_attention_schema_ready_pa4b(PDO $pdo): bool
{
    try{
        $pdo->query('SELECT id,owner_user_id,source_event_id,source_conversation_id,profile_agent_id,visitor_user_id,attention_type,status,snoozed_until,state_revision,wake_generation,read_generation,state_changed_at_ms,source_hash FROM agent_attention_items WHERE 1=0');
        $pdo->query('SELECT id,user_id,type,title,body,target_url,source_type,source_id,is_read,read_at,created_at FROM notifications WHERE 1=0');
        $pdo->query('SELECT id,owner_user_id,request_id,request_hash,operation,attention_id,notification_id,expected_revision,expected_generation,result_revision,result_generation,result_status,result_json,created_at_ms FROM profile_attention_operations_pa4b WHERE 1=0');
        $pdo->query('SELECT id,owner_user_id,attention_id,notification_id,profile_event_id,source_hash,wake_generation,chat_conversation_id,chat_agent_id,chat_message_id,created_at_ms,presented_at_ms,presented_tab_hash,presented_client_hash FROM profile_attention_deliveries_pa4b WHERE 1=0');return true;
    }catch(PDOException $e){return false;}
}
function vp3_profile_attention_ensure_schema_pa4b(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Attention installation requires no active transaction.');vp3_profile_attention_dependencies_pa4b();
    if(!vp3_profile_attention_has_column_pa4b($pdo,'agent_attention_items','id'))profile_agent_ensure_schema($pdo);
    // MySQL DDL commits independently. A sentinel survives interruption between
    // adding this field and importing the old, exact notification read receipt.
    foreach(['state_revision'=>'BIGINT NOT NULL DEFAULT 1','wake_generation'=>'BIGINT NOT NULL DEFAULT 1','read_generation'=>'BIGINT NOT NULL DEFAULT -1','state_changed_at_ms'=>'BIGINT NOT NULL DEFAULT 0','source_hash'=>"CHAR(64) NOT NULL DEFAULT ''"] as $column=>$definition)if(!vp3_profile_attention_has_column_pa4b($pdo,'agent_attention_items',$column))$pdo->exec('ALTER TABLE agent_attention_items ADD COLUMN '.$column.' '.$definition);
    if(vp3_profile_attention_has_column_pa4b($pdo,'notifications','is_read'))$pdo->exec("UPDATE agent_attention_items SET read_generation=CASE WHEN EXISTS(SELECT 1 FROM notifications n WHERE n.user_id=agent_attention_items.owner_user_id AND n.source_type='profile_event' AND n.source_id=agent_attention_items.source_event_id AND n.is_read=1) THEN wake_generation ELSE 0 END WHERE read_generation=-1");
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';$auto=$sqlite?'INTEGER PRIMARY KEY AUTOINCREMENT':'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';$suffix=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_attention_operations_pa4b(id $auto,owner_user_id BIGINT NOT NULL,request_id CHAR(32) NOT NULL,request_hash CHAR(64) NOT NULL,operation VARCHAR(24) NOT NULL,attention_id BIGINT NOT NULL,notification_id BIGINT NOT NULL,expected_revision BIGINT NOT NULL,expected_generation BIGINT NOT NULL,result_revision BIGINT NOT NULL,result_generation BIGINT NOT NULL,result_status VARCHAR(20) NOT NULL,result_json TEXT NOT NULL,created_at_ms BIGINT NOT NULL,UNIQUE(owner_user_id,request_id))".$suffix);
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_attention_deliveries_pa4b(id $auto,owner_user_id BIGINT NOT NULL,attention_id BIGINT NOT NULL,notification_id BIGINT NOT NULL,profile_event_id BIGINT NOT NULL,source_hash CHAR(64) NOT NULL,wake_generation BIGINT NOT NULL,chat_conversation_id BIGINT NOT NULL,chat_agent_id BIGINT NOT NULL,chat_message_id BIGINT NOT NULL DEFAULT 0,created_at_ms BIGINT NOT NULL,presented_at_ms BIGINT NULL,presented_tab_hash CHAR(64) NOT NULL DEFAULT '',presented_client_hash CHAR(64) NOT NULL DEFAULT '',UNIQUE(owner_user_id,attention_id,wake_generation))".$suffix);
    foreach([['agent_attention_items','idx_attention_wake_pa4b','status,snoozed_until,id'],['profile_attention_deliveries_pa4b','idx_attention_chat_pa4b','owner_user_id,chat_conversation_id,chat_agent_id,id']] as [$table,$name,$columns]){
        $q=$pdo->prepare($sqlite?"SELECT name FROM sqlite_master WHERE type='index' AND name=?":'SELECT INDEX_NAME FROM information_schema.statistics WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');$q->execute($sqlite?[$name]:[$table,$name]);if(!$q->fetchColumn())$pdo->exec('CREATE INDEX '.$name.' ON '.$table.'('.$columns.')');
    }
}
function vp3_profile_attention_require_pa4b(PDO $pdo): void
{
    vp3_profile_attention_dependencies_pa4b();if(!vp3_profile_attention_schema_ready_pa4b($pdo))vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_NOT_READY',503);
}
function vp3_profile_attention_owner_pa4b(PDO $pdo,array $user,array $context=[]): array
{
    vp3_profile_attention_dependencies_pa4b();$cid=vp3_profile_attention_id_pa4b($context['conversation_id']??0,true);$aid=vp3_profile_attention_id_pa4b($context['agent_id']??0,true);
    try{return vp3_profile_handoff_owner_pa3($pdo,$user,$cid,$aid,$cid===0);}catch(VP3ProfileHandoffException $e){vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_UNAVAILABLE',403);}
}
function vp3_profile_attention_json_pa4b(mixed $value,int $limit=65536): ?array
{
    if(!is_string($value)||strlen($value)>$limit||trim($value)==='')return null;$decoded=json_decode($value,true,32);return is_array($decoded)?$decoded:null;
}
function vp3_profile_attention_timestamp_pa4b(mixed $value): ?int
{
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$value))return null;$date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));return $date&&$date->format('Y-m-d H:i:s')===$value?$date->getTimestamp()*1000:null;
}
/** Capture only an actual native admission; an aggregate CRM session is not its actor. */
function vp3_profile_attention_capture_source_pa4b(PDO $pdo,int $owner,array $session,int $agentId,array $metadata): ?array
{
    if(!function_exists('current_user')||!function_exists('profile_conversation_native_authority_v1')||!is_string($_COOKIE['sf_profile_conversation']??null)||!preg_match('/^[a-f0-9]{64}$/D',$_COOKIE['sf_profile_conversation']))return null;
    $visitor=current_user();$visitorId=(int)($visitor['id']??0);if($visitorId===$owner||$visitorId!==(int)($session['visitor_user_id']??0)||(int)$session['owner_user_id']!==$owner)return null;
    $authority=profile_conversation_native_authority_v1($owner,$visitorId);$cid=is_int($metadata['conversation_id']??null)?$metadata['conversation_id']:0;$mid=is_int($metadata['message_id']??null)?$metadata['message_id']:0;
    if($cid>0&&!vp3_profile_agent_public_conversation_v390($pdo,$cid,$owner,$agentId,(int)$session['id'],false,$authority))return null;
    $sha='';if($mid>0){$q=$pdo->prepare("SELECT message,sender_user_id FROM profile_agent_messages WHERE id=? AND conversation_id=? AND sender_type='visitor'");$q->execute([$mid,$cid]);$message=$q->fetch(PDO::FETCH_ASSOC);if(!$message||(int)($message['sender_user_id']??0)!==$visitorId)return null;$sha=hash('sha256',(string)$message['message']);}
    $q=$pdo->prepare('SELECT username FROM user_profiles WHERE user_id=?');$q->execute([$owner]);$username=$q->fetchColumn();if(!is_string($username)||$username==='')return null;
    $revision='';if($visitorId>0){$q=$pdo->prepare('SELECT password_hash,is_active FROM users WHERE id=?');$q->execute([$visitorId]);$member=$q->fetch(PDO::FETCH_ASSOC);if(!$member||empty($member['is_active']))return null;$revision=hash('sha256',$visitorId.'|'.(string)$member['password_hash']);}
    return ['contract'=>'vp3.profile-event-authority.v1','owner_user_id'=>$owner,'profile_agent_id'=>$agentId,'profile_session_id'=>(int)$session['id'],'visitor_user_id'=>$visitorId,'credential_hash'=>$authority['credential_hash'],'session_hash'=>hash('sha256',(string)$session['session_key']),'profile_username'=>$username,'visitor_auth_revision'=>$revision,'public_conversation_id'=>$cid,'accepted_message_id'=>$mid,'accepted_message_sha256'=>$sha];
}
function vp3_profile_attention_native_source_pa4b(PDO $pdo,array $proof,array $event,?array $profile,?array $session,?array $binding,int $cid): bool
{
    if(($proof['contract']??null)!=='vp3.profile-event-authority.v1'||strlen(json_encode($proof,JSON_THROW_ON_ERROR))>4096)return false;
    foreach(['owner_user_id','profile_agent_id','profile_session_id','visitor_user_id'] as $key)if(!is_int($proof[$key]??null)||$proof[$key]!== (int)($event[$key]??0))return false;
    foreach(['credential_hash','session_hash'] as $key)if(!is_string($proof[$key]??null)||!preg_match('/^[a-f0-9]{64}$/D',$proof[$key]))return false;
    if(!$profile||!$session||($proof['profile_username']??null)!==$profile['username']||!hash_equals($proof['session_hash'],hash('sha256',(string)$session['session_key']))||($proof['public_conversation_id']??null)!==$cid)return false;
    if($cid>0&&(!$binding||($binding['transport']??null)!=='native'||!hash_equals($proof['credential_hash'],(string)$binding['credential_hash'])))return false;
    $visitor=$proof['visitor_user_id'];$revision='';if($visitor>0){$q=$pdo->prepare('SELECT password_hash,is_active FROM users WHERE id=?');$q->execute([$visitor]);$member=$q->fetch(PDO::FETCH_ASSOC);if(!$member||empty($member['is_active']))return false;$revision=hash('sha256',$visitor.'|'.(string)$member['password_hash']);}
    if(!is_string($proof['visitor_auth_revision']??null)||!hash_equals($revision,$proof['visitor_auth_revision']))return false;
    if(!is_int($proof['accepted_message_id']??null)||$proof['accepted_message_id']<0||!is_string($proof['accepted_message_sha256']??null))return false;
    if($proof['accepted_message_id']>0){$q=$pdo->prepare("SELECT message,sender_user_id FROM profile_agent_messages WHERE id=? AND conversation_id=? AND sender_type='visitor'");$q->execute([$proof['accepted_message_id'],$cid]);$message=$q->fetch(PDO::FETCH_ASSOC);if(!$message||(int)($message['sender_user_id']??0)!==$visitor||!hash_equals($proof['accepted_message_sha256'],hash('sha256',(string)$message['message'])))return false;}
    elseif($proof['accepted_message_sha256']!=='')return false;
    return true;
}
/** A saved PA3 admission can prove a historical statement without authorizing new inference. */
function vp3_profile_attention_accepted_source_pa4b(PDO $pdo,array $event,?array $profile,?array $agent,?array $session,?array $binding,int $cid,int $mid): ?bool
{
    if($cid<1||!function_exists('vp3_profile_handoff_schema_ready_pa3')||!vp3_profile_handoff_schema_ready_pa3($pdo))return null;
    $filter=$event['event_type']==='message_received'?' AND visitor_message_id=?':($mid>0?' AND visitor_message_id<?':'');
    $q=$pdo->prepare('SELECT * FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND conversation_id=?'.$filter.' ORDER BY visitor_message_id DESC LIMIT 1');$params=[(int)$event['owner_user_id'],$cid];if($filter!=='')$params[]=$mid;$q->execute($params);$turn=$q->fetch(PDO::FETCH_ASSOC);if(!$turn)return null;
    foreach(['owner_user_id','profile_agent_id','profile_session_id','visitor_user_id'] as $field)if((int)$turn[$field]!== (int)($event[$field]??0))return false;
    if(!$profile||!$agent||!$session||!$binding||$turn['transport']!=='native'||!hash_equals((string)$turn['credential_hash'],(string)($binding['credential_hash']??'')))return false;
    $snapshot=vp3_profile_attention_json_pa4b($turn['accepted_json']??null,4096);if(($snapshot['contract']??null)!=='vp3.profile-accepted-turn.pa3')return false;
    if(!function_exists('vp3_profile_agent_accepted_fingerprint_pa3'))require_once __DIR__.'/profile-agent-accepted-turn-pa3.php';
    foreach(['profile_fingerprint'=>[$profile,['user_id','username','display_name','bio','profile_agent_instructions','profile_agent_greeting']],'agent_fingerprint'=>[$agent,['id','owner_user_id','display_name','is_active']],'session_fingerprint'=>[$session,['id','owner_user_id','session_key']]] as $field=>[$row,$keys])if(!is_string($snapshot[$field]??null)||!hash_equals($snapshot[$field],vp3_profile_agent_accepted_fingerprint_pa3($row,$keys)))return false;
    $proof=['contract'=>'vp3.profile-event-authority.v1','owner_user_id'=>(int)$turn['owner_user_id'],'profile_agent_id'=>(int)$turn['profile_agent_id'],'profile_session_id'=>(int)$turn['profile_session_id'],'visitor_user_id'=>(int)$turn['visitor_user_id'],'credential_hash'=>$turn['credential_hash'],'session_hash'=>hash('sha256',(string)$session['session_key']),'profile_username'=>$profile['username'],'visitor_auth_revision'=>$snapshot['visitor_auth_revision']??null,'public_conversation_id'=>$cid,'accepted_message_id'=>(int)$turn['visitor_message_id'],'accepted_message_sha256'=>$snapshot['message_sha256']??null];
    return vp3_profile_attention_native_source_pa4b($pdo,$proof,$event,$profile,$session,$binding,$cid);
}
/** Immutable event/message evidence is distinct from current display consent. */
function vp3_profile_event_source_pa4b(PDO $pdo,array $user,int $eventId,array $context=[],bool $lock=false): array
{
    $user=vp3_profile_attention_owner_pa4b($pdo,$user,$context);$owner=(int)$user['id'];$suffix=$lock?vp3_profile_attention_lock_pa4b($pdo):'';
    $q=$pdo->prepare('SELECT * FROM profile_events WHERE id=? AND owner_user_id=?');$q->execute([$eventId,$owner]);$event=$q->fetch(PDO::FETCH_ASSOC);if(!$event)vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_NOT_FOUND',404);
    $metadata=vp3_profile_attention_json_pa4b($event['metadata_json']??null);$type=(string)$event['event_type'];$cid=is_int($metadata['conversation_id']??null)?max(0,$metadata['conversation_id']):0;$mid=is_int($metadata['message_id']??null)?max(0,$metadata['message_id']):0;
    $status='verified';$reason='';$sender='system';$senderId=null;$body='';$dependencies=[];$hash='';$conversation=null;$binding=null;$message=null;$visitor=(int)($event['visitor_user_id']??0);$aid=(int)($event['profile_agent_id']??0);$sid=(int)$event['profile_session_id'];
    $q=$pdo->prepare('SELECT p.*,u.display_name FROM user_profiles p INNER JOIN users u ON u.id=p.user_id WHERE p.user_id=?');$q->execute([$owner]);$profile=$q->fetch(PDO::FETCH_ASSOC)?:null;
    $q=$pdo->prepare('SELECT * FROM user_agents WHERE id=? AND owner_user_id=?');$q->execute([$aid,$owner]);$agent=$q->fetch(PDO::FETCH_ASSOC)?:null;
    $q=$pdo->prepare('SELECT id,owner_user_id,session_key FROM profile_visit_sessions WHERE id=? AND owner_user_id=?');$q->execute([$sid,$owner]);$session=$q->fetch(PDO::FETCH_ASSOC)?:null;
    $revoked=!$session||!$profile||empty($profile['is_public'])||empty($profile['profile_agent_enabled'])||!$agent||empty($agent['is_active'])||(int)$profile['profile_agent_id']!==$aid;
    if(!vp3_observation_personal_permission_v1($pdo,$user,'profile_chat.access'))$revoked=true;
    if($revoked){$status='unavailable';$reason='source_revoked';}
    if($cid>0){
        $q=$pdo->prepare('SELECT * FROM profile_agent_conversations WHERE id=? AND owner_user_id=?'.$suffix);$q->execute([$cid,$owner]);$conversation=$q->fetch(PDO::FETCH_ASSOC);
        $q=$pdo->prepare("SELECT context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type='system' ORDER BY id ASC LIMIT 2");$q->execute([$cid]);$bindings=$q->fetchAll(PDO::FETCH_COLUMN);$binding=count($bindings)===1?vp3_profile_attention_json_pa4b($bindings[0]):null;
        if(!$conversation||(int)$conversation['profile_agent_id']!==$aid||(int)$conversation['profile_session_id']!==$sid||(int)($conversation['visitor_user_id']??0)!==$visitor||!vp3_profile_agent_conversation_authority_valid_v1($binding,$owner)||(int)($binding['profile_agent_id']??0)!==$aid||(int)($binding['profile_session_id']??0)!==$sid||(int)($binding['visitor_user_id']??-1)!==$visitor){$status='historical_unverified';$reason='source_binding_unverified';}
    }
    if(in_array($type,['message_received','message_sent'],true)){
        if($cid<1||$mid<1||!$conversation){$status='historical_unverified';$reason='source_message_unverified';}
        else{
            $q=$pdo->prepare('SELECT id,conversation_id,sender_type,sender_user_id,message,context_json,created_at FROM profile_agent_messages WHERE id=? AND conversation_id=?');$q->execute([$mid,$cid]);$message=$q->fetch(PDO::FETCH_ASSOC);
            $sender=(string)($message['sender_type']??'system');$senderId=$message&&$message['sender_user_id']!==null?(int)$message['sender_user_id']:null;
            if(!$message||!in_array($sender,['visitor','owner','agent'],true)||($type==='message_received'&&$sender!=='visitor')||($type==='message_sent'&&$sender==='visitor')||($sender==='visitor'&&(int)($senderId??0)!==$visitor)||($sender==='owner'&&$senderId!==$owner)||($sender==='agent'&&$senderId!==null)||!is_string($message['message']??null)||trim($message['message'])===''||strlen($message['message'])>32768){$status='historical_unverified';$reason='source_message_unverified';}
            else{
                $body=$message['message'];$hash=hash('sha256',$body);if($sender==='visitor'&&$senderId===null&&$visitor===0)$senderId=0;
                if($sender==='agent'){
                    $saved=vp3_profile_attention_json_pa4b($message['context_json']??null);$dependencies=$saved['source_dependencies']??$saved['sources']??null;
                    if(!is_array($dependencies)||!array_is_list($dependencies)||count($dependencies)>128){$status='historical_unverified';$reason='source_dependencies_unverified';$dependencies=[];}
                    elseif($profile&&$agent){
                        if(!function_exists('vp3_profile_agent_public_sources_current_v1'))require_once __DIR__.'/profile-agent-public-service-v110.php';
                        $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$visitor]);$visitorRow=$q->fetch(PDO::FETCH_ASSOC)?:null;
                        if(!vp3_profile_agent_public_sources_current_v1($pdo,['profile'=>$profile,'agent'=>$agent,'visitor'=>$visitorRow],$dependencies,[])){$revoked=true;$status='unavailable';$reason='source_dependencies_revoked';}
                    }
                }
            }
        }
    }elseif($type==='conversation_started'){
        if($cid<1){$status='historical_unverified';$reason='source_binding_unverified';}$sender='visitor';$senderId=$visitor;$body='A visitor started a conversation with your Profile Agent.';
    }elseif(in_array($type,['profile_view','return_visitor'],true)){$sender='visitor';$senderId=$visitor;$body=$type==='return_visitor'?'A visitor returned to your Profile.':'A visitor viewed your Profile.';}
    elseif($type==='needs_owner'){$body='Your Profile Agent requested owner attention.';if($cid<1){$status='historical_unverified';$reason='source_binding_unverified';}}
    else{$body='A saved Profile event requires review.';}
    if($visitor>0){
        $q=$pdo->prepare('SELECT id,is_active,display_name FROM users WHERE id=?');$q->execute([$visitor]);$visitorRow=$q->fetch(PDO::FETCH_ASSOC);
        if(!$visitorRow||empty($visitorRow['is_active'])){$revoked=true;$status='unavailable';$reason='source_revoked';}
        $q=$pdo->prepare('SELECT 1 FROM user_blocks WHERE (blocker_user_id=? AND blocked_user_id=?) OR (blocker_user_id=? AND blocked_user_id=?) LIMIT 1');$q->execute([$owner,$visitor,$visitor,$owner]);if($q->fetchColumn()){$revoked=true;$status='unavailable';$reason='source_revoked';}
    }
    $proof=$metadata['source_authority']??null;
    if(is_array($proof)){if(!vp3_profile_attention_native_source_pa4b($pdo,$proof,$event,$profile,$session,$binding,$cid)){$revoked=true;$reason='source_authority_revoked';}}
    else{
        $proved=false;
        if(function_exists('vp3_profile_speech_schema_ready_pa4a')&&vp3_profile_speech_schema_ready_pa4a($pdo)){
            $query=$cid>0?"SELECT * FROM profile_speech_items_pa4a WHERE owner_user_id=? AND public_conversation_id=? AND kind='visitor_message'".($type==='message_received'?' AND visitor_message_id=?':'')." ORDER BY id DESC LIMIT 1":"SELECT * FROM profile_speech_items_pa4a WHERE owner_user_id=? AND source_event_id=? AND kind='arrival' LIMIT 1";
            $params=$cid>0?[$owner,$cid]:[$owner,$eventId];if($type==='message_received'&&$cid>0)$params[]=$mid;$q=$pdo->prepare($query);$q->execute($params);$speech=$q->fetch(PDO::FETCH_ASSOC);
            if($speech){if((int)$speech['profile_agent_id']!==$aid||(int)$speech['profile_session_id']!==$sid||(int)$speech['visitor_user_id']!==$visitor){$revoked=true;$reason='source_authority_revoked';}else{try{vp3_profile_speech_source_pa4a($pdo,$speech,$lock);$proved=true;}catch(VP3ProfileSpeechException $e){$revoked=true;$reason='source_authority_revoked';}}}
        }
        if(!$proved&&!$revoked){$accepted=vp3_profile_attention_accepted_source_pa4b($pdo,$event,$profile,$agent,$session,$binding,$cid,$mid);if($accepted===true)$proved=true;elseif($accepted===false){$revoked=true;$reason='source_authority_revoked';}}
        if(!$proved&&!$revoked){$status='historical_unverified';$reason='source_authority_unverified';}
    }
    $label=match($sender){'owner'=>'You','agent'=>'Profile Agent',default=>'A visitor'};
    if($sender==='visitor'&&$visitor>0){$q=$pdo->prepare('SELECT share_visit_identity FROM user_profiles WHERE user_id=?');$q->execute([$visitor]);if(in_array($q->fetchColumn(),[1,'1',true],true)&&!empty($visitorRow['display_name']))$label=mb_substr(trim((string)$visitorRow['display_name']),0,100);}
    if($sender==='agent'&&$agent)$label=mb_substr(trim((string)($agent['display_name']??''))?:'Profile Agent',0,100);
    $occurred=(string)$event['created_at'];if(vp3_profile_attention_timestamp_pa4b($occurred)===null){$status='historical_unverified';$reason='source_time_unverified';}
    if($revoked){$status='unavailable';if(!str_contains($reason,'revoked'))$reason='source_revoked';}
    $identity=['owner_user_id'=>$owner,'profile_event_id'=>$eventId,'event_type'=>$type,'occurred_at'=>$occurred,'profile_agent_id'=>$aid,'profile_session_id'=>$sid,'public_conversation_id'=>$cid,'public_message_id'=>$mid,'sender_type'=>$sender,'sender_user_id'=>$senderId,'statement_sha256'=>$hash];
    $sourceHash=hash('sha256',json_encode([$identity,$metadata,$dependencies,$binding?[$binding['transport']??null,$binding['credential_hash']??null]:null],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    if($status!=='verified')$label='Profile source';
    $projection=['title'=>$status==='verified'&&in_array($type,['message_received','message_sent'],true)?$label.' sent a Profile message':'Profile attention','body'=>$status==='verified'?mb_substr($body,0,4000):'The saved source is no longer available for current presentation.','speaker_label'=>$label,'actor_role'=>$status==='verified'?$sender:'system'];
    vp3_profile_attention_owner_pa4b($pdo,$user,$context);
    return ['contract'=>'vp3.profile-source.v1','status'=>$status,'reason'=>$reason]+$identity+['source_hash'=>$sourceHash,'source_dependencies'=>$dependencies,'presentation_owner'=>'profile_attention_pa4b','projection'=>$projection,'event'=>$event];
}
function vp3_profile_attention_source_pa4b(PDO $pdo,array $user,int $notificationId,array $context=[],bool $lock=false): array
{
    $user=vp3_profile_attention_owner_pa4b($pdo,$user,$context);$owner=(int)$user['id'];$q=$pdo->prepare('SELECT * FROM notifications WHERE id=? AND user_id=?');$q->execute([$notificationId,$owner]);$note=$q->fetch(PDO::FETCH_ASSOC);
    if(!$note||$note['source_type']!=='profile_event'||(int)$note['source_id']<1)vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_NOT_FOUND',404);
    $q=$pdo->prepare('SELECT * FROM agent_attention_items WHERE source_event_id=? AND owner_user_id=? LIMIT 2');$q->execute([(int)$note['source_id'],$owner]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);if(count($rows)!==1)vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_UNAVAILABLE',403);$attention=$rows[0];
    $source=vp3_profile_event_source_pa4b($pdo,$user,(int)$note['source_id'],$context,$lock);
    if((int)($attention['source_conversation_id']??0)!==$source['public_conversation_id']||(int)($attention['profile_agent_id']??0)!==$source['profile_agent_id']||(int)($attention['visitor_user_id']??0)!==(int)($source['event']['visitor_user_id']??0)||$attention['attention_type']!==$source['event_type'])vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_UNAVAILABLE',403);
    if(function_exists('vp3_profile_handoff_notification_owned_pa3')&&vp3_profile_handoff_notification_owned_pa3($pdo,$user,$note))$source['presentation_owner']='profile_handoff_pa3';
    elseif(function_exists('vp3_profile_speech_notification_owned_pa4a')&&vp3_profile_speech_notification_owned_pa4a($pdo,$user,$note))$source['presentation_owner']='profile_speech_pa4a';
    return $source+['notification'=>$note,'attention'=>$attention];
}
/** Called only by the canonical reply/resolve transaction after its conversation lock. */
function vp3_profile_attention_settle_conversation_pa4b(PDO $pdo,int $owner,int $cid,?string $attentionType=null): void
{
    if(!$pdo->inTransaction())throw new RuntimeException('Attention settlement requires the canonical conversation transaction.');
    $versioned=vp3_profile_attention_has_column_pa4b($pdo,'agent_attention_items','state_revision');$extra=$versioned?',state_revision=state_revision+1,state_changed_at_ms=?':'';$params=$versioned?[vp3_profile_attention_now_pa4b(),$owner,$cid]:[$owner,$cid];$filter='';if($attentionType!==null){$filter=' AND attention_type=?';$params[]=$attentionType;}
    $pdo->prepare("UPDATE agent_attention_items SET status='handled',snoozed_until=NULL".$extra." WHERE owner_user_id=? AND source_conversation_id=? AND status IN ('pending','seen','snoozed')".$filter)->execute($params);
}
function vp3_profile_attention_message_sent_pa4b(PDO $pdo,array $conversation,int $messageId): ?array
{
    if(!$pdo->inTransaction())throw new RuntimeException('Profile statement evidence requires the message transaction.');
    $q=$pdo->prepare('SELECT * FROM profile_agent_messages WHERE id=? AND conversation_id=?');$q->execute([$messageId,(int)$conversation['id']]);$message=$q->fetch(PDO::FETCH_ASSOC);$owner=(int)$conversation['owner_user_id'];
    if(!$message||!in_array($message['sender_type'],['owner','agent'],true)||($message['sender_type']==='owner'&&(int)$message['sender_user_id']!==$owner)||($message['sender_type']==='agent'&&$message['sender_user_id']!==null))vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_UNAVAILABLE',403);
    $q=$pdo->prepare('SELECT * FROM profile_visit_sessions WHERE id=? AND owner_user_id=?');$q->execute([(int)$conversation['profile_session_id'],$owner]);$session=$q->fetch(PDO::FETCH_ASSOC);if(!$session)vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_UNAVAILABLE',403);$session['visitor_user_id']=$conversation['visitor_user_id'];
    $metadata=['conversation_id'=>(int)$conversation['id'],'message_id'=>$messageId,'sender_type'=>$message['sender_type'],'sender_user_id'=>$message['sender_user_id']===null?null:(int)$message['sender_user_id']];
    $q=$pdo->prepare("SELECT metadata_json FROM profile_events WHERE owner_user_id=? AND profile_session_id=? AND event_type='message_received' ORDER BY id DESC LIMIT 100");$q->execute([$owner,(int)$conversation['profile_session_id']]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $raw){$prior=vp3_profile_attention_json_pa4b($raw);if(($prior['conversation_id']??null)===(int)$conversation['id']&&is_array($prior['source_authority']??null)){$metadata['source_authority']=$prior['source_authority'];break;}}
    return profile_event_create($pdo,$owner,$session,'message_sent',10,(int)$conversation['profile_agent_id'],$metadata,hash('sha256','profile-message-sent|'.$owner.'|'.$messageId));
}

function vp3_profile_attention_disposition_pa4b(array $row): string
{
    return match($row['status']??null){'pending','seen'=>'pending','snoozed'=>'snoozed','handled'=>'handled','ignored'=>'ignored',default=>'ignored'};
}
function vp3_profile_attention_due_pa4b(array $row): bool
{
    $until=vp3_profile_attention_timestamp_pa4b($row['snoozed_until']??null);return $row['status']==='snoozed'&&$until!==null&&$until<=vp3_profile_attention_now_pa4b();
}
function vp3_profile_attention_row_pa4b(PDO $pdo,int $owner,int $id,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM agent_attention_items WHERE id=? AND owner_user_id=?'.($lock?vp3_profile_attention_lock_pa4b($pdo):''));$q->execute([$id,$owner]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function vp3_profile_attention_delivery_row_pa4b(PDO $pdo,int $owner,int $attentionId,int $generation): ?array
{
    $q=$pdo->prepare('SELECT * FROM profile_attention_deliveries_pa4b WHERE owner_user_id=? AND attention_id=? AND wake_generation=?');$q->execute([$owner,$attentionId,$generation]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function vp3_profile_attention_delivery_valid_pa4b(PDO $pdo,array $delivery,array $source): bool
{
    if((int)$delivery['owner_user_id']!==$source['owner_user_id']||(int)$delivery['profile_event_id']!==$source['profile_event_id']||!hash_equals((string)$delivery['source_hash'],$source['source_hash']))return false;
    $q=$pdo->prepare('SELECT m.role,m.user_id,m.message,m.context_json,c.user_id owner_user_id,c.user_agent_id FROM chat_messages m INNER JOIN chat_conversations c ON c.id=m.conversation_id WHERE m.id=? AND m.conversation_id=?');$q->execute([(int)$delivery['chat_message_id'],(int)$delivery['chat_conversation_id']]);$message=$q->fetch(PDO::FETCH_ASSOC);$context=$message?vp3_profile_attention_json_pa4b($message['context_json']):null;$marker=$context['profile_attention']??null;
    if(!$message||$message['role']!=='assistant'||$message['user_id']!==null||$message['message']!=='Profile attention update.'||(int)$message['owner_user_id']!==$source['owner_user_id']||(int)($message['user_agent_id']??0)!==(int)$delivery['chat_agent_id']||!is_array($marker)||($marker['contract']??null)!==VP3_PROFILE_ATTENTION_PA4B)return false;
    foreach(['delivery_id'=>'id','attention_id'=>'attention_id','notification_id'=>'notification_id','profile_event_id'=>'profile_event_id','wake_generation'=>'wake_generation'] as $key=>$column)if(($marker[$key]??null)!==(int)$delivery[$column])return false;
    return ($marker['source_hash']??null)===$source['source_hash']&&($marker['public_conversation_id']??null)===$source['public_conversation_id'];
}
function vp3_profile_attention_projection_pa4b(PDO $pdo,array $source,array $context=[],?array $delivery=null): array
{
    $delivery??=vp3_profile_attention_delivery_row_pa4b($pdo,$source['owner_user_id'],(int)$source['attention']['id'],(int)$source['attention']['wake_generation']);
    return vp3_profile_attention_projection_data_pa4b($source,$context,$delivery,!$delivery||vp3_profile_attention_delivery_valid_pa4b($pdo,$delivery,$source));
}
function vp3_profile_attention_projection_data_pa4b(array $source,array $context,?array $delivery,bool $valid): array
{
    $row=$source['attention'];$note=$source['notification'];$owner=$source['owner_user_id'];$generation=(int)$row['wake_generation'];$state=vp3_profile_attention_disposition_pa4b($row);$due=vp3_profile_attention_due_pa4b($row);$read=(int)$row['read_generation']>=$generation;
    $location='none';if($delivery)$location=(int)$delivery['chat_conversation_id']===(int)($context['conversation_id']??0)&&(int)$delivery['chat_agent_id']===(int)($context['agent_id']??0)?'current':'elsewhere';
    $presented=$delivery&&$delivery['presented_at_ms']!==null;
    $reason=$source['reason'];if($source['status']!=='verified')$reason=$reason?:'source_unverified';elseif(!$valid)$reason='delivery_unverified';elseif(in_array($state,['handled','ignored'],true))$reason='terminal';elseif($state==='snoozed'&&!$due)$reason='snoozed';elseif($read&&!$due)$reason='read';elseif($source['presentation_owner']!=='profile_attention_pa4b')$reason='governed_presentation';elseif($presented&&!$due)$reason='presented';
    $currentGeneration=!$delivery||(int)$delivery['wake_generation']===$generation;if(!$currentGeneration)$reason='superseded';
    $verified=$source['status']==='verified';$active=!in_array($state,['handled','ignored'],true);$eligible=$verified&&$valid&&$currentGeneration&&$active&&(!$read||$due)&&(!$presented||$due)&&($state!=='snoozed'||$due)&&$source['presentation_owner']==='profile_attention_pa4b';
    return ['attention_id'=>(int)$row['id'],'notification_id'=>(int)$note['id'],'profile_event_id'=>$source['profile_event_id'],'public_conversation_id'=>$source['public_conversation_id'],'public_message_id'=>$source['public_message_id'],'source_status'=>$source['status'],'source_role'=>$source['projection']['actor_role'],'source_label'=>$source['projection']['speaker_label'],'title'=>mb_substr($source['projection']['title'],0,240),'display_text'=>$source['projection']['body'],'state_revision'=>(int)$row['state_revision'],'wake_generation'=>$generation,'disposition'=>$state,'read'=>$read,'presented'=>$delivery&&$delivery['presented_at_ms']!==null,'snoozed_until_ms'=>vp3_profile_attention_timestamp_pa4b($row['snoozed_until']??null),'wake_due'=>$due,'eligible'=>$eligible,'reason'=>$reason,'can_read'=>!$read,'can_snooze'=>$verified&&$active,'can_handle'=>$verified&&$active,'can_ignore'=>$active,'presentation_owner'=>$source['presentation_owner'],'voice_allowed'=>false,'delivery_id'=>$delivery?(int)$delivery['id']:0,'chat_conversation_id'=>$delivery?(int)$delivery['chat_conversation_id']:0,'chat_agent_id'=>$delivery?(int)$delivery['chat_agent_id']:0,'chat_message_id'=>$delivery?(int)$delivery['chat_message_id']:0,'delivery_location'=>$location];
}
/** Finish current-source authorization after all persisted private-marker reads. */
function vp3_profile_attention_view_pa4b(PDO $pdo,array $user,int $notificationId,array $context,?array $delivery=null): array
{
    $source=vp3_profile_attention_source_pa4b($pdo,$user,$notificationId,$context);
    $delivery??=vp3_profile_attention_delivery_row_pa4b($pdo,$source['owner_user_id'],(int)$source['attention']['id'],(int)$source['attention']['wake_generation']);$valid=!$delivery||vp3_profile_attention_delivery_valid_pa4b($pdo,$delivery,$source);
    $fresh=vp3_profile_attention_source_pa4b($pdo,$user,$notificationId,$context);if(!hash_equals($source['source_hash'],$fresh['source_hash']))vp3_profile_attention_error_pa4b();
    return ['source'=>$fresh,'delivery'=>$delivery,'item'=>vp3_profile_attention_projection_data_pa4b($fresh,$context,$delivery,$valid)];
}
function vp3_profile_attention_envelope_pa4b(array $extra=[]): array{return array_replace(['contract'=>VP3_PROFILE_ATTENTION_PA4B,'ok'=>true,'complete'=>true,'server_now_ms'=>vp3_profile_attention_now_pa4b(),'voice_allowed'=>false],$extra);}
/** Preserve old portal row shape while using the same current source as notifications. */
function vp3_profile_attention_legacy_row_pa4b(PDO $pdo,int $owner,array $row): array
{
    $safe=array_replace($row,['headline'=>'Profile attention','summary'=>'The saved Profile source is unavailable for current presentation.','assessment'=>'Saved Profile activity.','recommended_response'=>'Review the current Profile attention controls.','visitor_label'=>'Profile source','signed_in'=>false,'identity_disclosed'=>false,'contact_ref'=>'','username'=>'','profile_url'=>'','avatar_url'=>'','role_label'=>'','relationship_scope'=>'none','actions'=>['ignore'],'source_status'=>'unavailable','voice_allowed'=>false]);
    try{
        $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$owner]);$user=$q->fetch(PDO::FETCH_ASSOC);if(!$user)return $safe;
        $q=$pdo->prepare("SELECT * FROM notifications WHERE user_id=? AND source_type='profile_event' AND source_id=? ORDER BY id LIMIT 2");$q->execute([$owner,(int)$row['source_event_id']]);$notes=$q->fetchAll(PDO::FETCH_ASSOC);if(count($notes)!==1)return $safe;
        $note=notification_profile_projection_pa4b($pdo,$user,$notes[0]);if((int)$note['attention_id']!==(int)$row['id'])return $safe;
        $safe['headline']=$note['title'];$safe['summary']=$note['body'];$safe['visitor_label']=$note['source_label'];$safe['source_status']=$note['source_status'];$safe['notification_id']=(int)$note['id'];$safe['state_revision']=$note['state_revision'];$safe['wake_generation']=$note['wake_generation'];$safe['can_read']=$note['can_read'];
        if($note['source_status']==='verified'){$safe['signed_in']=(int)($row['visitor_user_id']??0)>0;$safe['identity_disclosed']=$note['source_role']==='visitor'&&$safe['signed_in']&&$note['source_label']!=='A visitor';$safe['actions']=(int)($row['source_conversation_id']??0)>0?['open_conversation','ignore']:['ignore'];}
    }catch(Throwable $error){}
    return $safe;
}
function vp3_profile_attention_state_pa4b(PDO $pdo,array $user,array $input=[]): array
{
    vp3_profile_attention_require_pa4b($pdo);$context=['conversation_id'=>vp3_profile_attention_id_pa4b($input['conversation_id']??0,true),'agent_id'=>vp3_profile_attention_id_pa4b($input['agent_id']??0,true)];$user=vp3_profile_attention_owner_pa4b($pdo,$user,$context);$owner=(int)$user['id'];$after=vp3_profile_attention_id_pa4b($input['after_id']??0,true);$page=vp3_profile_attention_id_pa4b(array_key_exists('page',$input)?$input['page']:0,true);if($page>100000)vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_INVALID',422);$offset=$page*25;$now=gmdate('Y-m-d H:i:s',intdiv(vp3_profile_attention_now_pa4b(),1000));
    $q=$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND source_type='profile_event'");$q->execute([$owner]);$unread=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT COUNT(*) FROM agent_attention_items WHERE owner_user_id=? AND status IN ('pending','seen','snoozed')");$q->execute([$owner]);$count=(int)$q->fetchColumn();
    // Source IDs are observations, not a wake or history cursor. Explicit pages
    // include every owned source; current unpresented work stays ahead of history.
    // Concurrent transitions can reorder offset pages, so each action still uses
    // the exact source revision/generation and clients explicitly refresh pages.
    $q=$pdo->prepare("SELECT n.id FROM notifications n LEFT JOIN agent_attention_items a ON a.source_event_id=n.source_id AND a.owner_user_id=n.user_id WHERE n.user_id=? AND n.source_type='profile_event' ORDER BY CASE WHEN a.status='snoozed' AND a.snoozed_until<=? THEN 0 WHEN a.status IN ('pending','seen') AND a.read_generation<a.wake_generation AND NOT EXISTS(SELECT 1 FROM profile_attention_deliveries_pa4b seen WHERE seen.owner_user_id=a.owner_user_id AND seen.attention_id=a.id AND seen.wake_generation=a.wake_generation AND seen.presented_at_ms IS NOT NULL) THEN 1 WHEN a.status IN ('pending','seen') AND a.read_generation<a.wake_generation THEN 2 WHEN a.status IN ('pending','seen','snoozed') THEN 3 WHEN a.status IN ('handled','ignored') THEN 4 ELSE 5 END,n.id ASC LIMIT 26 OFFSET ".$offset);$q->execute([$owner,$now]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$items=[];$errors=[];$latest=$after;
    foreach(array_slice($ids,0,25) as $id){$latest=max($latest,(int)$id);try{$view=vp3_profile_attention_view_pa4b($pdo,$user,(int)$id,$context);$items[]=$view['item'];}catch(VP3ProfileAttentionException $e){if($e->httpStatus>=500)throw $e;$errors[]=['notification_id'=>(int)$id,'reason'=>'source_unavailable'];}}
    vp3_profile_attention_owner_pa4b($pdo,$user,$context);return vp3_profile_attention_envelope_pa4b(['items'=>$items,'latest_id'=>$latest,'page'=>$page,'page_size'=>25,'has_more'=>count($ids)>25,'counts'=>$errors?null:['unread'=>$unread,'attention'=>$count],'errors'=>$errors,'complete'=>!$errors]);
}
function vp3_profile_attention_item_pa4b(PDO $pdo,array $user,array $input): array
{
    vp3_profile_attention_require_pa4b($pdo);$context=['conversation_id'=>vp3_profile_attention_id_pa4b($input['conversation_id']??null),'agent_id'=>vp3_profile_attention_id_pa4b($input['agent_id']??0,true)];$user=vp3_profile_attention_owner_pa4b($pdo,$user,$context);$id=vp3_profile_attention_id_pa4b($input['delivery_id']??null);
    $q=$pdo->prepare('SELECT * FROM profile_attention_deliveries_pa4b WHERE id=? AND owner_user_id=? AND chat_conversation_id=? AND chat_agent_id=?');$q->execute([$id,(int)$user['id'],$context['conversation_id'],$context['agent_id']]);$delivery=$q->fetch(PDO::FETCH_ASSOC);if(!$delivery)vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_NOT_FOUND',404);
    $view=vp3_profile_attention_view_pa4b($pdo,$user,(int)$delivery['notification_id'],$context,$delivery);return vp3_profile_attention_envelope_pa4b(['item'=>$view['item']]);
}
function vp3_profile_attention_wake_locked_pa4b(PDO $pdo,array $row): array
{
    if(!vp3_profile_attention_due_pa4b($row))return $row;$now=vp3_profile_attention_now_pa4b();
    $pdo->prepare("UPDATE agent_attention_items SET status='pending',snoozed_until=NULL,state_revision=state_revision+1,wake_generation=wake_generation+1,state_changed_at_ms=? WHERE id=? AND owner_user_id=? AND state_revision=? AND status='snoozed'")->execute([$now,(int)$row['id'],(int)$row['owner_user_id'],(int)$row['state_revision']]);
    $pdo->prepare("UPDATE notifications SET is_read=0,read_at=NULL WHERE user_id=? AND source_type='profile_event' AND source_id=?")->execute([(int)$row['owner_user_id'],(int)$row['source_event_id']]);
    return vp3_profile_attention_row_pa4b($pdo,(int)$row['owner_user_id'],(int)$row['id'],true)??throw new RuntimeException('Attention wake was not saved.');
}
function vp3_profile_attention_action_pa4b(PDO $pdo,array $user,array $input,string $operation): array
{
    vp3_profile_attention_require_pa4b($pdo);if(!in_array($operation,['present','ack','read','snooze','handled','ignored'],true))vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_INVALID',422);
    $context=['conversation_id'=>vp3_profile_attention_id_pa4b($input['conversation_id']??0,true),'agent_id'=>vp3_profile_attention_id_pa4b($input['agent_id']??0,true)];if(in_array($operation,['present','ack'],true)&&$context['conversation_id']<1)vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_INVALID',422);
    $user=vp3_profile_attention_owner_pa4b($pdo,$user,$context);$owner=(int)$user['id'];$nid=vp3_profile_attention_id_pa4b($input['notification_id']??null);$aid=vp3_profile_attention_id_pa4b($input['attention_id']??null);$revision=vp3_profile_attention_id_pa4b($input['state_revision']??null);$generation=vp3_profile_attention_id_pa4b($input['wake_generation']??null);$request=vp3_profile_attention_token_pa4b($input['request_id']??null);
    $ack=[];if($operation==='ack'){if(($input['visible']??null)!==true)vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_INVALID',422);$ack=['delivery_id'=>vp3_profile_attention_id_pa4b($input['delivery_id']??null),'chat_message_id'=>vp3_profile_attention_id_pa4b($input['chat_message_id']??null),'tab_id'=>vp3_profile_attention_token_pa4b($input['tab_id']??null),'client_instance_id'=>vp3_profile_attention_token_pa4b($input['client_instance_id']??null)];}
    if($operation==='snooze'&&vp3_profile_attention_id_pa4b($input['snooze_seconds']??86400)!==86400)vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_INVALID',422);
    $hash=hash('sha256',json_encode([$owner,$nid,$aid,$revision,$generation,$operation,$context,$ack],JSON_THROW_ON_ERROR));$initial=vp3_profile_attention_source_pa4b($pdo,$user,$nid,$context);
    if((int)$initial['attention']['id']!==$aid)vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_UNAVAILABLE',403);
    return vp3_profile_attention_atomic_pa4b($pdo,function()use($pdo,$user,$owner,$context,$nid,$aid,$revision,$generation,$request,$operation,$ack,$hash,$initial){
        // PA3 replies already hold the public conversation before attention.
        // Keep that order rather than locking attention then the conversation.
        $source=vp3_profile_attention_source_pa4b($pdo,$user,$nid,$context,true);$row=vp3_profile_attention_row_pa4b($pdo,$owner,$aid,true);
        $q=$pdo->prepare('SELECT * FROM notifications WHERE id=? AND user_id=?'.vp3_profile_attention_lock_pa4b($pdo));$q->execute([$nid,$owner]);$note=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||!$note||(int)$row['source_event_id']!==$source['profile_event_id']||!hash_equals($initial['source_hash'],$source['source_hash'])||($row['source_hash']!==''&&!hash_equals($row['source_hash'],$source['source_hash'])))vp3_profile_attention_error_pa4b();
        if($source['status']!=='verified'&&!in_array($operation,['read','ignored'],true))vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_UNAVAILABLE',403);
        $source['attention']=$row;$source['notification']=$note;
        $q=$pdo->prepare('SELECT * FROM profile_attention_operations_pa4b WHERE owner_user_id=? AND request_id=?'.vp3_profile_attention_lock_pa4b($pdo));$q->execute([$owner,$request]);$prior=$q->fetch(PDO::FETCH_ASSOC);
        if($prior){if(!hash_equals($prior['request_hash'],$hash))vp3_profile_attention_error_pa4b();return vp3_profile_attention_result_pa4b($pdo,$user,$nid,$context,true);}
        if((int)$row['state_revision']!==$revision||(int)$row['wake_generation']!==$generation)vp3_profile_attention_error_pa4b();
        $terminal=in_array($row['status'],['handled','ignored'],true);if($terminal&&$operation!=='read')vp3_profile_attention_error_pa4b();
        if(in_array($operation,['present','ack'],true)&&$source['presentation_owner']!=='profile_attention_pa4b')return vp3_profile_attention_envelope_pa4b(['duplicate'=>false,'presentation_owner'=>$source['presentation_owner'],'item'=>vp3_profile_attention_projection_pa4b($pdo,$source,$context)]);
        if($operation==='present'){$row=vp3_profile_attention_wake_locked_pa4b($pdo,$row);$source['attention']=$row;}
        $now=vp3_profile_attention_now_pa4b();$changed=false;
        if(in_array($operation,['present','ack'],true)){
            if($row['status']==='snoozed'||(int)$row['read_generation']>=(int)$row['wake_generation'])vp3_profile_attention_error_pa4b();
            $delivery=vp3_profile_attention_delivery_row_pa4b($pdo,$owner,$aid,(int)$row['wake_generation']);
            if($operation==='present'&&!$delivery){
                $pdo->prepare('INSERT INTO profile_attention_deliveries_pa4b(owner_user_id,attention_id,notification_id,profile_event_id,source_hash,wake_generation,chat_conversation_id,chat_agent_id,created_at_ms) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$owner,$aid,$nid,$source['profile_event_id'],$source['source_hash'],(int)$row['wake_generation'],$context['conversation_id'],$context['agent_id'],$now]);$deliveryId=(int)$pdo->lastInsertId();
                $marker=['contract'=>VP3_PROFILE_ATTENTION_PA4B,'delivery_id'=>$deliveryId,'attention_id'=>$aid,'notification_id'=>$nid,'profile_event_id'=>$source['profile_event_id'],'wake_generation'=>(int)$row['wake_generation'],'source_hash'=>$source['source_hash'],'public_conversation_id'=>$source['public_conversation_id']];
                $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at) VALUES(?,NULL,'assistant','Profile attention update.',?,?)")->execute([$context['conversation_id'],json_encode(['profile_attention'=>$marker],JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s',intdiv($now,1000))]);$messageId=(int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE profile_attention_deliveries_pa4b SET chat_message_id=? WHERE id=? AND owner_user_id=?')->execute([$messageId,$deliveryId,$owner]);$pdo->prepare('UPDATE chat_conversations SET updated_at=? WHERE id=? AND user_id=?')->execute([gmdate('Y-m-d H:i:s',intdiv($now,1000)),$context['conversation_id'],$owner]);
                if(function_exists('vp3_profile_projection_oblige_chat_pa4b')&&vp3_profile_projection_schema_ready_pa4b($pdo))vp3_profile_projection_oblige_chat_pa4b($pdo,$user,$source,['marker_kind'=>'profile_attention','delivery_id'=>$deliveryId,'chat_conversation_id'=>$context['conversation_id'],'chat_agent_id'=>$context['agent_id'],'chat_message_id'=>$messageId]);
                $delivery=vp3_profile_attention_delivery_row_pa4b($pdo,$owner,$aid,(int)$row['wake_generation']);$changed=true;
            }
            if(!$delivery||!vp3_profile_attention_delivery_valid_pa4b($pdo,$delivery,$source))vp3_profile_attention_error_pa4b();
            if($operation==='ack'){
                if((int)$delivery['id']!==$ack['delivery_id']||(int)$delivery['chat_message_id']!==$ack['chat_message_id']||(int)$delivery['chat_conversation_id']!==$context['conversation_id']||(int)$delivery['chat_agent_id']!==$context['agent_id'])vp3_profile_attention_error_pa4b();
                if($delivery['presented_at_ms']===null){$pdo->prepare('UPDATE profile_attention_deliveries_pa4b SET presented_at_ms=?,presented_tab_hash=?,presented_client_hash=? WHERE id=? AND presented_at_ms IS NULL')->execute([$now,hash('sha256',$ack['tab_id']),hash('sha256',$ack['client_instance_id']),(int)$delivery['id']]);$changed=true;}
            }
        }elseif($operation==='read'){
            if((int)$row['read_generation']<(int)$row['wake_generation']){$pdo->prepare('UPDATE agent_attention_items SET read_generation=wake_generation WHERE id=? AND owner_user_id=?')->execute([$aid,$owner]);$pdo->prepare('UPDATE notifications SET is_read=1,read_at=COALESCE(read_at,?) WHERE id=? AND user_id=?')->execute([gmdate('Y-m-d H:i:s',intdiv($now,1000)),$nid,$owner]);$changed=true;}
        }else{
            $state=$operation==='snooze'?'snoozed':$operation;$until=$operation==='snooze'?gmdate('Y-m-d H:i:s',intdiv($now,1000)+86400):null;
            $pdo->prepare('UPDATE agent_attention_items SET status=?,snoozed_until=? WHERE id=? AND owner_user_id=?')->execute([$state,$until,$aid,$owner]);$changed=true;
        }
        $pdo->prepare('UPDATE agent_attention_items SET source_hash=?,state_revision=state_revision+?,state_changed_at_ms=CASE WHEN ?=1 THEN ? ELSE state_changed_at_ms END WHERE id=? AND owner_user_id=?')->execute([$source['source_hash'],$changed?1:0,$changed?1:0,$now,$aid,$owner]);
        $final=vp3_profile_attention_source_pa4b($pdo,$user,$nid,$context,true);if(($final['status']!=='verified'&&!in_array($operation,['read','ignored'],true))||!hash_equals($source['source_hash'],$final['source_hash']))vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_UNAVAILABLE',403);vp3_profile_attention_owner_pa4b($pdo,$user,$context);
        $saved=$final['attention'];$pdo->prepare('INSERT INTO profile_attention_operations_pa4b(owner_user_id,request_id,request_hash,operation,attention_id,notification_id,expected_revision,expected_generation,result_revision,result_generation,result_status,result_json,created_at_ms) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$owner,$request,$hash,$operation,$aid,$nid,$revision,$generation,(int)$saved['state_revision'],(int)$saved['wake_generation'],vp3_profile_attention_disposition_pa4b($saved),json_encode(['changed'=>$changed],JSON_THROW_ON_ERROR),$now]);
        $result=vp3_profile_attention_result_pa4b($pdo,$user,$nid,$context,false);
        if($result['item']['source_status']!=='verified'&&!in_array($operation,['read','ignored'],true))vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_UNAVAILABLE',403);
        return $result;
    });
}
function vp3_profile_attention_result_pa4b(PDO $pdo,array $user,int $nid,array $context,bool $duplicate): array
{
    $view=vp3_profile_attention_view_pa4b($pdo,$user,$nid,$context);$source=$view['source'];$item=$view['item'];$result=vp3_profile_attention_envelope_pa4b(['duplicate'=>$duplicate,'presentation_owner'=>$source['presentation_owner'],'item'=>$item,'delivery_id'=>$item['delivery_id'],'chat_conversation_id'=>$item['chat_conversation_id'],'chat_agent_id'=>$item['chat_agent_id'],'chat_message_id'=>$item['chat_message_id'],'message'=>'Profile attention update.']);
    if($view['delivery']){$d=$view['delivery'];$result['context_json']=json_encode(['profile_attention'=>['contract'=>VP3_PROFILE_ATTENTION_PA4B,'delivery_id'=>(int)$d['id'],'attention_id'=>(int)$d['attention_id'],'notification_id'=>(int)$d['notification_id'],'profile_event_id'=>(int)$d['profile_event_id'],'wake_generation'=>(int)$d['wake_generation'],'source_hash'=>$d['source_hash'],'public_conversation_id'=>$source['public_conversation_id']]],JSON_THROW_ON_ERROR);}return $result;
}
function vp3_profile_attention_present_pa4b(PDO $pdo,array $user,array $input): array{return vp3_profile_attention_action_pa4b($pdo,$user,$input,'present');}
function vp3_profile_attention_ack_pa4b(PDO $pdo,array $user,array $input): array{return vp3_profile_attention_action_pa4b($pdo,$user,$input,'ack');}
function vp3_profile_attention_update_pa4b(PDO $pdo,array $user,array $input): array
{
    $op=$input['operation']??null;if(!in_array($op,['read','snooze','handled','ignored'],true))vp3_profile_attention_error_pa4b('PROFILE_ATTENTION_INVALID',422);return vp3_profile_attention_action_pa4b($pdo,$user,$input,$op);
}
function vp3_profile_attention_maintenance_pa4b(PDO $pdo,int $limit=20): array
{
    if(!vp3_profile_attention_schema_ready_pa4b($pdo))return ['ready'=>false,'reason'=>'upgrade_required','woken'=>0,'notifications_repaired'=>0];$limit=max(1,min(50,$limit));$now=gmdate('Y-m-d H:i:s',intdiv(vp3_profile_attention_now_pa4b(),1000));$counts=['ready'=>true,'reason'=>'','woken'=>0,'notifications_repaired'=>0];
    $q=$pdo->prepare("SELECT id,owner_user_id FROM agent_attention_items WHERE status='snoozed' AND snoozed_until<=? ORDER BY snoozed_until,id LIMIT ".$limit);$q->execute([$now]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $entry){$counts['woken']+=vp3_profile_attention_atomic_pa4b($pdo,function()use($pdo,$entry){$row=vp3_profile_attention_row_pa4b($pdo,(int)$entry['owner_user_id'],(int)$entry['id'],true);if(!$row||!vp3_profile_attention_due_pa4b($row))return 0;vp3_profile_attention_wake_locked_pa4b($pdo,$row);return 1;});}
    $q=$pdo->query("SELECT a.id,a.owner_user_id FROM agent_attention_items a INNER JOIN users u ON u.id=a.owner_user_id AND u.is_active=1 WHERE a.status IN ('pending','seen','snoozed') AND NOT EXISTS(SELECT 1 FROM notifications n WHERE n.user_id=a.owner_user_id AND n.source_type='profile_event' AND n.source_id=a.source_event_id) ORDER BY a.id LIMIT ".$limit);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $entry){$counts['notifications_repaired']+=vp3_profile_attention_atomic_pa4b($pdo,function()use($pdo,$entry){$row=vp3_profile_attention_row_pa4b($pdo,(int)$entry['owner_user_id'],(int)$entry['id'],true);if(!$row||in_array($row['status'],['handled','ignored'],true))return 0;$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([(int)$row['owner_user_id']]);$user=$q->fetch(PDO::FETCH_ASSOC);if(!$user)return 0;try{$source=vp3_profile_event_source_pa4b($pdo,$user,(int)$row['source_event_id']);}catch(VP3ProfileAttentionException $e){return 0;}if($source['status']!=='verified')return 0;
        $q=$pdo->prepare("SELECT id FROM notifications WHERE user_id=? AND source_type='profile_event' AND source_id=?".vp3_profile_attention_lock_pa4b($pdo));$q->execute([(int)$row['owner_user_id'],(int)$row['source_event_id']]);if($q->fetchColumn())return 0;
        $target=$source['public_conversation_id']>0?url('/profile-agent.php?tab=inbox&conversation='.$source['public_conversation_id']):url('/profile-agent.php?tab=visitors&session='.$source['profile_session_id']);$type=$source['event_type']==='profile_view'?'profile_view':'profile_'.$source['event_type'];
        $pdo->prepare('INSERT INTO notifications(user_id,type,title,body,target_url,source_type,source_id,is_read,created_at) VALUES(?,?,?,?,?,\'profile_event\',?,?,?)')->execute([(int)$row['owner_user_id'],$type,mb_substr($source['projection']['title'],0,240),$source['projection']['body'],$target,(int)$row['source_event_id'],(int)$row['read_generation']>=(int)$row['wake_generation']?1:0,$source['occurred_at']]);return 1;});}
    return $counts;
}
