<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/phone-agent-email-call-operator-v1.php';
require_once dirname(__DIR__).'/includes/phone-agent-email-call-review-v1.php';
require_once dirname(__DIR__).'/includes/phone-agent-workspace-native-v1.php';

$f=pec_fixture_v1();$pdo=$f['pdo'];$owner=$f['owner'];$now=time();
$seed=pec_draft_v1($f,'voice_review_source',['conversation_id'=>12]);
$candidates=vp3_phone_agent_email_call_candidates_v1($pdo,$f['context'],$now);
$candidate=array_values(array_filter($candidates,static fn(array $c):bool=>$c['source_id']===$seed['id']))[0];
$active=pec_operator_active_v1($f,$candidate);$request=$active['request'];
pec_check_v1((int)$request['conversation_id']===12&&(int)$f['context']['enrollment']['conversation_id']===11,
    'call links the original saved email Chat independently of enrollment primary Chat');
$key=$_SESSION['agent_connection_email_review_v1']=bin2hex(random_bytes(32));
$scope=vp3_phone_agent_workspace_scope_v1($pdo,$owner,$f['token'],$f['device_id'],false,$now);
$base=['device_id'=>$f['device_id'],'enrollment_revision'=>(int)$scope['enrollment']['revision'],'scope_revision'=>$scope['scope_revision'],
    'agent_id'=>7,'conversation_id'=>12,'csrf_token'=>csrf_token()];
$api=static fn(array $input,?int $clock=null):array=>vp3_phone_agent_email_calls_request_v1($pdo,$owner,$input+$base,$f['token'],$clock??time());
$principal=vp3_phone_agent_source_scope_v1($pdo,$owner,7,12)['principal'];
$nativeReview=agent_connection_email_chat_action_v1($pdo,$owner,$principal,12,['action'=>'review','draft_id'=>$seed['id']],$key);
$arm=['action'=>'voice_arm','request_id'=>$request['id'],'draft_id'=>$seed['id'],
    'review_token'=>$nativeReview['review_token'],'watch_replies'=>false,'confirmed'=>true];
$current=['request'=>$active['request'],'context'=>$active['context'],'plan'=>$active['plan']];
$turn=static fn(string $speech,?string $sid=null,?int $clock=null):?string=>vp3_phone_agent_email_call_voice_turn_v1($pdo,$current,$sid??$active['sid'],1,$speech,$clock??time());
foreach(['yes','I agree','1','send it','send email 12345','do not send email 123456','send email 123456 and another email','send email one hundred twenty three'] as $speech)
    pec_check_v1(vp3_phone_agent_email_call_challenge_v1($speech)===null,'ambiguous speech is not email authority: '.$speech);
pec_check_v1(vp3_phone_agent_email_call_challenge_v1('send email 123456')==='123456'
    &&vp3_phone_agent_email_call_challenge_v1('Send email one two three four five six.')==='123456','strict digits and six spoken digits resolve the same challenge');
pec_check_v1($turn('yes')===null&&(int)$pdo->query('SELECT COUNT(*) FROM phone_agent_email_call_reviews_v1')->fetchColumn()===0,
    'answering or a generic yes cannot create a private email review grant');
pec_reject_v1(fn()=>$api(array_replace($arm,['confirmed'=>false])),'voice approval needs a separate explicit owner action','invalid_request');
pec_reject_v1(fn()=>$api(array_replace($arm,['request_id'=>str_repeat('f',32)])),'review cannot bind to a different call request','voice_review_unavailable');
pec_reject_v1(fn()=>$api(array_replace($arm,['draft_id'=>str_repeat('e',32)])),'call cannot authorize a different draft','review_changed');
pec_reject_v1(fn()=>$api(array_replace($arm,['watch_replies'=>true])),'watch choice must match the exact native token','review_changed');
$armed=$api($arm);$id=$armed['review']['id'];$row=vp3_phone_agent_email_call_review_row_v1($pdo,1,$id,false);
pec_check_v1($armed['review']['state']==='armed'&&preg_match('/^send email [0-9](?: [0-9]){5}$/D',$armed['challenge'])===1
    &&strtotime($armed['review']['expires_at'])<=time()+120,'explicit review gets a short-lived one-call challenge');
