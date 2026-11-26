<?php
declare(strict_types=1);

require_once __DIR__.'/phone-agent-email-call-native-v1.php';
require_once dirname(__DIR__,2).'/includes/agent-operator-owner-email-v1.php';
require_once dirname(__DIR__,2).'/includes/agent-operator-dispatch-worker-v1.php';
require_once dirname(__DIR__,2).'/includes/agent-operator-answer-v1.php';

/** A real usefulness claim and preparation, with no external model or provider transport. */
function pec_operator_prepare_v1(array $fixture,array $candidate): array
{
    $pdo=$fixture['pdo'];$context=vp3_phone_agent_email_calls_context_v1($pdo,1,time());
    $decision=static function(string $prompt,string $protocol,Closure $before,array $job,bool $transient):string{
        if(!$transient||empty($job['background'])||str_contains($prompt,'BODY_SENTINEL')
            ||str_contains($prompt,'Private fixture subject')||str_contains($prompt,'sender@example.test'))
            throw new LogicException('Private email material reached the usefulness decision.');
        $before();$GLOBALS['pecDecisionCalls']=($GLOBALS['pecDecisionCalls']??0)+1;
        return '{"decision":"request_call","reason":"ready_for_approval"}';
    };
    $decisionResult=vp3_phone_agent_email_call_decide_v1($pdo,$context,$candidate,time(),$decision,'pec_http_v1');
    if(($decisionResult['reason']??null)!=='eligible')throw new RuntimeException('Fixture usefulness decision: '.($decisionResult['reason']??'missing'));
    $prepared=vp3_operator_owner_email_prepare_v1($pdo,$context,$candidate,time());
    if($prepared['state']!=='prepared')throw new RuntimeException('Fixture call preparation: '.$prepared['reason']);
    return vp3_operator_owner_email_record_v1($pdo,1,$prepared['request_id'])??throw new RuntimeException('Missing prepared call.');
}

function pec_operator_answer_v1(array $fixture,array $form,string $event,array $values=[],?callable $generator=null,?callable $recording=null): string
{
    $payload=array_replace($form,$values);
    $url=vp3_phone_browser_callback_v1('operator_answer',['event'=>$event]);
    $signature=vp3_phone_sms_twilio_signature_v110($url,$payload,'fixture-auth-token');
    return vp3_operator_answer_v1($fixture['pdo'],$event,$payload,$signature,time(),$generator,$recording);
}

/** Native accepted call, disclosure, affirmative recording consent and confirmed recording receipt. */
function pec_operator_active_v1(array $fixture,array $candidate): array
{
    $pdo=$fixture['pdo'];$request=pec_operator_prepare_v1($fixture,$candidate);$plan=null;$sid='CA'.bin2hex(random_bytes(16));
    $carrier=static function(array $current)use(&$plan,$sid):array{
        if(str_contains(json_encode($current,JSON_THROW_ON_ERROR),'BODY_SENTINEL'))throw new LogicException('Private body reached carrier adapter.');
        $plan=$current;
        return vp3_operator_submit_scheduled_v1($current,static function(string $method,string $path,array $fields)use($current,$sid):array{
            if($method!=='POST'||count($fields)!==7||!str_ends_with($path,'/Calls.json'))throw new LogicException('Unexpected carrier request.');
            $GLOBALS['pecCarrierCalls']=($GLOBALS['pecCarrierCalls']??0)+1;
            return ['sid'=>$sid,'account_sid'=>$current['account_sid'],'from'=>$fields['From'],'to'=>$fields['To']];
        });
    };
    $state=vp3_operator_carrier_attempt_v1($pdo,1,(int)$request['event_id'],(int)$request['start_epoch'],$carrier,null,'pec_http_v1');
    if($state!=='submitted'||$plan===null)throw new RuntimeException('Fixture carrier attempt: '.$state);
    $form=['AccountSid'=>$plan['account_sid'],'CallSid'=>$sid,'From'=>$plan['fields']['From'],'To'=>$plan['fields']['To']];
    $prompt=pec_operator_answer_v1($fixture,$form,'answer');
    if(!str_contains($prompt,'recorded and transcribed')||!str_contains($prompt,'event=confirm'))throw new RuntimeException('Missing native consent prompt.');
    $recordingSid='RE'.bin2hex(random_bytes(16));
    $recording=static function(string $method,string $path,array $fields)use($form,$recordingSid):array{
        if($method!=='POST'||!str_ends_with($path,'/Calls/'.$form['CallSid'].'/Recordings.json')
            ||($fields['RecordingChannels']??null)!=='dual'||($fields['PlayBeep']??null)!=='true')
            throw new LogicException('Unexpected recording request.');
        $GLOBALS['pecRecordingCalls']=($GLOBALS['pecRecordingCalls']??0)+1;
        return ['sid'=>$recordingSid,'account_sid'=>$form['AccountSid'],'call_sid'=>$form['CallSid'],
            'source'=>'StartCallRecordingAPI','status'=>'in-progress','channels'=>2];
    };
    $response=pec_operator_answer_v1($fixture,$form,'confirm',['SpeechResult'=>'I agree'],null,$recording);
    if(!str_contains($response,'event=turn1'))throw new RuntimeException('Fixture recording or active-call gate failed.');
    $context=vp3_phone_agent_email_calls_context_v1($pdo,1,time());
    $active=vp3_operator_owner_email_active_v1($pdo,$context,$request['id'],time());
    if($active===null)throw new RuntimeException('Missing canonical active call.');
    return $active+['form'=>$form,'recording_sid'=>$recordingSid,'initial_plan'=>$plan];
}
