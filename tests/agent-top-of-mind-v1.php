<?php
declare(strict_types=1);

// Reuse the real canonical queue/claimant/source fixture. The assertions below
// exercise the new read projection, not a second workflow implementation.
require __DIR__.'/agent-initiative-runtime-v1.php';
require_once dirname(__DIR__).'/includes/agent-top-of-mind-v1.php';
require_once dirname(__DIR__).'/includes/user-calendar-v1300.php';
require_once dirname(__DIR__).'/includes/agent-scheduling-v430.php';
$feedChecks=0;
function feed_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);++$GLOBALS['feedChecks'];echo 'PASS '.$label.PHP_EOL;}
function feed_item(array $state,int $id):?array{foreach($state['items'] as $item)if($item['run_id']===$id)return $item;return null;}
function feed_denied(callable $read):bool{try{$read();}catch(RuntimeException $error){return $error->getCode()===403;}return false;}

$GLOBALS['runtimeCalendarTitle']='Verified launch follow-through';
$GLOBALS['runtimeCalendarStart']=gmdate('Y-m-d H:i:s',time()+600);
$user=['id'=>1,'is_active'=>1];$policy=runtime_enable($user,0,42);
$beforeScope=$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null;
$coverage=[];$evidence=vp3_initiative_evidence_v1($pdo,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,null,0);
$calendar=array_filter($evidence,static fn(array $row):bool=>$row['kind']==='calendar');$ref=array_key_first($calendar);$source=$calendar[$ref];
$goal=['binding'=>$policy,'sources'=>[$ref=>$source['revision']],'canonical_sources'=>[$ref=>$source['canonical_source_revision']??$source['revision']],
    'tool'=>'work.prepare_private','primary'=>$ref,'focus'=>'Review verified launch details','report'=>true];
vp3_initiative_enqueue_v1($pdo,$user,'initiative.prepare',$goal,hash('sha256','feed-canonical-work'));
$runId=(int)$pdo->lastInsertId();
// SQLite lastInsertId now refers to the queued event, so find the actual run.
$q=$pdo->prepare('SELECT id FROM agent_workflow_runs WHERE owner_user_id=1 AND dedupe_key=?');
$q->execute([hash('sha256','initiative.prepare|'.hash('sha256','feed-canonical-work'))]);$runId=(int)$q->fetchColumn();
$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();$pdo->exec('PRAGMA query_only=ON');
$queued=vp3_top_of_mind_v1($pdo,$user,0,42);
feed_check(feed_item($queued,$runId)['state']==='queued'&&feed_item($queued,$runId)['step']==='',
    'a status read shows actual queued work without inventing execution or progress');
feed_check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before&&($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null)===$beforeScope,
    'the complete source-backed projection passes SQLite query-only mode and restores incoming Agent scope');
$pdo->exec('PRAGMA query_only=OFF');
$claim=agent_job_claim_run_v1900($pdo,$user,$runId,'cloud',agent_worker_cloud_worker_id_v1910(1),180);
feed_check(is_array($claim),'the existing canonical claimant starts the exact queued job');
agent_job_heartbeat_v1900($pdo,$user,$runId,(int)$claim['action']['id'],(string)$claim['lease_token'],35,'Preparing the launch analysis',240);
$working=feed_item(vp3_top_of_mind_v1($pdo,$user,0,42),$runId);
feed_check($working['state']==='working'&&$working['progress']===35&&$working['step']==='Preparing the launch analysis',
    'a valid real worker lease exposes its reported objective, step and progress');
$pdo->prepare("UPDATE agent_workflow_runs SET lease_expires_at='2000-01-01 00:00:00' WHERE id=?")->execute([$runId]);
$interrupted=feed_item(vp3_top_of_mind_v1($pdo,$user,0,42),$runId);
feed_check($interrupted['state']==='interrupted'&&$interrupted['progress']===null&&$interrupted['step']==='',
    'an expired lease cannot remain visually working or retain claimed current progress');
