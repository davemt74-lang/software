<?php
declare(strict_types=1);

/** Profile doorbell state; execution and receipts remain in the canonical job engine. */
const VP3_PROFILE_HANDOFF_PA3='vp3.profile-handoff.v1';
const VP3_PROFILE_HANDOFF_PICKUP_MS_PA3=15000;
const VP3_PROFILE_HANDOFF_DELIVERY_MS_PA3=5000;
const VP3_PROFILE_HANDOFF_PRESENCE_MS_PA3=45000;

final class VP3ProfileHandoffException extends RuntimeException
{
    public function __construct(public readonly string $publicCode,string $message,public readonly int $httpStatus=409)
    {parent::__construct($message,$httpStatus);}
}
if(!function_exists('vp3_profile_handoff_now_pa3')){
    function vp3_profile_handoff_now_pa3(): int{return (int)floor(microtime(true)*1000);}
}
function vp3_profile_handoff_error_pa3(string $code='HANDOFF_CHANGED',int $status=409): never
{
    $messages=[
        'HANDOFF_CHANGED'=>'This visitor handoff changed. Refresh the card and try again.',
        'HANDOFF_UNAVAILABLE'=>'Visitor handoff is not available.',
        'HANDOFF_INVALID'=>'Choose a valid visitor handoff action.',
        'HANDOFF_NOT_FOUND'=>'Visitor conversation was not found.',
        'HANDOFF_REPLAY_CONFLICT'=>'That request was already used for a different visitor action.',
        'HANDOFF_NOT_READY'=>'Visitor handoff needs a database upgrade.',
        'HANDOFF_JOB_CHANGED'=>'Visitor reply execution changed. Refresh the conversation.',
    ];
    throw new VP3ProfileHandoffException($code,$messages[$code]??$messages['HANDOFF_UNAVAILABLE'],$status);
}
function vp3_profile_handoff_lock_pa3(PDO $pdo): string{return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';}
function vp3_profile_handoff_date_pa3(?int $now=null): string{return gmdate('Y-m-d H:i:s',intdiv($now??vp3_profile_handoff_now_pa3(),1000));}
function vp3_profile_handoff_atomic_pa3(PDO $pdo,callable $work): mixed
{
    $owned=!$pdo->inTransaction();$savepoint='profile_handoff_'.bin2hex(random_bytes(5));
    if($owned)$pdo->beginTransaction();else $pdo->exec('SAVEPOINT '.$savepoint);
    try{$result=$work();if($owned)$pdo->commit();else $pdo->exec('RELEASE SAVEPOINT '.$savepoint);return $result;}
    catch(Throwable $e){if($owned&&$pdo->inTransaction())$pdo->rollBack();elseif(!$owned&&$pdo->inTransaction()){$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}throw $e;}
}
function vp3_profile_handoff_id_pa3(mixed $value,bool $zero=false): int
{
    if(is_int($value))$id=$value;
    elseif(is_string($value)&&preg_match('/^(0|[1-9][0-9]{0,17})$/D',$value))$id=(int)$value;
    else vp3_profile_handoff_error_pa3('HANDOFF_INVALID',422);
    if($id<($zero?0:1))vp3_profile_handoff_error_pa3('HANDOFF_INVALID',422);
    return $id;
}
function vp3_profile_handoff_token_pa3(mixed $value): string
{
    if(!is_string($value)||!preg_match('/^[a-f0-9]{32}$/D',$value))vp3_profile_handoff_error_pa3('HANDOFF_INVALID',422);
    return $value;
}
function vp3_profile_handoff_schema_ready_pa3(PDO $pdo): bool
{
    $columns=[
        'profile_agent_handoff_owners_pa3'=>'owner_user_id,availability,updated_at_ms',
        'profile_agent_handoff_presence_pa3'=>'owner_user_id,tab_id,chat_conversation_id,chat_agent_id,visible,last_seen_ms,presence_sequence',
        'profile_agent_handoff_conversations_pa3'=>'conversation_id,owner_user_id,mode,epoch,delivery_until_ms,acknowledged_at_ms,pickup_deadline_ms,updated_at_ms',
        'profile_agent_handoff_turns_pa3'=>'id,owner_user_id,profile_agent_id,profile_session_id,conversation_id,visitor_message_id,visitor_user_id,transport,credential_hash,client_request_id,request_hash,accepted_json,state,version,conversation_epoch,run_id,action_id,execution_lease_token,answer_message_id,last_owner_message_id,notification_id,delivery_until_ms,acknowledged_at_ms,pickup_deadline_ms,last_error_class,created_at,updated_at',
        'profile_agent_handoff_deliveries_pa3'=>'delivery_id,turn_id,owner_user_id,tab_id,chat_conversation_id,chat_agent_id,chat_message_id,turn_version,conversation_epoch,created_at_ms,acknowledged_at_ms',
        'profile_agent_handoff_operations_pa3'=>'owner_user_id,request_id,turn_id,operation,request_hash,message_id,created_at_ms',
    ];
    try{foreach($columns as $table=>$list)$pdo->query('SELECT '.$list.' FROM '.$table.' WHERE 1=0');return true;}catch(Throwable $e){return false;}
}
function vp3_profile_handoff_ensure_schema_pa3(PDO $pdo): void
{
    $ready=vp3_profile_handoff_schema_ready_pa3($pdo);
    if($pdo->inTransaction()){if($ready)return;throw new RuntimeException('Profile handoff schema must be initialized before admission.');}
    if($ready){vp3_profile_handoff_indexes_pa3($pdo);return;}
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$auto=$mysql?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $id=$mysql?'BIGINT UNSIGNED':'INTEGER';$uid=$mysql?'INT UNSIGNED':'INTEGER';$long=$mysql?'MEDIUMTEXT':'TEXT';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $schemas=[
        'profile_agent_handoff_owners_pa3'=>"owner_user_id $uid PRIMARY KEY,availability VARCHAR(12) NOT NULL DEFAULT 'available',updated_at_ms BIGINT NOT NULL",
        'profile_agent_handoff_presence_pa3'=>"owner_user_id $uid NOT NULL,tab_id CHAR(32) NOT NULL,chat_conversation_id $id NOT NULL,chat_agent_id $id NOT NULL DEFAULT 0,visible SMALLINT NOT NULL DEFAULT 0,last_seen_ms BIGINT NOT NULL,presence_sequence BIGINT NOT NULL DEFAULT 0,PRIMARY KEY(owner_user_id,tab_id)",
        'profile_agent_handoff_conversations_pa3'=>"conversation_id $id PRIMARY KEY,owner_user_id $uid NOT NULL,mode VARCHAR(16) NOT NULL,epoch INTEGER NOT NULL DEFAULT 1,delivery_until_ms BIGINT NULL,acknowledged_at_ms BIGINT NULL,pickup_deadline_ms BIGINT NULL,updated_at_ms BIGINT NOT NULL",
        'profile_agent_handoff_turns_pa3'=>"id $auto,owner_user_id $uid NOT NULL,profile_agent_id $id NOT NULL,profile_session_id $id NOT NULL,conversation_id $id NOT NULL,visitor_message_id $id NOT NULL UNIQUE,visitor_user_id $uid NOT NULL DEFAULT 0,transport VARCHAR(20) NOT NULL,credential_hash CHAR(64) NOT NULL,client_request_id CHAR(32) NOT NULL,request_hash CHAR(64) NOT NULL,accepted_json $long NOT NULL,state VARCHAR(24) NOT NULL,version INTEGER NOT NULL DEFAULT 1,conversation_epoch INTEGER NOT NULL,run_id $id NULL,action_id $id NULL,execution_lease_token CHAR(64) NOT NULL DEFAULT '',answer_message_id $id NULL,last_owner_message_id $id NULL,notification_id $id NULL,delivery_until_ms BIGINT NULL,acknowledged_at_ms BIGINT NULL,pickup_deadline_ms BIGINT NULL,last_error_class VARCHAR(80) NOT NULL DEFAULT '',created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,UNIQUE(owner_user_id,profile_session_id,client_request_id)",
        'profile_agent_handoff_deliveries_pa3'=>"delivery_id CHAR(32) PRIMARY KEY,turn_id $id NOT NULL,owner_user_id $uid NOT NULL,tab_id CHAR(32) NOT NULL,chat_conversation_id $id NOT NULL,chat_agent_id $id NOT NULL DEFAULT 0,chat_message_id $id NOT NULL,turn_version INTEGER NOT NULL,conversation_epoch INTEGER NOT NULL,created_at_ms BIGINT NOT NULL,acknowledged_at_ms BIGINT NULL,UNIQUE(turn_id,tab_id,chat_conversation_id,chat_agent_id,turn_version)",
        'profile_agent_handoff_operations_pa3'=>"owner_user_id $uid NOT NULL,request_id CHAR(32) NOT NULL,turn_id $id NOT NULL,operation VARCHAR(12) NOT NULL,request_hash CHAR(64) NOT NULL,message_id $id NULL,created_at_ms BIGINT NOT NULL,PRIMARY KEY(owner_user_id,request_id)",
    ];
    foreach($schemas as $table=>$sql)$pdo->exec('CREATE TABLE IF NOT EXISTS '.$table.' ('.$sql.')'.$tail);
    vp3_profile_handoff_indexes_pa3($pdo);
    if(!vp3_profile_handoff_schema_ready_pa3($pdo))vp3_profile_handoff_error_pa3('HANDOFF_NOT_READY',503);
}
function vp3_profile_handoff_indexes_pa3(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Profile handoff indexes must be initialized before admission.');
    $indexes=[
        ['profile_agent_handoff_turns_pa3','idx_profile_handoff_turn_conversation','owner_user_id,conversation_id,id'],
        ['profile_agent_handoff_turns_pa3','idx_profile_handoff_turn_state','owner_user_id,state,id'],
        ['profile_agent_handoff_turns_pa3','idx_profile_handoff_turn_job','owner_user_id,run_id,action_id'],
        ['profile_agent_handoff_conversations_pa3','idx_profile_handoff_thread_due','owner_user_id,mode,updated_at_ms,conversation_id'],
        ['profile_agent_handoff_presence_pa3','idx_profile_handoff_presence_live','owner_user_id,visible,last_seen_ms'],
    ];
    $exists=static function(string $table,string $name)use($pdo):bool{
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){$q=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?");$q->execute([$table,$name]);}
        else{$q=$pdo->prepare('SHOW INDEX FROM `'.$table.'` WHERE Key_name=?');$q->execute([$name]);}
        return(bool)$q->fetch();
    };
    foreach($indexes as [$table,$name,$columns]){
        if($exists($table,$name))continue;
        try{$pdo->exec('CREATE INDEX '.$name.' ON '.$table.' ('.$columns.')');}
        catch(PDOException $error){if(!$exists($table,$name))throw $error;}
    }
}
function vp3_profile_handoff_require_pa3(PDO $pdo): void
{
    if(!vp3_profile_handoff_schema_ready_pa3($pdo))vp3_profile_handoff_error_pa3('HANDOFF_NOT_READY',503);
}
function vp3_profile_handoff_row_pa3(PDO $pdo,int $turnId,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM profile_agent_handoff_turns_pa3 WHERE id=?'.($lock?vp3_profile_handoff_lock_pa3($pdo):''));$q->execute([$turnId]);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;
}
function vp3_profile_handoff_owner_pa3(PDO $pdo,array $user,int $cid=0,int $aid=0,bool $legacy=false): array
{
    $uid=(int)($user['id']??0);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$live=$q->fetch(PDO::FETCH_ASSOC);
    if(!$live||empty($live['is_active'])||(!$legacy&&(!function_exists('has_permission')||!has_permission('chat.access',$live))))vp3_profile_handoff_error_pa3('HANDOFF_UNAVAILABLE',403);
    if(!function_exists('vp3_observation_personal_permission_v1'))require_once __DIR__.'/agent-observation-authority-v1.php';
    foreach($legacy?['profile_agent.access']:['profile_agent.access','profile_chat.access'] as $permission)if(!vp3_observation_personal_permission_v1($pdo,$live,$permission))vp3_profile_handoff_error_pa3('HANDOFF_UNAVAILABLE',403);
    if($aid>0){$q=$pdo->prepare('SELECT id FROM user_agents WHERE id=? AND owner_user_id=? AND is_active=1');$q->execute([$aid,$uid]);if(!$q->fetchColumn())vp3_profile_handoff_error_pa3('HANDOFF_UNAVAILABLE',403);}
    if($cid>0){$q=$pdo->prepare('SELECT user_id,user_agent_id FROM chat_conversations WHERE id=?');$q->execute([$cid]);$chat=$q->fetch(PDO::FETCH_ASSOC);if(!$chat||(int)$chat['user_id']!==$uid||(int)($chat['user_agent_id']??0)!==$aid)vp3_profile_handoff_error_pa3('HANDOFF_UNAVAILABLE',403);}
    return $live;
}
function vp3_profile_handoff_availability_pa3(PDO $pdo,int $owner): string
{
    $q=$pdo->prepare('SELECT availability FROM profile_agent_handoff_owners_pa3 WHERE owner_user_id=?');$q->execute([$owner]);$value=$q->fetchColumn();return $value===false?'available':(in_array($value,['available','away','dnd'],true)?$value:'away');
}
function vp3_profile_handoff_reachable_pa3(PDO $pdo,int $owner): bool
{
    if(vp3_profile_handoff_availability_pa3($pdo,$owner)!=='available')return false;
    // The existing global Offline preference wins over the doorbell toggle.
    // Do not initialize settings storage or turn a failed settings read online.
    try{
        if(function_exists('table_exists')&&table_exists('team_user_presence')&&function_exists('column_exists')&&column_exists('team_user_presence','presence_mode')){
            $q=$pdo->prepare('SELECT presence_mode FROM team_user_presence WHERE user_id=? LIMIT 1');$q->execute([$owner]);$mode=$q->fetchColumn();if($mode!==false&&$mode!=='online')return false;
        }
    }catch(Throwable $e){return false;}
    $now=vp3_profile_handoff_now_pa3();$q=$pdo->prepare('SELECT p.chat_conversation_id,p.chat_agent_id FROM profile_agent_handoff_presence_pa3 p INNER JOIN chat_conversations c ON c.id=p.chat_conversation_id AND c.user_id=p.owner_user_id WHERE p.owner_user_id=? AND p.visible=1 AND p.last_seen_ms>=? AND p.last_seen_ms<=? AND COALESCE(c.user_agent_id,0)=p.chat_agent_id ORDER BY p.last_seen_ms DESC LIMIT 20');$q->execute([$owner,$now-VP3_PROFILE_HANDOFF_PRESENCE_MS_PA3,$now]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$aid=(int)$row['chat_agent_id'];if($aid===0)return true;$a=$pdo->prepare('SELECT id FROM user_agents WHERE id=? AND owner_user_id=? AND is_active=1');$a->execute([$aid,$owner]);if($a->fetchColumn())return true;}
    return false;
}
function vp3_profile_handoff_presence_pa3(PDO $pdo,array $user,array $input): array
{
    vp3_profile_handoff_require_pa3($pdo);$tab=vp3_profile_handoff_token_pa3($input['tab_id']??null);$cid=vp3_profile_handoff_id_pa3($input['chat_conversation_id']??0,true);$aid=vp3_profile_handoff_id_pa3($input['chat_agent_id']??0,true);
    if(!is_bool($input['visible']??null))vp3_profile_handoff_error_pa3('HANDOFF_INVALID',422);
    $sequence=vp3_profile_handoff_id_pa3($input['presence_sequence']??null);if($sequence>9007199254740991)vp3_profile_handoff_error_pa3('HANDOFF_INVALID',422);
    $visible=$input['visible']&&$cid>0;$live=vp3_profile_handoff_owner_pa3($pdo,$user,$cid,$aid);$uid=(int)$live['id'];$now=vp3_profile_handoff_now_pa3();
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$live,$uid,$tab,$cid,$aid,$visible,$now,$input,$sequence){
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=?'.vp3_profile_handoff_lock_pa3($pdo));$lock->execute([$uid]);
        $q=$pdo->prepare('SELECT presence_sequence FROM profile_agent_handoff_presence_pa3 WHERE owner_user_id=? AND tab_id=?');$q->execute([$uid,$tab]);$prior=$q->fetchColumn();
        if($prior!==false&&$sequence<=(int)$prior)return ['contract'=>VP3_PROFILE_HANDOFF_PA3,'accepted'=>false,'availability'=>vp3_profile_handoff_availability_pa3($pdo,$uid),'reachable'=>vp3_profile_handoff_reachable_pa3($pdo,$uid),'server_now_ms'=>$now];
        if(array_key_exists('availability',$input)){
            if(!in_array($input['availability'],['available','away','dnd'],true))vp3_profile_handoff_error_pa3('HANDOFF_INVALID',422);
            $q=$pdo->prepare('SELECT owner_user_id FROM profile_agent_handoff_owners_pa3 WHERE owner_user_id=?');$q->execute([$uid]);
            if($q->fetchColumn())$pdo->prepare('UPDATE profile_agent_handoff_owners_pa3 SET availability=?,updated_at_ms=? WHERE owner_user_id=?')->execute([$input['availability'],$now,$uid]);
            else $pdo->prepare('INSERT INTO profile_agent_handoff_owners_pa3(owner_user_id,availability,updated_at_ms) VALUES(?,?,?)')->execute([$uid,$input['availability'],$now]);
        }
        if($prior!==false)$pdo->prepare('UPDATE profile_agent_handoff_presence_pa3 SET chat_conversation_id=?,chat_agent_id=?,visible=?,last_seen_ms=?,presence_sequence=? WHERE owner_user_id=? AND tab_id=?')->execute([$cid,$aid,$visible?1:0,$now,$sequence,$uid,$tab]);
        else $pdo->prepare('INSERT INTO profile_agent_handoff_presence_pa3(owner_user_id,tab_id,chat_conversation_id,chat_agent_id,visible,last_seen_ms,presence_sequence) VALUES(?,?,?,?,?,?,?)')->execute([$uid,$tab,$cid,$aid,$visible?1:0,$now,$sequence]);
        vp3_profile_handoff_owner_pa3($pdo,$live,$cid,$aid);
        return ['contract'=>VP3_PROFILE_HANDOFF_PA3,'accepted'=>true,'availability'=>vp3_profile_handoff_availability_pa3($pdo,$uid),'reachable'=>vp3_profile_handoff_reachable_pa3($pdo,$uid),'server_now_ms'=>$now];
    });
}

