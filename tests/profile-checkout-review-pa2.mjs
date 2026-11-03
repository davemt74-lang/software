import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {VP3ProfileWebMCPRuntimeV100} from '../profile-webmcp-v100.js';
import {preparedAction,checkoutResult,manifest,copy,policy} from './fixtures/profile-checkout-review-pa2.mjs';

// Production IIFE and native runtime, with only DOM/transport boundaries controlled.
class CustomEventFixture extends Event{constructor(type,init={}){super(type);this.detail=init.detail;}}
class Element extends EventTarget{
  constructor(document,tag='div'){super();this.ownerDocument=document;this.tagName=tag.toUpperCase();this.children=[];this.parentElement=null;this.attrs={};this.dataset={};this.style={};this.disabled=false;this.checked=false;this._text='';this.scrollHeight=100;this.scrollTop=0;}
  get id(){return this.attrs.id||'';}set id(v){this.attrs.id=String(v);}
  get type(){return this.attrs.type||'';}set type(v){this.attrs.type=String(v);}
  get className(){return this.attrs.class||'';}set className(v){this.attrs.class=String(v);}
  get classList(){const n=this;return {add(...v){n.className=[...new Set([...n.className.split(/\s+/),...v])].join(' ').trim();},contains(v){return n.className.split(/\s+/).includes(v);},remove(v){n.className=n.className.split(/\s+/).filter(x=>x!==v).join(' ');}};}
  get isConnected(){return this===this.ownerDocument.body||Boolean(this.parentElement?.isConnected);}
  get textContent(){return this._text+this.children.map(n=>n.textContent).join('');}set textContent(v){this._text=String(v);this.replaceChildren();}
  set innerHTML(v){assert.equal(v,'','fixture does not interpret HTML');this._text='';this.replaceChildren();}
  replaceChildren(...nodes){for(const n of this.children)n.parentElement=null;this.children=[];this.append(...nodes);}
  append(...nodes){for(let n of nodes){if(typeof n==='string')n=this.ownerDocument.createTextNode(n);n.remove();n.parentElement=this;this.children.push(n);}}
  appendChild(n){this.append(n);return n;}remove(){if(this.parentElement)this.parentElement.children=this.parentElement.children.filter(n=>n!==this);this.parentElement=null;}
  setAttribute(k,v){this.attrs[k]=String(v);if(k.startsWith('data-'))this.dataset[k.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=String(v);}
  getAttribute(k){return this.attrs[k]??null;}
  matches(selector){const attrs=[...selector.matchAll(/\[([^\]=]+)(?:=['"]?([^'"\]]*)['"]?)?\]/g)];
    if(attrs.some(([,k,v])=>{const actual=k.startsWith('data-')?this.dataset[k.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]:this.attrs[k];return v===undefined?actual===undefined:actual!==v;}))return false;
    const simple=selector.replace(/\[[^\]]+\]/g,'');const tag=simple.match(/^[\w-]+/)?.[0];if(tag&&this.tagName!==tag.toUpperCase())return false;
    const id=simple.match(/#([\w-]+)/)?.[1];if(id&&id!==this.id)return false;return [...simple.matchAll(/\.([\w-]+)/g)].every(([,c])=>this.classList.contains(c));}
  querySelectorAll(selector){const found=[];const walk=n=>{for(const c of n.children){if(selector.split(',').some(s=>c.matches(s.trim())))found.push(c);walk(c);}};walk(this);return found;}
  querySelector(s){return this.querySelectorAll(s)[0]||null;}click(){if(!this.disabled)this.dispatchEvent(new Event('click'));}focus(){this.ownerDocument.activeElement=this;}
}
class Document extends EventTarget{
  constructor(){super();this.body=new Element(this,'body');this.visibilityState='visible';this.defaultView={CustomEvent:CustomEventFixture};this.modelContext={tools:new Map(),registerTool:async(tool,options)=>{this.modelContext.tools.set(tool.name,tool);options.signal?.addEventListener('abort',()=>this.modelContext.tools.delete(tool.name),{once:true});}};}
  createElement(t){return new Element(this,t);}createTextNode(text){const e=new Element(this,'#text');e.textContent=text;return e;}
  querySelector(s){return this.body.querySelector(s);}getElementById(id){return this.querySelector('#'+id);}
}
const source=fs.readFileSync(new URL('../profile-agent.js',import.meta.url),'utf8');
const tick=()=>new Promise(resolve=>setImmediate(resolve));let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
async function fixture(){
  const document=new Document(),shell=document.createElement('section');shell.id='profileAgentShell';document.body.append(shell);
  const thread=document.createElement('div');thread.dataset.profileAgentThread='';const form=document.createElement('form'),textarea=document.createElement('textarea'),submit=document.createElement('button');submit.type='submit';form.append(textarea,submit);
  const status=document.createElement('div');status.dataset.profileAgentStatus='';shell.append(thread,form,status);
  const storage=new Map(),reads=[],requests=[],pending=[],events=[];
  const localStorage={getItem:k=>{reads.push(k);return storage.get(k)||null;},setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)};
  const window={location:{href:'https://profile.example.test/demo',origin:'https://profile.example.test'},addEventListener(){},setInterval:()=>1,
    STONEFELLOW_PROFILE_AGENT:{username:'demo',endpoint:'/api/profile-agent.php',conversationNamespace:'PA1_NATIVE_NAMESPACE',greeting:''}};
  vm.runInNewContext(source,{window,document,localStorage,URL,URLSearchParams,structuredClone,CustomEvent:CustomEventFixture,clearInterval(){},fetch:async()=>({ok:true,status:200,json:async()=>({ok:true,agent:{greeting:''},messages:[],conversation:null})})});
  await tick();
  let nextAction=preparedAction();
  const runtime=new VP3ProfileWebMCPRuntimeV100({documentObject:document,sessionProof:'PA2_SESSION_PROOF',fetchImpl:async(_url,options)=>{
    const body=JSON.parse(options.body);requests.push(body);
    if(body.tool.endsWith('.prepare'))return {ok:true,status:200,json:async()=>({ok:true,action:copy(nextAction)})};
    return await new Promise((resolve,reject)=>pending.push({body,resolve,reject}));
  }});
  document.addEventListener('vp3:webmcp-confirmation-result',event=>events.push(event.detail));
  await runtime.start(copy(manifest));
  const f={document,thread,runtime,requests,pending,events,reads,async prepare(action=preparedAction()){
    nextAction=copy(action);await document.modelContext.tools.get(action.prepare_tool).execute({},{});await tick();return thread.querySelector(`[data-webmcp-confirmation="${action.intent_id}"]`);
  },get calls(){return requests.filter(r=>r.tool.endsWith('.confirm'));},async click(card){const box=card.querySelector('input[type=checkbox]');if(box){box.checked=true;box.dispatchEvent(new Event('change'));}card.querySelector('button.confirm').click();await tick();},async respond(payload=checkoutResult(),statusCode=200){const work=pending.shift();assert.ok(work,'pending confirm exists');work.resolve({ok:statusCode>=200&&statusCode<300,status:statusCode,json:async()=>copy(payload)});await tick();return work;},async fail(){const work=pending.shift();assert.ok(work);work.reject(new Error('lost response'));await tick();return work;},event(type,detail){document.dispatchEvent(new CustomEventFixture(type,{detail}));},close(){runtime.stop();}};
  return f;
}

