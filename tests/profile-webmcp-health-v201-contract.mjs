import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const health=read('includes/profile-webmcp-health-v201.php');
const api=read('api/profile-webmcp-health-v201.php');
const analytics=read('includes/profile-webmcp-analytics-v130.php');
const native=read('profile-webmcp-v100.js');
const external=read('profile-webmcp-external-v120.js');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(health,/vp3\.profile\.webmcp\.health\.v1/);
assert.match(health,/capability_resolver/);
assert.match(health,/tool_router/);
assert.match(health,/confirmation_ledger/);
assert.match(health,/continuity/);
assert.match(health,/version_negotiation/);
assert.match(health,/registration_mismatch/);
assert.match(health,/last_success/);
assert.match(health,/last_failure/);
assert.match(health,/sensitive_payloads_included'=>false/);
assert.match(api,/current_user\(\)/);
assert.match(api,/profile_for_user/);
assert.match(api,/Cache-Control: private, no-store/);
assert.doesNotMatch(api,/confirmation_token|receipt_token|manage_token|payment_token|credential/);
for(const src of [analytics,native,external]){
 assert.match(src,/registered_tool_count/);
 assert.match(src,/expected_tool_count/);
 assert.match(src,/runtime_build/);
 assert.match(src,/release_version/);
}
assert.match(workflow,/profile-webmcp-health-v201/);
assert.match(recovery,/profile-webmcp-health-v201/);
console.log('PROFILE_WEBMCP_HEALTH_V201_CONTRACT=PASS');
