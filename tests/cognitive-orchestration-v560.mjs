import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const core=read('includes/cognitive-orchestration-v560.php');
const api=read('api/cognitive-orchestration-v560.php');
const bridge=read('chat-cognitive-orchestration-v560.js');
const feed=read('includes/cognitive-feed-v530.php');
const planning=read('includes/cognitive-planning-v550.php');
const cardsPhp=read('includes/cognitive-cards-v520.php');
const cardsJs=read('chat-cognitive-cards-v520.js');
const chat=read('chat.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');

assert.match(core,/VP3_COGNITIVE_ORCHESTRATION_V560='vp3-cognitive-orchestration-v560-20260919'/);
for(const table of [
  'cognitive_plan_runs_v560',
  'cognitive_plan_steps_v560',
  'cognitive_plan_step_dependencies_v560',
  'cognitive_plan_step_events_v560',
  'cognitive_plan_verifications_v560'
]) assert.match(core,new RegExp('CREATE TABLE IF NOT EXISTS '+table));

const schemaBlocks=(core.match(/CREATE TABLE IF NOT EXISTS cognitive_plan_(?:runs|steps|step_dependencies|step_events|verifications)_v560[\s\S]*?ENGINE=InnoDB/g)||[]).join('\n');
assert.ok(schemaBlocks.length>0,'orchestration schema blocks missing');
assert.doesNotMatch(schemaBlocks,/message_text|transcript_text|content_text|body_text|summary_text|instruction_text/i,'orchestration persistence must remain reference-only');
assert.match(core,/plan_id BIGINT UNSIGNED NOT NULL/);
assert.match(core,/source_fingerprint CHAR\(64\) NOT NULL/);
assert.match(core,/tool_id VARCHAR\(120\) NOT NULL DEFAULT ''/);
assert.match(core,/requires_approval TINYINT\(1\) NOT NULL DEFAULT 0/);
assert.match(core,/depends_on_step_id BIGINT UNSIGNED NOT NULL/);
assert.match(core,/evidence_ref_type VARCHAR\(80\)/);
assert.match(core,/evidence_ref_id VARCHAR\(190\)/);

for(const fn of [
  'vp3_cognitive_orchestration_materialize_v560',
  'vp3_cognitive_orchestration_sync_v560',
  'vp3_cognitive_orchestration_handoff_v560',
  'vp3_cognitive_orchestration_reconcile_run_v560',
  'vp3_cognitive_orchestration_replan_step_v560',
  'vp3_cognitive_orchestration_feed_candidates_v560',
  'vp3_cognitive_orchestration_card_v560',
  'vp3_cognitive_orchestration_permission_v560'
]) assert.ok(core.includes('function '+fn),'missing '+fn);

assert.match(core,/\(string\)\(\$plan\['status'\]\?\?''\)!=='accepted'/,'only accepted 11B.6 plans may materialize');
assert.match(core,/'inspect','inspect'.*'completed','authorization'/s);
assert.match(core,/'handoff','handoff'.*\$handoffStatus,'canonical_outcome'/s);
assert.match(core,/'verify','verify'.*'blocked','canonical_outcome'/s);
assert.match(core,/'close','close'.*'blocked','run_outcome'/s);
assert.match(core,/cognitive_plan_step_dependencies_v560/,'steps must have durable dependency edges');

assert.match(core,/status='handoff_requested'.*attempt_count=attempt_count\+1/s,'handoff must record a request, not execution');
assert.match(core,/vp3_cognitive_orchestration_recount_v560\(\$pdo,\$run,'verifying','waiting'\)/);
assert.doesNotMatch(core,/execute_tool|tool_execute|run_tool|agent_tool_execute|agent_workflow_execute/i,'orchestration core must never execute a tool or workflow');

assert.match(core,/FROM cognitive_outcomes_v540/,'verification must consume 11B.5 canonical outcomes');
assert.match(core,/created_at>=\?/,'outcomes must be newer than the run');
assert.match(core,/in_array\(\$code,\['successful','resolved'\],true\)/);
assert.match(core,/in_array\(\$code,\['unsuccessful','ignored'\],true\)/);
assert.match(core,/vp3_cognitive_orchestration_replan_step_v560/);
assert.match(core,/status='superseded'/);
assert.match(core,/hash_equals\(\(string\)\$run\['source_fingerprint'\],\(string\)\$plan\['source_fingerprint'\]\)/);
assert.match(core,/verification_state='failed'/);
assert.match(core,/status='completed'.*status='accepted'/s,'verified closure must retire the accepted proposal');

assert.match(api,/has_permission\('chat\.access',\$user\)/);
assert.match(api,/hash_equals\(csrf_token\(\)/);
assert.match(api,/\['handoff_requested','handoff_rejected'\]/);
assert.match(api,/vp3_cognitive_orchestration_handoff_v560/);
assert.match(api,/'authority'=>'orchestration_only'/);
assert.doesNotMatch(api,/execute_tool|tool_execute|run_tool|agent_tool_execute/i);

assert.match(bridge,/document\.addEventListener\('vp3:cognitive-card-action'/);
assert.match(bridge,/card\.card_type\)!=='orchestration_run'/);
assert.match(bridge,/accepted\?'handoff_requested':'handoff_rejected'/);
assert.doesNotMatch(bridge,/requestSubmit\(|executeTool|runTool/i);

assert.match(feed,/vp3_cognitive_orchestration_sync_v560/);
assert.match(feed,/vp3_cognitive_orchestration_feed_candidates_v560/);
assert.match(planning,/vp3_cognitive_orchestration_plan_has_run_v560/,'accepted proposal card must yield to active run card');
assert.match(cardsPhp,/'orchestration_run'=>.*plan progress/s);
assert.match(cardsPhp,/cognitive_plan_runs_v560.*status NOT IN \('completed','closed','superseded','cancelled'\)/s);
assert.match(cardsJs,/orchestration_run:'↻'/);

assert.match(bootstrap,/cognitive-planning-v550\.php[\s\S]*cognitive-orchestration-v560\.php[\s\S]*cognitive-feed-v530\.php/);
assert.match(chat,/cognitive-orchestration-v560-20260919/);
assert.match(chat,/api\/cognitive-orchestration-v560\.php/);
assert.match(chat,/chat-cognitive-orchestration-v560\.js/);
assert.match(chat,/data-cognitive-orchestration-build/);
assert.match(upgrade,/vp3_cognitive_orchestration_schema_ready_v560/);
assert.match(upgrade,/vp3_cognitive_orchestration_ensure_schema_v560/);
assert.match(upgrade,/Cognitive Plan Orchestration & Follow-Through v5\.60/);

console.log('VP3 Cognitive Plan Orchestration & Follow-Through v5.60 contract passed.');
