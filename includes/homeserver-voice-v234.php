<?php
declare(strict_types=1);

/**
 * HomeServer v2.3 Section 5 — bounded local voice execution.
 *
 * Readiness probes do not create execution receipts. Actual synthesis and
 * transcription route through the canonical v2.3 execution contract.
 */
const VP3_HOMESERVER_VOICE_V234='vp3-homeserver-voice-v234-20260925';

function homeserver_voice_v234_status(int $userId,bool $force=false): array
{
    static $cache=[];
    if($userId<1)return ['available'=>false,'transcription_available'=>false,'reason'=>'signed_out'];
    if(!$force&&isset($cache[$userId]))return $cache[$userId];
    if(!function_exists('homeserver_execution_v220_can_route')||!homeserver_execution_v220_can_route($userId,'speech.status')){
        return $cache[$userId]=['available'=>false,'transcription_available'=>false,'reason'=>'homeserver_unavailable'];
    }
    try{
        $result=homeserver_execution_v220_execute($userId,'speech.status',[]);
        if(!is_array($result))$result=[];
        return $cache[$userId]=[
          'available'=>!empty($result['available']),
          'transcription_available'=>!empty($result['transcription_available']),
          'provider'=>mb_strimwidth(trim((string)($result['provider']??'piper')),0,80,''),
          'transcription_provider'=>mb_strimwidth(trim((string)($result['transcription_provider']??'whisper.cpp')),0,80,''),
          'max_text_chars'=>max(1,min(1000,(int)($result['max_text_chars']??220))),
          'max_audio_bytes'=>max(1024,min(512*1024,(int)($result['max_audio_bytes']??150*1024))),
          'voice_profile'=>is_array($result['voice_profile']??null)?[
            'voice'=>mb_strimwidth(trim((string)($result['voice_profile']['voice']??'')),0,120,''),
            'voice_source'=>mb_strimwidth(trim((string)($result['voice_profile']['voice_source']??'')),0,80,''),
            'ready'=>!empty($result['voice_profile']['ready']),
            'fallback'=>!empty($result['voice_profile']['fallback']),
        ]:[],
          'reason'=>!empty($result['available'])?'ready':'local_voice_unavailable',
        ];
    }catch(Throwable $e){
        return $cache[$userId]=[
          'available'=>false,'transcription_available'=>false,
          'reason'=>function_exists('homeserver_execution_v230_failure_class')
            ? homeserver_execution_v230_failure_class($e)
            : 'homeserver_unavailable',
        ];
    }
}

function homeserver_voice_v234_synthesize(int $userId,string $text): array
{
    $text=trim($text);
    if($text==='')throw new RuntimeException('Voice text is required.');
    $status=homeserver_voice_v234_status($userId);
    if(empty($status['available']))throw new RuntimeException('HomeServer local voice is unavailable.');
    $max=max(1,(int)($status['max_text_chars']??220));
    if(mb_strlen($text)>$max)throw new RuntimeException('Voice chunk exceeds the HomeServer relay limit.');

    $run=homeserver_execution_v230_execute($userId,'speech.synthesize',['text'=>$text]);
    $result=is_array($run['result']??null)?$run['result']:[];
    $encoded=(string)($result['audio_base64']??'');
    $audio=base64_decode($encoded,true);
    if(!is_string($audio)||strlen($audio)<44||substr($audio,0,4)!=='RIFF'||substr($audio,8,4)!=='WAVE'){
        throw new RuntimeException('HomeServer returned invalid local voice audio.');
    }
    $maxBytes=max(1024,(int)($status['max_audio_bytes']??150*1024));
    if(strlen($audio)>$maxBytes)throw new RuntimeException('HomeServer local voice audio exceeds the relay limit.');
    return [
      'audio'=>$audio,
      'content_type'=>'audio/wav',
      'provider'=>mb_strimwidth(trim((string)($result['provider']??'piper')),0,80,''),
      'voice'=>mb_strimwidth(trim((string)($result['voice']??'')),0,120,''),
      'voice_source'=>mb_strimwidth(trim((string)($result['voice_source']??'')),0,80,''),
      'bytes'=>strlen($audio),
      'execution'=>is_array($run['execution']??null)?$run['execution']:null,
    ];
}

function homeserver_voice_v234_transcribe(int $userId,string $audio): array
{
    $status=homeserver_voice_v234_status($userId);
    if(empty($status['transcription_available']))throw new RuntimeException('HomeServer local transcription is unavailable.');
    $max=max(1024,(int)($status['max_audio_bytes']??150*1024));
    if($audio===''||strlen($audio)>$max)throw new RuntimeException('Recorded audio exceeds the HomeServer relay limit.');
    if(strlen($audio)<44||substr($audio,0,4)!=='RIFF'||substr($audio,8,4)!=='WAVE'){
        throw new RuntimeException('HomeServer transcription requires PCM WAV audio.');
    }
    $run=homeserver_execution_v230_execute($userId,'speech.transcribe',[
      'audio_base64'=>base64_encode($audio),
    ]);
    $result=is_array($run['result']??null)?$run['result']:[];
    return [
      'text'=>mb_strimwidth(trim((string)($result['text']??'')),0,32000,''),
      'provider'=>mb_strimwidth(trim((string)($result['provider']??'whisper.cpp')),0,80,''),
      'model'=>mb_strimwidth(trim((string)($result['model']??'')),0,160,''),
      'execution'=>is_array($run['execution']??null)?$run['execution']:null,
    ];
}
