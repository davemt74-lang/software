import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import crypto from 'node:crypto';
import {spawn,spawnSync} from 'node:child_process';

const php=process.env.VP3_PHP_BINARY||'php';
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-pa2-webhook-'));
const config=path.join(tmp,'fixture.json');
const fixture=path.resolve('tests/fixtures/commerce-webhook-pa2-db.php');
function db(operation){const r=spawnSync(php,[fixture,operation,config],{encoding:'utf8'});assert.equal(r.status,0,r.stderr+r.stdout);return r.stdout?JSON.parse(r.stdout):null;}
const initial=db('init');
fs.cpSync('includes',path.join(tmp,'includes'),{recursive:true});
fs.mkdirSync(path.join(tmp,'tests/fixtures'),{recursive:true});
fs.copyFileSync('tests/fixtures/commerce-recovery-pa2.php',path.join(tmp,'tests/fixtures/commerce-recovery-pa2.php'));
fs.copyFileSync('tests/fixtures/commerce-webhook-pa2-bootstrap.php',path.join(tmp,'includes/bootstrap.php'));
fs.copyFileSync('commerce-payment-webhook.php',path.join(tmp,'commerce-payment-webhook.php'));
const reservation=net.createServer();await new Promise(r=>reservation.listen(0,'127.0.0.1',r));const port=reservation.address().port;await new Promise(r=>reservation.close(r));
const child=spawn(php,['-d','display_errors=0','-S',`127.0.0.1:${port}`,'-t',tmp],{env:{...process.env,VP3_PA2_WEBHOOK_CONFIG:config},stdio:['ignore','ignore','pipe']});
let logs='',checks=0;child.stderr.on('data',chunk=>logs+=chunk);
const base=`http://127.0.0.1:${port}/commerce-payment-webhook.php`;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const event=(f,id)=>({id,type:'checkout.session.completed',account:f.account,data:{object:{object:'checkout.session',id:f.session,payment_intent:f.payment,payment_status:'paid',amount_total:5000,currency:'usd',metadata:{vp3_commerce_order_id:String(f.id)}}}});
function signature(payload,timestamp=Math.floor(Date.now()/1000)){return `t=${timestamp},v1=${crypto.createHmac('sha256','fixture-stripe-webhook').update(`${timestamp}.${payload}`).digest('hex')}`;}
async function request(value,{method='POST',provider='stripe',headers={},signed=true}={}){
  const payload=typeof value==='string'?value:JSON.stringify(value);
  const r=await fetch(base+'?provider='+encodeURIComponent(provider),{method,headers:{'Content-Type':'application/json',...(signed?{'Stripe-Signature':signature(payload)}:{}),...headers},...(!['GET','HEAD'].includes(method)?{body:payload}:{})});
  const text=await r.text();let data=null;if(text){try{data=JSON.parse(text);}catch{throw Error(text+'\n'+logs.slice(-1800));}}return {r,text,data};
}
const normal=initial.orders.normal,early=initial.orders.early,late=initial.orders.late;
const paid=(state,f)=>state.agent_commerce_payments_v800.filter(x=>Number(x.order_id)===f.id);
try {
  let ready=false;for(let i=0;i<60;i++){try{await request('',{method:'GET'});ready=true;break;}catch{await new Promise(r=>setTimeout(r,50));}}assert.ok(ready,logs);
  const start=db('state');
  for(const method of ['GET','HEAD','PUT','DELETE']){const q=await request(event(normal,'evt_method'),{method});check(q.r.status===405&&q.r.headers.get('allow')==='POST',method+' cannot submit provider payment evidence');}
  let q=await request(event(normal,'evt_provider'),{provider:'unknown'});check(q.r.status===404,'unregistered provider is rejected');
  for(const headers of [{}, {'Stripe-Signature':'invalid'},{'Stripe-Signature':signature(JSON.stringify(event(normal,'evt_invalid')),1)}]) {
    q=await request(event(normal,'evt_invalid'),{signed:false,headers});check(q.r.status===400&&q.data.ok===false,'invalid, missing or expired signature is an explicit unsuccessful response');
  }
  q=await request('{invalid-json');check(q.r.status===400&&q.data.ok===false,'malformed signed JSON returns a safe client error');
  q=await request('x'.repeat(524289));check(q.r.status===413&&q.data.ok===false,'oversized provider payload is bounded and rejected');
  check(JSON.stringify(db('state'))===JSON.stringify(start),'all admission failures preserve every financial and recovery row');
  const legitimate=event(normal,'evt_normal');
  q=await request(legitimate,{headers:{'X-PA2-Provider-Failure':'1'}});
  check(q.r.status===500&&q.r.headers.get('retry-after')==='10'&&q.data.ok===false&&!/SECRET|credential|SQLSTATE/.test(q.text),'provider outage is retryable and redacts diagnostics');
  let state=db('state');check(paid(state,normal).length===0&&state.agent_commerce_webhook_events_v800.at(-1).status==='failed','failed request retains recovery evidence without claiming payment');
  for(const failure of ['payment','fulfillment']) {
    q=await request(legitimate,{headers:{'X-PA2-Sql-Failure':failure}});
    check(q.r.status===500&&q.data.ok===false&&!q.text.includes('SECRET'),failure+' write failure is safely unsuccessful');
    state=db('state');check(paid(state,normal).length===0&&state.agent_commerce_fulfillment_jobs_pa2.length===0,failure+' write failure rolls back the full financial transition');
  }
  q=await request(legitimate);check(q.r.status===200&&q.data.ok===true,'same authentic webhook recovers after database and provider failures');
  state=db('state');check(paid(state,normal).length===1&&state.agent_commerce_fulfillment_jobs_pa2.filter(x=>x.event_type!=='project_paid').length===1&&state.agent_commerce_fulfillment_jobs_pa2.filter(x=>x.event_type==='project_paid').length===1,'successful HTTP delivery records one payment with fulfillment and projection obligations');
  q=await request(legitimate,{headers:{'X-PA2-Provider-Failure':'1'}});check(q.r.status===200&&paid(db('state'),normal).length===1,'processed HTTP replay does not depend on provider availability');
  const conflict=structuredClone(legitimate);conflict.account='acct_other';q=await request(conflict);
  check(q.r.status===500&&q.data.ok===false&&paid(db('state'),normal).length===1,'conflicting authenticated event identity cannot overwrite the receipt');
  q=await request(event(early,'evt_early'));state=db('state');
  check(q.r.status===200&&state.agent_commerce_webhook_events_v800.find(x=>x.external_event_id==='evt_early').status==='pending'&&paid(state,early).length===0,'early webhook acknowledgement is backed by durable pending recovery');
  const recovery=db('publish_early');check(recovery.webhooks_processed>=1&&paid(db('state'),early).length===1,'scheduled recovery records early payment after the checkout becomes available');
  q=await request(event(late,'evt_late'));state=db('state');const order=state.agent_commerce_orders_v800.find(x=>Number(x.id)===late.id);
  check(q.r.status===200&&order.payment_status==='paid'&&order.order_status==='payment_review','late signed payment produces an accurate financial receipt and review state');
  const beforeRedirect=JSON.stringify(state);q=await request(event(late,'evt_redirect'),{method:'GET'});
  check(q.r.status===405&&JSON.stringify(db('state'))===beforeRedirect,'browser-style redirect parameters cannot establish payment');
  q=await request({id:'evt_ignored',type:'customer.updated',data:{object:{}}});check(q.r.status===200,'unsupported authentic provider event is harmlessly acknowledged');
  db('drop_recovery');q=await request(event(normal,'evt_no_storage'));
  check(q.r.status===503&&q.data.error==='commerce_schema_unavailable','missing recovery storage fails closed before acknowledging financial processing');
  check(!logs.includes('SECRET'),'endpoint server log excludes raw provider and SQL diagnostics');
  console.log(`COMMERCE_WEBHOOK_PA2=PASS driver=${initial.driver} checks=${checks}`);
}catch(error){console.error(logs.slice(-2500));throw error;}finally{
  if(child.exitCode===null&&child.signalCode===null){const stopped=new Promise(r=>child.once('exit',r));child.kill();await stopped;}
  db('cleanup');fs.rmSync(tmp,{recursive:true,force:true});
}
