import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
import {preparedAction,checkoutResult,manifest,copy,policy,markup} from './fixtures/profile-checkout-review-pa2.mjs';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const read=name=>fs.readFileSync(new URL('../'+name,import.meta.url),'utf8');
const files={'/agent.js':read('profile-agent.js'),'/runtime.js':read('profile-webmcp-v100.js'),'/profile.css':read('profile.css')};
const server=http.createServer((req,res)=>{
 if(files[req.url]){res.writeHead(200,{'Content-Type':req.url.endsWith('.css')?'text/css':'text/javascript'});res.end(files[req.url]);return;}
 res.writeHead(200,{'Content-Type':'text/html'});
 res.end(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/profile.css"><style>body{margin:0;font-family:system-ui}#profileAgentShell{max-width:640px;margin:16px}[data-profile-agent-thread]{display:grid;gap:12px;min-width:0}</style></head><body>${markup}<script>window.setInterval=()=>1;window.STONEFELLOW_PROFILE_AGENT={username:'demo',endpoint:'/api/profile-agent.php',conversationNamespace:'PA1_NATIVE_NAMESPACE'};Object.defineProperty(document,'modelContext',{value:{tools:new Map(),async registerTool(tool,options){this.tools.set(tool.name,tool);options.signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}}});</script><script src="/agent.js"></script><script type="module">import {VP3ProfileWebMCPRuntimeV100} from '/runtime.js';window.runtime=new VP3ProfileWebMCPRuntimeV100({sessionProof:'PA2_BROWSER_PROOF'});await window.runtime.start(${JSON.stringify(manifest)});window.fixtureReady=true;</script></body></html>`);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const base=`http://127.0.0.1:${server.address().port}`;let browser,checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
async function fixture(){
 const page=await browser.newPage({viewport:{width:390,height:844}}),calls=[],pending=[],waiting=[],errors=[];let action=preparedAction();
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/api/profile-agent.php*',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,agent:{greeting:'Ready'},messages:[],conversation:null})}));
 await page.route('**/api/profile-webmcp-v100.php',async route=>{
  const body=route.request().postDataJSON();calls.push(body);
  if(body.tool.endsWith('.prepare')){await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,action})});return;}
  const work={route,body};pending.push(work);waiting.shift()?.(work);
 });
 await page.goto(base+'/demo');await page.waitForFunction(()=>window.fixtureReady&&document.querySelector('[data-profile-agent-thread]')?.textContent.includes('Ready'));
 const f={page,calls,errors,async prepare(a=preparedAction()){
  action=copy(a);await page.evaluate(tool=>document.modelContext.tools.get(tool).execute({},{}),a.prepare_tool);
  const card=page.locator(`[data-webmcp-confirmation="${a.intent_id}"]`);await card.waitFor();return card;
 },get confirms(){return calls.filter(r=>r.tool.endsWith('.confirm'));},next:()=>pending.length?Promise.resolve(pending[0]):new Promise(resolve=>waiting.push(resolve)),
 async click(card){await card.locator('input[type=checkbox]').check();await card.locator('button.confirm').click();await f.next();},
 async respond(result=checkoutResult(),status=200){const work=await f.next();pending.shift();await work.route.fulfill({status,contentType:'application/json',body:JSON.stringify(result)});await page.waitForFunction(()=>!document.querySelector('[aria-busy="true"]'));return work;},
 async fail(){const work=await f.next();pending.shift();await work.route.abort('failed');await page.waitForFunction(()=>!document.querySelector('[aria-busy="true"]'));return work;},
 async close(){check(errors.length===0,'browser has no script errors: '+errors.join('; '));await page.close();}};
 return f;
}
try{
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
 {
  const f=await fixture(),a=preparedAction(),card=await f.prepare(a),{page}=f;
  check((await card.innerText()).includes(policy),'actual browser displays the entire long seller policy');
  check((await card.innerText()).includes('USD 5.00')&&(await card.innerText()).includes('24 hours in advance')&&(await card.innerText()).includes('Stripe'),'mobile review displays fee, cutoff and selected provider');
  check(await card.locator('img').count()===0&&!(await card.innerText()).includes('SECRET_'),'seller policy cannot become active HTML or expose hidden intent material');
  check(await card.locator('button.confirm').isDisabled(),'acceptance is required in the real DOM');
  const box=await card.boundingBox();check(box.x>=0&&box.x+box.width<=391,'checkout review fits the mobile viewport');
  await f.click(card);check(await card.locator('input').isDisabled()&&await card.locator('button.dismiss').isDisabled(),'pending request disables acceptance and cancellation');
  await card.locator('input').evaluate(input=>{input.checked=false;input.dispatchEvent(new Event('change'));input.checked=true;input.dispatchEvent(new Event('change'));});
  await page.evaluate(action=>document.dispatchEvent(new CustomEvent('vp3:webmcp-confirm',{detail:{action,terms_accepted:true}})),a);
  check(await card.locator('button.confirm').isDisabled()&&f.confirms.length===1,'checkbox changes and duplicate events cannot issue a second pending request');
  await f.respond();check((await card.innerText()).includes('Payment pending')&&!(await card.innerText()).includes('Completed.'),'successful checkout still describes the actual pending payment');
  const pay=card.getByRole('link',{name:'Continue to payment'}),receipt=card.getByRole('link',{name:'View receipt'});
  check(await pay.getAttribute('href')==='https://checkout.stripe.com/c/pay_fixture'&&(await pay.getAttribute('rel')).includes('noreferrer'),'payment link uses the returned HTTPS destination safely');
  check((await receipt.getAttribute('href')).startsWith(base+'/profile-commerce-order.php?username=demo&order=ORDER-7&receipt='),'private receipt link remains scoped to the profile and order');
  const before=await card.innerText();await page.evaluate(action=>document.dispatchEvent(new CustomEvent('vp3:webmcp-confirmation-result',{detail:{intent_id:action.intent_id,confirm_tool:action.confirm_tool,attempt_id:'review-1',result:{ok:false,error:{code:'NETWORK_ERROR',message:'STALE_FAILURE',retryable:true}}}})),a);
  check(await card.innerText()===before,'late error cannot replace a recorded outcome in the real DOM');await f.close();
 }
 for(const mode of ['lost_response','in_progress']){
  const f=await fixture(),a=preparedAction(),card=await f.prepare(a);await f.click(card);const key=f.confirms[0].input.idempotency_key;
  if(mode==='lost_response')await f.fail();else await f.respond({ok:false,error:{code:'ACTION_IN_PROGRESS',message:'Still in progress',retryable:true},action:{reuse_same_idempotency_key:true}},422);
  check(await card.locator('button.confirm').isEnabled(),'uncertain browser result permits the same confirmation to be retried');
  await f.click(card);check(f.confirms[1].input.idempotency_key===key,'browser '+mode+' retry preserves its original key');
  await f.page.evaluate(action=>document.dispatchEvent(new CustomEvent('vp3:webmcp-confirmation-result',{detail:{intent_id:action.intent_id,confirm_tool:action.confirm_tool,attempt_id:'review-1',result:{ok:false,error:{message:'STALE old error',retryable:true}}}})),a);
  check(await card.locator('button.confirm').isDisabled()&&!(await card.innerText()).includes('STALE'),'older attempt cannot overwrite a pending retry');
  await f.respond({...checkoutResult(),idempotent_replay:true});check(await card.locator('button.confirm').isDisabled()&&await card.getByRole('link',{name:'View receipt'}).count()===1,'verified retry leaves one result and no extra confirmation');await f.close();
 }
 {
  const f=await fixture(),a=preparedAction();delete a.preview.terms.cancellation_policy;const card=await f.prepare(a);
  check(await card.locator('button.confirm').isDisabled()&&await card.locator('input').count()===0,'missing policy evidence cannot produce an acceptance checkbox');await f.close();
 }
 {
  const f=await fixture(),card=await f.prepare();await f.click(card);await f.respond({ok:false,error:{code:'CONFIRMATION_CHANGED',message:'Seller terms changed'},action:{reprepare_required:true}},422);
  check(await card.locator('button.confirm').isDisabled()&&(await card.innerText()).includes('new review'),'changed terms require a new review in the browser');await f.close();
 }
 {
  const f=await fixture(),card=await f.prepare();await f.click(card);await f.respond(checkoutResult('payment_review'));
  check((await card.innerText()).includes('payment is verified')&&(await card.innerText()).includes('review fulfillment'),'late verified payment accurately explains fulfillment review');
  check(await card.getByRole('link',{name:'Continue to payment'}).count()===0&&await card.getByRole('link',{name:'View receipt'}).count()===1,'review-required order offers its receipt without another payment');await f.close();
 }
 {
  const f=await fixture(),card=await f.prepare();await f.click(card);const result=checkoutResult();result.checkout_url='javascript:alert(1)';result.receipt_url='https://evil.example/private';await f.respond(result);
  check(await card.locator('a').count()===0,'browser never exposes unsafe payment or foreign private receipt links');await f.close();
 }
 console.log(`PROFILE_CHECKOUT_REVIEW_PA2_BROWSER=PASS checks=${checks}`);
}finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
