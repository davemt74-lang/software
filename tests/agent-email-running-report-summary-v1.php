<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/ai-settings.php';
require_once dirname(__DIR__).'/includes/agent-email-running-report-v1.php';

$root = sys_get_temp_dir().'/vp3-email-summary-'.bin2hex(random_bytes(8));
mkdir($root,0700);
define('STONEFELLOW_ROOT',$root);

function report_summary_check(bool $pass, string $description): void
{
    if (!$pass) throw new RuntimeException($description);
    echo 'PASS '.$description.PHP_EOL;
}

try {
    $pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY); CREATE TABLE agent_connections_v1 (id INTEGER PRIMARY KEY)');
    agent_email_running_report_install_v1($pdo);
    $policy = ['owner_user_id'=>1,'connection_id'=>2,'authorization_key'=>str_repeat('a',64),'enabled'=>true,'enabled_at_ms'=>1,
        'rules'=>['triage_enabled'=>true,'content_analysis'=>true]];
    $otherPolicy = array_replace($policy,['authorization_key'=>str_repeat('b',64)]);
    $view = static fn(array $current = []): ?array => agent_email_running_report_view_v1($pdo,['policy'=>$current ?: $policy]);
    report_summary_check($view()===['pending'=>0,'unavailable'=>0,'findings'=>[]],
        'an inbox without analyzed content has no manufactured summary');

    $insert = $pdo->prepare('INSERT INTO agent_email_running_report_v1
        (thread_key,owner_user_id,connection_id,grant_key,source_triage_id,received_ms,importance,due_at,failures,
            finding_enc,finding_priority,finding_state,analyzed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $save = static function (array $policy, string $id, string $category, string $state, int $priority,
        string $summary, string $reason, bool $updating=false) use ($insert): void {
        $source = hash('sha256','source-'.$id);
        $finding = ['category'=>$category,'state'=>$state,'priority'=>['low','normal','high'][$priority],
            'summary'=>$summary,'importance_reason'=>$reason,'item_ref'=>$source,'evidence_message_id'=>'private-'.$id];
        $insert->execute([hash('sha256',$id),...agent_email_matters_policy_scope_v1($policy),$source,1,0,
            $updating?'2030-01-01 00:00:00':null,0,ai_encrypt_secret(json_encode($finding,JSON_THROW_ON_ERROR)),
            $priority,$state,'2026-01-01 00:00:00']);
    };
    $save($policy,'current','action_required','open',2,'The caterer requested a head count.',
        'The catering delivery slot depends on confirming the head count today.');
    $save($policy,'repeat','routine','open',2,'The caterer followed up.',
        'The catering delivery slot depends on confirming the head count today');
    $save($policy,'next','opportunity','open',1,'A venue offered a Friday booking.',
        'Friday is the only date the venue offered');
    $save($policy,'old','routine','resolved',2,'Last week’s order shipped.','The old shipment is already in transit.');
    $save($policy,'stale','action_required','open',2,'Earlier quote expired.','The expired quote needs a deposit.',true);
    $save($policy,'junk','likely_junk','open',2,'Unsolicited pitch.','The unsolicited pitch asks for a response.');
    $save($otherPolicy,'other','action_required','open',2,'A new event was proposed.','The proposed date is on a holiday.');

    $report = $view();
    report_summary_check($report['summary']==='The catering delivery slot depends on confirming the head count today. Friday is the only date the venue offered.',
        'the overview combines distinct current evidence and leaves stale, resolved and junk findings below it');
    $current = array_column($report['findings'],null,'id')[hash('sha256','current')];
    report_summary_check($current['item_ref']===hash('sha256','source-current') && !isset($current['evidence_message_id']),
        'the overview preserves the authorized source action without exposing the provider message ID');
    report_summary_check($view($otherPolicy)['summary']==='The proposed date is on a holiday.',
        'saved analysis is scoped to the inbox grant');
    $disabled = array_replace($policy,['rules'=>['triage_enabled'=>true,'content_analysis'=>false]]);
    report_summary_check($view($disabled)===null,'turning off analysis hides the overview and findings');

    $pdo->exec('DELETE FROM agent_email_running_report_v1');
    $save($policy,'same','decision','open',2,'Choose a quote.','Choose a quote');
    report_summary_check(!isset($view()['summary']),'a reason that repeats the finding is not a second summary');
    $save($policy,'newsletter','newsletter','informational',2,'A weekly digest arrived.','The digest contains upcoming headlines.');
    $save($policy,'resolved','routine','resolved',1,'The venue accepted Friday.','The final date is confirmed');
    report_summary_check($view()['summary']==='The final date is confirmed.',
        'a verified outcome can lead when no distinct open finding needs attention');
} finally {
    foreach (glob($root.'/private/*') ?: [] as $file) unlink($file);
    if (is_dir($root.'/private')) rmdir($root.'/private');
    rmdir($root);
}
