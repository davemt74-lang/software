import assert from 'node:assert/strict';
import {VP3ProfileWebMCPRuntimeV100,VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100} from '../profile-webmcp-v100.js';
const names=['vp3.campaigns.list','vp3.campaign.get','vp3.campaign.eligibility.get'];
class MC{constructor(){this.tools=new Map();}async registerTool(tool,{signal}={}){this.tools.set(tool.name,tool);signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}}
const calls=[],mc=new MC();
const native=new VP3ProfileWebMCPRuntimeV100({documentObject:{modelContext:mc},sessionProof:'a'.repeat(64),fetchImpl:async(url,options)=>{calls.push(JSON.parse(options.body));return {ok:true,status:200,async json(){return {ok:true,campaigns:[]};}};}});
await native.start({manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'demo',capabilities:{profile:true,campaigns:true},allowed_tools:['vp3.profile.get',...names]});
for(const name of names){assert.equal(mc.tools.has(name),true);assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100[name].annotations.readOnlyHint,true);assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100[name].annotations.consequentialHint,false);}
await mc.tools.get('vp3.campaign.get').execute({campaign_slug:'summer'},{});
assert.equal(calls[0].tool,'vp3.campaign.get');
assert.deepEqual(calls[0].input,{campaign_slug:'summer'});

globalThis.location={href:'https://shop.example.com/'};
await import('../profile-webmcp-external-v120.js');
const {ExternalRuntime,CATALOG}=globalThis.VP3ProfileWebMCPExternalV120;
const externalCalls=[],externalMc=new MC();
const manifest={manifest_version:'vp3.profile.webmcp.v1',surface:'external_site',property_id:44,property_domain:'example.com',profile_username:'demo',capabilities:{profile:true,campaigns:true},allowed_tools:['vp3.profile.get',...names],session:{authenticated:false,visitor_profile_known:false},external:{read_only:true,stateful_profile_agent:false,transactional_actions:false,scheduling_enabled:false,commerce_enabled:false,campaigns_enabled:true,chat_grant_required:false}};
const external=new ExternalRuntime({documentObject:{modelContext:externalMc},endpoint:'https://vp3.example/api/profile-webmcp-external-v120.php',publicKey:'b'.repeat(40),fetchImpl:async(url,options)=>{externalCalls.push({url,options});if(options.method==='GET')return {ok:true,status:200,async json(){return {ok:true,manifest,chat_grant:'',chat_grant_expires_at:''};}};return {ok:true,status:200,async json(){return {ok:true};}};}});
await external.start();
for(const name of names){assert.equal(externalMc.tools.has(name),true);assert.equal(CATALOG[name].annotations.readOnlyHint,true);}
await externalMc.tools.get('vp3.campaigns.list').execute({},{});
assert.equal(JSON.parse(externalCalls[1].options.body).tool,'vp3.campaigns.list');
assert.equal(externalCalls[1].options.credentials,'omit');
assert.equal('Authorization' in externalCalls[1].options.headers,false);
await external.syncManifest({...manifest,external:{...manifest.external,campaigns_enabled:false}});
for(const name of names)assert.equal(externalMc.tools.has(name),false,'disabled Campaign tool removed '+name);
assert.equal(externalMc.tools.has('vp3.profile.get'),true);
native.stop();external.stop();
console.log('PROFILE_WEBMCP_CAMPAIGNS_V170_RUNTIME=PASS');
