import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const api = read('api/chat-notifications-brain-v240.php');
const ui = read('chat-notifications-drawer-v240.js');
const css = read('chat-notifications-drawer-v240.css');
const outcomes = read('includes/agent-action-system-v124.php');

assert.doesNotThrow(() => new Function(ui), 'Activity Center with Brain outcome controls must remain valid JavaScript');

/* Current cognitive priorities become visible in the existing Agent Brain drawer. */
assert.ok(api.includes('function chat_notifications_v313_brain_priorities('), 'Activity Center must project current canonical Brain priorities');
assert.ok(api.includes('agent_cognitive_loop_v310_state($user)'), 'priority UI must read the canonical cognitive state');
assert.ok(api.includes('agent_cognitive_loop_v310_state_fresh($state)'), 'stale cognitive priorities must not remain actionable');
assert.ok(api.includes("'priorities'=>$brainAllowed ? chat_notifications_v313_brain_priorities($user, $pdo) : []"), 'Brain state response must include current priorities');
assert.ok(ui.includes('<strong>Current Priorities</strong>'), 'Agent Brain drawer must visibly expose Current Priorities');
assert.ok(ui.includes('ranked by Agent Brain') || ui.includes('Ranked by Agent Brain'), 'priority UI must identify Agent Brain as the ranking authority');
assert.ok(ui.includes('learned factor'), 'priority cards must expose the learned outcome factor');
assert.ok(ui.includes('brainPriorityMovement'), 'priority cards must explain rank/score movement');
assert.ok(ui.includes('requires_approval'), 'priority cards must expose approval requirements');
assert.ok(ui.includes('risk-'), 'priority cards must expose risk state');

/* Closure uses the same v313 outcome learner and never creates another persistence layer. */
assert.ok(api.includes("if ($action === 'brain_outcome')"), 'Activity Center endpoint must own a Brain outcome action');
assert.ok(api.includes('agent_action_v124_record_outcome('), 'Brain controls must call the canonical Outcome Closure writer');
assert.ok(api.includes("'surface'=>'activity_center'"), 'Outcome provenance must identify Activity Center');
assert.ok(api.includes("'successful','resolved','unsuccessful','ignored'"), 'server must restrict UI closure to final outcomes');
assert.ok(!/CREATE TABLE|ALTER TABLE/.test(api), 'Activity Center closure must not create a second outcome store');
assert.ok(outcomes.includes("$context['outcome']=$outcome;"), 'canonical Outcome Closure must remain the persistence authority');

/* The server, not browser-supplied display text, authorizes exact priority identity. */
assert.ok(api.includes('function chat_notifications_v313_base_priority_hash_map('), 'server must resolve current proactive priority hashes from canonical candidates');
assert.ok(api.includes('agent_cognitive_loop_v310_base_candidates($user, $context)'), 'proactive hashes must come from the same current candidate builder used by Agent Brain');
assert.ok(api.includes("preg_match('/^[a-f0-9]{40}$/', $hash)"), 'only exact canonical suggestion hashes may enter the server-side map');
assert.ok(api.includes('function chat_notifications_v313_priority_outcome_hash('), 'server must map Brain identity to canonical suggestion identity');
assert.ok(api.includes("preg_match('/^notification:(\\d+)$/'"), 'notification priority hashes must be deterministically derived server-side');
assert.ok(api.includes("$source === 'analytics'"), 'Analytics priority hashes must be derived server-side');
assert.ok(api.includes("!str_ends_with($title, '…')"), 'truncated Analytics display titles must never be used to reconstruct an outcome identity');
assert.ok(api.includes("$mapped = strtolower(trim((string)($baseHashes[$key] ?? '')));"), 'proactive identities must prefer the exact current server candidate map');
assert.ok(api.includes('foreach (chat_notifications_v313_brain_priorities($user, $pdo) as $candidate)'), 'submitted hash must be checked against current owner-visible priorities');
assert.ok(api.includes("$candidateHash !== '' && hash_equals($candidateHash, $hash)"), 'server must reject empty/ambiguous identities and exact-match the current priority hash');
assert.ok(api.includes('That priority is no longer active.'), 'stale or arbitrary priority identities must be rejected');
assert.ok(!api.includes("(string)($input['source']"), 'server must not trust browser-supplied source weighting metadata');

/* Latest outcome lookup is batched across the visible priority set. */
assert.ok(api.includes('function chat_notifications_v313_priority_outcome_map('), 'Activity Center must batch latest outcome lookup');
assert.ok(api.includes("suggestion_hash IN ({$placeholders})"), 'one query must load outcome state for visible priorities');
assert.ok(api.includes("event_type IN ('acted','dismissed')"), 'outcome display must read the canonical event vocabulary');
assert.ok(api.includes("'outcome_at'"), 'priority cards must retain when the latest outcome was recorded');

/* UI gives the four requested closure choices and prevents casual contradictory feedback. */
for (const [value,label] of [['successful','Worked'],['resolved','Resolved'],['unsuccessful',"Didn't work"],['ignored','Ignore']]) {
  assert.ok(ui.includes(`data-brain-outcome=\"${value}\"`), `Brain priority must expose ${label} control`);
  assert.ok(ui.includes(`>${label}</button>`), `Brain priority must visibly label ${label}`);
}
assert.ok(ui.includes("const finalOutcomes = ['successful','resolved','unsuccessful','ignored'];"), 'client must recognize final closure states');
assert.ok(ui.includes("const canClose = /^[a-f0-9]{40}$/.test(hash) && !closed;"), 'buttons must disappear after a final outcome or invalid identity');
assert.ok(ui.includes('Outcome controls are unavailable for this priority type.'), 'ambiguous identity must fail closed in the UI');
assert.ok(ui.includes('Recorded:'), 'closed priorities must visibly show the recorded result');
assert.ok(ui.includes("void mutate('brain_outcome', {hash, outcome});"), 'buttons must post through the existing Activity Center CSRF request path');
assert.ok(api.includes('This priority already has a final outcome.'), 'server must reject contradictory final outcomes');

/* Dedicated styling keeps the controls usable on desktop and narrow mobile layouts. */
assert.ok(css.includes('.chat-brain-priority-list'), 'priority list must be styled');
assert.ok(css.includes('.chat-brain-priority-actions'), 'outcome actions must be styled');
assert.ok(css.includes('.chat-brain-priority-outcome.recorded'), 'recorded closure state must be styled');
assert.ok(css.includes('.chat-brain-priority-actions{grid-template-columns:1fr 1fr}'), 'small screens must collapse outcome controls to two columns');

console.log('AGENT_BRAIN_OUTCOME_CONTROLS_CONTRACT=PASS');
