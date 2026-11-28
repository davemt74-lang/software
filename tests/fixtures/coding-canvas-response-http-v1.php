<?php
declare(strict_types=1);
if(PHP_SAPI==='cli'){
    ob_start();require __DIR__.'/coding-canvas-recovery-http-v1.php';ob_end_clean();
    require_once getenv('VP3_CANVAS_RECOVERY_SOURCE').'/includes/coding-canvas-response-v1.php';
    $selected=vp3_coding_canvas_read_v1($pdo,$owner,7,$canvasId);
    $received=['html'=>'<h1>Recovered response</h1>','css'=>'h1{color:green}','js'=>''];
    $raw=json_encode(['title'=>'Recovered response']+$received,JSON_THROW_ON_ERROR);
    $job=vp3_coding_canvas_queue_v1($pdo,$owner,7,11,agent_event_uuid_v1920(),'Rebuild existing app',$canvasId,$selected['revision']['id']);
    $lease=$claim($job['run_id']);
    vp3_coding_canvas_execute_v1($pdo,$owner,$lease,static function($db,$u,$pin,$id,$key,$prompt,$previous,$diagnostic,$context)use($raw):never{
        if(!$context['provider_checkpoint'](['text'=>$raw,'terminal'=>false,'duration_ms'=>81000]))throw new RuntimeException('Missing received-source fixture checkpoint');
        throw new Vp3CodingCanvasFailureV1('coding_provider_timeout');
    });
    echo json_encode(['run'=>$job['run_id'],'before'=>$selected,'artifact'=>$received,'text'=>$raw],JSON_THROW_ON_ERROR);
}else{
    require getenv('VP3_CANVAS_RECOVERY_SOURCE').'/tests/fixtures/coding-canvas-recovery-http-v1.php';
    if(isset($_SERVER['HTTP_X_FIXTURE_PHONE']))define('VP3_PHONE_AGENT_NATIVE_V1',true);
}
