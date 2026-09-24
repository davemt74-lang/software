<?php
declare(strict_types=1);

const VP3_CAMPAIGNS_REWARDS_RELEASE_V120='vp3-campaigns-rewards-release-v120-20260923';

function campaigns_rewards_release_manifest_v120(): array
{
    return [
        'release'=>'Campaigns & Rewards V1.20',
        'build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V120,
        'scope'=>'Campaign Messaging & Journeys',
        'plugin_key'=>'campaigns_rewards',
        'authority'=>[
            'messages'=>'campaign_messages',
            'deliveries'=>'campaign_deliveries',
            'idempotency'=>'campaign_idempotency_keys',
            'crm'=>'crm_contacts + crm_merchant_relationships + crm_contact_preferences',
            'automation'=>'campaign_automation_rules + campaign_rule_executions',
            'certificate'=>'reward_issuances',
            'events'=>'campaign_activity_events + canonical cognitive ingress',
        ],
        'invariants'=>[
            'no_new_tables'=>true,
            'no_second_scheduler'=>true,
            'cli_due_runner_only'=>true,
            'versioned_message_steps'=>true,
            'durable_scheduled_deliveries'=>true,
            'idempotent_journey_enqueue'=>true,
            'marketing_consent_enforced_at_enqueue_and_send'=>true,
            'transactional_opt_out_enforced'=>true,
            'stop_on_claim_supported'=>true,
            'expiration_reminders_supported'=>true,
            'email_sender_configuration_gated'=>true,
            'sms_provider_adapter_required'=>true,
            'agent_channel_is_notification_not_autonomous_action'=>true,
            'claim_attribution_supported'=>true,
            'automation_uses_v119_authority'=>true,
            'agent_cannot_auto_activate_journeys'=>true,
            'parallel_crm_added'=>false,
            'parallel_human_messaging_added'=>false,
            'parallel_wallet_added'=>false,
            'payment_authority_added'=>false,
        ],
    ];
}

function campaigns_rewards_release_readiness_v120(?PDO $pdo=null): array
{
    $pdo??=db();$domain=function_exists('vp3_cognitive_domain_registry_v2600')?(vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[]):[];
    $checks=[
        'v119_ready'=>function_exists('campaigns_rewards_release_readiness_v119'),
        'runtime'=>function_exists('campaigns_rewards_journey_enqueue_v120')&&function_exists('campaigns_rewards_dispatch_due_v120'),
        'expiration'=>function_exists('campaigns_rewards_queue_expiration_reminders_v120'),
        'performance'=>function_exists('campaigns_rewards_message_performance_v120'),
        'message_tables'=>$pdo&&table_exists('campaign_messages')&&table_exists('campaign_deliveries')&&table_exists('campaign_idempotency_keys'),
        'cognitive_domain'=>in_array(($domain['implementation_status']??''),['integrated-v1.20','integrated-v1.21'],true),
        'message_event'=>in_array('campaign.message_sent',(array)($domain['events']??[]),true),
        'journey_event'=>in_array('campaign.journey_queued',(array)($domain['events']??[]),true),
        'conversion_event'=>in_array('campaign.message_converted',(array)($domain['events']??[]),true),
        'schema_ready'=>$pdo&&function_exists('campaigns_rewards_platform_schema_ready_v100')&&campaigns_rewards_platform_schema_ready_v100($pdo),
    ];
    return ['build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V120,'ready'=>!in_array(false,$checks,true),'checks'=>$checks,'authority'=>'diagnostic_only'];
}
