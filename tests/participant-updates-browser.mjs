import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
const {chromium}=await import(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const files=['participant-updates-v1.js','chat-task-notes-v1.js','agent-reminders.js'];
const html=`<!doctype html><meta charset="utf-8"><details id="outer" open><summary>Conversation participant</summary><div id="host"></div></details><button data-orb-stop>Stop</button><script>
window.calls=[];window.bubbles=[];window.noteReads=[];window.busy=false;window.offerReady=true;
window.state={settings:{enabled:false,offer_updates:false,self_participant_id:0,revision:0},profiles:[{id:701,display_name:'David'},{id:702,display_name:'Alex'}],catalog:{lists:[{id:601,title:'Prep',items:[{id:210,title:'Pizza <img src=x onerror="window.bad=true">',status:'open'}]}],tasks:[],reminders:[],bounded:true},grants:[],presence:[]};
window.VP3_ORB_CONFIG={participantUpdatesEnabled:false,ownerKey:'1',remindersEndpoint:'/reminders'};
window.VP3_TASK_NOTES_V1={endpoint:'/notes',userId:1,agentId:7,csrf:'fixture'};
window.VP3_AGENT_PREFERENCE_STATE={enabled:true};window.VP3AgentOrb={bubble:text=>bubbles.push(text)};
window.VP3_ORB_ADAPTER={busy:()=>busy,conversationRef:()=>({agent:7,id:1}),reminderRequest:async body=>{calls.push({reminder:body.action});return {ok:true,item:null};},participantUpdates:async body=>{
 calls.push(body);if(body.action==='settings')return structuredClone(state);
 if(body.action==='configure'){state.settings={enabled:body.enabled,offer_updates:body.offer_updates,self_participant_id:body.self_participant_id,revision:state.settings.revision+1};state.grants=body.grants.map(g=>({...g,participant_id:body.participant_id}));return {settings:state.settings};}
 if(body.action==='offer')return {offer:offerReady?{participant_id:body.participant_id,name:body.participant_id===701?'David':'Alex',text:'I can show your shared updates.',token:'a'.repeat(32),visit_id:'b'.repeat(32)}:null};
 if(body.action==='ack_offer'){offerReady=false;return {};}
 if(body.action==='show')return {updates:state.grants.length?structuredClone(state.catalog):{lists:[],tasks:[],reminders:[]}};
 return {};
}};
</script>${files.map(f=>`<script src="/${f}"></script>`).join('')}<script>VP3ParticipantUpdates.mount(document.getElementById('host'));</script>`;
const server=http.createServer((req,res)=>{
 if(req.url==='/notes'){let raw='';req.on('data',x=>raw+=x);req.on('end',()=>{const input=JSON.parse(raw);res.setHeader('Content-Type','application/json');res.end(JSON.stringify({ok:true,note:{kind:'task_list',id:input.note.id,title:input.note.participant_id?'Prep':'Owner note',items:[{id:210,title:'Pizza <img src=x onerror="window.bad=true">',status:'open',done:false}],read_only:!!input.note.participant_id}}));});return;}
 const name=req.url.slice(1);res.setHeader('Content-Type',files.includes(name)?'text/javascript':'text/html');res.end(files.includes(name)?fs.readFileSync(name):html);
});await new Promise(r=>server.listen(0,'127.0.0.1',r));
const browser=await chromium.launch({headless:true});let checks=0;
const check=async(name,fn)=>{await fn();checks++;console.log('PASS '+name);};
try{
 const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto(`http://127.0.0.1:${server.address().port}`);
 await page.waitForFunction(()=>document.querySelector('.vp3-participant-updates')?.textContent.includes('Participant-aware tasks are off'));
 const root=page.locator('.vp3-participant-updates');await root.locator('summary').click();
 await check('settings starts opt-in, explicit owner mapping and no silent sharing',async()=>{assert.equal(await root.getByLabel('Enable participant-aware voice tasks').isChecked(),false);assert.equal(await page.evaluate(()=>state.grants.length),0);});
 await page.evaluate(()=>VP3TaskNotes.open({kind:'task_list',id:999}));await page.waitForFunction(()=>document.querySelector('.vp3-task-note strong')?.textContent==='Owner note');
 await root.getByLabel('Enable participant-aware voice tasks').check();await root.getByLabel('Offer updates once per visit').check();await root.getByLabel('My recognized profile',{exact:true}).selectOption('701');await root.getByLabel('Share with participant',{exact:true}).selectOption('702');await root.locator('select[data-kind="task_list"]').selectOption('edit');await root.getByRole('button',{name:'Save participant sharing',exact:true}).click();
 await page.waitForFunction(()=>document.querySelector('.vp3-participant-updates')?.textContent.includes('Participant sharing saved'));
 await check('save sends only the explicit recipient and selected permissions',async()=>{const cfg=await page.evaluate(()=>calls.find(c=>c.action==='configure'));assert.equal(cfg.self_participant_id,701);assert.deepEqual(cfg.grants,[{kind:'task_list',id:601,access:'edit'}]);});
 await page.locator('#outer').evaluate(n=>n.open=false);
 await page.evaluate(()=>VP3ParticipantUpdates.heard({participant_id:702}));await page.waitForFunction(()=>calls.some(c=>c.action==='ack_offer'));
 await check('voice-only arrival offers generic update without speaking private titles',async()=>{const text=await page.evaluate(()=>bubbles.join(' '));assert.match(text,/Alex/);assert.doesNotMatch(text,/Pizza|Prep/);assert.equal(await page.locator('#outer').evaluate(n=>n.open),true);assert.equal(await page.locator('.vp3-task-note').count(),0);});
 await root.getByRole('button',{name:'Show my updates',exact:true}).click();await root.getByRole('button',{name:'Show list note',exact:true}).waitFor();
 await check('explicit update request renders literal canonical titles without HTML execution',async()=>{assert.match(await root.textContent(),/Pizza <img/);assert.equal(await root.locator('img').count(),0);assert.equal(await page.evaluate(()=>window.bad===true),false);});
 await root.getByRole('button',{name:'Show list note',exact:true}).click();await page.waitForFunction(()=>document.querySelector('.vp3-task-note input')?.disabled===true);
 await check('shared sticky notes are read only and remain draggable',async()=>{assert.equal(await page.locator('.vp3-task-note input').isDisabled(),true);assert.equal(await page.locator('.vp3-task-note header').getAttribute('tabindex'),'0');});
 await page.evaluate(()=>VP3ParticipantUpdates.heard({participant_id:701}));
 await check('speaker change clears another participant notes and update details',async()=>{assert.equal(await page.locator('.vp3-task-note').count(),0);assert.doesNotMatch(await root.textContent(),/Shared updates loaded/);});
 await check('owner reminder presentation pauses in participant-aware mode',async()=>{const before=await page.evaluate(()=>calls.filter(c=>c.reminder).length);await page.evaluate(()=>VP3AgentReminders.poll());assert.equal(await page.evaluate(()=>calls.filter(c=>c.reminder).length),before);});
 await page.locator('[data-orb-stop]').click();await page.evaluate(()=>{offerReady=true;VP3ParticipantUpdates.camera({active:false,rows:[]});});
 await check('stop fences automatic presentation until fresh speech or camera restart',async()=>{const before=await page.evaluate(()=>bubbles.length);await page.waitForTimeout(5100);assert.equal(await page.evaluate(()=>bubbles.length),before);});
 await check('UI has no uncaught browser errors',async()=>assert.deepEqual(errors,[]));
 console.log('PARTICIPANT_UPDATES_BROWSER=PASS ('+checks+' cases; real DOM, sharing form, nighttime voice, leases, literal text, notes and stop)');
}finally{await browser.close();await new Promise(r=>server.close(r));}
