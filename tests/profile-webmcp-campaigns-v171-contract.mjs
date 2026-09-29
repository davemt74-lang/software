import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const adapter=read('includes/profile-webmcp-campaigns-v170.php');
const catalog=read('includes/profile-webmcp-v100.php');
const nativeApi=read('api/profile-webmcp-v100.php');
const router=read('includes/profile-webmcp-tool-router-v191.php');
const externalLayer=read('includes/profile-webmcp-external-v120.php');
const externalApi=read('api/profile-webmcp-external-v120.php');
const nativeRuntime=read('profile-webmcp-v100.js');
const externalRuntime=read('profile-webmcp-external-v120.js');
const actions=read('includes/profile-webmcp-actions-v150.php');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

for(const name of ['vp3.campaign.participation.prepare','vp3.campaign.participation.confirm']){
  const re=new RegExp(name.replaceAll('.','\\.'));
  assert.match(adapter,re,'server catalog '+name);
  assert.match(nativeRuntime,re,'native runtime '+name);
  assert.match(externalRuntime,re,'external runtime '+name);
}
assert.match(adapter,/vp3_profile_webmcp_action_prepare_v150\([^)]*campaign\.participate/s,'prepare must use durable Section 6 action ledger');
assert.match(adapter,/vp3_profile_webmcp_idempotency_hash_v150/,'confirm must use durable idempotency');
assert.match(adapter,/SELECT GET_LOCK\(\?,5\)/,'same-key confirmations must serialize');
assert.match(adapter,/vp3_profile_webmcp_action_by_idempotency_v150/,'confirm must support durable replay');
assert.match(adapter,/vp3_profile_webmcp_b64url_encode_v140\(\$sig\).*\$sigEncoded/s,'noncanonical signatures must fail closed');
assert.match(adapter,/campaign_state_hash/,'prepare must freeze Campaign/Reward state');
assert.match(adapter,/vp3_profile_webmcp_campaign_state_hash_v170\(\$pdo,\$campaign,\$reward\)/,'confirm must revalidate canonical state');
assert.match(adapter,/campaigns_rewards_public_validate_v118/,'prepare must use canonical Campaign validation');
assert.match(adapter,/campaigns_rewards_public_participate_v118/,'confirm must delegate all mutation to canonical Campaigns & Rewards');
assert.doesNotMatch(adapter,/campaigns_rewards_public_enroll_v100\s*\(/,'WebMCP must not directly create Campaign enrollments');
assert.doesNotMatch(adapter,/campaigns_rewards_resolve_contact_v100\s*\(/,'WebMCP must not directly create/update CRM contacts');
assert.doesNotMatch(adapter,/campaigns_rewards_issue_reward_v100\s*\(/,'WebMCP must not directly issue Rewards');
assert.doesNotMatch(adapter,/campaigns_rewards_journey_enqueue_latest_v118\s*\(/,'WebMCP must not directly enqueue Campaign Journeys');
assert.match(adapter,/webmcp-v170:'\.\$intentId/,'canonical participation request token must be stable per prepared intent');
assert.match(adapter,/m\.owner_user_id=\?/,'idempotent replay must stay bound to exact Profile owner');
assert.doesNotMatch(adapter,/\$canonical\[['"]contact['"]\]|\$canonical\[['"]issued['"]\]/,'raw canonical CRM or Reward issuance result must not be returned');
assert.match(adapter,/This Referral Campaign requires an explicit referral link or code/,'WebMCP referral campaigns must not use ambient query-string referral authority');
assert.match(adapter,/\['http','https'\]/,'proof URLs must be limited to HTTP(S)');

assert.match(catalog,/vp3\.campaign\.participation\.prepare/);
assert.match(catalog,/vp3\.campaign\.participation\.confirm/);
assert.match(catalog,/vp3_profile_webmcp_actions_schema_ready_v150/,'mutation tools must disappear when durable ledger is unavailable');
assert.match(nativeApi,/vp3_profile_webmcp_campaign_prepare_v170/);
assert.match(router,/vp3_profile_webmcp_campaign_confirm_v170/);
assert.match(nativeApi,/webmcp_campaign_prepared/);
assert.match(nativeApi,/webmcp_campaign_participation_completed/);
assert.match(externalApi,/vp3_profile_webmcp_dispatch_v191/);
assert.match(externalLayer,/transactional_actions'\s*=>\s*\$schedulingEnabled\|\|\$commerceEnabled\|\|\$campaignsEnabled/);
assert.match(externalLayer,/read_only'\s*=>\s*!\$chatEnabled&&!\$schedulingEnabled&&!\$commerceEnabled&&!\$campaignsEnabled/);
assert.match(externalRuntime,/credentials:'omit'/);
assert.doesNotMatch(externalRuntime,/Authorization|document\.cookie|localStorage|sessionStorage/,'connected-site Campaign mutation transport remains bearer/cookie/storage free');

assert.match(actions,/result_json/,'durable action ledger remains canonical replay authority');
assert.match(workflow,/profile-webmcp-campaigns-v171-contract\.mjs/);
assert.match(workflow,/profile-webmcp-campaigns-v171-runtime\.mjs/);
assert.match(workflow,/profile-webmcp-campaigns-v171\.php/);
assert.match(recovery,/profile-webmcp-campaigns-v171-contract\.mjs/);
assert.match(recovery,/profile-webmcp-campaigns-v171-runtime\.mjs/);
assert.match(recovery,/profile-webmcp-campaigns-v171\.php/);
console.log('PROFILE_WEBMCP_CAMPAIGNS_V171_CONTRACT=PASS');
