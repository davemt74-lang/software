<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v25.40 — Commitment & Deadline Protection release gate.
 */
const VP3_COGNITIVE_RELEASE_V2540='vp3-cognitive-commitment-protection-release-v2540-20260922';

function vp3_cognitive_release_manifest_v2540(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2540,
        'release_phase'=>'v25.40',
        'previous_release'=>'v25.30',
        'scope'=>'commitment_and_deadline_protection',
        'canonical_commitment_sources'=>[
            'goals'=>'agent_goal_commitments_v1715_and_agent_goals',
            'meetings'=>'video_meeting_commitment_command_v18230_lineage',
            'meeting_verification'=>'video_meeting_followthrough_closure_v18200',
            'memory_tasks'=>'agent_memory_items_task_lifecycle_v123',
        ],
        'authority_chain'=>[
            'commitment_protection'=>'cognitive_commitment_protection_v2540',
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
            'second_commitment_store'=>false,
            'second_scheduler'=>false,
            'second_job_queue'=>false,
            'second_worker'=>false,
            'second_lease_system'=>false,
            'second_receipt_ledger'=>false,
            'commitment_projection_is_derived'=>true,
            'goal_commitments_use_existing_goal_authority'=>true,
            'meeting_commitments_use_existing_meeting_authority'=>true,
            'memory_commitments_use_existing_memory_authority'=>true,
            'only_existing_claim_candidates_may_be_reordered'=>true,
            'claim_candidate_membership_is_unchanged'=>true,
            'execution_target_is_not_mutated'=>true,
            'deadline_is_not_mutated'=>true,
            'approval_state_is_not_mutated'=>true,
            'completion_is_not_inferred_from_execution'=>true,
            'verified_completion_uses_canonical_source'=>true,
            'v2530_remains_replanning_authority'=>true,
            'v2520_remains_resource_budget_authority'=>true,
            'v2480_remains_portfolio_admission_authority'=>true,
            'phase19_remains_claim_lease_execution_receipt_authority'=>true,
            'agent_brain_uses_same_commitment_projection'=>true,
            'agent_brief_uses_same_commitment_projection'=>true,
            'proactive_now_uses_same_commitment_projection'=>true,
            'working_context_includes_bounded_commitment_projection'=>true,
            'history_remains_canonical_chat_history'=>true,
            'model_reasoning_persisted'=>false,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2540(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $checks=[
        'v2530_ready'=>function_exists('vp3_cognitive_release_readiness_v2530'),
        'commitment_runtime_loaded'=>function_exists('vp3_cognitive_commitment_apply_v2540'),
        'commitment_claim_ranking_loaded'=>function_exists('vp3_cognitive_commitment_rank_claims_v2540'),
        'goal_commitment_authority_available'=>function_exists('agent_goal_commitment_state_v1715')||table_exists('agent_goals'),
        'portfolio_admission_preserved'=>function_exists('vp3_cognitive_portfolio_claim_admission_v2480'),
        'replanning_preserved'=>function_exists('vp3_cognitive_replanning_overlay_v2530'),
        'resource_budget_preserved'=>function_exists('vp3_cognitive_resource_budget_plan_v2520'),
        'phase19_claimant_preserved'=>function_exists('agent_job_claim_next_v1900'),
        'worker_runtime_preserved'=>function_exists('agent_worker_runtime_poll_v1910'),
        'working_context_preserved'=>function_exists('vp3_cognitive_context_assemble_v2420'),
    ];
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2540,
        'ready'=>!in_array(false,$checks,true),
        'checks'=>$checks,
        'authority'=>'diagnostic_only',
    ];
}
