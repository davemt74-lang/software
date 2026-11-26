import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../phone-service-worker-v1.js',import.meta.url),'utf8');
const reference='a'.repeat(32),token=Buffer.alloc(32,17).toString('base64url');
const payload={v:1,event_ref:reference,receipt_token:token};
const defer=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return {promise,resolve,reject};};
const flush=async()=>{for(let i=0;i<12;i++)await Promise.resolve();};
function setup(options={}){
 const base=options.base||'https://phone.test/vp3/',script=new URL('phone-service-worker-v1.js',base),app=new URL('phone-location.php',base);
 const handlers=new Map(),shown=[],requests=[],opened=[],timers=new Map(),facts={skip:0,claim:0,matches:0};
 const self={location:script,registration:{scope:options.scope||app.href,showNotification:(title,data)=>{shown.push({title,...structuredClone(data)});return options.show?options.show(title,data):Promise.resolve();}},
  clients:{claim:async()=>{facts.claim++;},matchAll:async value=>{facts.matches++;assert.deepEqual(JSON.parse(JSON.stringify(value)),{type:'window',includeUncontrolled:true});return options.clients||[];},openWindow:async url=>{opened.push(url);return null;}},
  skipWaiting:async()=>{facts.skip++;},addEventListener:(name,fn)=>handlers.set(name,fn)};
 const sandbox={self,URL,JSON,Object,Array,Number,String,RegExp,Promise,AbortController,
  fetch:async(url,request)=>{requests.push({url,request});return options.fetch?options.fetch(url,request):{ok:true,status:200};},
  setTimeout:(fn,ms)=>{const id=Symbol('timeout');timers.set(id,{fn,ms});return id;},clearTimeout:id=>timers.delete(id)};
 for(const key of ['caches','indexedDB','navigator','localStorage','sessionStorage'])Object.defineProperty(sandbox,key,{get(){throw Error('Forbidden background state or sensors: '+key);}});
 vm.runInNewContext(source,sandbox);
 const fire=(name,fields={})=>{const waits=[],event={...fields,waitUntil:promise=>waits.push(Promise.resolve(promise))};handlers.get(name)(event);return {event,promise:Promise.all(waits)};};
 const push=value=>fire('push',{data:value===undefined?undefined:{text:()=>typeof value==='string'?value:JSON.stringify(value)}});
 const click=(notification=shown.at(-1),action='')=>{let closed=0;const fired=fire('notificationclick',{action,notification:{...notification,close:()=>{closed++;}}});return {...fired,closed:()=>closed};};
 return {handlers,shown,requests,opened,facts,timers,app,fire,push,click,expire:()=>{for(const [id,timer] of [...timers]){timers.delete(id);assert.equal(timer.ms,5000);timer.fn();}}};
}
let checks=0;async function test(name,fn){await fn();checks++;console.log('PASS '+name);}
await test('installation lifecycle keeps the exact existing scope without fetch or background handlers',async()=>{
 const b=setup();await b.fire('install').promise;await b.fire('activate').promise;assert.equal(b.facts.skip,1);assert.equal(b.facts.claim,1);assert.deepEqual([...b.handlers.keys()],['install','activate','push','notificationclick']);assert.equal(b.requests.length,0);
});
await test('valid push shows only fixed generic text and keeps receipt capability out of notification data',async()=>{
 const b=setup();await b.push(payload).promise;assert.equal(b.shown.length,1);const notification=b.shown[0];assert.equal(notification.title,'VP3 Phone');assert.equal(notification.body,'Open VP3 Phone to check your updates.');assert.equal(notification.silent,false);assert.equal(notification.renotify,false);assert.equal(notification.tag,'vp3-phone-'+reference);assert.deepEqual(notification.data,{v:1,event_ref:reference});assert.equal(notification.icon,'https://phone.test/vp3/assets/phone-pwa/icon-192.png');assert.equal(Object.hasOwn(notification,'actions'),false);assert.equal(JSON.stringify(notification).includes(token),false);
});
await test('display receipt is a bounded credential-free POST to the fixed deployment-local endpoint',async()=>{
 const b=setup();await b.push(payload).promise;assert.equal(b.requests.length,1);const {url,request}=b.requests[0];assert.equal(url,'https://phone.test/vp3/api/phone-push-receipt-v1.php');assert.equal(request.method,'POST');assert.equal(request.credentials,'omit');assert.equal(request.mode,'same-origin');assert.equal(request.cache,'no-store');assert.equal(request.redirect,'error');assert.equal(request.referrerPolicy,'no-referrer');assert.equal(request.headers['X-VP3-Phone'],'1');assert.equal(request.headers['Content-Type'],'application/json');assert.deepEqual(JSON.parse(request.body),{event_ref:reference,receipt_token:token,kind:'displayed'});assert.equal(b.timers.size,0);
});
await test('no displayed receipt is sent before notification rendering succeeds',async()=>{
 const gate=defer(),b=setup({show:()=>gate.promise}),pending=b.push(payload);await flush();assert.equal(b.shown.length,1);assert.equal(b.requests.length,0);gate.resolve();await pending.promise;assert.equal(b.requests.length,1);
});
await test('a rejected showNotification cannot invent a displayed receipt',async()=>{
 const b=setup({show:()=>Promise.reject(Error('OS denied display'))});await assert.rejects(b.push(payload).promise,/OS denied display/);assert.equal(b.shown.length,1);assert.equal(b.requests.length,0);
});
await test('receipt transport failures do not suppress the visible notification or create retries',async()=>{
 for(const response of [()=>Promise.reject(Error('offline')),()=>({ok:false,status:503})]){const b=setup({fetch:response});await b.push(payload).promise;assert.equal(b.shown.length,1);assert.equal(b.requests.length,1);assert.equal(b.timers.size,0);}
});
await test('a hung receipt request aborts after its bounded lifetime',async()=>{
 const b=setup({fetch:(_url,{signal})=>new Promise((resolve,reject)=>signal.addEventListener('abort',()=>reject(new DOMException('aborted','AbortError')),{once:true}))}),pending=b.push(payload);await flush();assert.equal(b.shown.length,1);assert.equal(b.timers.size,1);b.expire();await pending.promise;assert.equal(b.requests[0].request.signal.aborted,true);assert.equal(b.requests.length,1);
});
await test('missing malformed oversized and unknown payloads still display a generic alert without a capability or tap action',async()=>{
 const invalid=[undefined,'{',null,[],{},'x'.repeat(1025),{...payload,v:2},{...payload,event_ref:'../../admin.php'},{...payload,event_ref:'A'.repeat(32)},{...payload,receipt_token:'x'.repeat(43)},{...payload,url:'https://private.invalid/secret'},{...payload,device_id:'d'.repeat(32)},{...payload,title:'Private Chat text',body:'private transcript',actions:[{action:'call'}]}];
 for(const value of invalid){const b=setup();await b.push(value).promise;assert.equal(b.shown.length,1);assert.equal(b.shown[0].title,'VP3 Phone');assert.equal(b.shown[0].data,null);assert.equal(b.requests.length,0);assert.doesNotMatch(JSON.stringify(b.shown),/private transcript|private\.invalid|Private Chat text/);const click=b.click();await click.promise;assert.equal(click.closed(),1);assert.equal(b.opened.length,0);assert.equal(b.facts.matches,0);}
});
await test('unexpected worker scope displays a generic alert but cannot navigate or issue a receipt',async()=>{
 const b=setup({scope:'https://phone.test/'});await b.push(payload).promise;assert.equal(b.shown.length,1);assert.equal(b.shown[0].data,null);assert.equal(b.requests.length,0);await b.click({data:{v:1,event_ref:reference},tag:'vp3-phone-'+reference}).promise;assert.equal(b.opened.length,0);
});
await test('duplicate pushes use one stable event tag while each push stays user-visible',async()=>{
 const b=setup();await b.push(payload).promise;await b.push(payload).promise;assert.equal(b.shown.length,2);assert.equal(b.shown[0].tag,b.shown[1].tag);assert.ok(b.shown.every(notification=>notification.silent===false&&notification.renotify===false));await b.push({...payload,event_ref:'b'.repeat(32)}).promise;assert.notEqual(b.shown[0].tag,b.shown[2].tag);
});
await test('a valid tap opens only the exact app with a bounded opaque reference',async()=>{
 const b=setup();await b.push(payload).promise;const click=b.click();await click.promise;assert.equal(click.closed(),1);assert.deepEqual(b.opened,['https://phone.test/vp3/phone-location.php?push_event='+reference]);assert.equal(b.requests.length,1,'A tap does not send a capability-based opened receipt or fetch private source');
});
await test('an existing exact-app window navigates and focuses through the authenticated route',async()=>{
 const actions=[];const client={url:'https://phone.test/vp3/phone-location.php?old=1#fragment',focused:true,navigate:async url=>{actions.push(['navigate',url]);client.url=url;return client;},focus:async()=>{actions.push(['focus']);return client;}};
 const b=setup({clients:[client]});await b.push(payload).promise;await b.click().promise;assert.deepEqual(actions,[['navigate','https://phone.test/vp3/phone-location.php?push_event='+reference],['focus']]);assert.equal(b.opened.length,0);
});
await test('foreign origin sibling prefix credentialed and encoded paths are never selected as the Phone app',async()=>{
 let touched=0;const urls=['https://other.test/vp3/phone-location.php','https://phone.test/vp3/chat.php','https://phone.test/vp3/phone-location.php/extra','https://phone.test/vp3/phone-location.php.evil','https://user@phone.test/vp3/phone-location.php','https://phone.test/vp3/%70hone-location.php','javascript:alert(1)'];
 const clients=urls.map(url=>({url,navigate:async()=>{touched++;},focus:async()=>{touched++;}}));const b=setup({clients});await b.push(payload).promise;await b.click().promise;assert.equal(touched,0);assert.deepEqual(b.opened,[b.app.href+'?push_event='+reference]);
});
await test('a stale window or failed navigation falls back to opening the same fixed app route',async()=>{
 for(const navigate of [async()=>{throw Error('closed');},async()=>null,async()=>({url:'https://untrusted.invalid/',focus:()=>{throw Error('foreign focus');}})]){const b=setup({clients:[{url:'https://phone.test/vp3/phone-location.php',navigate,focus:async()=>{}}]});await b.push(payload).promise;await b.click().promise;assert.deepEqual(b.opened,[b.app.href+'?push_event='+reference]);}
});
await test('unsupported actions forged tags and injected notification data cannot navigate',async()=>{
 for(const entry of [{action:'call',data:{v:1,event_ref:reference},tag:'vp3-phone-'+reference},{action:'',data:{v:1,event_ref:reference,url:'/admin'},tag:'vp3-phone-'+reference},{action:'',data:{v:1,event_ref:reference},tag:'another-app'},{action:'',data:{v:1,event_ref:'%2f%2fevil'},tag:'vp3-phone-%2f%2fevil'}]){const b=setup();await b.click(entry,entry.action).promise;assert.equal(b.opened.length,0);assert.equal(b.facts.matches,0);assert.equal(b.requests.length,0);}
});
await test('root and nested deployments derive their own exact app and receipt paths',async()=>{
 for(const base of ['https://phone.test/','https://phone.test/apps/vp3/']){const b=setup({base});await b.push(payload).promise;await b.click().promise;assert.equal(b.requests[0].url,base+'api/phone-push-receipt-v1.php');assert.equal(b.opened[0],base+'phone-location.php?push_event='+reference);}
});
console.log('PHONE_PUSH_SERVICE_WORKER_V1=PASS ('+checks+' cases; actual shipped worker, visible alerts, fixed routes and bounded receipts)');
