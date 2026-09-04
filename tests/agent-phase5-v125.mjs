import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const runtime=read('includes/agent-runtime-v125.php');
const brain=read('includes/agent-brain-v122.php');
const brainRuntime=read('includes/agent-brain-runtime-v125.php');
const activity=read('api/agent-activity-v94.php');
const streamApi=read('api/chat-stream-v121.php');
const streamAi=read('includes/ai-stream-v121.php');
const aiRuntime=read('includes/ai-runtime-v100.php');
const browser=read('chat-conversation-v131.js');
const voice=read('conversation-voice-v122.js');
const bootstrap=read('includes/bootstrap.php');
const phase4=read('tests/agent-phase4-v124.mjs');

const groups={
  '21 bounded background maintenance queue':[
    runtime.includes('agent_background_v125_enqueue'),
    runtime.includes("fopen($path,'x')"),
    runtime.includes('agent_background_v125_drain(3,900)'),
    runtime.includes('agent_background_v125_recover_orphans'),
    runtime.includes("glob($dir.'/*.json.working')"),
    runtime.includes("str_starts_with(basename($path),'failed-')"),
    runtime.includes("$raw['attempts']>=3"),
    runtime.includes("$kind==='conversation-summary'"),
    runtime.includes("$kind==='memory-reconcile'"),
    brain.includes('agent_background_v125_enqueue'),
    brainRuntime.includes('agent_brain_v122_refresh_conversation'),
    streamApi.includes("agent_background_v125_enqueue('conversation-summary'"),
    streamApi.includes("agent_background_v125_enqueue('memory-reconcile'"),
  ],
  '22 end-to-end request and AI tracing':[
    runtime.includes("header('X-Stonefellow-Trace: '"),
    runtime.includes("$_SERVER['HTTP_X_STONEFELLOW_TRACE']"),
    runtime.includes("agent_runtime_v125_append_jsonl('traces'"),
    runtime.includes('agent_runtime_v125_span'),
    streamApi.includes("agent_runtime_v125_span('chat.tools'"),
    streamApi.includes("agent_runtime_v125_span('chat.context'"),
    streamApi.includes("agent_runtime_v125_span('chat.ai_stream'"),
    streamApi.includes("'trace_id'=>agent_runtime_v125_trace_id()"),
    browser.includes("'X-Stonefellow-Trace':traceId"),
    browser.includes('response.headers.get(\'X-Stonefellow-Trace\')'),
    browser.includes("lastTraceId:''"),
    aiRuntime.includes("'trace_id'=>function_exists('agent_runtime_v125_trace_id')"),
    aiRuntime.includes("agent_runtime_v125_trace('ai.telemetry'"),
  ],
  '23 durable voice health telemetry':[
    voice.includes("action:'voice_health'"),
    voice.includes('recognition_errors:proof.recognitionErrors'),
    voice.includes('preserved_interruptions:proof.preservedInterruptions'),
    runtime.includes("agent_runtime_v125_append_jsonl('voice-health'"),
    runtime.includes("agent_runtime_v125_dir('voice-sessions')"),
    runtime.includes("'quality_score'=>round($quality,4)"),
    runtime.includes("$metrics['circuit_breaks']*.12"),
    activity.includes('agent_runtime_v125_health($user,$surface,$voice,$context)'),
    activity.includes("'runtime'=>'phase5-v125'"),
  ],
  '24 bounded provider retries and circuit breaker':[
    runtime.includes('agent_runtime_v125_breaker_state'),
    runtime.includes('agent_runtime_v125_breaker_allowed'),
    runtime.includes("$state['failures']>=4"),
    runtime.includes('agent_runtime_v125_resilient_call'),
    runtime.includes('min(1500,$baseDelayMs*(2**($attempt-1))+random_int(0,90))'),
    streamAi.includes('ai_v125_retryable_stream_result'),
    streamAi.includes('if(connection_aborted())return false'),
    streamAi.includes("!empty($result['partial'])"),
    streamAi.includes('in_array($status,[408,425,429],true)||$status>=500'),
    streamAi.includes('agent_runtime_v125_resilient_call'),
    streamAi.includes("$row['attempts']=$attempt"),
    streamAi.includes('\n                    2,\n                    160\n'),
  ],
  '25 idempotent streamed agent operations':[
    browser.includes("const idempotencyKey=runtimeId('voice-turn')"),
    browser.includes('idempotency_key:idempotencyKey'),
    streamApi.includes("$idempotencyScope='voice-chat:user-'.$userId"),
    streamApi.includes('agent_runtime_v125_idempotency_claim($idempotencyScope,$idempotencyKey,86400)'),
    streamApi.indexOf('agent_runtime_v125_idempotency_claim')<streamApi.indexOf("INSERT INTO chat_conversations"),
    streamApi.includes("($claim['state']??'')==='completed'"),
    streamApi.includes("if(empty($replay['ok']))"),
    streamApi.includes('agent_runtime_v125_idempotency_complete($idempotencyScope,$idempotencyKey,$done)'),
    streamApi.includes("agent_runtime_v125_idempotency_complete($idempotencyScope,$idempotencyKey,['ok'=>false"),
    !streamApi.includes('unlink(agent_runtime_v125_idempotency_path'),
    runtime.includes("in_array($status,['processing','completed'],true)"),
  ],
  'Phase 4 foundation remains intact':[
    phase4.includes('PHASE4_GROUPS'),
    bootstrap.includes('agent-runtime-v125.php'),
    bootstrap.includes('agent-brain-runtime-v125.php'),
    bootstrap.includes('agent-action-system-v124.php'),
    bootstrap.includes('agent_runtime_v125_boot();'),
    bootstrap.indexOf('agent_runtime_v125_boot();')<bootstrap.indexOf('agent_runtime_v126_housekeeping_maybe();'),
    fs.existsSync('includes/agent-action-system-v124.php'),
    fs.existsSync('includes/agent-memory-lifecycle-v123.php'),
    read('team-chat-v109.css').includes('.sf-online-rail-v109{display:none!important}'),
  ],
};

let pass=0,total=0;const failed=[];
for(const [name,checks] of Object.entries(groups)){
  const ok=checks.every(Boolean);pass+=ok?1:0;total++;
  console.log(`${ok?'PASS':'FAIL'} ${name} (${checks.filter(Boolean).length}/${checks.length})`);
  if(!ok)failed.push(name);
}
console.log(`PHASE5_GROUPS=${pass}/${total}`);
if(failed.length)throw new Error(`Failed: ${failed.join(', ')}`);
