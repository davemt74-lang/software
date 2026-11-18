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

function vp3_image_count_v1(array $config): int
{
    $count=array_key_exists('count',$config)?$config['count']:1;
    if(!is_int($count)||$count<1||$count>4)throw new InvalidArgumentException('Choose between one and four images.');
    return $count;
}

function vp3_image_output_bytes_limit_v1(): int
{
    return 25*1024*1024;
}

/** Transfer the transient pixels so the worker cannot retain them during response decoding. */
function vp3_image_take_source_v1(?string &$sourceImage): ?string
{
    $source=$sourceImage;$sourceImage=null;return $source;
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
        // A valid ancillary chunk can fill most of the output allowance. Hash
        // bounded slices without copying the full payload and its type prefix.
        $digest=hash_init('crc32b');hash_update($digest,$type);
        for($read=0;$read<$length;$read+=65536)hash_update($digest,substr($bytes,$offset+8+$read,min(65536,$length-$read)));
        $crc=substr($bytes,$offset+8+$length,4);
        if(!hash_equals(hash_final($digest,true),$crc))throw new RuntimeException('provider_image_unconfirmed');
        $offset+=$length+12;if($type==='IDAT'&&$length>0)$data=true;
        if($type==='IEND'){$end=$length===0&&$offset===$size;break;}
    }
    if(!$data||!$end)throw new RuntimeException('provider_image_unconfirmed');
}

