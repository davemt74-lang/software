<?php
declare(strict_types=1);

// Extend the existing real-controller/worker fixture with permission storage and
// controlled provider output. No production action, Chat or source reader is replaced.
require_once __DIR__.'/initiative-followthrough-chat-fixture.php';

function user_roles(): array{return ['member'=>'Member','admin'=>'Admin'];}
function valid_role(string $role): bool{return isset(user_roles()[$role]);}
function user_roles_for_user(?array $user=null): array
{
    $q=db()->prepare('SELECT role FROM users WHERE id=? AND is_active=1');$q->execute([(int)($user['id']??0)]);$role=$q->fetchColumn();return $role?[$role]:[];
}
function permissions_schema_ready(): bool{return table_exists('role_permissions');}

function action_fixture_schema_v1(): void
{
    $pdo=db();
    if(!column_exists('users','role'))$pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'member'");
    $pdo->exec("CREATE TABLE IF NOT EXISTS permissions(permission_key TEXT PRIMARY KEY);
        CREATE TABLE IF NOT EXISTS role_permissions(role TEXT,permission_key TEXT,UNIQUE(role,permission_key));
        CREATE TABLE IF NOT EXISTS settings(setting_key TEXT PRIMARY KEY,setting_value TEXT);
        CREATE TABLE IF NOT EXISTS user_account_types(user_id INTEGER,role TEXT,assigned_explicitly_at TEXT)");
    if(!$pdo->query("SELECT 1 FROM settings WHERE setting_key='personal_capabilities_seed_v242'")->fetchColumn()){
        $pdo->exec("INSERT INTO settings VALUES('personal_capabilities_seed_v242','1')");
        $q=$pdo->prepare('INSERT OR IGNORE INTO permissions(permission_key) VALUES(?)');
        $g=$pdo->prepare("INSERT OR IGNORE INTO role_permissions(role,permission_key) VALUES('member',?)");
        foreach(['account.access','chat.access','agent_brain.access','personal_knowledge.access','calendar.access','calendar.manage'] as $key){$q->execute([$key]);$g->execute([$key]);}
    }
}

function ai_generate_chat_response($prompt,...$args): array
{
    $options=is_array($args[4]??null)?$args[4]:[];$protocol=(string)($options['protocol']??'');
    $input=is_string($prompt)?json_decode($prompt,true,64):null;
    if($protocol==='vp3.initiative-reply.v1'){
        if(db()->inTransaction())throw new RuntimeException('Action intent inference held a database transaction.');
        followthrough_fixture_log_v1('initiative_model',['protocol'=>$protocol,'prompt'=>$prompt]);
        followthrough_fixture_provider_gate_v1('intent');
        if(isset($_SERVER['HTTP_X_FIXTURE_ACTION_INTENT_CHANGE']))db()->exec("UPDATE agent_memory_items SET memory_text='Changed during action planning',last_seen_at=NOW() WHERE id=100");
        $action=$_SERVER['HTTP_X_FIXTURE_ACTION_PLAN']??null;
        return ['ok'=>true,'answer'=>json_encode(['intent'=>(string)($_SERVER['HTTP_X_FIXTURE_FOLLOWTHROUGH_INTENT']??'advance'),
            'focus'=>'Advance the verified launch using an exact reviewed private action.',
            'action'=>$action===null?null:json_decode($action,true,32,JSON_THROW_ON_ERROR),
            'action_decision'=>(string)($_SERVER['HTTP_X_FIXTURE_ACTION_DECISION']??($action===null&&($input['pending_action_review']['status']??'')==='approval_pending'?'approve':'none'))],JSON_THROW_ON_ERROR)];
    }
    if($protocol==='vp3.initiative-draft.v1'&&($input['response_kind']??null)==='followthrough_reply'){
        if(db()->inTransaction())throw new RuntimeException('Main action response inference held a database transaction.');
        followthrough_fixture_log_v1('main_model',['query'=>$input['request']??'','owner'=>$args[2]['id']??0,'context'=>$input]);
        followthrough_fixture_provider_gate_v1();
        $a=$input['verified_followthrough']['verified_action']??null;
        $draft=($a['status']??'')==='approval_pending'?'I have the exact task details ready for your review.'
            :(($a['status']??'')==='completed'?'The reviewed action is saved and its result has been verified.'
                :(($a['status']??'')==='cancelled'?'Cancelled the proposed action.':'Let us work through the verified launch.'));
        return ['ok'=>true,'answer'=>json_encode(['draft'=>$draft],JSON_THROW_ON_ERROR)];
    }
    return followthrough_fixture_ai_generate_chat_response($prompt,...$args);
}

function action_fixture_state_v1(): array
{
    return followthrough_fixture_state_v1()+['tasks'=>db()->query("SELECT * FROM agent_memory_items WHERE memory_type IN ('task','commitment') ORDER BY id")->fetchAll(),
        'permissions'=>db()->query('SELECT * FROM role_permissions ORDER BY role,permission_key')->fetchAll()];
}

function action_fixture_control_v1(): never
{
    $action=(string)($_GET['action']??'state');
    if(!in_array($action,['state','seed','deny_brain','allow_brain','pending','new_turn','deactivate_agent'],true))followthrough_fixture_control_v1();
    header('Content-Type: application/json');
    try{
        $pdo=db();
        if($action==='seed'){
            $pdo->exec("INSERT OR IGNORE INTO role_permissions(role,permission_key) VALUES('member','agent_brain.access');UPDATE user_agents SET is_active=1;UPDATE users SET is_active=1");
            followthrough_fixture_seed_v1();
        }elseif($action==='deny_brain')$pdo->exec("DELETE FROM role_permissions WHERE permission_key='agent_brain.access'");
        elseif($action==='allow_brain')$pdo->exec("INSERT OR IGNORE INTO role_permissions(role,permission_key) VALUES('member','agent_brain.access')");
        elseif($action==='pending'){
            $u=['id'=>1,'is_active'=>1];$p=vp3_initiative_principal_v1($pdo,$u,7);
            vp3_task_list_prepare_v1($pdo,$u,$p,11,['action'=>'add','list_name'=>'Unrelated list','title'=>'Unrelated approval task','task_id'=>0]);
        }elseif($action==='new_turn')$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(11,1,'user','A newer unrelated owner request')");
        elseif($action==='deactivate_agent')$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');
        echo json_encode(['ok'=>true]+action_fixture_state_v1(),JSON_THROW_ON_ERROR);
    }catch(Throwable $error){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$error->getMessage(),'at'=>basename($error->getFile()).':'.$error->getLine()]);}
    exit;
}

action_fixture_schema_v1();
