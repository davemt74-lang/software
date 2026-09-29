import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const phpCatalog=read('includes/profile-webmcp-v100.php');
const jsRuntime=read('profile-webmcp-v100.js');
const externalRuntime=read('profile-webmcp-external-v120.js');
const resolver=read('includes/profile-webmcp-capability-resolver-v190.php');
const router=read('includes/profile-webmcp-tool-router-v191.php');
const agent=read('includes/agent-profile-webmcp-v193.php');
const v194=read('includes/profile-webmcp-continuity-v194.php');
const v195=read('includes/profile-webmcp-continuity-v195.php');

const phpTools=[...phpCatalog.matchAll(/'((?:vp3\.)[^']+)'\s*=>\s*\[/g)].map(m=>m[1]);
const jsTools=[...jsRuntime.matchAll(/'((?:vp3\.)[^']+)'\s*:\s*\{/g)].map(m=>m[1]);
const phpSet=new Set(phpTools);
const jsSet=new Set(jsTools);
const missingInBrowser=[...phpSet].filter(x=>!jsSet.has(x));
const unknownInBrowser=[...jsSet].filter(x=>!phpSet.has(x));
assert.deepEqual(missingInBrowser,[],'native browser catalog must cover every PHP tool');
assert.deepEqual(unknownInBrowser,[],'native browser catalog must not invent tools');

assert.match(externalRuntime,/manifest|allowed_tools|registerTool/s,'connected sites must remain manifest-driven');
assert.match(resolver,/external_site/);
assert.match(router,/native_profile','external_site/);

assert.match(agent,/vp3_profile_webmcp_resume_issue_v194/);
assert.match(v194,/vp3\.webmcp\.resume\.v1/);
assert.match(v194,/action_context_id/);
assert.match(v195,/vp3\.webmcp\.return\.v1/);
assert.match(v195,/conversation_id/);
assert.match(v195,/execution_allowed'=>false/);

for(const forbidden of ['vp3_profile_webmcp_dispatch_v191(','vp3_profile_webmcp_scheduling_confirm_v150(','vp3_profile_webmcp_commerce_checkout_confirm_v160(']){
  assert.equal(agent.includes(forbidden),false,'Agent Brain must not execute '+forbidden);
}

console.log('PROFILE_WEBMCP_RELEASE_V196_GOLDEN_JOURNEY=PASS');
