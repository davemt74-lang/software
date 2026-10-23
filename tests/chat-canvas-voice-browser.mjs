import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const root = new URL('../', import.meta.url);
const source = name => fs.readFile(new URL(name, root), 'utf8');
const browser = await chromium.launch({headless:true,
  ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {})});
const page = await browser.newPage({viewport:{width:1280,height:800}});
page.setDefaultTimeout(10000);
const errors = [], calls = [];
page.on('pageerror', error => errors.push(error.message));
let routeDelay = 0, failRoute = false, routeGate = null, sendGate = null, failSend = false, failHistory = false, failLoad = false;
let streamState = 'success';
let loadGate = null;
const messages = new Map([[42,[]],[43,[{id:101,role:'assistant',message:'The other conversation'}]]]);
const html = `<!doctype html><html lang="en-US"><head><meta charset="UTF-8"></head><body>
<div class="chat-app"><aside class="chat-sidebar"><strong>VP3</strong><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside>
<main class="chat-main"><header class="chat-topbar">Agent Chat</header><section class="chat-thread" id="chatThread"><div id="chatWelcome" hidden></div>
${'<article class="message"><div class="message-body">Conversation history</div></article>'.repeat(40)}</section>
<div class="chat-composer-shell" id="chatComposerShell"><form class="chat-composer" id="chatForm"><button id="chatAgentBriefButton" type="button" aria-label="Agent status">✦</button><textarea id="chatInput" rows="1" aria-label="Message"></textarea><button type="button" class="chat-voice-button" id="chatVoiceButton" aria-label="Start voice conversation">◉</button><button type="submit" id="sendChatButton" aria-label="Send message">↑</button></form><div id="chatVoiceStatus" class="chat-voice-status" hidden></div></div></main></div></body></html>`;
await page.route('http://chat.test/', route => route.fulfill({contentType:'text/html',body:html}));
await page.route('**/api/**', async route => {
  const payload = route.request().postDataJSON() || {};
  calls.push({...payload, path:new URL(route.request().url()).pathname});
  if(new URL(route.request().url()).pathname==='/api/chat-stream-v121.php'){
    if(sendGate)await sendGate;
    const event=streamState==='success'?{type:'done',data:{ok:true,conversation_id:42,answer:'Completed streamed reply',assistant_message_id:74}}:streamState==='auth'?{type:'error',error:'Sign in required'}:{type:'delta',delta:'Unconfirmed partial reply'};
    return route.fulfill({status:streamState==='auth'?401:200,contentType:'application/x-ndjson',body:JSON.stringify(event)+'\n'});
  }
  if(new URL(route.request().url()).pathname==='/api/research')return route.fulfill({json:{ok:true,contract:'vp3.chat-browser-research.v1',requests:[],runtimes:[],projects:[]}});
  if (payload.action === 'task.route') {
    if (routeGate) await routeGate;
    if (routeDelay) await new Promise(resolve => setTimeout(resolve, routeDelay));
    return route.fulfill(failRoute ? {status:503,json:{ok:false,error:'HomeServer routing unavailable'}} : {json:{ok:true,route:'chat'}});
  }
  if (payload.action === 'send') {
    if(sendGate)await sendGate;
    if(failSend)return route.fulfill({status:503,json:{ok:false,error:'Provider unavailable'}});
    return route.fulfill({json:{ok:true,conversation_id:payload.conversation_id||42,answer:'I heard you.',assistant_message_id:73}});
  }
  if(payload.action==='load'){
    if(loadGate)await loadGate;
    return route.fulfill(failLoad?{status:503,json:{ok:false,error:'History unavailable'}}:{json:{ok:true,messages:messages.get(Number(payload.conversation_id))||[]}});
  }
  if(payload.action==='history' && failHistory)return route.abort();
  return route.fulfill({json:{ok:true,conversations:[],items:[],schedules:[],updates:[],folders:[]}});
});
// Editor/participant loaders are outside this fixture; no external requests occur.
await page.route('**/*.js?**', route => route.fulfill({contentType:'text/javascript',body:''}));

