import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

class Node {
    constructor(){this.dataset={};this.textContent='';this.hidden=false;this.disabled=false;this.children=[];this.attrs={};this.handlers={};this.style={};}
    querySelector(selector){return nodes[selector];}
    setAttribute(k,v){this.attrs[k]=v;}
    getAttribute(k){return this.attrs[k]??null;}
    addEventListener(k,v){this.handlers[k]=v;}
    replaceChildren(...children){this.children=children;}
    append(...children){this.children.push(...children);}
}
const nodes=Object.fromEntries(['refresh','status','summary','list','default','count','time'].map(x=>['[data-inventory-'+x+']',new Node()]));
const root=new Node();root.dataset={api:'/api/homeserver-model-inventory-v1.php',csrf:'fixture-csrf'};
const pill=new Node(),device=new Node(),connection=new Node(),disconnect=new Node(),claimForm=new Node(),claimRoot=new Node();
claimRoot.querySelector=()=>claimForm;
pill.dataset.state='loading';device.textContent='—';
const ids={hsStatePill:pill,hsDeviceId:device,hsConnectCard:connection,hsDisconnect:disconnect};
let observe,fetchCalls=[],pending=[];
const window=new Node();
const timers=new Map();let timerId=0;
const context={document:{querySelector:selector=>selector==='[data-hs-device-claim]'?claimRoot:root,getElementById:id=>ids[id],createElement:()=>new Node(),createDocumentFragment:()=>new Node()},
    window,MutationObserver:class{constructor(fn){observe=fn;}observe(){}},AbortController,URLSearchParams,Date,
    setTimeout:fn=>{timers.set(++timerId,fn);return timerId;},clearTimeout:id=>timers.delete(id),
    fetch:(url,opts)=>{fetchCalls.push([url,opts]);return new Promise(resolve=>pending.push(resolve));}};
vm.runInNewContext(fs.readFileSync('homeserver-model-inventory-v1.js','utf8'),context);
const button=nodes['[data-inventory-refresh]'],status=nodes['[data-inventory-status]'],list=nodes['[data-inventory-list]'];
assert.equal(button.disabled,true);assert.equal(fetchCalls.length,0);
pill.dataset.state='connected';device.textContent='hs-fixture';observe();
assert.equal(button.disabled,false);assert.equal(fetchCalls.length,0);
const snapshot={ok:true,checked_at:'2026-11-26T09:00:00Z',inventory:{version:'vp3.local-model-inventory.v1',state:'reported',configured_model:'qwen:latest',configured_model_reported:true,models:[{model:'qwen:latest',configured_default:true,routing_eligible:true}]}};
const response=(body,status=200)=>({ok:status===200,status,json:async()=>body});
let run=button.handlers.click();
assert.equal(button.disabled,true);assert.equal(fetchCalls.length,1);
assert.equal(fetchCalls[0][1].method,'POST');assert.equal(fetchCalls[0][1].body.get('csrf_token'),'fixture-csrf');
await button.handlers.click();assert.equal(fetchCalls.length,1);
pending.shift()(response(snapshot));await run;
assert.equal(nodes['[data-inventory-count]'].textContent,'1');
assert.equal(list.hidden,false);
assert.match(list.children[0].children[0].children[0].children[1].textContent,/Not eligible for routing/);
run=button.handlers.click();device.textContent='hs-other';observe();
assert.equal(list.hidden,true);assert.equal(fetchCalls[1][1].signal.aborted,true);
pending.shift()(response(snapshot));await run;
assert.equal(list.hidden,true);assert.equal(nodes['[data-inventory-count]'].textContent,'0');
run=button.handlers.click();pending.shift()(response({ok:true,inventory:{version:'vp3.local-model-inventory.v1',state:'unsupported',models:[]}}));await run;
assert.match(status.textContent,/Update HomeServer/);
assert.equal(nodes['[data-inventory-default]'].textContent,'Unknown');
assert.equal(nodes['[data-inventory-count]'].textContent,'Not available');
run=button.handlers.click();pending.shift()(response({ok:true,inventory:{...snapshot.inventory,state:'unreachable',models:[],configured_model_reported:false}}));await run;
assert.match(nodes['[data-inventory-default]'].textContent,/discovery unavailable/);
assert.doesNotMatch(nodes['[data-inventory-default]'].textContent,/not reported/);
assert.equal(nodes['[data-inventory-count]'].textContent,'Not available');
run=button.handlers.click();pending.shift()(response({ok:true,inventory:{...snapshot.inventory,models:[],configured_model_reported:false,truncated:true}}));await run;
assert.match(nodes['[data-inventory-default]'].textContent,/not in returned list/);
run=button.handlers.click();pending.shift()(response(snapshot));await run;
claimForm.handlers.submit();assert.equal(list.hidden,true);
claimRoot.setAttribute('aria-busy','true');observe();assert.equal(button.disabled,true);
const beforeClaim=fetchCalls.length;await button.handlers.click();assert.equal(fetchCalls.length,beforeClaim);
claimForm.hidden=true;claimRoot.setAttribute('aria-busy','false');observe();assert.equal(button.disabled,false);assert.equal(list.hidden,true);
run=button.handlers.click();claimForm.handlers.submit();pending.shift()(response(snapshot));await run;
assert.equal(list.hidden,true,'direct device-code pairing suppresses old inventory even for the same device');
run=button.handlers.click();[...timers.values()].at(-1)();
assert.match(status.textContent,/timed out/);assert.equal(button.disabled,false);assert.equal(fetchCalls.at(-1)[1].signal.aborted,true);
pending.shift()(response(snapshot));await run;assert.equal(list.hidden,true);
run=button.handlers.click();pending.shift()(response({ok:false,error:'PRIVATE ERROR'},503));await run;
assert.doesNotMatch(status.textContent,/PRIVATE ERROR/);assert.equal(list.hidden,true);
run=button.handlers.click();disconnect.handlers.click();pending.shift()(response(snapshot));await run;
assert.equal(list.hidden,true);
pill.dataset.state='disconnected';observe();assert.equal(button.disabled,true);
const api=fs.readFileSync('api/homeserver-model-inventory-v1.php','utf8');
assert.match(api,/current_user\(\)/);assert.match(api,/has_permission\('account.access'/);assert.match(api,/hash_equals\(csrf_token\(\),\$csrf\)/);
assert.match(api,/vp3_homeserver_inventory_read_v1\(\$owner\)/);assert.match(api,/session_write_close\(\)/);
const page=fs.readFileSync('settings-homeserver.php','utf8');
assert.match(page,/data-hs-model-inventory/);assert.match(page,/homeserver-model-inventory-v1\.js/);
console.log('HomeServer inventory settings UI contract passed');
