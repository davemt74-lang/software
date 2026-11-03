<?php
declare(strict_types=1);

/** Source-bound Profile narration. No browser text or provider voice ID is an authority. */
require_once __DIR__.'/profile-agent-handoff-pa3.php';

const VP3_PROFILE_SPEECH_PA4A='vp3.profile-speech.v1';
const VP3_PROFILE_SPEECH_ARRIVAL_MS_PA4A=1800000;
const VP3_PROFILE_SPEECH_AUTO_MS_PA4A=300000;
const VP3_PROFILE_SPEECH_ATTEMPT_MS_PA4A=90000;
const VP3_PROFILE_SPEECH_PLAY_MS_PA4A=30000;

final class VP3ProfileSpeechException extends RuntimeException
{
    public function __construct(public readonly string $publicCode,string $message,public readonly int $httpStatus=409,public readonly int $retryAfterSeconds=0){parent::__construct($message,$httpStatus);}
}
function vp3_profile_speech_error_pa4a(string $code='PROFILE_SPEECH_CHANGED',int $status=409): never
{
    $message=match($code){
        'PROFILE_SPEECH_INVALID'=>'Choose a valid visitor speech action.',
        'PROFILE_SPEECH_NOT_READY'=>'Visitor speech is not available on this deployment.',
        'PROFILE_SPEECH_UNAVAILABLE'=>'This visitor speech source is no longer available.',
        'PROFILE_SPEECH_BUSY'=>'Voice playback is busy or a call is in progress.',
        'PROFILE_SPEECH_MUTED'=>'Visitor speech is muted or the current Chat is not available for playback.',
        'PROFILE_SPEECH_VOICE_UNAVAILABLE'=>'No permitted distinct visitor voice is available for this preference.',
        'PROFILE_SPEECH_RETRY_REQUIRED'=>'The previous speech request may have used provider quota. Choose Retry explicitly to create another request.',
        'PROFILE_SPEECH_AUDIO_EXPIRED'=>'The saved audio has expired. A new request requires an explicit retry.',
        'PROFILE_SPEECH_ALREADY_PLAYED'=>'Automatic playback for this visitor item has already been consumed.',
        default=>'This visitor speech item changed. Refresh its card before continuing.',
    };
    throw new VP3ProfileSpeechException($code,$message,$status,$code==='PROFILE_SPEECH_BUSY'?2:0);
}
if(!function_exists('vp3_profile_speech_now_pa4a')){function vp3_profile_speech_now_pa4a(): int{return vp3_profile_handoff_now_pa3();}}
function vp3_profile_speech_id_pa4a(mixed $value,bool $zero=false): int
{
    if(!is_int($value)&&!(is_string($value)&&preg_match('/^(0|[1-9][0-9]{0,14})$/D',$value)))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_INVALID',422);
    $id=(int)$value;if($id<($zero?0:1)||$id>9007199254740991)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_INVALID',422);return $id;
}
function vp3_profile_speech_token_pa4a(mixed $value,int $length=32): string
{
    if(!is_string($value)||!preg_match('/^[a-f0-9]{'.$length.'}$/D',$value))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_INVALID',422);return $value;
}
function vp3_profile_speech_json_pa4a(string $value,int $limit=16384): array
{
    if(strlen($value)>$limit)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
    $decoded=json_decode($value,true);if(!is_array($decoded)||array_is_list($decoded))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);return $decoded;
}
function vp3_profile_speech_dependencies_pa4a(): void
{
    if(!function_exists('vp3_profile_agent_public_live_v1'))require_once __DIR__.'/profile-agent-public-service-v110.php';
    if(!function_exists('vp3_profile_call_slot_pa3c'))require_once __DIR__.'/profile-calls-pa3c.php';
}
function vp3_profile_speech_provider_pa4a(): void
{
    if(!function_exists('vp3_profile_speech_config_pa4a'))require_once __DIR__.'/profile-speech-elevenlabs-pa4a.php';
}
function vp3_profile_speech_schema_ready_pa4a(PDO $pdo): bool
{
    try{
        $pdo->query('SELECT id,owner_user_id,principal_hash,visitor_user_id,credential_hash,style,preset_key,choice_origin,assignment_revision,voice_json,arrival_generation,arrival_started_at_ms,last_activity_at_ms,created_at_ms,updated_at_ms FROM profile_visitor_voices_pa4a WHERE 1=0');
        $pdo->query('SELECT id,owner_user_id,voice_assignment_id,kind,source_key,profile_agent_id,profile_session_id,visitor_user_id,credential_hash,authority_json,binding_hash,public_conversation_id,turn_id,visitor_message_id,source_event_id,arrival_generation,arrival_context,source_hash,revision,voice_revision,state,auto_consumed,automatic_expires_at_ms,delivery_id,chat_conversation_id,chat_agent_id,chat_message_id,tab_id,client_hash,last_reason,created_at_ms,updated_at_ms FROM profile_speech_items_pa4a WHERE 1=0');
        $pdo->query('SELECT id,owner_user_id,item_id,attempt_no,request_id,request_hash,mode,retry_of,cache_of,item_revision,voice_revision,text_hash,voice_json,config_fingerprint,model_id,output_format,scope_json,ticket_hash,provider_state,delivery_state,execution_token,lease_until_ms,provider_attempted,input_characters,provider_character_cost,provider_request_id,provider_trace_id,result_json,audio_key,audio_bytes,audio_sha256,audio_expires_at_ms,audio_removed_at_ms,play_request_id,play_token,playback_state,play_lease_until_ms,play_started_at_ms,play_absolute_until_ms,report_sequence,last_reason,created_at_ms,updated_at_ms FROM profile_speech_attempts_pa4a WHERE 1=0');return true;
    }catch(Throwable $error){return false;}
}
function vp3_profile_speech_ensure_schema_pa4a(PDO $pdo): void
{
    if($pdo->inTransaction()){if(vp3_profile_speech_schema_ready_pa4a($pdo))return;throw new RuntimeException('Visitor speech schema must initialize outside a transaction.');}
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';$auto=$sqlite?'INTEGER PRIMARY KEY AUTOINCREMENT':'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';$suffix=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_visitor_voices_pa4a(id $auto,owner_user_id BIGINT NOT NULL,principal_hash CHAR(64) NOT NULL,visitor_user_id BIGINT NOT NULL DEFAULT 0,credential_hash CHAR(64) NOT NULL,style VARCHAR(12) NOT NULL DEFAULT 'preset',preset_key CHAR(64) NOT NULL DEFAULT '',choice_origin VARCHAR(20) NOT NULL DEFAULT 'default',assignment_revision BIGINT NOT NULL DEFAULT 1,voice_json TEXT NULL,arrival_generation BIGINT NOT NULL DEFAULT 0,arrival_started_at_ms BIGINT NOT NULL DEFAULT 0,last_activity_at_ms BIGINT NOT NULL DEFAULT 0,created_at_ms BIGINT NOT NULL,updated_at_ms BIGINT NOT NULL,UNIQUE(owner_user_id,principal_hash))".$suffix);
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_speech_items_pa4a(id $auto,owner_user_id BIGINT NOT NULL,voice_assignment_id BIGINT NOT NULL,kind VARCHAR(20) NOT NULL,source_key CHAR(64) NOT NULL,profile_agent_id BIGINT NOT NULL,profile_session_id BIGINT NOT NULL,visitor_user_id BIGINT NOT NULL DEFAULT 0,credential_hash CHAR(64) NOT NULL,authority_json TEXT NOT NULL,binding_hash CHAR(64) NOT NULL DEFAULT '',public_conversation_id BIGINT NOT NULL DEFAULT 0,turn_id BIGINT NOT NULL DEFAULT 0,visitor_message_id BIGINT NOT NULL DEFAULT 0,source_event_id BIGINT NULL,arrival_generation BIGINT NOT NULL DEFAULT 0,arrival_context VARCHAR(8) NOT NULL DEFAULT 'page',source_hash CHAR(64) NOT NULL,revision BIGINT NOT NULL DEFAULT 1,voice_revision BIGINT NOT NULL DEFAULT 1,state VARCHAR(24) NOT NULL DEFAULT 'available',auto_consumed INTEGER NOT NULL DEFAULT 0,automatic_expires_at_ms BIGINT NOT NULL,delivery_id CHAR(32) NOT NULL DEFAULT '',chat_conversation_id BIGINT NOT NULL DEFAULT 0,chat_agent_id BIGINT NOT NULL DEFAULT 0,chat_message_id BIGINT NOT NULL DEFAULT 0,tab_id CHAR(32) NOT NULL DEFAULT '',client_hash CHAR(64) NOT NULL DEFAULT '',last_reason VARCHAR(48) NOT NULL DEFAULT '',created_at_ms BIGINT NOT NULL,updated_at_ms BIGINT NOT NULL,UNIQUE(owner_user_id,source_key))".$suffix);
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_speech_attempts_pa4a(id $auto,owner_user_id BIGINT NOT NULL,item_id BIGINT NOT NULL,attempt_no BIGINT NOT NULL,request_id CHAR(32) NOT NULL,request_hash CHAR(64) NOT NULL,mode VARCHAR(12) NOT NULL,retry_of BIGINT NULL,cache_of BIGINT NULL,item_revision BIGINT NOT NULL,voice_revision BIGINT NOT NULL,text_hash CHAR(64) NOT NULL,voice_json TEXT NOT NULL,config_fingerprint CHAR(64) NOT NULL,model_id VARCHAR(80) NOT NULL,output_format VARCHAR(40) NOT NULL,scope_json TEXT NOT NULL,ticket_hash CHAR(64) NOT NULL,provider_state VARCHAR(24) NOT NULL DEFAULT 'reserved',delivery_state VARCHAR(20) NOT NULL DEFAULT 'available',execution_token CHAR(32) NOT NULL DEFAULT '',lease_until_ms BIGINT NOT NULL,provider_attempted INTEGER NULL,input_characters INTEGER NOT NULL,provider_character_cost DECIMAL(20,6) NULL,provider_request_id VARCHAR(128) NULL,provider_trace_id VARCHAR(128) NULL,result_json TEXT NULL,audio_key CHAR(64) NULL,audio_bytes BIGINT NOT NULL DEFAULT 0,audio_sha256 CHAR(64) NULL,audio_expires_at_ms BIGINT NULL,audio_removed_at_ms BIGINT NULL,play_request_id CHAR(32) NOT NULL DEFAULT '',play_token CHAR(32) NOT NULL DEFAULT '',playback_state VARCHAR(24) NOT NULL DEFAULT 'none',play_lease_until_ms BIGINT NOT NULL DEFAULT 0,play_started_at_ms BIGINT NOT NULL DEFAULT 0,play_absolute_until_ms BIGINT NOT NULL DEFAULT 0,report_sequence BIGINT NOT NULL DEFAULT 0,last_reason VARCHAR(48) NOT NULL DEFAULT '',created_at_ms BIGINT NOT NULL,updated_at_ms BIGINT NOT NULL,UNIQUE(owner_user_id,request_id),UNIQUE(item_id,attempt_no))".$suffix);
    foreach([
        ['profile_speech_items_pa4a','idx_profile_speech_owner','owner_user_id,state,id'],
        ['profile_speech_items_pa4a','idx_profile_speech_chat','owner_user_id,chat_conversation_id,chat_agent_id,id'],
        ['profile_speech_items_pa4a','idx_profile_speech_public','owner_user_id,public_conversation_id,kind,id'],
        ['profile_speech_items_pa4a','idx_profile_speech_arrival','voice_assignment_id,arrival_generation,kind'],
        ['profile_speech_attempts_pa4a','idx_profile_speech_lease','owner_user_id,provider_state,lease_until_ms,id'],
        ['profile_speech_attempts_pa4a','idx_profile_speech_play','owner_user_id,play_lease_until_ms,id'],
        ['profile_speech_attempts_pa4a','idx_profile_speech_audio','audio_removed_at_ms,audio_expires_at_ms,id'],
    ] as [$table,$name,$columns]){
        $q=$pdo->prepare($sqlite?"SELECT name FROM sqlite_master WHERE type='index' AND name=?":'SELECT INDEX_NAME FROM information_schema.statistics WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');$q->execute($sqlite?[$name]:[$table,$name]);if(!$q->fetchColumn())$pdo->exec('CREATE INDEX '.$name.' ON '.$table.'('.$columns.')');
    }
}
function vp3_profile_speech_require_pa4a(PDO $pdo): void
{
    vp3_profile_speech_dependencies_pa4a();if(!vp3_profile_speech_schema_ready_pa4a($pdo)||!vp3_profile_handoff_schema_ready_pa3($pdo)||!vp3_profile_call_schema_ready_pa3c($pdo))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_NOT_READY',503);
}
function vp3_profile_speech_item_pa4a(PDO $pdo,int $id,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM profile_speech_items_pa4a WHERE id=?'.($lock?vp3_profile_handoff_lock_pa3($pdo):''));$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function vp3_profile_speech_attempt_row_pa4a(PDO $pdo,int $id,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM profile_speech_attempts_pa4a WHERE id=?'.($lock?vp3_profile_handoff_lock_pa3($pdo):''));$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function vp3_profile_speech_voice_row_pa4a(PDO $pdo,int $id,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM profile_visitor_voices_pa4a WHERE id=?'.($lock?vp3_profile_handoff_lock_pa3($pdo):''));$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function vp3_profile_speech_binding_pa4a(array $item): string
{
    $binding=[];foreach(['owner_user_id','voice_assignment_id','kind','source_key','profile_agent_id','profile_session_id','visitor_user_id','credential_hash','authority_json','public_conversation_id','turn_id','visitor_message_id','arrival_generation','arrival_context','source_hash'] as $key)$binding[$key]=(string)($item[$key]??'');return hash('sha256',json_encode($binding,JSON_THROW_ON_ERROR));
}
function vp3_profile_speech_snapshot_pa4a(array $ctx): array
{
    $owner=(int)($ctx['owner_user_id']??0);$a=$ctx['conversation_authority']??[];
    if($owner<1||($a['transport']??null)!=='native'||(int)($a['owner_user_id']??0)!==$owner||(int)($a['visitor_user_id']??-1)!==(int)($ctx['visitor']['id']??0)||!preg_match('/^[a-f0-9]{64}$/D',(string)($a['credential_hash']??'')))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
    return ['contract'=>'vp3.profile-speech-source.v1','owner_user_id'=>$owner,'profile_agent_id'=>(int)$ctx['agent_id'],'profile_session_id'=>(int)$ctx['session_id'],'visitor_user_id'=>(int)$a['visitor_user_id'],'credential_hash'=>$a['credential_hash'],'profile_username'=>(string)$ctx['profile']['username'],'session_hash'=>hash('sha256',(string)$ctx['session']['session_key']),'visitor_auth_revision'=>(string)($ctx['accepted_visitor_auth_revision']??'')];
}
function vp3_profile_speech_native_pa4a(PDO $pdo,array $ctx,bool $lock=false): array
{
    vp3_profile_speech_dependencies_pa4a();$ctx=vp3_profile_agent_public_live_v1($pdo,$ctx,$lock);$snapshot=vp3_profile_speech_snapshot_pa4a($ctx);
    if($snapshot['visitor_user_id']===$snapshot['owner_user_id'])vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
    try{vp3_profile_call_block_check_pa3c($pdo,$snapshot['owner_user_id'],$snapshot['visitor_user_id'],$lock);}catch(VP3ProfileCallException $e){vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);}return $ctx;
}
function vp3_profile_speech_assignment_pa4a(PDO $pdo,array $ctx): array
{
    if(!$pdo->inTransaction())throw new RuntimeException('Visitor assignment needs a transaction.');$s=vp3_profile_speech_snapshot_pa4a($ctx);$principal=hash('sha256','profile-speech|'.$s['owner_user_id'].'|'.$s['visitor_user_id'].'|'.$s['credential_hash']);$now=vp3_profile_speech_now_pa4a();
    $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';$pdo->prepare($sql.' INTO profile_visitor_voices_pa4a(owner_user_id,principal_hash,visitor_user_id,credential_hash,created_at_ms,updated_at_ms) VALUES(?,?,?,?,?,?)')->execute([$s['owner_user_id'],$principal,$s['visitor_user_id'],$s['credential_hash'],$now,$now]);
    $q=$pdo->prepare('SELECT * FROM profile_visitor_voices_pa4a WHERE owner_user_id=? AND principal_hash=?'.vp3_profile_handoff_lock_pa3($pdo));$q->execute([$s['owner_user_id'],$principal]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||(int)$row['visitor_user_id']!==$s['visitor_user_id']||!hash_equals($row['credential_hash'],$s['credential_hash'])||!hash_equals($row['principal_hash'],$principal))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);return $row;
}
function vp3_profile_speech_insert_item_pa4a(PDO $pdo,array $ctx,array $voice,string $kind,string $key,int $cid=0,int $turnId=0,int $messageId=0,string $sourceHash='',string $arrivalContext='page'): array
{
    $s=vp3_profile_speech_snapshot_pa4a($ctx);$sourceKey=hash('sha256',$key);$q=$pdo->prepare('SELECT * FROM profile_speech_items_pa4a WHERE owner_user_id=? AND source_key=?');$q->execute([$s['owner_user_id'],$sourceKey]);if($prior=$q->fetch(PDO::FETCH_ASSOC))return $prior;
    $now=vp3_profile_speech_now_pa4a();$sourceHash=$sourceHash!==''?$sourceHash:hash('sha256','arrival|'.$voice['id'].'|'.$voice['arrival_generation'].'|'.$arrivalContext.'|'.$cid);
    $pdo->prepare('INSERT INTO profile_speech_items_pa4a(owner_user_id,voice_assignment_id,kind,source_key,profile_agent_id,profile_session_id,visitor_user_id,credential_hash,authority_json,public_conversation_id,turn_id,visitor_message_id,arrival_generation,arrival_context,source_hash,voice_revision,automatic_expires_at_ms,created_at_ms,updated_at_ms) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$s['owner_user_id'],(int)$voice['id'],$kind,$sourceKey,$s['profile_agent_id'],$s['profile_session_id'],$s['visitor_user_id'],$s['credential_hash'],json_encode($s,JSON_THROW_ON_ERROR),$cid,$turnId,$messageId,(int)$voice['arrival_generation'],$arrivalContext,$sourceHash,(int)$voice['assignment_revision'],$now+VP3_PROFILE_SPEECH_AUTO_MS_PA4A,$now,$now]);$id=(int)$pdo->lastInsertId();$row=vp3_profile_speech_item_pa4a($pdo,$id,true);
    $pdo->prepare('UPDATE profile_speech_items_pa4a SET binding_hash=? WHERE id=?')->execute([vp3_profile_speech_binding_pa4a($row),$id]);return vp3_profile_speech_item_pa4a($pdo,$id,true)??throw new RuntimeException('Visitor speech source was not saved.');
}
/** Native page/message entry only. GET polling never calls this helper. */
function vp3_profile_speech_arrival_pa4a(PDO $pdo,array $ctx,string $arrivalContext='page',int $cid=0): ?array
{
    if(!vp3_profile_speech_schema_ready_pa4a($pdo))return null;if(!in_array($arrivalContext,['page','chat'],true)||($arrivalContext==='chat'&&$cid<1))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_INVALID',422);
    $ctx=vp3_profile_speech_native_pa4a($pdo,$ctx);return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$ctx,$arrivalContext,$cid){
        $ctx=vp3_profile_speech_native_pa4a($pdo,$ctx,true);if($cid>0)vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$cid,true);$voice=vp3_profile_speech_assignment_pa4a($pdo,$ctx);$now=vp3_profile_speech_now_pa4a();
        if((int)$voice['arrival_generation']<1||(int)$voice['last_activity_at_ms']>$now||$now-(int)$voice['last_activity_at_ms']>=VP3_PROFILE_SPEECH_ARRIVAL_MS_PA4A){$pdo->prepare('UPDATE profile_visitor_voices_pa4a SET arrival_generation=arrival_generation+1,arrival_started_at_ms=?,last_activity_at_ms=?,updated_at_ms=? WHERE id=?')->execute([$now,$now,$now,(int)$voice['id']]);}
        else $pdo->prepare('UPDATE profile_visitor_voices_pa4a SET last_activity_at_ms=?,updated_at_ms=? WHERE id=?')->execute([$now,$now,(int)$voice['id']]);
        $voice=vp3_profile_speech_voice_row_pa4a($pdo,(int)$voice['id'],true);$item=vp3_profile_speech_insert_item_pa4a($pdo,$ctx,$voice,'arrival','arrival|'.$voice['id'].'|'.$voice['arrival_generation'],$cid,0,0,'',$arrivalContext);
        if($arrivalContext==='chat'&&$item['arrival_context']==='page'&&(int)$item['auto_consumed']===0){
            $q=$pdo->prepare("SELECT id FROM profile_speech_attempts_pa4a WHERE item_id=? AND provider_state IN ('sending','ready','outcome_unverified') LIMIT 1");$q->execute([(int)$item['id']]);
            if(!$q->fetchColumn()){
                $pdo->prepare("UPDATE profile_speech_items_pa4a SET arrival_context='chat',public_conversation_id=?,source_hash=?,revision=revision+1,updated_at_ms=? WHERE id=?")->execute([$cid,hash('sha256','arrival|'.$voice['id'].'|'.$voice['arrival_generation'].'|chat|'.$cid),$now,(int)$item['id']]);$item=vp3_profile_speech_item_pa4a($pdo,(int)$item['id'],true);$pdo->prepare('UPDATE profile_speech_items_pa4a SET binding_hash=? WHERE id=?')->execute([vp3_profile_speech_binding_pa4a($item),(int)$item['id']]);
                $pdo->prepare("UPDATE profile_speech_attempts_pa4a SET provider_state='blocked',delivery_state='blocked',last_reason='source_changed',updated_at_ms=? WHERE item_id=? AND provider_state='reserved'")->execute([$now,(int)$item['id']]);
            }
        }
        vp3_profile_speech_native_pa4a($pdo,$ctx,true);return vp3_profile_speech_item_pa4a($pdo,(int)$item['id'],true);
    });
}
function vp3_profile_speech_page_entry_pa4a(PDO $pdo,array $profile,?array $visitor,array $session): ?array
{
    if(!vp3_profile_speech_schema_ready_pa4a($pdo)||empty($profile['profile_agent_enabled']))return null;
    try{vp3_profile_speech_dependencies_pa4a();$ctx=vp3_profile_agent_public_context_v110($pdo,$profile,$visitor,true);if((int)$ctx['session_id']!==(int)$session['id'])return null;return vp3_profile_speech_arrival_pa4a($pdo,$ctx);}
    catch(Throwable $error){error_log('Profile visitor arrival speech was unavailable.');return null;}
}
function vp3_profile_speech_link_event_pa4a(PDO $pdo,array $item,int $eventId): void
{
    if($eventId<1||$item['kind']!=='arrival')return;$q=$pdo->prepare("SELECT id FROM profile_events WHERE id=? AND owner_user_id=? AND profile_session_id=? AND event_type='profile_view'");$q->execute([$eventId,(int)$item['owner_user_id'],(int)$item['profile_session_id']]);if(!$q->fetchColumn())return;
    $pdo->prepare('UPDATE profile_speech_items_pa4a SET source_event_id=? WHERE id=? AND owner_user_id=? AND source_event_id IS NULL')->execute([$eventId,(int)$item['id'],(int)$item['owner_user_id']]);
}
/** Presentation ownership only; notification read state and pickup ACK remain independent. */
function vp3_profile_speech_notification_owned_pa4a(PDO $pdo,array $user,array $notification): bool
{
    if(!vp3_profile_speech_schema_ready_pa4a($pdo)||(int)($notification['user_id']??0)!==(int)($user['id']??0)||($notification['source_type']??null)!=='profile_event'||(int)($notification['source_id']??0)<1)return false;
    $q=$pdo->prepare("SELECT s.id FROM profile_speech_items_pa4a s INNER JOIN profile_events e ON e.id=s.source_event_id AND e.owner_user_id=s.owner_user_id AND e.profile_session_id=s.profile_session_id WHERE s.owner_user_id=? AND s.kind='arrival' AND e.event_type='profile_view' AND s.source_event_id=? LIMIT 1");$q->execute([(int)$user['id'],(int)$notification['source_id']]);return(bool)$q->fetchColumn();
}
/** Called inside successful native admission; it never changes pickup or responder state. */
function vp3_profile_speech_message_pa4a(PDO $pdo,array $ctx,array $turn): ?array
{
    if(!vp3_profile_speech_schema_ready_pa4a($pdo))return null;if(!$pdo->inTransaction())throw new RuntimeException('Message speech admission needs the message transaction.');
    $ctx=vp3_profile_speech_native_pa4a($pdo,$ctx,true);$cid=(int)$turn['conversation_id'];vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$cid,true);
    $q=$pdo->prepare("SELECT message,sender_user_id FROM profile_agent_messages WHERE id=? AND conversation_id=? AND sender_type='visitor'");$q->execute([(int)$turn['visitor_message_id'],$cid]);$message=$q->fetch(PDO::FETCH_ASSOC);
    if(!$message||(int)($message['sender_user_id']??0)!==(int)($ctx['visitor']['id']??0)||(int)$turn['owner_user_id']!==(int)$ctx['owner_user_id']||!hash_equals((string)$turn['credential_hash'],$ctx['conversation_authority']['credential_hash']))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
    vp3_profile_speech_arrival_pa4a($pdo,$ctx,'chat',$cid);$voice=vp3_profile_speech_assignment_pa4a($pdo,$ctx);
    return vp3_profile_speech_insert_item_pa4a($pdo,$ctx,$voice,'visitor_message','message|'.$turn['visitor_message_id'],$cid,(int)$turn['id'],(int)$turn['visitor_message_id'],hash('sha256',(string)$message['message']),'chat');
}

