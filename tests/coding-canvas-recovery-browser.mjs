import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {createRequire} from 'node:module';

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const page=await browser.newPage({viewport:{width:1280,height:900}});
page.setDefaultTimeout(10000);
const requests=[],errors=[];
const id='10000000-0000-4000-8000-000000000001';
const canvas={id,title:'Saved checklist',agent_id:7,conversation_id:42,active_revision:1,draft_revision:2};
const artifact={html:'<h1>Saved checklist</h1><p>Your records were kept.</p>',css:'',js:''};
const state={revision:'a'.repeat(64),value:{tasks:[{id:'kept',title:'Existing task',status:'open'}]}};
let job=null,number=0,failPoll=false;
const admissions=new Map();
const createJob=()=>({run_id:++number,conversation_id:42,canvas_id:id,state:'queued',revision:null,model:{model:'fixture-coder'},can_retry:false,operation:'build',reason:''});
const records=action=>requests.filter(r=>r.action===action);
page.on('pageerror',error=>errors.push(error.message));
await page.route('https://recovery.test/',route=>route.fulfill({contentType:'text/html',body:`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
  <div class="chat-app"><aside class="chat-sidebar"></aside><main class="chat-main"><header class="chat-topbar"></header>
  <section class="chat-thread" id="chatThread"></section><div class="chat-composer-shell" id="chatComposerShell"><form id="chatForm"><textarea id="chatInput"></textarea></form></div></main></div>`}));
await page.route('https://recovery.test/api/coding',route=>{
  const body=route.request().postDataJSON();requests.push(body);
  assert.equal(body.csrf_token,'fixture');assert.equal(body.agent_id,7);
  const respond=value=>route.fulfill({json:{ok:true,...value}});
  if(body.conversation_id!==42)return respond(body.action==='list'?{canvases:[]}:{job:null});
  switch(body.action){
    case 'list':return respond({canvases:job?.state==='ready'?[canvas]:[]});
    case 'resume':return respond({job});
    case 'build':
      assert.match(body.request_id,/^[a-f0-9-]{36}$/);job=createJob();admissions.set(body.request_id,job);return respond({job});
    case 'retry':
      assert.equal(body.run_id,job.run_id);assert.equal(job.can_retry,true);job=createJob();admissions.set(body.request_id,job);return respond({job});
    case 'recover':return respond({job:admissions.get(body.request_id)?.run_id===job?.run_id?job:null});
    case 'tick':assert.equal(body.run_id,job.run_id);job={...job,state:'running'};return respond({job});
    case 'job':
      if(failPoll)return route.fulfill({status:419,json:{ok:false,error:'Refresh the page to restore this session.'}});
      assert.equal(body.run_id,job.run_id);return respond({job});
    case 'saved_request':return respond({request:{prompt:'Build a saved checklist',operation:'build',canvas_id:id,revision:null}});
    case 'open':return respond({canvas,revision:{id:2,status:'draft',test_passed:false,artifact},state,
      revisions:[{id:2,status:'draft',test_passed:false},{id:1,status:'active',test_passed:true}]});
    default:throw Error('Unexpected recovery action '+body.action);
  }
});

