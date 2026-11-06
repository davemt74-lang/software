<?php
declare(strict_types=1);

require_once __DIR__.'/initiative-work-mission-v1.php';
if(!function_exists('vp3_initiative_capability_scope_v1'))require_once __DIR__.'/agent-initiative-capabilities-v1.php';

/** Existing completed-Mission evidence only. Reading never starts research or creates a brief. */
function vp3_initiative_mission_capabilities_snapshot_v1(PDO $pdo,array $scope,string $missionId): array
{
    $owner=(int)$scope['user']['id'];
    // This canonical reader checks the exact Mission/run/Chat, every completed
    // action and matching Cloud receipt, and current source/collection authority.
    $saved=initiative_work_mission_evidence_v1($pdo,$owner,$scope,$missionId);
    $records=[];$sources=[];
    foreach($saved['sources'] as $source){
        $records[]=[
            'source'=>$source['id'],'title'=>$source['title'],'text'=>$source['excerpt'],'url'=>$source['url'],
            'data'=>['kind'=>'saved_public_page_excerpt','mission_ref'=>'mission-evidence:'.$missionId,
                'captured_at'=>$source['collected_at'],'partial_excerpt'=>true,
                'generated_assessment'=>false],
        ];
        $sources[]=['source'=>$source['id'],'title'=>$source['title'],'url'=>$source['url']];
    }
    if(!$records||count($records)>4)throw new RuntimeException('Saved Mission excerpts are unavailable.',409);
    $ref='mission-evidence:'.$missionId;
    $coverage=['state'=>'limited','captured_sources'=>(int)$saved['coverage']['captured_sources'],
        'returned_excerpts'=>count($records),'excerpt_character_limit'=>1400,'excerpt_source_limit'=>4,
        'saved_evidence_only'=>true,'new_research_performed'=>false,'model_assessments_included'=>false];
    $snapshot=['source_ref'=>$ref,'canonical_revision'=>$saved['revision'],
        'objective'=>$saved['source_title'],'records'=>$records,'sources'=>$sources,'coverage'=>$coverage];
    // The upstream revision includes full saved result/receipt/source JSON, not
    // only the bounded excerpt prefixes. No observation-time stamp is included.
    $revision=hash('sha256',json_encode(['contract'=>'vp3.initiative-mission-evidence.v1',
        'owner'=>$owner,'agent'=>$scope['agent_id'],'conversation'=>$scope['conversation_id'],
        'snapshot'=>$snapshot],JSON_THROW_ON_ERROR));
    $url=url('/chat.php?agent='.($scope['agent_id']>0?$scope['agent_id']:'system')
        .'&conversation_id='.$scope['conversation_id'].'&mission='.$missionId);
    $entry=['ref'=>$ref,'kind'=>'mission_evidence','title'=>$saved['source_title'],
        'detail'=>'A completed Mission has '.count($records).' currently authorized saved public-page excerpt(s). '
            .'Read the captured evidence to prepare a useful private follow-up; these are partial excerpts, '
            .'not fresh research or independent verification of generated Mission conclusions.',
        'url'=>$url,'revision'=>$revision];
    return ['entry'=>$entry,'read'=>['source_ref'=>$ref,'source_revision'=>$revision]+$snapshot];
}

/** Bounded source admission; availability and private work use the existing Initiative authority. */
function vp3_initiative_mission_capabilities_evidence_v1(PDO $pdo,array $scope,array $binding,DateTimeImmutable $now,?array $selectedRefs,array &$coverage): array
{
    $scope=vp3_initiative_capability_scope_v1($pdo,$scope,$binding);
    $coverage['mission_evidence']='ready';$ids=[];$limited=false;
    if($selectedRefs!==null){
        foreach($selectedRefs as $ref){
            if(!is_string($ref)||!str_starts_with($ref,'mission-evidence:'))continue;
            if(!preg_match('/\Amission-evidence:([a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\z/D',$ref,$match))
                throw new InvalidArgumentException('Choose an exact saved Mission evidence source.');
            $ids[$match[1]]=true;
        }
        if(count($ids)>5)throw new InvalidArgumentException('Too many Mission evidence sources.');
        $ids=array_keys($ids);
        if(!$ids)return [];
    }
    try{
        foreach(['agent_cloud_missions_v1','agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts',
            'agent_cloud_mission_web_v1','chat_conversations'] as $table){
            if(!table_exists($table)){$coverage['mission_evidence']='unavailable';return [];}
        }
        if($selectedRefs===null){
            $q=$pdo->prepare("SELECT m.mission_id FROM agent_cloud_missions_v1 m
                INNER JOIN agent_workflow_runs r ON r.id=m.run_id AND r.owner_user_id=m.owner_user_id
                WHERE m.owner_user_id=? AND m.agent_id=? AND r.status='completed'
                ORDER BY m.created_at DESC,m.mission_id DESC LIMIT 21");
            $q->execute([(int)$scope['user']['id'],$scope['agent_id']]);
            $ids=$q->fetchAll(PDO::FETCH_COLUMN);$limited=count($ids)>20;$ids=array_slice($ids,0,20);
        }
        $evidence=[];
        foreach($ids as $index=>$id){
            try{$snapshot=vp3_initiative_mission_capabilities_snapshot_v1($pdo,$scope,(string)$id);}
            catch(DomainException $e){
                if(!in_array($e->getCode(),[403,404,409,422],true))throw $e;
                // An exact lookup definitively found this old source missing,
                // revoked or stale. It is not a transient incomplete scan: the
                // canonical caller must retire the old revision with 409.
                if($selectedRefs===null)$limited=true;
                continue;
            }
            $entry=$snapshot['entry'];$evidence[$entry['ref']]=$entry;
            if($selectedRefs===null&&count($evidence)===4){$limited=$limited||$index<count($ids)-1;break;}
        }
        $coverage['mission_evidence']=$limited?'limited':'ready';
        return $evidence;
    }catch(PDOException $e){
        // A partial transient read is not proof that the missing Missions do not exist.
        $coverage['mission_evidence']='unavailable';return [];
    }catch(RuntimeException|JsonException|TypeError $e){
        $coverage['mission_evidence']=$e->getCode()===403?'forbidden':'unavailable';return [];
    }
}

/** Read the exact selected Mission into canonical private preparation, with no model or write. */
function vp3_initiative_mission_capabilities_read_v1(PDO $pdo,array $scope,array $binding,string $tool,array $job,array $evidence): array
{
    $scope=vp3_initiative_capability_scope_v1($pdo,$scope,$binding);
    $ref=$job['primary']??null;$refs=$job['refs']??null;
    if($tool!=='missions.evidence.read'||($job['tool']??$tool)!==$tool||!is_string($ref)
        ||!preg_match('/\Amission-evidence:([a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\z/D',$ref,$match)
        ||!is_array($refs)||!in_array($ref,$refs,true)||($evidence[$ref]['ref']??null)!==$ref
        ||!is_string($evidence[$ref]['revision']??null)){
        throw new InvalidArgumentException('Choose the exact saved Mission evidence in this assignment.');
    }
    $current=vp3_initiative_mission_capabilities_snapshot_v1($pdo,$scope,$match[1]);
    if(!hash_equals($current['entry']['revision'],$evidence[$ref]['revision']))
        throw new RuntimeException('Saved Mission evidence changed.',409);
    return $current['read'];
}
