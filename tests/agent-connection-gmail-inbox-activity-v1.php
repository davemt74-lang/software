<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-activity-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-status-v1.php';

function db(): PDO { return $GLOBALS['pdo']; }
function has_permission(string $permission,array $user): bool
{
    return !empty($user['is_active']) && in_array($permission,['account.access','chat.access'],true);
}
function gmail_activity_check(bool $passed,string $label): void
{
    if (!$passed) throw new RuntimeException($label);
    echo 'PASS '.$label.PHP_EOL;
}
function gmail_activity_message(string $id,string $thread,int $when,string $from,string $to,string $rfc,array $labels,
    string $parent='',string $references=''): array
{
    $headers = ['From'=>$from,'To'=>$to,'Message-ID'=>$rfc,'In-Reply-To'=>$parent,'References'=>$references];
    return ['id'=>$id,'threadId'=>$thread,'internalDate'=>(string)$when,'labelIds'=>$labels,
        'payload'=>['headers'=>array_map(static fn($name,$value)=>['name'=>$name,'value'=>$value],array_keys($headers),$headers),
            'body'=>['data'=>'PRIVATE_TEST_BODY']]];
}

$fixture = sys_get_temp_dir().'/vp3-email-activity-'.bin2hex(random_bytes(6));
define('STONEFELLOW_ROOT',$fixture);
mkdir($fixture.'/private',0700,true);
file_put_contents($fixture.'/private/ai-key.php','<?php return '.var_export(base64_encode(random_bytes(32)),true).';');
putenv('VP3_GMAIL_CLIENT_ID=vp3-activity-fixture');
putenv('VP3_GMAIL_CLIENT_SECRET=local-test-only');
$pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys=ON');

