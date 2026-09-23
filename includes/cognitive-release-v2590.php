<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v25.90 — Unified Current State & Presentation Firewall release gate.
 */
const VP3_COGNITIVE_RELEASE_V2590='vp3-cognitive-current-state-presentation-release-v2590-20260923';

function vp3_cognitive_release_manifest_v2590(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2590,
        'release_phase'=>'v25.90',
        'previous_release'=>'v25.80',
        'scope'=>'unified_current_state_and_presentation_firewall',
        'authority_chain'=>[
            'event_ingress'=>'agent_event_inbox_v1920',
            'live_session'=>'agent_live_sessions_v2370',
            'legacy_activity_compatibility'=>'agent_activity_v94_snapshot',
            'working_context'=>'cognitive_context_v2420',
            'presentation'=>'cognitive_presentation_firewall_v2590',
            'attention_policy'=>'cognitive_attention_v2410',
            'delivery'=>'cognitive_presentation_v510',
            'decision_calibration'=>'cognitive_decision_calibration_v2580',
            'claims_leases_execution_receipts'=>'agent_job_engine_v1900',
        ],
        'invariants'=>[
            'second_event_ledger'=>false,
            'second_live_session_store'=>false,
            'second_brain'=>false,
            'second_memory_store'=>false,
            'second_relevance_learner'=>false,
            'second_scheduler'=>false,
            'second_job_queue'=>false,
            'second_worker'=>false,
            'second_approval_system'=>false,
            'second_execution_authority'=>false,
            'canonical_event_inbox_remains_authority'=>true,
            'v2370_live_session_remains_authority'=>true,
            'v94_activity_is_compatibility_input_only'=>true,
            'current_state_is_ephemeral_read_only'=>true,
            'raw_event_payloads_user_facing'=>false,
            'system_prompts_user_facing'=>false,
            'tool_traces_user_facing'=>false,
            'confidence_vectors_user_facing'=>false,
            'retrieval_labels_user_facing'=>false,
            'presentation_object_is_allowlist_only'=>true,
            'external_presentation_links_allowed'=>false,
            'working_context_gets_bounded_current_state'=>true,
            'agent_brief_gets_firewalled_current_state'=>true,
            'proactive_now_gets_same_current_state_projection'=>true,
            'agent_brain_gets_same_current_state_projection'=>true,
            'v540_remains_relevance_learning_authority'=>true,
            'v2350_remains_proactive_presentation_calibration_authority'=>true,
            'v2580_remains_decision_calibration_authority'=>true,
            'phase19_remains_claim_lease_execution_receipt_authority'=>true,
            'model_reasoning_persisted'=>false,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2590(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $checks=[
        'v2580_ready'=>function_exists('vp3_cognitive_release_readiness_v2580'),
        'event_inbox_preserved'=>function_exists('agent_event_schema_ready_v1920')&&agent_event_schema_ready_v1920($pdo),
        'live_session_preserved'=>function_exists('vp3_live_session_schema_ready_v2370')&&vp3_live_session_schema_ready_v2370($pdo),
        'current_state_loaded'=>function_exists('vp3_cognitive_current_state_projection_v2590'),
        'presentation_firewall_loaded'=>function_exists('vp3_cognitive_presentation_firewall_validate_v2590'),
        'working_context_preserved'=>function_exists('vp3_cognitive_context_assemble_v2420'),
        'presentation_preserved'=>function_exists('vp3_cognitive_presentation_state_v510'),
        'phase19_claimant_preserved'=>function_exists('agent_job_claim_next_v1900'),
    ];
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2590,
        'ready'=>!in_array(false,$checks,true),
        'checks'=>$checks,
        'authority'=>'diagnostic_only',
    ];
}
