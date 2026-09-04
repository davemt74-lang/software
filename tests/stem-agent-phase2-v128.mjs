import fs from 'node:fs';
import vm from 'node:vm';

const read=p=>fs.readFileSync(p,'utf8');
const source=read('admin/stem-advanced-tools-v128.js');
const wrapper=read('admin/stems.php');
const planner=read('includes/agent-tools-v91.php');
const phase1=read('tests/stem-agent-phase1-v127.mjs');
const matrix=read('docs/STEM_AGENT_TOOL_MATRIX_V128.md');

function assert(ok,label){console.log(`${ok?'PASS':'FAIL'} ${label}`);if(!ok)throw new Error(`Failed: ${label}`);}

const groups={
  'advanced tool inventory':[
    source.includes("'track_trim','send','route','plugin_picker','plugin_param','plugin_bypass','plugin_remove','aux_return'"),
    source.includes("'automation_point','automation_delete','automation_clear'"),
    source.includes("'clip_move','clip_trim','clip_gain','clip_fade','clip_mute','clip_split','clip_delete'"),
    source.includes("'loop_set','loop_clear','marker_add','region_add','reset_mix','zoom','snap'"),
    planner.includes('plugin_picker')&&planner.includes('automation_delete')&&planner.includes('clip_split'),
  ],
  'dedicated plugin and mix verification':[
    source.includes("label='stem.trim'"),
    source.includes("label=`stem.send_"),
    source.includes("label='stem.route'"),
    source.includes("label='stem.plugins.added'"),
    source.includes("label='plugin.param'"),
    source.includes("label='plugin.enabled'"),
    source.includes("label='stem.plugins.removed-exact'"),
    source.includes('exactPluginRemoval'),
    source.includes("label=`master.aux_"),
    source.includes("label='mix.reset-observed'"),
    source.includes("'mix.reset-no-change'"),
    source.includes('pluginPickerFallback'),
  ],
  'dedicated automation and arrangement verification':[
    source.includes("label='automation.point'"),
    source.includes("label='automation.delete-exact'"),
    source.includes('exactAutomationDelete'),
    source.includes("label='automation.clear'"),
    source.includes("label='clip.start'"),
    source.includes("label='clip.gain_db'"),
    source.includes("label=`clip.fade_"),
    source.includes("label='clip.muted'"),
    source.includes("label='clip.trim'"),
    source.includes("label='clip.split-exact'"),
    source.includes('exactClipSplit'),
    source.includes("label='clip.deleted'"),
    source.includes("label='loop.range'"),
    source.includes("label='marker.added'"),
    source.includes("label='region.added'"),
    source.includes("label='timeline.zoom'"),
    source.includes("label='timeline.snap'"),
  ],
  'runtime and destructive safety':[
    wrapper.includes("$phase1Token = 'stem-tools-phase1-v127-20260826'"),
    wrapper.includes("$phase2Token = 'stem-tools-phase2-v128-20260826'"),
    wrapper.includes('admin/stem-tool-bridge-v127.js'),
    wrapper.includes('admin/stem-advanced-tools-v128.js'),
    wrapper.indexOf('$toolBridge . $advancedBridge')>=0,
    wrapper.includes("data-stonefellow-build=\"stem-tools-phase1-v127-20260826\""),
    wrapper.includes("data-stonefellow-build=\"stem-tools-phase2-v128-20260826\""),
    wrapper.includes('stonefellow:stem-advanced-tools'),
    planner.includes("if($type==='clip_delete')$x['requires_confirmation']=true"),
    planner.includes("if($type==='automation_clear')$x['requires_confirmation']=true"),
    planner.includes("if ($type==='plugin_remove')")&&planner.includes("'requires_confirmation'=>true"),
    planner.includes("if ($type==='reset_mix')")&&planner.includes("'requires_confirmation'=>true"),
    phase1.includes('STEM_PHASE1_EXECUTION=PASS'),
    matrix.includes('Phase 2 v128'),
  ],
};

let groupPass=0;
for(const [name,checks] of Object.entries(groups)){
  const ok=checks.every(Boolean);console.log(`${ok?'PASS':'FAIL'} ${name} (${checks.filter(Boolean).length}/${checks.length})`);if(!ok)throw new Error(`Failed group: ${name}`);groupPass++;
}

