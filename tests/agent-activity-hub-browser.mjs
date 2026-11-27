import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright';

const browser = await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
const page = await browser.newPage({viewport:{width:1280,height:900}});
page.setDefaultTimeout(10000);
const waitForPanelEntrance = async panel => {
  const element = await panel.elementHandle();
  assert.ok(element,'Top of Mind panel exists before measuring its viewport bounds');
  try {
    // Read the current animation set each frame so cancellation or replacement
    // cannot leave this check waiting on an obsolete finished promise.
    await page.waitForFunction(target => target.isConnected && target.getAnimations().every(animation => {
      const timing = animation.effect?.getComputedTiming();
      return !timing || !Number.isFinite(timing.endTime)
        || (!animation.pending && ['finished','idle'].includes(animation.playState));
    }),element,{polling:'raf'});
  } finally {
    await element.dispose();
  }
};
const errors = [];
const requests = [];
let gated;
page.on('pageerror',error => { errors.push(error.message); if (process.env.VP3_TEST_DEBUG) console.log(error.message); });
if (process.env.VP3_TEST_DEBUG) await page.addInitScript(() => addEventListener('message',event => { if (event.data?.channel==='vp3-orb') console.log(JSON.stringify({kind:event.data.kind,conversation:event.data.conversation,conversation_id:event.data.conversation_id,accepted:event.data.accepted})); }));
if (process.env.VP3_TEST_DEBUG) page.on('console',message => console.log(message.text()));
await page.route('https://activity.test/',route => route.fulfill({contentType:'text/html',body:`<!doctype html>
<meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0}#sfOnlineRailV109{position:fixed;right:0;top:0}</style>
<main><section id="chatThread" data-view="chat"></section><form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form></main>
<button id="chatAgentBriefButton" type="button">Agent status<em data-agent-attention-badge hidden></em></button><section id="chatAgentBriefPopover" hidden><div data-agent-brief-content></div><footer></footer></section><aside id="sfOnlineRailV109"><div id="sfChatSettingsRailSlotV132"></div></aside>`}));
await page.route('https://activity.test/api/presentation**',route => route.fulfill({json:{ok:true,state:{brief:{agent_name:'Longfellow',active:true,attention_count:142,counts:{}},poll_seconds:30}}}));
await page.route('https://activity.test/api/hub**',async route => {
  const url = new URL(route.request().url());
  assert.equal(route.request().method(),'GET');
  assert.equal(route.request().headers()['x-vp3-working-attention'],'fixture-binding');
  assert.equal(url.searchParams.get('agent_id'),'7');
  const cid = Number(url.searchParams.get('conversation_id'));
  requests.push(cid);
  const state = {
    contract:'vp3.agent.activity.v1',agent_name:'Longfellow',current_chat:{title:'Workshop research'},owner_user_id:1,agent_id:7,conversation_id:cid,working_attention_binding:'fixture-binding',observed_at:new Date().toISOString(),coverage:{},
    sections:[
      {id:'reminders',label:'Reminders',items:[{id:'reminder:1',kind:'reminder',title:'Prepare workshop',detail:'Today',actions:[{kind:'task_notes',label:'Open reminders',view:'today'}]}]},
      {id:'lists',label:'Task lists',items:[{id:'list:4',kind:'list',title:'Workshop items',detail:'2 open',actions:[{kind:'task_notes',label:'Open list',list_id:4}]}]},
      {id:'history',label:'Recent Chats',items:[{id:'chat:43',kind:'chat',title:'Saved research',detail:'Earlier',actions:[{kind:'chat',label:'Resume Chat',conversation_id:43,href:'/chat.php?agent=7&conversation_id=43'}]}]},
      {id:'youtube',label:'YouTube',items:[{id:'youtube:3',kind:'youtube',title:'Comet research <img src=x>',detail:'Search results',actions:[{kind:'youtube',label:'Review results',conversation_id:42,message_id:8}]}]},
      {id:'songs',label:'Songs',items:[{id:'song:2',kind:'song',title:'Workshop song',detail:'Resume at 0:42',actions:[{kind:'song',label:'Resume song',track_id:2,href:'/track.php?id=2'}]}]},
      {id:'calendar',label:'Upcoming events',items:[{id:'event:8',kind:'calendar',title:'Workshop starts',detail:'Tomorrow',actions:[]}]},
      {id:'missions',label:'Missions',items:[{id:'mission:one',kind:'mission',title:'Comet investigation',detail:'Waiting for review',actions:[{kind:'mission',label:'Open mission',mission_id:'mission-one'}]}]},
      {id:'canvases',label:'Canvas drafts',items:[{id:'canvas:one',kind:'canvas',title:'Workshop checklist',detail:'Draft',actions:[{kind:'canvas',label:'Review canvas',canvas_id:'canvas-one',revision:2}]}]}
    ]
  };
  const collection = url.searchParams.get('collection');
  if(url.searchParams.get('view')==='curiosity'){
    const source={attachment_review_id:'a'.repeat(32),agent_id:7,conversation_id:cid,label:'Saved attachment review: Quote Q-104.pdf',href:'/chat.php?agent=7&conversation_id='+cid+'&attachment_review='+'a'.repeat(32)};
    state.curiosity_report={source_coverage:'ready',themes:[],attention:null,pending_question:null,
      next_question:{id:'b'.repeat(32),thread_id:'c'.repeat(32),title:'Quote Q-104.pdf',question:'What delivery date should we ask about?',state:'open',conversation_id:cid,message_id:0,
        sources:[source,{...source,agent_id:8,label:'Foreign private review'}]},
      threads:[{id:'d'.repeat(32),title:'Earlier quote question',state:'answered',progress:{answered:1,total:1},questions:[
        {id:'e'.repeat(32),question:'Which delivery window should we request?',state:'answered',answer:'Please ask for the afternoon.',conversation_id:cid,message_id:0,sources:[source]}]}]};
  }
  if (url.searchParams.get('view') === 'location') {
    state.sections = [];
    state.specialists = [{id:'location',state:cid ? 'armed' : 'idle',progress:null,conversation_id:cid,
      detail:cid ? '1 active arrival alert.' : 'No arrival alerts in this Chat.'}];
  }
  if (url.searchParams.get('view') === 'operator') {
    state.sections = [];
    const checked=Math.floor(Date.now()/1000);
    state.specialists = [{id:'operator',state:'idle',progress:null,conversation_id:cid,detail:'Phone calling is available.',
      operator_worker:{state:'reporting',last_verified_epoch:checked-1,checked_at:checked,fresh_until:checked+24,execution_enabled:false,live_certified:false}}];
    state.operator_history={coverage:'ready',recording_coverage:'ready',calls:[
      {call_id:'a'.repeat(32),contact_id:5,contact_name:'Taylor Caller',peer_e164:'+16025550111',direction:'outbound',state:'completed',created_at:'2026-11-23T10:00:00Z',duration_seconds:90,recording:{state:'ready',transcript_saved:true,report_saved:true}},
      {call_id:'b'.repeat(32),contact_id:5,contact_name:'Taylor Caller',peer_e164:'+16025550112',direction:'inbound',state:'no_answer',created_at:'2026-11-23T09:00:00Z',duration_seconds:0,recording:{state:'none',transcript_saved:false,report_saved:false}},
      {call_id:'legacy-1',contact_id:0,contact_name:'',peer_e164:'+16025550113',direction:'inbound',state:'voicemail_ready',created_at:'2026-11-23T08:00:00Z',duration_seconds:30,recording:{state:'received',transcript_saved:false,report_saved:false}}
    ]};
    state.operator_calendar={coverage:'ready',execution_enabled:false,appointments:[{event_id:91,title:'Planned phone call — Taylor Caller',label:'Tue, Nov 24, 10:00 AM MST',start_at:'2026-11-24T17:00:00Z',end_at:'2026-11-24T17:10:00Z',url:'/vp3/calendar-event.php?id=91',execution_enabled:false}]};
    state.operator_followups={coverage:'ready',reports:[{ref:'followup:'+'c'.repeat(32),call_id:'a'.repeat(32),created_at:'2026-11-23T10:00:00Z',items:[{key:'d'.repeat(64),revision:'e'.repeat(64),session_id:12,text:'Confirm the proposal deadline.',next_step:'Review timing with the contact.',review_state:'unreviewed',source_url:'/vp3/artist-listening-evidence.php?session=12&plugin=qa&section=unanswered&item=question-1&page=1'}]}]};
  }
  if (collection === 'reviews') state.sections = [{id:'office_reviews',label:'Completed reviews',items:[]}];
  if (collection === 'all') {
    state.sections = state.sections.filter(section=>['reminders','lists'].includes(section.id));
    state.sections[1].items[0].actions[0].kind = 'list_inventory';
    state.sections.push({id:'tasks',label:'Tasks',items:[]});
    state.pages = {reminders:{next_cursor:null},tasks:{next_cursor:null},lists:{next_cursor:null}};
  }
  if (collection === 'list_items') {
    state.list_id = Number(url.searchParams.get('list_id'));
    state.sections = [{id:'list_items',label:'Workshop items',items:[{id:'task:9',title:'Order workshop supplies',actions:[]}]}];
    state.pages = {list_items:{next_cursor:null}};
  }
  if (gated) { const gate=gated;gated=null;gate.started();await gate.release; }
  try { await route.fulfill({json:{ok:true,state}}); } catch (_error) {}
});

