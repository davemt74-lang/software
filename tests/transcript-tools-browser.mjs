import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';
const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
const page=await browser.newPage();const errors=[],chatRequests=[];page.on('pageerror',e=>errors.push(e.message));
page.on('request',request=>{if(new URL(request.url()).pathname==='/api/chat.php')chatRequests.push(request.method());});
await page.route('http://transcript.test/',r=>r.fulfill({contentType:'text/html',body:'<!doctype html><html><body><div class="chat-topbar-actions"></div><main id="chatThread"></main><form id="chatForm"><textarea id="chatInput"></textarea></form><p id="outside">Unrelated page text</p></body></html>'}));
await page.route('**/api/**',r=>{const url=new URL(r.request().url());return r.fulfill({json:url.pathname.includes('intelligence')?{ok:true,master:null,registry:[],app_status:{}}:{ok:true,sessions:[{id:21,title:'Pricing call',status:'draft'},{id:22,title:'Follow-up call',status:'draft'}]}});});
await page.route('**/*.js?**',r=>r.fulfill({contentType:'text/javascript',body:''}));
async function mountCanvas(){
 await page.evaluate(()=>{
  window.STONEFELLOW_RECORDINGS_V198_CONFIG={listeningEndpoint:'/api/artist-listening.php',intelligenceEndpoint:'/api/intelligence'};
  window.STONEFELLOW_ARTIST_RECORDINGS_V198={api:{refresh:async()=>({library:[]}),getState:()=>({library:[]})}};
  window.STONEFELLOW_CHAT={userId:1,agentId:4,endpoint:'/api/chat.php',csrf:'fixture-csrf',workingAttentionBinding:'fixture-page'};
  window.fixtureConversation=70;
  window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>fixtureConversation,agentId:()=>4,isReady:()=>true,isRestoring:()=>false};
  window.STONEFELLOW_AGENT_CONTEXT={userId:1,surface:'chat'};
  window.transcriptEvents=[];
  window.addEventListener('vp3:transcript-source-changed',event=>transcriptEvents.push(event.detail));
 });
 await page.addStyleTag({content:await fs.readFile('chat-transcription-canvas.css','utf8')});
 for(const name of ['chat-transcription-canvas.js','agent-context-v131.js'])await page.addScriptTag({content:await fs.readFile(name,'utf8')});
}
try{
 await page.goto('http://transcript.test/');
 await mountCanvas();
 await page.locator('#chatInput').fill('My unsent draft');
 await page.evaluate(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.open({sessionId:21}));
 await page.waitForFunction(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.selection()?.session_id===21);
 await page.evaluate(()=>{const p=document.createElement('p');p.id='selection-evidence';p.textContent='The agreed pizza price is twenty dollars.';document.querySelector('#chatTranscriptionCanvas').append(p);const range=document.createRange();range.selectNodeContents(p);const s=getSelection();s.removeAllRanges();s.addRange(range);});
 let context=await page.evaluate(()=>StonefellowAgentContext.snapshot());assert.equal(context.transcript_session_id,21);assert.equal(context.transcript_selected_text,'The agreed pizza price is twenty dollars.');
 console.log('PASS actual transcript panel selection supplies session and selected quote to shared Agent context');
 await page.evaluate(()=>{const range=document.createRange();range.selectNodeContents(document.querySelector('#outside'));const s=getSelection();s.removeAllRanges();s.addRange(range);});
 context=await page.evaluate(()=>StonefellowAgentContext.snapshot());assert.equal(context.transcript_selected_text,'');assert.equal(context.transcript_session_id,21);
 console.log('PASS selections outside the transcript panel never enter transcript context');
 const originalSource=await page.evaluate(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.sourceContext());
 await page.evaluate(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.open({sessionId:22}));context=await page.evaluate(()=>StonefellowAgentContext.snapshot());assert.equal(context.transcript_session_id,22);assert.equal(context.transcript_selected_text,'');
 assert.equal(await page.evaluate(source=>STONEFELLOW_TRANSCRIPTION_CANVAS.clearSourceForTurn(source),originalSource),false);
 assert.equal(await page.evaluate(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.sourceContext()?.session_id),22);
 await page.evaluate(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.close());
 context=await page.evaluate(()=>StonefellowAgentContext.snapshot());
 assert.equal(context.transcript_session_id,22);assert.equal(context.transcript_source_selected,true);assert.equal(context.transcript_selected_text,'');
 assert.equal(await page.evaluate(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.selection()),null);
 await page.locator('#chatTranscriptionCanvas').waitFor({state:'hidden'});
 assert.match(await page.locator('#chatTranscriptSource').textContent(),/Follow-up call/);
 assert.equal(await page.locator('#chatInput').inputValue(),'My unsent draft');
 await page.getByRole('button',{name:'Open active transcription'}).click();
 await page.waitForFunction(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.selection()?.session_id===22);
 await page.evaluate(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.close());
 await page.locator('#chatTranscriptionCanvas').waitFor({state:'hidden'});
 await page.getByRole('button',{name:'Clear transcription from Chat'}).click();
 context=await page.evaluate(()=>StonefellowAgentContext.snapshot());
 assert.equal(context.transcript_session_id,0);assert.equal(context.transcript_source_cleared,true);assert.equal(context.transcript_selected_text,'');
 assert.equal(await page.locator('#chatTranscriptSource').count(),0);
 assert.equal(await page.locator('#chatInput').inputValue(),'My unsent draft');
 console.log('PASS closing retains the selected source, Open returns to the exact recording, and Clear keeps the draft while excluding the source');

 await page.reload();await mountCanvas();
 context=await page.evaluate(()=>StonefellowAgentContext.snapshot());
 assert.equal(context.transcript_source_cleared,true);assert.equal(context.transcript_session_id,0);
 await page.evaluate(()=>{fixtureConversation=71;});
 assert.equal(await page.evaluate(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.sourceContext()),null);
 await page.evaluate(()=>{fixtureConversation=70;});
 assert.equal(await page.evaluate(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.sourceContext()?.cleared),true);
 console.log('PASS an explicit Clear survives reload only for the same Chat and account session');

 await page.evaluate(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.open({sessionId:21}));
 assert.equal(await page.evaluate(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.sourceContext()?.session_id),21);
 await page.evaluate(()=>{window.dispatchEvent(new Event('stonefellow:chat-context-changing'));fixtureConversation=71;});
 context=await page.evaluate(()=>StonefellowAgentContext.snapshot());
 assert.equal(context.transcript_session_id,0);assert.equal(context.transcript_source_selected,undefined);assert.equal(context.transcript_source_cleared,undefined);
 assert.equal(await page.locator('#chatTranscriptSource').count(),0);
 await page.evaluate(()=>document.getElementById('chatThread').insertAdjacentHTML('beforeend',
  '<div class="sf-v206-recording-message is-new-recording"><div data-v206-recording-card data-v206-session="22" data-v206-key="saved-audio"></div></div>'));
 await page.waitForFunction(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.sourceContext()?.session_id===22&&STONEFELLOW_TRANSCRIPTION_CANVAS_V308.newRecordingOpens===1);
 assert.match(await page.locator('#chatTranscriptSource').textContent(),/Follow-up call/);
 console.log('PASS a new saved-recording card selects its exact source without submitting a Chat turn');
 await page.evaluate(()=>{window.dispatchEvent(new Event('stonefellow:chat-context-changing'));fixtureConversation=0;});
 await page.evaluate(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.open({sessionId:21}));
 await page.evaluate(()=>{fixtureConversation=72;window.dispatchEvent(new CustomEvent('vp3:chat-conversation-bound',{detail:{previous_conversation_id:0,conversation_id:72}}));});
 assert.equal(await page.evaluate(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.sourceContext()?.session_id),21);
 console.log('PASS switching Chats clears the previous source, while the first saved turn retains its new Chat source');

 await page.evaluate(()=>{window.dispatchEvent(new Event('stonefellow:chat-context-changing'));fixtureConversation=73;});
 let releaseList,arrived;const waiting=new Promise(resolve=>arrived=resolve);
 await page.route('**/api/artist-listening.php**',async r=>{arrived();await new Promise(resolve=>releaseList=resolve);await r.fulfill({json:{ok:true,sessions:[{id:21,title:'Pricing call',status:'draft'}]}});});
 await page.evaluate(()=>{window.pendingTranscriptOpen=STONEFELLOW_TRANSCRIPTION_CANVAS.open({sessionId:21});});await waiting;
 await page.evaluate(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.close());releaseList();await page.evaluate(()=>window.pendingTranscriptOpen);await page.waitForTimeout(220);
 context=await page.evaluate(()=>StonefellowAgentContext.snapshot());assert.equal(context.transcript_session_id,0);assert.equal(await page.locator('#chatTranscriptionCanvas').evaluate(el=>el.hidden&&!el.classList.contains('open')),true);
 await page.unroute('**/api/artist-listening.php**');
 await page.evaluate(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.open({sessionId:21}));
 await page.evaluate(()=>{STONEFELLOW_CHAT.workingAttentionBinding='new-session-page';});
 await page.waitForFunction(()=>!document.getElementById('chatTranscriptSource'));
 assert.equal(await page.evaluate(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.sourceContext()),null);
 assert.equal(await page.evaluate(()=>STONEFELLOW_TRANSCRIPTION_CANVAS.selection()),null);
 assert.equal(await page.evaluate(()=>transcriptEvents.every(detail=>Object.keys(detail).sort().join() === 'cleared,conversation_id,session_id')),true);
 console.log('PASS closing during delayed loads and replacing the page session reject stale source context; activity events contain no transcript text');
 assert.deepEqual(chatRequests,[]);assert.deepEqual(errors,[]);console.log('TRANSCRIPT_TOOLS_BROWSER=PASS (actual panel and shared context, fixture transcript API)');
}finally{await browser.close();}
