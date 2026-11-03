<?php
declare(strict_types=1);

require_once __DIR__.'/profile-agent-public-service-v110.php';
require_once __DIR__.'/profile-agent-boundary-v390.php';
require_once __DIR__.'/profile-agent-accepted-turn-pa3.php';
require_once __DIR__.'/video-meetings-v1800.php';
require_once __DIR__.'/chat-settings-v237.php';

const VP3_PROFILE_CALL_PA3C='vp3.profile-call.pa3c';
const VP3_PROFILE_CALL_RING_MS_PA3C=30000;
const VP3_PROFILE_CALL_CONNECT_MS_PA3C=35000;
const VP3_PROFILE_CALL_LEASE_MS_PA3C=24000;
const VP3_PROFILE_CALL_TOKEN_SECONDS_PA3C=30;
const VP3_PROFILE_CALL_MAX_MS_PA3C=7200000;
const VP3_PROFILE_CALL_REFRESH_MS_PA3C=600000;

if(!function_exists('vp3_profile_call_now_pa3c')){
    function vp3_profile_call_now_pa3c(): int{return (int)floor(microtime(true)*1000);}
}

function vp3_profile_call_schema_ready_pa3c(PDO $pdo): bool
{
    try{
        $pdo->query('SELECT owner_user_id FROM profile_call_owner_locks_pa3c WHERE 1=0');
        $pdo->query('SELECT id,owner_user_id,conversation_id,conversation_epoch,turn_id,profile_agent_id,profile_session_id,visitor_user_id,visitor_auth_revision,owner_auth_revision,credential_hash,room_name,initiator,request_id,assisted,agent_name,status,revision,created_at_ms,accepted_at_ms,deadline_ms,owner_seen_ms,visitor_seen_ms,owner_token_until_ms,visitor_token_until_ms,owner_client_id,visitor_client_id,cleanup_due_ms,ended_at_ms FROM profile_calls_pa3c WHERE 1=0');
        return true;
    }catch(PDOException $e){return false;}
}

function vp3_profile_call_ensure_schema_pa3c(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Profile call schema must be upgraded outside a transaction.');
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $uid=$mysql?'INT UNSIGNED':'INTEGER';
    $id=$mysql?'BIGINT UNSIGNED':'INTEGER';
    $tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_call_owner_locks_pa3c (owner_user_id $uid PRIMARY KEY)$tail");
    $indexes=$mysql?', INDEX idx_profile_calls_owner (owner_user_id,status,created_at_ms), INDEX idx_profile_calls_conversation (owner_user_id,conversation_id,created_at_ms), INDEX idx_profile_calls_cleanup (cleanup_due_ms)':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_calls_pa3c (
        id CHAR(32) PRIMARY KEY, owner_user_id $uid NOT NULL, conversation_id $id NOT NULL,
        conversation_epoch INTEGER NOT NULL, turn_id $id NOT NULL, profile_agent_id $id NOT NULL,
        profile_session_id $id NOT NULL, visitor_user_id $uid NOT NULL, visitor_auth_revision CHAR(64) NOT NULL,
        owner_auth_revision CHAR(64) NOT NULL, credential_hash CHAR(64) NOT NULL,
        room_name VARCHAR(80) NOT NULL UNIQUE, initiator VARCHAR(7) NOT NULL, request_id CHAR(32) NOT NULL,
        assisted SMALLINT NOT NULL, agent_name VARCHAR(190) NOT NULL, status VARCHAR(12) NOT NULL,
        revision INTEGER NOT NULL DEFAULT 1, created_at_ms BIGINT NOT NULL, accepted_at_ms BIGINT NULL,
        deadline_ms BIGINT NOT NULL, owner_seen_ms BIGINT NULL, visitor_seen_ms BIGINT NULL,
        owner_token_until_ms BIGINT NULL, visitor_token_until_ms BIGINT NULL, owner_client_id CHAR(32) NULL,
        visitor_client_id CHAR(32) NULL, cleanup_due_ms BIGINT NULL, ended_at_ms BIGINT NULL,
        UNIQUE(owner_user_id,request_id)$indexes
    )$tail");
    if(!$mysql){
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_profile_calls_owner ON profile_calls_pa3c (owner_user_id,status,created_at_ms)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_profile_calls_conversation ON profile_calls_pa3c (owner_user_id,conversation_id,created_at_ms)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_profile_calls_cleanup ON profile_calls_pa3c (cleanup_due_ms)');
    }
}

function vp3_profile_call_error_pa3c(string $code,int $status=409): never
{
    $message=match($code){
        'CALL_INVALID'=>'Choose a valid Profile voice call action.',
        'CALL_NOT_FOUND'=>'This Profile voice call could not be found.',
        'CALL_NOT_READY'=>'Profile voice calls are unavailable on this installation.',
        'CALL_UNAVAILABLE'=>'The owner or this Profile conversation is unavailable for a voice call.',
        'CALL_BUSY'=>'The owner already has a pending or active voice call.',
        'CALL_CHANGED'=>'This voice call changed. Refresh its status and try again.',
        'CALL_DISCLOSURE_CHANGED'=>'The Agent assistance notice changed. Review the call notice and try again.',
        'CALL_TAB_ACTIVE'=>'This voice call is already connected in another tab.',
        'CALL_RATE_LIMITED'=>'Please wait before offering another voice call.',
        default=>'The Profile voice call could not be completed.',
    };
    throw new VP3ProfileAgentPublicException($code,$message,$status);
}

