<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/phone-recording-speech-v1.php';
function subscription_has_entitlement(array $user,string $key):bool{return !empty($user['ai']);}
function check(bool $condition,string $name):void{if(!$condition)throw new RuntimeException($name);echo 'PASS '.$name.PHP_EOL;}
function denied(callable $call,string $name,bool $started=false):void{
    try{$call();throw new RuntimeException('Expected rejection: '.$name);}
    catch(VP3PhoneRecordingSpeechErrorV1 $error){check($error->requestStarted===$started,$name);}
}
$dir=sys_get_temp_dir().'/vp3-phone-speech-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$mp3=$dir.'/recording.mp3';$bad=$dir.'/bad.mp3';
// MPEG1 layer III, 128 kbps / 44.1 kHz: two complete 417-byte frames.
$frame="\xff\xfb\x90\x00".str_repeat("\0",413);
file_put_contents($mp3,$frame.$frame);
file_put_contents($bad,'ID3'.str_repeat("\0",829));
$user=['id'=>7,'is_active'=>1,'ai'=>true];$calls=0;
try{
    $transport=static function($path,$options,$owner)use(&$calls,$mp3):array{
        $calls++;check($path===realpath($mp3),'private MP3 path passed to adapter');
        check($options['model_id']==='scribe_v2'&&$options['diarize']==='true'&&$options['timestamps_granularity']==='word','saved-call speech uses timestamped provider diarization');
        check($owner['id']===7&&!isset($options['xi-api-key']),'transport fixtures receive no provider secret');
        return ['text'=>'Hello there. A second voice.','words'=>[
            ['text'=>'Hello','start'=>.1,'end'=>.5,'speaker_id'=>'speaker_0','confidence'=>.9],
            ['text'=>'there.','start'=>.6,'end'=>1.2,'speaker_id'=>'speaker_0','confidence'=>.8],
            ['text'=>'A','start'=>1.3,'end'=>1.4,'speaker_id'=>'speaker_1'],
            ['text'=>'second','start'=>1.5,'end'=>1.9,'speaker_id'=>'speaker_1'],
            ['text'=>'voice.','start'=>2,'end'=>2.5,'speaker_id'=>'speaker_1'],
        ]];
    };
    $result=vp3_phone_recording_transcribe_v1($mp3,3,$user,$transport);
    check($calls===1&&count($result['segments'])===2,'one provider dispatch creates speaker-separated segments');
    check($result['segments'][0]['speaker']==='Speaker 1'&&$result['segments'][1]['speaker']==='Speaker 2','provider labels stay neutral rather than asserting account identity');
    check($result['segments'][0]['text']==='Hello there.'&&$result['segments'][0]['started_ms']===100&&$result['segments'][0]['ended_ms']===1200,'timed segments preserve transcript text and timing');
    check($result['segments'][0]['confidence']===.8&&$result['segments'][1]['confidence']===null,'confidence remains unknown unless supported');
    check($result['source_hash']===hash_file('sha256',$mp3)&&$result['timing_available'],'result is bound to exact verified recording');
    $again=vp3_phone_recording_transcribe_v1($mp3,3,$user,static fn()=>['text'=>'你好，世界。','words'=>[
        ['text'=>'你好，','start'=>0,'end'=>.5,'speaker_id'=>'speaker_0'],['text'=>'世界。','start'=>.5,'end'=>1,'speaker_id'=>'speaker_0'],
    ]]);
    check($again['segments'][0]['text']==='你好，世界。','non-Latin transcript text is not altered by token joining');
    $untimed=vp3_phone_recording_transcribe_v1($mp3,3,$user,static fn()=>['text'=>'A complete untimed message.']);
    check(!$untimed['timing_available']&&$untimed['segments'][0]['started_ms']===0&&$untimed['segments'][0]['ended_ms']===0&&$untimed['segments'][0]['speaker']==='Unidentified speaker','untimed fallback invents neither speech onsets nor identity');
    $id3=$dir.'/metadata.mp3';file_put_contents($id3,"ID3\x04\x00\x00\x00\x00\x00\x04".'test'.$frame.$frame);
    check(vp3_phone_recording_mp3_v1($id3,3)['size']===848,'valid ID3 metadata before MP3 frames is supported');
    $large=$dir.'/larger-than-tab.mp3';file_put_contents($large,str_repeat($frame,2700));
    check(vp3_phone_recording_mp3_v1($large,120)['size']>1048576,'MP3 processing is independent of the tab-audio one-MB limit');
    $truncated=$dir.'/truncated.mp3';file_put_contents($truncated,$frame.substr($frame,0,32));
    denied(fn()=>vp3_phone_recording_transcribe_v1($truncated,3,$user,$transport),'truncated later MP3 frame is rejected before dispatch');
    $oversized=$dir.'/oversized.mp3';$oversizedStream=fopen($oversized,'wb');ftruncate($oversizedStream,25*1024*1024+1);fclose($oversizedStream);
    denied(fn()=>vp3_phone_recording_transcribe_v1($oversized,120,$user,$transport),'recording byte limit is checked before dispatch');
    denied(fn()=>vp3_phone_recording_transcribe_v1($bad,3,$user,$transport),'metadata without real MP3 frames is rejected before dispatch');
    denied(fn()=>vp3_phone_recording_transcribe_v1($mp3,3601,$user,$transport),'recordings beyond one hour are explicitly bounded');
    denied(fn()=>vp3_phone_recording_transcribe_v1($mp3,3,['id'=>7,'ai'=>false],$transport),'AI eligibility is checked before dispatch');
    denied(fn()=>vp3_phone_recording_transcribe_v1($mp3,3,['id'=>7,'is_active'=>0,'ai'=>true],$transport),'inactive owner cannot dispatch speech');
    denied(fn()=>vp3_phone_recording_transcribe_v1($mp3,3,$user,static fn()=>['text'=>'Missing words.','words'=>[['text'=>'Missing','start'=>0,'end'=>1]]]),'incomplete provider timing cannot silently lose transcript content',true);
    denied(fn()=>vp3_phone_recording_transcribe_v1($mp3,3,$user,static fn()=>['text'=>'No','words'=>[['text'=>'No','start'=>3,'end'=>1]]]),'invalid word timing is rejected without truncation',true);
    denied(fn()=>vp3_phone_recording_transcribe_v1($mp3,3,$user,static fn()=>['text'=>'Late','words'=>[['text'=>'Late','start'=>9,'end'=>10]]]),'timing outside verified recording bounds is rejected',true);
    denied(fn()=>vp3_phone_recording_transcribe_v1($mp3,3,$user,static fn()=>['text'=>str_repeat('x',8001)]),'long untimed output is blocked instead of inventing segment boundaries',true);
    denied(fn()=>vp3_phone_recording_transcribe_v1($mp3,3,$user,static function(){throw new RuntimeException('simulated timeout');}),'transport interruption is marked as an uncertain dispatched request',true);
    echo 'PHONE_RECORDING_SPEECH_V1=PASS'.PHP_EOL;
}finally{
    foreach(glob($dir.'/*')?:[] as $path)unlink($path);rmdir($dir);
}
