<?php
declare(strict_types=1);

const VP3_CAMPAIGNS_REWARDS_RELEASE_V118='vp3-campaigns-rewards-release-v118-20260923';

function campaigns_rewards_release_manifest_v118(): array
{
    $catalog=function_exists('campaigns_rewards_campaign_type_catalog_v118')?campaigns_rewards_campaign_type_catalog_v118():[];
    return [
        'release'=>'Campaigns & Rewards V1.18',
        'build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V118,
        'plugin_key'=>'campaigns_rewards',
        'scope'=>'Microgifter-derived modular Campaign Types + end-to-end Merchant/Reward lifecycle',
        'campaign_type_count'=>count($catalog),
        'authority'=>[
            'campaign_types'=>'campaign_types',
            'campaigns'=>'campaigns + immutable campaign_versions',
            'crm_contacts'=>'crm_contacts',
            'merchant_relationships'=>'crm_merchant_relationships',
            'reward_products'=>'reward_products',
            'certificate_authority'=>'reward_issuances',
            'claim_authority'=>'reward_claims',
            'cognitive_events'=>'agent_event_inbox_v1920',
        ],
        'invariants'=>[
            'campaign_types_modular_extensible'=>true,
            'campaign_and_reward_saved_together'=>true,
            'required_reward_blocks_activation_when_missing'=>true,
            'signup_reward_is_newsletter_acquisition'=>true,
            'signup_reward_requires_marketing_consent'=>true,
            'signup_reward_writes_core_crm'=>true,
            'signup_reward_auto_issues_attached_reward'=>true,
            'public_type_behavior_is_registry_driven'=>true,
            'contest_reward_requires_winner_verification'=>true,
            'event_rsvp_reward_requires_later_verification'=>true,
            'proof_campaign_reward_requires_later_verification'=>true,
            'merchant_participation_fulfillment_queue'=>true,
            'existing_contact_campaigns_reuse_core_crm'=>true,
            'campaign_activity_uses_existing_cognitive_domain'=>true,
            'inbox_sent_claimed_remain_reward_projections'=>true,
            'payment_authority_added'=>false,
            'parallel_crm_added'=>false,
            'parallel_agent_brain_added'=>false,
        ],
    ];
}

function campaigns_rewards_release_readiness_v118(?PDO $pdo=null): array
{
    $pdo??=db();
    $catalog=function_exists('campaigns_rewards_campaign_type_catalog_v118')?campaigns_rewards_campaign_type_catalog_v118():[];
    $domain=function_exists('vp3_cognitive_domain_registry_v2600')?(vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[]):[];
    $checks=[
        'v110_ready'=>function_exists('campaigns_rewards_release_readiness_v110'),
        'catalog_expanded'=>count($catalog)>=20,
        'signup_behavior'=>($catalog['signup']['public_action']??'')==='newsletter_signup'&&($catalog['signup']['marketing']??'')==='required',
        'public_runtime'=>function_exists('campaigns_rewards_public_participate_v118'),
        'reward_sync'=>function_exists('campaigns_rewards_sync_campaign_rewards_v118'),
        'activation_gate'=>function_exists('campaigns_rewards_validate_campaign_activation_v118'),
        'cognitive_domain'=>in_array(($domain['implementation_status']??''),['integrated-v1.18','integrated-v1.19','integrated-v1.20','integrated-v1.21','integrated-v1.22'],true),
        'newsletter_event'=>in_array('campaign.newsletter_signup',(array)($domain['events']??[]),true),
        'reward_attachment_event'=>in_array('campaign.rewards_updated',(array)($domain['events']??[]),true),
        'schema_ready'=>$pdo&&function_exists('campaigns_rewards_platform_schema_ready_v100')&&campaigns_rewards_platform_schema_ready_v100($pdo),
    ];
    return ['build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V118,'ready'=>!in_array(false,$checks,true),'checks'=>$checks,'authority'=>'diagnostic_only'];
}
