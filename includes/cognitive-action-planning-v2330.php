<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Operations v23.30 — Cognitive Action Planning.
 *
 * Read-only Action Plan Contract over v5.50 proposals and v5.60 orchestration.
 * This layer registers no tools, executes nothing and owns no persistence.
 */
const VP3_COGNITIVE_ACTION_PLANNING_V2330='vp3-cognitive-action-planning-v2330-20260921';
const VP3_COGNITIVE_ACTION_PLANNING_CONTRACT_V2330='cognitive-action-plan-v1';
const VP3_COGNITIVE_ACTION_PLANNING_STEP_COUNT_V2330=5;

function vp3_cognitive_action_planning_risk_rank_v2330(string $risk): int
{
    return match($risk){'high'=>3,'medium'=>2,default=>1};
}

function vp3_cognitive_action_planning_tool_v2330(array $plan): array
{
    $toolId=vp3_cognitive_id_v500($plan['tool_id']??'',120);
    if($toolId===''){
        return [
            'id'=>'',
            'available'=>false,
            'mode'=>'review_only',
            'module'=>'agent_chat',
            'label'=>'User chooses a concrete VP3 action',
            'kind'=>'read',
            'risk'=>'low',
            'requires_approval'=>false,
        ];
    }

    $registry=vp3_cognitive_registry_storage_v500();
    $meta=$registry['tools'][$toolId]??null;
    if(!is_array($meta)){
        return [
            'id'=>$toolId,
            'available'=>false,
            'mode'=>'capability_unavailable',
            'module'=>'',
            'label'=>'Registered capability unavailable',
            'kind'=>'',
            'risk'=>(string)($plan['risk_level']??'low'),
            'requires_approval'=>true,
        ];
    }

    $riskOrder=['low'=>1,'medium'=>2,'high'=>3];
    $planRisk=in_array((string)($plan['risk_level']??''),array_keys($riskOrder),true)?(string)$plan['risk_level']:'low';
    $toolRisk=in_array((string)($meta['risk']??''),array_keys($riskOrder),true)?(string)$meta['risk']:'low';
    $risk=($riskOrder[$toolRisk]??1)>=($riskOrder[$planRisk]??1)?$toolRisk:$planRisk;
    $approvalAdded=!empty($meta['requires_approval'])&&empty($plan['requires_approval']);
    $riskIncreased=vp3_cognitive_action_planning_risk_rank_v2330($toolRisk)>vp3_cognitive_action_planning_risk_rank_v2330($planRisk);

    return [
        'id'=>$toolId,
        'available'=>true,
        'mode'=>'existing_capability',
        'module'=>vp3_cognitive_id_v500($meta['module']??'',80),
        'label'=>vp3_cognitive_text_v500($meta['label']??$toolId,120),
        'kind'=>in_array((string)($meta['kind']??'read'),['read','prepare','write','external'],true)?(string)$meta['kind']:'read',
        'risk'=>$risk,
        'requires_approval'=>!empty($plan['requires_approval'])||!empty($meta['requires_approval']),
        'boundary_changed'=>$approvalAdded||$riskIncreased,
        'boundary_changes'=>array_values(array_filter([
            $approvalAdded?'approval_added':null,
            $riskIncreased?'risk_increased':null,
        ])),
    ];
}

function vp3_cognitive_action_planning_handoff_compatible_v2330(array $capability,array $step): bool
{
    if(empty($capability['available'])||(string)($capability['mode']??'')!=='existing_capability')return false;
    if(!hash_equals((string)($capability['id']??''),vp3_cognitive_id_v500($step['tool_id']??'',120)))return false;
    if(!empty($capability['boundary_changed']))return false;
    if(!empty($capability['requires_approval'])&&empty($step['requires_approval']))return false;
    if(vp3_cognitive_action_planning_risk_rank_v2330((string)($capability['risk']??'low'))
        >vp3_cognitive_action_planning_risk_rank_v2330((string)($step['risk_level']??'low')))return false;
    return true;
}

