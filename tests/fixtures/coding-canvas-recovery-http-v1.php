<?php
declare(strict_types=1);

$source=getenv('VP3_CANVAS_RECOVERY_SOURCE');
$file=getenv('VP3_CANVAS_RECOVERY_DB');
if(!is_string($source)||!is_string($file)||$source===''||$file==='')throw new RuntimeException('Missing isolated Canvas test setup.');
$GLOBALS['initiativeScreenAccess']=true;
if(PHP_SAPI==='cli'){
    $GLOBALS['imageFixtureFile']=$file;
    ob_start();
    require $source.'/tests/coding-canvas-jobs-v1.php';
    ob_end_clean();
    vp3_coding_canvas_job_v1($pdo,$owner,7,11,$replacementJob['run_id'],'stop');
    echo json_encode(['failed'=>$failed['run_id'],'completed'=>$id,'request_id'=>$request,'retry_prompt'=>'Will fail'],JSON_THROW_ON_ERROR);
}else{
    putenv('VP3_IMAGE_TEST_DSN=sqlite:'.$file);
    require $source.'/tests/fixtures/image-generation-v1.php';
    require_once $source.'/includes/coding-canvas-jobs-v1.php';
    function personal_knowledge_available(array $user): bool{return personal_capability_has_v242('personal_knowledge.access',$user);}
}
