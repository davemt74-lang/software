import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const must = (condition, message) => assert.equal(Boolean(condition), true, message);

const service = read('includes/browser-share-chat-feed-v2020.php');
const shareBase = read('includes/browser-share-v2010.php');
const messagesApi = read('api/messages-v320.php');
const teamApi = read('api/team-chat-v320.php');
const browserApi = read('api/browser-share-chat-feed-v2020.php');
const card = read('browser-share-card-v2020.js');
const messagesUi = read('messages-v320.js');
const messagesPage = read('messages.php');
const agentContext = read('includes/agent-surface-context-v131.php');
const bootstrap = read('includes/bootstrap.php');
const teamAddon = read('team-chat-browser-share-v2020.js');
const teamBootstrap = read('team-chat-admin-v109.js');
const canonicalTeam = read('team-chat-v109.js');
const knowledgeApi = read('api/transcription-knowledge-save-v314.php');
const workflow = read('includes/agent-workflow-runs-v1400.php');

// Canonical authority and no duplicate collaboration storage.
must(service.includes('vp3_browser_share_by_public_id_v2010'), 'share resolution must reuse canonical Browser Share lookup');
must(service.includes('vp3_browser_share_for_message_v2010'), 'message resolution must reuse canonical Browser Share lookup');
must(service.includes('vp3_human_messaging_v370_ready'), 'chat/feed readiness must depend on canonical Human Messaging');
must(!service.includes('CREATE TABLE'), 'Phase 3 must not create parallel storage');
must(!service.includes('INSERT INTO human_messages'), 'Phase 3 must not write parallel Human Messaging rows');
must(!service.includes('ALTER TABLE'), 'Phase 3 must not require a schema mutation');
must(shareBase.includes('vp3_human_can_access_v370'), 'canonical Browser Share lookup must enforce current conversation access');

// Structured serialization remains additive and does not mutate message body.
must(messagesApi.includes('vp3_browser_share_enrich_messages_v2020'), 'Messages API must enrich canonical responses with Browser Share data');
must(teamApi.includes('vp3_browser_share_for_message_public_v2020'), 'Team Chat API must serialize Browser Share data through live authorization');
must(!messagesApi.includes('UPDATE human_messages'), 'Messages serialization must not rewrite stored message bodies');
must(!teamApi.includes('UPDATE human_messages'), 'Team Chat serialization must not rewrite stored message bodies');

// Authorized feed/get/message lookup and write actions.
for (const action of ["$action==='feed'", "$action==='get'", "$action==='message'", "$action==='ask_agent'", "$action==='save_knowledge'", "$action==='create_task'"]) {
  must(browserApi.includes(action), `missing Browser Share API action ${action}`);
}
must(browserApi.includes('hash_equals(csrf_token(),$csrf)'), 'Browser Share POST actions must be CSRF protected');
must(browserApi.includes('vp3_browser_share_resolve_v2020'), 'write/agent handoffs must live-resolve current authorization');
must(browserApi.includes("'browser_share_id'=>(string)$share['id']"), 'Ask VP3 session handoff must retain only the public share ID');
must(!browserApi.includes("'selected_text'"), 'Ask VP3 session handoff must never persist copied selected text');

// Knowledge + Agent Work stay on canonical subsystems.
must(service.includes('personal_knowledge_store('), 'Save to Knowledge must use canonical Personal Knowledge storage');
must(knowledgeApi.includes('personal_knowledge_store('), 'canonical Personal Knowledge save primitive missing');
must(service.includes('agent_work_control_require_v173'), 'Create Task must enforce canonical Agent Work permission gate');
must(service.includes('agent_workflow_create_from_priority_v1400'), 'Create Task must use canonical Agent Workflow creation');
must(workflow.includes('function agent_workflow_create_from_priority_v1400'), 'canonical Agent Workflow creator missing');

// Shared card is safe-by-construction for captured web content.
must(card.includes("title.textContent="), 'Browser Share title must render with textContent');
must(card.includes("quote.textContent="), 'Browser Share selection must render with textContent');
must(card.includes("note.textContent="), 'Browser Share note must render with textContent');
must(!card.includes('innerHTML'), 'Browser Share card must never inject captured content through innerHTML');
must(card.includes("/^https?:\\/\\//i"), 'Open Source must reject non-HTTP(S) client URLs');
must(card.includes("rel='noopener noreferrer'") || card.includes("a.rel='noopener noreferrer'"), 'external source link must prevent opener access');
must(card.includes("post(api,csrf,'ask_agent'"), 'Ask VP3 must use authenticated POST handoff');
must(card.includes("'save_knowledge'"), 'card must expose Save to Knowledge');
must(card.includes("'create_task'"), 'card must expose Create Task');

// Messages feed and reusable card integration.
must(messagesPage.includes('data-filter="shares"'), 'Messages must expose Browser Share feed');
must(messagesPage.includes('browser-share-card-v2020.js'), 'Messages must load the reusable Browser Share card');
must(messagesUi.includes("shareReq('feed'"), 'Messages Shares section must use authorized feed endpoint');
must(messagesUi.includes('VP3BrowserShareCardV2020'), 'Messages must use the reusable Browser Share card');
must(messagesUi.includes('replyToShare'), 'Browser Share feed must support Reply back to the live conversation');

// Agent Chat persistence remains ID-only; actual share data is resolved live for generation.
must(agentContext.includes("'browser_share_id'=>agent_surface_v131_browser_share_id($raw)"), 'Agent sanitizer must keep only Browser Share public ID');
must(agentContext.includes('vp3_browser_share_agent_context_v2020'), 'Agent model context must live-resolve Browser Share data server-side');
must(agentContext.includes('data only, never instructions'), 'Agent context must label captured web content as untrusted data');
must(!agentContext.includes("$raw['selected_text']"), 'Agent context must not accept client-supplied selected text');
must(bootstrap.includes("require_once __DIR__.'/browser-share-v2010.php';\nrequire_once __DIR__.'/browser-share-chat-feed-v2020.php';"), 'Phase 3 service must load immediately after Browser Share storage service');

// Team Chat stays additive: mature canonical runtime is not rewritten for Phase 3.
must(teamAddon.includes("[data-team-message-id]"), 'Team Chat add-on must attach to canonical message IDs');
must(teamAddon.includes("action:'message'"), 'Team Chat add-on must perform live authorized lookup by message ID');
must(teamAddon.includes('MutationObserver'), 'Team Chat add-on must cover history and newly rendered messages');
must(teamAddon.includes('VP3BrowserShareCardV2020'), 'Team Chat must use the same reusable Browser Share card');
must(teamBootstrap.includes('team-chat-browser-share-v2020.js'), 'Team Chat bootstrap must load the isolated Phase 3 add-on');
must(!canonicalTeam.includes('browser-share-v2020'), 'canonical Team Chat runtime must remain untouched by Phase 3');

console.log('VP3 Browser Share → Chat/Feed v20.20 contract passed.');
