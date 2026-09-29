import assert from 'node:assert/strict';
import {VP3ProfileWebMCPRuntimeV100,VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100} from '../profile-webmcp-v100.js';
const names=['vp3.campaign.participation.prepare','vp3.campaign.participation.confirm'];
class MC{constructor(){this.tools=new Map();}async registerTool(tool,{signal}={}){this.tools.set(tool.name,tool);signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}}

const calls=[],mc=new MC();
const native=new VP3ProfileWebMCPRuntimeV100({documentObject:{modelContext:mc},sessionProof:'a'.repeat(64),fetchImpl:async(url,options)=>{calls.push(JSON.parse(options.body));return {ok:true,status:200,async json(){return {ok:true,confirmation_required:true};}};}});
await native.start({manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'demo',capabilities:{profile:true,campaigns:true},allowed_tools:['vp3.profile.get',...names]});
for(const name of names)assert.equal(mc.tools.has(name),true,'native Campaign mutation tool '+name);
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100[names[0]].annotations.consequentialHint,false);
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100[names[1]].annotations.consequentialHint,true);
await mc.tools.get(names[0]).execute({campaign_slug:'summer',name:'Guest',email:'guest@example.com',marketing_consent:true},{});
assert.equal(calls[0].tool,names[0]);
assert.equal(calls[0].input.email,'guest@example.com');

globalThis.location={href:'https://shop.example.com/?vp3_ref='+('d'.repeat(48))};
await import('../profile-webmcp-external-v120.js');
const {ExternalRuntime,CATALOG}=globalThis.VP3ProfileWebMCPExternalV120;
const extCalls=[],extMc=new MC();
const manifest={manifest_version:'vp3.profile.webmcp.v1',surface:'external_site',property_id:44,property_domain:'example.com',profile_username:'demo',capabilities:{profile:true,campaigns:true},allowed_tools:['vp3.profile.get',...names],session:{authenticated:false,visitor_profile_known:false},external:{read_only:false,stateful_profile_agent:false,transactional_actions:true,scheduling_enabled:false,commerce_enabled:false,campaigns_enabled:true,chat_grant_required:false}};
const external=new ExternalRuntime({documentObject:{modelContext:extMc},endpoint:'https://vp3.example/api/profile-webmcp-external-v120.php',publicKey:'b'.repeat(40),fetchImpl:async(url,options)=>{extCalls.push({url,options});if(options.method==='GET')return {ok:true,status:200,async json(){return {ok:true,manifest,chat_grant:'',chat_grant_expires_at:''};}};return {ok:true,status:200,async json(){return {ok:true};}};}});
await external.start();
for(const name of names)assert.equal(extMc.tools.has(name),true,'external Campaign mutation tool '+name);
assert.equal(CATALOG[names[1]].annotations.consequentialHint,true);
await extMc.tools.get(names[1]).execute({confirmation_token:'x'.repeat(40),idempotency_key:'campaign_idem_123',intent:{campaign_id:9}},{});
const req=extCalls[1];
const body=JSON.parse(req.options.body);
assert.equal(body.tool,names[1]);
assert.equal(req.options.credentials,'omit');
assert.equal('Authorization' in req.options.headers,false);
assert.equal('chat_grant' in body,false,'Campaign mutation never borrows Profile Agent chat authority');
assert.equal(body.telemetry.agent_referral,'d'.repeat(48),'Section 4 Agent Radar referral continuity remains attached');
await external.syncManifest({...manifest,external:{...manifest.external,campaigns_enabled:false}});
for(const name of names)assert.equal(extMc.tools.has(name),false,'disabled Campaign mutation tool removed '+name);
native.stop();external.stop();
console.log('PROFILE_WEBMCP_CAMPAIGNS_V171_RUNTIME=PASS');
