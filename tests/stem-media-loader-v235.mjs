import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const sandbox={URL,Response,AbortController,setTimeout,clearTimeout,console};sandbox.globalThis=sandbox;
vm.runInNewContext(fs.readFileSync('admin/stem-media-loader-v235.js','utf8'),sandbox);
const {createPool}=sandbox.StonefellowStemMediaLoaderV235;
const body=Buffer.alloc(1200,42),counts=new Map();let active=0,maximum=0;
const pool=createPool({maxBytes:6000,concurrency:2,allowedUrls:Array.from({length:19},(_,i)=>`/stem/${i}`),fetcher:async url=>{
  counts.set(url,(counts.get(url)||0)+1);active++;maximum=Math.max(maximum,active);
  await new Promise(r=>setTimeout(r,3));active--;
  return new Response(body,{headers:{'Content-Type':'audio/mpeg','Content-Length':String(body.length)}});
}});
const jobs=Array.from({length:19},(_,i)=>pool.load(`/stem/${i}`));
const duplicate=pool.load('/stem/0');const values=await Promise.all(jobs);
assert.equal(await duplicate,values[0]);assert.equal(counts.get('/stem/0'),1);
assert.equal(maximum,2);assert.ok(pool.stats().retainedBytes<=6000);
assert.equal(values.filter(x=>x.mode==='local').length,5);assert.equal(values.filter(x=>x.mode==='stream').length,14);
assert.deepEqual(Buffer.from(await (await pool.response('/stem/0')).arrayBuffer()),body);
await assert.rejects(pool.load('/foreign'),e=>e.status===403);
pool.dispose();assert.equal(pool.stats().retainedBytes,0);await assert.rejects(pool.load('/stem/0'));
let attempts=0;
const retry=createPool({fetcher:async()=>{attempts++;if(attempts===1)return new Response('',{status:503});return new Response(body,{headers:{'Content-Type':'audio/mpeg','Content-Length':String(body.length)}});}});
assert.equal((await retry.load('/recover')).mode,'local');assert.equal(attempts,2);retry.dispose();
for(const [status,type,reason] of [[404,'text/plain','missing'],[403,'text/html','forbidden'],[200,'text/html','invalid_response']]){
  let calls=0;const p=createPool({fetcher:async()=>{calls++;return new Response('broken',{status,headers:{'Content-Type':type,'X-Stonefellow-Media-Status':reason}});}});
  await assert.rejects(p.load('/failure'),e=>e.reason===reason);assert.equal(calls,1);p.dispose();
}
let truncatedCalls=0;
const truncated=createPool({fetcher:async()=>{truncatedCalls++;return new Response(body,{headers:{'Content-Type':'audio/mpeg','Content-Length':'2000'}});}});
await assert.rejects(truncated.load('/truncated'),e=>e.reason==='incomplete_transfer');assert.equal(truncatedCalls,2);assert.equal(truncated.stats().retainedBytes,0);truncated.dispose();
let release;
const stale=createPool({concurrency:1,fetcher:async()=>{await new Promise(r=>release=r);return new Response(body,{headers:{'Content-Type':'audio/mpeg','Content-Length':String(body.length)}});}});
const first=stale.load('/stale');await new Promise(r=>setTimeout(r,1));stale.discard('/stale');release();await assert.rejects(first);assert.equal(stale.stats().retainedBytes,0);stale.dispose();
let abortObserved=false;
const cancel=createPool({concurrency:1,fetcher:async(_,{signal})=>new Promise((resolve,reject)=>{signal.addEventListener('abort',()=>{abortObserved=true;reject(Error('aborted'));});})});
const pending=cancel.load('/one'),queued=cancel.load('/two');await new Promise(r=>setTimeout(r,1));cancel.dispose();await Promise.all([assert.rejects(pending),assert.rejects(queued)]);assert.equal(abortObserved,true);assert.equal(cancel.stats().active,0);
console.log('STEM_MEDIA_LOADER_V235=PASS 19-source concurrency, deduplication, bounded memory, streaming fallback, terminal/transient/truncated failures, replacement and disposal');
