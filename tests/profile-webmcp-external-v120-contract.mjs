import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const layer=read('includes/profile-webmcp-external-v120.php');
const endpoint=read('api/profile-webmcp-external-v120.php');
const runtime=read('profile-webmcp-external-v120.js');
const sitesApi=read('api/agent-radar-sites.php');
const sitesUi=read('profile-agent-radar-sites.js');
const portal=read('profile-agent.php');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(layer,/vp3_radar_external_origin_allowed/,'external origin must reuse Agent Radar registered-domain authority');
assert.match(layer,/surface'\s*=>\s*'external_site'/);
assert.match(layer,/read_only'\s*=>\s*!\$chatEnabled&&!\$schedulingEnabled/);
assert.match(layer,/stateful_profile_agent'\s*=>\s*\$chatEnabled/);
assert.match(layer,/vp3\.agent\.get/);
assert.match(layer,/vp3\.agent\.message\.send/,'external tool allowlist may expose governed public Agent chat');
assert.match(layer,/vp3\.booking\.prepare/,'connected-site manifest may expose governed public scheduling');
assert.doesNotMatch(layer,/vp3\.commerce\.checkout\.prepare/,'connected-site manifest must still exclude future commerce checkout');

assert.match(endpoint,/vp3_radar_external_property_by_key/,'gateway must bind public property key to active Agent Radar property');
assert.match(endpoint,/vp3_profile_webmcp_external_origin_v120/,'gateway must verify browser Origin');
assert.match(endpoint,/Access-Control-Allow-Origin/);
assert.match(endpoint,/Vary: Origin/);
assert.match(endpoint,/Access-Control-Allow-Headers: Content-Type/);
assert.doesNotMatch(endpoint,/Access-Control-Allow-Credentials/i,'external WebMCP must not enable credentialed CORS');
assert.doesNotMatch(endpoint,/HTTP_AUTHORIZATION|Bearer\s|connected_site_auth/i,'public WebMCP must not use Connected Sites bearer credentials');
assert.match(endpoint,/REQUEST_METHOD.*OPTIONS/s,'CORS preflight must be supported');
assert.match(endpoint,/PROPERTY_MISMATCH/);
assert.match(endpoint,/PROFILE_MISMATCH/);
assert.match(endpoint,/vp3_profile_agent_public_message_service_v110/,'external Agent chat must reuse canonical public service');
assert.match(endpoint,/vp3_profile_webmcp_scheduling_confirm_v150/,'external gateway may execute scheduling only through governed adapter');
assert.doesNotMatch(endpoint,/mark_paid|claim_from_tray|vp3\.commerce\.checkout/,'external gateway must still exclude direct payment, reward, and commerce authority');

for(const name of ['vp3.profile.capabilities.get','vp3.profile.get','vp3.intent.resolve','vp3.agent.get']){
  assert.match(runtime,new RegExp(name.replaceAll('.','\\.')),'external trusted runtime '+name);
}
assert.match(runtime,/vp3\.agent\.message\.send/,'external trusted catalog may include governed Agent chat');
assert.match(runtime,/vp3\.booking\.prepare/,'trusted external runtime may expose governed scheduling tools');
assert.doesNotMatch(runtime,/vp3\.commerce\.checkout/,'external runtime must still exclude commerce checkout');
assert.match(runtime,/credentials:'omit'/,'external runtime must omit ambient cookies');
assert.match(runtime,/stateful_profile_agent===true&&Boolean\(this\.chatGrant\)/,'stateful chat tools must require both manifest authority and a grant');
assert.doesNotMatch(runtime,/Authorization|X-VP3-WebMCP-Session/,'external runtime must not send private bearer/session proof');
assert.doesNotMatch(runtime,/localStorage|sessionStorage|document\.cookie/,'external runtime must not persist identity or credentials');
assert.match(runtime,/data.*vp3Key|dataset\?\.vp3Key/,'loader must read public property key from embed');
assert.match(runtime,/controller\.abort\(\)/,'external tools must unregister with AbortSignal');

assert.match(sitesApi,/vp3_profile_webmcp_external_enrich_site_state_v120/,'owner site state must include WebMCP diagnostics');
assert.match(portal,/webmcpExternalScriptUrl/,'owner portal must publish external runtime URL');
assert.match(sitesUi,/profile-webmcp-external-v120\.js|webmcpExternalScriptUrl/);
assert.match(sitesUi,/Tracking \+ Agent Manifest \+ WebMCP/);
assert.match(sitesUi,/data-vp3-key/);
assert.match(sitesUi,/webmcp_tool_count/);
assert.doesNotMatch(sitesUi,/client_secret|access_token|refresh_token/,'embed UI must never serialize private connected-site credentials');

assert.match(workflow,/profile-webmcp-external-v120\.php/,'governed CI must lint external WebMCP layer');
assert.match(workflow,/profile-webmcp-external-v120\.js/,'package smoke must retain external runtime');
assert.match(workflow,/profile-webmcp-external-v120-contract\.mjs/,'governed CI must run external static contract');
assert.match(workflow,/profile-webmcp-external-v120-runtime\.mjs/,'governed CI must run external runtime contract');
assert.match(recovery,/profile-webmcp-external-v120-contract\.mjs/,'Recovery must retain Section 3 static contract');
assert.match(recovery,/profile-webmcp-external-v120-runtime\.mjs/,'Recovery must retain Section 3 runtime contract');
assert.match(recovery,/profile-webmcp-external-v120\.php/,'Recovery must retain Section 3 PHP contract');

console.log('PROFILE_WEBMCP_EXTERNAL_V120_CONTRACT=PASS');
