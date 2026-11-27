import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {createRequire} from 'node:module';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const page=await browser.newPage({viewport:{width:1280,height:900}});page.setDefaultTimeout(12000);
const id='10000000-0000-4000-8000-000000000001',requests=[],errors=[];
const artifact={html:'<h1>New received app</h1>',css:'h1{color:green}',js:'const value="🎨";'};
const full=JSON.stringify({title:'New received app',...artifact});
let text='{"title":"New app","html":"<h1>Writing',state='running',loseSave=true,holdSource=false;
let held=null;
const canvas={id,title:'Working app',agent_id:7,conversation_id:42,active_revision:1,draft_revision:2};
const job=()=>({run_id:19,conversation_id:42,canvas_id:id,state,revision:null,operation:'repair',can_retry:state==='failed',
  reason:state==='failed'?'The provider response timed out. It may have used API tokens.':'',model:{model:'fixture-coder'},live_source_available:true,
  provider_attempt:{status:state==='failed'?'unknown':'pending',usage_known:false,tokens:null,duration_ms:state==='failed'?81000:0}});
await page.route('https://live.test/',route=>route.fulfill({contentType:'text/html',body:'<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><div class="chat-app"><aside class="chat-sidebar"></aside><main class="chat-main"><header class="chat-topbar"></header><section class="chat-thread" id="chatThread"></section><div class="chat-composer-shell" id="chatComposerShell"><form id="chatForm"><textarea id="chatInput"></textarea></form></div></main></div>'}));
await page.route('https://live.test/api/coding',async route=>{
  const body=route.request().postDataJSON();requests.push(body);
  const respond=value=>route.fulfill({json:{ok:true,...value}});
  if(body.conversation_id!==42)return respond(body.action==='list'?{canvases:[]}:{job:null});
  switch(body.action){
    case 'list':return respond({canvases:[]});
    case 'resume':case 'job':return respond({job:job()});
    case 'received_source':
      if(holdSource){held=()=>respond({received_source:{run_id:19,text,terminal:false,can_recover:true}});return;}
      return respond({received_source:{run_id:19,text,terminal:false,can_recover:state==='failed'}});
    case 'recover_source':
      if(loseSave){loseSave=false;return route.abort('connectionreset');}
      return respond({canvas,revision:{id:2,status:'draft',test_passed:false,artifact,source_hash:'c'.repeat(64)},
        state:{revision:'a'.repeat(64),value:{kept:true}},revisions:[{id:2,status:'draft',test_passed:false},{id:1,status:'active',test_passed:true}],source_editor:{ready:true,can_edit:true}});
    default:throw Error('Unexpected live-source action '+body.action);
  }
});
page.on('pageerror',error=>errors.push(error.message));
try{
  await page.goto('https://live.test/');
  await page.evaluate(()=>{
    window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'fixture-owner'};
    window.VP3_CODING_CANVAS_V1={endpoint:'/api/coding',csrf:'fixture',usageUrl:'/ai-usage.php'};
    window.fixtureConversation=42;
    const show=view=>{document.getElementById('chatThread').dataset.view=view;window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));};
    window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>window.fixtureConversation,agentId:()=>7,isReady:()=>true,isRestoring:()=>false,
      showCodingCanvasView:()=>show('coding'),showChatView:()=>show('chat')};
  });
  for(const file of ['chat.css','chat-coding-canvas-v1.css'])await page.addStyleTag({content:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
  for(const file of ['coding-canvas-runtime-v1.js','coding-canvas-diff-v1.js','assets/vendor/acorn-8.19.0.js','coding-canvas-source-editor-v1.js','coding-canvas-live-source-v1.js','chat-coding-canvas-v1.js'])await page.addScriptTag({content:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
  const panel=page.locator('#chatCodingCanvasV1'),live=page.locator('.vp3-coding-live-source'),code=live.getByLabel('Live generated source');
  await page.getByRole('button',{name:'Code / App Canvas',exact:true}).click();
  await code.getByText('<h1>Writing',{exact:true}).waitFor();
  assert.equal(requests.filter(x=>x.action==='received_source').length>0,true,'Live source starts automatically when the owner opens the editor');
  text=JSON.stringify({title:'Partial',html:'<img src=x onerror="window.LIVE_CODE_EXECUTED=true">'}).slice(0,-1);
  await page.waitForFunction(()=>document.querySelector('[aria-label="Live generated source"]')?.textContent.includes('onerror'));
  assert.equal(await page.evaluate(()=>window.LIVE_CODE_EXECUTED),undefined,'Live code is rendered as text, never executed');
  assert.equal(await live.locator('img').count(),0);
  text=full;await page.waitForFunction(()=>document.querySelector('[aria-label="Live generated source"]')?.textContent==='<h1>New received app</h1>');
  await live.getByLabel('Live code language').selectOption('js');
  assert.equal(await code.textContent(),artifact.js,'Escapes and Unicode are decoded for the selected source language');
  state='failed';
  await panel.getByText('Provider status: unknown',{exact:false}).waitFor();
  await panel.getByRole('link',{name:'AI usage',exact:true}).waitFor();
  assert.match(await panel.locator('.vp3-coding-canvas-progress').textContent(),/81\.0 s.*Token usage not confirmed/);
  const prompt=panel.getByLabel('Describe an app to build or a change to make');
  const save=live.getByRole('button',{name:'Save received code as draft',exact:true});
  await save.waitFor();await prompt.fill('Keep my next instruction');assert.equal(await save.isDisabled(),true,'A received import cannot replace an unsent instruction');
  await prompt.fill('');await save.click();
  await live.getByRole('button',{name:'Recover saved draft',exact:true}).click();
  await panel.frameLocator('iframe').getByRole('heading',{name:'New received app'}).waitFor();
  const recover=requests.filter(x=>x.action==='recover_source');
  assert.equal(recover.length,2);assert.equal(recover[0].request_id,recover[1].request_id,'A lost import response reuses the exact save request');
  assert.equal(requests.some(x=>['build','repair','retry','test','apply','save_state'].includes(x.action)),false,'Viewing and importing code never triggers inference, activation or a live data write');
  await page.setViewportSize({width:390,height:844});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),true,'Live source wraps on phone widths');
  holdSource=true;await live.getByRole('button',{name:'Refresh received code'}).click();
  for(let i=0;!held;i++){if(i>100)throw Error('Missing held source response');await new Promise(resolve=>setTimeout(resolve,20));}
  await page.evaluate(()=>{window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.fixtureConversation=43;});
  await held();
  assert.equal(await live.isVisible(),false,'A delayed received response cannot restore private code after the Chat changes');
  assert.equal(await code.textContent(),'','Context changes clear received source');
  assert.deepEqual(errors,[]);
  console.log('Canvas live source browser passed: automatic updates, language switching, unexecuted source, inline unknown usage, loss-safe import, no new inference, responsive layout and context fencing.');
}finally{await browser.close();}
