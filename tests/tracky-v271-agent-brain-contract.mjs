import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const agent=read('includes/tracky-agent-v271.php');
const cloud=read('includes/tracky-cloud-v270.php');
const context=read('includes/cognitive-context-v2420.php');
const domains=read('includes/cognitive-domain-registry-v2600.php');
const legacyTools=read('includes/agent-tools-v84.php');
const authTools=read('includes/agent-tool-authorization-v400.php');
const brain=read('includes/agent-brain-v82.php');
const chat=read('api/chat.php');
const trackyPage=read('tracky.php');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');

assert.match(agent,/VP3_TRACKY_AGENT_CONTRACT_V271='physical-context-agent-v1'/);
assert.match(agent,/function tracky_agent_tools_query_v271/);
assert.match(agent,/function tracky_agent_context_items_v271/);
assert.match(agent,/function tracky_agent_register_cognitive_v271/);
assert.match(agent,/function tracky_agent_freshness_v271/);
assert.match(agent,/VP3_TRACKY_AGENT_CURRENT_SECONDS_V271=300/);
assert.match(agent,/api key\|secret key\|access token/);
assert.match(agent,/'relation'=>'location_of'/);
assert.match(agent,/\?\'has_location\':\$predicate/);
assert.match(agent,/function tracky_agent_on_sync_v271/);
assert.match(agent,/module'=>'physical_context'/);
for(const id of ['tracky.current_context','tracky.where_is','tracky.who_is_present','tracky.last_seen','tracky.what_changed','tracky.confidence','tracky.why','tracky.health']){
  assert.ok(agent.includes("'"+id+"'=>"),'missing cognitive tool '+id);
}
assert.doesNotMatch(agent,/'kind'=>'write'/);
assert.doesNotMatch(agent,/'kind'=>'prepare'/);
assert.doesNotMatch(agent,/devices\.control|device\.control|unlock|lock\.open|switch\.on/);
assert.match(agent,/'raw_perception_exposed'=>false/);
assert.match(agent,/vp3_cognitive_domain_ingest_v2600/);
assert.doesNotMatch(agent,/CREATE TABLE|ALTER TABLE|INSERT INTO agent_event_inbox/);

assert.match(cloud,/\$acceptedForCognition\[\]=\$event/);
assert.match(cloud,/tracky_agent_on_sync_v271\(\$pdo,\$userId,\$siteId,\$acceptedForCognition\)/);
assert.match(cloud,/require_once __DIR__\.'\/tracky-agent-v271\.php'/);

assert.match(context,/'physical_context'/);
assert.match(context,/tracky_agent_context_items_v271/);
assert.match(context,/'physical_context'=>'tracky_cloud_physical_context_v271_projection'/);
assert.match(context,/'raw_physical_perception_copied'=>false/);
assert.match(agent,/'freshness'=>\$current\['freshness'\]/);

assert.match(domains,/\$domains\['physical_context'\]=tracky_agent_domain_contract_v271\(\)/);
assert.match(domains,/tracky_agent_register_cognitive_v271\(\)/);

assert.match(authTools,/tracky_agent_tools_query_v271/);
assert.ok(authTools.indexOf('tracky_agent_tools_query_v271') < authTools.indexOf('homeserver_agent_read_v230_query'),'canonical tool boundary must route Tracky before generic HomeServer reads');
assert.match(legacyTools,/tracky_agent_tools_query_v271/);
assert.match(brain,/tracky_agent_tool_catalog_entry_v271/);
assert.match(chat,/tracky_agent_tools_query_v271/);
assert.ok(chat.indexOf('tracky_agent_tools_query_v271') < chat.indexOf('homeserver_agent_read_v230_query'),'non-stream chat must route Tracky before generic HomeServer reads');

assert.match(trackyPage,/Agent Brain integration/);
assert.match(trackyPage,/Read only/);
assert.match(trackyPage,/never the raw perception stream/);

assert.match(packageWorkflow,/test -f _deploy\/includes\/tracky-cloud-v270\.php/);
assert.match(packageWorkflow,/test -f _deploy\/includes\/tracky-agent-v271\.php/);

console.log('TRACKY_V271_AGENT_BRAIN_CONTRACT=PASS');
