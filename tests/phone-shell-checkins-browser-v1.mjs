import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync,mkdirSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const root=new URL('../',import.meta.url),html=execFileSync(process.env.PHP_BINARY||'php',[fileURLToPath(new URL('phone-location.php',root))],{encoding:'utf8'});
const statuses=JSON.parse(execFileSync(process.env.PHP_BINARY||'php',[fileURLToPath(new URL('tests/fixtures/phone-notification-status-v1.php',root))],{encoding:'utf8'}));
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
try{
 for(const width of [320,390,1280]){
  const page=await browser.newPage({viewport:{width,height:width===320?640:844},hasTouch:true});page.setDefaultTimeout(5000);
  const errors=[],requests=[];page.on('pageerror',e=>errors.push(e.message));
  const now=()=>Math.floor(Date.now()/1000),id='d'.repeat(32),profile='a'.repeat(32);
  const device={id,name:'My phone',role:'owner',status:'approved',generation:2,allow_location:true,location_state:'live',fix_at:now(),accuracy:8,holder_can_save_places:true,holder_place_version:1,
    communication:{profile_id:profile,revision:1,request_permission_saved:true,information_pending:1},checkins:{ready:true,server_epoch:now(),active:null,recent:[],patterns:[]}};
  device.push=structuredClone(statuses.find(item=>item.name==='stale_worker').device.push);device.push.delivery.last_test.device_id=id;
  await page.addInitScript(()=>Object.defineProperty(navigator,'geolocation',{configurable:true,value:{watchPosition:()=>1,clearWatch:()=>{},getCurrentPosition:ok=>ok({timestamp:Date.now(),coords:{latitude:33.45,longitude:-112.07,accuracy:8}})}}));
  await page.route('https://phone-shell.test/**',async route=>{
   const u=new URL(route.request().url());if(u.pathname==='/phone-location.php')return route.fulfill({contentType:'text/html',body:html});
   if(/^\/phone-[a-z0-9-]+\.(js|css)$/.test(u.pathname))return route.fulfill({contentType:u.pathname.endsWith('.js')?'text/javascript':'text/css',body:readFileSync(new URL(u.pathname.slice(1),root))});
   if(u.pathname!=='/api/phone-location-v1.php')return route.fulfill({status:404});
   const q=route.request().postDataJSON();requests.push(q);
   const ok=data=>route.fulfill({json:{ok:true,device,owner_name:'David',...data}});
   if(u.search)return route.fulfill({status:403,json:{ok:false}});
   if(q.action==='status')return ok({});
   if(q.action==='information_list')return ok({information:{profile_id:profile,profile_revision:1,files_ready:true,delete_ready:true,items:[{exchange_id:'e'.repeat(32),version:1,kind:'question',state:'pending',message:'Can you review this?',response:'',created_epoch:now(),updated_epoch:now(),expires_epoch:now()+86400}]}});
   if(q.action==='location_checkin'){
    assert.equal(q.confirmed,true);assert.equal(q.generation,2);assert.equal(q.learn,true);
    device.checkins.active={visit_id:q.visit_id,version:1,label:'',started_epoch:now()-3660,last_fix_epoch:now(),duration_seconds:3660,learning:true};return ok({});
   }
   if(q.action==='location_checkout'){assert.equal(q.version,1);device.checkins.recent=[{...device.checkins.active,ended_epoch:now(),reason:'manual'}];device.checkins.active=null;return ok({});}
   if(q.action==='holder_save_place'){assert.equal(q.holder_place_version,1);assert.equal(q.name,'Studio');return ok({saved_place:{id:'f'.repeat(32),name:q.name}});}
   if(q.action==='chat_status')return ok({turns:[]});
   return route.fulfill({status:409,json:{ok:false}});
  });
  await page.goto('https://phone-shell.test/phone-location.php');
  await page.getByRole('tab',{name:'Home',exact:true}).waitFor();
  assert.equal(await page.locator('#phone-home').isVisible(),true);assert.equal(await page.locator('#phone-dock').isVisible(),false);assert.equal(requests.filter(q=>q.action==='location_checkin'||q.action==='information_list').length,0);
  await page.locator('#phone-user-menu summary').click();await page.getByRole('button',{name:'Phone settings',exact:true}).waitFor();await page.locator('#phone-user-menu summary').press('Escape');assert.equal(await page.locator('#phone-user-menu').evaluate(e=>e.open),false);
  await page.getByRole('tab',{name:/^Requests/}).click();await page.getByText('Can you review this?',{exact:true}).waitFor();assert.equal(await page.getByRole('button',{name:'Delete request',exact:true}).count(),0,'holder has no owner delete control');
  await page.getByRole('tab',{name:'Phone',exact:true}).click();
  await assert.match(await page.locator('#phone-push-test-state').textContent(),/worker has not run recently.*Last test:.*was opened/,'current blocker and past success remain visible together');
  await page.getByLabel('Let the Location Specialist learn from this check-in').check();await page.getByRole('button',{name:'Check In Location',exact:true}).click();
  await page.getByText(/Checked in · 1h 1m/).waitFor();assert.equal(requests.filter(q=>q.action==='location_checkin').length,1);
  assert.equal(await page.getByRole('button',{name:'Check Out Location',exact:true}).isEnabled(),true);
  await page.getByRole('button',{name:'Save this location',exact:true}).click();await page.getByLabel('Location name',{exact:true}).fill('Studio');await page.getByRole('button',{name:'Save current location',exact:true}).click();await page.getByText('Saved “Studio” to David’s saved locations.',{exact:true}).waitFor();await page.getByRole('button',{name:'Done',exact:true}).click();
  await page.getByRole('button',{name:'Check Out Location',exact:true}).click();await page.getByText(/Location visit · 1h 1m/).waitFor();assert.equal(await page.getByRole('button',{name:'Check In Location',exact:true}).isVisible(),true);
  device.holder_can_save_places=false;device.holder_place_version++;device.checkins.recent[0].reason='left_area';await page.evaluate(()=>window.dispatchEvent(new Event('online')));await page.getByText(/Automatically checked out after leaving/).waitFor();assert.equal(await page.getByRole('button',{name:'Save this location',exact:true}).isVisible(),false,'revoked saved-location permission removes the action');
  const geometry=await page.evaluate(()=>({width:innerWidth,body:document.documentElement.scrollWidth,bottom:document.getElementById('phone-location').getBoundingClientRect().bottom,height:innerHeight,nested:[...document.querySelectorAll('#phone-connection *')].filter(n=>['auto','scroll'].includes(getComputedStyle(n).overflowY)&&n.scrollHeight>n.clientHeight+2).map(n=>n.id)}));
  assert.ok(geometry.body<=geometry.width+1,JSON.stringify(geometry));assert.ok(geometry.bottom<=geometry.height+2,JSON.stringify(geometry));assert.deepEqual(geometry.nested,[]);
  if(process.env.VP3_PHONE_SCREENSHOTS){mkdirSync(process.env.VP3_PHONE_SCREENSHOTS,{recursive:true});await page.screenshot({path:process.env.VP3_PHONE_SCREENSHOTS+'/phone-location-checkins-'+width+'.png'});await page.getByRole('tab',{name:'Home',exact:true}).click();await page.screenshot({path:process.env.VP3_PHONE_SCREENSHOTS+'/phone-home-'+width+'.png'});}
  assert.deepEqual(errors,[]);await page.close();console.log('PASS Phone shell, Requests, location check-in/checkout, and permission-scoped saved place at '+width+'px');
 }
}finally{await browser.close();}
