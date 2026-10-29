import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {chromium} from 'playwright';
const server=http.createServer((req,res)=>{
 const requestUrl=new URL(req.url,'http://fixture');const name=requestUrl.pathname.slice(1);
 if(['chat-voice.js','voice-lease-v122.js','agent-response-window.js'].includes(name)){
  res.setHeader('Content-Type','text/javascript');res.end(fs.readFileSync(name==='chat-voice.js'&&process.env.VP3_VOICE_SOURCE?process.env.VP3_VOICE_SOURCE:name));return;
 }
 res.setHeader('Content-Type','text/html');res.end(`<!doctype html><form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button><button type="button" id="chatVoiceButton">Listen</button></form><div id="chatVoiceStatus"></div><div id="chatThread"></div><script>
 window.STONEFELLOW_CHAT={userId:1,csrf:'test',endpoint:'/api/chat.php'};
 window.STONEFELLOW_AGENT_IDENTITY_V236={agentVoiceEnabled:true};
 window.STONEFELLOW_CHAT_VOICE_BOOT={intro:null};
 window.VP3_AGENT_PREFERENCE_STATE={enabled:true,allowed:true,agent:{id:7,name:'Longfellow'}};
 window.micDelay=${Number(requestUrl.searchParams.get('micDelay')||60)};window.submitted=[];window.nativeRecognizers=[];window.nativeLive=new Set();window.preparingMic=false;window.captureRaces=0;window.nativeOverlaps=0;
 Object.defineProperty(navigator,'permissions',{value:{query:async()=>({state:'granted'})}});
 Object.defineProperty(navigator,'mediaDevices',{value:{getUserMedia:async()=>{window.preparingMic=true;await new Promise(r=>setTimeout(r,window.micDelay));window.preparingMic=false;const track={readyState:'live',stop(){this.readyState='ended';},addEventListener(){}};return {getTracks:()=>[track],getAudioTracks:()=>[track]};}}});
 window.SpeechRecognition=class {
  constructor(){nativeRecognizers.push(this);this.ending=false;}
  start(){
   if(preparingMic||nativeLive.size){if(preparingMic)captureRaces++;if(nativeLive.size)nativeOverlaps++;queueMicrotask(()=>{this.onerror?.({error:'aborted'});this.onend?.();});return;}
   nativeLive.add(this);queueMicrotask(()=>this.onstart?.());
  }
  stop(){this.finish(false);}
  abort(){this.finish(true);}
  finish(aborted){if(this.ending||!nativeLive.has(this))return;this.ending=true;if(aborted)queueMicrotask(()=>this.onerror?.({error:'aborted'}));setTimeout(()=>{nativeLive.delete(this);this.onend?.();},aborted?900:20);}
 };
 document.getElementById('chatForm').addEventListener('submit',e=>{e.preventDefault();submitted.push(document.getElementById('chatInput').value);});
 </script><script src="/voice-lease-v122.js"></script><script src="/chat-voice.js"></script><script>window.VP3_ORB_ADAPTER={responseWindow:()=>VP3ConversationControls.responseWindow(),cancelResponseWindow:()=>VP3ConversationControls.cancelResponseWindow()};</script><script src="/agent-response-window.js"></script>`);
});
await new Promise(r=>server.listen(0,'127.0.0.1',r));const base=`http://127.0.0.1:${server.address().port}`;
const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
try{
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base);await page.waitForFunction(()=>window.VP3ConversationControls);
 await page.evaluate(()=>localStorage.setItem('stonefellow:voice-mode:1','0'));
 await page.clock.install();
 await page.evaluate(()=>window.dispatchEvent(new Event('vp3:agent-eyes-started')));
 await page.waitForFunction(()=>document.body.dataset.stonefellowAgentState==='listening');
 assert.equal(await page.evaluate(()=>captureRaces),0,'Recognition must wait for microphone monitor acquisition');
 console.log('PASS Eyes starts temporary listening after microphone acquisition');
 for(let i=0;i<4;i++){
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('stonefellow:agent-proactive-speech',{detail:{state:'start',text:'What should I call you?'}})));
  await page.waitForTimeout(80);
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('stonefellow:agent-proactive-speech',{detail:{state:'end',text:'What should I call you?',completed:true}})));
  await page.waitForTimeout(1400);
  await page.waitForFunction(()=>document.body.dataset.stonefellowAgentState==='listening');
 }
 assert.equal(await page.evaluate(()=>nativeOverlaps),0,'New recognition must wait for the aborted native recognizer to end');
 assert.equal(await page.evaluate(()=>nativeLive.size),1);
 assert.equal(await page.evaluate(()=>localStorage.getItem('stonefellow:voice-mode:1')),'0');
 assert.doesNotMatch(await page.locator('#chatVoiceStatus').innerText(),/failed|retry/i);
 console.log('PASS repeated spoken Eyes questions wait for native abort completion without failure or overlapping capture');
 await page.clock.runFor(16000);
 assert.equal(await page.evaluate(()=>VP3ConversationControls.isEnabled()),false);
 await page.clock.runFor(1000);assert.equal(await page.evaluate(()=>nativeLive.size),0);
 console.log('PASS response timeout releases capture without a late restart');
 await page.evaluate(()=>VP3ConversationControls.responseWindow());await page.clock.runFor(1000);
 await page.evaluate(()=>{const r=[...nativeLive][0];r.onerror?.({error:'not-allowed'});r.abort();});
 await page.clock.runFor(2000);
 assert.equal(await page.evaluate(()=>VP3ConversationControls.isEnabled()),false);
 assert.match(await page.locator('#chatVoiceStatus').innerText(),/permission is blocked/);
 assert.equal(await page.evaluate(()=>nativeLive.size),0);
 assert.deepEqual(errors,[]);
 console.log('PASS genuine microphone permission denial still stops listening');
 // Keep these pages isolated: each test owns its capture lease and timers.
 const race=await browser.newPage();await race.goto(base+'/?micDelay=1800');await race.clock.install();
 await race.evaluate(()=>{VP3ConversationControls.listen();window.dispatchEvent(new CustomEvent('stonefellow:agent-proactive-speech',{detail:{state:'start',text:'What should I call you?'}}));});
 await race.clock.runFor(100);await race.evaluate(()=>window.dispatchEvent(new CustomEvent('stonefellow:agent-proactive-speech',{detail:{state:'end',text:'What should I call you?',completed:true}})));
 await race.clock.runFor(4000);
 assert.equal(await race.evaluate(()=>captureRaces),0,'Barge capture must also wait for a pending monitor');
 assert.equal(await race.evaluate(()=>VP3ConversationControls.state().state),'listening','Speech during acquisition must not leave stale connecting flags');
 assert.equal(await race.evaluate(()=>nativeLive.size),1);
 await race.close();console.log('PASS proactive speech during slow microphone acquisition recovers one listener');
 const stable=await browser.newPage();await stable.goto(base);await stable.clock.install();
 await stable.evaluate(()=>VP3ConversationControls.listen());await stable.clock.runFor(200);
 await stable.evaluate(()=>{VP3ConversationControls.listen();VP3ConversationControls.listen();VP3ConversationControls.listen();});
 assert.equal(await stable.evaluate(()=>VP3ConversationControls.state().state),'listening');
 assert.equal(await stable.evaluate(()=>nativeRecognizers.length),1,'Repeated listen is idempotent');
 await stable.evaluate(()=>{const r=[...nativeLive][0];window.firstCapture=r;r.onresult({resultIndex:0,results:[{0:{transcript:'My name is',confidence:.99},isFinal:true}]});});
 await stable.clock.runFor(600);
 assert.equal(await stable.evaluate(()=>nativeLive.has(firstCapture)),true,'A final segment must not disconnect capture during a speech pause');
 await stable.evaluate(()=>firstCapture.onresult({resultIndex:1,results:[{0:{transcript:'My name is',confidence:.99},isFinal:true},{0:{transcript:'David Evans',confidence:.99},isFinal:true}]}));
 await stable.clock.runFor(1800);
 assert.deepEqual(await stable.evaluate(()=>submitted),['My name is David Evans']);
 await stable.close();console.log('PASS repeated start requests preserve one capture and multi-part names submit once');
 const retry=await browser.newPage();await retry.goto(base);await retry.clock.install();
 await retry.evaluate(()=>VP3ConversationControls.listen());await retry.clock.runFor(200);
 for(let i=0;i<2;i++){
  const before=await retry.evaluate(()=>nativeRecognizers.length);
  await retry.evaluate(()=>{const r=[...nativeLive][0];r.onerror({error:'aborted'});r.finish(false);});
  await retry.clock.runFor(300);
  assert.equal(await retry.evaluate(()=>nativeRecognizers.length),before,'Unexpected abort must back off instead of churning native capture');
  await retry.clock.runFor(1500);assert.equal(await retry.evaluate(()=>VP3ConversationControls.state().state),'listening');
 }
 await retry.evaluate(()=>VP3ConversationControls.stop());await retry.clock.runFor(2000);assert.equal(await retry.evaluate(()=>nativeLive.size),0);
 await retry.close();console.log('PASS unexpected abort retries are paced and Stop cancels pending retries');
 const longSpeech=await browser.newPage();await longSpeech.goto(base+'/?micDelay=1800');await longSpeech.clock.install();
 await longSpeech.evaluate(()=>{VP3ConversationControls.listen();window.dispatchEvent(new CustomEvent('stonefellow:agent-proactive-speech',{detail:{state:'start',text:'What should I call you?'}}));});
 await longSpeech.clock.runFor(2500);assert.equal(await longSpeech.evaluate(()=>captureRaces),0);assert.equal(await longSpeech.evaluate(()=>nativeLive.size),1);
 await longSpeech.evaluate(()=>window.dispatchEvent(new CustomEvent('stonefellow:agent-proactive-speech',{detail:{state:'end',text:'What should I call you?',completed:true}})));
 await longSpeech.clock.runFor(2000);assert.equal(await longSpeech.evaluate(()=>VP3ConversationControls.state().state),'listening');await longSpeech.close();
 console.log('PASS a longer greeting waits for monitor acquisition before barge capture');
 const blocked=await browser.newPage();await blocked.goto(base);await blocked.clock.install();
 await blocked.evaluate(()=>VP3ConversationControls.listen());await blocked.clock.runFor(200);
 await blocked.evaluate(()=>{const r=[...nativeLive][0];r.finish=()=>{};r.onerror({error:'aborted'});});
 await blocked.clock.runFor(6000);assert.equal(await blocked.evaluate(()=>VP3ConversationControls.isEnabled()),false);
 assert.match(await blocked.locator('#chatVoiceStatus').innerText(),/Reload this page/);
 await blocked.evaluate(()=>VP3ConversationControls.listen());await blocked.clock.runFor(2000);
 assert.equal(await blocked.evaluate(()=>nativeRecognizers.length),1,'A missing native end must not spawn overlapping capture');await blocked.close();
 console.log('PASS missing native release stops with a recoverable message instead of endless connecting');
 const bargeDenied=await browser.newPage();await bargeDenied.goto(base);await bargeDenied.clock.install();
 await bargeDenied.evaluate(()=>VP3ConversationControls.listen());await bargeDenied.clock.runFor(200);
 await bargeDenied.evaluate(()=>window.dispatchEvent(new CustomEvent('stonefellow:agent-proactive-speech',{detail:{state:'start',text:'Can you hear me?'}})));
 await bargeDenied.clock.runFor(1300);
 await bargeDenied.evaluate(()=>{const r=[...nativeLive][0];r.onerror({error:'not-allowed'});r.finish(false);});await bargeDenied.clock.runFor(3000);
 assert.equal(await bargeDenied.evaluate(()=>VP3ConversationControls.isEnabled()),false);assert.equal(await bargeDenied.evaluate(()=>nativeLive.size),0);
 assert.match(await bargeDenied.locator('#chatVoiceStatus').innerText(),/permission is blocked/);await bargeDenied.close();
 console.log('PASS microphone denial during barge-in stops without reconnect loops');
 console.log('AGENT_EYES_LISTENING_HANDOFF_BROWSER=PASS (10 cases)');


}finally{await browser.close();await new Promise(r=>server.close(r));}
