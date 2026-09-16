<?php
declare(strict_types=1);

/**
 * VP3 Phase 18.9 — Hybrid Meeting Intelligence.
 *
 * VP3 remains the meeting/product orchestrator. Cloud-authorized meetings keep
 * using the canonical Transcription Intelligence pipeline. When the organizer's
 * compute/privacy policy requires HomeServer, this layer dispatches the exact
 * meeting.intelligence.analyze capability and stores only a bounded, sanitized
 * result projection in VP3. HomeServer private context, source excerpts,
 * provider internals and relay credentials are never persisted here.
 */
const VP3_VIDEO_MEETINGS_INTELLIGENCE_HYBRID_V1890='video-meetings-intelligence-hybrid-v1890-20260915';
const VP3_VIDEO_MEETINGS_INTELLIGENCE_HOMESERVER_OPERATION_V1890='meeting.intelligence.analyze';
const VP3_VIDEO_MEETINGS_INTELLIGENCE_HOMESERVER_CONTRACT_V1890='vp3.meeting.intelligence.v1';
const VP3_VIDEO_MEETINGS_INTELLIGENCE_HOMESERVER_APP_V1890='meeting_intelligence_homeserver_v1890';

require_once __DIR__.'/video-meetings-intelligence-v1820.php';
require_once __DIR__.'/homeserver-agent-v018.php';
require_once __DIR__.'/homeserver-capability-registry-v033.php';

function video_meeting_intelligence_hybrid_clean_text_v1890(mixed $value,int $limit): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/u',' ',(string)$value)??''),0,max(0,$limit),'…');
}

function video_meeting_intelligence_hybrid_rows_v1890(mixed $value,string $primary,array $extra=[],int $limit=12): array
{
    if(!is_array($value))return [];
    $out=[];
    foreach(array_slice($value,0,max(0,$limit)) as $raw){
        if(is_string($raw))$raw=[$primary=>$raw];
        if(!is_array($raw))continue;
        $text=video_meeting_intelligence_hybrid_clean_text_v1890($raw[$primary]??$raw['text']??$raw['title']??'',1200);
        if($text==='')continue;
        $row=[$primary=>$text];
        foreach($extra as $key=>$max){
            $clean=video_meeting_intelligence_hybrid_clean_text_v1890($raw[$key]??'',(int)$max);
            if($clean!=='')$row[$key]=$clean;
        }
        $out[]=$row;
    }
    return $out;
}

function video_meeting_intelligence_hybrid_sanitize_snapshot_v1890(mixed $raw): array
{
    $raw=is_array($raw)?$raw:[];
    return [
        'summary'=>video_meeting_intelligence_hybrid_clean_text_v1890($raw['summary']??'',6000),
        'key_points'=>video_meeting_intelligence_hybrid_rows_v1890($raw['key_points']??[],'text',[],12),
        'decisions'=>video_meeting_intelligence_hybrid_rows_v1890($raw['decisions']??[],'decision',[],12),
        'actions'=>video_meeting_intelligence_hybrid_rows_v1890($raw['actions']??[],'action',['owner'=>500,'due_date'=>500],12),
        'questions'=>video_meeting_intelligence_hybrid_rows_v1890($raw['questions']??[],'question',[],12),
        'risks'=>video_meeting_intelligence_hybrid_rows_v1890($raw['risks']??[],'risk',[],12),
        'topics'=>video_meeting_intelligence_hybrid_rows_v1890($raw['topics']??[],'topic',[],12),
        'crm_candidates'=>video_meeting_intelligence_hybrid_rows_v1890($raw['crm_candidates']??[],'suggested_update',['contact'=>500,'signal'=>500],12),
        'task_candidates'=>video_meeting_intelligence_hybrid_rows_v1890($raw['task_candidates']??[],'title',['owner'=>500,'due_date'=>500],12),
        'follow_up_draft'=>video_meeting_intelligence_hybrid_clean_text_v1890($raw['follow_up_draft']??'',6000),
        'agent_brief'=>video_meeting_intelligence_hybrid_clean_text_v1890($raw['agent_brief']??'',6000),
    ];
}

