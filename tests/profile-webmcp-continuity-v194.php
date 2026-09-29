<?php
declare(strict_types=1);
session_start();
require dirname(__DIR__).'/includes/profile-webmcp-continuity-v194.php';

function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}

$_SESSION['vp3_profile_webmcp_resume_v194']=[
    str_repeat('a',32)=>['expires_at'=>time()+60,'created_at'=>time()],
    str_repeat('b',32)=>['expires_at'=>time()-1,'created_at'=>time()-100],
    'bad-token'=>['expires_at'=>time()+60,'created_at'=>time()],
];
$rows=vp3_profile_webmcp_resume_prune_v194(vp3_profile_webmcp_resume_store_v194(),time());
t(isset($rows[str_repeat('a',32)]),'valid token retained');
t(!isset($rows[str_repeat('b',32)]),'expired token removed');
t(!isset($rows['bad-token']),'invalid token removed');

$many=[];
for($i=0;$i<20;$i++){
    $token=str_pad(dechex($i),32,'0',STR_PAD_LEFT);
    $many[$token]=['expires_at'=>time()+600,'created_at'=>time()+$i];
}
$trimmed=vp3_profile_webmcp_resume_prune_v194($many,time());
t(count($trimmed)===VP3_PROFILE_WEBMCP_RESUME_MAX_V194,'resume store is bounded');
t(vp3_profile_webmcp_resume_validate_v194(['user_id'=>1,'username'=>'demo'],['id'=>1],'not-a-token')===null,'malformed token fails closed');

echo "PROFILE_WEBMCP_CONTINUITY_V194_PHP=PASS\n";
