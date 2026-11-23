import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const {chromium} = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],
  executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
const origin = 'https://vp3-phone.test';
const pathname = '/tenant/phone-location.php';
const doc = readFileSync(new URL('../phone-location.php',import.meta.url),'utf8').split('?>')[1];
const js = readFileSync(new URL('../phone-location-v1.js',import.meta.url),'utf8');
const css = readFileSync(new URL('../phone-location-v1.css',import.meta.url),'utf8');
const secret = 'C'.repeat(43), id = 'd'.repeat(32);
function initialDevice(status = 'pending',g = 1,loc = 'off') {
  return {id,name:'Test phone',role:'owner',status,generation:g,allow_location:status === 'approved',
    location_state:loc,fix_at:null,accuracy:null};
}
async function scenario({initial = null, geolocation = true, lostClaimAck = false, holdFirstFix = false} = {}) {
  const context = await browser.newContext({viewport:{width:375,height:667},isMobile:true,hasTouch:true});
  let device = initial && {...initial}, revoked = false, drop = lostClaimAck;
  let requests = [], routes = [], errors = [];
  const fixTimes=[];
  let firstFixSeen, rejectFirstFix;
  const firstFix = new Promise(resolve => { firstFixSeen = resolve; });
  const releaseFix = new Promise(resolve => { rejectFirstFix = resolve; });
  const page = await context.newPage();
  page.on('pageerror',e=>errors.push(String(e)));
  page.setDefaultTimeout(4500);
  await page.addInitScript(enabled=>{
    let pos,err,previousPos,online=true,peak=0;
    const options=[], active=new Set();
    Object.defineProperty(navigator,'onLine',{configurable:true,get:()=>online});
    Object.defineProperty(navigator,'geolocation',{configurable:true,value:enabled ? {
      watchPosition(ok,bad,opts) {
        previousPos=pos; pos=ok; err=bad;
        options.push(opts); active.add(options.length); peak=Math.max(peak,active.size);
        return options.length;
      },
      clearWatch(id) { active.delete(id); },
    } : undefined});
    window.phoneFixture = {
      get watches() { return options.length; }, get active() { return active.size > 0; },
      get peak() { return peak; }, get options() { return options; },
      position(timestamp=Date.now()) { if (active.size) pos({timestamp,coords:{latitude:33.4,longitude:-112.1,accuracy:9}}); },
      previous() { previousPos?.({timestamp:Date.now(),coords:{latitude:0,longitude:0,accuracy:123}}); },
      denial() { if (active.size) err({code:1}); },
      network(value) { online=value; window.dispatchEvent(new Event(value?'online':'offline')); },
      wake() { window.dispatchEvent(new Event('online')); },
      late() { pos?.({timestamp:Date.now()+1000,coords:{latitude:1,longitude:1,accuracy:9}}); }
    };
  },geolocation);
  await page.route(origin+'/**',async route=>{
    const u = new URL(route.request().url());
    routes.push(u.href);
    if (u.pathname === pathname) return route.fulfill({contentType:'text/html',body:doc});
    if (u.pathname.endsWith('phone-location-v1.js')) return route.fulfill({contentType:'text/javascript',body:js});
    if (u.pathname.endsWith('phone-location-v1.css')) return route.fulfill({contentType:'text/css',body:css});
    if (!u.pathname.endsWith('/api/phone-location-v1.php')) return route.fulfill({status:404});
    const req = route.request().postDataJSON();
    requests.push(req);
    if (req.action === 'fix') fixTimes.push(performance.now());
    assert.equal(route.request().headers()['x-vp3-phone'],'1');
    assert.equal(route.request().headers().authorization,undefined);
    const send = (status,b) => route.fulfill({status,json:b});
    if (req.action === 'claim') {
      if (req.invite !== secret || !/^[a-f0-9]{64}$/.test(req.claim_key)) return send(403,{ok:false});
      device = device || initialDevice();
      if (drop) { drop=false; return route.abort('failed'); }
    } else if (!device || revoked) return send(device?403:401,{ok:false});
    else if (req.action !== 'status') {
      if (req.device_id !== id || req.generation !== device.generation) return send(409,{ok:false});
      if (['accept_arrival','decline_arrival','stop_arrival'].includes(req.action)) {
        const rule = device.arrival_rules?.find(r => r.id === req.rule_id && r.generation === device.generation);
        if (!rule || req.rule_version !== rule.version || (req.action === 'stop_arrival' ? 'active' : 'requested') !== rule.state) {
          return send(409,{ok:false});
        }
        if (req.action === 'accept_arrival') { rule.state='active'; rule.version++; }
        else device.arrival_rules = device.arrival_rules.filter(r => r !== rule);
      } else if (['accept_history','decline_history','stop_history'].includes(req.action)) {
        const needed = req.action === 'stop_history' ? 'active' : 'requested';
        if (device.history_state !== needed || device.history_version !== req.history_version) return send(409,{ok:false});
        device.history_state = req.action === 'accept_history' ? 'active' : 'off';
        device.history_version++;
      } else if (req.action === 'set_place_permission') {
        if (device.place_permission_version !== req.place_permission_version) return send(409,{ok:false});
        device.allow_saved_places = req.allow_saved_places;
        device.place_permission_version++;
      } else if (req.action === 'start') {
        if (device.location_state !== 'requested') device.generation++;
        device.location_state='waiting';
      } else if (req.action === 'fix') {
        if (holdFirstFix) {
          holdFirstFix = false;
          firstFixSeen();
          await releaseFix;
          return send(422,{ok:false});
        }
        device.location_state='live'; device.fix_at=req.fix_at; device.accuracy=req.accuracy;
      } else if (req.action === 'disconnect') {device.status='revoked';revoked=true;}
      else {device.generation++;device.location_state=req.action==='denied'?'denied':'off';device.fix_at=null;}
    }
    return send(200,{ok:true,device,owner_name:'David Evans'});
  });
  return {page,requests,fixTimes,routes,errors,firstFix,rejectFirstFix, get device(){return device;}, revoke(){revoked=true;},
    visit:frag=>page.goto(origin+pathname+(frag||'')), close:()=>context.close()};
}
const waitText = (p,re) => p.waitForFunction(s=>new RegExp(s,'i').test(document.querySelector('#phone-status').textContent),re);
const pause = page => page.evaluate(()=>new Promise(r=>setTimeout(r,60)));
const delay = ms => new Promise(resolve=>setTimeout(resolve,ms));
async function waitUntil(description,condition,timeout=4500) {
  const deadline=performance.now()+timeout;
  do {
    if (await condition()) return;
    await delay(20);
  } while (performance.now()<deadline);
  throw new Error('Timed out waiting for '+description);
}
try {
  {
    const t=await scenario();
    await t.visit('#invite='+secret);
    await t.page.locator('#phone-name').fill('Test phone');
    assert.equal(new URL(t.page.url()).hash,'','invite erased from visible history');
    assert.ok(t.routes.every(u=>!u.includes(secret)),'invite never travels in request URLs');
    await t.page.locator('#phone-claim').click();
    await waitText(t.page,'Waiting for the account owner');
    assert.equal(await t.page.locator('#phone-owner').textContent(),'David Evans');
    assert.equal(await t.page.evaluate(()=>window.phoneFixture.watches),0);
    Object.assign(t.device,{status:'approved',allow_location:true,location_state:'requested',generation:2});
    await t.page.evaluate(()=>window.phoneFixture.wake());
    await waitText(t.page,'owner requested');
    assert.equal(await t.page.evaluate(()=>window.phoneFixture.watches),0,'remote request never enables GPS');
    await t.page.locator('#phone-start').click();
    await waitText(t.page,'Waiting for a fresh');
    await t.page.evaluate(()=>window.phoneFixture.position(Date.now()-65000));
    await pause(t.page);
    assert.equal(t.requests.filter(r=>r.action==='fix').length,0,'discard old sensor sample');
    await t.page.evaluate(()=>window.phoneFixture.position());
    await waitText(t.page,'received this phone');
    assert.equal(t.requests.filter(r=>r.action==='fix').length,1);
    const store = await t.page.evaluate(()=>JSON.stringify([Object.entries(localStorage),Object.entries(sessionStorage)]));
    assert.ok(!store.includes(secret) && !store.includes('33.4') && !store.includes('latitude'));
    await t.page.evaluate(()=>window.phoneFixture.network(false));
    await t.page.locator('#phone-stop').click();
    await waitText(t.page,'stopped on this page');
    assert.equal(await t.page.evaluate(()=>window.phoneFixture.active),false,'local collection stops immediately offline');
    await t.page.evaluate(()=>window.phoneFixture.late());
    assert.equal(t.requests.filter(r=>r.action==='fix').length,1);
    await t.page.evaluate(()=>window.phoneFixture.network(true));
    await waitText(t.page,'choose when');
    assert.equal(t.requests.filter(r=>r.action==='stop').length,1,'offline stop synchronized with original generation');
    assert.equal(t.device.location_state,'off');
    assert.equal(t.requests.filter(r=>r.action==='start').length,1);
    await t.page.locator('#phone-start').click();
    await waitText(t.page,'Waiting for a fresh');
    await t.page.evaluate(()=>window.phoneFixture.denial());
    await waitText(t.page,'permission was denied');
    assert.equal(t.device.location_state,'denied');
    assert.equal(await t.page.evaluate(()=>window.phoneFixture.active),false);
    await t.page.locator('#phone-start').click(); await waitText(t.page,'Waiting for a fresh');
    t.revoke(); await t.page.evaluate(()=>window.phoneFixture.wake()); await waitText(t.page,'expired or disconnected');
    assert.equal(await t.page.evaluate(()=>window.phoneFixture.active),false,'revocation clears browser watch');
    assert.equal(await t.page.locator('#phone-start').isVisible(),false);
    assert.deepEqual(t.errors,[]); await t.close();
  }
  {
    const t=await scenario({initial:initialDevice('approved',4),geolocation:false});
    await t.visit(); await waitText(t.page,'unavailable in this browser');
    assert.equal(await t.page.locator('#phone-start').isVisible(),false);
    assert.ok(await t.page.locator('#phone-disconnect').isVisible());
    await t.page.locator('#phone-disconnect').click(); await waitText(t.page,'phone disconnected');
    assert.deepEqual(t.errors,[]); await t.close();
  }
  {
    const t=await scenario({lostClaimAck:true});
    await t.visit('#'+secret); await t.page.locator('#phone-name').fill('Test phone');
    await t.page.locator('#phone-claim').click(); await waitText(t.page,'could not be confirmed');
    await t.page.locator('#phone-claim').click(); await waitText(t.page,'Waiting for the account');
    const claims=t.requests.filter(r=>r.action==='claim');
    assert.equal(claims.length,2);assert.equal(claims[0].claim_key,claims[1].claim_key,'lost acknowledgment retries use same claim proof');
    assert.deepEqual(t.errors,[]);await t.close();
  }
  {
    const t=await scenario({initial:initialDevice('approved',1)});
    await t.visit(); await t.page.locator('#phone-start').click(); await waitText(t.page,'Waiting for a fresh');
    await t.page.evaluate(()=>window.phoneFixture.network(false)); await t.page.locator('#phone-stop').click();
    Object.assign(t.device,{location_state:'requested',generation:t.device.generation+2});
    await t.page.evaluate(()=>window.phoneFixture.network(true)); await waitText(t.page,'owner requested');
    assert.equal(t.requests.filter(r=>r.action==='stop').length,0,'old offline intent cannot stop a newer grant');
    assert.equal(await t.page.evaluate(()=>window.phoneFixture.active),false);
    await t.page.reload();await waitText(t.page,'owner requested');
    assert.equal(await t.page.evaluate(()=>window.phoneFixture.watches),0,'a reloaded phone cannot auto-start a new watch');
    assert.deepEqual(t.errors,[]);await t.close();
  }
  for (const interruption of ['none','stop','offline']) {
    const t=await scenario({initial:initialDevice('approved',1),holdFirstFix:true});
    await t.visit(); await t.page.locator('#phone-start').click(); await waitText(t.page,'Waiting for a fresh');
    await t.page.evaluate(()=>window.phoneFixture.position(Date.now()-15000));
    await t.firstFix;
    const latest = await t.page.evaluate(()=>{
      const stamp = Date.now();
      window.phoneFixture.position(stamp-5000);
      window.phoneFixture.position(stamp);
      window.phoneFixture.position(stamp-1000); // A late, older callback must not replace the latest.
      return Math.floor(stamp/1000);
    });
    if (interruption === 'stop') await t.page.locator('#phone-stop').click();
    if (interruption === 'offline') await t.page.evaluate(()=>window.phoneFixture.network(false));
    t.rejectFirstFix();
    if (interruption === 'none') {
      await waitText(t.page,'received this phone');
      assert.deepEqual(t.requests.filter(r=>r.action==='fix').map(r=>r.fix_at).slice(1),[latest],
        'the latest native callback survives an older rejected in-flight upload');
    } else {
      if (interruption === 'stop') await waitText(t.page,'choose when');
      else {
        await t.page.evaluate(()=>window.phoneFixture.network(true));
        await waitText(t.page,'Waiting for a fresh');
      }
      await pause(t.page);
      assert.equal(t.requests.filter(r=>r.action==='fix').length,1,
        'buffered coordinates cannot survive ' + interruption);
    }
    assert.deepEqual(t.errors,[]); await t.close();
  }
  {
    const t=await scenario({initial:{...initialDevice('approved',3),update_interval_seconds:30,accuracy_mode:'balanced'}});
    const page=t.page;
    const fixes=()=>t.requests.filter(r=>r.action==='fix');
    await t.visit();
    const settings=()=>page.locator('#phone-preferences').textContent();
    const gps=()=>page.locator('#phone-gps').textContent();
    async function changeSettings(interval,mode) {
      Object.assign(t.device,{update_interval_seconds:interval,accuracy_mode:mode});
      await page.evaluate(()=>window.phoneFixture.wake());
      const expected='Every '+interval+' seconds · '+(mode==='high'?'High':'Balanced')+' accuracy';
      await waitUntil('phone preferences '+expected,async()=>await settings()===expected);
    }
    async function freshPosition() {
      return page.evaluate(()=>{const time=Date.now(); window.phoneFixture.position(time); return Math.floor(time/1000);});
    }
    await waitUntil('initial holder preferences',async()=>await settings()==='Every 30 seconds · Balanced accuracy');
    assert.equal(await page.evaluate(()=>window.phoneFixture.watches),0,'preferences cannot activate the phone');
    await page.locator('#phone-start').click();
    await waitText(page,'Waiting for a fresh');
    assert.equal(await page.evaluate(()=>window.phoneFixture.options.at(-1).enableHighAccuracy),false);
    await freshPosition();
    await waitText(page,'received this phone');
    const firstGpsLabel=await gps();
    // Exercise the real fetch pipeline. Advancing a synthetic page clock also expires live network requests.
    await delay(1050);
    await freshPosition();
    await delay(1050);
    const newest=await freshPosition();
    assert.equal(fixes().length,1,'first fix is immediate and burst callbacks are coalesced');
    await changeSettings(5,'balanced');
    await waitUntil('second GPS acknowledgment',async()=>await gps()!==firstGpsLabel && (await gps()).startsWith('Shared at'),8000);
    assert.equal(fixes().length,2);
    assert.equal(fixes()[1].fix_at,newest,'shortened interval sends the newest queued reading');
    assert.ok(t.fixTimes[1]-t.fixTimes[0]>=5000,'second request respects the new five-second minimum');

    await changeSettings(5,'high');
    assert.equal(await page.evaluate(()=>window.phoneFixture.options.at(-1).enableHighAccuracy),true);
    assert.equal(await page.evaluate(()=>window.phoneFixture.peak),1,'replacement never overlaps native watches');
    const secondGpsLabel=await gps();
    await delay(5100);
    await page.evaluate(()=>window.phoneFixture.previous());
    await freshPosition();
    await waitUntil('high-accuracy GPS acknowledgment',async()=>await gps()!==secondGpsLabel && (await gps()).startsWith('Shared at'));
    assert.equal(fixes().length,3);
    assert.equal(fixes()[2].latitude,33.4,'late callbacks from the replaced reader cannot supply the accepted fix');

    await delay(1050);
    await freshPosition();
    await changeSettings(15,'high');
    await delay(5100);
    assert.equal(fixes().length,3,'lengthening interval cancels the previous, earlier deadline');
    await page.locator('#phone-stop').click();
    await waitText(page,'choose when');
    // Stay beyond the longest outstanding 15-second timer to catch resurrected callbacks.
    await delay(Math.max(0,t.fixTimes[2]+16000-performance.now()));
    assert.equal(fixes().length,3,'stop discards the deferred sample and timer');
    await changeSettings(30,'balanced');
    assert.equal(await page.evaluate(()=>window.phoneFixture.active),false,'a settings change cannot reopen a stopped watch');
    assert.equal(t.requests.filter(r=>r.action==='start').length,1);
    assert.deepEqual(t.errors,[]); await t.close();
  }
  {
    const t=await scenario({initial:{...initialDevice('approved',3),history_state:'off',history_version:7,
      history_retention_days:0,allow_saved_places:false,place_permission_version:1},holdFirstFix:true});
    const {page}=t;
    const fixes=()=>t.requests.filter(r=>r.action==='fix');
    const scopeText=(kind,pattern)=>waitUntil(kind+' state '+pattern,async()=>
      pattern.test(await page.locator('#phone-'+kind+'-status').textContent()));
    await t.visit();
    await scopeText('history',/^Off$/);
    assert.equal(await page.evaluate(()=>window.phoneFixture.watches),0);
    await page.locator('#phone-start').click(); await waitText(page,'Waiting for a fresh');
    Object.assign(t.device,{history_state:'requested',history_retention_days:7,history_version:8});
    await page.evaluate(()=>window.phoneFixture.wake());
    await scopeText('history',/David Evans.*7 days/);
    await page.evaluate(()=>window.phoneFixture.position(Date.now()-10000));
    await t.firstFix;
    await page.evaluate(()=>window.phoneFixture.position(Date.now()-5000));
    await page.locator('#phone-history-accept').click();
    t.rejectFirstFix();
    await scopeText('history',/keep each new shared location for 7 days/);
    await waitText(page,'received this phone');
    assert.equal(fixes().length,2);
    assert.ok(fixes().every(r=>r.history_version === undefined),'acceptance never backfills queued live-only samples');
    await page.evaluate(()=>window.phoneFixture.position());
    await waitUntil('fresh history sample',()=>fixes().length===3,8000);
    assert.equal(fixes()[2].history_version,t.device.history_version);
    await page.locator('#phone-places-allow').click();
    await scopeText('places',/can save named places during this live session/);
    await page.evaluate(()=>window.phoneFixture.network(false));
    await page.locator('#phone-history-stop').click();
    await page.locator('#phone-places-stop').click();
    await scopeText('history',/off on this phone/);
    await scopeText('places',/withdrawn on this phone/);
    assert.equal(t.requests.some(r=>r.action==='stop_history'),false);
    assert.equal(await page.evaluate(()=>window.phoneFixture.active),true,'scope withdrawals preserve the native live watch');
    await page.evaluate(()=>window.phoneFixture.network(true));
    await scopeText('history',/^Off$/);
    await scopeText('places',/^Allow David Evans/);
    assert.equal(t.device.location_state,'live');
    assert.deepEqual(t.requests.filter(r=>r.action==='set_place_permission').map(r=>r.allow_saved_places),[true,false]);
    assert.equal(t.requests.filter(r=>r.action==='stop_history').length,1);
    await page.evaluate(()=>window.phoneFixture.position(Date.now()+1000));
    await waitUntil('live-only fix after offline withdrawal',()=>fixes().length===4,8000);
    assert.equal(fixes()[3].history_version,undefined);
    Object.assign(t.device,{history_state:'requested',history_version:t.device.history_version+1,history_retention_days:30});
    await page.evaluate(()=>window.phoneFixture.wake());
    await scopeText('history',/wants to keep.*30 days/);
    await page.locator('#phone-history-decline').click(); await scopeText('history',/^Off$/);
    assert.equal(t.device.location_state,'live');
    assert.equal(t.requests.filter(r=>r.action==='accept_history').length,1);
    assert.equal(t.requests.filter(r=>r.action==='decline_history').length,1);
    Object.assign(t.device,{history_state:'requested',history_version:t.device.history_version+1,history_retention_days:7});
    await page.evaluate(()=>window.phoneFixture.wake());
    await scopeText('history',/wants to keep.*7 days/);
    await page.evaluate(()=>window.phoneFixture.network(false));
    await page.locator('#phone-history-decline').click();
    Object.assign(t.device,{history_state:'requested',history_version:t.device.history_version+2,history_retention_days:1});
    await page.evaluate(()=>window.phoneFixture.network(true));
    await scopeText('history',/wants to keep.*1 day/);
    assert.equal(t.requests.filter(r=>r.action==='decline_history').length,1,'old offline decline leaves a replacement request untouched');
    assert.deepEqual(t.errors,[]); await t.close();
  }
  {
    const t = await scenario({initial:{...initialDevice('approved',8),arrival_rules:[]},holdFirstFix:true});
    const {page} = t;
    await t.visit();
    assert.equal(await page.locator('#phone-arrivals').isVisible(),false);
    await page.locator('#phone-start').click();
    await waitText(page,'Waiting for a fresh');
    const gen=t.device.generation, studio='a'.repeat(32), office='b'.repeat(32);
    const rule=(id,place_name,version)=>({id,place_name,version,generation:gen,state:'requested',task_query:'recording'});
    t.device.arrival_rules=[{...rule(studio,'Studio',1),offers_local_search:true},rule(office,'Office',1)];
    const row=id=>page.locator('#phone-arrivals [data-rule-id="'+id+'"]');
    const button=(id,action)=>row(id).locator('[data-arrival-action="'+action+'"]');
    const fixes=()=>t.requests.filter(r=>r.action==='fix');
    await page.evaluate(()=>window.phoneFixture.wake());
    await row(studio).waitFor({state:'visible'});
    assert.match(await row(studio).textContent(),/Studio.*David Evans.*private Agent Chat.*approve a local business search/);
    assert.doesNotMatch(await row(office).textContent(),/local business search/);
    assert.ok(await button(office,'accept_arrival').isVisible(),'the second request has its own consent control');
    await page.evaluate(()=>window.phoneFixture.position(Date.now()-10000));
    await t.firstFix;
    await page.evaluate(()=>window.phoneFixture.position(Date.now()-6000));
    await button(studio,'accept_arrival').click();
    t.rejectFirstFix();
    await button(studio,'stop_arrival').waitFor({state:'visible'});
    await waitText(page,'received this phone');
    assert.equal(fixes().length,2);
    assert.ok(fixes().every(f=>!f.arrival_grants),'old buffered fixes stay live-only after approval');
    await page.evaluate(()=>window.phoneFixture.position());
    await waitUntil('an arrival-authorized sensor sample',()=>fixes().length===3,8000);
    assert.deepEqual(fixes()[2].arrival_grants,{[studio]:2},'only the intended phone rule is eligible');
    assert.equal(fixes()[2].history_version,undefined,'arrival checks work with trail history off');

    await page.evaluate(()=>window.phoneFixture.network(false));
    await button(studio,'stop_arrival').click();
    await button(office,'decline_arrival').click();
    assert.match(await row(studio).textContent(),/stopped on this phone/);
    assert.equal(t.requests.filter(r=>r.action==='stop_arrival').length,0,'offline stop waits for transport');
    const storage = await page.evaluate(()=>JSON.stringify([Object.entries(localStorage),Object.entries(sessionStorage)]));
    assert.ok(!/Studio|Office|recording|latitude|longitude/.test(storage),'local retry records contain only action and opaque scope');
    t.device.arrival_rules[1].version=3;
    await page.evaluate(()=>window.phoneFixture.network(true));
    await row(studio).waitFor({state:'detached'});
    await button(office,'accept_arrival').waitFor({state:'visible'});
    assert.equal(t.requests.filter(r=>r.action==='stop_arrival').length,1);
    assert.equal(t.requests.filter(r=>r.action==='decline_arrival').length,0,'offline withdrawal cannot decline a revised request');
    assert.equal(await page.evaluate(()=>window.phoneFixture.active),true);
    await page.evaluate(()=>window.phoneFixture.position(Date.now()+1000));
    await waitUntil('a live-only sensor sample after arrival withdrawal',()=>fixes().length===4,8000);
    assert.equal(fixes()[3].arrival_grants,undefined);
    await button(office,'decline_arrival').click();
    await page.locator('#phone-arrivals').waitFor({state:'hidden'});
    assert.deepEqual(t.requests.filter(r=>r.action==='decline_arrival').map(r=>[r.rule_id,r.rule_version]),[[office,3]]);
    assert.deepEqual(t.errors,[]);
    await t.close();
  }
  console.log('PASS phone holder browser: consent, retry, denial, offline stop, supersession, revocation, unavailable GPS, in-flight recovery, per-device cadence, live accuracy changes, separate history, place and per-rule arrival permissions');
} finally {await browser.close();}
