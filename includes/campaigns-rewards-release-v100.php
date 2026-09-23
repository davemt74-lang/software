<?php
declare(strict_types=1);
const VP3_CAMPAIGNS_REWARDS_RELEASE_V100='vp3-campaigns-rewards-release-v100-20260923';

function campaigns_rewards_release_manifest_v100(): array
{
    return [
        'release'=>'Campaigns & Rewards V1.00','build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V100,'plugin_key'=>'campaigns_rewards','cognitive_domain'=>'campaigns_rewards',
        'authority'=>[
            'merchant_business_records'=>'canonical Campaigns & Rewards V1 tables','crm_contacts'=>'crm_contacts','merchant_relationships'=>'crm_merchant_relationships','team_identity_lifecycle'=>'workspace_memberships_v350',
            'plugin_install_state'=>'user_plugin_installations','cognitive_event_ingress'=>'agent_event_inbox_v1920','current_state'=>'cognitive_current_state_v2590','presentation'=>'cognitive_presentation_firewall_v2590',
        ],
        'invariants'=>[
            'merchant_entity_separate_from_login'=>true,'multiple_merchant_owners_admins_supported'=>true,'crm_contacts_duplicated'=>false,
            'canonical_team_memberships_duplicated'=>false,'team_scope_metadata_is_plugin_specific'=>true,'public_claim_inventory_bounded'=>true,
            'public_claim_per_customer_bounded'=>true,'claim_codes_random_and_unique'=>true,'campaign_profile_tab_active_only'=>true,
            'user_facing_cognition_uses_v2590_firewall'=>true,'campaign_events_use_v2600_registry'=>true,'plugin_disable_deletes_business_data'=>false,
        ],
    ];
}

function campaigns_rewards_release_readiness_v100(?PDO $pdo=null): array
{
    $pdo??=db();$catalog=function_exists('vp3_plugin_catalog_v320')?vp3_plugin_catalog_v320():[];
    $domain=function_exists('vp3_cognitive_domain_registry_v2600')?(vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[]):[];
    $checks=[
        'compatibility_schema_ready'=>$pdo&&campaigns_rewards_schema_ready_v100($pdo),'platform_schema_ready'=>$pdo&&function_exists('campaigns_rewards_platform_schema_ready_v100')&&campaigns_rewards_platform_schema_ready_v100($pdo),'plugin_catalog_registered'=>isset($catalog['campaigns_rewards']),
        'v2600_domain_integrated'=>str_starts_with((string)($domain['implementation_status']??''),'integrated'),
        'v2600_plugin_catalog_consistent'=>!empty($domain['plugin_catalog_registered']),
        'v500_cognitive_module_registered'=>function_exists('vp3_cognitive_registry_public_v500')&&in_array('campaigns_rewards',array_column(vp3_cognitive_registry_public_v500()['modules']??[],'module'),true),
        'canonical_event_ingress'=>function_exists('vp3_cognitive_domain_ingest_v2600'),
        'profile_campaign_query'=>function_exists('campaigns_rewards_profile_campaigns_v100'),'team_scope_contract'=>function_exists('campaigns_rewards_set_team_scope_v100'),
        'crm_link_contract'=>function_exists('crm_v180_upsert_contact'),'merchant_relationship_contract'=>function_exists('campaigns_rewards_merchant_relationship_v100'),
        'reward_issuance_contract'=>function_exists('campaigns_rewards_issue_reward_v100'),'claim_engine_contract'=>function_exists('campaigns_rewards_process_claim_v100'),
    ];
    return ['build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V100,'ready'=>!in_array(false,$checks,true),'checks'=>$checks,'authority'=>'diagnostic_only'];
}
