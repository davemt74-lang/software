<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

/** PS5E.2: private, one-use callback transport. No recording or autonomous execution. */
require_once __DIR__.'/phone-managed-callback-v1.php';
require_once __DIR__.'/phone-managed-transport-schema-v1.php';
require_once __DIR__.'/phone-managed-pilot-v1.php';

function vp3_managed_transport_enabled_v1():bool
{
    return (string)setting('phone_managed_callback_enabled_v1','0')==='1';
}
function vp3_managed_transport_nonce_v1(mixed $nonce):string
{
    vp3_managed_callback_need_v1(is_string($nonce)&&preg_match('/\A[a-f0-9]{64}\z/D',$nonce)===1,422);
    return $nonce;
}
function vp3_managed_media_nonce_v1(string $public):string
{
    vp3_managed_callback_id_v1($public);
    return hash_hmac('sha256','vp3-managed-media-callback-v1:'.$public,vp3_phone_twilio_credentials_v100()[1]);
}
function vp3_managed_transport_url_v1(string $event,string $public,string $nonce):string
{
    vp3_managed_callback_need_v1(in_array($event,['answer','confirm','parent','child','complete','record_notice','record_consent','recording','native_pin'],true),422);
    vp3_managed_callback_id_v1($public);vp3_managed_transport_nonce_v1($nonce);
    $base=vp3_phone_voice_validate_url_v130(vp3_phone_voice_callback_url_v130());
    return substr($base,0,-strlen('phone-voice-inbound-v130.php')).'phone-managed-callback-event-v1.php?'.
        http_build_query(['event'=>$event,'call'=>$public,'nonce'=>$nonce],'','&',PHP_QUERY_RFC3986);
}
function vp3_managed_transport_row_v1(PDO $pdo,string $public,bool $lock=false):?array
{
    $q=$pdo->prepare('SELECT * FROM phone_managed_callback_dispatch_v1 WHERE call_public_id=?'.($lock?vp3_phone_workspace_lock_v1($pdo):''));
    $q->execute([$public]);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $r?:null;
}
function vp3_managed_transport_call_v1(PDO $pdo,string $public,bool $lock=false):?array
{
    $q=$pdo->prepare("SELECT * FROM phone_browser_calls_v1 WHERE public_id=? AND transport_kind='managed_callback'".($lock?vp3_phone_workspace_lock_v1($pdo):''));
    $q->execute([$public]);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $r?:null;
}
/** Status may still be reconciled after loss of active owner authorization. */
function vp3_managed_transport_owner_lock_v1(PDO $pdo,int $owner):bool
{
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->prepare('UPDATE users SET id=id WHERE id=?')->execute([$owner]);
    $q=$pdo->prepare('SELECT id FROM users WHERE id=?'.vp3_phone_workspace_lock_v1($pdo));$q->execute([$owner]);$id=$q->fetchColumn();$q->closeCursor();return $id!==false;
}
function vp3_managed_transport_view_v1(array $transport,array $call):array
{
    return ['dispatch_state'=>(string)$transport['state'],'parent_state'=>(string)$transport['parent_state'],
        'call'=>vp3_phone_workspace_call_projection_v1($call)];
}
function vp3_managed_transport_sid_v1(mixed $sid):bool
{
    return is_string($sid)&&preg_match('/^CA[a-f0-9]{32}$/iD',$sid)===1;
}
function vp3_managed_transport_bind_parent_v1(PDO $pdo,array &$call,string $sid):bool
{
    if(!vp3_managed_transport_sid_v1($sid))return false;
    if($call['provider_call_sid'])return hash_equals((string)$call['provider_call_sid'],$sid);
    $q=$pdo->prepare('SELECT id FROM phone_browser_calls_v1 WHERE provider_call_sid=? OR provider_child_sid=?');$q->execute([$sid,$sid]);$used=$q->fetchColumn();$q->closeCursor();
    if($used!==false)return false;
    $q=$pdo->prepare('UPDATE phone_browser_calls_v1 SET provider_call_sid=? WHERE id=? AND provider_call_sid IS NULL');$q->execute([$sid,$call['id']]);
    if($q->rowCount()!==1)return false;
    $call['provider_call_sid']=$sid;return true;
}
function vp3_managed_transport_terminal_v1(PDO $pdo,array $transport,array $call,string $state,int $duration,int $now,?string $child=null,string $dispatchState='terminal'):void
{
    $terminal=['completed','busy','no_answer','failed','canceled'];
    if(!in_array($call['state'],$terminal,true)){
        $pdo->prepare('UPDATE phone_browser_calls_v1 SET state=?,provider_child_sid=COALESCE(provider_child_sid,?),duration_seconds=?,ended_epoch=?,updated_epoch=? WHERE id=?')
            ->execute([$state,$child,max((int)$call['duration_seconds'],max(0,min(240,$duration))),$now,$now,$call['id']]);
    }
    $pdo->prepare('UPDATE phone_managed_callback_dispatch_v1 SET state=?,updated_epoch=? WHERE call_public_id=?')->execute([$dispatchState,$now,$call['public_id']]);
    $pdo->prepare("UPDATE phone_managed_callback_requests_v1 SET state='finished',holder_e164='',destination_e164='',updated_epoch=? WHERE public_id=?")->execute([$now,$transport['request_id']]);
}
/** Reserve once before any network access; canonical busy and profile budgets are shared. */
function vp3_managed_transport_reserve_v1(PDO $pdo,array $user,string $id,bool $confirmed,int $now,bool $pilotRequired=false):array
{
    vp3_managed_callback_need_v1(vp3_managed_transport_enabled_v1()&&vp3_managed_transport_ready_v1($pdo),503);
    vp3_managed_callback_need_v1($confirmed&&(!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null),403);
    vp3_managed_callback_id_v1($id);
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$user,$id,$now,$pilotRequired){
        $owner=(int)($user['id']??0);$live=vp3_managed_callback_owner_lock_v1($pdo,$owner);
        $row=vp3_managed_callback_row_v1($pdo,$id,true);
        vp3_managed_callback_need_v1(is_array($row)&&(int)$row['owner_user_id']===$owner,404);
        require_once __DIR__.'/phone-native-dialthrough-v1.php';
        vp3_managed_callback_need_v1(!vp3_native_dialthrough_row_v1($pdo,$id,true));
        $q=$pdo->prepare('SELECT call_public_id FROM phone_managed_callback_dispatch_v1 WHERE request_id=?');$q->execute([$id]);$already=$q->fetchColumn();$q->closeCursor();
        if($already!==false){
            $transport=vp3_managed_transport_row_v1($pdo,(string)$already,true);$call=vp3_managed_transport_call_v1($pdo,(string)$already,true);
            vp3_managed_callback_need_v1(is_array($transport)&&is_array($call));
            return ['existing'=>vp3_managed_transport_view_v1($transport,$call)];
        }
        vp3_managed_callback_need_v1($row['state']==='approved'&&(int)$row['approved_epoch']>0&&(int)$row['expires_epoch']>$now);
        $authority=vp3_managed_callback_current_v1($pdo,$row,$live,$now);
        require_once __DIR__.'/phone-managed-recording-v1.php';
        if($pilotRequired)vp3_managed_callback_need_v1(vp3_managed_pilot_review_v1($pdo,$row,$now)['available'],403);
        vp3_managed_recording_parent_v1($pdo,$row,$authority,false,$now);
        vp3_connected_phone_prepare_v1($pdo,$authority['scope'],['device_id'=>$row['device_id'],'profile_id'=>$row['profile_id'],
            'profile_revision'=>(int)$row['profile_revision'],'phone_revision'=>(int)$row['phone_revision'],'confirmed'=>true],$now);
        $pdo->prepare("UPDATE phone_browser_calls_v1 SET state='canceled',ended_epoch=?,updated_epoch=? WHERE owner_user_id=? AND state='prepared' AND intent_expires_epoch<?")->execute([$now,$now,$owner,$now]);
        $q=$pdo->prepare("SELECT COUNT(*) FROM phone_browser_calls_v1 WHERE owner_user_id=? AND state IN ('prepared','dialing','ringing','answered')");$q->execute([$owner]);$busy=(int)$q->fetchColumn();$q->closeCursor();
        vp3_managed_callback_need_v1($busy===0);
        $q=$pdo->prepare('SELECT COUNT(*) FROM phone_browser_calls_v1 WHERE owner_user_id=? AND created_epoch>?');$q->execute([$owner,$now-600]);$recent=(int)$q->fetchColumn();$q->closeCursor();
        vp3_managed_callback_need_v1($recent<10,429);
        $public=bin2hex(random_bytes(16));$nonce=vp3_managed_media_nonce_v1($public);
        $plan=vp3_managed_callback_plan_v1($row,$now,vp3_managed_transport_url_v1('answer',$public,$nonce),vp3_managed_transport_url_v1('parent',$public,$nonce));
        $pdo->prepare("INSERT INTO phone_browser_calls_v1(public_id,owner_user_id,agent_id,phone_number_id,phone_e164,peer_e164,direction,
            conversation_id,chat_agent_id,state,recording_enabled,created_epoch,updated_epoch,paired_device_id,paired_profile_id,paired_profile_revision,paired_phone_revision,transport_kind)
            VALUES (?,?,?,?,?,?,'outbound',?,?,'dialing',0,?,?,?,?,?,?,'managed_callback')")
            ->execute([$public,$owner,(int)$row['line_agent_id'],(int)$row['number_id'],$row['line_e164'],$row['holder_e164'],
                (int)$row['conversation_id'],(int)$row['chat_agent_id'],$now,$now,$row['device_id'],$row['profile_id'],(int)$row['profile_revision'],(int)$row['phone_revision']]);
        $pdo->prepare("INSERT INTO phone_managed_callback_dispatch_v1(request_id,call_public_id,owner_user_id,callback_nonce_hash,state,created_epoch,updated_epoch,pilot_required)
            VALUES (?,?,?,?,'dispatching',?,?,?)")->execute([$id,$public,$owner,hash('sha256',$nonce),$now,$now,(int)$pilotRequired]);
        $q=$pdo->prepare("UPDATE phone_managed_callback_requests_v1 SET state='dispatch_claimed',updated_epoch=? WHERE public_id=? AND state='approved'");$q->execute([$now,$id]);vp3_managed_callback_need_v1($q->rowCount()===1);
        $call=vp3_managed_transport_call_v1($pdo,$public,true);
        $delivery=vp3_phone_workspace_delivery_v1($pdo,$call,'call','call:'.$public,'Twilio managed callback requested. Carrier connection is not yet confirmed.');
        $pdo->prepare('UPDATE phone_browser_calls_v1 SET chat_message_id=? WHERE id=?')->execute([$delivery['message_id'],$call['id']]);
        // The carrier command is internal and is never part of an HTTP/UI response.
        return ['public'=>$public,'plan'=>$plan];
    });
}
/** Explicit private dispatch only. No worker or web endpoint invokes this function in PS5E.2. */
function vp3_managed_transport_dispatch_v1(PDO $pdo,array $user,string $id,bool $confirmed,?callable $carrier=null,?int $clock=null,bool $pilotRequired=false):array
{
    vp3_managed_callback_need_v1(!vp3_phone_transaction_active_v1($pdo),409);
    $now=$clock??time();
    $pilotRequired=$pilotRequired||$carrier===null;
    $reserved=vp3_managed_transport_reserve_v1($pdo,$user,$id,$confirmed,$now,$pilotRequired);
    if(isset($reserved['existing']))return $reserved['existing'];
    $public=$reserved['public'];$owner=(int)$user['id'];
    // Recheck immediately before sending. A later revocation still fences the signed answer/confirm.
    $allowed=vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$owner,$public,$id,$clock){
        $now=$clock??time();
        if(!vp3_managed_transport_owner_lock_v1($pdo,$owner))return false;
        $row=vp3_managed_callback_row_v1($pdo,$id,true);$transport=vp3_managed_transport_row_v1($pdo,$public,true);$call=vp3_managed_transport_call_v1($pdo,$public,true);
        if(!$row||!$transport||!$call)return false;
        try{
            $live=vp3_managed_callback_owner_lock_v1($pdo,$owner);
            vp3_managed_callback_need_v1(vp3_managed_transport_enabled_v1()&&$row['state']==='dispatch_claimed'&&(int)$row['expires_epoch']>$now&&$transport['state']==='dispatching'&&!$call['provider_call_sid']);
            $authority=vp3_managed_callback_current_v1($pdo,$row,$live,$now);
            if((int)$transport['pilot_required']===1)vp3_managed_callback_need_v1(vp3_managed_pilot_allowed_v1($row,$now)&&!vp3_managed_recording_row_v1($pdo,$row['public_id'],true),403);
            vp3_managed_recording_parent_v1($pdo,$row,$authority,false,$now);return true;
        }catch(VP3PhoneWorkspaceException){vp3_managed_transport_terminal_v1($pdo,$transport,$call,'canceled',0,$now,null,'not_sent');return false;}
    });
    if(!$allowed){
        return vp3_managed_transport_view_v1(vp3_managed_transport_row_v1($pdo,$public),vp3_managed_transport_call_v1($pdo,$public));
    }
    $receipt=null;
    try{
        // No DB transaction is open across carrier I/O. Tests inject a synthetic carrier.
        if($carrier===null)$carrier=static function(array $fields):array{
            $account=vp3_phone_twilio_credentials_v100()[0];
            return vp3_phone_twilio_http_v100('POST','/2010-04-01/Accounts/'.$account.'/Calls.json',$fields);
        };
        $receipt=$carrier($reserved['plan']);
    }catch(Throwable){ /* An uncertain carrier POST must never be retried automatically. */ }
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$public,$owner,$receipt,$reserved,$clock){
        $now=$clock??time();vp3_managed_callback_need_v1(vp3_managed_transport_owner_lock_v1($pdo,$owner),403);
        $t=vp3_managed_transport_row_v1($pdo,$public,true);$call=vp3_managed_transport_call_v1($pdo,$public,true);
        vp3_managed_callback_need_v1(is_array($t)&&is_array($call));
        $valid=is_array($receipt)&&vp3_managed_transport_sid_v1($receipt['sid']??null)
            &&is_string($receipt['account_sid']??null)&&is_string($receipt['from']??null)&&is_string($receipt['to']??null)
            &&hash_equals((string)vp3_phone_twilio_state_v100()['account_sid'],$receipt['account_sid'])
            &&hash_equals($reserved['plan']['From'],$receipt['from'])
            &&hash_equals($reserved['plan']['To'],$receipt['to']);
        $bound=$valid&&vp3_managed_transport_bind_parent_v1($pdo,$call,$receipt['sid']);
        if($t['state']==='dispatching'){
            $state=$bound?'submitted':'uncertain';
            $pdo->prepare('UPDATE phone_managed_callback_dispatch_v1 SET state=?,updated_epoch=? WHERE call_public_id=? AND state=?')->execute([$state,$now,$public,'dispatching']);
            $t['state']=$state;
        }
        return vp3_managed_transport_view_v1($t,$call);
    });
}
/** Signed callback endpoint. The nonce and exact carrier identities bind the two legs. */
function vp3_managed_transport_event_v1(PDO $pdo,string $event,string $public,string $nonce,array $form,string $signature,int $now):array
{
    $url=vp3_managed_transport_url_v1($event,$public,$nonce);
    $sid=vp3_phone_browser_verify_v1($form,$signature,$url);
    if($event==='native_pin'){
        require_once __DIR__.'/phone-native-dialthrough-v1.php';
        return vp3_native_dialthrough_confirm_v1($pdo,$public,$nonce,$sid,$form,$now);
    }
    $hangup=['accepted'=>false,'xml'=>vp3_phone_browser_hangup_v1()];
    $hint=vp3_managed_transport_row_v1($pdo,$public);
    if(!$hint||!hash_equals((string)$hint['callback_nonce_hash'],hash('sha256',$nonce)))return $hangup;
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$event,$public,$nonce,$form,$sid,$now,$hint,$hangup){
        $owner=(int)$hint['owner_user_id'];if(!vp3_managed_transport_owner_lock_v1($pdo,$owner))return $hangup;
        $t=vp3_managed_transport_row_v1($pdo,$public,true);$call=vp3_managed_transport_call_v1($pdo,$public,true);
        if(!$t||!$call||(int)$call['owner_user_id']!==$owner||$t['state']==='not_sent'||!hash_equals($t['callback_nonce_hash'],hash('sha256',$nonce)))return $hangup;
        $row=vp3_managed_callback_row_v1($pdo,$t['request_id'],true);
        if(!$row||(int)$row['owner_user_id']!==$owner)return $hangup;
        $terminal=['completed','busy','no_answer','failed','canceled'];
        $mapping=['queued'=>'dialing','initiated'=>'dialing','ringing'=>'ringing','in-progress'=>'answered','answered'=>'answered',
            'completed'=>'completed','busy'=>'busy','no-answer'=>'no_answer','failed'=>'failed','canceled'=>'canceled'];
        $finished=in_array($call['state'],$terminal,true);
        $native=($call['managed_parent_kind']??'callback')==='native';
        if($native&&in_array($event,['answer','confirm','record_notice','record_consent','recording'],true))return $hangup;
        require_once __DIR__.'/phone-managed-recording-v1.php';
        if(in_array($event,['record_notice','record_consent'],true))return vp3_managed_recording_child_v1($pdo,$event,$row,$t,$call,$sid,$nonce,$form,$now);
        if($event==='recording')return vp3_managed_recording_event_v1($pdo,$row,$t,$call,$sid,$form,$now);
        if($event!=='child'){
            if(!hash_equals((string)$call[$native?'peer_e164':'phone_e164'],(string)($form['From']??''))||!hash_equals((string)$call[$native?'phone_e164':'peer_e164'],(string)($form['To']??'')))return $hangup;
            if(!vp3_managed_transport_bind_parent_v1($pdo,$call,$sid))return $hangup;
        }
        if($event==='answer'||$event==='confirm'){
            if($finished||!vp3_managed_transport_enabled_v1()||$row['state']!=='dispatch_claimed'||(int)$row['expires_epoch']<=$now||$t['parent_state']!=='')return $hangup;
            try{
                $live=vp3_managed_callback_owner_lock_v1($pdo,$owner);$authority=vp3_managed_callback_current_v1($pdo,$row,$live,$now);
                if((int)$t['pilot_required']===1)vp3_managed_callback_need_v1(vp3_managed_pilot_allowed_v1($row,$now)&&!vp3_managed_recording_row_v1($pdo,$row['public_id'],true),403);
                $recordPolicy=vp3_managed_recording_parent_v1($pdo,$row,$authority,false,$now);
            }catch(VP3PhoneWorkspaceException){return $hangup;}
            if($event==='answer'){
                if(!in_array($t['state'],['dispatching','submitted','uncertain','awaiting_confirmation'],true)||(int)$t['confirmation_epoch']>0)return $hangup;
                $pdo->prepare("UPDATE phone_managed_callback_dispatch_v1 SET state='awaiting_confirmation',updated_epoch=? WHERE call_public_id=?")->execute([$now,$public]);
                $action=vp3_phone_browser_xml_v1(vp3_managed_transport_url_v1('confirm',$public,$nonce));
                $notice=$recordPolicy?vp3_managed_recording_notice_v1($recordPolicy):'VP3 managed callback. Press 1 to connect the destination you approved in the app. Otherwise hang up. This call is not recorded by VP3.';
                $xml='<?xml version="1.0" encoding="UTF-8"?><Response><Gather input="dtmf" numDigits="1" timeout="8" actionOnEmptyResult="true" action="'.$action.'" method="POST"><Say>'.vp3_phone_browser_xml_v1($notice).'</Say></Gather><Hangup/></Response>';
                return ['accepted'=>true,'xml'=>$xml];
            }
            if($t['state']!=='awaiting_confirmation'||(int)$t['confirmation_epoch']>0)return $hangup;
            if(($form['Digits']??null)!=='1'){
                $pdo->prepare("UPDATE phone_managed_callback_dispatch_v1 SET state='holder_declined',updated_epoch=? WHERE call_public_id=? AND state='awaiting_confirmation'")->execute([$now,$public]);
                return $hangup;
            }
            if($recordPolicy)vp3_managed_recording_parent_v1($pdo,$row,$authority,true,$now);
            $q=$pdo->prepare("UPDATE phone_managed_callback_dispatch_v1 SET state='bridging',confirmation_epoch=?,updated_epoch=? WHERE call_public_id=? AND state='awaiting_confirmation' AND confirmation_epoch=0");
            $q->execute([$now,$now,$public]);if($q->rowCount()!==1)return $hangup;
            $complete=vp3_phone_browser_xml_v1(vp3_managed_transport_url_v1('complete',$public,$nonce));$status=vp3_phone_browser_xml_v1(vp3_managed_transport_url_v1('child',$public,$nonce));
            $line=vp3_phone_browser_xml_v1($row['line_e164']);$dest=vp3_phone_browser_xml_v1(vp3_managed_callback_number_v1($row['destination_e164']));
            $screen=$recordPolicy?' url="'.vp3_phone_browser_xml_v1(vp3_managed_transport_url_v1('record_notice',$public,$nonce)).'" method="POST"':'';
            $xml='<?xml version="1.0" encoding="UTF-8"?><Response><Dial callerId="'.$line.'" answerOnBridge="true" timeout="20" timeLimit="180" action="'.$complete.'" method="POST"><Number'.$screen.' statusCallback="'.$status.'" statusCallbackMethod="POST" statusCallbackEvent="initiated ringing answered completed">'.$dest.'</Number></Dial><Hangup/></Response>';
            return ['accepted'=>true,'xml'=>$xml];
        }
        if($event==='parent'){
            $state=$mapping[(string)($form['CallStatus']??'')]??null;
            if(!in_array($state,$terminal,true))return $hangup;
            if($t['parent_state']==='')$pdo->prepare('UPDATE phone_managed_callback_dispatch_v1 SET parent_state=?,updated_epoch=? WHERE call_public_id=?')->execute([$state,$now,$public]);
            // An answered parent alone is not a completed destination call.
            if(!$finished&&(int)$t['confirmation_epoch']===0)vp3_managed_transport_terminal_v1($pdo,$t,$call,$state==='completed'?'canceled':$state,0,$now);
            // After confirmation, the child/complete callback is authoritative for the destination.
            return ['accepted'=>true,'xml'=>vp3_phone_browser_hangup_v1()];
        }
        if((int)$t['confirmation_epoch']===0||!$call['provider_call_sid'])return $hangup;
        if($event==='child'){
            if(!hash_equals((string)$call['provider_call_sid'],(string)($form['ParentCallSid']??''))||$sid===$call['provider_call_sid'])return $hangup;
            $child=$sid;$raw=(string)($form['CallStatus']??'');$duration=(string)($form['CallDuration']??'0');
            if(!$finished&&(!hash_equals((string)$call['phone_e164'],(string)($form['From']??''))||!hash_equals((string)$row['destination_e164'],(string)($form['To']??''))))return $hangup;
        }else{
            $child=(string)($form['DialCallSid']??'');$raw=(string)($form['DialCallStatus']??'');$duration=(string)($form['DialCallDuration']??'0');
        }
        if(($child!==''&&!vp3_managed_transport_sid_v1($child))||$child===$call['provider_call_sid']||($call['provider_child_sid']&&$child!==''&&!hash_equals((string)$call['provider_child_sid'],$child)))return $hangup;
        $state=$mapping[$raw]??null;if(!$state||!preg_match('/^\d{1,6}$/D',$duration))return $hangup;
        if($event==='complete'&&!in_array($state,$terminal,true))return $hangup;
        if($finished)return ['accepted'=>true,'duplicate'=>true,'xml'=>vp3_phone_browser_hangup_v1()];
        if($child!==''){
            $q=$pdo->prepare('SELECT id FROM phone_browser_calls_v1 WHERE (provider_call_sid=? OR provider_child_sid=?) AND id<>?');$q->execute([$child,$child,$call['id']]);$other=$q->fetchColumn();$q->closeCursor();if($other!==false)return $hangup;
        }
        if(in_array($state,$terminal,true)){
            vp3_managed_transport_terminal_v1($pdo,$t,$call,$state,(int)$duration,$now,$child?:null);
        }else{
            if($child==='')return $hangup;
            $rank=['dialing'=>1,'ringing'=>2,'answered'=>3];
            if(($rank[$state]??0)>=($rank[$call['state']]??0))$pdo->prepare('UPDATE phone_browser_calls_v1 SET state=?,provider_child_sid=COALESCE(provider_child_sid,?),updated_epoch=? WHERE id=?')->execute([$state,$child,$now,$call['id']]);
        }
        return ['accepted'=>true,'xml'=>vp3_phone_browser_hangup_v1()];
    });
}
