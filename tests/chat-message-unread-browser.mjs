import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const root = new URL('../',import.meta.url);
const sources = Object.fromEntries(await Promise.all(['chat.js','chat-message-unread-v1.js','chat-message-unread-v1.css','chat-voice.js']
  .map(async name => [name,await fs.readFile(new URL(name,root),'utf8')])));
const browser = await chromium.launch({headless:true,
  ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {})});
const context = await browser.newContext({viewport:{width:1000,height:720}});
const origin = 'https://chat-message-read.test';
const history = new Map([[42,[{id:1,role:'assistant',message:'Historical reply'},
  {id:3,role:'system',message:'Restored internal control marker'}]],
  [43,[{id:2,role:'assistant',message:'A different Chat'}]]]);
const read = new Set([1,2]);
const calls = [];
const errors = [];
let nextId = 100;
let failAck = false;
let interleaveNext = false;
let interleavedId = 0;
let holdState = null;
let holdSync = null;
let acceptedBinding = 'owner-a';
const html = `<!doctype html><html lang="en"><meta charset="utf-8"><style>
  body{margin:0;font:16px sans-serif}.chat-thread{height:430px;overflow:auto;border:1px solid #ddd;scroll-behavior:auto}
  .message{min-height:120px;margin:12px;padding:10px;display:grid;grid-template-columns:30px 1fr}.message-role{height:22px}
  #readingOverlay{position:fixed;inset:0;background:#fff9;z-index:200}[hidden]{display:none!important}
</style><style>${sources['chat-message-unread-v1.css']}</style>
<button id="newChatButton">New Chat</button><div id="chatHistory"></div>
<section id="chatThread" class="chat-thread" data-view="chat"><div id="chatWelcome" hidden></div></section>
<form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button>
<button id="chatVoiceButton" type="button">Voice</button></form><div id="chatVoiceStatus"></div>
<div id="readingOverlay" hidden></div>
<script>
window.STONEFELLOW_CHAT={userId:1,agentId:0,initialConversationId:42,initialView:'chat',csrf:'fixture',
  endpoint:'/api/chat-v236.php',workingAttentionBinding:'owner-a'};
window.VP3_CHAT_MESSAGE_UNREAD_V1={endpoint:'/api/agent-chat-read-v1.php'};
window.speechCalls=0;window.speechSynthesis={cancel(){},speak(){window.speechCalls++;}};
window.SpeechSynthesisUtterance=class{constructor(text){this.text=text;}};
</script><script src="/chat.js"></script><script src="/chat-message-unread-v1.js"></script></html>`;

