<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/phone-workspace-v1.php';
require_once __DIR__.'/includes/agent-operator-occurrences-v1.php';
try{
    if(($argv[1]??'')==='--preview-dispatch'){
        require_once __DIR__.'/includes/agent-operator-dispatch-preview-v1.php';
        echo json_encode(['preview'=>vp3_operator_preview_due_v1(db(),time())],JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    $counts=vp3_operator_occurrences_tick_v1(db(),time());
    echo json_encode($counts,JSON_THROW_ON_ERROR).PHP_EOL;
    exit($counts['errors']?1:0);
}catch(Throwable){fwrite(STDERR,"Operator schedule worker unavailable. Check the Cloud upgrade.\n");exit(1);}
