import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {chromium} from 'playwright';

const executablePath=process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],...(executablePath?{executablePath}:{})});
const js=readFileSync(new URL('../chat-marketing-workspace-v1.js',import.meta.url),'utf8');
const css=readFileSync(new URL('../chat-marketing-workspace-v1.css',import.meta.url),'utf8');
const media=readFileSync(new URL('../chat-media-commands-v1.js',import.meta.url),'utf8');
const requests=[],errors=[];
let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const plans=[
 {product_id:10,agent_id:7,conversation_id:11,stage:'marketing_review',stage_label:'Review promotion',
  product_title:'Autumn shirts',resume:{owner_user_id:1,agent_id:7,conversation_id:11,product_id:10,stage:'marketing_review'}},
 {product_id:11,agent_id:7,conversation_id:33,stage:'published',stage_label:'Active',
  product_title:'Previous promotion',chat_url:'/chat.php?agent=7&conversation_id=33&marketing_plan=11'}
];
const campaigns=[{id:4,name:'Autumn special',business:'Shop',status:'active',readiness:'needs_setup',
 issues:['Choose an active reward'],window_state:'open',observations:{claims:'observed',redemptions:'unavailable',promotion:'observed'},
 href:'/campaigns.php?merchant=2&edit_campaign=4'}];
async function scenario(width){
  const page=await browser.newPage({viewport:{width,height:850}});page.setDefaultTimeout(10000);
  page.on('pageerror',err=>errors.push(err.message));
  await page.route('https://vp3.test/chat.php',route=>route.fulfill({status:200,contentType:'text/html',
    body:'<!doctype html><html><head></head><body><main class="chat-main"><section id="chatThread" data-view="chat"><div class="message">Private conversation</div></section><div id="chatComposerShell"><textarea id="chatInput"></textarea></div></main></body></html>'}));
  await page.route('https://vp3.test/api/initiative-workbench-v1.php',async route=>{
    const body=route.request().postDataJSON();requests.push(body);
    const data={ok:true,owner_key:'1',agent_id:7,conversation_id:11,tool:'marketing'};
    if(body.action==='marketing_overview')Object.assign(data,{coverage:'ready',campaigns,
      links:{campaigns:'/campaigns.php',products:'/profile-commerce-products.php',rewards:'/rewards.php'}});
    else if(body.action==='marketing_plans')Object.assign(data,{plans,next_before:null});
    else if(body.action==='marketing_plan')Object.assign(data,{plan:plans[0]});
    else Object.assign(data,{ok:false,error:'Wrong action'});
    await route.fulfill({status:data.ok?200:422,contentType:'application/json',body:JSON.stringify(data)});
  });
  await page.goto('https://vp3.test/chat.php');
  await page.evaluate(()=>{
    window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'binding'};
    window.VP3_INITIATIVE_WORKBENCH={endpoint:'/api/initiative-workbench-v1.php',mediaEndpoint:'/api/media-library-v86.php',
      followthroughEndpoint:'/api/marketing-followthrough-v1.php',csrf:'csrf',ownerKey:'1',agentId:7};
    const thread=document.getElementById('chatThread');
    window.fixture={calls:[],conversation:11,participant:false};
    window.VP3ParticipantContinuity={selectedContext:()=>window.fixture.participant};
    window.STONEFELLOW_CHAT_CONTINUITY_V87={
      conversationId:()=>window.fixture.conversation,agentId:()=>7,isReady:()=>true,isRestoring:()=>false,
      showMarketingCanvasView:()=>{thread.dataset.view='marketing';window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));},
      showChatView:()=>{thread.dataset.view='chat';window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));},
      mediaCommandContext:()=>({ownerKey:'1',agentId:7,conversationId:window.fixture.conversation,
        isCurrent:()=>thread.dataset.view==='chat'&&window.fixture.conversation===11}),
      showTurnNotice:msg=>window.fixture.calls.push(['notice',msg])
    };
    window.VP3InitiativeWorkbench={openProductLaunch:marker=>{window.fixture.calls.push(['resume',marker.product_id]);return true;},
      open:tool=>{window.fixture.calls.push(['editor',tool]);return true;}};
  });
  await page.addStyleTag({content:css});
  await page.addScriptTag({content:js});
  await page.addScriptTag({content:media});
  const opened=await page.evaluate(()=>window.VP3MediaCommands.open('marketing',
    window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext()));
  check(opened,'/marketing routes to Chat Canvas at '+width);
  await page.waitForSelector('#chatMarketingWorkspaceV1 [role="tab"][aria-selected="true"]');
  await page.waitForFunction(()=>document.querySelector('#chatMarketingWorkspaceV1')?.textContent.includes('Autumn shirts'));
  check(await page.locator('#chatThread').getAttribute('data-view')==='marketing','main Chat Canvas view switched');
  check(await page.locator('#vp3MediaCommands').count()===0,'legacy Marketing modal not opened');
  check(await page.getByText('Autumn special').count()>0,'live campaigns rendered from canonical overview');
  check(await page.locator('#chatMarketingWorkspaceV1').innerText().then(s=>!s.includes('customer email')),'private CRM not rendered');
  await page.getByRole('tab',{name:'Campaigns'}).click();
  check(await page.getByRole('link',{name:'Manage campaign'}).getAttribute('href')==='/campaigns.php?merchant=2&edit_campaign=4','safe edit destination');
  await page.getByRole('tab',{name:'Product plans'}).click();
  check(await page.getByRole('link',{name:'Open original Chat'}).getAttribute('href')==='/chat.php?agent=7&conversation_id=33&marketing_plan=11','original Chat protected link');
  if(width===390){
    const layout=await page.evaluate(()=>({body:document.documentElement.scrollWidth,screen:innerWidth}));
    check(layout.body<=layout.screen+2,'mobile workspace avoids horizontal page overflow');
  }
  await page.getByRole('button',{name:'Continue plan'}).click();
  await page.waitForFunction(()=>window.fixture.calls.some(x=>x[0]==='resume'));
  check(await page.locator('#chatThread').getAttribute('data-view')==='chat','plan resumes through Chat and existing workbench');
  await page.close();
}
try {
  await scenario(1200);await scenario(390);
  check(requests.every(x=>x.owner_key==='1'&&x.csrf_token==='csrf'&&x.agent_id===7&&x.conversation_id===11),'all reads bound to owner Agent and current Chat');
  check(requests.every(x=>['marketing_overview','marketing_plans','marketing_plan'].includes(x.action)),'no publication or sending mutations');
  check(errors.length===0,'no browser JavaScript exceptions: '+errors.join('; '));
  console.log('Marketing workspace browser '+checks+' checks passed');
}finally{await browser.close();}
