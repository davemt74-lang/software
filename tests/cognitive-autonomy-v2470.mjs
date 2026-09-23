import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const bootstrap=read('includes/bootstrap.php');
const autonomy=read('includes/cognitive-autonomy-v2470.php');
const release=read('includes/cognitive-release-v2470.php');
const goals=read('includes/agent-goal-strategy-v1710.php');
const goalPlan=read('includes/agent-goal-planning-v1711.php');
const goalExecution=read('includes/agent-goal-execution-v1712.php');
const objectives=read('includes/agent-objective-plans-v175.php');
const verification=read('includes/agent-objective-verification-v176.php');
const jobs=read('includes/agent-job-engine-v1900.php');
const workers=read('includes/agent-worker-runtime-v1910.php');
const context=read('includes/cognitive-context-v2420.php');
const loop=read('includes/agent-cognitive-loop-v310.php');
const presentation=read('includes/cognitive-presentation-v510.php');
const proactive=read('includes/cognitive-proactive-now-v2340.php');
const presentationJs=read('chat-cognitive-presentation-v510.js');
const drawerApi=read('api/chat-notifications-brain-v240.php');
const drawerJs=read('chat-notifications-drawer-v240.js');
const chat=read('chat.php');
const upgrade=read('upgrade.php');
const workflow=read('.github/workflows/cognitive-loop-release-v2360.yml');
const recovery=read('tools/run_recovery_baseline.py');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const docs=read('docs/VP3_COGNITIVE_AUTONOMY_V2470.md');

const runnerStart=autonomy.indexOf('function vp3_cognitive_autonomy_run_owner_v2470');
const runnerBody=autonomy.slice(runnerStart);

