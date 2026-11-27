import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {spawnSync} from 'node:child_process';
import {createECDH,createHash,webcrypto} from 'node:crypto';
const source=fs.readFileSync(new URL('../phone-push-subscriptions-v1.js',import.meta.url),'utf8');
const ecdh=createECDH('prime256v1');ecdh.generateKeys();const publicKey=ecdh.getPublicKey().toString('base64url'),keyId=createHash('sha256').update(publicKey).digest('hex');
const endpoint='https://web.push.apple.com/Qphone-ui-fixture',endpointHash=createHash('sha256').update(endpoint).digest('hex'),eventRef='a'.repeat(32),requestId='11111111-2222-4333-8444-555555555555';
const defer=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return {promise,resolve,reject};};
const flush=async()=>{for(let i=0;i<8;i++)await new Promise(resolve=>setImmediate(resolve));};
class Events{
 constructor(){this.handlers={};}addEventListener(name,fn){(this.handlers[name]??=[]).push(fn);}emit(name,event={}){return Promise.all((this.handlers[name]||[]).map(fn=>fn(event)));}
}
class Element extends Events{constructor(id){super();this.id=id;this.hidden=false;this.disabled=false;this.textContent='';}}
function fixture(){return {id:'d'.repeat(32),status:'approved',generation:8,push:{state:'registered',revision:4,enrollment_enabled:true,delivery_ready:true,key_id:keyId,application_server_key:publicKey,endpoint_hash:endpointHash,delivery:{enabled:true,ready:true,worker:{state:'observed',last_observed_epoch:Math.floor(Date.now()/1000),transport_ready:true},last_test:null}}};}
function receipt(device,overrides={}){const now=Math.floor(Date.now()/1000);return {event_ref:eventRef,device_id:device.id,subscription_revision:device.push.revision,state:'queued',attempts:0,created_epoch:now-5,expires_epoch:now+300,provider_accepted_epoch:0,client_observed_epoch:0,opened_epoch:0,retry_at:0,last_code:'',...overrides};}
function setup(options={}){
 const location=new URL(options.url||'https://phone.test/vp3/phone-location.php'),window=new Events(),document=new Events(),elements=new Map(),calls=[],resolved=[],unavailable=[],historyCalls=[],observers=[];
 for(const id of ['phone-location','phone-info','phone-push-controls','phone-guide-notification-state','phone-push-enable','phone-push-disable','phone-push-feedback','phone-push-test','phone-push-test-state'])elements.set(id,new Element(id));
 if(options.noTestElements){elements.delete('phone-push-test');elements.delete('phone-push-test-state');}
 document.getElementById=id=>elements.get(id)||null;document.hidden=Boolean(options.hidden);elements.get('phone-info').hidden=Boolean(options.infoHidden);
 const facts={subscriptions:0,unsubscribed:0,permissionPrompts:0,inspections:0,gesture:false,order:[]};let permission=options.permission||'granted',device=fixture(),handler=options.request||null,registrationError=false,controller;
 const makeSub=()=>({endpoint,options:{userVisibleOnly:true,applicationServerKey:Uint8Array.from(Buffer.from(options.wrongKey?'B'.repeat(87):publicKey,'base64url')).buffer},toJSON:()=>({endpoint,expirationTime:null,keys:{p256dh:publicKey,auth:Buffer.alloc(16,7).toString('base64url')}}),unsubscribe:async()=>{facts.unsubscribed++;facts.order.push('unsubscribe');sub=null;return true;}});
 let sub=options.local===false?null:makeSub();
 const app=new URL('./phone-location.php',location),script=new URL('./phone-service-worker-v1.js',location);
 const registration={scope:options.wrongScope?new URL('./',location).href:app.href,active:{scriptURL:options.wrongScript?new URL('./other-worker.js',location).href:script.href},pushManager:{getSubscription:async()=>{facts.inspections++;if(options.inspectGate)await options.inspectGate.promise;return sub;},subscribe:settings=>{assert.equal(facts.gesture,true,'subscribe starts inside the original trusted gesture');assert.equal(settings.userVisibleOnly,true);assert.deepEqual(Buffer.from(settings.applicationServerKey),Buffer.from(publicKey,'base64url'));facts.subscriptions++;permission='granted';sub=makeSub();return options.subscribeGate?options.subscribeGate.promise.then(()=>sub):Promise.resolve(sub);}}};
 const navigator={standalone:options.installed!==false,onLine:options.online!==false,serviceWorker:{getRegistration:async url=>{assert.equal(url,app.href);if(registrationError)throw Error('Registration lookup failed');return registration;}}};
 const Notification={get permission(){return permission;},requestPermission(){assert.equal(facts.gesture,true,'permission prompt starts in the trusted gesture');facts.permissionPrompts++;permission='granted';return Promise.resolve('granted');}};
 window.isSecureContext=options.secure!==false;window.matchMedia=()=>({matches:options.installed!==false});window.Notification=Notification;window.PushManager=function(){};
 if(options.unsupported)delete window.PushManager;
 const history={replaceState:(_state,_title,url)=>{historyCalls.push(url);location.href=new URL(url,location).href;}};
 const bind=(next=device,available=true)=>{device=structuredClone(next);controller.bind(device,available);};
 const request=async(action,body)=>{
  calls.push({action,...structuredClone(body)});facts.order.push(action);
  if(handler)return handler(action,body,{device,bind,receipt:overrides=>receipt(device,overrides)});
  if(action==='push_register'){const next=structuredClone(device);next.push={...next.push,state:'registered',revision:next.push.revision+1,endpoint_hash:endpointHash};bind(next);return {push:structuredClone(device.push),device:structuredClone(device)};}
  if(action==='push_disable'){const next=structuredClone(device);next.push={...next.push,state:'off',revision:next.push.revision+1};delete next.push.endpoint_hash;bind(next);return {push:structuredClone(device.push),device:structuredClone(device)};}
  if(action==='push_test')return receipt(device);
  if(action==='push_resolve')return receipt(device,{opened_epoch:Math.floor(Date.now()/1000),target:{tab:'info'}});
  throw Error('Unexpected action');
 };
 const crypto={getRandomValues:value=>webcrypto.getRandomValues(value),subtle:{digest:async(algorithm,value)=>{assert.equal(algorithm,'SHA-256');return Uint8Array.from(createHash('sha256').update(Buffer.from(value)).digest()).buffer;}}};
 let clockOffset=0;const ClockDate=class extends Date{static now(){return Date.now()+clockOffset;}};
 const sandbox={window,document,navigator,location,history,Notification,crypto,performance:{now:()=>1000},TextEncoder,Uint8Array,ArrayBuffer,Date:ClockDate,Number,String,Boolean,Promise,Object,Array,Map,Set,RegExp,JSON,URL,
  atob:value=>Buffer.from(value,'base64').toString('binary'),MutationObserver:class{constructor(fn){observers.push(fn);}observe(){}},console};
 for(const name of ['localStorage','sessionStorage','indexedDB'])Object.defineProperty(sandbox,name,{get(){throw Error('Private push state must not be persisted in '+name);}});
 vm.runInNewContext(source,sandbox);controller=window.VP3PhonePushSubscriptionsV1({request,onResolved:value=>resolved.push(structuredClone(value)),onUnavailable:()=>unavailable.push(elements.get('phone-push-test-state')?.textContent)});
 const click=(id,trusted=true)=>{facts.gesture=trusted;const pending=elements.get(id).emit('click',{isTrusted:trusted});facts.gesture=false;return pending;};
 return {window,document,elements,calls,resolved,unavailable,historyCalls,facts,location,registration,controller,bind,click,get device(){return device;},advanceClock:milliseconds=>{clockOffset+=milliseconds;},setHandler:fn=>handler=fn,setRegistrationError:value=>registrationError=value,setPermission:value=>permission=value,setOnline:value=>navigator.onLine=value,setInfoHidden:value=>{elements.get('phone-info').hidden=value;observers.forEach(fn=>fn());},wake:()=>window.emit('pageshow'),dispose:()=>window.emit('pagehide')};
}
let checks=0;async function test(name,fn){await fn();checks++;console.log('PASS '+name);}
await test('actual PHP delivery projections preserve current blockers alongside historical tests',async()=>{
 const result=spawnSync(process.env.PHP_BINARY||'php',[new URL('./fixtures/phone-notification-status-v1.php',import.meta.url).pathname],{encoding:'utf8'});
 assert.equal(result.status,0,result.stderr||result.stdout);
 const cases=JSON.parse(result.stdout),expected={missing_upgrade:/Database Upgrade/,ready:/Send a test/,credentials:/credentials are unavailable/,stale_worker:/worker has not run recently/,runtime:/PHP 8\.2 or newer/};
 for(const item of cases){
  assert.equal(item.device.push.delivery.state,item.state);
  const b=setup({local:false});b.bind(item.device);await flush();
  const label=b.elements.get('phone-push-test-state').textContent;assert.match(label,expected[item.name]);
  if(['stale_worker','runtime'].includes(item.name))assert.match(label,/Last test:.*was opened/,'past success cannot replace current health');
  await b.dispose();
 }
});
async function ready(options={}){const b=setup(options);b.bind(options.device||fixture(),options.available!==false);await flush();return b;}
await test('opening Info only inspects a subscription and never requests permission or sends a test',async()=>{
 const b=await ready();assert.equal(b.facts.subscriptions,0);assert.equal(b.facts.permissionPrompts,0);assert.equal(b.calls.length,0);assert.equal(b.elements.get('phone-push-test').disabled,false);assert.match(b.elements.get('phone-push-test-state').textContent,/saved subscription does not confirm delivery/);await b.dispose();
});
await test('test notification requires the trusted gesture and exact local subscribed installation',async()=>{
 const b=await ready();await b.click('phone-push-test',false);assert.equal(b.calls.length,0);await b.click('phone-push-test');assert.equal(b.calls.length,1);const call=b.calls[0];assert.equal(call.action,'push_test');assert.equal(call.device_id,b.device.id);assert.equal(call.subscription_revision,4);assert.equal(call.confirmed,true);assert.match(call.request_id,/^[a-f0-9]{32}$/);assert.equal(Object.hasOwn(call,'generation'),false,'GPS generation is independent');assert.match(b.elements.get('phone-push-test-state').textContent,/queued/);assert.equal(b.facts.subscriptions,0);await b.dispose();
});
await test('permission install ownership and worker gates block test calls even through direct stale events',async()=>{
 const scenarios=[{permission:'denied'},{permission:'default'},{installed:false},{unsupported:true},{secure:false},{online:false},{hidden:true},{infoHidden:true},{available:false},{wrongKey:true},{wrongScope:true},{wrongScript:true},{local:false}];
 for(const options of scenarios){const b=await ready(options);assert.equal(b.elements.get('phone-push-test').disabled,true,JSON.stringify(options));await b.click('phone-push-test');assert.equal(b.calls.length,0);assert.equal(b.facts.subscriptions,0);await b.dispose();}
 for(const change of [d=>d.status='pending',d=>d.push.state='off',d=>d.push.delivery.ready=false,d=>d.push.delivery.enabled=false]){const d=fixture();change(d);const b=await ready({device:d});assert.equal(b.elements.get('phone-push-test').disabled,true);await b.click('phone-push-test');assert.equal(b.calls.length,0);await b.dispose();}
});
await test('a lost or changed registration invalidates previously observed test readiness',async()=>{
 for(const change of ['script','lookup']){const b=await ready();assert.equal(b.elements.get('phone-push-test').disabled,false);if(change==='script'){b.registration.active.scriptURL='https://phone.test/vp3/other-worker.js';await b.click('phone-push-test');assert.equal(b.calls.length,0,'Direct stale event cannot use a changed active script');}else b.setRegistrationError(true);await b.wake();await flush();assert.equal(b.elements.get('phone-push-test').disabled,true);await b.click('phone-push-test');assert.equal(b.calls.length,0);await b.dispose();}
});
await test('fresh worker availability and provider acceptance never claim device delivery',async()=>{
 const b=await ready();let d=structuredClone(b.device);d.push.delivery.ready=false;d.push.delivery.worker.state='never_seen';b.bind(d);assert.match(b.elements.get('phone-push-test-state').textContent,/once-per-minute.*cron/);assert.equal(b.elements.get('phone-push-test').disabled,true);
 d.push.delivery.ready=true;d.push.delivery.worker.state='observed';d.push.delivery.last_test=receipt(d,{state:'accepted',provider_accepted_epoch:Math.floor(Date.now()/1000)});b.bind(d);assert.match(b.elements.get('phone-push-test-state').textContent,/push service accepted.*Display on this device is not confirmed/);assert.doesNotMatch(b.elements.get('phone-push-test-state').textContent,/delivered|showing|opened/);await b.dispose();
});
await test('client display reports and authenticated opens have distinct truthful labels',async()=>{
 const b=await ready();let d=structuredClone(b.device);d.push.delivery.last_test=receipt(d,{state:'accepted',client_observed_epoch:Math.floor(Date.now()/1000)});b.bind(d);assert.match(b.elements.get('phone-push-test-state').textContent,/installation reported showing/);assert.equal(b.resolved.length,0,'Status alone does not open private content');d.push.delivery.last_test.opened_epoch=Math.floor(Date.now()/1000);b.bind(d);assert.match(b.elements.get('phone-push-test-state').textContent,/opened in VP3 Phone/);assert.equal(b.resolved.length,0);await b.dispose();
});
await test('duplicate clicks share one in-flight test and explicit retry reuses its idempotency key',async()=>{
 const gate=defer(),b=await ready({request:()=>gate.promise});const first=b.click('phone-push-test');await flush();await b.click('phone-push-test');assert.equal(b.calls.length,1);gate.reject(Error('uncertain private endpoint'));await first;assert.doesNotMatch(b.elements.get('phone-push-test-state').textContent,/private endpoint/);assert.match(b.elements.get('phone-push-test-state').textContent,/could not be confirmed/);
 b.setHandler((_action,_body,{receipt})=>receipt());await b.click('phone-push-test');assert.equal(b.calls.length,2);assert.equal(b.calls[0].request_id,b.calls[1].request_id);await b.click('phone-push-test');assert.equal(b.calls[2].request_id,b.calls[0].request_id);await b.dispose();
});
await test('a polled display or terminal receipt allows a fresh test on the next trusted click',async()=>{
 for(const settled of [{client_observed_epoch:Math.floor(Date.now()/1000)},{opened_epoch:Math.floor(Date.now()/1000)},{state:'failed'},{state:'cancelled'},{state:'expired'}]){const b=await ready();await b.click('phone-push-test');const previous=b.calls[0].request_id,d=structuredClone(b.device);d.push.delivery.last_test=receipt(d,settled);b.bind(d);b.setHandler((_action,_body,{receipt})=>receipt({event_ref:'c'.repeat(32)}));await b.click('phone-push-test');assert.equal(b.calls.length,2);assert.notEqual(b.calls[1].request_id,previous);assert.match(b.elements.get('phone-push-test-state').textContent,/queued/);await b.dispose();}
});
await test('accepted and uncertain tests allow a fresh request after the delivery window expires',async()=>{
 for(const state of ['accepted','uncertain'])for(const polled of [false,true]){
  const b=await ready({request:(_action,_body,{receipt})=>receipt({state})});await b.click('phone-push-test');const previous=b.calls[0].request_id;
  if(polled){const d=structuredClone(b.device);d.push.delivery.last_test=receipt(d,{state});b.bind(d);}
  b.advanceClock(301000);await b.wake();assert.match(b.elements.get('phone-push-test-state').textContent,/window expired without a display report/);
  b.setHandler((_action,_body,{receipt})=>receipt({event_ref:'c'.repeat(32),expires_epoch:Math.floor(Date.now()/1000)+900}));await b.click('phone-push-test');
  assert.notEqual(b.calls[1].request_id,previous);assert.match(b.elements.get('phone-push-test-state').textContent,/queued/);await b.dispose();
 }
});
await test('accepted and uncertain receipts retain the same request while their delivery window is active',async()=>{
 for(const state of ['accepted','uncertain']){
  const b=await ready({request:(_action,_body,{receipt})=>receipt({state})});await b.click('phone-push-test');const d=structuredClone(b.device);d.push.delivery.last_test=receipt(d,{state});b.bind(d);b.advanceClock(60000);
  await b.click('phone-push-test');assert.equal(b.calls[1].request_id,b.calls[0].request_id);assert.doesNotMatch(b.elements.get('phone-push-test-state').textContent,/expired/);await b.dispose();
 }
});
await test('malformed foreign and stale-revision test receipts cannot claim success',async()=>{
 for(const overrides of [{event_ref:'not-a-reference'},{device_id:'e'.repeat(32)},{subscription_revision:3},{state:'delivered'},{client_observed_epoch:'1'},{opened_epoch:Math.floor(Date.now()/1000)+3600},{expires_epoch:0}]){const b=await ready({request:(_action,_body,{receipt})=>receipt(overrides)});await b.click('phone-push-test');assert.match(b.elements.get('phone-push-test-state').textContent,/could not be confirmed/);assert.doesNotMatch(b.elements.get('phone-push-test-state').textContent,/reported showing|was opened|queued/);await b.dispose();}
});
await test('device revision and page changes fence late test callbacks',async()=>{
 for(const change of ['device','revision','revoked','pagehide']){const gate=defer(),b=await ready({request:()=>gate.promise}),old=structuredClone(b.device),pending=b.click('phone-push-test');await flush();const next=structuredClone(b.device);if(change==='device')next.id='e'.repeat(32);if(change==='revision')next.push.revision++;if(change==='revoked')next.status='revoked';if(change==='pagehide')await b.dispose();else b.bind(next);gate.resolve(receipt(old,{client_observed_epoch:Math.floor(Date.now()/1000)}));await pending;await flush();assert.doesNotMatch(b.elements.get('phone-push-test-state').textContent,/reported showing/);if(change==='device')assert.equal(b.elements.get('phone-push-test').disabled,false,'Old completion cannot leave the new device permanently busy');await b.dispose();}
});
await test('GPS-only generation changes do not change push consent or reject a matching receipt',async()=>{
 const gate=defer(),b=await ready({request:()=>gate.promise}),original=structuredClone(b.device),pending=b.click('phone-push-test');await flush();const changed=structuredClone(b.device);changed.generation+=10;b.bind(changed);gate.resolve(receipt(original));await pending;assert.match(b.elements.get('phone-push-test-state').textContent,/queued/);await b.dispose();
});
await test('registration still begins in the trusted gesture and preserves pre-delivery wording',async()=>{
 const d=fixture();d.push.state='off';d.push.revision=0;delete d.push.endpoint_hash;delete d.push.delivery;d.push.delivery_ready=false;
 const b=await ready({device:d,local:false,permission:'default',noTestElements:true});assert.equal(b.elements.get('phone-push-enable').disabled,false);await b.click('phone-push-enable',false);assert.equal(b.facts.subscriptions,0);await b.click('phone-push-enable');await flush();assert.equal(b.facts.permissionPrompts,1);assert.equal(b.facts.subscriptions,0);assert.equal(b.calls.length,0);await b.click('phone-push-enable');await flush();assert.equal(b.facts.subscriptions,1);assert.equal(b.calls[0].action,'push_register');assert.equal(b.calls[0].subscription_revision,0);assert.match(b.elements.get('phone-push-feedback').textContent,/Background delivery is not active yet/);assert.match(b.elements.get('phone-guide-notification-state').textContent,/Notifications allowed · subscription saved/);await b.dispose();
});
await test('disable removes server authority before unsubscribing only the exact local subscription',async()=>{
 const b=await ready();await b.click('phone-push-disable');await flush();assert.deepEqual(b.facts.order,['push_disable','unsubscribe']);assert.equal(b.facts.unsubscribed,1);assert.equal(b.device.push.state,'off');assert.equal(b.elements.get('phone-push-test').disabled,true);await b.dispose();
 const foreign=await ready({wrongKey:true});await foreign.click('phone-push-disable');await flush();assert.equal(foreign.facts.unsubscribed,0);await foreign.dispose();
});
await test('late subscription completion cannot register into another pairing',async()=>{
 const gate=defer(),d=fixture();d.push.state='off';d.push.revision=0;delete d.push.endpoint_hash;const b=await ready({device:d,local:false,permission:'granted',subscribeGate:gate}),pending=b.click('phone-push-enable');await flush();const changed=structuredClone(d);changed.id='e'.repeat(32);b.bind(changed);gate.resolve();await pending;await flush();assert.equal(b.calls.length,0);assert.equal(b.facts.subscriptions,1);await b.dispose();
});
await test('opaque tap reference is removed from the URL and waits for authenticated foreground device binding',async()=>{
 const b=setup({url:'https://phone.test/vp3/phone-location.php?keep=1&push_event='+eventRef+'#review',infoHidden:true});assert.equal(b.location.search,'?keep=1');assert.equal(b.location.hash,'#review');assert.equal(b.historyCalls.length,1);assert.equal(b.calls.length,0);b.bind(fixture(),false);await flush();assert.equal(b.calls.length,0);b.bind(fixture(),true);await flush();assert.equal(b.calls.length,1);assert.deepEqual(b.calls[0],{action:'push_resolve',device_id:'d'.repeat(32),event_ref:eventRef});assert.equal(b.resolved.length,1);assert.deepEqual(b.resolved[0].target,{tab:'info'});assert.equal(b.facts.subscriptions,0);await b.dispose();
});
await test('authenticated tap resolves fixed Chat and request targets without private data from the push',async()=>{
 for(const target of [{tab:'chat',request_id:requestId},{tab:'requests',exchange_id:'b'.repeat(32)},{tab:'info'},{tab:'phone'}]){const b=setup({url:'https://phone.test/vp3/phone-location.php?push_event='+eventRef,request:(_action,_body,{receipt})=>receipt({state:'uncertain',opened_epoch:Math.floor(Date.now()/1000),target,private_text:'never copied from this envelope'})});b.bind(fixture());await flush();assert.equal(b.resolved.length,1);assert.deepEqual(b.resolved[0].target,target);assert.equal(b.resolved[0].state,'uncertain');assert.equal(JSON.stringify(b.resolved).includes('private_text'),false);assert.equal([...b.elements.values()].some(element=>element.textContent.includes('never copied')),false);await b.dispose();}
});
await test('unknown URLs malformed targets and unconfirmed opens never hydrate private source',async()=>{
 const invalid=[{target:{tab:'admin',url:'/admin'}},{target:{tab:'chat',request_id:'../private'}},{target:{tab:'requests',exchange_id:'b'.repeat(32),url:'/private'}},{target:{tab:'info',message_id:4}},{target:{tab:'chat'}},{target:{tab:'requests',exchange_id:'B'.repeat(32)}},{opened_epoch:0},{event_ref:'c'.repeat(32)},{device_id:'e'.repeat(32)},{subscription_revision:3}];
 for(const overrides of invalid){const b=setup({url:'https://phone.test/vp3/phone-location.php?push_event='+eventRef,infoHidden:true,request:(_action,_body,{receipt})=>receipt({opened_epoch:Math.floor(Date.now()/1000),target:{tab:'info'},...overrides})});b.bind(fixture());await flush();assert.equal(b.resolved.length,0);assert.match(b.elements.get('phone-push-test-state').textContent,/no longer available/);assert.equal(b.unavailable.length,1,'Current-scope failure asks the host to show Info');assert.equal(b.unavailable[0],b.elements.get('phone-push-test-state').textContent,'Safe notice is rendered before the host callback');assert.equal(b.calls.length,1);await b.dispose();}
});
await test('malformed duplicate and oversized URL references are removed without an API action',async()=>{
 for(const query of ['push_event=../../admin','push_event='+eventRef+'&push_event='+eventRef,'push_event='+'a'.repeat(3000),'push_event=']){const b=setup({url:'https://phone.test/vp3/phone-location.php?'+query});b.bind(fixture());await flush();assert.equal(b.location.search,'');assert.equal(b.calls.length,0);assert.equal(b.resolved.length,0);await b.dispose();}
});
await test('a tap response from an old pairing or subscription cannot open a panel in the new scope',async()=>{
 for(const change of ['device','revision','revoked','pagehide']){const gate=defer(),b=setup({url:'https://phone.test/vp3/phone-location.php?push_event='+eventRef,request:()=>gate.promise}),original=fixture();b.bind(original);await flush();const changed=structuredClone(original);if(change==='device')changed.id='e'.repeat(32);if(change==='revision')changed.push.revision++;if(change==='revoked')changed.status='revoked';if(change==='pagehide')await b.dispose();else b.bind(changed);gate.resolve(receipt(original,{opened_epoch:Math.floor(Date.now()/1000),target:{tab:'chat',request_id:requestId}}));await flush();assert.equal(b.resolved.length,0,change);assert.equal(b.unavailable.length,0,'Stale response cannot switch the new scope to Info');await b.dispose();}
});
await test('HTTP auth errors and raw upstream failures produce no private source or technical disclosure',async()=>{
 const b=setup({url:'https://phone.test/vp3/phone-location.php?push_event='+eventRef,infoHidden:true,request:()=>Promise.reject(Object.assign(Error('SQLSTATE secret provider URL'),{status:403}))});b.bind(fixture());await flush();assert.equal(b.resolved.length,0);assert.match(b.elements.get('phone-push-test-state').textContent,/no longer available/);assert.equal(b.unavailable.length,1);assert.equal([...b.elements.values()].some(element=>element.textContent.includes('SQLSTATE')),false);await b.dispose();
});
await test('server enrollment is not required to show the direct OS permission prompt',async()=>{
 const d=fixture();d.push.state='off';d.push.enrollment_enabled=false;delete d.push.endpoint_hash;
 const b=await ready({device:d,local:false,permission:'default'});
 assert.equal(b.facts.permissionPrompts,0);assert.equal(b.elements.get('phone-push-enable').disabled,false);
 await b.click('phone-push-enable',false);assert.equal(b.facts.permissionPrompts,0);
 await b.click('phone-push-enable');assert.equal(b.facts.permissionPrompts,1);assert.equal(b.calls.length,0);assert.equal(b.facts.subscriptions,0);
 assert.equal(b.elements.get('phone-push-enable').disabled,true,'server enrollment is still required before subscribing');await b.dispose();
});
await test('worker activation can repair a previously unavailable registration without reloading',async()=>{
 const d=fixture();d.push.state='off';delete d.push.endpoint_hash;
 const b=await ready({device:d,local:false,wrongScript:true,permission:'granted'});
 assert.equal(b.elements.get('phone-push-enable').disabled,true);
 b.registration.active.scriptURL='https://phone.test/vp3/phone-service-worker-v1.js';await b.window.emit('vp3:phone-pwa-worker-state');await flush();
 assert.equal(b.elements.get('phone-push-enable').disabled,false);assert.equal(b.facts.permissionPrompts,0);assert.equal(b.facts.subscriptions,0);await b.dispose();
});
console.log('PHONE_PUSH_DELIVERY_UI_V1=PASS ('+checks+' cases; actual subscription UI, consent, stale results and authenticated tap targets)');
