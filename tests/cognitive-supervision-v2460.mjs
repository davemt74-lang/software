import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const bootstrap=read('includes/bootstrap.php');
const supervision=read('includes/cognitive-supervision-v2460.php');
const release=read('includes/cognitive-release-v2460.php');
const context=read('includes/cognitive-context-v2420.php');
const follow=read('includes/cognitive-followthrough-v2450.php');
const loop=read('includes/agent-cognitive-loop-v310.php');
const jobs=read('includes/agent-job-engine-v1900.php');
const workflowRuns=read('includes/agent-workflow-runs-v1400.php');
const orchestration=read('includes/cognitive-orchestration-v560.php');
const verification=read('includes/agent-objective-verification-v176.php');
const presentation=read('includes/cognitive-presentation-v510.php');
const proactive=read('includes/cognitive-proactive-now-v2340.php');
const presentationJs=read('chat-cognitive-presentation-v510.js');
const drawerApi=read('api/chat-notifications-brain-v240.php');
const drawerJs=read('chat-notifications-drawer-v240.js');
const chat=read('chat.php');
const workflow=read('.github/workflows/cognitive-loop-release-v2360.yml');
const recovery=read('tools/run_recovery_baseline.py');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const docs=read('docs/VP3_COGNITIVE_SUPERVISION_V2460.md');

const reconcileStart=supervision.indexOf('function vp3_cognitive_supervision_reconcile_owner_v2460');
const reconcileBody=supervision.slice(reconcileStart);