$pdo->prepare('UPDATE agent_workflow_runs SET lease_expires_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s',time()+240),$runId]);
$modelCalls=0;
$executed=vp3_initiative_execute_v1($pdo,$user,$claim,static function(string $prompt,string $protocol)use(&$modelCalls):string{
    ++$modelCalls;return json_encode(['draft'=>'Verified private launch analysis.'],JSON_THROW_ON_ERROR);
});
feed_check($executed['ok']===true&&$modelCalls===1,'the existing worker performs the actual private preparation and commits a canonical receipt');
$done=feed_item(vp3_top_of_mind_v1($pdo,$user,0,42),$runId);
feed_check(in_array($done['state'],['completed','ready_to_report'],true),'a completed canonical receipt distinguishes prepared work from queued work');
$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();$pdo->exec('PRAGMA query_only=ON');
vp3_top_of_mind_v1($pdo,$user,0,42);vp3_top_of_mind_v1($pdo,$user,0,42);
feed_check($modelCalls===1&&(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,'repeated live reads perform no inference or record mutation');
$pdo->exec('PRAGMA query_only=OFF');
$GLOBALS['initiativeBrainAccess']=false;
feed_check(feed_item(vp3_top_of_mind_v1($pdo,$user,0,42),$runId)!==null,'the core feed remains available without the optional Brain UI entitlement when its source is independently permitted');
$GLOBALS['initiativeBrainAccess']=true;
feed_check(feed_denied(fn()=>vp3_top_of_mind_v1($pdo,['id'=>2,'is_active'=>1],0,42)),'another account cannot read the exact Chat work');
feed_check(feed_denied(fn()=>vp3_top_of_mind_v1($pdo,$user,7,42)),'another Agent cannot borrow the System conversation');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1,'agent'=>0,'participant'=>2,'revision'=>1];
feed_check(feed_denied(fn()=>vp3_top_of_mind_v1($pdo,$user,0,42)),'participant scope cannot read owner work');
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
$GLOBALS['runtimeCalendarTitle']='Changed current source';
$changed=vp3_top_of_mind_v1($pdo,$user,0,42);
feed_check(feed_item($changed,$runId)===null&&!str_contains(json_encode($changed),'Review verified launch details'),
    'a changed source removes its old private objective and result from the live feed');
reminder_schema_v1($pdo);$refs=[];$at=gmdate('Y-m-d H:i:s',time()-60);$stamp=gmdate('Y-m-d H:i:s');
for($i=9001;$i<=9006;$i++){
    $pdo->prepare("INSERT INTO agent_reminders_v1(id,owner_user_id,title,timezone,start_local,recurrence,delivery,next_at,status,conversation_id,created_at,updated_at) VALUES(?,1,?,'UTC',?,'none','chat',?,'active',42,?,?)")
        ->execute([$i,'Owner reminder '.$i,$at,$at,$stamp,$stamp]);$refs[]='reminder:'.$i;
}
$coverage=[];$many=vp3_initiative_evidence_v1($pdo,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,$refs,0);
feed_check(count($many)===6,'six distinct real reminders are verified through the canonical source reader');
$planGoal=['binding'=>$policy,'sources'=>array_column($many,'revision','ref'),'canonical_sources'=>array_column($many,'revision','ref')];
vp3_initiative_enqueue_v1($pdo,$user,'initiative.plan',$planGoal,hash('sha256','feed-many-source-plan'));
$q->execute([hash('sha256','initiative.plan|'.hash('sha256','feed-many-source-plan'))]);$planRun=(int)$q->fetchColumn();
$planClaim=agent_job_claim_run_v1900($pdo,$user,$planRun,'cloud',agent_worker_cloud_worker_id_v1910(1),180);
$assessment=feed_item(vp3_top_of_mind_v1($pdo,$user,0,42),$planRun);
feed_check($assessment['state']==='working'&&$assessment['kind']==='assessment'&&$assessment['sources']===[]
    &&$assessment['objective']==='Assess useful next steps','a real assessment with more than five sources remains visible using generic work status without disclosing unchecked source titles');
$assessed=vp3_initiative_execute_v1($pdo,$user,$planClaim,static fn():string=>'{"jobs":[]}');
$assessmentDone=feed_item(vp3_top_of_mind_v1($pdo,$user,0,42),$planRun);
feed_check($assessed['ok']&&$assessmentDone['state']==='assessed'&&$assessmentDone['result_url']==='',
    'a verified completed assessment never masquerades as completed private preparation or an artifact');
