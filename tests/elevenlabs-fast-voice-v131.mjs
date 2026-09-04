import fs from 'node:fs';

const read=path=>fs.readFileSync(path,'utf8');
const admin=read('api/admin-elevenlabs-v102.php');
const voice=read('api/agent-voice-v117.php');
const client=read('premium-voice-v117.js');
const adminUi=read('admin/ai-elevenlabs-v102.js');
const aiStream=read('includes/ai-stream-v121.php');
const chatStream=read('api/chat-stream-v121.php');
const chatAdapter=read('chat-conversation-v131.js');

function assert(ok,label){
  console.log(`${ok?'PASS':'FAIL'} ${label}`);
  if(!ok)throw new Error(`Failed: ${label}`);
}

assert(admin.includes("'eleven_flash_v2_5' => 'Flash v2.5 · Fastest'"),'Admin exposes Flash v2.5 as the fastest model');
assert(admin.includes("setting('ai_elevenlabs_model_id', 'eleven_flash_v2_5')"),'Admin defaults missing model configuration to Flash v2.5');
assert(!admin.includes("'eleven_v3' =>"),'Admin no longer offers the incompatible stale v3 TTS selection');
assert(adminUi.includes('Stonefellow defaults to Flash v2.5 for the fastest realtime responses'),'Admin explains the fast voice default');

assert(voice.includes("setting('ai_elevenlabs_model_id', 'eleven_flash_v2_5')"),'Voice endpoint reads the saved model with Flash v2.5 default');
assert(voice.includes("getenv('ELEVENLABS_MODEL_ID')"),'Voice endpoint supports an explicit environment model override');
assert(voice.includes("'model_id' => $modelId"),'Voice request sends the selected fast model to ElevenLabs');
assert(voice.includes("'output_format' => $outputFormat"),'Voice tickets retain the selected output format');
assert(voice.includes("getenv('ELEVENLABS_OUTPUT_FORMAT') ?: 'mp3_22050_32'"),'Voice endpoint defaults to the lighter 22.05kHz/32kbps MP3 stream');
assert(voice.includes("CURLOPT_TCP_NODELAY"),'Voice transport enables TCP_NODELAY when cURL supports it');
assert(!voice.includes('optimize_streaming_latency='),'Voice endpoint does not depend on ElevenLabs deprecated latency query tuning');

assert(client.includes('const FIRST_CHUNK_LIMIT = 180'),'Premium client uses a short first voice chunk');
assert(client.includes('const CHUNK_LIMIT = 900'),'Premium client keeps later chunks large enough for natural cadence');
assert(client.includes('primeNextTicket()'),'Premium client prefetches later voice tickets while audio is playing');
assert(client.includes("proof.modelId=String(data?.model_id"),'Premium client records the active ElevenLabs model for runtime proof');
assert(client.includes("proof.outputFormat=String(data?.output_format"),'Premium client records the active audio format for runtime proof');
assert(client.includes('proof.lastFirstAudioMs=firstAudioMs'),'Premium client measures time to first audible ElevenLabs output');
assert(client.includes("new CustomEvent('stonefellow:voice-latency'"),'Premium client publishes a runtime voice latency event');

assert(aiStream.includes("'Accept-Encoding: identity'"),'Provider SSE disables response compression buffering');
assert(aiStream.includes('CURLOPT_BUFFERSIZE=>4096'),'Provider SSE uses a small receive buffer');
assert(aiStream.includes('CURL_HTTP_VERSION_2TLS'),'Provider SSE prefers HTTP/2 over TLS');
assert(aiStream.includes('CURLOPT_TCP_NODELAY'),'Provider SSE enables TCP_NODELAY when supported');
assert(chatStream.includes("header('X-Accel-Buffering: no')")&&chatStream.includes("@ini_set('output_buffering','0')"),'Chat NDJSON explicitly disables server buffering');
assert(chatStream.includes("chat_v121_emit(['type'=>'delta','delta'=>$delta])"),'Provider deltas are flushed into Chat NDJSON immediately');
assert(chatAdapter.includes("speech.current?.push(delta)"),'Chat pushes each streamed LLM delta directly into premium voice');

console.log('ELEVENLABS_FAST_VOICE_V131=PASS');
