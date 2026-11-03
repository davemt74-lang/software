<?php
declare(strict_types=1);
// Run without extensions to exercise the production cURL adapter with controlled wire responses.
if(($argv[1]??'')!=='--fixture'){
    $p=proc_open([PHP_BINARY,'-n',__FILE__,'--fixture'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);if($code!==0)throw new RuntimeException($out);echo $out;exit;
}
if(($argv[1]??'')==='--fixture'){
$names=['CURLOPT_RETURNTRANSFER','CURLOPT_FOLLOWLOCATION','CURLOPT_PROTOCOLS','CURLPROTO_HTTPS','CURLOPT_PROXY','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_NETRC','CURL_NETRC_IGNORED','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_RESOLVE','CURLOPT_USERAGENT','CURLOPT_HTTPHEADER','CURLOPT_ENCODING','CURLOPT_WRITEFUNCTION','CURLOPT_HEADERFUNCTION','CURLINFO_HTTP_CODE'];foreach($names as $i=>$name)define($name,$i+1);
$options=[];$wireBody='Fixture body';$charges=0;$network=0;
function curl_init($url){return new stdClass();}function curl_setopt_array($ch,$value){$GLOBALS['options']=$value;return true;}function curl_exec($ch){$GLOBALS['network']++;$o=$GLOBALS['options'];$o[CURLOPT_HEADERFUNCTION]($ch,"Content-Type: text/plain\r\n");return $o[CURLOPT_WRITEFUNCTION]($ch,$GLOBALS['wireBody'])===strlen($GLOBALS['wireBody']);}function curl_getinfo($ch,$key){return 200;}function curl_close($ch){}
require dirname(__DIR__).'/includes/cloud-mission-web-v1.php';
$charge=static function()use(&$charges){$charges++;};$resolver=fn()=>['1.1.1.1'];$r=cloud_mission_web_request_v1('https://research.example.org/article',[],$charge,$resolver);
if($charges!==1||$network!==1||$r['body']!=='Fixture body'||$options[CURLOPT_RESOLVE]!==['research.example.org:443:1.1.1.1']||$options[CURLOPT_FOLLOWLOCATION]!==false||$options[CURLOPT_PROXY]!==''||$options[CURLOPT_SSL_VERIFYPEER]!==true||$options[CURLOPT_SSL_VERIFYHOST]!==2||$options[CURLOPT_NETRC]!==CURL_NETRC_IGNORED)throw new RuntimeException('Pinned HTTPS wire policy mismatch.');
foreach([['127.0.0.1'],['1.1.1.1','10.0.0.1'],['::ffff:127.0.0.1'],[]] as $ips){$before=$network;try{cloud_mission_web_request_v1('https://research.example.org/a',[],$charge,fn()=>$ips);throw new LogicException('Private DNS accepted.');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}if($network!==$before)throw new RuntimeException('Rejected DNS reached the network.');}
$wireBody=str_repeat('x',512001);try{cloud_mission_web_request_v1('https://research.example.org/a',[],$charge,$resolver);throw new LogicException('Oversized page accepted.');}catch(RuntimeException $e){if($e->getMessage()!=='page_too_large')throw $e;}
try{cloud_mission_web_redirect_v1('https://research.example.org/a','http://127.0.0.1/admin',[]);throw new LogicException('Unsafe redirect accepted.');}catch(InvalidArgumentException $expected){}
if(cloud_mission_web_redirect_v1('https://research.example.org/a','/b',['research.example.org'])!=='https://research.example.org/b')throw new RuntimeException('Safe redirect could not be resolved.');
echo "CLOUD_MISSION_WEB_TRANSPORT=PASS (DNS pinning, mixed/private addresses, TLS, proxy/credentials, redirect policy and response bound)\n";

}
