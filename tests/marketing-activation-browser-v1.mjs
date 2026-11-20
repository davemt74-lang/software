import assert from 'node:assert/strict';
import fs from 'node:fs';
import {chromium} from 'playwright';

const read=file=>fs.readFileSync(new URL('../'+file,import.meta.url),'utf8');
const css=read('chat-marketing-workspace-v1.css')+'\n'+read('chat-marketing-activation-v1.css');
const scripts=['chat-marketing-workspace-v1.js','chat-marketing-activation-v1.js','chat-media-commands-v1.js'];
const launch={headless:true,args:['--no-sandbox']};
if(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH)launch.executablePath=process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const browser=await chromium.launch(launch);
let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label)};
const baseCampaigns=[
 {id:5,name:'Coffee Welcome',business:'Owner Shop',status:'draft',version:0,
  readiness:'ready',window_state:'not_open',issues:[],
  observations:{claims:'unavailable',redemptions:'unavailable',promotion:'unavailable'},
  href:'/campaigns.php?merchant=7&edit_campaign=5'},
 {id:6,name:'Future Deal',business:'Owner Shop',status:'scheduled',version:2,
  readiness:'limited',window_state:'upcoming',issues:[],
  observations:{claims:'unavailable',redemptions:'unavailable',promotion:'unavailable'},
  href:'/campaigns.php?merchant=7&edit_campaign=6'}
];
const makeEvidence=(id,active=false)=>{
 const blocked=id===6;
 return {
  id,merchant_id:7,name:blocked?'Future Deal':'Coffee Welcome',
  status:blocked?'scheduled':'draft',version:blocked?2:0,type:'signup',
  starts_at:blocked?'2098-12-01 00:00:00':null,ends_at:null,
  readiness:blocked?'limited':'ready',
  checks:[
   {name:'location',state:'ready',detail:'Location verified'},
   {name:'reward',state:blocked?'limited':'ready',detail:'Reward verified'},
   {name:'claim_code',state:'ready',detail:'Claim code verified'},
   {name:'audience',state:'ready',detail:'Audience verified'}],
  blockers:blocked?['Readiness is limited. Complete the required setup.']:[],
  reward_count:1,landing_visibility:'unlisted',landing_published_on_activation:true,
  profile_publication_is_separate:true,may_enable_configured_automations:true,
  will_send_messages_directly:false,will_issue_rewards_directly:false,
  can_activate:!blocked,revision:blocked?'b'.repeat(64):'a'.repeat(64)
 };
};
async function scenario(width){
 const page=await browser.newPage({viewport:{width,height:900}});
 page.setDefaultTimeout(12000);
 const requests=[],errors=[];
 let activated=false,revoked=false,failConfirm=false,badPreview=false;
 page.on('pageerror',error=>errors.push(error.message));
 await page.route('https://vp3.test/chat.php',route=>route.fulfill({status:200,contentType:'text/html',
  body:'<!doctype html><html><head></head><body><main class="chat-main"><section id="chatThread" data-view="chat"><p class="message">Owner conversation</p></section><div id="chatComposerShell"><textarea id="chatInput"></textarea></div></main></body></html>'}));
 await page.route('https://vp3.test/api/initiative-workbench-v1.php',route=>{
  const input=route.request().postDataJSON()||{};requests.push(input);
  if(revoked)return route.fulfill({status:403,contentType:'application/json',
    body:JSON.stringify({ok:false,error:'Campaign permission changed'})});
  const data={ok:true,owner_key:'1',tool:'marketing',agent_id:7,conversation_id:11};
  if(input.action==='marketing_overview')Object.assign(data,{coverage:'ready',
    campaigns:baseCampaigns.map(c=>c.id===5&&activated?{...c,status:'active',version:1}:c),
    links:{campaigns:'/campaigns.php',products:'/profile-commerce-products.php',rewards:'/rewards.php'}});
  else if(input.action==='marketing_plans')Object.assign(data,{plans:[],next_before:null});
  else if(input.action==='marketing_activation_open'){
    Object.assign(data,{activation:makeEvidence(input.campaign_id)});
  }else if(input.action==='marketing_activation_preview'){
    if(input.revision!==(input.campaign_id===5?'a':'b').repeat(64))
      return route.fulfill({status:409,contentType:'application/json',
        body:JSON.stringify({ok:false,error:'Campaign changed'})});
    Object.assign(data,{activation:makeEvidence(input.campaign_id),
      review_token:badPreview?'bad-token':(input.campaign_id===6?null:'9999999999.'+'d'.repeat(64))});
  }else if(input.action==='marketing_activation_confirm'){
    if(failConfirm)return route.fulfill({status:409,contentType:'application/json',
      body:JSON.stringify({ok:false,error:'Current Campaign readiness changed'})});
    if(!input.confirmed||!input.request_key||!input.review_token?.startsWith('9999999999.'))
      return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({ok:false,error:'No approval'})});
    activated=true;
    Object.assign(data,{activated:true,campaign:{id:5,status:'active',name:'Coffee Welcome'},
      replayed:false,outbound_sent_by_this_action:false});
  } else return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({ok:false,error:'Unknown action'})});
  return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(data)});
 });
 await page.goto('https://vp3.test/chat.php');
 await page.evaluate(()=>{
  window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'owner-attention'};
  window.VP3_INITIATIVE_WORKBENCH={
    endpoint:'/api/initiative-workbench-v1.php',mediaEndpoint:'/api/media-library-v86.php',
    followthroughEndpoint:'/api/marketing-followthrough-v1.php',
    csrf:'owner-csrf',ownerKey:'1',agentId:7};
  const thread=document.getElementById('chatThread');
  window.fixture={conversation:11,participant:false};
  window.VP3ParticipantContinuity={selectedContext:()=>window.fixture.participant};
  window.STONEFELLOW_CHAT_CONTINUITY_V87={
    conversationId:()=>window.fixture.conversation,agentId:()=>7,isReady:()=>true,isRestoring:()=>false,
    showMarketingCanvasView:()=>{thread.dataset.view='marketing';window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));},
    showChatView:()=>{thread.dataset.view='chat';window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));},
    mediaCommandContext:()=>({ownerKey:'1',agentId:7,conversationId:window.fixture.conversation,
      isCurrent:()=>thread.dataset.view==='chat'&&window.fixture.conversation===11}),
    showTurnNotice:()=>{}
  };
 });
 await page.addStyleTag({content:css});
 for(const filename of scripts)await page.addScriptTag({content:read(filename)});
 const open=()=>page.evaluate(()=>window.VP3MediaCommands.open('marketing',
   window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext()));
 check(await open(),'Marketing opens inside Chat Canvas at '+width);
 await page.getByRole('tab',{name:'Campaigns'}).click();
 check(await page.getByRole('button',{name:'Review activation'}).count()===2,
   'Draft and scheduled Campaigns offer the exact activation review');
 await page.getByRole('button',{name:'Review activation'}).nth(1).click();
 const panel=page.locator('[data-marketing-activation]');
 await panel.waitFor();
 await page.waitForFunction(()=>document.querySelector('[data-marketing-activation]')?.textContent.includes('Activation blocked'));
 check(await panel.getByRole('button',{name:'Review activation'}).isDisabled(),
   'limited Campaign readiness blocks approval');
 check(requests.filter(x=>x.action==='marketing_activation_confirm').length===0,
   'blocked read never activates Campaign');
 await panel.getByRole('button',{name:'Back to campaigns'}).click();
 await page.getByRole('button',{name:'Review activation'}).first().click();
 await page.waitForFunction(()=>document.querySelector('[data-marketing-activation]')?.textContent.includes('Current readiness'));
 check((await panel.innerText()).includes('Public landing on activation: Yes'),
   'activation review prominently discloses eligible public landing exposure');
 check((await panel.innerText()).includes('automations may become eligible')
   &&(await panel.innerText()).includes('previously approved Profile placement'),
   'review discloses downstream automations and potential reappearance on Profile');
 check(requests.filter(x=>x.action==='marketing_activation_confirm').length===0,
   'opening activation review is read-only');
 await panel.getByRole('button',{name:'Review activation'}).click();
 await panel.getByRole('button',{name:'Confirm & activate Campaign'}).waitFor({state:'visible'});
 check((await panel.locator('.vp3-marketing-activation-approval').innerText())
   .includes('Previously approved Profile placements may become visible again'),
   'confirmation also warns that an existing Profile placement may reappear');
 check(requests.filter(x=>x.action==='marketing_activation_confirm').length===0,
   'signed preview does not change Campaign status');
 await panel.getByRole('button',{name:'Refresh readiness'}).click();
 check(await panel.getByRole('button',{name:'Confirm & activate Campaign'}).isHidden(),
   'new readiness read invalidates earlier approval');
 await page.waitForFunction(()=>document.querySelector('[data-marketing-activation]')?.textContent.includes('All current readiness checks'));
 badPreview=true;
 await panel.getByRole('button',{name:'Review activation'}).click();
 await page.waitForFunction(()=>document.querySelector('.vp3-marketing-activation-status')?.textContent.includes('changed'));
 check(await panel.getByRole('button',{name:'Confirm & activate Campaign'}).isHidden(),
   'malformed approval token cannot expose activation action');
 badPreview=false;
 await panel.getByRole('button',{name:'Review activation'}).click();
 await panel.getByRole('button',{name:'Confirm & activate Campaign'}).waitFor({state:'visible'});
 failConfirm=true;
 await panel.getByRole('button',{name:'Confirm & activate Campaign'}).click();
 await page.waitForFunction(()=>document.querySelector('.vp3-marketing-activation-status')?.textContent.includes('could not be verified'));
 check(requests.filter(x=>x.action==='marketing_activation_confirm').length===1&&!activated,
   'stale confirmation does not claim successful activation');
 failConfirm=false;
 await panel.getByRole('button',{name:'Review activation'}).click();
 await panel.getByRole('button',{name:'Confirm & activate Campaign'}).click();
 await page.waitForFunction(()=>!document.querySelector('[data-marketing-activation]'));
 check(activated&&requests.filter(x=>x.action==='marketing_activation_confirm').length===2,
   'one confirmed canonical lifecycle change after a failed review');
 check(requests.filter(x=>x.action==='marketing_activation_confirm')
   .every(x=>x.confirmed===true&&x.owner_key==='1'&&x.agent_id===7&&x.conversation_id===11
      &&x.csrf_token==='owner-csrf'&&x.revision==='a'.repeat(64)
      &&/^[a-f0-9]{32}$/.test(x.request_key)),
   'confirmation is signed, exact-Campaign and owner/Agent/Chat bound');
 await page.waitForFunction(()=>[...document.querySelectorAll('.vp3-marketing-workspace-card')]
   .some(card=>card.textContent.includes('Coffee Welcome')&&card.textContent.includes('Active')));
 check(await page.getByRole('button',{name:'Review activation'}).count()===1,
   'active Campaign cannot re-enter the activation review');
 check(!requests.some(x=>['publish','send','issue_reward','dispatch'].includes(x.action)),
   'Chat activation adds no second messaging, rewards or publication API');
 if(width===390) {
   const dims=await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,width:innerWidth}));
   check(dims.scroll<=dims.width+2,'390px activation review fits viewport without nested page overflow');
 }
 await page.getByRole('button',{name:'Review activation'}).click();
 await page.waitForFunction(()=>document.querySelector('[data-marketing-activation]')?.textContent.includes('Activation blocked'));
 revoked=true;
 await panel.getByRole('button',{name:'Refresh readiness'}).click();
 await page.waitForFunction(()=>document.getElementById('chatThread').dataset.view==='chat');
 check(!(await page.locator('#chatMarketingWorkspaceV1').textContent()).includes('Coffee Welcome'),
   'revoked access exits Chat Canvas and clears private Campaign details');
 check(errors.length===0,'no browser JS errors '+errors.join('; '));
 await page.close();
}
try {
 await scenario(1200);
 await scenario(390);
 console.log('Marketing Activation browser: '+checks+' checks passed');
}finally{await browser.close();}
