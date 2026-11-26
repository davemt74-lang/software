import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright';

const browser = await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
const page = await browser.newPage({viewport:{width:1280,height:900},hasTouch:true});
const errors = [];
page.on('pageerror',error => errors.push(error.message));
const longDetail = 'Waiting for review. ' + 'This saved investigation has a longer explanation and keeps all of its original actions available. '.repeat(8);
let reads = 0;
let showEmailReport = false;
let revisedPlanningSummary = false;
let showOfficeReport = false;
let showCoordinationFindings = false;
let coordinationResolved = false;
let analysisState = 'disabled';
let specialistClaim = '';
let analyzed = 0;
let hubStatus = 200;
const views = [];
const inventoryReads = [];
let wrongListPage = false;
let cupsAnalysisUpdated = false;
let rulesReads = 0;
let rulesRevision = '1'.repeat(64);
let forceRulesConflict = false;
const ruleSaves = [];
let nativeOwner = 1;
const nativeSettingsReads = [];
const rules = {
  gmail:{needs_attention:'ask',opportunity:'prepare',important:'report'},
  tasks:{waiting_owner:'ask',overdue:'ask',blocked:'ask',due_soon:'prepare',completed:'report'},
  planning:{pending_confirmation:'ask',conflict:'ask',changed:'report',followup:'prepare'},
  media:{resume:'ask',recent_search:'ask'},
};
const ruleCatalog = {
  actions:{watch:'Keep in Top of Mind',prepare:'Prepare privately',ask:'Ask in Chat',report:'Report in Chat'},
  specialists:{
    gmail:{label:'Gmail',triggers:{needs_attention:'Needs your input',opportunity:'Opportunity',important:'Important update'}},
    tasks:{label:'Tasks & Lists',triggers:{waiting_owner:'Waiting on you',overdue:'Overdue',blocked:'Blocked',due_soon:'Due soon',completed:'Completed'}},
    planning:{label:'Planning & Scheduling',triggers:{pending_confirmation:'Pending confirmation',conflict:'Conflict',changed:'Changed',followup:'Follow-up'}},
    media:{label:'Media & Entertainment',triggers:{resume:'Unfinished track',recent_search:'Recent search'}},
  },
};
const officeReviewTitle = 'List review: Workshop supplies';
const officeReviewDetail = 'The caterer has everything except the final head count. Confirm it before Friday to keep the order on track. <img src=x>';
const officeQuestion = 'How many people should the caterer plan for?';
const taskReport = {state:'ready',summary:'The vendor only needs the final number.',next_step:'Send the count by Friday.',
  chat_action:{kind:'chat',label:'View in Chat',conversation_id:42,message_id:12,href:'/chat.php?agent=8&conversation_id=42&message_id=12'}};
const listReport = {state:'ready',summary:officeReviewDetail,next_step:'Confirm the head count before Friday.',followup_question:officeQuestion,limited:true,
  action_plan:['Compare the registration total with the venue cap.','Prepare the final order for review.'],
  agent_task:{title:'Check the workshop order',state:'completed',result:'The venue cap is confirmed. <img src=x>'},
  chat_action:{kind:'chat',label:'View in Chat',conversation_id:42,message_id:12,href:'/chat.php?agent=7&conversation_id=42&message_id=12'}};
