<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-gmail-github-v1.php';

function db(): PDO { return $GLOBALS['pdo']; }
function setting(string $key, mixed $fallback=''): mixed { return $fallback; }
function url(string $path): string { return $path; }
function table_exists(string $table): bool
{
    $query = db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $query->execute([$table]);
    return (bool)$query->fetchColumn();
}
function column_exists(string $table, string $column): bool
{
    $query = db()->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $query->execute([$table,$column]);
    return (bool)$query->fetchColumn();
}
function has_permission(string $permission, array $user): bool
{
    return !empty($user['is_active']) && in_array($permission,['account.access','chat.access'],true);
}
function personal_capability_has_v242(string $capability, array $user): bool
{
    return !empty($user['is_active']) && in_array($capability,['personal_knowledge.access','personal_knowledge.manage'],true);
}
function github_check(bool $passed, string $label): void
{
    if (!$passed) throw new RuntimeException($label);
    $GLOBALS['checks']++;
    echo 'PASS '.$label.PHP_EOL;
}
function github_history(string $event, array $ids): array
{
    return ['status'=>200,'json'=>['historyId'=>$event,'history'=>[['id'=>$event,
        'messagesAdded'=>array_map(static fn(string $id): array => ['message'=>['id'=>$id]],$ids)]]]];
}

$dsn = (string)getenv('VP3_TEST_MYSQL_DSN');
if (!str_starts_with($dsn,'mysql:')) throw new RuntimeException('A native MySQL acceptance DSN is required.');
$login = getenv('VP3_TEST_MYSQL_USER') ?: 'root';
$password = (string)getenv('VP3_TEST_MYSQL_PASS');
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$server = new PDO($dsn,$login,$password,$options);
$database = 'vp3_github_fixture_'.bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$fixtureRoot = sys_get_temp_dir().'/vp3-gmail-github-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$fixtureRoot);
mkdir($fixtureRoot,0700);
$checks = 0;
putenv('VP3_GMAIL_CLIENT_ID=vp3-github-fixture.apps.googleusercontent.com');
putenv('VP3_GMAIL_CLIENT_SECRET=github-fixture-only');

