<?php
declare(strict_types=1);

/**
 * VP3 Proactive Planning & Suggested Actions v5.50 — Phase 11B.6.
 *
 * Plans are advisory projections over authorized cognitive references. This
 * layer never executes tools, grants permissions, or mutates canonical domain
 * objects. Acceptance only moves a proposal into review-ready state.
 */
const VP3_COGNITIVE_PLANNING_V550='vp3-cognitive-planning-v550-20260918';
const VP3_COGNITIVE_PLANNING_MAX_SYNC_V550=24;
const VP3_COGNITIVE_PLANNING_MAX_FEED_V550=6;

function vp3_cognitive_planning_schema_ready_v550(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && table_exists('cognitive_plans_v550')
        && table_exists('cognitive_plan_events_v550');
}

function vp3_cognitive_planning_ensure_schema_v550(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_cognitive_planning_schema_ready_v550($pdo))return;
    if(!table_exists('users'))throw new RuntimeException('VP3 users must exist before Proactive Planning.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_plans_v550 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      plan_key CHAR(64) NOT NULL,
      source_item_key VARCHAR(190) NOT NULL,
      source_fingerprint CHAR(64) NOT NULL,
      source_kind VARCHAR(80) NOT NULL DEFAULT '',
      object_type VARCHAR(80) NOT NULL,
      object_id VARCHAR(190) NOT NULL,
      object_scope VARCHAR(40) NOT NULL DEFAULT 'personal',
      plan_kind VARCHAR(80) NOT NULL,
      tool_id VARCHAR(120) NOT NULL DEFAULT '',
      risk_level VARCHAR(16) NOT NULL DEFAULT 'low',
      requires_approval TINYINT(1) NOT NULL DEFAULT 0,
      confidence DECIMAL(5,4) NOT NULL DEFAULT 0.5000,
      status VARCHAR(24) NOT NULL DEFAULT 'proposed',
      accepted_at DATETIME NULL,
      dismissed_at DATETIME NULL,
      superseded_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_cognitive_plan_public_v550 (public_id),
      UNIQUE KEY uq_cognitive_plan_key_v550 (owner_user_id,agent_namespace,plan_key),
      INDEX idx_cognitive_plan_owner_v550 (owner_user_id,agent_namespace,status,updated_at),
      INDEX idx_cognitive_plan_source_v550 (owner_user_id,agent_namespace,source_item_key),
      CONSTRAINT fk_cognitive_plan_owner_v550 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_plan_events_v550 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      plan_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      event_type VARCHAR(32) NOT NULL,
      event_key CHAR(64) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_cognitive_plan_event_v550 (event_key),
      INDEX idx_cognitive_plan_event_plan_v550 (plan_id,created_at),
      CONSTRAINT fk_cognitive_plan_event_plan_v550 FOREIGN KEY (plan_id) REFERENCES cognitive_plans_v550(id) ON DELETE CASCADE,
      CONSTRAINT fk_cognitive_plan_event_owner_v550 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_cognitive_planning_tool_meta_v550(string $toolId): ?array
{
    $toolId=vp3_cognitive_id_v500($toolId,120);
    if($toolId==='')return null;
    $registry=vp3_cognitive_registry_storage_v500();
    $meta=$registry['tools'][$toolId]??null;
    return is_array($meta)?$meta:null;
}

function vp3_cognitive_planning_kind_v550(array $candidate): array
{
    $request=is_array($candidate['card_request']??null)?$candidate['card_request']:[];
    $type=(string)($request['card_type']??'');
    $tool='';

    foreach((array)($candidate['planning_action_ids']??[]) as $actionId){
        $meta=vp3_cognitive_planning_tool_meta_v550((string)$actionId);
        if($meta){$tool=(string)$meta['id'];break;}
    }

    $kind=match($type){
        'meeting_brief'=>'prepare_meeting',
        'meeting_summary','meeting_followup'=>'follow_up_meeting',
        'meeting'=>'review_meeting',
        'workflow'=>'review_workflow',
        'goal'=>'advance_goal',
        'opportunity'=>'evaluate_opportunity',
        'risk'=>'mitigate_risk',
        'commitment'=>'follow_through_commitment',
        'decision'=>'evaluate_decision',
        'calendar_booking'=>'prepare_calendar_item',
        'brain_priority'=>'review_priority',
        default=>'',
    };

    if($tool===''){
        $tool=match($kind){
            'prepare_meeting'=>'meeting.prepare_brief',
            'follow_up_meeting'=>'meeting.draft_followup',
            default=>'',
        };
        if($tool!==''&&!vp3_cognitive_planning_tool_meta_v550($tool))$tool='';
    }
    return [$kind,$tool];
}

function vp3_cognitive_planning_candidate_ref_v550(array $candidate): ?array
{
    $request=is_array($candidate['card_request']??null)?$candidate['card_request']:[];
    $ref=is_array($request['object_ref']??null)?$request['object_ref']:[];
    try{return vp3_cognitive_validate_object_ref_v500($ref,true);}catch(Throwable $e){return null;}
}

function vp3_cognitive_planning_sync_v550(PDO $pdo,array $user,string $namespace,array $candidates): void
{
    if(!vp3_cognitive_planning_schema_ready_v550($pdo))return;
    $uid=(int)($user['id']??0);if($uid<1)return;
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $count=0;

    foreach($candidates as $candidate){
        if(++$count>VP3_COGNITIVE_PLANNING_MAX_SYNC_V550)break;
        if(!is_array($candidate))continue;
        if((string)($candidate['source']??'')==='cognitive_plan')continue;
        $sourceKey=mb_strimwidth(trim((string)($candidate['key']??'')),0,190,'');
        $fingerprint=strtolower(trim((string)($candidate['fingerprint']??'')));
        if($sourceKey===''||!preg_match('/^[a-f0-9]{64}$/',$fingerprint))continue;

        [$kind,$toolId]=vp3_cognitive_planning_kind_v550($candidate);
        if($kind==='')continue;
        $ref=vp3_cognitive_planning_candidate_ref_v550($candidate);
        if(!$ref)continue;
        if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$ref,'read'))continue;

        $registry=vp3_cognitive_registry_storage_v500();
        $meta=$toolId!==''?vp3_cognitive_planning_tool_meta_v550($toolId):null;
        $objectModule=(string)($registry['objects'][(string)$ref['type']]??'');
        if(is_array($meta)&&(string)($meta['module']??'')!==$objectModule){
            $toolId='';$meta=null;
        }
        $risk=is_array($meta)?(string)($meta['risk']??'low'):'low';
        $approval=is_array($meta)&&!empty($meta['requires_approval']);
        $confidence=max(.35,min(.98,((float)($candidate['score']??50))/100));
        $planKey=hash('sha256',vp3_cognitive_json_v500([
            $sourceKey,$fingerprint,$kind,$toolId,$ref['type'],$ref['id'],$ref['scope']
        ]));

        $pdo->prepare("UPDATE cognitive_plans_v550
          SET status='superseded',superseded_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
          WHERE owner_user_id=? AND agent_namespace=? AND source_item_key=?
            AND source_fingerprint<>? AND status IN ('proposed','accepted')")
          ->execute([$uid,$namespace,$sourceKey,$fingerprint]);

        $pdo->prepare("INSERT INTO cognitive_plans_v550
          (public_id,owner_user_id,agent_namespace,plan_key,source_item_key,source_fingerprint,source_kind,object_type,object_id,object_scope,plan_kind,tool_id,risk_level,requires_approval,confidence,status)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'proposed')
          ON DUPLICATE KEY UPDATE
            source_kind=VALUES(source_kind),
            risk_level=VALUES(risk_level),
            requires_approval=VALUES(requires_approval),
            confidence=VALUES(confidence)")
          ->execute([
              vp3_cognitive_uuid_v500(),$uid,$namespace,$planKey,$sourceKey,$fingerprint,
              vp3_cognitive_id_v500($candidate['source']??'',80),
              (string)$ref['type'],(string)$ref['id'],(string)$ref['scope'],
              $kind,$toolId,$risk,$approval?1:0,round($confidence,4)
          ]);
    }
}

