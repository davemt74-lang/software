<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-tool-json-v1.php';
// Actual provider builders and system-prompt function; only configuration/HTTP dependencies are fixtures.
$source=file_get_contents(dirname(__DIR__).'/includes/ai-settings.php');
$a=strpos($source,'function ai_system_prompt(');$b=strpos($source,'function ai_history_messages(',$a);
eval(str_replace("require_once __DIR__.'/agent-tool-json-v1.php';",'',substr($source,$a,$b-$a)));
$a=strpos($source,'function ai_openai_response(');$b=strpos($source,'function ai_subscription_estimated_input_tokens(',$a);eval(substr($source,$a,$b-$a));
function ai_provider_api_key($p){return 'fixture';}function ai_provider_model($p){return $p==='openai'?'gpt-5':'claude-haiku-5-5';}function ai_v100_complexity(...$a){return 'routine';}function ai_history_messages($h){return $h;}function ai_v100_current_message($q,$c){return $q;}function ai_v100_model_candidates($p,$c){return [ai_provider_model($p)];}function ai_v100_telemetry(...$a){}function ai_v100_usage(...$a){return [];}
function agent_brain_soul($u){return ['style'=>'friendly prose'];}function agent_brain_default_soul(){return [];}function agent_brain_tool_prompt($u){return 'Old tool description';}
function ai_curl_json($url,$headers,$body,...$args){$GLOBALS['payload']=$body;return ['ok'=>true,'data'=>['output_text'=>'{"operation":"list"}','content'=>[['type'=>'text','text'=>'{"operation":"list"}']]]];}
$checks=0;function check($ok,$why){if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;echo "PASS $why\n";}
foreach(['vp3.reminder-plan.v1','vp3.capability-plan.v1','vp3.transcript-plan.v1'] as $protocol){
 $control=['surface'=>'tool_json','protocol'=>$protocol];
 ai_openai_response('request',[],[],['id'=>1],$control);$p=$payload['instructions'];
 check(str_contains($p,'exactly one JSON object')&&!str_contains($p,'USER STYLE')&&!str_contains($p,'Old tool description'),'OpenAI interpreter contract excludes persona and old catalogs');
 ai_anthropic_response('request',[],[],['id'=>1],$control);check($payload['system']===$p,'Claude receives the identical strict interpreter contract');
}
check(str_contains(ai_system_prompt([],['id'=>1]),'USER STYLE'),'ordinary conversation retains its existing persona prompt');
check(vp3_tool_json_object_v1("```json\n{\"operation\":\"list\"}\n```",['operation'])['operation']==='list','bounded JSON-only code fence is safely normalized');
foreach(['{"operation":"list","answer":"secret"}','["list"]','{"operation":"list"} trailing prose'] as $bad){try{vp3_tool_json_object_v1($bad,['operation']);throw new RuntimeException('Accepted bad object');}catch(InvalidArgumentException|JsonException $e){check(true,'non-contract output is rejected without executing it');}}
$log=tempnam(sys_get_temp_dir(),'vp3-tool-json-');$old=ini_get('error_log');ini_set('error_log',$log);vp3_tool_json_log_v1('vp3.reminder-plan.v1',1,new InvalidArgumentException('unexpected_fields'),['answer'=>'SECRET reminder title']);ini_set('error_log',$old);$text=file_get_contents($log);unlink($log);check(str_contains($text,'unexpected_fields')&&!str_contains($text,'SECRET'),'diagnostics record validation stage without output contents');
echo "TOOL_JSON_PROVIDER=PASS ($checks cases; fixture HTTP, actual provider payload builders)\n";