function vp3_profile_handoff_request_hash_pa3(array $ctx,string $query): string
{
    return hash('sha256',json_encode([(int)$ctx['owner_user_id'],(int)$ctx['agent_id'],(int)$ctx['session_id'],(string)($ctx['conversation_authority']['transport']??''),(string)($ctx['conversation_authority']['credential_hash']??''),$query],JSON_THROW_ON_ERROR));
}
function vp3_profile_handoff_retry_pa3(PDO $pdo,array $ctx,string $requestId,string $query): ?array
{
    vp3_profile_handoff_require_pa3($pdo);vp3_profile_handoff_token_pa3($requestId);$q=$pdo->prepare('SELECT * FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND profile_session_id=? AND client_request_id=?');$q->execute([(int)$ctx['owner_user_id'],(int)$ctx['session_id'],$requestId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    if(!hash_equals((string)$row['request_hash'],vp3_profile_handoff_request_hash_pa3($ctx,trim($query))))vp3_profile_handoff_error_pa3('HANDOFF_REPLAY_CONFLICT',409);
    return $row;
}
function vp3_profile_handoff_conversation_pa3(PDO $pdo,int $owner,int $cid,bool $lock=false): array
{
    $q=$pdo->prepare('SELECT * FROM profile_agent_conversations WHERE id=? AND owner_user_id=?'.($lock?vp3_profile_handoff_lock_pa3($pdo):''));$q->execute([$cid,$owner]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)vp3_profile_handoff_error_pa3('HANDOFF_NOT_FOUND',404);return $row;
}
function vp3_profile_handoff_thread_pa3(PDO $pdo,int $owner,int $cid,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM profile_agent_handoff_conversations_pa3 WHERE conversation_id=? AND owner_user_id=?'.($lock?vp3_profile_handoff_lock_pa3($pdo):''));$q->execute([$cid,$owner]);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;
}
function vp3_profile_handoff_admit_pa3(PDO $pdo,array $ctx,array $conversation,int $visitorMessageId,string $clientRequestId,array $acceptedSnapshot=[]): array
{
    vp3_profile_handoff_require_pa3($pdo);if(!$pdo->inTransaction())throw new RuntimeException('Visitor turn admission requires an active transaction.');
    vp3_profile_handoff_token_pa3($clientRequestId);$uid=(int)$ctx['owner_user_id'];$cid=(int)$conversation['id'];$conversation=vp3_profile_handoff_conversation_pa3($pdo,$uid,$cid,true);
    $q=$pdo->prepare("SELECT * FROM profile_agent_messages WHERE id=? AND conversation_id=? AND sender_type='visitor'");$q->execute([$visitorMessageId,$cid]);$message=$q->fetch(PDO::FETCH_ASSOC);
    if(!$message||(int)$conversation['profile_agent_id']!==(int)$ctx['agent_id']||(int)$conversation['profile_session_id']!==(int)$ctx['session_id'])vp3_profile_handoff_error_pa3('HANDOFF_UNAVAILABLE',403);
    if($existing=vp3_profile_handoff_retry_pa3($pdo,$ctx,$clientRequestId,(string)$message['message']))return $existing;
    if(!$acceptedSnapshot&&function_exists('vp3_profile_agent_accepted_snapshot_pa3'))$acceptedSnapshot=vp3_profile_agent_accepted_snapshot_pa3($ctx);
    $accepted=json_encode($acceptedSnapshot,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(!$acceptedSnapshot||strlen($accepted)>16384)vp3_profile_handoff_error_pa3('HANDOFF_INVALID',422);
    $now=vp3_profile_handoff_now_pa3();$thread=vp3_profile_handoff_thread_pa3($pdo,$uid,$cid,true);
    if(!$thread){$mode=($conversation['status']??'')==='owner_joined'?'owner':(vp3_profile_handoff_reachable_pa3($pdo,$uid)?'pending':'agent');$pdo->prepare('INSERT INTO profile_agent_handoff_conversations_pa3(conversation_id,owner_user_id,mode,epoch,delivery_until_ms,updated_at_ms) VALUES(?,?,?,1,?,?)')->execute([$cid,$uid,$mode,$mode==='pending'?$now+VP3_PROFILE_HANDOFF_DELIVERY_MS_PA3:null,$now]);$thread=vp3_profile_handoff_thread_pa3($pdo,$uid,$cid,true);}
    elseif($thread['mode']==='resolved'){$mode=vp3_profile_handoff_reachable_pa3($pdo,$uid)?'pending':'agent';$pdo->prepare('UPDATE profile_agent_handoff_conversations_pa3 SET mode=?,epoch=epoch+1,delivery_until_ms=?,acknowledged_at_ms=NULL,pickup_deadline_ms=NULL,updated_at_ms=? WHERE conversation_id=? AND owner_user_id=?')->execute([$mode,$mode==='pending'?$now+VP3_PROFILE_HANDOFF_DELIVERY_MS_PA3:null,$now,$cid,$uid]);$thread=vp3_profile_handoff_thread_pa3($pdo,$uid,$cid,true);}
    $state=match($thread['mode']){'owner'=>'owner_claimed','agent'=>'agent_ready',default=>$thread['acknowledged_at_ms']!==null?'awaiting_owner':'awaiting_delivery'};
    $a=$ctx['conversation_authority'];$q=$pdo->prepare('INSERT INTO profile_agent_handoff_turns_pa3(owner_user_id,profile_agent_id,profile_session_id,conversation_id,visitor_message_id,visitor_user_id,transport,credential_hash,client_request_id,request_hash,accepted_json,state,conversation_epoch,delivery_until_ms,acknowledged_at_ms,pickup_deadline_ms,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$uid,(int)$ctx['agent_id'],(int)$ctx['session_id'],$cid,$visitorMessageId,(int)$a['visitor_user_id'],(string)$a['transport'],(string)$a['credential_hash'],$clientRequestId,vp3_profile_handoff_request_hash_pa3($ctx,(string)$message['message']),$accepted,$state,(int)$thread['epoch'],$thread['delivery_until_ms'],$thread['acknowledged_at_ms'],$thread['pickup_deadline_ms'],vp3_profile_handoff_date_pa3($now),vp3_profile_handoff_date_pa3($now)]);$turnId=(int)$pdo->lastInsertId();
    $q=$pdo->prepare("SELECT n.id FROM notifications n INNER JOIN profile_events e ON e.id=n.source_id AND e.owner_user_id=n.user_id WHERE n.user_id=? AND n.source_type='profile_event' AND e.dedupe_key=? ORDER BY n.id ASC LIMIT 1");$q->execute([$uid,hash('sha256','profile-message|'.$uid.'|'.$visitorMessageId)]);$notification=(int)$q->fetchColumn();
    if(!function_exists('agent_job_engine_schema_ready_v1900'))require_once __DIR__.'/agent-job-engine-v1900.php';if(!agent_job_engine_schema_ready_v1900($pdo))vp3_profile_handoff_error_pa3('HANDOFF_NOT_READY',503);
    $source='profile-turn:'.$turnId;$pdo->prepare("INSERT INTO agent_workflow_runs(owner_user_id,agent_id,workflow_type,origin,source_kind,source_key,source_hash,dedupe_key,title,goal,decision_summary,status,risk_level,requires_approval,approval_status,execution_target,capability_key,max_attempts,retry_backoff_seconds,timeout_seconds,current_action_id) VALUES(?,?,'profile_visitor_reply','profile','profile_visitor_turn',?,?,?,'Reply to Profile visitor','Answer the exact accepted public visitor message.','Public Profile authority and current source permissions apply.','paused','low',0,'not_required','cloud','profile.visitor.reply',3,5,300,NULL)")->execute([$uid,(int)$ctx['agent_id'],$source,sha1($source),hash('sha256',$source)]);$runId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO agent_workflow_actions(run_id,owner_user_id,sequence_no,action_key,action_type,label,summary,status,requires_approval,execution_target,capability_key,max_attempts,timeout_seconds) VALUES(?,?,1,?,'execute','Reply to Profile visitor','Use only the accepted public conversation.','queued',0,'cloud','profile.visitor.reply',3,300)")->execute([$runId,$uid,$source]);$actionId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE profile_agent_handoff_turns_pa3 SET run_id=?,action_id=?,notification_id=? WHERE id=?')->execute([$runId,$actionId,$notification?:null,$turnId]);
    vp3_profile_handoff_schedule_pa3($pdo,$uid,$cid);
    return vp3_profile_handoff_row_pa3($pdo,$turnId)??throw new RuntimeException('Visitor turn was not saved.');
}

function vp3_profile_handoff_projection_pa3(array $turn): array
{
    $state=(string)($turn['state']??'none');$terminal=in_array($state,['replied','blocked','cancelled','none'],true);
    $responder=match($state){'agent_ready','agent_running'=>'agent','owner_claimed'=>'owner','awaiting_delivery','awaiting_owner'=>'waiting',default=>'none'};
    return [
        'contract'=>VP3_PROFILE_HANDOFF_PA3,'turn_id'=>(int)($turn['id']??0),'revision'=>(int)($turn['version']??0),
        'conversation_id'=>(int)($turn['conversation_id']??0),'profile_agent_id'=>(int)($turn['profile_agent_id']??0),
        'visitor_message_id'=>(int)($turn['visitor_message_id']??0),'state'=>$state,'responder'=>$responder,
        'server_now_ms'=>vp3_profile_handoff_now_pa3(),'acknowledged_at_ms'=>isset($turn['acknowledged_at_ms'])?(int)$turn['acknowledged_at_ms']:null,
        'pickup_deadline_ms'=>isset($turn['pickup_deadline_ms'])?(int)$turn['pickup_deadline_ms']:null,
        'answer_message_id'=>(int)($turn['answer_message_id']??0),
        'execution_paused'=>false,
        'can_claim'=>!$terminal&&$state!=='owner_claimed','can_reply'=>!$terminal,
        'can_delegate'=>!$terminal&&!in_array($state,['agent_ready','agent_running'],true),'can_resolve'=>!$terminal,
        'reason'=>in_array($turn['last_error_class']??'',['authority_revoked','execution_failed','execution_changed','lease_expired','owner_resolved'],true)?(string)$turn['last_error_class']:'',
    ];
}
function vp3_profile_handoff_execution_paused_pa3(PDO $pdo,array $turn): bool
{
    if(!in_array($turn['state']??'',['agent_ready','agent_running'],true))return false;
    $q=$pdo->prepare('SELECT * FROM agent_workflow_runs WHERE id=? AND owner_user_id=?');$q->execute([(int)($turn['run_id']??0),(int)($turn['owner_user_id']??0)]);$run=$q->fetch(PDO::FETCH_ASSOC);
    return $run&&$run['status']==='paused'&&(!empty($run['paused_at'])||trim((string)($run['paused_from_status']??''))!==''||!empty($run['pause_requested_at']));
}
function vp3_profile_handoff_dispatch_projection_pa3(PDO $pdo,int $owner,int $cid): array
{
    $q=$pdo->prepare("SELECT * FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND conversation_id=? AND state IN ('awaiting_delivery','awaiting_owner','agent_ready','agent_running') ORDER BY visitor_message_id,id LIMIT 1");$q->execute([$owner,$cid]);$pending=$q->fetch(PDO::FETCH_ASSOC);
    return ['dispatch_turn_id'=>$pending?(int)$pending['id']:0,'dispatch_state'=>$pending?(string)$pending['state']:'none','dispatch_execution_paused'=>$pending?vp3_profile_handoff_execution_paused_pa3($pdo,$pending):false];
}
function vp3_profile_handoff_public_pa3(PDO $pdo,int $owner,int $conversationId): array
{
    if(!vp3_profile_handoff_schema_ready_pa3($pdo))return vp3_profile_handoff_projection_pa3(['conversation_id'=>$conversationId]);
    $q=$pdo->prepare('SELECT * FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND conversation_id=? ORDER BY visitor_message_id DESC,id DESC LIMIT 1');$q->execute([$owner,$conversationId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $out=vp3_profile_handoff_projection_pa3($row?:['conversation_id'=>$conversationId]);
    if($row&&vp3_profile_handoff_execution_paused_pa3($pdo,$row)){$out['execution_paused']=true;$out['reason']='execution_paused';}
    unset($out['can_claim'],$out['can_reply'],$out['can_delegate'],$out['can_resolve']);
    return $out+vp3_profile_handoff_dispatch_projection_pa3($pdo,$owner,$conversationId);
}
function vp3_profile_handoff_owner_card_pa3(PDO $pdo,array $turn): array
{
    $owner=(int)$turn['owner_user_id'];$cid=(int)$turn['conversation_id'];$out=vp3_profile_handoff_projection_pa3($turn);
    if(vp3_profile_handoff_execution_paused_pa3($pdo,$turn)){$out['execution_paused']=true;$out['reason']='execution_paused';}
    $out+=vp3_profile_handoff_dispatch_projection_pa3($pdo,$owner,$cid);
    $thread=vp3_profile_handoff_thread_pa3($pdo,$owner,$cid);$conversation=vp3_profile_handoff_conversation_pa3($pdo,$owner,$cid);
    if($turn['state']==='replied'&&($thread['mode']??'resolved')!=='resolved'&&($conversation['status']??'resolved')!=='resolved'){
        $latest=$pdo->prepare('SELECT id FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND conversation_id=? ORDER BY id DESC LIMIT 1');$latest->execute([$owner,$cid]);
        if((int)$latest->fetchColumn()===(int)$turn['id']){$out['can_claim']=$thread['mode']!=='owner';$out['can_reply']=true;$out['can_delegate']=$thread['mode']==='owner';$out['can_resolve']=true;$out['responder']=$thread['mode']==='owner'?'owner':'agent';}
    }
    $q=$pdo->prepare("SELECT message FROM profile_agent_messages WHERE id=? AND conversation_id=? AND sender_type='visitor'");$q->execute([(int)$turn['visitor_message_id'],$cid]);$message=$q->fetchColumn();if(!is_string($message))vp3_profile_handoff_error_pa3('HANDOFF_CHANGED',409);
    // CRM recognition is mutable across account switches; it cannot rename an
    // older conversation or disclose a different member's identity on its card.
    $q=$pdo->prepare('SELECT CASE WHEN s.visitor_user_id=? THEN s.identity_disclosed ELSE 0 END identity_disclosed,u.display_name FROM profile_visit_sessions s LEFT JOIN users u ON u.id=? WHERE s.id=? AND s.owner_user_id=?');$q->execute([(int)$turn['visitor_user_id'],(int)$turn['visitor_user_id'],(int)$turn['profile_session_id'],$owner]);$session=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT display_name FROM user_agents WHERE id=? AND owner_user_id=?');$q->execute([(int)$turn['profile_agent_id'],$owner]);$agent=$q->fetchColumn();
    $out['visitor_label']=!empty($session['identity_disclosed'])&&is_string($session['display_name']??null)?mb_substr(trim($session['display_name']),0,100):'Visitor';
    if($out['visitor_label']==='')$out['visitor_label']='Visitor';$out['visitor_message']=mb_substr($message,0,4000);$out['agent_name']=is_string($agent)?mb_substr($agent,0,100):'Profile Agent';
    $q=$pdo->prepare("SELECT id,sender_type,message,created_at FROM profile_agent_messages WHERE conversation_id=? AND sender_type IN ('visitor','owner','agent') ORDER BY id DESC LIMIT 20");$q->execute([$cid]);$out['history']=array_reverse(array_map(static fn(array $m):array=>['id'=>(int)$m['id'],'sender_type'=>(string)$m['sender_type'],'message'=>mb_substr((string)$m['message'],0,8000),'created_at'=>(string)$m['created_at']],$q->fetchAll(PDO::FETCH_ASSOC)));
    return $out;
}
function vp3_profile_handoff_owner_list_pa3(PDO $pdo,array $user,array $input=[]): array
{
    vp3_profile_handoff_require_pa3($pdo);$cid=vp3_profile_handoff_id_pa3($input['chat_conversation_id']??0,true);$aid=vp3_profile_handoff_id_pa3($input['chat_agent_id']??0,true);$live=vp3_profile_handoff_owner_pa3($pdo,$user,$cid,$aid);$owner=(int)$live['id'];
    $out=['contract'=>VP3_PROFILE_HANDOFF_PA3,'availability'=>vp3_profile_handoff_availability_pa3($pdo,$owner),'reachable'=>$cid>0&&vp3_profile_handoff_reachable_pa3($pdo,$owner),'server_now_ms'=>vp3_profile_handoff_now_pa3(),'turns'=>[]];
    if($cid>0){
        $q=$pdo->prepare("SELECT t.* FROM profile_agent_handoff_turns_pa3 t INNER JOIN profile_agent_conversations c ON c.id=t.conversation_id AND c.owner_user_id=t.owner_user_id WHERE t.owner_user_id=? AND (t.state NOT IN ('replied','blocked','cancelled') OR EXISTS (SELECT 1 FROM profile_agent_handoff_deliveries_pa3 delivered WHERE delivered.turn_id=t.id AND delivered.owner_user_id=t.owner_user_id AND delivered.chat_conversation_id=? AND delivered.chat_agent_id=?)) AND NOT EXISTS (SELECT 1 FROM profile_agent_handoff_turns_pa3 newer WHERE newer.conversation_id=t.conversation_id AND newer.owner_user_id=t.owner_user_id AND newer.id>t.id) ORDER BY t.id DESC LIMIT 20");$q->execute([$owner,$cid,$aid]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $turn){$card=vp3_profile_handoff_owner_card_pa3($pdo,$turn);$card['chat_conversation_id']=$cid;$card['chat_agent_id']=$aid;$card['chat_message_id']=0;$out['turns'][]=$card;}
    }
    vp3_profile_handoff_owner_pa3($pdo,$live,$cid,$aid);return $out;
}
function vp3_profile_handoff_notification_owned_pa3(PDO $pdo,array $user,array $notification): bool
{
    $owner=(int)($user['id']??0);$nid=(int)($notification['id']??0);
    if($owner<1||$nid<1||(int)($notification['user_id']??0)!==$owner||!vp3_profile_handoff_schema_ready_pa3($pdo))return false;
    $q=$pdo->prepare('SELECT t.id FROM profile_agent_handoff_turns_pa3 t INNER JOIN notifications n ON n.id=t.notification_id AND n.user_id=t.owner_user_id WHERE t.owner_user_id=? AND t.notification_id=? LIMIT 1');$q->execute([$owner,$nid]);if($q->fetchColumn())return true;
    if(($notification['source_type']??'')!=='profile_event')return false;
    $q=$pdo->prepare("SELECT t.id FROM profile_agent_handoff_turns_pa3 t INNER JOIN agent_attention_items a ON a.owner_user_id=t.owner_user_id AND a.source_conversation_id=t.conversation_id AND a.profile_agent_id=t.profile_agent_id INNER JOIN profile_events e ON e.id=a.source_event_id AND e.owner_user_id=t.owner_user_id AND e.profile_session_id=t.profile_session_id INNER JOIN notifications n ON n.source_type='profile_event' AND n.source_id=e.id AND n.user_id=t.owner_user_id WHERE t.owner_user_id=? AND n.id=? LIMIT 1");$q->execute([$owner,$nid]);return(bool)$q->fetchColumn();
}
function vp3_profile_handoff_tab_pa3(PDO $pdo,int $owner,string $tab,int $cid,int $aid,bool $visible=true): array
{
    $q=$pdo->prepare('SELECT * FROM profile_agent_handoff_presence_pa3 WHERE owner_user_id=? AND tab_id=?');$q->execute([$owner,$tab]);$row=$q->fetch(PDO::FETCH_ASSOC);$now=vp3_profile_handoff_now_pa3();
    if(!$row||(int)$row['chat_conversation_id']!==$cid||(int)$row['chat_agent_id']!==$aid||(int)$row['last_seen_ms']>$now||(int)$row['last_seen_ms']<$now-VP3_PROFILE_HANDOFF_PRESENCE_MS_PA3||($visible&&(int)$row['visible']!==1))vp3_profile_handoff_error_pa3('HANDOFF_CHANGED',409);
    return $row;
}
function vp3_profile_handoff_card_identity_pa3(PDO $pdo,int $owner,int $cid,int $messageId,int $turnId,int $publicCid): void
{
    $q=$pdo->prepare("SELECT m.context_json FROM chat_messages m INNER JOIN chat_conversations c ON c.id=m.conversation_id WHERE m.id=? AND m.conversation_id=? AND c.user_id=? AND m.role='assistant'");$q->execute([$messageId,$cid,$owner]);$raw=$q->fetchColumn();$ctx=is_string($raw)&&strlen($raw)<=65536?json_decode($raw,true):null;$marker=is_array($ctx)?($ctx['profile_handoff']??null):null;
    if(!is_array($marker)||($marker['contract']??null)!==VP3_PROFILE_HANDOFF_PA3||($marker['turn_id']??null)!==$turnId||($marker['conversation_id']??null)!==$publicCid)vp3_profile_handoff_error_pa3('HANDOFF_CHANGED',409);
}
function vp3_profile_handoff_delivery_pa3(PDO $pdo,array $user,array $input): array
{
    vp3_profile_handoff_require_pa3($pdo);$turnId=vp3_profile_handoff_id_pa3($input['turn_id']??null);$revision=vp3_profile_handoff_id_pa3($input['revision']??null);$tab=vp3_profile_handoff_token_pa3($input['tab_id']??null);$cid=vp3_profile_handoff_id_pa3($input['chat_conversation_id']??null);$aid=vp3_profile_handoff_id_pa3($input['chat_agent_id']??0,true);$live=vp3_profile_handoff_owner_pa3($pdo,$user,$cid,$aid);$uid=(int)$live['id'];
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$live,$uid,$turnId,$revision,$tab,$cid,$aid){
        $turn=vp3_profile_handoff_row_pa3($pdo,$turnId);if(!$turn||(int)$turn['owner_user_id']!==$uid)vp3_profile_handoff_error_pa3('HANDOFF_NOT_FOUND',404);
        vp3_profile_handoff_conversation_pa3($pdo,$uid,(int)$turn['conversation_id'],true);$turn=vp3_profile_handoff_row_pa3($pdo,$turnId,true);
        if((int)$turn['version']!==$revision)vp3_profile_handoff_error_pa3();vp3_profile_handoff_tab_pa3($pdo,$uid,$tab,$cid,$aid);
        $q=$pdo->prepare('SELECT * FROM profile_agent_handoff_deliveries_pa3 WHERE turn_id=? AND tab_id=? AND chat_conversation_id=? AND chat_agent_id=? AND turn_version=?');$q->execute([$turnId,$tab,$cid,$aid,$revision]);$delivery=$q->fetch(PDO::FETCH_ASSOC);
        if(!$delivery){
            $q=$pdo->prepare('SELECT chat_message_id FROM profile_agent_handoff_deliveries_pa3 WHERE turn_id=? AND owner_user_id=? AND chat_conversation_id=? AND chat_agent_id=? ORDER BY created_at_ms DESC LIMIT 1');$q->execute([$turnId,$uid,$cid,$aid]);$messageId=(int)$q->fetchColumn();
            if($messageId)vp3_profile_handoff_card_identity_pa3($pdo,$uid,$cid,$messageId,$turnId,(int)$turn['conversation_id']);
            else{$ctx=['sources'=>[],'profile_handoff'=>['contract'=>VP3_PROFILE_HANDOFF_PA3,'turn_id'=>$turnId,'revision'=>$revision,'conversation_id'=>(int)$turn['conversation_id']]];$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at) VALUES(?,NULL,'assistant','Profile visitor conversation.',?,?)")->execute([$cid,json_encode($ctx,JSON_THROW_ON_ERROR),vp3_profile_handoff_date_pa3()]);$messageId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE chat_conversations SET updated_at=? WHERE id=? AND user_id=?')->execute([vp3_profile_handoff_date_pa3(),$cid,$uid]);}
            $deliveryId=bin2hex(random_bytes(16));$pdo->prepare('INSERT INTO profile_agent_handoff_deliveries_pa3(delivery_id,turn_id,owner_user_id,tab_id,chat_conversation_id,chat_agent_id,chat_message_id,turn_version,conversation_epoch,created_at_ms) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$deliveryId,$turnId,$uid,$tab,$cid,$aid,$messageId,$revision,(int)$turn['conversation_epoch'],vp3_profile_handoff_now_pa3()]);
            $delivery=['delivery_id'=>$deliveryId,'chat_message_id'=>$messageId];
        }
        vp3_profile_handoff_card_identity_pa3($pdo,$uid,$cid,(int)$delivery['chat_message_id'],$turnId,(int)$turn['conversation_id']);$card=vp3_profile_handoff_owner_card_pa3($pdo,$turn);$card+=['chat_conversation_id'=>$cid,'chat_agent_id'=>$aid,'chat_message_id'=>(int)$delivery['chat_message_id']];vp3_profile_handoff_owner_pa3($pdo,$live,$cid,$aid);
        return ['contract'=>VP3_PROFILE_HANDOFF_PA3,'turn'=>$card,'delivery_id'=>(string)$delivery['delivery_id'],'chat_conversation_id'=>$cid,'chat_agent_id'=>$aid,'chat_message_id'=>(int)$delivery['chat_message_id'],'message_id'=>(int)$delivery['chat_message_id']];
    });
}
function vp3_profile_handoff_ack_pa3(PDO $pdo,array $user,array $input): array
{
    vp3_profile_handoff_require_pa3($pdo);$turnId=vp3_profile_handoff_id_pa3($input['turn_id']??null);$revision=vp3_profile_handoff_id_pa3($input['revision']??null);$tab=vp3_profile_handoff_token_pa3($input['tab_id']??null);$deliveryId=vp3_profile_handoff_token_pa3($input['delivery_id']??null);$cid=vp3_profile_handoff_id_pa3($input['chat_conversation_id']??null);$aid=vp3_profile_handoff_id_pa3($input['chat_agent_id']??0,true);$messageId=vp3_profile_handoff_id_pa3($input['chat_message_id']??null);$live=vp3_profile_handoff_owner_pa3($pdo,$user,$cid,$aid);$uid=(int)$live['id'];
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$live,$uid,$turnId,$revision,$tab,$deliveryId,$cid,$aid,$messageId){
        $turn=vp3_profile_handoff_row_pa3($pdo,$turnId);if(!$turn||(int)$turn['owner_user_id']!==$uid)vp3_profile_handoff_error_pa3('HANDOFF_NOT_FOUND',404);$publicCid=(int)$turn['conversation_id'];vp3_profile_handoff_conversation_pa3($pdo,$uid,$publicCid,true);$thread=vp3_profile_handoff_thread_pa3($pdo,$uid,$publicCid,true);$turn=vp3_profile_handoff_row_pa3($pdo,$turnId,true);
        $q=$pdo->prepare('SELECT * FROM profile_agent_handoff_deliveries_pa3 WHERE delivery_id=? AND owner_user_id=?'.vp3_profile_handoff_lock_pa3($pdo));$q->execute([$deliveryId,$uid]);$delivery=$q->fetch(PDO::FETCH_ASSOC);
        if(!$delivery||(int)$delivery['turn_id']!==$turnId||!hash_equals($delivery['tab_id'],$tab)||(int)$delivery['chat_conversation_id']!==$cid||(int)$delivery['chat_agent_id']!==$aid||(int)$delivery['chat_message_id']!==$messageId||(int)$delivery['turn_version']!==$revision)vp3_profile_handoff_error_pa3();
        vp3_profile_handoff_tab_pa3($pdo,$uid,$tab,$cid,$aid);vp3_profile_handoff_card_identity_pa3($pdo,$uid,$cid,$messageId,$turnId,$publicCid);
        if(!$thread||(int)$thread['epoch']!==(int)$delivery['conversation_epoch'])vp3_profile_handoff_error_pa3();
        if($delivery['acknowledged_at_ms']!==null){vp3_profile_handoff_owner_pa3($pdo,$live,$cid,$aid);return ['contract'=>VP3_PROFILE_HANDOFF_PA3,'duplicate'=>true,'turn'=>vp3_profile_handoff_owner_card_pa3($pdo,$turn)];}
        $now=vp3_profile_handoff_now_pa3();
        if(!$thread||$thread['mode']!=='pending'||(int)$thread['epoch']!==(int)$delivery['conversation_epoch']||!vp3_profile_handoff_reachable_pa3($pdo,$uid))vp3_profile_handoff_error_pa3();
        if($thread['acknowledged_at_ms']===null){
            if((int)$turn['version']!==$revision||$turn['state']!=='awaiting_delivery'||$thread['delivery_until_ms']===null||$now>=(int)$thread['delivery_until_ms'])vp3_profile_handoff_error_pa3();
            $deadline=$now+VP3_PROFILE_HANDOFF_PICKUP_MS_PA3;$pdo->prepare('UPDATE profile_agent_handoff_conversations_pa3 SET acknowledged_at_ms=?,pickup_deadline_ms=?,updated_at_ms=? WHERE conversation_id=? AND owner_user_id=?')->execute([$now,$deadline,$now,$publicCid,$uid]);
            $pdo->prepare("UPDATE profile_agent_handoff_turns_pa3 SET state='awaiting_owner',version=version+1,acknowledged_at_ms=?,pickup_deadline_ms=?,updated_at=? WHERE conversation_id=? AND owner_user_id=? AND conversation_epoch=? AND state='awaiting_delivery'")->execute([$now,$deadline,vp3_profile_handoff_date_pa3($now),$publicCid,$uid,(int)$thread['epoch']]);
        }
        $pdo->prepare('UPDATE profile_agent_handoff_deliveries_pa3 SET acknowledged_at_ms=? WHERE delivery_id=? AND owner_user_id=?')->execute([$now,$deliveryId,$uid]);$turn=vp3_profile_handoff_row_pa3($pdo,$turnId,true);vp3_profile_handoff_owner_pa3($pdo,$live,$cid,$aid);
        return ['contract'=>VP3_PROFILE_HANDOFF_PA3,'duplicate'=>false,'turn'=>vp3_profile_handoff_owner_card_pa3($pdo,$turn)];
    });
}

/** Called only while the exact Profile conversation is locked. */
function vp3_profile_handoff_schedule_pa3(PDO $pdo,int $owner,int $cid): void
{
    if(!$pdo->inTransaction())throw new RuntimeException('Visitor scheduling requires a transaction.');
    $thread=vp3_profile_handoff_thread_pa3($pdo,$owner,$cid,true);if(!$thread||$thread['mode']!=='agent')return;
    $q=$pdo->prepare("SELECT t.*,r.status job_status FROM profile_agent_handoff_turns_pa3 t INNER JOIN agent_workflow_runs r ON r.id=t.run_id AND r.owner_user_id=t.owner_user_id WHERE t.owner_user_id=? AND t.conversation_id=? AND t.state IN ('agent_ready','agent_running') ORDER BY t.visitor_message_id,t.id LIMIT 1".vp3_profile_handoff_lock_pa3($pdo));$q->execute([$owner,$cid]);$turn=$q->fetch(PDO::FETCH_ASSOC);if(!$turn)return;
    if(in_array($turn['job_status'],['failed','cancelled'],true)){$pdo->prepare("UPDATE profile_agent_handoff_turns_pa3 SET state='blocked',version=version+1,last_error_class='execution_failed',updated_at=? WHERE id=?")->execute([vp3_profile_handoff_date_pa3(),(int)$turn['id']]);return;}
    $q=$pdo->prepare('SELECT * FROM agent_workflow_runs WHERE id=? AND owner_user_id=?');$q->execute([(int)$turn['run_id'],$owner]);$run=$q->fetch(PDO::FETCH_ASSOC);
    // Paused is also the internal hold used before pickup. Only that unmarked
    // hold may be activated here; an explicit canonical job pause must survive.
    if($turn['job_status']==='paused'&&($run&&(!empty($run['paused_at'])||trim((string)($run['paused_from_status']??''))!==''||!empty($run['pause_requested_at']))))return;
    if($turn['job_status']==='paused'){
        $pdo->prepare("UPDATE agent_workflow_runs SET status='approved',next_attempt_at=NULL,progress_message='Ready for visitor reply' WHERE id=? AND owner_user_id=? AND status='paused'")->execute([(int)$turn['run_id'],$owner]);
        $pdo->prepare("UPDATE agent_workflow_actions SET status='queued',available_at=NULL WHERE id=? AND run_id=? AND owner_user_id=? AND status IN ('queued','executing')")->execute([(int)$turn['action_id'],(int)$turn['run_id'],$owner]);
    }
    if($turn['state']==='agent_running'&&$turn['job_status']==='approved')$pdo->prepare("UPDATE profile_agent_handoff_turns_pa3 SET state='agent_ready',version=version+1,execution_lease_token='',updated_at=? WHERE id=?")->execute([vp3_profile_handoff_date_pa3(),(int)$turn['id']]);
}
function vp3_profile_handoff_mode_pa3(PDO $pdo,int $owner,int $cid,string $mode): array
{
    if(!$pdo->inTransaction()||!in_array($mode,['agent','owner','resolved'],true))throw new RuntimeException('Visitor handoff transition is invalid.');
    $thread=vp3_profile_handoff_thread_pa3($pdo,$owner,$cid,true);if(!$thread)vp3_profile_handoff_error_pa3('HANDOFF_NOT_FOUND',404);
    $now=vp3_profile_handoff_now_pa3();$epoch=(int)$thread['epoch']+1;$state=match($mode){'agent'=>'agent_ready','owner'=>'owner_claimed',default=>'cancelled'};
    $pdo->prepare('UPDATE profile_agent_handoff_conversations_pa3 SET mode=?,epoch=?,updated_at_ms=? WHERE conversation_id=? AND owner_user_id=?')->execute([$mode,$epoch,$now,$cid,$owner]);
    $q=$pdo->prepare("SELECT id,run_id,action_id FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND conversation_id=? AND state IN ('awaiting_delivery','awaiting_owner','agent_ready','agent_running','owner_claimed') ORDER BY id".vp3_profile_handoff_lock_pa3($pdo));$q->execute([$owner,$cid]);$turns=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($turns as $turn){
        $pdo->prepare('UPDATE profile_agent_handoff_turns_pa3 SET state=?,version=version+1,conversation_epoch=?,execution_lease_token=\'\',last_error_class=?,updated_at=? WHERE id=? AND owner_user_id=?')->execute([$state,$epoch,$mode==='resolved'?'owner_resolved':'',vp3_profile_handoff_date_pa3($now),(int)$turn['id'],$owner]);
        $jobState=$mode==='resolved'?'cancelled':'paused';$actionState=$mode==='resolved'?'cancelled':'queued';
        $pdo->prepare("UPDATE agent_workflow_runs SET status=?,lease_owner='',lease_token='',lease_expires_at=NULL,heartbeat_at=NULL,current_action_id=NULL,next_attempt_at=NULL,progress_message=? WHERE id=? AND owner_user_id=? AND status NOT IN ('completed','failed','cancelled')")->execute([$jobState,$mode==='resolved'?'Visitor conversation resolved':'Visitor responder changed',(int)$turn['run_id'],$owner]);
        $pdo->prepare("UPDATE agent_workflow_actions SET status=?,available_at=NULL,heartbeat_at=NULL,progress_message=? WHERE id=? AND run_id=? AND owner_user_id=? AND status NOT IN ('completed','failed','cancelled')")->execute([$actionState,'Visitor responder changed',(int)$turn['action_id'],(int)$turn['run_id'],$owner]);
    }
    $status=match($mode){'agent'=>'open','owner'=>'owner_joined',default=>'resolved'};$pdo->prepare('UPDATE profile_agent_conversations SET status=?,updated_at=? WHERE id=? AND owner_user_id=?')->execute([$status,vp3_profile_handoff_date_pa3($now),$cid,$owner]);
    if($mode==='agent')vp3_profile_handoff_schedule_pa3($pdo,$owner,$cid);
    return vp3_profile_handoff_thread_pa3($pdo,$owner,$cid,true)??throw new RuntimeException('Visitor responder was not saved.');
}
function vp3_profile_handoff_owner_action_pa3(PDO $pdo,array $user,array $input,bool $legacy=false): array
{
    vp3_profile_handoff_require_pa3($pdo);$turnId=vp3_profile_handoff_id_pa3($input['turn_id']??null);$revision=vp3_profile_handoff_id_pa3($input['revision']??null);$publicCid=vp3_profile_handoff_id_pa3($input['conversation_id']??null);$requestId=vp3_profile_handoff_token_pa3($input['request_id']??null);$operation=$input['operation']??null;
    if(!in_array($operation,['claim','reply','delegate','resolve'],true))vp3_profile_handoff_error_pa3('HANDOFF_INVALID',422);
    $message=$operation==='reply'?($input['message']??null):'';if(!is_string($message)||($operation==='reply'&&(trim($message)===''||mb_strlen($message)>4000)))vp3_profile_handoff_error_pa3('HANDOFF_INVALID',422);$message=trim($message);
    $cid=$legacy?0:vp3_profile_handoff_id_pa3($input['chat_conversation_id']??null);$aid=$legacy?0:vp3_profile_handoff_id_pa3($input['chat_agent_id']??0,true);$live=vp3_profile_handoff_owner_pa3($pdo,$user,$cid,$aid,$legacy);$owner=(int)$live['id'];
    $hash=hash('sha256',json_encode([$owner,$turnId,$revision,$publicCid,$operation,$message,$cid,$aid,$legacy],JSON_THROW_ON_ERROR));
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$live,$owner,$turnId,$revision,$publicCid,$requestId,$operation,$message,$cid,$aid,$hash,$legacy){
        vp3_profile_handoff_conversation_pa3($pdo,$owner,$publicCid,true);$turn=vp3_profile_handoff_row_pa3($pdo,$turnId,true);
        if(!$turn||(int)$turn['owner_user_id']!==$owner||(int)$turn['conversation_id']!==$publicCid)vp3_profile_handoff_error_pa3('HANDOFF_NOT_FOUND',404);
        $q=$pdo->prepare('SELECT * FROM profile_agent_handoff_operations_pa3 WHERE owner_user_id=? AND request_id=?');$q->execute([$owner,$requestId]);$prior=$q->fetch(PDO::FETCH_ASSOC);
        if($prior){if(!hash_equals($prior['request_hash'],$hash))vp3_profile_handoff_error_pa3('HANDOFF_REPLAY_CONFLICT',409);vp3_profile_handoff_owner_pa3($pdo,$live,$cid,$aid,$legacy);return ['contract'=>VP3_PROFILE_HANDOFF_PA3,'duplicate'=>true,'turn'=>vp3_profile_handoff_owner_card_pa3($pdo,$turn),'message_id'=>(int)($prior['message_id']??0)];}
        if((int)$turn['version']!==$revision)vp3_profile_handoff_error_pa3();$terminal=in_array($turn['state'],['replied','blocked','cancelled'],true);
        if($terminal&&!$legacy){
            $thread=vp3_profile_handoff_thread_pa3($pdo,$owner,$publicCid,true);$latest=$pdo->prepare('SELECT id FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND conversation_id=? ORDER BY id DESC LIMIT 1');$latest->execute([$owner,$publicCid]);
            if($turn['state']!=='replied'||!$thread||$thread['mode']==='resolved'||(int)$latest->fetchColumn()!==$turnId||($operation==='delegate'&&$thread['mode']!=='owner'))vp3_profile_handoff_error_pa3();
        }
        if($legacy&&$terminal&&$turn['state']!=='replied'&&$operation==='reply')vp3_profile_handoff_error_pa3();
        vp3_profile_agent_response_token_v1($pdo,$publicCid,true);
        $mode=match($operation){'delegate'=>'agent','resolve'=>'resolved',default=>'owner'};vp3_profile_handoff_mode_pa3($pdo,$owner,$publicCid,$mode);$messageId=0;
        if($operation==='reply'){
            $ctx=['profile_handoff'=>['contract'=>VP3_PROFILE_HANDOFF_PA3,'turn_id'=>$turnId,'owner_request_id'=>$requestId]];$pdo->prepare("INSERT INTO profile_agent_messages(conversation_id,sender_type,sender_user_id,message,context_json,created_at) VALUES(?,'owner',?,?,?,?)")->execute([$publicCid,$owner,$message,json_encode($ctx,JSON_THROW_ON_ERROR),vp3_profile_handoff_date_pa3()]);$messageId=(int)$pdo->lastInsertId();
            $covered=$pdo->prepare("SELECT id,run_id,action_id FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND conversation_id=? AND visitor_message_id<=? AND state='owner_claimed' ORDER BY id".vp3_profile_handoff_lock_pa3($pdo));$covered->execute([$owner,$publicCid,(int)$turn['visitor_message_id']]);
            foreach($covered->fetchAll(PDO::FETCH_ASSOC) as $answered){
                $pdo->prepare("UPDATE profile_agent_handoff_turns_pa3 SET state='replied',version=version+1,answer_message_id=?,last_owner_message_id=?,updated_at=? WHERE id=? AND owner_user_id=?")->execute([$messageId,$messageId,vp3_profile_handoff_date_pa3(),(int)$answered['id'],$owner]);
                $pdo->prepare("UPDATE agent_workflow_runs SET status='cancelled',progress_message='Owner replied',cancelled_at=? WHERE id=? AND owner_user_id=? AND status='paused'")->execute([vp3_profile_handoff_date_pa3(),(int)$answered['run_id'],$owner]);$pdo->prepare("UPDATE agent_workflow_actions SET status='cancelled',progress_message='Owner replied' WHERE id=? AND owner_user_id=? AND status='queued'")->execute([(int)$answered['action_id'],$owner]);
            }
            if($terminal)$pdo->prepare('UPDATE profile_agent_handoff_turns_pa3 SET version=version+1,last_owner_message_id=?,updated_at=? WHERE id=? AND owner_user_id=?')->execute([$messageId,vp3_profile_handoff_date_pa3(),$turnId,$owner]);
            $pdo->prepare('UPDATE profile_agent_conversations SET last_summary=?,last_message_at=?,updated_at=? WHERE id=? AND owner_user_id=?')->execute([mb_strimwidth($message,0,900,'…'),vp3_profile_handoff_date_pa3(),vp3_profile_handoff_date_pa3(),$publicCid,$owner]);
        }elseif($terminal)$pdo->prepare('UPDATE profile_agent_handoff_turns_pa3 SET version=version+1,updated_at=? WHERE id=? AND owner_user_id=?')->execute([vp3_profile_handoff_date_pa3(),$turnId,$owner]);
        if(in_array($operation,['reply','resolve'],true))$pdo->prepare("UPDATE agent_attention_items SET status='handled',snoozed_until=NULL WHERE owner_user_id=? AND source_conversation_id=? AND status IN ('pending','seen','snoozed')")->execute([$owner,$publicCid]);
        $pdo->prepare('INSERT INTO profile_agent_handoff_operations_pa3(owner_user_id,request_id,turn_id,operation,request_hash,message_id,created_at_ms) VALUES(?,?,?,?,?,?,?)')->execute([$owner,$requestId,$turnId,$operation,$hash,$messageId?:null,vp3_profile_handoff_now_pa3()]);
        $turn=vp3_profile_handoff_row_pa3($pdo,$turnId,true);$card=vp3_profile_handoff_owner_card_pa3($pdo,$turn);vp3_profile_handoff_owner_pa3($pdo,$live,$cid,$aid,$legacy);return ['contract'=>VP3_PROFILE_HANDOFF_PA3,'duplicate'=>false,'turn'=>$card,'message_id'=>$messageId];
    });
}
function vp3_profile_handoff_tick_pa3(PDO $pdo,int $owner,int $conversationId=0,int $limit=20): array
{
    vp3_profile_handoff_require_pa3($pdo);$limit=max(1,min(100,$limit));$params=[$owner];$where=$conversationId>0?' AND conversation_id=?':'';if($conversationId>0)$params[]=$conversationId;
    $q=$pdo->prepare("SELECT conversation_id FROM profile_agent_handoff_conversations_pa3 WHERE owner_user_id=? AND mode IN ('pending','agent')".$where.' ORDER BY updated_at_ms,conversation_id LIMIT '.$limit);$q->execute($params);$changed=0;
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $cid){$changed+=vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$owner,$cid){
        vp3_profile_handoff_conversation_pa3($pdo,$owner,(int)$cid,true);$thread=vp3_profile_handoff_thread_pa3($pdo,$owner,(int)$cid,true);if(!$thread)return 0;$now=vp3_profile_handoff_now_pa3();
        if($thread['mode']==='pending'){
            $deadline=$thread['acknowledged_at_ms']!==null?$thread['pickup_deadline_ms']:$thread['delivery_until_ms'];
            if(!vp3_profile_handoff_reachable_pa3($pdo,$owner)||$deadline===null||$now>=(int)$deadline){vp3_profile_handoff_mode_pa3($pdo,$owner,(int)$cid,'agent');return 1;}
        }elseif($thread['mode']==='agent')vp3_profile_handoff_schedule_pa3($pdo,$owner,(int)$cid);
        return 0;
    });}
    return ['contract'=>VP3_PROFILE_HANDOFF_PA3,'changed'=>$changed,'server_now_ms'=>vp3_profile_handoff_now_pa3()];
}

