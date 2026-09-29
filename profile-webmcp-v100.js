export const VP3_PROFILE_WEBMCP_RUNTIME_V100 = 'profile-webmcp-runtime-v100-20260928';

function deepFreeze(value) {
  if (!value || typeof value !== 'object' || Object.isFrozen(value)) return value;
  Object.freeze(value);
  for (const child of Object.values(value)) deepFreeze(child);
  return value;
}

const REWARDS_CATALOG_V180=deepFreeze({
  'vp3.rewards.wallet.get':{title:'Get my Reward Wallet',description:'Return the signed-in viewer\'s safe Reward Inbox, Sent, and Claimed projections.',inputSchema:{type:'object',properties:{},additionalProperties:false},annotations:{readOnlyHint:true,untrustedContentHint:false,consequentialHint:false,debugging:false}},
  'vp3.reward.get':{title:'Get my Reward',description:'Return one safe Reward Wallet item by opaque public ID, including claim readiness without revealing credentials.',inputSchema:{type:'object',properties:{reward_public_id:{type:'string',minLength:1,maxLength:100}},required:['reward_public_id'],additionalProperties:false},annotations:{readOnlyHint:true,untrustedContentHint:false,consequentialHint:false,debugging:false}}
});

const CAMPAIGNS_CATALOG_V170=deepFreeze({
  'vp3.campaigns.list':{title:'List public campaigns',description:'List active published Campaigns shown on this public VP3 Profile.',inputSchema:{type:'object',properties:{},additionalProperties:false},annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}},
  'vp3.campaign.get':{title:'Get public campaign',description:'Return one public Campaign with public terms, location, participation requirements, and public Rewards.',inputSchema:{type:'object',properties:{campaign_slug:{type:'string',minLength:1,maxLength:120}},required:['campaign_slug'],additionalProperties:false},annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}},
  'vp3.campaign.eligibility.get':{title:'Get campaign participation requirements',description:'Describe public participation requirements without evaluating private CRM targeting.',inputSchema:{type:'object',properties:{campaign_slug:{type:'string',minLength:1,maxLength:120}},required:['campaign_slug'],additionalProperties:false},annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}},
  'vp3.campaign.participation.prepare':{title:'Prepare Campaign participation',description:'Validate and preview public Campaign participation without enrolling or issuing Rewards.',inputSchema:{type:'object',properties:{campaign_slug:{type:'string',minLength:1,maxLength:120},name:{type:'string',maxLength:190},email:{type:'string',maxLength:190},phone:{type:'string',maxLength:80},birthday:{type:'string',maxLength:40},social_handle:{type:'string',maxLength:190},proof_url:{type:'string',maxLength:2048},referral_ref:{type:'string',maxLength:190},marketing_consent:{type:'boolean'},reward_public_id:{type:'string',maxLength:100}},required:['campaign_slug'],additionalProperties:false},annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}},
  'vp3.campaign.participation.confirm':{title:'Confirm Campaign participation',description:'Complete the exact prepared participation through canonical Campaigns & Rewards.',inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}},
  'vp3.campaign.participation.get':{title:'Get Campaign participation status',description:'Return safe lifecycle state for a confirmed Campaign participation using its opaque reference.',inputSchema:{type:'object',properties:{participation_reference:{type:'string',pattern:'^[a-f0-9]{32}$'}},required:['participation_reference'],additionalProperties:false},annotations:{readOnlyHint:true,untrustedContentHint:false,consequentialHint:false,debugging:false}}
});

