import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const routing=read('includes/homeserver-execution-routing-v220.php');
const execution=read('includes/homeserver-local-execution-v230.php');
const voice=read('includes/homeserver-voice-v234.php');
const profile=read('includes/homeserver-profile-agent-v235.php');
const profileApi=read('api/profile-agent.php');
const voiceApi=read('api/homeserver-voice-v234.php');
const agentVoice=read('api/agent-voice-v117.php');
const bootstrap=read('includes/bootstrap.php');
const workflow=read('.github/workflows/homeserver-runtime-journey.yml');

const warmStart=agentVoice.indexOf("if ($action === 'warm') {");
const warmEnd=agentVoice.indexOf("if (!in_array($action, ['ticket', 'speak'], true))",warmStart);
const warmBlock=warmStart>=0&&warmEnd>warmStart?agentVoice.slice(warmStart,warmEnd):'';

const checks=[
 ['Section 5 helpers load after governed actions',bootstrap.indexOf('homeserver-governed-actions-v233.php')<bootstrap.indexOf('homeserver-voice-v234.php')&&bootstrap.indexOf('homeserver-voice-v234.php')<bootstrap.indexOf('homeserver-profile-agent-v235.php')],
 ['routing allowlists stateless local inference',routing.includes("'agent.infer.local'")],
 ['routing allowlists voice readiness, transcription and synthesis',["speech.status","speech.transcribe","speech.synthesize"].every(x=>routing.includes("'"+x+"'"))],
 ['stateless local inference is classified as agent compute',/agent\.infer\.local/.test(execution)&&/agent_compute/.test(execution)],
 ['voice readiness is read-safe while audio operations remain explicit',/speech\.status/.test(execution)&&/str_starts_with\(\$op,'speech\.'\)/.test(execution)],
 ['voice readiness probe bypasses receipt writer',/homeserver_execution_v220_execute\(\$userId,'speech\.status'/.test(voice)&&!/homeserver_execution_v230_execute\(\$userId,'speech\.status'/.test(voice)],
 ['actual synthesis uses canonical receipt execution',/homeserver_execution_v230_execute\(\$userId,'speech\.synthesize'/.test(voice)],
 ['actual transcription uses canonical receipt execution',/homeserver_execution_v230_execute\(\$userId,'speech\.transcribe'/.test(voice)],
 ['voice helper validates RIFF/WAVE audio and strict base64',/RIFF/.test(voice)&&/WAVE/.test(voice)&&/base64_decode\(\$encoded,true\)/.test(voice)],
 ['Profile Agent helper uses only stateless agent.infer.local',/agent\.infer\.local/.test(profile)&&!/'agent\.chat'/.test(profile)],
 ['Profile Agent helper requires advertised privacy-safe capability flags',/caller_supplied_context_only/.test(profile)&&/tools_enabled/.test(profile)&&/local_only/.test(profile)],
 ['Profile Agent local prompt is built only from approved context argument',/approvedContext/.test(profile)&&/Approved source/.test(profile)],
 ['public Profile Agent tries HomeServer before legacy Cloud answer',profileApi.indexOf('homeserver_profile_v235_answer')<profileApi.indexOf('chat_remote_answer')],
 ['public Profile Agent stores sanitized HomeServer compute metadata',/homeserver_compute/.test(profileApi)&&/failure_class/.test(profileApi)&&/execution/.test(profileApi)],
 ['Agent voice warm path can select HomeServer local voice',/homeserver_local/.test(agentVoice)&&/homeserver-speech-status/.test(agentVoice)],
 ['Agent voice one-use stream can return HomeServer WAV',/homeserver_voice_v234_synthesize/.test(agentVoice)&&/Content-Type: audio\/wav/.test(agentVoice)],
 ['ElevenLabs remains the first readiness authority',warmBlock.indexOf('stonefellow_voice_v244_verify')>=0&&warmBlock.indexOf('stonefellow_voice_v244_verify')<warmBlock.indexOf('stonefellow_voice_v234_homeserver_status')],
 ['HomeServer transcription API requires chat access and CSRF',/chat\.access/.test(voiceApi)&&/hash_equals\(csrf_token\(\)/.test(voiceApi)],
 ['Section 5 runtime journey lints and executes contract/unit tests',/homeserver-v235-voice-profile-contract\.mjs/.test(workflow)&&/homeserver-v235-voice-profile-unit\.php/.test(workflow)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`HomeServer v2.3 Section 5 voice/profile parity contract: ${checks.length}/${checks.length} passed`);