$stored=json_encode($row,JSON_THROW_ON_ERROR);
pec_check_v1(!str_contains($stored,$nativeReview['review_token'])&&!str_contains($stored,$armed['challenge'])
    &&!str_contains($stored,'BODY_SENTINEL')&&!str_contains($stored,'sender@example.test')
    &&!str_contains($stored,$f['token']),'voice grant stores hashes and references without email text, token or phone credential');
$generic=pec_operator_answer_v1($f,$active['form'],'turn1',['SpeechResult'=>'yes']);
pec_check_v1(str_contains($generic,'exact confirmation phrase')&&vp3_phone_agent_email_call_review_row_v1($pdo,1,$id,false)['state']==='armed',
    'actual signed Operator voice turn cannot turn a generic yes into Gmail approval');
pec_check_v1($turn($armed['challenge'],'CA'.str_repeat('f',32))===null,'another exact carrier SID cannot use this challenge');
$poll=$api(['action'=>'voice_poll','review_id'=>$id]);
pec_check_v1($poll['review']['state']==='armed'&&$pdo->query("SELECT status FROM agent_connection_email_drafts_v1 WHERE id='".$seed['id']."'")->fetchColumn()==='prepared',
    'heartbeat polling does not send or claim Gmail');
pec_reject_v1(fn()=>$api(['action'=>'voice_claim','review_id'=>$id,'review_token'=>$nativeReview['review_token']]),'unspoken challenge cannot be claimed','review_changed');
$badCode=vp3_phone_agent_email_call_challenge_v1($armed['challenge'])==='123456'?'654321':'123456';
for($i=0;$i<3;$i++)$turn('send email '.$badCode);
pec_check_v1(vp3_phone_agent_email_call_review_row_v1($pdo,1,$id,false)['state']==='cancelled','three incorrect exact-phrase attempts cancel the grant');
$armed=$api($arm);$id=$armed['review']['id'];
pec_check_v1($api(['action'=>'voice_cancel','review_id'=>$id])['review']['state']==='cancelled','owner can cancel an armed voice review');
$armed=$api($arm);$id=$armed['review']['id'];
$mutate=static function(string $sql,array $params,callable $work,string $label,?string $reason=null)use($pdo):void{
    $pdo->beginTransaction();try{vp3_phone_sql_v1($pdo,$sql,$params);pec_reject_v1($work,$label,$reason);}finally{$pdo->rollBack();}
};
$different=$seed['payload'];$different['body']='Changed after review';
$mutate('UPDATE agent_connection_email_drafts_v1 SET message_enc=? WHERE id=?',[ai_encrypt_secret(json_encode($different,JSON_THROW_ON_ERROR)),$seed['id']],
    fn()=>$api(['action'=>'voice_poll','review_id'=>$id]),'changed body invalidates the exact armed review','review_changed');
$different=$seed['payload'];$different['attachments']=[['asset_id'=>77]];
$mutate('UPDATE agent_connection_email_drafts_v1 SET message_enc=? WHERE id=?',[ai_encrypt_secret(json_encode($different,JSON_THROW_ON_ERROR)),$seed['id']],
    fn()=>$api(['action'=>'voice_poll','review_id'=>$id]),'changed attachment payload invalidates the exact armed review','review_changed');
$mutate('UPDATE phone_agent_email_call_policies_v1 SET revision=revision+1 WHERE owner_user_id=1',[],
    fn()=>$api(['action'=>'voice_poll','review_id'=>$id]),'call policy change invalidates armed review','policy_changed');
$mutate("UPDATE phone_browser_calls_v1 SET state='completed',ended_epoch=? WHERE public_id=?",[time(),$active['call']['public_id']],
    fn()=>$api(['action'=>'voice_poll','review_id'=>$id]),'ended native call cannot approve an email','call_changed');