const state={
  selected_id:1,zoom:1,snap:'grid',
  stems:[{
    id:1,name:'Vocals',muted:true,solo:true,volume:.7,pan:0,trim:0,send_a:.1,send_b:.2,route:'direct',
    plugins:[
      {index:0,type:'compressor',enabled:true,params:{threshold:-18,ratio:2}},
      {index:1,type:'delay',enabled:true,params:{time:.3,feedback:.2}}
    ],
    automation:{volume:[{t:1,v:.6},{t:2,v:.7},{t:3,v:.8}],pan:[],auxA:[],auxB:[]}
  }],
  master:{volume:1,aux_a:.8,aux_b:.7,plugins:[]},
  clips:[{id:'clip-a',kind:'stem',stem_id:1,name:'Vocals',start:0,duration:10,source_start:0,source_end:10,gain_db:0,fade_in:0,fade_out:0,muted:false}],
  loop:{active:false,start:0,end:0},markers:[],regions:[],controls:[]
};
const clone=()=>structuredClone(state);
const pluginAdd={clicked:false};
const elements={inspectorAddPlugin:{click(){pluginAdd.clicked=true;}}};
const document={
  getElementById:id=>elements[id]||null,
  querySelector(selector){
    if(selector.includes('data-plugin-type="eq5"'))return{click(){if(pluginAdd.clicked)state.stems[0].plugins.push({index:state.stems[0].plugins.length,type:'eq5',enabled:true,params:{gain:0}});}};
    if(selector.includes('data-stem-id="1"'))return{querySelector(){return{click(){state.selected_id=1;}};},click(){state.selected_id=1;}};
    return null;
  }
};
let splitPosition=8;
const base={
  __toolTruthV127:true,
  getAgentState:()=>clone(),
  async executeAgentCommand(command){
    const s=state.stems[0];
    switch(command.type){
      case 'send':s.send_a=Number(command.value);return{status:'success',result:'send changed',verified:true};
      case 'track_trim':s.trim=Number(command.value);return{status:'success',result:'trim changed',verified:true};
      case 'route':s.route=String(command.route);return{status:'success',result:'route changed',verified:true};
      case 'plugin_picker':return{status:'unsupported',result:'legacy picker missing',verified:false};
      case 'plugin_param':s.plugins[Number(command.plugin_index||0)].params[String(command.param)]=Number(command.value);return{status:'success',result:'param changed',verified:true};
      case 'plugin_bypass':s.plugins[Number(command.plugin_index||0)].enabled=!Boolean(command.bypassed);return{status:'success',result:'bypass changed',verified:true};
      case 'plugin_remove':s.plugins.splice(Number(command.plugin_index||0),1);return{status:'success',result:'plugin removed',verified:true};
      case 'aux_return':state.master.aux_a=Number(command.value);return{status:'success',result:'aux changed',verified:true};
      case 'automation_point':s.automation.volume.push({t:Number(command.time),v:Number(command.value)});s.automation.volume.sort((a,b)=>a.t-b.t);return{status:'success',result:'automation added',verified:true};
      case 'automation_delete':s.automation.volume.splice(Number(command.index||0),1);return{status:'success',result:'automation deleted',verified:true};
      case 'automation_clear':s.automation.volume=[];return{status:'success',result:'automation cleared',verified:true};
      case 'clip_move':state.clips.find(c=>c.id===command.clip_id).start=Number(command.start);return{status:'success',result:'clip moved',verified:true};
      case 'clip_gain':state.clips.find(c=>c.id===command.clip_id).gain_db=Number(command.value);return{status:'success',result:'gain changed',verified:true};
      case 'clip_mute':state.clips.find(c=>c.id===command.clip_id).muted=Boolean(command.value);return{status:'success',result:'mute changed',verified:true};
      case 'clip_split':{
        const c=state.clips.find(x=>x.id===command.clip_id);const end=c.start+c.duration;const right={...structuredClone(c),id:'clip-b',start:splitPosition,duration:end-splitPosition,source_start:splitPosition};c.duration=splitPosition-c.start;c.source_end=splitPosition;state.clips.push(right);return{status:'success',result:'clip split',verified:true};
      }
      case 'loop_set':state.loop={active:true,start:Number(command.start),end:Number(command.end)};return{status:'success',result:'loop set',verified:true};
      case 'loop_clear':state.loop.active=false;return{status:'success',result:'loop cleared',verified:true};
      case 'marker_add':state.markers.push({time:Number(command.time),label:String(command.label)});return{status:'success',result:'marker added',verified:true};
      case 'region_add':state.regions.push({start:Number(command.start),end:Number(command.end),label:String(command.label)});return{status:'success',result:'region added',verified:true};
      case 'zoom':state.zoom=Number(command.value);return{status:'success',result:'zoom changed',verified:true};
      case 'snap':state.snap=String(command.value);return{status:'success',result:'snap changed',verified:true};
      case 'reset_mix':s.muted=false;s.solo=false;s.volume=.5;return{status:'success',result:'mix reset',verified:true};
      default:return{status:'unsupported',result:'not mocked',verified:false};
    }
  }
};
const window={
  StonefellowStemStudioV91:base,
  STONEFELLOW_STEM_STUDIO:{duration:100},
  STONEFELLOW_STUDIO_RUNTIME_V87:{getPosition:()=>splitPosition},
  dispatchEvent(){}
};
const context={window,document,CSS:{escape:String},CustomEvent:class CustomEvent{},setInterval,clearInterval,setTimeout,clearTimeout,structuredClone,console};
vm.runInNewContext(source,context,{filename:'stem-advanced-tools-v128.js'});
const tools=window.StonefellowStemStudioV91;
assert(Boolean(tools?.__advancedTruthV128),'v128 advanced bridge installs over v127');

