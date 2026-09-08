<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/chat-execution-v019.php';

function expect_v019(bool $condition,string $message): void
{
    if(!$condition){
        fwrite(STDERR,"FAIL: {$message}\n");
        exit(1);
    }
}

$local=chat_execution_v019_homeserver([
    'compute_source'=>'homeserver_local',
    'provider'=>'ollama',
    'model'=>'llama-test',
    'usage'=>['prompt_tokens'=>12,'completion_tokens'=>8,'total_tokens'=>20],
    'run_id'=>41,
]);
expect_v019($local['source']==='homeserver_local','local route source');
expect_v019($local['label']==='HomeServer Local','local route label');
expect_v019($local['homeserver']==='connected','local HomeServer state');
expect_v019($local['usage']['total_tokens']===20,'local token usage');
expect_v019($local['cloud_tokens_debited']===0,'local cloud debit');

$provider=chat_execution_v019_homeserver([
    'compute_source'=>'user_provider',
    'provider'=>'anthropic',
    'model'=>'claude-test',
    'usage'=>['prompt_tokens'=>7,'completion_tokens'=>3,'total_tokens'=>10],
]);
expect_v019($provider['source']==='user_provider','user provider route source');
expect_v019($provider['label']==='Connected Provider','user provider route label');

$cloud=chat_execution_v019_base(
    'vp3_cloud','VP3 Cloud','openai','gpt-test','unavailable',true,
    'homeserver_unavailable',['prompt_tokens'=>100,'completion_tokens'=>50,'total_tokens'=>150],0,150
);
expect_v019($cloud['fallback_used']===true,'cloud fallback marker');
expect_v019($cloud['fallback_reason']==='homeserver_unavailable','cloud fallback reason');
expect_v019($cloud['cloud_tokens_debited']===150,'cloud debit');
$source=chat_execution_v019_source($cloud);
expect_v019($source['source']==='compute-routing:v019','compute source namespace');
expect_v019(str_contains($source['title'],'Compute: VP3 Cloud'),'visible compute title');
expect_v019(str_contains($source['title'],'openai / gpt-test'),'visible provider/model');
expect_v019(str_contains($source['title'],'150 tokens'),'visible token count');
expect_v019(str_contains($source['title'],'HomeServer unavailable'),'visible safe fallback reason');

$tool=chat_execution_v019_tool();
expect_v019($tool['source']==='vp3_tool','tool route source');
expect_v019($tool['usage']['total_tokens']===0,'tool route has no model tokens');

$sanitized=chat_execution_v019_base(
    'not-real','Unsafe','provider','model','secret-state',true,'raw-private-error',[],0,0
);
expect_v019($sanitized['source']==='vp3_retrieval','unknown route is normalized');
expect_v019($sanitized['homeserver']==='not_used','unknown HomeServer state is normalized');
expect_v019($sanitized['fallback_reason']==='none','unknown fallback detail is dropped');

$serialized=json_encode([$local,$provider,$cloud,$source,$tool,$sanitized],JSON_UNESCAPED_SLASHES);
expect_v019(!str_contains((string)$serialized,'relay_token'),'relay token cannot appear');
expect_v019(!str_contains((string)$serialized,'bearer_token'),'bearer token cannot appear');
expect_v019(!str_contains((string)$serialized,'raw-private-error'),'raw fallback error cannot appear');

fwrite(STDOUT,"VP3 v0.19 execution provenance regression passed\n");
