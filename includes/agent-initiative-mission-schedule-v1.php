<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-opportunity-view-v1.php';
require_once __DIR__.'/agent-initiative-followthrough-v1.php';
require_once __DIR__.'/agent-initiative-followup-conditions-v1.php';
require_once __DIR__.'/agent-initiative-tools-v1.php';
require_once __DIR__.'/agent-initiative-capabilities-v1.php';
require_once __DIR__.'/agent-catch-up-missions-v1.php';
require_once __DIR__.'/cloud-missions-v1.php';

/** The native Mission remains authoritative about approval, execution and dependencies. */
function vp3_initiative_mission_followups_v1(PDO $pdo,array $scope,DateTimeImmutable $now): array
{
    $user=$scope['user'];
    [$coverage,$items]=vp3_catch_up_missions_v1($pdo,$user,$scope['principal'],$now);
    $reviews=[];
    foreach($items as $item){
        $status=$item['status']??null;
        $focus=match($status){
            'awaiting_review'=>'Explain the existing Mission is waiting for assignment review. Offer to help review it. Only the owner can approve it.',
            'blocked'=>'Explain this Mission is waiting on an unfinished prerequisite. Offer to review the existing Mission. Do not infer the prerequisite identity or start any work.',
            'failed'=>'Explain that a worker failed in this Mission. Offer to review the existing Mission before deciding how to continue. Do not infer the cause or restart it.',
            default=>null,
        };
        if($focus===null||($status==='awaiting_review'&&$item['time']>$now->getTimestamp()-900))continue;
        $ref=$item['id'];$id=substr($ref,strlen('mission:'));
        try{
            $target=vp3_initiative_followup_mission_target_v1($pdo,$scope,$id);
            if(($target['identity']['conversation_id']??null)!==$scope['conversation_id'])continue;
            if($status==='awaiting_review'){
                $mission=cloud_mission_row_v1($pdo,$user,$id);
                // The proposal itself already provides this review handoff.
                if(str_starts_with((string)$mission['request_id'],'initiative-'))continue;
            }
        }catch(JsonException|InvalidArgumentException $error){
            continue;
        }catch(RuntimeException $error){
            if($error instanceof PDOException||!in_array($error->getCode(),[403,404,409,503],true))throw $error;
            continue;
        }
        $reviews[$ref]=$focus;
    }
    return [$coverage,$reviews];
}

/** Keep one pending discovery per Chat and avoid duplicate work admitted during Brainstorm. */
function vp3_initiative_mission_assignments_v1(PDO $pdo,array $user,array $binding): array
{
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $json=static function(string $path)use($sqlite):string{
        $value="JSON_EXTRACT(CASE WHEN JSON_VALID(r.goal) THEN r.goal ELSE '{}' END,'$path')";
        return $sqlite?$value:"JSON_UNQUOTE($value)";
    };
    $conversation=$json('$.binding.conversation_id');$lane=$json('$.opportunity_lane');
    $tool=$json('$.tool');$primary=$json('$.primary');
    $query=$pdo->prepare("SELECT r.goal,r.status FROM agent_workflow_runs r
        WHERE r.owner_user_id=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=?
            AND r.source_kind='agent_initiative' AND r.capability_key='initiative.prepare' AND r.execution_target='cloud'
            AND CAST($conversation AS CHAR)=? AND ($lane='missions' OR $tool='missions.prepare' OR $primary LIKE 'mission:%')
        ORDER BY r.id DESC LIMIT 80");
    $query->execute([(int)$user['id'],(string)vp3_initiative_binding_agent_v1($binding),(string)$binding['conversation_id']]);
    $handled=[];$inProgress=false;
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $run){
        $goal=json_decode((string)$run['goal'],true);$primary=$goal['primary']??null;
        if(!is_string($primary)||isset($goal['followthrough'])||isset($goal['followup']))continue;
        $waiting=in_array($run['status'],['approved','queued','executing','paused'],true);
        if(($goal['opportunity_lane']??null)==='missions'&&$waiting&&($goal['binding']??null)===$binding)$inProgress=true;
        $revision=$goal['sources'][$primary]??null;
        if(!is_string($revision)||($waiting&&($goal['binding']??null)!==$binding))continue;
        if(($goal['tool']??null)==='missions.prepare'||(str_starts_with($primary,'mission:')&&($goal['report']??null)===true)){
            $handled[$primary][$revision]=true;
        }
    }
    return [$inProgress,$handled];
}

