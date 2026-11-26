<?php
declare(strict_types=1);

require_once __DIR__.'/fixtures/phone-agent-email-call-operator-v1.php';
require_once dirname(__DIR__).'/includes/phone-agent-email-call-review-v1.php';
require_once dirname(__DIR__).'/includes/phone-agent-workspace-native-v1.php';

/** Observe the real committed native CAS, without replacing its SQL or transport. */
final class EmailCallSendingStatementV1 extends PDOStatement
{
    public function execute(?array $params=null): bool
    {
        $ok=parent::execute($params);
        $hook=$GLOBALS['emailCallSendingTransitionV1']??null;
        if($ok&&$this->rowCount()===1&&is_callable($hook)
            &&str_starts_with($this->queryString,"UPDATE agent_connection_email_drafts_v1 SET status='sending',watch_replies=")){
            $GLOBALS['emailCallSendingTransitionV1']=null;
            $hook();
        }
        return $ok;
    }
}

$f=pec_fixture_v1();$pdo=$f['pdo'];$owner=$f['owner'];
$seed=pec_draft_v1($f,'sending_transition_source',['conversation_id'=>12]);
$candidates=vp3_phone_agent_email_call_candidates_v1($pdo,$f['context'],time());
$candidate=array_values(array_filter($candidates,static fn(array $c):bool=>$c['source_id']===$seed['id']))[0];
$active=pec_operator_active_v1($f,$candidate);$request=$active['request'];
$key=$_SESSION['agent_connection_email_review_v1']=bin2hex(random_bytes(32));
$scope=vp3_phone_agent_workspace_scope_v1($pdo,$owner,$f['token'],$f['device_id'],false,time());
$base=['device_id'=>$f['device_id'],'enrollment_revision'=>(int)$scope['enrollment']['revision'],'scope_revision'=>$scope['scope_revision'],
    'agent_id'=>7,'conversation_id'=>12,'csrf_token'=>csrf_token()];
$api=static fn(array $input):array=>vp3_phone_agent_email_calls_request_v1($pdo,$owner,$input+$base,$f['token'],time());
$principal=vp3_phone_agent_source_scope_v1($pdo,$owner,7,12)['principal'];
$nativeReview=agent_connection_email_chat_action_v1($pdo,$owner,$principal,12,['action'=>'review','draft_id'=>$seed['id']],$key);
$armed=$api(['action'=>'voice_arm','request_id'=>$request['id'],'draft_id'=>$seed['id'],
    'review_token'=>$nativeReview['review_token'],'watch_replies'=>false,'confirmed'=>true]);
$id=$armed['review']['id'];$modelCalls=0;
$noModel=static function()use(&$modelCalls):array{++$modelCalls;return ['ok'=>true,'answer'=>'UNEXPECTED_MODEL_REPLY'];};
$spoken=pec_operator_answer_v1($f,$active['form'],'turn1',['SpeechResult'=>$armed['challenge']],$noModel);
pec_check_v1(str_contains($spoken,'event=turn2')&&$modelCalls===0
    &&vp3_phone_agent_email_call_review_row_v1($pdo,1,$id,false)['state']==='requested',
    'actual signed exact challenge records intent through the native active-call flow');
$claimed=$api(['action'=>'voice_claim','review_id'=>$id,'review_token'=>$nativeReview['review_token']]);
pec_check_v1($claimed['review']['state']==='claimed'
    &&(int)$pdo->query('SELECT COUNT(*) FROM agent_connection_sends_v1')->fetchColumn()===0,
    'real authenticated owner review claims that intent without executing Gmail');

$native=['scope'=>$scope,'resolved'=>vp3_phone_agent_workspace_item_v1($pdo,$scope,$request['item_ref'])];
$confirmation=['action'=>'confirm','draft_id'=>$seed['id'],'review_token'=>$nativeReview['review_token'],
    'confirmed'=>true,'watch_replies'=>false,'agent_id'=>7,'conversation_id'=>12];
