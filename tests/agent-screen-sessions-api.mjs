import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn,spawnSync} from 'node:child_process';
const orbTemplate=fs.readFileSync('includes/agent-orb.php','utf8');
const sessionEndpoint=orbTemplate.match(/'screenSessionsEndpoint'=>url\('([^']+)'\)/)?.[1];
assert.match(sessionEndpoint??'',/^\/api\/[a-z0-9-]+\.php$/);
const sessionFile=sessionEndpoint.slice(1);
assert.ok(fs.existsSync(sessionFile),'Agent Eyes session endpoint must exist: '+sessionEndpoint);
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-screen-sessions-'));for(const name of ['includes','api','sessions','budgets'])fs.mkdirSync(path.join(tmp,name));
for(const file of ['includes/knowledge.php','includes/shared-folders-v161.php','includes/agent-screen-share-v1.php','includes/agent-screen-sessions-v1.php','includes/agent-screen-audio-v1.php',sessionFile,'api/agent-screen-audio-v1.php','includes/agent-screen-insights-v1.php','includes/agent-screen-handoffs-v1.php','api/agent-screen-insights-v1.php'])fs.copyFileSync(file,path.join(tmp,file));
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
function user_agent_get_v236($pdo,$uid,$aid){return $uid===1&&$aid===4?['id'=>4]:null;}
function homeserver_https_v1300_status($uid){return ['connected'=>false];}
function ai_v100_ensure_runtime_dir(){return __DIR__.'/../budgets';}
function ai_elevenlabs_credential(){return ['','missing'];}
function ai_generate_chat_response(...$args){return ['ok'=>true,'answer'=>json_encode(['summary'=>'A song at 1:20','visible_text'=>'Artist Song 1:20 / 4:00','site_hint'=>'Player','page_title'=>'Song','observations'=>[['kind'=>'music','label'=>'Close to my heart','detail'=>'Artist song title','confidence'=>'high']]])];}
${setup}
`);
const reserve=net.createServer();await new Promise(r=>reserve.listen(0,'127.0.0.1',r));const port=reserve.address().port;await new Promise(r=>reserve.close(r));const child=spawn(process.env.VP3_PHP_BINARY||'php',['-d',`session.save_path=${tmp}/sessions`,'-S',`127.0.0.1:${port}`,'-t',tmp],{stdio:['ignore','ignore','pipe']});let logs='';child.stderr.on('data',x=>logs+=x);const url=`http://127.0.0.1:${port}${sessionEndpoint}`;let checks=0;
const post=async(body,headers={})=>{const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json',...headers},body:JSON.stringify({owner_key:'1',csrf_token:'fixture',...body})});return {r,data:await r.json()};};
const get=async(body,headers={})=>{const r=await fetch(url+'?'+new URLSearchParams(body),{headers});return {r,data:await r.json()};};const check=async(name,fn)=>{await fn();checks++;console.log('PASS '+name);};
const sid='00000000-0000-4000-8000-000000000001',cid='00000000-0000-4000-8000-000000000002';
try{
 for(let i=0;i<50;i++){try{await fetch(url);break;}catch{await new Promise(r=>setTimeout(r,50));}}
 await check('authentication, permission, CSRF and account fences apply to session changes',async()=>{assert.equal((await post({action:'start',session_id:sid},{'X-Fixture-Anonymous':'1'})).r.status,403);assert.equal((await post({action:'start',session_id:sid},{'X-Fixture-Denied':'1'})).r.status,403);assert.equal((await post({action:'start',session_id:sid,csrf_token:'wrong'})).r.status,419);assert.equal((await post({action:'start',session_id:sid,owner_key:'2'})).r.status,409);});
 await check('GET is read only and session creation returns the existing Knowledge folder',async()=>{assert.deepEqual((await get({})).data.sessions,[]);assert.equal((await get({owner_key:'1'},{'X-Fixture-User':'2'})).r.status,409);assert.equal((await get({})).data.owner_key,'1');assert.equal((await get({action:'start',session_id:sid})).r.status,422);const {r,data}=await post({action:'start',session_id:sid,title:'Music study',conversation_id:7});assert.equal(r.status,200);assert.match(data.session.knowledge_url,/knowledge.php\?folder=1/);assert.equal((await post({action:'start',session_id:sid})).data.session.folder_id,1);});
 await check('capture and analysis persist a server-derived note and owner-only pixels',async()=>{let {r,data}=await post({action:'capture',session_id:sid,capture_id:cid,image:'data:image/jpeg;base64,'+fs.readFileSync('tests/fixtures/tv-frame.b64','utf8').trim(),captured_at:new Date().toISOString(),surface:'browser',note:'Compare this song'});assert.equal(r.status,200);assert.equal(data.capture.knowledge_id>0,true);assert.equal(data.capture.body.image,undefined);({r,data}=await post({action:'analyze',capture_id:cid}));assert.equal(r.status,200);assert.equal(data.capture.body.observation.summary,'A song at 1:20');assert.match((await get({action:'capture',capture_id:cid})).data.capture.body.image,/^data:image\/jpeg/);assert.equal((await get({action:'capture',capture_id:cid},{'X-Fixture-User':'2'})).r.status,404);assert.equal((await get({action:'history',session_id:sid},{'X-Fixture-User':'2'})).r.status,404);});
 await check('timeline is private, paginated and ordered by capture time',async()=>{
  const id=n=>'00000000-0000-4000-8000-'+String(n).padStart(12,'0');
  const timelineSid=id(100),otherSid=id(101),frameId=id(102),otherCapture=id(103);
  assert.equal((await post({action:'start',session_id:timelineSid,title:'Timeline fixture'})).r.status,200);
  assert.equal((await post({action:'start',session_id:otherSid,title:'Other fixture'})).r.status,200);
  const frame={action:'capture',session_id:timelineSid,capture_id:frameId,image:'data:image/jpeg;base64,'+fs.readFileSync('tests/fixtures/tv-frame.b64','utf8').trim(),captured_at:new Date().toISOString(),surface:'browser'};
  assert.equal((await post({...frame,annotation_logging:'yes'})).r.status,422);
  const optedIn=await post({...frame,annotation_logging:true,on_screen_translation:'Forged text'});
  assert.equal(optedIn.r.status,200);
  assert.equal(optedIn.data.capture.body.annotation_logging,true);
  assert.equal(optedIn.data.capture.body.on_screen_translation,undefined);
  const timeline=(extra={},headers={})=>get({action:'timeline',session_id:timelineSid,...extra},headers);
  assert.deepEqual((await timeline()).data.captures,[]);

  const second='2026-11-03T12:00:01';
  const capture=(n,kind,at,body,session=timelineSid,owner=1)=>[owner,session,id(n),kind,at,'fixture private pixels',JSON.stringify(body)];
  const rows=[
   ...Array.from({length:28},(_,i)=>capture(200+i,'tab_audio','2026-11-03T12:00:02Z',{transcript:'Audio '+i,duration_seconds:15})),
   capture(300,'tab_audio',second+'.550Z',{transcript:'Timed audio',duration_seconds:15,cues:[{start_seconds:2,end_seconds:3,text:'Timed audio'}]}),
   capture(301,'screen',second+'.200Z',{annotation_logging:true,observation:{on_screen_translation:'Visible translation'},image:'private body image'}),
   capture(302,'tab_audio',second+'Z',{transcript:'Legacy audio'}),
   capture(303,'screen','2026-11-03T12:00:03Z',{observation:{on_screen_translation:'Logging disabled'}}),
   capture(304,'screen','2026-11-03T12:00:03Z',{annotation_logging:true,observation:{on_screen_translation:' '}}),
   capture(305,'tab_audio','2026-11-03T12:00:03Z',{transcript:'Withdrawn audio'}),
   capture(306,'tab_audio','2026-11-03T12:00:03Z',{transcript:'Foreign audio'},timelineSid,2),
   capture(103,'tab_audio','2026-11-03T12:00:03Z',{transcript:'Other session audio'},otherSid)
  ];
  const seed=spawnSync(process.env.VP3_PHP_BINARY||'php',['-r',`
   $pdo=new PDO('sqlite:'.$argv[1],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
   $data=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
   $q=$pdo->prepare('INSERT INTO agent_screen_captures_v1(owner_user_id,session_id,capture_id,kind,captured_at,image_data,body_json) VALUES(?,?,?,?,?,?,?)');
   foreach($data['rows'] as $row)$q->execute($row);
   $pdo->exec('CREATE TABLE IF NOT EXISTS agent_screen_reviews_v1 (owner_user_id INTEGER NOT NULL,capture_id TEXT NOT NULL,state TEXT NOT NULL,note TEXT NOT NULL,updated_at TEXT NOT NULL,PRIMARY KEY(owner_user_id,capture_id))');
   $pdo->prepare("INSERT INTO agent_screen_reviews_v1(owner_user_id,capture_id,state,note,updated_at) VALUES(1,?,'withdrawn','','2026-11-03T12:00:03Z')")->execute([$data['withdrawn']]);
  `,path.join(tmp,'state.sqlite')],{input:JSON.stringify({rows,withdrawn:id(305)}),encoding:'utf8'});
  assert.equal(seed.status,0,seed.stderr);
  assert.equal((await timeline({}, {'X-Fixture-Anonymous':'1'})).r.status,403);
  assert.equal((await timeline({}, {'X-Fixture-User':'2'})).r.status,404);
  assert.equal((await timeline({owner_key:'2'})).r.status,409);
  assert.equal((await timeline({before:otherCapture})).r.status,404);
  assert.equal((await timeline({before:'invalid'})).r.status,422);
  const first=await timeline();
  assert.equal(first.r.status,200);
  assert.equal(first.data.session.session_id,timelineSid);
  assert.deepEqual(first.data.captures.map(c=>c.capture_id),[...Array.from({length:28},(_,i)=>id(227-i)),id(300),id(301)]);
  assert.deepEqual(first.data.captures.at(-2).body.cues,[{start_seconds:2,end_seconds:3,text:'Timed audio'}]);
  assert.equal(first.data.captures.at(-1).body.observation.on_screen_translation,'Visible translation');
  assert.ok(first.data.captures.every(c=>c.body.image===undefined&&c.image_data===undefined&&c.owner_user_id===undefined));
  assert.equal(first.data.next_before,id(301));
  const earlier=await timeline({before:first.data.next_before});
  assert.deepEqual(earlier.data.captures.map(c=>c.body.transcript),['Legacy audio']);
  assert.equal(earlier.data.next_before,null);
  assert.equal((await post({action:'delete_session',session_id:timelineSid,remove_brain:false})).r.status,200);
  assert.equal((await timeline()).r.status,404);
  assert.equal((await post({action:'delete_session',session_id:otherSid,remove_brain:false})).r.status,200);
 });
 await check('proactive endpoint fences account, Agent, CSRF and stale suggestions',async()=>{
  const insights=url.replace('sessions','insights');const read=async params=>{const r=await fetch(insights+'?'+new URLSearchParams({owner_key:'1',agent_id:'4',...params}));return {r,data:await r.json()};};
  const send=async body=>{const r=await fetch(insights,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({owner_key:'1',agent_id:4,csrf_token:'fixture',...body})});return {r,data:await r.json()};};
  assert.equal((await read({owner_key:'2'})).r.status,409);assert.equal((await read({agent_id:'8'})).r.status,403);
  const feed=await read({});assert.equal(feed.r.status,200);assert.equal(feed.data.cards.length,2);const card=feed.data.cards.find(c=>c.kind==='new');
  assert.equal((await send({action:'decide',key:card.key,review_hash:card.review_hash,decision:'later',csrf_token:'wrong'})).r.status,419);
  assert.equal((await send({action:'decide',key:card.key,review_hash:card.review_hash,decision:'later'})).r.status,200);assert.equal((await read({})).data.cards.length,1);
  await send({action:'reset_suggestions'});const capture=(await get({action:'capture',capture_id:cid})).data.capture;
  assert.equal((await send({action:'review_capture',capture_id:cid,revision:Number(capture.revision),state:'incorrect',note:'Wrong title'})).r.status,200);
  assert.equal((await read({})).data.cards.length,0);assert.equal((await send({action:'decide',key:card.key,review_hash:card.review_hash,decision:'dismiss'})).r.status,409);
  assert.equal((await post({action:'brain',capture_id:cid})).r.status,409);
  const current=(await get({action:'capture',capture_id:cid})).data.capture;await send({action:'review_capture',capture_id:cid,revision:Number(current.revision),state:'active',note:'Reviewed'});
  const fresh=(await read({})).data.cards.find(c=>c.kind==='new');assert.notEqual(fresh.review_hash,card.review_hash);
  const mission=await send({action:'handoff',key:fresh.key,review_hash:fresh.review_hash,kind:'mission',handoff_id:'00000000-0000-4000-8000-000000000009',conversation_id:7});assert.equal(mission.r.status,503);assert.match(mission.data.error,/connected HomeServer/);
 });
 await check('saving to Brain and deleting a session use explicit owner actions',async()=>{assert.ok((await post({action:'brain',capture_id:cid})).data.capture.memory_id>0);assert.equal((await post({action:'delete_session',session_id:sid})).r.status,422);assert.equal((await post({action:'delete_session',session_id:sid,remove_brain:true},{'X-Fixture-User':'2'})).r.status,409);const {r,data}=await post({action:'delete_session',session_id:sid,remove_brain:true});assert.equal(r.status,200);assert.equal(data.deleted,true);assert.equal((await get({action:'capture',capture_id:cid})).r.status,404);assert.equal((await post({action:'start',session_id:sid})).r.status,409);assert.deepEqual((await get({})).data.sessions,[]);});
 await check('watched zone bounds are validated and crop pixels stay private across retries',async()=>{
  const session_id='00000000-0000-4000-8000-000000000004',capture_id='00000000-0000-4000-8000-000000000005';
  const frame={action:'capture',session_id,capture_id,image:'data:image/jpeg;base64,'+fs.readFileSync('tests/fixtures/tv-frame.b64','utf8').trim(),captured_at:new Date().toISOString(),surface:'browser'};
  const watch_region={x:.1,y:.2,width:.5,height:.3};
  assert.equal((await post({action:'start',session_id,title:'Watched zone fixture'})).r.status,200);
  for(const invalid of [null,{...watch_region,width:1},{...watch_region,height:.01},{...watch_region,x:'0.1'},{...watch_region,label:'other'}]){
   assert.equal((await post({...frame,watch_region:invalid})).r.status,422);
  }
  const {r,data}=await post({...frame,watch_region});
  assert.equal(r.status,200);
  assert.deepEqual(data.capture.body.watch_region,watch_region);
  assert.equal(data.capture.body.image,undefined);
  const retry=await post({...frame,watch_region});
  assert.equal(retry.data.capture.knowledge_id,data.capture.knowledge_id);
  assert.deepEqual((await get({action:'history',session_id})).data.captures.map(c=>c.capture_id),[capture_id]);
  assert.equal((await get({action:'capture',capture_id},{'X-Fixture-User':'2'})).r.status,404);
  assert.equal((await get({action:'capture',capture_id})).data.capture.body.image,frame.image);
 });
 await check('audio endpoint rejects unauthenticated and oversized or invalid segments',async()=>{const audioUrl=url.replace('sessions','audio');assert.equal((await fetch(audioUrl)).status,405);const form=new FormData();form.append('csrf_token','fixture');form.append('owner_key','1');form.append('session_id',sid);form.append('capture_id',cid);form.append('captured_at',new Date().toISOString());form.append('duration_seconds','15');form.append('audio',new Blob(['not audio']),'tab.webm');assert.equal((await fetch(audioUrl,{method:'POST',body:form,headers:{'X-Fixture-Anonymous':'1'}})).status,403);assert.equal((await fetch(audioUrl,{method:'POST',body:form})).status,404);});
 console.log(`AGENT_SCREEN_SESSIONS_API=PASS (${checks} cases)`);
}catch(e){console.error(logs.slice(-4000));throw e;}finally{child.kill();await new Promise(r=>child.once('exit',r));fs.rmSync(tmp,{recursive:true,force:true});}
