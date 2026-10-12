import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';
const source=await fs.readFile(new URL('../browser-companion/chat-research-worker.js',import.meta.url),'utf8');
function fixture({permission=true,redirect=false,swap=false}={}){
 const events=[],receipts=new Set();let next=0,status='waiting_chrome',live=true,tab;
 const urls=['https://one.example/about','https://two.example/services'];
 const request=()=>({id:'request',agent_id:17,status,next_source:next,mission:{mission_id:'mission'},sources:urls.map(url=>({url}))});
 const ctx={console,URL,chrome:{permissions:{contains:async()=>permission,request:async()=>{throw Error('Automatic permission expansion forbidden');}},tabs:{
  create:async value=>{events.push(['open',value]);tab={id:9,url:value.url};return tab;},remove:async id=>events.push(['close',id])}},
  connectionContext:async()=>({token:'private-token'}),connectionStillCurrent:async()=>live,
  sha256HexV2220:async v=>'hash:'+v,
  waitForGuardedNavigationV2220:async()=>({verified:true,result_code:redirect?'handoff_verified_redirect':'handoff_verified'}),
  browserResearchCaptureV2230:async id=>{assert.equal(id,9);events.push(['capture',tab.url]);if(swap)live=false;return {url:tab.url,page_text:'fixture'};},
  authorizedFetch:async(path,{json})=>{
   events.push([json.action,path]);
   if(json.action==='worker.list')return {requests:[request()]};
   if(json.action==='worker.claim'){status='running';return {request:request(),lease_token:'exclusive-lease'};}
   if(json.action==='worker.step'){
    assert.equal(json.lease_token,'exclusive-lease');
    if(json.failed){status='attention';return {request:request(),next:null};}
    while(receipts.has(urls[next]))next++;
    if(next===urls.length)status='complete';
    return {request:request(),next:next<urls.length?{url:urls[next],domain:new URL(urls[next]).hostname}:null};
   }
   throw Error('unexpected request');
  },browserResearchApiV2230:async(action,payload)=>{assert.equal(action,'analyze_page');assert.equal(payload.lease_token,'exclusive-lease');assert.equal(payload.agent_id,17);events.push(['analyze',payload.page.url]);receipts.add(payload.page.url);}};
 vm.createContext(ctx);vm.runInContext(source,ctx);return {ctx,events,getStatus:()=>status};
}
const normal=fixture();const completed=await normal.ctx.chatResearchRun({id:'request'});assert.equal(completed.status,'complete');
assert.deepEqual(normal.events.filter(x=>x[0]==='analyze').map(x=>x[1]),['https://one.example/about','https://two.example/services']);
assert.equal(normal.events.filter(x=>x[0]==='open').every(x=>x[1].active===false),true);
assert.equal(normal.events.filter(x=>x[0]==='close').length,2);
const noPermission=fixture({permission:false});await assert.rejects(()=>noPermission.ctx.chatResearchRun({id:'request'}));assert.equal(noPermission.getStatus(),'attention');assert.equal(noPermission.events.some(x=>x[0]==='open'),false);
const redirected=fixture({redirect:true});await assert.rejects(()=>redirected.ctx.chatResearchRun({id:'request'}));assert.equal(redirected.events.some(x=>x[0]==='capture'),false);assert.equal(redirected.events.filter(x=>x[0]==='close').length,1);
const swapped=fixture({swap:true});await assert.rejects(()=>swapped.ctx.chatResearchRun({id:'request'}));assert.equal(swapped.events.some(x=>x[0]==='analyze'),false);
let release;const gate=new Promise(r=>release=r);const concurrency=fixture();concurrency.ctx.waitForGuardedNavigationV2220=async()=>{await gate;return {verified:true,result_code:'handoff_verified'};};
const first=concurrency.ctx.chatResearchRun({id:'request'});await assert.rejects(()=>concurrency.ctx.chatResearchRun({id:'other'}),/already/);release();await first;
console.log('PASS: production Chrome reader handles exact reviewed targets, inactive owned tabs, bound lead/lease, source receipts, no alarm permission expansion, redirects, connection changes, cleanup and single-flight.');
