<?php
declare(strict_types=1);

const VP3_CAMPAIGNS_REWARDS_RELEASE_V122='vp3-campaigns-rewards-release-v122-20260923';

function campaigns_rewards_release_manifest_v122(): array
{
    return [
        'release'=>'Campaigns & Rewards V1.22',
        'build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V122,
        'scope'=>'Journey Intelligence & Optimization',
        'plugin_key'=>'campaigns_rewards',
        'authority'=>[
            'journey_definitions'=>'campaign_messages.template_json',
            'journey_outcomes'=>'campaign_deliveries + reward_claims attribution',
            'recommendations'=>'campaign_agent_recommendations',
            'idempotency'=>'campaign_idempotency_keys',
            'consent'=>'crm_contact_preferences + crm_merchant_relationships',
            'events'=>'campaign_activity_events + canonical cognitive ingress',
        ],
        'invariants'=>[
            'no_new_tables'=>true,
            'no_second_analytics_ledger'=>true,
            'no_autonomous_learner'=>true,
            'no_second_scheduler'=>true,
            'templates_create_drafts_only'=>true,
            'simulation_is_dry_run'=>true,
            'path_analytics_derived_from_canonical_deliveries'=>true,
            'ab_analysis_uses_observed_outcomes'=>true,
            'send_time_requires_verified_samples'=>true,
            'send_time_optimization_is_opt_in'=>true,
            'frequency_caps_enforced_at_dispatch'=>true,
            'fatigue_controls_enforced_at_dispatch'=>true,
            'agent_recommendations_require_human_review'=>true,
            'recommendations_never_auto_apply'=>true,
            'reward_authority_added'=>false,
            'payment_authority_added'=>false,
        ],
    ];
}

function campaigns_rewards_release_readiness_v122(?PDO $pdo=null): array
{
    $pdo??=db();$domain=function_exists('vp3_cognitive_domain_registry_v2600')?(vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[]):[];
    $checks=[
        'v121_ready'=>function_exists('campaigns_rewards_release_readiness_v121'),
        'templates'=>function_exists('campaigns_rewards_apply_journey_template_v122'),
        'path_analytics'=>function_exists('campaigns_rewards_journey_path_analytics_v122'),
        'ab'=>function_exists('campaigns_rewards_ab_comparison_v122'),
        'send_time'=>function_exists('campaigns_rewards_send_time_signal_v122'),
        'frequency'=>function_exists('campaigns_rewards_frequency_gate_v122'),
        'simulation'=>function_exists('campaigns_rewards_simulate_journey_v122'),
        'recommendations'=>function_exists('campaigns_rewards_refresh_optimization_recommendations_v122'),
        'cognitive_domain'=>in_array(($domain['implementation_status']??''),['integrated-v1.22','integrated-v1.23','integrated-v1.24','integrated-v1.25'],true),
        'schema_ready'=>$pdo&&function_exists('campaigns_rewards_platform_schema_ready_v100')&&campaigns_rewards_platform_schema_ready_v100($pdo),
    ];
    return ['build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V122,'ready'=>!in_array(false,$checks,true),'checks'=>$checks,'authority'=>'diagnostic_only'];
}