function vp3_cognitive_planning_row_v550(PDO $pdo,array $user,string $namespace,string|int $id): ?array
{
    if(!vp3_cognitive_planning_schema_ready_v550($pdo))return null;
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $uid=(int)($user['id']??0);if($uid<1)return null;
    $value=trim((string)$id);if($value==='')return null;
    if(ctype_digit($value)){
        $stmt=$pdo->prepare("SELECT * FROM cognitive_plans_v550 WHERE id=? AND owner_user_id=? AND agent_namespace=? LIMIT 1");
        $stmt->execute([(int)$value,$uid,$namespace]);
    }else{
        $stmt=$pdo->prepare("SELECT * FROM cognitive_plans_v550 WHERE public_id=? AND owner_user_id=? AND agent_namespace=? LIMIT 1");
        $stmt->execute([$value,$uid,$namespace]);
    }
    return $stmt->fetch()?:null;
}

function vp3_cognitive_planning_underlying_ref_v550(array $row): array
{
    return vp3_cognitive_object_ref_v500(
        (string)$row['object_type'],
        (string)$row['object_id'],
        (string)($row['object_scope']??'personal')
    );
}

function vp3_cognitive_planning_permission_v550(PDO $pdo,array $user,string $namespace,array $ref,string $operation='read'): bool
{
    if((string)($ref['type']??'')!=='proactive_plan')return false;
    $row=vp3_cognitive_planning_row_v550($pdo,$user,$namespace,(string)($ref['id']??''));
    if(!$row)return false;
    if($operation!=='read')return false;
    try{
        $underlying=vp3_cognitive_planning_underlying_ref_v550($row);
        return vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$underlying,'read');
    }catch(Throwable $e){return false;}
}

