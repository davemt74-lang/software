import fs from 'node:fs';

const chat=fs.readFileSync('chat.php','utf8');
const voice=fs.readFileSync('chat-voice.js','utf8');
function assert(ok,label){console.log(`${ok?'PASS':'FAIL'} ${label}`);if(!ok)throw new Error(`Failed: ${label}`);}

assert(chat.includes("$controlBuild = 'chat-voice-canonical-20260903'"),'Chat uses the canonical direct voice build');
assert(chat.includes('chat-voice.js?v='),'Chat loads direct v142 voice asset');
assert(!chat.includes('chat-voice-v140.js?v=')&&!chat.includes('chat-barge-v141.js?v='),'Chat does not load the discarded v140/v141 stack');
assert(!chat.includes('conversation-voice-v122.js?v='),'Chat does not load shared conversation controller');
assert(!chat.includes('voice-debug-v138.js?v=')&&!chat.includes('voice-debug-v137.js?v='),'Chat does not stack a separate voice debug runtime');
assert(!chat.includes('chat-conversation-v131.js?v='),'Chat does not load layered conversation voice adapter');
assert(!chat.includes('voiceStreamEndpoint')&&!chat.includes('chat-stream-v121.php'),'Chat debug/runtime has no separate voice backend path');
assert(voice.includes("has('voice_debug')"),'v142 debug remains opt-in');
assert(voice.includes('stonefellowVoiceDebug'),'canonical owner has one built-in debug panel');
assert(voice.includes('SR_START_CALL')&&voice.includes('SR_STARTED')&&voice.includes('SR_RESULT')&&voice.includes('SR_ERROR'),'built-in debug records the normal native recognizer lifecycle');
assert(voice.includes('BARGE_SR_START_CALL')&&voice.includes('BARGE_SR_STARTED')&&voice.includes('BARGE_SR_RESULT')&&voice.includes('BARGE_SR_ERROR'),'built-in debug records native barge recognizer lifecycle');
assert(voice.includes('VOICE_API_START')&&voice.includes('VOICE_API_SUCCESS')&&voice.includes('VOICE_API_ERROR'),'built-in debug records the normal Chat API voice lifecycle');
assert(voice.includes('userActivation')&&voice.includes('directOwner:true')&&voice.includes('singleChatApi:true')&&voice.includes('sharedConversationController:false'),'runtime debug/telemetry exposes activation and direct single-API ownership');

console.log('VOICE_DEBUG_SINGLE_CONTROLLER_V142=PASS');
