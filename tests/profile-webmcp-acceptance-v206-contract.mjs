import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const acceptance=read('includes/profile-webmcp-acceptance-v206.php');
const release=read('includes/profile-webmcp-release-v196.php');
const resolver=read('includes/profile-webmcp-capability-resolver-v190.php');
const router=read('includes/profile-webmcp-tool-router-v191.php');
const agent=read('includes/agent-profile-webmcp-v193.php');
const v194=read('includes/profile-webmcp-continuity-v194.php');
const v195=read('includes/profile-webmcp-continuity-v195.php');
const health=read('includes/profile-webmcp-health-v201.php');
const compat=read('includes/profile-webmcp-compatibility-v203.php');
const sites=read('includes/profile-webmcp-sites-v204.php');
const obs=read('includes/profile-webmcp-observability-v205.php');
const admin=read('admin/webmcp.php');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(acceptance,/vp3\.profile\.webmcp\.acceptance\.v1/);
for(const gate of ['release_catalog','surface_authority','version_negotiation','tool_lifecycle','continuity','connected_sites','observability','health_admin'])assert.match(acceptance,new RegExp("'"+gate+"'"));
assert.match(acceptance,/contains_sensitive_payload'=>false/);

assert.match(release,/native_profile[\s\S]*execution_allowed'=>true/);
assert.match(release,/external_site[\s\S]*execution_allowed'=>true/);
assert.match(release,/agent_brain[\s\S]*execution_allowed'=>false/);
assert.match(resolver,/vp3_profile_webmcp_apply_compatibility_v203/);
assert.match(router,/WEBMCP_TOOL_UNAVAILABLE/);
assert.match(agent,/'execution_allowed'=>false/);
assert.match(v194,/auto_execute_consequential'=>false/);
assert.match(v195,/return_token_hash'\]=''/);
assert.match(health,/contains_sensitive_payload'=>false/);
assert.match(compat,/minimum_runtime_build/);
assert.match(sites,/origin_mismatch_payload_retained|contains_sensitive_payload/);
assert.match(obs,/sensitive|correlation_id/i);
assert.match(admin,/WebMCP Operations/);
assert.match(admin,/Audit timeline/);
assert.match(admin,/Connected site runtime management/);
assert.match(workflow,/profile-webmcp-acceptance-v206/);
assert.match(recovery,/profile-webmcp-acceptance-v206/);

console.log('PROFILE_WEBMCP_ACCEPTANCE_V206_CONTRACT=PASS');
