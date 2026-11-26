<?php
declare(strict_types=1);

// Real canonical prompt, service meter, broker and transport; only cURL is simulated.
require __DIR__.'/agent-connection-gmail-inbox-initial-summary-v1.php';

$protocol='vp3.phone-agent-email-call-decision.v1';
$job=['background'=>true,'phone_agent_email_call_decision'=>true,'conversation_id'=>11,'job_key'=>'owner-email-call-fixture'];
$prompt='{"task":"email_call_usefulness","facts":{"kind":"organic_reply","ready_for_review":true,"priority":"normal"}}';
$infer=static fn(Closure $guard):string=>vp3_initiative_infer_v1($pdo,$owner,17,$prompt,$protocol,true,$guard,$job);
$GLOBALS['gmailSummaryScenario']='followthrough';$calls=count($GLOBALS['gmailSummaryDispatch']);$guards=0;$held=false;
$infer(static function()use($pdo,&$guards,&$held):void{
    ++$guards;$held=(int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn()===1;
});
$wire=end($GLOBALS['gmailSummaryDispatch'])['payload'];$encoded=json_encode($wire,JSON_THROW_ON_ERROR);
$ledger=$pdo->query("SELECT * FROM ai_execution_ledger WHERE service_stage='vp3.phone-agent-email-call-decision.v1' ORDER BY id DESC LIMIT 1")->fetch();
check_gmail_summary($guards===1&&$held&&count($GLOBALS['gmailSummaryDispatch'])===$calls+1
    &&($wire['max_output_tokens']??$wire['max_tokens']??0)===128
    &&str_contains($encoded,'email_call_usefulness')&&!str_contains($encoded,'private-chat-persona')
    &&!str_contains($encoded,'private-chat-tools')&&$ledger['service_key']==='initiative'
    &&(int)$ledger['is_background']===1&&(int)$ledger['agent_id']===17&&(int)$ledger['conversation_id']===11
    &&(int)$ledger['cloud_tokens_charged']===140,
    'owner email-call usefulness uses one bounded selected-Agent dispatch after real usage reservation without ordinary Chat context');

foreach(['rejected','unknown','empty'] as $scenario){
    $GLOBALS['gmailSummaryScenario']=$scenario;$calls=count($GLOBALS['gmailSummaryDispatch']);$guards=0;
    try{$infer(static function()use(&$guards):void{++$guards;});throw new LogicException('Invalid provider result accepted.');}
    catch(RuntimeException $error){
        check_gmail_summary(count($GLOBALS['gmailSummaryDispatch'])===$calls+1&&$guards===1,
            'email-call '.$scenario.' result does not retry HTTP or fall through to another model/provider');
    }
}
$GLOBALS['gmailSummaryScenario']='followthrough';$calls=count($GLOBALS['gmailSummaryDispatch']);
foreach([new DomainException('source_changed',409),new AgentConnectionGmailExceptionV1('Source unavailable.',503)] as $failure){
    try{$infer(static function()use($failure):void{throw $failure;});throw new LogicException('A changed source was dispatched.');}
    catch(RuntimeException|DomainException $error){
        check_gmail_summary($error===$failure&&count($GLOBALS['gmailSummaryDispatch'])===$calls
            &&(int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn()===0,
            'email-call actual dispatch refusal preserves the original source failure and releases held usage');
    }
}
foreach([[],['service'=>'conversation'],['service'=>'initiative','background'=>false]] as $wrong){
    $blocked=ai_v100_with_service_context($wrong,static fn()=>ai_generate_chat_response($prompt,[],[],$owner,'main_ai',
        ['surface'=>'tool_json','protocol'=>$protocol,'phone_agent_email_call_decision'=>true,
            'before_phone_agent_email_call_dispatch'=>static fn():bool=>true]));
    check_gmail_summary(empty($blocked['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$calls,
        'email-call protocol cannot bypass the explicit metered background Initiative service');
}
foreach([
    [true,static function():void{},['background'=>true]],
    [false,static function():void{},$job],
    [true,null,$job],
    [true,static function():void{},array_replace($job,['background'=>false])],
] as [$transient,$guard,$badJob]){
    try{vp3_initiative_infer_v1($pdo,$owner,17,$prompt,$protocol,$transient,$guard,$badJob);throw new LogicException('Unclaimed protocol accepted.');}
    catch(InvalidArgumentException $expected){check_gmail_summary(count($GLOBALS['gmailSummaryDispatch'])===$calls,
        'email-call protocol requires its transient background claim before routing or accounting');}
}
$blocked=ai_v100_with_service_context(['service'=>'initiative','background'=>true],static fn()=>ai_generate_chat_response(
    $prompt,[],[],$owner,'main_ai',['surface'=>'tool_json','protocol'=>$protocol]));
check_gmail_summary(empty($blocked['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$calls,
    'raw unflagged email-call protocol fails closed rather than using ordinary model dispatch');

$window=ai_v100_runtime_dir().'/rate-'.(int)$owner['id'].'-chat.json';
if(is_file($window))unlink($window);
$child=proc_open([PHP_BINARY,__DIR__.'/fixtures/phone-agent-email-call-routing-v1.php'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);
if(!is_resource($child))throw new RuntimeException('Unable to run native routing coverage.');
fclose($pipes[0]);if(proc_close($child)!==0)throw new RuntimeException('Owner email-call routing coverage failed.');
echo "PHONE_AGENT_EMAIL_CALL_MODEL_V1=PASS\n";
