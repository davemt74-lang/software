import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const router=read('includes/profile-webmcp-tool-router-v191.php');
const native=read('api/profile-webmcp-v100.php');
const external=read('api/profile-webmcp-external-v120.php');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(router,/VP3_PROFILE_WEBMCP_TOOL_ROUTER_V191/);
assert.match(router,/function vp3_profile_webmcp_dispatch_v191/);
assert.match(native,/profile-webmcp-tool-router-v191\.php/);
assert.match(external,/profile-webmcp-tool-router-v191\.php/);
assert.match(native,/vp3_profile_webmcp_dispatch_v191/);
assert.match(external,/vp3_profile_webmcp_dispatch_v191/);

for(const token of [
  'vp3_profile_agent_public_message_service_v110',
  'vp3_profile_webmcp_scheduling_confirm_v150',
  'vp3_profile_webmcp_commerce_checkout_confirm_v160',
  'vp3_profile_webmcp_campaign_confirm_v170',
  'vp3_profile_webmcp_rewards_wallet_v180',
  'vp3_profile_webmcp_reward_claim_confirm_v182',
  'vp3_profile_webmcp_reward_transfer_confirm_v183',
  'vp3_profile_webmcp_loyalty_status_v184'
]) assert.match(router,new RegExp(token));

for(const source of [native,external]){
  assert.doesNotMatch(source,/vp3_profile_webmcp_scheduling_confirm_v150/);
  assert.doesNotMatch(source,/vp3_profile_webmcp_commerce_checkout_confirm_v160/);
  assert.doesNotMatch(source,/vp3_profile_webmcp_campaign_confirm_v170/);
}
assert.doesNotMatch(native,/vp3_profile_webmcp_reward_claim_confirm_v182/);
assert.doesNotMatch(native,/vp3_profile_webmcp_reward_transfer_confirm_v183/);

assert.match(router,/if\(\$surface!=='native_profile'\)[\s\S]*CAPABILITY_UNAVAILABLE/,'personal Reward/Loyalty tools must fail closed off native Profile');
assert.match(router,/\$surface==='native_profile'\?\$proof:''/,'native signed proof must bind transactional contexts');
assert.match(router,/\$surface==='external_site'\?\$property:null/,'external property must bind transactional contexts');
assert.match(router,/\$surface==='external_site'\?\$origin:''/,'external origin must bind transactional contexts');
assert.match(router,/Connected-site Profile Agent context is unavailable/,'external Agent execution must require pre-authorized context');

assert.match(native,/vp3_profile_webmcp_session_proof_valid_v100/,'native transport proof stays at gateway');
assert.match(native,/vp3_profile_webmcp_native_origin_allowed_v100/,'native Origin enforcement stays at gateway');
assert.match(external,/vp3_profile_webmcp_external_origin_v120/,'external Origin enforcement stays at gateway');
assert.match(external,/vp3_profile_webmcp_chat_grant_verify_v140/,'external chat grant verification stays at gateway');
assert.doesNotMatch(router,/Access-Control-Allow-Origin|HTTP_X_VP3_WEBMCP_SESSION|chat_grant_verify_v140/,'router must not absorb transport authorization');

assert.match(router,/webmcp_booking_completed/);
assert.match(router,/webmcp_checkout_started/);
assert.match(router,/webmcp_campaign_participation_completed/);
assert.match(router,/webmcp_reward_claim_handoff_confirmed/);
assert.match(router,/webmcp_reward_transfer_completed/);

assert.match(workflow,/profile-webmcp-tool-router-v191\.php/);
assert.match(workflow,/profile-webmcp-tool-router-v191-contract\.mjs/);
assert.match(recovery,/profile-webmcp-tool-router-v191-contract\.mjs/);
assert.match(recovery,/profile-webmcp-tool-router-v191\.php/);

console.log('PROFILE_WEBMCP_TOOL_ROUTER_V191_CONTRACT=PASS');
