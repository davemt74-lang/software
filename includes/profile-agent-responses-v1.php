<?php
declare(strict_types=1);

require_once __DIR__.'/profile-agent-boundary-v390.php';

/** Owner response policy and durable, exact-message requests for owner input. */
const VP3_PROFILE_RESPONSE_V1='vp3.profile-response.v1';

final class VP3ProfileResponseException extends RuntimeException
{
    public function __construct(public readonly string $errorCode,string $message,public readonly int $status=409)
    {parent::__construct($message);}
}

function vp3_profile_responses_ready_v1(PDO $pdo): bool
{
    try{
        $pdo->query('SELECT owner_user_id,mode,revision,created_at_ms,updated_at_ms FROM profile_agent_response_settings_v1 WHERE 1=0');
        $pdo->query('SELECT id,owner_user_id,profile_agent_id,conversation_id,visitor_message_id,reason,status,receipt_message_id,reply_message_id,revision,created_at_ms,updated_at_ms,settled_at_ms FROM profile_agent_owner_requests_v1 WHERE 1=0');
        return true;
    }catch(PDOException){return false;}
}

/** Explicit setup/upgrade only. Request paths never create or alter tables. */
function vp3_profile_responses_install_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Install Profile responses outside transactions.');
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $auto=$mysql?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_agent_response_settings_v1 (
        owner_user_id BIGINT PRIMARY KEY,mode VARCHAR(24) NOT NULL DEFAULT 'automated',
        revision BIGINT NOT NULL DEFAULT 1,created_at_ms BIGINT NOT NULL,updated_at_ms BIGINT NOT NULL
    )".$suffix);
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_agent_owner_requests_v1 (
        id $auto,owner_user_id BIGINT NOT NULL,profile_agent_id BIGINT NOT NULL,
        conversation_id BIGINT NOT NULL,visitor_message_id BIGINT NOT NULL,
        reason VARCHAR(32) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'waiting',
        receipt_message_id BIGINT NULL,reply_message_id BIGINT NULL,revision BIGINT NOT NULL DEFAULT 1,
        created_at_ms BIGINT NOT NULL,updated_at_ms BIGINT NOT NULL,settled_at_ms BIGINT NULL,
        UNIQUE(owner_user_id,visitor_message_id)".
        ($mysql?',INDEX idx_profile_owner_requests_conversation(owner_user_id,conversation_id,status,visitor_message_id)':'').
        ')'.$suffix);
    if(!$mysql)$pdo->exec('CREATE INDEX IF NOT EXISTS idx_profile_owner_requests_conversation ON profile_agent_owner_requests_v1(owner_user_id,conversation_id,status,visitor_message_id)');
}

function vp3_profile_responses_lock_v1(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
}

function vp3_profile_responses_require_v1(PDO $pdo): void
{
    if(!vp3_profile_responses_ready_v1($pdo))throw new VP3ProfileResponseException('PROFILE_RESPONSES_UPGRADE_REQUIRED','Run Database Upgrade to enable Profile response settings and owner requests.',503);
}

/** Savepoints keep a caught failure from leaving partial writes in a caller's transaction. */
function vp3_profile_responses_atomic_v1(PDO $pdo,callable $work): mixed
{
    $owns=!$pdo->inTransaction();$savepoint='profile_responses_'.bin2hex(random_bytes(6));
    if($owns)$pdo->beginTransaction();else $pdo->exec('SAVEPOINT '.$savepoint);
    try{
        $result=$work();
        if($owns)$pdo->commit();else $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
        return $result;
    }catch(Throwable $e){
        if($pdo->inTransaction()){
            if($owns)$pdo->rollBack();else{$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}
        }
        throw $e;
    }
}

