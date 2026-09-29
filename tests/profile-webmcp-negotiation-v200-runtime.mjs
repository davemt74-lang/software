import assert from 'node:assert/strict';
import {
  VP3ProfileWebMCPRuntimeV100,
  VP3_PROFILE_WEBMCP_NEGOTIATION_CONTRACT_V200,
  VP3_PROFILE_WEBMCP_RELEASE_V196
} from '../profile-webmcp-v100.js';

const goodProtocol={
  contract:VP3_PROFILE_WEBMCP_NEGOTIATION_CONTRACT_V200,
  surface:'native_profile',
  supported_manifest_versions:['vp3.profile.webmcp.v1'],
  supported_release_versions:[VP3_PROFILE_WEBMCP_RELEASE_V196],
  current_runtime_build:'profile-webmcp-runtime-v100-20260928',
  downgrade_consequential_protection:false
};
const baseManifest={
  manifest_version:'vp3.profile.webmcp.v1',
  surface:'native_profile',
  profile_username:'demo',
  capabilities:{profile:true},
  allowed_tools:['vp3.profile.get'],
  protocol:goodProtocol
};
class ModelContext{constructor(){this.tools=new Map();}async registerTool(tool,{signal}={}){this.tools.set(tool.name,tool);signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}}

const native=new VP3ProfileWebMCPRuntimeV100({documentObject:{modelContext:new ModelContext()},fetchImpl:async()=>{throw new Error('not called');},sessionProof:'proof'});
const started=await native.start(baseManifest);
assert.deepEqual(started.registered,['vp3.profile.get']);
await assert.rejects(
  ()=>native.syncManifest({...baseManifest,protocol:{...goodProtocol,current_runtime_build:'old-native-build'}}),
  /incompatible/i
);
native.stop();

await import('../profile-webmcp-external-v120.js');
const {ExternalRuntime}=globalThis.VP3ProfileWebMCPExternalV120;
const key='a'.repeat(40);
const externalManifest={
  manifest_version:'vp3.profile.webmcp.v1',
  surface:'external_site',
  property_id:1,
  profile_username:'demo',
  capabilities:{profile:true},
  allowed_tools:['vp3.profile.get'],
  external:{read_only:true,stateful_profile_agent:false,transactional_actions:false},
  protocol:{
    contract:'vp3.profile.webmcp.negotiation.v1',
    surface:'external_site',
    supported_manifest_versions:['vp3.profile.webmcp.v1'],
    supported_release_versions:['profile-webmcp-release-v196-20260929'],
    current_runtime_build:'old-external-build',
    downgrade_consequential_protection:false
  }
};
const external=new ExternalRuntime({
  documentObject:{modelContext:new ModelContext()},
  endpoint:'https://vp3.example/api/profile-webmcp-external-v120.php',
  publicKey:key,
  fetchImpl:async()=>({ok:true,status:200,async json(){return {ok:true,manifest:externalManifest};}})
});
await assert.rejects(()=>external.start(),/incompatible/i);

console.log('PROFILE_WEBMCP_NEGOTIATION_V200_RUNTIME=PASS');
