import assert from 'node:assert/strict';
import {mkdir} from 'node:fs/promises';
import {chromium} from 'playwright';
import {startProfileWorkspaceServer} from './fixtures/profile-specialist-workspace-server.mjs';

// Hosted-only browser acceptance: real session, production query API and SQL.
const server=await startProfileWorkspaceServer({marketing:true});
let browser,page,checks=0,held=null;
const errors=[],requests=[],gates=[];
const check=(value,label)=>{assert.ok(value,label);checks++;};
const deferred=()=>{let resolve;const promise=new Promise(done=>{resolve=done;});return {promise,resolve};};
try{
  await mkdir('artifacts/product-marketing-followthrough',{recursive:true});
  browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
  page=await browser.newPage({viewport:{width:1280,height:960}});page.setDefaultTimeout(15000);
  page.on('pageerror',error=>errors.push(error.message));
  page.on('request',request=>{if(request.url().includes('/api/initiative-workbench-v1.php'))requests.push({method:request.method(),body:request.postDataJSON()});});
  await page.route('**/api/initiative-workbench-v1.php',async route=>{
    if(!held)return route.continue();
    const gate=held;held=null;const response=await route.fetch();gate.started.resolve();await gate.release.promise;
    try{await route.fulfill({response});}catch(error){if(!/closed|disposed|cancel|abort/i.test(error.message))throw error;}
  });
  const panel=()=>page.locator('#vp3ChatSpecialistActivity');
  const modal=()=>page.locator('[data-media-command="publication"]');
  const publication=()=>modal().locator('[data-product-marketing-publication]');
  const metric=key=>publication().locator('[data-product-marketing-metric="'+key+'"]');
  const control=async action=>{const response=await page.request.post(server.base+'/fixture/control',{data:{action}});assert.equal(response.status(),200,await response.text());};
  const screenshot=async name=>page.screenshot({path:'artifacts/product-marketing-followthrough/'+name+'.png',fullPage:true});
  const contained=async target=>{
    const box=await target.boundingBox(),size=page.viewportSize();
    check(box&&box.x>=0&&box.y>=0&&box.x+box.width<=size.width+1&&box.y+box.height<=size.height+1,'report shell remains inside the viewport');
    check(await target.evaluate(node=>node.scrollWidth<=node.clientWidth+1),'report shell has no horizontal overflow');
    const small=await target.locator('.vp3-product-marketing p,.vp3-product-marketing small,.vp3-product-marketing th,.vp3-product-marketing td,.vp3-product-marketing strong,.vp3-product-marketing summary').evaluateAll(nodes=>nodes.filter(node=>node.getClientRects().length&&node.textContent.trim()&&parseFloat(getComputedStyle(node).fontSize)<12).length);
    check(small===0,'visible report figures and coverage remain readable');
  };
  await page.goto(server.base+'/chat.php?agent=7&conversation_id=11');
  await page.waitForFunction(()=>window.VP3AgentActivityHub?.openSpecialist&&window.VP3MediaCommands?.openPublication);
  await page.locator('#chatInput').fill('Keep this private draft while I review the results.');
  await page.evaluate(()=>VP3AgentActivityHub.openSpecialist('marketing',document.getElementById('chatInput'),{label:'Marketing',fullReport:true}));
  const cards=()=>panel().locator('[data-product-marketing-outcome]');await cards().first().waitFor();
  check(await panel().locator('[data-product-marketing-plan]').count()===2&&await cards().count()===2,'ordinary product plans and publications appear in the existing Marketing report');
  check((await panel().innerText()).includes('Campaign follow-through'),'Campaign follow-through remains an independent existing section');
  await cards().first().locator(':scope > summary').click();
  const firstSource=await cards().first().getByRole('link',{name:'Review this publication',exact:true}).getAttribute('href');
  check(new URL(firstSource,server.base).searchParams.has('marketing_period'),'report source retains its completed period and publication selector');
  await contained(panel());await panel().evaluate(node=>{node.scrollTop=0;});await screenshot('desktop-report');
  await page.setViewportSize({width:390,height:844});await contained(panel());await panel().evaluate(node=>{node.scrollTop=0;});await screenshot('compact-report');
  await cards().first().getByRole('link',{name:'Review this publication',exact:true}).click();await publication().waitFor();
  check(await panel().count()===0&&page.url().endsWith('/chat.php?agent=7&conversation_id=11'),'same original Chat publication opens inline without changing the Chat URL');
  check(requests.at(-1).body.period_ref===new URL(firstSource,server.base).searchParams.get('marketing_period'),'inline report reads the exact selected source period');
  check(await page.locator('#chatInput').inputValue()==='Keep this private draft while I review the results.','opening results preserves the Chat composer draft');
  check(await page.evaluate(()=>fixtureLaunches.length)===0,'report reads do not open a creative or start any reviewed action');
  await modal().getByRole('button',{name:'Close',exact:true}).click();
  await control('marketing_rotate');
  const selection=server.seed.marketing,url='/chat.php?agent=7&conversation_id=11&marketing_plan='+selection.product_ids[0]
    +'&marketing_publication='+selection.publications[0]+'&marketing_period='+encodeURIComponent(selection.ref);
  await page.setViewportSize({width:1280,height:960});await page.goto(server.base+url);await publication().waitFor();
  check(await publication().getAttribute('data-product-marketing-publication')===selection.publications[0],'deep link restores the exact earlier publication after current publication rotation');
  check((await publication().innerText()).includes('Earlier publication')&&(await publication().innerText()).includes('USD 20.00'),'historical publication status and fixed verified amounts are visible');
  check((await metric('accepted_promotion_clicks').innerText()).includes('2')&&(await metric('accepted_promotion_clicks').innerText()).includes('1'),'selected and previous click counts render separately');
  check(await publication().getByRole('link',{name:'View recorded outcome',exact:true}).getAttribute('href')==='/chat.php?agent=7&conversation_id=11&message_id='+selection.native_message_id,'lifetime native outcome remains a related exact message link');
  await contained(modal());await modal().evaluate(node=>{node.scrollTop=0;});await screenshot('desktop-publication');
  await page.setViewportSize({width:390,height:844});await contained(modal());await modal().evaluate(node=>{node.scrollTop=0;});await screenshot('compact-publication');
  await metric('verified_payments:USD').scrollIntoViewIfNeeded();await screenshot('compact-payments');
  await publication().getByText('Definitions and coverage',{exact:true}).click();
  await publication().getByText('Definitions and coverage',{exact:true}).scrollIntoViewIfNeeded();await screenshot('compact-definitions');
  const nested=await publication().evaluate(node=>[...node.querySelectorAll('*')].filter(child=>['auto','scroll'].includes(getComputedStyle(child).overflowY)&&child.scrollHeight>child.clientHeight+1).length);
  check(nested===0,'publication measures and definitions do not add nested scrolling');
  await publication().getByRole('button',{name:'7 days',exact:true}).click();
  await page.waitForFunction(()=>document.querySelector('[data-product-marketing-ref]')?.dataset.productMarketingRef.includes(':v1:7:'));
  check(requests.at(-1).body.publication_id===selection.publications[0]&&requests.at(-1).body.period_ref.includes(':v1:7:'),'period control retains the immutable publication');
  await publication().locator('[data-product-marketing-exchange]').first().waitFor();
  check((await publication().getByRole('link',{name:'Open exact Profile conversation',exact:true}).first().getAttribute('href')).startsWith('/profile-agent.php?tab=inbox&conversation='),'saved visitor question opens the exact public Profile conversation');
  await publication().locator('[data-product-marketing-associations]').scrollIntoViewIfNeeded();await screenshot('compact-visitor-exchanges');
  await publication().getByRole('button',{name:'1 day',exact:true}).click();
  await page.waitForFunction(ref=>document.querySelector('[data-product-marketing-ref]')?.dataset.productMarketingRef.endsWith(ref.slice('profile:analytics:'.length)),selection.ref);
  await control('marketing_attribution_gap');await modal().getByRole('button',{name:'Refresh this publication',exact:true}).click();
  await metric('verified_paid_orders').getByText('Observed subset',{exact:true}).first().waitFor();
  check((await metric('verified_paid_orders').innerText()).includes('Observed subset'),'incomplete attribution visibly distinguishes known subset from a total');
  await screenshot('compact-partial');
  // Hold a real completed server response, then leave its private Chat before delivery.
  const gate={started:deferred(),release:deferred()};gates.push(gate);held=gate;
  await modal().getByRole('button',{name:'Refresh this publication',exact:true}).click();await gate.started.promise;
  await page.evaluate(()=>{window.dispatchEvent(new Event('stonefellow:chat-context-changing'));fixtureScope.cid=12;window.dispatchEvent(new Event('vp3:chat-context-ready'));});
  gate.release.resolve();await modal().waitFor({state:'detached'});
  check(!(await page.locator('body').innerText()).includes('USD 20.00'),'late fixed-publication response cannot return after a real scope change');
  await page.goto(server.base+url);await publication().waitFor();
  await publication().getByRole('button',{name:'Review saved creative',exact:true}).click();
  await page.waitForFunction(()=>fixtureLaunches.length===1);
  check((await page.evaluate(()=>fixtureLaunches[0])).publication_id===selection.publications[0],'explicit reviewed handoff rechecks and carries the exact historical tuple');
  check(await modal().count()===0,'explicit handoff closes its query-only panel');
  await page.goto(server.base+url);await publication().waitFor();await control('marketing_revoke');
  await modal().getByRole('button',{name:'Refresh this publication',exact:true}).click();await modal().waitFor({state:'detached'});
  check(!(await page.locator('body').innerText()).includes('Observed subset'),'current access revocation removes already displayed private publication evidence');
  check(requests.every(request=>request.method==='POST'&&request.body.action==='marketing_publication'),'all automatic and report requests use only the exact publication read action');
  check(server.readWriteFailure()==='','report and publication reads change no SQL and dispatch no provider');
  assert.deepEqual(errors,[]);check(true,'no browser runtime errors');
  console.log('PRODUCT_MARKETING_FOLLOWTHROUGH_BROWSER=PASS ('+checks+' checks; real API/SQL, original Chat, historical publication, desktop and390px)');
}catch(error){
  if(page)try{await page.screenshot({path:'artifacts/product-marketing-followthrough/failure.png',fullPage:true,timeout:5000});}catch{}
  error.message+='\nPHP log:\n'+server.logs();throw error;
}finally{for(const gate of gates)gate.release.resolve();await browser?.close();await server.close();}
