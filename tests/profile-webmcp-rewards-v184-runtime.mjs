import assert from 'node:assert/strict';
import {VP3ProfileWebMCPRuntimeV100,VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100} from '../profile-webmcp-v100.js';
class MC{constructor(){this.tools=new Map();}async registerTool(tool,{signal}={}){this.tools.set(tool.name,tool);signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}}
const name='vp3.loyalty.status.get',mc=new MC(),calls=[];
const rt=new VP3ProfileWebMCPRuntimeV100({documentObject:{modelContext:mc},sessionProof:'a'.repeat(64),fetchImpl:async(url,options)=>{calls.push({options,body:JSON.parse(options.body)});return {ok:true,status:200,async json(){return {ok:true,programs:[],count:0};}};}});
await rt.start({manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'demo',capabilities:{profile:true,rewards:true},allowed_tools:['vp3.profile.get',name],session:{authenticated:true,visitor_profile_known:false}});
assert.equal(mc.tools.has(name),true);
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100[name].annotations.readOnlyHint,true);
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100[name].annotations.consequentialHint,false);
await mc.tools.get(name).execute({},{});
assert.equal(calls[0].body.tool,name);
assert.equal(calls[0].options.credentials,'same-origin');
await rt.syncManifest({manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'demo',capabilities:{profile:true,rewards:false},allowed_tools:['vp3.profile.get'],session:{authenticated:false,visitor_profile_known:false}});
assert.equal(mc.tools.has(name),false);
rt.stop();
console.log('PROFILE_WEBMCP_REWARDS_V184_RUNTIME=PASS');
