import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';

const root=process.cwd(),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-speech-admin-')),php=process.env.VP3_PHP_BINARY||'php';
for(const name of ['includes','admin','sessions'])fs.mkdirSync(path.join(tmp,name));
for(const name of fs.readdirSync('includes'))if(!['bootstrap.php','profile-speech-elevenlabs-pa4a.php'].includes(name))fs.symlinkSync(path.resolve('includes',name),path.join(tmp,'includes',name));
// Keep production provider validation and storage; replace only the external HTTP boundary.
let provider=fs.readFileSync('includes/profile-speech-elevenlabs-pa4a.php','utf8');
const boundary='function vp3_profile_speech_http_pa4a(string $method,string $path,array $config,?array $payload=null,?callable $transport=null): array\n{';
assert.equal(provider.split(boundary).length,2);provider=provider.replace(boundary,boundary+"\n    $transport??='sp_http';");
const providerPath=path.join(tmp,'includes/profile-speech-elevenlabs-pa4a.php');fs.writeFileSync(providerPath,provider);
fs.copyFileSync('admin/profile-speech.php',path.join(tmp,'admin/profile-speech.php'));fs.copyFileSync('tests/fixtures/profile-speech-admin-pa4a-bootstrap.php',path.join(tmp,'includes/bootstrap.php'));
fs.writeFileSync(path.join(tmp,'admin/_header.php'),'<?php $notice=null; ?><html><body><main>');
fs.writeFileSync(path.join(tmp,'admin/_footer.php'),`<?php echo '<script type="application/json" id="fixture-state">'.json_encode(['state'=>vp3_profile_speech_admin_state_pa4a(),'provider_methods'=>array_column($GLOBALS['spCalls'],'method'),'saved_key_unchanged'=>ai_elevenlabs_credential()[0]==='SECRET_SAVED_ELEVENLABS_KEY'],JSON_HEX_TAG|JSON_THROW_ON_ERROR).'</script>'; ?></main></body></html>`);
const reservation=net.createServer();await new Promise(r=>reservation.listen(0,'127.0.0.1',r));const port=reservation.address().port;await new Promise(r=>reservation.close(r));
const child=spawn(php,['-d','display_errors=0','-d','session.save_path='+path.join(tmp,'sessions'),'-S',`127.0.0.1:${port}`,'-t',tmp],{env:{...process.env,VP3_PA4_ROOT:root,VP3_SPEECH_PROVIDER_FIXTURE_FILE:providerPath},stdio:['ignore','ignore','pipe']});
let logs='',checks=0;child.stderr.on('data',v=>logs+=v);const base=`http://127.0.0.1:${port}/admin/profile-speech.php`;
const check=(value,label)=>{assert.ok(value,label);checks++;console.log('PASS '+label);};
async function request(method='GET',fields={},user=1,extra={}){
 const r=await fetch(base+(method==='GET'?'?'+new URLSearchParams(fields):''),{method,redirect:'manual',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Fixture-User':String(user),Cookie:'PHPSESSID=speechadmin'+user,...extra},...(method==='POST'?{body:new URLSearchParams(fields)}:{})});
 const raw=await r.text(),match=raw.match(/<script type="application\/json" id="fixture-state">([^<]+)<\/script>/);return {status:r.status,location:r.headers.get('location'),raw,state:match?JSON.parse(match[1]):null};
}
try{
 let ready=false;for(let i=0;i<80;i++){try{const r=await request();if(r.status===200){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,25));}assert.ok(ready,logs);
 let r=await request('GET',{},0);check(r.status===302&&r.location==='/login.php','actual login gate protects Admin speech settings');
 r=await request('POST',{action:'disable',csrf_token:'profile-speech-admin-csrf'},2);check(r.status===403&&!r.state,'stored member role cannot change provider voice approval');
 r=await request();check(r.status===200&&r.state.state.enabled&&r.state.provider_methods.length===0,'Admin GET uses existing encrypted credential without provider generation or metadata calls');
 check(!/SECRET_SAVED_ELEVENLABS_KEY|SECRET_ENVIRONMENT_NOT_SELECTED|SECRET_PROVIDER_DIAGNOSTIC/.test(r.raw),'Admin never renders existing provider keys or diagnostic payloads');
 check(r.raw.includes('An unreported cost remains unknown')&&r.raw.includes('never regenerated automatically')&&r.raw.includes('does not import voices'),'Admin explains character accounting, explicit retry and public preset setup');
 const revision=r.state.state.settings_revision;
 r=await request('GET',{action:'disable'});check(r.status===200&&r.state.state.enabled&&r.state.provider_methods.length===0,'GET action cannot change speech availability');
 for(const action of ['save','disable','check_catalog']){r=await request('POST',{action,csrf_token:'wrong'});check(r.status===419&&r.state.state.enabled&&r.state.provider_methods.length===0,'body CSRF protects '+action);}
 r=await request('POST',{action:'check_catalog',csrf_token:'profile-speech-admin-csrf'});check(r.status===200&&r.state.provider_methods.length>0&&r.state.provider_methods.every(m=>m==='GET')&&r.raw.includes('did not generate audio'),'explicit catalog check verifies metadata without generating speech');
 const save={action:'save',csrf_token:'profile-speech-admin-csrf',settings_revision:revision,enabled:'1','presets[0][voice_id]':'PublicVoiceGood001','presets[0][label]':'Approved Visitor'};
 r=await request('POST',save);check(r.status===200&&r.state.state.presets.length===1&&r.state.state.presets[0].style==='female'&&r.state.provider_methods.every(m=>m==='GET'),'Admin saves only a current provider-validated public preset');
 check(r.state.saved_key_unchanged&&r.raw.includes('Profile speech settings saved.'),'explicit preset save preserves the encrypted API credential and displays its receipt');
 for(const mode of ['private','changed_id','error']){r=await request('POST',save,1,{'X-Fixture-Provider-Mode':mode});check([422,503].includes(r.status)&&r.state.state.presets.length===0&&r.state.saved_key_unchanged&&!r.raw.includes('SECRET_PROVIDER_DIAGNOSTIC'),'invalid provider evidence cannot approve preset: '+mode);}
 r=await request('POST',save,1,{'X-Fixture-Provider-Mode':'revoke_admin'});check(r.status===403&&r.state.state.presets.length===0,'Admin permission is reread after slow provider metadata before saving');
 r=await request('POST',{...save,settings_revision:'0'.repeat(64)});check(r.status===409&&r.state.provider_methods.length===0,'stale settings form cannot overwrite a changed voice configuration');
 r=await request('POST',{...save,'presets[1][voice_id]':'PublicVoiceGood001'});check(r.status===422&&r.state.state.presets.length===0,'duplicate provider IDs cannot occupy multiple approval entries');
 r=await request('POST',save,1,{'X-Fixture-Schema':'0'});check(r.status===503&&r.state.provider_methods.length===0&&r.state.state.presets.length===0,'missing speech storage rejects setup before provider effects');
 r=await request('POST',{action:'disable',csrf_token:'profile-speech-admin-csrf'});check(r.status===200&&!r.state.state.enabled&&r.state.saved_key_unchanged&&r.state.provider_methods.length===0,'disable preserves existing provider credentials and performs no paid I/O');
 r=await request('POST',{...save,'presets[0][label]':'<img src=x onerror=alert(1)>'});check(r.status===200&&!r.raw.includes('<img src=x onerror=alert(1)>')&&r.raw.includes('&lt;img'),'approved preset display labels are escaped in Admin HTML');
 for(const mode of ['model','format']){r=await request('GET',{},1,{'X-Fixture-Config':mode});check(r.status===200&&!r.state.state.ready&&r.state.state.reason===mode+'_unsupported'&&r.raw.includes('Unavailable until the configuration is corrected')&&r.state.provider_methods.length===0&&!r.raw.includes('SECRET_INVALID_MODEL'),'explicit unsupported '+mode+' is explained without silent substitution or provider I/O');}
 console.log(`PROFILE_SPEECH_ADMIN_PA4A=PASS (${checks} checks)`);
}finally{child.kill('SIGTERM');await new Promise(r=>child.once('exit',r));fs.rmSync(tmp,{recursive:true,force:true});}
