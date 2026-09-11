import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const tools = read('includes/agent-scheduling-tools-v460.php');
const boundary = read('includes/agent-tool-authorization-v400.php');
const bootstrap = read('includes/bootstrap.php');
const scheduling = read('includes/agent-scheduling-v430.php');
const actionSystem = read('includes/agent-action-system-v124.php');

assert.match(tools, /VP3_AGENT_SCHEDULING_TOOLS_V460/, 'phase 4 must expose a versioned scheduling tool runtime');
assert.match(bootstrap, /require_once __DIR__\.'\/agent-scheduling-tools-v460\.php';/, 'bootstrap must load scheduling tools');

const nativeRoute = boundary.indexOf('agent_scheduling_tools_query_v460');
const musicRoute = boundary.indexOf('$bookingIntent=');
assert.ok(nativeRoute > -1 && musicRoute > -1 && nativeRoute < musicRoute, 'native appointment scheduling must run before legacy music Booking Agent research');
assert.match(boundary, /vp3_agent_tool_authorize_result_v400\(\$scheduling,\$user,\$query\)/, 'scheduling results must pass through the canonical v4.00 authorization boundary');

assert.match(tools, /WHERE b\.owner_user_id=\?/, 'booking discovery must be scoped to the authenticated owner');
assert.match(tools, /owner_user_id=\? AND status IN \('pending','confirmed'\)/, 'booking mutations must remain owner scoped');
assert.match(tools, /\(int\)\$event\['owner_user_id'\]!==(int)\$user\['id'\]/, 'event types must be re-authorized to the current owner before mutation');
assert.match(tools, /SELECT user_agent_id FROM chat_conversations WHERE id=\? AND user_id=\?/, 'selected Agent attribution must come from an owner-scoped conversation');
assert.match(tools, /user_agent_get_v236\(\$pdo,\$userId,\$agentId\)/, 'selected Agent must be revalidated against its owner');
assert.match(tools, /'created_by_user_id'=>\(int\)\$user\['id'\]/, 'Agent-created bookings must record the owner principal');
assert.match(tools, /'created_by_agent_id'=>\$agentId/, 'Agent-created bookings must record the selected Agent');

assert.match(tools, /agent_scheduling_tools_prepare_v460/, 'state-changing scheduling must be prepared before execution');
assert.match(tools, /agent_scheduling_tools_confirmation_v460/, 'mutations require an explicit second-turn confirmation');
assert.match(tools, /'expires_at'=>time\(\)\+900/, 'pending approval must expire quickly');
assert.match(tools, /agent_scheduling_tools_pending_key_v460\(\(int\)\$user\['id'\],\$conversationId\)/, 'pending approval must be scoped to user and conversation');
assert.match(tools, /'nonce'=>bin2hex\(random_bytes\(16\)\)/, 'prepared mutations must carry an unpredictable nonce');
assert.match(tools, /agent_action_v124_risk/, 'scheduling approval must reuse the canonical Agent action risk model');
assert.match(actionSystem, /'requires_approval'=>\$external\|\|\$destructive/, 'canonical risk model must require approval for external/destructive effects');
assert.match(tools, /Reply \*\*Confirm booking\*\*/, 'booking must explicitly ask the owner for confirmation');
assert.match(tools, /Reply \*\*Confirm reschedule\*\*/, 'rescheduling must explicitly ask the owner for confirmation');
assert.match(tools, /Reply \*\*Confirm cancellation\*\*/, 'cancellation must explicitly ask the owner for confirmation');

assert.match(tools, /agent_scheduling_slots_for_date_v430/, 'availability must use canonical conflict-aware slot generation');
assert.match(tools, /agent_scheduling_validate_start_v430\(\$pdo,\$event,\$utc,\$excludeBookingId\)/, 'rescheduling availability must validate against canonical constraints while excluding only the source booking');
assert.match(tools, /agent_scheduling_create_booking_v430/, 'Agent booking writes must use the canonical conflict-safe booking engine');
assert.match(scheduling, /SELECT GET_LOCK\(\?,5\)/, 'canonical booking writes retain the schedule lock');
assert.match(tools, /SELECT GET_LOCK\(\?,5\)/, 'rescheduling must serialize its cancel/create transaction');
assert.match(tools, /if\(\$started\)\$pdo->beginTransaction\(\)/, 'rescheduling must be atomic');
assert.match(tools, /if\(\$started&&\$pdo->inTransaction\(\)\)\$pdo->rollBack\(\)/, 'failed reschedules must roll back');
assert.match(tools, /rescheduled_from_id=\?/, 'rescheduling must preserve booking lineage');
assert.match(tools, /SELECT RELEASE_LOCK\(\?\)/, 'reschedule schedule lock must be released');

for (const key of ['scheduling.list','scheduling.availability','scheduling.book','scheduling.reschedule','scheduling.cancel']) {
  assert.ok(tools.includes(`'${key}'`), `${key} must write Agent tool audit history`);
}
assert.match(tools, /'approval_required'/, 'prepared mutations must be auditable as approval-required');
assert.doesNotMatch(tools, /cancel_token|public_token/, 'Agent tools must never read or expose public bearer tokens');

assert.match(tools, /Please say AM or PM/, 'ambiguous 12-hour times must not be guessed');
assert.match(tools, /venue\|venues\|gig\|gigs\|tour\|touring/, 'music booking research must remain explicitly disambiguated from appointments');
assert.match(tools, /function agent_scheduling_tools_profile_query_v460/, 'phase 4 must include a safe public Profile Agent scheduling skill');
assert.match(tools, /private appointment-management link/, 'public Profile Agent must never expose another visitor booking for mutation');

assert.doesNotMatch(tools, /CREATE TABLE|ALTER TABLE|DROP TABLE/i, 'phase 4 must not introduce schema mutations outside the existing upgrade-managed scheduling store');
console.log('AGENT_SCHEDULING_TOOLS_V460=PASS');
