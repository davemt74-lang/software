import fs from 'node:fs';
import vm from 'node:vm';

const read=p=>fs.readFileSync(p,'utf8');
const bridgeSource=read('admin/stem-tool-bridge-v127.js');
const agentSource=read('admin/stem-agent-v127.js');
const wrapper=read('admin/stems.php');
const planner=read('includes/agent-tools-v91.php');
const api=read('api/stem-agent-v105.php');

function assert(ok,label){console.log(`${ok?'PASS':'FAIL'} ${label}`);if(!ok)throw new Error(`Failed: ${label}`);}

const groups={
  'tool result truth contract':[
    bridgeSource.includes("new Set(['success','failed','unsupported','no_change','cancelled'])"),
    bridgeSource.includes("verified:clean==='no_change'||Boolean(extra.verified)"),
    bridgeSource.includes("return result('unsupported',`Stonefellow does not have an executable"),
    bridgeSource.includes("was not confirmed by Studio state"),
    bridgeSource.includes("verification:'state-diff'"),
    bridgeSource.includes('desiredAlready(command,before)'),
    agentSource.includes("if(!value||typeof value!=='object'||Array.isArray(value))return{status:'unsupported'"),
    agentSource.includes("if(status==='success'&&!value.verified)return{status:'failed'"),
    agentSource.includes("I couldn’t complete that Studio action"),
    agentSource.includes("I can’t safely execute all of that in Stem Studio yet"),
  ],
  'core command execution coverage':[
    bridgeSource.includes("type==='play'||type==='pause'||type==='stop'"),
    bridgeSource.includes("if(type==='seek')return seekTo"),
    bridgeSource.includes("if(type==='save')return saveVerified()"),
    bridgeSource.includes("if(type==='tempo')"),
    bridgeSource.includes("['mute','unmute','solo','unsolo'].includes(type)"),
    bridgeSource.includes("if(type==='volume'||type==='pan')"),
    bridgeSource.includes("if(type==='master_volume')"),
    bridgeSource.includes("if(type==='bus_volume')"),
    bridgeSource.includes("if(type==='bus_mute')"),
    bridgeSource.includes("if(type==='metronome')"),
    bridgeSource.includes("if(type==='v105_metronome_volume')"),
    bridgeSource.includes("if(type==='monitor')"),
    bridgeSource.includes("if(type==='arm')"),
    bridgeSource.includes("if(type==='record')"),
    bridgeSource.includes("else if(type==='route')"),
  ],
  'post-command verification coverage':[
    bridgeSource.includes("label='playing'"),
    bridgeSource.includes("label='tempo'"),
    bridgeSource.includes("label='stem.muted'"),
    bridgeSource.includes("label='stem.solo'"),
    bridgeSource.includes("label='stem.volume'"),
    bridgeSource.includes("label='stem.pan'"),
    bridgeSource.includes("label='master.volume'"),
    bridgeSource.includes("label='bus.volume'"),
    bridgeSource.includes("label='bus.muted'"),
    bridgeSource.includes("label='metronome.enabled'"),
    bridgeSource.includes("label='monitoring'"),
    bridgeSource.includes("label='record-arm'"),
    bridgeSource.includes("label='recording'"),
    bridgeSource.includes("label='stem.route'"),
    bridgeSource.includes("label='playhead'"),
    bridgeSource.includes("verified:true,verification:'save-status'"),
  ],
  'live Stem integration':[
    wrapper.includes("$phase1Token = 'stem-tools-phase1-v127-20260826'"),
    wrapper.includes('admin/stem-tool-bridge-v127.js'),
    wrapper.includes("'admin/stem-agent-v127.js?v=stem-tools-phase1-v127-20260826' => 'admin/stem-agent-v131.js?v='"),
    wrapper.includes("data-stonefellow-build=\"stem-tools-phase1-v127-20260826\""),
    wrapper.includes("stonefellow:stem-tool-truth"),
    planner.includes('play,pause,save,save_as,tempo,reset_tempo'),
    api.includes("['type'=>'play']"),
    api.includes("['type'=>'pause']"),
    api.includes("['type'=>'record','requires_confirmation'=>true]"),
  ],
};

