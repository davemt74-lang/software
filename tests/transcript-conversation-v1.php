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
echo 'TRANSCRIPT_CONVERSATION=PASS ('.($checks-$before)." cases; actual source retrieval and ownership, controlled answer model)\n";
