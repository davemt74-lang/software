import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';
const root=process.cwd(),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-call-admin-')),php=process.env.VP3_PHP_BINARY||'php';
fs.mkdirSync(path.join(tmp,'includes'));fs.mkdirSync(path.join(tmp,'admin'));fs.mkdirSync(path.join(tmp,'sessions'));
for(const name of fs.readdirSync('includes'))if(name!=='bootstrap.php')fs.symlinkSync(path.resolve('includes',name),path.join(tmp,'includes',name));
fs.copyFileSync('admin/profile-calls.php',path.join(tmp,'admin/profile-calls.php'));
fs.copyFileSync('tests/fixtures/profile-calls-admin-pa3c-bootstrap.php',path.join(tmp,'includes/bootstrap.php'));
// The shared admin header consumes its own flash notice variable.
fs.writeFileSync(path.join(tmp,'admin/_header.php'),'<?php $notice=null; ?><html><body><main>');
fs.writeFileSync(path.join(tmp,'admin/_footer.php'),`<?php echo '<script type="application/json" id="fixture-state">'.json_encode(['readiness'=>vp3_profile_call_livekit_readiness_pa3c(),'source'=>setting('profile_calls_source_pa3c'),'invalidations'=>$GLOBALS['pcInvalidations'],'plaintext_saved'=>str_contains((string)setting('profile_calls_livekit_config_pa3c'),'SECRET_PROFILE')],JSON_HEX_TAG|JSON_THROW_ON_ERROR).'</script>'; ?></main></body></html>`);
const reservation=net.createServer();await new Promise(r=>reservation.listen(0,'127.0.0.1',r));const port=reservation.address().port;await new Promise(r=>reservation.close(r));
const child=spawn(php,['-d','display_errors=0','-d','session.save_path='+path.join(tmp,'sessions'),'-S',`127.0.0.1:${port}`,'-t',tmp],{env:{...process.env,VP3_PA3_ROOT:root},stdio:['ignore','ignore','pipe']});
let logs='',checks=0;child.stderr.on('data',v=>logs+=v);const base=`http://127.0.0.1:${port}/admin/profile-calls.php`;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
async function request(method='GET',fields={},user=1,extra={}){
 const r=await fetch(base+(method==='GET'?'?'+new URLSearchParams(fields):''),{method,redirect:'manual',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Fixture-User':String(user),Cookie:'PHPSESSID=adminfixture'+user,...extra},...(method==='POST'?{body:new URLSearchParams(fields)}:{})});
 const raw=await r.text(),match=raw.match(/<script type="application\/json" id="fixture-state">([^<]+)<\/script>/);return {status:r.status,location:r.headers.get('location'),raw,state:match?JSON.parse(match[1]):null};
}
try{
 let ready=false;for(let i=0;i<80;i++){try{const r=await request();if(r.status===200){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,25));}assert.ok(ready,logs);
 let r=await request('GET',{},0);check(r.status===302&&r.location==='/login.php','actual login service prevents anonymous admin access');
 r=await request('POST',{action:'disable',csrf_token:'profile-admin-fixture-csrf'},2);check(r.status===403&&!r.state,'actual stored member role cannot alter calling settings');
 r=await request();check(r.status===200&&r.state.readiness.ready&&r.state.invalidations===0,'authorized admin readiness GET is read-only');
 check(!/SECRET_PROFILE_FIRST|API_PROFILE_FIRST|MEETING_SECRET_SENTINEL|encrypted-fixture-provider/.test(r.raw),'rendered settings never expose saved keys or secrets');
 check(r.raw.includes('does not verify Agent deployment rules')&&r.raw.includes('Acknowledged by an administrator'),'API evidence and human-only deployment acknowledgement are visibly distinct');
 r=await request('GET',{action:'disable'});check(r.status===200&&r.state.readiness.enabled&&r.state.invalidations===0,'GET action cannot disable or invalidate calls');
 for(const action of ['save','disable','verify_api']){
  r=await request('POST',{action,csrf_token:'wrong'});check(r.status===419&&r.state.readiness.enabled&&r.state.invalidations===0,'body CSRF protects '+action+' before settings or provider effects');
 }
 r=await request('POST',{action:'save',csrf_token:'profile-admin-fixture-csrf',source:'profile',enabled:'1',human_only_acknowledged:'1'});
 check(r.status===200&&r.state.readiness.ready&&!r.state.plaintext_saved&&r.state.invalidations===0&&r.raw.includes('Profile call settings saved.'),'blank saved credential fields preserve the encrypted project and show the save receipt');
 r=await request('POST',{action:'save',csrf_token:'profile-admin-fixture-csrf',source:'profile',enabled:'1',human_only_acknowledged:'1',url:'wss://new-project.livekit.cloud'});
 check(r.status===422&&r.state.readiness.ready&&r.state.invalidations===0,'changing the project requires its matching secret before invalidation');
 r=await request('POST',{action:'save',csrf_token:'profile-admin-fixture-csrf',source:'profile',enabled:'1',human_only_acknowledged:'1',url:'wss://new-project.livekit.cloud',api_key:'NEW_PROJECT_API_KEY',api_secret:'NEW_PROJECT_SECRET_123456789'});
 check(r.status===200&&!r.state.readiness.ready&&r.state.readiness.reason==='api_unverified'&&r.state.invalidations===1,'project rotation queues old call closure and requires a fresh metadata check');
 check(!r.raw.includes('NEW_PROJECT_SECRET_123456789')&&!r.raw.includes('NEW_PROJECT_API_KEY'),'newly submitted credentials are not reflected in HTML');
 r=await request('POST',{action:'disable',csrf_token:'profile-admin-fixture-csrf'});check(r.status===200&&!r.state.readiness.enabled&&r.state.invalidations===1,'explicit disable retains cleanup obligation and blocks new calls');
 r=await request('POST',{action:'save',csrf_token:'profile-admin-fixture-csrf',source:'meetings',enabled:'1',human_only_acknowledged:'1'});
 check(r.status===200&&r.state.source==='meetings'&&r.state.readiness.reason==='api_unverified','existing meeting configuration requires explicit opt-in and separate verification');
 r=await request('POST',{action:'save',csrf_token:'profile-admin-fixture-csrf'},1,{'X-Fixture-Schema':'0'});check(r.status===503&&r.state.invalidations===0,'missing call storage cannot partially save calling settings');
 console.log(`PROFILE_CALLS_ADMIN_PA3C=PASS (${checks} checks)`);
}finally{child.kill('SIGTERM');await new Promise(r=>child.once('exit',r));fs.rmSync(tmp,{recursive:true,force:true});}
