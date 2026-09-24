<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/cognitive-runtime-v500.php';
require_once __DIR__.'/../includes/cognitive-domain-manifest-v2370.php';
require_once __DIR__.'/../includes/cognitive-domain-registry-v2600.php';
require_once __DIR__.'/../includes/campaigns-rewards-platform-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-domain-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-v110.php';
require_once __DIR__.'/../includes/campaigns-rewards-release-v110.php';

function cr_v110_assert(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);echo "PASS: {$message}\n";}

$manifest=campaigns_rewards_release_manifest_v110();$inv=$manifest['invariants']??[];
cr_v110_assert(($manifest['release']??'')==='Campaigns & Rewards V1.10','release manifest identifies V1.10');
cr_v110_assert(in_array('reward_transfers',campaigns_rewards_platform_required_tables_v100(),true),'reward transfer ledger is required platform schema');
cr_v110_assert(($inv['campaigns_and_rewards_are_separate_workspaces']??false)===true,'Campaigns and Rewards are separate workspaces');
cr_v110_assert(($inv['reward_wallet_sidebar_destination']??true)===false,'Reward Wallet is not a sidebar destination');
cr_v110_assert(($inv['reward_transfer_clones_issuance']??true)===false&&($inv['reward_transfer_changes_holder_transactionally']??false)===true,'SEND transfers the canonical certificate rather than cloning it');
cr_v110_assert(($inv['send_targets_core_crm_contacts']??false)===true&&($inv['send_invalidates_prior_holder_credential']??false)===true,'SEND is Core CRM based and invalidates prior holder credentials');
cr_v110_assert(($inv['qr_generation_external_service']??true)===false&&($inv['qr_credential_sent_in_http_request']??true)===false,'Claim QR remains local and fragment-only');
cr_v110_assert(($inv['claim_requires_reward_credential_merchant_code_and_authorized_operator']??false)===true&&($inv['claim_engine_bypassed']??true)===false,'V1 three-factor claim engine remains authoritative');

$domain=vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[];
cr_v110_assert(in_array(($domain['implementation_status']??''),['integrated-v1.10','integrated-v1.18','integrated-v1.19','integrated-v1.20','integrated-v1.21','integrated-v1.22','integrated-v1.23'],true),'cognitive domain is at least integrated V1.10');
cr_v110_assert(in_array('reward_transfers',$domain['authority']??[],true),'cognitive domain recognizes Reward Transfer authority');
cr_v110_assert(in_array('reward.sent',$domain['events']??[],true),'reward.sent remains canonical cognitive event');
cr_v110_assert(VP3_CAMPAIGNS_REWARDS_V110==='vp3-campaigns-rewards-v110-20260923','V1.10 runtime build is pinned');
echo "CAMPAIGNS_REWARDS_V110=PASS\n";
