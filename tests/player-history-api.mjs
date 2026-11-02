import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
const php=process.env.PHP_BINARY||'php',repo=process.cwd(),root=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-history-'));
fs.mkdirSync(path.join(root,'api'));fs.mkdirSync(path.join(root,'includes'));
for(const p of ['api/playback.php','api/player-library-v76.php','includes/player-v76.php','includes/music-media.php','includes/notifications.php','includes/agent-chat-activity.php','includes/recommendations.php','includes/music-catalog.php'])fs.copyFileSync(p,path.join(root,p));
fs.writeFileSync(path.join(root,'includes/bootstrap.php'),`<?php
define('STONEFELLOW_ROOT',dirname(__DIR__));
class HistoryPDO extends PDO {public function prepare(string $q,array $opts=[]):PDOStatement|false{return parent::prepare(str_replace([' FOR UPDATE','COALESCE(user_id,0)=?','INSERT IGNORE INTO'],['','COALESCE(user_id,0)=CAST(? AS INTEGER)','INSERT OR IGNORE INTO'],$q),$opts);}}
function db():PDO {static $p;if(!$p){$p=new HistoryPDO('sqlite:'.STONEFELLOW_ROOT.'/data.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$p->sqliteCreateFunction('NOW',fn()=>date('Y-m-d H:i:s'));}return $p;}
function current_user():?array {$id=(int)($_SERVER['HTTP_X_USER']??0);return $id?['id'=>$id,'role'=>'artist']:null;}
function csrf_token():string{return 'test';}
function url(string $p):string{return $p;}
function has_permission(string $key,?array $u=null):bool{return $key==='chat.access';}
function table_exists(string $t):bool{$s=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$s->execute([$t]);return (bool)$s->fetchColumn();}
function get_track_by_id(int $id):?array{$s=db()->prepare('SELECT * FROM tracks WHERE id=? AND is_published=1');$s->execute([$id]);return $s->fetch()?:null;}
function can_manage_track_production(array $t,?array $u=null):bool{$s=db()->prepare('SELECT 1 FROM members WHERE user_id=? AND workspace_id=?');$s->execute([($u??current_user())['id']??0,$t['workspace_id']]);return (bool)$s->fetchColumn();}
function can_view_track(array $t,?array $u=null):bool{return $t['visibility']==='public'||can_manage_track_production($t,$u);}
require __DIR__.'/music-catalog.php';require __DIR__.'/player-v76.php';require __DIR__.'/recommendations.php';require __DIR__.'/notifications.php';require __DIR__.'/agent-chat-activity.php';
`);
const sql=`CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT);INSERT INTO users VALUES(11,'Owner'),(12,'Other');CREATE TABLE playlists(id INTEGER PRIMARY KEY,owner_user_id INTEGER,title TEXT,description TEXT,visibility TEXT,updated_at TEXT);INSERT INTO playlists VALUES(1,11,'My list','','public','now'),(2,12,'Other list','','members','now');CREATE TABLE playlist_tracks(playlist_id INTEGER,track_id INTEGER,sort_order INTEGER,added_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(playlist_id,track_id));CREATE TABLE members(user_id INTEGER,workspace_id INTEGER);INSERT INTO members VALUES(11,1);
CREATE TABLE tracks(id INTEGER PRIMARY KEY,workspace_id INTEGER,title TEXT,album TEXT,visibility TEXT,is_published INTEGER,audio_path TEXT);INSERT INTO tracks VALUES(1,1,'Owner draft','Studio','admin',0,'/uploads/audio/existing.mp3');
CREATE TABLE track_play_sessions(id INTEGER PRIMARY KEY AUTOINCREMENT,session_token TEXT,track_id INTEGER,user_id INTEGER,listener_hash TEXT,device_type TEXT,referrer_host TEXT,source_context TEXT,duration_seconds REAL,last_position_seconds REAL,max_position_seconds REAL,listener_city TEXT,listener_region TEXT,listener_country TEXT,listener_latitude REAL,listener_longitude REAL,started_at TEXT DEFAULT CURRENT_TIMESTAMP,last_event_at TEXT DEFAULT CURRENT_TIMESTAMP,ended_at TEXT,listened_seconds REAL DEFAULT 0,completion_percent REAL DEFAULT 0,qualified_play INTEGER DEFAULT 0,completed INTEGER DEFAULT 0);
CREATE TABLE track_play_events(id INTEGER PRIMARY KEY AUTOINCREMENT,session_id INTEGER,event_type TEXT,position_seconds REAL,listened_delta_seconds REAL);
CREATE TABLE notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,type TEXT,title TEXT,body TEXT,target_url TEXT,source_type TEXT,source_id INTEGER,is_read INTEGER DEFAULT 0,created_at TEXT DEFAULT CURRENT_TIMESTAMP);`;
const run=code=>execFileSync(php,['-r',`require $argv[1];${code}`,path.join(root,'includes/bootstrap.php')],{encoding:'utf8'});
run(`db()->exec(${JSON.stringify(sql)});`);
const sock=net.createServer();await new Promise(r=>sock.listen(0,'127.0.0.1',r));const port=sock.address().port;await new Promise(r=>sock.close(r));
const server=spawn(php,['-S',`127.0.0.1:${port}`,'-t',root],{stdio:['ignore','pipe','pipe']});let logs='';server.stderr.on('data',x=>logs+=x);const base=`http://127.0.0.1:${port}`;
const headers={'Content-Type':'application/json','X-User':'11'};
const post=(route,body,h=headers)=>fetch(base+route,{method:'POST',headers:h,body:JSON.stringify({csrf_token:'test',...body})});
try{
 for(let i=0;i<60;i++){try{await fetch(base+'/ready');break;}catch{await new Promise(r=>setTimeout(r,50));}}
 let r=await post('/api/playback.php',{action:'start',track_id:1,duration:100,source:'agent_chat'});const start=await r.json();assert.equal(r.status,200,JSON.stringify(start)+logs);assert.equal(start.ok,true);assert.ok(start.session_token);
 const cookie=r.headers.get('set-cookie').split(';')[0];run("db()->exec(\"UPDATE track_play_sessions SET last_event_at=datetime('now','-5 seconds')\");");
 r=await post('/api/playback.php',{action:'pause',track_id:1,session_token:start.session_token,position:12,duration:100,delta:2},{...headers,Cookie:cookie});{const data=await r.json();assert.equal(data.ok,true,JSON.stringify(data)+logs);}
 r=await post('/api/player-library-v76.php',{action:'recent_history'});let history=await r.json();assert.equal(history.ok,true);assert.equal(history.tracks.length,1);assert.equal(history.tracks[0].title,'Owner draft');assert.equal(history.tracks[0].last_position_seconds,12);
 const data=JSON.parse(run("echo json_encode(['notifications'=>db()->query('SELECT * FROM notifications')->fetchAll(),'published'=>db()->query('SELECT is_published FROM tracks WHERE id=1')->fetchColumn()]);"));assert.equal(data.published,0);assert.equal(data.notifications.length,1);assert.equal(data.notifications[0].type,'agent_activity_music_playback');assert.equal(data.notifications[0].source_id,start.session_id);
 r=await post('/api/playback.php',{action:'heartbeat',track_id:1,session_token:start.session_token,position:12,delta:0},{...headers,Cookie:cookie});assert.equal((await r.json()).ok,true);assert.equal(Number(run("echo db()->query('SELECT COUNT(*) FROM notifications')->fetchColumn();")),1);
 r=await post('/api/player-library-v76.php',{action:'playlist_add_track',playlist_id:1,track_id:1});assert.equal((await r.json()).ok,true,'Owner could not add their playable draft to a playlist');
 r=await post('/api/player-library-v76.php',{action:'playlist_add_track',playlist_id:1,track_id:1});assert.equal((await r.json()).ok,true);assert.equal(Number(run("echo db()->query('SELECT COUNT(*) FROM playlist_tracks')->fetchColumn();")),1,'Duplicate membership was created');
 assert.equal(JSON.parse(run("echo json_encode(music_catalog_playlist_tracks(db(),1,['id'=>11]));")).length,1,'Owner playlist lost their authorized draft');
 assert.deepEqual(JSON.parse(run("echo json_encode(music_catalog_playlist_tracks(db(),1,['id'=>12]));")),[],'Shared playlist disclosed an unpublished production track');
 r=await post('/api/player-library-v76.php',{action:'playlist_add_track',playlist_id:2,track_id:1});assert.equal((await r.json()).ok,false,'A foreign playlist was modified');
 r=await post('/api/player-library-v76.php',{action:'recent_history'},{'Content-Type':'application/json','X-User':'12'});assert.deepEqual((await r.json()).tracks,[]);
 r=await post('/api/player-library-v76.php',{action:'recent_history'},{'Content-Type':'application/json'});assert.equal(r.status,401);
 r=await post('/api/player-library-v76.php',{action:'recent_history',csrf_token:'wrong'});assert.equal(r.status,419);
 r=await fetch(base+'/api/player-library-v76.php',{headers});assert.equal(r.status,405);
 run('db()->exec(\'DELETE FROM members\');');r=await post('/api/player-library-v76.php',{action:'recent_history'});assert.deepEqual((await r.json()).tracks,[]);
 console.log('PLAYER_HISTORY_API=PASS owner draft playback, canonical history/resume, Brain activity, owner playlist add/deduplication, private draft isolation, account boundaries, CSRF, method and revocation');
}finally{server.kill();fs.rmSync(root,{recursive:true,force:true});}
