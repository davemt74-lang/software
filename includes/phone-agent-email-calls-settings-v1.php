<?php
declare(strict_types=1);

require_once __DIR__.'/phone-agent-email-calls-schema-v1.php';
require_once __DIR__.'/phone-agent-workspace-v1.php';
require_once __DIR__.'/phone-workspace-v1.php';
require_once __DIR__.'/agent-operator-owner-phone-v1.php';

final class VP3PhoneAgentEmailCallException extends RuntimeException
{
    public function __construct(public readonly string $reason,int $status=409)
    {parent::__construct(vp3_phone_agent_email_calls_message_v1($reason),$status);}
}

function vp3_phone_agent_email_calls_check_v1(bool $ok,string $reason,int $http=409): void
{if(!$ok)throw new VP3PhoneAgentEmailCallException($reason,$http);}

/** Only fixed, product-facing text is returned; provider errors and mail content are private. */
function vp3_phone_agent_email_calls_message_v1(string $reason): string
{
    return match($reason){
        'eligible'=>'Email calls are ready within your phone permissions, calling hours and limits.',
        'off'=>'Email calls are off. Your emails remain available in AGENT.',
        'upgrade_required','operator_upgrade_required'=>'Install the database upgrade to use email calls.',
        'owner_sign_in_required'=>'Sign in as this phone\'s owner to manage email calls.',
        'owner_unavailable','owner_access_changed'=>'Your account access changed. Sign in again before continuing.',
        'session_changed'=>'Your session changed. Refresh before continuing.',
        'invalid_request'=>'Check these email call settings and try again.',
        'policy_changed','workspace_changed'=>'These settings or the phone workspace changed. Refresh before continuing.',
        'consent_required'=>'Confirm that you allow recorded and transcribed AI calls about these email items.',
        'workspace_enrollment_required'=>'Open AGENT on your connected phone and enable your private workspace.',
        'device_changed','enrollment_changed'=>'Reconnect your phone or refresh its AGENT enrollment, then save email call permission again.',
        'owner_phone_required'=>'Save your main phone number in Account phone settings.',
        'owner_updates_off'=>'Enable requested Agent updates in Account phone settings.',
        'owner_phone_changed'=>'Your main phone number changed. Review and save email call permission again.',
        'recipient_permission_required'=>'On the connected phone, allow spontaneous Agent calls, including recording and transcription.',
        'recipient_opted_out'=>'Calls are paused because the phone recipient asked not to be called again. Review the phone call consent before enabling further calls.',
        'recipient_changed'=>'This phone\'s communication profile changed. Review and save email call permission again.',
        'line_changed','line_required'=>'Choose an active assigned Agent phone line and save email call permission.',
        'route_or_recording_changed'=>'Finish the assigned line\'s calling, recording and transcription setup.',
        'calling_window'=>'Calls wait until both your Account and connected phone calling hours allow them.',
        'call_frequency'=>'Calls wait for your daily limit and minimum gap between calls.',
        'phone_busy'=>'Calls wait while another call is active or its outcome is unresolved.',
        'calendar_conflict'=>'Calls wait while your Calendar is busy.',
        'phone_unavailable'=>'Check your assigned Agent phone line and phone access.',
        'source_changed','source_unavailable'=>'The email changed or is no longer available for this call.',
        'source_reviewed'=>'This email has already been reviewed on an authorized phone.',
        'call_changed','request_closed','outside_occurrence'=>'This email call has ended or changed.',
        'review_changed'=>'This email review changed. Review the current draft before enabling voice approval again.',
        'voice_review_expired'=>'Voice approval expired. Review the email again to enable it for this call.',
        'voice_review_unavailable'=>'Open the exact email during its active call to enable voice approval.',
        default=>'Email calls are temporarily unavailable. Your emails remain available in AGENT.',
    };
}

function vp3_phone_agent_email_calls_policy_v1(PDO $pdo,int $owner,bool $lock=false): ?array
{
    return vp3_phone_sql_v1($pdo,'SELECT * FROM phone_agent_email_call_policies_v1 WHERE owner_user_id=?'
        .($lock?vp3_phone_lock_v1($pdo):''),[$owner])->fetch(PDO::FETCH_ASSOC)?:null;
}

