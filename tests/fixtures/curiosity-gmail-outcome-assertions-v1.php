<?php
declare(strict_types=1);

$outcomeChecks=$checks;
require_once dirname(__DIR__,2).'/includes/agent-curiosity-gmail-outcomes-v1.php';
if(!function_exists('notification_create_locked'))require_once dirname(__DIR__,2).'/includes/notifications.php';
$pdo->exec('UPDATE agent_connections_v1 SET followups_enabled=1 WHERE id=88');
$sentId='curiosity_sent_quote';$replyId='supplier_quote_answer';$operation=hash('sha256','curiosity-reviewed-send');
$payload['body']='Could you confirm delivery by December 5? This is the wording the owner edited and sent.';
$pdo->prepare("UPDATE agent_connection_email_drafts_v1 SET status='sent',sent_message_id=?,watch_replies=1,watch_registered=1,message_enc=? WHERE id=?")
    ->execute([$sentId,ai_encrypt_secret(json_encode($payload)),$draftId]);
$sendReceipt=['source'=>'agent_connection_email','email_outcome'=>['draft_id'=>$draftId,'message_id'=>$sentId]];
$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(11,NULL,'assistant','Email sent.',?)")->execute([json_encode($sendReceipt)]);
$verifiedSend=['operation_key'=>$operation,'authorization_key'=>$nativeDraft['authorization_key'],'connection_id'=>88,
    'from'=>'owner@example.test','to'=>'supplier@example.test','message_id'=>$sentId,'thread_id'=>'quote_thread','sent_at'=>time()-20];
canvas_pipeline_check(agent_connection_email_followup_register_v1($pdo,1,7,11,$verifiedSend,
    ['draft_id'=>$draftId,'user_message_id'=>(int)$nativeDraft['user_message_id']]),'a separately enabled native watch registers against the reviewed send receipt');
$makeRaw=static function(string $id,array $labels,int $at,array $headers,string $body):array{
    return ['id'=>$id,'threadId'=>'quote_thread','labelIds'=>$labels,'internalDate'=>(string)$at,
        'payload'=>['mimeType'=>'text/plain','headers'=>array_map(static fn(string $name,string $value):array=>['name'=>$name,'value'=>$value],array_keys($headers),array_values($headers)),
            'body'=>['data'=>rtrim(strtr(base64_encode($body),'+/','-_'),'=')]]];
};
$sentRaw=$makeRaw($sentId,['SENT'],(time()-15)*1000,['From'=>'owner@example.test','To'=>'supplier@example.test','Subject'=>'Re: Quote Q-104',
    'Message-ID'=>'<curiosity-sent@vp3.example.test>','In-Reply-To'=>'<quote104@supplier.example.test>','References'=>'<quote104@supplier.example.test>'],$payload['body']);
$replyRaw=$makeRaw($replyId,['INBOX'],(time()-5)*1000,['From'=>'supplier@example.test','To'=>'owner@example.test','Subject'=>'Re: Quote Q-104',
    'Message-ID'=>'<supplier-answer@supplier.example.test>','In-Reply-To'=>'<curiosity-sent@vp3.example.test>',
    'References'=>'<quote104@supplier.example.test> <curiosity-sent@vp3.example.test>','Authentication-Results'=>'mx.google.com; dmarc=pass'],
    'December 6 is our earliest delivery date. Can you accept it? PRIVATE_REPLY_SENTINEL');
$claim=str_repeat('7',32);$pdo->prepare('UPDATE agent_connection_email_followups_v1 SET claim_key=?,next_check_at=? WHERE operation_key=?')
    ->execute([$claim,gmdate('Y-m-d H:i:s',time()+180),$operation]);
$q=$pdo->prepare('SELECT * FROM agent_connection_email_followups_v1 WHERE operation_key=?');$q->execute([$operation]);$watch=$q->fetch(PDO::FETCH_ASSOC);
canvas_pipeline_check(agent_connection_email_followup_deliver_v1($pdo,$watch,$claim,agent_connection_gmail_message_v1($replyRaw)),
    'the actual native watch delivery creates the authenticated response notice');
