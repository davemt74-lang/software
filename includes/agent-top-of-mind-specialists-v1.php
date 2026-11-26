<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-specialist-registry-v1.php';

function vp3_top_of_mind_tool_specialist_v1(array $goal): string
{
    if(($goal['opportunity_lane']??null)==='missions')return 'mission';
    return vp3_initiative_goal_specialist_v1($goal)??'';
}

/** Keep the rail's task work current without hydrating the rest of the activity feed. */
function vp3_top_of_mind_tasks_v1(PDO $pdo,array $user,int $cid,array $policy,array $health,int $now): ?array
{
    if(!agent_brain_schema_ready()||!personal_capability_has_v242('agent_brain.access',$user))return null;
    $status=['id'=>'tasks','state'=>'idle','detail'=>'Ready to review tasks, lists, and reminders.','progress'=>null];
    if(($health['state']??null)==='paused'&&($policy['conversation_id']??null)===$cid){
        return array_replace($status,['state'=>'paused','detail'=>'Background task, list, and reminder reviews are paused.']);
    }
    if(empty($health['enabled'])||($policy['conversation_id']??null)!==$cid)return $status;
    $owner=(int)$user['id'];
    try{
        $live=vp3_initiative_access_v1($pdo,$user,$policy);
        $candidates=[];
        foreach(array_merge(vp3_initiative_runtime_runs_v1($pdo,$owner,(int)($policy['agent_id']??0),$policy,true),
            vp3_initiative_runtime_runs_v1($pdo,$owner,(int)($policy['agent_id']??0),$policy)) as $run){
            $goal=json_decode((string)($run['goal']??''),true);
            if(vp3_top_of_mind_tool_specialist_v1(is_array($goal)?$goal:[])!=='tasks'||($run['capability_key']??null)!=='initiative.prepare')continue;
            $primary=$goal['primary']??null;
            if(!is_string($primary)||!preg_match('/\A(?:task(?:-list)?|reminder):[1-9][0-9]{0,17}\z/D',$primary)
                ||!is_array($goal['sources']??null)||array_keys($goal['sources'])!==[$primary])continue;
            $state=(string)$run['status'];
            $updated=vp3_top_of_mind_lease_time_v1($run['updated_at']??$run['completed_at']??null);
            if(!in_array($state,['executing','approved','queued'],true)&&($updated<=$now-120||$updated>$now))continue;
            $candidates[(int)$run['id']]=[$run,$goal];
        }
        $rank=static fn(string $state):int=>match($state){'executing'=>0,'approved','queued'=>1,'completed'=>2,default=>3};
        uasort($candidates,static fn(array $a,array $b):int=>($rank($a[0]['status'])<=>$rank($b[0]['status']))?:($b[0]['id']<=>$a[0]['id']));
        $candidates=array_slice($candidates,0,4,true);
        require_once __DIR__.'/agent-initiative-task-reports-v1.php';
        $classified=vp3_initiative_task_report_classifications_v1($pdo,$live,$policy,
            array_filter(array_column($candidates,0),static fn(array $run):bool=>$run['status']==='completed'),$now);
        foreach($candidates as [$run,$goal]){
            try{
                if($run['status']==='completed'&&vp3_initiative_task_report_superseded_v1($run,$classified))continue;
                vp3_initiative_work_sources_v1($pdo,$live,$policy,$goal);
                $statusRow=vp3_top_of_mind_task_run_v1($pdo,$owner,$cid,$run,$goal,$live,$policy,$now);
                if($statusRow!==null)return array_replace($status,$statusRow);
            }catch(RuntimeException|JsonException $error){continue;}
        }
    }catch(RuntimeException $error){
        return array_replace($status,['state'=>'unavailable','detail'=>'Task, list, and reminder review status is unavailable.']);
    }
    return $status;
}

