import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const adapter=read('includes/profile-webmcp-rewards-v180.php');
const api=read('api/profile-webmcp-v100.php');
const router=read('includes/profile-webmcp-tool-router-v191.php');
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
assert.match(adapter,/vp3_profile_webmcp_action_prepare_v150/);
assert.match(adapter,/vp3_profile_webmcp_idempotency_hash_v150/);
assert.match(adapter,/SELECT GET_LOCK\(\?,5\)/);
assert.match(adapter,/reward_state_hash/);
assert.match(adapter,/campaigns_rewards_reward_holder_v110/);
assert.doesNotMatch(adapter,/campaigns_rewards_prepare_claim_v110\s*\(/);
assert.doesNotMatch(adapter,/campaigns_rewards_claim_from_tray_v110\s*\(/);
assert.doesNotMatch(adapter,/campaigns_rewards_process_claim_v100\s*\(/);
assert.match(adapter,/reward-inbox\.php\?claim=/);
assert.match(adapter,/credential_exposed'\s*=>\s*false/);
assert.match(adapter,/reward_redeemed'\s*=>\s*false/);
assert.match(adapter,/different WebMCP session/);
assert.match(adapter,/still in progress/);

assert.match(router,/vp3_profile_webmcp_reward_claim_prepare_v182/);
assert.match(router,/vp3_profile_webmcp_reward_claim_confirm_v182/);
assert.match(router,/webmcp_reward_claim_prepared/);
assert.match(router,/webmcp_reward_claim_handoff_confirmed/);
assert.match(layer,/vp3\.rewards\.claim\.prepare/);
assert.match(layer,/vp3\.rewards\.claim\.confirm/);
assert.match(layer,/vp3_profile_webmcp_actions_schema_ready_v150/);

assert.match(trayPage,/autoClaimPublicId/);
assert.match(trayJs,/cfg\.autoClaimPublicId/);
assert.match(trayJs,/prepareClaim\(Number\(target\.id\)\)/);
assert.match(trayJs,/history\.replaceState/);

assert.doesNotMatch(externalLayer,/vp3\.rewards\.claim\.(?:prepare|confirm)/);
assert.doesNotMatch(externalApi,/vp3_profile_webmcp_reward_claim_/);
assert.doesNotMatch(externalRuntime,/vp3\.rewards\.claim\.(?:prepare|confirm)/);

assert.match(workflow,/profile-webmcp-rewards-v182-contract\.mjs/);
assert.match(workflow,/profile-webmcp-rewards-v182-runtime\.mjs/);
assert.match(workflow,/profile-webmcp-rewards-v182\.php/);
assert.match(recovery,/profile-webmcp-rewards-v182-contract\.mjs/);
assert.match(recovery,/profile-webmcp-rewards-v182-runtime\.mjs/);
assert.match(recovery,/profile-webmcp-rewards-v182\.php/);
console.log('PROFILE_WEBMCP_REWARDS_V182_CONTRACT=PASS');
