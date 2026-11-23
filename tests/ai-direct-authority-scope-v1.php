<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/ai-direct-account-v1.php';
$dispatches=0;$revoked=false;$checks=0;
function check(bool $condition,string $label):void{global $checks;if(!$condition)throw new RuntimeException($label);$checks++;}
function ai_curl_json(string $url,array $headers,array $payload,int $timeout,mixed ...$unused):array
{global $dispatches,$revoked;$dispatches++;$revoked=true;return ['ok'=>true,'data'=>['answer'=>'fixture']];}
$route=['provider'=>'openai','model'=>'fixture','api_key'=>'fixture','funding'=>'system'];$user=['id'=>1];
$page=static fn()=>vp3_ai_direct_request_v1($user,$route,'profile_call_public',['max_output_tokens'=>100],1);
$guard=static function()use(&$revoked):void{if($revoked)throw new RuntimeException('source authority revoked',403);};
try{
    vp3_ai_direct_authority_scope_v1($guard,static function()use($page):void{$page();$page();});
    throw new RuntimeException('revoked second page unexpectedly dispatched');
}catch(RuntimeException $error){check($error->getCode()===403&&$dispatches===1,'authority revoked after page one blocks second provider dispatch');}
$page();check($dispatches===2,'failed scope always clears its request guard');
$revoked=false;$outer=0;$inner=0;
vp3_ai_direct_authority_scope_v1(static function()use(&$outer):void{$outer++;},static function()use(&$inner,$page):void{
    vp3_ai_direct_authority_scope_v1(static function()use(&$inner):void{$inner++;},$page);
    $page();
});
check($outer===4&&$inner===2,'nested authority scopes compose and clear independently');
$revoked=true;vp3_ai_direct_authority_scope_v1($guard,static fn()=>null);$page();check($dispatches===5,'successful empty scope also clears its guard');
echo 'AI_DIRECT_AUTHORITY_SCOPE_V1=PASS ('.$checks.' checks)'.PHP_EOL;