const emailRef = 'e'.repeat(64);
const coordinationRef = 'a'.repeat(64);
const mediaFindings = [
  {id:'media:track:8',kind:'resume',title:'Quiet signal',summary:'The track still has time to play. <img src=x>',
    next_step:'Resume from the saved position.',question:'Want to pick up Quiet signal?',
    actions:[{kind:'song',label:'Resume Quiet signal',track_id:8,href:'/track.php?id=8'},
      {kind:'url',label:'Unsafe destination',href:'//other.test/media'}]},
  {id:'media:youtube:17',kind:'youtube_pick',title:'The acoustic session',summary:'This came from a recent search.',
    next_step:'Open the verified search result.',question:'Want to watch the acoustic session?',
    actions:[{kind:'chat',label:'Open exact result',conversation_id:42,message_id:17,href:'/chat.php?agent=7&conversation_id=42&message_id=17'}]},
];
const emailFindings = [
  {category:'routine',priority:'low',state:'informational',summary:'Weekly digest is available.'},
  {category:'opportunity',priority:'high',state:'open',summary:'A venue wants to host a workshop. <img src=x>',
    importance_reason:'They are asking about a date this week.',next_step:'Confirm availability.',
    followup_question:'Which date works for you?',subject:'Workshop quote',item_ref:emailRef,source_is_evidence:false,updating:true},
  {category:'action_required',priority:'high',state:'open',summary:'Reply to the caterer by Friday.',next_step:'Confirm the head count.'},
  {category:'decision',priority:'normal',state:'open',summary:'Choose between two travel quotes.'},
  {category:'routine',priority:'normal',state:'open',summary:'The supplier is waiting for a shipping address.',next_step:'Send the office address.'},
];
await page.route('https://activity.test/',route => route.fulfill({contentType:'text/html',body:`<!doctype html>
<meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0}</style>
<div class="vp3-orb-root"><section class="vp3-orb-initiative" data-agent-initiative-status><div id="vp3AgentActivityHub" data-agent-activity-host></div><details data-agent-worker-details hidden><summary>Runtime details</summary></details></section></div>`}));
await page.route(/https:\/\/activity\.test\/(connections|scheduling)\.php\?view=specialist-settings/,route => {
  const url = new URL(route.request().url());
  const planning = url.pathname === '/scheduling.php';
  const app = planning ? 'planning' : url.searchParams.get('app');
  nativeSettingsReads.push([app,...['owner_user_id','agent','conversation_id','tab'].map(key => url.searchParams.get(key))]);
  return route.fulfill({contentType:'text/html',body:`<!doctype html><style>body{margin:0}main{min-height:780px}</style>
    <body data-vp3-specialist-settings="${app}" data-owner-user-id="${nativeOwner}" ${planning ? 'data-agent-id="7" data-conversation-id="42"' : ''}>
      <main><button>${planning ? 'Save schedule' : app === 'music' ? 'Save playback suggestions' : 'Save inbox review'}</button></main>
    </body>`});
});
await page.route('https://activity.test/api/coordination-rules**',route => {
  const request = route.request();
  assert.equal(request.headers()['x-vp3-working-attention'],'fixture-binding');
  if (request.method() === 'POST') {
    const payload = request.postDataJSON();
    assert.deepEqual([payload.agent_id,payload.conversation_id,payload.csrf_token,payload.revision],
      [7,42,'fixture-token',rulesRevision]);
    ruleSaves.push(payload.rules);
    if (forceRulesConflict) {
      forceRulesConflict = false;
      rulesRevision = '3'.repeat(64);
      rules.tasks.overdue = 'prepare';
      return route.fulfill({status:409,json:{ok:false,error:'Settings changed'}});
    }
    Object.assign(rules,payload.rules);
    rulesRevision = '2'.repeat(64);
  } else {
    const query = new URL(request.url()).searchParams;
    assert.deepEqual([query.get('agent_id'),query.get('conversation_id')],['7','42']);
    rulesReads++;
  }
  return route.fulfill({json:{ok:true,state:{owner_user_id:1,agent_id:7,conversation_id:42,revision:rulesRevision,rules,catalog:ruleCatalog}}});
});
await page.route('https://activity.test/api/top-of-mind**',route => {
  const params = new URL(route.request().url()).searchParams;
  assert.equal(route.request().method(),'GET');
  assert.equal(route.request().headers()['x-vp3-working-attention'],'fixture-binding');
  assert.deepEqual([...params.values()],['engagements','chat','7','42','gmail','31']);
  return route.fulfill({json:{ok:true,state:{contract:'vp3.agent.engagements.v1',owner_user_id:1,agent_id:7,conversation_id:42,
    specialist:'gmail',working_attention_binding:'fixture-binding',open_engagements_status:'ready',open_engagements_next_before:null,
    open_engagements:[{message_id:26,conversation_id:42,title:'Venue',question:'Can I ask about the venue?',
      result_url:'/chat.php?agent=7&conversation_id=42&message_id=26'}]}}});
});
await page.route('https://activity.test/api/hub**',route => {
  reads++;
  const params = new URL(route.request().url()).searchParams;
  const view = params.get('view');
  const collection = params.get('collection');
  const cursor = params.get('cursor');
  const listId = params.get('list_id');
  views.push(view);
  if (collection && collection !== 'reviews') inventoryReads.push([collection,cursor,listId]);
  if (hubStatus !== 200) return route.fulfill({status:hubStatus,body:'<p>Fixture error</p>',contentType:'text/html'});
  const lease = new Date(Date.now()+60_000).toISOString();
  const state = {contract:'vp3.agent.activity.v1',owner_user_id:1,agent_id:7,conversation_id:42,working_attention_binding:'fixture-binding',observed_at:new Date().toISOString(),agent_name:'Longfellow',current_chat:{title:'Workshop research'},coverage:{calendar:'unavailable'},
    coordination:{phase:'analyzing',detail:'Reviewing verified specialist findings.',finding:'Planning has a workshop checklist ready. <img src=x>',lease_expires_at:lease},
    specialists:[
      {id:'planning',state:'working',progress:55,detail:'Reviewing the workshop agenda.',lease_expires_at:lease},
      {id:'gmail',state:'checked',progress:null,open_engagements_status:'ready',open_engagements:[
        {message_id:32,conversation_id:42,title:'Delivery timing',question:'Can they deliver on Friday?',result_url:'/chat.php?agent=7&conversation_id=42&message_id=32'},
        {message_id:31,conversation_id:42,title:'Revised quote',question:'Should I ask for a revised quote? <img src=x>',result_url:'/chat.php?agent=7&conversation_id=42&message_id=31'}
      ],open_engagements_next_before:31},
      {id:'research',state:'thinking',progress:null,lease_expires_at:lease},
      {id:'tasks',state:'waiting_owner',progress:null,detail:'A list follow-up needs your review.',result_url:'/chat.php?agent=7&conversation_id=42&message_id=12',conversation_id:42,message_id:12},
      {id:'coding',state:'working',progress:95,lease_expires_at:'2000-01-01T00:00:00Z'},
    ],sections:[
    {id:'gmail_inbox',label:'Gmail',items:[{id:'gmail:inbox-monitor',title:'Gmail inbox monitoring',monitor:{state:'checked',analysis_state:analysisState,last_checked_at:new Date().toISOString(),scan:{at:new Date().toISOString(),from:'2026-01-01T00:00:00Z',total:0,important:0,replies:0},
      ...(specialistClaim.startsWith('gmail') ? {analysis_work:{state:'working',
        lease_until:specialistClaim==='gmail' ? lease : '2000-01-01T00:00:00Z'}} : {}),
      latest_arrivals:{at:'2026-10-02T12:00:00Z',total:6,important:2,replies:1,filtered:1,
        items:[{subject:'New delivery <img src=x>',from:'Warehouse <ops@example.test>',item_ref:emailRef}]},
      matters:{items:[{subject:'Workshop quote <img src=x>',stage:'followup_due'}]},
      ...(showEmailReport ? {triage:{generation:71},report:{running_report:{summary:'The workshop inquiry and catering reply are the most time-sensitive items.',pending:2,unavailable:1,findings:emailFindings},
        handoffs:{needs_attention:[emailFindings[1]],agent_work:[{category:'opportunity',summary:'Find a nearby event supplier.',handoff_question:'Which local suppliers rent equipment?'}]}}} : {})},
      actions:[{kind:'url',label:'Gmail settings',href:'/connections.php?app=gmail'}]},
      {id:'gmail:first-review',title:'Gmail Inbox Review',detail:'Ready to read in Chat',review_state:'ready',
        actions:[{kind:'chat',label:'View in Chat',href:'/chat.php?agent=7&conversation_id=42&message_id=12',conversation_id:42,message_id:12}]}]},
    {id:'lists',label:'Lists',items:[{id:'list:4',title:'Workshop supplies and materials '.repeat(8),detail:'2 open',actions:[{kind:'task_notes',label:'Open list',list_id:4}]}]},
    {id:'calendar',label:'Calendar',items:[{id:'event:1',title:'Workshop starts',detail:'Tomorrow',actions:[]}]},
    {id:'missions',label:'Missions',items:[{id:'mission:1',title:'Research opportunities <img src=x> · '+reads,detail:longDetail,actions:['Open','Review','Resume','Notes'].map((label,index)=>({kind:'task_notes',label,list_id:index+5}))}]}
  ]};
  if (!view && showEmailReport) {
    state.summary = {overview:'The workshop and catering are ready for your review.',updates:[
      {specialist_id:'gmail',title:'Catering needs a reply',detail:'Confirm the guest count by Friday. <img src=x>'},
      {specialist_id:'planning',title:revisedPlanningSummary ? 'Workshop setup moved to Monday' : 'Workshop setup is on Friday',
        routing:{specialist:'planning',trigger:'changed',action:'watch'}},
      {specialist_id:'tasks',title:'The checklist is ready'},
      {specialist_id:'unrecognized',title:'Never display an unknown source'},
    ],briefings:[
      {specialist_id:'profile',title:'Visitor follow-up',source_coverage:'ready',source_url:'/profile-agent.php?tab=inbox&conversation=23',
        presentation_owner:'profile_native',engagement:{state:'awaiting_owner',conversation_id:42,message_id:443,run_id:551,
          receipt_run_id:0,chat_url:'/chat.php?agent=7&conversation_id=42&message_id=443'},sections:{key_findings:['A visitor asked about the current offering.'],
          actions_followups:['Review the owner conversation'],open_questions:['Which follow-up matters?'],
          next_step:'Open Profile inbox',questions_for_you:['Would you like to respond?']}},
      {specialist_id:'media',title:'Verified music suggestion',source_coverage:'ready',source_url:'/track.php?id=8',
        engagement:{state:'preparation_verified',conversation_id:42,message_id:444,run_id:552,
          receipt_run_id:702,chat_url:'/chat.php?agent=7&conversation_id=42&message_id=444'},sections:{key_findings:['Saved listening activity suggests a track. <img src=x>'],
          actions_followups:['Proposed playback; not yet executed'],open_questions:['Which track next?'],
          next_step:'Review the saved track',questions_for_you:['Play this track?']}},
      {specialist_id:'marketing',title:'Campaign needs review',source_coverage:'limited',source_url:'https://outside.test/phishing',
        engagement:{state:revisedPlanningSummary?'approved_action_verified':'owner_marked_resolved',
          conversation_id:42,message_id:445,run_id:553,receipt_run_id:revisedPlanningSummary?727:0,
          chat_url:'/chat.php?agent=7&conversation_id=42&message_id=445'},sections:{key_findings:['A campaign has a verified follow-up.'],actions_followups:['Review draft'],
          open_questions:['Is the date final?'],next_step:'Review campaign',
          questions_for_you:['Approve a draft for review?']}},
    ]};
  }
  if (!view && showCoordinationFindings) {
    state.media_offer = {id:'youtube-offer:12',kind:'youtube_offer',title:'Quiet signal',
      detail:'A music pick from your recent YouTube results.',
      actions:[{kind:'chat',label:'View in Chat',conversation_id:42,message_id:12,href:'/chat.php?agent=7&conversation_id=42&message_id=12'}]};
    state.coordination_findings = [
      {id:coordinationRef,kind:'coordination_finding',state:coordinationResolved ? 'resolved' : 'needs_owner',
        title:'Approve the workshop head count',detail:'The caterer and calendar agree on Friday. <img src=x>',
        next_step:'Confirm the final number.',question:coordinationResolved ? '' : 'Should we plan for 30 guests?',
        result:coordinationResolved ? 'Prepared the 30-guest checklist.' : '',
        routing:{specialist:'planning',trigger:'pending_confirmation',action:'ask'},delivery:'delivered',
        actions:[{kind:'chat',label:'View in Chat',conversation_id:42,message_id:17,href:'/chat.php?agent=7&conversation_id=42&message_id=17'}],
        sources:[{specialist:'tasks',title:'Workshop supplies',url:'/chat.php?agent=7&conversation_id=42&message_id=12'},
          {specialist:'planning',title:'Friday workshop',url:'https://untrusted.test/source'},
          {specialist:'gmail',title:'Workshop quote',source_action:{type:'gmail_triage',item_ref:emailRef,generation:71}}]},
      {id:'b'.repeat(64),kind:'coordination_finding',state:'agent_work',title:'Check the venue setup',
        detail:'The checklist gives enough detail for a private plan.',next_step:'Prepare a setup order.',work_status:'running'},
      {id:'c'.repeat(64),kind:'coordination_finding',state:'watching',title:'Workshop appointment changed',
        detail:'The venue moved the setup window.',routing:{specialist:'planning',trigger:'changed',action:'report'},delivery:'pending'},
    ];
  }
  if (view === 'planning') {
    state.specialists = state.specialists.filter(row => row.id === 'planning');
    delete state.coordination;
    state.sections = [
      {id:'calendar',label:'Upcoming events',items:[{id:'planning:calendar:event:1',title:'Workshop starts',
        detail:'The event has a saved agenda.',actions:[{kind:'url',label:'Open event',href:'/calendar-event.php?id=1'}]}]},
      {id:'appointments',label:'Appointments',items:[{id:'planning:scheduling:booking:12',title:'Workshop setup visit',
        detail:'Pending appointment on Friday.',next_step:'Check what is still needed to confirm the appointment.',
        actions:[{kind:'url',label:'Open appointment',href:'/scheduling.php?workspace_booking=12&schedule=5&tab=bookings#booking-12'}]}]},
    ];
  }
  if (view === 'media') {
    assert.equal(route.request().headers()['x-vp3-working-attention'],'fixture-binding');
    assert.deepEqual([params.get('agent_id'),params.get('conversation_id')],['7','42']);
    state.specialists = specialistClaim.startsWith('media') ? [{id:'media',state:'working',progress:null,
      detail:'Reviewing a VP3 Media suggestion.',lease_expires_at:specialistClaim==='media' ? lease : '2000-01-01T00:00:00Z'}] : [];
    delete state.coordination;
    state.media_report = {coverage:'ready',findings:mediaFindings};
    state.sections = [
      {id:'songs',label:'Songs',items:[{id:'song:8',title:'Recently played fixture track',actions:[]}]},
      {id:'youtube',label:'YouTube',items:[{id:'youtube-search:17',title:'Saved acoustic search',actions:[]}]},
    ];
  }
  if (view === 'tasks') {
    state.sections = [
      ...state.sections.filter(section => section.id === 'lists'),
      {id:'reminders',label:'Reminders',items:[{id:'reminder:8',title:'Check venue access',actions:[]}]},
      {id:'tasks',label:'Tasks',items:[{id:'task:9',title:'Confirm the head count',actions:[]}]},
      {id:'office_reviews',label:'Completed reviews',items:showOfficeReport ? [
        {id:'office-review:5',kind:'office_review',title:officeReviewTitle,detail:officeReviewDetail,
          next_step:'Confirm the head count before Friday.',followup_question:'How many people should the caterer plan for?',
          actions:[{kind:'chat',label:'View in Chat',conversation_id:42,message_id:12,href:'/chat.php?agent=7&conversation_id=42&message_id=12'}]},
        {id:'office-review:4',kind:'office_review',title:'Reminder review: Venue access',detail:'Ask for a key before setting up.',
          actions:[{kind:'chat',label:'View in Chat',conversation_id:42,message_id:17,href:'/chat.php?agent=7&conversation_id=42&message_id=17'}]},
      ] : []},
    ];
    state.specialists = state.specialists.filter(row => row.id === 'tasks');
    delete state.coordination;
    if (collection === 'reviews') {
      state.sections = state.sections.filter(section => section.id === 'office_reviews');
    } else if (collection === 'all') {
      const lists = state.sections.find(section => section.id === 'lists');
      lists.label = 'Task lists';
      lists.items[0].actions[0] = {kind:'list_inventory',label:'Open list',list_id:4};
      if (showOfficeReport) lists.items[0].office_report = listReport;
      state.sections.find(section => section.id === 'tasks').items[0].office_report = {state:'queued'};
      state.sections.find(section => section.id === 'reminders').items = [];
      state.pages = {reminders:{next_cursor:'80'},tasks:{next_cursor:'90'},lists:{next_cursor:'4'}};
    } else if (collection) {
      const pages = {
        reminders:{label:'Reminders',items:[{id:'reminder:79',title:'Follow up with the venue',detail:'Completed',office_report:{state:'stale'},
          actions:[{kind:'task_notes',label:'Open reminders',view:'all',timezone:'America/Denver'}]}]},
        tasks:{label:'Tasks',items:[{id:'task:89',title:'Earlier completed task',detail:'Completed',actions:[]}]},
        lists:{label:'Task lists',items:[{id:'list:3',title:'Catering checklist',detail:'2 open',
          actions:[{kind:'list_inventory',label:'Open list',list_id:3}]}]},
        list_items:{label:'Catering checklist',items:cursor ? [
          {id:'task:21',title:'Order coffee cups',detail:'Completed',office_report:cupsAnalysisUpdated
            ? {...taskReport,summary:'The supplier confirmed the order and pickup window.'} : taskReport,actions:[]},
        ] : [{id:'task:22',title:'Ask for the revised menu',detail:'Waiting',office_report:{state:'running',
          agent_task:{title:'Check available menu options',state:'running'}},actions:[]}]},
      };
      assert.equal(route.request().headers()['x-vp3-working-attention'],'fixture-binding');
      assert.equal(params.get('agent_id'),'7');
      assert.equal(params.get('conversation_id'),'42');
      if (collection === 'list_items') state.list_id = cursor && wrongListPage ? 4 : Number(listId);
      state.sections = [{id:collection,...pages[collection]}];
      state.pages = {[collection]:{next_cursor:collection === 'list_items' && !cursor ? '22' : null}};
    }
  }
  return route.fulfill({json:{ok:true,state}});
});
await page.route('https://activity.test/api/gmail-inbox-initial-v1.php',route => {
  assert.deepEqual(route.request().postDataJSON(),{action:'analyze_recent',owner_key:'1',csrf_token:'fixture-token',
    working_attention_binding:'fixture-binding',agent_id:7,conversation_id:42});
  analyzed++;
  analysisState='ready';
  showEmailReport=true;
  return route.fulfill({json:{ok:true}});
});

