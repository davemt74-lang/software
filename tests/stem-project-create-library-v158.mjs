import fs from 'node:fs';
import vm from 'node:vm';

const moduleSource=fs.readFileSync('admin/stem-project-agent-v158.js','utf8');
const agentSource=fs.readFileSync('admin/stem-agent-v131.js','utf8');
const apiSource=fs.readFileSync('api/stem-agent-v105.php','utf8');
const projectApiSource=fs.readFileSync('api/studio-project-v77.php','utf8');
const entrySource=fs.readFileSync('admin/stems.php','utf8');
const assert=(ok,label)=>{console.log(`${ok?'PASS':'FAIL'} ${label}`);if(!ok)throw new Error(`Failed: ${label}`);};

assert(apiSource.includes("'type'=>'v158_create_library_project'")&&apiSource.includes("$roles[]='drum'")&&apiSource.includes("$roles[]='bass'")&&apiSource.includes("$roles[]='vocal'"),'compound request maps to one deterministic new-project command');
assert(projectApiSource.includes("'tempo_bpm'=>$tempo")&&projectApiSource.includes("'project_name'=>$name"),'project creation returns verifiable tempo and identity');
assert(agentSource.includes("type==='v158_create_library_project'")&&agentSource.includes('StonefellowStemProjectAgentV158'),'active agent executes the dedicated project tool');
assert(agentSource.includes("result.status==='success'&&result.verified&&(result.redirect||result.raw?.redirect)")&&agentSource.includes("target.searchParams.set('conversation_id'")&&agentSource.includes("target.searchParams.set('voice','1')"),'navigation happens only after verified success and preserves conversation mode');
assert(entrySource.includes('stem-project-agent-v158.js')&&entrySource.includes('stem-project-library-v158-20260829'),'Stem Studio loads and cache-busts the v158 project tool');

const card=(id,role,name,search='')=>({dataset:{libraryStemId:String(id),libraryRole:role,libraryCategory:role.toLowerCase(),libraryName:name,librarySearch:search||`${role} ${name}`}});
let cards=[
  card(22,'Bass','Low Bass','drums and bass low bass'),
  card(11,'Drums','Live Drum Kit'),
  card(33,'Vocals','Lead Vocal'),
  card(44,'Bass','Synth Bass')
];
const calls=[];let scenario='success';let nextStemId=1000;
const document={querySelectorAll(selector){return selector==='[data-library-card]'?cards:[];}};
const fetch=async(url,init={})=>{
  const data=Object.fromEntries(init.body.entries());calls.push(data);
  if(data.action==='create_project')return new Response(JSON.stringify({ok:true,track_id:900,project_name:data.project_name,tempo_bpm:Number(data.tempo_bpm),redirect:'/admin/stems.php?track=900'}),{status:200,headers:{'Content-Type':'application/json'}});
  if(data.action==='add_library_stem'){
    if(scenario==='fail-bass'&&data.source_stem_id==='22')return new Response(JSON.stringify({ok:false,error:'Bass media copy failed.'}),{status:500,headers:{'Content-Type':'application/json'}});
    return new Response(JSON.stringify({ok:true,stem_id:nextStemId++,source_stem_id:Number(data.source_stem_id)}),{status:200,headers:{'Content-Type':'application/json'}});
  }
  if(data.action==='delete_project')return new Response(JSON.stringify({ok:true}),{status:200,headers:{'Content-Type':'application/json'}});
  throw new Error(`Unexpected action ${data.action}`);
};
const window={STONEFELLOW_STEM_STUDIO:{trackId:77,projectEndpoint:'/api/studio-project-v77.php',csrf:'csrf'}};
vm.runInNewContext(moduleSource,{window,document,fetch,FormData,Response,console},{filename:'admin/stem-project-agent-v158.js'});
const tool=window.StonefellowStemProjectAgentV158;

const selection=tool.selectRoles(['drum','bass','vocal']);
assert(selection.ok&&selection.selected.map(item=>item.source_stem_id).join(',')==='11,22,33','role matching selects one exact drum, bass, and vocal source');
assert(new Set(selection.selected.map(item=>item.source_stem_id)).size===3,'role matching never reuses one library source');

const created=await tool.createProject({project_name:'Untitled Project',tempo_bpm:103,time_signature:'4/4',library_roles:['drum','bass','vocal']});
assert(created.status==='success'&&created.verified&&created.track_id===900,'new project is server-created and verified');
assert(created.tempo_bpm===103&&created.added.map(item=>item.role).join(',')==='drum,bass,vocal','verified project contains the requested roles at 103 BPM');
assert(calls.map(call=>call.action).join(',')==='create_project,add_library_stem,add_library_stem,add_library_stem','project creation occurs before all three library inserts');
assert(calls.slice(1).every(call=>Number(call.track_id)===900)&&calls.slice(1).every(call=>Number(call.track_id)!==77),'all samples go only to the newly created project');
assert(new Set(calls.slice(1).map(call=>Number(call.source_stem_id))).size===3,'three inserts use three distinct source stems');

cards=[card(11,'Drums','Live Drum Kit'),card(22,'Bass','Low Bass')];calls.length=0;
const missing=await tool.createProject({tempo_bpm:103,library_roles:['drum','bass','vocal']});
assert(missing.status==='failed'&&!missing.verified&&calls.length===0,'missing requested role prevents project creation and partial edits');

cards=[card(11,'Drums','Live Drum Kit'),card(22,'Bass','Low Bass'),card(33,'Vocals','Lead Vocal')];calls.length=0;scenario='fail-bass';
const rolledBack=await tool.createProject({tempo_bpm:103,library_roles:['drum','bass','vocal']});
assert(rolledBack.status==='failed'&&!rolledBack.verified,'failed library insert cannot be reported as success');
assert(calls.at(-1)?.action==='delete_project'&&Number(calls.at(-1)?.track_id)===900,'incomplete new project is rolled back');

console.log('STEM_PROJECT_LIBRARY_V158=PASS');
