<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

/** PS5E.4: exact-SID provider reconciliation and one-shot parent hangup. */
require_once __DIR__.'/phone-managed-transport-v1.php';
require_once __DIR__.'/phone-managed-control-schema-v1.php';

function vp3_managed_control_terminal_v1(string $state):bool
{
    return in_array($state,['completed','busy','no_answer','failed','canceled'],true);
}
function vp3_managed_control_status_v1(mixed $status):?string
{
    return is_string($status)?(['queued'=>'dialing','ringing'=>'ringing','in-progress'=>'answered','completed'=>'completed','busy'=>'busy','no-answer'=>'no_answer','failed'=>'failed','canceled'=>'canceled'][$status]??null):null;
}
function vp3_managed_control_row_v1(PDO $pdo,string $public,bool $lock=false):?array
{
    $q=$pdo->prepare('SELECT * FROM phone_managed_callback_controls_v1 WHERE call_public_id=?'.($lock?vp3_phone_workspace_lock_v1($pdo):''));
    $q->execute([$public]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row?:null;
}
/** The controls belong to the exact private paired Chat, not to a participant. */
function vp3_managed_control_scope_v1(PDO $pdo,array $scope,array $call,bool $lock=false):void
{
    vp3_managed_callback_need_v1((!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null)
        &&(int)$call['owner_user_id']===(int)$scope['owner_user_id']
        &&(int)$scope['conversation_id']>0&&(int)$call['conversation_id']===(int)$scope['conversation_id']
        &&(int)$call['chat_agent_id']===(int)$scope['agent_id'],404);
    $line=vp3_phone_workspace_number_v1($pdo,$scope,(int)$call['phone_number_id'],false,$lock);
    vp3_managed_callback_need_v1((int)$line['agent_id']===(int)$call['agent_id']
        &&hash_equals((string)$line['phone_e164'],(string)$call['phone_e164']),403);
}
function vp3_managed_control_view_v1(PDO $pdo,array $scope,array $call):array
{
    $out=['can_reconcile'=>false,'can_end'=>false,'parent_bound'=>false,'read_state'=>'unavailable','end_state'=>'none','dispatch_state'=>'','parent_state'=>'','missed_leg'=>''];
    if(($call['transport_kind']??'')!=='managed_callback'||!vp3_managed_control_ready_v1($pdo))return $out;
    try{
        vp3_managed_control_scope_v1($pdo,$scope,$call);
        $control=vp3_managed_control_row_v1($pdo,$call['public_id']);
        $t=vp3_managed_transport_row_v1($pdo,$call['public_id']);
        if(!$t||(int)$t['owner_user_id']!==(int)$scope['owner_user_id'])return $out;
        $out['dispatch_state']=(string)$t['state'];$out['parent_state']=(string)$t['parent_state'];
        if($call['state']==='no_answer')$out['missed_leg']=(int)$t['confirmation_epoch']>0?'destination':'holder';
        $out['parent_bound']=vp3_managed_transport_sid_v1($call['provider_call_sid']??null);
        $out['read_state']=$control['read_state']??'none';$out['end_state']=$control['end_state']??'none';
        if(!vp3_managed_control_terminal_v1((string)$call['state'])){
            $out['can_reconcile']=true;
            $out['can_end']=in_array($out['end_state'],['none','awaiting_identity'],true);
        }
    }catch(Throwable){}
    return $out;
}
function vp3_managed_control_result_v1(PDO $pdo,array $scope,string $public):array
{
    $call=vp3_managed_transport_call_v1($pdo,$public);
    vp3_managed_callback_need_v1(is_array($call),404);vp3_managed_control_scope_v1($pdo,$scope,$call);
    return ['call'=>vp3_phone_workspace_call_projection_v1($call)+['managed_control'=>vp3_managed_control_view_v1($pdo,$scope,$call)]];
}
/** Only exact immutable SIDs may be looked up. Never search by phone number/time. */
function vp3_managed_control_resource_v1(string $account,string $sid):string
{
    vp3_managed_callback_need_v1(preg_match('/^AC[a-f0-9]{32}$/iD',$account)===1&&vp3_managed_transport_sid_v1($sid),422);
    return '/2010-04-01/Accounts/'.$account.'/Calls/'.$sid.'.json';
}
function vp3_managed_control_evidence_v1(mixed $raw,string $account,array $call,?array $request=null):?array
{
    $child=$request!==null;$sid=(string)$call[$child?'provider_child_sid':'provider_call_sid'];
    $native=!$child&&($call['managed_parent_kind']??'callback')==='native';
    $to=$child?(string)$request['destination_e164']:(string)$call[$native?'phone_e164':'peer_e164'];
    if(!is_array($raw)||!vp3_managed_transport_sid_v1($sid)||!preg_match('/^\+[1-9][0-9]{7,14}$/D',$to))return null;
    foreach(['sid'=>$sid,'account_sid'=>$account,'from'=>(string)$call[$native?'peer_e164':'phone_e164'],'to'=>$to,'direction'=>$child?'outbound-dial':($native?'inbound':'outbound-api')] as $key=>$expected)
        if(!is_string($raw[$key]??null)||!hash_equals($expected,$raw[$key]))return null;
    if($child&&(!is_string($raw['parent_call_sid']??null)||!hash_equals((string)$call['provider_call_sid'],$raw['parent_call_sid'])))return null;
    if(!$child&&!empty($raw['parent_call_sid']))return null;
    $state=vp3_managed_control_status_v1($raw['status']??null);if(!$state)return null;
    $duration=$raw['duration']??null;
    if($duration===null||$duration==='')$duration=0;
    if(!is_int($duration)&&!(is_string($duration)&&preg_match('/^\d{1,6}$/D',$duration)))return null;
    if((int)$duration<0||(int)$duration>240)return null;
    if($state==='completed'&&(!isset($raw['duration'])||$raw['duration']===''))return null;
    return ['state'=>$state,'duration'=>(int)$duration];
}
/** Lock and reserve the control operation before any provider I/O. */
function vp3_managed_control_claim_v1(PDO $pdo,array $scope,string $public,string $action,bool $confirmed,int $now):array
{
    vp3_managed_callback_need_v1(vp3_managed_control_ready_v1($pdo),503);
    vp3_managed_callback_id_v1($public);
    vp3_managed_callback_need_v1(in_array($action,['managed_reconcile','managed_end'],true),422);
    vp3_managed_callback_need_v1($action!=='managed_end'||$confirmed,403);
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$public,$action,$now){
        $owner=(int)$scope['owner_user_id'];vp3_managed_callback_owner_lock_v1($pdo,$owner);
        $call=vp3_managed_transport_call_v1($pdo,$public,true);$t=vp3_managed_transport_row_v1($pdo,$public,true);
        vp3_managed_callback_need_v1(is_array($call)&&is_array($t)&&(int)$t['owner_user_id']===$owner,404);
        vp3_managed_control_scope_v1($pdo,$scope,$call,true);
        if(vp3_managed_control_terminal_v1((string)$call['state']))return ['done'=>true];
        $request=vp3_managed_callback_row_v1($pdo,$t['request_id'],true);
        vp3_managed_callback_need_v1(is_array($request)&&(int)$request['owner_user_id']===$owner&&$request['device_id']===$call['paired_device_id']&&$request['profile_id']===$call['paired_profile_id'],409);
        $control=vp3_managed_control_row_v1($pdo,$public,true);
        if(!$control){
            $pdo->prepare('INSERT INTO phone_managed_callback_controls_v1(call_public_id,owner_user_id,updated_epoch) VALUES (?,?,?)')->execute([$public,$owner,$now]);
            $control=vp3_managed_control_row_v1($pdo,$public,true);
        }
        vp3_managed_callback_need_v1((int)$control['owner_user_id']===$owner,404);
        $bound=vp3_managed_transport_sid_v1($call['provider_call_sid']??null);
        if($action==='managed_end'){
            // A stop intent fences the bridge even if the carrier POST receipt was lost.
            if(!in_array($control['end_state'],['none','awaiting_identity'],true))return ['done'=>true];
            // A stop intent prevents any not-yet-started recording. Already recorded
            // content is not made inaccessible just because a normal call ends.
            if(vp3_managed_recording_ready_v1($pdo))$pdo->prepare("UPDATE phone_managed_recording_consent_v1 SET revoked_epoch=?,updated_epoch=? WHERE request_id=? AND start_epoch=0 AND revoked_epoch=0")->execute([$now,$now,$t['request_id']]);
            $pdo->prepare("UPDATE phone_managed_callback_requests_v1 SET state='revoked',updated_epoch=? WHERE public_id=? AND state='dispatch_claimed'")->execute([$now,$t['request_id']]);
            $pdo->prepare('UPDATE phone_managed_callback_controls_v1 SET end_state=?,end_epoch=?,updated_epoch=? WHERE call_public_id=?')->execute([$bound?'claimed':'awaiting_identity',$now,$now,$public]);
        }else{
            vp3_managed_callback_need_v1((int)$control['last_read_epoch']<=$now-10,429);
            $pdo->prepare('UPDATE phone_managed_callback_controls_v1 SET read_state=?,last_read_epoch=?,updated_epoch=? WHERE call_public_id=?')->execute([$bound?'checking':'parent_unbound',$now,$now,$public]);
        }
        if(!$bound)return ['done'=>true];
        return ['call'=>$call,'transport'=>$t,'request'=>$request];
    });
}
/** Apply only exact read evidence, never reopen a terminal row. */
function vp3_managed_control_apply_v1(PDO $pdo,array $scope,array $snapshot,?array $parent,?array $child,string $readState,?string $endState,int $now):void
{
    $public=$snapshot['call']['public_id'];
    vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$snapshot,$parent,$child,$readState,$endState,$now,$public){
        vp3_managed_callback_owner_lock_v1($pdo,(int)$scope['owner_user_id']);
        $call=vp3_managed_transport_call_v1($pdo,$public,true);$t=vp3_managed_transport_row_v1($pdo,$public,true);
        vp3_managed_callback_need_v1(is_array($call)&&is_array($t),404);vp3_managed_control_scope_v1($pdo,$scope,$call,true);
        vp3_managed_callback_need_v1(hash_equals((string)$snapshot['call']['provider_call_sid'],(string)$call['provider_call_sid'])&&$t['request_id']===$snapshot['transport']['request_id']);
        $state=(string)$call['state'];
        if(!vp3_managed_control_terminal_v1($state)&&$parent){
            if(vp3_managed_control_terminal_v1($parent['state'])){
                $pdo->prepare('UPDATE phone_managed_callback_dispatch_v1 SET parent_state=?,updated_epoch=? WHERE call_public_id=? AND parent_state=?')->execute([$parent['state'],$now,$public,'']);
                if((int)$t['confirmation_epoch']===0){
                    vp3_managed_transport_terminal_v1($pdo,$t,$call,$parent['state']==='completed'?'canceled':$parent['state'],0,$now);
                }elseif($child&&vp3_managed_control_terminal_v1($child['state'])&&$call['provider_child_sid']&&hash_equals((string)$snapshot['call']['provider_child_sid'],(string)$call['provider_child_sid'])){
                    vp3_managed_transport_terminal_v1($pdo,$t,$call,$child['state'],$child['duration'],$now,(string)$call['provider_child_sid']);
                }else $readState='destination_unresolved';
            }elseif($child&&$call['provider_child_sid']&&hash_equals((string)$snapshot['call']['provider_child_sid'],(string)$call['provider_child_sid'])){
                // A live parent is not proof that the destination answered.
                $rank=['dialing'=>1,'ringing'=>2,'answered'=>3];
                if(isset($rank[$child['state']])&&($rank[$child['state']]>=($rank[$state]??0)))
                    $pdo->prepare('UPDATE phone_browser_calls_v1 SET state=?,updated_epoch=? WHERE id=?')->execute([$child['state'],$now,$call['id']]);
            }
        }
        $fresh=vp3_managed_transport_call_v1($pdo,$public,true);
        if(vp3_managed_control_terminal_v1((string)$fresh['state']))$readState='terminal_verified';
        if($endState!==null)$pdo->prepare('UPDATE phone_managed_callback_controls_v1 SET end_state=?,read_state=?,updated_epoch=? WHERE call_public_id=? AND end_state=?')->execute([$endState,$readState,$now,$public,'claimed']);
        else $pdo->prepare('UPDATE phone_managed_callback_controls_v1 SET read_state=?,updated_epoch=? WHERE call_public_id=?')->execute([$readState,$now,$public]);
    });
}
/** Owner initiated, bounded provider read or end. Tests inject the transport. */
function vp3_managed_control_action_v1(PDO $pdo,array $scope,array $input,?callable $carrier=null,?int $clock=null):array
{
    vp3_managed_callback_need_v1(!vp3_phone_transaction_active_v1($pdo));
    $action=(string)($input['action']??'');$public=vp3_managed_callback_id_v1($input['call_id']??null);
    $now=$clock??time();
    $snapshot=vp3_managed_control_claim_v1($pdo,$scope,$public,$action,($input['confirmed']??false)===true,$now);
    if(isset($snapshot['done']))return vp3_managed_control_result_v1($pdo,$scope,$public);
    $carrier=$carrier??'vp3_phone_twilio_http_v100';$account=(string)vp3_phone_twilio_state_v100()['account_sid'];
    $parent=null;$child=null;$endState=null;$readState='provider_unconfirmed';
    $call=$snapshot['call'];
    try{
        $resource=vp3_managed_control_resource_v1($account,$call['provider_call_sid']);
        $parent=vp3_managed_control_evidence_v1($carrier('GET',$resource,[]),$account,$call);
        if($parent){
            $readState='parent_'.$parent['state'];
            if($action==='managed_end'){
                if(vp3_managed_control_terminal_v1($parent['state']))$endState='parent_ended';
                else{
                    // Recheck immutable scope/claim after GET and before the one carrier mutation.
                    $can=vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$public,$call){
                        vp3_managed_callback_owner_lock_v1($pdo,(int)$scope['owner_user_id']);
                        $fresh=vp3_managed_transport_call_v1($pdo,$public,true);$control=vp3_managed_control_row_v1($pdo,$public,true);
                        if(!$fresh||!$control||$control['end_state']!=='claimed'||vp3_managed_control_terminal_v1((string)$fresh['state'])||$fresh['provider_call_sid']!==$call['provider_call_sid'])return false;
                        vp3_managed_control_scope_v1($pdo,$scope,$fresh,true);return true;
                    });
                    if($can){
                        $receipt=vp3_managed_control_evidence_v1($carrier('POST',$resource,['Status'=>$parent['state']==='answered'?'completed':'canceled']),$account,$call);
                        if($receipt&&vp3_managed_control_terminal_v1($receipt['state'])){$parent=$receipt;$endState='parent_ended';$readState='parent_'.$parent['state'];}
                        else $endState='uncertain';
                    }else $endState='not_sent';
                }
            }
            // No discovery by caller ID: only the child already proven by a signed callback.
            if(vp3_managed_transport_sid_v1($call['provider_child_sid']??null)&&$snapshot['transport']['confirmation_epoch']>0){
                $child=vp3_managed_control_evidence_v1($carrier('GET',vp3_managed_control_resource_v1($account,$call['provider_child_sid']),[]),$account,$call,$snapshot['request']);
            }
        }
    }catch(Throwable){$readState='provider_unconfirmed';}
    if($action==='managed_end'&&$endState===null)$endState='uncertain';
    vp3_managed_control_apply_v1($pdo,$scope,$snapshot,$parent,$child,$readState,$endState,$clock??time());
    return vp3_managed_control_result_v1($pdo,$scope,$public);
}
