import fs from 'node:fs';
import assert from 'node:assert/strict';

const deps=fs.readFileSync('includes/agent-work-dependencies-v174.php','utf8');
const engine=fs.readFileSync('includes/agent-job-engine-v1900.php','utf8');
const shared=fs.readFileSync('includes/release-chat-v105.php','utf8');
const api=fs.readFileSync('api/agent-workflow-runs-v1400.php','utf8');
const queue=fs.readFileSync('includes/agent-chat-intelligence-v171.php','utf8');
const upgrade=fs.readFileSync('agent-work-dependencies-upgrade-v174.php','utf8');
const migration=fs.readFileSync('upgrade-agent-work-dependencies-v174.sql','utf8');

assert.match(deps,/VP3_AGENT_WORK_DEPENDENCIES_V174\s*=\s*'agent-work-dependencies-v174-20260915'/,'Phase 17.4 needs a stable build marker');
assert.match(deps,/agent_workflow_run_dependencies/,'Phase 17.4 must use a normalized cross-workflow dependency table');
assert.match(migration,/UNIQUE KEY uq_agent_workflow_run_dependency \(run_id,depends_on_run_id\)/,'Duplicate prerequisite edges must be impossible');
assert.match(migration,/owner_user_id INT UNSIGNED NOT NULL/,'Dependency edges must be owner scoped');
assert.match(deps,/Both workflows must belong to your account/,'Cross-owner dependencies must fail closed');
assert.match(deps,/A workflow cannot depend on itself/,'Self dependencies must be rejected');
assert.match(deps,/agent_work_dependency_cycle_v174/,'Dependency creation must perform cycle detection');
assert.match(deps,/That dependency would create a cycle/,'Cycle creation must fail closed');
assert.match(deps,/\$status==='executing'/,'Active executions must not have their graph changed underneath a lease');
assert.match(deps,/p\.status<>'completed'/,'Only completed prerequisites may unblock a dependent workflow');
assert.match(deps,/\['cloud','homeserver'\]/,'Delegation may only target existing Cloud/HomeServer authorities');
assert.match(deps,/UPDATE agent_workflow_runs SET execution_target=\?/,'Delegation must use the canonical run execution target');
assert.match(deps,/UPDATE agent_workflow_actions SET execution_target=\?/,'Delegation must retarget remaining action authority');
assert.match(deps,/status<>'completed'/,'Delegation must cover retryable unfinished actions, not only currently queued work');
assert.match(deps,/agent_workflow_event_v1400/,'Dependency and delegation mutations must stay in the workflow audit trail');
assert.match(deps,/agent_work_dependencies_chat_v174/,'Phase 17.4 must be conversationally controllable');
assert.match(deps,/delegate\|assign\|route\|move/,'Chat must understand delegation commands');
assert.match(deps,/dependency_removed/,'Chat/API dependency removal must be auditable');

assert.match(engine,/require_once __DIR__\.'\/agent-work-dependencies-v174\.php'/,'The durable engine must load the dependency authority itself');
assert.match(engine,/agent_work_dependencies_satisfied_v174\(\$pdo,\$uid,\$runId\)/,'Run claims must fail closed on unfinished prerequisites');
assert.ok(engine.indexOf('agent_work_dependencies_satisfied_v174($pdo,$uid,$runId)') < engine.indexOf("SELECT * FROM agent_workflow_actions WHERE run_id=?"),'Cross-workflow prerequisites must be checked before an action lease is claimed');
assert.match(engine,/agent_job_dependencies_satisfied_v1900/,'Existing Phase 19 action-level dependencies must remain intact');

assert.match(shared,/agent_work_dependencies_chat_v174/,'Shared Chat must expose Phase 17.4 without a new command center');
assert.ok(shared.indexOf('agent_work_dependencies_chat_v174') < shared.indexOf('release_v105_schema_ready'),'Dependency/delegation routing must happen before unrelated release routing');
for(const action of ['delegate','add_dependency','remove_dependency'])assert.match(api,new RegExp(`'${action}'`),`Workflow API must expose ${action}`);
assert.match(api,/csrf_token/,'Workflow mutation API must preserve CSRF protection');
assert.match(api,/agent_work_dependencies_state_v174/,'Workflow API must expose blockers and dependencies through the canonical run representation');

assert.match(queue,/VP3_AGENT_WORK_DEPENDENCIES_UI_V174/,'Agent Chat queue needs a Phase 17.4 UI marker');
assert.match(queue,/return 'blocked'/,'Blocked dependencies must be a first-class queue lane');
assert.match(queue,/'blocked'=>\['label'=>'Blocked'/,'Agent Chat must render the blocked lane directly in the existing Work Queue');
assert.match(queue,/data-agent-work-dependencies/,'The existing Work Queue must advertise dependency capability without a second dashboard');
assert.match(queue,/NOT \(\{\$blockedExpr\}\)/,'Blocked work must not be double-counted as active or scheduled');
assert.match(queue,/workflow_blocked/,'Blocked workflows must contribute to Agent Brief attention');
assert.match(queue,/delegate workflow #15 to HomeServer/,'Queue help must teach conversational delegation');
assert.match(queue,/show dependencies for workflow #15/,'Queue help must teach blocker inspection');

assert.match(upgrade,/agent_work_dependencies_ensure_schema_v174/,'The admin upgrade must install Phase 17.4 idempotently');
assert.match(upgrade,/require_permission\('users\.manage'\)/,'Only an administrator may run the Phase 17.4 schema upgrade');
assert.doesNotMatch(deps,/DROP TABLE|TRUNCATE TABLE/i,'Phase 17.4 must be additive');
assert.doesNotMatch(deps,/setInterval\s*\(/,'Phase 17.4 must not add a second polling runtime');
assert.equal(fs.existsSync('agent-work-dependencies.php'),false,'Phase 17.4 must not create a competing work dashboard');

console.log('Agent Work Delegation & Dependencies v17.4 contract passed.');
