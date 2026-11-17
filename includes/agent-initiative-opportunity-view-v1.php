<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-runtime-v1.php';
require_once __DIR__.'/agent-initiative-brainstorm-v1.php';
require_once __DIR__.'/agent-initiative-specialist-registry-v1.php';

/** A private read model of the latest verified assessment, never an action grant. */
function vp3_initiative_opportunities_v1(PDO $pdo,array $user,int $agentId): array
{
    $verified=vp3_initiative_verified_opportunities_v1($pdo,$user,$agentId);
    return array_map(static function(array $row)use($verified):array{
        $item=[
            'id'=>$row['id'],'run_id'=>$verified['run_id'],'objective'=>$row['objective'],'rationale'=>$row['rationale'],
            'state'=>$row['status'],'conversation_id'=>$verified['conversation_id'],
        ];
        $handoff=vp3_initiative_handoff_view_v1($row['tool'],$row['source_revisions'],$verified['evidence']);
        if($handoff!==null)$item['handoff']=$handoff;
        return $item;
    },$verified['rows']??[]);
}

/** Server-only evidence for schedulers. Model wording is confined to the exact verified assessment. */
function vp3_initiative_verified_opportunities_v1(PDO $pdo,array $user,int $agentId,?string $onlyTool=null,?string $opportunityLane=null): array
{
    return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$user,$agentId,$onlyTool,$opportunityLane):array{
        if(vp3_participant_scope_v1()!==null)throw new RuntimeException('Private Agent access is required.',403);
        $policy=vp3_initiative_policy_v1($user,$agentId);
        $cid=$policy['conversation_id']??0;
        if(($policy['enabled']??false)!==true||!is_int($cid)||$cid<1)return [];
        $context=vp3_initiative_runtime_context_v1($pdo,$user,$agentId,$cid);
        if($context['policy']!==$policy)throw new RuntimeException('Agent work scope changed.',409);
        $live=vp3_initiative_access_v1($pdo,$context['user'],$policy);
        $rows=[];$goal=[];$runId=0;
        foreach(vp3_initiative_runtime_assessment_rows_v1($pdo,(int)$live['id'],$agentId,$policy,true,false,$opportunityLane) as $run){
            if(!is_string($run['receipt_json'])||$run['receipt_json']!==$run['result_json'])continue;
            $result=json_decode($run['receipt_json'],true);
            if(!is_array($result)||!in_array($result['effect_state']??null,['assignments_queued','existing_assignments','idle'],true))continue;
            $typed=vp3_initiative_brainstorm_tool_result_v1(is_array($result['initiative_tool_result']??null)?$result['initiative_tool_result']:[]);
            // A legacy or empty latest assessment supersedes older ideas too.
            $rows=$typed['output']['opportunities']??[];
            if($onlyTool!==null)$rows=array_values(array_filter($rows,static fn(array $row):bool=>$row['tool']===$onlyTool));
            if($opportunityLane==='app_suggestions'&&$rows===[])continue;
            $goal=json_decode((string)$run['goal'],true);$runId=(int)$run['id'];
            break;
        }
        if(!$rows||!is_array($goal['sources']??null))return [];
        $selected=$goal['sources'];
        if(!$selected||count($selected)>512)throw new RuntimeException('Assessment source binding changed.',409);
        foreach($selected as $ref=>$revision){
            if(!is_string($ref)||$ref===''||strlen($ref)>190||!is_string($revision)||!preg_match('/\A[a-f0-9]{64}\z/D',$revision)){
                throw new RuntimeException('Assessment source binding changed.',409);
            }
        }
        foreach($rows as $row)foreach($row['source_revisions'] as $ref=>$revision){
            if(($goal['sources'][$ref]??null)!==$revision)throw new RuntimeException('Assessment source binding changed.',409);
        }
        $read=static function(array $live)use($pdo,$policy,$selected,$goal,$agentId):array{
            // The model saw the entire assessment snapshot. Revalidate all of
            // it, not just model-declared refs, before releasing private text.
            return vp3_initiative_current_sources_v1($pdo,$live,$selected,!empty($policy['screen_enabled']),
                $goal['canonical_sources']??[],$agentId,$goal['capability_sources']??[]);
        };
        $current=$read($live);
        require_once __DIR__.'/agent-initiative-tools-v1.php';
        $tools=vp3_initiative_tools_catalog_v1($pdo,$live,$policy,$current);
        $requiresReady=$opportunityLane!=='app_suggestions';
        $filter=static function(array $rows,array $sources,array $tools)use($requiresReady):array{
            return array_values(array_filter($rows,static function(array $row)use($sources,$tools,$requiresReady):bool{
                $tool=$tools[$row['tool']]??[];
                if(!$tool||($requiresReady&&empty($tool['ready']))
                    ||isset($tool['source_refs'])&&!in_array($row['primary'],$tool['source_refs'],true))return false;
                foreach($row['source_revisions'] as $ref=>$revision)if(($sources[$ref]['revision']??null)!==$revision)return false;
                return true;
            }));
        };
        $rows=$filter($rows,$current,$tools);
        $fresh=vp3_initiative_runtime_context_v1($pdo,$live,$agentId,$cid);
        if($fresh['policy']!==$policy)throw new RuntimeException('Agent work scope changed.',409);
        $live=vp3_initiative_access_v1($pdo,$fresh['user'],$policy);
        $final=$read($live);
        $rows=$filter($rows,$final,vp3_initiative_tools_catalog_v1($pdo,$live,$policy,$final));
        // Match the original assessment's source order for preparation dedupe.
        return ['rows'=>$rows,'run_id'=>$runId,'conversation_id'=>$cid,'evidence'=>array_replace($selected,$final)];
    });
}

