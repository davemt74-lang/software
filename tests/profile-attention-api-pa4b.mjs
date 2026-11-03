import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn,spawnSync} from 'node:child_process';

const root=process.cwd(),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-attention-api-')),php=process.env.VP3_PHP_BINARY||'php',config=path.join(tmp,'fixture.json');
const fixture=path.resolve('tests/fixtures/profile-attention-api-pa4b-db.php');
const invoke=(action,input={})=>{const r=spawnSync(php,[fixture,action,config,JSON.stringify(input)],{encoding:'utf8'});assert.equal(r.status,0,r.stderr||r.stdout);return r.stdout;};
let init=JSON.parse(invoke('init')),now=init.now,seq=10,checks=0,logs='';
for(const name of ['includes','api','sessions'])fs.mkdirSync(path.join(tmp,name));
for(const name of fs.readdirSync('includes'))if(name!=='bootstrap.php')fs.symlinkSync(path.resolve('includes',name),path.join(tmp,'includes',name));
for(const name of ['chat-notifications-brain-v240.php','profile-agent.php','member-notifications.php'])fs.copyFileSync('api/'+name,path.join(tmp,'api',name));
fs.copyFileSync('tests/fixtures/profile-attention-api-pa4b-bootstrap.php',path.join(tmp,'includes/bootstrap.php'));
fs.copyFileSync('notifications.php',path.join(tmp,'notifications.php'));
// Unrelated navigation chrome is omitted; the actual page, forms and source
// services execute unchanged against the real SQL/native authority fixture.
for(const name of ['workspace-sidebar-v82.php','member-header.php']){fs.unlinkSync(path.join(tmp,'includes',name));fs.writeFileSync(path.join(tmp,'includes',name),name==='member-header.php'?'<?php echo $memberHeaderActions ?? "";':'<?php /* navigation fixture */');}
fs.writeFileSync(path.join(tmp,'health.php'),'<?php echo "ready";');
const reservation=net.createServer();await new Promise(r=>reservation.listen(0,'127.0.0.1',r));const port=reservation.address().port;await new Promise(r=>reservation.close(r));
const child=spawn(php,['-d','display_errors=0','-d','session.save_path='+path.join(tmp,'sessions'),'-S',`127.0.0.1:${port}`,'-t',tmp],{env:{...process.env,VP3_PA3_ROOT:root,VP3_PA3_CONFIG:config},stdio:['ignore','ignore','pipe']});child.stderr.on('data',v=>logs+=v);
const base=`http://127.0.0.1:${port}`,key=()=> (++seq).toString(16).padStart(32,'0');
const scope={conversation_id:11,agent_id:7},csrf='pa3-fixture-csrf';
const check=(ok,label)=>{assert.ok(ok,label);checks++;};
const snapshot=()=>JSON.parse(invoke('snapshot'));
const wire=(item,extra={})=>({...scope,notification_id:item.notification_id,attention_id:item.attention_id,state_revision:item.state_revision,wake_generation:item.wake_generation,request_id:key(),csrf_token:csrf,...extra});
const reset=()=>{init=JSON.parse(invoke('init'));now=init.now;return init.item;};
async function request(method,fields,user=1,failure='',endpoint='chat-notifications-brain-v240.php'){
 const read=['GET','HEAD'].includes(method),r=await fetch(base+'/api/'+endpoint+(read?'?'+new URLSearchParams(fields):''),{method,headers:{'Content-Type':'application/json','X-Fixture-User':String(user),'X-Fixture-Now':String(now),'X-Fixture-Failure':failure,Cookie:`PHPSESSID=attention${user}; sf_profile_conversation=${'b'.repeat(64)}; sf_profile_visitor=${'a'.repeat(64)}`},...(!read?{body:JSON.stringify(fields)}:{})});
 const raw=await r.text();let data=null;if(raw)try{data=JSON.parse(raw);}catch{throw Error(raw+'\n'+logs.slice(-3000));}return {status:r.status,data,raw};
}
async function page(method='GET',fields={}){
 const r=await fetch(base+'/notifications.php',{method,redirect:'manual',headers:{'X-Fixture-User':'1','X-Fixture-Now':String(now),Cookie:'PHPSESSID=attention1',...(method==='POST'?{'Content-Type':'application/x-www-form-urlencoded'}:{})},...(method==='POST'?{body:new URLSearchParams(fields)}:{})});return {status:r.status,text:await r.text(),location:r.headers.get('location')};
}
try{
 let started=false;for(let i=0;i<80;i++){try{if((await fetch(base+'/health.php')).status===200){started=true;break;}}catch{}await new Promise(r=>setTimeout(r,25));}assert.ok(started,logs);
 let item=reset(),before=snapshot();
 for(const action of ['profile_attention_present','profile_attention_ack','profile_attention_update'])for(const method of ['GET','HEAD']){const r=await request(method,{action,...wire(item)});check(r.status===405,method+' cannot mutate '+action);}
 for(const action of ['profile_attention_present','profile_attention_ack','profile_attention_update']){const r=await request('POST',{action,...wire(item,{csrf_token:'wrong'})});check(r.status===419,'CSRF protects '+action);}
 let r=await request('GET',{action:'profile_attention_state',...scope},0);check(r.status===401,'anonymous user cannot read owner attention');
 r=await request('GET',{action:'profile_attention_state',...scope},4);check(r.status===403,'foreign owner cannot select another private Chat');
 r=await request('POST',{action:'profile_attention_present',...wire(item,{conversation_id:13,agent_id:9})});check(r.status===403,'foreign private target is rejected');
 r=await request('POST',{action:'profile_attention_present',...wire(item,{conversation_id:11,agent_id:8})});check(r.status===403,'mismatched current Agent is rejected');
 check(JSON.stringify(snapshot())===JSON.stringify(before),'all denied requests leave durable evidence unchanged');
 r=await request('GET',{action:'profile_attention_state',...scope});check(r.status===200&&r.data.complete&&r.data.items.some(i=>i.attention_id===item.attention_id)&&r.data.voice_allowed===false,'GET returns exact typed current source without voice permission');
 check(r.data.items.some(i=>i.attention_id===item.attention_id&&i.display_text==='PUBLIC_VISITOR_STATEMENT')&&!/"(?:credential_hash|source_authority)"\s*:|PRIVATE_CHAT_SECRET|FOREIGN_CHAT_SECRET/.test(r.raw),'read projects public source and omits private evidence/secrets');
 check(JSON.stringify(snapshot())===JSON.stringify(before),'real HTTP GET is pure');
 const present={action:'profile_attention_present',...wire(item)};r=await request('POST',present);check(r.status===200&&r.data.item.delivery_location==='current'&&!r.data.item.read&&!r.data.item.presented,'POST creates current marker without marking read or visible');let result=r.data;item=result.item;
 const saved=snapshot();check(saved.chat_messages.find(m=>Number(m.id)===result.chat_message_id).message==='Profile attention update.','actual private Chat marker excludes public statement body');
 r=await request('POST',present);check(r.status===200&&r.data.duplicate&&r.data.chat_message_id===result.chat_message_id,'HTTP response loss recovers same delivery');
 r=await request('POST',{action:'profile_attention_present',...wire(item,{conversation_id:12,agent_id:8})});check(r.status===200&&r.data.item.delivery_location==='elsewhere'&&r.data.chat_message_id===result.chat_message_id,'second current Chat gets elsewhere without target switch');
 const ack={action:'profile_attention_ack',...wire(item,{delivery_id:result.delivery_id,chat_message_id:result.chat_message_id,visible:true,tab_id:'a'.repeat(32),client_instance_id:'c'.repeat(32)})};
 r=await request('POST',{...ack,chat_message_id:99999});check(r.status===409,'wrong marker cannot acknowledge delivery');
 r=await request('POST',ack);check(r.status===200&&r.data.item.presented&&!r.data.item.read,'actual visible ACK is independent of read');item=r.data.item;
 r=await request('POST',{action:'profile_attention_update',...wire(item,{operation:'read'})});check(r.status===200&&r.data.item.read&&r.data.item.disposition==='pending','explicit HTTP read leaves disposition pending');item=r.data.item;
 r=await request('POST',{action:'profile_attention_update',...wire(item,{operation:'snooze'})});check(r.status===200&&r.data.item.disposition==='snoozed','snooze is a separate disposition');item=r.data.item;
 now+=86400001;before=snapshot();r=await request('GET',{action:'profile_attention_state',...scope,after_id:999999});check(r.status===200&&r.data.items[0].wake_due&&r.data.items[0].eligible,'past snooze remains discoverable behind old cursor');
 check(JSON.stringify(snapshot())===JSON.stringify(before),'expired snooze GET does not mutate generations');invoke('wake',{now});
 r=await request('POST',ack);check(r.status===200&&r.data.duplicate&&!r.data.item.presented&&r.data.item.wake_generation===2,'old ACK replay reads current generation without acknowledging it');item=r.data.item;
 r=await request('POST',{action:'profile_attention_update',...wire(item,{operation:'handled'})});check(r.status===200&&r.data.item.disposition==='handled'&&!r.data.item.read,'handled does not imply read');item=r.data.item;
 r=await request('POST',{action:'profile_attention_present',...wire(item)});check(r.status===409,'terminal attention cannot replay into Chat');
 r=await request('GET',{action:'profile_attention_item',...scope,delivery_id:result.delivery_id});check(r.status===200&&!r.data.item.eligible&&r.data.voice_allowed===false,'reload resolves exact marker with terminal state and no speech');
 r=await request('GET',{action:'profile_attention_item',conversation_id:12,agent_id:8,delivery_id:result.delivery_id});check(r.status===404,'stored marker cannot be restored into another selected Chat');

 item=reset();for(const endpoint of ['chat-notifications-brain-v240.php','profile-agent.php']){
  const legacy=endpoint==='profile-agent.php'?{action:'attention_action',attention_action:'seen'}:{action:'mark_read'};
  r=await request('POST',{...legacy,csrf_token:csrf,notification_id:item.notification_id,attention_id:item.attention_id},1,'',endpoint);check(r.status===422,'legacy '+endpoint+' cannot omit revision/generation receipt');
 }
 r=await request('POST',{action:'present_attention',notification_id:item.notification_id,csrf_token:csrf,...scope});check(r.status===422,'old presenter cannot bypass source lifecycle');
 r=await request('POST',{action:'attention_action',attention_action:'seen',...wire(item,{conversation_id:0,agent_id:0})},1,'','profile-agent.php');check(r.status===200&&r.data.item.read,'old portal mutation uses same revision-aware lifecycle');
 item=reset();before=snapshot();r=await request('POST',{action:'profile_attention_present',...wire(item)},1,'receipt');check(r.status===503&&!r.data.complete&&r.data.counts===null&&!/SECRET|SQLSTATE/.test(r.raw),'SQL write failure is unavailable without diagnostics');check(JSON.stringify(snapshot())===JSON.stringify(before),'failed operation receipt rolls back actual HTTP marker writes');
 r=await request('GET',{action:'profile_attention_state',...scope},1,'count');check(r.status===503&&r.data.counts===null&&!r.data.complete&&!/SECRET|SQLSTATE/.test(r.raw),'failed count cannot appear caught up');
 r=await request('POST',{action:'profile_attention_present',...wire(item)},1,'late_agent');check(r.status===403&&snapshot().profile_attention_deliveries_pa4b.length===0,'late Agent revocation publishes no marker');
 item=reset();invoke('orphan');r=await request('GET',{action:'profile_attention_state',...scope});check(r.status===200&&!r.data.complete&&r.data.counts===null&&r.data.errors.length===1&&!r.raw.includes('SECRET_ORPHAN'),'orphan notification is explicit unavailable state');
 item=reset();invoke('revoke');r=await request('GET',{action:'profile_attention_state',...scope});check(r.status===200&&r.data.items[0].source_status==='unavailable'&&!r.raw.includes('PUBLIC_VISITOR_STATEMENT'),'revoked source body stays private');
 r=await request('POST',{action:'profile_attention_update',...wire(r.data.items[0],{operation:'ignored'})});check(r.status===200&&r.data.item.disposition==='ignored','owner can dismiss unavailable evidence without revival');
 item=reset();invoke('no_chat');r=await request('GET',{action:'profile_attention_state',conversation_id:0,agent_id:0});check(r.status===200&&r.data.items.some(i=>i.attention_id===item.attention_id),'Profile portal list does not require private Chat access');
 r=await request('GET',{action:'profile_attention_state',...scope});check(r.status===403,'positive private scope still requires current Chat permission');
 item=reset();invoke('partial');r=await request('GET',{action:'profile_attention_state',...scope});check(r.status===503&&r.data.code==='PROFILE_ATTENTION_NOT_READY'&&r.data.counts===null,'partial migration is not an empty successful inbox');invoke('restore_partial');
 item=reset();invoke('undisclose');r=await request('GET',{},1,'','member-notifications.php');check(r.status===200&&!r.raw.includes('Visitor A')&&!r.raw.includes('SAVED_UNTRUSTED_SUMMARY')&&r.raw.includes('PUBLIC_VISITOR_STATEMENT'),'actual header API uses current visitor disclosure and exact saved statement');
 let html=await page();check(html.status===200&&!html.text.includes('Visitor A')&&html.text.includes('name="state_revision"')&&html.text.includes('name="wake_generation"')&&html.text.includes('Mark other notifications read'),'actual standalone page uses current source and captured per-row controls; HTTP '+html.status);
 const form=html.text.match(new RegExp('<form[^>]*>[\\s\\S]*?<input type="hidden" name="notification_id" value="'+item.notification_id+'">[\\s\\S]*?</form>'))?.[0];assert.ok(form,html.text.slice(-4000));
 const values={};for(const match of form.matchAll(/<input type="hidden" name="([^"]+)" value="([^"]*)">/g))values[match[1]]=match[2];
 let posted=await page('POST',values);check(posted.status===302&&snapshot().agent_attention_items.find(a=>Number(a.id)===item.attention_id).read_generation==1,'standalone Read submits the exact captured generation');
 item=reset();const stale=wire(item,{operation:'snooze'});r=await request('POST',{action:'profile_attention_update',...stale});now+=86400001;invoke('wake',{now});before=snapshot();posted=await page('POST',{action:'profile_read',...wire(item)});check(posted.status===302&&JSON.stringify(snapshot())===JSON.stringify(before),'stale standalone form cannot acknowledge a newer wake');
 posted=await page('POST',{action:'all_read',csrf_token:csrf});check(posted.status===302&&snapshot().agent_attention_items.find(a=>Number(a.id)===item.attention_id).read_generation==0,'standalone bulk action leaves Profile generation unread');html=await page();check(html.text.includes('Other notifications marked as read')&&!html.text.includes('Notifications marked as read.'),'standalone bulk confirmation describes its actual non-Profile scope');
 item=reset();const unbound=Number(invoke('unbound'));before=snapshot();r=await request('POST',{action:'present_attention',notification_id:unbound,csrf_token:csrf,...scope});check(r.status===200&&!r.data.handled&&r.data.voice_allowed===false&&JSON.stringify(snapshot())===JSON.stringify(before),'unbound historical Profile type grants neither a private message nor speech');
 r=await request('GET',{},1,'','member-notifications.php');check(r.status===200&&!/UNBOUND_NAME|UNBOUND_PUBLIC_INSTRUCTION|private-target/.test(r.raw),'actual generic header neutralizes unproved legacy labels and content');
 now=JSON.parse(invoke('backlog')).now;before=snapshot();const paged=[];
 for(let page=0;page<3;page++){r=await request('GET',{action:'profile_attention_state',...scope,page});check(r.status===200&&r.data.page===page&&r.data.page_size===25&&r.data.items.length===(page===2?15:25)&&r.data.has_more===(page<2)&&r.data.counts.unread===65,'real HTTP Profile page '+page+' has bounded records and all-owner counts');paged.push(...r.data.items);}
 check(new Set(paged.map(i=>i.notification_id)).size===65&&JSON.stringify(snapshot())===JSON.stringify(before),'HTTP navigation reaches all 65 exact Profile sources without any presentation or read mutation');
 r=await request('GET',{action:'profile_attention_state',...scope,page:100001});check(r.status===422&&!r.data.complete&&r.data.counts===null,'HTTP paging rejects an out-of-bound offset without empty-success projection');
 console.log(`PROFILE_ATTENTION_API_PA4B=PASS (${checks} checks; ${init.driver})`);
}finally{child.kill('SIGTERM');await new Promise(r=>child.once('exit',r));invoke('cleanup');fs.rmSync(tmp,{recursive:true,force:true});}
