import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {spawnSync} from 'node:child_process';

const registrySource=fs.readFileSync('editor-agent.js','utf8');
const browserContextSource=fs.readFileSync('agent-context-v131.js','utf8');
const serverContextSource=fs.readFileSync('includes/agent-surface-context-v131.php','utf8');

class MemoryStorage {
  constructor(){this.values=new Map();}
  getItem(key){return this.values.has(key)?this.values.get(key):null;}
  setItem(key,value){this.values.set(String(key),String(value));}
  removeItem(key){this.values.delete(String(key));}
}
class FakeCustomEvent {
  constructor(type,init={}){this.type=type;this.detail=init.detail;}
}

const sharedStorage=new MemoryStorage();
function makeSandbox(pathname='/chat.php'){
  const events=[];
  const listeners=new Map();
  const sandbox={
    console,Date,JSON,Math,Promise,Set,Map,Object,Array,String,Number,Boolean,Error,
    CustomEvent:FakeCustomEvent,structuredClone:globalThis.structuredClone,
    localStorage:sharedStorage,location:{pathname},
    dispatchEvent(event){events.push(event);for(const fn of listeners.get(event.type)||[])fn(event);return true;},
    addEventListener(type,fn){if(!listeners.has(type))listeners.set(type,[]);listeners.get(type).push(fn);},
  };
  sandbox.window=sandbox;
  vm.createContext(sandbox);
  vm.runInContext(registrySource,sandbox,{filename:'editor-agent.js'});
  return {sandbox,events};
}

function makeAdapter(surface,path,state){
  return {
    label:surface==='stem'?'Stem Studio':'Video Editor',
    path,
    commands:()=>[
      {id:`${surface}.clip.split`,legacyType:'split',category:'edit',description:`Split a ${surface} clip.`,args:['clip_id'],mutates:true,verifiable:true},
    ],
    selection:()=>({current:{clip_id:'clip-a'},privateSelection:'do-not-persist'}),
    snapshot:()=>({splits:state.splits,privateState:'top-secret-editor-state'}),
    normalizeCommand:(command,descriptor)=>({...command,type:descriptor.legacyType}),
    execute:command=>{if(command.type==='split'){state.splits+=1;return{status:'success',result:'Split dispatched',verified:false};}return{status:'unsupported',result:'Unsupported',verified:false};},
    verify:(command,before,after)=>({status:after.splits===before.splits+1?'success':'failed',result:'Split state verified',verified:after.splits===before.splits+1}),
  };
}

const first=makeSandbox('/admin/stems.php');
const broker=first.sandbox.StonefellowEditorAgent;
const stemState={splits:0},videoState={splits:0};
broker.registerSurface('stem',makeAdapter('stem','/admin/stems.php',stemState));
broker.registerSurface('video',makeAdapter('video','/video-editor.php',videoState));

assert.deepEqual(Array.from(broker.knownSurfaces(),row=>row.id),['stem','video'],'broker exposes all known editor surfaces');
assert.equal(broker.capabilities().length,2,'broker exposes one flattened cross-surface capability catalog');
assert.deepEqual(Array.from(broker.searchCapabilities('clip split'),row=>row.id).sort(),['stem.clip.split','video.clip.split'],'capability search finds matching tools across editors');

const videoResolved=broker.resolveCommand({id:'video.clip.split'});
assert.equal(videoResolved.status,'resolved');
assert.equal(videoResolved.surface,'video');
assert.equal(videoResolved.available,true);
assert.equal(videoResolved.path,'/video-editor.php');

const ambiguous=broker.resolveCommand({type:'split'});
assert.equal(ambiguous.status,'ambiguous','shared legacy command names are never guessed across surfaces');
assert.deepEqual(Array.from(ambiguous.candidates,row=>row.surface).sort(),['stem','video']);

const executed=await broker.executeAny({command:{id:'video.clip.split',clip_id:'clip-a'}});
assert.equal(executed.status,'success','executeAny routes an exact namespaced command to its live editor');
assert.equal(executed.verified,true,'executeAny retains adapter verification truth');
assert.equal(videoState.splits,1);
assert.equal(stemState.splits,0);

const contextCatalog=broker.contextCatalog();
assert.equal(contextCatalog.surfaces.length,2);
assert.deepEqual(Array.from(contextCatalog.surfaces.find(row=>row.id==='video').commands),['video.clip.split']);
assert.equal(contextCatalog.surfaces.find(row=>row.id==='video').available,true);