$outcomeReads=0;$newerReply=false;
$outcomeHttp=static function(string $method,string $url)use(&$sentRaw,&$replyRaw,&$outcomeReads,&$newerReply,$raw,$http):array{
    if($method!=='GET')throw new LogicException('Outcome analysis is read-only.');
    ++$outcomeReads;
    if(str_contains($url,'/threads/')){
        $messages=[$raw,$sentRaw,$replyRaw];
        if($newerReply){$new=$replyRaw;$new['id']='newer_reply';$new['internalDate']=(string)((time()-1)*1000);$new['payload']['headers'][3]['value']='<newer@supplier.example.test>';$messages[]=$new;}
        return ['status'=>200,'json'=>['id'=>'quote_thread','messages'=>$messages]];
    }
    if(str_contains($url,'/messages/'.$sentRaw['id'].'?'))return ['status'=>200,'json'=>$sentRaw];
    if(str_contains($url,'/messages/'.$replyRaw['id'].'?'))return ['status'=>200,'json'=>$replyRaw];
    return $http($method,$url);
};
$before=vp3_curiosity_analysis_snapshot_v1($pdo,$owner,$binding);
canvas_pipeline_check(isset($before['email_replies'][$finding])&&$outcomeReads===0,'discovery links the actual watched reply to its exact Curiosity objective without fetching mail');
canvas_pipeline_check(vp3_curiosity_gmail_responses_v1($pdo,$owner,array_replace($binding,['conversation_id'=>15]),$brain['evidence'])===[],
    'a sibling Chat cannot borrow the watched response');
if($scenario==='gmail-outcome-newer')$newerReply=true;
if($scenario==='gmail-outcome-truncated')$replyRaw['payload']['body']['data']=rtrim(strtr(base64_encode('December 6 is earliest. '.str_repeat('Additional terms. ',700)),'+/','-_'),'=');
canvas_pipeline_check(vp3_curiosity_analysis_schedule_v1($pdo,$owner,$binding)==='queued','a verified watched reply admits one existing Curiosity analysis job');
$runs=canvas_pipeline_runs('initiative.prepare');$reviewRun=(int)end($runs)['id'];$outcomeCalls=0;
$done=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($reviewRun),static function(string $prompt)use($pdo,$finding,$scenario,&$replyRaw,&$outcomeCalls):string{
    ++$outcomeCalls;$input=json_decode($prompt,true);$mail=$input['watched_email_replies'][0];
    canvas_pipeline_check(!db()->inTransaction()&&str_contains($mail['owner_request'],'December 4')
        &&str_contains($mail['sent_wording'],'December 5')&&str_contains($mail['sender_response'],'December 6'),
        'Curiosity compares the original goal, actual owner-edited sent wording and verified sender response');
    if($scenario==='gmail-outcome-revoked')$pdo->exec('UPDATE agent_connections_v1 SET followups_enabled=0 WHERE id=88');
    if($scenario==='gmail-outcome-changed')$replyRaw['payload']['body']['data']=rtrim(strtr(base64_encode('December 5 is now available.'),'+/','-_'),'=');
    $ref=$scenario==='gmail-outcome-unknown'?'curiosity-finding:'.str_repeat('f',32):$finding;
    return json_encode(['draft'=>'The actual sent email requests December 5, while the supplier offers December 6. The owner needs to decide.',
        'questions'=>[],'email_outcomes'=>[['source_ref'=>$ref,'state'=>$scenario==='gmail-outcome-truncated'?'answered':'conflict',
            'summary'=>'The email sent by the owner requests December 5; the watched reply offers December 6 as the earliest date.',
            'question'=>'Would December 6 work, or should we ask about another option?']]]);
},['gmail_http'=>$outcomeHttp],false);
if($scenario!=='gmail-outcome'){
    canvas_pipeline_check(!$done['ok']&&$outcomeCalls===($scenario==='gmail-outcome-newer'?0:1),
        'newer mail, access withdrawal, changed content and unknown model references cannot commit an email outcome or repeat inference');
    canvas_pipeline_check(vp3_curiosity_gmail_outcome_v1($pdo,$owner,$binding,$before['email_replies'][$finding])===null,
        'a failed response analysis cannot create a downstream decision source');
    echo 'CURIOSITY_GMAIL_OUTCOME_NEGATIVE=PASS ('.$scenario.', '.($mysql?'mysql':'sqlite').")\n";return;
}
if(!$done['ok'])throw new RuntimeException('Outcome worker failed: '.json_encode($done));
$after=vp3_curiosity_analysis_snapshot_v1($pdo,$owner,$binding);$saved=canvas_pipeline_result($reviewRun)['initiative_tool_result'];
canvas_pipeline_check($saved['output']['email_outcomes'][0]['state']==='conflict'&&$outcomeCalls===1,'one canonical receipt stores the typed source-bound interpretation');
canvas_pipeline_check($after['revision']===$before['revision']&&vp3_curiosity_analysis_schedule_v1($pdo,$owner,$binding)==='already_handled',
    'an analysis output cannot manufacture new input or another paid response analysis');
