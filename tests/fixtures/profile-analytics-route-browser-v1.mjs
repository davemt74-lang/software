import assert from 'node:assert/strict';

/** Real Chat restoration + production destination helper/workspace API; only Chat transport is simulated. */
export async function profileAnalyticsRouteBrowserV1(browser,server,check){
  const deferred=()=>{let resolve;const promise=new Promise(done=>resolve=done);return {promise,resolve};};
  async function arrived(gate){
    let timer;
    try{await Promise.race([gate.started.promise,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('Real Chat restoration did not reach its delayed transport.')),15000);})]);}
    finally{clearTimeout(timer);}
  }
  async function scenario(options,run){
    const context=await browser.newContext({viewport:options.compact?{width:390,height:844}:{width:1280,height:960}});
    const page=await context.newPage();page.setDefaultTimeout(15000);
    const errors=[],reads=[],sends=[],gates=new Set(),state={hold:null};
    const gate=()=>{const value={started:deferred(),release:deferred()};gates.add(value);return value;};
    const panel=()=>page.locator('#vp3ChatSpecialistActivity');
    page.on('pageerror',error=>errors.push(error.message));
    page.on('request',request=>{
      const url=new URL(request.url());
      if(url.pathname.endsWith('/api/profile-specialist-workspace-v1.php')){
        reads.push({method:request.method(),payload:request.method()==='POST'?request.postDataJSON():Object.fromEntries(url.searchParams)});
      }
    });
    await context.route('**/api/chat-v236.php**',async route=>{
      const input=route.request().postDataJSON()||{};
      if(input.action==='send')sends.push(input);
      const held=input.action==='load'?state.hold:null;
      if(held){
        state.hold=null;held.started.resolve();
        try{await held.release.promise;}finally{gates.delete(held);}
      }
      const messages=input.action==='load'?[{id:1,role:'assistant',message:'Restored exact private Chat.'}]:[];
      try{await route.fulfill({json:{ok:true,messages,conversations:[],updates:[],unread_count:0,latest_id:0,latest_message_id:1}});}
      catch(error){if(!/closed|disposed|cancel|abort/i.test(error.message))throw error;}
    });
    try{
      await context.request.get(server.base+'/session');
      const reset=await context.request.post(server.base+'/fixture/reset');assert.equal(reset.status(),200);const seed=await reset.json();
      const base=options.base||'',agent=options.system?'system':'7',cid=options.system?seed.system_chat:11;
      const ref=seed.analytics.ref,url=new URL(server.base+base+'/chat.php');
      url.searchParams.set('agent',options.wrongAgent?'999999':agent);
      url.searchParams.set('conversation_id',String(options.wrongChat?999999:cid));
      url.searchParams.set('profile_analytics',options.malformed?'profile:analytics:invalid':ref);
      if(options.duplicate)url.searchParams.append('agent',agent);
      state.hold=gate();const first=state.hold;
      await page.goto(url.href,{waitUntil:'domcontentloaded'});await arrived(first);
      await page.waitForFunction(()=>window.VP3AgentActivityHub?.openProfile&&window.VP3ProfileWorkspaceV1
        &&window.STONEFELLOW_CHAT_CONTINUITY?.isRestoring());
      const instrument=()=>page.evaluate(()=>{
        const original=window.VP3AgentActivityHub;window.fixtureAnalyticsOpens=[];
        window.VP3AgentActivityHub={...original,openProfile(selection,trigger){
          window.fixtureAnalyticsOpens.push(selection);return original.openProfile(selection,trigger);
        }};
      });
      await instrument();
      const ready=async held=>{
        held.release.resolve();
        await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY?.isReady()&&!window.STONEFELLOW_CHAT_CONTINUITY.isRestoring());
        await page.evaluate(()=>new Promise(requestAnimationFrame));
      };
      const f={page,panel,seed,ref,cid,reads,sends,state,
        release:()=>ready(first),
        async reload(){state.hold=gate();const held=state.hold;await page.reload({waitUntil:'domcontentloaded'});await arrived(held);
          await page.waitForFunction(()=>window.VP3AgentActivityHub?.openProfile&&window.VP3ProfileWorkspaceV1);await instrument();await ready(held);},
        async control(action){const response=await context.request.post(server.base+'/fixture/control',{data:{action}});assert.equal(response.status(),200,await response.text());},
        repeats:()=>page.evaluate(()=>{window.dispatchEvent(new Event('vp3:chat-context-ready'));window.dispatchEvent(new Event('vp3:agent-activity-ready'));})
      };
      await run(f);
      check(sends.length===0,'fixed Analytics navigation never sends a Chat prompt');
      assert.deepEqual(errors,[],'real Chat route runtime has no page errors');
    }catch(error){
      try{await page.screenshot({path:'artifacts/profile-specialist-workspace/profile-period-route-failure.png',fullPage:true,timeout:5000});}catch{}
      throw error;
    }finally{
      for(const held of gates)held.release.resolve();
      state.hold?.release.resolve();
      await context.close();
    }
  }
  await scenario({},async f=>{
    check(await f.panel().count()===0&&f.reads.every(row=>row.payload.action!=='analytics_period'),'exact destination waits for real initial restoration');
    await f.release();await f.panel().locator('[data-profile-analytics-period]').waitFor();
    check(await f.page.evaluate(()=>window.fixtureAnalyticsOpens.length)===1,'one exact destination opens after initialRestore completes');
    check(await f.panel().locator('[data-profile-analytics-period]').getAttribute('data-profile-analytics-period')===f.ref,'saved link opens its exact completed period');
    const node=await f.panel().elementHandle();await f.repeats();await f.panel().locator('[data-profile-analytics-period]').waitFor();
    check(await f.page.evaluate(element=>element===document.getElementById('vp3ChatSpecialistActivity'),node)
      &&await f.page.evaluate(()=>window.fixtureAnalyticsOpens.length)===1,'repeated ready events retain one shell without reentering destination');
    await f.panel().getByRole('button',{name:'Close',exact:true}).click();await f.repeats();
    check(await f.panel().count()===0,'closing a consumed exact destination prevents late reopening');
    check(new URL(f.page.url()).searchParams.get('profile_analytics')===f.ref
      &&new URL(f.page.url()).searchParams.get('conversation_id')==='11','same-scope browser URL retains the fixed tuple for explicit reload');
    await f.control('timezone_change');await f.reload();await f.panel().locator('[data-profile-analytics-period]').waitFor();
    check((await f.panel().innerText()).includes('Period timezone: UTC')
      &&await f.panel().locator('[data-profile-analytics-period]').getAttribute('data-profile-analytics-period')===f.ref,
      'reload keeps saved zone and cutoff after current timezone setting changes');
    check(f.reads.filter(row=>row.payload.action==='analytics_period').every(row=>row.payload.ref===f.ref),
      'every exact-link read retains the same canonical ref');
    await f.panel().evaluate(element=>{element.scrollTop=0;});
    await f.page.screenshot({path:'artifacts/profile-specialist-workspace/profile-period-fixed-link.png',fullPage:true});
  });
  await scenario({},async f=>{
    await f.page.evaluate(()=>VP3AgentActivityHub.openProfile({tab:'overview'}));
    await f.panel().getByRole('button',{name:'Close',exact:true}).click();
    await f.release();await f.repeats();
    check(await f.panel().count()===0&&f.reads.every(row=>row.payload.action!=='analytics_period'),'actual Profile Close before readiness cancels pending destination');
  });
  await scenario({},async f=>{
    await f.page.getByRole('button',{name:'New Chat',exact:true}).click();await f.release();await f.repeats();
    check(await f.page.evaluate(()=>STONEFELLOW_CHAT_CONTINUITY.conversationId())===0&&await f.panel().count()===0,
      'real New Chat action cancels old pending destination and cannot restore its private scope');
    check(!new URL(f.page.url()).searchParams.has('profile_analytics'),'true scope change removes the fixed route hint');
  });
  for(const options of [{duplicate:true},{malformed:true},{wrongAgent:true},{wrongChat:true}]){
    await scenario(options,async f=>{
      check(await f.page.evaluate(()=>STONEFELLOW_CHAT.profileAnalyticsDestination)===null,'actual production request helper rejects malformed or fallback destination');
      await f.release();await f.repeats();
      check(await f.panel().count()===0&&f.reads.every(row=>row.payload.action!=='analytics_period'),'invalid original destination never opens in an authorized fallback Chat');
      check(!new URL(f.page.url()).searchParams.has('profile_analytics'),'rejected destination does not survive core URL normalization');
    });
  }
  await scenario({system:true,base:'/nested/app',compact:true},async f=>{
    await f.release();await f.panel().locator('[data-profile-analytics-period]').waitFor();
    check(await f.page.evaluate(()=>STONEFELLOW_CHAT_CONTINUITY.agentId())===0
      &&await f.page.evaluate(()=>STONEFELLOW_CHAT_CONTINUITY.conversationId())===f.cid,'system Agent and exact private Chat restore through the real core');
    check(f.reads.filter(row=>row.payload.action==='analytics_period').every(row=>row.payload.chat_agent_id==='0'&&row.payload.chat_conversation_id===String(f.cid)),
      'subdirectory fixed reads preserve exact system scope');
    const link=await f.panel().locator('[data-profile-period-link]').getAttribute('href');
    check(link.startsWith('/nested/app/chat.php?')&&new URL(link,server.base).searchParams.get('agent')==='system','saved source link keeps system Agent and deployment base');
    const bounds=await f.panel().boundingBox(),viewport=f.page.viewportSize();
    check(bounds&&bounds.x>=0&&bounds.y>=0&&bounds.x+bounds.width<=viewport.width+1&&bounds.y+bounds.height<=viewport.height+1
      &&await f.panel().evaluate(element=>element.scrollWidth<=element.clientWidth+1),'compact exact-link destination stays contained');
  });
}