/** Bounded local recovery; it never synthesizes, creates schema, or resets a sending attempt. */
function vp3_profile_speech_maintenance_pa4a(PDO $pdo,int $limit=20): array
{
    $counts=['ready'=>false,'reason'=>'schema_unavailable','reserved_expired'=>0,'sending_unverified'=>0,'playback_expired'=>0];if(!vp3_profile_speech_schema_ready_pa4a($pdo))return $counts;
    $counts['ready']=true;$counts['reason']='';$limit=max(1,min(100,$limit));$now=vp3_profile_speech_now_pa4a();
    $q=$pdo->prepare("SELECT id,provider_state,lease_until_ms,playback_state,play_lease_until_ms FROM profile_speech_attempts_pa4a WHERE (provider_state IN ('reserved','sending') AND lease_until_ms<=?) OR (playback_state IN ('granted','playing') AND play_lease_until_ms<=?) ORDER BY updated_at_ms,id LIMIT ".$limit);$q->execute([$now,$now]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if(in_array($row['provider_state'],['reserved','sending'],true)&&(int)$row['lease_until_ms']<=$now){$sending=$row['provider_state']==='sending';$u=$pdo->prepare("UPDATE profile_speech_attempts_pa4a SET provider_state=?,delivery_state='blocked',last_reason=?,updated_at_ms=? WHERE id=? AND provider_state=? AND lease_until_ms<=?");$u->execute([$sending?'outcome_unverified':'blocked',$sending?'sending_expired':'lease_expired',$now,(int)$row['id'],$row['provider_state'],$now]);$counts[$sending?'sending_unverified':'reserved_expired']+=$u->rowCount();}
        if(in_array($row['playback_state'],['granted','playing'],true)&&(int)$row['play_lease_until_ms']<=$now){$u=$pdo->prepare("UPDATE profile_speech_attempts_pa4a SET playback_state='interrupted',delivery_state='expired',last_reason='playback_lease_expired',updated_at_ms=? WHERE id=? AND playback_state IN ('granted','playing') AND play_lease_until_ms<=?");$u->execute([$now,(int)$row['id'],$now]);$counts['playback_expired']+=$u->rowCount();}
    }return $counts;
}

