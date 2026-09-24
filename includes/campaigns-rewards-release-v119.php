<?php
declare(strict_types=1);

const VP3_CAMPAIGNS_REWARDS_RELEASE_V119='vp3-campaigns-rewards-release-v119-20260923';

function campaigns_rewards_release_manifest_v119(): array
{
    return [
        'release'=>'Campaigns & Rewards V1.19',
        'build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V119,
        'scope'=>'Automation, Triggers & Lifecycle Intelligence',
        'plugin_key'=>'campaigns_rewards',
        'authority'=>[
            'rules'=>'campaign_automation_rules',
            'executions'=>'campaign_rule_executions',
            'crm'=>'crm_contacts + crm_merchant_relationships + crm_segments',
            'certificate'=>'reward_issuances',
            'recommendations'=>'campaign_agent_recommendations',
            'events'=>'campaign_activity_events + canonical cognitive ingress',
        ],
        'invariants'=>[
            'no_second_scheduler'=>true,
            'cli_due_runner_only'=>true,
            'event_contact_scoped'=>true,
            'saved_crm_segments_reused'=>true,
            'automation_uses_published_campaign_version'=>true,
            'automation_uses_canonical_reward_issuance'=>true,
            'idempotent_rule_execution'=>true,
            'failed_execution_retryable'=>true,
            'inventory_budget_contact_limits_preserved'=>true,
            'commerce_paid_bridge'=>true,
            'loyalty_milestone_bridge'=>true,
            'birthday_cross_year_safe'=>true,
            'win_back_supported'=>true,
            'referral_participant_referrer_both_supported'=>true,
            'lifecycle_recommendations_require_human_decision'=>true,
            'agent_cannot_auto_activate_rules'=>true,
            'parallel_crm_added'=>false,
            'parallel_wallet_added'=>false,
            'payment_authority_added'=>false,
        ],
    ];
}

function campaigns_rewards_release_readiness_v119(?PDO $pdo=null): array
{
    $pdo??=db();$domain=function_exists('vp3_cognitive_domain_registry_v2600')?(vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[]):[];
    $checks=[
        'v118_ready'=>function_exists('campaigns_rewards_release_readiness_v118'),
        'runtime'=>function_exists('campaigns_rewards_automation_run_trigger_v119')&&function_exists('campaigns_rewards_automation_run_due_v119'),
        'audiences'=>function_exists('campaigns_rewards_automation_audiences_v119')&&function_exists('campaigns_rewards_automation_crm_segments_v119'),
        'funnel'=>function_exists('campaigns_rewards_campaign_funnel_v119'),
        'recommendations'=>function_exists('campaigns_rewards_refresh_recommendations_v119'),
        'automation_tables'=>$pdo&&table_exists('campaign_automation_rules')&&table_exists('campaign_rule_executions'),
        'recommendation_table'=>$pdo&&table_exists('campaign_agent_recommendations'),
        'cognitive_domain'=>in_array(($domain['implementation_status']??''),['integrated-v1.19','integrated-v1.20','integrated-v1.21','integrated-v1.22','integrated-v1.23'],true),
        'automation_event'=>in_array('campaign.automation_executed',(array)($domain['events']??[]),true),
        'recommendation_event'=>in_array('campaign.recommendation_proposed',(array)($domain['events']??[]),true),
        'schema_ready'=>$pdo&&function_exists('campaigns_rewards_platform_schema_ready_v100')&&campaigns_rewards_platform_schema_ready_v100($pdo),
    ];
    return ['build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V119,'ready'=>!in_array(false,$checks,true),'checks'=>$checks,'authority'=>'diagnostic_only'];
}
