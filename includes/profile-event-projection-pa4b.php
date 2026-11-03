<?php
declare(strict_types=1);

/** Local projections of saved Profile evidence. No provider or business-action execution. */
const VP3_PROFILE_PROJECTION_PA4B='vp3.profile-projection.v1';

final class VP3ProfileProjectionException extends RuntimeException
{
    public function __construct(public readonly string $reason){parent::__construct('Profile projection is unavailable.',409);}
}
function vp3_profile_projection_now_pa4b(): int{return function_exists('vp3_profile_handoff_now_pa3')?vp3_profile_handoff_now_pa3():(int)floor(microtime(true)*1000);}
function vp3_profile_projection_lock_pa4b(PDO $pdo): string{return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
function vp3_profile_projection_atomic_pa4b(PDO $pdo,callable $work): mixed
{
    $own=!$pdo->inTransaction();$savepoint='profile_projection_'.bin2hex(random_bytes(5));
    if($own&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    if($own)$pdo->beginTransaction();else $pdo->exec('SAVEPOINT '.$savepoint);
    try{$result=$work();if($own)$pdo->commit();else $pdo->exec('RELEASE SAVEPOINT '.$savepoint);return $result;}
    catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();elseif(!$own&&$pdo->inTransaction()){$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}throw $e;}
}
function vp3_profile_projection_schema_ready_pa4b(PDO $pdo): bool
{
    try{$pdo->query('SELECT id,owner_user_id,profile_event_id,destination_kind,chat_conversation_id,chat_agent_id,chat_message_id,source_hash,source_json,state,attempt_count,next_attempt_at_ms,result_id,result_hash,last_reason,created_at_ms,updated_at_ms,completed_at_ms FROM profile_event_projections_pa4b WHERE 1=0');return true;}catch(Throwable $e){return false;}
}
function vp3_profile_projection_ensure_schema_pa4b(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Profile projection installation requires its own transaction boundary.');
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$auto=$mysql?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_event_projections_pa4b(id $auto,owner_user_id BIGINT NOT NULL,profile_event_id BIGINT NOT NULL,destination_kind VARCHAR(20) NOT NULL,chat_conversation_id BIGINT NOT NULL DEFAULT 0,chat_agent_id BIGINT NOT NULL DEFAULT 0,chat_message_id BIGINT NOT NULL DEFAULT 0,source_hash CHAR(64) NOT NULL,source_json TEXT NOT NULL,state VARCHAR(24) NOT NULL DEFAULT 'pending',attempt_count INTEGER NOT NULL DEFAULT 0,next_attempt_at_ms BIGINT NOT NULL DEFAULT 0,result_id BIGINT NULL,result_hash CHAR(64) NOT NULL DEFAULT '',last_reason VARCHAR(48) NOT NULL DEFAULT '',created_at_ms BIGINT NOT NULL,updated_at_ms BIGINT NOT NULL,completed_at_ms BIGINT NULL,UNIQUE(owner_user_id,profile_event_id,destination_kind,chat_conversation_id,chat_agent_id,chat_message_id))".$tail);
    $name='idx_profile_projection_due_pa4b';$exists=$mysql?$pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?'):$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?");$exists->execute(['profile_event_projections_pa4b',$name]);
    if(!$exists->fetchColumn())$pdo->exec('CREATE INDEX '.$name.' ON profile_event_projections_pa4b(state,next_attempt_at_ms,id)');
}
function vp3_profile_projection_dependencies_pa4b(): void
{
    if(!function_exists('vp3_profile_event_source_pa4b'))require_once __DIR__.'/profile-attention-pa4b.php';
}
function vp3_profile_projection_json_pa4b(array $value): string{return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
function vp3_profile_projection_canonical_pa4b(mixed $value): mixed
{
    if(!is_array($value))return $value;if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as $key=>$item)$value[$key]=vp3_profile_projection_canonical_pa4b($item);return $value;
}
function vp3_profile_projection_hash_pa4b(array $value): string{return hash('sha256',vp3_profile_projection_json_pa4b(vp3_profile_projection_canonical_pa4b($value)));}
/** Deliberately excludes statement text, display labels, credentials and private dependencies. */
function vp3_profile_projection_references_pa4b(array $source): array
{
    if(($source['contract']??null)!=='vp3.profile-source.v1'||!in_array($source['status']??null,['verified','historical_unverified','unavailable'],true))throw new VP3ProfileProjectionException('source_unverified');
    $out=['contract'=>'vp3.profile-source.v1'];
    foreach(['owner_user_id','profile_event_id','profile_agent_id','profile_session_id','public_conversation_id','public_message_id'] as $key){$value=$source[$key]??null;if(!is_int($value)||$value<0||($value===0&&in_array($key,['owner_user_id','profile_event_id'],true)))throw new VP3ProfileProjectionException('source_unverified');$out[$key]=$value;}
    foreach(['event_type','occurred_at','sender_type','statement_sha256','source_hash'] as $key){if(!is_string($source[$key]??null))throw new VP3ProfileProjectionException('source_unverified');$out[$key]=$source[$key];}
    if(!preg_match('/^[a-z][a-z0-9_]{0,49}$/D',$out['event_type'])||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$out['occurred_at'])||!in_array($out['sender_type'],['visitor','owner','agent','system'],true)||!preg_match('/^[a-f0-9]{64}$/D',$out['source_hash'])||($out['statement_sha256']!==''&&!preg_match('/^[a-f0-9]{64}$/D',$out['statement_sha256'])))throw new VP3ProfileProjectionException('source_unverified');
    $sender=$source['sender_user_id']??null;if($sender!==null&&(!is_int($sender)||$sender<0))throw new VP3ProfileProjectionException('source_unverified');$out['sender_user_id']=$sender;
    return $out;
}
function vp3_profile_projection_user_pa4b(PDO $pdo,int $owner): array
{
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$owner]);$user=$q->fetch(PDO::FETCH_ASSOC);if(!$user||empty($user['is_active']))throw new VP3ProfileProjectionException('permission_denied');return $user;
}
function vp3_profile_projection_source_pa4b(PDO $pdo,int $owner,int $event,array $context=[],bool $lock=false): array
{
    vp3_profile_projection_dependencies_pa4b();$source=vp3_profile_event_source_pa4b($pdo,vp3_profile_projection_user_pa4b($pdo,$owner),$event,$context,$lock);$refs=vp3_profile_projection_references_pa4b($source);
    if($refs['owner_user_id']!==$owner||$refs['profile_event_id']!==$event)throw new VP3ProfileProjectionException('source_changed');return $source;
}
function vp3_profile_projection_row_pa4b(PDO $pdo,int $id,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM profile_event_projections_pa4b WHERE id=?'.($lock?vp3_profile_projection_lock_pa4b($pdo):''));$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function vp3_profile_projection_insert_pa4b(PDO $pdo,array $source,string $destination,array $target=[],string $blockedReason=''): int
{
    if(!$pdo->inTransaction())throw new RuntimeException('Projection intent requires the source transaction.');
    $refs=vp3_profile_projection_references_pa4b($source);$scope=[(int)($target['chat_conversation_id']??0),(int)($target['chat_agent_id']??0),(int)($target['chat_message_id']??0)];
    if(!in_array($destination,['event_inbox','brain_archive'],true)||($destination==='event_inbox'&&$scope!==[0,0,0])||($destination==='brain_archive'&&($scope[0]<1||$scope[1]<0||$scope[2]<1)))throw new VP3ProfileProjectionException('destination_changed');
    $key=[$refs['owner_user_id'],$refs['profile_event_id'],$destination,...$scope];$q=$pdo->prepare('SELECT * FROM profile_event_projections_pa4b WHERE owner_user_id=? AND profile_event_id=? AND destination_kind=? AND chat_conversation_id=? AND chat_agent_id=? AND chat_message_id=?'.vp3_profile_projection_lock_pa4b($pdo));$q->execute($key);$prior=$q->fetch(PDO::FETCH_ASSOC);$json=vp3_profile_projection_json_pa4b($refs);
    if($prior){if(!hash_equals((string)$prior['source_hash'],$refs['source_hash'])||(string)$prior['source_json']!==$json)throw new VP3ProfileProjectionException('source_changed');return(int)$prior['id'];}
    $now=vp3_profile_projection_now_pa4b();$verified=$source['status']==='verified'&&$blockedReason==='';
    try{$pdo->prepare('INSERT INTO profile_event_projections_pa4b(owner_user_id,profile_event_id,destination_kind,chat_conversation_id,chat_agent_id,chat_message_id,source_hash,source_json,state,last_reason,created_at_ms,updated_at_ms) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([...$key,$refs['source_hash'],$json,$verified?'pending':'blocked',$verified?'':($blockedReason?:'source_unverified'),$now,$now]);return(int)$pdo->lastInsertId();}
    catch(PDOException $e){if((string)$e->getCode()!=='23000')throw $e;$q->execute($key);$prior=$q->fetch(PDO::FETCH_ASSOC);if(!$prior||!hash_equals((string)$prior['source_hash'],$refs['source_hash'])||(string)$prior['source_json']!==$json)throw new VP3ProfileProjectionException('source_changed');return(int)$prior['id'];}
}
function vp3_profile_projection_oblige_event_pa4b(PDO $pdo,int $ownerId,int $eventId): int
{
    if(!vp3_profile_projection_schema_ready_pa4b($pdo))return 0;
    return vp3_profile_projection_insert_pa4b($pdo,vp3_profile_projection_source_pa4b($pdo,$ownerId,$eventId), 'event_inbox');
}
/** Validate a canonical neutral marker and its stored delivery, never a caller-supplied quote. */
function vp3_profile_projection_marker_pa4b(PDO $pdo,array $source,array $destination,bool $lock=false,bool $authorize=true): array
{
    $owner=(int)$source['owner_user_id'];$cid=(int)($destination['chat_conversation_id']??0);$aid=(int)($destination['chat_agent_id']??0);$mid=(int)($destination['chat_message_id']??0);
    if($authorize)vp3_profile_attention_owner_pa4b($pdo,vp3_profile_projection_user_pa4b($pdo,$owner),['conversation_id'=>$cid,'agent_id'=>$aid]);
    if($cid<1||$aid<0||$mid<1)throw new VP3ProfileProjectionException('destination_changed');
    $q=$pdo->prepare('SELECT m.id,m.conversation_id,m.user_id,m.role,m.message,m.context_json,m.created_at,c.user_id AS owner_user_id,c.user_agent_id FROM chat_messages m INNER JOIN chat_conversations c ON c.id=m.conversation_id WHERE m.id=? AND m.conversation_id=?'.($lock?vp3_profile_projection_lock_pa4b($pdo):''));$q->execute([$mid,$cid]);$message=$q->fetch(PDO::FETCH_ASSOC);
    if(!$message||(int)$message['owner_user_id']!==$owner||(int)($message['user_agent_id']??0)!==$aid||$message['role']!=='assistant'||$message['user_id']!==null||strlen((string)$message['context_json'])>65536)throw new VP3ProfileProjectionException('destination_changed');
    $context=json_decode((string)$message['context_json'],true,32);if(!is_array($context))throw new VP3ProfileProjectionException('destination_changed');$matches=0;
    if(is_array($m=$context['profile_attention']??null)&&($m['contract']??null)==='vp3.profile-attention.v1'&&$message['message']==='Profile attention update.'){
        $q=$pdo->prepare('SELECT * FROM profile_attention_deliveries_pa4b WHERE id=? AND owner_user_id=? AND profile_event_id=? AND chat_conversation_id=? AND chat_agent_id=? AND chat_message_id=?');$q->execute([(int)($m['delivery_id']??0),$owner,(int)$source['profile_event_id'],$cid,$aid,$mid]);$d=$q->fetch(PDO::FETCH_ASSOC);
        if($d&&(int)($m['attention_id']??0)===(int)$d['attention_id']&&(int)($m['notification_id']??0)===(int)$d['notification_id']&&(int)($m['profile_event_id']??0)===(int)$source['profile_event_id']&&(int)($m['wake_generation']??0)===(int)$d['wake_generation']&&(int)($m['public_conversation_id']??0)===(int)$source['public_conversation_id']&&hash_equals((string)$d['source_hash'],(string)$source['source_hash'])&&hash_equals((string)($m['source_hash']??''),(string)$source['source_hash']))$matches++;
    }
    if(is_array($m=$context['profile_handoff']??null)&&($m['contract']??null)==='vp3.profile-handoff.v1'&&$message['message']==='Profile visitor conversation.'){
        $q=$pdo->prepare('SELECT t.id,t.conversation_id,t.visitor_message_id,t.answer_message_id,t.last_owner_message_id FROM profile_agent_handoff_deliveries_pa3 d INNER JOIN profile_agent_handoff_turns_pa3 t ON t.id=d.turn_id AND t.owner_user_id=d.owner_user_id WHERE d.owner_user_id=? AND d.turn_id=? AND d.chat_conversation_id=? AND d.chat_agent_id=? AND d.chat_message_id=? LIMIT 1');$q->execute([$owner,(int)($m['turn_id']??0),$cid,$aid,$mid]);$d=$q->fetch(PDO::FETCH_ASSOC);$bound=false;
        if($d&&$source['event_type']==='message_received')$bound=(int)$d['visitor_message_id']===(int)$source['public_message_id'];
        elseif($d&&$source['event_type']==='message_sent'){
            $bound=in_array((int)$source['public_message_id'],[(int)$d['answer_message_id'],(int)$d['last_owner_message_id']],true);
            if(!$bound){$q=$pdo->prepare("SELECT 1 FROM profile_agent_handoff_operations_pa3 WHERE owner_user_id=? AND turn_id=? AND operation='reply' AND message_id=? LIMIT 1");$q->execute([$owner,(int)$d['id'],(int)$source['public_message_id']]);$bound=(bool)$q->fetchColumn();}
        }
        if($d&&$bound&&(int)$d['conversation_id']===(int)$source['public_conversation_id']&&(int)($m['conversation_id']??0)===(int)$source['public_conversation_id'])$matches++;
    }
    if(is_array($m=$context['profile_speech']??null)&&($m['contract']??null)==='vp3.profile-speech.v1'&&in_array($message['message'],['Profile visitor announcement.','Profile visitor message.'],true)){
        $q=$pdo->prepare('SELECT * FROM profile_speech_items_pa4a WHERE id=? AND owner_user_id=? AND chat_conversation_id=? AND chat_agent_id=? AND chat_message_id=?');$q->execute([(int)($m['item_id']??0),$owner,$cid,$aid,$mid]);$d=$q->fetch(PDO::FETCH_ASSOC);
        if($d&&($m['kind']??null)===$d['kind']&&(int)($m['public_conversation_id']??0)===(int)$d['public_conversation_id']&&(int)$d['profile_agent_id']===(int)$source['profile_agent_id']&&(int)$d['profile_session_id']===(int)$source['profile_session_id']&&(($d['kind']==='arrival'&&$message['message']==='Profile visitor announcement.'&&(int)$d['source_event_id']===(int)$source['profile_event_id']&&(int)$source['public_message_id']===0)||($d['kind']==='visitor_message'&&$message['message']==='Profile visitor message.'&&$source['event_type']==='message_received'&&(int)$d['public_conversation_id']===(int)$source['public_conversation_id']&&(int)$d['visitor_message_id']===(int)$source['public_message_id']))){
            if(!function_exists('vp3_profile_speech_source_pa4a'))require_once __DIR__.'/profile-visitor-speech-pa4a.php';
            try{vp3_profile_speech_source_pa4a($pdo,$d,$lock);$matches++;}catch(VP3ProfileSpeechException $e){throw new VP3ProfileProjectionException('source_unverified');}
        }
    }
    if($matches!==1)throw new VP3ProfileProjectionException('destination_changed');return $message;
}
function vp3_profile_projection_oblige_chat_pa4b(PDO $pdo,array $user,array $source,array $destination): int
{
    if(!vp3_profile_projection_schema_ready_pa4b($pdo))return 0;
    $refs=vp3_profile_projection_references_pa4b($source);if((int)($user['id']??0)!==$refs['owner_user_id'])throw new VP3ProfileProjectionException('permission_denied');
    $fresh=vp3_profile_projection_source_pa4b($pdo,$refs['owner_user_id'],$refs['profile_event_id'],['conversation_id'=>(int)($destination['chat_conversation_id']??0),'agent_id'=>(int)($destination['chat_agent_id']??0)]);
    if(!hash_equals($refs['source_hash'],$fresh['source_hash']))throw new VP3ProfileProjectionException('source_changed');vp3_profile_projection_marker_pa4b($pdo,$fresh,$destination);
    return vp3_profile_projection_insert_pa4b($pdo,$fresh,'brain_archive',$destination);
}
function vp3_profile_projection_message_event_pa4b(PDO $pdo,int $owner,int $messageId): int
{
    $keys=[hash('sha256','profile-message|'.$owner.'|'.$messageId),hash('sha256','profile-message-sent|'.$owner.'|'.$messageId)];
    $q=$pdo->prepare('SELECT id FROM profile_events WHERE owner_user_id=? AND dedupe_key IN (?,?)');$q->execute([$owner,...$keys]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);if(count($ids)>1)throw new VP3ProfileProjectionException('source_changed');return(int)($ids[0]??0);
}
/** Attach an already saved source to a proven marker; never choose a latest Chat. */
function vp3_profile_projection_oblige_message_marker_pa4b(PDO $pdo,int $owner,int $messageId,array $destination): int
{
    if(!vp3_profile_projection_schema_ready_pa4b($pdo))return 0;$event=vp3_profile_projection_message_event_pa4b($pdo,$owner,$messageId);if($event<1)return 0;
    $source=vp3_profile_projection_source_pa4b($pdo,$owner,$event);return vp3_profile_projection_capture_marker_pa4b($pdo,$source,$destination);
}
/** A committed public action may record a destination that has since lost access.
 * Actual archive disclosure always rechecks current private Chat/Agent/Brain access.
 */
function vp3_profile_projection_capture_marker_pa4b(PDO $pdo,array $source,array $destination): int
{
    $reason='';try{vp3_profile_projection_marker_pa4b($pdo,$source,$destination,false,false);}catch(VP3ProfileProjectionException $e){$reason=$e->reason;}
    return vp3_profile_projection_insert_pa4b($pdo,$source,'brain_archive',$destination,$reason);
}
function vp3_profile_projection_oblige_turn_pa4b(PDO $pdo,int $owner,int $turnId,int $messageId): void
{
    if(!vp3_profile_projection_schema_ready_pa4b($pdo))return;
    $q=$pdo->prepare('SELECT DISTINCT chat_conversation_id,chat_agent_id,chat_message_id FROM profile_agent_handoff_deliveries_pa3 WHERE owner_user_id=? AND turn_id=? ORDER BY chat_message_id LIMIT 65');$q->execute([$owner,$turnId]);$destinations=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($destinations)>64)throw new VP3ProfileProjectionException('destination_changed');
    foreach($destinations as $destination)vp3_profile_projection_oblige_message_marker_pa4b($pdo,$owner,$messageId,$destination);
}
function vp3_profile_projection_event_payload_pa4b(array $source,bool $typed=true): array
{
    $event=$source['event'];$meta=json_decode((string)($event['metadata_json']??'{}'),true);if(!is_array($meta))$meta=[];
    $refs=[['type'=>'profile','id'=>(string)$source['owner_user_id'],'scope'=>'profile_agent']];
    if($source['profile_session_id']>0)$refs[]=['type'=>'profile_visit','id'=>(string)$source['profile_session_id'],'scope'=>'profile_agent'];
    if($source['public_conversation_id']>0)$refs[]=['type'=>'profile_conversation','id'=>(string)$source['public_conversation_id'],'scope'=>'profile_agent'];
    // The exact event resolves the saved actor and statement. Broad conversation
    // references can otherwise introduce a different, newer public statement.
    if($typed)$refs=[['type'=>'profile_event','id'=>(string)$source['profile_event_id'],'scope'=>'profile_agent']];
    $payload=['object_refs'=>$refs,'domain_source'=>'profile_agent','record_only'=>true,'brain_promotion_deferred'=>true,'profile_event_id'=>$source['profile_event_id'],'profile_agent_id'=>$source['profile_agent_id'],'priority'=>max(0,min(100,(int)($event['priority']??0))),'conversion_kind'=>in_array($meta['conversion_kind']??null,['booking','product'],true)?$meta['conversion_kind']:'','outcome_status'=>mb_strimwidth(trim((string)($meta['outcome_status']??'')),0,40,''),'value_cents'=>max(0,(int)($meta['value_cents']??0))];
    if($typed)$payload['public_source']=vp3_profile_projection_references_pa4b($source);return $payload;
}
function vp3_profile_projection_event_type_pa4b(array $source): string
{
    $type=$source['event_type'];$meta=json_decode((string)($source['event']['metadata_json']??'{}'),true);
    $map=['profile_view'=>!empty($meta['returning'])?'profile.return_visitor':'profile.visited','return_visitor'=>'profile.return_visitor','conversation_started'=>'profile.conversation_started','message_received'=>'profile.message_received','message_sent'=>'profile.message_sent','needs_owner'=>'profile.needs_owner','booking_intent'=>'profile.booking_intent','product_intent'=>'profile.product_intent','booking_converted'=>'profile.booking_converted','product_converted'=>'profile.product_converted'];
    return $map[$type]??throw new VP3ProfileProjectionException('source_unverified');
}
function vp3_profile_projection_inbox_pa4b(PDO $pdo,array $source): array
{
    if(!function_exists('agent_event_ingest_v1920'))require_once __DIR__.'/agent-event-infrastructure-v1920.php';
    if(!agent_event_schema_ready_v1920($pdo))throw new RuntimeException('Projection destination is not ready.');
    $owner=$source['owner_user_id'];$external='profile-event:'.$source['profile_event_id'];$type=vp3_profile_projection_event_type_pa4b($source);$payload=agent_event_sanitize_payload_v1920(vp3_profile_projection_event_payload_pa4b($source));$hash=agent_event_dedupe_hash_v1920($owner,'profile_agent',$type,$external,$payload);
    $q=$pdo->prepare('SELECT * FROM agent_event_inbox WHERE owner_user_id=? AND external_event_id=?'.vp3_profile_projection_lock_pa4b($pdo));$q->execute([$owner,$external]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)>1)throw new VP3ProfileProjectionException('dedupe_conflict');
    if(!$rows){agent_event_ingest_v1920($pdo,$owner,'profile_agent',$type,$payload,['verification_status'=>'trusted','external_event_id'=>$external,'occurred_at'=>$source['occurred_at']]);$q->execute([$owner,$external]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);}
    if(count($rows)!==1)throw new VP3ProfileProjectionException('dedupe_conflict');$row=$rows[0];$saved=json_decode((string)$row['payload_json'],true,32);
    if((string)$row['source']!=='profile_agent'||(string)$row['event_type']!==$type||(string)$row['external_event_id']!==$external||!hash_equals((string)$row['dedupe_hash'],$hash)||$row['verification_status']!=='trusted'||!in_array($row['processing_status'],['accepted','processed'],true)||!empty($row['linked_run_id'])||!is_array($saved))throw new VP3ProfileProjectionException('dedupe_conflict');
    if(vp3_profile_projection_hash_pa4b($saved)!==vp3_profile_projection_hash_pa4b($payload)){
        // A precise old record-only projection may acquire typed references.
        // Conflicting content is never overwritten merely because its ID matches.
        $legacy=agent_event_sanitize_payload_v1920(vp3_profile_projection_event_payload_pa4b($source,false));
        if(vp3_profile_projection_hash_pa4b($saved)!==vp3_profile_projection_hash_pa4b($legacy))throw new VP3ProfileProjectionException('dedupe_conflict');
        $pdo->prepare('UPDATE agent_event_inbox SET payload_json=? WHERE id=? AND owner_user_id=?')->execute([agent_event_json_v1920($payload),(int)$row['id'],$owner]);
    }
    $pdo->prepare("UPDATE agent_event_inbox SET processing_status='processed',processed_at=COALESCE(processed_at,UTC_TIMESTAMP()),last_error_code='',last_error_message='' WHERE id=? AND owner_user_id=?")->execute([(int)$row['id'],$owner]);
    $q->execute([$owner,$external]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$saved=count($rows)===1?json_decode((string)$rows[0]['payload_json'],true,32):null;
    if(!is_array($saved)||vp3_profile_projection_hash_pa4b($saved)!==vp3_profile_projection_hash_pa4b($payload)||$rows[0]['processing_status']!=='processed'||(int)$rows[0]['id']!==(int)$row['id']||$rows[0]['source']!=='profile_agent'||$rows[0]['event_type']!==$type||$rows[0]['external_event_id']!==$external||!hash_equals((string)$rows[0]['dedupe_hash'],$hash)||$rows[0]['verification_status']!=='trusted'||!empty($rows[0]['linked_run_id']))throw new VP3ProfileProjectionException('dedupe_conflict');
    return ['result_id'=>(int)$row['id'],'result_hash'=>vp3_profile_projection_hash_pa4b($payload)];
}
function vp3_profile_projection_archive_pa4b(PDO $pdo,array $source,array $row): array
{
    $user=vp3_profile_projection_user_pa4b($pdo,(int)$row['owner_user_id']);
    if(!function_exists('vp3_observation_personal_permission_v1'))require_once __DIR__.'/agent-observation-authority-v1.php';
    if(!vp3_observation_personal_permission_v1($pdo,$user,'agent_brain.access'))throw new VP3ProfileProjectionException('permission_denied');
    $message=vp3_profile_projection_marker_pa4b($pdo,$source,$row,true);$expected=['user_id'=>(int)$row['owner_user_id'],'user_agent_id'=>(int)$row['chat_agent_id'],'conversation_id'=>(int)$row['chat_conversation_id'],'source_message_id'=>(int)$row['chat_message_id'],'role'=>'assistant','input_mode'=>'text','message_text'=>$message['message'],'created_at'=>$message['created_at']];
    $q=$pdo->prepare('SELECT * FROM agent_chat_archive WHERE user_id=? AND source_message_id=?'.vp3_profile_projection_lock_pa4b($pdo));$q->execute([$expected['user_id'],$expected['source_message_id']]);$saved=$q->fetch(PDO::FETCH_ASSOC);
    if(!$saved){
        $pdo->prepare('INSERT INTO agent_chat_archive(user_id,user_agent_id,conversation_id,source_message_id,role,input_mode,message_text,created_at,archived_at) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$expected['user_id'],$expected['user_agent_id']?:null,$expected['conversation_id'],$expected['source_message_id'],'assistant','text',$expected['message_text'],$expected['created_at'],gmdate('Y-m-d H:i:s',intdiv(vp3_profile_projection_now_pa4b(),1000))]);$q->execute([$expected['user_id'],$expected['source_message_id']]);$saved=$q->fetch(PDO::FETCH_ASSOC);
    }
    if(!$saved)throw new VP3ProfileProjectionException('destination_changed');
    foreach($expected as $key=>$value){$actual=is_int($value)?(int)($saved[$key]??0):($saved[$key]??null);if($actual!==$value)throw new VP3ProfileProjectionException('destination_changed');}
    vp3_profile_projection_marker_pa4b($pdo,$source,$row,true);
    if(!vp3_observation_personal_permission_v1($pdo,vp3_profile_projection_user_pa4b($pdo,$expected['user_id']),'agent_brain.access'))throw new VP3ProfileProjectionException('permission_denied');
    return ['result_id'=>(int)$saved['id'],'result_hash'=>vp3_profile_projection_hash_pa4b($expected+['public_source'=>vp3_profile_projection_references_pa4b($source)])];
}
function vp3_profile_projection_repair_pa4b(PDO $pdo,int $obligationId): array
{
    $base=['contract'=>VP3_PROFILE_PROJECTION_PA4B,'obligation_id'=>$obligationId];
    if(!vp3_profile_projection_schema_ready_pa4b($pdo))return $base+['state'=>'retryable_failed','reason'=>'not_ready'];
    $initial=vp3_profile_projection_row_pa4b($pdo,$obligationId);if(!$initial)return $base+['state'=>'blocked','reason'=>'not_found'];
    try{return vp3_profile_projection_atomic_pa4b($pdo,function()use($pdo,$initial,$base){
        $scope=['conversation_id'=>(int)$initial['chat_conversation_id'],'agent_id'=>(int)$initial['chat_agent_id']];
        // Canonical source/conversation locks precede the projection row lock.
        $source=vp3_profile_projection_source_pa4b($pdo,(int)$initial['owner_user_id'],(int)$initial['profile_event_id'],$scope,true);$row=vp3_profile_projection_row_pa4b($pdo,(int)$initial['id'],true);
        if(!$row)throw new VP3ProfileProjectionException('source_changed');
        foreach(['owner_user_id','profile_event_id','destination_kind','chat_conversation_id','chat_agent_id','chat_message_id','source_hash','source_json'] as $key)if($row[$key]!==$initial[$key])throw new VP3ProfileProjectionException('source_changed');
        if($source['status']!=='verified')throw new VP3ProfileProjectionException('source_unverified');
        $refs=vp3_profile_projection_references_pa4b($source);if(!hash_equals((string)$row['source_hash'],$source['source_hash'])||(string)$row['source_json']!==vp3_profile_projection_json_pa4b($refs))throw new VP3ProfileProjectionException('source_changed');
        if($row['state']==='blocked')return $base+['state'=>'blocked','reason'=>(string)$row['last_reason']];
        if(!in_array($row['state'],['pending','retryable_failed','completed'],true))throw new VP3ProfileProjectionException('source_changed');
        $result=match($row['destination_kind']){'event_inbox'=>vp3_profile_projection_inbox_pa4b($pdo,$source),'brain_archive'=>vp3_profile_projection_archive_pa4b($pdo,$source,$row),default=>throw new VP3ProfileProjectionException('destination_changed')};
        $fresh=vp3_profile_projection_source_pa4b($pdo,(int)$row['owner_user_id'],(int)$row['profile_event_id'],$scope,true);if($fresh['status']!=='verified'||!hash_equals($source['source_hash'],$fresh['source_hash']))throw new VP3ProfileProjectionException('source_changed');
        if($row['state']==='completed'&&((int)$row['result_id']!==$result['result_id']||!hash_equals((string)$row['result_hash'],$result['result_hash'])))throw new VP3ProfileProjectionException('destination_changed');
        $now=vp3_profile_projection_now_pa4b();$pdo->prepare("UPDATE profile_event_projections_pa4b SET state='completed',attempt_count=attempt_count+?,next_attempt_at_ms=0,result_id=?,result_hash=?,last_reason='',updated_at_ms=?,completed_at_ms=COALESCE(completed_at_ms,?) WHERE id=?")->execute([$row['state']==='completed'?0:1,$result['result_id'],$result['result_hash'],$now,$now,(int)$row['id']]);
        $receipt=vp3_profile_projection_row_pa4b($pdo,(int)$row['id'],true);if(!$receipt||$receipt['state']!=='completed'||(int)$receipt['result_id']!==$result['result_id']||!hash_equals((string)$receipt['result_hash'],$result['result_hash']))throw new RuntimeException('Projection receipt was not saved.');
        foreach(['owner_user_id','profile_event_id','destination_kind','chat_conversation_id','chat_agent_id','chat_message_id','source_hash','source_json'] as $key)if($receipt[$key]!==$row[$key])throw new VP3ProfileProjectionException('source_changed');
        $fresh=vp3_profile_projection_source_pa4b($pdo,(int)$row['owner_user_id'],(int)$row['profile_event_id'],$scope,true);if($fresh['status']!=='verified'||!hash_equals($source['source_hash'],$fresh['source_hash']))throw new VP3ProfileProjectionException('source_changed');
        if($row['destination_kind']==='brain_archive'&&!vp3_observation_personal_permission_v1($pdo,vp3_profile_projection_user_pa4b($pdo,(int)$row['owner_user_id']),'agent_brain.access'))throw new VP3ProfileProjectionException('permission_denied');
        return $base+['state'=>'completed','reason'=>'','destination'=>$row['destination_kind'],'result_id'=>$result['result_id'],'duplicate'=>$row['state']==='completed'];
    });}catch(Throwable $error){
        $blocked=$error instanceof VP3ProfileProjectionException||($error instanceof VP3ProfileAttentionException&&in_array($error->httpStatus,[403,404,409],true));$reason=$error instanceof VP3ProfileProjectionException?$error->reason:($blocked?'permission_denied':'projection_failed');$state=$blocked?'blocked':'retryable_failed';
        try{$now=vp3_profile_projection_now_pa4b();$delay=min(3600000,5000*(2**min(9,max(0,(int)$initial['attempt_count']))));$pdo->prepare('UPDATE profile_event_projections_pa4b SET state=?,attempt_count=attempt_count+1,next_attempt_at_ms=?,last_reason=?,updated_at_ms=? WHERE id=? AND state<>?')->execute([$state,$blocked?0:$now+$delay,$reason,$now,$obligationId,'completed']);}catch(Throwable $ignored){}
        return $base+['state'=>$state,'reason'=>$reason];
    }
}
/** A denied historical source leaves only an explicitly blocked reference.
 * This is never a substitute for source verification and contains no statement.
 */
function vp3_profile_projection_discovery_source_pa4b(PDO $pdo,int $owner,int $eventId): array
{
    try{return vp3_profile_projection_source_pa4b($pdo,$owner,$eventId);}
    catch(Throwable $e){
        if(!($e instanceof VP3ProfileProjectionException&&$e->reason==='permission_denied')&&!($e instanceof VP3ProfileAttentionException&&in_array($e->httpStatus,[403,404],true)))throw $e;
        $q=$pdo->prepare('SELECT id,owner_user_id,profile_session_id,profile_agent_id,event_type,metadata_json,created_at FROM profile_events WHERE id=? AND owner_user_id=?');$q->execute([$eventId,$owner]);$event=$q->fetch(PDO::FETCH_ASSOC);if(!$event)throw new VP3ProfileProjectionException('source_changed');
        $refs=['contract'=>'vp3.profile-source.v1','status'=>'unavailable','reason'=>'permission_denied','owner_user_id'=>$owner,'profile_event_id'=>$eventId,'profile_agent_id'=>max(0,(int)$event['profile_agent_id']),'profile_session_id'=>max(0,(int)$event['profile_session_id']),'public_conversation_id'=>0,'public_message_id'=>0,'event_type'=>(string)$event['event_type'],'occurred_at'=>(string)$event['created_at'],'sender_type'=>'system','sender_user_id'=>null,'statement_sha256'=>''];
        $refs['source_hash']=vp3_profile_projection_hash_pa4b(['unverified_reference'=>$refs,'saved_metadata_sha256'=>hash('sha256',(string)$event['metadata_json'])]);return $refs;
    }
}
function vp3_profile_projection_has_table_pa4b(PDO $pdo,string $table): bool
{
    $q=$pdo->prepare($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?':"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return(bool)$q->fetchColumn();
}
/** SQL only locates candidates; the canonical source and marker prove every row. */
function vp3_profile_projection_event_id_sql_pa4b(PDO $pdo,string $key): string
{
    if(!in_array($key,['message_id','conversation_id'],true))throw new LogicException('Invalid discovery selector.');
    $json="CASE WHEN JSON_VALID(e.metadata_json) THEN e.metadata_json ELSE '{}' END";
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"CAST(JSON_UNQUOTE(JSON_EXTRACT($json,'$.".$key."')) AS UNSIGNED)":"CAST(JSON_EXTRACT($json,'$.".$key."') AS INTEGER)";
}
/** Bounded repair discovery for pre-upgrade misses, with no latest-Chat fallback. */
function vp3_profile_projection_discover_pa4b(PDO $pdo,int $limit=20): array
{
    $limit=max(1,min(50,$limit));$out=['discovered'=>0,'blocked'=>0];
    $q=$pdo->query("SELECT e.id,e.owner_user_id FROM profile_events e WHERE NOT EXISTS(SELECT 1 FROM profile_event_projections_pa4b p WHERE p.owner_user_id=e.owner_user_id AND p.profile_event_id=e.id AND p.destination_kind='event_inbox' AND p.chat_conversation_id=0 AND p.chat_agent_id=0 AND p.chat_message_id=0) ORDER BY e.id LIMIT ".$limit);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $candidate){
        $id=vp3_profile_projection_atomic_pa4b($pdo,function()use($pdo,$candidate){$source=vp3_profile_projection_discovery_source_pa4b($pdo,(int)$candidate['owner_user_id'],(int)$candidate['id']);return vp3_profile_projection_insert_pa4b($pdo,$source,'event_inbox');});$row=vp3_profile_projection_row_pa4b($pdo,$id);$out['discovered']++;if(($row['state']??null)==='blocked')$out['blocked']++;
    }
    $remaining=$limit;$mid=vp3_profile_projection_event_id_sql_pa4b($pdo,'message_id');$cid=vp3_profile_projection_event_id_sql_pa4b($pdo,'conversation_id');
    $queries=[];
    if(vp3_profile_projection_has_table_pa4b($pdo,'profile_attention_deliveries_pa4b'))$queries[]="SELECT DISTINCT e.id,e.owner_user_id,d.chat_conversation_id,d.chat_agent_id,d.chat_message_id FROM profile_attention_deliveries_pa4b d INNER JOIN profile_events e ON e.id=d.profile_event_id AND e.owner_user_id=d.owner_user_id";
    if(vp3_profile_projection_has_table_pa4b($pdo,'profile_agent_handoff_deliveries_pa3')&&vp3_profile_projection_has_table_pa4b($pdo,'profile_agent_handoff_turns_pa3'))$queries[]="SELECT DISTINCT e.id,e.owner_user_id,d.chat_conversation_id,d.chat_agent_id,d.chat_message_id FROM profile_agent_handoff_deliveries_pa3 d INNER JOIN profile_agent_handoff_turns_pa3 t ON t.id=d.turn_id AND t.owner_user_id=d.owner_user_id INNER JOIN profile_events e ON e.owner_user_id=d.owner_user_id AND $cid=t.conversation_id AND ((e.event_type='message_received' AND $mid=t.visitor_message_id) OR (e.event_type='message_sent' AND ($mid=t.answer_message_id OR $mid=t.last_owner_message_id OR EXISTS(SELECT 1 FROM profile_agent_handoff_operations_pa3 op WHERE op.owner_user_id=t.owner_user_id AND op.turn_id=t.id AND op.operation='reply' AND op.message_id=$mid))))";
    if(vp3_profile_projection_has_table_pa4b($pdo,'profile_speech_items_pa4a'))$queries[]="SELECT DISTINCT e.id,e.owner_user_id,d.chat_conversation_id,d.chat_agent_id,d.chat_message_id FROM profile_speech_items_pa4a d INNER JOIN profile_events e ON e.owner_user_id=d.owner_user_id AND ((d.kind='arrival' AND e.id=d.source_event_id) OR (d.kind='visitor_message' AND e.event_type='message_received' AND $mid=d.visitor_message_id AND $cid=d.public_conversation_id))";
    foreach($queries as $sql){
        if($remaining<1)break;$sql.=" WHERE d.chat_message_id>0 AND NOT EXISTS(SELECT 1 FROM profile_event_projections_pa4b p WHERE p.owner_user_id=e.owner_user_id AND p.profile_event_id=e.id AND p.destination_kind='brain_archive' AND p.chat_conversation_id=d.chat_conversation_id AND p.chat_agent_id=d.chat_agent_id AND p.chat_message_id=d.chat_message_id) ORDER BY e.id,d.chat_message_id LIMIT ".$remaining;
        foreach($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $candidate){
            $id=vp3_profile_projection_atomic_pa4b($pdo,function()use($pdo,$candidate){$source=vp3_profile_projection_discovery_source_pa4b($pdo,(int)$candidate['owner_user_id'],(int)$candidate['id']);return vp3_profile_projection_capture_marker_pa4b($pdo,$source,$candidate);});$row=vp3_profile_projection_row_pa4b($pdo,$id);$out['discovered']++;if(($row['state']??null)==='blocked')$out['blocked']++;$remaining--;
        }
    }
    return $out;
}
function vp3_profile_projection_sweep_pa4b(PDO $pdo,int $limit=20): array
{
    $out=['contract'=>VP3_PROFILE_PROJECTION_PA4B,'ready'=>vp3_profile_projection_schema_ready_pa4b($pdo),'discovered'=>0,'processed'=>0,'completed'=>0,'blocked'=>0,'retryable_failed'=>0,'reason'=>''];$limit=max(1,min(50,$limit));
    if(!$out['ready']){$out['reason']='not_ready';return $out;}
    if($pdo->inTransaction()){$out['ready']=false;$out['reason']='transaction_active';return $out;}
    try{$discovery=vp3_profile_projection_discover_pa4b($pdo,$limit);$out['discovered']=$discovery['discovered'];$out['blocked']=$discovery['blocked'];$q=$pdo->prepare("SELECT id FROM profile_event_projections_pa4b WHERE state IN ('pending','retryable_failed') AND next_attempt_at_ms<=? ORDER BY next_attempt_at_ms,id LIMIT ".$limit);$q->execute([vp3_profile_projection_now_pa4b()]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
        foreach($ids as $id){$result=vp3_profile_projection_repair_pa4b($pdo,(int)$id);$out['processed']++;if(isset($out[$result['state']]))$out[$result['state']]++;}return $out;
    }catch(Throwable $error){$out['ready']=false;$out['reason']='projection_failed';return $out;}
}