/** Bind the canonical action to the original accepted public turn, not just its numeric ID. */
function vp3_profile_handoff_job_pa3(PDO $pdo,array $turn,bool $lock=false): array
{
    $owner=(int)$turn['owner_user_id'];$suffix=$lock?vp3_profile_handoff_lock_pa3($pdo):'';$source='profile-turn:'.(int)$turn['id'];
    $q=$pdo->prepare('SELECT * FROM agent_workflow_runs WHERE id=? AND owner_user_id=?'.$suffix);$q->execute([(int)$turn['run_id'],$owner]);$run=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT * FROM agent_workflow_actions WHERE id=? AND run_id=? AND owner_user_id=?'.$suffix);$q->execute([(int)$turn['action_id'],(int)$turn['run_id'],$owner]);$action=$q->fetch(PDO::FETCH_ASSOC);
    if(!$run||!$action||(int)$run['agent_id']!==(int)$turn['profile_agent_id']||(int)$action['sequence_no']!==1||!in_array($run['requires_approval'],[0,'0'],true)||!in_array($action['requires_approval'],[0,'0'],true))vp3_profile_handoff_error_pa3('HANDOFF_JOB_CHANGED',409);
    foreach(['workflow_type'=>'profile_visitor_reply','origin'=>'profile','source_kind'=>'profile_visitor_turn','source_key'=>$source,'source_hash'=>sha1($source),'dedupe_key'=>hash('sha256',$source),'execution_target'=>'cloud','capability_key'=>'profile.visitor.reply','approval_status'=>'not_required'] as $key=>$expected){if(!is_string($run[$key]??null)||!hash_equals($expected,$run[$key]))vp3_profile_handoff_error_pa3('HANDOFF_JOB_CHANGED',409);}
    foreach(['action_key'=>$source,'action_type'=>'execute','execution_target'=>'cloud','capability_key'=>'profile.visitor.reply'] as $key=>$expected){if(!is_string($action[$key]??null)||!hash_equals($expected,$action[$key]))vp3_profile_handoff_error_pa3('HANDOFF_JOB_CHANGED',409);}
    if((int)$run['max_attempts']!==3||(int)$action['max_attempts']!==3||(int)$run['timeout_seconds']!==300||(int)$action['timeout_seconds']!==300)vp3_profile_handoff_error_pa3('HANDOFF_JOB_CHANGED',409);
    return ['run'=>$run,'action'=>$action];
}
function vp3_profile_handoff_block_changed_job_pa3(PDO $pdo,array $expected): array
{
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$expected){
        vp3_profile_handoff_conversation_pa3($pdo,(int)$expected['owner_user_id'],(int)$expected['conversation_id'],true);$turn=vp3_profile_handoff_row_pa3($pdo,(int)$expected['id'],true);
        if($turn&&(int)$turn['version']===(int)$expected['version']&&in_array($turn['state'],['awaiting_delivery','awaiting_owner','agent_ready','agent_running'],true))$pdo->prepare("UPDATE profile_agent_handoff_turns_pa3 SET state='blocked',version=version+1,execution_lease_token='',last_error_class='execution_changed',updated_at=? WHERE id=? AND owner_user_id=?")->execute([vp3_profile_handoff_date_pa3(),(int)$turn['id'],(int)$turn['owner_user_id']]);
        return ['ok'=>false,'contract'=>VP3_PROFILE_HANDOFF_PA3,'reason'=>'execution_changed','turn'=>vp3_profile_handoff_projection_pa3(vp3_profile_handoff_row_pa3($pdo,(int)$expected['id'])??$expected)];
    });
}
/** Read the canonical lease under the caller's publication transaction. */
function vp3_profile_handoff_lease_pa3(PDO $pdo,array $turn,array $claim,bool $lock=true): array
{
    $bound=vp3_profile_handoff_job_pa3($pdo,$turn,$lock);$run=$bound['run'];$action=$bound['action'];
    $token=$claim['lease_token']??null;
    if($run['status']!=='executing'||$action['status']!=='executing'||(int)$run['current_action_id']!==(int)$turn['action_id']||(int)($claim['run_id']??0)!==(int)$turn['run_id']||(int)($claim['action']['id']??0)!==(int)$turn['action_id']||!is_string($token)||!hash_equals((string)$run['lease_token'],$token)||!hash_equals((string)$run['lease_owner'],(string)($claim['worker_id']??''))||(strtotime((string)($run['lease_expires_at']??'').' UTC')?:0)<time())vp3_profile_handoff_error_pa3();
    return $run;
}
function vp3_profile_handoff_execution_fence_pa3(PDO $pdo,array $expected,array $claim): array
{
    $owner=(int)$expected['owner_user_id'];$cid=(int)$expected['conversation_id'];$conversation=vp3_profile_handoff_conversation_pa3($pdo,$owner,$cid,true);$thread=vp3_profile_handoff_thread_pa3($pdo,$owner,$cid,true);$turn=vp3_profile_handoff_row_pa3($pdo,(int)$expected['id'],true);
    if(!$thread||!$turn||$thread['mode']!=='agent'||($conversation['status']??'')!=='open'||$turn['state']!=='agent_running'||(int)$turn['version']!==(int)$expected['version']||(int)$turn['conversation_epoch']!==(int)$thread['epoch']||(int)$turn['conversation_epoch']!==(int)$expected['conversation_epoch']||!hash_equals((string)$turn['execution_lease_token'],(string)$claim['lease_token'])||!empty($turn['answer_message_id']))vp3_profile_handoff_error_pa3();
    vp3_profile_handoff_lease_pa3($pdo,$turn,$claim,true);return $turn;
}
function vp3_profile_handoff_execute_claim_pa3(PDO $pdo,array $user,array $claim,?callable $infer=null): array
{
    if($pdo->inTransaction())throw new RuntimeException('Visitor inference cannot run in a transaction.');
    require_once __DIR__.'/profile-agent-accepted-turn-pa3.php';require_once __DIR__.'/agent-job-engine-v1900.php';
    $owner=(int)($user['id']??0);$q=$pdo->prepare('SELECT * FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND run_id=? AND action_id=?');$q->execute([$owner,(int)($claim['run_id']??0),(int)($claim['action']['id']??0)]);$initial=$q->fetch(PDO::FETCH_ASSOC);if(!$initial)vp3_profile_handoff_error_pa3('HANDOFF_NOT_FOUND',404);
    $turn=null;
    try{
        $turn=vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$owner,$initial,$claim){
            $cid=(int)$initial['conversation_id'];$conversation=vp3_profile_handoff_conversation_pa3($pdo,$owner,$cid,true);$thread=vp3_profile_handoff_thread_pa3($pdo,$owner,$cid,true);$turn=vp3_profile_handoff_row_pa3($pdo,(int)$initial['id'],true);
            if(!$thread||$thread['mode']!=='agent'||($conversation['status']??'')!=='open'||!in_array($turn['state']??null,['agent_ready','agent_running'],true)||(int)$turn['conversation_epoch']!==(int)$thread['epoch']||!empty($turn['answer_message_id']))vp3_profile_handoff_error_pa3();
            $q=$pdo->prepare("SELECT id FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND conversation_id=? AND state IN ('agent_ready','agent_running') ORDER BY visitor_message_id,id LIMIT 1");$q->execute([$owner,$cid]);if((int)$q->fetchColumn()!==(int)$turn['id'])vp3_profile_handoff_error_pa3();
            vp3_profile_handoff_lease_pa3($pdo,$turn,$claim,true);
            if($turn['state']==='agent_running'&&hash_equals((string)$turn['execution_lease_token'],(string)$claim['lease_token']))vp3_profile_handoff_error_pa3();
            $pdo->prepare("UPDATE profile_agent_handoff_turns_pa3 SET state='agent_running',version=version+1,execution_lease_token=?,updated_at=? WHERE id=? AND owner_user_id=?")->execute([(string)$claim['lease_token'],vp3_profile_handoff_date_pa3(),(int)$turn['id'],$owner]);return vp3_profile_handoff_row_pa3($pdo,(int)$turn['id'],true);
        });
        $generated=vp3_profile_agent_accepted_generate_pa3($pdo,$turn,$claim,$infer);
        if(!is_string($generated['answer']??null)||trim($generated['answer'])===''||mb_strlen($generated['answer'])>20000||!is_array($generated['message_context']??null))throw new RuntimeException('Visitor answer is unavailable.');
        $result=vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$owner,$user,$turn,$claim,$generated){
            $current=vp3_profile_handoff_execution_fence_pa3($pdo,$turn,$claim);$ctx=vp3_profile_agent_accepted_recheck_pa3($pdo,$current,$generated,true);
            if(!empty($generated['needs_owner']))profile_agent_needs_owner($pdo,$ctx['profile'],$ctx['agent'],vp3_profile_agent_public_event_session_v1($ctx),$ctx['conversation'],(string)$ctx['visitor_message']['message']);
            $context=$generated['message_context'];$context['profile_handoff']=['contract'=>VP3_PROFILE_HANDOFF_PA3,'turn_id'=>(int)$turn['id'],'visitor_message_id'=>(int)$turn['visitor_message_id']];$json=json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(strlen($json)>131072)throw new RuntimeException('Visitor answer provenance is unavailable.');
            $pdo->prepare("INSERT INTO profile_agent_messages(conversation_id,sender_type,sender_user_id,message,context_json,created_at) VALUES(?,'agent',NULL,?,?,?)")->execute([(int)$turn['conversation_id'],trim($generated['answer']),$json,vp3_profile_handoff_date_pa3()]);$messageId=(int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE profile_agent_conversations SET last_message_at=?,updated_at=? WHERE id=? AND owner_user_id=?')->execute([vp3_profile_handoff_date_pa3(),vp3_profile_handoff_date_pa3(),(int)$turn['conversation_id'],$owner]);
            vp3_profile_agent_accepted_recheck_pa3($pdo,$current,$generated,true);vp3_profile_handoff_execution_fence_pa3($pdo,$turn,$claim);
            $pdo->prepare("UPDATE profile_agent_handoff_turns_pa3 SET state='replied',version=version+1,answer_message_id=?,execution_lease_token='',last_error_class='',updated_at=? WHERE id=? AND owner_user_id=?")->execute([$messageId,vp3_profile_handoff_date_pa3(),(int)$turn['id'],$owner]);
            $receipt=agent_job_record_result_v1900($pdo,$user,(int)$turn['run_id'],(int)$turn['action_id'],(string)$claim['lease_token'],'profile-turn:'.$turn['id'].':reply',true,'Public visitor reply saved.',['turn_id'=>(int)$turn['id'],'conversation_id'=>(int)$turn['conversation_id'],'visitor_message_id'=>(int)$turn['visitor_message_id'],'answer_message_id'=>$messageId],'',false);
            vp3_profile_handoff_schedule_pa3($pdo,$owner,(int)$turn['conversation_id']);$published=vp3_profile_handoff_row_pa3($pdo,(int)$turn['id'],true);
            // Receipt/next-turn SQL is also inside the disclosure boundary.
            vp3_profile_agent_accepted_recheck_pa3($pdo,$published,$generated,true);
            if($published['state']!=='replied'||(int)$published['answer_message_id']!==$messageId||(int)$published['version']!==(int)$turn['version']+1)vp3_profile_handoff_error_pa3();
            return ['ok'=>true,'reason'=>'completed','turn'=>vp3_profile_handoff_projection_pa3($published),'receipt_id'=>(int)$receipt['receipt_id']];
        });
        return ['contract'=>VP3_PROFILE_HANDOFF_PA3]+$result;
    }catch(Throwable $error){
        $fresh=vp3_profile_handoff_row_pa3($pdo,(int)$initial['id']);
        if($error instanceof VP3ProfileHandoffException&&$error->publicCode==='HANDOFF_JOB_CHANGED')return vp3_profile_handoff_block_changed_job_pa3($pdo,$fresh?:$initial);
        if(!$turn||!$fresh||$fresh['state']!=='agent_running'||(int)$fresh['version']!==(int)$turn['version']||!hash_equals((string)$fresh['execution_lease_token'],(string)($claim['lease_token']??'')))return ['ok'=>true,'contract'=>VP3_PROFILE_HANDOFF_PA3,'reason'=>'handoff_changed','turn'=>vp3_profile_handoff_projection_pa3($fresh?:$initial)];
        $authority=$error instanceof VP3ProfileAgentPublicException||($error instanceof VP3ProfileHandoffException&&in_array($error->httpStatus,[403,404],true));$reason=$authority?'authority_revoked':'execution_failed';
        try{
            vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$owner,$user,$turn,$claim,$reason,$authority){
                $current=vp3_profile_handoff_execution_fence_pa3($pdo,$turn,$claim);
                $receipt=agent_job_record_result_v1900($pdo,$user,(int)$turn['run_id'],(int)$turn['action_id'],(string)$claim['lease_token'],'profile-turn:'.$turn['id'].':failure:'.(int)($claim['action']['attempt_count']??1),false,'Public visitor reply was not published.',[],$reason,!$authority);
                $state=in_array($receipt['run']['status']??'',['approved','paused'],true)?'agent_ready':'blocked';$pdo->prepare("UPDATE profile_agent_handoff_turns_pa3 SET state=?,version=version+1,execution_lease_token='',last_error_class=?,updated_at=? WHERE id=? AND owner_user_id=?")->execute([$state,$reason,vp3_profile_handoff_date_pa3(),(int)$turn['id'],$owner]);vp3_profile_handoff_schedule_pa3($pdo,$owner,(int)$turn['conversation_id']);
            });
        }catch(Throwable $ignored){/* A lost lease cannot publish a failure on its replacement. */}
        return ['ok'=>false,'contract'=>VP3_PROFILE_HANDOFF_PA3,'reason'=>$reason,'turn'=>vp3_profile_handoff_projection_pa3(vp3_profile_handoff_row_pa3($pdo,(int)$initial['id'])?:$initial)];
    }
}
/** An already-authorized POST or CLI runner may dispatch exactly this one turn. */
function vp3_profile_handoff_dispatch_pa3(PDO $pdo,int $turnId,?callable $infer=null): array
{
    vp3_profile_handoff_require_pa3($pdo);if($pdo->inTransaction())throw new RuntimeException('Visitor dispatch cannot run in a transaction.');require_once __DIR__.'/agent-worker-cloud-v1910.php';
    $turn=vp3_profile_handoff_row_pa3($pdo,$turnId);if(!$turn)vp3_profile_handoff_error_pa3('HANDOFF_NOT_FOUND',404);$owner=(int)$turn['owner_user_id'];$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$owner]);$user=$q->fetch(PDO::FETCH_ASSOC);if(!$user||empty($user['is_active']))vp3_profile_handoff_error_pa3('HANDOFF_UNAVAILABLE',403);
    try{vp3_profile_handoff_job_pa3($pdo,$turn);}catch(VP3ProfileHandoffException $e){if($e->publicCode==='HANDOFF_JOB_CHANGED')return vp3_profile_handoff_block_changed_job_pa3($pdo,$turn);throw $e;}
    vp3_profile_handoff_tick_pa3($pdo,$owner,(int)$turn['conversation_id']);agent_job_recover_expired_v1900($pdo,$user,1,(int)$turn['run_id']);vp3_profile_handoff_tick_pa3($pdo,$owner,(int)$turn['conversation_id']);$turn=vp3_profile_handoff_row_pa3($pdo,$turnId);
    if($turn['state']!=='agent_ready')return ['ok'=>true,'contract'=>VP3_PROFILE_HANDOFF_PA3,'reason'=>$turn['state']==='replied'?'completed':'waiting','turn'=>vp3_profile_handoff_projection_pa3($turn)];
    $claim=agent_job_claim_run_v1900($pdo,$user,(int)$turn['run_id'],'cloud',agent_worker_cloud_worker_id_v1910($owner),300);
    if(!$claim)return ['ok'=>true,'contract'=>VP3_PROFILE_HANDOFF_PA3,'reason'=>'busy','turn'=>vp3_profile_handoff_projection_pa3(vp3_profile_handoff_row_pa3($pdo,$turnId)??$turn)];
    return vp3_profile_handoff_execute_claim_pa3($pdo,$user,$claim,$infer);
}
function vp3_profile_handoff_runner_pa3(PDO $pdo,int $ownerLimit=50): array
{
    if(!vp3_profile_handoff_schema_ready_pa3($pdo))return ['owners'=>0,'completed'=>0,'waiting'=>0,'failed'=>0];$ownerLimit=max(1,min(250,$ownerLimit));$counts=['owners'=>0,'completed'=>0,'waiting'=>0,'failed'=>0];
    $q=$pdo->query("SELECT DISTINCT owner_user_id FROM profile_agent_handoff_turns_pa3 WHERE state IN ('awaiting_delivery','awaiting_owner','agent_ready','agent_running') ORDER BY owner_user_id LIMIT ".$ownerLimit);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $owner){$counts['owners']++;
        try{vp3_profile_handoff_tick_pa3($pdo,(int)$owner,0,20);$s=$pdo->prepare("SELECT t.id FROM profile_agent_handoff_turns_pa3 t INNER JOIN agent_workflow_runs r ON r.id=t.run_id AND r.owner_user_id=t.owner_user_id WHERE t.owner_user_id=? AND t.state IN ('agent_ready','agent_running') AND ((r.status='approved' AND r.current_action_id IS NULL AND (r.next_attempt_at IS NULL OR r.next_attempt_at<=UTC_TIMESTAMP())) OR (r.status='executing' AND r.lease_expires_at<UTC_TIMESTAMP())) ORDER BY t.visitor_message_id,t.id LIMIT 1");$s->execute([(int)$owner]);$id=(int)$s->fetchColumn();if(!$id){$counts['waiting']++;continue;}$result=vp3_profile_handoff_dispatch_pa3($pdo,$id);if(($result['reason']??'')==='completed')$counts['completed']++;elseif(empty($result['ok']))$counts['failed']++;else $counts['waiting']++;}
        catch(Throwable $e){$counts['failed']++;error_log('Profile handoff runner failed ('.get_class($e).').');}
    }
    return $counts;
}
