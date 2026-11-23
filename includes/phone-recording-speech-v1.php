<?php
declare(strict_types=1);

/** Bounded saved-call speech input; provider speaker labels never assert identity. */
final class VP3PhoneRecordingSpeechErrorV1 extends RuntimeException
{
    public function __construct(string $message,int $code=422,public readonly bool $requestStarted=false)
    { parent::__construct($message,$code); }
}

function vp3_phone_recording_speech_text_v1(mixed $value,int $limit): string
{
    if(!is_string($value)||strlen($value)>1000000||preg_match('//u',$value)!==1)
        throw new VP3PhoneRecordingSpeechErrorV1('The speech service returned invalid transcript text.',503,true);
    $text=trim($value);
    $length=function_exists('mb_strlen')?mb_strlen($text,'UTF-8'):preg_match_all('/./us',$text);
    if($length===false||$length>$limit)
        throw new VP3PhoneRecordingSpeechErrorV1('The recording transcript exceeds the processing limit.',503,true);
    return $text;
}

/** A fixed provider media import must contain actual MPEG layer III frames. */
function vp3_phone_recording_mp3_v1(string $path,int $durationSeconds): array
{
    $real=realpath($path);
    $size=$real!==false&&is_file($real)?filesize($real):false;
    if($size===false||$size<32||$size>25*1024*1024)
        throw new VP3PhoneRecordingSpeechErrorV1('Use an available MP3 recording no larger than 25 MB.');
    if($durationSeconds<1||$durationSeconds>3600)
        throw new VP3PhoneRecordingSpeechErrorV1('Saved-call transcription supports recordings up to one hour.');
    $stream=fopen($real,'rb');
    if($stream===false)throw new VP3PhoneRecordingSpeechErrorV1('The private recording could not be opened.');
    try{
        $head=fread($stream,10);$offset=0;
        if(str_starts_with($head,'ID3')){
            if(strlen($head)!==10||!in_array(ord($head[3]),[2,3,4],true))
                throw new VP3PhoneRecordingSpeechErrorV1('Unsupported MP3 metadata.');
            $tagSize=0;
            foreach(str_split(substr($head,6,4)) as $byte){
                $number=ord($byte);if($number>127)throw new VP3PhoneRecordingSpeechErrorV1('Invalid MP3 metadata size.');
                $tagSize=($tagSize<<7)|$number;
            }
            $offset=10+$tagSize+((ord($head[3])===4&&(ord($head[5])&16)!==0)?10:0);
        }
        if($offset+4>$size||fseek($stream,$offset)!==0)
            throw new VP3PhoneRecordingSpeechErrorV1('The recording has no MP3 audio frames.');
        $header=fread($stream,4);
        $frame=vp3_phone_recording_mp3_frame_v1($header);
        if($frame===null||$offset+$frame>$size)
            throw new VP3PhoneRecordingSpeechErrorV1('The recording is not a complete MP3 audio file.');
        // Check a second frame when present. A legacy trailing ID3v1 tag is metadata.
        if($offset+$frame+4<=$size){
            fseek($stream,$offset+$frame);$next=fread($stream,4);
            $nextFrame=vp3_phone_recording_mp3_frame_v1($next);
            if(($nextFrame===null&&!str_starts_with($next,'TAG'))
                ||($nextFrame!==null&&$offset+$frame+$nextFrame>$size))
                throw new VP3PhoneRecordingSpeechErrorV1('The MP3 audio frame sequence is invalid.');
        }
    }finally{fclose($stream);}
    $hash=hash_file('sha256',$real);
    if(!is_string($hash))throw new VP3PhoneRecordingSpeechErrorV1('The recording could not be verified.');
    return ['path'=>$real,'size'=>$size,'source_hash'=>$hash];
}

function vp3_phone_recording_mp3_frame_v1(string $header): ?int
{
    if(strlen($header)<4)return null;
    [$a,$b,$c]=array_map('ord',str_split(substr($header,0,3)));
    $version=($b>>3)&3;$layer=($b>>1)&3;$rate=($c>>2)&3;$bitrate=($c>>4)&15;
    if($a!==255||($b&224)!==224||$version===1||$layer!==1||$rate===3||$bitrate===0||$bitrate===15)return null;
    $bitrates=$version===3?[0,32,40,48,56,64,80,96,112,128,160,192,224,256,320]:[0,8,16,24,32,40,48,56,64,80,96,112,128,144,160];
    $sample=[44100,48000,32000][$rate]/($version===3?1:($version===2?2:4));
    return (int)floor(($version===3?144000:72000)*$bitrates[$bitrate]/$sample)+(($c>>1)&1);
}