/** Exactly one paid POST. Unknown outcomes never trigger a transport retry. */
function vp3_image_provider_v1(array $config,array $user,?callable $transport=null,?string $sourceImage=null,?string $apiKey=null,?callable $recordUsage=null): array
{
    $key=$apiKey??ai_provider_api_key('openai');if($key==='')throw new RuntimeException('provider_unavailable');
    $count=vp3_image_count_v1($config);
    if(($config['mode']??'')==='chat_reference'&&$sourceImage===null)throw new RuntimeException('image_reference_changed');
    $body=['model'=>$config['model'],'prompt'=>$config['prompt'],'n'=>$count,'size'=>$config['size'],'quality'=>$config['quality'],'background'=>$config['background'],'output_format'=>'png','moderation'=>'auto','safety_identifier'=>hash('sha256','vp3-image-user:'.(int)$user['id'])];
    $endpoint='https://api.openai.com/v1/images/generations';
    if($sourceImage!==null){
        $marketing=($config['mode']??'')==='marketing_reference';
        $reference=($config['mode']??'')==='chat_reference';
        // Ordinary photos retain the four-MiB limit. An extended input is a frozen,
        // server-verified generated output, revalidated by the worker before this call.
        $max=$marketing||$reference?5_600_000:420_000;
        $mime=$marketing||$reference?'(?:jpeg|png|webp)':'jpeg';
        $generated=$reference&&is_array($config['reference']??null)&&array_key_exists('generated_output',$config['reference']);
        if($generated){
            $saved=$config['reference'];$proof=$saved['generated_output'];
            if(!is_array($proof)||array_keys($proof)!==['generation_id','sequence_no']
                ||!is_string($proof['generation_id'])||!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/',$proof['generation_id'])
                ||!is_int($proof['sequence_no'])||$proof['sequence_no']<1||$proof['sequence_no']>4
                ||!is_int($saved['asset_id']??null)||$saved['asset_id']<1||($saved['mime_type']??null)!=='image/png'
                ||!is_string($saved['sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/',$saved['sha256'])){
                throw new RuntimeException('image_reference_changed');
            }
            $max=4*intdiv(vp3_image_output_bytes_limit_v1()+2,3)+strlen('data:image/png;base64,');$mime='png';
        }
        if(strlen($sourceImage)>$max||!preg_match('~^data:image/'.$mime.';base64,[A-Za-z0-9+/]+={0,2}$~D',$sourceImage))throw new RuntimeException($marketing?'marketing_source_changed':($reference?'image_reference_changed':'image_source_changed'));
        if($generated){
            // Hash in bounded Base64-aligned chunks rather than allocating another full raster.
            $digest=hash_init('sha256');$decodedBytes=0;
            for($offset=strlen('data:image/png;base64,');$offset<strlen($sourceImage);$offset+=65536){
                $chunk=base64_decode(substr($sourceImage,$offset,65536),true);
                if(!is_string($chunk)||($decodedBytes+=strlen($chunk))>vp3_image_output_bytes_limit_v1())throw new RuntimeException('image_reference_changed');
                hash_update($digest,$chunk);
            }
            if(!hash_equals($saved['sha256'],hash_final($digest)))throw new RuntimeException('image_reference_changed');
        }
        $endpoint='https://api.openai.com/v1/images/edits';
        $body['images']=[['image_url'=>$sourceImage]];
    }
    if($transport){$result=$transport($endpoint,$body,$key);unset($body,$sourceImage);}
    else{
        $received='';$curl=curl_init($endpoint);
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>120,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_NETRC=>CURL_NETRC_IGNORED,CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$received): int{if(strlen($received)+strlen($chunk)>36*1024*1024)return 0;$received.=$chunk;return strlen($chunk);}]);
        unset($body,$sourceImage);
        if(defined('CURLOPT_PROTOCOLS'))curl_setopt($curl,CURLOPT_PROTOCOLS,CURLPROTO_HTTPS);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);unset($curl);
        // Destroy the handle before decoding: its callback retains $received
        // by reference, and PHP 8 curl_close alone does not release the handle.
        if($ok===false)throw new RuntimeException('provider_outcome_unconfirmed');
        if($status<200||$status>=300)throw new RuntimeException(match($status){401,403=>'provider_access_denied',429=>'provider_rate_limited',400,404,422=>'provider_request_rejected',default=>'provider_outcome_unconfirmed'});
        $result=json_decode($received,true,32,JSON_THROW_ON_ERROR);unset($received);
    }
    if(!is_array($result))throw new RuntimeException('provider_image_unconfirmed');
    $usage=is_array($result['usage']??null)?$result['usage']:null;
    foreach(['input_tokens','output_tokens','total_tokens'] as $field){
        if(!is_int($usage[$field]??null)||$usage[$field]<0||$usage[$field]>2_000_000_000){$usage=null;break;}
    }
    if($usage!==null&&$usage['total_tokens']!==$usage['input_tokens']+$usage['output_tokens'])$usage=null;
    if($usage!==null){
        $usage=array_intersect_key($usage,array_flip(['input_tokens','output_tokens','total_tokens']));
        // A verified provider charge can exist even if the returned image fails validation.
        if($recordUsage!==null)$recordUsage($usage);
    }
    if(!is_array($result['data']??null)||!array_is_list($result['data'])||count($result['data'])!==$count)throw new RuntimeException('provider_image_unconfirmed');
    $images=[];$encodedBytes=0;$decodedBytes=0;
    [$expectedWidth,$expectedHeight]=array_map('intval',explode('x',$config['size']));
    for($index=0;$index<$count;$index++){
        $item=$result['data'][$index];
        if(!is_array($item)||!is_string($item['b64_json']??null))throw new RuntimeException('provider_image_unconfirmed');
        $encoded=$item['b64_json'];$encodedBytes+=strlen($encoded);
        if($encodedBytes>34*1024*1024)throw new RuntimeException('image_too_large');
        // Release this encoded item after decoding. Iterating the mutable data
        // array by value would retain the original batch until the loop ends.
        unset($item,$result['data'][$index]);
        $bytes=base64_decode($encoded,true);unset($encoded);
        if(!is_string($bytes)||strlen($bytes)<24)throw new RuntimeException('provider_image_unconfirmed');
        $decodedBytes+=strlen($bytes);
        if($decodedBytes>vp3_image_output_bytes_limit_v1())throw new RuntimeException('image_too_large');
        vp3_image_png_check_v1($bytes);
        $image=@getimagesizefromstring($bytes);
        if(!$image||($image['mime']??'')!=='image/png'||$image[0]<1||$image[1]<1||$image[0]>4096||$image[1]>4096||$image[0]*$image[1]>16777216)throw new RuntimeException('provider_image_unconfirmed');
        if($image[0]!==$expectedWidth||$image[1]!==$expectedHeight)throw new RuntimeException('provider_image_unconfirmed');
        $images[]=['bytes'=>$bytes,'width'=>$image[0],'height'=>$image[1],'sha256'=>hash('sha256',$bytes)];
    }
    if($usage===null||$usage['output_tokens']<1)throw new RuntimeException('provider_usage_unconfirmed');
    // The first image fields preserve existing one-image worker integrations.
    return $images[0]+['images'=>$images,'usage'=>$usage];
}
