import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {createRequire} from 'node:module';

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const page=await browser.newPage({viewport:{width:1440,height:1000}});
page.setDefaultTimeout(10000);
const requests=[],errors=[];
const id='10000000-0000-4000-8000-000000000001',other='20000000-0000-4000-8000-000000000002';
const canvas={id,title:'Workshop checklist',agent_id:7,conversation_id:42,active_revision:1,draft_revision:3};
const state={revision:'a'.repeat(64),value:{done:false,records:['Kept personal record']}};
const html='<h1>Workshop checklist</h1>\n<label><input id="done" type="checkbox"> Packed</label>\n<p id="result"></p>';
const js="CanvasUI.checked('#done',Boolean(CanvasData.get().done));\nCanvasUI.on('#done','change',async ({checked})=>{await CanvasData.save({...CanvasData.get(),done:checked});CanvasUI.text('#result','Draft change kept');});";
const revisions=[
  {id:3,status:'draft',test_passed:false,can_rollback:false,artifact:{html:html.replace('Packed','Ready'),css:'h1 { color: #187561; }',js}},
  {id:2,status:'archived',test_passed:true,can_rollback:true,artifact:{html:html.replace('Packed','Archived'),css:'',js}},
  {id:1,status:'active',test_passed:true,can_rollback:false,artifact:{html,css:'',js}},
];
let comparisonFault=null;
const detail=(canvasId,revision)=>({canvas:canvasId===id?canvas:{...canvas,id:other,title:'Other private app',active_revision:null,draft_revision:1},
  revision:revisions.find(item=>item.id===(revision||1)),state,revisions:canvasId===id?revisions:[revisions.at(-1)]});
page.on('pageerror',error=>errors.push(error.message));
page.on('dialog',dialog=>{errors.push('Unexpected dialog');void dialog.dismiss();});
await page.route('https://workspace.test/',route=>route.fulfill({contentType:'text/html',body:`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
  <div class="chat-app"><aside class="chat-sidebar"></aside><main class="chat-main"><header class="chat-topbar"></header>
  <section class="chat-thread" id="chatThread"></section><div class="chat-composer-shell" id="chatComposerShell"><form id="chatForm"><textarea id="chatInput"></textarea></form></div></main></div>`}));
await page.route('https://workspace.test/api/coding',route=>{
  const body=route.request().postDataJSON();requests.push(body);
  if(process.env.VP3_TEST_DEBUG)console.log('workspace request',body.action,body.revision);
  assert.equal(body.csrf_token,'fixture');assert.equal(body.agent_id,7);
  const respond=value=>route.fulfill({json:{ok:true,...value}});
  switch(body.action){
    case 'list':return respond({canvases:body.conversation_id===42?[canvas]:[]});
    case 'resume':return respond({job:null});
    case 'open':{
      if(comparisonFault==='error'&&body.revision===1)return route.fulfill({status:503,json:{ok:false,error:'Saved version temporarily unavailable.'}});
      const result=structuredClone(detail(body.canvas_id,body.revision));
      if(comparisonFault==='stale'&&body.revision===1)result.canvas.active_revision=2;
      if(comparisonFault==='identity'&&body.revision===1)result.canvas.agent_id=8;
      return respond(result);
    }
    case 'test':
      assert.equal(body.revision,3);assert.equal(body.state_revision,state.revision);revisions[0].test_passed=true;
      return respond(detail(id,3));
    case 'apply':{
      assert.equal(body.expected_active_revision,canvas.active_revision);
      const selected=revisions.find(item=>item.id===body.revision);
      assert.ok(selected.test_passed);canvas.active_revision=selected.id;
      if(selected.id===canvas.draft_revision)canvas.draft_revision=null;
      for(const revision of revisions){revision.status=revision===selected?'active':revision.id===canvas.draft_revision?'draft':'archived';revision.can_rollback=revision!==selected&&revision.test_passed;}
      return respond(detail(id,selected.id));
    }
    default:throw Error('Unexpected workspace mutation '+body.action);
  }
});

