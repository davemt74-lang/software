<?php
declare(strict_types=1);

// Infrastructure only. Source readers, leases, preparation, delivery and HTTP controllers stay production code.
require_once __DIR__.'/agent-initiative-v1.php';
require_once __DIR__.'/agent-activity-v94.php';
require_once __DIR__.'/agent-worker-cloud-v1910.php';
$pdo=db();
$pdo->exec("CREATE TABLE IF NOT EXISTS agent_chat_archive(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,created_at TEXT);
    CREATE INDEX IF NOT EXISTS idx_agent_memory_agent_type_v410 ON agent_memory_items(user_agent_id,memory_type);
    CREATE INDEX IF NOT EXISTS idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_agent_id,last_seen_at);
    CREATE INDEX IF NOT EXISTS idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_agent_id,created_at);
    CREATE UNIQUE INDEX IF NOT EXISTS closed_chat_receipt_unique ON agent_workflow_receipts(owner_user_id,receipt_key);
    CREATE TRIGGER IF NOT EXISTS closed_chat_system_agent AFTER INSERT ON agent_workflow_runs
      WHEN NEW.source_kind='agent_initiative' AND NEW.agent_id=0
      BEGIN UPDATE agent_workflow_runs SET agent_id=NULL WHERE id=NEW.id; END;
    CREATE TABLE IF NOT EXISTS agent_activity_state(user_id INTEGER PRIMARY KEY,surface TEXT,context_key TEXT,task_kind TEXT,task_title TEXT,
      activity_state TEXT,last_input_at TEXT,last_task_at TEXT,last_heartbeat_at TEXT,idle_since_at TEXT,details_json TEXT,updated_at TEXT);
    CREATE TABLE IF NOT EXISTS agent_activity_events(id INTEGER PRIMARY KEY,user_id INTEGER,surface TEXT,context_key TEXT,task_kind TEXT,
      task_title TEXT,previous_state TEXT,activity_state TEXT,reason TEXT,details_json TEXT,created_at TEXT)");

