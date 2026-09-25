<?php
declare(strict_types=1);

$GLOBALS['v235_ops']=[];
$GLOBALS['v235_fail']=false;

function homeserver_execution_v220_can_route(int $userId,string $operation): bool
{
    return $userId===7&&in_array($operation,['speech.status','speech.synthesize','speech.transcribe','agent.infer.local'],true);
}
function homeserver_execution_v220_execute(int $userId,string $operation,array $payload=[]): array
{
    $GLOBALS['v235_ops'][]=['layer'=>'v220','operation'=>$operation,'payload'=>$payload];
    if($operation!=='speech.status')throw new RuntimeException('unexpected v220 operation');
    return [
      'available'=>true,'transcription_available'=>true,'provider'=>'piper','transcription_provider'=>'whisper.cpp',
      'max_text_chars'=>220,'max_audio_bytes'=>150*1024,
      'voice_profile'=>['voice'=>'en_US-lessac-medium','voice_source'=>'global','ready'=>true,'fallback'=>false],
    ];
}
function homeserver_execution_v220_registry(int $userId): array
{
    return [
      'available'=>$userId===7,
      'capabilities'=>[
        'unified_execution'=>[
          'profile_safe_local_inference'=>[
            'operation'=>'agent.infer.local','stateless'=>true,'local_only'=>true,
            'tools_enabled'=>false,'caller_supplied_context_only'=>true,
          ],
        ],
      ],
    ];
}
function homeserver_execution_v230_failure_class(Throwable $e): string
{
    return str_contains(strtolower($e->getMessage()),'timeout')?'timeout':'homeserver_unavailable';
}
function homeserver_execution_v230_execute(int $userId,string $operation,array $payload=[]): array
{
    $GLOBALS['v235_ops'][]=['layer'=>'v230','operation'=>$operation,'payload'=>$payload];
    if($GLOBALS['v235_fail'])throw new RuntimeException('HomeServer relay timeout');
    $execution=['version'=>'2.3','request_id'=>str_repeat('a',32),'domain'=>str_starts_with($operation,'speech.')?'voice':'agent_compute','operation'=>$operation,'route'=>'homeserver','status'=>'completed','fallback_used'=>false,'failure_class'=>'none','duration_ms'=>12,'result_meta'=>[]];
    if($operation==='speech.synthesize'){
        $wav='RIFF'.str_repeat("\0",4).'WAVE'.str_repeat("\0",96);
        return ['ok'=>true,'result'=>['audio_base64'=>base64_encode($wav),'provider'=>'piper','voice'=>'en_US-lessac-medium','voice_source'=>'global','bytes'=>strlen($wav)],'execution'=>$execution];
    }
    if($operation==='speech.transcribe'){
        return ['ok'=>true,'result'=>['text'=>'local transcript','provider'=>'whisper.cpp','model'=>'tiny.en','local'=>true],'execution'=>$execution];
    }
    if($operation==='agent.infer.local'){
        $messages=$payload['messages']??[];
        assert(is_array($messages)&&count($messages)>=2);
        assert(($messages[0]['role']??'')==='system');
        assert(str_contains((string)$messages[0]['content'],'Approved source'));
        assert(!str_contains((string)$messages[0]['content'],'PRIVATE-SENTINEL-NOT-APPROVED'));
        return ['ok'=>true,'result'=>['reply'=>'Local profile answer','provider'=>'ollama','model'=>'llama-test','compute_source'=>'homeserver_local','stateless'=>true,'tools_enabled'=>false],'execution'=>$execution];
    }
    throw new RuntimeException('unexpected v230 operation');
}

require dirname(__DIR__).'/includes/homeserver-voice-v234.php';
require dirname(__DIR__).'/includes/homeserver-profile-agent-v235.php';

$status=homeserver_voice_v234_status(7,true);
assert($status['available']===true);
assert($status['transcription_available']===true);
assert(count(array_filter($GLOBALS['v235_ops'],fn($x)=>$x['layer']==='v230'))===0,'readiness probe must not create execution receipt');

$synth=homeserver_voice_v234_synthesize(7,'Short local sentence.');
assert($synth['content_type']==='audio/wav');
assert(str_starts_with($synth['audio'],'RIFF'));
assert(($synth['execution']['operation']??'')==='speech.synthesize');

$wav='RIFF'.str_repeat("\0",4).'WAVE'.str_repeat("\0",96);
$transcript=homeserver_voice_v234_transcribe(7,$wav);
assert($transcript['text']==='local transcript');
assert(($transcript['execution']['operation']??'')==='speech.transcribe');

assert(homeserver_profile_v235_supported(7)===true);
$history=[
 ['role'=>'assistant','message'=>'Earlier public answer'],
 ['role'=>'user','message'=>'What is the public plan?'],
];
$context=[
 ['source'=>'profile:identity','title'=>'Public profile','text'=>'Public identity'],
 ['source'=>'profile:knowledge:9','title'=>'Published plan','text'=>'The approved public launch is October.'],
];
$messages=homeserver_profile_v235_messages('What is the public plan?',$history,$context);
$userCopies=array_values(array_filter($messages,fn($m)=>($m['role']??'')==='user'&&($m['content']??'')==='What is the public plan?'));
assert(count($userCopies)===1,'current question should not be duplicated from history');

$answer=homeserver_profile_v235_answer(7,'What is the public plan?',$history,$context);
assert(is_array($answer)&&$answer['answer']==='Local profile answer');
assert($answer['provider']==='ollama');
assert(($answer['execution']['operation']??'')==='agent.infer.local');
$last=homeserver_profile_v235_last();
assert($last['attempted']===true&&$last['success']===true);

$GLOBALS['v235_fail']=true;
$failed=homeserver_profile_v235_answer(7,'What is the public plan?',$history,$context);
assert($failed===null);
$last=homeserver_profile_v235_last();
assert($last['attempted']===true&&$last['success']===false&&$last['failure_class']==='timeout');

echo "HomeServer v2.3 Section 5 voice/profile parity runtime: PASS\n";