function vp3_profile_call_voice_ready_pa3c(): bool
{
    if(!video_meeting_livekit_ready_v1800()||!function_exists('curl_init'))return false;
    $url=parse_url(video_meeting_livekit_config_v1800()['url']);
    if(!$url||empty($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment']))return false;
    return ($url['scheme']??'')==='wss'
        ||(($url['scheme']??'')==='ws'&&in_array($url['host'],['127.0.0.1','localhost','[::1]'],true));
}

function vp3_profile_call_by_id_pa3c(PDO $pdo,string $id,bool $lock=false): ?array
{
    if(!preg_match('/^[a-f0-9]{32}$/D',$id))return null;
    $q=$pdo->prepare('SELECT * FROM profile_calls_pa3c WHERE id=?'.($lock?vp3_profile_handoff_lock_pa3($pdo):''));
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function vp3_profile_call_identity_pa3c(array $call,string $side): string
{
    if(!in_array($side,['owner','visitor'],true))throw new InvalidArgumentException('Invalid Profile call side.');
    return 'vp3-profile-'.$side.'-'.$call['id'];
}

/** A separate mutex serializes one owner's offers without changing lock order in PA3D. */
function vp3_profile_call_lock_owner_pa3c(PDO $pdo,int $owner): void
{
    if(!$pdo->inTransaction())throw new LogicException('Profile call updates need a transaction.');
    $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ?'INSERT INTO profile_call_owner_locks_pa3c(owner_user_id) VALUES(?) ON DUPLICATE KEY UPDATE owner_user_id=VALUES(owner_user_id)'
        :'INSERT INTO profile_call_owner_locks_pa3c(owner_user_id) VALUES(?) ON CONFLICT(owner_user_id) DO UPDATE SET owner_user_id=excluded.owner_user_id';
    $pdo->prepare($sql)->execute([$owner]);
}

function vp3_profile_call_settings_pa3c(PDO $pdo,int $owner): array
{
    $q=$pdo->prepare('SELECT p.user_id,p.is_public,p.profile_agent_enabled,p.profile_agent_id,a.display_name AS agent_name,a.is_active AS agent_active,u.is_active AS owner_active FROM user_profiles p LEFT JOIN user_agents a ON a.id=p.profile_agent_id AND a.owner_user_id=p.user_id INNER JOIN users u ON u.id=p.user_id WHERE p.user_id=?');
    $q->execute([$owner]);
    $profile=$q->fetch(PDO::FETCH_ASSOC);
    if(!$profile||empty($profile['is_public'])||empty($profile['profile_agent_enabled'])||empty($profile['agent_active'])||empty($profile['owner_active']))return [];
    $uid=(int)$profile['user_id'];
    $enabled=function_exists('chat_settings_agent_assisted_phone_chat_enabled_v237')&&chat_settings_agent_assisted_phone_chat_enabled_v237($pdo,$uid,$pdo->inTransaction());
    $assisted=$enabled&&function_exists('vp3_profile_call_assistance_ready_pa3c')&&vp3_profile_call_assistance_ready_pa3c($pdo)
        &&function_exists('vp3_profile_call_owner_assistance_allowed_pa3c')&&vp3_profile_call_owner_assistance_allowed_pa3c($pdo,$uid,(int)$profile['profile_agent_id'],$pdo->inTransaction());
    return ['agent_id'=>(int)$profile['profile_agent_id'],'agent_name'=>mb_substr(trim((string)$profile['agent_name']),0,190),'enabled'=>$enabled,'assisted'=>$assisted];
}

/** Current owner and native visitor authority, independent of the caller's browser. */
function vp3_profile_call_binding_pa3c(PDO $pdo,int $owner,int $cid,?array $call=null,bool $lock=false): ?array
{
    $conversation=vp3_profile_agent_owner_conversation_v390($pdo,$cid,$owner,$lock);
    if(!$conversation||$conversation['status']!=='owner_joined')return null;
    $thread=vp3_profile_handoff_thread_pa3($pdo,$owner,$cid,$lock);
    if(!$thread||$thread['mode']!=='owner'||$thread['requested_mode']!=='live'||$thread['live_status']!=='accepted')return null;
    if($call&&(int)$call['conversation_epoch']!==(int)$thread['epoch'])return null;

    $sql='SELECT * FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND conversation_id=?';
    $args=[$owner,$cid];
    if($call){$sql.=' AND id=?';$args[]=(int)$call['turn_id'];}
    $suffix=$lock?vp3_profile_handoff_lock_pa3($pdo):'';
    $q=$pdo->prepare($sql.' ORDER BY id DESC LIMIT 1'.$suffix);
    $q->execute($args);
    $turn=$q->fetch(PDO::FETCH_ASSOC);
    if(!$turn||$turn['transport']!=='native'||$turn['chat_mode']!=='live'||!in_array($turn['state'],['owner_claimed','replied'],true))return null;
    $visitorId=(int)$turn['visitor_user_id'];
    $agentId=(int)$turn['profile_agent_id'];
    $sessionId=(int)$turn['profile_session_id'];
    $authority=['contract'=>'vp3.profile-conversation-authority.v1','owner_user_id'=>$owner,'transport'=>'native','visitor_user_id'=>$visitorId,'credential_hash'=>(string)$turn['credential_hash']];
    if(!vp3_profile_agent_public_conversation_v390($pdo,$cid,$owner,$agentId,$sessionId,$lock,$authority))return null;

    // Public Profile admission locks these account rows before the thread.
    // Read them in READ COMMITTED here to avoid inverting that lock order.
    $q=$pdo->prepare('SELECT u.*,p.is_public,p.profile_agent_enabled,p.profile_agent_id,a.is_active AS agent_active FROM users u INNER JOIN user_profiles p ON p.user_id=u.id INNER JOIN user_agents a ON a.id=p.profile_agent_id AND a.owner_user_id=u.id WHERE u.id=?');
    $q->execute([$owner]);
    $user=$q->fetch(PDO::FETCH_ASSOC);
    if(!$user||empty($user['is_active'])||empty($user['is_public'])||empty($user['profile_agent_enabled'])
        ||empty($user['agent_active'])||(int)$user['profile_agent_id']!==$agentId)return null;
    if(!function_exists('has_permission')||!has_permission('chat.access',$user))return null;
    if(!function_exists('vp3_observation_personal_permission_v1'))require_once __DIR__.'/agent-observation-authority-v1.php';
    foreach(['profile_agent.access','profile_chat.access'] as $permission){
        if(!vp3_observation_personal_permission_v1($pdo,$user,$permission))return null;
    }
    $ownerRevision=hash('sha256',$owner.'|'.(string)($user['password_hash']??''));
    $q=$pdo->prepare('SELECT presence_mode,social_chat_enabled FROM team_user_presence WHERE user_id=? LIMIT 1'.$suffix);
    $q->execute([$owner]);
    $presence=$q->fetch(PDO::FETCH_ASSOC);
    if($presence&&($presence['presence_mode']!=='online'||!in_array($presence['social_chat_enabled'],[1,'1',true],true)))return null;
    if(vp3_profile_handoff_availability_pa3($pdo,$owner,$lock)!=='available')return null;

    $visitorRevision='';
    $visitorName='Visitor';
    if($visitorId>0){
        $q=$pdo->prepare('SELECT password_hash,is_active,display_name FROM users WHERE id=?');
        $q->execute([$visitorId]);
        $visitor=$q->fetch(PDO::FETCH_ASSOC);
        if(!$visitor||empty($visitor['is_active'])||!is_string($visitor['password_hash']))return null;
        $visitorRevision=hash('sha256',$visitorId.'|'.$visitor['password_hash']);
        $visitorName=mb_substr(trim((string)$visitor['display_name']),0,100);
        // This check deliberately never initializes social tables during a call.
        $q=$pdo->prepare('SELECT 1 FROM user_blocks WHERE (blocker_user_id=? AND blocked_user_id=?) OR (blocker_user_id=? AND blocked_user_id=?) LIMIT 1'.$suffix);
        $q->execute([$owner,$visitorId,$visitorId,$owner]);
        if($q->fetchColumn())return null;
    }
    $snapshot=json_decode((string)$turn['accepted_json'],true);
    if(!is_array($snapshot)||($snapshot['contract']??null)!=='vp3.profile-accepted-turn.pa3'
        ||!is_string($snapshot['visitor_auth_revision']??null)||!hash_equals($snapshot['visitor_auth_revision'],$visitorRevision))return null;
    $q=$pdo->prepare('SELECT id,owner_user_id,session_key,identity_disclosed,visitor_user_id FROM profile_visit_sessions WHERE id=? AND owner_user_id=?');
    $q->execute([$sessionId,$owner]);
    $session=$q->fetch(PDO::FETCH_ASSOC);
    if(!$session||!is_string($snapshot['session_fingerprint']??null))return null;
    $sessionFingerprint=vp3_profile_agent_accepted_fingerprint_pa3($session,['id','owner_user_id','session_key']);
    if(!hash_equals($snapshot['session_fingerprint'],$sessionFingerprint))return null;
    if($call&&((int)$call['profile_agent_id']!==$agentId||(int)$call['profile_session_id']!==$sessionId||(int)$call['visitor_user_id']!==$visitorId
        ||!hash_equals((string)$call['credential_hash'],(string)$turn['credential_hash'])||!hash_equals((string)$call['visitor_auth_revision'],$visitorRevision)
        ||!hash_equals((string)$call['owner_auth_revision'],$ownerRevision)))return null;
    return ['epoch'=>(int)$thread['epoch'],'turn_id'=>(int)$turn['id'],'agent_id'=>$agentId,'session_id'=>$sessionId,
        'visitor_id'=>$visitorId,'visitor_revision'=>$visitorRevision,'owner_revision'=>$ownerRevision,'credential_hash'=>(string)$turn['credential_hash'],
        'owner_name'=>mb_substr(trim((string)$user['display_name']),0,100)?:'Profile owner',
        'visitor_name'=>!empty($session['identity_disclosed'])&&(int)$session['visitor_user_id']===$visitorId?($visitorName?:'Visitor'):'Visitor'];
}

function vp3_profile_call_expired_pa3c(array $call,int $now): bool
{
    if(in_array($call['status'],['ringing','accepted'],true))return $now>=(int)$call['deadline_ms'];
    if($call['status']!=='live')return true;
    return $now>=(int)$call['accepted_at_ms']+VP3_PROFILE_CALL_MAX_MS_PA3C
        ||$now>=(int)$call['owner_seen_ms']+VP3_PROFILE_CALL_LEASE_MS_PA3C
        ||$now>=(int)$call['visitor_seen_ms']+VP3_PROFILE_CALL_LEASE_MS_PA3C;
}

function vp3_profile_call_live_pa3c(PDO $pdo,array $call,bool $lock=false): bool
{
    return in_array($call['status'],['accepted','live'],true)
        &&vp3_profile_call_voice_ready_pa3c()
        &&vp3_profile_call_binding_pa3c($pdo,(int)$call['owner_user_id'],(int)$call['conversation_id'],$call,$lock)!==null
        &&!vp3_profile_call_expired_pa3c($call,vp3_profile_call_now_pa3c());
}

/** Shared entry point for workers, before acquiring any assistance-specific locks. */
function vp3_profile_call_lock_current_pa3c(PDO $pdo,string $callId): ?array
{
    $initial=vp3_profile_call_by_id_pa3c($pdo,$callId);
    if(!$initial)return null;
    $owner=(int)$initial['owner_user_id'];
    vp3_profile_call_lock_owner_pa3c($pdo,$owner);
    vp3_profile_agent_owner_conversation_v390($pdo,(int)$initial['conversation_id'],$owner,true);
    $call=vp3_profile_call_by_id_pa3c($pdo,$callId,true);
    return $call&&vp3_profile_call_live_pa3c($pdo,$call,true)?$call:null;
}

/** A text reply can advance PA3D's epoch while leaving the same owner in control. */
function vp3_profile_call_text_transition_pa3c(PDO $pdo,int $owner,int $cid,int $previous,?int $next): void
{
    if(!$pdo->inTransaction()||!vp3_profile_call_schema_ready_pa3c($pdo))return;
    $where=" WHERE owner_user_id=? AND conversation_id=? AND conversation_epoch=? AND status IN ('ringing','accepted','live')";
    if($next!==null){
        $pdo->prepare('UPDATE profile_calls_pa3c SET conversation_epoch=?'.$where)->execute([$next,$owner,$cid,$previous]);
        return;
    }
    $now=vp3_profile_call_now_pa3c();
    $pdo->prepare("UPDATE profile_calls_pa3c SET status='ended',revision=revision+1,ended_at_ms=?,cleanup_due_ms=CASE WHEN owner_token_until_ms IS NOT NULL OR visitor_token_until_ms IS NOT NULL THEN ? ELSE NULL END".$where)
        ->execute([$now,$now,$owner,$cid,$previous]);
}

function vp3_profile_call_finish_pa3c(PDO $pdo,array $call,string $status,int $now): array
{
    if(!in_array($call['status'],['ringing','accepted','live'],true))return $call;
    $cleanup=max((int)$call['owner_token_until_ms'],(int)$call['visitor_token_until_ms'])>0?$now:null;
    $pdo->prepare('UPDATE profile_calls_pa3c SET status=?,revision=revision+1,ended_at_ms=?,cleanup_due_ms=? WHERE id=?')
        ->execute([$status,$now,$cleanup,$call['id']]);
    return vp3_profile_call_by_id_pa3c($pdo,(string)$call['id'],true)??throw new RuntimeException('Profile call was not saved.');
}

function vp3_profile_call_token_pa3c(array $call,string $side,string $name): array
{
    $cfg=video_meeting_livekit_config_v1800();
    $identity=vp3_profile_call_identity_pa3c($call,$side);
    $now=intdiv(vp3_profile_call_now_pa3c(),1000);
    $token=video_meeting_livekit_jwt_v1800([
        'iss'=>$cfg['api_key'],'sub'=>$identity,'name'=>$name,'nbf'=>$now-5,'exp'=>$now+VP3_PROFILE_CALL_TOKEN_SECONDS_PA3C,
        'video'=>['room'=>(string)$call['room_name'],'roomJoin'=>true,'canPublish'=>true,'canSubscribe'=>true,
            'canPublishData'=>false,'canPublishSources'=>['microphone'],'canUpdateOwnMetadata'=>false],
    ]);
    return ['token'=>$token,'url'=>$cfg['url'],'identity'=>$identity];
}

function vp3_profile_call_livekit_cloud_pa3c(): bool
{
    $host=parse_url(video_meeting_livekit_config_v1800()['url'],PHP_URL_HOST);
    return is_string($host)&&str_ends_with(strtolower($host),'.livekit.cloud');
}

function vp3_profile_call_room_request_pa3c(array $call,string $method,string $grant,array $payload): bool
{
    $meeting=['public_id'=>$call['id'],'room_name'=>$call['room_name']];
    $ch=curl_init(rtrim(video_meeting_livekit_http_base_v1800(),'/').'/twirp/livekit.RoomService/'.$method);
    if(!$ch)return false;
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT_MS=>1000,
        CURLOPT_TIMEOUT_MS=>2200,CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.video_meeting_livekit_service_token_v1800($meeting,$grant),'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode(['room'=>$call['room_name']]+$payload,JSON_THROW_ON_ERROR)]);
    $body=curl_exec($ch);
    $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return is_string($body)&&(($code>=200&&$code<300)||($method==='DeleteRoom'&&$code===404&&str_contains($body,'not_found')));
}

if(!function_exists('vp3_profile_call_delete_room_pa3c')){
    function vp3_profile_call_delete_room_pa3c(array $call): bool
    {
        if(!vp3_profile_call_voice_ready_pa3c())return false;
        $revoked=true;
        if(vp3_profile_call_livekit_cloud_pa3c()){
            foreach(['owner','visitor'] as $side){
                if(!$call[$side.'_token_until_ms'])continue;
                $removed=vp3_profile_call_room_request_pa3c($call,'RemoveParticipant','roomAdmin',[
                    'identity'=>vp3_profile_call_identity_pa3c($call,$side),'revoke_token_ts'=>time()+1,
                ]);
                $revoked=$revoked&&$removed;
            }
        }
        $deleted=vp3_profile_call_room_request_pa3c($call,'DeleteRoom','roomCreate',[]);
        $refreshWindowElapsed=vp3_profile_call_now_pa3c()>=(int)$call['ended_at_ms']+VP3_PROFILE_CALL_REFRESH_MS_PA3C;
        return $deleted&&($revoked||$refreshWindowElapsed);
    }
}

/** Reserve the retry before contacting LiveKit. Repeat after old grants have expired. */
function vp3_profile_call_retry_room_pa3c(PDO $pdo,array $call,int $now): bool
{
    if($call['cleanup_due_ms']===null||(int)$call['cleanup_due_ms']>$now)return false;
    $q=$pdo->prepare('UPDATE profile_calls_pa3c SET cleanup_due_ms=? WHERE id=? AND cleanup_due_ms=?');
    $claimedUntil=$now+10000;
    $q->execute([$claimedUntil,$call['id'],$call['cleanup_due_ms']]);
    if($q->rowCount()!==1)return false;
    try{$deleted=vp3_profile_call_delete_room_pa3c($call);}catch(Throwable $e){return false;}
    if($deleted){
        // Keep one cleanup after any in-flight Agent dispatch has settled.
        $horizon=max((int)$call['owner_token_until_ms'],(int)$call['visitor_token_until_ms'],(int)$call['ended_at_ms']+30000);
        // Self-hosted LiveKit can refresh tokens without an application call.
        // Keep the closed room under cleanup until those tokens age out too.
        if(!vp3_profile_call_livekit_cloud_pa3c()&&($call['owner_seen_ms']||$call['visitor_seen_ms']))
            $horizon=max($horizon,(int)$call['ended_at_ms']+VP3_PROFILE_CALL_REFRESH_MS_PA3C);
        $next=$now>=$horizon?null:min($horizon,$now+30000);
        $pdo->prepare('UPDATE profile_calls_pa3c SET cleanup_due_ms=? WHERE id=? AND cleanup_due_ms=?')->execute([$next,$call['id'],$claimedUntil]);
    }
    return $deleted;
}

function vp3_profile_call_cleanup_pa3c(PDO $pdo,int $limit=20): array
{
    if(!vp3_profile_call_schema_ready_pa3c($pdo))return ['expired'=>0,'rooms_closed'=>0];
    $limit=max(1,min($limit,50));
    $now=vp3_profile_call_now_pa3c();
    $q=$pdo->prepare("SELECT id,owner_user_id,conversation_id FROM profile_calls_pa3c WHERE (status IN ('ringing','accepted') AND deadline_ms<=?) OR (status='live' AND (owner_seen_ms<=? OR visitor_seen_ms<=? OR accepted_at_ms<=?)) ORDER BY created_at_ms LIMIT ".$limit);
    $q->execute([$now,$now-VP3_PROFILE_CALL_LEASE_MS_PA3C,$now-VP3_PROFILE_CALL_LEASE_MS_PA3C,$now-VP3_PROFILE_CALL_MAX_MS_PA3C]);
    $expired=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $initial){
        $expired+=vp3_profile_handoff_atomic_pa3($pdo,static function()use($pdo,$initial,$now): int{
            $owner=(int)$initial['owner_user_id'];
            vp3_profile_call_lock_owner_pa3c($pdo,$owner);
            vp3_profile_agent_owner_conversation_v390($pdo,(int)$initial['conversation_id'],$owner,true);
            $call=vp3_profile_call_by_id_pa3c($pdo,(string)$initial['id'],true);
            if(!$call||$call['ended_at_ms']!==null||!vp3_profile_call_expired_pa3c($call,$now))return 0;
            vp3_profile_call_finish_pa3c($pdo,$call,'expired',$now);
            return 1;
        });
    }
    $q=$pdo->prepare('SELECT * FROM profile_calls_pa3c WHERE cleanup_due_ms<=? ORDER BY cleanup_due_ms LIMIT '.$limit);
    $q->execute([$now]);
    $closed=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $call){if(vp3_profile_call_retry_room_pa3c($pdo,$call,$now))$closed++;}
    return ['expired'=>$expired,'rooms_closed'=>$closed];
}

function vp3_profile_call_projection_pa3c(?array $call,?array $binding,string $side): ?array
{
    if(!$call)return null;
    return ['id'=>(string)$call['id'],'conversation_id'=>(int)$call['conversation_id'],'revision'=>(int)$call['revision'],
        'status'=>(string)$call['status'],'initiator'=>(string)$call['initiator'],'assisted'=>(bool)$call['assisted'],
        'agent_name'=>(string)$call['agent_name'],'peer_name'=>(string)($binding[$side==='owner'?'visitor_name':'owner_name']??'')];
}

function vp3_profile_call_client_id_pa3c(mixed $value): string
{
    if(!is_string($value)||!preg_match('/^[a-f0-9]{32}$/D',$value))vp3_profile_call_error_pa3c('CALL_INVALID',422);
    return $value;
}

function vp3_profile_call_request_id_pa3c(mixed $value): string
{
    if(!is_string($value))vp3_profile_call_error_pa3c('CALL_INVALID',422);
    if(preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$value))$value=str_replace('-','',$value);
    return vp3_profile_call_client_id_pa3c($value);
}

