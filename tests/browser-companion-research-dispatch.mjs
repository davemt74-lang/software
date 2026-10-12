import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';
import {webcrypto} from 'node:crypto';

const source=await fs.readFile(new URL('../browser-companion/background.js',import.meta.url),'utf8');
const panel=await fs.readFile(new URL('../browser-companion/sidepanel.js',import.meta.url),'utf8');
const manifest=JSON.parse(await fs.readFile(new URL('../browser-companion/manifest.json',import.meta.url),'utf8'));
const stored={base_url:'https://cloud.example/installed/vp3',device_token:'a'.repeat(64)};
const requests=[];let receiver;
const chrome={runtime:{id:'a'.repeat(32),onMessage:{addListener(fn){receiver=fn;}}},
 storage:{local:{get:async keys=>Object.fromEntries(keys.filter(k=>k in stored).map(k=>[k,stored[k]])),
 set:async values=>Object.assign(stored,values),remove:async keys=>{for(const k of keys)delete stored[k];}}},
 permissions:{contains:async()=>true}};
const sandbox={chrome,importScripts:()=>{},URL,URLSearchParams,Headers,AbortController,TextEncoder,TextDecoder,
 crypto:webcrypto,setTimeout,clearTimeout,console,fetch:async(url,options)=>{
  requests.push({url,options});return {ok:true,status:200,json:async()=>({ok:true,receipt:requests.length})};}};
vm.createContext(sandbox);
vm.runInContext(source.split('chrome.runtime.onInstalled.addListener')[0],sandbox);
const listenerStart=source.indexOf('chrome.runtime.onMessage.addListener');
vm.runInContext(source.slice(listenerStart,source.indexOf('chrome.tabs.onUpdated.addListener',listenerStart)),sandbox);
const dispatch=message=>new Promise(resolve=>assert.equal(receiver(message,{id:chrome.runtime.id},resolve),true));
// Exercise the actual registered listener: runtime actions used to be shadowed by the project route.
for(const action of ['list','start','state','save','cancel']){
 const result=await dispatch({type:'research_runtime_action',action,payload:{mission_id:'mission',runtime_id:'runtime'}});
 assert.equal(result.ok,true);
 assert.equal(requests.at(-1).url,'https://cloud.example/installed/vp3/api/extension-research-agent-v2230.php');
 const body=JSON.parse(requests.at(-1).options.body);assert.equal(body.action,action);assert.equal(body.mission_id,'mission');
 assert.equal(requests.at(-1).options.headers.get('Authorization'),'Bearer '+stored.device_token);
 assert.equal(requests.at(-1).options.headers.get('X-VP3-Extension-Version'),manifest.version);
}
for(const action of ['create_project','assign']){
 assert.equal((await dispatch({type:'research_action',action,payload:{project_id:'project'}})).ok,true);
 assert.equal(requests.at(-1).url,'https://cloud.example/installed/vp3/api/research-projects-v2060.php');
 assert.equal(JSON.parse(requests.at(-1).options.body).action,action);
}
assert.match(panel,/function researchRequestV2230[\s\S]*?msg\('research_runtime_action'/);
assert.match(panel,/function researchAction\(action,payload\).*msg\('research_action'/);
assert.equal((await dispatch({type:'unknown_research_action'})).ok,false);
console.log('PASS: actual Chrome listener routes bounded research runtime actions separately from canonical project writes; authenticated installation URL and version retained.');