function vp3_phone_agent_email_calls_preferences_v1(PDO $pdo,int $owner,bool $lock=false): array
{
    vp3_phone_agent_email_calls_check_v1(VP3OperatorOwnerPhoneV1::ready($pdo),'upgrade_required',503);
    return vp3_phone_sql_v1($pdo,'SELECT * FROM phone_operator_owner_preferences_v1 WHERE owner_user_id=?'
        .($lock?vp3_phone_lock_v1($pdo):''),[$owner])->fetch(PDO::FETCH_ASSOC)?:VP3OperatorOwnerPhoneV1::defaults();
}

/** Validate an explicit owner grant for a worker, without creating an owner session. */
function vp3_phone_agent_email_calls_device_context_v1(PDO $pdo,array $user,string $deviceId,int $now,bool $lock=false): array
{
    $owner=(int)$user['id'];
    vp3_phone_agent_email_calls_check_v1(preg_match('/^[a-f0-9]{32}$/D',$deviceId)===1,'device_changed');
    $d=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_devices_v1 WHERE owner_user_id=? AND id=?'
        .($lock?vp3_phone_lock_v1($pdo):''),[$owner,$deviceId])->fetch(PDO::FETCH_ASSOC);
    vp3_phone_agent_email_calls_check_v1($d!==false&&$d['device_status']==='approved'&&(int)$d['expires_at']>$now,'device_changed');
    $e=vp3_phone_agent_workspace_enrollment_v1($pdo,$owner,$deviceId,$lock);
    vp3_phone_agent_email_calls_check_v1($e!==null&&(int)$e['enabled']===1,'workspace_enrollment_required');
    $fingerprint=hash('sha256',$owner.':'.(string)($user['password_hash']??''));
    vp3_phone_agent_email_calls_check_v1(hash_equals((string)$e['credential_hash'],(string)$d['token_hash'])
        &&hash_equals((string)$e['owner_fingerprint'],$fingerprint),'enrollment_changed');
    try{
        $agent=vp3_phone_agent_workspace_agent_v1($pdo,$owner,(int)$e['selected_agent_id']);
        vp3_phone_agent_workspace_chat_v1($pdo,$owner,$agent['id'],(int)$e['conversation_id']);
        $scope=vp3_phone_workspace_scope_v1($pdo,$user,['agent_id'=>$agent['id'],'conversation_id'=>(int)$e['conversation_id']]);
    }catch(RuntimeException $error){throw new VP3PhoneAgentEmailCallException('phone_unavailable',$error->getCode()===503?503:403);}
    $preferences=vp3_phone_agent_email_calls_preferences_v1($pdo,$owner,$lock);
    vp3_phone_agent_email_calls_check_v1((int)$preferences['phone_revision']>0
        &&preg_match('/^\+[1-9][0-9]{7,14}$/D',(string)$preferences['phone_e164'])===1,'owner_phone_required');
    vp3_phone_agent_email_calls_check_v1((int)$preferences['updates_requested']===1&&(int)$preferences['consent_epoch']>0,'owner_updates_off');
    $holder=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_communication_profiles_v1 WHERE owner_user_id=? AND device_id=?'
        .($lock?vp3_phone_lock_v1($pdo):''),[$owner,$deviceId])->fetch(PDO::FETCH_ASSOC);
    vp3_phone_agent_email_calls_check_v1($holder!==false&&(int)$holder['phone_revision']>0
        &&hash_equals((string)$holder['phone_e164'],(string)$preferences['phone_e164']),'recipient_changed');
    vp3_phone_agent_email_calls_check_v1((int)$holder['owner_calls']===1&&(int)$holder['holder_calls']===1
        &&(int)$holder['holder_proactive']===1&&((int)$holder['agent_call_consent']&2)===2,'recipient_permission_required');
    // The connected phone supplies independent hours and frequency limits. Duration
    // uses the existing owner ceiling; no unrecorded recipient duration is invented.
    $holder['max_duration_seconds']=(int)$preferences['max_duration_seconds'];
    return ['user'=>$user,'device'=>$d,'enrollment'=>$e,'preferences'=>$preferences,'holder'=>$holder,'scope'=>$scope,'agent'=>$agent];
}

