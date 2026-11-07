import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const script = await fs.readFile(new URL('../chat.js',import.meta.url),'utf8');
const browser = await chromium.launch({headless:true,
  executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
const uuid = '7971c1d8-612f-4ffa-baca-67824d43d841';
const revision = 'a'.repeat(64);
try {
  for (const agent of [0,7]) {
    const context = await browser.newContext({viewport:{width:agent ? 390 : 1280,height:850}});
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror',error => errors.push(error.message));
    const marker = {owner_user_id:1,agent_id:agent,conversation_id:42,run_id:90,
      review_actions:[{tool:'product',source_id:'31',source_revision:revision},
        {tool:'screen',source_id:uuid,source_revision:revision},
        {tool:'mission',source_id:uuid,source_revision:revision},
        {tool:'product',source_id:'31',source_revision:revision},
        {tool:'javascript',source_id:'31',source_revision:revision},
        {tool:'product',source_id:'https://foreign.test/',source_revision:revision},
        {tool:'screen',source_id:'<img onerror=alert(1)>',source_revision:revision},
        {tool:'mission',source_id:uuid,source_revision:'unverified'}]};
    const messages = [marker,{...marker,owner_user_id:2},{...marker,agent_id:agent+1},
      {...marker,conversation_id:43},{...marker,run_id:0}].map((work,index) => ({id:10+index,role:'assistant',
        message:'A verified review is available.',context_json:JSON.stringify({initiative_work:work})}));
    const launch = {owner_user_id:1,agent_id:agent,conversation_id:42,product_id:105,source_id:'31',
      draft_id:'d'.repeat(64),creative_id:'e'.repeat(32),stage:'creative_ready'};
    const launchMessage = (id, product_launch, source='agent_product_launch', role='assistant') => ({id,role,
      message:'A product launch update is available.',context_json:JSON.stringify({source,product_launch})});
    const photoNeeded = {owner_user_id:1,agent_id:agent,conversation_id:42,product_id:105,stage:'photo_needed'};
    messages.push(launchMessage(20,launch), launchMessage(21,{...launch,owner_user_id:2}),
      launchMessage(22,{...launch,agent_id:agent+1}), launchMessage(23,{...launch,conversation_id:43}),
      launchMessage(24,{...launch,creative_id:'other-creative'}), launchMessage(25,{...launch,stage:'constructor'}),
      launchMessage(26,launch,'assistant'), launchMessage(27,launch,'agent_product_launch','user'),
      launchMessage(28,{...launch,source_id:'031'}), launchMessage(29,photoNeeded));
    const publication = {...launch,stage:'publication_review'};
    const pollMessage = launchMessage(40,publication);
    await page.route('https://initiative-review.test/**',async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/') return route.fulfill({contentType:'text/html',body:`<!doctype html><meta charset="utf-8">
        <button id="newChatButton">New Chat</button><div id="chatHistory"></div>
        <section id="chatThread" data-view="chat"><div id="chatWelcome" hidden></div></section>
        <form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form>
        <script>window.STONEFELLOW_CHAT={userId:1,agentId:${agent},initialConversationId:42,initialView:'chat',
          endpoint:'/api/chat-v236.php',csrf:'fixture',workingAttentionBinding:'owner-a-session'};
          window.reviewCalls=[];window.launchCalls=[];
          window.VP3InitiativeWorkbench={open:(...args)=>window.reviewCalls.push(args),openProductLaunch:marker=>{window.launchCalls.push(marker);return true;}};</script>
        <script src="/chat.js"></script>`});
      if (url.pathname === '/chat.js') return route.fulfill({contentType:'text/javascript',body:script});
      const body = route.request().postDataJSON();
      const polled = body.action==='messages_after' && body.conversation_id===42 && body.after_id<pollMessage.id;
      if (polled && !messages.includes(pollMessage)) messages.push(pollMessage);
      return route.fulfill({json:{ok:true,messages:body.action==='load'?messages:polled?[pollMessage]:[],conversations:[],
        conversation_id:body.conversation_id,latest_id:0,unread_count:0,updates:[]}});
    });
    await page.goto('https://initiative-review.test/');
    await page.waitForFunction(() => window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady());
    assert.equal(await page.locator('[data-initiative-review]').count(),3);
    assert.equal(await page.locator('[data-product-launch]').count(),2);
    assert.deepEqual(await page.evaluate(() => window.launchCalls),[]);
    await page.getByRole('button',{name:'Choose a photo for marketing',exact:true}).click();
    await page.getByRole('button',{name:'Review creative in Canvas',exact:true}).click();
    assert.deepEqual(await page.evaluate(() => window.launchCalls),[photoNeeded,launch]);
    await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.syncConversation(42));
    await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.syncConversation(42));
    assert.equal(await page.locator('[data-product-launch]').count(),3);
    await page.getByRole('button',{name:'Review promotion in Canvas',exact:true}).click();
    assert.deepEqual(await page.evaluate(() => window.launchCalls),[photoNeeded,launch,publication]);
    await page.reload();
    await page.locator('[data-product-launch="publication_review"]').waitFor();
    assert.equal(await page.locator('[data-product-launch]').count(),3);
    assert.deepEqual(await page.evaluate(() => window.launchCalls),[]);
    await page.getByRole('button',{name:'Review listing draft',exact:true}).click();
    assert.deepEqual(await page.evaluate(() => window.reviewCalls),[['product','31',revision]]);
    await page.evaluate(() => {
      window.originalChatConfig=window.STONEFELLOW_CHAT;
      window.savedReviewButton=document.querySelector('[data-initiative-review="product"]');
      window.savedLaunchButton=document.querySelector('[data-product-launch="creative_ready"]');
      window.STONEFELLOW_CHAT={...window.STONEFELLOW_CHAT,userId:2};
      window.savedReviewButton.click();
      window.savedLaunchButton.click();
    });
    assert.equal(await page.evaluate(() => window.reviewCalls.length),1);
    await page.evaluate(() => {
      window.STONEFELLOW_CHAT={...window.originalChatConfig,workingAttentionBinding:'new-session'};
      window.savedReviewButton.disabled=false;window.savedReviewButton.click();
      window.savedLaunchButton.disabled=false;window.savedLaunchButton.click();
    });
    assert.equal(await page.evaluate(() => window.reviewCalls.length),1);
    await page.evaluate(async () => {
      window.STONEFELLOW_CHAT=window.originalChatConfig;
      await window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(43);
      window.savedReviewButton.disabled=false;window.savedReviewButton.click();
      window.savedLaunchButton.disabled=false;window.savedLaunchButton.click();
    });
    assert.equal(await page.evaluate(() => window.reviewCalls.length),1);
    assert.deepEqual(await page.evaluate(() => window.launchCalls),[]);
    assert.deepEqual(errors,[]);
    console.log(`PASS ${agent?'named mobile':'System desktop'} saved reviews: enum/schema, owner/Agent/Chat binding, dedupe, account/session changes and removed-message fence`);
    console.log(`PASS ${agent?'named mobile':'System desktop'} launch reviews: exact durable marker on sync and reload, explicit open, and owner/Agent/Chat fence`);
    await context.close();
  }
} finally { await browser.close(); }