function closed_chat_assert(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

function closed_chat_claim(array $user,int $runId): array
{
    $claim=agent_job_claim_run_v1900(db(),$user,$runId,'cloud',agent_worker_cloud_worker_id_v1910((int)$user['id']));
    closed_chat_assert(is_array($claim),'The production claimant must acquire the selected run.');
    $second=agent_job_claim_run_v1900(db(),$user,$runId,'cloud',agent_worker_cloud_worker_id_v1910((int)$user['id']));
    closed_chat_assert($second===null,'A second claim must not acquire work under an active lease.');
    return $claim;
}

function closed_chat_state(): array
{
    $pdo=db();
    return [
        'reports'=>$pdo->query("SELECT id,conversation_id,message,context_json FROM chat_messages WHERE role='assistant'
            AND JSON_EXTRACT(CASE WHEN JSON_VALID(context_json) THEN context_json ELSE '{}' END,'$.source')='agent_initiative' ORDER BY id")->fetchAll(),
        'receipts'=>$pdo->query("SELECT r.id,r.status,r.result_json,w.capability_key FROM agent_workflow_receipts r JOIN agent_workflow_runs w ON w.id=r.run_id ORDER BY r.id")->fetchAll(),
        'runs'=>$pdo->query("SELECT id,status,capability_key FROM agent_workflow_runs WHERE source_kind='agent_initiative' ORDER BY id")->fetchAll(),
    ];
}

function closed_chat_seed(): array
{
    $pdo=db();
    $pdo->exec(<<<'SQL'
        DELETE FROM agent_workflow_actions;DELETE FROM agent_workflow_runs;DELETE FROM chat_messages;
        INSERT INTO chat_conversations(id,user_id,user_agent_id,title,updated_at) VALUES
          (42,1,NULL,'Owner working Chat',CURRENT_TIMESTAMP),(43,2,NULL,'Foreign Chat',CURRENT_TIMESTAMP),
          (44,1,NULL,'Other owner Chat',CURRENT_TIMESTAMP);
        INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES
          (42,1,'user','Please keep these follow-ups moving.'),(42,NULL,'assistant','I will review the available private work.'),
          (43,NULL,'assistant','Only foreign history.'),(44,NULL,'assistant','Only this other conversation.');
        INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,memory_scope_version,
          confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json) VALUES
          (100,1,NULL,'task','Review launch assets','Review launch assets','closed-owner-task',410,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,1,
            '{"task_status":"open","due_at":"2020-01-01T00:00:00Z"}'),
          (200,2,NULL,'task','SECRET foreign task','SECRET foreign task','closed-foreign-task',410,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,1,
            '{"task_status":"open","due_at":"2020-01-01T00:00:00Z"}')
        SQL);
    $q=$pdo->prepare("INSERT INTO agent_reminders_v1(id,owner_user_id,title,timezone,start_local,recurrence,delivery,next_at,status,conversation_id,created_at,updated_at)
        VALUES (?,?,?,'UTC','2020-01-01T00:00:00','once','chat','2020-01-01T00:00:00Z','active',?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
    $q->execute([100,1,'Follow up on the product launch',42]);
    $q->execute([200,2,'SECRET foreign reminder',43]);
    $user=['id'=>1,'is_active'=>1];
    $reply=vp3_initiative_chat_v1($pdo,$user,vp3_agent_chat_principal_v380($user,null),['conversation_id'=>42],'on',null);
    closed_chat_assert(str_contains($reply['answer'],'Initiative is on'),'Owner opt-in must use the canonical Chat command.');
    return closed_chat_state();
}

function closed_chat_prepare(string $mode='initial'): array
{
    $pdo=db();$user=['id'=>1,'is_active'=>1];
    $staleDuringGeneration=$mode==='stale';
    if($mode!=='initial'){
        $title=$staleDuringGeneration?'A second current follow-up':'A current follow-up awaiting the owner';
        $pdo->prepare('UPDATE agent_reminders_v1 SET title=? WHERE id=100')->execute([$title]);
        $pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
    }
    vp3_initiative_schedule_v1($pdo,$user);
    $runId=(int)$pdo->query("SELECT MAX(id) FROM agent_workflow_runs WHERE source_kind='agent_initiative' AND capability_key='initiative.plan'")->fetchColumn();
    $plan=vp3_initiative_execute_v1($pdo,$user,closed_chat_claim($user,$runId),static function(string $prompt,string $protocol)use($staleDuringGeneration): string{
        $rows=json_decode($prompt,true,16,JSON_THROW_ON_ERROR)['evidence'];$refs=array_column($rows,'ref');
        closed_chat_assert($protocol==='vp3.initiative-plan.v1','The canonical planner protocol must be selected.');
        closed_chat_assert(in_array('reminder:100',$refs,true)&&in_array('task:100',$refs,true),'A reminder and a task must reach the same planner.');
        closed_chat_assert(!str_contains($prompt,'SECRET'),'The planner must not receive another owner’s evidence.');
        $jobs=[['tool'=>'work.prepare_private','primary'=>'reminder:100','refs'=>['reminder:100'],
            'focus'=>'Prepare a launch follow-up for the owner','report'=>true]];
        if(!$staleDuringGeneration)$jobs[]=['tool'=>'work.prepare_private','primary'=>'task:100','refs'=>['task:100'],
            'focus'=>'Prepare launch asset review questions','report'=>false];
        return json_encode(['jobs'=>$jobs],JSON_THROW_ON_ERROR);
    });
    closed_chat_assert(!empty($plan['ok']),'The production planner must save its selected assignments.');
    $pending=$pdo->query("SELECT id FROM agent_workflow_runs WHERE source_kind='agent_initiative' AND capability_key='initiative.prepare' AND status='approved' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    foreach($pending as $id){
        $result=vp3_initiative_execute_v1($pdo,$user,closed_chat_claim($user,(int)$id),static function(string $prompt,string $protocol)use($pdo,$staleDuringGeneration): string{
            closed_chat_assert($protocol==='vp3.initiative-draft.v1','The canonical preparation protocol must be selected.');
            $request=json_decode($prompt,true,16,JSON_THROW_ON_ERROR);
            $reminder=$request['evidence'][0]['ref']==='reminder:100';
            if($staleDuringGeneration)$pdo->exec("UPDATE agent_reminders_v1 SET title='Changed during inference' WHERE id=100");
            return json_encode(['draft'=>$staleDuringGeneration?'WITHHOLD this obsolete draft.':($reminder
                ?'I prepared launch follow-up questions from your saved reminder.'
                :'I prepared a private asset review checklist.')],JSON_THROW_ON_ERROR);
        });
        closed_chat_assert(!empty($result['ok'])!==$staleDuringGeneration,'Changed source evidence must prevent a successful preparation receipt.');
    }
    return closed_chat_state();
}
