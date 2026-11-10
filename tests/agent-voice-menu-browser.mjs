import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {execFileSync} from 'node:child_process';
import {chromium} from 'playwright';
const read=name=>fs.readFileSync(name,'utf8');
const markup=execFileSync(process.env.VP3_PHP_BINARY||'php',['tests/member-agent-voice-fixture.php'],{encoding:'utf8'});
const chatMarkup=execFileSync(process.env.VP3_PHP_BINARY||'php',['tests/member-agent-voice-fixture.php','main-chat'],{encoding:'utf8'});
let agents=[{id:7,display_name:'Longfellow',is_default:1,is_active:1,voice_enabled:0}],enabled=true,autoGreet=false,autoGreetReady=true,savedVoice={voice_id:'',voice_name:''},writes=[];
const server=http.createServer(async(req,res)=>{
 const url=new URL(req.url,'http://test');
 if(url.pathname.startsWith('/api/')){
  const chunks=[];for await(const chunk of req)chunks.push(chunk);const body=JSON.parse(Buffer.concat(chunks).toString()||'{}');
  if(req.method==='POST')writes.push({path:url.pathname,...body});
  let result={ok:true};
  if(url.pathname.includes('user-agent-system')){
   if(body.action==='set_default_agent')agents=agents.map(a=>({...a,is_default:Number(a.id===body.agent_id)}));
   result.state={agents,system_voice:savedVoice};
  }else if(url.pathname.includes('studio-voice-profile'))result.state={voice:{has_clone_binding:true,clone_enabled:true}};
  else if(url.pathname.includes('chat-settings')){
   if(body.action==='save_agent_voice')enabled=body.agent_voice_enabled;
   if(body.action==='save_auto_greet')autoGreet=body.auto_greet_enabled;
   result={ok:true,agent_voice_allowed:true,agent_auto_greet_allowed:true,agent_auto_greet_ready:autoGreetReady,chat:{agent_voice_enabled:enabled,auto_greet_enabled:autoGreet}};
  }
  else if(url.pathname.includes('agent-voice-settings')){
   if(body.action==='save_system_voice')savedVoice={voice_id:body.voice_id,voice_name:body.voice_id?'George':''};
   result={ok:true,selected:savedVoice,voices:[{voice_id:'GeorgeVoice123',name:'George'}]};
  }else result={ok:true,ready:true,verified:true,voice_name:savedVoice.voice_name||'Configured voice',voice_source:'account_default'};
  res.setHeader('Content-Type','application/json');res.end(JSON.stringify(result));return;
 }
 if(url.pathname.endsWith('.js')||url.pathname.endsWith('.css')){res.setHeader('Content-Type',url.pathname.endsWith('.css')?'text/css':'text/javascript');res.end(read(url.pathname.slice(1)));return;}
 const actualMarkup=url.pathname==='/chat'?chatMarkup:markup;
 const pageMarkup=url.searchParams.has('legacy')?actualMarkup.replaceAll('data-vp3-global-agent-voice',''):actualMarkup;
 const menuScript='<script src="/member-agent-voice-menu.js"></script>',settingsScript=url.pathname==='/chat'?'<script src="/chat-settings-v237.js"></script>':'';
 const runtimeScripts=url.searchParams.has('reverse')?settingsScript+menuScript:menuScript+settingsScript;
 res.setHeader('Content-Type','text/html');res.end(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/chat-header-ui.css"><link rel="stylesheet" href="/member-agent-voice-menu.css"><link rel="stylesheet" href="/agent-orb.css"><style>body{margin:0;font:14px system-ui}.chat-top-dropdown{position:fixed;right:10px;top:50px}</style><main><h1>${url.pathname==='/chat'?'Agent Chat':'Account'}</h1>${pageMarkup}</main><script>
window.originalVoiceToggle=document.querySelector('[data-agent-voice-toggle]');document.getElementById('chatProfileButton').addEventListener('click',()=>document.getElementById('chatProfileDropdown').hidden=!document.getElementById('chatProfileDropdown').hidden);
window.speechCount=0;window.busy=false;window.VP3_ORB_ADAPTER={ownerKey:'1',chatUrl:'/chat',isMainChat:()=>location.pathname==='/chat',state:()=>({state:'idle'}),busy:()=>window.busy,greet:async()=>{speechCount++;return window.allowAudio!==false;},responseWindow:()=>{window.responseStarts=(window.responseStarts||0)+1;return true;},cancelResponseWindow:()=>{window.responseCancels=(window.responseCancels||0)+1;}};
window.VP3EyesCore={visualState:v=>({state:v.state||'idle',recording:false}),followPoint:()=>null};
window.STONEFELLOW_CHAT_SETTINGS={endpoint:'/api/chat-settings-v237.php',csrf:'test'};
window.permission={state:'granted',onchange:null};Object.defineProperty(navigator,'permissions',{value:{query:async()=>permission}});
window.mediaCalls=0;Object.defineProperty(navigator,'mediaDevices',{value:{getUserMedia:async()=>{mediaCalls++;return {getTracks:()=>[{stop(){}}]};}}});
</script>${runtimeScripts}<script src="/agent-orb.js"></script><script src="/agent-orb-motion.js"></script><script src="/agent-welcome.js"></script><script src="/agent-response-window.js"></script>`);
});
await new Promise(r=>server.listen(0,'127.0.0.1',r));const base=`http://127.0.0.1:${server.address().port}`;
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});let checks=0;
const check=async(name,fn)=>{await fn();console.log('PASS '+name);checks++;};
try{
 const context=await browser.newContext({viewport:{width:1280,height:800}}),page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base+'/chat');await page.waitForFunction(()=>window.VP3_AGENT_PREFERENCE_STATE?.agent);
 await check('one upper voice toggle with both real menu and Chat settings runtimes',async()=>{await page.locator('#chatProfileButton').click();assert.equal(await page.locator('#chatProfileDropdown input[aria-label="Agent Voice"]').count(),1);assert.equal(await page.locator('#chatProfileDropdown .chat-profile-summary input[aria-label="Agent Voice"]').count(),1);assert.equal(await page.locator('.vp3-agent-voice-dashboard input[aria-label="Agent Voice"]').count(),0);});
 await check('Agent Voice and Auto Greet stay aligned and independently persist',async()=>{
  const voice=page.getByRole('checkbox',{name:'Agent Voice',exact:true});
  const greet=page.getByRole('checkbox',{name:'Auto Greet',exact:true});
  const row=page.getByRole('group',{name:'Agent voice preferences'});
  assert.equal(await row.getByRole('checkbox').count(),2);
  const voiceBox=await voice.boundingBox(),greetBox=await greet.boundingBox();
  assert.equal(voiceBox.y,greetBox.y);
  assert.ok(voiceBox.x+voiceBox.width<greetBox.x);
  assert.ok(await voice.isChecked());
  assert.equal(await greet.isChecked(),false);
  if(process.env.VP3_AGENT_MENU_SCREENSHOTS)await page.screenshot({path:process.env.VP3_AGENT_MENU_SCREENSHOTS+'/agent-menu-desktop.png'});
  await page.evaluate(()=>{window.lastAutoGreet=null;window.addEventListener('vp3:agent-auto-greet',event=>{window.lastAutoGreet=event.detail.enabled;});});
  await greet.focus();await page.keyboard.press('Space');
  await page.waitForFunction(()=>window.lastAutoGreet===true);
  assert.equal(writes.filter(w=>w.action==='save_auto_greet'&&w.auto_greet_enabled===true).length,1);
  assert.equal(writes.filter(w=>w.action==='save_agent_voice').length,0);
  assert.ok(await voice.isChecked());
  await page.reload();
  await page.waitForFunction(()=>window.VP3_AGENT_PREFERENCE_STATE?.autoGreetEnabled===true);
  await page.locator('#chatProfileButton').click();
  assert.ok(await greet.isChecked());
  await greet.uncheck();
  await page.waitForFunction(()=>window.VP3_AGENT_PREFERENCE_STATE?.autoGreetEnabled===false);
  assert.equal(writes.filter(w=>w.action==='save_auto_greet'&&w.auto_greet_enabled===false).length,1);
  assert.ok(await voice.isChecked());
 });
 await check('320px menu keeps both switches on one row and explains unavailable storage',async()=>{
  const narrow=await browser.newContext({viewport:{width:320,height:680},hasTouch:true});
  const small=await narrow.newPage();
  autoGreetReady=false;
  try {
   await small.goto(base+'/chat');await small.waitForFunction(()=>window.VP3_AGENT_PREFERENCE_STATE?.agent);
   await small.locator('#chatProfileButton').tap();
   const voice=small.getByRole('checkbox',{name:'Agent Voice',exact:true});
   const greet=small.getByRole('checkbox',{name:'Auto Greet',exact:true});
   const voiceBox=await voice.boundingBox(),greetBox=await greet.boundingBox();
   assert.equal(voiceBox.y,greetBox.y);
   assert.ok(voiceBox.x>=0&&greetBox.x+greetBox.width<=320);
   assert.ok(await voice.isEnabled());
   assert.ok(await greet.isDisabled());
   assert.match(await small.locator('label').filter({has:greet}).getAttribute('title'),/Database Upgrade/);
   if(process.env.VP3_AGENT_MENU_SCREENSHOTS)await small.screenshot({path:process.env.VP3_AGENT_MENU_SCREENSHOTS+'/agent-menu-mobile.png'});
  } finally {autoGreetReady=true;await narrow.close();}
 });
 await check('a cross-tab Auto Greet opt-out is delivered while settings are still loading',async()=>{
  const early=await context.newPage();
  let resolveSettings;
  const waitingForSettings=new Promise(resolve=>{resolveSettings=resolve;});
  await early.route('**/api/chat-settings-v237.php?*',resolveSettings);
  await early.addInitScript(()=>{
   window.STONEFELLOW_CHAT={autoGreetEnabled:true};
   window.earlyAutoGreet=[];
   window.addEventListener('vp3:agent-auto-greet',event=>window.earlyAutoGreet.push(event.detail));
  });
  await early.goto(base+'/account');
  await waitingForSettings;
  try {
   await page.evaluate(()=>localStorage.setItem('vp3:auto-greet-sync:1',JSON.stringify({enabled:false,at:Date.now()})));
   await early.waitForFunction(()=>window.earlyAutoGreet.some(event=>event.enabled===false&&event.fromStorage===true));
   assert.equal(await early.evaluate(()=>window.STONEFELLOW_CHAT.autoGreetEnabled),false);
  } finally {await early.close();}
 });
 await check('an authorized read cancels a stale Auto Greet bootstrap',async()=>{
  const stale=await context.newPage();
  await stale.addInitScript(()=>{
   window.STONEFELLOW_CHAT={autoGreetEnabled:true};
   window.addEventListener('vp3:agent-auto-greet',event=>{window.staleAutoGreet=event.detail;});
  });
  try {
   await stale.goto(base+'/chat');
   await stale.waitForFunction(()=>window.staleAutoGreet?.enabled===false&&window.staleAutoGreet?.source==='settings-read');
   assert.equal(await stale.evaluate(()=>window.STONEFELLOW_CHAT.autoGreetEnabled),false);
  } finally {await stale.close();}
 });
 await check('Chat skips the Orb greeting with Agent Voice and microphone permission enabled',async()=>{for(let index=0;index<2;index++){await page.waitForFunction(()=>window.VP3_AGENT_PREFERENCE_STATE?.agent);assert.equal(await page.evaluate(()=>speechCount),0);assert.equal(await page.evaluate(()=>mediaCalls),0);assert.equal(await page.locator('.vp3-agent-setup-notice').count(),0);if(index===0)await page.reload();}});
 await check('saved microphone permission still greets once outside Chat without requesting capture',async()=>{const account=await context.newPage();await account.goto(base+'/account');await account.waitForFunction(()=>speechCount===1);assert.equal(await account.evaluate(()=>mediaCalls),0);await account.reload();await account.waitForFunction(()=>window.VP3_AGENT_PREFERENCE_STATE?.agent);assert.equal(await account.evaluate(()=>speechCount),0);await account.close();});
 await page.locator('#chatProfileButton').click();
 await check('actual system voice dropdown persists provider choice',async()=>{await page.waitForSelector('[data-vp3-system-voice] option[value="GeorgeVoice123"]',{state:'attached'});await page.locator('[data-vp3-system-voice]').selectOption('GeorgeVoice123');await page.waitForFunction(()=>document.querySelector('[data-vp3-system-voice-status]').textContent.includes('saved'));assert.equal(savedVoice.voice_id,'GeorgeVoice123');assert.equal(writes.filter(w=>w.action==='save_system_voice').length,1);});
 await check('single master mutation persists and follows navigation to another member page',async()=>{await page.locator('[data-vp3-global-agent-voice]').uncheck();await page.waitForFunction(()=>window.VP3_AGENT_PREFERENCE_STATE?.enabled===false);assert.equal(writes.filter(w=>w.action==='save_agent_voice').length,1);await page.goto(base+'/account');await page.waitForFunction(()=>window.VP3_AGENT_PREFERENCE_STATE?.enabled===false);await page.locator('#chatProfileButton').click();assert.equal(await page.locator('[data-vp3-global-agent-voice]').isChecked(),false);assert.equal(await page.locator('[data-vp3-global-agent-voice]').count(),1);assert.equal(await page.locator('[data-vp3-system-voice]').inputValue(),'GeorgeVoice123');});
 await check('unfinished default selection persists reminder until saved',async()=>{agents=[...agents.map(a=>({...a,is_default:0})),{id:8,display_name:'Guide',is_default:0,is_active:1}];await page.reload();await page.waitForSelector('.vp3-agent-setup-notice:not([hidden])');assert.match(await page.locator('.vp3-agent-setup-notice').innerText(),/Choose your default agent/);await page.locator('.vp3-agent-setup-notice button').click();await page.locator('[data-vp3-agent-selector]').selectOption('8');await page.waitForFunction(()=>window.VP3_AGENT_PREFERENCE_STATE?.agent?.id===8);assert.equal(agents.find(a=>a.id===8).is_default,1);assert.equal(await page.locator('.vp3-agent-setup-notice').isVisible(),false);});
 await check('reply window starts only after completed spoken question with voice enabled',async()=>{await page.locator('[data-vp3-global-agent-voice]').check();await page.waitForFunction(()=>window.VP3_AGENT_PREFERENCE_STATE.enabled);await page.evaluate(()=>{window.responseStarts=0;window.dispatchEvent(new CustomEvent('stonefellow:agent-proactive-speech',{detail:{state:'start',text:'What should I call you?'}}));});assert.equal(await page.evaluate(()=>responseStarts),0);await page.evaluate(()=>window.dispatchEvent(new CustomEvent('stonefellow:agent-proactive-speech',{detail:{state:'end',text:'What should I call you?',completed:true}})));await page.waitForFunction(()=>responseStarts===1);await page.evaluate(()=>{for(const detail of [{state:'start',text:'Cancel?'},{state:'end',text:'Cancel?',completed:false},{state:'start',text:'Information only.'},{state:'end',text:'Information only.',completed:true}])window.dispatchEvent(new CustomEvent('stonefellow:agent-proactive-speech',{detail}));});assert.equal(await page.evaluate(()=>responseStarts),1);});
 await check('Eyes start, permission denial and Eyes stop obey bounded listening authority',async()=>{await page.evaluate(()=>window.dispatchEvent(new Event('vp3:agent-eyes-started')));await page.waitForFunction(()=>responseStarts===2);await page.evaluate(()=>{permission.state='denied';window.dispatchEvent(new Event('vp3:agent-eyes-started'));});await page.waitForTimeout(50);assert.equal(await page.evaluate(()=>responseStarts),2);await page.evaluate(()=>window.dispatchEvent(new Event('vp3:agent-eyes-stopped')));assert.ok(await page.evaluate(()=>responseCancels)>0);});
 await page.locator('#chatProfileButton').click();
 await check('orb moves within viewport with camera off, freezes near pointer and respects reduced motion',async()=>{await page.clock.install();await page.mouse.move(600,400);const before=await page.locator('.vp3-orb-root').boundingBox();await page.clock.runFor(10000);const moved=await page.locator('.vp3-orb-root').boundingBox();assert.ok(Math.hypot(moved.x-before.x,moved.y-before.y)>15);assert.ok(moved.x>=0&&moved.y>=0&&moved.x+moved.width<=1280&&moved.y+moved.height<=800);await page.mouse.move(moved.x+10,moved.y+10);const paused=await page.locator('.vp3-orb-root').boundingBox();await page.clock.runFor(3000);assert.deepEqual(await page.locator('.vp3-orb-root').boundingBox(),paused);await page.mouse.move(600,400);await page.emulateMedia({reducedMotion:'reduce'});const reduced=await page.locator('.vp3-orb-root').boundingBox();await page.clock.runFor(5000);assert.deepEqual(await page.locator('.vp3-orb-root').boundingBox(),reduced);assert.equal(await page.locator('[data-orb-motion]').isDisabled(),true);});
 await check('orb controls hide until hover or keyboard focus and remain available by touch',async()=>{await page.mouse.move(500,400);await page.evaluate(()=>document.activeElement?.blur());assert.equal(await page.locator('.vp3-orb-controls').evaluate(n=>getComputedStyle(n).visibility),'hidden');await page.locator('.vp3-orb-root').hover();assert.equal(await page.locator('.vp3-orb-controls').evaluate(n=>getComputedStyle(n).visibility),'visible');await page.mouse.move(500,400);await page.locator('.vp3-orb-ball').focus();assert.equal(await page.locator('.vp3-orb-controls').evaluate(n=>getComputedStyle(n).visibility),'visible');await page.evaluate(()=>{document.activeElement.blur();const ball=document.querySelector('.vp3-orb-ball');ball.dispatchEvent(new PointerEvent('pointerdown',{bubbles:true,pointerType:'touch',button:1}));});assert.equal(await page.locator('.vp3-orb-controls').evaluate(n=>getComputedStyle(n).visibility),'visible');await page.locator('h1').click();assert.equal(await page.locator('.vp3-orb-controls').evaluate(n=>getComputedStyle(n).visibility),'hidden');});
 for(const route of ['/chat?legacy=1','/chat?legacy=1&reverse=1','/account?legacy=1'])await check('legacy upper toggle is adopted once on '+route,async()=>{
  const isolated=await browser.newContext(),p=await isolated.newPage();await p.goto(base+route);await p.waitForFunction(()=>window.VP3_AGENT_PREFERENCE_STATE?.agent);
  await p.locator('#chatProfileButton').click();
  assert.equal(await p.locator('#chatProfileDropdown input[aria-label="Agent Voice"]').count(),1);
  assert.equal(await p.evaluate(()=>document.querySelector('[data-vp3-global-agent-voice]')===originalVoiceToggle),true,'Keep the original upper control');
  const before=writes.filter(w=>w.action==='save_agent_voice').length;await p.locator('[data-vp3-global-agent-voice]').uncheck();await p.waitForFunction(()=>VP3_AGENT_PREFERENCE_STATE.enabled===false);
  assert.equal(writes.filter(w=>w.action==='save_agent_voice').length,before+1,'One change produces exactly one request');
  await p.locator('[data-vp3-global-agent-voice]').check();await p.waitForFunction(()=>VP3_AGENT_PREFERENCE_STATE.enabled===true);
  await p.evaluate(()=>{const duplicate=originalVoiceToggle.closest('label').cloneNode(true);duplicate.querySelector('input').removeAttribute('data-vp3-global-agent-voice');document.querySelector('[data-vp3-agent-voice-body]').append(duplicate);});
  await p.waitForFunction(()=>document.querySelectorAll('#chatProfileDropdown input[aria-label="Agent Voice"]').length===1);
  assert.equal(await p.evaluate(()=>document.querySelector('[data-vp3-global-agent-voice]')===originalVoiceToggle),true);
  await isolated.close();
 });
 assert.deepEqual(errors,[]);await context.close();console.log(`AGENT_VOICE_MENU_BROWSER=PASS (${checks} cases)`);
}finally{await browser.close();await new Promise(r=>server.close(r));}
