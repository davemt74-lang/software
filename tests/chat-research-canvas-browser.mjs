import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const source = file => fs.readFile(new URL('../' + file, import.meta.url), 'utf8');
const browser = await chromium.launch({
  headless: true,
  args: ['--no-sandbox'],
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined
});
try {
  const page = await browser.newPage({viewport:{width:1280,height:850}});
  page.setDefaultTimeout(8000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const checkedAt = '2026-11-09T14:23:06Z';
  const summary = 'I checked public sources about telescope calibration.';
  const web = (kind, id, title, url, coverage) => ({
    source: kind + '-web:' + id.repeat(64), title, url, coverage
  });
  const topicSources = [
    web('topic','a','Observatory handbook <img src=x>','https://observatory.example/handbook','page_excerpt'),
    web('topic','b','A lead from a university','https://university.example/optics','search_match'),
    web('topic','c','Unsafe script','javascript:alert(1)','search_match'),
    web('topic','d','Insecure page','http://observatory.example/insecure','search_match'),
    web('topic','e','Credentials in URL','https://member:password@university.example/','search_match')
  ];
  const report = (id, run, text, overrides = {}, options = {}) => ({
    id, role: options.role || 'assistant', message: text,
    context_json: JSON.stringify({
      source: options.source || 'agent_initiative',
      sources: options.legacySources || [],
      research_canvas_v1: {
        owner_user_id:1, agent_id:7, conversation_id:42, run_id:run,
        kind:'topic', title:'Telescope calibration', status:'read_complete', checked_at:checkedAt,
        sources:topicSources, ...overrides
      }
    })
  });
  const messages = [
    report(10, 100, summary, {}, {legacySources:topicSources}),
    report(11, 101, 'A mismatched owner.', {owner_user_id:2}),
    report(12, 102, 'A mismatched Agent.', {agent_id:8}),
    report(13, 103, 'A mismatched Chat.', {conversation_id:99}),
    report(14, 104, 'Ordinary user text.', {}, {role:'user'}),
    report(15, 105, 'An ordinary assistant reply.', {}, {source:'agent_chat'})
  ];
  await page.route('https://research.test/', route => route.fulfill({contentType:'text/html',body:`
    <!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <div class="chat-app"><aside class="chat-sidebar"><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside>
    <main class="chat-main"><header class="chat-topbar">Agent Chat</header><section class="chat-thread" id="chatThread"><div id="chatWelcome" hidden></div></section>
    <div class="chat-composer-shell" id="chatComposerShell"><form class="chat-composer" id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form></div></main></div>
  `}));
  await page.route('**/api/chat-v236.php', route => {
    const body = route.request().postDataJSON();
    const rows = body.conversation_id === 42 ? messages : [{id:90,role:'assistant',message:'The other Chat.'}];
    if (body.action === 'load') return route.fulfill({json:{ok:true,messages:rows}});
    if (body.action === 'messages_after') {
      return route.fulfill({json:{ok:true,messages:rows.filter(row => row.id > body.after_id)}});
    }
    return route.fulfill({json:{ok:true,conversations:[{id:42,title:'Research Chat'},{id:99,title:'Other Chat'}],updates:[]}});
  });
  async function boot() {
    await page.goto('https://research.test/');
    await page.evaluate(() => {
      window.STONEFELLOW_CHAT = {userId:1,agentId:7,endpoint:'/api/chat-v236.php',
        csrf:'fixture',initialView:'chat',initialConversationId:42};
    });
    await page.addStyleTag({content:await source('chat.css')});
    await page.addScriptTag({content:await source('chat.js')});
    await page.waitForFunction(() => window.STONEFELLOW_CHAT_CONTINUITY?.isReady());
  }
  const thread = page.locator('#chatThread');
  const canvas = run => thread.locator(`[data-research-canvas-run-id="${run}"]`);
  await boot();
  await canvas(100).getByRole('heading',{name:'Telescope calibration'}).waitFor();
  assert.equal(await thread.getByText(summary,{exact:true}).count(),1);
  assert.equal(await thread.locator('[data-research-canvas-run-id]').count(),1);
  assert.equal(await canvas(100).locator('time').getAttribute('datetime'),checkedAt);
  const excerpt = canvas(100).getByRole('link',{name:'Observatory handbook <img src=x> · Page excerpt',exact:true});
  assert.equal(await excerpt.getAttribute('href'),'https://observatory.example/handbook');
  assert.equal(await excerpt.getAttribute('rel'),'noopener noreferrer');
  assert.equal(await excerpt.getAttribute('target'),'_blank');
  assert.equal(await canvas(100).getByRole('link',{name:'A lead from a university · Search lead'}).count(),1);
  assert.equal(await canvas(100).getByRole('link').count(),2);
  assert.equal(await canvas(100).locator('img').count(),0);
  assert.equal(await thread.locator('[data-public-research-sources]').count(),1);

  await page.setViewportSize({width:390,height:844});
  messages.push(
    report(16, 110, 'I found a venue update.', {
      kind:'artist',title:'An independent performer',sources:[
        web('artist','f','A'.repeat(190),'https://venue.example/update','page_excerpt')
      ]
    }),
    report(17, 111, 'The public search returned no eligible sources.', {
      title:'A different topic',status:'no_matches',sources:[]
    }),
    report(18, 112, 'An unconfirmed result.', {sources:topicSources.slice(2)})
  );
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY.syncConversation(42));
  await canvas(110).getByRole('heading',{name:'An independent performer'}).waitFor();
  await canvas(111).getByText(/No eligible sources/).waitFor();
  assert.equal(await canvas(111).getByRole('link').count(),0);
  assert.equal(await canvas(112).count(),0);
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY.syncConversation(42));
  assert.equal(await thread.locator('[data-research-canvas-run-id]').count(),3);
  await boot();
  await canvas(110).getByRole('link').waitFor();
  assert.equal(await thread.locator('[data-research-canvas-run-id]').count(),3);
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY.openConversation(99));
  await thread.getByText('The other Chat.',{exact:true}).waitFor();
  assert.equal(await thread.locator('[data-research-canvas-run-id]').count(),0);
  assert.deepEqual(errors,[]);
  console.log('CHAT_RESEARCH_CANVAS_BROWSER=PASS (history, live sync, mobile, source safety and exact Chat scope)');
} finally {
  await browser.close();
}