async function speak(text) {
  await page.evaluate(text => {
    const recognition = window.recognizers.findLast(item => item.active);
    if (!recognition) throw Error('No active speech recognizer');
    const final = [{transcript:text,confidence:.98}]; final.isFinal = true;
    recognition.onresult({resultIndex:0,results:[final]});
  }, text);
}
const sends = () => calls.filter(call => call.action === 'send');
try {
  // Exercise actual browser WebIDL handler setters before replacing the service.
  // This needs no microphone permission or connection to the speech provider.
  await page.goto('http://chat.test/');
  await page.evaluate(()=>{window.STONEFELLOW_CHAT={userId:1};window.NativeRecognition=window.SpeechRecognition||window.webkitSpeechRecognition;});
  assert.equal(await page.evaluate(()=>typeof window.NativeRecognition),'function');
  await page.addScriptTag({content:await source('voice-lease-v122.js')});
  const nativeConstruction=await page.evaluate(()=>{
    const recognizer=new (window.SpeechRecognition||window.webkitSpeechRecognition)();
    for(const name of ['onstart','onresult','onerror','onend','onspeechstart'])recognizer[name]=()=>{};
    recognizer.continuous=false;recognizer.interimResults=true;recognizer.lang='en-US';
    return {continuous:recognizer.continuous,interimResults:recognizer.interimResults,lang:recognizer.lang};
  });
  assert.deepEqual(nativeConstruction,{continuous:false,interimResults:true,lang:'en-US'});
  console.log('PASS lease facade accepts actual Chromium recognition handlers and options');
  async function boot(fast=false) {
  await page.goto('http://chat.test/');
  await page.evaluate(fast => {
    window.STONEFELLOW_CHAT = {userId:1,endpoint:'/api/chat-v236.php',csrf:'fixture',initialView:'chat'};
    localStorage.setItem('stonefellow:voice-mode:1','1');
    window.STONEFELLOW_AGENT_CONTEXT = {userId:1,surface:'chat',csrf:'fixture',proactiveEndpoint:'/api/context'};
    window.STONEFELLOW_AGENT_IDENTITY_V236 = {agentVoiceEnabled:true};
    window.VP3_AGENT_TEAMS_A3 = {endpoint:'/api/agent-missions-cloud-v1.php',csrf:'fixture'};
    window.VP3_CHAT_RESEARCH = {endpoint:'/api/research',csrf:'fixture'};
    window.VP3_PROJECT_KNOWLEDGE = {endpoint:'/api/project-knowledge-r5.php',csrf:'fixture'};
    window.recognizers = []; window.spoken = [];
    window.browserContextConsumptions=0;
    window.VP3_BROWSER_CONTEXT_V2130_RUNTIME={agentContext:()=>({surface:'fixture'}),consume:()=>{window.browserContextConsumptions++;}};
    let requestCounter=0;
    Object.defineProperty(window.crypto,'randomUUID',{value:()=> '44444444-4444-4444-8444-'+String(++requestCounter).padStart(12,'0')});
    window.SpeechRecognition = class {
      constructor() { window.recognizers.push(this); }
      start() { this.active=true; if(!window.hangRecognizer)queueMicrotask(() => this.onstart?.()); }
      stop() { this.active=false; queueMicrotask(() => this.onend?.()); }
      abort() { this.stop(); }
    };
    Object.defineProperty(window, 'speechSynthesis', {value:{cancel(){},speak(utterance){window.spoken.push(utterance.text);queueMicrotask(() => {utterance.onstart?.();utterance.onend?.();});}}});
    window.SpeechSynthesisUtterance = class { constructor(text){this.text=text;} };
    const button = document.getElementById('chatVoiceButton');
    window.STONEFELLOW_CHAT_VOICE_BOOT = {button,intro:null};
    button.id = 'chatVoiceButtonLegacyDormant';
    if(fast)window.StonefellowPremiumVoiceV122=()=>({warm:async()=>false,createStream(){},stop(){}});
  },fast);
  for (const name of ['chat.css','chat-cognitive-presentation-v510.css','chat-agent-teams-a3.css','chat-tools.css']) await page.addStyleTag({content:await source(name)});
  // Real scripts, in production order; the recognition service alone is mocked.
  for (const name of ['chat.js','chat-tools.js','voice-lease-v122.js','agent-context-v131.js','chat-voice.js','chat-agent-teams-a3.js','chat-browser-research-v1.js','project-knowledge-r5.js','project-knowledge-r6.js','project-knowledge-r7.js','knowledge-actions-r8.js']) await page.addScriptTag({content:await source(name)});
  await page.waitForFunction(() => document.querySelectorAll('#chatToolsContent>details').length === 5);
  }
  await boot();
  assert.equal(await page.evaluate(()=>window.recognizers.length),0,'A saved preference cannot reopen the microphone on page load');
  assert.equal(await page.locator('#chatVoiceButton').getAttribute('aria-pressed'),'false');
  assert.equal(await page.locator('#chatToolsPanel').isVisible(), false);
  assert.equal(await page.locator('#chatComposerShell>.project-knowledge-r6').count(), 0);
  assert.equal(await page.locator('#chatToolsContent #chatKnowledgeScopeV162').count(), 1);
  for (const viewport of [{width:1280,height:800},{width:390,height:844}]) {
    await page.setViewportSize(viewport);
    assert.ok((await page.locator('#chatThread').boundingBox()).height > viewport.height * .65, 'Conversation must own the viewport');
    assert.ok((await page.locator('#chatComposerShell').boundingBox()).height < 120, 'Tools must not inflate the composer');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await page.getByRole('button',{name:'Chat tools',exact:true}).click();
    await page.getByLabel('Work mode').selectOption('chat');
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#chatToolsButton').getAttribute('aria-expanded'), 'false');
    assert.equal(await page.locator('#chatToolsButton').evaluate(node => node === document.activeElement), true);
    await page.getByRole('button',{name:'Chat tools',exact:true}).click();
    assert.equal(await page.getByLabel('Work mode').inputValue(), 'chat', 'Closing tools must preserve context');
    await page.getByLabel('Work mode').selectOption('automatic');
    await page.getByRole('button',{name:'Close chat tools'}).click();
  }
  console.log('PASS compact desktop/mobile canvas and stateful tools');
  await page.getByRole('button',{name:'Start voice conversation'}).click();
  await page.waitForFunction(() => window.STONEFELLOW_CHAT_VOICE.starts > 0);
  console.log('PASS microphone starts through real lease');
  await speak('stop listening');
  await page.waitForFunction(()=>document.querySelector('#chatVoiceButton').getAttribute('aria-pressed')==='false');
  assert.equal(sends().length,0,'Stop listening must not send a Chat question');
  await page.getByRole('button',{name:'Start voice conversation'}).click();
  await page.waitForFunction(()=>document.body.dataset.stonefellowAgentState==='listening');
  console.log('PASS spoken stop listening releases capture and permits explicit restart without a Chat request');
  await page.evaluate(() => {
    const recognition=window.recognizers.findLast(item=>item.active);
    const interim=[{transcript:'Please answer',confidence:.98}];interim.isFinal=false;
    recognition.onresult({resultIndex:0,results:[interim]});
  });
  assert.equal(await page.locator('#chatInput').inputValue(),'Please answer');
  routeDelay = 3000;
  await speak('Please answer my question');
  await page.waitForFunction(() => document.querySelector('#chatInput').disabled);
  await page.waitForTimeout(2400);
  assert.doesNotMatch(await page.locator('#chatVoiceStatus').innerText(), /Chat was not submitted/);
  await page.waitForFunction(() => window.STONEFELLOW_CHAT_VOICE.apiSuccess === 1);
  assert.equal(sends().length, 1, 'Delayed routing must dispatch one Chat turn');
  assert.equal(sends()[0].input_mode, 'voice', 'Routing must retain voice identity');
  await page.waitForFunction(() => window.spoken.length === 1 && document.body.dataset.stonefellowAgentState === 'listening');
  routeDelay = 0; failRoute = true;
  await speak('Try a second question');
  await page.waitForFunction(() => document.querySelector('#chatToolsPanel').hidden === false);
  assert.match(await page.locator('.vp3-teams-status').innerText(), /routing unavailable/);
  assert.equal(sends().length, 1, 'An uncertain route must not dispatch or retry Chat');
  await page.getByRole('button',{name:'Close chat tools'}).click();
  await page.waitForFunction(() => document.querySelector('#chatVoiceButton').getAttribute('aria-pressed')==='false');
  assert.equal(await page.locator('#chatInput').inputValue(),'Try a second question','A failed route cannot erase its transcript');
  await page.locator('#chatInput').fill('');
  await page.getByRole('button',{name:'Start voice conversation'}).click();
  await page.waitForFunction(() => document.body.dataset.stonefellowAgentState === 'listening');
  failRoute = false;
  let release; routeGate = new Promise(resolve => {release=resolve;});
  await speak('Cancel this delayed request');
  await page.waitForFunction(() => document.querySelector('#chatInput').disabled);
  await page.locator('#chatVoiceButton').click(); // Interrupt while routing.
  release(); routeGate=null;
  await page.waitForFunction(() => !document.querySelector('#chatInput').disabled);
  assert.equal(sends().length, 1, 'A late route after Stop must never submit');
  assert.equal(await page.locator('#chatInput').inputValue(), 'Cancel this delayed request');
  await page.locator('#chatVoiceButton').click();
  assert.equal(await page.locator('#chatVoiceButton').getAttribute('aria-pressed'), 'false');
  assert.equal(await page.evaluate(() => window.STONEFELLOW_VOICE_LEASE_V122.owner), false);
  await page.getByRole('button',{name:'Chat tools',exact:true}).click();
  await page.getByLabel('Work mode').selectOption('chrome');
  await page.locator('#chatInput').fill('Research a topic');
  await page.locator('#sendChatButton').click();
  assert.equal(await page.locator('#chatToolsPanel').isVisible(),true,'Explicit research must expose its consent tools');
  assert.equal(sends().length,1,'Chrome consent must not submit an ordinary Chat turn');
  await page.getByLabel('Work mode').selectOption('automatic');
  await page.getByRole('button',{name:'Close chat tools'}).click();
  await page.locator('#chatInput').fill('');
  await page.evaluate(()=>{window.hangRecognizer=true;});
  await page.getByRole('button',{name:'Start voice conversation'}).click();
  await page.waitForFunction(()=>document.querySelector('#chatVoiceButton').getAttribute('aria-pressed')==='false');
  assert.match(await page.locator('#chatVoiceStatus').innerText(),/did not start.*retry/);
  assert.equal(await page.locator('#chatVoiceButton').getAttribute('data-agent-state'),'error');
  assert.equal(await page.evaluate(()=>window.STONEFELLOW_VOICE_LEASE_V122.owner),false);
  // Actual canonical chat recovery, including history-refresh failure and
  // a late reply after navigation. No recovery path replays a send.
  await page.getByRole('button',{name:'Chat tools',exact:true}).click();
  await page.getByLabel('Work mode').selectOption('chat');
  await page.getByRole('button',{name:'Close chat tools'}).click();
  failSend=true;
  await page.locator('#chatInput').fill('Keep this provider failure');
  await page.locator('#sendChatButton').click();
  await page.waitForFunction(()=>!document.querySelector('#sendChatButton').disabled && document.querySelector('#chatRecoveryStatus')?.hidden===false);
  assert.equal(await page.locator('#chatInput').inputValue(),'Keep this provider failure');
  await page.evaluate(()=>{
    const body=document.createElement('aside');body.id='testBrain';document.body.appendChild(body);window.VP3ChatDiagnostics.renderBrain(body);
  });
  assert.match(await page.locator('#testBrain').innerText(),/Chat session diagnostics/);
  assert.doesNotMatch(await page.locator('#testBrain').innerText(),/Keep this provider failure|Provider unavailable/,'Brain diagnostics hold status codes rather than message/provider content');
  assert.equal(await page.locator('#chatComposerShell #chatSessionDiagnostics').count(),0);
  assert.equal(await page.evaluate(()=>window.browserContextConsumptions),1,'Failed requests must retain one-turn Browser context');
  const failedCount=sends().length;
  let releaseHistory;
  loadGate=new Promise(resolve=>{releaseHistory=resolve;});
  await page.getByRole('button',{name:'Check history',exact:true}).click();
  await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.isRestoring());
  await page.locator('#chatInput').fill('Edited while checking history');
  await page.locator('#sendChatButton').click();
  assert.equal(sends().length,failedCount,'Checking history blocks dispatch but keeps the draft editable');
  releaseHistory();loadGate=null;
  await page.waitForFunction(()=>!window.STONEFELLOW_CHAT_CONTINUITY_V87.isRestoring());
  assert.equal(await page.locator('#chatInput').inputValue(),'Edited while checking history','A delayed history response must preserve edits made while checking');
  await boot();
  await page.waitForFunction(()=>!window.STONEFELLOW_CHAT_CONTINUITY_V87.isRestoring());
  assert.equal(await page.locator('#chatInput').inputValue(),'Edited while checking history','History-time edits survive reload');
  loadGate=new Promise(resolve=>{releaseHistory=resolve;});
  await page.evaluate(()=>{window.pendingHistorySwitch=window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(43);});
  await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.isRestoring());
  await page.locator('#chatInput').fill('Edited before switching away');
  releaseHistory();loadGate=null;
  await page.evaluate(()=>window.pendingHistorySwitch);
  assert.equal(await page.locator('#chatInput').inputValue(),'','Edits belong to the displayed source conversation, not the pending destination');
  await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(42));
  assert.equal(await page.locator('#chatInput').inputValue(),'Edited before switching away','Navigation preserves edits made while its history was loading');
  assert.equal(sends().length,failedCount,'History recovery and navigation never replay the draft');
  // Preserve the existing recovery scenarios' independent draft.
  await page.locator('#chatInput').fill('Keep this provider failure');
  await boot();
  await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===42);
  assert.equal(await page.locator('#chatInput').inputValue(),'Keep this provider failure');
  assert.equal(sends().length,failedCount,'Refresh restores the draft without replay');
  await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(43));
  await page.locator('#chatInput').fill('The other draft');
  await boot();
  await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===43);
  assert.equal(await page.locator('#chatInput').inputValue(),'The other draft','The selected conversation wins over newest history');
  await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(42));
  assert.equal(await page.locator('#chatInput').inputValue(),'Keep this provider failure');
  await page.context().setOffline(true);
  await page.locator('#chatInput').fill('Offline draft');
  await page.locator('#sendChatButton').click();
  assert.match(await page.locator('#chatRecoveryStatus').innerText(),/offline/);
  assert.equal(sends().length,failedCount,'Offline submits never reach the canonical endpoint');
  await page.context().setOffline(false);
  failSend=false;failHistory=true;
  await page.getByRole('button',{name:'Chat tools',exact:true}).click();
  await page.getByLabel('Work mode').selectOption('chat');
  await page.getByRole('button',{name:'Close chat tools'}).click();
  await page.locator('#chatInput').fill('A successful reply with unavailable history');
  await page.locator('#sendChatButton').click();
  await page.waitForFunction(()=>!document.querySelector('#sendChatButton').disabled);
  assert.equal(await page.locator('#chatInput').inputValue(),'','History refresh cannot turn a success into a failure');
  assert.match(await page.locator('#chatThread').innerText(),/I heard you/);
  failHistory=false;
  const consumedBeforeStop=await page.evaluate(()=>window.browserContextConsumptions);
  let releaseSend;sendGate=new Promise(resolve=>{releaseSend=resolve;});
  await page.locator('#chatInput').fill('Stop an in-flight provider response');
  await page.locator('#sendChatButton').click();
  await page.getByRole('button',{name:'Stop response',exact:true}).click();
  releaseSend();sendGate=null;
  assert.equal(await page.locator('#chatInput').inputValue(),'Stop an in-flight provider response');
  assert.equal(await page.locator('#sendChatButton').isEnabled(),true);
  sendGate=new Promise(resolve=>{releaseSend=resolve;});
  await page.locator('#chatInput').fill('An old conversation request');
  await page.locator('#sendChatButton').click();
  await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(43));
  releaseSend();sendGate=null;
  await page.waitForTimeout(200);
  assert.doesNotMatch(await page.locator('#chatThread').innerText(),/I heard you/,'A late response cannot enter a different conversation');
  assert.equal(await page.locator('#chatInput').inputValue(),'The other draft');
  assert.equal(await page.evaluate(()=>window.browserContextConsumptions),consumedBeforeStop,'Stopped or stale responses cannot consume Browser context');
  const countBeforeAuth=sends().length;
  await page.route('**/api/chat-v236.php',route=>route.fulfill({status:401,json:{ok:false,error:'Sign in required'}}));
  await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(42).catch(()=>{}));
  assert.equal(await page.evaluate(()=>sessionStorage.getItem('vp3:chat:drafts:1:0')),null,'Authorization loss clears private recovery state');
  assert.equal(await page.locator('#chatInput').inputValue(),'');
  assert.equal(sends().length,countBeforeAuth);
  console.log('PASS provider failure, selected-context/draft restore, offline recovery, independent history refresh, Stop, late reply fencing and session cleanup without replay');
  await page.unroute('**/api/chat-v236.php');
  const streams=()=>calls.filter(call=>call.path==='/api/chat-stream-v121.php');
  for(const kind of ['stop','truncated','auth']){
    streamState=kind==='auth'?'auth':kind==='truncated'?'truncated':'success';
    await boot(true);
    await page.getByRole('button',{name:'Chat tools',exact:true}).click();
    await page.getByLabel('Work mode').selectOption('chat');
    await page.getByRole('button',{name:'Close chat tools'}).click();
    await page.locator('#chatInput').fill('');
    if(kind==='stop')sendGate=new Promise(resolve=>{releaseSend=resolve;});
    await page.getByRole('button',{name:'Start voice conversation'}).click();
    await page.waitForFunction(()=>window.recognizers.some(item=>item.active));
    const before=streams().length;
    const dispatched=page.waitForRequest('**/api/chat-stream-v121.php');
    await speak('Audit the '+kind+' voice provider turn');
    await dispatched;
    if(kind==='stop'){
      await page.getByRole('button',{name:'Stop response',exact:true}).click();
      releaseSend();sendGate=null;
    }
    await page.waitForFunction(()=>!document.querySelector('#sendChatButton').disabled && document.querySelector('#chatVoiceButton').getAttribute('aria-pressed')==='false');
    await page.waitForTimeout(400);
    assert.equal(streams().length,before+1,'A failed/stopped voice turn must never replay');
    assert.equal(await page.evaluate(()=>window.spoken.length),0,'Unconfirmed or cancelled provider output cannot speak');
    assert.doesNotMatch(await page.locator('#chatThread').innerText(),/Completed streamed reply|Unconfirmed partial reply/);
    if(kind==='auth')assert.equal(await page.evaluate(()=>sessionStorage.getItem('vp3:chat:drafts:1:0')),null);
    else assert.match(await page.locator('#chatInput').inputValue(),new RegExp(kind));
  }
  console.log('PASS fast voice canonical Stop, truncated provider streams and expired sessions preserve owner boundaries without replay');
  assert.deepEqual(errors, []);
  if (process.env.VP3_CHAT_SCREENSHOT) await page.screenshot({path:process.env.VP3_CHAT_SCREENSHOT});
  console.log('PASS compact desktop/mobile canvas; tools preserve state; actual Chat + voice lease + automatic planner retain delayed voice input, speak once, resume listening, and reject failed/cancelled late routes.');
} catch(error) { console.error('PAGE_ERRORS',errors,'API_CALLS',calls); throw error; } finally { await browser.close(); }