function vp3_phone_recording_speech_response_v1(array $data,string $hash,int $durationSeconds): array
{
    $text=vp3_phone_recording_speech_text_v1($data['text']??null,250000);
    if($text==='')throw new VP3PhoneRecordingSpeechErrorV1('The recording contains no transcribed speech.',503,true);
    $words=$data['words']??null;
    if($words===null||$words===[]){
        vp3_phone_recording_speech_text_v1($text,8000);
        return ['text'=>$text,'segments'=>[[
            'key'=>substr(hash('sha256',$hash.'|untimed|'.$text),0,32),
            'text'=>$text,'speaker'=>'Unidentified speaker','started_ms'=>0,'ended_ms'=>0,'confidence'=>null,
        ]],'timing_available'=>false];
    }
    if(!is_array($words)||!array_is_list($words)||count($words)>50000)
        throw new VP3PhoneRecordingSpeechErrorV1('The speech service returned an invalid word manifest.',503,true);
    $segments=[];$current=null;$speakers=[];$cursor=0;$previousStart=0;
    $finish=static function()use(&$segments,&$current,$hash):void{
        if($current===null)return;
        $current['text']=trim($current['text']);
        if($current['text']!==''){
            $current['key']=substr(hash('sha256',$hash.'|'.count($segments).'|'.json_encode($current,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),0,32);
            $segments[]=$current;
        }
        $current=null;
        if(count($segments)>500)throw new VP3PhoneRecordingSpeechErrorV1('The recording has too many speech segments.',503,true);
    };
    foreach($words as $word){
        if(!is_array($word))throw new VP3PhoneRecordingSpeechErrorV1('The speech service returned an invalid word.',503,true);
        $type=$word['type']??'word';
        if(in_array($type,['spacing','audio_event'],true))continue;
        if($type!=='word')throw new VP3PhoneRecordingSpeechErrorV1('Unsupported speech token type.',503,true);
        $token=$word['text']??null;
        if(!is_string($token)||$token===''||strlen($token)>8000||preg_match('//u',$token)!==1)
            throw new VP3PhoneRecordingSpeechErrorV1('Invalid speech token text.',503,true);
        $start=$word['start']??null;$end=$word['end']??null;
        if((!is_int($start)&&!is_float($start))||(!is_int($end)&&!is_float($end))
            ||!is_finite((float)$start)||!is_finite((float)$end)||$start<0||$end<$start||$end>$durationSeconds+5)
            throw new VP3PhoneRecordingSpeechErrorV1('Invalid speech timing.',503,true);
        $startMs=(int)round($start*1000);$endMs=(int)round($end*1000);
        if($startMs<$previousStart)throw new VP3PhoneRecordingSpeechErrorV1('Speech timing is out of order.',503,true);
        $previousStart=$startMs;
        $at=strpos($text,$token,$cursor);
        if($at===false||preg_match('/\A\s*\z/u',substr($text,$cursor,$at-$cursor))!==1)
            throw new VP3PhoneRecordingSpeechErrorV1('Timed speech does not cover the full transcript.',503,true);
        $piece=substr($text,$cursor,$at-$cursor).$token;$cursor=$at+strlen($token);
        $speakerId=$word['speaker_id']??null;
        if($speakerId!==null&&(!is_string($speakerId)||strlen($speakerId)>80||preg_match('/\A[A-Za-z0-9_.:-]+\z/D',$speakerId)!==1))
            throw new VP3PhoneRecordingSpeechErrorV1('Invalid provider speaker label.',503,true);
        if($speakerId===null)$speaker='Unidentified speaker';
        else{
            if(!isset($speakers[$speakerId]))$speakers[$speakerId]='Speaker '.(count($speakers)+1);
            if(count($speakers)>20)throw new VP3PhoneRecordingSpeechErrorV1('Too many provider speaker labels.',503,true);
            $speaker=$speakers[$speakerId];
        }
        $confidence=$word['confidence']??null;
        if(!is_int($confidence)&&!is_float($confidence))$confidence=null;
        elseif(!is_finite((float)$confidence)||$confidence<0||$confidence>1)$confidence=null;
        if($current!==null&&($current['speaker']!==$speaker||$endMs-$current['started_ms']>45000||strlen($current['text'])+strlen($piece)>7800))$finish();
        if($current===null)$current=['text'=>'','speaker'=>$speaker,'started_ms'=>$startMs,'ended_ms'=>$endMs,'confidence'=>$confidence];
        $current['text'].=$piece;$current['ended_ms']=max($current['ended_ms'],$endMs);
        $current['confidence']=$current['confidence']===null||$confidence===null?null:min($current['confidence'],$confidence);
        if(strlen($current['text'])>8000)throw new VP3PhoneRecordingSpeechErrorV1('A speech segment exceeds its text limit.',503,true);
    }
    if(preg_match('/\A\s*\z/u',substr($text,$cursor))!==1)
        throw new VP3PhoneRecordingSpeechErrorV1('Timed speech omitted transcript content.',503,true);
    $finish();
    if(!$segments)throw new VP3PhoneRecordingSpeechErrorV1('No timed speech segments were returned.',503,true);
    return ['text'=>$text,'segments'=>$segments,'timing_available'=>true];
}

function vp3_phone_recording_transcribe_v1(string $path,int $durationSeconds,array $user,?callable $transport=null): array
{
    if((int)($user['id']??0)<1||(isset($user['is_active'])&&empty($user['is_active'])))
        throw new VP3PhoneRecordingSpeechErrorV1('An active recording owner is required.',403);
    if(function_exists('subscription_has_entitlement')&&!subscription_has_entitlement($user,'main_ai.access'))
        throw new VP3PhoneRecordingSpeechErrorV1('AI access is unavailable for this recording owner.',403);
    $file=vp3_phone_recording_mp3_v1($path,$durationSeconds);
    $options=['model_id'=>'scribe_v2','timestamps_granularity'=>'word','diarize'=>'true','tag_audio_events'=>'false'];
    if($transport!==null){
        try{$data=$transport($file['path'],$options,$user);}
        catch(Throwable $error){throw new VP3PhoneRecordingSpeechErrorV1('Speech processing was interrupted; review its outcome before retrying.',503,true);}
    }else{
        if(!function_exists('ai_elevenlabs_credential')||!function_exists('curl_init'))
            throw new VP3PhoneRecordingSpeechErrorV1('The saved-call speech service is unavailable.',503);
        [$key]=ai_elevenlabs_credential();
        if(!is_string($key)||$key==='')throw new VP3PhoneRecordingSpeechErrorV1('Configure the speech service before processing recordings.',503);
        $curl=curl_init('https://api.elevenlabs.io/v1/speech-to-text');
        if($curl===false)throw new VP3PhoneRecordingSpeechErrorV1('Speech transport could not start.',503);
        $raw='';
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>120,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['xi-api-key: '.$key,'Accept: application/json'],
            CURLOPT_POSTFIELDS=>$options+['file'=>new CURLFile($file['path'],'audio/mpeg','recording.mp3')],
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$raw):int{
                if(strlen($raw)+strlen($chunk)>8*1024*1024)return 0;
                $raw.=$chunk;return strlen($chunk);
            },
        ]);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if($ok===false||$status<200||$status>=300)
            throw new VP3PhoneRecordingSpeechErrorV1('Speech processing did not return a verified result; review before retrying.',503,true);
        try{$data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}
        catch(JsonException $error){throw new VP3PhoneRecordingSpeechErrorV1('Speech processing returned an invalid result; review before retrying.',503,true);}
    }
    if(!is_array($data)||array_is_list($data))throw new VP3PhoneRecordingSpeechErrorV1('The speech service returned an invalid result.',503,true);
    // The same limits apply to injected transports and production responses.
    try{$encoded=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
    catch(JsonException $error){throw new VP3PhoneRecordingSpeechErrorV1('The speech result is malformed.',503,true);}
    if(strlen($encoded)>8*1024*1024)throw new VP3PhoneRecordingSpeechErrorV1('The speech result exceeds its processing limit.',503,true);
    return vp3_phone_recording_speech_response_v1($data,$file['source_hash'],$durationSeconds)
        +['provider'=>'elevenlabs','model'=>'scribe_v2','source_hash'=>$file['source_hash']];
}
