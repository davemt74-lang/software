<?php
declare(strict_types=1);

const VP3_CAMPAIGNS_REWARDS_RELEASE_V123='vp3-campaigns-rewards-release-v123-20260923';

function campaigns_rewards_release_manifest_v123(): array
{
    return [
        'release'=>'Campaigns & Rewards V1.23',
        'build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V123,
        'scope'=>'Journey Publishing, Versioning & Release Management',
        'plugin_key'=>'campaigns_rewards',
        'schema'=>[
            'campaign_journeys',
            'campaign_journey_versions',
            'campaign_journey_publications',
        ],
        'authority'=>[
            'logical_journey'=>'campaign_journeys',
            'immutable_release'=>'campaign_journey_versions',
            'publication_audit'=>'campaign_journey_publications',
            'node_definition'=>'campaign_messages',
            'execution'=>'campaign_deliveries',
            'reward'=>'reward_issuances',
            'recommendations'=>'campaign_agent_recommendations',
        ],
        'invariants'=>[
            'migration_required'=>true,
            'new_tables'=>3,
            'published_versions_immutable'=>true,
            'draft_live_separation'=>true,
            'atomic_graph_publish'=>true,
            'inflight_version_pinning'=>true,
            'new_entrants_use_current_published'=>true,
            'rollback_creates_new_release'=>true,
            'scheduled_publish_requires_prior_human_authorization'=>true,
            'pause_new_enrollment_does_not_stop_inflight'=>true,
            'inflight_continue_migrate_exit_controls'=>true,
            'graph_validation_blocks_structural_errors'=>true,
            'provider_readiness_is_preflight_warning'=>true,
            'visual_builder_is_release_snapshot_driven'=>true,
            'release_health_derived_from_canonical_deliveries'=>true,
            'release_notes_required_surface'=>true,
            'legacy_v121_v122_fallback_preserved'=>true,
            'no_second_scheduler'=>true,
            'no_second_delivery_queue'=>true,
            'agent_cannot_publish_or_rollback'=>true,
            'reward_authority_added'=>false,
            'payment_authority_added'=>false,
        ],
    ];
}

function campaigns_rewards_release_readiness_v123(?PDO $pdo=null): array
{
    $pdo??=db();$domain=function_exists('vp3_cognitive_domain_registry_v2600')?(vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[]):[];
    $checks=[
        'v122_ready'=>function_exists('campaigns_rewards_release_readiness_v122'),
        'schema'=>$pdo&&campaigns_rewards_journey_release_schema_ready_v123($pdo),
        'publish'=>function_exists('campaigns_rewards_publish_journey_v123')&&function_exists('campaigns_rewards_rollback_journey_v123'),
        'pinning'=>function_exists('campaigns_rewards_journey_enqueue_v123')&&function_exists('campaigns_rewards_enqueue_next_step_v123'),
        'validation'=>function_exists('campaigns_rewards_validate_graph_v123')&&function_exists('campaigns_rewards_journey_release_suite_v123'),
        'scheduled'=>function_exists('campaigns_rewards_publish_due_v123'),
        'health'=>function_exists('campaigns_rewards_journey_release_health_v123'),
        'cognitive_domain'=>($domain['implementation_status']??'')==='integrated-v1.23',
        'publish_event'=>in_array('campaign.journey_published',(array)($domain['events']??[]),true),
        'pinned_event'=>in_array('campaign.journey_version_started',(array)($domain['events']??[]),true),
    ];
    return ['build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V123,'ready'=>!in_array(false,$checks,true),'checks'=>$checks,'authority'=>'diagnostic_only'];
}
