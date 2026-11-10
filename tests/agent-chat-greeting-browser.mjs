import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFile} from 'node:fs/promises';
import {createRequire} from 'node:module';

const {chromium} = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const asset = name => readFile(new URL('../' + name,import.meta.url),'utf8');
const menu = execFileSync(process.env.VP3_PHP_BINARY || 'php',['tests/member-agent-voice-fixture.php','main-chat'],{encoding:'utf8'});
const scripts = ['chat.js','voice-lease-v122.js','chat-voice.js','chat-notifications-drawer-v240.js','member-agent-voice-menu.js','agent-response-window.js','agent-welcome.js'];
const sources = new Map(await Promise.all(scripts.map(async name => [name,await asset(name)])));
const origin = 'http://localhost';
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
const errors = [];

async function fixture({enabled=true,autoGreet=true,permission='granted',blockPlayback=false,blockRecognition=false,selectedParticipant=false,legacy=false,saveAutoGreet}={}) {
  const context = await browser.newContext();
  const page = await context.newPage();
  page.setDefaultTimeout(8000);
  page.on('pageerror',error => errors.push(error.message));
  await page.route(`${origin}/**`,async route => {
    const request = route.request(), path = new URL(request.url()).pathname;
    if (path === '/chat.php') return route.fulfill({contentType:'text/html',body:`<!doctype html><meta charset="utf-8"><main class="chat-main">
      ${menu}
      <button id="newChatButton">New Chat</button><div id="chatHistory"></div>
      <section id="chatThread"><div id="chatWelcome" hidden></div></section>
      <form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button><button type="button" id="chatVoiceButton">Listen</button></form><div id="chatVoiceStatus"></div>
      </main><script>
      window.STONEFELLOW_CHAT={userId:1,agentId:7,initialConversationId:42,initialView:'chat',csrf:'fixture',endpoint:'/api/chat-v236.php',${autoGreet===null?'':`autoGreetEnabled:${autoGreet},`}${legacy?'intro:{token:"legacy-greeting",greeting:"Welcome back. What shall we work on?",updates:[{title:"An optional update"}]},':''}};
      window.STONEFELLOW_AGENT_IDENTITY_V236={displayName:'Longfellow',agentId:7,agentVoiceEnabled:${enabled}};
      window.STONEFELLOW_NOTIFICATION_DRAWER={endpoint:'/api/notifications',csrf:'fixture',agentId:7,userId:1,agentVoiceEnabled:${enabled}};
      window.VP3_ORB_ADAPTER={ownerKey:'1',isMainChat:()=>true,state:()=>({recording:false})};
      if (${selectedParticipant}) window.VP3ParticipantContinuity={selectedContext:()=>({id:8})};
      window.micPermission=${JSON.stringify(permission)};window.blockPlayback=${blockPlayback};window.blockRecognition=${blockRecognition};window.utterances=[];window.playing=null;
      window.permissionReads=0;window.micAcquisitions=0;window.nativeAttempts=0;window.nativeStarts=0;window.nativeLive=0;window.autoListenClicks=0;
      const listen=document.getElementById('chatVoiceButton');
      listen.addEventListener('click',event=>{if(!event.isTrusted)autoListenClicks++;},true);
      window.STONEFELLOW_CHAT_VOICE_BOOT={button:listen,legacyDormant:true};listen.id='chatVoiceButtonLegacyDormant';
      Object.defineProperty(navigator,'permissions',{value:{query:async()=>{permissionReads++;if(window.delayPermission)await new Promise(resolve=>window.releasePermission=resolve);if(micPermission==='unknown')throw Error('Unsupported');return {state:micPermission};}}});
      Object.defineProperty(navigator,'mediaDevices',{value:{getUserMedia:async()=>{micAcquisitions++;const track={readyState:'live',stop(){this.readyState='ended';},addEventListener(){}};window.track=track;return {getTracks:()=>[track],getAudioTracks:()=>[track]};}}});
      window.SpeechRecognition=class {
        start(){nativeAttempts++;if(window.playing)throw Error('Capture during playback');if(blockRecognition)throw new DOMException('Click Listen first','NotAllowedError');this.live=true;nativeStarts++;nativeLive++;queueMicrotask(()=>this.onstart?.());}
        stop(){this.abort();}
        abort(){if(!this.live)return;this.live=false;setTimeout(()=>{nativeLive--;this.onend?.();},180);}
      };
      window.SpeechSynthesisUtterance=class {constructor(text){this.text=text;}};
      Object.defineProperty(window,'speechSynthesis',{value:{speak(utterance){if(nativeLive)throw Error('Playback during capture');if(blockPlayback){queueMicrotask(()=>utterance.onerror?.());return;}utterances.push(utterance.text);playing=utterance;queueMicrotask(()=>utterance.onstart?.());},cancel(){const utterance=playing;playing=null;utterance?.onerror?.();}}});
      window.finishSpeech=()=>{const utterance=playing;playing=null;utterance?.onend?.();};
      window.addEventListener('vp3:reply-window',event=>{window.replyDuration=event.detail.active?event.detail.durationMs:0;});
      </script>${scripts.map(name=>`<script src="/${name}"></script>`).join('')}`});
    if (sources.has(path.slice(1))) return route.fulfill({contentType:'text/javascript',body:sources.get(path.slice(1))});
    const body = request.postDataJSON() || {};
    if (path.includes('chat-settings')) {
      if (body.action === 'save_agent_voice') enabled=body.agent_voice_enabled;
      if (body.action === 'save_auto_greet') {
        const status = await saveAutoGreet?.();
        if (status) return route.fulfill({status,json:{ok:false,error:'Auto Greet could not be saved.'}});
        autoGreet=body.auto_greet_enabled;
      }
      return route.fulfill({json:{ok:true,agent_voice_allowed:true,agent_auto_greet_allowed:true,agent_auto_greet_ready:true,
        chat:{agent_voice_enabled:enabled,auto_greet_enabled:autoGreet===true}}});
    }
    if (path.includes('user-agent-system')) return route.fulfill({json:{ok:true,state:{agents:[{id:7,display_name:'Longfellow',is_default:1,is_active:1}],system_voice:{}}}});
    if (path.includes('chat-v236')) return route.fulfill({json:{ok:true,conversation_id:body.conversation_id||42,messages:[],conversations:[{id:42,title:'First conversation'},{id:43,title:'Second conversation'}]}});
    return route.fulfill({json:{ok:true,agent_voice_enabled:enabled,ready:true,verified:true}});
  });
  await page.goto(`${origin}/chat.php`);
  await page.waitForFunction(participant => window.VP3AgentWelcome
    && window.STONEFELLOW_CHAT_CONTINUITY_V87?.isRestoring?.() === false
    && (participant || window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady?.() === true), selectedParticipant);
  return {context,page,setPermission:state=>{permission=state;}};
}

