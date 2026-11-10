import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright';

const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],...(executablePath ? {executablePath} : {})});
const page = await browser.newPage({viewport:{width:1280,height:900}});
const errors = [];
const checks = [];
const messages = [];
const sender = 'private@example.test';
const watchPrompt = `Notify me when I get an email from ${sender}.`;
const stopPrompt = `Stop watching for emails from ${sender}.`;
let status = 'idle';
let workingUntil = '';
let delayStatus = null;
let nextId = 1;
const workflowCalls = [];
const replyDraft = {id:'d'.repeat(32), status:'prepared', is_reply:true, from:'owner@example.test', to:sender,
  subject:'', body:'', review_token:'fixture-manual-token'};
const newDraft = {id:'e'.repeat(32), status:'prepared', is_reply:false, from:'owner@example.test', to:sender,
  subject:'Fixture new subject', body:'Fixture saved body.', review_token:'fixture-new-token'};
const replyCalls = [];
const searchRef = 'a1'.repeat(64);
const followupId = 800;
const readActions = [];
let writingGate = null;
let writingFails = true;
const workflowFolder = 'Member workflow reviews';
let workflowState = {
  available:true, enabled:false, can_disable:false, status:'disabled',
  connection_id:9, connection_revision:'a'.repeat(64), revision:'',
  folder_id:0, folder_name:'', folders:[], last_checked_at:null,
};

page.on('pageerror', error => errors.push(error.message));
await page.route('https://email.test/', route => route.fulfill({contentType:'text/html',body:`<!doctype html>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <div class="chat-app"><aside class="chat-sidebar"><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside>
  <main class="chat-main"><header class="chat-topbar"></header><section class="chat-thread" id="chatThread"><div id="chatWelcome" hidden></div></section>
  <div class="chat-composer-shell" id="chatComposerShell"><form class="chat-composer" id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form></div>
  </main></div><aside class="sf-online-rail-v109" id="sfOnlineRailV109"><div id="sfChatSettingsRailSlotV132"></div></aside>`}));

