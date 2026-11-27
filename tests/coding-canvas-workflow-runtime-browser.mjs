import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {createRequire} from 'node:module';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const page=await browser.newPage({viewport:{width:1000,height:800}});page.setDefaultTimeout(15000);
const errors=[],external=[];page.on('pageerror',e=>errors.push(e.message));
await page.route('https://workflow.test/',r=>r.fulfill({contentType:'text/html',body:'<!doctype html><div id="frames"></div>'}));
await page.route('https://external.test/**',r=>{external.push(r.request().url());return r.abort();});
const artifact={html:'<label>Task<input id="task"></label><button id="add">Add task</button><p id="list"></p><label><input id="done" type="checkbox">Done</label><p id="status"></p>',css:'',js:`
  let title='';
  const render=()=>CanvasUI.text('#list',(CanvasData.get().tasks||[]).join(', '));render();
  CanvasUI.on('#task','input',event=>{title=event.value;});
  CanvasUI.on('#add','click',async()=>{await CanvasData.save({tasks:[...(CanvasData.get().tasks||[]),title]});render();});
  CanvasUI.on('#done','change',event=>CanvasUI.text('#status',event.checked?'Done':'Open'));
`};
const plan={name:'Save a task',steps:[{action:'fill',selector:'#task',value:'Sample task 🎨'},{action:'click',selector:'#add'}],
  expect:{kind:'text',selector:'#list',value:'Sample task 🎨',match:'equals'},persist:true};
try{
  await page.goto('https://workflow.test/');
  for(const file of ['coding-canvas-runtime-v1.js','coding-canvas-workflow-runtime-v1.js'])await page.addScriptTag({content:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
  await page.evaluate(()=>{
    window.runWorkflow=({artifact,check,state={},verifyOnly=false,noAck=false,mode='check'})=>new Promise((resolve,reject)=>{
      document.getElementById('frames').replaceChildren();
      const runtime=window.VP3CanvasRuntimeV1,nonce=runtime.token(),frame=document.createElement('iframe');
      const payload={channel:runtime.channel,nonce,parentOrigin:location.origin,workerKey:runtime.token(),artifact,state,
        maxText:60000,stateLimit:32000,canSaveState:true,email:null,gmail:null,sources:{},openableSources:{},workflowMode:mode};
      const messages=[];let saved={};
      const timer=setTimeout(()=>{removeEventListener('message',receive);reject(Error('No workflow result'));},13000);
      const receive=event=>{
        const data=event.data;
        if(event.source!==frame.contentWindow||event.origin!=='null'||data?.channel!==runtime.channel||data.nonce!==nonce)return;
        messages.push(data);
        if(data.kind==='save'){
          saved=JSON.parse(data.json);
          if(!noAck)frame.contentWindow.postMessage({channel:runtime.channel,nonce,kind:'saved',id:data.id,value:saved},'*');
        }
        if(data.kind==='ready'){
          if(mode==='record'){window.recordFrame=frame;window.recordMessages=messages;clearTimeout(timer);removeEventListener('message',receive);resolve({record:true,nonce,channel:runtime.channel});return;}
          frame.contentWindow.postMessage({channel:runtime.channel,nonce,kind:'workflow_run',run_id:'fixture-check',check,verify_only:verifyOnly},'*');
        }
        if(data.kind==='workflow_done'||data.kind==='error'){
          clearTimeout(timer);removeEventListener('message',receive);resolve({...data,saved,messages});
        }
      };
      addEventListener('message',receive);frame.setAttribute('sandbox','allow-scripts');frame.srcdoc=runtime.createDocument(payload);document.getElementById('frames').append(frame);
    });
  });
  const run=options=>page.evaluate(args=>window.runWorkflow(args),{artifact,check:plan,...options});
  const passed=await run({});assert.equal(passed.passed,true);assert.deepEqual(passed.saved,{tasks:['Sample task 🎨']});
  assert.equal((await run({state:passed.sample_state,verifyOnly:true})).passed,true,'The expected saved result survives a fresh runtime');
  assert.equal((await run({state:{},verifyOnly:true})).reason,'expectation_failed','Persistence is verified against reloaded sample data');
  const checked=await run({check:{name:'Mark done',steps:[{action:'check',selector:'#done',value:true}],expect:{kind:'text',selector:'#status',value:'Done',match:'equals'},persist:false}});
  assert.equal(checked.passed,true,'Checkbox workflows use the real worker callback bridge');
  const missing=await run({artifact:{...artifact,js:''},check:{...plan,persist:false}});assert.equal(missing.reason,'handler_missing');
  assert.equal((await run({check:{...plan,steps:[{action:'click',selector:'#missing'}]}})).reason,'target_missing');
  assert.equal((await run({check:{...plan,steps:[{action:'fill',selector:'#add',value:'wrong'}]}})).reason,'wrong_control');
  assert.equal((await run({artifact:{...artifact,js:'CanvasUI.on("#add","click",()=>{throw new Error("bad");});'},check:{...plan,steps:[{action:'click',selector:'#add'}]}})).reason,'source_runtime');
  assert.equal((await run({noAck:true})).reason,'timeout','A save without an acknowledgement cannot pass');
  assert.deepEqual(external,[],'The test runtime has no external fetch path');
  // Real trusted user input records actions. Programmatic untrusted events do not.
  const record=await run({mode:'record'});
  await page.evaluate(({nonce,channel})=>{
    window.recorded=[];
    addEventListener('message',event=>{if(event.source===window.recordFrame.contentWindow&&event.data?.nonce===nonce&&event.data?.channel===channel&&event.data.kind==='workflow_record')window.recorded=event.data.steps;});
  },record);
  const frame=page.frameLocator('iframe');await frame.getByLabel('Task', {exact:true}).fill('Recorded sample');await frame.getByRole('button',{name:'Add task'}).click();
  await page.waitForFunction(()=>window.recorded.length===2);
  const steps=await page.evaluate(()=>window.recorded);assert.deepEqual(steps,[{action:'fill',selector:'#task',value:'Recorded sample'},{action:'click',selector:'#add'}]);
  await frame.locator('#task').evaluate(el=>{el.value='Untrusted';el.dispatchEvent(new Event('input',{bubbles:true}));});
  assert.deepEqual(await page.evaluate(()=>window.recorded),steps,'Untrusted events cannot append recorded actions');
  assert.deepEqual(errors,[]);
  console.log('Canvas workflow runtime passed: real worker actions, acknowledged disposable saves, reload verification, bounded failure reasons, trusted recording and no external requests.');
}finally{await browser.close();}
