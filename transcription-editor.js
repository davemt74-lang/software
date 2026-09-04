(() => {
  'use strict';

  const BUILD = 'transcription-editor';
  const COMMANDS = Object.freeze([
    {id:'transcription.document.create',category:'document',mutates:true,description:'Create a transcription document.'},
    {id:'transcription.document.open',category:'document',mutates:false,description:'Open a transcription document.'},
    {id:'transcription.document.delete',category:'document',mutates:true,description:'Delete a transcription document.'},
    {id:'transcription.document.rename',category:'document',mutates:true,description:'Rename the current transcription document.'},
    {id:'transcription.document.save',category:'document',mutates:true,description:'Replace and save the current transcript text.'},
    {id:'transcription.document.metadata.update',category:'document',mutates:true,description:'Update transcript tags, folder, association, track, or conversation.'},
    {id:'transcription.document.search',category:'document',mutates:false,description:'Search the current transcript text.'},
    {id:'transcription.library.filter',category:'library',mutates:false,description:'Filter the transcription library.'},
    {id:'transcription.folder.create',category:'library',mutates:true,description:'Create a transcription folder.'},
    {id:'transcription.folder.delete',category:'library',mutates:true,description:'Delete a transcription folder without deleting its transcripts.'},
    {id:'transcription.turn.update',category:'edit',mutates:true,description:'Correct a speaker label or speaker-turn text.'},
    {id:'transcription.selection.text.set',category:'selection',mutates:false,description:'Select a transcript text range.'},
    {id:'transcription.selection.turn.set',category:'selection',mutates:false,description:'Select a speaker turn.'},
    {id:'transcription.selection.clear',category:'selection',mutates:false,description:'Clear the transcription selection.'},
    {id:'transcription.view.prose',category:'view',mutates:false,description:'Use continuous prose editing view.'},
    {id:'transcription.view.turns',category:'view',mutates:false,description:'Use speaker-turn editing view.'},
    {id:'transcription.page.go',category:'view',mutates:false,description:'Open a transcript page.'},
    {id:'transcription.page.continuous',category:'view',mutates:false,description:'Use long-transcript continuous view.'},
    {id:'transcription.page.paged',category:'view',mutates:false,description:'Return to long-transcript page view.'},
    {id:'transcription.listening.start',category:'capture',mutates:true,description:'Start or resume live transcription.'},
    {id:'transcription.listening.stop',category:'capture',mutates:true,description:'Stop and finalize live transcription.'},
    {id:'transcription.listening.pause',category:'capture',mutates:true,description:'Pause live speech transcription without ending the session.'},
    {id:'transcription.listening.resume',category:'capture',mutates:true,description:'Resume a paused transcription session.'},
    {id:'transcription.recording.start',category:'capture',mutates:true,description:'Start retained audio recording while transcribing.'},
    {id:'transcription.recording.stop',category:'capture',mutates:true,description:'Stop and save retained audio.'},
    {id:'transcription.marker.add',category:'capture',mutates:true,description:'Add a timestamped marker.'},
    {id:'transcription.note.add',category:'capture',mutates:true,description:'Add a timestamped note.'},
    {id:'transcription.speaker_mode.set',category:'capture',mutates:true,description:'Set automatic or fixed speaker count mode.'},
    {id:'transcription.microphone.select',category:'capture',mutates:false,description:'Select the microphone used by retained audio and input tests.'},
    {id:'transcription.microphone.test',category:'capture',mutates:false,description:'Run the transcription microphone quality test.'},
    {id:'transcription.audio.seek',category:'audio',mutates:false,description:'Play retained audio covering a transcript timestamp.'},
    {id:'transcription.knowledge.promote_memory',category:'knowledge',mutates:true,description:'Promote selected or current transcript content to Agent Brain memory.'},
    {id:'transcription.knowledge.promote_knowledge',category:'knowledge',mutates:true,description:'Promote selected or current transcript content to the knowledge base.'},
    {id:'transcription.knowledge.promote_project_note',category:'knowledge',mutates:true,description:'Promote selected or current transcript content to project notes.'},
    {id:'transcription.ai.open',category:'ai',mutates:false,description:'Open the transcription AI panel.'},
    {id:'transcription.ai.close',category:'ai',mutates:false,description:'Close the transcription AI panel.'},
    {id:'transcription.ai.research.set',category:'ai',mutates:true,description:'Enable or disable AI research.'},
    {id:'transcription.ai.apps.set',category:'ai',mutates:true,description:'Choose transcription analysis apps.'},
    {id:'transcription.ai.app.activate',category:'ai',mutates:false,description:'Choose the active transcription analysis result.'},
    {id:'transcription.ai.analyze',category:'ai',mutates:true,description:'Run the selected transcription analysis apps.'},
    {id:'transcription.ai.save_brain',category:'ai',mutates:true,description:'Save the current AI analysis to Agent Brain.'},
    {id:'transcription.ai.save_knowledge',category:'ai',mutates:true,description:'Save the current AI analysis to the knowledge base.'},
    {id:'transcription.recording_library.refresh',category:'recording_library',mutates:false,description:'Refresh retained recording metadata.'},
    {id:'transcription.recording_library.search',category:'recording_library',mutates:false,description:'Search retained recordings.'},
    {id:'transcription.recording_library.select',category:'selection',mutates:false,description:'Select a retained recording.'},
    {id:'transcription.recording_library.play',category:'recording_library',mutates:false,description:'Play a retained recording, optionally from an absolute transcript time.'},
    {id:'transcription.recording_library.stop',category:'recording_library',mutates:false,description:'Stop retained recording playback.'},
    {id:'transcription.recording_library.rename',category:'recording_library',mutates:true,description:'Rename a retained recording.'},
    {id:'transcription.recording_library.favorite',category:'recording_library',mutates:true,description:'Set a retained recording favorite state.'},
    {id:'transcription.recording_library.delete',category:'recording_library',mutates:true,description:'Delete retained audio while preserving the transcript.'},
  ]);

  const arg = (name, type, required = false) => Object.freeze({name,type,required});
  const ARGUMENTS = Object.freeze({
    'transcription.document.create':[arg('folderId','number')],
    'transcription.document.open':[arg('sessionId','number',true)],
    'transcription.document.delete':[arg('sessionId','number')],
    'transcription.document.rename':[arg('title','string',true)],
    'transcription.document.save':[arg('text','string',true)],
    'transcription.document.metadata.update':[arg('tags','string|string[]'),arg('associationType','string'),arg('trackId','number'),arg('folderId','number'),arg('conversationId','number')],
    'transcription.document.search':[arg('query','string',true)],
    'transcription.library.filter':[arg('folder','string'),arg('query','string')],
    'transcription.folder.create':[arg('name','string',true)],
    'transcription.folder.delete':[arg('folderId','number',true)],
    'transcription.turn.update':[arg('segmentId','number'),arg('speaker','string'),arg('text','string')],
    'transcription.selection.text.set':[arg('start','number',true),arg('end','number',true)],
    'transcription.selection.turn.set':[arg('segmentId','number',true)],
    'transcription.page.go':[arg('page','number',true)],
    'transcription.note.add':[arg('text','string',true)],
    'transcription.speaker_mode.set':[arg('mode','string',true)],
    'transcription.microphone.select':[arg('deviceId','string')],
    'transcription.audio.seek':[arg('milliseconds','number',true)],
    'transcription.knowledge.promote_memory':[arg('text','string')],
    'transcription.knowledge.promote_knowledge':[arg('text','string')],
    'transcription.knowledge.promote_project_note':[arg('text','string')],
    'transcription.ai.research.set':[arg('enabled','boolean',true)],
    'transcription.ai.apps.set':[arg('apps','string[]',true)],
    'transcription.ai.app.activate':[arg('appId','string',true)],
    'transcription.ai.analyze':[arg('mode','string')],
    'transcription.recording_library.search':[arg('query','string'),arg('today','boolean'),arg('favorites','boolean')],
    'transcription.recording_library.select':[arg('sessionId','number'),arg('key','string'),arg('query','string')],
    'transcription.recording_library.play':[arg('sessionId','number'),arg('key','string'),arg('query','string'),arg('seconds','number')],
    'transcription.recording_library.rename':[arg('sessionId','number'),arg('key','string'),arg('query','string'),arg('name','string',true)],
    'transcription.recording_library.favorite':[arg('sessionId','number'),arg('key','string'),arg('query','string'),arg('favorite','boolean')],
    'transcription.recording_library.delete':[arg('sessionId','number'),arg('key','string'),arg('query','string')],
  });
  const DESTRUCTIVE = new Set([
    'transcription.document.delete',
    'transcription.folder.delete',
    'transcription.recording_library.delete',
  ]);

  const workspace = () => window.STONEFELLOW_ARTIST_LISTENING_WORKSPACE?.api || null;
  const capture = () => window.STONEFELLOW_ARTIST_LISTENING_V172?.api || null;
  const transcript = () => window.STONEFELLOW_ARTIST_LISTENING_TRANSCRIPT?.api || null;
  const ai = () => window.STONEFELLOW_ARTIST_LISTENING_AI?.api || null;
  const recordings = () => window.STONEFELLOW_ARTIST_RECORDINGS_V198?.api || null;

  function requireOwner(owner, name) {
    const value = owner();
    if (!value) throw new Error(`${name} capability owner is unavailable.`);
    return value;
  }

  function getState() {
    return {
      surface:'transcription',
      workspace:workspace()?.getState?.() || null,
      capture:capture()?.getState?.() || null,
      transcript:transcript()?.getState?.() || null,
      ai:ai()?.getState?.() || null,
      recordings:recordings()?.getState?.() || null,
    };
  }

  function getSelection() {
    const selection = workspace()?.getSelection?.() || {sessionId:0,text:{start:0,end:0,value:''},turn:null};
    return {
      surface:'transcription',
      document:selection.sessionId ? {sessionId:selection.sessionId} : null,
      text:selection.text || {start:0,end:0,value:''},
      turn:selection.turn || null,
      recording:recordings()?.getSelection?.() || null,
    };
  }

  function getCommands() {
    return COMMANDS.map(command => ({...command,args:(ARGUMENTS[command.id] || []).map(item => ({...item})),destructive:DESTRUCTIVE.has(command.id)}));
  }

  function validateArgs(command, args = {}) {
    const missing = (ARGUMENTS[command] || [])
      .filter(item => item.required && (args[item.name] === undefined || args[item.name] === null || args[item.name] === ''))
      .map(item => item.name);
    return missing.length ? `Missing required argument${missing.length === 1 ? '' : 's'}: ${missing.join(', ')}` : '';
  }

  function receiptState(state) {
    const workspace = state.workspace ? {
      ready:state.workspace.ready,
      sessionId:state.workspace.sessionId,
      current:state.workspace.current,
      filter:state.workspace.filter,
      view:state.workspace.view,
      paused:state.workspace.paused,
      liveSessionId:state.workspace.liveSessionId,
      editable:state.workspace.editable,
      microphoneId:state.workspace.microphoneId,
      selectedTurnId:state.workspace.selectedTurnId,
      sessionCount:Array.isArray(state.workspace.sessions)?state.workspace.sessions.length:0,
      folderCount:Array.isArray(state.workspace.folders)?state.workspace.folders.length:0,
      turnCount:Array.isArray(state.workspace.turns)?state.workspace.turns.length:0,
    } : null;
    const ai = state.ai ? {
      sessionId:state.ai.sessionId,
      open:state.ai.open,
      settingsOpen:state.ai.settingsOpen,
      researchEnabled:state.ai.researchEnabled,
      selectedApps:Array.isArray(state.ai.selectedApps)?[...state.ai.selectedApps]:[],
      activeApp:state.ai.activeApp,
      busy:state.ai.busy,
      hasReport:!!state.ai.report,
      liveWords:state.ai.liveWords,
      lastError:state.ai.lastError,
    } : null;
    const recordings = state.recordings ? {
      current:state.recordings.current,
      count:Array.isArray(state.recordings.library)?state.recordings.library.length:0,
      loading:state.recordings.loading,
      lastError:state.recordings.lastError,
    } : null;
    return {surface:'transcription',workspace,capture:state.capture,transcript:state.transcript,ai,recordings};
  }

  async function run(command, args) {
    const w = () => requireOwner(workspace, 'Transcription workspace');
    const c = () => requireOwner(capture, 'Transcription capture');
    const t = () => requireOwner(transcript, 'Long transcript');
    const a = () => requireOwner(ai, 'Transcription AI');
    const r = () => requireOwner(recordings, 'Recording library');
    switch (command) {
      case 'transcription.document.create': return w().createDocument(args);
      case 'transcription.document.open': return w().openDocument(args.sessionId);
      case 'transcription.document.delete': return w().deleteDocument(args.sessionId);
      case 'transcription.document.rename': return w().renameDocument(args.title);
      case 'transcription.document.save': return w().replaceText(args.text);
      case 'transcription.document.metadata.update': return w().updateMetadata(args);
      case 'transcription.document.search': return w().searchDocument(args.query);
      case 'transcription.library.filter': return w().filterLibrary(args);
      case 'transcription.folder.create': return w().createFolder(args.name);
      case 'transcription.folder.delete': return w().deleteFolder(args.folderId);
      case 'transcription.turn.update': return w().updateTurn(args);
      case 'transcription.selection.text.set': return w().selectText(args);
      case 'transcription.selection.turn.set': return w().selectTurn(args.segmentId);
      case 'transcription.selection.clear': return w().clearSelection();
      case 'transcription.view.prose': return w().setView('prose');
      case 'transcription.view.turns': return w().setView('turns');
      case 'transcription.page.go': return t().goPage(args.page);
      case 'transcription.page.continuous': return t().setView('continuous');
      case 'transcription.page.paged': return t().setView('page');
      case 'transcription.listening.start': return c().start();
      case 'transcription.listening.stop': return c().stop();
      case 'transcription.listening.pause': return w().pause();
      case 'transcription.listening.resume': return w().resume();
      case 'transcription.recording.start': return c().startRecording();
      case 'transcription.recording.stop': return c().stopRecording();
      case 'transcription.marker.add': return c().addMarker();
      case 'transcription.note.add': return c().addNote(args.text);
      case 'transcription.speaker_mode.set': return c().setSpeakerMode(args.mode);
      case 'transcription.microphone.select': return w().setMicrophone(args.deviceId || '');
      case 'transcription.microphone.test': return w().testMicrophone();
      case 'transcription.audio.seek': return w().seekAudio(args.milliseconds);
      case 'transcription.knowledge.promote_memory': return w().promote('memory', args.text || '');
      case 'transcription.knowledge.promote_knowledge': return w().promote('knowledge', args.text || '');
      case 'transcription.knowledge.promote_project_note': return w().promote('project', args.text || '');
      case 'transcription.ai.open': return a().open();
      case 'transcription.ai.close': return a().close();
      case 'transcription.ai.research.set': return a().setResearchEnabled(!!args.enabled);
      case 'transcription.ai.apps.set': return a().setApps(args.apps || []);
      case 'transcription.ai.app.activate': return a().setActiveApp(args.appId);
      case 'transcription.ai.analyze': return a().analyze(args.mode || 'manual');
      case 'transcription.ai.save_brain': return a().saveBrain();
      case 'transcription.ai.save_knowledge': return a().saveKnowledge();
      case 'transcription.recording_library.refresh': return r().refresh();
      case 'transcription.recording_library.search': return r().search(args);
      case 'transcription.recording_library.select': return r().select(args);
      case 'transcription.recording_library.play': return r().play(args);
      case 'transcription.recording_library.stop': return r().stop();
      case 'transcription.recording_library.rename': return r().rename(args);
      case 'transcription.recording_library.favorite': return r().favorite(args);
      case 'transcription.recording_library.delete': return r().delete(args);
      default: throw new Error(`Unsupported transcription command: ${command}`);
    }
  }

  function sameArray(a, b) {
    const left = Array.isArray(a) ? [...a].map(String).sort() : [];
    const right = Array.isArray(b) ? [...b].map(String).sort() : [];
    return left.length === right.length && left.every((value, index) => value === right[index]);
  }

  function verify(command, before, after, args = {}, result = null) {
    const norm = value => String(value ?? '').replace(/\s+/g,' ').trim();
    const sameList = (a,b) => {
      const left=(Array.isArray(a)?a:[]).map(value=>norm(value)).filter(Boolean).sort();
      const right=(Array.isArray(b)?b:[]).map(value=>norm(value)).filter(Boolean).sort();
      return left.length===right.length&&left.every((value,index)=>value===right[index]);
    };
    const currentRecording = after.recordings?.current || null;
    let ok = result !== false && result !== null && result !== undefined;
    let evidence = result;
    let method = 'return-value';
    switch (command) {
      case 'transcription.document.create': ok = Number(result?.id||0)>0 && after.workspace?.sessionId===Number(result.id); evidence=after.workspace?.current; method='state'; break;
      case 'transcription.document.open': ok = after.workspace?.sessionId === Number(args.sessionId || 0); evidence = after.workspace?.current; method='state'; break;
      case 'transcription.document.delete': { const id=Number(result?.sessionId||args.sessionId||before.workspace?.sessionId||0); ok=result?.deleted===true && id>0 && !(after.workspace?.sessions||[]).some(row=>Number(row?.id||0)===id); evidence={id,deleted:result?.deleted}; method='state'; break; }
      case 'transcription.document.rename': ok = norm(after.workspace?.current?.title) === norm(args.title); evidence = after.workspace?.current?.title; method='state'; break;
      case 'transcription.document.save': ok = norm(after.workspace?.documentText) === norm(args.text); evidence = after.workspace?.current; method='state'; break;
      case 'transcription.document.metadata.update': {
        const current=after.workspace?.current||{}; ok=Number(current.id||0)>0;
        if(args.tags!==undefined){const wanted=Array.isArray(args.tags)?args.tags:String(args.tags||'').split(',');ok=ok&&sameList(current.tags,wanted);}
        if(args.folderId!==undefined)ok=ok&&Number(current.folder?.id||0)===Number(args.folderId||0);
        if(args.conversationId!==undefined)ok=ok&&Number(current.conversationId||0)===Number(args.conversationId||0);
        evidence=current;method='state';break;
      }
      case 'transcription.document.search': ok = !!result && Number.isFinite(Number(result.count)) && Array.isArray(result.positions); method='return-value'; break;
      case 'transcription.library.filter': ok=Array.isArray(result); evidence={count:Array.isArray(result)?result.length:0,filter:after.workspace?.filter}; method='state'; break;
      case 'transcription.folder.create': ok=Number(result?.id||0)>0 && (after.workspace?.folders||[]).some(folder=>Number(folder?.id||0)===Number(result.id)); evidence=result; method='state'; break;
      case 'transcription.folder.delete': { const id=Number(result?.folderId||args.folderId||0); ok=result?.deleted===true && id>0 && !(after.workspace?.folders||[]).some(folder=>Number(folder?.id||0)===id); evidence=result; method='state'; break; }
      case 'transcription.turn.update': ok=Number(result?.id||0)===Number(args.segmentId||after.workspace?.selectedTurnId||0); if(args.speaker!==undefined)ok=ok&&norm(result?.speaker)===norm(args.speaker); if(args.text!==undefined)ok=ok&&norm(result?.text)===norm(args.text); evidence=result; method='state'; break;
      case 'transcription.selection.text.set': ok = after.workspace?.sessionId > 0 && getSelection().text.start === Number(args.start || 0) && getSelection().text.end === Number(args.end === undefined ? args.start || 0 : args.end); evidence = getSelection().text; method='state'; break;
      case 'transcription.selection.turn.set': ok = Number(getSelection().turn?.id || 0) === Number(args.segmentId || 0); evidence = getSelection().turn; method='state'; break;
      case 'transcription.selection.clear': ok = !getSelection().turn && getSelection().text.start === getSelection().text.end; evidence = getSelection(); method='state'; break;
      case 'transcription.view.prose': ok = after.workspace?.view === 'prose'; evidence = after.workspace?.view; method='state'; break;
      case 'transcription.view.turns': ok = after.workspace?.view === 'turns'; evidence = after.workspace?.view; method='state'; break;
      case 'transcription.page.go': ok = Number(after.transcript?.page || 0) === Number(args.page || 0); evidence = after.transcript; method='state'; break;
      case 'transcription.page.continuous': ok = after.transcript?.view === 'continuous'; evidence = after.transcript; method='state'; break;
      case 'transcription.page.paged': ok = after.transcript?.view === 'page'; evidence = after.transcript; method='state'; break;
      case 'transcription.listening.start': ok = after.capture?.active === true; evidence = after.capture; method='state'; break;
      case 'transcription.listening.stop': ok = after.capture?.active === false && after.capture?.pendingStop===false; evidence = after.capture; method='state'; break;
      case 'transcription.listening.pause': ok = after.workspace?.paused === true; evidence = after.workspace?.paused; method='state'; break;
      case 'transcription.listening.resume': ok = after.workspace?.paused === false; evidence = after.workspace?.paused; method='state'; break;
      case 'transcription.recording.start': ok = after.capture?.recordingActive === true; evidence = after.capture; method='state'; break;
      case 'transcription.recording.stop': ok = after.capture?.recordingActive === false && after.capture?.recordingUploading === false; evidence = after.capture; method='state'; break;
      case 'transcription.marker.add': ok = Number(after.capture?.markerCount || 0) > Number(before.capture?.markerCount || 0); evidence = after.capture?.markerCount; method='state'; break;
      case 'transcription.note.add': ok = Number(after.capture?.noteCount || 0) > Number(before.capture?.noteCount || 0); evidence = after.capture?.noteCount; method='state'; break;
      case 'transcription.speaker_mode.set': ok = String(after.capture?.speakerMode || '') === String(args.mode || ''); evidence = after.capture?.speakerMode; method='state'; break;
      case 'transcription.microphone.select': ok=String(after.workspace?.microphoneId||'')===String(args.deviceId||''); evidence=after.workspace?.microphoneId; method='state'; break;
      case 'transcription.microphone.test': ok=result?.ok===true&&norm(result?.label)!==''; evidence=result; method='return-value'; break;
      case 'transcription.audio.seek': ok=result===true; method='return-value'; break;
      case 'transcription.knowledge.promote_memory': ok=result?.promoted==='memory'&&Number(result?.sessionId||0)>0; evidence=result; method='server-ack'; break;
      case 'transcription.knowledge.promote_knowledge': ok=result?.promoted==='knowledge'&&Number(result?.sessionId||0)>0; evidence=result; method='server-ack'; break;
      case 'transcription.knowledge.promote_project_note': ok=result?.promoted==='project'&&Number(result?.sessionId||0)>0; evidence=result; method='server-ack'; break;
      case 'transcription.ai.open': ok = after.ai?.open === true; evidence = after.ai?.open; method='state'; break;
      case 'transcription.ai.close': ok = after.ai?.open === false; evidence = after.ai?.open; method='state'; break;
      case 'transcription.ai.research.set': ok = after.ai?.researchEnabled === !!args.enabled; evidence = after.ai?.researchEnabled; method='state'; break;
      case 'transcription.ai.apps.set': ok = sameArray(after.ai?.selectedApps, args.apps); evidence = after.ai?.selectedApps; method='state'; break;
      case 'transcription.ai.app.activate': ok = String(after.ai?.activeApp || '') === String(args.appId || ''); evidence = after.ai?.activeApp; method='state'; break;
      case 'transcription.ai.analyze': ok=!!result && !!after.ai?.report && !after.ai?.lastError; evidence=after.ai?.report; method='state'; break;
      case 'transcription.ai.save_brain': ok=result===true&&!after.ai?.lastError; method='server-ack'; break;
      case 'transcription.ai.save_knowledge': ok=result===true&&!after.ai?.lastError; method='server-ack'; break;
      case 'transcription.recording_library.refresh': ok=Array.isArray(result?.library); evidence={count:result?.library?.length||0}; method='state'; break;
      case 'transcription.recording_library.search': ok=Array.isArray(result); evidence={count:Array.isArray(result)?result.length:0}; method='return-value'; break;
      case 'transcription.recording_library.select': ok=!!result&&!!currentRecording&&Number(result.session_id||0)===Number(currentRecording.session_id||0)&&String(result.key||'')===String(currentRecording.key||''); evidence=currentRecording; method='state'; break;
      case 'transcription.recording_library.play': ok=!!result&&!!currentRecording&&Number(result.session_id||0)===Number(currentRecording.session_id||0)&&String(result.key||'')===String(currentRecording.key||''); evidence=currentRecording; method='state'; break;
      case 'transcription.recording_library.stop': ok=result===true; method='return-value'; break;
      case 'transcription.recording_library.rename': ok=!!result&&norm(result.name)===norm(args.name)&&(!currentRecording||String(currentRecording.key||'')!==String(result.key||'')||norm(currentRecording.name)===norm(args.name)); evidence=result; method='state'; break;
      case 'transcription.recording_library.favorite': { const wanted=args.favorite===undefined?!!result?.favorite:!!args.favorite;ok=!!result&&!!result.favorite===wanted&&(!currentRecording||String(currentRecording.key||'')!==String(result.key||'')||!!currentRecording.favorite===wanted);evidence=result;method='state';break; }
      case 'transcription.recording_library.delete': ok=result?.deleted===true && !(after.recordings?.library||[]).some(item=>Number(item.session_id||0)===Number(result.sessionId||0)&&String(item.key||'')===String(result.key||'')); evidence=result; method='state'; break;
      default: break;
    }
    return {ok:Boolean(ok), command, method, evidence};
  }

  async function execute(command, args = {}) {
    command = String(command || '');
    const spec = COMMANDS.find(item => item.id === command) || null;
    if (!spec) return {ok:false, command, args, error:`Unknown transcription command: ${command}`};
    const destructive = DESTRUCTIVE.has(command);
    const argumentError = validateArgs(command, args || {});
    if (argumentError) return {ok:false, command, destructive, args:{...(args || {})}, error:argumentError};
    const before = getState();
    try {
      const result = await run(command, args || {});
      const after = getState();
      const verification = verify(command, before, after, args || {}, result);
      const receipt = {ok:verification.ok, command, destructive, args:{...(args || {})}, result, verification, before:receiptState(before), after:receiptState(after)};
      window.dispatchEvent(new CustomEvent('stonefellow:transcription-editor-executed', {detail:receipt}));
      return receipt;
    } catch (error) {
      const after = getState();
      const receipt = {ok:false, command, destructive, args:{...(args || {})}, error:String(error?.message || error), before:receiptState(before), after:receiptState(after)};
      window.dispatchEvent(new CustomEvent('stonefellow:transcription-editor-executed', {detail:receipt}));
      return receipt;
    }
  }

  window.StonefellowTranscriptionEditor = {
    build:BUILD,
    loaded:true,
    surface:'transcription',
    getState,
    getSelection,
    getCommands,
    execute,
    verify,
  };

  const transcriptionEditor = window.StonefellowTranscriptionEditor;
  let EditorAgent = window.StonefellowEditorAgent || null;
  const editorAgentReady = EditorAgent
    ? Promise.resolve(EditorAgent)
    : import('/editor-agent.js?v=editor-agent-canonical-20260903')
        .then(() => window.StonefellowEditorAgent || null)
        .catch(() => null);
  const registryProof = window.STONEFELLOW_TRANSCRIPTION_EDITOR_AGENT = {
    build:BUILD,
    owner:'transcription-editor.js',
    registryBuild:String(EditorAgent?.build || ''),
    registered:false,
    capabilityCount:COMMANDS.length,
  };

  function normalizeRegistryCommand(command, descriptor) {
    const nested = command?.args && typeof command.args === 'object' && !Array.isArray(command.args) ? command.args : {};
    const args = {...nested};
    for (const spec of descriptor.args || []) {
      if (command?.[spec.name] !== undefined) args[spec.name] = command[spec.name];
    }
    return {
      id:descriptor.id,
      type:descriptor.id,
      args,
      destructive:descriptor.destructive === true,
      mutates:descriptor.mutates !== false,
      confirmed:command?.confirmed === true,
      description:String(descriptor.description || descriptor.id),
    };
  }

  async function executeRegistryCommand(command, context = {}) {
    if (command.destructive && command.confirmed !== true && context?.confirmed !== true) {
      const description = String(command.description || command.id).replace(/[.]+$/,'');
      const approved = typeof window.confirm === 'function'
        ? window.confirm(`Stonefellow wants to ${description}. Continue?`)
        : false;
      if (!approved) return {editorStatus:'cancelled',ok:false,error:'User cancelled the destructive transcription action.'};
    }
    return transcriptionEditor.execute(command.id, command.args || {});
  }

  function verifyRegistryCommand(command, before, after, raw) {
    if (raw?.editorStatus === 'cancelled') {
      return {status:'cancelled',result:String(raw.error || 'User cancelled the transcription action.'),verified:false};
    }
    if (!raw || raw.ok !== true || raw.verification?.ok !== true) {
      return {
        status:'failed',
        result:String(raw?.error || `${command.id} could not be verified by the Transcription Editor.`),
        verified:false,
        verification:raw?.verification || null,
      };
    }
    const method = String(raw.verification.method || 'transcription-state');
    return {
      status:'success',
      result:`${command.id} verified by ${method}.`,
      verified:true,
      verification:raw.verification,
    };
  }

  function registerEditorSurface() {
    if (!EditorAgent) return false;
    if (EditorAgent.hasSurface?.('transcription')) {
      registryProof.registered = true;
      registryProof.registryBuild = String(EditorAgent.build || '');
      return true;
    }
    EditorAgent.registerSurface('transcription', {
      label:'Transcription Editor',
      commands:() => transcriptionEditor.getCommands().map(command => ({...command,verifiable:true})),
      selection:() => transcriptionEditor.getSelection(),
      snapshot:() => receiptState(transcriptionEditor.getState()),
      normalizeCommand:normalizeRegistryCommand,
      execute:executeRegistryCommand,
      verify:verifyRegistryCommand,
    });
    registryProof.registered = true;
    registryProof.registryBuild = String(EditorAgent.build || '');
    window.dispatchEvent(new CustomEvent('stonefellow:transcription-editor-agent-ready', {detail:{build:BUILD,registryBuild:registryProof.registryBuild,commandCount:COMMANDS.length}}));
    return true;
  }

  void editorAgentReady.then(agent => {
    EditorAgent = agent || null;
    if (EditorAgent) registerEditorSurface();
  });

  window.dispatchEvent(new CustomEvent('stonefellow:transcription-editor-ready', {detail:{build:BUILD,commandCount:COMMANDS.length}}));
})();