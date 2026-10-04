(() => {
  'use strict';

  if (window.StonefellowStudioParticipants) return;

  const BUILD = 'studio-participants-section5-20261004';
  const cfg = window.STONEFELLOW_AGENT_CONTEXT || {};
  const endpoint = String(cfg.participantEndpoint || '/api/studio-participants.php');
  const csrf = String(cfg.csrf || '');
  const COMMANDS = Object.freeze([
    {id:'participants.context.refresh',category:'participants',description:'Refresh the current studio participant and voice-identity context.',mutates:false,verifiable:true,args:[]},
    {id:'participants.profile.save',category:'participants',description:'Create or update a participant profile for the current Stonefellow account.',mutates:true,verifiable:true,args:[
      {name:'participant_id',type:'number',required:false},{name:'linked_user_id',type:'number',required:false},{name:'display_name',type:'string',required:true},{name:'relationship',type:'string',required:false}
    ]},
    {id:'participants.consent.set',category:'voice_identity',description:'Set independent voice-recognition and voice-cloning consent for a participant. Enabling either requires direct user confirmation.',mutates:true,verifiable:true,destructive:true,args:[
      {name:'participant_id',type:'number',required:true},{name:'recognition_consent',type:'boolean',required:true},{name:'cloning_consent',type:'boolean',required:true},{name:'recognition_scope',type:'string',required:false}
    ]},
    {id:'participants.presence.record',category:'participants',description:'Record a manually assigned, account-linked, unknown, or provider-recognized speaker in the current studio session. Voice identity is conversational context only.',mutates:true,verifiable:true,args:[
      {name:'participant_id',type:'number',required:false},{name:'speaker_label',type:'string',required:false},{name:'recognition_method',type:'string',required:false},{name:'confidence',type:'number',required:false},{name:'provider_speaker_id',type:'string',required:false},{name:'presence_state',type:'string',required:false}
    ]},
    {id:'participants.voice.clone_from_recording',category:'voice_identity',description:'Create the signed-in user’s consented ElevenLabs voice clone from a retained recording. A direct user confirmation is required at execution time.',mutates:true,verifiable:true,destructive:true,args:[
      {name:'participant_id',type:'number',required:true},{name:'session_id',type:'number',required:true},{name:'recording_key',type:'string',required:true}
    ]},
  ]);
  let profiles = [];
  let context = { build: BUILD, count: 0, participants: [] };
  let loading = false;
  let lastError = '';
  let lastRefresh = 0;
  let refreshPromise = null;
  let refreshScope = '';
  let contextScope = '';
  let contextReceivedAt = 0;
  let epoch = 0;
  let mutationQueue = Promise.resolve();
  let mutationActive = false;
  let editorAgentRegistered = false;

  const cleanText = (value, limit = 160) => String(value ?? '').replace(/\s+/g, ' ').trim().slice(0, limit);
  const conversationId = () => Math.max(0, Number(window.StonefellowAgentContext?.conversationId?.() || cfg.conversationId || 0));
  const transcriptSessionId = () => Math.max(0, Number(window.STONEFELLOW_ARTIST_LISTENING_WORKSPACE?.api?.getState?.()?.sessionId || 0));

  const currentScope = () => {
    const sid = transcriptSessionId();
    return {cid: sid > 0 ? 0 : conversationId(), sid};
  };
  const scopeKey = scope => `${scope.cid}:${scope.sid}`;
  const emptyContext = () => ({build:BUILD,count:0,participants:[]});
  function currentParticipants() {
    if (Date.now() < contextReceivedAt || mutationActive || contextScope !== scopeKey(currentScope()) || Date.now() - contextReceivedAt >= 300000) return [];
    return (Array.isArray(context?.participants) ? context.participants : []).map(safeParticipant).slice(0,12);
  }

  function safeProfile(row) {
    return {
      id: Math.max(0, Number(row?.id || 0)),
      profile_key: cleanText(row?.profile_key, 40),
      linked_user_id: Math.max(0, Number(row?.linked_user_id || 0)),
      display_name: cleanText(row?.display_name, 120),
      relationship: cleanText(row?.relationship, 30),
      recognition_scope: cleanText(row?.recognition_scope, 30),
      recognition_consent: Boolean(row?.recognition_consent),
      cloning_consent: Boolean(row?.cloning_consent),
      voice: {
        provider: cleanText(row?.voice?.provider || 'elevenlabs', 30),
        recognition_enabled: Boolean(row?.voice?.recognition_enabled),
        clone_enabled: Boolean(row?.voice?.clone_enabled),
        recognition_verified: Boolean(row?.voice?.recognition_verified),
        clone_verified: Boolean(row?.voice?.clone_verified),
        verified: Boolean(row?.voice?.verified),
        has_recognition_binding: Boolean(row?.voice?.has_recognition_binding),
        has_clone_binding: Boolean(row?.voice?.has_clone_binding),
      },
    };
  }

  function safeParticipant(row) {
    const recognized = row?.recognized === true && Number(row?.participant_id) > 0 && row?.method !== 'unknown';
    return {
      participant_id: recognized ? Math.max(0, Number(row?.participant_id || 0)) : 0,
      name: recognized ? cleanText(row?.name, 120) : '',
      speaker_label: cleanText(row?.speaker_label, 80),
      relationship: recognized ? cleanText(row?.relationship || 'unknown', 30) : 'unknown',
      recognized,
      attribution_source: recognized ? cleanText(row?.attribution_source || 'owner_reported',40) : 'unidentified',
      method: cleanText(row?.method || 'unknown', 40),
      confidence: Math.max(0, Math.min(1, Number(row?.confidence || 0))),
      linked_user_id: recognized ? Math.max(0, Number(row?.linked_user_id || 0)) : 0,
      last_seen_at: cleanText(row?.last_seen_at, 40),
    };
  }

  function snapshot() {
    return {
      build: BUILD,
      ready: !loading && !lastError,
      loading,
      last_error: lastError,
      profile_count: profiles.length,
      profiles: profiles.map(safeProfile),
      context: {
        build: cleanText(context?.build || BUILD, 80),
        count: currentParticipants().length,
        authentication_authority: false,
        participants: currentParticipants(),
      },
    };
  }

  function publish(reason = 'update') {
    const detail = { reason, snapshot: snapshot() };
    try { window.dispatchEvent(new CustomEvent('stonefellow:studio-participants', { detail })); } catch (error) {}
    return detail.snapshot;
  }

  async function request(url, options = {}) {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(),30000);
    try {
      const response = await fetch(url, { credentials:'same-origin',cache:'no-store',...options,signal:controller.signal });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.ok) throw new Error(data?.error || `Participant request failed (${response.status}).`);
      return data;
    } finally { window.clearTimeout(timer); }
  }

  async function refresh(force = false) {
    const target = currentScope();
    const key = scopeKey(target);
    if (!force && key === contextScope && Date.now() >= lastRefresh && Date.now() - lastRefresh < 8000) return snapshot();
    if (refreshPromise && refreshScope === key) return refreshPromise;
    const token = ++epoch;
    refreshScope = key;
    context = emptyContext();
    contextScope = '';
    loading = true;
    lastError = '';
    publish('loading');
    const operation = (async () => {
      try {
        const params = new URLSearchParams({ action:'context' });
        if (target.cid > 0) params.set('conversation_id',String(target.cid));
        if (target.sid > 0) params.set('transcript_session_id',String(target.sid));
        const [profileData,contextData] = await Promise.all([
          request(`${endpoint}?action=profiles`),request(`${endpoint}?${params}`)
        ]);
        if (token !== epoch || key !== scopeKey(currentScope())) return snapshot();
        profiles = (Array.isArray(profileData.profiles) ? profileData.profiles : []).map(safeProfile);
        context = contextData.context && typeof contextData.context === 'object' ? contextData.context : emptyContext();
        contextScope = key;
        contextReceivedAt = lastRefresh = Date.now();
      } catch (error) {
        if (token === epoch) {context = emptyContext();contextScope='';lastError=cleanText(error?.message || error,240);}
      } finally {
        if (token === epoch) {loading=false;refreshPromise=null;publish(lastError?'error':'refresh');}
      }
      return snapshot();
    })();
    refreshPromise = operation;
    return operation;
  }

  function mutate(action, payload = {}) {
    const operation = mutationQueue.catch(() => {}).then(async () => {
      if (!csrf) throw new Error('Participant session token is unavailable.');
      ++epoch;
      refreshPromise = null;
      context = emptyContext();contextScope='';loading=false;
      mutationActive = true;
      publish('mutation');
      try {
      const data = await request(endpoint,{
        method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({csrf_token:csrf,action,...payload})
      });
      // A mutation receipt belongs to its original session; refresh current state.
      await refresh(true);
      return data;
      } catch (error) {
        context=emptyContext();contextScope='';lastError=cleanText(error?.message || error,240);
        throw error;
      } finally {mutationActive=false;publish(action);}
    });
    mutationQueue = operation;
    return operation;
  }

  async function saveProfile(input = {}) {
    return mutate('save_profile', {
      participant_id: Math.max(0, Number(input.participant_id || 0)),
      linked_user_id: Math.max(0, Number(input.linked_user_id || 0)),
      display_name: cleanText(input.display_name, 120),
      relationship: cleanText(input.relationship || 'guest', 30),
    });
  }

  async function setConsent(input = {}) {
    return mutate('set_consent', {
      participant_id: Math.max(0, Number(input.participant_id || 0)),
      recognition_consent: Boolean(input.recognition_consent),
      cloning_consent: Boolean(input.cloning_consent),
      recognition_scope: cleanText(input.recognition_scope || 'private', 30),
    });
  }

  async function cloneFromRecording(input = {}) {
    return mutate('clone_from_recording', {
      participant_id: Math.max(0, Number(input.participant_id || 0)),
      session_id: Math.max(0, Number(input.session_id || 0)),
      recording_key: cleanText(input.recording_key, 64),
      consent_confirmed: input.consent_confirmed === true,
    });
  }

  async function recordPresence(input = {}) {
    return mutate(input.recognition_method === 'voice' ? 'record_recognition' : 'record_presence', {
      conversation_id: Math.max(0, Number(input.conversation_id ?? currentScope().cid)),
      transcript_session_id: Math.max(0, Number(input.transcript_session_id ?? currentScope().sid)),
      presence_state: input.presence_state === 'left' ? 'left' : 'present',
      participant_id: Math.max(0, Number(input.participant_id || 0)),
      speaker_label: cleanText(input.speaker_label, 80),
      recognition_method: cleanText(input.recognition_method || 'manual', 40),
      confidence: Math.max(0, Math.min(1, Number(input.confidence || 0))),
      provider_speaker_id: cleanText(input.provider_speaker_id, 190),
    });
  }

  function agentContext() {
    return {
      build: BUILD,
      authentication_authority: false,
      conversation_id: currentScope().cid,
      transcript_session_id: currentScope().sid,
      participants: currentParticipants(),
    };
  }

  const api = Object.freeze({
    build: BUILD,
    snapshot,
    agentContext,
    refresh,
    saveProfile,
    setConsent,
    cloneFromRecording,
    recordPresence,
  });
  window.StonefellowStudioParticipants = api;
  window.STONEFELLOW_STUDIO_PARTICIPANTS = { build: BUILD, owner: 'studio-participants.js', authenticationAuthority: false };

  function commandArgs(command, descriptor) {
    const nested = command?.args && typeof command.args === 'object' && !Array.isArray(command.args) ? command.args : {};
    const args = { ...nested };
    for (const spec of descriptor.args || []) {
      if (command?.[spec.name] !== undefined) args[spec.name] = command[spec.name];
    }
    return args;
  }

  function userConfirmed(message) {
    if (typeof window.confirm !== 'function') return false;
    try { return window.confirm(message) === true; } catch (error) { return false; }
  }

  async function executeStudioCommand(command) {
    const args = command.args || {};
    switch (command.id) {
      case 'participants.context.refresh': return refresh(true);
      case 'participants.profile.save': return saveProfile(args);
      case 'participants.consent.set': {
        if ((Boolean(args.recognition_consent) || Boolean(args.cloning_consent))
          && !userConfirmed('Stonefellow wants to enable voice identity features for this participant. Continue?')) {
          return { editorStatus:'cancelled', ok:false, error:'User cancelled the participant voice-consent change.' };
        }
        return setConsent(args);
      }
      case 'participants.presence.record': return recordPresence(args);
      case 'participants.voice.clone_from_recording': {
        if (!userConfirmed('I confirm this retained recording contains my own voice and I want to send it to ElevenLabs to create my Stonefellow voice clone. Continue?')) {
          return { editorStatus:'cancelled', ok:false, error:'User cancelled voice cloning.' };
        }
        return cloneFromRecording({ ...args, consent_confirmed:true });
      }
      default: throw new Error(`Unsupported participant command: ${command.id}`);
    }
  }

  function verifyStudioCommand(command, before, after, raw) {
    if (raw?.editorStatus === 'cancelled') {
      return { status:'cancelled', result:cleanText(raw.error || 'User cancelled the participant action.', 240), verified:false };
    }
    const args = command.args || {};
    let ok = false;
    let evidence = raw || null;
    switch (command.id) {
      case 'participants.context.refresh':
        ok = after.loading === false && after.last_error === '';
        evidence = after.context;
        break;
      case 'participants.profile.save': {
        const participantId = Math.max(0, Number(raw?.participant_id || args.participant_id || 0));
        ok = participantId > 0 && after.profiles.some(row => row.id === participantId && (!args.display_name || row.display_name === cleanText(args.display_name, 120)));
        evidence = after.profiles.find(row => row.id === participantId) || null;
        break;
      }
      case 'participants.consent.set': {
        const participantId = Math.max(0, Number(raw?.participant_id || args.participant_id || 0));
        const profile = after.profiles.find(row => row.id === participantId) || null;
        ok = Boolean(profile)
          && profile.recognition_consent === Boolean(args.recognition_consent)
          && profile.cloning_consent === Boolean(args.cloning_consent);
        evidence = profile;
        break;
      }
      case 'participants.presence.record':
        ok = Math.max(0, Number(raw?.receipt?.id || 0)) > 0 && raw?.authentication_authority === false;
        evidence = raw?.receipt || null;
        break;
      case 'participants.voice.clone_from_recording': {
        const participantId = Math.max(0, Number(args.participant_id || 0));
        const profile = after.profiles.find(row => row.id === participantId) || null;
        ok = raw?.voice?.has_clone_binding === true || profile?.voice?.has_clone_binding === true;
        evidence = raw?.voice || profile?.voice || null;
        break;
      }
      default:
        ok = false;
    }
    return {
      status: ok ? 'success' : 'failed',
      result: ok ? `${command.id} verified.` : `${command.id} could not be verified.`,
      verified: ok,
      evidence,
    };
  }

  function registerEditorSurface() {
    const EditorAgent = window.StonefellowEditorAgent;
    if (!EditorAgent || editorAgentRegistered) return false;
    if (EditorAgent.hasSurface?.('participants')) {
      editorAgentRegistered = true;
      return true;
    }
    EditorAgent.registerSurface('participants', {
      label: 'Studio Participants',
      path: String(window.location?.pathname || '/chat.php'),
      commands: () => COMMANDS.map(row => ({ ...row, args: row.args.map(arg => ({ ...arg })) })),
      selection: () => ({
        recognized: agentContext().participants.filter(row => row.recognized),
        self: profiles.find(row => row.relationship === 'self') || null,
      }),
      snapshot: () => snapshot(),
      normalizeCommand: (command, descriptor) => ({
        id: descriptor.id,
        args: commandArgs(command, descriptor),
        mutates: descriptor.mutates !== false,
        destructive: descriptor.destructive === true,
        description: descriptor.description,
      }),
      execute: command => executeStudioCommand(command),
      verify: (command, before, after, raw) => verifyStudioCommand(command, before, after, raw),
    });
    editorAgentRegistered = true;
    return true;
  }

  window.addEventListener('stonefellow:editor-agent:ready', registerEditorSurface);
  window.addEventListener('stonefellow:agent-context', event => {
    const reason = String(event?.detail?.reason || '');
    if (reason === 'conversation' || reason === 'load' || reason === 'restore') void refresh(true);
  });
  window.addEventListener('stonefellow:transcription-editor-executed', () => void refresh(true));
  window.addEventListener('pageshow', () => void refresh(false));
  registerEditorSurface();
  publish('load');
  window.setTimeout(() => void refresh(true), 250);
})();

