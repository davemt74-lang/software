import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const base=read('includes/profile-webmcp-v100.php');
const discovery=read('includes/profile-webmcp-discovery-v110.php');
const api=read('api/profile-webmcp-v100.php');
const runtime=read('profile-webmcp-v100.js');
const profile=read('profile.php');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(base,/vp3_profile_webmcp_discovery_tools_v110/,'foundation catalog must accept discovery extension');

for(const name of ['vp3.profile.links.list','vp3.profile.media.get','vp3.agent.get','vp3.profile.public_state.get']){
  assert.ok(discovery.includes("'"+name+"'"),name+' missing from server discovery catalog');
  assert.ok(runtime.includes("'"+name+"'"),name+' missing from trusted runtime catalog');
  assert.ok(api.includes("'"+name+"'"),name+' missing from gateway dispatch');
}

assert.match(discovery,/readOnlyHint'=>true/g);
assert.doesNotMatch(discovery,/consequentialHint'=>true/,'Section 2 tools must remain non-consequential');
assert.match(discovery,/vp3_profile_webmcp_public_profile_v100/,'links must reuse public profile projection');
assert.match(discovery,/profile_public_media_url_v174/,'media must use public media URL resolver');
assert.match(discovery,/profile_active_agent/,'agent discovery must use canonical active agent');
assert.doesNotMatch(discovery,/system_prompt|instructions|knowledge|email/,'discovery projection must not include private agent/profile fields');
assert.match(discovery,/viewer_identity_disclosed/,'public state must preserve identity-disclosure semantics');
assert.doesNotMatch(discovery,/slots|orders|refund|claim|checkout|payment/,'Section 2 must not expose transactional detail');

assert.match(profile,/profile-webmcp-discovery-v110\.php/);
assert.match(api,/profile-webmcp-discovery-v110\.php/);
assert.match(api,/profile-public-media-v174\.php/);

assert.match(workflow,/tests\/profile-webmcp-v110\.php/);
assert.match(workflow,/tests\/profile-webmcp-v110-contract\.mjs/);
assert.match(workflow,/profile-webmcp-discovery-v110\.php/);
assert.match(recovery,/tests\/profile-webmcp-v110\.php/);
assert.match(recovery,/tests\/profile-webmcp-v110-contract\.mjs/);

console.log('PROFILE_WEBMCP_V110_CONTRACT=PASS');
