import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const chat=read('chat.php');
const voice=read('chat-voice.js');
const legacy=read('chat-legacy-v108.php');

const checks={
  'canonical Chat controls cache marker is present': chat.includes("$controlBuild = 'chat-voice-canonical-20260903'"),
  'v131 conversation/server runtime remains': chat.includes("$runtimeBuild = 'conversation-integration-v131-20260826'"),
  'visible legacy LISTEN button remains in composer': legacy.includes('id="chatVoiceButton"') && legacy.includes('aria-label="Start voice conversation"'),
  'legacy chat.js voice owner is made dormant before load': chat.includes('chatVoiceButtonLegacyDormant') && chat.includes('STONEFELLOW_CHAT_VOICE_BOOT'),
  'one direct Chat voice asset is loaded': chat.includes('data-chat-voice') && chat.includes('chat-voice.js?v=') && !chat.includes('chat-voice-v142.js?v='),
  'old v140/v141 Chat stack is not loaded': !chat.includes('chat-voice-v140.js?v=') && !chat.includes('chat-barge-v141.js?v='),
  'shared conversation controller is not loaded by Agent Chat': !chat.includes('conversation-voice-v122.js?v='),
  'old Chat voice lease and barge assets are not loaded': !chat.includes('voice-lease-v122.js?v=') && !chat.includes('editor-voice-barge-v134.js?v='),
  'old layered Chat adapter and debug assets are not loaded': !chat.includes('chat-conversation-v131.js?v=') && !chat.includes('voice-debug-v138.js?v='),
  'separate voice stream backend is removed': !chat.includes('voiceStreamEndpoint') && !chat.includes('chat-stream-v121.php') && !voice.includes('application/x-ndjson') && !voice.includes('idempotency_key'),
  'direct runtime uses stable SpeechRecognition plus processed monitor': voice.includes('new SpeechRecognitionCtor()') && voice.includes("current.start()") && !voice.includes("current.start(track)") && voice.includes("echoCancellation:'all'"),
  'blue listening follows native onstart': voice.includes('current.onstart=()=>') && voice.includes("setAgentState('listening',pendingFinalTranscript?"),
  'interim transcript is written into composer': voice.includes('updateComposer(preview)') && voice.includes('Listening · ${preview}'),
  'final transcript waits for quiet-window then submits through existing form': voice.includes('queueFinalTranscript(finalTranscript)') && voice.includes('TURN_END_PAUSE_MS=1800') && voice.includes('submitVoiceTranscript(transcript)') && voice.includes('form.requestSubmit()'),
  'spoken turn reuses normal Chat API': voice.includes('function handleVoiceApiFetch(') && voice.includes('nativeFetch(inputArg,nextInit)') && voice.includes("payload={...payload,input_mode:'voice'}"),
  'successful Chat JSON is spoken': voice.includes("speakAnswer(String(data.answer||''))"),
  'voice mode remains persisted per user': voice.includes('stonefellow:voice-mode:') && voice.includes("localStorage.setItem(MODE_KEY,voiceOn?'1':'0')"),
  'current ElevenLabs output remains': chat.includes('premium-voice-v117.js?v=') && voice.includes('StonefellowPremiumVoiceV122'),
  'automatic ElevenLabs page-load recovery stays inside the direct owner': !chat.includes('premium-autoplay-v148.js?v=') && voice.includes('pendingIntroSpeech') && voice.includes('INTRO_AUTOPLAY_BLOCKED') && voice.includes('introRetryScheduled'),
  'current Agent Context remains': chat.includes('agent-context-v131.js?v=') && voice.includes('StonefellowAgentContext'),
  'native spoken barge lives in same owner': voice.includes('speechRecognitionBarge:true') && voice.includes('function startBarge()') && voice.includes('BARGE_SR_STARTED') && voice.includes('getFloatTimeDomainData'),
  'manual interruption remains in same owner': voice.includes("interruptResponse('button')") && voice.includes('activeRequest.controller.abort()'),
  'debug is built into the canonical owner rather than stacked asset': voice.includes("has('voice_debug')") && voice.includes('stonefellowVoiceDebug'),
  'Video Editor footer control remains': chat.includes('id="chatVideoEditorButton"') && chat.includes("url('/video-editor.php')"),
  'Studio and Video navigation preserves voice mode': voice.includes('a[href*="/admin/stems.php"],a[href*="/video-editor.php"]') && voice.includes("target.searchParams.set('voice','1')"),
  'active conversation id carries across editors': voice.includes("target.searchParams.set('conversation_id',String(cid))"),
  'state colors remain listening thinking responding': chat.includes('data-stonefellow-agent-state="listening"') && chat.includes('data-stonefellow-agent-state="processing"') && chat.includes('data-stonefellow-agent-state="speaking"'),
};

const failed=Object.entries(checks).filter(([,ok])=>!ok).map(([name])=>name);
for(const [name,ok] of Object.entries(checks))console.log(`${ok?'PASS':'FAIL'} ${name}`);
console.log(`CHAT_CONTROLS_V149=${Object.values(checks).filter(Boolean).length}/${Object.keys(checks).length}`);
if(failed.length)throw new Error(`Failed: ${failed.join(', ')}`);
