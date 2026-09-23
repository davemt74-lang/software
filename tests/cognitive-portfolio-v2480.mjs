import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const bootstrap=read('includes/bootstrap.php');
const portfolio=read('includes/cognitive-portfolio-v2480.php');
const release=read('includes/cognitive-release-v2480.php');
const autonomy=read('includes/cognitive-autonomy-v2470.php');
const jobs=read('includes/agent-job-engine-v1900.php');
const workers=read('includes/agent-worker-runtime-v1910.php');
const dependencies=read('includes/agent-work-dependencies-v174.php');
const objectivePortfolio=read('includes/agent-objective-portfolio-v179.php');
const context=read('includes/cognitive-context-v2420.php');
const loop=read('includes/agent-cognitive-loop-v310.php');
const presentation=read('includes/cognitive-presentation-v510.php');
const proactive=read('includes/cognitive-proactive-now-v2340.php');
const presentationJs=read('chat-cognitive-presentation-v510.js');
const drawerApi=read('api/chat-notifications-brain-v240.php');
const drawerJs=read('chat-notifications-drawer-v240.js');
const chat=read('chat.php');
const workflow=read('.github/workflows/cognitive-loop-release-v2360.yml');
const recovery=read('tools/run_recovery_baseline.py');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const docs=read('docs/VP3_COGNITIVE_PORTFOLIO_V2480.md');

const claimStart=portfolio.indexOf('function vp3_cognitive_portfolio_claim_admission_v2480');
const claimBody=portfolio.slice(claimStart);
const jobClaimStart=jobs.indexOf('function agent_job_claim_run_v1900');
const jobClaimEnd=jobs.indexOf('\nfunction ',jobClaimStart+10);
const jobClaimBody=jobs.slice(jobClaimStart,jobClaimEnd<0?jobs.length:jobClaimEnd);
const runStart=portfolio.indexOf('function vp3_cognitive_portfolio_run_owner_v2480');
const runBody=portfolio.slice(runStart,claimStart);

