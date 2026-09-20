import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const must=(value,message)=>assert.equal(Boolean(value),true,message);

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const html=read('browser-companion/sidepanel.html');
const panel=read('browser-companion/sidepanel.js');
const css=read('browser-companion/sidepanel.css');
const context=read('includes/browser-context-v2130.php');
const extensionApi=read('api/extension-cognitive-now-v2120.php');
const chat=read('chat.php');
const chatJs=read('chat.js');
const chatContext=read('chat-browser-context-v2130.js');
const chatApi=[read('api/chat-v236.php'),read('includes/agent-chat-runtime-v2160.php')].join('\n');
const agentSurface=read('includes/agent-surface-context-v131.php');

const versionParts=String(manifest.version||'').split('.').map(Number);
must(versionParts.length===3&&(versionParts[0]>21||(versionParts[0]===21&&versionParts[1]>=3)),'v21.30+ manifest version missing');
must(background.includes(`const VP3_EXTENSION_VERSION = '${manifest.version}';`),'retained Browser Companion request version must match the manifest');

// Current page capture stays bounded and does not transmit the page body.
must(background.includes("meta[name=\"description\"]"),'page description metadata capture missing');
must(background.includes("meta[name=\"author\"]"),'page author metadata capture missing');
must(background.includes("meta[property=\"og:site_name\"]"),'page site-name metadata capture missing');
must(background.includes("document.documentElement?.lang"),'page language metadata capture missing');
must(background.includes("selected_text:utf8Limit(String(x.selected_text || ''),12000)"),'ephemeral selected text must be bounded to 12 KB');
must(background.includes("page_text_sha256:"),'page body fingerprint missing');
const payloadStart=background.indexOf('function browserContextPayload');
const payloadEnd=background.indexOf('async function contextualNow',payloadStart);
const payloadBlock=background.slice(payloadStart,payloadEnd);
must(payloadStart>=0&&payloadEnd>payloadStart,'browser context payload helper missing');
must(!/page_text\s*:/.test(payloadBlock),'raw page text must never be transmitted as browser context');
must(!payloadBlock.includes('pageText'),'raw page body must remain local');

// Contextual Now uses the existing durable-token endpoint and Agent capability.
must(background.includes("action:'context_feed'"),'contextual Now request missing');
must(background.includes("case 'context_now': return contextualNow"),'contextual Now message bridge missing');
must(background.includes("case 'context_handoff': return contextHandoff"),'Agent Chat context handoff bridge missing');
must(extensionApi.includes("if($action==='context_feed')"),'server contextual Now action missing');
must(extensionApi.includes("vp3_extension_session_has_capability_v2001($session,'agent.message')"),'contextual Now must retain live Agent capability enforcement');
must(extensionApi.includes("has_permission('chat.access',$user)"),'contextual Now must retain live Chat permission enforcement');
must(extensionApi.includes('vp3_browser_context_validate_v2130($rawContext)'), 'server page-context validation missing');
must(extensionApi.includes("vp3_browser_context_relationships_v2130($pdo,$user,$context,(array)($session['capabilities']??[]))"), 'server relationship resolution must receive live extension capabilities');
must(extensionApi.includes('vp3_browser_contextualize_feed_v2130($feed,$context,$relations)'), 'server cognitive contextualization missing');
must(extensionApi.includes("'persistence'=>'none_until_explicit_action'"),'explicit no-persistence response contract missing');

// The resolver itself is read-only. Persistence remains in explicit existing actions.
must(context.includes("const VP3_BROWSER_CONTEXT_V2130='browser-context-v2130-20260919'"),'context build marker missing');
must(context.includes("'ephemeral'=>true"),'ephemeral context marker missing');
must(!/\bINSERT\s+INTO\b/i.test(context),'context resolver must not insert durable records');
must(!/\bUPDATE\s+[a-z_]/i.test(context),'context resolver must not update durable records');
must(!/\bDELETE\s+FROM\b/i.test(context),'context resolver must not delete durable records');
must(!context.includes('vp3_browser_source_ensure_v2050('),'viewing a page must not create a Source');
must(context.includes('vp3_browser_source_this_page_v2050('),'existing Source/annotation lookup must reuse canonical read path');
must(context.includes("in_array('team.chat.read',$extensionCapabilities,true)"),
  'extension relationship UI must gate Source/Team activity on live team.chat.read');
must(extensionApi.includes("(array)($session['capabilities']??[])"),
  'extension contextual resolver must receive the live device capability set');
must(context.includes('vp3_research_project_role_v2060'),'Research relationship discovery must reauthorize membership');
must(context.includes('search_knowledge($query,$user,5)'),'Knowledge relationship discovery must use scoped Knowledge search');
must(context.includes('user_calendar_events_v1300($pdo,$user,$from,$to)'),'meeting/calendar discovery must use user-scoped calendar');
must(context.includes('crm_v180_can_manage($user)'),'CRM company/contact discovery must remain admin-scoped');
must(context.includes("p.is_public=1 AND u.is_active=1"),'profile relationship discovery must use public active profiles only');
must(context.includes("isset($caps['team.chat.read'])")&&context.includes("'kind'=>'manual_follow'"),'Follow suggestion must use canonical live capability');
must(context.includes("'kind'=>'manual_flow'")&&context.includes("'label'=>'Share with Team'"),'Team sharing must hand off to an explicit flow');
must(context.includes("'label'=>'Save to Knowledge'")&&context.includes('draft a proposed VP3 Knowledge entry for my approval'),'Knowledge suggestion must remain proposal-only');
must(context.includes("'label'=>'Create task'")&&context.includes('draft a proposed task for my approval'),'task suggestion must remain proposal-only');
must(context.includes("'label'=>'Add to Research'")&&context.includes('draft research note and the project choice for my approval'),'Research suggestion must remain proposal-only');

