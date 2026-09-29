import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const adapter=read('includes/profile-webmcp-rewards-v180.php');
const layer=read('includes/profile-webmcp-v100.php');
const resolver=read('includes/profile-webmcp-capability-resolver-v190.php');
const nativeApi=read('api/profile-webmcp-v100.php');
const nativeRuntime=read('profile-webmcp-v100.js');
const externalLayer=read('includes/profile-webmcp-external-v120.php');
const externalApi=read('api/profile-webmcp-external-v120.php');
const externalRuntime=read('profile-webmcp-external-v120.js');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(adapter,/vp3\.rewards\.wallet\.get/);
assert.match(adapter,/campaigns_rewards_reward_tray_v110\(\$pdo,\$viewerId\)/,'wallet must use canonical Reward Tray projection');
assert.match(adapter,/\$viewerId<1.*Sign in to view your Reward Wallet/s,'wallet must require authenticated viewer');
assert.doesNotMatch(adapter,/campaigns_rewards_prepare_claim_v110|campaigns_rewards_claim_from_tray_v110|campaigns_rewards_process_claim_v100/,'Reward Wallet/claim surfaces must never rotate or redeem credentials directly');
assert.doesNotMatch(adapter,/['"](?:credential|credential_hash|credential_last4|merchant_claim_code|recipient_email|recipient_name|crm_contact_id|inventory_on_hand|inventory_reserved)['"]\s*=>/,'safe wallet projection must not emit credential, recipient, CRM, or inventory fields');
assert.match(adapter,/public_id/);
assert.match(adapter,/claimable/);
assert.match(adapter,/counts/);

assert.match(resolver,/\$rewards=\$viewerId>0&&function_exists\('campaigns_rewards_reward_tray_v110'\)/,'Rewards capability must be authenticated-viewer gated');
assert.match(layer,/vp3_profile_webmcp_rewards_tool_catalog_v180/);
assert.match(nativeApi,/profile-webmcp-rewards-v180\.php/);
assert.match(nativeApi,/vp3_profile_webmcp_rewards_wallet_v180\(\$pdo,\$viewer\)/);
assert.match(nativeRuntime,/vp3\.rewards\.wallet\.get/);
assert.match(nativeRuntime,/credentials:'same-origin'/);

assert.doesNotMatch(externalLayer,/vp3\.rewards\.wallet\.get/,'connected-site manifest must not advertise personal Reward Wallet');
assert.doesNotMatch(externalApi,/vp3_profile_webmcp_rewards_wallet_v180/,'connected-site gateway must not dispatch personal Reward Wallet');
assert.doesNotMatch(externalRuntime,/vp3\.rewards\.wallet\.get/,'connected-site browser runtime must not contain personal Reward Wallet');
assert.match(externalLayer,/vp3_profile_webmcp_capabilities_v100\(\$pdo,\$profile,null\)/,'external capability computation must remain anonymous');

assert.match(workflow,/profile-webmcp-rewards-v180-contract\.mjs/);
assert.match(workflow,/profile-webmcp-rewards-v180-runtime\.mjs/);
assert.match(workflow,/profile-webmcp-rewards-v180\.php/);
assert.match(recovery,/profile-webmcp-rewards-v180-contract\.mjs/);
assert.match(recovery,/profile-webmcp-rewards-v180-runtime\.mjs/);
assert.match(recovery,/profile-webmcp-rewards-v180\.php/);
console.log('PROFILE_WEBMCP_REWARDS_V180_CONTRACT=PASS');
