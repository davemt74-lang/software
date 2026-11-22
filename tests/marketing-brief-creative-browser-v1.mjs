import assert from 'node:assert/strict';
import {readFileSync,mkdirSync} from 'node:fs';
import {chromium} from 'playwright';

const executablePath=process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],...(executablePath?{executablePath}:{})});
const artifact='artifacts/marketing-brief-creative';mkdirSync(artifact,{recursive:true});
let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const sourceRef='marketing:product-plan:10',sourceRevision='b'.repeat(64);
const binding={campaign_id:5,brief_id:9,campaign_revision:'f'.repeat(64)};
const next='Prepare a private creative option.';
const brief={contract:'vp3.marketing.private-brief.v1',state:'private_proposal_only',
  source_ref:sourceRef,source_revision:sourceRevision,source_coverage:'ready',
  objective:'Introduce the studio mug to local visitors.',deliverables:['Review product photo','Prepare private creative'],
  audience_decision:'Confirm the intended audience.',budget_decision:'Obtain an explicit spending approval.',
  channel_decision:'Choose the channel with the owner.',measurement:'Use independently recorded observations.',
  approval_gate:'Owner approval is required.',evidence_note:'Private planning proposal.',next_step:next,
  chat_prompt:`Source ${sourceRef} revision ${sourceRevision}. Do not send, publish, launch.`,
  creative_generated:false,campaign_created:false,activated:false,sent:false};
const catalog={merchants:[{id:7,name:'Studio Store'}],merchant_id:7,
  types:[{key:'signup',label:'Signup',default_cta:'Join'}],rewards:[],locations:[],limited:false};
const copy={kind:'product_marketing_draft',draft_id:'d'.repeat(64),product_id:10,asset_id:31,
  asset_sha256:'c'.repeat(64),title:'A mug for the studio',description:'Meet the ceramic studio mug.',
  campaign_idea:'Prepare a private studio introduction.',image_url:'/media-library-file-v86.php?id=31',
  review_url:'/profile-commerce-products.php?workspace_product=10#product-marketing-drafts',campaign_brief:binding};
const graphic={id:'e'.repeat(32),draft_id:copy.draft_id,product_id:10,asset_id:32,status:'saved',level:1,
  source_image_url:copy.image_url,image_url:'/media-library-file-v86.php?id=32',campaign_id:null,
  headline:copy.title,copy:copy.description,prompt:'',style:'clean',settings:{level:1,headline:copy.title,
    copy:copy.description,prompt:'',style:'clean',remove_background:false,tolerance:24},variants:[]};

