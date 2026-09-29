import assert from 'node:assert/strict';
await import('../profile-webmcp-external-v120.js');
const {ExternalRuntime,CATALOG,propertyKey}=globalThis.VP3ProfileWebMCPExternalV120;

class ModelContext{
  constructor(){this.tools=new Map();}
  async registerTool(tool,{signal}={}){this.tools.set(tool.name,tool);signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}
}
const key='a'.repeat(40);
assert.equal(propertyKey(key),key);
assert.equal(propertyKey('not-a-key'),'');
assert.equal(Object.isFrozen(CATALOG),true);
assert.equal(Object.isFrozen(CATALOG['vp3.profile.get']),true);

const manifest={
  manifest_version:'vp3.profile.webmcp.v1',
  surface:'external_site',
  property_id:44,
  property_domain:'example.com',
  profile_username:'demo',
  capabilities:{profile:true,profile_agent:true,booking:true,commerce:true},
  allowed_tools:[
    'vp3.profile.capabilities.get','vp3.profile.get','vp3.intent.resolve','vp3.agent.get',
    'vp3.agent.message.send','vp3.booking.prepare'
  ],
  session:{authenticated:false,visitor_profile_known:false},
  external:{read_only:true,stateful_profile_agent:false}
};
const calls=[];
const mc=new ModelContext();
const fetchImpl=async(url,options)=>{
  calls.push({url,options});
  if(options.method==='GET')return {ok:true,status:200,async json(){return {ok:true,manifest};}};
  return {ok:true,status:200,async json(){return {ok:true,profile:{username:'demo'}};}};
};
const events=[];
const r=new ExternalRuntime({
  documentObject:{modelContext:mc},
  fetchImpl,
  endpoint:'https://vp3.example/api/profile-webmcp-external-v120.php',
  publicKey:key,
  onEvent:e=>events.push(e)
});
const started=await r.start();
assert.equal(started.supported,true);
assert.deepEqual(started.registered,['vp3.agent.get','vp3.intent.resolve','vp3.profile.capabilities.get','vp3.profile.get']);
assert.equal(mc.tools.has('vp3.agent.message.send'),false,'malicious/stateful manifest tool must be ignored');
assert.equal(mc.tools.has('vp3.booking.prepare'),false,'future transaction tool must be ignored');
assert.equal(calls[0].options.credentials,'omit');
assert.equal(calls[0].options.cache,'no-store');
assert.match(calls[0].url,new RegExp('key='+key));

const result=await mc.tools.get('vp3.profile.get').execute({},{});
assert.equal(result.ok,true);
assert.equal(calls[1].options.credentials,'omit');
assert.equal(calls[1].options.cache,'no-store');
assert.deepEqual(calls[1].options.headers,{'Content-Type':'application/json'});
assert.equal('Authorization' in calls[1].options.headers,false);
const body=JSON.parse(calls[1].options.body);
assert.deepEqual(Object.keys(body).sort(),['input','manifest_version','profile_username','property_id','surface','telemetry','tool']);
assert.match(body.telemetry.webmcp_session_id,/^[A-Za-z0-9_-]{8,96}$/);
assert.match(body.telemetry.interaction_id,/^[A-Za-z0-9_-]{8,96}$/);
assert.equal(body.telemetry.agent_referral,'');
assert.equal(body.surface,'external_site');
assert.equal(body.property_id,44);
assert.equal('key' in body,false,'public property key remains URL-scoped, not tool input');
assert.equal('telemetry' in body.input,false,'transport telemetry must stay outside tool input');
assert.ok(events.some(e=>e.event==='tool_called'));
assert.ok(events.some(e=>e.event==='tool_completed'));

const controller=new AbortController(); controller.abort();
const cancelled=await mc.tools.get('vp3.profile.get').execute({}, {signal:controller.signal});
assert.equal(cancelled.ok,false);
assert.equal(cancelled.error.code,'CANCELLED');

await r.syncManifest({...manifest,allowed_tools:['vp3.profile.get']});
assert.deepEqual([...mc.tools.keys()],['vp3.profile.get']);
assert.ok(events.some(e=>e.event==='tool_unregistered'));
r.stop();
assert.equal(mc.tools.size,0);

const unsupported=new ExternalRuntime({documentObject:{},fetchImpl,endpoint:'https://vp3.example/api/profile-webmcp-external-v120.php',publicKey:key});
const noSupport=await unsupported.start();
assert.equal(noSupport.supported,false);

console.log('PROFILE_WEBMCP_EXTERNAL_V120_RUNTIME=PASS');
