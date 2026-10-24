<?php
declare(strict_types=1);
$settings=[];
function setting(string $key, mixed $default=''): mixed { return $GLOBALS['settings'][$key] ?? $default; }
function save_setting(string $key, string $value): void { $GLOBALS['settings'][$key]=$value; }
require dirname(__DIR__).'/includes/ai-settings.php';
require dirname(__DIR__).'/includes/ai-runtime-v100.php';
require dirname(__DIR__).'/includes/agent-tools-v90.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
check(ai_valid_model('anthropic','claude-haiku-5-5'),'Haiku 5.5 selectable');
check(ai_provider_model('anthropic')==='claude-haiku-5-5','Default model');
check(ai_active_provider()==='anthropic','Default main provider');
foreach (['routine','complex','deep'] as $complexity) {
    check(ai_v100_model_candidates('anthropic',$complexity)===['claude-haiku-5-5'],'Chat honors main model');
    check(agent_v90_model_candidates('anthropic',$complexity)===['claude-haiku-5-5'],'Tool planning honors main model');
}
$settings=['ai_active_provider'=>'openai','ai_anthropic_model'=>'claude-haiku-4-5','ai_anthropic_enabled'=>'1','ai_anthropic_api_key'=>'existing-encrypted-value'];
ai_haiku55_upgrade_defaults();
check(ai_active_provider()==='openai' && ai_provider_model('anthropic')==='claude-haiku-4-5','Upgrade preserves existing provider and model');
check($settings['ai_anthropic_api_key']==='existing-encrypted-value','Credentials preserved');
$settings['ai_anthropic_model']='claude-sonnet-5';
$settings['ai_active_provider']='openai';
ai_haiku55_upgrade_defaults();
check(ai_active_provider()==='openai' && ai_provider_model('anthropic')==='claude-sonnet-5','Later owner overrides persist');
$settings=['ai_active_provider'=>'local'];
ai_haiku55_upgrade_defaults();
check(ai_active_provider()==='local','Upgrade does not enable unconfigured provider');
$payload=['model'=>'claude-haiku-5-5','max_tokens'=>1400,'temperature'=>0.3,'top_p'=>1,'top_k'=>5,'messages'=>[['role'=>'user','content'=>'Hello']]];
$request=ai_haiku55_request($payload);
check(!array_intersect_key($request,array_flip(['temperature','top_p','top_k'])),'Invalid sampling parameters removed');
check($request['thinking']===['type'=>'disabled'] && $request['output_config']['effort']==='medium','Bounded output request is compatible');
check($request['max_tokens']===1400 && $request['messages']===$payload['messages'],'Quota and messages preserved');
$payload['model']='claude-haiku-4-5';
check(ai_haiku55_request($payload)===$payload,'Legacy model payload unchanged');
check(agent_v90_extract_provider_text('anthropic',['content'=>[['type'=>'thinking','thinking'=>'private'],['type'=>'text','text'=>'Answer']]])==='Answer','Only visible text is returned');
echo "Haiku 5.5 defaults, upgrade, routing and API compatibility PASS\n";
