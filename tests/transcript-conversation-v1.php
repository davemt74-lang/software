<?php
declare(strict_types=1);
require __DIR__.'/transcript-agent-v1.php';
$pdo->exec('UPDATE users SET is_active=1');$before=$checks;
function conversation_model(array $plan,?callable $inspect=null): callable{return static function($prompt)use($plan,$inspect){
 if(str_starts_with($prompt,'VP3_TRANSCRIPT_ANSWER_V1')){$data=json_decode(substr($prompt,strpos($prompt,"\n")+1),true);if($inspect)$inspect($data);$segments=$data['evidence']['segments'];return ['ok'=>true,'answer'=>json_encode(['answer'=>'The discussion covers pricing and the next steps.','citations'=>[(int)$segments[0]['id']]])];}
 return ['ok'=>true,'answer'=>json_encode($plan)];
};}
$call=static fn($plan,$inspect=null,$context=[])=>vp3_transcript_chat_v1($pdo,$u,$p,'Summarize the transcription titled Pricing',$context,[],conversation_model($plan,$inspect));
$r=$call(['operation'=>'summarize','title'=>'Pricing'],static function($data){ck($data['evidence']['complete']&&count($data['evidence']['segments'])===3,'summary receives all pages, including the final segment');});
ck(str_contains($r['answer'],'discussion covers')&&!str_contains($r['answer'],'Your authorized transcripts')&&$r['tool_receipt']['record_id']===1,'unique title resolves and summarizes in the same request');
ck($r['transcript_context']['session_id']===1&&strlen($r['transcript_context']['source_hash'])===64&&!$r['tool_receipt']['changes_applied'],'summary attaches a server source reference without writing Brain facts');
$pdo->exec("INSERT INTO artist_transcript_sessions_v172 VALUES(6,1,10,'Pricing review','draft','2026-10-30 15:00:00','','{}')");
$r=$call(['operation'=>'summarize','title'=>'Pricing']);ck($r['tool_receipt']['status']==='clarification_required'&&$r['transcript_context']['state']==='choosing','ambiguous title asks which recording instead of selecting latest');
$r=$call(['operation'=>'summarize','title'=>'Pricing call']);ck($r['tool_receipt']['record_id']===1,'an exact title wins over other partial title matches');
$r=vp3_transcript_chat_v1($pdo,$u,$p,'Can you analyze this transcript?',[],[],conversation_model(['operation'=>'run_analysis','session_id'=>1]),null,true);ck(!$r['handled'],'capability arbitration cannot run analysis or a write');
$map=artist_listening_transcript_page_map([['id'=>11,'segment_index'=>0,'segment_type'=>'transcript','speaker_label'=>'Alice','transcript_text'=>str_repeat('Budget planning. ',4000),'started_ms'=>0,'ended_ms'=>1000],['id'=>12,'segment_index'=>1,'segment_type'=>'transcript','speaker_label'=>'Bob','transcript_text'=>'The termination penalty is fifty dollars.','started_ms'=>2000,'ended_ms'=>3000]]);
$a=vp3_transcript_evidence_v1($map,'summarize','',0);$b=vp3_transcript_evidence_v1($map,'summarize','',$a['next_offset']);
ck(!$a['complete']&&$a['covered_characters']===48000&&$a['covered_characters']+$b['covered_characters']===$a['total_characters'],'long source coverage is bounded and continuation covers the remainder without a gap');
$q=vp3_transcript_evidence_v1($map,'discuss','What is the termination penalty?');ck(in_array(12,array_column($q['segments'],'id'),true),'question retrieval can reach evidence at the end of a long recording');
$r=vp3_transcript_chat_v1($pdo,$u,$p,'Summarize this transcript',['transcript_session_id'=>1],[],static fn($prompt)=>str_starts_with($prompt,'VP3_TRANSCRIPT_ANSWER_V1')?['ok'=>true,'answer'=>'{"answer":"Unverified output","citations":[999]}']:['ok'=>true,'answer'=>'{"operation":"summarize"}']);
ck($r['tool_receipt']['status']==='failed'&&!str_contains($r['answer'],'Unverified output'),'an invented citation prevents the proposed answer from being returned');
$r=$call(['operation'=>'summarize','session_id'=>1],static function(){$GLOBALS['denied']['artist_listening.access']=true;});ck($r['tool_receipt']['status']==='failed'&&!str_contains($r['answer'],'discussion covers'),'permission revocation during generation prevents the answer');unset($GLOBALS['denied']['artist_listening.access']);
$r=$call(['operation'=>'summarize','session_id'=>1],static function()use($pdo){$pdo->exec("UPDATE artist_transcript_segments_v172 SET transcript_text='Edited during inference' WHERE id=1");});ck($r['tool_receipt']['status']==='failed'&&!str_contains($r['answer'],'discussion covers'),'editing the source during generation invalidates the proposed answer');
$pdo->exec('CREATE TABLE chat_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,role TEXT,context_json TEXT)');
$pin=vp3_transcript_attachment_v1(1,'sourcehash');$save=$pdo->prepare("INSERT INTO chat_messages(conversation_id,role,context_json) VALUES(10,'assistant',?)");$save->execute([json_encode(['transcript_context'=>$pin])]);$save->execute(['{}']);
ck(vp3_transcript_context_v1($pdo,$u,$p,['conversation_id'=>10])['session_id']===1,'conversation source survives unrelated turns without relying on the last tool receipt');
ck(vp3_transcript_context_v1($pdo,$u,['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>8],['conversation_id'=>10])===null,'another Agent cannot load the conversation source reference');
$pdo->exec("INSERT INTO artist_transcript_segments_v172 VALUES(4,4,'unlinked-a',0,'transcript','Dave','The outreach draft is ready for review on Thursday.',1000,2000,.9,'','2026-10-30')");
$focused=['conversation_id'=>10,'transcript_session_id'=>4,'transcript_source_selected'=>true];
$routing=vp3_transcript_model_source_v1($pdo,$u,$p,$focused+['transcript_selected_text'=>'unverified sidebar text']);
ck($routing===['state'=>'active','session_id'=>4,'title'=>'Unlinked recording','source'=>'selected_recording'],'current source routing uses authorized canonical metadata without browser prose');
$r=vp3_transcript_chat_v1($pdo,$u,$p,'What do you think?',$focused,[],static function($prompt){
    $data=json_decode(substr($prompt,strpos($prompt,"\n")+1),true);
    ck(str_starts_with($prompt,'VP3_TRANSCRIPT_ANSWER_V1')&&$data['task']==='discuss'
        &&$data['title']==='Unlinked recording'&&array_column($data['evidence']['segments'],'id')===[4],
        'a natural question reaches the exact selected recording instead of an older attachment');
    return ['ok'=>true,'answer'=>'{"answer":"Review the outreach draft Thursday.","citations":[4]}'];
});
ck($r['transcript_context']['session_id']===4&&!$r['tool_receipt']['changes_applied']&&str_contains($r['answer'],'segment #4'),'discussion is cited and read only, with the source retained in this Chat');
$r=vp3_transcript_chat_v1($pdo,$u,$p,'Any suggestions?',$focused,[],conversation_model(['operation'=>'run_analysis']));
ck($r['tool_receipt']['capability']==='transcripts.read'&&$r['tool_receipt']['record_id']===4,'the source remains available when its selection is supplied without an open slideout');
$cleared=$focused+['transcript_source_cleared'=>true];
$notCalled=static fn()=>throw new LogicException('An unavailable source must not reach inference.');
$r=vp3_transcript_chat_v1($pdo,$u,$p,'What do you think?',$cleared,[],$notCalled);
ck(!$r['handled']&&vp3_transcript_model_source_v1($pdo,$u,$p,$cleared)===['state'=>'detached','session_id'=>0],'explicit Clear defeats both a stale browser selection and an older Chat attachment');
$save->execute([json_encode(['agent_context'=>['transcript_source_cleared'=>true]])]);
$r=vp3_transcript_chat_v1($pdo,$u,$p,'Any suggestions?',['conversation_id'=>10],[],$notCalled);
ck(!$r['handled'],'the saved Clear marker keeps the old source detached on a later turn');
$r=vp3_transcript_chat_v1($pdo,$u,$p,'Summarize this transcript',$cleared,[],conversation_model(['operation'=>'summarize','session_id'=>1],$notCalled));
ck($r['tool_receipt']['status']==='clarification_required','the interpreter cannot revive the old recording from an unqualified request after Clear');
$r=vp3_transcript_chat_v1($pdo,$u,$p,'What stands out?',$focused,[],conversation_model(['operation'=>'discuss']));
ck($r['tool_receipt']['record_id']===4,'a deliberate new selection works after an earlier Clear');
foreach([2,3,5] as $unavailable){
    $other=array_replace($focused,['transcript_session_id'=>$unavailable]);
    ck(vp3_transcript_model_source_v1($pdo,$u,$p,$other)===['state'=>'unavailable','session_id'=>0],'unavailable selection never becomes model source metadata');
    $r=vp3_transcript_chat_v1($pdo,$u,$p,'What do you think?',$other,[],$notCalled);
    ck($r['tool_receipt']['status']==='failed'&&!$r['sources'],'unavailable source blocks discussion without falling back to an older recording');
}
$restricted=$pdo->prepare('UPDATE artist_transcript_sessions_v172 SET metadata_json=? WHERE id=4');
$restricted->execute([json_encode(['capture_mode'=>'recording_library','video_meeting_id'=>0])]);
ck(vp3_transcript_model_source_v1($pdo,$u,$p,$focused)===['state'=>'unavailable','session_id'=>0],'retained meeting policy blocks the selected title before capability routing');
$r=vp3_transcript_chat_v1($pdo,$u,$p,'Summarize this transcript',$focused,[],$notCalled);
ck($r['tool_receipt']['status']==='failed'&&!str_contains($r['answer'],'Unlinked recording'),'Cloud policy blocks selected source metadata before transcript interpretation');
$restricted->execute(['{}']);
$r=vp3_transcript_chat_v1($pdo,$u,$p,'What do you think?',$focused,[],conversation_model(['operation'=>'discuss'],static function()use($restricted){
    $restricted->execute([json_encode(['capture_mode'=>'recording_library','video_meeting_id'=>0])]);
}));
ck($r['tool_receipt']['status']==='failed'&&!str_contains($r['answer'],'discussion covers'),'processing policy is checked again after inference');
$restricted->execute(['{}']);
$save->execute([json_encode(['transcript_context'=>vp3_transcript_attachment_v1(0,'',null,'detached')])]);ck(vp3_transcript_context_v1($pdo,$u,$p,['conversation_id'=>10])['state']==='detached','detach marker prevents an older source reference from returning');
$r=$call(['operation'=>'summarize','title'=>'%']);ck($r['tool_receipt']['status']==='no_matches','title selectors treat percent literally rather than as a wildcard');
$r=vp3_transcript_chat_v1($pdo,$u,$p,'Tell me about this transcription',['conversation_id'=>10,'transcript_session_id'=>1],[],static function($prompt){$data=json_decode(substr($prompt,strpos($prompt,"\n")+1),true);ck($data['selected_session_id']===0,'a detached source does not revive from stale UI selection');return ['ok'=>true,'answer'=>'{"operation":"clarify","question":"Which transcript should I use?"}'];});
$pdo->exec("UPDATE artist_transcript_segments_v172 SET transcript_text='".str_repeat('Large source paragraph. ',5000)."' WHERE id=2");
$r=$call(['operation'=>'summarize','session_id'=>1]);ck(str_contains($r['answer'],'not the complete recording')&&$r['transcript_context']['next_offset']===48000,'large summaries disclose partial coverage and store an exact continuation cursor');
$save->execute([json_encode(['transcript_context'=>$r['transcript_context']])]);
$r=vp3_transcript_chat_v1($pdo,$u,$p,'What did they say about pricing?',['conversation_id'=>10],[],conversation_model(['operation'=>'discuss']));ck($r['transcript_context']['next_offset']===48000&&!$r['transcript_context']['summary_complete'],'an intervening question preserves incomplete summary progress');
$save->execute([json_encode(['transcript_context'=>$r['transcript_context']])]);
$r=vp3_transcript_chat_v1($pdo,$u,$p,'Continue the transcript summary',['conversation_id'=>10],[],conversation_model(['operation'=>'continue'],static function($data){ck($data['evidence']['offset']===48000,'continuation reads the next source range rather than repeating the beginning');}));
ck($r['transcript_context']['next_offset']===96000,'continuation advances the server source cursor');

$pdo->exec('CREATE TABLE artist_transcript_page_analysis_v237(session_id INTEGER,page_number INTEGER,source_hash TEXT,source_word_count INTEGER,start_segment_index INTEGER,end_segment_index INTEGER,analysis_json TEXT,provider TEXT,model TEXT,generated_at TEXT,PRIMARY KEY(session_id,page_number));
CREATE TABLE artist_transcript_master_analysis_v237(session_id INTEGER PRIMARY KEY,source_hash TEXT,source_word_count INTEGER,page_count INTEGER,analyzed_page_count INTEGER,analysis_json TEXT,research_json TEXT,provider TEXT,model TEXT,generated_at TEXT)');
$selectedMap = artist_listening_transcript_page_map(artist_listening_v172_segments($pdo, 4));
$generatedAt = gmdate('c');
$module = ['source_hash' => $selectedMap['source_hash'], 'source_word_count' => $selectedMap['total_words'], 'generated_at' => $generatedAt];
$pluginFixture = ['modules' => [
    'basic' => $module + ['result' => ['summary' => 'A saved summary of the outreach discussion.']],
    'followup' => $module + ['result' => ['items' => [
        ['item_id' => 'f-open', 'follow_up' => 'Check the supplier response', 'status' => 'waiting', 'evidence_refs' => [['page' => 1]]],
        ['item_id' => 'f-rejected', 'follow_up' => 'Rejected item should stay out of the prompt', 'review_state' => 'rejected'],
    ]]],
    'research_brief' => $module + ['result' => ['unresolved' => [
        ['item_id' => 'r-open', 'item' => 'Unconfirmed price', 'next_check' => 'Check the public price sheet'],
    ]]],
]];
$insertReport = $pdo->prepare("INSERT INTO artist_transcript_master_analysis_v237 VALUES(?,?,?,?,?,?,?,'fixture','fixture',?)");
$insertReport->execute([4, $selectedMap['source_hash'], $selectedMap['total_words'], $selectedMap['page_count'], 0,
    json_encode($pluginFixture, JSON_THROW_ON_ERROR), '{}', $generatedAt]);
$reportQuestion = 'What follow-ups are still open?';
$r = vp3_transcript_chat_v1($pdo, $u, $p, $reportQuestion, $focused, [], static function (string $prompt): array {
    $data = json_decode(substr($prompt, strpos($prompt, "\n") + 1), true, 32, JSON_THROW_ON_ERROR);
    $reports = $data['saved_plugin_reports'];
    ck(str_starts_with($prompt, 'VP3_TRANSCRIPT_ANSWER_V1') && array_column($data['evidence']['segments'], 'id') === [4]
        && array_column($reports['modules'], 'plugin') === ['basic', 'followup', 'research_brief'],
        'the selected recording discussion receives raw segments and each applied report separately');
    ck($reports['suggestion_counts'] === ['followup' => 1, 'research' => 1]
        && $reports['suggestions'][0]['ref'] === 'suggestion:followup:items:f-open'
        && !str_contains($prompt, 'Rejected item should stay out'),
        'open follow-up evidence is prioritized and rejected report prose stays out of inference');
    return ['ok' => true, 'answer' => '{"answer":"The saved Follow-Up report suggests checking the supplier response. It is unreviewed.","citations":[],"report_citations":["suggestion:followup:items:f-open"]}'];
});
ck($r['tool_receipt']['status'] === 'read_complete' && !$r['tool_receipt']['changes_applied']
    && str_contains($r['answer'], 'Saved plugin reports:') && !str_contains($r['answer'], 'segment #')
    && str_contains($r['sources'][1]['url'], 'session=4&plugin=followup&section=items&item=f-open&page=1'),
    'a report-only answer cites the exact saved finding without treating it as recorded speech');
[, , $currentReportHash] = vp3_transcript_discussion_reports_v1($pdo, $u, $p, 4, $selectedMap['source_hash'], '');
ck($r['transcript_context']['report_snapshot_hash'] === $currentReportHash && strlen($currentReportHash) === 64,
    'a report-grounded answer retains a focus-independent snapshot proof');
$r = vp3_transcript_chat_v1($pdo, $u, $p, 'Any research suggestions?', $focused, [], static function (string $prompt): array {
    $data = json_decode(substr($prompt, strpos($prompt, "\n") + 1), true, 32, JSON_THROW_ON_ERROR);
    ck($data['saved_plugin_reports']['suggestions'][0]['ref'] === 'suggestion:research_brief:unresolved:r-open',
        'a natural research question prioritizes saved research suggestions');
    return ['ok' => true, 'answer' => '{"answer":"The saved research suggestion is to check the public price sheet.","citations":[],"report_citations":["suggestion:research_brief:unresolved:r-open"]}'];
});
ck($r['tool_receipt']['capability'] === 'transcripts.read' && str_contains($r['answer'], 'public price sheet'),
    'discussing an existing research suggestion uses the read operation');
foreach ([
    ['citations' => ['suggestion:followup:items:f-open']],
    ['citations' => [], 'report_citations' => [4]],
    ['citations' => [], 'report_citations' => ['suggestion:followup:items:f-rejected']],
] as $invalidRefs) {
    $r = vp3_transcript_chat_v1($pdo, $u, $p, $reportQuestion, $focused, [], static fn () =>
        ['ok' => true, 'answer' => json_encode(['answer' => 'Invalid source attribution'] + $invalidRefs, JSON_THROW_ON_ERROR)]);
    ck($r['tool_receipt']['status'] === 'failed' && !str_contains($r['answer'], 'Invalid source attribution'),
        'crossed source roles and excluded saved suggestions cannot be cited');
}
$r = vp3_transcript_chat_v1($pdo, $u, $p, $reportQuestion, $focused, [], static function () use ($pdo, $pluginFixture): array {
    $pluginFixture['modules']['followup']['result']['items'][0]['review_state'] = 'rejected';
    $pdo->prepare('UPDATE artist_transcript_master_analysis_v237 SET analysis_json=? WHERE session_id=4')
        ->execute([json_encode($pluginFixture, JSON_THROW_ON_ERROR)]);
    return ['ok' => true, 'answer' => '{"answer":"Outdated supplier response recommendation","citations":[],"report_citations":["suggestion:followup:items:f-open"]}'];
});
ck($r['tool_receipt']['status'] === 'failed' && str_contains($r['answer'], 'plugin reports changed')
    && !str_contains($r['answer'], 'Outdated supplier response recommendation'),
    'a saved report review change invalidates a response in flight');
echo 'TRANSCRIPT_CONVERSATION=PASS ('.($checks-$before)." cases; actual source retrieval and ownership, controlled answer model)\n";
