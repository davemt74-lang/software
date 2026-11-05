import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const runtime = await fs.readFile(new URL('../chat-cognitive-presentation-v510.js', import.meta.url), 'utf8');
const chatRuntime = await fs.readFile(new URL('../chat.js', import.meta.url), 'utf8');
const browser = await chromium.launch({
  headless:true,
  executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined
});
const context = await browser.newContext();
const page = await context.newPage();
const origin = 'https://live-attention.test';
const requests = [];
const errors = [];
const key = value => createHash('sha256').update(value).digest('hex');
const first = {
  key:key('mission:12'),
  title:'<img src=x onerror="window.unsafe=true"> Review the blocked Mission',
  summary:'A specialist needs your approval.',
  href:'/missions.php?mission=12',
  action_label:'Open Mission'
};
const reminder = {
  key:key('reminder:7'),
  title:'Review the time-sensitive reminder',
  summary:'The reminder is due in 15 minutes.',
  href:'/reminders.php?reminder=7',
  action_label:'Open Reminders'
};
const initiative = {state:'checked',last_checked_at:'2026-11-04T20:04:00Z'};
const current = (attention, opening=false) => ({state:'current',attention,opening,initiative});
const quiet = {state:'quiet',attention:null,opening:false,initiative:{state:'off'}};
let conversation = 42;
let acceptedBinding = 'owner-a-session';
let nextReply;
let live = current(first);

page.setDefaultTimeout(8000);
page.on('pageerror', error => errors.push(error.message));
await context.addInitScript(() => {
  window.fixtureChat = {id:42,ready:true,restoring:false,agent:0,busy:false};
  window.STONEFELLOW_CHAT_CONTINUITY = {
    conversationId:() => window.fixtureChat.id,
    agentId:() => window.fixtureChat.agent,
    isReady:() => window.fixtureChat.ready,
    isRestoring:() => window.fixtureChat.restoring,
    isBusy:() => window.fixtureChat.busy
  };
  window.VP3_COGNITIVE_PRESENTATION_V510 = {
    agentId:0, workingAttentionBinding:'owner-a-session', liveContext:true
  };
  window.speechCount = 0;
  window.submitCount = 0;
  window.STONEFELLOW_NOTIFICATION_CENTER = {
    announceWhen:() => { window.speechCount++; return false; },
    cancelSpeech:() => {}
  };
  document.addEventListener('submit', event => {
    event.preventDefault();
    window.submitCount++;
    window.submittedPrompt = document.getElementById('chatInput').value;
  });
});

await context.route(`${origin}/**`, async route => {
  const request = route.request();
  const url = new URL(request.url());
  if (url.pathname === '/') return route.fulfill({contentType:'text/html',body:`
    <!doctype html><meta charset="utf-8">
    <button id="chatAgentBriefButton">Agent Brief</button>
    <aside id="chatAgentBriefPopover" hidden><div data-agent-brief-content></div><footer></footer></aside>
    <section id="chatThread" data-view="chat"><div id="chatWelcome" hidden></div><article>Earlier conversation</article><section id="chatLiveContext" hidden></section></section>
    <form id="chatForm"><textarea id="chatInput"></textarea><button>Send</button></form>
    <script src="/presentation.js"></script>`});
  if (url.pathname === '/presentation.js') return route.fulfill({contentType:'text/javascript',body:runtime});
  if (url.pathname !== '/api/cognitive-presentation-v510.php') return route.fulfill({status:404});

  const binding = request.headers()['x-vp3-working-attention'] || '';
  const body = request.method() === 'POST' ? request.postDataJSON() : null;
  const id = Number(url.searchParams.get('conversation_id'));
  const includeLive = url.searchParams.get('include_live_context');
  requests.push({method:request.method(),binding,id,includeLive,working:url.searchParams.get('include_working_attention'),...(body && {action:body.action})});
  if (binding !== acceptedBinding) return route.fulfill({status:403,json:{ok:false,error:'account_changed'}});
  if (body) return route.fulfill({json:{ok:true}});
  const state = {brief:{agent_name:'VP3 Agent',active:true,status_label:'Active'}};
  if (includeLive === '1' && id === conversation) state.live_context = live;
  if (nextReply) {
    const gate = nextReply;
    nextReply = null;
    gate.arrived();
    await gate.release;
  }
  // A context switch may abort the held request before the server replies.
  await route.fulfill({json:{ok:true,state}}).catch(() => {});
});

