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
let routeDelay = 0, failRoute = false, routeGate = null;
const html = `<!doctype html><html lang="en-US"><head><meta charset="UTF-8"></head><body>
<div class="chat-app"><aside class="chat-sidebar"><strong>VP3</strong><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside>
<main class="chat-main"><header class="chat-topbar">Agent Chat</header><section class="chat-thread" id="chatThread"><div id="chatWelcome" hidden></div>
${'<article class="message"><div class="message-body">Conversation history</div></article>'.repeat(40)}</section>
<div class="chat-composer-shell" id="chatComposerShell"><form class="chat-composer" id="chatForm"><button id="chatAgentBriefButton" type="button" aria-label="Agent status">✦</button><textarea id="chatInput" rows="1" aria-label="Message"></textarea><button type="button" class="chat-voice-button" id="chatVoiceButton" aria-label="Start voice conversation">◉</button><button type="submit" id="sendChatButton" aria-label="Send message">↑</button></form><div id="chatVoiceStatus" class="chat-voice-status" hidden></div></div></main></div></body></html>`;
await page.route('http://chat.test/', route => route.fulfill({contentType:'text/html',body:html}));
await page.route('**/api/**', async route => {
  const payload = route.request().postDataJSON() || {};
  calls.push({...payload, path:new URL(route.request().url()).pathname});
  if(new URL(route.request().url()).pathname==='/api/research')return route.fulfill({json:{ok:true,contract:'vp3.chat-browser-research.v1',requests:[],runtimes:[],projects:[]}});
  if (payload.action === 'task.route') {
    if (routeGate) await routeGate;
    if (routeDelay) await new Promise(resolve => setTimeout(resolve, routeDelay));
    return route.fulfill(failRoute ? {status:503,json:{ok:false,error:'HomeServer routing unavailable'}} : {json:{ok:true,route:'chat'}});
  }
  if (payload.action === 'send') return route.fulfill({json:{ok:true,conversation_id:42,answer:'I heard you.',assistant_message_id:73}});
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
  await page.goto('http://chat.test/');
  await page.evaluate(() => {
    window.STONEFELLOW_CHAT = {userId:1,endpoint:'/api/chat-v236.php',csrf:'fixture',initialView:'chat'};
    window.STONEFELLOW_AGENT_CONTEXT = {userId:1,surface:'chat',csrf:'fixture',proactiveEndpoint:'/api/context'};
    window.STONEFELLOW_AGENT_IDENTITY_V236 = {agentVoiceEnabled:true};
    window.VP3_AGENT_TEAMS_A3 = {endpoint:'/api/agent-missions-cloud-v1.php',csrf:'fixture'};
    window.VP3_CHAT_RESEARCH = {endpoint:'/api/research',csrf:'fixture'};
    window.VP3_PROJECT_KNOWLEDGE = {endpoint:'/api/project-knowledge-r5.php',csrf:'fixture'};
    window.recognizers = []; window.spoken = [];
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
  });
  for (const name of ['chat.css','chat-cognitive-presentation-v510.css','chat-agent-teams-a3.css','chat-tools.css']) await page.addStyleTag({content:await source(name)});
  // Real scripts, in production order; the recognition service alone is mocked.
  for (const name of ['chat.js','chat-tools.js','voice-lease-v122.js','agent-context-v131.js','chat-voice.js','chat-agent-teams-a3.js','chat-browser-research-v1.js','project-knowledge-r5.js','project-knowledge-r6.js','project-knowledge-r7.js','knowledge-actions-r8.js']) await page.addScriptTag({content:await source(name)});
  await page.waitForFunction(() => document.querySelectorAll('#chatToolsContent>details').length === 5);
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
  await page.evaluate(()=>{window.hangRecognizer=true;});
  await page.getByRole('button',{name:'Start voice conversation'}).click();
  await page.waitForFunction(()=>document.querySelector('#chatVoiceButton').getAttribute('aria-pressed')==='false');
  assert.match(await page.locator('#chatVoiceStatus').innerText(),/did not start.*retry/);
  assert.equal(await page.locator('#chatVoiceButton').getAttribute('data-agent-state'),'error');
  assert.equal(await page.evaluate(()=>window.STONEFELLOW_VOICE_LEASE_V122.owner),false);
  assert.deepEqual(errors, []);
  if (process.env.VP3_CHAT_SCREENSHOT) await page.screenshot({path:process.env.VP3_CHAT_SCREENSHOT});
  console.log('PASS compact desktop/mobile canvas; tools preserve state; actual Chat + voice lease + automatic planner retain delayed voice input, speak once, resume listening, and reject failed/cancelled late routes.');
} catch(error) { console.error('PAGE_ERRORS',errors,'API_CALLS',calls); throw error; } finally { await browser.close(); }
