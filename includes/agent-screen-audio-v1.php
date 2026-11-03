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
function screen_audio_transcribe_v1(array $file,array $user,?callable $transport=null): string
{
    if(function_exists('subscription_has_entitlement')&&!subscription_has_entitlement($user,'main_ai.access'))throw new RuntimeException('AI access is unavailable for this account.',403);
    [$key,$state]=ai_elevenlabs_credential();if($key==='')throw new RuntimeException('Configure the ElevenLabs speech service in AI settings to transcribe tab audio.',503);
    $file=screen_audio_file_v1($file);
    if($transport)$data=$transport($file,'scribe_v2');
    else{
        if(!function_exists('curl_init'))throw new RuntimeException('Speech transport is unavailable.',503);
        $curl=curl_init('https://api.elevenlabs.io/v1/speech-to-text');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>75,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['xi-api-key: '.$key,'Accept: application/json'],CURLOPT_POSTFIELDS=>['model_id'=>'scribe_v2','diarize'=>'false','tag_audio_events'=>'false','file'=>new CURLFile($file['path'],$file['mime'],$file['name'])]]);
        $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$data=is_string($raw)?json_decode($raw,true):null;
        if($status<200||$status>=300||!is_array($data))throw new RuntimeException($status===401||$status===403?'The speech service rejected its saved credential or permission.':'Tab transcription failed. Check the speech service and retry.',503);
    }
    return screen_text_v1($data['text']??'',12000);
}
