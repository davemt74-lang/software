import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const action=read('includes/cognitive-action-planning-v2330.php');
const planning=read('includes/cognitive-planning-v550.php');
const orchestration=read('includes/cognitive-orchestration-v560.php');
const runtime=read('includes/cognitive-runtime-v500.php');
const bootstrap=read('includes/bootstrap.php');
const spec=read('docs/VP3_COGNITIVE_ACTION_PLANNING_V2330.md');

const checks=[
 ['read-only action contract layer', /Read-only Action Plan Contract/.test(action)],
 ['no schema', !/CREATE TABLE|ALTER TABLE|INSERT INTO|UPDATE .* SET|DELETE FROM/i.test(action)],
 ['no tool registration', !/vp3_cognitive_register_module_v500|tools\s*=>/i.test(action)],
 ['no model execution', /model_may_execute'=>false/.test(action)],
 ['no automatic external writes', /automatic_external_writes'=>false/.test(action)],
 ['source reauthorized', /vp3_cognitive_authorize_ref_v500\(\$pdo,\$user,\$namespace,\$ref,'read'\)/.test(action)],
 ['tool metadata from registry', /\$registry\['tools'\]\[\$toolId\]/.test(action)],
 ['risk cannot be weakened', /riskOrder/.test(action) && /toolRisk/.test(action) && /planRisk/.test(action)],
 ['live risk increase requires replan', /riskIncreased/.test(action) && /boundary_changed/.test(action) && /risk_increased/.test(action)],
 ['capability module drift fails closed', /capability_module_mismatch/.test(action) && /module_changed/.test(action)],
 ['approval cannot be weakened', /!empty\(\$plan\['requires_approval'\]\)\|\|!empty\(\$meta\['requires_approval'\]\)/.test(action)],
 ['missing capability fails closed', /capability_unavailable/.test(action) && /requires_approval'=>true/.test(action)],
 ['review-only plan cannot execute', /mode'=>'review_only'/.test(action) && /user_decision_required/.test(action)],
 ['exact five conceptual steps', /STEP_COUNT_V2330=5/.test(action) && /'key'=>'inspect'/.test(action) && /'key'=>'prepare'/.test(action) && /'key'=>'handoff'/.test(action) && /'key'=>'verify'/.test(action) && /'key'=>'close'/.test(action)],
 ['canonical success codes', /successful','resolved/.test(action)],
 ['failure triggers replan contract', /unsuccessful','ignored/.test(action)],
 ['handoff is not completion', /handoff_is_completion'=>false/.test(action)],
 ['planning exposes contract', /action_contract/.test(planning) && /vp3_cognitive_action_planning_contract_v2330/.test(planning)],
 ['planning card exposes capability and success', /actionProjection/.test(planning) && /liveCapability/.test(planning)],
 ['stale plan capability action hidden', /liveCapability\['available'\]/.test(planning) && /existing_capability/.test(planning)],
 ['orchestration exposes same contract', /action_contract/.test(orchestration) && /vp3_cognitive_action_planning_contract_v2330/.test(orchestration)],
 ['orchestration handoff rechecks live capability', /Cognitive plan capability is unavailable/.test(orchestration) && /Cognitive plan capability boundary changed/.test(orchestration)],
 ['orchestration blocks drifted materialization', /boundary_changed/.test(orchestration) && /return null/.test(orchestration)],
 ['run cards hide incompatible handoff', /vp3_cognitive_action_planning_handoff_compatible_v2330/.test(orchestration)],
 ['existing v560 remains executor-free', /never executes registered tools/.test(orchestration)],
 ['runtime registry remains capability authority', /normalizedTools/.test(runtime)],
 ['bootstrap loads v2330 after planning/orchestration', bootstrap.indexOf('cognitive-action-planning-v2330.php')>bootstrap.indexOf('cognitive-orchestration-v560.php') && bootstrap.indexOf('cognitive-action-planning-v2330.php')<bootstrap.indexOf('cognitive-feed-v530.php')],
 ['no chain-of-thought storage invariant', /no hidden chain-of-thought storage/.test(spec)],
 ['existing execution authority invariant', /existing authoritative capability/.test(spec)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Action Planning v23.30 contract: ${checks.length}/${checks.length} passed`);
