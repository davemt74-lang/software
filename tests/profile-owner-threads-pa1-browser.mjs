import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const read=name=>fs.readFileSync(new URL('../'+name,import.meta.url),'utf8');
const files={activity:read('profile-activity-chat.js'),portal:read('profile-agent-portal.js')};
const css={activity:read('profile-activity-chat.css'),portal:read('profile-agent-portal.css')};
const portalMarkup=`<main class="profile-agent-app"><nav>${['inbox','visitors','radar','agent','knowledge','profile','analytics'].map(tab=>`<button data-pa-tab="${tab}">${tab}</button>`).join('')}<button id="profileAgentRefresh">Refresh</button></nav><div id="profileAgentNotice"></div><div id="profileAgentServiceStatus"></div><div id="profileAgentMetrics"></div><div id="profileAgentPortal"><section data-pa-view="inbox"><div id="profileAgentAttention"></div><div id="profileAgentConversations"></div><div id="profileAgentThread"></div></section><section data-pa-view="visitors"><div id="profileAgentVisitors"></div></section><section data-pa-view="radar"><div id="profileAgentRadar"></div></section><section data-pa-view="agent"><div id="profileAgentSettings"></div></section><section data-pa-view="knowledge"><div id="profileAgentKnowledge"></div></section><section data-pa-view="profile"><div id="profileAgentProfileSettings"></div></section><section data-pa-view="analytics"><div id="profileAgentAnalytics"></div></section></div></main>`;
const server=http.createServer((req,res)=>{
  const surface=req.url.startsWith('/portal')?'portal':'activity';
  res.writeHead(200,{'Content-Type':'text/html'});
  res.end(`<!doctype html><meta charset="utf-8"><style>${css[surface]} [data-pa-view]{display:none}[data-pa-view].active{display:block}.profile-agent-app{display:block}.profile-agent-portal{height:auto}.profile-agent-thread{min-height:200px} nav{padding:10px}</style><div class="chat-topbar-actions"><div id="chatProfileMenu"></div><button id="chatNotificationButton">Notifications</button></div>${surface==='portal'?portalMarkup:''}<script>window.__polls=[];window.setInterval=(fn,delay)=>{window.__polls.push({fn,delay});return window.__polls.length};window.clearInterval=()=>{};window.__tick=()=>window.__polls.filter(x=>x.delay===15000).forEach(x=>x.fn());window.STONEFELLOW_AGENT_IDENTITY_V236={profileAgentEndpoint:'/api/profile-agent.php',profileAgentUrl:'/profile-agent.php',csrf:'fixture'};window.PROFILE_AGENT_PORTAL={endpoint:'/api/profile-agent.php',csrf:'fixture'};</script><script>${files[surface]}</script>`);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const base=`http://127.0.0.1:${server.address().port}`;
let browser,checks=0;
const check=(value,message)=>{assert.ok(value,message);checks++;console.log('PASS '+message);};
const copy=value=>JSON.parse(JSON.stringify(value));
async function fixture(surface){
  const page=await browser.newPage({viewport:{width:1280,height:1000}}),calls=[],holds=[],errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  const threads=new Map([101,202].map(id=>[id,{conversation:{id,visitor_label:`Visitor ${id}`,status:'open',last_message_at:'2026-10-01 12:00:00'},messages:[{id,conversation_id:id,sender_type:'visitor',message:`Private question from ${id}`}]}]));
  const profile={username:'owner',bio:'Saved bio',profile_agent_id:7,profile_agent_enabled:1,profile_agent_greeting:'Saved greeting',profile_agent_instructions:'Saved instructions',is_public:1};
  const policies={knowledge:{label:'Knowledge',description:'Explicit public material',profile_agent_allowed:false,audience_scope:'private'}};
  const state=()=>({profile:copy(profile),agents:[{id:7,display_name:'Profile helper',is_active:1}],public_agent_status:{reason:'live',agent_name:'Profile helper'},profile_url:'/owner',profile_media:{},policies:copy(policies),analytics:{},notifications:{unread:0},attention:[],activity:[],visits:[],conversations:[...threads.values()].map(t=>({...t.conversation,last_summary:t.messages.at(-1)?.message||''}))});
  await page.route('**/api/profile-agent.php*',async route=>{
    const request=route.request(),input=request.method()==='POST'?request.postDataJSON():{action:new URL(request.url()).searchParams.get('action')};
    calls.push(input);let payload={ok:true};
    if(input.action==='owner_state')payload.state=state();
    else if(input.action==='conversation_messages')Object.assign(payload,copy(threads.get(input.conversation_id)||{}));
    else if(input.action==='owner_reply'){
      const thread=threads.get(input.conversation_id);thread.messages.push({id:1000+calls.length,conversation_id:input.conversation_id,sender_type:'owner',message:input.message});thread.conversation.status='owner_joined';
      Object.assign(payload,{conversation_id:input.conversation_id,state:state(),messages:copy(thread.messages)});
    }else if(input.action==='conversation_status'){
      threads.get(input.conversation_id).conversation.status=input.status;Object.assign(payload,{conversation_id:input.conversation_id,state:state()});
    }else if(input.action==='save_profile_agent'||input.action==='save_profile'){
      for(const [key,value] of Object.entries(input))if(!['action','csrf_token'].includes(key))profile[key]=value;payload.state=state();
    }else if(input.action==='save_profile_access'){
      policies[input.resource_type]={...policies[input.resource_type],profile_agent_allowed:!!input.profile_agent_allowed,audience_scope:input.audience_scope};payload.state=state();
    }else throw new Error('Unexpected fixture action '+input.action);
    const index=holds.findIndex(h=>h.action===input.action&&(h.id===undefined||h.id===input.conversation_id));
    if(index>=0){const hold=holds.splice(index,1)[0];hold.arrived({input,payload,release:async(overrides={},status=200)=>{
      const finished=page.waitForEvent('requestfinished',{predicate:r=>r===request});
      await route.fulfill({status,contentType:'application/json',body:JSON.stringify({...payload,...overrides})});await finished;
      await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>resolve())));
    }});}
    else await route.fulfill({contentType:'application/json',body:JSON.stringify(payload)});
  });
  const selectors=surface==='activity'?{form:'[data-profile-reply]',messages:'[data-profile-thread-messages]',identity:'[data-profile-thread-identity]',notice:'[data-profile-thread-notice]',resolve:'[data-profile-resolve]'}:{form:'#paReplyForm',messages:'.profile-agent-thread-messages',identity:'[data-thread-identity]',notice:'[data-thread-notice]',resolve:'[data-thread-status="resolved"]'};
  const hold=(action,id)=>new Promise(arrived=>holds.push({action,id,arrived}));
  const open=async id=>{
    if(surface==='activity')await page.evaluate(id=>window.STONEFELLOW_PROFILE_ACTIVITY.open({tab:'conversations',conversationId:id}),id);
    else{await page.locator('[data-pa-tab="inbox"]').click();await page.locator(`[data-open-conversation="${id}"]`).click();}
  };
  const ready=async id=>page.waitForFunction(({form,id})=>Number(document.querySelector(form)?.dataset.conversationId)===id,{form:selectors.form,id});
  await page.goto(base+'/'+surface);
  if(surface==='portal')await page.locator('[data-open-conversation="101"]').waitFor();
  else await page.locator('.chat-profile-activity-button').waitFor();
  return {page,calls,threads,profile,policies,state,selectors,hold,open,ready,input:page.locator(selectors.form+' input[name="message"]'),async close(){check(errors.length===0,surface+' has no runtime errors: '+errors.join('; '));await page.close();}};
}

