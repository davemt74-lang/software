<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

require_once __DIR__.'/phone-managed-transport-v1.php';
require_once __DIR__.'/phone-native-dialthrough-schema-v1.php';
require_once __DIR__.'/phone-managed-recording-v1.php';

/** A separate default-off pilot flag, in addition to the managed-call flag. */
function vp3_native_dialthrough_enabled_v1():bool
{
    return vp3_managed_transport_enabled_v1()&&(string)setting('phone_native_dialthrough_enabled_v1','0')==='1';
}
function vp3_native_dialthrough_row_v1(PDO $pdo,string $id,bool $lock=false):?array
{
    if(!vp3_native_dialthrough_ready_v1($pdo))return null;
    $q=$pdo->prepare('SELECT * FROM phone_native_dialthrough_v1 WHERE request_id=?'.($lock?vp3_phone_workspace_lock_v1($pdo):''));
    $q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $r?:null;
}
function vp3_native_dialthrough_secret_v1():string
{
    $secret=vp3_phone_twilio_credentials_v100()[1];
    vp3_managed_callback_need_v1(is_string($secret)&&$secret!=='',503);return $secret;
}
function vp3_native_dialthrough_digest_v1(string $id,string $pin):string
{
    return hash_hmac('sha256','vp3-native-pin-v1:'.$id.':'.$pin,vp3_native_dialthrough_secret_v1());
}
function vp3_native_dialthrough_nonce_v1(string $id,string $sid):string
{
    return hash_hmac('sha256','vp3-native-challenge-v1:'.$id.':'.$sid,vp3_native_dialthrough_secret_v1());
}
function vp3_native_dialthrough_view_v1(array $row,array $native,int $now,bool $holder):array
{
    $available=vp3_native_dialthrough_enabled_v1()&&vp3_managed_pilot_allowed_v1($row,$now);
    $active=$row['state']==='approved'&&(int)$row['expires_epoch']>$now;
    return ['state'=>(string)$native['state'],'enabled'=>$available,
        'can_issue_pin'=>$holder&&$active&&$available&&$native['state']==='review'&&(int)$native['issued_epoch']===0,
        'expires_epoch'=>(int)$row['expires_epoch']];
}
/** Authenticated holder only. Code returned once to this response, not polling. */
function vp3_native_dialthrough_issue_v1(PDO $pdo,array $device,array $input,int $now):array
{
    vp3_managed_callback_need_v1(vp3_phone_transaction_active_v1($pdo)&&vp3_native_dialthrough_ready_v1($pdo)&&vp3_native_dialthrough_enabled_v1(),503);
    vp3_managed_callback_need_v1(!array_diff(array_keys($input),['device_id','generation','managed_request_id','confirmed'])
        &&($input['confirmed']??null)===true,422);
    vp3_managed_callback_need_v1(($device['device_status']??null)==='approved'&&(int)$device['expires_at']>$now
        &&($input['device_id']??null)===$device['id']&&($input['generation']??null)===(int)$device['generation'],403);
    $id=vp3_managed_callback_id_v1($input['managed_request_id']??null);$owner=(int)$device['owner_user_id'];
    $user=vp3_managed_callback_owner_lock_v1($pdo,$owner);
    $row=vp3_managed_callback_row_v1($pdo,$id,true);$native=vp3_native_dialthrough_row_v1($pdo,$id,true);
    vp3_managed_callback_need_v1(is_array($row)&&is_array($native)&&(int)$row['owner_user_id']===$owner&&(int)$native['owner_user_id']===$owner
        &&$row['device_id']===$device['id']&&$row['state']==='approved'&&(int)$row['approved_epoch']>0&&(int)$row['expires_epoch']>$now
        &&$native['state']==='review'&&(int)$native['issued_epoch']===0,409);
    vp3_managed_callback_current_v1($pdo,$row,$user,$now);
    vp3_managed_callback_need_v1(vp3_managed_pilot_review_v1($pdo,$row,$now)['available'],403);
    vp3_managed_callback_need_v1(!vp3_managed_recording_row_v1($pdo,$id,true));
    $pin=str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT);
    $q=$pdo->prepare("UPDATE phone_native_dialthrough_v1 SET pin_hash=?,issued_epoch=?,state='issued',updated_epoch=? WHERE request_id=? AND state='review' AND issued_epoch=0");
    $q->execute([vp3_native_dialthrough_digest_v1($id,$pin),$now,$now,$id]);vp3_managed_callback_need_v1($q->rowCount()===1);
    return ['native_dialthrough_ticket'=>['request_id'=>$id,'line_e164'=>$row['line_e164'],'pin'=>$pin,'expires_epoch'=>(int)$row['expires_epoch'],'server_epoch'=>$now]];
}
function vp3_native_dialthrough_hangup_v1():array
{return ['accepted'=>true,'twiml'=>vp3_phone_browser_hangup_v1()];}
function vp3_native_dialthrough_prompt_v1(array $row,string $sid):array
{
    $url=vp3_phone_browser_xml_v1(vp3_managed_transport_url_v1('native_pin',$row['public_id'],vp3_native_dialthrough_nonce_v1($row['public_id'],$sid)));
    return ['accepted'=>true,'twiml'=>'<?xml version="1.0" encoding="UTF-8"?><Response><Gather input="dtmf" numDigits="8" timeout="15" actionOnEmptyResult="true" action="'.$url.'" method="POST"><Say>VP3 private dial through. Enter the eight digit code shown on your connected phone. This call is not recorded by VP3.</Say></Gather><Hangup/></Response>'];
}
/** Called only from the signature-verified ordinary inbound edge. No SID or PIN guessing. */
function vp3_native_dialthrough_inbound_v1(PDO $pdo,array $number,string $sid,string $from,string $to,int $now):?array
{
    if(!vp3_native_dialthrough_ready_v1($pdo))return null;
    $q=$pdo->prepare('SELECT request_id,owner_user_id FROM phone_native_dialthrough_v1 WHERE challenge_sid=?');$q->execute([$sid]);$prior=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if(!$prior&&(!vp3_native_dialthrough_enabled_v1()||!preg_match('/^\+[1-9][0-9]{7,14}$/D',$from)))return null;
    $owner=(int)$number['owner_user_id'];
    if($prior&&(int)$prior['owner_user_id']!==$owner)return vp3_native_dialthrough_hangup_v1();
    // Ordinary incoming calls never acquire a native reservation or owner lock.
    if(!$prior){
        $q=$pdo->prepare("SELECT 1 FROM phone_managed_callback_requests_v1 r JOIN phone_native_dialthrough_v1 n ON n.request_id=r.public_id AND n.owner_user_id=r.owner_user_id WHERE r.owner_user_id=? AND r.number_id=? AND r.line_e164=? AND r.holder_e164=? AND r.state='approved' AND r.expires_epoch>? AND n.state IN ('issued','challenge') LIMIT 1");
        $q->execute([$owner,(int)$number['id'],$to,$from,$now]);$candidate=$q->fetchColumn();$q->closeCursor();if(!$candidate)return null;
    }
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$number,$sid,$from,$to,$now,$prior,$owner){
        if($prior){
            try{$user=vp3_managed_callback_owner_lock_v1($pdo,$owner);}catch(VP3PhoneWorkspaceException){return vp3_native_dialthrough_hangup_v1();}
            $row=vp3_managed_callback_row_v1($pdo,$prior['request_id'],true);$n=vp3_native_dialthrough_row_v1($pdo,$prior['request_id'],true);
            if(!$row||!$n||!vp3_native_dialthrough_enabled_v1()||$n['state']!=='challenge'||$row['state']!=='approved'||(int)$row['expires_epoch']<=$now||!vp3_managed_pilot_allowed_v1($row,$now)
                ||$row['holder_e164']!==$from||$row['line_e164']!==$to||(int)$row['number_id']!==(int)$number['id'])return vp3_native_dialthrough_hangup_v1();
            // Do not replay a challenge after any transition, including consent withdrawal.
            try{vp3_managed_callback_current_v1($pdo,$row,$user,$now);}
            catch(VP3PhoneWorkspaceException){return vp3_native_dialthrough_hangup_v1();}
            return vp3_native_dialthrough_prompt_v1($row,$sid);
        }
        $user=vp3_managed_callback_owner_lock_v1($pdo,$owner);
        $q=$pdo->prepare("SELECT r.public_id FROM phone_managed_callback_requests_v1 r JOIN phone_native_dialthrough_v1 n ON n.request_id=r.public_id AND n.owner_user_id=r.owner_user_id
            WHERE r.owner_user_id=? AND r.number_id=? AND r.line_e164=? AND r.holder_e164=? AND r.state='approved' AND r.expires_epoch>? AND n.state IN ('issued','challenge') LIMIT 2");
        $q->execute([$owner,(int)$number['id'],$to,$from,$now]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();
        if(!$ids)return vp3_native_dialthrough_hangup_v1();
        if(count($ids)!==1)return vp3_native_dialthrough_hangup_v1();
        $row=vp3_managed_callback_row_v1($pdo,$ids[0],true);$n=vp3_native_dialthrough_row_v1($pdo,$ids[0],true);
        if(!$row||!$n||$n['state']!=='issued'||$n['pin_hash']===null||!vp3_managed_pilot_allowed_v1($row,$now))return vp3_native_dialthrough_hangup_v1();
        try{vp3_managed_callback_current_v1($pdo,$row,$user,$now);}
        catch(VP3PhoneWorkspaceException){return vp3_native_dialthrough_hangup_v1();}
        $q=$pdo->prepare('SELECT id FROM phone_browser_calls_v1 WHERE provider_call_sid=? OR provider_child_sid=?');$q->execute([$sid,$sid]);$used=$q->fetchColumn();$q->closeCursor();
        if($used!==false)return vp3_native_dialthrough_hangup_v1();
        $q=$pdo->prepare("SELECT id FROM phone_voice_calls_v130 WHERE provider='twilio' AND provider_call_sid=?");$q->execute([$sid]);$used=$q->fetchColumn();$q->closeCursor();
        if($used!==false)return null; // An already-routed ordinary call is never converted.
        $q=$pdo->prepare("UPDATE phone_native_dialthrough_v1 SET challenge_sid=?,challenge_epoch=?,state='challenge',updated_epoch=? WHERE request_id=? AND state='issued' AND challenge_sid IS NULL");
        $q->execute([$sid,$now,$now,$row['public_id']]);
        if($q->rowCount()!==1)return vp3_native_dialthrough_hangup_v1();
        return vp3_native_dialthrough_prompt_v1($row,$sid);
    });
}
/** A single signed keypad attempt consumes the authorization even when wrong. */
function vp3_native_dialthrough_confirm_v1(PDO $pdo,string $id,string $nonce,string $sid,array $form,int $now):array
{
    $hangup=['accepted'=>false,'xml'=>vp3_phone_browser_hangup_v1()];
    $hint=vp3_native_dialthrough_row_v1($pdo,$id);
    if(!$hint||!hash_equals(vp3_native_dialthrough_nonce_v1($id,$sid),$nonce))return $hangup;
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$id,$sid,$form,$now,$hint,$hangup){
        $owner=(int)$hint['owner_user_id'];$user=vp3_managed_callback_owner_lock_v1($pdo,$owner);
        $row=vp3_managed_callback_row_v1($pdo,$id,true);$n=vp3_native_dialthrough_row_v1($pdo,$id,true);
        if(!$row||!$n||$n['state']!=='challenge'||$n['challenge_sid']!==$sid||(int)$row['owner_user_id']!==$owner
            ||$row['state']!=='approved'||$row['holder_e164']!==($form['From']??null)||$row['line_e164']!==($form['To']??null))return $hangup;
        $digits=$form['Digits']??null;
        $valid=vp3_native_dialthrough_enabled_v1()&&vp3_managed_pilot_allowed_v1($row,$now)&&(int)$row['expires_epoch']>$now&&(int)$n['challenge_epoch']+30>$now
            &&is_string($digits)&&preg_match('/^[0-9]{8}$/D',$digits)===1&&is_string($n['pin_hash'])
            &&hash_equals($n['pin_hash'],vp3_native_dialthrough_digest_v1($id,$digits));
        if($valid){
            try{
                $a=vp3_managed_callback_current_v1($pdo,$row,$user,$now);
                vp3_managed_callback_need_v1(!vp3_managed_recording_row_v1($pdo,$id,true));
                vp3_connected_phone_prepare_v1($pdo,$a['scope'],['device_id'=>$row['device_id'],'profile_id'=>$row['profile_id'],
                    'profile_revision'=>(int)$row['profile_revision'],'phone_revision'=>(int)$row['phone_revision'],'confirmed'=>true],$now);
                $q=$pdo->prepare("SELECT COUNT(*) FROM phone_browser_calls_v1 WHERE owner_user_id=? AND state IN ('prepared','dialing','ringing','answered') AND (state<>'prepared' OR intent_expires_epoch>?)");
                $q->execute([$owner,$now]);$busy=(int)$q->fetchColumn();$q->closeCursor();vp3_managed_callback_need_v1($busy===0);
                $q=$pdo->prepare('SELECT COUNT(*) FROM phone_browser_calls_v1 WHERE owner_user_id=? AND created_epoch>?');
                $q->execute([$owner,$now-600]);$recent=(int)$q->fetchColumn();$q->closeCursor();vp3_managed_callback_need_v1($recent<10,429);
                $q=$pdo->prepare('SELECT id FROM phone_browser_calls_v1 WHERE provider_call_sid=? OR provider_child_sid=?');$q->execute([$sid,$sid]);$used=$q->fetchColumn();$q->closeCursor();vp3_managed_callback_need_v1($used===false);
                $q=$pdo->prepare("SELECT id FROM phone_voice_calls_v130 WHERE provider='twilio' AND provider_call_sid=?");$q->execute([$sid]);$used=$q->fetchColumn();$q->closeCursor();vp3_managed_callback_need_v1($used===false);
            }catch(VP3PhoneWorkspaceException){$valid=false;}
        }
        // Failure is durably consumed before returning Hangup, not rolled back.
        if(!$valid){
            $pdo->prepare("UPDATE phone_native_dialthrough_v1 SET pin_hash=NULL,state='rejected',consumed_epoch=?,updated_epoch=? WHERE request_id=?")->execute([$now,$now,$id]);
            $pdo->prepare("UPDATE phone_managed_callback_requests_v1 SET state='canceled',holder_e164='',destination_e164='',updated_epoch=? WHERE public_id=? AND state='approved'")->execute([$now,$id]);
            return $hangup;
        }
        $public=bin2hex(random_bytes(16));$callbackNonce=bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO phone_browser_calls_v1(public_id,owner_user_id,agent_id,phone_number_id,phone_e164,peer_e164,direction,provider_call_sid,
            conversation_id,chat_agent_id,state,recording_enabled,created_epoch,updated_epoch,paired_device_id,paired_profile_id,paired_profile_revision,paired_phone_revision,transport_kind,managed_parent_kind)
            VALUES (?,?,?,?,?,?,'outbound',?,?,?,'dialing',0,?,?,?,?,?,?,'managed_callback','native')")
            ->execute([$public,$owner,(int)$row['line_agent_id'],(int)$row['number_id'],$row['line_e164'],$row['holder_e164'],$sid,
                (int)$row['conversation_id'],(int)$row['chat_agent_id'],$now,$now,$row['device_id'],$row['profile_id'],(int)$row['profile_revision'],(int)$row['phone_revision']]);
        $pdo->prepare("INSERT INTO phone_managed_callback_dispatch_v1(request_id,call_public_id,owner_user_id,callback_nonce_hash,state,created_epoch,confirmation_epoch,updated_epoch,pilot_required)
            VALUES (?,?,?,?,'bridging',?,?,?,1)")->execute([$id,$public,$owner,hash('sha256',$callbackNonce),$now,$now,$now]);
        $q=$pdo->prepare("UPDATE phone_native_dialthrough_v1 SET pin_hash=NULL,state='consumed',call_public_id=?,consumed_epoch=?,updated_epoch=? WHERE request_id=? AND state='challenge'");$q->execute([$public,$now,$now,$id]);vp3_managed_callback_need_v1($q->rowCount()===1);
        $q=$pdo->prepare("UPDATE phone_managed_callback_requests_v1 SET state='dispatch_claimed',updated_epoch=? WHERE public_id=? AND state='approved'");$q->execute([$now,$id]);vp3_managed_callback_need_v1($q->rowCount()===1);
        $call=vp3_managed_transport_call_v1($pdo,$public,true);
        $delivery=vp3_phone_workspace_delivery_v1($pdo,$call,'call','call:'.$public,'Twilio native dial-through authorized. Destination connection is not yet confirmed.');
        $pdo->prepare('UPDATE phone_browser_calls_v1 SET chat_message_id=? WHERE id=?')->execute([$delivery['message_id'],$call['id']]);
        $complete=vp3_phone_browser_xml_v1(vp3_managed_transport_url_v1('complete',$public,$callbackNonce));$status=vp3_phone_browser_xml_v1(vp3_managed_transport_url_v1('child',$public,$callbackNonce));
        $line=vp3_phone_browser_xml_v1($row['line_e164']);$dest=vp3_phone_browser_xml_v1(vp3_managed_callback_number_v1($row['destination_e164']));
        return ['accepted'=>true,'xml'=>'<?xml version="1.0" encoding="UTF-8"?><Response><Dial callerId="'.$line.'" answerOnBridge="true" timeout="20" timeLimit="180" action="'.$complete.'" method="POST"><Number statusCallback="'.$status.'" statusCallbackMethod="POST" statusCallbackEvent="initiated ringing answered completed">'.$dest.'</Number></Dial><Hangup/></Response>'];
    });
}
