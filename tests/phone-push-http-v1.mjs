import assert from 'node:assert/strict';
import {execFileSync,spawn} from 'node:child_process';
import {randomBytes,randomUUID} from 'node:crypto';
import {mkdtempSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {setTimeout as delay} from 'node:timers/promises';
import {fileURLToPath} from 'node:url';
import {localPort,preparePhoneSite} from './fixtures/phone-site-v1.mjs';

// Real shipped PHP endpoints, isolated users/sessions/SQLite/master key, and an
// injected push transport. No browser, OS permission or provider is used.
const repo=fileURLToPath(new URL('..',import.meta.url));
const root=mkdtempSync(path.join(tmpdir(),'vp3-phone-push-http-')),site=path.join(root,'web'),sessions=path.join(root,'sessions');
const php=process.env.VP3_PHP_BINARY||process.env.PHP_BINARY||'php';
const holder='/api/phone-location-v1.php',receiptPath='/api/phone-push-receipt-v1.php',tracking='/api/phone-tracking-v1.php';
let origin,server,serverErrors='',checks=0;
const fixture=(action,input={})=>JSON.parse(execFileSync(php,['-d','session.save_path='+sessions,path.join(repo,'tests/phone-push-http-fixture-v1.php'),site,action,JSON.stringify(input)],{encoding:'utf8'}));
async function check(name,fn){await fn();checks++;console.log('PASS '+name);}
async function send(endpoint,body,{cookie='',headers={},method='POST',raw}={}) {
  const defaults={'Content-Type':'application/json','X-VP3-Phone':'1',Origin:origin,'Sec-Fetch-Site':'same-origin'};
  if(cookie)defaults.Cookie=cookie;
  for(const [key,value] of Object.entries(headers)){if(value===null)delete defaults[key];else defaults[key]=value;}
  const response=await fetch(origin+endpoint,{method,headers:defaults,body:method==='GET'?undefined:raw??JSON.stringify(body),redirect:'error'});
  assert.match(response.headers.get('content-type')||'',/application\/json/,'Phone endpoint must return JSON');
  const result=await response.json();return {status:response.status,headers:response.headers,result};
}
async function ok(endpoint,body,options={}){const response=await send(endpoint,body,options);assert.equal(response.status,200,endpoint+' '+(body.action||'receipt')+' status');assert.equal(response.result.ok,true);return response;}
const ownerOptions=user=>({cookie:'PHPSESSID='+user.session,headers:{'X-VP3-Working-Attention':user.binding,'X-CSRF-Token':user.csrf}});
const ownerCall=(user,action,fields={})=>ok(tracking,{action,csrf_token:user.csrf,request_id:randomUUID(),...fields},ownerOptions(user));
const phoneCall=(phone,action,fields={})=>ok(holder,{action,device_id:phone.id,...fields},{cookie:phone.cookie});
async function pair(user,name,cid){
  const invite=(await ownerCall(user,'invite')).result;
  const claim=await ok(holder,{action:'claim',invite:invite.invite,name});
  const cookies=claim.headers.getSetCookie?claim.headers.getSetCookie():[claim.headers.get('set-cookie')||''];
  const credential=cookies.find(value=>value.startsWith('vp3_phone_device_v1='));
  assert.ok(credential);assert.match(credential,/HttpOnly/i);assert.match(credential,/SameSite=Strict/i);assert.match(credential,/path=\/api\/phone-location-v1\.php/i);
  assert.equal(Object.hasOwn(claim.result,'token'),false,'holder credential is only an HttpOnly cookie');
  const phone={id:claim.result.device.id,cookie:credential.split(';')[0]};
  const approved=(await ownerCall(user,'approve',{device_id:phone.id,generation:claim.result.device.generation,allow_location:false,chat_origin:{agent_id:0,conversation_id:cid}})).result;
  assert.equal(approved.device.status,'approved');return phone;
}
const display=payload=>({event_ref:payload.event_ref,receipt_token:payload.receipt_token,kind:'displayed'});
const noPrivateProjection=value=>{
  assert.equal(Object.hasOwn(value,'source'),false,'holder receipt exposes only a fixed target, never the internal source');
  const text=JSON.stringify(value);assert.doesNotMatch(text,/receipt_token|private_cipher|subscription_cipher|credential_hash|source_type|source_id|web\.push\.apple|Private holder|Private owner/);
};

try {
  const [owner,other]=preparePhoneSite(repo,site,sessions,php);
  const config=fixture('setup');
  const port=await localPort();origin='http://127.0.0.1:'+port;
  server=spawn(php,['-d','session.save_path='+sessions,'-d','display_errors=0','-S','127.0.0.1:'+port,'-t',site],{stdio:['ignore','ignore','pipe']});
  server.stderr.on('data',data=>{for(const line of data.toString().split('\n'))if(/Fatal error|Uncaught |Parse error/.test(line))serverErrors+=line+'\n';});
  for(let tries=0;tries<50;tries++){
    if(await fetch(origin+'/health.txt').then(response=>response.ok).catch(()=>false))break;
    if(tries===49)throw Error('Phone push HTTP fixture did not start');await delay(100);
  }
  const a=await pair(owner,'Primary installation',8),b=await pair(owner,'Sibling installation',8),c=await pair(other,'Other owner installation',10);
  await check('real pairing keeps holder cookies separate from owner web sessions',async()=>{
    for(const phone of [a,b,c])assert.equal((await phoneCall(phone,'status')).result.device.status,'approved');
    assert.equal((await send(holder,{action:'status'},ownerOptions(owner))).status,401);
  });
  await check('isolated push subscriptions enroll through the real holder HTTP action',async()=>{
    for(const phone of [a,b,c]){
      const subscription={endpoint:'https://web.push.apple.com/Qhttp_fixture_'+phone.id,expirationTime:null,keys:{p256dh:config.public_key,auth:randomBytes(16).toString('base64url')}};
      const result=(await phoneCall(phone,'push_register',{subscription_revision:0,confirmed:true,key_id:config.key_id,subscription})).result;
      assert.equal(result.push.state,'registered');assert.equal(result.push.revision,1);assert.equal(result.push.delivery.ready,true);
    }
  });
  const intent={action:'push_test',device_id:a.id,subscription_revision:1,confirmed:true,request_id:randomBytes(16).toString('hex')};
  let event,payload;
  await check('test enrollment requires holder authority exact scope confirmation and revision',async()=>{
    assert.equal((await send(holder,intent)).status,401);
    assert.equal((await send(holder,intent,ownerOptions(owner))).status,401);
    assert.equal((await send(holder,intent,{cookie:b.cookie})).status,403);
    assert.equal((await send(holder,{...intent,confirmed:false},{cookie:a.cookie})).status,422);
    assert.equal((await send(holder,{...intent,subscription_revision:0},{cookie:a.cookie})).status,409);
    assert.equal((await send(holder,{...intent,url:'/private'},{cookie:a.cookie})).status,422);
  });
  await check('a test request queues once and never claims display or exposes its capability',async()=>{
    event=(await ok(holder,intent,{cookie:a.cookie})).result;
    assert.match(event.event_ref,/^[a-f0-9]{32}$/);assert.equal(event.state,'queued');assert.equal(event.client_observed_epoch,0);assert.equal(event.opened_epoch,0);noPrivateProjection(event);
    assert.equal((await ok(holder,intent,{cookie:a.cookie})).result.event_ref,event.event_ref);
    assert.equal(fixture('snapshot',{event_ref:event.event_ref}).count,1);
    assert.equal((await send(holder,{action:'push_resolve',device_id:a.id,event_ref:event.event_ref},{cookie:a.cookie})).status,404,'a queued event not yet dispatched cannot claim an open');
  });
  await check('synthetic worker dispatch produces only an opaque event and display capability',async()=>{
    const dispatched=fixture('dispatch');assert.equal(dispatched.run.attempted,1);assert.equal(dispatched.payloads.length,1);payload=dispatched.payloads[0];
    assert.deepEqual(Object.keys(payload),['v','event_ref','receipt_token']);assert.equal(payload.v,1);assert.equal(payload.event_ref,event.event_ref);assert.match(payload.receipt_token,/^[A-Za-z0-9_-]{43}$/);
    const snapshot=fixture('snapshot',{event_ref:event.event_ref}).event;assert.equal(snapshot.state,'accepted');assert.ok(snapshot.provider_accepted_epoch>0);assert.equal(snapshot.client_observed_epoch,0);assert.equal(snapshot.opened_epoch,0);
  });
  await check('receipt endpoint rejects wrong method origin fetch-site and missing custom header',async()=>{
    const get=await send(receiptPath+'?event_ref='+event.event_ref,undefined,{method:'GET'});assert.equal(get.status,405);assert.equal(get.headers.get('allow'),'POST');
    for(const headers of [{'X-VP3-Phone':null},{'X-VP3-Phone':'0'},{Origin:'https://sibling.example.test'},{Origin:'null'},{'Sec-Fetch-Site':'cross-site'},{'Sec-Fetch-Site':'same-site'}])assert.equal((await send(receiptPath,display(payload),{headers})).status,403);
  });
  await check('receipt input remains bounded JSON and excludes actions or source projections',async()=>{
    assert.equal((await send(receiptPath,display(payload),{headers:{'Content-Type':'text/plain'}})).status,415);
    assert.equal((await send(receiptPath,{}, {raw:'{"padding":"'+'x'.repeat(4096)+'"}'})).status,413);
    assert.equal((await send(receiptPath,{}, {raw:'[]'})).status,422);
    assert.equal((await send(receiptPath,{}, {raw:'{broken'})).status,422);
    for(const kind of ['opened','client_observed','resolve','read'])assert.equal((await send(receiptPath,{...display(payload),kind})).status,422);
    for(const extra of [{action:'push_resolve'},{device_id:a.id},{target:{tab:'chat'}},{url:'/private'}])assert.equal((await send(receiptPath,{...display(payload),...extra})).status,422);
  });
  await check('wrong display capabilities cannot update any receipt',async()=>{
    assert.equal((await send(receiptPath,{...display(payload),receipt_token:randomBytes(32).toString('base64url')})).status,404);
    assert.equal((await send(receiptPath,{...display(payload),receipt_token:'bad'})).status,422);
    assert.equal((await send(receiptPath,{...display(payload),event_ref:randomBytes(16).toString('hex')})).status,404);
    const snapshot=fixture('snapshot',{event_ref:event.event_ref}).event;assert.equal(snapshot.client_observed_epoch,0);assert.equal(snapshot.opened_epoch,0);
  });
  await check('display capability is neither holder authentication nor private Chat access',async()=>{
    for(const body of [{action:'push_resolve',device_id:a.id,event_ref:event.event_ref},{action:'chat_status',device_id:a.id}]){
      assert.equal((await send(holder,{...body,receipt_token:payload.receipt_token})).status,401);
      assert.equal((await send(holder,body,{cookie:'vp3_phone_device_v1='+payload.receipt_token})).status,403);
    }
    assert.equal(fixture('snapshot',{event_ref:event.event_ref}).event.opened_epoch,0);
  });
  await check('successful unauthenticated receipt only records display and returns no source',async()=>{
    const response=await ok(receiptPath,display(payload));assert.deepEqual(response.result,{ok:true});
    assert.match(response.headers.get('cache-control')||'',/no-store/);assert.equal(response.headers.get('referrer-policy'),'no-referrer');assert.equal(response.headers.get('x-content-type-options'),'nosniff');assert.equal(response.headers.get('access-control-allow-origin'),null);assert.match(response.headers.get('permissions-policy')||'',/camera=\(\).*microphone=\(\).*geolocation=\(\)/);
    const before=fixture('snapshot',{event_ref:event.event_ref}).event;assert.ok(before.client_observed_epoch>0);assert.equal(before.opened_epoch,0);
    await ok(receiptPath,display(payload));assert.equal(fixture('snapshot',{event_ref:event.event_ref}).event.client_observed_epoch,before.client_observed_epoch,'repeat receipt preserves its original timestamp');
  });
  await check('authenticated resolution is restricted to the exact installation and fixed Info target',async()=>{
    for(const phone of [b,c])assert.equal((await send(holder,{action:'push_resolve',device_id:phone.id,event_ref:event.event_ref},{cookie:phone.cookie})).status,404);
    assert.equal((await send(holder,{action:'push_resolve',device_id:a.id,event_ref:event.event_ref},{cookie:b.cookie})).status,403);
    const opened=(await phoneCall(a,'push_resolve',{event_ref:event.event_ref})).result;assert.deepEqual(opened.target,{tab:'info'});assert.ok(opened.opened_epoch>0);noPrivateProjection(opened);
  });
  const requestId=randomUUID();let privatePayload;
  await check('a real owner reply queues a private source without embedding its text in push',async()=>{
    await phoneCall(a,'chat_send',{request_id:requestId,message:'Private holder HTTP question'});
    await ok('/api/phone-chat-reply-v1.php',{action:'reply',agent_id:0,conversation_id:8,device_id:a.id,request_id:requestId,message:'Private owner HTTP response'},ownerOptions(owner));
    const dispatched=fixture('dispatch');assert.equal(dispatched.payloads.length,1);privatePayload=dispatched.payloads[0];assert.deepEqual(Object.keys(privatePayload),['v','event_ref','receipt_token']);
    const shown=await ok(receiptPath,display(privatePayload));assert.deepEqual(shown.result,{ok:true});assert.equal(fixture('snapshot',{event_ref:privatePayload.event_ref}).event.opened_epoch,0);
  });
  await check('private reply resolution and focused Chat hydration retain the canonical phone boundary',async()=>{
    for(const phone of [b,c])assert.equal((await send(holder,{action:'push_resolve',device_id:phone.id,event_ref:privatePayload.event_ref},{cookie:phone.cookie})).status,404);
    const opened=(await phoneCall(a,'push_resolve',{event_ref:privatePayload.event_ref})).result;assert.deepEqual(opened.target,{tab:'chat',request_id:requestId});assert.ok(opened.opened_epoch>0);noPrivateProjection(opened);
    const privateTurn=(await phoneCall(a,'chat_status',{focus_request_id:requestId})).result.turns.find(turn=>turn.request_id===requestId);assert.equal(privateTurn.reply,'Private owner HTTP response');
    for(const phone of [b,c])assert.equal((await phoneCall(phone,'chat_status',{focus_request_id:requestId})).result.turns.some(turn=>turn.request_id===requestId),false);
  });
  await check('expired events and withdrawn subscriptions invalidate display and resolve capabilities',async()=>{
    fixture('expire',{event_ref:event.event_ref});assert.equal((await send(receiptPath,display(payload))).status,404);assert.equal((await send(holder,{action:'push_resolve',device_id:a.id,event_ref:event.event_ref},{cookie:a.cookie})).status,404);
    const off=(await phoneCall(a,'push_disable',{subscription_revision:1,confirmed:true})).result;assert.equal(off.push.state,'off');assert.equal(off.push.revision,2);
    assert.equal((await send(receiptPath,display(privatePayload))).status,404);assert.equal((await send(holder,{action:'push_resolve',device_id:a.id,event_ref:privatePayload.event_ref},{cookie:a.cookie})).status,404);
    const status=(await phoneCall(a,'status')).result;assert.equal(status.device.status,'approved');assert.equal(status.device.push.delivery.last_test,null);
  });
  assert.equal(serverErrors,'','shipped endpoints must not emit PHP failures');
  console.log('PHONE_PUSH_HTTP_V1=PASS ('+checks+' cases; real HTTP auth, narrow capabilities, canonical private source, no live provider)');
} finally {
  if(server&&server.exitCode===null){const closed=new Promise(resolve=>server.once('close',resolve));server.kill();await closed;}
  rmSync(root,{force:true,recursive:true});
}
