import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('admin/stem-command-bus-v159.js','utf8');
const runtime=fs.readFileSync('admin/stems-v108.js','utf8');
const api=fs.readFileSync('api/stem-agent-v105.php','utf8');
const mixApi=fs.readFileSync('api/stem-mix.php','utf8');
const projectApi=fs.readFileSync('api/studio-project-v77.php','utf8');
const page=fs.readFileSync('admin/stems.php','utf8');
const schema=fs.readFileSync('includes/permissions.php','utf8');
function assert(value,label){console.log(`${value?'PASS':'FAIL'} ${label}`);if(!value)throw new Error(`Failed: ${label}`);}

assert(page.includes('stem-command-bus-v159.js')&&page.includes('$commandBusBridge'),'active Stem page loads the v159 command bus before the agent adapter');
assert(schema.includes('duration_measures INT UNSIGNED NULL'),'cumulative schema adds authoritative duration measures');
assert(runtime.includes("type==='song_duration'")&&runtime.includes('extendLibrarySamplesToDuration'),'runtime derives duration and extends library samples');
assert(runtime.includes('clearedRanges')&&runtime.includes("type==='clear_measure_range'"),'non-ripple measure clearing persists its ranges');
assert(runtime.includes('redoStack')&&runtime.includes("type==='undo'")&&runtime.includes("type==='redo'"),'shared runtime owns undo and redo');
assert(runtime.includes('recordingCaptureChannel')&&runtime.includes('sourceOffset = 0'),'recording captures the assigned hardware channel without adjacent-input mixing');
assert(mixApi.includes("'durationMeasures'")&&mixApi.includes("'generatedLoop'")&&mixApi.includes("'clearedRanges'"),'saved versions retain duration, repeats, and cleared ranges');
assert(projectApi.includes("$action === 'create_empty_tracks'")&&projectApi.includes("$action === 'rename_project'")&&projectApi.includes("$action === 'update_project_duration'"),'project API persists v159 project and track operations');
for(const command of ['v159_set_duration','v159_create_empty_tracks','v159_clear_measures','v159_loop_measures','v159_track_state','v159_undo','v159_redo','v159_save_as','v159_load_version','v159_load_project','v159_rename_project'])assert(api.includes(command),`voice grammar emits ${command}`);

