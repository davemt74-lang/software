<?php
declare(strict_types=1);
// Reuse the real SQLite capture/Knowledge fixture and its baseline transcript tests.
require __DIR__.'/agent-screen-sessions-v1.php';
$before=$checks;
$file=tempnam(sys_get_temp_dir(),'screen-events-');file_put_contents($file,'RIFF'.pack('V',100).'WAVE'.str_repeat("\0",100));
$upload=['tmp_name'=>$file,'error'=>UPLOAD_ERR_OK];$calls=0;$sent=[];
$event=['type'=>'audio_event','text'=>'(laughter)','start'=>.3,'end'=>.8,'speaker_id'=>'untrusted-owner'];
$transcription=screen_audio_transcribe_v1($upload,$user,15,function($file,$model,$options)use(&$calls,&$sent,$event){$calls++;$sent=$options;return ['text'=>'','words'=>[$event]];});
check($calls===1&&$sent===['model_id'=>'scribe_v2','timestamps_granularity'=>'word','diarize'=>'false','tag_audio_events'=>'true'],
    'sound tags use the same single authorized tab transcription request without speaker attribution');
check($transcription['transcript']===''&&$transcription['audio_events']===[[
    'label'=>'laughter','start_seconds'=>.3,'end_seconds'=>.8,'provider'=>'elevenlabs','model'=>'scribe_v2','source'=>'shared_tab_audio','classification'=>'provider_label',
]],'event-only audio retains bounded provider labels and exact shared-tab provenance without inventing speech');
check(!str_contains(json_encode($transcription),'speaker_id')&&!str_contains(json_encode($transcription),'untrusted-owner'),
    'provider speaker hints are not promoted to identity');
$mixed=['text'=>'Hello (laughter) there.','words'=>[
    ['type'=>'word','text'=>'Hello','start'=>0,'end'=>.2],['type'=>'spacing','text'=>' '],$event,
    ['type'=>'spacing','text'=>' '],['type'=>'word','text'=>'there.','start'=>1.1,'end'=>1.5],
]];
$result=screen_audio_transcribe_v1($upload,$user,15,fn()=>$mixed);
check($result['transcript']===$mixed['text']&&isset($result['cues'])&&implode(' ',array_column($result['cues'],'text'))===$mixed['text'],
    'mixed speech and actual sound tags preserve the original transcript and timed cues');
check(count($result['audio_events'])===1&&$result['audio_events'][0]['label']==='laughter',
    'mixed audio exposes the event separately as a provider classification');
foreach([
    ['text'=>'(laughter)','start'=>-.1,'end'=>.2],['text'=>'(laughter)','start'=>2,'end'=>1],
    ['text'=>'(laughter)','start'=>0,'end'=>50],['text'=>'(laughter)','start'=>'0','end'=>1],
    ['text'=>'(laughter)','start'=>NAN,'end'=>1],['text'=>'<script>act now</script>','start'=>0,'end'=>1],
    ['text'=>str_repeat('x',81),'start'=>0,'end'=>1],
] as $invalid){
    check(screen_audio_events_v1([['type'=>'audio_event']+$invalid],15)===[],
        'malformed or unbounded provider event is discarded '.($checks-$before));
}
check(screen_audio_events_v1([['type'=>'word','text'=>'(coughing)','start'=>0,'end'=>1]],15)===[],
    'parenthesized words are not guessed to be non-speech events');
check(screen_audio_events_v1([['type'=>'other','text'=>'typing','start'=>0,'end'=>1]],15)===[],
    'unrecognized token types cannot become sound classifications');
check(count(screen_audio_events_v1(array_fill(0,20,$event),15))===8,'provider events are capped at eight per existing audio segment');
check(screen_audio_events_v1(['not-a-list'=>$event],15)===[],'an object-shaped words payload does not become event evidence');
$badTiming=$mixed;$badTiming['words'][2]['end']=50;
$fallback=screen_audio_transcribe_v1($upload,$user,15,fn()=>$badTiming);
check($fallback===['transcript'=>$mixed['text']],'invalid event timings preserve whole speech without false timed cues');

// Execute the exact response branch from the production API with the real capture
// writer. Authentication/CSRF/session gates are covered by the existing HTTP suite.
$api=file_get_contents(dirname(__DIR__).'/api/agent-screen-audio-v1.php');
if(!preg_match('/    if\(\$transcription\[\'transcript\'\][\s\S]*?(?=\n\}catch)/',$api,$branch))throw new RuntimeException('Audio API response branch unavailable.');
$sid='00000000-0000-4000-8000-000000000021';$id='00000000-0000-4000-8000-000000000022';
$at=gmdate('Y-m-d\TH:i:s\Z');$duration=15;screen_session_start_v1($pdo,$user,['session_id'=>$sid]);
eval($branch[0]);
check($response['ok']===true&&is_array($response['capture'])&&$response['capture']['body']['audio_events']===$transcription['audio_events'],
    'the actual API response branch stores and returns real event-only captures instead of discarding them');
$stored=screen_capture_v1($pdo,$user,$id);
check($stored['body']['transcript']===''&&$stored['body']['provider']==='elevenlabs'&&$stored['captured_at']===$at,
    'event-only capture retains its original session/time/provider without synthetic transcript content');
$transcription=['transcript'=>''];eval($branch[0]);
check($response['capture']===null,'a segment without speech or provider events remains an empty successful observation');
$calls=0;try{screen_audio_transcribe_v1($upload,['id'=>1,'denied'=>true],15,function()use(&$calls){$calls++;return [];});throw new RuntimeException('Expected entitlement rejection');}
catch(RuntimeException $e){check($e->getCode()===403&&$calls===0,'unpermitted audio cannot trigger an extra provider call');}
unlink($file);screen_session_delete_v1($pdo,$user,$sid,true);
echo 'AGENT_SCREEN_AUDIO_EVENTS_V1=PASS ('.($checks-$before).' additional cases)'."\n";
