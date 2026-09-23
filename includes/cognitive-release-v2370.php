<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v23.70 — Unified Live Session & Event Foundation release gate.
 *
 * Read-only readiness projection. Runtime/schema authority remains in the
 * existing event, session and Cognitive Runtime components.
 */
const VP3_COGNITIVE_RELEASE_V2370='vp3-cognitive-live-session-release-v2370-20260922';

function vp3_cognitive_release_manifest_v2370(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2370,
        'release_phase'=>'v23.70',
        'previous_release'=>'v23.60',
        'scope'=>'unified_live_session_event_foundation',
        'canonical'=>[
            'event_ledger'=>'agent_event_inbox',
            'activity_state'=>'agent_activity_state',
            'activity_history'=>'agent_activity_events',
            'live_session'=>'agent_live_sessions_v2370',
            'session_segments'=>'agent_live_session_segments_v2370',
            'cognitive_runtime'=>'v5.00',
            'domain_manifest'=>'v23.70',
        ],
        'invariants'=>[
            'second_brain'=>false,
            'second_chat'=>false,
            'second_event_ledger'=>false,
            'domain_records_remain_authoritative'=>true,
            'low_level_session_events_auto_promote_to_brain'=>false,
            'raw_internal_context_user_facing'=>false,
            'browser_page_context_remains_ephemeral'=>true,
            'execution_authority_changed'=>false,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2370(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $checks=[
        'domain_manifest_loaded'=>function_exists('vp3_cognitive_domain_manifest_v2370'),
        'event_runtime_loaded'=>function_exists('agent_event_ingest_v1920'),
        'live_session_runtime_loaded'=>function_exists('vp3_live_session_snapshot_v2370'),
        'session_cognitive_adapter_loaded'=>function_exists('vp3_cognitive_session_context_v2370'),
        'presentation_firewall_loaded'=>function_exists('chat_presentation_violation_v2370'),
    ];
    if($pdo){
        $checks['event_schema']=agent_event_schema_ready_v1920($pdo);
        $checks['live_session_schema']=vp3_live_session_schema_ready_v2370($pdo);
        if(function_exists('vp3_cognitive_registry_public_v500')){
            $registry=vp3_cognitive_registry_public_v500();
            $checks['session_module_registered']=in_array('live_session',array_column((array)($registry['modules']??[]),'module'),true);
        }else $checks['session_module_registered']=false;
    }
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2370,
        'ready'=>!in_array(false,$checks,true),
        'checks'=>$checks,
        'authority'=>'diagnostic_only',
    ];
}
