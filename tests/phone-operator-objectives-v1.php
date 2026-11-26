<?php
declare(strict_types=1);
require __DIR__.'/phone-browser-workspace-v1.php';
function ai_master_key(bool $create=false):?string{return str_repeat('k',32);}

function objective_fixture(?array $reuse=null,bool $agenda=true,bool $ambiguous=false):array
{
    $pdo=db();$n=($GLOBALS['objective_fixture_number']??0)+1;$GLOBALS['objective_fixture_number']=$n;$now=time();
    $pdo->exec("UPDATE users SET is_active=1,plan=1,denied=0 WHERE id=1");
    $pdo->exec("UPDATE phone_cloud_numbers_v100 SET owner_user_id=1,agent_id=7,voice_enabled=1,status='ready' WHERE id=1");
    vp3_phone_browser_number_policy_set_v1($pdo,1,true,true);
    if(!$reuse){
        $peer='+1602555'.str_pad((string)(7000+$n),4,'0',STR_PAD_LEFT);
        $insert=$pdo->prepare("INSERT INTO crm_contacts(public_id,owner_user_id,name,email,email_normalized,phone,status,created_at,updated_at) VALUES(?,1,'Objective contact','',?,?,'active',?,?)");
        $insert->execute([bin2hex(random_bytes(16)),'objective'.$n.'@example.test',$peer,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')]);$contact=(int)$pdo->lastInsertId();
        if($agenda)action('save_operator_agenda',['contact_id'=>$contact,'number_id'=>1,'expected_revision'=>0,'enabled'=>false,'review_missed'=>false,'review_account'=>false,
            'topics'=>'Call planning','questions'=>"Which day works?\nWho will attend?"]);
        if($ambiguous){
            for($aliasIndex=0;$aliasIndex<3;$aliasIndex++){
                $insert->execute([bin2hex(random_bytes(16)),'objective-alias'.$n.'-'.$aliasIndex.'@example.test',$peer,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')]);$alias=(int)$pdo->lastInsertId();
                action('save_operator_agenda',['contact_id'=>$alias,'number_id'=>1,'expected_revision'=>0,'enabled'=>false,'review_missed'=>false,'review_account'=>false,'topics'=>'Other agenda','questions'=>'Which other day?']);
                if($aliasIndex<2)$pdo->prepare("UPDATE crm_contacts SET phone='+16025559988' WHERE id=?")->execute([$alias]);
            }
        }
    }else{$contact=$reuse['contact'];$peer=$reuse['peer'];}
    $public=bin2hex(random_bytes(16));$parent='CA'.bin2hex(random_bytes(16));$child='CA'.bin2hex(random_bytes(16));$intent=bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO phone_browser_calls_v1(public_id,owner_user_id,agent_id,phone_number_id,phone_e164,peer_e164,direction,intent_hash,intent_expires_epoch,conversation_id,chat_agent_id,state,recording_enabled,created_epoch,updated_epoch)
        VALUES(?,1,7,1,'+16025550101',?,'outbound',?,?,11,0,'prepared',1,?,?)")
        ->execute([$public,$peer,hash('sha256',$intent),$now+120,$now-20,$now-20]);$id=(int)$pdo->lastInsertId();
    $voice=form($parent,['From'=>'client:'.vp3_phone_browser_identity_v1(1,0),'call_intent'=>$intent]);
    $xml=vp3_phone_browser_voice_v1($pdo,$voice,signature('voice',$voice));
    c(str_contains($xml,'<Dial')&&readcall($public)['provider_call_sid']===$parent,'real signed outbound acceptance binds the intended call');
    foreach(['in-progress','completed'] as $state){$f=form($child,['ParentCallSid'=>$parent,'CallStatus'=>$state,'CallDuration'=>'20']);c(vp3_phone_browser_status_v1($pdo,$f,signature('status',$f,$public),$public)['accepted'],'signed destination lifecycle remains intact');}
    $call=readcall($public);$hash=hash('sha256','objective-transcript:'.$public);$session=9000+$n;
    $policy=['enabled'=>true,'retain_audio'=>true,'transcribe'=>true,'analyze'=>true,'modules'=>['qa'],'revision'=>1,'model_route'=>'account_cloud'];
    $pdo->prepare("INSERT INTO phone_recording_jobs_v1(public_id,owner_user_id,agent_id,phone_number_id,origin_kind,call_id,call_public_id,provider_call_sid,provider_account_sid,provider_recording_sid,phone_e164,peer_e164,duration_seconds,state,stage,policy_json,session_id,transcript_hash,analysis_hash,conversation_id,created_epoch,updated_epoch)
        VALUES(?,1,7,1,'browser',?,?,?,?,?,'+16025550101',?,20,'ready','ready',?,?,?,?,11,?,?)")
        ->execute([bin2hex(random_bytes(16)),$id,$public,$parent,setting('phone_twilio_account_sid'),'RE'.bin2hex(random_bytes(16)),$peer,json_encode($policy,JSON_THROW_ON_ERROR),$session,$hash,hash('sha256','analysis:'.$public),$now,$now]);
    $jobId=(int)$pdo->lastInsertId();$job=vp3_phone_recording_row_v1($pdo,$jobId);
    $meta=['source'=>'phone_call_v1','phone_recording_v1'=>['job_id'=>$jobId,'call_kind'=>'browser','call_id'=>$public,'agent_id'=>7]];
    $pdo->prepare('INSERT INTO artist_transcript_sessions_v172(id,created_by_user_id,metadata_json,discarded_at) VALUES(?,1,?,NULL)')->execute([$session,json_encode($meta)]);
    $pdo->prepare("INSERT INTO artist_transcript_segments_v172(id,session_id,segment_type,transcript_text) VALUES(?,?,'transcript','Private raw call transcript fixture')")->execute([9000+$n,$session]);
    $delivery=vp3_phone_workspace_delivery_v1($pdo,$call,'voicemail','phone-recording-report:'.$job['public_id'],'Saved call report',
        ['voicemail_id'=>'job-'.$jobId,'report'=>['text'=>'Saved call report','review_state'=>'unreviewed','source_hash'=>$hash]]);
    $pdo->prepare('UPDATE phone_recording_jobs_v1 SET report_message_id=? WHERE id=?')->execute([$delivery['message_id'],$jobId]);$job=vp3_phone_recording_row_v1($pdo,$jobId);
    $scope=vp3_phone_workspace_scope_v1($pdo,user(),['agent_id'=>0,'conversation_id'=>11]);
    $saved=['session_id'=>$session,'source_hash'=>$hash,'fresh'=>true,'complete'=>true,'page_count'=>1,'qa_items'=>[
        ['section'=>'answered','item_id'=>'qa-answer','question'=>'Which day works?','answer'=>'Friday works.','review_state'=>'unreviewed','evidence_refs'=>[['page'=>1]]],
        ['section'=>'unanswered','item_id'=>'qa-open','question'=>'Who will attend?','review_state'=>'accepted','evidence_refs'=>[['page'=>1]]]]];
    return compact('public','id','contact','peer','call','job','scope','saved','now');
}
function objective_review(array $ctx,?array $saved=null,?callable $reader=null):array
{
    $saved??=$ctx['saved'];$reader??=static fn(int $id):array=>$saved;
    return vp3_operator_objectives_review_v1(db(),$ctx['scope'],$ctx['public'],time(),$reader);
}

$before=$checks;$ctx=objective_fixture();
$row=vp3_operator_objectives_row_v1($pdo,$ctx['id']);
c($row['source_state']==='bound'&&(int)$row['contact_id']===$ctx['contact'],'saved contact agenda is referenced at real carrier acceptance');
c(!str_contains(json_encode($row),'Which day')&&!str_contains(json_encode($row),'Friday')&&!str_contains(json_encode($row),$ctx['peer']),'new metadata stores no questions, answers, transcript or phone number');
vp3_operator_objectives_install_v1($pdo);vp3_operator_objectives_install_v1($pdo);
c(vp3_operator_objectives_row_v1($pdo,$ctx['id'])===$row,'repeated upgrade preserves the frozen reference');
$review=objective_review($ctx);
c($review['coverage']==='ready'&&array_column($review['questions'],'state')===['answer_suggested','open'],'current exact Q&A keeps generated answers distinct from reviewed answers and open questions');
c(str_contains($review['questions'][0]['source_url'],'plugin=qa')&&!str_contains(json_encode($review),'Private raw call transcript fixture'),'findings link their canonical items without returning raw speech');
$accepted=$ctx['saved'];$accepted['qa_items'][0]['review_state']='accepted';
c(objective_review($ctx,$accepted)['questions'][0]['state']==='answer_suggested','a generated accepted flag without server review proof cannot settle the question');
$accepted['qa_items'][0]['reviewed_at']=gmdate('c');
$accepted['qa_items'][0]['owner_review_proof_v1']=transcription_review_proof_v1((int)$ctx['job']['session_id'],$accepted['source_hash'],'qa','answered',$accepted['qa_items'][0]);
c(objective_review($ctx,$accepted)['questions'][0]['state']==='answer_reviewed','owner review is required for the reviewed-answer label');
$partial=$accepted;$partial['complete']=false;$r=objective_review($ctx,$partial);
c($r['coverage']==='partial'&&array_column($r['questions'],'state')===['unverified','unverified'],'partial coverage cannot settle either saved question');
$stale=$accepted;$stale['source_hash']=str_repeat('0',64);
c(objective_review($ctx,$stale)['questions']===[],'a changed transcript hash withholds all earlier answers');
$bad=$accepted;$bad['qa_items'][0]['evidence_refs']=[['page'=>999]];
c(objective_review($ctx,$bad)['questions'][0]['state']==='unverified','an accepted answer without a current page locator cannot settle the question');
$bad=$accepted;$bad['qa_items'][]=array_replace($bad['qa_items'][0],['item_id'=>'conflicting-answer','answer'=>'Saturday works.']);
c(objective_review($ctx,$bad)['questions'][0]['state']==='conflicting','two matching answers are a conflict rather than an arbitrary first result');
$bad=$accepted;$bad['qa_items'][0]['question']='Which day works for delivery?';
c(objective_review($ctx,$bad)['questions'][0]['state']==='unverified','a similar but different question is never silently matched');
$countBefore=(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();
objective_review($ctx);objective_review($ctx);
c((int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$countBefore,'reopening review never sends a second report');
denies(fn()=>vp3_operator_objectives_review_v1($pdo,vp3_phone_workspace_scope_v1($pdo,user(2),['agent_id'=>0,'conversation_id'=>21]),$ctx['public'],time()),404,'another owner cannot review the call');
denies(fn()=>vp3_operator_objectives_review_v1($pdo,vp3_phone_workspace_scope_v1($pdo,user(),['agent_id'=>7,'conversation_id'=>12]),$ctx['public'],time()),404,'another private Chat cannot borrow call questions');
denies(fn()=>action('operator_objectives_review',['call_id'=>$ctx['public']]),405,'the review route cannot be used as a POST mutation');

$changed=objective_fixture();$changedReader=static function(int $id)use($pdo,$changed):array{
    $pdo->prepare("UPDATE phone_operator_agendas_v1 SET questions='A later question',revision=revision+1 WHERE owner_user_id=1 AND contact_id=?")->execute([$changed['contact']]);return $changed['saved'];
};
$r=objective_review($changed,null,$changedReader);c($r['coverage']==='agenda_changed'&&$r['questions']===[],'an agenda edit during evidence reading cannot release the previous answer');
vp3_phone_workspace_delivery_v1($pdo,readcall($changed['public']),'call','call:'.$changed['public'],'Duplicate call receipt');
c(vp3_operator_objectives_row_v1($pdo,$changed['id'])['agenda_hash']!==vp3_operator_objectives_hash_v1(vp3_operator_agenda_row_v1($pdo,[1,$changed['contact'],11,0])),'a replay cannot replace the frozen agenda with a later revision');
$removed=objective_fixture();$r=objective_review($removed,null,static function(int $id)use($pdo,$removed):array{
    $pdo->prepare('DELETE FROM phone_browser_deliveries_v1 WHERE chat_message_id=?')->execute([(int)$removed['job']['report_message_id']]);return $removed['saved'];
});c($r['questions']===[],'removed original delivery fences cached Q&A findings');
$unrecorded=objective_fixture();$r=objective_review($unrecorded,null,static function(int $id)use($pdo,$unrecorded):array{
    $pdo->prepare('UPDATE phone_browser_calls_v1 SET recording_enabled=0 WHERE id=?')->execute([$unrecorded['id']]);return $unrecorded['saved'];
});c($r['questions']===[],'withdrawing the recorded-call binding during reading fences cached answers');
$moved=objective_fixture();denies(fn()=>objective_review($moved,null,static function(int $id)use($pdo,$moved):array{
    $pdo->prepare('UPDATE phone_browser_calls_v1 SET conversation_id=12,chat_agent_id=7 WHERE id=?')->execute([$moved['id']]);return $moved['saved'];
}),404,'reassigning a call during reading cannot move findings across Chats');
$disabled=objective_fixture();denies(fn()=>objective_review($disabled,null,static function(int $id)use($pdo,$disabled):array{$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');return $disabled['saved'];}),403,'account deactivation is rechecked after reading');
$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');

$missing=objective_fixture(null,false);c(objective_review($missing)['coverage']==='none','a call with no starting agenda is recorded as such');
action('save_operator_agenda',['contact_id'=>$missing['contact'],'number_id'=>1,'expected_revision'=>0,'enabled'=>false,'review_missed'=>false,'review_account'=>false,'topics'=>'Later','questions'=>'Which day works?']);
c(objective_review($missing)['coverage']==='none','adding an agenda after the call cannot fabricate an earlier objective');
$ambiguous=objective_fixture(null,true,true);c(objective_review($ambiguous)['coverage']==='ambiguous','a second matching contact beyond stale candidates cannot be hidden by a short first-page query');
$off=objective_fixture();$policy=json_decode($off['job']['policy_json'],true);$policy['modules']=['basic'];
$pdo->prepare('UPDATE phone_recording_jobs_v1 SET policy_json=? WHERE id=?')->execute([json_encode($policy),$off['job']['id']]);$calls=0;
$r=objective_review($off,null,static function(int $id)use(&$calls,$off):array{$calls++;return $off['saved'];});
c($r['coverage']==='analysis_off'&&$calls===0,'unconsented Q&A is rejected before reading any analysis');
$get=api('GET',['action'=>'operator_objectives_review','call_id'=>$off['public'],'agent_id'=>0,'conversation_id'=>11]);
c($get['review']['coverage']==='analysis_off'&&$get['review']['execution_enabled']===false,'the real GET route exposes only the scoped read-only review');

$queue=objective_fixture();$followScope=$queue['scope']+['principal'=>['kind'=>'system','viewer_user_id'=>1]];
$legacy=['session_id'=>(int)$queue['job']['session_id'],'source_hash'=>$queue['job']['transcript_hash'],'fresh'=>true,'page_count'=>1,'research_candidates'=>[
    ['session_id'=>(int)$queue['job']['session_id'],'source_hash'=>$queue['job']['transcript_hash'],'kind'=>'followup','plugin'=>'qa','section'=>'unanswered','item_id'=>'qa-open',
        'item_revision'=>str_repeat('a',64),'review_state'=>'accepted','question'=>'Who will attend?','next_step'=>'Ask who will attend.','source_url'=>'/artist-listening-evidence.php?session=1']]];
$legacyRead=static fn(int $id):array=>$legacy;$qaRead=static fn(int $id):array=>$queue['saved'];$refs=['followup:'.$queue['job']['public_id']];
$items=vp3_operator_followup_queue_v1($pdo,$followScope,new DateTimeImmutable('now'),$refs,$legacyRead,$qaRead)['reports'][0]['items'];
c(count($items)===2&&count(array_filter($items,static fn($i)=>$i['text']==='Who will attend?'))===1,'existing follow-up queue receives question progress without duplicating native Q&A items');
if(!function_exists('vp3_coordination_source_item_v1')){function vp3_coordination_source_item_v1(...$args):array{return ['source_arguments'=>$args];}}
$sources=vp3_coordination_operator_sources_v1($pdo,$followScope,$refs,new DateTimeImmutable('now'),$legacyRead,$qaRead);
c(count($sources['items'])===1&&!str_contains(json_encode($sources),'Which day works')&&!str_contains(json_encode($sources),'Friday')&&array_values($sources['items'])[0]['history_only']===true,'Top of Mind receives an existing deduplicated count and revision without private question/answer text or another announcement');

$prior=objective_fixture();$current=objective_fixture($prior);$device=bin2hex(random_bytes(16));$profile=bin2hex(random_bytes(16));
$pdo->prepare("INSERT INTO vp3_phone_devices_v1(id,owner_user_id,device_status,expires_at) VALUES(?,1,'approved',?)")->execute([$device,time()+3600]);
$pdo->prepare("INSERT INTO vp3_phone_communication_profiles_v1(owner_user_id,device_id,profile_id,revision,phone_revision,phone_e164,owner_calls,holder_calls,holder_memory,updated_epoch) VALUES(1,?,?,1,1,?,1,1,1,?)")
    ->execute([$device,$profile,$prior['peer'],time()]);
$pdo->prepare('UPDATE crm_contacts SET connected_phone_id=? WHERE id=?')->execute([$device,$prior['contact']]);
$priorSaved=$prior['saved'];$priorSaved['qa_items'][0]['section']='unanswered';unset($priorSaved['qa_items'][0]['answer']);
$readBoth=static fn(int $id):array=>$id===(int)$prior['job']['session_id']?$priorSaved:$current['saved'];
$r=objective_review($current,null,$readBoth);c($r['continuity']['state']==='available'&&count($r['continuity']['questions'])===2,'same-agenda open questions carry forward only under current recipient memory permission');
$currentAccepted=$current['saved'];$currentAccepted['qa_items'][0]['review_state']='accepted';
$currentAccepted['qa_items'][0]['reviewed_at']=gmdate('c');
$currentAccepted['qa_items'][0]['owner_review_proof_v1']=transcription_review_proof_v1((int)$current['job']['session_id'],$currentAccepted['source_hash'],'qa','answered',$currentAccepted['qa_items'][0]);
$r=objective_review($current,null,static fn(int $id):array=>$id===(int)$prior['job']['session_id']?$priorSaved:$currentAccepted);
c(!in_array('Which day works?',array_column($r['continuity']['questions'],'question'),true),'a reviewed current answer cannot reopen an older copy of that question');
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET holder_memory=0 WHERE device_id=?')->execute([$device]);
c(objective_review($current,null,$readBoth)['continuity']['questions']===[],'withdrawing recipient memory permission removes cross-call findings');
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET holder_memory=1 WHERE device_id=?')->execute([$device]);
$r=objective_review($current,null,static function(int $id)use($pdo,$device,$prior,$priorSaved,$current):array{
    if($id===(int)$prior['job']['session_id']){$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET holder_memory=0 WHERE device_id=?')->execute([$device]);return $priorSaved;}return $current['saved'];
});c($r['continuity']['questions']===[],'memory withdrawal during an earlier-call read fences its late result');
echo 'OPERATOR_CALL_OBJECTIVES=PASS ('.($checks-$before).' additional checks)'.PHP_EOL;
