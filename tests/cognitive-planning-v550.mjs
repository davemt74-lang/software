import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const planning=read('includes/cognitive-planning-v550.php');
const api=read('api/cognitive-planning-v550.php');
const feed=read('includes/cognitive-feed-v530.php');
const feedJs=read('chat-cognitive-feed-v530.js');
const cardsJs=read('chat-cognitive-cards-v520.js');
const cardsPhp=read('includes/cognitive-cards-v520.php');
const chat=read('chat.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');

assert.match(planning,/VP3_COGNITIVE_PLANNING_V550='vp3-cognitive-planning-v550-20260918'/);
for(const table of ['cognitive_plans_v550','cognitive_plan_events_v550'])
  assert.match(planning,new RegExp('CREATE TABLE IF NOT EXISTS '+table));

const schemaBlocks=(planning.match(/CREATE TABLE IF NOT EXISTS cognitive_(?:plans|plan_events)_v550[\s\S]*?ENGINE=InnoDB/g)||[]).join('\n');
assert.ok(schemaBlocks.length>0,'planning schema block missing');
assert.doesNotMatch(schemaBlocks,/message_text|transcript|content_text|body_text|summary_text|title_text/i,'planning persistence must remain reference-only');
assert.match(planning,/source_item_key VARCHAR\(190\)/);
assert.match(planning,/source_fingerprint CHAR\(64\)/);
assert.match(planning,/object_type VARCHAR\(80\)/);
assert.match(planning,/object_id VARCHAR\(190\)/);
assert.match(planning,/tool_id VARCHAR\(120\)/);
assert.match(planning,/requires_approval TINYINT\(1\)/);

for(const fn of [
  'vp3_cognitive_planning_sync_v550',
  'vp3_cognitive_planning_decide_v550',
  'vp3_cognitive_planning_feed_candidates_v550',
  'vp3_cognitive_planning_card_v550',
  'vp3_cognitive_planning_permission_v550'
]) assert.ok(planning.includes('function '+fn),'missing '+fn);

assert.match(planning,/if\(\$status==='accepted'[\s\S]*!empty\(\$liveCapability\['available'\]\)[\s\S]*existing_capability[\s\S]*hash_equals/,'tool action must only appear after acceptance and a matching live registered capability');
assert.match(planning,/Accepting this plan does not execute a tool, approve an action, or mutate the underlying VP3 object/);
assert.match(planning,/Use the registered VP3 tool only after the user explicitly chooses to proceed/);
assert.match(planning,/requires_approval'=>!empty\(\$row\['requires_approval'\]\)/);
assert.match(planning,/source_fingerprint<>\?/,'changed source must supersede older proposals');
assert.match(planning,/\$meta\['module'\].*\$objectModule/s,'suggested tools must belong to the referenced cognitive module');
assert.match(planning,/hash_equals\(\(string\)\$source\['fingerprint'\],\(string\)\$row\['source_fingerprint'\]\)/,'acceptance must recheck current source fingerprint');
assert.doesNotMatch(planning,/execute_tool|tool_execute|run_tool|agent_tool_execute/i,'planning core must not execute tools');

assert.match(api,/has_permission\('chat\.access',\$user\)/);
assert.match(api,/hash_equals\(csrf_token\(\)/);
assert.match(api,/\['accept','dismiss'\]/);
assert.match(api,/vp3_cognitive_planning_decide_v550/);
assert.match(api,/'authority'=>'proposal_only'/);

assert.match(feed,/vp3_cognitive_planning_sync_v550/);
assert.match(feed,/vp3_cognitive_planning_feed_candidates_v550/);
assert.match(feed,/planning_action_ids/);
assert.match(feed,/unset\(\$item\['planning_action_ids'\]\)/,'internal proposed action ids must not leak into feed payload');

assert.match(feedJs,/planningEndpoint/);
assert.match(feedJs,/planningApi\('accept'/);
assert.match(feedJs,/planningApi\('dismiss'/);
assert.match(feedJs,/cardType==='proactive_plan'/);
assert.match(feedJs,/Accept plan/);
assert.match(feedJs,/Dismiss plan/);
assert.match(cardsJs,/proactive_plan:'→'/);
assert.match(cardsPhp,/'proactive_plan'=>.*suggested actions/s,'Chat intent must recognize proposed plans and suggested actions');
assert.match(cardsPhp,/cognitive_plans_v550.*status IN \('proposed','accepted'\)/s,'Chat card retrieval must query only active plan states');

assert.match(bootstrap,/cognitive-planning-v550\.php/);
assert.match(chat,/cognitive-planning-v550-20260918/);
assert.match(chat,/planningEndpoint.*cognitive-planning-v550\.php/s);
assert.match(upgrade,/vp3_cognitive_planning_schema_ready_v550/);
assert.match(upgrade,/vp3_cognitive_planning_ensure_schema_v550/);

console.log('VP3 Proactive Planning & Suggested Actions v5.50 contract passed.');
