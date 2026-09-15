import fs from 'node:fs';
import assert from 'node:assert/strict';

const memory=fs.readFileSync('includes/agent-objective-memory-v177.php','utf8');
const chat=fs.readFileSync('includes/release-chat-v105.php','utf8');
const objective=fs.readFileSync('includes/agent-objective-plans-v175.php','utf8');
const verification=fs.readFileSync('includes/agent-objective-verification-v176.php','utf8');
const engine=fs.readFileSync('includes/agent-job-engine-v1900.php','utf8');
const upgrade=fs.readFileSync('upgrade.php','utf8');

assert.match(memory,/VP3_AGENT_OBJECTIVE_MEMORY_V177\s*=\s*'agent-objective-memory-v177-20260915'/,'Phase 17.7 needs a stable build marker');
assert.match(memory,/CREATE TABLE IF NOT EXISTS agent_objective_outcome_memory/,'Objective learning needs a derived outcome-memory table');
assert.match(memory,/UNIQUE KEY uq_agent_objective_memory_source \(owner_user_id,source_objective_run_id\)/,'Learning capture must be idempotent per owner/source objective');
assert.match(memory,/WHERE id=\? AND owner_user_id=\?/,'Memory lookup must be owner scoped');
assert.match(memory,/WHERE source_objective_run_id=\? AND owner_user_id=\?/,'Source-objective lookup must be owner scoped');
assert.match(memory,/owner_user_id=\?/,'Learning sync and retrieval must remain owner scoped');
assert.doesNotMatch(memory,/DROP TABLE|TRUNCATE TABLE/i,'Phase 17.7 schema changes must be additive');

assert.match(memory,/agent_objective_memory_template_v177/,'Learning must derive a reusable template from canonical objective history');
assert.match(memory,/agent_objective_children_rows_v175/,'Learning must read canonical 17.5 child workflows rather than duplicate live state');
assert.match(memory,/source_key.*:s.*t/s,'Relative objective stage/task structure must drive the learned template');
assert.match(memory,/execution_target_preference/,'Cloud/HomeServer routing may be learned as a preference');
assert.match(memory,/observed_risk_level/,'Observed risk shape should be available as learning context');
assert.match(memory,/observed_requires_approval/,'Observed approval shape should be available as learning context');
assert.match(memory,/objective_success_criteria/,'Verified success criteria must be retained as learned context');
assert.match(memory,/remediation_lessons/,'Remediation should be retained as learning evidence');
assert.doesNotMatch(memory,/INSERT INTO agent_objective_outcome_memory[^;]*(?:receipt|lease|approval_status|result_json)/is,'Derived memory must not persist canonical execution receipts, leases, approval decisions, or result payloads');

assert.match(memory,/objective_verification_status='achieved'/,'Only verified achievements qualify as positive outcome memory');
assert.match(memory,/status IN \('failed','cancelled'\)/,'Failed/cancelled objectives should be retained as negative evidence');
assert.match(memory,/\$outcome==='failed'\?0\.20/,'Failed outcomes must be materially down-ranked');
assert.match(memory,/\$outcome==='remediated'\?0\.88/,'Remediated success must rank below clean success');
assert.match(memory,/outcome_score/,'Outcome quality must contribute to retrieval ranking');
assert.match(memory,/similarity_score/,'Similar prior objectives need deterministic ranking');

assert.match(memory,/agent_objective_memory_sync_recent_v177/,'Learning must be rebuildable from canonical history');
assert.match(memory,/ON DUPLICATE KEY UPDATE/,'Repeated learning sync must update rather than duplicate memory');
assert.match(memory,/agent_objective_memory_similar_v177/,'Agent Chat needs similar-objective retrieval');
assert.match(memory,/agent_objective_memory_reuse_v177/,'Successful learned plans must support explicit reuse');
assert.match(memory,/in_array\(\(string\)\(\$memory\['outcome_status'\].*\['achieved','remediated'\]/s,'Failed memory must never be replayable as a plan');
assert.match(memory,/agent_objective_create_v175\(/,'Reuse must create fresh canonical 17.5 objective/workflow records');
assert.match(objective,/agent_action_v124_plan/,'Fresh 17.5 child creation must retain the current risk/approval planner');
assert.match(memory,/agent_objective_target_v175/,'Stored execution targets must be normalized against the current execution boundary');
assert.match(memory,/historical execution state was not copied|historical terminal state/,'Chat must explain the non-replay execution boundary');
assert.match(memory,/objective_memory_reused/,'Plan reuse must leave an auditable canonical workflow event');
assert.match(memory,/reuse_count=reuse_count\+1/,'Learned-plan reuse should improve future evidence about useful patterns');

for(const phrase of ['objective memory','similar objectives','reuse objective','best past plan'])assert.match(memory,new RegExp(phrase.replace(' ','\\s+'),'i'),`Agent Chat must recognize ${phrase}`);
assert.match(chat,/require_once __DIR__\.'\/agent-objective-memory-v177\.php'/,'Shared Agent Chat must load Phase 17.7');
assert.match(chat,/agent_objective_memory_chat_v177\(\$query,\$user,\$conversationId\)/,'Agent Chat must route explicit memory commands through Phase 17.7');
assert.ok(chat.indexOf('agent_objective_memory_chat_v177')<chat.indexOf('agent_objective_chat_v175'),'Memory reuse/search must be resolved before generic objective-create routing');

assert.match(verification,/agent_objective_verification_after_result_v176/,'Phase 17.6 verification remains authoritative for outcome truth');
assert.match(engine,/agent_objective_verification_after_result_v176/,'Phase 19 must remain the receipt-to-verification execution path');
assert.doesNotMatch(memory,/agent_job_claim|lease_expires|worker_id/,'Phase 17.7 must not create a competing worker or lease path');
assert.doesNotMatch(memory,/setInterval\s*\(/,'Phase 17.7 must not add browser polling');
assert.equal(fs.existsSync('agent-objective-memory.php'),false,'Phase 17.7 must not add a competing objective-memory dashboard');

assert.match(upgrade,/require_once __DIR__ \. '\/includes\/agent-objective-memory-v177\.php'/,'Central upgrade must load Phase 17.7');
assert.match(upgrade,/agent_objective_memory_schema_ready_v177\(\)/,'Central upgrade completeness must include Phase 17.7');
assert.match(upgrade,/agent_objective_memory_ensure_schema_v177\(\$pdo\)/,'Normal Run Upgrade must install Phase 17.7');
assert.match(upgrade,/Objective Memory \+ Outcome Learning/,'Upgrade UI must describe Phase 17.7');

console.log('Agent Objective Memory + Outcome Learning v17.7 contract passed.');
