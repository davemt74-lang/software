import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {chromium} from 'playwright';

const path=file=>new URL('../'+file,import.meta.url);
const files=['chat-marketing-workspace-v1.css','chat-marketing-editor-v1.css',
  'chat-marketing-workspace-v1.js','chat-marketing-editor-v1.js','chat-media-commands-v1.js'];
const content=Object.fromEntries(files.map(file=>[file,readFileSync(path(file),'utf8')]));
const executablePath=process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],...(executablePath?{executablePath}:{})});
let checks=0;const check=(truth,label)=>{assert.ok(truth,label);checks++;console.log('PASS '+label)};
const catalog={merchants:[{id:7,name:'Owner Coffee'}],merchant_id:7,
  types:[{key:'signup',label:'Signup',description:'Registration',requires_reward:false,default_cta:'Join'}],
  rewards:[{id:3,name:'Free coffee',type:'Product'}],locations:[{id:4,name:'Flagship'}],limited:false};
const originalFields={title:'Existing draft',campaign_type:'signup',slug:'existing-draft',subtitle:'',cta_label:'Join',
  objective:'Welcome',description:'Original copy',terms:'',starts_at:'',ends_at:'',location_id:0,reward_ids:[3]};
const campaign={id:5,merchant_id:7,status:'draft',revision:'f'.repeat(64),fields:originalFields,
  manage_url:'/campaigns.php?merchant=7&edit_campaign=5#campaign-editor'};