try {
    $pdo = new PDO(preg_replace('/;dbname=[^;]+/','',$dsn).';dbname='.$database,$login,$password,$options);
    $pdo->exec("SET time_zone='+00:00'");
    $pdo->exec('CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY, setting_value TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, is_active TINYINT NOT NULL)');
    $pdo->exec('INSERT INTO users VALUES (1,1),(2,1)');
    $pdo->exec('CREATE TABLE chat_conversations (id BIGINT UNSIGNED PRIMARY KEY, user_id INT UNSIGNED NOT NULL, user_agent_id BIGINT UNSIGNED NULL)');
    $pdo->exec('CREATE TABLE artist_transcript_folders_v177 (id INT UNSIGNED PRIMARY KEY,created_by_user_id INT UNSIGNED,
        folder_name VARCHAR(120),sort_order INT DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("INSERT INTO artist_transcript_folders_v177 (id,created_by_user_id,folder_name) VALUES (11,1,'VP3'),(22,2,'VP3')");
    $pdo->exec('CREATE TABLE knowledge_items (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,track_id INT NULL,folder_id INT UNSIGNED,
        title VARCHAR(190),description TEXT,file_name VARCHAR(190),file_path TEXT,file_type VARCHAR(40),mime_type VARCHAR(100),file_size INT,
        content_text TEXT,visibility VARCHAR(20),is_published TINYINT,created_by_user_id INT UNSIGNED,knowledge_scope VARCHAR(20),
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE knowledge_chunks (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,knowledge_id INT UNSIGNED,chunk_index INT,chunk_text TEXT)');
    $pdo->exec("CREATE TABLE notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
        type VARCHAR(50) NOT NULL, title VARCHAR(190) NOT NULL, body VARCHAR(500) NOT NULL, target_url VARCHAR(500) NOT NULL,
        source_type VARCHAR(80) NOT NULL, source_id BIGINT UNSIGNED NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_read TINYINT NOT NULL DEFAULT 0, read_at DATETIME NULL)");
    agent_connections_install_v1($pdo);
    $owner = ['id'=>1,'is_active'=>1];
    $mailbox = 'owner@example.test';
    $token = ['external_account_id'=>'github-fixture','account_email'=>$mailbox,'access_token'=>'fixture-github-access',
        'refresh_token'=>'fixture-github-refresh','expires_in'=>3600];
    $grantPolicy = ['read'=>true,'send'=>false,'followups'=>false];
    $client = agent_connections_client_fingerprint_v1();
    agent_connections_store_gmail_v1(1,$token,$grantPolicy,'',$client);
    $connection = agent_connections_find_v1($pdo,1,'gmail');
    $settings = ['connection_id'=>(int)$connection['id'],'connection_revision'=>hash('sha256',$connection['authorization_key']),
        'github_revision'=>'','action'=>'enable','folder_id'=>11];
    $cursor = '18446744073709551600';
    $eventId = '18446744073709551601';
    $history = [];
    $metadata = [];
    $profileCalls = 0;
    $queries = [];
    $reads = [];
    $afterRead = null;
    $http = static function(string $method,string $url,array $headers,?array $body,bool $form)
        use (&$cursor,&$history,&$metadata,&$profileCalls,&$queries,&$reads,&$afterRead,$mailbox): array {
        if ($method!=='GET' || $body!==null || $form || !str_starts_with($url,VP3_GMAIL_API_V1)
            || !in_array('Authorization: Bearer fixture-github-access',$headers,true)) {
            throw new LogicException('GitHub review called an unexpected provider or write method.');
        }
        $path = (string)parse_url($url,PHP_URL_PATH);
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        if (str_ends_with($path,'/profile')) {
            $profileCalls++;
            return ['status'=>200,'json'=>['emailAddress'=>$mailbox,'historyId'=>$cursor]];
        }
        if (str_ends_with($path,'/history')) {
            $queries[] = $query;
            return array_shift($history) ?? throw new LogicException('Unexpected Gmail history request.');
        }
        if (!str_contains($path,'/messages/') || ($query['format']??'')!=='metadata'
            || ($query['fields']??'')!=='id,internalDate,labelIds,payload(headers)') {
            throw new LogicException('GitHub review requested unexpected message content.');
        }
        preg_match_all('/(?:[?&])metadataHeaders=([^&]+)/',$url,$requested);
        $names = $requested[1];
        $id = basename($path);
        $reads[] = [$id,$names];
        if ($afterRead !== null) $afterRead();
        if (!isset($metadata[$id])) return ['status'=>404,'json'=>[]];
        $record = $metadata[$id];
        $record['payload']['headers'] = array_values(array_filter($record['payload']['headers'],
            static fn(array $header): bool => in_array($header['name'],$names,true)));
        return ['status'=>200,'json'=>$record];
    };
    $policy = static fn(): ?array => agent_connection_gmail_github_policy_v1(db(),1);
    $sweep = static fn(): array => agent_connection_gmail_github_sweep_v1(db(),5,$http);
    $notes = static fn(): array => db()->query('SELECT * FROM knowledge_items ORDER BY id')->fetchAll();
    $notices = static fn(): array => db()->query("SELECT * FROM notifications WHERE type='agent_email_github_actions' ORDER BY id")->fetchAll();
    $receipts = static fn(): int => (int)db()->query('SELECT COUNT(*) FROM agent_connection_gmail_github_receipts_v1')->fetchColumn();
    $due = static fn() => db()->exec("UPDATE agent_connection_gmail_github_v1 SET next_check_at='2000-01-01 00:00:00' WHERE owner_user_id=1");
    $auth = 'mx.google.com; dkim=pass header.i=@github.com header.b="C1/IvT"; '
        .'spf=pass (google.com: allowed (nested proof)) smtp.mailfrom=notifications@github.com; '
        .'dmarc=pass (p=REJECT sp=REJECT dis=NONE) header.from=github.com';
    github_check(agent_connection_gmail_github_authenticated_v1([$auth])
        && !agent_connection_gmail_github_authenticated_v1([$auth,$auth])
        && !agent_connection_gmail_github_authenticated_v1([str_replace('mx.google.com;','evil.test;',$auth)])
        && !agent_connection_gmail_github_authenticated_v1(['mx.google.com; spf=pass (ignore; dkim=pass header.i=@github.com); dmarc=pass header.from=github.com'])
        && !agent_connection_gmail_github_authenticated_v1([str_replace('header.from=github.com','header.from=github.com.evil.test',$auth)]),
        'only a single aligned Gmail DKIM and DMARC proof qualifies, including real quoted and folded header syntax');
    github_check($sweep() === ['processed'=>0,'filed'=>0] && $profileCalls===0 && !$queries && !$reads,
        'linked Gmail and existing read permission do not opt into GitHub review');
    github_check(agent_connection_gmail_github_save_v1($pdo,$owner,$settings,$http)==='github_enabled'
        && $policy()['history_id']===$cursor && $profileCalls===1 && agent_connection_gmail_inbox_policy_v1($pdo,1)===null,
        'GitHub review uses its own opt-in, exact owned destination, and independent Gmail baseline');
    $enabled = (int)$policy()['enabled_at_ms'];
    $message = static function(string $id, string $subject, array $headers=[], array $labels=['INBOX'], ?int $date=null) use ($enabled,$auth): array {
        $fields = array_replace(['From'=>'GitHub <notifications@github.com>','Cc'=>'ci_activity@noreply.github.com',
            'List-Id'=>'OWNER/repo <repo.OWNER.github.com>','Subject'=>$subject,'Authentication-Results'=>$auth],$headers);
        return ['id'=>$id,'internalDate'=>(string)($date??($enabled+1000)),'labelIds'=>$labels,
            'snippet'=>'PRIVATE_SNIPPET_SENTINEL','payload'=>['body'=>['data'=>'PRIVATE_BODY_SENTINEL'],
                'headers'=>array_map(static fn(string $name,string $value): array => ['name'=>$name,'value'=>$value],array_keys($fields),$fields)]];
    };
    $metadata = [
        'unrelated'=>$message('unrelated','UNRELATED_SUBJECT_SENTINEL',['From'=>'sender@example.test']),
        'spoof'=>$message('spoof','[OWNER/repo] Run failed: Spoof',['Authentication-Results'=>str_replace('dkim=pass','dkim=fail',$auth)]),
        'failure'=>$message('failure',"[OWNER/repo] Run failed:\r\n\t VERIFIED_WORKFLOW_SUBJECT_SENTINEL ".str_repeat('x',250).'TRUNCATED_SUFFIX_SENTINEL'),
        'pr'=>$message('pr','[OWNER/repo] Run failed: PR',['X-GitHub-Reason'=>'mention']),
        'success'=>$message('success','[OWNER/repo] Run succeeded: Build',['X-GitHub-Reason'=>'ci_activity','Cc'=>'']),
        'unknown'=>$message('unknown','Workflow notification',['List-Id'=>'']),
        'lookalike'=>$message('lookalike','[OWNER/repo] Run failed: Wrong',['From'=>'"notifications@github.com" <somebody@example.test>']),
        'outgoing'=>$message('outgoing','[OWNER/repo] Run failed: Sent',[],['INBOX','SENT']),
        'old'=>$message('old','[OWNER/repo] Run failed: Old',[],['INBOX'],$enabled),
    ];
    $ids = ['unrelated','spoof','failure','pr','success','unknown','lookalike','outgoing','failure','old'];
    $history = [github_history($eventId,$ids),github_history($eventId,$ids)];
    github_check($sweep()===['processed'=>1,'filed'=>2] && count($reads)===9 && $policy()['history_id']===$cursor
        && (int)$policy()['pending_offset']===5 && $reads[0]===['unrelated',['From']] && $receipts()===5,
        'bounded first pass reads only From on unrelated mail and only files verified workflow success and failure');
    $filed = $notes();
    $combined = json_encode([$filed,$notices()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    github_check(count($filed)===2 && array_map('intval',array_column($filed,'folder_id'))===[11,11]
        && array_map('intval',array_column($filed,'created_by_user_id'))===[1,1]
        && str_contains($combined,'https://github.com/OWNER/repo/actions')
        && str_contains($filed[0]['content_text'],'Subject: [OWNER/repo] Run failed: VERIFIED_WORKFLOW_SUBJECT_SENTINEL')
        && !str_contains($combined,'PRIVATE_') && !str_contains($combined,'UNRELATED_SUBJECT_SENTINEL')
        && !str_contains($combined,'TRUNCATED_SUFFIX_SENTINEL')
        && str_contains($filed[0]['content_text'],'Status reported by email: failure')
        && str_contains($filed[1]['content_text'],'Status reported by email: success') && count($notices())===1
        && !str_contains($notices()[0]['body'],'OWNER/repo') && !str_contains($notices()[0]['body'],'SUBJECT_SENTINEL'),
        'Knowledge stores private canonical facts and a safe Actions link, with one generic outcome notice');
    github_check($sweep()===['processed'=>1,'filed'=>1] && $policy()['history_id']===$eventId && $policy()['pending_event_id']===''
        && $receipts()===9 && count($notes())===3 && count($notices())===1
        && str_contains($notes()[2]['content_text'],'Repository unavailable') && array_column($queries,'startHistoryId')===[$cursor,$cursor],
        'verified unknown evidence files quietly, duplicates and old mail do not file, and paging completes');

    $newEvent = '18446744073709551602';
    $metadata['raced'] = $message('raced','[OWNER/repo] Run failed: Destination race');
    $history[] = github_history($newEvent,['raced']);
    $afterRead = static function() use ($pdo,&$afterRead): void {
        $afterRead = null;
        $pdo->exec('UPDATE artist_transcript_folders_v177 SET created_by_user_id=2 WHERE id=11');
    };
    $due();
    $before = count($reads);
    github_check($sweep()===['processed'=>1,'filed'=>0] && array_slice($reads,$before)===[['raced',['From']]]
        && $policy()['history_id']===$eventId && $policy()['last_status']==='destination_unavailable' && $policy()['next_check_at']===null
        && count($notes())===3 && $receipts()===9 && count($notices())===1,
        'destination ownership lost mid-request stops expanded reads and pauses without filing or consuming the cursor');
    $pdo->exec('UPDATE artist_transcript_folders_v177 SET created_by_user_id=1 WHERE id=11');
    $cursor = $eventId;
    github_check(agent_connection_gmail_github_save_v1($pdo,$owner,array_replace($settings,['action'=>'restart','github_revision'=>$policy()['revision']]),$http)==='github_restarted'
        && $receipts()===0 && count($notes())===3,
        'explicit restart renews the review checkpoint while preserving previously filed Knowledge');
    $metadata['raced']['internalDate'] = (string)((int)$policy()['enabled_at_ms']+1000);
    $pdo->exec("CREATE TRIGGER fixture_github_notice_failure BEFORE INSERT ON notifications FOR EACH ROW
        BEGIN IF NEW.type='agent_email_github_actions' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rejection'; END IF; END");
    $history[] = github_history($newEvent,['raced']);
    github_check($sweep()===['processed'=>1,'filed'=>0] && $policy()['history_id']===$eventId && $receipts()===0 && count($notes())===3
        && (int)$pdo->query('SELECT COUNT(*) FROM knowledge_chunks')->fetchColumn()===3 && !$notices(),
        'a notice write failure rolls back filing, indexing, receipt and cursor together');
    $pdo->exec('DROP TRIGGER fixture_github_notice_failure');
    $history[] = github_history($newEvent,['raced']);
    $due();
    github_check($sweep()===['processed'=>1,'filed'=>1] && $policy()['history_id']===$newEvent && $receipts()===1
        && count($notes())===4 && count($notices())===1,
        'retry after rollback files and alerts exactly once');
    $history[] = ['status'=>404,'json'=>[]];
    $due();
    github_check($sweep()===['processed'=>1,'filed'=>0] && $policy()['last_status']==='restart_required' && $policy()['next_check_at']===null
        && $sweep()===['processed'=>0,'filed'=>0] && $profileCalls===2,
        'expired Gmail history requires an explicit restart and never scans past mailbox content');
    echo 'AGENT_CONNECTION_GMAIL_GITHUB=PASS ('.$checks.' checks; native MySQL)'.PHP_EOL;
} finally {
    $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    foreach (['ai-key.php','ai-key.php.lock'] as $file) {
        if (is_file($fixtureRoot.'/private/'.$file)) unlink($fixtureRoot.'/private/'.$file);
    }
    if (is_dir($fixtureRoot.'/private')) rmdir($fixtureRoot.'/private');
    rmdir($fixtureRoot);
}
