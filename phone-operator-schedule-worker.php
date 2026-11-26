<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/phone-workspace-v1.php';
require_once __DIR__.'/includes/agent-operator-occurrences-v1.php';
require_once __DIR__.'/includes/phone-recording-pipeline-v1.php';
$directory=STONEFELLOW_ROOT.'/private/phone-recordings-v1';
if(!is_dir($directory))@mkdir($directory,0700,true);
try{
    if(($argv[1]??'')==='--preview-dispatch'){
        require_once __DIR__.'/includes/agent-operator-dispatch-preview-v1.php';
        echo json_encode(['preview'=>vp3_operator_preview_due_v1(db(),time())],JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    if(count($argv)>2||!in_array($argv[1]??'',['','--dispatch'],true)){
        fwrite(STDERR,"Usage: php phone-operator-schedule-worker.php [--preview-dispatch|--dispatch]\n");
        exit(2);
    }
    $counts=vp3_operator_occurrences_tick_v1(db(),time());
    if(($argv[1]??'')==='--dispatch'){
        require_once __DIR__.'/includes/agent-operator-dispatch-worker-v1.php';
        $counts['dispatch']=vp3_operator_dispatch_tick_v1(db(),'vp3_operator_submit_scheduled_v1');
        $counts['errors']+=$counts['dispatch']['errors']+$counts['dispatch']['uncertain'];
    }
    require_once __DIR__.'/includes/agent-operator-outcome-chat-v1.php';
    $notices=vp3_operator_outcome_chat_tick_v1(db(),time());
    $counts['chat_delivered']=$notices['delivered'];
    $counts['chat_deferred']=$notices['deferred'];
    $counts['errors']+=$notices['errors'];
    vp3_phone_worker_observe_v1(true,$counts['errors']?'failed':'completed');
    echo json_encode($counts,JSON_THROW_ON_ERROR).PHP_EOL;
    exit($counts['errors']?1:0);
}catch(Throwable){vp3_phone_worker_observe_v1(true,'failed');fwrite(STDERR,"Operator schedule worker unavailable. Check the Cloud upgrade.\n");exit(1);}
