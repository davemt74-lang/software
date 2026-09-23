<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.50 — Proactive Follow-Through & Cross-Surface Handoff release gate.
 */
const VP3_COGNITIVE_RELEASE_V2450='vp3-cognitive-followthrough-release-v2450-20260922';

function vp3_cognitive_release_manifest_v2450(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2450,
        'release_phase'=>'v24.50',
        'previous_release'=>'v24.40',
        'scope'=>'proactive_followthrough_and_cross_surface_handoff',
        'followthrough_authority'=>'cognitive_followthrough_v2450_projection',
        'authorities'=>[
            'continuity'=>'cognitive_continuity_v2440',
            'attention'=>'cognitive_attention_v2410',
            'working_context'=>'cognitive_context_v2420',
            'turn_orchestration'=>'cognitive_turn_v2430',
            'web_presentation'=>'cognitive_presentation_v510',
            'browser_delivery'=>'extension_notifications_v2140',
        ],
        'invariants'=>[
            'second_notification_queue'=>false,
            'second_delivery_ledger'=>false,
            'second_task_queue'=>false,
            'second_attention_engine'=>false,
            'stable_continuity_state_event_key'=>true,
            'browser_delivery_uses_existing_v2140_ledger'=>true,
            'interruptions_use_v2410_attention'=>true,
            'proactive_turns_use_v2430_turn_types'=>true,
            'focus_turn_assembles_v2420_context'=>true,
            'closed_continuity_disappears_from_candidates'=>true,
            'handoff_metadata_is_projection_only'=>true,
            'quiet_focus_and_budget_are_respected'=>true,
            'approval_is_never_bypassed'=>true,
            'execution_authority_remains_existing_runtime'=>true,
            'agent_namespace_is_preserved'=>true,
            'away_summary_uses_meaningful_continuity_changes'=>true,
            'agent_brain_uses_same_followthrough_projection'=>true,
            'proactive_now_uses_same_followthrough_projection'=>true,
            'model_reasoning_persisted'=>false,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2450(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $checks=[
        'v2440_ready'=>function_exists('vp3_cognitive_release_readiness_v2440'),
        'followthrough_candidates_loaded'=>function_exists('vp3_cognitive_followthrough_candidates_v2450'),
        'attention_preview_loaded'=>function_exists('vp3_cognitive_followthrough_preview_v2450'),
        'browser_candidates_loaded'=>function_exists('vp3_cognitive_followthrough_extension_candidates_v2450'),
        'presentation_brief_loaded'=>function_exists('vp3_cognitive_followthrough_brief_v2450'),
        'continuity_preserved'=>function_exists('vp3_cognitive_continuity_snapshot_v2440'),
        'attention_preserved'=>function_exists('vp3_cognitive_attention_preview_v2410'),
        'working_context_preserved'=>function_exists('vp3_cognitive_context_assemble_v2420'),
        'turn_orchestration_preserved'=>function_exists('vp3_cognitive_turn_prepare_v2430'),
        'browser_delivery_preserved'=>function_exists('vp3_extension_notification_claim_next_v2140'),
    ];
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2450,
        'ready'=>!in_array(false,$checks,true),
        'checks'=>$checks,
        'authority'=>'diagnostic_only',
    ];
}
