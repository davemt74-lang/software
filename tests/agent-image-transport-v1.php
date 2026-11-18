<?php
declare(strict_types=1);
$fixture=$argv[1]??'';
if(!in_array($fixture,['--fixture','--memory-fixture'],true)){
    foreach([['--fixture',[]],['--memory-fixture',['-d','memory_limit=128M']]] as [$mode,$options]){
        $p=proc_open([PHP_BINARY,'-n',...$options,__FILE__,$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);if($code!==0)throw new RuntimeException($out);echo $out;
    }
    exit;
}
if(in_array($fixture,['--fixture','--memory-fixture'],true)){
foreach(['CURLOPT_POST','CURLOPT_HTTPHEADER','CURLOPT_POSTFIELDS','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_FOLLOWLOCATION','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_PROXY','CURLOPT_NETRC','CURL_NETRC_IGNORED','CURLOPT_WRITEFUNCTION','CURLOPT_PROTOCOLS','CURLPROTO_HTTPS','CURLINFO_HTTP_CODE'] as $i=>$name)define($name,$i+1);
$options=[];$network=0;$status=200;$failed=false;$wire='';$target='';
function curl_init($url){$GLOBALS['target']=$url;return new stdClass();}
function curl_setopt_array($h,$v){if($GLOBALS['fixture']==='--memory-fixture')$h->options=$v;else $GLOBALS['options']=$v;return true;}
function curl_getinfo($h,$k){return $GLOBALS['status'];}function curl_close($h){}
function curl_setopt($h,$key,$value){if($GLOBALS['fixture']==='--memory-fixture')$h->options[$key]=$value;else $GLOBALS['options'][$key]=$value;return true;}
function curl_exec($h){
    $GLOBALS['network']++;if($GLOBALS['failed'])return false;
    if($GLOBALS['fixture']==='--memory-fixture'){
        // Stream a real response without retaining a second full fixture copy.
        // Keep the callback on its handle, matching PHP 8 handle lifetime.
        $GLOBALS['memoryRequestBytes']=strlen($h->options[CURLOPT_POSTFIELDS]);
        $file=fopen($GLOBALS['wireFile'],'rb');
        try{while(!feof($file)){$chunk=fread($file,65536);if($h->options[CURLOPT_WRITEFUNCTION]($h,$chunk)!==strlen($chunk))return false;}}
        finally{fclose($file);}
        return true;
    }
    return $GLOBALS['options'][CURLOPT_WRITEFUNCTION]($h,$GLOBALS['wire'])===strlen($GLOBALS['wire']);
}
function ai_provider_api_key($p){return 'wire-fixture-key';}
require dirname(__DIR__).'/includes/agent-image-provider-v1.php';
$chunk=static fn($type,$data)=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
$png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',1024,1024,8,6,0,0,0)).$chunk('IDAT',gzcompress(str_repeat("\0".str_repeat("\x40\x60\x80\xff",1024),1024))).$chunk('IEND','');
if($fixture==='--memory-fixture'){
    $imageFile=tempnam(sys_get_temp_dir(),'vp3-image-memory-');$wireFile=tempnam(sys_get_temp_dir(),'vp3-image-wire-');
    if(!is_string($imageFile)||!is_string($wireFile))throw new RuntimeException('Memory fixture storage is unavailable.');
    register_shutdown_function(static function()use($imageFile,$wireFile):void{@unlink($imageFile);@unlink($wireFile);});
    // A legal PNG ancillary chunk reaches the exact canonical output ceiling
    // without changing the image dimensions or bypassing container validation.
    $size=vp3_image_output_bytes_limit_v1();$length=$size-strlen($png)-12;
    $file=fopen($imageFile,'wb');fwrite($file,substr($png,0,-12));fwrite($file,pack('N',$length).'tEXt');
    $digest=hash_init('crc32b');hash_update($digest,'tEXt');$keyword="Fixture\0";
    fwrite($file,$keyword);hash_update($digest,$keyword);
    for($remaining=$length-strlen($keyword);$remaining>0;){
        // 0xFF produces '/' Base64 and covers the largest escaped-JSON input.
        $piece=str_repeat("\xff",min(65536,$remaining));fwrite($file,$piece);hash_update($digest,$piece);$remaining-=strlen($piece);
    }
    fwrite($file,hash_final($digest,true));fwrite($file,substr($png,-12));fclose($file);unset($piece);
    $file=fopen($wireFile,'wb');fwrite($file,'{"data":[{"b64_json":"');$source=fopen($imageFile,'rb');
    while(!feof($source))fwrite($file,base64_encode(fread($source,49152)));
    fclose($source);fwrite($file,'"}],"usage":{"input_tokens":10,"output_tokens":20,"total_tokens":30}}');fclose($file);
    $config=['model'=>'gpt-image-2.5-flare','prompt'=>'Bounded image transport fixture','size'=>'1024x1024','quality'=>'medium','background'=>'opaque',
        'mode'=>'chat_reference','reference'=>['asset_id'=>37,'mime_type'=>'image/png','sha256'=>hash_file('sha256',$imageFile),
            'generated_output'=>['generation_id'=>'11111111-2222-3333-4444-555555555555','sequence_no'=>3]]];
    $source='data:image/png;base64,'.base64_encode(file_get_contents($imageFile));
    $sourceLength=strlen($source);
    $result=vp3_image_provider_v1($config,['id'=>1],null,vp3_image_take_source_v1($source));
    if(filesize($imageFile)!==$size||strlen($result['bytes'])!==$size||$result['sha256']!==$config['reference']['sha256']
        ||$source!==null||$target!=='https://api.openai.com/v1/images/edits'||$network!==1||$memoryRequestBytes<=$sourceLength
        ||memory_get_peak_usage(true)>128*1024*1024)throw new RuntimeException('Bounded generated-image transport failed.');
    echo 'IMAGE_TRANSPORT_MEMORY=PASS (25 MiB source and exact 25 MiB PNG output; 128 MiB limit; peak '.memory_get_peak_usage(true)." bytes)\n";
    exit;
}
$wire=json_encode(['data'=>[['b64_json'=>base64_encode($png)]],'usage'=>['input_tokens'=>10,'output_tokens'=>20,'total_tokens'=>30]]);
$config=['model'=>'gpt-image-2.5-flare','prompt'=>'Fixture requested image','size'=>'1024x1024','quality'=>'medium','background'=>'transparent'];
$r=vp3_image_provider_v1($config,['id'=>1]);$body=json_decode($options[CURLOPT_POSTFIELDS],true);
if($network!==1||$target!=='https://api.openai.com/v1/images/generations'||$r['width']!==1024||$body['n']!==1||$body['background']!=='transparent'||isset($body['response_format'])||$options[CURLOPT_FOLLOWLOCATION]!==false||$options[CURLOPT_SSL_VERIFYPEER]!==true||$options[CURLOPT_SSL_VERIFYHOST]!==2||$options[CURLOPT_PROXY]!==''||$options[CURLOPT_NETRC]!==CURL_NETRC_IGNORED||$options[CURLOPT_PROTOCOLS]!==CURLPROTO_HTTPS)throw new RuntimeException('Image transport contract mismatch.');
$jpeg='data:image/jpeg;base64,'.trim(file_get_contents(__DIR__.'/fixtures/tv-frame.b64'));
vp3_image_provider_v1($config,['id'=>1],null,$jpeg);
$body=json_decode($options[CURLOPT_POSTFIELDS],true);
if($network!==2||$target!=='https://api.openai.com/v1/images/edits'||$body['images']!==[['image_url'=>$jpeg]]||$body['n']!==1
    ||$options[CURLOPT_FOLLOWLOCATION]!==false||$options[CURLOPT_PROTOCOLS]!==CURLPROTO_HTTPS)throw new RuntimeException('Screen edit transport contract mismatch.');
try{vp3_image_provider_v1($config,['id'=>1],null,'https://example.test/frame.jpg');throw new LogicException('External source accepted.');}
catch(RuntimeException $e){if($e->getMessage()!=='image_source_changed'||$network!==2)throw $e;}
$reference='data:image/png;base64,'.base64_encode($png);
$marketing=$config+['mode'=>'marketing_reference'];
vp3_image_provider_v1($marketing,['id'=>1],null,$reference);
$body=json_decode($options[CURLOPT_POSTFIELDS],true);
if($network!==3||$target!=='https://api.openai.com/v1/images/edits'||$body['images']!==[['image_url'=>$reference]])throw new RuntimeException('Marketing reference transport contract mismatch.');
try{vp3_image_provider_v1($marketing,['id'=>1],null,'https://example.test/product.png');throw new LogicException('External product reference accepted.');}
catch(RuntimeException $e){if($e->getMessage()!=='marketing_source_changed'||$network!==3)throw $e;}
$singleWire=$wire;$before=$network;
$wire=json_encode(['data'=>array_fill(0,4,['b64_json'=>base64_encode($png)]),'usage'=>['input_tokens'=>10,'output_tokens'=>80,'total_tokens'=>90]]);
$batch=vp3_image_provider_v1([...$config,'count'=>4],['id'=>1]);$body=json_decode($options[CURLOPT_POSTFIELDS],true);
if($network!==$before+1||$body['n']!==4||count($batch['images'])!==4||$batch['usage']['total_tokens']!==90
    ||$target!=='https://api.openai.com/v1/images/generations')throw new RuntimeException('Four-image transport contract mismatch.');
$before=$network;$edited=vp3_image_provider_v1([...$config,'count'=>4,'mode'=>'chat_reference'],['id'=>1],null,$reference);
$body=json_decode($options[CURLOPT_POSTFIELDS],true);
if($network!==$before+1||$body['n']!==4||$body['images']!==[['image_url'=>$reference]]||count($edited['images'])!==4
    ||$target!=='https://api.openai.com/v1/images/edits')throw new RuntimeException('Base-image batch transport contract mismatch.');
foreach([0,5,'2',true,null] as $count){
    $before=$network;
    try{vp3_image_provider_v1([...$config,'count'=>$count],['id'=>1]);throw new LogicException('Invalid batch count accepted.');}
    catch(InvalidArgumentException $error){if($network!==$before)throw new RuntimeException('Invalid count reached the provider.');}
}
foreach([null,'https://example.test/base.png'] as $input){
    $before=$network;
    try{vp3_image_provider_v1([...$config,'mode'=>'chat_reference'],['id'=>1],null,$input);throw new LogicException('Unbound base image accepted.');}
    catch(RuntimeException $error){if($error->getMessage()!=='image_reference_changed'||$network!==$before)throw $error;}
}
$wire=$singleWire;$before=$network;$charged=[];
try{vp3_image_provider_v1([...$config,'count'=>4],['id'=>1],null,null,null,static function($usage)use(&$charged){$charged=$usage;});throw new LogicException('Short provider batch accepted.');}
catch(RuntimeException $error){if($error->getMessage()!=='provider_image_unconfirmed'||$network!==$before+1||$charged['total_tokens']!==30)throw $error;}
$padding='Fixture'."\0".str_repeat('x',4*1024*1024);
$largePng=substr($png,0,-12).$chunk('tEXt',$padding).substr($png,-12);unset($padding);
$largeReference='data:image/png;base64,'.base64_encode($largePng);
$proof=['asset_id'=>37,'mime_type'=>'image/png','sha256'=>hash('sha256',$largePng),
    'generated_output'=>['generation_id'=>'11111111-2222-3333-4444-555555555555','sequence_no'=>3]];
$extended=[...$config,'mode'=>'chat_reference','reference'=>$proof];$before=$network;
vp3_image_provider_v1($extended,['id'=>1],null,$largeReference);
$body=json_decode($options[CURLOPT_POSTFIELDS],true);
if($network!==$before+1||$target!=='https://api.openai.com/v1/images/edits'
    ||$body['images']!==[['image_url'=>$largeReference]])throw new RuntimeException('Verified generated-output edit transport mismatch.');
foreach([
    [...$config,'mode'=>'chat_reference'],
    [...$extended,'reference'=>array_diff_key($proof,['generated_output'=>true])],
    [...$extended,'reference'=>[...$proof,'sha256'=>str_repeat('0',64)]],
    [...$extended,'reference'=>[...$proof,'generated_output'=>['generation_id'=>'foreign','sequence_no'=>3]]],
    [...$extended,'reference'=>[...$proof,'generated_output'=>['generation_id'=>$proof['generated_output']['generation_id'],'sequence_no'=>5]]],
] as $invalid){
    $before=$network;
    try{vp3_image_provider_v1($invalid,['id'=>1],null,$largeReference);throw new LogicException('Unverified large reference accepted.');}
    catch(RuntimeException $error){if($error->getMessage()!=='image_reference_changed'||$network!==$before)throw $error;}
}
$before=$network;$oversizedReference='data:image/png;base64,'.str_repeat('A',4*intdiv(vp3_image_output_bytes_limit_v1()+2,3)+4);
try{vp3_image_provider_v1($extended,['id'=>1],null,$oversizedReference);throw new LogicException('Over-ceiling generated reference accepted.');}
catch(RuntimeException $error){if($error->getMessage()!=='image_reference_changed'||$network!==$before)throw $error;}
unset($largePng,$largeReference,$oversizedReference,$proof,$extended,$body);
foreach([401=>'provider_access_denied',403=>'provider_access_denied',404=>'provider_request_rejected',422=>'provider_request_rejected',429=>'provider_rate_limited',302=>'provider_outcome_unconfirmed',503=>'provider_outcome_unconfirmed'] as $code=>$reason){$status=$code;$before=$network;try{vp3_image_provider_v1($config,['id'=>1]);throw new LogicException('Unsuccessful provider response accepted.');}catch(RuntimeException $e){if($e->getMessage()!==$reason||$network!==$before+1)throw $e;}}
$status=200;$failed=true;$before=$network;try{vp3_image_provider_v1($config,['id'=>1]);throw new LogicException('Timeout accepted.');}catch(RuntimeException $e){if($e->getMessage()!=='provider_outcome_unconfirmed'||$network!==$before+1)throw $e;}
$failed=false;$wire=str_repeat('x',36*1024*1024+1);try{vp3_image_provider_v1($config,['id'=>1]);throw new LogicException('Oversized response accepted.');}catch(RuntimeException $e){if($e->getMessage()!=='provider_outcome_unconfirmed')throw $e;}
try{vp3_image_provider_v1([...$config,'count'=>4],['id'=>1]);throw new LogicException('Oversized batch response accepted.');}catch(RuntimeException $e){if($e->getMessage()!=='provider_outcome_unconfirmed')throw $e;}
echo "IMAGE_TRANSPORT_V1=PASS (fixed generate/edit endpoints, one-to-four images, exact base references, aggregate byte bound, TLS, credential isolation, no redirects and exactly one paid POST per attempt)\n";

}
