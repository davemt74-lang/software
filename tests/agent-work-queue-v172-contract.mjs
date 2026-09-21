import fs from 'node:fs';
import assert from 'node:assert/strict';

const intelligence = fs.readFileSync('includes/agent-chat-intelligence-v171.php', 'utf8');
const presentation = fs.readFileSync('includes/cognitive-presentation-v510.php', 'utf8');
const presentationJs = fs.readFileSync('chat-cognitive-presentation-v510.js', 'utf8');
const chat = fs.readFileSync('chat.php', 'utf8');

assert.match(intelligence, /VP3_AGENT_WORK_QUEUE_V172\s*=\s*'agent-work-queue-v172-20260914'/, 'Phase 17.2 must expose a stable Agent Work Queue build marker');
assert.match(intelligence, /function vp3_agent_work_queue_model_v172\(/, 'Agent Work Queue must remain modeled inside the canonical intelligence provider');
assert.match(intelligence, /owner_user_id=\?/, 'Agent Work Queue reads must remain owner-scoped');
assert.match(intelligence, /status<>\'cancelled\'/, 'Cancelled work must not occupy an active queue lane');
assert.match(intelligence, /next_attempt_at/, 'Scheduled and retry work must reuse the durable job engine timing field');
assert.match(intelligence, /last_error_class/, 'Retry classification must reuse the durable job engine failure signal');
assert.match(intelligence, /progress_message/, 'Queue status must reuse durable job progress instead of inventing a second state store');
assert.match(intelligence, /updated_at_utc/, 'Queue must preserve raw canonical update time for cognitive ordering');
assert.match(intelligence, /next_attempt_at_utc/, 'Queue must preserve raw canonical schedule time for cognitive fingerprints');
assert.match(intelligence, /approval_status<>\'pending\'/, 'Active and future lanes must exclude work that is waiting on approval');

for (const lane of ['active','approval','scheduled','failed_retry','completed']) {
  assert.match(intelligence, new RegExp(`'${lane}'`), `Queue must expose the ${lane} lane`);
}

assert.match(presentation, /vp3_agent_chat_intelligence_model_v171\(/, 'Cognitive Presentation must consume the canonical intelligence model');
assert.match(presentation, /\$queue=is_array\(\$model\['work_queue'\]/, 'footer Brief must read the canonical Agent Work Queue');
assert.match(presentation, /foreach\(\['active','approval','blocked','scheduled','failed_retry'\] as \$lane\)/, 'footer Brief must prioritize actionable work lanes');
assert.match(presentation, /'current_work'=>\$current/, 'footer Brief state must expose the current work item');
assert.match(presentationJs, /Current work/, 'Agent Brief popup must render current work');
assert.match(presentationJs, /\/agent-workflows\.php/, 'current work must retain the existing workflow history/detail destination');
assert.match(chat, /chatAgentBriefPopover/, 'work presentation must live in the new Agent Brief popup');
assert.doesNotMatch(chat, /vp3_agent_work_queue_render_v172\(/, 'Chat shell must not directly render the legacy queue canvas');
assert.doesNotMatch(chat, /agent-work-queue\.php/, 'Phase 17.2 must not create or route Chat to a standalone Agent Work Queue page');
assert.doesNotMatch(intelligence, /CREATE TABLE|ALTER TABLE/i, 'Phase 17.2 must not introduce a parallel work-queue schema');
assert.doesNotMatch(intelligence, /setInterval\s*\(/, 'canonical queue provider must not add a parallel polling runtime');

console.log('Agent Work Queue v17.2 compatibility with Cognitive Presentation v5.10 passed.');