/** The owner lock also serializes first saves, before a settings row exists. */
function vp3_profile_responses_owner_lock_v1(PDO $pdo,int $owner): void
{
    if(!$pdo->inTransaction())throw new RuntimeException('Profile response settings require an owner transaction.');
    if($owner<1)throw new VP3ProfileResponseException('PROFILE_RESPONSE_OWNER_UNAVAILABLE','Your account changed. Reload Profile settings.',403);
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->prepare('UPDATE users SET id=id WHERE id=?')->execute([$owner]);
    $q=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.vp3_profile_responses_lock_v1($pdo));$q->execute([$owner]);
    if(!$q->fetchColumn())throw new VP3ProfileResponseException('PROFILE_RESPONSE_OWNER_UNAVAILABLE','Your account changed. Reload Profile settings.',403);
}

function vp3_profile_response_settings_v1(PDO $pdo,int $owner,bool $lock=false): array
{
    $ready=vp3_profile_responses_ready_v1($pdo);
    if(!$ready){
        // A partial/failed upgrade must never turn a saved human-only policy
        // into Automated merely because its request ledger is unavailable.
        $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
            ?"SELECT name FROM sqlite_master WHERE type='table' AND name='profile_agent_response_settings_v1'"
            :"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='profile_agent_response_settings_v1'";
        try{$exists=(bool)$pdo->query($sql)->fetchColumn();}
        catch(PDOException){throw new VP3ProfileResponseException('PROFILE_RESPONSE_SETTINGS_UNAVAILABLE','Profile response settings could not be verified. Please reload.',503);}
        if(!$exists)return ['ready'=>false,'mode'=>'automated','revision'=>0];
    }
    if($lock)vp3_profile_responses_owner_lock_v1($pdo,$owner);
    try{$q=$pdo->prepare('SELECT mode,revision FROM profile_agent_response_settings_v1 WHERE owner_user_id=?'.($lock?vp3_profile_responses_lock_v1($pdo):''));$q->execute([$owner]);$row=$q->fetch(PDO::FETCH_ASSOC);}
    catch(PDOException){throw new VP3ProfileResponseException('PROFILE_RESPONSE_SETTINGS_UNAVAILABLE','Profile response settings could not be verified. Please reload.',503);}
    if(!$row)return ['ready'=>$ready,'mode'=>'automated','revision'=>0];
    if(!in_array($row['mode'],['automated','user_responses'],true)||(int)$row['revision']<1)throw new VP3ProfileResponseException('PROFILE_RESPONSE_SETTINGS_UNAVAILABLE','Profile response settings could not be verified. Please reload.',503);
    return ['ready'=>$ready,'mode'=>$row['mode'],'revision'=>(int)$row['revision']];
}

function vp3_profile_response_mode_v1(PDO $pdo,int $owner,bool $lock=false): string
{
    return vp3_profile_response_settings_v1($pdo,$owner,$lock)['mode'];
}

/** Called within the atomic save of all Profile Agent settings. */
function vp3_profile_response_settings_save_v1(PDO $pdo,int $owner,string $mode,int $expectedRevision): array
{
    if(!$pdo->inTransaction())throw new RuntimeException('Saving Profile responses requires an owner transaction.');
    vp3_profile_responses_require_v1($pdo);
    if(!in_array($mode,['automated','user_responses'],true)||$expectedRevision<0)throw new VP3ProfileResponseException('PROFILE_RESPONSE_SETTINGS_INVALID','Choose Automated or User responses and reload the current settings before saving.',422);
    $saved=vp3_profile_response_settings_v1($pdo,$owner,true);
    if($saved['revision']!==$expectedRevision)throw new VP3ProfileResponseException('PROFILE_RESPONSE_SETTINGS_CHANGED','Profile response settings changed. Reload them before saving.',409);
    $now=(int)floor(microtime(true)*1000);$revision=$expectedRevision+1;
    if($expectedRevision===0){
        $pdo->prepare('INSERT INTO profile_agent_response_settings_v1(owner_user_id,mode,revision,created_at_ms,updated_at_ms) VALUES(?,?,?,?,?)')->execute([$owner,$mode,$revision,$now,$now]);
    }else{
        $q=$pdo->prepare('UPDATE profile_agent_response_settings_v1 SET mode=?,revision=?,updated_at_ms=? WHERE owner_user_id=? AND revision=?');$q->execute([$mode,$revision,$now,$owner,$expectedRevision]);
        if($q->rowCount()!==1)throw new VP3ProfileResponseException('PROFILE_RESPONSE_SETTINGS_CHANGED','Profile response settings changed. Reload them before saving.',409);
    }
    return ['ready'=>true,'mode'=>$mode,'revision'=>$revision];
}

