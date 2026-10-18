<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/homeserver-agent-v018.php';

function check(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException($message);
}

$fallbackCalls=0;
foreach(['timeout','relay_unreachable','authorization','provider_unavailable','empty_response','homeserver_unavailable','none'] as $failure){
    foreach([false,true] as $success){
        $blocked=false;
        try{
            homeserver_agent_v018_require_safe_fallback(['attempted'=>true,'success'=>$success,'failure_class'=>$failure]);
            $fallbackCalls++;
        }catch(RuntimeException $e){
            $blocked=$e->getCode()===409&&str_contains($e->getMessage(),'Check history');
        }
        check($blocked,'An unconfirmed dispatched turn must block a second inference: '.$failure);
    }
}
check($fallbackCalls===0,'No second provider may run after an uncertain dispatch');
foreach([[],['attempted'=>false,'failure_class'=>'homeserver_not_paired'],['attempted'=>false,'failure_class'=>'provider_unavailable']] as $attempt){
    homeserver_agent_v018_require_safe_fallback($attempt);
    $fallbackCalls++;
}
check($fallbackCalls===3,'Preflight fallback remains available');
echo "PASS: uncertain Agent dispatch never falls back; preflight fallback remains available\n";
