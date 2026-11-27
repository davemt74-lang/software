<?php
declare(strict_types=1);
if(PHP_SAPI==='cli'){
    ob_start();require __DIR__.'/coding-canvas-recovery-http-v1.php';ob_end_clean();
    $code=['html'=>'<button id="add">Add</button><p id="count">0</p>','css'=>'','js'=>'CanvasUI.on("#add","click",async()=>{await CanvasData.save({count:1});CanvasUI.text("#count","1");});'];
    $plan=[['name'=>'Save a sample count','steps'=>[['action'=>'click','selector'=>'#add']],
        'expect'=>['kind'=>'text','selector'=>'#count','value'=>'1','match'=>'equals'],'persist'=>true]];
    $saved=vp3_coding_canvas_save_draft_v1($pdo,$owner,7,11,'8f000000-0000-4000-8000-000000000001','Checked counter',$code,['count'=>17,'kept'=>true]);
    vp3_coding_canvas_mark_tested_v1($pdo,$owner,7,$saved['canvas']['id'],1,$saved['state']['revision']);
    vp3_coding_canvas_activate_v1($pdo,$owner,7,$saved['canvas']['id'],1,null);
    $draft=vp3_coding_canvas_source_save_v1($pdo,$owner,7,11,$saved['canvas']['id'],1,$saved['revision']['source_hash'],agent_event_uuid_v1920(),$code,null,1,$plan);
    echo json_encode($draft,JSON_THROW_ON_ERROR);
}else{
    require getenv('VP3_CANVAS_RECOVERY_SOURCE').'/tests/fixtures/coding-canvas-recovery-http-v1.php';
    if(isset($_SERVER['HTTP_X_FIXTURE_PHONE']))define('VP3_PHONE_AGENT_NATIVE_V1',true);
}
