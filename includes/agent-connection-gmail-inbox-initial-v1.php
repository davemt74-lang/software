<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-gmail-inbox-v1.php';

function agent_connection_gmail_inbox_initial_install_v1(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('First review storage cannot be installed inside a transaction.');
    $sqlite = agent_commerce_sqlite_pa2($pdo);
    $owner = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_connection_gmail_inbox_initial_v1 (
        owner_user_id $owner PRIMARY KEY,
        connection_id BIGINT UNSIGNED NOT NULL,
        generation_id BIGINT UNSIGNED NOT NULL,
        rules_hash CHAR(64) NOT NULL,
        run_id CHAR(32) NOT NULL,
        days TINYINT UNSIGNED NOT NULL,
        started_at_ms BIGINT UNSIGNED NOT NULL,
        phase VARCHAR(16) NOT NULL,
        checkpoint_json TEXT NULL,
        report_json TEXT NULL,
        claim_key CHAR(32) NULL,
        lease_until DATETIME NULL,
        next_run_at DATETIME NULL,
        failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (connection_id) REFERENCES agent_connections_v1(id) ON DELETE CASCADE".($sqlite ? ')' : ",
        INDEX idx_gmail_first_review_due_v1 (next_run_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin"));
    if ($sqlite) $pdo->exec('CREATE INDEX IF NOT EXISTS idx_gmail_first_review_due_v1 ON agent_connection_gmail_inbox_initial_v1 (next_run_at)');
}

function agent_connection_gmail_inbox_initial_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT owner_user_id,connection_id,generation_id,rules_hash,run_id,days,started_at_ms,phase,
            checkpoint_json,report_json,claim_key,lease_until,next_run_at,failures,updated_at
            FROM agent_connection_gmail_inbox_initial_v1 WHERE 1=0');
        return true;
    } catch (PDOException) { return false; }
}

function agent_connection_gmail_inbox_initial_purge_v1(PDO $pdo, int $owner): void
{
    if (agent_connection_gmail_inbox_initial_ready_v1($pdo)) {
        $pdo->prepare('DELETE FROM agent_connection_gmail_inbox_initial_v1 WHERE owner_user_id=?')->execute([$owner]);
        if (table_exists('notifications')) $pdo->prepare("DELETE FROM notifications WHERE user_id=?
            AND source_type='agent_connection_gmail_inbox' AND type='agent_email_inbox_review' AND title='First Gmail review ready'")
            ->execute([$owner]);
    }
}

/** Lock in the same order as Inbox Review settings and normal cursor commits. */
function agent_connection_gmail_inbox_initial_scope_v1(PDO $pdo, array $user, bool $lock=false): array
{
    $owner = (int)($user['id']??0);
    if ($owner<1 || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1()!==null)) return ['state'=>'disabled'];
    $live = agent_connection_gmail_inbox_user_v1($pdo,$owner,$lock);
    if (!$live || empty($live['is_active']) || !has_permission('account.access',$live) || !has_permission('chat.access',$live)) return ['state'=>'disabled'];
    if (!agent_connection_gmail_inbox_ready_v1($pdo)) return ['state'=>'disabled'];
    $policy = agent_connection_gmail_inbox_policy_v1($pdo,$owner);
    if (empty($policy['enabled'])) return ['state'=>'disabled'];
    $connection = agent_connections_find_v1($pdo,$owner,(int)$policy['connection_id'],$lock && !agent_commerce_sqlite_pa2($pdo));
    if ($lock) $policy = agent_connection_gmail_inbox_policy_v1($pdo,$owner,true);
    if (empty($policy['enabled'])) return ['state'=>'disabled'];
    $generation = agent_connection_gmail_inbox_notice_generation_v1($policy);
    if ($generation===null || !agent_connection_gmail_inbox_grant_v1($live,$connection,$policy)
        || !agent_connection_gmail_ready_v1()
        || !hash_equals((string)$connection['client_fingerprint'],agent_connections_client_fingerprint_v1())
        || !agent_connection_gmail_inbox_initial_ready_v1($pdo)) return ['state'=>'unavailable'];
    return ['state'=>'ready','owner'=>$owner,'user'=>$live,'policy'=>$policy,'generation'=>$generation,
        'rules_hash'=>hash('sha256',(string)$policy['rules_json'])];
}