function vp3_phone_agent_email_calls_context_v1(PDO $pdo,int $owner,int $now,bool $lock=false): array
{
    vp3_phone_agent_email_calls_check_v1($owner>0&&vp3_phone_agent_email_calls_ready_v1($pdo)
        &&vp3_phone_agent_workspace_ready_v1($pdo),'upgrade_required',503);
    try{$user=vp3_phone_agent_workspace_user_v1($pdo,$owner,$lock);}
    catch(RuntimeException){throw new VP3PhoneAgentEmailCallException('owner_access_changed',403);}
    $p=vp3_phone_agent_email_calls_policy_v1($pdo,$owner,$lock);
    vp3_phone_agent_email_calls_check_v1($p!==null&&((int)$p['urgent_enabled']===1||(int)$p['drafts_enabled']===1)
        &&(int)$p['consent_epoch']>0,'off');
    $c=vp3_phone_agent_email_calls_device_context_v1($pdo,$user,(string)$p['device_id'],$now,$lock);$e=$c['enrollment'];
    foreach(['grant_id','credential_hash','owner_fingerprint'] as $field)
        vp3_phone_agent_email_calls_check_v1(hash_equals((string)$p[$field],(string)$e[$field]),'enrollment_changed');
    vp3_phone_agent_email_calls_check_v1((int)$p['enrollment_revision']===(int)$e['revision']
        &&(int)$p['selected_agent_id']===(int)$e['selected_agent_id']&&(int)$p['conversation_id']===(int)$e['conversation_id'],'enrollment_changed');
    vp3_phone_agent_email_calls_check_v1((int)$p['phone_revision']===(int)$c['preferences']['phone_revision']
        &&hash_equals((string)$p['destination_hash'],hash('sha256',$c['preferences']['phone_e164'])),'owner_phone_changed');
    vp3_phone_agent_email_calls_check_v1(hash_equals((string)$p['holder_profile_id'],(string)$c['holder']['profile_id'])
        &&(int)$p['holder_phone_revision']===(int)$c['holder']['phone_revision'],'recipient_changed');
    vp3_phone_agent_email_calls_check_v1((int)$p['number_id']>0&&preg_match('/^[a-f0-9]{64}$/D',(string)$p['line_binding'])===1,'line_required');
    try{$line=vp3_phone_workspace_number_v1($pdo,$c['scope'],(int)$p['number_id'],true,$lock);}
    catch(VP3PhoneWorkspaceException){throw new VP3PhoneAgentEmailCallException('line_changed');}
    vp3_phone_agent_email_calls_check_v1(hash_equals((string)$p['line_binding'],hash('sha256',$line['provider_sid'].'|'.$line['phone_e164'])),'line_changed');
    return ['policy'=>$p]+$c;
}

function vp3_phone_agent_email_calls_policy_view_v1(?array $p): array
{
    return ['revision'=>(int)($p['revision']??0),'urgent_enabled'=>(bool)($p['urgent_enabled']??false),
        'drafts_enabled'=>(bool)($p['drafts_enabled']??false),'grace_seconds'=>(int)($p['grace_seconds']??300),
        'device_id'=>(string)($p['device_id']??''),'number_id'=>(int)($p['number_id']??0)];
}

