<?php
declare(strict_types=1);
session_start();
require dirname(__DIR__).'/includes/profile-webmcp-continuity-v195.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$user=['id'=>7];$profile=['user_id'=>7,'username'=>'demo'];
$issued=vp3_profile_webmcp_action_context_issue_v195($profile,$user,'Buy a product',42);
t((bool)preg_match('/^[a-f0-9]{32}$/',$issued['context_id']),'context id');
t((bool)preg_match('/^[a-f0-9]{32}$/',$issued['return_token']),'return token');
$noted=vp3_profile_webmcp_action_context_note_v195($profile,$user,$issued['context_id'],$issued['return_token'],'vp3.commerce.checkout.confirm','completed','',true);
t(($noted['phase']??'')==='completed','completed phase');
t(!empty($noted['idempotent_replay']),'idempotent replay retained');
t(empty($noted['contains_sensitive_payload']),'safe projection');
$returned=vp3_profile_webmcp_return_consume_v195($profile,$user,$issued['return_token']);
t(($returned['contract']??'')==='vp3.webmcp.return.v1','return contract');
t(vp3_profile_webmcp_return_consume_v195($profile,$user,$issued['return_token'])===null,'return token single-use');
$follow=vp3_profile_webmcp_return_followthrough_v195($user,'What happened with the profile action?',42);
t(!empty($follow['handled']),'agent followthrough');
t(($follow['profile_webmcp_return']['phase']??'')==='completed','followthrough phase');
t(vp3_profile_webmcp_action_context_validate_v195($profile,['id'=>8],$issued['context_id'],$issued['return_token'])===null,'wrong owner denied');
echo "PROFILE_WEBMCP_CONTINUITY_V195_PHP=PASS\n";
