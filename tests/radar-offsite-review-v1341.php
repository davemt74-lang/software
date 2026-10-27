<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-radar-foundation.php';
require dirname(__DIR__).'/includes/agent-radar-native-profile.php';
require dirname(__DIR__).'/includes/agent-radar-external.php';
require dirname(__DIR__).'/includes/agent-radar-server.php';
require dirname(__DIR__).'/includes/vp3-analytics.php';
$checks=0;function check(bool $ok,string $message):void{$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
function reject(callable $call,string $message):void{try{$call();}catch(Throwable $e){check(true,$message);return;}throw new RuntimeException($message);}
check(vp3_radar_looks_automated('ClaudeBot/1.0'),'Native/server Radar classifies known bot signature');
check(!vp3_radar_looks_automated('Mozilla/5.0 Chrome/156.0 Safari/537.36'),'Ordinary human browser stays out of bot classification');
check(vp3_radar_looks_automated('curl/8.0'),'Server-side collector can classify non-JavaScript automation');
check(vp3_radar_external_domain_normalize('https://www.example.test/path')==='example.test','Connected domain normalization');
reject(fn()=>vp3_radar_external_domain_normalize('127.0.0.1'),'Connected site rejects raw IP');
check(vp3_radar_external_origin_allowed('example.test','shop.example.test'),'Configured site can use its subdomain');
check(!vp3_radar_external_origin_allowed('example.test','example.test.evil.test'),'Suffix impersonation rejected');
check(!vp3_radar_external_origin_allowed('example.test','notexample.test'),'Lookalike domain rejected');
check(!vp3_radar_external_origin_allowed('example.test',''),'Missing browser origin rejected');
check(vp3_radar_external_path('https://example.test/pricing?api_key=secret#anchor')==='/pricing','Collector strips query and fragment');
check(vp3_radar_external_referrer_host('https://search.test/find?q=private','example.test')==='search.test','Only external referrer host retained');
check(vp3_radar_external_referrer_host('https://shop.example.test/private','example.test')==='','Same-site referrer stays empty');
$token=str_repeat('a',64);$property=['secret_hash'=>hash('sha256',$token)];
check(vp3_radar_server_token_valid($property,$token),'Server-side collector validates shared token hash');
check(!vp3_radar_server_token_valid($property,str_repeat('b',64)),'Wrong server token rejected');
check(!vp3_radar_server_token_valid(['secret_hash'=>''],$token),'Revoked server token rejected');
check(!vp3_radar_server_token_valid($property,'malformed'),'Malformed token rejected');
check(vp3_radar_identity_key(1,['id'=>5],'bot')!==vp3_radar_identity_key(2,['id'=>5],'bot'),'Radar contacts remain owner-scoped');
check(vp3_radar_external_session_key(1,2)!==vp3_radar_external_session_key(2,2),'Radar sessions remain property-scoped');
$session=str_repeat('s',24);
check(vp3_analytics_session_token($session)===$session,'Anonymous browser session accepted');
check(vp3_analytics_session_token('bad token')==='','Invalid browser session rejected');
check(vp3_analytics_session_key(1,$session)!==vp3_analytics_session_key(2,$session),'Anonymous analytics identifiers cannot join sites by their stored hash');
check(!str_contains(vp3_analytics_session_key(1,$session),$session),'Stored analytics session is a one-way property-bound hash');
$pdo=new PDO('sqlite::memory:');
check(!vp3_analytics_external_collect($pdo,['id'=>1,'owner_user_id'=>1],['session'=>$session],'ClaudeBot/1.0'),'Automation never enters the human analytics writer');
check(vp3_analytics_event_name('Purchase Completed')==='purchase_completed','Custom event names normalized');
echo 'RADAR_OFFSITE_REVIEW_V1341='.$checks." assertions PASS\n";
