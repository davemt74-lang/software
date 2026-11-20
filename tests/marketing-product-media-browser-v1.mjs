import assert from 'node:assert/strict';
import fs from 'node:fs';
import {chromium} from 'playwright';

const src=name=>fs.readFileSync(new URL('../'+name,import.meta.url),'utf8');
const css=src('chat-marketing-workspace-v1.css')+'\n'+src('chat-marketing-product-media-v1.css');
const scripts=['chat-marketing-workspace-v1.js','chat-marketing-product-media-v1.js','chat-media-commands-v1.js'];
const args={headless:true,args:['--no-sandbox']};
if(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH)args.executablePath=process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const browser=await chromium.launch(args);
let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label)};
const validProducts=[{id:12,title:'Coffee package'},{id:25,title:'Gift bundle'}];
const validMedia=[{id:'16',title:'Coffee package photo'},{id:'17',title:'Gift box photo'}];
async function scenario(width) {
 const page=await browser.newPage({viewport:{width,height:900}});page.setDefaultTimeout(12000);
 const requests=[],errors=[];
 let revoked=false,dropPhoto=false;
 page.on('pageerror',err=>errors.push(err.message));
 await page.route('https://vp3.test/chat.php',route=>route.fulfill({status:200,contentType:'text/html',
   body:'<!doctype html><html><head></head><body><main class="chat-main"><section id="chatThread" data-view="chat"><div class="message">Private Chat</div></section><div id="chatComposerShell"><textarea id="chatInput"></textarea></div></main></body></html>'}));
 await page.route('https://vp3.test/api/initiative-workbench-v1.php',route=>{
   const body=route.request().postDataJSON()||{};requests.push(body);
   if(revoked)return route.fulfill({status:403,contentType:'application/json',
     body:JSON.stringify({ok:false,error:'Owner account access changed'})});
   const data={ok:true,tool:'marketing',owner_key:'1',agent_id:7,conversation_id:11};
   if(body.action==='marketing_overview')Object.assign(data,{
     coverage:'ready',campaigns:[],
     links:{campaigns:'/campaigns.php',products:'/profile-commerce-products.php',rewards:'/rewards.php'}
   });
   else if(body.action==='marketing_plans')Object.assign(data,{plans:[],next_before:null});
   else if(body.action==='marketing_product_media')Object.assign(data,{
     selection:{coverage:'ready',action:'private_marketing_draft',will_publish:false,
       products:validProducts,media:dropPhoto?validMedia.slice(0,1):validMedia}
   });
   else return route.fulfill({status:422,contentType:'application/json',
     body:JSON.stringify({ok:false,error:'Unsupported action'})});
   return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(data)});
 });
 await page.goto('https://vp3.test/chat.php');
 await page.evaluate(()=>{
   window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'attention'};
   window.VP3_INITIATIVE_WORKBENCH={
     endpoint:'/api/initiative-workbench-v1.php',mediaEndpoint:'/api/media-library-v86.php',
     followthroughEndpoint:'/api/marketing-followthrough-v1.php',
     csrf:'owner-csrf',ownerKey:'1',agentId:7};
   const thread=document.getElementById('chatThread');
   window.fixture={cid:11,handoffs:[],notices:[]};
   window.VP3ParticipantContinuity={selectedContext:()=>false};
   window.STONEFELLOW_CHAT_CONTINUITY_V87={
     conversationId:()=>window.fixture.cid,agentId:()=>7,isReady:()=>true,isRestoring:()=>false,
     showMarketingCanvasView:()=>{thread.dataset.view='marketing';window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));},
     showChatView:()=>{thread.dataset.view='chat';window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));},
     mediaCommandContext:()=>({ownerKey:'1',agentId:7,conversationId:window.fixture.cid,
       isCurrent:()=>thread.dataset.view==='chat'&&window.fixture.cid===11}),
     showTurnNotice:text=>window.fixture.notices.push(text)
   };
   window.VP3InitiativeWorkbench={
     open:(tool,source,revision,options)=>{
       window.fixture.handoffs.push({tool,source,revision,options,view:thread.dataset.view});return true;
     }
   };
 });
 await page.addStyleTag({content:css});
 for(const script of scripts)await page.addScriptTag({content:src(script)});
 const open=()=>page.evaluate(()=>window.VP3MediaCommands.open('marketing',
   window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext()));
 check(await open(),'Marketing opens in Chat Canvas at '+width);
 await page.getByRole('tab',{name:'Creative & Rewards'}).click();
 await page.getByRole('button',{name:'Choose product & photo'}).click();
 const panel=page.locator('[data-marketing-product-media]');
 await panel.waitFor();
 const product=panel.getByRole('combobox',{name:'Product'});
 const photo=panel.getByRole('combobox',{name:'Saved promotional photo'});
 await page.waitForFunction(()=>document.querySelectorAll('[data-marketing-product-media] select')[1]?.options.length===3);
 check(await product.locator('option').count()===3&&await photo.locator('option').count()===3,
   'owner products and valid Media Library photos loaded without file URLs');
 check(requests.filter(x=>x.action==='marketing_product_media').length===1,
   'product/Media selections read once on entry with current Chat authority');
 await product.selectOption('12');await photo.selectOption('16');
 await panel.getByRole('button',{name:'Review selection'}).click();
 check((await panel.locator('.vp3-marketing-product-media-review').innerText()).includes('Coffee package photo'),
   'review displays exact product and saved photo titles');
 check((await page.evaluate(()=>window.fixture.handoffs.length))===0,
   'opening picker and preview never creates a draft');
 await photo.selectOption('17');
 check(await panel.getByRole('button',{name:'Continue to private marketing draft'}).isHidden(),
   'changing media after review invalidates confirmation');
 await panel.getByRole('button',{name:'Review selection'}).click();
 dropPhoto=true;
 await panel.getByRole('button',{name:'Continue to private marketing draft'}).click();
 await page.waitForFunction(()=>document.querySelector('.vp3-marketing-product-media-status')?.textContent.includes('changed'));
 check((await page.evaluate(()=>window.fixture.handoffs.length))===0
    &&(await page.locator('#chatThread').getAttribute('data-view'))==='marketing',
   'removed photo fails current-source revalidation and stays private');
 dropPhoto=false;
 await panel.getByRole('button',{name:'Refresh products & photos'}).click();
 await page.waitForFunction(()=>document.querySelectorAll('[data-marketing-product-media] select')[1]?.options.length===3);
 await product.selectOption('25');await photo.selectOption('17');
 await panel.getByRole('button',{name:'Review selection'}).click();
 await panel.getByRole('button',{name:'Continue to private marketing draft'}).click();
 await page.waitForFunction(()=>window.fixture.handoffs.length===1);
 const handoff=await page.evaluate(()=>window.fixture.handoffs[0]);
 check(handoff.tool==='marketing'&&handoff.source==='17'&&handoff.revision===''
   &&handoff.options.productId===25&&handoff.view==='chat',
   'confirmed selection hands off exact product and photo to existing private Marketing workbench');
 check(await page.locator('#chatThread').getAttribute('data-view')==='chat',
   'Marketing leaves the workspace before displaying Longfellow workbench');
 check(!requests.some(x=>/publish|send|issue|create/.test(x.action)),
   'selection uses read-only requests, never publishing or issuing');
 check(requests.every(x=>x.owner_key==='1'&&x.csrf_token==='owner-csrf'&&x.agent_id===7
   &&x.conversation_id===11),'all selections are bound to current owner, Agent and Chat');
 check(errors.length===0,'no browser errors '+errors.join('; '));
 if(width===390){
   check(await open(),'mobile Chat can reopen Marketing');
   await page.getByRole('tab',{name:'Creative & Rewards'}).click();
   await page.getByRole('button',{name:'Choose product & photo'}).click();
   await page.waitForFunction(()=>document.querySelectorAll('[data-marketing-product-media] select')[1]?.options.length===3);
   const size=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth}));
   check(size.scroll<=size.width+2,'390px picker fits without horizontal page overflow');
 }
 // Freshly revoked access must clear selections and return to original Chat.
 if(width!==390){
   check(await open(),'Marketing can reopen after a successful private workbench handoff');
   await page.getByRole('tab',{name:'Creative & Rewards'}).click();
   await page.getByRole('button',{name:'Choose product & photo'}).click();
   await page.waitForFunction(()=>document.querySelectorAll('[data-marketing-product-media] select')[1]?.options.length===3);
 }
 revoked=true;
 await page.locator('[data-marketing-product-media]').getByRole('button',{name:'Refresh products & photos'}).click();
 await page.waitForFunction(()=>document.getElementById('chatThread').dataset.view==='chat');
 check(!(await page.locator('#chatMarketingWorkspaceV1').textContent()).includes('Gift bundle'),
   'permission revocation clears previously loaded private product names');
 check((await page.evaluate(()=>window.fixture.handoffs.length))===1,
   'revocation cannot trigger any additional Marketing draft handoff');
 await page.close();
}
try {
 await scenario(1200);
 await scenario(390);
 console.log('Marketing Product & Media picker: '+checks+' browser checks passed');
} finally { await browser.close(); }
