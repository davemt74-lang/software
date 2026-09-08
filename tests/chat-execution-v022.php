<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/homeserver-agent-v018.php';
require dirname(__DIR__) . '/includes/chat-execution-v019.php';

function expect_v022(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

$runtime=chat_execution_v019_base(
    'vp3_cloud','VP3 Cloud','openai','gpt-test','unavailable',true,'homeserver_unavailable',
    ['prompt_tokens'=>10,'completion_tokens'=>5,'total_tokens'=>15],0,15,247,'timeout',900,false
);
expect_v022(($runtime['runtime_version']??'')==='v0.22','runtime version missing');
expect_v022((int)($runtime['latency_ms']??0)===247,'latency was not preserved');
expect_v022(($runtime['failure_class']??'')==='timeout','failure class was not preserved');
expect_v022((int)($runtime['cloud_tokens_debited']??0)===15,'cloud debit was not preserved');
expect_v022((int)($runtime['cloud_balance_remaining']??0)===900,'cloud balance was not preserved');

$source=chat_execution_v019_source($runtime);
$title=(string)($source['title']??'');
expect_v022(str_contains($title,'Compute: VP3 Cloud'),'compute source missing');
expect_v022(str_contains($title,'247 ms HomeServer'),'HomeServer latency missing');
expect_v022(str_contains($title,'15 VP3 tokens charged'),'cloud charge missing');
expect_v022(str_contains($title,'HomeServer timeout → fallback'),'fallback classification missing');
expect_v022(str_contains($title,'900 VP3 tokens left'),'remaining balance missing');

expect_v022(homeserver_agent_v018_failure_class('Connection timed out')==='timeout','timeout classifier failed');
expect_v022(homeserver_agent_v018_failure_class('Relay connection failed')==='relay_unreachable','relay classifier failed');
expect_v022(homeserver_agent_v018_failure_class('Provider model unavailable')==='provider_unavailable','provider classifier failed');
expect_v022(homeserver_agent_v018_failure_class('Permission required')==='authorization','authorization classifier failed');

homeserver_agent_v018_set_last_attempt([
    'attempted'=>true,'success'=>false,'latency_ms'=>88,'failure_class'=>'relay_unreachable',
    'provider'=>str_repeat('x',120),'model'=>str_repeat('y',220),
]);
$attempt=homeserver_agent_v018_last_attempt();
expect_v022((int)$attempt['latency_ms']===88,'attempt latency missing');
expect_v022(strlen((string)$attempt['provider'])<=80,'provider was not bounded');
expect_v022(strlen((string)$attempt['model'])<=160,'model was not bounded');

print("VP3 v0.22 live compute runtime unit test passed\n");