$GLOBALS['VP3_PHONE_AGENT_NATIVE_EXECUTION_CONTEXT_V1']=['context'=>$native,'kind'=>'email','voice_review'=>$id,'voice_input'=>$confirmation];
define('VP3_PHONE_AGENT_NATIVE_V1','email');
$transitions=0;$sendCount=0;$signedTurn='';
$GLOBALS['emailCallSendingTransitionV1']=static function()use($pdo,$f,$seed,$active,$request,$id,$noModel,
    &$transitions,&$sendCount,&$signedTurn,&$modelCalls):void{
    ++$transitions;
    $draft=agent_connection_email_chat_draft_v1($pdo,1,7,12,$seed['id']);
    pec_check_v1(!vp3_phone_transaction_active_v1($pdo)&&$draft['status']==='sending'&&!$draft['watch_replies'],
        'observer reaches the real native sending CAS before any send-ledger transaction');
    $context=vp3_phone_agent_email_calls_context_v1($pdo,1,time());
    $current=vp3_operator_owner_email_record_v1($pdo,1,$request['id']);
    $beforeHttp=$GLOBALS['pecHttpCalls'];
    pec_check_v1(vp3_phone_agent_email_call_sending_current_v1($pdo,$context,$current,$active['sid'],time())
        &&$GLOBALS['pecHttpCalls']===$beforeHttp,
        'exact current claimed review supplies a provider-free same-send witness');

    $mutate=static function(string $sql,array $params,string $label)use($pdo,$context,$current,$active):void{
        $pdo->beginTransaction();
        try{
            vp3_phone_sql_v1($pdo,$sql,$params);
            pec_check_v1(!vp3_phone_agent_email_call_sending_current_v1($pdo,$context,$current,$active['sid'],time()),$label);
        }finally{if($pdo->inTransaction())$pdo->rollBack();}
    };
    $mutate("UPDATE phone_agent_email_call_reviews_v1 SET state='armed' WHERE id=?",[$id],
        'sending status without a consumed exact review claim supplies no witness');
    $mutate('UPDATE phone_agent_email_call_reviews_v1 SET claimed_epoch=? WHERE id=?',[time()-31,$id],
        'an old claim cannot preserve a later sending transition');
    $mutate('UPDATE phone_agent_email_call_reviews_v1 SET heartbeat_epoch=? WHERE id=?',[time()-31,$id],
        'stale authenticated-review heartbeat withdraws the sending witness');
    $mutate('UPDATE agent_connection_email_drafts_v1 SET watch_replies=1 WHERE id=?',[$seed['id']],
        'a different reply-watching choice cannot reuse the exact sending witness');
    $mutate('UPDATE agent_connections_v1 SET read_enabled=0 WHERE id=7',[],
        'Gmail read withdrawal suppresses the active sending witness');
    $mutate('UPDATE agent_connections_v1 SET send_enabled=0 WHERE id=7',[],
        'Gmail send withdrawal suppresses the active sending witness');
    $mutate("UPDATE agent_connections_v1 SET status='disconnected' WHERE id=7",[],
        'disconnected Gmail suppresses the active sending witness');
    $mutate('UPDATE agent_connections_v1 SET authorization_key=? WHERE id=7',[str_repeat('e',64)],
        'a replacement Gmail grant cannot reuse the original sending witness');
    $mutate('UPDATE agent_connections_v1 SET client_fingerprint=? WHERE id=7',[str_repeat('f',64)],
        'a changed Gmail OAuth fingerprint suppresses the sending witness');
    $mutate('UPDATE agent_connections_v1 SET account_email=? WHERE id=7',['another@example.test'],
        'a different connected Gmail account cannot reuse the sending witness');
    $savedConfig=$GLOBALS['config']['connections']['gmail'];
    try{
        $GLOBALS['config']['connections']['gmail']['client_id']='replacement.apps.googleusercontent.com';
        pec_check_v1(!vp3_phone_agent_email_call_sending_current_v1($pdo,$context,$current,$active['sid'],time()),
            'changing the live OAuth app suppresses the sending witness');
        $GLOBALS['config']['connections']['gmail']=$savedConfig;
        $GLOBALS['config']['connections']['gmail']['client_secret']='';
        pec_check_v1(!vp3_phone_agent_email_call_sending_current_v1($pdo,$context,$current,$active['sid'],time()),
            'missing live OAuth credentials suppresses the sending witness');
    }finally{$GLOBALS['config']['connections']['gmail']=$savedConfig;}
    pec_check_v1(!vp3_phone_agent_email_call_sending_current_v1($pdo,$context,$current,'CA'.str_repeat('0',32),time()),
        'a different carrier leg cannot reuse an active sending witness');
    pec_check_v1($GLOBALS['pecHttpCalls']===$beforeHttp&&$sendCount===0,
        'witness and revocation checks perform no provider fetch or send');

    // A carrier callback is an independent request. Run it only after the real
    // autocommit sending claim, never inside the native provider transaction.
    $signedTurn=pec_operator_answer_v1($f,$active['form'],'turn2',['SpeechResult'=>'What is happening now?'],$noModel);
    $fresh=vp3_operator_owner_email_record_v1($pdo,1,$request['id']);
    $continued=vp3_operator_owner_email_active_v1($pdo,$context,$request['id'],time());
    pec_check_v1(str_contains($signedTurn,'Check the send status in your phone review.')
        &&str_contains($signedTurn,'I will not start another send.')&&str_contains($signedTurn,'event=turn3')
        &&!str_contains($signedTurn,'BODY_SENTINEL')&&!str_contains($signedTurn,'sender@example.test')
        &&!str_contains($signedTurn,'UNEXPECTED_MODEL_REPLY')&&$modelCalls===0,
        'real signed concurrent turn returns only generic pending-send guidance');
    pec_check_v1($fresh['state']==='submitted'&&$fresh['reason']==='conversation_active'&&$continued!==null
        &&$continued['sid']===$active['sid']&&$continued['plan']['reason']==='eligible',
        'the original exact call stays eligible while its own authenticated send is underway');
    pec_check_v1($sendCount===0&&(int)$pdo->query('SELECT COUNT(*) FROM agent_connection_sends_v1')->fetchColumn()===0,
        'concurrent carrier speech creates no email send or replacement claim');
};

