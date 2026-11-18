import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { mkdir } from 'node:fs/promises';
import { join } from 'node:path';

const { chromium } = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],
  executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
const origin = 'https://media-picker.test';
const endpoint = '/api/media-library-v86.php';
const owner = '12';
const path = name => fileURLToPath(new URL('../'+name,import.meta.url));
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
const literalTitle = '<img src=x onerror=alert(1)> Private bowl photograph';
const photo = (id, changes = {}) => ({id,title:`Studio image ${id}`,media_type:'photo',mime_type:'image/png',
  source:id % 3 === 0 ? 'openai_image' : id % 3 === 1 ? 'upload' : 'image_editor',available:true,file_size:4096,
  created_at:'2026-10-01T10:00:00Z',url:`/media-library-file-v86.php?id=${id}`,collection_ids:[id % 2 ? 2 : 3],...changes});
const photos = Array.from({length:65},(_, index) => photo(100-index,
  index === 0 ? {title:literalTitle} : index === 64 ? {title:'Older studio photograph'} : {}));
const html = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Media Library picker fixture</title><style>body{margin:0;padding:1rem;background:#eef2f7;font:16px system-ui}
main{max-width:72rem;margin:auto}textarea{width:100%;box-sizing:border-box}button{font:inherit}</style></head><body>
<main><h1>Product image selection</h1><dialog id="vp3InitiativeWorkbench" aria-label="Product draft">
<form id="destination"><label>Product description<textarea id="description">Keep this product description exactly.</textarea></label>
<button id="opener" type="button">Choose a saved image</button><div id="pickerHost"></div>
<button type="submit" id="save">Save destination</button><button id="otherFocus" type="button">Destination focus</button>
</form></dialog></main></body></html>`;

async function fixture({assets = photos, collectionsAvailable = true, viewport = {width:1280,height:1000}} = {}) {
  const page = await browser.newPage({viewport});
  page.setDefaultTimeout(8000);
  const calls = [];
  const errors = [];
  const foreignRequests = [];
  let nextBrowse;
  page.on('pageerror', error => errors.push(error.message));
  page.on('request', request => {
    if (new URL(request.url()).origin !== origin) foreignRequests.push(request.url());
  });
  await page.route(url => url.origin === origin && url.pathname === '/', route => route.fulfill({contentType:'text/html',body:html}));
  await page.route(`${origin}/media-library-file-v86.php?**`, route => route.fulfill({contentType:'image/png',body:png}));
  await page.route(url => url.origin === origin && url.pathname === endpoint, async route => {
    const request = route.request();
    const query = new URL(request.url()).searchParams;
    assert.equal(request.method(),'GET');
    assert.equal(request.headers()['x-vp3-media-owner'],owner);
    assert.equal(query.get('action'),'browse');
    assert.equal(query.get('type'),'photo');
    const call = Object.fromEntries(query);
    calls.push(call);
    const filtered = assets.filter(item => (!query.get('q') || item.title.toLowerCase().includes(query.get('q').toLowerCase()))
      && (!query.get('source') || (query.get('source') === 'generated' ? item.source === 'openai_image'
        : query.get('source') === 'edited' ? item.source === 'image_editor' : !['openai_image','image_editor'].includes(item.source)))
      && (!query.get('collection_id') || item.collection_ids?.includes(Number(query.get('collection_id'))))
      && (!query.get('before') || item.id < Number(query.get('before'))));
    const data = {ok:true,assets:filtered.slice(0,24),next_before:filtered.length > 24 ? filtered[23].id : null,
      collections_available:collectionsAvailable,
      collections:collectionsAvailable ? [{id:2,name:'Product photography'},{id:3,name:'Campaign ideas'}] : []};
    const reply = (body = data, status = 200) => route.fulfill({status,json:body});
    const handler = nextBrowse;
    nextBrowse = undefined;
    if (handler) return handler({route,query:call,data,reply});
    return reply();
  });
  await page.goto(origin);
  await page.addStyleTag({path:path('chat-initiative-workbench-v1.css')});
  await page.addStyleTag({path:path('media-library-picker-v1.css')});
  await page.addScriptTag({path:path('media-library-picker-v1.js')});
  await page.evaluate(({endpoint,owner}) => {
    const dialog = document.getElementById('vp3InitiativeWorkbench');
    dialog.showModal();
    const state = window.pickerFixture = {current:true,mode:'accept',committed:null,selections:[],closed:[],submitted:0};
    document.getElementById('destination').addEventListener('submit', event => { event.preventDefault(); state.submitted++; });
    window.openPickerFixture = ({mode = 'accept',selectedId = 100,actions = false} = {}) => {
      state.mode = mode;
      const opener = document.getElementById('opener');
      opener.focus();
      const options = {host:document.getElementById('pickerHost'),endpoint,ownerKey:owner,selectedId,
        title:'Choose a saved product image',isCurrent:() => state.current,returnFocus:opener,
        onClose:reason => {
          state.closed.push(reason);
          if (state.focusOnClose) document.getElementById('otherFocus').focus();
        },
        onSelect:async (asset, context) => {
          const record = {id:asset.id,url:asset.url,action:context.action,context,aborted:false,wasCurrent:context.isCurrent()};
          state.selections.push(record);
          context.signal.addEventListener('abort', () => { record.aborted = true; });
          if (state.mode === 'hold') await new Promise(resolve => { record.release = resolve; });
          if (state.mode === 'fail') throw new Error('Exact source is temporarily unavailable.');
          if (state.mode === 'reject') return false;
          if (!context.isCurrent()) { record.stale = true; return false; }
          state.committed = {id:asset.id,url:asset.url,action:context.action};
          return true;
        }};
      if (actions) options.actions = [
        {id:'edit',label:'Edit in Chat',maxBytes:25*1024*1024,acceptedMimes:['image/png'],
          eligibility:asset => asset.source === 'openai_image' ? '' : 'Choose a generated PNG; Chat verifies the saved original.'},
        {id:'marketing',label:'Use in Marketing',maxBytes:4*1024*1024,acceptedMimes:['image/jpeg','image/png','image/webp']}
      ];
      state.handle = window.VP3MediaLibraryPicker.open(options);
    };
  },{endpoint,owner});
  const picker = page.locator('[data-media-picker]');
  const control = name => picker.locator(`[data-media-picker-${name}]`);
  const card = id => picker.locator(`[data-media-picker-asset="${id}"]`);
  const open = async options => {
    await page.evaluate(options => window.openPickerFixture(options),options || {});
    if (!options?.expectClosed) await picker.waitFor();
  };
  const settled = async () => {
    await page.waitForFunction(() => document.querySelector('[data-media-picker-grid]')?.getAttribute('aria-busy') === 'false');
  };
  const search = async text => { await control('search').fill(text); await control('search').press('Enter'); await settled(); };
  const state = () => page.evaluate(() => ({committed:window.pickerFixture.committed,closed:window.pickerFixture.closed,
    submitted:window.pickerFixture.submitted,selections:window.pickerFixture.selections.map(item => ({id:item.id,action:item.action,
      aborted:item.aborted,current:item.context.isCurrent(),wasCurrent:item.wasCurrent,stale:item.stale === true}))}));
  const flush = () => page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  return {page,picker,control,card,open,settled,search,state,flush,calls,errors,foreignRequests,
    onBrowse:handler => { nextBrowse = handler; }};
}

async function screenshot(target, name) {
  const folder = process.env.VP3_MEDIA_PICKER_SCREENSHOTS;
  if (!folder) return;
  await mkdir(folder,{recursive:true});
  await target.screenshot({path:join(folder,name)});
}
let cases = 0;
const pass = label => { cases++; console.log('PASS '+label); };

try {
  const visual = await fixture();
  await visual.open();
  await visual.settled();
  assert.equal(await visual.control('grid').locator('li').count(),24);
  assert.equal(await visual.card(100).locator('img').getAttribute('src'),origin+'/media-library-file-v86.php?id=100');
  assert.equal(await visual.card(100).locator('strong').innerText(),literalTitle);
  assert.equal(await visual.card(100).locator('strong img').count(),0);
  assert.match(await visual.card(100).innerText(),/Current image/);
  assert.equal(await visual.control('use').isDisabled(),true);
  await visual.card(100).click();
  assert.equal(await visual.control('preview').getAttribute('data-media-picker-candidate'),'100');
  assert.equal(await visual.control('preview').locator('img').getAttribute('src'),origin+'/media-library-file-v86.php?id=100');
  assert.equal(await visual.control('use').isEnabled(),true);
  assert.deepEqual((await visual.state()).selections,[]);
  assert.equal((await visual.state()).committed,null);
  assert.equal(await visual.page.locator('form form').count(),0);
  await screenshot(visual.page.locator('dialog'),'media-picker-desktop.png');
  pass('Real thumbnails, literal private titles, current-image badge, and candidate preview render without committing a selection');
  await visual.control('use').click();
  await visual.picker.waitFor({state:'hidden'});
  assert.deepEqual((await visual.state()).committed,{id:100,url:origin+'/media-library-file-v86.php?id=100',action:'use'});
  assert.deepEqual((await visual.state()).closed,['selected']);
  assert.equal(await visual.page.locator('#opener').evaluate(element => element === document.activeElement),true);
  assert.equal(visual.calls.length,1);
  assert.deepEqual(visual.foreignRequests,[]);
  assert.deepEqual(visual.errors,[]);
  pass('Only explicit Use image hands off the exact asset ID and canonical URL, then restores focus without any write request');
  await visual.page.close();

  const filters = await fixture();
  await filters.open();
  await filters.settled();
  await filters.control('source').selectOption('generated');
  await filters.settled();
  assert.ok(filters.calls.at(-1).source === 'generated');
  const generatedIds = await filters.control('grid').locator('[data-media-picker-asset]').evaluateAll(elements => elements.map(element => Number(element.dataset.mediaPickerAsset)));
  assert.ok(generatedIds.length > 0 && generatedIds.every(id => id % 3 === 0));
  await filters.control('collection').selectOption('2');
  await filters.settled();
  assert.equal(filters.calls.at(-1).collection_id,'2');
  const collectionIds = await filters.control('grid').locator('[data-media-picker-asset]').evaluateAll(elements => elements.map(element => Number(element.dataset.mediaPickerAsset)));
  assert.ok(collectionIds.every(id => id % 2 === 1 && id % 3 === 0));
  await filters.control('source').selectOption('');
  await filters.settled();
  await filters.control('collection').selectOption('');
  await filters.settled();
  await filters.search('Older studio photograph');
  assert.equal(await filters.control('grid').locator('li').count(),1);
  assert.equal(filters.calls.at(-1).q,'Older studio photograph');
  await filters.card(36).click();
  assert.equal((await filters.state()).committed,null);
  pass('Search, Generated source, and collection filters send owner-bound read-only browse queries and reach an older exact image');
  await filters.search('');
  await filters.control('more').click();
  await filters.card(53).waitFor();
  assert.equal(await filters.control('grid').locator('li').count(),48);
  await filters.control('more').click();
  await filters.card(36).waitFor();
  assert.equal(await filters.control('grid').locator('li').count(),65);
  assert.equal(await filters.control('more').isVisible(),false);
  assert.ok(filters.calls.some(call => call.before === '77'));
  assert.ok(filters.calls.some(call => call.before === '53'));
  const allIds = await filters.control('grid').locator('[data-media-picker-asset]').evaluateAll(elements => elements.map(element => element.dataset.mediaPickerAsset));
  assert.equal(new Set(allIds).size,65);
  await filters.card(36).click();
  await filters.control('use').click();
  assert.equal((await filters.state()).committed.id,36);
  pass('Cursor pagination appends beyond both 24 and 50 images without duplicate rows and uses the exact oldest selected asset');
  await filters.page.close();

  const unavailable = await fixture({assets:[
    photo(30,{mime_type:'image/gif'}),photo(29,{file_size:4*1024*1024+1}),photo(28,{available:false}),
    photo(27,{url:'https://foreign.test/media-library-file-v86.php?id=27'}),photo(26,{url:'/media-library-file-v86.php?id=25'}),
    photo(25,{url:'/media-library-file-v86.php?id=25&id=99'}),photo(24,{url:'/media-library-file-v86.php?id=24&download=1'}),
    photo(23,{url:'javascript:alert(1)'}),photo(22,{media_type:'video'}),photo(21,{file_size:0}),
    photo(19,{url:'/unexpected/media-library-file-v86.php?id=19'}),
    photo(20,{id:Number.MAX_SAFE_INTEGER+1})
  ]});
  await unavailable.open();
  await unavailable.settled();
  for (const id of [30,29,28,27,26,25,24,23,22,19]) {
    assert.equal(await unavailable.card(id).isDisabled(),true);
    assert.ok((await unavailable.card(id).locator('[data-media-picker-disabled-reason]').innerText()).length > 0);
  }
  assert.equal(await unavailable.control('grid').locator('li').count(),11);
  for (const id of [27,26,25,24,23,19]) assert.equal(await unavailable.card(id).locator('img').count(),0);
  assert.deepEqual(unavailable.foreignRequests,[]);
  assert.equal(await unavailable.card(21).isEnabled(),true);
  assert.match(await unavailable.card(21).innerText(),/Size checked when used/);
  await unavailable.card(21).click();
  assert.equal(await unavailable.control('use').isEnabled(),true);
  assert.equal((await unavailable.state()).committed,null);
  pass('Unavailable, oversized, unsupported and unsafe exact-ID URLs are disabled with reasons; unknown size remains explicitly subject to use-time validation');
  await unavailable.page.close();

  const retry = await fixture();
  retry.onBrowse(({reply}) => reply({ok:false,error:'Library temporarily unavailable.'},503));
  await retry.open();
  await retry.control('error').getByText('Library temporarily unavailable.',{exact:true}).waitFor();
  await retry.control('retry').click();
  await retry.card(100).waitFor();
  assert.equal(retry.calls.length,2);
  assert.equal(await retry.control('error').isVisible(),false);
  await retry.search('No image can match this');
  await retry.control('empty').getByText('No matching images',{exact:true}).waitFor();
  assert.equal(await retry.control('use').isDisabled(),true);
  await retry.control('reset').click();
  await retry.card(100).waitFor();
  assert.equal(await retry.control('search').inputValue(),'');
  assert.equal((await retry.state()).committed,null);
  pass('Browse failure offers an effective retry; an empty filtered result has a working reset and never enables selection');
  await retry.page.close();
  const empty = await fixture({assets:[],collectionsAvailable:false});
  await empty.open();
  await empty.control('empty').getByText('No saved images yet',{exact:true}).waitFor();
  assert.equal(await empty.control('collection').isVisible(),false);
  assert.equal(await empty.control('collection').isDisabled(),true);
  assert.equal(await empty.control('reset').isVisible(),false);
  assert.equal(await empty.control('use').isDisabled(),true);
  await empty.control('cancel').click();
  assert.equal(await empty.page.locator('dialog').evaluate(element => element.open),true);
  pass('An empty library and unavailable collections give a usable empty state inside the existing destination');
  await empty.page.close();

  const keyboard = await fixture();
  await keyboard.open();
  await keyboard.settled();
  await keyboard.control('search').fill('Studio image');
  await keyboard.control('search').press('Enter');
  await keyboard.settled();
  assert.equal((await keyboard.state()).submitted,0);
  await keyboard.card(99).focus();
  await keyboard.card(99).press('ArrowRight');
  assert.equal(await keyboard.card(98).evaluate(element => element === document.activeElement),true);
  await keyboard.card(98).press('Enter');
  assert.equal(await keyboard.control('preview').getAttribute('data-media-picker-candidate'),'98');
  assert.equal((await keyboard.state()).committed,null);
  await keyboard.card(98).press('Escape');
  await keyboard.picker.waitFor({state:'hidden'});
  assert.equal(await keyboard.page.locator('dialog').evaluate(element => element.open),true);
  assert.equal(await keyboard.page.locator('#description').inputValue(),'Keep this product description exactly.');
  assert.equal(await keyboard.page.locator('#opener').evaluate(element => element === document.activeElement),true);
  assert.equal((await keyboard.state()).submitted,0);
  pass('Keyboard search and tile selection never submit the containing form; arrow navigation and Escape preserve the dialog, typed draft, and opener focus');
  await keyboard.page.close();

  const mobile = await fixture({viewport:{width:390,height:844}});
  await mobile.open();
  await mobile.settled();
  await mobile.card(100).click();
  await mobile.control('use').scrollIntoViewIfNeeded();
  const mobileButton = await mobile.control('use').boundingBox();
  assert.ok(mobileButton && mobileButton.width >= 44 && mobileButton.height >= 44 && mobileButton.x >= 0
    && mobileButton.x + mobileButton.width <= 390 && mobileButton.y >= 0 && mobileButton.y + mobileButton.height <= 844);
  assert.equal(await mobile.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),true);
  assert.equal(await mobile.picker.evaluate(element => element.scrollWidth <= element.clientWidth),true);
  await screenshot(mobile.page.locator('dialog'),'media-picker-mobile.png');
  await mobile.control('cancel').click();
  assert.equal(await mobile.page.locator('#description').inputValue(),'Keep this product description exactly.');
  pass('At phone width the visual picker has no horizontal overflow and its explicit Use image and Cancel controls remain reachable');
  await mobile.page.close();

  const cancel = await fixture();
  await cancel.open({mode:'hold'});
  await cancel.card(100).click();
  await cancel.control('use').click();
  await cancel.page.waitForFunction(() => window.pickerFixture.selections.length === 1);
  await cancel.control('cancel').click();
  assert.equal((await cancel.state()).selections[0].aborted,true);
  assert.equal((await cancel.state()).selections[0].current,false);
  await cancel.page.evaluate(() => window.pickerFixture.selections[0].release());
  await cancel.flush();
  assert.equal((await cancel.state()).committed,null);
  assert.deepEqual((await cancel.state()).closed,['cancelled']);
  pass('Cancel aborts in-flight selection, retires its isCurrent ticket, and prevents a late callback from committing');
  await cancel.page.close();

  const replacement = await fixture();
  await replacement.open({mode:'hold'});
  await replacement.card(100).click();
  await replacement.control('use').click();
  await replacement.page.waitForFunction(() => window.pickerFixture.selections.length === 1);
  await replacement.card(99).click();
  assert.equal((await replacement.state()).selections[0].aborted,true);
  await replacement.page.evaluate(() => window.pickerFixture.selections[0].release());
  await replacement.flush();
  assert.equal((await replacement.state()).committed,null);
  assert.equal(await replacement.control('preview').getAttribute('data-media-picker-candidate'),'99');
  await replacement.page.evaluate(() => { window.pickerFixture.mode = 'accept'; });
  await replacement.control('use').click();
  assert.equal((await replacement.state()).committed.id,99);
  assert.equal((await replacement.state()).selections.length,2);
  pass('A replacement candidate invalidates the previous async callback and requires a fresh explicit Use image');
  await replacement.page.close();

  const filteredPending = await fixture();
  await filteredPending.open({mode:'hold'});
  await filteredPending.card(100).click();
  await filteredPending.control('use').click();
  await filteredPending.page.waitForFunction(() => window.pickerFixture.selections.length === 1);
  await filteredPending.control('source').selectOption('generated');
  await filteredPending.settled();
  assert.equal((await filteredPending.state()).selections[0].aborted,true);
  assert.equal(await filteredPending.control('preview').getAttribute('data-media-picker-candidate'),'');
  assert.equal(await filteredPending.control('use').isDisabled(),true);
  await filteredPending.page.evaluate(() => window.pickerFixture.selections[0].release());
  await filteredPending.flush();
  assert.equal((await filteredPending.state()).committed,null);
  pass('Changing a browse filter aborts pending use and clears the candidate so a hidden old image cannot be committed');
  await filteredPending.page.close();

  const selectedRetry = await fixture();
  await selectedRetry.open({mode:'fail'});
  await selectedRetry.card(100).click();
  await selectedRetry.control('use').click();
  await selectedRetry.control('error').getByText('Exact source is temporarily unavailable.',{exact:true}).waitFor();
  assert.equal((await selectedRetry.state()).committed,null);
  assert.equal(await selectedRetry.control('preview').getAttribute('data-media-picker-candidate'),'100');
  await selectedRetry.page.evaluate(() => { window.pickerFixture.mode = 'accept'; });
  await selectedRetry.control('retry').click();
  await selectedRetry.picker.waitFor({state:'hidden'});
  const retriedSelections = (await selectedRetry.state()).selections;
  assert.equal(retriedSelections.length,2);
  assert.ok(retriedSelections.every(item => item.wasCurrent));
  assert.equal((await selectedRetry.state()).committed.id,100);
  pass('A failed destination validation preserves its candidate and retries with a fresh current selection ticket');
  await selectedRetry.page.close();

  const race = await fixture({assets:[photo(90,{title:'Alpha image'}),photo(89,{title:'Beta image'})]});
  await race.open();
  await race.settled();
  let releaseBrowse;
  const browseGate = new Promise(resolve => { releaseBrowse = resolve; });
  let finishBrowse;
  const browseSettled = new Promise(resolve => { finishBrowse = resolve; });
  race.onBrowse(async ({reply}) => {
    await browseGate;
    try { await reply(); } catch {} finally { finishBrowse(); }
  });
  const alphaRequest = race.page.waitForRequest(request => new URL(request.url()).searchParams.get('q') === 'Alpha');
  await race.control('search').fill('Alpha');
  await race.control('search').press('Enter');
  await alphaRequest;
  await race.search('Beta');
  await race.card(89).waitFor();
  releaseBrowse();
  await browseSettled;
  await race.flush();
  assert.equal(await race.control('grid').locator('li').count(),1);
  assert.equal(await race.card(90).count(),0);
  assert.equal(await race.control('search').inputValue(),'Beta');
  pass('A superseded browse response cannot overwrite the latest search results or restore its old candidate');
  await race.page.close();

  const lifecycle = await fixture();
  await lifecycle.open({mode:'hold'});
  await lifecycle.card(100).click();
  await lifecycle.control('use').click();
  await lifecycle.page.waitForFunction(() => window.pickerFixture.selections.length === 1);
  await lifecycle.page.evaluate(() => {
    document.getElementById('otherFocus').focus();
    document.getElementById('pickerHost').remove();
  });
  await lifecycle.page.waitForFunction(() => window.pickerFixture.closed.includes('stale'));
  assert.equal((await lifecycle.state()).selections[0].aborted,true);
  await lifecycle.page.evaluate(() => window.pickerFixture.selections[0].release());
  await lifecycle.flush();
  assert.equal((await lifecycle.state()).committed,null);
  assert.equal(await lifecycle.page.locator('#otherFocus').evaluate(element => element === document.activeElement),true);
  pass('Removing the picker host retires pending selection without stealing focus from the remaining destination');
  await lifecycle.page.close();
  const scope = await fixture();
  await scope.open({mode:'hold'});
  await scope.card(100).click();
  await scope.control('use').click();
  await scope.page.waitForFunction(() => window.pickerFixture.selections.length === 1);
  await scope.page.evaluate(() => { window.pickerFixture.current = false; document.getElementById('otherFocus').focus(); });
  await scope.picker.waitFor({state:'hidden'});
  await scope.page.evaluate(() => window.pickerFixture.selections[0].release());
  await scope.flush();
  assert.deepEqual((await scope.state()).closed,['stale']);
  assert.equal((await scope.state()).selections[0].aborted,true);
  assert.equal((await scope.state()).committed,null);
  assert.equal(await scope.page.locator('#otherFocus').evaluate(element => element === document.activeElement),true);
  pass('A scope change without a DOM event closes private image browsing, aborts pending use, and avoids returning focus to stale controls');
  await scope.page.close();

  const sameHost = await fixture();
  await sameHost.open({mode:'hold'});
  await sameHost.card(100).click();
  await sameHost.control('use').click();
  await sameHost.page.waitForFunction(() => window.pickerFixture.selections.length === 1);
  await sameHost.open({mode:'accept'});
  await sameHost.card(99).waitFor();
  assert.deepEqual((await sameHost.state()).closed,['stale']);
  assert.equal((await sameHost.state()).selections[0].aborted,true);
  await sameHost.page.evaluate(() => window.pickerFixture.selections[0].release());
  await sameHost.flush();
  assert.equal(await sameHost.picker.count(),1);
  assert.equal((await sameHost.state()).committed,null);
  await sameHost.card(99).click();
  await sameHost.control('use').click();
  assert.equal((await sameHost.state()).committed.id,99);
  pass('Opening a replacement picker in the same host retires its predecessor and leaves one independently usable current instance');
  await sameHost.page.close();

  const actions = await fixture({assets:[
    photo(210,{source:'openai_image',file_size:10*1024*1024}),photo(209,{source:'upload',mime_type:'image/jpeg'}),
    photo(208,{source:'openai_image'}),photo(207,{source:'openai_image',file_size:26*1024*1024})
  ]});
  await actions.open({actions:true});
  await actions.settled();
  assert.equal(await actions.control('use').count(),0);
  assert.equal(await actions.card(207).isDisabled(),true);
  assert.equal(await actions.card(210).isEnabled(),true);
  await actions.card(210).click();
  assert.equal(await actions.picker.locator('[data-media-picker-action="edit"]').isEnabled(),true);
  assert.equal(await actions.picker.locator('[data-media-picker-action="marketing"]').isDisabled(),true);
  assert.match(await actions.picker.locator('[data-media-picker-action-reason="marketing"]').innerText(),/4 MiB/);
  await actions.card(209).click();
  assert.equal(await actions.picker.locator('[data-media-picker-action="edit"]').isDisabled(),true);
  assert.equal(await actions.picker.locator('[data-media-picker-action="marketing"]').isEnabled(),true);
  await actions.card(208).click();
  assert.equal(await actions.picker.locator('[data-media-picker-action="edit"]').isEnabled(),true);
  assert.equal(await actions.picker.locator('[data-media-picker-action="marketing"]').isEnabled(),true);
  assert.equal((await actions.state()).committed,null);
  await actions.picker.locator('[data-media-picker-action="edit"]').click();
  assert.equal((await actions.state()).committed.action,'edit');
  await actions.open({actions:true});
  await actions.card(209).click();
  await actions.picker.locator('[data-media-picker-action="marketing"]').click();
  assert.equal((await actions.state()).committed.action,'marketing');
  assert.equal((await actions.state()).committed.id,209);
  pass('Optional image actions preserve the default API while enforcing separate 25 MiB generated-PNG edit and 4 MiB marketing eligibility');
  await actions.page.close();

  const authentication = await fixture();
  authentication.onBrowse(({reply}) => reply({ok:false,error:'Account changed.'},403));
  await authentication.page.evaluate(() => { window.pickerFixture.focusOnClose = true; });
  await authentication.open({expectClosed:true});
  await authentication.picker.waitFor({state:'hidden'});
  assert.deepEqual((await authentication.state()).closed,['stale']);
  assert.equal((await authentication.state()).committed,null);
  assert.equal(await authentication.page.locator('#otherFocus').evaluate(element => element === document.activeElement),true);
  assert.deepEqual(authentication.errors,[]);
  pass('An account-bound browse denial retires the picker instead of leaving the previous account’s assets or stealing handoff focus');
  await authentication.page.close();

  const cursor = await fixture();
  await cursor.open();
  await cursor.settled();
  cursor.onBrowse(({data,reply}) => reply({...data,next_before:90}));
  await cursor.control('more').click();
  await cursor.control('error').getByText('The next page could not be verified. Please try again.',{exact:true}).waitFor();
  assert.equal(await cursor.control('grid').locator('li').count(),24);
  await cursor.control('retry').click();
  await cursor.card(53).waitFor();
  assert.equal(await cursor.control('grid').locator('li').count(),48);
  assert.equal((await cursor.state()).committed,null);
  assert.deepEqual(cursor.errors,[]);
  pass('A non-advancing page cursor is rejected without corrupting existing results, and retry resumes the exact older page');
  await cursor.page.close();

  console.log(`MEDIA_LIBRARY_PICKER_BROWSER=PASS (${cases} cases; real shared component, controlled read-only Media Library)`);
} finally {
  await browser.close();
}
