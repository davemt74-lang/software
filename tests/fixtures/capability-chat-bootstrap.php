<?php
declare(strict_types=1);
// Infrastructure/model fixtures only. Endpoints, Chat runtime, discovery and reminder executors are production files.
require_once __DIR__.'/cognitive-runtime-v500.php';
require_once __DIR__.'/agent-chat-runtime-v2160.php';
require_once __DIR__.'/agent-surface-context-v131.php';
function db(){static $pdo;if(!$pdo){$pdo=new PDO('sqlite:'.__DIR__.'/../state.sqlite');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));$pdo->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,is_active INTEGER)');$pdo->exec('INSERT OR IGNORE INTO users VALUES(1,1),(2,1)');$pdo->exec("CREATE TABLE IF NOT EXISTS chat_conversations(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,user_agent_id INTEGER,artist_workspace_id INTEGER,title TEXT,updated_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);CREATE TABLE IF NOT EXISTS chat_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");reminder_schema_v1($pdo);}return $pdo;}
function table_exists($table){$q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return(bool)$q->fetchColumn();}
function column_exists($table,$column){foreach(db()->query('PRAGMA table_info('.$table.')') as $r)if($r['name']===$column)return true;return false;}
function current_user(){if(isset($_SERVER['HTTP_X_FIXTURE_ANONYMOUS']))return null;$id=(int)($_SERVER['HTTP_X_FIXTURE_USER']??1);return ['id'=>$id,'is_active'=>1];}
function csrf_token(){return 'fixture';}
function has_permission($key,$user){return !isset($_SERVER['HTTP_X_FIXTURE_DENIED']);}
function personal_capability_has_v242(...$args){return false;}
function user_agent_system_schema_ready_v236($pdo){return true;}
function user_agent_principal_v236($user,$agent){return ['kind'=>'system','viewer_user_id'=>$user['id'],'owner_user_id'=>$user['id'],'display_name'=>'Fixture Agent','agent_id'=>0];}
function artist_workspace_v181_scope_id($user){return 0;}
function calendar_schedule_awareness_snapshot_v1330(...$args){return [];}
function user_account_types_for_user_id(...$args){return ['member'];}
function user_agents_list_v236(...$args){return [];}
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
function agent_brain_schema_ready(){return false;}
function agent_brain_archive_and_parse(...$args){}
function chat_context_is_internal_source($s){return str_starts_with($s,'runtime:');}
function ai_v100_safe_exception($e,$fallback='Failed'){return $fallback;}
function ai_generate_chat_response($prompt,...$args){
    if(str_starts_with($prompt,'VP3_CAPABILITY_DISCOVERY_V1')){
        if(isset($_SERVER['HTTP_X_FIXTURE_MODEL_FAIL']))return ['ok'=>false,'error'=>'secret'];
        $json=json_decode(substr($prompt,strrpos($prompt,"\n")+1),true);$q=$json['request'];
        $op=str_starts_with($q,'Remind me')?'continue':(str_starts_with($q,'Did that')?'diagnose':'inspect');$ids=str_contains($q,'HomeServer')?['homeserver.status']:['reminders.manage'];
        if(in_array($op,['continue','diagnose'],true))$ids=[];
        return ['ok'=>true,'answer'=>json_encode(['operation'=>$op,'ids'=>$ids])];
    }
    $at=(new DateTimeImmutable('+2 minutes',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s');
    return ['ok'=>true,'answer'=>json_encode(['operation'=>'create','schedule'=>['title'=>'Stretch','timezone'=>'UTC','start_local'=>$at,'recurrence'=>'none','delivery'=>'chat']])];
}

function agent_scheduling_schema_ready_v430(...$args){return false;}
