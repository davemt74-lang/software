import fs from 'node:fs';

const chat=fs.readFileSync('chat.php','utf8');
const voice=fs.readFileSync('chat-voice.js','utf8');
const oldWrapper=fs.readFileSync('editor-voice-barge-v117.js','utf8');
function assert(ok,label){console.log(`${ok?'PASS':'FAIL'} ${label}`);if(!ok)throw new Error(`Failed: ${label}`);}

assert(oldWrapper.includes('__recognizerRecoveryV133'),'historical v133 recovery wrapper remains archived for context');
assert(!chat.includes('conversation-voice-v122.js?v=')&&!chat.includes('editor-voice-barge-v117.js?v=')&&!chat.includes('chat-voice-v140.js?v='),'Agent Chat no longer loads controller/recovery wrappers or old Chat owner');
assert(voice.includes("scheduleListening(260,'start-throw')")&&voice.includes('recognition=null'),'native start throw discards the bad recognizer and retries cleanly');
assert(voice.includes('if(!recognition)recognition=createRecognition()'),'direct owner creates a fresh normal recognizer when needed');
assert(voice.includes('recognitionStarting=false')&&voice.includes('recognitionListening=false')&&voice.includes('proof.ends+=1'),'native normal onend resets recognizer state');
assert(voice.includes("scheduleListening(reason==='pause-window'?100:reason==='echo-tail'?260:180,'onend')"),'unexpected native end returns to LISTEN, pause-window continuation is fast, and echo-tail recovery remains bounded');
assert(voice.includes("setAgentState('error','Microphone permission is blocked.')"),'permission failures stop retrying and surface honestly');
assert(voice.includes('bargeRecognition=null')&&voice.includes('bargeRestartTimer=setTimeout(()=>startBarge(),180)'),'native barge recognizer can recover independently while Stonefellow is speaking');
assert(voice.includes('directOwner:true')&&voice.includes('singleChatApi:true')&&voice.includes('sharedConversationController:false'),'recovery remains inside direct single-API Chat owner');

console.log('VOICE_RECOGNIZER_RECOVERY_V148=PASS');
