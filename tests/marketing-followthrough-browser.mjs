import assert from 'node:assert/strict';
import {mkdir} from 'node:fs/promises';
import {chromium} from 'playwright';
import {startProfileWorkspaceServer} from './fixtures/profile-specialist-workspace-server.mjs';

// Hosted acceptance: unchanged Chat runtime, production destination helper/API and real isolated SQL.
// Only the Chat message transport is simulated; report and plan requests reach the real endpoints.
const server=await startProfileWorkspaceServer({marketing:true});
let browser=null,systemServer=null,checks=0,scenarioNumber=0;
const check=(truth,label)=>{assert.ok(truth,label);checks++;};
const deferred=()=>{let resolve;const promise=new Promise(done=>resolve=done);return {promise,resolve};};
const arrived=async gate=>{let timer;try{await Promise.race([gate.started.promise,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('The expected delayed request did not start.')),15000);})]);}finally{clearTimeout(timer);}};

async function scenario(options,run){
  const fixture=options.server||server;
  const context=await browser.newContext({viewport:options.compact?{width:390,height:844}:{width:1280,height:960}});
  const page=await context.newPage();page.setDefaultTimeout(15000);
  const errors=[],requests=[],sends=[],gates=new Set(),state={chatGate:null,reportGate:null};
  const gate=()=>{const held={started:deferred(),release:deferred()};gates.add(held);return held;};
  const dialog=()=>page.locator('#vp3MediaCommands[data-media-command="marketing"]');
  const fixed=()=>dialog().locator('[data-marketing-fixed-ref]');
  const profile=()=>page.locator('#vp3ChatSpecialistActivity');
  const number=++scenarioNumber;
  page.on('pageerror',error=>errors.push(error.message));
  page.on('request',request=>{
    const url=new URL(request.url());
    if(/\/api\/(marketing-followthrough-v1|initiative-workbench-v1|profile-specialist-workspace-v1)\.php$/.test(url.pathname)){
      requests.push({path:url.pathname,method:request.method(),payload:request.method()==='POST'?request.postDataJSON():Object.fromEntries(url.searchParams)});
    }
  });
  await context.route('**/api/chat-v236.php**',async route=>{
    const input=route.request().postDataJSON()||{};
    if(input.action==='send')sends.push(input);
    const held=input.action==='load'?state.chatGate:null;
    if(held){state.chatGate=null;held.started.resolve();try{await held.release.promise;}finally{gates.delete(held);}}
    try{await route.fulfill({json:{ok:true,messages:input.action==='load'?[{id:1,role:'assistant',message:'Restored exact private Chat.'}]:[],
      conversations:[],updates:[],unread_count:0,latest_id:0,latest_message_id:1}});}
    catch(error){if(!/closed|disposed|cancel|abort/i.test(error.message))throw error;}
  });
  await context.route('**/api/marketing-followthrough-v1.php**',async route=>{
    const response=await route.fetch();
    const held=new URL(route.request().url()).searchParams.get('action')==='report'?state.reportGate:null;
    if(held){state.reportGate=null;held.started.resolve();try{await held.release.promise;}finally{gates.delete(held);}}
    try{await route.fulfill({response});}catch(error){if(!/closed|disposed|cancel|abort/i.test(error.message))throw error;}
  });
  try{
    await context.request.get(fixture.base+'/session');
    const reset=await context.request.post(fixture.base+'/fixture/reset');assert.equal(reset.status(),200);const seed=await reset.json();
    if(options.revokeBeforePage){const revoked=await context.request.post(fixture.base+'/fixture/control',{data:{action:'revoke'}});assert.equal(revoked.status(),200);}
    const base=options.base||'',agent=options.system?'system':'7',cid=options.system?seed.system_chat:11,ref=seed.followthrough.ref;
    const url=new URL(fixture.base+base+'/chat.php');url.searchParams.set('agent',options.wrongAgent?'999999':agent);
    url.searchParams.set('conversation_id',String(options.wrongChat?999999:cid));
    if(options.fixed!==false)url.searchParams.set('marketing_followthrough',options.malformed?'marketing:ft:invalid':ref);
    if(options.duplicate)url.searchParams.append('agent',agent);
    if(options.competing)url.searchParams.set('profile_analytics',seed.followthrough.period_ref);
    state.chatGate=gate();const first=state.chatGate;
    await page.goto(url.href,{waitUntil:'domcontentloaded'});await arrived(first);
    await page.waitForFunction(()=>window.VP3MediaCommands?.openFollowthrough&&window.VP3MarketingFollowthroughV1&&window.STONEFELLOW_CHAT_CONTINUITY?.isRestoring());
    const instrument=()=>page.evaluate(()=>{
      const original=window.VP3MediaCommands;window.fixtureMarketingOpens=[];
      window.VP3MediaCommands={...original,openFollowthrough(selection,bound){window.fixtureMarketingOpens.push(selection);return original.openFollowthrough(selection,bound);}};
    });
    await instrument();
    const ready=async held=>{held.release.resolve();await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY?.isReady()&&!window.STONEFELLOW_CHAT_CONTINUITY.isRestoring());await page.evaluate(()=>new Promise(requestAnimationFrame));};
    const f={context,page,dialog,fixed,profile,seed,ref,cid,requests,sends,state,gate,base,
      release:()=>ready(first),
      async reload(){state.chatGate=gate();const held=state.chatGate;await page.reload({waitUntil:'domcontentloaded'});await arrived(held);
        await page.waitForFunction(()=>window.VP3MediaCommands?.openFollowthrough);await instrument();await ready(held);},
      async control(action){const response=await context.request.post(fixture.base+'/fixture/control',{data:{action}});assert.equal(response.status(),200,await response.text());},
      repeats:()=>page.evaluate(()=>{window.dispatchEvent(new Event('vp3:chat-context-ready'));window.dispatchEvent(new Event('vp3:media-commands-ready'));}),
      async command(method='Enter'){
        await page.locator('#chatInput').fill('/marketing');
        check(await dialog().count()===0,'typing /marketing alone leaves the composer and navigation unchanged');
        if(method==='Enter')await page.locator('#chatInput').press('Enter');else await page.getByRole('button',{name:'Send',exact:true}).click();
        await dialog().locator(`[data-media-plan="${seed.followthrough.ids.product}"]`).waitFor();
      },
      async openOriginal(){
        await dialog().locator(`[data-media-plan-followthrough="${seed.followthrough.ids.product}"]`).click();
        await dialog().getByRole('heading',{name:'Choose a publication',exact:true}).waitFor();
        await dialog().locator(`[data-marketing-publication="${seed.followthrough.publication_id}"]`).getByRole('button',{name:'View follow-through',exact:true}).click();
        await fixed().waitFor();await this.openSaved();
      },
      async openSaved(){
        const opened=await page.evaluate(({ref,base,agent,cid})=>window.VP3MarketingFollowthroughV1.openSource({ref:ref.replace(/^marketing:/,'profile:'),
          href:base+'/chat.php?'+new URLSearchParams({agent,conversation_id:String(cid),marketing_followthrough:ref})}),{ref,base,agent,cid});
        check(opened===true,'same-private-Chat Profile alias opens the exact canonical Marketing report inline');
        await page.waitForFunction(ref=>document.querySelector('[data-marketing-fixed-ref]')?.dataset.marketingFixedRef===ref,ref);
      },
      async top(){await dialog().evaluate(element=>{element.scrollTop=0;});},
      async contained(){
        const bounds=await dialog().boundingBox(),viewport=page.viewportSize();
        check(bounds&&bounds.x>=0&&bounds.y>=0&&bounds.x+bounds.width<=viewport.width+1&&bounds.y+bounds.height<=viewport.height+1,'existing Marketing dialog remains inside the viewport');
        check(await dialog().evaluate(element=>element.scrollWidth<=element.clientWidth+1),'Marketing follow-through has no horizontal overflow');
        const nested=await dialog().evaluate(element=>[...element.querySelectorAll('*')].filter(node=>node.getClientRects().length&&['auto','scroll'].includes(getComputedStyle(node).overflowY)&&node.scrollHeight>node.clientHeight+1)
          .map(node=>({tag:node.tagName,className:node.className})));
        check(nested.length===0,'follow-through uses the dialog’s one vertical scroll region: '+JSON.stringify(nested));
        const offenders=await dialog().locator('.vp3-marketing-followthrough :is(p,span,small,button,th,td,a,h3,h4,summary,dt,dd)')
          .evaluateAll(nodes=>nodes.filter(node=>node.textContent.trim()&&node.getClientRects().length&&parseFloat(getComputedStyle(node).fontSize)<12)
            .map(node=>({tag:node.tagName,className:node.className,fontSize:getComputedStyle(node).fontSize,text:node.textContent.trim().slice(0,80)})));
        check(offenders.length===0,'fixed-period explanations and metrics are readable at least12px: '+JSON.stringify(offenders));
      }
    };
    await run(f);
    check(sends.length===0,'Marketing commands and exact report navigation never send a Chat prompt');
    check(fixture.readWriteFailure()==='','actual report reads do not write or dispatch inference');
    assert.deepEqual(errors,[],'real Chat and Marketing runtime has no page errors');
  }catch(error){
    try{await page.screenshot({path:'artifacts/marketing-followthrough/failure-'+number+'.png',fullPage:true,timeout:5000});}catch{}
    console.error(fixture.logs());throw error;
  }finally{
    for(const held of gates)held.release.resolve();state.chatGate?.release.resolve();state.reportGate?.release.resolve();
    await context.close();
  }
}

