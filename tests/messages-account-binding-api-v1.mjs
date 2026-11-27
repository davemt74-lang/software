import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';

// Run the deployed PHP entry points with an isolated authenticated-session and
// storage fixture. The expected-account guard must run before domain reads/writes.
const root=process.cwd(),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-messages-identity-'));
fs.mkdirSync(path.join(tmp,'api'));fs.mkdirSync(path.join(tmp,'includes'));
for(const name of ['messages-v320.php','browser-share-chat-feed-v2020.php','browser-share-media-v2040.php'])fs.copyFileSync(path.join(root,'api',name),path.join(tmp,'api',name));
for(const name of ['human-messaging-block-v370.php','browser-share-chat-feed-v2020.php','extension-device-auth-v2001.php','browser-share-media-v2040.php'])fs.writeFileSync(path.join(tmp,'includes',name),'<?php // Domain adapters are supplied by the isolated fixture.');
fs.writeFileSync(path.join(tmp,'includes','bootstrap.php'),`<?php
const VP3_BROWSER_SHARE_CHAT_FEED_V2020='fixture';const VP3_BROWSER_SHARE_FEED_LIMIT_MAX_V2020=100;
$pdo=new PDO('sqlite:'.__DIR__.'/identity.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE IF NOT EXISTS writes(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,kind TEXT)');
function db():PDO{return $GLOBALS['pdo'];}
function current_user():?array{$id=(int)($_SERVER['HTTP_X_FIXTURE_USER']??0);return $id>0?['id'=>$id,'is_active'=>1]:null;}
function csrf_token():string{return 'fixture-csrf';}
function verify_csrf():bool{return ($_POST['csrf_token']??'')===csrf_token();}
function vp3_human_messaging_v370_ensure_schema(PDO $pdo):void{}
function vp3_human_messaging_v370_ready(PDO $pdo):bool{return true;}
function vp3_human_inbox_v370(PDO $pdo,int $id):array{return ['owner'=>$id,'teams'=>[],'requests'=>[],'conversations'=>[['id'=>$id,'last_message'=>'Private user '.$id]],'writes'=>(int)$pdo->query('SELECT COUNT(*) FROM writes')->fetchColumn()];}
function vp3_social_settings_v320(PDO $pdo,int $id):array{return ['message_policy'=>'friends'];}
function vp3_human_send_message_v370(PDO $pdo,int $cid,int $id,string $body):array{$q=$pdo->prepare('INSERT INTO writes(user_id,kind) VALUES(?,?)');$q->execute([$id,'message']);return ['id'=>(int)$pdo->lastInsertId(),'sender_user_id'=>$id,'body'=>$body];}
function vp3_browser_share_chat_feed_require_ready_v2020(PDO $pdo):void{}
function vp3_browser_share_feed_v2020(PDO $pdo,int $id,int $limit,int $before):array{return ['items'=>[['id'=>'share-'.$id,'note'=>'Private user '.$id]]];}
function vp3_browser_share_create_task_v2020(PDO $pdo,array $user,string $share):array{$q=$pdo->prepare('INSERT INTO writes(user_id,kind) VALUES(?,?)');$q->execute([$user['id'],'share']);return ['id'=>(int)$pdo->lastInsertId()];}
class VP3BrowserShareMediaExceptionV2040 extends RuntimeException{public function __construct(public readonly string $apiCode,public readonly int $httpStatus,string $message){parent::__construct($message);}}
function vp3_extension_apply_cors_v2001():void{}
function vp3_browser_share_media_require_ready_v2040(PDO $pdo):PDO{return $pdo;}
function vp3_browser_share_media_list_v2040(PDO $pdo,string $share,int $id):array{return [['id'=>'media-'.$id,'kind'=>'screenshot','original_name'=>'Authorized media for '.$id]];}
`);
const reserve=net.createServer();await new Promise(resolve=>reserve.listen(0,'127.0.0.1',resolve));const port=reserve.address().port;await new Promise(resolve=>reserve.close(resolve));
const php=process.env.VP3_PHP_BINARY||'php';
const server=spawn(php,['-d','display_errors=0','-S',`127.0.0.1:${port}`,'-t',tmp],{stdio:['ignore','ignore','pipe']});let logs='';server.stderr.on('data',chunk=>logs+=chunk);
async function request({surface='messages',action='inbox',user=1,expected='1',method='GET',csrf='fixture-csrf'}={}){
  const headers={'X-Fixture-User':String(user)};if(expected!==null)headers['X-VP3-Messages-User']=expected;
  const body=new URLSearchParams({csrf_token:csrf,conversation_id:'10',message:'Fixture message',browser_share_id:'fixture-share',action});
  const endpoint=surface==='messages'?'messages-v320':surface==='media'?'browser-share-media-v2040':'browser-share-chat-feed-v2020';
  const response=await fetch(`http://127.0.0.1:${port}/api/${endpoint}.php?action=${encodeURIComponent(action)}&browser_share_id=fixture-share`,{method,headers,...(method==='POST'?{body}:{})});
  return {status:response.status,data:await response.json()};
}
let checks=0;const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
try{
  let started=false;for(let i=0;i<100;i++){try{if((await request()).status===200){started=true;break;}}catch{}await new Promise(resolve=>setTimeout(resolve,20));}assert.ok(started,logs);
  let result=await request();check(result.status===200&&result.data.inbox.owner===1,'bound Messages page reads its rendered account');
  result=await request({user:2});check(result.status===403&&result.data.error==='account_changed'&&!result.data.inbox,'account switch does not serialize the new account inbox');
  result=await request({user:2,expected:null});check(result.status===200&&result.data.inbox.owner===2,'legacy caller without optional header remains compatible');
  result=await request({user:2,method:'POST',action:'send'});check(result.status===403&&result.data.error==='account_changed','stale page cannot send as the new account');
  result=await request({user:2,expected:'2'});check(result.data.inbox.writes===0,'rejected stale send performs no domain write');
  result=await request({user:2,expected:'02'});check(result.status===403,'account binding is compared exactly');
  result=await request({user:0});check(result.status===401&&!result.data.inbox,'signed-out page cannot read inbox data');
  result=await request({surface:'shares',action:'feed',user:2});check(result.status===403&&result.data.error==='account_changed'&&!result.data.feed,'separate Shares feed enforces the same rendered account');
  result=await request({surface:'shares',action:'create_task',method:'POST',user:2});check(result.status===403,'stale share card cannot create a task as the new account');
  result=await request({user:2,expected:'2'});check(result.data.inbox.writes===0,'rejected share action performs no domain write');
  result=await request({surface:'shares',action:'feed',user:2,expected:null});check(result.status===200&&result.data.feed.items[0].id==='share-2','existing share clients without binding still work');
  result=await request({surface:'shares',action:'feed',user:0});check(result.status===401,'signed-out Shares request cannot return private content');
  result=await request({action:'send',method:'POST',csrf:'invalid'});check(result.status===419,'matching account does not bypass CSRF');
  result=await request({action:'send'});check(result.status===405,'matching account does not bypass method restrictions');
  result=await request({action:'send',method:'POST'});check(result.status===200&&result.data.message.sender_user_id===1,'current bound account can still send normally');
  result=await request({surface:'shares',action:'create_task',method:'POST',user:2,expected:'2'});check(result.status===200,'current bound account can still use share actions');
  result=await request({surface:'media'});check(result.status===200&&result.data.media[0].id==='media-1','media list accepts the rendered account binding');
  result=await request({surface:'media',user:2});check(result.status===403&&result.data.error.code==='account_changed'&&!result.data.media,'media list rejects changed account before serializing media');
  result=await request({surface:'media',user:0});check(result.status===401&&result.data.error.code==='login_required'&&!result.data.media,'bound media list detects logout even when public media exists');
  result=await request({surface:'media',user:2,expected:null});check(result.status===200&&result.data.media[0].id==='media-2','legacy media caller without optional binding remains compatible');
  result=await request({surface:'media',user:0,expected:null});check(result.status===200&&result.data.media[0].id==='media-0','unbound anonymous media reads still reach existing public-object authorization');
  console.log(`MESSAGES_ACCOUNT_BINDING_API_V1=PASS ${checks} checks`);
}finally{server.kill('SIGTERM');await new Promise(resolve=>server.exitCode!==null?resolve():server.once('exit',resolve));fs.rmSync(tmp,{recursive:true,force:true});}