await page.route('**/api/**', async route => {
  const payload = route.request().postDataJSON() || {};
  const path = new URL(route.request().url()).pathname;
  const ok = result => route.fulfill({json:{ok:true,...result}});
  if (path === '/api/chat-email-v1.php') {
    if (['open_followup','open_search'].includes(payload.action)) {
      assert.deepEqual(payload,{agent_id:7,conversation_id:42,action:payload.action,owner_key:'1',csrf_token:'fixture',
        ...(payload.action === 'open_followup' ? {message_id:followupId} : {item_ref:searchRef})});
      readActions.push(payload.action);
      return ok({message:{subject:'Exact received email',from:sender,body:'This is the exact private body.',can_reply:true}});
    }
    if (payload.action !== 'specialist_status') {
      const draft = [replyDraft,newDraft].find(value => value.id === payload.draft_id);
      assert.ok(draft);
      assert.equal(payload.conversation_id,42);
      replyCalls.push(payload);
      if (payload.action === 'revise_ai') {
        assert.equal(draft,newDraft);
        assert.equal(payload.instruction,'Make it friendlier.');
        assert.equal(payload.review_token,'fixture-new-token');
        Object.assign(newDraft,{status:'write_queued',write_kind:'revise',review_token:null});
      } else if (payload.action === 'retry_write') {
        assert.equal(draft,newDraft);
        assert.equal(payload.review_token,'fixture-retry-token');
        Object.assign(newDraft,{status:'write_queued',review_token:null});
      } else if (payload.action === 'advance_write') {
        assert.equal(draft,newDraft);
        assert.equal(draft.status,'write_queued');
        Object.assign(newDraft,{status:'writing'});
        await writingGate;
        if (writingFails) Object.assign(newDraft,{status:'write_failed',review_token:'fixture-retry-token'});
        else Object.assign(newDraft,{status:'prepared',body:'Thanks for reaching out. I appreciate it.',review_token:'fixture-ai-token'});
      } else if (payload.action === 'revise') {
        assert.equal(draft,replyDraft);
        assert.equal(payload.subject,'');
        assert.equal(payload.body,'This is my reply.');
        assert.equal(payload.review_token,'fixture-manual-token');
        Object.assign(replyDraft,{body:payload.body,review_token:'fixture-revised-token'});
      } else if (payload.action === 'confirm') {
        assert.equal(draft,replyDraft);
        assert.equal(payload.review_token,'fixture-revised-token');
        assert.equal(payload.confirmed,true);
        Object.assign(replyDraft,{status:'sent',review_token:null});
      } else assert.equal(payload.action,'review');
      return ok({draft});
    }
    assert.deepEqual(payload, {
      agent_id:7, conversation_id:payload.conversation_id, action:'specialist_status', owner_key:'1', csrf_token:'fixture',
    });
    checks.push(payload.conversation_id);
    const result = payload.conversation_id === 42 && newDraft.status === 'writing'
      ? {state:'working',work:newDraft.write_kind,working_until:new Date(Date.now()+30000).toISOString()}
      : payload.conversation_id === 42 && status !== 'idle'
      ? {state:status,origin_message_id:messages.find(row => row.message === watchPrompt).id}
      : {state:'idle',origin_message_id:null};
    if (result.state === 'working' && !result.work) result.working_until = workingUntil;
    if (delayStatus && payload.conversation_id === 42) {
      const gate = delayStatus;
      delayStatus = null;
      gate.started();
      await gate.release;
      try { await ok({specialist:result}); } catch (_error) {}
      return;
    }
    return ok({specialist:result});
  }
  if (payload.action === 'load') return ok({messages:payload.conversation_id === 42 ? messages : []});
  if (payload.action === 'task.route') return ok({route:'chat'});
  if (payload.action === 'send') {
    let answer = 'Email search completed.';
    let tool_receipt = {capability:'email.read',operation:'search',status:'read_complete'};
    if (payload.message === watchPrompt || payload.message === stopPrompt) {
      const watching = payload.message === watchPrompt;
      status = watching ? 'watching' : 'idle';
      answer = watching ? 'Gmail watch is active.' : 'Gmail watch stopped.';
      tool_receipt = {capability:'email.followups',operation:watching ? 'watch_sender' : 'stop_sender_watch',status:'completed'};
    }
    const user_message_id = nextId++;
    const assistant_message_id = nextId++;
    messages.push({id:user_message_id,role:'user',message:payload.message},{id:assistant_message_id,role:'assistant',message:answer});
    const actions = payload.message === `Show emails from ${sender}`
      ? [{type:'gmail_message',item_ref:searchRef,conversation_id:42,label:'Open result 1'}] : [];
    return ok({conversation_id:42,user_message_id,assistant_message_id,answer,tool_receipt,actions});
  }
  return ok({conversations:[],items:[],updates:[]});
});
await page.route('https://email.test/connection-gmail-github.php', async route => {
  assert.equal(route.request().method(),'POST');
  const payload = route.request().postDataJSON();
  workflowCalls.push(payload);
  assert.equal(payload.owner_key,'1');
  assert.equal(payload.csrf_token,'fixture');
  if (payload.action !== 'state') {
    assert.equal(payload.connection_id,workflowState.connection_id);
    assert.equal(payload.connection_revision,workflowState.connection_revision);
    assert.equal(payload.github_revision,workflowState.revision);
    if (payload.action === 'enable') {
      assert.equal(payload.folder_name,workflowFolder);
      workflowState = {...workflowState,enabled:true,can_disable:true,status:'waiting',revision:'b'.repeat(32),
        folder_id:18,folder_name:workflowFolder,folders:[{id:18,name:workflowFolder}]};
    } else {
      assert.equal(payload.action,'disable');
      assert.equal(Object.hasOwn(payload,'folder_name'),false);
      workflowState = {...workflowState,enabled:false,can_disable:false,status:'disabled',revision:'c'.repeat(32)};
    }
  }
  await route.fulfill({json:{ok:true,owner_key:'1',state:workflowState}});
});