function vp3_cognitive_planning_title_v550(string $kind): string
{
    return match($kind){
        'prepare_meeting'=>'Prepare for an upcoming meeting',
        'follow_up_meeting'=>'Complete meeting follow-up',
        'review_meeting'=>'Review an active meeting',
        'review_workflow'=>'Review an active workflow',
        'advance_goal'=>'Advance a current goal',
        'evaluate_opportunity'=>'Evaluate an opportunity',
        'mitigate_risk'=>'Review a risk and next response',
        'follow_through_commitment'=>'Follow through on a commitment',
        'evaluate_decision'=>'Evaluate a pending decision',
        'prepare_calendar_item'=>'Prepare for an upcoming calendar item',
        'review_priority'=>'Review a current Agent priority',
        default=>'Review suggested next action',
    };
}

function vp3_cognitive_planning_steps_v550(array $row): array
{
    $kind=(string)($row['plan_kind']??'');
    $steps=['Review the current canonical VP3 state and supporting evidence.'];
    $steps[]=match($kind){
        'prepare_meeting'=>'Prepare the brief, open questions, and relevant context before the meeting.',
        'follow_up_meeting'=>'Review decisions, commitments, and open follow-up before drafting anything.',
        'review_workflow'=>'Check progress, blockers, approvals, and the safest next step.',
        'advance_goal'=>'Review progress, milestones, dependencies, and the next reversible step.',
        'evaluate_opportunity'=>'Compare the evidence, expected value, timing, and downside before acting.',
        'mitigate_risk'=>'Confirm the risk is current, identify the smallest safe response, and preserve an audit trail.',
        'follow_through_commitment'=>'Confirm ownership, timing, and completion evidence before changing state.',
        'evaluate_decision'=>'Review evidence, alternatives, dependencies, and approval requirements.',
        'prepare_calendar_item'=>'Review timing, participants, context, and preparation needs.',
        'review_priority'=>'Confirm that this priority is still current and determine the next safe action.',
        default=>'Discuss the next safe action with the Agent before making changes.',
    };
    if(trim((string)($row['tool_id']??''))!==''){
        $steps[]='Use the registered VP3 tool only after the user explicitly chooses to proceed.';
    }else{
        $steps[]='Keep the next step conversational until the user chooses a concrete action.';
    }
    return $steps;
}

function vp3_cognitive_planning_context_v550(PDO $pdo,array $user,string $namespace,array $ref,array $options=[]): array
{
    $row=vp3_cognitive_planning_row_v550($pdo,$user,$namespace,(string)($ref['id']??''));
    if(!$row)throw new RuntimeException('Proactive plan not found.');
    $underlying=vp3_cognitive_planning_underlying_ref_v550($row);
    if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$underlying,'read'))throw new RuntimeException('Plan source is no longer authorized.');
    $actionContract=function_exists('vp3_cognitive_action_planning_contract_v2330')
        ? vp3_cognitive_action_planning_contract_v2330($pdo,$user,$namespace,$row)
        : null;
    return [
        'plan'=>[
            'id'=>(string)$row['public_id'],
            'kind'=>(string)$row['plan_kind'],
            'status'=>(string)$row['status'],
            'tool_id'=>(string)$row['tool_id'],
            'risk_level'=>(string)$row['risk_level'],
            'requires_approval'=>!empty($row['requires_approval']),
            'confidence'=>(float)$row['confidence'],
            'steps'=>vp3_cognitive_planning_steps_v550($row),
        ],
        'source_object_ref'=>$underlying,
        'action_contract'=>$actionContract,
        'authority'=>'proposal_only',
    ];
}

