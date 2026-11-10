<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/agent-connection-gmail-inbox-initial-v1.php';

$pdo = db();
header('Content-Type: application/json');

switch ($_GET['action'] ?? '') {
    case 'seed':
        // SQLite version of the columns used by the real grant reader.
        $pdo->exec('CREATE TABLE IF NOT EXISTS settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
        $pdo->exec('CREATE TABLE agent_connections_v1 (
            id INTEGER PRIMARY KEY, owner_user_id INTEGER, provider TEXT, status TEXT, read_enabled INTEGER,
            authorization_key TEXT, client_fingerprint TEXT, access_token_enc TEXT, refresh_token_enc TEXT)');
        $pdo->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY, user_id INTEGER, type TEXT, title TEXT,
            body TEXT, target_url TEXT, source_type TEXT, source_id INTEGER, created_at TEXT)');
        agent_connection_gmail_inbox_install_v1($pdo);
        agent_connection_gmail_inbox_initial_install_v1($pdo);
        $key = str_repeat('a', 64);
        $runId = str_repeat('c', 32);
        $when = gmdate('Y-m-d H:i:s', time() - 7200);
        $started = (time() - 7200) * 1000;
        $rules = '{}';
        $pdo->prepare("INSERT INTO agent_connections_v1 VALUES (71,1,'gmail','connected',1,?,?,?,?)")
            ->execute([$key,agent_connections_client_fingerprint_v1(),'fixture-access-token','fixture-refresh-token']);
        $pdo->prepare("INSERT INTO agent_connection_gmail_inbox_v1
            (owner_user_id,connection_id,authorization_key,enabled,rules_json,revision,history_id,enabled_at_ms)
            VALUES (1,71,?,1,?,?,'1234',?)")->execute([$key,$rules,str_repeat('b',32),$started]);
        $generation = agent_connection_gmail_inbox_notice_generation_v1(
            ['connection_id'=>71,'authorization_key'=>$key,'enabled_at_ms'=>$started]);
        // This saved report predates Chat delivery and has no Chat marker.
        $report = ['at'=>$when,'days'=>7,'scanned'=>3,'total'=>2,'important'=>1,'replies'=>0,'filtered'=>1,
            'truncated'=>false,'summary_status'=>'ready','summary'=>'The saved subjects suggest reviewing the spring schedule.',
            'tokens_charged'=>247,'items'=>[['from'=>'calendar@example.test','subject'=>'Private spring schedule header',
                'category'=>'important','message_id_enc'=>'fixture-encrypted-provider-id']]];
        $pdo->prepare("INSERT INTO agent_connection_gmail_inbox_initial_v1
            (owner_user_id,connection_id,generation_id,rules_hash,run_id,days,started_at_ms,phase,report_json,updated_at)
            VALUES (1,71,?,?,?,7,?,'ready',?,?)")->execute([
                $generation,hash('sha256',$rules),$runId,$started,json_encode($report, JSON_THROW_ON_ERROR),$when,
            ]);
        $pdo->beginTransaction();
        agent_connection_gmail_inbox_notify_v1($pdo,1,$report+['kind'=>'initial']);
        $pdo->commit();
        echo json_encode(['run_id'=>$runId]);
        break;

    case 'workspace':
        $pdo->prepare('UPDATE chat_conversations SET artist_workspace_id=17 WHERE id=? AND user_id=1')
            ->execute([(int)$_GET['conversation']]);
        echo '{}';
        break;

    case 'links':
        require_once __DIR__.'/includes/agent-activity-hub-v1.php';
        $scope = vp3_agent_activity_scope_v1($pdo,current_user(),7,(int)$_GET['conversation']);
        $notice = $pdo->query('SELECT target_url FROM notifications WHERE user_id=1 AND type=\'agent_email_inbox_review\'')->fetchColumn();
        echo json_encode(['notification'=>$notice,'activity'=>vp3_agent_activity_gmail_review_v1($pdo,$scope)]);
        break;

    case 'copy':
        // Simulate a misplaced historical context. It cannot become authority.
        $query = $pdo->prepare("SELECT message,context_json FROM chat_messages WHERE id=? AND role='assistant'");
        $query->execute([(int)$_GET['message']]);
        $message = $query->fetch();
        if (!$message) throw new RuntimeException('The source marker was not saved.');
        $pdo->prepare("INSERT INTO chat_messages (conversation_id,role,message,context_json) VALUES (?,'assistant',?,?)")
            ->execute([(int)$_GET['conversation'],$message['message'],$message['context_json']]);
        echo json_encode(['copied_id'=>(int)$pdo->lastInsertId()]);
        break;

    case 'stored':
        $query = $pdo->prepare('SELECT id,message,context_json FROM chat_messages WHERE conversation_id=? ORDER BY id');
        $query->execute([(int)$_GET['conversation']]);
        echo json_encode($query->fetchAll());
        break;

    case 'disable':
        // Leave the old report in storage so the test exercises grant rechecks.
        $pdo->exec('UPDATE agent_connection_gmail_inbox_v1 SET enabled=0 WHERE owner_user_id=1');
        echo '{}';
        break;

    default:
        http_response_code(404);
        echo '{}';
}
