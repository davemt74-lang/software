import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const ops=read('includes/cognitive-operations-v2300.php');
const queue=read('includes/cognitive-priority-queue-v2310.php');
const opp=read('includes/cognitive-opportunities-v2320.php');
const action=read('includes/cognitive-action-planning-v2330.php');
const feed=read('includes/cognitive-feed-v530.php');
const work=read('includes/agent-chat-intelligence-v171.php');
const planning=read('includes/cognitive-planning-v550.php');
const orchestration=read('includes/cognitive-orchestration-v560.php');
const audit=read('docs/VP3_COGNITIVE_STACK_AUDIT_V23X.md');

const checks=[
  ['v23.00 remains projection-only', /mode'=>'projection_only'/.test(ops) && !/\b(?:INSERT|UPDATE|DELETE|CREATE TABLE|ALTER TABLE)\b/i.test(ops)],
  ['v23.00 public surface remains summary-only', /function vp3_cognitive_operations_public_v2300/.test(ops) && !/'items'=>\$state\['items'\]/.test(ops)],
  ['v23.10 cognitive time uses raw UTC', /updated_at_utc/.test(work) && /next_attempt_at_utc/.test(work) && /row\['updated_at_utc'\]/.test(feed)],
  ['v23.10 localization is presentation-only', /user_calendar_default_timezone_v1300/.test(feed) && /row\['next_attempt_at_utc'\]/.test(feed)],
  ['v23.10 remains selected-feed projection', /mode'=>'selected_feed_projection'/.test(queue) && /MAX_ITEMS_V2310=12/.test(queue)],
  ['v23.20 bounded absence cannot resolve valid items', /valid_until IS NOT NULL AND valid_until<UTC_TIMESTAMP\(\)/.test(opp) && !/observation_key NOT IN/.test(opp)],
  ['v23.20 TTL refresh preserves fingerprint', /UPDATE cognitive_observations_v500 SET valid_until=\? WHERE id=\?/.test(opp) && !/SET valid_until=\?,updated_at/.test(opp)],
  ['v23.20 scan exposes truncation', /'truncated'=>\$truncated/.test(opp) && /'cleanup'=>'expired_only'/.test(opp)],
  ['v23.20 confidence normalized', /vp3_cognitive_score_v500\(max/.test(opp)],
  ['v23.30 module drift fails closed', /capability_module_mismatch/.test(action) && /module_changed/.test(action)],
  ['v23.30 stricter risk or approval requires replan', /risk_increased/.test(action) && /approval_added/.test(action) && /replan_required/.test(action)],
  ['v23.30 handoff compatibility is explicit', /function vp3_cognitive_action_planning_handoff_compatible_v2330/.test(action)],
  ['v5.60 blocks drift before materialization', /boundary_changed/.test(orchestration) && /\)\)return null;/.test(orchestration)],
  ['v5.60 rechecks live boundary before handoff', /Cognitive plan capability boundary changed\. Replan before handoff/.test(orchestration)],
  ['plan card hides drifted capability', /empty\(\$liveCapability\['boundary_changed'\]\)/.test(planning)],
  ['no v23 schema introduced', !/CREATE TABLE|ALTER TABLE/i.test(ops+queue+opp+action)],
  ['no v23 model execution', /model_may_execute'=>false/.test(ops) && /model_may_execute'=>false/.test(action)],
  ['no automatic external writes', /automatic_external_writes'=>false/.test(ops) && /automatic_external_writes'=>false/.test(queue) && /automatic_external_writes'=>false/.test(action)],
  ['audit documents pre-hardening scores', /v23\.30 — 8\.8 \/ 10/.test(audit) && /v23\.20 — 8\.4 \/ 10/.test(audit) && /v23\.10 — 9\.2 \/ 10/.test(audit) && /v23\.00 — 10\.0 \/ 10/.test(audit)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`VP3 Cognitive Stack backwards audit gate: ${checks.length}/${checks.length} passed`);
