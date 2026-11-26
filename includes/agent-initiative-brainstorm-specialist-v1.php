<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-specialist-reports-v1.php';
require_once __DIR__.'/agent-initiative-specialist-registry-v1.php';

function vp3_brainstorm_ref_v1(array $user,array $binding): string
{
    return 'brainstorm-context:'.(int)$user['id'].':'.vp3_initiative_binding_agent_v1($binding);
}

/** Every registered specialist contributes permitted facts, capabilities, or an explicit gap. */
function vp3_brainstorm_briefs_v1(array $catalog,array $evidence,array $reports,array $coverage=[]): array
{
    $briefs=[];
    foreach($catalog as $id=>$entry){
        if(in_array($id,['ob1','brainstorm'],true))continue;
        $briefs[$id]=['label'=>$entry['label'],'state'=>'no_current_context',
            'ready_tools'=>$entry['ready_tools'],'sources'=>[],'reports'=>[]];
        if(in_array($coverage['specialist_'.$id]??null,['unavailable','forbidden','unconfigured'],true)){
            $briefs[$id]['state']=$coverage['specialist_'.$id];
        }
    }
    foreach($evidence as $ref=>$source){
        $id=vp3_initiative_source_specialist_v1($source);
        if(!isset($briefs[$id])||count($briefs[$id]['sources'])>=2)continue;
        $briefs[$id]['sources'][]=$ref;
        $briefs[$id]['state']='current_context';
    }
    foreach($reports as $report){
        $id=($report['specialist']??null)==='email'?'gmail':($report['specialist']??null);
        if(!isset($briefs[$id])||count($briefs[$id]['reports'])>=2)continue;
        $briefs[$id]['reports'][]=$report;
        $briefs[$id]['state']='current_context';
    }
    ksort($briefs);
    return $briefs;
}

/** Existing executors contribute verified results under the same exact source and Agent binding. */
function vp3_brainstorm_completed_reports_v1(PDO $pdo,array $user,array $binding,array $evidence): array
{
    $binary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'BLOB':'BINARY';
    $q=$pdo->prepare("SELECT r.goal,a.result_json FROM agent_workflow_runs r
        JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
            AND a.sequence_no=1 AND a.status='completed' AND a.capability_key='initiative.prepare'
            AND a.action_key='initiative.prepare' AND a.execution_target='cloud'
        JOIN agent_workflow_receipts p ON p.id=(SELECT MAX(prior.id) FROM agent_workflow_receipts prior
            WHERE prior.run_id=r.id AND prior.action_id=a.id AND prior.owner_user_id=r.owner_user_id
            AND prior.attempt_no=a.attempt_count AND prior.executor='cloud')
        WHERE r.owner_user_id=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=?
            AND r.workflow_type='initiative' AND r.source_kind='agent_initiative' AND r.capability_key='initiative.prepare'
            AND r.execution_target='cloud' AND r.status='completed' AND p.status='completed'
            AND CAST(a.result_json AS $binary)=CAST(p.result_json AS $binary) ORDER BY r.id DESC LIMIT 24");
    $q->execute([(int)$user['id'],(string)vp3_initiative_binding_agent_v1($binding)]);
    $reports=[];$counts=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $goal=json_decode((string)$row['goal'],true,16);$saved=json_decode((string)$row['result_json'],true,32);
        if(!is_array($goal)||($goal['binding']??null)!==$binding||($saved['effect_state']??null)!=='private_draft_ready')continue;
        $id=vp3_initiative_goal_specialist_v1($goal);
        if($id===null||in_array($id,['ob1','brainstorm','operator','gmail'],true)||($counts[$id]??0)>=2
            ||empty($goal['sources'])||!is_array($goal['sources'])||!is_array($saved['initiative_tool_result']??null))continue;
        foreach($goal['sources'] as $ref=>$revision)if(($evidence[$ref]['revision']??null)!==$revision)continue 2;
        if(($saved['initiative_draft']??null)!==($saved['initiative_tool_result']['draft']??null))continue;
        try{
            vp3_initiative_work_sources_v1($pdo,$user,$binding,$goal);
            vp3_initiative_result_current_v1($pdo,$user,$binding,$goal,$saved['initiative_tool_result'],$evidence);
        }catch(PDOException $error){throw $error;}
        catch(RuntimeException $error){if(!in_array($error->getCode(),[403,404,409,503],true))throw $error;continue;}
        $summary=vp3_initiative_brainstorm_text_v1(trim((string)$saved['initiative_draft']),8000);
        if($summary===null)continue;
        $reports[]=['specialist'=>$id,'state'=>'ready','source_ref'=>$goal['primary'],
            'summary'=>mb_substr($summary,0,700)];
        $counts[$id]=($counts[$id]??0)+1;
    }
    return $reports;
}