function vp3_cognitive_action_planning_success_v2330(array $plan,array $capability): array
{
    if((string)$capability['mode']==='review_only'){
        return [
            'mode'=>'user_decision_required',
            'statement'=>'This proposal is review-only. Success cannot be auto-claimed until the user selects a concrete existing VP3 action or dismisses the proposal.',
            'verification_authority'=>'user_decision_then_existing_runtime',
            'successful_codes'=>[],
            'failure_codes'=>[],
        ];
    }
    if(empty($capability['available'])){
        return [
            'mode'=>'blocked',
            'statement'=>'The referenced capability is unavailable. The plan must not proceed until a currently registered VP3 capability is selected.',
            'verification_authority'=>'cognitive_runtime_registry',
            'successful_codes'=>[],
            'failure_codes'=>[],
        ];
    }

    $kind=(string)($plan['plan_kind']??'');
    $subject=match($kind){
        'prepare_meeting'=>'meeting preparation',
        'follow_up_meeting'=>'meeting follow-up',
        'review_workflow'=>'workflow next action',
        'advance_goal'=>'goal advancement',
        'evaluate_opportunity'=>'opportunity action',
        'mitigate_risk'=>'risk response',
        'follow_through_commitment'=>'commitment follow-through',
        'evaluate_decision'=>'decision action',
        'prepare_calendar_item'=>'calendar preparation',
        'review_priority'=>'priority action',
        default=>'planned action',
    };
    return [
        'mode'=>'canonical_outcome',
        'statement'=>'Success requires canonical VP3 outcome evidence that the '.$subject.' was successful or resolved after the authoritative handoff.',
        'verification_authority'=>'cognitive_orchestration_v560',
        'successful_codes'=>['successful','resolved'],
        'failure_codes'=>['unsuccessful','ignored'],
    ];
}

