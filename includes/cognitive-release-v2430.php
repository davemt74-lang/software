<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.30 — Cognitive Turn Orchestration release gate.
 */
const VP3_COGNITIVE_RELEASE_V2430='vp3-cognitive-turn-release-v2430-20260922';

function vp3_cognitive_release_manifest_v2430(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2430,
        'release_phase'=>'v24.30',
        'previous_release'=>'v24.20',
        'scope'=>'cognitive_turn_orchestration',
        'turn_authority'=>'cognitive_turn_v2430',
        'context_authority'=>'cognitive_context_v2420',
        'execution_authority'=>'existing_server_tool_and_runtime_authorities',
        'invariants'=>[
            'one_turn_orchestrator'=>true,
            'v2420_context_required_for_model_turns'=>true,
            'primary_agent_chat_cannot_bypass_v2420'=>true,
            'knowledge_chat_cannot_bypass_v2420'=>true,
            'turn_control_is_server_system_instruction'=>true,
            'retrieved_context_remains_data_only'=>true,
            'turn_control_grants_execution_authority'=>false,
            'turn_control_grants_approval_authority'=>false,
            'turn_control_grants_authentication_authority'=>false,
            'tool_authorization_remains_existing_server_authority'=>true,
            'unverified_action_claims_forbidden'=>true,
            'model_reasoning_persisted'=>false,
            'turn_metadata_contains_refs_not_hidden_reasoning'=>true,
            'activity_center_uses_observable_turn_state'=>true,
            'history_can_show_turn_type_without_exposing_reasoning'=>true,
            'no_new_turn_state_table'=>true,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2430(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $checks=[
        'v2420_ready'=>function_exists('vp3_cognitive_release_readiness_v2420'),
        'turn_prepare_loaded'=>function_exists('vp3_cognitive_turn_prepare_v2430'),
        'turn_finalize_loaded'=>function_exists('vp3_cognitive_turn_finalize_v2430'),
        'turn_system_prompt_loaded'=>function_exists('vp3_cognitive_turn_system_prompt_v2430'),
        'turn_latest_state_loaded'=>function_exists('vp3_cognitive_turn_latest_state_v2430'),
        'context_builder_preserved'=>function_exists('vp3_cognitive_context_assemble_v2420'),
        'runtime_router_preserved'=>function_exists('vp3_agent_runtime_plan_v420'),
        'attention_preserved'=>function_exists('vp3_cognitive_attention_status_v2410'),
    ];
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2430,
        'ready'=>!in_array(false,$checks,true),
        'checks'=>$checks,
        'authority'=>'diagnostic_only',
    ];
}