$assessmentAttempts=[];
for($i=0;$i<3;$i++){
    $hash=hash('sha256','feed-repeat-assessment-'.$i);
    vp3_initiative_enqueue_v1($pdo,$user,'initiative.plan',$planGoal,$hash);
    $q->execute([hash('sha256','initiative.plan|'.$hash)]);$assessmentAttempts[]=(int)$q->fetchColumn();
}
$pdo->prepare("UPDATE agent_workflow_runs SET status='failed',last_error_class='initiative_unconfirmed' WHERE id=?")->execute([$assessmentAttempts[0]]);
$pdo->prepare("UPDATE agent_workflow_runs SET status='failed',last_error_class='assessment_sources_changed' WHERE id=?")->execute([$assessmentAttempts[1]]);
$assessments=static fn():array=>array_values(array_filter(vp3_top_of_mind_v1($pdo,$user,0,42)['items'],static fn(array $item):bool=>$item['kind']==='assessment'));
$current=$assessments();
feed_check(count($current)===1&&$current[0]['run_id']===$assessmentAttempts[2]&&$current[0]['state']==='queued',
    'one current assessment represents repeated attempts while preparations keep their own rows');
$pdo->prepare("UPDATE agent_workflow_runs SET status='failed',last_error_class='assessment_sources_changed' WHERE id=?")->execute([$assessmentAttempts[2]]);
$current=$assessments();
feed_check(count($current)===1&&$current[0]['state']==='superseded',
    'a replaced assessment shows a source refresh, while older repair rows are omitted');
$liveRef=array_key_first($many);
$olderGoal=['binding'=>$policy,'sources'=>[$liveRef=>$many[$liveRef]['revision']],
    'canonical_sources'=>[$liveRef=>$many[$liveRef]['revision']],'tool'=>'work.prepare_private','primary'=>$liveRef,
    'focus'=>'Older executing work remains visible','report'=>true];
vp3_initiative_enqueue_v1($pdo,$user,'initiative.prepare',$olderGoal,hash('sha256','feed-older-active'));
$q->execute([hash('sha256','initiative.prepare|'.hash('sha256','feed-older-active'))]);$olderId=(int)$q->fetchColumn();
$olderClaim=agent_job_claim_run_v1900($pdo,$user,$olderId,'cloud',agent_worker_cloud_worker_id_v1910(1),180);
for($i=0;$i<6;$i++)vp3_initiative_enqueue_v1($pdo,$user,'initiative.prepare',array_replace($olderGoal,['focus'=>'New queued work '.$i]),hash('sha256','feed-newer-queued-'.$i));
$prioritized=vp3_top_of_mind_v1($pdo,$user,0,42);
feed_check($prioritized['items'][0]['run_id']===$olderId&&$prioritized['items'][0]['state']==='working'&&count($prioritized['items'])<=6,
    'an older actual executing job stays first and visible even with six newer queued jobs');
for($i=0;$i<26;$i++)vp3_initiative_enqueue_v1($pdo,$user,'initiative.prepare',array_replace($olderGoal,['focus'=>'Older backlog '.$i,'report'=>false]),hash('sha256','feed-backlog-old-'.$i));
vp3_initiative_enqueue_v1($pdo,$user,'initiative.prepare',array_replace($olderGoal,['focus'=>'Middle active work survives both history windows','report'=>false]),hash('sha256','feed-backlog-middle'));
$q->execute([hash('sha256','initiative.prepare|'.hash('sha256','feed-backlog-middle'))]);$middleId=(int)$q->fetchColumn();
$middleClaim=agent_job_claim_run_v1900($pdo,$user,$middleId,'cloud',agent_worker_cloud_worker_id_v1910(1),180);
$backlogCalls=0;
for($i=0;$i<26;$i++){
    $hash=hash('sha256','feed-backlog-new-'.$i);
    vp3_initiative_enqueue_v1($pdo,$user,'initiative.prepare',array_replace($olderGoal,['focus'=>'Completed private backlog '.$i,'report'=>false]),$hash);
    $q->execute([hash('sha256','initiative.prepare|'.$hash)]);$newId=(int)$q->fetchColumn();
    $newClaim=agent_job_claim_run_v1900($pdo,$user,$newId,'cloud',agent_worker_cloud_worker_id_v1910(1),180);
    $outcome=vp3_initiative_execute_v1($pdo,$user,$newClaim,static function()use(&$backlogCalls):string{++$backlogCalls;return '{"draft":"Verified private backlog preparation."}';});
    if(!$outcome['ok'])throw new RuntimeException('Canonical backlog preparation failed.');
}
$oldWindow=array_column(vp3_initiative_runtime_runs_v1($pdo,1,0,$policy,true),'id');
$recentWindow=array_column(vp3_initiative_runtime_runs_v1($pdo,1,0,$policy),'id');
$backlog=vp3_top_of_mind_v1($pdo,$user,0,42);
feed_check($backlogCalls===26&&!in_array($middleId,$oldWindow)&&!in_array($middleId,$recentWindow)
    &&$backlog['items'][0]['run_id']===$middleId&&$backlog['items'][0]['state']==='working',
    'an executing job hidden from both bounded oldest-pending and newest-history windows remains visible through the canonical executing-only selector');

