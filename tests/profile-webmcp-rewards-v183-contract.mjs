import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const adapter=read('includes/profile-webmcp-rewards-v180.php');
const api=read('api/profile-webmcp-v100.php');
const layer=read('includes/profile-webmcp-v100.php');
const runtime=read('profile-webmcp-v100.js');
const externalLayer=read('includes/profile-webmcp-external-v120.php');
const externalApi=read('api/profile-webmcp-external-v120.php');
const externalRuntime=read('profile-webmcp-external-v120.js');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

for(const name of ['vp3.rewards.transfer.contacts.list','vp3.rewards.transfer.prepare','vp3.rewards.transfer.confirm']){
  const re=new RegExp(name.replaceAll('.','\\.'));
  assert.match(adapter,re);
  assert.match(runtime,re);
}
assert.match(adapter,/campaigns_rewards_send_contacts_v110/,'recipient list must derive from canonical sender contact authority');
assert.match(adapter,/public_id/,'recipient selection must use opaque contact public IDs');
assert.doesNotMatch(adapter,/['"](?:contact_id|recipient_contact_id|recipient_email)['"]\s*=>\s*\[/,'WebMCP tool schemas must not accept raw CRM IDs or free-form recipient email');
assert.match(adapter,/crm_contacts WHERE public_id=\? AND owner_user_id=\?/,'recipient resolution must bind public ID to signed-in viewer');
assert.match(adapter,/campaigns_rewards_transfer_reward_v110/,'confirm must delegate ownership change to canonical Reward transfer authority');
assert.doesNotMatch(adapter,/UPDATE reward_issuances SET recipient_contact_id/,'WebMCP must not directly mutate Reward holder');
assert.doesNotMatch(adapter,/INSERT INTO reward_transfers/,'WebMCP must not directly create transfer records');
assert.match(adapter,/non-transferable/,'prepare must enforce canonical transferability');
assert.match(adapter,/reward_state_hash/,'prepared transfer must bind Reward state');
assert.match(adapter,/recipient_state_hash/,'prepared transfer must bind recipient state');
assert.match(adapter,/vp3_profile_webmcp_action_prepare_v150/);
assert.match(adapter,/vp3_profile_webmcp_action_by_idempotency_v150/);
assert.match(adapter,/SELECT GET_LOCK\(\?,5\)/);
assert.match(adapter,/webmcp:/,'canonical transfer idempotency must be namespaced to the prepared WebMCP intent');
assert.doesNotMatch(adapter,/newCredential|credential_hash|credential_last4/,'WebMCP adapter must never manipulate transfer credential internals');

assert.match(api,/vp3_profile_webmcp_reward_transfer_contacts_v183/);
assert.match(api,/vp3_profile_webmcp_reward_transfer_prepare_v183/);
assert.match(api,/vp3_profile_webmcp_reward_transfer_confirm_v183/);
assert.match(api,/webmcp_reward_transfer_prepared/);
assert.match(api,/webmcp_reward_transfer_completed/);
assert.match(layer,/vp3\.rewards\.transfer\.prepare/);
assert.match(layer,/vp3\.rewards\.transfer\.confirm/);
assert.match(layer,/vp3_profile_webmcp_actions_schema_ready_v150/);

assert.doesNotMatch(externalLayer,/vp3\.rewards\.transfer/,'connected-site manifest must not advertise personal Reward transfer');
assert.doesNotMatch(externalApi,/vp3_profile_webmcp_reward_transfer_/,'connected-site gateway must not dispatch personal Reward transfer');
assert.doesNotMatch(externalRuntime,/vp3\.rewards\.transfer/,'connected-site runtime must not contain personal Reward transfer');

assert.match(workflow,/profile-webmcp-rewards-v183-contract\.mjs/);
assert.match(workflow,/profile-webmcp-rewards-v183-runtime\.mjs/);
assert.match(workflow,/profile-webmcp-rewards-v183\.php/);
assert.match(recovery,/profile-webmcp-rewards-v183-contract\.mjs/);
assert.match(recovery,/profile-webmcp-rewards-v183-runtime\.mjs/);
assert.match(recovery,/profile-webmcp-rewards-v183\.php/);
console.log('PROFILE_WEBMCP_REWARDS_V183_CONTRACT=PASS');
