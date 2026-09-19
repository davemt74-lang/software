import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path,'utf8');
const must = (value,message) => assert.equal(Boolean(value),true,message);

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const html=read('browser-companion/sidepanel.html');
const js=read('browser-companion/sidepanel.js');
const css=read('browser-companion/sidepanel.css');
const api=read('api/extension-cognitive-now-v2120.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');

const versionParts=String(manifest.version||'').split('.').map(Number);
must(versionParts.length===3&&(versionParts[0]>21||(versionParts[0]===21&&versionParts[1]>=2)),'v21.20+ manifest version missing');
must(/const VP3_EXTENSION_VERSION = '21\.(?:[2-9]|[1-9]\d+)\.\d+';/.test(background),'v21.20+ request version missing');
must(background.includes("'/api/extension-cognitive-now-v2120.php'"),'Cognitive Now extension endpoint missing');
must(background.includes("case 'cognitive_now': return cognitiveNow();"),'Cognitive Now state bridge missing');
must(background.includes("case 'cognitive_action': return cognitiveAction("),'Cognitive Now action bridge missing');

for(const id of ['nowTab','nowView','refreshNowBtn','openAgentChatBtn','restoreNowBtn','nowStatus','nowAttentionCount','nowItemCount','nowEmpty','nowFeed']){
  must(html.includes(`id="${id}"`),`missing Agent Now element ${id}`);
}
must(html.indexOf('id="nowTab"')<html.indexOf('id="thisPageTab"'),'Now must be the first Browser Companion view');
must(html.includes('id="nowTab" class="feed-tab active"'),'Now must be the default active view');
must(html.includes('id="thisPageView" hidden'),'This Page must yield to Now on initial load');

must(js.includes("activeView='now'"),'Now must be the initial client view');
must(js.includes("ui.thisPageTab.disabled=!(state&&state.connected&&(canRead||canShare))"),'This Page must follow live read/share access');
must(js.includes("activeView==='now'&&!c.has('agent.message')"),'Now must fall back when Agent access is unavailable');
must(js.includes("setView('this_page')"),'authorized This Page fallback missing');
must(js.includes("function renderNow(feed)"),'Agent Now renderer missing');
must(js.includes("function cognitiveCard(section,item)"),'canonical cognitive card renderer missing');
must(js.includes("msg('cognitive_now')"),'Agent Now live load missing');
must(js.includes("cognitiveAction('hide'"),'Agent Now hide action missing');
must(js.includes("cognitiveAction('explain'"),'Agent Now Why explanation missing');
must(js.includes("cognitiveAction('plan_decide'"),'proposal decision action missing');
must(js.includes("b.dataset.action==='cognitive_plan_accept'?'accept':'dismiss'"),'plan decisions must remain accept/dismiss only');
must(js.includes("Plan actions remain proposal/review only."),'proposal-only UI boundary missing');
must(js.includes("cognitiveData&&cognitiveData.agent_url||'/chat.php'"),'Agent Chat handoff missing');
must(!js.includes('vp3:cognitive-card-tool-request'),'extension must never dispatch Cognitive Runtime tool execution events');
must(!js.includes('tool_id'),'extension UI must not send Cognitive Runtime tool identifiers');
must(css.includes('.now-card[data-attention="1"]'),'attention card styling missing');

must(api.includes('vp3_extension_apply_cors_v2001()'),'extension Cognitive API must use hardened CORS');
must(api.includes("HTTP_X_VP3_CONTRACT_VERSION"),'extension Cognitive API must enforce contract header');
must(api.includes('vp3_extension_session_authenticate_v2001($pdo)'),'extension Cognitive API must authenticate the durable device bearer');
must(api.includes("vp3_extension_session_has_capability_v2001($session,'agent.message')"),'Agent capability gate missing');
must(api.includes("has_permission('chat.access',$user)"),'live Agent Chat permission gate missing');
must(api.includes('vp3_cognitive_feed_compose_v530($pdo,$user,$namespace,false)'),'server-side canonical feed composition missing');
must(api.includes('vp3_cognitive_render_card_v500($pdo,$user,$namespace,$request)'),'server-side canonical card authorization/rendering missing');
must(api.includes("'type'=>'agent_review'"),'tool/prompt actions must be downgraded to Agent review');
must(api.includes("in_array($type,['prompt','tool'],true)"),'tool/prompt sanitization missing');
must(api.includes('vp3_cognitive_feed_hide_v530'),'canonical feed hide state missing');
must(api.includes('vp3_cognitive_learning_explain_v540'),'canonical Why explanation missing');
must(api.includes('vp3_cognitive_learning_feedback_v540'),'canonical learning feedback missing');
must(api.includes("'surface'=>'browser_companion_now'"),'Browser Companion learning surface attribution missing');
must(api.includes('vp3_cognitive_planning_decide_v550'),'canonical proposal decision helper missing');
must(api.includes("'authority'=>'proposal_only'"),'plan decision response must state proposal-only authority');
must(!api.includes('execute_tool'),'extension Cognitive API must not execute tools');
must(!api.includes('tool_execute'),'extension Cognitive API must not execute tools');

for(const file of [
  'cognitive-runtime-v500.php','cognitive-runtime-meetings-v500.php','cognitive-cards-v520.php',
  'cognitive-learning-v540.php','cognitive-planning-v550.php','cognitive-orchestration-v560.php',
  'cognitive-memory-v570.php','cognitive-feed-v530.php','cognitive-presentation-v510.php'
]){
  must(bootstrap.includes(`require_once __DIR__.'/${file}';`),`integrated Cognitive Runtime loader missing ${file}`);
}
must(bootstrap.includes("require_once __DIR__.'/extension-device-token-v2100.php';"),'v21 durable device-token loader must survive Cognitive integration');
must(upgrade.includes('vp3_extension_device_token_schema_ready_v2100()'),'v21 token schema readiness must survive integration');
must(upgrade.includes('vp3_extension_device_token_ensure_schema_v2100();'),'v21 token schema upgrade must survive integration');
for(const fn of ['vp3_cognitive_schema_ready_v500()','vp3_cognitive_feed_schema_ready_v530()','vp3_cognitive_memory_schema_ready_v570()']){
  must(upgrade.includes(fn),`Cognitive schema readiness missing ${fn}`);
}

console.log('VP3 Browser Companion Cognitive Now v21.20 contract passed.');
