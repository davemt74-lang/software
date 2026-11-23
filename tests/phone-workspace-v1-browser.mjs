import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {createHash} from 'node:crypto';
const {chromium}=createRequire(import.meta.url)(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=process.cwd(),source=await fs.readFile(path.join(root,'phone-workspace-v1.js'),'utf8'),css=await fs.readFile(path.join(root,'phone-workspace-v1.css'),'utf8');
const shellStyles=new Map(await Promise.all(['chat-header-ui.css','stonefellow-ui.css'].map(async file=>['/'+file,await fs.readFile(path.join(root,file),'utf8')])));
const fixtureHead='<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>*,*::before,*::after{box-sizing:border-box}body{margin:0}</style><link rel="stylesheet" href="/chat-header-ui.css"><link rel="stylesheet" href="/phone-workspace-v1.css">';
const headerShell=button=>'<header class="chat-topbar member-header"><div class="chat-topbar-title"><strong>Agent Chat</strong></div><div class="chat-topbar-actions member-header-actions"><div class="chat-top-menu">'+button+'</div></div></header>';
const actualState=process.env.PHONE_WORKSPACE_STATE_FIXTURE?JSON.parse(await fs.readFile(process.env.PHONE_WORKSPACE_STATE_FIXTURE,'utf8')):null;
const actualUi=process.env.PHONE_WORKSPACE_UI_FIXTURE?JSON.parse(await fs.readFile(process.env.PHONE_WORKSPACE_UI_FIXTURE,'utf8')):null;
const incomingId='a'.repeat(32),outgoingId='b'.repeat(32);
const fixture=()=>({numbers:[{id:1,phone_e164:'+16025550111',agent_id:2,agent_name:'Fixture agent',voice_enabled:true,browser_enabled:true,recording_enabled:true}],selected_number_id:1,readiness:{ready:true,reason:''},contacts:[{id:7,name:'Fixture contact <img src=x onerror=alert(1)>',phone_e164:'+16025550222',favorite:false}],favorites:[],calls:[],voicemails:[{id:'legacy-41',call_id:'legacy_41',peer_e164:'+16025550222',audio_url:'/api/fixture-audio',transcript:'Fixture transcript <script>unsafe()</script>',report_url:'/fixture-report',read:false,created_at:'2026-11-23T12:00:00Z'},{id:'job-42',call_id:'legacy_42',peer_e164:'+16025550333',audio_url:'javascript:unsafe()',transcript:'Other fixture voicemail',transcript_url:null,report_url:'https://untrusted.invalid/private',read:true,created_at:'2026-11-23T11:00:00Z'}],active_call:null,post_call_policy:{enabled:false,retain_audio:false,transcribe:false,analyze:false,model_route:'account_cloud',modules:['basic','qa','actions','decisions','followup']}});
const sdk=`(()=>{
 const m=window.phoneFixture={devices:[],calls:[],connects:[],accepts:0,rejects:0,stops:0,claims:0,releases:0,updates:0,denied:false,holdRegister:false,holdConnect:false};
 class Call{
 constructor(id,from='+16025550444',incoming=false){this.events={};this.customParameters=new Map([['phone_call_id',id]]);this.parameters={From:from};this.state=incoming?'pending':'open';this.muted=false;const track={readyState:'live',stop(){if(this.readyState!=='ended'){this.readyState='ended';m.stops++;}}};this.stream={getTracks:()=>[track]};m.calls.push(this);}
 on(n,f){(this.events[n]??=[]).push(f);return this;}emit(n,...args){for(const f of this.events[n]||[])f(...args);}status(){return this.state;}getLocalStream(){return this.stream;}isMuted(){return this.muted;}mute(v){this.muted=v;}
 accept(){m.accepts++;this.state='open';this.emit('accept',this);}reject(){m.rejects++;this.state='closed';this.emit('reject');}disconnect(){this.state='closed';this.emit('disconnect');}
 }
 class Device{
 constructor(token,options){this.events={};this.token=token;this.options=options;this.destroyed=false;m.devices.push(this);}
 on(n,f){(this.events[n]??=[]).push(f);return this;}emit(n,...args){for(const f of this.events[n]||[])f(...args);}
 async register(){if(m.holdRegister)await new Promise(resolve=>m.releaseRegister=resolve);this.emit('registered');}
 async connect(options){m.connects.push(options);const call=new Call('${outgoingId}');if(m.holdConnect)await new Promise(resolve=>m.releaseConnect=()=>resolve());return call;}
 updateToken(token){this.token=token;m.updates++;}disconnectAll(){}destroy(){this.destroyed=true;}
 }
 window.Twilio={Device};m.incoming=()=>{const call=new Call('${incomingId}','+16025550444',true);m.devices.at(-1).emit('incoming',call);return call;};
 window.StonefellowVoiceLeaseV122={acquireCapture(){if(m.denied)return null;m.claims++;let live=true;const streams=new Set();return {isCurrent:()=>live,ownStream:s=>{if(!live){s.getTracks().forEach(t=>t.stop());return false;}streams.add(s);return true;},release(){if(!live)return;live=false;m.releases++;for(const s of streams)s.getTracks().forEach(t=>t.stop());}};}};
})();`;
const integrity='sha384-'+createHash('sha384').update(sdk).digest('base64');
const actualSdk=await fs.readFile(path.join(root,'assets/twilio-voice-2.18.5.min.js'));
const actualIntegrity='sha384-LUG7DmzDPWNKRYoqyGpV4d/IByMLiya/oUhMSc/4/6hi0wbzO/8PffM4lzeFEYS8';
assert.equal('sha384-'+createHash('sha384').update(actualSdk).digest('base64'),actualIntegrity,'The actual shipped SDK bytes match their pinned integrity');
let state=fixture(),authorized=true,apiCalls=[],tokenGate=null,tokenStarted=false,scriptLoads=0;
const wav=Buffer.alloc(44+16000*2*4);wav.write('RIFF',0);wav.writeUInt32LE(wav.length-8,4);wav.write('WAVEfmt ',8);wav.writeUInt32LE(16,16);wav.writeUInt16LE(1,20);wav.writeUInt16LE(1,22);wav.writeUInt32LE(16000,24);wav.writeUInt32LE(32000,28);wav.writeUInt16LE(2,32);wav.writeUInt16LE(16,34);wav.write('data',36);wav.writeUInt32LE(wav.length-44,40);
const server=http.createServer((req,res)=>{
 const url=new URL(req.url,'http://fixture');
 if(url.pathname==='/phone-workspace-v1.js'){res.setHeader('Content-Type','text/javascript');return res.end(source);}
 if(url.pathname==='/phone-workspace-v1.css'){res.setHeader('Content-Type','text/css');return res.end(css);}
 if(shellStyles.has(url.pathname)){res.setHeader('Content-Type','text/css');return res.end(shellStyles.get(url.pathname));}
 if(url.pathname==='/assets/twilio-voice-2.18.5.min.js'){res.setHeader('Content-Type','text/javascript');if(url.searchParams.has('actual'))return res.end(actualSdk);scriptLoads++;return res.end(sdk);}
 if(url.pathname==='/sdk-smoke'){res.setHeader('Content-Type','text/html');return res.end('<!doctype html><meta charset="utf-8"><script src="/assets/twilio-voice-2.18.5.min.js?actual=1" integrity="'+actualIntegrity+'" crossorigin="anonymous"></script>');}
 if(url.pathname==='/api/fixture-audio'){res.setHeader('Content-Type','audio/wav');return res.end(wav);}
 if(url.pathname==='/api/phone-workspace-v1.php'){
  let body='';req.on('data',c=>body+=c);req.on('end',async()=>{
   const values=req.method==='POST'?JSON.parse(body):Object.fromEntries(url.searchParams);apiCalls.push(values);res.setHeader('Content-Type','application/json');
   if(!authorized){res.statusCode=403;return res.end(JSON.stringify({ok:false,error:'Phone access unavailable'}));}
   if(req.method==='POST'&&values.csrf_token!=='fixture-csrf'){res.statusCode=419;return res.end(JSON.stringify({ok:false}));}
   let result={};
   if(values.action==='state')result={state:structuredClone(state)};
   if(values.action==='token'){tokenStarted=true;if(tokenGate)await tokenGate;result={token:'fixture-token-'+String(apiCalls.length).padStart(20,'0'),identity:'fixture-number-1',expires_at:Math.floor(Date.now()/1000)+3600};}
   if(values.action==='prepare_call')result={call:{id:9,public_id:outgoingId,peer_e164:values.to_number,conversation_id:Number(values.conversation_id),agent_id:Number(values.agent_id),state:'preparing'},connect_params:{call_intent:'c'.repeat(64)}};
   if(values.action==='bind_call')result={call:{id:8,public_id:values.call_id,peer_e164:'+16025550444',conversation_id:Number(values.conversation_id),agent_id:Number(values.agent_id),state:'ringing'}};
   if(values.action==='favorite'){state.contacts[0].favorite=values.favorite;state.favorites=values.favorite?[state.contacts[0]]:[];}
   if(values.action==='save_recording_policy'){state.post_call_policy={enabled:values.enabled,retain_audio:values.retain_audio,transcribe:values.transcribe,analyze:values.analyze,model_route:'account_cloud',modules:values.modules};result={policy:state.post_call_policy};}
   if(values.action==='mark_voicemail_read')state.voicemails.find(v=>v.id===String(values.voicemail_id)).read=true;
   res.end(JSON.stringify({ok:true,...result}));
  });return;
 }
 const cid=url.searchParams.get('new')==='1'?0:10;
 res.setHeader('Content-Type','text/html');res.end(`${fixtureHead}${headerShell('<button class="chat-icon-button" data-phone-workspace-toggle aria-label="Phone">Phone</button>')}<div id="chatThread"></div><script>window.VP3_PHONE_WORKSPACE_V1=${JSON.stringify({endpoint:'/api/phone-workspace-v1.php',urlbase:'/',csrf:'fixture-csrf',owner_id:1,agent_id:2,conversation_id:cid,sdk_url:'/assets/twilio-voice-2.18.5.min.js',sdk_integrity:integrity})};window.currentConversation=${cid};window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>window.currentConversation,isReady:()=>true,isRestoring:()=>false,isBusy:()=>false,ensureConversation:async()=>{window.currentConversation=12;window.VP3PhoneWorkspaceV1.contextChanged({conversation_id:12,agent_id:2,ready:true});return 12;}};</script><script src="/phone-workspace-v1.js"></script>`);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const base='http://127.0.0.1:'+server.address().port,browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
let checks=0;const errors=[];let page,succeeded=false;
async function fresh(suffix=''){
 state=fixture();authorized=true;tokenGate=null;tokenStarted=false;apiCalls=[];
 if(page)await page.close();page=await browser.newPage({viewport:{width:1360,height:900}});page.on('pageerror',e=>errors.push(e.message));await page.goto(base+'/'+suffix);await page.waitForFunction(()=>window.VP3PhoneWorkspaceV1);await page.getByRole('button',{name:'Phone',exact:true}).click();await page.getByLabel('Selected phone number',{exact:true}).waitFor();return page;
}
async function check(name,run){await run();checks++;console.log('PASS '+name);}
async function enable(){await page.getByRole('button',{name:'Enable calls in this browser',exact:true}).click();await page.waitForFunction(()=>window.phoneFixture?.devices.length&&document.querySelector('.vp3-phone-notice')?.textContent==='Ready for incoming calls.');}
async function showDrawer(){if(!await page.locator('.vp3-phone-panel').isVisible())await page.getByRole('button',{name:'Phone',exact:true}).click();await page.getByRole('tab',{name:'Connections',exact:true}).click();}
async function closeDrawer(){if(await page.locator('.vp3-phone-panel').isVisible())await page.locator('.vp3-phone-header').getByRole('button',{name:'Close phone workspace',exact:true}).click();}
async function call(){await page.getByLabel('Phone number to call',{exact:true}).fill('+16025550555');await page.locator('.vp3-phone-dial button').click();await page.getByRole('button',{name:'Hang up',exact:true}).waitFor();}
try{
 await check('the actual bundled Twilio SDK loads with pinned integrity and no device registration or capture',async()=>{
  page=await browser.newPage();page.on('pageerror',e=>errors.push(e.message));await page.addInitScript(()=>{window.sdkSmokeCapture=0;const get=navigator.mediaDevices?.getUserMedia?.bind(navigator.mediaDevices);if(get)navigator.mediaDevices.getUserMedia=(...args)=>{window.sdkSmokeCapture++;return get(...args);};});
  await page.goto(base+'/sdk-smoke');await page.waitForFunction(()=>typeof window.Twilio?.Device==='function');assert.equal(await page.evaluate(()=>window.sdkSmokeCapture),0);assert.equal(apiCalls.length,0);
 });
 await fresh();
 await check('assigned workspace has no token or SDK activity until the owner enables calls',async()=>{assert.equal(scriptLoads,0);assert.equal(apiCalls.some(c=>c.action==='token'),false);assert.equal(await page.locator('.vp3-phone-panel').isVisible(),true);assert.equal(await page.getByText(/purchase|subscribe|sign up/i).count(),0);});
 await check('Phone covers the production header and Close remains clickable on desktop and mobile',async()=>{
  for(const viewport of [{width:1360,height:900},{width:390,height:844},{width:320,height:740}]){
   await page.setViewportSize(viewport);
   await page.waitForFunction(()=>document.querySelector('.vp3-phone-panel').getAnimations().every(animation=>animation.playState==='finished'));
   const hits=await page.evaluate(()=>{
    const close=document.querySelector('.vp3-phone-close'),box=close.getBoundingClientRect(),header=document.querySelector('.chat-topbar').getBoundingClientRect();
    const closeHit=document.elementFromPoint(box.x+box.width/2,box.y+box.height/2);
    const covered=[header.x+20,header.right-20].every(x=>document.elementFromPoint(x,header.y+header.height/2)?.closest('.vp3-phone-workspace'));
    return {close:closeHit===close||close.contains(closeHit),covered};
   });
   assert.equal(hits.close,true,'The Phone Close button receives pointer input at '+viewport.width+'px');
   assert.equal(hits.covered,true,'The Phone backdrop/panel covers both sides of the application header at '+viewport.width+'px');
   await page.locator('.vp3-phone-close').click();
   assert.equal(await page.locator('.vp3-phone-panel').isVisible(),false);
   assert.equal(await page.getByRole('button',{name:'Phone',exact:true}).evaluate(node=>node===document.activeElement),true);
   await showDrawer();
   if(viewport.width>500){
    await page.locator('.vp3-phone-backdrop').click({position:{x:20,y:20}});
    assert.equal(await page.locator('.vp3-phone-panel').isVisible(),false,'Clicking the backdrop above the application header closes Phone');
    assert.equal(await page.getByRole('button',{name:'Phone',exact:true}).evaluate(node=>node===document.activeElement),true);
    await showDrawer();
   }
  }
  await page.setViewportSize({width:1360,height:900});
 });
 await check('favorites and contact text are safe, scoped and saved through CSRF actions',async()=>{await page.getByRole('tab',{name:'Contacts',exact:true}).click();assert.equal(await page.locator('.vp3-phone-panel img').count(),0);await page.getByRole('button',{name:/^Favorite Fixture contact/}).click();await page.getByRole('button',{name:/^Remove favorite Fixture contact/}).waitFor();const saved=apiCalls.find(c=>c.action==='favorite');assert.equal(saved.contact_id,7);assert.equal(saved.conversation_id,10);assert.equal(saved.agent_id,2);assert.equal(saved.csrf_token,'fixture-csrf');});
 await check('saved voicemail uses authenticated audio and safe transcript/report links',async()=>{await page.getByRole('tab',{name:'Voicemail',exact:true}).click();assert.equal(await page.locator('.vp3-phone-voicemail audio').count(),1);assert.equal(await page.locator('.vp3-phone-voicemail a').count(),1);assert.equal(await page.locator('.vp3-phone-voicemail script').count(),0);await page.locator('.vp3-phone-voicemail summary').first().click();assert.match(await page.locator('.vp3-phone-voicemail').first().innerText(),/<script>unsafe/);await page.locator('audio').evaluate(async audio=>{audio.muted=true;audio.loop=true;window.savedVoicemailAudio=audio;await audio.play();});await page.evaluate(()=>window.VP3PhoneWorkspaceV1.refresh());assert.equal(await page.locator('audio').evaluate(audio=>audio===window.savedVoicemailAudio&&!audio.paused),true,'Polling preserves active playback');});
 await check('absent, null and blank media/transcript/report URLs never become same-page links',async()=>{await page.locator('audio').evaluateAll(nodes=>nodes.forEach(audio=>audio.pause()));const previous=structuredClone(state.voicemails);state.voicemails=[undefined,null,'','   '].map((missing,index)=>({id:'job-'+(70+index),call_id:'fixture_call_'+index,peer_e164:'+16025550222',transcript:'Fixture without media URL',audio_url:missing,transcript_url:missing,report_url:missing}));await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());assert.equal(await page.locator('.vp3-phone-voicemail audio').count(),0);assert.equal(await page.locator('.vp3-phone-voicemail a').count(),0);state.voicemails=previous;await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());});
 await check('drawer focus and Escape restore the header button',async()=>{await page.keyboard.press('Escape');assert.equal(await page.locator('.vp3-phone-panel').isVisible(),false);assert.equal(await page.getByRole('button',{name:'Phone',exact:true}).evaluate(n=>n===document.activeElement),true);await page.getByRole('button',{name:'Phone',exact:true}).click();await page.getByRole('tab',{name:'Connections',exact:true}).click();});
 await check('after-call settings are explicit, persisted and keep unsaved edits across polling',async()=>{assert.equal(await page.getByLabel('Enable Cloud transcription and analysis for recorded calls',{exact:true}).isChecked(),false);await page.getByLabel('Enable Cloud transcription and analysis for recorded calls',{exact:true}).check();await page.getByLabel('Create transcripts',{exact:true}).check();await page.getByLabel('Create a call report',{exact:true}).check();await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());assert.equal(await page.getByLabel('Enable Cloud transcription and analysis for recorded calls',{exact:true}).isChecked(),true);await page.getByRole('button',{name:'Save after-call settings',exact:true}).click();await page.waitForFunction(()=>document.querySelector('.vp3-phone-notice').textContent==='After-call settings saved.');const saved=apiCalls.find(c=>c.action==='save_recording_policy');assert.equal(saved.number_id,1);assert.equal(saved.enabled,true);assert.deepEqual(saved.modules,['basic','qa','actions','decisions','followup']);});
 await check('polling preserves focused dial text and caret',async()=>{const field=page.getByLabel('Phone number to call',{exact:true});await field.fill('+16025550555');await field.evaluate(n=>n.setSelectionRange(4,4));await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());assert.equal(await field.evaluate(n=>n===document.activeElement&&n.selectionStart===4),true);assert.equal(await field.inputValue(),'+16025550555');});
 await check('browser registration loads the pinned client without taking the microphone',async()=>{await enable();assert.equal(scriptLoads,1);assert.equal(await page.evaluate(()=>phoneFixture.claims),0);assert.equal(await page.locator('script[src*="twilio-voice-2.18.5"]').getAttribute('integrity'),integrity);});
 await check('incoming calls announce in Chat; Decline takes no microphone or bind action',async()=>{await closeDrawer();await page.evaluate(()=>phoneFixture.incoming());await page.locator('#chatThread').getByRole('button',{name:'Accept',exact:true}).waitFor();await page.locator('#chatThread').getByRole('button',{name:'Decline',exact:true}).click();assert.equal(await page.evaluate(()=>phoneFixture.rejects),1);assert.equal(await page.evaluate(()=>phoneFixture.claims),0);assert.equal(apiCalls.some(c=>c.action==='bind_call'),false);});
 await check('Accept binds the exact current conversation and releases microphone on remote end',async()=>{await closeDrawer();await page.evaluate(()=>phoneFixture.incoming());await page.locator('#chatThread').getByRole('button',{name:'Accept',exact:true}).click();await page.locator('#chatThread').getByRole('button',{name:'Hang up',exact:true}).waitFor();assert.equal(await page.evaluate(()=>phoneFixture.accepts),1);assert.equal(apiCalls.find(c=>c.action==='bind_call').call_id,incomingId);await page.evaluate(()=>phoneFixture.calls.at(-1).emit('disconnect'));await page.waitForFunction(()=>phoneFixture.releases===1);assert.equal(await page.evaluate(()=>phoneFixture.calls.at(-1).stream.getTracks().every(t=>t.readyState==='ended')),true);});
 await check('outgoing calls send only an opaque server intent to the SDK and explicit recording choice',async()=>{await showDrawer();await page.getByLabel('Record this call',{exact:true}).check();await call();assert.equal(apiCalls.filter(c=>c.action==='prepare_call').at(-1).recording_enabled,true);assert.deepEqual(await page.evaluate(()=>phoneFixture.connects.at(-1)),{params:{call_intent:'c'.repeat(64)}});await page.locator('.vp3-phone-section:not([hidden])').getByRole('button',{name:'Hang up',exact:true}).click();assert.equal(await page.evaluate(()=>phoneFixture.claims===phoneFixture.releases),true);});
 await check('microphone ownership conflict prevents call preparation',async()=>{const before=apiCalls.filter(c=>c.action==='prepare_call').length;await page.evaluate(()=>phoneFixture.denied=true);await page.getByLabel('Phone number to call',{exact:true}).fill('+16025550555');await page.locator('.vp3-phone-dial button').click();await page.waitForFunction(()=>document.querySelector('.vp3-phone-notice').textContent.includes('Another browser surface'));assert.equal(apiCalls.filter(c=>c.action==='prepare_call').length,before);assert.match(await page.locator('.vp3-phone-notice').innerText(),/Another browser surface/);await page.evaluate(()=>phoneFixture.denied=false);});
 await fresh();await enable();
 await check('late outgoing connection cannot survive a Chat context change',async()=>{await page.evaluate(()=>phoneFixture.holdConnect=true);await call();await page.evaluate(()=>VP3PhoneWorkspaceV1.contextChanged({conversation_id:11,agent_id:2,ready:true}));await page.evaluate(()=>phoneFixture.releaseConnect());await page.waitForFunction(()=>phoneFixture.calls.at(-1).stream.getTracks().every(t=>t.readyState==='ended'));assert.equal(await page.evaluate(()=>phoneFixture.claims===phoneFixture.releases),true);assert.equal(await page.locator('.vp3-phone-panel').getByRole('button',{name:'Hang up',exact:true}).count(),0);});
 await fresh();await enable();
 await check('late token refresh cannot reauthorize a disposed browser connection',async()=>{let release;tokenGate=new Promise(r=>release=r);tokenStarted=false;await page.evaluate(()=>phoneFixture.devices.at(-1).emit('tokenWillExpire'));for(let i=0;!tokenStarted;i++){if(i>100)throw Error('Token refresh not requested');await new Promise(r=>setTimeout(r,10));}await page.evaluate(()=>VP3PhoneWorkspaceV1.contextChanged({conversation_id:11,agent_id:2,ready:true}));release();await page.waitForTimeout(60);assert.equal(await page.evaluate(()=>phoneFixture.updates),0);tokenGate=null;});
 await fresh();await enable();await call();
 await check('assignment loss destroys the SDK, ends owned media and clears private cards',async()=>{authorized=false;await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());assert.equal(await page.getByRole('button',{name:'Phone',exact:true}).isVisible(),false);assert.equal(await page.evaluate(()=>phoneFixture.devices.every(d=>d.destroyed)),true);assert.equal(await page.evaluate(()=>phoneFixture.calls.every(c=>c.stream.getTracks().every(t=>t.readyState==='ended'))),true);assert.equal(await page.locator('.vp3-phone-section').allTextContents().then(x=>x.join('')),'');});
 await fresh('?new=1');
 await check('a new Chat initializes through its canonical conversation before phone credentials',async()=>{await enable();assert.equal(apiCalls.find(c=>c.action==='token').conversation_id,12);});
 await check('mobile drawer has one body scroll and unclipped controls',async()=>{await page.setViewportSize({width:320,height:740});const bounds=await page.locator('.vp3-phone-panel').boundingBox();assert.ok(bounds.x>=0&&bounds.x+bounds.width<=321);const layout=await page.locator('.vp3-phone-panel').evaluate(panel=>({width:panel.clientWidth,scroll:panel.scrollWidth,body:getComputedStyle(panel.querySelector('.vp3-phone-body')).overflowY}));assert.ok(layout.scroll<=layout.width+1);assert.equal(layout.body,'auto');const labels=await page.locator('.vp3-phone-tabs button').evaluateAll(buttons=>buttons.map(button=>{const range=document.createRange();range.selectNodeContents(button);return {width:range.getBoundingClientRect().width,available:button.clientWidth-4,lines:range.getClientRects().length,height:button.clientHeight};}));assert.ok(labels.every(label=>label.lines===1&&label.width<=label.available+1&&label.height>=44),'Mobile tab labels fit on one line with touch-sized buttons');await fs.mkdir(path.join(root,'tests/artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'tests/artifacts/phone-workspace-mobile.png')});await page.setViewportSize({width:1360,height:900});await page.screenshot({path:path.join(root,'tests/artifacts/phone-workspace-desktop.png')});});
 await fresh();await enable();
 await check('Recent calls combines browser and legacy history; legacy Chat callback starts a new authorized call',async()=>{
  state.calls=[{id:90,public_id:'d'.repeat(32),call_id:'d'.repeat(32),direction:'outbound',peer_e164:'+16025550666',phone_e164:'+16025550111',state:'completed',source_kind:'browser',browser_control:true,created_at:'2026-11-23T12:00:00Z'},{id:91,public_id:'legacy-91',call_id:'legacy-91',direction:'inbound',peer_e164:'+16025550777',phone_e164:'+16025550111',state:'voicemail_ready',source_kind:'legacy',browser_control:false,created_at:'2026-11-23T11:00:00Z'}];
  await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());await page.getByRole('tab',{name:'Recent calls',exact:true}).click();assert.equal(await page.locator('.vp3-phone-section:not([hidden]) .vp3-phone-row').count(),2);
  await closeDrawer();await page.evaluate(()=>{const card=document.createElement('div');card.id='legacyFixtureCard';document.getElementById('chatThread').append(card);VP3PhoneWorkspaceV1.bindMessage(card,{kind:'call',call_id:'legacy-91'});});
  const legacy=page.locator('#legacyFixtureCard');assert.match(await legacy.innerText(),/voicemail ready/);assert.equal(await legacy.getByRole('button',{name:/Accept|Decline|Hang up/}).count(),0);
  await legacy.getByRole('button',{name:'Call back',exact:true}).click();await page.waitForFunction(()=>phoneFixture.connects.length===1);assert.equal(apiCalls.filter(c=>c.action==='prepare_call').at(-1).to_number,'+16025550777');assert.equal(apiCalls.some(c=>c.action==='bind_call'),false);assert.deepEqual(await page.evaluate(()=>phoneFixture.connects[0]),{params:{call_intent:'c'.repeat(64)}});
  await showDrawer();await page.locator('.vp3-phone-section:not([hidden])').getByRole('button',{name:'Hang up',exact:true}).click();
 });
 if(actualState)await check('the unmodified PHP projection renders both voicemail namespaces and saved processing policy',async()=>{
  assert.ok(actualUi?.button&&actualUi?.config,'The actual PHP header and runtime configuration are mandatory with the real projection');
  assert.ok(actualState.numbers.length>0);assert.ok(actualState.voicemails.some(row=>/^job-/.test(row.id)));assert.ok(actualState.voicemails.some(row=>/^legacy-/.test(row.id)));
  await page.close();page=await browser.newPage({viewport:{width:1360,height:900}});page.on('pageerror',e=>errors.push(e.message));
  const origin=new URL(actualUi.config.endpoint).origin;
  await page.route(origin+'/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/phone-workspace-v1.js')return route.fulfill({contentType:'text/javascript',body:source});
   if(url.pathname==='/phone-workspace-v1.css')return route.fulfill({contentType:'text/css',body:css});
   if(shellStyles.has(url.pathname))return route.fulfill({contentType:'text/css',body:shellStyles.get(url.pathname)});
   if(url.pathname===new URL(actualUi.config.endpoint).pathname){assert.equal(route.request().method(),'GET');return route.fulfill({json:{ok:true,state:actualState}});}
   return route.fulfill({contentType:'text/html',body:fixtureHead+headerShell(actualUi.button)+'<section id="chatThread"></section><script>window.VP3_PHONE_WORKSPACE_V1='+JSON.stringify(actualUi.config)+';</script><script src="/phone-workspace-v1.js"></script>'});
  });
  await page.goto(origin+'/phone-fixture');await page.getByRole('button',{name:'Phone',exact:true}).click();await page.getByLabel('Selected phone number',{exact:true}).waitFor();
  const actualIcon=page.locator('[data-phone-workspace-toggle] svg');assert.equal(await actualIcon.getAttribute('width'),'18');assert.equal(await actualIcon.getAttribute('height'),'18');assert.equal(await page.locator('[data-phone-workspace-toggle]').getAttribute('aria-controls'),await page.locator('.vp3-phone-workspace').getAttribute('id'));
  assert.equal(await page.getByLabel('Selected phone number',{exact:true}).inputValue(),String(actualState.selected_number_id));
  assert.ok(actualState.calls.some(row=>/^legacy-/.test(row.public_id||row.call_id)),'The actual ledger includes historical calls');await page.getByRole('tab',{name:'Recent calls',exact:true}).click();assert.equal(await page.locator('.vp3-phone-section:not([hidden]) .vp3-phone-row').count(),actualState.calls.length);await page.getByRole('tab',{name:'Connections',exact:true}).click();
  assert.equal(await page.getByLabel('Enable Cloud transcription and analysis for recorded calls',{exact:true}).isChecked(),actualState.post_call_policy.enabled);
  for(const voicemail of actualState.voicemails)await page.evaluate(row=>{const host=document.createElement('div');document.getElementById('chatThread').append(host);window.VP3PhoneWorkspaceV1.bindMessage(host,{kind:'voicemail',call_id:row.call_id,voicemail_id:row.id});},voicemail);
  await page.getByRole('tab',{name:'Voicemail',exact:true}).click();
  assert.equal(await page.locator('.vp3-phone-section:not([hidden]) .vp3-phone-voicemail').count(),actualState.voicemails.length);
  assert.equal(await page.locator('#chatThread .vp3-phone-voicemail').count(),actualState.voicemails.length);
  const expectedAudio=actualState.voicemails.filter(row=>{try{return new URL(row.audio_url,origin).origin===origin&&Boolean(row.audio_url);}catch(_){return false;}});
  assert.equal(await page.locator('#chatThread .vp3-phone-voicemail audio').count(),expectedAudio.length,'Actual authenticated audio URLs are preserved as usable same-origin sources');
  assert.deepEqual((await page.locator('#chatThread .vp3-phone-voicemail audio').evaluateAll(nodes=>nodes.map(n=>n.src))).sort(),expectedAudio.map(row=>new URL(row.audio_url,origin).href).sort());
  for(const row of actualState.voicemails){if(row.transcript_url)assert.ok((await page.locator('#chatThread .vp3-phone-report').evaluateAll(nodes=>nodes.map(n=>n.href))).includes(row.transcript_url));}
  for(const row of actualState.voicemails){if(row.transcript)assert.ok((await page.locator('#chatThread').innerText()).includes(row.transcript));}
  assert.equal(await page.locator('.vp3-phone-voicemail a[href^="javascript:"],.vp3-phone-voicemail audio[src^="javascript:"]').count(),0);
  await page.screenshot({path:path.join(root,'tests/artifacts/phone-workspace-real-projection.png')});
 });
 assert.deepEqual(errors,[]);succeeded=true;console.log('PHONE_WORKSPACE_BROWSER=PASS ('+checks+' cases)');
}finally{if(!succeeded&&page&&!page.isClosed()){await fs.mkdir(path.join(root,'tests/artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'tests/artifacts/phone-workspace-failure.png'),fullPage:true,timeout:5000}).catch(()=>{});}await browser.close();await new Promise(resolve=>server.close(resolve));}