let r=await tools.executeAgentCommand({type:'send',stem_id:1,bus:'a',value:.4});
assert(r.status==='success'&&r.verified&&r.verification==='stem.send_a','send is exactly verified');
r=await tools.executeAgentCommand({type:'plugin_param',stem_id:1,plugin_index:0,param:'threshold',value:-12});
assert(r.status==='success'&&r.verification==='plugin.param','plugin parameter is exactly verified');
r=await tools.executeAgentCommand({type:'plugin_picker',stem_id:1,plugin:'eq'});
assert(r.status==='success'&&r.verification==='stem.plugins.added'&&state.stems[0].plugins.some(p=>p.type==='eq5'),'missing plugin picker uses real UI fallback and verifies insertion');
r=await tools.executeAgentCommand({type:'plugin_remove',stem_id:1,plugin_index:1,requires_confirmation:true});
assert(r.status==='success'&&r.verification==='stem.plugins.removed-exact'&&state.stems[0].plugins.every(p=>p.type!=='delay'),'requested plugin removal is exactly verified');
r=await tools.executeAgentCommand({type:'automation_delete',stem_id:1,parameter:'volume',index:1});
assert(r.status==='success'&&r.verification==='automation.delete-exact'&&state.stems[0].automation.volume.map(p=>p.t).join(',')==='1,3','requested automation point removal is exactly verified');
r=await tools.executeAgentCommand({type:'clip_move',clip_id:'clip-a',start:4});
assert(r.status==='success'&&r.verification==='clip.start'&&state.clips[0].start===4,'clip move is exactly verified');
r=await tools.executeAgentCommand({type:'clip_split',clip_id:'clip-a'});
assert(r.status==='success'&&r.verification==='clip.split-exact'&&state.clips.length===2,'clip split geometry is verified around the playhead');
r=await tools.executeAgentCommand({type:'loop_set',start:2,end:8});
assert(r.status==='success'&&r.verification==='loop.range','loop range is exactly verified');
r=await tools.executeAgentCommand({type:'marker_add',time:5,label:'Chorus'});
assert(r.status==='success'&&r.verification==='marker.added','marker insertion is exactly verified');
r=await tools.executeAgentCommand({type:'region_add',start:10,end:15,label:'Fix vocal'});
assert(r.status==='success'&&r.verification==='region.added','region insertion is exactly verified');
r=await tools.executeAgentCommand({type:'reset_mix',requires_confirmation:true});
assert(r.status==='success'&&r.verification==='mix.reset-observed'&&!state.stems[0].muted&&!state.stems[0].solo,'reset mix requires an observable neutralizing change');
r=await tools.executeAgentCommand({type:'reset_mix',requires_confirmation:true});
assert(r.status==='no_change'&&r.verification==='mix.reset-no-change','repeat reset mix reports no_change rather than fake success');

function installFalseBridge(falseState,executor){
  const falseBase={__toolTruthV127:true,getAgentState:()=>structuredClone(falseState),executeAgentCommand:executor};
  const falseWindow={StonefellowStemStudioV91:falseBase,STONEFELLOW_STEM_STUDIO:{duration:100},STONEFELLOW_STUDIO_RUNTIME_V87:{getPosition:()=>8},dispatchEvent(){}};
  vm.runInNewContext(source,{...context,window:falseWindow},{filename:`stem-advanced-tools-v128-false-${Math.random()}.js`});
  return falseWindow.StonefellowStemStudioV91;
}

let falseState=clone();
let falseTools=installFalseBridge(falseState,async()=>({status:'success',result:'fake send success',verified:true}));
r=await falseTools.executeAgentCommand({type:'send',stem_id:1,bus:'a',value:.9});
assert(r.status==='failed'&&r.verified===false,'advanced fake success is rejected');

falseState=clone();
falseState.stems[0].plugins=[
  {index:0,type:'compressor',enabled:true,params:{threshold:-12}},
  {index:1,type:'delay',enabled:true,params:{time:.3}},
  {index:2,type:'eq5',enabled:true,params:{gain:0}}
];
falseTools=installFalseBridge(falseState,async command=>{falseState.stems[0].plugins.splice(0,1);return{status:'success',result:'wrong plugin removed',verified:true};});
r=await falseTools.executeAgentCommand({type:'plugin_remove',stem_id:1,plugin_index:1});
assert(r.status==='failed','removing the wrong plugin is rejected even when count decreases');

falseState=clone();
falseState.stems[0].automation.volume=[{t:1,v:.6},{t:2,v:.7},{t:3,v:.8}];
falseTools=installFalseBridge(falseState,async command=>{falseState.stems[0].automation.volume.splice(0,1);return{status:'success',result:'wrong point removed',verified:true};});
r=await falseTools.executeAgentCommand({type:'automation_delete',stem_id:1,parameter:'volume',index:1});
assert(r.status==='failed','deleting the wrong automation point is rejected');

console.log(`STEM_PHASE2_GROUPS=${groupPass}/${Object.keys(groups).length}`);
console.log('STEM_PHASE2_EXECUTION=PASS');
