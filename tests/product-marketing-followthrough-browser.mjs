import assert from 'node:assert/strict';
import {mkdir} from 'node:fs/promises';
import {chromium} from 'playwright';
import {startProfileWorkspaceServer} from './fixtures/profile-specialist-workspace-server.mjs';

// Hosted only: real Chat continuity, Hub/source APIs, one existing Marketing dialog.
const server=await startProfileWorkspaceServer({marketing:true});
let browser=null,context=null,page=null,checks=0,chatDelay=null,hubDelay=null;
const gates=new Set(),requests=[],errors=[];
const check=(truth,label)=>{assert.ok(truth,label);checks++;};
const deferred=()=>{let resolve;const promise=new Promise(done=>resolve=done);return {promise,resolve};};
const delay=()=>{const gate={started:deferred(),release:deferred(),finished:deferred()};gates.add(gate);return gate;};
const arrived=async gate=>{let timer;try{await Promise.race([gate.started.promise,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('Expected delayed read did not start.')),15000);})]);}finally{clearTimeout(timer);}};
const settle=async gate=>{if(!gate)return;gate.started.resolve();await gate.release.promise;gates.delete(gate);};
const panel=()=>page.locator('#vp3ChatSpecialistActivity');
const summary=()=>panel().locator('[data-product-marketing-summary]');
const dialog=()=>page.locator('#vp3MediaCommands[data-media-command="marketing"]');
const fixed=()=>dialog().locator('[data-marketing-fixed-ref]');
const ready=()=>page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY?.isReady()&&!window.STONEFELLOW_CHAT_CONTINUITY.isRestoring());
const screenshot=name=>page.screenshot({path:'artifacts/product-marketing-followthrough/'+name+'.png',fullPage:true,timeout:name==='failure'?5000:20000});
async function contained(target,selector){
  const box=await target.boundingBox(),viewport=page.viewportSize();
  check(box&&box.x>=0&&box.y>=0&&box.x+box.width<=viewport.width+1&&box.y+box.height<=viewport.height+1,'report remains contained in the viewport');
  check(await target.evaluate(node=>node.scrollWidth<=node.clientWidth+1),'report has no horizontal overflow');
  const fonts=await target.locator(selector).evaluateAll(nodes=>nodes.filter(node=>node.getClientRects().length&&node.textContent.trim()&&parseFloat(getComputedStyle(node).fontSize)<12)
    .map(node=>({tag:node.tagName,size:getComputedStyle(node).fontSize,text:node.textContent.slice(0,100)})));
  check(fonts.length===0,'summary, comparison and source text stays readable: '+JSON.stringify(fonts));
}
async function openMarketing(){
  check(await page.evaluate(()=>window.VP3AgentActivityHub.openSpecialist('marketing',document.getElementById('chatInput'),{label:'Marketing',fullReport:true}))===true,'existing Marketing specialist accepts the current private Chat');
  await summary().locator('[data-product-marketing-link]').first().waitFor();
}
try{
  await mkdir('artifacts/product-marketing-followthrough',{recursive:true});
  browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
  context=await browser.newContext({viewport:{width:1280,height:960}});page=await context.newPage();page.setDefaultTimeout(20000);
  page.on('pageerror',error=>errors.push(error.message));
  page.on('request',request=>{const url=new URL(request.url());if(url.pathname.startsWith('/api/'))requests.push({path:url.pathname,method:request.method(),input:request.method()==='POST'?request.postDataJSON():Object.fromEntries(url.searchParams)});});
  await context.route('**/api/chat-v236.php**',async route=>{
    const input=route.request().postDataJSON()||{},held=input.action==='load'?chatDelay:null;if(held)chatDelay=null;
    await settle(held);
    try{await route.fulfill({json:{ok:true,messages:input.action==='load'?[{id:1,role:'assistant',message:'Restored exact private Chat.'}]:[],conversations:[],updates:[],unread_count:0,latest_id:0,latest_message_id:1}});}
    catch(error){if(!/closed|disposed|cancel|abort/i.test(error.message))throw error;}finally{held?.finished.resolve();}
  });
  await context.route('**/api/agent-activity-hub-v1.php**',async route=>{
    const response=await route.fetch(),held=new URL(route.request().url()).searchParams.get('view')==='marketing'?hubDelay:null;if(held)hubDelay=null;
    await settle(held);
    try{await route.fulfill({response});}catch(error){if(!/closed|disposed|cancel|abort/i.test(error.message))throw error;}finally{held?.finished.resolve();}
  });
  const login=await context.request.get(server.base+'/session');assert.equal(login.status(),200);const session=await login.json(),seed=session.seed.followthrough;
  await page.goto(server.base+'/chat.php?agent=7&conversation_id=11');await ready();await openMarketing();
  check(await summary().locator('[data-product-marketing-source]').count()>=3,'Marketing report shows native current plans and a canonical completed publication');
  check(await panel().getByRole('heading',{name:'Campaign follow-through',exact:true}).count()===1,'existing Campaign review remains alongside Product Marketing');
  check((await summary().innerText()).includes('Private T-shirt listing'),'native listing review is useful before a creative exists');
  await contained(panel(),'.vp3-product-marketing-summary :is(p,a,small,h4,h5)');await screenshot('desktop-marketing-hub');
  const link=summary().locator('[data-product-marketing-link^="marketing:ft:"]').first();
  const sourceRef=await link.getAttribute('data-product-marketing-link'),sourceHref=await link.getAttribute('href'),beforeUrl=page.url();
  check(new URL(sourceHref,server.base).searchParams.get('marketing_followthrough')===sourceRef,'Hub link contains the same exact canonical period reference');
  await link.click();await fixed().waitFor();
  check(await fixed().getAttribute('data-marketing-fixed-ref')===sourceRef&&page.url()===beforeUrl,'Hub opens its exact report inline without navigating or resolving another period');
  check(await panel().count()===0&&await dialog().count()===1&&await dialog().locator('[data-marketing-metric]').count()===7,'Hub handoff leaves one existing Marketing dialog and the canonical seven measures');
  const change=dialog().locator('[data-marketing-metric="recorded_cta_clicks"] td').last();
  check((await change.innerText())!==''&&!['Unavailable','Not comparable'].includes(await change.innerText()),'actual complete period counts display a verified comparison');
  await contained(dialog(),'.vp3-marketing-followthrough :is(p,a,small,th,td,summary)');await screenshot('desktop-canonical-detail');
  await dialog().getByRole('button',{name:'Back to plans',exact:true}).click();await dialog().locator('[data-media-plan-followthrough]').first().waitFor();
  await dialog().locator('[data-media-plan-followthrough="'+seed.ids.product+'"]').click();await dialog().getByRole('heading',{name:'Choose a publication',exact:true}).waitFor();
  check(!(await dialog().innerText()).includes(seed.publication_id)&&(await dialog().innerText()).includes('Tracking recorded')&&(await dialog().innerText()).includes('Current state:'),'publication picker presents useful saved title, recorded date and checked current state without raw IDs');
  await dialog().getByRole('button',{name:'Close',exact:true}).click();

  check(await page.evaluate(()=>window.VP3AgentActivityHub.openProfile({tab:'reports'},document.getElementById('chatInput')))===true,'existing Profile workspace opens its current source report');
  const related=panel().locator('[data-profile-related-marketing="profile:conversation:'+seed.ids.public_conversation+'"]');await related.waitFor();
  const relatedLink=related.locator('[data-product-marketing-source]').first(),relatedRef=await relatedLink.getAttribute('data-product-marketing-source');
  check((await related.innerText()).includes('Original T-shirt promotion'),'verified promotion association appears on its native conversation finding');
  check(new URL(await relatedLink.getAttribute('href'),server.base).searchParams.get('conversation_id')==='11','public visitor association keeps its separate original private Chat destination');
  await related.scrollIntoViewIfNeeded();await screenshot('desktop-profile-related-source');
  await relatedLink.click();await fixed().waitFor();
  check(await fixed().getAttribute('data-marketing-fixed-ref')===relatedRef&&await dialog().locator('[data-marketing-exchange]').count()===1,'related Profile finding opens the same canonical detail and exact saved exchange');
  check((await dialog().innerText()).includes('Can I get this T-shirt in blue?'),'literal receipt-backed question remains readable without inferred purchase intent');
  await dialog().getByRole('button',{name:'Close',exact:true}).click();

  await page.setViewportSize({width:390,height:844});await openMarketing();
  await contained(panel(),'.vp3-product-marketing-summary :is(p,a,small,h4,h5)');await screenshot('compact-marketing-hub');
  await summary().locator('[data-product-marketing-link^="marketing:ft:"]').first().click();await fixed().waitFor();
  await dialog().evaluate(node=>{node.scrollTop=0;});await contained(dialog(),'.vp3-marketing-followthrough :is(p,a,small,th,td,summary)');
  check(await dialog().locator('[data-marketing-metric] td[data-label="Change"]').count()===7,'compact rows retain a labeled change alongside selected and previous values');
  const nested=await dialog().evaluate(node=>[...node.querySelectorAll('*')].filter(child=>child.getClientRects().length&&['auto','scroll'].includes(getComputedStyle(child).overflowY)&&child.scrollHeight>child.clientHeight+1).length);
  check(nested===0,'comparison tables preserve the existing single dialog scroll');await screenshot('compact-canonical-detail');
  await dialog().getByRole('button',{name:'Close',exact:true}).click();

  // Preserve already-published five-parameter links through the production compatibility helper.
  await page.setViewportSize({width:1280,height:960});const legacy=new URL(server.base+'/chat.php');
  legacy.search=new URLSearchParams({agent:'7',conversation_id:'11',marketing_plan:String(seed.ids.product),marketing_publication:seed.publication_id,marketing_period:seed.period_ref});
  chatDelay=delay();const heldChat=chatDelay,start=requests.length;
  await page.goto(legacy.href,{waitUntil:'domcontentloaded'});await arrived(heldChat);
  await page.waitForFunction(()=>window.VP3MarketingFollowthroughV1&&window.VP3MediaCommands);
  check(await dialog().count()===0&&!requests.slice(start).some(row=>row.path.endsWith('/marketing-followthrough-v1.php')),'legacy publication waits for actual original Chat restoration');
  heldChat.release.resolve();await ready();await fixed().waitFor();
  check(await fixed().getAttribute('data-marketing-fixed-ref')===seed.ref,'strict legacy link opens the same exact canonical historical publication and period');
  const normalized=new URL(page.url());check(normalized.searchParams.get('marketing_followthrough')===seed.ref&&!normalized.searchParams.has('marketing_publication')&&!normalized.searchParams.has('marketing_period'),'legacy URL normalizes without leaving a competing plan/publication intent');
  await screenshot('desktop-legacy-canonical-link');await dialog().getByRole('button',{name:'Close',exact:true}).click();
  const malformed=new URL(legacy);malformed.searchParams.append('marketing_publication',seed.publication_id);const deniedStart=requests.length;
  await page.goto(malformed.href);await ready();await page.waitForFunction(()=>window.VP3MarketingFollowthroughV1&&window.VP3MediaCommands);
  check(await dialog().count()===0&&!requests.slice(deniedStart).some(row=>row.path.endsWith('/marketing-followthrough-v1.php')||row.input?.action==='marketing_plan'),'duplicate legacy selectors cannot fall back to auto-opening a current plan');

  // A response collected under the previous private Chat cannot restore its summary.
  await page.goto(server.base+'/chat.php?agent=7&conversation_id=11');await ready();hubDelay=delay();const heldHub=hubDelay;
  await page.evaluate(()=>window.VP3AgentActivityHub.openSpecialist('marketing',document.getElementById('chatInput'),{label:'Marketing',fullReport:true}));await arrived(heldHub);
  await page.evaluate(()=>document.getElementById('newChatButton').click());heldHub.release.resolve();await heldHub.finished.promise;
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  check(await summary().count()===0&&!(await page.locator('body').innerText()).includes('Private T-shirt listing'),'late summary cannot return after the actual New Chat lifecycle');
  check(requests.filter(row=>row.path.endsWith('/marketing-followthrough-v1.php')).every(row=>row.method==='GET'),'all automatic and detail reads use the canonical query-only endpoint');
  check(!requests.some(row=>['creative_open','creative_generate','creative_publish'].includes(row.input?.action)||row.path.endsWith('/chat-v236.php')&&row.input?.action==='send'),'summary and related-source reads create, publish and send nothing');
  check(server.readWriteFailure()==='','actual Hub/Profile/report reads do not mutate SQL or dispatch inference');assert.deepEqual(errors,[],'production runtime has no browser errors');
  console.log('PRODUCT_MARKETING_FOLLOWTHROUGH_BROWSER=PASS ('+checks+' checks; real Hub, related Profile sources, canonical detail and legacy route)');
}catch(error){if(page)try{await screenshot('failure');}catch{}console.error(server.logs());throw error;}
finally{for(const gate of gates)gate.release.resolve();chatDelay?.release.resolve();hubDelay?.release.resolve();await context?.close();await browser?.close();await server.close();}
