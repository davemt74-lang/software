<?php
declare(strict_types=1);

// A cold process has not loaded a planner/catalog through an earlier worker request.
require_once dirname(__DIR__).'/includes/agent-ob1-executive-v1.php';
$json=json_encode(['effect_state'=>'idle','jobs'=>0],JSON_THROW_ON_ERROR);
$projection=vp3_initiative_runtime_assessment_v1(['id'=>14,'status'=>'completed','action_status'=>'completed',
    'error_class'=>'','result_json'=>$json,'receipt_json'=>$json,'completed_at'=>gmdate('Y-m-d H:i:s')]);
if(($projection['outcome']??null)!=='idle'||($projection['queued']??null)!==0){
    throw new RuntimeException('A cold status request must recognize a verified completed OB1 assessment.');
}
echo 'INITIATIVE_ASSESSMENT_BOOTSTRAP=PASS'.PHP_EOL;