await context.route(`${origin}/**`,async route => {
  const req = route.request();
  const url = new URL(req.url());
  if (url.pathname === '/') return route.fulfill({contentType:'text/html',body:html});
  if (sources[url.pathname.slice(1)]) return route.fulfill({contentType:'text/javascript',body:sources[url.pathname.slice(1)]});
  if (url.pathname === '/api/agent-chat-read-v1.php') {
    const post = req.method() === 'POST';
    const data = post ? req.postDataJSON() : Object.fromEntries(url.searchParams);
    const ids = post ? data.message_ids : String(data.message_ids).split(',').map(Number);
    const id = Number(data.conversation_id);
    const item = {method:req.method(),id,ids:[...ids],binding:req.headers()['x-vp3-working-attention']};
    calls.push(item);
    if (item.binding !== acceptedBinding) return route.fulfill({status:403,json:{ok:false,error:'account_changed'}});
    if (post && failAck) return route.fulfill({status:503,json:{ok:false,error:'unavailable'}});
    const eligible = new Set((history.get(id) || []).filter(message => message.role === 'assistant' && message.message.trim()).map(message => message.id));
    if (ids.some(messageId => !eligible.has(messageId))) return route.fulfill({status:422,json:{ok:false,error:'invalid_messages'}});
    if (post) ids.forEach(messageId => read.add(messageId));
    const result = {ok:true,agent_id:Number(data.agent_id),conversation_id:id,message_ids:ids,
      unread_message_ids:ids.filter(messageId => !read.has(messageId))};
    if (!post && holdState) {
      const gate = holdState;
      holdState = null;
      gate.arrived();
      await gate.release;
    }
    return route.fulfill({json:result}).catch(() => {});
  }
  if (url.pathname !== '/api/chat-v236.php' && url.pathname !== '/api/chat-stream-v121.php') return route.fulfill({status:404});
  const data = req.postDataJSON();
  const id = Number(data.conversation_id || 42);
  const ok = payload => route.fulfill({json:{ok:true,...payload}});
  if (data.action === 'send') {
    const userId = ++nextId;
    history.get(id).push({id:userId,role:'user',message:data.message});
    if (interleaveNext) {
      interleaveNext = false;
      interleavedId = ++nextId;
      history.get(id).push({id:interleavedId,role:'assistant',message:'Profile activity during the ordinary response'});
    }
    const assistantId = ++nextId;
    const answer = url.pathname.includes('stream') ? 'Confirmed streamed reply' : 'Direct typed reply ' + assistantId;
    history.get(id).push({id:assistantId,role:'assistant',message:answer});
    const payload = {ok:true,conversation_id:id,user_message_id:userId,assistant_message_id:assistantId,answer};
    if (url.pathname.includes('stream')) return route.fulfill({contentType:'application/x-ndjson',body:
      [JSON.stringify({type:'start',conversation_id:id,assistant_message_id:assistantId}),
        JSON.stringify({type:'delta',delta:answer}),JSON.stringify({type:'done',data:payload})].join('\n') + '\n'});
    return ok(payload);
  }
  if (data.action === 'activity') return ok({conversation_id:id,latest_message_id:history.get(id)?.at(-1)?.id || 0,latest_id:0,unread_count:0,updates:[]});
  if (data.action === 'load') return ok({messages:history.get(id) || []});
  if (data.action === 'messages_after') {
    const messages = (history.get(id) || [])
      .filter(message => message.id > data.after_id || (data.pending_message_ids || []).includes(message.id));
    if (holdSync) {
      const gate = holdSync;
      holdSync = null;
      gate.arrived();
      await gate.release;
    }
    return ok({conversation_id:id,messages});
  }
  return ok({conversations:[{id:42,title:'First Chat'},{id:43,title:'Second Chat'}]});
});

function observe(page) { page.on('pageerror',error => errors.push(error.message)); page.setDefaultTimeout(8000); }
const page = await context.newPage();
observe(page);
const badge = id => page.locator(`[data-chat-message-id="${id}"] [data-chat-message-new]`);
const overlay = async shown => page.evaluate(shown => { document.getElementById('readingOverlay').hidden = !shown; },shown);
const refresh = () => page.evaluate(() => window.VP3_CHAT_MESSAGE_UNREAD.refresh());
const sync = () => page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY.syncConversation(42));
const addAssistant = text => {
  const id = ++nextId;
  history.get(42).push({id,role:'assistant',message:text});
  return id;
};