function vp3_cognitive_action_planning_contract_v2330(
    PDO $pdo,array $user,string $namespace,array $plan
): array {
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $ref=vp3_cognitive_planning_underlying_ref_v550($plan);
    if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$ref,'read')){
        throw new RuntimeException('Action plan source is no longer authorized.');
    }

    $registry=vp3_cognitive_registry_storage_v500();
    $sourceModule=vp3_cognitive_id_v500($registry['objects'][(string)$ref['type']]??'',80);
    if($sourceModule==='')throw new RuntimeException('Action plan source module is unavailable.');

    $capability=vp3_cognitive_action_planning_tool_v2330($plan);
    if((string)($capability['mode']??'')==='existing_capability'
        &&!hash_equals((string)($capability['module']??''),$sourceModule)){
        $capability['available']=false;
        $capability['mode']='capability_module_mismatch';
        $capability['requires_approval']=true;
        $capability['boundary_changed']=true;
        $capability['boundary_changes']=array_values(array_unique(array_merge(
            (array)($capability['boundary_changes']??[]),['module_changed']
        )));
    }
    $success=vp3_cognitive_action_planning_success_v2330($plan,$capability);
    $approval=!empty($capability['requires_approval']);
    $ready=(string)$capability['mode']==='existing_capability'
        &&!empty($capability['available'])
        &&empty($capability['boundary_changed']);

    $steps=[
        [
            'sequence'=>10,'key'=>'inspect','label'=>'Inspect current state',
            'owner'=>'cognitive_runtime','capability'=>$sourceModule,'mode'=>'read_only',
            'requires_approval'=>false,
            'completion_signal'=>'The source object is still authorized and the accepted plan fingerprint is current.',
        ],
        [
            'sequence'=>20,'key'=>'prepare','label'=>'Prepare handoff',
            'owner'=>'cognitive_planning','capability'=>'cognitive_planning_v550','mode'=>'proposal_only',
            'requires_approval'=>false,
            'completion_signal'=>'Evidence, risk, capability owner, approval boundary, and success criteria are explicit before handoff.',
        ],
        [
            'sequence'=>30,'key'=>'handoff','label'=>'Authorized action handoff',
            'owner'=>$ready?(string)$capability['module']:'user',
            'capability'=>$ready?(string)$capability['id']:'',
            'mode'=>$ready?'existing_runtime':'user_decision_required',
            'requires_approval'=>$approval,
            'completion_signal'=>$ready
                ? 'The authoritative capability receives an explicit handoff request under its existing permission and approval rules.'
                : 'The user selects a concrete currently available VP3 action before any execution can begin.',
        ],
        [
            'sequence'=>40,'key'=>'verify','label'=>'Verify canonical outcome',
            'owner'=>'cognitive_orchestration','capability'=>'cognitive_orchestration_v560','mode'=>'verification_only',
            'requires_approval'=>false,
            'completion_signal'=>(string)$success['statement'],
        ],
        [
            'sequence'=>50,'key'=>'close','label'=>'Close and learn',
            'owner'=>'cognitive_learning','capability'=>'cognitive_outcomes_v540','mode'=>'outcome_learning',
            'requires_approval'=>false,
            'completion_signal'=>'Verified successful/resolved work closes; unsuccessful/ignored work replans; source change supersedes.',
        ],
    ];

    return [
        'contract'=>VP3_COGNITIVE_ACTION_PLANNING_CONTRACT_V2330,
        'build'=>VP3_COGNITIVE_ACTION_PLANNING_V2330,
        'plan'=>[
            'id'=>(string)($plan['public_id']??''),
            'kind'=>(string)($plan['plan_kind']??''),
            'status'=>(string)($plan['status']??''),
            'source_fingerprint'=>(string)($plan['source_fingerprint']??''),
        ],
        'source'=>[
            'object_ref'=>$ref,
            'authority'=>$sourceModule,
        ],
        'capability'=>$capability,
        'execution_readiness'=>$ready?($approval?'approval_required':'handoff_available')
            :(!empty($capability['boundary_changed'])?'replan_required':(string)$capability['mode']),
        'success'=>$success,
        'supersession'=>[
            'source_fingerprint_change'=>'supersede',
            'lost_authorization'=>'block',
            'plan_dismissed'=>'cancel',
        ],
        'steps'=>$steps,
        'execution_boundary'=>[
            'authority'=>'existing_runtime_only',
            'automatic_external_writes'=>false,
            'model_may_execute'=>false,
            'approval_bypass'=>false,
            'authority_bypass'=>false,
            'handoff_is_completion'=>false,
        ],
    ];
}

function vp3_cognitive_action_planning_card_sections_v2330(array $contract): array
{
    $capability=(array)($contract['capability']??[]);
    $success=(array)($contract['success']??[]);
    $items=[];
    foreach((array)($contract['steps']??[]) as $step){
        if(!is_array($step))continue;
        $label=vp3_cognitive_text_v500($step['label']??'Plan step',120);
        $owner=vp3_cognitive_text_v500($step['owner']??'',80);
        $mode=vp3_cognitive_text_v500($step['mode']??'',80);
        $items[]=$label.' — '.$owner.($mode!==''?' · '.str_replace('_',' ',$mode):'');
    }
    return [
        'facts'=>[
            ['label'=>'Capability','value'=>(string)($capability['label']??'User decision required')],
            ['label'=>'Capability owner','value'=>(string)($capability['module']??'')],
            ['label'=>'Execution readiness','value'=>ucwords(str_replace('_',' ',(string)($contract['execution_readiness']??'review_only')))],
            ['label'=>'Approval','value'=>!empty($capability['requires_approval'])?'Required by existing runtime':'Not added by v23.30'],
        ],
        'sections'=>[
            ['label'=>'Bounded action contract','items'=>$items],
            ['label'=>'Success criteria','text'=>(string)($success['statement']??'Canonical outcome evidence is required.')],
            ['label'=>'Authority boundary','text'=>'v23.30 plans and explains. Existing VP3 capabilities authorize and execute; v5.60 verifies canonical outcomes before closure.'],
        ],
    ];
}