const panel=page.locator('#chatCodingCanvasV1');
const tab=name=>panel.getByRole('tab',{name,exact:true});
const compare=panel.getByLabel('Compare with version');
const comparison=panel.locator('.vp3-coding-canvas-changes');
const source=panel.locator('.vp3-coding-canvas-source pre');
const check=panel.locator('.vp3-coding-canvas-preview-check');
const records=action=>requests.filter(item=>item.action===action);
async function open(canvasId=id,revision=3){
  assert.equal(await page.evaluate(async ({canvasId,revision})=>window.VP3CodingCanvasV1.open(canvasId,revision),{canvasId,revision}),true);
  await panel.getByRole('button',{name:'Edit app',exact:true}).click();
  const selected=page.waitForResponse(response=>response.request().postDataJSON()?.action==='open'&&response.request().postDataJSON()?.revision===revision);
  await panel.getByLabel('Version',{exact:true}).selectOption(String(revision));await selected;
  await tab('Preview').click();
  await panel.frameLocator('iframe').getByRole('heading',{name:'Workshop checklist'}).waitFor();
}
async function changes(){
  await tab('Changes').click();
  if(await compare.locator('option[value="1"]').count())await compare.selectOption('1');
  await comparison.getByRole('status').filter({hasText:/files? changed|identical saved source|first saved version|unavailable|saved versions changed/}).waitFor();
}
async function holdComparison(){
  await tab('Preview').click();
  await page.evaluate(()=>{window.holdComparison=true;window.comparisonHeld=false;});
  await tab('Changes').click();
  await page.waitForFunction(()=>window.comparisonHeld);
}
async function release(){await page.evaluate(()=>{window.holdComparison=false;window.releaseComparisons.splice(0).forEach(resolve=>resolve());});}
async function layout(width){
  await page.setViewportSize({width,height:900});
  const measurements=await panel.evaluate(el=>({
    width:el.getBoundingClientRect().width,scroll:el.scrollWidth,client:el.clientWidth,document:document.documentElement.scrollWidth,
    sourceOverflow:getComputedStyle(el.querySelector('.vp3-coding-canvas-source pre')).overflowY,
    tabs:[...el.querySelectorAll('[role="tab"]')].map(tab=>tab.getBoundingClientRect().height),
    pickers:[...el.querySelectorAll('.vp3-coding-canvas-pickers select')].map(select=>select.getBoundingClientRect().height),
    instructionTarget:el.querySelector('.vp3-coding-canvas-editor-controls > summary').getBoundingClientRect().height,
    editorColumns:getComputedStyle(el.querySelector('.vp3-coding-canvas-editor-layout')).gridTemplateColumns.split(' ').length,
  }));
  assert.ok(measurements.width<=width&&measurements.scroll<=measurements.client+1&&measurements.document<=width+1,'No horizontal overflow: '+JSON.stringify(measurements));
  assert.equal(measurements.sourceOverflow,'visible','Source uses the Chat thread scroll rather than a nested scroller');
  assert.ok(measurements.tabs.every(height=>height>=44),'Tabs retain touch-sized targets');
  if(width<=1100)assert.ok(measurements.pickers.every(height=>height>=44)&&measurements.instructionTarget>=44,'Phone selectors and instructions retain touch-sized targets: '+JSON.stringify(measurements));
  assert.equal(measurements.editorColumns,width>1100?2:1);
  assert.equal(await page.locator('#chatComposerShell').isVisible(),true,'The native Chat footer stays available in the Canvas editor');
}

