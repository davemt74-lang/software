import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(p,'utf8');
const forecast=read('includes/cognitive-forecast-v2490.php');
const portfolio=read('includes/cognitive-portfolio-v2480.php');
const release=read('includes/cognitive-release-v2490.php');
const bootstrap=read('includes/bootstrap.php');
const context=read('includes/cognitive-context-v2420.php');
const presentation=read('includes/cognitive-presentation-v510.php');
const proactive=read('includes/cognitive-proactive-now-v2340.php');
const api=read('api/chat-notifications-brain-v240.php');
const brief=read('chat-cognitive-presentation-v510.js');
const drawer=read('chat-notifications-drawer-v240.js');
const workflow=read('.github/workflows/cognitive-loop-release-v2360.yml');
const recovery=read('tools/run_recovery_baseline.py');
const pkg=read('.github/workflows/production-deploy-package.yml');
const docs=read('docs/VP3_COGNITIVE_FORECAST_V2490.md');

const checks=[
 ['v24.90 loads after v24.80',bootstrap.indexOf("cognitive-portfolio-v2480.php")<bootstrap.indexOf("cognitive-forecast-v2490.php")],
 ['forecast creates no schema/store',!/(CREATE TABLE|ALTER TABLE|INSERT INTO|UPDATE\s+[a-z_]+\s+SET|DELETE FROM)/i.test(forecast)],
 ['forecast uses Phase 19 completed action history',/agent_workflow_actions/.test(forecast)&&/status='completed'/.test(forecast)&&/TIMESTAMPDIFF/.test(forecast)],
 ['forecast exposes bounded defaults',/DEFAULT_CLOUD_SECONDS/.test(forecast)&&/DEFAULT_HOMESERVER_SECONDS/.test(forecast)&&/MAX_ACTION_SECONDS/.test(forecast)],
 ['completion windows are explicit estimates',/earliest_completion_at/.test(forecast)&&/likely_completion_at/.test(forecast)&&/latest_completion_at/.test(forecast)],
 ['confidence is explicit',/confidence/.test(forecast)&&/"high":\(\$samples>=4\?'medium':'low'\)/.test(forecast)],
 ['conflict types are predicted',/deadline_risk/.test(forecast)&&/capacity_pressure/.test(forecast)&&/dependency_bottleneck/.test(forecast)&&/overlap_review/.test(forecast)],
 ['adaptive sequencing is pure advisory',/function vp3_cognitive_forecast_adaptive_resequence_v2490/.test(forecast)&&/advisory_only'=>true/.test(forecast)],
 ['v24.80 remains admission authority',/vp3_cognitive_forecast_adaptive_resequence_v2490/.test(portfolio)&&/claim_admitted_goal_ids/.test(portfolio)],
 ['forecast failure preserves v24.80 ordering',/Forecast failure leaves the proven v24.80 order/.test(portfolio)&&/catch\(Throwable \$e\)\{\}/.test(portfolio)],
 ['release forbids second scheduler and preserves Phase 19',/'second_scheduler'=>false/.test(release)&&/'v2480_remains_admission_authority'=>true/.test(release)&&/'phase19_remains_execution_authority'=>true/.test(release)],
 ['working context includes bounded forecast',/vp3_cognitive_forecast_context_item_v2490/.test(context)&&/'forecast'=>1/.test(context)],
 ['Cognitive Presentation exposes forecast',/vp3_cognitive_forecast_activity_projection_v2490/.test(presentation)&&/forecast_focus/.test(presentation)],
 ['Proactive Now exposes forecast',/vp3_cognitive_forecast_activity_projection_v2490/.test(proactive)&&/'forecast'=>/.test(proactive)],
 ['Agent Brain API exposes forecast',/vp3_cognitive_forecast_activity_projection_v2490/.test(api)&&/'forecast'=>\$forecast/.test(api)],
 ['Agent Brief renders forecast',/Portfolio forecast/.test(brief)&&/likely_completion_at/.test(brief)],
 ['Agent Brain renders Portfolio Forecast',/<strong>Portfolio Forecast<\/strong>/.test(drawer)&&/forecastItems/.test(drawer)],
 ['CI runs v24.90 gate',/cognitive-forecast-v2490\.mjs/.test(workflow)],
 ['Recovery Baseline runs v24.90 gate',/cognitive-forecast-v2490\.mjs/.test(recovery)],
 ['production package requires v24.90',/cognitive-forecast-v2490\.php/.test(pkg)&&/cognitive-release-v2490\.php/.test(pkg)],
 ['docs preserve authority chain and History',/v24\.90 Forecast\/Plan/.test(docs)&&/History remains canonical Agent Chat conversation history/.test(docs)],
];
for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Forecast v24.90 gate: ${checks.length}/${checks.length} passed`);