const card = page.locator('#chatLiveContext');
const popup = page.locator('#chatAgentBriefPopover');
const initiativeStatus = popup.locator('[data-agent-live-initiative]');
const input = page.locator('#chatInput');
const opening = card.getByText('Agent check-in',{exact:true});
const top = card.getByText('Top of mind',{exact:true});
const refresh = () => page.evaluate(() => window.VP3_COGNITIVE_PRESENTATION.refresh());
const followUpReference = id => page.evaluate(id => window.VP3_COGNITIVE_PRESENTATION.followUpReference(id),id);
const toggleBrief = () => page.locator('#chatAgentBriefButton').click();
const requestFor = id => page.waitForRequest(request => {
  const url = new URL(request.url());
  return url.pathname === '/api/cognitive-presentation-v510.php'
    && url.searchParams.get('include_live_context') === '1'
    && url.searchParams.get('conversation_id') === String(id);
});

try {
  await page.goto(origin);
  await card.getByText(first.title,{exact:true}).waitFor();
  await top.waitFor();
  await input.fill('An unsent draft');
  await refresh();
  assert.equal(await opening.count(),0);
  assert.equal(await popup.isHidden(),true);
  assert.equal(await card.locator('img').count(),0);
  assert.equal(await page.evaluate(() => window.unsafe),undefined);
  assert.equal(await card.getByRole('link',{name:first.action_label}).getAttribute('href'),first.href);
  assert.equal(await input.inputValue(),'An unsent draft');
  assert.equal(await followUpReference(42),first.key);
  assert.ok(!await followUpReference(99));
  await card.getByRole('button',{name:'Ask Agent'}).click();
  assert.equal(await input.inputValue(),'An unsent draft');
  assert.equal(await page.evaluate(() => window.submitCount),0);
  assert.deepEqual(requests[0],{method:'GET',binding:'owner-a-session',id:42,includeLive:'1',working:'0'});
  console.log('PASS ordinary restored Chat shows one literal, source-linked priority without opening Agent Brief');

  live = quiet;
  await refresh();
  await card.waitFor({state:'hidden'});
  await toggleBrief();
  await popup.getByText('Nothing pressing in the sources checked.',{exact:true}).waitFor();
  assert.match(await initiativeStatus.textContent(),/Initiative is off\./);
  assert.equal(await card.getByText(first.title,{exact:true}).count(),0);
  assert.equal(await top.count(),0);
  assert.equal(await opening.count(),0);
  assert.equal(await card.getByRole('link').count(),0);
  assert.equal(await page.locator('#chatThread').textContent(),'Earlier conversation');
  await toggleBrief();
  await input.fill('');
  console.log('PASS a quiet presentation retains its independent Initiative status without inventing a priority');

  live = current(reminder,true);
  await refresh();
  await opening.waitFor();
  assert.equal(await card.getByText(reminder.title,{exact:true}).count(),1);
  await refresh();
  assert.equal(await card.getByText(reminder.title,{exact:true}).count(),1);
  assert.equal(await page.evaluate(() => window.speechCount),0);
  assert.equal(await page.evaluate(() => window.submitCount),0);
  await page.reload();
  await card.getByText(reminder.title,{exact:true}).waitFor();
  assert.equal(await opening.count(),0);
  assert.equal(await page.evaluate(() => window.speechCount),0);
  assert.equal(await page.evaluate(() => window.submitCount),0);
  assert.equal(page.url(),`${origin}/`);
  console.log('PASS an eligible check-in appears once, remains a single source on poll, and stays silent');

  const sibling = await context.newPage();
  sibling.setDefaultTimeout(8000);
  sibling.on('pageerror', error => errors.push(error.message));
  await sibling.goto(origin);
  const siblingCard = sibling.locator('#chatLiveContext');
  await siblingCard.getByText(reminder.title,{exact:true}).waitFor();
  assert.equal(await siblingCard.getByText('Agent check-in',{exact:true}).count(),0);
  await page.bringToFront();
  await card.getByRole('button',{name:'Dismiss this update'}).click();
  assert.equal(await card.getByText(reminder.title,{exact:true}).count(),0);
  await siblingCard.waitFor({state:'hidden'});
  await sibling.close();
  assert.ok(!await followUpReference(42));
  await refresh();
  await page.reload();
  await toggleBrief();
  await initiativeStatus.waitFor();
  assert.match(await initiativeStatus.textContent(),/^Initiative checked its sources\. Sources last checked /);
  assert.equal(await card.getByText(reminder.title,{exact:true}).count(),0);
  await toggleBrief();
  console.log('PASS one check-in is shared across tabs, and dismissal persists without suppressing Initiative status');

  const unsafe = {...first,key:key('call:13'),title:'Open a safe view of the call',href:'/\\external.test'};
  live = current(unsafe);
  await refresh();
  await card.getByText(unsafe.title,{exact:true}).waitFor();
  assert.equal(await card.getByRole('link').count(),0);
  console.log('PASS the ordinary Chat card rejects a cross-origin action disguised as a relative path');

  await page.evaluate(() => { window.fixtureChat.busy = true; });
  await refresh();
  await card.waitFor({state:'hidden'});
  assert.ok(!await followUpReference(42));
  await page.evaluate(() => { window.fixtureChat.busy = false; });
  await refresh();
  await card.getByText(unsafe.title,{exact:true}).waitFor();
  console.log('PASS pending Chat work keeps the live item out of the way');

  await page.evaluate(() => {
    document.getElementById('chatThread').dataset.view = 'player';
    window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));
  });
  await card.waitFor({state:'hidden'});
  assert.ok(!await followUpReference(42));
  await refresh();
  assert.notEqual(requests.at(-1).includeLive,'1');
  await page.evaluate(() => {
    document.getElementById('chatThread').dataset.view = 'chat';
    window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));
  });
  await card.getByText(unsafe.title,{exact:true}).waitFor();
  console.log('PASS leaving the Chat view clears private presentation and returning refreshes it');

  let arrive, release;
  const arrived = new Promise(resolve => { arrive = resolve; });
  nextReply = {arrived:arrive,release:new Promise(resolve => { release = resolve; })};
  const late = {...first,key:key('old:42'),title:'Late private source for the previous Chat'};
  live = current(late);
  const pending = refresh();
  await arrived;
  await page.evaluate(() => {
    window.fixtureChat.ready = false;
    window.fixtureChat.restoring = true;
    window.dispatchEvent(new Event('stonefellow:chat-context-changing'));
  });
  assert.equal(await card.getByText(unsafe.title,{exact:true}).count(),0);
  const newRead = requestFor(99);
  conversation = 99;
  const newItem = {...first,key:key('new:99'),title:'Only this Chat’s current source'};
  live = current(newItem);
  await page.evaluate(() => {
    window.fixtureChat.id = 99;
    window.fixtureChat.ready = true;
    window.fixtureChat.restoring = false;
    window.dispatchEvent(new Event('vp3:chat-context-ready'));
  });
  release();
  await Promise.all([pending,newRead]);
  await card.getByText(newItem.title,{exact:true}).waitFor();
  assert.equal(await page.getByText(late.title,{exact:true}).count(),0);
  assert.ok(!await followUpReference(42));
  assert.equal(await followUpReference(99),newItem.key);
  console.log('PASS a delayed reply from the previous Chat cannot fill the newly restored Chat');

  const interaction = page.waitForResponse(response => {
    const request = response.request();
    return request.method() === 'POST' && request.postDataJSON()?.action === 'interaction';
  });
  await card.getByRole('button',{name:'Ask Agent'}).click();
  assert.deepEqual(await page.evaluate(() => [window.submitCount,window.submittedPrompt]),[1,'Help me with that']);
  await (await interaction).finished();
  console.log('PASS an explicit Ask Agent action uses the visible item’s bound follow-up');

  await input.fill('An unsent draft');
  acceptedBinding = 'owner-b-session';
  await refresh();
  assert.equal(await card.getByText(newItem.title,{exact:true}).count(),0);
  assert.equal(await card.getByRole('link').count(),0);
  assert.ok(requests.every(request => request.binding === 'owner-a-session'
    && (request.method === 'GET' || request.action === 'interaction')));
  assert.equal(await page.evaluate(() => window.speechCount),0);
  assert.equal(await page.evaluate(() => window.submitCount),1);
  assert.ok(!await followUpReference(99));
  assert.equal(await page.locator('#chatThread').textContent(),'Earlier conversation');
  assert.equal(await input.inputValue(),'An unsent draft');
  assert.equal(page.url(),`${origin}/`);
  assert.deepEqual(errors,[]);
  console.log('PASS an account change clears the source without sending, speaking, or navigating');

  await page.close();
  const native = await browser.newPage();
  native.setDefaultTimeout(8000);
  native.on('pageerror', error => errors.push(error.message));
  const nativeOrigin = 'https://native-attention.test';
  const sends = [];
  const reads = [];
  let releaseSend;
  const firstSend = new Promise(resolve => { releaseSend = resolve; });
  const history = [{id:1,role:'assistant',message:'Restored private conversation'}];
  let selected = first;
  await native.route(`${nativeOrigin}/**`, async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname === '/') return route.fulfill({contentType:'text/html',body:`
      <!doctype html><meta charset="utf-8">
      <aside><button id="newChatButton">New Chat</button><div id="chatHistory"></div></aside>
      <button id="chatAgentBriefButton">Agent Brief</button>
      <aside id="chatAgentBriefPopover" hidden><div data-agent-brief-content></div><footer></footer></aside>
      <section id="chatThread" data-view="chat"><div id="chatWelcome" hidden></div><section id="chatLiveContext" hidden></section>
      <form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form>
      <script>
        window.STONEFELLOW_CHAT = {userId:1,agentId:0,initialConversationId:42,endpoint:'/api/chat-v236.php',
          initialView:'chat',csrf:'fixture',workingAttentionBinding:'owner-a-session'};
        window.VP3_COGNITIVE_PRESENTATION_V510 = {agentId:0,workingAttentionBinding:'owner-a-session',liveContext:true};
      </script><script src="/chat.js"></script><script src="/presentation.js"></script>`});
    if (url.pathname === '/chat.js') return route.fulfill({contentType:'text/javascript',body:chatRuntime});
    if (url.pathname === '/presentation.js') return route.fulfill({contentType:'text/javascript',body:runtime});
    if (url.pathname === '/api/cognitive-presentation-v510.php') {
      if (request.method() === 'POST') return route.fulfill({json:{ok:true}});
      const id = url.searchParams.get('conversation_id');
      const includeLive = url.searchParams.get('include_live_context');
      reads.push({id,includeLive});
      return route.fulfill({json:{ok:true,state:{brief:{agent_name:'VP3 Agent',active:true,status_label:'Active'},
        ...(id === '42' && includeLive === '1' ? {live_context:current(selected)} : {})}}});
    }
    if (url.pathname !== '/api/chat-v236.php') return route.fulfill({status:404});
    const body = request.postDataJSON();
    const ok = data => route.fulfill({json:{ok:true,...data}});
    if (body.action === 'send') {
      sends.push(body);
      if (sends.length === 1) await firstSend;
      const id = history.length + 1;
      const answer = 'Saved reply for turn ' + sends.length;
      history.push({id,role:'user',message:body.message}, {id:id + 1,role:'assistant',message:answer});
      return ok({conversation_id:42,assistant_message_id:id + 1,answer});
    }
    if (body.action === 'activity') return ok({conversation_id:body.conversation_id,latest_message_id:history.at(-1).id,
      latest_id:0,unread_count:0,updates:[]});
    if (body.action === 'messages_after') return ok({messages:history.filter(message => message.id > body.after_id)});
    return ok({messages:body.action === 'load' ? history : [],conversations:[{id:42,title:'Private Chat'}]});
  });
  await native.goto(nativeOrigin);
  const nativeCard = native.locator('#chatLiveContext');
  await nativeCard.getByText(first.title,{exact:true}).waitFor();
  const sent = native.waitForRequest(request => new URL(request.url()).pathname === '/api/chat-v236.php'
    && request.postDataJSON()?.action === 'send');
  await nativeCard.getByRole('button',{name:'Ask Agent'}).click();
  await sent;
  await nativeCard.waitFor({state:'hidden'});
  releaseSend();
  await native.getByText('Saved reply for turn 1',{exact:true}).waitFor();
  assert.equal(sends[0].working_context_ref,first.key);
  assert.equal(sends[0].conversation_id,42);
  assert.equal(sends[0].message,'Help me with that');

  selected = reminder;
  await native.evaluate(() => window.VP3_COGNITIVE_PRESENTATION.refresh());
  await nativeCard.getByText(reminder.title,{exact:true}).waitFor();
  await native.locator('#chatInput').fill('Help me with that');
  await native.getByRole('button',{name:'Send',exact:true}).click();
  await native.getByText('Saved reply for turn 2',{exact:true}).waitFor();
  assert.equal(sends[1].working_context_ref,reminder.key);
  assert.equal(sends[1].conversation_id,42);
  console.log('PASS native Chat sends the exact visible source for both explicit and typed follow-up');

  selected = first;
  await native.evaluate(() => window.VP3_COGNITIVE_PRESENTATION.refresh());
  await nativeCard.getByText(first.title,{exact:true}).waitFor();
  const addSyncedReply = async text => {
    const id = history.at(-1).id + 1;
    history.push({id,role:'user',message:'A different question'}, {id:id + 1,role:'assistant',message:text});
    await native.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY.syncConversation(42));
    await native.getByText(text,{exact:true}).waitFor();
  };
  await addSyncedReply('Newer reply from another tab');
  await nativeCard.getByText(first.title,{exact:true}).waitFor();
  await native.locator('#chatInput').fill('Tell me more');
  await native.getByRole('button',{name:'Send',exact:true}).click();
  await native.getByText('Saved reply for turn 3',{exact:true}).waitFor();
  assert.equal(sends[2].working_context_ref,undefined);

  await native.evaluate(() => window.VP3_COGNITIVE_PRESENTATION.refresh());
  await nativeCard.getByText(first.title,{exact:true}).waitFor();
  await addSyncedReply('Another newer reply from the other tab');
  await nativeCard.getByRole('button',{name:'Ask Agent'}).click();
  await native.getByText('Saved reply for turn 4',{exact:true}).waitFor();
  assert.equal(sends[3].working_context_ref,first.key);
  assert.equal(sends[3].conversation_id,42);
  console.log('PASS cross-tab delivery leaves generic follow-ups with the newest turn and preserves explicit source selection');

  const readCount = reads.length;
  await native.getByRole('button',{name:'New Chat',exact:true}).click();
  await native.waitForFunction(() => window.STONEFELLOW_CHAT_CONTINUITY.conversationId() === 0);
  await native.evaluate(() => window.VP3_COGNITIVE_PRESENTATION.refresh());
  await nativeCard.waitFor({state:'hidden'});
  assert.ok(reads.length > readCount);
  assert.ok(reads.slice(readCount).every(read => read.id === null && read.includeLive === null));
  assert.equal(sends.length,4);
  assert.deepEqual(errors,[]);
  console.log('PASS a new unpersisted Chat keeps Brief working without requesting an unbound live item');
} finally {
  await browser.close();
}