function vp3_profile_call_select_pa3c(PDO $pdo,int $owner,int $cid=0,bool $activeOnly=false): ?array
{
    $sql='SELECT * FROM profile_calls_pa3c WHERE owner_user_id=?';
    $params=[$owner];
    if($cid>0){$sql.=' AND conversation_id=?';$params[]=$cid;}
    if($activeOnly)$sql.=" AND status IN ('ringing','accepted','live')";
    $q=$pdo->prepare($sql.($activeOnly?' ORDER BY created_at_ms DESC,id DESC':' ORDER BY CASE WHEN ended_at_ms IS NULL THEN 0 ELSE 1 END, created_at_ms DESC, ended_at_ms DESC, id DESC').' LIMIT 1');
    $q->execute($params);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function vp3_profile_call_base_projection_pa3c(PDO $pdo,int $owner,bool $voice): array
{
    $settings=vp3_profile_call_settings_pa3c($pdo,$owner);
    return ['contract'=>VP3_PROFILE_CALL_PA3C,'voice_ready'=>$voice,'available'=>false,
        'agent_assisted_phone_chat_enabled'=>(bool)($settings['enabled']??false),
        'assisted'=>$voice&&(bool)($settings['assisted']??false),'agent_name'=>(string)($settings['agent_name']??'')];
}

/** All returned data is a public projection. SQL rows never leave the application. */
function vp3_profile_call_action_pa3c(PDO $pdo,int $owner,int $cid,string $side,string $action,array $input,?array $publicCtx=null): array
{
    $callId=isset($input['call_id'])?vp3_profile_call_client_id_pa3c($input['call_id']):null;
    if($action!=='offer'&&$action!=='state'&&$callId===null)vp3_profile_call_error_pa3c('CALL_INVALID',422);
    if($action==='offer'&&$callId!==null)vp3_profile_call_error_pa3c('CALL_INVALID',422);
    if($side==='visitor'&&$cid===0&&$callId!==null)vp3_profile_call_error_pa3c('CALL_INVALID',422);
    $requestId=$action==='offer'?vp3_profile_call_request_id_pa3c($input['request_id']??null):null;
    $voice=vp3_profile_call_voice_ready_pa3c();
    $ready=vp3_profile_call_schema_ready_pa3c($pdo)&&vp3_profile_handoff_schema_ready_pa3($pdo);
    if(!$ready&&$action!=='state')vp3_profile_call_error_pa3c('CALL_NOT_READY',503);
    if(!$ready||($side==='visitor'&&$cid===0)||($action==='state'&&$cid===0&&$callId===null&&!vp3_profile_call_select_pa3c($pdo,$owner,0,true)))
        return vp3_profile_call_base_projection_pa3c($pdo,$owner,$ready&&$voice)+['call'=>null];
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&!$pdo->inTransaction())$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    [$payload,$finished,$dispatch]=vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$owner,$cid,$side,$action,$input,$publicCtx,$callId,$requestId,$voice){
        vp3_profile_call_lock_owner_pa3c($pdo,$owner);
        $now=vp3_profile_call_now_pa3c();
        $active=$action==='offer'||$cid===0?vp3_profile_call_select_pa3c($pdo,$owner,0,true):null;
        $finished=null;
        if($action==='offer'&&$active&&(int)$active['conversation_id']!==$cid&&vp3_profile_call_expired_pa3c($active,$now)){
            vp3_profile_agent_owner_conversation_v390($pdo,(int)$active['conversation_id'],$owner,true);
            $active=vp3_profile_call_by_id_pa3c($pdo,(string)$active['id'],true);
            if($active&&vp3_profile_call_expired_pa3c($active,$now)){
                $finished=vp3_profile_call_finish_pa3c($pdo,$active,'expired',$now);
                $active=null;
            }
        }
        if($requestId!==null){
            $q=$pdo->prepare('SELECT * FROM profile_calls_pa3c WHERE owner_user_id=? AND request_id=?');
            $q->execute([$owner,$requestId]);
            $duplicate=$q->fetch(PDO::FETCH_ASSOC)?:null;
            if($duplicate&&((int)$duplicate['conversation_id']!==$cid||$duplicate['initiator']!==$side||($input['disclosed']??null)!==(bool)$duplicate['assisted']))vp3_profile_call_error_pa3c('CALL_CHANGED');
        }else $duplicate=null;
        $initial=$callId!==null?vp3_profile_call_by_id_pa3c($pdo,$callId):($duplicate??($cid>0?vp3_profile_call_select_pa3c($pdo,$owner,$cid):$active));
        if($initial&&((int)$initial['owner_user_id']!==$owner||($cid>0&&(int)$initial['conversation_id']!==$cid)))vp3_profile_call_error_pa3c('CALL_NOT_FOUND',404);
        if($callId!==null&&!$initial)vp3_profile_call_error_pa3c('CALL_NOT_FOUND',404);
        if($cid===0&&$initial)$cid=(int)$initial['conversation_id'];
        if($cid===0)return [vp3_profile_call_base_projection_pa3c($pdo,$owner,$voice)+['call'=>null],null,null];
        $conversation=vp3_profile_agent_owner_conversation_v390($pdo,$cid,$owner,true);
        if(!$conversation&&(!$initial||$side==='visitor'))vp3_profile_call_error_pa3c('CALL_NOT_FOUND',404);
        if($side==='visitor'){
            if(!$publicCtx)vp3_profile_call_error_pa3c('CALL_NOT_FOUND',404);
            vp3_profile_agent_public_bound_conversation_v1($pdo,$publicCtx,$cid,true);
        }
        $call=$initial?vp3_profile_call_by_id_pa3c($pdo,(string)$initial['id'],true):null;
        $binding=$conversation?vp3_profile_call_binding_pa3c($pdo,$owner,$cid,$call&&$call['ended_at_ms']===null?$call:null,true):null;
        $result=vp3_profile_call_base_projection_pa3c($pdo,$owner,$voice);
        $reachable=$binding&&$voice&&vp3_profile_handoff_live_allowed_pa3d($pdo,$owner,$binding['agent_id'],0,0,true);
        $result['available']=(bool)$reachable;
        if($call&&$side==='visitor'){
            $authority=$publicCtx['conversation_authority'];
            if((int)$call['profile_session_id']!==(int)$publicCtx['session_id']||(int)$call['visitor_user_id']!==(int)$authority['visitor_user_id']
                ||!hash_equals((string)$call['credential_hash'],(string)$authority['credential_hash']))vp3_profile_call_error_pa3c('CALL_NOT_FOUND',404);
        }
        $now=vp3_profile_call_now_pa3c();
        if($call&&$call['ended_at_ms']===null){
            $expired=vp3_profile_call_expired_pa3c($call,$now);
            if($expired||!$binding||!$voice){
                $finished=$call=vp3_profile_call_finish_pa3c($pdo,$call,$expired?'expired':'ended',$now);
                // The current thread may remain eligible for a later, distinct call.
                $binding=$conversation?vp3_profile_call_binding_pa3c($pdo,$owner,$cid,null,true):null;
                $result['available']=$voice&&$binding&&vp3_profile_handoff_live_allowed_pa3d($pdo,$owner,$binding['agent_id'],0,0,true);
                if(!in_array($action,['state','offer','end'],true))return [$result+['call'=>vp3_profile_call_projection_pa3c($call,$binding,$side),'call_changed'=>true],$finished,null];
            }
        }
        if($action==='state'||$duplicate){
            return [$result+['call'=>vp3_profile_call_projection_pa3c($call,$binding,$side)],$finished,null];
        }
        if($action==='offer'){
            if(!$result['available']||!$binding){
                if($finished)return [$result+['call'=>vp3_profile_call_projection_pa3c($call,$binding,$side),'call_changed'=>true],$finished,null];
                vp3_profile_call_error_pa3c('CALL_UNAVAILABLE',409);
            }
            if($call&&$call['ended_at_ms']===null)vp3_profile_call_error_pa3c('CALL_BUSY');
            if($active&&$active['id']!==($call['id']??null))vp3_profile_call_error_pa3c('CALL_BUSY');
            if(($input['disclosed']??null)!==$result['assisted'])vp3_profile_call_error_pa3c('CALL_DISCLOSURE_CHANGED');
            $q=$pdo->prepare('SELECT COUNT(*) FROM profile_calls_pa3c WHERE owner_user_id=? AND created_at_ms>?');
            $q->execute([$owner,$now-60000]);
            if((int)$q->fetchColumn()>=5)vp3_profile_call_error_pa3c('CALL_RATE_LIMITED',429);
            $id=bin2hex(random_bytes(16));
            $q=$pdo->prepare("INSERT INTO profile_calls_pa3c(id,owner_user_id,conversation_id,conversation_epoch,turn_id,profile_agent_id,profile_session_id,visitor_user_id,visitor_auth_revision,owner_auth_revision,credential_hash,room_name,initiator,request_id,assisted,agent_name,status,created_at_ms,deadline_ms) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'ringing',?,?)");
            $q->execute([$id,$owner,$cid,$binding['epoch'],$binding['turn_id'],$binding['agent_id'],$binding['session_id'],$binding['visitor_id'],
                $binding['visitor_revision'],$binding['owner_revision'],$binding['credential_hash'],'vp3-profile-call-'.$id,$side,$requestId,$result['assisted']?1:0,$result['agent_name'],$now,$now+VP3_PROFILE_CALL_RING_MS_PA3C]);
            $call=vp3_profile_call_by_id_pa3c($pdo,$id,true);
            return [$result+['call'=>vp3_profile_call_projection_pa3c($call,$binding,$side)],$finished,null];
        }
        if(!$call)vp3_profile_call_error_pa3c('CALL_NOT_FOUND',404);
        if($action==='end'&&$call['ended_at_ms']!==null)return [$result+['call'=>vp3_profile_call_projection_pa3c($call,$binding,$side)],$finished,null];
        $currentRevision=$input['revision']??null;
        if($side!==$call['initiator']&&$currentRevision===1&&
            (($action==='accept'&&in_array($call['status'],['accepted','live'],true)&&($input['disclosed']??null)===(bool)$call['assisted'])
                ||($action==='decline'&&$call['status']==='declined'))){
            return [$result+['call'=>vp3_profile_call_projection_pa3c($call,$binding,$side)],$finished,null];
        }
        if($call['ended_at_ms']!==null||!$binding)vp3_profile_call_error_pa3c('CALL_CHANGED');

        // Column names are fixed by the validated participant role.
        [$clientField,$seenField,$expiryField]=match($side){
            'owner'=>['owner_client_id','owner_seen_ms','owner_token_until_ms'],
            'visitor'=>['visitor_client_id','visitor_seen_ms','visitor_token_until_ms'],
        };
        $client=$call[$clientField];
        $granted=$client!==null&&max((int)$call[$expiryField],(int)$call[$seenField]+VP3_PROFILE_CALL_LEASE_MS_PA3C)>$now;
        $extra=[];
        if($action==='accept'||$action==='decline'){
            $matches=is_int($currentRevision)&&(int)$call['revision']===$currentRevision;
            if(!$matches||$call['status']!=='ringing'||$call['initiator']===$side)vp3_profile_call_error_pa3c('CALL_CHANGED');
            if($action==='decline')$finished=$call=vp3_profile_call_finish_pa3c($pdo,$call,'declined',$now);
            else{
                if(!$result['available'])vp3_profile_call_error_pa3c('CALL_UNAVAILABLE',409);
                if(($input['disclosed']??null)!==(bool)$call['assisted']
                    ||((bool)$call['assisted']&&(!$result['assisted']||$call['agent_name']!==$result['agent_name'])))vp3_profile_call_error_pa3c('CALL_DISCLOSURE_CHANGED');
                $pdo->prepare("UPDATE profile_calls_pa3c SET status='accepted',revision=revision+1,accepted_at_ms=?,deadline_ms=? WHERE id=?")
                    ->execute([$now,$now+VP3_PROFILE_CALL_CONNECT_MS_PA3C,$call['id']]);
            }
        }elseif($action==='token'){
            if(!in_array($call['status'],['accepted','live'],true)||!is_int($currentRevision)||(int)$call['revision']!==$currentRevision)vp3_profile_call_error_pa3c('CALL_CHANGED');
            $clientId=vp3_profile_call_client_id_pa3c($input['client_id']??null);
            if($granted&&!hash_equals((string)$client,$clientId))vp3_profile_call_error_pa3c('CALL_TAB_ACTIVE');
            $pdo->prepare("UPDATE profile_calls_pa3c SET $clientField=?,$expiryField=? WHERE id=?")
                ->execute([$clientId,$now+VP3_PROFILE_CALL_TOKEN_SECONDS_PA3C*1000,$call['id']]);
            $extra=vp3_profile_call_token_pa3c($call,$side,$binding[$side.'_name']);
        }elseif($action==='heartbeat'){
            $clientId=vp3_profile_call_client_id_pa3c($input['client_id']??null);
            if(!in_array($call['status'],['accepted','live'],true)||!$granted||!hash_equals((string)$client,$clientId))vp3_profile_call_error_pa3c('CALL_CHANGED');
            $pdo->prepare("UPDATE profile_calls_pa3c SET $seenField=? WHERE id=?")->execute([$now,$call['id']]);
            if($call['status']==='accepted'){
                $pdo->prepare("UPDATE profile_calls_pa3c SET status='live',revision=revision+1 WHERE id=? AND owner_seen_ms>=? AND visitor_seen_ms>=?")
                    ->execute([$call['id'],$now-VP3_PROFILE_CALL_LEASE_MS_PA3C,$now-VP3_PROFILE_CALL_LEASE_MS_PA3C]);
            }
        }elseif($action==='end'){
            if($granted&&(!is_string($input['client_id']??null)||!hash_equals((string)$client,$input['client_id'])))vp3_profile_call_error_pa3c('CALL_TAB_ACTIVE');
            $finished=$call=vp3_profile_call_finish_pa3c($pdo,$call,'ended',$now);
        }
        $call=vp3_profile_call_by_id_pa3c($pdo,(string)$call['id'],true);
        return [$result+['call'=>vp3_profile_call_projection_pa3c($call,$binding,$side)]+$extra,$finished,$action==='heartbeat'&&$call['status']==='live'&&!empty($call['assisted'])?$call:null];
    });
    if($finished)vp3_profile_call_retry_room_pa3c($pdo,$finished,vp3_profile_call_now_pa3c());
    if($dispatch&&function_exists('vp3_profile_call_try_dispatch_pa3c')){
        try{vp3_profile_call_try_dispatch_pa3c($pdo,$dispatch);}catch(Throwable $e){error_log('Profile call Agent dispatch failed ('.get_class($e).').');}
    }
    return $payload;
}
