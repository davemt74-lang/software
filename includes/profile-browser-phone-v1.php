<?php
declare(strict_types=1);
require_once __DIR__.'/profile-browser-phone-schema-v1.php';
require_once __DIR__.'/profile-phone-v1.php';
require_once __DIR__.'/phone-browser-v1.php';
require_once __DIR__.'/profile-agent-public-service-v110.php';
require_once __DIR__.'/agent-observation-authority-v1.php';

final class VP3ProfileBrowserPhoneException extends RuntimeException
{
    public function __construct(public readonly string $publicCode,string $message,public readonly int $httpStatus=409){parent::__construct($message,$httpStatus);}
}
function vp3_profile_browser_phone_error_v1(string $code='PROFILE_PHONE_CHANGED',int $status=409,string $message='This Profile call changed. Close it and check the current phone line.'): never
{throw new VP3ProfileBrowserPhoneException($code,$message,$status);}
function vp3_profile_browser_phone_now_v1(): int{return (int)floor(microtime(true)*1000);}
function vp3_profile_browser_phone_terminal_v1(string $state): bool{return in_array($state,['completed','busy','no_answer','failed','canceled','expired'],true);}
function vp3_profile_browser_phone_require_v1(PDO $pdo): void
{if(!vp3_profile_browser_phone_ready_v1($pdo)||!vp3_phone_workspace_schema_ready_v1($pdo))vp3_profile_browser_phone_error_v1('PROFILE_PHONE_UPGRADE_REQUIRED',503,'Run Database Upgrade to enable browser calls from Profile chat.');}
function vp3_profile_browser_phone_config_v1(): string
{
    $c=vp3_phone_browser_config_state_v1();if(!$c['ready'])vp3_profile_browser_phone_error_v1('PROFILE_PHONE_NOT_READY',409,'Browser calling is unavailable for this line.');
    return hash('sha256',json_encode([$c['api_key_sid'],$c['twiml_app_sid'],vp3_phone_twilio_state_v100()['account_sid'],$c['callback_urls'],(string)setting('phone_browser_api_key_secret_v1','')],JSON_THROW_ON_ERROR));
}
function vp3_profile_browser_phone_line_v1(PDO $pdo,array $profile): array
{
    $public=vp3_profile_phone_public_v1($pdo,$profile);if(!$public['enabled'])vp3_profile_browser_phone_error_v1();
    $owner=(int)$profile['user_id'];$saved=vp3_profile_phone_row_v1($pdo,$owner);
    $q=$pdo->prepare('SELECT * FROM phone_cloud_numbers_v100 WHERE id=? AND owner_user_id=?');$q->execute([(int)$saved['phone_number_id'],$owner]);$n=$q->fetch(PDO::FETCH_ASSOC);
    if(!$n||!vp3_phone_browser_policy_v1($pdo,$n)['browser_enabled'])vp3_profile_browser_phone_error_v1('PROFILE_PHONE_NOT_READY',409,'Browser calling is not enabled for this phone line.');
    vp3_profile_browser_phone_config_v1();return ['settings'=>$saved,'number'=>$n];
}
function vp3_profile_browser_phone_availability_v1(PDO $pdo,array $profile): array
{
    $out=['contract'=>VP3_PROFILE_BROWSER_PHONE_V1,'enabled'=>false,'reason'=>'Browser calling is unavailable. You can use your phone app.'];
    if(!vp3_profile_browser_phone_ready_v1($pdo)||!vp3_phone_workspace_schema_ready_v1($pdo))return $out;
    try{vp3_profile_browser_phone_line_v1($pdo,$profile);return ['contract'=>VP3_PROFILE_BROWSER_PHONE_V1,'enabled'=>true,'reason'=>'Calls use this line’s existing Phone routing.'];}catch(VP3ProfileBrowserPhoneException){return $out;}
}
function vp3_profile_browser_phone_row_v1(PDO $pdo,string $id,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM profile_browser_phone_calls_v1 WHERE public_id=?'.($lock?vp3_phone_workspace_lock_v1($pdo):''));$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function vp3_profile_browser_phone_owner_lock_v1(PDO $pdo,int $owner,bool $requireActive=true): void
{
    if(!$pdo->inTransaction())throw new LogicException('Profile calls require the owner transaction.');
    // Native offers/acceptance use the same slot first. This serializes both
    // transports without requiring a media provider on Phone-only installs.
    require_once __DIR__.'/profile-calls-pa3c.php';
    if(vp3_profile_call_schema_ready_pa3c($pdo))vp3_profile_call_slot_pa3c($pdo,$owner,true);
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->prepare('UPDATE users SET id=id WHERE id=?')->execute([$owner]);
    $q=$pdo->prepare('SELECT id FROM users WHERE id=?'.($requireActive?' AND is_active=1':'').vp3_phone_workspace_lock_v1($pdo));$q->execute([$owner]);if(!$q->fetchColumn())vp3_profile_browser_phone_error_v1();
}
/** Carrier continuation uses stored native authority, never PHP login or caller ID. */
function vp3_profile_browser_phone_authority_v1(PDO $pdo,array $call): array
{
    $owner=(int)$call['owner_user_id'];$profile=profile_for_user($pdo,$owner,false);$user=profile_user_row($pdo,$owner);
    if(!$profile||!$user||empty($user['is_active'])||!has_permission('account.access',$user)||(int)$profile['profile_agent_id']!==(int)$call['profile_agent_id'])vp3_profile_browser_phone_error_v1();
    $line=vp3_profile_browser_phone_line_v1($pdo,$profile);$saved=$line['settings'];$number=$line['number'];
    foreach(['phone_number_id'=>'phone_number_id','phone_revision'=>'revision','phone_agent_id'=>'agent_id'] as $stored=>$key)if((int)$call[$stored]!==(int)$saved[$key])vp3_profile_browser_phone_error_v1();
    if(!hash_equals($call['phone_e164'],(string)$number['phone_e164'])||!hash_equals($call['config_fingerprint'],vp3_profile_browser_phone_config_v1()))vp3_profile_browser_phone_error_v1();
    $q=$pdo->prepare('SELECT session_key FROM profile_visit_sessions WHERE id=? AND owner_user_id=?');$q->execute([(int)$call['profile_session_id'],$owner]);$key=$q->fetchColumn();
    if(!is_string($key)||!hash_equals($call['session_fingerprint'],hash('sha256',$key)))vp3_profile_browser_phone_error_v1();
    $visitor=(int)$call['visitor_user_id'];if($visitor===$owner||$visitor<0)vp3_profile_browser_phone_error_v1();
    if($visitor>0){$q=$pdo->prepare('SELECT is_active,password_hash FROM users WHERE id=?');$q->execute([$visitor]);$u=$q->fetch(PDO::FETCH_ASSOC);if(!$u||!$u['is_active']||!hash_equals($call['visitor_auth_revision'],hash('sha256',$visitor.'|'.$u['password_hash'])))vp3_profile_browser_phone_error_v1();}
    $authority=['contract'=>'vp3.profile-conversation-authority.v1','transport'=>'native','owner_user_id'=>$owner,'visitor_user_id'=>$visitor,'credential_hash'=>$call['credential_hash']];
    $conversation=vp3_profile_agent_public_conversation_v390($pdo,(int)$call['conversation_id'],$owner,(int)$call['profile_agent_id'],(int)$call['profile_session_id'],false,$authority);
    if(!$conversation||$conversation['status']==='resolved')vp3_profile_browser_phone_error_v1();
    require_once __DIR__.'/profile-calls-pa3c.php';vp3_profile_call_block_check_pa3c($pdo,$owner,$visitor,false);
    return $line+['profile'=>$profile,'conversation'=>$conversation];
}
function vp3_profile_browser_phone_current_sid_v1(PDO $pdo,string $sid): bool
{
    $call=vp3_profile_browser_phone_find_sid_v1($pdo,$sid);if(!$call)return true;
    if(vp3_profile_browser_phone_terminal_v1($call['state'])||$call['end_requested_ms']!==null||vp3_profile_browser_phone_now_v1()>(int)$call['created_ms']+600000)return false;
    try{vp3_profile_browser_phone_authority_v1($pdo,$call);return true;}catch(VP3ProfileBrowserPhoneException|VP3ProfileCallException){return false;}
}
function vp3_profile_browser_phone_intent_v1(array $call): string
{
    return hash_hmac('sha256','profile-browser|'.$call['public_id'].'|'.$call['owner_user_id'].'|'.$call['credential_hash'],ai_decrypt_secret((string)setting('phone_browser_api_key_secret_v1','')));
}
/** An outgoing-only, per-intent identity cannot receive calls or impersonate an owner. */
function vp3_profile_browser_phone_token_v1(array $call): array
{
    $c=vp3_phone_browser_config_state_v1();$now=time();$expiry=min($now+300,intdiv((int)$call['expires_ms'],1000));
    if(!$c['ready']||$expiry<=$now)vp3_profile_browser_phone_error_v1('PROFILE_PHONE_EXPIRED',409,'This call request expired. Start a new call.');
    $header=['typ'=>'JWT','alg'=>'HS256','cty'=>'twilio-fpa;v=1'];$claims=['jti'=>$c['api_key_sid'].'-'.bin2hex(random_bytes(12)),'iss'=>$c['api_key_sid'],'sub'=>vp3_phone_twilio_state_v100()['account_sid'],'iat'=>$now,'nbf'=>$now,'exp'=>$expiry,
        'grants'=>['identity'=>$call['identity'],'voice'=>['incoming'=>['allow'=>false],'outgoing'=>['application_sid'=>$c['twiml_app_sid']]]]];
    $body=vp3_phone_browser_base64_v1(json_encode($header,JSON_THROW_ON_ERROR)).'.'.vp3_phone_browser_base64_v1(json_encode($claims,JSON_THROW_ON_ERROR));
    return ['token'=>$body.'.'.vp3_phone_browser_base64_v1(hash_hmac('sha256',$body,ai_decrypt_secret((string)setting('phone_browser_api_key_secret_v1','')),true)),'expires_at'=>$expiry,'connect_params'=>['profile_call_intent'=>vp3_profile_browser_phone_intent_v1($call)]];
}
function vp3_profile_browser_phone_result_v1(?array $call,int $cid=0): array
{
    $now=vp3_profile_browser_phone_now_v1();return ['contract'=>VP3_PROFILE_BROWSER_PHONE_V1,'conversation_id'=>$call?(int)$call['conversation_id']:$cid,'server_now_ms'=>$now,'valid_until_ms'=>$now+30000,
        'call'=>$call?['id'=>$call['public_id'],'state'=>$call['state'],'route_kind'=>$call['route_kind'],'terminal'=>vp3_profile_browser_phone_terminal_v1($call['state']),'answered'=>(int)($call['owner_answered_ms']??0)>0,'duration_seconds'=>(int)$call['duration_seconds'],'end_requested'=>$call['end_requested_ms']!==null]:null];
}
/** Preserve the responder choice while holding and fencing text/image workers. */
function vp3_profile_browser_phone_hold_v1(PDO $pdo,int $owner,int $cid): void
{
    vp3_profile_agent_response_token_v1($pdo,$cid,true);
    if(!vp3_profile_handoff_schema_ready_pa3($pdo))return;
    $q=$pdo->prepare("SELECT id,run_id,action_id FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND conversation_id=? AND state IN ('agent_ready','agent_running')".vp3_phone_workspace_lock_v1($pdo));$q->execute([$owner,$cid]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $t){
        $pdo->prepare("UPDATE profile_agent_handoff_turns_pa3 SET state='agent_ready',version=version+1,execution_lease_token='',updated_at=? WHERE id=?")->execute([gmdate('Y-m-d H:i:s'),$t['id']]);
        $pdo->prepare("UPDATE agent_workflow_runs SET status='paused',lease_owner='',lease_token='',lease_expires_at=NULL,heartbeat_at=NULL,current_action_id=NULL,next_attempt_at=NULL,progress_message='Profile phone call in progress' WHERE id=? AND owner_user_id=? AND status NOT IN ('completed','failed','cancelled')")->execute([$t['run_id'],$owner]);
        $pdo->prepare("UPDATE agent_workflow_actions SET status='queued',available_at=NULL,heartbeat_at=NULL WHERE id=? AND run_id=? AND owner_user_id=? AND status NOT IN ('completed','failed','cancelled')")->execute([$t['action_id'],$t['run_id'],$owner]);
    }
}
function vp3_profile_browser_phone_receipt_v1(PDO $pdo,array $call): void
{
    if((int)($call['receipt_message_id']??0)<1)return;
    $text=match($call['state']){'prepared'=>'Browser phone call requested.','ringing'=>'Phone call is ringing.','answered'=>'The owner answered the phone call.','completed'=>'Browser phone call ended.','busy'=>'The phone line was busy.','no_answer'=>'The phone call was not answered.','failed'=>'The browser phone call could not connect.','canceled'=>'Browser phone call canceled.','expired'=>'The browser call request expired.',default=>match($call['route_kind']){'agent'=>'Phone call routed to the configured Phone Agent.','voicemail'=>'Phone call routed to voicemail.',default=>'Connecting the browser phone call…'}};
    $pdo->prepare("UPDATE profile_agent_messages SET message=? WHERE id=? AND conversation_id=? AND sender_type='receipt'")->execute([$text,(int)$call['receipt_message_id'],(int)$call['conversation_id']]);
}
function vp3_profile_browser_phone_settle_v1(PDO $pdo,array $call,string $state,int $duration=0): void
{
    if(!$pdo->inTransaction())throw new LogicException('Call settlement requires a transaction.');
    if(vp3_profile_browser_phone_terminal_v1($call['state']))return;
    if(!vp3_profile_browser_phone_terminal_v1($state))throw new InvalidArgumentException('A carrier completion must be terminal.');
    $now=vp3_profile_browser_phone_now_v1();$pdo->prepare('UPDATE profile_browser_phone_calls_v1 SET state=?,duration_seconds=?,ended_ms=?,updated_ms=? WHERE public_id=?')->execute([$state,max((int)$call['duration_seconds'],$duration),$now,$now,$call['public_id']]);
    // Parent termination also releases an owner browser leg whose final child
    // callback was lost. Its own duration and voicemail evidence stay intact.
    if(!empty($call['provider_call_sid'])){
        $q=$pdo->prepare("SELECT id FROM phone_browser_calls_v1 WHERE owner_user_id=? AND provider_call_sid=? AND direction='inbound' AND state IN ('prepared','dialing','ringing','answered')".vp3_phone_workspace_lock_v1($pdo));$q->execute([(int)$call['owner_user_id'],$call['provider_call_sid']]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $phoneId){
            $pdo->prepare('UPDATE phone_browser_calls_v1 SET state=?,ended_epoch=?,updated_epoch=? WHERE id=?')->execute([$state==='expired'?'failed':$state,intdiv($now,1000),intdiv($now,1000),$phoneId]);
            vp3_operator_voice_close_call_v1($pdo,(int)$phoneId);
        }
    }
    $call['state']=$state;vp3_profile_browser_phone_receipt_v1($pdo,$call);
    if(vp3_profile_handoff_schema_ready_pa3($pdo))vp3_profile_handoff_schedule_pa3($pdo,(int)$call['owner_user_id'],(int)$call['conversation_id']);
}

/** A REST receipt can repair a lost webhook; transport failure never proves completion. */
function vp3_profile_browser_phone_provider_receipt_v1(PDO $pdo,array $call,array $receipt): bool
{
    $map=['completed'=>'completed','busy'=>'busy','no-answer'=>'no_answer','failed'=>'failed','canceled'=>'canceled'];
    $duration=$receipt['duration']??'0';$status=$receipt['status']??null;
    if(($receipt['sid']??null)!==$call['provider_call_sid']||($receipt['account_sid']??null)!==$call['provider_account_sid']||!is_string($status)||!isset($map[$status])||(!is_string($duration)&&!is_int($duration))||!preg_match('/\A[0-9]{1,6}\z/D',(string)$duration))return false;
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$call,$map,$status,$duration){
        vp3_profile_browser_phone_owner_lock_v1($pdo,(int)$call['owner_user_id'],false);$fresh=vp3_profile_browser_phone_row_v1($pdo,$call['public_id'],true);
        if(!$fresh||$fresh['provider_call_sid']!==$call['provider_call_sid']||$fresh['provider_account_sid']!==$call['provider_account_sid'])return false;
        vp3_profile_browser_phone_settle_v1($pdo,$fresh,$map[$status],min(86400,(int)$duration));return true;
    });
}

