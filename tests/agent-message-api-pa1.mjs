import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const dir=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-agent-message-pa1-'));
const auditPath=path.join(dir,'request-audit.json');
fs.mkdirSync(path.join(dir,'api'));fs.mkdirSync(path.join(dir,'includes'));
fs.copyFileSync(path.join(root,'api/agent-message.php'),path.join(dir,'api/agent-message.php'));
const phpQuote=s=>"'"+s.replaceAll('\\','\\\\').replaceAll("'","\\'")+"'";
fs.writeFileSync(path.join(dir,'includes/bootstrap.php'),`<?php\ndefine('VP3_AGENT_MESSAGE_API_FIXTURE',true);\nrequire ${phpQuote(path.join(root,'tests/agent-message-authority-pa1.php'))};\n`);
const listener=net.createServer();await new Promise(resolve=>listener.listen(0,'127.0.0.1',resolve));const port=listener.address().port;await new Promise(resolve=>listener.close(resolve));
const server=spawn(process.env.PHP_BINARY||'php',['-d','display_errors=0','-S',`127.0.0.1:${port}`,'-t',dir],{env:{...process.env,VP3_MESSAGE_HTTP_AUDIT:auditPath},stdio:['ignore','ignore','pipe']});
let serverLog='';server.stderr.on('data',data=>{serverLog+=data;});
let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;};
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
try{
  let ready=false;
  for(let i=0;i<100;i++){try{await fetch(`http://127.0.0.1:${port}/ready`);ready=true;break;}catch{if(server.exitCode!==null)throw new Error(serverLog);await pause(20);}}
  assert.ok(ready,'fixture HTTP server started');
  async function call({mode='',token='a',method='POST',body={message:'Public brochure'},raw,ua='ChatGPT-User fixture',username='fixture'}={}){
    fs.rmSync(auditPath,{force:true});
    const response=await fetch(`http://127.0.0.1:${port}/api/agent-message.php?username=${username}`,{method,headers:{'Content-Type':'application/json','User-Agent':ua,'X-Fixture-Mode':mode,...(token?{Authorization:`Bearer ${token.repeat(64)}`}:{})},...(method==='POST'?{body:raw??JSON.stringify(body)}:{})});
    const text=await response.text();const data=text?JSON.parse(text):null;
    for(let i=0;i<100&&!fs.existsSync(auditPath);i++)await pause(10);
    const audit=JSON.parse(fs.readFileSync(auditPath,'utf8'));
    check(!text.includes('SECRET')&&!text.includes('SQLSTATE')&&!text.includes('/private/')&&!text.includes('_approved_context')&&!text.includes('_history_contexts'),'response excludes private history, provider/SQL details and internal source proofs');
    check(audit.ddl===0,'actual endpoint performs no schema writes');
    return {response,data,audit,status:response.status};
  }
  let out=await call();check(out.status===200&&out.data.answer==='Approved public reply.'&&out.data.grant.status==='approved'&&out.audit.provider_calls===1,'valid approved grant completes through actual endpoint');
  out=await call({mode:'history'});check(out.status===200&&JSON.stringify(out.audit.history)==='[{"role":"user","message":"Own history only."}]','actual endpoint isolates two caller grants sharing one Radar contact');
  out=await call({token:'c'});check(out.status===200&&out.data.grant.status==='consumed'&&out.audit.grants.find(r=>r.id===53).status==='consumed','one-use success reports consumed grant');
  for(const mode of ['grant_denied','grant_expired','referral_denied','referral_source_revoked']){
    out=await call({mode});check(out.status===403&&out.data.ok===false&&!('answer' in out.data)&&!('referral' in out.data),`${mode} suppresses late answer and referral`);
  }
  out=await call({mode:'provider_error',token:'c'});check(out.status===503&&out.audit.grants.find(r=>r.id===53).status==='consumed','uncertain provider failure does not resurrect a one-use grant');
  out=await call({mode:'provider_exception'});check(out.status===500&&out.data.error==='The Profile Agent could not complete this message.','unexpected provider exception has fixed public error');
  out=await call({mode:'setup_error'});check(out.status===503&&out.data.error==='VP3 Agent Messaging is temporarily unavailable.','setup SQL exception has fixed public error');
  out=await call({mode:'quota',token:'c'});check(out.status===402&&out.audit.grants.find(r=>r.id===53).status==='approved_once','known pre-provider quota refusal preserves one-use retry');
  out=await call({mode:'rate'});check(out.status===429&&out.response.headers.get('retry-after')==='10'&&out.audit.provider_calls===0,'aggregate rate limit and retry hint are preserved');
  for(const [raw,status] of [['{',400],[JSON.stringify({message:['SECRET']}),422],['x'.repeat(8193),413]]){
    out=await call({raw,token:'c'});check(out.status===status&&out.audit.provider_calls===0&&out.audit.grants.find(r=>r.id===53).status==='approved_once','invalid pre-provider payload restores only its exact one-use claim');
  }
  for(const args of [{token:''},{ua:'Unrecognized automated client'},{token:'d'},{username:'other'}]){
    out=await call(args);check([401,403].includes(out.status)&&out.audit.provider_calls===0,'unapproved owner/caller/token cannot infer or read history');
  }
  out=await call({username:'fixture&username[]=invalid'});check(out.status===404&&out.audit.provider_calls===0,'malformed Profile selector has a fixed safe response');
  out=await call({method:'GET'});check(out.status===405&&out.audit.provider_calls===0,'external message endpoint remains POST-only');
  out=await call({method:'OPTIONS'});check(out.status===204&&out.audit.provider_calls===0,'public CORS preflight remains available without inference');
  console.log(`AGENT_MESSAGE_API_PA1=PASS (${checks} checks; actual PHP endpoint; fixture providers only)`);
}finally{
  server.kill('SIGTERM');await new Promise(resolve=>server.once('exit',resolve));fs.rmSync(dir,{recursive:true,force:true});
}
