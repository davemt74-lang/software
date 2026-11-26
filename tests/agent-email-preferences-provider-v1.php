<?php
declare(strict_types=1);

// Shipped broker, prompt builder, meter and cURL orchestration; only the wire is simulated.
require __DIR__.'/agent-connection-gmail-inbox-initial-summary-v1.php';

$protocol='vp3.email-preference-suggestion.v1';
$job=['background'=>false,'email_preference_suggestion'=>true,'conversation_id'=>11,'job_key'=>'email-preference-fixture'];
$prompt='{"email_soul":"Use natural wording.","generated_text":{"subject":"Hello","body":"I hope you are well. Here is the update."},"owner_edited_text":{"subject":"Hello","body":"Here is the update."}}';
$infer=static fn(Closure $guard):string=>vp3_initiative_infer_v1($pdo,$owner,17,$prompt,$protocol,true,$guard,$job);
$GLOBALS['gmailSummaryScenario']='compose';$calls=count($GLOBALS['gmailSummaryDispatch']);$guards=0;$held=false;
$infer(static function()use($pdo,&$guards,&$held):void{
    ++$guards;$held=(int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn()===1;
});
$wire=end($GLOBALS['gmailSummaryDispatch'])['payload'];$encoded=json_encode($wire,JSON_THROW_ON_ERROR);
$ledger=$pdo->query("SELECT * FROM ai_execution_ledger WHERE service_stage='vp3.email-preference-suggestion.v1' ORDER BY id DESC LIMIT 1")->fetch();
check_gmail_summary($guards===1&&$held&&count($GLOBALS['gmailSummaryDispatch'])===$calls+1
    &&($wire['max_output_tokens']??$wire['max_tokens']??0)===384
    &&str_contains($encoded,'owner_edited_text')&&!str_contains($encoded,'private-chat-persona')
    &&!str_contains($encoded,'private-chat-tools')&&$ledger['service_key']==='initiative'
    &&(int)$ledger['is_background']===0&&(int)$ledger['agent_id']===17&&(int)$ledger['conversation_id']===11
    &&(int)$ledger['cloud_tokens_charged']===140,
    'explicit writing-preference preparation uses one bounded selected-Agent foreground dispatch after real metering without ordinary Chat context');
check_gmail_summary(str_contains($encoded,'untrusted evidence')&&str_contains($encoded,'nothing is remembered until')
    &&str_contains($encoded,'factual corrections')&&str_contains($encoded,'recipient-specific'),
    'the real provider prompt treats both email versions as evidence and distinguishes a style suggestion from accepted memory');

foreach(['rejected','unknown','empty'] as $scenario){
    $GLOBALS['gmailSummaryScenario']=$scenario;$calls=count($GLOBALS['gmailSummaryDispatch']);$guards=0;
    try{$infer(static function()use(&$guards):void{++$guards;});throw new LogicException('Invalid provider result accepted.');}
    catch(RuntimeException $error){
        check_gmail_summary(count($GLOBALS['gmailSummaryDispatch'])===$calls+1&&$guards===1,
            'writing-preference '.$scenario.' response does not retry HTTP or switch model/provider');
    }
}
$GLOBALS['gmailSummaryScenario']='compose';$calls=count($GLOBALS['gmailSummaryDispatch']);
foreach([new DomainException('Saved edit changed.',409),new DomainException('Session revoked.',403)] as $failure){
    try{$infer(static function()use($failure):void{throw $failure;});throw new LogicException('A stale preference request was dispatched.');}
    catch(DomainException|RuntimeException $error){
        check_gmail_summary($error===$failure&&count($GLOBALS['gmailSummaryDispatch'])===$calls
            &&(int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn()===0,
            'the actual preference dispatch guard preserves its native refusal and releases the reserved usage');
    }
}
foreach([[],['service'=>'conversation'],['service'=>'initiative'],['service'=>'initiative','background'=>true]] as $wrong){
    $blocked=ai_v100_with_service_context($wrong,static fn()=>ai_generate_chat_response($prompt,[],[],$owner,'main_ai',
        ['surface'=>'tool_json','protocol'=>$protocol,'email_preference_suggestion'=>true,
            'before_email_preference_dispatch'=>static fn():bool=>true]));
    check_gmail_summary(empty($blocked['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$calls,
        'writing-preference inference requires explicit foreground Initiative metering and cannot become a background learner');
}
foreach([
    [true,static function():void{},['background'=>false]],
    [false,static function():void{},$job],
    [true,null,$job],
    [true,static function():void{},array_replace($job,['background'=>true])],
    [true,static function():void{},$job+['phone_agent_email_call_decision'=>true]],
] as [$transient,$guard,$badJob]){
    try{vp3_initiative_infer_v1($pdo,$owner,17,$prompt,$protocol,$transient,$guard,$badJob);throw new LogicException('Unclaimed protocol accepted.');}
    catch(InvalidArgumentException $expected){check_gmail_summary(count($GLOBALS['gmailSummaryDispatch'])===$calls,
        'writing-preference protocol requires its isolated explicit owner claim before routing');}
}
$blocked=ai_v100_with_service_context(['service'=>'initiative','background'=>false],static fn()=>ai_generate_chat_response(
    $prompt,[],[],$owner,'main_ai',['surface'=>'tool_json','protocol'=>$protocol]));
check_gmail_summary(empty($blocked['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$calls,
    'an unflagged writing-preference protocol cannot fall through to normal Chat inference');

$window=ai_v100_runtime_dir().'/rate-'.(int)$owner['id'].'-chat.json';
if(is_file($window))unlink($window);
$child=proc_open([PHP_BINARY,__DIR__.'/fixtures/agent-email-preferences-routing-v1.php'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);
if(!is_resource($child))throw new RuntimeException('Unable to run writing-preference routing coverage.');
fclose($pipes[0]);if(proc_close($child)!==0)throw new RuntimeException('Writing-preference routing coverage failed.');
echo "AGENT_EMAIL_PREFERENCES_PROVIDER_V1=PASS\n";
