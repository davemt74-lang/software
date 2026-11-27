import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {createRequire} from 'node:module';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const page=await browser.newPage({viewport:{width:1440,height:1000}});page.setDefaultTimeout(10000);
const errors=[],requests=[],id='30000000-0000-4000-8000-000000000001';
const canvas={id,title:'Workshop counter',agent_id:7,conversation_id:42,active_revision:1,draft_revision:null};
const state={revision:'a'.repeat(64),value:{count:0,records:['Keep this record']}};
const html='<h1>Workshop counter 🎨</h1>\n<label><input id="increment" type="checkbox"> Increment</label>\n<p id="count"></p>';
const js='// Counter 🎨\nCanvasUI.text("#count",String(CanvasData.get().count));\nCanvasUI.on("#increment","change",async()=>{const data={...CanvasData.get(),count:CanvasData.get().count+1};await CanvasData.save(data);CanvasUI.text("#count",String(data.count));});';
const revisions=[{id:1,artifact:{html,css:'h1 { color: #187561; }',js},test_passed:true,ever:true}];
const saves=new Map();
let job=null,holdJob=false,jobNumber=0,canEdit=true;
const records=action=>requests.filter(r=>r.action===action);
const hash=artifact=>createHash('sha256').update(JSON.stringify(artifact)).digest('hex');
const detail=revision=>{
  const selected=revisions.find(r=>r.id===(revision||canvas.active_revision||canvas.draft_revision));
  const info=r=>({id:r.id,source_hash:hash(r.artifact),test_passed:r.test_passed,can_rollback:r.ever&&r.id!==canvas.active_revision,
    status:r.id===canvas.active_revision?'active':r.id===canvas.draft_revision?'draft':'saved',commands:[]});
  return structuredClone({canvas,state,source_editor:{ready:true,can_edit:canEdit},revision:{...info(selected),artifact:selected.artifact},revisions:[...revisions].reverse().map(info)});
};
function newDraft(artifact){const revision={id:revisions.at(-1).id+1,artifact:structuredClone(artifact),test_passed:false,ever:false};revisions.push(revision);canvas.draft_revision=revision.id;return revision.id;}
page.on('pageerror',e=>errors.push(e.message));
await page.route('https://source-editor.test/',route=>route.fulfill({contentType:'text/html',body:'<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><div class="chat-app"><aside class="chat-sidebar"></aside><main class="chat-main"><header class="chat-topbar"></header><section class="chat-thread" id="chatThread"></section><div class="chat-composer-shell" id="chatComposerShell"><form id="chatForm"><textarea id="chatInput"></textarea></form></div></main></div>'}));
await page.route('https://source-editor.test/api/coding',route=>{
  const body=route.request().postDataJSON();requests.push(body);
  assert.equal(body.agent_id,7);assert.equal(body.csrf_token,'fixture');
  const ok=value=>route.fulfill({json:{ok:true,...value}});
  const fail=error=>route.fulfill({status:409,json:{ok:false,error}});
  switch(body.action){
    case 'list':return ok({canvases:[canvas]});
    case 'resume':return ok({job:job?.conversation_id===body.conversation_id?job:null});
    case 'open':return ok(detail(body.revision));
    case 'save_source':{
      assert.equal(body.canvas_id,id);assert.equal(body.source_hash,hash(revisions.find(r=>r.id===body.revision).artifact));
      const signature=JSON.stringify(body),old=saves.get(body.request_id);
      if(old){assert.equal(old.signature,signature,'Recovery replays the exact original source save');return ok({...detail(old.revision),source_saved:true});}
      if(body.expected_active_revision!==canvas.active_revision||body.expected_draft_revision!==canvas.draft_revision)return fail('This app has a newer draft or working version. Your edits were kept; reopen the latest version before saving.');
      const revision=newDraft(body.artifact);saves.set(body.request_id,{signature,revision});
      return ok({...detail(revision),source_saved:true});
    }
    case 'test':revisions.find(r=>r.id===body.revision).test_passed=true;return ok(detail(body.revision));
    case 'apply':{
      assert.equal(body.expected_active_revision,canvas.active_revision);
      const selected=revisions.find(r=>r.id===body.revision);assert.equal(selected.test_passed,true);
      canvas.active_revision=selected.id;selected.ever=true;if(canvas.draft_revision===selected.id)canvas.draft_revision=null;
      return ok(detail(selected.id));
    }
    case 'repair':
      job={run_id:++jobNumber,conversation_id:body.conversation_id,canvas_id:id,state:'queued',revision:null,model:{model:'fixture-coder'},can_retry:false};
      return ok({job});
    case 'tick':job={...job,state:'running'};return ok({job});
    case 'job':
      if(!holdJob&&job.state==='running'){
        const previous=records('repair').at(-1).revision,artifact=structuredClone(revisions.find(r=>r.id===previous).artifact);
        artifact.html=artifact.html.replace(/<h1>.*?<\/h1>/,'<h1>Agent updated counter 🎨</h1>');
        job={...job,state:'ready',revision:newDraft(artifact)};
      }
      return ok({job});
    default:throw Error('Unexpected source editor action '+body.action);
  }
});
const panel=page.locator('#chatCodingCanvasV1'),source=panel.locator('.vp3-coding-canvas-source');
const language=panel.getByLabel('Source language'),editor=()=>source.getByRole('textbox');
const tab=name=>panel.getByRole('tab',{name,exact:true});
const save=source.getByRole('button',{name:'Save source draft',exact:true});
async function open(revision=1){
  assert.equal(await page.evaluate(async ({id,revision})=>window.VP3CodingCanvasV1.open(id,revision),{id,revision}),true);
  await panel.getByRole('button',{name:'Edit app',exact:true}).click();
  const response=page.waitForResponse(r=>r.request().postDataJSON()?.action==='open'&&r.request().postDataJSON()?.revision===revision);
  await panel.getByLabel('Version',{exact:true}).selectOption(String(revision));await response;await tab('Source').click();
}
async function code(value,type='html'){await language.selectOption(type);await editor().fill(value);await source.locator('pre').filter({hasText:value}).waitFor();}
async function changed(){await page.waitForFunction(()=>!document.querySelector('.vp3-coding-source-controls button').disabled);}
try{
  await page.goto('https://source-editor.test/');
  await page.evaluate(()=>{
    window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'fixture-owner'};
    window.VP3_CODING_CANVAS_V1={endpoint:'/api/coding',csrf:'fixture',agentId:7};window.fixtureConversation=42;
    const show=view=>{document.querySelector('#chatThread').dataset.view=view;window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));};
    window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>window.fixtureConversation,agentId:()=>7,isReady:()=>true,isRestoring:()=>false,
      showCodingCanvasView:()=>show('coding'),showChatView:()=>show('chat')};
    const set=window.setTimeout.bind(window),clear=window.clearTimeout.bind(window),deadlines=new Map();
    window.setTimeout=(fn,ms,...args)=>{if(ms!==15000)return set(fn,ms,...args);const id=set(()=>{},600000);deadlines.set(id,()=>fn(...args));return id;};
    window.clearTimeout=id=>{deadlines.delete(id);clear(id);};
    window.expireSourceRequests=()=>{for(const [id,fn]of [...deadlines]){deadlines.delete(id);clear(id);fn();}};
    const fetch=window.fetch.bind(window);window.holdSourceSaves=false;window.sourceSaveHeld=false;window.releaseSourceSaves=[];
    window.fetch=async(url,options)=>{
      const body=JSON.parse(options.body),response=await fetch(url,options);
      if(body.action!=='save_source'||!window.holdSourceSaves)return response;
      const saved=await response.json();window.sourceSaveHeld=true;
      return {ok:true,status:200,json:()=>new Promise(resolve=>window.releaseSourceSaves.push(()=>resolve(saved)))};
    };
  });
  for(const file of ['chat.css','chat-coding-canvas-v1.css'])await page.addStyleTag({content:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
  for(const file of ['coding-canvas-runtime-v1.js','coding-canvas-diff-v1.js','assets/vendor/acorn-8.19.0.js','coding-canvas-source-editor-v1.js','chat-coding-canvas-v1.js'])await page.addScriptTag({content:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
  await open();
  assert.equal(await editor().getAttribute('readonly'),null);
  const local=html.replace('Workshop counter','Unsaved counter');
  await code(local);await changed();
  assert.equal(records('save_source').length,0,'Typing never sends or saves source');
  await tab('Changes').click();await panel.getByText('Version 1 → Unsaved edits',{exact:false}).waitFor();
  assert.equal(records('open').filter(r=>r.revision!==1).length,0,'Unsaved comparison needs no extra server read');
  await tab('Source').click();await source.getByRole('button',{name:'Preview edits',exact:true}).click();
  await panel.frameLocator('iframe').getByRole('heading',{name:'Unsaved counter 🎨',exact:true}).waitFor();
  await panel.frameLocator('iframe').getByLabel('Increment',{exact:true}).check();
  await panel.frameLocator('iframe').getByText('1',{exact:true}).waitFor();
  assert.equal(records('save_state').length,0,'Unsaved previews cannot write even when based on the active version');
  assert.deepEqual(state.value,{count:0,records:['Keep this record']});
  assert.equal(await panel.getByRole('button',{name:'Use this version',exact:true}).isDisabled(),true);
  await tab('Source').click();
  await page.evaluate(()=>window.holdSourceSaves=true);await save.click();await page.waitForFunction(()=>window.sourceSaveHeld);
  const firstRequest=records('save_source').at(-1);
  const newer=local.replace('Unsaved counter','Newer counter');
  await code(newer);await page.evaluate(()=>window.expireSourceRequests());
  await source.getByRole('status').filter({hasText:/interrupted|timed out|take too long|not confirmed/i}).waitFor();
  await page.evaluate(()=>{window.holdSourceSaves=false;window.releaseSourceSaves.splice(0).forEach(resolve=>resolve());});
  await save.click();await panel.locator('.vp3-coding-canvas-version-status').getByText('Reviewing version 2',{exact:false}).waitFor();
  assert.equal(records('save_source').at(-1).request_id,firstRequest.request_id,'Recovery uses the same native idempotency key');
  assert.equal(revisions.length,2,'A lost save response does not create a second version');
  assert.equal(await editor().inputValue(),newer,'Typing during an uncertain save is rebased onto its confirmed draft');
  await save.click();await panel.locator('.vp3-coding-canvas-version-status').getByText('Reviewing version 3',{exact:false}).waitFor();
  assert.notEqual(records('save_source').at(-1).request_id,firstRequest.request_id);
  assert.equal(revisions.at(-1).artifact.html,newer);assert.equal(canvas.active_revision,1);
  assert.equal(records('test').length,0,'Source saves never record a test');
  assert.equal(await save.isDisabled(),true,'Confirmed saves leave no phantom unsaved source');

  await code('const a=1;\nconst b= ;','js');
  await source.getByText(/JavaScript · Line 2, column/).waitFor();
  await source.getByRole('button',{name:'Go to error',exact:true}).click();
  assert.equal(await editor().getAttribute('aria-invalid'),'true');
  assert.equal(await source.getByRole('button',{name:'Preview edits',exact:true}).isDisabled(),true);
  await source.getByRole('button',{name:'Discard source edits',exact:true}).click();
  await code('<img src="https://leak.test/private" onerror="bad()">');
  await source.getByText('This element is outside the Canvas preview contract.',{exact:false}).waitFor();
  assert.equal(await save.isDisabled(),true);
  assert.equal(await source.locator('img,script,iframe').count(),0,'The highlighter never executes source markup');
  await source.getByRole('button',{name:'Discard source edits',exact:true}).click();
  await language.selectOption('js');
  assert.ok(await source.locator('.vp3-coding-source-comment').count(),'Saved source is highlighted');
  const selectStart=js.indexOf('CanvasUI.on'),selectEnd=js.length;
  await editor().evaluate((el,{selectStart,selectEnd})=>{el.setSelectionRange(selectStart,selectEnd);el.dispatchEvent(new Event('select'));},{selectStart,selectEnd});
  const prompt=panel.getByLabel('Describe an app to build or a change to make');
  await prompt.fill('Keep the counter and make the heading clearer.');
  await source.getByRole('button',{name:'Use selection in Agent request',exact:true}).click();
  assert.equal(await prompt.inputValue(),'Keep the counter and make the heading clearer.','Attaching code never overwrites an unsent instruction');
  assert.equal(records('repair').length,0,'Selecting source never starts a paid Agent request');
  await panel.getByRole('button',{name:'Make requested change',exact:true}).click();
  await page.waitForFunction(()=>document.querySelector('.vp3-coding-canvas-version-status').textContent.includes('Reviewing version 4'));
  const selected=records('repair').at(-1);
  assert.deepEqual(selected.edit_context,{language:'js',start_byte:Buffer.byteLength(js.slice(0,selectStart)),end_byte:Buffer.byteLength(js),source_hash:hash(revisions[2].artifact)});
  assert.equal(Object.hasOwn(selected.edit_context,'selected_source'),false);
  assert.equal(canvas.active_revision,1,'A completed targeted Agent change remains a draft');

  await tab('Source').click();await language.selectOption('html');
  const editBeforeRace=(await editor().inputValue()).replace('Agent updated counter','Private unsaved counter');
  await code(editBeforeRace);await changed();
  const remote=newDraft({...revisions.at(-1).artifact,css:'h1{color:blue}'});
  await save.click();await source.getByText('This app has a newer draft or working version.',{exact:false}).waitFor();
  assert.equal(await editor().inputValue(),editBeforeRace,'A stale save preserves the local source');
  await open(remote);
  await source.getByRole('button',{name:'Recover source edits · version 4',exact:true}).click();
  await source.getByText('Private unsaved counter',{exact:false}).first().waitFor();
  assert.equal(await editor().inputValue(),editBeforeRace);
  await save.click();await source.getByText('This app has a newer draft or working version.',{exact:false}).waitFor();
  assert.equal(canvas.draft_revision,remote,'Recovering old unsaved code retains its original conflict fence');

  await page.evaluate(()=>{window.fixtureConversation=43;window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.dispatchEvent(new Event('vp3:chat-context-ready'));});
  assert.equal(await source.locator('pre').textContent(),'');assert.equal(await source.locator('textarea').inputValue(),'');
  await open(4);
  assert.ok(!(await editor().inputValue()).includes('Private unsaved counter'),'Another Chat cannot receive the previous Chat’s private buffer');
  await page.evaluate(()=>{window.fixtureConversation=42;window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.dispatchEvent(new Event('vp3:chat-context-ready'));});
  await open(4);assert.equal(await editor().inputValue(),editBeforeRace,'Returning to the exact original scope restores unsaved work');
  await source.getByRole('button',{name:'Discard source edits',exact:true}).click();await open(remote);
  holdJob=true;await prompt.fill('Update this app while I prepare another source change.');
  await panel.getByRole('button',{name:'Repair',exact:true}).click();
  await page.waitForFunction(()=>document.querySelector('.vp3-coding-canvas-progress').textContent.includes('Working on the canvas'));
  await code((await editor().inputValue())+'<p>My next unsaved change</p>');
  holdJob=false;await panel.getByText('Draft ready to test · fixture-coder',{exact:true}).waitFor();
  assert.ok((await editor().inputValue()).includes('My next unsaved change'),'A background result cannot replace source being edited');
  assert.ok((await panel.locator('.vp3-coding-canvas-version-status').textContent()).includes('Reviewing version '+remote));

  // An accepted save may finish after navigation; its private response cannot
  // populate the new Chat. The original scoped buffer still recovers by ID.
  await source.getByRole('button',{name:'Discard source edits',exact:true}).click();await open(canvas.draft_revision);
  const savedBase=(await editor().inputValue()),late=savedBase+'<p>Private late source</p>';
  await code(late);await page.evaluate(()=>{window.holdSourceSaves=true;window.sourceSaveHeld=false;});
  await save.click();await page.waitForFunction(()=>window.sourceSaveHeld);
  const lateRequest=records('save_source').at(-1);
  await page.evaluate(()=>{window.fixtureConversation=43;window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.dispatchEvent(new Event('vp3:chat-context-ready'));
    window.holdSourceSaves=false;window.releaseSourceSaves.splice(0).forEach(resolve=>resolve());});
  assert.equal(await source.locator('pre').textContent(),'');assert.equal(await source.locator('textarea').inputValue(),'');
  await page.evaluate(()=>{window.fixtureConversation=42;window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.dispatchEvent(new Event('vp3:chat-context-ready'));});
  await open(lateRequest.revision);assert.equal(await editor().inputValue(),late);
  await save.click();await page.waitForFunction(()=>document.querySelector('.vp3-coding-canvas-version-status').textContent.includes('Reviewing version '+window.expectedSourceVersion),await page.evaluate(value=>window.expectedSourceVersion=value,canvas.draft_revision));
  assert.equal(records('save_source').at(-1).request_id,lateRequest.request_id);

  // Account/binding changes must also fence a late promise's cache writeback.
  await code((await editor().inputValue())+'<p>Discard on account change</p>');
  await page.evaluate(()=>{window.holdSourceSaves=true;window.sourceSaveHeld=false;});await save.click();await page.waitForFunction(()=>window.sourceSaveHeld);
  const identityRequest=records('save_source').at(-1);
  await page.evaluate(()=>{window.STONEFELLOW_CHAT.workingAttentionBinding='changed-owner-session';window.dispatchEvent(new Event('stonefellow:chat-context-changing'));
    window.holdSourceSaves=false;window.releaseSourceSaves.splice(0).forEach(resolve=>resolve());});
  await page.evaluate(()=>{window.STONEFELLOW_CHAT.workingAttentionBinding='fixture-owner';window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.dispatchEvent(new Event('vp3:chat-context-ready'));});
  await open(identityRequest.revision);
  assert.ok(!(await editor().inputValue()).includes('Discard on account change'),'A late response cannot recreate buffers cleared by identity change');
  assert.equal(await source.getByRole('button',{name:/Recover source edits/}).isVisible(),false);

  const screenshots=process.env.VP3_CANVAS_SOURCE_SCREENSHOT_DIR;
  if(screenshots)await fs.mkdir(screenshots,{recursive:true});
  for(const width of [1440,390,320]){
    await page.setViewportSize({width,height:950});await tab('Source').click();
    const layout=await source.evaluate(el=>{const input=el.querySelector('textarea');return {page:document.documentElement.scrollWidth,scroll:el.scrollWidth,client:el.clientWidth,
      inner:input.scrollHeight-input.clientHeight,font:parseFloat(getComputedStyle(input).fontSize),overflow:getComputedStyle(input).overflowY};});
    assert.ok(layout.page<=width+1&&layout.scroll<=layout.client+1,'Source fits the viewport: '+JSON.stringify(layout));
    assert.ok(layout.inner<=3&&layout.overflow==='hidden','The Chat thread owns source scrolling: '+JSON.stringify(layout));
    if(width<761)assert.ok(layout.font>=16,'Phone source avoids zoom-sized text');
    if(screenshots&&width!==320)await page.screenshot({path:screenshots+'/canvas-source-'+width+'.png',animations:'disabled'});
  }
  assert.deepEqual(errors,[]);assert.deepEqual(state.value,{count:0,records:['Keep this record']});
  console.log('Canvas source editor passed: safe editable/highlighted source, exact diagnostics, disposable previews, idempotent recovery, typing preservation, scoped buffers, stale saves, targeted requests, background-result fencing and desktop/phone layout.');
}catch(error){if(process.env.VP3_TEST_DEBUG)console.log((await panel.textContent()).slice(-8000),errors);throw error;}
finally{await browser.close();}
