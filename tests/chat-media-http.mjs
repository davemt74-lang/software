import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {createRequire} from 'node:module';

const php=process.env.PHP_BINARY||'php';
const root=fs.mkdtempSync(path.join(os.tmpdir(),'chat-media-http-'));
const repository=process.cwd();
fs.mkdirSync(path.join(root,'includes'));fs.mkdirSync(path.join(root,'images'));
fs.copyFileSync('media.php',path.join(root,'media.php'));
fs.copyFileSync('includes/music-media.php',path.join(root,'includes/music-media.php'));
fs.copyFileSync('images/stonefellow-studio.png',path.join(root,'images/stonefellow-studio.png'));
// Deterministic one-second PCM audio suitable for real browser decoding.
const wav=Buffer.alloc(44+16000);wav.write('RIFF');wav.writeUInt32LE(wav.length-8,4);wav.write('WAVEfmt ',8);
wav.writeUInt32LE(16,16);wav.writeUInt16LE(1,20);wav.writeUInt16LE(1,22);wav.writeUInt32LE(8000,24);
wav.writeUInt32LE(16000,28);wav.writeUInt16LE(2,32);wav.writeUInt16LE(16,34);wav.write('data',36);wav.writeUInt32LE(16000,40);
for(let i=0;i<8000;i++)wav.writeInt16LE(Math.round(Math.sin(i*2*Math.PI*440/8000)*1000),44+i*2);
fs.writeFileSync(path.join(root,'song.wav'),wav);
const bootstrap=`<?php
define('STONEFELLOW_ROOT',dirname(__DIR__));
function db():PDO { static $pdo; return $pdo??=new PDO('sqlite:'.STONEFELLOW_ROOT.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
function current_user():?array { $id=(int)($_SERVER['HTTP_X_FIXTURE_USER']??$_GET['fixture_user']??0); return $id?['id'=>$id]:null; }
function is_logged_in():bool { return current_user()!==null; }
function get_track_by_id(int $id):?array { $s=db()->prepare('SELECT * FROM tracks WHERE id=? AND is_published=1');$s->execute([$id]);return $s->fetch()?:null; }
function can_manage_track_production(array $track,?array $user=null):bool { $s=db()->prepare('SELECT 1 FROM members WHERE workspace_id=? AND user_id=?');$s->execute([$track['workspace_id'],($user??current_user())['id']??0]);return (bool)$s->fetchColumn(); }
function can_view_track(array $track,?array $user=null):bool { return $track['visibility']==='public'||can_manage_track_production($track,$user); }
function artist_music_v185_public_cover(...$args):?array { return null; }
function artist_music_v185_public_track(...$args):?array { return null; }
function artist_music_v185_resolve_audio(...$args):?string { return null; }
`;
fs.writeFileSync(path.join(root,'includes/bootstrap.php'),bootstrap);
execFileSync(php,['-r',`require $argv[1];db()->exec("CREATE TABLE tracks(id INTEGER,workspace_id INTEGER,is_published INTEGER,visibility TEXT,audio_path TEXT,cover_path TEXT,artist_track_id INTEGER); CREATE TABLE members(workspace_id INTEGER,user_id INTEGER); INSERT INTO members VALUES(1,11); INSERT INTO tracks VALUES(1,1,0,'public','/song.wav','/missing.png',NULL),(2,1,1,'public','/song.wav','',99),(3,1,0,'admin','','',NULL),(4,2,0,'public','/song.wav','',NULL);");`,path.join(root,'includes/bootstrap.php')]);
const socket=net.createServer();await new Promise(resolve=>socket.listen(0,'127.0.0.1',resolve));const port=socket.address().port;await new Promise(resolve=>socket.close(resolve));
const server=spawn(php,['-S',`127.0.0.1:${port}`,'-t',root],{stdio:['ignore','pipe','pipe']});
let errors='';server.stderr.on('data',data=>{errors+=data.toString();});
const base=`http://127.0.0.1:${port}`;
let browser;
try {
  let ready=false;for(let i=0;i<60;i++){try{await fetch(base+'/not-found');ready=true;break;}catch{await new Promise(resolve=>setTimeout(resolve,50));}}assert.ok(ready,'PHP fixture did not start: '+errors);
  const read=(route,options={})=>fetch(base+route,options);
  const owner={'X-Fixture-User':'11'};
  let response=await read('/media.php?track=1&type=audio',{headers:owner});assert.equal(response.status,200);assert.match(response.headers.get('content-type'),/audio\//);assert.deepEqual(Buffer.from(await response.arrayBuffer()),wav);
  response=await read('/media.php?track=1&type=audio',{headers:{...owner,Range:'bytes=44-99'}});assert.equal(response.status,206);assert.equal(response.headers.get('content-range'),`bytes 44-99/${wav.length}`);assert.equal(response.headers.get('content-length'),'56');assert.deepEqual(Buffer.from(await response.arrayBuffer()),wav.subarray(44,100));
  response=await read('/media.php?track=1&type=audio',{method:'HEAD',headers:owner});assert.equal(response.status,200);assert.equal((await response.arrayBuffer()).byteLength,0);assert.equal(Number(response.headers.get('content-length')),wav.length);
  response=await read('/media.php?track=1&type=cover',{headers:owner});assert.equal(response.status,200);assert.match(response.headers.get('content-type'),/image\/png/);assert.deepEqual(Buffer.from(await response.arrayBuffer()),fs.readFileSync('images/stonefellow-studio.png'));
  for(const headers of [{},{'X-Fixture-User':'12'}])assert.equal((await read('/media.php?track=1&type=audio',{headers})).status,404,'Draft audio leaked to an unauthorized user');
  assert.equal((await read('/media.php?track=4&type=audio',{headers:owner})).status,404,'Foreign workspace media leaked');
  assert.equal((await read('/media.php?track=3&type=audio',{headers:owner})).status,404,'Missing full-song audio fabricated');
  response=await read('/media.php?track=2&type=audio');assert.equal(response.status,200,'Stale catalog reference blocked valid backing audio');assert.deepEqual(Buffer.from(await response.arrayBuffer()),wav);
  response=await read('/media.php?track=1&type=audio',{headers:{...owner,Range:'bytes=999999-'}});assert.equal(response.status,416);assert.equal(response.headers.get('content-range'),`bytes */${wav.length}`);

  if(process.argv.includes('--browser')) {
    const require=createRequire(import.meta.url);const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
    browser=await chromium.launch({headless:true,args:['--no-sandbox','--autoplay-policy=no-user-gesture-required']});
    const page=await browser.newPage();const requested=[];page.on('request',request=>{if(request.url().includes('/media.php'))requested.push(request.url());});
    const source=fs.readFileSync('chat.js','utf8');
    const errorUi=source.slice(source.indexOf('  function showChatMediaError('),source.indexOf('  function systemAppActionCardHtml('));
    const html=`<div class="chat-stem-copy"><audio class="chat-stem-preview" controls preload="none" src="/media.php?track=1&type=audio&fixture_user=11"></audio></div><div class="chat-stem-copy"><audio id="missing" class="chat-stem-preview" controls preload="none" src="/media.php?track=3&type=audio&fixture_user=11"></audio></div><script>${errorUi}</script>`;
    fs.writeFileSync(path.join(root,'browser.html'),html);
    await page.goto(base+'/browser.html');await page.waitForTimeout(350);assert.equal(requested.length,0,'Hidden players fetched media at page load');
    await page.locator('audio').first().evaluate(audio=>audio.play());await page.waitForFunction(()=>document.querySelector('audio').currentTime>0);
    assert.equal(await page.locator('audio').first().evaluate(audio=>Math.round(audio.duration)),1,'Valid draft audio failed to decode');
    await page.locator('#missing').evaluate(audio=>audio.play().catch(()=>{}));await page.locator('[data-chat-media-status]').waitFor();assert.match(await page.locator('[data-chat-media-status]').textContent(),/Audio is unavailable/);
    console.log('CHAT_MEDIA_BROWSER=PASS zero-eager-requests draft-playback failure-feedback');
  }
  execFileSync(php,['-r',`require $argv[1];db()->exec('DELETE FROM members');`,path.join(root,'includes/bootstrap.php')]);
  assert.equal((await read('/media.php?track=1&type=audio',{headers:owner})).status,404,'Revoked membership retained media');
  console.log('CHAT_MEDIA_HTTP=PASS draft-auth ranges HEAD cover-fallback backing-fallback revocation');
} finally { await browser?.close();server.kill();await new Promise(resolve=>{if(server.exitCode!==null)resolve();else server.once('exit',resolve);});fs.rmSync(root,{recursive:true,force:true}); }
