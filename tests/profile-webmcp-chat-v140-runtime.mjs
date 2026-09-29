import assert from 'node:assert/strict';

globalThis.location={href:'https://shop.example.com/menu'};
await import('../profile-webmcp-external-v120.js');
const {ExternalRuntime}=globalThis.VP3ProfileWebMCPExternalV120;

class MC{
  constructor(){this.tools=new Map();}
  async registerTool(tool,{signal}={}){this.tools.set(tool.name,tool);signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}
}
const allowed=[
  'vp3.profile.capabilities.get','vp3.profile.get','vp3.intent.resolve','vp3.agent.get',
  'vp3.agent.chat.start','vp3.agent.conversation.get','vp3.agent.message.send','vp3.agent.owner_handoff.request'
];
const manifest={
  manifest_version:'vp3.profile.webmcp.v1',surface:'external_site',property_id:44,property_domain:'example.com',profile_username:'demo',
  capabilities:{profile:true,profile_agent:true},allowed_tools:allowed,
  session:{authenticated:false,visitor_profile_known:false},
  external:{read_only:false,stateful_profile_agent:true,transactional_actions:false,chat_grant_required:true}
};
const key='a'.repeat(40), grant='signed.payload.signature';
const future=new Date(Date.now()+10*60*1000).toISOString();
const calls=[],events=[];
const mc=new MC();
const fetchImpl=async(url,options)=>{
  calls.push({url,options});
  if(options.method==='GET')return {ok:true,status:200,async json(){return {ok:true,manifest,chat_grant:grant,chat_grant_expires_at:future};}};
  const body=JSON.parse(options.body);
  if(body.tool==='vp3.agent.chat.start')return {ok:true,status:200,async json(){return {ok:true,conversation:{conversation_id:7001,status:'open'}};}};
  if(body.tool==='vp3.agent.message.send')return {ok:true,status:200,async json(){return {ok:true,conversation_id:7001,answer:'Hello'};}};
  return {ok:true,status:200,async json(){return {ok:true,agent:{name:'Profile Agent'}};}};
};
const r=new ExternalRuntime({
  documentObject:{modelContext:mc},fetchImpl,
  endpoint:'https://vp3.example/api/profile-webmcp-external-v120.php',publicKey:key,onEvent:e=>events.push(e)
});
const started=await r.start();
assert.equal(started.supported,true);
assert.deepEqual(started.registered,[...allowed].sort());
assert.match(calls[0].url,/session=[A-Za-z0-9_-]{8,96}/,'manifest request must bind the in-memory WebMCP session');
assert.equal(calls[0].options.credentials,'omit');

const profile=await mc.tools.get('vp3.profile.get').execute({},{});
assert.equal(profile.ok,true);
const profileBody=JSON.parse(calls[1].options.body);
assert.equal('chat_grant' in profileBody,false,'read-only tools must not carry chat grant');

const start=await mc.tools.get('vp3.agent.chat.start').execute({},{});
assert.equal(start.ok,true);
const startBody=JSON.parse(calls[2].options.body);
assert.equal(startBody.chat_grant,grant);
assert.equal(startBody.telemetry.webmcp_session_id,r.webmcpSessionId);
assert.deepEqual(startBody.input,{});
assert.equal('chat_grant' in startBody.input,false,'grant must stay outside tool input');
assert.equal('Authorization' in calls[2].options.headers,false);
assert.equal(calls[2].options.credentials,'omit');

const message=await mc.tools.get('vp3.agent.message.send').execute({conversation_id:7001,message:'Hello'},{});
assert.equal(message.ok,true);
const messageBody=JSON.parse(calls[3].options.body);
assert.equal(messageBody.chat_grant,grant);
assert.deepEqual(messageBody.input,{conversation_id:7001,message:'Hello'});
assert.notEqual(messageBody.telemetry.interaction_id,startBody.telemetry.interaction_id);

r.chatGrant='';
r.chatGrantExpiresAt=0;
await mc.tools.get('vp3.agent.message.send').execute({conversation_id:7001,message:'Refresh grant'},{});
const refreshGet=calls.find((call,index)=>index>=4&&call.options.method==='GET');
assert.ok(refreshGet,'expired/missing grant must refresh the manifest before chat');
assert.equal(r.chatGrant,grant);

const maliciousManifest={...manifest,external:{read_only:true,stateful_profile_agent:false,transactional_actions:false,chat_grant_required:false}};
const mc2=new MC();
const r2=new ExternalRuntime({
 documentObject:{modelContext:mc2},publicKey:key,endpoint:'https://vp3.example/api/profile-webmcp-external-v120.php',
 fetchImpl:async()=>({ok:true,status:200,async json(){return {ok:true,manifest:maliciousManifest,chat_grant:'',chat_grant_expires_at:''};}})
});
await r2.start();
assert.equal(mc2.tools.has('vp3.agent.message.send'),false,'manifest cannot register chat without chat authority and a grant');
assert.equal(mc2.tools.has('vp3.profile.get'),true);

r.stop();r2.stop();
assert.equal(mc.tools.size,0);
assert.equal(mc2.tools.size,0);
console.log('PROFILE_WEBMCP_CHAT_V140_RUNTIME=PASS');
