<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.80 — Autonomous Portfolio Coordination release gate.
 */
const VP3_COGNITIVE_RELEASE_V2480='vp3-cognitive-portfolio-release-v2480-20260922';

function vp3_cognitive_release_manifest_v2480(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2480,
        'release_phase'=>'v24.80',
        'previous_release'=>'v24.70',
        'scope'=>'autonomous_portfolio_coordination',
        'coordination_authority'=>'cognitive_portfolio_v2480_projection_and_claim_admission',
        'existing_authorities'=>[
            'goals'=>'agent_goals',
            'goal_objectives'=>'agent_goal_objectives',
            'roadmap'=>'agent_goal_milestones_v1711',
            'objective_portfolio'=>'agent_objective_portfolio_v179',
            'dependencies'=>'agent_work_dependencies_v174',
            'autonomous_mutations'=>'cognitive_autonomy_v2470',
            'durable_claimant'=>'agent_job_engine_v1900',
            'worker_readiness'=>'agent_worker_runtime_v1910',
            'attention'=>'cognitive_attention_v2410',
        ],
        'invariants'=>[
            'second_portfolio_store'=>false,
            'second_scheduler'=>false,
            'second_job_queue'=>false,
            'second_worker'=>false,
            'second_lease_system'=>false,
            'second_receipt_ledger'=>false,
            'second_dependency_graph'=>false,
            'portfolio_direct_tool_execution'=>false,
            'portfolio_worker_claim_authority'=>false,
            'portfolio_approval_authority'=>false,
            'manual_and_supervised_work_remains_ungated'=>true,
            'mixed_authority_shared_objective_passes_through'=>true,
            'claim_gate_applies_only_to_autonomous_objective_steps'=>true,
            'objective_parent_verification_not_portfolio_gated'=>true,
            'semantic_overlap_holds_only_new_materialization'=>true,
            'existing_work_never_cancelled_for_similarity'=>true,
            'worker_capacity_is_projection_only'=>true,
            'capacity_projection_does_not_refresh_live_homeserver_registry'=>true,
            'live_worker_readiness_remains_v1910'=>true,
            'portfolio_claim_failure_fails_open'=>true,
            'canonical_dependencies_are_read_only'=>true,
            'cross_goal_dependency_leverage_is_derived'=>true,
            'shared_objectives_are_reused_not_duplicated'=>true,
            'new_objective_and_remediation_mutations_delegate_to_v2470'=>true,
            'v2470_risk_approval_and_remediation_bounds_preserved'=>true,
            'working_context_includes_portfolio_projection'=>true,
            'agent_brain_uses_same_portfolio_projection'=>true,
            'proactive_now_uses_same_portfolio_projection'=>true,
            'history_remains_canonical_chat_history'=>true,
            'model_reasoning_persisted'=>false,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2480(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $checks=[
        'v2470_ready'=>function_exists('vp3_cognitive_release_readiness_v2470'),
        'portfolio_snapshot_loaded'=>function_exists('vp3_cognitive_portfolio_snapshot_v2480'),
        'portfolio_runner_loaded'=>function_exists('vp3_cognitive_portfolio_run_owner_v2480'),
        'claim_admission_loaded'=>function_exists('vp3_cognitive_portfolio_claim_admission_v2480'),
        'autonomy_preserved'=>function_exists('vp3_cognitive_autonomy_run_owner_v2470'),
        'objective_portfolio_preserved'=>function_exists('agent_objective_portfolio_v179'),
        'dependencies_preserved'=>function_exists('agent_work_dependencies_satisfied_v174'),
        'durable_claimant_preserved'=>function_exists('agent_job_claim_next_v1900'),
        'worker_runtime_preserved'=>function_exists('agent_worker_runtime_poll_v1910'),
        'working_context_preserved'=>function_exists('vp3_cognitive_context_assemble_v2420'),
    ];
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2480,
        'ready'=>!in_array(false,$checks,true),
        'checks'=>$checks,
        'authority'=>'diagnostic_only',
    ];
}
