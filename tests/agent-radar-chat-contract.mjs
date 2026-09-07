import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const bootstrap = read('includes/bootstrap.php');
const chatTool = read('includes/agent-radar-chat.php');
const chatApi = read('api/chat.php');
const gateway = read('includes/agent-radar-gateway.php');

assert.ok(bootstrap.includes("require_once __DIR__.'/agent-radar-chat.php';"), 'bootstrap must load the Radar chat tool');
assert.ok(chatTool.includes('vp3_radar_chat_tool'), 'Radar must expose a Main Feed tool');
assert.ok(chatTool.includes('vp3_radar_chat_intent'), 'Radar chat must be narrowly intent-gated');
assert.ok(chatTool.includes('vp3_radar_chat_find_contact'), 'Radar chat writes must resolve one owner-scoped Agent CRM contact');
assert.ok(chatTool.includes('WHERE c.id=? AND c.owner_user_id=?'), 'direct Agent CRM lookup must be owner scoped');
assert.ok(chatTool.includes("risk_score>=70"), 'chat must be able to report high-risk contacts');
assert.ok(chatTool.includes('vp3_radar_gateway_contact_policy'), 'chat summaries must expose the current contact policy');
assert.ok(chatTool.includes('vp3_radar_gateway_set_contact_policy'), 'chat must use the same canonical policy writer as the Radar UI');
assert.ok(chatTool.includes("'block'=>$name.' is now blocked across connected sites"), 'chat must clearly describe Block everywhere behavior');
assert.ok(chatTool.includes("'limit'=>$name.' is now limited to '"), 'chat must clearly describe contact rate limits');
assert.ok(chatTool.includes('agent_tool_log'), 'Radar chat reads/writes must enter existing tool history when available');
assert.ok(!chatTool.includes('REMOTE_ADDR'), 'Radar chat must not introduce raw IP use');

assert.ok(chatApi.includes("function_exists('vp3_radar_chat_tool')"), 'Main Feed must call Radar tool before generic tool execution');
assert.ok(chatApi.indexOf("vp3_radar_chat_tool($query") < chatApi.indexOf('release_v105_chat_tool($query'), 'Radar tool must get first chance at explicit Radar/Gateway commands');
assert.ok(chatApi.includes("'radar_security_action'"), 'Main Feed activity must surface native/Gateway high-risk alerts');
assert.ok(chatApi.includes("'radar_external_security_action'"), 'Main Feed activity must surface external-site high-risk alerts');
assert.ok(chatApi.includes("'radar_agent_visit_needs_attention'"), 'Main Feed activity must surface meaningful native AI visits');
assert.ok(chatApi.includes("'radar_external_visit_needs_attention'"), 'Main Feed activity must surface meaningful external AI visits');
assert.ok(chatApi.includes("'agent_activity_radar_rate_limited'"), 'Main Feed activity must surface rate-limit enforcement');
assert.ok(chatApi.includes('notification_unread_count($user)'), 'Radar activity must continue using the canonical notification unread system');
assert.ok(gateway.includes("create_notification("), 'Gateway enforcement must write into the existing notification system');

console.log('AGENT_RADAR_CHAT_CONTRACT=PASS');