/** Reuse authorized adapters. No raw sensing, mailbox content, call data, or self-generated report loop. */
function vp3_brainstorm_snapshot_v1(PDO $pdo,array $user,array $binding,?array $base=null,array $coverage=[]): array
{
    $live=vp3_initiative_access_v1($pdo,$user,$binding);
    if($base===null)$base=vp3_initiative_evidence_v1($pdo,$live,new DateTimeImmutable('now',new DateTimeZone('UTC')),
        false,[],$coverage,null,vp3_initiative_binding_agent_v1($binding),false);
    $evidence=[];$counts=[];
    foreach($base as $ref=>$source){
        if(in_array($source['kind']??null,['brainstorm_context','live_context','eyes_visit','screen_followup','email_canvas_opportunity'],true)
            ||in_array($source['specialist']??null,['gmail','operator','brainstorm','ob1'],true))continue;
        $id=vp3_initiative_source_specialist_v1($source)??'context';
        if(($counts[$id]??0)>=2)continue;
        $counts[$id]=($counts[$id]??0)+1;
        $evidence[$ref]=$source;
    }
    $authority=[];
    $reports=vp3_initiative_specialist_reports_v1($pdo,$live,$binding,$evidence,$authority,false,false);
    $reports=array_merge($reports,vp3_brainstorm_completed_reports_v1($pdo,$live,$binding,$evidence));
    $tools=vp3_initiative_tools_catalog_v1($pdo,$live,$binding,$evidence);
    unset($tools['brainstorm.explore']);
    $briefs=vp3_brainstorm_briefs_v1(vp3_initiative_specialist_catalog_v1($tools),$evidence,$reports,$coverage);
    $toolState=[];
    foreach($tools as $id=>$entry)$toolState[$id]=array_intersect_key($entry,array_flip(
        ['ready','reason','source_refs','context_refs','approval_required_source_refs','auto_drafts_enabled']));
    ksort($evidence);ksort($toolState);
    // Volatile scan timestamps and our own pending/completed work do not invent novelty.
    $revision=vp3_initiative_brainstorm_semantic_v1(['binding'=>$binding,
        'sources'=>array_column($evidence,'revision','ref'),
        'reports'=>vp3_initiative_brainstorm_report_keys_v1($reports),'tools'=>$toolState,
        'coverage'=>array_map(static fn(array $brief):array=>array_intersect_key($brief,array_flip(['state','sources','ready_tools'])),$briefs)]);
    return ['revision'=>$revision,'evidence'=>$evidence,'reports'=>$reports,'briefs'=>$briefs,'tools'=>$tools];
}

/** A private aggregate reference authorizes only the creative worker, never a downstream tool. */
function vp3_brainstorm_evidence_v1(PDO $pdo,array $user,array $binding,?array $selectedRefs,array $base,array $coverage): array
{
    if(empty($binding['enabled'])||(int)($binding['conversation_id']??0)<1)return [];
    $ref=vp3_brainstorm_ref_v1($user,$binding);
    if($selectedRefs!==null&&!in_array($ref,$selectedRefs,true))return [];
    $snapshot=vp3_brainstorm_snapshot_v1($pdo,$user,$binding,$selectedRefs===null?$base:null,$coverage);
    if(!$snapshot['evidence']&&!$snapshot['reports'])return [];
    return [$ref=>['ref'=>$ref,'kind'=>'brainstorm_context','title'=>'Current specialist briefs',
        'detail'=>'Private creative exploration across permitted specialist inputs. Only brainstorm.explore accepts this reference.',
        'url'=>url('/chat.php?agent='.vp3_initiative_binding_agent_v1($binding).'&conversation_id='.$binding['conversation_id']),
        'revision'=>$snapshot['revision'],'brainstorm_context'=>$snapshot]];
}