function vp3_phone_agent_email_calls_call_view_v1(PDO $pdo,array $r): array
{
    $callId=null;
    if($r['event_id']!==null){
        $q=$pdo->prepare('SELECT call_public_id FROM phone_operator_carrier_attempts_v1 WHERE owner_user_id=? AND event_id=? AND start_epoch=?');
        $q->execute([(int)$r['owner_user_id'],(int)$r['event_id'],(int)$r['start_epoch']]);$raw=$q->fetchColumn();
        if(is_string($raw)&&preg_match('/^[a-f0-9]{32}$/D',$raw)===1)$callId=$raw;
    }
    return ['request_id'=>$r['id'],'revision'=>(int)$r['revision'],'state'=>$r['state'],'reason'=>$r['reason'],
        'call_id'=>$callId,'updated_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$r['updated_epoch'])];
}

/** Active means a native answered, consented, recorded call, never merely submitted. */
function vp3_phone_agent_email_calls_active_view_v1(PDO $pdo,int $owner,int $agent,int $now,?string $deviceId=null): ?array
{
    $q=$pdo->prepare("SELECT * FROM phone_agent_email_call_requests_v1 WHERE owner_user_id=? AND selected_agent_id=?
        AND state='submitted' AND end_epoch>? ORDER BY created_epoch DESC LIMIT 4");$q->execute([$owner,$agent,$now]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        if($deviceId!==null&&!hash_equals($r['device_id'],$deviceId))continue;
        try{
            require_once __DIR__.'/agent-operator-owner-email-v1.php';
            $c=vp3_phone_agent_email_calls_context_v1($pdo,$owner,$now);
            $active=vp3_operator_owner_email_active_v1($pdo,$c,$r['id'],$now);
            if($active!==null){$view=vp3_phone_agent_email_calls_call_view_v1($pdo,$r);
                return array_intersect_key($view,array_flip(['request_id','revision','state','call_id']))
                    +['item_ref'=>$r['item_ref'],'source_kind'=>$r['source_kind']];}
        }catch(RuntimeException){return null;}
    }
    return null;
}

function vp3_phone_agent_email_calls_view_v1(PDO $pdo,array $user,int $agent,int $now,?array $phoneScope=null): array
{
    $owner=(int)$user['id'];$p=vp3_phone_agent_email_calls_policy_v1($pdo,$owner);$preferences=vp3_phone_agent_email_calls_preferences_v1($pdo,$owner);
    $q=$pdo->prepare("SELECT id,device_name FROM vp3_phone_devices_v1 WHERE owner_user_id=? AND device_status='approved' AND expires_at>? ORDER BY device_name,id LIMIT 50");
    $q->execute([$owner,$now]);$devices=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $d){
        $entry=['device_id'=>$d['id'],'label'=>mb_substr(trim((string)$d['device_name'])?:'Connected phone',0,48),
            'agent_name'=>'Agent workspace','ready'=>false,'reason'=>'workspace_enrollment_required','lines'=>[]];
        try{
            $e=vp3_phone_agent_workspace_enrollment_v1($pdo,$owner,$d['id']);
            if($e!==null&&(int)$e['enabled']===1){$a=vp3_phone_agent_workspace_agent_v1($pdo,$owner,(int)$e['selected_agent_id']);$entry['agent_name']=$a['name'];}
            $c=vp3_phone_agent_email_calls_device_context_v1($pdo,$user,$d['id'],$now);
            foreach(vp3_phone_workspace_numbers_v1($pdo,$c['scope']) as $n){
                if(!$n['voice_enabled']||$n['status']!=='active')continue;
                $entry['lines'][]=['number_id'=>(int)$n['id'],'label'=>mb_substr($n['agent_name'].' · '.$n['phone_e164'],0,240)];
            }
            $entry['ready']=count($entry['lines'])>0;$entry['reason']=$entry['ready']?'eligible':'line_required';
        }catch(VP3PhoneAgentEmailCallException $e){$entry['reason']=$e->reason;}
        catch(RuntimeException){$entry['reason']='phone_unavailable';}
        $devices[]=$entry;
    }
    $enabled=$p!==null&&((bool)$p['urgent_enabled']||(bool)$p['drafts_enabled']);$reason='off';
    if($enabled){
        try{require_once __DIR__.'/agent-operator-owner-email-v1.php';$c=vp3_phone_agent_email_calls_context_v1($pdo,$owner,$now);
            $reason=vp3_operator_owner_email_readiness_v1($pdo,$c,$now)['reason'];}
        catch(VP3PhoneAgentEmailCallException $e){$reason=$e->reason;}
        catch(RuntimeException){$reason='phone_unavailable';}
    }
    $phone=$preferences['phone_e164'];
    $out=['ok'=>true,'policy'=>vp3_phone_agent_email_calls_policy_view_v1($p),'devices'=>$devices,
        'owner_phone'=>['masked_number'=>$phone===''?'':'Ending in '.substr($phone,-4),'updates_requested'=>(bool)$preferences['updates_requested'],
            'time_zone'=>$preferences['timezone'],'window_start'=>$preferences['window_start'],'window_end'=>$preferences['window_end'],
            'max_calls_per_day'=>(int)$preferences['max_calls_per_day'],'min_gap_minutes'=>(int)$preferences['min_gap_minutes'],
            'max_duration_seconds'=>(int)$preferences['max_duration_seconds']],
        'readiness'=>['state'=>!$enabled?'off':($reason==='eligible'?'ready':'blocked'),'reason'=>$reason,
            'message'=>vp3_phone_agent_email_calls_message_v1($reason)],
        'decision'=>empty($p['decision_code'])?null:['reason'=>$p['decision_code'],'at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$p['decision_epoch'])],
        'active_call'=>vp3_phone_agent_email_calls_active_view_v1($pdo,$owner,$agent,$now,$phoneScope['device']['id']??null),
        'links'=>['account_phone'=>url('/account.php').'#agent-phone','phone_connection'=>url('/phone-location.php')]];
    if($phoneScope!==null)$out['scope']=vp3_phone_agent_email_calls_phone_envelope_v1($phoneScope);
    return $out;
}

function vp3_phone_agent_email_calls_phone_envelope_v1(array $scope): array
{return ['device_id'=>$scope['device']['id'],'enrollment_revision'=>(int)$scope['enrollment']['revision'],'scope_revision'=>$scope['scope_revision']];}

function vp3_phone_agent_email_calls_save_v1(PDO $pdo,array $user,array $input,int $now): void
{
    $owner=(int)$user['id'];$old=vp3_phone_agent_email_calls_policy_v1($pdo,$owner,true);
    vp3_phone_agent_email_calls_check_v1(is_int($input['expected_revision']??null)&&$input['expected_revision']===(int)($old['revision']??0),'policy_changed');
    vp3_phone_agent_email_calls_check_v1(is_bool($input['urgent_enabled']??null)&&is_bool($input['drafts_enabled']??null)
        &&is_int($input['grace_seconds']??null)&&$input['grace_seconds']>=60&&$input['grace_seconds']<=3600,'invalid_request',422);
    $enabled=$input['urgent_enabled']||$input['drafts_enabled'];
    $row=['owner_user_id'=>$owner,'revision'=>(int)($old['revision']??0)+1,'urgent_enabled'=>(int)$input['urgent_enabled'],
        'drafts_enabled'=>(int)$input['drafts_enabled'],'grace_seconds'=>$input['grace_seconds'],'decision_code'=>'',
        'decision_epoch'=>0,'checked_epoch'=>0,'consent_epoch'=>0,'urgent_since_epoch'=>0,'drafts_since_epoch'=>0,
        'created_epoch'=>(int)($old['created_epoch']??$now),'updated_epoch'=>$now];
    if($enabled){
        vp3_phone_agent_email_calls_check_v1(($input['confirmed']??null)===true,'consent_required',422);
        vp3_phone_agent_email_calls_check_v1(is_string($input['selected_device_id']??null)&&is_int($input['number_id']??null)&&$input['number_id']>0,'invalid_request',422);
        $c=vp3_phone_agent_email_calls_device_context_v1($pdo,$user,$input['selected_device_id'],$now,true);$e=$c['enrollment'];
        try{$line=vp3_phone_workspace_number_v1($pdo,$c['scope'],$input['number_id'],true,true);}
        catch(VP3PhoneWorkspaceException){throw new VP3PhoneAgentEmailCallException('line_required');}
        vp3_phone_agent_email_calls_check_v1($line['status']==='active'&&$line['phone_e164']!==$c['preferences']['phone_e164'],'line_required');
        $row+=['device_id'=>$e['device_id'],'grant_id'=>$e['grant_id'],'enrollment_revision'=>(int)$e['revision'],
            'credential_hash'=>$e['credential_hash'],'owner_fingerprint'=>$e['owner_fingerprint'],
            'selected_agent_id'=>(int)$e['selected_agent_id'],'conversation_id'=>(int)$e['conversation_id'],
            'number_id'=>(int)$line['id'],'line_binding'=>hash('sha256',$line['provider_sid'].'|'.$line['phone_e164']),
            'phone_revision'=>(int)$c['preferences']['phone_revision'],'destination_hash'=>hash('sha256',$c['preferences']['phone_e164']),
            'holder_profile_id'=>$c['holder']['profile_id'],'holder_phone_revision'=>(int)$c['holder']['phone_revision']];
        $same=$old!==null;
        foreach(['device_id','grant_id','enrollment_revision','credential_hash','owner_fingerprint','selected_agent_id','conversation_id',
            'number_id','line_binding','phone_revision','destination_hash','holder_profile_id','holder_phone_revision'] as $field)
            $same=$same&&((string)($old[$field]??'')===(string)$row[$field]);
        $row['consent_epoch']=$same&&(int)$old['consent_epoch']>0?(int)$old['consent_epoch']:$now;
        foreach(['urgent','drafts'] as $kind)$row[$kind.'_since_epoch']=$row[$kind.'_enabled']
            ?($same&&!empty($old[$kind.'_enabled'])?(int)$old[$kind.'_since_epoch']:$now):0;
    }
    if($old!==null){
        $columns=array_values(array_diff(array_keys($row),['owner_user_id','created_epoch']));
        vp3_phone_sql_v1($pdo,'UPDATE phone_agent_email_call_policies_v1 SET '.implode(',',array_map(static fn($key)=>$key.'=?',$columns))
            .' WHERE owner_user_id=? AND revision=?',[...array_map(static fn($key)=>$row[$key],$columns),$owner,(int)$old['revision']]);
    }else{
        $columns=array_keys($row);vp3_phone_sql_v1($pdo,'INSERT INTO phone_agent_email_call_policies_v1('.implode(',',$columns).') VALUES('
            .implode(',',array_fill(0,count($columns),'?')).')',array_values($row));
    }
    vp3_phone_sql_v1($pdo,"UPDATE phone_agent_email_call_reviews_v1 SET state='cancelled',revision=revision+1,updated_epoch=?
        WHERE owner_user_id=? AND state IN ('armed','requested')",[$now,$owner]);
}

/** Authenticated API boundary, shared by desktop settings and the exact-cookie phone path. */
function vp3_phone_agent_email_calls_request_v1(PDO $pdo,?array $user,array $input,?string $token=null,?int $now=null): array
{
    $now??=time();$phone=$token!==null;$action=$input['action']??null;
    $fields=['state'=>[],'save'=>['expected_revision','urgent_enabled','drafts_enabled','grace_seconds','selected_device_id','number_id','confirmed'],
        'statuses'=>['item_refs'],'voice_arm'=>['request_id','draft_id','review_token','watch_replies','confirmed'],
        'voice_poll'=>['review_id'],'voice_claim'=>['review_id','review_token'],'voice_cancel'=>['review_id']];
    $common=$phone?['action','device_id','enrollment_revision','scope_revision','agent_id','conversation_id','csrf_token']
        :['action','owner_key','agent_id','conversation_id','csrf_token'];
    vp3_phone_agent_email_calls_check_v1(is_string($action)&&isset($fields[$action])&&!array_diff(array_keys($input),[...$common,...$fields[$action]]),'invalid_request',422);
    $delegated=$phone?vp3_phone_agent_device_scope_v1($pdo,(string)$token,(string)($input['device_id']??''),false,$now):null;
    if($delegated!==null)vp3_phone_agent_email_calls_check_v1($action!=='save','owner_access_changed',403);
    try{$live=$delegated['user']??vp3_phone_agent_workspace_owner_v1($pdo,$user);}
    catch(RuntimeException $error){throw new VP3PhoneAgentEmailCallException('owner_sign_in_required',$error->getCode()===403?403:401);}
    $owner=(int)$live['id'];
    vp3_phone_agent_email_calls_check_v1(is_string($input['csrf_token']??null)&&is_string($_SESSION['csrf_token']??null)
        &&hash_equals($_SESSION['csrf_token'],$input['csrf_token']),'session_changed',419);
    vp3_phone_agent_email_calls_check_v1(vp3_phone_agent_email_calls_ready_v1($pdo)&&vp3_phone_agent_workspace_ready_v1($pdo),'upgrade_required',503);
    vp3_phone_agent_email_calls_check_v1(is_int($input['agent_id']??null)&&$input['agent_id']>=0
        &&is_int($input['conversation_id']??null)&&$input['conversation_id']>0,'invalid_request',422);
    if(!$phone)vp3_phone_agent_email_calls_check_v1((is_int($input['owner_key']??null)||is_string($input['owner_key']??null))
        &&(string)$input['owner_key']===(string)$owner,'owner_access_changed',403);
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$live,$owner,$input,$token,$phone,$now,$action,$delegated):array{
        $live=$delegated!==null?vp3_phone_agent_workspace_user_v1($pdo,$owner,true):vp3_phone_agent_workspace_owner_v1($pdo,$live,true);$scope=null;$agent=$input['agent_id'];
        vp3_phone_agent_workspace_chat_v1($pdo,$owner,$agent,$input['conversation_id']);
        if($phone){
            $scope=vp3_phone_agent_workspace_scope_v1($pdo,$live,$token,(string)($input['device_id']??''),true,$now);
            vp3_phone_agent_email_calls_check_v1(is_int($input['enrollment_revision']??null)
                &&$input['enrollment_revision']===(int)$scope['enrollment']['revision']
                &&is_string($input['scope_revision']??null)&&hash_equals($scope['scope_revision'],$input['scope_revision'])
                &&$agent===(int)$scope['enrollment']['selected_agent_id'],'workspace_changed');
        }
        if(str_starts_with($action,'voice_')){
            vp3_phone_agent_email_calls_check_v1($scope!==null,'voice_review_unavailable',403);
            require_once __DIR__.'/phone-agent-email-call-review-v1.php';
            $result=vp3_phone_agent_email_call_review_request_v1($pdo,$scope,$input,$now);
        }elseif($action==='statuses'){
            vp3_phone_agent_email_calls_check_v1(is_array($input['item_refs']??null)&&array_is_list($input['item_refs'])&&count($input['item_refs'])<=40,'invalid_request',422);
            $items=[];
            foreach(array_values(array_unique($input['item_refs'],SORT_REGULAR)) as $ref){
                vp3_phone_agent_email_calls_check_v1(is_string($ref)&&preg_match('/^[a-f0-9]{32}$/D',$ref)===1,'invalid_request',422);
                $r=vp3_phone_sql_v1($pdo,'SELECT r.* FROM phone_agent_email_call_requests_v1 r
                    JOIN vp3_phone_agent_items_v1 i ON i.owner_user_id=r.owner_user_id AND i.item_ref=r.item_ref AND i.agent_id=r.selected_agent_id
                    WHERE r.owner_user_id=? AND r.selected_agent_id=? AND r.item_ref=? ORDER BY r.created_epoch DESC LIMIT 1',[$owner,$agent,$ref])->fetch(PDO::FETCH_ASSOC);
                if($r!==false){
                    try{vp3_phone_agent_workspace_chat_v1($pdo,$owner,$agent,(int)$r['conversation_id']);}
                    catch(RuntimeException){$r=false;}
                }
                $items[]=['item_ref'=>$ref,'call'=>$r===false?null:vp3_phone_agent_email_calls_call_view_v1($pdo,$r)];
            }
            $result=['ok'=>true,'items'=>$items,'active_call'=>vp3_phone_agent_email_calls_active_view_v1($pdo,$owner,$agent,$now,$scope['device']['id']??null)];
        }else{
            if($action==='save')vp3_phone_agent_email_calls_save_v1($pdo,$live,$input,$now);
            $result=vp3_phone_agent_email_calls_view_v1($pdo,$live,$agent,$now,$scope);
        }
        if($scope!==null){vp3_phone_agent_workspace_scope_current_v1($pdo,$scope,true);$result['scope']=vp3_phone_agent_email_calls_phone_envelope_v1($scope);}
        if($delegated===null)vp3_phone_agent_workspace_owner_v1($pdo,$live,true);
        return $result;
    });
}
