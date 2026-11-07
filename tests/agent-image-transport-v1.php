<?php
declare(strict_types=1);
if(($argv[1]??'')!=='--fixture'){
    $p=proc_open([PHP_BINARY,'-n',__FILE__,'--fixture'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);if($code!==0)throw new RuntimeException($out);echo $out;exit;
}
if(($argv[1]??'')==='--fixture'){
foreach(['CURLOPT_POST','CURLOPT_HTTPHEADER','CURLOPT_POSTFIELDS','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_FOLLOWLOCATION','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_PROXY','CURLOPT_NETRC','CURL_NETRC_IGNORED','CURLOPT_WRITEFUNCTION','CURLOPT_PROTOCOLS','CURLPROTO_HTTPS','CURLINFO_HTTP_CODE'] as $i=>$name)define($name,$i+1);
$options=[];$network=0;$status=200;$failed=false;$wire='';$target='';
function curl_init($url){$GLOBALS['target']=$url;return new stdClass();}function curl_setopt_array($h,$v){$GLOBALS['options']=$v;return true;}function curl_getinfo($h,$k){return $GLOBALS['status'];}function curl_close($h){}
function curl_setopt($h,$key,$value){$GLOBALS['options'][$key]=$value;return true;}
function curl_exec($h){$GLOBALS['network']++;if($GLOBALS['failed'])return false;return $GLOBALS['options'][CURLOPT_WRITEFUNCTION]($h,$GLOBALS['wire'])===strlen($GLOBALS['wire']);}
function ai_provider_api_key($p){return 'wire-fixture-key';}
require dirname(__DIR__).'/includes/agent-image-provider-v1.php';
$chunk=static fn($type,$data)=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
$png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',1024,1024,8,6,0,0,0)).$chunk('IDAT',gzcompress(str_repeat("\0".str_repeat("\x40\x60\x80\xff",1024),1024))).$chunk('IEND','');
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
foreach([401=>'provider_access_denied',403=>'provider_access_denied',429=>'provider_rate_limited',302=>'provider_outcome_unconfirmed',503=>'provider_outcome_unconfirmed'] as $code=>$reason){$status=$code;$before=$network;try{vp3_image_provider_v1($config,['id'=>1]);throw new LogicException('Unsuccessful provider response accepted.');}catch(RuntimeException $e){if($e->getMessage()!==$reason||$network!==$before+1)throw $e;}}
$status=200;$failed=true;$before=$network;try{vp3_image_provider_v1($config,['id'=>1]);throw new LogicException('Timeout accepted.');}catch(RuntimeException $e){if($e->getMessage()!=='provider_outcome_unconfirmed'||$network!==$before+1)throw $e;}
$failed=false;$wire=str_repeat('x',36*1024*1024+1);try{vp3_image_provider_v1($config,['id'=>1]);throw new LogicException('Oversized response accepted.');}catch(RuntimeException $e){if($e->getMessage()!=='provider_outcome_unconfirmed')throw $e;}
echo "IMAGE_TRANSPORT_V1=PASS (fixed generate/edit endpoints, TLS, credential isolation, byte bound, no redirects and exactly one paid POST per attempt)\n";

}
