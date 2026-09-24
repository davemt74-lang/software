<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/includes/bootstrap.php';

$pdo=db();
if(!$pdo){fwrite(STDERR,"Campaigns & Rewards V1.22: database unavailable\n");exit(2);}
if(!function_exists('campaigns_rewards_run_due_v122')){fwrite(STDERR,"Campaigns & Rewards V1.22 runtime unavailable\n");exit(2);}

try{
    $summary=campaigns_rewards_run_due_v122($pdo);
    fwrite(STDOUT,campaigns_rewards_json_v100(['build'=>VP3_CAMPAIGNS_REWARDS_V122,'ran_at'=>gmdate('c'),'summary'=>$summary])."\n");
    exit(0);
}catch(Throwable $e){
    fwrite(STDERR,"Campaigns & Rewards V1.22 failed: ".$e->getMessage()."\n");exit(1);
}
