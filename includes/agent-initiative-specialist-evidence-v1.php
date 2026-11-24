<?php
declare(strict_types=1);

require_once __DIR__.'/agent-coordination-sources-v1.php';
require_once __DIR__.'/agent-coordination-work-v1.php';

/** Project current reports into the same revision-bound evidence used by Initiative jobs. */
function vp3_initiative_specialist_evidence_v1(PDO $pdo,array $user,array $binding,DateTimeImmutable $now,
    ?array $selectedRefs,array &$coverage,int $perSpecialist=2): array
{
    $prefix='specialist:';
    $nativeRefs=null;
    if($selectedRefs!==null){
        $nativeRefs=[];
        foreach($selectedRefs as $ref){
            if(!is_string($ref)||!str_starts_with($ref,$prefix))continue;
            $native=substr($ref,strlen($prefix));
            if(vp3_coordination_source_ref_valid_v1($native))$nativeRefs[]=$native;
        }
        if(!$nativeRefs)return [];
    }
    $coverage['specialist_reports']='unavailable';
    $settings=vp3_coordination_rules_v1($pdo,$user,vp3_initiative_binding_agent_v1($binding));
    $items=[];$coverageBySpecialist=[];
    foreach($nativeRefs===null?[null]:array_chunk(array_unique($nativeRefs),24) as $batch){
        $snapshot=vp3_coordination_sources_v1($pdo,$user,$binding,$batch,$now);
        $items+=$snapshot['items'];
        foreach($snapshot['coverage'] as $specialist=>$state){
            if(($coverageBySpecialist[$specialist]??null)==='unavailable')continue;
            $coverageBySpecialist[$specialist]=$state;
        }
    }
    $coverage['specialist_reports']='ready';
    foreach($coverageBySpecialist as $specialist=>$state)$coverage['specialist_'.$specialist]=$state;
    $rank=['high'=>0,'normal'=>1,'low'=>2];
    uasort($items,static fn(array $a,array $b):int=>
        ($rank[$a['priority']]??2)<=>($rank[$b['priority']]??2));
    $evidence=[];$counts=[];
    foreach($items as $ref=>$item){
        $specialist=$item['specialist'];
        // Gmail content requires its dedicated mailbox path. Proposed Curiosity
        // questions are not evidence of owner preferences.
        if(in_array($specialist,['gmail','curiosity','casual','operator'],true))continue;
        $route=vp3_coordination_item_route_v1($settings,$item);
        $ownedProduct=$specialist==='marketing'&&vp3_product_marketing_ref_kind_v1($ref)!==null;
        if(($route['action']==='watch'&&$specialist!=='profile'&&!$ownedProduct)||$route['trigger']==='start_plan')continue;
        if($nativeRefs===null&&($counts[$specialist]??0)>=$perSpecialist)continue;
        $counts[$specialist]=($counts[$specialist]??0)+1;
        $key=$prefix.$ref;
        $evidence[$key]=[
            'ref'=>$key,'kind'=>'specialist_finding','specialist'=>$specialist,
            'title'=>$item['title'],'detail'=>mb_substr($item['summary'],0,480),'url'=>$item['url'],
            'next_step'=>$item['next_step'],'question'=>$item['question'],
            'priority'=>$item['priority'],'status'=>$item['status'],'routing'=>$route['action'],
            'private_summary_only'=>in_array($specialist,['canvas','connected-sites','marketing','profile'],true),
            'revision'=>vp3_coordination_source_hash_v1([$item['revision'],$route]),
        ];
        if($specialist==='profile'){
            $evidence[$key]['public_evidence_untrusted']=true;
            $evidence[$key]['profile_presentation_owned']=($item['profile_presentation_owned']??false)===true;
            $evidence[$key]['profile_source_kind']=$item['profile_source_kind'];
            if(isset($item['profile_canonical_ref']))$evidence[$key]['profile_canonical_ref']=$item['profile_canonical_ref'];
            if(isset($item['presentation_owner']))$evidence[$key]['presentation_owner']=$item['presentation_owner'];
        }
        if($specialist==='marketing'&&vp3_product_marketing_ref_kind_v1($ref)!==null){
            $evidence[$key]['public_evidence_untrusted']=true;
            $evidence[$key]['marketing_presentation_owned']=($item['marketing_presentation_owned']??false)===true;
            $evidence[$key]['marketing_source_kind']=$item['marketing_source_kind'];
            $evidence[$key]['presentation_owner']=$item['presentation_owner'];
        }
        if(isset($item['canonical_ref'],$item['canonical_revision'])){
            $evidence[$key]['canonical_ref']=$item['canonical_ref'];
            $evidence[$key]['canonical_revision']=$item['canonical_revision'];
        }
    }
    return $evidence;
}

