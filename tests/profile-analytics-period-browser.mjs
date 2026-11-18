import assert from 'node:assert/strict';
import {mkdir} from 'node:fs/promises';
import {chromium} from 'playwright';
import {startProfileWorkspaceServer} from './fixtures/profile-specialist-workspace-server.mjs';

// Hosted browser acceptance using the real scoped endpoint and isolated SQL.
const server=await startProfileWorkspaceServer({analytics:true});
let browser,page,checks=0;
const errors=[],requests=[];
const check=(value,label)=>{assert.ok(value,label);checks++;};
try{
  await mkdir('artifacts/profile-analytics-period',{recursive:true});
  browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
  page=await browser.newPage({viewport:{width:1280,height:960}});page.setDefaultTimeout(15000);
  page.on('pageerror',error=>errors.push(error.message));
  page.on('request',request=>{if(request.url().includes('/api/profile-specialist-workspace-v1.php'))requests.push({method:request.method(),url:request.url()});});
  await page.goto(server.base);await page.waitForFunction(()=>window.VP3AgentActivityHub?.openProfile&&window.VP3ProfileWorkspaceV1);
  const panel=()=>page.locator('#vp3ChatSpecialistActivity');
  const period=()=>panel().locator('[data-profile-analytics-period]');
  const metric=key=>panel().locator('[data-profile-analytics-metric="'+key+'"]');
  const control=async action=>{const response=await page.request.post(server.base+'/fixture/control',{data:{action}});assert.equal(response.status(),200,await response.text());};
  const layout=async()=>{
    const bounds=await panel().boundingBox(),size=page.viewportSize();
    check(bounds&&bounds.x>=0&&bounds.y>=0&&bounds.x+bounds.width<=size.width+1&&bounds.y+bounds.height<=size.height+1,'period workspace stays in viewport');
    check(await panel().evaluate(el=>el.scrollWidth<=el.clientWidth+1),'period workspace has no horizontal overflow');
    const scrollers=await period().evaluate(el=>[...el.querySelectorAll('*')].filter(node=>['auto','scroll'].includes(getComputedStyle(node).overflowY)&&node.scrollHeight>node.clientHeight+1).length);
    check(scrollers===0,'period sections have no nested scrolling');
    const tiny=await period().locator('p,small,th,td,strong,dt,dd,summary').evaluateAll(nodes=>nodes.filter(node=>node.getClientRects().length&&node.textContent.trim()&&parseFloat(getComputedStyle(node).fontSize)<12).map(node=>node.textContent));
    check(tiny.length===0,'period text remains at least12px');
  };
  await page.evaluate(()=>VP3AgentActivityHub.openProfile({tab:'analytics',analyticsRef:fixtureSeed.analytics.ref}));
  await period().waitFor();
  check(await period().getAttribute('data-profile-analytics-period')===server.seed.analytics.ref,'browser opens the selected fixed source');
  check((await metric('visit_records').innerText()).includes('3')&&(await metric('visit_records').innerText()).includes('1'),'selected and previous native visit evidence displayed');
  check((await panel().innerText()).includes('USD 20.00')&&(await panel().innerText()).includes('EUR 15.00'),'browser keeps verified currencies separate');
  check(await panel().locator('[data-profile-backlog]').count()===2,'current conversation and unanswered-request backlog shown separately');
  await layout();await panel().evaluate(el=>{el.scrollTop=0;});
  await page.screenshot({path:'artifacts/profile-analytics-period/desktop-period.png',fullPage:true});
  await panel().getByRole('button',{name:'7 days',exact:true}).click();
  const sibling=server.seed.analytics.period_options.find(row=>row.period===7).ref;
  await page.waitForFunction(ref=>document.querySelector('[data-profile-analytics-period]')?.dataset.profileAnalyticsPeriod===ref,sibling);
  check((await period().innerText()).includes('Observed subset'),'incomplete historical period uses observed-subset wording');
  await panel().getByRole('button',{name:'Refresh Profile',exact:true}).click();await period().waitFor();
  check(new URL(requests.at(-1).url).searchParams.get('ref')===sibling,'explicit refresh retains cutoff and timezone');
  await panel().getByRole('button',{name:'1 day',exact:true}).click();
  await page.waitForFunction(ref=>document.querySelector('[data-profile-analytics-period]')?.dataset.profileAnalyticsPeriod===ref,server.seed.analytics.ref);
  await page.setViewportSize({width:390,height:844});await layout();await panel().evaluate(el=>{el.scrollTop=0;});
  await page.screenshot({path:'artifacts/profile-analytics-period/compact-period.png',fullPage:true});
  const commerce=metric('verified_payments:USD');await commerce.scrollIntoViewIfNeeded();
  await page.screenshot({path:'artifacts/profile-analytics-period/compact-commerce.png',fullPage:true});
  await panel().getByRole('button',{name:'Traffic details',exact:true}).click();await panel().locator('.vp3-analytics-shell').waitFor();
  check((await panel().innerText()).includes('Traffic across your VP3 presence'),'existing traffic renderer remains available');
  await panel().getByRole('button',{name:'Completed periods',exact:true}).click();await period().waitFor();
  check(await period().getAttribute('data-profile-analytics-period')===server.seed.analytics.ref,'switching traffic view retains exact completed source');
  await control('profile_off');await panel().getByRole('button',{name:'Refresh Profile',exact:true}).click();await period().waitFor();
  check((await panel().innerText()).includes('USD 20.00'),'private period remains available while public Profile is off');
  await control('analytics_events_off');await panel().getByRole('button',{name:'Refresh Profile',exact:true}).click();await period().waitFor();
  check((await metric('visit_records').innerText()).includes('Unavailable'),'event outage is visibly unavailable');
  check((await panel().innerText()).includes('USD 20.00'),'verified commerce remains visible during independent event outage');
  await control('analytics_events_restore');await control('analytics_revoke');
  await panel().getByRole('button',{name:'Refresh Profile',exact:true}).click();await panel().getByRole('button',{name:'Reload Chat',exact:true}).waitFor();
  check(await period().count()===0&&!(await panel().innerText()).includes('USD 20.00'),'permission loss clears period values');
  check(requests.every(request=>request.method==='GET'),'Analytics interactions use read endpoints only');
  check(server.readWriteFailure()==='','all Analytics reads preserve SQL and start no provider');
  assert.deepEqual(errors,[]);check(true,'no browser runtime errors');
  console.log('PROFILE_ANALYTICS_PERIOD_BROWSER=PASS ('+checks+' checks; production API and SQL, desktop and390px)');
}catch(error){
  if(page)try{await page.screenshot({path:'artifacts/profile-analytics-period/failure.png',fullPage:true,timeout:5000});}catch{}
  error.message+='\nPHP log:\n'+server.logs();throw error;
}finally{await browser?.close();await server.close();}
