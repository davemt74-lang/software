import assert from 'node:assert/strict';
import {mkdir} from 'node:fs/promises';
import {chromium} from 'playwright';
import {startProfileWorkspaceServer} from './fixtures/profile-specialist-workspace-server.mjs';
import {profileAnalyticsRouteBrowserV1} from './fixtures/profile-analytics-route-browser-v1.mjs';

// Hosted browser acceptance: real API/session/SQL/provider fixture; never a live visitor/provider.
const server=await startProfileWorkspaceServer();
let browser=null,page=null,held=null,mode='ready',checks=0;
const gates=new Set(),requests=[],errors=[];
const defer=()=>{let resolve;const promise=new Promise(done=>{resolve=done;});return {promise,resolve};};
const check=(truth,label)=>{assert.ok(truth,label);checks++;};
const started=async gate=>{let timer;try{await Promise.race([gate.started.promise,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('The expected delayed request did not start.')),15000);})]);}finally{clearTimeout(timer);}};
try{
  browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
  page=await browser.newPage({viewport:{width:1280,height:960}});page.setDefaultTimeout(15000);
  page.on('pageerror',error=>errors.push(error.message));
  await page.route('**/api/profile-specialist-workspace-v1.php**',async route=>{
    const request=route.request();const payload=request.method()==='POST'?request.postDataJSON():Object.fromEntries(new URL(request.url()).searchParams);
    requests.push({method:request.method(),payload:{...payload}});
    const response=await route.fetch();
    if(!response.ok())return route.fulfill({response});
    const value=await response.json();
    if(mode==='lose_prepare'&&payload.action==='draft_prepare'){
      mode='ready';return route.abort('failed'); // real saved request; only its response is lost
    }
    if(mode==='lose_reply'&&payload.action==='conversation_action'&&payload.operation==='reply'){
      mode='ready';return route.abort('failed'); // real canonical send already committed
    }
    if(mode==='wrong_scope'&&request.method()==='GET')value.scope.chat_conversation_id++;
    if(mode==='hostile'&&payload.action==='conversation'){
      value.data.messages.items[0].text='Visitor <img src=x onerror="window.workspaceAttack=true"> asks about sizing.';
    }
    if(mode==='delay_thread'&&payload.action==='conversation'&&held){
      const gate=held;held=null;mode='ready';gates.add(gate);
      value.data.messages.items[0].text='STALE PRIVATE VISITOR CONVERSATION';
      gate.started.resolve();try{await gate.release.promise;}finally{gates.delete(gate);}
    }
    try{await route.fulfill({response,json:value});}catch(error){if(!/closed|disposed|cancel|abort/i.test(error.message))throw error;}
  });
  const fixtureVisitRefs=server.seed.visit_events.map(id=>'profile:event:'+id);
  const panel=()=>page.locator('#vp3ChatSpecialistActivity');
  const tab=name=>panel().getByRole('tab',{name,exact:true});
  const reply=()=>panel().locator('[data-profile-reply-body]');
  const body=()=>page.locator('body');
  async function reload(){mode='ready';await page.goto(server.base);await page.waitForFunction(()=>window.VP3AgentActivityHub?.openProfile&&window.VP3ProfileWorkspaceV1&&window.VP3ChatSpecialists?.snapshot({includeResults:true}).some(row=>row.key==='profile'));}
  async function openThread(id=server.seed.primary){
    await tab('Conversations').click();
    await panel().locator(`[data-profile-conversation="${id}"]`).getByRole('button',{name:'Open conversation',exact:true}).click();
    await reply().waitFor();
  }
  async function openMind(){
    await page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:activity-center-view',{detail:{topOfMindHost:document.getElementById('topMindHost'),open:true,tab:'brain'}})));
    await page.locator('[data-top-of-mind-profile-report]').waitFor();
  }
  async function contained(){
    const bounds=await panel().boundingBox(),viewport=page.viewportSize();
    check(bounds&&bounds.x>=0&&bounds.y>=0&&bounds.x+bounds.width<=viewport.width+1&&bounds.y+bounds.height<=viewport.height+1,'shared workspace stays inside the viewport');
    check(await panel().evaluate(element=>element.scrollWidth<=element.clientWidth+1),'workspace has no horizontal overflow');
    const scrollers=await panel().evaluate(element=>[...element.querySelectorAll('*')]
      .filter(node=>node.tagName!=='TEXTAREA'&&['auto','scroll'].includes(getComputedStyle(node).overflowY)&&node.scrollHeight>node.clientHeight+1)
      .map(node=>({tag:node.tagName,className:node.className,clientHeight:node.clientHeight,scrollHeight:node.scrollHeight})));
    check(scrollers.length===0,'workspace content has no nested vertical scroller: '+JSON.stringify(scrollers));
    check(await page.locator('#profileActivityCanvas').count()===0,'legacy Profile canvas is not mounted alongside the workspace');
  }
  async function readableAnalytics(){
    const offenders=await panel().locator(':is(.vp3-analytics-shell,.profile-revenue-shell) :is(p,span,small,label,select,button,th,td,a,b,strong,h2,h3,.vp3-analytics-empty,.vp3-analytics-privacy)')
      .evaluateAll(nodes=>nodes.filter(node=>node.textContent.trim()&&node.getClientRects().length&&parseFloat(getComputedStyle(node).fontSize)<12)
        .map(node=>({tag:node.tagName,className:node.className,parentClass:node.parentElement.className,fontSize:getComputedStyle(node).fontSize,text:node.textContent.trim().slice(0,100)})));
    check(offenders.length===0,'mounted Analytics explanatory text is readable (at least 12px): '+JSON.stringify(offenders));
    const totals=await panel().locator('.vp3-analytics-metric strong,.profile-revenue-total strong')
      .evaluateAll(nodes=>nodes.map(node=>({text:node.textContent,fontSize:getComputedStyle(node).fontSize})));
    check(totals.length>0&&totals.every(node=>parseFloat(node.fontSize)>=20),'Analytics totals retain a clear visual hierarchy: '+JSON.stringify(totals));
  }
  async function readablePeriods(){
    const offenders=await panel().locator('.vp3-profile-period :is(p,span,small,button,th,td,a,strong,h4,dt,dd,summary)')
      .evaluateAll(nodes=>nodes.filter(node=>node.textContent.trim()&&node.getClientRects().length&&parseFloat(getComputedStyle(node).fontSize)<12)
        .map(node=>({tag:node.tagName,className:node.className,fontSize:getComputedStyle(node).fontSize,text:node.textContent.trim().slice(0,90)})));
    check(offenders.length===0,'completed-period values and coverage stay readable: '+JSON.stringify(offenders));
  }
  async function stored(){const response=await page.request.get(server.base+'/fixture/state');assert.equal(response.status(),200);return response.json();}
  async function control(action){const response=await page.request.post(server.base+'/fixture/control',{data:{action}});assert.equal(response.status(),200,await response.text());}
  await mkdir('artifacts/profile-specialist-workspace',{recursive:true});
  await reload();
  check(await page.locator('.chat-topbar-actions .chat-profile-activity-button').count()===0,'Profile navigation does not add a specialist icon to the main header');
  await page.locator('[data-chat-specialist="profile"]').click();
  await panel().locator('.vp3-profile-service').waitFor();
  check(JSON.stringify(await panel().getByRole('tab').allTextContents())===JSON.stringify(['Overview','Conversations','Calls','Analytics','Reports']),'production Profile rail opens all five workspace tabs');
  check(await tab('Overview').getAttribute('aria-selected')==='true','normal Profile entry starts with Overview');
  await contained();
  await page.screenshot({path:'artifacts/profile-specialist-workspace/desktop-overview.png',fullPage:true});
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('profile-activity:open',{detail:{tab:'conversations'}})));
  await panel().locator('[data-profile-conversation]').first().waitFor();
  check(await panel().count()===1&&await tab('Conversations').getAttribute('aria-selected')==='true','existing Profile event navigation opens Conversations in the same workspace without a header button');
  check(await page.locator('.chat-topbar-actions .chat-profile-activity-button').count()===0,'Profile reentry keeps the main header free of a specialist icon');
  await openThread();
  await panel().getByRole('button',{name:'Take over',exact:true}).click();
  await panel().getByRole('button',{name:'Prepare private draft',exact:true}).waitFor({state:'visible'});
  await page.waitForFunction(()=>[...document.querySelectorAll('#vp3ChatSpecialistActivity button')].some(button=>button.textContent==='Prepare private draft'&&!button.disabled));
  check((await panel().innerText()).includes('You are now responding'),'real canonical takeover enables the exact-thread private draft');
  mode='lose_prepare';await panel().getByRole('button',{name:'Prepare private draft',exact:true}).click();
  await panel().getByRole('button',{name:'Retry saved preparation',exact:true}).waitFor();
  check(await panel().getByRole('button',{name:'Prepare private draft',exact:true}).isDisabled(),'lost preparation response cannot start a second request');
  await panel().getByRole('button',{name:'Retry saved preparation',exact:true}).click();
  await page.waitForFunction(()=>document.querySelector('[data-profile-reply-body]')?.value==='Here is a private draft for this exact visitor.');
  const preparations=requests.filter(row=>row.payload.action==='draft_prepare');
  check(preparations.length===2&&JSON.stringify(preparations[0].payload)===JSON.stringify(preparations[1].payload),'uncertain preparation retries the unchanged request');
  check((await stored()).provider_dispatches.length===1,'actual saved preparation recovery performs one provider dispatch');
  await reply().fill('Owner reviewed and edited browser reply.');
  await contained();
  await page.screenshot({path:'artifacts/profile-specialist-workspace/desktop-conversation.png',fullPage:true});
  await tab('Analytics').click();
  await panel().locator('.vp3-profile-period').waitFor();
  await page.evaluate(ref=>VP3AgentActivityHub.openProfile({tab:'analytics',analyticsRef:ref}),server.seed.analytics.ref);
  await page.waitForFunction(ref=>document.querySelector('[data-profile-analytics-period]')?.dataset.profileAnalyticsPeriod===ref,server.seed.analytics.ref);
  check((await panel().innerText()).includes('Period timezone: UTC')&&await panel().locator('[data-profile-analytics-metric="visit_records"]').count()===1,
    'completed period uses actual native aggregate evidence and its fixed timezone');
  check(await panel().getByRole('heading',{name:'Completed day · 16 Nov 2026',exact:true}).count()===1
    &&(await panel().innerText()).includes('Previous period: 15 Nov 2026'),'completed-period headings show the selected and previous included calendar dates');
  const periodDetails=()=>panel().locator('[data-profile-period-details]');
  check(await periodDetails().evaluate(element=>!element.open),'exact period metadata starts collapsed');
  await readablePeriods();await contained();await panel().evaluate(element=>{element.scrollTop=0;});
  await page.screenshot({path:'artifacts/profile-specialist-workspace/profile-period-desktop.png',fullPage:true});
  const readsBeforeDetails=requests.length;
  await periodDetails().locator('summary').focus();await page.keyboard.press('Enter');
  check(await periodDetails().evaluate(element=>element.open)
    &&(await periodDetails().innerText()).includes('2026-11-16T00:00:00+00:00 to 2026-11-17T00:00:00+00:00')
    &&(await periodDetails().innerText()).includes('2026-11-15T00:00:00+00:00 to 2026-11-16T00:00:00+00:00')
    &&(await periodDetails().innerText()).includes('The start is included; the end is excluded.')
    &&(await periodDetails().innerText()).includes('Timezone source in this link: account choice.')
    &&(await periodDetails().innerText()).includes('Fixed cutoff:')&&(await periodDetails().innerText()).includes('Evidence checked:'),
    'keyboard-opened Period details retain exact offset bounds, inclusion rule, source, cutoff and evidence check');
  await page.keyboard.press('Enter');
  check(await periodDetails().evaluate(element=>!element.open)&&requests.length===readsBeforeDetails,'closing period details changes only presentation');
  await panel().getByRole('button',{name:'7 days',exact:true}).click();
  const seven=server.seed.analytics.period_options.find(option=>option.period===7).ref;
  await page.waitForFunction(ref=>document.querySelector('[data-profile-analytics-period]')?.dataset.profileAnalyticsPeriod===ref,seven);
  await panel().getByRole('button',{name:'Refresh Profile',exact:true}).click();
  await page.waitForFunction(ref=>document.querySelector('[data-profile-analytics-period]')?.dataset.profileAnalyticsPeriod===ref,seven);
  check(requests.filter(row=>row.payload.action==='analytics_period').at(-1).payload.ref===seven,'period length and Refresh preserve the same encoded cutoff and timezone');
  await panel().getByRole('button',{name:'Traffic details',exact:true}).click();
  await panel().locator('.vp3-analytics-shell').waitFor();
  check((await panel().innerText()).includes('Recorded conversion value'),'Analytics uses the mounted renderer with measured conversion labels');
  await readableAnalytics();
  await contained();
  await page.screenshot({path:'artifacts/profile-specialist-workspace/desktop-analytics.png',fullPage:true});
  await openThread();
  check(await reply().inputValue()==='Owner reviewed and edited browser reply.','owner-edited text survives same-scope tab switching');
  await panel().getByRole('button',{name:'Close',exact:true}).click();
  await page.locator('[data-chat-specialist="profile"]').click();
  await panel().locator('.vp3-profile-service').waitFor();await openThread();
  check(await reply().inputValue()==='Owner reviewed and edited browser reply.','Profile specialist entry reopens the same scoped edited draft');
  mode='lose_reply';await panel().getByRole('button',{name:'Send reply to visitor',exact:true}).click();
  await panel().getByRole('button',{name:'Retry previous action',exact:true}).waitFor();
  const committed=await stored();
  check(committed.profile_agent_messages.filter(row=>row.sender_type==='owner'&&Number(row.conversation_id)===server.seed.primary).length===1,'reply committed before the browser lost its receipt');
  await panel().getByRole('button',{name:'Close',exact:true}).click();
  await page.evaluate(()=>window.STONEFELLOW_PROFILE_ACTIVITY.open({tab:'conversations',conversationId:fixtureSeed.primary}));
  await panel().getByRole('button',{name:'Retry previous action',exact:true}).waitFor();
  await panel().getByRole('button',{name:'Retry previous action',exact:true}).click();
  await panel().getByText('Your reply was sent to this visitor.',{exact:true}).waitFor();
  const replies=requests.filter(row=>row.payload.action==='conversation_action'&&row.payload.operation==='reply');
  check(replies.length===2&&JSON.stringify(replies[0].payload)===JSON.stringify(replies[1].payload),'reopened reply recovery uses the same turn/revision/message/request ID');
  check((await stored()).profile_agent_messages.filter(row=>row.sender_type==='owner'&&Number(row.conversation_id)===server.seed.primary).length===1,'canonical response-loss retry leaves one public owner reply');

  mode='hostile';await panel().getByRole('button',{name:'Refresh Profile',exact:true}).click();
  await panel().getByText('Visitor <img src=x onerror="window.workspaceAttack=true"> asks about sizing.',{exact:true}).waitFor();
  check(await panel().locator('img,script').count()===0&&await page.evaluate(()=>window.workspaceAttack===undefined),'visitor content cannot create executable DOM');
  mode='wrong_scope';await panel().getByRole('button',{name:'Refresh Profile',exact:true}).click();
  await panel().getByText('The Profile response did not match this Chat.',{exact:true}).waitFor();
  check(await reply().count()===0,'mismatched scope response clears the previous conversation');
  mode='ready';await panel().getByRole('button',{name:'Retry',exact:true}).click();await reply().waitFor();

  await page.evaluate(()=>{window.nativeCardBefore=document.getElementById('fixtureNativeCall');window.nativeMediaBefore=nativeCardBefore.querySelector('[data-fixture-media]');});
  await page.evaluate(()=>window.STONEFELLOW_PROFILE_ACTIVITY.open({tab:'calls',callId:fixtureSeed.call_id,publicConversationId:fixtureSeed.call_conversation_id}));
  await panel().getByRole('button',{name:'Open call in Chat',exact:true}).waitFor();
  check((await panel().innerText()).includes('This is a direct call.'),'human call has an honest non-assisted activity state');
  const beforeCallActions=requests.filter(row=>row.method==='POST').length;
  await panel().getByRole('button',{name:'Open call in Chat',exact:true}).click();
  await panel().getByText('That live call card is not available in this Chat. The call has not been restarted.',{exact:true}).waitFor();
  check(requests.filter(row=>row.method==='POST').length===beforeCallActions,'missing exact native call card never triggers delivery, join or dispatch');
  await tab('Overview').click();await panel().locator('.vp3-profile-service').waitFor();
  await panel().getByRole('button',{name:'Close',exact:true}).click();
  check(await page.evaluate(()=>nativeCardBefore===document.getElementById('fixtureNativeCall')&&nativeMediaBefore===nativeCardBefore.querySelector('[data-fixture-media]')&&nativeMediaBefore.isConnected),'workspace lifecycle preserves the existing native card/media DOM');

  // Current/history navigation uses the real endpoint; terminal sections remain static.
  await page.locator('[data-chat-specialist="profile"]').click();await tab('Calls').click();
  await panel().getByRole('button',{name:'Call history',exact:true}).click();
  await panel().locator(`[data-profile-workspace-call="${server.seed.outcomes.assisted.call_id}"]`).getByRole('button',{name:'View outcome',exact:true}).click();
  const outcomeSection=key=>panel().locator(`[data-profile-outcome-section="${key}"]`);
  await outcomeSection('analysis').getByText('The saved call discussed public opening hours and an owner-reviewed follow-up.',{exact:true}).waitFor();
  const historyReads=requests.length,historyWrites=requests.filter(row=>row.method==='POST').length,historyDispatches=(await stored()).provider_dispatches.length;
  check(await panel().locator('[data-profile-outcome-section]').count()===4,'real terminal history renders analysis, transcript, research and reviewed follow-ups');
  check(await panel().getByRole('button',{name:'Call history',exact:true}).getAttribute('aria-pressed')==='true','selected outcome stays in the actual history filter');
  check(await outcomeSection('followups').locator('[data-profile-followup]').count()===20,'initial reviewed follow-ups are genuinely paged');
  await contained();await panel().evaluate(element=>{element.scrollTop=0;});
  await page.screenshot({path:'artifacts/profile-specialist-workspace/desktop-call-outcome.png',fullPage:true});
  await outcomeSection('transcript').locator('summary').click();
  await outcomeSection('transcript').getByRole('button',{name:'Load more transcript',exact:true}).click();
  await page.waitForFunction(()=>document.querySelectorAll('[data-profile-transcript-segment]').length===55);
  await outcomeSection('research').getByRole('button',{name:'Load more research',exact:true}).click();
  await page.waitForFunction(()=>document.querySelectorAll('[data-profile-research]').length===7);
  await outcomeSection('followups').getByRole('button',{name:'Load more follow-ups',exact:true}).click();
  await page.waitForFunction(()=>document.querySelectorAll('[data-profile-followup]').length===23);
  check(await panel().locator('[data-profile-transcript-segment]').count()===55&&await panel().locator('[data-profile-research]').count()===7,
    'browser appends every real stored transcript and research page');
  check(await panel().locator('[data-profile-followup]').count()===23,'browser retains every reviewed task page including completed and cancelled records');
  check(!requests.slice(historyReads).some(row=>row.payload.action==='call_activity'||row.method==='POST'),'terminal pagination does not start live activity or mutate the call');
  check((await stored()).provider_dispatches.length===historyDispatches,'outcome reads never dispatch an inference');
  await contained();
  await panel().getByRole('button',{name:'Refresh Profile',exact:true}).click();
  await page.waitForFunction(()=>document.querySelectorAll('[data-profile-transcript-segment]').length===50);
  await control('transcript_change');await outcomeSection('transcript').locator('summary').click();
  await outcomeSection('transcript').getByRole('button',{name:'Load more transcript',exact:true}).click();
  await panel().getByRole('button',{name:'Reload outcome',exact:true}).waitFor();
  check(await panel().locator('[data-profile-transcript-segment],[data-profile-followup]').count()===0,
    'a real source correction removes all accumulated outcome sections before reload');
  await panel().getByRole('button',{name:'Reload outcome',exact:true}).click();
  await outcomeSection('analysis').getByText('The saved analysis no longer matches the current transcript. Open the saved transcript to review it.',{exact:true}).waitFor();
  check(!(await outcomeSection('analysis').innerText()).includes('The saved call discussed'),'stale saved analysis is withheld in the browser');
  await control('analysis_refresh');await control('recording_revoke');
  await panel().getByRole('button',{name:'Refresh Profile',exact:true}).click();
  await outcomeSection('transcript').getByText('Your current access does not include this saved section.',{exact:true}).waitFor({state:'attached'});
  check((await panel().innerText()).includes('Call ended')&&await panel().locator('[data-profile-transcript-segment],[data-profile-followup]').count()===0,
    'recording denial keeps authorized call state and removes all recording-derived content');
  await control('recording_restore');await reload();
  await page.locator('[data-chat-specialist="profile"]').click();
  await panel().locator('.vp3-profile-service').waitFor();check(await tab('Overview').getAttribute('aria-selected')==='true','normal entry after reload still starts Overview');
  await tab('Calls').click();await outcomeSection('analysis').getByText('The saved call discussed public opening hours and an owner-reviewed follow-up.',{exact:true}).waitFor();
  check(await panel().getByRole('button',{name:'Call history',exact:true}).getAttribute('aria-pressed')==='true',
    'after reload, Calls restores the exact saved history selection and rereads its current evidence');
  await panel().getByRole('button',{name:'Back to calls',exact:true}).click();
  await panel().locator(`[data-profile-workspace-call="${server.seed.outcomes.ordinary.call_id}"]`).getByRole('button',{name:'View outcome',exact:true}).click();
  await outcomeSection('analysis').getByText('This section does not apply to this call.',{exact:true}).waitFor();
  check(await panel().locator('[data-profile-transcript-segment]').count()===0,'ordinary completed call does not invent assisted content');
  check(requests.filter(row=>row.method==='POST').length===historyWrites,'all completed-call navigation and section recovery remain read-only');
  await control('tasks_complete');
  await panel().getByRole('button',{name:'Close',exact:true}).click();

  await page.locator('[data-chat-specialist="profile"]').click();await openThread();
  held={started:defer(),release:defer()};const threadGate=held;mode='delay_thread';
  await panel().getByRole('button',{name:'Refresh Profile',exact:true}).click();await started(threadGate);
  await page.evaluate(()=>window.STONEFELLOW_PROFILE_ACTIVITY.open({tab:'conversations',conversationId:fixtureSeed.secondary}));
  await panel().getByText('Does the second product come in blue?',{exact:true}).waitFor();threadGate.release.resolve();
  await page.waitForTimeout(60);
  check(!(await body().innerText()).includes('STALE PRIVATE VISITOR CONVERSATION'),'delayed first-thread response cannot paint the second visitor');
  held={started:defer(),release:defer()};const agentGate=held;mode='delay_thread';
  await panel().getByRole('button',{name:'Refresh Profile',exact:true}).click();await started(agentGate);
  await page.evaluate(()=>{
    window.dispatchEvent(new Event('stonefellow:chat-context-changing'));fixtureScope.agent=8;STONEFELLOW_CHAT.agentId=8;
    window.dispatchEvent(new Event('vp3:chat-context-ready'));VP3AgentActivityHub.openProfile({tab:'conversations',publicConversationId:fixtureSeed.primary});
  });agentGate.release.resolve();
  await panel().getByText('This Chat’s Profile access could not be verified.',{exact:true}).waitFor();
  check(!(await body().innerText()).includes('STALE PRIVATE VISITOR CONVERSATION'),'private Agent switch discards delayed source data');

  await page.setViewportSize({width:390,height:844});await reload();await openMind();
  await page.locator('[data-top-of-mind-profile-report]').click();
  await panel().locator('[data-profile-report]').waitFor();
  check(await tab('Reports').getAttribute('aria-selected')==='true','compact Top of Mind report action selects Reports in the shared shell');
  check(JSON.stringify(await panel().locator('[data-profile-report-section] h4').allTextContents())===JSON.stringify(['Key findings','Actions & follow-ups','Open questions','Next step','Questions for you']),'Reports retains the shared five-section projection');
  await contained();await page.screenshot({path:'artifacts/profile-specialist-workspace/compact-reports.png',fullPage:true});
  const visitGroup=panel().locator('[data-profile-visit-group]');
  await visitGroup.getByText('2 visit records in this checked view',{exact:true}).waitFor();
  const beforeVisitActions=requests.filter(row=>row.method==='POST').length;
  await visitGroup.locator('summary').click();
  const visitLinks=await visitGroup.locator('[data-profile-source]').evaluateAll(links=>links.map(link=>({ref:link.dataset.profileSource,revision:link.dataset.profileSourceRevision,owner:link.dataset.profilePresentationOwner})));
  check(visitLinks.length===2&&visitLinks.every(link=>fixtureVisitRefs.includes(link.ref)&&/^[a-f0-9]{64}$/.test(link.revision)&&link.owner==='profile_attention_pa4b'),
    'compact routine group retains each current native source and presentation proof');
  check(requests.filter(row=>row.method==='POST').length===beforeVisitActions,'expanding routine visits never acknowledges or resolves them');
  await contained();await page.screenshot({path:'artifacts/profile-specialist-workspace/compact-visit-group.png',fullPage:true});

  await tab('Conversations').focus();await page.keyboard.press('ArrowLeft');
  check(await tab('Overview').getAttribute('aria-selected')==='true','ArrowLeft selects the preceding workspace tab');
  await page.keyboard.press('End');await panel().locator('[data-profile-report]').waitFor();
  check(await tab('Reports').getAttribute('aria-selected')==='true','End selects the final tab with its current report');
  await page.evaluate(()=>window.STONEFELLOW_PROFILE_ACTIVITY.open({tab:'calls',callView:'history',callId:fixtureSeed.outcomes.assisted.call_id,publicConversationId:fixtureSeed.outcomes.assisted.public_conversation_id}));
  await outcomeSection('analysis').getByText('The saved call discussed public opening hours and an owner-reviewed follow-up.',{exact:true}).waitFor();
  check(await panel().getByRole('button',{name:'Call history',exact:true}).getAttribute('aria-pressed')==='true','legacy outcome bridge preserves the exact history selection');
  const outcomeFonts=await panel().locator('.vp3-profile-call-detail :is(p,small,dt,dd,summary,a,strong)').evaluateAll(nodes=>nodes.filter(node=>node.textContent.trim()&&node.getClientRects().length&&parseFloat(getComputedStyle(node).fontSize)<12).map(node=>({tag:node.tagName,text:node.textContent.slice(0,70),font:getComputedStyle(node).fontSize})));
  check(outcomeFonts.length===0,'compact saved outcome text stays readable: '+JSON.stringify(outcomeFonts));
  await contained();await panel().evaluate(element=>{element.scrollTop=0;});await page.screenshot({path:'artifacts/profile-specialist-workspace/compact-call-outcome.png',fullPage:true});
  await tab('Analytics').click();await panel().getByRole('button',{name:'Completed periods',exact:true}).click();
  await panel().locator('.vp3-profile-period').waitFor();await readablePeriods();await contained();
  await panel().evaluate(element=>{element.scrollTop=0;});
  const firstPeriodValue=await panel().locator('[data-profile-analytics-metric="visit_records"] strong').first().boundingBox();
  const compactPanel=await panel().boundingBox();
  check(firstPeriodValue&&compactPanel&&firstPeriodValue.y+firstPeriodValue.height<=compactPanel.y+compactPanel.height-8,
    'compact completed periods show the first metric value before scrolling: '+JSON.stringify({firstPeriodValue,compactPanel}));
  await page.screenshot({path:'artifacts/profile-specialist-workspace/profile-period-compact.png',fullPage:true});
  await periodDetails().locator('summary').focus();await page.keyboard.press('Enter');
  check(await periodDetails().evaluate(element=>element.open)&&(await periodDetails().innerText()).includes('Fixed cutoff:'),
    'compact Period details remain keyboard-accessible');
  await readablePeriods();await contained();await panel().evaluate(element=>{element.scrollTop=0;});
  await page.screenshot({path:'artifacts/profile-specialist-workspace/profile-period-details-compact.png',fullPage:true});
  await page.keyboard.press('Enter');
  await panel().getByRole('button',{name:'Traffic details',exact:true}).click();await panel().locator('.vp3-analytics-shell').waitFor();await readableAnalytics();await contained();
  await page.screenshot({path:'artifacts/profile-specialist-workspace/compact-analytics.png',fullPage:true});
  await page.keyboard.press('Escape');
  check(await panel().count()===0&&await page.evaluate(()=>document.activeElement?.id==='chatInput'),'Escape closes one shell and restores the Chat composer focus');
  await page.locator('[data-top-of-mind-profile-report]').click();await panel().locator('[data-profile-report]').waitFor();
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:9}})));
  check(await panel().count()===0,'participant selection clears the private owner workspace');
  check(await page.evaluate(()=>VP3AgentActivityHub.openProfile({tab:'overview'}))===false,'participant context cannot reopen owner Profile data');
  check(requests.every(row=>!['owner_state','owner_feed','delivery','dispatch','join'].includes(row.payload.action)),'workspace creates no competing inbox, delivery or call worker');
  check(server.readWriteFailure()==='','all browser GETs preserve storage and do not dispatch inference');
  assert.deepEqual(errors,[],'browser runtime has no page errors');
  await profileAnalyticsRouteBrowserV1(browser,server,check);
  check(server.readWriteFailure()==='','real Chat destination reads preserve storage and never dispatch inference');
  console.log(`PROFILE_SPECIALIST_WORKSPACE_BROWSER=PASS (${checks} checks)`);
}catch(error){
  if(page&&!page.isClosed()){
    try{
      await mkdir('artifacts/profile-specialist-workspace',{recursive:true});
      await page.screenshot({path:'artifacts/profile-specialist-workspace/failure.png',fullPage:true,timeout:5000});
    }catch(screenshotError){console.error('Failure screenshot unavailable:',screenshotError.message);}
  }
  console.error(server.logs());throw error;
}
finally{
  held?.release.resolve();for(const gate of gates)gate.release.resolve();
  await browser?.close();await server.close();
}
