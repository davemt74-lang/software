import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const actions=read('includes/profile-webmcp-actions-v150.php');
const scheduling=read('includes/profile-webmcp-scheduling-v150.php');
const catalog=read('includes/profile-webmcp-v100.php');
const nativeApi=read('api/profile-webmcp-v100.php');
const router=read('includes/profile-webmcp-tool-router-v191.php');
const externalLayer=read('includes/profile-webmcp-external-v120.php');
const resolver=read('includes/profile-webmcp-capability-resolver-v190.php');
const externalApi=read('api/profile-webmcp-external-v120.php');
const nativeRuntime=read('profile-webmcp-v100.js');
const externalRuntime=read('profile-webmcp-external-v120.js');
const upgrade=read('upgrade.php');
const bootstrap=read('includes/bootstrap.php');
const sitesUi=read('profile-agent-radar-sites.js');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(actions,/CREATE TABLE IF NOT EXISTS profile_webmcp_actions/);
assert.match(actions,/UNIQUE KEY uq_profile_webmcp_action_intent/);
assert.match(actions,/UNIQUE KEY uq_profile_webmcp_action_idempotency/);
assert.match(actions,/payload_hash CHAR\(64\)/);
assert.match(actions,/session_hash CHAR\(64\)/);
assert.match(actions,/result_json LONGTEXT/);
assert.match(actions,/state VARCHAR\(24\).*prepared/s);
assert.doesNotMatch(actions,/guest_name|guest_email|manage_token|cancel_token|payment_url/,'action ledger schema/helper must not persist guest PII or booking credentials');
const commitStart=actions.indexOf('function vp3_profile_webmcp_action_commit_v150');
assert.ok(commitStart>0);
const commitBlock=actions.slice(commitStart);
assert.match(commitBlock,/safeBooking/);
assert.doesNotMatch(commitBlock,/guest_name|guest_email|public_token|manage_token|cancel_token/,'committed action snapshot must remain PII/credential free');

assert.match(bootstrap,/profile-webmcp-actions-v150\.php/);
assert.match(catalog,/vp3_profile_webmcp_tool_runtime_ready_v150/,'state-changing scheduling tools must be hidden until ledger upgrade');
assert.match(resolver,/vp3_profile_webmcp_tool_runtime_ready_v150/,'connected-site scheduling actions must also fail closed before ledger upgrade');
assert.match(upgrade,/vp3_profile_webmcp_actions_schema_ready_v150/);
assert.match(upgrade,/vp3_profile_webmcp_actions_ensure_schema_v150/);

