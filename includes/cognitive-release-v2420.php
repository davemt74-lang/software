<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.20 — Unified Context Assembly & Working Memory gate.
 */
const VP3_COGNITIVE_RELEASE_V2420='vp3-cognitive-context-release-v2420-20260922';

function vp3_cognitive_release_manifest_v2420(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2420,
        'release_phase'=>'v24.20',
        'previous_release'=>'v24.10',
        'scope'=>'unified_context_assembly_and_working_memory',
        'context_authority'=>'cognitive_context_v2420',
        'source_authorities'=>[
            'live_session'=>'cognitive_live_session_v2370',
            'priorities'=>'agent_cognitive_loop_v310',
            'episodic_memory'=>'cognitive_memory_v570',
            'durable_memory'=>'agent_memory_items',
            'attention'=>'cognitive_attention_v2410',
            'object_context'=>'cognitive_runtime_v500',
            'conversation'=>'agent_chat_archive',
        ],
        'limits'=>[
            'items'=>VP3_COGNITIVE_CONTEXT_MAX_ITEMS_V2420,
            'item_chars'=>VP3_COGNITIVE_CONTEXT_MAX_ITEM_CHARS_V2420,
            'text_bytes'=>VP3_COGNITIVE_CONTEXT_MAX_TEXT_BYTES_V2420,
            'packet_bytes'=>VP3_COGNITIVE_CONTEXT_MAX_PACKET_BYTES_V2420,
        ],
        'invariants'=>[
            'one_context_builder'=>true,
            'working_memory_is_ephemeral'=>true,
            'second_brain'=>false,
            'second_memory_store'=>false,
            'second_event_ledger'=>false,
            'raw_event_payloads_copied'=>false,
            'model_reasoning_persisted'=>false,
            'object_refs_reauthorized_before_context'=>true,
            'episodic_occurrences_reauthorized'=>true,
            'named_agent_history_is_namespace_scoped'=>true,
            'named_agents_do_not_inherit_unscoped_system_priorities'=>true,
            'retrieved_context_is_data_not_instructions'=>true,
            'context_grants_execution_authority'=>false,
            'voice_grants_authentication_authority'=>false,
            'agent_brain_drawer_uses_context_projection'=>true,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2420(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $checks=[
        'v2410_ready'=>function_exists('vp3_cognitive_release_readiness_v2410'),
        'context_builder_loaded'=>function_exists('vp3_cognitive_context_assemble_v2420'),
        'chat_projection_loaded'=>function_exists('vp3_cognitive_context_chat_items_v2420'),
        'activity_projection_loaded'=>function_exists('vp3_cognitive_context_activity_projection_v2420'),
        'scoped_history_loaded'=>function_exists('vp3_cognitive_context_history_rows_v2420'),
        'v500_authorization_preserved'=>function_exists('vp3_cognitive_authorize_ref_v500'),
        'v570_memory_preserved'=>function_exists('vp3_cognitive_memory_authorized_occurrences_v570'),
        'v2410_attention_preserved'=>function_exists('vp3_cognitive_attention_status_v2410'),
    ];
    if($pdo){
        $checks['live_session_schema']=function_exists('vp3_live_session_schema_ready_v2370')&&vp3_live_session_schema_ready_v2370($pdo);
        $checks['attention_schema']=function_exists('vp3_cognitive_attention_schema_ready_v2410')&&vp3_cognitive_attention_schema_ready_v2410($pdo);
    }
    return ['build'=>VP3_COGNITIVE_RELEASE_V2420,'ready'=>!in_array(false,$checks,true),'checks'=>$checks,'authority'=>'diagnostic_only'];
}
