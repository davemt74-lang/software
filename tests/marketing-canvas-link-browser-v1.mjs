import assert from 'node:assert/strict';
import fs from 'node:fs';
import {chromium} from 'playwright';

const source=name=>fs.readFileSync(new URL('../'+name,import.meta.url),'utf8');
const css=source('chat-marketing-workspace-v1.css')+'\n'+source('chat-marketing-canvas-link-v1.css');
const js=['chat-marketing-workspace-v1.js','chat-marketing-canvas-link-v1.js','chat-media-commands-v1.js'];
const chromiumArgs={headless:true,args:['--no-sandbox']};
if(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH)chromiumArgs.executablePath=process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const browser=await chromium.launch(chromiumArgs);
let checks=0;
const check=(truth,label)=>{assert.ok(truth,label);checks++;console.log('PASS '+label)};
const firstId='01234567-89ab-4cde-8f01-0123456789ab',secondId='12345678-89ab-4cde-8f01-0123456789ab';
const candidates=[
  {id:firstId,title:'Product artwork',version:2,attached:false,proof:'a'.repeat(64)},
  {id:secondId,title:'Campaign artwork',version:4,attached:true,proof:'b'.repeat(64)}
];
const campaigns=[
  {id:5,name:'Autumn Campaign',business:'Owner Shop',status:'active',version:3,
   readiness:'ready',window_state:'open',issues:[],observations:{claims:'unavailable',redemptions:'unavailable',promotion:'unavailable'},
   href:'/campaigns.php?merchant=7&edit_campaign=5'}
];
async function scenario(width){
 const page=await browser.newPage({viewport:{width,height:850}});
 page.setDefaultTimeout(14000);
 const writes=[],reads=[],errors=[];
 let linked=false,failedWrite=true,revoked=false,overrideEndpoint='';
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://vp3.test/chat.php',route=>route.fulfill({status:200,contentType:'text/html',
  body:'<!doctype html><html><head></head><body><main class="chat-main"><div id="chatThread" data-view="chat"><div class="message">Owner Chat</div></div><div id="chatComposerShell"><textarea id="chatInput"></textarea></div></main></body></html>'}));
 await page.route('https://vp3.test/api/initiative-workbench-v1.php',route=>{
  const body=route.request().postDataJSON();reads.push(body);
  if(revoked)return route.fulfill({status:403,contentType:'application/json',
    body:JSON.stringify({ok:false,error:'Access revoked'})});
  const response={ok:true,owner_key:'1',agent_id:7,conversation_id:11,tool:'marketing'};
  if(body.action==='marketing_overview')Object.assign(response,{coverage:'ready',campaigns,
    links:{campaigns:'/campaigns.php',products:'/profile-commerce-products.php',rewards:'/rewards.php'}});
  else if(body.action==='marketing_plans')Object.assign(response,{plans:[],next_before:null});
  else if(body.action==='marketing_canvas_choices'){
    if(body.campaign_id!==5)return route.fulfill({status:403,contentType:'application/json',
      body:JSON.stringify({ok:false,error:'Foreign campaign'})});
    Object.assign(response,{creative:{
      campaign:{id:5,title:'Autumn Campaign',version:3},choices:candidates,coverage:'ready',
      linked:linked?{state:'linked',label:'Campaign artwork · Version 4'}:null,
      link_endpoint:overrideEndpoint||'/api/marketing-canvas-link-v1.php'
    }});
  } else return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({ok:false,error:'Unknown'})});
  return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(response)});
 });
 await page.route('https://vp3.test/api/marketing-canvas-link-v1.php',route=>{
   const payload=route.request().postDataJSON();
   writes.push({payload,header:route.request().headers()['x-vp3-working-attention']});
   if(failedWrite)return route.fulfill({status:409,contentType:'application/json',
     body:JSON.stringify({ok:false,error:'Canvas or Campaign changed'})});
   linked=true;
   return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,state:'linked'})});
 });
 await page.goto('https://vp3.test/chat.php');
 await page.evaluate(()=>{
  window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'private-page-binding'};
  window.VP3_INITIATIVE_WORKBENCH={
    endpoint:'/api/initiative-workbench-v1.php',mediaEndpoint:'/api/media-library-v86.php',
    followthroughEndpoint:'/api/marketing-followthrough-v1.php',
    csrf:'csrf-example',ownerKey:'1',agentId:7
  };
  const thread=document.getElementById('chatThread');
  window.fixture={cid:11,participant:false};
  window.VP3ParticipantContinuity={selectedContext:()=>window.fixture.participant};
  window.STONEFELLOW_CHAT_CONTINUITY_V87={
    conversationId:()=>window.fixture.cid,agentId:()=>7,isReady:()=>true,isRestoring:()=>false,
    showMarketingCanvasView:()=>{thread.dataset.view='marketing';window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));},
    showChatView:()=>{thread.dataset.view='chat';window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));},
    mediaCommandContext:()=>({ownerKey:'1',agentId:7,conversationId:window.fixture.cid,
      isCurrent:()=>thread.dataset.view==='chat'&&window.fixture.cid===11})
  };
 });
 await page.addStyleTag({content:css});
 for(const name of js)await page.addScriptTag({content:source(name)});
 const opened=await page.evaluate(()=>window.VP3MediaCommands.open('marketing',
   window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext()));
 check(opened,'/marketing opens secure Canvas workspace at '+width);
 await page.getByRole('tab',{name:'Campaigns'}).click();
 await page.getByRole('button',{name:'Link tested Canvas'}).click();
 const panel=page.locator('[data-marketing-canvas-link]');
 await panel.waitFor();
 await page.waitForFunction(()=>document.querySelector('[data-marketing-canvas-link] select')?.options.length>0
   &&document.querySelectorAll('[data-marketing-canvas-link] select')[1]?.options.length===2);
 check(await page.locator('#chatThread').getAttribute('data-view')==='marketing',
   'creative association is in Chat Canvas, not a second dashboard');
 check(reads.some(r=>r.action==='marketing_canvas_choices'&&r.campaign_id===5),'read exact Campaign bound to owner Chat');
 check(writes.length===0,'loading and choosing Canvas never writes or publishes');
 await panel.getByRole('button',{name:'Review link'}).click();
 await panel.getByRole('button',{name:'Confirm private Canvas link'}).waitFor({state:'visible'});
 check(writes.length===0,'explicit review must precede any canonical link write');
 await panel.locator('select').nth(1).selectOption(secondId+':4');
 check(await panel.getByRole('button',{name:'Confirm private Canvas link'}).isHidden(),
   'changing the selected Canvas invalidates prior approval');
 await panel.getByRole('button',{name:'Review link'}).click();
 check((await panel.locator('.vp3-marketing-canvas-link-review').innerText()).includes('Autumn Campaign (v3)')
   &&(await panel.locator('.vp3-marketing-canvas-link-review').innerText()).includes('Campaign artwork (v4)'),
   'review names exact Campaign and tested Canvas revisions');
 await panel.getByRole('button',{name:'Confirm private Canvas link'}).click();
 await page.waitForFunction(()=>document.querySelector('.vp3-marketing-canvas-link-status')?.textContent.includes('could not be verified'));
 check(writes.length===1&&!linked,'stale write gives no false success');
 check(writes[0].payload.canvas_id===secondId&&writes[0].payload.canvas_version===4
   &&writes[0].payload.campaign_id===5&&writes[0].payload.campaign_version===3
   &&writes[0].payload.canvas_proof==='b'.repeat(64)
   &&writes[0].payload.csrf_token==='csrf-example'
   &&writes[0].header==='private-page-binding','existing canonical endpoint receives exact protected selection');
 failedWrite=false;
 await panel.getByRole('button',{name:'Review link'}).click();
 await panel.getByRole('button',{name:'Confirm private Canvas link'}).click();
 await page.waitForFunction(()=>document.querySelector('[data-marketing-canvas-link]')?.textContent.includes('Current saved link:'));
 check(linked&&writes.length===2,'only confirmed link writes, then re-reads canonical linked artifact');
 check(reads.some(r=>r.action==='marketing_canvas_choices')&&writes.every(x=>x.payload.conversation_id===11),
   'current Chat and source are preserved');
 if(width===390){
   const size=await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,viewport:innerWidth}));
   check(size.scroll<=size.viewport+2,'390px workspace has no horizontal overflow');
 }
 revoked=true;
 await panel.getByRole('button',{name:'Reload choices'}).click();
 await page.waitForFunction(()=>document.getElementById('chatThread').dataset.view==='chat');
 check(!(await page.locator('#chatMarketingWorkspaceV1').innerText()).includes('Campaign artwork'),
   'revocation drops private creative titles and returns to Chat');
 check(!writes.some(x=>Object.hasOwn(x.payload,'status')||Object.hasOwn(x.payload,'publish')||Object.hasOwn(x.payload,'send')),
   'no publishing, issuing or messaging capability added by the Canvas link');
 check(errors.length===0,'no browser exceptions '+errors.join('; '));
 await page.close();
}
try{await scenario(1220);await scenario(390);console.log('Marketing Canvas link: '+checks+' browser checks passed');}
finally{await browser.close();}
