<?php
declare(strict_types=1);
// Infrastructure/model fixtures only. Endpoints, Chat runtime, discovery and reminder executors are production files.
require_once __DIR__.'/cognitive-runtime-v500.php';
require_once __DIR__.'/agent-chat-runtime-v2160.php';
require_once __DIR__.'/agent-surface-context-v131.php';
require_once __DIR__.'/agent-scheduling-tools-v460.php';
require_once __DIR__.'/agent-brain-v122.php';
require_once __DIR__.'/phone-agent-schema-v1.php';
require_once __DIR__.'/workflow-agent-schema-v1.php';
require_once __DIR__.'/record-agent-schema-v1.php';
require_once __DIR__.'/agent-brain-owner-context-v1.php';
require_once __DIR__.'/agent-memory-lifecycle-v123.php';
require_once __DIR__.'/agent-task-lifecycle-v123.php';
require_once __DIR__.'/homeserver-local-execution-v230.php';
require_once __DIR__.'/homeserver-agent-read-v230.php';
require_once __DIR__.'/system-apps-v330.php';
require_once __DIR__.'/media-agent-schema-v1.php';
require_once __DIR__.'/media-studio-v86.php';
require_once __DIR__.'/app-agent-remote-v1.php';
require_once __DIR__.'/system-app-agent-schema-v1.php';
require_once __DIR__.'/app-operation-schema-v1.php';
require_once __DIR__.'/private-share-agent-v1.php';
class TranscriptFixturePDO extends PDO {public int $updatedId=0;public function lastInsertId(?string $name=null):string|false{return $this->updatedId?(string)$this->updatedId:parent::lastInsertId($name);}public function prepare(string $sql,array $options=[]):PDOStatement|false{if(str_starts_with(ltrim($sql),'INSERT'))$this->updatedId=0;$sql=str_replace(' FOR UPDATE','',$sql);$sql=preg_replace('/DATE_ADD\(UTC_TIMESTAMP\(\),INTERVAL (\d+) MINUTE\)/i',"datetime('now','+$1 minutes')",$sql);$sql=str_replace('IF(','IIF(',$sql);if(str_contains($sql,'information_schema.statistics'))$sql="SELECT 1 FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?";$sql=preg_replace('/ON DUPLICATE KEY UPDATE/i','ON CONFLICT DO UPDATE SET',$sql);$sql=preg_replace('/VALUES\((\w+)\)/i','excluded.$1',$sql);return parent::prepare($sql,$options);}}
function db(){static $pdo;if(!$pdo){$pdo=new TranscriptFixturePDO('sqlite:'.__DIR__.'/../state.sqlite');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->sqliteCreateFunction('LAST_INSERT_ID',function($id)use($pdo){$pdo->updatedId=(int)$id;return$id;});$pdo->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));$pdo->sqliteCreateFunction('UTC_TIMESTAMP',fn()=>gmdate('Y-m-d H:i:s'));$pdo->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,is_active INTEGER)');$pdo->exec('INSERT OR IGNORE INTO users(id,is_active) VALUES(1,1),(2,1)');$pdo->exec("CREATE TABLE IF NOT EXISTS chat_conversations(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,user_agent_id INTEGER,artist_workspace_id INTEGER,title TEXT,updated_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);CREATE TABLE IF NOT EXISTS chat_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");reminder_schema_v1($pdo);capability_fixture_agents($pdo);vp3_task_lists_schema_v1($pdo);}return $pdo;}
function table_exists($table){if($table==='user_calendar_events'&&isset($_SERVER['HTTP_X_FIXTURE_CALENDAR_MISSING']))return false;$q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return(bool)$q->fetchColumn();}
function column_exists($table,$column){foreach(db()->query('PRAGMA table_info('.$table.')') as $r)if($r['name']===$column)return true;return false;}
function current_user(){if(isset($_SERVER['HTTP_X_FIXTURE_ANONYMOUS']))return null;$id=(int)($_SERVER['HTTP_X_FIXTURE_USER']??1);return ['id'=>$id,'is_active'=>1];}
function csrf_token(){return 'fixture';}
function has_permission($key,$user){return !isset($_SERVER['HTTP_X_FIXTURE_DENIED']);}
function personal_capability_has_v242($key,...$args){return ($key==='agent_brain.access'&&isset($_SERVER['HTTP_X_FIXTURE_BRAIN_READY']))||($key==='personal_knowledge.access'&&isset($_SERVER['HTTP_X_FIXTURE_RECORD_READY']));}
function artist_workspace_v181_scope_id($user){return 0;}
function calendar_schedule_awareness_snapshot_v1330(...$args){if(isset($_SERVER['HTTP_X_FIXTURE_OWNER_TRAP']))throw new RuntimeException('OWNER_READ_REACHED');return [];}
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
function member_navigation_menu_links($u){return [['key'=>'contacts','label'=>'Contacts','url'=>'/contacts.php']];}
function music_workspace_resources_v330_can_manage_track($pdo,$t,$u){return (int)$t['owner_user_id']===(int)$u['id'];}
function music_workspace_resources_v330_track_workspace_id($pdo,$t){return 0;}
function setting($key,$default=''){return $default;}
function subscription_has_entitlement($user,$key){return (isset($_SERVER['HTTP_X_FIXTURE_PHONE_READY'])||isset($_SERVER['HTTP_X_FIXTURE_IMAGES_READY']))&&(int)$user['id']===1;}
function subscription_entitlement_limit(...$args){return 3;}
function homeserver_execution_v220_can_route(...$args){return isset($_SERVER['HTTP_X_FIXTURE_HOME_READY']);}
function homeserver_https_v1300_status($uid){return ['paired'=>isset($_SERVER['HTTP_X_FIXTURE_MEDIA_READY'])||isset($_SERVER['HTTP_X_FIXTURE_APP_READY'])||isset($_SERVER['HTTP_X_FIXTURE_SYSTEM_APP_READY'])||isset($_SERVER['HTTP_X_FIXTURE_OPERATION_READY'])];}
function homeserver_https_v1300_remote_operation(...$args){if(isset($_SERVER['HTTP_X_FIXTURE_OPERATION_READY']))return app_operation_remote_v1(...$args);if(($args[1]??'')==='apps.system.permissions.status'&&isset($_SERVER['HTTP_X_FIXTURE_SYSTEM_APP_READY']))return system_app_agent_remote_v1(...$args);if(in_array($args[1]??'',['apps.user.list','apps.manager.status','apps.user.workspace.status'],true)&&isset($_SERVER['HTTP_X_FIXTURE_APP_READY']))return app_agent_remote_fixture_v1(...$args);if(str_starts_with($args[1]??'','apps.'))return media_fixture_remote_v1(...$args);if(isset($_SERVER['HTTP_X_FIXTURE_HOME_FAIL']))throw new RuntimeException('HomeServer offline');return ['items'=>[['name'=>'Fixture local file','ref'=>'hsf-1-0123456789abcdef','snippet'=>'Fixture local source']]];}
function agent_brain_schema_ready(){return isset($_SERVER['HTTP_X_FIXTURE_BRAIN_READY']);}
function agent_brain_normalize($s){return strtolower(trim($s));}
function agent_brain_extract_dates($s){return [];}
function agent_brain_sentences($s){return preg_split('/(?<=[.!?])\s+|\R+/u',trim($s))?:[];}
function agent_background_v125_enqueue(...$args){}
function agent_brain_archive_and_parse(...$args){}
function chat_context_is_internal_source($s){return str_starts_with($s,'runtime:');}
function ai_v100_safe_exception($e,$fallback='Failed'){return $fallback;}
function ai_generate_chat_response($prompt,...$args){
    if(str_starts_with($prompt,'VP3_PARTICIPANT_TASKS_V1')){file_put_contents(__DIR__.'/../participant-prompt.txt',$prompt);return ['ok'=>true,'answer'=>$_SERVER['HTTP_X_FIXTURE_PARTICIPANT_PLAN']??'{"operation":"show","kind":"all","id":0}'];}
    if(!str_starts_with($prompt,'VP3_CAPABILITY_DISCOVERY_V1')&&str_contains($prompt,'personal reminder commands.')&&str_contains($prompt,'Show reminders'))return ['ok'=>true,'answer'=>'{"operation":"list","view":"open"}'];
    if(str_starts_with($prompt,'VP3_TRANSCRIPT_ANSWER_V1')){
        $data=json_decode(substr($prompt,strpos($prompt,"\n")+1),true);$segments=$data['evidence']['segments'];return ['ok'=>true,'answer'=>json_encode(['answer'=>'The discussion covers the project budget and delivery date.','citations'=>[(int)$segments[0]['id']]])];
    }

    if(str_starts_with($prompt,'VP3_TRANSCRIPT_PLAN_V1')){
        $json=json_decode(substr($prompt,strrpos($prompt,"\n")+1),true);
        if($json['request']==='Summarize the transcription titled jcal')return ['ok'=>true,'answer'=>'{"operation":"summarize","title":"jcal"}'];
        if($json['request']==='Find the transcription titled jcal')return ['ok'=>true,'answer'=>'{"operation":"list","query":"jcal"}'];
        if($json['request']==='Can you read transcripts?')return ['ok'=>true,'answer'=>'{"operation":"none"}'];
        if($json['request']==='Detach this transcript')return ['ok'=>true,'answer'=>'{"operation":"detach"}'];
        if(in_array($json['request'],['Tell me about this transcription','What did they say about the budget?','What were the main issues?'],true))return ['ok'=>true,'answer'=>json_encode($json['selected_session_id']?['operation'=>'discuss']:['operation'=>'clarify','question'=>'Which transcript should I use?'])];
        if($json['request']==='The first one')return ['ok'=>true,'answer'=>'{"operation":"read","session_id":23}'];if(str_contains($json['request'],'October 30th'))return ['ok'=>true,'answer'=>'{"operation":"read","date":"2026-10-30"}'];return ['ok'=>true,'answer'=>json_encode(['query'=>$json['selected_text']??'','operation'=>str_starts_with($json['request'],'Highlight')?'highlight':(str_contains($json['request'],'saved analysis')?'read_analysis':'read'),'session_id'=>(int)((str_contains($json['request'],'selected')?($json['ui_selected_session_id']??0):0)?:$json['selected_session_id']?:21)])];
    }
    if(str_starts_with($prompt,'VP3_CAPABILITY_DISCOVERY_V1')){
        if(isset($_SERVER['HTTP_X_FIXTURE_PARTICIPANT_OWNER_PLAN']))return ['ok'=>true,'answer'=>$_SERVER['HTTP_X_FIXTURE_PARTICIPANT_OWNER_PLAN']];
        if(isset($_SERVER['HTTP_X_FIXTURE_MODEL_FAIL']))return ['ok'=>false,'error'=>'secret'];
        $json=json_decode(substr($prompt,strrpos($prompt,"\n")+1),true);$q=$json['request'];
        if($q==='Learn my voice')return ['ok'=>true,'answer'=>json_encode(['operation'=>'voice_profile','ids'=>['voice.profiles.enroll'],'action'=>'prepare'])];
        if($q==='Load from the asteroid you saved me')return ['ok'=>true,'answer'=>json_encode(['operation'=>'navigate','ids'=>['navigation.open'],'action'=>'open','destination'=>'stem_studio','query'=>'From the asteroid you saved me'])];
        if($q==='Offer to open that track in Stem Studio')return ['ok'=>true,'answer'=>json_encode(['operation'=>'navigate','ids'=>['navigation.open'],'action'=>'prepare','destination'=>'stem_studio','track_id'=>9])];
        if($q==='Yes open it')return ['ok'=>true,'answer'=>json_encode(['operation'=>'navigate','ids'=>['navigation.open'],'action'=>'confirm'])];
        if($q==='Open forbidden Studio track')return ['ok'=>true,'answer'=>json_encode(['operation'=>'navigate','ids'=>['navigation.open'],'action'=>'open','destination'=>'stem_studio','track_id'=>10])];
        if($q==='Open contacts')return ['ok'=>true,'answer'=>json_encode(['operation'=>'navigate','ids'=>['navigation.open'],'action'=>'open','destination'=>'contacts'])];
        $taskPlan=null;
        if(preg_match('/^Add (.+) to my (.+) list$/i',$q,$m))$taskPlan=['action'=>'add','list_name'=>$m[2],'title'=>$m[1]];
        elseif(preg_match('/^Save (.+) as a task$/i',$q,$m))$taskPlan=['action'=>'create_task','title'=>$m[1]];
        elseif(preg_match('/^Show (.+) list$/i',$q,$m))$taskPlan=['action'=>'show','list_name'=>$m[1]];
        elseif($q==='Show my lists')$taskPlan=['action'=>'show_lists'];
        elseif(in_array($q,['Yes create it','Yes save it'],true)&&!empty($json['pending_task_list']))$taskPlan=['action'=>'confirm'];
        elseif($q==='No cancel the list'&&!empty($json['pending_task_list']))$taskPlan=['action'=>'cancel'];
        if($taskPlan)return ['ok'=>true,'answer'=>json_encode(['operation'=>'task_list','ids'=>['tasks.lists']]+$taskPlan)];
        if($q==='Show reminders')return ['ok'=>true,'answer'=>'{"operation":"continue","ids":[]}'];
        if($q==='What were the main issues?')return ['ok'=>true,'answer'=>'{"operation":"transcript_read","ids":["transcripts.read"]}'];
        if(in_array($q,['Tell me about this transcription','Can you read transcripts?'],true))return ['ok'=>true,'answer'=>'{"operation":"inspect","ids":["transcripts.read"]}'];
        if(in_array($q,['What did they say about the budget?','Detach this transcript'],true))return ['ok'=>true,'answer'=>'{"operation":"continue","ids":[]}'];
        if($q==='The first one')return ['ok'=>true,'answer'=>'{"operation":"continue","ids":[]}'];
        if($q==='Do we have a list tool yet')return ['ok'=>true,'answer'=>'{"operation":"inspect","ids":["capabilities.inspect"]}'];
        if($q==='Can you install System Apps?')return ['ok'=>true,'answer'=>'{"operation":"inspect","ids":["system_apps.manage"]}'];
        if($q==='Install System App vp3.notes')return ['ok'=>true,'answer'=>'{"operation":"system_apps_action","ids":["system_apps.manage"],"action":"install","app_key":"vp3.notes"}'];
        if(preg_match('/^Confirm app ([A-Z2-9]{8})$/',$q,$m))return ['ok'=>true,'answer'=>json_encode(['operation'=>'system_apps_action','ids'=>['system_apps.manage'],'action'=>'confirm','code'=>$m[1]])];
        if($q==='Inspect app controls user.notes')return ['ok'=>true,'answer'=>'{"operation":"app_operation_read","ids":["apps.control.read"],"app_key":"user.notes"}'];
        if($q==='Inspect System App release vp3.notes')return ['ok'=>true,'answer'=>'{"operation":"app_operation_read","ids":["system_apps.release.read"],"app_key":"vp3.notes"}'];
        if($q==='List Cloud System App catalog')return ['ok'=>true,'answer'=>'{"operation":"system_apps_read","ids":["system_apps.catalog.read"]}'];
        if($q==='Read stored System App health')return ['ok'=>true,'answer'=>'{"operation":"system_apps_read","ids":["system_apps.health.read"]}'];
        if(str_starts_with($q,'Read System App permissions for '))return ['ok'=>true,'answer'=>json_encode(['operation'=>'system_apps_read','ids'=>['system_apps.permissions.read'],'app_key'=>substr($q,strlen('Read System App permissions for '))])];
        if($q==='Can you read System App permissions?')return ['ok'=>true,'answer'=>'{"operation":"inspect","ids":["system_apps.permissions.read"]}'];
        if($q==='List saved HomeServer user apps')return ['ok'=>true,'answer'=>'{"operation":"apps_read","ids":["apps.user.read"]}'];
        if($q==='List installed HomeServer apps')return ['ok'=>true,'answer'=>'{"operation":"apps_read","ids":["apps.manager.read"],"view":"installed"}'];
        if(str_starts_with($q,'Inspect workspace '))return ['ok'=>true,'answer'=>json_encode(['operation'=>'apps_read','ids'=>['apps.workspace.read'],'app_key'=>substr($q,strlen('Inspect workspace '))])];
        if($q==='Can you inspect my apps?')return ['ok'=>true,'answer'=>'{"operation":"inspect","ids":["apps.user.read","apps.manager.read","apps.workspace.read"]}'];
        if($q==='Read saved TV history')return ['ok'=>true,'answer'=>'{"operation":"media_read","ids":["tv.history.read"]}'];
        if($q==='Read saved TV preferences')return ['ok'=>true,'answer'=>'{"operation":"media_read","ids":["tv.preferences.read"]}'];
        if($q==='Search local Music Server library')return ['ok'=>true,'answer'=>'{"operation":"media_read","ids":["music.library.read"],"view":"search","query":"fixture"}'];
        if($q==='Search local Media Server library')return ['ok'=>true,'answer'=>'{"operation":"media_read","ids":["media.library.read"],"view":"search","query":"fixture","media_type":"video"}'];
        if($q==='Can you play music on HomeServer?')return ['ok'=>true,'answer'=>'{"operation":"inspect","ids":["music.library.read"]}'];
        if(in_array($q,['List my phone numbers','List my SMS threads','Read SMS thread 11','Read SMS thread 12'],true))return ['ok'=>true,'answer'=>json_encode(['operation'=>'phone_read','ids'=>[$q==='List my phone numbers'?'phone.numbers.read':'phone.sms.read'],'thread_id'=>str_starts_with($q,'Read SMS thread ')?(int)substr($q,16):0])];
        if($q==='Search saved Knowledge for pizza')return ['ok'=>true,'answer'=>json_encode(['operation'=>'record_read','ids'=>['knowledge.search'],'query'=>'pizza'])];
        if($q==='Search saved Brain for pizza')return ['ok'=>true,'answer'=>json_encode(['operation'=>'record_read','ids'=>['brain.read'],'query'=>'pizza'])];
        if($q==='List saved Brain tasks')return ['ok'=>true,'answer'=>json_encode(['operation'=>'record_read','ids'=>['tasks.read'],'query'=>''])];
        if(str_starts_with($q,'Complete saved Brain task #'))return ['ok'=>true,'answer'=>json_encode(['operation'=>'record_task','ids'=>['tasks.manage'],'task_id'=>(int)substr($q,strlen('Complete saved Brain task #')),'task_status'=>'completed'])];
        if($q==='Search my HomeServer files for fixture')return ['ok'=>true,'answer'=>'{"operation":"continue","ids":[]}'];
        if($q==='Can you read SMS?')return ['ok'=>true,'answer'=>json_encode(['operation'=>'inspect','ids'=>['phone.sms.read']])];
        $op=(str_starts_with($q,'Remind me')||str_contains($q,'events on my calendar')||str_contains($q,'transcript')||str_contains(strtolower($q),'reminder list')||str_contains(strtolower($q),'my reminders')||str_contains(strtolower($q),'open reminders'))?'continue':(str_starts_with($q,'Did that')?'diagnose':'inspect');$ids=str_contains($q,'HomeServer')?['homeserver.status']:['reminders.manage'];
        if(str_contains($q,'workflow'))$op='continue';if(in_array($op,['continue','diagnose'],true))$ids=[];
        return ['ok'=>true,'answer'=>json_encode(['operation'=>$op,'ids'=>$ids])];
    }
    $json=json_decode(substr($prompt,strrpos($prompt,"\n")+1),true);
    if(str_contains($json['request']??'','workflow')||($json['request']??'')==='Search my HomeServer files for fixture')return ['ok'=>true,'answer'=>'{"operation":"none"}'];
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_transcript_sessions_v172(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,conversation_id INTEGER,title TEXT,status TEXT,started_at TEXT,last_activity_at TEXT,metadata_json TEXT,agent_memory_id INTEGER);CREATE TABLE IF NOT EXISTS artist_transcript_segments_v172(id INTEGER PRIMARY KEY,session_id INTEGER,client_segment_key TEXT,segment_index INTEGER,segment_type TEXT,speaker_label TEXT,transcript_text TEXT,started_ms INTEGER,ended_ms INTEGER,confidence REAL,created_at TEXT,updated_at TEXT);INSERT OR IGNORE INTO artist_transcript_sessions_v172 VALUES(21,1,NULL,'Fixture transcript','draft','2026-10-30 15:00:00','2026-10-30 15:05:00','{}',NULL),(22,2,NULL,'SECRET foreign transcript','draft','2026-10-30 15:00:00','2026-10-30 15:05:00','{}',NULL);INSERT OR IGNORE INTO artist_transcript_segments_v172 VALUES(1,21,'fixture',0,'transcript','Dave','The agreed pizza price is twenty dollars.',1000,5000,.9,'',''),(2,22,'foreign',0,'transcript','Other','SECRET transcript body',1000,5000,.9,'','')");
    $pdo->exec("INSERT OR IGNORE INTO artist_transcript_sessions_v172 VALUES(23,1,NULL,'Phoenix previous day','draft','2026-10-30 01:00:00','2026-10-30 01:05:00','{}',NULL);INSERT OR IGNORE INTO artist_transcript_segments_v172 VALUES(3,23,'midnight',0,'transcript','Dave','A different local recording day.',1000,5000,.9,'','')");
    $pdo->exec("INSERT OR IGNORE INTO artist_transcript_sessions_v172 VALUES(24,1,NULL,'jcal-sept-2','draft','2026-09-02 12:12:41','','{}',NULL);INSERT OR IGNORE INTO artist_transcript_segments_v172 VALUES(4,24,'jcal',0,'transcript','Alice','The project budget is ten thousand dollars and delivery is Friday.',1000,5000,.9,'','')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_transcript_page_analysis_v237(session_id INTEGER,page_number INTEGER,source_hash TEXT,source_word_count INTEGER,start_segment_index INTEGER,end_segment_index INTEGER,analysis_json TEXT,provider TEXT,model TEXT,generated_at TEXT,PRIMARY KEY(session_id,page_number));CREATE TABLE IF NOT EXISTS artist_transcript_master_analysis_v237(session_id INTEGER PRIMARY KEY,source_hash TEXT,source_word_count INTEGER,page_count INTEGER,analyzed_page_count INTEGER,analysis_json TEXT,research_json TEXT,provider TEXT,model TEXT,generated_at TEXT)");
    $map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,21));$hash=$map['source_hash'];$date=gmdate('c');$analysis=['modules'=>['basic'=>['source_hash'=>$hash,'generated_at'=>$date,'result'=>['summary'=>'Fixture saved AI analysis','key_findings'=>[['text'=>'Fixture finding','item_id'=>'f1','review_state'=>'unreviewed']]]]]];$q=$pdo->prepare("INSERT OR IGNORE INTO artist_transcript_master_analysis_v237 VALUES(?,?,?,?,?,?,?,'fixture','fixture',?)");$q->execute([21,$hash,8,1,0,json_encode($analysis),'{}',$date]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_data_policies(owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER);CREATE TABLE IF NOT EXISTS user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_memory_items(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,user_agent_id INTEGER,memory_type TEXT,subject TEXT,memory_text TEXT,memory_hash TEXT UNIQUE,memory_scope_version INTEGER,source_archive_id INTEGER,confidence REAL,occurrence_count INTEGER,first_seen_at TEXT,last_seen_at TEXT,is_active INTEGER,metadata_json TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracks(id INTEGER PRIMARY KEY,title TEXT,owner_user_id INTEGER,producer_user_id INTEGER);INSERT OR IGNORE INTO tracks VALUES(9,'From the asteroid you saved me',1,NULL),(10,'SECRET foreign track',2,NULL)");
    $from=gmdate('Y-m-d H:i:s',time()+3600);$to=gmdate('Y-m-d H:i:s',time()+7200);
    $q=$pdo->prepare("INSERT OR IGNORE INTO user_calendar_events VALUES(?, ?, ?, NULL, ?, '', '', ?, ?, 'UTC',0,'none',0,'user','','active',NULL,'','')");
    $q->execute([1,1,1,'Fixture calendar review',$from,$to]);$q->execute([2,2,2,'SECRET foreign calendar',$from,$to]);
    if(isset($_SERVER['HTTP_X_FIXTURE_PHONE_READY']))phone_agent_fixture_v1($pdo);
    if(isset($_SERVER['HTTP_X_FIXTURE_WORK_READY']))workflow_agent_fixture_v1($pdo);
    if(isset($_SERVER['HTTP_X_FIXTURE_IMAGES_READY']))image_capability_fixture_v1($pdo);
    if(isset($_SERVER['HTTP_X_FIXTURE_RECORD_READY']))record_agent_fixture_v1($pdo);
    if(isset($_SERVER['HTTP_X_FIXTURE_MEDIA_READY']))media_agent_fixture_v1($pdo);
    if(isset($_SERVER['HTTP_X_FIXTURE_SYSTEM_APP_READY'])||isset($_SERVER['HTTP_X_FIXTURE_OPERATION_READY']))system_app_agent_fixture_v1($pdo);
    if(isset($_SERVER['HTTP_X_FIXTURE_OPERATION_READY']))app_operation_fixture_v1($pdo);
    if(isset($_SERVER['HTTP_X_FIXTURE_PRIVATE_SHARE_READY'])){
        if(!column_exists('users','display_name'))$pdo->exec("ALTER TABLE users ADD COLUMN display_name TEXT DEFAULT '';");
        $pdo->exec("UPDATE users SET display_name=CASE id WHEN 1 THEN 'Owner' WHEN 2 THEN 'Sender' ELSE display_name END;INSERT OR IGNORE INTO users(id,is_active,display_name) VALUES(3,1,'Other recipient')");
        if(!table_exists('vp3_user_app_private_shares_v240'))private_share_agent_fixture_v1($pdo);
        $pdo->exec("UPDATE vp3_user_app_private_shares_v240 SET status='accepted' WHERE id=1");
        if(($_SERVER['HTTP_X_FIXTURE_PRIVATE_SHARE_MODE']??'')==='invalid_status')$pdo->exec("UPDATE vp3_user_app_private_shares_v240 SET status='SECRET_INVALID_STATE' WHERE id=1");
    }
}
function agent_tool_log(...$args): void{}
function agent_scheduling_timezone_v430(string $value,string $fallback='UTC'):string{return user_calendar_timezone_v1300($value,$fallback);}

function ai_provider_api_key($provider): string{return isset($_SERVER['HTTP_X_FIXTURE_IMAGES_READY'])?'fixture-image-key':'';}
function ai_provider_enabled($provider): bool{return isset($_SERVER['HTTP_X_FIXTURE_IMAGES_READY']);}
function image_capability_fixture_v1(PDO $pdo): void{
    if(!defined('STONEFELLOW_ROOT'))define('STONEFELLOW_ROOT',dirname(__DIR__));
    workflow_agent_fixture_v1($pdo);
    foreach(['capability_key TEXT','action_key TEXT','action_type TEXT','label TEXT','summary TEXT','attempt_count INTEGER DEFAULT 0','result_summary TEXT','result_json TEXT','error_class TEXT','started_at TEXT','completed_at TEXT','updated_at TEXT'] as $c){$name=explode(' ',$c)[0];if(!column_exists('agent_workflow_actions',$name))$pdo->exec('ALTER TABLE agent_workflow_actions ADD COLUMN '.$c);}
    foreach(['run_id INTEGER','owner_user_id INTEGER','action_id INTEGER','depends_on_action_id INTEGER'] as $c){$name=explode(' ',$c)[0];if(!column_exists('agent_workflow_action_dependencies',$name))$pdo->exec('ALTER TABLE agent_workflow_action_dependencies ADD COLUMN '.$c);}
    foreach(['receipt_key TEXT','result_json TEXT'] as $c){$name=explode(' ',$c)[0];if(!column_exists('agent_workflow_receipts',$name))$pdo->exec('ALTER TABLE agent_workflow_receipts ADD COLUMN '.$c);}
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_media_assets(id INTEGER PRIMARY KEY,user_id INTEGER,media_type TEXT,title TEXT,mime_type TEXT,file_path TEXT,file_size INTEGER,duration_seconds REAL,source TEXT,metadata_json TEXT,created_at TEXT,updated_at TEXT);CREATE TABLE IF NOT EXISTS video_editor_projects(id INTEGER PRIMARY KEY,user_id INTEGER);INSERT OR IGNORE INTO user_media_assets(id,user_id,media_type,title) VALUES(1,1,'photo','Fixture uploaded sunset'),(2,2,'photo','SECRET foreign photo')");
}