class FormDataMock{
  constructor(){this.values=new Map();}
  append(key,value){this.values.set(String(key),String(value));}
  get(key){return this.values.get(String(key));}
}
let studioState={duration_measures:0,stems:[{id:11,name:'Drums',role:'Drums',muted:false,solo:false,volume:1,pan:0},{id:22,name:'Bass',role:'Bass',muted:false,solo:false,volume:1,pan:0}],loop:{active:false,start:0,end:0}};
let mixState={durationMeasures:0,stems:{'11':{muted:false},'22':{muted:false}}};
const clone=value=>JSON.parse(JSON.stringify(value));
const projectCalls=[];
const base={
  getAgentState:()=>clone(studioState),getMixState:()=>clone(mixState),applyMixState:value=>{mixState=clone(value);studioState.duration_measures=Number(value.durationMeasures||0);},
  beginUndoGroup(){},endUndoGroup(){},collectMixState:()=>clone(mixState),getSelectedMix:()=>({id:0,name:''}),setSelectedMixRef(){},
  async mixRequest(action,fields={}){if(action==='save')return{ok:true,mix_id:44,mix_name:fields.mix_name};if(action==='list')return{ok:true,mixes:[{id:44,mix_name:'Verse balance',updated_at:'now'}]};if(action==='load')return{ok:true,mix:{id:44,mix_name:'Verse balance',state:clone(mixState)}};throw new Error('unexpected mix action');},
  async executeAgentCommand(command){
    if(command.type==='song_duration'){studioState.duration_measures=Number(command.measures);mixState.durationMeasures=Number(command.measures);return{status:'success',result:'duration',verified:true};}
    if(['mute','unmute','solo','unsolo','volume','pan'].includes(command.type)){const row=studioState.stems.find(item=>item.id===Number(command.stem_id));if(command.type==='mute'||command.type==='unmute')row.muted=command.type==='mute';if(command.type==='solo'||command.type==='unsolo')row.solo=command.type==='solo';if(command.type==='volume')row.volume=Number(command.value);if(command.type==='pan')row.pan=Number(command.value);return{status:'success',result:command.type,verified:true};}
    if(command.type==='clear_measure_range'||command.type==='loop_measures'||command.type==='undo'||command.type==='redo')return{status:'success',result:command.type,verified:true};
    return{status:'unsupported',result:'unsupported',verified:false};
  }
};
const mediaTrack={label:'Focusrite Scarlett 18i8',readyState:'live',enabled:true,getSettings:()=>({channelCount:4,deviceId:'focus-1'}),stop(){}};
const context={
  window:{STONEFELLOW_STEM_STUDIO:{trackId:9,projectTitle:'Song',csrf:'token',projectEndpoint:'/project',projects:[{id:9,name:'Song',url:'/admin/stems.php?track=9'},{id:8,name:'Older',url:'/admin/stems.php?track=8'}]},StonefellowStemStudioV91:base,dispatchEvent(){}},
  navigator:{mediaDevices:{async getUserMedia(){return{getAudioTracks:()=>[mediaTrack],getTracks:()=>[mediaTrack]};},async enumerateDevices(){return[{kind:'audioinput',deviceId:'focus-1',groupId:'focus',label:'Focusrite Scarlett 18i8'}];}}},
  FormData:FormDataMock,CustomEvent:class{constructor(type,init){this.type=type;this.detail=init?.detail;}},setInterval,clearInterval,console,
  async fetch(url,{body}){const action=body.get('action');projectCalls.push({action,body});if(action==='update_project_duration')return new Response(JSON.stringify({ok:true,duration_measures:Number(body.get('duration_measures')),duration_seconds:37.28}),{status:200,headers:{'content-type':'application/json'}});if(action==='create_empty_tracks'){const specs=JSON.parse(body.get('tracks_json'));return new Response(JSON.stringify({ok:true,created:specs.map((row,index)=>({stem_id:100+index,...row})),redirect:'/admin/stems.php?track=9'}),{status:200,headers:{'content-type':'application/json'}});}throw new Error(`unexpected project action ${action}`);},
  Response
};
context.window.window=context.window;context.window.navigator=context.navigator;context.window.fetch=context.fetch;context.window.FormData=FormDataMock;
vm.runInNewContext(source,context,{filename:'admin/stem-command-bus-v159.js'});
const bus=context.window.StonefellowStemStudioV91;
assert(bus.__commandBusV159,'command bus installs over the verified shared bridge');
let result=await bus.executeAgentCommand({type:'v159_set_duration',measures:16});
assert(result.status==='success'&&result.verified&&studioState.duration_measures===16&&projectCalls[0].action==='update_project_duration','duration command verifies local and persistent state');
result=await bus.executeAgentCommand({type:'v159_track_state',action:'solo',stem_ids:[22],exclusive:true});
assert(result.status==='success'&&studioState.stems[1].solo&&!studioState.stems[0].solo,'exclusive solo resolves through shared mixer state');
result=await bus.executeAgentCommand({type:'v159_clear_measures',stem_ids:[11],start_measure:1,end_measure:2});
assert(result.status==='success'&&result.verification==='non-ripple-arrangement','measure clearing returns a verified non-ripple receipt');
result=await bus.executeAgentCommand({type:'v159_create_empty_tracks',count:4,role:'Vocal',base_name:'Vocal',input_provider:'focusrite'});
const createCall=projectCalls.find(row=>row.action==='create_empty_tracks');const specs=JSON.parse(createCall.body.get('tracks_json'));
assert(result.status==='success'&&result.created.length===4&&new Set(specs.map(row=>row.input_channel)).size===4,'four empty vocal tracks receive four verified distinct Focusrite channels');
result=await bus.executeAgentCommand({type:'v159_save_as',name:'Verse balance'});
assert(result.status==='success'&&result.mix_id===44,'voice Save As creates a persistent named version');
result=await bus.executeAgentCommand({type:'v159_load_project',which:'recent'});
assert(result.status==='success'&&result.redirect.includes('track=8'),'voice project loading returns verified navigation');

console.log('STEM_COMMAND_BUS_V159=PASS');
