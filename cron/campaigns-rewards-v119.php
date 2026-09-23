<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=db();if(!$pdo){fwrite(STDERR,"Database unavailable.\n");exit(1);}
if(!function_exists('campaigns_rewards_platform_schema_ready_v100')||!campaigns_rewards_platform_schema_ready_v100($pdo)){
    fwrite(STDERR,"Campaigns & Rewards schema is not installed. Run the VP3 database upgrade.\n");exit(2);
}
if(!function_exists('campaigns_rewards_automation_run_due_v119')){fwrite(STDERR,"Campaign automation runtime is unavailable.\n");exit(3);}
try{
    $result=campaigns_rewards_automation_run_due_v119($pdo);
    echo json_encode(['ok'=>true,'phase'=>'campaigns-rewards-v119','result'=>$result],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable $e){
    fwrite(STDERR,'Campaign automation due run failed: '.$e->getMessage().PHP_EOL);exit(4);
}