$mutate("UPDATE phone_operator_carrier_attempts_v1 SET recording_state='failed' WHERE event_id=?",[(int)$request['event_id']],
    fn()=>$api(['action'=>'voice_poll','review_id'=>$id]),'withdrawn or failed recording prevents voice review','call_changed');
pec_reject_v1(fn()=>$api(['action'=>'voice_poll','review_id'=>$id],strtotime($armed['review']['expires_at'])),'challenge expiry cannot be extended by polling','voice_review_expired');
$mutate('UPDATE phone_agent_email_call_reviews_v1 SET heartbeat_epoch=? WHERE id=?',[time()-21,$id],
    fn()=>$api(['action'=>'voice_poll','review_id'=>$id]),'closed or suspended owner review cannot resume stale voice intent','voice_review_expired');
$session=$_SESSION;$_SESSION=[];
$turn($armed['challenge']);
pec_check_v1(vp3_phone_agent_email_call_review_row_v1($pdo,1,$id,false)['state']==='requested'
    &&$pdo->query("SELECT status FROM agent_connection_email_drafts_v1 WHERE id='".$seed['id']."'")->fetchColumn()==='prepared',
    'carrier can record exact intent without any owner session but never executes Gmail');
pec_reject_v1(fn()=>$api(['action'=>'voice_claim','review_id'=>$id,'review_token'=>$nativeReview['review_token']]),
    'logged-out owner cannot execute recorded call intent','owner_sign_in_required');$_SESSION=$session;
$oldKey=$_SESSION['phone_agent_workspace_key_v1'];$_SESSION['phone_agent_workspace_key_v1']=bin2hex(random_bytes(32));
pec_reject_v1(fn()=>$api(['action'=>'voice_claim','review_id'=>$id,'review_token'=>$nativeReview['review_token']]),'a different authenticated session cannot claim the old review','workspace_changed');
$_SESSION['phone_agent_workspace_key_v1']=$oldKey;
pec_reject_v1(fn()=>$api(['action'=>'voice_claim','review_id'=>$id,'review_token'=>$nativeReview['watch_review_token']]),
    'claim cannot substitute a different canonical watch token','review_changed');
$claim=$api(['action'=>'voice_claim','review_id'=>$id,'review_token'=>$nativeReview['review_token']]);
pec_check_v1($claim['review']['state']==='claimed','exact current owner review can claim the spoken intent once');
pec_reject_v1(fn()=>$api(['action'=>'voice_claim','review_id'=>$id,'review_token'=>$nativeReview['review_token']]),'claimed intent is never automatically retried','review_changed');
$native=['scope'=>$scope,'resolved'=>vp3_phone_agent_workspace_item_v1($pdo,$scope,$request['item_ref'])];
$confirmation=['action'=>'confirm','draft_id'=>$seed['id'],'review_token'=>$nativeReview['review_token'],'confirmed'=>true,
    'watch_replies'=>false,'agent_id'=>7,'conversation_id'=>12];
vp3_phone_agent_email_call_review_execution_v1($pdo,$native,$id,$confirmation,time());
pec_check_v1(true,'claimed exact review reaches the normal native Gmail execution fence');
$mutate('UPDATE phone_agent_email_call_policies_v1 SET drafts_enabled=0,urgent_enabled=0 WHERE owner_user_id=1',[],
    fn()=>vp3_phone_agent_email_call_review_execution_v1($pdo,$native,$id,$confirmation,time()),'revocation after claim still blocks final send','off');
$mutate('UPDATE phone_agent_email_call_reviews_v1 SET claimed_epoch=? WHERE id=?',[time()-31,$id],
    fn()=>vp3_phone_agent_email_call_review_execution_v1($pdo,$native,$id,$confirmation,time()),'delayed claim cannot restart an email send','review_changed');
$mutate('UPDATE phone_recording_policies_v1 SET transcribe=0 WHERE owner_user_id=1 AND agent_id=7',[],
    fn()=>vp3_phone_agent_email_call_review_execution_v1($pdo,$native,$id,$confirmation,time()),'recording policy withdrawal after claim blocks actual send','route_or_recording_changed');
