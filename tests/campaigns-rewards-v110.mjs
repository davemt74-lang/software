import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const schema=read('includes/campaigns-rewards-platform-v100.php');
const runtime=read('includes/campaigns-rewards-v110.php');
const release=read('includes/campaigns-rewards-release-v110.php');
const domain=read('includes/cognitive-domain-registry-v2600.php');
const nav=read('includes/member-navigation.php');
const sidebar=read('includes/main-sidebar.php');
const campaigns=read('campaigns.php');
const rewards=read('rewards.php');
const wallet=read('rewards-wallet.php');
const claim=read('campaign-claim.php');
const chat=read('chat.php');
const api=read('api/reward-tray-v110.php');
const tray=read('reward-tray-v110.js');
const qr=read('reward-qr-v110.js');
const css=read('reward-tray-v110.css');
const docs=read('docs/CAMPAIGNS_REWARDS_V110.md');
const setup=read('setup.php');
const upgrade=read('upgrade.php');

const checks=[
 ['Reward Transfer is durable canonical audit state',/CREATE TABLE IF NOT EXISTS reward_transfers/.test(schema)&&/reward_issuance_id/.test(schema)&&/from_contact_id/.test(schema)&&/to_contact_id/.test(schema)&&/idempotency_key/.test(schema)],
 ['platform readiness requires Reward Transfer schema',/reward_issuances','reward_transfers','reward_claims/.test(schema)],
 ['SEND locks current holder and never inserts a second issuance',/campaigns_rewards_reward_holder_v110\\(\\$pdo,\\$issuanceId,\\$senderUserId,true\\)/.test(runtime)&&/UPDATE reward_issuances SET recipient_contact_id/.test(runtime)&&!/INSERT INTO reward_issuances/.test((runtime.match(/function campaigns_rewards_transfer_reward_v110[\\s\\S]*?function campaigns_rewards_prepare_claim_v110/)||[''])[0])],
 ['SEND uses sender Core CRM selection then resolves Merchant Core CRM identity',/crm_v180_contact_for_owner\\(\\$pdo,\\$senderUserId,\\$sourceContactId\\)/.test(runtime)&&/campaigns_rewards_resolve_contact_v100/.test(runtime)&&/campaigns_rewards_ensure_merchant_relationship_v100/.test(runtime)],
 ['SEND invalidates previously revealed credential',/newCredential=campaigns_rewards_secret_v100/.test(runtime)&&/credential_hash=\\?,credential_last4=\\?/.test(runtime)],
 ['SEND records transfer audit and reward.sent cognition',/INSERT INTO reward_transfers/.test(runtime)&&/reward.sent/.test(runtime)&&/campaigns_rewards_activity_event_v100/.test(runtime)],
 ['Reward Tray has Inbox Sent Claimed projections and counts',/inbox/.test(runtime)&&/sent/.test(runtime)&&/claimed/.test(runtime)&&/'counts'=>\\['inbox'=>count\\(\\$inbox\\),'sent'=>count\\(\\$sent\\),'claimed'=>count\\(\\$claimed\\)\\]/.test(runtime)],
 ['Claim prepare rotates one-time credential and exposes operator boundary',/campaigns_rewards_rotate_reward_credential_v100/.test(runtime)&&/'can_process'=>\\$canProcess/.test(runtime)&&/claims\\.process/.test(runtime)],
 ['Tray claim delegates to canonical online three-factor engine',/campaigns_rewards_process_claim_v100/.test(runtime)&&/'online'=>true/.test(runtime)&&/'expected_merchant_id'=>\\$merchantId/.test(runtime)&&/campaigns_rewards_platform_assert_can_v100\\(\\$pdo,\\$merchantId,\\$actorUserId,'claims\\.process'\\)/.test(runtime)],
 ['API requires login chat access CSRF and no-store',/current_user/.test(api)&&/chat\\.access/.test(api)&&/hash_equals\\(csrf_token/.test(api)&&/Cache-Control: no-store/.test(api)],
 ['API exposes state send prepare_claim and claim actions',/action==='state'/.test(api)&&/action==='send'/.test(api)&&/action==='prepare_claim'/.test(api)&&/action==='claim'/.test(api)],
 ['Agent Chat loads Reward Tray CSS local QR and tray runtime',/reward-tray-v110\\.css/.test(chat)&&/reward-qr-v110\\.js/.test(chat)&&/reward-tray-v110\\.js/.test(chat)&&/api\\/reward-tray-v110\\.php/.test(chat)],
 ['Header tray contains INBOX SENT CLAIMED tabs with live count badges',/\\['inbox','sent','claimed'\\]/.test(tray)&&/data-reward-count/.test(tray)&&/reward-tray-tabs/.test(tray)],
 ['Inbox cards always contain SEND and CLAIM controls',/data-reward-send/.test(tray)&&/>SEND</.test(tray)&&/data-reward-claim/.test(tray)&&/>CLAIM</.test(tray)],
 ['SEND modal selects from returned CRM contacts',/Send to CRM Contact/.test(tray)&&/state\\.contacts/.test(tray)&&/contact_id/.test(tray)&&/idempotency_key/.test(tray)],
 ['CLAIM modal renders QR and Merchant Claim Code input',/rewardClaimQr/.test(tray)&&/Merchant Claim Code/.test(tray)&&/merchant_claim_code/.test(tray)&&/VP3RewardQR/.test(tray)],
 ['Non-operator holders cannot bypass merchant authorization',/form\\.elements\\.merchant_claim_code\\.disabled=!can/.test(tray)&&/not an authorized claim operator/.test(tray)],
 ['QR is generated locally without any network call or external service',/VP3RewardQR/.test(qr)&&!/fetch\\(|XMLHttpRequest|https?:\\/\\//.test(qr)&&/VERSION=5/.test(qr)&&/DATA_CODEWORDS=108/.test(qr)],
 ['QR Claim Terminal URL is made absolute client-side and credential stays in fragment',/new URL\\(claimCtx\\.claim_terminal_url/.test(tray)&&/qrUrl\\.hash='reward='/.test(tray)&&/encodeURIComponent/.test(tray)],
 ['Claim Terminal consumes reward fragment and clears address bar',/location\\.hash/.test(claim)&&/params\\.get\\('reward'\\)/.test(claim)&&/history\\.replaceState/.test(claim)&&/claimRewardCredential/.test(claim)],
 ['Campaigns is campaign-focused and routes Reward management to Rewards',/memberHeaderTitle='Campaigns'/.test(campaigns)&&/rewards\\.php/.test(campaigns)&&!/action==='reward_save'/.test(campaigns)&&!/action==='claim_code_create'/.test(campaigns)&&!/action==='make_good'/.test(campaigns)],
 ['Rewards is a separate Merchant workspace',/memberHeaderTitle='Rewards'/.test(rewards)&&/reward_save/.test(rewards)&&/reward_issue/.test(rewards)&&/claim_code_create/.test(rewards)&&/make_good/.test(rewards)&&/reconcile/.test(rewards)],
 ['Rewards manages reusable products inventory Campaign attachments and Core CRM issuance',/campaigns_rewards_save_reward_workspace_v110/.test(rewards)&&/inventory_on_hand/.test(rewards)&&/campaign_ids\\[\\]/.test(rewards)&&/CRM Contact/.test(rewards)],
 ['Navigation has separate Campaigns and Rewards destinations',/'campaigns','Campaigns',url\\('\\/campaigns\\.php'\\)/.test(nav)&&/'rewards','Rewards',url\\('\\/rewards\\.php'\\)/.test(nav)],
 ['Reward Wallet is absent from navigation and legacy route redirects to Chat Inbox',!/\\$add\\(\\$links,'reward_wallet','Reward Wallet'/.test(nav)&&/rewards-wallet\\.php'=>'chat'/.test(nav)&&/chat\\.php\\?reward_tray=inbox/.test(wallet)],
 ['Primary sidebar order contains Campaigns then Rewards then Team',sidebar.includes("'profile_commerce','campaigns','rewards','team'")&&/'campaigns'=>'Campaigns'/.test(sidebar)&&/'rewards'=>'Rewards'/.test(sidebar)],
 ['Campaigns and Rewards intentionally duplicate into bottom user menu',/array_reverse\\(\\['campaigns','rewards'\\]\\)/.test(sidebar)&&/dualFooterLink/.test(sidebar)],
 ['Reward Wallet is not a sidebar key',!/reward_wallet/.test(sidebar)],
 ['Fresh setup and upgrade run canonical platform schema migration',/campaigns_rewards_platform_ensure_schema_v100/.test(setup)&&/campaigns_rewards_platform_ensure_schema_v100/.test(upgrade)],
 ['Cognitive domain is integrated V1.10 and includes Reward Transfer authority',/implementation_status'=>'integrated-v1\\.10'/.test(domain)&&/'reward_transfers'/.test(domain)&&/'reward.sent'/.test(domain)],
 ['Release gate forbids cloned certificates external QR and claim bypass',/reward_transfer_clones_issuance'=>false/.test(release)&&/qr_generation_external_service'=>false/.test(release)&&/claim_engine_bypassed'=>false/.test(release)&&/claim_requires_reward_credential_merchant_code_and_authorized_operator'=>true/.test(release)],
 ['Reward Tray CSS targets header tabs canvas cert cards and modals',/reward-tray-tabs/.test(css)&&/reward-tray-canvas/.test(css)&&/reward-cert/.test(css)&&/reward-tray-modal/.test(css)],
 ['Documentation captures the split projection transfer and claim authority',/separate.*Campaign/i.test(docs)&&/Reward Tray/i.test(docs)&&/not cloned/i.test(docs)&&/URL fragment/i.test(docs)&&/three-factor/i.test(docs)],
];
for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log('Campaigns & Rewards V1.10 contract: '+checks.length+'/'+checks.length+' passed');