$pdo->exec('CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT);
    INSERT INTO chat_conversations VALUES(46,1,NULL,NULL);
    ALTER TABLE chat_conversations ADD COLUMN title TEXT');
agent_connection_youtube_results_schema_v1($pdo);
agent_connection_youtube_consent_schema_v1($pdo);
vp3_youtube_playback_install_v1($pdo);
agent_connection_youtube_set_consent_v1($pdo,1,true);
vp3_youtube_history_change_v1($pdo,1,'enable_history');
$capture=vp3_youtube_capture_key_v1($pdo,1);
$mediaSeed=static function(int $conversation,string $title,string $video,string $query,array $extra=[])use($pdo):int {
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,role,message) VALUES(?,'assistant','YouTube search')")->execute([$conversation]);
    $id=(int)$pdo->lastInsertId();
    agent_connection_youtube_results_save_v1($pdo,1,$conversation,$id,['query'=>$query,'expires_at'=>time()+60,
        'videos'=>[['video_id'=>$video,'title'=>$title,'channel_title'=>'Test channel','made_for_kids'=>false]]]+$extra);
    return $id;
};
$localMedia=$mediaSeed(42,'Recent eligible YouTube playback','local000001','Quiet session music',['suggestion_kind'=>'playlist']);
$otherMedia=$mediaSeed(46,'Other private conversation playback','other000001','Other private search');
$beforePlay=vp3_top_of_mind_v1($pdo,$user,0,42);
feed_check($beforePlay['music']===null&&($beforePlay['youtube_search']['message_id']??0)===$localMedia
    &&$beforePlay['youtube_search']['kind']==='playlist'&&$beforePlay['youtube_search']['result_count']===1
    &&!str_contains(json_encode($beforePlay['youtube_search']),'local000001'),
    'the current Chat’s returned playlist is available separately from verified playback, without video identifiers');
$otherChat=vp3_top_of_mind_v1($pdo,$user,0,46);
feed_check($otherChat['state']==='linked_elsewhere'&&$otherChat['items']===[]&&($otherChat['youtube_search']['message_id']??0)===$otherMedia,
    'a manually opened Chat sees its own search context without inheriting another Chat’s work or search');
$localPlay='10000000-0000-4000-8000-000000000001';$otherPlay='10000000-0000-4000-8000-000000000002';
feed_check(vp3_youtube_history_record_v1($pdo,1,$localMedia,'local000001',$localPlay,$capture)
    &&vp3_youtube_history_record_v1($pdo,1,$otherMedia,'other000001',$otherPlay,$capture),
    'the real capture path creates one approved play in each exact saved Chat');
$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();$pdo->exec('PRAGMA query_only=ON');
$music=vp3_top_of_mind_v1($pdo,$user,0,42)['music'];
feed_check($music['title']==='Recent eligible YouTube playback'&&$music['channel_title']==='Test channel'
    &&str_contains($music['chat_url'],'agent=system&conversation_id=42')&&!str_contains(json_encode($music),$capture)
    &&!str_contains(json_encode($music),$localPlay)&&(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,
    'the read-only Music card reports verified playback only from its exact Chat and releases no capture or playback identifiers');
$pdo->exec('PRAGMA query_only=OFF');
$coverage=[];
$context=vp3_initiative_evidence_v1($pdo,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,null,0);
feed_check(!str_contains(json_encode($context),'Recent eligible YouTube playback'),
    'this playback card does not expose YouTube titles to planning or configured AI');
vp3_youtube_history_change_v1($pdo,1,'pause_history');
$capturePaused=vp3_top_of_mind_v1($pdo,$user,0,42);
feed_check($capturePaused['music']===null&&$capturePaused['youtube_search']['message_id']===$localMedia,
    'pausing playback capture immediately removes its observation while preserving returned search context');
vp3_youtube_history_change_v1($pdo,1,'enable_history');
$pdo->prepare('UPDATE agent_connection_youtube_results_v1 SET expires_at=? WHERE message_id=?')->execute([time()-1,$localMedia]);
$expired=vp3_top_of_mind_v1($pdo,$user,0,42);
feed_check($expired['music']===null&&$expired['youtube_search']===null,'expired media data is omitted even when a playback record survives cleanup');
$olderSearch=$mediaSeed(42,'Older provider title','older000001','Yesterday’s unrelated query');
$pdo->prepare('UPDATE chat_messages SET created_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s',time()-86401),$olderSearch]);
feed_check(vp3_top_of_mind_v1($pdo,$user,0,42)['youtube_search']===null,'Top of Mind leaves older cached results in History');
$artistSource=$mediaSeed(42,'A public provider title','artist00001','Music artists similar to The Test Artist',
    ['suggestion_kind'=>'similar','taste_topic'=>'The Test Artist']);