function vp3_top_of_mind_task_run_v1(PDO $pdo,int $owner,int $cid,array $run,array $goal,array $live,array $policy,int $now): ?array
{
    $runId=(int)$run['id'];
    $q=$pdo->prepare("SELECT * FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? AND sequence_no=1
        AND action_key='initiative.prepare' AND capability_key='initiative.prepare' AND execution_target='cloud' LIMIT 1");
    $q->execute([$runId,$owner]);
    $action=$q->fetch(PDO::FETCH_ASSOC);
    if(!$action)return null;
    $result=json_decode((string)($action['result_json']??''),true);
    $result=is_array($result)?$result:[];
    $receipt=null;
    if(in_array($run['status'],['completed','failed'],true)){
        $q=$pdo->prepare("SELECT status,result_json FROM agent_workflow_receipts WHERE run_id=? AND action_id=? AND owner_user_id=?
            AND attempt_no=? AND executor='cloud' ORDER BY id DESC LIMIT 1");
        $q->execute([$runId,(int)$action['id'],$owner,(int)$action['attempt_count']]);
        $receipt=$q->fetch(PDO::FETCH_ASSOC)?:null;
        if(($receipt['status']??null)!==$run['status']||($receipt['result_json']??null)!==($action['result_json']??null))return null;
    }
    $state=vp3_top_of_mind_state_v1($run,$action,$receipt,$result,$now);
    if(in_array($state,['ready_to_report','completed'],true)
        &&in_array($result['initiative_tool_result']['output']['office_disposition']??null,['note','ignore'],true))return null;
    if($state==='queued'&&($action['status']??null)!=='queued')return null;
    $taskTitle='';
    if(isset($goal['office_agent_task'])){
        require_once __DIR__.'/agent-initiative-office-entry-work-v1.php';
        $task=vp3_initiative_office_agent_task_parent_v1($pdo,$live,$goal);
        $taskTitle=vp3_top_of_mind_text_v1($task['title'],160);
    }
    $out=['state'=>$state,'run_id'=>$runId,'detail'=>match($state){
        'working'=>$taskTitle!==''?'Agent Task in progress · '.$taskTitle:'Reviewing a saved task, list, or reminder.',
        'queued'=>$taskTitle!==''?'Agent Task queued · '.$taskTitle:'A task, list, or reminder review is queued.',
        'ready_to_report','completed'=>$taskTitle!==''?'Agent Task completed · '.$taskTitle:'A verified task, list, or reminder review is ready.',
        'failed'=>$taskTitle!==''?'Agent Task failed · '.$taskTitle:'The latest task, list, or reminder review could not be verified.',
        default=>'',
    }];
    if($out['detail']==='')return null;
    if($state==='working')$out['lease_expires_at']=gmdate(DATE_ATOM,vp3_top_of_mind_lease_time_v1($run['lease_expires_at']));
    if(in_array($state,['ready_to_report','completed'],true)){
        vp3_initiative_result_current_v1($pdo,$live,$policy,$goal,$result['initiative_tool_result']??[]);
        $out['state']='ready';
        $messageId=(int)($result['initiative_message_id']??0);
        if($messageId>0){
            $q=$pdo->prepare("SELECT context_json FROM chat_messages WHERE id=? AND conversation_id=? AND role='assistant' LIMIT 1");
            $q->execute([$messageId,$cid]);
            $context=json_decode((string)$q->fetchColumn(),true);
            $marker=$context['initiative_work']??[];
            $agentId=(int)($policy['agent_id']??0);
            if(($context['source']??null)==='agent_initiative'&&($context['initiative_run_id']??null)===$runId
                &&($marker['owner_user_id']??null)===$owner&&($marker['agent_id']??null)===$agentId
                &&($marker['conversation_id']??null)===$cid&&($marker['run_id']??null)===$runId){
                $out['message_id']=$messageId;
                $out['conversation_id']=$cid;
                $out['result_url']=vp3_top_of_mind_media_href_v1($agentId,$cid,$messageId);
            }
        }
    }
    return $out;
}

/** Coordination work belongs to the specialist that supplied its primary finding. */
function vp3_top_of_mind_coordination_specialists_v1(PDO $pdo,array $user,int $agentId,array $policy,int $now): array
{
    require_once __DIR__.'/agent-coordination-work-v1.php';
    require_once __DIR__.'/agent-coordination-sources-v1.php';
    $names=['gmail'=>'the current Gmail finding','tasks'=>'a task, list, or reminder finding',
        'planning'=>'the current schedule','media'=>'a VP3 Media suggestion','canvas'=>'a private Canvas app record',
        'curiosity'=>'the next Curiosity question','connected-sites'=>'a connected POS finding','marketing'=>'a campaign recommendation',
        'transcripts'=>'a saved transcription finding','profile'=>'verified visitor conversations, calls and follow-ups'];
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $json=static function(string $path,string $column='r.goal')use($sqlite):string{
        $value="JSON_EXTRACT(CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END,'$path')";
        return $sqlite?$value:"JSON_UNQUOTE($value)";
    };
    $specialist=$json('$.coordination_route.specialist');
    $primarySource=$json('$.primary');
    $conversation=$json('$.binding.conversation_id');
    $revision=$json('$.binding.revision');
    $delivery=$json('$.initiative_delivery','a.result_json');
    $owner=(int)$user['id'];
    $q=$pdo->prepare("SELECT r.id,r.goal,r.status,r.source_key,r.lease_expires_at
        FROM agent_workflow_runs r JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
        AND a.sequence_no=1 AND a.action_key='initiative.prepare' AND a.capability_key='initiative.prepare' AND a.execution_target='cloud'
        WHERE r.owner_user_id=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=?
        AND r.source_kind='agent_initiative' AND r.source_key LIKE 'coordination:%' AND r.workflow_type='initiative'
        AND r.capability_key='initiative.prepare' AND r.execution_target='cloud' AND CAST($conversation AS CHAR)=? AND $revision=?
        AND ($specialist IN ('gmail','tasks','planning','media','canvas','curiosity','connected-sites','transcripts','marketing','profile') OR ($specialist='casual'
            AND $primarySource IN ('casual:media','casual:music','casual:youtube','casual:youtube_history')))
        AND ((r.status='executing' AND a.status='executing' AND r.current_action_id=a.id AND r.lease_expires_at>?)
            OR (r.status IN ('approved','queued') AND a.status='queued')
            OR (r.status='completed' AND a.status='completed' AND a.completed_at<=?
                AND ($delivery='pending' OR ($delivery='private' AND a.completed_at>=?))))
        ORDER BY CASE r.status WHEN 'executing' THEN 0 WHEN 'completed' THEN 2 ELSE 1 END,r.id DESC LIMIT 24");
    $stamp=gmdate('Y-m-d H:i:s',$now);
    $q->execute([$owner,(string)$agentId,(string)$policy['conversation_id'],$policy['revision'],
        $stamp,$stamp,gmdate('Y-m-d H:i:s',$now-120)]);
    $out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $run){
        $goal=json_decode((string)$run['goal'],true);
        $id=$goal['coordination_route']['specialist']??'';
        $primary=$goal['primary']??'';
        $mediaInvitation=in_array($primary,['casual:media','casual:music','casual:youtube','casual:youtube_history'],true)
            &&($goal['coordination_route']??null)===['specialist'=>'casual','trigger'=>substr($primary,strlen('casual:')),'action'=>'ask'];
        if($mediaInvitation)$id='media';
        $family=match($id){'tasks'=>'office','transcripts'=>'transcript',default=>$id};
        if(!is_string($id)||isset($out[$id])||!isset($names[$id])||($goal['binding']??null)!==$policy||($goal['tool']??null)!=='coordination.review'
            ||($run['source_key']??null)!=='coordination:'.($goal['coordination_id']??'')
            ||!is_string($primary)||(!$mediaInvitation&&!str_starts_with($primary,$family.':'))
            ||!vp3_coordination_source_ref_valid_v1($primary)||!isset($goal['sources'][$primary]))continue;
        $state=match($run['status']){'executing'=>'working','completed'=>'ready',default=>'queued'};
        $detail=match($state){'working'=>'Reviewing '.$names[$id].'.','queued'=>'A review of '.$names[$id].' is queued.',
            default=>'A verified review of '.$names[$id].' is ready.'};
        if($state==='ready'){
            $result=vp3_coordination_verified_run_v1($pdo,$user,$run);
            // Posted questions live in the engagement list. They do not own a worker state.
            if($result===null||!in_array($result['initiative_delivery']??null,['pending','private'],true))continue;
            try{
                $work=$result['initiative_tool_result'];
                $evidence=vp3_coordination_result_evidence_v1($pdo,$user,$policy,$goal,$work);
                if($result['initiative_delivery']==='pending'){
                    vp3_coordination_route_current_v1($pdo,$user,$goal,$evidence);
                    $state='ready_to_report';$detail='A verified finding is waiting to post in Chat.';
                }
            }catch(RuntimeException|JsonException $error){continue;}
        }
        $row=['id'=>$id,'state'=>$state,'detail'=>$detail,'progress'=>null,'run_id'=>(int)$run['id'],'conversation_id'=>$policy['conversation_id'],
            'lease_expires_at'=>$state==='working'?gmdate(DATE_ATOM,vp3_top_of_mind_lease_time_v1($run['lease_expires_at'])):''];
        $out[$id]=$row;
    }
    return $out;
}

function vp3_top_of_mind_prefer_work_v1(?array $current,array $candidate): array
{
    $order=['working'=>0,'waiting_owner'=>1,'waiting_specialist'=>2,'queued'=>3,'ready_to_report'=>4,'waiting_reply'=>5,
        'ready'=>6,'completed'=>6,'blocked'=>7,'failed'=>7,'unavailable'=>8,'paused'=>8,'checked'=>9,'idle'=>10];
    return $current!==null&&($order[$current['state']]??10)<=($order[$candidate['state']]??10)?$current:$candidate;
}

const VP3_TOP_OF_MIND_ENGAGEMENT_SPECIALISTS_V1=['gmail','planning','tasks','media','canvas','curiosity','research',
    'image-generator','coding','mission','connected-sites','ob1','brainstorm','development','transcripts','marketing','commerce','profile'];
const VP3_TOP_OF_MIND_ENGAGEMENT_PAGE_SIZE_V1=8;

/** These exact source names retain the originating specialist for casual or starter questions. */
function vp3_top_of_mind_engagement_casual_specialists_v1(): array
{
    require_once __DIR__.'/agent-specialist-starters-v1.php';
    $families=['casual:email'=>'gmail','casual:today'=>'planning','casual:resume'=>'tasks',
        'casual:music'=>'media','casual:youtube'=>'media','casual:youtube_history'=>'media','casual:media'=>'media'];
    foreach(VP3_SPECIALIST_STARTERS_V1 as $trigger=>$starter)$families['casual:'.$trigger]=$starter['specialist'];
    return $families;
}

/** The candidate window is an index hint, never authorization to read or act on a question. */
function vp3_top_of_mind_open_engagement_candidates_v1(PDO $pdo,int $owner,int $agentId,int $cid,?string $specialist=null,
    int $before=0,?int $postedBefore=null,array $excludedMessages=[],bool $includeMoreFamilies=true): array
{
    if(!agent_job_engine_schema_ready_v1900($pdo))throw new RuntimeException('Engagement receipts are unavailable.',503);
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $json=static function(string $column,string $path)use($sqlite):string{
        $value="JSON_EXTRACT(CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END,'$path')";
        return $sqlite?$value:"JSON_UNQUOTE($value)";
    };
    $runId=$json('m.context_json','$.initiative_work.run_id');
    $source=$json('m.context_json','$.source');
    $state=$json('m.context_json','$.initiative_followthrough_state.state');
    $clarification=$json('m.context_json','$.initiative_followthrough_state.followup_clarification');
    $output=$json('m.context_json','$.initiative_output.type');
    $ownerQuestion=$json('m.context_json','$.initiative_output.state');
    $tool=$json('r.goal','$.tool');
    $primary=$json('r.goal','$.primary');
    $route=$json('r.goal','$.coordination_route.specialist');
    $routeAction=$json('r.goal','$.coordination_route.action');
    $routeTrigger=$json('r.goal','$.coordination_route.trigger');
    $nativeType=$sqlite?'INTEGER':'UNSIGNED';
    $casual=vp3_top_of_mind_engagement_casual_specialists_v1();
    $casualCases=[];
    foreach($casual as $ref=>$family){
        $trigger=substr($ref,strlen('casual:'));
        $casualCases[]="WHEN $primary='$ref' AND $routeTrigger='$trigger' THEN '$family'";
    }
    // The candidate filter does not authorize content. The existing reply loader checks the
    // original message, exact owner/Agent/Chat, receipt, live grant and source revisions below.
    $followupQuestion=$json('m.context_json','$.initiative_output.followup_question');
    $familySql="CASE WHEN $output='initiative_coordination' AND $ownerQuestion='needs_owner' AND $tool='coordination.review' THEN
        CASE WHEN $route='casual' AND $routeAction='ask' THEN CASE ".implode(' ',$casualCases)." END
        WHEN $route IN ('gmail','planning','tasks','media','canvas','curiosity','connected-sites','transcripts','marketing','profile') AND $routeAction='ask' THEN $route END
        WHEN $output='initiative_capability_read' AND $tool IN ('tasks.read','tasks.lists.read','reminders.read')
            AND $state='open' AND $followupQuestion IS NOT NULL THEN 'tasks' END";
    $cursorSql=$before>0?'AND m.id<?':'';
    $postedAt='CAST('.vp3_initiative_db_wall_epoch_sql_v1($pdo,'m.created_at')." AS $nativeType)";
    $ageSql=$postedBefore===null?'':"AND $postedAt<=?";
    $exclusionSql=$excludedMessages?'AND m.id NOT IN ('.implode(',',array_fill(0,count($excludedMessages),'?')).')':'';
    $familySqlFilter=$specialist===null?'':' AND candidates.specialist=?';
    $limit=$specialist===null?32:24;
    $args=[$owner,$agentId,$cid];
    if($before>0)$args[]=$before;
    if($postedBefore!==null)$args[]=$postedBefore;
    $args=[...$args,...$excludedMessages];
    if($specialist!==null)$args[]=$specialist;
    $selector="FROM (
        SELECT m.id,$familySql AS specialist,$postedAt AS posted_at FROM chat_messages m JOIN agent_workflow_runs r
            ON r.id=CAST($runId AS $nativeType) AND r.owner_user_id=? AND COALESCE(r.agent_id,0)=?
            AND r.source_kind='agent_initiative' AND r.status='completed' AND r.capability_key='initiative.prepare'
        WHERE m.conversation_id=? AND m.role='assistant' AND m.user_id IS NULL AND $source='agent_initiative'
            AND ($state='open' OR ($state='discussed' AND COALESCE($clarification,'')<>'')) $cursorSql $ageSql $exclusionSql
    ) candidates WHERE candidates.specialist IS NOT NULL $familySqlFilter";
    $q=$pdo->prepare('SELECT candidates.id,candidates.specialist,candidates.posted_at '.$selector.' ORDER BY candidates.id DESC LIMIT '.($limit+1));
    foreach($args as $index=>$value)$q->bindValue($index+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    $q->execute();
    $candidates=$q->fetchAll(PDO::FETCH_ASSOC);
    $next=count($candidates)>$limit?(int)$candidates[$limit-1]['id']:null;
    $moreFamilies=[];
    if($next!==null&&$includeMoreFamilies){
        if($specialist!==null)$moreFamilies=[$specialist];
        else{
            $q=$pdo->prepare('SELECT DISTINCT candidates.specialist '.$selector.' AND candidates.id<?');
            foreach([...$args,$next] as $index=>$value)$q->bindValue($index+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
            $q->execute();
            $moreFamilies=$q->fetchAll(PDO::FETCH_COLUMN);
        }
    }
    return ['rows'=>array_slice($candidates,0,$limit),'next_before'=>$next,'more_families'=>$moreFamilies];
}

/** Verify the original message and receipt through the normal exact-reply boundary. */
function vp3_top_of_mind_open_engagement_v1(PDO $pdo,array $user,array $principal,int $cid,int $id,string $family,array $casual): ?array
{
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    $loaded=vp3_initiative_followthrough_load_v1($pdo,$user,$principal,$cid,$id);
    $pending=$loaded['state'];
    $out=$loaded['result']['initiative_tool_result']['output']??[];
    $context=$loaded['context'];
    if(vp3_initiative_followthrough_coordination_question_v1($context,false)){
        $primary=$loaded['goal']['primary'];
        $actualFamily=$casual[$primary]??($loaded['evidence'][$primary]['specialist']??null);
        if($family!==$actualFamily)return null;
        $followup=trim((string)($pending['followup_clarification']??''));
        $open=$pending['state']==='open'||($pending['state']==='discussed'&&$followup!==''&&$pending['continuation_run_id']===0);
        $question=$followup!==''?$followup:($out['question']??'');
    }else{
        $open=vp3_initiative_followthrough_office_question_tool_v1($context)!==null;
        $question=$out['followup_question']??'';
    }
    if(!$open||!is_string($question)||trim($question)==='')return null;
    return ['question'=>trim($question),'title'=>vp3_top_of_mind_text_v1($out['title']??'',160),'loaded'=>$loaded];
}

/** This uses the Chat's message index, then the ordinary exact-reply verifier. No work advances on a read. */
function vp3_top_of_mind_open_engagements_v1(PDO $pdo,array $user,array $principal,int $agentId,int $cid,?string $specialist=null,
    int $before=0,?int $postedBefore=null,array $excludedMessages=[]): array
{
    $families=$specialist===null?VP3_TOP_OF_MIND_ENGAGEMENT_SPECIALISTS_V1:[$specialist];
    $empty=['open_engagements'=>[],'open_engagements_next_before'=>null,'open_engagements_status'=>'ready'];
    $pages=array_fill_keys($families,$empty);
    if($cid<1)return $pages;
    try{
        $window=vp3_top_of_mind_open_engagement_candidates_v1($pdo,(int)$user['id'],$agentId,$cid,$specialist,
            $before,$postedBefore,$excludedMessages);
    }catch(Throwable $error){
        vp3_initiative_health_diagnostic_v1($error,'open_engagements_read',(int)$user['id'],$agentId,$cid);
        return array_fill_keys($families,array_replace($empty,['open_engagements_status'=>'unavailable']));
    }
    $casual=vp3_top_of_mind_engagement_casual_specialists_v1();
    $seen=[];
    foreach($window['rows'] as $row){
        $id=(int)$row['id'];$family=$row['specialist'];
        if(!isset($pages[$family]))continue;
        $page=&$pages[$family];
        if(count($page['open_engagements'])>=VP3_TOP_OF_MIND_ENGAGEMENT_PAGE_SIZE_V1){
            $page['open_engagements_next_before']??=$seen[$family];
            unset($page);continue;
        }
        try{
            $engagement=vp3_top_of_mind_open_engagement_v1($pdo,$user,$principal,$cid,$id,$family,$casual);
            if($engagement!==null){
                $page['open_engagements'][]=['question'=>$engagement['question'],'title'=>$engagement['title'],
                    'message_id'=>$id,'conversation_id'=>$cid,'result_url'=>vp3_top_of_mind_media_href_v1($agentId,$cid,$id)];
                $seen[$family]=$id;
            }
        }catch(JsonException){
            // A malformed historical marker cannot prove this is an unanswered question.
        }catch(Throwable $error){
            if($error instanceof PDOException||!($error instanceof RuntimeException)||!in_array((int)$error->getCode(),[403,404,409],true)){
                $page['open_engagements_status']='unavailable';
                vp3_initiative_health_diagnostic_v1($error,'open_engagement_read',(int)$user['id'],$agentId,$cid);
            }
        }
        unset($page);
    }
    foreach($window['more_families'] as $family)$pages[$family]['open_engagements_next_before']??=$window['next_before'];
    return $pages;
}

/** Use the existing discovery checkpoint. Reading a specialist popup does not start another scan. */
function vp3_top_of_mind_specialist_scan_v1(string $id,array $scan): array
{
    $found=$scan['found']??0;
    $matches=$scan['chat_matches']??null;
    $handled=max($scan['already_handled']??0,$scan['legacy_handled']??0);
    $gate=$id==='media'?match($scan['handoff_gate']??$scan['offer_gate']??null){
        'quiet_chat_required'=>'The last check could not verify quiet, focused Chat for a Media suggestion.',
        'handoff_pending'=>'A Media suggestion is already queued for Chat.',
        'handoff_stopped'=>'The last Media suggestion stopped before posting.',
        'playing'=>'The last check found media playback in progress.',
        'idle_spacing'=>'Waiting for the current quiet period to finish.',
        'owner_turn_pending'=>'Waiting for the current Chat turn to finish.',
        'recent_proactive_post'=>'Waiting after a recent Agent post.',
        'verification_unavailable'=>'The last check could not verify Media delivery.',
        'disabled'=>'Automatic Media check-ins are off in settings.',
        'ready'=>'A Media suggestion is eligible for Chat.',
        'cooldown'=>'Waiting after a recent suggestion.',
        'report_only'=>'Findings are available in the Media report.',
        'new_activity_required'=>'Waiting for a new Chat turn before offering another track.',
        'already_offered'=>'Current suggestions were already offered.',
        'no_playable_sources'=>'No playable track, resume, or saved YouTube suggestion was found.',
        default=>null,
    }:($id==='curiosity'?match($scan['offer_gate']??null){
        'candidate'=>'A Curiosity question is available for the next quiet Chat check.',
        'ready'=>'Curiosity has a question ready for Chat.',
        'handoff_pending'=>'A Curiosity question is already queued for Chat.',
        'quiet_chat_required'=>'The last check could not verify quiet, focused Chat for Curiosity to ask its question.',
        'playing'=>'Curiosity is waiting for current media playback to finish.',
        'owner_turn_pending'=>'Curiosity is waiting for the current Chat turn to finish.',
        'recent_proactive_post'=>'Curiosity is waiting after a recent Chat update.',
        'higher_priority'=>'A Curiosity question is available and higher-priority work has the current Chat slot.',
        'report_only'=>'Curiosity questions stay in Top of Mind under the current Chat rule.',
        'awaiting_reply'=>'An earlier question is still open. Curiosity continues to explore other topics.',
        'no_question'=>'Curiosity is looking for a useful question.',
        default=>null,
    }:null);
    $state=match($scan['coverage']){'unavailable'=>'unavailable','unconfigured'=>'blocked',default=>'checked'};
    $detail=match($scan['coverage']){
        'unavailable'=>'The last specialist check could not verify its sources.',
        'unconfigured'=>'This specialist has no configured sources for this Chat.',
        'limited'=>$found===0?'The last check found no current findings in the sources it could verify.':null,
        default=>null,
    };
    $detail??=$gate??match(true){
        $id==='profile'&&in_array('native_presentation',array_column($scan['dispositions']??[],'reason'),true)=>
            'Current visitor, call or task updates remain with their existing presentation. Profile findings are available for review.',
        $found===0=>'The last check found no current findings.',
        $matches===null=>'Current findings are available in the report. Chat eligibility is awaiting review.',
        $matches===0=>'Current findings are available in the report. None matched a Chat rule.',
        $handled>=$matches=>'The last check found that matching Chat updates were already handled.',
        default=>'The last check found '.($matches===1?'one finding':$matches.' findings').' that matched Chat rules.',
    };
    return ['id'=>$id,'state'=>$state,'detail'=>$detail,'progress'=>null]
        +($id==='profile'?['source_coverage'=>$scan['coverage']]:[]);
}

/** The Profile workspace reads current facts; status reads never deliver a visitor or call. */
function vp3_top_of_mind_profile_status_v1(PDO $pdo,array $user,array $principal,int $agentId,int $cid,array $policy,int $now,
    bool $includeReports): ?array
{
    if($cid<1)return null;
    require_once __DIR__.'/agent-coordination-profile-v1.php';
    $binding=['owner_user_id'=>(int)$user['id'],'agent_id'=>$agentId,'conversation_id'=>$cid]
        + (($policy['conversation_id']??null)===$cid?$policy:[]);
    try{
        vp3_coordination_profile_authority_v1($pdo,
            ['user'=>$user,'principal'=>$principal,'agent_id'=>$agentId,'conversation_id'=>$cid],$binding);
        if(!$includeReports)return ['id'=>'profile','state'=>'idle','detail'=>'Open Profile to review visitor conversations, calls and follow-ups.',
            'progress'=>null,'conversation_id'=>$cid];
        $snapshot=vp3_coordination_sources_v1($pdo,$user,$binding,now:new DateTimeImmutable('@'.$now),specialistOnly:'profile');
    }catch(RuntimeException $error){
        if($error->getCode()===403)return null;
        vp3_initiative_health_diagnostic_v1($error,'profile_status_read',(int)$user['id'],$agentId,$cid);
        $snapshot=['items'=>[],'coverage'=>['profile'=>'unavailable']];
    }
    $coverage=$snapshot['coverage']['profile']??'unavailable';
    [$state,$detail]=match($coverage){
        'unconfigured'=>['blocked','Profile sources are not configured for this owner.'],
        'unavailable'=>['unavailable','Current Profile findings could not be verified.'],
        'limited'=>['checked','Profile findings are available with limited source coverage.'],
        default=>['checked',$snapshot['items']?'Current Profile findings are available for review.':'No current Profile findings in the sources checked.'],
    };
    return ['id'=>'profile','state'=>$state,'detail'=>$detail,'progress'=>null,'conversation_id'=>$cid,'source_coverage'=>$coverage];
}

/** Connections can be usable on demand even while a proactive report is pending. Read saved state only. */
function vp3_top_of_mind_connected_sites_status_v1(PDO $pdo,array $scope,int $now): ?array
{
    require_once __DIR__.'/connected-sites-reports-v1.php';
    $user=$scope['user'];
    $owner=vp3_connected_sites_report_owner_v1($user);
    if(!function_exists('knowledge_retrieval_v162_owner_session'))require_once __DIR__.'/knowledge-retrieval-v162.php';
    if(!knowledge_retrieval_v162_owner_session($user,$scope['principal']))return null;

    $providers=['FoodMonster'=>[],'Stonefellow'=>[]];
    try{
        $report=vp3_connected_sites_report_v1($pdo,$scope,new DateTimeImmutable('@'.$now),includeFindings:false);
        foreach($report['connections'] as $connection){
            $proactive=in_array('proactive',array_column($connection['datasets'],'mode'),true);
            $states=$proactive?array_column($connection['locations'],'state'):['on_demand'];
            array_push($providers['FoodMonster'],...($states?:[$connection['state']]));
        }
    }catch(Throwable $error){
        vp3_initiative_health_diagnostic_v1($error,'foodmonster_status_read',$owner,$scope['agent_id']??0,$scope['conversation_id']??0);
        $providers['FoodMonster'][]='unavailable';
    }

    try{
        if(schema_metadata_table_exists($pdo,'vp3_stoneward_connections_v1')){
            require_once __DIR__.'/agent-media-stoneward-v1.php';
            $sites=vp3_stoneward_connections_v1($pdo,$owner);
            $rows=$sites?vp3_stoneward_media_rows_v1($pdo,$owner):[];
            foreach($sites as $site){
                $row=$rows[$site['id']]??null;
                $providers['Stonefellow'][]=$row===null?'on_demand':vp3_connected_sites_report_state_v1($row,$now);
            }
        }
    }catch(Throwable $error){
        vp3_initiative_health_diagnostic_v1($error,'stonefellow_status_read',$owner,$scope['agent_id']??0,$scope['conversation_id']??0);
        $providers['Stonefellow'][]='unavailable';
    }

    $all=array_merge(...array_values($providers));
    if(!$all)return null;
    $state=match(true){
        (bool)array_intersect($all,['ready','on_demand','partial','stale'])=>'checked',
        (bool)array_intersect($all,['upgrade_required','authorization_required'])=>'blocked',
        in_array('unchecked',$all,true)=>'idle',
        default=>'unavailable',
    };
    $detail=[];
    foreach($providers as $provider=>$states){
        foreach(array_unique($states) as $siteState){
            $detail[]=$provider.' '.match($siteState){
                'ready'=>'has a verified report.',
                'on_demand'=>'is available on demand.',
                'unchecked'=>'is waiting for its first check.',
                'stale'=>'is showing the last verified report.',
                'partial'=>'has a partially verified report.',
                'authorization_required'=>'needs its connection permissions checked.',
                'upgrade_required'=>'needs the VP3 Database Upgrade for proactive checks.',
                default=>'could not verify its latest report.',
            };
        }
    }
    return ['id'=>'connected-sites','state'=>$state,'detail'=>implode(' ',$detail),'progress'=>null];
}

/** Read only native status here. The Curiosity report loads on demand. */
function vp3_top_of_mind_curiosity_status_v1(PDO $pdo,array $user,int $agentId,int $cid): array
{
    $row=['id'=>'curiosity','state'=>'unavailable','progress'=>null,'conversation_id'=>$cid];
    try{
        if(is_file(__DIR__.'/agent-curiosity-v1.php')){
            require_once __DIR__.'/agent-curiosity-v1.php';
            $status=vp3_curiosity_status_v1($pdo,$user,$agentId,$cid);
            if(in_array($status['state']??null,['checked','idle','unavailable'],true))$row['state']=$status['state'];
            if(isset($status['detail'])&&is_string($status['detail']))$row['detail']=$status['detail'];
        }
    }catch(Throwable $error){}
    return $row;
}

/** Full reports use the same verified source as Initiative. The work poll stays metadata-only. */
function vp3_top_of_mind_transcripts_status_v1(PDO $pdo,array $user,int $agentId,int $cid,array $policy,int $now,
    bool $includeReports): ?array
{
    if(!has_permission('artist_listening.access',$user))return null;
    $row=['id'=>'transcripts','state'=>'idle','detail'=>'Open Transcription Intelligence to review saved recordings.',
        'progress'=>null,'conversation_id'=>$cid];
    if(!$includeReports||$cid<1)return $row;
    require_once __DIR__.'/agent-coordination-sources-v1.php';
    $binding=['owner_user_id'=>(int)$user['id'],'agent_id'=>$agentId,'conversation_id'=>$cid]
        + (($policy['conversation_id']??null)===$cid ? $policy : []);
    try{
        $snapshot=vp3_coordination_sources_v1($pdo,$user,$binding,now:new DateTimeImmutable('@'.$now),specialistOnly:'transcripts');
    }catch(RuntimeException $error){
        if($error->getCode()===403)return null;
        vp3_initiative_health_diagnostic_v1($error,'transcripts_status_read',(int)$user['id'],$agentId,$cid);
        $snapshot=['items'=>[],'coverage'=>['transcripts'=>'unavailable']];
    }
    $coverage=$snapshot['coverage']['transcripts']??'unavailable';
    if($coverage==='unconfigured')return null;
    $reports=[];
    foreach($snapshot['items'] as $item){
        $ref=$item['ref']??'';
        if(($item['specialist']??null)!=='transcripts'||!is_string($ref)
            ||!preg_match('/\Atranscript:([1-9][0-9]{0,15})\z/D',$ref,$match)
            ||(int)$match[1]>9007199254740991)continue;
        $reports[]=['session_id'=>(int)$match[1],'title'=>$item['title'],'summary'=>$item['summary'],
            'next_step'=>$item['next_step'],'question'=>$item['question'],'url'=>$item['url']];
        if(count($reports)===4)break;
    }
    [$state,$detail]=match(true){
        (bool)$reports=>['ready','Current findings from saved transcription analysis.'],
        $coverage==='unavailable'=>['unavailable','Saved transcription findings could not be verified.'],
        $coverage==='limited'=>['checked','No current findings were verified in the recordings checked.'],
        default=>['checked','No open findings in the saved transcription reports checked.'],
    };
    return array_replace($row,['state'=>$state,'detail'=>$detail,'transcript_reports'=>$reports]);
}

/** These checkpoints describe discovery only. A verified workflow item owns execution state. */
function vp3_top_of_mind_opportunity_checks_v1(array $checks,int $now): array
{
    $rows=[];
    foreach(['app_suggestions'=>'coding','research'=>'research','missions'=>'mission'] as $lane=>$id){
        $check=$checks[$lane]??[];
        $state=$check['state']??null;
        $at=$check['checked_at']??null;
        if(!is_int($at)||$at<1||$at>$now)continue;
        [$status,$detail]=match($state){
            'checking'=>$at>$now-VP3_INITIATIVE_OPPORTUNITY_INTERVAL_V1
                ?['scheduled','The latest check has no recorded outcome yet.'] : ['scheduled','The previous check has no recorded outcome.'],
            'queued'=>['checked','The last check queued an assignment.'],
            'already_handled'=>['checked','The last check found work already assigned or handled.'],
            'no_opportunity'=>['checked','The last check found no useful next step in its current sources.'],
            'not_ready'=>['blocked','A required tool is unavailable for the next check.'],
            'blocked'=>['blocked','The last check was blocked.'],
            'failed'=>['unavailable','The last check could not verify its sources.'],
            default=>['',''],
        };
        if($status==='')continue;
        $rows[$id]=['id'=>$id,'state'=>$status,'detail'=>$detail,'progress'=>null,'checked_at'=>gmdate(DATE_ATOM,$at)];
    }
    return $rows;
}

/** Counts are disclosed only inside the owner’s authorized private conversation. */
function vp3_location_specialist_status_v1(PDO $pdo,array $user,array $principal,int $agentId,int $cid,int $now): ?array
{
    require_once __DIR__.'/schema-metadata.php';
    if (vp3_participant_scope_v1() !== null || !knowledge_retrieval_v162_owner_session($user,$principal)
        || !has_permission('account.access',$user) || !has_permission('chat.access',$user)
        || !schema_metadata_table_exists($pdo,'vp3_phone_arrival_rules_v1')) return null;

    $row = ['id'=>'location','conversation_id'=>$cid,'state'=>'idle','progress'=>null,
        'detail'=>'No arrival alerts in this Chat.'];
    if ($cid < 1) return $row;
    // The enclosing Chat read verified this owner, Agent and conversation. Use only
    // counts so the activity feed never receives phone names, places, topics or GPS.
    try {
        $query = $pdo->prepare("SELECT r.state,COUNT(*) FROM vp3_phone_arrival_rules_v1 r
            JOIN vp3_phone_devices_v1 d ON d.owner_user_id=r.owner_user_id AND d.id=r.device_id AND d.generation=r.generation
            JOIN vp3_phone_places_v1 p ON p.owner_user_id=r.owner_user_id AND p.id=r.place_id
            JOIN chat_conversations c ON c.id=r.conversation_id AND c.user_id=r.owner_user_id
                AND ((r.agent_id=0 AND c.user_agent_id IS NULL) OR (r.agent_id>0 AND c.user_agent_id=r.agent_id))
            WHERE r.owner_user_id=? AND r.agent_id=? AND r.conversation_id=?
            AND d.device_status='approved' AND d.allow_location=1 AND d.expires_at>?
            AND d.location_state IN ('waiting','live') AND r.state IN ('requested','active') GROUP BY r.state");
        $query->execute([(int)$user['id'],$agentId,$cid,$now]);
        $counts = $query->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException) {
        $row['state'] = 'unavailable';
        $row['detail'] = 'Arrival alert status could not be verified.';
        return $row;
    }
    $active = (int)($counts['active'] ?? 0);
    $pending = (int)($counts['requested'] ?? 0);
    $parts = [];
    if ($active) $parts[] = $active === 1 ? '1 active arrival alert.' : $active.' active arrival alerts.';
    if ($pending) $parts[] = $pending === 1 ? '1 alert awaits phone approval.' : $pending.' alerts await phone approval.';
    if ($parts) {
        $row['state'] = $pending ? 'awaiting_consent' : 'armed';
        $row['detail'] = implode(' ',$parts);
    }
    return $row;
}

/** Operator status is only a private owner Chat read; it never starts a call. */
function vp3_operator_specialist_status_v1(PDO $pdo,array $user,array $principal,int $agentId,int $cid,int $now): ?array
{
    require_once __DIR__.'/schema-metadata.php';
    if (vp3_participant_scope_v1() !== null || !knowledge_retrieval_v162_owner_session($user,$principal)
        || !has_permission('account.access',$user) || !has_permission('chat.access',$user)
        || !schema_metadata_table_exists($pdo,'phone_browser_calls_v1')) return null;
    require_once __DIR__.'/agent-operator-specialist-v1.php';
    return vp3_operator_call_status_v1($pdo,$user,$agentId,$cid,$now);
}

function vp3_top_of_mind_specialists_v1(PDO $pdo,array $user,array $principal,int $agentId,int $cid,array $policy,array $health,
    ?array $gmail,int $now,array $items=[],bool $includeEngagements=false,bool $includeTranscriptReports=false,bool $privateChat=false): array
{
    require_once __DIR__.'/agent-planning-status-v1.php';
    $out=[vp3_planning_status_read_v1($pdo,$user,$principal,$agentId,$cid,$policy,$health,$now),
        vp3_top_of_mind_curiosity_status_v1($pdo,$user,$agentId,$cid)];
    if ($privateChat) {
        $location = vp3_location_specialist_status_v1($pdo,$user,$principal,$agentId,$cid,$now);
        if ($location !== null) $out[] = $location;
        $operator = vp3_operator_specialist_status_v1($pdo,$user,$principal,$agentId,$cid,$now);
        if ($operator !== null) $out[] = $operator;
    }
    $transcripts=vp3_top_of_mind_transcripts_status_v1($pdo,$user,$agentId,$cid,$policy,$now,$includeTranscriptReports);
    if($transcripts!==null)$out[]=$transcripts;
    $profile=vp3_top_of_mind_profile_status_v1($pdo,$user,$principal,$agentId,$cid,$policy,$now,$includeTranscriptReports);
    if($profile!==null)$out[]=$profile;
    $tasks=vp3_top_of_mind_tasks_v1($pdo,$user,$cid,$policy,$health,$now);
    if($tasks!==null)$out[]=$tasks;
    if($cid>0){
        try{
            require_once __DIR__.'/agent-top-of-mind-image-v1.php';
            $image=vp3_top_of_mind_image_status_v1($pdo,$user,$agentId,$cid,$now);
            if($image!==null)$out[]=$image;
        }catch(Throwable $error){
            vp3_initiative_health_diagnostic_v1($error,'image_specialist_status_read',(int)$user['id'],$agentId,$cid);
        }
    }
    if($gmail!==null){
        [$state,$detail]=match($gmail['state']??''){
            'working'=>['working','Checking the inbox and open email follow-ups.'],
            'scheduled'=>['scheduled','Waiting for the next inbox check.'],
            'checked'=>['checked','The last inbox check is complete.'],
            'overdue'=>match($gmail['overdue_reason']??''){
                'claim_expired'=>['interrupted','The inbox check was interrupted. It can resume in open Chat.'],
                'retry_due'=>['waiting_retry','The last inbox check failed. Its retry is due.'],
                default=>['queued','A scheduled inbox check is due. It can run in open Chat.'],
            },
            'retrying'=>['queued','The next inbox check is a retry.'],
            'restart_required'=>['blocked','Inbox monitoring needs to be restarted.'],
            default=>['unavailable','Inbox monitoring is unavailable.'],
        };
        $row=['id'=>'gmail','state'=>$state,'detail'=>$detail,'progress'=>null,'lease_expires_at'=>$gmail['lease_until']??null];
        $analysis=$gmail['analysis_work']??null;
        if(is_array($analysis)){
            [$state,$detail]=match($analysis['state']??''){
                'working'=>['working','Analyzing the content of an inbox thread.'],
                'queued'=>['queued','Inbox threads are queued for content analysis.'],
                'ready'=>['ready','A verified inbox thread analysis is ready.'],
                default=>['idle',''],
            };
            if($detail!=='')$row=vp3_top_of_mind_prefer_work_v1($row,
                ['id'=>'gmail','state'=>$state,'detail'=>$detail,'progress'=>null,'lease_expires_at'=>$analysis['lease_until']??null]);
        }
        $out[]=$row;
    }
    $specialists=array_column($out,null,'id');
    if(($health['enabled']??null)===true&&($policy['conversation_id']??null)===$cid){
        try{
            foreach(vp3_top_of_mind_coordination_specialists_v1($pdo,$user,$agentId,$policy,$now) as $id=>$row){
                $specialists[$id]=vp3_top_of_mind_prefer_work_v1($specialists[$id]??null,$row);
            }
        }catch(Throwable $error){
            vp3_initiative_health_diagnostic_v1($error,'specialist_status_read',(int)$user['id'],$agentId,$cid);
        }
        require_once __DIR__.'/agent-coordination-schedule-v1.php';
        $checkpoint=vp3_coordination_scan_checkpoint_v1($user,$policy);
        foreach($checkpoint['specialists']??[] as $id=>$scan){
            if($id==='connected-sites')continue;
            if($id==='operator'){
                // The checkpoint is a historical scan, not a current source read.
                // Do not expose it outside verified private Phone/Transcript access,
                // or replace a live call status or blocked/unavailable state.
                if($privateChat && isset($specialists['operator'])
                    && $specialists['operator']['state']==='idle'
                    && has_permission('artist_listening.access',$user)
                    && in_array($scan['coverage']??null,['ready','limited'],true)) {
                    $count=(int)($scan['found']??0);
                    if($count>0) {
                        $specialists['operator']['state']='checked';
                        $specialists['operator']['detail']='The last check found '.$count
                            .($count===1?' call report':' call reports').' with follow-ups to review. Open Operator Brain for current findings.';
                        if(isset($specialists['operator']['operator_worker']))
                            $specialists['operator']['detail'].=' '.vp3_operator_worker_detail_8a4($specialists['operator']['operator_worker']);
                    }
                }
                continue;
            }
            $row=vp3_top_of_mind_specialist_scan_v1($id,$scan);
            $specialists[$id]=vp3_top_of_mind_prefer_work_v1($specialists[$id]??null,$row);
        }
        try{
            $connected=vp3_top_of_mind_connected_sites_status_v1($pdo,
                ['user'=>$user,'principal'=>$principal,'agent_id'=>$agentId,'conversation_id'=>$cid],$now);
            if($connected!==null){
                $specialists['connected-sites']=vp3_top_of_mind_prefer_work_v1($specialists['connected-sites']??null,$connected);
            }
        }catch(Throwable $error){
            if($error->getCode()!==403){
                vp3_initiative_health_diagnostic_v1($error,'connected_sites_status_read',(int)$user['id'],$agentId,$cid);
                $specialists['connected-sites']??=['id'=>'connected-sites','state'=>'unavailable',
                    'detail'=>'Connected Sites report storage could not be read.','progress'=>null];
            }
        }
        $specialists['media']??=['id'=>'media','state'=>'idle','detail'=>'Waiting for the first Media check in this Chat.','progress'=>null];
        $specialists['marketing']??=['id'=>'marketing','state'=>'idle','detail'=>'Waiting for the first Marketing check in this Chat.','progress'=>null];
    }
    foreach($items as $item){
        $id=$item['specialist_id']??'';
        if($id==='ob1'||!in_array($id,VP3_TOP_OF_MIND_ENGAGEMENT_SPECIALISTS_V1,true))continue;
        $state=match($item['state']){'ready_to_report'=>'ready','working','queued','waiting_owner','completed','failed','interrupted','paused'=>$item['state'],default=>''};
        if($state==='')continue;
        $current=$specialists[$id]??null;
        $row=['id'=>$id,'state'=>$state,'detail'=>$item['step']!==''?$item['step']:$item['objective'],
            'run_id'=>$item['run_id'],'conversation_id'=>$cid,'progress'=>$item['progress']??null,
            'lease_expires_at'=>$item['lease_expires_at']];
        if(isset($item['handoff']))$row['handoff']=$item['handoff'];
        if(isset($item['message_id'])){
            $row['message_id']=$item['message_id'];
            $row['result_url']=vp3_top_of_mind_media_href_v1($agentId,$cid,$item['message_id']);
        }
        $preferred=vp3_top_of_mind_prefer_work_v1($current,$row);
        $preferred['run_ids']=array_values(array_unique(array_merge($current['run_ids']??[],[$item['run_id']])));
        $specialists[$id]=$preferred;
    }
    if(($health['enabled']??null)===true&&($policy['conversation_id']??null)===$cid){
        try{
            require_once __DIR__.'/agent-initiative-opportunity-schedule-v1.php';
            $checks=vp3_initiative_opportunity_checkpoint_v1($user,$policy);
            foreach(vp3_top_of_mind_opportunity_checks_v1($checks,$now) as $id=>$row)$specialists[$id]??=$row;
        }catch(Throwable $error){
            vp3_initiative_health_diagnostic_v1($error,'opportunity_status_read',(int)$user['id'],$agentId,$cid);
        }
    }
    if($includeEngagements&&$cid>0){
        foreach(vp3_top_of_mind_open_engagements_v1($pdo,$user,$principal,$agentId,$cid) as $id=>$page){
            if(!isset($specialists[$id])&&($page['open_engagements']||$page['open_engagements_next_before']!==null)){
                $specialists[$id]=['id'=>$id,'state'=>'idle','detail'=>'','progress'=>null];
            }
            if(isset($specialists[$id]))$specialists[$id]+=$page;
        }
    }
    if($transcripts===null)unset($specialists['transcripts']);
    elseif(isset($transcripts['transcript_reports']))$specialists['transcripts']['transcript_reports']=$transcripts['transcript_reports'];
    if($profile===null)unset($specialists['profile']);
    elseif(isset($profile['source_coverage']))$specialists['profile']['source_coverage']=$profile['source_coverage'];
    return array_values($specialists);
}

/** Recheck both the assessment's sources and its specialist reports before showing its private finding. */
function vp3_top_of_mind_latest_finding_v1(PDO $pdo,array $user,array $policy,array $runs,int $now): ?array
{
    $eligible=array_filter($runs,static fn(array $run):bool=>$run['capability_key']==='initiative.plan'&&$run['status']==='completed');
    if(!$eligible)return null;
    $ids=array_keys($eligible);
    $slots=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare("SELECT a.run_id,a.result_json,a.completed_at,r.result_json AS receipt_json
        FROM agent_workflow_actions a JOIN agent_workflow_receipts r ON r.run_id=a.run_id AND r.action_id=a.id
            AND r.owner_user_id=a.owner_user_id AND r.attempt_no=a.attempt_count AND r.executor='cloud' AND r.status='completed'
        WHERE a.owner_user_id=? AND a.run_id IN ($slots) AND a.sequence_no=1 AND a.capability_key='initiative.plan'
            AND a.status='completed' AND a.completed_at>? AND a.result_json LIKE '%\"finding\":%'
        ORDER BY a.completed_at DESC,a.run_id DESC,r.id DESC LIMIT 3");
    $q->execute([(int)$user['id'],...$ids,gmdate('Y-m-d H:i:s',$now-1800)]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if($row['result_json']!==$row['receipt_json'])continue;
        $goal=json_decode((string)$eligible[(int)$row['run_id']]['goal'],true);
        $result=json_decode((string)$row['result_json'],true);
        if(!is_array($goal['sources']??null)||!in_array($result['effect_state']??null,['assignments_queued','existing_assignments','idle'],true))continue;
        $toolResult=$result['initiative_tool_result']??null;
        if(!is_array($toolResult))continue;
        $text=vp3_top_of_mind_finding_v1($pdo,$user,$policy,$goal,$toolResult,(string)$row['completed_at'],$now);
        if($text!==null)return ['text'=>$text,'completed_at'=>vp3_top_of_mind_lease_time_v1($row['completed_at'])];
    }
    return null;
}

function vp3_top_of_mind_finding_v1(PDO $pdo,array $user,array $policy,array $goal,array $toolResult,string $updatedAt,int $now): ?string
{
    $finding=vp3_initiative_brainstorm_tool_result_v1($toolResult)['output']['finding']??null;
    $updated=vp3_top_of_mind_lease_time_v1($updatedAt);
    if($finding===null||$updated<=$now-1800||$updated>$now)return null;
    require_once __DIR__.'/agent-initiative-specialist-reports-v1.php';
    try{
        $evidence=$goal['sources']?vp3_initiative_current_sources_v1($pdo,$user,$goal['sources'],!empty($policy['screen_enabled']),
            $goal['canonical_sources']??[],vp3_initiative_binding_agent_v1($policy),$goal['capability_sources']??[]):[];
        $authority=[];
        $reportKey=$goal['report_only']??null;
        $reports=is_string($reportKey)?vp3_initiative_specialist_report_only_v1($pdo,$user,$policy,$reportKey)
            :vp3_initiative_specialist_reports_v1($pdo,$user,$policy,$evidence,$authority);
        if(array_diff($finding['report_keys'],vp3_initiative_brainstorm_report_keys_v1($reports)))return null;
        if($authority)vp3_initiative_specialist_reports_current_v1($pdo,$user,$policy,$authority);
        vp3_initiative_access_v1($pdo,$user,$policy);
        return $finding['text'];
    }catch(RuntimeException|JsonException $error){return null;}
}

/** Report real worker stages and verified results. Polling never advances the loop. */
function vp3_top_of_mind_coordination_v1(array $health,array $specialists,int $now,array $items=[]): array
{
    $state=$health['state']??'unavailable';
    if(in_array($state,['no_chat','starting','linked_elsewhere','unavailable','blocked','paused'],true)){
        return match($state){
            'paused'=>['phase'=>'paused','detail'=>'Top of Mind is paused.'],
            'no_chat'=>['phase'=>'waiting','detail'=>'Waiting for a saved Chat.'],
            'starting'=>['phase'=>'waiting','detail'=>'Waiting for coordination to start in this Chat.'],
            'linked_elsewhere'=>['phase'=>'waiting','detail'=>'Coordination is linked to another Chat.'],
            'blocked'=>['phase'=>'unavailable','detail'=>'A model route is unavailable for the next assessment.'],
            default=>['phase'=>'unavailable','detail'=>'Background coordination is currently unavailable.'],
        };
    }
    $acting=null;
    foreach($health['active_jobs']??[] as $job){
        if(($job['expiresAt']??0)<=$now*1000)continue;
        $ob1=($job['kind']??null)==='assessment'&&($job['specialist']??'ob1')==='ob1';
        $phase=$ob1?(in_array($job['phase']??null,['reviewing','coordinating','presenting'],true)
            ?$job['phase']:($job['coordination_phase']??'analyzing')):'acting';
        $active=['phase'=>$phase,'detail'=>match($phase){
            'reviewing'=>'Reviewing current specialist findings.',
            'coordinating'=>'Connecting verified inputs and assigning next steps.',
            'presenting'=>'Checking the verified result for presentation.',
            'analyzing'=>'Assessing current sources and specialist reports.',
            'planning'=>'Checking the plan and assigning eligible next steps.',
            'brainstorming'=>'Reasoning through useful next steps.',
            default=>'Specialists are advancing assigned work.',
        },'lease_expires_at'=>gmdate(DATE_ATOM,intdiv($job['expiresAt'],1000))];
        if($phase!=='acting')return $active;
        $acting??=$active;
    }
    if($acting!==null)return $acting;
    $queued=null;
    foreach($items as $item){
        if(($item['state']??null)==='ready_to_report')return ['phase'=>'presenting','detail'=>'A verified finding is ready to share.'];
        if(($item['state']??null)==='queued'){
            if(($item['kind']??null)==='assessment')$queued='The next assessment is queued.';
            else $queued??='An Agent assignment is queued.';
        }
    }
    if($queued!==null)return ['phase'=>'waiting','detail'=>$queued];
    $waitingForReview=false;
    foreach($specialists as $specialist){
        if(($specialist['state']??null)==='queued'&&($specialist['run_id']??0)>0)
            return ['phase'=>'waiting','detail'=>'A specialist assignment is queued.'];
        if(in_array($specialist['state']??null,['waiting_specialist','waiting_owner'],true))$waitingForReview=true;
    }
    $latest=$health['latest_assessment_run']??[];
    $last=$health['last_assessment']??[];
    if(($latest['status']??null)==='queued')return ['phase'=>'waiting','detail'=>'The next assessment is queued.'];
    if(($latest['status']??null)==='retry_scheduled')return ['phase'=>'waiting','detail'=>'An assessment retry is scheduled.'];
    if(($latest['reason_code']??null)==='assessment_sources_changed'){
        return ['phase'=>'waiting','detail'=>'Sources changed. Waiting for a fresh assessment.'];
    }
    if(in_array($latest['status']??null,['failed','unverified'],true)){
        return ['phase'=>'unavailable','detail'=>'The latest assessment could not be verified.'];
    }
    if(($latest['status']??null)==='interrupted')return ['phase'=>'waiting','detail'=>'The latest assessment was interrupted.'];
    if(($latest['status']??null)==='completed'&&($latest['run_id']??null)===($last['run_id']??null)){
        $at=strtotime($last['at']??'')?:0;
        if($at>0&&$at<=$now){
            $assignments=$last['queued']??0;
            // The scheduler normally revisits its sources every five minutes.
            $awaitingRefresh=$at<=$now-300;
            $detail=match($last['outcome']??null){
                'idle'=>'The last assessment found no useful work to assign.',
                'existing_assignments'=>'The last assessment found that the eligible work was already assigned.',
                'assignments_queued'=>'The last assessment assigned '.$assignments.($assignments===1?' next step.':' next steps.'),
                default=>'The last assessment completed.',
            };
            $names=['gmail'=>'Gmail','planning'=>'Planning','tasks'=>'Tasks','media'=>'Media & Entertainment'];
            $inputs=is_array($last['inputs']??null)?array_values(array_intersect_key($names,array_flip($last['inputs']))):[];
            if($inputs)$detail.=' Reviewed '.implode(', ',$inputs).'.';
            if($waitingForReview)$detail.=' An open item is waiting for review.';
            if($awaitingRefresh)$detail.=' Waiting for a fresh assessment.';
            return ['phase'=>$awaitingRefresh?'waiting':'checked','detail'=>$detail,'checked_at'=>$last['at']];
        }
    }
    if($waitingForReview)return ['phase'=>'waiting','detail'=>'An open item is waiting for a specialist or your review.'];
    if($state==='failed')return ['phase'=>'unavailable','detail'=>'The latest work could not be verified.'];
    if($state==='waiting')return ['phase'=>'waiting','detail'=>'Waiting for the next verified assessment.'];
    if($state==='checked'&&!empty($health['last_checked_at'])){
        return ['phase'=>'checked','detail'=>'The last source check has no verified assessment result yet.',
            'checked_at'=>$health['last_checked_at']];
    }
    return ['phase'=>'idle','detail'=>'Monitoring for new work and changes.'];
}
