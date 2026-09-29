import assert from 'node:assert/strict';
import {VP3ProfileWebMCPRuntimeV100,VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100} from '../profile-webmcp-v100.js';
class MC{
 constructor(){this.tools=new Map();}
 async registerTool(tool,{signal}={}){this.tools.set(tool.name,tool);signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}
}
const mc=new MC(),calls=[];
const runtime=new VP3ProfileWebMCPRuntimeV100({
 documentObject:{modelContext:mc},
 sessionProof:'agent-proof',
 fetchImpl:async(url,options)=>{calls.push(JSON.parse(options.body));return {ok:true,status:200,async json(){return {ok:true,conversation_id:7001,answer:'Hello'};}};}
});
const manifest={
 manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'demo',
 capabilities:{profile:true,profile_agent:true},
 allowed_tools:['vp3.profile.get','vp3.agent.get','vp3.agent.conversation.get','vp3.agent.message.send','vp3.agent.owner_handoff.request']
};
const started=await runtime.start(manifest);
assert.deepEqual(started.registered,[
 'vp3.agent.conversation.get','vp3.agent.get','vp3.agent.message.send','vp3.agent.owner_handoff.request','vp3.profile.get'
]);
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100['vp3.agent.get'].annotations.readOnlyHint,true);
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100['vp3.agent.message.send'].annotations.readOnlyHint,false);
assert.equal(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100['vp3.agent.message.send'].annotations.consequentialHint,false);
const send=mc.tools.get('vp3.agent.message.send');
const result=await send.execute({conversation_id:7001,message:'What are your hours?'},{});
assert.equal(result.ok,true);
assert.deepEqual(calls[0].input,{conversation_id:7001,message:'What are your hours?'});
assert.equal('profile_token' in calls[0].input,false,'legacy transport token must not enter WebMCP tool input');
await runtime.syncManifest({...manifest,capabilities:{profile:true,profile_agent:false},allowed_tools:['vp3.profile.get']});
assert.equal(mc.tools.has('vp3.agent.message.send'),false,'agent tools must disappear with capability');
runtime.stop();
console.log('PROFILE_WEBMCP_AGENT_V110_RUNTIME=PASS');
