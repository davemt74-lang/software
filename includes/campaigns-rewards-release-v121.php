<?php
declare(strict_types=1);

const VP3_CAMPAIGNS_REWARDS_RELEASE_V121='vp3-campaigns-rewards-release-v121-20260923';

function campaigns_rewards_release_manifest_v121(): array
{
    return [
        'release'=>'Campaigns & Rewards V1.21',
        'build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V121,
        'scope'=>'Journey Orchestration & Delivery Providers',
        'plugin_key'=>'campaigns_rewards',
        'authority'=>[
            'journey_definitions'=>'campaign_messages.template_json',
            'journey_instances'=>'campaign_deliveries.metadata_json',
            'idempotency'=>'campaign_idempotency_keys',
            'crm_consent'=>'crm_contacts + crm_merchant_relationships + crm_contact_preferences',
            'automation'=>'campaign_automation_rules + campaign_rule_executions',
            'certificate'=>'reward_issuances',
            'events'=>'campaign_activity_events + canonical cognitive ingress',
        ],
        'invariants'=>[
            'no_new_tables'=>true,
            'no_second_scheduler'=>true,
            'no_second_queue'=>true,
            'versioned_nodes'=>true,
            'decision_branching'=>true,
            'wait_until_nodes'=>true,
            'deterministic_weighted_ab_variants'=>true,
            'conversion_exits'=>true,
            'timezone_delivery'=>true,
            'quiet_hours_enforced_at_dispatch'=>true,
            'bounded_exponential_retry'=>true,
            'dead_letter_state'=>true,
            'manual_dead_letter_retry_requires_publish_authority'=>true,
            'sendgrid_email_provider'=>true,
            'twilio_sms_provider'=>true,
            'twilio_webhook_signature_validation'=>true,
            'sendgrid_signed_event_webhook_validation'=>true,
            'provider_webhooks_cannot_issue_rewards'=>true,
            'v120_legacy_journeys_remain_compatible'=>true,
            'agent_cannot_auto_activate_journeys'=>true,
            'parallel_crm_added'=>false,
            'parallel_human_messaging_added'=>false,
            'payment_authority_added'=>false,
        ],
    ];
}

function campaigns_rewards_release_readiness_v121(?PDO $pdo=null): array
{
    $pdo??=db();$domain=function_exists('vp3_cognitive_domain_registry_v2600')?(vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[]):[];
    $checks=[
        'v120_ready'=>function_exists('campaigns_rewards_release_readiness_v120'),
        'orchestrator'=>function_exists('campaigns_rewards_journey_enqueue_v121')&&function_exists('campaigns_rewards_dispatch_due_v121'),
        'providers'=>function_exists('campaigns_rewards_sendgrid_sender_v121')&&function_exists('campaigns_rewards_twilio_sender_v121'),
        'webhooks'=>function_exists('campaigns_rewards_provider_webhook_v121'),
        'retry'=>function_exists('campaigns_rewards_retry_delivery_v121')&&function_exists('campaigns_rewards_retry_dead_letters_v121'),
        'cognitive_domain'=>in_array(($domain['implementation_status']??''),['integrated-v1.21','integrated-v1.22','integrated-v1.23'],true),
        'branch_event'=>in_array('campaign.journey_branch_selected',(array)($domain['events']??[]),true),
        'dead_letter_event'=>in_array('campaign.message_dead_lettered',(array)($domain['events']??[]),true),
        'schema_ready'=>$pdo&&function_exists('campaigns_rewards_platform_schema_ready_v100')&&campaigns_rewards_platform_schema_ready_v100($pdo),
    ];
    return ['build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V121,'ready'=>!in_array(false,$checks,true),'checks'=>$checks,'authority'=>'diagnostic_only'];
}
