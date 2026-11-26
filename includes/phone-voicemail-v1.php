<?php
declare(strict_types=1);

/** Owner voicemail controls. Greetings, messages and call recordings have separate authority. */
const VP3_PHONE_VOICEMAIL_GREETING_MAX_V1=1920044;

function vp3_phone_voicemail_owner_private_v1(): bool
{
    return !function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null;
}
function vp3_phone_voicemail_require_private_v1(): void
{
    if(!vp3_phone_voicemail_owner_private_v1())vp3_phone_workspace_error_v1('phone_private_owner_required',403,'Open your private Phone workspace to manage voicemail.');
}
function vp3_phone_voicemail_ready_v1(PDO $pdo): bool
{
    try{
        $pdo->query('SELECT phone_number_id,owner_user_id,agent_id,revision,rings,transcribe,announce,greeting_wav,greeting_sha256,greeting_seconds FROM phone_voicemail_settings_v1 WHERE 1=0');
        $pdo->query('SELECT provider_call_sid,owner_user_id,agent_id,phone_number_id,policy_json FROM phone_voicemail_receipts_v1 WHERE 1=0');
        $pdo->query('SELECT token_hash,owner_user_id,agent_id,phone_number_id,provider_call_sid,greeting_sha256,expires_epoch FROM phone_voicemail_greeting_tokens_v1 WHERE 1=0');
        $pdo->query('SELECT provider_recording_sid,owner_user_id,provider_account_sid,job_id,legacy_id,deleted_epoch,purge_state,purge_attempts,next_attempt_epoch,updated_epoch FROM phone_voicemail_deletions_v1 WHERE 1=0');return true;
    }catch(PDOException){return false;}
}
function vp3_phone_voicemail_install_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Install voicemail storage outside a transaction.');
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';$tail=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';$blob=$sqlite?'BLOB':'MEDIUMBLOB';
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_voicemail_settings_v1(phone_number_id BIGINT PRIMARY KEY,owner_user_id BIGINT NOT NULL,agent_id BIGINT NOT NULL,revision BIGINT NOT NULL DEFAULT 1,rings INTEGER NOT NULL DEFAULT 4,transcribe INTEGER NOT NULL DEFAULT 0,announce INTEGER NOT NULL DEFAULT 1,greeting_wav $blob NULL,greeting_sha256 CHAR(64) NOT NULL DEFAULT '',greeting_seconds INTEGER NOT NULL DEFAULT 0,updated_epoch BIGINT NOT NULL)$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_voicemail_receipts_v1(provider_call_sid VARCHAR(40) PRIMARY KEY,owner_user_id BIGINT NOT NULL,agent_id BIGINT NOT NULL,phone_number_id BIGINT NOT NULL,policy_json TEXT NOT NULL,created_epoch BIGINT NOT NULL)$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_voicemail_greeting_tokens_v1(token_hash CHAR(64) PRIMARY KEY,owner_user_id BIGINT NOT NULL,agent_id BIGINT NOT NULL,phone_number_id BIGINT NOT NULL,provider_call_sid VARCHAR(40) NOT NULL,greeting_sha256 CHAR(64) NOT NULL,expires_epoch BIGINT NOT NULL)$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_voicemail_deletions_v1(provider_recording_sid VARCHAR(40) PRIMARY KEY,owner_user_id BIGINT NOT NULL,provider_account_sid VARCHAR(40) NOT NULL,job_id BIGINT NOT NULL DEFAULT 0,legacy_id BIGINT NOT NULL DEFAULT 0,deleted_epoch BIGINT NOT NULL,purge_state VARCHAR(20) NOT NULL DEFAULT 'pending',purge_attempts INTEGER NOT NULL DEFAULT 0,next_attempt_epoch BIGINT NOT NULL DEFAULT 0,updated_epoch BIGINT NOT NULL)$tail");
}
function vp3_phone_voicemail_raw_v1(PDO $pdo,int $owner,int $agent,int $number): array
{
    $q=$pdo->prepare('SELECT * FROM phone_voicemail_settings_v1 WHERE phone_number_id=? AND owner_user_id=? AND agent_id=?');$q->execute([$number,$owner,$agent]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if($row)return $row;
    $automatic=false;
    if(function_exists('vp3_phone_recording_policy_v1')&&table_exists('phone_recording_policies_v1')){
        $policy=vp3_phone_recording_policy_v1($pdo,['id'=>$owner],$agent);
        $automatic=$policy['enabled']&&$policy['transcribe'];
    }
    return ['phone_number_id'=>$number,'owner_user_id'=>$owner,'agent_id'=>$agent,'revision'=>0,'rings'=>4,'transcribe'=>(int)$automatic,'announce'=>1,'greeting_sha256'=>'','greeting_seconds'=>0,'greeting_wav'=>null];
}
function vp3_phone_voicemail_settings_v1(PDO $pdo,array $scope,int $numberId): array
{
    if(!vp3_phone_voicemail_owner_private_v1())return ['available'=>false,'reason_code'=>'private_owner_required','reason'=>'Open your private Phone workspace to manage voicemail.'];
    if(!vp3_phone_voicemail_ready_v1($pdo))return ['available'=>false,'reason'=>'Voicemail settings need the Cloud database upgrade. Ask an administrator to run upgrade.php.'];
    $number=vp3_phone_workspace_number_v1($pdo,$scope,$numberId);$row=vp3_phone_voicemail_raw_v1($pdo,$scope['owner_user_id'],(int)$number['agent_id'],$numberId);
    return ['available'=>true,'number_id'=>$numberId,'revision'=>(int)$row['revision'],'rings'=>(int)$row['rings'],'ring_seconds'=>(int)$row['rings']*6,
        'transcribe'=>(bool)$row['transcribe'],'announce'=>(bool)$row['announce'],'greeting'=>['present'=>$row['greeting_sha256']!=='','duration_seconds'=>(int)$row['greeting_seconds'],
        'preview_url'=>$row['greeting_sha256']!==''?url('/api/phone-voicemail-greeting-v1.php?number_id='.$numberId.'&revision='.(int)$row['revision']):'']];
}
function vp3_phone_voicemail_lock_v1(PDO $pdo,array $scope,int $numberId,int $expected): array
{
    vp3_phone_voicemail_require_private_v1();
    if(!vp3_phone_voicemail_ready_v1($pdo))vp3_phone_workspace_error_v1('phone_voicemail_upgrade_required',503,'Voicemail settings need the Cloud database upgrade.');
    $q=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.vp3_phone_workspace_lock_v1($pdo));$q->execute([$scope['owner_user_id']]);if(!$q->fetchColumn())vp3_phone_workspace_error_v1();
    vp3_phone_workspace_live_user_v1($pdo,$scope['user']);$number=vp3_phone_workspace_number_v1($pdo,$scope,$numberId,false,true);
    $row=vp3_phone_voicemail_raw_v1($pdo,$scope['owner_user_id'],(int)$number['agent_id'],$numberId);
    if((int)$row['revision']!==$expected)vp3_phone_workspace_error_v1('phone_voicemail_changed',409,'Voicemail settings changed. Refresh and review them before saving.');return $row;
}
function vp3_phone_voicemail_write_v1(PDO $pdo,array $row): void
{
    // Reassignment never inherits the previous holder's settings or greeting.
    $pdo->prepare('DELETE FROM phone_voicemail_settings_v1 WHERE phone_number_id=?')->execute([(int)$row['phone_number_id']]);
    $q=$pdo->prepare('INSERT INTO phone_voicemail_settings_v1(phone_number_id,owner_user_id,agent_id,revision,rings,transcribe,announce,greeting_wav,greeting_sha256,greeting_seconds,updated_epoch) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $values=[(int)$row['phone_number_id'],(int)$row['owner_user_id'],(int)$row['agent_id'],(int)$row['revision']+1,(int)$row['rings'],(int)$row['transcribe'],(int)$row['announce'],$row['greeting_wav'],$row['greeting_sha256'],(int)$row['greeting_seconds'],time()];
    foreach($values as $i=>$value)$q->bindValue($i+1,$value,$i===7?($value===null?PDO::PARAM_NULL:PDO::PARAM_LOB):(is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR));$q->execute();
    $pdo->prepare('DELETE FROM phone_voicemail_greeting_tokens_v1 WHERE phone_number_id=?')->execute([(int)$row['phone_number_id']]);
}
function vp3_phone_voicemail_save_settings_v1(PDO $pdo,array $scope,array $input): array
{
    $numberId=vp3_phone_workspace_id_v1($input['number_id']??null);$revision=vp3_phone_workspace_id_v1($input['expected_revision']??null,true);$rings=vp3_phone_workspace_id_v1($input['rings']??null);
    if($rings<2||$rings>10||!is_bool($input['transcribe']??null)||!is_bool($input['announce']??null))vp3_phone_workspace_error_v1('invalid_phone_request',422,'Choose two to ten approximate rings and voicemail preferences.');
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$input,$numberId,$revision,$rings){
        $row=vp3_phone_voicemail_lock_v1($pdo,$scope,$numberId,$revision);
        if($input['transcribe']&&(!has_permission('artist_listening.access',$scope['user'])||!subscription_has_entitlement($scope['user'],'main_ai.access')))vp3_phone_workspace_error_v1('phone_voicemail_transcription_access',403,'Transcription and AI access are required to transcribe voicemail.');
        $row['rings']=$rings;$row['transcribe']=$input['transcribe'];$row['announce']=$input['announce'];vp3_phone_voicemail_write_v1($pdo,$row);
        return ['voicemail_settings'=>vp3_phone_voicemail_settings_v1($pdo,$scope,$numberId)];
    });
}
function vp3_phone_voicemail_wav_v1(string $bytes): int
{
    $length=strlen($bytes);$u16=static fn($offset):int=>(int)unpack('v',substr($bytes,$offset,2))[1];$u32=static fn($offset):int=>(int)unpack('V',substr($bytes,$offset,4))[1];
    if($length<32044||$length>VP3_PHONE_VOICEMAIL_GREETING_MAX_V1||substr($bytes,0,4)!=='RIFF'||substr($bytes,8,8)!=='WAVEfmt '||substr($bytes,36,4)!=='data'
        ||$u32(4)!==$length-8||$u32(16)!==16||$u16(20)!==1||$u16(22)!==1||$u32(24)!==16000||$u32(28)!==32000||$u16(32)!==2||$u16(34)!==16||$u32(40)!==$length-44||($length-44)%2!==0)
        vp3_phone_workspace_error_v1('phone_voicemail_greeting_invalid',422,'Record a greeting between one and sixty seconds.');
    return (int)ceil(($length-44)/32000);
}
function vp3_phone_voicemail_greeting_save_v1(PDO $pdo,array $scope,int $numberId,int $revision,string $bytes): array
{
    $seconds=vp3_phone_voicemail_wav_v1($bytes);
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$numberId,$revision,$bytes,$seconds){$row=vp3_phone_voicemail_lock_v1($pdo,$scope,$numberId,$revision);$row['greeting_wav']=$bytes;$row['greeting_sha256']=hash('sha256',$bytes);$row['greeting_seconds']=$seconds;vp3_phone_voicemail_write_v1($pdo,$row);return ['voicemail_settings'=>vp3_phone_voicemail_settings_v1($pdo,$scope,$numberId)];});
}
function vp3_phone_voicemail_greeting_clear_v1(PDO $pdo,array $scope,array $input): array
{
    $numberId=vp3_phone_workspace_id_v1($input['number_id']??null);$revision=vp3_phone_workspace_id_v1($input['expected_revision']??null,true);
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$numberId,$revision){$row=vp3_phone_voicemail_lock_v1($pdo,$scope,$numberId,$revision);$row['greeting_wav']=null;$row['greeting_sha256']='';$row['greeting_seconds']=0;vp3_phone_voicemail_write_v1($pdo,$row);return ['voicemail_settings'=>vp3_phone_voicemail_settings_v1($pdo,$scope,$numberId)];});
}
function vp3_phone_voicemail_policy_v1(array $row,bool $manual=false): array
{
    return ['purpose'=>'voicemail','manual'=>$manual,'enabled'=>$manual||(bool)$row['transcribe'],'retain_audio'=>true,'transcribe'=>$manual||(bool)$row['transcribe'],'analyze'=>false,'modules'=>[],'model_route'=>'account_cloud','revision'=>(int)$row['revision'],'number_id'=>(int)$row['phone_number_id'],'announce'=>(bool)$row['announce']];
}
/** Snapshot voicemail consent only after the existing signed incoming route was accepted. */
function vp3_phone_voicemail_twiml_v1(PDO $pdo,array $route,string $sid,string $name,string $callback): string
{
    if(!vp3_phone_voicemail_ready_v1($pdo))return vp3_phone_voice_twiml_v130($name,$callback);
    $row=vp3_phone_voicemail_raw_v1($pdo,(int)$route['owner_user_id'],(int)$route['agent_id'],(int)$route['phone_number_id']);
    $q=$pdo->prepare('SELECT owner_user_id,agent_id,phone_number_id FROM phone_voicemail_receipts_v1 WHERE provider_call_sid=?');$q->execute([$sid]);$prior=$q->fetch(PDO::FETCH_ASSOC);
    if($prior&&((int)$prior['owner_user_id']!==(int)$route['owner_user_id']||(int)$prior['agent_id']!==(int)$route['agent_id']||(int)$prior['phone_number_id']!==(int)$route['phone_number_id']))throw new RuntimeException('Voicemail receipt changed.',409);
    if(!$prior){try{$pdo->prepare('INSERT INTO phone_voicemail_receipts_v1(provider_call_sid,owner_user_id,agent_id,phone_number_id,policy_json,created_epoch) VALUES (?,?,?,?,?,?)')->execute([$sid,(int)$route['owner_user_id'],(int)$route['agent_id'],(int)$route['phone_number_id'],json_encode(vp3_phone_voicemail_policy_v1($row),JSON_THROW_ON_ERROR),time()]);}catch(PDOException $e){$q->execute([$sid]);if(!$q->fetch())throw $e;}}
    $greeting='';
    if($row['greeting_sha256']!==''){
        $token=bin2hex(random_bytes(32));$pdo->prepare('DELETE FROM phone_voicemail_greeting_tokens_v1 WHERE expires_epoch<?')->execute([time()]);
        $pdo->prepare('INSERT INTO phone_voicemail_greeting_tokens_v1(token_hash,owner_user_id,agent_id,phone_number_id,provider_call_sid,greeting_sha256,expires_epoch) VALUES (?,?,?,?,?,?,?)')->execute([hash('sha256',$token),(int)$route['owner_user_id'],(int)$route['agent_id'],(int)$route['phone_number_id'],$sid,$row['greeting_sha256'],time()+600]);
        $greeting=substr($callback,0,-strlen('phone-voice-inbound-v130.php')).'phone-voicemail-greeting-v1.php?token='.$token;
    }
    return vp3_phone_voice_twiml_v130($name,$callback,$greeting);
}
function vp3_phone_voicemail_ring_timeout_v1(PDO $pdo,array $call): int
{
    if(!vp3_phone_voicemail_ready_v1($pdo))return 20;$row=vp3_phone_voicemail_raw_v1($pdo,(int)$call['owner_user_id'],(int)$call['agent_id'],(int)$call['phone_number_id']);
    // Twilio adds five seconds. A six-second ring cadence is only an estimate.
    return max(5,min(55,(int)$row['rings']*6-5));
}
function vp3_phone_voicemail_greeting_audio_v1(PDO $pdo,?array $user,array $input): string
{
    if(!vp3_phone_voicemail_ready_v1($pdo))throw new RuntimeException('Greeting unavailable.',404);
    if(isset($input['token'])){
        if(!is_string($input['token'])||!preg_match('/^[a-f0-9]{64}$/D',$input['token']))throw new RuntimeException('Greeting unavailable.',404);
        $q=$pdo->prepare('SELECT * FROM phone_voicemail_greeting_tokens_v1 WHERE token_hash=? AND expires_epoch>=?');$q->execute([hash('sha256',$input['token']),time()]);$token=$q->fetch(PDO::FETCH_ASSOC);if(!$token)throw new RuntimeException('Greeting unavailable.',404);
        $route=vp3_phone_voice_current_route_v131($pdo,$token['provider_call_sid'],1800,['voicemail_offered','voicemail_fallback']);
        if(empty($route['accepted'])||(int)$route['owner_user_id']!==(int)$token['owner_user_id']||(int)$route['agent_id']!==(int)$token['agent_id']||(int)$route['phone_number_id']!==(int)$token['phone_number_id'])throw new RuntimeException('Greeting unavailable.',404);
        $row=vp3_phone_voicemail_raw_v1($pdo,(int)$token['owner_user_id'],(int)$token['agent_id'],(int)$token['phone_number_id']);
        if(!hash_equals((string)$token['greeting_sha256'],(string)$row['greeting_sha256']))throw new RuntimeException('Greeting unavailable.',404);
    }else{
        vp3_phone_voicemail_require_private_v1();
        if(!$user)throw new RuntimeException('Sign in to preview your greeting.',401);$scope=vp3_phone_workspace_scope_v1($pdo,$user);
        $numberId=vp3_phone_workspace_id_v1($input['number_id']??null);$revision=vp3_phone_workspace_id_v1($input['revision']??null,true);$number=vp3_phone_workspace_number_v1($pdo,$scope,$numberId);
        $row=vp3_phone_voicemail_raw_v1($pdo,(int)$user['id'],(int)$number['agent_id'],$numberId);if((int)$row['revision']!==$revision)throw new RuntimeException('Greeting unavailable.',404);
    }
    if(!is_string($row['greeting_wav'])||$row['greeting_sha256']===''||!hash_equals($row['greeting_sha256'],hash('sha256',$row['greeting_wav'])))throw new RuntimeException('Greeting unavailable.',404);return $row['greeting_wav'];
}
function vp3_phone_voicemail_deleted_v1(PDO $pdo,string $sid): bool
{
    try{$q=$pdo->prepare('SELECT provider_recording_sid FROM phone_voicemail_deletions_v1 WHERE provider_recording_sid=?');$q->execute([$sid]);return (bool)$q->fetchColumn();}
    catch(PDOException $error){
        if(!vp3_phone_voicemail_missing_table_v1($pdo,$error,'phone_voicemail_deletions_v1'))throw $error;
        // Only a genuinely pre-upgrade installation may lack tombstones. Once
        // voicemail settings exist, missing deletion storage must fail closed.
        try{$pdo->query('SELECT phone_number_id FROM phone_voicemail_settings_v1 WHERE 1=0');}
        catch(PDOException $settingsError){if(vp3_phone_voicemail_missing_table_v1($pdo,$settingsError,'phone_voicemail_settings_v1'))return false;throw $settingsError;}
        throw new RuntimeException('Voicemail deletion storage is unavailable. Run the Cloud database upgrade.',503);
    }
}
function vp3_phone_voicemail_missing_table_v1(PDO $pdo,PDOException $error,string $table): bool
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
        ?str_contains($error->getMessage(),'no such table: '.$table)
        :((int)($error->errorInfo[1]??0)===1146&&str_contains($error->getMessage(),$table));
}
function vp3_phone_voicemail_job_v1(array $job): bool
{
    $policy=json_decode((string)($job['policy_json']??''),true);return !empty($job['voicemail_message'])||($policy['purpose']??'')==='voicemail'||($job['origin_kind']??'')==='legacy';
}
function vp3_phone_voicemail_receipt_policy_v1(PDO $pdo,array $call): array
{
    $default=vp3_phone_voicemail_policy_v1(['phone_number_id'=>(int)$call['phone_number_id'],'revision'=>0,'transcribe'=>false,'announce'=>true]);
    if(!vp3_phone_voicemail_ready_v1($pdo))return $default;
    $q=$pdo->prepare('SELECT * FROM phone_voicemail_receipts_v1 WHERE provider_call_sid=?');$q->execute([$call['provider_call_sid']]);$receipt=$q->fetch(PDO::FETCH_ASSOC);
    if(!$receipt)return $default;
    if((int)$receipt['owner_user_id']!==(int)$call['owner_user_id']||(int)$receipt['agent_id']!==(int)$call['agent_id']||(int)$receipt['phone_number_id']!==(int)$call['phone_number_id'])throw new RuntimeException('Voicemail consent binding changed.',409);
    $policy=json_decode($receipt['policy_json'],true);return is_array($policy)?$policy:$default;
}
function vp3_phone_voicemail_paid_authority_v1(PDO $pdo,array $job,array $policy): void
{
    if(!vp3_phone_voicemail_ready_v1($pdo)||empty($policy['enabled'])||empty($policy['transcribe']))throw new RuntimeException('Voicemail transcription was not authorized.',409);
    if(empty($policy['manual'])){
        $row=vp3_phone_voicemail_raw_v1($pdo,(int)$job['owner_user_id'],(int)$job['agent_id'],(int)$job['phone_number_id']);
        if(!(bool)$row['transcribe']||(int)$row['revision']!==(int)($policy['revision']??-1))throw new RuntimeException('Voicemail transcription preferences changed.',409);
    }
}
function vp3_phone_voicemail_announce_allowed_v1(PDO $pdo,array $job): bool
{
    if(!vp3_phone_voicemail_job_v1($job))return true;
    if(vp3_phone_voicemail_deleted_v1($pdo,(string)$job['provider_recording_sid']))return false;
    if(!vp3_phone_voicemail_ready_v1($pdo))return true;
    $row=vp3_phone_voicemail_raw_v1($pdo,(int)$job['owner_user_id'],(int)$job['agent_id'],(int)$job['phone_number_id']);return (bool)$row['announce'];
}
/** Resolve either namespace to one immutable voicemail; call recordings are excluded. */
function vp3_phone_voicemail_message_v1(PDO $pdo,array $scope,string $opaque): array
{
    $job=null;$legacy=null;
    if(preg_match('/^job-([1-9][0-9]{0,14})$/D',$opaque,$m)){
        $q=$pdo->prepare('SELECT * FROM phone_recording_jobs_v1 WHERE id=? AND owner_user_id=?');$q->execute([(int)$m[1],$scope['owner_user_id']]);$job=$q->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$job||($scope['agent_id']>0&&(int)$job['agent_id']!==$scope['agent_id']))throw new RuntimeException('Voice message not found.',404);
        if(!vp3_phone_voicemail_job_v1($job)){
            $q=$pdo->prepare('SELECT id FROM phone_voice_recordings_v130 WHERE provider_recording_sid=? AND owner_user_id=?');$q->execute([$job['provider_recording_sid'],$scope['owner_user_id']]);if(!$q->fetchColumn())throw new RuntimeException('This is a call recording, not a voicemail message.',422);
        }
    }elseif(preg_match('/^legacy-([1-9][0-9]{0,14})$/D',$opaque,$m)){
        $legacy=vp3_phone_recording_legacy_row_v1($pdo,$scope['user'],(int)$m[1],$scope['agent_id']);
        $q=$pdo->prepare('SELECT * FROM phone_recording_jobs_v1 WHERE provider_recording_sid=? AND owner_user_id=?');$q->execute([$legacy['provider_recording_sid'],$scope['owner_user_id']]);$job=$q->fetch(PDO::FETCH_ASSOC)?:null;
    }else throw new RuntimeException('Voice message not found.',404);
    if($job){$call=vp3_phone_recording_call_v1($pdo,$job);return ['job'=>$job,'legacy'=>$legacy,'call'=>$call,'sid'=>$job['provider_recording_sid'],'account'=>$job['provider_account_sid']];}
    $q=$pdo->prepare('SELECT * FROM phone_voice_calls_v130 WHERE id=? AND owner_user_id=?');$q->execute([(int)$legacy['call_id'],$scope['owner_user_id']]);$call=$q->fetch(PDO::FETCH_ASSOC);if(!$call)throw new RuntimeException('Voice message not found.',404);
    $call['source_kind']='legacy';$call['public_id']='legacy-'.$call['id'];return ['job'=>null,'legacy'=>$legacy,'call'=>$call,'sid'=>$legacy['provider_recording_sid'],'account'=>vp3_phone_twilio_credentials_v100()[0]];
}
function vp3_phone_voicemail_transcribe_v1(PDO $pdo,array $scope,string $opaque): array
{
    vp3_phone_voicemail_require_private_v1();
    if(!vp3_phone_voicemail_ready_v1($pdo))vp3_phone_workspace_error_v1('phone_voicemail_upgrade_required',503,'Voicemail needs the Cloud database upgrade.');
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$opaque){
        $q=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.vp3_phone_workspace_lock_v1($pdo));$q->execute([$scope['owner_user_id']]);if(!$q->fetchColumn())vp3_phone_workspace_error_v1();
        $message=vp3_phone_voicemail_message_v1($pdo,$scope,$opaque);if(vp3_phone_voicemail_deleted_v1($pdo,$message['sid']))throw new RuntimeException('Voice message was deleted.',404);
        $job=$message['job'];
        if(!$job){$call=$message['call'];$call['voicemail_message']=true;vp3_phone_recording_receive_v1($pdo,$call,['RecordingSid'=>$message['sid'],'CallSid'=>$call['provider_call_sid'],'AccountSid'=>$message['account'],'RecordingStatus'=>'completed','RecordingDuration'=>(string)$message['legacy']['duration_seconds']]);$q=$pdo->prepare('SELECT * FROM phone_recording_jobs_v1 WHERE provider_recording_sid=?');$q->execute([$message['sid']]);$job=$q->fetch(PDO::FETCH_ASSOC);}
        vp3_phone_recording_authorize_v1($pdo,$job);
        $user=vp3_phone_recording_owner_v1($pdo,$scope['owner_user_id']);if(!has_permission('artist_listening.access',$user)||!subscription_has_entitlement($user,'main_ai.access'))throw new RuntimeException('Transcription and AI access are required.',403);
        if((int)$job['session_id']>0){
            $q=$pdo->prepare('SELECT discarded_at FROM artist_transcript_sessions_v172 WHERE id=? AND created_by_user_id=?');$q->execute([(int)$job['session_id'],$scope['owner_user_id']]);$session=$q->fetch(PDO::FETCH_ASSOC);
            if(!$session||$session['discarded_at']!==null)throw new RuntimeException('The saved voice message was removed.',404);
            $q=$pdo->prepare('SELECT COUNT(*) FROM artist_transcript_segments_v172 WHERE session_id=?');$q->execute([(int)$job['session_id']]);
            if((int)$q->fetchColumn()>0)return ['recording'=>vp3_phone_recording_public_v1($job),'already_transcribed'=>true];
        }
        if(in_array($job['state'],['transcribing','analyzing'],true)||(int)$job['speech_dispatched']||(int)$job['analysis_dispatched'])throw new RuntimeException('This transcription was already started. Review its saved result before another attempt.',409);
        if($job['error_code']==='recording_unavailable')throw new RuntimeException('The provider did not produce a voice message.',409);
        $row=vp3_phone_voicemail_raw_v1($pdo,$scope['owner_user_id'],(int)$job['agent_id'],(int)$job['phone_number_id']);$policy=vp3_phone_voicemail_policy_v1($row,true);
        $policyJson=json_encode($policy,JSON_THROW_ON_ERROR);
        $q=$pdo->prepare("UPDATE phone_recording_jobs_v1 SET state='queued',stage='queued',policy_json=?,error_code='',last_error='',lease_token='',lease_until=0,updated_epoch=? WHERE id=? AND owner_user_id=? AND state IN ('queued','ready','failed') AND speech_dispatched=0 AND analysis_dispatched=0");$q->execute([$policyJson,time(),(int)$job['id'],$scope['owner_user_id']]);$changed=$q->rowCount();
        $q=$pdo->prepare('SELECT * FROM phone_recording_jobs_v1 WHERE id=? AND owner_user_id=?'.vp3_phone_workspace_lock_v1($pdo));$q->execute([(int)$job['id'],$scope['owner_user_id']]);$fresh=$q->fetch(PDO::FETCH_ASSOC);
        // MySQL may report zero for an identical already-queued request. A
        // concurrently claimed or deleted job must never be reported queued.
        if(!$fresh||($changed!==1&&($fresh['state']!=='queued'||!hash_equals($policyJson,(string)$fresh['policy_json'])||(int)$fresh['speech_dispatched']||(int)$fresh['analysis_dispatched']||$fresh['lease_token']!==''||(int)$fresh['lease_until']!==0)))throw new RuntimeException('This voice message is already being processed. Refresh to see its current status.',409);
        return ['recording'=>vp3_phone_recording_public_v1($fresh),'queued'=>true];
    });
}
function vp3_phone_voicemail_post_v1(PDO $pdo,array $scope,string $opaque): array
{
    vp3_phone_voicemail_require_private_v1();
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$opaque){
        $q=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.vp3_phone_workspace_lock_v1($pdo));$q->execute([$scope['owner_user_id']]);if(!$q->fetchColumn())vp3_phone_workspace_error_v1();
        $message=vp3_phone_voicemail_message_v1($pdo,$scope,$opaque);if(vp3_phone_voicemail_deleted_v1($pdo,$message['sid']))throw new RuntimeException('Voice message was deleted.',404);
        $call=$message['call'];$call['conversation_id']=$scope['conversation_id'];$call['chat_agent_id']=$scope['agent_id'];$job=$message['job'];
        $id=$job?'job-'.(int)$job['id']:'legacy-'.(int)$message['legacy']['id'];$text=vp3_phone_voicemail_text_v1($pdo,$job,$call);$hash=$job&&$job['transcript_hash']!==''?substr($job['transcript_hash'],0,16):'audio';
        $result=vp3_phone_workspace_delivery_v1($pdo,$call,'voicemail','voicemail-post:'.$message['sid'].':'.$scope['conversation_id'].':'.$hash,$text,['voicemail_id'=>$id,'status'=>'ready']);return ['posted'=>true]+$result;
    });
}
function vp3_phone_voicemail_text_v1(PDO $pdo,?array $job,array $call): string
{
    $peer=(string)($call['peer_e164']??$call['caller_e164']??'');$text='Voicemail'.($peer!==''?' from '.$peer:'').'.';
    if($job&&(int)$job['session_id']>0){$q=$pdo->prepare("SELECT s.transcript_text FROM artist_transcript_segments_v172 s JOIN artist_transcript_sessions_v172 t ON t.id=s.session_id WHERE t.id=? AND t.created_by_user_id=? AND t.discarded_at IS NULL AND s.segment_type='transcript' ORDER BY s.segment_index,s.id LIMIT 50");$q->execute([(int)$job['session_id'],(int)$job['owner_user_id']]);$transcript=mb_substr(implode("\n",array_column($q->fetchAll(PDO::FETCH_ASSOC),'transcript_text')),0,8000);if($transcript!=='')$text.="\n\nTranscript\n".$transcript;}
    return $text;
}
function vp3_phone_voicemail_chat_ids_v1(PDO $pdo,int $owner,string $sid,string $public='',int $conversationId=0): array
{
    $keys=['voicemail-post:'.$sid.':%'];if($public!==''){$keys[]='phone-recording:'.$public;$keys[]='phone-recording-report:'.$public;$keys[]='phone-recording-report:'.$public.':%';}
    $conditions=implode(' OR ',array_fill(0,count($keys),'d.event_key LIKE ?'));
    $args=[$owner,...$keys];$conversation='';if($conversationId>0){$conversation=' AND d.conversation_id=?';$args[]=$conversationId;}
    $q=$pdo->prepare('SELECT DISTINCT d.chat_message_id FROM phone_browser_deliveries_v1 d JOIN chat_conversations c ON c.id=d.conversation_id AND c.user_id=d.owner_user_id WHERE d.owner_user_id=? AND ('.$conditions.')'.$conversation.' ORDER BY d.chat_message_id DESC');$q->execute($args);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
}
function vp3_phone_voicemail_delete_v1(PDO $pdo,array $scope,string $opaque): array
{
    vp3_phone_voicemail_require_private_v1();
    if(!vp3_phone_voicemail_ready_v1($pdo))vp3_phone_workspace_error_v1('phone_voicemail_upgrade_required',503,'Voicemail needs the Cloud database upgrade.');
    $paths=[];$result=vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$opaque,&$paths){
        $q=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.vp3_phone_workspace_lock_v1($pdo));$q->execute([$scope['owner_user_id']]);if(!$q->fetchColumn())vp3_phone_workspace_error_v1();
        if(preg_match('/^legacy-([1-9][0-9]{0,14})$/D',$opaque,$match)){
            $q=$pdo->prepare('SELECT d.purge_state,d.provider_recording_sid,c.agent_id,j.public_id FROM phone_voicemail_deletions_v1 d JOIN phone_voice_recordings_v130 r ON r.id=d.legacy_id AND r.owner_user_id=d.owner_user_id JOIN phone_voice_calls_v130 c ON c.id=r.call_id AND c.owner_user_id=r.owner_user_id LEFT JOIN phone_recording_jobs_v1 j ON j.id=d.job_id AND j.owner_user_id=d.owner_user_id WHERE d.legacy_id=? AND d.owner_user_id=?');$q->execute([(int)$match[1],$scope['owner_user_id']]);$deleted=$q->fetch(PDO::FETCH_ASSOC);
            if($deleted&&($scope['agent_id']===0||(int)$deleted['agent_id']===$scope['agent_id']))return ['deleted'=>true,'provider_cleanup'=>$deleted['purge_state'],'redacted_chat_message_ids'=>array_slice(vp3_phone_voicemail_chat_ids_v1($pdo,$scope['owner_user_id'],$deleted['provider_recording_sid'],(string)($deleted['public_id']??''),$scope['conversation_id']),0,100)];
        }
        $message=vp3_phone_voicemail_message_v1($pdo,$scope,$opaque);$job=$message['job'];
        if(vp3_phone_voicemail_deleted_v1($pdo,$message['sid']))return ['deleted'=>true,'provider_cleanup'=>'pending','redacted_chat_message_ids'=>array_slice(vp3_phone_voicemail_chat_ids_v1($pdo,$scope['owner_user_id'],$message['sid'],(string)($job['public_id']??''),$scope['conversation_id']),0,100)];
        $legacyId=(int)($message['legacy']['id']??0);$q=$pdo->prepare('SELECT id FROM phone_voice_recordings_v130 WHERE provider_recording_sid=? AND owner_user_id=?');$q->execute([$message['sid'],$scope['owner_user_id']]);$legacyId=(int)($q->fetchColumn()?:$legacyId);$now=time();
        $pdo->prepare('INSERT INTO phone_voicemail_deletions_v1(provider_recording_sid,owner_user_id,provider_account_sid,job_id,legacy_id,deleted_epoch,updated_epoch) VALUES (?,?,?,?,?,?,?)')->execute([$message['sid'],$scope['owner_user_id'],$message['account'],(int)($job['id']??0),$legacyId,$now,$now]);
        if($legacyId>0)$pdo->prepare("UPDATE phone_voice_recordings_v130 SET recording_status='deleted' WHERE id=? AND owner_user_id=?")->execute([$legacyId,$scope['owner_user_id']]);
        if($job){
            $pdo->prepare("UPDATE phone_recording_jobs_v1 SET state='deleted',stage='deleted',lease_token='',lease_until=0,error_code='message_deleted',last_error='',updated_epoch=? WHERE id=? AND owner_user_id=?")->execute([$now,(int)$job['id'],$scope['owner_user_id']]);$paths[]=vp3_phone_recording_source_path_v1($job);
            if((int)$job['session_id']>0){
                $q=$pdo->prepare('SELECT metadata_json FROM artist_transcript_sessions_v172 WHERE id=? AND created_by_user_id=?'.vp3_phone_workspace_lock_v1($pdo));$q->execute([(int)$job['session_id'],$scope['owner_user_id']]);$meta=json_decode((string)$q->fetchColumn(),true);
                if(is_array($meta)&&(int)($meta['phone_recording_v1']['job_id']??0)===(int)$job['id']){
                    require_once __DIR__.'/artist-listening.php';if(preg_match('/^[a-f0-9]{32}$/D',(string)$job['recording_key']))$paths[]=artist_listening_v197_private_dir($scope['user'],(int)$job['session_id']).'/'.$job['recording_key'].'.mp3';
                    $meta['recordings_v197']=[];$meta['audio_retained']=false;$meta['phone_recording_v1']['deleted_epoch']=$now;
                    $pdo->prepare("UPDATE artist_transcript_sessions_v172 SET status='discarded',discarded_at=?,metadata_json=? WHERE id=? AND created_by_user_id=?")->execute([gmdate('Y-m-d H:i:s'),json_encode($meta,JSON_THROW_ON_ERROR),(int)$job['session_id'],$scope['owner_user_id']]);
                    $pdo->prepare('DELETE FROM artist_transcript_segments_v172 WHERE session_id=?')->execute([(int)$job['session_id']]);
                    $pdo->prepare('DELETE FROM artist_transcript_master_analysis_v237 WHERE session_id=?')->execute([(int)$job['session_id']]);
                }
            }
        }
        $messageIds=vp3_phone_voicemail_chat_ids_v1($pdo,$scope['owner_user_id'],$message['sid'],(string)($job['public_id']??''));
        foreach($messageIds as $mid)$pdo->prepare("UPDATE chat_messages SET message='Voice message deleted.',context_json=? WHERE id=? AND conversation_id IN (SELECT id FROM chat_conversations WHERE user_id=?)")->execute([json_encode(['phone_v1'=>['kind'=>'voicemail','voicemail_id'=>$job?'job-'.$job['id']:'legacy-'.$legacyId,'call_id'=>$message['call']['public_id'],'status'=>'deleted']],JSON_THROW_ON_ERROR),$mid,$scope['owner_user_id']]);
        return ['deleted'=>true,'provider_cleanup'=>'pending','redacted_chat_message_ids'=>array_slice(vp3_phone_voicemail_chat_ids_v1($pdo,$scope['owner_user_id'],$message['sid'],(string)($job['public_id']??''),$scope['conversation_id']),0,100)];
    });foreach($paths as $path)if(is_file($path))@unlink($path);return $result;
}
/** Retry the local part after a crash between the tombstone commit and unlink. */
function vp3_phone_voicemail_purge_local_v1(PDO $pdo,array $deleted): void
{
    if((int)$deleted['job_id']<1)return;
    $q=$pdo->prepare("SELECT * FROM phone_recording_jobs_v1 WHERE id=? AND owner_user_id=? AND provider_recording_sid=? AND state='deleted'");$q->execute([(int)$deleted['job_id'],(int)$deleted['owner_user_id'],$deleted['provider_recording_sid']]);$job=$q->fetch(PDO::FETCH_ASSOC);if(!$job)throw new RuntimeException('Deleted recording binding changed.');
    $paths=[vp3_phone_recording_source_path_v1($job)];
    if((int)$job['session_id']>0){
        $q=$pdo->prepare('SELECT metadata_json,discarded_at FROM artist_transcript_sessions_v172 WHERE id=? AND created_by_user_id=?');$q->execute([(int)$job['session_id'],(int)$job['owner_user_id']]);$session=$q->fetch(PDO::FETCH_ASSOC);$meta=$session?json_decode($session['metadata_json'],true):null;
        if($session&&$session['discarded_at']!==null&&(int)($meta['phone_recording_v1']['job_id']??0)===(int)$job['id']&&preg_match('/^[a-f0-9]{32}$/D',(string)$job['recording_key'])){require_once __DIR__.'/artist-listening.php';$paths[]=artist_listening_v197_private_dir(['id'=>(int)$job['owner_user_id']],(int)$job['session_id']).'/'.$job['recording_key'].'.mp3';}
    }
    foreach($paths as $path)if((is_file($path)||is_link($path))&&!@unlink($path))throw new RuntimeException('Private recording deletion will be retried.');
}
/** The existing recording worker performs idempotent provider cleanup; no new scheduler. */
function vp3_phone_voicemail_purge_once_v1(PDO $pdo,?callable $transport=null): ?array
{
    if(!vp3_phone_voicemail_ready_v1($pdo))return null;
    $q=$pdo->prepare("SELECT * FROM phone_voicemail_deletions_v1 WHERE purge_state='pending' AND next_attempt_epoch<=? ORDER BY deleted_epoch LIMIT 1");$q->execute([time()]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
    $now=time();$q=$pdo->prepare("UPDATE phone_voicemail_deletions_v1 SET next_attempt_epoch=?,purge_attempts=purge_attempts+1,updated_epoch=? WHERE provider_recording_sid=? AND purge_state='pending' AND next_attempt_epoch<=?");$q->execute([$now+300,$now,$row['provider_recording_sid'],$now]);if($q->rowCount()!==1)return null;
    try{
        vp3_phone_voicemail_purge_local_v1($pdo,$row);
        $account=(string)$row['provider_account_sid'];$sid=(string)$row['provider_recording_sid'];if(!preg_match('/^AC[a-f0-9]{32}$/iD',$account)||!preg_match('/^RE[a-f0-9]{32}$/iD',$sid))throw new RuntimeException('Invalid deleted recording address.');
        $endpoint='https://api.twilio.com/2010-04-01/Accounts/'.$account.'/Recordings/'.$sid.'.json';
        if($transport)$status=$transport($endpoint,$row);
        else{[$configured,$token]=vp3_phone_twilio_credentials_v100();if(!hash_equals($account,$configured)||!function_exists('curl_init'))throw new RuntimeException('Recording provider unavailable.');$ch=curl_init($endpoint);curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'DELETE',CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERPWD=>$account.':'.$token,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC]);curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);}
        if(!in_array($status,[204,404],true))throw new RuntimeException('Provider deletion is not confirmed.');
        $pdo->prepare("UPDATE phone_voicemail_deletions_v1 SET purge_state='complete',updated_epoch=? WHERE provider_recording_sid=?")->execute([time(),$sid]);return ['ok'=>true,'reason'=>'voicemail_deleted'];
    }catch(Throwable){return ['ok'=>false,'reason'=>'voicemail_provider_cleanup_pending'];}
}
function vp3_phone_voicemail_action_v1(PDO $pdo,array $scope,array $input): array
{
    $action=$input['action'];if($action==='save_voicemail_settings')return vp3_phone_voicemail_save_settings_v1($pdo,$scope,$input);
    if($action==='use_default_voicemail_greeting')return vp3_phone_voicemail_greeting_clear_v1($pdo,$scope,$input);
    if(!is_string($input['voicemail_id']??null))throw new RuntimeException('Choose a voice message.',422);
    return match($action){'transcribe_voicemail'=>vp3_phone_voicemail_transcribe_v1($pdo,$scope,$input['voicemail_id']),'delete_voicemail'=>vp3_phone_voicemail_delete_v1($pdo,$scope,$input['voicemail_id']),'post_voicemail'=>vp3_phone_voicemail_post_v1($pdo,$scope,$input['voicemail_id']),default=>throw new RuntimeException('Unknown voicemail action.',422)};
}