await page.route('https://activity.test/runtime/*.js',async route => {
  const file = new URL(route.request().url()).pathname.split('/').pop();
  await route.fulfill({contentType:'text/javascript',body:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
});
const outerScripts = '<script src="/runtime/agent-eyes-core.js"></script><script src="/runtime/chat-specialist-rail-v1.js"></script><script src="/runtime/agent-activity-hub-v1.js"></script><script src="/runtime/agent-top-of-mind-v1.js"></script><script src="/runtime/agent-orb.js"></script>';
const orbCss = await fs.readFile(new URL('../agent-orb.css',import.meta.url),'utf8') + await fs.readFile(new URL('../agent-activity-hub-v1.css',import.meta.url),'utf8');
await page.route('https://activity.test/standalone**',route => route.fulfill({contentType:'text/html',body:`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>${orbCss}</style><h1>Workspace</h1><script>
window.VP3_ORB_CONFIG={ownerKey:'1',agentId:7,workingAttentionBinding:'fixture-binding',activityEndpoint:'/api/hub'};
window.VP3_ORB_ADAPTER={ownerKey:'1',chatUrl:'/embedded?mode=${new URL(route.request().url()).searchParams.get('mode')||'ready'}',state:()=>({state:'idle'}),isMainChat:()=>false,conversationUrl:ref=>'/embedded?mode=${new URL(route.request().url()).searchParams.get('mode')||'ready'}&conversation_id='+ref.id+'&agent='+ref.agent,stop:()=>{}};
</script>${outerScripts}`}));
await page.route('https://activity.test/embedded**',route => route.fulfill({contentType:'text/html',body:`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><main><section id="chatThread"></section><form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form><audio id="childAudio"></audio><button id="nativeResume">Resume</button></main><script>
window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'fixture-binding'};
window.VP3_ORB_CONFIG={ownerKey:'1',agentId:7,workingAttentionBinding:'fixture-binding',activityEndpoint:'/api/hub'};
window.childReady=${!['wait','timeout'].includes(new URL(route.request().url()).searchParams.get('mode'))};window.mode=${JSON.stringify(new URL(route.request().url()).searchParams.get('mode')||'ready')};
window.releaseReady=()=>{window.childReady=true;window.dispatchEvent(new Event('vp3:chat-context-ready'));};
window.currentChat=42;window.views=0;window.calls=[];window.keptAudio=document.getElementById('childAudio');
window.STONEFELLOW_CHAT_CONTINUITY={agentId:()=>7,conversationId:()=>window.currentChat,isReady:()=>window.childReady,isRestoring:()=>!window.childReady,openConversation:async(id,options)=>{window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.currentChat=id;if(options?.messageId){const row=document.createElement('article');row.dataset.chatMessageId=String(options.messageId);row.dataset.chatConversationId=String(id);document.getElementById('chatThread').append(row);}window.dispatchEvent(new Event('vp3:chat-context-ready'));await new Promise(resolve=>setTimeout(resolve,20));window.calls.push(['chat',id,options]);return true;},playActivityTrack:async id=>{await Promise.resolve();window.calls.push(['song',id]);return window.mode!=='denied';}};
// Embedded actions also work with an older cached voice facade.
window.STONEFELLOW_CHAT_CONTINUITY_V87=window.STONEFELLOW_CHAT_CONTINUITY;
window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>window.currentChat};
window.VP3CodingCanvasV1={open:async(...args)=>{window.calls.push(['canvas',...args]);return true;}};
window.VP3AgentMissionsV1={open:async id=>{window.calls.push(['mission',id]);return true;}};
window.VP3_ORB_ADAPTER={ownerKey:'1',state:()=>({state:'idle'}),conversationRef:()=>window.childReady?{id:window.currentChat,agent:7}:null,stop:()=>{}};
</script>${outerScripts}<script>window.VP3ChatSpecialists.update('coding',{label:'Coding Agent',status:'Building draft',viewLabel:'Open code canvas',onView:()=>window.views++});</script>`}));

try {
  await page.goto('https://activity.test/');
  await page.evaluate(() => {
    window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'fixture-binding'};
    window.VP3_COGNITIVE_PRESENTATION_V510={agentId:7,workingAttentionBinding:'fixture-binding',endpoint:'/api/presentation',csrf:'fixture'};
    window.VP3_ORB_CONFIG={ownerKey:'1',agentId:7,workingAttentionBinding:'fixture-binding',activityEndpoint:'/api/hub',
      specialistSettings:{developmentAdmin:'/admin/agent-development.php'}};
    window.VP3_CHAT_SPECIALIST_PRESENCE={owner_user_id:1,agent_id:7,states:{location:'unavailable'},phoneTrackingUrl:'/vp3/phone-tracking.php'};
    window.currentChat=42;
    window.views=0;
    window.calls=[];
    window.STONEFELLOW_CHAT_CONTINUITY={agentId:()=>7,conversationId:()=>window.currentChat,isReady:()=>true,isRestoring:()=>false,
      openConversation:async id=>{window.calls.push(['chat',id]);window.currentChat=id;return true;},
      playActivityTrack:async id=>{window.calls.push(['song',id]);return true;},
      stageSpecialistPrompt:(prompt,cid)=>{
        const input=document.getElementById('chatInput');
        if(cid!==window.currentChat||input.value.trim())return false;
        window.calls.push(['example',prompt,cid]);input.value=prompt;input.focus();return true;
      }};
    window.VP3CodingCanvasV1={open:async(...args)=>{window.calls.push(['canvas',...args]);return true;}};
    window.VP3AgentMissionsV1={open:async id=>{window.calls.push(['mission',id]);return true;},
      prepareExample:(objective,cid)=>{window.calls.push(['mission-example',objective,cid]);return cid===window.currentChat;}};
    window.VP3TaskNotes={open:ref=>window.calls.push(['note',ref])};
    window.VP3ParticipantContinuity={selectedContext:()=>window.participantSelected?{participant_id:14}:null,clearSelection:()=>{}};
    window.VP3_ORB_ADAPTER={ownerKey:'1',chatUrl:'/chat.php',state:()=>({state:'idle'}),conversationRef:()=>({id:window.currentChat,agent:7}),conversationUrl:ref=>'/chat.php?agent=7&conversation_id='+ref.id,isMainChat:()=>true,focusChat:()=>{},stop:()=>{}};
  });
  for (const file of ['agent-orb.css','agent-activity-hub-v1.css','chat-specialist-rail-v1.css']) await page.addStyleTag({content:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
  for (const file of ['agent-eyes-core.js','chat-specialist-rail-v1.js','agent-activity-hub-v1.js','agent-top-of-mind-v1.js','agent-orb.js','chat-cognitive-presentation-v510.js']) await page.addScriptTag({content:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
  assert.equal(requests.length,0,'A closed hub does not fetch private activity');
  await page.waitForFunction(()=>document.querySelector('[data-agent-brief-content]').getAttribute('aria-busy')==='false');
  const launcher=page.locator('#chatAgentBriefButton');
  const badge=launcher.locator('[data-agent-attention-badge]');
  assert.equal(await launcher.getAttribute('aria-label'),'Top of Mind');
  assert.equal(await badge.isVisible(),false,'The shared launcher hides the legacy owner-wide count');
  assert.equal(await badge.textContent(),'');
  await page.evaluate(async()=>{window.savedHub=window.VP3AgentActivityHub;delete window.VP3AgentActivityHub;await window.VP3_COGNITIVE_PRESENTATION.refresh();});
  assert.equal(await badge.textContent(),'99+','The standalone legacy Brief keeps its count');
  assert.equal(await launcher.getAttribute('aria-label'),'Longfellow · Active');
  await launcher.click();
  assert.equal(await page.locator('#chatAgentBriefPopover').isVisible(),true);
  await page.evaluate(()=>{window.VP3_COGNITIVE_PRESENTATION.closeBrief();window.VP3AgentActivityHub=window.savedHub;window.dispatchEvent(new Event('vp3:agent-activity-ready'));});
  assert.equal(await launcher.getAttribute('aria-label'),'Top of Mind');
  assert.equal(await badge.isVisible(),false);
  await page.locator('#chatInput').focus();
  const hub=page.locator('#vp3AgentActivityHub');
  const liveWork=page.getByRole('dialog',{name:'Coding Agent'});
  await page.evaluate(() => window.VP3ChatSpecialists.update('coding',{label:'Coding Agent',title:'Checklist',status:'Building draft',viewLabel:'Open code canvas',onView:()=>window.views++}));
  assert.equal(await page.evaluate(()=>document.activeElement.id),'chatInput','Worker updates preserve composer focus');
  assert.equal(await liveWork.count(),0,'Work appears only when selected');
  const codingRail = page.getByRole('button',{name:'Coding Agent',exact:true});
  const specialist = page.locator('#vp3ChatSpecialistActivity');
  await codingRail.click();
  await specialist.getByText('Building draft',{exact:true}).waitFor();
  assert.equal(await specialist.getByRole('button',{name:'Try a demo canvas'}).count(),0);
  assert.equal(await hub.isVisible(),false,'The rail opens a specialist preview without opening the orb');
  assert.equal(await specialist.getByText('Prepare workshop',{exact:true}).count(),0);
  const previewBox = await specialist.boundingBox();
  await specialist.getByRole('button',{name:'Full report',exact:true}).click();
  await specialist.getByRole('button',{name:'Open code canvas',exact:true}).waitFor();
  const workspaceBox = await specialist.boundingBox();
  assert.ok(workspaceBox.width>previewBox.width,'The same specialist panel expands for its workspace');
  assert.equal(await hub.isVisible(),false);
  await specialist.getByRole('button',{name:'Back',exact:true}).click();
  assert.equal(await specialist.getByRole('button',{name:'Full report',exact:true}).isVisible(),true);
  await page.keyboard.press('Escape');
  assert.equal(await specialist.isVisible(),false);
  assert.equal(await codingRail.evaluate(button=>button===document.activeElement),true);
  await codingRail.click();
  await specialist.getByRole('button',{name:'Full report',exact:true}).click();
  await specialist.getByRole('button',{name:'Open code canvas',exact:true}).click();
  assert.equal(await page.evaluate(() => window.views),1);
  await page.evaluate(() => window.VP3ChatSpecialists.update('tasks',{label:'Tasks & Lists',status:'Idle',state:'idle',presence:true}));
  await page.getByRole('button',{name:'Tasks & Lists · Idle',exact:true}).click();
  await specialist.getByRole('button',{name:'Open workspace',exact:true}).click();
  await specialist.getByText('Workshop items',{exact:true}).waitFor();
  assert.equal(await specialist.getByText('Workshop starts',{exact:true}).count(),0,'The Tasks workspace contains only its own sources');
  await specialist.getByRole('button',{name:'Open list',exact:true}).click();
  await specialist.getByText('Order workshop supplies',{exact:true}).waitFor();
  await specialist.getByRole('button',{name:'Back to inventory',exact:true}).click();
  await specialist.getByText('No saved tasks.',{exact:true}).waitFor();
  await specialist.getByRole('button',{name:'Close',exact:true}).click();
  await specialist.waitFor({state:'detached'});
  await page.evaluate(()=>{
    window.attachmentSourceClicks=[];
    window.VP3GmailDocumentsV1={openSavedReview:(id,event)=>{window.attachmentSourceClicks.push([id,event.isTrusted,window.currentChat]);return true;}};
    window.VP3AgentActivityHub.openSpecialist('curiosity',document.getElementById('chatInput'),{fullReport:true,label:'Curiosity'});
  });
  const sourceLinks=specialist.getByRole('button',{name:'Saved attachment review: Quote Q-104.pdf',exact:true});
  await sourceLinks.first().waitFor();assert.equal(await sourceLinks.count(),2,'Current and answered threads retain their saved-review source action.');
  assert.equal(await specialist.getByRole('button',{name:'Foreign private review',exact:true}).count(),0,'Another Agent source is not opened.');
  await sourceLinks.first().click();await sourceLinks.last().click();
  assert.deepEqual(await page.evaluate(()=>window.attachmentSourceClicks),[['a'.repeat(32),true,42],['a'.repeat(32),true,42]]);
  await specialist.getByRole('button',{name:'Close',exact:true}).click();await specialist.waitFor({state:'detached'});
  await page.evaluate(()=>delete window.VP3GmailDocumentsV1);
  await page.evaluate(() => {
    const rail=window.VP3ChatSpecialists;
    for (const [id,label,state,status] of [
      ['image-generator','Image Generator','idle','Idle'],['media','Media & Entertainment','checked','Checked'],
      ['development:idle','Agent Development','paused','Paused'],['mission','Missions','idle','Idle'],
      ['location','Location Specialist','idle','Idle']
    ]) rail.update(id,{label,status,state,presence:true});
  });
  const composer=page.locator('#chatInput');
  await composer.fill('My existing draft');
  await page.getByRole('button',{name:'Image Generator · Idle',exact:true}).click();
  const imageExample=specialist.getByRole('button',{name:'Try an image'});
  await imageExample.waitFor();
  await imageExample.evaluate(control=>control.click());
  assert.equal(await composer.inputValue(),'My existing draft','A synthetic click cannot stage a prompt.');
  await imageExample.click();
  await specialist.getByText('Finish your current draft or Chat turn, then try again.').waitFor();
  assert.equal(await composer.inputValue(),'My existing draft');
  await composer.fill('');
  await page.evaluate(() => window.VP3ChatSpecialists.update('image-generator',{label:'Image Generator',status:'Idle',state:'idle',presence:true}));
  await imageExample.click();
  await specialist.waitFor({state:'detached'});
  assert.match(await composer.inputValue(),/^Generate a square image of a small desert observatory/);
  assert.deepEqual((await page.evaluate(()=>window.calls.filter(call=>call[0]==='example'))).map(call=>call[2]),[42]);
  await composer.fill('');
  await page.getByRole('button',{name:'Media & Entertainment · Checked',exact:true}).click();
  await specialist.getByText('Would you like to listen to music or watch a video?',{exact:true}).waitFor();
  await specialist.getByRole('button',{name:'Find a video',exact:true}).click();
  assert.equal(await composer.inputValue(),'Search YouTube for a short live acoustic performance');
  await composer.fill('');
  await page.getByRole('button',{name:'Location Specialist · Idle',exact:true}).click();
  await specialist.getByText('1 active arrival alert.',{exact:true}).waitFor();
  const tracking = new URL(await specialist.getByRole('link',{name:'Open Phone Tracking'}).getAttribute('href'));
  assert.equal(tracking.pathname,'/vp3/phone-tracking.php');
  assert.deepEqual(Object.fromEntries(tracking.searchParams),{agent:'7',conversation_id:'42'});
  await specialist.getByRole('button',{name:'List arrival alerts'}).click();
  assert.equal(await composer.inputValue(),'List my arrival alerts');
  await composer.fill('');
  await page.evaluate(() => { window.currentChat=0;window.dispatchEvent(new Event('vp3:chat-context-ready')); });
  await page.locator('[data-chat-specialist="location"]').click();
  await specialist.getByText('No arrival alerts in this Chat.',{exact:true}).waitFor();
  const newMap = new URL(await specialist.getByRole('link',{name:'Open Phone Tracking'}).getAttribute('href'));
  assert.deepEqual(Object.fromEntries(newMap.searchParams),{agent:'7'});
  await specialist.getByRole('button',{name:'Set up local research'}).click();
  assert.equal(await composer.inputValue(),'When my iPhone arrives at Studio, check my open recording tasks. If I still need XLR cables, suggest nearby music stores open today.');
  await composer.fill('');
  await page.locator('[data-chat-specialist="location"]').click();
  await specialist.getByText('No arrival alerts in this Chat.',{exact:true}).waitFor();
  await specialist.getByRole('button',{name:'Set up an arrival alert'}).click();
  assert.equal(await composer.inputValue(),'When my iPhone arrives at Studio, show me my open recording tasks.');
  await composer.fill('');
  await page.locator('[data-chat-specialist="location"]').click();
  await specialist.getByText('No arrival alerts in this Chat.',{exact:true}).waitFor();
  // A restored conversation can become ready without a preceding context-changing event.
  await page.evaluate(() => { window.currentChat=42;window.dispatchEvent(new Event('vp3:chat-context-ready')); });
  await specialist.waitFor({state:'detached'});
  await page.evaluate(() => {
    window.VP3PhoneWorkspaceV1={open:()=>{window.operatorPhoneOpens=(window.operatorPhoneOpens||0)+1;},openHistory:()=>{window.operatorHistoryOpens=(window.operatorHistoryOpens||0)+1;},openRecordings:()=>{window.operatorRecordingOpens=(window.operatorRecordingOpens||0)+1;}};
    window.VP3ChatSpecialists.update('operator',{label:'Operator Specialist',status:'Idle',state:'idle',presence:true});
  });
  await page.locator('[data-chat-specialist="operator"]').click();
  await specialist.getByText('Phone calling is available.',{exact:true}).waitFor();
  await specialist.getByRole('button',{name:'Open Phone',exact:true}).evaluate(control=>control.click());
  assert.equal(await page.evaluate(()=>window.operatorPhoneOpens||0),0,'Synthetic Operator clicks cannot open Phone');
  await specialist.getByRole('button',{name:'Open Phone',exact:true}).click();
  await specialist.waitFor({state:'detached'});
  assert.equal(await page.evaluate(()=>window.operatorPhoneOpens),1,'Operator hands off to the canonical Phone workspace');
  await page.locator('[data-chat-specialist="operator"]').click();
  await specialist.getByText('3 recent calls · 2 caller groups',{exact:true}).waitFor();
  await specialist.getByText('Signed HomeServer report verified',{exact:true}).waitFor();
  await specialist.getByText('Registration reporting only. Autonomous calls are off; live call readiness is not verified.',{exact:true}).waitFor();
  await specialist.getByText('1 saved transcripts · 1 saved reports',{exact:true}).waitFor();
  await specialist.getByText('Confirm the proposal deadline.',{exact:true}).waitFor();
  await specialist.getByText('AI suggestion · needs review',{exact:true}).waitFor();
  await specialist.getByText('Planned phone call — Taylor Caller',{exact:true}).waitFor();
  assert.equal(await specialist.getByRole('link',{name:'Open Calendar appointment',exact:true}).getAttribute('href'),'/vp3/calendar-event.php?id=91');
  assert.equal(await specialist.getByRole('link',{name:'Review source evidence'}).getAttribute('href'),'/vp3/artist-listening-evidence.php?session=12&plugin=qa&section=unanswered&item=question-1&page=1');
  await specialist.getByRole('button',{name:'History',exact:true}).click();
  await specialist.getByText('Recording job completed · Saved transcript · Saved report',{exact:true}).waitFor();
  assert.equal(await specialist.locator('[data-operator-view] article strong').filter({hasText:'Taylor Caller'}).count(),2,'Operator groups two saved phone channels into the same contact');
  await specialist.getByRole('combobox',{name:'Filter call history by caller'}).selectOption('contact:5');
  assert.equal(await specialist.locator('[data-operator-view] article strong').filter({hasText:'+16025550113'}).count(),0,'Operator contact filtering excludes other calls');
  await specialist.getByRole('button',{name:'Open recordings and reports',exact:true}).evaluate(control=>control.click());
  assert.equal(await page.evaluate(()=>window.operatorRecordingOpens||0),0,'Synthetic recording shortcut is rejected');
  await specialist.getByRole('button',{name:'Open recordings and reports',exact:true}).click();
  await specialist.waitFor({state:'detached'});
  assert.equal(await page.evaluate(()=>window.operatorRecordingOpens),1,'Operator hands off to the existing recording and report workspace');
  await page.locator('[data-chat-specialist="operator"]').click();
  await specialist.getByText('3 recent calls · 2 caller groups',{exact:true}).waitFor();
  await page.evaluate(()=>{window.currentChat=43;window.dispatchEvent(new Event('vp3:chat-context-ready'));});
  await specialist.waitFor({state:'detached'});
  await page.evaluate(()=>{window.currentChat=42;window.dispatchEvent(new Event('vp3:chat-context-ready'));});
  await page.getByRole('button',{name:'Agent Development · Paused',exact:true}).click();
  assert.equal(await specialist.getByRole('link',{name:'Agent Development settings',exact:true}).getAttribute('href'),'/admin/agent-development.php');
  assert.equal(await specialist.getByRole('button',{name:'Draft an example request'}).count(),0);
  await specialist.getByRole('button',{name:'Close',exact:true}).click();
  await page.getByRole('button',{name:'Missions · Idle',exact:true}).click();
  await specialist.getByRole('button',{name:'Try a Mission plan'}).click();
  await specialist.waitFor({state:'detached'});
  assert.deepEqual((await page.evaluate(()=>window.calls.filter(call=>call[0]==='mission-example'))).map(call=>call[2]),[42]);
  assert.equal(await composer.inputValue(),'');
  const orb=page.locator('.vp3-orb-ball');
  assert.equal(await page.locator('.vp3-orb-root').locator('[data-orb-brain],[data-orb-activity],[data-agent-activity-preview],[data-orb-size],[data-orb-motion]').count(),0);
  await page.evaluate(()=>{
    window.orbChatWasOpened=false;
    new MutationObserver(records=>{
      if(records.some(record=>record.oldValue===null)||!document.getElementById('vp3AgentChatPopup').hidden)window.orbChatWasOpened=true;
    }).observe(document.getElementById('vp3AgentChatPopup'),{attributes:true,attributeFilter:['hidden'],attributeOldValue:true});
  });
  await orb.dblclick();
  const worker=page.locator('[data-agent-initiative-status]');
  await hub.getByText('Prepare workshop',{exact:true}).waitFor();
  assert.equal(await hub.getByRole('button',{name:'Try an image',exact:true}).isVisible(),true);
  assert.equal(await orb.getAttribute('aria-expanded'),'true');
  await waitForPanelEntrance(worker);
  const desktopBox=await worker.boundingBox();
  assert.ok(desktopBox&&desktopBox.x>=11&&desktopBox.x+desktopBox.width<=1269,'The wider panel keeps its viewport margin beside the Orb: '+JSON.stringify(desktopBox));
  assert.equal(await hub.getByRole('heading',{name:'Top of Mind',exact:true}).isVisible(),true);
  assert.equal(await liveWork.count(),0);
  assert.equal(await hub.locator('img').count(),0);
  assert.equal(await hub.getByText('Longfellow · Workshop research',{exact:true}).isVisible(),true);
  assert.equal(await hub.locator('[data-activity-group="Continue"]').evaluate(element=>element.open),false,'Only the first nonempty group starts expanded');
  if (process.env.VP3_HUB_SCREENSHOTS) await page.screenshot({path:'/workspace/scratch/48e2f20316b2/agent-activity-hub-desktop.png'});
  await orb.dblclick();
  await hub.waitFor({state:'hidden'});
  assert.equal(await orb.getAttribute('aria-expanded'),'false');
  assert.equal(await orb.evaluate(button=>button===document.activeElement),true,'Double-click closes Top of Mind and restores Orb focus');
  await orb.dblclick();
  await hub.getByText('Prepare workshop',{exact:true}).waitFor();
  await hub.getByRole('button',{name:'Open code canvas',exact:true}).click();
  assert.equal(await page.evaluate(() => window.views),2);
  assert.equal(await hub.isVisible(),false);
  assert.equal(await orb.getAttribute('aria-expanded'),'false');
  await orb.press('Shift+Enter');
  await hub.getByRole('heading',{name:'Top of Mind',exact:true}).waitFor();
  await orb.press('Shift+Enter');
  assert.equal(await hub.isVisible(),false,'Shift+Enter toggles the same panel');
  assert.equal(await orb.evaluate(button=>button===document.activeElement),true);
  await orb.press('Shift+Enter');
  await hub.getByRole('heading',{name:'Top of Mind',exact:true}).waitFor();
  await page.keyboard.press('Escape');
  assert.equal(await hub.isVisible(),false);
  assert.equal(await orb.evaluate(button=>button===document.activeElement),true);
  assert.equal(await page.evaluate(()=>window.orbChatWasOpened),false,'Opening Top of Mind does not briefly open Agent Chat.');

  const expand=async()=>{await hub.getByText('Prepare workshop',{exact:true}).waitFor();for(const details of await hub.locator('details[data-activity-group]').all())if(!await details.evaluate(element=>element.open))await details.locator('summary').click();};
  const open=async()=>{await page.evaluate(() => window.VP3AgentActivityHub.open());await hub.getByText('Prepare workshop',{exact:true}).waitFor();await expand();};
  await page.locator('#chatAgentBriefButton').click();
  assert.equal(await page.locator('#chatAgentBriefPopover').isVisible(),false);
  await expand();
  await hub.getByRole('button',{name:'Open list',exact:true}).click();
  assert.deepEqual(await page.evaluate(() => window.calls.at(-1)),['note',{kind:'task_list',id:4}]);
  await hub.getByRole('button',{name:'Open reminders',exact:true}).click();
  assert.deepEqual(await page.evaluate(() => window.calls.at(-1)),['note',{kind:'reminders',view:'today'}]);
  await hub.getByRole('button',{name:'Resume song',exact:true}).click();
  assert.deepEqual(await page.evaluate(() => window.calls.at(-1)),['song',2]);
  const slot=hub.locator('[data-agent-activity-listening]');
  await slot.evaluate(element=>{const audio=document.createElement('audio');audio.id='keptAudio';element.append(audio);window.keptAudio=audio;});
  await page.evaluate(()=>window.VP3AgentActivityHub.refresh());
  await hub.getByText('Workshop checklist',{exact:true}).waitFor();
  assert.equal(await page.evaluate(()=>window.keptAudio===document.getElementById('keptAudio')),true,'Refresh preserves native audio identity');
  await hub.getByRole('button',{name:'Review canvas',exact:true}).click();
  assert.deepEqual(await page.evaluate(() => window.calls.at(-1)),['canvas','canvas-one',2]);
  await open();
  await page.evaluate(()=>{window.originalCanvasOpen=window.VP3CodingCanvasV1.open;window.VP3CodingCanvasV1.open=()=>new Promise(resolve=>{window.releaseCanvas=resolve;});});
  await hub.getByRole('button',{name:'Review canvas',exact:true}).click();
  await page.waitForFunction(()=>typeof window.releaseCanvas==='function');
  await page.evaluate(()=>window.VP3AgentActivityHub.close(false));
  await open();
  await page.evaluate(()=>{window.releaseCanvas(true);window.VP3CodingCanvasV1.open=window.originalCanvasOpen;});
  await page.waitForTimeout(20);
  assert.equal(await hub.isVisible(),true,'An old Canvas response cannot close a newly reopened pane');
  await open();
  await hub.getByRole('button',{name:'Open mission',exact:true}).click();
  assert.deepEqual(await page.evaluate(() => window.calls.at(-1)),['mission','mission-one']);

  await page.setViewportSize({width:390,height:740});
  await open();
  await hub.getByText('Prepare workshop',{exact:true}).waitFor();
  await waitForPanelEntrance(worker);
  const box=await worker.boundingBox();
  assert.ok(box&&box.x>=0&&box.y>=0&&box.x+box.width<=391&&box.y+box.height<=741,'Top of Mind fits the mobile viewport: '+JSON.stringify(box));
  if (process.env.VP3_HUB_SCREENSHOTS) {
    await hub.locator('details[data-activity-group]').evaluateAll(elements=>elements.forEach((element,index)=>{element.open=index===0;}));
    await page.screenshot({path:'/workspace/scratch/48e2f20316b2/agent-activity-hub-mobile.png'});
  }
  await hub.getByRole('button',{name:'Open code canvas',exact:true}).click();
  assert.equal(await page.evaluate(() => window.views),3,'Specialist remains available on mobile');
  await open();
  await hub.getByRole('button',{name:'Resume Chat',exact:true}).click();
  assert.deepEqual(await page.evaluate(() => window.calls.at(-1)),['chat',43]);
  await open();
  await hub.getByText('Prepare workshop',{exact:true}).waitFor();
  let start,release;
  const pending=new Promise(resolve=>{start=resolve;});
  gated={started:start,release:new Promise(resolve=>{release=resolve;})};
  await page.evaluate(()=>window.VP3AgentActivityHub.refresh());
  await pending;
  await page.evaluate(()=>{window.participantSelected=true;window.dispatchEvent(new Event('vp3:participant-selection-changed'));});
  assert.equal(await hub.isVisible(),false);
  assert.equal(await hub.getByText('Prepare workshop',{exact:true}).count(),0);
  release();
  await page.waitForTimeout(100);
  assert.equal(await hub.getByText('Prepare workshop',{exact:true}).count(),0,'Late owner response cannot remount private cards');
  assert.equal(await page.evaluate(()=>window.VP3AgentActivityHub.open()),false);
  await page.locator('#chatAgentBriefButton').click();
  assert.equal(await page.locator('#chatAgentBriefPopover').isVisible(),false,'Participant rejection cannot fall back to private Brief');
  assert.equal(await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.openDetails()),false);
  const count=requests.length;
  await orb.dblclick();
  assert.equal(await hub.isVisible(),false,'The Orb gesture cannot reopen private activity in participant context');
  await page.waitForTimeout(200);
  assert.equal(requests.length,count,'Closed and participant views do not poll');
  await page.evaluate(()=>{window.participantSelected=false;window.dispatchEvent(new Event('vp3:participant-selection-changed'));});
  await open();
  await hub.getByText('Prepare workshop',{exact:true}).waitFor();
  await page.keyboard.press('Escape');
  assert.equal(await hub.isVisible(),false);

  await page.goto('https://activity.test/standalone');
  await page.locator('.vp3-orb-ball').click();
  const child=await page.locator('iframe').elementHandle().then(element=>element.contentFrame());
  await child.waitForFunction(()=>Boolean(window.VP3AgentOrb?.openActivity));
  assert.equal(await child.evaluate(()=>window.VP3ChatSpecialists.openActivity('coding',document.getElementById('nativeResume'))),true);
  const childSpecialist=child.locator('#vp3ChatSpecialistActivity');
  await childSpecialist.getByRole('button',{name:'Full report',exact:true}).click();
  await childSpecialist.getByRole('button',{name:'Open code canvas',exact:true}).waitFor();
  assert.equal(await hub.isVisible(),false,'An embedded specialist stays within its Chat popup');
  assert.equal(await page.locator('#vp3AgentChatPopup').isVisible(),true);
  await childSpecialist.getByRole('button',{name:'Close',exact:true}).click();
  await child.evaluate(()=>window.VP3AgentActivityHub.open({specialist:'coding'}));
  await hub.getByText('Prepare workshop',{exact:true}).waitFor();
  assert.equal(await page.locator('#vp3AgentChatPopup').isVisible(),false,'Parent hub replaces the embedded Chat popup');
  assert.equal(await page.locator('iframe').count(),1);
  assert.equal(await child.evaluate(()=>window.keptAudio===document.getElementById('childAudio')),true);
  await hub.getByRole('button',{name:'Open code canvas',exact:true}).click();
  await child.waitForFunction(()=>window.views===1);
  assert.equal(await page.locator('#vp3AgentChatPopup').isVisible(),true);
  assert.equal(await hub.isVisible(),false);
  await open();
  await hub.getByRole('button',{name:'Review canvas',exact:true}).click();
  await child.waitForFunction(()=>window.calls.some(call=>call[0]==='canvas'));
  assert.deepEqual(await child.evaluate(()=>window.calls.at(-1)),['canvas','canvas-one',2]);
  assert.equal(await page.locator('iframe').count(),1,'Canonical Canvas action preserves the existing embedded runtime');
  await open();
  await hub.getByRole('button',{name:'Resume song',exact:true}).click();
  await child.waitForFunction(()=>window.calls.some(call=>call[0]==='song'));
  assert.deepEqual(await child.evaluate(()=>window.calls.at(-1)),['song',2]);
  assert.equal(new URL(page.url()).pathname,'/standalone','An accepted asynchronous song relay never follows fallback navigation');
  await open();
  await hub.getByRole('button',{name:'Review results',exact:true}).click();
  await child.waitForFunction(()=>window.calls.some(call=>call[0]==='chat'));
  assert.deepEqual(await child.evaluate(()=>window.calls.at(-1)),['chat',42,{messageId:8}]);
  assert.equal(new URL(page.url()).pathname,'/standalone','An intentional source Chat notification does not trigger fallback navigation');
  await page.evaluate(()=>{
    window.staleResult=null;
    window.addEventListener('message',event=>{if(event.data?.request_id==='stale-fixture')window.staleResult=event.data.accepted;});
    document.querySelector('iframe').contentWindow.postMessage({channel:'vp3-orb',kind:'activity-action',ownerKey:'1',agent_id:7,conversation_id:99,working_attention_binding:'fixture-binding',request_id:'stale-fixture',action:{kind:'song',track_id:3}},location.origin);
  });
  await page.waitForFunction(()=>window.staleResult===false);
  assert.equal(await child.evaluate(()=>window.calls.some(call=>call[0]==='song'&&call[1]===3)),false,'An action for another Chat is rejected by the embedded runtime');
  // The ordinary first action on a workspace page must boot the existing Chat popup.
  await page.goto('https://activity.test/standalone?mode=ready');
  await page.evaluate(()=>sessionStorage.clear());
  await open();
  assert.equal(await page.locator('iframe').count(),0);
  await hub.getByRole('button',{name:'Resume song',exact:true}).click();
  const coldChild=await page.locator('iframe').elementHandle().then(element=>element.contentFrame());
  await coldChild.waitForFunction(()=>window.calls?.some(call=>call[0]==='song'));
  assert.deepEqual(await coldChild.evaluate(()=>window.calls.at(-1)),['song',2]);
  assert.equal(new URL(page.url()).pathname,'/standalone');
  assert.equal(await page.locator('iframe').count(),1);

  await page.goto('https://activity.test/standalone?mode=wait');
  await open();
  await hub.getByRole('button',{name:'Review canvas',exact:true}).click();
  const waitingChild=await page.locator('iframe').elementHandle().then(element=>element.contentFrame());
  await waitingChild.waitForFunction(()=>typeof window.releaseReady==='function');
  assert.deepEqual(await waitingChild.evaluate(()=>window.calls),[]);
  await waitingChild.evaluate(()=>window.releaseReady());
  await waitingChild.waitForFunction(()=>window.calls.some(call=>call[0]==='canvas'));
  assert.deepEqual(await waitingChild.evaluate(()=>window.calls.at(-1)),['canvas','canvas-one',2]);

  await page.goto('https://activity.test/standalone?mode=wait');
  await open();
  await hub.getByRole('button',{name:'Open mission',exact:true}).click();
  const latestChild=await page.locator('iframe').elementHandle().then(element=>element.contentFrame());
  await latestChild.waitForFunction(()=>typeof window.releaseReady==='function');
  await page.evaluate(()=>{window.latestResult=window.VP3AgentOrb.activityAction({kind:'canvas',canvas_id:'canvas-latest',revision:3});});
  await latestChild.evaluate(()=>window.releaseReady());
  await latestChild.waitForFunction(()=>window.calls.length>0);
  assert.deepEqual(await latestChild.evaluate(()=>window.calls),[['canvas','canvas-latest',3]],'Only the latest queued intent dispatches after startup');

  await page.goto('https://activity.test/standalone?mode=ready');
  await open();
  await hub.getByRole('button',{name:'Open mission',exact:true}).click();
  const missionChild=await page.locator('iframe').elementHandle().then(element=>element.contentFrame());
  await missionChild.waitForFunction(()=>window.calls?.some(call=>call[0]==='mission'));
  assert.deepEqual(await missionChild.evaluate(()=>window.calls.at(-1)),['mission','mission-one']);

  await page.goto('https://activity.test/standalone?mode=wait');
  await open();
  await hub.getByRole('button',{name:'Resume song',exact:true}).click();
  const cancelledChild=await page.locator('iframe').elementHandle().then(element=>element.contentFrame());
  await cancelledChild.waitForFunction(()=>typeof window.releaseReady==='function');
  await page.evaluate(()=>{
    window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:14}}));
    window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:-1}}));
  });
  await cancelledChild.evaluate(()=>window.releaseReady());
  assert.deepEqual(await cancelledChild.evaluate(()=>window.calls),[],'A participant change cancels a connecting action');

  await page.goto('https://activity.test/standalone?mode=denied');
  await open();
  await hub.getByRole('button',{name:'Resume song',exact:true}).click();
  const deniedChild=await page.locator('iframe').elementHandle().then(element=>element.contentFrame());
  await deniedChild.waitForFunction(()=>window.calls?.some(call=>call[0]==='song'));
  await page.locator('.vp3-orb-bubble').getByText('Playback did not start. Use Resume in Agent Chat.',{exact:true}).waitFor();
  assert.equal(await deniedChild.locator('#nativeResume').isVisible(),true);
  assert.equal(new URL(page.url()).pathname,'/standalone','Denied playback leaves normal Resume available');

  await page.goto('https://activity.test/standalone?mode=timeout');
  await page.clock.install();
  await open();
  await hub.getByRole('button',{name:'Resume song',exact:true}).click();
  const timeoutChild=await page.locator('iframe').elementHandle().then(element=>element.contentFrame());
  await timeoutChild.waitForFunction(()=>typeof window.releaseReady==='function');
  await page.clock.fastForward(10050);
  await page.locator('.vp3-orb-bubble').getByText('Agent Chat is still connecting. Try the item again when Chat is ready.',{exact:true}).waitFor();
  await timeoutChild.evaluate(()=>window.releaseReady());
  assert.deepEqual(await timeoutChild.evaluate(()=>window.calls),[],'A timed out intent never runs later');
  assert.equal(new URL(page.url()).pathname,'/standalone');
  assert.deepEqual(errors,[]);
  console.log('Agent activity hub browser fixture passed');
} finally { await browser.close(); }
