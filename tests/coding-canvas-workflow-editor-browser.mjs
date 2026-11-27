import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {createRequire} from 'node:module';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const id='10000000-0000-4000-8000-000000000001';
const bad={html:'<button id="add">Add one</button><p id="count">0</p>',css:'',js:''};
const fixed={...bad,js:'CanvasUI.text("#count",String(CanvasData.get().count||0));CanvasUI.on("#add","click",async()=>{await CanvasData.save({count:(CanvasData.get().count||0)+1});CanvasUI.text("#count",String(CanvasData.get().count));});'};
const initialPlan=[{name:'Add and save one',steps:[{action:'click',selector:'#add'}],expect:{kind:'text',selector:'#count',value:'1',match:'equals'},persist:true}];
async function scenario(repairFails=false){
 const page=await browser.newPage({viewport:{width:1280,height:900}});page.setDefaultTimeout(15000);
 const requests=[],errors=[];page.on('pageerror',e=>errors.push(e.message));
 const canvas={id,title:'Counter app',agent_id:7,conversation_id:42,active_revision:1,draft_revision:2};
 const state={revision:'a'.repeat(64),value:{count:17,kept:true}};
 const versions=new Map([[1,{artifact:fixed,checks:[]}],[2,{artifact:bad,checks:structuredClone(initialPlan)}]]);
 const reports=new Map();let job=null,polls=0,holdBegin=false,heldBegin=null;
 const detail=revision=>({canvas:{...canvas},revision:{id:revision,source_hash:String(revision).padStart(64,'0'),artifact:versions.get(revision).artifact,
   status:revision===1?'active':'draft',test_passed:revision===1||reports.get(revision)?.status==='passed',workflow_checks:versions.get(revision).checks,workflow_result:reports.get(revision)||null},
   revisions:[...versions.keys()].map(id=>({id,status:id===1?'active':'draft',test_passed:id===1||reports.get(id)?.status==='passed'})),state,
   workflow_testing:{ready:true,can_edit:true},source_editor:{ready:true,can_edit:true}});
 await page.route('https://checks.test/',r=>r.fulfill({contentType:'text/html',body:'<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><div class="chat-app"><aside class="chat-sidebar"></aside><main class="chat-main"><header class="chat-topbar"></header><section class="chat-thread" id="chatThread"></section><div class="chat-composer-shell" id="chatComposerShell"><form id="chatForm"><textarea id="chatInput"></textarea></form></div></main></div>'}));
 await page.route('https://checks.test/api/coding',r=>{
  const b=r.request().postDataJSON();requests.push(b);const respond=x=>r.fulfill({json:{ok:true,...x}});
  if(b.conversation_id!==42)return respond(b.action==='list'?{canvases:[]}:{job:null});
  switch(b.action){
   case 'list':return respond({canvases:[canvas]});case 'resume':return respond({job});
   case 'open':return respond(detail(b.revision||canvas.active_revision));
   case 'begin_check':{
    const current=reports.get(b.revision);
    if(current?.request_id!==b.request_id)reports.set(b.revision,{status:'running',request_id:b.request_id});
    if(holdBegin){heldBegin=()=>respond(detail(b.revision));return;}
    return respond(detail(b.revision));
   }
   case 'finish_check':reports.set(b.revision,{status:b.result.failed_case===null?'passed':'failed',request_id:b.request_id,result:b.result});return respond(detail(b.revision));
   case 'check_repair':
    job={run_id:77,canvas_id:id,conversation_id:42,state:'queued',revision:null,model:{model:'fixture-coder'},reason:'',operation:'repair',can_retry:false};return respond({job});
   case 'tick':job={...job,state:'running'};return respond({job});
   case 'job':
    if(++polls>=2){canvas.draft_revision=3;versions.set(3,{artifact:repairFails?bad:fixed,checks:structuredClone(initialPlan)});job={...job,state:'ready',revision:3};}
    return respond({job});
   case 'save_checks':{
    const revision=Math.max(...versions.keys())+1;canvas.draft_revision=revision;versions.set(revision,{artifact:versions.get(b.revision).artifact,checks:b.workflow_checks});return respond(detail(revision));
   }
   default:throw Error('Unexpected workflow editor request '+b.action);
  }
 });
 try{
  await page.goto('https://checks.test/');await page.evaluate(()=>{
   window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'fixture-owner'};window.VP3_CODING_CANVAS_V1={endpoint:'/api/coding',csrf:'fixture'};
   window.fixtureConversation=42;
   const show=view=>{document.getElementById('chatThread').dataset.view=view;window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));};
   window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>window.fixtureConversation,agentId:()=>7,isReady:()=>true,isRestoring:()=>false,
    showCodingCanvasView:()=>show('coding'),showChatView:()=>show('chat')};
  });
  for(const f of ['chat.css','chat-coding-canvas-v1.css'])await page.addStyleTag({content:await fs.readFile(new URL('../'+f,import.meta.url),'utf8')});
  for(const f of ['coding-canvas-runtime-v1.js','coding-canvas-workflow-runtime-v1.js','coding-canvas-diff-v1.js','assets/vendor/acorn-8.19.0.js','coding-canvas-source-editor-v1.js','coding-canvas-workflow-editor-v1.js','chat-coding-canvas-v1.js'])await page.addScriptTag({content:await fs.readFile(new URL('../'+f,import.meta.url),'utf8')});
  await page.evaluate(async id=>window.VP3CodingCanvasV1.open(id,2),id);
  await page.getByRole('button',{name:'Edit app',exact:true}).click();
  const panel=page.locator('#chatCodingCanvasV1'),workflow=panel.locator('.vp3-coding-workflow'),status=workflow.getByRole('status');
  await panel.getByRole('button',{name:'Test & repair',exact:true}).click();
  await status.getByText(repairFails?'A workflow check failed. Review the saved check and source.':'All 1 saved workflow checks passed.',{exact:false}).waitFor();
  assert.equal(requests.filter(x=>x.action==='check_repair').length,1,'One explicit cycle can admit only one repair');
  assert.equal(requests.filter(x=>x.action==='finish_check').length,2,'The repaired version is actually retested');
  assert.equal(requests.filter(x=>x.action==='finish_check')[0].result.reason,'handler_missing');
  assert.equal(requests.filter(x=>x.action==='finish_check')[1].revision,3);
  assert.deepEqual(state.value,{count:17,kept:true});assert.equal(canvas.active_revision,1,'The working version stays active throughout tests and repair');
  assert.equal(requests.some(x=>['save_state','apply','build','repair','retry','email_data','command_data'].includes(x.action)),false,'Workflow runs use sample data without live data writes or extra inference paths');
  if(!repairFails){
   assert.equal(reports.get(3).status,'passed');
   await workflow.getByRole('button',{name:'Record a workflow',exact:true}).click();
   await panel.frameLocator('iframe').getByRole('button',{name:'Add one'}).click();
   await workflow.getByLabel('Check name',{exact:true}).fill('Recorded add');
   await workflow.getByLabel('Expected visible text',{exact:true}).fill('1');
   await workflow.getByLabel('Must survive reloading the app',{exact:true}).check();
   await workflow.getByRole('button',{name:'Save workflow check',exact:true}).click();
   await status.getByText('Workflow check saved in version 4.',{exact:false}).waitFor();
   assert.equal(versions.get(4).checks.length,2);assert.deepEqual(versions.get(4).checks[1].steps,[{action:'click',selector:'#add'}]);
   await panel.getByRole('button',{name:'Test preview',exact:true}).click();
   await status.getByText('All 2 saved workflow checks passed.',{exact:false}).waitFor();
   assert.equal(requests.filter(x=>x.action==='check_repair').length,1,'Test preview alone never requests a paid repair');
   await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);
   await page.evaluate(()=>{window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.fixtureConversation=43;});
   assert.equal(await workflow.isVisible(),false,'Leaving the Chat clears its workflow UI');
  }else {
   assert.equal(reports.get(3).status,'failed','A failing retest remains a failed draft');
   holdBegin=true;
   await panel.getByRole('button',{name:'Test preview',exact:true}).click();
   for(let n=0;!heldBegin;n++){if(n>100)throw Error('Missing held check begin');await new Promise(resolve=>setTimeout(resolve,20));}
   await page.evaluate(()=>{window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.fixtureConversation=43;});
   await heldBegin();
   assert.equal(await workflow.isVisible(),false);
   assert.equal(requests.filter(x=>x.action==='finish_check').length,2,'A delayed test admission cannot finish or restore a previous Chat workflow');
  }
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
}
try{await scenario();await scenario(true);console.log('Canvas workflow editor passed: real recorded actions, disposable data, one explicit repair, preserved checks, retest, failed retest without another attempt, no-inference Test preview and responsive context fencing.');}
finally{await browser.close();}