try{
  await mkdir('artifacts/marketing-followthrough',{recursive:true});
  browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
  await scenario({fixed:false},async f=>{
    await f.release();await f.command();
    check(await f.page.locator('#chatInput').inputValue()===''&&await f.dialog().count()===1,'Enter opens one existing Marketing dialog and consumes only the recognized command');
    await f.dialog().locator(`[data-media-plan-followthrough="${f.seed.followthrough.ids.product}"]`).click();
    await f.dialog().getByRole('heading',{name:'Choose a publication',exact:true}).waitFor();
    check(await f.dialog().locator('[data-marketing-publication]').count()===2,'publication picker keeps historical and current publications separate');
    check(!f.requests.some(row=>row.payload.action==='report'),'opening publication choices never silently chooses the current creative');
    await f.top();await f.contained();await f.page.screenshot({path:'artifacts/marketing-followthrough/desktop-publications.png',fullPage:true});
    await f.dialog().locator(`[data-marketing-publication="${f.seed.followthrough.publication_id}"]`).getByRole('button',{name:'View follow-through',exact:true}).click();
    await f.fixed().waitFor();
    check((await f.fixed().getAttribute('data-marketing-fixed-ref')).split(':')[3]===f.seed.followthrough.publication_id,'explicit publication selection retains the selected original publication');
    await f.openSaved();
    check(await f.dialog().locator('[data-marketing-metric]').count()===7&&await f.dialog().locator('[data-marketing-exchange]').count()===1,'actual collector renders seven measures and one proof-backed public exchange');
    check((await f.dialog().innerText()).includes('Original T-shirt promotion')&&(await f.dialog().innerText()).includes('USD 20.00'),'historical publication title and exact currency amount reach the visible report');
    check(!f.requests.some(row=>row.payload.action==='creative_open'||row.payload.action==='marketing_plan'),'reading follow-through does not open a creative or change the current plan');
    await f.top();await f.contained();await f.page.screenshot({path:'artifacts/marketing-followthrough/desktop-report.png',fullPage:true});
    const originalRef=await f.fixed().getAttribute('data-marketing-fixed-ref');
    await f.dialog().getByRole('button',{name:'Refresh this period',exact:true}).click();await f.fixed().waitFor();
    check(f.requests.filter(row=>row.payload.action==='report').at(-1).payload.ref===originalRef,'Refresh rereads the exact saved cutoff and publication');
    await f.dialog().getByRole('button',{name:'7 days',exact:true}).click();await f.fixed().waitFor();
    const siblingRef=await f.fixed().getAttribute('data-marketing-fixed-ref');
    check(siblingRef.split(':')[3]===originalRef.split(':')[3]&&siblingRef.split(':').slice(5).join(':')===originalRef.split(':').slice(5).join(':'),'period siblings preserve publication, cutoff and timezone context');
    await f.control('timezone_change');await f.dialog().getByRole('button',{name:'Latest completed periods',exact:true}).click();await f.fixed().waitFor();
    check((await f.dialog().innerText()).includes('Period timezone: Asia/Tokyo')&&f.requests.filter(row=>row.payload.action==='report').at(-1).payload.publication_id===f.seed.followthrough.publication_id,'explicit Latest resolves current settings for the selected historical publication');
    await f.dialog().getByRole('button',{name:'Back to plans',exact:true}).click();await f.dialog().locator(`[data-media-plan="${f.seed.followthrough.ids.product}"]`).waitFor();
    check(await f.dialog().count()===1&&await f.dialog().locator('[data-marketing-fixed-ref]').count()===0,'Back to plans rereads the existing plan list within the same dialog');
    await f.dialog().getByRole('button',{name:'Close',exact:true}).click();await f.command('Send');await f.openOriginal();
    check(await f.dialog().count()===1,'Send activates the same /marketing flow after close');
    await f.page.evaluate(()=>{const workbench=window.VP3InitiativeWorkbench;window.fixtureCreativeSelections=[];window.VP3InitiativeWorkbench={...workbench,openProductLaunch(marker){window.fixtureCreativeSelections.push(marker);return false;}};});
    await f.dialog().getByRole('button',{name:'Review original creative',exact:true}).click();
    const creative=await f.page.evaluate(()=>window.fixtureCreativeSelections[0]);
    check(creative.creative_id===f.seed.followthrough.ids.original_creative&&creative.draft_id===f.seed.followthrough.ids.draft,'explicit original review targets the historical draft even outside the recent draft list');
    await f.dialog().getByRole('button',{name:'Open current plan',exact:true}).click();
    await f.dialog().getByText('This plan needs attention before it can be continued. Its saved content has been kept.',{exact:true}).waitFor();
    check(f.requests.filter(row=>row.payload.action==='marketing_plan').at(-1).payload.product_id===f.seed.followthrough.ids.product,'current-plan action performs its independent fresh plan read');
    check(await f.page.evaluate(()=>window.fixtureCreativeSelections.length)===1,'unavailable current plan does not substitute the historical creative');
    // The isolated SQL fixture intentionally has no image bytes; exact launch bytes are covered by the retained launch suite.
    await f.dialog().getByRole('button',{name:'Open visitor conversation',exact:true}).click();
    await f.profile().locator('[data-profile-reply-body]').waitFor();
    check(await f.dialog().count()===0&&await f.profile().getByRole('tab',{name:'Conversations',exact:true}).getAttribute('aria-selected')==='true','verified exchange opens the existing Profile workspace, without a second Marketing dialog');
    check(f.requests.some(row=>row.path.endsWith('/api/profile-specialist-workspace-v1.php')&&row.payload.action==='conversation'&&row.payload.public_conversation_id===String(f.seed.followthrough.ids.public_conversation)),'Profile reads the exact linked public conversation under the unchanged original private Chat');
    check(await f.page.locator('#chatThread').getAttribute('data-view')==='chat','report-to-conversation navigation preserves the Chat canvas');
  });
  await scenario({},async f=>{
    check(await f.dialog().count()===0&&!f.requests.some(row=>row.payload.action==='report'),'saved report waits for the real delayed initial restoration');
    await f.release();await f.fixed().waitFor();
    check(await f.fixed().getAttribute('data-marketing-fixed-ref')===f.ref&&await f.page.evaluate(()=>window.fixtureMarketingOpens.length)===1,'exact original private Chat opens its fixed report once');
    await f.repeats();check(await f.dialog().count()===1&&await f.page.evaluate(()=>window.fixtureMarketingOpens.length)===1,'repeated readiness retains one dialog and one consumed destination');
    await f.dialog().getByRole('button',{name:'Close',exact:true}).click();await f.repeats();check(await f.dialog().count()===0,'closing a consumed saved link prevents late reopening');
    check(new URL(f.page.url()).searchParams.get('marketing_followthrough')===f.ref,'same-scope saved link remains reloadable');
    await f.control('timezone_change');await f.reload();await f.fixed().waitFor();
    check(await f.fixed().getAttribute('data-marketing-fixed-ref')===f.ref&&(await f.dialog().innerText()).includes('Period timezone: UTC'),'reload preserves encoded period context after account timezone changes');
    check(f.requests.filter(row=>row.payload.action==='report').every(row=>row.payload.ref===f.ref),'saved-link rereads never resolve a rolling replacement');
    await f.top();await f.page.screenshot({path:'artifacts/marketing-followthrough/desktop-fixed-link.png',fullPage:true});
  });
  await scenario({compact:true,base:'/nested/app'},async f=>{
    await f.release();await f.fixed().waitFor();await f.top();await f.contained();
    const firstMetric=await f.dialog().locator('[data-marketing-metric]').first().boundingBox();
    check(firstMetric&&firstMetric.y+firstMetric.height<f.page.viewportSize().height,'compact overview shows its first measure before requiring a scroll');
    await f.page.screenshot({path:'artifacts/marketing-followthrough/compact-report.png',fullPage:true});
    const details=f.dialog().locator('[data-marketing-period-details]');
    check(await details.getAttribute('open')===null,'exact timestamp details start collapsed');
    await details.locator('summary').focus();await f.page.keyboard.press('Enter');
    check(await details.getAttribute('open')!==null&&(await details.innerText()).includes('Fixed cutoff:')&&(await details.innerText()).includes('Selected period:'),'keyboard disclosure retains exact cutoff and offset range');
    const link=await details.locator('[data-marketing-fixed-link]').getAttribute('href');
    check(link.startsWith('/nested/app/chat.php?')&&new URL(link,server.base).searchParams.get('marketing_followthrough')===f.ref,'fixed link retains deployment base and exact canonical Marketing reference');
    await f.contained();await f.top();await f.page.screenshot({path:'artifacts/marketing-followthrough/compact-period-details.png',fullPage:true});
    await details.locator('summary').focus();await f.page.keyboard.press('Enter');
    await f.dialog().getByRole('button',{name:'Back to plans',exact:true}).click();await f.dialog().locator(`[data-media-plan="${f.seed.followthrough.ids.product}"]`).waitFor();
    await f.dialog().getByRole('button',{name:'Close',exact:true}).click();await f.command();
    await f.page.keyboard.press('Escape');check(await f.dialog().count()===0&&await f.page.locator('#chatInput').evaluate(element=>document.activeElement===element),'Escape closes the command dialog and restores the original composer focus');
  });
  await scenario({},async f=>{
    await f.page.getByRole('button',{name:'New Chat',exact:true}).click();await f.release();await f.repeats();
    check(await f.page.evaluate(()=>STONEFELLOW_CHAT_CONTINUITY.conversationId())===0&&await f.dialog().count()===0,'real New Chat before restoration cancels the old publication destination');
    check(!new URL(f.page.url()).searchParams.has('marketing_followthrough')&&!f.requests.some(row=>row.payload.action==='report'),'scope change removes the old fixed hint without reading it in a fallback Chat');
  });
  for(const options of [{duplicate:true},{malformed:true},{wrongAgent:true},{wrongChat:true},{competing:true}]){
    await scenario(options,async f=>{
      check(await f.page.evaluate(()=>STONEFELLOW_CHAT.marketingFollowthroughDestination)===null,'actual original-query helper rejects ambiguous, malformed or fallback tuple');
      await f.release();await f.repeats();
      check(await f.dialog().count()===0&&!f.requests.some(row=>row.payload.action==='report'),'rejected original route never opens a publication in an authorized fallback Chat');
      check(!new URL(f.page.url()).searchParams.has('marketing_followthrough'),'rejected Marketing hint does not survive real Chat URL normalization');
    });
  }
  await scenario({},async f=>{
    f.state.reportGate=f.gate();const held=f.state.reportGate;await f.release();await arrived(held);
    await f.dialog().getByRole('button',{name:'Close',exact:true}).click();await f.page.getByRole('button',{name:'New Chat',exact:true}).click();
    held.release.resolve();await f.repeats();await f.page.evaluate(()=>new Promise(requestAnimationFrame));
    check(await f.dialog().count()===0&&await f.page.locator('[data-marketing-fixed-ref]').count()===0,'a late successful report cannot reappear after Close and a real Chat switch');
  });
  await scenario({},async f=>{
    await f.release();await f.fixed().waitFor();await f.control('followthrough_legacy_click');
    await f.dialog().getByRole('button',{name:'Refresh this period',exact:true}).click();await f.fixed().waitFor();
    check((await f.dialog().locator('[data-marketing-metric="conversation_attributed_clicks"]').innerText()).includes('partial'),'legacy missing conversation attribution renders a verified partial subset');
    await f.control('revoke');await f.dialog().getByRole('button',{name:'Refresh this period',exact:true}).click();
    await f.dialog().getByText('Your session or access changed. Return to plans to check current access.',{exact:true}).waitFor();
    check(await f.fixed().count()===0&&!(await f.dialog().innerText()).includes('USD '),'actual Profile permission loss clears every prior report fact');
    await f.dialog().getByRole('button',{name:'Back to plans',exact:true}).click();await f.dialog().locator(`[data-media-plan="${f.seed.followthrough.ids.product}"]`).waitFor();
    check((await f.dialog().innerText()).includes('Marketing plans'),'private existing plan list remains available under its separate current authority');
  });
  await scenario({fixed:false,revokeBeforePage:true},async f=>{
    await f.release();
    check(await f.page.evaluate(()=>Boolean(window.VP3FixedPeriodToolsV1)&&window.VP3ProfileWorkspaceV1===undefined&&window.VP3_PROFILE_WORKSPACE_V1===undefined),'fresh no-Profile-grant page initializes only pure fixed-period tools');
    await f.command();await f.dialog().locator(`[data-media-plan-followthrough="${f.seed.followthrough.ids.product}"]`).click();
    await f.dialog().getByRole('heading',{name:'Choose a publication',exact:true}).waitFor();
    check(await f.dialog().locator('[data-marketing-publication]').count()===2,'actual no-Profile-grant browser can choose privately owned publications');
    await f.dialog().locator(`[data-marketing-publication="${f.seed.followthrough.publication_id}"]`).getByRole('button',{name:'View follow-through',exact:true}).click();
    await f.dialog().getByText('Your session or access changed. Return to plans to check current access.',{exact:true}).waitFor();
    check(await f.fixed().count()===0,'private inventory access does not expose the separately authorized full report');
    await f.dialog().getByRole('button',{name:'Back to plans',exact:true}).click();await f.dialog().locator(`[data-media-plan="${f.seed.followthrough.ids.product}"]`).waitFor();
  });
  systemServer=await startProfileWorkspaceServer({marketing:true,marketingAgent:0});
  await scenario({server:systemServer,system:true,base:'/nested/app',compact:true},async f=>{
    await f.release();await f.fixed().waitFor();
    check(await f.page.evaluate(()=>STONEFELLOW_CHAT_CONTINUITY.agentId())===0&&await f.page.evaluate(()=>STONEFELLOW_CHAT_CONTINUITY.conversationId())===f.cid,'system Agent restores the exact original private Chat');
    check(f.requests.filter(row=>row.payload.action==='report').every(row=>row.payload.chat_agent_id==='0'&&row.payload.chat_conversation_id===String(f.cid)),'system report reads preserve numeric0 and the selected private Chat');
    const link=await f.dialog().locator('[data-marketing-fixed-link]').getAttribute('href');
    check(link.startsWith('/nested/app/chat.php?')&&new URL(link,systemServer.base).searchParams.get('agent')==='system','system report source link is base-aware and canonical');
    await f.top();await f.contained();await f.page.screenshot({path:'artifacts/marketing-followthrough/compact-system-fixed-link.png',fullPage:true});
  });
  console.log('MARKETING_FOLLOWTHROUGH_BROWSER=PASS ('+checks+' checks; actual dialog, Chat restoration, SQL/API and scoped source navigation)');
}finally{await browser?.close();await systemServer?.close();await server.close();}
