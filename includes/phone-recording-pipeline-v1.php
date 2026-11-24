<?php
declare(strict_types=1);

require_once __DIR__.'/phone-voicemail-v1.php';

/** Durable Cloud-only phone recordings. Webhooks enqueue; a server worker performs paid work. */
const VP3_PHONE_RECORDING_PIPELINE_V1='phone-recording-pipeline-v1';
const VP3_PHONE_RECORDING_MAX_BYTES_V1=26214400;
const VP3_PHONE_RECORDING_LEASE_SECONDS_V1=900;

function vp3_phone_recording_schema_ready_v1(?PDO $pdo=null): bool
{
    $pdo??=function_exists('db')?db():null;if(!$pdo)return false;
    try{
        $pdo->query('SELECT id,public_id,owner_user_id,agent_id,phone_number_id,origin_kind,call_id,call_public_id,provider_call_sid,provider_account_sid,provider_recording_sid,phone_e164,peer_e164,duration_seconds,state,stage,policy_json,session_id,recording_key,audio_sha256,transcript_hash,analysis_hash,conversation_id,report_message_id,speech_dispatched,analysis_dispatched,lease_token,lease_until,attempts,error_code,last_error,read_epoch,created_epoch,updated_epoch FROM phone_recording_jobs_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,agent_id,enabled,retain_audio,transcribe,`analyze`,modules_json,model_route,revision,created_epoch,updated_epoch FROM phone_recording_policies_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,recording_id,read_epoch FROM phone_recording_legacy_reads_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,job_id,seconds,reserved_epoch FROM phone_recording_speech_budget_v1 WHERE 1=0');return true;
    }catch(PDOException){return false;}
}
function vp3_phone_recording_ensure_schema_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Install phone recording storage before a transaction.');
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $id=$sqlite?'INTEGER PRIMARY KEY AUTOINCREMENT':'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    $tail=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $jobIndexes=$sqlite?'':',KEY idx_phone_recording_queue_v1(state,id),KEY idx_phone_recording_owner_v1(owner_user_id,agent_id,id)';
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_recording_policies_v1 (
        owner_user_id BIGINT NOT NULL,agent_id BIGINT NOT NULL,enabled INTEGER NOT NULL DEFAULT 0,
        retain_audio INTEGER NOT NULL DEFAULT 1,transcribe INTEGER NOT NULL DEFAULT 1,`analyze` INTEGER NOT NULL DEFAULT 1,
        modules_json TEXT NOT NULL,model_route VARCHAR(32) NOT NULL DEFAULT 'account_cloud',revision INTEGER NOT NULL DEFAULT 1,
        created_epoch BIGINT NOT NULL,updated_epoch BIGINT NOT NULL,PRIMARY KEY(owner_user_id,agent_id))$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_recording_jobs_v1 (
        id $id,public_id CHAR(32) NOT NULL UNIQUE,owner_user_id BIGINT NOT NULL,agent_id BIGINT NOT NULL,
        phone_number_id BIGINT NOT NULL,origin_kind VARCHAR(16) NOT NULL,call_id BIGINT NOT NULL,
        call_public_id VARCHAR(64) NOT NULL,provider_call_sid VARCHAR(40) NOT NULL,
        provider_account_sid VARCHAR(40) NOT NULL,provider_recording_sid VARCHAR(40) NOT NULL UNIQUE,
        phone_e164 VARCHAR(20) NOT NULL,peer_e164 VARCHAR(20) NOT NULL DEFAULT '',duration_seconds INTEGER NOT NULL,
        state VARCHAR(24) NOT NULL DEFAULT 'queued',stage VARCHAR(40) NOT NULL DEFAULT 'queued',policy_json TEXT NOT NULL,
        session_id BIGINT NOT NULL DEFAULT 0,recording_key CHAR(32) NOT NULL DEFAULT '',audio_sha256 CHAR(64) NOT NULL DEFAULT '',
        transcript_hash CHAR(64) NOT NULL DEFAULT '',analysis_hash CHAR(64) NOT NULL DEFAULT '',
        conversation_id BIGINT NOT NULL DEFAULT 0,report_message_id BIGINT NOT NULL DEFAULT 0,
        speech_dispatched INTEGER NOT NULL DEFAULT 0,analysis_dispatched INTEGER NOT NULL DEFAULT 0,
        lease_token CHAR(64) NOT NULL DEFAULT '',lease_until BIGINT NOT NULL DEFAULT 0,attempts INTEGER NOT NULL DEFAULT 0,
        error_code VARCHAR(80) NOT NULL DEFAULT '',last_error VARCHAR(300) NOT NULL DEFAULT '',
        read_epoch BIGINT NOT NULL DEFAULT 0,created_epoch BIGINT NOT NULL,updated_epoch BIGINT NOT NULL$jobIndexes)$tail");
    if($sqlite){$pdo->exec('CREATE INDEX IF NOT EXISTS idx_phone_recording_queue_v1 ON phone_recording_jobs_v1(state,id)');$pdo->exec('CREATE INDEX IF NOT EXISTS idx_phone_recording_owner_v1 ON phone_recording_jobs_v1(owner_user_id,agent_id,id)');}
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_recording_legacy_reads_v1 (owner_user_id BIGINT NOT NULL,recording_id BIGINT NOT NULL,read_epoch BIGINT NOT NULL,PRIMARY KEY(owner_user_id,recording_id))$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_recording_speech_budget_v1 (owner_user_id BIGINT NOT NULL,job_id BIGINT NOT NULL,seconds INTEGER NOT NULL,reserved_epoch BIGINT NOT NULL,PRIMARY KEY(owner_user_id,job_id))$tail");
    vp3_phone_voicemail_install_v1($pdo);
}
function vp3_phone_recording_json_v1(array $value): string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
function vp3_phone_recording_decode_v1(mixed $value): array{$r=is_string($value)?json_decode($value,true):null;return is_array($r)?$r:[];}
function vp3_phone_recording_stack_v1(): void
{
    foreach(['artist-listening','artist-listening-transcript','transcription-app-registry','transcription-apps-wave2','transcription-apps-wave2-save','transcription-intelligence-items','transcription-intelligence-relations','transcription-intelligence-actions','transcription-workflow-config','transcription-intelligence-outputs','transcription-deeper-intelligence','transcription-deeper-items','transcription-deeper-chat','transcription-deeper-report','transcription-deeper-advanced'] as $name)require_once __DIR__.'/'.$name.'.php';
}
function vp3_phone_recording_defaults_v1(): array
{
    return ['enabled'=>false,'retain_audio'=>true,'transcribe'=>true,'analyze'=>true,
        'modules'=>['basic','qa','actions','decisions','followup'],'model_route'=>'account_cloud','revision'=>0];
}
function vp3_phone_recording_modules_v1(array $modules): array
{
    vp3_phone_recording_stack_v1();$registry=transcription_app_registry_v307();
    if(!$modules||count($modules)>8)throw new RuntimeException('Choose one to eight post-call analysis modules.',422);
    $ids=[];foreach($modules as $id){
        if(!is_string($id)||!isset($registry[$id])||in_array($id,['summary_output','action_plan','research_brief','changes'],true))
            throw new RuntimeException('Post-call analysis cannot run reviewed outputs or public/comparison research automatically.',422);
        $ids[$id]=$id;
    }
    return array_values($ids);
}
function vp3_phone_recording_policy_v1(PDO $pdo,array $user,int $agentId): array
{
    $q=$pdo->prepare('SELECT * FROM phone_recording_policies_v1 WHERE owner_user_id=? AND agent_id=?');$q->execute([(int)$user['id'],$agentId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)return vp3_phone_recording_defaults_v1();
    return ['enabled'=>(bool)$row['enabled'],'retain_audio'=>(bool)$row['retain_audio'],'transcribe'=>(bool)$row['transcribe'],
        'analyze'=>(bool)$row['analyze'],'modules'=>vp3_phone_recording_decode_v1($row['modules_json']),
        'model_route'=>(string)$row['model_route'],'revision'=>(int)$row['revision']];
}
function vp3_phone_recording_owner_v1(PDO $pdo,int $owner): array
{
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$owner]);$user=$q->fetch(PDO::FETCH_ASSOC);
    if(!$user||empty($user['is_active'])||!has_permission('account.access',$user)||!has_permission('chat.access',$user))throw new RuntimeException('Phone recording access changed.',403);
    return $user;
}
function vp3_phone_recording_save_policy_v1(PDO $pdo,array $user,int $agentId,array $input): array
{
    $user=vp3_phone_recording_owner_v1($pdo,(int)($user['id']??0));$old=vp3_phone_recording_policy_v1($pdo,$user,$agentId);
    $next=$old;foreach(['enabled','retain_audio','transcribe','analyze'] as $key)if(array_key_exists($key,$input)){
        if(!is_bool($input[$key]))throw new RuntimeException('Post-call preferences must be true or false.',422);$next[$key]=$input[$key];
    }
    if(isset($input['model_route'])&&$input['model_route']!=='account_cloud')throw new RuntimeException('Post-call processing uses the account Cloud model route.',422);
    if(isset($input['modules'])){if(!is_array($input['modules'])||!array_is_list($input['modules']))throw new RuntimeException('Choose existing analysis modules.',422);$next['modules']=vp3_phone_recording_modules_v1($input['modules']);}
    if($next['analyze']&&!$next['transcribe'])throw new RuntimeException('Transcription is required before post-call analysis.',422);
    if($next['enabled']){
        $q=$pdo->prepare("SELECT n.id FROM phone_cloud_numbers_v100 n JOIN user_agents a ON a.id=n.agent_id AND a.owner_user_id=n.owner_user_id AND a.is_active=1 WHERE n.owner_user_id=? AND n.agent_id=? AND n.provider='twilio' AND n.voice_enabled=1 LIMIT 1");
        $q->execute([(int)$user['id'],$agentId]);
        if(!$q->fetchColumn()||!vp3_phone_package_v100($pdo,(int)$user['id'])['voice'])throw new RuntimeException('An active phone number must be assigned to this owner and Agent.',403);
        if(($next['retain_audio']||$next['transcribe'])&&!has_permission('artist_listening.access',$user))throw new RuntimeException('Transcription access is required for saved phone media.',403);
        if(($next['transcribe']||$next['analyze'])&&!subscription_has_entitlement($user,'main_ai.access'))throw new RuntimeException('AI access is required for automatic phone transcription.',403);
        if($next['analyze']){vp3_phone_recording_stack_v1();vp3_ai_direct_account_route_v1($user);}
    }
    $now=time();$encoded=vp3_phone_recording_json_v1($next['modules']);
    $q=$pdo->prepare('SELECT revision FROM phone_recording_policies_v1 WHERE owner_user_id=? AND agent_id=?');$q->execute([(int)$user['id'],$agentId]);$exists=$q->fetchColumn();
    if($exists===false)$pdo->prepare('INSERT INTO phone_recording_policies_v1 (owner_user_id,agent_id,enabled,retain_audio,transcribe,`analyze`,modules_json,model_route,revision,created_epoch,updated_epoch) VALUES (?,?,?,?,?,?,?,?,1,?,?)')->execute([(int)$user['id'],$agentId,(int)$next['enabled'],(int)$next['retain_audio'],(int)$next['transcribe'],(int)$next['analyze'],$encoded,'account_cloud',$now,$now]);
    else $pdo->prepare('UPDATE phone_recording_policies_v1 SET enabled=?,retain_audio=?,transcribe=?,`analyze`=?,modules_json=?,revision=revision+1,updated_epoch=? WHERE owner_user_id=? AND agent_id=?')->execute([(int)$next['enabled'],(int)$next['retain_audio'],(int)$next['transcribe'],(int)$next['analyze'],$encoded,$now,(int)$user['id'],$agentId]);
    return vp3_phone_recording_policy_v1($pdo,$user,$agentId);
}
function vp3_phone_recording_call_v1(PDO $pdo,array $job): array
{
    $kind=(string)$job['origin_kind'];
    if(!in_array($kind,['browser','legacy'],true))throw new RuntimeException('Recording call source is invalid.',409);
    $table=$kind==='browser'?'phone_browser_calls_v1':'phone_voice_calls_v130';
    $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([(int)$job['call_id']]);$call=$q->fetch(PDO::FETCH_ASSOC);
    if(!$call||(int)$call['owner_user_id']!==(int)$job['owner_user_id']||(int)$call['agent_id']!==(int)$job['agent_id']
        ||(int)$call['phone_number_id']!==(int)$job['phone_number_id']||!hash_equals((string)$call['provider_call_sid'],(string)$job['provider_call_sid']))throw new RuntimeException('The immutable recording call binding changed.',409);
    $call['source_kind']=$kind;if(!isset($call['public_id']))$call['public_id']='legacy-'.$call['id'];
    if($kind==='legacy'){
        $q=$pdo->prepare('SELECT conversation_id,chat_agent_id FROM phone_browser_deliveries_v1 WHERE owner_user_id=? AND event_key=?');$q->execute([(int)$job['owner_user_id'],'call:'.$call['public_id']]);$original=$q->fetch(PDO::FETCH_ASSOC);
        if($original){$call['conversation_id']=(int)$original['conversation_id'];$call['chat_agent_id']=(int)$original['chat_agent_id'];}
    }
    return $call;
}
function vp3_phone_recording_authorize_v1(PDO $pdo,array $job,bool $paid=false): array
{
    $fresh=vp3_phone_recording_row_v1($pdo,(int)$job['id']);
    if($fresh['state']==='deleted'||vp3_phone_voicemail_deleted_v1($pdo,(string)$job['provider_recording_sid']))throw new RuntimeException('Voice message was deleted.',409);
    if(($job['lease_token']??'')!==''&&(!hash_equals((string)$fresh['lease_token'],(string)$job['lease_token'])||(int)$fresh['lease_until']<time()))throw new RuntimeException('Recording worker lease changed.',409);
    if(!hash_equals((string)$fresh['policy_json'],(string)$job['policy_json']))throw new RuntimeException('Recording processing consent changed.',409);
    $user=vp3_phone_recording_owner_v1($pdo,(int)$job['owner_user_id']);$call=vp3_phone_recording_call_v1($pdo,$job);
    $q=$pdo->prepare('SELECT n.owner_user_id,n.agent_id,n.provider,n.voice_enabled,a.is_active FROM phone_cloud_numbers_v100 n JOIN user_agents a ON a.id=n.agent_id AND a.owner_user_id=n.owner_user_id WHERE n.id=?');$q->execute([(int)$job['phone_number_id']]);$number=$q->fetch(PDO::FETCH_ASSOC);
    if(!$number||(int)$number['owner_user_id']!==(int)$job['owner_user_id']||(int)$number['agent_id']!==(int)$job['agent_id']||empty($number['is_active'])||$number['provider']!=='twilio'||empty($number['voice_enabled'])||!vp3_phone_package_v100($pdo,(int)$user['id'])['voice'])throw new RuntimeException('This phone number is no longer assigned to this owner and Agent.',403);
    if(($call['transport_kind']??'')==='managed_callback'){
        require_once __DIR__.'/phone-managed-recording-v1.php';
        $original=vp3_managed_recording_saved_policy_v1($pdo,$call,(string)$job['provider_recording_sid']);
        if($original!==vp3_phone_recording_decode_v1($job['policy_json']))throw new RuntimeException('Managed recording processing consent changed.',409);
    }
    if($paid){
        $snapshot=vp3_phone_recording_decode_v1($job['policy_json']);
        if((!empty($snapshot['retain_audio'])||!empty($snapshot['transcribe']))&&!has_permission('artist_listening.access',$user))throw new RuntimeException('Saved media permission changed.',403);
        if((!empty($snapshot['transcribe'])||!empty($snapshot['analyze']))&&!subscription_has_entitlement($user,'main_ai.access'))throw new RuntimeException('Post-call processing permission changed.',403);
        $snapshot=vp3_phone_recording_decode_v1($job['policy_json']);
        if(($snapshot['purpose']??'')==='voicemail')vp3_phone_voicemail_paid_authority_v1($pdo,$job,$snapshot);
        else{$policy=vp3_phone_recording_policy_v1($pdo,$user,(int)$job['agent_id']);if(!$policy['enabled']||$policy['revision']!==(int)($snapshot['revision']??-1))throw new RuntimeException('Post-call processing preferences changed; no further provider request was sent.',409);}
    }
    return ['user'=>$user,'call'=>$call];
}
/** Local commit fences take short row locks; no provider request runs under these locks. */
function vp3_phone_recording_lock_authority_v1(PDO $pdo,array $job,bool $paid): array
{
    if(!$pdo->inTransaction())throw new RuntimeException('Recording commit authority requires a transaction.');
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        foreach([['users','id',(int)$job['owner_user_id']],['phone_cloud_numbers_v100','id',(int)$job['phone_number_id']],['user_agents','id',(int)$job['agent_id']]] as [$table,$column,$id]){$q=$pdo->prepare('SELECT '.$column.' FROM '.$table.' WHERE '.$column.'=? FOR UPDATE');$q->execute([$id]);$q->fetchColumn();}
        if($paid){$q=$pdo->prepare('SELECT revision FROM phone_recording_policies_v1 WHERE owner_user_id=? AND agent_id=? FOR UPDATE');$q->execute([(int)$job['owner_user_id'],(int)$job['agent_id']]);$q->fetchColumn();}
        if((int)$job['session_id']>0){$q=$pdo->prepare('SELECT id FROM artist_transcript_sessions_v172 WHERE id=? FOR UPDATE');$q->execute([(int)$job['session_id']]);$q->fetchColumn();}
    }
    return vp3_phone_recording_authorize_v1($pdo,$job,$paid);
}
/** Called only after a signed provider callback; does no media/provider work. */
function vp3_phone_recording_receive_v1(PDO $pdo,array $call,array $form): array
{
    $sid=$form['RecordingSid']??null;$callSid=$form['CallSid']??null;$account=$form['AccountSid']??null;$status=$form['RecordingStatus']??null;
    if(!is_string($sid)||!preg_match('/^RE[a-f0-9]{32}$/iD',$sid)||!is_string($callSid)||!hash_equals((string)($call['provider_call_sid']??''),$callSid)
        ||!is_string($account)||!preg_match('/^AC[a-f0-9]{32}$/iD',$account)||!in_array($status,['completed','failed','absent','processing'],true))throw new RuntimeException('Invalid phone recording receipt.',422);
    if($status==='processing')return ['accepted'=>true,'queued'=>false,'reason'=>'recording_processing'];
    $duration=(string)($form['RecordingDuration']??'0');if(!preg_match('/^\d{1,5}$/D',$duration))throw new RuntimeException('Invalid recording duration.',422);
    $kind=($call['source_kind']??'browser')==='legacy'?'legacy':'browser';$owner=(int)($call['owner_user_id']??0);$agent=(int)($call['agent_id']??0);
    if($owner<1||$agent<1||(int)($call['phone_number_id']??0)<1||(int)($call['id']??0)<1)throw new RuntimeException('Recording owner binding is unavailable.',409);
    $result=vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$call,$form,$sid,$callSid,$account,$status,$duration,$kind,$owner,$agent){
    $lock=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.vp3_phone_workspace_lock_v1($pdo));$lock->execute([$owner]);if(!$lock->fetchColumn())throw new RuntimeException('Recording owner unavailable.',403);
    if(vp3_phone_voicemail_deleted_v1($pdo,$sid))return ['accepted'=>true,'queued'=>false,'deleted'=>true];
    $q=$pdo->prepare('SELECT * FROM phone_recording_jobs_v1 WHERE provider_recording_sid=?');$q->execute([$sid]);$existing=$q->fetch(PDO::FETCH_ASSOC);
    if($existing){
        if((int)$existing['owner_user_id']!==$owner||(int)$existing['agent_id']!==$agent||$existing['origin_kind']!==$kind||(int)$existing['call_id']!==(int)$call['id']||!hash_equals($existing['provider_account_sid'],$account))throw new RuntimeException('Recording receipt conflicts with its original call.',409);
        return ['accepted'=>true,'queued'=>false,'duplicate'=>true,'recording'=>vp3_phone_recording_public_v1(vp3_phone_recording_row_v1($pdo,(int)$existing['id']))];
    }
    $user=vp3_phone_recording_owner_v1($pdo,$owner);$policy=($kind==='legacy'||!empty($call['voicemail_message']))?vp3_phone_voicemail_receipt_policy_v1($pdo,$call):vp3_phone_recording_policy_v1($pdo,$user,$agent);$now=time();
    $managed=($call['transport_kind']??'')==='managed_callback';
    if($managed){
        require_once __DIR__.'/phone-managed-recording-v1.php';
        $original=vp3_managed_recording_saved_policy_v1($pdo,$call,$sid);
        if($policy!==$original)throw new RuntimeException('Managed recording policy changed.',409);
        $policy=$original;
    }
    $public=bin2hex(random_bytes(16));$state=$status==='completed'?'queued':'failed';$seconds=(int)$duration;
    $fields=['public_id','owner_user_id','agent_id','phone_number_id','origin_kind','call_id','call_public_id','provider_call_sid','provider_account_sid','provider_recording_sid','phone_e164','peer_e164','duration_seconds','state','policy_json','conversation_id','error_code','last_error','created_epoch','updated_epoch'];
    $args=[$public,$owner,$agent,(int)$call['phone_number_id'],$kind,(int)$call['id'],(string)($call['public_id']??('legacy-'.$call['id'])),$callSid,$account,$sid,(string)($call['phone_e164']??$call['called_e164']??''),$managed?'':(string)($call['peer_e164']??$call['caller_e164']??''),$seconds,$state,vp3_phone_recording_json_v1($policy),(int)($call['conversation_id']??0),$state==='failed'?'recording_unavailable':'',$state==='failed'?'The provider did not produce a recording.':'',$now,$now];
    try{$pdo->prepare('INSERT INTO phone_recording_jobs_v1 ('.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')')->execute($args);}
    catch(PDOException $error){$q->execute([$sid]);if($q->fetchColumn())return vp3_phone_recording_receive_v1($pdo,$call,$form);throw $error;}
    $id=(int)$pdo->lastInsertId();$job=vp3_phone_recording_row_v1($pdo,$id);
    return ['accepted'=>true,'queued'=>$state==='queued','duplicate'=>false,'recording'=>vp3_phone_recording_public_v1(vp3_phone_recording_row_v1($pdo,$id))];
    });
    if(isset($result['recording']['id'])){$id=vp3_phone_recording_job_id_v1($result['recording']['id']);vp3_phone_recording_announce_v1($pdo,vp3_phone_recording_row_v1($pdo,$id),$call);$result['recording']=vp3_phone_recording_public_v1(vp3_phone_recording_row_v1($pdo,$id));}
    return $result;
}
function vp3_phone_recording_announce_v1(PDO $pdo,array $job,array $call): void
{
    if(!function_exists('vp3_phone_workspace_delivery_v1'))return;
    vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$job,$call){
    $q=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.vp3_phone_workspace_lock_v1($pdo));$q->execute([(int)$job['owner_user_id']]);if(!$q->fetchColumn())return;
    $job=vp3_phone_recording_row_v1($pdo,(int)$job['id']);if($job['state']==='deleted'||!vp3_phone_voicemail_announce_allowed_v1($pdo,$job))return;
    $call=vp3_phone_recording_call_v1($pdo,$job);$call['public_id']=$job['call_public_id'];
    $delivery=vp3_phone_workspace_delivery_v1($pdo,$call,'voicemail','phone-recording:'.$job['public_id'],
        $job['state']==='failed'?'The phone recording was unavailable.':(vp3_phone_voicemail_job_v1($job)?vp3_phone_voicemail_text_v1($pdo,$job,$call):'A saved voice message is available.'),
        ['voicemail_id'=>'job-'.(int)$job['id'],'status'=>$job['state']]);
    if((int)($delivery['conversation_id']??0)>0)$pdo->prepare('UPDATE phone_recording_jobs_v1 SET conversation_id=? WHERE id=?')->execute([(int)$delivery['conversation_id'],(int)$job['id']]);
    });
}
function vp3_phone_recording_row_v1(PDO $pdo,int $id): array{$q=$pdo->prepare('SELECT * FROM phone_recording_jobs_v1 WHERE id=?');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('Recording not found.',404);return $r;}
function vp3_phone_recording_job_id_v1(mixed $id): int
{
    if(!is_string($id)||!preg_match('/^job-([1-9][0-9]{0,17})$/D',$id,$match))throw new RuntimeException('Recording not found.',404);
    return (int)$match[1];
}
function vp3_phone_recording_public_v1(array $job): array
{
    $session=(int)$job['session_id'];$key=(string)$job['recording_key'];
    $voicemail=vp3_phone_voicemail_job_v1($job);if($job['state']==='deleted')return ['id'=>'job-'.(int)$job['id'],'call_id'=>$job['call_public_id'],'kind'=>'voicemail','state'=>'deleted','audio_url'=>'','transcript_url'=>'','report_url'=>'','recording_refs'=>[],'transcript'=>''];
    return ['id'=>'job-'.(int)$job['id'],'kind'=>$voicemail?'voicemail':'call_recording','can_delete'=>$voicemail,'can_post'=>$voicemail,'can_transcribe'=>$voicemail&&!(int)$job['speech_dispatched']&&!(int)$job['analysis_dispatched']&&!in_array($job['state'],['transcribing','analyzing'],true)&&$job['error_code']!=='recording_unavailable','public_id'=>$job['public_id'],'call_id'=>$job['call_public_id'],'state'=>$job['state'],'stage'=>$job['stage'],
        'duration_seconds'=>(int)$job['duration_seconds'],'transcript_session_id'=>$session,'recording_refs'=>$session>0&&$key!==''?[['session_id'=>$session,'key'=>$key]]:[],
        'audio_url'=>$job['error_code']==='recording_unavailable'?'':url('/api/phone-recording-audio-v1.php?id=job-'.(int)$job['id']),'transcript_url'=>$session>0?url('/artist-listening.php?session='.$session):'',
        'report_message_id'=>(int)$job['report_message_id'],'conversation_id'=>(int)$job['conversation_id'],'error_code'=>$job['error_code'],'error'=>$job['last_error'],
        'peer_e164'=>$job['peer_e164'],'phone_number_id'=>(int)$job['phone_number_id'],'agent_id'=>(int)$job['agent_id'],
        'read'=>(int)($job['read_epoch']??0)>0,'created_at'=>gmdate(DATE_ATOM,(int)$job['created_epoch']),
        'report_url'=>$session>0?url('/artist-listening.php?session='.$session):'',
        'retry_available'=>$job['state']==='failed'&&$job['error_code']!=='recording_unavailable'&&!(int)$job['speech_dispatched']&&!(int)$job['analysis_dispatched'],'created_epoch'=>(int)$job['created_epoch']];
}
function vp3_phone_recording_workspace_v1(PDO $pdo,array $user,int $agentId=0): array
{
    $user=vp3_phone_recording_owner_v1($pdo,(int)($user['id']??0));
    $sql="SELECT * FROM phone_recording_jobs_v1 WHERE owner_user_id=? AND state<>'deleted'";$args=[(int)$user['id']];if($agentId>0){$sql.=' AND agent_id=?';$args[]=$agentId;}
    $q=$pdo->prepare($sql.' ORDER BY id DESC LIMIT 50');$q->execute($args);$rows=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $job){
        if(vp3_phone_voicemail_deleted_v1($pdo,(string)$job['provider_recording_sid']))continue;
        if(!vp3_phone_voicemail_job_v1($job)){try{$v=$pdo->prepare('SELECT id FROM phone_voice_recordings_v130 WHERE provider_recording_sid=? AND owner_user_id=?');$v->execute([$job['provider_recording_sid'],$job['owner_user_id']]);$job['voicemail_message']=(bool)$v->fetchColumn();}catch(PDOException){}}
        $row=vp3_phone_recording_public_v1($job);$row['transcript']='';
        if((int)$job['session_id']>0){$s=$pdo->prepare('SELECT id FROM artist_transcript_sessions_v172 WHERE id=? AND created_by_user_id=? AND discarded_at IS NULL');$s->execute([(int)$job['session_id'],(int)$user['id']]);
            if(!$s->fetchColumn()){$row['transcript_url']='';$row['report_url']='';$row['audio_url']='';$row['recording_refs']=[];}
            else{$t=$pdo->prepare("SELECT transcript_text FROM artist_transcript_segments_v172 WHERE session_id=? AND segment_type='transcript' ORDER BY segment_index,id LIMIT 5");$t->execute([(int)$job['session_id']]);$row['transcript']=mb_substr(implode("\n",array_column($t->fetchAll(PDO::FETCH_ASSOC),'transcript_text')),0,8000);if($row['transcript']!=='')$row['can_transcribe']=false;}}
        $rows[]=$row;}
    // Existing saved voicemails remain available without inventing a new paid job.
    try{$pdo->query('SELECT id FROM phone_voice_recordings_v130 WHERE 1=0');$legacy=true;}catch(PDOException){$legacy=false;}
    if($legacy){
        $sql="SELECT r.*,c.agent_id,c.phone_number_id,c.caller_e164,c.called_e164,c.provider_call_sid,rr.read_epoch FROM phone_voice_recordings_v130 r JOIN phone_voice_calls_v130 c ON c.id=r.call_id AND c.owner_user_id=r.owner_user_id LEFT JOIN phone_recording_legacy_reads_v1 rr ON rr.owner_user_id=r.owner_user_id AND rr.recording_id=r.id WHERE r.owner_user_id=? AND r.provider='twilio' AND r.recording_status='completed' AND NOT EXISTS (SELECT 1 FROM phone_recording_jobs_v1 j WHERE j.provider_recording_sid=r.provider_recording_sid)";
        $args=[(int)$user['id']];if($agentId>0){$sql.=' AND c.agent_id=?';$args[]=$agentId;}$q=$pdo->prepare($sql.' ORDER BY r.id DESC LIMIT 50');$q->execute($args);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $recording)$rows[]=vp3_phone_recording_legacy_public_v1($recording);
    }
    usort($rows,static fn($a,$b)=>($b['created_epoch']<=>$a['created_epoch'])?:strcmp($b['id'],$a['id']));return array_slice($rows,0,50);
}
function vp3_phone_recording_legacy_public_v1(array $row): array
{
    $id=(int)$row['id'];$epoch=strtotime((string)($row['created_at']??''))?:0;
    return ['id'=>'legacy-'.$id,'kind'=>'voicemail','can_delete'=>true,'can_post'=>true,'can_transcribe'=>true,'call_id'=>'legacy-'.(int)$row['call_id'],'state'=>'ready','stage'=>'ready','peer_e164'=>(string)$row['caller_e164'],'phone_number_id'=>(int)$row['phone_number_id'],'agent_id'=>(int)$row['agent_id'],
        'audio_url'=>url('/api/phone-recording-audio-v1.php?id=legacy-'.$id),'transcript'=>'','report_url'=>'','transcript_url'=>'','recording_refs'=>[],'transcript_session_id'=>0,'read'=>(int)($row['read_epoch']??0)>0,'created_at'=>(string)($row['created_at']??''),'created_epoch'=>$epoch,'duration_seconds'=>(int)$row['duration_seconds'],'retry_available'=>false];
}
function vp3_phone_recording_legacy_row_v1(PDO $pdo,array $user,int $id,int $agentId=0): array
{
    $q=$pdo->prepare("SELECT r.*,c.agent_id,c.phone_number_id,c.caller_e164,c.called_e164,c.provider_call_sid FROM phone_voice_recordings_v130 r JOIN phone_voice_calls_v130 c ON c.id=r.call_id AND c.owner_user_id=r.owner_user_id WHERE r.id=? AND r.owner_user_id=? AND r.provider='twilio' AND r.recording_status='completed'");$q->execute([$id,(int)$user['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||($agentId>0&&(int)$row['agent_id']!==$agentId)||vp3_phone_voicemail_deleted_v1($pdo,(string)$row['provider_recording_sid']))throw new RuntimeException('Recording not found.',404);return $row;
}
function vp3_phone_recording_mark_read_v1(PDO $pdo,array $user,string $opaqueId,int $agentId=0): array
{
    $user=vp3_phone_recording_owner_v1($pdo,(int)($user['id']??0));
    if(preg_match('/^legacy-([1-9][0-9]{0,17})$/D',$opaqueId,$match)){
        $row=vp3_phone_recording_legacy_row_v1($pdo,$user,(int)$match[1],$agentId);$q=$pdo->prepare('SELECT read_epoch FROM phone_recording_legacy_reads_v1 WHERE owner_user_id=? AND recording_id=?');$q->execute([(int)$user['id'],(int)$row['id']]);
        if($q->fetchColumn()===false){try{$pdo->prepare('INSERT INTO phone_recording_legacy_reads_v1(owner_user_id,recording_id,read_epoch) VALUES (?,?,?)')->execute([(int)$user['id'],(int)$row['id'],time()]);}catch(PDOException $error){$q->execute([(int)$user['id'],(int)$row['id']]);if($q->fetchColumn()===false)throw $error;}}
        $row['read_epoch']=time();return vp3_phone_recording_legacy_public_v1($row);
    }
    $id=vp3_phone_recording_job_id_v1($opaqueId);$job=vp3_phone_recording_row_v1($pdo,$id);
    if((int)$job['owner_user_id']!==(int)$user['id']||($agentId>0&&(int)$job['agent_id']!==$agentId))throw new RuntimeException('Recording not found.',404);
    $pdo->prepare('UPDATE phone_recording_jobs_v1 SET read_epoch=? WHERE id=? AND owner_user_id=?')->execute([time(),$id,(int)$user['id']]);return vp3_phone_recording_public_v1(vp3_phone_recording_row_v1($pdo,$id));
}
function vp3_phone_recording_call_jobs_v1(PDO $pdo,array $user,string $kind,int $callId): array
{
    $q=$pdo->prepare("SELECT * FROM phone_recording_jobs_v1 WHERE owner_user_id=? AND origin_kind=? AND call_id=? AND state<>'deleted' ORDER BY id DESC LIMIT 10");$q->execute([(int)$user['id'],$kind,$callId]);return array_map('vp3_phone_recording_public_v1',$q->fetchAll(PDO::FETCH_ASSOC));
}
function vp3_phone_recording_retry_v1(PDO $pdo,array $user,int $id): array
{
    $job=vp3_phone_recording_row_v1($pdo,$id);if((int)$job['owner_user_id']!==(int)$user['id'])throw new RuntimeException('Recording not found.',404);
    $authority=vp3_phone_recording_authorize_v1($pdo,$job);
    if(($authority['call']['transport_kind']??'')==='managed_callback')vp3_phone_recording_authorize_v1($pdo,$job,true);
    if($job['state']!=='failed'||$job['error_code']==='recording_unavailable'||(int)$job['speech_dispatched']||(int)$job['analysis_dispatched'])throw new RuntimeException('This provider attempt needs review before any additional paid work. It will not replay automatically.',409);
    $policy=vp3_phone_recording_policy_v1($pdo,$user,(int)$job['agent_id']);
    $pdo->prepare("UPDATE phone_recording_jobs_v1 SET state='queued',stage='queued',policy_json=?,error_code='',last_error='',lease_token='',lease_until=0,updated_epoch=? WHERE id=? AND state='failed' AND speech_dispatched=0 AND analysis_dispatched=0")->execute([vp3_phone_recording_json_v1($policy),time(),$id]);
    return vp3_phone_recording_public_v1(vp3_phone_recording_row_v1($pdo,$id));
}
/** Fixed provider address. RecordingUrl from a callback is deliberately never used. */
function vp3_phone_recording_fetch_v1(array $job,?callable $transport=null): string
{
    $sid=(string)$job['provider_recording_sid'];$account=(string)$job['provider_account_sid'];
    if(!preg_match('/^RE[a-f0-9]{32}$/iD',$sid)||!preg_match('/^AC[a-f0-9]{32}$/iD',$account))throw new RuntimeException('Invalid recording address.');
    $endpoint='https://api.twilio.com/2010-04-01/Accounts/'.$account.'/Recordings/'.$sid.'.mp3';
    if($transport){$bytes=$transport($endpoint,$job);if(!is_string($bytes))throw new RuntimeException('The provider recording was unavailable.');}
    else{
        [$configured,$secret]=vp3_phone_twilio_credentials_v100();if(!hash_equals($account,$configured)||$secret==='')throw new RuntimeException('The recording provider account changed.');
        if(!function_exists('curl_init'))throw new RuntimeException('Recording transport is unavailable.');$curl=curl_init($endpoint);$bytes='';
        curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERPWD=>$account.':'.$secret,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$bytes):int{if(strlen($bytes)+strlen($chunk)>VP3_PHONE_RECORDING_MAX_BYTES_V1)return 0;$bytes.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$mime=(string)curl_getinfo($curl,CURLINFO_CONTENT_TYPE);curl_close($curl);
        if(!$ok||$status!==200||!str_starts_with(strtolower($mime),'audio/'))throw new RuntimeException('The provider recording is unavailable. Retry playback later.');
    }
    $head=substr($bytes,0,3);$frame=strlen($bytes)>=2&&ord($bytes[0])===255&&(ord($bytes[1])&224)===224;
    if(strlen($bytes)<32||strlen($bytes)>VP3_PHONE_RECORDING_MAX_BYTES_V1||($head!=='ID3'&&!$frame))throw new RuntimeException('The provider returned invalid or oversized MP3 audio.');
    return $bytes;
}
function vp3_phone_recording_source_path_v1(array $job): string
{
    return STONEFELLOW_ROOT.'/private/phone-recordings-v1/'.(int)$job['owner_user_id'].'/'.(int)$job['id'].'/source.mp3';
}
function vp3_phone_recording_store_source_v1(array $job,string $bytes): string
{
    $path=vp3_phone_recording_source_path_v1($job);$dir=dirname($path);
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Private recording storage is unavailable.');@chmod($dir,0700);
    $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';if(file_put_contents($tmp,$bytes,LOCK_EX)!==strlen($bytes)){@unlink($tmp);throw new RuntimeException('The recording could not be retained.');}@chmod($tmp,0600);
    if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('The recording could not be retained.');}return $path;
}
function vp3_phone_recording_claim_v1(PDO $pdo): ?array
{
    $now=time();
    // An interrupted paid attempt is never put back on the automatic queue.
    $q=$pdo->prepare("SELECT * FROM phone_recording_jobs_v1 WHERE state IN ('transcribing','analyzing') AND lease_until>0 AND lease_until<? ORDER BY id LIMIT 25");$q->execute([$now]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $expired){$u=$pdo->prepare("UPDATE phone_recording_jobs_v1 SET state='failed',stage='interrupted',error_code='interrupted_review',last_error='Processing was interrupted. Review the saved result before another paid attempt.',lease_token='',lease_until=0,updated_epoch=? WHERE id=? AND lease_token=? AND lease_until<? AND state IN ('transcribing','analyzing')");$u->execute([$now,(int)$expired['id'],$expired['lease_token'],$now]);if($u->rowCount()===1)@unlink(vp3_phone_recording_source_path_v1($expired));}
    $q=$pdo->query("SELECT id FROM phone_recording_jobs_v1 WHERE state='queued' ORDER BY id LIMIT 1");$id=(int)($q->fetchColumn()?:0);if($id<1)return null;
    $token=bin2hex(random_bytes(32));$q=$pdo->prepare("UPDATE phone_recording_jobs_v1 SET state='transcribing',stage='preparing',lease_token=?,lease_until=?,attempts=attempts+1,updated_epoch=? WHERE id=? AND state='queued'");$q->execute([$token,$now+VP3_PHONE_RECORDING_LEASE_SECONDS_V1,$now,$id]);
    return $q->rowCount()===1?vp3_phone_recording_row_v1($pdo,$id):null;
}
function vp3_phone_recording_checkpoint_v1(PDO $pdo,array $job,array $values): array
{
    $allowed=['state','stage','session_id','recording_key','audio_sha256','transcript_hash','analysis_hash','conversation_id','report_message_id','speech_dispatched','analysis_dispatched','error_code','last_error'];
    $set=[];$args=[];foreach($values as $key=>$value){if(!in_array($key,$allowed,true))throw new RuntimeException('Invalid recording checkpoint.');$set[]=$key.'=?';$args[]=$value;}
    $set[]='updated_epoch=?';$args[]=time();$args[]=(int)$job['id'];$args[]=(string)$job['lease_token'];$args[]=time();
    $q=$pdo->prepare('UPDATE phone_recording_jobs_v1 SET '.implode(',',$set).' WHERE id=? AND lease_token=? AND lease_until>=?');$q->execute($args);
    if($q->rowCount()!==1){
        // Native MySQL reports changed rows, so an identical checkpoint in the
        // same second can legitimately affect zero. Use a current locking read
        // rather than a transaction snapshot to distinguish it from lost work.
        $q=$pdo->prepare('SELECT * FROM phone_recording_jobs_v1 WHERE id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([(int)$job['id']]);$fresh=$q->fetch(PDO::FETCH_ASSOC);
        if(!$fresh||$job['lease_token']===''||!hash_equals((string)$job['lease_token'],(string)$fresh['lease_token'])||(int)$fresh['lease_until']<time())throw new RuntimeException('Recording worker lease changed.',409);
        foreach($values as $key=>$value)if((string)$fresh[$key]!== (string)$value)throw new RuntimeException('Recording worker lease changed.',409);
        return $fresh;
    }
    return vp3_phone_recording_row_v1($pdo,(int)$job['id']);
}
function vp3_phone_recording_import_v1(PDO $pdo,array $job,array $user,array $speech,string $path,bool $retain): array
{
    vp3_phone_recording_stack_v1();$segments=$speech['segments']??null;
    if(!is_array($segments)||!array_is_list($segments)||count($segments)>500)throw new RuntimeException('Speech service returned no usable transcript.');
    $client='phone-'.substr(hash('sha256',$job['owner_user_id'].':'.$job['agent_id'].':'.$job['provider_recording_sid']),0,40);$now=gmdate('Y-m-d H:i:s');
    $q=$pdo->prepare('SELECT * FROM artist_transcript_sessions_v172 WHERE created_by_user_id=? AND client_session_key=?');$q->execute([(int)$user['id'],$client]);$existing=$q->fetch(PDO::FETCH_ASSOC);
    $contentHash=hash('sha256',vp3_phone_recording_json_v1($segments));
    if($existing){
        vp3_phone_recording_authorize_v1($pdo,$job,true);$oldMeta=vp3_phone_recording_decode_v1($existing['metadata_json']);
        if($existing['discarded_at']!==null||(int)($oldMeta['phone_recording_v1']['job_id']??0)!==(int)$job['id'])throw new RuntimeException('A different or removed transcript already exists for this recording.',409);
        if(hash_equals((string)($oldMeta['phone_recording_v1']['import_hash']??''),$contentHash))return ['session_id'=>(int)$existing['id'],'recording_key'=>(string)($oldMeta['phone_recording_v1']['recording_key']??''),'transcript_hash'=>(string)($oldMeta['phone_recording_v1']['transcript_hash']??'')];
        $oldKey=(string)($oldMeta['phone_recording_v1']['recording_key']??'');$retained=false;foreach($oldMeta['recordings_v197']??[] as $recording)if(($recording['key']??'')===$oldKey)$retained=true;
        if(!$retained||!preg_match('/^[a-f0-9]{32}$/D',$oldKey)||!is_file(artist_listening_v197_private_dir($user,(int)$existing['id']).'/'.$oldKey.'.mp3'))throw new RuntimeException('The saved voicemail audio was removed.',409);
        $q=$pdo->prepare('SELECT COUNT(*) FROM artist_transcript_segments_v172 WHERE session_id=?');$q->execute([(int)$existing['id']]);
        if(!vp3_phone_voicemail_job_v1($job)||(int)$q->fetchColumn()>0||!hash_equals((string)($oldMeta['phone_recording_v1']['audio_sha256']??''),(string)hash_file('sha256',$path)))throw new RuntimeException('The saved voice message changed. Review it before replacing its transcript.',409);
    }
    $key=$retain?substr(hash('sha256','phone-audio:'.$job['provider_recording_sid']),0,32):'';$duration=(int)$job['duration_seconds']*1000;
    $origin=['job_id'=>(int)$job['id'],'call_kind'=>$job['origin_kind'],'call_id'=>$job['call_public_id'],'agent_id'=>(int)$job['agent_id'],'import_hash'=>$contentHash,'audio_sha256'=>hash_file('sha256',$path),'recording_key'=>$key];
    $call=vp3_phone_recording_call_v1($pdo,$job);
    if(($call['transport_kind']??'')==='managed_callback')$origin['transport_kind']='managed_callback';
    $meta=['source'=>'phone_call_v1','capture_mode'=>'phone_recording_import','audio_retained'=>$retain,'phone_recording_v1'=>$origin];
    $pdo->beginTransaction();$copied='';
    try{
        vp3_phone_recording_lock_authority_v1($pdo,$job,true);
        $title=(vp3_phone_voicemail_job_v1($job)?'Voicemail · ':'Phone call · ').gmdate('Y-m-d H:i',(int)$job['created_epoch']);
        if($existing){
            $q=$pdo->prepare('SELECT metadata_json,discarded_at FROM artist_transcript_sessions_v172 WHERE id=? AND created_by_user_id=?');$q->execute([(int)$existing['id'],(int)$user['id']]);$locked=$q->fetch(PDO::FETCH_ASSOC);
            $q=$pdo->prepare('SELECT COUNT(*) FROM artist_transcript_segments_v172 WHERE session_id=?');$q->execute([(int)$existing['id']]);
            if(!$locked||$locked['discarded_at']!==null||(int)$q->fetchColumn()>0||!hash_equals((string)$existing['metadata_json'],(string)$locked['metadata_json']))throw new RuntimeException('The saved voice message changed before transcription finished.',409);
            $session=(int)$existing['id'];$pdo->prepare('UPDATE artist_transcript_sessions_v172 SET metadata_json=?,last_activity_at=? WHERE id=?')->execute([vp3_phone_recording_json_v1($meta),$now,$session]);
        }else{
            $pdo->prepare("INSERT INTO artist_transcript_sessions_v172 (owner_user_id,created_by_user_id,conversation_id,client_session_key,title,status,language,metadata_json,duration_ms,started_at,stopped_at,last_activity_at) VALUES (?,?,?,?,?,'draft','en-US',?,?,?,?,?)")->execute([(int)$user['id'],(int)$user['id'],(int)$job['conversation_id']?:null,$client,$title,vp3_phone_recording_json_v1($meta),$duration,$now,$now,$now]);
            $session=(int)$pdo->lastInsertId();
        }
        if($retain){$dir=artist_listening_v197_private_dir($user,$session);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Recording storage is unavailable.');@chmod($dir,0700);$target=$dir.'/'.$key.'.mp3';if(!$existing){$copied=$target;if(!copy($path,$copied))throw new RuntimeException('The recording could not be retained.');@chmod($copied,0600);}
            $meta['recordings_v197']=[['key'=>$key,'file_name'=>$key.'.mp3','mime_type'=>'audio/mpeg','bytes'=>filesize($target),'duration_ms'=>$duration,'started_ms'=>0,'ended_ms'=>$duration,'created_at'=>gmdate('c')]];
            $pdo->prepare('UPDATE artist_transcript_sessions_v172 SET metadata_json=? WHERE id=?')->execute([vp3_phone_recording_json_v1($meta),$session]);}
        $insert=$pdo->prepare("INSERT INTO artist_transcript_segments_v172 (session_id,client_segment_key,segment_index,segment_type,speaker_label,transcript_text,started_ms,ended_ms,confidence) VALUES (?,?,?,'transcript',?,?,?,?,?)");
        foreach($segments as $index=>$segment){
            $text=$segment['text']??null;$speaker=$segment['speaker']??null;$start=$segment['started_ms']??null;$end=$segment['ended_ms']??null;
            if(!is_string($text)||trim($text)===''||mb_strlen($text)>8000||!is_string($speaker)||mb_strlen($speaker)>80||!is_int($start)||!is_int($end)||$start<0||$end<$start||$end>$duration+1000)throw new RuntimeException('Invalid recording transcript segment.');
            $insert->execute([$session,substr(hash('sha256',$client.':'.$index.':'.$text),0,40),$index,$speaker,$text,$start,$end,is_numeric($segment['confidence']??null)?max(0,min(1,(float)$segment['confidence'])):null]);}
        $map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,$session));$meta['phone_recording_v1']['transcript_hash']=$map['source_hash'];
        $pdo->prepare('UPDATE artist_transcript_sessions_v172 SET metadata_json=? WHERE id=?')->execute([vp3_phone_recording_json_v1($meta),$session]);
        $import=['session_id'=>$session,'recording_key'=>$key,'transcript_hash'=>(string)$map['source_hash']];vp3_phone_recording_checkpoint_v1($pdo,$job,$import);
        $pdo->commit();return $import;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();if($copied!=='')@unlink($copied);throw $error;}
}
function vp3_phone_recording_report_text_v1(array $analysis,bool $transcribed): string
{
    $master=is_array($analysis['master']??null)?$analysis['master']:[];$mods=$master['analysis']['modules']??[];
    $lines=['Phone call report'];if($analysis)$lines[]='AI findings are suggestions awaiting review.';
    $extract=static function(array $rows,string $field):array{$out=[];foreach($rows as $row){$text=is_array($row)?($row[$field]??''):'';if(is_string($text)&&trim($text)!=='')$out[]=mb_substr(preg_replace('/\s+/u',' ',trim($text))??'',0,650);if(count($out)>=4)break;}return $out;};
    $findings=$extract((array)($mods['basic']['result']['key_findings']??[]),'text');$summary=$mods['basic']['result']['summary']??'';if(is_string($summary)&&trim($summary)!=='')array_unshift($findings,mb_substr(trim($summary),0,700));
    $actions=array_merge($extract((array)($mods['actions']['result']['items']??[]),'action'),$extract((array)($mods['followup']['result']['items']??[]),'follow_up'));
    $questions=$extract((array)($mods['qa']['result']['unanswered']??[]),'question');
    foreach(['Key findings'=>$findings,'Actions & follow-ups'=>array_slice($actions,0,4),'Open questions'=>$questions] as $heading=>$items){$lines[]="\n".$heading;$lines[]=$items?'• '.implode("\n• ",$items):($transcribed?'No saved finding in this section.':'Automatic transcription is off.');}
    $lines[]="\nNext step\n".($actions[0]??($transcribed?'Review the transcript and selected analysis.':'Play the saved message; enable post-call processing for future recordings if wanted.'));
    $lines[]="\nQuestions for you\n".($questions[0]??'Which follow-up would you like to review?');
    return implode("\n",$lines);
}
function vp3_phone_recording_assert_source_v1(PDO $pdo,array $job): void
{
    $q=$pdo->prepare('SELECT metadata_json,discarded_at FROM artist_transcript_sessions_v172 WHERE id=? AND created_by_user_id=?');
    $q->execute([(int)$job['session_id'],(int)$job['owner_user_id']]);$session=$q->fetch(PDO::FETCH_ASSOC);
    $meta=$session?vp3_phone_recording_decode_v1($session['metadata_json']):[];
    if(!$session||$session['discarded_at']!==null||($meta['source']??'')!=='phone_call_v1'||(int)($meta['phone_recording_v1']['job_id']??0)!==(int)$job['id'])throw new RuntimeException('The phone transcript source changed.',409);
    $map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,(int)$job['session_id']));
    if(!hash_equals((string)$job['transcript_hash'],(string)$map['source_hash']))throw new RuntimeException('The phone transcript changed; the saved copy was preserved.',409);
}
function vp3_phone_recording_deliver_v1(PDO $pdo,array $job,array $call,array $analysis,bool $transcribed): array
{
    if(!function_exists('vp3_phone_workspace_delivery_v1'))throw new RuntimeException('Phone Chat delivery is unavailable.');
    if(!vp3_phone_voicemail_announce_allowed_v1($pdo,$job))return ['message_id'=>0,'conversation_id'=>(int)$job['conversation_id'],'suppressed'=>true];
    $view=vp3_phone_recording_public_v1($job);$text=vp3_phone_voicemail_job_v1($job)?vp3_phone_voicemail_text_v1($pdo,$job,$call):vp3_phone_recording_report_text_v1($analysis,$transcribed);
    if((int)$job['session_id']>0)$text.="\n\nTranscript: ".url('/artist-listening.php?session='.(int)$job['session_id']);
    $event='phone-recording-report:'.$job['public_id'];
    // A newly requested transcript gets one new report even when an earlier
    // audio-only report exists. Replayed completion uses the same source hash.
    if(vp3_phone_voicemail_job_v1($job)&&$transcribed)$event.=':'.$job['transcript_hash'];
    return vp3_phone_workspace_delivery_v1($pdo,$call,'voicemail',$event,$text,
        ['voicemail_id'=>'job-'.(int)$job['id'],'status'=>'ready','recording_refs'=>$view['recording_refs'],'transcript_session_id'=>(int)$job['session_id'],'report'=>['text'=>$text,'review_state'=>'unreviewed','source_hash'=>$job['transcript_hash']]]);
}
/** One bounded job; adapters are server-injected test transports, never request input. */
function vp3_phone_recording_worker_once_v1(PDO $pdo,array $adapters=[]): array
{
    $job=vp3_phone_recording_claim_v1($pdo);if(!$job)return ['ok'=>true,'reason'=>'no_work'];$path='';
    try{
        $authority=vp3_phone_recording_authorize_v1($pdo,$job);$user=$authority['user'];$call=$authority['call'];$policy=vp3_phone_recording_decode_v1($job['policy_json']);$analysis=[];$transcribed=false;
        if(!empty($policy['enabled'])){
            vp3_phone_recording_authorize_v1($pdo,$job,true);
            if(!empty($policy['analyze'])&&!isset($adapters['analyze'])){vp3_phone_recording_stack_v1();vp3_ai_direct_account_route_v1($user);}
            $job=vp3_phone_recording_checkpoint_v1($pdo,$job,['stage'=>'fetching']);$bytes=vp3_phone_recording_fetch_v1($job,$adapters['fetch']??null);$path=vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$job,$bytes){vp3_phone_recording_lock_authority_v1($pdo,$job,true);return vp3_phone_recording_store_source_v1($job,$bytes);});unset($bytes);
            $job=vp3_phone_recording_checkpoint_v1($pdo,$job,['audio_sha256'=>hash_file('sha256',$path)]);
            vp3_phone_recording_authorize_v1($pdo,$job,true);
            if(empty($policy['transcribe'])&&!empty($policy['retain_audio'])){
                $import=vp3_phone_recording_import_v1($pdo,$job,$user,['segments'=>[]],$path,true);$job=vp3_phone_recording_row_v1($pdo,(int)$job['id']);
            }
            if(!empty($policy['transcribe'])){
                require_once __DIR__.'/phone-recording-speech-v1.php';
                vp3_phone_recording_reserve_speech_v1($pdo,$job);
                $job=vp3_phone_recording_checkpoint_v1($pdo,$job,['stage'=>'speech','speech_dispatched'=>1]);
                $speech=vp3_phone_recording_transcribe_v1($path,(int)$job['duration_seconds'],$user,$adapters['speech']??null);
                vp3_phone_recording_authorize_v1($pdo,$job,true);
                $import=vp3_phone_recording_import_v1($pdo,$job,$user,$speech,$path,!empty($policy['retain_audio']));
                $job=vp3_phone_recording_row_v1($pdo,(int)$job['id']);$transcribed=true;
                if(!empty($policy['analyze'])){
                    vp3_phone_recording_authorize_v1($pdo,$job,true);vp3_phone_recording_stack_v1();$modules=vp3_phone_recording_modules_v1((array)$policy['modules']);
                    vp3_phone_recording_assert_source_v1($pdo,$job);
                    $map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,(int)$job['session_id']));
                    if((int)$map['page_count']>4)throw new RuntimeException('The transcript was saved. Automatic call analysis is limited to four transcript pages; review longer calls in Transcription.');
                    if(!isset($adapters['analyze']))vp3_ai_direct_account_route_v1($user);
                    $job=vp3_phone_recording_checkpoint_v1($pdo,$job,['state'=>'analyzing','stage'=>'analysis','analysis_dispatched'=>1]);
                    $workflow=['depth'=>'standard','live_analysis'=>false,'web_research'=>false,'context_mode'=>'transcript','focus'=>'Identify supported call findings, questions, decisions and follow-ups.'];
                    $analysis=vp3_ai_direct_authority_scope_v1(
                        static function()use($pdo,$job):void{
                            $fresh=vp3_phone_recording_row_v1($pdo,(int)$job['id']);
                            if(!hash_equals((string)$job['lease_token'],(string)$fresh['lease_token'])||(int)$fresh['lease_until']<time()||$fresh['state']!=='analyzing')throw new RuntimeException('Recording worker lease changed.',409);
                            vp3_phone_recording_authorize_v1($pdo,$job,true);vp3_phone_recording_assert_source_v1($pdo,$job);
                        },
                        static fn()=>isset($adapters['analyze'])?$adapters['analyze']($pdo,$user,(int)$job['session_id'],$modules,$workflow):transcription_app_analyze_v307($pdo,$user,(int)$job['session_id'],'manual',$modules,$workflow));
                    if(!is_array($analysis)||!empty($analysis['plugin_errors'])||!empty($analysis['skipped'])||array_diff($modules,(array)($analysis['executed_apps']??[])))throw new RuntimeException('The selected post-call analysis did not fully complete. Review saved results before another paid attempt.');
                    vp3_phone_recording_authorize_v1($pdo,$job,true);
                    vp3_phone_recording_assert_source_v1($pdo,$job);
                    if(!hash_equals((string)$job['transcript_hash'],(string)($analysis['master']['source_hash']??'')))throw new RuntimeException('The saved analysis does not match the phone transcript.',409);
                    foreach($modules as $module)if(!hash_equals((string)$job['transcript_hash'],(string)($analysis['master']['analysis']['modules'][$module]['source_hash']??'')))throw new RuntimeException('A selected module did not retain current transcript evidence.',409);
                    $job=vp3_phone_recording_checkpoint_v1($pdo,$job,['analysis_hash'=>hash('sha256',vp3_phone_recording_json_v1($analysis['master']??[]))]);
                }
            }
        }
        $pdo->beginTransaction();try{
            $authority=vp3_phone_recording_lock_authority_v1($pdo,$job,!empty($policy['enabled']));
            if($transcribed)vp3_phone_recording_assert_source_v1($pdo,$job);
            $job=vp3_phone_recording_checkpoint_v1($pdo,$job,['stage'=>'report']);$delivery=vp3_phone_voicemail_job_v1($job)&&!$transcribed?['message_id'=>0,'conversation_id'=>(int)$job['conversation_id'],'suppressed'=>true]:vp3_phone_recording_deliver_v1($pdo,$job,$authority['call'],$analysis,$transcribed);
            if((int)($delivery['message_id']??0)<1&&empty($delivery['suppressed']))throw new RuntimeException('Phone Chat report was not saved.');
            $job=vp3_phone_recording_checkpoint_v1($pdo,$job,['state'=>'ready','stage'=>'ready','report_message_id'=>(int)$delivery['message_id'],'conversation_id'=>(int)$delivery['conversation_id']]);
            $pdo->prepare("UPDATE phone_recording_jobs_v1 SET lease_token='',lease_until=0 WHERE id=? AND lease_token=?")->execute([(int)$job['id'],$job['lease_token']]);$pdo->commit();
        }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
        if($path!=='')@unlink($path);return ['ok'=>true,'reason'=>'ready','recording'=>vp3_phone_recording_public_v1($job)];
    }catch(Throwable $error){
        $fresh=vp3_phone_recording_row_v1($pdo,(int)$job['id']);$paid=(int)$fresh['speech_dispatched']||(int)$fresh['analysis_dispatched'];
        if($error instanceof VP3PhoneRecordingSpeechErrorV1&&!$error->requestStarted&&!(int)$fresh['analysis_dispatched']){$paid=false;$pdo->prepare('UPDATE phone_recording_jobs_v1 SET speech_dispatched=0 WHERE id=? AND lease_token=?')->execute([(int)$job['id'],$job['lease_token']]);}
        $code=$paid?'provider_result_review':'preflight_failed';$message=$paid?'Post-call processing did not finish. Review the saved transcript/provider result before another paid attempt.':mb_substr($error->getMessage(),0,300);
        if($error instanceof RuntimeException&&in_array($error->getCode(),[403,409],true)){$code='authorization_changed';$message=mb_substr($error->getMessage(),0,300);}
        $q=$pdo->prepare("UPDATE phone_recording_jobs_v1 SET state='failed',stage='failed',error_code=?,last_error=?,lease_token='',lease_until=0,updated_epoch=? WHERE id=? AND lease_token=?");$q->execute([$code,$message,time(),(int)$job['id'],$job['lease_token']]);
        if($path!=='')@unlink($path);return ['ok'=>false,'reason'=>$code,'recording'=>vp3_phone_recording_public_v1(vp3_phone_recording_row_v1($pdo,(int)$job['id']))];
    }
}
/** Conservative per-owner paid speech allowance; uncertain attempts remain counted. */
function vp3_phone_recording_reserve_speech_v1(PDO $pdo,array $job): void
{
    $seconds=(int)$job['duration_seconds'];if($seconds<1||$seconds>3600)throw new RuntimeException('Phone speech processing requires a recording from one second to one hour.',422);
    if($pdo->inTransaction())throw new RuntimeException('Speech reservation requires its own short transaction.');
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT id FROM users WHERE id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([(int)$job['owner_user_id']]);if(!$q->fetchColumn())throw new RuntimeException('Phone recording access changed.',403);
        $q=$pdo->prepare('SELECT reserved_epoch FROM phone_recording_speech_budget_v1 WHERE owner_user_id=? AND job_id=?');$q->execute([(int)$job['owner_user_id'],(int)$job['id']]);
        $reserved=$q->fetchColumn();
        if($reserved===false||(int)$reserved<=time()-3600){$q=$pdo->prepare('SELECT COALESCE(SUM(seconds),0) FROM phone_recording_speech_budget_v1 WHERE owner_user_id=? AND reserved_epoch>?');$q->execute([(int)$job['owner_user_id'],time()-3600]);
            if((int)$q->fetchColumn()+$seconds>3600)throw new RuntimeException('Automatic phone transcription reached its 60-minute hourly owner allowance. The recording remains available.',429);
            if($reserved===false)$pdo->prepare('INSERT INTO phone_recording_speech_budget_v1(owner_user_id,job_id,seconds,reserved_epoch) VALUES (?,?,?,?)')->execute([(int)$job['owner_user_id'],(int)$job['id'],$seconds,time()]);
            else $pdo->prepare('UPDATE phone_recording_speech_budget_v1 SET seconds=?,reserved_epoch=? WHERE owner_user_id=? AND job_id=?')->execute([$seconds,time(),(int)$job['owner_user_id'],(int)$job['id']]);}
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function vp3_phone_recording_worker_tick_v1(PDO $pdo,int $limit=1,array $adapters=[]): array
{
    $out=['completed'=>0,'failed'=>0,'results'=>[]];$cleanup=vp3_phone_voicemail_purge_once_v1($pdo,$adapters['delete']??null);if($cleanup){$out[$cleanup['ok']?'completed':'failed']++;$out['results'][]=$cleanup;}for($i=0;$i<max(1,min(3,$limit));$i++){$r=vp3_phone_recording_worker_once_v1($pdo,$adapters);if(($r['reason']??'')==='no_work')break;$out[$r['ok']?'completed':'failed']++;$out['results'][]=$r;}return $out;
}
/** A read-only CLI observation, never evidence that a queued job has completed. */
function vp3_phone_recording_worker_health_v1(?int $clock=null): array
{
    $empty=['state'=>'never_seen','observation_scope'=>'server','last_observed_at'=>'','outcome'=>''];
    $path=STONEFELLOW_ROOT.'/private/phone-recordings-v1/worker-health.json';
    if(is_link($path)||!is_file($path))return $empty;
    $size=@filesize($path);if($size===false||$size>2048)return $empty;
    $raw=@file_get_contents($path);$data=is_string($raw)?json_decode($raw,true):null;$now=$clock??time();
    if(!is_array($data)||($data['source']??'')!=='phone_recording_cli'||!is_int($data['observed_epoch']??null)||$data['observed_epoch']<1||$data['observed_epoch']>$now||!in_array($data['outcome']??'', ['processing','idle','completed','failed'],true))return $empty;
    return ['state'=>$now-$data['observed_epoch']<=1200?'observed':'stale','observation_scope'=>'server','last_observed_at'=>gmdate(DATE_ATOM,$data['observed_epoch']),'outcome'=>$data['outcome']];
}
function vp3_phone_recording_worker_observe_v1(string $outcome): void
{
    if(PHP_SAPI!=='cli'||!in_array($outcome,['processing','idle','completed','failed'],true))return;
    $path=STONEFELLOW_ROOT.'/private/phone-recordings-v1/worker-health.json';if(is_link($path)||!is_dir(dirname($path)))return;
    $temporary=$path.'.'.bin2hex(random_bytes(6)).'.tmp';$json=vp3_phone_recording_json_v1(['source'=>'phone_recording_cli','observed_epoch'=>time(),'outcome'=>$outcome]);
    if(file_put_contents($temporary,$json,LOCK_EX)===strlen($json)){@chmod($temporary,0600);if(!rename($temporary,$path))@unlink($temporary);}else @unlink($temporary);
}
/** Historical playback stays with the immutable receipt owner after number transfer. */
function vp3_phone_recording_audio_v1(PDO $pdo,array $user,int $id): array
{
    $user=vp3_phone_recording_owner_v1($pdo,(int)($user['id']??0));$job=vp3_phone_recording_row_v1($pdo,$id);
    if((int)$job['owner_user_id']!==(int)$user['id']||$job['state']==='deleted'||vp3_phone_voicemail_deleted_v1($pdo,(string)$job['provider_recording_sid']))throw new RuntimeException('Recording not found.',404);$call=vp3_phone_recording_call_v1($pdo,$job);
    if(($call['transport_kind']??'')==='managed_callback'){
        require_once __DIR__.'/phone-managed-recording-v1.php';
        vp3_managed_recording_saved_policy_v1($pdo,$call,(string)$job['provider_recording_sid']);
    }
    if((int)$job['session_id']>0&&$job['recording_key']!==''){
        require_once __DIR__.'/artist-listening.php';
        $q=$pdo->prepare('SELECT metadata_json,discarded_at FROM artist_transcript_sessions_v172 WHERE id=? AND created_by_user_id=?');$q->execute([(int)$job['session_id'],(int)$user['id']]);$session=$q->fetch(PDO::FETCH_ASSOC);
        if(!$session||$session['discarded_at']!==null)throw new RuntimeException('This retained recording was removed.',404);
        $metadata=vp3_phone_recording_decode_v1($session['metadata_json']);$found=false;foreach($metadata['recordings_v197']??[] as $recording)if(($recording['key']??'')===$job['recording_key'])$found=true;
        if(!$found)throw new RuntimeException('This retained recording was removed.',404);
        $path=artist_listening_v197_private_dir($user,(int)$job['session_id']).'/'.$job['recording_key'].'.mp3';if(!is_file($path))throw new RuntimeException('Retained recording unavailable.',404);
        return ['path'=>$path,'mime'=>'audio/mpeg'];
    }
    return ['bytes'=>vp3_phone_recording_fetch_v1($job),'mime'=>'audio/mpeg'];
}
function vp3_phone_recording_audio_any_v1(PDO $pdo,array $user,string $id): array
{
    if(str_starts_with($id,'job-'))return vp3_phone_recording_audio_v1($pdo,$user,vp3_phone_recording_job_id_v1($id));
    $user=vp3_phone_recording_owner_v1($pdo,(int)($user['id']??0));
    if(!preg_match('/^legacy-([1-9][0-9]{0,17})$/D',$id,$match))throw new RuntimeException('Recording not found.',404);
    $row=vp3_phone_recording_legacy_row_v1($pdo,$user,(int)$match[1]);[$account]=vp3_phone_twilio_credentials_v100();
    return ['bytes'=>vp3_phone_recording_fetch_v1(['provider_recording_sid'=>$row['provider_recording_sid'],'provider_account_sid'=>$account]),'mime'=>'audio/mpeg'];
}