function video_meeting_intelligence_hybrid_segments_v1890(PDO $pdo,array $meeting): array
{
    $stmt=$pdo->prepare("SELECT speaker_name,start_ms,end_ms,transcript_text FROM video_meeting_transcript_segments WHERE meeting_id=? AND is_final=1 AND TRIM(transcript_text)<>'' ORDER BY id ASC LIMIT 501");
    $stmt->execute([(int)$meeting['id']]);
    $rows=$stmt->fetchAll()?:[];
    if(count($rows)>500)throw new RuntimeException('This meeting transcript is too large for one private intelligence request.');
    $segments=[];$chars=0;
    foreach($rows as $row){
        if(!is_array($row))continue;
        $text=video_meeting_intelligence_hybrid_clean_text_v1890($row['transcript_text']??'',8000);
        if($text==='')continue;
        $chars+=mb_strlen($text);
        if($chars>120000)throw new RuntimeException('This meeting transcript is too large for one private intelligence request.');
        $start=max(0,(int)($row['start_ms']??0));$end=max($start,(int)($row['end_ms']??$start));
        $speaker=video_meeting_intelligence_hybrid_clean_text_v1890($row['speaker_name']??'',120)?:'Participant';
        $segments[]=['speaker_name'=>$speaker,'start_ms'=>$start,'end_ms'=>$end,'text'=>$text];
    }
    return $segments;
}

function video_meeting_intelligence_hybrid_policy_requires_home_v1890(array $policy): bool
{
    $requested=strtolower(trim((string)($policy['requested_compute']??'')));
    return in_array($requested,['homeserver','homeserver_only'],true)
        || (!empty($policy['policy_resolved'])&&($policy['cloud_ai_allowed']??null)===false&&$requested!=='vp3_cloud');
}

function video_meeting_intelligence_hybrid_route_v1890(PDO $pdo,array $meeting,array $source,bool $forceRefresh=false): array
{
    $policy=video_meeting_intelligence_policy_v1820($pdo,$meeting,$source);
    $requested=strtolower(trim((string)($policy['requested_compute']??'auto')))?:'auto';
    $resolved=!empty($policy['policy_resolved']);
    $cloudAllowed=($policy['cloud_ai_allowed']??null)===true;
    $base=[
        'version'=>'v18.9','route'=>'blocked','status'=>'blocked','reason_code'=>'no_authorized_processing_route',
        'requested_compute'=>$requested,'policy_resolved'=>$resolved,'cloud_allowed'=>$cloudAllowed,
        'homeserver_required'=>false,'homeserver_available'=>false,'capability_advertised'=>false,'ready'=>false,
    ];
    if(empty($meeting['transcription_enabled']))return array_replace($base,['status'=>'off','reason_code'=>'transcription_disabled']);
    if(!$resolved)return array_replace($base,['status'=>'pending','reason_code'=>'policy_pending']);

    $requiresHome=video_meeting_intelligence_hybrid_policy_requires_home_v1890($policy);
    $base['homeserver_required']=$requiresHome;
    if(!$requiresHome&&$cloudAllowed){
        return array_replace($base,['route'=>'cloud','status'=>'ready','reason_code'=>'cloud_authorized','ready'=>true]);
    }
    if(!$requiresHome){
        return array_replace($base,['route'=>'blocked','status'=>'blocked','reason_code'=>'explicit_cloud_route_not_authorized']);
    }

    // Never probe another user's private HomeServer from attendee/guest context.
    $viewer=function_exists('current_user')?current_user():null;
    if((int)($viewer['id']??0)!==(int)($meeting['owner_user_id']??0)){
        return array_replace($base,['route'=>'blocked','status'=>'private_required','reason_code'=>'organizer_private_route']);
    }

    $registry=homeserver_capability_v033_registry((int)$meeting['owner_user_id'],$forceRefresh);
    $available=!empty($registry['available']);
    $advertised=$available&&in_array(VP3_VIDEO_MEETINGS_INTELLIGENCE_HOMESERVER_OPERATION_V1890,(array)($registry['operations']??[]),true);
    $credentials=$advertised&&function_exists('homeserver_agent_v018_credentials')?homeserver_agent_v018_credentials((int)$meeting['owner_user_id']):null;
    $ready=$advertised&&is_array($credentials)&&trim((string)($credentials['relay']??''))!==''&&trim((string)($credentials['home']??''))!=='';
    $base['homeserver_available']=$available;$base['capability_advertised']=$advertised;
    if($ready){
        return array_replace($base,['route'=>'homeserver','status'=>'ready','reason_code'=>'homeserver_private_ready','ready'=>true]);
    }
    return array_replace($base,[
        'route'=>'blocked','status'=>'required_unavailable',
        'reason_code'=>!$available?'homeserver_unavailable':(!$advertised?'intelligence_capability_unavailable':'homeserver_credentials_unavailable'),
    ]);
}

