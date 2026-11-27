<?php
declare(strict_types=1);

$scenario=$argv[1]??'gmail';
if($scenario==='gmail-new-mail')$GLOBALS['curiosityNewMail']=true;
if($scenario==='gmail-outbound')$GLOBALS['curiosityNewMail']='sent';
$beforeDrafts=canvas_pipeline_count('agent_connection_email_drafts_v1');$modelCalls=0;
$done=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($work),static function(string $prompt,string $protocol)use(&$modelCalls,$scenario,$pdo,&$raw,$owner):string{
    ++$modelCalls;$input=json_decode($prompt,true);
    canvas_pipeline_check(!db()->inTransaction()&&$protocol==='vp3.initiative-draft.v1'
        &&$input['response_kind']==='coordination_gmail_reply_draft'&&str_contains($input['owner_reply'],'December 4')
        &&str_contains($input['email_soul'],'Email Specialist')&&$input['attachment_review']['coverage']==='partial',
        'the governed Email Specialist receives the actual answer, current Email Soul and uncertain attachment context');
    if($scenario==='gmail-revoked')$pdo->exec('UPDATE agent_connections_v1 SET read_enabled=0 WHERE id=88');
    if($scenario==='gmail-stale-bytes')$raw['payload']['parts'][1]['body']['data']=rtrim(strtr(base64_encode('changed file'),'+/','-_'),'=');
    if($scenario==='gmail-soul'){
        $soul=agent_brain_soul_profile_v1($owner,'email');
        agent_brain_soul_update_v1($owner,'email',$soul['revision'],'save','Use short replies and a direct opening sentence.');
    }
    return json_encode(['draft'=>'Thanks for the quote. Could you confirm whether delivery by December 4 is possible?']);
},['gmail_http'=>$docHttp],false);
if($scenario!=='gmail'){
    if($scenario==='gmail-soul'){
        $directory=agent_brain_user_dir(1);$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($directory);
    }
    canvas_pipeline_check(!$done['ok']&&canvas_pipeline_count('agent_connection_email_drafts_v1')===$beforeDrafts,
        'a changed '.$scenario.' cannot commit a reply draft or false ready result');
    canvas_pipeline_check($modelCalls===(in_array($scenario,['gmail-new-mail','gmail-outbound'],true)?0:1),
        'freshness checks prevent stale preflight inference and never retry paid preparation');
    echo 'CURIOSITY_GMAIL_DRAFT_NEGATIVE=PASS ('.$scenario.', '.($mysql?'mysql':'sqlite').")\n";return;
}
if(!$done['ok'])throw new RuntimeException('Gmail worker failed: '.json_encode($done));
canvas_pipeline_check(canvas_pipeline_count('agent_connection_email_drafts_v1')===$beforeDrafts+1&&$modelCalls===1,'one Email Specialist worker commits one real canonical draft');
$result=canvas_pipeline_result($work);$draftId=$result['initiative_tool_result']['output']['gmail_reply_draft_id'];
$nativeDraft=agent_connection_email_chat_draft_v1($pdo,1,7,11,$draftId);$payload=agent_connection_email_chat_payload_v1($nativeDraft);
canvas_pipeline_check($nativeDraft['recipient']==='supplier@example.test'&&$payload['reply']['thread_id']==='quote_thread'
    &&$payload['reply']['message_id']===$raw['id']&&$payload['reply']['in_reply_to']==='<quote104@supplier.example.test>'
    &&$payload['body']==='Thanks for the quote. Could you confirm whether delivery by December 4 is possible?',
    'recipient, exact Gmail thread and RFC reply headers come from Gmail while the owner controls requested terms');
canvas_pipeline_check(!str_contains($nativeDraft['message_enc'],'December 4')&&$nativeDraft['status']==='prepared'
    &&$nativeDraft['sent_message_id']===null&&(int)$nativeDraft['watch_replies']===0,'the reply remains encrypted, unsent and outside automatic follow-up watches');
$goal=json_decode(agent_workflow_row_v1400($pdo,1,$work)['goal'],true);
vp3_curiosity_gmail_result_current_v1($pdo,$owner,$binding,$goal,$result['initiative_tool_result']);
$progress=vp3_curiosity_gmail_progress_v1($pdo,$owner,$binding,[$finding]);
canvas_pipeline_check($progress[$finding]['draft_state']==='awaiting_review'&&$progress[$finding]['owner_objective']==='unverified',
    'OB1 receives canonical awaiting-review progress without treating the owner objective as completed');
$authority=[];$reports=vp3_initiative_specialist_reports_v1($pdo,$owner,$binding,$brain['evidence'],$authority);
canvas_pipeline_check(($authority['curiosity_reply_drafts'][$finding]??null)===$progress[$finding],
    'the supervisor reads the same native draft progress through its existing specialist report path');
canvas_pipeline_check(!isset(vp3_initiative_tools_catalog_v1($pdo,$owner,$binding,$brain['evidence'])['gmail.prepare_reply']),
    'a committed draft removes the request from further autonomous drafting');
$pdo->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00'");
$delivered=vp3_initiative_deliver_pending_v1($pdo,$owner,7);
$q=$pdo->prepare("SELECT context_json FROM chat_messages WHERE conversation_id=11 AND role='assistant' ORDER BY id DESC LIMIT 1");$q->execute();$postContext=json_decode($q->fetchColumn(),true);
canvas_pipeline_check(($postContext['actions'][0]['type']??null)==='email_review'&&($postContext['actions'][0]['id']??null)===$draftId,
    'the actual Agent Chat delivery carries the existing authenticated Gmail review action');
