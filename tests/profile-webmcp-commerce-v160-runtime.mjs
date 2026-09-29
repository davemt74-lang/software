import assert from 'node:assert/strict';
import {VP3ProfileWebMCPRuntimeV100,VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100} from '../profile-webmcp-v100.js';

const names=[
 'vp3.commerce.products.list','vp3.commerce.product.get','vp3.commerce.checkout.prepare','vp3.commerce.checkout.confirm',
 'vp3.commerce.order.get','vp3.commerce.receipt.get','vp3.commerce.delivery.get','vp3.commerce.refund.status',
 'vp3.commerce.refund.prepare','vp3.commerce.refund.confirm'
];
class MC{
 constructor(){this.tools=new Map();}
 async registerTool(tool,{signal}={}){this.tools.set(tool.name,tool);signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}
}
const nativeCalls=[],nativeMc=new MC();
const native=new VP3ProfileWebMCPRuntimeV100({
 documentObject:{modelContext:nativeMc},sessionProof:'a'.repeat(64),
 fetchImpl:async(url,options)=>{nativeCalls.push(JSON.parse(options.body));return {ok:true,status:200,async json(){return {ok:true,confirmation_required:true};}};}
});
await native.start({manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'demo',capabilities:{profile:true,commerce:true},allowed_tools:['vp3.profile.get',...names]});
for(const name of names)assert.equal(nativeMc.tools.has(name),true,'native commerce tool '+name);
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100['vp3.commerce.checkout.confirm'].annotations.consequentialHint,true);
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100['vp3.commerce.refund.confirm'].annotations.consequentialHint,true);
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100['vp3.commerce.checkout.prepare'].annotations.consequentialHint,false);
await nativeMc.tools.get('vp3.commerce.checkout.confirm').execute({confirmation_token:'x'.repeat(40),idempotency_key:'commerce_idem_1234',intent:{product_id:7},terms_accepted:true},{});
assert.equal(nativeCalls[0].tool,'vp3.commerce.checkout.confirm');
assert.equal(nativeCalls[0].input.terms_accepted,true);
assert.equal('price_cents' in nativeCalls[0].input,false,'runtime confirm does not manufacture price');

globalThis.location={href:'https://shop.example.com/?vp3_ref='+('c'.repeat(48))};
await import('../profile-webmcp-external-v120.js');
const {ExternalRuntime,CATALOG}=globalThis.VP3ProfileWebMCPExternalV120;
const externalCalls=[],externalMc=new MC();
const manifest={
 manifest_version:'vp3.profile.webmcp.v1',surface:'external_site',property_id:44,property_domain:'example.com',profile_username:'demo',
 capabilities:{profile:true,profile_agent:false,booking:false,commerce:true},allowed_tools:['vp3.profile.get',...names],
 session:{authenticated:false,visitor_profile_known:false},
 external:{read_only:false,stateful_profile_agent:false,transactional_actions:true,scheduling_enabled:false,commerce_enabled:true,chat_grant_required:false}
};
const external=new ExternalRuntime({
 documentObject:{modelContext:externalMc},endpoint:'https://vp3.example/api/profile-webmcp-external-v120.php',publicKey:'b'.repeat(40),
 fetchImpl:async(url,options)=>{
   externalCalls.push({url,options});
   if(options.method==='GET')return {ok:true,status:200,async json(){return {ok:true,manifest,chat_grant:'',chat_grant_expires_at:''};}};
   return {ok:true,status:200,async json(){return {ok:true};}};
 }
});
await external.start();
for(const name of names)assert.equal(externalMc.tools.has(name),true,'external commerce tool '+name);
assert.equal(CATALOG['vp3.commerce.checkout.confirm'].annotations.consequentialHint,true);
await externalMc.tools.get('vp3.commerce.checkout.prepare').execute({product_slug:'service',payer_email:'buyer@example.com'},{});
const body=JSON.parse(externalCalls[1].options.body);
assert.equal(body.tool,'vp3.commerce.checkout.prepare');
assert.equal('chat_grant' in body,false,'commerce tools do not use Profile Agent chat grants');
assert.equal(externalCalls[1].options.credentials,'omit');
assert.equal('Authorization' in externalCalls[1].options.headers,false);
assert.equal(body.telemetry.agent_referral,'c'.repeat(48),'existing first-party referral lineage remains attached');

await external.syncManifest({...manifest,external:{...manifest.external,commerce_enabled:false}});
for(const name of names)assert.equal(externalMc.tools.has(name),false,'disabled commerce tool removed '+name);
assert.equal(externalMc.tools.has('vp3.profile.get'),true);

native.stop();external.stop();
console.log('PROFILE_WEBMCP_COMMERCE_V160_RUNTIME=PASS');