const source = file => fs.readFile(new URL('../' + file, import.meta.url), 'utf8');
const specialist = page.locator('[data-chat-specialist="email"]');
const panel = page.locator('[data-chat-specialist-panel="email"]');
const inline = page.locator('.vp3-chat-email-specialist-status');
const refreshStatus = () => page.evaluate(() => window.dispatchEvent(new Event('focus')));
const setWorking = ms => {
  status = 'working';
  workingUntil = new Date(Date.now() + ms).toISOString();
};
const send = async message => {
  await page.locator('#chatInput').fill(message);
  await page.locator('#sendChatButton').click();
};
try {
  await page.goto('https://email.test/');
  await page.evaluate(() => {
    window.STONEFELLOW_CHAT = {userId:1,agentId:7,endpoint:'/api/chat.php',csrf:'fixture',initialView:'chat',initialConversationId:42};
    window.VP3_CHAT_EMAIL_V1 = {endpoint:'/api/chat-email-v1.php',ownerKey:'1',csrf:'fixture',agentId:7,connectionsUrl:'/connections.php',
      githubWorkflow:{endpoint:'/connection-gmail-github.php'}};
  });
  for (const file of ['chat.css','team-chat-v109.css','chat-specialist-rail-v1.css','chat-tools.css','chat-email-v1.css']) {
    await page.addStyleTag({content:await source(file)});
  }
  for (const file of ['chat-specialist-rail-v1.js','chat-tools.js','chat-email-v1.js','chat.js']) {
    await page.addScriptTag({content:await source(file)});
  }
  await page.waitForFunction(() => window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady());
  await send(`Show emails from ${sender}`);
  await page.getByText('Email search completed.',{exact:true}).waitFor();
  await page.getByRole('button',{name:'Open result 1'}).click();
  const messageReview = page.getByRole('dialog',{name:'Gmail message'});
  await messageReview.getByText('This is the exact private body.',{exact:true}).waitFor();
  assert.equal(await messageReview.getByRole('button',{name:'Reply',exact:true}).isVisible(),true);
  await messageReview.getByRole('button',{name:'Close'}).click();
  messages.push({id:followupId,role:'assistant',message:'Gmail watch found a reply.',context_json:JSON.stringify({actions:[{
    type:'gmail_followup',message_id:followupId,conversation_id:42,can_reply:true,
  }]})});
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(42));
  await page.getByRole('button',{name:'Open message',exact:true}).click();
  await messageReview.getByText('This is the exact private body.',{exact:true}).waitFor();
  await messageReview.getByRole('button',{name:'Close'}).click();
  assert.deepEqual(readActions,['open_search','open_followup']);
  assert.equal(await specialist.count(),0);
  assert.equal(await inline.isVisible(),false);

  await send(watchPrompt);
  await page.getByText('Gmail watch is active.',{exact:true}).waitFor();
  assert.equal(await specialist.count(),0,'An armed watch is not an active worker.');
  await page.evaluate(() => {
    window.openedActivityHub = 0;
    window.VP3AgentActivityHub = {open:() => { window.openedActivityHub++; }};
  });

  setWorking(2500);
  await refreshStatus();
  await specialist.waitFor();
  assert.equal(await inline.isVisible(),false);
  await page.getByRole('button',{name:'Email Specialist',exact:true}).click();
  await panel.getByText('Checking Gmail',{exact:true}).waitFor();
  await panel.getByText('Looking for new mail matching a watch from this Chat.',{exact:true}).waitFor();
  assert.equal(await page.evaluate(() => window.openedActivityHub),0);
  assert.equal(await panel.getByRole('link').count(),0);
  assert.doesNotMatch(await panel.innerText(),/private@example/);
  await specialist.waitFor({state:'detached',timeout:4500});
  await panel.waitFor({state:'detached'});

  setWorking(30000);
  await refreshStatus();
  await specialist.waitFor();
  await specialist.click();
  await panel.getByRole('button',{name:'Close',exact:true}).click();
  await panel.waitFor({state:'detached'});

  await page.setViewportSize({width:390,height:844});
  await specialist.waitFor({state:'detached'});
  await inline.waitFor();
  assert.match(await inline.textContent(),/Checking Gmail/);
  assert.doesNotMatch(await inline.textContent(),/private@example/);
  await inline.click();
  await panel.getByText('Checking Gmail',{exact:true}).waitFor();
  await panel.getByRole('button',{name:'Close',exact:true}).click();
  assert.equal(await inline.evaluate(node => node === document.activeElement),true);
  await page.setViewportSize({width:1280,height:900});
  await specialist.waitFor();
  await inline.waitFor({state:'hidden'});
  await page.locator('#sfOnlineRailV109').evaluate(rail => { rail.hidden = true; });
  await inline.waitFor();
  await inline.click();
  await panel.getByText('Checking Gmail',{exact:true}).waitFor();
  await panel.getByRole('button',{name:'Close',exact:true}).click();
  await page.locator('#sfOnlineRailV109').evaluate(rail => { rail.hidden = false; });
  await specialist.waitFor();
  await inline.waitFor({state:'hidden'});

  status = 'paused';
  await refreshStatus();
  await specialist.waitFor({state:'detached'});
  assert.equal(await inline.isVisible(),false);

  setWorking(30000);
  await refreshStatus();
  await specialist.waitFor();

  let release;
  const result = new Promise(resolve => { release = resolve; });
  let started;
  const inFlight = new Promise(resolve => { started = resolve; });
  delayStatus = {started,release:result};
  await refreshStatus();
  await inFlight;
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(12));
  await specialist.waitFor({state:'detached'});
  await panel.waitFor({state:'detached'});
  release();
  assert.equal(await inline.isVisible(),false);

  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(42));
  await specialist.waitFor();
  await send(stopPrompt);
  await page.getByText('Gmail watch stopped.',{exact:true}).waitFor();
  await specialist.waitFor({state:'detached'});
  assert.equal(await inline.isVisible(),false);
  assert.ok(checks.includes(12) && checks.includes(42));

  const openDraft = async (id, label) => {
    await page.locator('#chatThread').evaluate((thread,{id,label}) => {
      const button = document.createElement('button');
      button.dataset.chatEmailReview = id;
      button.dataset.chatEmailConversation = '42';
      button.textContent = label;
      thread.append(button);
    },{id,label});
    await page.getByRole('button',{name:label}).click();
  };
  await openDraft(replyDraft.id,'Review fixture reply');
  const replyReview = page.getByRole('dialog',{name:'Review Gmail draft'});
  await replyReview.getByText('Write your reply, then review it before sending.').waitFor();
  const approveReply = replyReview.getByRole('button',{name:'Approve and send'});
  const edit = replyReview.getByRole('button',{name:'Edit manually'});
  const revise = replyReview.getByRole('button',{name:'Save edits'});
  const cancel = replyReview.getByRole('button',{name:'Cancel edits'});
  const subject = replyReview.getByLabel('Subject');
  const body = replyReview.getByLabel('Message');
  assert.equal(await approveReply.isVisible(),false);
  assert.equal(await revise.isDisabled(),true);
  assert.equal(await subject.isEditable(),false);
  assert.equal(await subject.inputValue(),'');
  assert.equal(await body.isEditable(),true);
  await body.fill('This is my reply.');
  assert.equal(await approveReply.isVisible(),false);
  await revise.click();
  await replyReview.getByText('Changes saved. Review the updated email before sending.').waitFor();
  assert.equal(await approveReply.isEnabled(),true);
  assert.equal(await body.isEditable(),false);
  assert.deepEqual(replyCalls.map(call => call.action),['review','revise']);
  await edit.click();
  assert.equal(await subject.isEditable(),false,'The original blank subject remains locked.');
  await body.fill('Unsaved edit.');
  assert.equal(await approveReply.isVisible(),false);
  await cancel.click();
  assert.equal(await body.inputValue(),'This is my reply.');
  assert.equal(await body.isEditable(),false);
  assert.equal(await replyCalls.length,2,'Cancel edits never saves or sends.');
  await approveReply.click();
  await replyReview.getByText('Gmail confirmed the send.',{exact:true}).waitFor();
  await replyReview.getByRole('button',{name:'Close'}).click();

  await openDraft(newDraft.id,'Review fixture new email');
  await edit.waitFor();
  assert.equal(await subject.isEditable(),false);
  assert.equal(await body.isEditable(),false);
  await edit.click();
  await subject.fill('New unsaved subject');
  await body.fill('');
  assert.equal(await revise.isDisabled(),true);
  assert.equal(await approveReply.isVisible(),false);
  await cancel.click();
  assert.equal(await subject.inputValue(),newDraft.subject);
  assert.equal(await body.inputValue(),newDraft.body);
  await edit.click();
  await body.fill('Abandon this edit on Chat switch.');
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(12));
  await replyReview.waitFor({state:'hidden'});
  assert.deepEqual(replyCalls.map(call => call.action),['review','revise','confirm','review']);
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(42));

  await openDraft(newDraft.id,'Review AI revision fixture');
  await replyReview.getByRole('button',{name:'Revise with AI (uses tokens)'}).click();
  const instructions = replyReview.getByLabel('Revision instructions');
  await instructions.fill('Make it friendlier.');
  let finishWriting;
  writingGate = new Promise(resolve => { finishWriting = resolve; });
  await replyReview.getByRole('button',{name:'Generate revision'}).click();
  await replyReview.getByText('The Email Specialist is queued to write this draft.',{exact:true}).waitFor();
  await replyReview.getByRole('button',{name:'Close'}).click();
  await specialist.waitFor();
  await specialist.click();
  await panel.getByText('Revising Gmail draft',{exact:true}).waitFor();
  await panel.getByRole('button',{name:'Close',exact:true}).click();
  await send('I can use Chat while the Email Specialist works.');
  await page.getByText('I can use Chat while the Email Specialist works.',{exact:true}).waitFor();
  await page.getByRole('button',{name:'Review AI revision fixture'}).click();
  await replyReview.getByText('The Email Specialist is revising the saved draft.',{exact:true}).waitFor();
  finishWriting();
  await replyReview.getByText('AI could not finish this draft. Retry with AI or use the manual editor.',{exact:true}).waitFor();
  assert.equal(await body.inputValue(),'Fixture saved body.');
  assert.equal(await approveReply.isVisible(),false);
  writingFails = false;
  await replyReview.getByRole('button',{name:'Retry AI (uses tokens)'}).click();
  await approveReply.waitFor();
  assert.equal(await body.inputValue(),'Thanks for reaching out. I appreciate it.');
  assert.equal(await subject.inputValue(),'Fixture new subject');
  assert.equal(replyCalls.filter(call=>call.action==='advance_write').length,2);
  assert.equal(replyCalls.filter(call=>call.action==='confirm').length,1,'AI writing never submits an email.');
  await replyReview.getByRole('button',{name:'Close'}).click();
  await specialist.waitFor({state:'detached'});

  Object.assign(newDraft,{status:'write_queued',write_kind:'compose',body:'',review_token:null});
  writingGate = new Promise(resolve => { finishWriting = resolve; });
  messages.push({id:nextId++,role:'assistant',message:'Drafting a reply in the exact Gmail thread.',
    context_json:JSON.stringify({actions:[{type:'email_review',id:newDraft.id,conversation_id:42,is_reply:true,writing:true}]})});
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(42));
  await page.getByRole('button',{name:'Review reply',exact:true}).waitFor();
  await specialist.waitFor();
  assert.equal(await replyReview.isVisible(),false,'The queued writer starts with the review closed.');
  await specialist.click();
  await panel.getByText('Writing Gmail draft',{exact:true}).waitFor();
  await panel.getByRole('button',{name:'Close',exact:true}).click();
  await page.getByRole('button',{name:'Review reply',exact:true}).click();
  await replyReview.getByText('The Email Specialist is writing the reply.',{exact:true}).waitFor();
  finishWriting();
  await approveReply.waitFor();
  assert.equal(replyCalls.filter(call=>call.action==='advance_write').length,3,'Opening a working draft reuses its active writer.');
  assert.equal(replyCalls.filter(call=>call.action==='confirm').length,1);
  await replyReview.getByRole('button',{name:'Close'}).click();
  await specialist.waitFor({state:'detached'});

  await page.setViewportSize({width:390,height:844});
  const tools = page.getByRole('dialog',{name:'Chat tools'});
  const gmail = tools.getByText('Gmail',{exact:true});
  const workflow = tools.locator('.vp3-chat-github-workflow');
  const workflowToggle = workflow.getByRole('checkbox',{name:'GitHub Workflow Review'});
  const folder = workflow.getByLabel(/Private Knowledge folder name/);
  const saveWorkflow = workflow.getByRole('button',{name:'Save workflow review'});
  await page.getByRole('button',{name:'Chat tools'}).click();
  assert.equal(workflowCalls.length,0,'opening Chat tools alone never requests private Gmail settings');
  await gmail.click();
  await workflow.getByText('Workflow review is off.',{exact:true}).waitFor();
  assert.equal(await workflowToggle.isChecked(),false);
  assert.equal(await folder.isVisible(),false);
  assert.match(await workflow.innerText(),/Announcements follow your Agent Voice setting/);
  assert.deepEqual(workflowCalls,[{action:'state',owner_key:'1',csrf_token:'fixture'}]);
  await workflowToggle.check();
  assert.equal(await folder.inputValue(),'');
  assert.equal(await saveWorkflow.isDisabled(),true);
  assert.equal(workflowCalls.length,1,'checking the option requires an explicit folder and save');
  await folder.fill(workflowFolder);
  await saveWorkflow.click();
  await workflow.getByText('Waiting for the next background check.',{exact:true}).waitFor();
  assert.equal(workflowCalls.at(-1).action,'enable');
  assert.equal(await folder.inputValue(),workflowFolder);

  await page.evaluate(() => {
    window.workflowParticipantSelected = true;
    window.VP3ParticipantContinuity = {selectedContext:() => window.workflowParticipantSelected ? {participant_id:'2'} : null};
    window.dispatchEvent(new Event('vp3:participant-selection-changed'));
  });
  await workflow.waitFor({state:'hidden'});
  assert.equal(await workflow.locator('input[name="folder_name"]').inputValue(),'');
  assert.equal(await workflow.locator('datalist option').count(),0);
  await page.evaluate(() => {
    window.workflowParticipantSelected = false;
    window.dispatchEvent(new Event('vp3:participant-selection-changed'));
  });
  await workflow.getByText('Waiting for the next background check.',{exact:true}).waitFor();
  assert.equal(await folder.inputValue(),workflowFolder);
  assert.equal(workflowCalls.at(-1).action,'state');

  await page.evaluate(() => {
    window.STONEFELLOW_CHAT.userId = 2;
    window.dispatchEvent(new Event('stonefellow:chat-context-changing'));
  });
  await workflow.waitFor({state:'hidden'});
  assert.equal(await workflow.locator('input[name="folder_name"]').inputValue(),'');
  await page.evaluate(() => {
    window.STONEFELLOW_CHAT.userId = 1;
    window.dispatchEvent(new Event('vp3:chat-context-ready'));
  });
  await workflow.getByText('Waiting for the next background check.',{exact:true}).waitFor();
  await workflowToggle.uncheck();
  await saveWorkflow.click();
  await workflow.getByText('Workflow review is off.',{exact:true}).waitFor();
  assert.equal(workflowCalls.at(-1).action,'disable');
  assert.equal(await folder.isVisible(),false);
  assert.deepEqual(errors,[]);
  console.log('CHAT_EMAIL_SPECIALIST_BROWSER=PASS (Chat receipt, specialist rail, Gmail workflow preferences, participant privacy and cancellation)');
} finally {
  await browser.close();
}
