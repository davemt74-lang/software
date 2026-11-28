import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {copyFileSync,mkdirSync,mkdtempSync,rmSync,writeFileSync} from 'node:fs';
import {createServer} from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
const root=mkdtempSync(join(tmpdir(),'vp3-email-capture-http-'));let server,logs='';
try{
  mkdirSync(join(root,'api'));mkdirSync(join(root,'includes'));
  for(const name of ['gmail-email-capture-source-v1.php','gmail-email-capture-input-v1.php'])copyFileSync(new URL('../includes/'+name,import.meta.url),join(root,'includes',name));
  copyFileSync(new URL('../api/gmail-email-capture-v1.php',import.meta.url),join(root,'api/gmail-email-capture-v1.php'));
  writeFileSync(join(root,'includes/bootstrap.php'),`<?php
session_start();
if(isset($_SERVER['HTTP_X_FIXTURE_PHONE']))define('VP3_PHONE_AGENT_NATIVE_V1',true);
function current_user():?array{return isset($_SERVER['HTTP_X_FIXTURE_ANONYMOUS'])?null:['id'=>(int)($_SERVER['HTTP_X_FIXTURE_OWNER']??1)];}
function has_permission($name,$user):bool{return !isset($_SERVER['HTTP_X_FIXTURE_DENY']);}
function csrf_token():string{return 'http-capture-csrf';}
function db():PDO{static $pdo;return $pdo??=new PDO('sqlite::memory:');}
function vp3_participant_scope_v1():?array{return isset($_SERVER['HTTP_X_FIXTURE_PARTICIPANT'])?['participant_id'=>3]:null;}
function ai_decrypt_secret(string $value):string{return $value;}
function agent_connection_gmail_id_v1($value):string{return is_string($value)&&preg_match('/^[A-Za-z0-9_-]{1,190}$/D',$value)?$value:'';}
`);
  writeFileSync(join(root,'includes/agent-chat-runtime-v2160.php'),`<?php
function vp3_agent_chat_resolve_agent_v380(PDO $pdo,array $user,int $agent):?array{
 if($user['id']===1&&in_array($agent,[0,7],true))return $agent?['id'=>$agent]:null;
 throw new DomainException('Foreign Agent.',403);
}
function vp3_agent_chat_principal_v380(array $user,?array $agent):array{return ['agent_id'=>$agent['id']??0];}
`);
  writeFileSync(join(root,'includes/agent-email-preferences-http-v1.php'),`<?php
function agent_email_preferences_http_current_v1(PDO $pdo,array $user,array $principal,int $chat):Closure{
 return static function()use($principal,$chat):bool{
  if(isset($_SERVER['HTTP_X_FIXTURE_REVOKED'])||($principal['agent_id']===7?$chat!==11:$chat!==14))throw new DomainException('Private Chat access changed.',403);
  return true;
 };
}
`);
  writeFileSync(join(root,'includes/gmail-email-capture-v1.php'),`<?php
require_once __DIR__.'/gmail-email-capture-source-v1.php';require_once __DIR__.'/gmail-email-capture-input-v1.php';
function gmail_email_capture_analyze_v1($pdo,$user,$principal,$chat,$source,$input,$current):array{
 $current();$c=gmail_email_capture_input_v1($input);header('X-Fixture-Capture: analyze');
 return ['id'=>substr(gmail_email_capture_hash_v1([$user['id'],$principal['agent_id'],$chat,$input['request_id']]),0,32),'state'=>'ready','result'=>['summary'=>'Scoped selected text']];
}
function gmail_email_capture_get_v1($pdo,$user,$principal,$chat,$source,$id,$current):array{
 $current();if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Invalid saved result.',422);
 header('X-Fixture-Capture: get');return ['id'=>$id,'state'=>'ready'];
}
function gmail_email_capture_history_v1($pdo,$user,$principal,$chat,$source,$cursor,$current):array{
 $current();header('X-Fixture-Capture: history');return ['items'=>[],'next_cursor'=>null,'actions'=>[]];
}
function gmail_email_capture_finding_v1($pdo,$user,$principal,$chat,$source,$action,$input,$current):array{
 $current();header('X-Fixture-Capture: '.$action);return ['kind'=>$action,'message'=>'Finding saved'];
}
function gmail_email_capture_save_v1($pdo,$user,$principal,$chat,$source,$action,$input,$current):array{
 $current();header('X-Fixture-Capture: '.$action);return ['kind'=>$action,'message'=>'Saved'];
}
`);
  const port=await new Promise(resolve=>{const s=createServer().listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
  server=spawn(process.env.PHP_BINARY||'php',['-S',`127.0.0.1:${port}`,'-t',root],{stdio:['ignore','pipe','pipe']});
  server.stdout.on('data',d=>logs+=d);server.stderr.on('data',d=>logs+=d);
  const url=`http://127.0.0.1:${port}/api/gmail-email-capture-v1.php`;
  for(let i=0;i<80;i++){try{await fetch(url);break;}catch{await new Promise(resolve=>setTimeout(resolve,25));}}
  const source={contract:'gmail-capture-source-v1',owner:1,connection:7,grant:'a'.repeat(64),fingerprint:'fingerprint',account:'owner@example.test',message:'capture_one',thread:'capture_thread',revision:'b'.repeat(64),expires:Math.floor(Date.now()/1000)+900};
  const token=Buffer.from(JSON.stringify(source)).toString('hex');
  const input={action:'analyze',owner_key:'1',agent_id:7,conversation_id:11,csrf_token:'http-capture-csrf',source_ref:token,request_id:'c'.repeat(32),mode:'text',text:'Selected email date',image:'',timezone:'America/Phoenix'};
  async function post(body=input,headers={}){const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json',...headers},body:typeof body==='string'?body:JSON.stringify(body)});return {r,data:await r.json()};}
  let n=0;
  const expect=async(body,headers,status,label)=>{const {r}=await post(body,headers);assert.equal(r.status,status,label);n++;};
  let {r,data}=await post();assert.equal(r.status,200);assert.equal(r.headers.get('x-fixture-capture'),'analyze');assert.equal(r.headers.get('cache-control'),'private, no-store');assert.equal(r.headers.get('referrer-policy'),'no-referrer');n++;
  const id=data.capture.id;
  const get={...input,action:'get'};for(const field of ['mode','text','image','timezone'])delete get[field];
  ({r,data}=await post(get));assert.equal(r.status,200);assert.equal(data.capture.id,id,'Lost-body GET derives the exact same scoped saved attempt identity');n++;
  for(const action of ['calendar','reminder']){const body={...input,action,fields:{title:'Reviewed action'}};for(const key of ['request_id','mode','image','timezone'])delete body[key];const {r}=await post(body);assert.equal(r.status,200);assert.equal(r.headers.get('x-fixture-capture'),action);n++;}
  const base={owner_key:input.owner_key,agent_id:7,conversation_id:11,csrf_token:input.csrf_token,source_ref:token};
  ({r,data}=await post({...base,action:'history'}));assert.equal(r.status,200);assert.deepEqual(data.history.items,[]);assert.equal(r.headers.get('x-fixture-capture'),'history');n++;
  for(const action of ['task','chat']){({r,data}=await post({...base,action,capture_id:id,text:'Reviewed excerpt',fields:action==='task'?{title:'Reviewed task',notes:'Finding'}:{message:'Reviewed finding'}}));assert.equal(r.status,200);assert.equal(data.saved.kind,action);assert.equal(r.headers.get('x-fixture-capture'),action);n++;}
  await expect(input,{'X-Fixture-Anonymous':'1'},401,'anonymous capture');
  await expect(input,{'X-Fixture-Deny':'1'},401,'revoked Chat entitlement');
  await expect({...input,owner_key:'2'},{},409,'changed owner');
  await expect({...input,csrf_token:'bad'},{},419,'CSRF');
  await expect({...input,agent_id:8},{},403,'other Agent');
  await expect({...input,conversation_id:12},{},403,'other Chat');
  await expect(input,{'X-Fixture-Phone':'1'},403,'paired Phone cannot acquire owner capture actions');
  await expect(input,{'X-Fixture-Participant':'1'},403,'reported participant cannot acquire owner capture actions');
  await expect(input,{'X-Fixture-Revoked':'1'},403,'late private scope revocation');
  await expect({...input,url:'https://private.example/action?token=secret'},{},422,'arbitrary target field');
  await expect({...input,mode:'photo',image:'https://private.example/photo'},{},422,'remote image URL');
  await expect({...input,source_ref:'invalid'},{},422,'malformed source');
  await expect({...input,source_ref:Buffer.from(JSON.stringify({...source,owner:2})).toString('hex')},{},409,'foreign signed source');
  await expect({...input,source_ref:Buffer.from(JSON.stringify({...source,expires:1})).toString('hex')},{},409,'expired source');
  await expect({...input,action:'send_email'},{},422,'no email send operation');
  await expect([] ,{},422,'JSON list');await expect('"scalar"',{},422,'JSON scalar');await expect('x'.repeat(460001),{},413,'bounded body');
  await expect({...get,capture_id:'d'.repeat(32)},{},422,'ambiguous saved result identity');
  const captureGet={...base,action:'get',capture_id:[]};await expect(captureGet,{},422,'non-string saved result identity');
  await expect({...base,action:'history',cursor:[]},{},422,'non-string history cursor');
  for(const action of ['history','task','chat']){
    const body={...base,action,...(action==='history'?{}:{fields:{title:'No side effect'},text:''})};
    await expect(body,{'X-Fixture-Revoked':'1'},403,action+' rechecks the live private Chat');
    await expect(body,{'X-Fixture-Phone':'1'},403,action+' remains unavailable to paired Phone authority');
  }
  const system={...input,agent_id:0,conversation_id:14};({r}=await post(system));assert.equal(r.status,200);n++;
  console.log(`GMAIL_EMAIL_CAPTURE_HTTP=PASS (${n} cases)`);
}catch(error){console.error(logs.slice(-3000));throw error;}
finally{if(server){server.kill();await new Promise(resolve=>server.once('exit',resolve));}rmSync(root,{recursive:true,force:true});}
