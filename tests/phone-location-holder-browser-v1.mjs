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
async function scenario({initial = null, geolocation = true, lostClaimAck = false} = {}) {
  const context = await browser.newContext({viewport:{width:375,height:667},isMobile:true,hasTouch:true});
  let device = initial && {...initial}, revoked = false, drop = lostClaimAck;
  let requests = [], routes = [], errors = [];
  const page = await context.newPage();
  page.on('pageerror',e=>errors.push(String(e)));
  page.setDefaultTimeout(4500);
  await page.addInitScript(enabled=>{
    let pos,err,watch=0,active=false,online=true;
    Object.defineProperty(navigator,'onLine',{configurable:true,get:()=>online});
    Object.defineProperty(navigator,'geolocation',{configurable:true,value:enabled ? {
      watchPosition(ok,bad) { pos=ok; err=bad; active=true; return ++watch; },
      clearWatch() { active=false; },
    } : undefined});
    window.phoneFixture = {
      get watches() { return watch; }, get active() { return active; },
      position(timestamp=Date.now()) { if (active) pos({timestamp,coords:{latitude:33.4,longitude:-112.1,accuracy:9}}); },
      denial() { if (active) err({code:1}); },
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
      if (req.action === 'start') {
        if (device.location_state !== 'requested') device.generation++;
        device.location_state='waiting';
      } else if (req.action === 'fix') {
        device.location_state='live'; device.fix_at=req.fix_at; device.accuracy=req.accuracy;
      } else if (req.action === 'disconnect') {device.status='revoked';revoked=true;}
      else {device.generation++;device.location_state=req.action==='denied'?'denied':'off';device.fix_at=null;}
    }
    return send(200,{ok:true,device,owner_name:'David Evans'});
  });
  return {page,requests,routes,errors, get device(){return device;}, revoke(){revoked=true;},
    visit:frag=>page.goto(origin+pathname+(frag||'')), close:()=>context.close()};
}
const waitText = (p,re) => p.waitForFunction(s=>new RegExp(s,'i').test(document.querySelector('#phone-status').textContent),re);
const pause = page => page.evaluate(()=>new Promise(r=>setTimeout(r,60)));
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
  console.log('PASS phone holder browser: claim/retry, local consent, stale fix, denial, offline stop, cookie session, supersession, revocation and unavailable GPS');
} finally {await browser.close();}
