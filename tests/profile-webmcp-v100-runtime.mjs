import assert from 'node:assert/strict';
import {
  VP3ProfileWebMCPRuntimeV100,
  VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100
} from '../profile-webmcp-v100.js';

class ModelContext {
  constructor(){this.tools=new Map();this.calls=0;}
  async registerTool(tool,options={}){
    this.calls++;
    this.tools.set(tool.name,tool);
    options.signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});
  }
}
const manifest={
  manifest_version:'vp3.profile.webmcp.v1',
  surface:'native_profile',
  profile_username:'demo',
  capabilities:{profile:true,booking:true},
  allowed_tools:['vp3.profile.capabilities.get','vp3.profile.get','vp3.intent.resolve','vp3.evil.inject']
};

{
  const r=new VP3ProfileWebMCPRuntimeV100({documentObject:{}});
  const started=await r.start(manifest);
  assert.equal(started.supported,false);
}

const documentObject={modelContext:new ModelContext()};
const requests=[];
const fetchImpl=async(url,options)=>{
  requests.push({url,options,body:JSON.parse(options.body)});
  return {ok:true,status:200,async json(){return {ok:true,profile:{username:'demo'}};}};
};
const events=[];
const r=new VP3ProfileWebMCPRuntimeV100({
  documentObject,fetchImpl,endpoint:'/api/profile-webmcp-v100.php',sessionProof:'proof-123',onEvent:e=>events.push(e)
});
const started=await r.start(manifest);
assert.equal(started.supported,true);
assert.deepEqual(started.registered,['vp3.intent.resolve','vp3.profile.capabilities.get','vp3.profile.get']);
assert.equal(documentObject.modelContext.tools.has('vp3.evil.inject'),false,'unknown manifest tools must be ignored');
assert.equal(Object.isFrozen(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100),true);
assert.equal(Object.isFrozen(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100['vp3.profile.get']),true);

const profileTool=documentObject.modelContext.tools.get('vp3.profile.get');
assert.equal(profileTool.annotations.readOnlyHint,true);
assert.equal(profileTool.annotations.consequentialHint,false);
const result=await profileTool.execute({},{});
assert.equal(result.ok,true);
assert.equal(requests.length,1);
assert.equal(requests[0].options.headers['X-VP3-WebMCP-Session'],'proof-123');
assert.equal(requests[0].options.credentials,'same-origin');
assert.deepEqual(Object.keys(requests[0].body).sort(),['client_versions','input','manifest_version','profile_username','surface','telemetry','tool']);
assert.equal(requests[0].body.client_versions.negotiation_contract,'vp3.profile.webmcp.negotiation.v1');
assert.deepEqual(requests[0].body.client_versions.manifest_versions,['vp3.profile.webmcp.v1']);
assert.deepEqual(requests[0].body.client_versions.release_versions,['profile-webmcp-release-v196-20260929']);
assert.equal(requests[0].body.client_versions.runtime_build,'profile-webmcp-runtime-v100-20260928');
assert.match(requests[0].body.telemetry.webmcp_session_id,/^[A-Za-z0-9_-]{8,96}$/);
assert.match(requests[0].body.telemetry.interaction_id,/^[A-Za-z0-9_-]{8,96}$/);
assert.equal(requests[0].body.telemetry.agent_referral,'');
assert.equal('owner_user_id' in requests[0].body,false,'client must not assert owner authority');
assert.deepEqual(requests[0].body.input,{},'session proof must stay outside tool input');
assert.equal('telemetry' in requests[0].body.input,false,'transport telemetry must stay outside tool input');
assert.ok(events.some(e=>e.event==='tool_called'));
assert.ok(events.some(e=>e.event==='tool_completed'));

const initialCalls=documentObject.modelContext.calls;
await r.syncManifest({...manifest,capabilities:{profile:true,booking:false}});
assert.equal(documentObject.modelContext.calls,initialCalls,'unrelated capability changes must not churn trusted foundation tools');

await r.syncManifest({...manifest,allowed_tools:['vp3.profile.get']});
assert.deepEqual([...documentObject.modelContext.tools.keys()],['vp3.profile.get']);
assert.ok(events.some(e=>e.event==='tool_unregistered'));

const controller=new AbortController();
controller.abort();
const cancelled=await documentObject.modelContext.tools.get('vp3.profile.get').execute({}, {signal:controller.signal});
assert.equal(cancelled.ok,false);
assert.equal(cancelled.error.code,'CANCELLED');

r.stop();
assert.equal(documentObject.modelContext.tools.size,0);
console.log('PROFILE_WEBMCP_V100_RUNTIME=PASS');