async function scenario(width){
  const page=await browser.newPage({viewport:{width,height:950}});page.setDefaultTimeout(15000);
  const requests=[],errors=[];let campaign=null,review=null,copySaved=false;
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://vp3.test/chat.php',route=>route.fulfill({contentType:'text/html',body:
    '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>'+
    '<body><div id="chatToolsContent"></div><main class="chat-main"><section id="chatThread" data-view="chat">'+
    '<p>Owner conversation</p></section><div id="chatComposerShell"><textarea id="chatInput"></textarea></div></main></body></html>'}));
  await page.route('https://vp3.test/media-library-file-v86.php?**',route=>route.fulfill({contentType:'image/png',
    body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=','base64')}));
  await page.route('https://vp3.test/api/initiative-workbench-v1.php',async route=>{
    const body=route.request().postDataJSON();requests.push(body);
    const flags=await page.evaluate(()=>window.fixture);
    if(flags.denied)return route.fulfill({status:403,contentType:'application/json',body:JSON.stringify({ok:false,error:'Campaign permission changed'})});
    let result={ok:true,owner_key:'1',agent_id:7,conversation_id:11,tool:'marketing'};
    switch(body.action){
      case 'marketing_overview':Object.assign(result,{coverage:'ready',campaigns:campaign?[{
        id:5,name:campaign.fields.title,business:'Studio Store',status:'draft',version:0,
        readiness:'needs_setup',window_state:'not_open',issues:[],observations:{},has_saved_brief:true,
        href:'/campaigns.php?merchant=7&edit_campaign=5#campaign-editor'}]:[],
        opportunity_portfolio:{contract:'vp3.marketing.opportunity-portfolio.v1',coverage:'ready',
          review_scope:'current_authorized_sources_only',opportunities:[{
            source_ref:sourceRef,source_revision:sourceRevision,source_id:10,kind:'product-plan',
            label:'Product marketing',title:'Studio mug launch',finding:'Review the saved listing and original photo.',
            next_step:next,question:'',priority:'normal',source_status:'marketing_review',coverage:'ready',
            source_href:'/chat.php?agent=7&conversation_id=11&marketing_plan=10',notification_owner:'product_launch',private_brief:brief}]},
        links:{campaigns:'/campaigns.php',products:'/profile-commerce-products.php',rewards:'/rewards.php'}});break;
      case 'marketing_plans':Object.assign(result,{plans:[],next_before:null});break;
      case 'marketing_editor_options':Object.assign(result,{catalog});break;
      case 'marketing_editor_preview':
        assert.deepEqual(body.brief_source,{source_ref:sourceRef,source_revision:sourceRevision});review=body;
        Object.assign(result,{preview:{...body.fields,status:'draft',will_publish:false,source_brief:'Studio mug launch'},
          review_token:'9999999999.'+'a'.repeat(64)});break;
      case 'marketing_editor_save':
        assert.ok(body.confirmed);assert.deepEqual(body.brief_source,review.brief_source);assert.deepEqual(body.fields,review.fields);
        campaign={id:5,merchant_id:7,status:'draft',revision:binding.campaign_revision,fields:body.fields};
        Object.assign(result,{saved:true,published:false,draft:campaign});break;
      case 'marketing_brief_open':
        assert.equal(body.campaign_id,5);
        Object.assign(result,{brief_work:{contract:'vp3.marketing.campaign-brief.v1',binding,campaign_id:5,
          title:campaign.fields.title,objective:campaign.fields.objective,source_ref:sourceRef,
          source_revision:sourceRevision,source_title:'Studio mug launch',brief_objective:brief.objective,
          state:flags.ready?'creative_ready':copySaved?'copy_saved':'brief_saved',private_only:true,
          href:'/campaigns.php?merchant=7&edit_campaign=5#campaign-editor',resume:copySaved?{
            product_id:10,draft_id:copy.draft_id,source_id:'31',creative_id:flags.ready?graphic.id:''}:null}});break;
      case 'marketing_product_media':Object.assign(result,{selection:{coverage:'ready',action:'private_marketing_draft',will_publish:false,
        products:[{id:10,title:'Studio mug'},{id:20,title:'Other product'}],media:[{id:'31',title:'Original mug photo'}]}});break;
      case 'sources':Object.assign(result,{sources:[{source_id:'31',source_title:'Original mug photo'}],products:[{id:10,title:'Studio mug'}]});break;
      case 'preview':
        assert.deepEqual(body.campaign_brief,binding);
        Object.assign(result,{preview:{source_id:'31',source_title:'Original mug photo',revision:'c'.repeat(64),details:{
          image_url:copy.image_url,summary:'Product: Studio mug',drafts:[],
          owner_review:'Campaign: '+campaign.fields.title+'. Objective: '+campaign.fields.objective}},review_token:'review-copy'});break;
      case 'create':
        assert.deepEqual(body.campaign_brief,binding);assert.equal(body.confirmed,true);assert.equal(body.review_token,'review-copy');
        copySaved=true;Object.assign(result,{output:copy});break;
      case 'creative_open':
        assert.equal(body.draft_id,copy.draft_id);assert.equal(body.product_id,10);assert.equal(body.source_id,'31');
        if(body.creative_id)assert.equal(body.creative_id,graphic.id);
        Object.assign(result,{draft:{...copy,cta:'View product',product_url:'',management_url:'/profile-commerce-products.php?workspace_product=10'},
          creative:flags.ready?graphic:null,variants:flags.ready?[graphic]:[],promotion:null,campaigns:[]});break;
      default:throw new Error('Unexpected or implicit action '+body.action);
    }
    await route.fulfill({contentType:'application/json',body:JSON.stringify(result)});
  });
  await page.goto('https://vp3.test/chat.php');
  await page.evaluate(()=>{
    window.fixture={ready:false,denied:false,conversation:11};
    window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'bound'};
    window.VP3_INITIATIVE_WORKBENCH={endpoint:'/api/initiative-workbench-v1.php',mediaEndpoint:'/api/media-library-v86.php',
      followthroughEndpoint:'/api/marketing-followthrough-v1.php',csrf:'csrf',ownerKey:'1',agentId:7};
    const show=view=>{document.querySelector('#chatThread').dataset.view=view;
      window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));};
    const bound=()=>({ownerKey:'1',agentId:7,conversationId:window.fixture.conversation,
      isCurrent:()=>window.fixture.conversation===11});
    window.STONEFELLOW_CHAT_CONTINUITY_V87={conversationId:()=>window.fixture.conversation,agentId:()=>7,
      isReady:()=>true,isRestoring:()=>false,showChatView:()=>show('chat'),showMarketingCanvasView:()=>show('marketing'),
      mediaCommandContext:bound,showTurnNotice:()=>{}};
    window.VP3ParticipantContinuity={selectedContext:()=>null};
  });
  await page.addStyleTag({content:'*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif}.chat-main{max-width:1200px;margin:auto}'});
  for(const name of ['chat-marketing-workspace-v1.css','chat-marketing-editor-v1.css','chat-marketing-product-media-v1.css','chat-initiative-workbench-v1.css'])
    await page.addStyleTag({content:readFileSync(new URL('../'+name,import.meta.url),'utf8')});
  for(const name of ['chat-marketing-editor-v1.js','chat-marketing-product-media-v1.js','chat-marketing-workspace-v1.js',
    'chat-marketing-canvas-v1.js','chat-initiative-workbench-v1.js'])
    await page.addScriptTag({content:readFileSync(new URL('../'+name,import.meta.url),'utf8')});
  check(await page.evaluate(()=>window.VP3MarketingWorkspaceV1.open(window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext())),
    'current owner Marketing workspace opens at '+width);
  await page.getByText('Develop private creative brief',{exact:true}).click();
  await page.getByRole('button',{name:'Start private Campaign draft from brief'}).click();
  const editor=page.locator('[data-marketing-editor]');
  await page.waitForFunction(()=>document.querySelector('[data-marketing-editor] [name="title"]')?.value==='Studio mug launch');
  check(!requests.some(r=>r.action==='marketing_editor_save'||r.action==='create'),'opening a brief or editor makes no write or provider request');
  await editor.getByRole('button',{name:'Review draft',exact:true}).click();
  await editor.getByRole('button',{name:'Confirm & save private draft'}).click();
  const panel=page.locator('.vp3-marketing-campaign-brief');
  await panel.getByText('Ready to prepare creative',{exact:true}).waitFor();
  check(requests.filter(r=>r.action==='marketing_editor_save').length===1,
    'reviewed Campaign save carries the source and returns to its private creative brief');
  await page.screenshot({path:`${artifact}/brief-${width}.png`,fullPage:true});
  await panel.getByRole('button',{name:'Choose product & photo for Campaign'}).click();
  const picker=page.locator('[data-marketing-product-media]');
  await page.waitForFunction(()=>document.querySelector('[data-marketing-product-media] select')?.value==='10');
  check(await picker.locator('select').first().inputValue()==='10','the brief retains its product selection');
  await picker.locator('select').nth(1).selectOption('31');
  await picker.getByRole('button',{name:'Review selection',exact:true}).click();
  await picker.getByRole('button',{name:'Continue to private marketing draft'}).click();
  const workbench=page.locator('dialog[open]');
  await workbench.getByRole('button',{name:'Review source',exact:true}).click();
  await workbench.getByText(/Campaign: Studio mug launch\. Objective:/).waitFor();
  check(!requests.some(r=>r.action==='create'),'Campaign, product and photo review still has no model request');
  await workbench.getByRole('button',{name:'Save private marketing draft',exact:true}).click();
  await workbench.getByRole('button',{name:'Design promotion in Canvas'}).click();
  const canvas=page.locator('[data-marketing-canvas]');
  await canvas.getByRole('button',{name:'Return to Campaign'}).waitFor();
  check(requests.filter(r=>r.action==='create').length===1&&await canvas.getByRole('button',{name:'Review publication'}).isHidden(),
    'one owner-confirmed private copy opens the existing Canvas with Campaign publication controls withheld');
  await canvas.getByRole('button',{name:'Return to Campaign'}).click();
  await panel.getByText('Private copy saved',{exact:true}).waitFor();
  check(await panel.getByRole('button',{name:'Continue saved Campaign creative'}).count()===1,
    'return to Campaign restores the exact saved private copy');
  await page.evaluate(()=>{window.fixture.ready=true;});
  await panel.getByRole('button',{name:'Refresh Campaign creative'}).click();
  await panel.getByText('Creative ready for review',{exact:true}).waitFor();
  await panel.getByRole('button',{name:'Continue saved Campaign creative'}).click();
  await canvas.getByText('Saved privately',{exact:true}).first().waitFor();
  check(await canvas.getByRole('button',{name:'Review publication'}).isHidden()
    &&await canvas.getByRole('button',{name:'Return to Campaign'}).isVisible(),
    'canonical saved graphic reopens for private owner review and keeps its Campaign return');
  const fit=await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2);
  check(fit,'Campaign creative Canvas fits the '+width+'px viewport');
  await page.screenshot({path:`${artifact}/creative-${width}.png`,fullPage:true});
  await canvas.getByRole('button',{name:'Return to Campaign'}).click();
  await panel.getByText('Creative ready for review',{exact:true}).waitFor();
  await page.screenshot({path:`${artifact}/review-${width}.png`,fullPage:true});
  await page.evaluate(()=>{window.fixture.denied=true;});
  await panel.getByRole('button',{name:'Refresh Campaign creative'}).click();
  await page.waitForFunction(()=>document.querySelector('#chatThread').dataset.view==='chat');
  check(await page.locator('.vp3-marketing-campaign-brief').count()===0,'revoked permission clears previously visible private Campaign creative');
  check(requests.filter(r=>r.action==='create').length===1
    &&!requests.some(r=>['creative_generate','creative_publish','creative_publish_preview','marketing_activation_confirm'].includes(r.action)),
    'opening, restoring and returning never generates images, publishes or activates implicitly');
  check(errors.length===0,'combined Campaign, workbench and Canvas flow has no browser exception');
  await page.close();
}
try{await scenario(1200);await scenario(390);console.log(`MARKETING_BRIEF_CREATIVE_BROWSER=PASS (${checks} checks)`);}
finally{await browser.close();}
