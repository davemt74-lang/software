(function(global){
'use strict';

const BUILD='profile-webmcp-external-v120-20260928';
const MANIFEST='vp3.profile.webmcp.v1';

function deepFreeze(value){
  if(!value||typeof value!=='object'||Object.isFrozen(value))return value;
  Object.freeze(value);
  for(const child of Object.values(value))deepFreeze(child);
  return value;
}

const CATALOG=deepFreeze({
  'vp3.profile.capabilities.get':{
    title:'Get profile capabilities',
    description:'Return the currently available VP3 capabilities for this connected website.',
    inputSchema:{type:'object',properties:{},additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:false,consequentialHint:false,debugging:false}
  },
  'vp3.profile.get':{
    title:'Get public profile',
    description:'Return the public VP3 profile projection connected to this website.',
    inputSchema:{type:'object',properties:{},additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.intent.resolve':{
    title:'Resolve profile intent',
    description:'Identify which VP3 capabilities can help with a user goal without executing an action.',
    inputSchema:{type:'object',properties:{goal:{type:'string',minLength:1,maxLength:1000}},required:['goal'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.agent.get':{
    title:'Get Profile Agent',
    description:'Return the public VP3 Profile Agent identity connected to this website.',
    inputSchema:{type:'object',properties:{},additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.agent.chat.start':{
    title:'Start Profile Agent chat',
    description:'Start or resume an anonymous connected-site conversation with this Profile Agent.',
    inputSchema:{type:'object',properties:{},additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.agent.conversation.get':{
    title:'Get Profile Agent conversation',
    description:'Return one connected-site conversation bound to this exact WebMCP site session.',
    inputSchema:{type:'object',properties:{conversation_id:{type:'integer',minimum:1}},required:['conversation_id'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.agent.message.send':{
    title:'Send message to Profile Agent',
    description:'Send a message through the canonical Profile Agent conversation boundary.',
    inputSchema:{type:'object',properties:{conversation_id:{type:'integer',minimum:1},message:{type:'string',minLength:1,maxLength:2000}},required:['message'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.agent.owner_handoff.request':{
    title:'Request profile owner assistance',
    description:'Ask the profile owner for assistance with this exact connected-site conversation.',
    inputSchema:{type:'object',properties:{conversation_id:{type:'integer',minimum:1},reason:{type:'string',maxLength:1000}},required:['conversation_id'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  }
});

function safeError(code,message,retryable=false){
  return {ok:false,error:{code,message,retryable}};
}

function transportIdV130(){
  try{if(global.crypto?.randomUUID)return global.crypto.randomUUID().replaceAll('-','');}catch{}
  try{
    const bytes=new Uint8Array(16);global.crypto?.getRandomValues?.(bytes);
    const value=[...bytes].map(v=>v.toString(16).padStart(2,'0')).join('');
    if(value.length===32)return value;
  }catch{}
  return (Date.now().toString(36)+Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2)).slice(0,64);
}

function referralTokenV130(){
  try{
    const token=String(new URL(global.location?.href||'https://vp3.invalid/').searchParams.get('vp3_ref')||'').toLowerCase().trim();
    return /^[a-f0-9]{48}$/.test(token)?token:'';
  }catch{return '';}
}

function propertyKey(value){
  value=String(value||'').trim().toLowerCase();
  return /^[a-f0-9]{40}$/.test(value)?value:'';
}

function isChatToolV140(name){
  return [
    'vp3.agent.chat.start','vp3.agent.conversation.get',
    'vp3.agent.message.send','vp3.agent.owner_handoff.request'
  ].includes(String(name||''));
}

class ExternalRuntime{
  constructor({
    documentObject=global.document,
    fetchImpl=global.fetch?.bind(global),
    endpoint='',
    publicKey='',
    onEvent=()=>{}
  }={}){
    this.documentObject=documentObject;
    this.fetchImpl=fetchImpl;
    this.endpoint=String(endpoint||'');
    this.publicKey=propertyKey(publicKey);
    this.onEvent=onEvent;
    this.webmcpSessionId=transportIdV130();
    this.chatGrant='';
    this.chatGrantExpiresAt=0;
    this.manifest=null;
    this.registrations=new Map();
  }

  get supported(){
    return Boolean(this.documentObject?.modelContext?.registerTool);
  }

  gatewayUrl(){
    const u=new URL(this.endpoint,global.location?.href||'https://vp3.invalid/');
    u.searchParams.set('key',this.publicKey);
    u.searchParams.set('session',this.webmcpSessionId);
    return u.href;
  }

  effectiveToolNames(manifest=this.manifest){
    if(!manifest||manifest.manifest_version!==MANIFEST||manifest.surface!=='external_site')return [];
    if(!manifest.external||manifest.external.transactional_actions!==false)return [];
    const allowed=new Set(Array.isArray(manifest.allowed_tools)?manifest.allowed_tools:[]);
    return Object.keys(CATALOG).filter(name=>allowed.has(name)).sort();
  }

  async loadManifest(){
    if(!this.fetchImpl||!this.endpoint||!this.publicKey)throw new Error('External WebMCP configuration is invalid.');
    const response=await this.fetchImpl(this.gatewayUrl(),{
      method:'GET',
      credentials:'omit',
      cache:'no-store',
      headers:{Accept:'application/json'}
    });
    const data=await response.json().catch(()=>null);
    if(!response.ok||data?.ok!==true||!data?.manifest)throw new Error(data?.error?.message||'Connected-site WebMCP manifest could not be loaded.');
    if(data.manifest.manifest_version!==MANIFEST||data.manifest.surface!=='external_site')throw new Error('Connected-site WebMCP manifest is invalid.');
    this.manifest=data.manifest;
    this.chatGrant=String(data.chat_grant||'');
    const expires=Date.parse(String(data.chat_grant_expires_at||''));
    this.chatGrantExpiresAt=Number.isFinite(expires)?expires:0;
    return this.manifest;
  }

  async start(){
    if(!this.supported){
      this.onEvent({event:'unsupported'});
      return {supported:false,registered:[]};
    }
    await this.loadManifest();
    const registered=await this.syncManifest(this.manifest);
    this.onEvent({event:'ready',property_id:this.manifest.property_id,registered});
    return {supported:true,registered,manifest:this.manifest};
  }

  async syncManifest(manifest){
    this.manifest=structuredClone(manifest);
    const desired=new Set(this.effectiveToolNames(this.manifest));

    for(const [name,entry] of this.registrations){
      if(!desired.has(name)){
        entry.controller.abort();
        this.registrations.delete(name);
        this.onEvent({event:'tool_unregistered',tool:name});
      }
    }

    for(const name of desired){
      if(this.registrations.has(name))continue;
      const definition=CATALOG[name];
      const controller=new AbortController();
      try{
        await this.documentObject.modelContext.registerTool({
          name,
          title:definition.title,
          description:definition.description,
          inputSchema:definition.inputSchema,
          annotations:definition.annotations,
          execute:(args={},options={})=>this.execute(name,args,options)
        },{signal:controller.signal});
        this.registrations.set(name,{controller});
        this.onEvent({event:'tool_registered',tool:name});
      }catch(error){
        controller.abort();
        this.onEvent({event:'tool_registration_failed',tool:name});
      }
    }
    return [...this.registrations.keys()].sort();
  }

  async ensureChatGrantV140(){
    if(this.chatGrant&&this.chatGrantExpiresAt>Date.now()+30000)return true;
    await this.loadManifest();
    await this.syncManifest(this.manifest);
    return Boolean(this.chatGrant&&this.chatGrantExpiresAt>Date.now());
  }

  async execute(name,args={},options={}){
    if(options?.signal?.aborted)return safeError('CANCELLED','The connected-site capability request was cancelled.');
    if(!this.fetchImpl||!this.manifest||!this.publicKey)return safeError('RUNTIME_UNAVAILABLE','Connected-site WebMCP is unavailable.');
    if(!this.effectiveToolNames().includes(name))return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    if(isChatToolV140(name)){
      try{
        if(!await this.ensureChatGrantV140())return safeError('CHAT_GRANT_REQUIRED','Connected-site Profile Agent chat is unavailable.');
      }catch{
        return safeError('CHAT_GRANT_REQUIRED','Connected-site Profile Agent chat is unavailable.',true);
      }
    }

    const interactionId=transportIdV130();
    const startedAt=Date.now();
    try{
      this.onEvent({event:'tool_called',tool:name,interaction_id:interactionId});
      const response=await this.fetchImpl(this.gatewayUrl(),{
        method:'POST',
        credentials:'omit',
        cache:'no-store',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
          manifest_version:this.manifest.manifest_version,
          surface:'external_site',
          property_id:this.manifest.property_id,
          profile_username:this.manifest.profile_username,
          tool:name,
          input:args,
          chat_grant:isChatToolV140(name)?this.chatGrant:'',
          telemetry:{
            webmcp_session_id:this.webmcpSessionId,
            interaction_id:interactionId,
            agent_referral:referralTokenV130()
          }
        }),
        signal:options?.signal
      });
      const data=await response.json().catch(()=>null);
      if(!response.ok||data?.ok!==true){
        const error=safeError(data?.error?.code||('HTTP_'+response.status),data?.error?.message||'The connected-site capability could not be completed.',Boolean(data?.error?.retryable));
        this.onEvent({event:'tool_failed',tool:name,interaction_id:interactionId,duration_ms:Date.now()-startedAt,code:error.error.code});
        return error;
      }
      this.onEvent({event:'tool_completed',tool:name,interaction_id:interactionId,duration_ms:Date.now()-startedAt});
      return data;
    }catch(error){
      if(options?.signal?.aborted||error?.name==='AbortError'){
        this.onEvent({event:'tool_cancelled',tool:name,interaction_id:interactionId,duration_ms:Date.now()-startedAt});
        return safeError('CANCELLED','The connected-site capability request was cancelled.');
      }
      this.onEvent({event:'tool_failed',tool:name,interaction_id:interactionId,duration_ms:Date.now()-startedAt,code:'NETWORK_ERROR'});
      return safeError('NETWORK_ERROR','VP3 could not be reached.',true);
    }
  }

  stop(){
    for(const entry of this.registrations.values())entry.controller.abort();
    this.registrations.clear();
    this.onEvent({event:'runtime_stopped'});
  }
}

async function bootFromScript(script){
  if(!script)throw new Error('VP3 external WebMCP script element is unavailable.');
  const key=propertyKey(script.dataset?.vp3Key);
  if(!key)throw new Error('VP3 external WebMCP property key is invalid.');
  const src=new URL(script.src,global.location?.href||'https://vp3.invalid/');
  const endpoint=script.dataset?.vp3WebmcpEndpoint||new URL('/api/profile-webmcp-external-v120.php',src).href;
  const runtime=new ExternalRuntime({endpoint,publicKey:key});
  const result=await runtime.start();
  global.VP3_PROFILE_WEBMCP_EXTERNAL={
    build:BUILD,
    supported:result.supported,
    property_id:runtime.manifest?.property_id||null,
    manifest_version:runtime.manifest?.manifest_version||MANIFEST,
    registered:result.registered||[],
    read_only:Boolean(runtime.manifest?.external?.read_only),
    chat_enabled:Boolean(runtime.manifest?.external?.stateful_profile_agent),
    runtime
  };
  try{
    global.dispatchEvent?.(new CustomEvent('vp3:webmcp-ready',{detail:{property_id:runtime.manifest?.property_id||null,registered:result.registered||[]}}));
  }catch{}
  return runtime;
}

global.VP3ProfileWebMCPExternalV120=deepFreeze({
  BUILD,
  MANIFEST,
  CATALOG,
  ExternalRuntime,
  bootFromScript,
  propertyKey
});

const autoScript=typeof document!=='undefined'?document.currentScript:null;
if(autoScript?.dataset?.vp3Key&&autoScript?.dataset?.vp3WebmcpAuto!=='off'){
  Promise.resolve().then(()=>bootFromScript(autoScript)).catch(error=>{
    global.VP3_PROFILE_WEBMCP_EXTERNAL={
      build:BUILD,
      supported:false,
      registered:[],
      read_only:true,
      chat_enabled:false,
      error:String(error?.message||'Connected-site WebMCP failed to start.')
    };
  });
}
})(globalThis);
