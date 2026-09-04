import fs from 'node:fs';

const chat=fs.readFileSync('chat.php','utf8');
const voice=fs.readFileSync('chat-voice.js','utf8');
const brain=fs.readFileSync('includes/agent-brain-context-v142.php','utf8');
const bootstrap=fs.readFileSync('includes/bootstrap.php','utf8');
function check(ok,label){console.log(`${ok?'PASS':'FAIL'} ${label}`);if(!ok)process.exitCode=1;}

check(chat.includes("$controlBuild = 'chat-voice-canonical-20260903'"),'Chat publishes the canonical direct voice build');
check(chat.includes("$voiceAssetBuild = 'chat-voice-canonical-20260903'")&&chat.includes("$voiceCacheBuild = 'chat-voice-canonical-20260903-failover1'"),'Chat publishes canonical voice ownership with a fresh failover cache token');
check(chat.includes('data-chat-voice')&&chat.includes('chat-voice.js?v=')&&!chat.includes('chat-voice-v142.js?v='),'Chat loads one canonical voice owner');
check(!chat.includes('chat-barge-v141.js?v=')&&!chat.includes('chat-voice-v140.js?v='),'broken v140/v141 Chat stack is inactive');
check(voice.includes('speechRecognitionBarge:true')&&voice.includes('BARGE_SR_STARTED')&&voice.includes('BARGE_SR_RESULT'),'automatic barge uses native SpeechRecognition');
check(voice.includes('AudioContext')&&voice.includes('getFloatTimeDomainData')&&!voice.includes('current.start(track)'),'automatic barge uses processed near-field monitoring without the dead start(track) path');
check(voice.includes("echoCancellation:'all'")&&voice.includes('noiseSuppression:true'),'managed speech track requests system-audio echo cancellation and noise suppression');
check(voice.includes("interruptResponse('button')"),'red/thinking AI control is a guaranteed manual interrupt without disabling LISTEN');
check(voice.includes("cutOffForBarge(heard)")&&voice.includes("finishBargeCapture(finalText.trim(),'final')"),'spoken barge cuts response and submits completed interruption utterance');
check(voice.includes('activeRequest.controller.abort()')&&voice.includes('premium?.stop?.()'),'interruption stops response work and voice output');
check(bootstrap.includes('agent-brain-context-v142.php')&&!bootstrap.includes('agent-brain-context-v123.php'),'bootstrap uses fresh Agent Brain context pathname');
check(brain.includes('crc32((string)$feature)')&&!brain.includes('crc32($feature)'),'active Agent Brain casts numeric-looking feature keys before crc32');

console.log('CHAT_BARGE_CRC_V157=PASS');
