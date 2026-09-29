import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const adapter=read('includes/profile-webmcp-rewards-v180.php');
const api=read('api/profile-webmcp-v100.php');
const layer=read('includes/profile-webmcp-v100.php');
const runtime=read('profile-webmcp-v100.js');
const trayPage=read('includes/reward-tray-page-v113.php');
const trayJs=read('reward-tray-v110.js');
const externalLayer=read('includes/profile-webmcp-external-v120.php');
const externalApi=read('api/profile-webmcp-external-v120.php');
const externalRuntime=read('profile-webmcp-external-v120.js');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

for(const name of ['vp3.rewards.claim.prepare','vp3.rewards.claim.confirm']){
  const re=new RegExp(name.replaceAll('.','\\.'));
  assert.match(adapter,re);
  assert.match(runtime,re);
}
assert.match(adapter,/vp3_profile_webmcp_action_prepare_v150/,'prepare must use durable action ledger');
assert.match(adapter,/vp3_profile_webmcp_idempotency_hash_v150/,'confirm must use durable idempotency');
assert.match(adapter,/SELECT GET_LOCK\(\?,5\)/,'same-key confirmations must serialize');
assert.match(adapter,/reward_state_hash/,'prepared handoff must freeze canonical Reward state');
assert.match(adapter,/campaigns_rewards_reward_holder_v110/,'Reward holder authority must remain canonical');
assert.doesNotMatch(adapter,/campaigns_rewards_prepare_claim_v110\s*\(/,'WebMCP must not rotate/reveal Reward credential');
assert.doesNotMatch(adapter,/campaigns_rewards_claim_from_tray_v110\s*\(/,'WebMCP must not redeem from tray');
assert.doesNotMatch(adapter,/campaigns_rewards_process_claim_v100\s*\(/,'WebMCP must not invoke merchant redemption authority');
assert.doesNotMatch(adapter,/merchant_claim_code\s*=>|credential\s*=>/,'WebMCP result must not emit merchant claim code or credential');
assert.match(adapter,/reward-inbox\.php\?claim=/,'confirm must hand off to existing authenticated Reward Inbox');
assert.match(adapter,/credential_exposed'\s*=>\s*false/);
assert.match(adapter,/reward_redeemed'\s*=>\s*false/);
assert.match(adapter,/different WebMCP session/,'confirm must remain session-bound');
assert.match(adapter,/still in progress/,'same-key in-flight action must not replay as success');

assert.match(api,/vp3_profile_webmcp_reward_claim_prepare_v181/);
assert.match(api,/vp3_profile_webmcp_reward_claim_confirm_v181/);
assert.match(api,/webmcp_reward_claim_prepared/);
assert.match(api,/webmcp_reward_claim_handoff_confirmed/);
assert.match(layer,/vp3\.rewards\.claim\.prepare/);
assert.match(layer,/vp3\.rewards\.claim\.confirm/);
assert.match(layer,/vp3_profile_webmcp_actions_schema_ready_v150/,'Reward mutation tools must disappear when ledger is unavailable');

assert.match(trayPage,/autoClaimPublicId/);
assert.match(trayJs,/cfg\.autoClaimPublicId/);
assert.match(trayJs,/prepareClaim\(Number\(target\.id\)\)/,'handoff must use existing Reward claim UI');
assert.match(trayJs,/history\.replaceState/,'handoff query should be removed from address bar before credential generation');

assert.doesNotMatch(externalLayer,/vp3\.rewards\.claim\.(?:prepare|confirm)/,'connected sites must not advertise personal Reward claim');
assert.doesNotMatch(externalApi,/vp3_profile_webmcp_reward_claim_/,'connected-site gateway must not dispatch personal Reward claim');
assert.doesNotMatch(externalRuntime,/vp3\.rewards\.claim\.(?:prepare|confirm)/,'connected-site runtime must not contain personal Reward claim');

assert.match(workflow,/profile-webmcp-rewards-v181-contract\.mjs/);
assert.match(workflow,/profile-webmcp-rewards-v181-runtime\.mjs/);
assert.match(workflow,/profile-webmcp-rewards-v181\.php/);
assert.match(recovery,/profile-webmcp-rewards-v181-contract\.mjs/);
assert.match(recovery,/profile-webmcp-rewards-v181-runtime\.mjs/);
assert.match(recovery,/profile-webmcp-rewards-v181\.php/);
console.log('PROFILE_WEBMCP_REWARDS_V181_CONTRACT=PASS');
