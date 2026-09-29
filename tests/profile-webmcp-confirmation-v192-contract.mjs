import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const confirm=read('includes/profile-webmcp-confirmation-v192.php');
const router=read('includes/profile-webmcp-tool-router-v191.php');
const nativeApi=read('api/profile-webmcp-v100.php');
const externalApi=read('api/profile-webmcp-external-v120.php');
const runtime=read('profile-webmcp-v100.js');
const externalRuntime=read('profile-webmcp-external-v120.js');
const agent=read('profile-agent.js');
const css=read('profile.css');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(confirm,/VP3_PROFILE_WEBMCP_ACTION_CONTRACT_V192='vp3\.webmcp\.action\.v1'/);
for(const pair of [
  ['vp3.booking.prepare','vp3.booking.confirm'],
  ['vp3.booking.reschedule.prepare','vp3.booking.reschedule.confirm'],
  ['vp3.booking.cancel.prepare','vp3.booking.cancel.confirm'],
  ['vp3.commerce.checkout.prepare','vp3.commerce.checkout.confirm'],
  ['vp3.commerce.refund.prepare','vp3.commerce.refund.confirm'],
  ['vp3.campaign.participation.prepare','vp3.campaign.participation.confirm'],
  ['vp3.rewards.claim.prepare','vp3.rewards.claim.confirm'],
  ['vp3.rewards.transfer.prepare','vp3.rewards.transfer.confirm']
]){
  assert.match(confirm,new RegExp("'"+pair[0].replaceAll('.','\\.')+"'\\s*=>\\s*'"+pair[1].replaceAll('.','\\.')+"'"));
}
for(const field of ['phase','prepare_tool','confirm_tool','requires_confirmation','intent_id','expires_at_unix','expires_at_utc','preview','idempotency','confirmation','reprepare_required']){
  assert.match(confirm,new RegExp("'"+field+"'\\s*=>"));
}
assert.match(confirm,/requires_terms_acceptance'\s*=>\s*\$tool==='vp3\.commerce\.checkout\.prepare'/);
for(const code of ['CONFIRMATION_EXPIRED','ACTION_IN_PROGRESS','IDEMPOTENCY_CONFLICT','CONFIRMATION_MISMATCH','ACTION_UNAVAILABLE']){
  assert.match(confirm,new RegExp(code));
}
assert.match(router,/profile-webmcp-confirmation-v192\.php/);
assert.match(router,/vp3_profile_webmcp_action_respond_v192/);
assert.match(nativeApi,/vp3_profile_webmcp_action_error_v192/);
assert.match(externalApi,/vp3_profile_webmcp_action_error_v192/);

assert.match(runtime,/vp3:webmcp-confirmation/);
assert.match(runtime,/vp3:webmcp-confirmation-result/);
assert.match(runtime,/vp3:webmcp-confirm/);
assert.match(runtime,/idempotency_key:transportIdV130\(\)/);
assert.match(runtime,/terms_accepted=Boolean\(detail\.terms_accepted\)/);
assert.match(runtime,/removeEventListener\('vp3:webmcp-confirm'/);
assert.doesNotMatch(runtime,/localStorage|sessionStorage|document\.cookie/,'confirmation runtime must not persist action material');

assert.match(externalRuntime,/event:'confirmation_required'/);
assert.doesNotMatch(externalRuntime,/vp3:webmcp-confirm/,'connected-site runtime must not create host-page confirmation authority');

assert.match(agent,/profile-agent-confirmation-card/);
assert.match(agent,/safePreviewRows/);
assert.match(agent,/blocked=\/\(token\|secret\|credential\|hash\|intent/);
assert.match(agent,/requires_terms_acceptance/);
assert.match(agent,/I accept the seller terms/);
assert.match(agent,/new CustomEvent\('vp3:webmcp-confirm'/);
assert.match(agent,/CONFIRMATION_EXPIRED/);
assert.match(agent,/ACTION_IN_PROGRESS/);
assert.doesNotMatch(agent,/textContent\s*=.*confirmation\.(?:token|intent)/,'card must never render raw confirmation material');
assert.match(css,/Profile WebMCP v1\.92 unified confirmation cards/);
assert.match(css,/\.profile-agent-confirmation-card/);

assert.match(workflow,/profile-webmcp-confirmation-v192\.php/);
assert.match(workflow,/profile-webmcp-confirmation-v192-contract\.mjs/);
assert.match(workflow,/profile-webmcp-confirmation-v192-runtime\.mjs/);
assert.match(recovery,/profile-webmcp-confirmation-v192\.php/);
assert.match(recovery,/profile-webmcp-confirmation-v192-contract\.mjs/);
assert.match(recovery,/profile-webmcp-confirmation-v192-runtime\.mjs/);
console.log('PROFILE_WEBMCP_CONFIRMATION_V192_CONTRACT=PASS');