/** Read a saved arrival/message with current source permissions. It is not an inference grant. */
function vp3_profile_speech_source_pa4a(PDO $pdo,array $item,bool $lock=false): array
{
    vp3_profile_speech_dependencies_pa4a();if(!hash_equals((string)$item['binding_hash'],vp3_profile_speech_binding_pa4a($item)))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
    $s=vp3_profile_speech_json_pa4a((string)$item['authority_json'],4096);if(($s['contract']??null)!=='vp3.profile-speech-source.v1')vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
    foreach(['owner_user_id','profile_agent_id','profile_session_id','visitor_user_id'] as $field)if(!is_int($s[$field]??null)||$s[$field]!== (int)$item[$field])vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
    foreach(['credential_hash','session_hash'] as $field)if(!is_string($s[$field]??null)||!preg_match('/^[a-f0-9]{64}$/D',$s[$field]))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
    if(!hash_equals($s['credential_hash'],(string)$item['credential_hash']))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);$owner=$s['owner_user_id'];$visitorId=$s['visitor_user_id'];$suffix=$lock?vp3_profile_handoff_lock_pa3($pdo):'';
    $q=$pdo->prepare('SELECT p.*,u.is_active,u.role,u.display_name FROM user_profiles p INNER JOIN users u ON u.id=p.user_id WHERE p.user_id=?'.$suffix);$q->execute([$owner]);$profile=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?'.$suffix);$q->execute([$owner]);$user=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT * FROM user_agents WHERE id=? AND owner_user_id=?'.$suffix);$q->execute([$s['profile_agent_id'],$owner]);$agent=$q->fetch(PDO::FETCH_ASSOC);
    if(!$profile||!$user||empty($user['is_active'])||empty($profile['is_public'])||empty($profile['profile_agent_enabled'])||(int)$profile['profile_agent_id']!==$s['profile_agent_id']||($profile['username']??null)!==($s['profile_username']??null)||!$agent||empty($agent['is_active'])||$visitorId===$owner)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
    if(!function_exists('vp3_observation_personal_permission_v1'))require_once __DIR__.'/agent-observation-authority-v1.php';
    foreach(['profile_agent.access','profile_chat.access'] as $permission)if(!vp3_observation_personal_permission_v1($pdo,$user,$permission))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
    $q=$pdo->prepare('SELECT id,owner_user_id,session_key FROM profile_visit_sessions WHERE id=? AND owner_user_id=?'.$suffix);$q->execute([$s['profile_session_id'],$owner]);$session=$q->fetch(PDO::FETCH_ASSOC);if(!$session||!hash_equals($s['session_hash'],hash('sha256',(string)$session['session_key'])))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
    $visitor=null;$label='A visitor';
    if($visitorId>0){
        $q=$pdo->prepare('SELECT id,display_name,is_active,password_hash FROM users WHERE id=?'.$suffix);$q->execute([$visitorId]);$visitor=$q->fetch(PDO::FETCH_ASSOC);
        if(!$visitor||empty($visitor['is_active'])||!is_string($s['visitor_auth_revision']??null)||!hash_equals($s['visitor_auth_revision'],hash('sha256',$visitorId.'|'.(string)$visitor['password_hash'])))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
        $q=$pdo->prepare('SELECT share_visit_identity FROM user_profiles WHERE user_id=?'.$suffix);$q->execute([$visitorId]);if(in_array($q->fetchColumn(),[1,'1',true],true)){$name=mb_substr(trim((string)$visitor['display_name']),0,100);if($name!=='')$label=$name;}
    }elseif(($s['visitor_auth_revision']??'')!=='')vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
    try{vp3_profile_call_block_check_pa3c($pdo,$owner,$visitorId,$lock);}catch(VP3ProfileCallException $e){vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);}
    $cid=(int)$item['public_conversation_id'];$conversation=null;$turn=null;$text='';
    if($cid>0){$authority=['contract'=>'vp3.profile-conversation-authority.v1','transport'=>'native','owner_user_id'=>$owner,'visitor_user_id'=>$visitorId,'credential_hash'=>$s['credential_hash']];$conversation=vp3_profile_agent_public_conversation_v390($pdo,$cid,$owner,$s['profile_agent_id'],$s['profile_session_id'],$lock,$authority);if(!$conversation)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);}
    if($item['kind']==='visitor_message'){
        if($cid<1||(int)$item['turn_id']<1||(int)$item['visitor_message_id']<1)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
        $turn=vp3_profile_handoff_row_pa3($pdo,(int)$item['turn_id'],$lock);
        foreach(['owner_user_id','profile_agent_id','profile_session_id','visitor_user_id','visitor_message_id'] as $key)if(!$turn||(int)$turn[$key]!== (int)$item[$key])vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
        if((int)$turn['conversation_id']!==$cid||$turn['transport']!=='native'||!hash_equals((string)$turn['credential_hash'],$s['credential_hash']))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
        $q=$pdo->prepare("SELECT message,sender_user_id FROM profile_agent_messages WHERE id=? AND conversation_id=? AND sender_type='visitor'".$suffix);$q->execute([(int)$item['visitor_message_id'],$cid]);$message=$q->fetch(PDO::FETCH_ASSOC);$text=(string)($message['message']??'');
        if(!$message||(int)($message['sender_user_id']??0)!==$visitorId||trim($text)===''||mb_strlen($text)>2000||!hash_equals((string)$item['source_hash'],hash('sha256',$text)))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
    }elseif($item['kind']==='arrival'){
        if(!in_array($item['arrival_context'],['page','chat'],true)||($item['arrival_context']==='chat'&&$cid<1)||(int)$item['arrival_generation']<1||(int)$item['turn_id']!==0||(int)$item['visitor_message_id']!==0||!hash_equals((string)$item['source_hash'],hash('sha256','arrival|'.$item['voice_assignment_id'].'|'.$item['arrival_generation'].'|'.$item['arrival_context'].'|'.$cid)))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
        $text=$label.($item['arrival_context']==='chat'?' is chatting with your Profile Agent.':' visited your profile.');
    }else vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
    $automatic=$item['kind']==='arrival';if($item['kind']==='visitor_message'){$thread=vp3_profile_handoff_thread_pa3($pdo,$owner,$cid,$lock);$automatic=$thread&&$thread['mode']==='owner'&&$turn['state']==='owner_claimed'&&(int)$turn['conversation_epoch']===(int)$thread['epoch'];}
    $automatic=$automatic&&(int)$item['auto_consumed']===0&&(int)$item['automatic_expires_at_ms']>vp3_profile_speech_now_pa4a()&&(int)$item['created_at_ms']<=vp3_profile_speech_now_pa4a();
    return compact('user','profile','agent','session','visitor','label','text','conversation','turn','automatic')+['text_hash'=>hash('sha256',$text)];
}
function vp3_profile_speech_scope_pa4a(array $input,bool $delivery=false): array
{
    $scope=['chat_conversation_id'=>vp3_profile_speech_id_pa4a($input['chat_conversation_id']??null),'chat_agent_id'=>vp3_profile_speech_id_pa4a($input['chat_agent_id']??0,true),'tab_id'=>vp3_profile_speech_token_pa4a($input['tab_id']??null),'client_hash'=>hash('sha256',vp3_profile_speech_token_pa4a($input['client_instance_id']??null))];
    if($delivery){$scope['chat_message_id']=vp3_profile_speech_id_pa4a($input['chat_message_id']??null);$scope['delivery_id']=vp3_profile_speech_token_pa4a($input['delivery_id']??null);}return $scope;
}
function vp3_profile_speech_owner_pa4a(PDO $pdo,array $user,array $scope,bool $voice=false,bool $lock=false): array
{
    try{$user=vp3_profile_handoff_owner_pa3($pdo,$user,(int)$scope['chat_conversation_id'],(int)$scope['chat_agent_id']);}catch(VP3ProfileHandoffException $e){vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);}
    if(!$voice)return $user;
    try{vp3_profile_handoff_tab_pa3($pdo,(int)$user['id'],$scope['tab_id'],(int)$scope['chat_conversation_id'],(int)$scope['chat_agent_id'],true);}catch(VP3ProfileHandoffException $e){vp3_profile_speech_error_pa4a('PROFILE_SPEECH_MUTED',409);}
    if(!function_exists('chat_settings_agent_voice_allowed_v237'))require_once __DIR__.'/chat-settings-v237.php';if(!chat_settings_agent_voice_allowed_v237($user))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_MUTED',403);
    try{$q=$pdo->prepare('SELECT presence_mode,social_chat_enabled,sound_enabled,agent_voice_enabled FROM team_user_presence WHERE user_id=? LIMIT 2'.($lock?vp3_profile_handoff_lock_pa3($pdo):''));$q->execute([(int)$user['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);}catch(PDOException $e){vp3_profile_speech_error_pa4a('PROFILE_SPEECH_NOT_READY',503);}
    if(count($rows)>1)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_MUTED',409);
    if($rows){if($rows[0]['presence_mode']!=='online')vp3_profile_speech_error_pa4a('PROFILE_SPEECH_MUTED',409);foreach(['social_chat_enabled','sound_enabled','agent_voice_enabled'] as $key)if(!in_array($rows[0][$key],[1,'1',true],true))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_MUTED',409);}
    if(vp3_profile_handoff_availability_pa3($pdo,(int)$user['id'],$lock)!=='available')vp3_profile_speech_error_pa4a('PROFILE_SPEECH_MUTED',409);
    if(vp3_profile_call_owner_busy_pa3c($pdo,(int)$user['id']))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_BUSY',409);return $user;
}
function vp3_profile_speech_delivery_check_pa4a(PDO $pdo,array $item,array $scope): void
{
    foreach(['chat_conversation_id','chat_agent_id','chat_message_id'] as $key)if((int)$item[$key]!== (int)($scope[$key]??-1))vp3_profile_speech_error_pa4a();
    foreach(['delivery_id','tab_id','client_hash'] as $key)if(!is_string($scope[$key]??null)||!hash_equals((string)$item[$key],$scope[$key]))vp3_profile_speech_error_pa4a();
    $q=$pdo->prepare('SELECT m.context_json,c.user_id,c.user_agent_id FROM chat_messages m INNER JOIN chat_conversations c ON c.id=m.conversation_id WHERE m.id=? AND m.conversation_id=?');$q->execute([(int)$item['chat_message_id'],(int)$item['chat_conversation_id']]);$message=$q->fetch(PDO::FETCH_ASSOC);$context=$message?json_decode((string)$message['context_json'],true):null;$marker=is_array($context)?($context['profile_speech']??null):null;
    if(!$message||(int)$message['user_id']!==(int)$item['owner_user_id']||(int)($message['user_agent_id']??0)!==(int)$item['chat_agent_id']||!is_array($marker)||($marker['contract']??null)!==VP3_PROFILE_SPEECH_PA4A||(int)($marker['item_id']??0)!==(int)$item['id']||($marker['kind']??null)!==$item['kind']||(int)($marker['public_conversation_id']??-1)!==(int)$item['public_conversation_id'])vp3_profile_speech_error_pa4a();
}
function vp3_profile_speech_projection_pa4a(PDO $pdo,array $item,array $source,array $scope): array
{
    $reason='';$can=true;try{vp3_profile_speech_provider_pa4a();vp3_profile_speech_config_valid_pa4a(vp3_profile_speech_config_pa4a());vp3_profile_speech_owner_pa4a($pdo,$source['user'],$scope,true);}catch(VP3ProfileSpeechException $e){$can=false;$reason=match($e->publicCode){'PROFILE_SPEECH_BUSY'=>'call_or_voice_busy','PROFILE_SPEECH_NOT_READY'=>'not_ready','PROFILE_SPEECH_VOICE_UNAVAILABLE'=>'voice_unavailable',default=>'muted_or_unavailable'};}
    $state='available';$q=$pdo->prepare('SELECT * FROM profile_speech_attempts_pa4a WHERE item_id=? ORDER BY attempt_no DESC LIMIT 1');$q->execute([(int)$item['id']]);$attempt=$q->fetch(PDO::FETCH_ASSOC);$playback='none';$retry=false;
    if($attempt){
        $state=match($attempt['provider_state']){'reserved','sending'=>'pending','outcome_unverified'=>'outcome_unverified','rejected','blocked'=>'blocked',default=>'available'};$playback=$attempt['playback_state'];
        // A lost terminal report must not hide manual cache replay until cron.
        // Project expiry without changing the saved permit or consumption proof.
        if(in_array($playback,['granted','playing'],true)&&(int)$attempt['play_lease_until_ms']<=vp3_profile_speech_now_pa4a())$playback='interrupted';
        $state=match($playback){'granted'=>'granted','playing'=>'playing','ended'=>'client_finished','interrupted'=>'interrupted','blocked'=>'blocked',default=>$state};
        if($attempt['provider_state']==='ready'&&((int)($attempt['audio_expires_at_ms']??0)<=vp3_profile_speech_now_pa4a()||!empty($attempt['audio_removed_at_ms'])))$state='expired';
        $retry=$can&&!in_array($attempt['provider_state'],['reserved','sending'],true)&&($state==='expired'||in_array($attempt['provider_state'],['outcome_unverified','rejected'],true)||($attempt['provider_state']==='blocked'&&$attempt['provider_attempted']!==0&&$attempt['provider_attempted']!=='0'));
    }
    $canPlay=$can&&!in_array($state,['pending','granted','playing','outcome_unverified','expired'],true)&&!$retry;
    return ['id'=>(int)$item['id'],'revision'=>(int)$item['revision'],'kind'=>$item['kind'],'public_conversation_id'=>(int)$item['public_conversation_id'],'turn_id'=>(int)$item['turn_id'],'visitor_message_id'=>(int)$item['visitor_message_id'],'visitor_label'=>$source['label'],'display_text'=>$source['text'],'voice_assignment_id'=>(int)$item['voice_assignment_id'],'voice_revision'=>(int)$item['voice_revision'],'voice_label'=>$item['kind']==='arrival'?'Agent voice':'Visitor voice','state'=>$state,'playback_state'=>$playback,'can_play'=>$canPlay,'can_retry'=>$retry,'automatic_allowed'=>$can&&$source['automatic']&&!$attempt,'reason'=>$reason,'chat_conversation_id'=>(int)$item['chat_conversation_id'],'chat_agent_id'=>(int)$item['chat_agent_id'],'chat_message_id'=>(int)$item['chat_message_id'],'delivery_id'=>(string)$item['delivery_id'],'attempt_id'=>$attempt?(int)$attempt['id']:0,'latest_attempt_id'=>$attempt?(int)$attempt['id']:null,'latest_attempt_state'=>$attempt?$attempt['provider_state']:null,'latest_attempt_reason'=>$attempt?$attempt['last_reason']:null];
}
function vp3_profile_speech_state_pa4a(PDO $pdo,array $user,array $input): array
{
    vp3_profile_speech_require_pa4a($pdo);$scope=vp3_profile_speech_scope_pa4a($input);$user=vp3_profile_speech_owner_pa4a($pdo,$user,$scope);$owner=(int)$user['id'];$public=vp3_profile_speech_id_pa4a($input['public_conversation_id']??0,true);$params=[$owner,$scope['chat_conversation_id'],$scope['chat_agent_id']];$where='';if($public>0){try{vp3_profile_handoff_conversation_pa3($pdo,$owner,$public);}catch(VP3ProfileHandoffException $e){vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);}$where=' AND public_conversation_id=?';$params[]=$public;}
    $q=$pdo->prepare('SELECT * FROM profile_speech_items_pa4a WHERE owner_user_id=? AND (chat_message_id=0 OR (chat_conversation_id=? AND chat_agent_id=?))'.$where.' ORDER BY id DESC LIMIT 40');$q->execute($params);$candidates=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $item){try{$source=vp3_profile_speech_source_pa4a($pdo,$item);if($item['kind']==='visitor_message'&&!$source['automatic']&&(int)$item['chat_message_id']===0&&$public<1)continue;$candidates[]=$item;}catch(VP3ProfileSpeechException $e){continue;}}
    $items=[];foreach($candidates as $item){try{$fresh=vp3_profile_speech_item_pa4a($pdo,(int)$item['id']);if(!$fresh||!hash_equals($fresh['binding_hash'],$item['binding_hash']))continue;$source=vp3_profile_speech_source_pa4a($pdo,$fresh);$projection=vp3_profile_speech_projection_pa4a($pdo,$fresh,$source,$scope);$final=vp3_profile_speech_source_pa4a($pdo,$fresh);if(!hash_equals($source['text_hash'],$final['text_hash']))continue;$items[]=$projection;}catch(VP3ProfileSpeechException $e){continue;}}
    vp3_profile_speech_owner_pa4a($pdo,$user,$scope);return ['contract'=>VP3_PROFILE_SPEECH_PA4A,'items'=>array_reverse($items),'ready'=>true,'reason'=>'','server_now_ms'=>vp3_profile_speech_now_pa4a()];
}
function vp3_profile_speech_delivery_pa4a(PDO $pdo,array $user,array $input): array
{
    vp3_profile_speech_require_pa4a($pdo);$scope=vp3_profile_speech_scope_pa4a($input);$id=vp3_profile_speech_id_pa4a($input['item_id']??null);$revision=vp3_profile_speech_id_pa4a($input['revision']??null);$user=vp3_profile_speech_owner_pa4a($pdo,$user,$scope);$initial=vp3_profile_speech_item_pa4a($pdo,$id);if(!$initial||(int)$initial['owner_user_id']!==(int)$user['id'])vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$user,$scope,$id,$revision,$initial){
        vp3_profile_call_slot_pa3c($pdo,(int)$user['id'],true);if((int)$initial['public_conversation_id']>0)vp3_profile_handoff_conversation_pa3($pdo,(int)$user['id'],(int)$initial['public_conversation_id'],true);$source=vp3_profile_speech_source_pa4a($pdo,$initial,true);$item=vp3_profile_speech_item_pa4a($pdo,$id,true);if(!$item||(int)$item['revision']!==$revision||!hash_equals($item['binding_hash'],$initial['binding_hash']))vp3_profile_speech_error_pa4a();
        vp3_profile_speech_owner_pa4a($pdo,$user,$scope);try{vp3_profile_handoff_tab_pa3($pdo,(int)$user['id'],$scope['tab_id'],$scope['chat_conversation_id'],$scope['chat_agent_id'],true);}catch(VP3ProfileHandoffException $e){vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);}$now=vp3_profile_speech_now_pa4a();
        if((int)$item['chat_message_id']>0&&((int)$item['chat_conversation_id']!==$scope['chat_conversation_id']||(int)$item['chat_agent_id']!==$scope['chat_agent_id']))vp3_profile_speech_error_pa4a();
        if((int)$item['chat_message_id']<1){$marker=['sources'=>[],'profile_speech'=>['contract'=>VP3_PROFILE_SPEECH_PA4A,'item_id'=>$id,'revision'=>$revision,'kind'=>$item['kind'],'public_conversation_id'=>(int)$item['public_conversation_id']]];$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at) VALUES(?,NULL,'assistant',?,?,?)")->execute([$scope['chat_conversation_id'],$item['kind']==='arrival'?'Profile visitor announcement.':'Profile visitor message.',json_encode($marker,JSON_THROW_ON_ERROR),vp3_profile_handoff_date_pa3()]);$messageId=(int)$pdo->lastInsertId();}else $messageId=(int)$item['chat_message_id'];
        if($item['tab_id']!==$scope['tab_id']||$item['client_hash']!==$scope['client_hash']||$item['delivery_id']===''){
            $busy=$pdo->prepare("SELECT id FROM profile_speech_attempts_pa4a WHERE item_id=? AND ((provider_state IN ('reserved','sending') AND lease_until_ms>?) OR (playback_state IN ('granted','playing') AND play_lease_until_ms>?)) LIMIT 1");$busy->execute([$id,$now,$now]);if($busy->fetchColumn())vp3_profile_speech_error_pa4a('PROFILE_SPEECH_BUSY',409);
            $pdo->prepare('UPDATE profile_speech_items_pa4a SET delivery_id=?,chat_conversation_id=?,chat_agent_id=?,chat_message_id=?,tab_id=?,client_hash=?,revision=revision+1,updated_at_ms=? WHERE id=?')->execute([bin2hex(random_bytes(16)),$scope['chat_conversation_id'],$scope['chat_agent_id'],$messageId,$scope['tab_id'],$scope['client_hash'],$now,$id]);
        }
        $item=vp3_profile_speech_item_pa4a($pdo,$id,true);$bound=$scope+['chat_message_id'=>$messageId,'delivery_id'=>$item['delivery_id']];vp3_profile_speech_delivery_check_pa4a($pdo,$item,$bound);$source=vp3_profile_speech_source_pa4a($pdo,$item,true);vp3_profile_speech_owner_pa4a($pdo,$user,$scope);
        return ['contract'=>VP3_PROFILE_SPEECH_PA4A,'item'=>vp3_profile_speech_projection_pa4a($pdo,$item,$source,$scope),'delivery_id'=>$item['delivery_id'],'chat_conversation_id'=>$scope['chat_conversation_id'],'chat_agent_id'=>$scope['chat_agent_id'],'chat_message_id'=>$messageId,'server_now_ms'=>$now];
    });
}