function vp3_initiative_mission_enqueue_v1(PDO $pdo,array $user,array $binding,string $tool,string $primary,string $focus,array $evidence): bool
{
    $sources=array_column($evidence,'revision','ref');
    $canonical=array_map(static fn(array $row):string=>$row['canonical_source_revision']??$row['revision'],$evidence);
    $hash=hash('sha256',json_encode([$binding,$tool,$primary,$sources],JSON_THROW_ON_ERROR));
    return vp3_initiative_enqueue_v1($pdo,$user,'initiative.prepare',[
        'binding'=>$binding,'sources'=>$sources,'canonical_sources'=>$canonical,
        'capability_sources'=>vp3_initiative_capability_source_map_v1($evidence,array_keys($evidence)),
        'tool'=>$tool,'primary'=>$primary,'opportunity_lane'=>'missions','focus'=>$focus,'report'=>true,
    ],$hash);
}

/** Discover owner review work without waiting for another Brainstorm run. */
function vp3_initiative_schedule_missions_v1(PDO $pdo,array $user,array $binding,?DateTimeImmutable $now=null): string
{
    if($pdo->inTransaction()||($binding['enabled']??false)!==true||vp3_participant_scope_v1()!==null)return 'blocked';
    if(!table_exists('agent_cloud_missions_v1')||!cloud_mission_ready_v1($pdo))return 'not_ready';
    $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    return vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$user,$binding,$now):string{
        vp3_initiative_owner_lock_v1($pdo,$user);
        $scope=vp3_initiative_tools_scope_v1($pdo,$user,$binding);$live=$scope['user'];$agent=$scope['agent_id'];
        [$inProgress,$handled]=vp3_initiative_mission_assignments_v1($pdo,$live,$binding);
        if($inProgress)return 'already_handled';
        [$coverage,$followups]=vp3_initiative_mission_followups_v1($pdo,$scope,$now);
        $matched=false;
        if($followups){
            $evidence=vp3_initiative_evidence_v1($pdo,$live,$now,selectedRefs:array_keys($followups),agentId:$agent);
            foreach($followups as $ref=>$focus){
                $row=$evidence[$ref]??null;
                if(($row['kind']??null)!=='mission')continue;
                $matched=true;
                if(isset($handled[$ref][$row['revision']]))continue;
                if(vp3_initiative_mission_enqueue_v1($pdo,$live,$binding,'work.prepare_private',$ref,$focus,[$ref=>$row]))return 'queued';
            }
        }

        try{$ideas=vp3_initiative_verified_opportunities_v1($pdo,$live,$agent,'missions.prepare');}
        catch(Vp3InitiativeSourcesChangedV1 $error){$ideas=[];}
        foreach($ideas['rows']??[] as $idea){
            $ref=$idea['primary'];$sources=array_intersect_key($ideas['evidence'],$idea['source_revisions']);
            if(array_filter($sources,static fn(array $row):bool=>in_array($row['kind']??null,['mission','mission_evidence','workbench_review'],true)))continue;
            $matched=true;
            if($idea['status']!=='considered'||isset($handled[$ref][$sources[$ref]['revision']]))continue;
            if(vp3_initiative_mission_enqueue_v1($pdo,$live,$binding,'missions.prepare',$ref,$idea['objective'],$sources))return 'queued';
        }
        if($matched)return 'already_handled';
        return match($coverage){'unavailable'=>'failed','forbidden'=>'blocked',default=>'no_opportunity'};
    });
}