/** Bound provider work, outside SQL transactions, to the one stored parent. */
function vp3_profile_browser_phone_reconcile_v1(PDO $pdo,array $call,?callable $carrier=null): void
{
    if($pdo->inTransaction()||empty($call['provider_call_sid'])||vp3_profile_browser_phone_terminal_v1($call['state']))return;
    $account=(string)vp3_phone_twilio_state_v100()['account_sid'];
    if(!hash_equals((string)$call['provider_account_sid'],$account))return;
    $claimed=vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$call){
        vp3_profile_browser_phone_owner_lock_v1($pdo,(int)$call['owner_user_id'],false);$fresh=vp3_profile_browser_phone_row_v1($pdo,$call['public_id'],true);$now=vp3_profile_browser_phone_now_v1();
        if(!$fresh||vp3_profile_browser_phone_terminal_v1($fresh['state'])||$now-max((int)$fresh['created_ms'],(int)$fresh['last_reconciled_ms'])<30000)return null;
        $ending=$fresh['end_requested_ms']!==null||$now>=(int)$fresh['created_ms']+600000;
        $pdo->prepare('UPDATE profile_browser_phone_calls_v1 SET last_reconciled_ms=?,end_requested_ms=COALESCE(end_requested_ms,?) WHERE public_id=?')->execute([$now,$ending?$now:null,$fresh['public_id']]);
        return ['call'=>$fresh,'ending'=>$ending];
    });
    if(!$claimed)return;
    $carrier=$carrier??'vp3_phone_twilio_http_v100';
    try{
        $receipt=$carrier($claimed['ending']?'POST':'GET','/2010-04-01/Accounts/'.$account.'/Calls/'.$claimed['call']['provider_call_sid'].'.json',$claimed['ending']?['Status'=>'completed']:[]);
        if(is_array($receipt))vp3_profile_browser_phone_provider_receipt_v1($pdo,$claimed['call'],$receipt);
    }catch(Throwable){/* Leave the reservation held until the provider confirms its outcome. */}
}
function vp3_profile_browser_phone_request_id_v1(mixed $value): string
{if(!is_string($value)||!preg_match('/\A[a-f0-9]{32}\z/D',$value))vp3_profile_browser_phone_error_v1('PROFILE_PHONE_INVALID',422,'Choose the current call request.');return $value;}
function vp3_profile_browser_phone_lookup_v1(PDO $pdo,array $ctx,array $input,bool $lock=false): ?array
{
    $request=vp3_profile_browser_phone_request_id_v1($input['client_request_id']??null);
    $q=$pdo->prepare('SELECT * FROM profile_browser_phone_calls_v1 WHERE owner_user_id=? AND credential_hash=? AND client_request_id=?'.($lock?vp3_phone_workspace_lock_v1($pdo):''));$q->execute([(int)$ctx['owner_user_id'],$ctx['conversation_authority']['credential_hash'],$request]);$call=$q->fetch(PDO::FETCH_ASSOC);
    if(isset($input['call_id'])&&($input['call_id']!==null&&$input['call_id']!=='')){vp3_profile_browser_phone_request_id_v1($input['call_id']);if(!$call||!hash_equals($call['public_id'],$input['call_id']))vp3_profile_browser_phone_error_v1('PROFILE_PHONE_NOT_FOUND',404,'This call is unavailable.');}
    if($call){foreach(['profile_agent_id'=>(int)$ctx['agent_id'],'profile_session_id'=>(int)$ctx['session_id'],'visitor_user_id'=>(int)($ctx['visitor']['id']??0)] as $k=>$v)if((int)$call[$k]!==$v)vp3_profile_browser_phone_error_v1();
        $cid=(int)($input['conversation_id']??0);if($cid>0&&$cid!==(int)$call['conversation_id'])vp3_profile_browser_phone_error_v1();
        vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,(int)$call['conversation_id'],$lock);
    }return $call?:null;
}
function vp3_profile_browser_phone_prepare_v1(PDO $pdo,array $ctx,array $input,?callable $carrier=null): array
{
    vp3_profile_browser_phone_require_v1($pdo);$request=vp3_profile_browser_phone_request_id_v1($input['client_request_id']??null);
    $ctx=vp3_profile_agent_public_live_v1($pdo,$ctx);
    vp3_profile_browser_phone_reconcile_owner_v1($pdo,(int)$ctx['owner_user_id'],$carrier);
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$ctx,$input,$request){
        $owner=(int)$ctx['owner_user_id'];vp3_profile_browser_phone_owner_lock_v1($pdo,$owner);$ctx=vp3_profile_agent_public_live_v1($pdo,$ctx,true);
        $prior=vp3_profile_browser_phone_lookup_v1($pdo,$ctx,$input,true);
        if($prior){if($prior['state']==='prepared'&&(int)$prior['expires_ms']<=vp3_profile_browser_phone_now_v1()){vp3_profile_browser_phone_settle_v1($pdo,$prior,'expired');$prior=vp3_profile_browser_phone_row_v1($pdo,$prior['public_id'],true);}
            if(!vp3_profile_browser_phone_terminal_v1($prior['state']))vp3_profile_browser_phone_authority_v1($pdo,$prior);
            return vp3_profile_browser_phone_result_v1($prior)+($prior['state']==='prepared'&&$prior['end_requested_ms']===null?vp3_profile_browser_phone_token_v1($prior):[]);
        }
        $q=$pdo->prepare('SELECT client_request_id FROM profile_browser_phone_cancellations_v1 WHERE owner_user_id=? AND credential_hash=? AND client_request_id=?');$q->execute([$owner,$ctx['conversation_authority']['credential_hash'],$request]);if($q->fetchColumn())vp3_profile_browser_phone_error_v1('PROFILE_PHONE_CANCELED',409,'This call request was canceled. Start a new call.');
        $line=vp3_profile_browser_phone_line_v1($pdo,$ctx['profile']);$saved=$line['settings'];$n=$line['number'];$now=vp3_profile_browser_phone_now_v1();
        $expired=$pdo->prepare("SELECT * FROM profile_browser_phone_calls_v1 WHERE owner_user_id=? AND state='prepared' AND expires_ms<=?");$expired->execute([$owner,$now]);foreach($expired->fetchAll(PDO::FETCH_ASSOC) as $old)vp3_profile_browser_phone_settle_v1($pdo,$old,'expired');
        if(vp3_profile_browser_phone_active_v1($pdo,$owner))vp3_profile_browser_phone_error_v1('PROFILE_PHONE_BUSY',409,'This Profile already has a phone call in progress.');
        if(vp3_profile_call_schema_ready_pa3c($pdo)&&vp3_profile_call_owner_busy_pa3c($pdo,$owner))vp3_profile_browser_phone_error_v1('PROFILE_PHONE_BUSY',409,'End the current Profile call first.');
        $q=$pdo->prepare("SELECT id FROM phone_browser_calls_v1 WHERE owner_user_id=? AND state IN ('prepared','dialing','ringing','answered') LIMIT 1");$q->execute([$owner]);if($q->fetchColumn())vp3_profile_browser_phone_error_v1('PROFILE_PHONE_BUSY',409,'The owner’s phone line is busy. Try again shortly.');
        $q=$pdo->prepare('SELECT COUNT(*) total,SUM(CASE WHEN credential_hash=? THEN 1 ELSE 0 END) visitor_total FROM profile_browser_phone_calls_v1 WHERE owner_user_id=? AND created_ms>?');$q->execute([$ctx['conversation_authority']['credential_hash'],$owner,$now-600000]);$counts=$q->fetch(PDO::FETCH_ASSOC);if((int)$counts['total']>=10||(int)$counts['visitor_total']>=3)vp3_profile_browser_phone_error_v1('PROFILE_PHONE_RATE_LIMITED',429,'Please wait before starting another phone call.');
        require_once __DIR__.'/profile-calls-pa3c.php';vp3_profile_call_block_check_pa3c($pdo,$owner,(int)($ctx['visitor']['id']??0),true);
        $cid=(int)($input['conversation_id']??0);$conversation=$cid>0?vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$cid,true):vp3_profile_agent_public_create_bound_v1($pdo,$ctx);$cid=(int)$conversation['id'];
        if(vp3_profile_handoff_media_active_pa3d($pdo,$owner,$cid,true))vp3_profile_browser_phone_error_v1('PROFILE_PHONE_BUSY',409,'End the current Profile call first.');
        $conversation=vp3_profile_agent_prepare_visitor_turn_v390($pdo,$conversation);
        $public=bin2hex(random_bytes(16));$call=['public_id'=>$public,'owner_user_id'=>$owner,'credential_hash'=>$ctx['conversation_authority']['credential_hash']];$intent=vp3_profile_browser_phone_intent_v1($call);
        $pdo->prepare("INSERT INTO profile_browser_phone_calls_v1(public_id,owner_user_id,profile_agent_id,phone_agent_id,phone_number_id,phone_e164,phone_revision,profile_session_id,visitor_user_id,conversation_id,credential_hash,visitor_auth_revision,session_fingerprint,client_request_id,identity,intent_hash,config_fingerprint,provider_account_sid,state,route_kind,response_twiml,created_ms,expires_ms,updated_ms) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'prepared','pending','',?,?,?)")
            ->execute([$public,$owner,(int)$ctx['agent_id'],(int)$n['agent_id'],(int)$n['id'],$n['phone_e164'],(int)$saved['revision'],(int)$ctx['session_id'],(int)($ctx['visitor']['id']??0),$cid,$call['credential_hash'],$ctx['accepted_visitor_auth_revision']??'',hash('sha256',$ctx['session']['session_key']),$request,'vp3_profile_'.$public,hash('sha256',$intent),vp3_profile_browser_phone_config_v1(),(string)vp3_phone_twilio_state_v100()['account_sid'],$now,$now+90000,$now]);
        $pdo->prepare("INSERT INTO profile_agent_messages(conversation_id,sender_type,sender_user_id,message,context_json) VALUES(?,'receipt',NULL,'Browser phone call requested.',?)")->execute([$cid,json_encode(['contract'=>VP3_PROFILE_BROWSER_PHONE_V1,'call_id'=>$public],JSON_THROW_ON_ERROR)]);$mid=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE profile_browser_phone_calls_v1 SET receipt_message_id=? WHERE public_id=?')->execute([$mid,$public]);
        $pdo->prepare('UPDATE profile_agent_conversations SET last_message_at=NOW(),updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([$cid,$owner]);
        vp3_profile_browser_phone_hold_v1($pdo,$owner,$cid);$call=vp3_profile_browser_phone_row_v1($pdo,$public,true);vp3_profile_browser_phone_authority_v1($pdo,$call);
        return vp3_profile_browser_phone_result_v1($call)+vp3_profile_browser_phone_token_v1($call);
    });
}
function vp3_profile_browser_phone_state_v1(PDO $pdo,array $ctx,array $input,?callable $carrier=null): array
{
    vp3_profile_browser_phone_require_v1($pdo);$call=vp3_profile_browser_phone_lookup_v1($pdo,$ctx,$input);
    if($call){vp3_profile_browser_phone_reconcile_v1($pdo,$call,$carrier);$call=vp3_profile_browser_phone_row_v1($pdo,$call['public_id']);}
    if($call&&$call['state']==='prepared'&&(int)$call['expires_ms']<=vp3_profile_browser_phone_now_v1()){
        vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$call){vp3_profile_browser_phone_owner_lock_v1($pdo,(int)$call['owner_user_id']);$c=vp3_profile_browser_phone_row_v1($pdo,$call['public_id'],true);if($c['state']==='prepared')vp3_profile_browser_phone_settle_v1($pdo,$c,'expired');});$call=vp3_profile_browser_phone_row_v1($pdo,$call['public_id']);
    }
    if($call&&!vp3_profile_browser_phone_terminal_v1($call['state']))vp3_profile_browser_phone_authority_v1($pdo,$call);
    vp3_profile_agent_public_live_v1($pdo,$ctx);return vp3_profile_browser_phone_result_v1($call,(int)($input['conversation_id']??0));
}
/** A cancellation before admission is retained so a late prepare cannot create an orphan. */
function vp3_profile_browser_phone_end_v1(PDO $pdo,array $ctx,array $input,?callable $carrier=null): array
{
    vp3_profile_browser_phone_require_v1($pdo);$request=vp3_profile_browser_phone_request_id_v1($input['client_request_id']??null);
    $call=vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$ctx,$input,$request){
        $owner=(int)$ctx['owner_user_id'];vp3_profile_browser_phone_owner_lock_v1($pdo,$owner);vp3_profile_agent_public_live_v1($pdo,$ctx,true);$call=vp3_profile_browser_phone_lookup_v1($pdo,$ctx,$input,true);
        $q=$pdo->prepare('SELECT client_request_id FROM profile_browser_phone_cancellations_v1 WHERE owner_user_id=? AND credential_hash=? AND client_request_id=?');$params=[$owner,$ctx['conversation_authority']['credential_hash'],$request];$q->execute($params);
        if(!$q->fetchColumn()){
            // Keep cancellation-before-admission reliable without permitting
            // unlimited anonymous tombstones. Known calls can always be ended.
            if(!$call){
                $limit=$pdo->prepare('SELECT COUNT(*) total,SUM(CASE WHEN credential_hash=? THEN 1 ELSE 0 END) visitor_total FROM profile_browser_phone_cancellations_v1 WHERE owner_user_id=? AND created_ms>?');
                $limit->execute([$ctx['conversation_authority']['credential_hash'],$owner,vp3_profile_browser_phone_now_v1()-600000]);$count=$limit->fetch(PDO::FETCH_ASSOC);
                if((int)$count['total']>=300||(int)$count['visitor_total']>=30)vp3_profile_browser_phone_error_v1('PROFILE_PHONE_RATE_LIMITED',429,'Please wait before canceling another call request.');
            }
            $pdo->prepare('INSERT INTO profile_browser_phone_cancellations_v1(owner_user_id,credential_hash,client_request_id,created_ms) VALUES(?,?,?,?)')->execute([...$params,vp3_profile_browser_phone_now_v1()]);
        }
        if(!$call||vp3_profile_browser_phone_terminal_v1($call['state']))return $call;
        if($call['state']==='prepared'){vp3_profile_browser_phone_settle_v1($pdo,$call,'canceled');return vp3_profile_browser_phone_row_v1($pdo,$call['public_id'],true);}
        $pdo->prepare('UPDATE profile_browser_phone_calls_v1 SET end_requested_ms=COALESCE(end_requested_ms,?),updated_ms=? WHERE public_id=?')->execute([vp3_profile_browser_phone_now_v1(),vp3_profile_browser_phone_now_v1(),$call['public_id']]);return vp3_profile_browser_phone_row_v1($pdo,$call['public_id'],true);
    });
    if($call&&!vp3_profile_browser_phone_terminal_v1($call['state'])&&$call['provider_call_sid']){
        // Only end the exact server-bound call; a browser can supply neither SID nor destination.
        $account=(string)vp3_phone_twilio_state_v100()['account_sid'];$sid=$call['provider_call_sid'];$carrier=$carrier??'vp3_phone_twilio_http_v100';
        if(!hash_equals((string)$call['provider_account_sid'],$account))return vp3_profile_browser_phone_result_v1($call);
        try{$receipt=$carrier('POST','/2010-04-01/Accounts/'.$account.'/Calls/'.$sid.'.json',['Status'=>'completed']);
            if(is_array($receipt))vp3_profile_browser_phone_provider_receipt_v1($pdo,$call,$receipt);
        }catch(Throwable){/* The SDK disconnect and signed callback still settle an uncertain end. */}
        $call=vp3_profile_browser_phone_row_v1($pdo,$call['public_id']);
    }
    return vp3_profile_browser_phone_result_v1($call,(int)($input['conversation_id']??0));
}
/** The caller has already verified the carrier signature at the fixed TwiML App URL. */
function vp3_profile_browser_phone_voice_v1(PDO $pdo,array $form,string $parent): string
{
    vp3_profile_browser_phone_require_v1($pdo);$intent=$form['profile_call_intent']??null;
    if(!is_string($intent)||!preg_match('/\A[a-f0-9]{64}\z/D',$intent))return vp3_phone_browser_hangup_v1();
    $q=$pdo->prepare('SELECT public_id,owner_user_id FROM profile_browser_phone_calls_v1 WHERE intent_hash=?');$q->execute([hash('sha256',$intent)]);$key=$q->fetch(PDO::FETCH_ASSOC);if(!$key)return vp3_phone_browser_hangup_v1();
    try{$admitted=vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$key,$intent,$form,$parent){
        vp3_profile_browser_phone_owner_lock_v1($pdo,(int)$key['owner_user_id']);$call=vp3_profile_browser_phone_row_v1($pdo,$key['public_id'],true);
        if(!hash_equals('client:'.$call['identity'],(string)($form['From']??''))||$call['end_requested_ms']!==null||vp3_profile_browser_phone_now_v1()>(int)$call['created_ms']+600000)return null;
        try{vp3_profile_browser_phone_authority_v1($pdo,$call);}catch(VP3ProfileBrowserPhoneException|VP3ProfileCallException){return null;}
        if($call['provider_call_sid'])return hash_equals($call['provider_call_sid'],$parent)&&!vp3_profile_browser_phone_terminal_v1($call['state'])?['duplicate'=>$call['response_twiml']]:null;
        if($call['state']!=='prepared'||(int)$call['expires_ms']<=vp3_profile_browser_phone_now_v1())return null;
        $q=$pdo->prepare("SELECT id FROM phone_browser_calls_v1 WHERE owner_user_id=? AND state IN ('prepared','dialing','ringing','answered') LIMIT 1");$q->execute([(int)$call['owner_user_id']]);if($q->fetchColumn())return null;
        $now=vp3_profile_browser_phone_now_v1();$pdo->prepare("UPDATE profile_browser_phone_calls_v1 SET state='dialing',provider_call_sid=?,updated_ms=? WHERE public_id=? AND state='prepared' AND provider_call_sid IS NULL")->execute([$parent,$now,$call['public_id']]);
        $pdo->prepare("INSERT INTO phone_voice_calls_v130(provider,provider_call_sid,owner_user_id,phone_number_id,agent_id,caller_e164,called_e164,created_at) VALUES('twilio',?,?,?,?,'',?,?)")->execute([$parent,(int)$call['owner_user_id'],(int)$call['phone_number_id'],(int)$call['phone_agent_id'],$call['phone_e164'],gmdate('Y-m-d H:i:s')]);
        return $call;
    });}catch(VP3ProfileBrowserPhoneException|VP3ProfileCallException){return vp3_phone_browser_hangup_v1();}
    if(!$admitted)return vp3_phone_browser_hangup_v1();
    if(array_key_exists('duplicate',$admitted))return $admitted['duplicate']!==''?$admitted['duplicate']:vp3_phone_browser_hangup_v1();
    $route=vp3_phone_voice_route_accepted_v130($pdo,$parent,vp3_phone_voice_callback_url_v130());$xml=!empty($route['accepted'])&&is_string($route['twiml']??null)?$route['twiml']:vp3_phone_browser_hangup_v1();
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$admitted,$parent,$xml){
        vp3_profile_browser_phone_owner_lock_v1($pdo,(int)$admitted['owner_user_id'],false);$call=vp3_profile_browser_phone_row_v1($pdo,$admitted['public_id'],true);
        if(!$call||$call['provider_call_sid']!==$parent||!vp3_profile_browser_phone_current_sid_v1($pdo,$parent))return vp3_phone_browser_hangup_v1();
        $pdo->prepare('UPDATE profile_browser_phone_calls_v1 SET response_twiml=? WHERE public_id=?')->execute([$xml,$call['public_id']]);vp3_profile_browser_phone_receipt_v1($pdo,$call);return $xml;
    });
}
function vp3_profile_browser_phone_route_v1(PDO $pdo,string $sid,string $route): void
{
    if(!in_array($route,['owner','agent','voicemail'],true))throw new InvalidArgumentException('Invalid Profile phone route.');
    $call=vp3_profile_browser_phone_find_sid_v1($pdo,$sid);if(!$call||vp3_profile_browser_phone_terminal_v1($call['state']))return;
    $pdo->prepare("UPDATE profile_browser_phone_calls_v1 SET route_kind=?,state=CASE WHEN state='answered' THEN state ELSE ? END,updated_ms=? WHERE public_id=? AND ended_ms IS NULL")->execute([$route,$route==='owner'?'ringing':'dialing',vp3_profile_browser_phone_now_v1(),$call['public_id']]);
    vp3_profile_browser_phone_receipt_v1($pdo,vp3_profile_browser_phone_row_v1($pdo,$call['public_id']));
}
/** Child pickup is distinct from the parent call's transport completion. */
function vp3_profile_browser_phone_sync_v1(PDO $pdo,array $phone,bool $complete,array $evidence=[]): void
{
    $call=vp3_profile_browser_phone_find_sid_v1($pdo,(string)($phone['provider_call_sid']??''));if(!$call)return;
    $answered=($evidence['answered']??false)===true||$phone['state']==='answered'||($phone['state']==='completed'&&(int)$phone['duration_seconds']>0);
    if($answered)$pdo->prepare("UPDATE profile_browser_phone_calls_v1 SET state=CASE WHEN ended_ms IS NULL THEN 'answered' ELSE state END,route_kind='owner',owner_answered_ms=COALESCE(owner_answered_ms,?),updated_ms=? WHERE public_id=?")->execute([vp3_profile_browser_phone_now_v1(),vp3_profile_browser_phone_now_v1(),$call['public_id']]);
    elseif($phone['state']==='ringing')$pdo->prepare("UPDATE profile_browser_phone_calls_v1 SET state='ringing',updated_ms=? WHERE public_id=? AND state IN ('prepared','dialing','ringing')")->execute([vp3_profile_browser_phone_now_v1(),$call['public_id']]);
    vp3_profile_browser_phone_receipt_v1($pdo,vp3_profile_browser_phone_row_v1($pdo,$call['public_id']));
}
function vp3_profile_browser_phone_parent_status_v1(PDO $pdo,array $form,string $signature): array
{
    $sid=vp3_phone_browser_verify_v1($form,$signature,vp3_phone_browser_callback_v1('status'));$call=vp3_profile_browser_phone_find_sid_v1($pdo,$sid);if(!$call||($form['AccountSid']??null)!==$call['provider_account_sid'])return ['accepted'=>false];
    $map=['completed'=>'completed','busy'=>'busy','no-answer'=>'no_answer','failed'=>'failed','canceled'=>'canceled'];$status=$form['CallStatus']??'';$duration=$form['CallDuration']??'0';
    if(!isset($map[$status])||!is_string($duration)||!preg_match('/\A[0-9]{1,6}\z/D',$duration))return ['accepted'=>false];
    return vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$call,$sid,$map,$status,$duration){
        // Historical signed completion remains valid after publication is revoked.
        vp3_profile_browser_phone_owner_lock_v1($pdo,(int)$call['owner_user_id'],false);$fresh=vp3_profile_browser_phone_row_v1($pdo,$call['public_id'],true);
        if(!$fresh||$fresh['provider_call_sid']!==$sid)return ['accepted'=>false];
        vp3_profile_browser_phone_settle_v1($pdo,$fresh,$map[$status],min(86400,(int)$duration));return ['accepted'=>true];
    });
}