function vp3_profile_speech_catalog_rows_pa4a(array $catalog): array
{
    if(($catalog['ready']??false)!==true||!is_array($catalog['voices']??null)||count($catalog['voices'])>1000)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',503);$rows=[];
    foreach($catalog['voices'] as $row){
        if(!is_array($row)||!preg_match('/^[a-f0-9]{64}$/D',(string)($row['preset_key']??''))||!preg_match('/^[A-Za-z0-9_-]{8,128}$/D',(string)($row['voice_id']??''))||!preg_match('/^[a-f0-9]{64}$/D',(string)($row['origin_hash']??''))||!in_array($row['style']??null,['male','female','neutral','unclassified'],true)||!in_array($row['provenance']??null,['provider_default','admin_public'],true))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',503);
        if(isset($rows[$row['preset_key']]))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',503);$rows[$row['preset_key']]=['preset_key'=>$row['preset_key'],'voice_id'=>$row['voice_id'],'name'=>mb_substr(trim((string)($row['name']??'Visitor voice')),0,120),'style'=>$row['style'],'provenance'=>$row['provenance'],'origin_hash'=>$row['origin_hash']];
    }ksort($rows,SORT_STRING);return $rows;
}
function vp3_profile_speech_config_valid_pa4a(array $config): void
{
    if(($config['ready']??false)!==true||!preg_match('/^[a-f0-9]{64}$/D',(string)($config['config_fingerprint']??''))||!is_string($config['model_id']??null)||$config['model_id']===''||strlen($config['model_id'])>80||!is_string($config['output_format']??null)||$config['output_format']===''||strlen($config['output_format'])>40)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',503);
}
/** Provider metadata is fetched outside all SQL locks; credential material never enters the core. */
function vp3_profile_speech_policy_pa4a(PDO $pdo,array $user,array $item,array $scope): array
{
    if($pdo->inTransaction())throw new RuntimeException('Voice catalog reads must run outside the transaction.');vp3_profile_speech_provider_pa4a();$config=vp3_profile_speech_config_pa4a();vp3_profile_speech_config_valid_pa4a($config);$catalog=vp3_profile_speech_catalog_pa4a();
    if(!hash_equals($config['config_fingerprint'],(string)($catalog['config_fingerprint']??'')))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',503);$rows=vp3_profile_speech_catalog_rows_pa4a($catalog);$ownerVoice=vp3_profile_speech_owner_voice_pa4a($pdo,$user,(int)$scope['chat_agent_id'],(int)$item['profile_agent_id']);
    if(!is_array($ownerVoice['excluded_voice_ids']??null)||count($ownerVoice['excluded_voice_ids'])>100)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',503);
    return compact('config','rows','ownerVoice');
}
function vp3_profile_speech_select_voice_pa4a(PDO $pdo,array $item,array $assignment,array $policy): array
{
    $ownerVoice=$policy['ownerVoice'];
    if($item['kind']==='arrival'){
        $voice=$ownerVoice['narrator']??null;if(!is_array($voice)||!preg_match('/^[A-Za-z0-9_-]{8,128}$/D',(string)($voice['voice_id']??''))||!preg_match('/^[a-f0-9]{64}$/D',(string)($voice['origin_hash']??''))||!in_array($voice['provenance']??null,['owner_default','owner_clone'],true))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',503);
        return ['voice_id'=>$voice['voice_id'],'name'=>mb_substr((string)($voice['name']??'Agent voice'),0,120),'provenance'=>$voice['provenance'],'origin_hash'=>$voice['origin_hash'],'role'=>'narrator','preset_key'=>''];
    }
    if(!in_array($assignment['style'],['preset','male','female','neutral'],true)||!in_array($assignment['choice_origin'],['default','visitor_choice'],true))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',503);
    $eligible=[];foreach($policy['rows'] as $key=>$candidate){if(in_array($candidate['voice_id'],$ownerVoice['excluded_voice_ids'],true))continue;if($assignment['style']!=='preset'&&$candidate['style']!==$assignment['style'])continue;if($assignment['preset_key']!==''&&!hash_equals($assignment['preset_key'],$key))continue;$eligible[$key]=$candidate;}
    if(!$eligible)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',503);$chosen=null;
    if(is_string($assignment['voice_json'])&&$assignment['voice_json']!==''){$saved=vp3_profile_speech_json_pa4a($assignment['voice_json']);$key=(string)($saved['preset_key']??'');if(isset($eligible[$key])&&($eligible[$key]['voice_id']??null)===($saved['voice_id']??null)&&($eligible[$key]['origin_hash']??null)===($saved['origin_hash']??null))$chosen=$eligible[$key];}
    if(!$chosen){$values=array_values($eligible);$chosen=$values[(int)(hexdec(substr(hash('sha256',$assignment['principal_hash']),0,8))%count($values))];}
    $chosen['role']='visitor';$serialized=json_encode($chosen,JSON_THROW_ON_ERROR);
    if($assignment['voice_json']!==$serialized){$pdo->prepare('UPDATE profile_visitor_voices_pa4a SET voice_json=?,assignment_revision=assignment_revision+1,updated_at_ms=? WHERE id=?')->execute([$serialized,vp3_profile_speech_now_pa4a(),(int)$assignment['id']]);}
    return $chosen;
}
function vp3_profile_speech_voice_current_pa4a(PDO $pdo,array $user,array $item,array $scope,array $voice,array $policy): void
{
    $config=vp3_profile_speech_config_pa4a();if(!hash_equals((string)$policy['config']['config_fingerprint'],(string)($config['config_fingerprint']??''))||($config['ready']??false)!==true)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',503);
    $current=vp3_profile_speech_owner_voice_pa4a($pdo,$user,(int)$scope['chat_agent_id'],(int)$item['profile_agent_id']);
    if(($voice['role']??null)==='narrator'){$n=$current['narrator']??null;if(!is_array($n)||($n['voice_id']??null)!==($voice['voice_id']??null)||($n['origin_hash']??null)!==($voice['origin_hash']??null)||($n['provenance']??null)!==($voice['provenance']??null))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',503);}
    else{$candidate=$policy['rows'][$voice['preset_key']??'']??null;if(!is_array($candidate)||($candidate['voice_id']??null)!==($voice['voice_id']??null)||($candidate['origin_hash']??null)!==($voice['origin_hash']??null)||in_array($voice['voice_id'],$current['excluded_voice_ids']??[],true))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',503);}
}
function vp3_profile_speech_visitor_state_pa4a(PDO $pdo,array $ctx,?callable $afterMetadata=null): array
{
    vp3_profile_speech_require_pa4a($pdo);$ctx=vp3_profile_speech_native_pa4a($pdo,$ctx);$s=vp3_profile_speech_snapshot_pa4a($ctx);$principal=hash('sha256','profile-speech|'.$s['owner_user_id'].'|'.$s['visitor_user_id'].'|'.$s['credential_hash']);$q=$pdo->prepare('SELECT * FROM profile_visitor_voices_pa4a WHERE owner_user_id=? AND principal_hash=?');$q->execute([$s['owner_user_id'],$principal]);$voice=$q->fetch(PDO::FETCH_ASSOC);$presets=[];$ready=false;$reason='voice_unavailable';
    try{vp3_profile_speech_provider_pa4a();$catalog=vp3_profile_speech_catalog_pa4a();foreach(array_slice(array_values(vp3_profile_speech_catalog_rows_pa4a($catalog)),0,100) as $candidate)$presets[]=['preset_key'=>$candidate['preset_key'],'label'=>$candidate['name'],'style'=>$candidate['style']];$ready=true;$reason='';}catch(Throwable $e){}
    if($afterMetadata)$afterMetadata();vp3_profile_speech_native_pa4a($pdo,$ctx);return ['contract'=>VP3_PROFILE_SPEECH_PA4A,'style'=>$voice?$voice['style']:'preset','preset_key'=>$voice?$voice['preset_key']:'','revision'=>$voice?(int)$voice['assignment_revision']:1,'presets'=>$presets,'ready'=>$ready,'reason'=>$reason];
}
function vp3_profile_speech_preference_pa4a(PDO $pdo,array $ctx,array $input,?callable $afterMetadata=null): array
{
    vp3_profile_speech_require_pa4a($pdo);$ctx=vp3_profile_speech_native_pa4a($pdo,$ctx);$style=$input['style']??null;$preset=$input['preset_key']??'';$revision=vp3_profile_speech_id_pa4a($input['revision']??1);
    if(!in_array($style,['preset','male','female','neutral'],true)||!is_string($preset)||($preset!==''&&!preg_match('/^[a-f0-9]{64}$/D',$preset))||($style!=='preset'&&$preset!==''))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_INVALID',422);
    vp3_profile_speech_provider_pa4a();$rows=vp3_profile_speech_catalog_rows_pa4a(vp3_profile_speech_catalog_pa4a());if($preset!==''&&!isset($rows[$preset]))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',422);if($afterMetadata)$afterMetadata();
    vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$ctx,$style,$preset,$revision){$ctx=vp3_profile_speech_native_pa4a($pdo,$ctx,true);$voice=vp3_profile_speech_assignment_pa4a($pdo,$ctx);if((int)$voice['assignment_revision']!==$revision)vp3_profile_speech_error_pa4a();if($voice['style']===$style&&$voice['preset_key']===$preset&&$voice['choice_origin']==='visitor_choice')return;$now=vp3_profile_speech_now_pa4a();
        $pdo->prepare("UPDATE profile_visitor_voices_pa4a SET style=?,preset_key=?,choice_origin='visitor_choice',voice_json=NULL,assignment_revision=assignment_revision+1,updated_at_ms=? WHERE id=?")->execute([$style,$preset,$now,(int)$voice['id']]);
        $pdo->prepare('UPDATE profile_speech_items_pa4a SET voice_revision=?,revision=revision+1,updated_at_ms=? WHERE voice_assignment_id=?')->execute([(int)$voice['assignment_revision']+1,$now,(int)$voice['id']]);
        $pdo->prepare("UPDATE profile_speech_attempts_pa4a SET delivery_state='blocked',provider_state=CASE WHEN provider_state='reserved' THEN 'blocked' ELSE provider_state END,last_reason='voice_changed',updated_at_ms=? WHERE item_id IN (SELECT id FROM profile_speech_items_pa4a WHERE voice_assignment_id=?) AND delivery_state='available'")->execute([$now,(int)$voice['id']]);vp3_profile_speech_native_pa4a($pdo,$ctx,true);
    });return vp3_profile_speech_visitor_state_pa4a($pdo,$ctx,$afterMetadata);
}
function vp3_profile_speech_claim_reply_pa4a(PDO $pdo,array $attempt,array $item,array $source,array $scope,bool $duplicate): array
{
    $ticket=null;$now=vp3_profile_speech_now_pa4a();
    if(in_array($attempt['provider_state'],['reserved','ready'],true)&&$attempt['delivery_state']==='available'&&(int)$attempt['lease_until_ms']>$now){$ticket=bin2hex(random_bytes(32));$pdo->prepare('UPDATE profile_speech_attempts_pa4a SET ticket_hash=? WHERE id=?')->execute([hash('sha256',$ticket),(int)$attempt['id']]);}
    return ['contract'=>VP3_PROFILE_SPEECH_PA4A,'item'=>vp3_profile_speech_projection_pa4a($pdo,$item,$source,$scope),'attempt_id'=>(int)$attempt['id'],'state'=>$attempt['provider_state'],'ticket'=>$ticket,'voice_revision'=>(int)$attempt['voice_revision'],'server_now_ms'=>$now,'lease_until_ms'=>(int)$attempt['lease_until_ms'],'duplicate'=>$duplicate,'retry_may_charge'=>($attempt['provider_state']==='reserved'&&empty($attempt['cache_of']))];
}
function vp3_profile_speech_claim_pa4a(PDO $pdo,array $user,array $input,bool $retry=false,?callable $afterMetadata=null): array
{
    vp3_profile_speech_require_pa4a($pdo);$scope=vp3_profile_speech_scope_pa4a($input,true);$id=vp3_profile_speech_id_pa4a($input['item_id']??null);$revision=vp3_profile_speech_id_pa4a($input['revision']??null);$request=vp3_profile_speech_token_pa4a($input['request_id']??null);$mode=$input['mode']??null;if(!in_array($mode,['automatic','manual'],true))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_INVALID',422);$retryOf=$retry?vp3_profile_speech_id_pa4a($input['retry_of']??null):0;if($retry&&($mode!=='manual'||($input['retry_confirmed']??null)!==true))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_RETRY_REQUIRED',409);
    $user=vp3_profile_speech_owner_pa4a($pdo,$user,$scope,true);$owner=(int)$user['id'];$initial=vp3_profile_speech_item_pa4a($pdo,$id);if(!$initial||(int)$initial['owner_user_id']!==$owner)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);vp3_profile_speech_source_pa4a($pdo,$initial);$policy=vp3_profile_speech_policy_pa4a($pdo,$user,$initial,$scope);if($afterMetadata)$afterMetadata();
    $hash=hash('sha256',json_encode([$owner,$id,$scope,$mode,$retryOf,$retry],JSON_THROW_ON_ERROR));vp3_profile_speech_maintenance_pa4a($pdo,20);
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$user,$owner,$scope,$id,$revision,$request,$mode,$retry,$retryOf,$initial,$policy,$hash){
        vp3_profile_call_slot_pa3c($pdo,$owner,true);if((int)$initial['public_conversation_id']>0)vp3_profile_handoff_conversation_pa3($pdo,$owner,(int)$initial['public_conversation_id'],true);$source=vp3_profile_speech_source_pa4a($pdo,$initial,true);$assignment=vp3_profile_speech_voice_row_pa4a($pdo,(int)$initial['voice_assignment_id'],true);$item=vp3_profile_speech_item_pa4a($pdo,$id,true);
        if(!$item||!$assignment||(int)$assignment['owner_user_id']!==$owner||(int)$assignment['visitor_user_id']!==(int)$item['visitor_user_id']||!hash_equals($assignment['credential_hash'],$item['credential_hash'])||!hash_equals($item['binding_hash'],$initial['binding_hash']))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
        vp3_profile_speech_owner_pa4a($pdo,$user,$scope,true,true);vp3_profile_speech_delivery_check_pa4a($pdo,$item,$scope);$now=vp3_profile_speech_now_pa4a();
        $q=$pdo->prepare('SELECT * FROM profile_speech_attempts_pa4a WHERE owner_user_id=? AND request_id=?'.vp3_profile_handoff_lock_pa3($pdo));$q->execute([$owner,$request]);$prior=$q->fetch(PDO::FETCH_ASSOC);
        if($prior){if(!hash_equals($prior['request_hash'],$hash)||(int)$prior['item_id']!==$id)vp3_profile_speech_error_pa4a();vp3_profile_speech_attempt_current_pa4a($pdo,$user,$item,$source,$scope,$prior,$policy);return vp3_profile_speech_claim_reply_pa4a($pdo,$prior,$item,$source,$scope,true);}
        if((int)$item['revision']!==$revision)vp3_profile_speech_error_pa4a();if($mode==='automatic'&&!$source['automatic'])vp3_profile_speech_error_pa4a((int)$item['auto_consumed']>0?'PROFILE_SPEECH_ALREADY_PLAYED':'PROFILE_SPEECH_UNAVAILABLE',409);
        $busy=$pdo->prepare("SELECT id FROM profile_speech_attempts_pa4a WHERE owner_user_id=? AND delivery_state='available' AND (((provider_state IN ('reserved','sending') OR (provider_state='ready' AND playback_state IN ('none','granted','playing'))) AND lease_until_ms>?) OR (playback_state IN ('granted','playing') AND play_lease_until_ms>?)) LIMIT 1".vp3_profile_handoff_lock_pa3($pdo));$busy->execute([$owner,$now,$now]);if($busy->fetchColumn())vp3_profile_speech_error_pa4a('PROFILE_SPEECH_BUSY',409);
        $voice=vp3_profile_speech_select_voice_pa4a($pdo,$item,$assignment,$policy);$assignment=vp3_profile_speech_voice_row_pa4a($pdo,(int)$assignment['id'],true);vp3_profile_speech_voice_current_pa4a($pdo,$user,$item,$scope,$voice,$policy);
        if((int)$item['voice_revision']!==(int)$assignment['assignment_revision']){$pdo->prepare('UPDATE profile_speech_items_pa4a SET voice_revision=?,revision=revision+1,updated_at_ms=? WHERE id=?')->execute([(int)$assignment['assignment_revision'],$now,$id]);$item=vp3_profile_speech_item_pa4a($pdo,$id,true);}
        $lastQ=$pdo->prepare('SELECT * FROM profile_speech_attempts_pa4a WHERE item_id=? ORDER BY attempt_no DESC LIMIT 1'.vp3_profile_handoff_lock_pa3($pdo));$lastQ->execute([$id]);$last=$lastQ->fetch(PDO::FETCH_ASSOC);$cache=null;
        if($retry){if(!$last||(int)$last['id']!==$retryOf||in_array($last['provider_state'],['reserved','sending'],true))vp3_profile_speech_error_pa4a();}
        elseif($last){
            if($mode==='automatic')vp3_profile_speech_error_pa4a('PROFILE_SPEECH_ALREADY_PLAYED',409);
            if($last['provider_state']==='ready'&&!empty($last['audio_key'])&&empty($last['audio_removed_at_ms'])&&(int)$last['audio_expires_at_ms']>$now&&hash_equals($last['text_hash'],$source['text_hash'])&&(int)$last['voice_revision']===(int)$assignment['assignment_revision']&&hash_equals($last['voice_json'],json_encode($voice,JSON_THROW_ON_ERROR))&&hash_equals($last['config_fingerprint'],$policy['config']['config_fingerprint']))$cache=$last;
            elseif(($last['provider_attempted']!==0&&$last['provider_attempted']!=='0')||in_array($last['provider_state'],['sending','outcome_unverified','rejected','ready'],true)||($mode==='automatic'&&(int)$last['attempt_no']>=3))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_RETRY_REQUIRED',409);
        }
        $number=$last?(int)$last['attempt_no']+1:1;$state=$cache?'ready':'reserved';$config=$policy['config'];$expires=$cache?min($now+VP3_PROFILE_SPEECH_ATTEMPT_MS_PA4A,(int)$cache['audio_expires_at_ms']):$now+VP3_PROFILE_SPEECH_ATTEMPT_MS_PA4A;
        $pdo->prepare('INSERT INTO profile_speech_attempts_pa4a(owner_user_id,item_id,attempt_no,request_id,request_hash,mode,retry_of,cache_of,item_revision,voice_revision,text_hash,voice_json,config_fingerprint,model_id,output_format,scope_json,ticket_hash,provider_state,lease_until_ms,provider_attempted,input_characters,created_at_ms,updated_at_ms) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$owner,$id,$number,$request,$hash,$mode,$retryOf?:null,$cache?(int)$cache['id']:null,(int)$item['revision'],(int)$assignment['assignment_revision'],$source['text_hash'],json_encode($voice,JSON_THROW_ON_ERROR),$config['config_fingerprint'],$config['model_id'],$config['output_format'],json_encode($scope,JSON_THROW_ON_ERROR),hash('sha256',bin2hex(random_bytes(32))),$state,$expires,0,mb_strlen($source['text']),$now,$now]);$attemptId=(int)$pdo->lastInsertId();
        if($cache)$pdo->prepare('UPDATE profile_speech_attempts_pa4a SET audio_key=?,audio_bytes=?,audio_sha256=?,audio_expires_at_ms=?,result_json=? WHERE id=?')->execute([$cache['audio_key'],(int)$cache['audio_bytes'],$cache['audio_sha256'],(int)$cache['audio_expires_at_ms'],json_encode(['status'=>'ready','cached'=>true,'cache_of'=>(int)$cache['id']],JSON_THROW_ON_ERROR),$attemptId]);
        $attempt=vp3_profile_speech_attempt_row_pa4a($pdo,$attemptId,true);$source=vp3_profile_speech_source_pa4a($pdo,$item,true);vp3_profile_speech_owner_pa4a($pdo,$user,$scope,true,true);return vp3_profile_speech_claim_reply_pa4a($pdo,$attempt,$item,$source,$scope,false);
    });
}