$mutate('UPDATE phone_browser_numbers_v1 SET recording_enabled=0 WHERE phone_number_id=1',[],
    fn()=>vp3_phone_agent_email_call_review_execution_v1($pdo,$native,$id,$confirmation,time()),'assigned route recording withdrawal after claim blocks send','route_or_recording_changed');
$mutate("UPDATE user_calendar_events SET description='Changed after voice claim' WHERE id=?",[(int)$request['event_id']],
    fn()=>vp3_phone_agent_email_call_review_execution_v1($pdo,$native,$id,$confirmation,time()),'Calendar mutation after claim invalidates the current call','assignment_changed');
$mutate('UPDATE phone_operator_scheduled_assignments_v1 SET enabled=0 WHERE owner_user_id=1 AND event_id=?',[(int)$request['event_id']],
    fn()=>vp3_phone_agent_email_call_review_execution_v1($pdo,$native,$id,$confirmation,time()),'withdrawn native assignment after claim blocks send','assignment_changed');
$mutate('INSERT INTO phone_operator_recipient_optouts_v1(owner_user_id,destination_hash,withdrawn_epoch) VALUES(1,?,?)',[hash('sha256','+16025550100'),time()],
    fn()=>vp3_phone_agent_email_call_review_execution_v1($pdo,$native,$id,$confirmation,time()),'recipient opt-out after claim blocks actual email confirmation','recipient_opted_out');
pec_reject_v1(fn()=>vp3_phone_agent_email_call_review_execution_v1($pdo,$native,$id,array_replace($confirmation,['watch_replies'=>true]),time()),
    'final execution rechecks the exact watch choice','review_changed');
pec_check_v1((int)$pdo->query('SELECT COUNT(*) FROM agent_connection_sends_v1')->fetchColumn()===0,'all carrier and voice steps leave Gmail transport untouched');

// Exercise the actual canonical send lifecycle through both native execution hooks.
$GLOBALS['VP3_PHONE_AGENT_NATIVE_EXECUTION_CONTEXT_V1']=['context'=>$native,'kind'=>'email','voice_review'=>$id,'voice_input'=>$confirmation];
define('VP3_PHONE_AGENT_NATIVE_V1','email');
session_write_close();$sendCount=0;
$http=static function(string $method,string $url,array $headers,?array $body,bool $form)use(&$sendCount,$seed):array{
    if($method==='GET')return pec_http_v1($method,$url,$headers,$body,$form);
    if($method!=='POST'||$url!==VP3_GMAIL_API_V1.'/messages/send'||$form||($body['threadId']??null)!==$seed['payload']['reply']['thread_id'])
        throw new LogicException('Unexpected native send operation.');
    $mime=base64_decode(strtr($body['raw'],'-_','+/'),true);
    $parts=is_string($mime)?explode("\r\n\r\n",$mime,2):[];
    pec_check_v1(count($parts)===2&&str_contains($parts[0],'To: sender@example.test')
        &&base64_decode($parts[1],true)==='BODY_SENTINEL_PRIVATE_DRAFT','canonical provider request contains the exact reviewed recipient and body');
    $sendCount++;return ['status'=>200,'json'=>['id'=>'voice_review_sent','threadId'=>$seed['payload']['reply']['thread_id']]];
};
$sent=agent_connection_email_chat_action_v1($pdo,$owner,$principal,12,$confirmation,$key,$http);
pec_check_v1($sent['status']==='sent'&&$sendCount===1,'open authenticated native review executes exactly one canonical Gmail send');
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
$done=$api(['action'=>'voice_poll','review_id'=>$id]);
pec_check_v1($done['review']['state']==='completed','voice completion is derived from the canonical Gmail result');
pec_check_v1((int)$pdo->query('SELECT COUNT(*) FROM agent_connection_sends_v1')->fetchColumn()===1,'voice approval shares the existing native Gmail send ledger');
echo 'Email-call voice review acceptance: '.$GLOBALS['pecChecks']." assertions passed.\n";
