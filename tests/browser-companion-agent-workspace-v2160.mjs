import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>assert.equal(Boolean(v),true,m);

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.js');
const html=read('browser-companion/sidepanel.html');
const css=read('browser-companion/sidepanel.css');
const extensionApi=read('api/extension-agent-chat-v2160.php');
const webApi=read('api/chat-v236.php');
const runtime=read('includes/agent-chat-runtime-v2160.php');
const chat=read('chat.php');

const workspaceVersion=String(manifest.version||'').split('.').map(Number);
must(workspaceVersion.length===3&&(workspaceVersion[0]>21||(workspaceVersion[0]===21&&workspaceVersion[1]>=6)),'v21.60+ manifest version missing');
must(/const VP3_EXTENSION_VERSION = '21\.(?:[6-9]|[1-9]\d+)\.\d+';/.test(background),'v21.60+ request version missing');

// Browser workspace UI.
for(const id of ['agentTab','agentView','agentWorkspaceName','agentWorkspaceStatus','agentRefreshBtn','agentConversationSelect','agentNewChatBtn','agentOpenFullBtn','agentUsePageContext','agentContextLabel','agentMessages','agentMessageInput','agentSendBtn']){
  must(html.includes(`id="${id}"`),`missing Agent workspace element ${id}`);
}
must(css.includes('.agent-workspace-card')&&css.includes('.agent-messages'),'Agent workspace styling missing');
must(panel.includes("activeView=v;const now=v==='now',agent=v==='agent'"),'Agent workspace must remain a first-class sidebar view');
must(panel.includes("setInterval(()=>pollAgentMessagesV2160(),4000)"),'visible Agent workspace must poll canonical messages');
must(panel.includes("clearInterval(agentPollTimer)")&&panel.includes("window.onbeforeunload"),'Agent poller cleanup missing');

// No Chrome-side conversation database/memory.
must(!background.includes('agent_conversations')&&!panel.includes('localStorage.setItem')&&!panel.includes('chrome.storage.local.set'),
  'Browser Agent workspace must not create a parallel conversation store');
must(panel.includes("let agentConversationId=0,agentWorkspaceAgentId=0"),'conversation/Agent identity must stay page-memory only');

// Durable-token, live permission adapter.
must(extensionApi.includes("vp3_extension_session_authenticate_v2001($pdo)"),'Browser Agent API durable-token auth missing');
must(extensionApi.includes("vp3_extension_session_has_capability_v2001($session,'agent.message')"),'live agent.message gate missing');
must(extensionApi.includes("has_permission('chat.access',$user)"),'live Chat permission gate missing');
must(extensionApi.includes("vp3_agent_chat_conversation_v380($pdo,$conversationId,$userId,$agent)"),'conversation ownership/Agent scoping must use canonical boundary');
must(extensionApi.includes("LIMIT 30"),'Browser conversation list must remain bounded');
must(extensionApi.includes("LIMIT 80"),'Browser message history/poll must remain bounded');
must(extensionApi.includes("strlen($raw)>65536"),'Browser Agent request body must be bounded');
must(extensionApi.includes("mb_strlen($message)>6000"),'Browser Agent message length must be bounded');
must(!extensionApi.includes("csrf_token()")&&!extensionApi.includes("hash_equals(csrf_token()"),
  'durable-token Browser Agent endpoint must not depend on web CSRF/session credentials');
must(webApi.includes("hash_equals(csrf_token(),$csrf)"),'web Agent Chat must retain CSRF protection');
must(extensionApi.includes("(SELECT COALESCE(MAX(m.id),0) FROM chat_messages m WHERE m.conversation_id=c.id) latest_message_id"),
  'conversation list must use SQL-mode-portable latest-message projection');
must(!extensionApi.includes("GROUP BY c.id"),'Browser conversation list must not rely on GROUP BY functional-dependency behavior');

// Same default Agent and namespace continuity as web Chat.
must(runtime.includes("function vp3_agent_chat_runtime_default_agent_v2160"),'shared default Agent resolver missing');
must(runtime.includes("!empty($agent['is_default'])"),'default Agent preference missing');
must(extensionApi.includes("vp3_agent_chat_runtime_default_agent_v2160($pdo,$user)"),'Browser Agent must use same default Agent behavior as web Chat');
must(panel.includes("if(agentWorkspaceAgentId>0)request.agent_id=agentWorkspaceAgentId;"),'resolved Agent must remain pinned across sidebar requests');

