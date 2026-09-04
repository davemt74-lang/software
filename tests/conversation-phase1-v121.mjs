import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const runtime = 'conversation-integration-v131-20260826';
const chat = read('chat.php');
const chatLegacy = read('chat.js');
const adapter = read('chat-voice.js');
const voice = read('conversation-voice-v122.js');
const premium = read('premium-voice-v117.js');
const barge = read('editor-voice-barge-v117.js');
const voiceApi = read('api/agent-voice-v117.php');
const chatStream = read('api/chat-stream-v121.php');
const aiStream = read('includes/ai-stream-v121.php');
const stem = read('admin/stem-agent-v131.js');
const video = read('editor-agent-v131.js');
const stems = read('admin/stems.php');
const stemApi = read('api/stem-agent-v105.php');
const activity = read('agent-activity-v94.js');
const videoHeader = read('video-header-v92.js');
const ht = read('.htaccess');

const checks = {
  'Phase 1 runtime is active in Chat and Studio': chat.includes(runtime) && stems.includes(runtime),

  // 1 — one LISTEN / conversation controller on all three surfaces.
  'legacy Agent Chat recognition is deliberately dormant': chat.includes('chatVoiceButtonLegacyDormant') && chat.includes('legacyDormant:true') && chatLegacy.includes('configureVoiceConversation();'),
  'Agent Chat loads one direct controller adapter': chat.includes('chat-voice.js') && adapter.includes('directOwner:true') && !chat.includes('conversation-voice-v122.js?v='),
  'Stem and Video retain shared controller interface': stem.includes('StonefellowConversationVoiceV122') && video.includes('StonefellowConversationVoiceV122') && voice.includes('window.StonefellowConversationVoiceV122=api'),
  'one v121 recognition lifecycle owns normal and barge modes': voice.includes("startRecognition('normal')") && voice.includes("startRecognition('barge')") && voice.includes('new SpeechRecognitionCtor()'),
  'shared LISTEN state crosses surfaces': voice.includes('stonefellow:voice-mode') && voice.includes('voiceKey(userId)') && adapter.includes('stonefellow:voice-mode:'),

  // 2 — explicit output API, no global speechSynthesis interception.
  'Agent Chat no longer loads global speechSynthesis interceptor': !chat.includes('chat-voice-v117.js') && !adapter.includes('speechSynthesis.speak =') && !voice.includes('speechSynthesis.speak ='),
  'Stonefellow output is explicit': voice.includes('createSpeechStream') && voice.includes('async function speak(text)') && premium.includes('StonefellowPremiumVoiceV122'),
  'browser speech exists only as explicit fallback': voice.includes('function browserSpeak') && voice.includes('window.speechSynthesis.speak(utterance)'),

  // 3 — model deltas reach ElevenLabs before the full model response completes.
  'voice Chat uses NDJSON streaming endpoint': adapter.includes("new URL('chat-stream-v121.php',chatUrl)") && adapter.includes('response.body.getReader()') && chatStream.includes('application/x-ndjson'),
  'OpenAI and Anthropic are requested in streaming mode': aiStream.includes("$payload['stream']=true") && aiStream.includes('response.output_text.delta') && aiStream.includes('content_block_delta'),
  'provider deltas are flushed immediately': aiStream.includes('$onDelta($delta)') && chatStream.includes("'type'=>'delta'") && adapter.includes('premiumStream.push(delta)'),
  'ElevenLabs stream starts from live sentence queue': adapter.includes('premium.createStream({') && premium.includes('takeReadySentence') && premium.includes('createStream(callbacks={})'),
  'PHP session lock is released before long streaming I/O': chatStream.includes('session_write_close()') && chatStream.indexOf('session_write_close()') < chatStream.indexOf('ai_v121_stream_chat_response'),

  // 4 — optimize time-to-first-audio.
  'first voice chunk is intentionally small': premium.includes('FIRST_CHUNK_LIMIT = 180') && premium.includes('CHUNK_LIMIT = 900'),
  'later sentence ticket is prefetched during current playback': premium.includes('function primeNextTicket()') && premium.includes('if(playing)primeNextTicket()') && premium.includes('prefetchedTickets'),
  'audio response is verified before playback': premium.includes('fetchAudio(streamUrl,signal)') && premium.includes('response.blob()') && premium.includes('current.onplaying'),
  'ElevenLabs remains Flash streaming': voiceApi.includes('eleven_flash_v2_5') && voiceApi.includes("'/stream?output_format='") && voiceApi.includes('X-Accel-Buffering: no'),

  // 5 — preserve an interruption instead of dropping its first words.
  'standby recognition listens while Stonefellow speaks': voice.includes("current.continuous=mode==='barge'") && voice.includes('bargeCandidate') && voice.includes('bargeRecognitionStarts'),
  'recognized pre-roll is preserved after interruption': voice.includes('usableBargeCandidate()') && voice.includes('preservedInterruptions') && voice.includes('finishInterruptCapture'),
  'self-output candidates are rejected': voice.includes('resemblesOutput(candidate,spokenOutput)') && voice.includes('echoCandidatesRejected'),
  'barge-in cancels active model stream': voice.includes('options.onInterrupt?.(') && adapter.includes('activeRequest.controller.abort()') && adapter.includes('interruptions'),
  'interrupted server turn keeps stable assistant id': chatStream.includes('assistant_message_id') && chatStream.includes("UPDATE chat_messages SET message=?,context_json=?") && chatStream.includes('ignore_user_abort(true)'),
  'client never duplicates an interrupted turn': adapter.includes('request.interrupted') && !adapter.includes('streamFallbacks'),

  // Existing v120 reliability guarantees must remain.
  'fatal recognition failures clear persisted LISTEN': adapter.includes("kind==='not-allowed'||kind==='service-not-allowed'") && adapter.includes('voiceOn=false;writeMode()'),
  'Stem remains linked to main conversation': stemApi.includes('chat_conversations') && stemApi.includes('chat_messages') && stemApi.includes('stem_agent_v131'),
  'device meters still yield to LISTEN': activity.includes('voiceActive()') && videoHeader.includes('voiceActive()'),
  'critical v121 assets revalidate': ht.includes('conversation-voice-v121') && ht.includes('chat-conversation-v121') && ht.includes('no-cache, must-revalidate'),
  'mobile Team Chat rail remains hidden': read('team-chat-v109.css').includes('@media(max-width:760px)') && read('team-chat-v109.css').includes('.sf-online-rail-v109{display:none!important}'),
};

const failed = Object.entries(checks).filter(([, ok]) => !ok).map(([name]) => name);
for (const [name, ok] of Object.entries(checks)) console.log(`${ok ? 'PASS' : 'FAIL'} ${name}`);
console.log(`PHASE1_CONVERSATION=${Object.values(checks).filter(Boolean).length}/${Object.keys(checks).length}`);
if (failed.length) throw new Error(`Failed: ${failed.join(', ')}`);
