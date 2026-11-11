import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const script=await fs.readFile(new URL('../chat.js',import.meta.url),'utf8');
const css=await fs.readFile(new URL('../chat.css',import.meta.url),'utf8');
const browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const preview=(overrides={})=>({version:1,owner_user_id:1,agent_id:7,conversation_id:42,plan_id:91,plan_hash:'b'.repeat(64),
  status:'approval_pending',label:'Create meeting task',summary:'Save the selected follow-up from Launch review.',
  details:[{label:'Task',value:'Prepare launch <img src=x onerror=alert(1)>'},{label:'Meeting',value:'Launch <script>alert(1)</script>'}],
  confirmation_text:'Confirm meeting action 91',...overrides});
const assistant=(id,action,message='Here are the exact details to review.')=>({id,role:'assistant',message,
  context_json:JSON.stringify(action?{meeting_action:action}:{})});
const cards=page=>page.locator('[data-meeting-action-plan]');
const pending=page=>page.locator('[data-meeting-action-plan][data-meeting-action-status="approval_pending"]');
const settled=page=>page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady()&&!window.STONEFELLOW_CHAT_CONTINUITY_V87.isBusy());
async function send(page,text){await page.locator('#chatInput').fill(text);await page.locator('#chatForm').evaluate(form=>form.requestSubmit());await settled(page);}

async function fixture({mobile=false,messages=[assistant(10,preview())],respond}={}){
  const context=await browser.newContext({viewport:{width:mobile?390:1280,height:850}}),page=await context.newPage();
  page.setDefaultTimeout(10000);
  const errors=[],sends=[];let nextId=Math.max(20,...messages.map(message=>message.id));
  page.on('pageerror',error=>errors.push(error.message));
  await page.route('https://meeting-action.test/**',async route=>{
    const url=new URL(route.request().url());
    if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:`<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/chat.css"><body class="chat-page"><div class="chat-app"><aside class="chat-sidebar"><div id="chatHistory"></div><button id="newChatButton">New Chat</button></aside><main class="chat-main"><header class="chat-topbar">Agent Chat</header><section id="chatThread" class="chat-thread" data-view="chat"><div id="chatWelcome" hidden></div></section><div class="chat-composer-shell" id="chatComposerShell"><form id="chatForm" class="chat-composer"><textarea id="chatInput"></textarea><button type="button" id="chatVoiceButton">Listen</button><button id="sendChatButton">Send</button></form></div></main></div><script>window.STONEFELLOW_CHAT={userId:1,agentId:7,initialConversationId:42,initialView:'chat',endpoint:'/api/chat-v236.php',csrf:'fixture',workingAttentionBinding:'meeting-owner-session'};window.SpeechRecognition=class {constructor(){window.meetingRecognition=this;}start(){}stop(){}};</script><script src="/chat.js"></script></body></html>`});
    if(url.pathname==='/chat.js')return route.fulfill({contentType:'text/javascript',body:script});
    if(url.pathname==='/chat.css')return route.fulfill({contentType:'text/css',body:css});
    const body=route.request().postDataJSON();
    if(body.action==='send'){
      sends.push(body);
      const result=respond?await respond(body,sends.length):{meeting_action:preview({status:'completed',summary:'Task saved and independently verified.'}),answer:'Saved the task.'};
      const userId=++nextId,assistantId=++nextId;
      messages.push({id:userId,role:'user',message:body.message},assistant(assistantId,result.meeting_action,result.answer));
      return route.fulfill({json:{ok:true,conversation_id:42,user_message_id:userId,assistant_message_id:assistantId,
        answer:result.answer,meeting_action:result.meeting_action,sources:[],actions:[]}});
    }
    return route.fulfill({json:{ok:true,conversation_id:body.conversation_id,conversations:[],updates:[],unread_count:0,
      messages:body.action==='load'?messages:body.action==='messages_after'?messages.filter(message=>message.id>body.after_id||(body.pending_message_ids||[]).includes(message.id)):[]}});
  });
  const open=async()=>{await page.goto('https://meeting-action.test/');await settled(page);return page;};
  return {page,context,errors,sends,messages,open};
}

