import assert from 'node:assert/strict';
import {VP3ProfileWebMCPRuntimeV100,VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100} from '../profile-webmcp-v100.js';
class MC{constructor(){this.tools=new Map();}async registerTool(tool,{signal}={}){this.tools.set(tool.name,tool);signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}}
const name='vp3.campaign.participation.get',mc=new MC(),calls=[];
const native=new VP3ProfileWebMCPRuntimeV100({documentObject:{modelContext:mc},sessionProof:'a'.repeat(64),fetchImpl:async(url,options)=>{calls.push(JSON.parse(options.body));return {ok:true,status:200,async json(){return {ok:true,participation:{status:'completed'}};}};}});
await native.start({manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'demo',capabilities:{profile:true,campaigns:true},allowed_tools:['vp3.profile.get',name]});
assert.equal(mc.tools.has(name),true);
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100[name].annotations.readOnlyHint,true);
await mc.tools.get(name).execute({participation_reference:'c'.repeat(32)},{});
assert.equal(calls[0].tool,name);

globalThis.location={href:'https://shop.example.com/?vp3_ref='+('e'.repeat(48))};
await import('../profile-webmcp-external-v120.js');
const {ExternalRuntime}=globalThis.VP3ProfileWebMCPExternalV120,emc=new MC(),ecalls=[];
const manifest={manifest_version:'vp3.profile.webmcp.v1',surface:'external_site',property_id:44,property_domain:'example.com',profile_username:'demo',capabilities:{profile:true,campaigns:true},allowed_tools:['vp3.profile.get',name],session:{authenticated:false,visitor_profile_known:false},external:{read_only:false,stateful_profile_agent:false,transactional_actions:true,scheduling_enabled:false,commerce_enabled:false,campaigns_enabled:true,chat_grant_required:false}};
const ext=new ExternalRuntime({documentObject:{modelContext:emc},endpoint:'https://vp3.example/api/profile-webmcp-external-v120.php',publicKey:'b'.repeat(40),fetchImpl:async(url,options)=>{ecalls.push({url,options});if(options.method==='GET')return {ok:true,status:200,async json(){return {ok:true,manifest,chat_grant:'',chat_grant_expires_at:''};}};return {ok:true,status:200,async json(){return {ok:true};}};}});
await ext.start();assert.equal(emc.tools.has(name),true);
await emc.tools.get(name).execute({participation_reference:'d'.repeat(32)},{});
assert.equal(ecalls[1].options.credentials,'omit');
assert.equal('Authorization' in ecalls[1].options.headers,false);
assert.equal(JSON.parse(ecalls[1].options.body).telemetry.agent_referral,'e'.repeat(48));
native.stop();ext.stop();
console.log('PROFILE_WEBMCP_CAMPAIGNS_V172_RUNTIME=PASS');
