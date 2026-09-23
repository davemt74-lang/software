<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v23.90 — Domain Integration II release gate.
 */
const VP3_COGNITIVE_RELEASE_V2390='vp3-cognitive-domain-integration-release-v2390-20260922';

function vp3_cognitive_release_manifest_v2390(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2390,
        'release_phase'=>'v23.90',
        'previous_release'=>'v23.80',
        'scope'=>'domain_integration_ii',
        'domains'=>[
            'meetings','browser_operations','research_knowledge','messaging_team',
            'workflow_tools_approvals','homeserver_operations','media_studio','analytics_attribution'
        ],
        'invariants'=>[
            'second_event_ledger'=>false,
            'second_brain'=>false,
            'domain_records_remain_authoritative'=>true,
            'domain_events_record_only'=>true,
            'brain_promotion_deferred'=>true,
            'passive_browsing_memory'=>false,
            'raw_message_body_in_events'=>false,
            'raw_browser_page_content_in_events'=>false,
            'raw_tool_payloads_in_events'=>false,
            'homeserver_credentials_in_events'=>false,
            'execution_authority_changed'=>false,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2390(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $expected=[
        'meetings','browser_operations','research_knowledge','messaging_team',
        'workflow_tools_approvals','homeserver_operations','media_studio','analytics_attribution'
    ];
    $checks=[
        'v2380_ready'=>function_exists('vp3_cognitive_release_readiness_v2380'),
        'meetings_runtime_preserved'=>function_exists('vp3_cognitive_register_meetings_v500'),
        'domain_bridge_loaded'=>function_exists('vp3_cognitive_domain_record_v2390'),
        'research_bridge_loaded'=>function_exists('vp3_cognitive_research_event_bridge_v2390'),
        'knowledge_bridge_loaded'=>function_exists('vp3_cognitive_knowledge_event_v2390'),
        'messaging_bridge_loaded'=>function_exists('vp3_cognitive_message_bridge_v2390'),
        'team_bridge_loaded'=>function_exists('vp3_cognitive_team_member_event_v2390'),
        'tool_bridge_loaded'=>function_exists('vp3_cognitive_tool_event_v2390'),
        'homeserver_bridge_loaded'=>function_exists('vp3_cognitive_homeserver_event_v2390'),
        'media_bridge_loaded'=>function_exists('vp3_cognitive_media_event_v2390'),
        'attribution_bridge_loaded'=>function_exists('vp3_cognitive_attribution_event_v2390'),
        'browser_bridge_loaded'=>function_exists('vp3_cognitive_browser_transaction_event_v2390'),
        'annotation_bridge_loaded'=>function_exists('vp3_cognitive_annotation_event_v2390'),
    ];
    if($pdo){
        $checks['event_schema']=function_exists('agent_event_schema_ready_v1920')&&agent_event_schema_ready_v1920($pdo);
        if(function_exists('vp3_cognitive_registry_public_v500')){
            $registry=vp3_cognitive_registry_public_v500();
            $modules=array_column((array)($registry['modules']??[]),'module');
            foreach($expected as $domain)$checks['module_'.$domain]=in_array($domain,$modules,true);
        }
    }
    return ['build'=>VP3_COGNITIVE_RELEASE_V2390,'ready'=>!in_array(false,$checks,true),'checks'=>$checks,'authority'=>'diagnostic_only'];
}
