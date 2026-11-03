import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn,spawnSync} from 'node:child_process';

const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-pa1-owner-api-'));
const php=process.env.VP3_PHP_BINARY||'php';
const config=path.join(tmp,'fixture-config.json');
const fixture=path.resolve('tests/fixtures/profile-owner-api-pa1-db.php');
const init=spawnSync(php,[fixture,'init',config],{encoding:'utf8'});
assert.equal(init.status,0,init.stderr);const driver=init.stdout.trim();
fs.cpSync('includes',path.join(tmp,'includes'),{recursive:true});fs.mkdirSync(path.join(tmp,'api'));
fs.copyFileSync('api/profile-agent.php',path.join(tmp,'api/profile-agent.php'));
fs.copyFileSync('tests/fixtures/profile-owner-api-pa1.php',path.join(tmp,'includes/bootstrap.php'));
fs.copyFileSync('tests/fixtures/profile-owner-api-pa1-state.php',path.join(tmp,'state.php'));
const reservation=net.createServer();await new Promise(r=>reservation.listen(0,'127.0.0.1',r));const port=reservation.address().port;await new Promise(r=>reservation.close(r));
const child=spawn(php,['-d','display_errors=0','-S',`127.0.0.1:${port}`,'-t',tmp],{env:{...process.env,VP3_PA1_API_CONFIG:config},stdio:['ignore','ignore','pipe']});
let logs='',checks=0;child.stderr.on('data',data=>logs+=data);const base=`http://127.0.0.1:${port}`;
const check=(condition,label)=>{assert.ok(condition,label);checks++;console.log('PASS '+label);};
async function state(){const r=await fetch(base+'/state.php');assert.equal(r.status,200);return await r.json();}
async function request(method,fields={},headers={},query={}){
  const read=method==='GET'||method==='HEAD';const params=new URLSearchParams(read?{...query,...fields}:query);
  const response=await fetch(base+'/api/profile-agent.php?'+params,{method,headers:{'Content-Type':'application/json',...headers},...(!read?{body:JSON.stringify(fields)}:{})});
  const text=await response.text();let data=null;if(text){try{data=JSON.parse(text);}catch{throw Error(text+'\n'+logs.slice(-2000));}}
  return {response,data,text};
}
const csrf={csrf_token:'fixture-owner-csrf'};
const mutations={save_profile:{username:'changed'},save_profile_media:{media_type:'cover'},save_profile_agent:{profile_agent_id:7,profile_agent_enabled:1},save_profile_access:{resource_type:'knowledge',profile_agent_allowed:1},attention_action:{attention_id:1,attention_action:'ignored'},conversation_status:{conversation_id:101,status:'resolved'},owner_reply:{conversation_id:101,message:'Should never be sent'}};
try{
  let ready=false;for(let i=0;i<60;i++){try{await state();ready=true;break;}catch{await new Promise(r=>setTimeout(r,50));}}assert.ok(ready,logs);
  const initial=await state();
  for(const [action,args] of Object.entries(mutations)){
    for(const method of ['GET','HEAD','PUT','DELETE']){
      const r=await request(method,{action,...args,...csrf},{},{action,...args,...csrf});
      check(r.response.status===405&&r.response.headers.get('allow')?.includes('POST'),`${method} cannot execute ${action}, including a supplied token`);
    }
    for(const token of [undefined,'wrong-token',[]]){
      const r=await request('POST',{action,...args,...(token!==undefined?{csrf_token:token}:{})});
      check(r.response.status===419&&r.data.ok===false,`${action} requires a valid scalar CSRF token in its POST body`);
    }
  }
  let r=await request('POST',{action:'owner_reply',...mutations.owner_reply},{},csrf);
  check(r.response.status===419,'query-string CSRF cannot authorize a POST body');
  for(const [headers,status] of [[{'X-Fixture-User':'0'},401],[{'X-Fixture-Account-Denied':'1'},401],[{'X-Fixture-Profile-Denied':'1'},403]]){
    r=await request('POST',{action:'owner_reply',...mutations.owner_reply,...csrf},headers);
    check(r.response.status===status,'owner action requires current account and Profile access');
  }
  check(JSON.stringify(await state())===JSON.stringify(initial),'all denied methods, tokens and permissions leave stored messages, status, attention and settings unchanged');
  r=await request('GET',{action:'conversation_messages',conversation_id:101});
  check(r.response.status===200&&r.data.conversation.id==101&&r.data.messages[0].message==='Visitor one message','authorized GET reads its exact owned thread without a mutation token');
  r=await request('GET',{action:'conversation_messages',conversation_id:201});
  check(r.response.status===404&&!r.text.includes('SECRET'),'owner cannot read another owner’s conversation');
  for(const action of ['owner_reply','conversation_status']){
    r=await request('POST',{action,...mutations[action],conversation_id:201,...csrf});
    check(r.response.status===404&&!r.text.includes('SECRET'),`${action} rejects a foreign conversation under the real row boundary`);
  }
  r=await request('POST',{action:'owner_reply',conversation_id:102,message:'Owner historical reply',...csrf});
  let saved=await state();
  check(r.response.status===200&&r.data.conversation_id===102&&r.data.messages.at(-1).message==='Owner historical reply','successful reply acknowledges the exact historical owned thread');
  check(saved.profile_agent_messages.filter(m=>m.sender_type==='owner').length===1&&saved.profile_agent_messages.at(-1).conversation_id==102&&saved.profile_agent_conversations.find(c=>c.id==102).status==='owner_joined','owner reply stores once in the selected thread and takes over that thread');
  const sentEvent=saved.profile_events[0],sentMetadata=sentEvent&&JSON.parse(sentEvent.metadata_json);
  check(saved.profile_events.length===1&&sentEvent.owner_user_id==1&&sentEvent.profile_session_id==12&&sentEvent.profile_agent_id==8&&sentEvent.visitor_user_id==4&&sentEvent.event_type==='message_sent'&&sentMetadata.conversation_id===102&&sentMetadata.message_id==saved.profile_agent_messages.at(-1).id&&sentMetadata.sender_type==='owner'&&sentMetadata.sender_user_id===1&&!('source_authority' in sentMetadata),'historical owner reply records its exact canonical actor and message without inventing visitor authority');
  check(saved.agent_attention_items.find(a=>a.id==2).status==='handled'&&saved.agent_attention_items.find(a=>a.id==1).status==='pending'&&saved.agent_attention_items.find(a=>a.id==3).status==='pending','owner reply handles only that thread’s attention');
  r=await request('POST',{action:'conversation_status',conversation_id:101,status:'resolved',...csrf});
  saved=await state();
  check(r.response.status===200&&r.data.conversation_id===101&&saved.profile_agent_conversations.find(c=>c.id==101).status==='resolved','valid status POST acknowledges and updates the exact owned thread');
  check(saved.profile_agent_conversations.find(c=>c.id==201).status==='open'&&saved.profile_agent_messages.find(m=>m.conversation_id==201).message==='SECRET foreign message','successful owner actions preserve another owner’s records');
  for(const args of [{action:'owner_reply',conversation_id:101,message:''},{action:'owner_reply',conversation_id:101,message:'a'.repeat(4001)},{action:'conversation_status',conversation_id:101,status:'bad'},{action:'save_profile',username:'invalid'},{action:['owner_reply']}]){
    r=await request('POST',{...args,...csrf});check(r.response.status===422&&r.data.code==='VALIDATION_FAILED','reviewed invalid input retains a safe actionable validation response');
  }
  const beforeFailure=await state();
  for(const fault of ['insert','after_insert','event_insert']){
    r=await request('POST',{action:'owner_reply',conversation_id:101,message:'Uncommitted reply',...csrf},{'X-Fixture-Fault':fault});
    check(r.response.status===500&&r.data.code==='PROFILE_AGENT_REQUEST_FAILED'&&!/SECRET|SQLSTATE|private\/|config\.php/.test(r.text),'database '+fault+' failure exposes no server diagnostic');
    check(JSON.stringify(await state())===JSON.stringify(beforeFailure),'database '+fault+' failure rolls back the full owner reply transaction');
  }
  for(const fault of ['runtime','sql_validation_text','connect']){
    r=await request('GET',{action:'owner_state'},{'X-Fixture-Fault':fault});
    check(r.response.status===500&&r.data.code==='PROFILE_AGENT_REQUEST_FAILED'&&!r.text.includes('SECRET'),'unexpected '+fault+' failure cannot become public validation or successful empty state');
  }
  r=await request('GET',{action:'state',username:'owner'},{'X-Fixture-User':'0','X-Fixture-Fault':'public_sql'});
  check(r.response.status===500&&r.data.code==='PROFILE_AGENT_REQUEST_FAILED'&&!/SECRET|SQLSTATE|column|sqlite|mysql|\.php/i.test(r.text),'anonymous public failure redacts real SQL diagnostics');
  r=await request('GET',{action:'owner_state'},{'X-Fixture-Fault':'offline'});
  check(r.response.status===503&&r.data.ok===false,'unavailable database is an explicit unsuccessful service state');
  const form=new FormData();form.set('action','save_profile_media');form.set('media_type','cover');form.set('csrf_token',csrf.csrf_token);
  const upload=await fetch(base+'/api/profile-agent.php',{method:'POST',body:form});
  const uploadData=await upload.json();check(upload.status===422&&uploadData.error==='Choose a JPG, PNG or WEBP image to upload.','multipart image form still reaches validation with its body CSRF token');
  r=await request('POST',{action:'save_profile',username:'valid',...csrf});
  check(r.response.status===200&&(await state()).fixture_owner_mutations.length===1,'valid authorized profile-setting POST still reaches its canonical writer');
  console.log(`PROFILE_OWNER_API_PA1=PASS driver=${driver} checks=${checks}`);
}catch(error){console.error(logs.slice(-3500));throw error;}finally{
  if(child.exitCode===null&&child.signalCode===null){const stopped=new Promise(resolve=>child.once('exit',resolve));child.kill();await stopped;}
  const cleanup=spawnSync(php,[fixture,'cleanup',config],{encoding:'utf8'});assert.equal(cleanup.status,0,cleanup.stderr);
  fs.rmSync(tmp,{recursive:true,force:true});
}
