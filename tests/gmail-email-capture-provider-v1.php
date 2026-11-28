<?php
declare(strict_types=1);

// The native broker, service meter and provider request builders run unchanged.
// Only physical provider transport is simulated; this does not test live OCR quality.
require __DIR__.'/agent-connection-gmail-inbox-initial-summary-v1.php';
require_once dirname(__DIR__).'/includes/gmail-email-capture-input-v1.php';
require_once dirname(__DIR__).'/includes/agent-gmail-document-input-v1.php';
$protocol=GMAIL_EMAIL_CAPTURE_PROTOCOL_V1;
$capture=['mode'=>'text','text'=>'Camera launch December 12 at 10:00.','image'=>''];
$control=static fn(Closure $guard,array $value):array=>['surface'=>'tool_json','protocol'=>$protocol,'gmail_email_capture'=>true,
    'gmail_capture_input'=>$value,'before_gmail_capture_dispatch'=>$guard];
$review=static fn(Closure $guard,array $value):array=>ai_v100_with_service_context(['service'=>'conversation','background'=>false,
    'stage'=>$protocol,'agent_id'=>17,'conversation_id'=>11,'job_key'=>'gmail-capture:provider-test'],static fn()=>ai_generate_chat_response(
        '{"owner_instruction":"Analyze this selected area","selected_text":"Camera launch December 12 at 10:00."}',
        [['role'=>'user','message'=>'unrelated previous message']],[['text'=>'unrelated private context']],$owner,'main_ai',$control($guard,$value),$value['mode']==='photo'?[$value['image']]:[]));
$GLOBALS['gmailSummaryScenario']='compose';$calls=count($GLOBALS['gmailSummaryDispatch']);$guards=0;$held=false;
$response=$review(static function()use($pdo,&$guards,&$held):bool{++$guards;$held=(int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn()===1;return true;},$capture);
$wire=end($GLOBALS['gmailSummaryDispatch'])['payload'];$json=json_encode($wire,JSON_THROW_ON_ERROR);
check_gmail_summary(!empty($response['ok'])&&$guards===1&&$held&&count($GLOBALS['gmailSummaryDispatch'])===$calls+1&&$wire['store']===false&&$wire['max_output_tokens']===4096,
    'email area analysis dispatches once after a real native usage reservation and disables provider storage');
check_gmail_summary(!str_contains($json,'unrelated previous message')&&!str_contains($json,'unrelated private context')&&!str_contains($json,'private-chat-persona')
    &&str_contains($json,'untrusted data')&&str_contains($json,'Do not fetch links'),'capture interpretation excludes unrelated history, RAG and persona while retaining the data-only contract');
$ledger=$pdo->query("SELECT * FROM ai_execution_ledger WHERE service_stage='vp3.gmail-email-capture.v1' ORDER BY id DESC LIMIT 1")->fetch();
check_gmail_summary($ledger['service_key']==='conversation'&&(int)$ledger['is_background']===0&&(int)$ledger['agent_id']===17&&(int)$ledger['conversation_id']===11
    &&(int)$ledger['cloud_tokens_charged']===140,'capture usage retains the exact owner, selected Agent and Chat');
$image='data:image/jpeg;base64,'.trim(file_get_contents(__DIR__.'/fixtures/tv-frame.b64'));
$photo=['mode'=>'photo','text'=>'Visible selected text','image'=>$image];
gmail_email_capture_input_v1($photo);
$calls=count($GLOBALS['gmailSummaryDispatch']);$response=$review(static fn():bool=>true,$photo);$wire=end($GLOBALS['gmailSummaryDispatch'])['payload'];
check_gmail_summary(!empty($response['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$calls+1
    &&$wire['input'][0]['content'][1]===['type'=>'input_image','image_url'=>$image],'photo analysis preserves only the exact bounded JPEG supplied by its capture request');
foreach(['rejected','unknown','empty'] as $scenario){
    $GLOBALS['gmailSummaryScenario']=$scenario;$calls=count($GLOBALS['gmailSummaryDispatch']);$response=$review(static fn():bool=>true,$capture);
    check_gmail_summary(empty($response['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$calls+1,'capture '.$scenario.' never retries or switches model/provider');
}
$GLOBALS['gmailSummaryScenario']='compose';$calls=count($GLOBALS['gmailSummaryDispatch']);$response=$review(static fn():bool=>false,$capture);
check_gmail_summary(empty($response['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$calls&&(int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn()===0,
    'late source revocation stops the actual physical capture dispatch and releases held usage');
foreach([[],['service'=>'conversation'],['service'=>'conversation','background'=>true],['service'=>'initiative','background'=>false]] as $wrong){
    $response=ai_v100_with_service_context($wrong,static fn()=>ai_generate_chat_response('{}',[],[],$owner,'main_ai',$control(static fn():bool=>true,$capture)));
    check_gmail_summary(empty($response['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$calls,'capture requires explicit native foreground Conversation accounting');
}
$response=ai_v100_with_service_context(['service'=>'conversation','background'=>false],static fn()=>ai_generate_chat_response('{}',[],[],$owner,'main_ai',
    ['surface'=>'tool_json','protocol'=>$protocol,'gmail_capture_input'=>$capture]));
check_gmail_summary(empty($response['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$calls,'an unclaimed capture protocol cannot fall through to ordinary Chat');
$response=ai_v100_with_service_context(['service'=>'conversation','background'=>false],static fn()=>ai_generate_chat_response('{}',[],[],$owner,'main_ai',$control(static fn():bool=>true,$photo),[]));
check_gmail_summary(empty($response['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$calls,'capture image substitution or omission is rejected before dispatch');
echo "GMAIL_EMAIL_CAPTURE_PROVIDER=PASS\n";