function vp3_cognitive_planning_card_v550(PDO $pdo,array $user,string $namespace,array $ref,string $mode): array
{
    $row=vp3_cognitive_planning_row_v550($pdo,$user,$namespace,(string)($ref['id']??''));
    if(!$row)throw new RuntimeException('Proactive plan not found.');
    $context=vp3_cognitive_planning_context_v550($pdo,$user,$namespace,$ref,['card'=>true]);
    $actionContract=is_array($context['action_contract']??null)?$context['action_contract']:[];
    $actionProjection=$actionContract&&function_exists('vp3_cognitive_action_planning_card_sections_v2330')
        ? vp3_cognitive_action_planning_card_sections_v2330($actionContract)
        : ['facts'=>[],'sections'=>[]];
    $status=(string)$row['status'];
    $actions=[[
        'type'=>'prompt',
        'label'=>'Review with Agent',
        'prompt'=>'Review proposed plan '.(string)$row['public_id'].' with me. Explain the evidence, risks, permissions, and each step. Do not execute anything until I explicitly choose an action.'
    ]];
    $liveCapability=is_array($actionContract['capability']??null)?$actionContract['capability']:[];
    if($status==='accepted'
        &&!empty($liveCapability['available'])
        &&(string)($liveCapability['mode']??'')==='existing_capability'
        &&hash_equals((string)($liveCapability['id']??''),vp3_cognitive_id_v500($row['tool_id']??'',120))){
        $actions[]=['type'=>'tool','label'=>'Continue to tool action','tool_id'=>(string)$liveCapability['id']];
    }

    return [
        'title'=>vp3_cognitive_planning_title_v550((string)$row['plan_kind']),
        'subtitle'=>'Proactive plan · proposal only',
        'status'=>$status,
        'summary'=>$status==='accepted'
            ? 'Accepted for review. No tool or canonical object has been changed.'
            : 'Suggested from current VP3 state. No action has been taken.',
        'timestamp'=>(string)$row['updated_at'],
        'badges'=>array_values(array_filter([
            ucfirst((string)$row['risk_level']).' risk',
            !empty($row['requires_approval'])?'Approval required':null,
        ])),
        'facts'=>array_merge([
            ['label'=>'Confidence','value'=>(string)round(((float)$row['confidence'])*100).'%'],
            ['label'=>'Authority','value'=>'Proposal only'],
        ],(array)($actionProjection['facts']??[])),
        'sections'=>array_merge([
            ['label'=>'Proposed sequence','items'=>vp3_cognitive_planning_steps_v550($row)],
        ],(array)($actionProjection['sections']??[]),[
            ['label'=>'Execution boundary','text'=>'Accepting this plan does not execute a tool, approve an action, or mutate the underlying VP3 object.'],
        ]),
        'actions'=>$actions,
    ];
}

