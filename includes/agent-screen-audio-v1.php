<?php
declare(strict_types=1);
require_once __DIR__.'/agent-screen-sessions-v1.php';
function screen_audio_budget_v1(int $uid,?string $release=null): string
{
    $path=ai_v100_ensure_runtime_dir().'/screen-audio-'.$uid.'.json';$h=fopen($path,'c+');if(!$h)throw new RuntimeException('Tab audio budget unavailable.',503);@chmod($path,0600);
    try{if(!flock($h,LOCK_EX))throw new RuntimeException('Tab audio budget unavailable.',503);$s=json_decode((string)stream_get_contents($h),true)?:[];$now=microtime(true);$id='';
        if($release===null){if($now-(float)($s['window']??0)>=3600)$s=['window'=>$now,'count'=>0];if(($s['busy_until']??0)>$now||$now-(float)($s['last']??0)<5||(int)($s['count']??0)>=300)throw new RuntimeException('Tab transcription is busy or limited. Wait before retrying.',429);$id=bin2hex(random_bytes(16));$s=[...$s,'count'=>(int)($s['count']??0)+1,'last'=>$now,'request'=>$id,'busy_until'=>$now+100];}
        elseif(($s['request']??'')===$release)unset($s['busy_until'],$s['request']);
        rewind($h);ftruncate($h,0);fwrite($h,json_encode($s,JSON_THROW_ON_ERROR));fflush($h);return $id;
    }finally{flock($h,LOCK_UN);fclose($h);}
}
function screen_audio_file_v1(array $file): array
{
    $path=(string)($file['tmp_name']??'');$size=is_file($path)?filesize($path):0;
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||$size<32||$size>1048576)throw new RuntimeException('Use a tab audio segment smaller than 1 MB.',413);
    $head=(string)file_get_contents($path,false,null,0,16);
    if(str_starts_with($head,"\x1a\x45\xdf\xa3"))return ['path'=>$path,'mime'=>'audio/webm','name'=>'tab.webm'];
    if(str_starts_with($head,'OggS'))return ['path'=>$path,'mime'=>'audio/ogg','name'=>'tab.ogg'];
    if(substr($head,0,4)==='RIFF'&&substr($head,8,4)==='WAVE')return ['path'=>$path,'mime'=>'audio/wav','name'=>'tab.wav'];
    if(substr($head,4,4)==='ftyp')return ['path'=>$path,'mime'=>'audio/mp4','name'=>'tab.m4a'];
    throw new InvalidArgumentException('Unsupported tab audio container.');
}
/** Provider labels describe the shared tab's audio, not the owner or their intent. */
function screen_audio_event_label_v1(mixed $value): string
{
    if(!is_string($value)||strlen($value)>240)return '';
    $label=trim($value," \t\r\n()[]");
    return preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ,_-]{0,79}$/u',$label)?$label:'';
}
function screen_audio_events_v1(mixed $words,float $duration): array
{
    if(!is_array($words)||!array_is_list($words)||count($words)>1024||!is_finite($duration)||$duration<=0)return [];
    $events=[];
    foreach($words as $word){
        if(!is_array($word)||($word['type']??null)!=='audio_event')continue;
        $label=screen_audio_event_label_v1($word['text']??null);$start=$word['start']??null;$end=$word['end']??null;
        if($label===''||(!is_int($start)&&!is_float($start))||(!is_int($end)&&!is_float($end))
            ||!is_finite($start)||!is_finite($end)||$start<0||$end<$start||$end>$duration+.25)continue;
        $end=min($end,$duration);if($start>$end)continue;
        $events[]=['label'=>$label,'start_seconds'=>$start,'end_seconds'=>$end,
            'provider'=>'elevenlabs','model'=>'scribe_v2','source'=>'shared_tab_audio','classification'=>'provider_label'];
        if(count($events)>=8)break;
    }
    return $events;
}
function screen_audio_request_options_v1(): array
{
    // The existing authorized shared-tab request also returns provider sound tags.
    // https://elevenlabs.io/docs/api-reference/speech-to-text/convert
    return ['model_id'=>'scribe_v2','timestamps_granularity'=>'word','diarize'=>'false','tag_audio_events'=>'true'];
}
function screen_audio_cues_v1(mixed $words,string $transcript,float $duration): array
{
    if($transcript===''||!is_array($words)||!array_is_list($words)||count($words)>1024)return [];

    $cues=[];$cue=null;$tokenText='';$previousEnd=0.0;
    foreach($words as $word){
        if(!is_array($word)||!is_string($word['text']??null))return [];
        $text=$word['text'];
        if(strlen($text)>960||strlen($tokenText)+strlen($text)>50000)return [];
        $tokenText.=$text;
        if(($word['type']??null)==='spacing'){
            if(!preg_match('/^\s+$/u',$text))return [];
            if($cue!==null)$cue['text'].=$text;
            continue;
        }

        $start=$word['start']??null;$end=$word['end']??null;
        $type=$word['type']??null;
        if(!in_array($type,['word','audio_event'],true)||($type==='audio_event'&&screen_audio_event_label_v1($text)==='')||trim($text)===''||
            (!is_int($start)&&!is_float($start))||(!is_int($end)&&!is_float($end)))return [];
        if(!is_finite($start)||!is_finite($end)||$start<$previousEnd||$end<$start||$end>$duration+.25)return [];
        $end=min($end,$duration);
        if($start>$end)return [];

        if($cue!==null&&($start-$cue['end_seconds']>=.8||
            preg_match('/[.!?…。！？؟]$/u',rtrim($cue['text']))||
            mb_strlen($cue['text'].$text)>240)){
            $cue['text']=trim($cue['text']);
            $cues[]=$cue;$cue=null;
        }
        $cue??=['start_seconds'=>$start,'end_seconds'=>$end,'text'=>''];
        $cue['end_seconds']=$end;
        $cue['text'].=$text;
        $previousEnd=$end;
    }
    if($cue!==null){$cue['text']=trim($cue['text']);$cues[]=$cue;}
    // When the timed tokens do not cover the transcript, retain the whole segment as the timing fallback.
    $normalize=static fn(string $text): ?string=>preg_replace('/\s+/u',' ',trim($text));
    if(count($cues)>64||$normalize($tokenText)!==$normalize($transcript))return [];
    return $cues;
}
function screen_audio_transcribe_v1(array $file,array $user,float $duration,?callable $transport=null): array
{
    if(function_exists('subscription_has_entitlement')&&!subscription_has_entitlement($user,'main_ai.access'))throw new RuntimeException('AI access is unavailable for this account.',403);
    [$key,$state]=ai_elevenlabs_credential();if($key==='')throw new RuntimeException('Configure the ElevenLabs speech service in AI settings to transcribe tab audio.',503);
    $file=screen_audio_file_v1($file);
    $options=screen_audio_request_options_v1();
    if($transport)$data=$transport($file,'scribe_v2',$options);
    else{
        if(!function_exists('curl_init'))throw new RuntimeException('Speech transport is unavailable.',503);
        $curl=curl_init('https://api.elevenlabs.io/v1/speech-to-text');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>75,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['xi-api-key: '.$key,'Accept: application/json'],CURLOPT_POSTFIELDS=>$options+['file'=>new CURLFile($file['path'],$file['mime'],$file['name'])]]);
        $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$data=is_string($raw)?json_decode($raw,true):null;
        if($status<200||$status>=300||!is_array($data))throw new RuntimeException($status===401||$status===403?'The speech service rejected its saved credential or permission.':'Tab transcription failed. Check the speech service and retry.',503);
    }
    $transcript=screen_text_v1($data['text']??'',12000);
    $cues=screen_audio_cues_v1($data['words']??null,$transcript,$duration);
    $events=screen_audio_events_v1($data['words']??null,$duration);
    return ['transcript'=>$transcript]+($cues?['cues'=>$cues]:[])+($events?['audio_events'=>$events]:[]);
}