const checks=[
  ['v24.60 loads after v24.50 and before Agent Brain loop',
    bootstrap.indexOf("cognitive-followthrough-v2450.php")<bootstrap.indexOf("cognitive-supervision-v2460.php")
    &&bootstrap.indexOf("cognitive-supervision-v2460.php")<bootstrap.indexOf("agent-cognitive-loop-v310.php")
    &&bootstrap.includes("cognitive-release-v2460.php")],
  ['supervision creates no second schema/queue',
    !(supervision.match(/CREATE TABLE|ALTER TABLE|INSERT INTO/ig)||[]).length],
  ['supervision reads v24.40 continuity as its open-work source',
    /vp3_cognitive_continuity_snapshot_v2440/.test(supervision)],
  ['durable workflow health detects expired lease heartbeat stall and ready stall',
    /expired_execution_lease/.test(supervision)
    &&/heartbeat_stale/.test(supervision)
    &&/ready_unclaimed/.test(supervision)],
  ['workflow dependencies and terminal failure are supervised',
    /agent_workflow_run_dependencies/.test(supervision)
    &&/dependency_blocked/.test(supervision)
    &&/terminal_failure/.test(supervision)],
  ['cognitive plan supervision detects verification replan loop and auth loss',
    /verification_overdue/.test(supervision)
    &&/replan_required/.test(supervision)
    &&/replan_loop/.test(supervision)
    &&/authorization_lost/.test(supervision)],
  ['meeting and generic continuity supervision remains projection-only',
    /commitment_overdue/.test(supervision)
    &&/verification_needs_attention/.test(supervision)
    &&/'projection_only'=>true/.test(supervision)],
  ['automatic workflow repair uses only existing expired-lease recovery',
    /agent_job_recover_expired_v1900/.test(reconcileBody)
    &&!/agent_job_retry_v1900\(/.test(reconcileBody)
    && !/agent_workflow_retry_v1400\(/.test(reconcileBody)],
  ['supervision never claims worker execution',
    !/agent_job_claim_run_v1900\(/.test(supervision)
    &&!/agent_job_claim_next_v1900\(/.test(supervision)
    &&/'worker_claim_authority'=>false/.test(supervision)],
  ['existing job engine preserves lease retry and terminal semantics',
    /function agent_job_recover_expired_v1900/.test(jobs)
    &&/attempt<\$max/.test(jobs)
    &&/status='failed'/.test(jobs)],
  ['automatic plan work is reconciliation only',
    /vp3_cognitive_orchestration_reconcile_run_v560/.test(reconcileBody)
    && !/vp3_cognitive_orchestration_handoff_v560\(/.test(reconcileBody)],
  ['existing Cognitive Orchestration still owns replan step creation',
    /function vp3_cognitive_orchestration_replan_step_v560/.test(orchestration)
    &&/status='needs_replan'/.test(orchestration)],
  ['objective replacement remains existing v17.6 authority',
    /function agent_objective_verification_rewire_failed_v176/.test(verification)
    && !/agent_objective_verification_rewire_failed_v176\(/.test(reconcileBody)],
  ['v24.20 Working Context includes bounded supervision section',
    context.indexOf("'continuity'")<context.indexOf("'supervision'")
    &&context.indexOf("'supervision'")<context.indexOf("'conversation'")
    &&/'supervision'=>1/.test(context)
    &&/vp3_cognitive_supervision_context_item_v2460/.test(context)],
  ['supervision context has no execution authority',
    /instruction_authority'=>false/.test(supervision)
    &&/'execution_authority'=>false/.test(supervision)
    &&/'approval_authority'=>false/.test(supervision)],
  ['v24.50 handoff event identity includes supervision health transitions',
    /vp3_cognitive_followthrough_event_key_v2450\(string \$namespace,array \$item,array \$supervision=\[\]\)/.test(follow)
    &&/\$supervision\['health_state'\]/.test(follow)],
  ['v24.50 priority and user response incorporate supervision',
    /max\([\s\S]*\$supervision\['score'\]/.test(follow)
    &&/\$supervision\['requires_user'\]/.test(follow)],
  ['background cognitive loop performs governed supervision reconciliation',
    /vp3_cognitive_supervision_reconcile_owner_v2460/.test(loop)
    &&/'supervision_reconcile'=>\$supervisionReconcile/.test(loop)],
  ['Cognitive Presentation exposes supervision focus',
    /vp3_cognitive_supervision_activity_projection_v2460/.test(presentation)
    &&/supervision_focus/.test(presentation)],
  ['Proactive Now uses same supervision projection',
    /vp3_cognitive_supervision_activity_projection_v2460/.test(proactive)
    &&/'supervision'=>/.test(proactive)],
  ['Agent Brain API uses same supervision projection',
    /vp3_cognitive_supervision_activity_projection_v2460/.test(drawerApi)
    &&/'supervision'=>\$supervision/.test(drawerApi)],
  ['Agent Brief renders autonomous supervision',
    /Autonomous supervision/.test(presentationJs)
    &&/supervision_focus/.test(presentationJs)],
  ['Agent Brain renders Work Supervision and Open Work health',
    /<strong>Work Supervision<\/strong>/.test(drawerJs)
    &&/supervisionByRef/.test(drawerJs)
    &&/health_state/.test(drawerJs)],
  ['Chat cache-busts v24.60 supervision UI',
    /\$cognitiveSupervisionBuild = 'cognitive-supervision-v2460-20260922'/.test(chat)
    &&/'supervisionBuild'=>\$cognitiveSupervisionBuild/.test(chat)],
  ['release gate forbids scheduler retry execution and approval duplication',
    /'second_scheduler'=>false/.test(release)
    &&/'second_retry_engine'=>false/.test(release)
    &&/'supervision_direct_tool_execution'=>false/.test(release)
    &&/'supervision_terminal_retry'=>false/.test(release)],
  ['CI runs v24.60 gate', /cognitive-supervision-v2460\.mjs/.test(workflow)],
  ['Recovery Baseline retains v24.60 gate', /cognitive-supervision-v2460\.mjs/.test(recovery)],
  ['Production package requires v24.60 runtime and release gate',
    /cognitive-supervision-v2460\.php/.test(packageWorkflow)
    &&/cognitive-release-v2460\.php/.test(packageWorkflow)],
  ['docs explicitly separate autonomous supervision from autonomous execution',
    /autonomous about \*\*supervision and governed reconciliation\*\*/.test(docs)
    &&/Never automatic in v24\.60/.test(docs)
    &&/No separate supervision history database/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Supervision v24.60 gate: ${checks.length}/${checks.length} passed`);
