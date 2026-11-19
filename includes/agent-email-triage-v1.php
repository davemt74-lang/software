<?php
declare(strict_types=1);

require_once __DIR__.'/agent-email-matters-v1.php';
require_once __DIR__.'/agent-email-triage-assessment-v1.php';

function agent_email_triage_install_v1(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('Email triage storage cannot be installed inside a transaction.');
    $sqlite = agent_commerce_sqlite_pa2($pdo);
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
    $index = $sqlite ? '' : ', INDEX idx_email_triage_groups_v1 (owner_user_id,connection_id,grant_key,group_key,received_ms,triage_id)';
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_email_triage_v1 (
        triage_id CHAR(64) PRIMARY KEY,
        owner_user_id INT UNSIGNED NOT NULL,
        connection_id BIGINT UNSIGNED NOT NULL,
        grant_key CHAR(64) NOT NULL,
        message_id_enc VARCHAR(512) NOT NULL,
        thread_id_enc VARCHAR(512) NULL,
        matter_id CHAR(64) NULL,
        received_ms BIGINT UNSIGNED NOT NULL,
        sender_address VARCHAR(254) NOT NULL,
        subject VARCHAR(240) NOT NULL,
        context_key VARCHAR(24) NOT NULL,
        purpose_key VARCHAR(24) NOT NULL,
        sender_key VARCHAR(24) NOT NULL,
        attention_key VARCHAR(24) NOT NULL,
        group_key VARCHAR(24) NOT NULL,
        reason_key VARCHAR(32) NOT NULL,
        due_date CHAR(10) NULL,
        due_at CHAR(20) NULL,
        is_protected TINYINT NOT NULL DEFAULT 0,
        is_historical TINYINT NOT NULL DEFAULT 0,
        should_alert TINYINT NOT NULL DEFAULT 0,
        observed_at DATETIME NOT NULL,
        revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (connection_id) REFERENCES agent_connections_v1(id) ON DELETE CASCADE
        $index
    )".$tail);
    if ($sqlite) $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_triage_groups_v1
        ON agent_email_triage_v1 (owner_user_id,connection_id,grant_key,group_key,received_ms,triage_id)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_email_triage_sender_rules_v1 (
        owner_user_id INT UNSIGNED NOT NULL,
        sender_hash CHAR(64) NOT NULL,
        attention_key VARCHAR(24) NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (owner_user_id,sender_hash),
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$tail);
}

function agent_email_triage_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT triage_id,attention_key,group_key,revision FROM agent_email_triage_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,sender_hash,attention_key FROM agent_email_triage_sender_rules_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

/** Inbox opt-out removes mailbox-derived data. Explicit account preferences survive reconnects. */
function agent_email_triage_clear_v1(PDO $pdo,int $owner): void
{
    if ($owner < 1 || !agent_email_triage_ready_v1($pdo)) return;
    $pdo->prepare('DELETE FROM agent_email_triage_v1 WHERE owner_user_id=?')->execute([$owner]);
}

function agent_email_triage_scope_v1(array $scope): ?array
{
    return ($scope['policy']['rules']['triage_enabled'] ?? false) === true
        ? agent_email_matters_bound_scope_v1($scope) : null;
}

function agent_email_triage_groups_v1(): array
{
    return ['active'=>'Needs attention','waiting'=>'Waiting','historical'=>'Catch-up','promotion'=>'Promotions',
        'newsletter'=>'Newsletters','update'=>'Updates','receipt'=>'Receipts','other'=>'Other mail'];
}

function agent_email_triage_attention_choices_v1(): array
{
    return ['act_soon','review','read_later','informational'];
}

function agent_email_triage_group_key_v1(string $attention,string $purpose,bool $historical): string
{
    if (in_array($attention,['act_soon','review'],true)) return 'active';
    if ($attention === 'waiting') return 'waiting';
    if ($historical) return 'historical';
    return in_array($purpose,['promotion','newsletter','update','receipt'],true) ? $purpose : 'other';
}

