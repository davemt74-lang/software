import assert from 'node:assert/strict';
import {VP3ProfileWebMCPRuntimeV100} from '../profile-webmcp-v100.js';

class ModelContext{
  constructor(){this.tools=new Map();}
  async registerTool(tool,{signal}={}){this.tools.set(tool.name,tool);signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}
}
const nativeManifest={
  manifest_version:'vp3.profile.webmcp.v1',
  surface:'native_profile',
  profile_username:'demo',
  capabilities:{profile:true},
  allowed_tools:['vp3.profile.get'],
  protocol:{
    contract:'vp3.profile.webmcp.negotiation.v1',
    surface:'native_profile',
    supported_manifest_versions:['vp3.profile.webmcp.v1'],
    supported_release_versions:['profile-webmcp-release-v196-20260929'],
    current_runtime_build:'profile-webmcp-runtime-v100-20260928',
    downgrade_consequential_protection:false
  }
};
const nativeEvents=[];
const native=new VP3ProfileWebMCPRuntimeV100({
  documentObject:{modelContext:new ModelContext()},
  sessionProof:'proof',
  fetchImpl:async()=>({ok:true,status:200,async json(){return {ok:true,profile:{username:'demo'}};}}),
  onEvent:e=>nativeEvents.push(e)
});
await native.start(nativeManifest);
let d=native.diagnostics();
assert.equal(d.registration_count,1);
assert.equal(d.expected_registration_count,1);
assert.equal(d.registration_match,true);
assert.equal(d.protocol_compatible,true);
await native.registrations.get('vp3.profile.get').controller.signal;
await native.documentObject.modelContext.tools.get('vp3.profile.get').execute({},{});
d=native.diagnostics();
assert.equal(d.last_success,'vp3.profile.get');
assert.equal(typeof d.updated_at,'number');
assert.equal('input' in d,false);
assert.equal('confirmation_token' in d,false);

await import('../profile-webmcp-external-v120.js');
const {ExternalRuntime}=globalThis.VP3ProfileWebMCPExternalV120;
const externalManifest={
  manifest_version:'vp3.profile.webmcp.v1',
  surface:'external_site',
  property_id:7,
  profile_username:'demo',
  capabilities:{profile:true},
  allowed_tools:['vp3.profile.get'],
  session:{authenticated:false,visitor_profile_known:false},
  external:{read_only:true,stateful_profile_agent:false,transactional_actions:false,scheduling_enabled:false,commerce_enabled:false,campaigns_enabled:false},
  protocol:{
    contract:'vp3.profile.webmcp.negotiation.v1',
    surface:'external_site',
    supported_manifest_versions:['vp3.profile.webmcp.v1'],
    supported_release_versions:['profile-webmcp-release-v196-20260929'],
    current_runtime_build:'profile-webmcp-external-v120-20260928',
    downgrade_consequential_protection:false
  }
};
const key='a'.repeat(40);
const external=new ExternalRuntime({
  documentObject:{modelContext:new ModelContext()},
  endpoint:'https://vp3.example/api/profile-webmcp-external-v120.php',
  publicKey:key,
  fetchImpl:async(url,options)=>{
    if(options.method==='GET')return {ok:true,status:200,async json(){return {ok:true,manifest:externalManifest};}};
    return {ok:false,status:503,async json(){return {ok:false,error:{code:'TEMP_UNAVAILABLE',message:'temporary'}};}};
  }
});
await external.start();
let e=external.diagnostics();
assert.equal(e.registration_match,true);
await external.registrations.get('vp3.profile.get');
await external.documentObject.modelContext.tools.get('vp3.profile.get').execute({},{});
e=external.diagnostics();
assert.equal(e.last_failure,'vp3.profile.get');
assert.equal(e.last_code,'TEMP_UNAVAILABLE');
assert.equal('input' in e,false);

console.log('PROFILE_WEBMCP_HEALTH_V201_RUNTIME=PASS');