/** Read actionable work and the dedicated assessment independently of the general Ideas preview. */
function vp3_initiative_canvas_review_v1(PDO $pdo,array $user,array $principal,int $agentId,int $cid): array
{
    if($cid<1)return ['items'=>[],'coverage'=>'disabled'];
    return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$user,$principal,$agentId,$cid):array{
        $context=vp3_initiative_runtime_context_v1($pdo,$user,$agentId,$cid);
        $policy=$context['policy'];
        if(($policy['enabled']??false)!==true||($policy['conversation_id']??null)!==$cid)return ['items'=>[],'coverage'=>'disabled'];
        $live=vp3_initiative_access_v1($pdo,$context['user'],$policy);
        $owner=(int)$live['id'];
        $recent=vp3_initiative_runtime_runs_v1($pdo,$owner,$agentId,$policy,opportunityLane:'app_suggestions');
        $pending=vp3_initiative_runtime_runs_v1($pdo,$owner,$agentId,$policy,pending:true,opportunityLane:'app_suggestions');
        $runs=array_filter(array_replace(array_column($pending,null,'id'),array_column($recent,null,'id')),
            static fn(array $run):bool=>($run['capability_key']??null)==='initiative.prepare'
                &&in_array($run['status'],['approved','queued','executing','completed'],true));
        $items=[];$covered=[];$shown=[];$coverage='ready';
        foreach(array_slice($runs,0,8) as $run){
            $goal=json_decode((string)$run['goal'],true);
            if(($goal['tool']??null)!=='canvas.prepare'||!is_string($goal['primary']??null)||isset($shown[$goal['primary']]))continue;
            try{
                $primary=$goal['primary'];
                $sources=vp3_initiative_work_sources_v1($pdo,$live,$policy,$goal);
                $item=vp3_initiative_canvas_review_work_v1($pdo,$live,$principal,$policy,$run,$goal,$sources);
                if($item===null)continue;
                $covered[$primary]=true;
                if(empty($item['suppressed'])){$items[]=$item;$shown[$primary]=true;}
            }catch(RuntimeException|JsonException $error){
                if(!in_array($error->getCode(),[403,404,409],true))$coverage='limited';
            }
            if(count($items)>=4)break;
        }
        try{
            $verified=vp3_initiative_verified_opportunities_v1($pdo,$live,$agentId,'canvas.prepare','app_suggestions');
            foreach($verified['rows']??[] as $row){
                if(isset($covered[$row['primary']]))continue;
                $sources=array_intersect_key($verified['evidence'],$row['source_revisions']);
                $items[]=['id'=>'canvas-idea:'.$row['id'],'title'=>$row['objective'],'detail'=>$row['rationale'],
                    'state'=>'considered','sources'=>vp3_initiative_canvas_review_sources_v1($sources),
                    'handoff'=>vp3_initiative_handoff_view_v1('canvas.prepare',$row['source_revisions'],$sources)];
            }
        }catch(RuntimeException|JsonException $error){$coverage='limited';}
        if(vp3_initiative_runtime_context_v1($pdo,$live,$agentId,$cid)['policy']!==$policy){
            throw new RuntimeException('Canvas Suggestions scope changed.',409);
        }
        return ['items'=>$items,'coverage'=>$coverage];
    });
}

