<?php
declare(strict_types=1);

const VP3_CAMPAIGNS_REWARDS_RELEASE_V126='vp3-campaigns-rewards-release-v126-20260923';

function campaigns_rewards_release_manifest_v126(): array
{
    return [
      'release'=>'Campaigns & Rewards V1.26','build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V126,
      'scope'=>'Adaptive Campaign Optimization & Lifecycle Intelligence','plugin_key'=>'campaigns_rewards',
      'schema'=>['campaign_decision_outcomes','campaign_optimization_snapshots'],
      'authority'=>[
        'decisions'=>'campaign_decisions',
        'verified_outcomes'=>'campaign_decision_outcomes',
        'optimization_history'=>'campaign_optimization_snapshots',
        'crm'=>'canonical CRM tables',
        'journeys'=>'campaign_journey_versions + campaign_journey_instances',
        'delivery'=>'campaign_deliveries',
        'rewards'=>'reward_issuances + reward_claims',
        'recommendations'=>'campaign_agent_recommendations',
      ],
      'invariants'=>[
        'migration_required'=>true,'new_tables'=>2,
        'decision_outcomes_append_only'=>true,'optimization_snapshots_append_only'=>true,
        'outcomes_derive_from_canonical_records'=>true,'no_parallel_conversion_ledger'=>true,
        'lifecycle_is_observed_not_overwritten'=>true,'fatigue_is_diagnostic_only'=>true,
        'holdout_reporting_is_observed_not_causal_without_external_outcomes'=>true,
        'recommendations_require_human_review'=>true,'agent_auto_apply'=>false,
        'no_second_crm'=>true,'no_second_reward_ledger'=>true,'no_second_scheduler'=>true,
        'no_autonomous_campaign_mutation'=>true,
      ],
    ];
}

function campaigns_rewards_release_readiness_v126(?PDO $pdo=null): array
{
    $pdo??=db();$domain=function_exists('vp3_cognitive_domain_registry_v2600')?(vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[]):[];
    $checks=[
      'v125_ready'=>function_exists('campaigns_rewards_release_readiness_v125'),
      'schema'=>$pdo&&campaigns_rewards_optimization_schema_ready_v126($pdo),
      'outcome_attribution'=>function_exists('campaigns_rewards_collect_decision_outcomes_v126')&&function_exists('campaigns_rewards_record_outcome_v126'),
      'optimization'=>function_exists('campaigns_rewards_campaign_optimization_v126')&&function_exists('campaigns_rewards_create_optimization_snapshot_v126'),
      'lifecycle'=>function_exists('campaigns_rewards_lifecycle_performance_v126'),
      'fatigue'=>function_exists('campaigns_rewards_fatigue_signal_v126'),
      'governance'=>function_exists('campaigns_rewards_refresh_optimization_v126'),
      'cognitive_domain'=>($domain['implementation_status']??'')==='integrated-v1.26',
    ];
    return ['build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V126,'ready'=>!in_array(false,$checks,true),'checks'=>$checks,'authority'=>'diagnostic_and_recommendation_only'];
}
