<?php
declare(strict_types=1);

require_once __DIR__.'/profile-calls-pa3c.php';

/** Read only current owner-authorized call evidence. Call lifecycle and transcripts retain their own authorities. */
function vp3_catch_up_profile_calls_v1(PDO $pdo,array $user,array $principal,DateTimeImmutable $now): array
{
    $owner=(int)($user['id']??0);
    $kind=$principal['kind']??'';
    $agent=(int)($principal['agent_id']??0);
    if($owner<1||!in_array($kind,['system','user_agent'],true)
        ||(int)($principal['viewer_user_id']??$owner)!==$owner
        ||($kind==='system'&&$agent!==0)
        ||($kind==='user_agent'&&($agent<1||(int)($principal['owner_user_id']??0)!==$owner))
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)){
        return ['forbidden',[]];
    }
    try{$user=vp3_profile_handoff_owner_pa3($pdo,$user,0,$agent);}
    catch(VP3ProfileHandoffException $e){if($e->getCode()===403)return ['forbidden',[]];throw $e;}
    if(!has_permission('account.access',$user))return ['forbidden',[]];
    if(!vp3_profile_call_schema_ready_pa3c($pdo))return ['unavailable',[]];

    $at=(int)$now->format('Uv');
    $scope=$agent>0?' AND c.profile_agent_id=?':'';
    $args=$agent>0?[$owner,$at-7*86400000,$at,$agent]:[$owner,$at-7*86400000,$at];
    $q=$pdo->prepare("SELECT c.* FROM profile_calls_pa3c c
        JOIN profile_agent_conversations t ON t.id=c.conversation_id AND t.owner_user_id=c.owner_user_id
        WHERE c.owner_user_id=? AND c.offered_by='visitor' AND c.offer_expires_at_ms BETWEEN ? AND ?".$scope."
        AND c.accepted_at_ms IS NULL AND (c.state='offered' OR (c.state='expired' AND c.last_reason='offer_expired'))
        AND c.mode IN ('audio','video')
        AND t.status='open'
        AND NOT EXISTS (SELECT 1 FROM profile_calls_pa3c newer
            WHERE newer.owner_user_id=c.owner_user_id AND newer.conversation_id=c.conversation_id AND newer.id>c.id)
        ORDER BY c.offer_expires_at_ms DESC,c.id DESC LIMIT 21");
    $q->execute($args);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $reply=$pdo->prepare("SELECT 1 FROM profile_agent_messages
        WHERE conversation_id=? AND sender_type='owner' AND sender_user_id=? AND created_at>=? LIMIT 1");
    $items=[];
    foreach(array_slice($rows,0,20) as $call){
        if(!vp3_catch_up_profile_call_current_v1($pdo,$owner,$call))continue;
        $reply->execute([(int)$call['conversation_id'],$owner,gmdate('Y-m-d H:i:s',intdiv((int)$call['created_at_ms'],1000))]);
        if($reply->fetchColumn())continue;
        $time=intdiv((int)$call['offer_expires_at_ms'],1000);
        $items[]=vp3_catch_up_item_v1('profile-call:'.(int)$call['id'],'Missed Profile call',
            'An incoming '.($call['mode']==='video'?'video':'voice').' call expired before it was answered.',
            'Profile calls','/profile-agent.php?tab=inbox',64,$time)
            +['status'=>'missed','source_updated_at'=>gmdate(DATE_ATOM,$time)];
    }
    try{[$taskCoverage,$tasks]=vp3_catch_up_profile_call_tasks_v1($pdo,$user,$principal,$now);}
    catch(Throwable $e){$taskCoverage='unavailable';$tasks=[];}
    return [count($rows)>20||$taskCoverage==='limited'||$taskCoverage==='unavailable'?'limited':'ready',[...$items,...$tasks]];
}

function vp3_catch_up_profile_call_current_v1(PDO $pdo,int $owner,array $call): bool
{
    try{vp3_profile_call_context_pa3c($pdo,$owner,(int)$call['conversation_id'],false,$call);return true;}
    catch(VP3ProfileCallException|VP3ProfileHandoffException $e){
        if(in_array($e->getCode(),[403,404],true))return false;
        throw $e;
    }
}

/** An assisted transcript can only contribute a task already saved from an explicitly reviewed finding. */
function vp3_catch_up_profile_call_tasks_v1(PDO $pdo,array $user,array $principal,DateTimeImmutable $now): array
{
    if(!personal_capability_has_v242('agent_brain.access',$user)||!has_permission('artist_listening.access',$user))return ['forbidden',[]];
    if(!vp3_profile_call_activity_schema_ready_pa3c($pdo)
        ||!vp3_agent_memory_scope_schema_ready_v410($pdo)||!table_exists('artist_transcript_sessions_v172')){
        return ['unavailable',[]];
    }
    if(!function_exists('vp3_transcript_session_v1'))require_once __DIR__.'/transcript-agent-v1.php';
    vp3_task_list_authorize_v1($pdo,$user,$principal,0);
    $owner=(int)$user['id'];
    $agent=(int)$principal['agent_id'];
    [$scope,$args]=vp3_agent_memory_scope_sql_v410($agent);
    $q=$pdo->prepare("SELECT id,subject,metadata_json,last_seen_at FROM agent_memory_items
        WHERE user_id=? AND ".$scope." AND is_active=1 AND memory_type IN ('task','commitment')
        AND metadata_json LIKE ? ORDER BY last_seen_at DESC,id DESC LIMIT 51");
    $q->execute([$owner,...$args,'%transcription_intelligence%']);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $source=$pdo->prepare("SELECT c.* FROM profile_call_assistance_pa3c a JOIN profile_calls_pa3c c ON c.public_id=a.call_id
        WHERE a.transcript_session_id=? AND c.owner_user_id=? AND c.assisted=1 AND c.accepted_at_ms IS NOT NULL
        ".($agent>0?'AND c.profile_agent_id=?':'')." LIMIT 2");
    $items=[];
    $zone=new DateTimeZone(reminder_zone_v1(user_calendar_default_timezone_v1300($pdo,$user)));
    $utc=new DateTimeZone('UTC');
    $today=$now->setTimezone($zone)->setTime(23,59,59)->getTimestamp();
    foreach(array_slice($rows,0,50) as $row){
        $meta=json_decode((string)$row['metadata_json'],true);
        if(!is_array($meta)||($meta['source_kind']??'')!=='transcription_intelligence'
            ||($meta['created_from_reviewed_item']??false)!==true
            ||!in_array($meta['task_status']??null,['open','in_progress'],true)
            ||!in_array((int)($meta['assigned_user_id']??0),[0,$owner],true))continue;
        $id=(int)$row['id'];
        $session=(int)($meta['source_session_id']??0);
        if($session<1||!vp3_task_list_task_allowed_v1($pdo,$user,$principal,$id))continue;
        $source->execute($agent>0?[$session,$owner,$agent]:[$session,$owner]);
        $calls=$source->fetchAll(PDO::FETCH_ASSOC);
        if(count($calls)!==1||!vp3_catch_up_profile_call_current_v1($pdo,$owner,$calls[0]))continue;
        try{$transcript=vp3_transcript_session_v1($pdo,$user,$principal,$session);}
        catch(RuntimeException $e){if(in_array($e->getCode(),[403,404],true))continue;throw $e;}
        $origin=json_decode((string)$transcript['metadata_json'],true);
        if(!empty($transcript['discarded_at'])||!is_array($origin)||($origin['source']??'')!=='profile_call_pa3c'
            ||($origin['profile_call_id']??'')!==(string)$calls[0]['public_id'])continue;

        $due=vp3_catch_up_timestamp_v1($meta['due_at']??null,$zone);
        $updated=vp3_catch_up_timestamp_v1($row['last_seen_at'],$utc);
        $time=$due??$updated??PHP_INT_MAX;
        $priority=$due===null?69:($due<=$today?79:65);
        $reason='A reviewed follow-up from a Profile call is still open.';
        if($due!==null){
            $date=(new DateTimeImmutable('@'.$due))->setTimezone($zone);
            $reason='A reviewed follow-up from a Profile call is due '.$date->format(strlen((string)$meta['due_at'])===10?'D, M j':'D, M j \a\t g:i A T').'.';
        }
        $items[]=vp3_catch_up_item_v1('task:'.$id,(string)$row['subject'],$reason,
            'Profile call follow-up','/artist-listening.php?session='.$session,$priority,$time)
            +['status'=>$meta['task_status'],'source_updated_at'=>$updated===null?null:gmdate(DATE_ATOM,$updated),
                '_source_revision'=>hash('sha256',json_encode(array_diff_key($row,['last_seen_at'=>true]),JSON_THROW_ON_ERROR))];
    }
    return [count($rows)>50?'limited':'ready',$items];
}
