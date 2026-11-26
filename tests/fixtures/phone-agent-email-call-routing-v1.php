<?php
declare(strict_types=1);

require dirname(__DIR__).'/agent-initiative-model-v1.php';
$GLOBALS['safeHome']=true;$GLOBALS['plan']=$auto;$GLOBALS['homeFailure']=true;
unset($GLOBALS['replacePlan']);
$GLOBALS['homeCalls']=$GLOBALS['cloudCalls']=[];$guardCalls=0;
$job=['background'=>true,'phone_agent_email_call_decision'=>true,'conversation_id'=>11,'job_key'=>'email-call-home-fixture'];
rejects_model(static function()use($pdo,$user,$job,&$guardCalls):void{
    vp3_initiative_infer_v1($pdo,$user,17,'{"facts":{}}','vp3.phone-agent-email-call-decision.v1',true,
        static function()use(&$guardCalls):void{++$guardCalls;},$job);
},'failed owner email-call HomeServer dispatch never falls through to a second Cloud inference');
check_model($guardCalls===1&&count($GLOBALS['homeCalls'])===1&&$GLOBALS['cloudCalls']===[]
    &&$GLOBALS['homeCalls'][0][3]===true,
    'owner email-call local decision retains caller-only transient context and one dispatch claim');
unset($GLOBALS['homeFailure']);$GLOBALS['plan']=$cloud;$GLOBALS['cloudResult']=['ok'=>true,'answer'=>'{}'];
$guardCalls=0;
vp3_initiative_infer_v1($pdo,$user,17,'{"facts":{}}','vp3.phone-agent-email-call-decision.v1',true,
    static function()use(&$guardCalls):void{++$guardCalls;},$job);
$options=end($GLOBALS['cloudCalls'])[5];
check_model($guardCalls===0&&($options['phone_agent_email_call_decision']??false)===true
    &&($options['before_phone_agent_email_call_dispatch']??null) instanceof Closure,
    'Cloud email-call claim is forwarded to the actual provider boundary rather than consumed before metering');
($options['before_phone_agent_email_call_dispatch'])();
check_model($guardCalls===1,'the forwarded email-call provider callback consumes the original current-source guard');
echo "PHONE_AGENT_EMAIL_CALL_ROUTING_V1=PASS\n";
