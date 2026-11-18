import assert from 'node:assert/strict';
import {mkdir} from 'node:fs/promises';
import {chromium} from 'playwright';
import {startProfileSpecialistReportServer} from './fixtures/profile-specialist-report-server.mjs';

// Run in hosted browser CI. The baseline reads the production ActivityHub API
// and Profile source adapter; interception adds only explicit failure/race cases.
const server=await startProfileSpecialistReportServer();
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],
  executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const page=await browser.newPage({viewport:{width:1280,height:900}});
page.setDefaultTimeout(10000);
const errors=[],requests=[];
let mode='ready',held=null;
const pendingGates=new Set();
const defer=()=>{let resolve;const promise=new Promise(done=>{resolve=done;});return {promise,resolve};};
let checks=0;
const check=(truth,label)=>{assert.ok(truth,label);checks++;};
page.on('pageerror',error=>errors.push(error.message));
await page.route('**/api/agent-activity-hub-v1.php**',async route=>{
  const request=route.request(),query=new URL(request.url()).searchParams;
  requests.push({method:request.method(),view:query.get('view'),agent:query.get('agent_id'),chat:query.get('conversation_id')});
  assert.equal(request.method(),'GET');
  assert.equal(query.get('view'),'profile');
  const scenario=mode;
  const real=await route.fetch({headers:{...request.headers(),
    ...(['limited','unavailable','unconfigured'].includes(scenario)?{'X-VP3-Test-Profile-State':scenario}:{})}});
  if(!real.ok())return route.fulfill({response:real});
  const data=await real.json();
  if(scenario==='hostile'){
    data.state.profile_report.key_findings=['Visitor <img src=x onerror="window.profileAttack=true"> asks about the workshop.'];
    data.state.profile_report.findings[0].title='Visitor <svg onload="window.profileAttack=true">';
  }
  if(scenario==='unsafe')data.state.profile_report.findings[0].url='//untrusted.test/profile-agent.php?tab=inbox&conversation=1';
  if(scenario==='wrong_scope')data.state.conversation_id++;
  if(scenario==='failure')return route.fulfill({status:503,contentType:'text/html',body:'Fixture outage'});
  if(scenario==='delay'&&held){
    const gate=held;held=null;mode='ready';
    data.state.profile_report.key_findings=['STALE PRIVATE PROFILE REPORT'];
    pendingGates.add(gate);gate.started.resolve();
    try{await gate.release.promise;}finally{pendingGates.delete(gate);}
  }
  try{await route.fulfill({response:real,json:data});}catch(error){
    if(!/closed|disposed|cancel|abort/i.test(error.message))throw error;
  }
});
const panel=()=>page.locator('#vp3ChatSpecialistActivity');
const ready=async coverage=>page.waitForSelector(`[data-profile-report="${coverage}"]`);
const refresh=async()=>page.evaluate(()=>window.VP3AgentActivityHub.refresh());
const headings=async()=>panel().locator('[data-profile-report-section] h4').allTextContents();
const expected=['Key findings','Actions & follow-ups','Open questions','Next step','Questions for you'];
async function reload(){mode='ready';await page.goto(server.base);await page.waitForFunction(()=>window.VP3AgentActivityHub&&window.VP3ChatSpecialists?.snapshot({includeResults:true}).some(row=>row.key==='profile'));}
async function openDesktop(){await page.locator('[data-chat-specialist="profile"]').click();await ready('ready');}
async function openMind(){
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:activity-center-view',{detail:{
    topOfMindHost:document.getElementById('topMindHost'),open:true,tab:'brain'}})));
  await page.locator('[data-top-of-mind-specialist="profile"]').waitFor();
}
async function contained(){
  const rect=await panel().boundingBox();const size=page.viewportSize();
  check(rect&&rect.x>=0&&rect.y>=0&&rect.x+rect.width<=size.width+1&&rect.y+rect.height<=size.height+1,'Profile report stays inside the viewport');
  check(await panel().evaluate(node=>node.scrollWidth<=node.clientWidth+1),'Profile report has no horizontal overflow');
}
try{
  await mkdir('artifacts/profile-specialist',{recursive:true});
  await reload();
  check(await page.locator('[data-chat-specialist="profile"]').isVisible(),'Profile appears in the normal private specialist rail');
  await openDesktop();
  check(JSON.stringify(await headings())===JSON.stringify(expected),'current source report contains all five required sections');
  check(await panel().getByRole('link').count()>0,'real adapter findings provide exact source links');
  check(requests.every(row=>row.method==='GET'&&row.view==='profile'&&row.agent==='7'&&row.chat==='11'),'report requests preserve the current saved private scope');
  await contained();
  await page.screenshot({path:'artifacts/profile-specialist/desktop-compact.png',fullPage:true});
  await panel().getByRole('button',{name:'Full report',exact:true}).click();
  check(await panel().getAttribute('data-workspace')==='true','Full report expands the existing specialist shell');
  check(await panel().getByRole('tab').count()===0,'Phase 2 does not create a competing tabbed workspace');
  await contained();
  await page.screenshot({path:'artifacts/profile-specialist/desktop.png',fullPage:true});
  const sourceHref=await panel().locator('[data-profile-source]').first().getAttribute('href');
  check(/^\/profile-agent\.php\?tab=(inbox&conversation|visitors&session)=[1-9][0-9]*$/.test(sourceHref)
    ||/^\/artist-listening\.php\?session=[1-9][0-9]*$/.test(sourceHref),'source link is an exact native destination');
  await panel().getByRole('button',{name:'Close',exact:true}).click();
  await openMind();
  const mind=page.locator('[data-top-of-mind-specialist="profile"]');
  await mind.waitFor();
  check((await mind.innerText()).includes('Profile sources checked'),'Top of Mind displays Profile with its current coverage');
  await mind.getByRole('button',{name:'Open Profile report',exact:true}).click();
  await ready('ready');
  check(await panel().getAttribute('data-workspace')==='true','Top of Mind opens the same current full report');

  mode='hostile';await refresh();
  await panel().getByText('Visitor <img src=x onerror="window.profileAttack=true"> asks about the workshop.',{exact:true}).waitFor();
  check(await panel().locator('img,svg[onload],script').count()===0,'visitor markup cannot create executable DOM');
  check(await page.evaluate(()=>window.profileAttack===undefined),'visitor text cannot execute code');
  for(const coverage of ['limited','unavailable','unconfigured']){
    mode=coverage;await refresh();await ready(coverage);
    check(JSON.stringify(await headings())===JSON.stringify(expected),coverage+' keeps the report structure');
    if(coverage!=='limited'){
      check(await panel().locator('[data-profile-source]').count()===0,coverage+' removes previous source links');
      check(!(await panel().innerText()).includes('asks about the workshop'),coverage+' removes previous private facts');
    }else check((await panel().innerText()).includes('may be incomplete'),'limited data is presented as partial coverage');
  }
  for(const failure of ['unsafe','wrong_scope','failure']){
    mode=failure;await refresh();
    await panel().getByText('The Profile report could not be loaded. Retrying shortly.',{exact:false}).waitFor();
    check(await panel().locator('[data-profile-source]').count()===0,failure+' cannot retain earlier links');
    check(await panel().locator('[data-profile-report]').count()===0,failure+' cannot retain an old report');
    mode='ready';await panel().getByRole('button',{name:'Retry now',exact:true}).click();await ready('ready');
  }

  held={started:defer(),release:defer()};const chatGate=held;mode='delay';await refresh();await chatGate.started.promise;
  await page.evaluate(()=>{
    window.dispatchEvent(new Event('stonefellow:chat-context-changing'));
    fixtureScope.cid=0;window.dispatchEvent(new Event('vp3:chat-context-ready'));
    VP3AgentActivityHub.openSpecialist('profile',document.getElementById('chatInput'),{label:'Profile',fullReport:true});
  });
  chatGate.release.resolve();await ready('unconfigured');
  check((await panel().innerText()).includes('Save this Chat'),'changing to an unsaved Chat reads its own setup state');
  check(!(await page.locator('body').innerText()).includes('STALE PRIVATE PROFILE REPORT'),'late response from another Chat is discarded');

  await reload();await openDesktop();
  held={started:defer(),release:defer()};const agentGate=held;mode='delay';await refresh();await agentGate.started.promise;
  await page.evaluate(()=>{
    window.dispatchEvent(new Event('stonefellow:chat-context-changing'));
    fixtureScope.agent=8;STONEFELLOW_CHAT.agentId=8;
    window.dispatchEvent(new Event('vp3:chat-context-ready'));
    VP3AgentActivityHub.openSpecialist('profile',document.getElementById('chatInput'),{label:'Profile',fullReport:true});
  });
  agentGate.release.resolve();
  await panel().getByText('This Chat’s access could not be verified.',{exact:false}).waitFor();
  check(!(await page.locator('body').innerText()).includes('STALE PRIVATE PROFILE REPORT'),'late response from another Agent is discarded');
  check(await panel().locator('[data-profile-source]').count()===0,'Agent mismatch leaves no old source links');

  await page.setViewportSize({width:390,height:844});await reload();
  await openMind();
  await page.locator('[data-top-of-mind-profile-report]').click();await ready('ready');
  check(await panel().getAttribute('data-workspace')==='true','compact Top of Mind opens Profile through its production report action');
  check(JSON.stringify(await headings())===JSON.stringify(expected),'compact viewport retains the complete report');
  await contained();
  await page.screenshot({path:'artifacts/profile-specialist/compact.png',fullPage:true});
  await page.keyboard.press('Escape');await page.keyboard.press('Escape');
  check(await panel().count()===0,'keyboard dismissal closes the shared report shell');
  check(await page.evaluate(()=>document.activeElement?.id==='chatInput'),'keyboard dismissal returns focus to the Chat composer');
  await page.locator('[data-top-of-mind-profile-report]').click();await ready('ready');
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:9}})));
  check(await panel().count()===0,'participant switch clears the owner report');
  check(await page.evaluate(()=>VP3AgentActivityHub.openSpecialist('profile',null,{label:'Profile'}))===false,'participant context cannot reopen owner Profile evidence');
  check(server.readWriteFailure()==='','browser report reads do not mutate storage or invoke inference');
  assert.deepEqual(errors,[],'browser runtime should not raise page errors');
  console.log(`AGENT_PROFILE_SPECIALIST_REPORT_BROWSER=PASS (${checks} checks)`);
}catch(error){console.error(server.logs());throw error;}
finally{
  if(held)held.release.resolve();
  for(const gate of pendingGates)gate.release.resolve();
  await browser.close();await server.close();
}
