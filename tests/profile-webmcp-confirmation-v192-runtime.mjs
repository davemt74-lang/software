import assert from 'node:assert/strict';
import {VP3ProfileWebMCPRuntimeV100} from '../profile-webmcp-v100.js';

class ModelContext{
  constructor(){this.tools=new Map();}
  async registerTool(tool,options={}){
    this.tools.set(tool.name,tool);
    options.signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});
  }
}
class Doc extends EventTarget{
  constructor(){super();this.modelContext=new ModelContext();this.defaultView={CustomEvent:globalThis.CustomEvent};}
}
if(typeof globalThis.CustomEvent!=='function'){
  globalThis.CustomEvent=class CustomEvent extends Event{constructor(type,init={}){super(type);this.detail=init.detail;}};
}

const manifest={
  manifest_version:'vp3.profile.webmcp.v1',
  surface:'native_profile',
  profile_username:'demo',
  capabilities:{booking:true,commerce:true},
  allowed_tools:['vp3.booking.prepare','vp3.booking.confirm','vp3.commerce.checkout.prepare','vp3.commerce.checkout.confirm']
};
const doc=new Doc();
const requests=[];
let mode='booking_prepare';
const intentId='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const checkoutIntent='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const fetchImpl=async(_url,options)=>{
  const body=JSON.parse(options.body);requests.push(body);
  if(mode==='booking_prepare')return {ok:true,status:200,async json(){return {ok:true,action:{contract:'vp3.webmcp.action.v1',phase:'prepared',prepare_tool:'vp3.booking.prepare',confirm_tool:'vp3.booking.confirm',title:'Review booking',requires_confirmation:true,intent_id:intentId,expires_at_unix:1999999999,preview:{start_at_utc:'2026-10-01T18:00:00Z'},confirmation:{token:'booking-token',intent:{booking:'x'},requires_terms_acceptance:false}}};}};
  if(mode==='booking_confirm')return {ok:true,status:200,async json(){return {ok:true,booking:{status:'confirmed'},action:{contract:'vp3.webmcp.action.v1',phase:'completed',prepare_tool:'vp3.booking.prepare',confirm_tool:'vp3.booking.confirm',intent_id:intentId,requires_confirmation:false}};}};
  if(mode==='checkout_prepare')return {ok:true,status:200,async json(){return {ok:true,action:{contract:'vp3.webmcp.action.v1',phase:'prepared',prepare_tool:'vp3.commerce.checkout.prepare',confirm_tool:'vp3.commerce.checkout.confirm',title:'Review checkout',requires_confirmation:true,intent_id:checkoutIntent,expires_at_unix:1999999999,preview:{amount:'25.00'},confirmation:{token:'checkout-token',intent:{checkout:'x'},requires_terms_acceptance:true}}};}};
  return {ok:true,status:200,async json(){return {ok:true,order:{status:'pending'},action:{contract:'vp3.webmcp.action.v1',phase:'completed',prepare_tool:'vp3.commerce.checkout.prepare',confirm_tool:'vp3.commerce.checkout.confirm',intent_id:checkoutIntent,requires_confirmation:false}};}};
};

const events=[];
const confirmationEvents=[];
const resultEvents=[];
doc.addEventListener('vp3:webmcp-confirmation',e=>confirmationEvents.push(e.detail));
doc.addEventListener('vp3:webmcp-confirmation-result',e=>resultEvents.push(e.detail));

const runtime=new VP3ProfileWebMCPRuntimeV100({documentObject:doc,fetchImpl,sessionProof:'proof-123',onEvent:e=>events.push(e)});
await runtime.start(manifest);

mode='booking_prepare';
const prepare=await doc.modelContext.tools.get('vp3.booking.prepare').execute({start_at_utc:'2026-10-01T18:00:00Z',guest_name:'Test',guest_email:'test@example.com'},{});
assert.equal(prepare.ok,true);
assert.equal(confirmationEvents.length,1);
assert.equal(confirmationEvents[0].intent_id,intentId);
assert.ok(events.some(e=>e.event==='confirmation_required'&&e.intent_id===intentId));

mode='booking_confirm';
doc.dispatchEvent(new CustomEvent('vp3:webmcp-confirm',{detail:{action:confirmationEvents[0]}}));
await new Promise(resolve=>setTimeout(resolve,0));
const bookingConfirm=requests.at(-1);
assert.equal(bookingConfirm.tool,'vp3.booking.confirm');
assert.equal(bookingConfirm.input.confirmation_token,'booking-token');
assert.deepEqual(bookingConfirm.input.intent,{booking:'x'});
assert.match(bookingConfirm.input.idempotency_key,/^[A-Za-z0-9_-]{8,96}$/);
assert.equal(resultEvents.some(e=>e.intent_id===intentId&&e.result?.ok===true),true);

mode='checkout_prepare';
await doc.modelContext.tools.get('vp3.commerce.checkout.prepare').execute({product_slug:'x',payer_email:'test@example.com'},{});
const checkoutAction=confirmationEvents.at(-1);
assert.equal(checkoutAction.intent_id,checkoutIntent);
assert.equal(checkoutAction.confirmation.requires_terms_acceptance,true);

mode='checkout_confirm';
doc.dispatchEvent(new CustomEvent('vp3:webmcp-confirm',{detail:{action:checkoutAction,terms_accepted:true}}));
await new Promise(resolve=>setTimeout(resolve,0));
const checkoutConfirm=requests.at(-1);
assert.equal(checkoutConfirm.tool,'vp3.commerce.checkout.confirm');
assert.equal(checkoutConfirm.input.terms_accepted,true);

const before=requests.length;
runtime.stop();
doc.dispatchEvent(new CustomEvent('vp3:webmcp-confirm',{detail:{action:checkoutAction,terms_accepted:true}}));
await new Promise(resolve=>setTimeout(resolve,0));
assert.equal(requests.length,before,'stop must remove confirmation listener');

console.log('PROFILE_WEBMCP_CONFIRMATION_V192_RUNTIME=PASS');
