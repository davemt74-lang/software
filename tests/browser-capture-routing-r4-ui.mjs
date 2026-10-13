import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url),{chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,...(process.env.SCREENSHOT_CHROMIUM_EXECUTABLE?{executablePath:process.env.SCREENSHOT_CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage','--use-gl=angle','--use-angle=swiftshader','--disable-gpu-sandbox']}:{} )});
const page=await browser.newPage({viewport:{width:390,height:844}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
try{
 await page.setContent('<main style="max-width:360px"><section id="agentView"><section class="agent-workspace-card"><div id="agentMessages"></div></section></section></main>');
 await page.evaluate(()=>{
  window.capture={available:true,source_url:'https://supplier.example/prices',title:'Supplier <img src=x>',canonical_url:'https://supplier.example/prices',selected_text:'Flour 24.50 <script>evil()</script>',captured_at:'2026-10-13T05:00:00Z'};
  window.agentWorkspaceAgentId=0;window.agentConversationId=42;window.calls=[];window.failSend=true;
  window.msg=async(type,data)=>{calls.push({type,...data});if(data.action==='options')return {agent_id:0,owner_user_id:7,device_id:'device',knowledge_available:true,projects:[{id:'project',title:'Supplier project'}],folders:[{id:1,folder_name:'Supplier folder'}]};if(failSend)throw Error('Temporary network failure');return {conversation_id:42,agent_id:0,message_id:1};};
  window.loadAgentConversationsV2160=async()=>{};window.loadAgentConversationV2160=async id=>{window.loaded=id;};
 });
 await page.addStyleTag({content:'.capture-routing-r4{overflow-wrap:anywhere}.field{display:flex;flex-direction:column;margin:8px 0}textarea,input,select{max-width:100%;box-sizing:border-box}'});
 await page.addScriptTag({content:await fs.readFile(new URL('../browser-companion/capture-routing-r4.js',import.meta.url),'utf8')});
 await page.getByText('Add highlighted annotation to Chat',{exact:true}).click();await page.getByRole('button',{name:'Send annotation to Chat',exact:true}).waitFor();
 await page.getByLabel('Your annotation',{exact:true}).fill('Compare this supplier');await page.getByLabel('Research project (optional)').selectOption('project');await page.getByLabel('Save to shared Cloud / HomeServer Knowledge').check();await page.getByLabel('Existing Knowledge folder').selectOption('1');await page.getByLabel('Tags (comma separated)').fill('supplier,pricing');
 assert.equal(await page.locator('img[src=x]').count(),0);assert.equal(await page.locator('script').filter({hasText:'evil()'}).count(),0);
 await page.getByRole('button',{name:'Send annotation to Chat',exact:true}).click();await page.getByText('Temporary network failure',{exact:false}).waitFor();
 const first=await page.evaluate(()=>calls.find(x=>x.action==='send').payload);assert.equal(first.owner_user_id,7);assert.equal(first.conversation_id,42);assert.equal(first.folder_id,1);assert.equal(first.project_id,'project');assert.equal(first.confirmed,true);
 await page.evaluate(()=>window.failSend=false);await page.getByRole('button',{name:'Send annotation to Chat',exact:true}).click();
 await page.waitForFunction(()=>window.loaded===42);const sends=await page.evaluate(()=>calls.filter(x=>x.action==='send').map(x=>x.payload));assert.deepEqual(sends[0],sends[1],'A failed send changed the frozen reviewed capture');
 assert.equal(await page.locator('.capture-routing-r4 textarea[readonly]').inputValue(),'');
 assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth+2));assert.deepEqual(errors,[]);
 console.log('PASS: real mobile annotation composer, text safety, existing Chat/project/folder/tags selection, reviewed send, retained exact retry and canonical Chat handoff.');
}finally{await browser.close();}