$http=static function(string $method,string $url,array $headers,?array $body,bool $form)use($pdo,$seed,$id,&$sendCount,&$transitions):array{
    if($method==='GET')return pec_http_v1($method,$url,$headers,$body,$form);
    if($method!=='POST'||$url!==VP3_GMAIL_API_V1.'/messages/send'||$form||($body['threadId']??null)!==$seed['payload']['reply']['thread_id'])
        throw new LogicException('Unexpected provider mutation in sending-transition test.');
    pec_check_v1($transitions===1&&$pdo->inTransaction()
        &&vp3_phone_agent_email_call_review_row_v1($pdo,1,$id,false)['state']==='claimed',
        'unchanged canonical Gmail transport runs after the signed turn with its original claimed review');
    ++$sendCount;
    return ['status'=>200,'json'=>['id'=>'sending_transition_sent','threadId'=>$seed['payload']['reply']['thread_id']]];
};
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[EmailCallSendingStatementV1::class]);
session_write_close();
try{$sent=agent_connection_email_chat_action_v1($pdo,$owner,$principal,12,$confirmation,$key,$http);}
finally{$GLOBALS['emailCallSendingTransitionV1']=null;$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PDOStatement::class]);}
pec_check_v1($sent['status']==='sent'&&$sendCount===1&&$transitions===1&&$signedTurn!==''&&$modelCalls===0,
    'one canonical reviewed email completes after the concurrent signed sending turn');
pec_check_v1((int)$pdo->query('SELECT COUNT(*) FROM agent_connection_sends_v1')->fetchColumn()===1
    &&(int)$pdo->query('SELECT COUNT(*) FROM phone_operator_carrier_attempts_v1')->fetchColumn()===1,
    'sending bridge creates neither another email operation nor another phone attempt');
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
pec_check_v1($api(['action'=>'voice_poll','review_id'=>$id])['review']['state']==='completed',
    'authenticated review completion follows the actual matching immutable native send receipt');
echo 'Email-call active sending acceptance: '.$GLOBALS['pecChecks']." assertions passed.\n";