// One canonical send runtime for web + Chrome.
must(runtime.includes("function vp3_agent_chat_send_v2160("),'canonical shared Agent send runtime missing');
must(webApi.includes("$payload=vp3_agent_chat_send_v2160($pdo,$user,$activeAgent,$principal,$input,$brainAllowed);"),'web Chat send must delegate to shared runtime');
must(extensionApi.includes("vp3_agent_chat_send_v2160($pdo,$user,$activeAgent,$principal,$runtimeInput,$brainAllowed)"),'Browser Agent send must delegate to shared runtime');
must(runtime.includes("$userId=(int)($user['id']??0);"),'shared runtime user identity initialization missing');
must(runtime.includes("vp3_agent_tool_execute_query_v400"),'shared runtime must retain canonical Agent tools');
must(runtime.includes("knowledge_retrieval_v162_generate_answer"),'shared runtime must retain Knowledge retrieval');
must(runtime.includes("homeserver_agent_v025_chat"),'shared runtime must retain HomeServer route');
must(runtime.includes("ai_usage_accounting_v032"),'shared runtime must retain AI usage accounting');
must(runtime.includes("vp3_cognitive_cards_chat_requests_v520"),'shared runtime must retain Cognitive cards');
must(!runtime.includes('chat_v236_'),'shared runtime must not depend on web-endpoint-only helpers');

// Page context stays bounded, server-revalidated and non-persistent.
must(background.includes("function browserAgentContextV2160(capture)"),'Browser Agent page-context adapter missing');
must(background.includes("const page = browserContextPayload(capture);"),'Browser Agent context must reuse v21.30 bounded payload');
must(panel.includes("use_context:Boolean(ui.agentUsePageContext.checked&&capture&&capture.available)"),'per-turn page context toggle missing');
must(runtime.includes("vp3_browser_context_validate_v2130"),'shared runtime must server-revalidate Browser page context');
must(runtime.includes("vp3_browser_context_relationships_v2130($pdo,$user,$browserContext)"),'shared runtime must reauthorize page relationships');
must(runtime.includes("unset($persistedAgentContext['browser_context']);"),'full Browser context must not persist into Agent message metadata');
must(panel.includes("'Page context disabled for this turn.'"),'page-context disabled state missing');

// Cross-surface history continuity.
must(extensionApi.includes("'action'=>'list'")===false,'sanity: Browser API action dispatch must not hard-code invalid array dispatch');
for(const action of ["$action==='list'","$action==='load'","$action==='messages_after'","$action==='send'"]){
  must(extensionApi.includes(action),`missing Browser Agent action ${action}`);
}
must(panel.includes("agentWorkspaceRequestV2160('messages_after'"),'cross-surface message sync missing');
must(panel.includes("agentWorkspaceAgentId=Math.max(0,Number(payload.agent.id||0))"),'Agent identity sync from server missing');

// Open full Chat must preserve exact authorized conversation.
must(panel.includes("params.set('agent',agentWorkspaceAgentId>0?String(agentWorkspaceAgentId):'system')"),'full Chat Agent continuity missing');
must(panel.includes("params.set('conversation_id',String(agentConversationId))"),'full Chat conversation deep link missing');
must(chat.includes("$requestedConversationId=max(0,(int)($_GET['conversation_id']??0));"),'web Chat requested conversation handling missing');
must(chat.includes("WHERE id=? AND user_id=? AND user_agent_id=? LIMIT 1"),'custom-Agent conversation deep link must be ownership scoped');
must(chat.includes("WHERE id=? AND user_id=? AND user_agent_id IS NULL LIMIT 1"),'system-Agent conversation deep link must be ownership scoped');

// Live capability revocation and lifecycle.
must(
  panel.includes("['now','agent','execution','memory'].includes(activeView)&&!c.has('agent.message')")
    || panel.includes("['now','agent','memory'].includes(activeView)&&!c.has('agent.message')")
    || panel.includes("['now','agent'].includes(activeView)&&!c.has('agent.message')"),
  'Agent view must leave revoked capability immediately'
);
must(panel.includes("ui.agentSendBtn.disabled=!(state&&state.connected&&c.has('agent.message')"),'Agent send button live capability gate missing');
must(panel.includes("clearInterval(agentPollTimer);agentPollTimer=null;"),'Agent poll interval must stop off-view');

console.log('VP3 Browser Companion Browser Agent Workspace v21.60 contract passed.');
