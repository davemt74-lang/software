import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const nativeRuntime=read('profile-webmcp-v100.js');
const externalRuntime=read('profile-webmcp-external-v120.js');
const nativeApi=read('api/profile-webmcp-v100.php');
const externalApi=read('api/profile-webmcp-external-v120.php');
const agent=read('includes/agent-profile-webmcp-v193.php');
const router=read('includes/profile-webmcp-tool-router-v191.php');
const continuity=read('includes/profile-webmcp-continuity-v194.php')+read('includes/profile-webmcp-continuity-v195.php');
const obs=read('includes/profile-webmcp-observability-v205.php');

assert.match(nativeRuntime,/client_versions/);
assert.match(externalRuntime,/client_versions/);
assert.match(nativeApi,/WEBMCP_VERSION_INCOMPATIBLE|vp3_profile_webmcp_negotiate_v200/);
assert.match(externalApi,/vp3_profile_webmcp_negotiate_v200\('external_site'/);
assert.match(router,/vp3_profile_webmcp_dispatch_v191/);
assert.match(router,/WEBMCP_TOOL_UNAVAILABLE/);

assert.match(agent,/vp3_profile_webmcp_resume_issue_v194/);
assert.match(agent,/'requires_signed_profile_surface'=>true/);
assert.match(agent,/'execution_allowed'=>false/);
assert.doesNotMatch(agent,/vp3_profile_webmcp_dispatch_v191\(/);

assert.match(continuity,/vp3\.webmcp\.resume\.v1/);
assert.match(continuity,/vp3\.webmcp\.return\.v1/);
assert.match(continuity,/auto_execute_consequential'=>false/);
assert.match(continuity,/return_token_hash'\]=''/);

assert.match(obs,/webmcp_agent_planned/);
assert.match(obs,/webmcp_returned/);
assert.match(obs,/correlation_id/);
assert.doesNotMatch(obs,/confirmation_token|manage_token|receipt_token|payer_email|guest_email/);

const phpCatalog=[
 read('includes/profile-webmcp-v100.php'),
 read('includes/profile-webmcp-commerce-v160.php'),
 read('includes/profile-webmcp-campaigns-v170.php'),
 read('includes/profile-webmcp-rewards-v180.php')
].join('\n');
const phpTools=[...phpCatalog.matchAll(/'((?:vp3\.)[^']+)'\s*=>\s*\[/g)].map(m=>m[1]);
const jsTools=[...nativeRuntime.matchAll(/'((?:vp3\.)[^']+)'\s*:\s*\{/g)].map(m=>m[1]);
assert.deepEqual([...new Set(phpTools)].sort(),[...new Set(jsTools)].sort(),'native browser/PHP tool catalogs must remain identical');

console.log('PROFILE_WEBMCP_ACCEPTANCE_V206_GOLDEN_JOURNEY=PASS');
