<?php
declare(strict_types=1);
require __DIR__.'/transcript-agent-intelligence-v1.php';
require_once dirname(__DIR__).'/includes/agent-operator-objectives-v1.php';
function ai_master_key(bool $create=false):?string{if($create)throw new LogicException('A review must not create a credential key.');return empty($GLOBALS['objective_key_unavailable'])?str_repeat('k',32):null;}
$before=$checks;$GLOBALS['denied']=[];$pdo->exec('UPDATE users SET is_active=1');
$pdo->exec("UPDATE artist_transcript_sessions_v172 SET status='draft' WHERE id=1");
$pdo->exec("UPDATE artist_transcript_segments_v172 SET transcript_text='Which day works? Friday works for the call.' WHERE id=1");
$map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,1));$hash=$map['source_hash'];$date=gmdate('c');
$qa=['answered'=>[['item_id'=>'call-answer','question'=>'Which day works?','answer'=>'Friday works for the call.',
    'review_state'=>'accepted','evidence'=>'Page 1','evidence_refs'=>[['page'=>1]]]],'unanswered'=>[]];
$modules=['qa'=>['source_hash'=>$hash,'source_word_count'=>$map['total_words'],'generated_at'=>$date,'result'=>$qa]];
$pdo->exec('DELETE FROM artist_transcript_page_analysis_v237 WHERE session_id=1');
foreach($map['pages'] as $index=>$page)$pdo->prepare("INSERT INTO artist_transcript_page_analysis_v237 VALUES(?,?,?,?,?,?,?,'fixture','fixture',?)")
    ->execute([1,$index+1,$page['source_hash'],$page['word_count']??1,0,0,'{}',$date]);
$pdo->prepare('UPDATE artist_transcript_master_analysis_v237 SET source_hash=?,source_word_count=?,page_count=?,analyzed_page_count=?,analysis_json=? WHERE session_id=1')
    ->execute([$hash,$map['total_words'],$map['page_count'],$map['page_count'],json_encode(['modules'=>$modules],JSON_THROW_ON_ERROR)]);
$job=['session_id'=>1,'conversation_id'=>10];$questions=vp3_operator_objectives_questions_v1("Which day works?\nWhich date is delivery?");
$saved=vp3_operator_objectives_analysis_v1($pdo,$u,$job);
ck($saved['fresh']&&$saved['complete']&&count($saved['qa_items'])===1,'call review reads real current canonical Q&A and complete page coverage');
$progress=vp3_operator_objectives_progress_v1($questions,$saved,1);
ck($progress[0]['state']==='answer_suggested','a model-supplied accepted flag is not owner approval');
$master=artist_listening_v237_analysis_status($pdo,1,$map)['master'];
transcription_deeper_review_item_v307($pdo,1,$master,'qa','call-answer','accepted');
$saved=vp3_operator_objectives_analysis_v1($pdo,$u,$job);$progress=vp3_operator_objectives_progress_v1($questions,$saved,1);
ck($progress[0]['state']==='answer_reviewed'&&$progress[0]['answer']==='Friday works for the call.'&&$progress[1]['state']==='unverified','only the exact owner-reviewed Q&A answer advances its saved question');
ck(str_contains($progress[0]['source_url'],'plugin=qa')&&str_contains($progress[0]['source_url'],'item=call-answer'),'call progress links the original canonical Q&A item');
$tampered=$saved;$tampered['qa_items'][0]['answer']='A different answer';
ck(vp3_operator_objectives_progress_v1($questions,$tampered,1)[0]['state']==='answer_suggested','editing an answer cannot reuse an earlier owner review proof');
$tampered=$saved;$tampered['source_hash']=str_repeat('b',64);
ck(vp3_operator_objectives_progress_v1($questions,$tampered,1)[0]['state']==='answer_suggested','an earlier transcript review proof cannot approve changed source evidence');
$GLOBALS['objective_key_unavailable']=true;
ck(vp3_operator_objectives_progress_v1($questions,$saved,1)[0]['state']==='answer_suggested','an unavailable private key does not manufacture review authority or create a replacement key');
$GLOBALS['objective_key_unavailable']=false;
foreach(['accepted','rejected','unreviewed'] as $state){
    $master=artist_listening_v237_analysis_status($pdo,1,$map)['master'];
    transcription_intelligence_review_item_v302($pdo,1,$master,'qa','call-answer',$state);
    $current=vp3_operator_objectives_analysis_v1($pdo,$u,$job);
    $item=$current['qa_items'][0];
    ck(transcription_review_proven_v1(1,$hash,'qa','answered',$item)===($state==='accepted'),'canonical v302 '.$state.' state has only its exact owner review authority');
}
$master=artist_listening_v237_analysis_status($pdo,1,$map)['master'];
transcription_deeper_edit_item_v307($pdo,1,$master,'qa','call-answer','Which day works?');
$current=vp3_operator_objectives_analysis_v1($pdo,$u,$job);
ck(transcription_review_proven_v1(1,$hash,'qa','answered',$current['qa_items'][0]),'the existing deeper Edit action supplies a bound owner review');
$master=artist_listening_v237_analysis_status($pdo,1,$map)['master'];
transcription_intelligence_edit_item_v302($pdo,1,$master,'qa','call-answer','Which day works?');
$current=vp3_operator_objectives_analysis_v1($pdo,$u,$job);
ck(transcription_review_proven_v1(1,$hash,'qa','answered',$current['qa_items'][0]),'the existing v302 Edit action supplies a bound owner review');
$pdo->exec('DELETE FROM artist_transcript_page_analysis_v237 WHERE session_id=1 AND page_number=1');
$partial=vp3_operator_objectives_analysis_v1($pdo,$u,$job);
ck(!$partial['complete']&&vp3_operator_objectives_progress_v1($questions,$partial,1)[0]['state']==='unverified','partial canonical page coverage cannot imply a verified answer');
$modules['qa']['source_hash']=str_repeat('0',64);$pdo->prepare('UPDATE artist_transcript_master_analysis_v237 SET analysis_json=? WHERE session_id=1')->execute([json_encode(['modules'=>$modules])]);
ck(!vp3_operator_objectives_analysis_v1($pdo,$u,$job)['fresh'],'stale module evidence is distinct from a current master transcript hash');
$pdo->exec("UPDATE artist_transcript_segments_v172 SET transcript_text='The transcript changed after analysis.' WHERE id=1");
ck(!vp3_operator_objectives_analysis_v1($pdo,$u,$job)['fresh'],'editing the canonical transcript invalidates the old answer');
try{vp3_operator_objectives_analysis_v1($pdo,$u,['session_id'=>1,'conversation_id'=>11]);throw new LogicException('Wrong Chat read succeeded.');}
catch(RuntimeException $e){ck($e->getCode()===404,'a different private Chat cannot borrow the analyzed transcript');}
$pdo->exec("UPDATE artist_transcript_sessions_v172 SET status='discarded' WHERE id=1");
try{vp3_operator_objectives_analysis_v1($pdo,$u,$job);throw new LogicException('Discarded transcript read succeeded.');}
catch(RuntimeException $e){ck($e->getCode()===404,'the canonical reader withholds discarded recordings');}
echo 'OPERATOR_OBJECTIVES_CANONICAL_READER=PASS ('.($checks-$before).' additional checks)'.PHP_EOL;