function agent_email_triage_date_v1(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$value)) return null;
    [$year,$month,$day] = array_map('intval',explode('-',$value));
    return checkdate($month,$day,$year) ? $value : null;
}

function agent_email_triage_deadline_v1(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/\A(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:Z|\+00:00)\z/D',$value,$m)) return null;
    return agent_commerce_date_pa2($m[1].' '.$m[2]) > 0 ? $m[1].'T'.$m[2].'Z' : null;
}

/** Metadata categorization proposes work. A validated body or thread can raise its priority. */
function agent_email_triage_classify_v1(array $message,?string $rule=null,bool $activeFollowup=false,bool $verifiedTransition=false): array
{
    $labels = is_array($message['labels'] ?? null) ? $message['labels'] : [];
    $subject = agent_email_matters_metadata_v1($message['subject'] ?? null,240);
    $mailbox = agent_connection_gmail_address_v1($message['from'] ?? null);
    $automated = !empty($message['automated']) || preg_match('/\A(?:no[-_.]?reply|do[-_.]?not[-_.]?reply|mailer[-_.]?daemon)@/i',$mailbox);
    $signals = agent_connection_gmail_subject_priority_signals_v1($subject);
    [$purpose,$attention,$reason] = match (true) {
        preg_match('/\b(?:payment receipt|order confirmation|invoice paid|receipt for|tax invoice)\b/iu',$subject) === 1
            => ['receipt','informational','receipt_subject'],
        in_array('CATEGORY_PROMOTIONS',$labels,true) => ['promotion','read_later','gmail_promotions'],
        !empty($message['list_unsubscribe']) || preg_match('/\b(?:newsletter|weekly digest|monthly digest)\b/iu',$subject) === 1
            => ['newsletter','read_later','mailing_list'],
        !empty($message['reply']) => ['conversation','informational','reply_header'],
        preg_match('/\b(?:meeting invitation|calendar invitation)\b/iu',$subject) === 1
            => ['meeting','review','meeting_subject'],
        in_array('CATEGORY_UPDATES',$labels,true) || in_array('CATEGORY_SOCIAL',$labels,true) || in_array('CATEGORY_FORUMS',$labels,true)
            => ['update','informational','gmail_updates'],
        default => ['unknown','unknown','insufficient_evidence'],
    };
    $context = 'unknown';
    $sender = $automated ? 'automated_service' : 'unknown';
    $assessment = is_array($message['assessment'] ?? null) ? $message['assessment'] : [];
    $assessmentReason = $assessment['reason'] ?? null;
    $protected = false;
    $dueDate = null;
    $dueAt = null;
    if (!empty($message['important']) || in_array('IMPORTANT',$labels,true)) {
        [$attention,$reason] = ['review','gmail_important'];
    }
    if ($purpose !== 'promotion' && in_array('urgent',$signals,true)) {
        [$attention,$reason] = ['act_soon','urgent_subject'];
    } elseif ($purpose !== 'promotion' && $signals && $attention !== 'review') {
        [$attention,$reason] = ['review','priority_subject'];
    }

    if (($assessment['status'] ?? null) === 'assessed') {
        if (in_array($assessment['context'] ?? null,['personal','business','personal_interest','mixed'],true)) $context = $assessment['context'];
        if (in_array($assessment['sender'] ?? null,['individual','small_business','organization','automated_service'],true)) $sender = $assessment['sender'];
        if ($assessmentReason === 'deadline') {
            $dueDate = agent_email_triage_date_v1($assessment['deadline_date'] ?? null);
            $dueAt = agent_email_triage_deadline_v1($assessment['deadline_at'] ?? null);
            [$purpose,$attention,$reason] = $dueDate !== null || $dueAt !== null
                ? ['request','act_soon','body_deadline'] : ['request','review','body_deadline_unclear'];
            $protected = true;
        } elseif (in_array($assessmentReason,['deadline_unclear','action_requested','reply_change'],true)) {
            $purpose = $assessmentReason === 'reply_change' ? 'conversation' : 'request';
            if ($attention !== 'act_soon') $attention = 'review';
            $reason = match ($assessmentReason) {
                'deadline_unclear'=>'body_deadline_unclear','action_requested'=>'body_request','reply_change'=>'thread_changed',
            };
            $protected = true;
        }
    } elseif (in_array($assessment['status'] ?? null,['deferred','unavailable'],true) && $attention !== 'act_soon') {
        $possibleDeadline = agent_email_triage_assessment_priority_v1($message,false) >= 4;
        if ($possibleDeadline || (in_array($attention,['informational','unknown'],true) && ($signals || $activeFollowup))) {
            [$attention,$reason] = ['review','body_unavailable'];
            $protected = $possibleDeadline;
        }
    }
    if ($activeFollowup || $verifiedTransition) {
        if ($attention !== 'act_soon') $attention = 'review';
        if ($reason !== 'body_deadline' && ($verifiedTransition || $reason !== 'thread_changed')) {
            $reason = $verifiedTransition ? 'verified_reply' : 'open_matter_reply';
        }
        $protected = true;
    }
    if (in_array($rule,agent_email_triage_attention_choices_v1(),true)
        && (!$protected || ($attention === 'review' && $rule === 'act_soon'))) {
        $attention = $rule;
        if (!$protected) $reason = 'sender_rule';
    }
    // Explicit owner priority watches outrank saved sender downgrades and
    // model-led routine classifications; never grant sending authority.
    if (!empty($message['priority_matches'])) {
        $attention = 'act_soon';
        $reason = 'owner_priority_watch';
        $protected = true;
    }
    return ['context'=>$context,'purpose'=>$purpose,'sender'=>$sender,'attention'=>$attention,'reason_key'=>$reason,
        'due_date'=>$dueDate,'due_at'=>$dueAt,'protected'=>$protected];
}

