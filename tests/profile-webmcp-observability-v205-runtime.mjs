import assert from 'node:assert/strict';
import {VP3ProfileWebMCPRuntimeV100} from '../profile-webmcp-v100.js';

if(typeof globalThis.CustomEvent!=='function'){
  globalThis.CustomEvent=class CustomEvent extends Event{constructor(type,init={}){super(type);this.detail=init.detail;}};
}
class MC{constructor(){this.tools=new Map();}async registerTool(tool,{signal}={}){this.tools.set(tool.name,tool);signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}}
class Doc extends EventTarget{constructor(){super();this.modelContext=new MC();this.defaultView={CustomEvent:globalThis.CustomEvent};}}

const doc=new Doc(), calls=[];
const correlation='a'.repeat(32), returnToken='b'.repeat(32);
const fetchImpl=async(url,options)=>{
  const body=JSON.parse(options.body);calls.push({url:String(url),body});
  if(String(url).includes('continuity')){
    return {ok:true,status:200,async json(){return {ok:true,context:{contract:'vp3.webmcp.return.v1',correlation_id:correlation,phase:body.phase||'viewed'}};}};
  }
  if(body.tool==='vp3.intent.resolve')return {ok:true,status:200,async json(){return {ok:true,resolution:{recommended_capabilities:['commerce']}};}};
  if(body.tool==='vp3.commerce.products.list')return {ok:true,status:200,async json(){return {ok:true,products:[]};}};
  throw new Error('unexpected request');
};
const manifest={
  manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'demo',
  capabilities:{profile:true,commerce:true},allowed_tools:['vp3.intent.resolve','vp3.commerce.products.list']
};
const runtimeObj=new VP3ProfileWebMCPRuntimeV100({
  documentObject:doc,fetchImpl,sessionProof:'proof',
  continuityEndpoint:'/api/profile-webmcp-continuity-v195.php'
});
await runtimeObj.start(manifest);
const resume={
  contract:'vp3.webmcp.resume.v1',continuity_version:'profile-webmcp-continuity-v194-20260929',
  profile_username:'demo',goal:'Show products',recommended_capabilities:['commerce'],
  recommended_tools:[{name:'vp3.commerce.products.list',read_only:true,consequential:false}],
  resolved_capabilities:['commerce'],action_context_id:correlation,correlation_id:correlation,
  return_token:returnToken,return_path:'/chat.php?profile_webmcp_return='+returnToken,
  execution_allowed:true,auto_execute_consequential:false
};
const detail=await runtimeObj.resume(resume);
doc.dispatchEvent(new CustomEvent('vp3:webmcp-resume-continue',{detail}));
await new Promise(r=>setTimeout(r,0));
const productCall=calls.find(c=>c.body.tool==='vp3.commerce.products.list');
assert.ok(productCall,'resumed safe tool should execute');
assert.equal(productCall.body.telemetry.correlation_id,correlation,'resumed execution must preserve Agent handoff correlation');
assert.notEqual(productCall.body.telemetry.interaction_id,correlation,'individual interaction id remains distinct');
const intentCall=calls.find(c=>c.body.tool==='vp3.intent.resolve');
assert.ok(intentCall);
assert.match(intentCall.body.telemetry.correlation_id,/^[A-Za-z0-9_-]{8,96}$/);
assert.ok(calls.some(c=>String(c.url).includes('continuity')&&c.body.phase==='viewed'),'resume must publish viewed continuity state');
runtimeObj.stop();
console.log('PROFILE_WEBMCP_OBSERVABILITY_V205_RUNTIME=PASS');