function vp3_brainstorm_catalog_v1(array $evidence): array
{
    $refs=array_keys(array_filter($evidence,static fn(array $row):bool=>($row['kind']??null)==='brainstorm_context'));
    return $refs?['brainstorm.explore'=>['id'=>'brainstorm.explore','capability'=>'initiative.prepare','mode'=>'private',
        'ready'=>true,'reason'=>'ready','source_refs'=>$refs,'context_refs'=>$refs,
        'description'=>'Ask the separate Brainstorming specialist to explore useful ideas across all permitted specialist briefs. It returns ideas privately to OB1. It cannot dispatch work, generate images, build an app or publish. Set report to false.']]:[];
}

function vp3_brainstorm_draft_v1(array $ideas): string
{
    if(!$ideas)return 'No additional useful opportunities were found in the current specialist briefs.';
    return "Ideas prepared for OB1 review:\n\n".implode("\n\n",array_map(static fn(array $idea):string=>
        $idea['objective']."\n".$idea['rationale'],$ideas));
}

/** Private storage validates the exact schema; source/tool authority is checked separately. */
function vp3_brainstorm_tool_result_v1(array $value): ?array
{
    $out=$value['output']??null;
    if(array_keys($value)!==['draft','output']||!is_array($out)
        ||array_keys($out)!==['type','status','context_ref','context_revision','ideas','input_specialists']
        ||$out['type']!=='initiative_brainstorm_report'
        ||!is_string($out['context_ref'])||!preg_match('/\Abrainstorm-context:[1-9][0-9]{0,15}:[0-9]{1,16}\z/D',$out['context_ref'])
        ||!is_string($out['context_revision'])||!preg_match('/\A[a-f0-9]{64}\z/D',$out['context_revision'])
        ||!is_array($out['ideas'])||!array_is_list($out['ideas'])||count($out['ideas'])>3
        ||$out['status']!==($out['ideas']?'ideas_ready':'no_opportunity')
        ||!is_array($out['input_specialists'])||!array_is_list($out['input_specialists'])
        ||count($out['input_specialists'])>32||count(array_unique($out['input_specialists']))!==count($out['input_specialists']))return null;
    foreach($out['input_specialists'] as $id){
        if(!is_string($id)||!preg_match('/\A[a-z][a-z0-9-]{0,47}\z/D',$id))return null;
    }
    foreach($out['ideas'] as $idea){
        if(!is_array($idea)||array_keys($idea)!==['tool','primary','refs','objective','rationale']
            ||!is_string($idea['tool'])||!preg_match('/\A[a-z][a-z0-9_.]{0,79}\z/D',$idea['tool'])
            ||!is_string($idea['primary'])||strlen($idea['primary'])>190
            ||!is_array($idea['refs'])||!array_is_list($idea['refs'])||!$idea['refs']||count($idea['refs'])>5
            ||!is_string($idea['objective'])||!is_string($idea['rationale'])
            ||vp3_initiative_brainstorm_text_v1($idea['objective'],300)!==$idea['objective']
            ||vp3_initiative_brainstorm_text_v1($idea['rationale'],500)!==$idea['rationale'])return null;
        foreach($idea['refs'] as $ref)if(!is_string($ref)||$ref===''||strlen($ref)>190)return null;
        if(!in_array($idea['primary'],$idea['refs'],true)||count(array_unique($idea['refs']))!==count($idea['refs']))return null;
    }
    if($value['draft']!==vp3_brainstorm_draft_v1($out['ideas']))return null;
    return $value;
}

