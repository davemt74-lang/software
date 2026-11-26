<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

/** Owner phone workspace. A subscription alone never grants an assigned number. */
require_once __DIR__.'/phone-cloud-v100.php';
require_once __DIR__.'/phone-sms-v110.php';
require_once __DIR__.'/phone-caller-contacts-v1.php';
require_once __DIR__.'/phone-caller-contacts-schema-v1.php';
require_once __DIR__.'/agent-operator-auto-call-v1.php';
require_once __DIR__.'/agent-operator-live-schema-v1.php';
require_once __DIR__.'/agent-operator-owner-phone-chat-v1.php';
require_once __DIR__.'/agent-operator-connected-call-v1.php';
require_once __DIR__.'/phone-managed-control-schema-v1.php';
require_once __DIR__.'/phone-managed-recording-schema-v1.php';
require_once __DIR__.'/phone-native-dialthrough-schema-v1.php';
require_once __DIR__.'/phone-voicemail-v1.php';
require_once __DIR__.'/phone-call-companion-v1.php';
require_once __DIR__.'/agent-operator-admission-schema-v1.php';

final class VP3PhoneWorkspaceException extends RuntimeException
{
    public function __construct(public readonly string $publicCode,string $message,public readonly int $httpStatus=409){parent::__construct($message,$httpStatus);}
}
function vp3_phone_workspace_error_v1(string $code='phone_unavailable',int $status=403,string $message='Phone access is unavailable for this account.'): never
{throw new VP3PhoneWorkspaceException($code,$message,$status);}
function vp3_phone_workspace_lock_v1(PDO $pdo): string{return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
function vp3_phone_workspace_atomic_v1(PDO $pdo,callable $fn): mixed
{
    if(vp3_phone_transaction_active_v1($pdo))return $fn();
    $pdo->beginTransaction();try{$out=$fn();$pdo->commit();return $out;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function vp3_phone_workspace_id_v1(mixed $value,bool $zero=false): int
{
    if(!is_int($value)&&!(is_string($value)&&preg_match('/^(0|[1-9][0-9]{0,14})$/D',$value)))vp3_phone_workspace_error_v1('invalid_phone_request',422,'Choose a valid phone record.');
    $id=(int)$value;if($id<($zero?0:1)||$id>9007199254740991)vp3_phone_workspace_error_v1('invalid_phone_request',422,'Choose a valid phone record.');return $id;
}
function vp3_phone_workspace_schema_ready_v1(PDO $pdo): bool
{
    try{
        if(!vp3_phone_sms_assignment_schema_ready_v111($pdo))return false;
        require_once __DIR__.'/phone-managed-callback-schema-v1.php';
        if(!vp3_managed_callback_ready_v1($pdo))return false;
        require_once __DIR__.'/phone-managed-transport-schema-v1.php';
        if(!vp3_managed_transport_ready_v1($pdo))return false;
        $pdo->query('SELECT id,public_id,owner_user_id,agent_id,phone_number_id,phone_e164,peer_e164,direction,voice_call_id,provider_call_sid,provider_child_sid,intent_hash,intent_expires_epoch,intent_consumed_epoch,conversation_id,chat_agent_id,chat_message_id,state,recording_enabled,duration_seconds,created_epoch,updated_epoch,ended_epoch,fallback_claimed_epoch,owner_phone_revision,owner_test_received_epoch,paired_device_id,paired_profile_id,paired_profile_revision,paired_phone_revision,transport_kind,managed_parent_kind FROM phone_browser_calls_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,agent_id,selected_number_id FROM phone_browser_preferences_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,contact_id FROM phone_browser_favorites_v1 WHERE 1=0');
        $pdo->query('SELECT phone_number_id,owner_user_id,agent_id,browser_enabled,recording_enabled FROM phone_browser_numbers_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,last_epoch,conversation_id,chat_agent_id FROM phone_browser_presence_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,event_key,chat_message_id,conversation_id,chat_agent_id FROM phone_browser_deliveries_v1 WHERE 1=0');return true;
    }catch(PDOException){return false;}
}
function vp3_phone_workspace_ensure_schema_v1(PDO $pdo): void
{
    if(vp3_phone_transaction_active_v1($pdo))throw new RuntimeException('Phone schema must initialize outside a transaction.');
    require_once __DIR__.'/phone-managed-callback-schema-v1.php';
    vp3_managed_callback_install_v1($pdo);
    require_once __DIR__.'/phone-managed-transport-schema-v1.php';
    vp3_managed_transport_install_v1($pdo);
    require_once __DIR__.'/phone-managed-control-schema-v1.php';
    vp3_managed_control_install_v1($pdo);
    vp3_managed_recording_install_v1($pdo);
    vp3_native_dialthrough_install_v1($pdo);
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';$auto=$sqlite?'INTEGER PRIMARY KEY AUTOINCREMENT':'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';$suffix=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_browser_calls_v1(id $auto,public_id CHAR(32) NOT NULL UNIQUE,
        owner_user_id BIGINT NOT NULL,agent_id BIGINT NOT NULL,phone_number_id BIGINT NOT NULL,phone_e164 VARCHAR(20) NOT NULL,peer_e164 VARCHAR(20) NOT NULL DEFAULT '',
        direction VARCHAR(12) NOT NULL,voice_call_id BIGINT NULL,provider_call_sid VARCHAR(40) NULL UNIQUE,provider_child_sid VARCHAR(40) NULL UNIQUE,
        intent_hash CHAR(64) NULL UNIQUE,intent_expires_epoch BIGINT NOT NULL DEFAULT 0,intent_consumed_epoch BIGINT NOT NULL DEFAULT 0,
        conversation_id BIGINT NULL,chat_agent_id BIGINT NOT NULL DEFAULT 0,chat_message_id BIGINT NULL,state VARCHAR(24) NOT NULL,
        recording_enabled SMALLINT NOT NULL DEFAULT 0,duration_seconds INTEGER NOT NULL DEFAULT 0,created_epoch BIGINT NOT NULL,updated_epoch BIGINT NOT NULL,ended_epoch BIGINT NULL,fallback_claimed_epoch BIGINT NOT NULL DEFAULT 0,owner_phone_revision BIGINT NOT NULL DEFAULT 0,owner_test_received_epoch BIGINT NOT NULL DEFAULT 0,paired_device_id CHAR(32) NULL,paired_profile_id CHAR(32) NULL,paired_profile_revision BIGINT NOT NULL DEFAULT 0,paired_phone_revision BIGINT NOT NULL DEFAULT 0)".$suffix);
    foreach(['incoming_choice'=>"VARCHAR(12) NOT NULL DEFAULT ''",'owner_phone_revision'=>'BIGINT NOT NULL DEFAULT 0','owner_test_received_epoch'=>'BIGINT NOT NULL DEFAULT 0',
        'paired_device_id'=>'CHAR(32) NULL','paired_profile_id'=>'CHAR(32) NULL','paired_profile_revision'=>'BIGINT NOT NULL DEFAULT 0','paired_phone_revision'=>'BIGINT NOT NULL DEFAULT 0','transport_kind'=>"VARCHAR(24) NOT NULL DEFAULT 'browser'",'managed_parent_kind'=>"VARCHAR(16) NOT NULL DEFAULT 'callback'"] as $field=>$definition){
        try{$pdo->query('SELECT '.$field.' FROM phone_browser_calls_v1 WHERE 1=0');}catch(PDOException){$pdo->exec('ALTER TABLE phone_browser_calls_v1 ADD COLUMN '.$field.' '.$definition);}
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS phone_browser_preferences_v1(owner_user_id BIGINT NOT NULL,agent_id BIGINT NOT NULL,selected_number_id BIGINT NOT NULL,PRIMARY KEY(owner_user_id,agent_id))'.$suffix);
    $pdo->exec('CREATE TABLE IF NOT EXISTS phone_browser_favorites_v1(owner_user_id BIGINT NOT NULL,contact_id BIGINT NOT NULL,PRIMARY KEY(owner_user_id,contact_id))'.$suffix);
    $pdo->exec('CREATE TABLE IF NOT EXISTS phone_browser_numbers_v1(phone_number_id BIGINT PRIMARY KEY,owner_user_id BIGINT NOT NULL,agent_id BIGINT NOT NULL,browser_enabled SMALLINT NOT NULL DEFAULT 0,recording_enabled SMALLINT NOT NULL DEFAULT 0)'.$suffix);
    $pdo->exec('CREATE TABLE IF NOT EXISTS phone_browser_presence_v1(owner_user_id BIGINT PRIMARY KEY,last_epoch BIGINT NOT NULL,conversation_id BIGINT NULL,chat_agent_id BIGINT NOT NULL DEFAULT 0)'.$suffix);
    $pdo->exec('CREATE TABLE IF NOT EXISTS phone_browser_deliveries_v1(owner_user_id BIGINT NOT NULL,event_key VARCHAR(150) NOT NULL,chat_message_id BIGINT NOT NULL,conversation_id BIGINT NOT NULL,chat_agent_id BIGINT NOT NULL,PRIMARY KEY(owner_user_id,event_key))'.$suffix);
    foreach(['idx_phone_browser_owner_recent_v1'=>'owner_user_id,id','idx_phone_browser_owner_state_v1'=>'owner_user_id,state','idx_phone_browser_owner_created_v1'=>'owner_user_id,created_epoch','idx_phone_browser_connected_profile_v1'=>'owner_user_id,paired_profile_id,created_epoch'] as $name=>$columns){
        if($sqlite)$pdo->exec('CREATE INDEX IF NOT EXISTS '.$name.' ON phone_browser_calls_v1('.$columns.')');
        else{$q=$pdo->prepare('SHOW INDEX FROM phone_browser_calls_v1 WHERE Key_name=?');$q->execute([$name]);if(!$q->fetch())$pdo->exec('CREATE INDEX '.$name.' ON phone_browser_calls_v1('.$columns.')');}
    }
}
function vp3_phone_workspace_live_user_v1(PDO $pdo,array $user): array
{
    $id=(int)($user['id']??0);if($id<1)vp3_phone_workspace_error_v1();
    $q=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');$q->execute([$id]);$live=$q->fetch(PDO::FETCH_ASSOC);
    if(!$live||empty($live['is_active'])||!has_permission('account.access',$live)||!has_permission('chat.access',$live))vp3_phone_workspace_error_v1();return $live;
}
function vp3_phone_workspace_available_v1(PDO $pdo,array $user): bool
{
    try{
        $user=vp3_phone_workspace_live_user_v1($pdo,$user);
        if(!vp3_phone_package_v100($pdo,(int)$user['id'])['included'])return false;
        $q=$pdo->prepare("SELECT n.id FROM phone_cloud_numbers_v100 n JOIN user_agents a ON a.id=n.agent_id AND a.owner_user_id=n.owner_user_id AND a.is_active=1 WHERE n.owner_user_id=? AND n.provider='twilio' LIMIT 1");
        $q->execute([(int)$user['id']]);return (bool)$q->fetchColumn();
    }catch(VP3PhoneWorkspaceException|PDOException){return false;}
}
function vp3_phone_workspace_scope_v1(PDO $pdo,array $user,array $input=[]): array
{
    $user=vp3_phone_workspace_live_user_v1($pdo,$user);
    if(!vp3_phone_workspace_available_v1($pdo,$user))vp3_phone_workspace_error_v1('phone_assignment_required',403,'An administrator must assign an active Agent phone number to your account.');
    if(!vp3_phone_workspace_schema_ready_v1($pdo))vp3_phone_workspace_error_v1('phone_upgrade_required',503,'Phone requires the Cloud database upgrade.');
    $aid=vp3_phone_workspace_id_v1($input['agent_id']??0,true);$cid=vp3_phone_workspace_id_v1($input['conversation_id']??0,true);
    if($aid>0&&!vp3_phone_agent_owned_v100($pdo,(int)$user['id'],$aid))vp3_phone_workspace_error_v1('phone_scope_changed');
    if($cid>0){$q=$pdo->prepare('SELECT user_id,user_agent_id FROM chat_conversations WHERE id=? LIMIT 1');$q->execute([$cid]);$c=$q->fetch(PDO::FETCH_ASSOC);
        if(!$c||(int)$c['user_id']!==(int)$user['id']||(int)($c['user_agent_id']??0)!==$aid)vp3_phone_workspace_error_v1('phone_conversation_changed',403,'Open an authorized Chat conversation.');}
    return ['user'=>$user,'owner_user_id'=>(int)$user['id'],'agent_id'=>$aid,'conversation_id'=>$cid];
}
function vp3_phone_workspace_numbers_v1(PDO $pdo,array $scope): array
{
    $sql="SELECT n.id,n.phone_e164,n.agent_id,n.status,n.voice_enabled,a.display_name AS agent_name,
        COALESCE(b.browser_enabled,0) AS browser_enabled,COALESCE(b.recording_enabled,0) AS recording_enabled
        FROM phone_cloud_numbers_v100 n JOIN user_agents a ON a.id=n.agent_id AND a.owner_user_id=n.owner_user_id AND a.is_active=1
        LEFT JOIN phone_browser_numbers_v1 b ON b.phone_number_id=n.id AND b.owner_user_id=n.owner_user_id AND b.agent_id=n.agent_id
        WHERE n.owner_user_id=? AND n.provider='twilio'";$args=[$scope['owner_user_id']];
    if($scope['agent_id']>0){$sql.=' AND n.agent_id=?';$args[]=$scope['agent_id'];}$q=$pdo->prepare($sql.' ORDER BY n.id LIMIT 50');$q->execute($args);
    return array_map(static function(array $n):array{foreach(['id','agent_id'] as $key)$n[$key]=(int)$n[$key];foreach(['voice_enabled','browser_enabled','recording_enabled'] as $key)$n[$key]=(bool)$n[$key];return $n;},$q->fetchAll(PDO::FETCH_ASSOC));
}
function vp3_phone_workspace_number_v1(PDO $pdo,array $scope,int $numberId,bool $voice=false,bool $lock=false): array
{
    $q=$pdo->prepare("SELECT n.* FROM phone_cloud_numbers_v100 n JOIN user_agents a ON a.id=n.agent_id AND a.owner_user_id=n.owner_user_id AND a.is_active=1 WHERE n.id=? AND n.owner_user_id=? AND n.provider='twilio' LIMIT 1".($lock?vp3_phone_workspace_lock_v1($pdo):''));
    $q->execute([$numberId,$scope['owner_user_id']]);$n=$q->fetch(PDO::FETCH_ASSOC);
    if(!$n||($scope['agent_id']>0&&(int)$n['agent_id']!==$scope['agent_id']))vp3_phone_workspace_error_v1('phone_number_unavailable',404,'This assigned phone number is unavailable.');
    if($voice&&(!(int)$n['voice_enabled']||!vp3_phone_package_v100($pdo,$scope['owner_user_id'])['voice']))vp3_phone_workspace_error_v1('phone_voice_unavailable',409,'Incoming voice and the voice allowance must be enabled for this assigned number.');return $n;
}
function vp3_phone_workspace_selected_v1(PDO $pdo,array $scope,array $numbers): int
{
    $q=$pdo->prepare('SELECT selected_number_id FROM phone_browser_preferences_v1 WHERE owner_user_id=? AND agent_id=?');$q->execute([$scope['owner_user_id'],$scope['agent_id']]);$selected=(int)$q->fetchColumn();
    return in_array($selected,array_column($numbers,'id'),true)?$selected:(int)($numbers[0]['id']??0);
}
function vp3_phone_workspace_contacts_v1(PDO $pdo,int $owner): array
{
    if(!vp3_phone_caller_contacts_table_v1($pdo,'crm_contacts'))return [];
    $relationship=vp3_phone_contact_details_schema_ready_v1($pdo)?'c.relationship':"'' AS relationship";
    $q=$pdo->prepare("SELECT c.id,c.name,c.phone,$relationship,CASE WHEN f.contact_id IS NULL THEN 0 ELSE 1 END AS favorite FROM crm_contacts c LEFT JOIN phone_browser_favorites_v1 f ON f.contact_id=c.id AND f.owner_user_id=c.owner_user_id WHERE c.owner_user_id=? AND c.status<>'archived' AND c.phone<>'' ORDER BY c.name,c.id LIMIT 250");$q->execute([$owner]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $c){$phone=vp3_phone_caller_contacts_e164_v1((string)$c['phone']);if($phone==='')continue;$out[]=['id'=>(int)$c['id'],'name'=>(string)$c['name'],'phone_e164'=>$phone,'relationship'=>(string)$c['relationship'],'favorite'=>(bool)$c['favorite']];}return $out;
}
/** Find prior owner receipts beyond the recent window without counting linked calls twice. */
function vp3_phone_workspace_caller_first_v1(PDO $pdo,array $scope,array $phones): array
{
    $phones=array_values(array_unique(array_filter($phones,static fn($phone):bool=>is_string($phone)&&preg_match('/^\+[1-9][0-9]{7,14}$/D',$phone)===1)));
    $phones=array_slice($phones,0,100);if(!$phones)return [];$marks=implode(',',array_fill(0,count($phones),'?'));$out=[];
    foreach(['browser','legacy'] as $kind){
        if($kind==='legacy'&&!table_exists('phone_voice_calls_v130'))continue;
        $peer=$kind==='browser'?'c.peer_e164':'c.caller_e164';$table=$kind==='browser'?'phone_browser_calls_v1':'phone_voice_calls_v130';
        $created=$kind==='browser'?'c.created_epoch':'c.created_at';
        $where="c.owner_user_id=? AND $peer IN ($marks)";$args=[$scope['owner_user_id'],...$phones];
        if($scope['agent_id']>0){$where.=' AND c.agent_id=?';$args[]=$scope['agent_id'];}
        if($kind==='legacy')$where.=" AND c.provider='twilio' AND NOT EXISTS (SELECT 1 FROM phone_browser_calls_v1 b WHERE b.owner_user_id=c.owner_user_id AND (b.voice_call_id=c.id OR b.provider_call_sid=c.provider_call_sid))";
        $firstSql="SELECT earliest.peer,earliest.created,MIN(c.id) AS first_id FROM (SELECT $peer AS peer,MIN($created) AS created FROM $table c WHERE $where GROUP BY $peer) earliest JOIN $table c ON $peer=earliest.peer AND $created=earliest.created WHERE $where GROUP BY earliest.peer,earliest.created";
        $q=$pdo->prepare($firstSql);$q->execute([...$args,...$args]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            if($kind==='browser')$epoch=(int)$row['created'];
            else{$raw=(string)$row['created'];$date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw,new DateTimeZone('UTC'));$epoch=$date&&$date->format('Y-m-d H:i:s')===$raw?$date->getTimestamp():0;}
            if($epoch<1)continue;
            $out[(string)$row['peer']][$kind]=['created_at'=>gmdate(DATE_ATOM,$epoch),'id'=>(int)$row['first_id']];
        }
    }
    return $out;
}
function vp3_phone_workspace_call_projection_v1(array $row): array
{
    $connected=(string)($row['paired_profile_id']??'')!=='';
    $managed=($row['transport_kind']??'browser')==='managed_callback';
    return ['id'=>(int)$row['id'],'public_id'=>(string)$row['public_id'],'call_id'=>(string)$row['public_id'],'direction'=>(string)$row['direction'],
        'peer_e164'=>$connected?'':(string)$row['peer_e164'],'phone_e164'=>(string)$row['phone_e164'],'state'=>(string)$row['state'],
        'agent_id'=>(int)$row['agent_id'],'number_id'=>(int)$row['phone_number_id'],'conversation_id'=>(int)($row['conversation_id']??0),
        'duration_seconds'=>(int)$row['duration_seconds'],'created_at'=>gmdate(DATE_ATOM,(int)$row['created_epoch']),'recording_enabled'=>(bool)$row['recording_enabled'],
        'agent_conversation_active'=>($row['incoming_choice']??'')==='agent'&&($row['parent_voice_state']??'')==='conversation_active',
        'incoming_choice'=>(string)($row['incoming_choice']??''),'incoming_routing_available'=>array_key_exists('incoming_choice',$row),
        'source_kind'=>'browser','browser_control'=>!$managed]+($managed?['transport_kind'=>'managed_callback']+(($row['managed_parent_kind']??'callback')==='native'?['managed_mode'=>'twilio_native']:[]):[])+((int)($row['owner_phone_revision']??0)>0?['owner_test'=>true]:[])+($connected?['connected_phone'=>true,'peer_masked'=>'Ending in '.substr((string)$row['peer_e164'],-4),'connected_profile_id'=>(string)$row['paired_profile_id']]:[]);
}
/** The existing signed inbound receipt remains the historical owner authority. */
function vp3_phone_workspace_legacy_calls_v1(PDO $pdo,array $scope,bool $terminalOnly=false): array
{
    if(!table_exists('phone_voice_calls_v130'))return [];
    $duration=table_exists('phone_voice_recordings_v130')?'COALESCE((SELECT MAX(r.duration_seconds) FROM phone_voice_recordings_v130 r WHERE r.call_id=c.id AND r.owner_user_id=c.owner_user_id),0)':'0';
    $sql="SELECT c.*, $duration AS recording_duration_seconds FROM phone_voice_calls_v130 c WHERE c.owner_user_id=? AND c.provider='twilio' AND NOT EXISTS (
        SELECT 1 FROM phone_browser_calls_v1 b WHERE b.owner_user_id=c.owner_user_id
        AND (b.voice_call_id=c.id OR b.provider_call_sid=c.provider_call_sid))";$args=[$scope['owner_user_id']];
    if($scope['agent_id']>0){$sql.=' AND c.agent_id=?';$args[]=$scope['agent_id'];}
    if($terminalOnly)$sql.=" AND c.state IN ('completed','voicemail_ready')";
    $q=$pdo->prepare($sql.' ORDER BY c.created_at DESC,c.id DESC LIMIT 50');$q->execute($args);$rows=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $call){
        $raw=(string)$call['created_at'];$created=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw,new DateTimeZone('UTC'));
        $epoch=$created&&$created->format('Y-m-d H:i:s')===$raw?$created->getTimestamp():0;
        $duration=max(0,min(86400,(int)$call['recording_duration_seconds']));
        $public='legacy-'.(int)$call['id'];
        $rows[]=['id'=>(int)$call['id'],'public_id'=>$public,'call_id'=>$public,'source_kind'=>'legacy','browser_control'=>false,'direction'=>'inbound',
            'peer_e164'=>(string)$call['caller_e164'],'phone_e164'=>(string)$call['called_e164'],'state'=>(string)$call['state'],
            'agent_id'=>(int)$call['agent_id'],'number_id'=>(int)$call['phone_number_id'],'conversation_id'=>0,
            'duration_seconds'=>$duration,'created_at'=>$epoch>0?gmdate(DATE_ATOM,$epoch):'','recording_enabled'=>false];
    }
    return $rows;
}
function vp3_phone_workspace_state_v1(PDO $pdo,array $user,array $input=[]): array
{
    require_once __DIR__.'/phone-browser-v1.php';
    $scope=vp3_phone_workspace_scope_v1($pdo,$user,$input);$numbers=vp3_phone_workspace_numbers_v1($pdo,$scope);$contacts=vp3_phone_workspace_contacts_v1($pdo,$scope['owner_user_id']);
    $parentState=table_exists('phone_voice_calls_v130')?
        "(SELECT v.state FROM phone_voice_calls_v130 v WHERE v.id=b.voice_call_id AND v.owner_user_id=b.owner_user_id AND v.provider_call_sid=b.provider_call_sid)":'NULL';
    $sql="SELECT b.*, $parentState AS parent_voice_state FROM phone_browser_calls_v1 b WHERE b.owner_user_id=?";$args=[$scope['owner_user_id']];if($scope['agent_id']>0){$sql.=' AND b.agent_id=?';$args[]=$scope['agent_id'];}
    $q=$pdo->prepare($sql.' ORDER BY id DESC LIMIT 50');$q->execute($args);$calls=[];$active=null;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $card=vp3_phone_workspace_call_projection_v1($r);
        if(($r['transport_kind']??'')==='managed_callback'){
            require_once __DIR__.'/phone-managed-control-v1.php';
            $card['managed_control']=vp3_managed_control_view_v1($pdo,$scope,$r);
        }
        $calls[]=$card;
        if(!$active&&in_array($r['state'],['ringing','dialing','answered'],true)&&vp3_phone_browser_live_call_v1($pdo,$r))$active=$card;
    }
    $calls=array_merge($calls,vp3_phone_workspace_legacy_calls_v1($pdo,$scope));
    usort($calls,static fn(array $a,array $b):int=>strcmp($b['created_at'],$a['created_at'])?:strcmp($b['public_id'],$a['public_id']));$calls=array_slice($calls,0,50);
    // Recording storage is an optional, explicit upgrade. A missing or partial
    // recording migration must not hide the owner's assigned lines or history.
    $recordingReady=function_exists('vp3_phone_recording_schema_ready_v1')&&vp3_phone_recording_schema_ready_v1($pdo)&&vp3_phone_voicemail_ready_v1($pdo);
    $recordingReadiness=['ready'=>$recordingReady,'reason'=>$recordingReady?'':'Phone recordings need the Cloud database upgrade. Ask an administrator to run upgrade.php, then retry.'];
    $voicemails=[];
    if($recordingReady&&function_exists('vp3_phone_recording_workspace_v1'))$voicemails=vp3_phone_recording_workspace_v1($pdo,$scope['user'],$scope['agent_id']);
    elseif($recordingReady&&table_exists('phone_voice_recordings_v130')){
        $sql="SELECT r.id,r.call_id,r.created_at,r.duration_seconds,c.caller_e164,c.agent_id FROM phone_voice_recordings_v130 r JOIN phone_voice_calls_v130 c ON c.id=r.call_id AND c.owner_user_id=r.owner_user_id WHERE r.owner_user_id=? AND r.recording_status='completed'";$args=[$scope['owner_user_id']];if($scope['agent_id']>0){$sql.=' AND c.agent_id=?';$args[]=$scope['agent_id'];}$q=$pdo->prepare($sql.' ORDER BY r.id DESC LIMIT 50');$q->execute($args);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$voicemails[]=['id'=>(int)$r['id'],'call_id'=>'legacy-'.(int)$r['call_id'],'peer_e164'=>(string)$r['caller_e164'],'audio_url'=>url('/api/phone-voice-audio-v130.php?id='.(int)$r['id']),'transcript'=>'','report_url'=>'','created_at'=>(string)$r['created_at'],'duration_seconds'=>(int)$r['duration_seconds'],'read'=>false];
    }
    $peers=array_column(array_merge($calls,$voicemails,$active?[$active]:[]),'peer_e164');
    $known=vp3_phone_caller_contacts_lookup_v1($pdo,$scope['owner_user_id'],$peers);$firstCalls=vp3_phone_workspace_caller_first_v1($pdo,$scope,$peers);
    $identity=static function(array $row)use($known,$firstCalls):array{
        $peer=(string)($row['peer_e164']??'');$contact=$known[$peer]??null;$created=(string)($row['created_at']??'');$prior=false;
        // Within one ledger its monotonic ID orders receipts in the same second.
        // Across ledgers a tied timestamp supplies no evidence of an earlier call.
        if($created!==''&&isset($row['source_kind']))foreach($firstCalls[$peer]??[] as $kind=>$first){
            if(strcmp($first['created_at'],$created)<0||($first['created_at']===$created&&$kind===$row['source_kind']&&$first['id']<(int)$row['id'])){$prior=true;break;}
        }
        return $row+['contact_id'=>(int)($contact['id']??0),'contact_name'=>(string)($contact['name']??''),'caller_familiar'=>$prior];
    };
    $calls=array_map($identity,$calls);$callByRef=array_column($calls,null,'call_id');
    $voicemails=array_map(static function(array $row)use($identity,$callByRef):array{$row=$identity($row);if(isset($callByRef[$row['call_id']??'']))$row['caller_familiar']=$callByRef[$row['call_id']]['caller_familiar'];return $row;},$voicemails);if($active)$active=$identity($active);
    // Keep callers relevant to this workspace visible even beyond the alphabetical cap.
    if($known){
        $ids=array_values(array_unique(array_column($known,'id')));$q=$pdo->prepare('SELECT contact_id FROM phone_browser_favorites_v1 WHERE owner_user_id=? AND contact_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute([$scope['owner_user_id'],...$ids]);$favorites=array_fill_keys(array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)),true);$book=[];
        $details=array_column($contacts,null,'id');
        foreach($known as $contact)if(!isset($book[$contact['id']]))$book[$contact['id']]=$contact+['favorite'=>isset($favorites[$contact['id']]),'relationship'=>(string)($details[$contact['id']]['relationship']??'')];
        foreach($contacts as $contact)if(!isset($book[$contact['id']]))$book[$contact['id']]=$contact;
        $contacts=array_slice(array_values($book),0,250);
    }
    require_once __DIR__.'/phone-browser-v1.php';$ready=vp3_phone_browser_config_state_v1();$selected=vp3_phone_workspace_selected_v1($pdo,$scope,$numbers);$voice=false;$browser=false;$selectedAgent=0;foreach($numbers as $n)if($n['id']===$selected){$voice=$n['voice_enabled'];$browser=$n['browser_enabled'];$selectedAgent=$n['agent_id'];}
    if(!$voice)$ready=['ready'=>false,'reason'=>'Voice is not enabled for the selected assigned number.'];
    elseif(!$browser)$ready=['ready'=>false,'reason'=>'An administrator must enable browser calling for the selected assigned number.'];
    elseif(!vp3_phone_package_v100($pdo,$scope['owner_user_id'])['voice'])$ready=['ready'=>false,'reason'=>'The account voice allowance is not active.'];
    $policy=$recordingReady&&function_exists('vp3_phone_recording_policy_v1')&&$selectedAgent>0?vp3_phone_recording_policy_v1($pdo,$scope['user'],$selectedAgent):null;
    $liveCaption=['available'=>false,'eligible'=>false,'call_id'=>'','state'=>'off','reason'=>''];
    if(vp3_operator_live_schema_ready_v1($pdo)){
        require_once __DIR__.'/agent-operator-live-v1.php';
        $liveCaption=vp3_operator_live_view_v1($pdo,$scope,$active,time());
    }
    $trace=[];
    if($scope['conversation_id']>0&&vp3_phone_voicemail_owner_private_v1()){
        $q=$pdo->prepare("SELECT d.chat_message_id,m.context_json FROM phone_browser_deliveries_v1 d JOIN chat_messages m ON m.id=d.chat_message_id AND m.conversation_id=d.conversation_id WHERE d.owner_user_id=? AND d.chat_agent_id=? AND d.conversation_id=? AND d.event_key LIKE 'call:%' ORDER BY d.chat_message_id DESC LIMIT 8");
        $q->execute([$scope['owner_user_id'],$scope['agent_id'],$scope['conversation_id']]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $delivery){
            $phone=json_decode((string)$delivery['context_json'],true)['phone_v1']??[];
            if(($phone['kind']??'')!=='call'||!is_string($phone['call_id']??null))continue;
            $trace[]=['call_id'=>$phone['call_id'],'message_id'=>(int)$delivery['chat_message_id'],'browser_route'=>$phone['browser_route']??''];
        }
    }
    return ['numbers'=>$numbers,'contacts'=>$contacts,'favorites'=>array_values(array_filter($contacts,static fn($c)=>$c['favorite'])),'calls'=>$calls,'voicemails'=>$voicemails,
        'companion_cards'=>vp3_phone_companion_cards_v1($pdo,$scope,$calls),'operator_trace'=>$trace,'operator_live'=>$liveCaption,'voicemail_settings'=>$selected>0?vp3_phone_voicemail_settings_v1($pdo,$scope,$selected):['available'=>false],
        'operator_admission_available'=>$scope['conversation_id']>0&&vp3_operator_admission_schema_ready_v1($pdo)&&(!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null),
        'auto_call'=>vp3_operator_auto_call_book_v1($pdo,$scope,$selected,$contacts),
        'readiness'=>['ready'=>(bool)$ready['ready'],'reason'=>(string)$ready['reason']],'recording_readiness'=>$recordingReadiness,'contact_write_ready'=>vp3_phone_caller_contacts_schema_ready_v1($pdo),'contact_details_ready'=>vp3_phone_contact_details_schema_ready_v1($pdo),'active_call'=>$active,'selected_number_id'=>$selected,'post_call_policy'=>$policy,
        'processing_worker'=>function_exists('vp3_phone_recording_worker_health_v1')?vp3_phone_recording_worker_health_v1():['state'=>'never_seen']];
}
/** Insert one ordinary owner Chat delivery; credentials/media URLs never enter its context. */
function vp3_phone_workspace_delivery_v1(PDO $pdo,array $call,string $kind,string $eventKey,string $text,array $extra=[]): array
{
    if(!in_array($kind,['call','voicemail'],true)||!preg_match('/^[a-zA-Z0-9:_.-]{1,145}$/D',$eventKey))throw new InvalidArgumentException('Invalid phone delivery.');
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$call,$kind,$eventKey,$text,$extra){
        $owner=(int)$call['owner_user_id'];$q=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.vp3_phone_workspace_lock_v1($pdo));$q->execute([$owner]);if(!$q->fetchColumn())vp3_phone_workspace_error_v1();
        $q=$pdo->prepare('SELECT * FROM phone_browser_deliveries_v1 WHERE owner_user_id=? AND event_key=?');$q->execute([$owner,$eventKey]);$old=$q->fetch(PDO::FETCH_ASSOC);if($old&&($call['transport_kind']??'')==='managed_callback'&&((int)$old['conversation_id']!==(int)($call['conversation_id']??0)||(int)$old['chat_agent_id']!==(int)($call['chat_agent_id']??0)))vp3_phone_workspace_error_v1('managed_report_scope_changed',409,'The managed call’s original conversation changed.');if($old)return ['message_id'=>(int)$old['chat_message_id'],'conversation_id'=>(int)$old['conversation_id'],'chat_agent_id'=>(int)$old['chat_agent_id']];
        $cid=(int)($call['conversation_id']??0);$aid=(int)($call['chat_agent_id']??0);$managed=($call['transport_kind']??'')==='managed_callback';$q=$pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? AND COALESCE(user_agent_id,0)=?');$q->bindValue(1,$cid,PDO::PARAM_INT);$q->bindValue(2,$owner,PDO::PARAM_INT);$q->bindValue(3,$aid,PDO::PARAM_INT);$q->execute();
        if(!$q->fetchColumn()){if($managed)vp3_phone_workspace_error_v1('managed_report_scope_changed',409,'The managed call’s original conversation is unavailable.');$aid=0;$q=$pdo->prepare('SELECT id FROM chat_conversations WHERE user_id=? AND user_agent_id IS NULL ORDER BY updated_at DESC,id DESC LIMIT 1');$q->execute([$owner]);$cid=(int)$q->fetchColumn();
            if(!$cid){$pdo->prepare("INSERT INTO chat_conversations(user_id,user_agent_id,title,created_at,updated_at) VALUES (?,NULL,'Phone',?,?)")->execute([$owner,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')]);$cid=(int)$pdo->lastInsertId();}}
        $context=['phone_v1'=>['kind'=>$kind,'call_id'=>(string)$call['public_id']]];
        foreach(['voicemail_id','transcript_session_id','recording_refs','report','status','browser_route'] as $key)if(array_key_exists($key,$extra))$context['phone_v1'][$key]=$extra[$key];
        $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at) VALUES (?,NULL,'assistant',?,?,?)")->execute([$cid,$text,json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),gmdate('Y-m-d H:i:s')]);$mid=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO phone_browser_deliveries_v1(owner_user_id,event_key,chat_message_id,conversation_id,chat_agent_id) VALUES (?,?,?,?,?)')->execute([$owner,$eventKey,$mid,$cid,$aid]);
        $pdo->prepare('UPDATE chat_conversations SET updated_at=? WHERE id=? AND user_id=?')->execute([gmdate('Y-m-d H:i:s'),$cid,$owner]);return ['message_id'=>$mid,'conversation_id'=>$cid,'chat_agent_id'=>$aid];
    });
}
/** Announce an accepted legacy route even when there is no browser Device or recording. */
function vp3_phone_workspace_legacy_incoming_v1(PDO $pdo,array $route,string $sid,string $browserReason='browser_unavailable'): array
{
    if(empty($route['accepted'])||!vp3_phone_workspace_schema_ready_v1($pdo))return [];
    require_once __DIR__.'/phone-voice-v130.php';
    $current=vp3_phone_voice_current_route_v131($pdo,$sid,14400,['voicemail_offered','conversation_active','streaming_active']);
    if(empty($current['accepted']))return [];
    foreach(['owner_user_id','phone_number_id','agent_id'] as $key)if((int)($route[$key]??0)!==(int)$current[$key])return [];
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$current,$sid,$browserReason){
        $q=$pdo->prepare('SELECT * FROM users WHERE id=?'.vp3_phone_workspace_lock_v1($pdo));$q->execute([(int)$current['owner_user_id']]);$user=$q->fetch(PDO::FETCH_ASSOC);if(!$user)return [];
        $q=$pdo->prepare("SELECT * FROM phone_voice_calls_v130 WHERE provider='twilio' AND provider_call_sid=?".vp3_phone_workspace_lock_v1($pdo));$q->execute([$sid]);$call=$q->fetch(PDO::FETCH_ASSOC);if(!$call||!vp3_phone_workspace_available_v1($pdo,$user))return [];
        $q=$pdo->prepare('SELECT id FROM phone_browser_calls_v1 WHERE owner_user_id=? AND (voice_call_id=? OR provider_call_sid=?) LIMIT 1'.vp3_phone_workspace_lock_v1($pdo));$q->execute([(int)$call['owner_user_id'],(int)$call['id'],$sid]);if($q->fetchColumn())return [];
        $call['public_id']='legacy-'.(int)$call['id'];$call['conversation_id']=0;$call['chat_agent_id']=0;
        $q=$pdo->prepare('SELECT conversation_id,chat_agent_id FROM phone_browser_presence_v1 WHERE owner_user_id=? AND last_epoch>?');$q->execute([(int)$call['owner_user_id'],time()-300]);$presence=$q->fetch(PDO::FETCH_ASSOC);
        if($presence&&((int)$presence['chat_agent_id']===0||(int)$presence['chat_agent_id']===(int)$call['agent_id'])){$call['conversation_id']=(int)$presence['conversation_id'];$call['chat_agent_id']=(int)$presence['chat_agent_id'];}
        $peer=(string)$call['caller_e164'];$text='Incoming phone call'.($peer!==''?' from '.$peer:' from a private caller').' to '.(string)$call['called_e164'].'.';
        return vp3_phone_workspace_delivery_v1($pdo,$call,'call','call:'.$call['public_id'],$text,['browser_route'=>$browserReason]);
    });
}
function vp3_phone_workspace_action_v1(PDO $pdo,array $user,array $input): array
{
    $scope=vp3_phone_workspace_scope_v1($pdo,$user,$input);$action=(string)($input['action']??'');
    if(in_array($action,['owner_phone_settings','save_owner_phone','save_owner_call_preferences','confirm_owner_test'],true))return vp3_owner_phone_chat_action_v1($pdo,$scope,$input);
    if($action==='managed_review'){
        require_once __DIR__.'/agent-operator-managed-intelligence-v1.php';
        if(array_diff(array_keys($input),['action','call_id','agent_id','conversation_id']))vp3_phone_workspace_error_v1('invalid_phone_request',422,'Choose an existing managed call.');
        return ['review'=>vp3_managed_intelligence_review_v1($pdo,$scope,vp3_managed_callback_id_v1($input['call_id']??null),time())];
    }
    if(in_array($action,['managed_reconcile','managed_end'],true)){
        require_once __DIR__.'/phone-managed-control-v1.php';
        return vp3_managed_control_action_v1($pdo,$scope,$input);
    }
    if($action==='assign_scheduled_operator')return vp3_operator_schedule_assign_v1($pdo,$scope,$input);
    if($action==='assign_contact_phone')return vp3_phone_companion_assign_v1($pdo,$scope,$input);
    if($action==='share_call_item')return vp3_phone_companion_share_v1($pdo,$scope,$input);
    if($action==='save_caller_contact')return vp3_phone_caller_contact_save_v1($pdo,$scope,$input);
    if(in_array($action,['phone_contact_detail','save_phone_contact','save_phone_contact_schedule','cancel_phone_contact_schedule'],true)){
        require_once __DIR__.'/phone-contact-management-v1.php';
        if($action==='phone_contact_detail')return vp3_phone_contact_detail_v1($pdo,$scope,$input);
        if($action==='save_phone_contact')return vp3_phone_contact_save_v1($pdo,$scope,$input);
        require_once __DIR__.'/phone-contact-schedules-v1.php';
        return $action==='save_phone_contact_schedule'?vp3_phone_contact_schedule_save_v1($pdo,$scope,$input,time()):vp3_phone_contact_schedule_cancel_v1($pdo,$scope,$input,time());
    }
    if(in_array($action,['save_voicemail_settings','use_default_voicemail_greeting','transcribe_voicemail','delete_voicemail','post_voicemail'],true))return vp3_phone_voicemail_action_v1($pdo,$scope,$input);
    if($action==='save_auto_call_policy')return vp3_operator_auto_call_save_v1($pdo,$scope,$input);
    if(in_array($action,['operator_admission_preview','save_operator_consent'],true)){
        require_once __DIR__.'/agent-operator-admission-v1.php';
        require_once __DIR__.'/phone-recording-pipeline-v1.php';
        return $action==='operator_admission_preview'?vp3_operator_admission_preview_v1($pdo,$scope,$input,time()):vp3_operator_consent_save_v1($pdo,$scope,$input,time());
    }
    if(in_array($action,['operator_calendar_preview','save_operator_calendar'],true)){
        require_once __DIR__.'/agent-operator-calendar-v1.php';
        return $action==='operator_calendar_preview'
            ?['plan'=>vp3_operator_calendar_preview_v1($pdo,$scope,$input,time())]
            :vp3_operator_calendar_save_v1($pdo,$scope,$input,time());
    }
    if(in_array($action,['token','prepare_call','prepare_owner_test','prepare_connected_call','bind_call','route_incoming','cancel_prepared'],true)){require_once __DIR__.'/phone-browser-v1.php';return vp3_phone_browser_action_v1($pdo,$scope,$input);}
    if($action==='select_number'){$id=vp3_phone_workspace_id_v1($input['number_id']??null);vp3_phone_workspace_number_v1($pdo,$scope,$id);
        vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$id){$pdo->prepare('DELETE FROM phone_browser_preferences_v1 WHERE owner_user_id=? AND agent_id=?')->execute([$scope['owner_user_id'],$scope['agent_id']]);$pdo->prepare('INSERT INTO phone_browser_preferences_v1(owner_user_id,agent_id,selected_number_id) VALUES (?,?,?)')->execute([$scope['owner_user_id'],$scope['agent_id'],$id]);});return ['selected_number_id'=>$id];}
    if($action==='favorite'){$id=vp3_phone_workspace_id_v1($input['contact_id']??null);if(!is_bool($input['favorite']??null))vp3_phone_workspace_error_v1('invalid_phone_request',422,'Choose a favorite state.');
        $q=$pdo->prepare("SELECT id FROM crm_contacts WHERE id=? AND owner_user_id=? AND status<>'archived'");$q->execute([$id,$scope['owner_user_id']]);if(!$q->fetchColumn())vp3_phone_workspace_error_v1('contact_unavailable',404,'Contact not found.');
        vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$id,$input){$pdo->prepare('DELETE FROM phone_browser_favorites_v1 WHERE owner_user_id=? AND contact_id=?')->execute([$scope['owner_user_id'],$id]);if($input['favorite'])$pdo->prepare('INSERT INTO phone_browser_favorites_v1(owner_user_id,contact_id) VALUES (?,?)')->execute([$scope['owner_user_id'],$id]);});return ['contact_id'=>$id,'favorite'=>$input['favorite']];}
    if($action==='mark_voicemail_read'&&function_exists('vp3_phone_recording_mark_read_v1')){
        if(!is_string($input['voicemail_id']??null))vp3_phone_workspace_error_v1('invalid_phone_request',422,'Choose a saved voice message.');
        return vp3_phone_recording_mark_read_v1($pdo,$scope['user'],$input['voicemail_id'],$scope['agent_id']);
    }
    if(in_array($action,['recording_policy','save_recording_policy','postcall_policy'],true)&&function_exists('vp3_phone_recording_save_policy_v1')){
        $number=vp3_phone_workspace_number_v1($pdo,$scope,vp3_phone_workspace_id_v1($input['number_id']??null));
        if($action==='recording_policy')return ['policy'=>vp3_phone_recording_policy_v1($pdo,$scope['user'],(int)$number['agent_id'])];
        $policy=$input['policy']??$input;if(!is_array($policy)||array_is_list($policy))vp3_phone_workspace_error_v1('invalid_phone_request',422,'Choose valid post-call preferences.');
        return ['policy'=>vp3_phone_recording_save_policy_v1($pdo,$scope['user'],(int)$number['agent_id'],$policy)];
    }
    if($action==='retry_recording'&&function_exists('vp3_phone_recording_retry_v1'))return vp3_phone_recording_retry_v1($pdo,$scope['user'],vp3_phone_recording_job_id_v1($input['voicemail_id']??null));
    vp3_phone_workspace_error_v1('invalid_phone_action',422,'Choose a supported phone action.');
}
/** Shared HTTP boundary, tested without a carrier or paid provider request. */
function vp3_phone_workspace_request_v1(PDO $pdo,?array $user,string $method,array $input,string $csrf): array
{
    if(!$user)vp3_phone_workspace_error_v1('authentication_required',401,'Sign in to use Phone.');
    $action=$input['action']??($method==='GET'?'state':'');if(!is_string($action))vp3_phone_workspace_error_v1('invalid_phone_request',422,'Choose a supported phone action.');$input['action']=$action;
    if($method==='GET'){
        if($action==='state')return ['state'=>vp3_phone_workspace_state_v1($pdo,$user,$input)];
        if(in_array($action,['recording_policy','operator_calendar_preview','operator_admission_preview','owner_phone_settings','managed_review','phone_contact_detail'],true))return vp3_phone_workspace_action_v1($pdo,$user,$input);
        vp3_phone_workspace_error_v1('method_not_allowed',405,'This phone action requires POST.');
    }
    if($method!=='POST')vp3_phone_workspace_error_v1('method_not_allowed',405,'Use GET or POST for Phone.');
    if($csrf===''||!is_string($input['csrf_token']??null)||!hash_equals($csrf,$input['csrf_token']))vp3_phone_workspace_error_v1('csrf_failed',403,'Refresh this page before changing Phone.');
    if(in_array($action,['state','recording_policy','operator_calendar_preview','operator_admission_preview','owner_phone_settings','managed_review','phone_contact_detail'],true))vp3_phone_workspace_error_v1('method_not_allowed',405,'This phone action requires GET.');
    return vp3_phone_workspace_action_v1($pdo,$user,$input);
}
/** Existing Chat activity delivers each incoming call once into its validated current principal. */
function vp3_phone_workspace_chat_updates_v1(PDO $pdo,array $user,array $principal,array $input): array
{
    if(!vp3_phone_workspace_available_v1($pdo,$user)||!vp3_phone_workspace_schema_ready_v1($pdo))return [];
    $aid=($principal['kind']??'system')==='user_agent'?(int)($principal['agent_id']??0):0;
    $scope=vp3_phone_workspace_scope_v1($pdo,$user,['agent_id'=>$aid,'conversation_id'=>$input['conversation_id']??0]);if(!$scope['conversation_id'])return [];
    $sql="SELECT * FROM phone_browser_calls_v1 WHERE owner_user_id=? AND direction='inbound' AND created_epoch>?";$args=[$scope['owner_user_id'],time()-86400];if($aid>0){$sql.=' AND agent_id=?';$args[]=$aid;}$q=$pdo->prepare($sql.' ORDER BY id DESC LIMIT 10');$q->execute($args);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $call){$call['conversation_id']=$scope['conversation_id'];$call['chat_agent_id']=$aid;$delivery=vp3_phone_workspace_delivery_v1($pdo,$call,'call','call:'.$call['public_id'],'Incoming phone call.');if($delivery['conversation_id']===$scope['conversation_id'])$out[]=['call_id'=>$call['public_id'],'message_id'=>$delivery['message_id'],'conversation_id'=>$delivery['conversation_id']];}
    return $out;
}