try {
    $pdo->exec("CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL);
        CREATE TABLE users (id INTEGER PRIMARY KEY, is_active INTEGER NOT NULL);
        INSERT INTO users VALUES (1,1),(2,1);
        CREATE TABLE agent_connections_v1 (id INTEGER PRIMARY KEY, owner_user_id INTEGER NOT NULL,
            provider TEXT NOT NULL, authorization_key TEXT NOT NULL, status TEXT NOT NULL, read_enabled INTEGER NOT NULL,
            client_fingerprint TEXT NOT NULL);
        CREATE TABLE agent_connection_sends_v1 (owner_user_id INTEGER NOT NULL, connection_id INTEGER NOT NULL,
            authorization_key TEXT NOT NULL, status TEXT NOT NULL, provider_message_id TEXT NOT NULL, provider_thread_id TEXT NOT NULL);");
    agent_connection_gmail_inbox_install_v1($pdo);
    agent_email_matters_install_v1($pdo);
    $mailbox = 'owner@example.test';
    $sender = 'sender@example.test';
    $token = 'fixture-activity-token';
    $enabled = (time()-3600)*1000;
    $inboundAt = $enabled+3000;
    $sentAt = $inboundAt+1000;
    $grant = str_repeat('a',64);
    foreach ([[1,11,$grant],[2,22,str_repeat('b',64)]] as [$owner,$connection,$key]) {
        $pdo->prepare("INSERT INTO agent_connections_v1 VALUES (?,?,'gmail',?,'connected',1,?)")
            ->execute([$connection,$owner,$key,agent_connections_client_fingerprint_v1()]);
        $pdo->prepare("INSERT INTO agent_connection_gmail_inbox_v1
            (owner_user_id,connection_id,authorization_key,enabled,rules_json,revision,enabled_at_ms)
            VALUES (?,?,?,1,?,?,?)")->execute([$owner,$connection,$key,'{"triage_enabled":true}',str_repeat('c',32),$enabled]);
    }
    $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,['id'=>1]);
    $otherScope = agent_connection_gmail_inbox_bound_scope_v1($pdo,['id'=>2]);
    agent_email_running_report_install_v1($pdo);
    $analysisScope=$scope;
    $analysisScope['policy']['rules']['content_analysis']=true;
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $leaseUntil=$now->modify('+1 minute');
    $threadKey=str_repeat('d',64);
    $pdo->prepare('INSERT INTO agent_email_running_report_v1
        (thread_key,owner_user_id,connection_id,grant_key,source_triage_id,received_ms,importance,due_at,lease_key)
        VALUES (?,1,11,?,?,0,1,?,?)')->execute([$threadKey,agent_email_matters_policy_scope_v1($analysisScope['policy'])[2],
            str_repeat('e',64),$leaseUntil->format('Y-m-d H:i:s'),str_repeat('f',32)]);
    gmail_activity_check(agent_connection_gmail_inbox_analysis_work_v1($pdo,$scope,$now)===null
        &&agent_connection_gmail_inbox_analysis_work_v1($pdo,$analysisScope,$now)===['state'=>'working','lease_until'=>$leaseUntil->format('Y-m-d\TH:i:s\Z')]
        &&agent_connection_gmail_inbox_analysis_work_v1($pdo,$analysisScope,$leaseUntil)===['state'=>'queued','lease_until'=>null],
        'Gmail only reports live analysis for an opted-in mailbox and turns an elapsed lease into queued work');
    $pdo->prepare('DELETE FROM agent_email_running_report_v1 WHERE thread_key=?')->execute([$threadKey]);
    $policy = $scope['policy'];
    $otherPolicy = $otherScope['policy'];
    $observe = static fn(array $policy,string $thread,string $id,int $when,string $subject): bool => agent_commerce_atomic_pa2(db(),
        static fn()=>agent_email_matters_observe_inbox_v1(db(),$policy,[
            'thread_id'=>$thread,'message_id'=>$id,'received_at_ms'=>$when,'from'=>$sender,'subject'=>$subject,
        ],agent_commerce_time_pa2()));
    $observe($policy,'tracked_reply','1001',$inboundAt,'Direct reply');
    $observe($policy,'tracked_archive','archive_anchor',$inboundAt,'Archive only');
    $observe($otherPolicy,'other_owner','private_anchor',$inboundAt,'Other owner');
    $rows = static fn(): array => array_column(agent_email_matters_summary_v1(db(),$scope)['items'],null,'subject');
    $commit = static fn(array $observations) => agent_commerce_atomic_pa2(db(),static fn()=>
        agent_connection_gmail_inbox_activity_commit_v1(db(),$policy,$observations,agent_commerce_time_pa2()));
    $countEvents = static fn(string $kind): int => (int)db()->query("SELECT COUNT(*) FROM agent_email_matter_events_v1
        WHERE event_kind=".$pdo->quote($kind))->fetchColumn();
    $stillCurrent = true;
    $claimCurrent = static function () use (&$stillCurrent): bool { return $stillCurrent; };
    $calls = [];
    $replyAnchor = gmail_activity_message('1001','tracked_reply',$inboundAt,$sender,$mailbox,'<original@example.test>',['INBOX']);
    $validReply = gmail_activity_message('direct_gmail_sent','tracked_reply',$sentAt,$mailbox,$sender,'<direct@example.test>',['SENT'],'<original@example.test>');
    $archiveAnchor = gmail_activity_message('archive_anchor','tracked_archive',$inboundAt,$sender,$mailbox,'<archive@example.test>',[]);
    $wrongReply = gmail_activity_message('wrong_recipient','tracked_archive',$sentAt,$mailbox,'other@example.test','<other@example.test>',['SENT'],'<archive@example.test>');
    $threads = ['tracked_reply'=>[$replyAnchor,$validReply],'tracked_archive'=>[$archiveAnchor,$wrongReply]];
    $provider = static function(string $method,string $url,array $headers,?array $body,bool $form)
        use (&$calls,&$threads,$token): array {
        $path = (string)parse_url($url,PHP_URL_PATH);
        $thread = basename($path);
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        if ($method !== 'GET' || $body !== null || $form || !str_contains($path,'/threads/')
            || !in_array('Authorization: Bearer '.$token,$headers,true) || ($query['format'] ?? '') !== 'metadata'
            || ($query['fields'] ?? '') !== 'id,messages(id,threadId,labelIds,internalDate,payload(headers))'
            || !array_key_exists($thread,$threads)) throw new LogicException('Unexpected Gmail metadata access.');
        $calls[] = $thread;
        return ['status'=>200,'json'=>['id'=>$thread,'messages'=>$threads[$thread]]];
    };
    $scan = static fn(): array => agent_connection_gmail_inbox_activity_scan_v1(db(),$policy,$token,$mailbox,$claimCurrent,$provider);
    gmail_activity_check(!agent_connection_gmail_inbox_activity_ready_v1($pdo) && $scan() === [] && !$calls,
        'an older schema never starts additional Gmail activity requests');
    agent_connection_gmail_inbox_activity_install_v1($pdo);
    agent_connection_gmail_inbox_activity_install_v1($pdo);
    $disabled = array_replace($policy,['rules'=>[]]);
    gmail_activity_check(agent_connection_gmail_inbox_activity_scan_v1($pdo,$disabled,$token,$mailbox,$claimCurrent,$provider) === []
        && !$calls && agent_commerce_atomic_pa2($pdo,$scan) === [],
        'extra provider reads require explicit triage consent and run outside the database transaction');
    $observations = $scan();
    sort($calls);
    gmail_activity_check($calls === ['tracked_archive','tracked_reply'] && count($observations)===2,
        'the pass fetches exactly the two known owner threads and never searches unrelated Sent mail');
    $commit($observations);
    $current = $rows();
    gmail_activity_check($current['Direct reply']['stage']==='actioned' && $current['Direct reply']['sent_in_gmail']
        && $current['Direct reply']['mailbox_state']==='inbox' && $countEvents('sent_in_gmail')===1,
        'verified Gmail Sent from the owner to the original sender records a direct reply on the exact matter');
    gmail_activity_check($current['Archive only']['stage']==='needs_review' && $current['Archive only']['mailbox_state']==='archived'
        && empty($current['Archive only']['sent_in_gmail']),
        'archive is distinct from resolution and a wrong recipient cannot settle the matter');
    $private = json_encode($pdo->query('SELECT * FROM agent_connection_gmail_inbox_activity_v1')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);
    gmail_activity_check(!str_contains($private,'direct_gmail_sent') && !str_contains($private,'PRIVATE_TEST_BODY')
        && !str_contains(json_encode($current,JSON_THROW_ON_ERROR),'_enc'),
        'the exact Sent identifier is encrypted and no body or provider identifiers appear in the owner projection');
    $revision = $current['Direct reply']['revision'];
    $commit($observations);
    $commit($scan());
    gmail_activity_check($countEvents('sent_in_gmail')===1 && $rows()['Direct reply']['revision']===$revision
        && $rows()['Direct reply']['sent_in_gmail'],
        'replaying provider observations preserves one receipt and avoids changing the work revision');

    $observationWithoutProof = gmail_activity_message('nonmatching','tracked_archive',$sentAt,$mailbox,$sender,
        '<later@example.test>',['SENT'],'<different@example.test>','<archive@example.test>');
    $threads['tracked_archive'] = [$archiveAnchor,$observationWithoutProof];
    $commit($scan());
    gmail_activity_check(empty($rows()['Archive only']['sent_in_gmail']),
        'an explicit different parent cannot be overridden by a matching References header');
    $referencesOnly = gmail_activity_message('references_match','tracked_archive',$sentAt,$mailbox,$sender,
        '<later@example.test>',['SENT'],'','<old@example.test> <archive@example.test>');
    $laterInbound = gmail_activity_message('later_archive_request','tracked_archive',$sentAt+1000,$sender,$mailbox,
        '<later-request@example.test>',['INBOX']);
    $threads['tracked_archive'] = [$archiveAnchor,$referencesOnly,$laterInbound];
    $commit($scan());
    gmail_activity_check(empty($rows()['Archive only']['sent_in_gmail']) && $rows()['Archive only']['mailbox_state']==='inbox',
        'a newer incoming message prevents a matching older reply from clearing the matter even before intake sees the message');
    $threads['tracked_archive'] = [$archiveAnchor,$referencesOnly];
    $pdo->prepare("INSERT INTO agent_connection_sends_v1 VALUES (1,11,?,'sent',?,?)")
        ->execute([$grant,'references_match','tracked_archive']);
    $commit($scan());
    gmail_activity_check(empty($rows()['Archive only']['sent_in_gmail']) && $countEvents('sent_in_gmail')===1,
        'the exact existing VP3 send ledger prevents a native reply from being recorded as external');
    $pdo->exec('DELETE FROM agent_connection_sends_v1');
    $nativePending = gmail_activity_message('native_pending','tracked_archive',$sentAt,$mailbox,$sender,
        agent_connection_gmail_rfc_message_id_v1('pending-native-send'),['SENT'],'<archive@example.test>');
    $threads['tracked_archive'] = [$archiveAnchor,$nativePending];
    $commit($scan());
    gmail_activity_check(empty($rows()['Archive only']['sent_in_gmail']) && $countEvents('sent_in_gmail')===1,
        'a Gmail copy of a pending VP3 send remains subject to native receipt reconciliation');
    $malformedAnchor = array_replace($archiveAnchor,['labelIds'=>null]);
    $threads['tracked_archive'] = [$malformedAnchor];
    $commit($scan());
    gmail_activity_check(!isset($rows()['Archive only']['mailbox_state']),
        'malformed provider labels never prove archive status');
    $threads['tracked_archive'] = [$archiveAnchor,$referencesOnly];
    $observations = $scan();
    $observe($policy,'tracked_archive','later_archive_request',$sentAt+1000,'New archive question');
    $commit($observations);
    gmail_activity_check($rows()['New archive question']['stage']==='needs_review'
        && empty($rows()['New archive question']['sent_in_gmail'])
        && !isset($rows()['New archive question']['mailbox_state']),
        'an inbox cursor commit that added newer work invalidates the earlier Gmail thread observation and projection');

    $threads['tracked_archive'] = [$archiveAnchor,$laterInbound];
    $before = count($calls);
    $racingProvider = static function (...$args) use ($provider,&$stillCurrent): array {
        $result = $provider(...$args);
        $stillCurrent = false;
        return $result;
    };
    gmail_activity_check(agent_connection_gmail_inbox_activity_scan_v1(
        $pdo,$policy,$token,$mailbox,$claimCurrent,$racingProvider) === [] && count($calls)===$before+1,
        'a revoked claim discards in-flight evidence and stops additional provider reads');
    $stillCurrent = true;
    $other = agent_email_matters_summary_v1($pdo,$otherScope)['items'][0];
    gmail_activity_check(empty($other['sent_in_gmail']) && !isset($other['mailbox_state'])
        && $other['subject']==='Other owner',
        'the private activity projection stays within the current owner and grant');
    $nextInbound = gmail_activity_message('1002','tracked_reply',$sentAt+1000,$sender,$mailbox,'<second-inbound@example.test>',['INBOX']);
    $nextReply = gmail_activity_message('second_gmail_sent','tracked_reply',$sentAt+2000,$mailbox,$sender,
        '<second-direct@example.test>',['SENT'],'<second-inbound@example.test>');
    $observe($policy,'tracked_reply','1002',$sentAt+1000,'Second direct reply');
    $firstAnchor = agent_email_matters_source_key_v1($policy,'inbox','1001');
    $secondAnchor = agent_email_matters_source_key_v1($policy,'inbox','1002');
    $unansweredAnchor = agent_email_matters_source_key_v1($policy,'inbox','later_archive_request');
    $anchors = [$firstAnchor,$secondAnchor,$unansweredAnchor];
    gmail_activity_check(agent_connection_gmail_inbox_activity_answered_v1($pdo,$scope,$anchors)===[$firstAnchor=>true],
        'new inbound work keeps the prior exact Sent receipt and remains unanswered until Gmail verifies its own reply');
    $threads['tracked_reply'] = [$replyAnchor,$validReply,$nextInbound,$nextReply];
    $commit($scan());
    $answered = agent_connection_gmail_inbox_activity_answered_v1($pdo,$scope,$anchors);
    gmail_activity_check(count($answered)===2 && !empty($answered[$firstAnchor]) && !empty($answered[$secondAnchor])
        && $rows()['Second direct reply']['sent_in_gmail'] && $countEvents('sent_in_gmail')===2
        && agent_connection_gmail_inbox_activity_answered_v1($pdo,$otherScope,$anchors)===[],
        'verified Sent receipts for two inbound anchors survive the latest projection and remain scoped to the owner');
    agent_connection_gmail_inbox_activity_clear_v1($pdo,1);
    gmail_activity_check((int)$pdo->query('SELECT COUNT(*) FROM agent_connection_gmail_inbox_activity_v1')->fetchColumn()===0
        && empty($rows()['Second direct reply']['sent_in_gmail']),
        'revocation cleanup removes stored activity projections');
} finally {
    unlink($fixture.'/private/ai-key.php');
    rmdir($fixture.'/private');
    rmdir($fixture);
}
