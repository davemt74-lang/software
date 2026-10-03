import assert from 'node:assert/strict';import fs from 'node:fs';import vm from 'node:vm';
const source=fs.readFileSync('tracky-agent-eyes-v1g4.js','utf8');
let now=0,handler,listeners={},timers=[],requests=[],interval;
function node(){return {children:[],textContent:'',append(n){this.children.push(n);},replaceChildren(){this.children=[];},addEventListener(e,fn){this[e]=fn;}};}
const list=node(),button=node(),panel={dataset:{endpoint:'/api/tracky-agent-eyes-v1g4.php?site=office'},querySelector:q=>q==='[data-eyes-list]'?list:button};
const document={visibilityState:'visible',querySelectorAll:()=>[panel],createElement:()=>node(),addEventListener:(e,fn)=>listeners[e]=fn};
class Controller{constructor(){this.signal={aborted:false};}abort(){this.signal.aborted=true;}}
const status={site_label:'Office <img>',device_id:'local',connection:'recent_contact',contact_age_seconds:299,state:'available',title:'Recent checked scene',meaning:'Possible chair',age_seconds:59,observed_at:'2026-10-03T00:00:00Z',guidance:'Checked snapshot',consent_note:'Last received state only'};
const response=(s=status)=>({ok:true,json:async()=>({ok:true,statuses:[s]})});
vm.runInNewContext(source,{document,performance:{now:()=>now},AbortController:Controller,setInterval:fn=>interval=fn,setTimeout:(fn,ms)=>{const t={fn,ms};timers.push(t);return t;},clearTimeout:t=>{if(t)t.cleared=true;},fetch:async(path,options)=>{requests.push({path,options});return handler?handler():response();}});
const flush=()=>new Promise(r=>setImmediate(r));const allText=n=>[n.textContent,...n.children.flatMap(c=>allText(c))].join(' ');
await flush();assert.match(allText(list),/Possible chair/);assert.match(allText(list),/Office <img>/);assert.equal(timers.filter(t=>!t.cleared&&t.ms===1000).length,2);
for(const t of timers.filter(t=>!t.cleared&&t.ms===1000))t.fn();assert.doesNotMatch(allText(list),/Possible chair/);assert.match(allText(list),/contact is stale/);
handler=async()=>{now+=2000;return response();};button.click();await flush();assert.doesNotMatch(allText(list),/Possible chair/,'Slow response extended scene expiry');
for(const age of [-1,60,NaN,'1']){handler=async()=>response({...status,age_seconds:age});button.click();await flush();assert.doesNotMatch(allText(list),/Possible chair/);}
let resolve;handler=()=>new Promise(r=>resolve=r);button.click();await flush();assert.match(allText(list),/Checking/);document.visibilityState='hidden';listeners.visibilitychange();resolve(response());await flush();assert.doesNotMatch(allText(list),/Possible chair/,'Late hidden response repopulated scene');
const count=requests.length;interval();await flush();assert.equal(requests.length,count,'Hidden page polled');
document.visibilityState='visible';handler=async()=>{throw Error('SECRET');};listeners.visibilitychange();await flush();assert.match(allText(list),/Scene status unavailable/);assert.doesNotMatch(allText(list),/SECRET/);
assert.ok(requests.every(r=>r.options.cache==='no-store'&&!r.options.method&&!r.options.body));assert.doesNotMatch(source,/innerHTML|getUserMedia|localStorage|sessionStorage/);
console.log('TRACKY_AGENT_EYES_UI_V1G4: original TTL/transit, contact expiry, invalid age, hidden and late responses, error clearing and GET-only PASS');
