import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {Blob} from 'node:buffer';

const source = readFileSync(new URL('../phone-recording-holder-v1.js',import.meta.url),'utf8');
const markup = readFileSync(new URL('../includes/phone-camera-app-view.php',import.meta.url),'utf8');
const page = readFileSync(new URL('../phone-location.php',import.meta.url),'utf8');
const drain = () => new Promise(resolve => setImmediate(resolve));

class Node {
  constructor() { this.events = {}; this.attrs = {}; this.dataset = {}; this.children = []; }
  addEventListener(key,callback) { this.events[key] = callback; }
  getAttribute(key) { return this[key] ?? null; }
  setAttribute(key,value) { this[key] = value; }
  removeAttribute(key) { delete this[key]; }
  append(...nodes) { this.children.push(...nodes); }
  remove() { this.removed = true; }
  pause() {}
  focus() {}
  load() {}
  click(isTrusted=true) { return this.events.click?.({isTrusted}); }
}
function fixture({supported=true,mime='video/mp4',limit=32*1024*1024,chunk=512*1024}={}) {
  const nodes = new Map(), events = {}, sessions = [], calls = [], uploads = [], urls = new Map(), released = [];
  const track = {kind:'video',readyState:'live'}, stream = {getTracks:() => [track]};
  const get = id => { if (!nodes.has(id)) nodes.set(id,new Node()); return nodes.get(id); };
  const by = key => get('phone-record-' + key);
  get('phone-location').dataset.endpoint = 'api/phone-location-v1.php';
  let record = {id:'rec1',state:'requested',max_bytes:limit,chunk_bytes:chunk}, next=0, acceptError=false;
  let failUpload=false, failCapture=false, failReview=false;
  class Recorder {
    static isTypeSupported(type) { return type === mime; }
    constructor(input,opts) { assert.equal(input,stream); this.mimeType=opts.mimeType; this.state='inactive'; sessions.push(this); }
    start(slice) { if (failCapture) throw Error(); this.state='recording'; this.slice=slice; }
    emit(value) { this.ondataavailable({data:new Blob([value],{type:this.mimeType})}); }
    stop() { this.state='inactive'; queueMicrotask(() => this.onstop()); }
  }
  class BlobURL extends URL {
    static createObjectURL(blob) { const url='blob:local-'+(urls.size+released.length+1); urls.set(url,blob); return url; }
    static revokeObjectURL(url) { released.push(url); urls.delete(url); }
  }
  class Form { constructor() { this.fields={}; } append(k,v) { this.fields[k]=v; } }
  const fetch = async (endpoint,options) => {
    assert.equal(endpoint.origin,'https://test.invalid'); assert.equal(options.credentials,'same-origin');
    assert.equal(options.headers['X-VP3-Phone'],'1');
    const fields=options.body.fields; uploads.push(fields);
    assert.equal(fields.session_id,'session'); assert.equal(fields.recording_id,'rec1'); assert.equal(fields.take_id,record.take_id);
    if (failUpload) throw Error('provider disconnected');
    if ((Number(fields.index)+1)*chunk>=Number(fields.total_bytes)) record={...record,state:'submitted'};
    return {ok:true,json:async()=>({ok:true,next_index:Number(fields.index)+1,recording:{...record}})};
  };
  const win = {MediaRecorder:supported?Recorder:undefined,addEventListener:(e,fn)=>{events[e]=fn;}};
  vm.runInNewContext(source,{window:win,document:{getElementById:get,createElement:()=>new Node()},
    URL:BlobURL,location:new URL('https://test.invalid/phone-location.php'),Blob,FormData:Form,AbortController,fetch,setTimeout,clearTimeout});
  const request=async(action,fields)=>{
    calls.push([action,fields]);
    assert.equal(fields.session_id,'session'); assert.equal(fields.recording_id,'rec1');
    if (action==='camera_record_accept') {
      if (acceptError) throw Error();
      if (record.state==='accepted' && !fields.take_id) return {recording:{...record}};
      assert.ok(record.state==='requested' && !fields.take_id || record.state==='reviewing' && fields.take_id===record.take_id);
      record={...record,state:'accepted',take_id:'take'+(++next)};
    } else if (action==='camera_record_review') {
      if (failReview) throw Error();
      assert.equal(fields.take_id,record.take_id); record={...record,state:'reviewing'};
    } else if (action==='camera_record_decline') record={...record,state:'declined'};
    return {recording:{...record}};
  };
  const app = win.VP3PhoneRecording(request);
  return {app,by,get,events,calls,uploads,sessions,urls,released,track,
    sync:(enabled=true,camera={session_id:'session',device_id:'phone',recording:record})=>app.sync(camera,enabled?stream:null),
    click:(key,trusted=true)=>by(key).click(trusted),setUploadFailure:value=>{failUpload=value;},
    setCaptureFailure:value=>{failCapture=value;},setAcceptFailure:value=>{acceptError=value;},setReviewFailure:value=>{failReview=value;},
    async take(value='first take',action='start') { await by(action).click(); sessions.at(-1).emit(value); by('stop').click(); await drain(); }};
}
for (const id of ['start','stop','decline','status','review','save','submit','redo','local']) assert.ok(markup.includes('id="phone-record-'+id+'"'));
assert.ok(page.indexOf('phone-recording-holder-v1.js')<page.indexOf('phone-camera-holder-v1.js'));
{
  const f=fixture();
  f.sync(false); assert.equal(f.by('start').disabled,true); await f.click('start'); assert.equal(f.calls.length,0);
  f.sync(); assert.equal(f.by('start').disabled,false); await f.click('start',false);
  assert.equal(f.calls.length,0); assert.equal(f.sessions.length,0);
  await f.click('start'); assert.equal(f.by('stop').hidden,false); assert.equal(f.app.isActive(),true);
  assert.equal(f.sessions[0].state,'recording'); f.sessions[0].emit('take one'); f.click('stop'); await drain();
  assert.equal(f.by('review').hidden,false); const url=f.by('review').src;
  assert.equal(f.by('save').href,url); assert.match(f.by('save').download,/\.mp4$/);
  assert.equal(f.uploads.length,0); assert.equal(await f.urls.get(url).text(),'take one');
  await f.take('take two','redo'); assert.ok(f.released.includes(url));
  f.setUploadFailure(true); await f.click('submit'); assert.equal(f.by('submit').disabled,false);
  assert.match(f.by('status').textContent,/still on this phone/); const second=f.by('save').href;
  assert.equal(await f.urls.get(second).text(),'take two');
  f.setUploadFailure(false); await f.click('submit'); assert.ok(f.by('submit').hidden);
  assert.equal(f.by('save').href,second); assert.equal(f.app.isActive(),false);
  assert.equal(await f.uploads.at(-1).chunk.text(),'take two'); assert.match(f.by('status').textContent,/submitted/);
  f.app.end(); assert.ok(f.released.includes(second)); assert.equal(f.track.readyState,'live');
}
{
  const f=fixture({mime:'video/webm',chunk:4});
  f.sync(); await f.take('abcdefghij'); assert.match(f.by('save').download,/\.webm$/);
  f.setReviewFailure(true); await f.click('submit'); assert.equal(f.uploads.length,0);
  f.setReviewFailure(false); await f.click('submit');
  assert.deepEqual(f.uploads.map(item=>item.index),['0','1','2']);
  assert.equal((await Promise.all(f.uploads.map(item=>item.chunk.text()))).join(''),'abcdefghij');
  assert.ok(f.uploads.every(item=>item.mime_type==='video/webm'));
}
{
  const f=fixture({limit:10}); f.sync(); await f.click('start');
  f.sessions[0].emit('123456789101'); await drain();
  assert.equal(f.sessions[0].state,'inactive'); assert.equal(f.by('submit').disabled,true);
  assert.equal(f.by('local').hidden,false); assert.match(f.by('status').textContent,/exceeds/);
  const url=f.by('save').href; f.app.end();
  assert.equal(f.by('retained').hidden,false); assert.equal(f.by('retained-list').children[0].children[1].href,url);
  f.sync(true,{session_id:'other-session',device_id:'other',recording:{id:'new',state:'requested'}});
  assert.equal(f.by('review').hidden,true); await f.click('submit'); assert.equal(f.uploads.length,0);
  f.by('retained-list').children[0].children[2].click(); assert.ok(f.released.includes(url));
}
{
  const f=fixture(); f.sync(); await f.click('start'); f.sessions[0].emit('unfinished take');
  f.app.end(); await drain();
  assert.equal(f.by('retained-list').children.length,1); assert.equal(f.uploads.length,0);
  f.events.pagehide(); assert.equal(f.urls.size,0);
}
{
  const f=fixture(); f.sync(); await f.take(); const url=f.by('review').src;
  f.setCaptureFailure(true); await f.click('redo');
  assert.equal(f.by('save').href,url); assert.match(f.by('status').textContent,/could not start/);
  f.sync(true,{session_id:'session',device_id:'phone',recording:{id:'rec1',state:'expired'}});
  const saved=f.by('retained-list').children[0].children[1];
  assert.equal(saved.href,url); assert.equal(await f.urls.get(saved.href).text(),'first take');
  assert.equal(f.uploads.length,0);
}
{
  const f=fixture(); f.sync(); f.setCaptureFailure(true); await f.click('start');
  assert.equal(f.by('start').hidden,false);
  f.setCaptureFailure(false); f.sync(); await f.take('recovered');
  assert.equal(await f.urls.get(f.by('save').href).text(),'recovered');
}
{
  const f=fixture({supported:false}); f.sync();
  assert.equal(f.by('start').hidden,true); assert.match(f.by('status').textContent,/cannot record/);
  await f.click('decline'); assert.equal(f.get('phone-recording').hidden,true);
  assert.deepEqual(f.calls.map(([a])=>a),['camera_record_decline']);
}
console.log('PASS phone recording consent, review, re-record, explicit download and submit, chunk retry, size cap, and session isolation');
