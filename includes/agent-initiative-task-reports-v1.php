<?php
declare(strict_types=1);

/** Recent, exact readonly task results from the existing worker and current attempt. */
function vp3_initiative_task_report_candidates_v1(PDO $pdo,array $user,array $binding,int $now,?int $runId=null,bool $activityView=false): array
{
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $json=static function(string $path,string $column='r.goal')use($sqlite):string{
        $value="JSON_EXTRACT(CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END,'$path')";
        return $sqlite?$value:"JSON_UNQUOTE($value)";
    };
    $conversation=$json('$.binding.conversation_id');$revision=$json('$.binding.revision');$tool=$json('$.tool');
    $report=$json('$.report');$true=$sqlite?'1':"'true'";$exact=$runId===null?'':' AND r.id=?';
    $delivery=$json('$.initiative_delivery','a.result_json');
    $entry=$json('$.office_entry');
    $filter=$activityView?" AND ($delivery='delivered' OR ($delivery='private' AND $entry=$true))":" AND $revision=? AND a.completed_at>=?";
    $query=$pdo->prepare("SELECT r.id AS run_id,r.goal,a.completed_at,a.result_json,c.result_json AS receipt_json
        FROM agent_workflow_runs r JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
            AND a.sequence_no=1 AND a.action_key='initiative.prepare' AND a.capability_key='initiative.prepare'
            AND a.execution_target='cloud' AND a.status='completed'
        JOIN agent_workflow_receipts c ON c.run_id=r.id AND c.action_id=a.id AND c.owner_user_id=r.owner_user_id
            AND c.attempt_no=a.attempt_count AND c.executor='cloud' AND c.status='completed'
        WHERE r.owner_user_id=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=?
            AND r.workflow_type='initiative' AND r.source_kind='agent_initiative'
            AND r.capability_key='initiative.prepare' AND r.execution_target='cloud' AND r.status='completed'
            AND CAST($conversation AS CHAR)=? AND $tool IN ('tasks.read','tasks.lists.read','reminders.read')
            AND $report=$true$filter$exact
        ORDER BY r.id DESC,c.id DESC LIMIT 8");
    $args=[(int)$user['id'],(string)vp3_initiative_binding_agent_v1($binding),(string)$binding['conversation_id']];
    if(!$activityView)array_push($args,$binding['revision'],gmdate('Y-m-d H:i:s',$now-1800));
    if($runId!==null)$args[]=$runId;
    $query->execute($args);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function vp3_initiative_task_report_v1(PDO $pdo,array $user,array $binding,array $row,int $now,?array $activityScope=null): ?array
{
    if(!is_string($row['result_json'])||$row['result_json']!==$row['receipt_json'])return null;
    $goal=json_decode((string)$row['goal'],true);
    $ref=$goal['primary']??null;
    $kind=match($goal['tool']??null){'tasks.read'=>'task','tasks.lists.read'=>'task_list','reminders.read'=>'reminder',default=>null};
    $savedBinding=$goal['binding']??null;
    if($kind===null||!is_array($savedBinding)||($activityScope===null&&$savedBinding!==$binding)||($goal['report']??null)!==true
        ||isset($goal['followup'])||isset($goal['followthrough'])
        ||!is_string($ref)||!is_array($goal['sources']??null)||array_keys($goal['sources'])!==[$ref])return null;

    $completed=agent_job_utc_timestamp_v1900($row['completed_at']);
    $result=json_decode($row['result_json'],true);
    $work=$result['initiative_tool_result']??null;
    if(($activityScope===null&&$completed<$now-1800)||$completed<1||$completed>$now||($result['effect_state']??null)!=='private_draft_ready'
        ||!in_array($result['initiative_delivery']??null,['private','pending','delivered'],true)||!is_array($work)
        ||($work['output']['type']??null)!=='initiative_capability_read'||($work['output']['status']??null)!=='read_complete'
        ||($work['output']['tool']??null)!==$goal['tool']||($work['tool_receipt']['changes_applied']??null)!==false
        ||!is_string($work['draft']??null)||$work['draft']!==($result['initiative_draft']??null))return null;
    $disposition=$work['output']['office_disposition']??null;
    if($disposition==='ignore'||($activityScope===null&&$disposition==='note'))return null;
    $delivery=[];
    $draft=$work['draft'];
    if($activityScope===null){
        vp3_initiative_result_current_v1($pdo,$user,$binding,$goal,$work);
    }else{
        $storedId=$result['initiative_message_id']??null;
        $messageId=(is_int($storedId)||is_string($storedId))?filter_var($storedId,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):false;
        $runId=(int)$row['run_id'];
        $owner=(int)$activityScope['user']['id'];
        $agent=$activityScope['agent_id'];
        if(($savedBinding['conversation_id']??null)!==$activityScope['conversation_id']
            ||vp3_initiative_binding_agent_v1($savedBinding)!==$agent
            ||($savedBinding['owner_user_id']??($agent===0?$owner:null))!==$owner)return null;
        if($result['initiative_delivery']==='delivered'){
            if($messageId===false||!vp3_initiative_task_report_message_v1($pdo,$activityScope,$messageId,$runId,$work))return null;
        }elseif($result['initiative_delivery']!=='private'||($goal['office_entry']??null)!==true||!in_array($storedId,[0,'0'],true)){
            return null;
        }
        $read=vp3_initiative_capability_result_current_v1($pdo,['verification_only'=>true]+$activityScope,$savedBinding,$goal,$work);
        $delivery=['message_id'=>$messageId===false?0:$messageId,'run_id'=>$runId,'title'=>$read['records'][0]['title']??'',
            'source_ref'=>$ref];
        $fields=[];$suffix='';
        foreach(['next_step'=>'Next step','followup_question'=>'Question for you'] as $field=>$label){
            if(!is_string($work['output'][$field]??null)||$work['output'][$field]==='')continue;
            $fields[$field]=$work['output'][$field];
            $suffix.="\n\n$label: ".$fields[$field];
        }
        if($suffix!==''&&str_ends_with($draft,$suffix)&&strlen($draft)>strlen($suffix)){
            $draft=substr($draft,0,-strlen($suffix));
            if($disposition!=='note')$delivery+=$fields;
        }
    }
    $summary=vp3_top_of_mind_text_v1($draft,500);
    if($summary==='')return null;

    return ['report'=>['specialist'=>'tasks','state'=>'ready','source_kind'=>$kind,'checked_at'=>gmdate(DATE_ATOM,$completed),
        'record_count'=>$work['output']['record_count'],'summary'=>$summary],
        'proof'=>hash('sha256',json_encode([$row['run_id'],$completed,$work],JSON_THROW_ON_ERROR))]+$delivery;
}

/** An exact per-entry classification can supersede a legacy report from any authorized Chat. */
function vp3_initiative_task_report_classifications_v1(PDO $pdo,array $user,array $binding,array $rows,int $now,?array $scope=null): array
{
    $rows=array_filter($rows,static function(array $row):bool{
        $goal=json_decode((string)$row['goal'],true);
        return ($goal['office_entry']??null)===true;
    });
    if(!$rows)return [];
    require_once __DIR__.'/agent-initiative-office-entry-projection-v1.php';
    require_once __DIR__.'/agent-activity-scope-v1.php';
    $scope??=vp3_agent_activity_scope_v1($pdo,$user,vp3_initiative_binding_agent_v1($binding),$binding['conversation_id']);
    return vp3_initiative_office_entry_classified_runs_v1($pdo,$scope,$rows,$now);
}

function vp3_initiative_task_report_superseded_v1(array $row,array $classified): bool
{
    if(!$classified)return false;
    $goal=json_decode((string)$row['goal'],true);
    $ref=$goal['primary']??null;
    return ($goal['office_entry']??null)===true&&is_string($ref)&&isset($classified[$ref])
        &&$classified[$ref]>(int)($row['run_id']??$row['id']);
}

function vp3_initiative_task_report_message_v1(PDO $pdo,array $scope,int $messageId,int $runId,array $work): bool
{
    $query=$pdo->prepare("SELECT message,context_json FROM chat_messages WHERE id=? AND conversation_id=? AND role='assistant' AND user_id IS NULL");
    $query->execute([$messageId,$scope['conversation_id']]);
    $message=$query->fetch(PDO::FETCH_ASSOC);
    if(!$message||$message['message']!==$work['draft'])return false;
    $context=json_decode((string)$message['context_json'],true);
    $marker=$context['initiative_work']??null;
    if(($context['source']??null)!=='agent_initiative'||($context['initiative_run_id']??null)!==$runId
        ||($marker['run_id']??null)!==$runId||($marker['owner_user_id']??null)!==(int)$scope['user']['id']
        ||($marker['agent_id']??null)!==$scope['agent_id']||($marker['conversation_id']??null)!==$scope['conversation_id'])return false;
    $displayed=agent_workflow_initiative_result_v1400(['output'=>$context['initiative_output']??null,'tool_receipt'=>$context['tool_receipt']??null]);
    return ($displayed['output']??null)===$work['output']&&($displayed['tool_receipt']??null)===$work['tool_receipt'];
}

/** Current receipt-backed reports remain available within their original saved Chat. */
function vp3_initiative_task_activity_reviews_v1(PDO $pdo,array $scope,int $now): array
{
    $user=$scope['user'];
    $binding=['conversation_id'=>$scope['conversation_id'],'agent_id'=>$scope['agent_id']];
    $reviews=[];$seen=[];
    $rows=vp3_initiative_task_report_candidates_v1($pdo,$user,$binding,$now,null,true);
    $classified=vp3_initiative_task_report_classifications_v1($pdo,$user,$binding,$rows,$now,$scope);
    foreach($rows as $row){
        $runId=(int)$row['run_id'];
        if(isset($reviews[$runId])||vp3_initiative_task_report_superseded_v1($row,$classified))continue;
        try{$verified=vp3_initiative_task_report_v1($pdo,$user,$binding,$row,$now,$scope);}
        catch(JsonException $error){continue;}
        catch(RuntimeException $error){if(in_array($error->getCode(),[403,409],true))continue;throw $error;}
        if($verified===null||isset($seen[$verified['source_ref']]))continue;
        $seen[$verified['source_ref']]=true;
        $reviews[$runId]=$verified;
        if(count($reviews)>=3)break;
    }
    return array_values($reviews);
}

/** Only delivered research for currently authorized task topics in this saved Chat. */
function vp3_initiative_task_activity_research_v1(PDO $pdo,array $scope,int $now): array
{
    require_once __DIR__.'/agent-initiative-research-status-v1.php';
    $owner=(int)$scope['user']['id'];$agentId=$scope['agent_id'];$conversation=$scope['conversation_id'];
    $rows=vp3_initiative_research_status_candidates_v1($pdo,$owner,$agentId,['conversation_id'=>$conversation],$now,taskReport:true);
    $completed=[];$refs=[];
    foreach($rows as $row){
        $goal=json_decode((string)$row['goal'],true);
        $binding=$goal['binding']??null;$primary=$goal['primary']??null;$sources=$goal['sources']??null;
        if(!is_array($binding)||($binding['enabled']??null)!==true||($binding['conversation_id']??null)!==$conversation
            ||($binding['agent_id']??0)!==$agentId||($binding['owner_user_id']??($agentId===0?$owner:null))!==$owner
            ||!is_string($binding['revision']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$binding['revision'])
            ||($goal['tool']??null)!=='topic.research'||($goal['report']??null)!==true
            ||!is_string($primary)||vp3_initiative_task_research_id_v1($primary)===null
            ||!is_array($sources)||array_keys($sources)!==[$primary]
            ||!is_string($sources[$primary])||!preg_match('/\A[a-f0-9]{64}\z/D',$sources[$primary]))continue;
        $proof=vp3_initiative_research_status_completion_v1($pdo,$owner,$agentId,$conversation,$row,$goal,$now,true);
        if(($proof['state']??null)!=='completed')continue;
        $result=json_decode($row['result_json'],true);
        $research=vp3_initiative_research_tool_result_v1($result['initiative_tool_result']);
        $summary=vp3_top_of_mind_text_v1($research['draft']??null,500);
        if($summary==='')continue;
        $completed[]=['ref'=>$primary,'revision'=>$sources[$primary],'topic'=>$research['output']['public_topic']??null,
            'review'=>['run_id'=>(int)$row['run_id'],'message_id'=>$proof['message_id'],
                'report'=>['source_kind'=>'task_research','summary'=>$summary]]];
        $refs[$primary]=true;
    }
    if(!$completed)return [];

    // Read the grant and the canonical source again without using a research provider.
    $coverage=[];
    $current=vp3_initiative_task_research_evidence_v1($pdo,$scope,array_keys($refs),$coverage);
    $reviews=[];
    foreach($completed as $candidate){
        $ref=$candidate['ref'];$source=$current[$ref]??null;
        if(isset($reviews[$ref])||($source['revision']??null)!==$candidate['revision']||($source['title']??null)!==$candidate['topic'])continue;
        $reviews[$ref]=$candidate['review']+['title'=>$source['title']];
        if(count($reviews)>=3)break;
    }
    return array_values($reviews);
}

function vp3_initiative_task_reports_v1(PDO $pdo,array $user,array $binding,int $now,array &$proofs): array
{
    $reports=[];$seen=[];
    $rows=vp3_initiative_task_report_candidates_v1($pdo,$user,$binding,$now);
    $classified=vp3_initiative_task_report_classifications_v1($pdo,$user,$binding,$rows,$now);
    foreach($rows as $row){
        $runId=(int)$row['run_id'];
        if(isset($seen[$runId])||vp3_initiative_task_report_superseded_v1($row,$classified))continue;
        $seen[$runId]=true;
        try{$verified=vp3_initiative_task_report_v1($pdo,$user,$binding,$row,$now);}
        catch(RuntimeException|JsonException $error){continue;}
        if($verified===null)continue;
        $reports[]=$verified['report'];$proofs[$runId]=$verified['proof'];
        if(count($reports)>=2)break;
    }
    return $reports;
}

function vp3_initiative_task_reports_current_v1(PDO $pdo,array $user,array $binding,array $proofs,int $now): void
{
    foreach($proofs as $runId=>$proof){
        try{
            $row=vp3_initiative_task_report_candidates_v1($pdo,$user,$binding,$now,(int)$runId)[0]??null;
            $classified=vp3_initiative_task_report_classifications_v1($pdo,$user,$binding,$row===null?[]:[$row],$now);
            $verified=$row===null||vp3_initiative_task_report_superseded_v1($row,$classified)
                ?null:vp3_initiative_task_report_v1($pdo,$user,$binding,$row,$now);
            if(($verified['proof']??null)===$proof)continue;
        }catch(RuntimeException|JsonException $error){}
        throw new Vp3InitiativeSourcesChangedV1();
    }
}