function video_meeting_intelligence_hybrid_public_route_v1890(array $route): array
{
    return [
        'version'=>'v18.9','route'=>(string)($route['route']??'blocked'),'status'=>(string)($route['status']??'blocked'),
        'reason_code'=>(string)($route['reason_code']??'no_authorized_processing_route'),
        'requested_compute'=>(string)($route['requested_compute']??'auto'),'policy_resolved'=>!empty($route['policy_resolved']),
        'cloud_allowed'=>!empty($route['cloud_allowed']),'homeserver_required'=>!empty($route['homeserver_required']),
        'homeserver_available'=>!empty($route['homeserver_available']),'capability_advertised'=>!empty($route['capability_advertised']),
        'ready'=>!empty($route['ready']),
    ];
}

function video_meeting_intelligence_hybrid_validate_response_v1890(array $result,array $meeting,string $sourceHash,string $mode,string $idempotencyKey): array
{
    if(($result['ok']??null)===true&&is_array($result['payload']??null))$result=$result['payload'];
    $checks=[
        [(string)($result['contract']??''),VP3_VIDEO_MEETINGS_INTELLIGENCE_HOMESERVER_CONTRACT_V1890,'contract'],
        [(string)($result['operation']??''),VP3_VIDEO_MEETINGS_INTELLIGENCE_HOMESERVER_OPERATION_V1890,'operation'],
        [strtolower((string)($result['meeting']??'')),strtolower((string)$meeting['public_id']),'meeting'],
        [strtolower((string)($result['source_hash']??'')),strtolower($sourceHash),'source hash'],
        [strtolower((string)($result['mode']??'')),$mode,'mode'],
        [strtolower((string)($result['idempotency_key']??'')),strtolower($idempotencyKey),'idempotency key'],
        [strtolower((string)($result['route']??'')),'homeserver','route'],
        [strtolower((string)($result['compute_source']??'')),'homeserver_local','compute source'],
    ];
    foreach($checks as [$actual,$expected,$label]){
        if($actual===''||!hash_equals($expected,$actual))throw new RuntimeException('HomeServer Meeting Intelligence returned an invalid '.$label.' binding.');
    }
    $snapshot=video_meeting_intelligence_hybrid_sanitize_snapshot_v1890($result['snapshot']??[]);
    if($snapshot['summary']==='')throw new RuntimeException('HomeServer Meeting Intelligence returned no usable summary.');
    return [
        'version'=>'v18.9','source'=>'homeserver','route'=>'homeserver','compute_source'=>'homeserver_local',
        'meeting'=>(string)$meeting['public_id'],'source_hash'=>$sourceHash,'mode'=>$mode,
        'idempotency_key'=>$idempotencyKey,'generated_at'=>gmdate('c'),'snapshot'=>$snapshot,
    ];
}