try{
  await page.goto('https://workspace.test/');
  await page.evaluate(()=>{
    window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'fixture-owner'};
    window.VP3_CODING_CANVAS_V1={endpoint:'/api/coding',csrf:'fixture',agentId:7};
    window.fixtureConversation=42;
    const show=view=>{document.getElementById('chatThread').dataset.view=view;window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));};
    window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>window.fixtureConversation,agentId:()=>7,isReady:()=>true,isRestoring:()=>false,
      showCodingCanvasView:()=>show('coding'),showChatView:()=>show('chat')};
    const fetch=window.fetch.bind(window);window.releaseComparisons=[];
    window.fetch=async (url,options)=>{
      const body=JSON.parse(options.body),response=await fetch(url,options);
      if(!window.holdComparison||body.action!=='open'||body.revision!==1||body.canvas_id!=='10000000-0000-4000-8000-000000000001')return response;
      const saved=await response.json();window.comparisonHeld=true;
      return {ok:true,status:200,json:()=>new Promise(resolve=>window.releaseComparisons.push(()=>resolve(saved)))};
    };
  });
  for(const file of ['chat.css','chat-coding-canvas-v1.css'])await page.addStyleTag({content:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
  for(const file of ['coding-canvas-runtime-v1.js','coding-canvas-diff-v1.js','assets/vendor/acorn-8.19.0.js','coding-canvas-source-editor-v1.js','chat-coding-canvas-v1.js'])await page.addScriptTag({content:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
  await open();
  assert.equal(await tab('Preview').getAttribute('aria-selected'),'true');
  assert.equal(await panel.getByRole('button',{name:'Use this version',exact:true}).isDisabled(),true,'A new draft cannot be used before the native preview test');
  await check.getByText('Run Test preview',{exact:false}).waitFor();
  await panel.frameLocator('iframe').getByLabel('Ready',{exact:true}).check();
  await panel.frameLocator('iframe').getByText('Draft change kept',{exact:true}).waitFor();
  await panel.locator('iframe').evaluate(el=>el.dataset.retained='same-frame');
  const beforeReview=requests.length;
  await changes();
  assert.equal(await compare.inputValue(),'1','Changes defaults to the saved working version');
  await comparison.getByText('Version 1 → 3 · 2 files changed · +2 / −1 lines',{exact:true}).waitFor();
  assert.ok((await comparison.locator('[data-change="add"]').allTextContents()).some(line=>line.includes('Ready')));
  await comparison.getByRole('button',{name:'View CSS source',exact:true}).click();
  assert.equal(await tab('Source').getAttribute('aria-selected'),'true');
  assert.equal(await source.textContent(),revisions[0].artifact.css);
  await panel.getByLabel('Source language').selectOption('js');
  assert.equal(await source.textContent(),js);
  await tab('Source').press('Home');
  assert.equal(await tab('Preview').getAttribute('aria-selected'),'true');
  assert.equal(await panel.locator('iframe').getAttribute('data-retained'),'same-frame','Tab switches preserve the exact preview element');
  assert.equal(await panel.frameLocator('iframe').getByLabel('Ready',{exact:true}).isChecked(),true,'Draft interaction state survives tab switches');
  assert.ok(requests.slice(beforeReview).every(item=>['open','list','resume'].includes(item.action)),'Review is read-only and cannot test, apply, generate or save app records');
  assert.deepEqual(state.value,{done:false,records:['Kept personal record']});
  await tab('Preview').press('ArrowRight');
  await comparison.getByText('Version 1 → 3',{exact:false}).waitFor();
  await compare.selectOption('2');
  await comparison.getByText('Version 2 → 3',{exact:false}).waitFor();
  await tab('Changes').press('End');
  assert.equal(await tab('Source').getAttribute('aria-selected'),'true');

  const hostile='<img src="https://leak.test/private" onerror="window.leaked=true">';
  revisions[0].artifact.html+='\n'+hostile;
  await open();await changes();
  assert.ok((await comparison.textContent()).includes(hostile),'Saved markup is displayed literally');
  assert.equal(await comparison.locator('img,iframe,script').count(),0,'Comparison never inserts saved markup as HTML');
  await tab('Source').click();
  await panel.getByLabel('Source language').selectOption('html');
  assert.equal(await source.textContent(),revisions[0].artifact.html);
  await layout(1440);await layout(390);await layout(320);
  const screenshots=process.env.VP3_CANVAS_WORKSPACE_SCREENSHOT_DIR;
  if(screenshots){
    await fs.mkdir(screenshots,{recursive:true});await page.setViewportSize({width:1440,height:1000});await changes();
    await page.screenshot({path:screenshots+'/canvas-workspace-desktop.png',animations:'disabled'});
  }
  await page.setViewportSize({width:390,height:900});await open(other,1);await open();await changes();
  assert.equal(await panel.locator('.vp3-coding-canvas-editor-controls').getAttribute('open'),null,'Phone review starts with instructions collapsed');
  if(screenshots)await page.screenshot({path:screenshots+'/canvas-workspace-phone.png',animations:'disabled'});
  const instructions=panel.locator('.vp3-coding-canvas-editor-controls');
  await instructions.evaluate(el=>el.open=true);
  await instructions.locator(':scope > summary').click();
  assert.equal(await panel.getByLabel('Describe an app to build or a change to make').isVisible(),false);
  await instructions.locator(':scope > summary').click();
  const prompt=panel.getByLabel('Describe an app to build or a change to make');
  await prompt.fill('Keep this unfinished instruction');await open(other,1);await open();
  assert.equal(await prompt.isVisible(),true,'Opening a saved app on a phone keeps unfinished instructions visible');
  assert.equal(await prompt.inputValue(),'Keep this unfinished instruction');await prompt.fill('');
  await page.setViewportSize({width:1440,height:1000});

  for(const fault of ['stale','identity','error']){
    comparisonFault=fault;await tab('Preview').click();await changes();
    await comparison.getByText(fault==='error'?'Saved version temporarily unavailable.':'The saved versions changed. Reload the selected version before comparing again.',{exact:true}).waitFor();
    assert.equal(await comparison.locator('.vp3-coding-canvas-diff-row').count(),0);
    comparisonFault=null;await panel.getByRole('button',{name:'Reload selected version',exact:true}).click();
    await comparison.getByText('Version 1 → 3',{exact:false}).waitFor();
  }

  await holdComparison();await tab('Source').click();await release();
  assert.equal(await comparison.locator('.vp3-coding-canvas-diff-row').count(),0,'Leaving Changes discards late private comparison text');
  await holdComparison();await open(other,1);await release();await changes();
  await comparison.getByText('This is the first saved version.',{exact:false}).waitFor();
  assert.equal(await compare.isDisabled(),true);
  assert.equal(await comparison.locator('.vp3-coding-canvas-diff-row').count(),0,'A late response cannot cross Canvas selection');
  await open();await holdComparison();
  await panel.getByLabel('Version',{exact:true}).selectOption('2');await release();
  await comparison.getByText('Version 1 → 2',{exact:false}).waitFor();
  assert.ok(!(await comparison.textContent()).includes(hostile),'A late comparison cannot cross revision selection');
  await open();await holdComparison();
  await panel.getByRole('button',{name:'Back to Chat',exact:true}).click();await release();
  assert.equal(await comparison.locator('.vp3-coding-canvas-diff-row').count(),0,'Closing the editor clears private comparison results');
  assert.equal(await page.locator('#chatComposerShell').isVisible(),true,'Returning to Chat restores the composer');

  await open();await holdComparison();
  await page.evaluate(()=>{window.fixtureConversation=43;window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.dispatchEvent(new Event('vp3:chat-context-ready'));});
  await release();assert.equal(await source.textContent(),'');assert.equal(await comparison.textContent(),'Compare with versionReload selected version');
  await page.evaluate(()=>{window.fixtureConversation=42;window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.dispatchEvent(new Event('vp3:chat-context-ready'));});
  await open();await holdComparison();
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:9}})));
  await release();assert.equal(await source.textContent(),'');assert.equal(await comparison.locator('.vp3-coding-canvas-diff-row').count(),0);
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:0}})));
  await open();await holdComparison();
  await page.evaluate(()=>{window.STONEFELLOW_CHAT.agentId=8;window.dispatchEvent(new Event('stonefellow:chat-context-changing'));});
  await release();assert.equal(await source.textContent(),'');assert.equal(await comparison.locator('.vp3-coding-canvas-diff-row').count(),0,'Agent changes discard an earlier private comparison');
  await page.evaluate(()=>{window.STONEFELLOW_CHAT.agentId=7;window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.dispatchEvent(new Event('vp3:chat-context-ready'));});

  const savedHtml=revisions[0].artifact.html;
  revisions[0].artifact.html=html+'\n'+Array.from({length:1600},(_,i)=>'<!-- New line '+i+' -->').join('\n');
  await open();await changes();
  assert.equal(await comparison.locator('.vp3-coding-canvas-diff-row').count(),800);
  await comparison.getByText('further lines are omitted',{exact:false}).waitFor();
  await tab('Source').click();assert.equal(await source.textContent(),revisions[0].artifact.html,'Source retains the complete artifact after the comparison rendering limit');
  revisions[0].artifact.html=savedHtml;await open();
  const testResponse=page.waitForResponse(response=>response.request().postDataJSON()?.action==='test');
  await panel.getByRole('button',{name:'Test preview',exact:true}).click();await testResponse;
  await check.getByText('Startup check passed.',{exact:false}).waitFor();
  assert.equal(records('apply').length,0,'Passing a test never activates the draft');
  await panel.getByRole('button',{name:'Use this version',exact:true}).click();
  await panel.locator('.vp3-coding-canvas-version-status').getByText('Working version 3',{exact:false}).waitFor();
  assert.equal(records('apply').length,1);
  await panel.getByLabel('Version',{exact:true}).selectOption('2');
  await panel.getByRole('button',{name:'Restore this version',exact:true}).waitFor();
  await panel.getByRole('button',{name:'View working version',exact:true}).click();
  await panel.locator('.vp3-coding-canvas-version-status').getByText('Reviewing version 3',{exact:false}).waitFor();
  assert.equal(records('apply').length,1,'Viewing the working version is a read');
  await panel.getByLabel('Version',{exact:true}).selectOption('2');
  const restore=panel.getByRole('button',{name:'Restore this version',exact:true});
  await page.waitForFunction(()=>[...document.querySelectorAll('#chatCodingCanvasV1 button')].some(button=>button.textContent==='Restore this version'&&!button.disabled));
  await restore.click();
  await panel.locator('.vp3-coding-canvas-version-status').getByText('Working version 2',{exact:false}).waitFor();
  assert.equal(records('apply').length,2);assert.deepEqual(state.value,{done:false,records:['Kept personal record']});
  assert.equal(records('save_state').length,0);
  assert.deepEqual(errors,[]);
  console.log('Canvas editor workspace passed: readonly comparison, retained preview state, exact source, accessible tabs, stale/late scope fences, bounded rendering, phone layout and explicit test/use/restore.');
}catch(error){
  if(process.env.VP3_TEST_DEBUG){console.log('Page errors',errors);console.log((await panel.textContent()).slice(0,5000));}
  throw error;
}finally{await browser.close();}
