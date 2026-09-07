import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const bootstrap = read('includes/bootstrap.php');
const foundation = read('includes/agent-radar-foundation.php');
const access = read('includes/agent-access.php');
const accessChat = read('includes/agent-access-chat.php');
const radarChat = read('includes/agent-radar-chat.php');
const requestApi = read('api/agent-access-request.php');
const messageApi = read('api/agent-message.php');
const policyApi = read('api/agent-radar-policy.php');
const chatApi = read('api/chat.php');
const profilePage = read('profile-agent.php');
const ui = read('profile-agent-access-requests.js');
const css = read('profile-agent-access-requests.css');

assert.ok(bootstrap.includes("require_once __DIR__.'/agent-access.php';"), 'bootstrap must load Agent Messaging grant runtime');
assert.ok(bootstrap.includes("require_once __DIR__.'/agent-access-chat.php';"), 'bootstrap must load Agent Messaging chat approval runtime');
assert.ok(foundation.includes('vp3_agent_access_requests'), 'Radar schema must include Agent Messaging access requests');
assert.ok(foundation.includes("const VP3_AGENT_MESSAGING_CAPABILITY = 'agent.messaging'"), 'Agent Messaging must use the canonical subscription capability');
assert.ok(access.includes("const VP3_AGENT_ACCESS_CAPABILITIES = ['agent.message']"), 'public grant capabilities must be explicitly allowlisted');
assert.ok(access.includes("(string)$registry['visitor_class']!=='ai_user_agent'"), 'only recognized user-directed agents may request messaging');
assert.ok(access.includes("hash('sha256',$token)"), 'request grant secret must be stored as a hash');
assert.ok(access.includes('FOR UPDATE'), 'grant claiming must lock the access row atomically');
assert.ok(access.includes("status='consumed'"), 'one-time grants must be consumed atomically');
assert.ok(access.includes('vp3_agent_access_restore_once'), 'failed one-time message attempts must be restorable');
assert.ok(access.includes("ai_generate_chat_response($query,$history,$approvedContext,$ownerUser,'agent.messaging')"), 'Agent Messaging must bill through the owner’s canonical AI quota scope');
assert.ok(access.includes('profile_agent_context($pdo,$profile,$agent,null,$query)'), 'Agent Messaging must reuse the public Profile Agent context boundary');
assert.ok(access.includes("event_type IN ('agent_message_inbound','agent_message_outbound')"), 'agent conversation history must remain in Radar/CRM events');

assert.ok(requestApi.includes("header('Access-Control-Allow-Origin: *')"), 'access request endpoint must be machine-readable cross-origin');
assert.ok(requestApi.includes("'request_token'=>(string)$result['request_token']"), 'new request must return its one-time secret token to the requesting agent');
assert.ok(requestApi.includes('VP3 stores only its hash'), 'request endpoint must explain that the raw grant token is not recoverable');
assert.ok(requestApi.includes("vp3_agent_access_status_by_token"), 'request token must support status polling');
assert.ok(requestApi.includes("409"), 'duplicate pending requests must be suppressed rather than minting replacement secrets');

assert.ok(messageApi.includes("vp3_agent_access_grant_claim"), 'message endpoint must require an approved grant');
assert.ok(messageApi.includes("((string)$grant['capability']!=='agent.message')"), 'message endpoint must bind grants to Agent Messaging capability');
assert.ok(messageApi.includes("vp3_agent_access_restore_once"), 'message endpoint must restore one-time grants when processing fails');
assert.ok(messageApi.includes("vp3_agent_message_json(429"), 'message endpoint must reserve HTTP 429 for rate limiting');
assert.ok(messageApi.includes("vp3_agent_message_json(402"), 'message endpoint must report unavailable owner AI quota distinctly');
assert.ok(messageApi.includes("vp3_agent_message_json(403"), 'message endpoint must report access/Gateway denial distinctly');
assert.ok(messageApi.includes("vp3_agent_message_json(422"), 'message endpoint must report invalid message input distinctly');
assert.ok(messageApi.includes("vp3_agent_message_json(503"), 'message endpoint must report provider/runtime unavailability distinctly');
assert.ok(messageApi.includes("header('Retry-After: 10')"), 'rate-limited callers must receive Retry-After guidance');

assert.ok(policyApi.includes("'access_requests'=>vp3_agent_access_owner_list"), 'owner Gateway API must return messaging requests');
assert.ok(policyApi.includes("$action==='access_request_decision'"), 'owner Gateway API must support access-request decisions');
assert.ok(policyApi.includes("vp3_agent_access_owner_decide"), 'owner decisions must use the canonical grant service');
assert.ok(chatApi.includes("'radar_agent_access_request'"), 'Main Feed activity must surface Agent Messaging requests');

assert.ok(profilePage.includes('/profile-agent-access-requests.css'), 'Profile Agent Radar must load messaging approval styles');
assert.ok(profilePage.includes('/profile-agent-access-requests.js'), 'Profile Agent Radar must load messaging approval controls');
assert.ok(ui.includes('Allow once'), 'Radar UI must expose one-time approval');
assert.ok(ui.includes('Always allow'), 'Radar UI must expose persistent approval');
assert.ok(ui.includes('Deny'), 'Radar UI must expose denial');
assert.ok(ui.includes('existing VP3 AI token balance'), 'Radar UI must disclose token consumption');
assert.ok(css.includes('@media(max-width:480px)'), 'messaging approvals must remain usable on small screens');

assert.ok(accessChat.includes('vp3_agent_access_chat_tool'), 'Main Feed must have a dedicated Agent Messaging approval tool');
assert.ok(accessChat.includes('allow_once'), 'chat tool must support one-time approval');
assert.ok(accessChat.includes("'allow'"), 'chat tool must support persistent approval');
assert.ok(accessChat.includes("'deny'"), 'chat tool must support denial/revocation');
const messagingRoute = radarChat.indexOf("vp3_agent_access_chat_tool($query,$user,$conversationId)");
const analyticsRoute = radarChat.indexOf("vp3_analytics_chat_tool($query,$user,$conversationId)");
assert.ok(messagingRoute >= 0 && analyticsRoute >= 0 && messagingRoute < analyticsRoute, 'messaging approval commands must run before broader Radar/Analytics intent handling');

console.log('AGENT_MESSAGING_CONTRACT=PASS');