try {
  await page.goto('https://activity.test/');
  await page.evaluate(() => {
    window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'fixture-binding'};
    window.VP3_ORB_CONFIG={ownerKey:'1',activityEndpoint:'/api/hub',topOfMindEndpoint:'/api/top-of-mind',coordinationRulesEndpoint:'/api/coordination-rules',csrf:'fixture-token',
      specialistSettings:{connections:'/connections.php',planning:'/scheduling.php'}};
    window.chatReady=true;
    window.STONEFELLOW_CHAT_CONTINUITY={agentId:()=>7,conversationId:()=>42,isReady:()=>window.chatReady,isRestoring:()=>false,
      openConversation:(id,options)=>{window.calls.push(['chat',id,options]);return true;},
      playActivityTrack:id=>{window.calls.push(['song',id]);return true;},
      showPlayerView:()=>window.calls.push(['player'])};
    document.body.insertAdjacentHTML('beforeend','<div id="chatThread"><article data-chat-conversation-id="42" data-chat-message-id="12"></article><article data-chat-conversation-id="42" data-chat-message-id="17"></article></div>');
    document.body.insertAdjacentHTML('beforeend','<button id="gmailLaunch" style="position:fixed;right:0;top:150px">Gmail activity</button><button id="tasksLaunch" style="position:fixed;right:0;top:185px">Tasks activity</button><button id="planningLaunch" style="position:fixed;right:0;top:220px">Planning activity</button>');
    document.body.insertAdjacentHTML('beforeend','<aside id="sfOnlineRailV109" style="position:fixed;right:0;top:280px"></aside>');
    for(const [id,key] of [['gmailLaunch','gmail'],['tasksLaunch','tasks'],['planningLaunch','planning']]){
      const trigger=document.getElementById(id);
      trigger.addEventListener('click',()=>window.VP3AgentActivityHub.openSpecialist(key,trigger));
    }
    window.calls=[];
    window.VP3_GMAIL_INBOX_INITIAL_V1={endpoint:'/api/gmail-inbox-initial-v1.php',ownerKey:'1',agentId:7,csrf:'fixture-token',
      connectionsUrl:'/connections.php?app=gmail'};
    window.VP3_COGNITIVE_PRESENTATION={openDiagnostics:()=>{window.calls.push(['diagnostics']);return true;}};
    window.VP3GmailInboxInitialV1={openTriageItem:value=>{window.calls.push(['gmail',value]);return true;},
      openArrivedItem:(ref,cid)=>{window.calls.push(['arrived',ref,cid]);return true;}};
    window.VP3TaskNotes={open:value=>window.calls.push(value)};
    window.VP3AgentOrb={activityHost:()=>document.getElementById('vp3AgentActivityHub'),
      activitySpecialists:()=>[{key:'ob1',state:'running',label:'OB1',status:'Working'}],
      positionActivity:()=>{},prepareActivity:()=>{}};
  });
  for (const file of ['agent-orb.css','agent-top-of-mind-v1.css','agent-activity-hub-v1.css','chat-specialist-rail-v1.css']) await page.addStyleTag({content:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
  for (const file of ['chat-specialist-rail-v1.js','agent-activity-hub-v1.js','agent-top-of-mind-v1.js']) await page.addScriptTag({content:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
  assert.equal(inventoryReads.length,0,'Loading Chat does not request the full Office inventory');
  await page.evaluate(() => window.VP3AgentActivityHub.open({specialist:'tasks'}));
  const hub=page.locator('#vp3AgentActivityHub');
  const summary=hub.getByRole('region',{name:'Summary',exact:true});
  const presence=hub.getByRole('group',{name:'Current presence'});
  await presence.getByText('Listening off',{exact:true}).waitFor();
  await page.evaluate(() => {
    const orb = document.querySelector('.vp3-orb-root');
    orb.dataset.state = 'listening';
    orb.dataset.eyesActive = 'true';
  });
  await presence.getByText('Listening',{exact:true}).waitFor();
  await presence.getByText('Agent Eyes on',{exact:true}).waitFor();
  await page.evaluate(() => {
    const orb = document.querySelector('.vp3-orb-root');
    delete orb.dataset.state;
    delete orb.dataset.eyesActive;
  });
  await hub.getByText('2 open',{exact:true}).waitFor();
  const casualSettings = hub.getByRole('link',{name:'Casual check-in settings'});
  assert.equal(await casualSettings.getAttribute('href'),'/connections.php#casual-checkins');
  assert.equal(await casualSettings.getAttribute('target'),'_blank','Opening settings leaves Agent Chat open.');
  assert.equal(rulesReads,0,'Rules are fetched only when opened');
  const editor = hub.locator('.vp3-agent-activity-rules');
  await editor.locator('summary').click();
  const specialty = editor.getByRole('combobox',{name:'Specialist',exact:true});
  const saveRules = editor.getByRole('button',{name:'Save rules',exact:true});
  await editor.getByRole('combobox',{name:'Needs your input',exact:true}).waitFor();
  assert.equal(await saveRules.isDisabled(),true);
  await specialty.selectOption('tasks');
  const overdue = editor.getByRole('combobox',{name:'Overdue',exact:true});
  assert.equal(await overdue.inputValue(),'ask');
  await overdue.selectOption('watch');
  await specialty.selectOption('planning');
  await editor.getByRole('combobox',{name:'Pending confirmation',exact:true}).selectOption('report');
  await specialty.selectOption('tasks');
  assert.equal(await overdue.inputValue(),'watch','Changing specialists preserves edits');
  await hub.getByRole('button',{name:'Refresh',exact:true}).click();
  await saveRules.click();
  await editor.getByText('Chat rules saved.',{exact:true}).waitFor();
  assert.equal(ruleSaves[0].tasks.overdue,'watch');
  assert.equal(ruleSaves[0].planning.pending_confirmation,'report');
  assert.equal(ruleSaves[0].gmail.needs_attention,'ask');
  await overdue.selectOption('report');
  forceRulesConflict = true;
  await saveRules.click();
  await editor.getByText('Rules changed in another session. Reload rules to continue.',{exact:true}).waitFor();
  assert.equal(await overdue.inputValue(),'report','A conflict preserves the unsaved choice until reload');
  assert.equal(await saveRules.isDisabled(),true);
  await editor.getByRole('button',{name:'Reload rules',exact:true}).click();
  await page.waitForFunction(() => [...document.querySelectorAll('.vp3-agent-activity-rules label')].some(label =>
    label.querySelector('span')?.textContent === 'Overdue' && label.querySelector('select:enabled')?.value === 'prepare'));
  assert.equal(await overdue.inputValue(),'prepare','Reload adopts the latest verified setting');
  await page.setViewportSize({width:320,height:900});
  assert.ok(await editor.evaluate(element=>element.scrollWidth-element.clientWidth<=1),'Expanded rules fit the narrow panel');
  await page.setViewportSize({width:1280,height:900});
  await editor.locator('summary').click();
  assert.equal(await hub.evaluate(element=>getComputedStyle(element.closest('[data-agent-initiative-status]')).backgroundColor),'rgb(255, 255, 255)');
  assert.equal(await summary.getByText('No new specialist findings to summarize.',{exact:true}).count(),1);
  assert.equal(await summary.evaluate(element=>Boolean(element.compareDocumentPosition(element.parentNode.querySelector('[data-activity-group]')) & Node.DOCUMENT_POSITION_FOLLOWING)),true);
  await hub.locator('[data-activity-group="Agent work"] > summary').click();
  const mission=hub.locator('.vp3-agent-activity-item').filter({hasText:'Research opportunities'});
  const detail=mission.locator('.vp3-agent-activity-detail');
  assert.equal(await detail.evaluate(element=>element.open),false,'Long details start collapsed');
  assert.equal(await detail.locator('p').isVisible(),false);
  assert.equal(await mission.locator('button').count(),4,'All existing actions remain available');
  assert.equal(await hub.locator('img').count(),0,'Titles remain text, including markup');
  assert.equal(await hub.getByText('Some sources are unavailable.',{exact:true}).isVisible(),true,'Source health is retained');
  const specialist = id => hub.locator(`[data-activity-specialist="${id}"]`);
  assert.equal(await specialist('tasks').evaluate(element=>element.classList.contains('selected')),true,'Rail selection survives the first status load');
  assert.equal(await hub.getByRole('region',{name:'Agent coordination'}).locator('span').textContent(),'Analyzing');
  assert.equal(await hub.getByRole('region',{name:'Agent coordination'}).getByText('Planning has a workshop checklist ready. <img src=x>',{exact:true}).count(),1);
  assert.equal(await specialist('ob1').count(),0,'An Initiative assessment is shown once in Agent coordination');
  assert.equal(await specialist('planning').getByRole('progressbar').evaluate(element=>element.value),55,'Measured progress is shown as measured');
  assert.equal(await specialist('research').getByRole('progressbar').getAttribute('data-indeterminate'),'','Active work without measurements stays indeterminate');
  assert.equal(await specialist('tasks').getByRole('progressbar').count(),0,'Waiting is a static status');
  assert.equal(await specialist('coding').getByRole('progressbar').count(),0,'An expired lease cannot keep showing progress');
  assert.equal(await specialist('coding').getByText('Interrupted',{exact:true}).count(),1);
  assert.equal(await specialist('gmail').count(),1,'Gmail recurring monitoring and first review share one section');
  assert.equal(await specialist('gmail').getByText('Checked',{exact:true}).count(),1,'Unanswered questions do not make Gmail appear paused.');
  const questions = specialist('gmail').locator('.vp3-agent-open-engagements');
  assert.equal(await questions.getByText('Can they deliver on Friday?',{exact:true}).isVisible(),false);
  await questions.getByText('Awaiting your reply (2+)',{exact:true}).click();
  assert.equal(await questions.locator('li').count(),2);
  await questions.getByRole('button',{name:'Show more',exact:true}).click();
  await questions.getByText('Can I ask about the venue?',{exact:true}).waitFor();
  assert.equal(await questions.getByRole('button',{name:'View in Chat',exact:true}).count(),3);
  assert.equal(await questions.locator('img').count(),0);
  await questions.getByText('Awaiting your reply (3)',{exact:true}).click();
  assert.equal(await specialist('gmail').getByText(/6 new messages reviewed/).count(),0);
  assert.equal(await specialist('gmail').getByText(/Workshop quote/).count(),0);
  assert.equal(await specialist('gmail').getByText('Ready to read in Chat',{exact:true}).count(),0);
  const monitorDetails=specialist('gmail').locator('.vp3-email-monitor-details');
  assert.equal(await monitorDetails.evaluate(element=>element.open),false);
  assert.equal(await monitorDetails.getByText('Last check completed.').isVisible(),false);
  await monitorDetails.locator('summary').click();
  assert.equal(await monitorDetails.getByRole('button',{name:'Gmail settings'}).count(),1);
  assert.equal(await monitorDetails.getByRole('button',{name:'View first inbox review'}).count(),1);
  await monitorDetails.locator('summary').click();
  showEmailReport = true;
  await hub.getByRole('button',{name:'Refresh',exact:true}).click();
  const attention=hub.locator('[data-activity-group="Needs attention"]');
  const agentWork=hub.locator('[data-activity-group="Agent work"]');
  await summary.getByText('The workshop and catering are ready for your review.',{exact:true}).waitFor();
  assert.equal(await summary.getByRole('list',{name:'Specialist updates'}).getByRole('listitem').count(),3);
  assert.equal(await summary.getByText('Confirm the guest count by Friday. <img src=x>',{exact:true}).count(),1);
  assert.equal(await summary.locator('img').count(),0);
  assert.equal(await summary.getByText('Workshop setup is on Friday',{exact:true}).count(),1);
  const briefs = summary.locator('.vp3-agent-specialist-briefings');
  await briefs.getByText('Verified specialist briefings (3)',{exact:true}).click();
  assert.equal(await briefs.locator('article').count(),3,'All three sources share one Brain reports surface');
  assert.equal(await briefs.getByText('A visitor asked about the current offering.',{exact:true}).count(),1);
  assert.equal(await briefs.getByText('Saved listening activity suggests a track. <img src=x>',{exact:true}).count(),1);
  assert.equal(await briefs.locator('img').count(),0,'Source evidence must be DOM text, never HTML');
  assert.equal(await briefs.getByText('Proposed playback; not yet executed',{exact:true}).count(),1);
  assert.equal(await briefs.getByText('Some sources could not be verified.',{exact:true}).count(),1);
  assert.equal(await briefs.getByText('Existing Profile notifications own this visitor update.',{exact:true}).count(),1);
  assert.equal(await briefs.locator('a[href*="outside.test"]').count(),0,'Never navigate to an untrusted external source');
  assert.equal(await briefs.locator('a[href="/track.php?id=8"]').count(),1,'Verified Media source navigation preserved');
  assert.equal(await briefs.locator('.vp3-agent-specialist-briefing-section b').count(),15,'All five report sections present');
  assert.equal(await briefs.getByText('Waiting for your reply in Chat',{exact:true}).count(),1,
    'Profile engagement is awaiting its actual owner reply');
  assert.equal(await briefs.getByText('Private preparation verified · not sent or published',{exact:true}).count(),1,
    'Media receipt cannot claim playback or publication');
  assert.equal(await briefs.getByText('You marked this resolved · not an executor completion',{exact:true}).count(),1,
    'Marketing owner resolution must not be represented as a completed worker action');
  assert.equal(await briefs.locator('.vp3-agent-specialist-engagement').count(),3,'One bounded lifecycle per specialist');
  assert.equal(await briefs.locator('a[data-vp3-specialist-message-id="443"][href="/chat.php?agent=7&conversation_id=42&message_id=443"]').count(),1,
    'Reply links to the exact scoped original Profile Chat question');
  assert.equal(await briefs.locator('a[data-vp3-specialist-message-id="444"][href="/chat.php?agent=7&conversation_id=42&message_id=444"]').count(),1,
    'Verified private work keeps the original Media Chat link');
  assert.equal(await briefs.locator('a[href*="outside.test"]').count(),0,'Untrusted URLs never become actionable');
  const nextSummaryCheck = reads + 1;
  await summary.locator('.vp3-agent-activity-summary-updates').evaluate(element=>window.originalSummaryList=element);
  await hub.getByRole('button',{name:'Refresh',exact:true}).click();
  await mission.locator('strong').filter({hasText:' · '+nextSummaryCheck}).waitFor();
  assert.equal(await summary.locator('.vp3-agent-activity-summary-updates').evaluate(element=>element===window.originalSummaryList),true,
    'A refresh with unchanged reports keeps the live summary DOM');
  revisedPlanningSummary = true;
  await summary.getByText('Workshop setup moved to Monday',{exact:true}).waitFor({timeout:16000});
  assert.equal(await summary.getByText('Workshop setup is on Friday',{exact:true}).count(),0,'An automatic poll replaces the old report');
  assert.equal(await briefs.getByText('You marked this resolved · not an executor completion',{exact:true}).count(),0,
    'A newly verified approved action replaces the earlier owner-only resolution');
  assert.equal(await briefs.getByText('Approved private action completed with receipt',{exact:true}).count(),1,
    'The Brain refreshes the same specialist report after the executor receipt appears');
  assert.equal(await briefs.locator('a[data-vp3-specialist-message-id="445"]').count(),1,
    'Receipt status changes must retain the original owner decision link');
  assert.equal(await summary.getByText('Catering needs a reply',{exact:true}).count(),1);
  assert.equal(await summary.getByText('Changed → Keep in Top of Mind',{exact:true}).count(),1);
  assert.equal(await agentWork.getByText('Which local suppliers rent equipment?',{exact:true}).count(),0,'Raw Gmail handoffs stay in the specialist report');
  assert.equal(await hub.getByRole('region',{name:'Email Report'}).count(),0,'Top of Mind shows shared decisions');
  assert.equal(await hub.getByText('Weekly digest is available.',{exact:true}).count(),0);

  for (const width of [1280,390,320]) {
    await page.setViewportSize({width,height:900});
    const layout=await hub.evaluate(element=>{
      const rect=value=>{const b=value.getBoundingClientRect();return {left:b.left,right:b.right,top:b.top,bottom:b.bottom,height:b.height};};
      const panel=element.closest('[data-agent-initiative-status]');
      return {panel:rect(panel),horizontalOverflow:panel.scrollWidth-panel.clientWidth,header:[...element.querySelector('header').children].map(rect),rows:[...element.querySelectorAll('.vp3-agent-activity-item')].map(row=>({row:rect(row),copy:rect(row.querySelector('.vp3-agent-activity-copy')),actions:rect(row.querySelector('.vp3-agent-activity-actions'))})),nestedScroll:[...element.querySelectorAll('*')].filter(child=>['auto','scroll'].includes(getComputedStyle(child).overflowY)).length};
    });
    assert.ok(layout.panel.left>=0&&layout.panel.right<=width,'Panel fits at '+width+'px');
    assert.ok(layout.horizontalOverflow<=1,'Long content cannot widen or horizontally scroll the pane at '+width+'px');
    assert.ok(layout.header.every(child=>child.top<layout.header[0].bottom&&child.bottom>layout.header[0].top),'Refresh, Details and Close share the title row at '+width+'px');
    for (const {row,copy,actions} of layout.rows) {
      assert.ok(row.height<=110,'Collapsed item stays compact at '+width+'px');
      assert.ok(actions.left>=copy.right-1&&actions.right<=row.right+1,'Actions stay beside the title at '+width+'px');
      assert.ok(Math.abs((copy.top+copy.bottom)-(actions.top+actions.bottom))<=2,'Text and actions are vertically aligned at '+width+'px');
    }
    assert.equal(layout.nestedScroll,0,'Only the outer pane scrolls');
    if (process.env.VP3_HUB_COMPACT_SCREENSHOTS) await page.screenshot({path:'/tmp/vp3-hub-compact-'+width+'.png'});
  }

  showCoordinationFindings=true;
  await hub.getByRole('button',{name:'Refresh',exact:true}).click();
  const overviewTab=hub.getByRole('tab',{name:'Overview',exact:true});
  const opportunitiesTab=hub.getByRole('tab',{name:'Opportunities',exact:true});
  const opportunities=hub.getByRole('tabpanel',{name:'Opportunities',exact:true});
  await overviewTab.focus();
  await page.keyboard.press('ArrowRight');
  assert.equal(await opportunitiesTab.getAttribute('aria-selected'),'true');
  const opportunityWork=opportunities.getByRole('region',{name:'Agent work',exact:true});
  await opportunities.getByRole('region',{name:'YouTube pick',exact:true}).getByText('Quiet signal',{exact:true}).waitFor();
  const ownerFinding=opportunities.getByRole('region',{name:'Needs attention',exact:true}).locator(`[data-coordination-finding="${coordinationRef}"]`);
  await ownerFinding.getByText('Question for you: Should we plan for 30 guests?',{exact:true}).waitFor();
  assert.equal(await ownerFinding.getByText('Planning & Scheduling · Pending confirmation → Ask in Chat',{exact:true}).count(),1);
  assert.equal(await ownerFinding.getByText('Posted to Chat',{exact:true}).count(),1);
  await opportunityWork.getByText('Check the venue setup',{exact:true}).waitFor();
  const reportFinding=opportunityWork.locator('[data-coordination-finding="'+'c'.repeat(64)+'"]');
  assert.equal(await reportFinding.getByText('Planning & Scheduling · Changed → Report in Chat',{exact:true}).count(),1);
  assert.equal(await reportFinding.getByText('Waiting to post to Chat',{exact:true}).count(),1);
  assert.equal(await reportFinding.getByRole('button',{name:'View in Chat'}).count(),0);
  assert.equal(await ownerFinding.getByText('The caterer and calendar agree on Friday. <img src=x>',{exact:true}).count(),1);
  assert.equal(await ownerFinding.locator('img').count(),0,'Coordination findings render as plain text');
  assert.equal(await ownerFinding.getByRole('link',{name:'Tasks & Lists · Workshop supplies'}).getAttribute('href'),
    '/chat.php?agent=7&conversation_id=42&message_id=12');
  assert.equal(await ownerFinding.getByRole('link',{name:/Friday workshop/}).count(),0,'A source cannot navigate off site');
  assert.equal(await attention.getByText('A venue wants to host a workshop. <img src=x>',{exact:true}).count(),0,'Raw Gmail handoffs do not bypass a shared rule');
  assert.equal(await opportunityWork.getByText('In progress',{exact:true}).count(),1);
  await ownerFinding.getByRole('button',{name:'Gmail · Workshop quote'}).click();
  assert.deepEqual(await page.evaluate(()=>window.calls.at(-1)),['gmail',{id:emailRef,generation:71,binding:'fixture-binding',agent:7,conversation:42}]);
  await page.evaluate(()=>window.VP3AgentActivityHub.open());
  await opportunitiesTab.click();
  await ownerFinding.getByRole('button',{name:'View in Chat'}).click();
  assert.deepEqual(await page.evaluate(()=>window.calls.at(-1)),['chat',42,{messageId:17}],'A finding opens the exact delivered Chat turn');
  coordinationResolved=true;
  await page.evaluate(()=>window.VP3AgentActivityHub.open());
  await opportunitiesTab.click();
  await opportunityWork.getByText('Result: Prepared the 30-guest checklist.',{exact:true}).waitFor();
  assert.equal(await ownerFinding.count(),0,'The resolved finding leaves Needs attention');
  assert.equal(await hub.getByText(/Should we plan for 30 guests/).count(),0,'The closed question is removed from Top of Mind');
  assert.equal(await hub.locator(`[data-coordination-finding="${coordinationRef}"]`).count(),1,'A current finding is shown once');
  assert.ok(await hub.evaluate(element=>element.scrollWidth-element.clientWidth<=1),'Findings fit at narrow viewport widths');
  await overviewTab.click();

  await detail.locator('summary').click();
  assert.equal(await detail.locator('p').textContent(),longDetail,'Expansion retains the complete original detail');
  const previousReads=reads;
  await hub.getByRole('button',{name:'Refresh',exact:true}).click();
  await mission.locator('strong').filter({hasText:' · '+(previousReads+1)}).waitFor();
  assert.ok(reads>previousReads);
  assert.equal(await detail.evaluate(element=>element.open),true,'Refresh preserves the deliberate expansion');
  await detail.locator('summary').click();
  await hub.getByRole('button',{name:'Open list',exact:true}).click();
  assert.deepEqual(await page.evaluate(()=>window.calls.at(-1)),{kind:'task_list',id:4});
  await mission.getByRole('button',{name:'Notes',exact:true}).click();
  assert.deepEqual(await page.evaluate(()=>window.calls.at(-1)),{kind:'task_list',id:8});

  await hub.locator('[data-agent-activity-listening]').evaluate(element=>{
    const audio=document.createElement('audio');audio.id='nativeAudio';element.append(audio);window.nativeAudio=audio;
    const resume=document.createElement('button');resume.textContent='Resume listening';resume.onclick=()=>window.calls.push('listening');element.append(resume);
  });
  await hub.getByRole('button',{name:'Refresh',exact:true}).click();
  await hub.getByRole('button',{name:'Resume listening',exact:true}).click();
  assert.equal(await page.evaluate(()=>window.nativeAudio===document.getElementById('nativeAudio')),true,'Refresh keeps the native listening element');
  assert.equal(await page.evaluate(()=>window.calls.at(-1)),'listening');
  await specialist('tasks').getByRole('button',{name:'View in Chat',exact:true}).click();
  assert.deepEqual(await page.evaluate(()=>window.calls.at(-1)),['chat',42,{messageId:12}],'The saved result opens its exact Chat message');
  await page.evaluate(()=>window.VP3AgentActivityHub.open({specialist:'ob1'}));
  await hub.getByRole('region',{name:'Agent coordination'}).locator('span').waitFor();
  assert.equal(await hub.getByRole('region',{name:'Agent coordination'}).evaluate(element=>element.classList.contains('selected')),true);
  await hub.getByRole('button',{name:'Close',exact:true}).click();

  const launch=page.getByRole('button',{name:'Gmail activity'});
  const workspace=page.getByRole('dialog',{name:'Gmail',exact:true});
  const emailReport=workspace.getByRole('region',{name:'Inbox analysis'});
  showEmailReport=false;
  await launch.focus();
  await page.keyboard.press('Enter');
  await emailReport.getByText('Inbox content analysis is off. Turn it on in Gmail settings.',{exact:true}).waitFor();
  assert.equal(views.at(-1),'gmail','The Gmail popup requests only its own report');
  assert.equal(await workspace.getByRole('link',{name:'Open Gmail settings'}).isVisible(),true);
  assert.equal(await workspace.getByRole('button',{name:'Analyze recent inbox'}).count(),0);
  assert.equal(await workspace.getByText(/Last check|Next check/).count(),0,'The report does not use the legacy scan timeline as a fallback');
  const arrivals=workspace.getByRole('region',{name:'Latest inbox arrivals'});
  await arrivals.getByText('6 new messages · 2 marked important · 1 reply · 1 filtered by your rules',{exact:true}).waitFor();
  assert.equal(await arrivals.getByText('New delivery <img src=x>',{exact:true}).isVisible(),true,
    'Retained arrivals remain visible after an empty scan with content analysis off');
  assert.equal(await arrivals.locator('img').count(),0);
  await workspace.getByRole('button',{name:'Gmail settings',exact:true}).click();
  const gmailSettings=page.getByRole('dialog',{name:'Gmail settings',exact:true});
  const gmailFrame=gmailSettings.locator('iframe[title="Gmail connection settings"]');
  const inboxSave=gmailFrame.contentFrame().getByRole('button',{name:'Save inbox review'});
  await inboxSave.waitFor();
  assert.deepEqual(nativeSettingsReads.at(-1),['gmail','1',null,null,null]);
  assert.equal(await page.getByRole('region',{name:'Inbox analysis'}).isVisible(),false);
  await gmailSettings.getByRole('combobox',{name:'Important update',exact:true}).selectOption('watch');
  await gmailSettings.getByRole('button',{name:'Save rules'}).click();
  await gmailSettings.getByText('Chat rules saved.',{exact:true}).waitFor();
  assert.deepEqual(Object.keys(ruleSaves.at(-1)),['gmail']);
  assert.equal(await gmailSettings.getByRole('combobox',{name:'Due soon',exact:true}).count(),0);
  await page.setViewportSize({width:320,height:900});
  assert.equal(await gmailSettings.evaluate(element=>element.scrollWidth<=element.clientWidth),true);
  assert.equal(await gmailFrame.evaluate(element=>element.clientHeight>=element.contentDocument.body.scrollHeight),true,
    'The settings panel owns vertical scrolling for the native form');
  await inboxSave.focus();
  await page.keyboard.press('Escape');
  await emailReport.waitFor();
  const gmailGear=workspace.getByRole('button',{name:'Gmail settings',exact:true});
  assert.equal(await gmailGear.evaluate(element=>element===document.activeElement),true);
  assert.equal(await gmailGear.getAttribute('aria-expanded'),'false');
  nativeOwner=2;
  await gmailGear.click();
  await gmailSettings.getByText('Gmail connection settings could not be verified here.',{exact:true}).waitFor();
  assert.equal(await gmailFrame.isVisible(),false,'A native frame for another owner stays hidden');
  await gmailSettings.getByRole('button',{name:'Back',exact:true}).click();
  nativeOwner=1;
  await page.setViewportSize({width:1280,height:900});
  await workspace.getByRole('button',{name:'Diagnostics'}).click();
  await workspace.waitFor({state:'detached'});
  assert.deepEqual(await page.evaluate(()=>window.calls.at(-1)),['diagnostics']);

  for(const [state,message] of [
    ['processing','Inbox analysis is in progress. Findings appear here as email is reviewed.'],
    ['empty','There are no findings from the latest inbox analysis.'],
    ['unavailable','The specialist couldn’t analyze the available email.'],
    ['waiting','No email has been analyzed yet. Analyze your recent inbox to get started.'],
  ]){
    analysisState=state;
    await launch.click();
    await emailReport.getByText(message,{exact:true}).waitFor();
    assert.equal(await workspace.getByText(/Last check|Next check/).count(),0);
    assert.equal(await arrivals.getByText('New delivery <img src=x>',{exact:true}).isVisible(),true);
    if(state!=='waiting')await workspace.getByRole('button',{name:'Close',exact:true}).click();
  }
  await workspace.getByRole('button',{name:'Analyze recent inbox'}).click();
  await emailReport.getByText('The workshop inquiry and catering reply are the most time-sensitive items.',{exact:true}).waitFor();
  assert.equal(analyzed,1);
  assert.equal(await hub.isVisible(),false);
  assert.equal(await workspace.locator('[data-activity-specialist="planning"]').count(),0);
  await emailReport.getByRole('region',{name:'Opportunities'}).getByText('A venue wants to host a workshop. <img src=x>',{exact:true}).waitFor();
  assert.equal(await emailReport.getByRole('region',{name:'Needs attention'}).getByText('Reply to the caterer by Friday.',{exact:true}).isVisible(),true);
  assert.equal(await emailReport.getByRole('region',{name:'Decisions'}).getByText('Choose between two travel quotes.',{exact:true}).isVisible(),true);
  assert.equal(await emailReport.getByText('Which date works for you?',{exact:false}).isVisible(),true);
  assert.equal(await emailReport.getByText('Confirm availability.',{exact:false}).isVisible(),true);
  assert.equal(await emailReport.getByText('Newer email hasn’t been analyzed yet.',{exact:true}).isVisible(),true);
  assert.equal(await emailReport.getByText('Weekly digest is available.',{exact:true}).count(),0);
  assert.equal(await emailReport.locator('img').count(),0,'Analysis text is never interpreted as markup');
  await workspace.getByRole('button',{name:'Full report'}).click();
  await emailReport.getByRole('region',{name:'Follow-ups'}).getByText('The supplier is waiting for a shipping address.',{exact:true}).waitFor();
  await emailReport.getByRole('region',{name:'Recent updates'}).getByText('Weekly digest is available.',{exact:true}).waitFor();
  assert.equal(await emailReport.getByText('They are asking about a date this week.',{exact:true}).isVisible(),true);
  assert.equal(await workspace.getByText(/Last check |Next check |View first inbox review/).count(),0,'The full report contains analyzed email without scan timestamps or the Chat review');
  assert.equal(await workspace.evaluate(element=>element.scrollWidth<=element.clientWidth),true,'Expanded Gmail workspace fits the narrow screen');
  await page.keyboard.press('Escape');
  assert.equal(await emailReport.isVisible(),true,'Closing the full report returns to the compact briefing');
  await page.keyboard.press('Escape');
  await workspace.waitFor({state:'detached'});
  assert.equal(await launch.evaluate(element=>element===document.activeElement),true);
  await launch.click();
  await arrivals.getByRole('button',{name:'Open email New delivery <img src=x>',exact:true}).click();
  await workspace.waitFor({state:'detached'});
  assert.deepEqual(await page.evaluate(()=>window.calls.at(-1)),['arrived',emailRef,42]);
  await launch.click();
  await emailReport.getByRole('button',{name:'Open latest message Workshop quote'}).click();
  assert.deepEqual(await page.evaluate(()=>window.calls.at(-1)),['gmail',{id:emailRef,generation:71,binding:'fixture-binding',agent:7,conversation:42}]);

  const tasksLaunch=page.getByRole('button',{name:'Tasks activity'});
  await tasksLaunch.click();
  const tasksWorkspace=page.getByRole('dialog',{name:'Tasks & Lists'});
  await tasksWorkspace.getByText('A list follow-up needs your review.',{exact:true}).waitFor();
  assert.equal(inventoryReads.length,0,'The compact Tasks popup does not request the full inventory');
  await tasksWorkspace.getByRole('button',{name:'Tasks & Lists settings',exact:true}).click();
  const taskSettings=page.getByRole('dialog',{name:'Tasks & Lists settings',exact:true});
  await taskSettings.getByRole('combobox',{name:'Due soon',exact:true}).waitFor();
  assert.equal(await taskSettings.getByRole('combobox',{name:'Important update',exact:true}).count(),0);
  assert.equal(await taskSettings.locator('iframe').count(),0);
  await taskSettings.getByRole('button',{name:'Back',exact:true}).click();
  await tasksWorkspace.getByRole('button',{name:'Open workspace'}).click();
  await tasksWorkspace.getByRole('button',{name:'Open list'}).waitFor();
  await tasksWorkspace.getByRole('region',{name:'Report for Confirm the head count'}).getByText('Analysis queued.',{exact:true}).waitFor();
  assert.equal(views.at(-1),'tasks','The Tasks specialist requests only office sources');
  assert.deepEqual(inventoryReads.at(-1),['all',null,null]);
  await tasksWorkspace.getByRole('navigation',{name:'Office collections'}).getByRole('button',{name:'Task lists',exact:true}).click();
  assert.equal(await tasksWorkspace.getByRole('heading',{name:'Task lists',exact:true}).evaluate(element=>document.activeElement===element),true);
  assert.equal(await tasksWorkspace.getByText('Workshop starts',{exact:true}).count(),0);
  assert.equal(await tasksWorkspace.getByRole('region',{name:'Email Report'}).count(),0);
  await tasksWorkspace.getByText('No visible items on this page.',{exact:true}).waitFor();
  await tasksWorkspace.getByRole('button',{name:'Load more reminders'}).click();
  await tasksWorkspace.getByText('Follow up with the venue',{exact:true}).waitFor();
  await tasksWorkspace.getByRole('region',{name:'Report for Follow up with the venue'}).getByText('The analysis is no longer current.',{exact:true}).waitFor();
  await tasksWorkspace.getByRole('button',{name:'Load more tasks'}).click();
  await tasksWorkspace.getByText('Earlier completed task',{exact:true}).waitFor();
  assert.equal(await tasksWorkspace.getByRole('region',{name:'Report for Earlier completed task'}).count(),0);
  await Promise.all([
    page.waitForResponse(response=>new URL(response.url()).searchParams.get('collection') === 'all'),
    page.evaluate(()=>window.VP3AgentActivityHub.refresh()),
  ]);
  await tasksWorkspace.getByText('Earlier completed task',{exact:true}).waitFor();
  await tasksWorkspace.getByRole('button',{name:'Load more task lists'}).click();
  const cateringList=tasksWorkspace.locator('.vp3-agent-activity-item').filter({hasText:'Catering checklist'});
  await cateringList.getByRole('button',{name:'Open list'}).click();
  await tasksWorkspace.getByText('Ask for the revised menu',{exact:true}).waitFor();
  await tasksWorkspace.getByRole('region',{name:'Report for Ask for the revised menu'}).getByText('Check available menu options · In progress',{exact:true}).waitFor();
  assert.deepEqual(inventoryReads.at(-1),['list_items',null,'3']);
  wrongListPage=true;
  await tasksWorkspace.getByRole('button',{name:'Load more list items'}).click();
  await tasksWorkspace.getByText('Could not load more items. Try again.',{exact:true}).waitFor();
  assert.equal(await tasksWorkspace.getByText('Order coffee cups',{exact:true}).count(),0,'A mismatched list page cannot render its items');
  wrongListPage=false;
  await tasksWorkspace.getByRole('button',{name:'Load more list items'}).click();
  await tasksWorkspace.getByText('Order coffee cups',{exact:true}).waitFor();
  const cupsReport = tasksWorkspace.getByRole('region',{name:'Report for Order coffee cups'});
  await cupsReport.getByText(taskReport.summary,{exact:true}).waitFor();
  assert.equal(await cupsReport.getByRole('button',{name:'View in Chat'}).count(),0,'A report link cannot switch to another Agent');
  assert.equal(await tasksWorkspace.getByRole('region',{name:'Report for Ask for the revised menu'}).getByText(taskReport.summary).count(),0);
  assert.deepEqual(inventoryReads.at(-1),['list_items','22','3']);
  assert.equal(await tasksWorkspace.getByRole('button',{name:'Load more list items'}).count(),0);
  cupsAnalysisUpdated = true;
  await page.clock.setFixedTime(new Date(Date.now() + 15000));
  await cupsReport.evaluate(element=>element.scrollIntoView({block:'center'}));
  const loadedPageReads = inventoryReads.filter(([collection,cursor])=>collection==='list_items' && cursor==='22').length;
  await page.evaluate(()=>window.VP3AgentActivityHub.refresh());
  await cupsReport.getByText('The supplier confirmed the order and pickup window.',{exact:true}).waitFor();
  assert.equal(inventoryReads.filter(([collection,cursor])=>collection==='list_items' && cursor==='22').length,loadedPageReads+1);
  assert.equal(await tasksWorkspace.getByText('Ask for the revised menu',{exact:true}).count(),1,'Refreshing an older report preserves other list rows');
  await page.clock.setFixedTime(new Date());
  await tasksWorkspace.getByRole('button',{name:'Back to inventory'}).click();
  await tasksWorkspace.getByText('Earlier completed task',{exact:true}).waitFor();
  await tasksWorkspace.getByText('Catering checklist',{exact:true}).waitFor();
  await tasksWorkspace.getByRole('button',{name:'Open reminders'}).click();
  assert.deepEqual(await page.evaluate(()=>window.calls.at(-1)),{kind:'reminders',view:'all',timezone:'America/Denver'});
  await tasksWorkspace.waitFor({state:'detached'});

  showOfficeReport=true;
  await tasksLaunch.click();
  const completedReview=tasksWorkspace.locator('.vp3-agent-activity-item').filter({hasText:officeReviewTitle});
  await completedReview.getByText(officeReviewTitle,{exact:true}).waitFor();
  await completedReview.getByText('Next step: Confirm the head count before Friday.',{exact:true}).waitFor();
  await completedReview.getByText('Suggested question: How many people should the caterer plan for?',{exact:true}).waitFor();
  assert.equal(await tasksWorkspace.getByRole('button',{name:'View in Chat',exact:true}).count(),1,'The compact popup shows one exact review link');
  assert.equal(await tasksWorkspace.getByText('Reminder review: Venue access',{exact:true}).count(),0);
  assert.equal(await tasksWorkspace.getByRole('button',{name:'Open list'}).count(),0,'Raw office records stay in the workspace');
  await tasksWorkspace.getByRole('button',{name:'Full report',exact:true}).click();
  const entryReport = tasksWorkspace.getByRole('region',{name:/^Report for Workshop supplies/});
  await entryReport.getByText(officeReviewDetail,{exact:true}).waitFor();
  await entryReport.getByText('Analysis uses selected saved details.',{exact:true}).waitFor();
  assert.equal(await tasksWorkspace.getByText(officeReviewDetail,{exact:true}).count(),1,'An attached review does not repeat in the expanded aggregate');
  await entryReport.getByText('Question for you: ' + officeQuestion,{exact:true}).waitFor();
  assert.deepEqual(await entryReport.getByRole('listitem').allTextContents(),listReport.action_plan);
  await entryReport.getByRole('heading',{name:'Agent Task',exact:true}).waitFor();
  await entryReport.getByText('Check the workshop order · Completed',{exact:true}).waitFor();
  await entryReport.getByText(listReport.agent_task.result,{exact:true}).waitFor();
  assert.equal(await tasksWorkspace.locator('img').count(),0,'Office report text stays plain text');
  assert.deepEqual(await tasksWorkspace.locator('.vp3-agent-activity-source h4').allTextContents(),['Completed reviews','Reminders','Tasks','Task lists']);
  assert.equal(await tasksWorkspace.evaluate(element=>element.scrollWidth<=element.clientWidth),true);
  await entryReport.getByRole('button',{name:'View in Chat'}).click();
  assert.deepEqual(await page.evaluate(()=>window.calls.at(-1)),['chat',42,{messageId:12}],'The attached review opens its exact delivered Chat message');
  await tasksWorkspace.waitFor({state:'detached'});

  const planningLaunch=page.getByRole('button',{name:'Planning activity'});
  const planningWorkspace=page.getByRole('dialog',{name:'Planning & Scheduling',exact:true});
  await planningLaunch.click();
  await planningWorkspace.getByText('Reviewing the workshop agenda.',{exact:true}).waitFor();
  assert.equal(views.includes('planning'),false,'Opening the compact Planning popup does not load appointments');
  await planningWorkspace.getByRole('button',{name:'Planning & Scheduling settings',exact:true}).click();
  const planningSettings=page.getByRole('dialog',{name:'Planning & Scheduling settings',exact:true});
  await planningSettings.frameLocator('iframe[title="Scheduling settings"]').getByRole('button',{name:'Save schedule'}).waitFor();
  assert.deepEqual(nativeSettingsReads.at(-1),['planning','1','7','42','settings']);
  await planningSettings.getByRole('combobox',{name:'Pending confirmation',exact:true}).waitFor();
  await page.evaluate(() => window.dispatchEvent(new Event('stonefellow:chat-context-changing')));
  await planningSettings.waitFor({state:'detached'});
  assert.equal(await page.locator('iframe[title="Scheduling settings"]').count(),0);
  await planningLaunch.click();
  await planningWorkspace.getByRole('button',{name:'Open workspace'}).click();
  await planningWorkspace.getByText('Workshop setup visit',{exact:true}).waitFor();
  assert.equal(views.at(-1),'planning','The expanded Planning popup requests only its own data');
  assert.deepEqual(await planningWorkspace.locator('.vp3-agent-activity-source h4').allTextContents(),['Upcoming events','Appointments']);
  assert.equal(await planningWorkspace.getByText('The event has a saved agenda.',{exact:true}).isVisible(),true);
  assert.equal(await planningWorkspace.getByText('Next step: Check what is still needed to confirm the appointment.',{exact:true}).isVisible(),true);
  assert.equal(await planningWorkspace.getByRole('button',{name:'Open appointment',exact:true}).isVisible(),true);
  await planningWorkspace.getByRole('button',{name:'Close',exact:true}).click();

  await page.evaluate(() => window.VP3ChatSpecialists.update('media',{label:'Media & Entertainment',state:'idle',status:'Idle',
    presence:true,inline:false,keepLauncher:true}));
  const mediaLaunch = page.locator('[data-chat-specialist="media"]');
  const mediaWorkspace = page.getByRole('dialog',{name:'Media & Entertainment',exact:true});
  await mediaLaunch.click();
  await mediaWorkspace.getByText('Want to pick up Quiet signal?',{exact:false}).waitFor();
  assert.equal(views.at(-1),'media','The rail requests only the scoped Media report');
  assert.equal(await mediaWorkspace.getByText('The acoustic session',{exact:true}).count(),0,'The compact preview starts with the leading finding');
  assert.equal(await mediaWorkspace.locator('img').count(),0,'Media evidence remains plain text');
  assert.equal(await mediaWorkspace.getByRole('button',{name:'Unsafe destination'}).count(),0);
  await mediaWorkspace.getByRole('button',{name:'Resume Quiet signal'}).click();
  assert.deepEqual(await page.evaluate(()=>window.calls.at(-1)),['song',8]);
  await mediaWorkspace.getByRole('button',{name:'Media & Entertainment settings',exact:true}).click();
  const mediaSettings = page.getByRole('dialog',{name:'Media & Entertainment settings',exact:true});
  await mediaSettings.frameLocator('iframe[title="Music and YouTube preferences"]').getByRole('button',{name:'Save playback suggestions'}).waitFor();
  assert.deepEqual(nativeSettingsReads.at(-1),['music','1',null,null,null]);
  await mediaSettings.getByRole('combobox',{name:'Unfinished track',exact:true}).waitFor();
  await mediaSettings.getByRole('button',{name:'Back',exact:true}).click();
  await mediaWorkspace.getByRole('button',{name:'Full report',exact:true}).click();
  await mediaWorkspace.getByText('The acoustic session',{exact:true}).waitFor();
  await mediaWorkspace.getByText('Saved acoustic search',{exact:true}).waitFor();
  await page.setViewportSize({width:320,height:820});
  assert.equal(await mediaWorkspace.evaluate(element=>element.scrollWidth<=element.clientWidth),true,'The full report fits mobile');
  await mediaWorkspace.getByRole('button',{name:'Open Player',exact:true}).click();
  await mediaWorkspace.waitFor({state:'detached'});
  assert.deepEqual(await page.evaluate(()=>window.calls.at(-1)),['player'],'The report opens the existing player in Chat');
  await page.setViewportSize({width:1280,height:900});

  specialistClaim='gmail';
  await launch.click();
  await workspace.locator('[data-specialist-state="working"]').waitFor();
  await workspace.getByRole('button',{name:'Close',exact:true}).click();
  specialistClaim='gmail-expired';
  await launch.click();
  await workspace.getByRole('region',{name:'Latest inbox arrivals'}).waitFor();
  assert.equal(await workspace.locator('[data-specialist-state="working"]').count(),0,'An expired analysis claim cannot show Gmail as working');
  await workspace.getByRole('button',{name:'Close',exact:true}).click();
  specialistClaim='media';
  await mediaLaunch.click();
  await mediaWorkspace.locator('[data-specialist-state="working"]').waitFor();
  await mediaWorkspace.getByRole('button',{name:'Close',exact:true}).click();
  specialistClaim='media-expired';
  await mediaLaunch.click();
  await mediaWorkspace.getByText('Want to pick up Quiet signal?',{exact:false}).waitFor();
  assert.equal(await mediaWorkspace.locator('[data-specialist-state="working"]').count(),0,'An expired Media claim cannot show active work');
  await mediaWorkspace.getByRole('button',{name:'Close',exact:true}).click();
  specialistClaim='';

  await page.evaluate(() => {window.chatReady=false;});
  await launch.click();
  await workspace.getByText('Waiting for Agent Chat to finish connecting…',{exact:true}).waitFor();
  assert.equal(await emailReport.count(),0,'No report is rendered until the current private Chat is ready');
  hubStatus=503;
  await page.evaluate(() => {window.chatReady=true;window.dispatchEvent(new Event('vp3:chat-context-ready'));});
  await workspace.getByRole('button',{name:'Retry now'}).waitFor();
  hubStatus=200;
  await workspace.getByRole('button',{name:'Retry now'}).click();
  await emailReport.getByText('The workshop inquiry and catering reply are the most time-sensitive items.',{exact:true}).waitFor();
  hubStatus=403;
  await page.evaluate(() => window.VP3AgentActivityHub.refresh());
  await workspace.getByRole('button',{name:'Reload Chat'}).waitFor();
  assert.equal(await emailReport.count(),0,'Refused access clears the previous private report and retains the popup');
  await page.evaluate(() => window.dispatchEvent(new Event('vp3:participant-selection-changed')));
  await workspace.waitFor({state:'detached'});
  assert.deepEqual(errors,[]);
  console.log('Compact activity hub desktop/mobile layout, settings scope, expansion, actions, source health and listening passed');
} finally { await browser.close(); }
