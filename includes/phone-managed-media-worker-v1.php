<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

/** Dedicated media lifecycle driver. Only the Cloud CLI or a synthetic test calls it. */
require_once __DIR__.'/phone-managed-recording-v1.php';
require_once __DIR__.'/phone-managed-control-v1.php';

function vp3_managed_media_driver_enabled_v1():bool
{
    return (string)setting('phone_managed_media_driver_enabled_v1','0')==='1';
}
function vp3_managed_media_recording_resource_v1(string $account,string $parent,string $record):string
{
    vp3_managed_callback_need_v1(preg_match('/^AC[a-f0-9]{32}$/iD',$account)===1&&vp3_managed_transport_sid_v1($parent)&&vp3_managed_recording_sid_v1($record),422);
    return '/2010-04-01/Accounts/'.$account.'/Calls/'.$parent.'/Recordings/'.$record.'.json';
}
function vp3_managed_media_recording_evidence_v1(mixed $raw,string $account,array $call,array $consent):?string
{
    if(!is_array($raw))return null;
    foreach(['sid'=>(string)$consent['recording_sid'],'account_sid'=>$account,'call_sid'=>(string)$call['provider_call_sid'],'source'=>'StartCallRecordingAPI','track'=>'both'] as $k=>$v)
        if(!is_string($raw[$k]??null)||!hash_equals($v,$raw[$k]))return null;
    if(!in_array($raw['channels']??null,[2,'2'],true)||!in_array($raw['status']??null,['in-progress','paused','processing','stopped','completed','absent'],true))return null;
    return $raw['status'];
}
/** Withdrawal is durable immediately, and does not falsely assert carrier termination. */
function vp3_managed_media_withdraw_locked_v1(PDO $pdo,array $row,int $now):array
{
    vp3_managed_callback_need_v1(vp3_phone_transaction_active_v1($pdo)&&vp3_managed_recording_ready_v1($pdo),503);
    $c=vp3_managed_recording_row_v1($pdo,$row['public_id'],true);
    vp3_managed_callback_need_v1(is_array($c)&&(int)$c['owner_user_id']===(int)$row['owner_user_id'],404);
    vp3_managed_recording_revoke_v1($pdo,$row['public_id'],$now);
    // If a provider mutation might already have happened, do not label it stopped.
    if((int)$c['start_epoch']===0)$pdo->prepare("UPDATE phone_managed_recording_consent_v1 SET stop_state='not_started',updated_epoch=? WHERE request_id=? AND start_epoch=0 AND stop_state='none'")->execute([$now,$row['public_id']]);
    return vp3_managed_recording_view_v1(vp3_managed_recording_row_v1($pdo,$row['public_id'],true));
}
/** Claim a single stop by exact recording SID; no Twilio.CURRENT or discovery by phone number. */
function vp3_managed_media_stop_v1(PDO $pdo,string $public,?callable $carrier=null,?int $clock=null):array
{
    vp3_managed_callback_need_v1(!vp3_phone_transaction_active_v1($pdo)&&vp3_managed_recording_ready_v1($pdo),503);
    $now=$clock??time();vp3_managed_callback_id_v1($public);
    $hint=vp3_managed_transport_row_v1($pdo,$public);vp3_managed_callback_need_v1(is_array($hint),404);
    $snapshot=vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$hint,$public,$now){
        $owner=(int)$hint['owner_user_id'];vp3_managed_callback_need_v1(vp3_managed_transport_owner_lock_v1($pdo,$owner),403);
        $t=vp3_managed_transport_row_v1($pdo,$public,true);$call=vp3_managed_transport_call_v1($pdo,$public,true);
        $c=$t?vp3_managed_recording_row_v1($pdo,$t['request_id'],true):null;
        vp3_managed_callback_need_v1(is_array($t)&&is_array($call)&&is_array($c)&&(int)$call['owner_user_id']===$owner&&(int)$c['owner_user_id']===$owner
            &&(int)$c['revoked_epoch']>0&&(int)$c['start_epoch']>0,409);
        if(in_array($c['start_state'],['received','absent','discard_required'],true)){
            $pdo->prepare("UPDATE phone_managed_recording_consent_v1 SET stop_state='terminal_verified',updated_epoch=? WHERE request_id=?")->execute([$now,$c['request_id']]);
            return ['done'=>'terminal_verified'];
        }
        if(!in_array($c['stop_state'],['none','awaiting_identity'],true))return ['done'=>$c['stop_state']];
        if(!vp3_managed_recording_sid_v1($c['recording_sid'])||!vp3_managed_transport_sid_v1($call['provider_call_sid'])){
            $pdo->prepare("UPDATE phone_managed_recording_consent_v1 SET stop_state='awaiting_identity',updated_epoch=? WHERE request_id=?")->execute([$now,$c['request_id']]);
            return ['done'=>'awaiting_identity'];
        }
        $q=$pdo->prepare("UPDATE phone_managed_recording_consent_v1 SET stop_state='claimed',stop_epoch=?,updated_epoch=? WHERE request_id=? AND stop_state IN ('none','awaiting_identity')");
        $q->execute([$now,$now,$c['request_id']]);vp3_managed_callback_need_v1($q->rowCount()===1);
        return ['call'=>$call,'consent'=>$c];
    });
    if(isset($snapshot['done']))return ['state'=>$snapshot['done']];
    $call=$snapshot['call'];$c=$snapshot['consent'];$result='uncertain';
    try{
        $carrier=$carrier??'vp3_phone_twilio_http_v100';
        $account=(string)vp3_phone_twilio_state_v100()['account_sid'];
        $resource=vp3_managed_media_recording_resource_v1($account,$call['provider_call_sid'],$c['recording_sid']);
        $state=vp3_managed_media_recording_evidence_v1($carrier('GET',$resource,[]),$account,$call,$c);
        if(in_array($state,['stopped','completed','absent'],true))$result='terminal_verified';
        elseif(in_array($state,['in-progress','paused','processing'],true)){
            $allowed=vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$call,$c){
                if(!vp3_managed_transport_owner_lock_v1($pdo,(int)$call['owner_user_id']))return false;
                $fresh=vp3_managed_recording_row_v1($pdo,$c['request_id'],true);$live=vp3_managed_transport_call_v1($pdo,$call['public_id'],true);
                return $fresh&&$live&&$fresh['stop_state']==='claimed'&&(int)$fresh['revoked_epoch']>0
                    &&$fresh['recording_sid']===$c['recording_sid']&&$live['provider_call_sid']===$call['provider_call_sid'];
            });
            if($allowed){
                $reply=vp3_managed_media_recording_evidence_v1($carrier('POST',$resource,['Status'=>'stopped']),$account,$call,$c);
                if(in_array($reply,['stopped','completed','absent'],true))$result='terminal_verified';
            }else $result='not_sent';
        }
    }catch(Throwable){/* A stop with an uncertain receipt is never automatically repeated. */}
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$call,$c,$result,$clock){
        vp3_managed_transport_owner_lock_v1($pdo,(int)$call['owner_user_id']);
        $q=$pdo->prepare("UPDATE phone_managed_recording_consent_v1 SET stop_state=?,updated_epoch=? WHERE request_id=? AND stop_state='claimed'");
        $q->execute([$result,$clock??time(),$c['request_id']]);
        $fresh=vp3_managed_recording_row_v1($pdo,$c['request_id'],true);
        return ['state'=>$fresh['stop_state']??'uncertain'];
    });
}
/** Bounded read/stop/start pass. Stop remains available if new recording is disabled. */
function vp3_managed_media_tick_v1(PDO $pdo,?callable $carrier=null,?int $clock=null):array
{
    $out=['started'=>0,'stopped'=>0,'unconfirmed'=>0,'attempted'=>0];
    if(!vp3_managed_recording_ready_v1($pdo)||!vp3_managed_transport_ready_v1($pdo))return $out;
    $now=$clock??time();
    // A current authority or policy loss also becomes a durable withdrawal
    // for in-flight media; no new paid work is authorized by a stale snapshot.
    $q=$pdo->query("SELECT t.call_public_id,t.owner_user_id,t.request_id FROM phone_managed_recording_consent_v1 c
        JOIN phone_managed_callback_dispatch_v1 t ON t.request_id=c.request_id AND t.owner_user_id=c.owner_user_id
        JOIN phone_browser_calls_v1 b ON b.public_id=t.call_public_id AND b.owner_user_id=t.owner_user_id
        WHERE c.revoked_epoch=0 AND c.start_epoch>0 AND c.start_state IN ('starting','started','uncertain')
          AND b.state IN ('dialing','ringing','answered') ORDER BY c.updated_epoch ASC LIMIT 4");
    $liveRows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
    foreach($liveRows as $hint){
        try{vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$hint,$now){
            if(!vp3_managed_transport_owner_lock_v1($pdo,(int)$hint['owner_user_id']))return;
            $r=vp3_managed_callback_row_v1($pdo,$hint['request_id'],true);$c=vp3_managed_recording_row_v1($pdo,$hint['request_id'],true);
            if(!$r||!$c||(int)$c['revoked_epoch']>0)return;
            try{
                vp3_managed_callback_need_v1(vp3_managed_recording_enabled_v1()&&vp3_managed_transport_enabled_v1()&&vp3_managed_media_driver_enabled_v1()&&$r['state']==='dispatch_claimed');
                $user=vp3_managed_callback_owner_lock_v1($pdo,(int)$hint['owner_user_id']);$a=vp3_managed_callback_current_v1($pdo,$r,$user,$now);
                vp3_managed_recording_current_v1($pdo,$r,$c,$a);
            }catch(VP3PhoneWorkspaceException){vp3_managed_recording_revoke_v1($pdo,$r['public_id'],$now);}
        });}catch(Throwable){$out['unconfirmed']++;}
    }
    // First service explicit withdrawal. Unknown identity is left for a signed
    // terminal receipt or operator hangup; never guess or retry an uncertain POST.
    $q=$pdo->query("SELECT t.call_public_id FROM phone_managed_recording_consent_v1 c
        JOIN phone_managed_callback_dispatch_v1 t ON t.request_id=c.request_id AND t.owner_user_id=c.owner_user_id
        WHERE c.revoked_epoch>0 AND c.start_epoch>0 AND (c.stop_state='none' OR (c.stop_state='awaiting_identity' AND c.recording_sid IS NOT NULL))
        ORDER BY c.updated_epoch ASC LIMIT 2");
    $ids=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();
    foreach($ids as $id){
        $out['attempted']++;
        try{$v=vp3_managed_media_stop_v1($pdo,(string)$id,$carrier,$clock);$out[$v['state']==='terminal_verified'?'stopped':'unconfirmed']++;}
        catch(Throwable){$out['unconfirmed']++;}
    }
    if(!vp3_managed_media_driver_enabled_v1()||!vp3_managed_recording_enabled_v1()||!vp3_managed_transport_enabled_v1())return $out;
    // The public acceptance pilot is unrecorded. This driver cannot broaden it.
    $q=$pdo->prepare("SELECT t.call_public_id,t.callback_nonce_hash FROM phone_managed_recording_consent_v1 c
        JOIN phone_managed_callback_dispatch_v1 t ON t.request_id=c.request_id AND t.owner_user_id=c.owner_user_id
        JOIN phone_managed_callback_requests_v1 r ON r.public_id=t.request_id AND r.owner_user_id=t.owner_user_id
        JOIN phone_browser_calls_v1 b ON b.public_id=t.call_public_id AND b.owner_user_id=t.owner_user_id
        WHERE t.pilot_required=0 AND c.start_state='consented' AND c.revoked_epoch=0 AND c.start_epoch=0
          AND b.state='answered' AND t.state='bridging' AND t.parent_state='' AND r.state='dispatch_claimed' AND r.expires_epoch>?
        ORDER BY c.updated_epoch ASC LIMIT 1");
    $q->execute([$now]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if(!$row)return $out;
    $out['attempted']++;
    try{
        $nonce=vp3_managed_media_nonce_v1($row['call_public_id']);
        vp3_managed_callback_need_v1(hash_equals($row['callback_nonce_hash'],hash('sha256',$nonce)));
        $result=vp3_managed_recording_start_v1($pdo,$row['call_public_id'],$nonce,$carrier,$clock);
        $out[$result['state']==='started'?'started':'unconfirmed']++;
    }catch(Throwable){$out['unconfirmed']++;}
    return $out;
}
