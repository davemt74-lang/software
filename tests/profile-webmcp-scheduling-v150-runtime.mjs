import assert from 'node:assert/strict';

const schedulingNames=[
 'vp3.booking.options.list','vp3.booking.availability.list','vp3.booking.prepare','vp3.booking.confirm','vp3.booking.get',
 'vp3.booking.reschedule.prepare','vp3.booking.reschedule.confirm','vp3.booking.cancel.prepare','vp3.booking.cancel.confirm'
];

const native=await import('../profile-webmcp-v100.js');
class MC{
 constructor(){this.tools=new Map();}
 async registerTool(tool,{signal}={}){this.tools.set(tool.name,tool);signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}
}
const nativeCalls=[];
const nativeMc=new MC();
const nativeRuntime=new native.VP3ProfileWebMCPRuntimeV100({
 documentObject:{modelContext:nativeMc},sessionProof:'a'.repeat(64),
 fetchImpl:async(url,options)=>{nativeCalls.push(JSON.parse(options.body));return {ok:true,status:200,async json(){return {ok:true,confirmation_required:true};}};}
});
const nativeManifest={
 manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'demo',
 capabilities:{profile:true,booking:true},allowed_tools:['vp3.profile.get',...schedulingNames]
};
await nativeRuntime.start(nativeManifest);
for(const name of schedulingNames)assert.equal(nativeMc.tools.has(name),true,'native scheduling tool '+name);
assert.equal(native.VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100['vp3.booking.confirm'].annotations.consequentialHint,true);
assert.equal(native.VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100['vp3.booking.prepare'].annotations.consequentialHint,false);
await nativeMc.tools.get('vp3.booking.confirm').execute({
 confirmation_token:'token'.repeat(8),idempotency_key:'idem_12345678',intent:{event_type_id:7}
},{});
assert.equal(nativeCalls[0].tool,'vp3.booking.confirm');
assert.equal(nativeCalls[0].input.idempotency_key,'idem_12345678');
assert.equal('chat_grant' in nativeCalls[0],false);

globalThis.location={href:'https://shop.example.com/'};
await import('../profile-webmcp-external-v120.js');
const {ExternalRuntime,CATALOG}=globalThis.VP3ProfileWebMCPExternalV120;
const externalCalls=[], externalMc=new MC();
const externalManifest={
 manifest_version:'vp3.profile.webmcp.v1',surface:'external_site',property_id:44,property_domain:'example.com',profile_username:'demo',
 capabilities:{profile:true,profile_agent:false,booking:true},
 allowed_tools:['vp3.profile.get',...schedulingNames],
 session:{authenticated:false,visitor_profile_known:false},
 external:{read_only:false,stateful_profile_agent:false,transactional_actions:true,scheduling_enabled:true,chat_grant_required:false}
};
const externalRuntime=new ExternalRuntime({
 documentObject:{modelContext:externalMc},endpoint:'https://vp3.example/api/profile-webmcp-external-v120.php',publicKey:'b'.repeat(40),
 fetchImpl:async(url,options)=>{
  externalCalls.push({url,options});
  if(options.method==='GET')return {ok:true,status:200,async json(){return {ok:true,manifest:externalManifest,chat_grant:'',chat_grant_expires_at:''};}};
  return {ok:true,status:200,async json(){return {ok:true,confirmation_required:true};}};
 }
});
await externalRuntime.start();
for(const name of schedulingNames)assert.equal(externalMc.tools.has(name),true,'external scheduling tool '+name);
assert.equal(externalMc.tools.has('vp3.agent.message.send'),false,'scheduling must not require or imply chat');
assert.equal(CATALOG['vp3.booking.confirm'].annotations.consequentialHint,true);
await externalMc.tools.get('vp3.booking.prepare').execute({event_type_id:7,start_at_utc:'2030-01-01 18:00:00',guest_name:'Guest',guest_email:'g@example.com'},{});
const prepareBody=JSON.parse(externalCalls[1].options.body);
assert.equal(prepareBody.tool,'vp3.booking.prepare');
assert.equal('chat_grant' in prepareBody,false,'scheduling prepare must not carry chat grant');
assert.equal(externalCalls[1].options.credentials,'omit');
await externalMc.tools.get('vp3.booking.confirm').execute({confirmation_token:'token'.repeat(8),idempotency_key:'idem_12345678',intent:{event_type_id:7}},{});
const confirmBody=JSON.parse(externalCalls[2].options.body);
assert.equal(confirmBody.tool,'vp3.booking.confirm');
assert.equal('chat_grant' in confirmBody,false,'scheduling confirm uses its signed confirmation token, not chat grant');
assert.equal('Authorization' in externalCalls[2].options.headers,false);

const disabled={...externalManifest,external:{...externalManifest.external,scheduling_enabled:false},allowed_tools:['vp3.profile.get',...schedulingNames]};
await externalRuntime.syncManifest(disabled);
for(const name of schedulingNames)assert.equal(externalMc.tools.has(name),false,'disabled scheduling tool removed '+name);
assert.equal(externalMc.tools.has('vp3.profile.get'),true);

nativeRuntime.stop();externalRuntime.stop();
console.log('PROFILE_WEBMCP_SCHEDULING_V150_RUNTIME=PASS');
