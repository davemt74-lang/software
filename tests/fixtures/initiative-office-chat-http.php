<?php
declare(strict_types=1);

function initiative_http_office_fixture_v1(string $kind): array
{
    return match($kind){
        'task'=>['tasks.read','task:100','The launch needs a product image and a reviewed promotion plan.',
            'The launch follow-up still needs a reviewed promotion plan.',
            'Identify who can review the promotion plan.','What is blocking the plan review?'],
        'list'=>['tasks.lists.read','task-list:601','Launch follow-up',
            'A task on Coordinated launch is waiting on a reviewed promotion plan.',
            'Check who owns the plan review.','Who can unblock the review?'],
        'reminder'=>['reminders.read','reminder:201','Call the venue about the jukebox',
            'The reminder to call the venue about the jukebox is overdue.',
            'Confirm whether the call happened.','Did you reach the venue, or is there a better time to call?'],
    };
}

function initiative_http_seed_office_v1(PDO $pdo,string $kind): void
{
    if($kind==='reminder'){
        $pdo->exec("DELETE FROM agent_memory_items WHERE id=100");
        reminder_schema_v1($pdo);
        $stamp=gmdate('Y-m-d\TH:i:s\Z',time()-86400);
        $pdo->prepare("INSERT INTO agent_reminders_v1
            (id,owner_user_id,title,timezone,start_local,recurrence,delivery,next_at,status,conversation_id,created_at,updated_at)
            VALUES(201,1,'Call the venue about the jukebox','UTC',?,'none','chat',?,'active',11,?,?)")
            ->execute([substr($stamp,0,-1),$stamp,$stamp,$stamp]);
        $pdo->prepare("INSERT INTO agent_reminder_occurrences_v1(id,reminder_id,owner_user_id,scheduled_at,wake_at,status)
            VALUES(301,201,1,?,?,'pending')")->execute([$stamp,$stamp]);
        return;
    }

    $metadata=['task_status'=>'open','source_kind'=>'explicit_user','conversation_id'=>11];
    $pdo->prepare('UPDATE agent_memory_items SET metadata_json=? WHERE id=100')->execute([json_encode($metadata,JSON_THROW_ON_ERROR)]);
    if($kind==='list'){
        require_once dirname(__DIR__,2).'/includes/agent-task-lists-v1.php';
        vp3_task_lists_schema_v1($pdo);
        $pdo->prepare('INSERT INTO agent_task_lists_v1(id,owner_user_id,agent_scope_id,name,name_key,created_at) VALUES(601,1,7,?,?,?)')
            ->execute(['Coordinated launch',vp3_task_list_key_v1('Coordinated launch'),gmdate('c')]);
        $pdo->prepare("INSERT INTO agent_task_list_items_v1(list_id,task_id,item_key,created_at) VALUES(601,100,'fixture-member',?)")
            ->execute([gmdate('c')]);
    }
}

function initiative_http_office_model_v1(string $kind,string $protocol,array $input): array
{
    [$tool,$ref,$recordText,$draft,$nextStep,$question]=initiative_http_office_fixture_v1($kind);
    if($protocol==='vp3.initiative-plan.v1'){
        $evidence=array_column($input['evidence']??[],null,'ref')[$ref]??[];
        if(empty($input['tools'][$tool]['ready'])||($evidence['capability_tool']??null)!==$tool){
            throw new RuntimeException('The real Office capability and exact source must be present in the plan.');
        }
        return ['jobs'=>[],'opportunities'=>[]];
    }
    if($protocol==='vp3.initiative-draft.v1'&&($input['response_kind']??null)==='coordination_review'){
        $primary='office:'.$ref;
        if(($input['primary']??null)!==$primary
            ||($input['matched_rule']??null)!==['specialist'=>'tasks','trigger'=>'waiting_owner','action'=>'ask']
            ||!in_array($primary,array_column($input['specialist_reports']??[],'ref'),true)){
            throw new RuntimeException('The shared reviewer must see the saved Office finding and its matched rule.');
        }
        return ['title'=>'Office decision','summary'=>$draft,'next_step'=>$nextStep,'question'=>$question,
            'state'=>'needs_owner','refs'=>[$primary]];
    }
    $records=$input['tool_result']['records']??[];
    if($protocol!==VP3_INITIATIVE_OFFICE_REPORT_PROTOCOL_V1||($input['response_kind']??null)!=='office_entry_review'){
        throw new RuntimeException('Office analysis must use the specialist worker and the shared coordinator.');
    }
    if($kind==='list'&&array_column($records,'source')===['task:100']){
        return ['office_disposition'=>'note','draft'=>'The saved list member is open.'];
    }
    if(($input['tool_result']['tool']??null)!==$tool||array_column($records,'source')!==[$ref]
        ||!str_contains($records[0]['text']??'',$recordText)){
        throw new RuntimeException('The real Office worker must read the exact saved source.');
    }
    return ['office_disposition'=>'review','draft'=>$draft,'next_step'=>$nextStep,'followup_question'=>$question,
        'action_plan'=>[],'owner_attention'=>$kind!=='task'];
}

/** The browser uses its own clock. Expire only the fixture's server scan checkpoints. */
function initiative_http_office_rescan_v1(): never
{
    $body=json_decode((string)file_get_contents('php://input'),true,8,JSON_THROW_ON_ERROR);
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!hash_equals(csrf_token(),(string)($body['csrf_token']??''))){
        http_response_code(419);exit;
    }
    db()->exec("DELETE FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7 AND memory_type='initiative_sweep'
        AND subject IN ('coordination-scan','office-entry-scan')");
    if(($body['saved_only']??false)===true&&getenv('VP3_INITIATIVE_HTTP_OFFICE')==='task'){
        db()->exec("UPDATE fixture_controls SET scenario='office_saved_only' WHERE id=1");
    }
    if(($body['age_delivery']??false)===true){
        $age=gmdate('Y-m-d H:i:s',time()-180);
        db()->prepare("UPDATE agent_workflow_actions SET completed_at=? WHERE owner_user_id=1 AND status='completed'
            AND run_id IN (SELECT id FROM agent_workflow_runs WHERE owner_user_id=1 AND agent_id=7 AND source_key LIKE 'coordination:%')")
            ->execute([$age]);
    }
    header('Content-Type: application/json');echo '{"ok":true}';exit;
}

function initiative_http_office_state_v1(PDO $pdo,string $kind): array
{
    return match($kind){
        'task'=>['task'=>$pdo->query('SELECT * FROM agent_memory_items WHERE id=100')->fetch(PDO::FETCH_ASSOC)],
        'list'=>['list'=>$pdo->query('SELECT * FROM agent_task_lists_v1 WHERE id=601')->fetch(PDO::FETCH_ASSOC),
            'member'=>$pdo->query('SELECT * FROM agent_task_list_items_v1 WHERE list_id=601')->fetch(PDO::FETCH_ASSOC),
            'task'=>$pdo->query('SELECT * FROM agent_memory_items WHERE id=100')->fetch(PDO::FETCH_ASSOC)],
        'reminder'=>['reminder'=>$pdo->query('SELECT * FROM agent_reminders_v1 WHERE id=201')->fetch(PDO::FETCH_ASSOC),
            'occurrence'=>$pdo->query('SELECT * FROM agent_reminder_occurrences_v1 WHERE id=301')->fetch(PDO::FETCH_ASSOC)],
    };
}