// Busy accounts keep draft progress even after many unrelated completed jobs.
for($index=0;$index<35;++$index){
    $unrelated=$goal;$unrelated['tool']='work.prepare_private';$unrelated['report']=false;
    vp3_initiative_enqueue_v1($pdo,$owner,'initiative.prepare',$unrelated,hash('sha256','unrelated-draft-progress-'.$index));
    $runs=canvas_pipeline_runs('initiative.prepare');$other=(int)end($runs)['id'];
    $finished=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($other),static fn():string=>json_encode(['draft'=>'Private source-grounded notes.']),null,false);
    if(!$finished['ok'])throw new LogicException('The unrelated native job did not complete.');
}
canvas_pipeline_check(vp3_curiosity_gmail_progress_v1($pdo,$owner,$binding,[$finding])===$progress,
    'completed unrelated work cannot age canonical draft progress out of the supervisor report');
$pdo->prepare("UPDATE agent_connection_email_drafts_v1 SET status='sent',sent_message_id='native_sent_reply' WHERE id=?")->execute([$draftId]);
canvas_pipeline_check(vp3_curiosity_gmail_progress_v1($pdo,$owner,$binding,[$finding])[$finding]['draft_state']==='unverified',
    'a sent status without the exact durable Chat send receipt cannot become a sending claim');
$sentContext=['source'=>'agent_connection_email','email_outcome'=>['draft_id'=>$draftId,'message_id'=>'native_sent_reply']];
$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(11,NULL,'assistant','Email reply sent.',?)")
    ->execute([json_encode($sentContext)]);
canvas_pipeline_check(vp3_curiosity_gmail_progress_v1($pdo,$owner,$binding,[$finding])[$finding]['draft_state']==='sent',
    'the exact canonical Chat send receipt supports sending progress while business completion remains unverified');
$pdo->prepare("UPDATE agent_connection_email_drafts_v1 SET status='prepared',sent_message_id=NULL WHERE id=?")->execute([$draftId]);
// Owner edits remain authoritative and do not authorize another generation.
$payload['body']='Please confirm delivery by December 4. Thank you.';
$pdo->prepare('UPDATE agent_connection_email_drafts_v1 SET message_enc=? WHERE id=?')->execute([ai_encrypt_secret(json_encode($payload)),$draftId]);
vp3_curiosity_gmail_result_current_v1($pdo,$owner,$binding,$goal,$result['initiative_tool_result']);
canvas_pipeline_check(agent_connection_email_chat_payload_v1(agent_connection_email_chat_draft_v1($pdo,1,7,11,$draftId))['body']===$payload['body']
    &&$modelCalls===1,'reading a completed result preserves the owner edit and never regenerates it');
$another=$goal;$another['focus']='Try repeating the same email preparation.';
vp3_initiative_enqueue_v1($pdo,$owner,'initiative.prepare',$another,hash('sha256','duplicate-curiosity-gmail-request'));
$runs=canvas_pipeline_runs('initiative.prepare');$duplicate=(int)end($runs)['id'];
$retry=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($duplicate),static function():string{throw new LogicException('A second draft inference is forbidden.');},['gmail_http'=>$docHttp],false);
canvas_pipeline_check(!$retry['ok']&&canvas_pipeline_count('agent_connection_email_drafts_v1')===$beforeDrafts+1
    &&agent_connection_email_chat_payload_v1(agent_connection_email_chat_draft_v1($pdo,1,7,11,$draftId))['body']===$payload['body'],
    'a duplicate planner assignment cannot charge another model call or overwrite an owner-edited draft');
$pdo->prepare("UPDATE agent_connection_email_drafts_v1 SET status='discarded',message_enc='' WHERE id=?")->execute([$draftId]);
$cancelled=vp3_curiosity_gmail_progress_v1($pdo,$owner,$binding,[$finding]);
canvas_pipeline_check($cancelled[$finding]['draft_state']==='discarded'
    &&!isset(vp3_initiative_tools_catalog_v1($pdo,$owner,$binding,$brain['evidence'])['gmail.prepare_reply']),
    'an erased, discarded draft remains closed through its canonical receipt and cannot be recreated');
try{vp3_initiative_specialist_reports_current_v1($pdo,$owner,$binding,['gmail'=>null,'research'=>[],'curiosity_reply_drafts'=>$progress]);throw new LogicException('Expected changed progress.');}
catch(Vp3InitiativeSourcesChangedV1 $expected){canvas_pipeline_check(true,'a changed native draft state fences an older supervisor assessment');}
$pdo->exec('UPDATE agent_connections_v1 SET read_enabled=0 WHERE id=88');
try{vp3_curiosity_gmail_result_current_v1($pdo,$owner,$binding,$goal,$result['initiative_tool_result']);throw new LogicException('Expected source withdrawal.');}
catch(Vp3InitiativeSourcesChangedV1 $expected){canvas_pipeline_check(true,'withdrawing Gmail access withdraws the derived draft result and review delivery');}
echo 'CURIOSITY_GMAIL_DRAFT=PASS ('.($checks-$threadChecks).' assertions, '.($mysql?'mysql':'sqlite').")\n";
