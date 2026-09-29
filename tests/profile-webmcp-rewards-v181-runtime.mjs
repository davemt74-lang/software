import assert from 'node:assert/strict';
import {VP3ProfileWebMCPRuntimeV100,VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100} from '../profile-webmcp-v100.js';
class MC{constructor(){this.tools=new Map();}async registerTool(tool,{signal}={}){this.tools.set(tool.name,tool);signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}}
const name='vp3.reward.get',mc=new MC(),calls=[];
const rt=new VP3ProfileWebMCPRuntimeV100({documentObject:{modelContext:mc},sessionProof:'a'.repeat(64),fetchImpl:async(url,options)=>{calls.push(JSON.parse(options.body));return {ok:true,status:200,async json(){return {ok:true,reward:{public_id:'r1'},credential_exposed:false};}};}});
await rt.start({manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'demo',capabilities:{profile:true,rewards:true},allowed_tools:['vp3.profile.get',name],session:{authenticated:true,visitor_profile_known:false}});
assert.equal(mc.tools.has(name),true);
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100[name].annotations.readOnlyHint,true);
await mc.tools.get(name).execute({reward_public_id:'r1'},{});
assert.equal(calls[0].tool,name);
assert.deepEqual(calls[0].input,{reward_public_id:'r1'});
await rt.syncManifest({manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'demo',capabilities:{profile:true,rewards:false},allowed_tools:['vp3.profile.get'],session:{authenticated:false,visitor_profile_known:false}});
assert.equal(mc.tools.has(name),false);
rt.stop();
console.log('PROFILE_WEBMCP_REWARDS_V181_RUNTIME=PASS');
