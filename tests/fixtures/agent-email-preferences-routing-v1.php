<?php
declare(strict_types=1);

require dirname(__DIR__).'/agent-initiative-model-v1.php';
$GLOBALS['safeHome']=true;$GLOBALS['plan']=$auto;$GLOBALS['homeFailure']=true;
unset($GLOBALS['replacePlan']);
$GLOBALS['homeCalls']=$GLOBALS['cloudCalls']=[];$guardCalls=0;
$job=['background'=>false,'email_preference_suggestion'=>true,'conversation_id'=>11,'job_key'=>'email-preference-home-fixture'];
rejects_model(static function()use($pdo,$user,$job,&$guardCalls):void{
    vp3_initiative_infer_v1($pdo,$user,17,'{"owner_edited_text":{}}','vp3.email-preference-suggestion.v1',true,
        static function()use(&$guardCalls):void{++$guardCalls;},$job);
},'failed writing-preference HomeServer dispatch never falls through to a second Cloud inference');
check_model($guardCalls===1&&count($GLOBALS['homeCalls'])===1&&$GLOBALS['cloudCalls']===[]
    &&$GLOBALS['homeCalls'][0][3]===true,
    'writing-preference local inference retains caller-only transient context and one dispatch claim');
unset($GLOBALS['homeFailure']);$GLOBALS['plan']=$cloud;$GLOBALS['cloudResult']=['ok'=>true,'answer'=>'{}'];
$guardCalls=0;
vp3_initiative_infer_v1($pdo,$user,17,'{"owner_edited_text":{}}','vp3.email-preference-suggestion.v1',true,
    static function()use(&$guardCalls):void{++$guardCalls;},$job);
$options=end($GLOBALS['cloudCalls'])[5];
check_model($guardCalls===0&&($options['email_preference_suggestion']??false)===true
    &&($options['before_email_preference_dispatch']??null) instanceof Closure,
    'Cloud preference claim is forwarded to the actual provider boundary rather than consumed before metering');
($options['before_email_preference_dispatch'])();
check_model($guardCalls===1,'the forwarded preference callback consumes the original live owner/source guard');
echo "AGENT_EMAIL_PREFERENCES_ROUTING_V1=PASS\n";
