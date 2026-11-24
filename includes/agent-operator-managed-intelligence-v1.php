<?php
declare(strict_types=1);

/**
 * Read-only managed-call report authority. A pairing is not an identity
 * assertion about the person at the destination. Never join by caller ID.
 */
require_once __DIR__.'/agent-operator-followups-v1.php';
require_once __DIR__.'/phone-managed-recording-v1.php';

function vp3_managed_intelligence_scope_v1(PDO $pdo,array $scope,array $call):void
{
    $owner=(int)($scope['owner_user_id']??0);$agent=(int)($scope['agent_id']??-1);$cid=(int)($scope['conversation_id']??0);
    vp3_managed_callback_need_v1($owner>0&&$agent>=0&&$cid>0
        &&(!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null)
        &&($call['transport_kind']??'')==='managed_callback'&&(int)$call['owner_user_id']===$owner
        &&(int)$call['chat_agent_id']===$agent&&(int)$call['conversation_id']===$cid
        &&($agent===0||(int)$call['agent_id']===$agent),404);
    vp3_managed_callback_need_v1(has_permission('artist_listening.access',$scope['user']),403);
}
/** Verify original, affirmative, immutable recording consent before exposing evidence. */
function vp3_managed_intelligence_policy_v1(PDO $pdo,array $scope,array $job,array $call):array
{
    vp3_managed_intelligence_scope_v1($pdo,$scope,$call);
    vp3_managed_callback_need_v1($call['state']==='completed'&&(int)$call['ended_epoch']>0&&(int)$call['recording_enabled']===1
        &&(int)$job['conversation_id']===(int)$call['conversation_id']
        &&(int)$job['owner_user_id']===(int)$call['owner_user_id']
        &&(int)$job['agent_id']===(int)$call['agent_id']&&(int)$job['phone_number_id']===(int)$call['phone_number_id']
        &&$job['origin_kind']==='browser'&&(int)$job['call_id']===(int)$call['id']
        &&$job['call_public_id']===$call['public_id']
        &&hash_equals((string)$call['provider_call_sid'],(string)$job['provider_call_sid']),409);
    $policy=vp3_managed_recording_saved_policy_v1($pdo,$call,(string)$job['provider_recording_sid']);
    vp3_managed_callback_need_v1($policy===vp3_phone_recording_decode_v1($job['policy_json'])&&!empty($policy['enabled']),409);
    return $policy;
}
/** Extra, live holder permission is required for ongoing context or proactive review. */
function vp3_managed_intelligence_continuity_v1(PDO $pdo,array $scope,array $call,int $now):bool
{
    try{
        vp3_managed_intelligence_scope_v1($pdo,$scope,$call);
        $q=$pdo->prepare("SELECT p.holder_memory,p.holder_calls,p.owner_calls,p.phone_revision,p.profile_id
            FROM vp3_phone_devices_v1 d JOIN vp3_phone_communication_profiles_v1 p ON p.owner_user_id=d.owner_user_id AND p.device_id=d.id
            JOIN vp3_paired_phone_threads_v1 t ON t.owner_user_id=d.owner_user_id AND t.device_id=d.id
            JOIN chat_conversations c ON c.id=t.conversation_id AND c.user_id=t.owner_user_id AND COALESCE(c.user_agent_id,0)=t.agent_id
            WHERE d.owner_user_id=? AND d.id=? AND d.device_status='approved' AND d.expires_at>?
              AND p.profile_id=? AND p.phone_revision=? AND t.agent_id=? AND t.conversation_id=?");
        $q->execute([(int)$call['owner_user_id'],$call['paired_device_id'],$now,$call['paired_profile_id'],(int)$call['paired_phone_revision'],
            (int)$scope['agent_id'],(int)$scope['conversation_id']]);$p=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        return is_array($p)&&(int)$p['holder_memory']===1&&(int)$p['holder_calls']===1&&(int)$p['owner_calls']===1;
    }catch(Throwable){return false;}
}
/** Use only modules covered by the original recording agreement. */
function vp3_managed_intelligence_candidates_v1(array $saved,array $policy):array
{
    $allowed=array_filter((array)($policy['modules']??[]),'is_string');
    $saved['research_candidates']=array_values(array_filter(
        is_array($saved['research_candidates']??null)?$saved['research_candidates']:[],
        static fn($item):bool=>is_array($item)&&is_string($item['plugin']??null)&&in_array($item['plugin'],$allowed,true)));
    return $saved;
}
/** A bounded history of call outcomes with this connected profile, not the far-end person. */
function vp3_managed_intelligence_history_v1(PDO $pdo,array $scope,array $call,int $now):array
{
    if(!vp3_managed_intelligence_continuity_v1($pdo,$scope,$call,$now))return ['state'=>'permission_required','calls'=>[]];
    $q=$pdo->prepare("SELECT public_id,state,created_epoch,duration_seconds FROM phone_browser_calls_v1
        WHERE owner_user_id=? AND chat_agent_id=? AND conversation_id=? AND paired_device_id=? AND paired_profile_id=?
          AND paired_phone_revision=? AND transport_kind='managed_callback' AND id<>? AND created_epoch>=? AND created_epoch<=?
          AND provider_call_sid IS NOT NULL AND ended_epoch IS NOT NULL AND state IN ('completed','busy','no_answer','failed','canceled')
        ORDER BY created_epoch DESC,id DESC LIMIT 5");
    $q->execute([(int)$scope['owner_user_id'],(int)$scope['agent_id'],(int)$scope['conversation_id'],$call['paired_device_id'],$call['paired_profile_id'],
        (int)$call['paired_phone_revision'],(int)$call['id'],$now-90*86400,min($now,(int)$call['created_epoch'])]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
    return ['state'=>'available','calls'=>array_map(static fn(array $r):array=>['call_id'=>(string)$r['public_id'],'state'=>(string)$r['state'],
        'created_at'=>gmdate(DATE_ATOM,(int)$r['created_epoch']),'duration_seconds'=>max(0,min(240,(int)$r['duration_seconds']))],$rows)];
}
function vp3_managed_intelligence_report_v1(PDO $pdo,array $scope,array $job,array $call):?array
{
    // No substitute latest Chat or arbitrary message supplied by the browser.
    $q=$pdo->prepare("SELECT m.context_json FROM phone_browser_deliveries_v1 d
        JOIN chat_messages m ON m.id=d.chat_message_id AND m.conversation_id=d.conversation_id AND m.role='assistant'
        JOIN chat_conversations c ON c.id=d.conversation_id AND c.user_id=d.owner_user_id AND COALESCE(c.user_agent_id,0)=d.chat_agent_id
        WHERE d.owner_user_id=? AND d.chat_agent_id=? AND d.conversation_id=? AND d.event_key=? AND d.chat_message_id=?");
    $q->execute([(int)$scope['owner_user_id'],(int)$scope['agent_id'],(int)$scope['conversation_id'],'phone-recording-report:'.$job['public_id'],(int)$job['report_message_id']]);
    $context=vp3_phone_recording_decode_v1($q->fetchColumn())['phone_v1']??[];$q->closeCursor();
    $report=$context['report']??null;
    if(($context['kind']??null)!=='voicemail'||($context['call_id']??null)!==$call['public_id']
        ||($context['voicemail_id']??null)!=='job-'.(int)$job['id']||!is_array($report)
        ||($report['source_hash']??null)!==$job['transcript_hash']||!is_string($report['text']??null))return null;
    // The saved artifact is historical AI text, not a new instruction, reviewed
    // fact, identity assertion, or source of task execution authority.
    $text=trim(mb_substr(strip_tags($report['text']),0,9000));
    return $text!==''?['text'=>$text,'review_state'=>'unreviewed','historical'=>true]:null;
}
/**
 * Explicit private review, never a poll, model request, task mutation or
 * recording replay. The injectable reader is a server-side test seam.
 */
function vp3_managed_intelligence_review_v1(PDO $pdo,array $scope,string $public,int $now,?callable $read=null):array
{
    vp3_managed_callback_id_v1($public);
    $q=$pdo->prepare("SELECT * FROM phone_browser_calls_v1 WHERE public_id=? AND owner_user_id=? AND transport_kind='managed_callback'");
    $q->execute([$public,(int)$scope['owner_user_id']]);$call=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    vp3_managed_callback_need_v1(is_array($call),404);vp3_managed_intelligence_scope_v1($pdo,$scope,$call);
    $out=['call_id'=>$public,'coverage'=>'no_report','outcome'=>(string)$call['state'],'duration_seconds'=>max(0,min(240,(int)$call['duration_seconds'])),
        'recording_state'=>'none','transcript_url'=>'','report'=>null,'followups'=>[],'continuity'=>['state'=>'permission_required','calls'=>[]],
        'execution_enabled'=>false];
    if($call['state']!=='completed'||(int)$call['ended_epoch']<1)return $out;
    $out['continuity']=vp3_managed_intelligence_history_v1($pdo,$scope,$call,$now);
    if(!vp3_phone_recording_schema_ready_v1($pdo)){$out['coverage']='unavailable';return $out;}
    $q=$pdo->prepare("SELECT * FROM phone_recording_jobs_v1 WHERE owner_user_id=? AND origin_kind='browser' AND call_id=? AND call_public_id=? ORDER BY id DESC LIMIT 2");
    $q->execute([(int)$call['owner_user_id'],(int)$call['id'],$public]);$jobs=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
    if(!$jobs)return $out;
    if(count($jobs)!==1){$out['coverage']='unavailable';return $out;}
    $job=$jobs[0];
    try{$policy=vp3_managed_intelligence_policy_v1($pdo,$scope,$job,$call);}
    catch(RuntimeException $error){$out['coverage']='consent_unavailable';return $out;}
    $call['source_kind']='browser';$evidence=vp3_operator_recording_evidence_v1($pdo,$scope['user'],(int)$scope['agent_id'],$job,$call);
    if(!$evidence){$out['coverage']='unavailable';return $out;}
    $out['recording_state']=$evidence['state'];
    if($job['state']!=='ready'){$out['coverage']=$evidence['state'];return $out;}
    if(empty($policy['transcribe'])){$out['coverage']='transcription_off';return $out;}
    if(!$evidence['transcript_saved']||!preg_match('/^[a-f0-9]{64}$/D',(string)$job['transcript_hash'])){$out['coverage']='unavailable';return $out;}
    if($read===null){
        require_once __DIR__.'/transcript-agent-v1.php';
        $principal=(int)$scope['agent_id']>0?['kind'=>'user_agent','agent_id'=>(int)$scope['agent_id'],'owner_user_id'=>(int)$scope['owner_user_id'],'viewer_user_id'=>(int)$scope['owner_user_id']]:['kind'=>'system','viewer_user_id'=>(int)$scope['owner_user_id']];
        $read=static function(int $session)use($pdo,$scope,$principal):array{
            $s=vp3_transcript_session_v1($pdo,$scope['user'],$principal,$session);
            if((int)($s['conversation_id']??0)!==(int)$scope['conversation_id'])throw new RuntimeException('Transcript conversation changed.',409);
            return vp3_transcript_intelligence_v1($pdo,$scope['user'],$principal,$session,'research_candidates');
        };
    }
    try{$saved=$read((int)$job['session_id']);}
    catch(Throwable){$out['coverage']='unavailable';return $out;}
    if(!is_array($saved)||($saved['session_id']??null)!==(int)$job['session_id']||($saved['source_hash']??null)!==$job['transcript_hash']){
        $out['coverage']='stale';return $out;
    }
    $out['transcript_url']=url('/artist-listening.php?session='.(int)$job['session_id']);
    if(empty($policy['analyze'])){$out['coverage']='analysis_off';return $out;}
    if(($saved['fresh']??null)!==true||!$evidence['report_saved']||!preg_match('/^[a-f0-9]{64}$/D',(string)$job['analysis_hash'])){
        $out['coverage']='stale';return $out;
    }
    $out['report']=vp3_managed_intelligence_report_v1($pdo,$scope,$job,$call);
    if(!$out['report']){$out['coverage']='unavailable';return $out;}
    $out['followups']=vp3_operator_followup_items_v1($job,vp3_managed_intelligence_candidates_v1($saved,$policy));
    $out['coverage']='ready';return $out;
}
