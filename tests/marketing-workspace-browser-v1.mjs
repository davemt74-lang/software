import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {chromium} from 'playwright';

const executablePath=process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],...(executablePath?{executablePath}:{})});
const js=readFileSync(new URL('../chat-marketing-workspace-v1.js',import.meta.url),'utf8');
const css=readFileSync(new URL('../chat-marketing-workspace-v1.css',import.meta.url),'utf8');
const media=readFileSync(new URL('../chat-media-commands-v1.js',import.meta.url),'utf8');
const requests=[],errors=[];
let deny=false;
let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const plans=[
 {product_id:10,agent_id:7,conversation_id:11,stage:'marketing_review',stage_label:'Review promotion',
  product_title:'Autumn shirts',resume:{owner_user_id:1,agent_id:7,conversation_id:11,product_id:10,stage:'marketing_review'}},
 {product_id:11,agent_id:7,conversation_id:33,stage:'published',stage_label:'Active',
  product_title:'Previous promotion',chat_url:'/chat.php?agent=7&conversation_id=33&marketing_plan=11'}
];
const campaigns=[{id:4,name:'Autumn special',business:'Shop',version:3,status:'active',readiness:'needs_setup',
 issues:['Choose an active reward'],window_state:'open',observations:{claims:'observed',redemptions:'unavailable',promotion:'observed'},
 performance:{sources:{
   claims:{state:'unobserved',metrics:{},window:{}},
   redemptions:{state:'unavailable',metrics:{},window:{}},
   promotion:{state:'observed',metrics:{clicks:18,checkout_starts:3,paid_orders:0,refunded_orders:0},
     window:{start:'2026-11-10T00:00:00+00:00',end:null}}},
   decisions:[{id:12,type:'fatigue',status:'proposed'},{id:13,type:'low-observed-conversion',status:'accepted'}]},
 followthrough:[{recommendation_id:13,type:'low-observed-conversion',state:'observed',
   reviewed_at:'2026-10-09T12:00:00+00:00',change_at:'2026-10-10T12:00:00+00:00',
   before:{window_start:'2026-10-01T00:00:00+00:00',window_end:'2026-10-08T00:00:00+00:00',
     window_days:7,metrics:{treatment_entries:45,converted:2,reward_claimed:1}},after:{window_start:'2026-10-11T12:00:00+00:00',window_end:'2026-10-18T12:00:00+00:00',
     window_days:7,metrics:{treatment_entries:60,converted:5,reward_claimed:3}},
   scope:'observational_not_causal',
   learning:{contract:'vp3.marketing-outcome-learning.v1',state:'descriptive_only',
     direction:'higher_observed_rate',prior_rate_bps:444,later_rate_bps:833,
     prior_entries:45,later_entries:60,window_end:'2026-10-18T12:00:00+00:00',
     next_step:'Review which creative was actually applied, continue tracking, and consider a controlled test before expanding.',
     scope:'separate_observations_not_causal'}}],
 href:'/campaigns.php?merchant=2&edit_campaign=4'}];
