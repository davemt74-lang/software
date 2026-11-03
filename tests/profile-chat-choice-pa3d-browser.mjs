import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
import {chatChoice,choiceMarkup} from './fixtures/profile-chat-choice-pa3d.mjs';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const read=name=>fs.readFileSync(new URL('../'+name,import.meta.url),'utf8');
const namespace='a'.repeat(64);
const server=http.createServer((request,response)=>{
 const asset={'/profile-agent.js':['text/javascript',read('profile-agent.js')],'/profile.css':['text/css',read('profile.css')]}[request.url];
 if(asset){response.writeHead(200,{'Content-Type':asset[0]});response.end(asset[1]);return;}
 response.writeHead(200,{'Content-Type':'text/html'});
 response.end(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/profile.css"><style>body{padding:10px}#chatInput{display:none}</style><body class="profile-page">${choiceMarkup}<script>window.STONEFELLOW_PROFILE_AGENT=${JSON.stringify({username:'fixture-owner',ownerName:'Dave',endpoint:'/api/profile-agent.php',conversationNamespace:namespace,profileToken:'FIXTURE_PROFILE_TOKEN'})}</script><script src="/profile-agent.js"></script>`);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
let browser,checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;console.log('PASS '+label);};
async function fixture({available=true,ownerName='Dave',loseFirst=false}={}){
 const context=await browser.newContext({viewport:{width:390,height:740}}),page=await context.newPage();
 const requests=[],messages=[],saved=new Map(),errors=[];
 const state={chat:chatChoice({live_text_available:available,owner_name:ownerName}),handoff:null,conversation:null};let id=100,turn=500;
 page.on('pageerror',e=>errors.push(e.message));
 const snapshot=(after=0)=>({agent:{name:'Profile Guide',greeting:''},chat:state.chat,conversation:state.conversation,handoff:state.handoff&&{...state.handoff,server_now_ms:Date.now()},messages:messages.filter(m=>m.id>Number(after))});
 await page.route('**/api/profile-agent.php*',async route=>{
  const request=route.request(),body=request.method()==='GET'?Object.fromEntries(new URL(request.url()).searchParams):request.postDataJSON();requests.push({method:request.method(),body});
  const reply=(data,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify({ok:status<400,...data})});
  if(body.action==='state'||body.action==='poll')return reply(snapshot(body.after_id||0));
  if(body.action==='message'){
   let receipt=saved.get(body.client_request_id);
   if(!receipt){
    const live=body.chat_mode==='live',accepted=live&&body.continue_live===true&&state.chat.live_status==='accepted';
    const waiting=live&&!accepted&&state.chat.live_text_available;
    const phase=accepted?'owner_claimed':waiting?'awaiting_owner':'agent_ready';
    state.chat=chatChoice({mode:live?'live':'agent',owner_name:ownerName,live_text_available:state.chat.live_text_available,live_status:accepted?'accepted':waiting?'waiting':live?'unavailable':'none',can_end_live_chat:accepted||waiting});
    state.conversation={conversation_id:31,status:accepted?'owner_joined':'open'};
    state.handoff={turn_id:++turn,revision:1,conversation_id:31,state:phase,dispatch_turn_id:turn,dispatch_state:phase,pickup_deadline_ms:waiting?Date.now()+15000:null};
    messages.push({id:++id,sender_type:'visitor',message:body.message});
    receipt={accepted:true,conversation_id:31,visitor_message_id:id,client_request_id:body.client_request_id};saved.set(body.client_request_id,receipt);
   }
   if(loseFirst){loseFirst=false;return route.abort('failed');}
   return reply({...snapshot(),...receipt});
  }
  if(body.action==='dispatch'){
   messages.push({id:++id,sender_type:'agent',message:'Browser Agent response'});state.handoff={...state.handoff,state:'replied',dispatch_state:'none',revision:state.handoff.revision+1};
   return reply({conversation_id:31,turn_id:body.turn_id,dispatch_checked:true});
  }
  if(body.action==='end_live_chat'){
   if(body.conversation_id!==31||body.turn_id!==state.handoff.turn_id||body.revision!==state.handoff.revision)return reply({error:'Live chat changed'},409);
   state.chat={...state.chat,mode:'agent',live_status:'ended',can_end_live_chat:false};state.conversation={conversation_id:31,status:'open'};
   state.handoff={...state.handoff,state:'replied',dispatch_state:'none',revision:state.handoff.revision+1};return reply(snapshot());
  }
  return reply({error:'Unexpected request'},422);
 });
 await page.goto(`http://127.0.0.1:${server.address().port}/fixture-owner`);
 const checkbox=page.locator('[data-profile-live-chat]'),end=page.locator('[data-profile-end-live]'),status=page.locator('[data-profile-agent-status]'),availability=page.locator('[data-profile-live-availability]'),textarea=page.getByRole('textbox',{name:'Visitor message'});
 return {page,requests,saved,state,checkbox,end,status,availability,textarea,
  accept(){state.chat={...state.chat,live_status:'accepted',can_end_live_chat:true};state.conversation.status='owner_joined';state.handoff={...state.handoff,state:'owner_claimed',dispatch_state:'owner_claimed',pickup_deadline_ms:null,revision:state.handoff.revision+1};messages.push({id:++id,sender_type:'owner',message:'Direct owner answer'});},
  async send(value){await textarea.fill(value);await page.getByRole('button',{name:/^(Send|Retry message)$/}).click();},
  async close(){check(!errors.length,'actual widget emitted no script errors'+(errors.length?': '+errors.join('; '):''));await context.close();}
 };
}
try{
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
 {
  const f=await fixture();await f.page.waitForFunction(()=>!document.querySelector('[data-profile-live-chat]').disabled);
  check(!await f.checkbox.isChecked(),'available mobile widget defaults to Agent chat');
  await f.send('Ask the Agent');await f.page.getByText('Browser Agent response',{exact:true}).waitFor();
  check(f.requests.find(r=>r.body.action==='message')?.body.chat_mode==='agent','default real widget submission explicitly chooses Agent chat');
  check(f.requests.some(r=>r.body.action==='dispatch'&&r.method==='POST'),'Agent-only response starts through the canonical dispatch action');await f.close();
 }
 {
  const name='Dave <img src=x onerror=window.__ownerExecuted=true>',f=await fixture({ownerName:name});await f.page.waitForFunction(()=>!document.querySelector('[data-profile-live-chat]').disabled);
  check(await f.page.locator('[data-profile-owner-name]').textContent()===name&&await f.page.locator('[data-profile-owner-name] img').count()===0,'owner name is rendered as inert text in the real checkbox label');
  await f.checkbox.check();check(!f.requests.some(r=>r.method==='POST'),'checking live choice performs no mutation');
  await f.send('May I speak to the owner?');await f.page.getByRole('button',{name:'Cancel live request'}).waitFor();
  const first=f.requests.find(r=>r.body.action==='message');check(first.body.chat_mode==='live'&&!Object.hasOwn(first.body,'continue_live'),'first live request is explicit and has no continuation marker');
  check(await f.checkbox.isChecked()&&await f.checkbox.isDisabled()&&!f.requests.some(r=>r.body.action==='dispatch'),'pending owner pickup locks the selected mode and suppresses Agent dispatch');
  f.accept();await f.page.getByText('Direct owner answer',{exact:true}).waitFor();
  check((await f.status.textContent()).includes('chatting live with')&&(await f.page.locator('[data-profile-agent-disclosure]').textContent()).includes('Profile Agent is paused'),'accepted owner chat is identified and the Agent is paused');
  check(await f.page.locator('.profile-agent-message.owner .profile-agent-message-speaker').textContent()===name&&!await f.page.evaluate(()=>window.__ownerExecuted),'real owner attribution is inert');
  await f.send('An accepted live follow-up');await f.page.getByText('An accepted live follow-up',{exact:true}).waitFor();
  await f.page.waitForFunction(()=>!document.querySelector('[data-profile-end-live]').disabled);
  const continuation=f.requests.filter(r=>r.body.action==='message').at(-1);check(continuation.body.chat_mode==='live'&&continuation.body.continue_live===true&&continuation.body.conversation_id===31,'accepted follow-up continues the same live chat without a new request');
  await f.textarea.fill('Keep my mobile draft');const expected={turn_id:f.state.handoff.turn_id,revision:f.state.handoff.revision};await f.end.click();await f.end.waitFor({state:'hidden'});
  const ending=f.requests.find(r=>r.body.action==='end_live_chat');check(ending?.method==='POST'&&ending.body.conversation_id===31&&ending.body.turn_id===expected.turn_id&&ending.body.revision===expected.revision,'End posts the exact displayed conversation and revision');
  check(await f.textarea.inputValue()==='Keep my mobile draft'&&!(await f.checkbox.isChecked())&&await f.page.getByText('Direct owner answer',{exact:true}).count()===1,'End preserves the unsent draft and same transcript');
  check(await f.page.evaluate(()=>{const shell=document.querySelector('#profileAgentShell'),choice=document.querySelector('[data-profile-live-choice]');return document.documentElement.scrollWidth<=innerWidth&&shell.scrollWidth<=shell.clientWidth&&choice.scrollWidth<=choice.clientWidth;}),'widget, page, and choice fit a 390px mobile viewport without horizontal overflow');
  check(await f.page.locator('#chatInput').inputValue()==='PRIVATE_OWNER_DRAFT'&&!JSON.stringify(f.requests).includes('PRIVATE_OWNER_DRAFT'),'public widget never transmits the private composer');await f.close();
 }
 {
  const f=await fixture({available:false});await f.page.getByText(/unavailable for live chat/).waitFor();
  check(await f.checkbox.isDisabled()&&!(await f.checkbox.isChecked()),'offline owner disables live choice on mobile');await f.send('Continue with Agent');await f.page.getByText('Browser Agent response',{exact:true}).waitFor();
  check(f.requests.find(r=>r.body.action==='message')?.body.chat_mode==='agent','offline status still permits regular Agent messages');await f.close();
 }
 {
  const f=await fixture({loseFirst:true});await f.page.waitForFunction(()=>!document.querySelector('[data-profile-live-chat]').disabled);await f.checkbox.check();await f.send('Restore my saved live request');await f.page.getByRole('button',{name:'Retry message'}).waitFor();
  f.state.chat.live_text_available=false;await f.page.reload();await f.page.getByRole('button',{name:'Cancel live request'}).waitFor();await f.page.waitForFunction(()=>!document.querySelector('[data-profile-end-live]').disabled);
  const sends=f.requests.filter(r=>r.body.action==='message');check(sends.length===2&&sends[0].body.chat_mode==='live'&&sends[1].body.chat_mode==='live'&&sends[0].body.client_request_id===sends[1].body.client_request_id&&f.saved.size===1,'real reload retries one exact live intent even after availability changes');await f.close();
 }
 console.log(`PROFILE_CHAT_CHOICE_PA3D_BROWSER=PASS ${checks} checks`);
}finally{await browser?.close();await new Promise(resolve=>server.close(resolve));}