// Server contextualizes the canonical Cognitive Runtime instead of implementing a second Agent.
must(context.includes('vp3_browser_contextualize_feed_v2130'),'cognitive feed contextualizer missing');
must(context.includes("$item['context_score']=$score;"),'page relevance score missing');
must(context.includes("$item['context_reason']='Related to the current page context';"),'page relevance reason missing');
must(context.includes('vp3_browser_context_insights_v2130'),'evidence-based page insights missing');

// Browser Now UX.
for(const id of ['nowContextStrip','nowContextTitle','nowContextMeta','toggleNowContextBtn','nowContextualCount','nowContextPanel','nowRelationshipSummary','nowRelationshipList','nowContextActions']){
  must(html.includes(`id="${id}"`),`missing contextual Now element ${id}`);
}
must(panel.includes('nowContextIgnored=false'),'temporary page-context ignore state missing');
must(panel.includes("const payload=await msg('context_now',{capture:capture})"),'page-aware Now load missing');
must(panel.includes("ui.openAgentChatBtn.textContent='Ask Agent about this page'"),'Ask Agent page action missing');
must(panel.includes("contextSuggestions.forEach"),'contextual suggestion renderer missing');
must(panel.includes("nowContextIgnored=!nowContextIgnored"),'Use/Ignore page control missing');
must(
  panel.includes("['now','this_page','live','alerts','search']")
    ||panel.includes("['now','agent','this_page','live','alerts','search']")
    ||panel.includes("['now','agent','memory','this_page','live','alerts','search']")
    ||panel.includes("['now','agent','execution','memory','this_page','live','alerts','search']")
    ||panel.includes("['now','agent','delegation','execution','memory','this_page','live','alerts','search']"),
  'active page watcher must include Now'
);
must(panel.includes("if(activeView==='now')await loadNow(true)"),'active-tab changes must refresh page-aware Now');
must(panel.includes("next=(x.source_url||'')+'\\n'+(x.title||'')"),
  'SPA-style title changes must invalidate current page context');
must(panel.includes("pill contextual")||css.includes('.pill.contextual'),'contextual card marker missing');
must(css.includes('.now-context-panel'),'context panel styling missing');
must(css.includes('.now-context-insight'),'context insight styling missing');

// Agent handoff stays fragment-only and memory-only in the browser.
must(background.includes("/chat.php#vp3-browser-context="),'Agent page context must use URL fragment handoff');
must(!background.includes("/chat.php?vp3-browser-context="),'page context must never be placed in a query string');
must(chatContext.includes("const HASH_KEY = 'vp3-browser-context='"),'Agent Chat fragment reader missing');
must(chatContext.includes("history.replaceState(history.state,'',location.pathname+location.search)"),'Agent Chat must remove fragment immediately');
must(!chatContext.includes('localStorage')&&!chatContext.includes('sessionStorage'),'ephemeral Browser context must not use browser storage');
must(chatContext.includes("className='chat-browser-context-v2130'"),'temporary Browser context strip missing');
must(chatContext.includes("consume:clear"),'one-turn Browser context consume contract missing');
must(chat.includes('chat-browser-context-v2130.js'),'Agent Chat must load Browser context runtime');
must(chat.includes('chat-browser-context-v2130.css'),'Agent Chat must load Browser context styles');

// Sending to Agent is explicit. Context is consumed only after successful API response.
must(chatJs.includes("payload.agent_context=browserAgentContext"),'Agent Chat send must attach active ephemeral context');
const apiCallIndex=chatJs.indexOf('const data = await api(payload);');
const consumeIndex=chatJs.indexOf('browserContextRuntime.consume();',apiCallIndex);
must(apiCallIndex>=0&&consumeIndex>apiCallIndex,'ephemeral context must be consumed only after successful Agent response');

// The Agent server trusts neither client relationships nor prior extension authorization.
must(chatApi.includes("unset($rawAgentContext['browser_context']);"),'Agent server must remove untrusted raw Browser context before enrichment');
must(chatApi.includes('vp3_browser_context_validate_v2130(['),'Agent server must revalidate Browser page identity');
must(chatApi.includes('vp3_browser_context_relationships_v2130($pdo,$user,$browserContext)'), 'Agent server must re-resolve relationships for the signed-in user');
must(chatApi.includes("$persistedAgentContext=$agentContext;")&&chatApi.includes("unset($persistedAgentContext['browser_context']);"),'full Browser context must not persist into Agent message metadata');
must(chatApi.includes("'source'=>'browser-context:ephemeral'"),'Agent response may expose only a minimal ephemeral page source marker');
must(agentSurface.includes("function agent_surface_v131_browser_context"),'canonical Agent Surface must explicitly sanitize Browser context');
must(agentSurface.includes("'browser_context'=>null"),'Agent Surface schema must declare Browser context');
must(agentSurface.includes("'authentication_authority'=>false")&&agentSurface.includes("'instructions_authority'=>false"),
  'Browser page context must be data-only and non-authoritative');
must(agentSurface.includes("$browserContext=agent_surface_v131_browser_context($raw);"),
  'Agent Surface sanitizer must preserve only its bounded Browser context representation');
must(agentSurface.includes("'selected_text'=>agent_surface_v131_text($page['selected_text']??'',12000)"),
  'Agent Surface selected text must remain bounded');

// No autonomous Cognitive Runtime tool execution is added in v21.30.
for(const file of [background,panel,context,extensionApi,chatContext]){
  must(!file.includes('execute_tool')&&!file.includes('tool_execute'),'v21.30 must not add autonomous Cognitive Runtime tool execution');
}

console.log('VP3 Browser Companion Contextual Agent Actions v21.30 contract passed.');
