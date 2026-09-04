import fs from 'node:fs';

const voice=fs.readFileSync('chat-voice.js','utf8');
const chat=fs.readFileSync('chat.php','utf8');
const assert=(ok,label)=>{console.log(`${ok?'PASS':'FAIL'} ${label}`);if(!ok)throw new Error(`Failed: ${label}`);};

assert(voice.includes("const BUILD='chat-voice-canonical-20260903'"),'v157 Agent Chat lifecycle controller is active');
assert(!chat.includes('premium-autoplay-v148.js?v='),'Chat does not load a separate autoplay wrapper');
assert(chat.includes('premium-voice-v117.js?v=')&&chat.indexOf('premium-voice-v117.js?v=')<chat.indexOf('chat-voice.js?v='),'premium voice loads directly before the existing Chat controller');
assert(voice.includes("if(voiceOn&&spoken){pendingIntroSpeech=spoken;setTimeout"),'persisted voice attempts the personalized intro from the Chat controller');
assert(voice.includes('function isAutoplayBlockedError')&&voice.includes('Browser blocked ElevenLabs audio'),'controller recognizes browser autoplay blocking');
assert(voice.includes("log('INTRO_AUTOPLAY_BLOCKED'")&&voice.includes("scheduleListening(80,'intro-autoplay-blocked')"),'blocked autoplay returns to active LISTEN without a false speech-end');
assert(voice.includes('pendingIntroSpeech&&voiceOn&&!processing&&!speaking&&!introRetryScheduled'),'queued intro retry is guarded against duplicate playback');
assert(voice.includes('speakAnswer(pendingIntroSpeech)'),'queued intro retries after premium audio unlock');
assert(voice.includes("if(pendingIntroSpeech&&normalizeSpeechText(pendingIntroSpeech)===normalizeSpeechText(currentSpokenText))pendingIntroSpeech=''"),'successful audible intro clears the pending retry only when speech actually starts');
assert(voice.includes("if(voiceOn){setAgentState('listening','Listening…');scheduleListening(0,'boot-persisted');}"),'persisted voice enters blue LISTEN immediately on page load');
assert(voice.includes("const unlockOnly=voiceOn&&!!pendingIntroSpeech&&!speaking&&!processing"),'LISTEN button unlocks a queued intro without turning persisted voice mode off');

console.log('CHAT_AUTOPLAY_LIFECYCLE_V157=PASS');