function vp3_brainstorm_prepare_v1(array $job,array $evidence,callable $infer): array
{
    $primary=$job['primary'];$source=$evidence[$primary]??null;
    if(($source['kind']??null)!=='brainstorm_context'||($job['report']??null)!==false||$job['refs']!==[$primary]){
        throw new InvalidArgumentException('Brainstorming requires its private specialist context.');
    }
    $snapshot=$source['brainstorm_context'];$tools=$snapshot['tools'];
    $allowed=array_keys(array_filter($tools,static fn(array $entry):bool=>($entry['ready']??null)===true));
    $rows=array_map(static fn(array $row):array=>array_diff_key($row,array_flip(['url','revision','canonical_source_revision'])),array_values($snapshot['evidence']));
    $input=['role'=>'brainstorm','evidence'=>$rows,'tools'=>$tools,'specialists'=>$snapshot['briefs'],
        'specialist_reports'=>$snapshot['reports'],'budget'=>['max_jobs'=>0,'max_opportunities'=>3,'max_refs'=>5],
        'objective'=>$job['focus']];
    $answer=$infer(json_encode($input,JSON_THROW_ON_ERROR),'vp3.initiative-plan.v1');
    $plan=vp3_initiative_assessment_v1($answer,$snapshot['evidence'],$allowed,$tools,$snapshot['reports']);
    if($plan['jobs']||count($plan['opportunities'])>3||!empty($plan['opportunities_discarded'])){
        throw new InvalidArgumentException('Brainstorming may return only verified ideas, not assignments.');
    }
    $result=['draft'=>vp3_brainstorm_draft_v1($plan['opportunities']),'output'=>[
        'type'=>'initiative_brainstorm_report','status'=>$plan['opportunities']?'ideas_ready':'no_opportunity',
        'context_ref'=>$primary,'context_revision'=>$source['revision'],'ideas'=>$plan['opportunities'],
        'input_specialists'=>array_keys(array_filter($snapshot['briefs'],static fn(array $brief):bool=>$brief['state']==='current_context'))]];
    return vp3_brainstorm_tool_result_v1($result)??throw new InvalidArgumentException('Invalid Brainstorming report.');
}

function vp3_brainstorm_result_current_v1(PDO $pdo,array $user,array $binding,array $goal,array $result): array
{
    $normalized=vp3_brainstorm_tool_result_v1($result);
    if($normalized===null||($goal['report']??null)!==false)throw new Vp3InitiativeSourcesChangedV1();
    $out=$normalized['output'];$snapshot=vp3_brainstorm_snapshot_v1($pdo,$user,$binding);
    if($out['context_ref']!==vp3_brainstorm_ref_v1($user,$binding)
        ||($goal['sources']??null)!==[$out['context_ref']=>$out['context_revision']]
        ||!hash_equals($snapshot['revision'],$out['context_revision']))throw new Vp3InitiativeSourcesChangedV1();
    $tools=$snapshot['tools'];$discarded=0;
    $ideas=vp3_initiative_brainstorm_opportunities_v1(array_map(static fn(array $idea):object=>(object)$idea,$out['ideas']),
        $snapshot['evidence'],array_keys(array_filter($tools,static fn(array $entry):bool=>($entry['ready']??null)===true)),$tools,$discarded);
    $inputs=array_keys(array_filter($snapshot['briefs'],static fn(array $brief):bool=>$brief['state']==='current_context'));
    if($discarded||$ideas!==$out['ideas']||$inputs!==$out['input_specialists'])throw new Vp3InitiativeSourcesChangedV1();
    return $normalized;
}

