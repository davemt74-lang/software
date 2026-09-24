<?php
declare(strict_types=1);

const VP3_CAMPAIGNS_REWARDS_RELEASE_V125='vp3-campaigns-rewards-release-v125-20260923';

function campaigns_rewards_release_manifest_v125(): array
{
    return [
      'release'=>'Campaigns & Rewards V1.25','build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V125,
      'scope'=>'Journey Personalization & Offer Decisioning','plugin_key'=>'campaigns_rewards',
      'schema'=>['campaign_decisions'],
      'authority'=>[
        'rules'=>'immutable Journey Version node template',
        'decision_ledger'=>'campaign_decisions',
        'crm'=>'canonical CRM tables',
        'offers'=>'campaign_reward_sets + campaign_reward_set_items',
        'issuance'=>'reward_issuances through campaigns_rewards_issue_reward_v100',
        'execution'=>'campaign_deliveries',
      ],
      'invariants'=>[
        'migration_required'=>true,'new_tables'=>1,'decision_ledger_append_only'=>true,
        'rules_versioned_with_journey_release'=>true,'holdout_deterministic'=>true,'conflict_suppression_deterministic'=>true,
        'dynamic_reward_uses_attached_rewards_only'=>true,'reward_issuance_still_canonical'=>true,'decision_issue_requires_ledger_authorization'=>true,
        'dynamic_content_uses_saved_context'=>true,'preview_is_dry_run'=>true,'agent_recommendations_no_auto_apply'=>true,
        'no_second_crm'=>true,'no_second_reward_ledger'=>true,'no_second_scheduler'=>true,'no_autonomous_live_rule_change'=>true,
      ],
    ];
}

function campaigns_rewards_release_readiness_v125(?PDO $pdo=null): array
{
    $pdo??=db();$domain=function_exists('vp3_cognitive_domain_registry_v2600')?(vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[]):[];
    $checks=[
      'v124_ready'=>function_exists('campaigns_rewards_release_readiness_v124'),
      'schema'=>$pdo&&campaigns_rewards_decision_schema_ready_v125($pdo),
      'context'=>function_exists('campaigns_rewards_decision_context_v125'),
      'entry'=>function_exists('campaigns_rewards_entry_decision_v125'),
      'offer'=>function_exists('campaigns_rewards_select_offer_v125')&&function_exists('campaigns_rewards_decision_validate_issue_v125'),
      'preview'=>function_exists('campaigns_rewards_preview_decision_v125'),
      'cognitive_domain'=>in_array(($domain['implementation_status']??''),['integrated-v1.25','integrated-v1.26'],true),
    ];
    return ['build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V125,'ready'=>!in_array(false,$checks,true),'checks'=>$checks,'authority'=>'diagnostic_only'];
}
