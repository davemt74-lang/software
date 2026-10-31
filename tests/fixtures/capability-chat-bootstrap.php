<?php
declare(strict_types=1);
// Infrastructure/model fixtures only. Endpoints, Chat runtime, discovery and reminder executors are production files.
require_once __DIR__.'/cognitive-runtime-v500.php';
require_once __DIR__.'/agent-chat-runtime-v2160.php';
require_once __DIR__.'/agent-surface-context-v131.php';
require_once __DIR__.'/agent-scheduling-tools-v460.php';
require_once __DIR__.'/agent-brain-v122.php';
require_once __DIR__.'/phone-agent-schema-v1.php';
class TranscriptFixturePDO extends PDO {public int $updatedId=0;public function lastInsertId(?string $name=null):string|false{return $this->updatedId?(string)$this->updatedId:parent::lastInsertId($name);}public function prepare(string $sql,array $options=[]):PDOStatement|false{if(str_starts_with(ltrim($sql),'INSERT'))$this->updatedId=0;$sql=preg_replace('/ON DUPLICATE KEY UPDATE/i','ON CONFLICT DO UPDATE SET',$sql);$sql=preg_replace('/VALUES\((\w+)\)/i','excluded.$1',$sql);return parent::prepare($sql,$options);}}
function db(){static $pdo;if(!$pdo){$pdo=new TranscriptFixturePDO('sqlite:'.__DIR__.'/../state.sqlite');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->sqliteCreateFunction('LAST_INSERT_ID',function($id)use($pdo){$pdo->updatedId=(int)$id;return$id;});$pdo->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));$pdo->sqliteCreateFunction('UTC_TIMESTAMP',fn()=>gmdate('Y-m-d H:i:s'));$pdo->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,is_active INTEGER)');$pdo->exec('INSERT OR IGNORE INTO users VALUES(1,1),(2,1)');$pdo->exec("CREATE TABLE IF NOT EXISTS chat_conversations(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,user_agent_id INTEGER,artist_workspace_id INTEGER,title TEXT,updated_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);CREATE TABLE IF NOT EXISTS chat_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");reminder_schema_v1($pdo);capability_fixture_agents($pdo);}return $pdo;}
function table_exists($table){if($table==='user_calendar_events'&&isset($_SERVER['HTTP_X_FIXTURE_CALENDAR_MISSING']))return false;$q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return(bool)$q->fetchColumn();}
function column_exists($table,$column){foreach(db()->query('PRAGMA table_info('.$table.')') as $r)if($r['name']===$column)return true;return false;}
function current_user(){if(isset($_SERVER['HTTP_X_FIXTURE_ANONYMOUS']))return null;$id=(int)($_SERVER['HTTP_X_FIXTURE_USER']??1);return ['id'=>$id,'is_active'=>1];}
function csrf_token(){return 'fixture';}
function has_permission($key,$user){return !isset($_SERVER['HTTP_X_FIXTURE_DENIED']);}
function personal_capability_has_v242($key,...$args){return $key==='agent_brain.access'&&isset($_SERVER['HTTP_X_FIXTURE_BRAIN_READY']);}
function artist_workspace_v181_scope_id($user){return 0;}
function calendar_schedule_awareness_snapshot_v1330(...$args){return [];}
function user_account_types_for_user_id(...$args){return ['member'];}
function user_has_role(...$args){return false;}
function vp3_agent_runtime_plan_v420(...$args){return ['route'=>'vp3_cloud','cloud'=>['ready'=>true],'effective_preference'=>'vp3_cloud'];}
function vp3_agent_runtime_tool_plan_v420(...$args){return ['route'=>'vp3_tool'];}
function vp3_agent_runtime_finalize_v420($plan,$execution,...$args){return $execution;}
function vp3_agent_runtime_public_plan_v420($plan){return $plan;}
function ai_usage_accounting_v032_record(...$args){}
function agent_runtime_v125_trace(...$args){}
function agent_runtime_v125_trace_id(){return 'fixture-trace';}
function agent_runtime_v125_span($label,$fn,...$args){return $fn();}
function agent_runtime_v125_idempotency_claim(...$args){return ['claimed'=>true];}
function agent_runtime_v125_idempotency_complete(...$args){}
function track_is_playlist_request($q){return false;}
function track_is_next_request($q){return false;}
function track_media_from_answer(...$args){return [];}
function track_media_suggestions(...$args){return [];}
function track_merge_media_suggestions(...$args){return [];}
function url($path){return $path;}
function setting($key,$default=''){return $default;}
function subscription_has_entitlement($user,$key){return isset($_SERVER['HTTP_X_FIXTURE_PHONE_READY'])&&(int)$user['id']===1;}
function subscription_entitlement_limit(...$args){return 3;}
function agent_brain_schema_ready(){return isset($_SERVER['HTTP_X_FIXTURE_BRAIN_READY']);}
function agent_brain_normalize($s){return strtolower(trim($s));}
function agent_brain_sentences($s){return preg_split('/(?<=[.!?])\s+|\R+/u',trim($s))?:[];}
function agent_background_v125_enqueue(...$args){}
function agent_brain_archive_and_parse(...$args){}
function chat_context_is_internal_source($s){return str_starts_with($s,'runtime:');}
function ai_v100_safe_exception($e,$fallback='Failed'){return $fallback;}
function ai_generate_chat_response($prompt,...$args){
    if(str_starts_with($prompt,'VP3_TRANSCRIPT_PLAN_V1')){
        $json=json_decode(substr($prompt,strrpos($prompt,"\n")+1),true);return ['ok'=>true,'answer'=>json_encode(['query'=>$json['selected_text']??'','operation'=>str_starts_with($json['request'],'Highlight')?'highlight':(str_contains($json['request'],'saved analysis')?'read_analysis':'read'),'session_id'=>(int)($json['selected_session_id']?:21)])];
    }
    if(str_starts_with($prompt,'VP3_CAPABILITY_DISCOVERY_V1')){
        if(isset($_SERVER['HTTP_X_FIXTURE_MODEL_FAIL']))return ['ok'=>false,'error'=>'secret'];
        $json=json_decode(substr($prompt,strrpos($prompt,"\n")+1),true);$q=$json['request'];
        if(in_array($q,['List my phone numbers','List my SMS threads','Read SMS thread 11','Read SMS thread 12'],true))return ['ok'=>true,'answer'=>json_encode(['operation'=>'phone_read','ids'=>[$q==='List my phone numbers'?'phone.numbers.read':'phone.sms.read'],'thread_id'=>str_starts_with($q,'Read SMS thread ')?(int)substr($q,16):0])];
        if($q==='Can you read SMS?')return ['ok'=>true,'answer'=>json_encode(['operation'=>'inspect','ids'=>['phone.sms.read']])];
        $op=(str_starts_with($q,'Remind me')||str_contains($q,'events on my calendar')||str_contains($q,'transcript')||str_contains(strtolower($q),'reminder list')||str_contains(strtolower($q),'my reminders')||str_contains(strtolower($q),'open reminders'))?'continue':(str_starts_with($q,'Did that')?'diagnose':'inspect');$ids=str_contains($q,'HomeServer')?['homeserver.status']:['reminders.manage'];
        if(in_array($op,['continue','diagnose'],true))$ids=[];
        return ['ok'=>true,'answer'=>json_encode(['operation'=>$op,'ids'=>$ids])];
    }
    $json=json_decode(substr($prompt,strrpos($prompt,"\n")+1),true);
    if(isset($_SERVER['HTTP_X_FIXTURE_REMINDER_BAD'])&&(!isset($json['previous_invalid_output'])||$_SERVER['HTTP_X_FIXTURE_REMINDER_BAD']==='always'))return ['ok'=>true,'answer'=>'{"operation":"list","reminders":[]}'];
    if(!str_starts_with($json['request']??'','Remind me'))return ['ok'=>true,'answer'=>json_encode(['operation'=>'list','view'=>str_contains($json['request']??'','today')?'today':'open'])];
    $at=(new DateTimeImmutable('+2 minutes',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s');
    return ['ok'=>true,'answer'=>json_encode(['operation'=>'create','schedule'=>['title'=>'Stretch','timezone'=>'UTC','start_local'=>$at,'recurrence'=>'none','delivery'=>'chat']])];
}

function agent_scheduling_schema_ready_v430(...$args){return false;}

function capability_fixture_agents(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_key TEXT,display_name TEXT,agent_role TEXT,engine_key TEXT,instructions TEXT,is_default INTEGER,is_profile_agent INTEGER,is_active INTEGER,voice_enabled INTEGER,created_at TEXT,updated_at TEXT)");
    $pdo->exec("INSERT OR IGNORE INTO user_agents VALUES(7,1,'longfellow','Longfellow','personal','stonefellow','',1,0,1,1,'',''),(8,1,'other','Other Agent','personal','stonefellow','',0,0,1,1,'',''),(9,2,'foreign','Foreign Agent','personal','stonefellow','',1,0,1,1,'','')");
    foreach(['user_agent_preferences','user_relationships','user_data_policy_audit'] as $t)$pdo->exec("CREATE TABLE IF NOT EXISTS $t(id INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_calendar_events(id INTEGER PRIMARY KEY,owner_user_id INTEGER,created_by_user_id INTEGER,created_by_agent_id INTEGER,title TEXT,description TEXT,location TEXT,start_at_utc TEXT,end_at_utc TEXT,timezone TEXT,all_day INTEGER,recurrence TEXT,agent_notifications INTEGER,source TEXT,source_reference TEXT,status TEXT,cancelled_at TEXT,created_at TEXT,updated_at TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_transcript_sessions_v172(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,conversation_id INTEGER,title TEXT,status TEXT,started_at TEXT,last_activity_at TEXT,metadata_json TEXT,agent_memory_id INTEGER);CREATE TABLE IF NOT EXISTS artist_transcript_segments_v172(id INTEGER PRIMARY KEY,session_id INTEGER,client_segment_key TEXT,segment_index INTEGER,segment_type TEXT,speaker_label TEXT,transcript_text TEXT,started_ms INTEGER,ended_ms INTEGER,confidence REAL,created_at TEXT,updated_at TEXT);INSERT OR IGNORE INTO artist_transcript_sessions_v172 VALUES(21,1,NULL,'Fixture transcript','draft','','','{}',NULL),(22,2,NULL,'SECRET foreign transcript','draft','','','{}',NULL);INSERT OR IGNORE INTO artist_transcript_segments_v172 VALUES(1,21,'fixture',0,'transcript','Dave','The agreed pizza price is twenty dollars.',1000,5000,.9,'',''),(2,22,'foreign',0,'transcript','Other','SECRET transcript body',1000,5000,.9,'','')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_transcript_page_analysis_v237(session_id INTEGER,page_number INTEGER,source_hash TEXT,source_word_count INTEGER,start_segment_index INTEGER,end_segment_index INTEGER,analysis_json TEXT,provider TEXT,model TEXT,generated_at TEXT,PRIMARY KEY(session_id,page_number));CREATE TABLE IF NOT EXISTS artist_transcript_master_analysis_v237(session_id INTEGER PRIMARY KEY,source_hash TEXT,source_word_count INTEGER,page_count INTEGER,analyzed_page_count INTEGER,analysis_json TEXT,research_json TEXT,provider TEXT,model TEXT,generated_at TEXT)");
    $map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,21));$hash=$map['source_hash'];$date=gmdate('c');$analysis=['modules'=>['basic'=>['source_hash'=>$hash,'generated_at'=>$date,'result'=>['summary'=>'Fixture saved AI analysis','key_findings'=>[['text'=>'Fixture finding','item_id'=>'f1','review_state'=>'unreviewed']]]]]];$q=$pdo->prepare("INSERT OR IGNORE INTO artist_transcript_master_analysis_v237 VALUES(?,?,?,?,?,?,?,'fixture','fixture',?)");$q->execute([21,$hash,8,1,0,json_encode($analysis),'{}',$date]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_data_policies(owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER);CREATE TABLE IF NOT EXISTS user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_memory_items(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,user_agent_id INTEGER,memory_type TEXT,subject TEXT,memory_text TEXT,memory_hash TEXT UNIQUE,memory_scope_version INTEGER,source_archive_id INTEGER,confidence REAL,occurrence_count INTEGER,first_seen_at TEXT,last_seen_at TEXT,is_active INTEGER,metadata_json TEXT)");
    $from=gmdate('Y-m-d H:i:s',time()+3600);$to=gmdate('Y-m-d H:i:s',time()+7200);
    $q=$pdo->prepare("INSERT OR IGNORE INTO user_calendar_events VALUES(?, ?, ?, NULL, ?, '', '', ?, ?, 'UTC',0,'none',0,'user','','active',NULL,'','')");
    $q->execute([1,1,1,'Fixture calendar review',$from,$to]);$q->execute([2,2,2,'SECRET foreign calendar',$from,$to]);
    if(isset($_SERVER['HTTP_X_FIXTURE_PHONE_READY']))phone_agent_fixture_v1($pdo);
}
function agent_tool_log(...$args): void{}
function agent_scheduling_timezone_v430(string $value,string $fallback='UTC'):string{return user_calendar_timezone_v1300($value,$fallback);}