function vp3_profile_owner_request_row_v1(array $row): array
{
    return ['id'=>(int)$row['id'],'conversation_id'=>(int)$row['conversation_id'],
        'visitor_message_id'=>(int)$row['visitor_message_id'],'status'=>(string)$row['status'],'reason'=>(string)$row['reason'],
        'receipt_message_id'=>(int)($row['receipt_message_id']??0),'reply_message_id'=>(int)($row['reply_message_id']??0),'revision'=>(int)$row['revision']];
}

/** No responder/handoff transition here: the canonical caller owns that fence. */
function vp3_profile_owner_request_create_v1(PDO $pdo,array $ctx,array $conversation,int $visitorMessageId,string $reason): array
{
    if(!$pdo->inTransaction())throw new RuntimeException('Creating a Profile owner request requires the conversation transaction.');
    vp3_profile_responses_require_v1($pdo);
    if(!in_array($reason,['user_responses','knowledge_gap','service_unavailable','quota_exhausted'],true))throw new VP3ProfileResponseException('PROFILE_OWNER_REQUEST_INVALID','This message could not be submitted for a response.',422);
    return vp3_profile_responses_atomic_v1($pdo,function()use($pdo,$ctx,$conversation,$visitorMessageId,$reason){
        $owner=(int)($ctx['owner_user_id']??0);$agentId=(int)($ctx['agent_id']??0);$sid=(int)($ctx['session_id']??0);$cid=(int)($conversation['id']??0);$visitor=(int)($ctx['visitor']['id']??0);
        $invalid=static function(): never {throw new VP3ProfileResponseException('PROFILE_OWNER_REQUEST_UNAVAILABLE','This message could not be submitted for a response.',409);};
        if($owner<1||$agentId<1||$sid<1||$cid<1||$visitorMessageId<1||$visitor===$owner
            ||(int)($ctx['profile']['user_id']??0)!==$owner||(int)($ctx['agent']['id']??0)!==$agentId||(int)($ctx['agent']['owner_user_id']??0)!==$owner
            ||(int)($ctx['session']['id']??0)!==$sid||(int)($ctx['session']['owner_user_id']??0)!==$owner
            ||(int)($ctx['conversation_authority']['visitor_user_id']??-1)!==$visitor)$invalid();
        $bound=vp3_profile_agent_public_conversation_v390($pdo,$cid,$owner,$agentId,$sid,true,$ctx['conversation_authority']??null);
        if(!$bound||$bound['status']==='resolved')$invalid();
        foreach(['owner_user_id'=>$owner,'profile_agent_id'=>$agentId,'profile_session_id'=>$sid,'visitor_user_id'=>$visitor] as $key=>$value)if((int)($conversation[$key]??0)!==$value)$invalid();
        $q=$pdo->prepare('SELECT p.*,u.display_name,u.is_active FROM user_profiles p INNER JOIN users u ON u.id=p.user_id WHERE p.user_id=?'.vp3_profile_responses_lock_v1($pdo));$q->execute([$owner]);$profile=$q->fetch(PDO::FETCH_ASSOC);
        $q=$pdo->prepare('SELECT id,owner_user_id,display_name,is_active FROM user_agents WHERE id=? AND owner_user_id=?'.vp3_profile_responses_lock_v1($pdo));$q->execute([$agentId,$owner]);$agent=$q->fetch(PDO::FETCH_ASSOC);
        $q=$pdo->prepare('SELECT * FROM profile_visit_sessions WHERE id=? AND owner_user_id=?'.vp3_profile_responses_lock_v1($pdo));$q->execute([$sid,$owner]);$session=$q->fetch(PDO::FETCH_ASSOC);
        if(!$profile||empty($profile['is_active'])||empty($profile['is_public'])||empty($profile['profile_agent_enabled'])||(int)$profile['profile_agent_id']!==$agentId||!$agent||empty($agent['is_active'])||!$session)$invalid();
        $q=$pdo->prepare("SELECT id,message,sender_user_id FROM profile_agent_messages WHERE id=? AND conversation_id=? AND sender_type='visitor'".vp3_profile_responses_lock_v1($pdo));$q->execute([$visitorMessageId,$cid]);$message=$q->fetch(PDO::FETCH_ASSOC);
        if(!$message||(int)($message['sender_user_id']??0)!==$visitor)$invalid();
        $q=$pdo->prepare('SELECT * FROM profile_agent_owner_requests_v1 WHERE owner_user_id=? AND visitor_message_id=?'.vp3_profile_responses_lock_v1($pdo));$q->execute([$owner,$visitorMessageId]);$existing=$q->fetch(PDO::FETCH_ASSOC);
        if($existing){
            if((int)$existing['conversation_id']!==$cid||(int)$existing['profile_agent_id']!==$agentId||(int)$existing['receipt_message_id']<1)$invalid();
            if($existing['status']==='waiting')return vp3_profile_owner_request_row_v1($existing);
            if($existing['status']!=='delegated')$invalid();
        }
        $now=(int)floor(microtime(true)*1000);$revision=$existing?(int)$existing['revision']+1:1;
        if($existing){
            // Explicit delegation may still find an information gap. Retain
            // the exact request identity and start a new durable waiting cycle.
            $id=(int)$existing['id'];$q=$pdo->prepare("UPDATE profile_agent_owner_requests_v1 SET status='waiting',reason=?,receipt_message_id=NULL,reply_message_id=NULL,revision=?,updated_at_ms=?,settled_at_ms=NULL WHERE id=? AND owner_user_id=? AND status='delegated' AND revision=?");
            $q->execute([$reason,$revision,$now,$id,$owner,(int)$existing['revision']]);if($q->rowCount()!==1)$invalid();
        }else{
            $pdo->prepare("INSERT INTO profile_agent_owner_requests_v1(owner_user_id,profile_agent_id,conversation_id,visitor_message_id,reason,status,revision,created_at_ms,updated_at_ms) VALUES(?,?,?,?,?,'waiting',1,?,?)")
                ->execute([$owner,$agentId,$cid,$visitorMessageId,$reason,$now,$now]);$id=(int)$pdo->lastInsertId();
        }
        // 'system' is reserved for the hidden authority binding. A receipt has
        // its own stored role and is projected as a system notice by read APIs.
        $name=mb_strimwidth(trim((string)preg_replace('/[\x00-\x1f\x7f]+/u',' ',(string)$profile['display_name'])),0,120,'…');
        if($name==='')$name='the profile owner';
        $receipt='Submitted to '.$name.'. Waiting for a response.';
        // Keep the submission reason and application UTC clock in each durable
        // receipt. Reopening the ledger may change its current reason; reports
        // must not relabel an earlier submission using today's value.
        $context=json_encode(['contract'=>VP3_PROFILE_RESPONSE_V1,'request_id'=>$id,'visitor_message_id'=>$visitorMessageId,'revision'=>$revision,
            'reason'=>$reason,'submitted_at_ms'=>$now],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $pdo->prepare("INSERT INTO profile_agent_messages(conversation_id,sender_type,sender_user_id,message,context_json) VALUES(?,'receipt',NULL,?,?)")->execute([$cid,$receipt,$context]);$receiptId=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE profile_agent_owner_requests_v1 SET receipt_message_id=? WHERE id=? AND owner_user_id=?')->execute([$receiptId,$id,$owner]);
        // CRM sessions can retain a previously signed-in visitor. The bound
        // conversation and exact visitor message determine this event's actor.
        if($visitor<1||(int)($session['visitor_user_id']??0)!==$visitor)$session['identity_disclosed']=0;
        $session['visitor_user_id']=$visitor?:null;
        profile_agent_needs_owner($pdo,$profile,$agent,$session,$bound,(string)$message['message'],$visitorMessageId);
        if($existing){
            $q=$pdo->prepare("SELECT id FROM profile_events WHERE owner_user_id=? AND dedupe_key=? AND event_type='needs_owner'");$q->execute([$owner,hash('sha256','needs_owner|'.$owner.'|message|'.$visitorMessageId)]);$eventId=(int)$q->fetchColumn();
            $versioned=function_exists('vp3_profile_attention_has_column_pa4b')&&vp3_profile_attention_has_column_pa4b($pdo,'agent_attention_items','wake_generation');
            $extra=$versioned?',state_revision=state_revision+1,wake_generation=wake_generation+1,state_changed_at_ms=?':'';
            $pdo->prepare("UPDATE agent_attention_items SET status='pending',snoozed_until=NULL".$extra.' WHERE owner_user_id=? AND source_event_id=? AND source_conversation_id=?')
                ->execute($versioned?[$now,$owner,$eventId,$cid]:[$owner,$eventId,$cid]);
            $readAt=function_exists('vp3_profile_attention_has_column_pa4b')&&vp3_profile_attention_has_column_pa4b($pdo,'notifications','read_at');
            $pdo->prepare('UPDATE notifications SET is_read=0'.($readAt?',read_at=NULL':'')." WHERE user_id=? AND source_type='profile_event' AND source_id=?")->execute([$owner,$eventId]);
        }
        return vp3_profile_owner_request_row_v1(['id'=>$id,'conversation_id'=>$cid,'visitor_message_id'=>$visitorMessageId,'status'=>'waiting','reason'=>$reason,'receipt_message_id'=>$receiptId,'reply_message_id'=>0,'revision'=>$revision]);
    });
}

function vp3_profile_owner_request_pending_v1(PDO $pdo,int $owner,int $cid): bool
{
    if($owner<1||$cid<1||!vp3_profile_responses_ready_v1($pdo))return false;
    $q=$pdo->prepare("SELECT r.id FROM profile_agent_owner_requests_v1 r INNER JOIN profile_agent_conversations c ON c.id=r.conversation_id AND c.owner_user_id=r.owner_user_id AND c.profile_agent_id=r.profile_agent_id WHERE r.owner_user_id=? AND r.conversation_id=? AND r.status='waiting' LIMIT 1");$q->execute([$owner,$cid]);return(bool)$q->fetchColumn();
}

function vp3_profile_response_projection_v1(PDO $pdo,int $owner,int $cid=0): array
{
    $settings=vp3_profile_response_settings_v1($pdo,$owner);$request=null;
    if($settings['ready']&&$owner>0&&$cid>0){
        // An earlier unanswered request remains visible until actually settled.
        $q=$pdo->prepare("SELECT r.* FROM profile_agent_owner_requests_v1 r INNER JOIN profile_agent_conversations c ON c.id=r.conversation_id AND c.owner_user_id=r.owner_user_id AND c.profile_agent_id=r.profile_agent_id WHERE r.owner_user_id=? AND r.conversation_id=? ORDER BY CASE WHEN r.status='waiting' THEN 0 ELSE 1 END,r.visitor_message_id DESC,r.id DESC LIMIT 1");$q->execute([$owner,$cid]);$row=$q->fetch(PDO::FETCH_ASSOC);if($row)$request=vp3_profile_owner_request_row_v1($row);
    }
    return ['contract'=>VP3_PROFILE_RESPONSE_V1,'mode'=>$settings['mode'],'ready'=>$settings['ready'],'request'=>$request];
}

/** One bounded request read supplies the owner's Inbox list without per-row polling queries. */
function vp3_profile_response_projections_v1(PDO $pdo,int $owner,array $conversationIds): array
{
    $ids=array_slice(array_values(array_unique(array_filter(array_map('intval',$conversationIds),static fn(int $id): bool=>$id>0))),0,100);
    if(!$ids)return [];
    $settings=vp3_profile_response_settings_v1($pdo,$owner);$base=['contract'=>VP3_PROFILE_RESPONSE_V1,'mode'=>$settings['mode'],'ready'=>$settings['ready'],'request'=>null];$out=[];
    foreach($ids as $id)$out[$id]=$base;
    if(!$settings['ready'])return $out;
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare("SELECT r.* FROM profile_agent_owner_requests_v1 r
        INNER JOIN profile_agent_conversations c ON c.id=r.conversation_id AND c.owner_user_id=r.owner_user_id AND c.profile_agent_id=r.profile_agent_id
        WHERE r.owner_user_id=? AND r.conversation_id IN ($marks)
          AND NOT EXISTS (SELECT 1 FROM profile_agent_owner_requests_v1 newer
            WHERE newer.owner_user_id=r.owner_user_id AND newer.conversation_id=r.conversation_id
              AND ((newer.status='waiting' AND r.status<>'waiting')
                OR ((newer.status='waiting')=(r.status='waiting') AND newer.visitor_message_id>r.visitor_message_id)))");
    $q->execute([$owner,...$ids]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$out[(int)$row['conversation_id']]['request']=vp3_profile_owner_request_row_v1($row);
    return $out;
}

/** Only the canonical owner reply/resolve/delegate transaction settles requests. */
function vp3_profile_owner_requests_settle_v1(PDO $pdo,int $owner,int $cid,string $status,int $throughMessageId=0,int $replyMessageId=0): void
{
    // Existing deployments must retain their established owner reply path.
    if(!vp3_profile_responses_ready_v1($pdo))return;
    if(!$pdo->inTransaction())throw new RuntimeException('Settling Profile owner requests requires the conversation transaction.');
    if(!in_array($status,['answered','resolved','delegated'],true)||$throughMessageId<0||$replyMessageId<0)throw new VP3ProfileResponseException('PROFILE_OWNER_REQUEST_INVALID','The owner request could not be updated.',422);
    $q=$pdo->prepare('SELECT id,visitor_user_id FROM profile_agent_conversations WHERE id=? AND owner_user_id=?'.vp3_profile_responses_lock_v1($pdo));$q->execute([$cid,$owner]);$conversation=$q->fetch(PDO::FETCH_ASSOC);
    if(!$conversation)throw new VP3ProfileResponseException('PROFILE_OWNER_REQUEST_UNAVAILABLE','The owner request could not be updated.',409);
    if($throughMessageId>0){
        $q=$pdo->prepare("SELECT sender_user_id FROM profile_agent_messages WHERE id=? AND conversation_id=? AND sender_type='visitor'");$q->execute([$throughMessageId,$cid]);$through=$q->fetch(PDO::FETCH_ASSOC);
        if(!$through||(int)($through['sender_user_id']??0)!==(int)($conversation['visitor_user_id']??0))throw new VP3ProfileResponseException('PROFILE_OWNER_REQUEST_UNAVAILABLE','The owner request could not be updated.',409);
    }
    if($status==='answered'){
        $q=$pdo->prepare("SELECT id FROM profile_agent_messages WHERE id=? AND conversation_id=? AND sender_type='owner' AND sender_user_id=?");$q->execute([$replyMessageId,$cid,$owner]);
        if(!$q->fetchColumn()||($throughMessageId>0&&$throughMessageId>=$replyMessageId))throw new VP3ProfileResponseException('PROFILE_OWNER_REQUEST_UNAVAILABLE','The owner reply could not be verified.',409);
        if($throughMessageId===0)$throughMessageId=$replyMessageId-1;
    }elseif($replyMessageId!==0)throw new VP3ProfileResponseException('PROFILE_OWNER_REQUEST_INVALID','The owner request could not be updated.',422);
    $now=(int)floor(microtime(true)*1000);$values=[$status,$replyMessageId?:null,$now,$now,$owner,$cid];
    $through=$throughMessageId>0?' AND visitor_message_id<=?':'';if($through!=='')$values[]=$throughMessageId;
    $pdo->prepare("UPDATE profile_agent_owner_requests_v1 SET status=?,reply_message_id=?,revision=revision+1,updated_at_ms=?,settled_at_ms=? WHERE owner_user_id=? AND conversation_id=? AND status='waiting'".$through)->execute($values);
}
