import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
import {choiceMarkup,chatChoice} from './fixtures/profile-chat-choice-pa3d.mjs';

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const sources=new Map(['profile-agent.js','profile-agent-portal.js'].map(name=>['/'+name,fs.readFileSync(new URL('../'+name,import.meta.url),'utf8')]));
const namespace='a'.repeat(64),threads=new Map(),requests=[],stalledRadar=new Set(),failedStartup=new Set();
let documentSequence=0,messageSequence=100,checks=0;
const portalMarkup=`<main class="profile-agent-app"><button id="profileAgentRefresh">Refresh</button><div id="profileAgentNotice"></div><div id="profileAgentServiceStatus"></div><div id="profileAgentMetrics"></div><div id="profileAgentPortal"><section data-pa-view="inbox"></section>${['profileAgentAttention','profileAgentConversations','profileAgentThread','profileAgentVisitors','profileAgentRadar','profileAgentSettings','profileAgentKnowledge','profileAgentProfileSettings','profileAgentAnalytics'].map(id=>`<div id="${id}"></div>`).join('')}</div></main>`;
const ownerThread={id:101,visitor_label:'Visitor 101',status:'open',last_summary:'Question for the owner'};
const ownerState=()=>({profile:{username:'owner',profile_agent_id:7,profile_agent_enabled:1},profile_url:'/owner',public_agent_status:{reason:'live'},agents:[],policies:{},analytics:{},notifications:{},visits:[],activity:[],conversations:[{...ownerThread}]});
const server=http.createServer(async(req,res)=>{
  const url=new URL(req.url,'http://fixture.test');
  if(sources.has(url.pathname)){res.writeHead(200,{'Content-Type':'text/javascript'});res.end(sources.get(url.pathname));return;}
  if(url.pathname==='/api/agent-radar.php'){stalledRadar.add(res);res.on('close',()=>stalledRadar.delete(res));return;}
  if(url.pathname==='/api/profile-agent.php'){
    let body='';for await(const chunk of req)body+=chunk;
    const input=body?JSON.parse(body):Object.fromEntries(url.searchParams);requests.push(input);
    const reply=data=>{res.writeHead(200,{'Content-Type':'application/json','Cache-Control':'no-store'});res.end(JSON.stringify({ok:true,...data}));};
    if(input.action==='owner_state')return reply({state:ownerState()});
    if(input.action==='conversation_messages')return reply({conversation:{...ownerThread},messages:[{id:101,sender_type:'visitor',message:'Question for the owner'}]});
    if(input.action==='conversation_status'){ownerThread.status=input.status;return reply({conversation_id:input.conversation_id,state:ownerState()});}
    const token=req.headers.cookie?.match(/fixture=([^;]+)/)?.[1]||'default';
    if(!threads.has(token))threads.set(token,{conversation:null,handoff:null,messages:[],receipts:new Map()});
    const thread=threads.get(token);
    if(token==='startup-recovery'&&input.action==='state'&&!failedStartup.has(token)){
      failedStartup.add(token);thread.conversation={conversation_id:31,status:'open'};thread.messages=[{id:90,sender_type:'agent',message:'Saved conversation recovered'}];
      res.writeHead(503,{'Content-Type':'application/json'});res.end(JSON.stringify({ok:false,error:'Temporary startup interruption'}));return;
    }
    const snapshot=()=>({conversation:thread.conversation,handoff:thread.handoff,chat:chatChoice({live_text_available:false}),agent:{name:'Profile Guide'},messages:thread.messages.filter(m=>m.id>Number(input.after_id||0))});
    if(['state','poll'].includes(input.action))return reply(snapshot());
    if(input.action==='message'){
      let receipt=thread.receipts.get(input.client_request_id);
      if(!receipt){
        const message={id:++messageSequence,sender_type:'visitor',message:input.message};thread.messages.push(message);
        thread.conversation={conversation_id:31,status:'open'};
        thread.handoff={turn_id:message.id,revision:1,state:'agent_ready',dispatch_state:'agent_ready',dispatch_turn_id:message.id,conversation_id:31};
        receipt={conversation_id:31,visitor_message_id:message.id,client_request_id:input.client_request_id};thread.receipts.set(input.client_request_id,receipt);
      }
      return reply({...snapshot(),...receipt});
    }
    if(input.action==='dispatch'){
      if(thread.handoff.state!=='replied')thread.messages.push({id:++messageSequence,sender_type:'agent',message:'Confirmed public answer'});
      thread.handoff={...thread.handoff,state:'replied',dispatch_state:'none',revision:2};return reply({dispatch_checked:true});
    }
    res.writeHead(422,{'Content-Type':'application/json'});res.end(JSON.stringify({ok:false,error:'Unexpected fixture action'}));return;
  }
  res.writeHead(200,{'Content-Type':'text/html'});
  const portal=url.pathname==='/portal',cfg=portal?{endpoint:'/api/profile-agent.php',radarEndpoint:'/api/agent-radar.php',csrf:'fixture'}:{username:'fixture-owner',endpoint:'/api/profile-agent.php',conversationNamespace:namespace,profileToken:'fixture',agentName:'Profile Guide'};
  res.end(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>button,input,textarea{min-height:36px}#profileAgentThread{min-height:180px}</style>${portal?portalMarkup:choiceMarkup}<script>window.__documentSequence=${++documentSequence};window.${portal?'PROFILE_AGENT_PORTAL':'STONEFELLOW_PROFILE_AGENT'}=${JSON.stringify(cfg)};</script><script src="/${portal?'profile-agent-portal':'profile-agent'}.js"></script>`);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const base=`http://127.0.0.1:${server.address().port}`;
let browser;const failures=[];
const check=(value,label)=>{assert.ok(value,label);checks++;console.log('PASS '+label);};
async function scenario(name,run){try{await run();}catch(error){failures.push(name+': '+error.message);console.error('FAIL '+name+': '+error.message);}}
async function visitor(mode){
  const context=await browser.newContext({viewport:{width:390,height:740}}),page=await context.newPage(),errors=[];
  await context.addCookies([{name:'fixture',value:mode,url:base}]);page.on('pageerror',error=>errors.push(error.message));
  if(mode==='blocked-read')await page.addInitScript(()=>Object.defineProperty(window,'localStorage',{get(){throw new DOMException('Storage denied','SecurityError');}}));
  if(mode==='blocked-write')await page.addInitScript(()=>{const original=Storage.prototype.setItem;Storage.prototype.setItem=function(key,value){if(this===localStorage)throw new DOMException('Storage full','QuotaExceededError');return original.call(this,key,value);};});
  if(mode==='startup-recovery')await page.addInitScript(key=>localStorage.setItem(key,'31'),`stonefellow-profile-agent:v1:fixture-owner:${namespace}`);
  await page.goto(base+'/visitor');
  return {context,page,errors,async send(message){await page.locator('textarea').first().fill(message);await page.getByRole('button',{name:'Send',exact:true}).click();await page.getByText('Confirmed public answer',{exact:true}).first().waitFor({timeout:3000});}};
}
try{
  browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
  for(const mode of ['blocked-read','blocked-write'])await scenario(mode,async()=>{
    const f=await visitor(mode);
    try{await f.send('Public question with '+mode);check(f.errors.length===0,mode+' does not abort the public chat runtime');check(threads.get(mode).receipts.size===1,mode+' saves one visitor turn and displays its answer');check(await f.page.getByRole('button',{name:'Send',exact:true}).isEnabled(),mode+' does not turn a confirmed send into an uncertain retry');}finally{await f.context.close();}
  });
  await scenario('back-forward restore',async()=>{
    const f=await visitor('restore');
    try{
      await f.send('Before leaving the profile');await f.page.locator('textarea').first().fill('Unsent draft for this visitor');const before=await f.page.evaluate(()=>window.__documentSequence);
      await f.page.evaluate(()=>{window.dispatchEvent(new PageTransitionEvent('pagehide',{persisted:true}));window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}));});
      await f.page.waitForFunction(previous=>window.__documentSequence>previous,before,{timeout:3000});
      await f.page.waitForFunction(()=>document.querySelector('textarea')?.value==='Unsent draft for this visitor');
      check(await f.page.locator('textarea').first().inputValue()==='Unsent draft for this visitor','back-forward restore preserves the unsent draft in its exact visitor namespace');
      await f.send('After returning to the profile');
      check(threads.get('restore').receipts.size===2,'restored page revalidates the visitor binding and can send again');
      check(await f.page.getByText('Before leaving the profile',{exact:true}).count()===1,'restored page recovers the existing transcript without duplicates');
      check(f.errors.length===0,'restored visitor widget emits no runtime errors');
    }finally{await f.context.close();}
  });
  await scenario('startup recovery',async()=>{
    const f=await visitor('startup-recovery');
    try{
      await f.page.getByText('Temporary startup interruption',{exact:true}).waitFor();
      await f.page.evaluate(()=>window.dispatchEvent(new Event('online')));
      await f.page.getByText('Saved conversation recovered',{exact:true}).waitFor({timeout:3000});
      check(!(await f.page.locator('[data-profile-agent-status]').innerText()).includes('Temporary startup interruption'),'successful reconnect clears only the stale startup error');
      check(f.errors.length===0,'online recovery resumes the saved conversation without script errors');
    }finally{await f.context.close();}
  });
  await scenario('independent Inbox and takeover',async()=>{
    const page=await browser.newPage();
    try{
      await page.clock.install();
      await page.goto(base+'/portal');await page.locator('[data-open-conversation="101"]').waitFor({timeout:3000});
      check(stalledRadar.size>0,'Inbox loads while the independent Radar request is still stalled');
      await page.locator('[data-open-conversation="101"]').click();await page.locator('#paReplyForm').waitFor({timeout:3000});
      await page.getByRole('button',{name:'Take over',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-thread-identity]')?.textContent.includes('owner joined'));
      check(requests.some(r=>r.action==='conversation_status'&&r.conversation_id===101&&r.status==='owner_joined'),'Inbox takeover changes only the selected canonical conversation');
      await page.getByRole('button',{name:'Let Agent handle',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-thread-identity]')?.textContent.includes('open'));
      check(requests.some(r=>r.action==='conversation_status'&&r.conversation_id===101&&r.status==='open'),'Inbox delegation returns the same selected thread to the Agent');
      check(!requests.some(r=>r.action==='owner_reply'),'changing responder does not send an invented owner message');
      await page.locator('#paReplyForm input').fill('Unsent owner reply');
      let hold=true,arrived;const stalled=new Promise(resolve=>arrived=resolve);
      await page.route('**/api/profile-agent.php?action=owner_state',route=>{if(hold){arrived();return;}return route.continue();});
      await page.locator('#profileAgentRefresh').click();await stalled;
      await page.clock.fastForward(16000);
      await page.waitForFunction(()=>document.getElementById('profileAgentNotice').textContent.includes('timed out'));
      check(await page.locator('#paReplyForm input').inputValue()==='Unsent owner reply','timed out owner refresh preserves the exact thread draft');
      check((await page.locator('#profileAgentRadar').innerText()).includes('timed out'),'stalled Radar reports its own timeout without clearing the Inbox');
      hold=false;await page.locator('#profileAgentRefresh').click();
      await page.waitForFunction(()=>document.getElementById('profileAgentNotice').textContent==='Profile Agent workspace refreshed.');
      check(await page.locator('#paReplyForm input').inputValue()==='Unsent owner reply','timed out request releases the Inbox for a successful retry');
    }finally{await page.close();}
  });
  assert.equal(failures.length,0,failures.join('\n'));
  console.log(`PROFILE_CHAT_RECOVERY_BROWSER_V1=${checks} assertions PASS`);
}finally{
  if(browser)await browser.close();for(const response of stalledRadar)response.destroy();server.closeAllConnections();await new Promise(resolve=>server.close(resolve));
}
