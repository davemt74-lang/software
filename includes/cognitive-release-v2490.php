<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.90 — Portfolio Forecasting & Adaptive Resource Planning release gate.
 */
const VP3_COGNITIVE_RELEASE_V2490='vp3-cognitive-portfolio-forecast-release-v2490-20260922';

function vp3_cognitive_release_manifest_v2490(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2490,
        'release_phase'=>'v24.90',
        'previous_release'=>'v24.80',
        'scope'=>'portfolio_forecasting_and_adaptive_resource_planning',
        'authority_chain'=>[
            'forecast_and_advisory_sequence'=>'cognitive_forecast_v2490',
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
            'forecast_store_created'=>false,
            'forecast_completion_windows_are_estimates'=>true,
            'forecast_confidence_is_explicit'=>true,
            'forecast_failure_preserves_v2480_order'=>true,
            'v2480_remains_admission_authority'=>true,
            'phase19_remains_execution_authority'=>true,
            'adaptive_sequence_is_advisory_only'=>true,
            'working_context_includes_forecast_projection'=>true,
            'agent_brain_uses_same_forecast_projection'=>true,
            'agent_brief_uses_same_forecast_projection'=>true,
            'proactive_now_uses_same_forecast_projection'=>true,
            'history_remains_canonical_chat_history'=>true,
            'model_reasoning_persisted'=>false,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2490(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $checks=[
        'v2480_ready'=>function_exists('vp3_cognitive_release_readiness_v2480'),
        'forecast_loaded'=>function_exists('vp3_cognitive_forecast_snapshot_v2490'),
        'adaptive_resequence_loaded'=>function_exists('vp3_cognitive_forecast_adaptive_resequence_v2490'),
        'portfolio_preserved'=>function_exists('vp3_cognitive_portfolio_snapshot_v2480'),
        'phase19_claimant_preserved'=>function_exists('agent_job_claim_next_v1900'),
        'worker_runtime_preserved'=>function_exists('agent_worker_runtime_poll_v1910'),
        'working_context_preserved'=>function_exists('vp3_cognitive_context_assemble_v2420'),
    ];
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2490,
        'ready'=>!in_array(false,$checks,true),
        'checks'=>$checks,
        'authority'=>'diagnostic_only',
    ];
}