function vp3_cognitive_planning_event_v550(PDO $pdo,array $row,int $uid,string $eventType,string $dedupe=''): void
{
    $eventType=vp3_cognitive_id_v500($eventType,32);
    if($eventType==='')return;
    $dedupe=$dedupe!==''?$dedupe:bin2hex(random_bytes(12));
    $key=hash('sha256',vp3_cognitive_json_v500([(int)$row['id'],$uid,$eventType,$dedupe]));
    $pdo->prepare("INSERT IGNORE INTO cognitive_plan_events_v550 (plan_id,owner_user_id,event_type,event_key,created_at)
      VALUES (?,?,?,?,UTC_TIMESTAMP())")
      ->execute([(int)$row['id'],$uid,$eventType,$key]);
}

function vp3_cognitive_planning_decide_v550(PDO $pdo,array $user,string $namespace,string $planId,string $decision): array
{
    if(!in_array($decision,['accept','dismiss'],true))throw new InvalidArgumentException('Unknown proactive plan decision.');
    $row=vp3_cognitive_planning_row_v550($pdo,$user,$namespace,$planId);
    if(!$row)throw new RuntimeException('Proactive plan not found.');
    $underlying=vp3_cognitive_planning_underlying_ref_v550($row);
    if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$underlying,'read'))throw new RuntimeException('Plan source is no longer authorized.');
    if(!in_array((string)$row['status'],['proposed','accepted'],true))throw new RuntimeException('Proactive plan is no longer actionable.');

    if($decision==='accept'&&function_exists('vp3_cognitive_feed_find_candidate_v530')){
        $source=vp3_cognitive_feed_find_candidate_v530($pdo,$user,$namespace,(string)$row['source_item_key']);
        if(!is_array($source)||!hash_equals((string)$source['fingerprint'],(string)$row['source_fingerprint'])){
            $pdo->prepare("UPDATE cognitive_plans_v550 SET status='superseded',superseded_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
              WHERE id=? AND owner_user_id=?")->execute([(int)$row['id'],(int)$user['id']]);
            throw new RuntimeException('This proposed plan changed with its source. Refresh to review the current proposal.');
        }
    }

    $uid=(int)$user['id'];
    if($decision==='accept'){
        $pdo->prepare("UPDATE cognitive_plans_v550 SET status='accepted',accepted_at=COALESCE(accepted_at,UTC_TIMESTAMP()),dismissed_at=NULL,updated_at=UTC_TIMESTAMP()
          WHERE id=? AND owner_user_id=?")->execute([(int)$row['id'],$uid]);
        vp3_cognitive_planning_event_v550($pdo,$row,$uid,'accepted','accept');
    }else{
        $pdo->prepare("UPDATE cognitive_plans_v550 SET status='dismissed',dismissed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
          WHERE id=? AND owner_user_id=?")->execute([(int)$row['id'],$uid]);
        vp3_cognitive_planning_event_v550($pdo,$row,$uid,'dismissed','dismiss');
    }
    return vp3_cognitive_planning_row_v550($pdo,$user,$namespace,$planId)?:$row;
}

function vp3_cognitive_planning_feed_candidates_v550(PDO $pdo,array $user,string $namespace): array
{
    if(!vp3_cognitive_planning_schema_ready_v550($pdo))return [];
    $stmt=$pdo->prepare("SELECT * FROM cognitive_plans_v550
      WHERE owner_user_id=? AND agent_namespace=? AND status IN ('proposed','accepted')
      ORDER BY FIELD(status,'accepted','proposed'),confidence DESC,updated_at DESC
      LIMIT ".VP3_COGNITIVE_PLANNING_MAX_FEED_V550);
    $stmt->execute([(int)$user['id'],$namespace]);
    $out=[];
    foreach($stmt->fetchAll()?:[] as $row){
        try{
            if((string)$row['status']==='accepted'
                && function_exists('vp3_cognitive_orchestration_plan_has_run_v560')
                && vp3_cognitive_orchestration_plan_has_run_v560($pdo,(int)$user['id'],$namespace,(int)$row['id']))continue;
            $underlying=vp3_cognitive_planning_underlying_ref_v550($row);
            if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$underlying,'read'))continue;
            $request=vp3_cognitive_feed_request_v530('proactive_plan',(string)$row['public_id'],'personal','standard');
            $score=(string)$row['status']==='accepted'?79:72;
            $candidate=vp3_cognitive_feed_candidate_v530(
                'plan:'.(string)$row['public_id'],
                (string)$row['status']==='accepted'?'priorities':'opportunities',
                $score,
                (string)$row['status']==='accepted'
                    ? 'Accepted for review; execution still requires an explicit user action.'
                    : 'Suggested from current VP3 state; no action has been taken.',
                $request,
                'cognitive_plan',
                (string)$row['updated_at'],
                ['status'=>$row['status'],'plan_kind'=>$row['plan_kind'],'source_fingerprint'=>$row['source_fingerprint']],
                false
            );
            $candidate['plan_status']=(string)$row['status'];
            $out[]=$candidate;
        }catch(Throwable $e){}
    }
    return $out;
}

function vp3_cognitive_register_planning_v550(): void
{
    $registry=vp3_cognitive_registry_storage_v500();
    if(isset($registry['modules']['proactive_planning']))return;
    vp3_cognitive_register_module_v500([
        'module'=>'proactive_planning',
        'version'=>'proactive-planning-v550',
        'objects'=>['proactive_plan'],
        'events'=>[],
        'permission_resolver'=>'vp3_cognitive_planning_permission_v550',
        'context_provider'=>'vp3_cognitive_planning_context_v550',
        'relationship_provider'=>null,
        'cards'=>['proactive_plan'=>'vp3_cognitive_planning_card_v550'],
        'tools'=>[],
        'freshness_policy'=>['default_seconds'=>60],
        'sensitivity_policy'=>['reference_only'=>true,'proposal_only'=>true],
        'surfaces'=>['brief','away_digest','notification','ask_user','chat_response'],
        'voice_safe'=>false,
    ]);
}

vp3_cognitive_register_planning_v550();
