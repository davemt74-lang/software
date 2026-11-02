import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
const {chromium}=await import(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const chat=fs.readFileSync('chat.js','utf8'),a=chat.indexOf('  function agentActionHtml(action)'),b=chat.indexOf('  function messageElement(',a);
const escape=s=>String(s).replace(/[&<>"']/g,x=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[x]));
const render=new Function('escapeHtml','withStudioReturn',chat.slice(a,b)+';return agentActionHtml;')(escape,x=>x);
const tokens=['a','b','c','d','e'].map(x=>x.repeat(32)),requests=[],saved=new Map();let holdReview=false,holdConfirm=false,releaseReview,releaseConfirm;
const card=t=>render({type:'participant_setup',token:t,conversation_id:10});
assert.equal(render({type:'participant_setup',token:'bad" onclick="alert(1)',conversation_id:10}),'');
const html=`<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/style"><section class="message assistant"><div class="message-body">${tokens.map(t=>card(t)).join('')}</div></section><form id="chatForm"><textarea id="chatInput"></textarea></form><script>
window.cid=10;window.synced=[];window.reloads=0;window.selections=[];window.prompts=[];
window.VP3_ORB_CONFIG={participantUpdatesEndpoint:'/api/participant',csrf:'fixture'};
window.VP3_ORB_ADAPTER={conversationRef:()=>({id:cid,agent:7}),participantDetails:async id=>({id:Number(id),name:'Canonical Alex',note:'Chosen conversation note'})};
window.STONEFELLOW_CHAT_CONTINUITY_V87={conversationId:()=>cid,syncConversation:async id=>synced.push(id)};
window.VP3ParticipantUpdates={reload:async()=>reloads++};window.VP3ParticipantContinuity={setSelection:p=>selections.push(p)};
document.querySelector('#chatForm').addEventListener('submit',e=>{e.preventDefault();prompts.push(document.querySelector('#chatInput').value);});
</script><script src="/script"></script>`;
const profiles=[{id:7,name:'David',recognition_consent:true},{id:8,name:'Alex <img src=x onerror="window.bad=true">',recognition_consent:true}];
const server=http.createServer(async(req,res)=>{
 if(req.url==='/api/participant'){
  let raw='';for await(const chunk of req)raw+=chunk;const body=JSON.parse(raw);requests.push(body);
  res.setHeader('Content-Type','application/json');
  const share=body.token===tokens[1]||body.token===tokens[4];
  if(body.action==='review_setup'){
   if(holdReview&&body.token===tokens[3])await new Promise(r=>releaseReview=r);
   if(saved.has(body.token)){res.end(JSON.stringify({ok:true,review:{status:'completed',result:saved.get(body.token)}}));return;}
   res.end(JSON.stringify({ok:true,review:{status:'prepared',kind:share?'share_list':'link_self',participant_id:share?8:body.token===tokens[2]?0:7,list_id:share?601:0,access_modes:['read','edit'],profiles,lists:[{id:601,name:'Prep'}],revision:'review-v1',enabled:true}}));return;
  }
  if(holdConfirm&&body.token===tokens[4])await new Promise(r=>releaseConfirm=r);
  const result={kind:share?'share_list':'link_self',decision:body.decision,participant_id:body.participant_id,participant_name:share?'Canonical Alex':'David',answer:body.decision==='cancel'?'Participant setup cancelled.':share?'Shared Prep with Alex: can view only.':'Linked David to the signed-in account.',assistant_message_id:90,settings:{enabled:true}};saved.set(body.token,result);res.end(JSON.stringify({ok:true,result}));return;
 }
 if(req.url==='/script'){res.setHeader('Content-Type','text/javascript');res.end(fs.readFileSync('chat-participant-setup-v2.js'));return;}
 if(req.url==='/style'){res.setHeader('Content-Type','text/css');res.end(fs.readFileSync('participant-updates-v1.css'));return;}
 res.setHeader('Content-Type','text/html');res.end(html);
});await new Promise(r=>server.listen(0,'127.0.0.1',r));
const browser=await chromium.launch({headless:true});let checks=0;const check=async(name,fn)=>{await fn();checks++;console.log('PASS '+name);};
try{
 const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto(`http://127.0.0.1:${server.address().port}`);
 const open=async i=>{await page.locator(`[data-participant-setup-token="${tokens[i]}"]`).click();const panel=page.locator('.chat-participant-setup').last();await panel.getByLabel('Participant profile',{exact:true}).waitFor();return panel;};
 let panel=await open(0);
 await check('production Chat action opens an explicit owner review without sending confirmation',async()=>{assert.equal(requests.filter(x=>x.action==='confirm_setup').length,0);assert.equal(await panel.getByLabel('Participant profile',{exact:true}).inputValue(),'7');assert.equal(await panel.getByLabel('I am the signed-in account owner and approve this change').isChecked(),false);});
 await panel.getByRole('button',{name:'Confirm profile link',exact:true}).click();
 await check('profile link requires the owner checkbox',async()=>{assert.equal(requests.filter(x=>x.action==='confirm_setup').length,0);assert.match(await panel.textContent(),/Choose the participant/);});
 await panel.getByLabel('I am the signed-in account owner and approve this change').check();await panel.getByRole('button',{name:'Confirm profile link',exact:true}).click();await panel.getByRole('button',{name:'Show my open work',exact:true}).waitFor();
 await check('confirmation sends exact token, owner choice, Agent, conversation and CSRF',async()=>{const r=requests.find(x=>x.action==='confirm_setup');assert.equal(r.owner_confirmed,true);assert.equal(r.participant_id,7);assert.equal(r.token,tokens[0]);assert.equal(r.conversation_id,10);assert.equal(r.agent_id,7);assert.equal(r.csrf_token,'fixture');assert.equal(r.revision,'review-v1');assert.deepEqual(await page.evaluate(()=>synced),[10]);});
 await panel.getByRole('button',{name:'Show my open work',exact:true}).click();
 await check('next action submits the open work question through canonical Chat',async()=>assert.deepEqual(await page.evaluate(()=>prompts),['What do I need to do?']));
 panel=await open(1);
 await check('sharing has no implicit access mode and renders participant labels literally',async()=>{assert.equal(await panel.getByLabel('Shared list access').inputValue(),'');assert.equal(await panel.locator('img').count(),0);assert.equal(await page.evaluate(()=>window.bad===true),false);});
 await panel.getByLabel('I am the signed-in account owner and approve this change').check();await panel.getByRole('button',{name:'Confirm sharing',exact:true}).click();
 await check('owner checkbox alone cannot submit an unspecified sharing mode',async()=>assert.equal(requests.filter(x=>x.action==='confirm_setup').length,1));
 await panel.getByLabel('Shared list access').selectOption('read');await panel.getByRole('button',{name:'Confirm sharing',exact:true}).click();await panel.getByRole('button',{name:'Use Canonical Alex in typed Chat',exact:true}).waitFor();
 await check('sharing saves selected view access without automatically switching the speaker',async()=>{assert.equal(requests.filter(x=>x.action==='confirm_setup').at(-1).access_mode,'read');assert.deepEqual(await page.evaluate(()=>selections),[]);});
 await panel.getByRole('button',{name:'Use Canonical Alex in typed Chat',exact:true}).click();await page.waitForFunction(()=>selections.length===1);
 await check('optional typed participant selection uses fresh canonical details and no authentication authority',async()=>{const p=await page.evaluate(()=>selections[0]);assert.equal(p.display_name,'Canonical Alex');assert.equal(p.participant_id,'8');assert.equal(p.authentication_authority,false);assert.equal(p.source,'manual_selection');});
 panel=await open(2);await panel.getByRole('button',{name:'Cancel',exact:true}).click();await page.waitForFunction(()=>document.querySelectorAll('.chat-participant-setup')[2]?.textContent.includes('setup cancelled'));
 await check('cancel is available without approving the owner checkbox',async()=>assert.equal(requests.filter(x=>x.action==='confirm_setup').at(-1).decision,'cancel'));
 holdReview=true;await page.locator(`[data-participant-setup-token="${tokens[3]}"]`).click();await page.waitForTimeout(80);await page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:8}})));releaseReview?.();holdReview=false;await page.waitForTimeout(80);
 await check('participant change closes a delayed review and rejects its late response',async()=>{const last=page.locator('.chat-participant-setup').last();assert.equal(await last.locator('select').count(),0);assert.match(await last.textContent(),/Review closed/);});
 panel=await open(4);await panel.getByLabel('Shared list access').selectOption('read');await panel.getByLabel('I am the signed-in account owner and approve this change').check();holdConfirm=true;await panel.getByRole('button',{name:'Confirm sharing',exact:true}).click();await page.waitForTimeout(80);await page.evaluate(()=>{cid=11;window.dispatchEvent(new Event('stonefellow:chat-context-changing'));});releaseConfirm?.();holdConfirm=false;await page.waitForTimeout(80);
 await check('late confirmation receipt does not appear in a different conversation',async()=>{assert.match(await panel.textContent(),/Review closed/);assert.doesNotMatch(await panel.textContent(),/Shared Prep/);});
 const confirms=requests.filter(x=>x.action==='confirm_setup').length;await page.evaluate(()=>cid=10);await page.locator(`[data-participant-setup-token="${tokens[4]}"]`).click();await page.waitForFunction(()=>Array.from(document.querySelectorAll('.chat-participant-setup')).at(-1)?.textContent.includes('Shared Prep'));
 await check('reopening a saved card reads the committed receipt without submitting it again',async()=>assert.equal(requests.filter(x=>x.action==='confirm_setup').length,confirms));
 await page.setViewportSize({width:390,height:844});
 await check('review cards fit the mobile viewport',async()=>{assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));assert.deepEqual(errors,[]);});
 console.log(`CHAT_PARTICIPANT_SETUP_V2_BROWSER=PASS (${checks} cases; production Chat renderer, actual DOM, explicit owner choices, saved receipts, context races and mobile layout)`);
}finally{releaseReview?.();releaseConfirm?.();await browser.close();await new Promise(r=>server.close(r));}