function agent_connection_gmail_inbox_initial_row_v1(PDO $pdo, array $scope, bool $lock=false): ?array
{
    if ($scope['state']!=='ready') return null;
    $q = $pdo->prepare('SELECT * FROM agent_connection_gmail_inbox_initial_v1 WHERE owner_user_id=? AND connection_id=?
        AND generation_id=? AND rules_hash=?'.($lock?agent_commerce_lock_pa2($pdo):''));
    $q->execute([$scope['owner'],$scope['policy']['connection_id'],$scope['generation'],$scope['rules_hash']]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function agent_connection_gmail_inbox_initial_checkpoint_v1(array $row): array
{
    if ($row['checkpoint_json']===null) return ['page'=>'','pages'=>0,'scanned'=>0,'seen'=>[],
        'total'=>0,'important'=>0,'replies'=>0,'filtered'=>0,'items'=>[],'truncated'=>false];
    return json_decode($row['checkpoint_json'],true,12,JSON_THROW_ON_ERROR);
}

/** A start must describe the Gmail settings rendered when the owner reviewed the token notice. */
function agent_connection_gmail_inbox_initial_start_token_v1(array $scope): string
{
    return hash('sha256',implode(':',[$scope['owner'],$scope['generation'],$scope['rules_hash'],$scope['policy']['revision']]));
}

/** The private Chat projection omits message headers and all provider identifiers. */
function agent_connection_gmail_inbox_initial_chat_result_v1(array $report, array $policy): array
{
    $result = ['summary'=>($report['summary_status'] ?? '') === 'ready' && is_string($report['summary'] ?? null)
        ? mb_substr($report['summary'], 0, 1000) : ''];
    $latest = json_decode((string)($policy['last_review_json'] ?? ''), true, 12);
    if (!is_array($latest) || !is_string($latest['at'] ?? null)
        || !preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $latest['at'])) return $result;
    $counts = [];
    foreach (['total','important','replies'] as $key) {
        if (!is_int($latest[$key] ?? null) || $latest[$key] < 0 || $latest[$key] > 100) return $result;
        $counts[$key] = $latest[$key];
    }
    if ($counts['important'] > $counts['total'] || $counts['replies'] > $counts['total']) return $result;
    return $result + ['latest'=>$counts + ['at'=>str_replace(' ', 'T', $latest['at']).'Z']];
}

/** Read only. The default status view omits all mailbox details. */
function agent_connection_gmail_inbox_initial_view_v1(PDO $pdo, array $user, bool $includeReport=false, ?string $expectedRevision=null, bool $includeChatResult=false): array
{
    $scope = agent_connection_gmail_inbox_initial_scope_v1($pdo,$user);
    if ($scope['state']!=='ready') return ['state'=>$scope['state'],'limit'=>50];
    if ($expectedRevision!==null && !hash_equals($scope['policy']['revision'],$expectedRevision)) return ['state'=>'unavailable','limit'=>50];
    $start = $includeReport ? ['start_token'=>agent_connection_gmail_inbox_initial_start_token_v1($scope)] : [];
    $row = agent_connection_gmail_inbox_initial_row_v1($pdo,$scope);
    if (!$row) return ['state'=>'needs_start','limit'=>50]+$start;
    $phase = $row['phase'];
    $report = $phase==='ready' ? json_decode((string)$row['report_json'],true,12,JSON_THROW_ON_ERROR) : null;
    $progress = $report ?? agent_connection_gmail_inbox_initial_checkpoint_v1($row);
    $state = match ($phase) {
        'ready'=>'ready', 'failed'=>'failed',
        default=>($row['claim_key']!==null && (string)$row['lease_until']>agent_commerce_time_pa2() ? 'working' : 'queued'),
    };
    $view = ['state'=>$state,'run_id'=>$row['run_id'],'days'=>(int)$row['days'],'limit'=>50,
        'stage'=>in_array($phase,['scan','failed'],true)?'scan':'summary','scanned'=>(int)$progress['scanned'],'truncated'=>!empty($progress['truncated']),
        'has_report'=>$report!==null,'updated_at'=>str_replace(' ','T',$row['updated_at']).'Z'];
    if ($report!==null) $view['total'] = (int)$report['total'];
    if ($includeReport && $report!==null) $view['report'] = $report;
    if ($includeChatResult && $report!==null) {
        $live = agent_connection_gmail_inbox_initial_scope_v1($pdo, $user);
        if ($live['state'] !== 'ready' || $live['generation'] !== $scope['generation']
            || $live['policy']['revision'] !== $scope['policy']['revision']
            || (agent_connection_gmail_inbox_initial_row_v1($pdo, $live)['run_id'] ?? '') !== $row['run_id']) {
            return ['state'=>'unavailable','limit'=>50];
        }
        $policy = $live['policy'];
        $view['chat_result'] = agent_connection_gmail_inbox_initial_chat_result_v1($report, $policy);
        if (!empty($policy['claim_key']) && (string)$policy['next_check_at'] > agent_commerce_time_pa2()) {
            $view['inbox_working_until'] = str_replace(' ', 'T', $policy['next_check_at']).'Z';
        }
    }
    $next = $row['lease_until'] ?? $row['next_run_at'];
    if ($next!==null) $view['next_attempt_at'] = str_replace(' ','T',$next).'Z';
    return $view+$start;
}

/** Only an explicit start can authorize a new first review and its metered summary. */
function agent_connection_gmail_inbox_initial_start_v1(PDO $pdo, array $user, int $days, string $startToken): void
{
    if (!in_array($days,[1,3,7,14,30],true)) throw new AgentConnectionExceptionV1('Choose a supported review window.',422);
    agent_commerce_atomic_pa2($pdo,static function() use($pdo,$user,$days,$startToken):void {
        $scope = agent_connection_gmail_inbox_initial_scope_v1($pdo,$user,true);
        if ($scope['state']!=='ready') throw new AgentConnectionExceptionV1('Enable Inbox Review and verify Gmail read access first.',409);
        if (!hash_equals(agent_connection_gmail_inbox_initial_start_token_v1($scope),$startToken)) {
            throw new AgentConnectionExceptionV1('Gmail settings changed. Reload before starting this review.',409);
        }
        $current = agent_connection_gmail_inbox_initial_row_v1($pdo,$scope,true);
        if ($current && $current['phase']!=='failed') return;
        agent_connection_gmail_inbox_initial_purge_v1($pdo,$scope['owner']);
        $now = agent_commerce_time_pa2();
        $pdo->prepare("INSERT INTO agent_connection_gmail_inbox_initial_v1
            (owner_user_id,connection_id,generation_id,rules_hash,run_id,days,started_at_ms,phase,next_run_at,updated_at)
            VALUES (?,?,?,?,?,?,?,'scan',?,?)")
            ->execute([$scope['owner'],$scope['policy']['connection_id'],$scope['generation'],$scope['rules_hash'],
                bin2hex(random_bytes(16)),$days,(int)floor(microtime(true)*1000),$now,$now]);
    });
}

function agent_connection_gmail_inbox_initial_report_v1(array $row, array $progress, array $summary=[]): array
{
    return ['days'=>(int)$row['days'],'scanned'=>(int)$progress['scanned'],'limit'=>50,'truncated'=>!empty($progress['truncated']),
        'total'=>(int)$progress['total'],'important'=>(int)$progress['important'],'replies'=>(int)$progress['replies'],
        'filtered'=>(int)$progress['filtered'],'items'=>$progress['items'],'at'=>agent_commerce_time_pa2(),
        'summary'=>is_string($summary['summary']??null)?$summary['summary']:'',
        'summary_status'=>$summary['status']??'unverified','tokens_charged'=>$summary['tokens_charged']??null];
}

/** Completion and the private bell notice are one transaction. The new-mail cursor is untouched. */
function agent_connection_gmail_inbox_initial_complete_v1(PDO $pdo, array $scope, array $row, array $progress, array $summary=[]): void
{
    $report = agent_connection_gmail_inbox_initial_report_v1($row,$progress,$summary);
    $pdo->prepare("UPDATE agent_connection_gmail_inbox_initial_v1 SET phase='ready',report_json=?,checkpoint_json=NULL,
        claim_key=NULL,lease_until=NULL,next_run_at=NULL,updated_at=? WHERE owner_user_id=? AND run_id=?")
        ->execute([json_encode($report,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),$report['at'],$scope['owner'],$row['run_id']]);
    agent_connection_gmail_inbox_notify_v1($pdo,$scope['owner'],$report+['kind'=>'initial'],$scope['policy']);
}

/** Claim and run one bounded slice; both worker and owner browser share this lease. */
function agent_connection_gmail_inbox_initial_advance_v1(PDO $pdo, array $user, string $runId, ?callable $http=null, ?callable $summarize=null): bool
{
    if ($pdo->inTransaction() || !preg_match('/\A[a-f0-9]{32}\z/D',$runId)) return false;
    $reserved = agent_commerce_atomic_pa2($pdo,static function() use($pdo,$user,$runId):?array {
        $scope = agent_connection_gmail_inbox_initial_scope_v1($pdo,$user,true);
        $row = agent_connection_gmail_inbox_initial_row_v1($pdo,$scope,true);
        $now = agent_commerce_time_pa2();
        if (!$row || !hash_equals($row['run_id'],$runId) || $row['next_run_at']===null || $row['next_run_at']>$now
            || ($row['lease_until']!==null && $row['lease_until']>$now)) return null;
        $progress = agent_connection_gmail_inbox_initial_checkpoint_v1($row);
        if ($row['phase']==='summarizing') {
            // The previous paid attempt may have reached its provider. Never replay it.
            agent_connection_gmail_inbox_initial_complete_v1($pdo,$scope,$row,$progress);
            return null;
        }
        if (!in_array($row['phase'],['scan','summary'],true)) return null;
        $claim = bin2hex(random_bytes(16));
        $pdo->prepare('UPDATE agent_connection_gmail_inbox_initial_v1 SET phase=?,claim_key=?,lease_until=?,updated_at=?
            WHERE owner_user_id=? AND run_id=?')->execute([$row['phase']==='summary'?'summarizing':'scan',$claim,
                gmdate('Y-m-d H:i:s',time()+180),$now,$scope['owner'],$runId]);
        return [$scope,$row,$progress,$claim];
    });
    if (!$reserved) return false;
    [$scope,$row,$progress,$claim] = $reserved;
    $current = static function(bool $lock=false) use($pdo,$user,$runId,$claim):?array {
        $fresh = agent_connection_gmail_inbox_initial_scope_v1($pdo,$user,$lock);
        $latest = agent_connection_gmail_inbox_initial_row_v1($pdo,$fresh,$lock);
        if (!$latest || !hash_equals($latest['run_id'],$runId) || !hash_equals((string)$latest['claim_key'],$claim)
            || (string)$latest['lease_until']<=agent_commerce_time_pa2()) return null;
        return $fresh;
    };
    $scan = null;
    $summary = [];
    try {
        if ($row['phase']==='scan') {
            require_once __DIR__.'/agent-connection-gmail-inbox-initial-scan-v1.php';
            if (!$current()) return false;
            $policy = $scope['policy'];
            $access = agent_connections_access_v1($scope['owner'],(int)$policy['connection_id'],'read',$http,$policy['authorization_key']);
            if (!hash_equals($access['authorization_key'],$policy['authorization_key'])) return false;
            $scan = agent_connection_gmail_inbox_initial_scan_v1($policy,$access['access_token'],(int)$row['started_at_ms'],(int)$row['days'],
                $progress['page'],$progress['scanned'],$progress['pages'],$http,static fn():bool=>$current()!==null,
                static fn(string $key):bool=>isset($progress['seen'][$key]));
        } else {
            require_once __DIR__.'/agent-connection-gmail-inbox-initial-summary-v1.php';
            $before = static function() use($current):void {
                if (!$current()) throw new RuntimeException('First review access changed.',403);
            };
            $fresh = $current();
            if (!$fresh) return false;
            $summary = ($summarize??'agent_connection_gmail_inbox_initial_summary_v1')($pdo,$fresh['user'],
                agent_connection_gmail_inbox_initial_report_v1($row,$progress),$before);
        }
    } catch (Throwable $error) {
        if (function_exists('agent_runtime_v125_trace')) agent_runtime_v125_trace('worker.gmail_first_review.failed',['error_class'=>get_class($error)]);
    }
    return agent_commerce_atomic_pa2($pdo,static function() use($pdo,$current,$row,$progress,$scan,$summary):bool {
        $fresh = $current(true);
        if (!$fresh) return false;
        if ($row['phase']==='summary') {
            agent_connection_gmail_inbox_initial_complete_v1($pdo,$fresh,$row,$progress,$summary);
            return true;
        }
        $now = agent_commerce_time_pa2();
        if (($scan['status']??null)!=='checked') {
            $failures = (int)$row['failures']+1;
            $pdo->prepare('UPDATE agent_connection_gmail_inbox_initial_v1 SET phase=?,claim_key=NULL,lease_until=NULL,
                next_run_at=?,failures=?,checkpoint_json=?,updated_at=? WHERE owner_user_id=?')->execute([$failures>=3?'failed':'scan',
                    $failures>=3?null:gmdate('Y-m-d H:i:s',time()+30*$failures),$failures,
                    $failures>=3?null:$row['checkpoint_json'],$now,$fresh['owner']]);
            return true;
        }
        foreach ($scan['messages'] as $key=>$message) {
            if (isset($progress['seen'][$key])) continue;
            $progress['seen'][$key] = true;
            if ($message===null) continue;
            if (agent_connection_gmail_inbox_ignored_v1($message,$fresh['policy']['rules'])) { $progress['filtered']++; continue; }
            $progress['total']++;
            $progress['important'] += (int)$message['important'];
            $progress['replies'] += (int)$message['reply'];
            if (count($progress['items'])<8) $progress['items'][] = ['from'=>$message['from'],
                'subject'=>mb_substr($message['subject'],0,240,'UTF-8'),
                'category'=>$message['reply']?'reply':($message['important']?'important':'new')];
        }
        foreach (['page','pages','scanned','truncated'] as $field) $progress[$field] = $scan[$field];
        if (!$scan['more'] && $progress['total']===0) {
            agent_connection_gmail_inbox_initial_complete_v1($pdo,$fresh,$row,$progress,['status'=>'no_messages','tokens_charged'=>0]);
            return true;
        }
        $pdo->prepare('UPDATE agent_connection_gmail_inbox_initial_v1 SET phase=?,checkpoint_json=?,claim_key=NULL,
            lease_until=NULL,next_run_at=?,failures=0,updated_at=? WHERE owner_user_id=?')->execute([$scan['more']?'scan':'summary',
                json_encode($progress,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),$now,$now,$fresh['owner']]);
        return true;
    });
}

function agent_connection_gmail_inbox_initial_sweep_v1(PDO $pdo): int
{
    if ($pdo->inTransaction() || !agent_connection_gmail_inbox_initial_ready_v1($pdo)) return 0;
    $q = $pdo->prepare('SELECT i.owner_user_id,i.run_id FROM agent_connection_gmail_inbox_initial_v1 i
        JOIN agent_connection_gmail_inbox_v1 p ON p.owner_user_id=i.owner_user_id AND p.connection_id=i.connection_id AND p.enabled=1
        JOIN agent_connections_v1 c ON c.id=p.connection_id AND c.owner_user_id=p.owner_user_id
            AND c.authorization_key=p.authorization_key AND c.status=\'connected\' AND c.read_enabled=1
        JOIN users u ON u.id=i.owner_user_id AND u.is_active=1
        WHERE i.next_run_at<=? AND (i.lease_until IS NULL OR i.lease_until<=?) ORDER BY i.next_run_at,i.owner_user_id LIMIT 5');
    $now = agent_commerce_time_pa2();
    $q->execute([$now,$now]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (agent_connection_gmail_inbox_initial_advance_v1($pdo,['id'=>(int)$row['owner_user_id']],$row['run_id'])) return 1;
    }
    return 0;
}