$q=$pdo->prepare("SELECT id,result_json FROM agent_workflow_receipts WHERE run_id=? AND status='completed' ORDER BY id DESC LIMIT 1");$q->execute([$reviewRun]);$proof=$q->fetch(PDO::FETCH_ASSOC);
$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute(['{}',$proof['id']]);
canvas_pipeline_check(vp3_curiosity_gmail_outcome_v1($pdo,$owner,$binding,$before['email_replies'][$finding])===null,
    'a mismatched action and receipt withdraws the outcome instead of authorizing a decision');
$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute([$proof['result_json'],$proof['id']]);
$brain=vp3_brainstorm_snapshot_v1($pdo,$owner,$binding);
canvas_pipeline_check($brain['evidence'][$finding]['curiosity_gmail_outcome']['owner_objective']==='unverified'
    &&str_contains(json_encode($brain['reports']),'December 6')&&!str_contains(json_encode($brain),'PRIVATE_REPLY_SENTINEL'),
    'Brainstorming receives the interpretation and unresolved objective without copied raw reply text');
$candidate=['jobs'=>[['tool'=>'chat.check_in','primary'=>$finding,'refs'=>[$finding],
    'focus'=>'Ask for a delivery-date decision.','report'=>true]]];
$unreviewed=$brain['evidence'];unset($unreviewed[$finding]['curiosity_gmail_outcome']);
try{vp3_initiative_plan_v1(json_encode($candidate),$unreviewed,['chat.check_in']);throw new LogicException('Expected an unreviewed decision rejection.');}
catch(Vp3InitiativePlanValidationV1 $expected){canvas_pipeline_check(true,'an unreviewed Curiosity finding cannot become a Chat decision');}
$answered=$brain['evidence'];$answered[$finding]['curiosity_gmail_outcome']['state']='answered';$answered[$finding]['curiosity_gmail_outcome']['question']='';
try{vp3_initiative_plan_v1(json_encode($candidate),$answered,['chat.check_in']);throw new LogicException('Expected an unnecessary question rejection.');}
catch(Vp3InitiativePlanValidationV1 $expected){canvas_pipeline_check(true,'an answered report without a useful decision question cannot force another Chat interruption');}
canvas_pipeline_check(vp3_brainstorm_schedule_v1($pdo,$owner,$binding)==='queued','the reviewed outcome wakes the existing Brainstorming lane');
$runs=canvas_pipeline_runs('initiative.prepare');$brainRun=(int)end($runs)['id'];
$done=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($brainRun),static fn():string=>json_encode(['jobs'=>[],
    'opportunities'=>[['tool'=>'chat.check_in','primary'=>$finding,'refs'=>[$finding],
        'objective'=>'Ask whether the later delivery date works','rationale'=>'The owner needs to choose between accepting December 6 or asking about alternatives.']]]),null,false);
canvas_pipeline_check($done['ok'],'Brainstorming proposes a decision without posting or sending anything');
$plan=['binding'=>$binding,'sources'=>array_column($brain['evidence'],'revision','ref'),'canonical_sources'=>array_column($brain['evidence'],'revision','ref')];
vp3_initiative_enqueue_v1($pdo,$owner,'initiative.plan',$plan,hash('sha256','watched-reply-ob1'));
$runs=canvas_pipeline_runs('initiative.plan');$supervisor=(int)end($runs)['id'];
$done=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($supervisor),static fn():string=>json_encode(['jobs'=>[
    ['tool'=>'chat.check_in','primary'=>$finding,'refs'=>[$finding],'focus'=>'Ask the owner to choose a delivery-date next step.','report'=>true]]]),null,false);
