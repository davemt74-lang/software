import assert from 'node:assert/strict';

globalThis.location={href:'https://shop.example.com/menu?vp3_ref='+('c'.repeat(48))};
await import('../profile-webmcp-external-v120.js');
const {ExternalRuntime}=globalThis.VP3ProfileWebMCPExternalV120;

class MC{
  constructor(){this.tools=new Map();}
  async registerTool(tool,{signal}={}){this.tools.set(tool.name,tool);signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}
}
const manifest={
  manifest_version:'vp3.profile.webmcp.v1',surface:'external_site',property_id:44,property_domain:'example.com',profile_username:'demo',
  capabilities:{profile:true},allowed_tools:['vp3.profile.get'],session:{authenticated:false,visitor_profile_known:false},
  external:{read_only:true,stateful_profile_agent:false}
};
const calls=[],events=[];
const runtime=new ExternalRuntime({
  documentObject:{modelContext:new MC()},
  endpoint:'https://vp3.example/api/profile-webmcp-external-v120.php',
  publicKey:'a'.repeat(40),
  onEvent:e=>events.push(e),
  fetchImpl:async(url,options)=>{
    calls.push({url,options});
    if(options.method==='GET')return {ok:true,status:200,async json(){return {ok:true,manifest};}};
    return {ok:true,status:200,async json(){return {ok:true,profile:{username:'demo'}};}};
  }
});
await runtime.start();
const result=await runtime.documentObject.modelContext.tools.get('vp3.profile.get').execute({},{});
assert.equal(result.ok,true);
const body=JSON.parse(calls[1].options.body);
assert.match(body.telemetry.webmcp_session_id,/^[A-Za-z0-9_-]{8,96}$/);
assert.match(body.telemetry.interaction_id,/^[A-Za-z0-9_-]{8,96}$/);
assert.equal(body.telemetry.agent_referral,'c'.repeat(48));
assert.deepEqual(body.input,{});
assert.equal('agent_referral' in body.input,false);
assert.equal(calls[1].options.credentials,'omit');
assert.ok(events.some(e=>e.event==='tool_called'&&e.tool==='vp3.profile.get'));
assert.ok(events.some(e=>e.event==='tool_completed'&&e.tool==='vp3.profile.get'));

const firstSession=body.telemetry.webmcp_session_id;
await runtime.documentObject.modelContext.tools.get('vp3.profile.get').execute({},{});
const body2=JSON.parse(calls[2].options.body);
assert.equal(body2.telemetry.webmcp_session_id,firstSession,'runtime session id must remain stable in-memory');
assert.notEqual(body2.telemetry.interaction_id,body.telemetry.interaction_id,'each tool call needs a distinct interaction id');

runtime.stop();
console.log('PROFILE_WEBMCP_ANALYTICS_V130_RUNTIME=PASS');
