import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const assets=new Set(['agent-eyes-core.js','agent-orb.js','agent-orb.css','agent-orb-particles.js','agent-orb-particles.css','chat-specialist-rail-v1.js','chat-specialist-rail-v1.css']);
let workMode='active';
const workRequests=[],pendingWork=[];
const server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://localhost'),file=path.basename(url.pathname);
  if(url.pathname==='/api/top-of-mind'){
    workRequests.push({method:req.method,view:url.searchParams.get('view'),agent:url.searchParams.get('agent_id'),binding:req.headers['x-vp3-working-attention']});
    const mode=workMode;
    const reply=()=>{
      res.setHeader('Content-Type','application/json');
      if(mode==='forbidden'){res.statusCode=403;res.end('{"ok":false}');return;}
      const state={contract:'vp3.agent.work.v1',owner_user_id:1,agent_id:7,conversation_id:42,working_attention_binding:'private-session',
        observed_at:new Date(Date.now()-(mode==='stale'?60000:0)).toISOString(),
        health:{state:mode==='idle'?'waiting':'running',enabled:true,active_jobs:mode==='idle'?[]:[{id:'workflow:21',kind:'assessment',step:'Brainstorming useful opportunities',expiresAt:Date.now()+60000}]}};
      if(mode==='owner')state.owner_user_id=2;
      if(mode==='agent')state.agent_id=8;
      if(mode==='binding')state.working_attention_binding='other';
      res.end(JSON.stringify({ok:true,state}));
    };
    if(mode==='hold')pendingWork.push(reply);else reply();
    return;
  }
  if(assets.has(file)){
    res.setHeader('Content-Type',file.endsWith('.css')?'text/css':'text/javascript');
    res.end(fs.readFileSync(file));return;
  }
  const chat=url.pathname==='/chat';
  res.setHeader('Content-Type','text/html');
  res.end(`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="/agent-orb.css"><link rel="stylesheet" href="/agent-orb-particles.css"><link rel="stylesheet" href="/chat-specialist-rail-v1.css">
    <style>body{margin:0;background:#f8f8f8}main{padding:24px}#chatComposerShell{position:fixed;bottom:0;left:24px;right:24px;padding:12px;background:white}#chatForm{display:flex}#chatInput{flex:1;min-width:0}#sfOnlineRailV109{position:fixed;right:0;top:0}</style>
    <main><h1>${chat?'Agent Chat':'Workspace'}</h1>${chat?'<section id="chatThread"></section><div id="chatComposerShell"><form id="chatForm"><textarea id="chatInput" aria-label="Message"></textarea><button id="chatVoiceButton" type="button">Listen</button><button id="sendChatButton">Send</button></form></div><aside id="sfOnlineRailV109"><div id="sfChatSettingsRailSlotV132"></div></aside>':''}</main>
    <script>
      window.foreground='idle';window.listenCalls=0;window.stopCalls=0;window.sent=0;window.selected=null;window.hubOpened=[];
      window.VP3ParticipantContinuity={selectedContext:()=>window.selected,snapshot:()=>null,clearSelection:()=>{},setSelection:value=>{window.selected=value;},updatePresence:()=>{}};
      window.VP3AgentActivityHub={open:options=>{window.hubOpened.push(options);return true;}};
      window.VP3_ORB_CONFIG={ownerKey:'1',agentId:7,workingAttentionBinding:'private-session',shape:'orbit',topOfMindEndpoint:${url.searchParams.has('work')?"'/api/top-of-mind'":"''"}};
      ${chat?`window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'private-session'};
      window.STONEFELLOW_CHAT_CONTINUITY={isReady:()=>true,isRestoring:()=>false,conversationId:()=>42};
      document.getElementById('chatForm').addEventListener('submit',event=>{event.preventDefault();window.sent++;});
      document.getElementById('chatVoiceButton').addEventListener('click',()=>window.VP3_ORB_ADAPTER.listen());`:''}
      window.VP3_ORB_ADAPTER={ownerKey:'1',chatUrl:'/chat',state:()=>({state:window.foreground}),
        conversationRef:()=>(${chat?'{id:42,agent:7}':'null'}),conversationUrl:ref=>'/chat?conversation_id='+ref.id,
        isMainChat:()=>${chat},focusChat:()=>document.getElementById('chatInput')?.focus(),busy:()=>false,
        listen:()=>{window.listenCalls++;window.foreground='listening';},stop:()=>{window.stopCalls++;window.foreground='idle';}};
    </script>
    <script src="/agent-eyes-core.js"></script><script src="/chat-specialist-rail-v1.js"></script><script src="/agent-orb.js"></script><script src="/agent-orb-particles.js"></script>`);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const url=`http://127.0.0.1:${server.address().port}`;
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const errors=[];
let checks=0;
const check=async(name,run)=>{await run();checks++;console.log('PASS '+name);};
async function pageAt(route='/chat',options={}){
  const {clock=false,...browserOptions}=options;
  const page=await browser.newPage({viewport:{width:1280,height:850},...browserOptions});
  if(clock)await page.clock.install();
  page.on('pageerror',error=>{errors.push(error.message);if(process.env.VP3_TEST_DEBUG)console.error(error.message);});
  await page.goto(url+route);
  await page.waitForFunction(()=>!!window.VP3AgentOrb);
  return page;
}
const activeJob=()=>({id:'workflow:21',kind:'assessment',step:'Brainstorming useful opportunities',expiresAt:Date.now()+60000});
try{
  const main=await pageAt();
  await check('Working stays independent while the main Agent listens, replies and accepts text',async()=>{
    await main.locator('#chatInput').fill('Keep this draft');
    await main.locator('#chatInput').focus();
    await main.evaluate(job=>VP3AgentOrb.setJobs('initiative',[job]),activeJob());
    await main.locator('[data-working="true"] [data-orb-work-tracks="1"]').waitFor();
    assert.equal(await main.locator('[data-orb-voice-state]').textContent(),'Working');
    assert.equal(await main.locator('#chatInput').inputValue(),'Keep this draft');
    assert.equal(await main.locator('#chatInput').evaluate(node=>node===document.activeElement),true);
    assert.deepEqual(await main.evaluate(()=>[listenCalls,stopCalls,hubOpened.length]),[0,0,0]);
    await main.locator('#chatVoiceButton').click();
    await main.waitForFunction(()=>document.querySelector('.vp3-orb-root').dataset.state==='listening');
    assert.equal(await main.locator('[data-orb-voice-state]').textContent(),'Listening · Working');
    assert.equal(await main.evaluate(()=>VP3AgentOrb.state().working),true);
    const before=await main.locator('[data-orb-work-tracks="1"]').evaluate(node=>node.toDataURL());
    await main.waitForFunction(previous=>document.querySelector('[data-orb-work-tracks="1"]').toDataURL()!==previous,before);
    if(process.env.VP3_TEST_SCREENSHOT)await main.screenshot({path:process.env.VP3_TEST_SCREENSHOT});
    await main.locator('#sendChatButton').click();
    assert.equal(await main.evaluate(()=>sent),1);
    for(const primary of ['thinking','speaking','error','idle']){
      await main.evaluate(value=>window.foreground=value,primary);
      await main.waitForFunction(value=>document.querySelector('.vp3-orb-root').dataset.state===value,primary);
      assert.equal(await main.locator('.vp3-orb-root').getAttribute('data-working'),'true');
      assert.equal(await main.locator('[data-orb-work-tracks="1"]').count(),1);
    }
  });
  await check('A real assessment opens current work in the shared rail and closes when it stops',async()=>{
    const specialist=main.locator('[data-chat-specialist="brainstorm"]');
    const panel=main.getByRole('dialog',{name:'Opportunity Agent'});
    await specialist.waitFor();
    assert.deepEqual(await main.evaluate(()=>VP3ChatSpecialists.snapshot().map(item=>[item.key,item.label,item.status,item.detail,item.canView])),[
      ['brainstorm','Opportunity Agent','Working','Brainstorming useful opportunities',false]
    ]);
    assert.equal(await main.locator('.vp3-chat-specialist-panel').count(),0);
    assert.equal(await main.evaluate(()=>hubOpened.length),0);
    await specialist.click();
    await panel.waitFor();
    assert.equal(await specialist.getAttribute('aria-expanded'),'true');
    assert.match(await panel.textContent(),/Top of Mind brainstorm/);
    assert.match(await panel.textContent(),/Brainstorming useful opportunities/);
    assert.deepEqual(await main.evaluate(()=>hubOpened),[]);
    await main.evaluate(job=>VP3AgentOrb.setJobs('initiative',[job]),{
      ...activeJob(),step:'Reviewing the current plan'
    });
    await panel.getByText('Reviewing the current plan',{exact:true}).waitFor();
    assert.equal(await panel.getByText('Brainstorming useful opportunities',{exact:true}).count(),0);
    await main.evaluate(()=>{
      VP3AgentOrb.setJobs('mission',[{id:'mission:live',expiresAt:Date.now()+10000}]);
      VP3AgentOrb.setJobs('initiative',[]);
    });
    await specialist.waitFor({state:'detached'});
    await panel.waitFor({state:'detached'});
    assert.equal(await main.evaluate(()=>VP3AgentOrb.isWorking()),true);
    await main.evaluate(()=>VP3AgentOrb.setJobs('mission',[]));
    assert.equal(await main.evaluate(()=>VP3AgentOrb.isWorking()),false);
    await main.locator('.vp3-orb-ball').hover();
    await main.locator('[data-orb-activity]').click();
    assert.deepEqual(await main.evaluate(()=>hubOpened),[undefined]);
  });
  await check('Terminal, expired, invalid and paused work cannot leave a Working dot behind',async()=>{
    await main.evaluate(()=>{
      window.foreground='listening';
      VP3AgentOrb.setJobs('initiative',[{id:'workflow:30',kind:'assessment',expiresAt:Date.now()+1000}]);
    });
    await main.locator('[data-working="true"]').waitFor();
    await main.waitForFunction(()=>document.querySelector('.vp3-orb-root').dataset.state==='listening');
    await main.waitForFunction(()=>!VP3AgentOrb.isWorking());
    assert.equal(await main.evaluate(()=>VP3AgentOrb.isWorking()),false);
    assert.equal(await main.locator('[data-orb-voice-state]').textContent(),'Listening');
    assert.equal(await main.locator('[data-chat-specialist="brainstorm"]').count(),0);
    await main.evaluate(()=>VP3AgentOrb.setJobs('initiative',[
      {id:'workflow:31',kind:'assessment',expiresAt:Date.now()-1},
      {id:'image:wrong-source',expiresAt:Date.now()+10000},
      {id:'workflow:32',expiresAt:'tomorrow'}
    ]));
    assert.equal(await main.locator('[data-orb-work-tracks]').count(),0);
    await main.evaluate(()=>VP3AgentOrb.setJobs('initiative',[{id:'workflow:33',kind:'assessment',expiresAt:Date.now()+1000}]));
    await main.locator('[data-working="true"]').waitFor();
    await main.evaluate(()=>VP3AgentOrb.setJobs('initiative',[]));
    assert.equal(await main.locator('.vp3-orb-root').getAttribute('data-working'),'false');
    assert.equal(await main.locator('[data-orb-voice-state]').textContent(),'Listening');
  });
  await check('Permission, account and Agent changes immediately remove private work',async()=>{
    for(const change of ['participant','binding','agent','owner']){
      const page=await pageAt();
      try{
        await page.evaluate(job=>VP3AgentOrb.setJobs('initiative',[job]),activeJob());
        await page.locator('[data-working="true"]').waitFor();
        await page.locator('[data-chat-specialist="brainstorm"]').click();
        const panel=page.getByRole('dialog',{name:'Opportunity Agent'});
        await panel.waitFor();
        await page.evaluate(kind=>{
          if(kind==='participant'){window.selected={participant_id:8};dispatchEvent(new Event('vp3:participant-selection-changed'));}
          if(kind==='binding')STONEFELLOW_CHAT.workingAttentionBinding='new-session';
          if(kind==='agent')STONEFELLOW_CHAT.agentId=8;
          if(kind==='owner')VP3_ORB_CONFIG.ownerKey='2';
        },change);
        await page.waitForFunction(()=>!VP3AgentOrb.isWorking());
        await panel.waitFor({state:'detached'});
        await page.evaluate(job=>VP3AgentOrb.setJobs('initiative',[job]),activeJob());
        assert.equal(await page.evaluate(()=>VP3AgentOrb.isWorking()),false);
        assert.equal(await page.locator('[data-chat-specialist="brainstorm"]').count(),0);
      }finally{await page.close();}
    }
  });
  await main.close();
  await check('Embedded work propagates while closed and survives listening without accepting stale scope',async()=>{
    const page=await pageAt('/workspace');
    try{
      await page.evaluate(()=>VP3AgentOrb.open());
      const frame=await page.locator('iframe').elementHandle().then(node=>node.contentFrame());
      await frame.waitForFunction(()=>!!window.VP3AgentOrb);
      await frame.evaluate(job=>VP3AgentOrb.setJobs('initiative',[job]),activeJob());
      await page.locator('[data-working="true"]').waitFor();
      await frame.evaluate(()=>window.foreground='listening');
      await page.waitForFunction(()=>document.querySelector('.vp3-orb-root').dataset.state==='listening');
      assert.equal(await page.locator('[data-orb-voice-state]').textContent(),'Listening · Working');
      await page.getByRole('button',{name:'Close Agent Chat',exact:true}).click();
      assert.equal(await page.evaluate(()=>VP3AgentOrb.isWorking()),true);
      assert.equal(await page.evaluate(()=>VP3AgentOrb.activitySpecialists().some(item=>item.key==='brainstorm')),true);
      await frame.evaluate(()=>dispatchEvent(new Event('stonefellow:chat-context-changing')));
      await page.waitForFunction(()=>!VP3AgentOrb.isWorking());
      assert.equal(await page.evaluate(()=>VP3AgentOrb.activitySpecialists().some(item=>item.key==='brainstorm')),false);
      for(const patch of [{epoch:0},{agent_id:8},{ownerKey:'2'},{working_attention_binding:'other'},{participant:true}]){
        await frame.evaluate(patch=>parent.postMessage({channel:'vp3-orb',kind:'jobs',ownerKey:'1',source:'initiative',jobs:[{id:'workflow:90',kind:'assessment',expiresAt:Date.now()+30000}],activity_context:{ownerKey:'1',agent_id:7,conversation_id:42,working_attention_binding:'private-session',ready:true,participant:false,epoch:1,...patch}},location.origin),patch);
        await page.waitForTimeout(30);
        assert.equal(await page.evaluate(()=>VP3AgentOrb.isWorking()),false);
      }
      await frame.evaluate(job=>VP3AgentOrb.setJobs('initiative',[job]),activeJob());
      await page.locator('[data-working="true"]').waitFor();
      await frame.evaluate(()=>VP3AgentOrb.setJobs('initiative',[]));
      await page.waitForFunction(()=>!VP3AgentOrb.isWorking());
    }finally{await page.close();}
  });
  for(const shape of ['orbit','particle_face']){
    await check(`Mobile ${shape} keeps reduced-motion work dots static while listening`,async()=>{
      const page=await pageAt('/chat',{viewport:{width:390,height:844},reducedMotion:'reduce',hasTouch:true});
      try{
        await page.evaluate(({shape,job})=>{
          dispatchEvent(new CustomEvent('vp3:agent-orb-shape',{detail:{shape}}));
          window.foreground='listening';VP3AgentOrb.setJobs('initiative',[job]);
        },{shape,job:activeJob()});
        const tracks=page.locator('[data-orb-work-tracks="1"]');
        await tracks.waitFor();
        await page.waitForFunction(()=>document.querySelector('.vp3-orb-root').dataset.state==='listening');
        assert.equal(await page.locator('[data-vp3-orb-work]').getAttribute('data-orb-shape'),shape);
        const before=await tracks.evaluate(node=>node.toDataURL());
        await page.waitForTimeout(150);
        assert.equal(await tracks.evaluate(node=>node.toDataURL()),before);
        assert.equal(await page.locator('[data-orb-voice-state]').textContent(),'Listening · Working');
        const box=await tracks.boundingBox();
        assert.ok(box.x>=0&&box.y>=0&&box.x+box.width<=390&&box.y+box.height<=844);
        assert.equal(await page.locator('.vp3-chat-specialist-panel').count(),0);
      }finally{await page.close();}
    });
  }
  await check('Global work polls only the small verified projection and creates no Chat iframe',async()=>{
    workMode='active';
    const page=await pageAt('/workspace?work=1',{clock:true});
    try{
      await page.locator('[data-working="true"]').waitFor();
      assert.equal(await page.locator('iframe').count(),0);
      assert.equal(await page.locator('[data-orb-voice-state]').textContent(),'Working');
      await page.evaluate(()=>window.foreground='listening');
      await page.clock.fastForward(220);
      assert.equal(await page.locator('[data-orb-voice-state]').textContent(),'Listening · Working');
      workMode='idle';
      await page.clock.fastForward(2600);
      await page.waitForFunction(()=>!VP3AgentOrb.isWorking());
      assert.equal(await page.locator('[data-orb-voice-state]').textContent(),'Listening');
    }finally{await page.close();}
    const before=workRequests.length,chat=await pageAt('/chat?work=1',{clock:true});
    try{await chat.clock.fastForward(1000);assert.equal(workRequests.length,before);}finally{await chat.close();}
    assert.ok(workRequests.every(row=>row.method==='GET'&&row.view==='work'&&row.agent==='7'&&row.binding==='private-session'));
  });
  await check('Hidden global pages stop polling and refresh verified work when visible again',async()=>{
    workMode='active';
    const page=await pageAt('/workspace?work=1',{clock:true});
    try{
      await page.locator('[data-working="true"]').waitFor();
      await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:true});dispatchEvent(new Event('visibilitychange'));document.dispatchEvent(new Event('visibilitychange'));});
      await page.waitForFunction(()=>!VP3AgentOrb.isWorking());
      const before=workRequests.length;
      await page.clock.fastForward(3000);
      assert.equal(workRequests.length,before);
      await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:false});document.dispatchEvent(new Event('visibilitychange'));});
      await page.locator('[data-working="true"]').waitFor();
    }finally{await page.close();}
  });
  await check('Stale, mismatched and denied global work clears its dot and specialist',async()=>{
    for(const mode of ['stale','owner','agent','binding','forbidden']){
      workMode='active';
      const page=await pageAt('/workspace?work=1',{clock:true});
      try{
        await page.locator('[data-working="true"]').waitFor();
        workMode=mode;
        await page.clock.fastForward(2600);
        await page.waitForFunction(()=>!VP3AgentOrb.isWorking());
        assert.equal(await page.locator('[data-orb-work-tracks]').count(),0);
        assert.equal(await page.evaluate(()=>VP3ChatSpecialists.snapshot().some(item=>item.key==='brainstorm')),false);
        if(mode==='forbidden'){
          const before=workRequests.length;
          await page.clock.fastForward(8100);
          assert.equal(workRequests.length,before);
        }
      }finally{await page.close();}
    }
  });
  await check('A late global work response cannot restore work after private context changes',async()=>{
    workMode='hold';
    const page=await pageAt('/workspace?work=1');
    try{
      for(let n=0;!pendingWork.length;n++){
        if(n>100)throw Error('Global work poll did not reach its controlled response.');
        await new Promise(resolve=>setTimeout(resolve,10));
      }
      await page.evaluate(()=>{window.selected={participant_id:8};dispatchEvent(new Event('vp3:participant-selection-changed'));});
      pendingWork.shift()();
      await page.waitForTimeout(100);
      assert.equal(await page.evaluate(()=>VP3AgentOrb.isWorking()),false);
      assert.equal(await page.evaluate(()=>VP3ChatSpecialists.snapshot().length),0);
    }finally{while(pendingWork.length)pendingWork.shift()();await page.close();}
  });
  assert.deepEqual(errors,[]);
  console.log(`PASS ${checks} Working/browser checks`);
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
