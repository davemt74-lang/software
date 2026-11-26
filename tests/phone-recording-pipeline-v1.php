<?php
declare(strict_types=1);
/** Real private-media/transcript/Chat persistence; all paid transports are fixtures. */
$directory=sys_get_temp_dir().'/vp3-phone-pipeline-'.bin2hex(random_bytes(8));mkdir($directory,0700);
define('STONEFELLOW_ROOT',$directory);
require dirname(__DIR__).'/includes/phone-workspace-v1.php';
require dirname(__DIR__).'/includes/phone-recording-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-operator-history-v1.php';
function db():PDO{return $GLOBALS['pdo'];}
function has_permission(string $permission,?array $user=null):bool{return empty($user['blocked']);}
function subscription_has_entitlement(?array $user,string $key):bool{return !empty($user['is_active'])&&empty($user['blocked']);}
function subscription_entitlement_limit(?array $user,string $key,?int $default=null):?int{return 10;}
function vp3_user_llm_v1_effective(PDO $pdo,array $user):array{return ['route'=>'own_key','provider'=>'openai','model'=>'fixture-model','api_key'=>'fixture-not-a-secret'];}
function url(string $path):string{return 'https://vp3.example'.$path;}
function ai_curl_json(string $url,array $headers,array $payload,int $timeout,mixed ...$unused):array
{
    $GLOBALS['fixture_page_dispatches']=($GLOBALS['fixture_page_dispatches']??0)+1;
    db()->exec('UPDATE phone_recording_policies_v1 SET revision=revision+1 WHERE owner_user_id=1');
    return ['ok'=>true,'data'=>['answer'=>'fixture page']];
}
$checks=0;
function check(bool $yes,string $label):void{global $checks;if(!$yes)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label.PHP_EOL;}
function denied(callable $fn,string $label):void{try{$fn();}catch(RuntimeException $error){check(in_array($error->getCode(),[403,404,409,422,429],true),$label);return;}throw new RuntimeException('FAIL expected rejection: '.$label);}
$mysqlDsn=(string)getenv('VP3_PHONE_MYSQL_DSN');$mysql=$mysqlDsn!=='';
$pdo=$mysql?new PDO($mysqlDsn,(string)getenv('VP3_PHONE_MYSQL_USER'),(string)getenv('VP3_PHONE_MYSQL_PASSWORD')):new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$fixtureTables=['phone_voicemail_deletions_v1','phone_voicemail_greeting_tokens_v1','phone_voicemail_receipts_v1','phone_voicemail_settings_v1','phone_recording_speech_budget_v1','phone_recording_legacy_reads_v1','phone_recording_jobs_v1','phone_recording_policies_v1','artist_transcript_master_analysis_v237','artist_transcript_segments_v172','artist_transcript_sessions_v172','phone_browser_deliveries_v1','chat_messages','chat_conversations','phone_voice_recordings_v130','phone_voice_calls_v130','phone_browser_calls_v1','phone_cloud_numbers_v100','user_agents','users'];
if($mysql){if(!preg_match('/_test$/D',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('MySQL fixture requires a dedicated database ending _test.');foreach($fixtureTables as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);}
$fixtureId=$mysql?'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER,blocked INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER)');
$pdo->exec('CREATE TABLE phone_cloud_numbers_v100(id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,provider TEXT,voice_enabled INTEGER)');
$pdo->exec('CREATE TABLE phone_browser_calls_v1(id INTEGER PRIMARY KEY,public_id TEXT,owner_user_id INTEGER,agent_id INTEGER,phone_number_id INTEGER,provider_call_sid TEXT,phone_e164 TEXT,peer_e164 TEXT,conversation_id INTEGER,chat_agent_id INTEGER)');
$pdo->exec('CREATE TABLE phone_voice_calls_v130(id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,phone_number_id INTEGER,provider_call_sid TEXT,caller_e164 TEXT,called_e164 TEXT)');
$pdo->exec('CREATE TABLE phone_voice_recordings_v130(id INTEGER PRIMARY KEY,call_id INTEGER,owner_user_id INTEGER,provider TEXT,provider_recording_sid TEXT,recording_status TEXT,duration_seconds INTEGER,created_at TEXT)');
$pdo->exec('CREATE TABLE chat_conversations(id '.$fixtureId.',user_id INTEGER,user_agent_id INTEGER,title TEXT,created_at TEXT,updated_at TEXT)');
$pdo->exec('CREATE TABLE chat_messages(id '.$fixtureId.',conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT,created_at TEXT)');
$pdo->exec('CREATE TABLE phone_browser_deliveries_v1(owner_user_id INTEGER,event_key VARCHAR(145),chat_message_id INTEGER,conversation_id INTEGER,chat_agent_id INTEGER,PRIMARY KEY(owner_user_id,event_key))');
$pdo->exec('CREATE TABLE artist_transcript_sessions_v172(id '.$fixtureId.',owner_user_id INTEGER,created_by_user_id INTEGER,conversation_id INTEGER,client_session_key VARCHAR(64),title TEXT,status TEXT,language TEXT,metadata_json TEXT,duration_ms INTEGER,started_at TEXT,stopped_at TEXT,last_activity_at TEXT,discarded_at TEXT DEFAULT NULL,UNIQUE(created_by_user_id,client_session_key))');
$pdo->exec('CREATE TABLE artist_transcript_segments_v172(id '.$fixtureId.',session_id INTEGER,client_segment_key TEXT,segment_index INTEGER,segment_type TEXT,speaker_label TEXT,transcript_text TEXT,started_ms INTEGER,ended_ms INTEGER,confidence REAL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE artist_transcript_master_analysis_v237(session_id INTEGER PRIMARY KEY,source_hash TEXT,analysis_json TEXT)');
$pdo->exec('INSERT INTO users(id,is_active) VALUES(1,1),(2,1)');$pdo->exec('INSERT INTO user_agents VALUES(7,1,1),(8,2,1)');
$pdo->exec("INSERT INTO phone_cloud_numbers_v100 VALUES(1,1,7,'twilio',1)");
$owner=['id'=>1,'is_active'=>1];$other=['id'=>2,'is_active'=>1];
vp3_phone_recording_ensure_schema_v1($pdo);check(vp3_phone_recording_schema_ready_v1($pdo),'new schema checks use supplied PDO');
$newCall=static function(int $id)use($pdo):array{
    $call=['id'=>$id,'public_id'=>str_pad(dechex($id),32,'0',STR_PAD_LEFT),'owner_user_id'=>1,'agent_id'=>7,'phone_number_id'=>1,'provider_call_sid'=>'CA'.str_pad(dechex($id),32,'0',STR_PAD_LEFT),'phone_e164'=>'+16025550101','peer_e164'=>'+16025550999','conversation_id'=>0,'chat_agent_id'=>0];
    $pdo->prepare('INSERT INTO phone_browser_calls_v1 VALUES(?,?,?,?,?,?,?,?,?,?)')->execute(array_values($call));return $call;
};
$receipt=static function(array $call,int $number,int $duration=3)use($pdo):array{return vp3_phone_recording_receive_v1($pdo,$call,['AccountSid'=>'AC'.str_repeat('a',32),'CallSid'=>$call['provider_call_sid'],'RecordingSid'=>'RE'.str_pad(dechex($number),32,'0',STR_PAD_LEFT),'RecordingStatus'=>'completed','RecordingDuration'=>(string)$duration,'RecordingUrl'=>'http://untrusted.invalid/private']);};
$frame="\xff\xfb\x90\x00".str_repeat("\0",413);$audio=$frame.$frame;$counts=['fetch'=>0,'speech'=>0,'analysis'=>0];
$adapters=['fetch'=>static function(string $endpoint,array $job)use(&$counts,$audio):string{$counts['fetch']++;check(str_starts_with($endpoint,'https://api.twilio.com/2010-04-01/Accounts/AC')&&!str_contains($endpoint,'untrusted'),'fixed authenticated provider media address');return $audio;},
    'speech'=>static function($path,$options,$user)use(&$counts):array{$counts['speech']++;return ['text'=>'Please send the plan. When is the deadline?','words'=>[['text'=>'Please send the plan.','start'=>0,'end'=>1,'speaker_id'=>'a'],['text'=>'When is the deadline?','start'=>1,'end'=>2,'speaker_id'=>'b']]];},
    'analyze'=>static function(PDO $pdo,array $user,int $session,array $modules,array $workflow)use(&$counts):array{
        $counts['analysis']++;check($workflow['web_research']===false&&$workflow['live_analysis']===false,'automatic selected modules have no web/live side effect');
        $map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,$session));$saved=[];
        foreach($modules as $id)$saved[$id]=['source_hash'=>$map['source_hash'],'result'=>match($id){'basic'=>['summary'=>'A plan was requested.','key_findings'=>[['text'=>'The deadline needs confirmation.']]],'actions'=>['items'=>[['action'=>'Review and send the plan.']]],'qa'=>['unanswered'=>[['question'=>'When is the deadline?']]],default=>['items'=>[]]}];
        $master=['source_hash'=>$map['source_hash'],'analysis'=>['modules'=>$saved]];$pdo->prepare('INSERT INTO artist_transcript_master_analysis_v237 VALUES(?,?,?)')->execute([$session,$map['source_hash'],json_encode($master)]);
        return ['master'=>$master,'executed_apps'=>$modules,'plugin_errors'=>[]];
    }];
