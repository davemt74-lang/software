import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>assert.equal(Boolean(v),true,m);

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.js');
const html=read('browser-companion/sidepanel.html');
const css=read('browser-companion/sidepanel.css');

must(manifest.version==='21.5.0','v21.50 manifest version missing');
must(background.includes("const VP3_EXTENSION_VERSION = '21.5.0';"),'v21.50 request version missing');

// One VP3 context-menu tree, no duplicated legacy selection command.
must(background.includes("id:'vp3-quick-root'"),'VP3 quick-action root missing');
for(const action of ['ask_page','summarize','compare_knowledge','prepare_meeting','add_research','save_knowledge','create_task','share_team','annotate']){
  must(background.includes(`${action}:{`)||background.includes(`${action}: {`),`missing quick action ${action}`);
}
must(!background.includes("id: 'vp3-share-selection'"),'legacy duplicate selection menu must be removed');
must(background.includes("contexts:['page','selection','link','image','video','audio']"),'context menu must support page/selection/link/media');

// Capture context stays bounded and ephemeral.
must(background.includes("utf8Limit(String(info.selectionText).trim(), 12000)"),'selection must stay bounded');
must(background.includes("info?.linkUrl"),'link target context missing');
must(background.includes("info?.srcUrl"),'media/image target context missing');
must(background.includes("kind:mediaType === 'audio' ? 'audio_reference' : 'video_reference'"),'audio/video quick target reference missing');
must(background.includes("chrome.storage.session.set"),'composer quick-action handoff must use session storage');
must(background.includes("pending_quick_action_v2150"),'pending quick-action session key missing');
must(!/chrome\.storage\.local\.set\([^)]*pending_quick_action_v2150/s.test(background),'quick-action context must not use durable local storage');
must(background.includes("age > 5 * 60 * 1000"),'pending quick action must expire after five minutes');
must(background.indexOf("chrome.storage.session.remove('pending_quick_action_v2150')") < background.indexOf("age > 5 * 60 * 1000"),'pending quick action must be consumed once before use');

// Canonical server suggestions and live permissions remain authoritative.
must(background.includes("const contextual = await contextualNow(capture);"),'quick Agent action must use contextual Now');
must(background.includes(".find(item => item && item.id === config.suggestion)"),'quick action must resolve server-proposed suggestion');
must(background.includes("const handoff = await contextHandoff(contextual.agent_payload"),'Agent handoff must reuse ephemeral v21.30 contract');
must(background.includes("const account = await currentAccount();"),'composer flow must refresh live account');
must(background.includes("liveCapabilities.has('team.share.create')"),'composer flow must enforce live Team share capability');
must(!background.includes('execute_tool')&&!background.includes('tool_execute'),'quick actions must not autonomously execute Cognitive tools');

// Persistent sidebar toolbar mirrors right-click actions.
for(const id of ['quickActionsCard','quickAskBtn','quickSummarizeBtn','quickCompareBtn','quickResearchBtn','quickKnowledgeBtn','quickTaskBtn','quickTeamBtn','quickAnnotateBtn']){
  must(html.includes(`id="${id}"`),`missing quick-action sidebar element ${id}`);
}
must(css.includes('.quick-action-grid'),'quick-action toolbar styling missing');
must(panel.includes("runSidebarQuickActionV2150"),'sidebar quick-action runner missing');
must(panel.includes("ui.quickResearchBtn.disabled=!quickPageOk||!c.has('agent.message')||!c.has('knowledge.write')"),'Research quick action capability gate missing');
must(panel.includes("ui.quickKnowledgeBtn.disabled=!quickPageOk||!c.has('agent.message')||!c.has('knowledge.write')"),'Knowledge quick action capability gate missing');
must(panel.includes("ui.quickTaskBtn.disabled=!quickPageOk||!c.has('agent.message')||!c.has('task.propose')"),'Task quick action capability gate missing');
must(panel.includes("ui.quickTeamBtn.disabled=!quickPageOk||!c.has('team.share.create')"),'Team quick action capability gate missing');
must(panel.includes("ui.quickAnnotateBtn.disabled=!quickPageOk||!c.has('team.share.create')"),'Annotate quick action capability gate missing');

// Persistence-capable actions remain explicit composer/proposal flows.
const quickStart=background.indexOf('const VP3_QUICK_ACTIONS_V2150');
const quickEnd=background.indexOf('async function disconnect()',quickStart);
const quickBlock=background.slice(quickStart,quickEnd);
must(quickBlock.includes("share_team:{ flow:'share_team'"),'Team quick action must route to composer');
must(quickBlock.includes("annotate:{ flow:'annotate'"),'Annotate quick action must route to composer');
must(!quickBlock.includes("createRichShare("),'context-menu quick actions must not create Browser Shares automatically');
must(!quickBlock.includes("sourceFeedAction("),'context-menu quick actions must not mutate Source state directly');
must(!quickBlock.includes("browserShareAction("),'context-menu quick actions must not mutate Knowledge/Task directly');
must(panel.includes("Nothing is saved until you publish."),'annotation composer must state no implicit persistence');
must(panel.includes("Choose a Team or conversation under Deliver to, then publish this annotation."),'Team flow must require explicit destination/publish');

console.log('VP3 Browser Companion Contextual Quick Actions v21.50 contract passed.');
