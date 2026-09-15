import fs from 'node:fs';
import assert from 'node:assert/strict';

const objective=fs.readFileSync('includes/agent-objective-plans-v175.php','utf8');
const shared=fs.readFileSync('includes/release-chat-v105.php','utf8');
const dependencies=fs.readFileSync('includes/agent-work-dependencies-v174.php','utf8');
const control=fs.readFileSync('includes/agent-work-control-v173.php','utf8');
const engine=fs.readFileSync('includes/agent-job-engine-v1900.php','utf8');

assert.match(objective,/VP3_AGENT_OBJECTIVE_PLANS_V175\s*=\s*'agent-objective-plans-v175-20260915'/,'Phase 17.5 needs a stable build marker');
assert.match(objective,/agent_workflow_schema_ready_v1400/,'Objectives must reuse the canonical workflow ledger');
assert.match(objective,/agent_job_engine_schema_ready_v1900/,'Objectives must require the durable Phase 19 engine');
assert.match(objective,/agent_work_dependencies_schema_ready_v174/,'Objectives must require the Phase 17.4 dependency graph');
assert.match(objective,/agent_work_control_schema_ready_v173/,'Objectives must reuse Phase 17.3 work controls');
assert.doesNotMatch(objective,/CREATE TABLE|ALTER TABLE|DROP TABLE|TRUNCATE TABLE/i,'Phase 17.5 must not create a parallel objective schema');

assert.match(objective,/agent_action_v124_plan/,'Each child workflow must inherit the canonical risk and approval planner');
assert.match(objective,/requires_approval/,'Per-workflow approval state must be preserved');
assert.match(objective,/approval_pending/,'Protected child workflows must wait for approval');
assert.match(objective,/agent_workflow_runs/,'Objective parents and children must be normal durable workflow runs');
assert.match(objective,/agent_workflow_actions/,'Objective child work must use the canonical action ledger');
assert.match(objective,/'agent_objective_plan'/,'The objective parent must be identifiable in the canonical run ledger');
assert.match(objective,/'agent_objective_step'/,'Objective children must be identifiable in the canonical run ledger');
assert.match(objective,/'objective_plan'/,'Objective parents must use source_kind grouping');
assert.match(objective,/'objective_step'/,'Objective children must use source_kind grouping');
assert.match(objective,/source_hash/,'Objective membership must use an existing durable grouping field');

