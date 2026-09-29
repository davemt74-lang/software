export const VP3_PROFILE_WEBMCP_RUNTIME_V100 = 'profile-webmcp-runtime-v100-20260928';

function deepFreeze(value) {
  if (!value || typeof value !== 'object' || Object.isFrozen(value)) return value;
  Object.freeze(value);
  for (const child of Object.values(value)) deepFreeze(child);
  return value;
}

export const VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100 = deepFreeze({
  'vp3.profile.capabilities.get': {
    title: 'Get profile capabilities',
    description: 'Return the currently available VP3 capabilities for this public profile and visitor.',
    inputSchema: {type:'object',properties:{},additionalProperties:false},
    annotations: {readOnlyHint:true,untrustedContentHint:false,consequentialHint:false,debugging:false}
  },
  'vp3.profile.get': {
    title: 'Get public profile',
    description: 'Return the public VP3 profile projection approved for agent use.',
    inputSchema: {type:'object',properties:{},additionalProperties:false},
    annotations: {readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.agent.get': {
    title: 'Get Profile Agent',
    description: 'Return the public Profile Agent identity and greeting available to this visitor.',
    inputSchema: {type:'object',properties:{},additionalProperties:false},
    annotations: {readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.agent.conversation.get': {
    title: 'Get Profile Agent conversation',
    description: 'Return one conversation bound to this exact profile, Profile Agent, and visitor session.',
    inputSchema: {type:'object',properties:{conversation_id:{type:'integer',minimum:1}},required:['conversation_id'],additionalProperties:false},
    annotations: {readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.agent.message.send': {
    title: 'Send message to Profile Agent',
    description: 'Send a visitor message to this Profile Agent using the canonical conversation and privacy boundary.',
    inputSchema: {type:'object',properties:{conversation_id:{type:'integer',minimum:1},message:{type:'string',minLength:1,maxLength:2000}},required:['message'],additionalProperties:false},
    annotations: {readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.agent.owner_handoff.request': {
    title: 'Request profile owner assistance',
    description: 'Ask the profile owner for assistance with this exact visitor conversation.',
    inputSchema: {type:'object',properties:{conversation_id:{type:'integer',minimum:1},reason:{type:'string',maxLength:1000}},required:['conversation_id'],additionalProperties:false},
    annotations: {readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.intent.resolve': {
    title: 'Resolve profile intent',
    description: 'Identify which currently available VP3 profile capabilities can help with a user goal without executing an action.',
    inputSchema: {
      type:'object',
      properties:{goal:{type:'string',minLength:1,maxLength:1000}},
      required:['goal'],
      additionalProperties:false
    },
    annotations: {readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  }
});

function stable(value) {
  if (value === null || typeof value !== 'object') return JSON.stringify(value);
  if (Array.isArray(value)) return '[' + value.map(stable).join(',') + ']';
  return '{' + Object.keys(value).sort().map(k => JSON.stringify(k)+':'+stable(value[k])).join(',') + '}';
}

function safeError(code, message, retryable=false) {
  return {ok:false,error:{code,message,retryable}};
}

export class VP3ProfileWebMCPRuntimeV100 {
  constructor({
    documentObject=globalThis.document,
    fetchImpl=globalThis.fetch?.bind(globalThis),
    endpoint='/api/profile-webmcp-v100.php',
    sessionProof='',
    onEvent=()=>{}
  }={}) {
    this.documentObject=documentObject;
    this.fetchImpl=fetchImpl;
    this.endpoint=endpoint;
    this.sessionProof=String(sessionProof||'');
    this.onEvent=onEvent;
    this.manifest=null;
    this.registrations=new Map();
  }

  get supported() {
    return Boolean(this.documentObject?.modelContext?.registerTool);
  }

  effectiveToolNames(manifest=this.manifest) {
    if (!manifest || manifest.manifest_version !== 'vp3.profile.webmcp.v1' || manifest.surface !== 'native_profile') return [];
    const allowed=new Set(Array.isArray(manifest.allowed_tools)?manifest.allowed_tools:[]);
    return Object.keys(VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100).filter(name=>allowed.has(name)).sort();
  }

  async start(manifest) {
    if (!this.supported) {
      this.onEvent({event:'unsupported'});
      return {supported:false,registered:[]};
    }
    const registered=await this.syncManifest(manifest);
    return {supported:true,registered};
  }

  async syncManifest(manifest) {
    if (!manifest || manifest.manifest_version !== 'vp3.profile.webmcp.v1' || manifest.surface !== 'native_profile') {
      throw new Error('Invalid VP3 Profile WebMCP manifest.');
    }
    this.manifest=structuredClone(manifest);
    const desired=new Set(this.effectiveToolNames(manifest));

    for (const [name,entry] of this.registrations) {
      if (!desired.has(name)) {
        entry.controller.abort();
        this.registrations.delete(name);
        this.onEvent({event:'tool_unregistered',tool:name});
      }
    }

    for (const name of desired) {
      const definition=VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100[name];
      const fingerprint=stable({name,definition});
      const current=this.registrations.get(name);
      if (current?.fingerprint===fingerprint) continue;
      if (current) {
        current.controller.abort();
        this.registrations.delete(name);
      }
      await this.#register(name,definition,fingerprint);
    }
    return [...this.registrations.keys()].sort();
  }

  stop() {
    for (const entry of this.registrations.values()) entry.controller.abort();
    this.registrations.clear();
    this.onEvent({event:'runtime_stopped'});
  }

  async #register(name,definition,fingerprint) {
    const controller=new AbortController();
    try {
      await this.documentObject.modelContext.registerTool({
        name,
        title:definition.title,
        description:definition.description,
        inputSchema:definition.inputSchema,
        annotations:definition.annotations,
        execute:(args={},options={})=>this.#execute(name,args,options)
      },{signal:controller.signal});
      this.registrations.set(name,{controller,fingerprint});
      this.onEvent({event:'tool_registered',tool:name});
    } catch (error) {
      controller.abort();
      this.onEvent({event:'tool_registration_failed',tool:name});
    }
  }

  async #execute(name,args,options) {
    if (options?.signal?.aborted) {
      return safeError('CANCELLED','The profile capability request was cancelled.');
    }
    if (!this.fetchImpl || !this.manifest || !this.sessionProof) {
      return safeError('RUNTIME_UNAVAILABLE','VP3 Profile WebMCP is unavailable.');
    }
    if (!this.effectiveToolNames().includes(name)) {
      return safeError('CAPABILITY_UNAVAILABLE','That profile capability is unavailable.');
    }
    try {
      const response=await this.fetchImpl(this.endpoint,{
        method:'POST',
        credentials:'same-origin',
        headers:{
          'Content-Type':'application/json',
          'X-VP3-WebMCP-Session':this.sessionProof
        },
        body:JSON.stringify({
          manifest_version:this.manifest.manifest_version,
          surface:'native_profile',
          profile_username:this.manifest.profile_username,
          tool:name,
          input:args
        }),
        signal:options?.signal
      });
      let data;
      try { data=await response.json(); }
      catch { return safeError('INVALID_GATEWAY_RESPONSE','VP3 returned an invalid profile response.'); }
      if (!response.ok || data?.ok!==true) {
        return safeError(
          data?.error?.code || ('HTTP_'+response.status),
          data?.error?.message || 'The profile capability could not be completed.',
          Boolean(data?.error?.retryable)
        );
      }
      return data;
    } catch (error) {
      if (options?.signal?.aborted || error?.name==='AbortError') {
        return safeError('CANCELLED','The profile capability request was cancelled.');
      }
      return safeError('NETWORK_ERROR','VP3 could not be reached.',true);
    }
  }
}

export async function vp3ProfileWebMCPBootV100(config=globalThis.VP3_PROFILE_WEBMCP) {
  if (!config || !config.manifest || !config.sessionProof) return null;
  const runtime=new VP3ProfileWebMCPRuntimeV100({
    endpoint:config.endpoint,
    sessionProof:config.sessionProof
  });
  await runtime.start(config.manifest);
  return runtime;
}

if (typeof window !== 'undefined' && window.VP3_PROFILE_WEBMCP) {
  vp3ProfileWebMCPBootV100(window.VP3_PROFILE_WEBMCP)
    .then(runtime=>{ if(runtime) window.VP3_PROFILE_WEBMCP_RUNTIME=runtime; })
    .catch(()=>{});
}