const persisted=sharedStorage.getItem('stonefellow:editor-capability-catalog')||'';
assert.match(persisted,/stem\.clip\.split/,'safe command descriptors persist across navigation');
assert.match(persisted,/video\.clip\.split/,'all learned editor descriptors persist across navigation');
assert.doesNotMatch(persisted,/top-secret-editor-state|do-not-persist/,'catalog persistence never stores editor state or selection data');

const second=makeSandbox('/chat.php');
const restored=second.sandbox.StonefellowEditorAgent;
assert.equal(restored.surfaces().length,0,'a new page has no live editor adapters until they register');
assert.deepEqual(Array.from(restored.knownSurfaces(),row=>row.id),['stem','video'],'a new page restores safe learned capability descriptors');
assert.ok(restored.knownSurfaces().every(row=>row.available===false),'restored capabilities are truthfully marked unavailable');

const unavailableResolution=restored.resolveCommand({id:'stem.clip.split'});
assert.equal(unavailableResolution.status,'resolved');
assert.equal(unavailableResolution.available,false);
assert.equal(unavailableResolution.path,'/admin/stems.php');
const unavailableExecution=await restored.executeAny({command:{id:'stem.clip.split',clip_id:'clip-a'}});
assert.equal(unavailableExecution.status,'unsupported','known command on an unloaded editor is never reported as executed');
assert.equal(unavailableExecution.reason,'surface_unavailable');
assert.equal(unavailableExecution.requiredSurface,'stem');
assert.equal(unavailableExecution.path,'/admin/stems.php');

const restoredVideoState={splits:4};
restored.registerSurface('video',makeAdapter('video','/video-editor.php',restoredVideoState));
const liveAgain=restored.resolveCommand({id:'video.clip.split'});
assert.equal(liveAgain.available,true,'registration upgrades a known capability to currently executable');
const restoredExecution=await restored.executeAny({command:{id:'video.clip.split',clip_id:'clip-a'}});
assert.equal(restoredExecution.status,'success');
assert.equal(restoredVideoState.splits,5);

assert.match(browserContextSource,/EDITOR_AGENT_ASSET='editor-agent-capabilities-20260903'/,'Agent Context bootstraps the canonical broker on Chat');
assert.match(browserContextSource,/editor_capabilities:editorCapabilities\(\)/,'Agent Context carries the compact capability catalog');
assert.match(browserContextSource,/stonefellow:editor-agent:catalog-updated/,'Agent Context republishes when editor capabilities change');
assert.match(serverContextSource,/\['chat','stem','video','transcription'\]/,'server sanitizer recognizes the Transcription surface');
assert.match(serverContextSource,/'editor_capabilities'=>null/,'server context has an explicit capability field');
assert.match(serverContextSource,/str_starts_with\(\$commandId,\$id\.'\.'\)/,'server sanitizer enforces surface command namespaces');

const rawContext={
  surface:'transcription',
  editor_capabilities:{
    build:'editor-agent-canonical-20260903',schema:'editor-agent-capability-catalog-20260903',
    surfaces:[
      {id:'video',label:'Video Editor',path:'/video-editor.php',available:false,commands:['video.clip.split','stem.clip.delete','<script>']},
      {id:'transcription',label:'Transcription Editor',path:'/artist-listening.php',available:true,commands:['transcription.document.rename']},
    ],
  },
};
const php=spawnSync('php',['-r',"require 'includes/agent-surface-context-v131.php'; $raw=json_decode(getenv('RAW'),true); echo json_encode(agent_surface_v131_sanitize($raw));"],{
  cwd:process.cwd(),encoding:'utf8',env:{...process.env,RAW:JSON.stringify(rawContext)}
});
assert.equal(php.status,0,php.stderr||'PHP sanitizer invocation failed');
const sanitized=JSON.parse(php.stdout);
assert.equal(sanitized.surface,'transcription','Transcription surface survives server sanitization');
assert.deepEqual(sanitized.editor_capabilities.surfaces[0].commands,['video.clip.split'],'server drops commands that do not belong to the declared surface');
assert.deepEqual(sanitized.editor_capabilities.surfaces[1].commands,['transcription.document.rename']);

console.log('EDITOR_AGENT_CAPABILITY_BROKER=PASS');
