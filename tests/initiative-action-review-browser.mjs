import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const script=await fs.readFile(new URL('../chat.js',import.meta.url),'utf8');
const css=await fs.readFile(new URL('../chat.css',import.meta.url),'utf8');
const browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
try{
  for(const mobile of [false,true]){
    const context=await browser.newContext({viewport:{width:mobile?390:1280,height:850}}),page=await context.newPage(),errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    const outcome={owner_user_id:1,agent_id:7,conversation_id:42,message_id:10,run_id:90,reply_ref:'a'.repeat(64),revision:2,state:'discussed',reply_message_id:11,continuation_run_id:0};
    const action={run_id:91,status:'approval_pending',plan_hash:'b'.repeat(64),adapter:'task_list.add',arguments:{list_id:7,title:'Prepare launch <img src=x onerror=alert(1)>'},targets:[{ref:'task-list:7',title:'Launch <script>alert(1)</script>'}],effect_class:'private_record',review_required:true,receipt_id:0};
    const marker={owner_user_id:1,agent_id:7,conversation_id:42,run_id:90,review_actions:[],followthrough:{reply_ref:outcome.reply_ref,revision:2,state:'discussed'}};
    const messages=[{id:10,role:'assistant',message:'I can help prepare the launch.',context_json:JSON.stringify({source:'agent_initiative',initiative_work:marker})},
      {id:11,role:'user',message:'Create a launch task.'},
      {id:12,role:'assistant',message:'Here are the exact details to review.',context_json:JSON.stringify({initiative_followthrough:outcome,initiative_action:action})},
      ...[{...outcome,owner_user_id:2},{...outcome,agent_id:8},{...outcome,conversation_id:43}].map((scope,index)=>({id:13+index,role:'assistant',message:'Foreign review.',context_json:JSON.stringify({initiative_followthrough:scope,initiative_action:action})})),
      {id:16,role:'assistant',message:'Unregistered action.',context_json:JSON.stringify({initiative_followthrough:outcome,initiative_action:{...action,adapter:'payment.send'}})},
      {id:17,role:'assistant',message:'Unexpected action data.',context_json:JSON.stringify({initiative_followthrough:outcome,initiative_action:{...action,arguments:{owner_user_id:2}}})}];
    await page.route('https://initiative-action.test/**',async route=>{
      const url=new URL(route.request().url());
      if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:`<!doctype html><html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/chat.css"><body class="chat-page"><div class="chat-app"><main class="chat-main"><div id="chatHistory"></div><button id="newChatButton">New Chat</button><section id="chatThread" class="chat-thread" data-view="chat"><div id="chatWelcome" hidden></div></section><form id="chatForm" class="chat-composer"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form></main></div><script>window.STONEFELLOW_CHAT={userId:1,agentId:7,initialConversationId:42,initialView:'chat',endpoint:'/api/chat-v236.php',csrf:'fixture',workingAttentionBinding:'action-owner-session'};</script><script src="/chat.js"></script></body></html>`});
      if(url.pathname==='/chat.js')return route.fulfill({contentType:'text/javascript',body:script});
      if(url.pathname==='/chat.css')return route.fulfill({contentType:'text/css',body:css});
      const body=route.request().postDataJSON();return route.fulfill({json:{ok:true,messages:body.action==='load'?messages:[],conversations:[],updates:[],conversation_id:body.conversation_id,unread_count:0}});
    });
    await page.goto('https://initiative-action.test/');await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady());
    assert.equal(await page.locator('.chat-initiative-action-review').count(),1);
    const review=page.locator('.chat-initiative-action-review');
    assert.equal(await review.getByText(action.arguments.title,{exact:true}).count(),1);
    assert.equal(await review.locator('img').count(),0,'reviewed text is not executable markup');
    assert.equal(await review.getByText(action.targets[0].title+' · task-list:7',{exact:true}).count(),1);
    assert.equal(await review.locator('script').count(),0,'canonical target labels are also escaped text');
    assert.equal(await review.getByText('Review before saving',{exact:true}).count(),1);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    await review.getByRole('button',{name:'Reply',exact:true}).click();
    assert.equal(await page.locator('#chatInitiativeReplyCue').count(),1);
    await page.locator('#chatInitiativeReplyCue button').click();
    await page.evaluate(()=>{window.savedActionButton=document.querySelector('[data-initiative-action-reply]');window.originalActionConfig=window.STONEFELLOW_CHAT;window.STONEFELLOW_CHAT={...window.STONEFELLOW_CHAT,userId:2};window.savedActionButton.click();});
    assert.equal(await page.locator('#chatInitiativeReplyCue').count(),0);
    assert.equal(await review.getByRole('button',{name:'Reply'}).isDisabled(),true);
    await page.evaluate(()=>{window.STONEFELLOW_CHAT=window.originalActionConfig;});
    messages.push({id:18,role:'assistant',message:'The task was saved and verified.',context_json:JSON.stringify({initiative_followthrough:{...outcome,revision:3,reply_message_id:18},initiative_action:{...action,status:'completed',receipt_id:55}})});
    await page.reload();await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady());
    assert.equal(await page.locator('.chat-initiative-action-review').count(),2);
    assert.equal(await page.locator('[data-initiative-action-reply]').count(),0,'verified completion retires the earlier review on reload');
    assert.equal(await page.getByText('Completed · verified receipt #55',{exact:true}).count(),2);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    assert.deepEqual(errors,[]);
    console.log('PASS '+(mobile?'mobile':'desktop')+' exact main Chat action review, escaped parameters, owner/Agent/Chat scope, reply association, account change and receipt-backed retirement');
    await context.close();
  }
}finally{await browser.close();}
