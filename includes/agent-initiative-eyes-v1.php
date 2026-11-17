<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-live-context-v1.php';

function vp3_initiative_eyes_snapshot_v1(PDO $pdo,array $scope,DateTimeImmutable $now): ?array
{
    if(!table_exists('agent_activity_state'))return null;
    $snapshot=vp3_live_context_snapshot_v1($pdo,$scope['user'],$scope['principal'],$scope['conversation_id'],null,$now);
    $ref=$snapshot['eyes_visit_ref']??null;
    if(!vp3_live_context_eyes_visit_ref_v1($ref))return null;
    require_once __DIR__.'/agent-eyes.php';
    $namespace=$scope['agent_id']>0?'agent:'.$scope['agent_id']:'system';
    $visit=vp3_eyes_visit($pdo,$scope['user'],$ref,$scope['conversation_id'],$namespace,$now->getTimestamp());
    if($visit===null)return null;
    $retained=[
        'ref'=>$ref,'visit_state'=>$visit['state'],'observed_at'=>$visit['occurred_at'],
        'greetings_allowed'=>!empty($snapshot['semantic']['eyes']['greetings_allowed'])&&!empty($visit['greetings_allowed']),
    ];
    return $retained;
}

/** The source key is exact so a changed screen or planner prompt cannot repeat a visit. */
function vp3_initiative_eyes_assignment_v1(PDO $pdo,int $owner,string $ref,bool $assessment): ?array
{
    $key=$assessment?'assessment:'.$ref:$ref;
    $capability=$assessment?'initiative.plan':'initiative.prepare';
    $q=$pdo->prepare("SELECT r.id,r.status,a.result_json,
        EXISTS(SELECT 1 FROM agent_workflow_receipts p
            WHERE p.owner_user_id=r.owner_user_id AND p.run_id=r.id AND p.action_id=a.id
                AND p.attempt_no=a.attempt_count AND p.executor='cloud' AND p.status='completed'
                AND p.result_json=a.result_json) AS receipt_verified
        FROM agent_workflow_runs r JOIN agent_workflow_actions a
          ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id AND a.sequence_no=1
          AND a.capability_key=r.capability_key AND a.execution_target='cloud'
        WHERE r.owner_user_id=? AND r.source_kind='agent_initiative' AND r.capability_key=?
          AND r.execution_target='cloud' AND r.source_key=?"
        .($assessment?" AND r.status='completed' AND a.status='completed'":'')
        .' ORDER BY r.id DESC LIMIT 1');
    $q->execute([$owner,$capability,$key]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    $row['result']=json_decode((string)$row['result_json'],true);
    return $row;
}

/** One optional, ephemeral visit signal in the normal multi-source assessment. */
function vp3_initiative_eyes_evidence_v1(PDO $pdo,array $scope,DateTimeImmutable $now,?array $selectedRefs,array &$coverage): array
{
    if($selectedRefs!==null&&!array_filter($selectedRefs,'vp3_live_context_eyes_visit_ref_v1'))return [];
    $coverage['eyes_visit']='ready';
    try{
        $visit=vp3_initiative_eyes_snapshot_v1($pdo,$scope,$now);
        if(!$visit||!$visit['greetings_allowed']||!in_array($visit['visit_state'],['first','return'],true))return [];
        $ref=$visit['ref'];
        if($selectedRefs!==null&&!in_array($ref,$selectedRefs,true))return [];
        if($selectedRefs===null){
            $assessment=vp3_initiative_eyes_assignment_v1($pdo,(int)$scope['user']['id'],$ref,true);
            if(!empty($assessment['receipt_verified'])&&($assessment['result']['eyes_visit_ref']??null)===$ref)return [];
        }
        $route=vp3_initiative_live_route_admit_v1($pdo,$scope);
    }catch(RuntimeException $error){
        $coverage['eyes_visit']='unavailable';
        return [];
    }
    return [$ref=>[
        'ref'=>$ref,'kind'=>'eyes_visit','visit_state'=>$visit['visit_state'],'title'=>'Current Agent Eyes visit',
        'detail'=>'Fresh provisional camera presence scoped to this Chat. Consider a brief check-in if welcome. No identity or permission is established.',
        'url'=>'','ephemeral'=>true,'revision'=>hash('sha256',$ref.'|'.$visit['visit_state'].'|'.$route),
    ]];
}

function vp3_initiative_eyes_status_v1(PDO $pdo,array $user,array $binding): array
{
    $aid=(int)$binding['agent_id'];$cid=(int)$binding['conversation_id'];
    $principal=vp3_initiative_principal_v1($pdo,$user,$aid);
    $scope=['user'=>$user,'principal'=>$principal,'agent_id'=>$aid,'conversation_id'=>$cid];
    $visit=vp3_initiative_eyes_snapshot_v1($pdo,$scope,new DateTimeImmutable('now',new DateTimeZone('UTC')));
    if(!$visit)return ['decision'=>'no_visit'];
    $status=array_intersect_key($visit,['visit_state'=>true,'observed_at'=>true]);
    $policy=vp3_initiative_policy_v1($user,$aid);
    $ref=$visit['ref'];$owner=(int)$user['id'];
    $work=vp3_initiative_eyes_assignment_v1($pdo,$owner,$ref,false);
    if(!$work){
        $assessment=vp3_initiative_eyes_assignment_v1($pdo,$owner,$ref,true);
        $verified=!empty($assessment['receipt_verified'])&&($assessment['result']['eyes_visit_ref']??null)===$ref;
        return $status+['decision'=>match(true){
            !$visit['greetings_allowed']=>'greetings_off',
            $verified,$visit['visit_state']==='continuing'=>'quiet',
            empty($policy['enabled']),($policy['conversation_id']??null)!==$cid=>'held',
            default=>'pending',
        }];
    }
    $run=(int)$work['id'];$status['run_id']=$run;
    $result=$work['result']??[];
    if($work['status']==='completed'&&!empty($work['receipt_verified'])
        &&($result['initiative_tool_result']['output']['eyes_visit_ref']??null)===$ref){
        $message=(int)($result['initiative_message_id']??0);
        if(($result['initiative_delivery']??null)==='delivered'&&$message>0){
            require_once __DIR__.'/agent-initiative-followthrough-v1.php';
            $origin=vp3_initiative_followthrough_origin_load_v1($pdo,$user,$principal,$cid,$message);
            if((int)$origin['run']['id']!==$run)return $status+['decision'=>'withheld'];
            $claim=$result['initiative_voice']['status']??null;
            return $status+['decision'=>'posted','message_id'=>$message,'voice'=>match($claim){
                'spoken'=>'played','suppressed'=>'skipped','claimed'=>'pending',default=>'unavailable',
            }];
        }
        return $status+['decision'=>($result['initiative_delivery']??null)==='pending'?'held':'withheld'];
    }
    return $status+['decision'=>match($work['status']){
        'approved','queued'=>'queued','executing'=>'working','paused'=>'held',default=>'withheld',
    }];
}
