import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {chromium} from 'playwright';
const server=http.createServer((req,res)=>{
 const name=new URL(req.url,'http://fixture').pathname.slice(1);
 if(['chat-voice.js','voice-lease-v122.js','agent-response-window.js'].includes(name)){
  res.setHeader('Content-Type','text/javascript');res.end(fs.readFileSync(name==='chat-voice.js'&&process.env.VP3_VOICE_SOURCE?process.env.VP3_VOICE_SOURCE:name));return;
 }
 res.setHeader('Content-Type','text/html');res.end(`<!doctype html><form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button><button type="button" id="chatVoiceButton">Listen</button></form><div id="chatVoiceStatus"></div><div id="chatThread"></div><script>
 window.STONEFELLOW_CHAT={userId:1,csrf:'test',endpoint:'/api/chat.php'};
 window.STONEFELLOW_AGENT_IDENTITY_V236={agentVoiceEnabled:true};
 window.STONEFELLOW_CHAT_VOICE_BOOT={intro:null};
 window.VP3_AGENT_PREFERENCE_STATE={enabled:true,allowed:true,agent:{id:7,name:'Longfellow'}};
 window.nativeRecognizers=[];window.nativeLive=new Set();window.preparingMic=false;window.captureRaces=0;window.nativeOverlaps=0;
 Object.defineProperty(navigator,'permissions',{value:{query:async()=>({state:'granted'})}});
 Object.defineProperty(navigator,'mediaDevices',{value:{getUserMedia:async()=>{window.preparingMic=true;await new Promise(r=>setTimeout(r,60));window.preparingMic=false;const track={readyState:'live',stop(){this.readyState='ended';},addEventListener(){}};return {getTracks:()=>[track],getAudioTracks:()=>[track]};}}});
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
 document.getElementById('chatForm').addEventListener('submit',e=>e.preventDefault());
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
 console.log('AGENT_EYES_LISTENING_HANDOFF_BROWSER=PASS (4 cases)');
}finally{await browser.close();await new Promise(r=>server.close(r));}