/** Use private app, site, and campaign reports only for preparation in the bound Agent. */
function vp3_initiative_specialist_job_v1(array $job,array $evidence): array
{
    foreach($job['refs'] as $ref){
        $source=$evidence[$ref]??[];
        if(($source['kind']??null)!=='specialist_finding')continue;
        if(!empty($source['private_summary_only'])&&$job['tool']!=='work.prepare_private'){
            throw new Vp3InitiativePlanValidationV1('invalid_job_sources','This specialist report is available only for private preparation.');
        }
        if((($source['specialist']??null)==='profile'
            ||(($source['specialist']??null)==='marketing'&&($source['marketing_presentation_owned']??false)===true))
            &&($job['primary']??null)===$ref){
            // Profile's current native presenters and shared report route own
            // automatic Chat updates. General planning may retain private context.
            $job['report']=false;
        }
        if(($source['specialist']??null)==='transcripts'){
            if($job['tool']==='chat.check_in'){
                throw new Vp3InitiativePlanValidationV1('invalid_job_sources','The Transcriptions specialist handles recording follow-ups.');
            }
            if($job['tool']==='work.prepare_private'&&($job['primary']??null)===$ref)$job['report']=false;
        }
        if(($source['routing']??null)==='prepare'){
            if($job['tool']==='chat.check_in'){
                throw new Vp3InitiativePlanValidationV1('invalid_job_sources','This report is set to private preparation.');
            }
            $job['report']=false;
        }
    }
    return $job;
}

/** Attribute a verified source consistently in planning and assessment receipts. */
function vp3_initiative_source_specialist_v1(array $source): ?string
{
    require_once __DIR__.'/agent-initiative-specialist-registry-v1.php';
    return $source['specialist']??match($source['kind']??''){
        'task','task_list','open_task','task_research','reminder'=>'tasks',
        'calendar','meeting_prep','calendar_readiness','scheduling_readiness'=>'planning',
        'artist_interest','topic_interest','song_history','youtube_interest_checkin'=>'media',
        'canvas_work','canvas_feedback'=>'canvas',
        'email_canvas_opportunity'=>'gmail',
        'curiosity_finding'=>'curiosity',
        'mission','mission_evidence'=>'mission',
        'transcript','transcript_research'=>'transcripts',
        'product_context','product_readiness','order','campaign','reward'=>'commerce',
        default=>vp3_initiative_tool_specialist_v1((string)($source['capability_tool']??'')),
    };
}