const COMMERCE_CATALOG_V160=deepFreeze({
  'vp3.commerce.products.list':{title:'List public products',description:'List canonical public Profile Commerce products.',inputSchema:{type:'object',properties:{},additionalProperties:false},annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}},
  'vp3.commerce.product.get':{title:'Get public product',description:'Return one public product, seller terms, and safe payment-provider choices.',inputSchema:{type:'object',properties:{product_slug:{type:'string',minLength:1,maxLength:80}},required:['product_slug'],additionalProperties:false},annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}},
  'vp3.commerce.checkout.prepare':{title:'Prepare commerce checkout',description:'Validate canonical product price, terms, payer email, and provider without creating an order.',inputSchema:{type:'object',properties:{product_slug:{type:'string',minLength:1,maxLength:80},payer_email:{type:'string',minLength:3,maxLength:190},connection_id:{type:'integer',minimum:1}},required:['product_slug','payer_email'],additionalProperties:false},annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}},
  'vp3.commerce.checkout.confirm':{title:'Confirm commerce checkout',description:'Create the prepared canonical order and hosted provider checkout. This does not mark payment paid.',inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:4096},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true},terms_accepted:{type:'boolean'}},required:['confirmation_token','idempotency_key','intent','terms_accepted'],additionalProperties:false},annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}},
  'vp3.commerce.order.get':{title:'Get commerce order',description:'Return the customer-safe canonical order projection using receipt authority.',inputSchema:{type:'object',properties:{order_number:{type:'string',minLength:1,maxLength:80},receipt_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['order_number','receipt_token'],additionalProperties:false},annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}},
  'vp3.commerce.receipt.get':{title:'Get commerce receipt',description:'Return the customer-safe receipt projection and receipt URL.',inputSchema:{type:'object',properties:{order_number:{type:'string',minLength:1,maxLength:80},receipt_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['order_number','receipt_token'],additionalProperties:false},annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}},
  'vp3.commerce.delivery.get':{title:'Get fulfillment status',description:'Return fulfillment state and whether private delivery is available without embedding private delivery content.',inputSchema:{type:'object',properties:{order_number:{type:'string',minLength:1,maxLength:80},receipt_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['order_number','receipt_token'],additionalProperties:false},annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}},
  'vp3.commerce.refund.status':{title:'Get refund request status',description:'Return refundable balance and seller-review refund-request status.',inputSchema:{type:'object',properties:{order_number:{type:'string',minLength:1,maxLength:80},receipt_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['order_number','receipt_token'],additionalProperties:false},annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}},
  'vp3.commerce.refund.prepare':{title:'Prepare refund request',description:'Validate and preview a seller-reviewed refund request. No money moves.',inputSchema:{type:'object',properties:{order_number:{type:'string',minLength:1,maxLength:80},receipt_token:{type:'string',pattern:'^[a-f0-9]{64}$'},reason:{type:'string',minLength:3,maxLength:500}},required:['order_number','receipt_token','reason'],additionalProperties:false},annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}},
  'vp3.commerce.refund.confirm':{title:'Confirm refund request',description:'Submit the prepared request for seller review. This never executes a provider refund.',inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:4096},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}}
});

