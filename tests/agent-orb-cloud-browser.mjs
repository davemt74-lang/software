import assert from 'node:assert/strict';import fs from 'node:fs';import http from 'node:http';import path from 'node:path';import {createRequire} from 'node:module';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const writes=[],voiceConfigs=[];let consent=false,participantName='David',participantNote='';
const profile=()=>({id:7,display_name:participantName,recognition_consent:consent,cloning_consent:true,recognition_scope:'private'});
const server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://localhost');
  if(url.pathname==='/sub/api/participants.php'){
    let body='';req.on('data',chunk=>body+=chunk);req.on('end',()=>{if(body){const data=JSON.parse(body);writes.push(data);if(data.action==='save_agent_details'){participantName=data.display_name;participantNote=data.conversation_note;}if(data.action==='allow_local_visual_recognition')consent=true;}res.setHeader('Content-Type','application/json');res.end(JSON.stringify({ok:true,profiles:[profile()],participant:{id:'7',name:participantName,note:participantNote}}));});return;
  }
  if(url.pathname==='/premium.js'){res.setHeader('Content-Type','text/javascript');res.end(`window.StonefellowPremiumVoiceV122=cfg=>{window.voiceConfig=cfg;return {stop:()=>window.cancelled=true,speak:(text,callbacks)=>{window.spoken=text;window.finishSpeech=callbacks.onEnd;callbacks.onStart();return Promise.resolve(true)}}};`);return;}
  if(['/agent-eyes-core.js','/agent-orb-adapter.js','/agent-orb.js','/agent-orb-particles.js','/chat-notifications-drawer-v240.js','/agent-orb.css','/agent-orb-particles.css','/voice-trained-core.js'].includes(url.pathname)){res.setHeader('Content-Type',url.pathname.endsWith('.css')?'text/css; charset=utf-8':'text/javascript; charset=utf-8');res.end(fs.readFileSync(path.basename(url.pathname)));return;}
  const main=url.searchParams.has('main')?`<main class="chat-main"><h1>Cloud Chat</h1><section class="chat-thread" id="chatThread" data-view="player"><section data-chat-view="player"><button data-chat-view-target="player">Player view</button></section><article class="message">Existing conversation</article></section><div id="chatComposerShell"><form id="chatForm"><textarea id="chatInput"></textarea><button id="chatVoiceButton" type="button">Voice</button><button id="sendChatButton">Send</button></form></div></main><script>window.originalThread=document.getElementById('chatThread');window.originalInput=document.getElementById('chatInput');window.sent=0;document.getElementById('chatForm').addEventListener('submit',e=>{e.preventDefault();window.sent++;const reply=document.createElement('article');reply.className='message';reply.textContent='New reply '+window.sent;originalThread.append(reply);});</script>`:'<main><h1>Admin settings</h1></main>';
  res.setHeader('Content-Type','text/html; charset=utf-8');res.end(`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/agent-orb.css"><link rel="stylesheet" href="/agent-orb-particles.css"><style>body{margin:0}#chatComposerShell{position:fixed;bottom:0;left:0;right:0;padding:8px;background:white}#chatForm{display:flex;gap:8px}#chatInput{flex:1;min-width:0}.chat-thread{height:220px;overflow:auto}.message{padding:12px}</style>${main}<script>window.VP3_ORB_CONFIG={trainedVoiceUrl:'/voice-trained-client.js',ownerKey:'${url.searchParams.get('owner')==='2'?'2':'1'}',csrf:'test',chatUrl:'/sub/chat.php',chatEndpoint:'/sub/api/chat.php',participantsEndpoint:'/sub/api/participants.php',notificationsEndpoint:'/sub/api/notifications.php',eyesEndpoint:'/sub/api/eyes.php',agentVoiceEnabled:true,voiceScript:'/premium.js',drawerScript:'/chat-notifications-drawer-v240.js'};</script><script src="/agent-eyes-core.js"></script><script src="/agent-orb-adapter.js"></script><script src="/agent-orb.js"></script><script src="/agent-orb-particles.js"></script>`);
});await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const url=`http://127.0.0.1:${server.address().port}`;
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});let checks=0;
const check=async(name,fn)=>{await fn();checks++;console.log('PASS '+name);};
async function checkPopupLayout(page, viewport) {
  const popup = page.locator('#vp3AgentChatPopup');
  const orb = page.locator('.vp3-orb-ball');
  const work = orb.locator('.vp3-public-orb-work-tracks');
  await work.waitFor();
  const [box, ball, tracks] = await Promise.all([popup.boundingBox(), orb.boundingBox(), work.boundingBox()]);
  assert.ok(box.x >= 0 && box.y >= 0 && box.x + box.width <= viewport.width && box.y + box.height <= viewport.height);
  assert.ok(Math.abs(box.x + box.width / 2 - viewport.width / 2) < 1, 'popup is horizontally centered');
  assert.ok(Math.abs(box.y + box.height / 2 - viewport.height / 2) < 1, 'popup is vertically centered');
  assert.ok(tracks.x >= 0 && tracks.y >= 0 && tracks.x + tracks.width <= viewport.width && tracks.y + tracks.height <= viewport.height);
  if (viewport.width > 740) {
    assert.ok(ball.x >= box.x + box.width && tracks.x >= box.x + box.width, 'orb and work tracks stay to the right');
  } else {
    assert.ok(ball.y + ball.height <= box.y && tracks.y + tracks.height <= box.y, 'orb and work tracks stay above');
  }
  assert.equal(await orb.evaluate(node => node.closest('.vp3-orb-root').nextElementSibling?.id), 'vp3AgentChatPopup');
  assert.equal(await orb.getAttribute('aria-expanded'), 'true');
}
try{
  const context=await browser.newContext();const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto(url);await page.waitForFunction(()=>!!window.STONEFELLOW_NOTIFICATION_CENTER);
  await check('admin page reuses existing voice path without adding a header bell or opening chat',async()=>{assert.equal(await page.locator('iframe').count(),0);assert.equal(await page.locator('.chat-notification-drawer').count(),0);});
  await check('automatic greeting uses configured voice and subdirectory-aware endpoint',async()=>{assert.equal(await page.evaluate(()=>window.VP3_ORB_ADAPTER.greet('Welcome.',()=>true)),true);assert.equal(new URL(await page.evaluate(()=>voiceConfig.agentEndpoint),url).pathname,'/sub/api/chat.php');await page.waitForFunction(()=>document.querySelector('.vp3-orb-root').dataset.state==='speaking');assert.equal(await page.evaluate(()=>spoken),'Welcome.');});
  await check('actual speech end clears the pulse without opening popup',async()=>{await page.evaluate(()=>window.finishSpeech());await page.waitForFunction(()=>document.querySelector('.vp3-orb-root').dataset.state==='idle');assert.equal(await page.locator('#vp3AgentChatPopup').isVisible(),false);});
  await check('orb Stop cancels existing premium output',async()=>{await page.evaluate(()=>window.VP3_ORB_ADAPTER.greet('Again.',()=>true));await page.locator('.vp3-orb-root').hover();await page.locator('[data-orb-stop]').click();assert.equal(await page.evaluate(()=>cancelled),true);});
  await check('Agent Voice off suppresses automatic audio',async()=>{await page.evaluate(()=>window.dispatchEvent(new CustomEvent('stonefellow:agent-voice',{detail:{enabled:false}})));assert.equal(await page.evaluate(()=>window.VP3_ORB_ADAPTER.greet('Muted.',()=>true)),false);});
  const v=Array(32).fill(0).map((_,i)=>i===0?1:0);
  await check('local enrollment uses the existing participant and leaves cloning preferences untouched',async()=>{await page.evaluate(v=>window.VP3_ORB_ADAPTER.enrollEyes({id:'7',embeddings:[v,v,v],portraits:Array.from({length:3},()=>({image:'data:image/jpeg;base64,/9j/2Q==',captured_at:new Date().toISOString()})),isCurrent:()=>true}),v);assert.equal(writes.length,1);assert.equal(writes[0].action,'allow_local_visual_recognition');assert.equal(writes[0].consent_confirmed,true);assert.equal('cloning_consent' in writes[0],false);assert.equal('embeddings' in writes[0],false);const rows=await page.evaluate(()=>window.VP3_ORB_ADAPTER.loadEyesProfiles());assert.equal(rows[0].embeddings.length,3);assert.equal(rows[0].portraits.length,3);assert.equal('portraits' in writes[0],false);});
  await check('different Cloud account cannot load another account’s local samples',async()=>{const other=await context.newPage();await other.goto(url+'?owner=2');const rows=await other.evaluate(()=>window.VP3_ORB_ADAPTER.loadEyesProfiles());assert.equal(rows[0].embeddings.length,0);assert.equal(rows[0].portraits.length,0);await other.close();});
  await check('forgetting local samples does not change voice or participant settings',async()=>{await page.evaluate(()=>window.VP3_ORB_ADAPTER.forgetEyes('7'));assert.equal((await page.evaluate(()=>window.VP3_ORB_ADAPTER.loadEyesProfiles()))[0].embeddings.length,0);assert.equal(writes.length,1);});
  await check('introductions reuse the canonical participant without altering any consent or voice settings',async()=>{const p=await page.evaluate(()=>window.VP3_ORB_ADAPTER.introduceEyes({name:'david'}));assert.equal(p.name,'David');assert.equal(p.id,'7');assert.equal(writes.length,1);});
  await check('a cancelled introduction cannot create a participant',async()=>{assert.equal(await page.evaluate(async()=>{try{await window.VP3_ORB_ADAPTER.introduceEyes({name:'Alex',isCurrent:()=>false});return false;}catch(_){return true;}}),true);assert.equal(writes.length,1);});
  await check('local voice learning uses its own sample key without uploading voice features',async()=>{await page.evaluate(async v=>{for(let i=0;i<3;i++)await VP3_ORB_ADAPTER.saveVoiceSample({id:'7',embedding:v,isCurrent:()=>true});},v);const profiles=await page.evaluate(()=>VP3_ORB_ADAPTER.loadVoiceProfiles());assert.equal(profiles[0].voiceProfileReady,true);assert.equal(profiles[0].voiceEmbeddings.length,3);assert.ok(writes.slice(1).every(w=>w.action==='allow_local_voice_recognition'&&!('embedding' in w)&&!('cloning_consent' in w)));assert.equal((await page.evaluate(()=>VP3_ORB_ADAPTER.loadEyesProfiles()))[0].embeddings.length,0);});
  await check('voice features remain account scoped and forgetting them preserves visual samples',async()=>{const other=await context.newPage();await other.goto(url+'?owner=2');assert.equal((await other.evaluate(()=>VP3_ORB_ADAPTER.loadVoiceProfiles()))[0].voiceProfileReady,false);await other.close();await page.evaluate(async v=>{await VP3_ORB_ADAPTER.enrollEyes({id:'7',embeddings:[v,v,v],portraits:Array.from({length:3},()=>({image:'data:image/jpeg;base64,/9j/2Q==',captured_at:new Date().toISOString()})),isCurrent:()=>true});await VP3_ORB_ADAPTER.forgetVoice('7');},v);assert.equal((await page.evaluate(()=>VP3_ORB_ADAPTER.loadEyesProfiles()))[0].embeddings.length,3);assert.equal((await page.evaluate(()=>VP3_ORB_ADAPTER.loadVoiceProfiles()))[0].voiceProfileReady,false);});
  await check('name and conversation note updates leave recognition and cloning fields untouched',async()=>{await page.evaluate(()=>VP3_ORB_ADAPTER.saveParticipantDetails({id:'7',name:'David Evans',note:'Prefers brief replies',isCurrent:()=>true}));const p=await page.evaluate(()=>VP3_ORB_ADAPTER.participantDetails('7'));assert.equal(p.name,'David Evans');assert.equal(p.note,'Prefers brief replies');assert.equal(writes.at(-1).action,'save_agent_details');assert.equal('recognition_consent' in writes.at(-1),false);assert.equal('cloning_consent' in writes.at(-1),false);});
  await check('full Chat links preserve conversation and selected agent within the subdirectory',async()=>{const link=await page.evaluate(()=>VP3_ORB_ADAPTER.conversationUrl({id:73,agent:5}));assert.match(link,/\/sub\/chat.php\?conversation_id=73&agent=5$/);});
  await check('trained profiles remain separate, account scoped and absent from server payloads',async()=>{await page.evaluate(async()=>{const v=Array.from({length:512},(_,i)=>i===0?1:0);for(let i=0;i<3;i++)await VP3_ORB_ADAPTER.saveTrainedVoiceSample({id:'7',embedding:v,isCurrent:()=>true});});const p=(await page.evaluate(()=>VP3_ORB_ADAPTER.loadVoiceProfiles()))[0];assert.equal(p.voiceTrainedProfile.embeddings.length,3);assert.equal(p.voiceTrainedProfile.rawAudioStored,false);assert.equal(p.voiceEmbeddings.length,0);assert.equal((await page.evaluate(()=>VP3_ORB_ADAPTER.loadEyesProfiles()))[0].embeddings.length,3);assert.equal(writes.some(w=>JSON.stringify(w).includes('embeddings')),false);const other=await context.newPage();await other.goto(url+'?owner=2');assert.equal((await other.evaluate(()=>VP3_ORB_ADAPTER.loadVoiceProfiles()))[0].voiceTrainedProfile,null);await other.close();await page.evaluate(()=>VP3_ORB_ADAPTER.forgetVoice('7'));assert.equal((await page.evaluate(()=>VP3_ORB_ADAPTER.loadVoiceProfiles()))[0].voiceTrainedProfile,null);});
  await check('TV overlay styling applies with ordinary and reduced motion',async()=>{await page.evaluate(()=>{const node=document.createElement('div');node.id='motionProbe';node.className='vp3-eyes-box vp3-tv-box';document.body.append(node);});for(const reducedMotion of ['no-preference','reduce']){await page.emulateMedia({reducedMotion});assert.equal(await page.locator('#motionProbe').evaluate(node=>getComputedStyle(node).borderTopColor),'rgb(240, 191, 97)');if(reducedMotion==='reduce')assert.equal(await page.locator('#motionProbe').evaluate(node=>getComputedStyle(node).transitionDuration),'0s');}await page.emulateMedia({reducedMotion:'no-preference'});await page.locator('#motionProbe').evaluate(node=>node.remove());});
  await check('footer Chat opens and toggles the off-page popup using one embedded runtime',async()=>{await page.locator('[data-orb-chat]').click();await page.locator('#vp3AgentChatPopup').waitFor({state:'visible'});assert.equal(await page.locator('iframe').count(),1);assert.match(await page.locator('iframe').getAttribute('src'),/\/sub\/chat\.php\?orb_embed=1$/);await page.getByRole('button',{name:'Close Agent Chat',exact:true}).click();assert.equal(await page.locator('[data-orb-chat]').getAttribute('aria-expanded'),'false');await page.locator('[data-orb-chat]').click();assert.equal(await page.locator('iframe').count(),1);await page.getByRole('button',{name:'Close Agent Chat',exact:true}).click();});
  await check('embedded status uses the existing parent orb and clears when the popup closes',async()=>{
    await page.locator('[data-orb-chat]').click();
    const frame=await page.locator('iframe').elementHandle().then(node=>node.contentFrame());
    await frame.waitForFunction(()=>typeof window.VP3AgentOrb?.setInitiativeStatus==='function');
    const status=page.locator('.vp3-orb-initiative');
    await frame.evaluate(()=>parent.postMessage({channel:'vp3-orb',kind:'initiative-status',ownerKey:'other',view:{label:'Foreign status'}},location.origin));
    assert.equal(await status.evaluate(node=>node.hidden),true);
    await frame.evaluate(()=>window.VP3AgentOrb.setInitiativeStatus({label:'Checking from the embedded Chat.',setting:'off'},action=>window.lastInitiativeAction=action));
    await page.locator('.vp3-orb-ball').hover();
    await status.getByText('Checking from the embedded Chat.').waitFor();
    await status.getByRole('button',{name:'Pause'}).click();
    await frame.waitForFunction(()=>window.lastInitiativeAction==='off');
    await page.getByRole('button',{name:'Close Agent Chat',exact:true}).click();
    assert.equal(await status.evaluate(node=>node.hidden),true);
  });
  await check('embedded Chat can change the host orb appearance for the same account',async()=>{
    await page.locator('[data-orb-chat]').click();
    const frame=await page.locator('iframe').elementHandle().then(node=>node.contentFrame());
    const orb=page.locator('.vp3-orb-ball .vp3-public-orb');
    await frame.waitForFunction(()=>document.documentElement.dataset.vp3OrbEmbed==='1');
    await frame.evaluate(()=>parent.postMessage({channel:'vp3-orb',kind:'agent-orb-shape',ownerKey:'other',shape:'particle_face'},location.origin));
    await frame.evaluate(()=>parent.postMessage({channel:'vp3-orb',kind:'agent-orb-shape',ownerKey:'1',shape:'invalid'},location.origin));
    assert.equal(await orb.getAttribute('data-orb-shape'),'orbit');
    const change=shape=>frame.evaluate(shape=>window.dispatchEvent(new CustomEvent('vp3:agent-orb-shape',{detail:{shape}})),shape);
    await change('particle_face');
    await page.locator('.vp3-orb-ball [data-orb-shape="particle_face"]').waitFor();
    await change('orbit');
    await page.locator('.vp3-orb-ball [data-orb-shape="orbit"]').waitFor();
    await page.getByRole('button',{name:'Close Agent Chat',exact:true}).click();
  });
  await check('the floating orb shows distinct current jobs and bounds the number of visible tracks',async()=>{
    const work=page.locator('.vp3-public-orb-work-tracks');
    const status=page.locator('#vp3OrbJobsStatus');
    assert.equal(await work.count(),0);
    await page.evaluate(()=>{
      const expiresAt=Date.now()+10000;
      VP3AgentOrb.setJobs('initiative',[{id:'workflow:10',expiresAt},{id:'workflow:10',expiresAt}]);
      VP3AgentOrb.setJobs('mission',[1,2,3,4,5].map(id=>({id:`mission:abc:${id}`,expiresAt})));
      VP3AgentOrb.setJobs('image',[{id:'image:stale',expiresAt:1},{id:'workflow:foreign',expiresAt}]);
    });
    await page.locator('[data-orb-work-tracks="4"]').waitFor();
    assert.equal(await status.textContent(),'6 jobs running');
    assert.equal(await status.isVisible(),true);
    assert.equal(await page.locator('.vp3-orb-ball').getAttribute('aria-describedby'),'vp3OrbJobsStatus');
    const image=await work.evaluate(node=>node.toDataURL());
    await page.waitForFunction(previous=>document.querySelector('.vp3-public-orb-work-tracks').toDataURL()!==previous,image);
    await page.evaluate(()=>VP3AgentOrb.setJobs('mission',[]));
    await page.locator('[data-orb-work-tracks="1"]').waitFor();
    assert.equal(await status.textContent(),'1 job running');
    await page.evaluate(()=>VP3AgentOrb.setJobs('initiative',[]));
    await work.waitFor({state:'detached'});
    assert.equal(await status.textContent(),'');
  });
  await check('same-account embedded job updates persist while the popup is closed and expire when stale',async()=>{
    await page.locator('[data-orb-chat]').click();
    const frame=await page.locator('iframe').elementHandle().then(node=>node.contentFrame());
    await frame.waitForFunction(()=>document.documentElement.dataset.vp3OrbEmbed==='1');
    const work=page.locator('.vp3-public-orb-work-tracks');
    await frame.evaluate(()=>parent.postMessage({channel:'vp3-orb',kind:'jobs',ownerKey:'other',source:'image',jobs:[{id:'image:other',expiresAt:Date.now()+5000}]},location.origin));
    await frame.evaluate(()=>VP3AgentOrb.setJobs('development',[{id:'development:local',expiresAt:Date.now()+1300}]));
    await page.locator('[data-orb-work-tracks="1"]').waitFor();
    await page.getByRole('button',{name:'Close Agent Chat',exact:true}).click();
    assert.equal(await work.count(),1);
    await work.waitFor({state:'detached'});
  });
  await check('job rings in reduced motion are static in either appearance',async()=>{
    const still=await browser.newPage({reducedMotion:'reduce'});
    try{
      await still.goto(url);
      await still.evaluate(()=>VP3AgentOrb.setJobs('initiative',[{id:'workflow:15',expiresAt:Date.now()+5000}]));
      const work=still.locator('[data-orb-work-tracks="1"]');
      await work.waitFor();
      const before=await work.evaluate(node=>node.toDataURL());
      await still.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:agent-orb-shape',{detail:{shape:'particle_face'}})));
      await still.waitForTimeout(110);
      assert.equal(await still.locator('[data-vp3-orb-work]').getAttribute('data-orb-shape'),'particle_face');
      assert.equal(await work.evaluate(node=>node.toDataURL()),before);
    }finally{await still.close();}
  });
  for(const viewport of [{width:1280,height:850},{width:390,height:844}]){
    const main=await context.newPage();await main.setViewportSize(viewport);main.on('pageerror',e=>errors.push(e.message));await main.goto(url+'?main=1');await main.waitForFunction(()=>window.VP3AgentOrb?.open);
    await check(`Cloud Chat opens a centered live canvas with the orb outside at ${viewport.width}px`,async()=>{
      await main.locator('#chatInput').fill('Keep this draft');
      await main.evaluate(() => VP3AgentOrb.setJobs('mission', [{id:'mission:layout', expiresAt:Date.now() + 10000}]));
      await main.locator('[data-orb-chat]').click();
      await main.locator('#vp3AgentChatPopup').waitFor({state:'visible'});
      await checkPopupLayout(main, viewport);
      assert.equal(await main.locator('#vp3AgentChatPopup #chatThread .message').innerText(),'Existing conversation');
      assert.equal(await main.locator('#chatThread').getAttribute('data-view'),'chat');
      assert.equal(await main.locator('iframe').count(),0);
      assert.equal(await main.locator('#chatForm').count(),1);
      assert.equal(await main.locator('#chatVoiceButton').count(),1);
      assert.equal(await main.locator('#chatInput').inputValue(),'Keep this draft');
      assert.equal(await main.evaluate(()=>document.getElementById('chatInput')===originalInput&&document.getElementById('chatThread')===originalThread&&sent===0),true);
      await main.locator('#chatComposerShell').evaluate(node => { node.style.minHeight = '140px'; });
      await main.waitForFunction(() => {
        const popup = document.getElementById('vp3AgentChatPopup').getBoundingClientRect();
        const footer = document.getElementById('chatComposerShell').getBoundingClientRect();
        return popup.bottom <= footer.top - 7;
      });
      await checkPopupLayout(main, viewport);
      await main.locator('#chatComposerShell').evaluate(node => { node.style.minHeight = ''; });
      await main.evaluate(() => VP3AgentOrb.setJobs('mission', []));
    });
    await check(`live reply and Escape preserve current conversation and workspace at ${viewport.width}px`,async()=>{await main.evaluate(()=>document.getElementById('chatForm').requestSubmit());assert.equal(await main.locator('#vp3AgentChatPopup .message').last().innerText(),'New reply 1');await main.keyboard.press('Escape');assert.equal(await main.locator('#vp3AgentChatPopup').isVisible(),false);assert.equal(await main.locator('.chat-main > #chatThread').count(),1);assert.equal(await main.locator('#chatThread').getAttribute('data-view'),'player');assert.equal(await main.locator('[data-chat-view="player"]').isVisible(),true);assert.equal(await main.locator('#chatInput').inputValue(),'Keep this draft');await main.locator('[data-orb-chat]').click();assert.equal(await main.locator('#vp3AgentChatPopup .message').count(),2);await main.getByRole('button',{name:'Close Agent Chat',exact:true}).click();assert.equal(await main.evaluate(()=>sent),1);assert.equal(await main.locator('[data-orb-chat]').getAttribute('aria-expanded'),'false');});
    await check(`background status stays in the animated orb at ${viewport.width}px and supports hover, focus and Escape`,async()=>{
      const orb=main.locator('.vp3-orb-ball'),status=main.locator('.vp3-orb-initiative');
      await main.mouse.move(2,2);await main.evaluate(()=>document.activeElement.blur());
      await main.evaluate(()=>window.VP3AgentOrb.setInitiativeStatus({
        label:'No background worker has been observed. This open Chat can advance permitted work.',
        detail:'Uses no personal Knowledge for permitted private work. Sources last checked: Today.',
        setting:'off'
      },action=>window.lastInitiativeAction=action));
      assert.equal(await main.locator('#chatThread [data-agent-initiative-status]').count(),0);
      assert.equal(await status.isVisible(),false);
      assert.equal(await orb.locator('[data-orb-ring="pulse"]').evaluate(node=>getComputedStyle(node).animationName),'vp3-public-orb-pulse');
      await orb.hover();
      await status.getByText(/No background worker has been observed/).waitFor();
      assert.match(await status.locator('small').innerText(),/Uses no personal Knowledge.*Sources last checked/);
      const box=await status.boundingBox();
      assert.ok(box.x>=0&&box.y>=0&&box.x+box.width<=viewport.width&&box.y+box.height<=viewport.height);
      await status.getByRole('button',{name:'Pause'}).click();
      assert.equal(await main.evaluate(()=>window.lastInitiativeAction),'off');
      await main.mouse.move(2,2);await main.evaluate(()=>document.activeElement.blur());
      await status.waitFor({state:'hidden'});
      await orb.focus();await status.waitFor({state:'visible'});
      assert.equal(await orb.getAttribute('aria-describedby'),'vp3OrbInitiativeDetails');
      await main.keyboard.press('Escape');await status.waitFor({state:'hidden'});
      await main.evaluate(()=>window.VP3AgentOrb.setInitiativeStatus(null));
      assert.equal(await orb.getAttribute('aria-describedby'),null);
    });
    await main.close();
  }
  await check('touch reveals the orb status without clipping on a narrow screen',async()=>{
    const mobile=await browser.newContext({viewport:{width:320,height:600},isMobile:true,hasTouch:true});
    try{
      const touch=await mobile.newPage();
      await touch.goto(url+'?main=1');
      const status=touch.locator('.vp3-orb-initiative');
      await touch.evaluate(()=>window.VP3AgentOrb.setInitiativeStatus({label:'Touch background status',detail:'Sources last checked: Today.'}));
      await touch.evaluate(() => VP3AgentOrb.setJobs('mission', [{id:'mission:layout', expiresAt:Date.now() + 10000}]));
      assert.equal(await status.isVisible(),false);
      await touch.locator('.vp3-orb-ball').tap();
      await status.waitFor({state:'visible'});
      await checkPopupLayout(touch, {width:320,height:600});
      const bounds=await status.boundingBox();
      assert.ok(bounds.x>=0&&bounds.x+bounds.width<=320&&bounds.y>=0&&bounds.y+bounds.height<=600);
    }finally{await mobile.close();}
  });
  assert.deepEqual(errors,[]);await context.close();console.log(`AGENT_ORB_CLOUD_BROWSER=PASS (${checks} cases)`);
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
