import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const layer=read('includes/profile-webmcp-analytics-v130.php');
const bootstrap=read('includes/bootstrap.php');
const nativeApi=read('api/profile-webmcp-v100.php');
const externalApi=read('api/profile-webmcp-external-v120.php');
const nativeRuntime=read('profile-webmcp-v100.js');
const externalRuntime=read('profile-webmcp-external-v120.js');
const analytics=read('includes/vp3-analytics.php');
const ui=read('profile-agent-analytics.js');
const referral=read('includes/agent-referral-attribution.php');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(bootstrap,/profile-webmcp-analytics-v130\.php/,'bootstrap must load WebMCP analytics layer');
assert.match(layer,/VP3_PROFILE_WEBMCP_EVENT_ENVELOPE_V130='vp3\.webmcp\.event\.v1'/);
for(const event of ['webmcp_tool_called','webmcp_tool_completed','webmcp_tool_failed','webmcp_tool_cancelled','webmcp_tool_denied','webmcp_handoff_requested','webmcp_message_sent']){
  assert.match(layer,new RegExp(event),'event taxonomy '+event);
}
assert.match(layer,/vp3_radar_native_property/,'native telemetry must use canonical Radar property');
assert.match(layer,/vp3_radar_native_identity/,'recognized WebMCP agents must reuse Agent Radar contacts');
assert.match(layer,/vp3_radar_sessions/,'WebMCP sessions must reuse canonical Radar session storage');
assert.match(layer,/vp3_radar_events/,'WebMCP events must reuse canonical Radar event storage');
assert.match(layer,/vp3_agent_referral_lookup/,'referral token must resolve through canonical first-party attribution');
assert.match(layer,/INSERT IGNORE INTO vp3_agent_referral_events/,'WebMCP referral continuity must be durable and deduped');
assert.match(layer,/event_type,value_amount,occurred_at[\s\S]*'webmcp'/,'WebMCP lineage must have its own non-conversion referral event');
assert.doesNotMatch(layer,/click_count=click_count\+1|conversion_count=conversion_count\+1/,'WebMCP lineage must not inflate referral or conversion counts');

const envelopeStart=layer.indexOf('function vp3_profile_webmcp_event_envelope_v130');
const envelopeEnd=layer.indexOf('\nfunction ',envelopeStart+20);
const envelope=layer.slice(envelopeStart,envelopeEnd);
assert.doesNotMatch(envelope,/agent_referral|tool_input|message_body|raw_user_agent/i,'durable event envelope must exclude raw token/content fields');

assert.match(nativeApi,/vp3_profile_webmcp_telemetry_v130/);
assert.match(nativeApi,/webmcp_tool_called/);
assert.match(nativeApi,/webmcp_tool_denied/);
assert.match(nativeApi,/vp3_profile_webmcp_tool_json_v130/);
assert.match(nativeApi,/webmcp_message_sent/);
assert.match(nativeApi,/webmcp_handoff_requested/);

assert.match(externalApi,/vp3_profile_webmcp_telemetry_v130/);
assert.match(externalApi,/webmcp_manifest_loaded/);
assert.match(externalApi,/webmcp_tool_called/);
assert.match(externalApi,/webmcp_tool_denied/);
assert.match(externalApi,/vp3_profile_webmcp_external_tool_json_v130/);

for(const runtime of [nativeRuntime,externalRuntime]){
  assert.match(runtime,/webmcp_session_id/);
  assert.match(runtime,/interaction_id/);
  assert.match(runtime,/agent_referral/);
  assert.match(runtime,/tool_called/);
  assert.match(runtime,/tool_completed/);
  assert.doesNotMatch(runtime,/telemetry:\s*\{[^}]*input|telemetry:\s*\{[^}]*message/s,'runtime telemetry must not copy tool content');
}
assert.match(externalRuntime,/credentials:'omit'/,'external telemetry must retain credential-free transport');
assert.doesNotMatch(externalRuntime,/localStorage|sessionStorage|document\.cookie/,'external WebMCP attribution must remain non-persistent');
assert.match(externalRuntime,/searchParams\.get\('vp3_ref'\)/,'external runtime may forward only the existing first-party referral token');

assert.match(analytics,/webmcp_tool_calls/);
assert.match(analytics,/webmcp_completed/);
assert.match(analytics,/webmcp_failed/);
assert.match(analytics,/webmcp_denied/);
assert.match(analytics,/webmcp_activity/);
assert.match(ui,/WebMCP calls/);
assert.match(ui,/Human \+ Agent \+ WebMCP activity/);
assert.match(ui,/webmcp_status/);
assert.match(ui,/duration_ms/);

assert.match(referral,/vp3_ref/,'WebMCP referral continuity must build on existing first-party referral tokens');

assert.match(workflow,/profile-webmcp-analytics-v130\.php/,'governed CI must lint Section 4 analytics layer');
assert.match(workflow,/profile-webmcp-analytics-v130-contract\.mjs/,'governed CI must run Section 4 static contract');
assert.match(workflow,/profile-webmcp-analytics-v130-runtime\.mjs/,'governed CI must run Section 4 runtime contract');
assert.match(recovery,/profile-webmcp-analytics-v130-contract\.mjs/,'Recovery must retain Section 4 static contract');
assert.match(recovery,/profile-webmcp-analytics-v130-runtime\.mjs/,'Recovery must retain Section 4 runtime contract');
assert.match(recovery,/profile-webmcp-analytics-v130\.php/,'Recovery must retain Section 4 PHP contract');

console.log('PROFILE_WEBMCP_ANALYTICS_V130_CONTRACT=PASS');