/** Return one receipt-verified, currently authorized creative report to OB1. */
function vp3_brainstorm_report_v1(PDO $pdo,array $user,array $binding): ?array
{
    if(!function_exists('agent_job_engine_schema_ready_v1900')||!agent_job_engine_schema_ready_v1900($pdo))return null;
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';$binary=$sqlite?'BLOB':'BINARY';
    $tool="JSON_EXTRACT(CASE WHEN JSON_VALID(r.goal) THEN r.goal ELSE '{}' END,'$.tool')";
    if(!$sqlite)$tool="JSON_UNQUOTE($tool)";
    $q=$pdo->prepare("SELECT r.id,r.goal,a.result_json FROM agent_workflow_runs r
        JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
            AND a.sequence_no=1 AND a.status='completed' AND a.capability_key='initiative.prepare'
            AND a.action_key='initiative.prepare' AND a.execution_target='cloud'
        JOIN agent_workflow_receipts p ON p.id=(SELECT MAX(prior.id) FROM agent_workflow_receipts prior
            WHERE prior.run_id=r.id AND prior.action_id=a.id AND prior.owner_user_id=r.owner_user_id
            AND prior.attempt_no=a.attempt_count AND prior.executor='cloud')
        WHERE r.owner_user_id=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=?
            AND r.workflow_type='initiative' AND r.source_kind='agent_initiative' AND r.capability_key='initiative.prepare'
            AND r.execution_target='cloud' AND r.status='completed' AND p.status='completed'
            AND CAST(a.result_json AS $binary)=CAST(p.result_json AS $binary)
            AND $tool='brainstorm.explore' ORDER BY r.id DESC LIMIT 3");
    $q->execute([(int)$user['id'],(string)vp3_initiative_binding_agent_v1($binding)]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $goal=json_decode((string)$row['goal'],true,16);$saved=json_decode((string)$row['result_json'],true,32);
        if(($goal['binding']??null)!==$binding||($saved['effect_state']??null)!=='private_draft_ready'
            ||!is_array($saved['initiative_tool_result']??null)
            ||($saved['initiative_draft']??null)!==($saved['initiative_tool_result']['draft']??null))continue;
        try{$result=vp3_brainstorm_result_current_v1($pdo,$user,$binding,$goal,$saved['initiative_tool_result']);}
        catch(PDOException $error){throw $error;}
        catch(RuntimeException $error){if(!in_array($error->getCode(),[403,404,409,503],true))throw $error;continue;}
        return ['run_id'=>(int)$row['id'],'key'=>hash('sha256',json_encode([$row['id'],$result],JSON_THROW_ON_ERROR)),
            'report'=>['specialist'=>'brainstorm','state'=>'ready','outcome'=>$result['output']['status'],
                'summary'=>$result['draft'],'ideas'=>$result['output']['ideas']]];
    }
    return null;
}

/** One stable context has one canonical private Brainstorming assignment. */
function vp3_brainstorm_schedule_v1(PDO $pdo,array $user,array $binding): string
{
    return vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$user,$binding):string{
        vp3_initiative_owner_lock_v1($pdo,$user);
        if(vp3_initiative_runtime_runs_v1($pdo,(int)$user['id'],vp3_initiative_binding_agent_v1($binding),$binding,
            pending:true,opportunityLane:'brainstorm'))return 'already_handled';
        $live=vp3_initiative_access_v1($pdo,$user,$binding);$coverage=[];
        $evidence=vp3_initiative_evidence_v1($pdo,$live,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,
            [vp3_brainstorm_ref_v1($live,$binding)],vp3_initiative_binding_agent_v1($binding));
        $ref=vp3_brainstorm_ref_v1($live,$binding);$source=$evidence[$ref]??null;
        if($source===null)return 'no_opportunity';
        $goal=['binding'=>$binding,'tool'=>'brainstorm.explore','primary'=>$ref,'sources'=>[$ref=>$source['revision']],
            'canonical_sources'=>[$ref=>$source['revision']],'report'=>false,'opportunity_lane'=>'brainstorm',
            'focus'=>'Explore useful connections and creative opportunities across the permitted specialist briefs.'];
        $key=hash('sha256',json_encode([$binding,'brainstorm.explore',$ref,$goal['sources']],JSON_THROW_ON_ERROR));
        return vp3_initiative_enqueue_v1($pdo,$live,'initiative.prepare',$goal,$key)?'queued':'already_handled';
    });
}
