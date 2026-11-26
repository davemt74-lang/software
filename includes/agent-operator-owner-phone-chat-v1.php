<?php
declare(strict_types=1);
require_once __DIR__.'/agent-operator-owner-phone-v1.php';

/** Private owner settings and explicit, browser-originated reachability tests. */
function vp3_owner_phone_chat_private_v1(array $scope):void
{
    if($scope['conversation_id']<1||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))
        vp3_phone_workspace_error_v1('operator_private_chat_required',403,'Open your saved private Agent Chat.');
}
function vp3_owner_phone_chat_read_v1(PDO $pdo,array $scope,bool $lock=false):array
{
    vp3_owner_phone_chat_private_v1($scope);
    if(!VP3OperatorOwnerPhoneV1::ready($pdo))vp3_phone_workspace_error_v1('operator_owner_phone_upgrade',503,'Install the Account phone upgrade.');
    try{return VP3OperatorOwnerPhoneV1::read($pdo,$scope['user'],$lock);}
    catch(DomainException $e){vp3_phone_workspace_error_v1('operator_owner_phone_scope',403,$e->getMessage());}
}
function vp3_owner_phone_chat_view_v1(PDO $pdo,array $scope):array
{
    $p=vp3_owner_phone_chat_read_v1($pdo,$scope);
    $test=null;
    if($p['phone_revision']>0&&$p['phone_e164']!==''){
        $q=$pdo->prepare("SELECT public_id,state,conversation_id,chat_agent_id,created_epoch,owner_test_received_epoch,provider_child_sid
            FROM phone_browser_calls_v1 WHERE owner_user_id=? AND owner_phone_revision=? AND peer_e164=? ORDER BY id DESC LIMIT 1");
        $q->execute([$scope['owner_user_id'],$p['phone_revision'],$p['phone_e164']]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if($r)$test=['call_id'=>$r['public_id'],'state'=>$r['state'],'created_epoch'=>(int)$r['created_epoch'],
            'received'=>(int)$r['owner_test_received_epoch']>0,
            'can_confirm'=>(int)$r['owner_test_received_epoch']===0
                &&(int)$r['conversation_id']===$scope['conversation_id']&&(int)$r['chat_agent_id']===$scope['agent_id']
                &&in_array($r['state'],['answered','completed'],true)&&is_string($r['provider_child_sid'])
                &&preg_match('/\ACA[a-f0-9]{32}\z/iD',$r['provider_child_sid'])===1];
    }
    return ['phone_e164'=>$p['phone_e164'],'phone_revision'=>$p['phone_revision'],'revision'=>$p['revision'],
        'updates_requested'=>$p['updates_requested'],'hello_requested'=>$p['hello_requested'],
        'last_test'=>$test,'execution_enabled'=>false];
}
function vp3_owner_phone_chat_action_v1(PDO $pdo,array $scope,array $input):array
{
    $p=vp3_owner_phone_chat_read_v1($pdo,$scope);
    $action=$input['action'];
    if($action==='owner_phone_settings')return ['owner_phone'=>vp3_owner_phone_chat_view_v1($pdo,$scope)];
    try{
        if($action==='save_owner_phone'){
            VP3OperatorOwnerPhoneV1::saveNumber($pdo,$scope['user'],['phone_e164'=>$input['phone_e164']??null,
                'expected_revision'=>$input['expected_revision']??null],time());
        }elseif($action==='save_owner_call_preferences'){
            if(!is_bool($input['updates_requested']??null)||!is_bool($input['hello_requested']??null))
                vp3_phone_workspace_error_v1('operator_owner_phone_input',422,'Choose the calling preferences.');
            $fields=['expected_revision'=>$input['expected_revision']??null,'update_scope'=>$p['update_scope'],'timezone'=>$p['timezone'],
                'window_start'=>$p['window_start'],'window_end'=>$p['window_end'],'max_calls_per_day'=>(string)$p['max_calls_per_day'],
                'min_gap_minutes'=>(string)$p['min_gap_minutes'],'max_duration_seconds'=>(string)$p['max_duration_seconds']];
            foreach(['updates_requested','hello_requested'] as $k)if($input[$k])$fields[$k]='1';
            VP3OperatorOwnerPhoneV1::savePreferences($pdo,$scope['user'],$fields,time());
        }elseif($action==='confirm_owner_test'){
            $id=$input['call_id']??null;$rev=vp3_phone_workspace_id_v1($input['phone_revision']??null);
            if(!is_string($id)||preg_match('/\A[a-f0-9]{32}\z/D',$id)!==1||($input['received']??null)!==true)
                vp3_phone_workspace_error_v1('operator_owner_test_input',422,'Confirm the received test call.');
            vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$id,$rev){
                $p=vp3_owner_phone_chat_read_v1($pdo,$scope,true);
                if($p['phone_revision']!==$rev||$p['phone_e164']==='')
                    vp3_phone_workspace_error_v1('operator_owner_phone_changed',409,'Your saved number changed.');
                $q=$pdo->prepare("SELECT * FROM phone_browser_calls_v1 WHERE public_id=? AND owner_user_id=? AND conversation_id=? AND chat_agent_id=? AND owner_phone_revision=? AND peer_e164=?".vp3_phone_workspace_lock_v1($pdo));
                $q->execute([$id,$scope['owner_user_id'],$scope['conversation_id'],$scope['agent_id'],$rev,$p['phone_e164']]);$call=$q->fetch(PDO::FETCH_ASSOC);
                if(!$call||!in_array($call['state'],['answered','completed'],true)||preg_match('/\ACA[a-f0-9]{32}\z/iD',(string)$call['provider_child_sid'])!==1)
                    vp3_phone_workspace_error_v1('operator_owner_test_unconfirmed',409,'Wait for the carrier to report an answered test call.');
                if(!(int)$call['owner_test_received_epoch'])$pdo->prepare('UPDATE phone_browser_calls_v1 SET owner_test_received_epoch=? WHERE id=?')->execute([time(),$call['id']]);
            });
        }else vp3_phone_workspace_error_v1('operator_owner_phone_input',422,'Choose a supported owner phone action.');
    }catch(DomainException $e){vp3_phone_workspace_error_v1('operator_owner_phone_input',409,$e->getMessage());}
    return ['owner_phone'=>vp3_owner_phone_chat_view_v1($pdo,$scope)];
}
/** Called inside the canonical call preparation transaction, after locking the owner. */
function vp3_owner_phone_test_destination_v1(PDO $pdo,array $scope,array $input,int $now):array
{
    if(($input['confirmed']??null)!==true||array_key_exists('to_number',$input)||!empty($input['recording_enabled']))
        vp3_phone_workspace_error_v1('operator_owner_test_confirmation',422,'Confirm a test call to your saved number, without recording.');
    $p=vp3_owner_phone_chat_read_v1($pdo,$scope,true);
    if($p['phone_e164']===''||$p['phone_revision']!==vp3_phone_workspace_id_v1($input['phone_revision']??null))
        vp3_phone_workspace_error_v1('operator_owner_phone_changed',409,'Your saved number changed. Refresh before testing.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM phone_browser_calls_v1 WHERE owner_user_id=? AND owner_phone_revision>0 AND created_epoch>?');
    $q->execute([$scope['owner_user_id'],$now-120]);$recent=(int)$q->fetchColumn();
    $q->execute([$scope['owner_user_id'],$now-86400]);$daily=(int)$q->fetchColumn();
    if($recent||$daily>=3)vp3_phone_workspace_error_v1('operator_owner_test_limit',429,'Wait before testing again. Up to three test calls per day.');
    return ['destination'=>$p['phone_e164'],'revision'=>$p['phone_revision']];
}
/** Signed carrier callback has no browser session. Recheck the exact saved revision. */
function vp3_owner_phone_test_current_v1(PDO $pdo,array $call):bool
{
    if((int)($call['owner_phone_revision']??0)===0)return true;
    if(!$pdo->inTransaction()||!VP3OperatorOwnerPhoneV1::ready($pdo)||(int)$call['recording_enabled']!==0)return false;
    $q=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.vp3_phone_workspace_lock_v1($pdo));
    $q->execute([(int)$call['owner_user_id']]);if(!$q->fetchColumn())return false;
    $q=$pdo->prepare('SELECT phone_e164,phone_revision FROM phone_operator_owner_preferences_v1 WHERE owner_user_id=?'.vp3_phone_workspace_lock_v1($pdo));
    $q->execute([(int)$call['owner_user_id']]);$r=$q->fetch(PDO::FETCH_ASSOC);
    return $r!==false&&(int)$r['phone_revision']===(int)$call['owner_phone_revision']
        &&$r['phone_e164']!==''&&hash_equals($r['phone_e164'],(string)$call['peer_e164']);
}