async function scenario(width){
  const page=await browser.newPage({viewport:{width,height:900}});page.setDefaultTimeout(12000);
  const requests=[],errors=[];
  let block=false,review=null;
  page.on('pageerror',err=>errors.push(err.message));
  await page.route('https://vp3.test/chat.php',route=>route.fulfill({status:200,contentType:'text/html',
    body:'<!doctype html><html><head></head><body><main class="chat-main"><section id="chatThread" data-view="chat"><p class="message">Owner conversation</p></section><div id="chatComposerShell"><textarea id="chatInput"></textarea></div></main></body></html>'}));
  await page.route('https://vp3.test/api/initiative-workbench-v1.php',async route=>{
    const payload=route.request().postDataJSON()||{};requests.push(payload);
    if(block)return route.fulfill({status:403,contentType:'application/json',
      body:JSON.stringify({ok:false,error:'Owner permission changed'})});
    const data={ok:true,owner_key:'1',agent_id:7,conversation_id:11,tool:'marketing'};
    if(payload.action==='marketing_overview')Object.assign(data,{coverage:'ready',campaigns:[{
      id:5,name:'Existing draft',business:'Owner Coffee',status:'draft',
      readiness:'ready',window_state:'not_open',issues:[],
      observations:{claims:'unavailable',redemptions:'unavailable',promotion:'unavailable'},
      href:'/campaigns.php?merchant=7&edit_campaign=5'}],links:{
      campaigns:'/campaigns.php',products:'/profile-commerce-products.php',rewards:'/rewards.php'}});
    else if(payload.action==='marketing_plans')Object.assign(data,{plans:[],next_before:null});
    else if(payload.action==='marketing_editor_options')Object.assign(data,{catalog});
    else if(payload.action==='marketing_editor_open')Object.assign(data,{catalog,draft:campaign});
    else if(payload.action==='marketing_editor_preview'){
      review=payload;
      Object.assign(data,{preview:{title:payload.fields.title,campaign_type:'signup',description:payload.fields.description,
        subtitle:payload.fields.subtitle,cta_label:payload.fields.cta_label,reward_count:payload.fields.reward_ids.length,
        starts_at:payload.fields.starts_at,ends_at:payload.fields.ends_at,status:'draft',will_publish:false},
        review_token:'9999999999.'+'a'.repeat(64)});
    } else if(payload.action==='marketing_editor_save'){
      if(!review||!payload.confirmed||payload.review_token!=='9999999999.'+'a'.repeat(64)
        ||JSON.stringify(payload.fields)!==JSON.stringify(review.fields)
        ||payload.request_key!==review.request_key) {
        return route.fulfill({status:422,contentType:'application/json',
          body:JSON.stringify({ok:false,error:'Review changed'})});
      }
      Object.assign(data,{saved:true,published:false,draft:{id:payload.campaign_id||101,merchant_id:7,status:'draft',
        revision:'e'.repeat(64),fields:payload.fields}});
    } else return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({ok:false,error:'Unknown action'})});
    return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(data)});
  });
  await page.goto('https://vp3.test/chat.php');
  await page.evaluate(()=>{
    window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'bind'};
    window.VP3_INITIATIVE_WORKBENCH={endpoint:'/api/initiative-workbench-v1.php',
      mediaEndpoint:'/api/media-library-v86.php',followthroughEndpoint:'/api/marketing-followthrough-v1.php',
      csrf:'csrf-test',ownerKey:'1',agentId:7};
    const thread=document.getElementById('chatThread');
    window.fixture={cid:11,participant:false};
    window.VP3ParticipantContinuity={selectedContext:()=>window.fixture.participant};
    window.STONEFELLOW_CHAT_CONTINUITY_V87={
      conversationId:()=>window.fixture.cid,agentId:()=>7,isReady:()=>true,isRestoring:()=>false,
      showMarketingCanvasView:()=>{thread.dataset.view='marketing';window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));},
      showChatView:()=>{thread.dataset.view='chat';window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));},
      mediaCommandContext:()=>({ownerKey:'1',agentId:7,conversationId:window.fixture.cid,
        isCurrent:()=>thread.dataset.view==='chat'&&window.fixture.cid===11}),
      showTurnNotice:()=>{}
    };
  });
  await page.addStyleTag({content:content['chat-marketing-workspace-v1.css']+'\n'+content['chat-marketing-editor-v1.css']});
  for(const filename of files.filter(x=>x.endsWith('.js')))await page.addScriptTag({content:content[filename]});
  const open=()=>page.evaluate(()=>window.VP3MediaCommands.open('marketing',
    window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext()));
  check(await open(),'Marketing Chat Canvas opens at '+width);
  await page.getByRole('tab',{name:'Campaigns'}).click();
  await page.getByRole('button',{name:'Create private Campaign'}).click();
  const form=page.locator('[data-marketing-editor] form');
  await form.locator('[name="title"]').waitFor();
  await page.waitForFunction(()=>document.querySelector('[data-marketing-editor] select')?.options.length>0);
  await form.locator('[name="title"]').fill('Coffee launch');
  await form.locator('[name="description"]').fill('Owner reviewed coffee promotion');
  await form.locator('.vp3-marketing-editor-reward input').check();
  await form.locator('[name="slug"]').fill('coffee-launch');
  check(await form.locator('.vp3-marketing-editor-reward input').count()===1,'approved Reward selector loaded');
  await page.getByRole('button',{name:'Review draft'}).click();
  await page.getByRole('button',{name:'Confirm & save private draft'}).waitFor({state:'visible'});
  check(requests.some(r=>r.action==='marketing_editor_preview')&&!requests.some(r=>r.action==='marketing_editor_save'),
    'draft preview does not write or publish');
  await form.locator('[name="subtitle"]').fill('Updated subtitle');
  check(await page.getByRole('button',{name:'Confirm & save private draft'}).isHidden(),
    'editing after review invalidates confirmation');
  await page.getByRole('button',{name:'Review draft'}).click();
  await page.getByRole('button',{name:'Confirm & save private draft'}).click();
  await page.waitForFunction(()=>!document.querySelector('[data-marketing-editor]'));
  check(requests.filter(r=>r.action==='marketing_editor_save').length===1,'one confirmed canonical draft save');
  check(requests.find(r=>r.action==='marketing_editor_save').fields.reward_ids.join(',')==='3',
    'Campaign draft included approved Reward selection');
  await page.getByRole('button',{name:'Edit private draft'}).click();
  await page.waitForFunction(()=>document.querySelector('[data-marketing-editor] input[name="title"]')?.value==='Existing draft');
  check(requests.some(r=>r.action==='marketing_editor_open'&&r.campaign_id===5),
    'existing Campaign opens by exact persisted draft revision');
  check(await form.locator('[name="title"]').inputValue()==='Existing draft','saved editable fields restored');
  if(width===390){
    const layout=await page.evaluate(()=>({document:document.documentElement.scrollWidth,viewport:innerWidth}));
    check(layout.document<=layout.viewport+2,'390px view has no horizontal overflow');
  }
  await page.getByRole('button',{name:'Back to campaigns'}).click();
  block=true;
  await page.getByRole('button',{name:'Create private Campaign'}).click();
  await page.waitForFunction(()=>document.getElementById('chatThread').dataset.view==='chat');
  check(!(await page.locator('#chatMarketingWorkspaceV1').textContent()).includes('Existing draft'),
    'revoked owner access exits and clears the private workspace');
  check(requests.every(r=>r.owner_key==='1'&&r.agent_id===7&&r.conversation_id===11),
    'every editor request is bound to exact owner Agent and Chat');
  check(!requests.some(r=>['campaign_publish','creative_publish','reward_issue','send'].includes(r.action)),
    'inline Draft Editor cannot publish, send or issue Rewards');
  check(errors.length===0,'no runtime errors: '+errors.join('; '));
  await page.close();
}
try{await scenario(1180);await scenario(390);console.log('Marketing Draft Editor browser: '+checks+' checks passed');}
finally{await browser.close();}
