<?php
declare(strict_types=1);

require_once __DIR__.'/fixtures/phone-agent-email-call-operator-v1.php';
require_once dirname(__DIR__).'/includes/phone-agent-email-call-review-v1.php';

function pec_outcome_api_v1(array $fixture,array $case,array $input): array
{
    return vp3_phone_agent_email_calls_request_v1($fixture['pdo'],$fixture['owner'],$input+$case['base'],$fixture['token'],time());
}

/** Each case starts with an actual native active call and an exact authenticated claimed review. */
function pec_outcome_case_v1(array $fixture,string $name): array
{
    $pdo=$fixture['pdo'];$owner=$fixture['owner'];$draft=pec_draft_v1($fixture,$name);
    $candidates=vp3_phone_agent_email_call_candidates_v1($pdo,$fixture['context'],time());
    $candidate=array_values(array_filter($candidates,static fn(array $c):bool=>$c['source_id']===$draft['id']))[0];
    $active=pec_operator_active_v1($fixture,$candidate);
    $key=$_SESSION['agent_connection_email_review_v1']=bin2hex(random_bytes(32));
    $scope=vp3_phone_agent_workspace_scope_v1($pdo,$owner,$fixture['token'],$fixture['device_id'],false,time());
    $principal=vp3_phone_agent_source_scope_v1($pdo,$owner,7,11)['principal'];
    $review=agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,['action'=>'review','draft_id'=>$draft['id']],$key);
    $base=['device_id'=>$fixture['device_id'],'enrollment_revision'=>(int)$scope['enrollment']['revision'],
        'scope_revision'=>$scope['scope_revision'],'agent_id'=>7,'conversation_id'=>11,'csrf_token'=>csrf_token()];
    $case=compact('draft','active','key','scope','principal','review','base');
    $armed=pec_outcome_api_v1($fixture,$case,['action'=>'voice_arm','request_id'=>$active['request']['id'],'draft_id'=>$draft['id'],
        'review_token'=>$review['review_token'],'watch_replies'=>false,'confirmed'=>true]);
    $current=['request'=>$active['request'],'context'=>$active['context'],'plan'=>$active['plan']];
    vp3_phone_agent_email_call_voice_turn_v1($pdo,$current,$active['sid'],1,$armed['challenge'],time());
    $claimed=pec_outcome_api_v1($fixture,$case,['action'=>'voice_claim','review_id'=>$armed['review']['id'],'review_token'=>$review['review_token']]);
    pec_check_v1($claimed['review']['state']==='claimed','native '.$name.' review consumes its exact spoken challenge once');
    return $case+['id'=>$armed['review']['id'],'current'=>$current];
}

function pec_outcome_send_v1(array $fixture,array $case,array $review,string $expectedBody,string $providerId): array
{
    $calls=0;
    $http=static function(string $method,string $url,array $headers,?array $body,bool $form)use(&$calls,$case,$expectedBody,$providerId):array{
        if($method==='GET')return pec_http_v1($method,$url,$headers,$body,$form);
        if($method!=='POST'||$url!==VP3_GMAIL_API_V1.'/messages/send'||$form
            ||($body['threadId']??null)!==$case['draft']['payload']['reply']['thread_id'])throw new LogicException('Unexpected Gmail fixture operation.');
        $mime=base64_decode(strtr((string)($body['raw']??''),'-_','+/'),true);$parts=is_string($mime)?explode("\r\n\r\n",$mime,2):[];
        if(count($parts)!==2||base64_decode($parts[1],true)!==$expectedBody)throw new LogicException('The canonical send changed the reviewed body.');
        ++$calls;return ['status'=>200,'json'=>['id'=>$providerId,'threadId'=>$case['draft']['payload']['reply']['thread_id']]];
    };
    $result=agent_connection_email_chat_action_v1($fixture['pdo'],$fixture['owner'],$case['principal'],11,
        ['action'=>'confirm','draft_id'=>$case['draft']['id'],'review_token'=>$review['review_token'],'watch_replies'=>false,'confirmed'=>true],$case['key'],$http);
    pec_check_v1($calls===1&&$result['status']==='sent','one normal canonical send records the exact '.$providerId.' payload and provider receipt');
    return $result;
}

