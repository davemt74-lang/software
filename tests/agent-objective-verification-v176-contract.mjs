import fs from 'node:fs';
import assert from 'node:assert/strict';

const verification=fs.readFileSync('includes/agent-objective-verification-v176.php','utf8');
const objective=fs.readFileSync('includes/agent-objective-plans-v175.php','utf8');
const engine=fs.readFileSync('includes/agent-job-engine-v1900.php','utf8');
const upgrade=fs.readFileSync('upgrade.php','utf8');

assert.match(verification,/VP3_AGENT_OBJECTIVE_VERIFICATION_V176\s*=\s*'agent-objective-verification-v176-20260915'/,'Phase 17.6 needs a stable build marker');
assert.match(verification,/objective_verification_status/,'Verification state must live on the canonical workflow run');
assert.match(verification,/objective_success_criteria/,'Objectives need durable success criteria');
assert.match(verification,/objective_verification_summary/,'Verification needs a durable summary');
assert.match(verification,/objective_verification_evidence/,'Verification evidence must be durable');
assert.match(verification,/objective_verified_at/,'Achievement needs a verification timestamp');
assert.match(verification,/objective_remediation_count/,'Adaptive replanning must track bounded remediation cycles');
assert.doesNotMatch(verification,/CREATE TABLE/i,'Phase 17.6 must not introduce a parallel objective table');
assert.doesNotMatch(verification,/DROP TABLE|TRUNCATE TABLE/i,'Phase 17.6 schema changes must be additive');

assert.match(verification,/agent_objective_success_criteria_v176/,'Objective creation must define measurable success criteria');
assert.match(verification,/Every planned child workflow reaches completed with receipt-backed execution evidence/,'Receipt-backed completion must be a default success criterion');
assert.match(verification,/objective_achieved/,'Verification execution must return an explicit achievement signal');
assert.match(verification,/If evidence is insufficient, objective_achieved must be false/,'Verification must fail closed on insufficient evidence');
assert.match(verification,/agent_objective_verification_signal_v176/,'Receipt results must be interpreted as objective verification evidence');
assert.match(verification,/return null/,'Missing verification signals must not be treated as success');
assert.match(verification,/agent_objective_verification_after_result_v176/,'Receipt completion must feed objective verification');
assert.match(verification,/objective_achieved','completed','completed'/,'Verified objectives need an auditable achieved event');
assert.match(verification,/objective_remediation_queued/,'Unmet criteria must create an auditable remediation event');

assert.match(verification,/agent_objective_insert_run_v175/,'Remediation must reuse canonical child workflow creation');
assert.match(verification,/agent_objective_insert_dependency_v175/,'Remediation must reuse the existing cross-workflow dependency graph');
assert.match(verification,/agent_action_v124_plan|agent_objective_insert_run_v175/s,'Remediation children must retain the canonical risk and approval planner');
assert.match(verification,/status='approved'.*Needs remediation/s,'A completed objective must be reopenable when verification fails');
assert.match(verification,/agent_objective_verification_add_review_action_v176/,'Remediation must queue a new verification action instead of reusing a completed receipt');
assert.match(verification,/agent_objective_verification_rewire_failed_v176/,'Failed objective work must support targeted replacement');
assert.match(verification,/DELETE FROM agent_workflow_run_dependencies/,'Affected dependency edges must be rewired away from replaced failed work');
assert.match(verification,/replacement_run_id/,'Failed work history must point to its replacement');
assert.match(verification,/status==='completed'|status\]==='completed'/,'Completed dependent work must be preserved rather than rewritten');

for(const phrase of ['verify','repair','replan'])assert.match(verification,new RegExp(`objective\\.${phrase}`),`Agent Chat needs an audited objective.${phrase} tool path`);
assert.match(verification,/why|blocking|complete/,'Agent Chat must explain why an objective is not achieved');
assert.match(verification,/agent_objective_verification_answer_v176/,'Objective verification needs a conversational status surface');
assert.match(verification,/Achieved/,'Chat must surface Achieved state');
assert.match(verification,/Needs remediation/,'Chat must surface Needs remediation state');
assert.match(verification,/Verifying/,'Chat must surface Verifying state');

assert.match(objective,/agent_objective_verification_initialize_v176/,'New Phase 17.5 objectives must initialize Phase 17.6 verification criteria');
assert.match(objective,/agent_objective_verification_public_fields_v176/,'Objective status must expose Phase 17.6 verification state');
assert.match(objective,/agent_objective_verification_chat_v176/,'Existing Agent Chat objective routing must expose Phase 17.6 without a second command center');
assert.match(objective,/objective_remediation/,'Remediation children must remain visible as objective children');

assert.match(engine,/require_once __DIR__\.'\/agent-objective-verification-v176\.php'/,'The durable worker runtime must load Phase 17.6');
assert.match(engine,/agent_objective_verification_after_result_v176/,'Phase 19 receipt commits must invoke objective verification');
assert.match(engine,/if\(!empty\(\$verification\['reopened'\]\)\)\$terminal=''/,'Reopened objectives must not be recorded as successful terminal outcomes');
assert.match(engine,/agent_work_dependencies_satisfied_v174/,'Phase 17.4 run prerequisites must remain enforced before claims');
assert.match(engine,/agent_job_dependencies_satisfied_v1900/,'Phase 19 action dependencies must remain enforced');

assert.match(upgrade,/require_once __DIR__ \. '\/includes\/agent-objective-verification-v176\.php'/,'Central upgrade must load Phase 17.6');
assert.match(upgrade,/agent_objective_verification_schema_ready_v176\(\)/,'Central upgrade completeness must include Phase 17.6');
assert.match(upgrade,/agent_objective_verification_ensure_schema_v176\(\$pdo\)/,'Normal Run Upgrade must install Phase 17.6');
assert.match(upgrade,/Objective Verification \+ Adaptive Replanning/,'Upgrade UI must describe Phase 17.6');

assert.doesNotMatch(verification,/setInterval\s*\(/,'Phase 17.6 must not add browser polling');
assert.equal(fs.existsSync('agent-objective-verification.php'),false,'Phase 17.6 must not add a competing objective dashboard');

console.log('Agent Objective Verification + Adaptive Replanning v17.6 contract passed.');
