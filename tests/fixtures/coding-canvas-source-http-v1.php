<?php
declare(strict_types=1);
if(PHP_SAPI==='cli'){
    ob_start();require __DIR__.'/coding-canvas-recovery-http-v1.php';$fixture=json_decode(ob_get_clean(),true,16,JSON_THROW_ON_ERROR);
    $sourceCode=['html'=>'<h1>Native source editor</h1>','css'=>'','js'=>'const marker="🎨";'];
    $sourceSaved=vp3_coding_canvas_save_draft_v1($pdo,$owner,7,11,'8e000000-0000-4000-8000-000000000001','Native source editor',$sourceCode,['kept'=>true]);
    echo json_encode($fixture+['source'=>$sourceSaved],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}else{
    require getenv('VP3_CANVAS_RECOVERY_SOURCE').'/tests/fixtures/coding-canvas-recovery-http-v1.php';
}
