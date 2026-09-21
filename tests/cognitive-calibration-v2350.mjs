import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const cal=read('includes/cognitive-calibration-v2350.php');
const learning=read('includes/cognitive-learning-v540.php');
const planningApi=read('api/cognitive-planning-v550.php');
const orchestration=read('includes/cognitive-orchestration-v560.php');
const browser=read('api/extension-cognitive-now-v2120.php');
const proactive=read('includes/cognitive-proactive-now-v2340.php');
const chatJs=read('chat-cognitive-feed-v530.js');
const bootstrap=read('includes/bootstrap.php');
const spec=read('docs/VP3_COGNITIVE_CALIBRATION_V2350.md');

const checks=[
 ['no schema', !/CREATE TABLE|ALTER TABLE/i.test(cal)],
 ['same v540 ledger', /cognitive_feedback_events_v540/.test(cal) && /vp3_cognitive_learning_feedback_v540/.test(cal)],
 ['60 day bounded window', /WINDOW_DAYS_V2350=60/.test(cal)],
 ['minimum evidence', /MIN_EVIDENCE_V2350=5/.test(cal)],
 ['explicit plan signals', /plan_accepted/.test(cal) && /plan_dismissed/.test(cal) && /handoff_requested/.test(cal) && /handoff_postponed/.test(cal) && /plan_completed/.test(cal)],
 ['canonical outcomes included', /outcome_successful/.test(cal) && /outcome_unsuccessful/.test(cal) && /outcome_ignored/.test(cal)],
 ['canonical outcomes weighted stronger', /outcome_successful'\]\*2\.0/.test(cal) && /outcome_unsuccessful'\]\*2\.0/.test(cal)],
 ['plan candidate reauthorized', /vp3_cognitive_authorize_ref_v500/.test(cal)],
 ['plan candidate exact fingerprint lifecycle', /item_key=\? AND item_fingerprint=\?/.test(cal)],
 ['accept is engagement not execution', /plan_accepted/.test(planningApi) && !/\$action==='accept'\?'acted'/.test(planningApi)],
 ['dismiss is explicit negative signal', /plan_dismissed/.test(planningApi)],
 ['Browser plan semantics match Agent Chat', /plan_accepted/.test(browser) && /plan_dismissed/.test(browser) && !/decision==='accept'\?'acted'/.test(browser)],
 ['handoff requested feeds action signal', /handoff_requested/.test(orchestration) && /vp3_cognitive_calibration_feedback_plan_v2350/.test(orchestration)],
 ['handoff reject is postpone signal', /handoff_postponed/.test(orchestration)],
 ['canonical completion feeds plan completion', /plan_completed/.test(orchestration)],
 ['v540 allows explicit events', /plan_accepted/.test(learning) && /plan_dismissed/.test(learning) && /handoff_postponed/.test(learning)],
 ['v540 plan accepted maps engagement', /'plan_accepted'=>'engagements'/.test(learning)],
 ['v540 plan dismissed maps ignored', /'plan_dismissed'=>'ignored'/.test(learning)],
 ['v540 handoff requested maps action', /'handoff_requested'=>'actions_taken'/.test(learning)],
 ['deterministic sections still protected', /\['priorities','opportunities','recent'\]/.test(learning)],
 ['calibration cannot reorder queue', /'queue_reordering'=>false/.test(cal)],
 ['calibration only reduces focus', /\$mode==='conservative'\?1:3/.test(cal)],
 ['focus reduction does not truncate plan counting', /if\(count\(\$focus\)>=\$focusLimit\)continue/.test(proactive) && !/if\(count\(\$focus\)>=\$focusLimit\)break/.test(proactive)],
 ['conservative suppresses optional return voice suffix', /return_voice_context_enabled/.test(cal) && /return_voice_context_enabled/.test(proactive)],
 ['proactive brief exposes calibration', /'calibration'=>\$calibration/.test(proactive)],
 ['UI exposes calibration state', /cognitiveCalibration/.test(chatJs) && /Calibration/.test(chatJs)],
 ['bootstrap order', bootstrap.indexOf('cognitive-calibration-v2350.php')<bootstrap.indexOf('cognitive-proactive-now-v2340.php')],
 ['no second learner invariant', /no second learner/.test(spec)],
 ['acceptance not execution invariant', /A plan accept never becomes an execution signal/.test(spec)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Calibration v23.50 contract: ${checks.length}/${checks.length} passed`);
