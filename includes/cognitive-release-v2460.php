<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.60 — Autonomous Work Supervision release gate.
 */
const VP3_COGNITIVE_RELEASE_V2460='vp3-cognitive-supervision-release-v2460-20260922';

function vp3_cognitive_release_manifest_v2460(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2460,
        'release_phase'=>'v24.60',
        'previous_release'=>'v24.50',
        'scope'=>'autonomous_work_supervision',
        'supervision_authority'=>'cognitive_supervision_v2460_projection',
        'existing_recovery_authorities'=>[
            'durable_job_recovery'=>'agent_job_recover_expired_v1900',
            'workflow_retry'=>'agent_job_retry_v1900_user_controlled',
            'objective_remediation'=>'agent_objective_verification_v176',
            'cognitive_plan_reconciliation'=>'cognitive_orchestration_v560',
            'attention'=>'cognitive_attention_v2410',
            'followthrough'=>'cognitive_followthrough_v2450',
        ],
        'invariants'=>[
            'second_scheduler'=>false,
            'second_task_store'=>false,
            'second_retry_engine'=>false,
            'second_execution_queue'=>false,
            'second_approval_system'=>false,
            'supervision_direct_tool_execution'=>false,
            'supervision_worker_claim'=>false,
            'supervision_terminal_retry'=>false,
            'expired_lease_recovery_uses_existing_job_engine'=>true,
            'plan_reconciliation_uses_authoritative_outcomes'=>true,
            'replan_loop_escalates_to_user'=>true,
            'authorization_loss_never_bypassed'=>true,
            'active_lease_never_stolen'=>true,
            'working_context_includes_supervision'=>true,
            'followthrough_event_key_tracks_health_transition'=>true,
            'agent_brain_uses_same_supervision_projection'=>true,
            'proactive_now_uses_same_supervision_projection'=>true,
            'background_cognitive_loop_runs_governed_reconciliation'=>true,
            'model_reasoning_persisted'=>false,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2460(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $checks=[
        'v2450_ready'=>function_exists('vp3_cognitive_release_readiness_v2450'),
        'supervision_snapshot_loaded'=>function_exists('vp3_cognitive_supervision_snapshot_v2460'),
        'supervision_reconcile_loaded'=>function_exists('vp3_cognitive_supervision_reconcile_owner_v2460'),
        'continuity_preserved'=>function_exists('vp3_cognitive_continuity_snapshot_v2440'),
        'followthrough_preserved'=>function_exists('vp3_cognitive_followthrough_candidates_v2450'),
        'attention_preserved'=>function_exists('vp3_cognitive_attention_preview_v2410'),
        'working_context_preserved'=>function_exists('vp3_cognitive_context_assemble_v2420'),
        'durable_job_recovery_preserved'=>function_exists('agent_job_recover_expired_v1900'),
        'user_retry_preserved'=>function_exists('agent_job_retry_v1900'),
        'plan_reconciliation_preserved'=>function_exists('vp3_cognitive_orchestration_reconcile_run_v560'),
    ];
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2460,
        'ready'=>!in_array(false,$checks,true),
        'checks'=>$checks,
        'authority'=>'diagnostic_only',
    ];
}
