import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';

const {chromium} = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({
  headless: true,
  args: ['--no-sandbox'],
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined
});

try {
  const page = await browser.newPage();
  page.setDefaultTimeout(8000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const calls = [];
  const current = 42;
  const other = 99;
  const history = [{id:current,title:'Meeting Chat'}, {id:other,title:'Other Chat'}];
  const message = (id, text) => ({id,role:'assistant',message:text});
  const threads = new Map([
    [current,[message(10,'Earlier meeting conversation')]],
    [other,[message(90,'Only the other conversation')]]
  ]);
  const initiative = {...message(11,'I prepared a private agenda from the open meeting follow-up.'),
    context_json:JSON.stringify({source:'agent_initiative',skip_brain_archive:true,sources:[]})};
  const late = {...initiative,...message(12,'Late initiative for the previous conversation')};
  let releaseRestore;
  const restoreGate = new Promise(resolve => { releaseRestore = resolve; });
  let releaseInitialActivity;
  const initialActivityGate = new Promise(resolve => { releaseInitialActivity = resolve; });
  let releaseLate;
  const lateGate = new Promise(resolve => { releaseLate = resolve; });

  await page.route('https://initiative.test/', route => route.fulfill({contentType:'text/html',body:`
    <!doctype html><meta charset="utf-8">
    <aside><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside>
    <main><section id="chatThread" data-view="chat"><div id="chatWelcome" hidden></div></section>
    <form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form></main>
  `}));
  await page.route('**/api/chat-v236.php', async route => {
    const body = route.request().postDataJSON();
    calls.push(body);
    const ok = data => route.fulfill({json:{ok:true,...data}});
    if (body.action === 'load') {
      if (body.conversation_id === current) await restoreGate;
      return ok({messages:threads.get(body.conversation_id)});
    }
    if (body.action === 'activity') {
      if (!body.conversation_id) await initialActivityGate;
      const id = body.conversation_id || other;
      return ok({conversation_id:id,latest_message_id:threads.get(id).at(-1).id,
        latest_id:0,unread_count:0,updates:[]});
    }
    if (body.action === 'messages_after') {
      if (body.conversation_id === current && body.after_id === initiative.id) await lateGate;
      return ok({conversation_id:body.conversation_id,
        messages:threads.get(body.conversation_id).filter(row => row.id > body.after_id)});
    }
    return ok({conversations:history});
  });
  const matches = (action, fields = {}) => request => {
    if (!request.url().endsWith('/api/chat-v236.php')) return false;
    const body = request.postDataJSON();
    return body.action === action && Object.entries(fields).every(([key,value]) => body[key] === value);
  };
  const thread = page.locator('#chatThread');

  await page.goto('https://initiative.test/');
  await page.evaluate(id => { window.STONEFELLOW_CHAT = {userId:1,agentId:0,endpoint:'/api/chat-v236.php',
    csrf:'fixture',initialView:'chat',initialConversationId:id,activityPollMs:2000}; }, current);
  const restoring = page.waitForRequest(matches('load',{conversation_id:current}));
  const initialPoll = page.waitForRequest(matches('activity',{conversation_id:0}));
  await page.addScriptTag({path:fileURLToPath(new URL('../chat.js',import.meta.url))});
  await Promise.all([restoring,initialPoll]);
  releaseRestore();
  await thread.getByText('Earlier meeting conversation',{exact:true}).waitFor();
  const initialReply = page.waitForResponse(response => matches('activity',{conversation_id:0})(response.request()));
  releaseInitialActivity();
  await (await initialReply).finished();
  assert.equal(await thread.getByText('Only the other conversation',{exact:true}).count(),0);
  assert.equal(calls.some(row => row.action === 'messages_after' && row.conversation_id === other),false);

  threads.get(current).push(initiative);
  const poll = await page.waitForRequest(matches('activity',{conversation_id:current}));
  assert.equal(poll.postDataJSON().after_id,0);
  await thread.getByText(initiative.message,{exact:true}).waitFor();
  assert.equal(await thread.locator('[data-chat-conversation-id="42"][data-chat-message-id="11"]').count(),1);
  assert.ok(calls.some(row => row.action === 'messages_after' && row.conversation_id === current && row.after_id === 10));

  const pending = page.waitForRequest(matches('messages_after',{conversation_id:current,after_id:initiative.id}));
  threads.get(current).push(late);
  await pending;
  assert.equal(await thread.getByText(initiative.message,{exact:true}).count(),1);
  await page.getByRole('button',{name:'Other Chat',exact:true}).click();
  await thread.getByText('Only the other conversation',{exact:true}).waitFor();
  releaseLate();
  // A complete poll in the newly selected Chat proves the delayed old sync settled.
  await page.waitForRequest(matches('activity',{conversation_id:other}));
  assert.equal(await thread.getByText(late.message,{exact:true}).count(),0);
  assert.equal(await thread.getByText(initiative.message,{exact:true}).count(),0);
  assert.equal(calls.filter(row => row.action === 'messages_after' && row.conversation_id === other).length,0);
  assert.ok(calls.filter(row => row.action === 'activity').every(row => row.after_id === 0));
  assert.deepEqual(errors,[]);
  console.log('CHAT_INITIATIVE_BROWSER=PASS (zero notification cursor, exact active Chat, delayed response isolated)');
} finally {
  await browser.close();
}
