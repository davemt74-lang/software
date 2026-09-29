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

const COMMERCE_CATALOG_V160=deepFreeze({
  'vp3.commerce.products.list':{
    title:'List public products',description:'List canonical public Profile Commerce products.',
    inputSchema:{type:'object',properties:{},additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.commerce.product.get':{
    title:'Get public product',description:'Return one public product, seller terms, and safe payment-provider choices.',
    inputSchema:{type:'object',properties:{product_slug:{type:'string',minLength:1,maxLength:80}},required:['product_slug'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.commerce.checkout.prepare':{
    title:'Prepare commerce checkout',description:'Validate canonical product price, terms, payer email, and provider without creating an order.',
    inputSchema:{type:'object',properties:{product_slug:{type:'string',minLength:1,maxLength:80},payer_email:{type:'string',minLength:3,maxLength:190},connection_id:{type:'integer',minimum:1}},required:['product_slug','payer_email'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.commerce.checkout.confirm':{
    title:'Confirm commerce checkout',description:'Create the prepared canonical order and hosted provider checkout. This does not mark payment paid.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:4096},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true},terms_accepted:{type:'boolean'}},required:['confirmation_token','idempotency_key','intent','terms_accepted'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  },
  'vp3.commerce.order.get':{
    title:'Get commerce order',description:'Return the customer-safe canonical order projection using receipt authority.',
    inputSchema:{type:'object',properties:{order_number:{type:'string',minLength:1,maxLength:80},receipt_token:{type:'string',pattern:'^[a-f0-9]{64}
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
,
  'vp3.booking.options.list':{
    title:'List public appointment types',description:'List appointment types currently open for public booking.',
    inputSchema:{type:'object',properties:{},additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.availability.list':{
    title:'List public booking availability',description:'Return public bookable slots without private calendar details.',
    inputSchema:{type:'object',properties:{event_type_id:{type:'integer',minimum:1},event_slug:{type:'string',maxLength:80},date:{type:'string',pattern:'^\\d{4}-\\d{2}-\\d{2}$'},timezone:{type:'string',maxLength:80}},required:['date'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.prepare':{
    title:'Prepare public booking',description:'Validate and preview a public booking without creating it.',
    inputSchema:{type:'object',properties:{event_type_id:{type:'integer',minimum:1},event_slug:{type:'string',maxLength:80},start_at_utc:{type:'string',maxLength:40},guest_timezone:{type:'string',maxLength:80},guest_name:{type:'string',minLength:1,maxLength:190},guest_email:{type:'string',minLength:3,maxLength:190},guest_phone:{type:'string',maxLength:80},guest_notes:{type:'string',maxLength:2000},intake:{type:'object',additionalProperties:true}},required:['start_at_utc','guest_name','guest_email'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.confirm':{
    title:'Confirm public booking',description:'Create the exact prepared booking after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  },
  'vp3.booking.get':{
    title:'Get public booking',description:'Return one booking using its opaque public token.',
    inputSchema:{type:'object',properties:{public_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['public_token'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.reschedule.prepare':{
    title:'Prepare booking reschedule',description:'Validate and preview a new booking time.',
    inputSchema:{type:'object',properties:{manage_token:{type:'string',pattern:'^[a-f0-9]{64}$'},start_at_utc:{type:'string',maxLength:40},guest_timezone:{type:'string',maxLength:80}},required:['manage_token','start_at_utc'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.reschedule.confirm':{
    title:'Confirm booking reschedule',description:'Apply the exact prepared reschedule after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  },
  'vp3.booking.cancel.prepare':{
    title:'Prepare booking cancellation',description:'Validate and preview booking cancellation.',
    inputSchema:{type:'object',properties:{manage_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['manage_token'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.cancel.confirm':{
    title:'Confirm booking cancellation',description:'Cancel the exact prepared booking after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  }});

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
function isSchedulingToolV150(name){
  return String(name||'').startsWith('vp3.booking.');
}
function isCommerceToolV160(name){
  return String(name||'').startsWith('vp3.commerce.');
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
    if(!manifest.external)return [];
    const allowed=new Set(Array.isArray(manifest.allowed_tools)?manifest.allowed_tools:[]);
    const chatEnabled=manifest.external.stateful_profile_agent===true&&Boolean(this.chatGrant);
    const schedulingEnabled=manifest.external.scheduling_enabled===true;
    const commerceEnabled=manifest.external.commerce_enabled===true;
    return Object.keys(CATALOG).filter(name=>allowed.has(name)&&(!isChatToolV140(name)||chatEnabled)&&(!isSchedulingToolV150(name)||schedulingEnabled)&&(!isCommerceToolV160(name)||commerceEnabled)).sort();
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
    const trusted=Object.prototype.hasOwnProperty.call(CATALOG,name);
    const allowed=Array.isArray(this.manifest.allowed_tools)&&this.manifest.allowed_tools.includes(name);
    if(!trusted||!allowed)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    if(isChatToolV140(name)){
      if(this.manifest?.external?.stateful_profile_agent!==true)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
      try{
        if(!await this.ensureChatGrantV140())return safeError('CHAT_GRANT_REQUIRED','Connected-site Profile Agent chat is unavailable.');
      }catch{
        return safeError('CHAT_GRANT_REQUIRED','Connected-site Profile Agent chat is unavailable.',true);
      }
      if(!this.effectiveToolNames().includes(name))return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }else if(isSchedulingToolV150(name)){
      if(this.manifest?.external?.scheduling_enabled!==true)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
      if(!this.effectiveToolNames().includes(name))return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }else if(isCommerceToolV160(name)){
      if(this.manifest?.external?.commerce_enabled!==true)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
      if(!this.effectiveToolNames().includes(name))return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }else if(!this.effectiveToolNames().includes(name)){
      return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }

    const interactionId=transportIdV130();
    const startedAt=Date.now();
    try{
      this.onEvent({event:'tool_called',tool:name,interaction_id:interactionId});
      const payload={
        manifest_version:this.manifest.manifest_version,
        surface:'external_site',
        property_id:this.manifest.property_id,
        profile_username:this.manifest.profile_username,
        tool:name,
        input:args,
        telemetry:{
          webmcp_session_id:this.webmcpSessionId,
          interaction_id:interactionId,
          agent_referral:referralTokenV130()
        }
      };
      if(isChatToolV140(name))payload.chat_grant=this.chatGrant;
      const response=await this.fetchImpl(this.gatewayUrl(),{
        method:'POST',
        credentials:'omit',
        cache:'no-store',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(payload),
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
    scheduling_enabled:Boolean(runtime.manifest?.external?.scheduling_enabled),
    commerce_enabled:Boolean(runtime.manifest?.external?.commerce_enabled),
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
      scheduling_enabled:false,
      commerce_enabled:false,
      error:String(error?.message||'Connected-site WebMCP failed to start.')
    };
  });
}
})(globalThis);
}},required:['order_number','receipt_token'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.commerce.receipt.get':{
    title:'Get commerce receipt',description:'Return the customer-safe receipt projection and receipt URL.',
    inputSchema:{type:'object',properties:{order_number:{type:'string',minLength:1,maxLength:80},receipt_token:{type:'string',pattern:'^[a-f0-9]{64}
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
,
  'vp3.booking.options.list':{
    title:'List public appointment types',description:'List appointment types currently open for public booking.',
    inputSchema:{type:'object',properties:{},additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.availability.list':{
    title:'List public booking availability',description:'Return public bookable slots without private calendar details.',
    inputSchema:{type:'object',properties:{event_type_id:{type:'integer',minimum:1},event_slug:{type:'string',maxLength:80},date:{type:'string',pattern:'^\\d{4}-\\d{2}-\\d{2}$'},timezone:{type:'string',maxLength:80}},required:['date'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.prepare':{
    title:'Prepare public booking',description:'Validate and preview a public booking without creating it.',
    inputSchema:{type:'object',properties:{event_type_id:{type:'integer',minimum:1},event_slug:{type:'string',maxLength:80},start_at_utc:{type:'string',maxLength:40},guest_timezone:{type:'string',maxLength:80},guest_name:{type:'string',minLength:1,maxLength:190},guest_email:{type:'string',minLength:3,maxLength:190},guest_phone:{type:'string',maxLength:80},guest_notes:{type:'string',maxLength:2000},intake:{type:'object',additionalProperties:true}},required:['start_at_utc','guest_name','guest_email'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.confirm':{
    title:'Confirm public booking',description:'Create the exact prepared booking after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  },
  'vp3.booking.get':{
    title:'Get public booking',description:'Return one booking using its opaque public token.',
    inputSchema:{type:'object',properties:{public_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['public_token'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.reschedule.prepare':{
    title:'Prepare booking reschedule',description:'Validate and preview a new booking time.',
    inputSchema:{type:'object',properties:{manage_token:{type:'string',pattern:'^[a-f0-9]{64}$'},start_at_utc:{type:'string',maxLength:40},guest_timezone:{type:'string',maxLength:80}},required:['manage_token','start_at_utc'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.reschedule.confirm':{
    title:'Confirm booking reschedule',description:'Apply the exact prepared reschedule after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  },
  'vp3.booking.cancel.prepare':{
    title:'Prepare booking cancellation',description:'Validate and preview booking cancellation.',
    inputSchema:{type:'object',properties:{manage_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['manage_token'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.cancel.confirm':{
    title:'Confirm booking cancellation',description:'Cancel the exact prepared booking after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  }});

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
function isSchedulingToolV150(name){
  return String(name||'').startsWith('vp3.booking.');
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
    if(!manifest.external)return [];
    const allowed=new Set(Array.isArray(manifest.allowed_tools)?manifest.allowed_tools:[]);
    const chatEnabled=manifest.external.stateful_profile_agent===true&&Boolean(this.chatGrant);
    const schedulingEnabled=manifest.external.scheduling_enabled===true;
    return Object.keys(CATALOG).filter(name=>allowed.has(name)&&(!isChatToolV140(name)||chatEnabled)&&(!isSchedulingToolV150(name)||schedulingEnabled)).sort();
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
    const trusted=Object.prototype.hasOwnProperty.call(CATALOG,name);
    const allowed=Array.isArray(this.manifest.allowed_tools)&&this.manifest.allowed_tools.includes(name);
    if(!trusted||!allowed)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    if(isChatToolV140(name)){
      if(this.manifest?.external?.stateful_profile_agent!==true)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
      try{
        if(!await this.ensureChatGrantV140())return safeError('CHAT_GRANT_REQUIRED','Connected-site Profile Agent chat is unavailable.');
      }catch{
        return safeError('CHAT_GRANT_REQUIRED','Connected-site Profile Agent chat is unavailable.',true);
      }
      if(!this.effectiveToolNames().includes(name))return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }else if(isSchedulingToolV150(name)){
      if(this.manifest?.external?.scheduling_enabled!==true)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
      if(!this.effectiveToolNames().includes(name))return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }else if(!this.effectiveToolNames().includes(name)){
      return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }

    const interactionId=transportIdV130();
    const startedAt=Date.now();
    try{
      this.onEvent({event:'tool_called',tool:name,interaction_id:interactionId});
      const payload={
        manifest_version:this.manifest.manifest_version,
        surface:'external_site',
        property_id:this.manifest.property_id,
        profile_username:this.manifest.profile_username,
        tool:name,
        input:args,
        telemetry:{
          webmcp_session_id:this.webmcpSessionId,
          interaction_id:interactionId,
          agent_referral:referralTokenV130()
        }
      };
      if(isChatToolV140(name))payload.chat_grant=this.chatGrant;
      const response=await this.fetchImpl(this.gatewayUrl(),{
        method:'POST',
        credentials:'omit',
        cache:'no-store',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(payload),
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
}},required:['order_number','receipt_token'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.commerce.delivery.get':{
    title:'Get fulfillment status',description:'Return fulfillment state and whether private delivery is available without embedding private delivery content.',
    inputSchema:{type:'object',properties:{order_number:{type:'string',minLength:1,maxLength:80},receipt_token:{type:'string',pattern:'^[a-f0-9]{64}
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
,
  'vp3.booking.options.list':{
    title:'List public appointment types',description:'List appointment types currently open for public booking.',
    inputSchema:{type:'object',properties:{},additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.availability.list':{
    title:'List public booking availability',description:'Return public bookable slots without private calendar details.',
    inputSchema:{type:'object',properties:{event_type_id:{type:'integer',minimum:1},event_slug:{type:'string',maxLength:80},date:{type:'string',pattern:'^\\d{4}-\\d{2}-\\d{2}$'},timezone:{type:'string',maxLength:80}},required:['date'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.prepare':{
    title:'Prepare public booking',description:'Validate and preview a public booking without creating it.',
    inputSchema:{type:'object',properties:{event_type_id:{type:'integer',minimum:1},event_slug:{type:'string',maxLength:80},start_at_utc:{type:'string',maxLength:40},guest_timezone:{type:'string',maxLength:80},guest_name:{type:'string',minLength:1,maxLength:190},guest_email:{type:'string',minLength:3,maxLength:190},guest_phone:{type:'string',maxLength:80},guest_notes:{type:'string',maxLength:2000},intake:{type:'object',additionalProperties:true}},required:['start_at_utc','guest_name','guest_email'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.confirm':{
    title:'Confirm public booking',description:'Create the exact prepared booking after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  },
  'vp3.booking.get':{
    title:'Get public booking',description:'Return one booking using its opaque public token.',
    inputSchema:{type:'object',properties:{public_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['public_token'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.reschedule.prepare':{
    title:'Prepare booking reschedule',description:'Validate and preview a new booking time.',
    inputSchema:{type:'object',properties:{manage_token:{type:'string',pattern:'^[a-f0-9]{64}$'},start_at_utc:{type:'string',maxLength:40},guest_timezone:{type:'string',maxLength:80}},required:['manage_token','start_at_utc'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.reschedule.confirm':{
    title:'Confirm booking reschedule',description:'Apply the exact prepared reschedule after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  },
  'vp3.booking.cancel.prepare':{
    title:'Prepare booking cancellation',description:'Validate and preview booking cancellation.',
    inputSchema:{type:'object',properties:{manage_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['manage_token'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.cancel.confirm':{
    title:'Confirm booking cancellation',description:'Cancel the exact prepared booking after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  }});

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
function isSchedulingToolV150(name){
  return String(name||'').startsWith('vp3.booking.');
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
    if(!manifest.external)return [];
    const allowed=new Set(Array.isArray(manifest.allowed_tools)?manifest.allowed_tools:[]);
    const chatEnabled=manifest.external.stateful_profile_agent===true&&Boolean(this.chatGrant);
    const schedulingEnabled=manifest.external.scheduling_enabled===true;
    return Object.keys(CATALOG).filter(name=>allowed.has(name)&&(!isChatToolV140(name)||chatEnabled)&&(!isSchedulingToolV150(name)||schedulingEnabled)).sort();
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
    const trusted=Object.prototype.hasOwnProperty.call(CATALOG,name);
    const allowed=Array.isArray(this.manifest.allowed_tools)&&this.manifest.allowed_tools.includes(name);
    if(!trusted||!allowed)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    if(isChatToolV140(name)){
      if(this.manifest?.external?.stateful_profile_agent!==true)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
      try{
        if(!await this.ensureChatGrantV140())return safeError('CHAT_GRANT_REQUIRED','Connected-site Profile Agent chat is unavailable.');
      }catch{
        return safeError('CHAT_GRANT_REQUIRED','Connected-site Profile Agent chat is unavailable.',true);
      }
      if(!this.effectiveToolNames().includes(name))return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }else if(isSchedulingToolV150(name)){
      if(this.manifest?.external?.scheduling_enabled!==true)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
      if(!this.effectiveToolNames().includes(name))return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }else if(!this.effectiveToolNames().includes(name)){
      return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }

    const interactionId=transportIdV130();
    const startedAt=Date.now();
    try{
      this.onEvent({event:'tool_called',tool:name,interaction_id:interactionId});
      const payload={
        manifest_version:this.manifest.manifest_version,
        surface:'external_site',
        property_id:this.manifest.property_id,
        profile_username:this.manifest.profile_username,
        tool:name,
        input:args,
        telemetry:{
          webmcp_session_id:this.webmcpSessionId,
          interaction_id:interactionId,
          agent_referral:referralTokenV130()
        }
      };
      if(isChatToolV140(name))payload.chat_grant=this.chatGrant;
      const response=await this.fetchImpl(this.gatewayUrl(),{
        method:'POST',
        credentials:'omit',
        cache:'no-store',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(payload),
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
}},required:['order_number','receipt_token'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.commerce.refund.status':{
    title:'Get refund request status',description:'Return refundable balance and seller-review refund-request status.',
    inputSchema:{type:'object',properties:{order_number:{type:'string',minLength:1,maxLength:80},receipt_token:{type:'string',pattern:'^[a-f0-9]{64}
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
,
  'vp3.booking.options.list':{
    title:'List public appointment types',description:'List appointment types currently open for public booking.',
    inputSchema:{type:'object',properties:{},additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.availability.list':{
    title:'List public booking availability',description:'Return public bookable slots without private calendar details.',
    inputSchema:{type:'object',properties:{event_type_id:{type:'integer',minimum:1},event_slug:{type:'string',maxLength:80},date:{type:'string',pattern:'^\\d{4}-\\d{2}-\\d{2}$'},timezone:{type:'string',maxLength:80}},required:['date'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.prepare':{
    title:'Prepare public booking',description:'Validate and preview a public booking without creating it.',
    inputSchema:{type:'object',properties:{event_type_id:{type:'integer',minimum:1},event_slug:{type:'string',maxLength:80},start_at_utc:{type:'string',maxLength:40},guest_timezone:{type:'string',maxLength:80},guest_name:{type:'string',minLength:1,maxLength:190},guest_email:{type:'string',minLength:3,maxLength:190},guest_phone:{type:'string',maxLength:80},guest_notes:{type:'string',maxLength:2000},intake:{type:'object',additionalProperties:true}},required:['start_at_utc','guest_name','guest_email'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.confirm':{
    title:'Confirm public booking',description:'Create the exact prepared booking after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  },
  'vp3.booking.get':{
    title:'Get public booking',description:'Return one booking using its opaque public token.',
    inputSchema:{type:'object',properties:{public_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['public_token'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.reschedule.prepare':{
    title:'Prepare booking reschedule',description:'Validate and preview a new booking time.',
    inputSchema:{type:'object',properties:{manage_token:{type:'string',pattern:'^[a-f0-9]{64}$'},start_at_utc:{type:'string',maxLength:40},guest_timezone:{type:'string',maxLength:80}},required:['manage_token','start_at_utc'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.reschedule.confirm':{
    title:'Confirm booking reschedule',description:'Apply the exact prepared reschedule after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  },
  'vp3.booking.cancel.prepare':{
    title:'Prepare booking cancellation',description:'Validate and preview booking cancellation.',
    inputSchema:{type:'object',properties:{manage_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['manage_token'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.cancel.confirm':{
    title:'Confirm booking cancellation',description:'Cancel the exact prepared booking after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  }});

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
function isSchedulingToolV150(name){
  return String(name||'').startsWith('vp3.booking.');
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
    if(!manifest.external)return [];
    const allowed=new Set(Array.isArray(manifest.allowed_tools)?manifest.allowed_tools:[]);
    const chatEnabled=manifest.external.stateful_profile_agent===true&&Boolean(this.chatGrant);
    const schedulingEnabled=manifest.external.scheduling_enabled===true;
    return Object.keys(CATALOG).filter(name=>allowed.has(name)&&(!isChatToolV140(name)||chatEnabled)&&(!isSchedulingToolV150(name)||schedulingEnabled)).sort();
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
    const trusted=Object.prototype.hasOwnProperty.call(CATALOG,name);
    const allowed=Array.isArray(this.manifest.allowed_tools)&&this.manifest.allowed_tools.includes(name);
    if(!trusted||!allowed)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    if(isChatToolV140(name)){
      if(this.manifest?.external?.stateful_profile_agent!==true)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
      try{
        if(!await this.ensureChatGrantV140())return safeError('CHAT_GRANT_REQUIRED','Connected-site Profile Agent chat is unavailable.');
      }catch{
        return safeError('CHAT_GRANT_REQUIRED','Connected-site Profile Agent chat is unavailable.',true);
      }
      if(!this.effectiveToolNames().includes(name))return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }else if(isSchedulingToolV150(name)){
      if(this.manifest?.external?.scheduling_enabled!==true)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
      if(!this.effectiveToolNames().includes(name))return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }else if(!this.effectiveToolNames().includes(name)){
      return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }

    const interactionId=transportIdV130();
    const startedAt=Date.now();
    try{
      this.onEvent({event:'tool_called',tool:name,interaction_id:interactionId});
      const payload={
        manifest_version:this.manifest.manifest_version,
        surface:'external_site',
        property_id:this.manifest.property_id,
        profile_username:this.manifest.profile_username,
        tool:name,
        input:args,
        telemetry:{
          webmcp_session_id:this.webmcpSessionId,
          interaction_id:interactionId,
          agent_referral:referralTokenV130()
        }
      };
      if(isChatToolV140(name))payload.chat_grant=this.chatGrant;
      const response=await this.fetchImpl(this.gatewayUrl(),{
        method:'POST',
        credentials:'omit',
        cache:'no-store',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(payload),
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
}},required:['order_number','receipt_token'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.commerce.refund.prepare':{
    title:'Prepare refund request',description:'Validate and preview a seller-reviewed refund request. No money moves.',
    inputSchema:{type:'object',properties:{order_number:{type:'string',minLength:1,maxLength:80},receipt_token:{type:'string',pattern:'^[a-f0-9]{64}
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
,
  'vp3.booking.options.list':{
    title:'List public appointment types',description:'List appointment types currently open for public booking.',
    inputSchema:{type:'object',properties:{},additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.availability.list':{
    title:'List public booking availability',description:'Return public bookable slots without private calendar details.',
    inputSchema:{type:'object',properties:{event_type_id:{type:'integer',minimum:1},event_slug:{type:'string',maxLength:80},date:{type:'string',pattern:'^\\d{4}-\\d{2}-\\d{2}$'},timezone:{type:'string',maxLength:80}},required:['date'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.prepare':{
    title:'Prepare public booking',description:'Validate and preview a public booking without creating it.',
    inputSchema:{type:'object',properties:{event_type_id:{type:'integer',minimum:1},event_slug:{type:'string',maxLength:80},start_at_utc:{type:'string',maxLength:40},guest_timezone:{type:'string',maxLength:80},guest_name:{type:'string',minLength:1,maxLength:190},guest_email:{type:'string',minLength:3,maxLength:190},guest_phone:{type:'string',maxLength:80},guest_notes:{type:'string',maxLength:2000},intake:{type:'object',additionalProperties:true}},required:['start_at_utc','guest_name','guest_email'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.confirm':{
    title:'Confirm public booking',description:'Create the exact prepared booking after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  },
  'vp3.booking.get':{
    title:'Get public booking',description:'Return one booking using its opaque public token.',
    inputSchema:{type:'object',properties:{public_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['public_token'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.reschedule.prepare':{
    title:'Prepare booking reschedule',description:'Validate and preview a new booking time.',
    inputSchema:{type:'object',properties:{manage_token:{type:'string',pattern:'^[a-f0-9]{64}$'},start_at_utc:{type:'string',maxLength:40},guest_timezone:{type:'string',maxLength:80}},required:['manage_token','start_at_utc'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.reschedule.confirm':{
    title:'Confirm booking reschedule',description:'Apply the exact prepared reschedule after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  },
  'vp3.booking.cancel.prepare':{
    title:'Prepare booking cancellation',description:'Validate and preview booking cancellation.',
    inputSchema:{type:'object',properties:{manage_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['manage_token'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.cancel.confirm':{
    title:'Confirm booking cancellation',description:'Cancel the exact prepared booking after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  }});

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
function isSchedulingToolV150(name){
  return String(name||'').startsWith('vp3.booking.');
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
    if(!manifest.external)return [];
    const allowed=new Set(Array.isArray(manifest.allowed_tools)?manifest.allowed_tools:[]);
    const chatEnabled=manifest.external.stateful_profile_agent===true&&Boolean(this.chatGrant);
    const schedulingEnabled=manifest.external.scheduling_enabled===true;
    return Object.keys(CATALOG).filter(name=>allowed.has(name)&&(!isChatToolV140(name)||chatEnabled)&&(!isSchedulingToolV150(name)||schedulingEnabled)).sort();
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
    const trusted=Object.prototype.hasOwnProperty.call(CATALOG,name);
    const allowed=Array.isArray(this.manifest.allowed_tools)&&this.manifest.allowed_tools.includes(name);
    if(!trusted||!allowed)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    if(isChatToolV140(name)){
      if(this.manifest?.external?.stateful_profile_agent!==true)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
      try{
        if(!await this.ensureChatGrantV140())return safeError('CHAT_GRANT_REQUIRED','Connected-site Profile Agent chat is unavailable.');
      }catch{
        return safeError('CHAT_GRANT_REQUIRED','Connected-site Profile Agent chat is unavailable.',true);
      }
      if(!this.effectiveToolNames().includes(name))return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }else if(isSchedulingToolV150(name)){
      if(this.manifest?.external?.scheduling_enabled!==true)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
      if(!this.effectiveToolNames().includes(name))return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }else if(!this.effectiveToolNames().includes(name)){
      return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }

    const interactionId=transportIdV130();
    const startedAt=Date.now();
    try{
      this.onEvent({event:'tool_called',tool:name,interaction_id:interactionId});
      const payload={
        manifest_version:this.manifest.manifest_version,
        surface:'external_site',
        property_id:this.manifest.property_id,
        profile_username:this.manifest.profile_username,
        tool:name,
        input:args,
        telemetry:{
          webmcp_session_id:this.webmcpSessionId,
          interaction_id:interactionId,
          agent_referral:referralTokenV130()
        }
      };
      if(isChatToolV140(name))payload.chat_grant=this.chatGrant;
      const response=await this.fetchImpl(this.gatewayUrl(),{
        method:'POST',
        credentials:'omit',
        cache:'no-store',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(payload),
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
},reason:{type:'string',minLength:3,maxLength:500}},required:['order_number','receipt_token','reason'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.commerce.refund.confirm':{
    title:'Confirm refund request',description:'Submit the prepared request for seller review. This never executes a provider refund.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:4096},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  }
});

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
,
  'vp3.booking.options.list':{
    title:'List public appointment types',description:'List appointment types currently open for public booking.',
    inputSchema:{type:'object',properties:{},additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.availability.list':{
    title:'List public booking availability',description:'Return public bookable slots without private calendar details.',
    inputSchema:{type:'object',properties:{event_type_id:{type:'integer',minimum:1},event_slug:{type:'string',maxLength:80},date:{type:'string',pattern:'^\\d{4}-\\d{2}-\\d{2}$'},timezone:{type:'string',maxLength:80}},required:['date'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.prepare':{
    title:'Prepare public booking',description:'Validate and preview a public booking without creating it.',
    inputSchema:{type:'object',properties:{event_type_id:{type:'integer',minimum:1},event_slug:{type:'string',maxLength:80},start_at_utc:{type:'string',maxLength:40},guest_timezone:{type:'string',maxLength:80},guest_name:{type:'string',minLength:1,maxLength:190},guest_email:{type:'string',minLength:3,maxLength:190},guest_phone:{type:'string',maxLength:80},guest_notes:{type:'string',maxLength:2000},intake:{type:'object',additionalProperties:true}},required:['start_at_utc','guest_name','guest_email'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.confirm':{
    title:'Confirm public booking',description:'Create the exact prepared booking after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  },
  'vp3.booking.get':{
    title:'Get public booking',description:'Return one booking using its opaque public token.',
    inputSchema:{type:'object',properties:{public_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['public_token'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.reschedule.prepare':{
    title:'Prepare booking reschedule',description:'Validate and preview a new booking time.',
    inputSchema:{type:'object',properties:{manage_token:{type:'string',pattern:'^[a-f0-9]{64}$'},start_at_utc:{type:'string',maxLength:40},guest_timezone:{type:'string',maxLength:80}},required:['manage_token','start_at_utc'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.reschedule.confirm':{
    title:'Confirm booking reschedule',description:'Apply the exact prepared reschedule after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  },
  'vp3.booking.cancel.prepare':{
    title:'Prepare booking cancellation',description:'Validate and preview booking cancellation.',
    inputSchema:{type:'object',properties:{manage_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['manage_token'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.cancel.confirm':{
    title:'Confirm booking cancellation',description:'Cancel the exact prepared booking after confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  }});

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
function isSchedulingToolV150(name){
  return String(name||'').startsWith('vp3.booking.');
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
    if(!manifest.external)return [];
    const allowed=new Set(Array.isArray(manifest.allowed_tools)?manifest.allowed_tools:[]);
    const chatEnabled=manifest.external.stateful_profile_agent===true&&Boolean(this.chatGrant);
    const schedulingEnabled=manifest.external.scheduling_enabled===true;
    return Object.keys(CATALOG).filter(name=>allowed.has(name)&&(!isChatToolV140(name)||chatEnabled)&&(!isSchedulingToolV150(name)||schedulingEnabled)).sort();
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
    const trusted=Object.prototype.hasOwnProperty.call(CATALOG,name);
    const allowed=Array.isArray(this.manifest.allowed_tools)&&this.manifest.allowed_tools.includes(name);
    if(!trusted||!allowed)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    if(isChatToolV140(name)){
      if(this.manifest?.external?.stateful_profile_agent!==true)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
      try{
        if(!await this.ensureChatGrantV140())return safeError('CHAT_GRANT_REQUIRED','Connected-site Profile Agent chat is unavailable.');
      }catch{
        return safeError('CHAT_GRANT_REQUIRED','Connected-site Profile Agent chat is unavailable.',true);
      }
      if(!this.effectiveToolNames().includes(name))return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }else if(isSchedulingToolV150(name)){
      if(this.manifest?.external?.scheduling_enabled!==true)return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
      if(!this.effectiveToolNames().includes(name))return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }else if(!this.effectiveToolNames().includes(name)){
      return safeError('CAPABILITY_UNAVAILABLE','That connected-site capability is unavailable.');
    }

    const interactionId=transportIdV130();
    const startedAt=Date.now();
    try{
      this.onEvent({event:'tool_called',tool:name,interaction_id:interactionId});
      const payload={
        manifest_version:this.manifest.manifest_version,
        surface:'external_site',
        property_id:this.manifest.property_id,
        profile_username:this.manifest.profile_username,
        tool:name,
        input:args,
        telemetry:{
          webmcp_session_id:this.webmcpSessionId,
          interaction_id:interactionId,
          agent_referral:referralTokenV130()
        }
      };
      if(isChatToolV140(name))payload.chat_grant=this.chatGrant;
      const response=await this.fetchImpl(this.gatewayUrl(),{
        method:'POST',
        credentials:'omit',
        cache:'no-store',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(payload),
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
