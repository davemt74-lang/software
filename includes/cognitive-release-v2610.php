<?php
declare(strict_types=1);

/** VP3 Cognitive Runtime v26.10 — Cross-Domain Entity Graph release gate. */
const VP3_COGNITIVE_RELEASE_V2610='vp3-cognitive-release-v2610-20260923';

function vp3_cognitive_release_manifest_v2610(): array
{
    return [
        'release'=>'Cognitive Runtime v26.10',
        'build'=>VP3_COGNITIVE_RELEASE_V2610,
        'scope'=>'Cross-Domain Entity Graph & Relationship Resolution',
        'authority_chain'=>[
            'object_registry'=>'cognitive_runtime_v500',
            'domain_registry'=>'cognitive_domain_registry_v2600',
            'relationship_providers'=>'registered_domain_relationship_providers',
            'event_seed_source'=>'agent_event_inbox_v1920_object_refs_only',
            'working_context'=>'cognitive_context_v2420',
            'current_state'=>'cognitive_current_state_v2590',
            'presentation'=>'cognitive_presentation_firewall_v2590',
        ],
        'invariants'=>[
            'new_graph_table'=>false,
            'new_entity_table'=>false,
            'domain_business_records_copied'=>false,
            'second_crm'=>false,
            'second_team_store'=>false,
            'second_event_ledger'=>false,
            'second_brain'=>false,
            'second_memory_store'=>false,
            'second_attention_pipeline'=>false,
            'second_execution_authority'=>false,
            'graph_projection_ephemeral'=>true,
            'authorization_checked_each_hop'=>true,
            'event_seed_uses_object_refs_only'=>true,
            'raw_event_payloads_exposed'=>false,
            'name_or_email_similarity_auto_merges_identity'=>false,
            'model_inferred_identity_links_auto_merge'=>false,
            'ambiguous_links_remain_unresolved'=>true,
            'deterministic_or_user_confirmed_relationships_expand_graph'=>true,
            'v2590_presentation_firewall_required'=>true,
            'campaigns_rewards_reference_domain_extended'=>true,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2610(?PDO $pdo=null): array
{
    $checks=[
        'v2600_ready'=>function_exists('vp3_cognitive_release_readiness_v2600'),
        'runtime_registry_preserved'=>function_exists('vp3_cognitive_registry_public_v500'),
        'relationship_runtime_preserved'=>function_exists('vp3_cognitive_relationships_for_ref_v500'),
        'domain_registry_preserved'=>function_exists('vp3_cognitive_domain_registry_v2600'),
        'entity_graph_loaded'=>function_exists('vp3_cognitive_entity_graph_projection_v2610'),
        'entity_graph_health_loaded'=>function_exists('vp3_cognitive_entity_graph_health_v2610'),
        'working_context_bridge_loaded'=>function_exists('vp3_cognitive_entity_graph_context_item_v2610'),
        'presentation_firewall_preserved'=>function_exists('vp3_cognitive_presentation_firewall_validate_v2590'),
        'presentation_bridge_loaded'=>function_exists('vp3_cognitive_entity_graph_presentation_v2610'),
        'campaign_relationship_provider_loaded'=>function_exists('campaigns_rewards_cognitive_relationships_canonical_v100'),
    ];
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2610,
        'ready'=>!in_array(false,$checks,true),
        'checks'=>$checks,
        'authority'=>'diagnostic_only',
    ];
}