canvas_pipeline_check($done['ok']&&(int)canvas_pipeline_result($supervisor)['jobs']===1,'OB1 alone assigns the source-bound decision to Agent Chat');
$runs=canvas_pipeline_runs('initiative.prepare');$decisionRun=(int)end($runs)['id'];
$pdo->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00'");
$done=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($decisionRun),static function(string $prompt):string{
    $input=json_decode($prompt,true);
    canvas_pipeline_check($input['response_kind']==='chat.check_in'&&str_contains($prompt,'December 6')&&!str_contains($prompt,'PRIVATE_REPLY_SENTINEL'),
        'the Chat writer receives the reviewed decision and uncertainty rather than raw mail');
    return json_encode(['draft'=>'The watched supplier reply offers December 6, while your sent email requested December 5. Would the later date work, or should we ask about another option?']);
},['gmail_http'=>$outcomeHttp],false);
if(!$done['ok'])throw new RuntimeException('Decision worker failed: '.json_encode($done));
canvas_pipeline_check(vp3_initiative_deliver_pending_v1($pdo,$owner,7)===true,'the decision reaches the existing native Agent Chat delivery gate');
$decision=canvas_pipeline_result($decisionRun);$postId=$decision['initiative_message_id'];
canvas_pipeline_check($postId>0&&$decision['initiative_tool_result']['output']['curiosity_email_reply']['response_revision']===$before['email_replies'][$finding]['revision'],
    'the delivered decision retains its exact watched-response identity');
$q=$pdo->prepare('SELECT context_json FROM chat_messages WHERE id=?');$q->execute([$postId]);$decisionContext=json_decode($q->fetchColumn(),true);
$replyState=$decisionContext['initiative_followthrough_state'];$ownerAnswer='December 6 is too late. Please compare pickup and delivery options.';
$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(11,1,'user',?)")->execute([$ownerAnswer]);$answerId=(int)$pdo->lastInsertId();
$follow=vp3_initiative_followthrough_reply_v1($pdo,$owner,$principal,11,$answerId,
    ['message_id'=>$postId,'reply_ref'=>$replyState['reply_ref'],'revision'=>$replyState['revision']],$ownerAnswer,null,
    static fn():string=>json_encode(['intent'=>'advance','focus'=>'Compare pickup and delivery options for the owner, without sending a message.']));
canvas_pipeline_check(!empty($follow['handled'])&&str_contains(json_encode($follow),'queued'),
    'the actual authenticated owner answer is tracked by native follow-through as the next private objective');
$decisionGoal=json_decode(agent_workflow_row_v1400($pdo,1,$decisionRun)['goal'],true);
vp3_initiative_enqueue_v1($pdo,$owner,'initiative.prepare',$decisionGoal,hash('sha256','repeat-watched-response-decision'));
$runs=canvas_pipeline_runs('initiative.prepare');$duplicate=(int)end($runs)['id'];
$repeatCalls=0;
$repeat=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($duplicate),static function()use(&$repeatCalls):string{++$repeatCalls;return json_encode(['draft'=>'Repeat decision.']);},['gmail_http'=>$outcomeHttp],false);
canvas_pipeline_check(!$repeat['ok']&&$repeatCalls===0,'a delivered watched-response decision cannot repeat or charge another model call');
$pdo->exec('UPDATE agent_connections_v1 SET followups_enabled=0 WHERE id=88');
canvas_pipeline_check(vp3_curiosity_gmail_responses_v1($pdo,$owner,$binding,$brain['evidence'])===[],
    'withdrawing follow-up access removes the derived outcome and decision source');
echo 'CURIOSITY_GMAIL_OUTCOME=PASS ('.($checks-$outcomeChecks).' assertions, '.($mysql?'mysql':'sqlite').")\n";