$artist=vp3_top_of_mind_v1($pdo,$user,0,42)['artist_research'];
feed_check($artist['artist']==='The Test Artist'&&$artist['status']==='available'&&$artist['source_message_id']===$artistSource,
    'a recent explicitly named artist keeps its exact source and offers an owner action');
$marker=['artist'=>'The Test Artist','mode'=>'read','status'=>'read_complete','checked_at'=>gmdate(DATE_ATOM)];
$receipt=vp3_tool_receipt_v1('artist.research','search','read_complete');
$pdo->prepare("INSERT INTO chat_messages(conversation_id,role,message,context_json) VALUES(42,'assistant','Research',?)")
    ->execute([json_encode(['artist_research_v1'=>$marker,'tool_receipt'=>$receipt],JSON_THROW_ON_ERROR)]);
$reportId=(int)$pdo->lastInsertId();
$artist=vp3_top_of_mind_v1($pdo,$user,0,42)['artist_research'];
feed_check($artist['status']==='ready'&&$artist['message_id']===$reportId&&$artist['source_message_id']===$artistSource,
    'verified research updates the same artist thread to link to its exact report');
vp3_initiative_chat_v1($pdo,$user,vp3_agent_chat_principal_v380($user,null),['conversation_id'=>42],'off',null);
$paused=vp3_top_of_mind_v1($pdo,$user,0,42);
feed_check($paused['state']==='paused'&&$paused['items']===[]&&$paused['youtube_search']['message_id']===$artistSource,
    'an explicit Pause stops work projection while manual source context remains available');
agent_connection_youtube_set_consent_v1($pdo,1,false);
feed_check(vp3_top_of_mind_v1($pdo,$user,0,42)['youtube_search']===null,'revoking YouTube consent immediately hides its cached Top of Mind search');
feed_check(count($queued['items'])<=6&&!str_contains(json_encode($queued),'lease_token')&&!str_contains(json_encode($queued),'initiative_draft'),
    'bounded feed output excludes leases, raw prompts, model drafts and hidden reasoning');
$originalTimezone=date_default_timezone_get();
try{
    date_default_timezone_set('America/Phoenix');$now=time();
    $leaseRun=['status'=>'executing','current_action_id'=>5,'lease_expires_at'=>gmdate('Y-m-d H:i:s',$now-5)];
    $leaseAction=['id'=>5,'status'=>'executing'];
    feed_check(vp3_top_of_mind_state_v1($leaseRun,$leaseAction,null,[],$now)==='interrupted',
        'a UTC lease remains expired when the PHP host uses the owner Phoenix timezone');
    $leaseRun['lease_expires_at']=gmdate('Y-m-d H:i:s',$now+30);
    feed_check(vp3_top_of_mind_state_v1($leaseRun,$leaseAction,null,[],$now)==='working'
        &&vp3_top_of_mind_lease_time_v1('2026-02-31 10:00:00')===0,
        'UTC lease parsing accepts actual future work and rejects malformed calendar timestamps');
}finally{date_default_timezone_set($originalTimezone);}
$emptyAction=['status'=>'completed','result_json'=>'{}'];$emptyReceipt=['status'=>'completed','result_json'=>'{}'];
feed_check(vp3_top_of_mind_state_v1(['status'=>'completed','capability_key'=>'initiative.prepare'],$emptyAction,$emptyReceipt,[],time())==='unverified',
    'matching empty completion rows cannot masquerade as a verified private preparation');
feed_check(vp3_top_of_mind_state_v1(['status'=>'completed','capability_key'=>'initiative.plan'],$emptyAction,$emptyReceipt,[],time())==='unverified',
    'a completed assessment requires a recognized canonical assessment effect');
echo 'AGENT_TOP_OF_MIND_V1=PASS ('.$feedChecks.' cases)'.PHP_EOL;