try{
    $call=$newCall(1);$first=$receipt($call,1);check($counts===['fetch'=>0,'speech'=>0,'analysis'=>0],'recording callback only queues metadata');
    check($first['recording']['id']==='job-1','new voicemail IDs are typed');
    $result=vp3_phone_recording_worker_once_v1($pdo,$adapters);check($result['ok']&&$counts===['fetch'=>0,'speech'=>0,'analysis'=>0],'default-off policy never downloads or charges providers');
    $receipt($call,1);check((int)$pdo->query('SELECT COUNT(*) FROM phone_recording_jobs_v1')->fetchColumn()===1&&(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===2,'callback and report deliveries are idempotent');
    $policy=vp3_phone_recording_save_policy_v1($pdo,$owner,7,['enabled'=>true,'modules'=>['basic','qa','actions','decisions','followup']]);check($policy['enabled']&&$policy['revision']===1&&$policy['model_route']==='account_cloud','explicit policy persists selected existing modules and account route');
    $updated=vp3_phone_recording_save_policy_v1($pdo,$owner,7,['analyze'=>false]);check(!$updated['analyze']&&$updated['revision']===2,'policy update preserves public analyze boolean with quoted SQL identifier');vp3_phone_recording_save_policy_v1($pdo,$owner,7,['analyze'=>true]);
    denied(fn()=>vp3_phone_recording_save_policy_v1($pdo,$owner,7,['modules'=>['research_brief']]),'automatic reviewed research output is rejected');
    $receipt($newCall(2),2);$result=vp3_phone_recording_worker_once_v1($pdo,$adapters);if(!$result['ok'])throw new RuntimeException(json_encode($result));check($counts['speech']===1&&$counts['analysis']===1,'complete recording imports, transcribes and analyzes once');
    $job=vp3_phone_recording_row_v1($pdo,2);$session=(int)$job['session_id'];$saved=$pdo->query('SELECT * FROM artist_transcript_sessions_v172 WHERE id='.$session)->fetch();$meta=json_decode($saved['metadata_json'],true);
    check($saved['status']==='draft'&&(int)$saved['created_by_user_id']===1&&$meta['source']==='phone_call_v1','canonical private draft preserves phone source and owner');
    check((int)$pdo->query('SELECT COUNT(*) FROM artist_transcript_segments_v172 WHERE session_id='.$session)->fetchColumn()===2,'canonical transcript retains provider speakers and segments');
    $media=vp3_phone_recording_audio_any_v1($pdo,$owner,'job-2');check(is_file($media['path'])&&hash_file('sha256',$media['path'])===hash('sha256',$audio),'retained playback uses durable private canonical media');
    check(!is_file(vp3_phone_recording_source_path_v1($job)),'worker removes temporary source after durable import');
    $message=$pdo->query('SELECT * FROM chat_messages WHERE id='.(int)$job['report_message_id'])->fetch();$context=json_decode($message['context_json'],true)['phone_v1'];
    check($context['voicemail_id']==='job-2'&&$context['recording_refs'][0]['session_id']===$session&&$context['report']['review_state']==='unreviewed','actual owner Chat report links canonical recording and unreviewed analysis');
    check(str_contains($message['message'],'Review and send the plan.')&&str_contains($message['message'],'When is the deadline?'),'post-call report contains saved actions and owner questions');
    $operatorSource=['id'=>(int)$job['call_id'],'agent_id'=>7,'source_kind'=>'browser','public_id'=>$job['call_public_id']];
    $operatorBefore=$counts;
    $operatorEvidence=vp3_operator_recording_evidence_v1($pdo,$owner,0,$job,$operatorSource);
    check($operatorEvidence===['state'=>'ready','transcript_saved'=>true,'report_saved'=>true]&&$counts===$operatorBefore,'Operator verifies saved transcript and canonical Chat report without another paid request');
    check(vp3_operator_recording_evidence_v1($pdo,$other,0,$job,$operatorSource)===null&&vp3_operator_recording_evidence_v1($pdo,$owner,8,$job,$operatorSource)===null,'Operator recording evidence rejects foreign owner and Agent');
    check(vp3_operator_recording_evidence_v1($pdo,$owner,0,$job,array_replace($operatorSource,['public_id'=>str_repeat('0',32)]))===null,'Operator does not infer a report from a similar caller');
    $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute(['{}',(int)$job['report_message_id']]);
    check(vp3_operator_recording_evidence_v1($pdo,$owner,0,$job,$operatorSource)['report_saved']===false,'Operator does not claim a report whose canonical binding was removed');
    $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([$message['context_json'],(int)$job['report_message_id']]);
    $before=$counts;$receipt($call=$newCall(3),3);$pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=2,agent_id=8 WHERE id=1');$result=vp3_phone_recording_worker_once_v1($pdo,$adapters);check(!$result['ok']&&$counts===$before,'assignment transfer fences new paid processing');
    check(is_file(vp3_phone_recording_audio_any_v1($pdo,$owner,'job-2')['path']),'historical media ownership survives number reassignment');denied(fn()=>vp3_phone_recording_audio_any_v1($pdo,$other,'job-2'),'new number owner cannot read old private media');
    $pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=1,agent_id=7 WHERE id=1');
    $receipt($newCall(4),4);$mutating=$adapters;$mutating['speech']=static function($path,$opts,$user)use($adapters,$pdo):array{$result=$adapters['speech']($path,$opts,$user);$pdo->exec('UPDATE phone_recording_policies_v1 SET revision=revision+1 WHERE owner_user_id=1');return $result;};
    $result=vp3_phone_recording_worker_once_v1($pdo,$mutating);check(!$result['ok']&&$result['reason']==='authorization_changed'&&(int)vp3_phone_recording_row_v1($pdo,4)['session_id']===0,'policy revision revoked during STT blocks import/report');
    denied(fn()=>vp3_phone_recording_retry_v1($pdo,$owner,4),'uncertain or completed paid attempt cannot silently replay');
    $receipt($newCall(5),5);$timeout=$adapters;$timeout['speech']=static function(){throw new RuntimeException('fixture provider timeout');};$result=vp3_phone_recording_worker_once_v1($pdo,$timeout);check(!$result['ok']&&(int)vp3_phone_recording_row_v1($pdo,5)['speech_dispatched']===1,'provider timeout remains marked dispatched for review');
    denied(fn()=>vp3_phone_recording_retry_v1($pdo,$owner,5),'paid timeout is not eligible for retry');
    $receipt($newCall(6),6,3601);$before=$counts;$result=vp3_phone_recording_worker_once_v1($pdo,$adapters);check(!$result['ok']&&$counts['speech']===$before['speech']&&!vp3_phone_recording_row_v1($pdo,6)['speech_dispatched'],'oversized duration fails before paid speech dispatch');
    $receipt($newCall(7),7);$editing=$adapters;$editing['analyze']=static function(...$args)use($adapters,$pdo):array{$result=$adapters['analyze'](...$args);$pdo->prepare('UPDATE artist_transcript_segments_v172 SET transcript_text=? WHERE session_id=?')->execute(['Edited by owner',$args[2]]);return $result;};
    $result=vp3_phone_recording_worker_once_v1($pdo,$editing);check(!$result['ok']&&$result['reason']==='authorization_changed'&&!vp3_phone_recording_row_v1($pdo,7)['report_message_id'],'source edits fence stale analysis report');
    $pdo->exec("INSERT INTO phone_voice_calls_v130 VALUES(10,1,7,1,'CA".str_repeat('e',32)."','+16025550888','+16025550101')");
    $pdo->exec("INSERT INTO phone_voice_recordings_v130 VALUES(2,10,1,'twilio','RE".str_repeat('e',32)."','completed',12,'2026-11-23 12:00:00')");
    $rows=vp3_phone_recording_workspace_v1($pdo,$owner,7);$ids=array_column($rows,'id');check(in_array('job-2',$ids,true)&&in_array('legacy-2',$ids,true),'legacy saved voicemail coexists without ID collision or new provider job');
    check(vp3_phone_recording_mark_read_v1($pdo,$owner,'legacy-2',7)['read']&&vp3_phone_recording_mark_read_v1($pdo,$owner,'job-2',7)['read'],'both voicemail sources preserve read state');
    denied(fn()=>vp3_phone_recording_mark_read_v1($pdo,$other,'legacy-2'),'legacy voicemail cannot cross owners');
    denied(fn()=>vp3_phone_recording_mark_read_v1($pdo,$owner,'job-2',8),'voicemail cannot cross scoped Agent');
    $pdo->exec('UPDATE artist_transcript_sessions_v172 SET discarded_at=CURRENT_TIMESTAMP WHERE id='.$session);denied(fn()=>vp3_phone_recording_audio_any_v1($pdo,$owner,'job-2'),'discarded canonical audio is never resurrected from provider');
    $operatorRemoved=vp3_operator_recording_evidence_v1($pdo,$owner,0,$job,$operatorSource);
    check(!$operatorRemoved['transcript_saved']&&!$operatorRemoved['report_saved'],'Operator hides removed transcript and derived report availability');
    $rows=vp3_phone_recording_workspace_v1($pdo,$owner);$view=array_values(array_filter($rows,static fn($r)=>$r['id']==='job-2'))[0];check($view['transcript']==='','discarded transcript is absent from workspace excerpt');
    // Re-delivery after an initial Chat insertion failure must recover without duplicating paid work.
    $pdo->exec($mysql?"CREATE TRIGGER fixture_chat_failure BEFORE INSERT ON chat_messages FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture delivery unavailable'":"CREATE TRIGGER fixture_chat_failure BEFORE INSERT ON chat_messages BEGIN SELECT RAISE(ABORT,'fixture delivery unavailable'); END");$call=$newCall(8);
    try{$receipt($call,8);}catch(PDOException){}$pdo->exec('DROP TRIGGER fixture_chat_failure');$result=$receipt($call,8);check($result['duplicate']&&(int)vp3_phone_recording_row_v1($pdo,8)['conversation_id']>0,'duplicate callback recovers failed initial Chat announcement');
    $pdo->exec('UPDATE phone_recording_speech_budget_v1 SET seconds=3600');$receipt($newCall(9),9);$pdo->exec("UPDATE phone_recording_jobs_v1 SET state='failed' WHERE id=8");$before=$counts;$result=vp3_phone_recording_worker_once_v1($pdo,$adapters);check(!$result['ok']&&$counts['speech']===$before['speech']&&!vp3_phone_recording_row_v1($pdo,9)['speech_dispatched'],'hourly speech allowance fences new charges before dispatch');
    $pdo->exec('DELETE FROM phone_recording_speech_budget_v1');$receipt($newCall(10),10);$pages=$adapters;
    $pages['analyze']=static function(PDO $pdo,array $user,int $session,array $modules,array $workflow):array{
        $route=['provider'=>'openai','model'=>'fixture','api_key'=>'fixture','funding'=>'system'];
        // Public-scope funding bypass is a test transport fixture only; the live
        // phone path invokes the canonical account-metered transcription service.
        vp3_ai_direct_request_v1($user,$route,'profile_call_public',['max_output_tokens'=>100],1);
        vp3_ai_direct_request_v1($user,$route,'profile_call_public',['max_output_tokens'=>100],1);
        throw new RuntimeException('A revoked second analysis page was dispatched.');
    };
    $result=vp3_phone_recording_worker_once_v1($pdo,$pages);check(!$result['ok']&&$result['reason']==='authorization_changed'&&$GLOBALS['fixture_page_dispatches']===1,'policy revoke after first analysis request blocks later paid page/batch');
    $receipt($newCall(11),11);$invalid=$adapters;$invalid['fetch']=static fn()=> 'ID3'.str_repeat("\0",831);$before=$counts;$result=vp3_phone_recording_worker_once_v1($pdo,$invalid);
    check(!$result['ok']&&!vp3_phone_recording_row_v1($pdo,11)['speech_dispatched']&&$counts['speech']===$before['speech'],'MP3 preflight rejection proves no paid request started');
    $pdo->exec('UPDATE phone_recording_speech_budget_v1 SET reserved_epoch=1 WHERE job_id=11');vp3_phone_recording_retry_v1($pdo,$owner,11);$result=vp3_phone_recording_worker_once_v1($pdo,$adapters);
    check($result['ok']&&$counts['speech']===$before['speech']+1&&(int)$pdo->query('SELECT reserved_epoch FROM phone_recording_speech_budget_v1 WHERE job_id=11')->fetchColumn()>1,'explicit unpaid retry refreshes allowance and sends exactly one speech request');
    $receipt($newCall(12),12);$expired=vp3_phone_recording_claim_v1($pdo);vp3_phone_recording_store_source_v1($expired,$audio);$pdo->exec('UPDATE phone_recording_jobs_v1 SET lease_until=1 WHERE id=12');vp3_phone_recording_claim_v1($pdo);
    check(vp3_phone_recording_row_v1($pdo,12)['error_code']==='interrupted_review'&&!is_file(vp3_phone_recording_source_path_v1($expired)),'expired worker is fenced and its temporary audio removed');
    check(vp3_phone_recording_worker_health_v1()['state']==='never_seen','worker status read does not invent an observation');
    vp3_phone_recording_worker_observe_v1('completed');$health=vp3_phone_recording_worker_health_v1();check($health['state']==='observed'&&$health['outcome']==='completed'&&vp3_phone_recording_worker_health_v1(time()+1201)['state']==='stale','CLI worker observation persists and ages honestly');
    check(vp3_phone_worker_health_v1(true)['state']==='never_seen','the recording worker cannot make the call scheduler appear healthy');
    vp3_phone_worker_observe_v1(true,'failed');
    $schedule=vp3_phone_worker_health_v1(true);
    check($schedule['state']==='observed'&&$schedule['outcome']==='failed'&&vp3_phone_recording_worker_health_v1()['outcome']==='completed','scheduler failures retain a separate operational receipt');
    check(vp3_phone_worker_health_v1(true,strtotime($schedule['last_observed_at'])+181)['state']==='stale','the once-per-minute scheduler shows overdue after three minutes');
    check(!glob($directory.'/private/phone-recordings-v1/*/*/source.mp3'),'worker temporary audio is removed on every success or failure');
    echo 'PHONE_RECORDING_PIPELINE_V1=PASS ('.$checks.' checks)'.PHP_EOL;
}finally{
    if($mysql)foreach($fixtureTables as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($iterator as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($directory);
}
