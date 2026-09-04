import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const ops=read('includes/agent-ops-v126.php');
const runtime=read('includes/agent-runtime-v125.php');
const bootstrap=read('includes/bootstrap.php');
const healthApi=read('api/runtime-health-v126.php');
const status=read('admin/runtime-status.php');
const phpSelf=read('tests/runtime-phase6-v126.php');
const chat=read('chat.php');
const chatVoice=read('chat-voice.js');
const stems=read('admin/stems.php');
const video=read('video-editor.php');
const controller=read('conversation-voice-v122.js');
const rail=read('team-chat-v109.css');
const phase5=read('tests/agent-phase5-v125.mjs');

const groups={
  '26 current operator health and diagnostics':[
    ops.includes('agent_runtime_v126_health_snapshot'),
    ops.includes('agent_runtime_v126_job_health'),
    ops.includes('agent_runtime_v126_breaker_health'),
    ops.includes('agent_runtime_v126_voice_quality'),
    ops.includes("'buckets'=>$buckets"),
    healthApi.includes("$action==='snapshot'"),
    healthApi.includes("$action==='self_test'"),
    healthApi.includes("$action==='cleanup'"),
    status.includes('Stonefellow Production Runtime'),
    status.includes('Run Resilience Self-Test'),
    status.includes('/api/team-chat-v109.php'),
    !status.includes('/api/team-chat-v103.php'),
    !status.includes('team-chat-v108.js'),
    !status.includes('force-cache-bust-20260825-2'),
  ],
  '27 bounded retention and automatic housekeeping':[
    ops.includes("'traces'=>14*86400"),
    ops.includes("'voice-health'=>30*86400"),
    ops.includes("'voice-sessions'=>30*86400"),
    ops.includes("'idempotency'=>2*86400"),
    ops.includes("'breakers'=>7*86400"),
    ops.includes("'jobs-failed'=>14*86400"),
    ops.includes('agent_runtime_v126_cleanup'),
    ops.includes('agent_runtime_v126_housekeeping_maybe'),
    ops.includes('LOCK_EX|LOCK_NB'),
    bootstrap.includes('agent_runtime_v126_housekeeping_maybe();'),
    phpSelf.includes("touch($oldTrace,time()-20*86400)"),
    phpSelf.includes("touch($oldVoice,time()-40*86400)"),
  ],
  '28 privacy-safe observability redaction':[
    ops.includes('agent_runtime_v126_sensitive_key'),
    ops.includes('agent_runtime_v126_redact_string'),
    ops.includes('Bearer [redacted]'),
    ops.includes('[redacted-token]'),
    ops.includes('[email]'),
    ops.includes('[phone]'),
    ops.includes('[non-scalar omitted]'),
    runtime.includes("if(function_exists('agent_runtime_v126_safe_row'))$row=agent_runtime_v126_safe_row($bucket,$row)"),
    phpSelf.includes("'authorization'=>'Bearer secret-token-value'"),
    phpSelf.includes('sensitive-key redaction'),
  ],
  '29 deterministic resilience self-tests':[
    ops.includes('agent_runtime_v126_self_test'),
    ops.includes("$checks['breaker_opens']"),
    ops.includes("$checks['breaker_recovers']"),
    ops.includes("$checks['idempotency_replay']"),
    ops.includes("$checks['queue_dedupe']"),
    phpSelf.includes('agent_runtime_v126_self_test()'),
    phpSelf.includes('PHASE6_RUNTIME_SELFTEST=PASS'),
    phpSelf.includes('phase6_remove_tree($root)'),
    healthApi.includes("'runtime'=>'phase6-v126'"),
  ],
  '30 final cross-surface production acceptance':[
    bootstrap.includes("header('X-Stonefellow-Production: ' . STONEFELLOW_PRODUCTION_V126)"),
    bootstrap.includes('agent-ops-v126.php'),
    bootstrap.includes('agent-brain-context-v142.php')&&!bootstrap.includes('agent-brain-context-v123.php'),
    chat.includes('conversation-integration-v131-20260826')&&chat.includes('chat-voice.js')&&chat.includes('agent-context-v131.js')&&chat.includes('team-chat-admin-v109.js')&&!chat.includes('chat-voice-v140.js?v=')&&!chat.includes('chat-barge-v141.js?v=')&&!chat.includes('conversation-voice-v122.js?v=')&&!chat.includes('voiceStreamEndpoint')&&!chat.includes('chat-stream-v121.php'),
    chatVoice.includes('directOwner:true')&&chatVoice.includes('singleChatApi:true')&&chatVoice.includes('sharedConversationController:false')&&chatVoice.includes('speechRecognitionBarge:true')&&chatVoice.includes('new SpeechRecognitionCtor()')&&chatVoice.includes('BARGE_SR_STARTED')&&chatVoice.includes('handleVoiceApiFetch')&&!chatVoice.includes('idempotency_key')&&!chatVoice.includes('application/x-ndjson')&&chatVoice.includes('getFloatTimeDomainData')&&!chatVoice.includes('current.start(track)'),
    stems.includes('conversation-integration-v131-20260826')&&stems.includes('admin/stem-agent-v131.js')&&stems.includes('agent-context-v131.js')&&stems.includes('stem-tool-bridge-v127.js')&&stems.includes('stem-advanced-tools-v128.js')&&stems.includes('editor-voice-barge-v117.js'),
    video.includes('conversation-integration-v131-20260826')&&video.includes('agent-context-v131.js')&&video.includes('editor-agent-v131.js')&&video.includes('editor-voice-barge-v117.js'),
    controller.includes("const BUILD='conversation-integration-v131-20260826'")||controller.includes("const CONTROL_BUILD='voice-three-of-three-v157-20260829'"),
    controller.includes('StonefellowConversationVoiceV122=api')&&!controller.includes('StonefellowConversationVoiceV121')&&!controller.includes('StonefellowConversationVoiceV120'),
    rail.includes('width:48px')&&rail.includes('.sf-online-rail-v109{display:none!important}'),
    phase5.includes('PHASE5_GROUPS'),
    fs.existsSync('tests/agent-phase4-v124.mjs'),
    fs.existsSync('tests/agent-phase3-v123.mjs'),
    fs.existsSync('tests/conversation-phase2-v122.mjs'),
    fs.existsSync('tests/conversation-consolidation-v131.mjs'),
    fs.existsSync('tests/chat-voice-runtime.mjs'),
    fs.existsSync('tests/stem-agent-v131-integration.mjs'),
    status.includes("STONEFELLOW_RUNTIME_BUILD='production-hardening-v126-20260826'"),
  ],
  'All previous architecture phases remain present':[
    fs.existsSync('voice-lease-v122.js'),
    fs.existsSync('premium-voice-v117.js'),
    fs.existsSync('includes/agent-memory-lifecycle-v123.php'),
    fs.existsSync('includes/agent-action-system-v124.php'),
    fs.existsSync('includes/agent-runtime-v125.php'),
    fs.existsSync('includes/agent-ops-v126.php'),
    !chat.includes('chat-voice-v117.js'),
    rail.includes('@media(max-width:760px)'),
  ],
};

let pass=0,total=0;const failed=[];
for(const [name,checks] of Object.entries(groups)){
  const ok=checks.every(Boolean);pass+=ok?1:0;total++;
  console.log(`${ok?'PASS':'FAIL'} ${name} (${checks.filter(Boolean).length}/${checks.length})`);
  if(!ok)failed.push(name);
}
console.log(`PHASE6_GROUPS=${pass}/${total}`);
if(failed.length)throw new Error(`Failed: ${failed.join(', ')}`);