/** Validate a stored execution tuple without trusting browser text, voice, or provider state. */
function vp3_profile_speech_attempt_current_pa4a(PDO $pdo,array $user,array $item,array $source,array $scope,array $attempt,array $policy): void
{
    if((int)$attempt['owner_user_id']!==(int)$user['id']||(int)$attempt['item_id']!==(int)$item['id']||(int)$attempt['item_revision']!==(int)$item['revision']||(int)$attempt['voice_revision']!==(int)$item['voice_revision']||!hash_equals($attempt['text_hash'],$source['text_hash'])||!hash_equals($attempt['scope_json'],json_encode($scope,JSON_THROW_ON_ERROR)))vp3_profile_speech_error_pa4a();
    $assignment=vp3_profile_speech_voice_row_pa4a($pdo,(int)$item['voice_assignment_id']);
    if(!$assignment||(int)$assignment['owner_user_id']!==(int)$user['id']||(int)$assignment['assignment_revision']!==(int)$attempt['voice_revision']||(int)$assignment['visitor_user_id']!==(int)$item['visitor_user_id']||!hash_equals($assignment['credential_hash'],$item['credential_hash']))vp3_profile_speech_error_pa4a();
    $voice=vp3_profile_speech_json_pa4a($attempt['voice_json']);
    if(($voice['role']??null)!==($item['kind']==='arrival'?'narrator':'visitor')||($voice['role']==='visitor'&&!hash_equals((string)$assignment['voice_json'],$attempt['voice_json'])))vp3_profile_speech_error_pa4a();
    foreach(['config_fingerprint','model_id','output_format'] as $key)if(!is_string($policy['config'][$key]??null)||!hash_equals((string)$attempt[$key],$policy['config'][$key]))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',503);
    vp3_profile_speech_voice_current_pa4a($pdo,$user,$item,$scope,$voice,$policy);vp3_profile_speech_delivery_check_pa4a($pdo,$item,$scope);vp3_profile_speech_owner_pa4a($pdo,$user,$scope,true,$pdo->inTransaction());
}
/** Current local binding only; provider metadata is independently checked immediately before paid I/O. */
function vp3_profile_speech_local_policy_pa4a(array $attempt): array
{
    vp3_profile_speech_provider_pa4a();$config=vp3_profile_speech_config_pa4a();vp3_profile_speech_config_valid_pa4a($config);$voice=vp3_profile_speech_json_pa4a($attempt['voice_json']);$rows=[];
    if(($voice['role']??null)==='visitor')$rows[(string)($voice['preset_key']??'')]=$voice;
    return ['config'=>$config,'rows'=>$rows];
}
function vp3_profile_speech_attempt_input_pa4a(PDO $pdo,array $user,array $input): array
{
    vp3_profile_speech_require_pa4a($pdo);$scope=vp3_profile_speech_scope_pa4a($input,true);$user=vp3_profile_speech_owner_pa4a($pdo,$user,$scope,true);$id=vp3_profile_speech_id_pa4a($input['attempt_id']??null);$itemId=vp3_profile_speech_id_pa4a($input['item_id']??null);$revision=vp3_profile_speech_id_pa4a($input['revision']??null);$ticket=vp3_profile_speech_token_pa4a($input['ticket']??null,64);
    $attempt=vp3_profile_speech_attempt_row_pa4a($pdo,$id);$item=vp3_profile_speech_item_pa4a($pdo,$itemId);
    if(!$item||!$attempt||(int)$item['owner_user_id']!==(int)$user['id']||(int)$attempt['owner_user_id']!==(int)$user['id']||(int)$attempt['item_id']!==$itemId)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
    if((int)$item['revision']!==$revision||!hash_equals((string)$attempt['ticket_hash'],hash('sha256',$ticket)))vp3_profile_speech_error_pa4a();
    return compact('scope','user','item','attempt','ticket');
}
/** Caller owns the transaction; the owner call slot is the shared serialization point. */
function vp3_profile_speech_attempt_lock_pa4a(PDO $pdo,array $seed): array
{
    $initial=$seed['item'];$user=$seed['user'];vp3_profile_call_slot_pa3c($pdo,(int)$user['id'],true);
    if((int)$initial['public_conversation_id']>0)vp3_profile_handoff_conversation_pa3($pdo,(int)$user['id'],(int)$initial['public_conversation_id'],true);
    $source=vp3_profile_speech_source_pa4a($pdo,$initial,true);vp3_profile_speech_voice_row_pa4a($pdo,(int)$initial['voice_assignment_id'],true);$item=vp3_profile_speech_item_pa4a($pdo,(int)$initial['id'],true);$attempt=vp3_profile_speech_attempt_row_pa4a($pdo,(int)$seed['attempt']['id'],true);
    if(!$item||!$attempt||!hash_equals($item['binding_hash'],$initial['binding_hash'])||!hash_equals($attempt['ticket_hash'],hash('sha256',$seed['ticket'])))vp3_profile_speech_error_pa4a();
    return array_replace($seed,compact('item','attempt','source'));
}
function vp3_profile_speech_attempt_internal_pa4a(array $attempt,array $item,array $source,array $scope,bool $required): array
{
    $voice=vp3_profile_speech_json_pa4a($attempt['voice_json']);return ['attempt'=>$attempt,'attempt_id'=>(int)$attempt['id'],'provider_required'=>$required,'text'=>$source['text'],'input_characters'=>mb_strlen($source['text']),'voice_id'=>$voice['voice_id'],'origin_hash'=>$voice['origin_hash'],'provenance'=>$voice['provenance'],'preset_key'=>$voice['preset_key']??'','model_id'=>$attempt['model_id'],'output_format'=>$attempt['output_format'],'config_fingerprint'=>$attempt['config_fingerprint'],'owner_user_id'=>(int)$attempt['owner_user_id'],'chat_agent_id'=>(int)$scope['chat_agent_id'],'profile_agent_id'=>(int)$item['profile_agent_id']];
}
function vp3_profile_speech_attempt_begin_pa4a(PDO $pdo,array $user,array $input,?callable $afterMetadata=null): array
{
    $seed=vp3_profile_speech_attempt_input_pa4a($pdo,$user,$input);$policy=vp3_profile_speech_policy_pa4a($pdo,$seed['user'],$seed['item'],$seed['scope']);if($afterMetadata)$afterMetadata();
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$seed,$policy){$v=vp3_profile_speech_attempt_lock_pa4a($pdo,$seed);extract($v,EXTR_SKIP);vp3_profile_speech_attempt_current_pa4a($pdo,$user,$item,$source,$scope,$attempt,$policy);$now=vp3_profile_speech_now_pa4a();
        if($attempt['delivery_state']!=='available'||(int)$attempt['lease_until_ms']<=$now)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_RETRY_REQUIRED',409);
        $required=false;
        if($attempt['provider_state']==='reserved'){$token=bin2hex(random_bytes(16));$pdo->prepare("UPDATE profile_speech_attempts_pa4a SET provider_state='sending',provider_attempted=NULL,execution_token=?,lease_until_ms=?,updated_at_ms=? WHERE id=? AND provider_state='reserved'")->execute([$token,$now+VP3_PROFILE_SPEECH_ATTEMPT_MS_PA4A,$now,(int)$attempt['id']]);$attempt=vp3_profile_speech_attempt_row_pa4a($pdo,(int)$attempt['id'],true);$required=true;}
        elseif(!in_array($attempt['provider_state'],['sending','ready'],true))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_RETRY_REQUIRED',409);
        $source=vp3_profile_speech_source_pa4a($pdo,$item,true);vp3_profile_speech_attempt_current_pa4a($pdo,$user,$item,$source,$scope,$attempt,$policy);return vp3_profile_speech_attempt_internal_pa4a($attempt,$item,$source,$scope,$required);
    });
}
/** Original sender's read-only final preflight. It never grants a second synthesis. */
function vp3_profile_speech_attempt_recheck_pa4a(PDO $pdo,array $user,array $input,array $expected): array
{
    $seed=vp3_profile_speech_attempt_input_pa4a($pdo,$user,$input);$attempt=$seed['attempt'];
    if((int)$attempt['id']!==(int)($expected['id']??0)||$attempt['provider_state']!=='sending'||$attempt['delivery_state']!=='available'||(int)$attempt['lease_until_ms']<=vp3_profile_speech_now_pa4a()||$attempt['execution_token']===''||!hash_equals($attempt['execution_token'],(string)($expected['execution_token']??'')))vp3_profile_speech_error_pa4a();
    $source=vp3_profile_speech_source_pa4a($pdo,$seed['item']);vp3_profile_speech_attempt_current_pa4a($pdo,$seed['user'],$seed['item'],$source,$seed['scope'],$attempt,vp3_profile_speech_local_policy_pa4a($attempt));
    $source=vp3_profile_speech_source_pa4a($pdo,$seed['item']);if(!hash_equals($attempt['text_hash'],$source['text_hash']))vp3_profile_speech_error_pa4a();vp3_profile_speech_owner_pa4a($pdo,$seed['user'],$seed['scope'],true);return $attempt;
}
function vp3_profile_speech_result_pa4a(array $attempt,array $result): array
{
    $required=['status','reason','provider_attempted','attempt_id','config_fingerprint','input_characters','provider_character_cost','provider_request_id','provider_trace_id','audio_key','audio_bytes','audio_sha256','audio_expires_at_ms','content_type'];if(array_diff($required,array_keys($result))||!is_int($result['attempt_id']))throw new UnexpectedValueException('Speech provider receipt is incomplete.');
    $status=$result['status'];$attempted=$result['provider_attempted'];
    if(!in_array($status,['ready','blocked','rejected','outcome_unverified'],true)||!is_bool($attempted)||(int)($result['attempt_id']??0)!==(int)$attempt['id']||!hash_equals($attempt['config_fingerprint'],(string)($result['config_fingerprint']??''))||!is_int($result['input_characters']??null)||$result['input_characters']!==(int)$attempt['input_characters']||($result['content_type']??null)!=='audio/mpeg'||($status==='blocked'&&$attempted)||($status!=='blocked'&&!$attempted))throw new UnexpectedValueException('Speech provider receipt is invalid.');
    $reasons=['ready','provider_unavailable','configuration_changed','voice_unavailable','provider_outcome_unverified','provider_rate_limited','provider_access_denied','provider_request_rejected','audio_storage_unverified'];if(!in_array($result['reason']??null,$reasons,true))throw new UnexpectedValueException('Speech provider reason is invalid.');
    $cost=$result['provider_character_cost']??null;if($cost!==null&&(!is_string($cost)||!preg_match('/^(0|[1-9][0-9]{0,13})(\.[0-9]{1,6})?$/D',$cost)))throw new UnexpectedValueException('Speech usage proof is invalid.');
    foreach(['provider_request_id','provider_trace_id'] as $key){$value=$result[$key]??null;if($value!==null&&(!is_string($value)||$value===''||strlen($value)>128||preg_match('/[\x00-\x1f\x7f]/',$value)))throw new UnexpectedValueException('Speech provider reference is invalid.');}
    if($status==='ready'){
        foreach(['audio_key','audio_sha256'] as $key)if(!is_string($result[$key]??null)||!preg_match('/^[a-f0-9]{64}$/D',$result[$key]))throw new UnexpectedValueException('Speech audio proof is invalid.');
        if(!is_int($result['audio_bytes']??null)||$result['audio_bytes']<48||$result['audio_bytes']>4194304||!is_int($result['audio_expires_at_ms']??null)||$result['audio_expires_at_ms']<=vp3_profile_speech_now_pa4a()||$result['audio_expires_at_ms']>vp3_profile_speech_now_pa4a()+610000)throw new UnexpectedValueException('Speech audio expiry is invalid.');
    }elseif(($result['audio_key']??null)!==null||($result['audio_sha256']??null)!==null||($result['audio_expires_at_ms']??null)!==null||($result['audio_bytes']??0)!==0)throw new UnexpectedValueException('Unverified speech contains audio.');
    return array_intersect_key($result,array_flip(['status','reason','provider_attempted','attempt_id','config_fingerprint','input_characters','provider_character_cost','provider_request_id','provider_trace_id','audio_key','audio_bytes','audio_sha256','audio_expires_at_ms','content_type']));
}
/** A late source revocation blocks delivery without erasing real provider usage. */
function vp3_profile_speech_attempt_finish_pa4a(PDO $pdo,array $reserved,array $result): array
{
    $expected=$reserved['attempt']??null;if(!is_array($expected)||($expected['provider_state']??null)!=='sending'||!preg_match('/^[a-f0-9]{32}$/D',(string)($expected['execution_token']??'')))throw new UnexpectedValueException('Speech execution identity is invalid.');
    try{$result=vp3_profile_speech_result_pa4a($expected,$result);}catch(UnexpectedValueException $e){$result=['status'=>'outcome_unverified','reason'=>'provider_outcome_unverified','provider_attempted'=>true,'attempt_id'=>(int)$expected['id'],'config_fingerprint'=>$expected['config_fingerprint'],'input_characters'=>(int)$expected['input_characters'],'provider_character_cost'=>null,'provider_request_id'=>null,'provider_trace_id'=>null,'audio_key'=>null,'audio_bytes'=>0,'audio_sha256'=>null,'audio_expires_at_ms'=>null,'content_type'=>'audio/mpeg'];}
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$expected,$result){
        vp3_profile_call_slot_pa3c($pdo,(int)$expected['owner_user_id'],true);$item=vp3_profile_speech_item_pa4a($pdo,(int)$expected['item_id']);$allowed=true;$reason=$result['reason'];$scope=vp3_profile_speech_json_pa4a($expected['scope_json']);
        try{if(!$item)vp3_profile_speech_error_pa4a();if((int)$item['public_conversation_id']>0)vp3_profile_handoff_conversation_pa3($pdo,(int)$expected['owner_user_id'],(int)$item['public_conversation_id'],true);$source=vp3_profile_speech_source_pa4a($pdo,$item,true);vp3_profile_speech_voice_row_pa4a($pdo,(int)$item['voice_assignment_id'],true);$item=vp3_profile_speech_item_pa4a($pdo,(int)$item['id'],true);vp3_profile_speech_attempt_current_pa4a($pdo,$source['user'],$item,$source,$scope,$expected,vp3_profile_speech_local_policy_pa4a($expected));}
        catch(VP3ProfileSpeechException|VP3ProfileHandoffException|VP3ProfileCallException|VP3ProfileSpeechProviderException $e){$allowed=false;$reason='authority_changed';}
        $attempt=vp3_profile_speech_attempt_row_pa4a($pdo,(int)$expected['id'],true);if(!$attempt||!hash_equals((string)$attempt['execution_token'],$expected['execution_token'])||(int)$attempt['item_id']!==(int)$expected['item_id']||!hash_equals($attempt['request_hash'],$expected['request_hash']))throw new RuntimeException('Speech completion fence changed.');
        if(!in_array($attempt['provider_state'],['sending','outcome_unverified'],true))return ['attempt_id'=>(int)$attempt['id'],'state'=>$attempt['provider_state'],'duplicate'=>true];
        $now=vp3_profile_speech_now_pa4a();$allowed=$allowed&&$attempt['provider_state']==='sending'&&$attempt['delivery_state']==='available'&&(int)$attempt['lease_until_ms']>$now&&$result['status']==='ready';
        $pdo->prepare('UPDATE profile_speech_attempts_pa4a SET provider_state=?,provider_attempted=?,provider_character_cost=?,provider_request_id=?,provider_trace_id=?,result_json=?,audio_key=?,audio_bytes=?,audio_sha256=?,audio_expires_at_ms=?,delivery_state=?,last_reason=?,updated_at_ms=? WHERE id=?')->execute([$result['status'],$result['provider_attempted']?1:0,$result['provider_character_cost'],$result['provider_request_id'],$result['provider_trace_id'],json_encode($result,JSON_THROW_ON_ERROR),$result['audio_key'],$result['audio_bytes'],$result['audio_sha256'],$result['audio_expires_at_ms'],$allowed?'available':'blocked',$reason,$now,(int)$attempt['id']]);
        if($allowed){try{$final=vp3_profile_speech_source_pa4a($pdo,$item,true);vp3_profile_speech_attempt_current_pa4a($pdo,$final['user'],$item,$final,$scope,$attempt,vp3_profile_speech_local_policy_pa4a($attempt));}catch(VP3ProfileSpeechException|VP3ProfileHandoffException|VP3ProfileCallException|VP3ProfileSpeechProviderException $e){$allowed=false;$pdo->prepare("UPDATE profile_speech_attempts_pa4a SET delivery_state='blocked',last_reason='authority_changed' WHERE id=?")->execute([(int)$attempt['id']]);}}
        return ['attempt_id'=>(int)$attempt['id'],'state'=>$result['status'],'delivery_allowed'=>$allowed,'duplicate'=>false];
    });
}
function vp3_profile_speech_audio_authorize_pa4a(PDO $pdo,array $user,array $input): array
{
    $seed=vp3_profile_speech_attempt_input_pa4a($pdo,$user,$input);$attempt=$seed['attempt'];$item=$seed['item'];$source=vp3_profile_speech_source_pa4a($pdo,$item);$policy=vp3_profile_speech_policy_pa4a($pdo,$seed['user'],$item,$seed['scope']);vp3_profile_speech_attempt_current_pa4a($pdo,$seed['user'],$item,$source,$seed['scope'],$attempt,$policy);
    if($attempt['provider_state']!=='ready'||$attempt['delivery_state']!=='available')vp3_profile_speech_error_pa4a('PROFILE_SPEECH_RETRY_REQUIRED',409);
    if((int)$attempt['lease_until_ms']<=vp3_profile_speech_now_pa4a()||empty($attempt['audio_key'])||!empty($attempt['audio_removed_at_ms'])||(int)$attempt['audio_expires_at_ms']<=vp3_profile_speech_now_pa4a())vp3_profile_speech_error_pa4a('PROFILE_SPEECH_AUDIO_EXPIRED',409);
    $fresh=vp3_profile_speech_attempt_row_pa4a($pdo,(int)$attempt['id']);$item=vp3_profile_speech_item_pa4a($pdo,(int)$item['id']);if(!$fresh||!$item||!hash_equals($fresh['ticket_hash'],$attempt['ticket_hash'])||$fresh['delivery_state']!=='available'||$fresh['audio_key']!==$attempt['audio_key'])vp3_profile_speech_error_pa4a();$source=vp3_profile_speech_source_pa4a($pdo,$item);vp3_profile_speech_attempt_current_pa4a($pdo,$seed['user'],$item,$source,$seed['scope'],$fresh,$policy);return $fresh;
}

