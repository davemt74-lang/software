<?php
declare(strict_types=1);

/** Only assessment identity changes with time; prepared work retains its source dedupe. */
function vp3_initiative_brainstorm_slot_v1(?DateTimeImmutable $now=null): int
{
    return intdiv(($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->getTimestamp(),300);
}

/** Stable object order, while retaining ordered arrays and all semantic fields. */
function vp3_initiative_brainstorm_semantic_v1(array $payload): string
{
    unset($payload['reassessment_slot']);
    $sort=static function(mixed $value)use(&$sort):mixed{
        if(!is_array($value))return $value;
        if(!array_is_list($value))ksort($value);
        foreach($value as $key=>$item)$value[$key]=$sort($item);
        return $value;
    };
    return hash('sha256',json_encode($sort($payload),JSON_THROW_ON_ERROR));
}

/** Deduplicate in-flight work, and completed assessments of unchanged setup only. */
function vp3_initiative_brainstorm_pending_v1(PDO $pdo,array $user,array $payload,bool $setupOnly=false): bool
{
    $binding=$payload['binding'];$sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $value=static function(string $path)use($sqlite):string{
        $sql="JSON_EXTRACT(CASE WHEN JSON_VALID(r.goal) THEN r.goal ELSE '{}' END,'$path')";
        return $sqlite?$sql:"JSON_UNQUOTE($sql)";
    };
    $statuses=$setupOnly?"'approved','queued','executing','completed'":"'approved','queued','executing'";
    $q=$pdo->prepare("SELECT r.goal FROM agent_workflow_runs r WHERE r.owner_user_id=?
        AND CAST(COALESCE(r.agent_id,0) AS CHAR)=? AND r.source_kind='agent_initiative'
        AND r.execution_target='cloud' AND r.capability_key='initiative.plan'
        AND r.status IN ($statuses)
        AND CAST(".$value('$.binding.conversation_id')." AS CHAR)=? AND ".$value('$.binding.revision')."=?
        ORDER BY r.id DESC LIMIT 25");
    $q->execute([(int)$user['id'],(string)vp3_initiative_binding_agent_v1($binding),
        (string)$binding['conversation_id'],(string)$binding['revision']]);
    $semantic=vp3_initiative_brainstorm_semantic_v1($payload);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $json){
        $pending=json_decode((string)$json,true,16);
        if(is_array($pending)&&is_array($pending['binding']??null)
            &&vp3_initiative_brainstorm_semantic_v1($pending)===$semantic)return true;
    }
    return false;
}

function vp3_initiative_brainstorm_tool_scope_v1(array $job,array $tools): void
{
    $tool=$tools[$job['tool']]??null;
    if(is_array($tool)&&isset($tool['source_refs'])&&!in_array($job['primary'],$tool['source_refs'],true)){
        throw new Vp3InitiativePlanValidationV1('invalid_job_sources','The selected tool does not accept this situation source.');
    }
    if(isset($tool['context_refs'])&&array_diff($job['refs'],$tool['context_refs'])){
        throw new Vp3InitiativePlanValidationV1('invalid_job_sources','The selected tool does not accept all the supporting sources.');
    }
}

/** These are concise owner-facing explanations, never a model reasoning transcript. */
function vp3_initiative_brainstorm_text_v1(mixed $value,int $limit): ?string
{
    if(!is_string($value)||!mb_check_encoding($value,'UTF-8'))return null;
    $value=trim($value);
    if($value===''||mb_strlen($value)>$limit||preg_match('/https?:\/\/|\bwww\.|[<>]|[\x00-\x08\x0b-\x1f\x7f]/i',$value))return null;
    return $value;
}

function vp3_initiative_brainstorm_report_keys_v1(array $reports): array
{
    $keys=[];
    foreach($reports as $report){
        unset($report['source_ref']);
        if(($report['specialist']??null)==='research'&&in_array($report['state']??null,['ready','completed'],true))$report['state']='result';
        if(isset($report['latest_completed_scan']))unset($report['state'],$report['last_checked_at']);
        $keys[]=vp3_initiative_brainstorm_semantic_v1($report);
    }
    return array_values(array_unique($keys));
}

/** Count only the verified inputs passed to ob1, without retaining their identifiers or text. */
function vp3_initiative_brainstorm_inputs_v1(array $evidence,array $reports): array
{
    require_once __DIR__.'/agent-initiative-specialist-evidence-v1.php';
    $inputs=[];
    foreach($evidence as $ref=>$source){
        if(($source['ref']??null)!==$ref||!is_string($source['revision']??null)
            ||!preg_match('/\A[a-f0-9]{64}\z/D',$source['revision']))continue;
        $specialist=vp3_initiative_source_specialist_v1($source);
        if(!isset(vp3_initiative_specialist_catalog_v1([])[$specialist]))continue;
        $inputs[$specialist]??=['sources'=>0,'reports'=>0];
        $inputs[$specialist]['sources']++;
    }
    foreach($reports as $report){
        $specialist=($report['specialist']??null)==='email'?'gmail':($report['specialist']??null);
        if(!isset(vp3_initiative_specialist_catalog_v1([])[$specialist]))continue;
        $inputs[$specialist]??=['sources'=>0,'reports'=>0];
        $inputs[$specialist]['reports']++;
    }
    ksort($inputs);
    return $inputs;
}

/** Wake only the superseded ob1 assessment's existing review checkpoint. */
function vp3_initiative_brainstorm_wake_changed_v1(PDO $pdo,int $owner,array $binding): void
{
    if(!table_exists('agent_memory_items'))return;
    [$scope,$params]=vp3_agent_memory_scope_sql_v410(vp3_initiative_binding_agent_v1($binding));
    $metadata="CASE WHEN JSON_VALID(metadata_json) THEN metadata_json ELSE '{}' END";
    $extract=static function(string $path)use($pdo,$metadata):string{
        $sql="JSON_EXTRACT($metadata,'$path')";
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?$sql:"JSON_UNQUOTE($sql)";
    };
    $query=$pdo->prepare("UPDATE agent_memory_items SET metadata_json=JSON_SET($metadata,'$.specialist_source_changed',1)
        WHERE user_id=? AND $scope AND memory_type='initiative_sweep' AND subject='last-review' AND is_active=1
        AND CAST(".$extract('$.conversation_id')." AS CHAR)=? AND ".$extract('$.revision')."=?
        AND JSON_EXTRACT($metadata,'$.specialist_source_changed') IS NULL");
    $query->execute([$owner,...$params,(string)$binding['conversation_id'],$binding['revision']]);
}

/** Reject invalid optional ideas independently of the assignments and any verified ideas. */
function vp3_initiative_brainstorm_opportunities_v1(mixed $value,array $evidence,array $allowedTools,array $tools=[],int &$discarded=0): array
{
    $discarded=0;
    if(!is_array($value)||!array_is_list($value)||count($value)>8){
        $discarded=1;
        return [];
    }
    $out=[];$seen=[];
    foreach($value as $row){
        $opportunity=vp3_initiative_brainstorm_opportunity_v1($row,$evidence,$allowedTools,$tools);
        $key=$opportunity===null?'':$opportunity['tool'].'|'.$opportunity['primary'];
        if($opportunity===null||isset($seen[$key])){
            $discarded++;
            continue;
        }
        $seen[$key]=true;
        $out[]=$opportunity;
    }
    return $out;
}

function vp3_initiative_brainstorm_opportunity_v1(mixed $row,array $evidence,array $allowedTools,array $tools): ?array
{
    $fields=['tool','primary','refs','objective','rationale'];
    if(!$row instanceof stdClass||count((array)$row)!==count($fields)||array_diff(array_keys((array)$row),$fields))return null;
    $row=(array)$row;
    $objective=vp3_initiative_brainstorm_text_v1($row['objective'],300);
    $rationale=vp3_initiative_brainstorm_text_v1($row['rationale'],500);
    if($objective===null||$rationale===null)return null;
    // The canonical parser preserves tentative interests, research scope, typed refs and ready tool rules.
    try{
        $job=vp3_initiative_jobs_v1(json_encode(['jobs'=>[['tool'=>$row['tool'],'primary'=>$row['primary'],
            'refs'=>$row['refs'],'focus'=>$objective,'report'=>true]]],JSON_THROW_ON_ERROR),$evidence,$allowedTools)[0];
        vp3_initiative_brainstorm_tool_scope_v1($job,$tools);
    }catch(InvalidArgumentException|JsonException){return null;}
    return ['tool'=>$job['tool'],'primary'=>$job['primary'],'refs'=>$job['refs'],'objective'=>$objective,'rationale'=>$rationale];
}

/** Advance one candidate that actually appears in the verified Canvas report. */
function vp3_initiative_canvas_assessment_v1(array $plan): array
{
    $ideas=array_slice($plan['opportunities'],0,3);
    $work=[];
    foreach($ideas as $idea){
        foreach($plan['jobs'] as $job){
            if($job['report']!==true||$job['primary']!==$idea['primary']||$job['tool']!==$idea['tool']
                ||count($job['refs'])!==count($idea['refs'])||array_diff($job['refs'],$idea['refs']))continue;
            $job['focus']=$idea['objective'];
            $work[]=$job;
            break 2;
        }
    }
    return array_replace($plan,['opportunities'=>$ideas,'jobs'=>$work]);
}

/** Deterministic source identity is server-owned, never an LLM-provided novelty key. */
function vp3_initiative_brainstorm_identity_v1(string $tool,string $primary,array $revisions): string
{
    ksort($revisions);
    return hash('sha256',json_encode([$tool,$primary,$revisions],JSON_THROW_ON_ERROR));
}

/** Build a private typed assessment receipt from verified sources and actual admissions. */
function vp3_initiative_brainstorm_result_v1(array $opportunities,array $evidence,array $admissions=[],?array $finding=null,array $ob1Inputs=[]): array
{
    $rows=[];
    foreach($opportunities as $opportunity){
        $revisions=[];
        foreach($opportunity['refs'] as $ref){
            $revision=$evidence[$ref]['revision']??null;
            if(!is_string($revision)||!preg_match('/\A[a-f0-9]{64}\z/D',$revision))throw new InvalidArgumentException('Invalid opportunity source revision.');
            $revisions[$ref]=$revision;
        }
        ksort($revisions);
        $id=vp3_initiative_brainstorm_identity_v1($opportunity['tool'],$opportunity['primary'],$revisions);
        $rows[]=['id'=>$id]+$opportunity+['source_revisions'=>$revisions,'status'=>$admissions[$id]??'considered'];
    }
    $result=['draft'=>'','output'=>['type'=>'initiative_brainstorm','status'=>'assessed','opportunities'=>$rows]];
    if($finding!==null)$result['output']['finding']=$finding;
    if($ob1Inputs)$result['output']['ob1_inputs']=$ob1Inputs;
    return vp3_initiative_brainstorm_tool_result_v1($result)??throw new InvalidArgumentException('Invalid brainstorm result.');
}

/** Pure fail-closed private storage schema; public workflow projections omit it. */
function vp3_initiative_brainstorm_tool_result_v1(array $value): ?array
{
    if(array_diff(array_keys($value),['draft','output'])||($value['draft']??null)!=='')return null;
    $output=$value['output']??null;
    if(!is_array($output)||array_diff(array_keys($output),['type','status','opportunities','finding','ob1_inputs'])
        ||($output['type']??null)!=='initiative_brainstorm'||($output['status']??null)!=='assessed'
        ||!is_array($output['opportunities']??null)||!array_is_list($output['opportunities'])||count($output['opportunities'])>8)return null;
    $rows=[];$seen=[];
    foreach($output['opportunities'] as $row){
        $fields=['id','tool','primary','refs','objective','rationale','source_revisions','status'];
        if(!is_array($row)||count($row)!==count($fields)||array_diff(array_keys($row),$fields)
            ||!is_string($row['id']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$row['id'])
            ||!is_string($row['tool']??null)||!preg_match('/\A[a-z][a-z0-9_.]{0,79}\z/D',$row['tool'])
            ||!is_string($row['primary']??null)||$row['primary']===''||strlen($row['primary'])>190
            ||!is_array($row['refs']??null)||!array_is_list($row['refs'])||count($row['refs'])<1||count($row['refs'])>5
            ||!is_array($row['source_revisions']??null)||count($row['source_revisions'])!==count($row['refs'])
            ||!in_array($row['status']??null,['considered','queued','existing'],true)
            ||!is_string($row['objective']??null)||!is_string($row['rationale']??null)
            ||vp3_initiative_brainstorm_text_v1($row['objective']??null,300)!==($row['objective']??null)
            ||vp3_initiative_brainstorm_text_v1($row['rationale']??null,500)!==($row['rationale']??null))return null;
        $refs=[];
        foreach($row['refs'] as $ref){
            if(!is_string($ref)||$ref===''||strlen($ref)>190||isset($refs[$ref]))return null;
            $revision=$row['source_revisions'][$ref]??null;
            if(!is_string($revision)||!preg_match('/\A[a-f0-9]{64}\z/D',$revision))return null;
            $refs[$ref]=true;
        }
        if(!isset($refs[$row['primary']])||isset($seen[$row['id']])
            ||vp3_initiative_brainstorm_identity_v1($row['tool'],$row['primary'],$row['source_revisions'])!==$row['id'])return null;
        $seen[$row['id']]=true;$rows[]=$row;
    }
    $result=['draft'=>'','output'=>['type'=>'initiative_brainstorm','status'=>'assessed','opportunities'=>$rows]];
    if(array_key_exists('finding',$output)){
        $finding=$output['finding'];
        if(!is_array($finding)||array_keys($finding)!==['text','report_keys']||!is_string($finding['text'])
            ||vp3_initiative_brainstorm_text_v1($finding['text'],500)!==$finding['text']
            ||!is_array($finding['report_keys'])||!array_is_list($finding['report_keys'])
            ||count($finding['report_keys'])<1||count($finding['report_keys'])>12)return null;
        foreach($finding['report_keys'] as $key)if(!is_string($key)||!preg_match('/\A[a-f0-9]{64}\z/D',$key))return null;
        $result['output']['finding']=$finding;
    }
    if(array_key_exists('ob1_inputs',$output)){
        $inputs=$output['ob1_inputs'];
        require_once __DIR__.'/agent-initiative-specialist-registry-v1.php';
        if(!is_array($inputs)||!$inputs||array_diff(array_keys($inputs),array_keys(vp3_initiative_specialist_catalog_v1([]))))return null;
        foreach($inputs as $counts){
            if(!is_array($counts)||array_keys($counts)!==['sources','reports']
                ||!is_int($counts['sources'])||$counts['sources']<0||$counts['sources']>512
                ||!is_int($counts['reports'])||$counts['reports']<0||$counts['reports']>16
                ||$counts['sources']+$counts['reports']===0)return null;
        }
        ksort($inputs);
        $result['output']['ob1_inputs']=$inputs;
    }
    return $result;
}
