<?php
declare(strict_types=1);

function vp3_image_models_v1(): array
{
    return ['gpt-image-2.5-flare'=>'GPT Image 2.5 Flare','gpt-image-2.5-sunburst'=>'GPT Image 2.5 Sunburst','chatgpt-image-latest'=>'ChatGPT Image (latest)','gpt-image-1.5'=>'GPT Image 1.5','gpt-image-1-mini'=>'GPT Image 1 mini'];
}
function vp3_image_settings_v1(): array
{
    $model=(string)setting('ai_image_model','gpt-image-2.5-flare');
    return ['enabled'=>(string)setting('ai_image_enabled','1')==='1','model'=>isset(vp3_image_models_v1()[$model])?$model:'gpt-image-2.5-flare'];
}
function vp3_image_provider_ready_v1(): bool
{
    return function_exists('ai_provider_api_key')&&function_exists('curl_init')&&vp3_image_settings_v1()['enabled']&&ai_provider_enabled('openai')&&ai_provider_api_key('openai')!=='';
}

/** Verify the PNG container as well as its header before publishing provider bytes. */
function vp3_image_png_check_v1(string $bytes): void
{
    if(substr($bytes,0,8)!=="\x89PNG\r\n\x1a\n")throw new RuntimeException('provider_image_unconfirmed');
    $offset=8;$chunks=0;$data=false;$end=false;$size=strlen($bytes);
    while($offset+12<=$size){
        if(++$chunks>4096)throw new RuntimeException('provider_image_unconfirmed');
        $length=unpack('N',substr($bytes,$offset,4))[1];$type=substr($bytes,$offset+4,4);
        if($length>$size-$offset-12||!preg_match('/^[A-Za-z]{4}$/D',$type)||($chunks===1&&($type!=='IHDR'||$length!==13))||($chunks>1&&$type==='IHDR'))throw new RuntimeException('provider_image_unconfirmed');
        $payload=substr($bytes,$offset+8,$length);$crc=substr($bytes,$offset+8+$length,4);
        if(!hash_equals(hash('crc32b',$type.$payload,true),$crc))throw new RuntimeException('provider_image_unconfirmed');
        $offset+=$length+12;if($type==='IDAT'&&$length>0)$data=true;
        if($type==='IEND'){$end=$length===0&&$offset===$size;break;}
    }
    if(!$data||!$end)throw new RuntimeException('provider_image_unconfirmed');
}

/** Exactly one paid POST. Unknown outcomes never trigger a transport retry. */
function vp3_image_provider_v1(array $config,array $user,?callable $transport=null): array
{
    $key=ai_provider_api_key('openai');if($key==='')throw new RuntimeException('provider_unavailable');
    $body=['model'=>$config['model'],'prompt'=>$config['prompt'],'n'=>1,'size'=>$config['size'],'quality'=>$config['quality'],'background'=>$config['background'],'output_format'=>'png','moderation'=>'auto','safety_identifier'=>hash('sha256','vp3-image-user:'.(int)$user['id'])];
    if($transport)$result=$transport('https://api.openai.com/v1/images/generations',$body,$key);
    else{
        $received='';$curl=curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR),CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>120,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_NETRC=>CURL_NETRC_IGNORED,CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$received): int{if(strlen($received)+strlen($chunk)>36*1024*1024)return 0;$received.=$chunk;return strlen($chunk);}]);
        if(defined('CURLOPT_PROTOCOLS'))curl_setopt($curl,CURLOPT_PROTOCOLS,CURLPROTO_HTTPS);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        if($ok===false)throw new RuntimeException('provider_outcome_unconfirmed');
        if($status<200||$status>=300)throw new RuntimeException(match($status){401,403=>'provider_access_denied',429=>'provider_rate_limited',400=>'provider_request_rejected',default=>'provider_outcome_unconfirmed'});
        $result=json_decode($received,true,32,JSON_THROW_ON_ERROR);unset($received);
    }
    if(!is_array($result)||!is_array($result['data']??null)||count($result['data'])!==1||!is_string($result['data'][0]['b64_json']??null))throw new RuntimeException('provider_image_unconfirmed');
    $encoded=$result['data'][0]['b64_json'];if(strlen($encoded)>34*1024*1024)throw new RuntimeException('image_too_large');
    $bytes=base64_decode($encoded,true);unset($encoded);if(!is_string($bytes)||strlen($bytes)<24||strlen($bytes)>25*1024*1024)throw new RuntimeException('provider_image_unconfirmed');
    vp3_image_png_check_v1($bytes);
    $image=@getimagesizefromstring($bytes);
    if(!$image||($image['mime']??'')!=='image/png'||$image[0]<1||$image[1]<1||$image[0]>4096||$image[1]>4096||$image[0]*$image[1]>16777216)throw new RuntimeException('provider_image_unconfirmed');
    [$expectedWidth,$expectedHeight]=array_map('intval',explode('x',$config['size']));
    if($image[0]!==$expectedWidth||$image[1]!==$expectedHeight)throw new RuntimeException('provider_image_unconfirmed');
    $usage=$result['usage']??null;
    foreach(['input_tokens','output_tokens','total_tokens'] as $field)if(!is_int($usage[$field]??null)||$usage[$field]<0)throw new RuntimeException('provider_usage_unconfirmed');
    if($usage['total_tokens']!==$usage['input_tokens']+$usage['output_tokens']||$usage['output_tokens']<1)throw new RuntimeException('provider_usage_unconfirmed');
    return ['bytes'=>$bytes,'width'=>$image[0],'height'=>$image[1],'sha256'=>hash('sha256',$bytes),'usage'=>array_intersect_key($usage,array_flip(['input_tokens','output_tokens','total_tokens']))];
}
