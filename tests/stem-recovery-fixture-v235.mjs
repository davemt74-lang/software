import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {webcrypto} from 'node:crypto';
import {execFileSync} from 'node:child_process';
import assert from 'node:assert/strict';
import {fixture} from './music-file-repair-fixture.mjs';
export async function recoveryFixture(){

const f=await fixture();
let success=false;
try {
  for(const p of ['api/stem-direct-v79.php','includes/music-import-upload-limits-v1.php'])fs.copyFileSync(p,path.join(f.root,p));
  const extra={
    tracks:{tempo_bpm:'INTEGER',duration:'TEXT',mood:'TEXT',energy:'TEXT',keywords:'TEXT'},
    track_projects:{source_zip_name:'TEXT',rpp_file_name:'TEXT',project_sample_rate:'INTEGER',media_sample_rate:'INTEGER',project_start_seconds:'REAL',master_plugin_chain_json:'TEXT',imported_by_user_id:'INTEGER'},
    track_stems:{stem_name:'TEXT',stem_role:'TEXT',source_track_name:'TEXT',channels:'INTEGER',sample_rate:'INTEGER',bit_depth:'INTEGER',rpp_track_guid:'TEXT',rpp_volume:'REAL',rpp_pan:'REAL',rpp_fx_summary:'TEXT',plugin_chain_json:'TEXT',duration_seconds:'REAL',start_offset_seconds:'REAL',sort_order:'INTEGER DEFAULT 0',instrument:'TEXT',updated_at:'TEXT'}
  };
  for(const [table,columns]of Object.entries(extra))for(const [name,type]of Object.entries(columns))f.run(`db()->exec(${JSON.stringify(`ALTER TABLE ${table} ADD COLUMN ${name} ${type}`)});`);
  fs.mkdirSync(path.join(f.root,'private'),{recursive:true});
  const form=await f.form();
  const mp3=Buffer.from(JSON.parse(fs.readFileSync('tests/fixtures/stem-mp3-metadata-v1.json','utf8')).fixtures[0].base64,'base64');
  const names=Array.from({length:19},(_,i)=>i<8?`Instrument ${i+1}`:`Vocal ${i+1}`);
  const rpp='<REAPER_PROJECT 0.1 7\nTEMPO 170 4 4\n'+names.map((name,i)=>`  <TRACK {00000000-0000-0000-0000-${String(i+1).padStart(12,'0')}}\n    NAME "${name}"\n    <ITEM\n      POSITION 0\n      LENGTH 1.25\n      <SOURCE WAVE\n        FILE "original-take-${i+1}.wav"\n      >\n    >\n  >\n`).join('')+'>'+('\n// import fixture padding, not private data'.repeat(1800));
  const zip=execFileSync('python3',['-c',`import io,zipfile,sys,json,base64
p=json.load(sys.stdin);b=io.BytesIO()
with zipfile.ZipFile(b,'w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('Session/Session.rpp',p['rpp'])
 for i in range(19): z.writestr('Session/'+str(i+1).zfill(2)+'.mp3',base64.b64decode(p['mp3']))
sys.stdout.buffer.write(b.getvalue())`],{input:JSON.stringify({rpp,mp3:mp3.toString('base64')})});
  const nodes=new Map();
  for(const id of ['stemZipInput','stemUploadButton','stemUploadCancel','stemUploadProgress','stemUploadProgressBar','stemUploadProgressText','stemUploadStatus'])nodes.set(id,{listeners:{},addEventListener(type,cb){this.listeners[type]=cb},style:{},classList:{toggle(){}},hidden:false});
  const file=Object.assign(new Blob([zip]),{name:'Session.zip'});
  nodes.get('stemZipInput').files=[file];
  const calls=[];
  const ctx={window:{STONEFELLOW_BROWSER_ZIP:{endpoint:f.base+'/api/stem-direct-v79.php',csrf:form.csrf,trackId:1,chunkBytes:65536,maxBytes:10485760,hasExisting:true,reviewBeforeSave:true,requireProject:true,musicLibrary:true,track:{title:'Existing song'},successUrl:'/admin/stems.php?track=1'},confirm:()=>true},document:{getElementById:id=>nodes.get(id)},crypto:webcrypto,TextDecoder,Blob,Response,FormData,AbortController,DecompressionStream,console};
  ctx.fetch=async(url,options)=>{
    const action=options.body.get('action');
    const response=await fetch(url,{...options,headers:{'X-User':'11',Cookie:form.cookie}});
    const raw=await response.clone().text();let data;try{data=JSON.parse(raw)}catch{}
    calls.push({action,status:response.status,ok:data?.ok,error:data?.error});

    return response;
  };
  vm.runInNewContext(fs.readFileSync('admin/stem-import-review-v79.js','utf8'),ctx);
  ctx.window.STONEFELLOW_STEM_IMPORT_META.review=async review=>review;
  vm.runInNewContext(fs.readFileSync('admin/stem-browser-zip-v79.js','utf8'),ctx);
  await nodes.get('stemUploadButton').listeners.click();
  assert.ok(calls.some(x=>x.action==='save_finish'&&x.ok),JSON.stringify(calls.filter(x=>!x.ok)));
  const snapshot=f.snapshot(),stems=snapshot.stems.filter(x=>x.is_active);
  assert.equal(stems.length,19);
  stems.forEach(row=>{assert.ok(row.duration_seconds>1&&row.duration_seconds<1.5);assert.equal(row.channels,1);assert.equal(row.sample_rate,48000);assert.deepEqual(fs.readFileSync(path.join(f.root,row.file_path.replace(/^\//,''))),mp3);});
  assert.equal(snapshot.mixes[0].mix_json,'{"volume":0.4}');assert.equal(snapshot.history[0].last_position_seconds,23);assert.equal(snapshot.playlists.length,1);
  fs.appendFileSync(path.join(f.root,'includes/bootstrap.php'),`\nfunction require_permission($key):void{if(!has_permission($key,current_user())){http_response_code(403);exit;}}\nfunction can_view_track(array $t,?array $u=null):bool{return can_manage_track_production($t,$u);}`);
  success=true;return {...f,formData:form,stems,mp3};
}finally{if(!success)f.close();}
}