try{
  for(const mobile of [false,true]){
    const action=preview(),messages=[assistant(10,action),
      ...[{owner_user_id:2},{agent_id:8},{conversation_id:43},{plan_hash:'bad'},{status:'executing'},
        {confirmation_text:'Send money'},{details:[{label:'Bad',value:{unexpected:true}}]}]
        .map((change,index)=>assistant(11+index,preview(change),'Unrelated or malformed projection.'))];
    const f=await fixture({mobile,messages});
    try{
      const page=await f.open();assert.equal(await cards(page).count(),1);
      assert.equal(await pending(page).getByText(action.details[0].value,{exact:true}).count(),1);
      assert.equal(await pending(page).getByText(action.details[1].value,{exact:true}).count(),1);
      assert.equal(await cards(page).locator('img,script').count(),0,'all review values are inert text');
      assert.equal(await page.locator('[role="dialog"]').count(),0,'review stays in the normal Chat');
      const box=await pending(page).boundingBox();
      assert.ok(box.width>=(mobile?250:400)&&box.height<450,'review has a usable compact layout');
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
      await pending(page).getByRole('button',{name:'Confirm',exact:true}).click();await settled(page);
      assert.equal(f.sends.length,1);assert.equal(f.sends[0].message,'Confirm meeting action 91');assert.equal(f.sends[0].input_mode,'text');
      assert.equal(await pending(page).count(),0);assert.equal(await cards(page).getByRole('button').count(),0);
      assert.equal(await cards(page).last().getByText('Task saved and independently verified.',{exact:true}).count(),1);
      await page.reload();await settled(page);
      assert.equal(await pending(page).count(),0);assert.equal(await cards(page).getByRole('button').count(),0);
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);assert.deepEqual(f.errors,[]);
      console.log(`PASS ${mobile?'mobile':'desktop'} scoped inline review, safe text, exact confirmation, completed retirement and history reload`);
    }finally{await f.context.close();}
  }

  const edited=preview({plan_id:92,plan_hash:'c'.repeat(64),confirmation_text:'Confirm meeting action 92',
    details:[{label:'Task',value:'Prepare revised launch draft'}]});
  const edit=await fixture({respond:(_body,n)=>n===1?{answer:'I revised the draft for your review.',meeting_action:edited}
    :{answer:'Saved the revised task.',meeting_action:{...edited,status:'completed',summary:'Revised task saved.'}}});
  try{
    const page=await edit.open();await page.evaluate(()=>{window.oldMeetingConfirm=document.querySelector('[data-meeting-action-controls] button');});
    await send(page,'Change the task to Prepare revised launch draft');
    assert.equal(await pending(page).count(),1);assert.equal(await pending(page).getAttribute('data-meeting-action-plan'),'92');
    assert.equal(await page.getByText('Replaced by a newer review',{exact:true}).count(),1);
    await page.evaluate(()=>window.oldMeetingConfirm.click());assert.equal(edit.sends.length,1,'a detached old control cannot send');
    await pending(page).getByRole('button',{name:'Confirm',exact:true}).click();await settled(page);
    assert.deepEqual(edit.sends.map(row=>row.message),['Change the task to Prepare revised launch draft','Confirm meeting action 92']);
    assert.equal(await pending(page).count(),0);assert.deepEqual(edit.errors,[]);
    console.log('PASS editing creates a new exact review and retires the older control before confirmation');
  }finally{await edit.context.close();}

  const cancel=await fixture({respond:()=>({answer:'Cancelled the reviewed action.',meeting_action:preview({status:'cancelled',summary:'Nothing was saved.'})})});
  try{
    const page=await cancel.open();await page.locator('#chatInput').fill('My unfinished message');
    await pending(page).getByRole('button',{name:'Cancel',exact:true}).click();assert.equal(cancel.sends.length,0);
    assert.equal(await page.locator('#chatInput').inputValue(),'My unfinished message');
    await page.locator('#chatInput').fill('');await pending(page).getByRole('button',{name:'Cancel',exact:true}).click();await settled(page);
    assert.equal(cancel.sends[0].message,'Cancel meeting action 91');assert.equal(await pending(page).count(),0);assert.deepEqual(cancel.errors,[]);
    console.log('PASS cancellation uses an exact ordinary Chat command and controls preserve existing composer drafts');
  }finally{await cancel.context.close();}

  const identity=await fixture();
  try{
    const page=await identity.open();await page.evaluate(()=>{window.STONEFELLOW_CHAT={...window.STONEFELLOW_CHAT,userId:2};});
    await pending(page).getByRole('button',{name:'Confirm',exact:true}).click();assert.equal(identity.sends.length,0);
    assert.equal(await pending(page).getByRole('button',{name:'Confirm',exact:true}).isDisabled(),true);assert.deepEqual(identity.errors,[]);
    console.log('PASS an account change fences a previously rendered confirmation control');
  }finally{await identity.context.close();}

  const voice=await fixture({messages:[],respond:(_body,n)=>n===1
    ?{answer:'Which meeting: Launch review or Design review?'}
    :n===2?{answer:'Here is the Launch review task.',meeting_action:preview()}
    :{answer:'Saved the meeting task.',meeting_action:preview({status:'completed',summary:'Task saved.'})}});
  try{
    const page=await voice.open();
    const speak=text=>page.evaluate(value=>{const result=[{transcript:value}];result.isFinal=true;window.meetingRecognition.onresult({resultIndex:0,results:[result]});},text);
    await speak('Create a task from that meeting');await settled(page);assert.equal(await cards(page).count(),0,'ambiguity creates no confirmation');
    await page.getByText('Which meeting: Launch review or Design review?',{exact:true}).waitFor();
    await speak('Use Launch review');await settled(page);assert.equal(await pending(page).count(),1);
    await speak('Yes');await settled(page);assert.equal(await pending(page).count(),0);
    assert.deepEqual(voice.sends.map(row=>row.input_mode),['voice','voice','voice']);
    assert.deepEqual(voice.sends.map(row=>row.message),['Create a task from that meeting','Use Launch review','Yes']);assert.deepEqual(voice.errors,[]);
    console.log('PASS spoken ambiguity, selection, review and plain confirmation use the same normal Chat renderer and send path');
  }finally{await voice.context.close();}

  const sync=await fixture({messages:[]});
  try{
    const page=await sync.open();sync.messages.push(assistant(10,preview()));
    await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.syncConversation(42));assert.equal(await pending(page).count(),1);
    sync.messages.push(assistant(11,{...edited,status:'completed',summary:'The replacement task was saved.'}));
    await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.syncConversation(42));assert.equal(await pending(page).count(),0,'completion retires an old review even if its replacement preview was missed');
    sync.messages.push(assistant(12,preview({plan_id:93,plan_hash:'d'.repeat(64),confirmation_text:'Confirm meeting action 93'})));
    await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.syncConversation(42));assert.equal(await pending(page).count(),1);
    sync.messages[2]=assistant(12,preview({plan_id:93,plan_hash:'d'.repeat(64),status:'stale',summary:'The meeting changed. Request a fresh review.'}),'The meeting changed. Request a fresh review.');
    await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.syncConversation(42));assert.equal(await pending(page).count(),0);
    assert.equal(await cards(page).count(),3,'same-row revalidation updates the existing review without duplicating it');
    assert.equal(await page.locator('[data-chat-message-id="12"] .message-text').textContent(),'The meeting changed. Request a fresh review.');
    assert.equal(await cards(page).getByRole('button').count(),0);assert.deepEqual(sync.errors,[]);
    console.log('PASS synchronized reviews render in Chat and same-row stale source projections retire controls without duplicate cards');
  }finally{await sync.context.close();}
}finally{await browser.close();}
