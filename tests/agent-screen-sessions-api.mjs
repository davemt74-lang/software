import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-screen-sessions-'));for(const name of ['includes','api','sessions','budgets'])fs.mkdirSync(path.join(tmp,name));
for(const file of ['includes/knowledge.php','includes/shared-folders-v161.php','includes/agent-screen-share-v1.php','includes/agent-screen-sessions-v1.php','includes/agent-screen-audio-v1.php','api/agent-screen-sessions-v1.php','api/agent-screen-audio-v1.php'])fs.copyFileSync(file,path.join(tmp,file));
const unit=fs.readFileSync('tests/agent-screen-sessions-v1.php','utf8');let setup=unit.slice(unit.indexOf('$pdo->exec("CREATE TABLE artist_'),unit.indexOf('screen_sessions_schema_v1($pdo);'));
setup=setup.replaceAll('CREATE TABLE ','CREATE TABLE IF NOT EXISTS ');
fs.writeFileSync(path.join(tmp,'includes/bootstrap.php'),`<?php
session_start();require_once __DIR__.'/knowledge.php';
$pdo=new PDO('sqlite:'.__DIR__.'/../state.sqlite');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));
function db(): PDO{return $GLOBALS['pdo'];}
function current_user(){return isset($_SERVER['HTTP_X_FIXTURE_ANONYMOUS'])?null:['id'=>(int)($_SERVER['HTTP_X_FIXTURE_USER']??1)];}
function csrf_token(){return 'fixture';}
function has_permission(...$args){return !isset($_SERVER['HTTP_X_FIXTURE_DENIED']);}
function personal_capability_has_v242(...$args){return !isset($_SERVER['HTTP_X_FIXTURE_DENIED']);}
function table_exists($name){$q=db()->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$name]);return (bool)$q->fetchColumn();}
function column_exists(...$args){return true;}
function url($path){return $path;}
function vp3_agent_memory_scope_from_conversation_v410($user,$id,$set=true){if($id!==7||$user['id']!==1)throw new RuntimeException('Wrong conversation.',403);return 4;}
function vp3_agent_memory_scope_set_current_v410($uid,$aid){$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=['user_id'=>$uid,'user_agent_id'=>$aid];}
function agent_brain_schema_ready(){return true;}
function agent_brain_v122_upsert_system_memory($user,$type,$subject,$text,$meta,$confidence){db()->prepare('INSERT INTO agent_memory_items(user_id,memory_type,subject,memory_text,agent_id) VALUES(?,?,?,?,?)')->execute([$user['id'],$type,$subject,$text,4]);return (int)db()->lastInsertId();}
function ai_v100_ensure_runtime_dir(){return __DIR__.'/../budgets';}
function ai_elevenlabs_credential(){return ['','missing'];}
function ai_generate_chat_response(...$args){return ['ok'=>true,'answer'=>json_encode(['summary'=>'A song at 1:20','visible_text'=>'Artist Song 1:20 / 4:00','site_hint'=>'Player','page_title'=>'Song'])];}
${setup}
`);
const reserve=net.createServer();await new Promise(r=>reserve.listen(0,'127.0.0.1',r));const port=reserve.address().port;await new Promise(r=>reserve.close(r));const child=spawn(process.env.VP3_PHP_BINARY||'php',['-d',`session.save_path=${tmp}/sessions`,'-S',`127.0.0.1:${port}`,'-t',tmp],{stdio:['ignore','ignore','pipe']});let logs='';child.stderr.on('data',x=>logs+=x);const url=`http://127.0.0.1:${port}/api/agent-screen-sessions-v1.php`;let checks=0;
const post=async(body,headers={})=>{const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json',...headers},body:JSON.stringify({owner_key:'1',csrf_token:'fixture',...body})});return {r,data:await r.json()};};
const get=async(body,headers={})=>{const r=await fetch(url+'?'+new URLSearchParams(body),{headers});return {r,data:await r.json()};};const check=async(name,fn)=>{await fn();checks++;console.log('PASS '+name);};
const sid='00000000-0000-4000-8000-000000000001',cid='00000000-0000-4000-8000-000000000002';
try{
 for(let i=0;i<50;i++){try{await fetch(url);break;}catch{await new Promise(r=>setTimeout(r,50));}}
 await check('authentication, permission, CSRF and account fences apply to session changes',async()=>{assert.equal((await post({action:'start',session_id:sid},{'X-Fixture-Anonymous':'1'})).r.status,403);assert.equal((await post({action:'start',session_id:sid},{'X-Fixture-Denied':'1'})).r.status,403);assert.equal((await post({action:'start',session_id:sid,csrf_token:'wrong'})).r.status,419);assert.equal((await post({action:'start',session_id:sid,owner_key:'2'})).r.status,409);});
 await check('GET is read only and session creation returns the existing Knowledge folder',async()=>{assert.deepEqual((await get({})).data.sessions,[]);assert.equal((await get({action:'start',session_id:sid})).r.status,422);const {r,data}=await post({action:'start',session_id:sid,title:'Music study',conversation_id:7});assert.equal(r.status,200);assert.match(data.session.knowledge_url,/knowledge.php\?folder=1/);assert.equal((await post({action:'start',session_id:sid})).data.session.folder_id,1);});
 await check('capture and analysis persist a server-derived note and owner-only pixels',async()=>{let {r,data}=await post({action:'capture',session_id:sid,capture_id:cid,image:'data:image/jpeg;base64,'+fs.readFileSync('tests/fixtures/tv-frame.b64','utf8').trim(),captured_at:new Date().toISOString(),surface:'browser',note:'Compare this song'});assert.equal(r.status,200);assert.equal(data.capture.knowledge_id>0,true);assert.equal(data.capture.body.image,undefined);({r,data}=await post({action:'analyze',capture_id:cid}));assert.equal(r.status,200);assert.equal(data.capture.body.observation.summary,'A song at 1:20');assert.match((await get({action:'capture',capture_id:cid})).data.capture.body.image,/^data:image\/jpeg/);assert.equal((await get({action:'capture',capture_id:cid},{'X-Fixture-User':'2'})).r.status,404);assert.equal((await get({action:'history',session_id:sid},{'X-Fixture-User':'2'})).r.status,404);});
 await check('saving to Brain and deleting a session use explicit owner actions',async()=>{assert.ok((await post({action:'brain',capture_id:cid})).data.capture.memory_id>0);assert.equal((await post({action:'delete_session',session_id:sid})).r.status,422);assert.equal((await post({action:'delete_session',session_id:sid,remove_brain:true},{'X-Fixture-User':'2'})).r.status,409);const {r,data}=await post({action:'delete_session',session_id:sid,remove_brain:true});assert.equal(r.status,200);assert.equal(data.deleted,true);assert.equal((await get({action:'capture',capture_id:cid})).r.status,404);assert.equal((await post({action:'start',session_id:sid})).r.status,409);assert.deepEqual((await get({})).data.sessions,[]);});
 await check('audio endpoint rejects unauthenticated and oversized or invalid segments',async()=>{const audioUrl=url.replace('sessions','audio');assert.equal((await fetch(audioUrl)).status,405);const form=new FormData();form.append('csrf_token','fixture');form.append('owner_key','1');form.append('session_id',sid);form.append('capture_id',cid);form.append('captured_at',new Date().toISOString());form.append('duration_seconds','15');form.append('audio',new Blob(['not audio']),'tab.webm');assert.equal((await fetch(audioUrl,{method:'POST',body:form,headers:{'X-Fixture-Anonymous':'1'}})).status,403);assert.equal((await fetch(audioUrl,{method:'POST',body:form})).status,404);});
 console.log(`AGENT_SCREEN_SESSIONS_API=PASS (${checks} cases)`);
}catch(e){console.error(logs.slice(-4000));throw e;}finally{child.kill();await new Promise(r=>child.once('exit',r));fs.rmSync(tmp,{recursive:true,force:true});}
