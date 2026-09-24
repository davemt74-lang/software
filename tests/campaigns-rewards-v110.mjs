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
const inboxPage=read('reward-inbox.php');
const sentPage=read('reward-sent.php');
const claimedPage=read('reward-claimed.php');
const trayPage=read('includes/reward-tray-page-v113.php');
const memberHeader=read('includes/member-header.php');
const claim=read('campaign-claim.php');
const chat=read('chat.php');
const api=read('api/reward-tray-v110.php');
const tray=read('reward-tray-v110.js');
const qr=read('reward-qr-v110.js');
const css=read('reward-tray-v110.css');
const docs=read('docs/CAMPAIGNS_REWARDS_V110.md');
const setup=read('setup.php');
const upgrade=read('upgrade.php');
const transferFn=(runtime.match(/function campaigns_rewards_transfer_reward_v110[\s\S]*?function campaigns_rewards_prepare_claim_v110/)||[''])[0];

const checks=[
 ['Reward Transfer is durable canonical audit state',schema.includes('CREATE TABLE IF NOT EXISTS reward_transfers')&&schema.includes('reward_issuance_id')&&schema.includes('from_contact_id')&&schema.includes('to_contact_id')&&schema.includes('idempotency_key')],
 ['platform readiness requires Reward Transfer schema',schema.includes("'reward_issuances','reward_transfers','reward_claims'")],
 ['SEND locks current holder and never inserts a second issuance',runtime.includes('campaigns_rewards_reward_holder_v110($pdo,$issuanceId,$senderUserId,true)')&&runtime.includes('UPDATE reward_issuances SET recipient_contact_id')&&!transferFn.includes('INSERT INTO reward_issuances')],
 ['SEND uses sender Core CRM selection then Merchant CRM identity',runtime.includes('crm_v180_contact_for_owner($pdo,$senderUserId,$sourceContactId)')&&runtime.includes('campaigns_rewards_resolve_contact_v100')&&runtime.includes('campaigns_rewards_ensure_merchant_relationship_v100')],
 ['SEND invalidates previously revealed credential',runtime.includes('$newCredential=campaigns_rewards_secret_v100')&&runtime.includes('credential_hash=?,credential_last4=?')],
 ['SEND records transfer audit and reward.sent cognition',runtime.includes('INSERT INTO reward_transfers')&&runtime.includes("'reward.sent'")&&runtime.includes('campaigns_rewards_activity_event_v100')],
 ['Reward Tray has Inbox Sent Claimed projections and counts',runtime.includes("'inbox'=>$inbox")&&runtime.includes("'sent'=>$sent")&&runtime.includes("'claimed'=>$claimed")&&runtime.includes("'inbox'=>count($inbox)")],
 ['Claim prepare rotates one-time credential and exposes operator boundary',runtime.includes('campaigns_rewards_rotate_reward_credential_v100')&&runtime.includes("'can_process'=>$canProcess")&&runtime.includes("'claims.process'")],
 ['Tray claim delegates to canonical online three-factor engine',runtime.includes('campaigns_rewards_process_claim_v100')&&runtime.includes("'online'=>true")&&runtime.includes("'expected_merchant_id'=>$merchantId")&&runtime.includes("campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'claims.process')")],
 ['API requires login chat access CSRF and no-store',api.includes('current_user()')&&api.includes("'chat.access'")&&api.includes('hash_equals(csrf_token()')&&api.includes('Cache-Control: no-store')],
 ['API exposes state send prepare_claim and claim actions',api.includes("$action==='state'")&&api.includes("$action==='send'")&&api.includes("$action==='prepare_claim'")&&api.includes("$action==='claim'")],
 ['Agent Chat loads V1.13 Reward Tray CSS local QR and routed tabs',chat.includes('reward-tray-v110.css?v=113')&&chat.includes('reward-qr-v110.js?v=113')&&chat.includes('reward-tray-v110.js?v=113')&&chat.includes('/reward-inbox.php')&&chat.includes('/reward-sent.php')&&chat.includes('/reward-claimed.php')],
 ['Header tray contains routed INBOX SENT CLAIMED links with live count badges',tray.includes("['inbox','sent','claimed']")&&tray.includes('data-reward-count')&&tray.includes('reward-tray-tabs')&&tray.includes("href=\"'+esc(routes[key])+'\"")],
 ['Reward certificate tabs stay out of the left sidebar',!sidebar.includes('reward-sidebar-subnav')&&!sidebar.includes('data-reward-tray-tab="<?= e($rewardBucket) ?>"')],
 ['Header tabs insert before the flexible spacer so they float left in the right-column header',tray.includes("const actions=qs('.chat-topbar-actions',top),spacer=qs('.chat-topbar-spacer',top)")&&tray.includes('top.insertBefore(nav,spacer||actions||top.firstChild)')],
 ['Reward header counts update every matching badge hook',tray.includes("document.querySelectorAll('[data-reward-count=\"'+k+'\"]')")],
 ['Reward tabs remain visible while schema upgrade is pending',chat.includes("$rewardTrayRuntime = function_exists('campaigns_rewards_v110_schema_ready')")&&!chat.includes("campaigns_rewards_v110_schema_ready(db())")&&trayPage.includes("$schemaReady=(bool)$pdo")],
 ['Mobile uses a true second-row Reward subheader with touch-sized routed tabs',css.includes('.reward-tray-subheader{display:none')&&css.includes('body.reward-tray-ready .chat-main{grid-template-rows:58px auto minmax(0,1fr) auto}')&&css.includes('.reward-tray-subheader{display:flex')&&css.includes('min-height:46px')&&css.includes('touch-action:manipulation')],
 ['Inbox cards contain SEND and CLAIM controls',tray.includes('data-reward-send')&&tray.includes('>SEND<')&&tray.includes('data-reward-claim')&&tray.includes('>CLAIM<')],
 ['SEND modal selects from returned CRM contacts',tray.includes('Send to CRM Contact')&&tray.includes('state.contacts')&&tray.includes('contact_id')&&tray.includes('idempotency_key')],
 ['CLAIM modal renders QR and Merchant Claim Code input',tray.includes('rewardClaimQr')&&tray.includes('Merchant Claim Code')&&tray.includes('merchant_claim_code')&&tray.includes('VP3RewardQR')],
 ['Non-operator holders cannot bypass merchant authorization',tray.includes('form.elements.merchant_claim_code.disabled=!can')&&tray.includes('not an authorized claim operator')],
 ['QR is generated locally without network calls',qr.includes('VP3RewardQR')&&!qr.includes('fetch(')&&!qr.includes('XMLHttpRequest')&&!qr.includes('new Image(')&&!qr.includes('script src=')&&qr.includes('VERSION=5')&&qr.includes('DATA_CODEWORDS=108')],
 ['QR URL becomes absolute client-side and secret stays in fragment',tray.includes('new URL(claimCtx.claim_terminal_url')&&tray.includes("qrUrl.hash='reward='")&&tray.includes('encodeURIComponent')],
 ['Claim Terminal consumes reward fragment and clears address bar',claim.includes('location.hash')&&claim.includes("params.get('reward')")&&claim.includes('history.replaceState')&&claim.includes('claimRewardCredential')],
 ['Campaigns is campaign-focused and routes Reward management to Rewards',campaigns.includes("$memberHeaderTitle='Campaigns'")&&campaigns.includes('/rewards.php')&&!campaigns.includes("$action==='reward_save'")&&!campaigns.includes("$action==='claim_code_create'")&&!campaigns.includes("$action==='make_good'")],
 ['Rewards is a separate Merchant workspace',rewards.includes("$memberHeaderTitle='Rewards'")&&rewards.includes("$action==='reward_save'")&&rewards.includes("$action==='reward_issue'")&&rewards.includes("$action==='claim_code_create'")&&rewards.includes("$action==='make_good'")&&rewards.includes("$action==='reconcile'")],
 ['Rewards manages reusable products inventory Campaign attachments and CRM issuance',rewards.includes('campaigns_rewards_save_reward_workspace_v110')&&rewards.includes('inventory_on_hand')&&rewards.includes('campaign_ids[]')&&rewards.includes('CRM Contact')],
 ['Navigation has separate Campaigns and Rewards destinations',nav.includes("$add($links,'campaigns','Campaigns',url('/campaigns.php'),'agent')")&&nav.includes("$add($links,'rewards','Rewards',url('/rewards.php'),'agent')")],
 ['Reward Wallet is absent from nav and legacy route redirects to dedicated Inbox',!nav.includes("$add($links,'reward_wallet','Reward Wallet'")&&nav.includes("'rewards-wallet.php'=>'chat'")&&wallet.includes('/reward-inbox.php')],
 ['Dedicated Inbox Sent and Claimed routes each load the shared Reward page shell',inboxPage.includes("$rewardTrayPageBucket='inbox'")&&sentPage.includes("$rewardTrayPageBucket='sent'")&&claimedPage.includes("$rewardTrayPageBucket='claimed'")&&trayPage.includes("require __DIR__.'/workspace-sidebar-v82.php'")],
 ['Each Reward tab is a real page link and SEND/CLAIM navigate to the resulting page',trayPage.includes("href=\"<?= e($routes[$key]) ?>\"")&&tray.includes('location.assign(routes.sent)')&&tray.includes('location.assign(routes.claimed)')],
 ['Shared member header supports left-side Reward tabs without moving user actions',memberHeader.includes('$memberHeaderLeadingHtml')&&memberHeader.includes('<?= $memberHeaderLeadingHtml ?>')&&memberHeader.indexOf('<?= $memberHeaderLeadingHtml ?>')<memberHeader.indexOf('chat-topbar-title member-header-title')],
 ['Reward routed pages render tabs/actions only in the top header',trayPage.includes('$memberHeaderShowTitle=false')&&trayPage.includes("$memberHeaderTitle=''")&&trayPage.includes("$memberHeaderSubtitle=''")],
 ['Reward routed pages omit redundant page title and description block',!trayPage.includes('class="reward-tray-head"')&&!trayPage.includes('id="rewardTrayTitle"')&&!trayPage.includes('id="rewardTraySubtitle"')&&!trayPage.includes('Your claimed certificate history.')],
 ['Enabled Campaigns plugin stays visible in navigation even before schema readiness',nav.includes("$campaignNavVisible=!empty($campaignState['enabled'])")&&nav.includes("$add($links,'campaigns','Campaigns'")&&nav.includes("$add($links,'rewards','Rewards'")],
 ['Primary sidebar order contains Campaigns then Rewards then Team',sidebar.includes("'profile_commerce','campaigns','rewards','team'")&&sidebar.includes("'campaigns'=>'Campaigns'")&&sidebar.includes("'rewards'=>'Rewards'")],
 ['Campaigns and Rewards duplicate into bottom user menu',sidebar.includes("array_reverse(['campaigns','rewards'])")&&sidebar.includes('$dualFooterLink')],
 ['Reward Wallet is not a sidebar key',!sidebar.includes('reward_wallet')],
 ['Fresh setup and upgrade run canonical platform schema migration',setup.includes('campaigns_rewards_platform_ensure_schema_v100')&&upgrade.includes('campaigns_rewards_platform_ensure_schema_v100')],
 ['Cognitive domain remains integrated beyond V1.10 and includes Reward Transfer authority',/implementation_status'=>'integrated-v1\.(?:10|18|19|20|21|22|23|24|25)'/.test(domain)&&domain.includes("'reward_transfers'")&&domain.includes("'reward.sent'")],
 ['Release gate forbids cloned certs external QR and claim bypass',release.includes("'reward_transfer_clones_issuance'=>false")&&release.includes("'qr_generation_external_service'=>false")&&release.includes("'claim_engine_bypassed'=>false")&&release.includes("'claim_requires_reward_credential_merchant_code_and_authorized_operator'=>true")],
 ['Reward Tray CSS targets tabs canvas cert cards and modals',css.includes('.reward-tray-tabs')&&css.includes('.reward-tray-canvas')&&css.includes('.reward-cert')&&css.includes('.reward-tray-modal')],
 ['Documentation captures split projection transfer and claim authority',docs.includes('separates merchant Campaign operations')&&docs.includes('Reward Tray')&&docs.includes('certificate is not cloned')&&docs.includes('URL fragment')&&docs.includes('three-factor')],
];
for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log('Campaigns & Rewards V1.10 contract: '+checks.length+'/'+checks.length+' passed');