{
 const f=await fixture(),a=preparedAction(),card=await f.prepare(a);
 check(f.reads[0]==='stonefellow-profile-agent:v1:demo:PA1_NATIVE_NAMESPACE','PA1 public visitor namespace remains unchanged');
 check(card.textContent.includes(policy)&&card.textContent.includes('USD 5.00')&&card.textContent.includes('24 hours in advance'),'complete long seller policy, cancellation fee and cutoff are displayed');
 check(card.textContent.includes('USD 25.00')&&card.textContent.includes('Stripe')&&card.textContent.includes('buyer@example.com'),'exact total, chosen provider and receipt email are reviewed');
 check(card.querySelector('img')===null&&!card.textContent.includes('SECRET_')&&!card.textContent.includes('c'.repeat(64)),'seller text is inert and confirmation material is never rendered');
 const confirm=card.querySelector('button.confirm'),terms=card.querySelector('input[type=checkbox]');confirm.click();await tick();
 check(confirm.disabled&&f.calls.length===0,'unchecked acceptance prevents confirmation');
 f.event('vp3:webmcp-confirm',{action:a,terms_accepted:false});await tick();
 check(f.calls.length===0,'runtime also rejects missing explicit seller acceptance');
 await f.click(card);check(f.calls.length===1&&card.querySelectorAll('button,input').every(n=>n.disabled),'pending confirmation disables every control');
 terms.checked=false;terms.dispatchEvent(new Event('change'));terms.checked=true;terms.dispatchEvent(new Event('change'));confirm.click();f.event('vp3:webmcp-confirm',{action:a,terms_accepted:true});await tick();
 check(confirm.disabled&&f.calls.length===1,'terms toggles and duplicate events cannot start a concurrent confirmation');
 await f.respond();
 check(card.textContent.includes('Payment pending')&&!card.textContent.includes('Completed.'),'checkout reports payment pending instead of global completion');
 const links=card.querySelectorAll('a');check(links.length===2&&links[0].textContent==='Continue to payment'&&links[1].textContent==='View receipt','canonical checkout and receipt destinations remain actionable');
 check(links[0].href==='https://checkout.stripe.com/c/pay_fixture'&&links[0].target==='_blank'&&links[0].rel.includes('noreferrer'),'provider destination is explicit and isolated from the private receipt context');
 const text=card.textContent;f.event('vp3:webmcp-confirmation-result',{intent_id:a.intent_id,confirm_tool:a.confirm_tool,attempt_id:'review-1',result:{ok:false,error:{code:'NETWORK_ERROR',message:'STALE_FAILURE',retryable:true}}});
 check(card.textContent===text&&confirm.disabled,'late error cannot replace a recorded result or reopen confirmation');
 f.close();
}
for(const mutate of [a=>delete a.preview.terms.cancellation_policy,a=>a.preview.terms.cancellation_policy='x'.repeat(4001),a=>a.preview.terms.cancellation_fee_cents=3000,a=>a.preview.payment_provider.connection_id=9,a=>a.preview.product.currency='eur',a=>a.preview.terms.price_cents=2400,a=>delete a.preview.payer_email]){
 const f=await fixture(),a=preparedAction();mutate(a);const card=await f.prepare(a);await f.click(card);
 check(card.querySelector('button.confirm').disabled&&f.calls.length===0&&card.textContent.includes('could not be verified'),'incomplete or mismatched checkout facts cannot be accepted');f.close();
}
{
 const f=await fixture(),a=preparedAction();a.preview.terms.cancellation_policy='';const card=await f.prepare(a);
 check(card.textContent.includes('has not provided additional')&&card.querySelector('input[type=checkbox]'),'explicit empty seller policy is disclosed without inventing terms');f.close();
}
for(const mode of ['network','invalid_json','http_500','in_progress']){
 const f=await fixture(),a=preparedAction(),card=await f.prepare(a);await f.click(card);const key=f.calls[0].input.idempotency_key;
 if(mode==='network')await f.fail();
 else if(mode==='invalid_json'){const work=f.pending.shift();work.resolve({ok:true,status:200,json:async()=>{throw new Error('bad json');}});await tick();}
 else await f.respond({ok:false,error:{code:mode==='in_progress'?'ACTION_IN_PROGRESS':'HTTP_500',message:'Result not available',retryable:mode==='in_progress'},action:{reuse_same_idempotency_key:true}},mode==='http_500'?500:422);
 check(!card.querySelector('button.confirm').disabled&&card.textContent.includes('same prepared action'),mode+' permits only an exact prepared-action retry');
 await f.click(card);check(f.calls.length===2&&f.calls[1].input.idempotency_key===key,mode+' retry retains its original idempotency key');
 const pendingText=card.textContent;f.event('vp3:webmcp-confirmation-result',{intent_id:a.intent_id,confirm_tool:a.confirm_tool,attempt_id:'review-1',result:{ok:true,action:checkoutResult('paid').action}});
 check(card.textContent===pendingText&&card.querySelector('button.confirm').disabled,'superseded attempt result cannot overwrite the pending retry');
 await f.respond({...checkoutResult(),idempotent_replay:true});check(card.textContent.includes('Payment pending'),'confirmed retry returns the recorded payment state');
 const other=await f.prepare(preparedAction('b'.repeat(32)));await f.click(other);check(f.calls[2].input.idempotency_key!==key,'different prepared intent has a distinct idempotency key');await f.respond();f.close();
}
for(const payload of [
 {ok:false,error:{code:'CONFIRMATION_EXPIRED',message:'Expired'},action:{reprepare_required:true}},
 {ok:false,error:{code:'CONFIRMATION_CHANGED',message:'Terms changed'},action:{reprepare_required:true}},
 {ok:false,error:{code:'IDEMPOTENCY_CONFLICT',message:'Different key'},action:{phase:'conflict'}}
]){
 const f=await fixture(),card=await f.prepare();await f.click(card);await f.respond(payload,422);const before=f.calls.length;await f.click(card);
 check(f.calls.length===before&&card.querySelector('button.confirm').disabled&&card.textContent.includes('review'),payload.error.code+' cannot replay an invalid prepared review');f.close();
}
{
 const f=await fixture(),a=preparedAction(),card=await f.prepare(a);await f.click(card);const before=card.textContent;
 f.event('vp3:webmcp-confirmation-result',{intent_id:'f'.repeat(32),confirm_tool:a.confirm_tool,result:checkoutResult('paid')});
 f.event('vp3:webmcp-confirmation-result',{intent_id:a.intent_id,confirm_tool:'vp3.commerce.refund.confirm',result:checkoutResult('paid')});
 check(card.textContent===before,'wrong intent or tool cannot change another confirmation card');
 await f.respond({ok:true,checkout_url:'https://evil.example/pay',action:{phase:'completed'}});
 check(card.textContent.includes('could not be verified')&&card.querySelectorAll('a').length===0&&card.querySelector('button.confirm').disabled,'transport success without domain outcome stays unverified and consumed');f.close();
}
for(const badUrl of ['javascript:alert(1)','data:text/html,bad','https://user:pass@checkout.stripe.com/pay','http://evil.example/pay']){
 const f=await fixture(),card=await f.prepare();await f.click(card);const result=checkoutResult();result.checkout_url=badUrl;await f.respond(result);
 check(card.querySelectorAll('a').length===1&&card.querySelector('a').textContent==='View receipt','unsafe payment destination is never rendered');f.close();
}
for(const badReceipt of ['https://evil.example/profile-commerce-order.php?username=demo&order=ORDER-7&receipt='+'c'.repeat(64),'/profile-commerce-order.php?username=foreign&order=ORDER-7&receipt='+'c'.repeat(64),'/profile-commerce-order.php?username=demo&order=OTHER&receipt='+'c'.repeat(64)]){
 const f=await fixture(),card=await f.prepare();await f.click(card);const result=checkoutResult();result.receipt_url=badReceipt;await f.respond(result);
 check(card.querySelectorAll('a').length===1&&card.querySelector('a').textContent==='Continue to payment','receipt destination must bind this profile and canonical order');f.close();
}
for(const code of ['payment_review','paid','fulfilled']){
 const f=await fixture(),card=await f.prepare();await f.click(card);await f.respond(checkoutResult(code));
 check(card.querySelectorAll('a').length===1&&card.querySelector('a').textContent==='View receipt'&&!card.textContent.includes('Continue to payment'),code+' shows receipt without soliciting another payment');f.close();
}
{
 const f=await fixture(),a=preparedAction();const card=await f.prepare(a);await f.click(card);const text=card.textContent,count=f.events.length;f.runtime.stop();await f.respond(checkoutResult('paid'));
 check(f.events.length===count&&card.textContent===text,'stopped runtime cannot resurrect an in-flight confirmation result');
}
{
 const f=await fixture(),a=preparedAction();const card=await f.prepare(a);await f.click(card);const text=card.textContent,count=f.events.length;await f.runtime.syncManifest({...copy(manifest),profile_username:'other'});await f.respond(checkoutResult('paid'));
 check(f.events.length===count&&card.textContent===text,'changed profile invalidates outstanding confirmation result authority');f.close();
}
{
 const f=await fixture(),a=preparedAction(),card=await f.prepare(a);const changed=copy(a);changed.confirmation.intent.product_id=999;f.event('vp3:webmcp-confirm',{action:changed,terms_accepted:true});await tick();
 check(f.calls.length===0&&card.querySelector('button.confirm').disabled,'browser-supplied action mutation cannot replace the retained server review');f.close();
}
{
 const f=await fixture(),a=preparedAction(),card=await f.prepare(a);
 const direct=f.document.modelContext.tools.get(a.confirm_tool).execute({confirmation_token:a.confirmation.token,intent:copy(a.confirmation.intent),idempotency_key:'direct_confirmation_123',terms_accepted:true},{});await tick();
 check(card.querySelector('button.confirm').disabled&&f.calls.length===1,'direct registered-tool confirmation binds and locks the existing review card');
 const before=card.textContent;f.event('vp3:webmcp-confirmation-result',{intent_id:'f'.repeat(32),confirm_tool:a.confirm_tool,result:checkoutResult('paid')});
 check(card.textContent===before,'direct-tool result cannot be replaced by another intent');
 await f.respond();await direct;
 check(card.textContent.includes('Payment pending')&&card.querySelectorAll('a').length===2,'direct canonical confirmation updates the same card with payment and receipt links');
 f.event('vp3:webmcp-confirm',{action:a,terms_accepted:true});await tick();
 check(f.calls.length===1&&[...f.runtime.preparedConfirmations.values()][0].idempotencyKey==='direct_confirmation_123','subsequent human event cannot issue a second key after direct confirmation');f.close();
}
for(const change of ['stop','profile','proof']){
 const f=await fixture(),a=preparedAction();let release;
 f.runtime.fetchImpl=async()=>await new Promise(resolve=>{release=resolve;});
 const reading=f.document.modelContext.tools.get(a.prepare_tool).execute({},{});await tick();
 if(change==='stop')f.runtime.stop();else if(change==='profile')await f.runtime.syncManifest({...copy(manifest),profile_username:'other'});else f.runtime.sessionProof='OTHER_PROOF';
 release({ok:true,status:200,json:async()=>({ok:true,action:a})});const result=await reading;
 check(result.ok===false&&f.runtime.preparedConfirmations.size===0&&f.thread.querySelectorAll('[data-webmcp-confirmation]').length===0,'late prepare after '+change+' cannot register or display a review');f.close();
}
console.log(`PROFILE_CHECKOUT_REVIEW_PA2=PASS checks=${checks}`);
