<?php
declare(strict_types=1);

/** Twilio browser transport. Only signed carrier callbacks can consume dial intents. */
require_once __DIR__.'/phone-workspace-v1.php';
require_once __DIR__.'/phone-sms-v110.php';
require_once __DIR__.'/phone-voice-v130.php';

function vp3_phone_browser_callback_v1(string $kind,array $query=[]): string
{
    $files=['voice'=>'phone-browser-voice-v1.php','status'=>'phone-browser-status-v1.php','complete'=>'phone-browser-complete-v1.php','recording'=>'phone-browser-recording-v1.php','disclosure'=>'phone-browser-disclosure-v1.php','operator_answer'=>'phone-operator-answer-v1.php'];
    if(!isset($files[$kind]))throw new InvalidArgumentException('Invalid phone callback.');
    $base=vp3_phone_voice_validate_url_v130(vp3_phone_voice_callback_url_v130());
    $url=substr($base,0,-strlen('phone-voice-inbound-v130.php')).$files[$kind];
    return $url.($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):'');
}
function vp3_phone_browser_config_state_v1(): array
{
    $sid=trim((string)setting('phone_browser_api_key_sid_v1',''));$app=trim((string)setting('phone_browser_twiml_app_sid_v1',''));$stored=(string)setting('phone_browser_api_key_secret_v1','');$secret='';$urls=[];
    try{if($stored!=='')$secret=ai_decrypt_secret($stored);}catch(Throwable){}
    try{foreach(['voice','status','complete','recording','disclosure'] as $key)$urls[$key]=vp3_phone_browser_callback_v1($key);}catch(Throwable){}
    $ready=(bool)vp3_phone_twilio_state_v100()['configured']&&preg_match('/^SK[a-f0-9]{32}$/iD',$sid)===1&&preg_match('/^AP[a-f0-9]{32}$/iD',$app)===1&&strlen($secret)>=16&&count($urls)===5;
    return ['ready'=>$ready,'reason'=>$ready?'':'Ask an administrator to configure the browser Voice API key, secret, TwiML App and fixed HTTPS callbacks.',
        'api_key_sid'=>$sid,'twiml_app_sid'=>$app,'has_secret'=>$stored!=='','callback_urls'=>$urls];
}
function vp3_phone_browser_config_save_v1(array $input): void
{
    $sid=trim((string)($input['api_key_sid']??''));$app=trim((string)($input['twiml_app_sid']??''));$secret=trim((string)($input['api_key_secret']??''));
    if(!preg_match('/^SK[a-f0-9]{32}$/iD',$sid)||!preg_match('/^AP[a-f0-9]{32}$/iD',$app)||($secret!==''&&(strlen($secret)<16||strlen($secret)>256)))throw new InvalidArgumentException('Enter a valid Twilio API Key SID, secret and TwiML App SID.');
    if($secret===''){
        $old=(string)setting('phone_browser_api_key_sid_v1','');
        if(!hash_equals($old,$sid)||!vp3_phone_browser_config_state_v1()['has_secret'])throw new InvalidArgumentException('Enter the secret when setting or changing the browser API key.');
    }
    $encrypted=$secret!==''?ai_encrypt_secret($secret):null;
    save_setting('phone_browser_api_key_sid_v1',$sid);save_setting('phone_browser_twiml_app_sid_v1',$app);if($encrypted!==null)save_setting('phone_browser_api_key_secret_v1',$encrypted);
}
function vp3_phone_browser_number_policy_set_v1(PDO $pdo,int $numberId,bool $enabled,bool $recording): void
{
    if(!vp3_phone_workspace_schema_ready_v1($pdo))vp3_phone_workspace_error_v1('phone_upgrade_required',503,'Run the phone workspace upgrade first.');
    vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$numberId,$enabled,$recording){
        $q=$pdo->prepare('SELECT * FROM phone_cloud_numbers_v100 WHERE id=?'.vp3_phone_workspace_lock_v1($pdo));$q->execute([$numberId]);$n=$q->fetch(PDO::FETCH_ASSOC);
        if(!$n||(int)$n['owner_user_id']<1||!vp3_phone_agent_owned_v100($pdo,(int)$n['owner_user_id'],(int)$n['agent_id']))throw new RuntimeException('Assign this number to an active owner Agent first.');
        if($enabled){
            if(!(int)$n['voice_enabled'])throw new RuntimeException('Click Enable incoming voicemail for this number in Registered number inventory before enabling browser calling.');
            $plan=vp3_phone_package_v100($pdo,(int)$n['owner_user_id']);
            if(!$plan['included'])throw new RuntimeException('The assigned member needs Agent Phone Numbers Included with a positive quantity in their subscription package or an Admin entitlement grant.');
            if(!$plan['voice'])throw new RuntimeException('The assigned member needs Agent Phone Calls (phone.voice) in their subscription package or an Admin entitlement grant.');
            if(!vp3_phone_browser_config_state_v1()['ready'])throw new RuntimeException('Complete Browser phone with a Twilio API Key SID, API Key Secret and TwiML App SID, and save the Signed HTTPS voice webhook URL under Incoming calls first.');
        }
        $pdo->prepare('DELETE FROM phone_browser_numbers_v1 WHERE phone_number_id=?')->execute([$numberId]);
        $pdo->prepare('INSERT INTO phone_browser_numbers_v1(phone_number_id,owner_user_id,agent_id,browser_enabled,recording_enabled) VALUES (?,?,?,?,?)')->execute([$numberId,(int)$n['owner_user_id'],(int)$n['agent_id'],$enabled?1:0,$recording?1:0]);
        if(!$enabled||!$recording)vp3_operator_live_revoke_line_v1($pdo,$numberId);
    });
}
function vp3_phone_browser_policy_v1(PDO $pdo,array $number): array
{
    $q=$pdo->prepare('SELECT browser_enabled,recording_enabled FROM phone_browser_numbers_v1 WHERE phone_number_id=? AND owner_user_id=? AND agent_id=?');$q->execute([(int)$number['id'],(int)$number['owner_user_id'],(int)$number['agent_id']]);return $q->fetch(PDO::FETCH_ASSOC)?:['browser_enabled'=>0,'recording_enabled'=>0];
}
function vp3_phone_browser_require_v1(PDO $pdo,array $scope,int $numberId,bool $lock=false): array
{
    $number=vp3_phone_workspace_number_v1($pdo,$scope,$numberId,true,$lock);
    $policy=vp3_phone_browser_policy_v1($pdo,$number);
    if(!(int)$policy['browser_enabled']||!vp3_phone_browser_config_state_v1()['ready'])vp3_phone_workspace_error_v1('phone_browser_not_ready',409,'Browser calling is not enabled and configured for this assigned number.');
    return $number+['recording_enabled'=>(int)$policy['recording_enabled']];
}
function vp3_phone_browser_identity_v1(int $owner,int $chatAgent=0): string{if($owner<1||$chatAgent<0)throw new InvalidArgumentException('Invalid phone identity.');return 'vp3_phone_u'.$owner.'_a'.$chatAgent;}
function vp3_phone_browser_base64_v1(string $data): string{return rtrim(strtr(base64_encode($data),'+/','-_'),'=');}
function vp3_phone_browser_token_v1(int $owner,int $now=0,int $chatAgent=0): array
{
    $now=$now?:time();$config=vp3_phone_browser_config_state_v1();if(!$config['ready'])vp3_phone_workspace_error_v1('phone_browser_not_ready',409,$config['reason']);
    $identity=vp3_phone_browser_identity_v1($owner,$chatAgent);$account=(string)vp3_phone_twilio_state_v100()['account_sid'];$expiry=$now+300;
    $header=['typ'=>'JWT','alg'=>'HS256','cty'=>'twilio-fpa;v=1'];$claims=['jti'=>$config['api_key_sid'].'-'.bin2hex(random_bytes(12)),'iss'=>$config['api_key_sid'],'sub'=>$account,'iat'=>$now,'nbf'=>$now,'exp'=>$expiry,
        'grants'=>['identity'=>$identity,'voice'=>['incoming'=>['allow'=>true],'outgoing'=>['application_sid'=>$config['twiml_app_sid']]]]];
    $body=vp3_phone_browser_base64_v1(json_encode($header,JSON_THROW_ON_ERROR)).'.'.vp3_phone_browser_base64_v1(json_encode($claims,JSON_THROW_ON_ERROR));
    $secret=ai_decrypt_secret((string)setting('phone_browser_api_key_secret_v1',''));
    return ['token'=>$body.'.'.vp3_phone_browser_base64_v1(hash_hmac('sha256',$body,$secret,true)),'identity'=>$identity,'expires_at'=>$expiry];
}
function vp3_phone_browser_action_v1(PDO $pdo,array $scope,array $input): array
{
    $action=(string)$input['action'];
    if($action==='cancel_prepared'){
        $public=$input['call_id']??null;if(!is_string($public)||!preg_match('/^[a-f0-9]{32}$/D',$public))vp3_phone_workspace_error_v1('phone_call_unavailable',404,'This call is unavailable.');
        return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$public){
            $q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE public_id=? AND owner_user_id=?'.vp3_phone_workspace_lock_v1($pdo));$q->execute([$public,$scope['owner_user_id']]);$call=$q->fetch(PDO::FETCH_ASSOC);
            if(!$call||($scope['agent_id']>0&&(int)$call['agent_id']!==$scope['agent_id']))vp3_phone_workspace_error_v1('phone_call_unavailable',404,'This call is unavailable.');
            vp3_phone_workspace_number_v1($pdo,$scope,(int)$call['phone_number_id']);
            if($call['state']==='canceled'&&!(int)$call['intent_consumed_epoch']&&!$call['provider_call_sid'])return ['canceled'=>true];
            if($call['state']!=='prepared'||(int)$call['intent_consumed_epoch']||$call['provider_call_sid'])vp3_phone_workspace_error_v1('phone_call_already_started',409,'The carrier has already accepted this call. Use the live call controls.');
            $pdo->prepare("UPDATE phone_browser_calls_v1 SET state='canceled',ended_epoch=?,updated_epoch=? WHERE id=? AND state='prepared' AND intent_consumed_epoch=0 AND provider_call_sid IS NULL")->execute([time(),time(),$call['id']]);return ['canceled'=>true];
        });
    }
    if($action==='route_incoming'){
        vp3_phone_voicemail_require_private_v1();
        $choice=$input['destination']??null;
        $public=$input['call_id']??null;
        if(!in_array($choice,['agent','voicemail'],true)||!is_string($public)||!preg_match('/^[a-f0-9]{32}$/D',$public))
            vp3_phone_workspace_error_v1('invalid_phone_request',422,'Choose a ringing call and a destination.');
        return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$public,$choice){
            $q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE public_id=? AND owner_user_id=?'.vp3_phone_workspace_lock_v1($pdo));
            $q->execute([$public,$scope['owner_user_id']]);$call=$q->fetch(PDO::FETCH_ASSOC);
            if(!$call||$call['direction']!=='inbound'||$call['transport_kind']!=='browser'||$scope['conversation_id']<1||
                (int)$call['conversation_id']!==$scope['conversation_id']||(int)$call['chat_agent_id']!==$scope['agent_id'])
                vp3_phone_workspace_error_v1('phone_call_unavailable',404,'Open the incoming call in its original Chat.');
            vp3_phone_browser_require_v1($pdo,$scope,(int)$call['phone_number_id']);
            if(!array_key_exists('incoming_choice',$call))vp3_phone_workspace_error_v1('phone_upgrade_required',503,'Run the phone workspace upgrade.');
            if($call['state']!=='ringing'||$call['fallback_claimed_epoch']||time()-(int)$call['created_epoch']>120)
                vp3_phone_workspace_error_v1('phone_call_changed',409,'This call is no longer ringing.');
            if($call['incoming_choice']!==''&&$call['incoming_choice']!==$choice)
                vp3_phone_workspace_error_v1('phone_call_changed',409,'Another call action already won.');
            if($choice==='agent'){
                require_once __DIR__.'/phone-voice-conversation-v140.php';
                if(!vp3_phone_voice_live_config_v140($pdo,$call))
                    vp3_phone_workspace_error_v1('phone_agent_unavailable',409,'Enable live Agent conversation for this line before sending a caller to the Agent.');
            }
            $pdo->prepare('UPDATE phone_browser_calls_v1 SET incoming_choice=?,updated_epoch=? WHERE id=?')->execute([$choice,time(),$call['id']]);
            return ['destination'=>$choice,'call_id'=>$public];
        });
    }
    if($action==='bind_call'){
        $public=$input['call_id']??null;if(!is_string($public)||!preg_match('/^[a-f0-9]{32}$/D',$public)||$scope['conversation_id']<1)vp3_phone_workspace_error_v1('phone_call_unavailable',404,'Open this incoming call in an authorized Chat.');
        return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$public){
            $q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE public_id=? AND owner_user_id=?'.vp3_phone_workspace_lock_v1($pdo));$q->execute([$public,$scope['owner_user_id']]);$call=$q->fetch(PDO::FETCH_ASSOC);
            if(!$call||(string)($call['paired_profile_id']??'')!==''||($scope['agent_id']>0&&(int)$call['agent_id']!==$scope['agent_id'])||!in_array($call['state'],['ringing','dialing','answered'],true))vp3_phone_workspace_error_v1('phone_call_unavailable',404,'This call is unavailable.');
            vp3_phone_browser_require_v1($pdo,$scope,(int)$call['phone_number_id']);
            if((string)$call['phone_e164']!==(string)vp3_phone_workspace_number_v1($pdo,$scope,(int)$call['phone_number_id'])['phone_e164'])vp3_phone_workspace_error_v1();
            if(array_key_exists('incoming_choice',$call)&&$call['direction']==='inbound'){
                if($call['incoming_choice']!=='')vp3_phone_workspace_error_v1('phone_call_changed',409,'Another call action already won.');
                $pdo->prepare("UPDATE phone_browser_calls_v1 SET incoming_choice='answer' WHERE id=?")->execute([$call['id']]);
            }
            $pdo->prepare('UPDATE phone_browser_calls_v1 SET conversation_id=?,chat_agent_id=?,updated_epoch=? WHERE id=?')->execute([$scope['conversation_id'],$scope['agent_id'],time(),$call['id']]);$call['conversation_id']=$scope['conversation_id'];$call['chat_agent_id']=$scope['agent_id'];
            return ['call'=>vp3_phone_workspace_call_projection_v1($call)];
        });
    }
    $numbers=vp3_phone_workspace_numbers_v1($pdo,$scope);$id=vp3_phone_workspace_id_v1($input['number_id']??vp3_phone_workspace_selected_v1($pdo,$scope,$numbers));
    if($action==='token'){
        vp3_phone_browser_require_v1($pdo,$scope,$id);
        vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope){$pdo->prepare('DELETE FROM phone_browser_presence_v1 WHERE owner_user_id=?')->execute([$scope['owner_user_id']]);$pdo->prepare('INSERT INTO phone_browser_presence_v1(owner_user_id,last_epoch,conversation_id,chat_agent_id) VALUES (?,?,?,?)')->execute([$scope['owner_user_id'],time(),$scope['conversation_id']?:null,$scope['agent_id']]);});
        return vp3_phone_browser_token_v1($scope['owner_user_id'],0,$scope['agent_id']);
    }
    $ownerTest=$action==='prepare_owner_test';$connected=$action==='prepare_connected_call';
    if($action!=='prepare_call'&&!$ownerTest&&!$connected)vp3_phone_workspace_error_v1('invalid_phone_action',422,'Choose a supported phone action.');
    if($scope['conversation_id']<1)vp3_phone_workspace_error_v1('phone_conversation_required',409,'Open a Chat conversation before dialing.');
    if(!$ownerTest&&!$connected&&!is_string($input['to_number']??null))vp3_phone_workspace_error_v1('invalid_phone_request',422,'Choose a valid international phone number.');
    $to=$ownerTest||$connected?'':vp3_phone_e164_v100($input['to_number']);if(array_key_exists('recording_enabled',$input)&&!is_bool($input['recording_enabled']))vp3_phone_workspace_error_v1('invalid_phone_request',422,'Choose a recording preference.');
    vp3_phone_browser_require_v1($pdo,$scope,$id);
    vp3_profile_browser_phone_reconcile_owner_v1($pdo,(int)$scope['owner_user_id']);
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$id,$to,$input,$ownerTest,$connected){
        $q=$pdo->prepare('SELECT id FROM users WHERE id=?'.vp3_phone_workspace_lock_v1($pdo));$q->execute([$scope['owner_user_id']]);$n=vp3_phone_browser_require_v1($pdo,$scope,$id,true);
        $testRevision=0;$paired=null;
        if($ownerTest){$test=vp3_owner_phone_test_destination_v1($pdo,$scope,$input,time());$to=$test['destination'];$testRevision=$test['revision'];}
        if($connected){$paired=vp3_connected_phone_prepare_v1($pdo,$scope,$input,time());$to=$paired['destination'];}
        if(hash_equals((string)$n['phone_e164'],$to))vp3_phone_workspace_error_v1('invalid_phone_destination',422,'Choose a different destination number.');
        $now=time();$pdo->prepare("UPDATE phone_browser_calls_v1 SET state='canceled',ended_epoch=?,updated_epoch=? WHERE owner_user_id=? AND state='prepared' AND intent_expires_epoch<?")->execute([$now,$now,$scope['owner_user_id'],$now]);
        $q=$pdo->prepare("SELECT COUNT(*) FROM phone_browser_calls_v1 WHERE owner_user_id=? AND state IN ('prepared','dialing','ringing','answered')");$q->execute([$scope['owner_user_id']]);if((int)$q->fetchColumn()>0||vp3_profile_browser_phone_active_v1($pdo,(int)$scope['owner_user_id']))vp3_phone_workspace_error_v1('phone_busy',409,'End the current call before dialing another.');
        $q=$pdo->prepare('SELECT COUNT(*) FROM phone_browser_calls_v1 WHERE owner_user_id=? AND created_epoch>?');$q->execute([$scope['owner_user_id'],$now-600]);if((int)$q->fetchColumn()>=10)vp3_phone_workspace_error_v1('phone_rate_limited',429,'Wait before placing more calls.');
        $public=bin2hex(random_bytes(16));$intent=bin2hex(random_bytes(32));$record=!$ownerTest&&!$connected&&!empty($input['recording_enabled'])&&!empty($n['recording_enabled']);
        $pdo->prepare("INSERT INTO phone_browser_calls_v1(public_id,owner_user_id,agent_id,phone_number_id,phone_e164,peer_e164,direction,intent_hash,intent_expires_epoch,conversation_id,chat_agent_id,state,recording_enabled,created_epoch,updated_epoch,owner_phone_revision,paired_device_id,paired_profile_id,paired_profile_revision,paired_phone_revision) VALUES (?,?,?,?,?,?,'outbound',?,?,?,?, 'prepared',?,?,?,?,?,?,?,?)")
            ->execute([$public,$scope['owner_user_id'],(int)$n['agent_id'],$id,(string)$n['phone_e164'],$to,hash('sha256',$intent),$now+90,$scope['conversation_id'],$scope['agent_id'],$record?1:0,$now,$now,$testRevision,$paired['device_id']??null,$paired['profile_id']??null,$paired['revision']??0,$paired['phone_revision']??0]);
        $q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE public_id=?');$q->execute([$public]);$call=$q->fetch(PDO::FETCH_ASSOC);
        $delivery=vp3_phone_workspace_delivery_v1($pdo,$call,'call','call:'.$public,$ownerTest?'Personal phone test call.':($connected?'Outgoing connected phone call.':'Outgoing phone call.'));$pdo->prepare('UPDATE phone_browser_calls_v1 SET chat_message_id=? WHERE id=?')->execute([$delivery['message_id'],$call['id']]);
        return ['call'=>vp3_phone_workspace_call_projection_v1($call),'connect_params'=>['call_intent'=>$intent]];
    });
}
function vp3_phone_browser_xml_v1(string $value): string{return htmlspecialchars($value,ENT_QUOTES|ENT_XML1|ENT_SUBSTITUTE,'UTF-8');}
function vp3_phone_browser_hangup_v1(): string{return '<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>';}
function vp3_phone_browser_verify_v1(array $form,string $signature,string $url): string
{
    $provider=vp3_phone_twilio_state_v100();if(!$provider['configured'])throw new RuntimeException('Phone carrier is unavailable.');
    return vp3_phone_voice_check_event_v130($form,$signature,$url,vp3_phone_twilio_credentials_v100()[1],(string)$provider['account_sid']);
}
function vp3_phone_browser_live_call_v1(PDO $pdo,array $call): bool
{
    try{$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([(int)$call['owner_user_id']]);$user=$q->fetch(PDO::FETCH_ASSOC);if(!$user)return false;
        $scope=vp3_phone_workspace_scope_v1($pdo,$user,['agent_id'=>(int)$call['agent_id']]);$n=vp3_phone_browser_require_v1($pdo,$scope,(int)$call['phone_number_id']);return hash_equals((string)$call['phone_e164'],(string)$n['phone_e164']);
    }catch(VP3PhoneWorkspaceException){return false;}
}
function vp3_phone_browser_dial_attrs_v1(array $call,bool $record,int $timeout=20): string
{
    $query=['call'=>(string)$call['public_id']];$attrs=' answerOnBridge="true" timeout="'.max(5,min(60,$timeout)).'" action="'.vp3_phone_browser_xml_v1(vp3_phone_browser_callback_v1('complete',$query)).'" method="POST"';
    if((int)($call['owner_phone_revision']??0)>0)$attrs.=' timeLimit="60"';
    elseif((string)($call['paired_profile_id']??'')!=='')$attrs.=' timeLimit="180"';
    if($record)$attrs.=' record="record-from-answer-dual" recordingStatusCallback="'.vp3_phone_browser_xml_v1(vp3_phone_browser_callback_v1('recording',$query)).'" recordingStatusCallbackMethod="POST" recordingStatusCallbackEvent="completed"';return $attrs;
}
function vp3_phone_browser_voice_v1(PDO $pdo,array $form,string $signature): string
{
    $parent=vp3_phone_browser_verify_v1($form,$signature,vp3_phone_browser_callback_v1('voice'));
    if(array_key_exists('profile_call_intent',$form)||(is_string($form['From']??null)&&str_starts_with($form['From'],'client:vp3_profile_'))){
        require_once __DIR__.'/profile-browser-phone-v1.php';
        return vp3_profile_browser_phone_voice_v1($pdo,$form,$parent);
    }
    $intent=$form['call_intent']??null;
    if(!is_string($intent)||!preg_match('/^[a-f0-9]{64}$/D',$intent))return vp3_phone_browser_hangup_v1();
    // Resolve the untrusted intent to its owner, then lock that owner before
    // the call row to share the pairing/number-edit lock order.
    $q=$pdo->prepare('SELECT owner_user_id FROM phone_browser_calls_v1 WHERE intent_hash=?');$q->execute([hash('sha256',$intent)]);$owner=(int)$q->fetchColumn();$q->closeCursor();
    if($owner<1)return vp3_phone_browser_hangup_v1();
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$form,$parent,$intent,$owner){
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->prepare('UPDATE users SET id=id WHERE id=?')->execute([$owner]);
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.vp3_phone_workspace_lock_v1($pdo));$lock->execute([$owner]);$found=$lock->fetchColumn();$lock->closeCursor();if(!$found)return vp3_phone_browser_hangup_v1();
        $q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE intent_hash=? AND owner_user_id=?'.vp3_phone_workspace_lock_v1($pdo));$q->execute([hash('sha256',$intent),$owner]);$call=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$call||$call['state']!=='prepared'||(int)$call['intent_consumed_epoch']>0||(int)$call['intent_expires_epoch']<time()||!hash_equals('client:'.vp3_phone_browser_identity_v1((int)$call['owner_user_id'],(int)$call['chat_agent_id']),(string)($form['From']??''))||!vp3_phone_browser_live_call_v1($pdo,$call))return vp3_phone_browser_hangup_v1();
        if(!vp3_owner_phone_test_current_v1($pdo,$call)||!vp3_connected_phone_current_v1($pdo,$call,time()))return vp3_phone_browser_hangup_v1();
        $now=time();$q=$pdo->prepare("UPDATE phone_browser_calls_v1 SET provider_call_sid=?,intent_consumed_epoch=?,state='dialing',updated_epoch=? WHERE id=? AND intent_consumed_epoch=0 AND state='prepared'");$q->execute([$parent,$now,$now,$call['id']]);if($q->rowCount()!==1)return vp3_phone_browser_hangup_v1();
        vp3_operator_objectives_bind_v1($pdo,$call,$now);
        $call['provider_call_sid']=$parent;$status=vp3_phone_browser_xml_v1(vp3_phone_browser_callback_v1('status',['call'=>$call['public_id']]));
        $disclosure=$call['recording_enabled']?' url="'.vp3_phone_browser_xml_v1(vp3_phone_browser_callback_v1('disclosure',['call'=>$call['public_id']])).'" method="POST"':'';
        return '<?xml version="1.0" encoding="UTF-8"?><Response><Dial callerId="'.vp3_phone_browser_xml_v1((string)$call['phone_e164']).'"'.vp3_phone_browser_dial_attrs_v1($call,(bool)$call['recording_enabled']).'><Number'.$disclosure.' statusCallback="'.$status.'" statusCallbackMethod="POST" statusCallbackEvent="initiated ringing answered completed">'.vp3_phone_browser_xml_v1((string)$call['peer_e164']).'</Number></Dial></Response>';
    });
}
/** Called only after the existing carrier-signed inbound receipt was accepted. */
function vp3_phone_browser_incoming_v1(PDO $pdo,array $route,string $sid,?string &$reason=null): ?string
{
    $reason='browser_unavailable';
    if(!vp3_phone_workspace_schema_ready_v1($pdo)||!preg_match('/^CA[a-f0-9]{32}$/iD',$sid)||!vp3_phone_browser_config_state_v1()['ready'])return null;
    $q=$pdo->prepare('SELECT * FROM phone_cloud_numbers_v100 WHERE id=?');$q->execute([(int)$route['phone_number_id']]);$n=$q->fetch(PDO::FETCH_ASSOC);if(!$n||(int)$n['owner_user_id']!==(int)$route['owner_user_id']||(int)$n['agent_id']!==(int)$route['agent_id'])return null;
    $policy=vp3_phone_browser_policy_v1($pdo,$n);if(!(int)$policy['browser_enabled']){$reason='line_disabled';return null;}
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$route,$sid,$n,$policy,&$reason){
        $q=$pdo->prepare('SELECT id FROM users WHERE id=?'.vp3_phone_workspace_lock_v1($pdo));$q->execute([(int)$n['owner_user_id']]);
        $q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE provider_call_sid=?'.vp3_phone_workspace_lock_v1($pdo));$q->execute([$sid]);$call=$q->fetch(PDO::FETCH_ASSOC);
        if(!$call){
            $call=['owner_user_id'=>(int)$n['owner_user_id'],'agent_id'=>(int)$n['agent_id'],'phone_number_id'=>(int)$n['id'],'phone_e164'=>(string)$n['phone_e164']];if(!vp3_phone_browser_live_call_v1($pdo,$call))return null;
            $q=$pdo->prepare("SELECT id FROM phone_voice_calls_v130 WHERE provider='twilio' AND provider_call_sid=?".vp3_phone_workspace_lock_v1($pdo));$q->execute([$sid]);$legacy=(int)$q->fetchColumn();
            if($legacy>0){$q=$pdo->prepare('SELECT chat_message_id FROM phone_browser_deliveries_v1 WHERE owner_user_id=? AND event_key=?'.vp3_phone_workspace_lock_v1($pdo));$q->execute([$call['owner_user_id'],'call:legacy-'.$legacy]);if($q->fetchColumn())return null;}
            $q=$pdo->prepare("SELECT id FROM phone_browser_calls_v1 WHERE owner_user_id=? AND state IN ('dialing','ringing','answered') LIMIT 1");$q->execute([$call['owner_user_id']]);if($q->fetchColumn()){$reason='another_call';return null;}
            $q=$pdo->prepare('SELECT conversation_id,chat_agent_id FROM phone_browser_presence_v1 WHERE owner_user_id=? AND last_epoch>?');$q->execute([$call['owner_user_id'],time()-300]);$presence=$q->fetch(PDO::FETCH_ASSOC)?:[];
            if(!$presence){$reason='no_registered_browser';return null;}
            if((int)$presence['chat_agent_id']!==0&&(int)$presence['chat_agent_id']!==$call['agent_id']){$reason='different_agent';return null;}
            $public=bin2hex(random_bytes(16));$now=time();
            $pdo->prepare("INSERT INTO phone_browser_calls_v1(public_id,owner_user_id,agent_id,phone_number_id,phone_e164,peer_e164,direction,voice_call_id,provider_call_sid,conversation_id,chat_agent_id,state,recording_enabled,created_epoch,updated_epoch) VALUES (?,?,?,?,?,?,'inbound',?,?,?,?,'ringing',?,?,?)")
                ->execute([$public,$call['owner_user_id'],$call['agent_id'],$call['phone_number_id'],$call['phone_e164'],(string)$route['caller_e164'],$legacy?:null,$sid,$presence['conversation_id']??null,(int)($presence['chat_agent_id']??0),(int)$policy['recording_enabled'],$now,$now]);
            $q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE public_id=?');$q->execute([$public]);$call=$q->fetch(PDO::FETCH_ASSOC);
            $delivery=vp3_phone_workspace_delivery_v1($pdo,$call,'call','call:'.$public,'Incoming phone call.');$pdo->prepare('UPDATE phone_browser_calls_v1 SET conversation_id=?,chat_agent_id=?,chat_message_id=? WHERE id=?')->execute([$delivery['conversation_id'],$delivery['chat_agent_id'],$delivery['message_id'],$call['id']]);
        }
        if((int)$call['owner_user_id']!==(int)$route['owner_user_id']||!vp3_phone_browser_live_call_v1($pdo,$call)||!in_array($call['state'],['ringing'],true))return vp3_phone_browser_hangup_v1();
        $status=vp3_phone_browser_xml_v1(vp3_phone_browser_callback_v1('status',['call'=>$call['public_id']]));
        $notice=$call['recording_enabled']?'<Say>This call will be recorded. Please hang up now if you do not want to be recorded.</Say><Pause length="2"/>':'';
        $profileLimit='';
        if(vp3_profile_browser_phone_find_sid_v1($pdo,$sid)!==null){
            require_once __DIR__.'/profile-browser-phone-v1.php';
            vp3_profile_browser_phone_route_v1($pdo,$sid,'owner');
            $profileLimit=' timeLimit="600"';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><Response>'.$notice.'<Dial'.vp3_phone_browser_dial_attrs_v1($call,(bool)$call['recording_enabled'],vp3_phone_voicemail_ring_timeout_v1($pdo,$call)).$profileLimit.'><Client statusCallback="'.$status.'" statusCallbackMethod="POST" statusCallbackEvent="initiated ringing answered completed"><Identity>'.vp3_phone_browser_identity_v1((int)$call['owner_user_id'],(int)$call['chat_agent_id']).'</Identity><Parameter name="phone_call_id" value="'.$call['public_id'].'"/></Client></Dial></Response>';
    });
}
function vp3_phone_browser_call_by_sid_v1(PDO $pdo,string $sid): ?array
{
    if(!preg_match('/^CA[a-f0-9]{32}$/iD',$sid))return null;$q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE provider_call_sid=? OR provider_child_sid=? LIMIT 1');$q->execute([$sid,$sid]);$r=$q->fetch(PDO::FETCH_ASSOC);return $r?:null;
}
/** A Number whisper executes on the signed, bound called leg before bridging. */
function vp3_phone_browser_disclosure_v1(PDO $pdo,array $form,string $signature,string $public): string
{
    if(!preg_match('/^[a-f0-9]{32}$/D',$public))throw new InvalidArgumentException('Invalid phone call.');
    $sid=vp3_phone_browser_verify_v1($form,$signature,vp3_phone_browser_callback_v1('disclosure',['call'=>$public]));
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$form,$public,$sid){
        $q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE public_id=?'.vp3_phone_workspace_lock_v1($pdo));$q->execute([$public]);$call=$q->fetch(PDO::FETCH_ASSOC);
        if(!$call||$call['direction']!=='outbound'||!(int)$call['recording_enabled']||!in_array($call['state'],['dialing','ringing','answered'],true)||!vp3_phone_browser_live_call_v1($pdo,$call)||$sid===$call['provider_call_sid'])return vp3_phone_browser_hangup_v1();
        if(!hash_equals((string)$call['peer_e164'],(string)($form['To']??''))||!hash_equals((string)$call['phone_e164'],(string)($form['From']??'')))return vp3_phone_browser_hangup_v1();
        if($call['provider_child_sid']){if(!hash_equals((string)$call['provider_child_sid'],$sid))return vp3_phone_browser_hangup_v1();}
        elseif(!isset($form['ParentCallSid'])||!hash_equals((string)$call['provider_call_sid'],(string)$form['ParentCallSid']))return vp3_phone_browser_hangup_v1();
        if(!$call['provider_child_sid'])$pdo->prepare('UPDATE phone_browser_calls_v1 SET provider_child_sid=? WHERE id=? AND provider_child_sid IS NULL')->execute([$sid,$call['id']]);
        return '<?xml version="1.0" encoding="UTF-8"?><Response><Say>This call will be recorded. Please hang up now if you do not want to be recorded.</Say><Pause length="2"/></Response>';
    });
}
function vp3_phone_browser_fallback_v1(PDO $pdo,array $call,bool $complete): bool
{
    if(!$complete||$call['direction']!=='inbound'||!in_array($call['state'],['busy','no_answer','failed','canceled'],true)||(int)$call['fallback_claimed_epoch']||!vp3_phone_browser_live_call_v1($pdo,$call))return false;
    $q=$pdo->prepare('UPDATE phone_browser_calls_v1 SET fallback_claimed_epoch=? WHERE id=? AND fallback_claimed_epoch=0');$q->execute([time(),$call['id']]);return $q->rowCount()===1;
}
/** Recording metadata is accepted only for the immutable, opted-in parent call. */
function vp3_phone_browser_recording_v1(PDO $pdo,array $form,string $signature,string $public): array
{
    if(!preg_match('/^[a-f0-9]{32}$/D',$public))throw new InvalidArgumentException('Invalid phone call.');
    $sid=vp3_phone_browser_verify_v1($form,$signature,vp3_phone_browser_callback_v1('recording',['call'=>$public]));
    $q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE public_id=?');$q->execute([$public]);$call=$q->fetch(PDO::FETCH_ASSOC);
    if(!$call||($call['transport_kind']??'')==='managed_callback'||!(int)$call['recording_enabled']||!hash_equals((string)$call['provider_call_sid'],$sid))return ['accepted'=>false];
    require_once __DIR__.'/phone-recording-pipeline-v1.php';
    if(!vp3_phone_recording_schema_ready_v1($pdo))throw new RuntimeException('Phone recording storage needs an upgrade.');
    return vp3_phone_recording_receive_v1($pdo,$call,$form);
}
function vp3_phone_browser_status_v1(PDO $pdo,array $form,string $signature,string $public,bool $complete=false): array
{
    if(!preg_match('/^[a-f0-9]{32}$/D',$public))throw new InvalidArgumentException('Invalid phone call.');
    $sid=vp3_phone_browser_verify_v1($form,$signature,vp3_phone_browser_callback_v1($complete?'complete':'status',['call'=>$public]));
    // Profile parent settlement locks the owner before its child receipt. Use
    // the same order for signed child events without changing legacy callbacks.
    $lookup=$pdo->prepare('SELECT provider_call_sid FROM phone_browser_calls_v1 WHERE public_id=?');$lookup->execute([$public]);$parent=$lookup->fetchColumn();$lookup->closeCursor();
    $profile=is_string($parent)&&$parent!==''?vp3_profile_browser_phone_find_sid_v1($pdo,$parent):null;
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$form,$public,$sid,$complete,$profile){
        if($profile!==null){
            require_once __DIR__.'/profile-browser-phone-v1.php';
            vp3_profile_browser_phone_owner_lock_v1($pdo,(int)$profile['owner_user_id'],false);
        }
        $q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE public_id=?'.vp3_phone_workspace_lock_v1($pdo));$q->execute([$public]);$call=$q->fetch(PDO::FETCH_ASSOC);if(!$call||!$call['provider_call_sid']||($call['transport_kind']??'browser')==='managed_callback')return ['accepted'=>false];
        if($complete){if(!hash_equals((string)$call['provider_call_sid'],$sid))return ['accepted'=>false];$child=(string)($form['DialCallSid']??'');$state=(string)($form['DialCallStatus']??'');}
        else{$child=$sid;$state=(string)($form['CallStatus']??'');if($sid===$call['provider_call_sid'])return ['accepted'=>false];if(isset($form['ParentCallSid'])&&!hash_equals((string)$call['provider_call_sid'],(string)$form['ParentCallSid']))return ['accepted'=>false];if(!$call['provider_child_sid']&&(!isset($form['ParentCallSid'])||!hash_equals((string)$call['provider_call_sid'],(string)$form['ParentCallSid'])))return ['accepted'=>false];}
        if($child!==''&&!preg_match('/^CA[a-f0-9]{32}$/iD',$child))return ['accepted'=>false];
        if($call['provider_child_sid']&&$child!==''&&!hash_equals((string)$call['provider_child_sid'],$child))return ['accepted'=>false];
        $mapped=['queued'=>'dialing','initiated'=>'dialing','ringing'=>'ringing','in-progress'=>'answered','answered'=>'answered','completed'=>'completed','busy'=>'busy','no-answer'=>'no_answer','failed'=>'failed','canceled'=>'canceled'];if(!isset($mapped[$state]))return ['accepted'=>false];$next=$mapped[$state];
        $rank=['prepared'=>0,'dialing'=>1,'ringing'=>2,'answered'=>3,'completed'=>4,'busy'=>4,'no_answer'=>4,'failed'=>4,'canceled'=>4];$current=(string)$call['state'];
        $duration=(string)($form['DialCallDuration']??$form['CallDuration']??'0');
        $evidence=['answered'=>$next==='answered'||($next==='completed'&&preg_match('/^[0-9]{1,6}$/D',$duration)&&(int)$duration>0)];
        if(($rank[$current]??4)===4||$rank[$next]<($rank[$current]??0)){
            if(vp3_profile_browser_phone_find_sid_v1($pdo,(string)$call['provider_call_sid'])!==null){
                require_once __DIR__.'/profile-browser-phone-v1.php';
                vp3_profile_browser_phone_sync_v1($pdo,$call,$complete,$evidence);
            }
            return ['accepted'=>true,'duplicate'=>true,'call'=>$call,'fallback'=>vp3_phone_browser_fallback_v1($pdo,$call,$complete)];
        }
        if(!preg_match('/^[0-9]{1,6}$/D',$duration))return ['accepted'=>false];$now=time();
        $pdo->prepare('UPDATE phone_browser_calls_v1 SET state=?,provider_child_sid=COALESCE(provider_child_sid,?),duration_seconds=?,ended_epoch=?,updated_epoch=? WHERE id=?')->execute([$next,$child?:null,max((int)$call['duration_seconds'],min((int)$duration,86400)),$rank[$next]===4?$now:null,$now,$call['id']]);
        if($rank[$next]===4)vp3_operator_voice_close_call_v1($pdo,(int)$call['id']);
        $call['state']=$next;$call['provider_child_sid']=$call['provider_child_sid']?:$child;$call['duration_seconds']=max((int)$call['duration_seconds'],min((int)$duration,86400));
        if(vp3_profile_browser_phone_find_sid_v1($pdo,(string)$call['provider_call_sid'])!==null){
            require_once __DIR__.'/profile-browser-phone-v1.php';
            vp3_profile_browser_phone_sync_v1($pdo,$call,$complete,$evidence);
        }
        return ['accepted'=>true,'state'=>$next,'call'=>$call,'fallback'=>vp3_phone_browser_fallback_v1($pdo,$call,$complete)];
    });
}
