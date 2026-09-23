<?php
declare(strict_types=1);

/** VP3 Cognitive Runtime v26.00 — Cognitive Domain Registry release gate. */
const VP3_COGNITIVE_RELEASE_V2600='vp3-cognitive-release-v2600-20260923';

function vp3_cognitive_release_manifest_v2600(): array
{
    return [
        'release'=>'Cognitive Runtime v26.00',
        'build'=>VP3_COGNITIVE_RELEASE_V2600,
        'scope'=>'Cognitive Domain Registry foundation',
        'authority_chain'=>[
            'runtime_module_registry'=>'cognitive_runtime_v500',
            'domain_inventory'=>'cognitive_domain_manifest_v2370',
            'event_ingress'=>'agent_event_inbox_v1920',
            'live_session'=>'agent_live_sessions_v2370',
            'attention_policy'=>'cognitive_attention_v2410',
            'working_context'=>'cognitive_context_v2420',
            'current_state'=>'cognitive_current_state_v2590',
            'presentation'=>'cognitive_presentation_firewall_v2590',
            'decision_calibration'=>'cognitive_decision_calibration_v2580',
            'claims_leases_execution_receipts'=>'agent_job_engine_v1900',
        ],
        'invariants'=>[
            'new_domain_registry_table'=>false,
            'second_event_ledger'=>false,
            'second_runtime_module_registry'=>false,
            'second_attention_pipeline'=>false,
            'second_current_state_store'=>false,
            'second_presentation_layer'=>false,
            'second_brain'=>false,
            'second_memory_store'=>false,
            'second_relevance_learner'=>false,
            'second_scheduler'=>false,
            'second_job_queue'=>false,
            'second_worker'=>false,
            'second_approval_system'=>false,
            'second_execution_authority'=>false,
            'v500_runtime_module_registry_remains_authority'=>true,
            'v2370_domain_inventory_remains_source_manifest'=>true,
            'v1920_event_inbox_remains_ingress_authority'=>true,
            'v2410_attention_remains_policy_authority'=>true,
            'v2590_current_state_remains_ephemeral'=>true,
            'v2590_presentation_firewall_required'=>true,
            'domain_business_records_remain_authoritative'=>true,
            'unknown_or_malformed_domain_events_enter_canonical_inbox'=>false,
            'unknown_or_malformed_domain_events_quarantined_before_ingress'=>true,
            'campaigns_rewards_reference_contract_present'=>true,
            'campaigns_rewards_business_tables_created_by_v2600'=>false,
            'campaigns_rewards_plugin_catalog_exposed_before_implementation'=>false,
            'crm_or_team_records_duplicated_for_campaigns'=>false,
            'model_reasoning_persisted'=>false,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2600(?PDO $pdo=null): array
{
    $registry=function_exists('vp3_cognitive_domain_registry_v2600')?vp3_cognitive_domain_registry_v2600():[];
    $integrity=function_exists('vp3_cognitive_domain_registry_integrity_v2600')?vp3_cognitive_domain_registry_integrity_v2600():['ok'=>false];
    $campaign=(array)($registry['domains']['campaigns_rewards']??[]);
    $checks=[
        'v2590_ready'=>function_exists('vp3_cognitive_release_readiness_v2590'),
        'runtime_registry_preserved'=>function_exists('vp3_cognitive_register_module_v500'),
        'domain_manifest_preserved'=>function_exists('vp3_cognitive_domain_manifest_v2370'),
        'canonical_ingress_preserved'=>function_exists('agent_event_ingest_v1920'),
        'attention_preserved'=>function_exists('vp3_cognitive_attention_decision_v2410'),
        'current_state_preserved'=>function_exists('vp3_cognitive_current_state_projection_v2590'),
        'presentation_firewall_preserved'=>function_exists('vp3_cognitive_presentation_firewall_validate_v2590'),
        'domain_registry_loaded'=>!empty($registry),
        'domain_registry_integrity'=>!empty($integrity['ok']),
        'campaigns_rewards_contract_ready'=>($campaign['implementation_status']??'')==='contract_ready',
        'campaigns_rewards_not_prematurely_exposed'=>empty($campaign['plugin_catalog_registered']),
    ];
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2600,
        'ready'=>!in_array(false,$checks,true),
        'checks'=>$checks,
        'authority'=>'diagnostic_only',
    ];
}