assert.match(objective,/beginTransaction\(\)/,'Objective decomposition must install runs and graph atomically');
assert.match(objective,/agent_workflow_run_dependencies/,'Objective stages must use the existing Phase 17.4 dependency table');
assert.match(objective,/objective_v175/,'Objective-created dependency edges must be attributable');
assert.match(objective,/agent_objective_insert_dependency_v175\(\$pdo,\$uid,\$runId,\$prerequisite,'stage'\)/,'Child workflows must support stage-to-stage prerequisites');
assert.match(objective,/agent_objective_insert_dependency_v175\(\$pdo,\$uid,\$parentId,\$childId,'objective'\)/,'The objective parent must wait for child workflows');
assert.match(objective,/foreach\(\$allRuns as \$childId\)agent_objective_insert_dependency_v175/,'The objective parent must depend on every child run so remaining work is visible');
assert.match(objective,/foreach\(\$stageRuns\[\$stage\] as \$runId\)foreach\(\$stageRuns\[\$stage-1\] as \$prerequisite\)/,'Every run in a stage must wait for the prior stage');
assert.match(objective,/\$run=agent_workflow_row_v1400\(\$pdo,\$uid,\$runId\)/,'Dependency audit events must inspect the actual durable run state');
assert.match(objective,/dependency_added',\$status,\$status/,'Dependency audit events must preserve the run’s real status');
assert.doesNotMatch(objective,/approval_not_required','planning'/,'Objective audit history must not invent a planning transition that never occurred');

assert.match(objective,/homeserver.*cloud|cloud.*homeserver/s,'Objective work must retain Cloud/HomeServer execution authority');
assert.match(objective,/agent_work_delegate_v174/,'Objective delegation must reuse Phase 17.4 delegation');
assert.match(objective,/agent_work_control_pause_v173/,'Objective pause must reuse Phase 17.3 work control');
assert.match(objective,/agent_work_control_resume_v173/,'Objective resume must reuse Phase 17.3 work control');
assert.match(objective,/agent_work_control_cancel_v173/,'Objective cancel must reuse Phase 17.3 work control');
assert.match(objective,/agent_work_control_priority_v173/,'Objective priority changes must reuse Phase 17.3 work control');

assert.match(objective,/agent_objective_heuristic_stages_v175/,'Agent Chat must be able to decompose an objective without supplied steps');
assert.match(objective,/agent_objective_explicit_stages_v175/,'Users must be able to provide an explicit workflow sequence');
assert.match(objective,/->|→/,'Explicit objective plans must support sequential stages');
assert.match(objective,/parallel/i,'Explicit objective plans must support parallel work within a stage');
assert.match(objective,/progress_percent/,'Objective inspection must report objective-level progress');
assert.match(objective,/waiting approval/,'Objective inspection must surface approval blockers');
assert.match(objective,/failed/,'Objective inspection must surface failed children');
assert.match(objective,/paused/,'Objective inspection must surface paused children');

for(const command of ['pause','resume','cancel','priority','delegate'])assert.match(objective,new RegExp(`agent_objective_${command}_v175`),`Objective plans must support ${command}`);
assert.match(objective,/agent_objective_extract_step_ordinal_v175/,'Objective delegation must parse a child step independently from the objective id and target');
assert.ok(objective.includes("preg_match('/\\bstep\\s*#?\\s*(\\d+)\\b/i'"),'The advertised step-number syntax must remain recognized');
assert.match(objective,/\(HomeServer\|Home Server\|Cloud\|VP3 Cloud\)/,'Objective delegation must independently parse the execution target');
assert.match(objective,/agent_objective_state_v175/,'Objective Chat must expose objective status and blockers');
assert.match(objective,/objective\.create/,'Objective creation must remain in the Agent tool audit log');
assert.match(objective,/objective\.control/,'Objective controls must remain in the Agent tool audit log');

assert.match(shared,/require_once __DIR__\.'\/agent-objective-plans-v175\.php'/,'Shared Chat must load Phase 17.5');
assert.match(shared,/agent_objective_chat_v175/,'Shared Chat must route objective commands');
assert.ok(shared.indexOf('agent_objective_chat_v175') < shared.indexOf('agent_work_dependencies_chat_v174'),'Objective routing must happen before single-workflow dependency routing');
assert.ok(shared.indexOf('agent_objective_chat_v175') < shared.indexOf('agent_work_control_chat_v173'),'Objective routing must happen before single-workflow controls');
assert.ok(shared.indexOf('agent_objective_chat_v175') < shared.indexOf('release_v105_schema_ready'),'Objective routing must happen before release-specific intent handling');

assert.match(dependencies,/agent_work_dependencies_satisfied_v174/,'Phase 17.4 prerequisite eligibility must remain intact');
assert.match(engine,/agent_work_dependencies_satisfied_v174/,'Phase 19 must still enforce prerequisite completion before claims');
assert.match(engine,/agent_job_dependencies_satisfied_v1900/,'Existing action-level dependencies must remain intact');
assert.doesNotMatch(objective,/setInterval\s*\(/,'Phase 17.5 must not add a browser polling loop');
assert.equal(fs.existsSync('agent-objectives.php'),false,'Phase 17.5 must not add a competing objective dashboard');
assert.equal(fs.existsSync('upgrade-agent-objective-plans-v175.sql'),false,'Phase 17.5 should require no new SQL migration');

console.log('Agent Objective Decomposition + Multi-Workflow Plans v17.5 contract passed.');
