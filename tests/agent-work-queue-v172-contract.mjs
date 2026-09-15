import fs from 'node:fs';
import assert from 'node:assert/strict';

const intelligence = fs.readFileSync('includes/agent-chat-intelligence-v171.php', 'utf8');
const chat = fs.readFileSync('chat.php', 'utf8');

assert.match(intelligence, /VP3_AGENT_WORK_QUEUE_V172\s*=\s*'agent-work-queue-v172-20260914'/, 'Phase 17.2 must expose a stable Agent Work Queue build marker');
assert.match(intelligence, /function vp3_agent_work_queue_model_v172\(/, 'Agent Work Queue must be modeled inside the integrated Chat intelligence provider');
assert.match(intelligence, /owner_user_id=\?/, 'Agent Work Queue reads must remain owner-scoped');
assert.match(intelligence, /status<>\'cancelled\'/, 'Cancelled work must not occupy an active queue lane');
assert.match(intelligence, /next_attempt_at/, 'Scheduled and retry work must reuse the durable job engine timing field');
assert.match(intelligence, /last_error_class/, 'Retry classification must reuse the durable job engine failure signal');
assert.match(intelligence, /progress_message/, 'Queue status must reuse durable job progress instead of inventing a second state store');
assert.match(intelligence, /approval_status<>\'pending\'/, 'Active and future lanes must exclude work that is waiting on approval');

for (const lane of ['active','approval','scheduled','failed_retry','completed']) {
  assert.match(intelligence, new RegExp(`'${lane}'`), `Queue must expose the ${lane} lane`);
}

assert.match(intelligence, /Agent Work Queue/, 'Queue must render directly in the Agent Brief');
assert.match(intelligence, /data-agent-work-queue=/, 'Queue must have an integrated Chat canvas marker');
assert.match(intelligence, /data-agent-intelligence-prompt=/, 'Queue item actions must flow through the canonical Chat composer');
assert.match(intelligence, /Do not execute before my explicit approval/, 'Approval review must preserve the explicit approval boundary');
assert.match(intelligence, /Workflow history/, 'The pre-existing workflow page may remain available as history/detail, not as a new command center');
assert.match(intelligence, /vp3_agent_work_queue_render_v172\(\$workQueue\)/, 'Queue must be rendered inside the existing Agent Brief body');
assert.match(chat, /agent-chat-intelligence-v171\.php/, 'Phase 17.2 must stay inside the canonical Agent Chat intelligence integration');
assert.doesNotMatch(chat, /agent-work-queue\.php/, 'Phase 17.2 must not create or route Chat to a standalone Agent Work Queue page');
assert.doesNotMatch(intelligence, /CREATE TABLE|ALTER TABLE/i, 'Phase 17.2 must not introduce a parallel work-queue schema');
assert.doesNotMatch(intelligence, /setInterval\s*\(/, 'Server-rendered queue must not add a parallel polling runtime');

console.log('Agent Work Queue v17.2 contract passed.');
