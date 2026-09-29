import assert from 'node:assert/strict';
import {VP3ProfileWebMCPRuntimeV100,VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100} from '../profile-webmcp-v100.js';
class MC{constructor(){this.tools=new Map();}async registerTool(tool,{signal}={}){this.tools.set(tool.name,tool);signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}}
const names=['vp3.rewards.transfer.contacts.list','vp3.reward.transfer.prepare','vp3.reward.transfer.confirm'];
const mc=new MC(),calls=[];
const rt=new VP3ProfileWebMCPRuntimeV100({documentObject:{modelContext:mc},sessionProof:'a'.repeat(64),fetchImpl:async(url,options)=>{calls.push(JSON.parse(options.body));return {ok:true,status:200,async json(){return {ok:true};}};}});
await rt.start({manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'demo',capabilities:{profile:true,rewards:true},allowed_tools:['vp3.profile.get',...names],session:{authenticated:true,visitor_profile_known:false}});
for(const name of names)assert.equal(mc.tools.has(name),true,'registered '+name);
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100['vp3.rewards.transfer.contacts.list'].annotations.readOnlyHint,true);
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100['vp3.reward.transfer.prepare'].annotations.consequentialHint,false);
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100['vp3.reward.transfer.confirm'].annotations.consequentialHint,true);
await mc.tools.get('vp3.reward.transfer.prepare').execute({reward_public_id:'reward-1',target_contact_id:9,note:'Enjoy'},{});
assert.equal(calls[0].tool,'vp3.reward.transfer.prepare');
assert.equal(calls[0].input.reward_public_id,'reward-1');
assert.equal(calls[0].input.target_contact_id,9);
await rt.syncManifest({manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'demo',capabilities:{profile:true,rewards:false},allowed_tools:['vp3.profile.get'],session:{authenticated:false,visitor_profile_known:false}});
for(const name of names)assert.equal(mc.tools.has(name),false,'removed '+name);
rt.stop();
console.log('PROFILE_WEBMCP_REWARDS_V182_RUNTIME=PASS');
