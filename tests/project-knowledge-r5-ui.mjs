import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url),{chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,...(process.env.SCREENSHOT_CHROMIUM_EXECUTABLE?{executablePath:process.env.SCREENSHOT_CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage','--use-gl=angle','--use-angle=swiftshader','--disable-gpu-sandbox']}:{} )});
const source=await fs.readFile(new URL('../project-knowledge-r5.js',import.meta.url),'utf8');
const chromeSource=await fs.readFile(new URL('../browser-companion/project-knowledge-r5.js',import.meta.url),'utf8');assert.equal(chromeSource,source,'Chrome UI differs from the canonical canvas');
const modes=['cloud','native','chrome'];
try{
 for(const mode of modes){
  const page=await browser.newPage({viewport:{width:390,height:844}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('http://r5.test/**',r=>r.fulfill({contentType:'text/html',body:'<main style="width:100%;max-width:370px"><div id="chatMessages"></div><form id="chatForm"><textarea id="chatInput"></textarea><button type="submit">Send</button></form></main>'}));
  await page.goto('http://r5.test/');
  await page.evaluate(mode=>{
   window.chatScope=42;window.calls=[];window.submits=0;document.getElementById('chatForm').onsubmit=e=>{e.preventDefault();submits++;};
   window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>chatScope};window.STONEFELLOW_AGENT_IDENTITY_V236={agentId:0};
   window.HomeServerAgentRouting={getActiveConversationId:()=>String(chatScope),getSelectedAgentId:()=>0};
   window.agentWorkspaceAgentId=0;window.agentConversationId=42;
   if(mode==='chrome'){document.getElementById('chatForm').remove();document.querySelector('main').insertAdjacentHTML('beforeend','<section id="agentView"><section class="agent-workspace-card"><textarea id="agentMessageInput"></textarea></section></section>');}
   else window.VP3_PROJECT_KNOWLEDGE={mode,endpoint:'http://r5.test/api',csrf:'fixture'};
   window.row={id:'33333333-3333-4333-8333-333333333333',title:'Supplier <img src=x onerror=evil()>',selection:'Flour 24.50 <script>evil()</script>',note:'Compare costs',tags:['pricing'],project_id:'project1',project_title:'Suppliers',folder_id:1,folder_name:'Suppliers',knowledge_id:9,captured_at:'2026-10-13 05:00:00',source_url:'https://supplier.example/prices',can_edit:true,revision:'a'.repeat(64),sync:{state:'pending',confirmed_at:null}};
   window.handle=async payload=>{
    calls.push(structuredClone(payload));
    if(window.revoked)return {ok:false,error:'Knowledge permission revoked.'};
    if(payload.action==='edit'){if(window.conflict)return {ok:false,error:'This annotation changed. Refresh and review the current version.'};row={...row,...payload,revision:'b'.repeat(64)};return {ok:true,contract:'vp3.project-knowledge.r5',item:row};}
    if(window.defer)await new Promise(resolve=>window.release=resolve);
    return {ok:true,contract:'vp3.project-knowledge.r5',items:window.many?Array.from({length:25},()=>row):[row],count:window.many?25:1,offset:0,folders:[{id:1,folder_name:'Suppliers'},{id:2,folder_name:'Purchasing'}],projects:[{id:'project1',title:'Suppliers',can_edit:true},{id:'project2',title:'Purchasing',can_edit:true}]};
   };
   window.msg=async(type,{payload})=>{if(type!=='project_knowledge_r5')throw Error('Unexpected command');const r=await handle(payload);if(!r.ok)throw Error(r.error);return r;};
   window.fetch=async(_url,options)=>{const r=await handle(JSON.parse(options.body));return {ok:r.ok,status:r.ok?200:(window.revoked?403:409),json:async()=>r};};
  },mode);
  await page.addScriptTag({content:source});await page.locator('summary').getByText('Project Knowledge',{exact:true}).click();await page.getByRole('button',{name:'Use in Chat',exact:true}).waitFor();
  assert.equal(await page.locator('img').count(),0);assert.equal(await page.locator('script').filter({hasText:'evil()'}).count(),0);
  await page.getByLabel('Search annotations').fill('flour');await page.getByLabel('Tag',{exact:true}).fill('pricing');await page.getByLabel('Project',{exact:true}).selectOption('project1');await page.getByLabel('Knowledge folder',{exact:true}).selectOption('1');await page.getByLabel('Captured from').fill('2026-10-01');await page.getByLabel('Captured through').fill('2026-10-13');await page.getByRole('button',{name:'Search',exact:true}).click();await page.getByRole('button',{name:'Edit organization'}).waitFor();
  let call=await page.evaluate(()=>calls.at(-1));assert.equal(call.q,'flour');assert.equal(call.folder_id,1);assert.equal(call.tag,'pricing');assert.equal(call.before,'2026-10-13');
  await page.getByRole('button',{name:'Edit organization'}).click();await page.getByLabel('Annotation note',{exact:true}).fill('Wholesale saffron');await page.getByLabel('Tags, separated by commas').fill('wholesale,costs');await page.getByLabel('Project',{exact:true}).last().selectOption('project2');await page.getByLabel('Knowledge folder',{exact:true}).last().selectOption('2');await page.getByRole('button',{name:'Review changes'}).click();
  assert.equal(await page.evaluate(()=>calls.filter(c=>c.action==='edit').length),0,'Review wrote before confirmation');
  await page.evaluate(()=>window.conflict=true);await page.getByRole('button',{name:'Save reviewed changes'}).click();await page.getByText('This annotation changed.',{exact:false}).waitFor();assert.equal(await page.getByLabel('Annotation note').inputValue(),'Wholesale saffron');
  const exact=await page.evaluate(()=>calls.at(-1));assert.equal(exact.expected_revision,'a'.repeat(64));assert.equal(exact.folder_id,2);assert.equal(exact.confirmed,true);
  await page.evaluate(()=>window.conflict=false);await page.getByRole('button',{name:'Save reviewed changes'}).click();await page.getByRole('button',{name:'Edit organization'}).waitFor();
  assert.deepEqual(await page.evaluate(()=>calls.filter(c=>c.action==='edit').slice(-2)),[exact,exact]);
  await page.getByRole('button',{name:'Use in Chat',exact:true}).click();assert.match(await page.locator(mode==='chrome'?'#agentMessageInput':'#chatInput').inputValue(),/supplier\.example\/prices/);assert.equal(await page.evaluate(()=>submits),0,'Picker automatically sent a Chat/model turn');
  await page.locator('summary').getByText('Project Knowledge',{exact:true}).click();await page.evaluate(()=>window.revoked=true);await page.getByRole('button',{name:'Search',exact:true}).click();await page.getByText('Knowledge permission revoked.',{exact:false}).waitFor();assert.equal(await page.locator('.r5-results article').count(),0,'Revoked results stayed visible');
  await page.evaluate(()=>{window.revoked=false;window.defer=true;});await page.getByRole('button',{name:'Search',exact:true}).click();await page.waitForFunction(()=>typeof window.release==='function');await page.evaluate(mode=>{if(mode==='chrome')agentConversationId=43;else chatScope=43;window.release();window.defer=false;},mode);await page.getByText('Chat context changed.',{exact:false}).first().waitFor();assert.equal(await page.locator('.r5-results article').count(),0);assert.equal(await page.getByRole('button',{name:'Search',exact:true}).isEnabled(),true,'Context change left interface locked');
  await page.evaluate(()=>window.many=true);await page.getByRole('button',{name:'Search',exact:true}).click();await page.waitForFunction(()=>document.querySelectorAll('.r5-results article').length===25);
  assert.equal(await page.evaluate(()=>{const p=document.querySelector('.project-knowledge-r5');return p.getBoundingClientRect().height<=window.innerHeight*.46&&p.scrollHeight>p.clientHeight;}),true,'Long result lists displaced the Chat composer');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),true,'Mobile horizontal overflow');assert.deepEqual(errors,[]);await page.close();
 }
 console.log('PASS: R5 actual Cloud/native/Chrome mobile canvas, search filters, exact review and conflict retry, source safety, composer-only use, revoked/stale result clearing and layout.');
}finally{await browser.close();}