const checks=[
  ['v24.70 loads after v24.60 and before Agent Brain loop',
    bootstrap.indexOf("cognitive-supervision-v2460.php")<bootstrap.indexOf("cognitive-autonomy-v2470.php")
    &&bootstrap.indexOf("cognitive-autonomy-v2470.php")<bootstrap.indexOf("agent-cognitive-loop-v310.php")
    &&bootstrap.includes("cognitive-release-v2470.php")],
  ['canonical goal table owns execution mode with manual default',
    /execution_mode VARCHAR\(24\) NOT NULL DEFAULT 'manual'/.test(goals)
    &&/autonomy_updated_at DATETIME NULL/.test(goals)
    &&/column_exists\('agent_goals','execution_mode'\)/.test(goals)],
  ['upgrade requires and installs v24.70 governance schema',
    /vp3_cognitive_autonomy_schema_ready_v2470\(\)/.test(upgrade)
    &&/vp3_cognitive_autonomy_ensure_schema_v2470\(\$pdo\)/.test(upgrade)],
  ['execution modes are manual supervised autonomous only',
    /\['manual','supervised','autonomous'\]/.test(autonomy)],
  ['goal autonomy requires explicit chat command',
    /goal_execution_mode_changed/.test(autonomy)
    &&/set\|make\|switch/.test(autonomy)
    &&/vp3_cognitive_autonomy_chat_goal_mode_v2470/.test(goalExecution)],
  ['autonomous project runner does not invent a roadmap',
    /plan_missing'=>'build_roadmap'/.test(autonomy)
    &&!/agent_goal_plan_seed_v1711\(/.test(runnerBody)],
  ['autonomy materializes only an existing milestone',
    /agent_goal_milestone_row_v1711/.test(autonomy)
    &&/objective_run_id IS NULL/.test(autonomy)
    &&/agent_objective_create_v175/.test(autonomy)],
  ['objective creation retains Phase 17.5 risk and approval gates',
    /\$status=\$plan\['requires_approval'\]\?'approval_pending':'approved'/.test(objectives)
    &&/risk_level/.test(objectives)],
  ['autonomy never claims a worker or directly runs a tool',
    !/agent_job_claim_run_v1900\(/.test(autonomy)
    &&!/agent_job_claim_next_v1900\(/.test(autonomy)
    &&!/agent_worker_runtime_poll_v1910\(/.test(autonomy)
    &&/'direct_tool_execution'=>false/.test(autonomy)],
  ['existing durable job engine remains lease and receipt authority',
    /function agent_job_claim_next_v1900/.test(jobs)
    &&/lease_token/.test(jobs)
    &&/agent_workflow_receipts/.test(jobs)
    &&/function agent_worker_runtime_poll_v1910/.test(workers)],
  ['automatic remediation is low risk and approval free only',
    /\$risk==='low'&&\!\$approval/.test(autonomy)
    &&/risk_or_approval_requires_user/.test(autonomy)],
  ['cancelled work is never autonomously replaced',
    /\$status==='cancelled'/.test(autonomy)
    &&/cancelled_work_requires_user/.test(autonomy)],
  ['autonomous remediation cycles are bounded',
    /VP3_COGNITIVE_AUTONOMY_MAX_REMEDIATION_CYCLES_V2470=2/.test(autonomy)
    &&/remediation_cycle_limit/.test(autonomy)],
  ['Phase 17.6 replacement is filterable and idempotent',
    /array \$onlyRunIds=\[\]/.test(verification)
    &&/event_type='objective_replaced'/.test(verification)
    &&/if\(\$already->fetchColumn\(\)\)continue/.test(verification)],
  ['replaced failed children leave active objective projection',
    /NOT EXISTS \([\s\S]*event_type='objective_replaced'/.test(objectives)],
  ['background cognitive loop runs v24.70 after supervision',
    loop.indexOf('vp3_cognitive_supervision_reconcile_owner_v2460')<loop.indexOf('vp3_cognitive_autonomy_run_owner_v2470')
    &&/'autonomy_run'=>\$autonomyRun/.test(loop)],
  ['v24.20 Working Context includes one bounded autonomy section',
    /'live_session','continuity','supervision','autonomy','conversation'/.test(context)
    &&/'autonomy'=>1/.test(context)
    &&/vp3_cognitive_autonomy_context_item_v2470/.test(context)],
  ['autonomy context is projection-only with no instruction authority',
    /instruction_authority'=>false/.test(autonomy)
    &&/'worker_claim_authority'=>false/.test(autonomy)],
  ['Cognitive Presentation exposes autonomy focus',
    /vp3_cognitive_autonomy_activity_projection_v2470/.test(presentation)
    &&/autonomy_focus/.test(presentation)],
  ['Proactive Now uses the same v24.70 projection',
    /vp3_cognitive_autonomy_activity_projection_v2470/.test(proactive)
    &&/'autonomy'=>/.test(proactive)],
  ['Agent Brain API uses the same v24.70 projection',
    /vp3_cognitive_autonomy_activity_projection_v2470/.test(drawerApi)
    &&/'autonomy'=>\$autonomy/.test(drawerApi)],
  ['Agent Brief renders long-horizon autonomous goal state',
    /Long-horizon goal/.test(presentationJs)
    &&/autonomy_focus/.test(presentationJs)],
  ['Agent Brain renders Autonomous Projects',
    /<strong>Autonomous Projects<\/strong>/.test(drawerJs)
    &&/execution_mode/.test(drawerJs)
    &&/execution_state/.test(drawerJs)],
  ['History remains canonical conversation history',
    /<strong>Agent History<\/strong>/.test(drawerJs)
    &&/Authorized conversation archive/.test(drawerJs)
    &&!/Autonomous Projects[\s\S]*function historyView/.test(drawerJs.slice(drawerJs.indexOf('function historyView')))],
  ['Chat cache-busts v24.70 UI',
    /\$cognitiveAutonomyBuild = 'cognitive-autonomy-v2470-20260922'/.test(chat)
    &&/'autonomyBuild'=>\$cognitiveAutonomyBuild/.test(chat)],
  ['release contract forbids parallel execution systems',
    /'second_project_store'=>false/.test(release)
    &&/'second_scheduler'=>false/.test(release)
    &&/'second_worker'=>false/.test(release)
    &&/'autonomy_approval_bypass'=>false/.test(release)],
  ['CI runs v24.70 gate', /cognitive-autonomy-v2470\.mjs/.test(workflow)],
  ['Recovery Baseline retains v24.70 gate', /cognitive-autonomy-v2470\.mjs/.test(recovery)],
  ['Production package requires v24.70 runtime and release gate',
    /cognitive-autonomy-v2470\.php/.test(packageWorkflow)
    &&/cognitive-release-v2470\.php/.test(packageWorkflow)],
  ['docs state explicit opt-in and no roadmap invention',
    /defaults to \*\*manual\*\*/.test(docs)
    &&/does \*\*not\*\* invent a roadmap/.test(docs)
    &&/History.*intentionally unchanged/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Autonomy v24.70 gate: ${checks.length}/${checks.length} passed`);
