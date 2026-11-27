import assert from 'node:assert/strict';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import {execFileSync, spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const analyticsProtocol = 'vp3_music_analytics_v1';
export const analyticsRoute = '/api/music-analytics-v1.php';

/**
 * Production Music Workspace, analytics HTTP route, services and database.
 * Authentication/session discovery and unrelated shared chrome are explicit
 * isolated adapters. The page, controller, stylesheet, member navigation runtime,
 * catalog authority and listening measurement are copied from the repository.
 * Synthetic audio/session seeds use the production native/provider services.
 * This fixture is not a deployed-host or physical-device certification.
 */
export async function musicAnalyticsFixture() {
  const php = process.env.PHP_BINARY || 'php';
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'vp3-music-analytics-'));
  let server, logs = '';
  const close = () => {server?.kill(); fs.rmSync(root, {recursive:true, force:true});};
  try {
    fs.cpSync(path.join(repository, 'includes'), path.join(root, 'includes'), {recursive:true});
    const files = ['music-workspace.php','api/music-analytics-v1.php','assets/js/music-analytics-v1.js','assets/css/music-analytics-v1.css',
      'tests/music-song-metadata-v1.php','tests/fixtures/music-listening-v1.php','tests/fixtures/music-analytics-v1.php','tests/fixtures/stem-mp3-metadata-v1.json',
      'chat.css','member-shell-v77.js','chat-header-ui.css','stonefellow-ui.css','member-page-scroll.css','agent-ui-v034.css'];
    for (const file of files) {
      const target = path.join(root, file); fs.mkdirSync(path.dirname(target), {recursive:true});
      fs.copyFileSync(path.join(repository, file), target);
    }
    fs.writeFileSync(path.join(root, 'includes/bootstrap.php'), `<?php
declare(strict_types=1);
session_save_path(dirname(__DIR__));session_start();
define('STONEFELLOW_ROOT',dirname(__DIR__));
putenv('MUSIC_LISTENING_TEST_DB='.STONEFELLOW_ROOT.'/data.sqlite');
$config=['site'=>['base_path'=>'','base_url'=>'https://vp3.example.test'],'uploads'=>[]];
require STONEFELLOW_ROOT.'/tests/fixtures/music-analytics-v1.php';
function require_login():void{if(!current_user()){http_response_code(401);exit('Please sign in.');}}
`);
    fs.writeFileSync(path.join(root, 'includes/workspace-sidebar-v82.php'), `<link rel="stylesheet" href="/agent-ui-v034.css">
<aside id="chatSidebar" class="chat-sidebar workspace-main-sidebar" data-shell-active="music_workspace">
<div class="chat-sidebar-top"><a class="chat-brand" href="/music-workspace.php?workspace=1">VP3</a><button class="chat-icon-button mobile-only" id="closeChatSidebar" type="button" aria-label="Close menu">×</button></div>
<div class="chat-sidebar-sections"><nav class="chat-sidebar-nav" aria-label="VP3 workspace navigation"><a class="chat-sidebar-nav-link active" href="/music-workspace.php?workspace=1" aria-current="page"><span>♫</span><strong>Music Workspace</strong></a></nav></div></aside>`);
    fs.writeFileSync(path.join(root, 'includes/member-header.php'), `<?php ?>
<header class="chat-topbar member-header" data-member-header data-shell-active="music_workspace"><button class="chat-icon-button mobile-only" id="openChatSidebar" type="button" aria-label="Open VP3 navigation" aria-controls="chatSidebar">☰</button><div class="chat-topbar-title member-header-title"><strong><?= e($memberHeaderTitle??'Music Workspace') ?></strong><span><?= e($memberHeaderSubtitle??'') ?></span></div></header>
<link rel="stylesheet" href="/chat-header-ui.css" data-member-header-ui><link rel="stylesheet" href="/member-page-scroll.css" data-member-page-scroll><script src="/member-shell-v77.js" data-member-shell-runtime defer></script>`);
    fs.writeFileSync(path.join(root, '_test-session.php'), "<?php require __DIR__.'/includes/bootstrap.php';header('Content-Type: application/json');echo json_encode(['csrf_token'=>csrf_token()]);");
    const run = (code, ...args) => execFileSync(php, ['-r','require $argv[1];'+code,path.join(root,'includes/bootstrap.php'),...args], {encoding:'utf8',cwd:repository});
    const seed = JSON.parse(run(`$seed=music_analytics_test_seed();
      foreach(['track_projects','track_stems','shows','release_plans','release_items','track_credits'] as $name)
        db()->exec('CREATE TABLE '.$name.' (id INTEGER PRIMARY KEY,workspace_id INTEGER)');
      echo json_encode($seed,JSON_THROW_ON_ERROR);`));
    const execute = (sql, params=[]) => run('$q=db()->prepare($argv[2]);$q->execute(json_decode($argv[3],true,512,JSON_THROW_ON_ERROR));',sql,JSON.stringify(params));
    const readDb = (sql, params=[]) => JSON.parse(run('$q=db()->prepare($argv[2]);$q->execute(json_decode($argv[3],true,512,JSON_THROW_ON_ERROR));echo json_encode($q->fetchAll(),JSON_THROW_ON_ERROR);',sql,JSON.stringify(params)));
    const snapshot = () => JSON.parse(run('echo json_encode(music_listening_test_snapshot(),JSON_THROW_ON_ERROR);'));
    const socket = net.createServer();
    await new Promise((resolve,reject)=>{socket.once('error',reject);socket.listen(0,'127.0.0.1',resolve);});
    const port = socket.address().port; await new Promise(resolve=>socket.close(resolve));
    server = spawn(php,['-d','display_errors=stderr','-d','opcache.enable_cli=0','-S',`127.0.0.1:${port}`,'-t',root],{stdio:['ignore','pipe','pipe']});
    server.stdout.on('data',chunk=>{logs+=chunk;});server.stderr.on('data',chunk=>{logs+=chunk;});
    const base = `http://127.0.0.1:${port}`;
    let ready = false;
    for(let attempt=0;attempt<60;attempt++){
      try{const response=await fetch(base+'/_test-session.php');if(response.ok){ready=true;break;}}catch{}
      if(server.exitCode!==null)break;
      await new Promise(resolve=>setTimeout(resolve,50));
    }
    assert.ok(ready,'Analytics PHP fixture failed to start: '+logs);
    const json = async response => {
      const body=await response.text();
      try{return JSON.parse(body);}catch{throw new Error(`Invalid analytics JSON (${response.status}): ${body}\n${logs}`);}
    };
    const auth = async (user=11) => {
      const response=await fetch(base+'/_test-session.php',{headers:{'X-User':String(user)}});
      const {csrf_token:csrf}=await json(response);
      const cookie=response.headers.get('set-cookie')?.split(';')[0];
      assert.ok(csrf&&cookie,'Fixture could not initialize a session.');
      return {csrf,cookie,user,headers:{'X-User':String(user),Cookie:cookie}};
    };
    const initial=await auth();
    const post=(input={}, {credentials=initial,user=credentials.user,csrf=credentials.csrf,headers={},path=analyticsRoute}={})=>fetch(base+path,{
      method:'POST',headers:{...credentials.headers,'X-User':String(user),'Content-Type':'application/json',...headers},
      body:JSON.stringify({protocol:analyticsProtocol,action:'report',workspace_id:1,period:'30d',timezone:'UTC',channel:'all',traffic:'audience',csrf_token:csrf,...input}),
    });
    return {root,base,php,run,seed,execute,readDb,snapshot,auth,post,json,initial,close,get logs(){return logs;}};
  } catch(error){close();throw error;}
}