function agent_email_triage_reason_v1(string $reason): string
{
    return match ($reason) {
        'receipt_subject'=>'The subject identifies a receipt or transaction.',
        'gmail_promotions'=>'Gmail placed this message in Promotions.',
        'mailing_list'=>'The message looks like a newsletter or mailing-list update.',
        'reply_header'=>'The message includes a reply header.',
        'meeting_subject'=>'The subject indicates a calendar or meeting invitation.',
        'gmail_updates'=>'The Gmail category indicates a routine update.',
        'gmail_important'=>'Gmail marked this message important.',
        'urgent_subject'=>'The subject explicitly marks the message urgent.',
        'priority_subject'=>'The subject contains a priority or action cue.',
        'body_deadline'=>'The message body contains an actionable deadline.',
        'body_deadline_unclear'=>'The message body suggests a deadline that needs clarification.',
        'body_request'=>'The message body asks for an action.',
        'thread_changed'=>'The reviewed thread contains a change that may affect the next step.',
        'body_unavailable'=>'A priority cue needs review and the message body could not be assessed.',
        'verified_reply'=>'A verified reply advanced an open email matter.',
        'open_matter_reply'=>'This reply may advance an open email matter.',
        'sender_rule'=>'Your saved sender rule set the attention level.',
        'owner_priority_watch'=>'This email matches your high-priority sender or subject list.',
        'owner_correction'=>'You changed the attention level for this message.',
        'owner_digest_action'=>'You acted on this message in the Gmail digest.',
        default=>'The available evidence leaves the purpose or priority unclear.',
    };
}

function agent_email_triage_sender_hash_v1(int $owner,string $address): string
{
    $key = ai_master_key(false);
    if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('Email sender rules need the installation encryption key.');
    return hash_hmac('sha256','vp3-email-triage-sender-v1:'.$owner.':'.$address,$key);
}