assert.match(scheduling,/hash_hmac\('sha256'/,'prepare confirmation token must be signed');
assert.match(scheduling,/vp3_profile_webmcp_b64url_encode_v140\(\$sig\).*\$sigEncoded/s,'non-canonical signature encoding must fail closed');
assert.match(scheduling,/vp3_profile_webmcp_native_signing_secret_v150/,'native confirmation signing must use a server-only session secret');
assert.match(scheduling,/vp3_profile_webmcp_action_secrets/,'server-only signing secret must stay in PHP session state');
assert.doesNotMatch(scheduling,/return hash\('sha256','vp3-webmcp-scheduling-v150\|native\|'\.\$nativeProof/,'browser-visible native proof must not be the signing key');
assert.match(scheduling,/payload_hash/);
assert.match(scheduling,/\$action\['operation'\]!==\$operation/,'confirm must re-bind stored operation');
assert.match(scheduling,/\$action\['profile_username'\]/,'confirm must re-bind stored profile');
assert.match(scheduling,/session_hash/);
assert.match(scheduling,/origin_hash/);
assert.match(scheduling,/VP3_PROFILE_WEBMCP_SCHEDULING_INTENT_TTL_V150=600/);
assert.match(scheduling,/agent_scheduling_public_event_for_owner_v450/,'event lookup must be public-only');
assert.match(scheduling,/agent_scheduling_public_event_by_slug_v450/,'slug lookup must be public-only');
assert.match(scheduling,/agent_scheduling_slots_for_date_v430\(\$pdo,\(int\)\$event\['id'\],\$date,true\)/,'availability must request public-only slots');
assert.match(scheduling,/agent_scheduling_validate_start_v430/,'confirm must revalidate availability');
assert.match(scheduling,/event_state_hash/,'booking prepare must bind event state');
assert.match(scheduling,/booking_state_hash/,'manage actions must bind booking state');
assert.match(scheduling,/vp3_profile_webmcp_action_by_idempotency_v150/,'confirm must enforce durable idempotency');
assert.match(actions,/FOR UPDATE/,'idempotency/action ledger rows must be transaction locked');
assert.match(scheduling,/SELECT GET_LOCK\(\?,5\)/,'same-key confirmations must serialize before the booking transaction');
assert.match(scheduling,/SELECT RELEASE_LOCK\(\?\)/,'idempotency named lock must always release');
assert.match(scheduling,/agent_scheduling_create_booking_v430/,'create must use canonical scheduling store');
assert.match(scheduling,/agent_appointment_lifecycle_reschedule_v700/,'reschedule must preserve canonical lifecycle');
assert.match(scheduling,/agent_appointment_lifecycle_transition_v700/,'cancel must preserve canonical lifecycle');
assert.match(scheduling,/agent_paid_appointments_create_personal_v800/,'paid appointments must use canonical paid hold');
assert.match(scheduling,/agent_paid_appointments_payment_url_v800/,'paid booking must return existing secure payment flow');
assert.match(scheduling,/if\(\$paid\)\{[\s\S]*vp3_profile_webmcp_booking_by_id_v150/,'paid booking response must reload canonical pending status after payment hold');
assert.match(scheduling,/vp3_profile_webmcp_booking_public_projection_v150/,'public-token lookup must use PII-minimized projection');
const publicProjection=scheduling.slice(scheduling.indexOf('function vp3_profile_webmcp_booking_public_projection_v150'),scheduling.indexOf('function vp3_profile_webmcp_booking_response_v150'));
assert.doesNotMatch(publicProjection,/guest_name|guest_email|guest_phone|guest_notes|cancel_token|manage_token/,'public booking projection must not expose guest PII or management credentials');
assert.doesNotMatch(scheduling,/agent_paid_appointments_mark_paid_v800/,'WebMCP must never mark appointment payment paid');
assert.doesNotMatch(scheduling,/SELECT[\s\S]{0,240}(calendar_events|user_calendar_events)/i,'Scheduling WebMCP must not query private calendar rows directly');

for(const name of [
 'vp3.booking.options.list','vp3.booking.availability.list','vp3.booking.prepare','vp3.booking.confirm','vp3.booking.get',
 'vp3.booking.reschedule.prepare','vp3.booking.reschedule.confirm','vp3.booking.cancel.prepare','vp3.booking.cancel.confirm'
]){
 assert.match(catalog,new RegExp(name.replaceAll('.','\\.')),'server catalog '+name);
 assert.match(nativeRuntime,new RegExp(name.replaceAll('.','\\.')),'native runtime '+name);
 assert.match(externalRuntime,new RegExp(name.replaceAll('.','\\.')),'external runtime '+name);
}
for(const name of ['vp3.booking.confirm','vp3.booking.reschedule.confirm','vp3.booking.cancel.confirm']){
 const idx=catalog.indexOf("'"+name+"'");
 const block=catalog.slice(idx,idx+1800);
 assert.match(block,/consequentialHint'\s*=>\s*true/,'confirm must be consequential '+name);
}
assert.match(router,/vp3_profile_webmcp_scheduling_confirm_v150/);
assert.match(externalApi,/vp3_profile_webmcp_dispatch_v191/);
assert.match(router,/webmcp_confirmation_required/);
assert.match(router,/webmcp_confirmation_required/);
assert.match(router,/webmcp_booking_completed/);
assert.match(router,/webmcp_booking_completed/);
assert.match(externalLayer,/scheduling_enabled'\s*=>\s*\$schedulingEnabled/);
assert.match(externalRuntime,/scheduling_enabled===true/);
assert.match(externalRuntime,/credentials:'omit'/);
assert.doesNotMatch(externalRuntime,/Authorization|X-VP3-WebMCP-Session/,'connected-site scheduling must remain credential-free browser transport');
assert.doesNotMatch(externalRuntime,/localStorage|sessionStorage|document\.cookie/,'connected-site scheduling must remain in-memory only');
assert.match(sitesUi,/Scheduling <b>/);
assert.match(sitesUi,/Public scheduling and Profile Commerce use explicit prepare\/confirm tools/);

assert.match(workflow,/profile-webmcp-actions-v150\.php/);
assert.match(workflow,/profile-webmcp-scheduling-v150\.php/);
assert.match(workflow,/profile-webmcp-scheduling-v150-contract\.mjs/);
assert.match(workflow,/profile-webmcp-scheduling-v150-runtime\.mjs/);
assert.match(recovery,/profile-webmcp-scheduling-v150\.php/);
assert.match(recovery,/profile-webmcp-scheduling-v150-contract\.mjs/);
assert.match(recovery,/profile-webmcp-scheduling-v150-runtime\.mjs/);

console.log('PROFILE_WEBMCP_SCHEDULING_V150_CONTRACT=PASS');
