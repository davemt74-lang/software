<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-v1.php';
require_once dirname(__DIR__).'/includes/agent-email-triage-v1.php';

function db(): PDO { return $GLOBALS['pdo']; }
function has_permission(string $permission,array $user): bool
{
    return !empty($user['is_active']) && in_array($permission,['account.access','chat.access'],true);
}
function triage_check(bool $pass,string $description): void
{
    if (!$pass) throw new RuntimeException($description);
    $GLOBALS['triage_checks'] = 1 + ($GLOBALS['triage_checks'] ?? 0);
    echo 'PASS '.$description.PHP_EOL;
}

$fixture = sys_get_temp_dir().'/vp3-email-triage-'.bin2hex(random_bytes(6));
define('STONEFELLOW_ROOT',$fixture);
mkdir($fixture.'/private',0700,true);
file_put_contents($fixture.'/private/ai-key.php','<?php return '.var_export(base64_encode(random_bytes(32)),true).';');
putenv('VP3_GMAIL_CLIENT_ID=vp3-triage-fixture');
putenv('VP3_GMAIL_CLIENT_SECRET=local-test-only');
$filename = $fixture.'/mail.sqlite';
$pdo = new PDO('sqlite:'.$filename,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys=ON');

try {
    $pdo->exec("CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL);
        CREATE TABLE users (id INTEGER PRIMARY KEY, is_active INTEGER NOT NULL);
        INSERT INTO users VALUES (1,1),(2,1);
        CREATE TABLE agent_connections_v1 (id INTEGER PRIMARY KEY, owner_user_id INTEGER NOT NULL,
            provider TEXT NOT NULL, authorization_key TEXT NOT NULL, status TEXT NOT NULL, read_enabled INTEGER NOT NULL,
            client_fingerprint TEXT NOT NULL);");
    triage_check(!agent_email_triage_ready_v1($pdo),'an older schema leaves triage unavailable');
    agent_email_triage_clear_v1($pdo,1);
    agent_connection_gmail_inbox_install_v1($pdo);
    agent_email_matters_install_v1($pdo);
    agent_email_triage_install_v1($pdo);
    agent_email_triage_install_v1($pdo);

    $owner = ['id'=>1];
    $other = ['id'=>2];
    $start = (time()-60)*1000;
    $grant = str_repeat('a',64);
    foreach ([[1,11,$grant],[2,22,str_repeat('b',64)]] as [$account,$connection,$key]) {
        $pdo->prepare("INSERT INTO agent_connections_v1 VALUES (?,?,'gmail',?,'connected',1,?)")
            ->execute([$connection,$account,$key,agent_connections_client_fingerprint_v1()]);
        $pdo->prepare("INSERT INTO agent_connection_gmail_inbox_v1
            (owner_user_id,connection_id,authorization_key,enabled,rules_json,revision,enabled_at_ms)
            VALUES (?,?,?,1,?,?,?)")->execute([$account,$connection,$key,'{"triage_enabled":true}',str_repeat('a',32),$start]);
    }
    $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$owner);
    $otherScope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$other);
    $policy = $scope['policy'];
    $at = agent_commerce_time_pa2();
    $message = static fn(string $id,int $after,string $subject,array $more=[]): array => $more + [
        'thread_id'=>'thread_'.$id,'message_id'=>$id,'received_at_ms'=>$start+$after,
        'from'=>'news@example.test','subject'=>$subject,'important'=>false,'reply'=>false,
        'labels'=>['INBOX','CATEGORY_PROMOTIONS'],'body'=>'PRIVATE_BODY_FIXTURE',
    ];
    $record = static fn(array $message,bool $historical=false): ?array => agent_commerce_atomic_pa2(db(),static fn() =>
        agent_email_triage_record_v1(db(),$policy,$message,$at,$historical));
    $summary = static fn(): array => array_column(agent_email_triage_summary_v1(db(),$scope)['groups'],'count','key');
    $group = static fn(string $key,?string $cursor=null,int $limit=20): array => agent_email_triage_group_v1(db(),$scope,$key,$cursor,$limit);

    $quiet = $message('before_optin',1,'Urgent sale');
    triage_check(agent_commerce_atomic_pa2($pdo,static fn() => agent_email_triage_record_v1($pdo,
        ['rules'=>[]] + $policy,$quiet,$at)) === null
        && agent_email_triage_summary_v1($pdo,['policy'=>['rules'=>[]]+$policy]+$scope)['groups'] === [],
        'mailbox access without triage consent cannot save or expose classifications');
    for ($i=1;$i<=23;$i++) {
        $promo = $record($message('promotion_'.$i,$i,'Urgent sale · edition '.$i));
        if ($promo['alert'] || $promo['group'] !== 'promotion') throw new RuntimeException('Promotions must stay grouped.');
    }
    triage_check($summary() === ['promotion'=>23],'23 urgent-looking promotions produce one inspectable group and no individual alerts');
    triage_check($record($message('promotion_1',1,'Urgent sale · edition 1')) === null && $summary() === ['promotion'=>23],
        'replayed provider messages cannot create a duplicate classification or alert');

    $due = $message('renewal_exact_provider_id',24,'Subscription renewal',[
        'assessment'=>['status'=>'assessed','reason'=>'deadline','deadline_date'=>'2031-02-03','deadline_at'=>'2031-02-03T18:00:00+00:00',
            'context'=>'business','sender'=>'organization','raw_body'=>'PRIVATE_BODY_FIXTURE'],
    ]);
    $renewal = $record($due);
    triage_check($renewal['alert'] && $renewal['attention']==='act_soon' && $renewal['group']==='active'
        && $renewal['due_at']==='2031-02-03T18:00:00Z' && $renewal['due_date']==='2031-02-03'
        && $renewal['context']==='business' && $renewal['sender']==='organization'
        && $summary() === ['active'=>1,'promotion'=>23],'a grounded renewal deadline overrides a promotional category');
    $unknownDeadline = agent_email_triage_classify_v1($message('invalid_date',30,'Possible renewal',[
        'assessment'=>['status'=>'assessed','reason'=>'deadline','deadline_date'=>'2031-02-30','deadline_at'=>'2031-02-30T18:00:00Z'],
    ]),'read_later');
    triage_check($unknownDeadline['due_date']===null && $unknownDeadline['due_at']===null
        && $unknownDeadline['attention']==='review' && $unknownDeadline['reason_key']==='body_deadline_unclear',
        'an invalid or ambiguous date remains visible for review without inventing a deadline');
    $deferredDeadline = agent_email_triage_classify_v1($message('deferred_due',31,'License renewal',[
        'assessment'=>['status'=>'deferred','reason'=>'read_budget'],
    ]),'read_later');
    $highRule = agent_email_triage_classify_v1($due,'act_soon');
    triage_check($deferredDeadline['attention']==='review' && $deferredDeadline['due_date']===null
        && $deferredDeadline['reason_key']==='body_unavailable' && $highRule['reason_key']==='body_deadline',
        'an unassessed renewal stays visible and a sender rule preserves grounded deadline provenance');

    $raw = json_encode($pdo->query('SELECT * FROM agent_email_triage_v1')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);
    $view = json_encode([$summary(),$group('active')],JSON_THROW_ON_ERROR);
    triage_check(!str_contains($raw,'renewal_exact_provider_id') && !str_contains($raw,'PRIVATE_BODY_FIXTURE')
        && !str_contains($view,'_enc') && !str_contains($view,'PRIVATE_BODY_FIXTURE'),
        'triage stores only sealed provider IDs and fixed evidence reasons, without message bodies');
    triage_check(agent_email_triage_source_v1($pdo,$scope,$renewal['id']) === [
        'message_id'=>'renewal_exact_provider_id','connection_id'=>11,'authorization_key'=>$grant,
    ] && agent_email_triage_source_v1($pdo,$otherScope,$renewal['id'])===null
        && agent_email_triage_group_v1($pdo,$otherScope,'active')['items']===[],
        'the server resolves an exact opaque source only for the authorized owner and mailbox grant');

    $first = $group('promotion',null,10);
    $second = $group('promotion',$first['next'],10);
    $third = $group('promotion',$second['next'],10);
    $pageIds = array_column([...$first['items'],...$second['items'],...$third['items']],'id');
    triage_check(count($first['items'])===10 && count($second['items'])===10 && count($third['items'])===3 && $third['next']===null
        && count(array_unique($pageIds))===23 && $group('active',$first['next'],10)['items']===[],
        'scoped keyset pagination exposes the complete group once, including rows with the same labels');
    $wrong = agent_email_triage_correct_v1($pdo,$other,$first['items'][0]['id'],1,'review',true);
    $once = agent_email_triage_correct_v1($pdo,$owner,$first['items'][0]['id'],1,'review');
    $stale = agent_email_triage_correct_v1($pdo,$owner,$first['items'][0]['id'],1,'read_later',true);
    triage_check($wrong===null && $stale===null && $once['group']==='active' && $once['revision']===2
        && !$once['rule_saved'] && $summary() === ['active'=>2,'promotion'=>22],
        'an exact owner correction can pull one message into review without making a sender rule');
    $quietRule = agent_email_triage_correct_v1($pdo,$owner,$renewal['id'],1,'read_later',true);
    triage_check($quietRule['rule_saved'] && !$quietRule['correction_applied']
        && $quietRule['attention']==='act_soon' && $quietRule['due_date']==='2031-02-03',
        'a saved quiet sender rule leaves an existing grounded deadline active');
    $future = $record($message('future_routine',25,'Plain update',['labels'=>['INBOX']]));
    $freshDue = $record($message('future_deadline',26,'Annual subscription',['assessment'=>$due['assessment']]));
    triage_check($future['attention']==='read_later' && $future['reason']==='Your saved sender rule set the attention level.'
        && !$future['alert'] && $freshDue['attention']==='act_soon' && $freshDue['alert'],
        'a standing preference applies to future routine mail while a verified deadline still alerts');

    $old = $record($message('historic_only',-50,'News from last month'),true);
    $oldDue = $record($message('historic_deadline',-40,'Older deadline',['assessment'=>$due['assessment']]),true);
    triage_check($old['historical'] && $old['group']==='historical' && !$old['alert']
        && $oldDue['historical'] && $oldDue['group']==='active' && !$oldDue['alert'],
        'catch-up stays quiet while unresolved historical deadlines remain visible');

    $anchor = $message('original_asked',30,'Please confirm',['thread_id'=>'exact_open_thread','labels'=>['INBOX']]);
    $threadMatterId = agent_email_matters_source_key_v1($policy,'thread','exact_open_thread');
    agent_commerce_atomic_pa2($pdo,static fn() => agent_email_matters_observe_inbox_v1($pdo,$policy,$anchor,$at));
    $pdo->prepare("UPDATE agent_email_matters_v1 SET stage='awaiting_reply',last_operation_key=?,last_sent_at=? WHERE matter_id=?")
        ->execute([str_repeat('c',64),intdiv($start,1000),$threadMatterId]);
    $reply = $record($message('reply_on_open',31,'Re: Please confirm',['thread_id'=>'exact_open_thread','reply'=>true]));
    triage_check($reply['attention']==='review' && $reply['matter_id']===$threadMatterId && !$reply['alert'],
        'the existing open matter overrides a quiet sender rule without claiming an unverified milestone');
    $change = ['status'=>'assessed','reason'=>'reply_change','reply_change'=>'changed_commitment','thread_status'=>'verified'];
    $linked = $record($message('verified_change',32,'Re: Please confirm',[
        'thread_id'=>'exact_open_thread','reply'=>true,'assessment'=>$change,
    ]));
    $unlinked = $record($message('unlinked_change',32,'Re: Please confirm',[
        'thread_id'=>'exact_open_thread','reply'=>true,'assessment'=>['thread_status'=>'unlinked']+$change,
    ]));
    triage_check($linked['alert'] && $linked['attention']==='review'
        && str_contains($linked['reason'],'change that may affect the next step') && !$unlinked['alert'],
        'only an exact linked material reply can raise an alert without claiming work completed');
    $verifiedMessage = $message('known_watch_result',32,'Re: Confirmed',['thread_id'=>'exact_open_thread','reply'=>true]);
    $watchEvent = agent_email_matters_source_key_v1($policy,'watched_reply',str_repeat('c',64).':known_watch_result');
    agent_commerce_atomic_pa2($pdo,static fn() =>
        agent_email_matters_event_v1($pdo,$threadMatterId,$watchEvent,'watched_reply',$at,str_repeat('c',64),42));
    $watched = $record($verifiedMessage);
    triage_check(!$watched['alert'] && $watched['attention']==='review'
        && $watched['reason']==='A verified reply advanced an open email matter.',
        'an exact watched-reply receipt suppresses a second alert for the same change');
    $pdo->prepare("UPDATE agent_email_matters_v1 SET stage='snoozed',resume_stage='awaiting_reply' WHERE matter_id=?")
        ->execute([$threadMatterId]);
    $snoozed = $record($message('snoozed_reply',33,'Re: urgent delivery',
        ['thread_id'=>'exact_open_thread','reply'=>true,'verified_transition'=>true,'labels'=>['INBOX']]));
    triage_check($snoozed['matter_id']===$threadMatterId && !$snoozed['alert'],'an explicit matter snooze keeps the classification without speaking up');

    $newGrant = str_repeat('d',64);
    $pdo->prepare('UPDATE agent_connections_v1 SET authorization_key=? WHERE id=11')->execute([$newGrant]);
    $pdo->prepare('UPDATE agent_connection_gmail_inbox_v1 SET authorization_key=? WHERE owner_user_id=1')->execute([$newGrant]);
    $rotated = agent_connection_gmail_inbox_bound_scope_v1($pdo,$owner);
    triage_check(agent_email_triage_source_v1($pdo,$rotated,$renewal['id'])===null
        && agent_email_triage_correct_v1($pdo,$owner,$renewal['id'],$quietRule['revision'],'review')===null
        && agent_email_triage_summary_v1($pdo,$rotated)['groups']===[],
        'a replacement Gmail grant cannot read or change earlier classification evidence');
    agent_email_triage_clear_v1($pdo,1);
    $afterRegrant = agent_commerce_atomic_pa2($pdo,static fn() => agent_email_triage_record_v1(
        $pdo,$rotated['policy'],$message('future_after_regrant',45,'Routine after reconnect',['labels'=>['INBOX']]),$at));
    triage_check($afterRegrant['attention']==='read_later'
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_email_triage_v1')->fetchColumn()===1
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_email_triage_sender_rules_v1')->fetchColumn()===1,
        'clearing mailbox data preserves a user-authored rule for future eligible mail');
    echo 'AGENT_EMAIL_TRIAGE_V1=PASS ('.$GLOBALS['triage_checks']." checks)\n";
} finally {
    $pdo = null;
    @unlink($filename);
    @unlink($fixture.'/private/ai-key.php');
    @rmdir($fixture.'/private');
    @rmdir($fixture);
}