/** Supply the planner with current source references and exact, already assigned work. */
function vp3_initiative_specialist_plan_context_v1(PDO $pdo,array $user,array $binding,array $tools,array $evidence): array
{
    require_once __DIR__.'/agent-initiative-specialist-registry-v1.php';
    require_once __DIR__.'/agent-initiative-runtime-v1.php';
    require_once __DIR__.'/agent-coordination-schedule-v1.php';
    $catalog=vp3_initiative_specialist_catalog_v1($tools);
    $checkpoint=vp3_coordination_scan_checkpoint_v1($user,$binding);
    foreach($checkpoint['specialists']??[] as $id=>$state){
        if(!isset($catalog[$id]))continue;
        $catalog[$id]['last_scan']=array_intersect_key($state,array_flip(['coverage','found','chat_matches','queued']));
        $catalog[$id]['last_scan']['at']=$checkpoint['at'];
    }
    foreach($evidence as $ref=>$source){
        $id=vp3_initiative_source_specialist_v1($source);
        if(isset($catalog[$id]))$catalog[$id]['current_sources'][]=$ref;
    }
    $runs=vp3_initiative_runtime_runs_v1($pdo,(int)$user['id'],vp3_initiative_binding_agent_v1($binding),$binding,true);
    foreach($runs as $run){
        if($run['capability_key']!=='initiative.prepare')continue;
        $goal=json_decode((string)$run['goal'],true,16);
        if(!is_array($goal))continue;
        $id=vp3_initiative_goal_specialist_v1($goal);
        if(!isset($catalog[$id])||count($catalog[$id]['assigned']??[])>=2)continue;
        $revisions=$goal['sources']??[];
        // This is advisory state only. Reuse exact input refs when their
        // revisions are in the current assessment, and omit all other refs.
        if(!is_array($revisions))$revisions=[];
        $current=array_filter($revisions,static fn(mixed $revision,string $ref):bool=>is_string($revision)
            &&($evidence[$ref]['revision']??null)===$revision,
            ARRAY_FILTER_USE_BOTH);
        $catalog[$id]['assigned'][]=['tool'=>$goal['tool'],
            'state'=>$run['status']==='executing'&&agent_job_utc_timestamp_v1900($run['lease_expires_at']??null)>time()
                ?'working':'queued',
            'sources'=>array_keys($current)];
    }
    if($evidence){
        try{
            foreach(vp3_initiative_specialist_completed_work_v1($pdo,$user,$binding,$evidence) as $id=>$result){
                if(isset($catalog[$id]))$catalog[$id]['latest_result']=$result;
            }
        }catch(PDOException $error){
            if(function_exists('agent_runtime_v125_trace')){
                agent_runtime_v125_trace('initiative.specialist_result_read_failed',['error_class'=>get_class($error)]);
            }
        }
    }
    return $catalog;
}

/** Only same-scope completions with an exact receipt and current evidence can inform the next plan. */
function vp3_initiative_specialist_completed_work_v1(PDO $pdo,array $user,array $binding,array $evidence): array
{
    $runs=vp3_initiative_runtime_runs_v1($pdo,(int)$user['id'],vp3_initiative_binding_agent_v1($binding),$binding);
    $candidates=[];
    foreach($runs as $run){
        if($run['status']!=='completed'||$run['capability_key']!=='initiative.prepare')continue;
        $goal=json_decode((string)$run['goal'],true,16);
        $sources=$goal['sources']??null;
        if(!is_array($sources)||!$sources||array_filter($sources,static fn(mixed $revision,string $ref):bool=>
            !is_string($revision)||($evidence[$ref]['revision']??null)!==$revision,ARRAY_FILTER_USE_BOTH))continue;
        $id=vp3_initiative_goal_specialist_v1($goal);
        if($id===null)continue;
        $candidates[(int)$run['id']]=['specialist'=>$id,'tool'=>$goal['tool'],'sources'=>array_keys($sources)];
    }
    if(!$candidates)return [];
    $slots=implode(',',array_fill(0,count($candidates),'?'));
    $binary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'BLOB':'BINARY';
    $query=$pdo->prepare("SELECT a.run_id,a.result_json FROM agent_workflow_actions a
        JOIN agent_workflow_receipts p ON p.run_id=a.run_id AND p.action_id=a.id AND p.owner_user_id=a.owner_user_id
            AND p.attempt_no=a.attempt_count AND p.status='completed' AND p.executor='cloud'
        WHERE a.owner_user_id=? AND a.run_id IN ($slots) AND a.sequence_no=1
            AND a.action_key='initiative.prepare' AND a.capability_key='initiative.prepare'
            AND a.execution_target='cloud' AND a.status='completed'
            AND CAST(a.result_json AS $binary)=CAST(p.result_json AS $binary)
        ORDER BY a.run_id DESC,p.id DESC");
    $query->execute([(int)$user['id'],...array_keys($candidates)]);
    $results=[];
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
        $candidate=$candidates[(int)$row['run_id']];
        $id=$candidate['specialist'];
        if(isset($results[$id]))continue;
        $result=json_decode((string)$row['result_json'],true,24);
        if(($result['effect_state']??null)!=='private_draft_ready')continue;
        $output=$result['initiative_tool_result']['output']??[];
        $results[$id]=['tool'=>$candidate['tool'],'sources'=>$candidate['sources'],
            'status'=>vp3_initiative_brainstorm_text_v1($output['status']??null,80)??'prepared',
            'delivery'=>match($result['initiative_delivery']??null){
                'delivered'=>'posted','pending'=>'pending','withheld'=>'withheld',default=>'private',
            }];
    }
    return $results;
}
