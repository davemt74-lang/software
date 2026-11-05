import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {createRequire} from 'node:module';

const {chromium} = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const asset = name => fs.readFile(new URL('../' + name, import.meta.url), 'utf8');
const template = await asset('chat.php');
const statusPopover = template.match(/<section class="chat-agent-brief-popover"[\s\S]*?<\/section>/)?.[0];
assert.ok(statusPopover);
assert.doesNotMatch(template, /chat-catch-up-v1\.(?:css|js)/);
const browser = await chromium.launch({
  headless: true,
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined
});
const errors = [];
const answer = 'Your 2 p.m. meeting is next. Review the agenda before it starts.';
const sources = [
  {source:'agent-catch-up:calendar:2',title:'Open calendar',url:'/calendar.php?event=2'},
  {source:'agent-catch-up:invalid',title:'Invalid source',url:'//unrelated.test/data'}
];

try {
  for (const width of [320, 1280]) {
    const page = await browser.newPage({viewport:{width,height:740}});
    page.setDefaultTimeout(10000);
    page.on('pageerror', error => errors.push(error.message));
    const sends = [];
    const briefReads = [];
    const directReads = [];
    const history = Array.from({length:30}, (_, i) => ({
      id:i + 1, role:i % 2 ? 'assistant' : 'user', message:'Earlier conversation ' + (i + 1)
    }));
    await page.route('http://catch-up.test/', route => route.fulfill({contentType:'text/html',body:`
      <!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
      <div class="chat-app"><aside class="chat-sidebar"><button id="newChatButton">New chat</button>
      <div id="chatHistory"></div></aside><main class="chat-main">
      <header class="chat-topbar">Agent Chat</header><section class="chat-thread" id="chatThread"><div id="chatWelcome" hidden></div></section>
      <div class="chat-composer-shell" id="chatComposerShell">${statusPopover}
      <form class="chat-composer" id="chatForm"><button type="button" id="chatAgentBriefButton" aria-label="Agent status">✦</button>
      <textarea id="chatInput" aria-label="Message"></textarea><button type="submit" id="sendChatButton">Send</button>
      </form></div></main></div>`}));
    await page.route('**/api/**', async route => {
      const request = route.request();
      const path = new URL(request.url()).pathname;
      const body = request.postDataJSON() || {};
      if (path === '/api/agent-catch-up-v1.php') {
        directReads.push(request.url());
        return route.fulfill({status:503,json:{ok:false}});
      }
      if (path === '/api/cognitive-presentation-v510.php') {
        if (request.method() === 'GET') briefReads.push({
          binding:request.headers()['x-vp3-working-attention'],
          includeWorkingAttention:new URL(request.url()).searchParams.get('include_working_attention')
        });
        return route.fulfill({json:{ok:true,state:{brief:{agent_name:'Longfellow',status_label:'Active'}}}});
      }
      if (body.action === 'send') {
        sends.push({...body,pageBinding:request.headers()['x-vp3-working-attention']});
        const id = history.length + 1;
        history.push({id,role:'user',message:body.message},
          {id:id + 1,role:'assistant',message:answer,context_json:JSON.stringify({sources})});
        return route.fulfill({json:{ok:true,conversation_id:42,assistant_message_id:id + 1,answer,sources}});
      }
      return route.fulfill({json:{ok:true,messages:body.action === 'load' ? history : [],
        conversations:[{id:42,title:'Current Chat'}]}});
    });

    async function boot() {
      await page.goto('http://catch-up.test/');
      await page.evaluate(() => {
        window.STONEFELLOW_CHAT = {userId:1,agentId:17,initialConversationId:42,
          endpoint:'/api/chat-v236.php',initialView:'chat',csrf:'fixture',workingAttentionBinding:'owner-a-session'};
        window.VP3_COGNITIVE_PRESENTATION_V510 = {agentId:17,workingAttentionBinding:'owner-a-session'};
      });
      for (const name of ['chat.css','chat-cognitive-presentation-v510.css']) {
        await page.addStyleTag({content:await asset(name)});
      }
      for (const name of ['chat.js','chat-cognitive-presentation-v510.js']) {
        await page.addScriptTag({content:await asset(name)});
      }
      await page.getByText('Earlier conversation 30',{exact:true}).waitFor();
      await page.getByRole('button',{name:'Longfellow · Active'}).waitFor();
    }

    await boot();
    const thread = page.locator('#chatThread');
    const input = page.getByRole('textbox',{name:'Message'});
    const status = page.getByRole('button',{name:'Longfellow · Active'});
    const catchUp = page.getByRole('button',{name:'Catch me up',exact:true});
    assert.equal(await thread.locator('.message').count(),30);
    assert.equal(await catchUp.isVisible(),false);
    assert.deepEqual(briefReads,[{binding:'owner-a-session',includeWorkingAttention:'0'}]);
    await thread.evaluate(node => { node.scrollTop = node.scrollHeight; });
    await input.fill('Unsent draft');
    await status.click();
    await catchUp.click();
    assert.equal(await input.inputValue(),'Unsent draft');
    assert.equal(sends.length,0);

    await input.fill('');
    await status.click();
    await catchUp.click();
    await thread.getByText(answer,{exact:true}).waitFor();
    assert.equal(sends.length,1);
    assert.equal(sends[0].message,'Catch me up');
    assert.equal(sends[0].conversation_id,42);
    assert.equal(sends[0].pageBinding,'owner-a-session');
    assert.equal(await thread.locator('.message.assistant').last().getByRole('link',{name:'Open calendar'}).getAttribute('href'),'http://catch-up.test' + sources[0].url);
    assert.equal(await thread.getByRole('link',{name:'Invalid source'}).count(),0);
    assert.equal(await catchUp.isVisible(),false);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));

    await boot();
    assert.equal(await thread.getByText(answer,{exact:true}).count(),1);
    assert.equal(await thread.getByRole('link',{name:'Open calendar'}).count(),1);
    assert.equal(await thread.locator('.message').count(),32);
    assert.equal(await thread.locator('[data-chat-catch-up],[data-agent-intro]').count(),0);
    assert.equal(sends.length,1);
    assert.deepEqual(directReads,[]);
    await page.close();
  }
  assert.deepEqual(errors,[]);
  console.log('PASS Catch me up uses one saved Chat reply on desktop and mobile, preserves drafts and stays quiet on reload');
} finally {
  await browser.close();
}
