import assert from 'node:assert/strict';
import {VP3ProfileWebMCPRuntimeV100,VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100} from '../profile-webmcp-v100.js';
class MC{constructor(){this.tools=new Map();}async registerTool(tool,{signal}={}){this.tools.set(tool.name,tool);signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}}
const names=['vp3.rewards.transfer.contacts.list','vp3.rewards.transfer.prepare','vp3.rewards.transfer.confirm'];
const mc=new MC(),calls=[];
const rt=new VP3ProfileWebMCPRuntimeV100({
  documentObject:{modelContext:mc},
  sessionProof:'a'.repeat(64),
  fetchImpl:async(url,options)=>{calls.push({url,options,body:JSON.parse(options.body)});return {ok:true,status:200,async json(){return {ok:true};}};}
});
await rt.start({manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'demo',capabilities:{profile:true,rewards:true},allowed_tools:['vp3.profile.get','vp3.reward.get',...names],session:{authenticated:true,visitor_profile_known:false}});
for(const name of names)assert.equal(mc.tools.has(name),true,name+' registered');
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100[names[0]].annotations.readOnlyHint,true);
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100[names[1]].annotations.consequentialHint,false);
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100[names[2]].annotations.consequentialHint,true);
await mc.tools.get(names[1]).execute({reward_public_id:'reward-1',recipient_public_id:'contact-1',note:'Enjoy'},{});
assert.equal(calls[0].body.tool,names[1]);
assert.deepEqual(calls[0].body.input,{reward_public_id:'reward-1',recipient_public_id:'contact-1',note:'Enjoy'});
assert.equal(calls[0].options.credentials,'same-origin');
await rt.syncManifest({manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'demo',capabilities:{profile:true,rewards:false},allowed_tools:['vp3.profile.get'],session:{authenticated:false,visitor_profile_known:false}});
for(const name of names)assert.equal(mc.tools.has(name),false,name+' removed when Rewards unavailable');
rt.stop();
console.log('PROFILE_WEBMCP_REWARDS_V183_RUNTIME=PASS');