function vp3_profile_speech_play_pa4a(PDO $pdo,array $user,array $input,?callable $afterMetadata=null): array
{
    $seed=vp3_profile_speech_attempt_input_pa4a($pdo,$user,$input);$request=vp3_profile_speech_token_pa4a($input['request_id']??null);vp3_profile_speech_audio_authorize_pa4a($pdo,$user,$input);if($afterMetadata)$afterMetadata();
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$seed,$request){$v=vp3_profile_speech_attempt_lock_pa4a($pdo,$seed);extract($v,EXTR_SKIP);$policy=vp3_profile_speech_local_policy_pa4a($attempt);vp3_profile_speech_attempt_current_pa4a($pdo,$user,$item,$source,$scope,$attempt,$policy);$now=vp3_profile_speech_now_pa4a();
        if($attempt['provider_state']!=='ready'||$attempt['delivery_state']!=='available'||(int)$attempt['audio_expires_at_ms']<=$now)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_AUDIO_EXPIRED',409);
        if($attempt['play_request_id']!==''){
            if(!hash_equals($attempt['play_request_id'],$request)||!in_array($attempt['playback_state'],['granted','playing'],true)||(int)$attempt['play_lease_until_ms']<=$now)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_ALREADY_PLAYED',409);
            return ['contract'=>VP3_PROFILE_SPEECH_PA4A,'attempt_id'=>(int)$attempt['id'],'play_token'=>$attempt['play_token'],'server_now_ms'=>$now,'lease_until_ms'=>(int)$attempt['play_lease_until_ms'],'absolute_until_ms'=>(int)$attempt['play_absolute_until_ms'],'report_sequence'=>(int)$attempt['report_sequence'],'granted'=>true,'duplicate'=>true];
        }
        if($attempt['mode']==='automatic'&&((int)$item['auto_consumed']!==0||!$source['automatic']))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_ALREADY_PLAYED',409);
        $q=$pdo->prepare("SELECT id FROM profile_speech_attempts_pa4a WHERE owner_user_id=? AND id<>? AND playback_state IN ('granted','playing') AND play_lease_until_ms>? LIMIT 1".vp3_profile_handoff_lock_pa3($pdo));$q->execute([(int)$user['id'],(int)$attempt['id'],$now]);if($q->fetchColumn())vp3_profile_speech_error_pa4a('PROFILE_SPEECH_BUSY',409);
        $token=bin2hex(random_bytes(16));$absolute=min((int)$attempt['audio_expires_at_ms'],$now+600000);$until=min($now+VP3_PROFILE_SPEECH_PLAY_MS_PA4A,$absolute);
        $pdo->prepare("UPDATE profile_speech_attempts_pa4a SET play_request_id=?,play_token=?,playback_state='granted',play_lease_until_ms=?,play_started_at_ms=?,play_absolute_until_ms=?,report_sequence=0,updated_at_ms=? WHERE id=?")->execute([$request,$token,$until,$now,$absolute,$now,(int)$attempt['id']]);
        $pdo->prepare('UPDATE profile_speech_items_pa4a SET auto_consumed=1,updated_at_ms=? WHERE id=?')->execute([$now,(int)$item['id']]);
        $source=vp3_profile_speech_source_pa4a($pdo,$item,true);vp3_profile_speech_attempt_current_pa4a($pdo,$user,$item,$source,$scope,$attempt,$policy);
        return ['contract'=>VP3_PROFILE_SPEECH_PA4A,'attempt_id'=>(int)$attempt['id'],'play_token'=>$token,'server_now_ms'=>$now,'lease_until_ms'=>$until,'absolute_until_ms'=>$absolute,'report_sequence'=>0,'granted'=>true,'duplicate'=>false];
    });
}
/** A browser report proves only what that exact client reports, never that a person heard audio. */
function vp3_profile_speech_report_pa4a(PDO $pdo,array $user,array $input): array
{
    $seed=vp3_profile_speech_attempt_input_pa4a($pdo,$user,$input);$status=$input['status']??null;$token=vp3_profile_speech_token_pa4a($input['play_token']??null);vp3_profile_speech_token_pa4a($input['request_id']??null);
    if(!in_array($status,['blocked','playing','ended','interrupted'],true))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_INVALID',422);$sequence=$status==='playing'?vp3_profile_speech_id_pa4a($input['report_sequence']??null):0;
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$seed,$status,$token,$sequence){$v=vp3_profile_speech_attempt_lock_pa4a($pdo,$seed);extract($v,EXTR_SKIP);$policy=vp3_profile_speech_local_policy_pa4a($attempt);vp3_profile_speech_attempt_current_pa4a($pdo,$user,$item,$source,$scope,$attempt,$policy);$now=vp3_profile_speech_now_pa4a();
        if($attempt['play_token']===''||!hash_equals($attempt['play_token'],$token)||$attempt['provider_state']!=='ready'||$attempt['delivery_state']!=='available')vp3_profile_speech_error_pa4a();
        if(in_array($attempt['playback_state'],['ended','blocked','interrupted'],true)){
            if($status!==$attempt['playback_state'])vp3_profile_speech_error_pa4a('PROFILE_SPEECH_ALREADY_PLAYED',409);
        }elseif(!in_array($attempt['playback_state'],['granted','playing'],true)||(int)$attempt['play_lease_until_ms']<=$now||(int)$attempt['play_absolute_until_ms']<=$now)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_ALREADY_PLAYED',409);
        elseif($status==='playing'){
            if($sequence<(int)$attempt['report_sequence'])vp3_profile_speech_error_pa4a();
            if($sequence>(int)$attempt['report_sequence']){$until=min($now+VP3_PROFILE_SPEECH_PLAY_MS_PA4A,(int)$attempt['play_absolute_until_ms'],(int)$attempt['audio_expires_at_ms']);$pdo->prepare("UPDATE profile_speech_attempts_pa4a SET playback_state='playing',report_sequence=?,play_lease_until_ms=?,updated_at_ms=? WHERE id=?")->execute([$sequence,$until,$now,(int)$attempt['id']]);}
        }else{$pdo->prepare('UPDATE profile_speech_attempts_pa4a SET playback_state=?,play_lease_until_ms=0,updated_at_ms=? WHERE id=?')->execute([$status,$now,(int)$attempt['id']]);}
        $attempt=vp3_profile_speech_attempt_row_pa4a($pdo,(int)$attempt['id'],true);$source=vp3_profile_speech_source_pa4a($pdo,$item,true);vp3_profile_speech_attempt_current_pa4a($pdo,$user,$item,$source,$scope,$attempt,$policy);
        return ['contract'=>VP3_PROFILE_SPEECH_PA4A,'attempt_id'=>(int)$attempt['id'],'state'=>$attempt['playback_state'],'client_reported'=>true,'server_now_ms'=>$now,'lease_until_ms'=>(int)$attempt['play_lease_until_ms'],'absolute_until_ms'=>(int)$attempt['play_absolute_until_ms'],'report_sequence'=>(int)$attempt['report_sequence']];
    });
}
/** Runs under the canonical call-owner slot lock; paid I/O evidence is never discarded. */
function vp3_profile_speech_interrupt_pa4a(PDO $pdo,int $ownerId,string $reason): void
{
    if(!vp3_profile_speech_schema_ready_pa4a($pdo))return;if(!$pdo->inTransaction())throw new RuntimeException('Speech interruption requires the call transaction.');if($ownerId<1||!in_array($reason,['call_active','settings_changed','authority_changed'],true))throw new InvalidArgumentException('Speech interruption reason is invalid.');
    $now=vp3_profile_speech_now_pa4a();$pdo->prepare("UPDATE profile_speech_items_pa4a SET auto_consumed=1,updated_at_ms=? WHERE owner_user_id=? AND id IN (SELECT item_id FROM profile_speech_attempts_pa4a WHERE owner_user_id=? AND delivery_state='available')")->execute([$now,$ownerId,$ownerId]);
    $pdo->prepare("UPDATE profile_speech_attempts_pa4a SET provider_state=CASE WHEN provider_state='reserved' THEN 'blocked' ELSE provider_state END,delivery_state='blocked',playback_state=CASE WHEN playback_state IN ('granted','playing') THEN 'interrupted' ELSE playback_state END,play_lease_until_ms=0,last_reason=?,updated_at_ms=? WHERE owner_user_id=? AND delivery_state='available'")->execute([$reason,$now,$ownerId]);
}
