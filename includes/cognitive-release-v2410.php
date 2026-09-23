<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.10 — Unified Attention Engine release gate.
 */
const VP3_COGNITIVE_RELEASE_V2410='vp3-cognitive-attention-release-v2410-20260922';

function vp3_cognitive_release_manifest_v2410(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2410,
        'release_phase'=>'v24.10',
        'previous_release'=>'v24.00',
        'scope'=>'unified_attention_engine',
        'policy_authority'=>'cognitive_attention_v2410',
        'delivery_authorities'=>['cognitive_presentation_v510','extension_notifications_v2140'],
        'budget'=>[
            'scope'=>'user_global_across_agent_namespaces_and_surfaces',
            'interruptions'=>VP3_COGNITIVE_ATTENTION_MAX_INTERRUPTS_V2410,
            'window_minutes'=>VP3_COGNITIVE_ATTENTION_WINDOW_MINUTES_V2410,
            'planned_reservation_ttl_minutes'=>VP3_COGNITIVE_ATTENTION_PLAN_TTL_MINUTES_V2410,
            'repeat_cooldown_minutes'=>VP3_COGNITIVE_ATTENTION_REPEAT_COOLDOWN_MINUTES_V2410,
            'critical_can_bypass'=>true,
        ],
        'invariants'=>[
            'one_attention_policy'=>true,
            'second_notification_queue'=>false,
            'second_voice_queue'=>false,
            'existing_delivery_ledgers_preserved'=>true,
            'candidate_preview_consumes_budget'=>false,
            'only_selected_interruptions_reserve_budget'=>true,
            'quiet_and_focus_suppress_noncritical_interruptions'=>true,
            'noninterruptible_state_suppresses_voice'=>true,
            'noninterruptible_user_response_is_deferred'=>true,
            'sensitive_content_spoken'=>false,
            'repeat_interruptions_cooled_down'=>true,
            'released_reservations_reconsiderable'=>true,
            'stale_planned_reservations_do_not_hold_budget'=>true,
            'cross_surface_reservations_serialized'=>true,
            'direct_user_requests_never_suppressed'=>true,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2410(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $checks=[
        'v2400_ready'=>function_exists('vp3_cognitive_release_readiness_v2400'),
        'attention_policy_loaded'=>function_exists('vp3_cognitive_attention_decide_v2410'),
        'attention_arbiter_loaded'=>function_exists('vp3_cognitive_attention_arbitrate_v2410'),
        'attention_preview_loaded'=>function_exists('vp3_cognitive_attention_preview_v2410'),
        'presentation_delivery_preserved'=>function_exists('vp3_cognitive_presentation_state_v510'),
        'presentation_policy_entry_preserved'=>function_exists('vp3_cognitive_presentation_decide_v500'),
    ];
    if($pdo){
        $checks['attention_schema']=vp3_cognitive_attention_schema_ready_v2410($pdo);
        $checks['presentation_schema']=function_exists('vp3_cognitive_presentation_schema_ready_v510')&&vp3_cognitive_presentation_schema_ready_v510($pdo);
    }
    return ['build'=>VP3_COGNITIVE_RELEASE_V2410,'ready'=>!in_array(false,$checks,true),'checks'=>$checks,'authority'=>'diagnostic_only'];
}
