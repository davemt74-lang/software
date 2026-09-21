<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Operations v23.60 — Cognitive Loop Release Hardening.
 *
 * Read-only release manifest and runtime readiness projection. This phase
 * freezes v23.00-v23.50 feature behavior and adds no new cognitive authority.
 */
const VP3_COGNITIVE_RELEASE_V2360='vp3-cognitive-loop-release-v2360-20260921';

function vp3_cognitive_release_manifest_v2360(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2360,
        'family'=>'cognitive_operations_v23',
        'feature_range'=>'v23.00-v23.50',
        'release_phase'=>'v23.60',
        'status'=>'release_candidate',
        'components'=>[
            'v23.00'=>'cognitive_operations_core',
            'v23.10'=>'unified_agent_priority_queue',
            'v23.20'=>'proactive_opportunity_detection',
            'v23.30'=>'cognitive_action_planning',
            'v23.40'=>'proactive_agent_now_and_agent_voice',
            'v23.50'=>'outcome_learning_and_cognitive_calibration',
        ],
        'canonical_runtime'=>[
            'runtime'=>'v5.00',
            'presentation'=>'v5.10',
            'cards'=>'v5.20',
            'feed'=>'v5.30',
            'learning'=>'v5.40',
            'planning'=>'v5.50',
            'orchestration'=>'v5.60',
            'memory'=>'v5.70',
        ],
        'invariants'=>[
            'second_brain'=>false,
            'second_feed'=>false,
            'second_learning_store'=>false,
            'model_granted_authority'=>false,
            'automatic_external_writes'=>false,
            'approval_bypass'=>false,
            'execution_bypass'=>false,
            'handoff_is_completion'=>false,
            'browser_can_execute_cognitive_tools'=>false,
            'deterministic_attention_can_be_learned_away'=>false,
            'standalone_opportunity_voice'=>false,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2360(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $checks=[
        'runtime_loaded'=>function_exists('vp3_cognitive_state_v500'),
        'cards_loaded'=>function_exists('vp3_cognitive_render_card_v500'),
        'feed_loaded'=>function_exists('vp3_cognitive_feed_compose_v530'),
        'learning_loaded'=>function_exists('vp3_cognitive_learning_feedback_v540'),
        'planning_loaded'=>function_exists('vp3_cognitive_planning_decide_v550'),
        'orchestration_loaded'=>function_exists('vp3_cognitive_orchestration_handoff_v560'),
        'memory_loaded'=>function_exists('vp3_cognitive_memory_sync_v570'),
        'operations_loaded'=>function_exists('vp3_cognitive_operations_compose_v2300'),
        'priority_queue_loaded'=>function_exists('vp3_cognitive_priority_queue_compose_v2310'),
        'opportunities_loaded'=>function_exists('vp3_cognitive_opportunity_sync_v2320'),
        'action_planning_loaded'=>function_exists('vp3_cognitive_action_planning_contract_v2330'),
        'proactive_now_loaded'=>function_exists('vp3_cognitive_proactive_now_compose_v2340'),
        'calibration_loaded'=>function_exists('vp3_cognitive_calibration_state_v2350'),
    ];
    if($pdo){
        $checks['runtime_schema']=function_exists('vp3_cognitive_schema_ready_v500')&&vp3_cognitive_schema_ready_v500($pdo);
        $checks['presentation_schema']=function_exists('vp3_cognitive_presentation_schema_ready_v510')&&vp3_cognitive_presentation_schema_ready_v510($pdo);
        $checks['feed_schema']=function_exists('vp3_cognitive_feed_schema_ready_v530')&&vp3_cognitive_feed_schema_ready_v530($pdo);
        $checks['learning_schema']=function_exists('vp3_cognitive_learning_schema_ready_v540')&&vp3_cognitive_learning_schema_ready_v540($pdo);
        $checks['planning_schema']=function_exists('vp3_cognitive_planning_schema_ready_v550')&&vp3_cognitive_planning_schema_ready_v550($pdo);
        $checks['orchestration_schema']=function_exists('vp3_cognitive_orchestration_schema_ready_v560')&&vp3_cognitive_orchestration_schema_ready_v560($pdo);
        $checks['memory_schema']=function_exists('vp3_cognitive_memory_schema_ready_v570')&&vp3_cognitive_memory_schema_ready_v570($pdo);
    }

    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2360,
        'ready'=>!in_array(false,$checks,true),
        'checks'=>$checks,
        'authority'=>'diagnostic_only',
    ];
}
