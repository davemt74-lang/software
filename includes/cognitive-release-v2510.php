<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v25.10 — Strategic Portfolio Optimization release gate.
 */
const VP3_COGNITIVE_RELEASE_V2510='vp3-cognitive-strategic-portfolio-optimization-release-v2510-20260922';

function vp3_cognitive_release_manifest_v2510(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2510,
        'release_phase'=>'v25.10',
        'previous_release'=>'v24.90',
        'scope'=>'strategic_portfolio_optimization',
        'v2500_required'=>false,
        'strategy_set'=>[
            'balanced','protect_deadlines','unlock_dependencies','maximize_throughput',
        ],
        'authority_chain'=>[
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
            'optimization_store_created'=>false,
            'calibration_store_required'=>false,
            'strategy_comparison_is_deterministic'=>true,
            'strategy_metrics_are_observable'=>true,
            'only_autonomous_order_is_optimized'=>true,
            'execution_target_is_not_mutated'=>true,
            'approval_state_is_not_mutated'=>true,
            'work_state_is_not_mutated'=>true,
            'optimization_failure_preserves_v2490_order'=>true,
            'v2490_remains_forecast_authority'=>true,
            'v2480_remains_admission_authority'=>true,
            'phase19_remains_execution_authority'=>true,
            'agent_brain_uses_same_optimization_projection'=>true,
            'agent_brief_uses_same_optimization_projection'=>true,
            'proactive_now_uses_same_optimization_projection'=>true,
            'working_context_includes_bounded_optimization'=>true,
            'history_remains_canonical_chat_history'=>true,
            'model_reasoning_persisted'=>false,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2510(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $checks=[
        'v2490_ready'=>function_exists('vp3_cognitive_release_readiness_v2490'),
        'optimizer_loaded'=>function_exists('vp3_cognitive_optimization_snapshot_v2510'),
        'strategy_compare_loaded'=>function_exists('vp3_cognitive_optimization_compare_v2510'),
        'strategy_resequence_loaded'=>function_exists('vp3_cognitive_optimization_resequence_v2510'),
        'forecast_preserved'=>function_exists('vp3_cognitive_forecast_snapshot_v2490'),
        'portfolio_admission_preserved'=>function_exists('vp3_cognitive_portfolio_claim_admission_v2480'),
        'phase19_claimant_preserved'=>function_exists('agent_job_claim_next_v1900'),
        'worker_runtime_preserved'=>function_exists('agent_worker_runtime_poll_v1910'),
        'working_context_preserved'=>function_exists('vp3_cognitive_context_assemble_v2420'),
    ];
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2510,
        'ready'=>!in_array(false,$checks,true),
        'checks'=>$checks,
        'authority'=>'diagnostic_only',
    ];
}
