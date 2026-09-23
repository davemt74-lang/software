<?php
declare(strict_types=1);

const VP3_CAMPAIGNS_REWARDS_RELEASE_V110='vp3-campaigns-rewards-release-v110-20260923';

function campaigns_rewards_release_manifest_v110(): array
{
    return [
        'release'=>'Campaigns & Rewards V1.10',
        'build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V110,
        'plugin_key'=>'campaigns_rewards',
        'scope'=>'Rewards Workspace + Agent Chat Reward Tray',
        'authority'=>[
            'reward_products'=>'reward_products',
            'certificate_authority'=>'reward_issuances',
            'transfer_audit'=>'reward_transfers',
            'claim_authority'=>'reward_claims + canonical V1 claim engine',
            'crm_contacts'=>'crm_contacts',
            'merchant_relationships'=>'crm_merchant_relationships',
            'cognitive_events'=>'agent_event_inbox_v1920',
        ],
        'invariants'=>[
            'campaigns_and_rewards_are_separate_workspaces'=>true,
            'reward_wallet_sidebar_destination'=>false,
            'reward_tray_is_projection_not_second_wallet_store'=>true,
            'reward_transfer_clones_issuance'=>false,
            'reward_transfer_changes_holder_transactionally'=>true,
            'reward_transfer_has_durable_audit'=>true,
            'send_targets_core_crm_contacts'=>true,
            'send_invalidates_prior_holder_credential'=>true,
            'credential_plaintext_persisted'=>false,
            'qr_generation_external_service'=>false,
            'qr_credential_sent_in_http_request'=>false,
            'claim_requires_reward_credential_merchant_code_and_authorized_operator'=>true,
            'claim_engine_bypassed'=>false,
            'claim_processing_online_only'=>true,
            'claim_action_tracked'=>true,
            'campaign_versions_remain_immutable'=>true,
            'reward_products_remain_reusable'=>true,
            'plugin_disable_deletes_business_data'=>false,
        ],
    ];
}

function campaigns_rewards_release_readiness_v110(?PDO $pdo=null): array
{
    $pdo??=db();
    $checks=[
        'v100_ready'=>function_exists('campaigns_rewards_release_readiness_v100'),
        'v110_schema_ready'=>$pdo&&function_exists('campaigns_rewards_v110_schema_ready')&&campaigns_rewards_v110_schema_ready($pdo),
        'reward_transfer_table'=>$pdo&&table_exists('reward_transfers'),
        'reward_workspace'=>function_exists('campaigns_rewards_save_reward_workspace_v110'),
        'reward_tray'=>function_exists('campaigns_rewards_reward_tray_v110'),
        'reward_transfer'=>function_exists('campaigns_rewards_transfer_reward_v110'),
        'claim_prepare'=>function_exists('campaigns_rewards_prepare_claim_v110'),
        'claim_bridge'=>function_exists('campaigns_rewards_claim_from_tray_v110'),
        'canonical_claim_engine'=>function_exists('campaigns_rewards_process_claim_v100'),
        'crm_contact_authority'=>function_exists('crm_v180_contacts_for_owner')&&function_exists('crm_v180_contact_for_owner'),
        'cognitive_reward_sent'=>function_exists('vp3_cognitive_domain_registry_v2600')
            && in_array('reward.sent',(array)(vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']['events']??[]),true),
    ];
    return ['build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V110,'ready'=>!in_array(false,$checks,true),'checks'=>$checks,'authority'=>'diagnostic_only'];
}
