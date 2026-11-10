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
        throw new InvalidArgumentException('The selected tool does not accept this situation source.');
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

/** Ideas use the same tool/source rules as real assignments but their own bounded count. */
function vp3_initiative_brainstorm_opportunities_v1(mixed $value,array $evidence,array $allowedTools,array $tools=[]): array
{
    if(!is_array($value)||!array_is_list($value)||count($value)>8)throw new InvalidArgumentException('Invalid initiative opportunities.');
    $out=[];$seen=[];
    foreach($value as $row){
        $fields=['tool','primary','refs','objective','rationale'];
        if(!$row instanceof stdClass||count((array)$row)!==count($fields)||array_diff(array_keys((array)$row),$fields)){
            throw new InvalidArgumentException('Invalid initiative opportunity.');
        }
        $row=(array)$row;
        $objective=vp3_initiative_brainstorm_text_v1($row['objective']??null,300);
        $rationale=vp3_initiative_brainstorm_text_v1($row['rationale']??null,500);
        if($objective===null||$rationale===null)throw new InvalidArgumentException('Invalid initiative opportunity explanation.');
        // One-item use of the canonical assignment parser preserves tentative
        // interests, research scope, typed refs and current ready tool rules.
        $job=vp3_initiative_jobs_v1(json_encode(['jobs'=>[['tool'=>$row['tool']??null,'primary'=>$row['primary']??null,
            'refs'=>$row['refs']??null,'focus'=>$objective,'report'=>true]]],JSON_THROW_ON_ERROR),$evidence,$allowedTools)[0];
        vp3_initiative_brainstorm_tool_scope_v1($job,$tools);
        $key=$job['tool'].'|'.$job['primary'];
        if(isset($seen[$key]))throw new InvalidArgumentException('Duplicate initiative opportunity.');
        $seen[$key]=true;
        $out[]=['tool'=>$job['tool'],'primary'=>$job['primary'],'refs'=>$job['refs'],
            'objective'=>$objective,'rationale'=>$rationale];
    }
    return $out;
}

/** Deterministic source identity is server-owned, never an LLM-provided novelty key. */
function vp3_initiative_brainstorm_identity_v1(string $tool,string $primary,array $revisions): string
{
    ksort($revisions);
    return hash('sha256',json_encode([$tool,$primary,$revisions],JSON_THROW_ON_ERROR));
}

/** Build a private typed assessment receipt from verified sources and actual admissions. */
function vp3_initiative_brainstorm_result_v1(array $opportunities,array $evidence,array $admissions=[]): array
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
    return vp3_initiative_brainstorm_tool_result_v1($result)??throw new InvalidArgumentException('Invalid brainstorm result.');
}

/** Pure fail-closed private storage schema; public workflow projections omit it. */
function vp3_initiative_brainstorm_tool_result_v1(array $value): ?array
{
    if(array_diff(array_keys($value),['draft','output'])||($value['draft']??null)!=='')return null;
    $output=$value['output']??null;
    if(!is_array($output)||count($output)!==3||array_diff(array_keys($output),['type','status','opportunities'])
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
    return ['draft'=>'','output'=>['type'=>'initiative_brainstorm','status'=>'assessed','opportunities'=>$rows]];
}