const greeting = '[data-agent-voice-greeting]';
try {
  {
    const {context,page} = await fixture();
    await page.waitForFunction(() => utterances.length===1);
    assert.equal(await page.locator(`${greeting} .message-text`).innerText(),await page.evaluate(() => utterances[0]));
    assert.equal(await page.evaluate(() => nativeStarts+micAcquisitions+permissionReads),0);
    await page.clock.install();
    await page.evaluate(() => {
      localStorage.setItem('stonefellow:voice-mode:1','0');
      window.nextSpeech=STONEFELLOW_NOTIFICATION_CENTER.announceWhen('Gmail is connected. What would you like to try?',()=>true,{autoReply:false});
      finishSpeech();
    });
    await page.waitForFunction(() => nativeLive===1 && replyDuration===15000);
    assert.equal(await page.evaluate(() => autoListenClicks),1);
    await page.clock.runFor(14700);
    assert.deepEqual(await page.evaluate(() => [nativeLive,utterances.length]),[1,1]);
    await page.clock.runFor(500);
    await page.waitForFunction(() => utterances.length===2);
    assert.deepEqual(await page.evaluate(() => [nativeLive,micAcquisitions,track.readyState,localStorage.getItem('stonefellow:voice-mode:1')]),[0,1,'ended','0']);
    await page.evaluate(() => finishSpeech());
    assert.equal(await page.evaluate(() => nativeStarts),1);
    await page.reload();
    await page.waitForFunction(() => nativeLive===1 && replyDuration===15000);
    assert.equal(await page.locator(greeting).count(),0);
    assert.deepEqual(await page.evaluate(() => [nativeStarts,autoListenClicks,utterances.length]),[1,1,0]);
    await page.evaluate(() => STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(43));
    await page.clock.runFor(300);
    await page.waitForFunction(() => nativeLive===0);
    assert.equal(await page.locator(greeting).count(),0);
    assert.deepEqual(await page.evaluate(() => [nativeStarts,autoListenClicks,utterances.length]),[1,1,0]);
    await context.close();
    console.log('PASS Chat greets and listens for 15 seconds, reload starts quiet Listen during greeting cooldown, and switching conversations releases capture');
  }
  for (const autoGreet of [null,false]) {
    const {context,page} = await fixture({autoGreet});
    await page.clock.install();
    await page.evaluate(() => window.dispatchEvent(new CustomEvent('vp3:agent-auto-greet',{detail:{enabled:true}})));
    await page.clock.runFor(300);
    assert.equal(await page.locator(greeting).count(),0);
    assert.equal(await page.evaluate(() => permissionReads+autoListenClicks+nativeAttempts+utterances.length),0);
    await page.locator('#chatVoiceButton').click();
    await page.waitForFunction(() => nativeLive===1);
    assert.equal(await page.evaluate(() => autoListenClicks),0);
    await context.close();
  }
  console.log('PASS Auto Greet is opt-in on page load and manual Listen is available while it is off');
  {
    const {context,page} = await fixture({selectedParticipant:true});
    await page.clock.install();
    await page.clock.runFor(300);
    assert.equal(await page.locator(greeting).count(),0);
    assert.equal(await page.evaluate(() => STONEFELLOW_CHAT_CONTINUITY_V87.isReady()),false);
    assert.equal(await page.evaluate(() => permissionReads+autoListenClicks+nativeAttempts+utterances.length),0);
    await context.close();
    console.log('PASS a selected participant cannot trigger the owner’s automatic greeting or Listen');
  }
  {
    const {context,page} = await fixture({enabled:false});
    assert.equal(await page.locator(greeting).count(),0);
    assert.equal(await page.evaluate(() => STONEFELLOW_NOTIFICATION_CENTER.announce('Muted connection notice.')),false);
    await page.evaluate(() => localStorage.setItem('vp3:chat-voice-greeting:1',String(Date.now())));
    await page.locator('#chatProfileButton').click();
    await page.locator('[data-vp3-global-agent-voice]').check();
    await page.waitForFunction(() => utterances.length===1);
    await page.evaluate(() => finishSpeech());
    await page.waitForFunction(() => nativeLive===1);
    await page.locator('[data-vp3-global-agent-voice]').uncheck();
    await page.waitForFunction(() => nativeLive===0 && track.readyState==='ended');
    await context.close();
    console.log('PASS the real account toggle speaks on activation despite cooldown and turning it off releases capture');
  }
  for (const permission of ['prompt','denied','unknown']) {
    const {context,page,setPermission} = await fixture({permission});
    await page.waitForFunction(() => utterances.length===1);
    await page.evaluate(() => finishSpeech());
    await page.waitForFunction(() => permissionReads===1);
    assert.equal(await page.evaluate(() => autoListenClicks+nativeStarts+micAcquisitions),0);
    if (permission === 'prompt') {
      setPermission('granted');
      await page.reload();
      await page.waitForFunction(() => nativeLive===1 && replyDuration===15000);
      assert.deepEqual(await page.evaluate(() => [autoListenClicks,utterances.length]),[1,0]);
    }
    await context.close();
  }
  {
    const {context,page} = await fixture({blockRecognition:true});
    await page.waitForFunction(() => utterances.length===1);
    await page.clock.install();
    await page.evaluate(() => {
      localStorage.setItem('stonefellow:voice-mode:1','0');
      finishSpeech();
    });
    await page.waitForFunction(() => nativeAttempts===1);
    await page.clock.runFor(17000);
    assert.equal(await page.evaluate(() => autoListenClicks),1);
    assert.equal(await page.evaluate(() => nativeAttempts),1);
    assert.match(await page.locator('#chatVoiceStatus').innerText(),/Select Listen/);
    assert.equal(await page.evaluate(() => localStorage.getItem('stonefellow:voice-mode:1')),'0');
    await page.evaluate(() => { blockRecognition=false; });
    await page.locator('#chatVoiceButton').click();
    await page.waitForFunction(() => nativeLive===1);
    await context.close();
    console.log('PASS a blocked automatic Listen attempt leaves manual startup available and does not retry');
  }
  {
    let finishSave;
    const saving = new Promise(resolve=>{finishSave=resolve;});
    const {context,page} = await fixture({saveAutoGreet:()=>saving});
    try {
      await page.waitForFunction(() => utterances.length===1);
      await page.evaluate(() => finishSpeech());
      await page.waitForFunction(() => nativeLive===1);
      await page.locator('#chatProfileButton').click();
      const toggle = page.getByRole('checkbox',{name:'Auto Greet'});
      await toggle.uncheck();
      assert.equal(await toggle.isDisabled(),true);
      await page.waitForFunction(() => nativeLive===0 && track.readyState==='ended');
      assert.equal(await page.evaluate(() => localStorage.getItem('vp3:auto-greet-sync:1')),null);
      finishSave();
      await page.waitForFunction(() => JSON.parse(localStorage.getItem('vp3:auto-greet-sync:1'))?.enabled===false);
      assert.equal(await toggle.isChecked(),false);
    } finally { finishSave(); await context.close(); }
    console.log('PASS disabling Auto Greet releases active capture before saving and broadcasts the saved preference');
  }
  {
    let finishSave;
    const saving = new Promise(resolve=>{finishSave=resolve;});
    const {context,page} = await fixture({saveAutoGreet:()=>saving});
    try {
      await page.waitForFunction(() => utterances.length===1);
      await page.evaluate(() => {delayPermission=true;finishSpeech();});
      await page.waitForFunction(() => typeof releasePermission==='function');
      await page.locator('#chatProfileButton').click();
      const toggle = page.getByRole('checkbox',{name:'Auto Greet'});
      await toggle.uncheck();
      await page.evaluate(() => releasePermission());
      assert.equal(await page.evaluate(() => autoListenClicks+nativeStarts+micAcquisitions),0);
      finishSave(503);
      await page.waitForFunction(() => document.querySelector('[data-vp3-agent-voice-status]').textContent.includes('paused for this page'));
      assert.equal(await toggle.isChecked(),true);
      assert.equal(await page.evaluate(() => localStorage.getItem('vp3:auto-greet-sync:1')),null);
      await page.evaluate(() => window.dispatchEvent(new Event('focus')));
      assert.equal(await page.evaluate(() => autoListenClicks+nativeStarts+micAcquisitions),0);
      await page.locator('#chatProfileButton').click();
      await page.locator('#chatVoiceButton').click();
      await page.waitForFunction(() => nativeLive===1);
    } finally { finishSave(503); await context.close(); }
    console.log('PASS a failed opt-out restores the saved switch, keeps automatic capture paused for this page, and leaves manual Listen available');
  }
  console.log('PASS prompt, denied and unknown permissions allow the greeting without capture, and a reload after granting access starts quiet Listen');
  {
    const {context,page} = await fixture({blockPlayback:true,legacy:true});
    const retry = page.getByRole('button',{name:'Hear greeting'});
    await retry.waitFor();
    assert.equal(await page.locator(greeting).count(),1);
    assert.match(await page.locator(greeting).innerText(),/An optional update/);
    assert.equal(await page.evaluate(() => permissionReads+nativeStarts),0);
    await page.evaluate(() => { blockPlayback=false;delayPermission=true; });
    await retry.click();
    assert.deepEqual(await page.evaluate(() => utterances),['Welcome back. What shall we work on?']);
    await page.evaluate(() => finishSpeech());
    await page.waitForFunction(() => typeof releasePermission==='function');
    await page.locator('#chatInput').fill('Unsent draft');
    await page.evaluate(() => releasePermission());
    await page.clock.install();
    await page.clock.runFor(200);
    assert.equal(await page.evaluate(() => nativeStarts+micAcquisitions),0);
    assert.equal(await page.locator('#chatInput').inputValue(),'Unsent draft');
    await context.close();
    console.log('PASS blocked autoplay offers a retry for the exact canvas greeting and typing during permission verification cancels capture');
  }
  {
    const {context,page} = await fixture();
    await page.waitForFunction(() => utterances.length===1);
    await page.evaluate(() => finishSpeech());
    await page.waitForFunction(() => nativeLive===1);
    await page.evaluate(() => {
      Object.defineProperty(document,'hidden',{value:true});
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await page.waitForFunction(() => nativeLive===0 && track.readyState==='ended');
    await context.close();
    console.log('PASS hiding the canvas stops and releases the greeting capture');
  }
  assert.deepEqual(errors,[]);
  console.log('AGENT_CHAT_GREETING_BROWSER=PASS');
} finally { await browser.close(); }
