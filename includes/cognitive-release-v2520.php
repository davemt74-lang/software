<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v25.20 — Resource Budgeting & Capacity Reservations release gate.
 */
const VP3_COGNITIVE_RELEASE_V2520='vp3-cognitive-resource-budget-release-v2520-20260922';

function vp3_cognitive_release_manifest_v2520(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2520,
        'release_phase'=>'v25.20',
        'previous_release'=>'v25.10',
        'scope'=>'resource_budgeting_and_capacity_reservations',
        'authority_chain'=>[
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
            'reservation_store_created'=>false,
            'resource_budget_store_created'=>false,
            'reservations_are_derived'=>true,
            'reservations_are_admission_only'=>true,
            'only_autonomous_admission_is_budgeted'=>true,
            'manual_and_supervised_authority_passthrough'=>true,
            'execution_target_is_not_mutated'=>true,
            'approval_state_is_not_mutated'=>true,
            'worker_lease_is_not_created'=>true,
            'resource_budget_failure_preserves_v2480_behavior'=>true,
            'v2510_remains_optimization_authority'=>true,
            'v2490_remains_forecast_authority'=>true,
            'v2480_remains_admission_authority'=>true,
            'phase19_remains_execution_authority'=>true,
            'agent_brain_uses_same_resource_projection'=>true,
            'agent_brief_uses_same_resource_projection'=>true,
            'proactive_now_uses_same_resource_projection'=>true,
            'working_context_includes_bounded_resource_budget'=>true,
            'history_remains_canonical_chat_history'=>true,
            'model_reasoning_persisted'=>false,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2520(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $checks=[
        'v2510_ready'=>function_exists('vp3_cognitive_release_readiness_v2510'),
        'resource_budget_loaded'=>function_exists('vp3_cognitive_resource_budget_plan_v2520'),
        'claim_budget_loaded'=>function_exists('vp3_cognitive_resource_claim_budget_v2520'),
        'portfolio_admission_preserved'=>function_exists('vp3_cognitive_portfolio_claim_admission_v2480'),
        'forecast_preserved'=>function_exists('vp3_cognitive_forecast_snapshot_v2490'),
        'optimization_preserved'=>function_exists('vp3_cognitive_optimization_snapshot_v2510'),
        'phase19_claimant_preserved'=>function_exists('agent_job_claim_next_v1900'),
        'worker_runtime_preserved'=>function_exists('agent_worker_runtime_poll_v1910'),
        'working_context_preserved'=>function_exists('vp3_cognitive_context_assemble_v2420'),
    ];
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2520,
        'ready'=>!in_array(false,$checks,true),
        'checks'=>$checks,
        'authority'=>'diagnostic_only',
    ];
}
