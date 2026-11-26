import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';

// The browser receives the real PHP/SQLite projection, including both recording
// namespaces and persisted preferences. No carrier or paid provider is used.
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-phone-release-'));
const stateFile=path.join(temp,'phone-workspace-state.json');
const uiFile=path.join(temp,'phone-workspace-ui.json');
function run(command,args,extra={}){
  const result=spawnSync(command,args,{cwd:root,encoding:'utf8',timeout:180000,maxBuffer:8*1024*1024,...extra});
  if(result.stdout)process.stdout.write(result.stdout);
  if(result.stderr)process.stderr.write(result.stderr);
  if(result.error)throw result.error;
  assert.equal(result.status,0,`${path.basename(command)} ${args[0]} must pass`);
}
try{
  run(process.env.PHP_BIN||'php',['tests/phone-browser-workspace-v1.php',`--emit-state=${stateFile}`]);
  assert.ok(fs.existsSync(stateFile),'The real PHP state projection is mandatory.');
  const state=JSON.parse(fs.readFileSync(stateFile,'utf8'));
  assert.ok(Array.isArray(state.numbers)&&state.numbers.length>0,'The projection needs a real assigned number.');
  assert.ok(state.numbers.some(number=>number.id===state.selected_number_id),'The selected number belongs to the returned assignments.');
  assert.ok(Array.isArray(state.calls)&&state.calls.some(call=>call.source_kind==='browser'&&/^[a-f0-9]{32}$/.test(call.public_id)&&call.browser_control===true),'The real browser-call ledger is represented.');
  assert.ok(state.calls.some(call=>call.source_kind==='legacy'&&/^legacy-[1-9][0-9]*$/.test(call.public_id)&&call.browser_control===false),'Existing inbound call history is represented as read-only legacy calls.');
  assert.equal(new Set(state.calls.map(call=>call.public_id)).size,state.calls.length,'The merged call projection has no duplicate public call identities.');
  assert.ok(state.calls.every(call=>call.call_id===call.public_id),'Typed Chat call references preserve their projected call identity.');
  assert.ok(Array.isArray(state.voicemails)&&state.voicemails.some(row=>/^job-[1-9][0-9]*$/.test(row.id)),'The real recording pipeline namespace is represented.');
  assert.ok(state.voicemails.some(row=>/^legacy-[1-9][0-9]*$/.test(row.id)),'Historical voicemail remains represented alongside new jobs.');
  assert.equal(typeof state.post_call_policy?.enabled,'boolean','Persisted policy exposes the explicit post-call processing choice.');
  assert.equal(state.post_call_policy?.model_route,'account_cloud','The persisted processing route matches the disclosed Cloud services.');
  assert.equal(typeof state.readiness?.ready,'boolean','Readiness is an explicit server decision.');
  run(process.env.PHP_BIN||'php',['tests/phone-workspace-integration-v1.php',`--emit-ui=${uiFile}`]);
  assert.ok(fs.existsSync(uiFile),'The real PHP header/configuration fixture is mandatory.');
  const ui=JSON.parse(fs.readFileSync(uiFile,'utf8'));
  assert.ok(typeof ui.button==='string'&&ui.button.includes('data-phone-workspace-toggle')&&ui.button.includes('<svg'),'The browser uses the actual rendered Phone header button.');
  assert.equal(ui.config?.agent_id,0,'The real UI fixture uses the backend MAIN Agent namespace.');
  assert.equal(ui.config?.conversation_id,11,'The real UI fixture uses the backend owner MAIN conversation.');
  assert.equal(new URL(ui.config?.endpoint).origin,'https://cloud.example','The rendered UI and actual media links share their real fixture origin.');
  console.log('PASS real PHP Phone projection includes assigned numbers, browser/legacy call history, both voicemail namespaces and persisted Cloud choice');
  if(!process.argv.includes('--projection-only')){
    // The real-audio conversation checks now also exercise explicit pause/resume controls.
    run(process.execPath,['tests/phone-workspace-v1-browser.mjs'],{timeout:240000,env:{...process.env,PHONE_WORKSPACE_STATE_FIXTURE:stateFile,PHONE_WORKSPACE_UI_FIXTURE:uiFile}});
  }
}finally{
  fs.rmSync(temp,{recursive:true,force:true});
}