async function scenario(width){
  let staleVersion=false;
  let workLink=null;
  const page=await browser.newPage({viewport:{width,height:850}});page.setDefaultTimeout(10000);
  page.on('pageerror',err=>errors.push(err.message));
  await page.route('https://vp3.test/chat.php',route=>route.fulfill({status:200,contentType:'text/html',
    body:'<!doctype html><html><head></head><body><main class="chat-main"><section id="chatThread" data-view="chat"><div class="message">Private conversation</div></section><div id="chatComposerShell"><textarea id="chatInput"></textarea></div></main></body></html>'}));
  await page.route('https://vp3.test/api/initiative-workbench-v1.php',async route=>{
    const body=route.request().postDataJSON();requests.push(body);
    if(deny) return route.fulfill({status:403,contentType:'application/json',
      body:JSON.stringify({ok:false,error:'Marketing access revoked'})});
    const data={ok:true,owner_key:'1',agent_id:7,conversation_id:11,tool:'marketing'};
    if(body.action==='marketing_overview')Object.assign(data,{coverage:'ready',
      campaigns:(staleVersion?campaigns.map(row=>({...row,version:row.version+1})):campaigns)
        .map(row=>({...row,linked_creative:workLink?[workLink]:[]})),
      opportunity_portfolio:{contract:'vp3.marketing.opportunity-portfolio.v1',
        review_scope:'current_authorized_sources_only',coverage:'limited',opportunities:[
          {source_ref:'marketing:campaign-readiness:4',source_revision:'a'.repeat(64),
           source_id:4,kind:'campaign-readiness',label:'Campaign readiness',title:'Autumn special setup',
           finding:'The saved Campaign needs a reward.',next_step:'Choose the permitted reward.',
           question:'Review this issue?',priority:'high',source_status:'open',
           source_href:'/campaigns.php?merchant=2&edit_campaign=4',coverage:'limited',
           notification_owner:'coordination'},
          {source_ref:'marketing:product-plan:10',source_revision:'b'.repeat(64),
           source_id:10,kind:'product-plan',label:'Product marketing',title:'Autumn shirts · product plan',
           finding:'Verified private design review needed.',next_step:'Review existing artwork.',
           question:'',priority:'low',source_status:'photo_needed',
           source_href:'/chat.php?agent=7&conversation_id=11&marketing_plan=10',coverage:'limited',
           notification_owner:'product_launch'},
          {source_ref:'marketing:campaign-performance:4',source_revision:'a'.repeat(64),
           source_id:4,kind:'campaign-performance',label:'Performance review',title:'Forged external destination',
           finding:'Private text must not become a link.',next_step:'Review in Campaigns.',
           question:'',priority:'normal',source_status:'open',
           source_href:'https://attacker.invalid/steal',coverage:'limited',
           notification_owner:'coordination'},
        ]},
      links:{campaigns:'/campaigns.php',products:'/profile-commerce-products.php',rewards:'/rewards.php'}});
    else if(body.action==='marketing_plans')Object.assign(data,{plans,next_before:null});
    else if(body.action==='marketing_plan')Object.assign(data,{plan:plans[0]});
    else if(body.action==='marketing_work_choices')Object.assign(data,{work_choices:{
      campaign_id:4,recommendation_id:13,campaign_version:staleVersion?4:3,
      plans:[{product_id:10,title:'Autumn shirts',stage:'creative_ready',revision:'a'.repeat(64)}],
      limited:false,disclaimer:'Private association only'
    }});
    else if(body.action==='marketing_work_link'){
      if(body.campaign_id!==4||body.recommendation_id!==13||body.expected_campaign_version!==3
        ||body.product_id!==10||body.expected_plan_revision!=='a'.repeat(64))data.ok=false;
      else {
        workLink={id:44,recommendation_id:13,product_id:10,product_title:'Autumn shirts',
          plan_stage:'creative_ready',review_state:'linked',campaign_version:3,
          evidence_state:'awaiting_change',implementation:'not_verified'};
        data.work_saved={id:44,state:'linked',published:false,campaign_mutated:false};
      }
    }else if(body.action==='marketing_work_approve'){
      if(!workLink||body.link_id!==44||body.expected_campaign_version!==3)data.ok=false;
      else {
        workLink={...workLink,review_state:'approved_for_use'};
        data.work_saved={id:44,state:'approved_for_use',published:false,campaign_mutated:false};
      }
    }else if(body.action==='marketing_work_unlink'){
      if(!workLink||body.link_id!==44)data.ok=false;
      else {workLink=null;data.work_saved={id:44,state:'unlinked',published:false,campaign_mutated:false};}
    }
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
  const portfolio=page.locator('.vp3-marketing-opportunity-queue');
  check(await portfolio.count()===1,'existing Marketing overview shows prioritized opportunities');
  check(await portfolio.locator('.vp3-marketing-opportunity').count()===2,'only two source-validated marketing opportunities render');
  check(await portfolio.getByText('Autumn special setup').count()===1
    &&await portfolio.getByText('Autumn shirts · product plan').count()===1,
    'Campaign and product sources use the same opportunity queue');
  check(await portfolio.getByText('Opportunity coverage: limited.',{exact:false}).count()===1,
    'partial source coverage never becomes a misleading complete audit');
  check(await portfolio.locator('a[href="/campaigns.php?merchant=2&edit_campaign=4"]').count()===1
    &&await portfolio.locator('a[href="/chat.php?agent=7&conversation_id=11&marketing_plan=10"]').count()===1,
    'only authorized Campaign and exact Chat product-plan links are actionable');
  check(await portfolio.locator('a[href*="attacker.invalid"]').count()===0
    &&await portfolio.getByText('Forged external destination').count()===0,
    'untrusted external recommendation links fail closed');
  check(await page.locator('#chatMarketingWorkspaceV1').innerText().then(s=>!s.includes('customer email')),'private CRM not rendered');
  await page.getByRole('tab',{name:'Analytics'}).click();
  check(await page.getByRole('heading',{name:'Campaign performance & next actions'}).count()===1,
    'analytics tab provides read-only performance briefing');
  check(await page.getByText('18',{exact:true}).count()>=1&&await page.getByText('3',{exact:true}).count()>=1,
    'verified promotion counters render as recorded');
  check(await page.getByText('No verified activity recorded yet; not a measured zero.').count()===1,
    'unobserved Reward claims never appear as a measured zero');
  check(await page.getByText('Evidence unavailable. No metric is estimated.').count()===1,
    'unavailable optimization evidence has no invented metrics');
  check(await page.getByText(/checkout starts are recorded but no paid orders are attributed/).count()===1,
    'evidence-backed checkout review opportunity is shown without automatic mutation');
  check(await page.getByRole('heading',{name:'Human-review recommendations'}).count()===1,
    'recommendation remains under human review in canonical Campaigns');
  check(await page.getByRole('link',{name:'Open detailed Campaign analytics'}).getAttribute('href')==='/campaigns.php?merchant=2&edit_campaign=4',
    'analytics drills into existing Campaigns page without duplicating the manager');
  check(!(await page.locator('#chatMarketingWorkspaceV1').innerText()).includes('customer_email'),
    'private attributes never appear in analytics cards');
  check(await page.getByText('2 before · 5 after').count()===1,
    'audited nonoverlapping converted counts are presented separately without causality');
  check(await page.getByText(/not a causal effect, revenue lift/).count()===1,
    'post-review evidence prominently denies causal claims');
  check(await page.getByRole('heading',{name:'Learning from verified observations'}).count()===1
    &&await page.getByText(/4\.44% before/).count()===1
    &&await page.getByText(/8\.33% after/).count()===1,
    'desktop/mobile Marketing Analytics explains independent per-entry conversion rates');
  check(await page.getByText(/consider a controlled test before expanding/).count()===1,
    'next step stays an owner-review recommendation, not an automatic campaign change');
  check(await page.getByText(/do not establish causal impact or creative use/).count()===1,
    'bounded learning explicitly avoids unsupported creative attribution');
  check(await page.getByRole('button',{name:'Prepare private creative'}).count()===1,
    'an accepted review exposes an explicit private creative handoff');
  await page.getByRole('button',{name:'Prepare private creative'}).click();
  await page.waitForFunction(()=>document.getElementById('vp3MarketingTab3')?.getAttribute('aria-selected')==='true');
  check(await page.getByText(/No Campaign edit or public creative change is implied/).count()===1,
    'private preparation does not claim a published change or automatic Campaign binding');
  check(await page.getByRole('link',{name:'Review Campaign'}).getAttribute('href')==='/campaigns.php?merchant=2&edit_campaign=4',
    'approved creative focus keeps the exact canonical Campaign edit destination');
  await page.getByRole('tab',{name:'Analytics'}).click();
  staleVersion=true;
  await page.getByRole('button',{name:'Prepare private creative'}).click();
  await page.waitForFunction(()=>document.querySelector('#chatMarketingWorkspaceV1 .vp3-marketing-workspace-status')
    ?.textContent.includes('This Campaign review changed.'));
  check(await page.getByRole('tab',{name:'Analytics'}).getAttribute('aria-selected')==='true',
    'a changed Campaign revision cannot reopen its previous approved creative handoff');
  staleVersion=false;
  await page.getByRole('button',{name:'Link saved creative'}).click();
  await page.waitForFunction(()=>document.getElementById('vp3MarketingTab3')?.getAttribute('aria-selected')==='true');
  check(await page.getByRole('button',{name:'Link this saved draft'}).count()===1,
    'owner can explicitly associate an existing private product creative plan');
  await page.getByRole('button',{name:'Link this saved draft'}).click();
  await page.waitForFunction(()=>document.querySelector('#chatMarketingWorkspaceV1 .vp3-marketing-workspace-status')
    ?.textContent.includes('Saved draft linked'));
  await page.getByRole('tab',{name:'Analytics'}).click();
  check(await page.getByRole('heading',{name:'Linked private creative'}).count()===1
    &&await page.getByRole('button',{name:'Review creative approval'}).count()===1,
    'linked creative survives a workspace reload without claiming Campaign implementation');
  await page.getByRole('button',{name:'Review creative approval'}).click();
  check(await page.getByText(/does not publish, send, or apply a Campaign edit/).count()===1,
    'creative approval requires a separate explicit confirmation');
  await page.getByRole('button',{name:'Confirm private creative approval'}).click();
  await page.waitForFunction(()=>document.querySelector('#chatMarketingWorkspaceV1 .vp3-marketing-workspace-status')
    ?.textContent.includes('Private creative approved'));
  check(await page.getByText('Approved for use (private only)').count()===1,
    'verified approval appears without pretending the Campaign was edited');
  await page.getByRole('button',{name:'Unlink'}).click();
  await page.getByRole('button',{name:'Confirm unlink'}).click();
  await page.waitForFunction(()=>document.querySelector('#chatMarketingWorkspaceV1 .vp3-marketing-workspace-status')
    ?.textContent.includes('association unlinked'));
  check(await page.getByText('No saved creative is linked to an accepted recommendation yet.').count()===1,
    'unlink preserves original work and removes only its private association');

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
  const reopen=()=>page.evaluate(()=>window.VP3MediaCommands.open('marketing',
    window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext()));
  check(await reopen(),'Marketing can reopen after approved plan handoff');
  await page.waitForFunction(()=>document.querySelector('#chatMarketingWorkspaceV1')?.textContent.includes('Autumn special'));
  deny=true;
  await page.getByRole('button',{name:'Refresh'}).click();
  await page.waitForFunction(()=>document.getElementById('chatThread').dataset.view==='chat');
  check(!(await page.locator('#chatMarketingWorkspaceV1').textContent()).includes('Autumn special'),
    'revoked permission removes previously visible private campaign rows');
  deny=false;
  check(await reopen(),'Marketing can reopen when access is restored');
  await page.waitForFunction(()=>document.querySelector('#chatMarketingWorkspaceV1')?.textContent.includes('Autumn special'));
  await page.evaluate(()=>{
    window.fixture.conversation=77;
    window.dispatchEvent(new Event('vp3:chat-conversation-changed'));
  });
  check(await page.locator('#chatThread').getAttribute('data-view')==='chat',
    'changing conversation closes the owner-private Marketing view');
  check(!(await page.locator('#chatMarketingWorkspaceV1').textContent()).includes('Autumn special'),
    'switching Chat clears the private campaign contents');
  await page.close();
}
try {
  await scenario(1200);await scenario(390);
  check(requests.every(x=>x.owner_key==='1'&&x.csrf_token==='csrf'&&x.agent_id===7&&x.conversation_id===11),'all reads bound to owner Agent and current Chat');
  check(requests.every(x=>['marketing_overview','marketing_plans','marketing_plan',
    'marketing_work_choices','marketing_work_link','marketing_work_approve','marketing_work_unlink'].includes(x.action)),
    'only private review and association mutations; no publication or sending');
  check(['marketing_work_link','marketing_work_approve','marketing_work_unlink'].every(action=>
    requests.some(x=>x.action===action)),'all three explicit private creative decisions were exercised');
  check(errors.length===0,'no browser JavaScript exceptions: '+errors.join('; '));
  console.log('Marketing workspace browser '+checks+' checks passed');
}finally{await browser.close();}
