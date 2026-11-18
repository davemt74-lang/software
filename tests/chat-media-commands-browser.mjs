import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import {mkdir} from 'node:fs/promises';
import {join} from 'node:path';

const {chromium} = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({headless:true, args:['--no-sandbox'],
  executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
const origin = 'https://media-commands.test';
const owner = '12', agent = 7, conversation = 42;
const script = name => fileURLToPath(new URL(`../${name}`, import.meta.url));
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
const assets = [
  {id:106,title:'Generated too large',source:'openai_image',file_size:26*1024*1024},
  {id:105,title:'Ordinary large upload',source:'upload',file_size:6*1024*1024},
  {id:104,title:'Generated large PNG',source:'openai_image',file_size:6*1024*1024},
  {id:103,title:'Unsupported GIF',source:'upload',file_size:1024,mime_type:'image/gif'},
  {id:102,title:'Beta photo',source:'image_editor',file_size:4096},
  {id:101,title:'Alpha <img src=x onerror=alert(1)> photo',source:'upload',file_size:4096},
].map(row => ({media_type:'photo',mime_type:'image/png',available:true,collection_ids:[],
  created_at:'2026-11-01T12:00:00Z',url:`/media-library-file-v86.php?id=${row.id}`,...row}));
const stages = [
  ['published','Active'],['paused','Paused'],['design_review','Design graphic'],['image_starting','Starting image'],
  ['image_pending','Creating image'],['creative_ready','Review graphic'],['publication_review','Ready to publish'],
  ['creative_failed','Image needs attention'],['marketing_review','Review promotion'],['listing_review','Review listing'],
  ['photo_needed','Choose an image'],['needs_attention','Needs attention'],['published','Active'],['paused','Paused'],
];
const planRows = stages.map(([stage,stage_label], index) => {
  const product_id = 1030-index, cid = index >= 12 ? 99+index : conversation;
  return {product_id,product_title:index === 0 ? 'Vase <img src=x onerror=alert(1)>' : `Product ${product_id}`,
    agent_id:agent,conversation_id:cid,stage,stage_label,needs_attention:stage === 'needs_attention',
    image:stage === 'needs_attention' ? null : {id:101,title:'Product photograph',url:'/media-library-file-v86.php?id=101'},
    resume:stage === 'needs_attention' ? null : {owner_user_id:Number(owner),agent_id:agent,conversation_id:cid,
      product_id,stage,source_id:'101',draft_id:'a'.repeat(64),creative_id:'b'.repeat(32)},
    chat_url:`/chat.php?agent=${agent}&conversation_id=${cid}&marketing_plan=${product_id}`};
});
const html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
  + '<body><main><section id="chatThread" data-view="chat">Private Agent Chat</section>'
  + '<form id="chatForm"><label>Chat draft<textarea id="chatInput"></textarea></label><button id="sendChatButton">Send</button></form>'
  + '<button id="otherFocus" type="button">Other control</button></main></body></html>';

async function fixture(page, options = {}) {
  page.setDefaultTimeout(8000);
  const calls = [], browseCalls = [], errors = [], gates = [];
  const plans = new Map((options.plans || planRows).map(plan => [plan.product_id, structuredClone(plan)]));
  const delayed = new Map(), responses = new Map();
  page.on('pageerror', error => errors.push(error.message));
  await page.route(url => url.origin === origin && url.pathname === '/chat.php', route => route.fulfill({contentType:'text/html',body:html}));
  await page.route(`${origin}/media-library-file-v86.php?**`, route => route.fulfill({contentType:'image/png',body:png}));
  await page.route(`${origin}/api/media-library-v86.php?**`, route => {
    const request = route.request(), query = new URL(request.url()).searchParams;
    assert.equal(request.method(),'GET');
    assert.equal(request.headers()['x-vp3-media-owner'],owner);
    assert.equal(query.get('action'),'browse');
    assert.equal(query.get('type'),'photo');
    browseCalls.push(Object.fromEntries(query));
    const q = (query.get('q') || '').toLowerCase();
    const source = query.get('source');
    const rows = (options.assets || assets).filter(asset => asset.title.toLowerCase().includes(q)
      && (source !== 'generated' || asset.source === 'openai_image')
      && (source !== 'edited' || asset.source === 'image_editor')
      && (source !== 'uploaded' || asset.source === 'upload'));
    return route.fulfill({json:{ok:true,assets:rows,next_before:null,collections_available:false,collections:[]}});
  });
  await page.route(`${origin}/api/initiative-workbench-v1.php`, async route => {
    const request = route.request(), body = request.postDataJSON();
    assert.equal(request.method(),'POST');
    assert.equal(body.owner_key,owner);
    assert.equal(body.csrf_token,'fixture-csrf');
    assert.equal(body.agent_id,agent);
    assert.equal(body.conversation_id,conversation);
    assert.equal(body.tool,'marketing');
    assert.ok(['marketing_plans','marketing_plan'].includes(body.action), 'Command listing must stay read-only');
    calls.push(body);
    const gate = delayed.get(body.action);
    if (gate) { delayed.delete(body.action); gate.started(); await gate.promise; }
    const override = responses.get(body.action);
    if (override) responses.delete(body.action);
    const envelope = {ok:true,owner_key:owner,agent_id:body.agent_id,conversation_id:body.conversation_id,tool:'marketing'};
    let payload;
    if (body.action === 'marketing_plans') {
      const rows = [...plans.values()].filter(plan => !body.before || plan.product_id < body.before).sort((a,b) => b.product_id-a.product_id);
      payload = {plans:rows.slice(0,12),next_before:rows.length > 12 ? rows[11].product_id : null};
    } else payload = {plan:plans.get(body.product_id)};
    try { await route.fulfill(override || {json:{...envelope,...payload}}); } catch (error) {
      if (!gate) throw error;
    }
  });
  await page.goto(`${origin}/chat.php${options.query || ''}`);
  await page.evaluate(({owner,agent,conversation,ready}) => {
    window.fixtureScope = {ready,valid:true,owner,agent,conversation,revision:1};
    window.fixtureState = {base:77,attached:[],editCalls:[],marketing:[],launched:[],holdAttach:false,attachError:'',openMarketing:true,openPlan:true};
    window.STONEFELLOW_CHAT = {userId:Number(owner),agentId:agent,workingAttentionBinding:'fixture-private-binding'};
    window.VP3_INITIATIVE_WORKBENCH = {ownerKey:owner,agentId:agent,csrf:'fixture-csrf',
      endpoint:'/api/initiative-workbench-v1.php',mediaEndpoint:'/api/media-library-v86.php'};
    window.STONEFELLOW_CHAT_CONTINUITY_V87 = {mediaCommandContext:() => {
      const live = window.fixtureScope;
      if (!live.ready || !live.valid) return null;
      const bound = {ownerKey:live.owner,agentId:live.agent,conversationId:live.conversation,revision:live.revision};
      bound.isCurrent = () => live.ready && live.valid && live.owner === bound.ownerKey && live.agent === bound.agentId
        && live.conversation === bound.conversationId && live.revision === bound.revision;
      return bound;
    }};
    window.VP3ImageComposer = {attachAsset:async (asset,bound) => {
      const state = window.fixtureState;
      state.editCalls.push(asset.id);
      if (state.holdAttach) await new Promise(resolve => { state.releaseAttach = resolve; });
      if (!bound.isCurrent() || bound.signal.aborted) return false;
      if (state.attachError) throw new Error(state.attachError);
      state.base = asset.id; state.attached.push(asset.id);
      return true;
    }};
    window.VP3InitiativeWorkbench = {
      openMarketingImage:(asset,bound) => {
        if (!bound.isCurrent() || !window.fixtureState.openMarketing) return false;
        window.fixtureState.marketing.push({id:asset.id,owner:bound.ownerKey,agent:bound.agentId,conversation:bound.conversationId});
        return true;
      },
      openProductLaunch:marker => {
        if (!window.fixtureState.openPlan) return false;
        window.fixtureState.launched.push(marker);
        return true;
      },
    };
    document.getElementById('chatInput').value = 'Keep this carefully typed draft';
    document.getElementById('chatInput').focus();
  }, {owner,agent,conversation,ready:options.ready !== false});
  if (Object.hasOwn(options,'intent')) {
    await page.evaluate(({intent,strip}) => {
      STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandIntent = () => intent;
      if (strip) history.replaceState(null,'','/chat.php');
    }, {intent:options.intent,strip:options.stripIntentUrl === true});
  }
  await page.addStyleTag({path:script('media-library-picker-v1.css')});
  await page.addStyleTag({path:script('chat-media-commands-v1.css')});
  await page.addScriptTag({path:script('media-library-picker-v1.js')});
  await page.addScriptTag({path:script('chat-media-commands-v1.js')});
  return {calls,browseCalls,errors,plans,
    async open(kind) {
      assert.equal(await page.evaluate(kind => window.VP3MediaCommands.open(kind,
        window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext()),kind),true);
    },
    response(action, response) { responses.set(action,response); },
    defer(action) {
      let release, started;
      const promise = new Promise(resolve => { release = resolve; });
      const began = new Promise(resolve => { started = resolve; });
      const gate = {promise,release,started,began};
      delayed.set(action,gate); gates.push(gate);
      return gate;
    },
    async finish() {
      for (const gate of gates) gate.release();
      assert.deepEqual(errors,[]);
    },
  };
}

const modal = page => page.locator('[data-media-command]');
const card = (page,id) => page.locator(`[data-media-picker-asset="${id}"]`);
const action = (page,id) => page.locator(`[data-media-picker-action="${id}"]`);
const plan = (page,id) => page.locator(`[data-media-plan="${id}"]`);
const planOpen = (page,id) => page.locator(`[data-media-plan-open="${id}"]`);
async function state(page) { return page.evaluate(() => ({base:fixtureState.base,attached:fixtureState.attached,
  marketing:fixtureState.marketing,launched:fixtureState.launched,draft:document.getElementById('chatInput').value})); }
async function screenshot(page,name) {
  if (!process.env.VP3_MEDIA_PICKER_SCREENSHOTS) return;
  await mkdir(process.env.VP3_MEDIA_PICKER_SCREENSHOTS,{recursive:true});
  await page.screenshot({path:join(process.env.VP3_MEDIA_PICKER_SCREENSHOTS,name+'.png'),fullPage:true});
}
let passed = 0;
async function test(name, run, options = {}) {
  const page = await browser.newPage({viewport:options.viewport || {width:1280,height:980}});
  let fx;
  try { fx = await fixture(page,options); await run(page,fx); await fx.finish(); passed++; console.log('PASS '+name); }
  finally { await fx?.finish(); await page.close(); }
}

try {
  await test('images preview and cancel preserve the destination and typed draft',async (page,fx) => {
    await fx.open('images'); await card(page,101).waitFor();
    assert.equal(await page.evaluate(() => document.activeElement.hasAttribute('data-media-picker-search')),true);
    await card(page,101).click();
    assert.equal(await action(page,'edit').isEnabled(),true);
    assert.equal(await action(page,'marketing').isEnabled(),true);
    assert.equal(await card(page,101).locator('.vp3-media-picker-name img').count(),0);
    assert.deepEqual((await state(page)).attached,[]);
    await screenshot(page,'commands-images-desktop');
    await page.locator('[data-media-picker-cancel]').click();
    await modal(page).waitFor({state:'detached'});
    const saved = await state(page);
    assert.equal(saved.base,77); assert.equal(saved.draft,'Keep this carefully typed draft');
    assert.equal(await page.evaluate(() => document.activeElement.id),'chatInput');
    assert.equal(fx.calls.length,0);
  });

  await test('Edit in Chat attaches exactly the chosen image and returns focus',async (page,fx) => {
    await fx.open('images'); await card(page,102).click(); await action(page,'edit').click();
    await modal(page).waitFor({state:'detached'});
    const saved = await state(page);
    assert.deepEqual(saved.attached,[102]); assert.equal(saved.base,102);
    assert.equal(saved.draft,'Keep this carefully typed draft'); assert.deepEqual(saved.marketing,[]);
    assert.equal(await page.evaluate(() => document.activeElement.id),'chatInput');
  });

  await test('generated edit and Marketing limits remain distinct',async (page,fx) => {
    await fx.open('images'); await card(page,104).click();
    assert.equal(await action(page,'edit').isEnabled(),true);
    assert.equal(await action(page,'marketing').isDisabled(),true);
    assert.match(await page.locator('[data-media-picker-action-reason="marketing"]').innerText(),/4 MiB/);
    for (const id of [103,105,106]) assert.equal(await card(page,id).isDisabled(),true);
    await action(page,'edit').click(); await modal(page).waitFor({state:'detached'});
    assert.deepEqual((await state(page)).attached,[104]);
  });

  await test('Use in Marketing is a scoped read-only handoff',async (page,fx) => {
    await fx.open('images'); await card(page,101).click(); await action(page,'marketing').click();
    await modal(page).waitFor({state:'detached'});
    const saved = await state(page);
    assert.deepEqual(saved.marketing,[{id:101,owner,agent,conversation}]);
    assert.deepEqual(saved.attached,[]); assert.equal(saved.base,77);
    assert.equal(saved.draft,'Keep this carefully typed draft'); assert.equal(fx.calls.length,0);
  });

  await test('attachment validation error is useful and can be retried',async (page,fx) => {
    await page.evaluate(() => { fixtureState.attachError = 'The saved PNG is not a verified generated image.'; });
    await fx.open('images'); await card(page,104).click(); await action(page,'edit').click();
    await page.locator('[data-media-picker-error]').waitFor();
    assert.match(await page.locator('[data-media-picker-error]').innerText(),/verified generated image/);
    assert.equal((await state(page)).base,77);
    await page.evaluate(() => { fixtureState.attachError = ''; });
    await page.locator('[data-media-picker-retry]').click(); await modal(page).waitFor({state:'detached'});
    assert.deepEqual((await state(page)).attached,[104]);
  });

  await test('cancel rejects a late Edit in Chat completion',async (page,fx) => {
    await page.evaluate(() => { fixtureState.holdAttach = true; });
    await fx.open('images'); await card(page,101).click(); await action(page,'edit').click();
    await page.waitForFunction(() => typeof fixtureState.releaseAttach === 'function');
    await page.locator('[data-media-picker-cancel]').click(); await modal(page).waitFor({state:'detached'});
    await page.evaluate(async () => { fixtureState.releaseAttach(); await Promise.resolve(); });
    assert.equal((await state(page)).base,77); assert.deepEqual((await state(page)).attached,[]);
    assert.equal((await state(page)).draft,'Keep this carefully typed draft');
  });

  await test('search and a replacement candidate fence a pending attachment',async (page,fx) => {
    await page.evaluate(() => { fixtureState.holdAttach = true; });
    await fx.open('images'); await card(page,101).click(); await action(page,'edit').click();
    await page.waitForFunction(() => typeof fixtureState.releaseAttach === 'function');
    await page.locator('[data-media-picker-search]').fill('Beta'); await card(page,102).waitFor();
    await page.evaluate(() => { fixtureState.holdAttach = false; });
    await card(page,102).click(); await action(page,'edit').click(); await modal(page).waitFor({state:'detached'});
    await page.evaluate(async () => { fixtureState.releaseAttach(); await Promise.resolve(); });
    assert.deepEqual((await state(page)).attached,[102]); assert.equal((await state(page)).base,102);
  });

  await test('idle Chat and identity changes retire command UI',async (page,fx) => {
    await fx.open('images'); await card(page,101).waitFor();
    await page.evaluate(() => { fixtureScope.revision++; });
    await modal(page).waitFor({state:'detached'});
    assert.deepEqual((await state(page)).attached,[]);
    await fx.open('marketing'); await plan(page,1030).waitFor();
    await page.evaluate(() => { STONEFELLOW_CHAT.workingAttentionBinding = 'new-private-session'; });
    await modal(page).waitFor({state:'detached'});
  });

  await test('marketing shows active paused and unfinished plans with bounded paging',async (page,fx) => {
    await fx.open('marketing'); await plan(page,1030).waitFor();
    assert.equal(await page.locator('[data-media-plan]').count(),12);
    assert.equal(await page.locator('[data-media-plan-stage="published"]').first().innerText(),'Active');
    assert.equal(await page.locator('[data-media-plan-stage="paused"]').first().innerText(),'Paused');
    assert.equal(await plan(page,1030).locator('h3 img').count(),0);
    assert.equal(fx.calls.length,1); assert.equal(fx.calls[0].action,'marketing_plans');
    assert.deepEqual((await state(page)).launched,[]);
    await screenshot(page,'commands-marketing-active');
    await page.locator('[data-media-command-more]').click(); await plan(page,1017).waitFor();
    assert.equal(await page.locator('[data-media-plan]').count(),14);
    assert.equal(fx.calls[1].before,1019);
    assert.equal(await page.locator('[data-media-command-more]').isHidden(),true);
    const link = page.locator('[data-media-plan-link="1018"]');
    assert.equal(await link.getAttribute('href'),`${origin}/chat.php?agent=7&conversation_id=111&marketing_plan=1018`);
    assert.equal(await link.getAttribute('target'),null);
  });

  await test('resume uses fresh canonical stage instead of the listed marker',async (page,fx) => {
    await fx.open('marketing'); await plan(page,1030).waitFor();
    const fresh = fx.plans.get(1030); fresh.stage = 'paused'; fresh.stage_label = 'Paused'; fresh.resume.stage = 'paused';
    await planOpen(page,1030).click(); await modal(page).waitFor({state:'detached'});
    const launched = (await state(page)).launched;
    assert.equal(launched.length,1); assert.equal(launched[0].stage,'paused');
    assert.equal(fx.calls.at(-1).action,'marketing_plan'); assert.equal(fx.calls.at(-1).product_id,1030);
    assert.equal((await state(page)).draft,'Keep this carefully typed draft');
  });

  await test('superseded and cancelled plan reads cannot reopen a previous plan',async (page,fx) => {
    await fx.open('marketing'); await plan(page,1030).waitFor();
    const first = fx.defer('marketing_plan');
    await planOpen(page,1030).click(); await first.began;
    await planOpen(page,1029).click(); await modal(page).waitFor({state:'detached'});
    first.release();
    assert.deepEqual((await state(page)).launched.map(marker => marker.product_id),[1029]);
    await fx.open('marketing'); await plan(page,1030).waitFor();
    const next = fx.defer('marketing_plan');
    await planOpen(page,1030).click(); await next.began;
    await page.locator('[data-media-command-close]').click(); next.release();
    await modal(page).waitFor({state:'detached'});
    assert.deepEqual((await state(page)).launched.map(marker => marker.product_id),[1029]);
  });

  await test('network failure retries and needs-attention plans stay reviewable',async (page,fx) => {
    fx.response('marketing_plans',{status:503,json:{ok:false,error:'Plan storage is temporarily unavailable.'}});
    await fx.open('marketing'); await page.locator('[data-media-command-error]').waitFor();
    assert.match(await page.locator('[data-media-command-error]').innerText(),/temporarily unavailable/);
    await page.locator('[data-media-command-retry]').click(); await plan(page,1019).waitFor();
    await planOpen(page,1019).click(); await page.locator('[data-media-command-error]').waitFor();
    assert.match(await page.locator('[data-media-command-error]').innerText(),/needs attention/);
    assert.deepEqual((await state(page)).launched,[]); assert.equal(await modal(page).isVisible(),true);
  });

  await test('account denial and mismatched response scope clear private rows',async (page,fx) => {
    await fx.open('marketing'); await plan(page,1030).waitFor();
    fx.response('marketing_plans',{status:403,json:{ok:false,error:'Account changed.'}});
    await page.locator('[data-media-command-more]').click(); await modal(page).waitFor({state:'detached'});
    await fx.open('marketing'); await plan(page,1030).waitFor();
    fx.response('marketing_plan',{json:{ok:true,owner_key:'99',agent_id:agent,conversation_id:conversation,tool:'marketing',plan:planRows[0]}});
    await planOpen(page,1030).click(); await modal(page).waitFor({state:'detached'});
    await fx.open('marketing'); await plan(page,1030).waitFor();
    fx.response('marketing_plans',{status:404,json:{ok:false,error:'This private Chat is unavailable.'}});
    await page.locator('[data-media-command-more]').click(); await page.locator('[data-media-command-error]').waitFor();
    assert.equal(await page.locator('[data-media-plan]').count(),0);
    assert.equal(await page.locator('[data-media-command-more]').isHidden(),true);
    await page.locator('[data-media-command-close]').click();
    await fx.open('marketing'); await plan(page,1030).waitFor();
    fx.response('marketing_plan',{status:404,json:{ok:false,error:'This product marketing plan is unavailable.'}});
    await planOpen(page,1030).click(); await page.locator('[data-media-command-error]').waitFor();
    assert.equal(await page.locator('[data-media-plan]').count(),0);
    assert.match(await page.locator('[data-media-command-error]').innerText(),/unavailable/);
    assert.deepEqual((await state(page)).launched,[]);
  });

  await test('empty lists and untrusted cross-Chat links are handled safely',async (page,fx) => {
    await fx.open('marketing'); await page.locator('[data-media-command-empty]').waitFor();
    assert.equal(await page.locator('[data-media-command-more]').isHidden(),true);
    await page.locator('[data-media-command-close]').click();
    fx.plans.set(1018,{...structuredClone(planRows[12]),chat_url:'https://attacker.test/chat.php?agent=7&conversation_id=111&marketing_plan=1018'});
    await fx.open('marketing'); await plan(page,1018).waitFor();
    assert.equal(await page.locator('[data-media-plan-link]').count(),0);
    assert.equal(await page.locator('[data-media-command-unavailable]').isDisabled(),true);
    assert.deepEqual((await state(page)).launched,[]);
  }, {plans:[]});

  await test('deep link waits for actual matching Chat readiness and is consumed once',async (page,fx) => {
    assert.equal(fx.calls.length,0); assert.equal(await modal(page).count(),0);
    await page.evaluate(() => { fixtureScope.ready = true; fixtureScope.conversation = 99; window.dispatchEvent(new Event('vp3:chat-context-ready')); });
    assert.equal(fx.calls.length,0);
    await page.evaluate(() => { fixtureScope.conversation = 42; window.dispatchEvent(new Event('vp3:chat-context-ready')); });
    await page.waitForFunction(() => fixtureState.launched.length === 1);
    assert.equal(fx.calls.length,1); assert.equal(fx.calls[0].action,'marketing_plan');
    assert.equal(fx.calls[0].product_id,1030);
    assert.equal(new URL(page.url()).searchParams.has('marketing_plan'),false);
    await page.evaluate(() => window.dispatchEvent(new Event('vp3:chat-context-ready')));
    assert.equal(fx.calls.length,1);
  }, {ready:false,query:'?agent=7&conversation_id=42&marketing_plan=1030'});

  await test('unavailable deep-link plan keeps an inline retry and never resumes',async (page,fx) => {
    await page.locator('[data-media-command-error]').waitFor();
    assert.match(await page.locator('[data-media-command-error]').innerText(),/needs attention/);
    assert.deepEqual((await state(page)).launched,[]);
    assert.equal(fx.calls[0].action,'marketing_plan');
    assert.equal(new URL(page.url()).searchParams.has('marketing_plan'),false);
    await page.locator('[data-media-command-retry]').click();
    await page.waitForFunction(() => document.querySelector('[data-media-command]').dataset.mediaCommandBusy === 'false');
    assert.equal(fx.calls.length,2); assert.deepEqual((await state(page)).launched,[]);
  }, {query:'?agent=7&conversation_id=42&marketing_plan=1019'});

  await test('runtime intent survives history URL cleanup before the module loads',async (page,fx) => {
    await page.waitForFunction(() => fixtureState.launched.length === 1);
    assert.equal(fx.calls.length,1); assert.equal(fx.calls[0].action,'marketing_plan');
    assert.equal(fx.calls[0].product_id,1030);
    assert.equal(page.url(),origin+'/chat.php');
  }, {query:'?agent=7&conversation_id=42&marketing_plan=1030',
    intent:{productId:1030,conversationId:42,agentId:7},stripIntentUrl:true});

  await test('authoritative runtime intent cannot fall back to a conflicting URL',async (page,fx) => {
    await page.evaluate(() => window.dispatchEvent(new Event('vp3:chat-context-ready')));
    assert.equal(fx.calls.length,0); assert.equal(await modal(page).count(),0);
  }, {query:'?agent=7&conversation_id=42&marketing_plan=1030',intent:{productId:1030,conversationId:99,agentId:7}});

  await test('null runtime intent suppresses an otherwise valid URL intent',async (page,fx) => {
    await page.evaluate(() => window.dispatchEvent(new Event('vp3:chat-context-ready')));
    assert.equal(fx.calls.length,0); assert.equal(await modal(page).count(),0);
  }, {query:'?agent=7&conversation_id=42&marketing_plan=1030',intent:null});

  await test('mobile command surfaces fit and Escape closes the active picker',async (page,fx) => {
    await fx.open('images'); await card(page,104).click();
    await screenshot(page,'commands-images-mobile');
    assert.equal(await page.evaluate(() => {
      const modal = document.querySelector('[data-media-command]');
      return modal.scrollWidth <= modal.clientWidth + 1 && modal.getBoundingClientRect().right <= innerWidth;
    }),true);
    await page.keyboard.press('Escape'); await modal(page).waitFor({state:'detached'});
    await fx.open('marketing'); await plan(page,1030).waitFor();
    await screenshot(page,'commands-marketing-mobile');
    assert.equal(await page.evaluate(() => {
      const modal = document.querySelector('[data-media-command]');
      return modal.scrollWidth <= modal.clientWidth + 1 && modal.getBoundingClientRect().right <= innerWidth;
    }),true);
    await page.keyboard.press('Escape'); await modal(page).waitFor({state:'detached'});
    assert.equal((await state(page)).draft,'Keep this carefully typed draft');
  }, {viewport:{width:390,height:844}});

  console.log(`Chat media commands browser regression passed (${passed} groups).`);
} finally { await browser.close(); }