function agent_email_triage_find_v1(PDO $pdo,array $scope,string $id,bool $lock=false): ?array
{
    $query = $pdo->prepare('SELECT * FROM agent_email_triage_v1
        WHERE owner_user_id=? AND connection_id=? AND grant_key=? AND triage_id=?'.($lock ? agent_commerce_lock_pa2($pdo) : ''));
    $query->execute([...$scope,$id]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** Resolve an opaque reference inside the current grant before the Gmail read path uses it. */
function agent_email_triage_source_v1(PDO $pdo,array $scope,string $id): ?array
{
    $bound = agent_email_triage_scope_v1($scope);
    if ($bound === null || !preg_match('/\A[a-f0-9]{64}\z/D',$id) || !agent_email_triage_ready_v1($pdo)) return null;
    $row = agent_email_triage_find_v1($pdo,$bound,$id);
    if ($row === null) return null;
    $providerId = agent_connection_gmail_id_v1(ai_decrypt_secret($row['message_id_enc']));
    if ($providerId === '' || !hash_equals($id,agent_email_matters_source_key_v1($scope['policy'],'triage',$providerId))) return null;
    return ['message_id'=>$providerId,'connection_id'=>$bound[1],'authorization_key'=>$scope['policy']['authorization_key']];
}

function agent_email_triage_view_v1(array $row): array
{
    return ['id'=>$row['triage_id'],'revision'=>(int)$row['revision'],'from'=>$row['sender_address'],'subject'=>$row['subject'],
        'received_at'=>gmdate('Y-m-d\TH:i:s\Z',intdiv((int)$row['received_ms'],1000)),
        'context'=>$row['context_key'],'purpose'=>$row['purpose_key'],'sender'=>$row['sender_key'],
        'attention'=>$row['attention_key'],'group'=>$row['group_key'],'reason'=>agent_email_triage_reason_v1($row['reason_key']),
        'due_date'=>$row['due_date'],'due_at'=>$row['due_at'],'matter_id'=>$row['matter_id'],
        'historical'=>(bool)$row['is_historical'],'alert'=>(bool)$row['should_alert']];
}

/** Persist one accepted Gmail message with its cursor. Only new rows may request an alert. */
function agent_email_triage_record_v1(PDO $pdo,array $policy,array $message,string $at,bool $historical=false): ?array
{
    if (($policy['rules']['triage_enabled'] ?? false) !== true) return null;
    $scope = agent_email_matters_policy_scope_v1($policy);
    $providerId = agent_connection_gmail_id_v1($message['message_id'] ?? null);
    $thread = agent_connection_gmail_id_v1($message['thread_id'] ?? null);
    $from = agent_connection_gmail_address_v1($message['from'] ?? null);
    $received = agent_email_matters_received_ms_v1($message['received_at_ms'] ?? null);
    if ($scope === null || $providerId === '' || $from === '' || $received < 1
        || (!$historical && $received <= (int)$policy['enabled_at_ms']) || agent_commerce_date_pa2($at) === 0) return null;
    if (!$pdo->inTransaction()) throw new LogicException('Email triage must commit with the inbox cursor.');
    $id = agent_email_matters_source_key_v1($policy,'triage',$providerId);
    if (agent_email_triage_find_v1($pdo,$scope,$id) !== null) return null;

    $ruleLookup = $pdo->prepare('SELECT attention_key FROM agent_email_triage_sender_rules_v1 WHERE owner_user_id=? AND sender_hash=?');
    $ruleLookup->execute([$scope[0],agent_email_triage_sender_hash_v1($scope[0],$from)]);
    $savedRule = $ruleLookup->fetchColumn();
    $rule = is_string($savedRule) ? $savedRule : null;
    $matterId = $thread === '' ? null : agent_email_matters_source_key_v1($policy,'thread',$thread);
    $matter = $matterId === null || !agent_email_matters_ready_v1($pdo) ? null : agent_email_matters_find_v1($pdo,$scope,$matterId);
    $matterStage = $matter['stage'] ?? null;
    $stage = $matterStage === 'snoozed' ? $matter['resume_stage'] : $matterStage;
    $activeFollowup = ($message['active_followup'] ?? false) === true || (!empty($message['reply'])
        && in_array($stage,['awaiting_reply','followup_due','reply_received'],true)
        && $received >= (int)$matter['latest_received_ms'] && intdiv($received,1000) >= (int)$matter['last_sent_at']);
    $alreadyAnnounced = false;
    if ($matter !== null && !empty($matter['last_operation_key'])) {
        $event = agent_email_matters_source_key_v1($policy,'watched_reply',$matter['last_operation_key'].':'.$providerId);
        $receipt = $pdo->prepare("SELECT 1 FROM agent_email_matter_events_v1
            WHERE matter_id=? AND event_key=? AND event_kind='watched_reply' AND chat_message_id>0");
        $receipt->execute([$matterId,$event]);
        $alreadyAnnounced = $receipt->fetchColumn() !== false;
    }
    $verifiedTransition = ($message['verified_transition'] ?? false) === true || $alreadyAnnounced;
    $message['priority_matches'] = agent_connection_gmail_inbox_priority_matches_v1($message,$policy['rules']);
    $classification = agent_email_triage_classify_v1($message,$rule,$activeFollowup,$verifiedTransition);
    $group = agent_email_triage_group_key_v1($classification['attention'],$classification['purpose'],$historical);
    $assessment = $message['assessment'] ?? null;
    $threadChanged = $activeFollowup && is_array($assessment)
        && ($assessment['status'] ?? null) === 'assessed' && ($assessment['thread_status'] ?? null) === 'verified'
        && in_array($assessment['reply_change'] ?? null,
            ['declined_commitment','changed_commitment','confirmed_commitment','new_request'],true);
    $shouldAlert = !$historical && !$alreadyAnnounced && $matterStage !== 'snoozed'
        && ($classification['attention'] === 'act_soon' || $verifiedTransition || $threadChanged);
    if ($matter === null && $group !== 'active') $matterId = null;

    $query = $pdo->prepare(agent_commerce_insert_ignore_pa2($pdo).' INTO agent_email_triage_v1
        (triage_id,owner_user_id,connection_id,grant_key,message_id_enc,thread_id_enc,matter_id,received_ms,sender_address,subject,
        context_key,purpose_key,sender_key,attention_key,group_key,reason_key,due_date,due_at,is_protected,is_historical,should_alert,observed_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $query->execute([$id,...$scope,ai_encrypt_secret($providerId),$thread === '' ? null : ai_encrypt_secret($thread),$matterId,
        $received,$from,agent_email_matters_metadata_v1($message['subject'] ?? null,240),$classification['context'],
        $classification['purpose'],$classification['sender'],$classification['attention'],$group,$classification['reason_key'],
        $classification['due_date'],$classification['due_at'],(int)$classification['protected'],(int)$historical,(int)$shouldAlert,$at]);
    if ($query->rowCount() !== 1) return null;
    $row = agent_email_triage_find_v1($pdo,$scope,$id);
    if ($row === null) throw new RuntimeException('The saved email classification could not be verified.');
    return agent_email_triage_view_v1($row);
}

function agent_email_triage_summary_v1(PDO $pdo,array $scope): array
{
    $bound = agent_email_triage_scope_v1($scope);
    if ($bound === null || !agent_email_triage_ready_v1($pdo)) return ['groups'=>[],'unreviewed_count'=>0];
    $query = $pdo->prepare('SELECT group_key,COUNT(*) AS total FROM agent_email_triage_v1
        WHERE owner_user_id=? AND connection_id=? AND grant_key=? GROUP BY group_key');
    $query->execute($bound);
    $counts = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $counts[$row['group_key']] = (int)$row['total'];
    $groups = [];
    foreach (agent_email_triage_groups_v1() as $key=>$label) {
        if (!empty($counts[$key])) $groups[] = ['key'=>$key,'label'=>$label,'count'=>$counts[$key]];
    }
    return ['groups'=>$groups,'unreviewed_count'=>$counts['active'] ?? 0];
}

/** An opaque cursor is a scoped item in the same group. New arrivals do not shift subsequent pages. */
function agent_email_triage_group_v1(PDO $pdo,array $scope,string $group,?string $cursor=null,int $limit=20): array
{
    $bound = agent_email_triage_scope_v1($scope);
    $empty = ['items'=>[],'next'=>null];
    if ($bound === null || !isset(agent_email_triage_groups_v1()[$group]) || !agent_email_triage_ready_v1($pdo)) return $empty;
    $args = [...$bound,$group];
    $before = '';
    if ($cursor !== null) {
        if (!preg_match('/\A[a-f0-9]{64}\z/D',$cursor)) return $empty;
        $row = agent_email_triage_find_v1($pdo,$bound,$cursor);
        if ($row === null || $row['group_key'] !== $group) return $empty;
        $before = ' AND (received_ms<? OR (received_ms=? AND triage_id<?))';
        array_push($args,$row['received_ms'],$row['received_ms'],$cursor);
    }
    $limit = max(1,min(50,$limit));
    $query = $pdo->prepare('SELECT * FROM agent_email_triage_v1
        WHERE owner_user_id=? AND connection_id=? AND grant_key=? AND group_key=?'.$before.
        ' ORDER BY received_ms DESC,triage_id DESC LIMIT '.($limit+1));
    $query->execute($args);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    $next = count($rows) > $limit ? $rows[$limit-1]['triage_id'] : null;
    return ['items'=>array_map('agent_email_triage_view_v1',array_slice($rows,0,$limit)),'next'=>$next];
}

function agent_email_triage_correct_v1(PDO $pdo,array $user,string $id,int $revision,string $attention,bool $rememberSender=false): ?array
{
    if ($revision < 1 || !preg_match('/\A[a-f0-9]{64}\z/D',$id)
        || !in_array($attention,agent_email_triage_attention_choices_v1(),true) || !agent_email_triage_ready_v1($pdo)) return null;
    require_once __DIR__.'/agent-connection-gmail-inbox-v1.php';
    return agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$user,$id,$revision,$attention,$rememberSender): ?array {
        $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$user,true);
        $bound = agent_email_triage_scope_v1($scope);
        $row = $bound === null ? null : agent_email_triage_find_v1($pdo,$bound,$id,true);
        if ($row === null || (int)$row['revision'] !== $revision) return null;

        $now = agent_commerce_time_pa2();
        if ($rememberSender) {
            $hash = agent_email_triage_sender_hash_v1($bound[0],$row['sender_address']);
            $pdo->prepare(agent_commerce_insert_ignore_pa2($pdo).' INTO agent_email_triage_sender_rules_v1
                (owner_user_id,sender_hash,attention_key,updated_at) VALUES (?,?,?,?)')
                ->execute([$bound[0],$hash,$attention,$now]);
            $pdo->prepare('UPDATE agent_email_triage_sender_rules_v1 SET attention_key=?,updated_at=?
                WHERE owner_user_id=? AND sender_hash=?')->execute([$attention,$now,$bound[0],$hash]);
        }
        $kept = $rememberSender && !empty($row['is_protected'])
            && (in_array($attention,['read_later','informational'],true) || ($row['attention_key'] === 'act_soon' && $attention === 'review'));
        $effective = $kept ? $row['attention_key'] : $attention;
        $reason = $rememberSender && !empty($row['is_protected'])
            ? $row['reason_key'] : ($rememberSender ? 'sender_rule' : 'owner_correction');
        $group = agent_email_triage_group_key_v1($effective,$row['purpose_key'],(bool)$row['is_historical']);
        $pdo->prepare('UPDATE agent_email_triage_v1 SET attention_key=?,group_key=?,reason_key=?,revision=revision+1 WHERE triage_id=?')
            ->execute([$effective,$group,$reason,$id]);
        $saved = agent_email_triage_find_v1($pdo,$bound,$id);
        if ($saved === null) throw new RuntimeException('The corrected email classification could not be verified.');
        return agent_email_triage_view_v1($saved)+['rule_saved'=>$rememberSender,'correction_applied'=>!$kept];
    });
}