const checks=[
  ['v24.80 loads after v24.70 and before Agent Brain loop',
    bootstrap.indexOf("cognitive-autonomy-v2470.php")<bootstrap.indexOf("cognitive-portfolio-v2480.php")
    &&bootstrap.indexOf("cognitive-portfolio-v2480.php")<bootstrap.indexOf("agent-cognitive-loop-v310.php")
    &&bootstrap.includes("cognitive-release-v2480.php")],
  ['portfolio coordinator creates no second schema or durable portfolio ledger',
    !(portfolio.match(/CREATE TABLE|ALTER TABLE|INSERT INTO|UPDATE\s+[a-z_]+\s+SET|DELETE FROM/ig)||[]).length],
  ['portfolio derives canonical goal objective and dependency graph',
    /FROM agent_goal_objectives/.test(portfolio)
    &&/agent_workflow_run_dependencies/.test(portfolio)
    &&/agent_goals/.test(portfolio)],
  ['portfolio reuses existing objective similarity instead of new duplicate engine',
    /agent_objective_portfolio_similarity_v179/.test(portfolio)
    &&/VP3_COGNITIVE_PORTFOLIO_OVERLAP_THRESHOLD_V2480=0\.58/.test(portfolio)
    &&/function agent_objective_portfolio_similarity_v179/.test(objectivePortfolio)],
  ['semantic overlap holds only not-yet-materialized autonomous goal work',
    portfolio.includes("items[$i]['execution_state']!=='needs_objective'")
    &&portfolio.includes("items[$j]['execution_state']!=='needs_objective'")
    &&portfolio.includes("items[$j]['hold_reason']='semantic_overlap'")
    &&/existing canonical objectives are never cancelled or blocked merely by similarity/.test(portfolio)],
  ['worker capacity is lightweight projection not live readiness refresh',
    /agent_worker_runtime_active_count_v1910/.test(portfolio)
    &&/homeserver_vp3_connection/.test(portfolio)
    &&!/agent_worker_runtime_summary_v1910\(/.test(portfolio)
    &&!/agent_worker_runtime_homeserver_registry_v1910\(/.test(portfolio)],
  ['Phase 19 remains live readiness and capability authority',
    /function agent_worker_runtime_poll_v1910/.test(workers)
    &&/agent_worker_runtime_authorize_claim_v1910/.test(workers)
    &&/capability_unavailable/.test(workers)],
  ['claim gate applies only to objective-step work',
    /\$sourceKind!=='objective_step'/.test(portfolio)
    &&/not_autonomous_portfolio_work/.test(claimBody)],
  ['manual or supervised shared work passes through portfolio gate',
    /shared_non_autonomous_authority/.test(claimBody)
    &&/\(string\)\$mode!=='autonomous'/.test(claimBody)],
  ['portfolio never claims workers itself',
    !/agent_job_claim_run_v1900\(/.test(portfolio)
    &&!/agent_job_claim_next_v1900\(/.test(portfolio)
    &&/'worker_claim_authority'=>false/.test(portfolio)],
  ['existing job claimant asks v24.80 before leasing autonomous project work',
    /vp3_cognitive_portfolio_claim_admission_v2480/.test(jobClaimBody)
    &&jobClaimBody.indexOf('vp3_cognitive_portfolio_claim_admission_v2480')<jobClaimBody.indexOf('$pdo->beginTransaction()')],
  ['portfolio failure cannot deadlock already-authorized durable work',
    /Portfolio coordination is an admission policy/.test(jobs)
    &&/Fail open/.test(jobs)],
  ['existing dependencies still gate actual claim execution',
    /agent_work_dependencies_satisfied_v174/.test(jobs)
    &&/function agent_work_dependencies_satisfied_v174/.test(dependencies)],
  ['claim admission reserves free executor slots from existing capacity',
    /claim_admitted_goal_ids/.test(portfolio)
    &&/\$remainingFree\[\$executor\]=max\(0,\$free-count\(\$claimAdmitted\[\$executor\]\)\)/.test(portfolio)],
  ['project fairness prefers a nonactive goal before another slot for an active goal',
    /\$aActive<=>\$bActive/.test(portfolio)
    &&/active_executors/.test(portfolio)],
  ['cross-goal dependency leverage feeds coordination score',
    /blocked_by_goals/.test(portfolio)
    &&/dependency_leverage/.test(portfolio)
    &&/\$leverage\*0\.17/.test(portfolio)],
  ['v24.70 accepts bounded portfolio admission policy while retaining default behavior',
    /function vp3_cognitive_autonomy_run_owner_v2470\(PDO \$pdo,array \$user,array \$policy=\[\]\)/.test(autonomy)
    &&/allowed_goal_ids/.test(autonomy)
    &&/objective_budget/.test(autonomy)
    &&/remediation_budget/.test(autonomy)],
  ['v24.80 delegates autonomous mutations to v24.70',
    /vp3_cognitive_autonomy_run_owner_v2470\(\$pdo,\$user,\$policy\)/.test(runBody)
    &&!/vp3_cognitive_autonomy_materialize_milestone_v2470\(/.test(runBody)
    &&!/vp3_cognitive_autonomy_remediate_objective_v2470\(/.test(runBody)],
  ['Agent Brain loop coordinates before using v24.70 fallback',
    /vp3_cognitive_portfolio_run_owner_v2480/.test(loop)
    &&loop.indexOf('vp3_cognitive_portfolio_run_owner_v2480')<loop.indexOf("elseif(function_exists('vp3_cognitive_autonomy_run_owner_v2470'))")
    &&/'portfolio_run'=>\$portfolioRun/.test(loop)],
  ['v24.20 Working Context includes one bounded portfolio section',
    context.indexOf("'autonomy'")<context.indexOf("'portfolio'")
    &&context.indexOf("'portfolio'")<context.indexOf("'conversation'")
    &&/'portfolio'=>1/.test(context)
    &&/vp3_cognitive_portfolio_context_item_v2480/.test(context)],
  ['portfolio context has no instruction authority',
    /instruction_authority'=>false/.test(portfolio)
    &&/'scheduler_authority'=>false/.test(portfolio)
    &&/'approval_authority'=>false/.test(portfolio)],
  ['Cognitive Presentation exposes portfolio focus and capacity',
    /vp3_cognitive_portfolio_activity_projection_v2480/.test(presentation)
    &&/portfolio_focus/.test(presentation)
    &&/portfolio_capacity/.test(presentation)],
  ['Proactive Now uses the same portfolio projection',
    /vp3_cognitive_portfolio_activity_projection_v2480/.test(proactive)
    &&/'portfolio'=>/.test(proactive)],
  ['Agent Brain API uses the same portfolio projection',
    /vp3_cognitive_portfolio_activity_projection_v2480/.test(drawerApi)
    &&/'portfolio'=>\$portfolio/.test(drawerApi)],
  ['Agent Brief renders portfolio coordination',
    /Portfolio coordination/.test(presentationJs)
    &&/portfolio_focus/.test(presentationJs)
    &&/portfolio_capacity/.test(presentationJs)],
  ['Agent Brain renders Portfolio Coordination',
    /<strong>Portfolio Coordination<\/strong>/.test(drawerJs)
    &&/portfolioItems/.test(drawerJs)
    &&/Cloud free/.test(drawerJs)
    &&/HomeServer free/.test(drawerJs)],
  ['History remains canonical conversation history',
    /<strong>Agent History<\/strong>/.test(drawerJs)
    &&/Authorized conversation archive/.test(drawerJs)],
  ['Chat cache-busts v24.80 UI',
    /\$cognitivePortfolioBuild = 'cognitive-portfolio-v2480-20260922'/.test(chat)
    &&/'portfolioBuild'=>\$cognitivePortfolioBuild/.test(chat)],
  ['release contract forbids parallel portfolio execution systems',
    /'second_portfolio_store'=>false/.test(release)
    &&/'second_scheduler'=>false/.test(release)
    &&/'portfolio_worker_claim_authority'=>false/.test(release)
    &&/'capacity_projection_does_not_refresh_live_homeserver_registry'=>true/.test(release)],
  ['CI runs v24.80 gate', /cognitive-portfolio-v2480\.mjs/.test(workflow)],
  ['Recovery Baseline retains v24.80 gate', /cognitive-portfolio-v2480\.mjs/.test(recovery)],
  ['Production package requires v24.80 runtime and release gate',
    /cognitive-portfolio-v2480\.php/.test(packageWorkflow)
    &&/cognitive-release-v2480\.php/.test(packageWorkflow)],
  ['docs explain admission not scheduling and preserve History',
    /Portfolio coordination decides admission and sequencing pressure/.test(docs)
    &&/No new portfolio database/.test(docs)
    &&/History.*remains canonical conversation history/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Portfolio v24.80 gate: ${checks.length}/${checks.length} passed`);