export const VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100 = deepFreeze({
  'vp3.profile.capabilities.get': {
    title:'Get profile capabilities',description:'Return the currently available VP3 capabilities for this public profile and visitor.',
    inputSchema:{type:'object',properties:{},additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:false,consequentialHint:false,debugging:false}
  },
  'vp3.profile.get': {
    title:'Get public profile',description:'Return the public VP3 profile projection approved for agent use.',
    inputSchema:{type:'object',properties:{},additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.agent.get': {
    title:'Get Profile Agent',description:'Return the public Profile Agent identity and greeting available to this visitor.',
    inputSchema:{type:'object',properties:{},additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.agent.chat.start': {
    title:'Start Profile Agent chat',description:'Start or resume a visitor conversation with this Profile Agent.',
    inputSchema:{type:'object',properties:{},additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.agent.conversation.get': {
    title:'Get Profile Agent conversation',description:'Return one conversation bound to this exact profile, Profile Agent, and visitor session.',
    inputSchema:{type:'object',properties:{conversation_id:{type:'integer',minimum:1}},required:['conversation_id'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.agent.message.send': {
    title:'Send message to Profile Agent',description:'Send a visitor message using the canonical Profile Agent conversation boundary.',
    inputSchema:{type:'object',properties:{conversation_id:{type:'integer',minimum:1},message:{type:'string',minLength:1,maxLength:2000}},required:['message'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.agent.owner_handoff.request': {
    title:'Request profile owner assistance',description:'Ask the profile owner for assistance with this exact visitor conversation.',
    inputSchema:{type:'object',properties:{conversation_id:{type:'integer',minimum:1},reason:{type:'string',maxLength:1000}},required:['conversation_id'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.options.list': {
    title:'List public appointment types',description:'List appointment types currently open for public booking.',
    inputSchema:{type:'object',properties:{},additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.availability.list': {
    title:'List public booking availability',description:'Return public bookable slots without private calendar details.',
    inputSchema:{type:'object',properties:{event_type_id:{type:'integer',minimum:1},event_slug:{type:'string',maxLength:80},date:{type:'string',pattern:'^\\d{4}-\\d{2}-\\d{2}$'},timezone:{type:'string',maxLength:80}},required:['date'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.prepare': {
    title:'Prepare public booking',description:'Validate and preview a public booking without creating it.',
    inputSchema:{type:'object',properties:{event_type_id:{type:'integer',minimum:1},event_slug:{type:'string',maxLength:80},start_at_utc:{type:'string',maxLength:40},guest_timezone:{type:'string',maxLength:80},guest_name:{type:'string',minLength:1,maxLength:190},guest_email:{type:'string',minLength:3,maxLength:190},guest_phone:{type:'string',maxLength:80},guest_notes:{type:'string',maxLength:2000},intake:{type:'object',additionalProperties:true}},required:['start_at_utc','guest_name','guest_email'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.confirm': {
    title:'Confirm public booking',description:'Create the exact prepared booking after explicit confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  },
  'vp3.booking.get': {
    title:'Get public booking',description:'Return one booking using its opaque public token.',
    inputSchema:{type:'object',properties:{public_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['public_token'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.reschedule.prepare': {
    title:'Prepare booking reschedule',description:'Validate and preview a new time using the opaque booking manage token.',
    inputSchema:{type:'object',properties:{manage_token:{type:'string',pattern:'^[a-f0-9]{64}$'},start_at_utc:{type:'string',maxLength:40},guest_timezone:{type:'string',maxLength:80}},required:['manage_token','start_at_utc'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.reschedule.confirm': {
    title:'Confirm booking reschedule',description:'Apply the exact prepared reschedule after explicit confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  },
  'vp3.booking.cancel.prepare': {
    title:'Prepare booking cancellation',description:'Validate and preview cancellation using the opaque booking manage token.',
    inputSchema:{type:'object',properties:{manage_token:{type:'string',pattern:'^[a-f0-9]{64}$'}},required:['manage_token'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:false,debugging:false}
  },
  'vp3.booking.cancel.confirm': {
    title:'Confirm booking cancellation',description:'Cancel the exact prepared booking after explicit confirmation and idempotency validation.',
    inputSchema:{type:'object',properties:{confirmation_token:{type:'string',minLength:20,maxLength:2048},idempotency_key:{type:'string',minLength:8,maxLength:96},intent:{type:'object',additionalProperties:true}},required:['confirmation_token','idempotency_key','intent'],additionalProperties:false},
    annotations:{readOnlyHint:false,untrustedContentHint:true,consequentialHint:true,debugging:false}
  },
  ...COMMERCE_CATALOG_V160,
  ...CAMPAIGNS_CATALOG_V170,
  ...REWARDS_CATALOG_V180,
  'vp3.intent.resolve': {
    title:'Resolve profile intent',description:'Identify which currently available VP3 profile capabilities can help with a user goal without executing an action.',
    inputSchema:{type:'object',properties:{goal:{type:'string',minLength:1,maxLength:1000}},required:['goal'],additionalProperties:false},
    annotations:{readOnlyHint:true,untrustedContentHint:true,consequentialHint:false,debugging:false}
  }
})

function stable(value) {
  if (value === null || typeof value !== 'object') return JSON.stringify(value);
  if (Array.isArray(value)) return '[' + value.map(stable).join(',') + ']';
  return '{' + Object.keys(value).sort().map(k => JSON.stringify(k)+':'+stable(value[k])).join(',') + '}';
}

function safeError(code, message, retryable=false) {
  return {ok:false,error:{code,message,retryable}};
}

function transportIdV130() {
  try { if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID().replaceAll('-',''); } catch {}
  try {
    const bytes=new Uint8Array(16);globalThis.crypto?.getRandomValues?.(bytes);
    const value=[...bytes].map(v=>v.toString(16).padStart(2,'0')).join('');
    if (value.length===32) return value;
  } catch {}
  return (Date.now().toString(36)+Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2)).slice(0,64);
}

function referralTokenV130() {
  try {
    const token=String(new URL(globalThis.location?.href||'https://vp3.invalid/').searchParams.get('vp3_ref')||'').toLowerCase().trim();
    return /^[a-f0-9]{48}$/.test(token)?token:'';
  } catch { return ''; }
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
    this.webmcpSessionId=transportIdV130();
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
    const interactionId=transportIdV130();
    const startedAt=Date.now();
    try {
      this.onEvent({event:'tool_called',tool:name,interaction_id:interactionId});
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
          input:args,
          telemetry:{
            webmcp_session_id:this.webmcpSessionId,
            interaction_id:interactionId,
            agent_referral:referralTokenV130()
          }
        }),
        signal:options?.signal
      });
      let data;
      try { data=await response.json(); }
      catch { return safeError('INVALID_GATEWAY_RESPONSE','VP3 returned an invalid profile response.'); }
      if (!response.ok || data?.ok!==true) {
        const error=safeError(
          data?.error?.code || ('HTTP_'+response.status),
          data?.error?.message || 'The profile capability could not be completed.',
          Boolean(data?.error?.retryable)
        );
        this.onEvent({event:'tool_failed',tool:name,interaction_id:interactionId,duration_ms:Date.now()-startedAt,code:error.error.code});
        return error;
      }
      this.onEvent({event:'tool_completed',tool:name,interaction_id:interactionId,duration_ms:Date.now()-startedAt});
      return data;
    } catch (error) {
      if (options?.signal?.aborted || error?.name==='AbortError') {
        this.onEvent({event:'tool_cancelled',tool:name,interaction_id:interactionId,duration_ms:Date.now()-startedAt});
        return safeError('CANCELLED','The profile capability request was cancelled.');
      }
      this.onEvent({event:'tool_failed',tool:name,interaction_id:interactionId,duration_ms:Date.now()-startedAt,code:'NETWORK_ERROR'});
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
