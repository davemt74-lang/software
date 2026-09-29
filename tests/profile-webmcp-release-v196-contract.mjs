import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const release=read('includes/profile-webmcp-release-v196.php');
const resolver=read('includes/profile-webmcp-capability-resolver-v190.php');
const router=read('includes/profile-webmcp-tool-router-v191.php');
const agent=read('includes/agent-profile-webmcp-v193.php');
const resume=read('includes/profile-webmcp-continuity-v194.php');
const returned=read('includes/profile-webmcp-continuity-v195.php');
const nativeApi=read('api/profile-webmcp-v100.php');
const externalApi=read('api/profile-webmcp-external-v120.php');
const runtime=read('profile-webmcp-v100.js');
const externalRuntime=read('profile-webmcp-external-v120.js');
const profile=read('profile.php');
const chat=read('chat.php');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(release,/VP3_PROFILE_WEBMCP_RELEASE_V196/);
assert.match(release,/native_profile.*execution_allowed'=>true/s);
assert.match(release,/external_site.*execution_allowed'=>true/s);
assert.match(release,/agent_brain.*execution_allowed'=>false/s);
assert.match(release,/return_single_use'=>true/);
assert.match(release,/sensitive_payload_return'=>false/);
assert.match(release,/function vp3_profile_webmcp_release_catalog_audit_v196/);

assert.match(resolver,/execution_allowed'=>\$surface!=='agent_brain'/);
assert.match(router,/in_array\(\$surface,\['native_profile','external_site'\],true\)/);
assert.doesNotMatch(router,/agent_brain[^\n]{0,120}dispatch/i);
assert.match(agent,/'execution_allowed'=>false/);
assert.match(agent,/'requires_signed_profile_surface'=>true/);
assert.match(resume,/auto_execute_consequential'=>false/);
assert.match(returned,/contains_sensitive_payload'=>false/);
assert.match(returned,/return_token_hash'\]=''/);

assert.match(nativeApi,/vp3_profile_webmcp_dispatch_v191/);
assert.match(externalApi,/vp3_profile_webmcp_dispatch_v191/);
assert.match(runtime,/vp3:webmcp-confirm/);
assert.match(externalRuntime,/registerTool|modelContext/);
assert.match(profile,/webmcp_resume/);
assert.match(chat,/profile_webmcp_return/);

assert.match(workflow,/profile-webmcp-release-v196/);
assert.match(recovery,/profile-webmcp-release-v196/);

console.log('PROFILE_WEBMCP_RELEASE_V196_CONTRACT=PASS');
