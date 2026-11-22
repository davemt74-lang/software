import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {recoveryFixture} from './stem-recovery-fixture-v235.mjs';
const f=await recoveryFixture();
try{
 for(const stem of f.stems){
  const response=await fetch(f.base+'/stem-media-v34.php?id='+stem.id,{headers:{'X-User':'11',Cookie:f.formData.cookie}});
  const bytes=Buffer.from(await response.arrayBuffer());
  assert.equal(response.status,200,bytes.toString('utf8')+'\n'+f.logs);assert.equal(response.headers.get('Content-Type'),'audio/mpeg');
  assert.deepEqual(bytes,f.mp3);
 }
 const id=f.stems[0].id;
 const partial=await fetch(f.base+'/stem-media-v34.php?id='+id,{headers:{Range:'bytes=25-1023','X-User':'11'}});
 assert.equal(partial.status,206);assert.deepEqual(Buffer.from(await partial.arrayBuffer()),f.mp3.subarray(25,1024));
 const denied=await fetch(f.base+'/stem-media-v34.php?id='+id,{headers:{'X-User':'14'}});assert.equal(denied.status,403);
 for(const file of ['api/stem-agent-v105.php','includes/stem-agent-continuity-v235.php','includes/agent-chat-boundary-v380.php'])fs.copyFileSync(file,path.join(f.root,file));
 fs.appendFileSync(path.join(f.root,'includes/bootstrap.php'),"\nrequire_once __DIR__.'/agent-chat-boundary-v380.php';function agent_v91_plan_stem(...$args):array{throw new RuntimeException('History must not call a model.');}");
 f.run(`db()->exec("CREATE TABLE agent_studio_sessions(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,track_id INTEGER,status TEXT,started_at TEXT,last_activity_at TEXT);CREATE TABLE agent_studio_history(id INTEGER PRIMARY KEY,session_id INTEGER,user_id INTEGER,role TEXT,message_text TEXT,command_json TEXT,status TEXT,result_text TEXT,created_at TEXT)");`);
 const studio=async(user,token=f.formData.csrf)=>fetch(f.base+'/api/stem-agent-v105.php',{method:'POST',headers:{'Content-Type':'application/json','X-User':String(user),Cookie:f.formData.cookie},body:JSON.stringify({action:'history',track_id:1,csrf_token:token,agent_id:0})});
 const history=await studio(11);const historyData=await history.json();assert.equal(history.status,200,JSON.stringify(historyData));assert.equal(historyData.ok,true,'the real Studio Agent API accepts its owner’s unpublished production draft');
 assert.equal((await studio(14)).status,403);assert.equal((await studio(11,'wrong')).status,419);
 console.log('STEM_REAPER_RECOVERY_V235=PASS compressed ZIP, 19 unmatched-source MP3s, metadata, actual PHP media bytes/ranges, foreign-owner denial and preserved mix/history');
}finally{f.close();}
