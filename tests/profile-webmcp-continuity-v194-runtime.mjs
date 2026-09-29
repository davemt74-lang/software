import assert from 'node:assert/strict';
import {VP3ProfileWebMCPRuntimeV100} from '../profile-webmcp-v100.js';

if(typeof globalThis.CustomEvent!=='function'){
  globalThis.CustomEvent=class CustomEvent extends Event{constructor(type,init={}){super(type);this.detail=init.detail;}};
}
class ModelContext{
  constructor(){this.tools=new Map();}
  async registerTool(tool,options={}){
    this.tools.set(tool.name,tool);
    options.signal?.addEventListener('abort',()=>this.tools.delete(tool.name),{once:true});
  }
}
class Doc extends EventTarget{
  constructor(){super();this.modelContext=new ModelContext();this.defaultView={CustomEvent:globalThis.CustomEvent};}
}

const doc=new Doc();
const calls=[];
const fetchImpl=async(_url,options)=>{
  const body=JSON.parse(options.body);calls.push(body);
  if(body.tool==='vp3.intent.resolve')return {ok:true,status:200,async json(){return {ok:true,resolution:{recommended_capabilities:['commerce'],execution_performed:false}};}};
  if(body.tool==='vp3.commerce.products.list')return {ok:true,status:200,async json(){return {ok:true,products:[{slug:'demo'}]};}};
  throw new Error('unexpected tool '+body.tool);
};
const manifest={
  manifest_version:'vp3.profile.webmcp.v1',
  surface:'native_profile',
  profile_username:'demo',
  capabilities:{profile:true,commerce:true},
  allowed_tools:['vp3.intent.resolve','vp3.profile.get','vp3.commerce.products.list','vp3.commerce.checkout.prepare','vp3.commerce.checkout.confirm']
};
const events=[];
const resumes=[];
const results=[];
doc.addEventListener('vp3:webmcp-resume',e=>resumes.push(e.detail));
doc.addEventListener('vp3:webmcp-resume-result',e=>results.push(e.detail));
const runtime=new VP3ProfileWebMCPRuntimeV100({documentObject:doc,fetchImpl,sessionProof:'proof',onEvent:e=>events.push(e)});
await runtime.start(manifest);

const resume={
  contract:'vp3.webmcp.resume.v1',
  continuity_version:'profile-webmcp-continuity-v194-20260929',
  profile_username:'demo',
  goal:'Show products on my profile',
  recommended_capabilities:['commerce'],
  recommended_tools:[
    {name:'vp3.commerce.checkout.confirm',read_only:false,consequential:true},
    {name:'vp3.commerce.products.list',read_only:true,consequential:false}
  ],
  resolved_capabilities:['commerce'],
  execution_allowed:true,
  auto_execute_consequential:false
};
await runtime.resume(resume);
assert.equal(calls[0].tool,'vp3.intent.resolve');
assert.equal(resumes.length,1);
assert.equal(resumes[0].auto_execute_consequential,false);
assert.ok(events.some(e=>e.event==='resume_ready'));

doc.dispatchEvent(new CustomEvent('vp3:webmcp-resume-continue',{detail:resumes[0]}));
await new Promise(resolve=>setTimeout(resolve,0));
assert.equal(calls.at(-1).tool,'vp3.commerce.products.list','Continue must choose safe read-only discovery');
assert.equal(calls.some(c=>c.tool==='vp3.commerce.checkout.confirm'),false,'resume must never auto-confirm');
assert.equal(calls.some(c=>c.tool==='vp3.commerce.checkout.prepare'),false,'resume must never auto-prepare');
assert.equal(results.at(-1).ok,true);

const before=calls.length;
runtime.stop();
doc.dispatchEvent(new CustomEvent('vp3:webmcp-resume-continue',{detail:resumes[0]}));
await new Promise(resolve=>setTimeout(resolve,0));
assert.equal(calls.length,before,'stop must remove resume listener');

console.log('PROFILE_WEBMCP_CONTINUITY_V194_RUNTIME=PASS');