function pec_outcome_no_claim_v1(array $fixture,array $case,string $label): void
{
    $speech=vp3_phone_agent_email_call_voice_turn_v1($fixture['pdo'],$case['current'],$case['active']['sid'],2,'Was it sent?',time());
    pec_check_v1(is_string($speech)&&!str_contains($speech,'your reviewed email was sent'),$label);
}

$f=pec_fixture_v1();$pdo=$f['pdo'];
try {
    $changed=pec_outcome_case_v1($f,'outcome_edited');
    $next=agent_connection_email_chat_action_v1($pdo,$f['owner'],$changed['principal'],11,
        ['action'=>'compose_save','draft_id'=>$changed['draft']['id'],'review_token'=>$changed['review']['review_token'],
            'to'=>$changed['review']['to'],'subject'=>$changed['review']['subject'],
            'body'=>'A different manually reviewed message.','attachment_ids'=>[]],$changed['key']);
    pec_outcome_send_v1($f,$changed,$next,'A different manually reviewed message.','outcome_manual_newer');
    $old=vp3_phone_agent_email_call_review_row_v1($pdo,1,$changed['id'],false);
    $actual=agent_connection_email_chat_draft_v1($pdo,1,7,11,$changed['draft']['id']);
    pec_check_v1(!hash_equals($old['review_hash'],agent_connection_email_chat_review_hash_v1($actual,false))
        &&!vp3_phone_agent_email_call_review_sent_v1($pdo,$old,$actual),
        'a later legal composer edit and manual send cannot prove the earlier voice-reviewed version was sent');
    pec_reject_v1(fn()=>pec_outcome_api_v1($f,$changed,['action'=>'voice_poll','review_id'=>$changed['id']]),
        'the older claimed voice poll rejects a newer manually sent version','review_changed');
    pec_outcome_no_claim_v1($f,$changed,'carrier review hook does not claim the changed manually sent email is the old reviewed outcome');
    pec_check_v1(vp3_phone_agent_email_call_review_row_v1($pdo,1,$changed['id'],false)['state']==='claimed',
        'a mismatched result does not rewrite the old claim to completed or permit another send');

    // Independent call-history scenario in the isolated fixture database, following native Operator fixtures.
    foreach(['phone_agent_email_call_reviews_v1','phone_browser_deliveries_v1','phone_voice_turns_v140','phone_browser_calls_v1',
        'phone_operator_carrier_attempts_v1','phone_operator_occurrences_v1','phone_operator_scheduled_assignments_v1',
        'phone_agent_email_call_requests_v1','user_calendar_events'] as $table)$pdo->exec('DELETE FROM '.$table);
    $exact=pec_outcome_case_v1($f,'outcome_exact');
    pec_outcome_send_v1($f,$exact,$exact['review'],'BODY_SENTINEL_PRIVATE_DRAFT','outcome_exact_sent');
    $review=vp3_phone_agent_email_call_review_row_v1($pdo,1,$exact['id'],false);
    $draft=agent_connection_email_chat_draft_v1($pdo,1,7,11,$exact['draft']['id']);
    $operation=agent_connection_email_chat_operation_key_v1($draft['id']);
    pec_check_v1(vp3_phone_agent_email_call_review_sent_v1($pdo,$review,$draft),
        'matching saved version, watch choice and actual immutable Gmail receipt prove the exact reviewed outcome');
    $mutations=[
        ['pending receipt',"UPDATE agent_connection_sends_v1 SET status='pending' WHERE owner_user_id=1 AND operation_key=?",[$operation]],
        ['different provider message',"UPDATE agent_connection_sends_v1 SET provider_message_id='another_sent_message' WHERE owner_user_id=1 AND operation_key=?",[$operation]],
        ['missing provider message',"UPDATE agent_connection_sends_v1 SET provider_message_id='' WHERE owner_user_id=1 AND operation_key=?",[$operation]],
        ['different provider thread',"UPDATE agent_connection_sends_v1 SET provider_thread_id='another_thread' WHERE owner_user_id=1 AND operation_key=?",[$operation]],
        ['missing provider thread',"UPDATE agent_connection_sends_v1 SET provider_thread_id='' WHERE owner_user_id=1 AND operation_key=?",[$operation]],
        ['different payload digest','UPDATE agent_connection_sends_v1 SET payload_digest=? WHERE owner_user_id=1 AND operation_key=?',[hash('sha256','different payload'),$operation]],
        ['different Gmail authorization','UPDATE agent_connection_sends_v1 SET authorization_key=? WHERE owner_user_id=1 AND operation_key=?',[str_repeat('b',64),$operation]],
        ['missing provider confirmation time','UPDATE agent_connection_sends_v1 SET sent_at=0 WHERE owner_user_id=1 AND operation_key=?',[$operation]],
        ['missing exact receipt','DELETE FROM agent_connection_sends_v1 WHERE owner_user_id=1 AND operation_key=?',[$operation]],
        ['different watch choice','UPDATE agent_connection_email_drafts_v1 SET watch_replies=1 WHERE id=?',[$draft['id']]],
    ];
    foreach($mutations as [$label,$sql,$parameters]){
        $pdo->beginTransaction();
        try{
            vp3_phone_sql_v1($pdo,$sql,$parameters);
            $live=agent_connection_email_chat_draft_v1($pdo,1,7,11,$draft['id'],true);
            pec_check_v1(!vp3_phone_agent_email_call_review_sent_v1($pdo,$review,$live),$label.' is not exact reviewed-send proof');
            pec_reject_v1(fn()=>pec_outcome_api_v1($f,$exact,['action'=>'voice_poll','review_id'=>$exact['id']]),
                $label.' cannot mark a claimed voice review completed','review_changed');
            pec_outcome_no_claim_v1($f,$exact,$label.' cannot make the carrier claim reviewed-email success');
        }finally{if($pdo->inTransaction())$pdo->rollBack();}
    }
    $done=pec_outcome_api_v1($f,$exact,['action'=>'voice_poll','review_id'=>$exact['id']]);
    $speech=vp3_phone_agent_email_call_voice_turn_v1($pdo,$exact['current'],$exact['active']['sid'],3,'Was it sent?',time());
    pec_check_v1($done['review']['state']==='completed'&&$speech==='Gmail confirmed that your reviewed email was sent.',
        'only the exact confirmed native receipt permits completed state and the matching spoken success');
    $pdo->beginTransaction();
    try{
        vp3_phone_sql_v1($pdo,'DELETE FROM agent_connection_sends_v1 WHERE owner_user_id=1 AND operation_key=?',[$operation]);
        pec_reject_v1(fn()=>pec_outcome_api_v1($f,$exact,['action'=>'voice_poll','review_id'=>$exact['id']]),
            'completed review still requires its exact native receipt rather than trusting a saved success label','review_changed');
        pec_outcome_no_claim_v1($f,$exact,'a completed label without current proof cannot manufacture spoken success');
    }finally{if($pdo->inTransaction())$pdo->rollBack();}
    pec_check_v1((int)$pdo->query('SELECT COUNT(*) FROM agent_connection_sends_v1')->fetchColumn()===2,
        'outcome reads and rejected proof checks never create another Gmail send operation');
    echo 'PHONE_AGENT_EMAIL_CALL_OUTCOME_V1=PASS '.($GLOBALS['pecChecks']??0).PHP_EOL;
}finally{pec_cleanup_v1($f);}
