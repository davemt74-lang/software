<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-email-followup-v1.php';
require_once dirname(__DIR__).'/includes/agent-email-matters-v1.php';

function db(): PDO { return $GLOBALS['pdo']; }
function has_permission(string $permission,array $user): bool
{
    return !empty($user['is_active']) && in_array($permission,['account.access','chat.access'],true);
}
function matter_check(bool $pass,string $description): void
{
    if (!$pass) throw new RuntimeException($description);
    echo 'PASS '.$description.PHP_EOL;
}
function matter_test_connection(string $filename): PDO
{
    $pdo = new PDO('sqlite:'.$filename,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    return $pdo;
}

$fixture = sys_get_temp_dir().'/vp3-email-matter-'.bin2hex(random_bytes(6));
define('STONEFELLOW_ROOT',$fixture);
mkdir($fixture.'/private',0700,true);
file_put_contents($fixture.'/private/ai-key.php','<?php return '.var_export(base64_encode(random_bytes(32)),true).';');
putenv('VP3_GMAIL_CLIENT_ID=vp3-matter-fixture');
putenv('VP3_GMAIL_CLIENT_SECRET=local-test-only');
$filename = $fixture.'/mail.sqlite';
$pdo = matter_test_connection($filename);

try {
    $pdo->exec("CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL);
        CREATE TABLE users (id INTEGER PRIMARY KEY, is_active INTEGER NOT NULL);
        INSERT INTO users VALUES (1,1),(2,1);
        CREATE TABLE agent_connections_v1 (id INTEGER PRIMARY KEY, owner_user_id INTEGER NOT NULL,
            provider TEXT NOT NULL, authorization_key TEXT NOT NULL, status TEXT NOT NULL, read_enabled INTEGER NOT NULL,
            client_fingerprint TEXT NOT NULL);
        CREATE TABLE agent_connection_sends_v1 (owner_user_id INTEGER NOT NULL, connection_id INTEGER NOT NULL,
            authorization_key TEXT NOT NULL, operation_key TEXT NOT NULL, status TEXT NOT NULL, provider_message_id TEXT NOT NULL,
            provider_thread_id TEXT NOT NULL, sent_at INTEGER NOT NULL);");
    matter_check(!agent_email_matters_ready_v1($pdo),'legacy schema is optional until installed');
    agent_email_matters_clear_v1($pdo,1);
    agent_connection_gmail_inbox_install_v1($pdo);
    agent_connection_email_followup_install_v1($pdo);
    agent_email_matters_install_v1($pdo);
    agent_email_matters_install_v1($pdo);
    $owner = ['id'=>1];
    $other = ['id'=>2];
    $start = (time()-60)*1000;
    $grant = str_repeat('a',64);
    foreach ([[1,11,$grant],[2,22,str_repeat('b',64)]] as [$account,$connection,$key]) {
        $pdo->prepare("INSERT INTO agent_connections_v1 VALUES (?,?,'gmail',?,'connected',1,?)")
            ->execute([$connection,$account,$key,agent_connections_client_fingerprint_v1()]);
        $pdo->prepare("INSERT INTO agent_connection_gmail_inbox_v1
            (owner_user_id,connection_id,authorization_key,enabled,rules_json,revision,enabled_at_ms)
            VALUES (?,?,?,1,'{}',?,?)")->execute([$account,$connection,$key,str_repeat('a',32),$start]);
    }
    $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$owner);
    $otherScope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$other);
    $policy = $scope['policy'];
    $message = static fn(string $id,int $after,string $subject,string $thread='fixture_thread'): array => [
        'thread_id'=>$thread,'message_id'=>$id,'received_at_ms'=>$start+$after,
        'from'=>'sender@example.test','subject'=>$subject,'important'=>false,'reply'=>false,'body'=>'PRIVATE_BODY_FIXTURE',
    ];
    $inbox = static fn(array $message): bool => agent_commerce_atomic_pa2(db(),static fn() =>
        agent_email_matters_observe_inbox_v1(db(),$policy,$message,agent_commerce_time_pa2()));
    $view = static fn(): array => agent_email_matters_summary_v1(db(),$scope);
    $active = static fn(): array => $view()['items'][0];
    $scan = static fn(int $after,int $total,bool $more) => agent_commerce_atomic_pa2(db(),static fn() =>
        agent_email_matters_record_scan_v1(db(),$policy,['at'=>gmdate('Y-m-d H:i:s',intdiv($start,1000)+$after),
            'total'=>$total,'important'=>$total>0?1:0,'replies'=>0],$more));
    $scanView = static fn(): ?array => agent_email_matters_scan_status_v1(db(),$scope);

    matter_check($inbox($message('first_provider_id',10,'Urgent request')) && !$inbox($message('first_provider_id',10,'Urgent request')),
        'the same verified inbox message is idempotent');
    $first = $active();
    matter_check($first['important'] && $first['stage']==='needs_review' && $first['direction']==='inbound'
        && $inbox($message('older_provider_id',5,'Older mail')) && $active()===$first,
        'an out-of-order message is recorded without replacing the newest attention state');
    $private = json_encode($pdo->query('SELECT * FROM agent_email_matters_v1')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);
    matter_check(!str_contains($private,'fixture_thread') && !str_contains($private,'first_provider_id')
        && !str_contains($private,'PRIVATE_BODY_FIXTURE') && !str_contains(json_encode($view(),JSON_THROW_ON_ERROR),'_enc'),
        'provider identifiers are encrypted and message bodies stay out of persistence and projections');
    matter_check(agent_email_matters_summary_v1($pdo,$otherScope)['items']===[]
        && agent_email_matters_update_v1($pdo,$other,$first['id'],$first['revision'],'dismiss')===null,
        'another account cannot observe or change an exact matter ID');

    $scan(5,2,true);
    matter_check($scanView()['at']===null && $scanView()['total']===0 && $scanView()['in_progress'],
        'an incomplete first scan withholds partial totals');
    $pdo = matter_test_connection($filename);
    $scan(6,3,false);
    $completed = $scanView();
    matter_check($completed['total']===5 && $completed['important']===2 && $completed['from']===null && !$completed['in_progress'],
        'a restarted worker commits both pages as one complete scan');
    $scan(7,0,false);
    matter_check($scanView()['total']===0 && $scanView()['from']===$completed['at'] && $active()===$first,
        'a successful quiet scan records zero and retains open work');

    $dismissed = agent_email_matters_update_v1($pdo,$owner,$first['id'],$first['revision'],'dismiss');
    matter_check($dismissed['stage']==='dismissed' && $view()['items']===[] && ($view()['closed'][0]??null)===$dismissed,
        'the latest dismissed matter can be found for reopening');
    matter_check(!$inbox($message('first_provider_id',10,'Urgent request')) && $view()['items']===[]
        && $inbox($message('new_provider_id',20,'New terms')) && $active()['stage']==='needs_review',
        'only new mail reopens a dismissed matter');
    matter_check(agent_email_matters_update_v1($pdo,$owner,$first['id'],$first['revision'],'dismiss')===null,
        'a stale owner action cannot dismiss newer mail');
    $current = $active();
    $snoozeAt = gmdate('Y-m-d H:i:s',time()+20);
    $snoozed = agent_email_matters_update_v1($pdo,$owner,$current['id'],$current['revision'],'snooze',$snoozeAt);
    matter_check($snoozed['stage']==='snoozed' && $inbox($message('during_snooze',30,'Updated terms'))
        && $active()['stage']==='snoozed','snoozing holds attention until its due time while recording a new message');
    $scan(90,0,true);
    matter_check($active()['stage']==='snoozed','an incomplete check does not resume a due matter');
    $scan(91,0,false);
    matter_check($active()['stage']==='needs_review' && $active()['subject']==='Updated terms','a complete check resumes the due matter');

    $operation = str_repeat('c',64);
    $receipt = ['thread_id'=>'fixture_thread','message_id'=>'sent_fixture','operation_key'=>$operation,
        'sent_at'=>time(),'connection_id'=>11,'authorization_key'=>$grant,'to'=>'sender@example.test'];
    $recordSend = static fn(?string $parent): bool => agent_commerce_atomic_pa2(db(),static fn() =>
        agent_email_matters_note_send_v1(db(),$policy,$receipt,$parent,'Updated terms'));
    matter_check(!$recordSend('during_snooze'),'an unverified send cannot move a matter');
    $pdo->prepare("INSERT INTO agent_connection_sends_v1 VALUES (1,11,?,?,'sent',?,?,?)")
        ->execute([$grant,$operation,$receipt['message_id'],$receipt['thread_id'],$receipt['sent_at']]);
    $expires = gmdate('Y-m-d H:i:s',time()+150);
    $nextCheck = gmdate('Y-m-d H:i:s',time()+25);
    $pdo->prepare("INSERT INTO agent_connection_email_followups_v1
        (owner_user_id,operation_key,connection_id,authorization_key,agent_id,conversation_id,account_email,
            recipient_email,sent_message_id,thread_id,status,next_check_at,expires_at)
        VALUES (1,?,11,?,0,41,'owner@example.test','sender@example.test','sent_fixture','fixture_thread','waiting',?,?)")
        ->execute([$operation,$grant,$nextCheck,$expires]);
    matter_check($recordSend('during_snooze') && !$recordSend('during_snooze') && $active()['stage']==='awaiting_reply'
        && $active()['waiting_for']==='sender@example.test' && $active()['next_check_at']===agent_email_matters_iso_v1($nextCheck)
        && $active()['next_wake_at']===agent_email_matters_iso_v1($expires),
        'verified Sent plus a registered exact watch enters one durable wait');
    $reply = $message('watched_provider_id',60001,'They confirmed');
    $watch = ['owner_user_id'=>1,'connection_id'=>11,'authorization_key'=>$grant,'operation_key'=>$operation,'thread_id'=>'fixture_thread'];
    $recordReply = static fn(): bool => agent_commerce_atomic_pa2(db(),static fn() =>
        agent_email_matters_note_watched_reply_v1(db(),$policy,$watch,$reply));
    matter_check($inbox($reply) && !$recordReply() && $active()['stage']==='awaiting_reply',
        'same-thread inbound mail alone cannot satisfy a specific reply watch');
    matter_check($inbox($message('newer_provider_id',60002,'New related activity')) && $active()['stage']==='awaiting_reply',
        'newer mail in the same thread remains visible while the exact watch is unresolved');
    $pdo->prepare("UPDATE agent_connection_email_followups_v1 SET status='delivered',reply_message_id=?,reply_chat_message_id=601
        WHERE operation_key=?")->execute([$reply['message_id'],$operation]);
    matter_check($recordReply() && !$recordReply() && $active()['stage']==='reply_received' && $active()['next_wake_at']===null
        && $active()['subject']==='New related activity'
        && $active()['reply_result']===['agent_id'=>0,'conversation_id'=>41,'message_id'=>601]
        && (int)$pdo->query("SELECT chat_message_id FROM agent_email_matter_events_v1 WHERE event_kind='watched_reply'")->fetchColumn()===601,
        'the verified exact watch receipt advances the matter once and links its Chat result');
    $current = $active();
    $resolved = agent_email_matters_update_v1($pdo,$owner,$current['id'],$current['revision'],'resolve');
    matter_check($resolved['stage']==='resolved' && $view()['items']===[]
        && agent_email_matters_update_v1($pdo,$owner,$resolved['id'],$resolved['revision'],'reopen')['stage']==='needs_review',
        'a resolution remains reopenable through an explicit owner decision');

    $sentWithoutWatch = array_replace($receipt,['thread_id'=>'unwatched_thread','message_id'=>'unwatched_send',
        'operation_key'=>str_repeat('e',64)]);
    $sendLedger = $pdo->prepare("INSERT INTO agent_connection_sends_v1 VALUES (1,11,?,?,'sent',?,?,?)");
    $persistSend = static function (array $send) use ($sendLedger,$grant): void {
        $sendLedger->execute([$grant,$send['operation_key'],$send['message_id'],$send['thread_id'],$send['sent_at']]);
    };
    $recordVerifiedSend = static fn(array $send,?string $parent,string $subject): bool => agent_commerce_atomic_pa2(db(),static fn() =>
        agent_email_matters_note_send_v1(db(),$policy,$send,$parent,$subject));
    $itemsBySubject = static fn(): array => array_column($view()['items'],null,'subject');
    $persistSend($sentWithoutWatch);
    matter_check($recordVerifiedSend($sentWithoutWatch,null,'Unwatched send')
        && $itemsBySubject()['Unwatched send']['stage']==='actioned'
        && $itemsBySubject()['Unwatched send']['direction']==='outbound',
        'a verified send without a native watch records an action and no waiting claim');
    matter_check($inbox($message('late_first_inbound',10,'Preceding email','unwatched_thread'))
        && $itemsBySubject()['Preceding email']['stage']==='actioned'
        && $itemsBySubject()['Preceding email']['direction']==='inbound',
        'the first delayed inbound observation adds context without undoing a newer verified send');
    matter_check($inbox(array_replace($message('new_unwatched_inbound',10,'Later response','unwatched_thread'),
        ['received_at_ms'=>($sentWithoutWatch['sent_at']+1)*1000])) && $itemsBySubject()['Later response']['stage']==='needs_review',
        'a message received after a verified no-watch send opens fresh review');
    $lateReceipt = array_replace($receipt,['sent_at'=>$receipt['sent_at']-1,'message_id'=>'old_sent_fixture','operation_key'=>str_repeat('f',64)]);
    $persistSend($lateReceipt);
    matter_check($recordVerifiedSend($lateReceipt,'during_snooze','Updated terms')
        && $itemsBySubject()['New related activity']['stage']==='needs_review',
        'a late-verified send keeps its receipt without settling newer inbound activity');

    $expiringSend = array_replace($receipt,['thread_id'=>'expiring_thread','message_id'=>'expiring_send','operation_key'=>str_repeat('1',64)]);
    $persistSend($expiringSend);
    $pdo->prepare("INSERT INTO agent_connection_email_followups_v1
        (owner_user_id,operation_key,connection_id,authorization_key,agent_id,conversation_id,account_email,
            recipient_email,sent_message_id,thread_id,status,next_check_at,expires_at)
        VALUES (1,?,11,?,0,41,'owner@example.test','sender@example.test','expiring_send','expiring_thread','waiting',?,?)")
        ->execute([$expiringSend['operation_key'],$grant,$nextCheck,$expires]);
    matter_check($recordVerifiedSend($expiringSend,null,'Expiring reply wait')
        && $itemsBySubject()['Expiring reply wait']['stage']==='awaiting_reply','a new verified thread can start a reply wait');
    $pdo->prepare('UPDATE agent_connection_email_followups_v1 SET expires_at=?,next_check_at=NULL WHERE operation_key=?')
        ->execute([gmdate('Y-m-d H:i:s',time()-1),$expiringSend['operation_key']]);
    $expired = $itemsBySubject()['Expiring reply wait'];
    matter_check($expired['stage']==='followup_due' && $expired['next_wake_at']===null && $expired['next_check_at']===null
        && $view()['counts']['followup_due']===1 && $view()['counts']['awaiting_reply']===0,
        'native expiry is immediately visible even before the next successful scan');
    $deferred = agent_email_matters_update_v1($pdo,$owner,$expired['id'],$expired['revision'],'snooze',$snoozeAt);
    matter_check($deferred['stage']==='snoozed' && agent_email_matters_update_v1(
        $pdo,$owner,$deferred['id'],$deferred['revision'],'reopen')['stage']==='followup_due',
        'an owner can snooze and resume a projected native watch expiry');

    $stoppedSend = array_replace($receipt,['thread_id'=>'stopped_thread','message_id'=>'stopped_send','operation_key'=>str_repeat('2',64)]);
    $persistSend($stoppedSend);
    $pdo->prepare("INSERT INTO agent_connection_email_followups_v1
        (owner_user_id,operation_key,connection_id,authorization_key,agent_id,conversation_id,account_email,
            recipient_email,sent_message_id,thread_id,status,next_check_at,expires_at)
        VALUES (1,?,11,?,0,41,'owner@example.test','sender@example.test','stopped_send','stopped_thread','waiting',?,?)")
        ->execute([$stoppedSend['operation_key'],$grant,$nextCheck,$expires]);
    matter_check($recordVerifiedSend($stoppedSend,null,'Stopped reply wait')
        && $itemsBySubject()['Stopped reply wait']['stage']==='awaiting_reply','a matching registered native watch begins active');
    $setWatch = $pdo->prepare("UPDATE agent_connection_email_followups_v1
        SET status='stopped',next_check_at=NULL,authorization_key=? WHERE operation_key=?");
    $setWatch->execute([str_repeat('7',64),$stoppedSend['operation_key']]);
    matter_check($itemsBySubject()['Stopped reply wait']['stage']==='awaiting_reply',
        'a native watch under a different grant cannot change the matter outcome');
    $setWatch->execute([$grant,$stoppedSend['operation_key']]);
    matter_check($itemsBySubject()['Stopped reply wait']['stage']==='followup_due'
        && $itemsBySubject()['Stopped reply wait']['next_check_at']===null,
        'a stopped native watch immediately shows follow-up due without a checker');
    $scan(92,0,false);
    $statusQuery = $pdo->prepare('SELECT stage FROM agent_email_matters_v1 WHERE matter_id=?');
    $statusQuery->execute([$itemsBySubject()['Stopped reply wait']['id']]);
    matter_check($statusQuery->fetchColumn()==='followup_due','the next successful scan persists an early-stopped native watch outcome');
    $scan(600,0,false);
    matter_check($itemsBySubject()['Expiring reply wait']['stage']==='followup_due',
        'an expired wait makes follow-up due without claiming a reply or sending one');

    $completed = $scanView();
    $scan(601,7,true);
    matter_check($scanView()['in_progress'] && $scanView()['at']===$completed['at'],'pending pages retain the last successful count');
    agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$policy,$start): void {
        agent_email_matters_reset_scan_v1($pdo,$policy);
        $pdo->prepare('UPDATE agent_connection_gmail_inbox_v1 SET enabled_at_ms=? WHERE owner_user_id=1')->execute([$start+1]);
    });
    $restarted = agent_connection_gmail_inbox_bound_scope_v1($pdo,$owner);
    matter_check($restarted['generation']!==$scope['generation']
        && agent_email_matters_scan_status_v1($pdo,$restarted)===$completed
        && agent_email_matters_summary_v1($pdo,$restarted)['items']===$view()['items'],
        'an explicit cursor restart drops partial counts and preserves verified work under the same grant');
    $replacementKey = str_repeat('d',64);
    $pdo->prepare('UPDATE agent_connections_v1 SET authorization_key=? WHERE id=11')->execute([$replacementKey]);
    $pdo->prepare('UPDATE agent_connection_gmail_inbox_v1 SET authorization_key=? WHERE owner_user_id=1')->execute([$replacementKey]);
    $replacement = agent_connection_gmail_inbox_bound_scope_v1($pdo,$owner);
    matter_check(agent_email_matters_summary_v1($pdo,$replacement)['items']===[]
        && agent_email_matters_scan_status_v1($pdo,$replacement)===null
        && agent_email_matters_update_v1($pdo,$owner,$first['id'],$active()['revision'],'dismiss')===null,
        'a replaced mailbox grant cannot expose or mutate the previous grant work');
    agent_email_matters_clear_v1($pdo,1);
    matter_check((int)$pdo->query('SELECT COUNT(*) FROM agent_email_matters_v1')->fetchColumn()===0
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_email_matter_events_v1')->fetchColumn()===0
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_email_matter_scans_v1')->fetchColumn()===0,
        'revoking the Inbox Review policy clears that owner’s retained matters, events, and scan counts');
} finally {
    unset($pdo);
    unlink($filename);
    unlink($fixture.'/private/ai-key.php');
    rmdir($fixture.'/private');
    rmdir($fixture);
}