function video_meeting_intelligence_hybrid_store_v1890(PDO $pdo,array $meeting,array $sanitized): void
{
    $json=json_encode($sanitized,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))throw new RuntimeException('Could not store sanitized private meeting intelligence.');
    $stmt=$pdo->prepare("INSERT INTO video_meeting_artifacts (meeting_id,app_id,artifact_type,result_json,source_hash,generated_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE result_json=VALUES(result_json),artifact_type=VALUES(artifact_type),generated_at=NOW()");
    $stmt->execute([(int)$meeting['id'],VP3_VIDEO_MEETINGS_INTELLIGENCE_HOMESERVER_APP_V1890,'sanitized_private_analysis',$json,(string)$sanitized['source_hash']]);
}

function video_meeting_intelligence_hybrid_artifact_v1890(PDO $pdo,array $meeting,string $sourceHash): ?array
{
    if($sourceHash==='')return null;
    $stmt=$pdo->prepare('SELECT result_json,generated_at FROM video_meeting_artifacts WHERE meeting_id=? AND app_id=? AND source_hash=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int)$meeting['id'],VP3_VIDEO_MEETINGS_INTELLIGENCE_HOMESERVER_APP_V1890,$sourceHash]);
    $row=$stmt->fetch();if(!is_array($row))return null;
    $decoded=json_decode((string)($row['result_json']??''),true);if(!is_array($decoded)||($decoded['source']??'')!=='homeserver')return null;
    $decoded['generated_at']=(string)($decoded['generated_at']??$row['generated_at']??'');
    return $decoded;
}

function video_meeting_intelligence_hybrid_snapshot_v1890(array $artifact): array
{
    $snap=video_meeting_intelligence_hybrid_sanitize_snapshot_v1890($artifact['snapshot']??[]);
    $tasks=[];
    foreach((array)$snap['task_candidates'] as $row){
        if(!is_array($row))continue;
        $tasks[]=['action'=>(string)($row['title']??''),'owner'=>(string)($row['owner']??''),'due_date'=>(string)($row['due_date']??''),'source'=>'task_candidate'];
    }
    $crm=[];
    foreach((array)$snap['crm_candidates'] as $row){
        if(!is_array($row))continue;
        $crm[]=['action'=>(string)($row['suggested_update']??''),'contact'=>(string)($row['contact']??''),'signal'=>(string)($row['signal']??''),'review_state'=>'candidate'];
    }
    return [
        'fresh'=>true,'summary'=>(string)$snap['summary'],'key_points'=>(array)$snap['key_points'],
        'decisions'=>(array)$snap['decisions'],'actions'=>array_slice(array_merge((array)$snap['actions'],$tasks),0,24),
        'questions'=>(array)$snap['questions'],'answered_questions'=>[],'risks'=>(array)$snap['risks'],'topics'=>(array)$snap['topics'],
        'crm'=>['matched_contacts'=>[],'signals'=>[],'next_best_actions'=>$crm,'recommended_updates'=>[]],
        'task_candidates'=>(array)$snap['task_candidates'],'follow_up_draft'=>(string)$snap['follow_up_draft'],'agent_brief'=>(string)$snap['agent_brief'],
        'plugins'=>[['id'=>'homeserver_private','generated_at'=>(string)($artifact['generated_at']??''),'provider'=>'HomeServer','model'=>'local','source_hash'=>(string)($artifact['source_hash']??'')]],
        'generated_at'=>(string)($artifact['generated_at']??''),'provider'=>'HomeServer','model'=>'local',
    ];
}

function video_meeting_intelligence_public_state_v1890(PDO $pdo,array $meeting,bool $forceRefresh=false): array
{
    $state=video_meeting_intelligence_public_state_v1820($pdo,$meeting);
    $source=video_meeting_intelligence_source_v1820($pdo,$meeting);
    $route=video_meeting_intelligence_hybrid_route_v1890($pdo,$meeting,$source,$forceRefresh);
    $state['hybrid_intelligence']=video_meeting_intelligence_hybrid_public_route_v1890($route);
    $artifact=video_meeting_intelligence_hybrid_artifact_v1890($pdo,$meeting,(string)($source['source_hash']??''));
    if($artifact!==null){
        $state['snapshot']=video_meeting_intelligence_hybrid_snapshot_v1890($artifact);
        $state['hybrid_intelligence']['source']='homeserver';
        $state['hybrid_intelligence']['mode']=(string)($artifact['mode']??'');
        $state['hybrid_intelligence']['generated_at']=(string)($artifact['generated_at']??'');
    }else{
        $state['hybrid_intelligence']['source']=$route['route']==='cloud'?'vp3_cloud':'';
    }
    return $state;
}

function video_meeting_intelligence_hybrid_set_error_v1890(PDO $pdo,array $meeting,string $message): void
{
    $message=mb_strimwidth(trim($message),0,1000,'…');
    $pdo->prepare("INSERT INTO video_meeting_intelligence_state (meeting_id,last_error) VALUES (?,?) ON DUPLICATE KEY UPDATE last_error=VALUES(last_error),updated_at=NOW()")
        ->execute([(int)$meeting['id'],$message]);
}

function video_meeting_intelligence_run_homeserver_v1890(PDO $pdo,array $meeting,string $mode,string $submittedHash): array
{
    $mode=$mode==='final'?'final':'live';
    if($mode==='final'&&!in_array((string)($meeting['status']??''),['ended','processed'],true))throw new RuntimeException('Final meeting intelligence is only available after the meeting ends.');
    $source=video_meeting_intelligence_source_v1820($pdo,$meeting);$sourceHash=(string)($source['source_hash']??'');
    if($sourceHash===''||$submittedHash===''||!hash_equals($sourceHash,$submittedHash))throw new RuntimeException('The meeting transcript changed while intelligence was running. Refresh and try again.');
    $route=video_meeting_intelligence_hybrid_route_v1890($pdo,$meeting,$source,true);
    if(($route['route']??'')!=='homeserver'||empty($route['ready'])){
        throw new RuntimeException('Private HomeServer Meeting Intelligence is required but is not ready.');
    }
    $segments=video_meeting_intelligence_hybrid_segments_v1890($pdo,$meeting);
    if(!$segments)throw new RuntimeException('The meeting transcript does not contain analyzable text.');
    $credentials=homeserver_agent_v018_credentials((int)$meeting['owner_user_id']);
    if(!$credentials)throw new RuntimeException('Private HomeServer Meeting Intelligence is not connected.');
    $idempotencyKey='vp3-meeting-intelligence:'.strtolower((string)$meeting['public_id']).':'.strtolower($sourceHash);
    $payload=[
        'contract'=>VP3_VIDEO_MEETINGS_INTELLIGENCE_HOMESERVER_CONTRACT_V1890,
        'meeting'=>strtolower((string)$meeting['public_id']),'source_hash'=>strtolower($sourceHash),'mode'=>$mode,
        'idempotency_key'=>$idempotencyKey,'cloud_processing_allowed'=>false,'requested_compute'=>'homeserver',
        'title'=>mb_strimwidth(trim((string)($meeting['title']??'Meeting')),0,240,''),'segments'=>$segments,
    ];
    try{
        $result=homeserver_vp3_remote_operation((string)$credentials['relay'],VP3_VIDEO_MEETINGS_INTELLIGENCE_HOMESERVER_OPERATION_V1890,$payload,(string)$credentials['home']);
        $sanitized=video_meeting_intelligence_hybrid_validate_response_v1890($result,$meeting,$sourceHash,$mode,$idempotencyKey);
        video_meeting_intelligence_hybrid_store_v1890($pdo,$meeting,$sanitized);
        video_meeting_intelligence_record_analysis_v1820($pdo,$meeting,$mode,$sourceHash,'');
        return video_meeting_intelligence_public_state_v1890($pdo,$meeting);
    }catch(Throwable $e){
        video_meeting_intelligence_hybrid_set_error_v1890($pdo,$meeting,$e->getMessage());
        throw $e;
    }
}
