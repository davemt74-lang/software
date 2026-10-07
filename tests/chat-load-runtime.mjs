import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source=fs.readFileSync('chat.js','utf8');
const poll=source.slice(source.indexOf('  async function pollAgentActivity('),source.indexOf('  function startAgentActivityPolling()'));
const api=source.slice(source.indexOf('  async function api(payload'),source.indexOf('  function writeActivityCursor('));
assert.doesNotMatch(source,/\bliveStatus\b|chatLiveStatus/,'Removed activity status must not be referenced');
let response,requestCount=0,timer,delay,cleared=0;
const context={AbortController,document:{hidden:false,getElementById:()=>null},cfg:{endpoint:'/api/chat.php',csrf:'token'},
    window:{setTimeout:(fn,ms)=>{timer=fn;delay=ms;return 1;},clearTimeout:()=>{cleared++;}},
    fetch:async(_url,options)=>{requestCount++;assert.equal(JSON.parse(options.body).csrf_token,'token');return response(options.signal);},
    writeActivityCursor:value=>{context.activityCursor=value;},updateNotificationBadge:value=>{context.unread=value;},
    syncConversationMessagesV101:async()=>{},activityBusy:false,activityCursor:0,conversationId:1,lastLoadedMessageId:0,pendingConversationSync:0,busy:false};
vm.createContext(context);vm.runInContext(api+poll+'\nglobalThis.poll=pollAgentActivity;',context);
response=async()=>({ok:true,json:async()=>({ok:true,latest_id:8,unread_count:2,updates:[]})});
await context.poll(true);assert.equal(context.activityCursor,8);assert.equal(context.unread,2);assert.equal(context.activityBusy,false);
assert.equal(delay,15000,'Activity polling must have a finite timeout');
let finish;
response=signal=>new Promise((resolve,reject)=>{finish=resolve;signal.addEventListener('abort',()=>reject(new Error('timeout')),{once:true});});
const pending=context.poll(true);await context.poll(true);assert.equal(requestCount,2,'Concurrent polling was not coalesced');
timer();await pending;assert.equal(context.activityBusy,false,'Timeout permanently blocked polling');
response=async()=>({ok:false,json:async()=>({ok:false,error:'Access revoked'})});
await context.poll(true);assert.equal(context.activityBusy,false,'Failure permanently blocked polling');
response=async()=>({ok:true,json:async()=>({ok:true,latest_id:9,updates:[]})});
await context.poll(true);assert.equal(context.activityCursor,9,'Polling did not recover');
context.document.hidden=true;const count=requestCount;await context.poll();assert.equal(requestCount,count);await context.poll(true);assert.equal(requestCount,count+1);
assert.equal(cleared,requestCount,'Polling timers leaked');

const stem=source.slice(source.indexOf('  function stemMediaHtml(item)'),source.indexOf('  function showChatMediaError('));
vm.runInContext(stem+'\nglobalThis.stem=stemMediaHtml;',context);
context.escapeHtml=value=>String(value).replaceAll('"','&quot;');context.withStudioReturn=value=>value;
const card=context.stem({track_id:1,audio:'/stem-media-v34.php?id=2',song:'Song'});
assert.match(card,/preload="none"/);assert.doesNotMatch(card,/data-play-track=/);assert.match(card,/Full song audio is not available/);
assert.match(context.stem({track_id:1,song_audio:'/media.php?track=1&type=audio'}),/data-play-track="1"/);
for(const file of ['chat.js','chat-legacy-v108.php'])assert.doesNotMatch(fs.readFileSync(file,'utf8'),/preload="metadata"|preload = 'metadata'/,'Hidden players must not fetch metadata on initial load');

// Exercise the same error presentation used by native stem and custom song controls.
const errorUi=source.slice(source.indexOf('  function showChatMediaError('),source.indexOf('  // Media errors do not bubble.'));
context.audioFeedbackHosts=new WeakMap();
let node;const host={querySelector:()=>node,appendChild:value=>{node=value;}};
context.document.createElement=()=>({dataset:{},setAttribute:()=>{}});
vm.runInContext(errorUi+'\nglobalThis.showError=showChatMediaError;',context);
const audio={closest:()=>host};context.showError(audio,true);assert.match(node.textContent,/Audio is unavailable/);assert.equal(node.hidden,false);
const first=node;context.showError(audio,true);assert.equal(node,first,'Repeated errors duplicated status');context.showError(audio,false);assert.equal(node.hidden,true);
const hiddenAudio={closest:()=>null,parentElement:null};context.audioFeedbackHosts.set(hiddenAudio,host);context.showError(hiddenAudio,true);assert.equal(node.hidden,false,'Full-song failure stayed inside a hidden player');
console.log('CHAT_LOAD_RUNTIME=PASS missing-status timeout retry lazy-audio error-feedback');
