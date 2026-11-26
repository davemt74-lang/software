<?php
declare(strict_types=1);
require_once __DIR__.'/agent-operator-live-schema-v1.php';
require_once __DIR__.'/phone-browser-v1.php';
require_once __DIR__.'/phone-recording-pipeline-v1.php';

const VP3_OPERATOR_LIVE_SECONDS_V1=120;
const VP3_OPERATOR_LIVE_CHUNK_SECONDS_V1=4;
const VP3_OPERATOR_LIVE_PCM_BYTES_V1=128000;
const VP3_OPERATOR_LIVE_HOUR_SECONDS_V1=240;

function vp3_operator_live_private_v1(array $scope): void
{
    if($scope['conversation_id']<1||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))
        vp3_phone_workspace_error_v1('operator_private_chat_required',403,'Open your saved private Chat.');
}
function vp3_operator_live_call_v1(PDO $pdo,array $scope,string $public,int $now,bool $lock=false,bool $active=true): array
{
    vp3_operator_live_private_v1($scope);
    if(!preg_match('/^[a-f0-9]{32}$/D',$public))vp3_phone_workspace_error_v1('operator_live_call_unavailable',404,'This phone call is unavailable.');
    $q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE public_id=? AND owner_user_id=? AND conversation_id=? AND chat_agent_id=?'.($lock?vp3_phone_workspace_lock_v1($pdo):''));
    $q->execute([$public,$scope['owner_user_id'],$scope['conversation_id'],$scope['agent_id']]);$call=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if(!$call||($scope['agent_id']>0&&(int)$call['agent_id']!==$scope['agent_id']))
        vp3_phone_workspace_error_v1('operator_live_call_unavailable',404,'This phone call is unavailable.');
    if(!$active)return $call;
    if((int)($call['owner_phone_revision']??0)>0||(string)($call['paired_profile_id']??'')!=='')vp3_phone_workspace_error_v1('operator_live_test_call',409,'Reachability test calls do not use AI captions.');
    // A browser SDK accept event or an existing carrier recording notice does
    // not prove an answered call or consent to this separate AI processing.
    if($call['state']!=='answered'||$call['ended_epoch']!==null
        ||!preg_match('/^CA[a-f0-9]{32}$/iD',(string)$call['provider_call_sid'])
        ||!preg_match('/^CA[a-f0-9]{32}$/iD',(string)$call['provider_child_sid'])
        ||(int)$call['created_epoch']>$now||(int)$call['created_epoch']<=$now-300
        ||(int)$call['updated_epoch']>$now||(int)$call['updated_epoch']<=$now-300
        ||!vp3_phone_browser_live_call_v1($pdo,$call))
        vp3_phone_workspace_error_v1('operator_live_call_changed',409,'A fresh signed answered call is required.');
    $line=vp3_phone_workspace_number_v1($pdo,$scope,(int)$call['phone_number_id'],true,$lock);
    $numberPolicy=vp3_phone_browser_policy_v1($pdo,$line);
    if(empty($numberPolicy['recording_enabled'])||!has_permission('artist_listening.access',$scope['user'])
        ||!subscription_has_entitlement($scope['user'],'main_ai.access')||!vp3_phone_recording_schema_ready_v1($pdo))
        vp3_phone_workspace_error_v1('operator_live_permission',409,'Calling, recording approval and transcription access are required.');
    $policy=vp3_phone_recording_policy_v1($pdo,$scope['user'],(int)$call['agent_id']);
    if(empty($policy['enabled'])||empty($policy['transcribe'])||(int)$policy['revision']<1)
        vp3_phone_workspace_error_v1('operator_live_permission',409,'Enable Cloud transcription in After calls first.');
    $call['live_policy_revision']=(int)$policy['revision'];
    return $call;
}
function vp3_operator_live_row_v1(PDO $pdo,int $id,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM phone_operator_live_sessions_v1 WHERE call_id=?'.($lock?vp3_phone_workspace_lock_v1($pdo):''));
    $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row?:null;
}
function vp3_operator_live_speech_key_v1(): string
{
    if(!function_exists('ai_elevenlabs_credential')||!function_exists('curl_init'))return '';
    [$key]=ai_elevenlabs_credential();return is_string($key)?trim($key):'';
}
function vp3_operator_live_view_v1(PDO $pdo,array $scope,?array $active,int $now): array
{
    $view=['available'=>false,'eligible'=>false,'call_id'=>'','state'=>'off','reason'=>''];
    if(!vp3_operator_live_schema_ready_v1($pdo)||$scope['conversation_id']<1
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))return $view;
    $view['available']=true;$view['assist_available']=true;
    $view['voice_available']=vp3_operator_voice_ready_v1($pdo)&&vp3_operator_live_speech_key_v1()!=='';
    $view['participation_available']=$view['voice_available']&&vp3_operator_participation_ready_v1($pdo);
    $view['controls_available']=vp3_operator_live_controls_ready_v1($pdo);
    if(!$active)return $view;
    try {
        $call=vp3_operator_live_call_v1($pdo,$scope,(string)$active['public_id'],$now);
        $view['call_id']=$call['public_id'];$row=vp3_operator_live_row_v1($pdo,(int)$call['id']);
        if($row){
            $assist=vp3_operator_live_assist_row_v1($pdo,(int)$call['id']);
            $participation=vp3_operator_participation_row_v1($pdo,(int)$call['id']);
            $view['participation_state']=$participation['state']??'off';
            $view['state']=$assist!==null&&$row['state']==='active'&&(int)$row['expires_epoch']>$now
                &&(int)$row['policy_revision']===$call['live_policy_revision']?'authorized':'closed';
            $view['eligible']=$view['state']==='authorized'&&vp3_operator_live_speech_key_v1()!=='';
            return $view;
        }
        $view['eligible']=vp3_operator_live_speech_key_v1()!=='';
        if(!$view['eligible'])$view['reason']='The Cloud speech service is not configured.';
        return $view;
    }catch(VP3PhoneWorkspaceException $error){$view['reason']=$error->getMessage();return $view;}
    catch(Throwable){return ['available'=>false,'eligible'=>false,'call_id'=>'','state'=>'off','reason'=>''];}
}
function vp3_operator_live_lock_owner_v1(PDO $pdo,array $scope): array
{
    $q=$pdo->prepare('SELECT id FROM users WHERE id=?'.vp3_phone_workspace_lock_v1($pdo));
    $q->execute([$scope['owner_user_id']]);$id=$q->fetchColumn();$q->closeCursor();
    if(!$id)vp3_phone_workspace_error_v1();
    return vp3_phone_workspace_scope_v1($pdo,$scope['user'],['agent_id'=>$scope['agent_id'],'conversation_id'=>$scope['conversation_id']]);
}
function vp3_operator_live_session_matches_v1(?array $row,array $call,array $scope,string $token,int $now): bool
{
    return $row!==null&&$row['state']==='active'&&(int)$row['expires_epoch']>$now
        &&(int)$row['consent_epoch']<=$now
        &&(int)$row['owner_user_id']===$scope['owner_user_id']&&(int)$row['agent_id']===(int)$call['agent_id']
        &&(int)$row['chat_agent_id']===$scope['agent_id']&&(int)$row['conversation_id']===$scope['conversation_id']
        &&(int)$row['number_id']===(int)$call['phone_number_id']&&(int)$row['policy_revision']===$call['live_policy_revision']
        &&hash_equals((string)$row['token_hash'],hash('sha256',$token));
}
function vp3_operator_live_budget_v1(PDO $pdo,int $owner,int $now): int
{
    // Include the longest session beyond the sliding hour so an in-flight
    // reservation cannot age out early. Uncertain paid attempts stay counted.
    $q=$pdo->prepare('SELECT COALESCE(SUM(reserved_seconds),0) FROM phone_operator_live_sessions_v1 WHERE owner_user_id=? AND consent_epoch>=?');
    $q->execute([$owner,$now-3600-VP3_OPERATOR_LIVE_SECONDS_V1]);$seconds=(int)$q->fetchColumn();$q->closeCursor();return $seconds;
}
function vp3_operator_live_start_v1(PDO $pdo,array $scope,string $public,int $now,bool $attested,bool $serviceReady,string $mode='remote',bool $suggestions=false,?int $seconds=null): array
{
    if(!$attested)vp3_phone_workspace_error_v1('operator_live_consent_required',422,'Confirm that everyone agreed to separate live Cloud AI transcription.');
    if(!$serviceReady)vp3_phone_workspace_error_v1('operator_live_service_unavailable',503,'The Cloud speech service is not configured.');
    if(!in_array($mode,['remote','both'],true))vp3_phone_workspace_error_v1('operator_live_request',422,'Choose a supported capture mode.');
    if($seconds!==null&&!in_array($seconds,[30,60,120],true))vp3_phone_workspace_error_v1('operator_live_request',422,'Choose a 30, 60 or 120 second caption session.');
    $controls=vp3_operator_live_controls_ready_v1($pdo);
    if($seconds!==null&&!$controls)vp3_phone_workspace_error_v1('operator_upgrade_required',503,'Run the Cloud session controls upgrade.');
    $requestedControls=$seconds!==null;
    $seconds??=$mode==='both'?60:120;
    return vp3_phone_workspace_atomic_v1($pdo,static function()use($pdo,$scope,$public,$now,$mode,$suggestions,$seconds,$controls,$requestedControls):array{
        $scope=vp3_operator_live_lock_owner_v1($pdo,$scope);
        $call=vp3_operator_live_call_v1($pdo,$scope,$public,$now,true);
        if(vp3_operator_live_row_v1($pdo,(int)$call['id'],true))
            vp3_phone_workspace_error_v1('operator_live_already_used',409,'Live captions have already been authorized for this call.');
        $q=$pdo->prepare("SELECT 1 FROM phone_operator_live_sessions_v1 WHERE owner_user_id=? AND state='active' AND expires_epoch>? LIMIT 1");
        $q->execute([$scope['owner_user_id'],$now]);$busy=$q->fetchColumn();$q->closeCursor();
        if($busy)vp3_phone_workspace_error_v1('operator_live_busy',409,'Another live caption session is authorized.');
        if(vp3_operator_live_budget_v1($pdo,$scope['owner_user_id'],$now)+($mode==='both'?8:4)>VP3_OPERATOR_LIVE_HOUR_SECONDS_V1)
            vp3_phone_workspace_error_v1('operator_live_budget',429,'Live caption processing allowance has been reached.');
        $token=bin2hex(random_bytes(32));$expires=min($now+$seconds,(int)$call['created_epoch']+300,(int)$call['updated_epoch']+300);
        if($expires<$now+8)vp3_phone_workspace_error_v1('operator_live_call_changed',409,'The recent call receipt window is ending.');
        $q=$pdo->prepare("INSERT INTO phone_operator_live_sessions_v1(call_id,owner_user_id,agent_id,chat_agent_id,conversation_id,number_id,policy_revision,token_hash,state,consent_epoch,expires_epoch) VALUES(?,?,?,?,?,?,?,?,'active',?,?)");
        $q->execute([(int)$call['id'],$scope['owner_user_id'],(int)$call['agent_id'],$scope['agent_id'],$scope['conversation_id'],(int)$call['phone_number_id'],$call['live_policy_revision'],hash('sha256',$token),$now,$expires]);
        $audioLimit=$seconds*($mode==='both'?2:1);
        if($controls)$pdo->prepare('UPDATE phone_operator_live_sessions_v1 SET audio_limit_seconds=?,controls_enabled=? WHERE call_id=?')->execute([$audioLimit,(int)$requestedControls,(int)$call['id']]);
        $q=$pdo->prepare('INSERT INTO phone_operator_live_assist_v1(call_id,capture_mode,suggestions_enabled,receipt_secret) VALUES(?,?,?,?)');
        $q->execute([(int)$call['id'],$mode,$suggestions?1:0,bin2hex(random_bytes(32))]);
        $grant=['call_id'=>$public,'token'=>$token,'expires_epoch'=>$expires,'chunk_seconds'=>4,'execution'=>'captions_only'];
        if($mode!=='remote'||$suggestions)$grant+=['capture_mode'=>$mode,'suggestions_enabled'=>$suggestions];
        if($requestedControls)$grant+=['session_seconds'=>$seconds,'audio_limit_seconds'=>$audioLimit,'max_sequences'=>intdiv($audioLimit,$mode==='both'?8:4),
            'audio_seconds_remaining'=>min($audioLimit,max(0,VP3_OPERATOR_LIVE_HOUR_SECONDS_V1-vp3_operator_live_budget_v1($pdo,$scope['owner_user_id'],$now)))];
        return $grant;
    });
}
function vp3_operator_live_wav_v1(string $pcm): string
{
    if(strlen($pcm)!==VP3_OPERATOR_LIVE_PCM_BYTES_V1)throw new InvalidArgumentException('Invalid live caption audio size.');
    $size=strlen($pcm);
    return 'RIFF'.pack('V',36+$size).'WAVEfmt '.pack('VvvVVvv',16,1,1,16000,32000,2,16).'data'.pack('V',$size).$pcm;
}
function vp3_operator_live_speech_v1(string $pcm,string $key): string
{
    if($key==='')throw new RuntimeException('Cloud speech is unavailable.');
    $file=tempnam(sys_get_temp_dir(),'vp3-op-caption-');
    if($file===false)throw new RuntimeException('Caption input could not be prepared.');
    try{
        chmod($file,0600);$wav=vp3_operator_live_wav_v1($pcm);
        if(file_put_contents($file,$wav,LOCK_EX)!==strlen($wav))throw new RuntimeException('Caption input could not be prepared.');
        $curl=curl_init('https://api.elevenlabs.io/v1/speech-to-text');
        if($curl===false)throw new RuntimeException('Cloud speech is unavailable.');
        $raw='';
        try{
            curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_HTTPHEADER=>['xi-api-key: '.$key,'Accept: application/json'],
                CURLOPT_POSTFIELDS=>['model_id'=>'scribe_v2','diarize'=>'false','tag_audio_events'=>'false','file'=>new CURLFile($file,'audio/wav','caption.wav')],
                CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$raw):int{
                    if(strlen($raw)+strlen($chunk)>262144)return 0;$raw.=$chunk;return strlen($chunk);
                }]);
            $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        }finally{curl_close($curl);}
        if($ok===false||$status<200||$status>=300)throw new RuntimeException('Cloud speech result could not be verified.');
        $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($data)||!is_string($data['text']??null))throw new RuntimeException('Cloud speech returned an invalid result.');
        return $data['text'];
    }finally{@unlink($file);}
}
/** Reserve the complete frame budget before dispatch. Each authorized side is separately transcribed. */
function vp3_operator_live_chunk_v1(PDO $pdo,array $scope,string $public,string $token,int $seq,string $pcm,int $now,callable $speech,?callable $clock=null,?string $localPcm=null): array
{
    if(strlen($pcm)!==VP3_OPERATOR_LIVE_PCM_BYTES_V1||($localPcm!==null&&strlen($localPcm)!==VP3_OPERATOR_LIVE_PCM_BYTES_V1))vp3_phone_workspace_error_v1('operator_live_request',422,'Invalid live caption audio chunk.');
    $cost=$localPcm!==null?8:4;$mode=$localPcm!==null?'both':'remote';
    $id=vp3_phone_workspace_atomic_v1($pdo,static function()use($pdo,$scope,$public,$token,$seq,$now,$cost,$mode):int{
        $scope=vp3_operator_live_lock_owner_v1($pdo,$scope);
        $call=vp3_operator_live_call_v1($pdo,$scope,$public,$now,true);
        $id=(int)$call['id'];$row=vp3_operator_live_row_v1($pdo,$id,true);$assist=vp3_operator_live_assist_row_v1($pdo,$id,true);
        if(vp3_operator_voice_busy_v1($pdo,$id,$now)||!$assist||$assist['capture_mode']!==$mode||(int)$assist['suggestion_pending']!==0
            ||!vp3_operator_live_session_matches_v1($row,$call,$scope,$token,$now)||(int)$row['next_seq']!==$seq
            ||(int)$row['pending_seq']!==0||(int)$row['last_dispatch_epoch']>$now-3
            ||(int)$row['consent_epoch']>$now-3||(int)$row['reserved_seconds']+$cost>(int)($row['audio_limit_seconds']??VP3_OPERATOR_LIVE_SECONDS_V1)
            ||vp3_operator_live_budget_v1($pdo,$scope['owner_user_id'],$now)+$cost>VP3_OPERATOR_LIVE_HOUR_SECONDS_V1)
            vp3_phone_workspace_error_v1('operator_live_changed',409,'Live caption permission, sequence or allowance changed. Stop captions.');
        $q=$pdo->prepare('UPDATE phone_operator_live_sessions_v1 SET next_seq=next_seq+1,pending_seq=?,reserved_seconds=reserved_seconds+?,last_dispatch_epoch=? WHERE call_id=? AND state=\'active\' AND next_seq=? AND pending_seq=0');
        $q->execute([$seq,$cost,$now,$id,$seq]);
        if($q->rowCount()!==1)vp3_phone_workspace_error_v1('operator_live_changed',409,'Caption reservation changed.');
        return $id;
    });
    try{
        // No speech content is written to the session table or Chat ledger.
        $caption=vp3_operator_live_caption_text_v1($speech($pcm));$localCaption=null;
        if($localPcm!==null){
            // Revocation while the first provider request was in flight prevents the second.
            vp3_phone_workspace_atomic_v1($pdo,static function()use($pdo,$scope,$public,$token,$seq,$id,$clock):void{
                $at=$clock?$clock():time();$fresh=vp3_operator_live_lock_owner_v1($pdo,$scope);
                $call=vp3_operator_live_call_v1($pdo,$fresh,$public,$at,true);
                $row=vp3_operator_live_row_v1($pdo,$id,true);$assist=vp3_operator_live_assist_row_v1($pdo,$id,true);
                if(!$assist||$assist['capture_mode']!=='both'||!vp3_operator_live_session_matches_v1($row,$call,$fresh,$token,$at)
                    ||(int)$row['pending_seq']!==$seq)
                    vp3_phone_workspace_error_v1('operator_live_changed',409,'Live caption permission changed.');
            });
            $localCaption=vp3_operator_live_caption_text_v1($speech($localPcm));
        }
        $finished=$clock?$clock():time();
        return vp3_phone_workspace_atomic_v1($pdo,static function()use($pdo,$scope,$public,$token,$seq,$id,$caption,$localCaption,$finished,$mode):array{
            $scope=vp3_operator_live_lock_owner_v1($pdo,$scope);
            $call=vp3_operator_live_call_v1($pdo,$scope,$public,$finished,true);
            $row=vp3_operator_live_row_v1($pdo,$id,true);
            $assist=vp3_operator_live_assist_row_v1($pdo,$id,true);
            if(!$assist||$assist['capture_mode']!==$mode||!vp3_operator_live_session_matches_v1($row,$call,$scope,$token,$finished)||(int)$row['pending_seq']!==$seq)
                vp3_phone_workspace_error_v1('operator_live_changed',409,'Live caption permission changed.');
            $q=$pdo->prepare('UPDATE phone_operator_live_sessions_v1 SET pending_seq=0 WHERE call_id=? AND pending_seq=? AND state=\'active\'');
            $q->execute([$id,$seq]);
            if($q->rowCount()!==1)vp3_phone_workspace_error_v1('operator_live_changed',409,'Live caption permission changed.');
            $result=['call_id'=>$public,'seq'=>$seq,'text'=>$caption,'speaker'=>'remote_audio'];
            if(!empty($row['controls_enabled']))$result['audio_seconds_remaining']=max(0,min((int)$row['audio_limit_seconds']-(int)$row['reserved_seconds'],
                VP3_OPERATOR_LIVE_HOUR_SECONDS_V1-vp3_operator_live_budget_v1($pdo,$scope['owner_user_id'],$finished)));
            if($localCaption!==null)$result['local_text']=$localCaption;
            if(!empty($assist['suggestions_enabled'])){
                $result['receipts']=[];
                if($caption!=='')$result['receipts'][]=vp3_operator_live_caption_receipt_v1($assist,$public,$seq,'remote_audio',$caption);
                if($localCaption!==null&&$localCaption!=='')$result['receipts'][]=vp3_operator_live_caption_receipt_v1($assist,$public,$seq,'local_audio',$localCaption);
            }
            return $result;
        });
    }catch(Throwable $error){
        // Never replay a possibly charged request, even if the response was lost.
        $pdo->prepare("UPDATE phone_operator_live_sessions_v1 SET state='closed',pending_seq=0 WHERE call_id=? AND owner_user_id=? AND pending_seq=?")
            ->execute([$id,$scope['owner_user_id'],$seq]);
        vp3_phone_workspace_error_v1('operator_live_interrupted',409,'Live caption processing stopped. Its last request may have been charged.');
    }
}
function vp3_operator_live_stop_v1(PDO $pdo,array $scope,string $public,string $token,int $now): array
{
    return vp3_phone_workspace_atomic_v1($pdo,static function()use($pdo,$scope,$public,$token,$now):array{
        $scope=vp3_operator_live_lock_owner_v1($pdo,$scope);
        $call=vp3_operator_live_call_v1($pdo,$scope,$public,$now,true,false);
        $row=vp3_operator_live_row_v1($pdo,(int)$call['id'],true);
        if(!$row||(int)$row['owner_user_id']!==$scope['owner_user_id']||(int)$row['conversation_id']!==$scope['conversation_id']
            ||(int)$row['chat_agent_id']!==$scope['agent_id']||!hash_equals((string)$row['token_hash'],hash('sha256',$token)))
            vp3_phone_workspace_error_v1('operator_live_call_unavailable',404,'Live caption session is unavailable.');
        vp3_operator_voice_close_call_v1($pdo,(int)$call['id']);
        vp3_operator_participation_close_v1($pdo,(int)$call['id']);
        $pdo->prepare("UPDATE phone_operator_live_sessions_v1 SET state='closed',pending_seq=0 WHERE call_id=? AND owner_user_id=?")
            ->execute([(int)$call['id'],$scope['owner_user_id']]);
        return ['call_id'=>$public,'stopped'=>true];
    });
}
function vp3_operator_live_request_v1(PDO $pdo,?array $user,string $method,array $input,string $csrf,?callable $speech=null,?int $clock=null,?callable $suggestionModel=null,?callable $voiceSpeech=null): array
{
    if(!$user)vp3_phone_workspace_error_v1('authentication_required',401,'Sign in to use Phone.');
    if($method!=='POST')vp3_phone_workspace_error_v1('method_not_allowed',405,'Live captions require POST.');
    if($csrf===''||!is_string($input['csrf_token']??null)||!hash_equals($csrf,$input['csrf_token']))
        vp3_phone_workspace_error_v1('csrf_failed',403,'Refresh Chat before using live captions.');
    $action=$input['action']??null;$public=$input['call_id']??null;
    if(!is_string($public)||!in_array($action,['start','chunk','stop','suggest','voice_prepare','voice_claim','voice_finish','participation_start','participation_decide','participation_stop','participation_pause','participation_resume'],true))
        vp3_phone_workspace_error_v1('operator_live_request',422,'Choose a supported live caption action.');
    $fields=['action','csrf_token','agent_id','conversation_id','call_id'];
    $extra=match($action){'start'=>['consent_attested','capture_mode','suggestions_attested','session_seconds'],'chunk'=>['token','seq','pcm','local_pcm'],'stop'=>['token'],'suggest'=>['token','receipts'],'voice_prepare'=>['token','source_seq','suggestion','proof','voice_consent_attested'],
        'voice_claim','voice_finish'=>['token','source_seq','turn_token','participation_token'],
        'participation_start'=>['token','consent_attested','mode','max_decisions','max_responses'],
        'participation_decide'=>['token','participation_token','receipts','previous'],
        'participation_stop'=>['token','participation_token'],
        'participation_pause','participation_resume'=>['token','participation_token','expected_revision']};
    if($action==='voice_prepare')$extra[]='participation_token';
    if(array_diff(array_keys($input),[...$fields,...$extra]))vp3_phone_workspace_error_v1('operator_live_request',422,'Unsupported live caption input.');
    $scope=vp3_phone_workspace_scope_v1($pdo,$user,$input);vp3_operator_live_private_v1($scope);
    if(!vp3_operator_live_schema_ready_v1($pdo))vp3_phone_workspace_error_v1('operator_upgrade_required',503,'Run the Cloud database upgrade for live captions.');
    $now=$clock??time();
    if($action==='start'){
        $mode=$input['capture_mode']??'remote';$assist=$input['suggestions_attested']??false;
        if(!is_string($mode)||!is_bool($assist))vp3_phone_workspace_error_v1('operator_live_request',422,'Choose explicit capture and assistance preferences.');
        $seconds=$input['session_seconds']??null;
        if(array_key_exists('session_seconds',$input)&&!is_int($seconds))vp3_phone_workspace_error_v1('operator_live_request',422,'Choose a whole session duration.');
        return vp3_operator_live_start_v1($pdo,$scope,$public,$now,($input['consent_attested']??null)===true,
            $speech!==null||vp3_operator_live_speech_key_v1()!=='',$mode,$assist,$seconds);
    }
    $token=$input['token']??null;
    if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))vp3_phone_workspace_error_v1('operator_live_request',422,'Invalid caption permission.');
    if($action==='stop')return vp3_operator_live_stop_v1($pdo,$scope,$public,$token,$now);
    if($action==='participation_start'){
        if(!is_bool($input['consent_attested']??null)||!is_string($input['mode']??null))vp3_phone_workspace_error_v1('operator_live_request',422,'Choose explicit conversation permission.');
        $decisions=$input['max_decisions']??4;$responses=$input['max_responses']??2;
        if(!is_int($decisions)||!is_int($responses))vp3_phone_workspace_error_v1('operator_live_request',422,'Choose whole conversation allowances.');
        return vp3_operator_participation_start_v1($pdo,$scope,$public,$token,$input['consent_attested'],$input['mode'],$now,$decisions,$responses);
    }
    $participationToken=$input['participation_token']??null;
    if(array_key_exists('participation_token',$input)&&(!is_string($participationToken)||!preg_match('/^[a-f0-9]{64}$/D',$participationToken)))
        vp3_phone_workspace_error_v1('operator_live_request',422,'Invalid conversation permission.');
    if(in_array($action,['participation_decide','participation_stop','participation_pause','participation_resume'],true)){
        if($participationToken===null)vp3_phone_workspace_error_v1('operator_live_request',422,'Conversation permission is required.');
        if($action==='participation_stop')return vp3_operator_participation_stop_v1($pdo,$scope,$public,$token,$participationToken,$now);
        if(in_array($action,['participation_pause','participation_resume'],true)){
            if(!is_int($input['expected_revision']??null)||$input['expected_revision']<0)vp3_phone_workspace_error_v1('operator_live_request',422,'Use the current conversation controls.');
            return vp3_operator_participation_control_v1($pdo,$scope,$public,$token,$participationToken,$action==='participation_pause',$input['expected_revision'],$now);
        }
        return vp3_operator_participation_decide_v1($pdo,$scope,$public,$token,$participationToken,$input['receipts']??null,$input['previous']??[],$now,$suggestionModel,$clock===null?null:static fn():int=>$clock);
    }
    if($action==='suggest')return vp3_operator_live_suggest_v1($pdo,$scope,$public,$token,$input['receipts']??null,$now,$suggestionModel,$clock===null?null:static fn():int=>$clock);
    if(in_array($action,['voice_prepare','voice_claim','voice_finish'],true)){
        $seq=$input['source_seq']??null;
        if(!is_int($seq)||$seq<1||$seq>30)vp3_phone_workspace_error_v1('operator_voice_request',422,'Choose a current reviewed suggestion.');
        if($action==='voice_prepare'){
            if(!is_string($input['suggestion']??null)||!is_string($input['proof']??null)||!is_bool($input['voice_consent_attested']??null))
                vp3_phone_workspace_error_v1('operator_voice_request',422,'Review the exact script and consent to the AI voice turn.');
            return vp3_operator_voice_prepare_v1($pdo,$scope,$public,$token,$seq,$input['suggestion'],$input['proof'],
                $input['voice_consent_attested'],$now,$voiceSpeech,$clock===null?null:static fn():int=>$clock,$participationToken);
        }
        $turn=$input['turn_token']??null;
        if(!is_string($turn)||!preg_match('/^[a-f0-9]{64}$/D',$turn))vp3_phone_workspace_error_v1('operator_voice_request',422,'Invalid voice turn permission.');
        return $action==='voice_claim'?vp3_operator_voice_claim_v1($pdo,$scope,$public,$token,$seq,$turn,$now,$participationToken)
            :vp3_operator_voice_finish_v1($pdo,$scope,$public,$token,$seq,$turn,$now);
    }
    $seq=$input['seq']??null;$encoded=$input['pcm']??null;
    if(!is_int($seq)||$seq<1||$seq>30||!is_string($encoded)||strlen($encoded)!==170668)
        vp3_phone_workspace_error_v1('operator_live_request',422,'Invalid live caption audio chunk.');
    $pcm=base64_decode($encoded,true);
    if($pcm===false||strlen($pcm)!==VP3_OPERATOR_LIVE_PCM_BYTES_V1||base64_encode($pcm)!==$encoded)
        vp3_phone_workspace_error_v1('operator_live_request',422,'Invalid live caption audio chunk.');
    $localPcm=null;
    if(array_key_exists('local_pcm',$input)){
        $encodedLocal=$input['local_pcm'];
        if(!is_string($encodedLocal)||strlen($encodedLocal)!==170668||($localPcm=base64_decode($encodedLocal,true))===false
            ||strlen($localPcm)!==VP3_OPERATOR_LIVE_PCM_BYTES_V1||base64_encode($localPcm)!==$encodedLocal)
            vp3_phone_workspace_error_v1('operator_live_request',422,'Invalid local caption audio chunk.');
    }
    if($speech===null){
        $key=vp3_operator_live_speech_key_v1();
        if($key==='')vp3_phone_workspace_error_v1('operator_live_service_unavailable',503,'Cloud speech is unavailable.');
        $speech=static fn(string $audio):string=>vp3_operator_live_speech_v1($audio,$key);
    }
    return vp3_operator_live_chunk_v1($pdo,$scope,$public,$token,$seq,$pcm,$now,$speech,$clock===null?null:static fn():int=>$clock,$localPcm);
}