const panel=page.locator('#chatCodingCanvasV1');
const launch=page.getByRole('button',{name:'Code / App Canvas',exact:true});
const prompt=panel.getByLabel('Describe an app to build or a change to make');
const status=panel.locator('.vp3-coding-canvas-progress [role="status"]');
async function load(){
  await page.goto('https://recovery.test/');
  await page.evaluate(()=>{
    window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'fixture-owner'};
    window.VP3_CODING_CANVAS_V1={endpoint:'/api/coding',csrf:'fixture',agentId:7};
    window.fixtureConversation=42;
    const show=view=>{document.getElementById('chatThread').dataset.view=view;window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));};
    window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>window.fixtureConversation,agentId:()=>7,isReady:()=>true,isRestoring:()=>false,
      showCodingCanvasView:()=>show('coding'),showChatView:()=>show('chat')};
    // Control only API deadlines. Preview workers and polling retain their real timers.
    const set=window.setTimeout.bind(window),clear=window.clearTimeout.bind(window),deadlines=new Map();
    window.setTimeout=(fn,ms,...args)=>{
      if(ms!==15000&&ms!==110000)return set(fn,ms,...args);
      const id=set(()=>{},600000);deadlines.set(id,()=>fn(...args));return id;
    };
    window.clearTimeout=id=>{deadlines.delete(id);clear(id);};
    window.expireCanvasRequests=()=>{for(const [id,fn] of [...deadlines]){deadlines.delete(id);clear(id);fn();}};
    const fetch=window.fetch.bind(window);
    window.holdActions=[];window.releaseBodies=[];
    window.fetch=async (url,options)=>{
      const action=JSON.parse(options.body).action;
      if(action==='build'&&window.skipNextBuild){
        window.skipNextBuild=false;window.skippedBuild=JSON.parse(options.body);window.heldAction=action;
        return {ok:true,status:200,json:()=>new Promise(()=>{})};
      }
      const response=await fetch(url,options);
      if(!window.holdActions.includes(action))return response;
      const saved=await response.json();
      window.heldAction=action;
      return {ok:true,status:200,json:()=>new Promise(resolve=>window.releaseBodies.push(()=>resolve(saved)))};
    };
  });
  for(const file of ['chat.css','chat-coding-canvas-v1.css'])await page.addStyleTag({content:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
  await page.addScriptTag({content:await fs.readFile(new URL('../coding-canvas-runtime-v1.js',import.meta.url),'utf8')});
  await page.addScriptTag({content:await fs.readFile(new URL('../coding-canvas-diff-v1.js',import.meta.url),'utf8')});
  const resumed=page.waitForResponse(r=>r.request().postDataJSON()?.action==='resume');
  await page.addScriptTag({content:await fs.readFile(new URL('../chat-coding-canvas-v1.js',import.meta.url),'utf8')});
  await resumed;
  await launch.click();
}

try{
  await load();
  await page.evaluate(()=>window.skipNextBuild=true);
  await prompt.fill('Build a saved checklist');
  await panel.getByRole('button',{name:'Build new',exact:true}).click();
  await page.waitForFunction(()=>window.heldAction==='build');
  const uncertainRequest=await page.evaluate(()=>window.skippedBuild.request_id);
  await page.evaluate(()=>window.expireCanvasRequests());
  await status.getByText('No saved run was found for that request.',{exact:false}).waitFor();
  assert.equal(await prompt.inputValue(),'Build a saved checklist');
  assert.equal(records('build').length,0,'A request lost before admission has no server-side run');
  await page.evaluate(()=>{window.holdActions=['build'];window.heldAction=null;});
  await panel.getByRole('button',{name:'Build new',exact:true}).click();
  await page.waitForFunction(()=>window.heldAction==='build');
  assert.equal(records('build')[0].request_id,uncertainRequest,'Resubmitting an unconfirmed unchanged brief retains its original idempotency key');
  await prompt.fill('Keep this next change I am typing');
  await page.evaluate(()=>window.expireCanvasRequests());
  await status.getByText('Waiting to build · fixture-coder',{exact:true}).waitFor();
  assert.equal(records('build').length,1,'A lost response body must not trigger another build');
  assert.equal(records('recover').at(-1).request_id,records('build')[0].request_id,'Recovery looks up the exact original admission');
  assert.equal(await prompt.inputValue(),'Keep this next change I am typing','Late admission confirmation preserves a new unsent instruction');
  job={...job,state:'ready',revision:2};
  await status.getByText('Draft ready to test · fixture-coder',{exact:true}).waitFor();
  await panel.frameLocator('iframe').getByRole('heading',{name:'Saved checklist'}).waitFor();
  assert.equal(await panel.getByRole('listitem').filter({hasText:'Test'}).getAttribute('aria-current'),'step');
  assert.deepEqual(state.value.tasks,[{id:'kept',title:'Existing task',status:'open'}]);
  await panel.getByRole('button',{name:'Edit saved request',exact:true}).click();
  assert.equal(records('saved_request').length,0,'Loading a saved brief cannot overwrite personal text');
  await prompt.fill('');
  await page.evaluate(()=>{window.holdActions=['saved_request'];window.heldAction=null;});
  await panel.getByRole('button',{name:'Edit saved request',exact:true}).click();
  await page.waitForFunction(()=>window.heldAction==='saved_request');
  await page.evaluate(async id=>{await window.VP3CodingCanvasV1.open(id,2);},id);
  await page.evaluate(()=>{window.holdActions=[];window.releaseBodies.splice(0).forEach(resolve=>resolve());});
  await panel.getByRole('button',{name:'Edit app',exact:true}).click();
  assert.equal(await prompt.inputValue(),'','A late saved brief cannot change an app the owner navigated away from');
  await panel.getByRole('button',{name:'Edit saved request',exact:true}).click();
  await page.waitForFunction(()=>document.querySelector('#chatCodingCanvasV1 textarea').value==='Build a saved checklist');
  assert.equal(records('build').length,1,'Editing a saved brief cannot start generation');

  const beforeReload=records('build').length+records('tick').length;
  await load();
  await status.getByText('Draft ready to test · fixture-coder',{exact:true}).waitFor();
  await panel.frameLocator('iframe').getByRole('heading',{name:'Saved checklist'}).waitFor();
  await panel.getByRole('button',{name:'Open build result',exact:true}).click();
  await panel.frameLocator('iframe').getByRole('heading',{name:'Saved checklist'}).waitFor();
  assert.equal(records('build').length+records('tick').length,beforeReload,'Reload finds a completed draft without paid generation');

  job={...job,state:'failed',revision:null,can_retry:true,reason:'The model stopped before finishing the app. Edit the request into a smaller change or retry the saved request.'};
  await load();
  await status.getByText('The model stopped before finishing the app.',{exact:false}).waitFor();
  await prompt.fill('My separate new idea');
  await page.evaluate(()=>window.holdActions=['retry']);
  await panel.getByRole('button',{name:'Retry saved request',exact:true}).click();
  await page.waitForFunction(()=>window.heldAction==='retry');
  await page.evaluate(()=>window.expireCanvasRequests());
  await status.getByText('Waiting to build · fixture-coder',{exact:true}).waitFor();
  assert.equal(records('retry').length,1,'A lost retry response recovers its replacement instead of starting another');
  assert.equal(records('recover').at(-1).request_id,records('retry')[0].request_id);
  assert.equal(await prompt.inputValue(),'My separate new idea');
  await page.evaluate(()=>window.holdActions=['job']);
  await page.waitForFunction(()=>window.heldAction==='job');
  const buildsBeforeCheck=records('build').length+records('retry').length;
  await page.evaluate(()=>window.expireCanvasRequests());
  await status.getByText('Checking again…',{exact:false}).waitFor();
  await page.evaluate(()=>{window.holdActions=[];window.releaseBodies.splice(0).forEach(resolve=>resolve());});
  job={...job,state:'failed',can_retry:true,reason:'The Coding Agent run expired. You can start a new request.'};
  await panel.getByRole('button',{name:'Check build status',exact:true}).click();
  await status.getByText('The Coding Agent run expired.',{exact:false}).waitFor();
  assert.equal(records('build').length+records('retry').length,buildsBeforeCheck,'An interrupted status check never starts generation');

  const screenshots=process.env.VP3_CANVAS_RECOVERY_SCREENSHOT_DIR;
  if(screenshots){await fs.mkdir(screenshots,{recursive:true});await page.screenshot({path:screenshots+'/canvas-recovery-desktop.png',animations:'disabled'});}
  await page.setViewportSize({width:390,height:780});
  await panel.getByRole('button',{name:'Check build status',exact:true}).click();
  const layout=await panel.evaluate(el=>({width:el.getBoundingClientRect().width,scroll:el.scrollWidth,client:el.clientWidth,
    buttons:[...el.querySelectorAll('.vp3-coding-canvas-progress button')].filter(b=>!b.hidden).map(b=>b.getBoundingClientRect().height)}));
  assert.ok(layout.width<=390&&layout.scroll<=layout.client+1,'Recovery controls fit a narrow phone without horizontal scrolling');
  assert.ok(layout.buttons.every(height=>height>=44),'Recovery actions remain touch-sized: '+JSON.stringify(layout));
  if(screenshots)await page.screenshot({path:screenshots+'/canvas-recovery-phone.png',animations:'disabled'});

  await panel.getByRole('button',{name:'Retry saved request',exact:true}).click();
  await status.getByText('Waiting to build · fixture-coder',{exact:true}).waitFor();
  failPoll=true;
  await status.getByText('Refresh the page to restore this session.',{exact:false}).waitFor();
  const unauthorizedReads=records('job').length;
  await page.waitForTimeout(3000);
  assert.equal(records('job').length,unauthorizedReads,'A revoked session stops automatic polling instead of looping forever');
  failPoll=false;
  job={...job,state:'failed',can_retry:true,reason:'The Coding Agent run expired.'};
  await panel.getByRole('button',{name:'Check build status',exact:true}).click();
  await status.getByText('The Coding Agent run expired.',{exact:false}).waitFor();
  await page.evaluate(()=>{window.holdActions=['retry','recover'];window.heldAction=null;});
  await panel.getByRole('button',{name:'Retry saved request',exact:true}).click();
  await page.waitForFunction(()=>window.heldAction==='retry');
  await page.evaluate(()=>window.expireCanvasRequests());
  await page.waitForFunction(()=>window.heldAction==='recover');
  const ticksBeforeContextChange=records('tick').length;
  const contextResumed=page.waitForResponse(r=>r.request().postDataJSON()?.action==='resume'&&r.request().postDataJSON()?.conversation_id===43);
  await page.evaluate(()=>{
    window.fixtureConversation=43;
    window.dispatchEvent(new Event('stonefellow:chat-context-changing'));
    window.dispatchEvent(new Event('vp3:chat-context-ready'));
    window.releaseBodies.splice(0).forEach(resolve=>resolve());
  });
  await page.waitForFunction(()=>!document.querySelector('#chatCodingCanvasV1').innerText.includes('The Coding Agent run expired.'));
  await contextResumed;
  assert.equal(records('tick').length,ticksBeforeContextChange,'Late uncertain admission recovery cannot dispatch work into the newly selected Chat');
  assert.equal(await prompt.inputValue(),'','Changing Chat clears private editor instructions');
  assert.deepEqual(errors,[]);
  console.log('Canvas recovery browser passed: lost admission/body, exact retry recovery, reload, saved brief, preserved typing, stalled status, revoked session, Chat isolation and desktop/phone layout.');
}finally{await browser.close();}
