import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';
import {VP3ProfileWebMCPRuntimeV100} from '../profile-webmcp-v100.js';

const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-native-outcomes-'));
fs.cpSync('includes',path.join(tmp,'includes'),{recursive:true});fs.mkdirSync(path.join(tmp,'api'));
fs.copyFileSync('api/profile-webmcp-v100.php',path.join(tmp,'api/profile-webmcp-v100.php'));
for(const [source,dest] of Object.entries({
 'profile-webmcp-native-bootstrap-v1.php':'bootstrap.php',
 'profile-webmcp-native-public-v1.php':'profile-webmcp-v100.php',
 'profile-webmcp-native-telemetry-v1.php':'profile-webmcp-analytics-v130.php',
 'profile-webmcp-native-router-v1.php':'profile-webmcp-tool-router-v191.php',
 'profile-webmcp-outcomes-v1.php':'profile-webmcp-outcomes-fixture-v1.php',
}))fs.copyFileSync('tests/fixtures/'+source,path.join(tmp,'includes',dest));
fs.writeFileSync(path.join(tmp,'state.php'),`<?php
require __DIR__.'/includes/bootstrap.php';
if(isset($_GET['reset']))native_fixture_reset_v1();
$mode=$_GET['mode']??'';
$sql=match($mode){'inactive'=>'UPDATE users SET is_active=0 WHERE id=1','agent'=>'UPDATE user_agents SET is_active=0 WHERE id=7','conversation'=>'UPDATE chat_conversations SET user_agent_id=8 WHERE id=1','permission'=>'UPDATE users SET profile_allowed=0 WHERE id=1','profile'=>'UPDATE user_profiles SET is_public=0 WHERE user_id=1',default=>''};
if($sql!=='')db()->exec($sql);
$row=array_values($_SESSION['vp3_profile_webmcp_action_context_v195'])[0];
if($mode==='consumed'){$row['returned_at']=time();$row['return_token_hash']='';$_SESSION['vp3_profile_webmcp_action_context_v195'][$row['context_id']]=$row;}
if($mode==='expired'){$row['expires_at']=time()-1;$_SESSION['vp3_profile_webmcp_action_context_v195'][$row['context_id']]=$row;}
header('Content-Type: application/json');echo json_encode(['context_id'=>$row['context_id'],'calls'=>(int)db()->query('SELECT COUNT(*) FROM fixture_calls')->fetchColumn(),'committed'=>(int)db()->query("SELECT COUNT(*) FROM profile_webmcp_actions WHERE state='committed'")->fetchColumn(),'server_outcome'=>$row['server_outcome']??null]);`);
const reserve=net.createServer();await new Promise(r=>reserve.listen(0,'127.0.0.1',r));const port=reserve.address().port;await new Promise(r=>reserve.close(r));
const child=spawn(process.env.VP3_PHP_BINARY||'php',['-S',`127.0.0.1:${port}`,'-t',tmp],{stdio:['ignore','ignore','pipe']});let logs='';child.stderr.on('data',x=>logs+=x);
const base=`http://127.0.0.1:${port}`;let checks=0;
function check(ok,label,detail=null){assert.ok(ok,label+(ok?'':' '+JSON.stringify(detail)+' '+logs.slice(-1000)));checks++;console.log('PASS '+label);}
const state=async(query='')=>{const raw=await(await fetch(base+'/state.php'+query)).text();try{return JSON.parse(raw);}catch{throw Error(raw+'\n'+logs.slice(-2000));}};
async function post(tool,input={},continuity=undefined,extra={},headers={}){
 const body={manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'fixture',tool,input,telemetry:{webmcp_session_id:'FixtureNativeSession',interaction_id:'FixtureInteract',correlation_id:'UntrustedClientCorrelation'},...extra};if(continuity!==undefined)body.continuity=continuity;
 const r=await fetch(base+'/api/profile-webmcp-v100.php',{method:'POST',headers:{'Content-Type':'application/json',Origin:base,'X-VP3-WebMCP-Session':'a'.repeat(64),...headers},body:JSON.stringify(body)});const raw=await r.text();let data;try{data=JSON.parse(raw);}catch{throw Error(raw+'\n'+logs.slice(-2500));}return {status:r.status,data};
}
try{
 for(let i=0;i<60;i++){try{await state();break;}catch{await new Promise(r=>setTimeout(r,50));}}
 let s=await state('?reset=1'),proof={context_id:s.context_id,return_token:'b'.repeat(32)};
 let r=await post('vp3.profile.get');check(r.data.ok===true&&(await state()).calls===1,'ordinary public request with absent continuity remains available');
 for(const invalid of [null,[],{},'bad',{...proof,return_token:'c'.repeat(32)},{...proof,context_id:'f'.repeat(32)},{...proof,phase:'completed'}]){
  await state('?reset=1');r=await post('vp3.booking.prepare',{},invalid);check(r.status===403&&(await state()).calls===0,'supplied invalid proof is rejected before native operation dispatch');
 }
 for(const mode of ['inactive','agent','conversation','permission','profile','consumed','expired']){
  s=await state('?reset=1');proof={context_id:s.context_id,return_token:'b'.repeat(32)};await state('?mode='+mode);r=await post('vp3.booking.prepare',{},proof);check([403,404].includes(r.status)&&(await state()).calls===0,mode+' source authority denies before dispatch');
 }
 s=await state('?reset=1');proof={context_id:s.context_id,return_token:'b'.repeat(32)};r=await post('vp3.booking.prepare',{},proof);check(r.data.ok&&r.data.profile_webmcp_outcome?.state==='prepared','actual native API binds the canonical prepared intent',r);
 const args={intent:r.data.intent,confirmation_token:r.data.confirmation_token,idempotency_key:'FixtureConfirm_1'};
 let bad={...args,intent:{...args.intent,target:999}};r=await post('vp3.booking.confirm',bad,proof);check(r.status===403&&(await state()).committed===0,'changed confirmation payload is rejected before commit');
 r=await post('vp3.booking.confirm',args,proof);check(r.data.ok&&r.data.profile_webmcp_outcome?.result==='booking_created'&&(await state()).committed===1,'actual native confirmation binds the real committed receipt');
 check(!JSON.stringify(r.data.profile_webmcp_outcome).includes('SECRET')&&!JSON.stringify(r.data.profile_webmcp_outcome).includes('token'),'native public outcome excludes domain tokens and PII');
 r=await post('vp3.booking.confirm',args,proof);check(r.data.ok&&r.data.profile_webmcp_outcome.replayed===true&&(await state()).committed===1,'same-key native replay returns evidence without a second commitment');
 for(const late of ['agent','conversation','profile','malformed_receipt']){
  s=await state('?reset=1');proof={context_id:s.context_id,return_token:'b'.repeat(32)};const prepared=await post('vp3.booking.prepare',{},proof);const actual={intent:prepared.data.intent,confirmation_token:prepared.data.confirmation_token,idempotency_key:'FixtureConfirm_1'};
  r=await post('vp3.booking.confirm',actual,proof,{}, {'X-Fixture-Late':late});s=await state();check(r.data.ok===false&&r.data.error?.code==='ACTION_OUTCOME_UNVERIFIED'&&s.committed===1&&s.server_outcome?.state==='prepared','late '+late+' cannot promote an unverified response or replay the action');
 }
 const telemetry=fs.readFileSync(path.join(tmp,'telemetry.jsonl'),'utf8');check(!telemetry.includes('b'.repeat(32))&&!telemetry.includes('return_token'),'continuity proof never enters telemetry');

 if(typeof globalThis.CustomEvent!=='function')globalThis.CustomEvent=class CustomEvent extends Event{constructor(type,init={}){super(type);this.detail=init.detail;}};
 class ModelContext{constructor(){this.tools=new Map();}async registerTool(tool,options={}){this.tools.set(tool.name,tool);options.signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}}
 class Doc extends EventTarget{constructor(){super();this.modelContext=new ModelContext();this.defaultView={CustomEvent:globalThis.CustomEvent};}}
 const doc=new Doc(),calls=[],events=[];
 const runtime=new VP3ProfileWebMCPRuntimeV100({documentObject:doc,sessionProof:'fixture',onEvent:e=>events.push(e),fetchImpl:async(url,options)=>{
  const body=JSON.parse(options.body);calls.push(body);
  return {ok:true,status:200,async json(){return body.tool==='vp3.intent.resolve'?{ok:true,resolution:{recommended_capabilities:['profile']}}:{ok:true,profile:{}};}};
 }});
 await runtime.start({manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'fixture',capabilities:{profile:true},allowed_tools:['vp3.profile.get','vp3.intent.resolve']});
 await doc.modelContext.tools.get('vp3.profile.get').execute({literal:'unchanged'});check(calls.at(-1).continuity===undefined,'ordinary browser runtime omits continuity entirely');
 await runtime.resume({contract:'vp3.webmcp.resume.v1',profile_username:'fixture',goal:'profile',recommended_capabilities:['profile'],recommended_tools:[],resolved_capabilities:['profile'],action_context_id:'a'.repeat(32),return_token:'b'.repeat(32),return_path:'/chat.php'});
 await doc.modelContext.tools.get('vp3.profile.get').execute({literal:'unchanged'});const wire=calls.at(-1);check(wire.continuity?.context_id==='a'.repeat(32)&&wire.continuity.return_token==='b'.repeat(32),'resumed browser runtime sends separate top-level proof');
 check(JSON.stringify(wire.input)==='{"literal":"unchanged"}'&&!JSON.stringify(wire.telemetry).includes('b'.repeat(32))&&!JSON.stringify(events).includes('b'.repeat(32)),'browser proof is separated from tool arguments, telemetry and events');
 console.log(`PROFILE_WEBMCP_NATIVE_OUTCOMES_V1=PASS (${checks} cases; actual HTTP endpoint/context/receipt code, fixture discovery and business operation)`);
}finally{
 child.kill();await new Promise(r=>child.once('exit',r));fs.rmSync(tmp,{recursive:true,force:true});
}
