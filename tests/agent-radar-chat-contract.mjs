import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const bootstrap = read('includes/bootstrap.php');
const chatTool = read('includes/agent-radar-chat.php');
const adapter = read('includes/agent-radar-read-v1.php');
const chatService = [chatTool,adapter].join('\n');
const chatApi = [read('api/chat.php'),read('includes/agent-tool-authorization-v400.php')].join('\n');
const gateway = read('includes/agent-radar-gateway.php');

assert.ok(bootstrap.includes("require_once __DIR__.'/agent-radar-chat.php';"), 'bootstrap must load the Radar chat tool');
assert.ok(chatTool.includes('vp3_radar_chat_tool'), 'Radar must expose a Main Feed tool');
assert.ok(chatTool.includes('vp3_radar_chat_intent'), 'Radar chat must be narrowly intent-gated');
assert.ok(chatTool.includes('vp3_radar_chat_find_contact'), 'Radar chat writes must resolve one owner-scoped Agent CRM contact');
assert.ok(chatTool.includes('WHERE c.id=? AND c.owner_user_id=?'), 'direct Agent CRM lookup must be owner scoped');
assert.ok(adapter.includes('risk_score>=70'), 'the shared read must report the real high-risk threshold');
assert.ok(chatTool.includes('vp3_radar_read_v1') && adapter.includes('vp3_radar_contact_policy_v1'), 'legacy summaries must use the shared current-policy read');
assert.ok(adapter.includes('vp3_radar_gateway_set_contact_policy'), 'chat must use the same canonical policy writer as the Radar UI');
assert.ok(adapter.includes('Gateway policy saved: ') && adapter.includes('Enforcement on connected sites depends on their installed Agent Gateway integration'), 'policy saving must be distinguished from observed external enforcement');
assert.ok(adapter.includes("'limit '.$policy['limit_30m'].' requests per 30 minutes'"), 'chat must clearly describe verified saved contact rate limits');
assert.ok(chatTool.includes('agent_tool_log'), 'Radar chat reads/writes must enter existing tool history when available');
assert.ok(!chatService.includes('REMOTE_ADDR'), 'Radar chat must not introduce raw IP use');
assert.ok(chatTool.includes('vp3_radar_resolve_contact_v1') && adapter.includes('count($matches)===1'), 'contact targeting requires one exact match instead of a best-score guess');
assert.ok(!chatService.includes('$bestScore'), 'fuzzy contact score ties must not select a mutation target');
assert.ok(adapter.includes('c.owner_user_id=p.owner_user_id') && adapter.includes('vp3_radar_owned_rows_v1'), 'policy joins and returned record ownership must remain bound to the current owner');
assert.ok(adapter.includes('vp3_observation_personal_permission_v1') && adapter.includes('vp3_radar_authority_v1($pdo,$user,$principal,$context)'), 'reads and canonical writes must recheck current Agent, conversation and personal authority');
assert.ok(adapter.includes('outcome_unverified') && adapter.includes('Effects may already exist'), 'an uncertain saved result must not report completion or repeat a write');
assert.ok(adapter.includes("$same?'read_complete':'completed'"), 'unchanged saved policy must produce a read receipt without a new mutation');
assert.ok(chatTool.indexOf('$nonexecuting=vp3_radar_nonexecuting_v1') < chatTool.indexOf('vp3_agent_access_chat_tool($query'), 'capability, tutorial and conditional guards must precede older delegated reads/writes');
assert.ok(chatTool.includes('$incoming||!$directRadar'), 'explicit Radar reads must not enter the broad operator-summary delegate while incoming messaging retains its separate workflow');
assert.ok(adapter.includes('LIMIT 13') && adapter.includes('array_slice($rows,0,12)'), 'record reads must be bounded and explicitly truncated');
assert.ok(!/\b(?:CREATE TABLE|ALTER TABLE|DROP TABLE)\b/.test(adapter), 'the adapter must not create or upgrade storage');

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
