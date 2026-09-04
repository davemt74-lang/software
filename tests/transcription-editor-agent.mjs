import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const registrySource=fs.readFileSync('editor-agent.js','utf8');
const transcriptionSource=fs.readFileSync('transcription-editor.js','utf8');

let title='Original transcript';
let sessionId=7;
let sessions=[{id:7,title}];
let confirmResult=false;
const events=[];

const workspaceState=()=>({
  ready:true,
  sessionId,
  current:sessionId?{id:sessionId,title,tags:[],folder:null,conversationId:0}:null,
  filter:{folder:'all',query:''},
  view:'prose',
  paused:false,
  liveSessionId:0,
  editable:true,
  microphoneId:'',
  selectedTurnId:0,
  sessions:sessions.map(row=>({...row,title:row.id===sessionId?title:row.title})),
  folders:[],
  turns:[],
  documentText:'hello world',
});

const window={
  dispatchEvent:event=>events.push(event),
  confirm:()=>confirmResult,
  STONEFELLOW_ARTIST_LISTENING_WORKSPACE:{api:{
    getState:workspaceState,
    getSelection:()=>({sessionId,text:{start:0,end:5,value:'hello'},turn:null}),
    renameDocument:async next=>{title=String(next);sessions=sessions.map(row=>row.id===sessionId?{...row,title}:row);return{id:sessionId,title};},
    deleteDocument:async requested=>{const id=Number(requested||sessionId);sessions=sessions.filter(row=>row.id!==id);if(sessionId===id)sessionId=0;return{deleted:true,sessionId:id};},
  }},
  STONEFELLOW_ARTIST_LISTENING_V172:{api:{getState:()=>({active:false,pendingStop:false,recordingActive:false,recordingUploading:false,markerCount:0,noteCount:0,speakerMode:'auto'})}},
  STONEFELLOW_ARTIST_LISTENING_TRANSCRIPT:{api:{getState:()=>({view:'page',page:1})}},
  STONEFELLOW_ARTIST_LISTENING_AI:{api:{getState:()=>({sessionId,open:false,settingsOpen:false,researchEnabled:false,selectedApps:[],activeApp:'',busy:false,report:null,liveWords:0,lastError:''})}},
  STONEFELLOW_ARTIST_RECORDINGS_V198:{api:{getState:()=>({current:null,library:[],loading:false,lastError:''}),getSelection:()=>null}},
};

class CustomEvent {
  constructor(type,options={}){this.type=type;this.detail=options.detail;}
}

const context=vm.createContext({window,CustomEvent,structuredClone,console,Promise,setTimeout,clearTimeout});
vm.runInContext(registrySource,context,{filename:'editor-agent.js'});
vm.runInContext(transcriptionSource,context,{filename:'transcription-editor.js'});
await new Promise(resolve=>setTimeout(resolve,0));

const registry=window.StonefellowEditorAgent;
assert(registry,'universal Editor Agent loaded');
assert.equal(registry.hasSurface('transcription'),true,'Transcription Editor registers with universal Editor Agent');

const inspection=await registry.inspect('transcription');
assert.equal(inspection.commands.length,50,'Transcription registry exposes all 50 reviewed commands');
assert.equal(inspection.selection.document.sessionId,7,'registry exposes current transcription document selection');
assert.equal(inspection.selection.text.value,'hello','registry exposes current text selection');
assert.equal(inspection.state.workspace.current.title,'Original transcript','registry exposes current transcription state');
assert.equal(inspection.state.workspace.documentText,undefined,'registry state does not duplicate full transcript text');
assert.equal(inspection.state.workspace.sessionCount,1,'registry exposes bounded transcription counts instead of full library arrays');
assert.equal(inspection.state.workspace.sessions,undefined,'registry state does not duplicate the transcription library');

const renamed=await registry.execute({surface:'transcription',command:{id:'transcription.document.rename',title:'Renamed transcript'}});
assert.equal(renamed.status,'success','verified transcription mutation reports success');
assert.equal(renamed.verified,true,'verified transcription mutation is marked verified');
assert.equal(title,'Renamed transcript','registry delegates mutation to canonical Transcription Editor API');
assert.equal(renamed.raw.verification.method,'state','registry preserves canonical transcription verification evidence');
assert.equal(renamed.before.workspace.documentText,undefined,'execution before-state remains bounded');
assert.equal(renamed.after.workspace.sessions,undefined,'execution after-state remains bounded');

const cancelled=await registry.execute({surface:'transcription',command:{id:'transcription.document.delete',sessionId:7}});
assert.equal(cancelled.status,'cancelled','destructive transcription command requires confirmation');
assert.equal(sessionId,7,'cancelled destructive action does not mutate transcript state');

const removed=await registry.execute({surface:'transcription',command:{id:'transcription.document.delete',sessionId:7},context:{confirmed:true}});
assert.equal(removed.status,'success','explicitly confirmed destructive action can execute');
assert.equal(removed.verified,true,'confirmed destructive action must still verify state');
assert.equal(sessionId,0,'confirmed deletion reaches canonical Transcription Editor API');

const unsupported=await registry.execute({surface:'transcription',command:{id:'transcription.not-real'}});
assert.equal(unsupported.status,'unsupported','unknown transcription command never executes');
assert.equal(window.STONEFELLOW_TRANSCRIPTION_EDITOR_AGENT.registered,true,'Transcription registration publishes canonical proof');
assert.equal(window.STONEFELLOW_TRANSCRIPTION_EDITOR_AGENT.capabilityCount,50,'registration proof reports exact capability count');
assert(events.some(event=>event.type==='stonefellow:transcription-editor-agent-ready'),'registration emits ready event');

console.log('TRANSCRIPTION_EDITOR_AGENT=PASS');
