<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v25.30 — Autonomous Portfolio Replanning & Recovery release gate.
 */
const VP3_COGNITIVE_RELEASE_V2530='vp3-cognitive-portfolio-replanning-release-v2530-20260922';

function vp3_cognitive_release_manifest_v2530(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2530,
        'release_phase'=>'v25.30',
        'previous_release'=>'v25.20',
        'scope'=>'autonomous_portfolio_replanning_and_recovery',
        'authority_chain'=>[
            'replanning'=>'cognitive_replanning_v2530_recovery_overlay',
            'resource_budget'=>'cognitive_resource_budget_v2520_admission_policy',
            'strategic_optimization'=>'cognitive_optimization_v2510_projection_only',
            'forecast_and_sequence'=>'cognitive_forecast_v2490',
            'portfolio_admission'=>'cognitive_portfolio_v2480',
            'autonomous_mutations'=>'cognitive_autonomy_v2470',
            'claims_leases_execution_receipts'=>'agent_job_engine_v1900',
            'live_capability_readiness'=>'agent_worker_runtime_v1910',
        ],
        'invariants'=>[
            'second_scheduler'=>false,
            'second_job_queue'=>false,
            'second_worker'=>false,
            'second_lease_system'=>false,
            'second_receipt_ledger'=>false,
            'replan_store_created'=>false,
            'historical_plan_store_created'=>false,
            'replanning_is_derived'=>true,
            'replanning_is_recovery_overlay'=>true,
            'only_autonomous_order_may_change'=>true,
            'manual_and_supervised_relative_order_preserved'=>true,
            'execution_target_is_not_mutated'=>true,
            'approval_state_is_not_mutated'=>true,
            'dependency_graph_is_not_mutated'=>true,
            'worker_lease_is_not_created'=>true,
            'capacity_loss_escalates_without_executor_switch'=>true,
            'user_or_approval_gates_escalate_without_bypass'=>true,
            'replan_failure_preserves_v2480_behavior'=>true,
            'v2520_remains_resource_budget_authority'=>true,
            'v2510_remains_optimization_authority'=>true,
            'v2490_remains_forecast_authority'=>true,
            'v2480_remains_admission_authority'=>true,
            'phase19_remains_execution_authority'=>true,
            'agent_brain_uses_same_replanning_projection'=>true,
            'agent_brief_uses_same_replanning_projection'=>true,
            'proactive_now_uses_same_replanning_projection'=>true,
            'working_context_includes_bounded_replanning'=>true,
            'history_remains_canonical_chat_history'=>true,
            'model_reasoning_persisted'=>false,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2530(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $checks=[
        'v2520_ready'=>function_exists('vp3_cognitive_release_readiness_v2520'),
        'replanning_loaded'=>function_exists('vp3_cognitive_replanning_overlay_v2530'),
        'replanning_snapshot_loaded'=>function_exists('vp3_cognitive_replanning_snapshot_v2530'),
        'resource_budget_preserved'=>function_exists('vp3_cognitive_resource_budget_plan_v2520'),
        'optimization_preserved'=>function_exists('vp3_cognitive_optimization_snapshot_v2510'),
        'forecast_preserved'=>function_exists('vp3_cognitive_forecast_snapshot_v2490'),
        'portfolio_admission_preserved'=>function_exists('vp3_cognitive_portfolio_claim_admission_v2480'),
        'phase19_claimant_preserved'=>function_exists('agent_job_claim_next_v1900'),
        'worker_runtime_preserved'=>function_exists('agent_worker_runtime_poll_v1910'),
        'working_context_preserved'=>function_exists('vp3_cognitive_context_assemble_v2420'),
    ];
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2530,
        'ready'=>!in_array(false,$checks,true),
        'checks'=>$checks,
        'authority'=>'diagnostic_only',
    ];
}
