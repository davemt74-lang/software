<?php
declare(strict_types=1);

/** Producers validate their private result and expose one typed, reusable artifact. */
function vp3_initiative_result_producers_v1(): array
{
    return ['topic.research'=>'public_research_excerpts','artist.research'=>'public_research_excerpts'];
}

function vp3_initiative_result_inputs_v1(string $tool): array
{
    return match($tool){
        'canvas.prepare','image.prepare','work.prepare_private','chat.check_in'=>['public_research_excerpts'],
        default=>[],
    };
}

function vp3_initiative_result_ref_v1(mixed $ref): bool
{
    return is_string($ref)&&preg_match('/\Aspecialist-result:[1-9][0-9]{0,17}\z/D',$ref)===1;
}

/** Read an exact completed attempt. Delivery status and display text never grant evidence access. */
function vp3_initiative_result_rows_v1(PDO $pdo,array $user,array $binding,int $now,?array $ids): array
{
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $json=static function(string $path)use($sqlite):string{
        $value="JSON_EXTRACT(CASE WHEN JSON_VALID(r.goal) THEN r.goal ELSE '{}' END,'$path')";
        return $sqlite?$value:"JSON_UNQUOTE($value)";
    };
    $producers=array_keys(vp3_initiative_result_producers_v1());
    $slots=implode(',',array_fill(0,count($producers),'?'));
    $binary=$sqlite?'BLOB':'BINARY';
    $selection=$ids===null?'a.completed_at>=?':'r.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
    $args=$ids??[gmdate('Y-m-d H:i:s',$now-1800)];
    $stmt=$pdo->prepare("SELECT r.id,r.goal,a.result_json FROM agent_workflow_runs r
        JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
            AND a.sequence_no=1 AND a.status='completed' AND a.action_key='initiative.prepare'
            AND a.capability_key='initiative.prepare' AND a.execution_target='cloud'
        JOIN agent_workflow_receipts p ON p.id=(SELECT MAX(prior.id) FROM agent_workflow_receipts prior
            WHERE prior.run_id=r.id AND prior.action_id=a.id AND prior.owner_user_id=r.owner_user_id
            AND prior.attempt_no=a.attempt_count AND prior.executor='cloud')
        WHERE r.owner_user_id=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=? AND r.workflow_type='initiative'
            AND r.source_kind='agent_initiative' AND r.capability_key='initiative.prepare'
            AND r.execution_target='cloud' AND r.status='completed' AND p.status='completed'
            AND CAST(a.result_json AS $binary)=CAST(p.result_json AS $binary)
            AND CAST(".$json('$.binding.conversation_id')." AS CHAR)=? AND ".$json('$.binding.revision')."=?
            AND ".$json('$.tool')." IN ($slots) AND $selection
        ORDER BY r.id DESC".($ids===null?' LIMIT 8':''));
    $stmt->execute([(int)$user['id'],(string)vp3_initiative_binding_agent_v1($binding),
        (string)$binding['conversation_id'],$binding['revision'],...$producers,...$args]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Current result artifacts can be discovered or re-read by exact reference after discovery ages out. */
function vp3_initiative_result_evidence_v1(PDO $pdo,array $user,array $binding,DateTimeImmutable $now,
    ?array $selectedRefs,array &$coverage): array
{
    $ids=null;
    if($selectedRefs!==null){
        $ids=array_map(static fn(string $ref):int=>(int)substr($ref,strlen('specialist-result:')),
            array_filter($selectedRefs,'vp3_initiative_result_ref_v1'));
        if(!$ids)return [];
    }
    $coverage['specialist_results']='ready';
    if(!agent_job_engine_schema_ready_v1900($pdo)){
        $coverage['specialist_results']='unavailable';
        return [];
    }
    require_once __DIR__.'/agent-initiative-specialist-reports-v1.php';
    require_once __DIR__.'/agent-initiative-specialist-registry-v1.php';
    require_once __DIR__.'/agent-initiative-research-artifact-v1.php';
    $evidence=[];$sources=[];$candidates=[];
    foreach($ids===null?[null]:array_chunk(array_unique($ids),24) as $batch){
        foreach(vp3_initiative_result_rows_v1($pdo,$user,$binding,$now->getTimestamp(),$batch) as $row){
            $goal=json_decode((string)$row['goal'],true,16);
            $result=json_decode((string)$row['result_json'],true,32);
            $primary=$goal['primary']??null;
            if(($goal['binding']??null)!==$binding||!is_string($primary)||!is_array($goal['sources']??null)
                ||array_keys($goal['sources'])!==[$primary]||($result['effect_state']??null)!=='private_draft_ready')continue;
            $work=$result['initiative_tool_result']??null;
            if(!is_array($work)||($result['initiative_draft']??null)!==($work['draft']??null))continue;
            $artifact=match(vp3_initiative_result_producers_v1()[$goal['tool']??'']??null){
                'public_research_excerpts'=>vp3_initiative_research_artifact_v1($work),
                default=>null,
            };
            if($artifact===null)continue;
            $candidates[]=[$row,$goal,$primary,$work,$artifact];
            $sources[$primary]=true;
        }
    }
    if(!$candidates)return [];
    // The producer owns the source verifier, so a result cannot recurse into
    // the generic evidence reader or use another result as public query input.
    $current=[];
    foreach(array_chunk(array_keys($sources),8) as $batch){
        $sourceCoverage=[];
        $current+=vp3_initiative_specialist_research_sources_v1($pdo,$user,$binding,$batch,$now->getTimestamp(),$sourceCoverage);
        if(in_array('unavailable',$sourceCoverage,true))$coverage['specialist_results']='unavailable';
    }
    foreach($candidates as [$row,$goal,$primary,$work,$artifact]){
        if(($current[$primary]['revision']??null)!==$goal['sources'][$primary])continue;
        try{vp3_initiative_result_current_v1($pdo,$user,$binding,$goal,$work);}
        catch(PDOException $error){throw $error;}
        catch(RuntimeException $error){
            if(!in_array($error->getCode(),[403,404,409,503],true))throw $error;
            if($error->getCode()===503)$coverage['specialist_results']='unavailable';
            continue;
        }
        $ref='specialist-result:'.$row['id'];
        $evidence[$ref]=['ref'=>$ref,'kind'=>'specialist_result',
            'specialist'=>vp3_initiative_tool_specialist_v1($goal['tool']),
            'title'=>'Research excerpts for '.mb_substr((string)$current[$primary]['title'],0,135),
            'detail'=>'Verified quotations from public pages. The quoted values apply only to the exact product, quantity and unit stated in the source.',
            'artifact'=>$artifact,'parent_source'=>$primary,'url'=>$current[$primary]['url'],
            'revision'=>hash('sha256',json_encode([$ref,$goal['tool'],$goal['sources'],$artifact],JSON_THROW_ON_ERROR)),
        ];
        if($ids===null&&count($evidence)>=4)break;
    }
    return $evidence;
}

/** Require an accepting tool and anchor result-driven work to an exact predecessor. */
function vp3_initiative_result_job_v1(array $job,array $evidence): void
{
    $accepted=vp3_initiative_result_inputs_v1($job['tool']);
    foreach($job['refs'] as $ref){
        if(($evidence[$ref]['kind']??null)!=='specialist_result')continue;
        if(($evidence[$job['primary']]['kind']??null)!=='specialist_result'
            ||!in_array($evidence[$ref]['artifact']['artifact_type']??null,$accepted,true)){
            throw new Vp3InitiativePlanValidationV1('invalid_job_sources','This tool does not accept the selected predecessor result.');
        }
    }
}

/** The downstream tool and exact predecessors identify the handoff across repeated assessments. */
function vp3_initiative_result_assignment_key_v1(array $binding,string $tool,array $sources): string
{
    $parents=array_filter($sources,'vp3_initiative_result_ref_v1',ARRAY_FILTER_USE_KEY);
    ksort($parents);
    return hash('sha256',json_encode(['specialist-result-handoff',$binding,$tool,$parents],JSON_THROW_ON_ERROR));
}
