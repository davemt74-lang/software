<?php
declare(strict_types=1);

function fail_v032(string $message): never{fwrite(STDERR,"FAIL: {$message}\n");exit(1);} 
require dirname(__DIR__).'/includes/ai-usage-accounting-v032.php';

$config=['ai_cost_rates'=>[
    'openai:model-a'=>['input_per_million_usd'=>2.0,'output_per_million_usd'=>8.0],
    'other:*'=>['input_per_million_usd'=>1.0,'output_per_million_usd'=>3.0],
]];

$local=ai_usage_accounting_v032_cost(['source'=>'homeserver_local','provider'=>'ollama','model'=>'local','usage'=>['prompt_tokens'=>900,'completion_tokens'=>100]]);
if($local['micros']!==0||$local['rate_source']!=='local_zero')fail_v032('HomeServer-local execution must report zero VP3 cloud cost.');

$tool=ai_usage_accounting_v032_cost(['source'=>'vp3_tool','usage'=>[]]);
if($tool['micros']!==0)fail_v032('VP3 tool execution must report zero VP3 cloud cost.');

$cloud=ai_usage_accounting_v032_cost(['source'=>'vp3_cloud','provider'=>'openai','model'=>'model-a','usage'=>['prompt_tokens'=>1000000,'completion_tokens'=>500000]]);
if($cloud['micros']!==6000000)fail_v032('Configured provider/model estimate is incorrect.');
if($cloud['rate_source']!=='config:openai:model-a')fail_v032('Exact model rate must win over wildcard rate.');

$wildcard=ai_usage_accounting_v032_cost(['source'=>'user_provider','provider'=>'other','model'=>'model-x','usage'=>['prompt_tokens'=>1000000,'completion_tokens'=>1000000]]);
if($wildcard['micros']!==4000000)fail_v032('Provider wildcard cost estimate is incorrect.');

$unknown=ai_usage_accounting_v032_cost(['source'=>'vp3_cloud','provider'=>'missing','model'=>'unknown','usage'=>['prompt_tokens'=>100,'completion_tokens'=>100]]);
if($unknown['micros']!==null||$unknown['rate_source']!=='unconfigured')fail_v032('Unknown provider pricing must remain explicitly unknown.');

if(ai_usage_accounting_v032_format_cost(0)!=='$0.00')fail_v032('Zero cost formatting is incorrect.');
if(ai_usage_accounting_v032_format_cost(null)!=='—')fail_v032('Unknown cost formatting is incorrect.');

echo "AI usage accounting v0.32 unit contract passed.\n";