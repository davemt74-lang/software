<?php
declare(strict_types=1);

require_once __DIR__.'/homeserver-scope-v026.php';
require_once __DIR__.'/homeserver-vp3.php';
require_once __DIR__.'/homeserver-agent-v018.php';
if(!defined('STONEFELLOW_ROOT'))define('STONEFELLOW_ROOT',dirname(__DIR__));

// Disposable account/SQL/provider infrastructure only. Domain admission,
// shared capability execution, leases, receipts, Chat and Brain are production.
function capabilities_browser_schema_v1(): void
{
    $pdo=db();
    if(table_exists('capabilities_browser_state'))return;
    $pdo->exec("CREATE TABLE capabilities_browser_state(key TEXT PRIMARY KEY,value TEXT);
        CREATE TABLE IF NOT EXISTS agent_compute_preferences(user_id INTEGER PRIMARY KEY,preference TEXT);
        CREATE TABLE IF NOT EXISTS agent_compute_overrides(user_id INTEGER,agent_id INTEGER,preference TEXT);
        CREATE TABLE IF NOT EXISTS homeserver_connections(user_id INTEGER PRIMARY KEY,status TEXT,capabilities_json TEXT,
            relay_token_enc TEXT,homeserver_token_enc TEXT,device_id TEXT);
        CREATE TABLE IF NOT EXISTS video_meetings(id INTEGER PRIMARY KEY,owner_user_id INTEGER,organizer_agent_id INTEGER,
            public_id TEXT,room_name TEXT,agent_mode TEXT,title TEXT);
        CREATE TABLE IF NOT EXISTS video_meeting_participants(id INTEGER,invite_token TEXT);
        CREATE TABLE IF NOT EXISTS video_meeting_transcript_segments(id INTEGER);
        CREATE TABLE IF NOT EXISTS video_meeting_artifacts(id INTEGER)");
}

function capabilities_browser_value_v1(string $key,mixed $value=null,bool $write=false): mixed
{
    if($write){
        db()->prepare('INSERT INTO capabilities_browser_state(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value')
            ->execute([$key,json_encode($value,JSON_THROW_ON_ERROR)]);return $value;
    }
    $q=db()->prepare('SELECT value FROM capabilities_browser_state WHERE key=?');$q->execute([$key]);$value=$q->fetchColumn();
    return $value===false?null:json_decode($value,true,32,JSON_THROW_ON_ERROR);
}

/** This is the sole remote scope transport replacement; the privacy guard is real. */
function homeserver_scope_v026_fetch(int $owner,bool $force=false,bool $readOnly=false,?PDO $pdo=null): array
{
    if(!$readOnly||$pdo!==db())throw new RuntimeException('Meeting policy did not retain the readonly source PDO.');
    if($pdo->inTransaction())throw new RuntimeException('Remote policy transport ran inside a transaction.');
    if(!$force)throw new RuntimeException('Meeting policy did not force a current scope read.');
    if(!homeserver_scope_v026_supported($owner,true,$pdo))throw new RuntimeException('Meeting scope capability metadata was not canonical.');
    $credentials=homeserver_agent_v018_credentials($owner,true,$pdo);
    if(($credentials['transport']??'')!=='custom_websocket'
        ||($credentials['home']??'')!=='browser-disposable-home-credential-1234567890'
        ||($credentials['relay']??'')!=='browser-disposable-relay-credential-1234567890')
        throw new RuntimeException('Meeting policy did not validate the stored encrypted fixture credentials.');
    followthrough_fixture_log_v1('remote_scope',['request_id'=>(string)($_SERVER['HTTP_X_FIXTURE_REQUEST_ID']??''),
        'owner'=>$owner,'force'=>$force,'readonly'=>$readOnly,'pdo_matches'=>$pdo===db(),
        'credential_transport'=>$credentials['transport'],'in_transaction'=>$pdo->inTransaction()]);
    return ['version'=>'v0.26','supported'=>true,'available'=>true,'reason'=>'scope_ready',
        'scope'=>homeserver_scope_v026_normalize(['cloud_allowed'=>!capabilities_browser_value_v1('block_cloud')]),
        'last_known_cloud_blocked'=>false];
}

function capabilities_browser_model_v1(string $prompt,string $protocol): ?array
{
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    if($protocol==='vp3.initiative-reply.v1'){
        followthrough_fixture_log_v1('initiative_model',['protocol'=>$protocol,'prompt'=>$prompt]);
        return ['ok'=>true,'answer'=>json_encode(['intent'=>(string)($_SERVER['HTTP_X_FIXTURE_FOLLOWTHROUGH_INTENT']??'discuss'),
            'focus'=>'Discuss the verified saved account record and its private next step.'],JSON_THROW_ON_ERROR)];
    }
    if($protocol==='vp3.initiative-draft.v1'&&($input['response_kind']??'')==='followthrough_reply'){
        if(db()->inTransaction())throw new RuntimeException('Owner reply model held a transaction.');
        followthrough_fixture_log_v1('main_model',['protocol'=>$protocol,'prompt'=>$prompt]);
        return ['ok'=>true,'answer'=>json_encode(['draft'=>'We can work through the decision in that verified private note. I have kept the original record and proposed next step separate from any business change.'],JSON_THROW_ON_ERROR)];
    }
    if($protocol!=='vp3.initiative-plan.v1'&&($protocol!=='vp3.initiative-draft.v1'||($input['response_kind']??'')==='followthrough_reply'))return null;
    if(db()->inTransaction())throw new RuntimeException('Capability inference held a transaction.');
    followthrough_fixture_log_v1('capability_model',['request_id'=>(string)($_SERVER['HTTP_X_FIXTURE_REQUEST_ID']??''),
        'protocol'=>$protocol,'prompt'=>$prompt,'in_transaction'=>false]);
    $tool=(string)capabilities_browser_value_v1('tool');$ref=(string)capabilities_browser_value_v1('ref');
    $sentinel=(string)capabilities_browser_value_v1('sentinel');
    if(str_contains($prompt,'SECRET'))throw new RuntimeException('The capability model received foreign private data.');
    if($protocol==='vp3.initiative-plan.v1'){
        $entry=$input['tools'][$tool]??[];
        if(empty($entry['ready'])||!isset($entry['tool_contract'])||!in_array($ref,$entry['source_refs']??[],true)
            ||str_contains(json_encode($input['evidence'],JSON_THROW_ON_ERROR),$sentinel))
            throw new RuntimeException('The real capability catalog or situation preview was incorrect.');
        return ['ok'=>true,'answer'=>json_encode(['jobs'=>[['tool'=>$tool,'primary'=>$ref,'refs'=>[$ref],
            'focus'=>'Prepare one useful private next step from the actual selected saved record.','report'=>true]]],JSON_THROW_ON_ERROR)];
    }
    if(isset($input['tool_result'])){
        if(($input['tool_result']['tool']??'')!==$tool
            ||!str_contains(json_encode($input['tool_result']['records']??[],JSON_THROW_ON_ERROR),$sentinel))
            throw new RuntimeException('The raw source sentinel did not reach the real selected capability read.');
        if(isset($input['owner_continuation'])){
            $continuation=$input['owner_continuation'];
            if(($continuation['kind']??'')!=='owner_requested_private_continuation'
                ||!is_string($continuation['owner_reply']??null)
                ||!str_contains($continuation['owner_reply'],'OWNER_BROWSER_REPLY_SENTINEL')
                ||!str_contains((string)($continuation['proposal_text']??''),$sentinel))
                throw new RuntimeException('The real read-leaf continuation lost the full owner reply or verified proposal.');
        }
        if(($_GET['hold']??'')==='1'){
            $gate=(string)getenv('VP3_CAPABILITY_BROWSER_GATE');
            file_put_contents($gate.'.entered',json_encode(['in_transaction'=>db()->inTransaction()]));
            $deadline=microtime(true)+20;
            while(!is_file($gate.'.release')){if(microtime(true)>$deadline)throw new RuntimeException('The model transport gate timed out.');usleep(10000);}
        }
        return ['ok'=>true,'answer'=>json_encode(['draft'=>'I read the current saved record. '.$sentinel
            .' supports a private follow-up: confirm the unresolved supplier or delivery decision before changing the plan.'
            .(isset($input['owner_continuation'])?' I retained the owner’s full requested detail OWNER_BROWSER_REPLY_SENTINEL in this private continuation.':'')],JSON_THROW_ON_ERROR)];
    }
    // Existing owner-requested private continuation; this is still a model
    // response, never a substituted tool output or fabricated execution receipt.
    return ['ok'=>true,'answer'=>json_encode(['draft'=>'The requested private continuation is ready. I prepared a focused question about the saved decision; no business record was changed.'],JSON_THROW_ON_ERROR)];
}

function capabilities_browser_presence_v1(bool $visible): void
{
    db()->prepare("UPDATE agent_activity_state SET last_heartbeat_at=NOW(),details_json=? WHERE user_id=1")
        ->execute([json_encode(['visible'=>$visible,'conversation_id'=>11,'chat_focused'=>true,'chat_busy'=>false],JSON_THROW_ON_ERROR)]);
}

function capabilities_browser_seed_v1(string $tool,bool $meeting=false): array
{
    if(!in_array($tool,['tasks.read','transcripts.read'],true)||($meeting&&$tool!=='transcripts.read'))throw new RuntimeException('Invalid browser fixture capability.');
    $pdo=db();$user=['id'=>1,'is_active'=>1];
    foreach(['agent_workflow_receipts','agent_workflow_action_dependencies','agent_workflow_events','agent_workflow_actions','agent_workflow_runs',
        'chat_messages','chat_conversations','agent_memory_items','agent_activity_state','agent_activity_events','user_agent_data_rules','user_data_policies',
        'followthrough_fixture_calls','capabilities_browser_state','agent_compute_preferences','agent_compute_overrides','homeserver_connections','video_meetings'] as $table)$pdo->exec('DELETE FROM '.$table);
    foreach(['agent_task_list_items_v1','agent_task_list_actions_v1','agent_task_lists_v1','agent_participant_grants_v1',
        'agent_participant_presence_v1','agent_participant_observers_v1','agent_participant_settings_v1'] as $table)$pdo->exec('DELETE FROM '.$table);
    $pdo->exec("DELETE FROM artist_transcript_segments_v172 WHERE session_id=301;DELETE FROM artist_transcript_sessions_v172 WHERE id=301;
        DELETE FROM artist_transcript_master_analysis_v237 WHERE session_id=301;
        INSERT INTO chat_conversations(id,user_id,user_agent_id,title,updated_at) VALUES(11,1,7,'Useful account work',NOW()),
            (12,1,8,'Sibling Chat',NOW()),(13,2,9,'Other owner Chat',NOW());
        INSERT INTO chat_messages(conversation_id,user_id,role,message,created_at) VALUES
            (11,1,'user','Help advance useful account work','2000-01-01 00:00:00'),
            (11,NULL,'assistant','I will review the saved context.','2000-01-01 00:00:00'),
            (12,NULL,'assistant','SIBLING CHAT SECRET','2000-01-01 00:00:00'),(13,NULL,'assistant','FOREIGN CHAT SECRET','2000-01-01 00:00:00');
        INSERT INTO agent_activity_state VALUES(1,'chat','chat:11','chat','Useful account work','paused',NOW(),NOW(),NOW(),NULL,
            '{\"visible\":true,\"conversation_id\":11,\"chat_focused\":true,\"chat_busy\":false}',NOW());
        INSERT INTO agent_compute_preferences VALUES(1,'auto')");
    $pdo->prepare('INSERT INTO homeserver_connections(user_id,status,capabilities_json,relay_token_enc,homeserver_token_enc,device_id) VALUES(?,?,?,?,?,?)')
        ->execute([1,'connected','{"features":["app.scopes.v1"]}',
            homeserver_vp3_encrypt('browser-disposable-relay-credential-1234567890'),
            homeserver_vp3_encrypt('browser-disposable-home-credential-1234567890'),'capability-browser-device']);
    $sentinel=$tool==='tasks.read'?'TASK_BROWSER_DETAIL_SENTINEL':'MEETING_BROWSER_DETAIL_SENTINEL';
    $text=str_repeat('Ordinary saved account context. ',28).$sentinel.'. Confirm the unresolved supplier and delivery decision.';
    $q=$pdo->prepare("INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,memory_scope_version,confidence,
        occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json) VALUES(?,?,?,'task',?,?,?,410,1,1,NOW(),NOW(),1,?)");
    $q->execute([100,1,7,'Review the saved supplier decision',$text,'capability-browser-task',json_encode(['task_status'=>'open','due_at'=>'2020-01-01T00:00:00Z'])]);
    $q->execute([200,1,8,'SIBLING SECRET','SIBLING SECRET must stay private','capability-browser-sibling','{}']);
    $q->execute([300,2,9,'FOREIGN SECRET','FOREIGN SECRET must stay private','capability-browser-foreign','{}']);
    $ref=$tool==='tasks.read'?'task:100':'transcript:301';$meta=[];
    if($meeting){
        $pdo->exec("INSERT INTO video_meetings VALUES(501,1,7,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','capability-browser-room','assist','Recorded owner meeting')");
        $meta=['capture_mode'=>'vp3_video_meeting','video_meeting_id'=>501];
    }
    $pdo->prepare('INSERT INTO artist_transcript_sessions_v172 VALUES(301,1,11,?,?,?,?,?,NULL)')
        ->execute(['Recent owner recording','draft',gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),json_encode($meta,JSON_THROW_ON_ERROR)]);
    $pdo->prepare("INSERT INTO artist_transcript_segments_v172 VALUES(301,301,'capability-browser-segment',0,'transcript','Owner',?,1000,2000,.9,?,?)")
        ->execute([$text,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')]);
    foreach(['tool'=>$tool,'ref'=>$ref,'sentinel'=>$sentinel,'meeting'=>$meeting] as $key=>$value)capabilities_browser_value_v1($key,$value,true);
    $principal=vp3_agent_chat_principal_v380($user,vp3_agent_chat_resolve_agent_v380($pdo,$user,7));
    $enabled=vp3_initiative_chat_v1($pdo,$user,$principal,['conversation_id'=>11],'on',null);
    if(empty($enabled['ok']))throw new RuntimeException('Canonical fixture policy failed.');
    vp3_initiative_schedule_v1($pdo,$user,7);
    $plan=(int)$pdo->query("SELECT MAX(id) FROM agent_workflow_runs WHERE capability_key='initiative.plan'")->fetchColumn();
    $planned=vp3_initiative_execute_v1($pdo,$user,followthrough_fixture_claim_v1($plan));
    if(empty($planned['ok']))throw new RuntimeException('Actual capability planning failed: '.json_encode($planned));
    $prepare=(int)$pdo->query("SELECT MAX(id) FROM agent_workflow_runs WHERE capability_key='initiative.prepare'")->fetchColumn();
    $goal=json_decode((string)$pdo->query('SELECT goal FROM agent_workflow_runs WHERE id='.$prepare)->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    if($goal['tool']!==$tool||$goal['primary']!==$ref)throw new RuntimeException('The canonical planner did not select the new capability.');
    capabilities_browser_value_v1('prepare_id',$prepare,true);
    if($meeting)capabilities_browser_presence_v1(false);
    return capabilities_browser_state_v1();
}

function capabilities_browser_state_v1(): array
{
    return followthrough_fixture_state_v1()+['prepare_id'=>capabilities_browser_value_v1('prepare_id'),
        'tool'=>capabilities_browser_value_v1('tool'),'ref'=>capabilities_browser_value_v1('ref'),'sentinel'=>capabilities_browser_value_v1('sentinel'),
        'analysis_count'=>(int)db()->query('SELECT COUNT(*) FROM artist_transcript_master_analysis_v237 WHERE session_id=301')->fetchColumn(),
        'request_id'=>(string)($_SERVER['HTTP_X_FIXTURE_REQUEST_ID']??'')];
}

function capabilities_browser_control_v1(): never
{
    header('Content-Type: application/json');
    try{
        $pdo=db();$action=(string)($_GET['action']??'state');
        if($action==='seed')$state=capabilities_browser_seed_v1((string)($_GET['tool']??'tasks.read'),($_GET['meeting']??'')==='1');
        elseif($action==='claim'){
            $id=(int)capabilities_browser_value_v1('prepare_id');$claim=followthrough_fixture_claim_v1($id);
            agent_job_heartbeat_v1900($pdo,['id'=>1,'is_active'=>1],$id,(int)$claim['action']['id'],$claim['lease_token'],42,'Reading current saved capability sources',240);
            capabilities_browser_value_v1('claim',$claim,true);$state=capabilities_browser_state_v1();
        }elseif($action==='execute'){
            $id=(int)($_GET['id']??capabilities_browser_value_v1('prepare_id'));
            $claim=$id===(int)capabilities_browser_value_v1('prepare_id')?capabilities_browser_value_v1('claim'):null;
            $claim=is_array($claim)?$claim:followthrough_fixture_claim_v1($id);
            $done=vp3_initiative_execute_v1($pdo,['id'=>1,'is_active'=>1],$claim);
            $state=capabilities_browser_state_v1()+['execution_result'=>$done];
        }elseif($action==='present'){
            capabilities_browser_presence_v1(($_GET['visible']??'1')==='1');$state=capabilities_browser_state_v1();
        }elseif($action==='deliver'){
            $before=(int)$pdo->query("SELECT COUNT(*) FROM followthrough_fixture_calls WHERE kind='capability_model'")->fetchColumn();
            $delivered=vp3_initiative_deliver_pending_v1($pdo,['id'=>1,'is_active'=>1],7);
            $state=capabilities_browser_state_v1()+['delivered'=>$delivered,'models_before_delivery'=>$before];
        }elseif($action==='source_change'){
            $pdo->exec("UPDATE agent_memory_items SET memory_text='The owner changed this saved task before delivery.' WHERE id=100");$state=capabilities_browser_state_v1();
        }else $state=capabilities_browser_state_v1();
        echo json_encode(['ok'=>true]+$state,JSON_THROW_ON_ERROR);
    }catch(Throwable $error){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$error->getMessage(),'at'=>basename($error->getFile()).':'.$error->getLine()]);}
    exit;
}

capabilities_browser_schema_v1();
