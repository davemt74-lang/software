import fs from 'node:fs';

const chat=fs.readFileSync('chat.php','utf8');
const voice=fs.readFileSync('chat-voice.js','utf8');
const oldBarge=fs.readFileSync('editor-voice-barge-v117.js','utf8');
function assert(ok,label){console.log(`${ok?'PASS':'FAIL'} ${label}`);if(!ok)throw new Error(`Failed: ${label}`);}

assert(oldBarge.includes('__interruptRecoveryV132'),'historical wrapper remains archived only');
assert(!chat.includes('editor-voice-barge-v117.js?v=')&&!chat.includes('editor-voice-barge-v134.js?v=')&&!chat.includes('chat-barge-v141.js?v='),'Agent Chat loads no interruption wrapper/helper asset');
assert(voice.includes('function startBarge()')&&voice.includes('speechRecognitionBarge:true'),'direct Chat runtime owns native interruption detection');
assert(voice.includes('function interruptResponse(')&&voice.includes("interruptResponse('button')"),'direct Chat runtime owns guaranteed manual interruption');
assert(voice.includes('activeRequest.controller.abort()'),'interruption aborts an active normal Chat API request');
assert(voice.includes('premium?.stop?.()')&&voice.includes('speechSynthesis?.cancel()'),'interruption stops current voice playback');
assert(voice.includes("startListening('interrupt')"),'manual interruption immediately returns to direct native listening');
assert(voice.includes('BARGE_SR_STARTED')&&voice.includes('BARGE_SR_RESULT')&&voice.includes('cutOffForBarge(heard)'),'spoken barge is driven by native SpeechRecognition results');
assert(voice.includes("finishBargeCapture(finalText.trim(),'final')"),'completed interruption utterance becomes the next voice turn');
assert(voice.includes('AudioContext')&&voice.includes('getFloatTimeDomainData')&&!voice.includes('current.start(track)'),'interrupt detection uses processed near-field monitoring without replacing native recognition');
assert(voice.includes('singleChatApi:true')&&!voice.includes('application/x-ndjson'),'interrupt recovery remains inside the single-API Chat owner');
assert(!chat.includes('conversation-voice-v122.js?v='),'interrupt recovery does not route through shared conversation controller');

console.log('VOICE_INTERRUPT_RESUME_V142=PASS');
