import assert from 'node:assert/strict';
import {mkdir} from 'node:fs/promises';
import {chromium} from 'playwright';
import {startProfileWorkspaceServer} from './fixtures/profile-specialist-workspace-server.mjs';

// Real production endpoint, SQL evidence and workspace; no mocked report data.
const server=await startProfileWorkspaceServer({analytics:true,responseAnalytics:true});
let browser,page,checks=0;
const errors=[],requests=[];
const check=(ok,label)=>{assert.ok(ok,label);checks++;};
try{
  await mkdir('artifacts/profile-response-analytics',{recursive:true});
  browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
  page=await browser.newPage({viewport:{width:1280,height:960}});page.setDefaultTimeout(15000);
  page.on('pageerror',error=>errors.push(error.message));
  page.on('request',request=>{if(request.url().includes('/api/profile-specialist-workspace-v1.php'))requests.push({method:request.method(),url:request.url()});});
  await page.goto(server.base);await page.waitForFunction(()=>window.VP3AgentActivityHub?.openProfile&&window.VP3ProfileWorkspaceV1);
  const panel=()=>page.locator('#vp3ChatSpecialistActivity');
  const report=()=>panel().locator('[data-profile-response-period]');
  const backlog=()=>panel().locator('[data-profile-response-backlog]');
  const metric=key=>report().locator('[data-profile-analytics-metric="'+key+'"]');
  const control=async action=>{const r=await page.request.post(server.base+'/fixture/control',{data:{action}});assert.equal(r.status(),200,await r.text());};
  const refresh=async()=>{await panel().getByRole('button',{name:'Refresh Profile',exact:true}).click();await report().waitFor();};
  const layout=async()=>{
    const bounds=await panel().boundingBox(),size=page.viewportSize();
    check(bounds&&bounds.x>=0&&bounds.y>=0&&bounds.x+bounds.width<=size.width+1&&bounds.y+bounds.height<=size.height+1,'report stays inside the viewport');
    check(await panel().evaluate(el=>el.scrollWidth<=el.clientWidth+1),'report has no horizontal overflow');
    const small=await report().locator('p,small,th,td,strong,dt,dd,summary').evaluateAll(nodes=>nodes.filter(node=>node.getClientRects().length&&node.textContent.trim()&&parseFloat(getComputedStyle(node).fontSize)<12).length);
    check(small===0,'response report type remains readable');
  };
  await page.evaluate(()=>VP3AgentActivityHub.openProfile({tab:'analytics',analyticsRef:fixtureSeed.analytics.ref}));await report().waitFor();
  check((await metric('owner_requests_submitted').locator('td').first().innerText()).trim()==='7','completed report counts seven unique submitted questions');
  check((await metric('knowledge_gap_requests').locator('td').first().innerText()).trim()==='4'
    &&(await metric('user_response_requests').locator('td').first().innerText()).trim()==='1'
    &&(await metric('service_unavailable_requests').locator('td').first().innerText()).trim()==='1'
    &&(await metric('quota_exhausted_requests').locator('td').first().innerText()).trim()==='1','knowledge gaps, direct owner mode, service failures and quota limits stay distinct');
  check((await metric('owner_requests_answered').locator('td').first().innerText()).trim()==='3'
    &&(await metric('median_owner_response_seconds').locator('td').first().innerText()).includes('600'),'owner responses include earlier submissions and use verified reply timing');
  check((await report().locator('[data-profile-response-questions="period"]').first().innerText()).includes('2 questions in 2 conversations'),'case and punctuation variants form a recurring question group');
  check((await backlog().innerText()).includes('5 waiting for your response · 1 delegated')&&(await backlog().innerText()).includes('Longest current wait:'),'current backlog has distinct waiting/delegated counts and age');
  const note=backlog().locator('[data-profile-response-knowledge="1289101"]');
  check(await note.count()===1&&(await note.getAttribute('href')).endsWith('/knowledge.php?folder=1289001&edit=1289101'),'current permitted note suggestion opens the existing Knowledge editor');
  check((await backlog().innerText()).includes('does not edit notes or change Knowledge Access'),'suggestions explain the existing reviewed edit and permission flow');
  check(!(await panel().innerText()).includes('FOREIGN PRIVATE'),'foreign note metadata is absent');
  await layout();await report().scrollIntoViewIfNeeded();await page.screenshot({path:'artifacts/profile-response-analytics/desktop.png',fullPage:true});
  await page.setViewportSize({width:390,height:844});await layout();await report().scrollIntoViewIfNeeded();
  await page.screenshot({path:'artifacts/profile-response-analytics/mobile.png',fullPage:true});
  await backlog().locator('[data-profile-response-questions="waiting"]').scrollIntoViewIfNeeded();
  await page.screenshot({path:'artifacts/profile-response-analytics/mobile-knowledge-review.png',fullPage:true});
  const conversationButton=backlog().locator('[data-profile-response-conversation]').first(),cid=await conversationButton.getAttribute('data-profile-response-conversation');
  await conversationButton.click();await panel().getByRole('button',{name:'Back to conversations',exact:true}).waitFor();
  check(new URL(requests.at(-1).url).searchParams.get('public_conversation_id')===cid,'question action opens its exact owner-scoped conversation');
  await page.evaluate(()=>VP3AgentActivityHub.openProfile({tab:'analytics',analyticsRef:fixtureSeed.analytics.ref}));await report().waitFor();
  await control('analytics_note_revoke');await refresh();
  check(await backlog().locator('[data-profile-response-knowledge]').count()===0,'revoked current note permission removes saved-note suggestions');
  check((await metric('owner_requests_submitted').locator('td').first().innerText()).trim()==='7','note permission changes preserve completed request facts');
  await control('analytics_note_restore');await refresh();check(await backlog().locator('[data-profile-response-knowledge]').count()===1,'current permitted review links recover on refresh');
  await control('analytics_responses_off');await refresh();
  check((await metric('owner_requests_submitted').innerText()).includes('Unavailable')&&(await backlog().innerText()).includes('unavailable'),'missing response storage is unavailable rather than zero');
  check(await panel().locator('[data-profile-analytics-metric="visit_records"]').count()===1,'independent analytics remain visible during a response ledger outage');
  await control('analytics_responses_restore');await refresh();
  check((await metric('owner_requests_submitted').locator('td').first().innerText()).trim()==='7','restored durable evidence restores the exact selected period');
  await control('analytics_revoke');await panel().getByRole('button',{name:'Refresh Profile',exact:true}).click();await panel().getByRole('button',{name:'Reload Chat',exact:true}).waitFor();
  check(await report().count()===0&&await backlog().count()===0,'Profile permission loss clears private response reports and note titles');
  check(requests.every(request=>request.method==='GET'),'report, note suggestion and conversation navigation perform no mutations');
  check(server.readWriteFailure()==='','real endpoint reporting starts no provider or SQL write');
  assert.deepEqual(errors,[]);check(true,'no browser runtime errors');
  console.log('PROFILE_RESPONSE_ANALYTICS_BROWSER_V1=PASS ('+checks+' checks; real API/SQL; desktop and390px)');
}catch(error){
  if(page)try{await page.screenshot({path:'artifacts/profile-response-analytics/failure.png',fullPage:true,timeout:5000});}catch{}
  error.message+='\nPHP log:\n'+server.logs();throw error;
}finally{await browser?.close();await server.close();}
