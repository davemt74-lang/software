<?php
declare(strict_types=1);

// Retain the shipped broker, model/payer routing, real meter and cURL boundary.
// The provider response is simulated; this does not certify live OCR quality.
require __DIR__.'/agent-connection-gmail-inbox-initial-summary-v1.php';
require_once dirname(__DIR__).'/includes/agent-gmail-document-input-v1.php';

$protocol='vp3.gmail-document-review.v1';$pdf=file_get_contents(__DIR__.'/fixtures/gmail-documents/quote.pdf');
$document=['mime'=>'application/pdf','name'=>'Quote Q-104','bytes'=>$pdf];
$control=static fn(Closure $guard):array=>['surface'=>'tool_json','protocol'=>$protocol,'gmail_document_review'=>true,
    'gmail_document'=>$document,'before_gmail_document_dispatch'=>static function()use($guard,$pdo,$owner):bool{
        agent_gmail_document_cloud_route_v1($pdo,$owner,17);return $guard();
    }];
$review=static fn(Closure $guard):array=>ai_v100_with_service_context(['service'=>'conversation','background'=>false,
    'stage'=>$protocol,'agent_id'=>17,'conversation_id'=>11],static fn()=>ai_generate_chat_response(
        '{"owner_instruction":"Check this quote","email_soul":"Be brief.","message":{"body":"Exact requested email"}}',
        [['role'=>'user','message'=>'unrelated previous message']],[['text'=>'unrelated private context']],$owner,'main_ai',$control($guard)));
$GLOBALS['gmailSummaryScenario']='compose';$calls=count($GLOBALS['gmailSummaryDispatch']);$held=false;$guards=0;
$response=$review(static function()use($pdo,&$held,&$guards):bool{
    ++$guards;$held=(int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn()===1;return true;
});
$wire=end($GLOBALS['gmailSummaryDispatch'])['payload'];$json=json_encode($wire,JSON_THROW_ON_ERROR);
$file=$wire['input'][0]['content'][1];
$ledger=$pdo->query("SELECT * FROM ai_execution_ledger WHERE service_stage='vp3.gmail-document-review.v1' ORDER BY id DESC LIMIT 1")->fetch();
check_gmail_summary(!empty($response['ok'])&&$guards===1&&$held&&count($GLOBALS['gmailSummaryDispatch'])===$calls+1
    &&$wire['store']===false&&$wire['max_output_tokens']===2200&&$file['type']==='input_file'
    &&$file['filename']==='Quote Q-104.pdf'&&$file['file_data']==='data:application/pdf;base64,'.base64_encode($pdf),
    'explicit PDF review sends the exact validated bytes in one bounded non-stored Responses request after usage reservation');
check_gmail_summary($ledger['service_key']==='conversation'&&(int)$ledger['is_background']===0
    &&(int)$ledger['agent_id']===17&&(int)$ledger['conversation_id']===11&&(int)$ledger['cloud_tokens_charged']===140
    &&!str_contains($json,'unrelated previous message')&&!str_contains($json,'unrelated private context')&&!str_contains($json,'private-chat-persona'),
    'document review retains selected Agent, Chat, measured usage and isolated context');
check_gmail_summary(str_contains($json,'untrusted reference data')&&str_contains($json,'You have no tools')&&str_contains($json,'Never invent dates')
    &&str_contains($json,'email_soul guides only suggested reply style'),'provider contract treats document instructions as data and requires uncertainty and reviewed reply wording');
foreach(['rejected','unknown','empty'] as $scenario){
    $GLOBALS['gmailSummaryScenario']=$scenario;$calls=count($GLOBALS['gmailSummaryDispatch']);$response=$review(static fn():bool=>true);
    check_gmail_summary(empty($response['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$calls+1,
        'document '.$scenario.' response never retries or changes model/provider');
}
$GLOBALS['gmailSummaryScenario']='compose';$calls=count($GLOBALS['gmailSummaryDispatch']);
$response=$review(static fn():bool=>false);
check_gmail_summary(empty($response['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$calls
    &&(int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn()===0,'late source revocation prevents dispatch and releases the real usage reservation');
$GLOBALS['gmailSummaryHomeOnly']=true;
try {$blockedRoute=$review(static fn():bool=>true);}
catch (DomainException $error) {
    if ($error->getCode()!==503) throw $error;
    $blockedRoute=['ok'=>false];
}
check_gmail_summary(empty($blockedRoute['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$calls
    &&(int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn()===0,
    'the shipped document route fence honors HomeServer-only preferences after usage reservation and releases the unsent allowance');
$GLOBALS['gmailSummaryHomeOnly']=false;
foreach([[],['service'=>'conversation'],['service'=>'conversation','background'=>true],['service'=>'initiative','background'=>false]] as $wrong){
    $response=ai_v100_with_service_context($wrong,static fn()=>ai_generate_chat_response('{}',[],[],$owner,'main_ai',$control(static fn():bool=>true)));
    check_gmail_summary(empty($response['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$calls,'document review cannot run without explicit foreground Conversation accounting');
}
$response=ai_v100_with_service_context(['service'=>'conversation','background'=>false],static fn()=>ai_generate_chat_response('{}',[],[],$owner,'main_ai',
    ['surface'=>'tool_json','protocol'=>$protocol,'gmail_document'=>$document]));
check_gmail_summary(empty($response['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$calls,'an unclaimed document protocol cannot fall through to ordinary Chat');
$anthropic=agent_gmail_document_provider_content_v1('anthropic',$document,'Owner direction');
check_gmail_summary($anthropic[0]===['type'=>'document','source'=>['type'=>'base64','media_type'=>'application/pdf','data'=>base64_encode($pdf)]]
    &&$anthropic[1]===['type'=>'text','text'=>'Owner direction'],'Anthropic native PDF input preserves the exact document bytes and explicit direction');
$image=['mime'=>'image/jpeg','name'=>'scan.jpg','bytes'=>file_get_contents(__DIR__.'/fixtures/gmail-documents/quote-scan.jpg')];
check_gmail_summary(agent_gmail_document_provider_content_v1('openai',$image,'Review')[1]['image_url']==='data:image/jpeg;base64,'.base64_encode($image['bytes'])
    &&agent_gmail_document_provider_content_v1('anthropic',$image,'Review')[0]['type']==='image','both provider image contracts preserve actual scan bytes');
echo "AGENT_GMAIL_DOCUMENTS_PROVIDER_V1=PASS\n";
