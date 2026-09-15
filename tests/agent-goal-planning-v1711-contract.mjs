import fs from 'node:fs';
import assert from 'node:assert/strict';

const plan=fs.readFileSync('includes/agent-goal-planning-v1711.php','utf8');
const goal=fs.readFileSync('includes/agent-goal-strategy-v1710.php','utf8');
const chat=fs.readFileSync('includes/release-chat-v105.php','utf8');
const upgrade=fs.readFileSync('upgrade.php','utf8');

assert.match(plan,/agent-goal-planning-v1711-20260915/,'stable Phase 17.11 marker is required');
assert.match(plan,/CREATE TABLE IF NOT EXISTS agent_goal_milestones/,'durable roadmap milestones are required');
assert.match(plan,/objective_run_id BIGINT UNSIGNED NULL/,'milestones must optionally link to canonical objectives');
assert.match(plan,/agent_goal_milestone_state_v1711/,'milestone state must be derived centrally');
assert.match(plan,/objective_verification_status.*achieved/s,'milestone achievement must derive from Phase 17.6 verified objective outcomes');
assert.match(plan,/return 'advisory'/,'unconverted milestones must remain advisory');
assert.match(plan,/projected_date/,'goal plans must project an ordered path');
assert.match(plan,/remaining_count/,'goal plans must expose remaining roadmap work');
assert.match(plan,/health.*stalled/s,'Phase 17.11 must expose plan health and stalled state');
assert.match(plan,/plan_missing|awaiting_objective|blocked|waiting_approval|review_needed/,'stalled-plan states must be explicit');
assert.match(plan,/agent_goal_plan_seed_v1711/,'users must be able to explicitly generate a roadmap');
assert.match(plan,/agent_objective_heuristic_stages_v175/,'roadmap generation may reuse canonical objective decomposition heuristics');
assert.match(plan,/agent_objective_create_v175/,'milestone execution must create a fresh canonical Phase 17.5 objective');
assert.match(plan,/agent_goal_link_objective_v1710/,'fresh milestone objectives must enter the canonical Goal → Objective hierarchy');
assert.match(plan,/Attach that objective to the goal before linking it to a milestone/,'existing objectives cannot bypass the goal hierarchy');
assert.match(plan,/agent_goal_plan_resequence_v1711/,'roadmaps must support explicit sequencing changes');
assert.match(plan,/without changing objective execution/,'roadmap sequencing must not mutate execution state');
assert.match(plan,/agent_goal_plan_retire_milestone_v1711/,'users must be able to retire obsolete advisory milestones');
assert.match(plan,/Linked objective execution, approvals, and receipts were not changed/,'milestone retirement must preserve execution history');
assert.match(plan,/agent_goal_plan_suggested_strategy_v1711/,'adaptive strategy must be evidence driven');
assert.match(plan,/Use similar verified outcome history as evidence/,'learned verified outcomes must inform revisions');
assert.match(plan,/agent_goal_plan_revise_strategy_v1711/,'strategy revision must be an explicit user action');
assert.match(plan,/adaptive_strategy_revised/,'strategy revisions must be auditable in the existing goal event ledger');
assert.doesNotMatch(plan,/UPDATE\s+agent_workflow_runs\s+SET\s+status|UPDATE\s+agent_workflow_actions\s+SET\s+status/i,'roadmap layer must not directly mutate canonical execution status');
assert.doesNotMatch(plan,/lease_owner|lease_expires|receipt_json|result_json|next_attempt_at/,'roadmap layer must not own Phase 19 worker state');
assert.doesNotMatch(plan,/UPDATE\s+agent_goal_milestones[\s\S]{0,180}SET[\s\S]{0,120}status\s*=\s*['\"]achieved['\"]/i,'milestones must not have a manual achieved mutation');
assert.doesNotMatch(plan,/setInterval\s*\(/,'goal planning must not introduce browser-style polling');

assert.match(chat,/require_once __DIR__\.\'\/agent-goal-planning-v1711\.php\'/,'shared Chat boundary must load Phase 17.11');
assert.match(chat,/agent_goal_plan_chat_v1711\(\$query,\$user,\$conversationId\)/,'Agent Chat must route roadmap language through Phase 17.11');
assert.ok(chat.indexOf('agent_goal_plan_chat_v1711') < chat.indexOf('agent_goal_chat_v1710'),'roadmap routing must occur before generic goal routing');
assert.match(upgrade,/agent_goal_planning_schema_ready_v1711\(\)/,'central upgrade readiness must include Phase 17.11');
assert.match(upgrade,/agent_goal_planning_ensure_schema_v1711\(\$pdo\)/,'normal Run Upgrade must install Phase 17.11');
assert.match(upgrade,/advisory goal roadmaps\/milestones/,'upgrade copy must promise preservation of roadmap data');
assert.match(upgrade,/Goals \+ Strategy \+ Adaptive Roadmaps/,'upgrade UI must identify the new capability');
assert.match(goal,/Goal → Objectives → Workflows → Actions|Goals, Strategy & Objective Hierarchy/s,'Phase 17.10 canonical hierarchy must remain present');

console.log('Agent Goal Planning v17.11 contract passed.');
