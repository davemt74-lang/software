import assert from 'node:assert/strict';
import {VP3ProfileWebMCPRuntimeV100} from '../profile-webmcp-v100.js';
if(typeof globalThis.CustomEvent!=='function')globalThis.CustomEvent=class CustomEvent extends Event{constructor(type,init={}){super(type);this.detail=init.detail;}};
class ModelContext{constructor(){this.tools=new Map();}async registerTool(tool,options={}){this.tools.set(tool.name,tool);options.signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});}}
class Doc extends EventTarget{constructor(){super();this.modelContext=new ModelContext();this.defaultView={CustomEvent:globalThis.CustomEvent};}}
const doc=new Doc();const calls=[];const returnEvents=[];
doc.addEventListener('vp3:webmcp-return-ready',e=>returnEvents.push(e.detail));
const fetchImpl=async(url,options)=>{
  const body=JSON.parse(options.body);calls.push({url,body});
  if(String(url).includes('continuity'))return {ok:true,status:200,async json(){return {ok:true,context:{contract:'vp3.webmcp.return.v1',phase:body.phase}};}};
  if(body.tool==='vp3.intent.resolve')return {ok:true,status:200,async json(){return {ok:true,resolution:{recommended_capabilities:['commerce']}};}};
  if(body.tool==='vp3.commerce.products.list')return {ok:true,status:200,async json(){return {ok:true,products:[]};}};
  if(body.tool==='vp3.commerce.checkout.confirm')return {ok:true,status:200,async json(){return {ok:true,action:{contract:'vp3.webmcp.action.v1',phase:'completed',confirm_tool:'vp3.commerce.checkout.confirm',idempotent_replay:true}};}};
  throw new Error('unexpected '+body.tool);
};
const manifest={manifest_version:'vp3.profile.webmcp.v1',surface:'native_profile',profile_username:'demo',capabilities:{profile:true,commerce:true},allowed_tools:['vp3.intent.resolve','vp3.commerce.products.list','vp3.commerce.checkout.confirm']};
const runtime=new VP3ProfileWebMCPRuntimeV100({documentObject:doc,fetchImpl,sessionProof:'proof',continuityEndpoint:'/api/profile-webmcp-continuity-v195.php'});
await runtime.start(manifest);
await runtime.resume({contract:'vp3.webmcp.resume.v1',profile_username:'demo',goal:'buy',recommended_capabilities:['commerce'],recommended_tools:[],resolved_capabilities:['commerce'],action_context_id:'a'.repeat(32),return_token:'b'.repeat(32),return_path:'/chat.php?profile_webmcp_return='+'b'.repeat(32)});
doc.dispatchEvent(new CustomEvent('vp3:webmcp-cancel',{detail:{action:{contract:'vp3.webmcp.action.v1',prepare_tool:'vp3.commerce.checkout.prepare'}}}));
await new Promise(r=>setTimeout(r,0));
assert.ok(calls.find(c=>String(c.url).includes('continuity')&&c.body.phase==='cancelled'),'cancel must update continuity');
await doc.modelContext.tools.get('vp3.commerce.checkout.confirm').execute({});
await new Promise(r=>setTimeout(r,0));
const note=calls.find(c=>String(c.url).includes('continuity')&&c.body.phase==='completed');
assert.ok(note,'completed action must update continuity');
assert.equal(note.body.idempotent_replay,true);
assert.equal(note.body.confirmation_token,undefined);
assert.equal(returnEvents.at(-1).phase,'completed');
console.log('PROFILE_WEBMCP_CONTINUITY_V195_RUNTIME=PASS');