try {
  await page.goto(origin);
  await page.getByText('Historical reply',{exact:true}).waitFor();
  await refresh();
  assert.equal(await page.locator('[data-chat-message-new]').count(),0);
  assert.equal(calls.filter(call => call.method === 'POST').length,0);
  assert.ok(calls.every(call => !call.ids.includes(3)));
  console.log('PASS historical restored replies stay read without acknowledging unseen history');

  await overlay(true);
  await page.locator('#chatInput').fill('A typed message');
  await page.evaluate(() => document.getElementById('chatForm').requestSubmit());
  const typedId = nextId;
  await page.locator('[data-chat-message-id]').filter({hasText:'Direct typed reply'}).waitFor();
  const savedTypedId = Number(await page.locator('.message.assistant').last().getAttribute('data-chat-message-id'));
  assert.ok(savedTypedId >= typedId);
  await badge(savedTypedId).waitFor();
  await page.waitForTimeout(1400);
  assert.ok(!read.has(savedTypedId));
  assert.equal(await page.evaluate(() => window.speechCalls),0);
  console.log('PASS a direct typed response binds its persisted ID and stays NEW behind a covering UI');

  failAck = true;
  await overlay(false);
  await page.waitForRequest(req => req.method() === 'POST' && new URL(req.url()).pathname === '/api/agent-chat-read-v1.php');
  await badge(savedTypedId).waitFor();
  assert.ok(!read.has(savedTypedId));
  failAck = false;
  await badge(savedTypedId).waitFor({state:'detached',timeout:8000});
  assert.ok(read.has(savedTypedId));
  console.log('PASS NEW clears only after the visible message receives a successful server acknowledgement');

  await overlay(true);
  interleaveNext = true;
  await page.locator('#chatInput').fill('An ordinary request while another post arrives');
  await page.evaluate(() => document.getElementById('chatForm').requestSubmit());
  await page.locator('.message.assistant').filter({hasText:'Direct typed reply'}).last().waitFor();
  await page.waitForFunction(() => !document.getElementById('sendChatButton').disabled);
  await sync();
  await refresh();
  await badge(interleavedId).waitFor();
  const directOrder = await page.locator('[data-chat-message-id]').evaluateAll(nodes => nodes.map(node => Number(node.dataset.chatMessageId)));
  assert.equal(directOrder.filter(id => id === interleavedId).length,1);
  assert.ok(directOrder.indexOf(interleavedId - 1) < directOrder.indexOf(interleavedId));
  assert.ok(directOrder.indexOf(interleavedId) < directOrder.indexOf(interleavedId + 1));
  console.log('PASS a Profile post saved during an ordinary reply stays visible exactly once in canonical saved order');

  await overlay(true);
  const systemId = ++nextId;
  history.get(42).push({id:systemId,role:'system',message:'Synced internal control marker'});
  const sparseIds = Array.from({length:6},(_,index) => addAssistant('Worker result ' + index));
  await sync();
  await refresh();
  for (const id of sparseIds) await badge(id).waitFor();
  assert.equal(await badge(systemId).count(),0);
  assert.ok(calls.every(call => !call.ids.includes(systemId)));
  await overlay(false);
  await badge(sparseIds.at(-1)).waitFor({state:'detached'});
  assert.ok(!read.has(sparseIds[0]));
  await badge(sparseIds[0]).waitFor();
  console.log('PASS only visible worker posts are acknowledged while earlier offscreen posts remain NEW');

  await page.reload();
  await page.getByText('Worker result 5',{exact:true}).waitFor();
  await badge(sparseIds[0]).waitFor();
  assert.equal(await badge(sparseIds.at(-1)).count(),0);
  console.log('PASS reload preserves sparse read receipts without treating all restored posts as read');

  await overlay(true);
  const longId = addAssistant('Long verified reply. ' + 'This is a grounded detail in the current result. '.repeat(150));
  await sync();
  await refresh();
  await badge(longId).waitFor();
  const headerBox = await page.locator(`[data-chat-message-id="${longId}"] .message-role`).boundingBox();
  const threadBox = await page.locator('#chatThread').boundingBox();
  assert.ok(headerBox.y < threadBox.y);
  await overlay(false);
  await badge(longId).waitFor({state:'detached'});
  assert.ok(read.has(longId));
  assert.ok(!read.has(sparseIds[0]));
  console.log('PASS a long reply is viewed through its visible body while its offscreen header and earlier unread posts stay independent');

  await overlay(true);
  const placeholderId = ++nextId;
  history.get(42).push({id:placeholderId,role:'assistant',message:'',context_json:'{}'});
  const laterId = addAssistant('A later ordinary update');
  await sync();
  await refresh();
  assert.equal(await page.locator(`[data-chat-message-id="${placeholderId}"]`).count(),0);
  await badge(laterId).waitFor();
  history.get(42).find(message => message.id === placeholderId).message = 'Earlier stream completed';
  await sync();
  await refresh();
  await badge(placeholderId).waitFor();
  await sync();
  assert.equal(await page.locator(`[data-chat-message-id="${placeholderId}"]`).count(),1);
  const order = await page.locator('[data-chat-message-id]').evaluateAll(nodes => nodes.map(node => Number(node.dataset.chatMessageId)));
  assert.ok(order.indexOf(placeholderId) < order.indexOf(laterId));
  assert.ok(!read.has(placeholderId));
  console.log('PASS a blank stream completes under its exact earlier ID after newer posts, renders once in order, and remains NEW');
  let releaseSync;
  let syncArrived;
  const syncStarted = new Promise(resolve => { syncArrived = resolve; });
  holdSync = {arrived:syncArrived,release:new Promise(resolve => { releaseSync = resolve; })};
  const delayedSyncId = addAssistant('Late completion from the previous Chat');
  const delayedSync = sync();
  await syncStarted;
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(43));
  releaseSync();
  await delayedSync;
  assert.equal(await page.locator(`[data-chat-message-id="${delayedSyncId}"]`).count(),0);
  assert.ok(!read.has(delayedSyncId));
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(42));
  await badge(delayedSyncId).waitFor();
  console.log('PASS a delayed canonical sync cannot render or acknowledge the previous Chat after a context change');
  await overlay(false);

  const sibling = await context.newPage();
  observe(sibling);
  await sibling.goto(origin);
  await page.setViewportSize({width:390,height:700});
  await sibling.evaluate(() => { document.getElementById('readingOverlay').hidden = false; });
  await sibling.bringToFront();
  // Headless Chromium exposes every Page as visible. Supply the browser's
  // visibility signal while retaining real DOM geometry and two live tabs.
  await page.evaluate(() => {
    Object.defineProperty(document,'hidden',{configurable:true,get:() => true});
    document.dispatchEvent(new Event('visibilitychange'));
  });
  const mobileId = addAssistant('A hidden mobile update');
  await sync();
  await refresh();
  await badge(mobileId).waitFor();
  await page.waitForTimeout(1500);
  assert.ok(!read.has(mobileId),JSON.stringify({old:await page.evaluate(() => ({hidden:document.hidden,focus:document.hasFocus()})),
    sibling:await sibling.evaluate(() => ({hidden:document.hidden,focus:document.hasFocus(),covered:!document.getElementById('readingOverlay').hidden})),
    ack:calls.filter(call => call.method === 'POST' && call.ids.includes(mobileId))}));
  await sibling.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY.syncConversation(42));
  await sibling.locator(`[data-chat-message-id="${mobileId}"] [data-chat-message-new]`).waitFor();
  await sibling.evaluate(() => { document.getElementById('readingOverlay').hidden = true; });
  await sibling.locator(`[data-chat-message-id="${mobileId}"] [data-chat-message-new]`).waitFor({state:'detached'});
  await page.waitForFunction(id => !document.querySelector(`[data-chat-message-id="${id}"] [data-chat-message-new]`),mobileId);
  assert.ok(read.has(mobileId));
  console.log('PASS a hidden mobile tab retains NEW until another visible tab confirms viewing the exact post');
  await sibling.close();

  await page.bringToFront();
  await page.evaluate(() => {
    delete document.hidden;
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await overlay(true);
  const staleId = addAssistant('A delayed first Chat state');
  await sync();
  await refresh();
  await badge(staleId).waitFor();
  let release;
  let arrived;
  const started = new Promise(resolve => { arrived = resolve; });
  holdState = {arrived,release:new Promise(resolve => { release = resolve; })};
  const pending = refresh();
  await started;
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY.openConversation(43));
  release();
  await pending;
  await page.getByText('A different Chat',{exact:true}).waitFor();
  await overlay(false);
  await page.waitForTimeout(1400);
  assert.equal(await page.locator('[data-chat-message-new]').count(),0);
  assert.ok(!read.has(staleId));
  console.log('PASS a delayed read state cannot paint or acknowledge a different Chat');

  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY.openConversation(42));
  await overlay(true);
  const accountId = addAssistant('A source before account switching');
  await sync();
  await refresh();
  await badge(accountId).waitFor();
  await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide',{persisted:true})));
  assert.equal(await page.locator('[data-chat-message-new]').count(),0);
  await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true})));
  await badge(accountId).waitFor();
  assert.ok(!read.has(accountId));
  console.log('PASS a restored page resumes exact scoped read state after fencing the hidden page');
  await page.evaluate(() => { window.STONEFELLOW_CHAT.agentId = 7; });
  await refresh();
  assert.equal(await page.locator('[data-chat-message-new]').count(),0);
  await page.waitForTimeout(1400);
  assert.ok(!read.has(accountId));
  console.log('PASS changing the selected Agent clears the old scope without acknowledging its messages');
  await page.reload();
  await overlay(true);
  await badge(accountId).waitFor();
  let releaseAccount;
  let accountArrived;
  const accountStarted = new Promise(resolve => { accountArrived = resolve; });
  holdState = {arrived:accountArrived,release:new Promise(resolve => { releaseAccount = resolve; })};
  const pendingAccount = refresh();
  await accountStarted;
  await page.evaluate(() => {
    window.STONEFELLOW_CHAT = {...window.STONEFELLOW_CHAT,userId:2,workingAttentionBinding:'owner-b'};
  });
  acceptedBinding = 'owner-b';
  releaseAccount();
  await pendingAccount;
  assert.equal(await page.locator('[data-chat-message-new]').count(),0);
  await overlay(false);
  await page.waitForTimeout(1400);
  assert.ok(!read.has(accountId));
  assert.equal(await page.evaluate(() => window.speechCalls),0);
  assert.deepEqual(errors,[]);
  console.log('PASS account replacement invalidates old badges and cannot acknowledge or synthesize speech');

  acceptedBinding = 'owner-a';
  const streamed = await context.newPage();
  observe(streamed);
  await streamed.goto(origin);
  await streamed.getByText('Historical reply',{exact:true}).waitFor();
  await streamed.evaluate(() => {
    window.recognizers = [];
    window.SpeechRecognition = class {
      constructor() { window.recognizers.push(this); }
      start() { this.active = true; queueMicrotask(() => this.onstart?.()); }
      stop() { this.active = false; queueMicrotask(() => this.onend?.()); }
      abort() { this.stop(); }
    };
    window.StonefellowPremiumVoiceV122 = () => ({warm:async () => false,stop(){},createStream(){}});
    Object.defineProperty(window,'speechSynthesis',{value:{cancel(){},speak(utterance){
      window.speechCalls++;
      queueMicrotask(() => { utterance.onstart?.(); utterance.onend?.(); });
    }}});
    window.STONEFELLOW_CHAT_VOICE_BOOT = {button:document.getElementById('chatVoiceButton')};
  });
  await streamed.addScriptTag({content:sources['chat-voice.js']});
  await streamed.getByRole('button',{name:'Start voice conversation'}).click();
  await streamed.waitForFunction(() => window.recognizers.some(item => item.active));
  await streamed.evaluate(() => {
    document.getElementById('readingOverlay').hidden = false;
    const final = [{transcript:'Review the next steps from this voice conversation',confidence:.99}];
    final.isFinal = true;
    window.recognizers.findLast(item => item.active).onresult({resultIndex:0,results:[final]});
  });
  const streamedMessage = streamed.locator('.message.assistant').filter({hasText:'Confirmed streamed reply'});
  await streamedMessage.waitFor();
  const streamedId = Number(await streamedMessage.getAttribute('data-chat-message-id'));
  assert.ok(streamedId > 0);
  await streamedMessage.locator('[data-chat-message-new]').waitFor();
  const speechBeforeRead = await streamed.evaluate(() => window.speechCalls);
  await streamed.evaluate(() => {
    window.VP3ConversationControls.stop();
    document.getElementById('readingOverlay').hidden = true;
  });
  await streamedMessage.locator('[data-chat-message-new]').waitFor({state:'detached'});
  assert.ok(read.has(streamedId));
  assert.equal(await streamed.evaluate(() => window.speechCalls),speechBeforeRead);
  assert.deepEqual(errors,[]);
  console.log('PASS a confirmed NDJSON reply uses the canonical saved-message renderer and read acknowledgement creates no speech');
} finally {
  await browser.close();
}
