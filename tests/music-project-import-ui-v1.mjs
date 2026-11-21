import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const page=fs.readFileSync('music-project-import.php','utf8');
const overlayJs=fs.readFileSync('music-import-progress-v1.js','utf8');
const overlayCss=fs.readFileSync('music-import-progress-v1.css','utf8');
const zip=fs.readFileSync('admin/stem-browser-zip-v79.js','utf8');
const direct=fs.readFileSync('admin/direct-stem-upload-v79.js','utf8');
const api=fs.readFileSync('api/stem-direct-v79.php','utf8');
const workflow=fs.existsSync('.github/workflows/authority-section14.yml') ? fs.readFileSync('.github/workflows/authority-section14.yml','utf8') : '';

for(const id of ['stemZipInput','musicImportOverlay',
  'musicImportPhase','musicImportTrack','musicImportItems','musicImportView','musicImportRetry']){
  assert.match(page,new RegExp(`id="${id}"`),`${id} mounted in owner-facing page`);
}
assert.match(page,/music-import-progress-v1.js/);
assert.doesNotMatch(page,/direct-stem-upload-v79.js/,'only one uploader in Music Library');
for(const stale of ['directRppFile','directStemFiles','directStemUploadButton','project-direct-title']){
  assert(!page.includes('id="'+stale+'"'),'no competing direct upload control '+stale);
}
assert.match(page,/'successUrl'=>\$studio/,'successful import targets the Stem Editor');
assert.match(page,/Open in Stem Editor/,'post-import action opens the song in Stem Editor');
assert.match(page,/musicLibrary'=>true/);
assert.match(page,/member_agent_voice_enabled\(\$user\)/);
assert.match(page,/REAPER project \+ MP3 stems package/);
assert.match(zip,/chosen\.format!=='MP3'/,'Music Library package must contain MP3 stems');
assert.match(overlayJs,/STONEFELLOW_NOTIFICATION_CENTER/);
assert.match(overlayJs,/announceWhen\(message/);
assert.match(overlayJs,/stonefellow:agent-voice/);
assert.match(overlayCss,/prefers-reduced-motion:reduce/);
assert.match(overlayCss,/max-width:600px/);
assert.match(zip,/rpp_chunk/);
assert.match(direct,/rpp_chunk/);
assert.match(zip,/max_chunk_bytes/);
assert.match(direct,/max_chunk_bytes/);
assert.match(zip,/cfg\.reviewBeforeSave/);
assert.match(direct,/cfg\.reviewBeforeSave/);
assert.match(zip,/await fetch\(cfg\.endpoint,\{method:'POST',credentials:'same-origin',cache:'no-store',body:base\('abort'\)\}\)/);
assert.match(direct,/await fetch\(cfg\.endpoint,\{method:'POST',credentials:'same-origin',cache:'no-store',body:base\('abort'\)\}\)/);
assert.match(api,/\$action === 'rpp_chunk'/);
assert.match(api,/vp3_music_import_safe_chunk_v1/);
assert.match(api,/\$total > 0/);
assert.match(api,/\$state\['requires_rpp'\]/);
if(workflow)assert.match(workflow,/music-import-upload-limits-v1\.php/);
console.log('PASS page, backend and voice integration contracts');

class Node {
  constructor(tag='div') {
    this.tag=tag;this.style={};this.dataset={};this.children=[];this.attrs={};this.hidden=false;
    this.textContent='';this.listeners={};this.className='';this.isConnected=true;
    this.classList={add(){},remove(){},toggle(){}};
  }
  append(...kids){this.children.push(...kids);}
  replaceChildren(...kids){this.children=[...kids];}
  setAttribute(key,value){this.attrs[key]=value;}
  getAttribute(key){return this.attrs[key]??null;}
  addEventListener(type,cb){this.listeners[type]=cb;}
  focus(){this.focused=true;}
  click(){this.listeners.click?.();}
}
const nodes=new Map();
for(const id of ['musicImportOverlay','musicImportTitle','musicImportPhase','musicImportCounts',
  'musicImportBar','musicImportTrack','musicImportItems','musicImportError','musicImportCancel',
  'musicImportRetry','musicImportClose','musicImportView'])nodes.set(id,new Node());
nodes.get('musicImportOverlay').hidden=true;
const events={};
const speech=[];
const ctx={
  window:{
    STONEFELLOW_BROWSER_ZIP:{musicLibrary:true,agentVoiceEnabled:true},
    STONEFELLOW_NOTIFICATION_CENTER:{announceWhen:(msg,can)=>{if(can())speech.push(msg);return Promise.resolve(true);}},
    addEventListener:(name,cb)=>{events[name]=cb;}
  },
  document:{
    getElementById:id=>nodes.get(id)||null,
    createElement:tag=>new Node(tag),
    body:new Node('body'),
    activeElement:new Node('button'),
    hidden:false
  },
  SpeechSynthesisUtterance:class{}
};
vm.runInNewContext(overlayJs,ctx,{filename:'music-import-progress-v1.js'});
const ui=ctx.window.VP3MusicImportProgress;
assert(ui,'module mounts on music library page');
let retries=0,cancels=0;
assert.equal(ui.begin({name:'New Song',files:[
  {key:'rpp',name:'Song.RPP',kind:'project'},
  {key:'stem-0',name:'Drums.wav',kind:'stem'},
  {key:'stem-1',name:'Bass.wav',kind:'stem'}
],onCancel:()=>cancels++,onRetry:()=>retries++}),true);
assert.equal(nodes.get('musicImportOverlay').hidden,false);
assert.equal(nodes.get('musicImportItems').children.length,3);
assert.equal(speech.length,1);
ui.file('rpp','uploading',20);ui.rppUploaded();
ui.file('stem-0','uploading',45);ui.file('stem-0','uploaded',100);
assert.match(nodes.get('musicImportCounts').textContent,/2 of 3 files uploaded/);
ui.pause();assert.equal(nodes.get('musicImportOverlay').hidden,true,'review dialog remains interactive');
ui.resume();assert.equal(nodes.get('musicImportOverlay').hidden,false);
ui.importing();ui.file('stem-0','importing',100);ui.file('stem-0','complete',100);
ui.file('stem-1','uploaded',100);ui.file('stem-1','complete',100);
ui.finish({stemCount:2,url:'/admin/stems.php?track=12'});
assert.equal(nodes.get('musicImportTrack').attrs['aria-valuenow'],'100');
assert.equal(nodes.get('musicImportView').hidden,false);
assert.equal(nodes.get('musicImportView').href,'/admin/stems.php?track=12');
assert.equal(nodes.get('musicImportItems').children.length,3);
assert.match(nodes.get('musicImportCounts').textContent,/2 of 2 stems imported/);
assert.match(speech.at(-1),/stems are imported/);
ui.close();
assert.equal(nodes.get('musicImportOverlay').hidden,true);
ui.begin({name:'Broken project',files:[{key:'rpp',name:'bad.RPP',kind:'project'}],onRetry:()=>retries++,onCancel:()=>cancels++});
ui.failed('Server rejected invalid project.','rpp');
assert.equal(nodes.get('musicImportRetry').hidden,false);
assert.equal(nodes.get('musicImportError').hidden,false);
assert.equal(nodes.get('musicImportError').textContent,'Server rejected invalid project.');
nodes.get('musicImportRetry').click();assert.equal(retries,1);
ui.begin({name:'Cancel',files:[],onCancel:()=>cancels++});
nodes.get('musicImportCancel').click();assert.equal(cancels,1);
ui.cancelled();assert.equal(nodes.get('musicImportRetry').hidden,false);
ui.close();
events['stonefellow:agent-voice']({detail:{enabled:false}});
const before=speech.length;
ui.begin({name:'Silent',files:[]});ui.rppUploaded();ui.finish({stemCount:0});
assert.equal(speech.length,before,'agent voice obeys the user disable setting');
console.log('PASS progress overlay, per-file states, accessible progress, review pause, retry, cancel, voice gating, completion');
