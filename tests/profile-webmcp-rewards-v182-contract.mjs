import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const adapter=read('includes/profile-webmcp-rewards-v180.php');
const layer=read('includes/profile-webmcp-v100.php');
const api=read('api/profile-webmcp-v100.php');
const runtime=read('profile-webmcp-v100.js');
const extLayer=read('includes/profile-webmcp-external-v120.php');
const extApi=read('api/profile-webmcp-external-v120.php');
const extRuntime=read('profile-webmcp-external-v120.js');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

for(const name of ['vp3.rewards.transfer.contacts.list','vp3.reward.transfer.prepare','vp3.reward.transfer.confirm']){
  const re=new RegExp(name.replaceAll('.','\\.'));
  assert.match(adapter,re,'server catalog '+name);
  assert.match(runtime,re,'native runtime '+name);
}
assert.match(layer,/vp3\.reward\.transfer\.prepare/);
assert.match(layer,/vp3\.reward\.transfer\.confirm/);
assert.match(layer,/vp3_profile_webmcp_actions_schema_ready_v150/,'consequential Reward transfer must be hidden without durable ledger');

assert.match(adapter,/campaigns_rewards_send_contacts_v110/,'contacts must come from canonical CRM-backed transfer selector');
assert.match(adapter,/campaigns_rewards_reward_holder_v110/,'holder authority must remain canonical');
assert.match(adapter,/vp3_profile_webmcp_action_prepare_v150/,'prepare must use durable action ledger');
assert.match(adapter,/SELECT GET_LOCK\(\?,5\)/,'confirm must serialize same idempotency key');
assert.match(adapter,/vp3_profile_webmcp_action_by_idempotency_v150/,'confirm must support durable replay');
assert.match(adapter,/vp3_profile_webmcp_reward_transfer_state_hash_v182/,'Reward and contact state must be frozen');
assert.match(adapter,/terms_hash/,'Reward terms must be state-bound');
assert.match(adapter,/campaigns_rewards_transfer_reward_v110\(/,'confirm must delegate mutation to canonical transfer authority');
assert.match(adapter,/webmcp-v182:/,'canonical transfer must receive deterministic WebMCP idempotency key');
assert.doesNotMatch(adapter,/UPDATE\s+reward_issuances|INSERT\s+INTO\s+reward_transfers|campaigns_rewards_rotate_reward_credential_v100\s*\(/i,'WebMCP must not directly mutate holder/credential/transfer authority');
assert.doesNotMatch(adapter,/['"]issuance_id['"]\s*=>\s*\(int\)\$holder\['id'\]/,'client-visible prepared intent must not expose internal issuance ID');
assert.match(adapter,/vp3_profile_webmcp_reward_raw_holder_v182\(\$pdo,\$viewerId,\(string\)\$intent\['reward_public_id'\]\)/,'confirm must resolve internal issuance server-side from opaque public ID');
assert.match(adapter,/owner_user_id'\s*=>\s*\$viewerId/,'action authority must bind to signed-in viewer, not public Profile owner');
assert.match(adapter,/native_profile/);
assert.match(adapter,/vp3_profile_webmcp_native_signing_secret_v150\(\$viewerId\)/);

assert.match(api,/vp3_profile_webmcp_reward_transfer_prepare_v182/);
assert.match(api,/vp3_profile_webmcp_reward_transfer_confirm_v182/);
assert.match(api,/empty\(\$result\['idempotent_replay'\]\).*webmcp_reward_transfer_completed/s,'completion telemetry must suppress idempotent replay');
assert.doesNotMatch(extLayer,/vp3\.reward\.transfer\.(?:prepare|confirm)/);
assert.doesNotMatch(extApi,/vp3_profile_webmcp_reward_transfer_(?:prepare|confirm)_v182/);
assert.doesNotMatch(extRuntime,/vp3\.reward\.transfer\.(?:prepare|confirm)/);

assert.match(workflow,/profile-webmcp-rewards-v182-contract\.mjs/);
assert.match(workflow,/profile-webmcp-rewards-v182-runtime\.mjs/);
assert.match(workflow,/profile-webmcp-rewards-v182\.php/);
assert.match(recovery,/profile-webmcp-rewards-v182-contract\.mjs/);
assert.match(recovery,/profile-webmcp-rewards-v182-runtime\.mjs/);
assert.match(recovery,/profile-webmcp-rewards-v182\.php/);
console.log('PROFILE_WEBMCP_REWARDS_V182_CONTRACT=PASS');
