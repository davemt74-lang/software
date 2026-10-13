import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url),{chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,...(process.env.SCREENSHOT_CHROMIUM_EXECUTABLE?{executablePath:process.env.SCREENSHOT_CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage']}:{} )});
const source=await fs.readFile(new URL('../project-knowledge-r7.js',import.meta.url),'utf8');
assert.equal(source,await fs.readFile(new URL('../browser-companion/project-knowledge-r7.js',import.meta.url),'utf8'));
try{
 for(const mode of ['cloud','native','chrome']){
  const page=await browser.newPage({viewport:{width:390,height:844}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://r7.test/**',r=>r.fulfill({contentType:'text/html',body:'<main style="max-width:370px"><form id="chatForm"><textarea id="chatInput">My private typed question</textarea></form></main><aside id="agentBrainDrawer"><div class="agent-brain-drawer-body"></div></aside>'}));await page.goto('https://r7.test/');
  await page.evaluate(mode=>{
   window.conversation=42;window.calls=[];window.submits=0;document.getElementById('chatForm').onsubmit=e=>{e.preventDefault();submits++;};
   window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>conversation};window.STONEFELLOW_AGENT_IDENTITY_V236={agentId:0};window.HomeServerAgentRouting={getActiveConversationId:()=>String(conversation),getSelectedAgentId:()=>1};window.agentWorkspaceAgentId=0;window.agentConversationId=42;
   if(mode==='chrome'){document.getElementById('chatForm').remove();document.querySelector('main').innerHTML='<section id="agentView"><section class="agent-workspace-card"><textarea id="agentMessageInput">My private typed question</textarea></section></section>';}
   else window.VP3_PROJECT_KNOWLEDGE={mode,endpoint:mode==='native'?'https://r7.test/api/v1/workspace-sync/project-knowledge':'https://r7.test/api/project-knowledge-r5.php',csrf:'fixture'};
   window.contextState={scope:{mode:'project',project_id:'77777777-7777-4777-8777-777777777777',folder_id:0},title:'Suppliers',revision:'a'.repeat(64),available:true};
   window.item={kind:'annotation',id:'33333333-3333-4333-8333-333333333333',revision:'c'.repeat(64),title:'Supplier <img src=x onerror=evil()>',url:'https://supplier.example/prices',captured_at:'2026-09-01',excerpt:'PRIVATE ORIGINAL QUOTE',refreshable:true,freshness:{state:'review_due',age_days:42,notice:'Age does not establish correctness.'}};
   window.refresh={id:'44444444-4444-4444-8444-444444444444',source:item,status:'prepared',project_id:contextState.scope.project_id,folder_id:1,research:{status:'prepared',review_hash:'b'.repeat(64),sources:[{url:item.url}],budgets:{pages:1,claims:60,duration_minutes:60}}};
   window.handle=async payload=>{
    calls.push(structuredClone(payload));if(window.revoked)throw Error('Knowledge access revoked.');if(window.defer)await new Promise(resolve=>window.release=resolve);
    if(payload.action==='prepare'){window.prepareId=payload.request_id;}
    if(payload.action==='start'){if(payload.expected_hash!==refresh.research.review_hash||payload.confirmed!==true)throw Error('Wrong exact plan');refresh.status='waiting_chrome';}
    if(payload.action==='get'&&refresh.status==='waiting_chrome'){refresh.status='complete';Object.assign(refresh,{comparison:{notice:'Different observations do not prove a real-world change.'},review_hash:'d'.repeat(64),before_knowledge_content:'Earlier separate note',knowledge_content:'Reviewed findings <script>evil()</script> [C1] pinned version version2'});}
    if(payload.action==='apply'){if(payload.expected_hash!==refresh.review_hash||payload.confirmed!==true)throw Error('Wrong exact Knowledge review');refresh.status='applied';refresh.sync={state:'received',confirmed_at:'2026-10-13 05:01:00'};refresh.report={url:'/research-report.php?report=draft'};refresh.knowledge_url='/personal-knowledge.php';}
    return {ok:true,contract:'vp3.project-knowledge.r7',state:contextState,cloud_origin:'https://r7.test',items:[item],projects:[{id:contextState.scope.project_id,title:'Suppliers'}],folders:[{id:1,folder_name:'Purchasing'}],runtimes:[{id:'88888888-8888-4888-8888-888888888888',title:'Approved Chrome'}],runs:payload.action==='list'&&window.prepareId?[refresh]:[],refresh};
   };
   window.msg=async(type,{payload})=>{if(type!=='project_knowledge_r7')throw Error('Wrong worker route');return handle(payload);};window.fetch=async(url,options)=>{window.lastUrl=url;try{return {ok:true,json:async()=>await handle(JSON.parse(options.body))};}catch(e){return {ok:false,json:async()=>({error:e.message})};}};
  },mode);await page.addScriptTag({content:source});
  await page.getByText('Review Knowledge freshness',{exact:true}).click();await page.getByRole('button',{name:'Prepare reviewed refresh'}).waitFor();assert.equal(await page.evaluate(()=>calls.length),1);
  await page.getByLabel('Reviewed Knowledge folder').selectOption('1');await page.getByRole('button',{name:'Prepare reviewed refresh'}).click();await page.getByRole('button',{name:'Start this exact source reread'}).waitFor();
  assert.equal(await page.evaluate(()=>calls.some(c=>c.action==='start')),false);const prepare=await page.evaluate(()=>calls.find(c=>c.action==='prepare'));assert.equal(prepare.reference.revision,'c'.repeat(64));assert.equal(prepare.folder_id,1);assert.equal(prepare.state_revision,'a'.repeat(64));assert.equal(JSON.stringify(prepare).includes('PRIVATE ORIGINAL QUOTE'),false);
  await page.getByRole('button',{name:'Start this exact source reread'}).click();assert.equal(await page.evaluate(()=>calls.some(c=>c.action==='start')),false);
  await page.getByRole('checkbox',{name:'Start this exact source reread'}).check();await page.getByRole('button',{name:'Start this exact source reread'}).click();await page.getByText('Refresh status: waiting_chrome',{exact:true}).waitFor();
  await page.getByRole('button',{name:'Check refresh progress'}).click();await page.getByRole('button',{name:'Apply this reviewed Knowledge update'}).waitFor();assert.equal(await page.evaluate(()=>calls.some(c=>c.action==='apply')),false);
  assert.equal(await page.locator('.project-knowledge-r7 img,.project-knowledge-r7 script').count(),0);
  await page.getByRole('checkbox',{name:'Apply this reviewed Knowledge update'}).check();await page.getByRole('button',{name:'Apply this reviewed Knowledge update'}).click();await page.getByText('Refresh status: applied',{exact:true}).waitFor();assert.equal(await page.locator('.r7-brain-receipts a').count(),2);
  assert.equal(await page.locator(mode==='chrome'?'#agentMessageInput':'#chatInput').inputValue(),'My private typed question');assert.equal(await page.evaluate(()=>submits),0);assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  await page.evaluate(()=>window.revoked=true);await page.getByRole('button',{name:'Check source ages'}).click();await page.getByText('Knowledge access revoked.',{exact:true}).waitFor();assert.equal(await page.locator('.project-knowledge-r7 article,.r7-brain-receipts a').count(),0);
  await page.evaluate(()=>{window.revoked=false;window.defer=true;});await page.getByRole('button',{name:'Check source ages'}).click();await page.waitForFunction(()=>typeof release==='function');await page.evaluate(mode=>{conversation=43;if(mode==='chrome')agentConversationId=43;window.release();window.defer=false;},mode);await page.getByText('Conversation changed. Check its source ages again.',{exact:true}).waitFor();assert.equal(await page.locator('.project-knowledge-r7 article').count(),0);assert.deepEqual(errors,[]);await page.close();
 }
 console.log('PASS: R7 actual three mobile canvases, separate exact plan/write confirmation, source-only requests, no draft replacement/send, safe cited previews, Brain receipts, permission revocation and conversation-race clearing.');
}finally{await browser.close();}
