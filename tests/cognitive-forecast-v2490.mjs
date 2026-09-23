import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const bootstrap=read('includes/bootstrap.php');
const forecast=read('includes/cognitive-forecast-v2490.php');
const release=read('includes/cognitive-release-v2490.php');
const portfolio=read('includes/cognitive-portfolio-v2480.php');
const jobs=read('includes/agent-job-engine-v1900.php');
const workers=read('includes/agent-worker-runtime-v1910.php');
const context=read('includes/cognitive-context-v2420.php');
const presentation=read('includes/cognitive-presentation-v510.php');
const proactive=read('includes/cognitive-proactive-now-v2340.php');
const brainApi=read('api/chat-notifications-brain-v240.php');
const briefJs=read('chat-cognitive-presentation-v510.js');
const brainJs=read('chat-notifications-drawer-v240.js');
const chat=read('chat.php');
const workflow=read('.github/workflows/cognitive-loop-release-v2360.yml');
const recovery=read('tools/run_recovery_baseline.py');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const docs=read('docs/VP3_COGNITIVE_FORECAST_V2490.md');

const forecastOrder=portfolio.indexOf('vp3_cognitive_forecast_adaptive_resequence_v2490');
const admissionOrder=portfolio.indexOf("$claimAdmitted=['cloud'=>[],'homeserver'=>[]]");

const checks=[
  ['v24.90 runtime loads after v24.80 and release gate is loaded',
    bootstrap.indexOf("cognitive-portfolio-v2480.php")<bootstrap.indexOf("cognitive-forecast-v2490.php")
    &&bootstrap.includes("cognitive-release-v2490.php")],
  ['forecast creates no second schema or durable forecast ledger',
    !(forecast.match(/CREATE TABLE|ALTER TABLE|INSERT INTO|UPDATE\s+[a-z_]+\s+SET|DELETE FROM/ig)||[]).length],
  ['forecast reads bounded Phase 19 action history',
    /FROM agent_workflow_actions/.test(forecast)
    &&/status='completed'/.test(forecast)
    &&/HISTORY_DAYS_V2490=30/.test(forecast)],
  ['forecast uses bounded defaults for sparse executor history',
    /DEFAULT_CLOUD_SECONDS_V2490=900/.test(forecast)
    &&/DEFAULT_HOMESERVER_SECONDS_V2490=1200/.test(forecast)
    &&/source'=>'bounded_default'/.test(forecast)],
  ['forecast exposes earliest likely latest windows and explicit confidence',
    /earliest_completion_at/.test(forecast)
    &&/likely_completion_at/.test(forecast)
    &&/latest_completion_at/.test(forecast)
    &&/function vp3_cognitive_forecast_confidence_v2490/.test(forecast)],
  ['forecast detects deadline capacity dependency and overlap pressure',
    /deadline_risk/.test(forecast)
    &&/capacity_pressure/.test(forecast)
    &&/dependency_bottleneck/.test(forecast)
    &&/overlap_review/.test(forecast)],
  ['adaptive resequencing is pure advisory logic',
    /function vp3_cognitive_forecast_adaptive_resequence_v2490/.test(forecast)
    &&/advisory_only'=>true/.test(forecast)
    &&!/agent_job_claim_(?:run|next)_v1900\(/.test(forecast)],
  ['v24.80 consumes forecast sequence before admission while preserving fallback',
    forecastOrder>=0&&admissionOrder>=0&&forecastOrder<admissionOrder
    &&/Forecast failure leaves the proven v24\.80 order/.test(portfolio)
    &&/catch\(Throwable \$e\)\{\}/.test(portfolio)],
  ['v24.80 remains actual admission authority',
    /function vp3_cognitive_portfolio_claim_admission_v2480/.test(portfolio)
    &&/claim_admitted_goal_ids/.test(portfolio)],
  ['Phase 19 remains worker claim and lease authority',
    /function agent_job_claim_run_v1900/.test(jobs)
    &&/lease_token/.test(jobs)
    &&/agent_worker_runtime_authorize_claim_v1910/.test(workers)],
  ['forecast has no worker claim approval or execution authority',
    /'scheduler_authority'=>false/.test(forecast)
    &&/'worker_claim_authority'=>false/.test(forecast)
    &&/'approval_authority'=>false/.test(forecast)
    &&/'execution_authority'=>false/.test(forecast)],
  ['Working Context includes one bounded forecast section after portfolio',
    context.indexOf("'portfolio'")<context.indexOf("'forecast'")
    &&/'forecast'=>1/.test(context)
    &&/vp3_cognitive_forecast_context_item_v2490/.test(context)],
  ['forecast context carries no instruction authority',
    /instruction_authority'=>false/.test(forecast)
    &&/ephemeral_projection'=>true/.test(forecast)],
  ['Cognitive Presentation exposes the forecast projection',
    /vp3_cognitive_forecast_activity_projection_v2490/.test(presentation)
    &&/forecast_focus/.test(presentation)
    &&/'forecast'=>\$forecast/.test(presentation)],
  ['Proactive Now uses the same forecast projection',
    /vp3_cognitive_forecast_activity_projection_v2490/.test(proactive)
    &&/'forecast'=>\[/.test(proactive)
    &&/'forecast'=>'cognitive_forecast_v2490'/.test(proactive)],
  ['Agent Brain API uses the same forecast projection',
    /vp3_cognitive_forecast_activity_projection_v2490/.test(brainApi)
    &&/'forecast'=>\$forecast/.test(brainApi)],
  ['Agent Brief renders Portfolio forecast',
    /Portfolio forecast/.test(briefJs)
    &&/forecast_focus/.test(briefJs)
    &&/likely_completion_at/.test(briefJs)],
  ['Agent Brain renders Portfolio Forecast',
    /<strong>Portfolio Forecast<\/strong>/.test(brainJs)
    &&/forecastItems/.test(brainJs)
    &&/Deadline risk/.test(brainJs)],
  ['History remains canonical conversation history',
    /<strong>Agent History<\/strong>/.test(brainJs)
    &&/Authorized conversation archive/.test(brainJs)],
  ['chat cache-bust exposes v24.90 forecast build',
    /cognitive-forecast-v2490-20260922/.test(chat)
    &&/forecastBuild/.test(chat)],
  ['release contract preserves v24.80 and Phase 19 authority',
    /'v2480_remains_admission_authority'=>true/.test(release)
    &&/'phase19_remains_execution_authority'=>true/.test(release)
    &&/'forecast_failure_preserves_v2480_order'=>true/.test(release)
    &&/'adaptive_sequence_is_advisory_only'=>true/.test(release)],
  ['CI runs v24.90 release gate',/cognitive-forecast-v2490\.mjs/.test(workflow)],
  ['Recovery Baseline retains v24.90 gate',/cognitive-forecast-v2490\.mjs/.test(recovery)],
  ['Production package requires v24.90 runtime and release gate',
    /cognitive-forecast-v2490\.php/.test(packageWorkflow)
    &&/cognitive-release-v2490\.php/.test(packageWorkflow)
    &&/Cognitive Runtime v24\.90/.test(packageWorkflow)],
  ['docs preserve authority chain and History',
    /v24\.90 Forecast\/Plan/.test(docs)
    &&/v24\.80 Admission/.test(docs)
    &&/Phase 19 Claim\/Lease\/Execute\/Receipt/.test(docs)
    &&/History remains canonical Agent Chat conversation history/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Forecast v24.90 gate: ${checks.length}/${checks.length} passed`);