function vp3_initiative_canvas_review_work_v1(PDO $pdo,array $user,array $principal,array $policy,array $run,array $goal,array $sources): ?array
{
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    require_once __DIR__.'/agent-initiative-canvas-v1.php';
    $runId=(int)$run['id'];
    $query=$pdo->prepare("SELECT a.id,a.status,a.result_json,
        (SELECT p.result_json FROM agent_workflow_receipts p WHERE p.run_id=a.run_id AND p.action_id=a.id
            AND p.owner_user_id=a.owner_user_id AND p.attempt_no=a.attempt_count AND p.status='completed' AND p.executor='cloud'
            ORDER BY p.id DESC LIMIT 1) AS receipt
        FROM agent_workflow_actions a WHERE a.run_id=? AND a.owner_user_id=? AND a.sequence_no=1
        AND a.action_key='initiative.prepare' AND a.capability_key='initiative.prepare' AND a.execution_target='cloud' LIMIT 1");
    $query->execute([$runId,(int)$user['id']]);
    $action=$query->fetch(PDO::FETCH_ASSOC);
    if(!$action)return null;
    $state=match($run['status']){
        'approved','queued'=>$action['status']==='queued'?'queued':'',
        'executing'=>$action['status']==='executing'&&(int)$run['current_action_id']===(int)$action['id']
            &&agent_job_utc_timestamp_v1900($run['lease_expires_at']??null)>time()?'working':'',
        'completed'=>$action['status']==='completed'&&is_string($action['receipt'])&&$action['receipt']===$action['result_json']?'completed':'',
        default=>'',
    };
    if($state==='')return null;
    $item=['id'=>'canvas-work:'.$runId,'state'=>$state,'title'=>$goal['focus']??'Canvas proposal','detail'=>'',
        'sources'=>vp3_initiative_canvas_review_sources_v1($sources),
        'handoff'=>vp3_initiative_handoff_view_v1('canvas.prepare',$goal['sources'],$sources)];
    if($state!=='completed')return $item;
    $result=json_decode($action['receipt'],true,32,JSON_THROW_ON_ERROR);
    if(($result['effect_state']??null)!=='private_draft_ready'||!in_array($result['initiative_delivery']??null,['pending','delivered'],true))return null;
    $tool=vp3_initiative_canvas_tool_result_v1($result['initiative_tool_result']??[]);
    if($tool===null||$tool['output']['parent_run_id']!==$runId)return null;
    vp3_initiative_canvas_result_current_v1($pdo,$user,$policy,$goal,$tool);
    $item['title']=$tool['output']['proposal']['title'];
    $item['detail']=$tool['draft']??'';
    $item['state']=$tool['output']['status']==='draft_queued'?'draft_queued':'pending';
    if($result['initiative_delivery']==='delivered'){
        $message=filter_var($result['initiative_message_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($message===false)return null;
        $post=vp3_initiative_followthrough_load_v1($pdo,$user,$principal,$policy['conversation_id'],$message);
        $ownerState=$post['state']['state']??null;
        if((int)$post['run']['id']!==$runId)return null;
        if(in_array($ownerState,['deferred','declined','owner_resolved','completed'],true))return ['suppressed'=>true];
        if(!in_array($ownerState,['open','discussed','queued'],true))return null;
        $item['message_id']=$message;
        if($tool['output']['status']==='proposal_ready')$item['state']=match($ownerState){
            'queued'=>'draft_queued','discussed'=>'discussed',default=>'proposal_ready',
        };
    }
    if($tool['output']['status']==='draft_queued'){
        $item=array_replace($item,vp3_initiative_canvas_review_draft_v1($pdo,$user,$policy,$tool['output']['coding_run_id']));
    }
    return $item;
}

/** A requested generation is ready only when the Coding draft's own Chat card verifies. */
function vp3_initiative_canvas_review_draft_v1(PDO $pdo,array $user,array $policy,int $runId): array
{
    require_once __DIR__.'/coding-canvas-chat-v1.php';
    $run=vp3_coding_canvas_job_row_v1($pdo,$user,$runId);
    if(!in_array($run['status'],['completed','failed'],true))return [];
    $query=$pdo->prepare("SELECT a.status,p.result_json,a.result_json AS action_result FROM agent_workflow_actions a
        LEFT JOIN agent_workflow_receipts p ON p.run_id=a.run_id AND p.action_id=a.id AND p.owner_user_id=a.owner_user_id
            AND p.attempt_no=a.attempt_count AND p.status=a.status AND p.executor='cloud'
        WHERE a.run_id=? AND a.owner_user_id=? AND a.sequence_no=1 AND a.capability_key=? AND a.execution_target='cloud'
        ORDER BY p.id DESC LIMIT 1");
    $query->execute([$runId,(int)$user['id'],$run['capability_key']]);
    $proof=$query->fetch(PDO::FETCH_ASSOC);
    if(($proof['status']??null)!==$run['status'])return [];
    if($run['status']==='failed')return ['state'=>'draft_failed'];
    if(!is_string($proof['result_json'])||$proof['result_json']!==$proof['action_result'])return [];
    $receipt=json_decode($proof['result_json'],true);
    $message=filter_var($receipt['canvas_message_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($message===false||vp3_coding_canvas_chat_card_v1($pdo,$user,vp3_initiative_binding_agent_v1($policy),$policy['conversation_id'],$runId,$message)===null)return [];
    return ['state'=>'draft_ready','message_id'=>$message];
}

function vp3_initiative_canvas_review_sources_v1(array $sources): array
{
    $titles=[];
    foreach(array_slice($sources,0,3) as $source){
        $title=vp3_initiative_brainstorm_text_v1($source['title']??null,190);
        if($title===null)continue;
        $specialist=$source['specialist']??match($source['kind']??''){
            'task','task_list','open_task','task_research','reminder'=>'tasks',
            'calendar','meeting_prep','calendar_readiness','scheduling_readiness'=>'planning',
            'artist_interest','topic_interest','song_history','youtube_play_analysis'=>'media',
            'canvas_work','canvas_feedback'=>'canvas',
            'email_canvas_opportunity'=>'gmail',
            'curiosity_finding'=>'curiosity',
            'transcript','transcript_research'=>'transcripts',
            'chat_history'=>'brainstorm',
            default=>null,
        };
        $titles[$title]=['title'=>$title,'specialist'=>$specialist];
    }
    return array_values($titles);
}
