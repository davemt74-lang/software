<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.70 — Governed Autonomous Remediation &
 * Long-Horizon Project Execution release gate.
 */
const VP3_COGNITIVE_RELEASE_V2470='vp3-cognitive-autonomy-release-v2470-20260922';

function vp3_cognitive_release_manifest_v2470(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2470,
        'release_phase'=>'v24.70',
        'previous_release'=>'v24.60',
        'scope'=>'governed_autonomous_remediation_and_long_horizon_execution',
        'autonomy_authority'=>'agent_goals.execution_mode',
        'modes'=>['manual','supervised','autonomous'],
        'existing_authorities'=>[
            'goals'=>'agent_goals',
            'roadmap'=>'agent_goal_milestones_v1711',
            'objectives'=>'agent_objective_plans_v175',
            'verification_and_remediation'=>'agent_objective_verification_v176',
            'workflow_dependencies'=>'agent_work_dependencies_v174',
            'durable_jobs'=>'agent_job_engine_v1900',
            'worker_runtime'=>'agent_worker_runtime_v1910',
            'supervision'=>'cognitive_supervision_v2460',
            'followthrough'=>'cognitive_followthrough_v2450',
            'attention'=>'cognitive_attention_v2410',
        ],
        'invariants'=>[
            'default_goal_mode_is_manual'=>true,
            'autonomous_mode_requires_explicit_user_change'=>true,
            'second_project_store'=>false,
            'second_scheduler'=>false,
            'second_worker'=>false,
            'second_retry_engine'=>false,
            'second_approval_system'=>false,
            'second_receipt_ledger'=>false,
            'autonomy_direct_tool_execution'=>false,
            'autonomy_worker_claim'=>false,
            'autonomy_approval_bypass'=>false,
            'autonomy_does_not_invent_roadmap'=>true,
            'autonomy_materializes_only_existing_milestones'=>true,
            'created_objectives_use_phase_175_risk_and_approval'=>true,
            'execution_uses_phase_19_worker_lease_receipt_path'=>true,
            'automatic_failed_work_replacement_low_risk_only'=>true,
            'cancelled_work_never_auto_replaced'=>true,
            'automatic_remediation_cycles_are_bounded'=>true,
            'replacement_is_idempotent'=>true,
            'replaced_failures_become_historical_not_active_blockers'=>true,
            'working_context_includes_autonomy_projection'=>true,
            'agent_brain_uses_same_autonomy_projection'=>true,
            'proactive_now_uses_same_autonomy_projection'=>true,
            'history_remains_canonical_chat_history'=>true,
            'model_reasoning_persisted'=>false,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2470(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $checks=[
        'v2460_ready'=>function_exists('vp3_cognitive_release_readiness_v2460'),
        'autonomy_schema_loaded'=>function_exists('vp3_cognitive_autonomy_schema_ready_v2470'),
        'autonomy_runner_loaded'=>function_exists('vp3_cognitive_autonomy_run_owner_v2470'),
        'autonomy_projection_loaded'=>function_exists('vp3_cognitive_autonomy_snapshot_v2470'),
        'goal_execution_preserved'=>function_exists('agent_goal_execution_state_v1712'),
        'objective_creation_preserved'=>function_exists('agent_objective_create_v175'),
        'objective_replacement_preserved'=>function_exists('agent_objective_verification_rewire_failed_v176'),
        'durable_job_engine_preserved'=>function_exists('agent_job_claim_next_v1900'),
        'worker_runtime_preserved'=>function_exists('agent_worker_runtime_poll_v1910'),
        'supervision_preserved'=>function_exists('vp3_cognitive_supervision_snapshot_v2460'),
        'attention_preserved'=>function_exists('vp3_cognitive_attention_preview_v2410'),
    ];
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2470,
        'ready'=>!in_array(false,$checks,true),
        'checks'=>$checks,
        'authority'=>'diagnostic_only',
    ];
}
