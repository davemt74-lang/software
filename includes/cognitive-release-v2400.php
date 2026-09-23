<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.00 — Memory Promotion & Episodic Consolidation release gate.
 */
const VP3_COGNITIVE_RELEASE_V2400='vp3-cognitive-memory-promotion-release-v2400-20260922';

function vp3_cognitive_release_manifest_v2400(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2400,
        'release_phase'=>'v24.00',
        'previous_release'=>'v23.90',
        'scope'=>'memory_promotion_and_episodic_consolidation',
        'memory_layers'=>[
            'episodic'=>'cognitive_memory_occurrences_v570',
            'promotion_audit'=>'cognitive_memory_promotion_receipts_v2400',
            'durable_semantic'=>'agent_memory_items',
        ],
        'invariants'=>[
            'second_brain'=>false,
            'second_event_ledger'=>false,
            'raw_event_payload_promoted'=>false,
            'domain_records_remain_authoritative'=>true,
            'routine_events_auto_promoted'=>false,
            'recurring_patterns_require_threshold'=>true,
            'system_memory_scope_explicit'=>true,
            'passive_browsing_memory'=>false,
            'promotion_is_idempotent'=>true,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2400(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $checks=[
        'v2390_ready'=>function_exists('vp3_cognitive_release_readiness_v2390'),
        'promotion_loaded'=>function_exists('vp3_cognitive_memory_promotion_scan_v2400'),
        'policy_loaded'=>function_exists('vp3_cognitive_memory_promotion_policy_v2400'),
        'episodic_memory_preserved'=>function_exists('vp3_cognitive_memory_occurrence_v570'),
        'brain_store_preserved'=>function_exists('agent_brain_v122_memory_hash'),
    ];
    if($pdo){
        $checks['promotion_schema']=vp3_cognitive_memory_promotion_schema_ready_v2400($pdo);
        $checks['episodic_schema']=function_exists('vp3_cognitive_memory_schema_ready_v570')&&vp3_cognitive_memory_schema_ready_v570($pdo);
        $checks['event_schema']=function_exists('agent_event_schema_ready_v1920')&&agent_event_schema_ready_v1920($pdo);
    }
    return ['build'=>VP3_COGNITIVE_RELEASE_V2400,'ready'=>!in_array(false,$checks,true),'checks'=>$checks,'authority'=>'diagnostic_only'];
}
