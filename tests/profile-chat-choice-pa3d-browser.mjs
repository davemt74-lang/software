import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {execFileSync} from 'node:child_process';
import {createRequire} from 'node:module';
import {chatChoice} from './fixtures/profile-chat-choice-pa3d.mjs';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const read=name=>fs.readFileSync(new URL('../'+name,import.meta.url),'utf8');
const namespace='a'.repeat(64);
const photo=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLbtAAAAABJRU5ErkJggg==','base64');
const profile=read('profile.php');
const widgetSource=profile.match(/<div class="profile-agent-widget-root"[\s\S]*?(?=\n<\?php endif; \?>\n<script>)/)?.[0];
const launcher=profile.match(/const profileAgentShell=.*?(?=const webmcpResumeBox=)/s)?.[0];
assert.ok(widgetSource&&launcher,'browser test uses the production public widget and launcher');
function renderWidget(preview){
 return execFileSync('php',['-r',`
  $agent=['display_name'=>'Profile Guide'];$displayName='Dave';$profileOrbShape='orbit';$agentGreeting='Hello from the Profile Agent.';
  $isOwner=$chatPreview=${preview?'true':'false'};
  function e($value){return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');}function url($value){return $value;}
  eval('?>'.stream_get_contents(STDIN));
 `],{input:widgetSource,encoding:'utf8'});
}
const widgets={visitor:renderWidget(false),owner:renderWidget(true)};
const assets=new Map(['profile-agent.js','profile.css','profile-calls-pa3c.js','profile-calls-pa3c.css','profile-speech-pa4a.css'].map(name=>['/'+name,read(name)]));
const server=http.createServer((request,response)=>{
 const path=new URL(request.url,'http://localhost');
 if(assets.has(path.pathname)){response.writeHead(200,{'Content-Type':path.pathname.endsWith('.css')?'text/css':'text/javascript'});response.end(assets.get(path.pathname));return;}
 response.writeHead(200,{'Content-Type':'text/html'});
 const preview=path.searchParams.has('preview'),speechEndpoint=path.searchParams.has('speech')?'/api/profile-speech-pa4a.php':undefined;
 const config={username:'fixture-owner',ownerName:'Dave',endpoint:'/api/profile-agent.php',conversationNamespace:namespace,profileToken:'FIXTURE_PROFILE_TOKEN',speechEndpoint,callEndpoint:'/api/profile-calls-pa3c.php'};
 response.end(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/profile.css"><link rel="stylesheet" href="/profile-calls-pa3c.css"><link rel="stylesheet" href="/profile-speech-pa4a.css"><style>#chatInput{display:none}</style><body class="profile-page">${preview?widgets.owner:widgets.visitor}<textarea id="chatInput">PRIVATE_OWNER_DRAFT</textarea><script>window.STONEFELLOW_PROFILE_AGENT=${JSON.stringify(config)};${launcher}</script><script src="/profile-calls-pa3c.js"></script><script src="/profile-agent.js"></script>`);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
let browser,checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;console.log('PASS '+label);};
async function fixture({available=true,ownerName='Dave',loseFirst=false,staleSession=false,preview=false,viewport={width:390,height:740}}={}){
 const context=await browser.newContext({viewport}),page=await context.newPage();
 const requests=[],messages=[],saved=new Map(),errors=[];
 const state={chat:chatChoice({live_text_available:available,owner_name:ownerName}),handoff:null,conversation:null};let id=100,turn=500;
 page.on('pageerror',e=>errors.push(e.message));
 const snapshot=(after=0)=>({agent:{name:'Profile Guide',greeting:''},chat:state.chat,conversation:state.conversation,handoff:state.handoff&&{...state.handoff,server_now_ms:Date.now()},messages:messages.filter(m=>m.id>Number(after)),attachment_max_bytes:2097152});
 await page.route('**/api/profile-calls-pa3c.php*',route=>route.fulfill({status:200,json:{ok:true,contract:'vp3.profile-call.v1',calls:[],can_offer:available,reason:''}}));
 if(staleSession)await page.route('**/api/profile-speech-pa4a.php*',async route=>{
  const request=route.request(),body=request.method()==='GET'?Object.fromEntries(new URL(request.url()).searchParams):request.postDataJSON();requests.push({method:request.method(),body});
  const current=body.conversation_namespace==='d'.repeat(64);
  return route.fulfill({status:current?200:409,json:current?{ok:true,contract:'vp3.profile-speech.v1',ready:true,revision:body.revision?body.revision+1:1,presets:[],preset_key:null,style:body.style||'preset'}:{ok:false,code:'PROFILE_SESSION_CHANGED'}});
 });
 await page.route('**/api/profile-agent.php*',async route=>{
  const request=route.request(),contentType=request.headers()['content-type']||'';
  let body,attachment;
  if(contentType.startsWith('multipart/form-data')){
   const form=await new Request(request.url(),{method:'POST',headers:{'Content-Type':contentType},body:request.postDataBuffer()}).formData();
   body=JSON.parse(form.get('attachment_payload'));
   const file=form.get('profile_attachment');attachment={name:file.name,mime:file.type,bytes:file.size};
  }else body=request.method()==='GET'?Object.fromEntries(new URL(request.url()).searchParams):request.postDataJSON();
  requests.push({method:request.method(),body,attachment});
  const reply=(data,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify({ok:status<400,...data})});
  if(staleSession&&body.action==='visitor_session')return reply({conversation_namespace:'d'.repeat(64),profile_token:'e'.repeat(48)});
  if(staleSession&&body.conversation_namespace!=='d'.repeat(64))return reply({code:'PROFILE_SESSION_CHANGED'},409);
  if(body.action==='state'||body.action==='poll')return reply(snapshot(body.after_id||0));
  if(body.action==='attachment')return route.fulfill({status:200,contentType:body.preview==='1'?'image/png':'application/octet-stream',headers:{'Content-Disposition':body.preview==='1'?'inline':'attachment; filename="photo.png"'},body:photo});
  if(body.action==='message'){
   let receipt=saved.get(body.client_request_id);
   if(!receipt){
    const live=body.chat_mode==='live',accepted=live&&body.continue_live===true&&state.chat.live_status==='accepted';
    const waiting=live&&!accepted&&state.chat.live_text_available;
    const phase=accepted?'owner_claimed':waiting?'awaiting_owner':'agent_ready';
    state.chat=chatChoice({mode:live?'live':'agent',owner_name:ownerName,live_text_available:state.chat.live_text_available,live_status:accepted?'accepted':waiting?'waiting':live?'unavailable':'none',can_end_live_chat:accepted||waiting});
    state.conversation={conversation_id:31,status:accepted?'owner_joined':'open'};
    state.handoff={turn_id:++turn,revision:1,conversation_id:31,state:phase,dispatch_turn_id:turn,dispatch_state:phase,pickup_deadline_ms:waiting?Date.now()+15000:null};
    messages.push({id:++id,sender_type:'visitor',message:body.message,...(attachment?{attachment}:{})});
    receipt={accepted:true,conversation_id:31,visitor_message_id:id,client_request_id:body.client_request_id,...(attachment?{attachment}:{})};saved.set(body.client_request_id,receipt);
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
 await page.goto(`http://127.0.0.1:${server.address().port}/fixture-owner${preview?'?preview=1':staleSession?'?speech=1':''}`);
 const launch=page.getByRole('button',{name:'Ask Profile Guide'}),settings=page.getByRole('button',{name:'Chat settings'}),presence=page.locator('[data-profile-agent-presence]');
 const checkbox=page.locator('[data-profile-live-chat]'),end=page.locator('[data-profile-end-live]'),status=page.locator('[data-profile-agent-status]'),textarea=page.locator('.profile-agent-compose textarea');
 await launch.click();
 return {page,requests,saved,state,launch,settings,presence,checkbox,end,status,textarea,
  async openSettings(){if(await settings.getAttribute('aria-expanded')==='false')await settings.click();},
  accept(){state.chat={...state.chat,live_status:'accepted',can_end_live_chat:true};state.conversation.status='owner_joined';state.handoff={...state.handoff,state:'owner_claimed',dispatch_state:'owner_claimed',pickup_deadline_ms:null,revision:state.handoff.revision+1};messages.push({id:++id,sender_type:'owner',message:'Direct owner answer'});},
  async send(value){await textarea.fill(value);await page.getByRole('button',{name:/^(Send|Retry message)$/}).click();},
  async close(){check(!errors.length,'actual widget emitted no script errors'+(errors.length?': '+errors.join('; '):''));await context.close();}
 };
}
try{
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
 {
  const f=await fixture();await f.page.waitForFunction(()=>!document.querySelector('[data-profile-live-chat]').disabled);
  check(!await f.checkbox.isChecked()&&!(await f.checkbox.isVisible())&&(await f.presence.textContent())==='Dave online for live chat','available mobile widget defaults to Agent chat and keeps permissions in Settings');
  const controls=f.page.getByRole('group',{name:'Chat actions'});
  check(await controls.getByRole('button').count()===5&&await controls.getByRole('button',{name:'Add image'}).isDisabled()&&await controls.getByRole('button',{name:'Add file'}).isDisabled(),'composer shows all five actions and reserves attachments for an authorized human thread');
  const chat=f.page.getByRole('button',{name:'Chat options'});
  await chat.click();
  const audio=f.page.getByRole('button',{name:'Request audio call'});
  await audio.waitFor();await f.page.waitForFunction(()=>!document.querySelector('[data-call-offer="audio"]').disabled);
  check(await chat.getAttribute('aria-expanded')==='true'&&!(await f.page.getByRole('log').isVisible()),'Chat opens call controls in the same scroll region as messages');
  await f.page.getByRole('button',{name:'Live text chat',exact:true}).click();
  check(await f.checkbox.isVisible()&&await f.settings.getAttribute('aria-expanded')==='true'&&await f.checkbox.evaluate(node=>document.activeElement===node),'live chat shortcut opens and focuses the Settings checkbox');
  await f.page.keyboard.press('Escape');
  check(await f.page.getByRole('log').isVisible()&&await f.settings.evaluate(node=>document.activeElement===node),'Escape returns to the thread and restores footer focus');
  await f.send('Ask the Agent');await f.page.getByText('Browser Agent response',{exact:true}).waitFor();
  check(f.requests.find(r=>r.body.action==='message')?.body.chat_mode==='agent','default real widget submission explicitly chooses Agent chat');
  check(f.requests.some(r=>r.body.action==='dispatch'&&r.method==='POST'),'Agent-only response starts through the canonical dispatch action');await f.close();
 }
 {
  const f=await fixture({staleSession:true});await f.page.waitForFunction(()=>!document.querySelector('[data-profile-live-chat]').disabled);
  const recovery=f.requests.find(request=>request.body.action==='visitor_session');
  check(recovery?.method==='GET'&&Object.keys(recovery.body).sort().join(',')==='action,username','an empty stale page recovers with a read that carries no previous session or conversation');
  await f.openSettings();
  const voices=f.page.getByRole('combobox');
  await voices.selectOption('male');
  await f.page.waitForFunction(()=>!document.querySelector('#profileVisitorSpeechPreference').disabled);
  const preference=f.requests.find(request=>request.body.action==='visitor_preference');
  check(preference?.body.style==='male'&&preference.body.profile_token==='e'.repeat(48)&&f.requests.filter(request=>request.body.action==='visitor_state').every(request=>request.body.conversation_namespace==='d'.repeat(64)),'voice reads and selection use the recovered binding without racing stale startup state');
  await f.checkbox.check();await f.send('Explicit message after recovery');await f.openSettings();await f.page.getByRole('button',{name:'Cancel live request'}).waitFor();
  const sent=f.requests.find(request=>request.body.action==='message');
  check(sent.body.conversation_namespace==='d'.repeat(64)&&sent.body.profile_token==='e'.repeat(48)&&sent.body.conversation_id===0&&sent.body.chat_mode==='live','live checkbox works after recovery and sends under the new binding only after explicit submit');await f.close();
 }
 {
  const name='Dave <img src=x onerror=window.__ownerExecuted=true>',f=await fixture({ownerName:name});await f.page.waitForFunction(()=>!document.querySelector('[data-profile-live-chat]').disabled);
  check(await f.page.locator('[data-profile-owner-name]').textContent()===name&&await f.page.locator('[data-profile-owner-name] img').count()===0,'owner name is rendered as inert text in the real checkbox label');
  await f.openSettings();
  await f.checkbox.check();check(!f.requests.some(r=>r.method==='POST'),'checking live choice performs no mutation');
  check((await f.presence.textContent()).includes('Next message requests'),'header reflects the selected live intent without adding a notice section');
  await f.send('May I speak to the owner?');await f.openSettings();await f.page.getByRole('button',{name:'Cancel live request'}).waitFor();
  const first=f.requests.find(r=>r.body.action==='message');check(first.body.chat_mode==='live'&&!Object.hasOwn(first.body,'continue_live'),'first live request is explicit and has no continuation marker');
  check(await f.checkbox.isChecked()&&await f.checkbox.isDisabled()&&!f.requests.some(r=>r.body.action==='dispatch'),'pending owner pickup locks the selected mode and suppresses Agent dispatch');
  await f.settings.click();f.accept();await f.page.getByText('Direct owner answer',{exact:true}).waitFor();
  check((await f.presence.textContent()).startsWith('Live with')&&(await f.status.getAttribute('data-quiet'))==='true'&&(await f.page.locator('[data-profile-agent-disclosure]').textContent()).includes('Profile Agent is paused'),'accepted owner chat is identified in the header and retains accessibility information');
  check(await f.page.locator('.profile-agent-message.owner .profile-agent-message-speaker').textContent()===name&&!await f.page.evaluate(()=>window.__ownerExecuted),'real owner attribution is inert');
  await f.send('An accepted live follow-up');await f.page.getByText('An accepted live follow-up',{exact:true}).waitFor();
  await f.page.waitForFunction(()=>!document.querySelector('[data-profile-end-live]').disabled);
  const continuation=f.requests.filter(r=>r.body.action==='message').at(-1);check(continuation.body.chat_mode==='live'&&continuation.body.continue_live===true&&continuation.body.conversation_id===31,'accepted follow-up continues the same live chat without a new request');
  await f.textarea.fill('Keep my mobile draft');const expected={turn_id:f.state.handoff.turn_id,revision:f.state.handoff.revision};await f.openSettings();await f.end.click();await f.end.waitFor({state:'hidden'});
  const ending=f.requests.find(r=>r.body.action==='end_live_chat');check(ending?.method==='POST'&&ending.body.conversation_id===31&&ending.body.turn_id===expected.turn_id&&ending.body.revision===expected.revision,'End posts the exact displayed conversation and revision');
  check(await f.textarea.inputValue()==='Keep my mobile draft'&&!(await f.checkbox.isChecked())&&await f.page.getByText('Direct owner answer',{exact:true}).count()===1,'End preserves the unsent draft and same transcript');
  check(await f.page.evaluate(()=>{const shell=document.querySelector('#profileAgentShell'),choice=document.querySelector('[data-profile-live-choice]');return document.documentElement.scrollWidth<=innerWidth&&shell.scrollWidth<=shell.clientWidth&&choice.scrollWidth<=choice.clientWidth;}),'widget, page, and choice fit a 390px mobile viewport without horizontal overflow');
  check(await f.page.locator('#chatInput').inputValue()==='PRIVATE_OWNER_DRAFT'&&!JSON.stringify(f.requests).includes('PRIVATE_OWNER_DRAFT'),'public widget never transmits the private composer');await f.close();
 }
 {
  const f=await fixture();await f.openSettings();await f.checkbox.check();await f.send('Can I send you an image?');
  await f.openSettings();await f.page.getByRole('button',{name:'Cancel live request'}).waitFor();
  const imageButton=f.page.getByRole('button',{name:'Add image'});
  const fileButton=f.page.getByRole('button',{name:'Add file'});
  check(await imageButton.isDisabled()&&await fileButton.isDisabled(),'attachments stay unavailable while the owner is deciding');
  await f.settings.click();f.accept();await f.page.getByText('Direct owner answer',{exact:true}).waitFor();
  check(await imageButton.isEnabled()&&await fileButton.isEnabled(),'both attachment controls enable in the accepted owner chat');
  const choose=f.page.waitForEvent('filechooser');await imageButton.click();
  await (await choose).setFiles({name:'photo.png',mimeType:'image/png',buffer:photo});
  check(await f.page.getByRole('button',{name:'Remove photo.png'}).isVisible()&&!f.requests.some(r=>r.attachment),'selecting the image stages it locally until Send');
  await f.send('');
  const image=f.page.getByRole('link',{name:'Download photo.png'});
  await image.waitFor();
  const sent=f.requests.find(r=>r.attachment);
  check(sent?.attachment.bytes===photo.length&&sent?.body.continue_live===true&&sent.body.message===''&&sent.body.conversation_id===31,'Send submits one image-only multipart message to the accepted conversation');
  await f.page.waitForFunction(()=>document.querySelector('.profile-agent-message-attachment img')?.naturalWidth===1);
  const downloaded=f.page.waitForEvent('download');await image.click();
  const filename=(await downloaded).suggestedFilename();
  check(filename==='photo.png'&&f.requests.some(r=>r.body.action==='attachment'&&r.body.preview==='1'&&r.body.conversation_id==='31'),'the image previews and downloads from the conversation-bound endpoint (filename '+filename+')');
  await f.close();
 }
 {
  const f=await fixture({available:false});await f.page.waitForFunction(()=>document.querySelector('[data-profile-agent-presence]').dataset.state==='offline');
  check(await f.checkbox.isDisabled()&&!(await f.checkbox.isChecked()),'offline owner disables live choice on mobile');await f.send('Continue with Agent');await f.page.getByText('Browser Agent response',{exact:true}).waitFor();
  check(f.requests.find(r=>r.body.action==='message')?.body.chat_mode==='agent','offline status still permits regular Agent messages');await f.close();
 }
 {
  const f=await fixture({loseFirst:true});await f.page.waitForFunction(()=>!document.querySelector('[data-profile-live-chat]').disabled);await f.openSettings();await f.checkbox.check();await f.send('Restore my saved live request');await f.page.getByRole('button',{name:'Retry message'}).waitFor();
  check(await f.page.getByRole('button',{name:'Retry message'}).locator('svg').count()===1,'retry changes the accessible label while keeping the send icon');
  f.state.chat.live_text_available=false;await f.page.reload();await f.launch.click();await f.openSettings();await f.page.getByRole('button',{name:'Cancel live request'}).waitFor();await f.page.waitForFunction(()=>!document.querySelector('[data-profile-end-live]').disabled);
  const sends=f.requests.filter(r=>r.body.action==='message');check(sends.length===2&&sends[0].body.chat_mode==='live'&&sends[1].body.chat_mode==='live'&&sends[0].body.client_request_id===sends[1].body.client_request_id&&f.saved.size===1,'real reload retries one exact live intent even after availability changes');await f.close();
 }
 {
  const f=await fixture({preview:true,viewport:{width:1180,height:820}});
  check((await f.presence.textContent())==='Owner preview'&&await f.page.getByText('Hello from the Profile Agent.').isVisible()&&await f.textarea.isDisabled(),'owner preview shows the greeting with an honest status and no visitor composer');
  await f.openSettings();
  check(await f.page.getByRole('link',{name:'Manage Profile Agent'}).getAttribute('href')==='/profile-agent.php'&&f.requests.length===0,'owner preview can open its settings link without initiating visitor requests');
  await f.close();
 }
 console.log(`PROFILE_CHAT_CHOICE_PA3D_BROWSER=PASS ${checks} checks`);
}finally{await browser?.close();await new Promise(resolve=>server.close(resolve));}