let groupPass=0;
for(const [name,checks] of Object.entries(groups)){
  const ok=checks.every(Boolean);console.log(`${ok?'PASS':'FAIL'} ${name} (${checks.filter(Boolean).length}/${checks.length})`);if(!ok)throw new Error(`Failed group: ${name}`);groupPass++;
}

// Executable synthetic Studio bridge test. It intentionally makes the legacy
// bridge claim success without changing state; v127 must reject that claim.
const state={
  playing:false,tempo:120,recording:false,monitoring:false,
  stems:[{id:1,name:'Vocals',muted:false,solo:false,volume:.5,pan:0,route:'direct'}],
  buses:[{key:'vocals',volume:1,muted:false}],master:{volume:1},metronome:{enabled:false,volume:.5},clips:[]
};
class ClassList{constructor(){this.s=new Set();}contains(v){return this.s.has(v);}add(v){this.s.add(v);}remove(v){this.s.delete(v);}toggle(v,on){if(on===undefined)on=!this.s.has(v);on?this.s.add(v):this.s.delete(v);return on;}}
const makeButton=click=>({disabled:false,classList:new ClassList(),attrs:{},click,getAttribute(k){return this.attrs[k]??null;},setAttribute(k,v){this.attrs[k]=String(v);}});
const playButton=makeButton(()=>{state.playing=!state.playing;});
const muteButton=makeButton(()=>{state.stems[0].muted=!state.stems[0].muted;});
const elements={stemPlayButton:playButton};
const document={
  getElementById:id=>elements[id]||null,
  querySelector:selector=>selector.includes('data-stem-mute')?muteButton:null,
};
const base={
  getAgentState:()=>structuredClone(state),
  async executeAgentCommand(command){
    if(command.type==='volume')return {status:'success',result:'Legacy claimed volume changed'};
    if(command.type==='ui_click')return {status:'success',result:'Legacy claimed UI changed'};
    if(command.type==='route'){state.stems[0].route=String(command.route);return {status:'success',result:'Legacy route changed'};}
    return null;
  }
};
const window={StonefellowStemStudioV91:base,dispatchEvent(){},STONEFELLOW_STUDIO_RUNTIME_V87:{getPosition:()=>0},STONEFELLOW_STEM_STUDIO:{duration:100,sourceTempo:120}};
const context={window,document,CSS:{escape:String},Event:class Event{constructor(type,opts){this.type=type;Object.assign(this,opts);}},MouseEvent:class MouseEvent{},CustomEvent:class CustomEvent{},setInterval,clearInterval,setTimeout,clearTimeout,structuredClone,console};
vm.runInNewContext(bridgeSource,context,{filename:'stem-tool-bridge-v127.js'});
const truth=window.StonefellowStemStudioV91;
assert(Boolean(truth?.__toolTruthV127),'v127 truth bridge installs');
let r=await truth.executeAgentCommand({type:'play'});
assert(r.status==='success'&&r.verified===true&&state.playing===true,'missing legacy play is executed and verified');
r=await truth.executeAgentCommand({type:'play'});
assert(r.status==='no_change'&&r.verified===true,'repeat play becomes no_change');
r=await truth.executeAgentCommand({type:'mute',stem_id:1});
assert(r.status==='success'&&r.verified===true&&state.stems[0].muted===true,'missing legacy mute is executed and verified');
r=await truth.executeAgentCommand({type:'volume',stem_id:1,value:.8});
assert(r.status==='failed'&&r.verified===false&&state.stems[0].volume===.5,'legacy core fake success is rejected when state did not change');
r=await truth.executeAgentCommand({type:'ui_click',control_id:'fake'});
assert(r.status==='failed'&&r.verified===false,'advanced legacy success without a state diff is rejected');
r=await truth.executeAgentCommand({type:'route',stem_id:1,route:'vocals'});
assert(r.status==='success'&&r.verified===true&&state.stems[0].route==='vocals','legacy real success passes after state verification');
r=await truth.executeAgentCommand({type:'not_a_real_studio_tool'});
assert(r.status==='unsupported'&&r.verified===false,'unknown command is unsupported, never success');

console.log(`STEM_PHASE1_GROUPS=${groupPass}/${Object.keys(groups).length}`);
console.log('STEM_PHASE1_EXECUTION=PASS');
