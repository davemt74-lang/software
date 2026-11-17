import assert from 'node:assert/strict';
import fs from 'node:fs';
import {createRequire} from 'node:module';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});const page=await browser.newPage({viewport:{width:390,height:844}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
const source={session_id:'00000000-0000-4000-8000-000000000001',capture_id:'00000000-0000-4000-8000-000000000002',revision:1,title:'Pizza research',captured_at:new Date().toISOString(),url:'/knowledge.php?folder=12&screen_session=00000000-0000-4000-8000-000000000001'};
const repeat={key:'a'.repeat(64),review_hash:'b'.repeat(64),kind:'recurrence',title:'You returned to Pizza dough <img>',why:'The same subject appears in two different sharing sessions; this is not a confirmed preference.',sources:[source],refs:[source]};
const wrap={...repeat,key:'c'.repeat(64),kind:'wrapup',title:'Session wrap-up: Pizza research'};let hidden=false,withdrawn=false,audioWithdrawn=false,links=[],calls=[],foreign=false;
const audioId='00000000-0000-4000-8000-000000000003';
const report=()=>withdrawn?null:{owner_user_id:1,agent_id:4,conversation_id:7,message_id:11,session_id:source.session_id,
 cards:[...(hidden?[]:[repeat]),wrap],handoffs:links,...(audioWithdrawn?{}:{audio:{knowledge_url:source.url,segments:[{
  capture_id:audioId,captured_at:source.captured_at,text:'A spoken phrase <img src=x onerror=alert(1)> from the video.',sounds:['music']
 }]}})};
await page.route('https://insights.test/',r=>r.fulfill({contentType:'text/html',body:'<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><main id="chatThread" data-view="chat"><article class="message assistant" data-chat-message-role="assistant" data-chat-conversation-id="7" data-chat-message-id="11"><div class="message-body"><div class="message-role">Longfellow</div><div class="message-text">Screen-share wrap-up.</div></div></article></main><select id="vp3ChatTaskMode"><option value="automatic">Automatic</option><option value="chat">Chat</option></select><form id="chatForm"><textarea id="chatInput"></textarea></form><section id="review"></section>'}));
await page.route('https://insights.test/api/insights**',async r=>{const body=r.request().method()==='POST'?r.request().postDataJSON():Object.fromEntries(new URL(r.request().url()).searchParams);calls.push(body);const common={ok:true,owner_key:foreign?'2':'1',agent_id:4};
 if(body.action==='chat_report'){assert.equal(body.conversation_id,'7');assert.equal(body.message_id,'11');return r.fulfill({json:{...common,answer:withdrawn?'There are no current observations left in this screen-share wrap-up.':'Screen-share wrap-up.',report:report()}});}
 if(body.action!=='review_capture'){assert.equal(body.conversation_id,7);assert.equal(body.report_message_id,11);}
 if(body.action==='decide'){hidden=true;return r.fulfill({json:{...common,decision:body.decision}});}
 if(body.action==='reset_suggestions'){hidden=false;return r.fulfill({json:common});}
 if(body.action==='handoff'){const target='00000000-0000-4000-8000-000000000009';const link={handoff_id:body.handoff_id,target_id:target,kind:body.kind,status:'prepared',source_current:true,...(body.kind==='research'?{url:'/research-project.php?project='+target}:{})};links.push(link);return r.fulfill({json:{...common,...link,...(body.kind==='mission'?{prepared_mission:{mission:{id:target,chat_task:{draft:{}}}}}:{})}});}
 if(body.action==='sync_results')return r.fulfill({json:{...common,knowledge_id:33,url:'/knowledge.php?folder=12'}});
 if(body.action==='wrapup')return r.fulfill({json:{...common,knowledge_id:34,url:'/knowledge.php?folder=12'}});
 if(body.action==='review_capture'){if(body.capture_id===audioId)audioWithdrawn=true;else withdrawn=true;return r.fulfill({json:{...common,state:body.state}});}
 return r.fulfill({status:409,json:{ok:false,error:'Source changed'}});
});
let checks=0;const check=async(name,fn)=>{await fn();checks++;console.log('PASS '+name);};
try{
 await page.goto('https://insights.test/');await page.addStyleTag({content:fs.readFileSync('chat-screen-insights-v1.css','utf8')});await page.evaluate(()=>{window.VP3_SCREEN_INSIGHTS={endpoint:'/api/insights',ownerKey:'1',agentId:4,csrf:'fixture'};window.syncs=[];window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>7,agentId:()=>4,isReady:()=>true,isRestoring:()=>false,syncConversation:id=>syncs.push(id),stageSpecialistPrompt:(text,id)=>{if(id!==7)return false;document.querySelector('#chatInput').value=text;return true;}};window.VP3_SCREEN_PREPARED_MISSION=async value=>{window.prepared=value;};window.submits=0;document.querySelector('form').addEventListener('submit',e=>{e.preventDefault();submits++;});});await page.addScriptTag({content:fs.readFileSync('chat-screen-insights-v1.js','utf8')});
 await check('report loading waits for canonical Chat and creates no standalone message or feed',async()=>{assert.equal(calls.length,0);assert.equal(await page.locator('.message.assistant').count(),1);assert.equal(await page.locator('.vp3-screen-insight-report').count(),0);});
 const mountReport=()=>page.evaluate(value=>VP3_CHAT_SCREEN_INSIGHTS_V1.mount(document.querySelector('.message.assistant'),value),report());
 await mountReport();await mountReport();
 assert.equal(await page.locator('.message-body .vp3-screen-insight-report').count(),1);
 await page.getByRole('heading',{name:repeat.title}).waitFor();
 await check('ordinary Chat report cards are safely rendered with source explanation and no automatic action',async()=>{assert.equal(await page.locator('.vp3-screen-insight-report img').count(),0);await page.getByText('Why this suggestion?',{exact:true}).first().click();assert.match(await page.locator('.vp3-screen-insight-report').innerText(),/not a confirmed preference/);assert.equal(calls.filter(c=>c.action==='handoff').length,0);assert.match(await page.locator('.vp3-screen-insight-report details a').first().getAttribute('href'),/knowledge.php\?folder=12/);});
 await check('Agent Ears excerpts and sound labels render safely inside the saved assistant response',async()=>{
  await page.getByText('Agent Ears transcript excerpts',{exact:true}).click();
  const audio=page.locator('[data-screen-report-audio]');assert.match(await audio.innerText(),/A spoken phrase <img src=x onerror=alert\(1\)>/);
  assert.match(await audio.innerText(),/Detected sounds · music/);assert.equal(await audio.locator('img').count(),0);
  assert.equal(await audio.locator('time').getAttribute('datetime'),new Date(source.captured_at).toISOString());
 });
 await page.getByRole('button',{name:'Later (24 hours)',exact:true}).first().click();await page.getByRole('heading',{name:repeat.title}).waitFor({state:'detached'});
 await check('Later persists through refresh and can be restored explicitly',async()=>{assert.equal(calls.find(c=>c.action==='decide').decision,'later');await page.getByRole('button',{name:'Restore dismissed suggestions'}).click();await page.getByRole('heading',{name:repeat.title}).waitFor();});
 await page.getByRole('button',{name:'Prepare Mission',exact:true}).first().click();await page.waitForFunction(()=>window.prepared);
 await check('Mission handoff opens assignment review without starting workers',async()=>{const c=calls.find(c=>c.action==='handoff');assert.equal(c.kind,'mission');assert.equal(c.conversation_id,7);assert.match(c.handoff_id,/^[a-f0-9-]{36}$/);assert.equal(calls.some(c=>c.action==='start'||c.action==='tools.start'),false);});
 await page.getByRole('button',{name:'Create Research project',exact:true}).first().click();await page.getByRole('link',{name:'Open Research project'}).waitFor();
 await check('Research uses the canonical project link and carries the reviewed source hash',async()=>{const c=calls.find(c=>c.action==='handoff'&&c.kind==='research');assert.equal(c.review_hash,repeat.review_hash);assert.equal(c.owner_key,'1');assert.equal(c.agent_id,4);});
 await page.getByRole('button',{name:'Save latest results to session folder'}).first().click();await page.getByRole('link',{name:'Open in My Knowledge'}).waitFor();
 await check('result sync reports the actual Knowledge destination',async()=>{assert.match(await page.getByRole('link',{name:'Open in My Knowledge'}).getAttribute('href'),/knowledge.php\?folder=12/);});
 await page.getByRole('button',{name:'Set reminder…',exact:true}).first().click();await page.waitForFunction(()=>document.querySelector('#chatInput').value.startsWith('Remind me'));
 await check('reminder action prepares a Chat request and does not claim a scheduled reminder',async()=>{assert.match(await page.locator('#chatInput').inputValue(),/^Remind me to follow up/);assert.equal(await page.evaluate(()=>submits),0);assert.equal(await page.locator('#vp3ChatTaskMode').inputValue(),'chat');assert.match(await page.locator('.vp3-screen-insight-report').innerText(),/Choose a time before saving/);});
 await page.getByRole('button',{name:'Save session wrap-up',exact:true}).click();await page.waitForFunction(()=>document.querySelector('.vp3-screen-insight-status')?.textContent.includes('Session wrap-up saved to My Knowledge.'));
 await check('posted report synchronizes canonical Chat without duplicating its row',async()=>{
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:screen-report-posted',{detail:{conversation_id:7,message_id:11}})));
  assert.deepEqual(await page.evaluate(()=>syncs),[7]);assert.equal(await page.locator('.message.assistant').count(),1);assert.equal(calls.some(c=>c.action==='feed'),false);
 });
 await check('mobile report layout has no horizontal overflow',async()=>{assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));});
 await check('account mismatch on refresh removes retained private evidence',async()=>{
  foreign=true;await page.evaluate(sessionId=>window.dispatchEvent(new CustomEvent('vp3:screen-observation-reviewed',{detail:{sessionId}})),source.session_id);
  await page.locator('.vp3-screen-insight-report').waitFor({state:'detached'});assert.match(await page.locator('.message-text').innerText(),/unavailable/);
  assert.equal(await page.locator('.message.assistant').count(),1);foreign=false;await mountReport();
 });
 await check('reviewing audio outside the card sources refreshes and removes its transcript excerpt',async()=>{
  audioWithdrawn=true;const before=calls.filter(c=>c.action==='chat_report').length;
  await page.evaluate(captureId=>window.dispatchEvent(new CustomEvent('vp3:screen-observation-reviewed',{detail:{captureId}})),audioId);
  await page.locator('[data-screen-report-audio]').waitFor({state:'detached'});
  assert.equal(calls.filter(c=>c.action==='chat_report').length,before+1);assert.equal(await page.getByRole('heading',{name:repeat.title}).count(),1);
 });
 await page.addScriptTag({content:fs.readFileSync('agent-screen-review-v1.js','utf8')});await page.evaluate(row=>{VP3_SCREEN_REVIEW_CAPTURE(document.getElementById('review'),row,{endpoint:'/api/insights',ownerKey:'1',agentId:4,csrf:'fixture'});}, {...source,review:{state:'active',note:''}});await page.getByRole('combobox',{name:'Observation status'}).selectOption('incorrect');await page.getByRole('textbox',{name:'Correction or clarification'}).fill('Wrong title');await page.getByRole('button',{name:'Save review',exact:true}).click();await page.getByRole('heading',{name:repeat.title}).waitFor({state:'detached'});
 await check('capture corrections include the exact revision and refresh current suggestions',async()=>{const c=calls.find(c=>c.action==='review_capture');assert.equal(c.revision,1);assert.equal(c.state,'incorrect');assert.equal(c.note,'Wrong title');assert.equal(await page.locator('.message.assistant').count(),1);assert.match(await page.locator('.message-text').innerText(),/no current observations/);});
 await check('mobile cards have no horizontal overflow or browser errors',async()=>{assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));assert.deepEqual(errors,[]);});
 console.log('AGENT_SCREEN_INSIGHTS_BROWSER=PASS ('+checks+' cases)');
}finally{await browser.close();}
