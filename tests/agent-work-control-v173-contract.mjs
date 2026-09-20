import fs from 'node:fs';
import assert from 'node:assert/strict';

const control = fs.readFileSync('includes/agent-work-control-v173.php','utf8');
const engine = fs.readFileSync('includes/agent-job-engine-v1900.php','utf8');
const api = fs.readFileSync('api/agent-workflow-runs-v1400.php','utf8');
const canonicalChat = [
  fs.readFileSync('api/chat-v236.php','utf8'),
  fs.readFileSync('includes/agent-chat-runtime-v2160.php','utf8')
].join('\n');
const sharedChat = fs.readFileSync('includes/release-chat-v105.php','utf8');
const queue = fs.readFileSync('includes/agent-chat-intelligence-v171.php','utf8');
const upgrade = fs.readFileSync('agent-work-control-upgrade-v173.php','utf8');
const migration = fs.readFileSync('upgrade-agent-work-control-v173.sql','utf8');

assert.match(control,/VP3_AGENT_WORK_CONTROL_V173\s*=\s*'agent-work-control-v173-20260915'/,'Phase 17.3 needs a stable build marker');
for (const column of ['work_priority','pause_requested_at','paused_at','paused_from_status']) {
  assert.match(control,new RegExp(column),`Phase 17.3 must persist ${column} in the durable workflow ledger`);
  assert.match(migration,new RegExp(column),`The deploy migration must install ${column}`);
}
assert.match(control,/owner_user_id=\?/,'All work-control mutations must remain owner-scoped');
assert.match(control,/has_permission\('account\.access'/,'Work control must preserve account access authorization');
assert.match(control,/agent_workflow_event_v1400/,'Control mutations must remain in the canonical workflow audit trail');
assert.match(control,/agent_workflow_approve_v1400/,'Approval must reuse the canonical Phase 14 approval mutation');
assert.match(control,/agent_job_cancel_v1900/,'Cancel must reuse the durable job-engine cancellation boundary');
assert.match(control,/agent_job_retry_v1900/,'Retry must reuse the durable bounded-retry boundary');
assert.match(control,/status='paused'/,'Pause must be a real durable workflow state, not a fake far-future schedule');
assert.match(control,/Pause requested — finishing current action/,'Executing work must use a safe pause-after-current-action request');
assert.match(control,/Only a paused workflow can be resumed/,'Resume must fail closed unless the run is actually paused');
assert.match(control,/next_attempt_at=\?/,'Rescheduling must reuse the Phase 19 durable timing field');
assert.match(control,/DateTimeImmutable/,'Rescheduling must parse owner-local time through an explicit timezone');
assert.match(control,/work_priority=\?/,'Priority changes must update the durable workflow row');
assert.match(control,/question=.*preg_match/,'Conversational questions about a control must not be mistaken for mutation commands');
assert.match(control,/agent_work_control_extract_run_id_v173/,'Mutating Chat commands must resolve an explicit workflow id');

assert.match(engine,/work_priority DESC/,'Durable claim ordering must honor user work priority');
assert.match(engine,/pause_requested_at IS NULL/,'A pending pause request must block new claims');
assert.match(engine,/Paused after current action/,'A leased action result must atomically honor a requested pause');
assert.match(engine,/Paused · retry waiting/,'A retryable failure must preserve retry timing while honoring pause');
assert.match(engine,/Expired lease recovered and the requested pause was honored/,'Lease recovery must not silently resume pause-requested work');

for (const action of ['pause','resume','reschedule','priority']) {
  assert.match(api,new RegExp(`'${action}'`),`Workflow API must expose ${action}`);
}
assert.match(api,/csrf_token/,'Workflow control API must keep CSRF protection');
assert.match(api,/agent_work_control_public_run_v173/,'Workflow API must expose control state through the canonical run representation');

assert.match(canonicalChat,/agent_work_control_chat_v173/,'Canonical Agent Chat must invoke Work Control directly');
assert.ok(canonicalChat.indexOf('agent_work_control_chat_v173($query') < canonicalChat.indexOf('user_calendar_agent_query_v1300($query'), 'Work Control must run before Calendar intent routing in canonical Agent Chat');
assert.match(sharedChat,/agent_work_control_chat_v173/,'Fallback Chat must share the same Agent Work Control boundary');
assert.ok(sharedChat.indexOf('agent_work_control_chat_v173') < sharedChat.indexOf('release_v105_schema_ready'), 'Fallback Work Control must run before release-specific tool routing');

assert.match(queue,/'paused'/,'Agent Chat Work Queue must expose a paused lane');
assert.match(queue,/data-agent-work-control=/,'Agent Chat must expose the Phase 17.3 control marker');
assert.match(queue,/(?:priority high|reprioritize)/,'The Chat queue must teach conversational priority control');
assert.match(queue,/(?:resume workflow|can resume)/,'The Chat queue must teach conversational resume control');
assert.match(queue,/(?:reschedule workflow|reschedule)/,'The Chat queue must teach conversational rescheduling');
assert.match(queue,/Workflow history/,'Detailed history remains available without becoming the command center');

assert.match(upgrade,/agent_work_control_ensure_schema_v173/,'The admin upgrade must install Phase 17.3 idempotently');
assert.match(upgrade,/require_permission\('users\.manage'\)/,'Only an administrator may run the schema upgrade');
assert.doesNotMatch(control,/DROP TABLE|TRUNCATE TABLE/i,'Work Control must not destructively replace the existing workflow ledger');
assert.doesNotMatch(control,/setInterval\s*\(/,'Work Control must not add a second polling runtime');

console.log('Agent Work Control v17.3 contract passed.');
