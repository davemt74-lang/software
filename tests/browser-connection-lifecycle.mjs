import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {webcrypto} from 'node:crypto';
const source=fs.readFileSync('browser-companion/background.js','utf8');
const event=()=>({addListener(){}});
const local={base_url:'https://vp3.me',device_token:'a'.repeat(64)};
const calls=[];
let responder,authFlow,messageHandler;
const chrome={
  storage:{local:{async get(keys){return Object.fromEntries(keys.map(k=>[k,local[k]]));},async set(values){Object.assign(local,values);},async remove(keys){keys.forEach(k=>delete local[k]);}},session:{async get(){return {};},async set(){},async remove(){}}},
  runtime:{onInstalled:event(),onStartup:event(),onMessage:{addListener(fn){messageHandler=fn;}},getURL:p=>'chrome-extension://test/'+p},
  identity:{getRedirectURL:()=> 'https://hfolffhjjhgjmomfkeefhndkgehombbk.chromiumapp.org/vp3-connect',launchWebAuthFlow:args=>authFlow(args)},
  contextMenus:{onClicked:event()},tabs:{onUpdated:event(),onRemoved:event(),onCreated:event()},
  alarms:{onAlarm:event(),async get(){return true;},async create(){}},notifications:{onClicked:event(),onButtonClicked:event(),onClosed:event()},
  permissions:{async contains(){return true;},async request(){return true;}},sidePanel:{}
};
const timers=new Set();
const context=vm.createContext({chrome,URL,Headers,AbortController,TextEncoder,crypto:webcrypto,importScripts(){},console,
 setTimeout(fn,ms){const id=setTimeout(fn,ms);timers.add(id);return id;},clearTimeout(id){clearTimeout(id);timers.delete(id);},
 fetch:async(url,options)=>{calls.push({url,options});return responder(url,options);}});
vm.runInContext(source,context);
const run=expression=>vm.runInContext(expression,context);
const response=(status,payload)=>({ok:status>=200&&status<300,status,async json(){return payload;}});
const deferred=()=>{let resolve;const promise=new Promise(r=>resolve=r);return {promise,resolve};};
const flush=()=>new Promise(r=>setImmediate(r));
for (const status of [401,503,200]) {
 local.device_token='a'.repeat(64);const wait=deferred();responder=()=>wait.promise;
 const request=run("authorizedFetch('/test')");await flush();local.device_token='b'.repeat(64);
 wait.resolve(response(status,{ok:status===200,error:{message:'not available'}}));
 await assert.rejects(request);
 assert.equal(local.device_token,'b'.repeat(64));assert.equal(timers.size,0);
}
console.log('PASS stale 401, failure and success cannot erase or return data from a replacement connection');
local.device_token='a'.repeat(64);responder=async()=>response(401,{ok:false});
await assert.rejects(run("authorizedFetch('/test')"));assert.equal(local.device_token,undefined);
console.log('PASS current credential revocation still clears the saved token');

let wait=deferred();authFlow=()=>wait.promise;
const connect=run('beginConnect()');await flush();await assert.rejects(run('beginConnect()'),/already in progress/);
await run('disconnect()');
wait.resolve(chrome.identity.getRedirectURL()+'?state=invalid&code='+'c'.repeat(64));
await assert.rejects(connect,/cancelled or changed/);assert.equal(local.device_token,undefined);
console.log('PASS duplicate connect is blocked and disconnect cancels unfinished authorization');

for(const target of ['https://evil.example/vp3-connect','https://hfolffhjjhgjmomfkeefhndkgehombbk.chromiumapp.org/other']){
 authFlow=async({url})=>target+'?state='+new URL(url).searchParams.get('state')+'&code='+'c'.repeat(64);
 await assert.rejects(run('beginConnect()'),/callback/);assert.equal(local.device_token,undefined);
}
console.log('PASS callback origin and path are bound to Chrome redirect URL as well as random state');

wait=deferred();local.device_token='a'.repeat(64);responder=()=>wait.promise;
const disconnect=run('disconnect()');await flush();local.device_token='b'.repeat(64);
wait.resolve(response(200,{ok:true}));await disconnect;
assert.equal(local.device_token,'b'.repeat(64));
assert.equal(calls.at(-1).options.headers.get('Authorization'),'Bearer '+'a'.repeat(64));
console.log('PASS delayed disconnect only revokes and clears the original credential');
assert.equal(timers.size,0);
