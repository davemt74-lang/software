<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v23.80 — Domain Integration I release gate.
 */
const VP3_COGNITIVE_RELEASE_V2380='vp3-cognitive-domain-integration-release-v2380-20260922';

function vp3_cognitive_release_manifest_v2380(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2380,
        'release_phase'=>'v23.80',
        'previous_release'=>'v23.70',
        'scope'=>'domain_integration_i',
        'domains'=>['profile_agent','scheduling','booking_appointments','calendar','commerce','crm_relationships','notifications'],
        'invariants'=>[
            'second_event_ledger'=>false,
            'second_brain'=>false,
            'domain_records_remain_authoritative'=>true,
            'domain_events_record_only'=>true,
            'brain_promotion_deferred'=>true,
            'raw_event_payloads_user_facing'=>false,
            'execution_authority_changed'=>false,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2380(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $expected=['profile_agent','scheduling','booking_appointments','calendar','commerce','crm_relationships','notifications'];
    $checks=[
        'v2370_ready'=>function_exists('vp3_cognitive_release_readiness_v2370'),
        'domain_bridge_loaded'=>function_exists('vp3_cognitive_domain_record_v2380'),
        'event_catalog_loaded'=>function_exists('vp3_cognitive_domain_event_types_v2380'),
        'profile_bridge_loaded'=>function_exists('vp3_cognitive_profile_event_bridge_v2380'),
        'booking_bridge_loaded'=>function_exists('vp3_cognitive_booking_event_v2380'),
        'calendar_bridge_loaded'=>function_exists('vp3_cognitive_calendar_event_v2380'),
        'commerce_bridge_loaded'=>function_exists('vp3_cognitive_commerce_audit_bridge_v2380'),
        'relationship_bridge_loaded'=>function_exists('vp3_cognitive_crm_relationship_bridge_v2380'),
        'notification_bridge_loaded'=>function_exists('vp3_cognitive_notification_event_v2380'),
    ];
    if($pdo){
        $checks['event_schema']=function_exists('agent_event_schema_ready_v1920')&&agent_event_schema_ready_v1920($pdo);
        if(function_exists('vp3_cognitive_registry_public_v500')){
            $registry=vp3_cognitive_registry_public_v500();
            $modules=array_column((array)($registry['modules']??[]),'module');
            foreach($expected as $domain)$checks['module_'.$domain]=in_array($domain,$modules,true);
        }
    }
    return ['build'=>VP3_COGNITIVE_RELEASE_V2380,'ready'=>!in_array(false,$checks,true),'checks'=>$checks,'authority'=>'diagnostic_only'];
}
