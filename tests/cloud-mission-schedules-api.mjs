import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {spawn,spawnSync} from 'node:child_process';
import net from 'node:net';
const root=process.cwd(),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-recurring-')),php=process.env.VP3_PHP_BINARY||'php';
for(const p of ['api','includes','sessions'])fs.mkdirSync(path.join(tmp,p));
fs.copyFileSync('api/agent-missions-cloud-v1.php',path.join(tmp,'api/agent-missions-cloud-v1.php'));
fs.writeFileSync(path.join(tmp,'includes/bootstrap.php'),'<?php require '+JSON.stringify(root+'/tests/fixtures/cloud-mission-schedules-v1.php')+'; session_start();');
for(const p of ['cloud-missions-v1.php'])fs.writeFileSync(path.join(tmp,'includes',p),'<?php require_once '+JSON.stringify(root+'/includes/'+p)+';');
fs.writeFileSync(path.join(tmp,'includes/homeserver-https-relay-v1300.php'),'<?php');
const env={...process.env,VP3_CLOUD_SCHEDULE_DSN:'sqlite:'+tmp+'/state.sqlite'};
const reserve=net.createServer();await new Promise(r=>reserve.listen(0,'127.0.0.1',r));const port=reserve.address().port;await new Promise(r=>reserve.close(r));
const child=spawn(php,['-d','session.save_path='+tmp+'/sessions','-S',`127.0.0.1:${port}`,'-t',tmp],{env,stdio:['ignore','ignore','pipe']});let logs='';child.stderr.on('data',c=>logs+=c);
const url=`http://127.0.0.1:${port}/api/agent-missions-cloud-v1.php`;
async function post(action,body={},headers={}){const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json',...headers},body:JSON.stringify({action,csrf_token:'fixture',...body})});return {status:r.status,data:await r.json()};}
let count=0;const check=async(name,fn)=>{await fn();count++;console.log('PASS '+name);};
try{
 for(let i=0;i<50;i++){try{await fetch(url);break;}catch{await new Promise(r=>setTimeout(r,50));}}
 await check('Cloud schedule routes enforce authentication and CSRF',async()=>{assert.equal((await post('schedule.list',{}, {'X-Fixture-Anonymous':'1'})).status,403);assert.equal((await post('cloud.schedule.tick',{csrf_token:'wrong'})).status,419);assert.equal((await post('schedule.list')).status,200);});
 const m=(await post('task.prepare',{executor:'cloud',objective:'Compare dough research',request_id:crypto.randomUUID(),thread_id:11,agent_id:7})).data.mission;
 const draft=m.chat_task.draft;draft.assignments[0].tools=['knowledge.search'];draft.assignments[0].max_calls=1;
 assert.equal((await post('tools.configure',{mission_id:m.id,expected_revision:0,request_id:crypto.randomUUID(),confirmed:true,assignments:draft})).status,200);
 const request={mission_id:m.id,request_id:crypto.randomUUID(),expected_revision:1,confirmed:true,folder_id:0,save_reports:true,timing:{frequency:'daily',weekday:0,hour:9,minute:0,timezone:'America/Phoenix'}};let schedule;
 await check('reviewed recurring plans create real owner folders and recover lost responses',async()=>{const r=await post('schedule.create',request);assert.equal(r.status,200);schedule=r.data.schedules[0];assert.ok(schedule.folder_id>0);assert.equal((await post('schedule.create',request)).data.schedules[0].id,schedule.id);assert.equal((await post('schedule.list',{}, {'X-Fixture-User':'2'})).data.schedules.length,0);assert.equal((await post('schedule.pause',{schedule_id:schedule.id,request_id:crypto.randomUUID(),confirmed:true,expected_revision:1},{'X-Fixture-User':'2'})).status,404);});
 const seed=spawnSync(php,['-r','require '+JSON.stringify(root+'/tests/fixtures/cloud-mission-schedules-v1.php')+'; db()->exec("UPDATE agent_cloud_mission_schedules_v1 SET next_run_at=\'2000-01-01 00:00:00\'");'],{env,encoding:'utf8'});assert.equal(seed.status,0,seed.stderr);
 let run;
 await check('Cloud scheduling enqueues one job without a HomeServer connection',async()=>{assert.equal((await post('cloud.schedule.tick')).data.created,1);assert.equal((await post('cloud.schedule.tick')).data.created,0);const s=(await post('schedule.list')).data.schedules[0];assert.equal(s.runs.length,1);run=s.runs[0].mission_id;assert.equal((await post('get',{mission_id:run})).data.mission.status,'running');});
 await check('actual API worker execution saves the completed indexed report and history',async()=>{assert.equal((await post('cloud.tick',{mission_id:run})).data.mission.tasks[0].status,'completed');assert.equal((await post('cloud.tick',{mission_id:run})).data.mission.status,'completed');const s=(await post('schedule.list')).data.schedules[0];assert.equal(s.runs[0].report_state,'saved');assert.ok(s.runs[0].knowledge_id>0);assert.equal(s.runs[0].evidence_changed,-1);assert.ok((await post('notifications.list')).data.notifications.items.some(n=>n.title==='Cloud Mission · Report saved in My Knowledge'));assert.equal((await post('execution',{mission_id:run})).data.execution.receipts.length,2);});
 await check('pause, stale revision and cancellation apply through the production route',async()=>{assert.equal((await post('schedule.pause',{schedule_id:schedule.id,expected_revision:1,request_id:crypto.randomUUID(),confirmed:true})).status,200);assert.equal((await post('schedule.resume',{schedule_id:schedule.id,expected_revision:1,request_id:crypto.randomUUID(),confirmed:true})).status,409);assert.equal((await post('schedule.cancel',{schedule_id:schedule.id,expected_revision:2,request_id:crypto.randomUUID(),confirmed:true})).status,200);assert.equal((await post('schedule.list')).data.schedules[0].status,'cancelled');assert.equal((await post('get',{mission_id:run})).data.mission.status,'completed');});
 await check('scheduler health does not invent an unattended worker',async()=>{const h=(await post('schedule.list')).data.cloud_scheduler_health;assert.equal(h.background_ready,false);assert.equal(h.state,'browser_assisted');});
 await check('production CLI runner discovers a due owner and completes a report with Chat closed',async()=>{
  const again=await post('schedule.create',{...request,request_id:crypto.randomUUID()});assert.equal(again.status,200);
  const setup=spawnSync(php,['-r','require '+JSON.stringify(root+'/tests/fixtures/cloud-mission-schedules-v1.php')+'; db()->exec("DELETE FROM agent_workflow_runs WHERE source_kind<>\'cloud_mission\'; UPDATE agent_cloud_mission_schedules_v1 SET next_run_at=\'2000-01-01 00:00:00\' WHERE status=\'active\'");'],{env,encoding:'utf8'});assert.equal(setup.status,0,setup.stderr);
  const worker=path.join(tmp,'worker');fs.mkdirSync(worker);fs.mkdirSync(path.join(worker,'includes'));
  fs.copyFileSync('agent-worker-v1910.php',path.join(worker,'agent-worker-v1910.php'));
  fs.writeFileSync(path.join(worker,'includes/bootstrap.php'),'<?php define("STONEFELLOW_ROOT",'+JSON.stringify(worker)+'); require '+JSON.stringify(root+'/tests/fixtures/cloud-mission-schedules-v1.php')+'; require '+JSON.stringify(root+'/includes/agent-runtime-v125.php')+';');
  // This Cloud-only journey excludes unrelated HomeServer transport setup.
  fs.writeFileSync(path.join(worker,'includes/agent-job-worker-v1900.php'),'<?php');
  for(const name of ['agent-worker-cloud-v1910.php','cloud-mission-schedules-v1.php','profile-calls-pa3c.php','profile-visitor-speech-pa4a.php','profile-speech-elevenlabs-pa4a.php'])fs.writeFileSync(path.join(worker,'includes',name),'<?php require_once '+JSON.stringify(root+'/includes/'+name)+';');
  for(let i=0;i<2;i++){const r=spawnSync(php,[path.join(worker,'agent-worker-v1910.php'),'--executor=cloud'],{env,encoding:'utf8'});assert.equal(r.status,0,r.stderr+r.stdout);const result=JSON.parse(r.stdout.trim());assert.equal(result.cycle.cloud_completed,1,r.stdout);}
  const list=(await post('schedule.list')).data;assert.equal(list.cloud_scheduler_health.background_ready,true);assert.ok(list.schedules.some(s=>s.status==='active'&&s.runs[0]?.report_state==='saved'));
 });
 console.log(`CLOUD_MISSION_SCHEDULES_API=PASS (${count} cases)`);
}catch(e){console.error(logs.slice(-2500));throw e;}finally{child.kill();await new Promise(r=>child.once('exit',r));fs.rmSync(tmp,{recursive:true,force:true});}