try{
  browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
  for(const surface of ['activity','portal']){
    const f=await fixture(surface),{page,selectors}=f;
    const a=f.hold('conversation_messages',101);await f.open(101);const readA=await a;
    const b=f.hold('conversation_messages',202);await f.open(202);const readB=await b;
    await readB.release();await f.ready(202);await readA.release();
    check((await page.locator(selectors.messages).innerText()).includes('Private question from 202')&&!(await page.locator(selectors.messages).innerText()).includes('Private question from 101'),surface+' reverse read completion keeps the selected visitor');
    await f.input.fill('Reply intended for visible visitor 202');const sent=f.hold('owner_reply',202);await page.locator(selectors.form+' button[type="submit"]').click();const reply=await sent;
    check(reply.input.conversation_id===202&&reply.input.message==='Reply intended for visible visitor 202',surface+' reply uses the immutable displayed conversation');
    await reply.release();await page.waitForFunction(selector=>document.querySelector(selector)?.textContent==='Reply sent.',selectors.notice);
    check(await f.input.inputValue()==='',surface+' verified reply clears only its sent draft');

    // A later selection of the same ID must also invalidate the first read.
    const old=f.hold('conversation_messages',101);await f.open(101);const oldA=await old;
    await f.open(202);await f.ready(202);f.threads.get(101).messages.push({id:901,conversation_id:101,sender_type:'visitor',message:'Latest visitor 101 message'});
    await f.open(101);await f.ready(101);await oldA.release();
    check((await page.locator(selectors.messages).innerText()).includes('Latest visitor 101 message'),surface+' A to B to A rejects the first A response');

    const failed=f.hold('conversation_messages',202);await f.open(202);const staleFailure=await failed;await f.open(101);await f.ready(101);await staleFailure.release({ok:false,error:'STALE visitor 202 error'},503);
    check(!(await page.locator('body').innerText()).includes('STALE visitor 202 error')&&(await page.locator(selectors.messages).innerText()).includes('Latest visitor 101 message'),surface+' stale read failure cannot replace the new thread');

    for(const action of ['owner_reply','conversation_status'])for(const failure of [false,true]){
      await f.open(101);await f.ready(101);await f.input.fill('Draft for old visitor 101');
      const pending=f.hold(action,101);
      await page.locator(action==='owner_reply'?selectors.form+' button[type="submit"]':selectors.resolve).click();const mutation=await pending;
      check(mutation.input.conversation_id===101,surface+' '+action+' captures the displayed target before awaiting');
      await f.open(202);await f.ready(202);await f.input.fill('Unsent draft for current visitor 202');
      await mutation.release(failure?{ok:false,error:'STALE mutation failure'}:{},failure?503:200);
      check(Number(await page.locator(selectors.form).getAttribute('data-conversation-id'))===202&&(await f.input.inputValue())==='Unsent draft for current visitor 202',surface+' late '+action+' '+(failure?'failure':'success')+' preserves the new thread and draft');
      check(!(await page.locator(selectors.notice).innerText()).includes(failure?'STALE':'sent'),surface+' stale mutation does not publish a result in the new thread');
    }

    await f.open(101);await f.ready(101);await f.input.fill('Draft retained until exact acknowledgment');
    const badAck=f.hold('owner_reply',101);await page.locator(selectors.form+' button[type="submit"]').click();const wrong=await badAck;
    await wrong.release({conversation_id:202});
    check((await f.input.inputValue())==='Draft retained until exact acknowledgment'&&(await page.locator(selectors.notice).innerText()).includes('could not be confirmed'),surface+' mismatched mutation acknowledgment preserves the draft and reports uncertainty');
    const writes=f.calls.filter(x=>['owner_reply','conversation_status'].includes(x.action)).length;
    await f.input.fill('A focused unsent draft');await f.input.evaluate(node=>{node.focus();node.setSelectionRange(3,9);window.__savedInput=node;});
    f.threads.get(101).conversation.status='resolved';f.threads.get(101).messages.push({id:902,conversation_id:101,sender_type:'visitor',message:'Incoming while owner types'});
    await page.evaluate(()=>window.__tick());
    await page.waitForFunction(selector=>document.querySelector(selector)?.textContent.includes('Incoming while owner types'),selectors.messages);
    check(await f.input.evaluate(node=>node===window.__savedInput&&document.activeElement===node&&node.value==='A focused unsent draft'&&node.selectionStart===3&&node.selectionEnd===9),surface+' periodic transcript refresh retains the exact input, focus, selection and draft');
    check((await page.locator(selectors.identity).innerText()).includes('resolved'),surface+' periodic thread refresh shows the current saved status');
    check(f.calls.filter(x=>['owner_reply','conversation_status'].includes(x.action)).length===writes,surface+' passive refresh does not send or mutate a thread');
    await f.open(202);await f.ready(202);check((await f.input.inputValue())==='Unsent draft for current visitor 202',surface+' each visitor retains a separate unsent draft');
    await f.open(101);await f.ready(101);check((await f.input.inputValue())==='A focused unsent draft',surface+' switching back restores only that visitor draft');

    const mismatch=f.hold('conversation_messages',202);await f.open(202);const foreign=await mismatch;await foreign.release({conversation:{id:101},messages:[{message:'WRONG thread response'}]});
    check(await page.locator(selectors.form).count()===0&&!(await page.locator('body').innerText()).includes('WRONG thread response'),surface+' mismatched read response cannot create a reply form');
    await f.close();
  }

  const f=await fixture('portal'),{page}=f;
  await page.locator('[data-pa-tab="agent"]').click();const greeting=page.locator('#paAgentForm [name="profile_agent_greeting"]');
  await greeting.fill('Unsaved greeting');await greeting.evaluate(node=>{node.focus();node.setSelectionRange(2,7);window.__savedGreeting=node;});
  for(let i=0;i<3;i++){const wait=page.waitForResponse(r=>r.url().includes('action=owner_state'));await page.evaluate(()=>window.__tick());await wait;await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(resolve)));}
  check(await greeting.evaluate(node=>node===window.__savedGreeting&&document.activeElement===node&&node.value==='Unsaved greeting'&&node.selectionStart===2&&node.selectionEnd===7),'portal refresh retains dirty focused settings nodes and cursor');
  await page.locator('[data-pa-tab="inbox"]').click();await page.evaluate(()=>window.__tick());await page.locator('[data-pa-tab="agent"]').click();
  check(await greeting.inputValue()==='Unsaved greeting','portal refresh preserves settings drafts after focus moves away');
  const save=f.hold('save_profile_agent');await page.locator('#paAgentForm button[type="submit"]').click();const saving=await save;
  check(f.calls.filter(x=>x.action==='save_profile_agent').length===1,'retained settings form has exactly one submit listener after repeated refreshes');
  await greeting.fill('New edits made while saving');await saving.release();
  check(await greeting.inputValue()==='New edits made while saving','settings save acknowledgment cannot erase newer edits');
  await page.locator('[data-pa-tab="profile"]').click();const bio=page.locator('#paProfileForm [name="bio"]');await bio.fill('Unsaved public bio');
  await page.locator('[data-profile-media-form="cover"] input[type="file"]').setInputFiles({name:'cover.png',mimeType:'image/png',buffer:Buffer.from('local-fixture')});
  await page.locator('[data-pa-tab="inbox"]').click();const poll=page.waitForResponse(r=>r.url().includes('action=owner_state'));await page.evaluate(()=>window.__tick());await poll;await page.locator('[data-pa-tab="profile"]').click();
  check(await bio.inputValue()==='Unsaved public bio'&&await page.locator('[data-profile-media-form="cover"] input[type="file"]').evaluate(node=>node.files[0]?.name==='cover.png'),'portal background refresh preserves profile edits and an unsubmitted file selection');
  await page.locator('[data-pa-tab="knowledge"]').click();
  const first=f.hold('save_profile_access');await page.locator('[data-policy="knowledge"] [data-policy-allow]').check();const policySave=await first;
  const next=f.hold('save_profile_access');await page.locator('[data-policy="knowledge"] [data-policy-audience]').selectOption('public');await policySave.release();const queued=await next;
  check(queued.input.profile_agent_allowed===1&&queued.input.audience_scope==='public','knowledge policy changes made during a save are serialized with their latest explicit values');await queued.release();
  check(f.calls.filter(x=>x.action==='save_profile_access').length===2,'knowledge policy retention does not duplicate change listeners or writes');
  await f.close();
  console.log(`PROFILE_OWNER_THREADS_PA1_BROWSER=${checks} assertions PASS`);
}finally{
  if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));
}
