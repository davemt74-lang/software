<?php
declare(strict_types=1);

require_once __DIR__.'/agent-email-matters-v1.php';

const VP3_GMAIL_OWNER_DIGEST_IMPORTANCE_V1 = 3;

function agent_email_running_report_install_v1(PDO $pdo): void
{
    $sqlite = agent_commerce_sqlite_pa2($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_email_running_report_v1 (
        thread_key CHAR(64) PRIMARY KEY,
        owner_user_id INT UNSIGNED NOT NULL,
        connection_id BIGINT UNSIGNED NOT NULL,
        grant_key CHAR(64) NOT NULL,
        source_triage_id CHAR(64) NOT NULL,
        received_ms BIGINT UNSIGNED NOT NULL,
        importance TINYINT UNSIGNED NOT NULL,
        due_at DATETIME NULL,
        lease_key CHAR(32) NULL,
        failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
        failure_reason VARCHAR(48) NULL,
        finding_enc TEXT NULL,
        finding_priority TINYINT UNSIGNED NULL,
        finding_state VARCHAR(20) NULL,
        analyzed_at DATETIME NULL,
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (connection_id) REFERENCES agent_connections_v1(id) ON DELETE CASCADE".($sqlite ? ')' : ",
        KEY idx_email_running_due_v1 (due_at,importance),
        KEY idx_email_running_owner_v1 (owner_user_id,connection_id,grant_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin"));
    if ($sqlite) {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_running_due_v1 ON agent_email_running_report_v1 (due_at,importance)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_running_owner_v1 ON agent_email_running_report_v1 (owner_user_id,connection_id,grant_key)');
    }
    if (!agent_email_running_report_failure_ready_v1($pdo)) {
        $pdo->exec('ALTER TABLE agent_email_running_report_v1 ADD COLUMN failure_reason VARCHAR(48) NULL');
    }
}

function agent_email_running_report_failure_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT failure_reason FROM agent_email_running_report_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

/** Record stable categories only. Provider text and message content stay private. */
function agent_email_running_report_failure_v1(Throwable $error): string
{
    if ($error instanceof Vp3InitiativeModelDiagnosticV1) return $error->diagnostic;
    if ($error instanceof AgentGmailAnalysisSourceV1) return $error->reason;
    if ($error instanceof AgentConnectionGmailExceptionV1 || $error instanceof AgentConnectionExceptionV1) {
        return match ($error->getCode()) {
            401,403 => 'gmail_access_denied',
            404 => 'gmail_message_unavailable',
            429 => 'gmail_rate_limited',
            default => 'gmail_provider_unavailable',
        };
    }
    if ($error instanceof PDOException) return 'analysis_storage_unavailable';
    return match ($error->getMessage()) {
        'quota_exhausted' => 'analysis_quota_exhausted',
        'route_unavailable' => 'analysis_cloud_unavailable',
        default => 'unknown',
    };
}

function agent_email_running_report_failure_text_v1(mixed $reason): ?string
{
    require_once __DIR__.'/agent-initiative-errors-v1.php';
    return match ($reason) {
        'gmail_message_unavailable' => 'The Gmail source message was unavailable.',
        'gmail_source_ignored' => 'The saved inbox rules excluded the source message.',
        'gmail_no_readable_body' => 'The source message had no readable body.',
        'gmail_access_denied' => 'Gmail denied access to the message or mailbox.',
        'gmail_rate_limited' => 'Gmail limited requests to the mailbox.',
        'gmail_provider_unavailable' => 'Gmail did not complete the message request.',
        'analysis_storage_unavailable' => 'The saved analysis data could not be accessed.',
        'analysis_quota_exhausted' => 'The available VP3 Cloud AI balance was exhausted.',
        'analysis_cloud_unavailable' => 'The default Agent had no permitted Cloud route for Gmail analysis.',
        default => vp3_initiative_model_diagnostic_text_v1($reason),
    };
}

function agent_email_running_report_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT thread_key FROM agent_email_running_report_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function agent_email_running_report_clear_v1(PDO $pdo, int $owner): void
{
    if (!agent_email_running_report_ready_v1($pdo)) return;
    $pdo->prepare('DELETE FROM agent_email_running_report_v1 WHERE owner_user_id=?')->execute([$owner]);
}

function agent_email_running_report_enabled_v1(array $policy): bool
{
    return ($policy['rules']['triage_enabled'] ?? false) === true
        && ($policy['rules']['content_analysis'] ?? false) === true;
}

/** The cursor transaction owns enqueueing. One thread keeps one current finding. */
function agent_email_running_report_enqueue_v1(PDO $pdo, array $policy, array $message, array $triage, string $now): void
{
    $bound = agent_email_matters_policy_scope_v1($policy);
    $thread = agent_connection_gmail_id_v1($message['thread_id'] ?? null);
    $received = agent_email_matters_received_ms_v1($message['received_at_ms'] ?? null);
    if ($bound === null || $thread === '' || $received < 1) return;
    $key = agent_email_matters_source_key_v1($policy,'thread',$thread);
    $query = $pdo->prepare('SELECT received_ms,source_triage_id,failures FROM agent_email_running_report_v1 WHERE thread_key=?');
    $query->execute([$key]);
    $previous = $query->fetch(PDO::FETCH_ASSOC);
    $attention = $triage['attention'];
    if ($previous === false && !in_array($attention,['act_soon','review','unknown'],true)) return;
    if ($previous !== false && ((int)$previous['received_ms'] > $received
        || ((int)$previous['received_ms'] === $received && strcmp($previous['source_triage_id'],$triage['id']) >= 0))) return;
    $importance = match ($attention) {'act_soon'=>2,'review'=>1,default=>0};
    if ($previous === false) {
        $pdo->prepare('INSERT INTO agent_email_running_report_v1
            (thread_key,owner_user_id,connection_id,grant_key,source_triage_id,received_ms,importance,due_at)
            VALUES (?,?,?,?,?,?,?,?)')->execute([$key,...$bound,$triage['id'],$received,$importance,$now]);
        return;
    }
    $resetFailure = (int)$previous['failures'] > 0 && agent_email_running_report_failure_ready_v1($pdo) ? ',failure_reason=NULL' : '';
    $pdo->prepare('UPDATE agent_email_running_report_v1 SET source_triage_id=?,received_ms=?,importance=?,due_at=?,lease_key=NULL,failures=0'
        .$resetFailure.' WHERE thread_key=?')->execute([$triage['id'],$received,$importance,$now,$key]);
}

/** Promote the verified thread without replacing a newer source or interrupting an active analysis claim. */
function agent_email_running_report_prioritize_v1(PDO $pdo,array $policy,array $bound,string $thread,string $triageId,mixed $received): void
{
    $key = agent_email_matters_source_key_v1($policy,'thread',$thread);
    $read = $pdo->prepare('SELECT 1 FROM agent_email_running_report_v1 WHERE thread_key=?
        AND owner_user_id=? AND connection_id=? AND grant_key=?'.agent_commerce_lock_pa2($pdo));
    $read->execute([$key,...$bound]);
    if ($read->fetchColumn() === false) {
        agent_email_running_report_enqueue_v1($pdo,$policy,['thread_id'=>$thread,'received_at_ms'=>$received],
            ['id'=>$triageId,'attention'=>'act_soon'],agent_commerce_time_pa2());
    }
    $pdo->prepare('UPDATE agent_email_running_report_v1 SET importance=?,
        finding_priority=CASE WHEN finding_enc IS NULL THEN finding_priority ELSE 2 END
        WHERE thread_key=? AND owner_user_id=? AND connection_id=? AND grant_key=?')
        ->execute([VP3_GMAIL_OWNER_DIGEST_IMPORTANCE_V1,$key,...$bound]);
}

function agent_email_running_report_scope_v1(PDO $pdo, array $row, bool $lock=false): ?array
{
    $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,['id'=>(int)$row['owner_user_id']],$lock);
    if (($scope['state'] ?? null) !== 'ready' || !$scope['provider_ready']
        || !agent_email_running_report_enabled_v1($scope['policy'])) return null;
    $bound = agent_email_matters_policy_scope_v1($scope['policy']);
    return $bound !== null && $bound[1] === (int)$row['connection_id']
        && hash_equals($bound[2],$row['grant_key']) ? $scope : null;
}

function agent_email_running_report_claim_current_v1(PDO $pdo, array $row, string $claim, string $revision): bool
{
    $scope = agent_email_running_report_scope_v1($pdo,$row);
    if ($scope === null || !hash_equals($revision,$scope['policy']['revision'])) return false;
    $query = $pdo->prepare('SELECT 1 FROM agent_email_running_report_v1 WHERE thread_key=? AND lease_key=? AND due_at>?');
    $query->execute([$row['thread_key'],$claim,agent_commerce_time_pa2()]);
    return $query->fetchColumn() !== false;
}

function agent_email_running_report_process_v1(PDO $pdo, array $row, ?callable $http=null, ?callable $model=null, ?string $policyRevision=null): bool
{
    $scope = agent_email_running_report_scope_v1($pdo,$row);
    if ($scope === null) return false;
    $revision = $scope['policy']['revision'];
    if ($policyRevision !== null && !hash_equals($policyRevision,$revision)) return false;
    $claim = bin2hex(random_bytes(16));
    $reserve = $pdo->prepare('UPDATE agent_email_running_report_v1 SET lease_key=?,due_at=?
        WHERE thread_key=? AND source_triage_id=? AND due_at=? AND due_at<=?');
    $reserve->execute([$claim,gmdate('Y-m-d H:i:s',time()+180),$row['thread_key'],$row['source_triage_id'],
        $row['due_at'],agent_commerce_time_pa2()]);
    if ($reserve->rowCount() !== 1) return false;
    $current = static fn(): bool => agent_email_running_report_claim_current_v1($pdo,$row,$claim,$revision);

    $finding = null;
    $terminal = false;
    $failure = null;
    $coordinationCategories = ['action_required','opportunity','decision'];
    require_once __DIR__.'/agent-connection-gmail-thread-analysis-v1.php';
    try {
        $bound = agent_email_matters_policy_scope_v1($scope['policy']);
        $triage = agent_email_triage_find_v1($pdo,$bound,$row['source_triage_id']);
        $thread = agent_connection_gmail_id_v1(ai_decrypt_secret((string)($triage['thread_id_enc'] ?? '')));
        $message = agent_connection_gmail_id_v1(ai_decrypt_secret((string)($triage['message_id_enc'] ?? '')));
        if ($thread === '' || $message === '') throw new AgentGmailAnalysisSourceV1('gmail_message_unavailable',404);
        if (!$current()) return false;
        if (agent_connection_gmail_inbox_ignored_v1(['from'=>$triage['sender_address'],'subject'=>$triage['subject']],$scope['policy']['rules'])) {
            throw new AgentGmailAnalysisSourceV1('gmail_source_ignored',404);
        }
        $senderRules = $pdo->prepare('SELECT attention_key FROM agent_email_triage_sender_rules_v1 WHERE owner_user_id=? AND sender_hash=?');
        $senderRules->execute([$bound[0],agent_email_triage_sender_hash_v1($bound[0],$triage['sender_address'])]);
        $senderAttention = $senderRules->fetchColumn();
        if (($triage['reason_key'] ?? null) === 'owner_correction') $senderAttention = $triage['attention_key'];
        $access = agent_connections_access_v1((int)$row['owner_user_id'],(int)$row['connection_id'],'read',$http,
            $scope['policy']['authorization_key']);
        $analysis = agent_connection_gmail_thread_analysis_v1($pdo,$scope['user'],$access['access_token'],$thread,$message,
            $http,$current,$model,is_string($senderAttention) ? $senderAttention : null);
        $evidence = agent_email_matters_source_key_v1($scope['policy'],'triage',$analysis['evidence_message_id']);
        $analysis['source_is_evidence'] = agent_email_triage_find_v1($pdo,$bound,$evidence) !== null;
        $analysis['item_ref'] = $analysis['source_is_evidence'] ? $evidence : $row['source_triage_id'];
        $prior = is_string($row['finding_enc'] ?? null) ? json_decode(ai_decrypt_secret($row['finding_enc']),true,8) : null;
        $coordinationChanged = in_array($analysis['category'],$coordinationCategories,true);
        if (!$coordinationChanged) $coordinationChanged = in_array($prior['category'] ?? null,$coordinationCategories,true);
        if (isset($analysis['material_observation'])) {
            $analysis['material_observation']['source_triage_id'] = $row['source_triage_id'];
            $analysis['material_observation']['source_received_ms'] = agent_email_matters_received_ms_v1($row['received_ms']);
            $priorObservation = $prior['material_observation'] ?? null;
            if ($coordinationChanged && is_array($priorObservation)) {
                require_once __DIR__.'/agent-coordination-sources-v1.php';
                $candidate = $prior['coordination_anchor'] ?? ['source_triage_id'=>$priorObservation['source_triage_id'] ?? null,
                    'received_ms'=>$priorObservation['source_received_ms'] ?? null,'finding_enc'=>$row['finding_enc']];
                $anchor = vp3_coordination_gmail_anchor_v1($pdo,$bound,'gmail:'.$row['thread_key'],$triage,$analysis,$candidate);
                if ($anchor !== null) $analysis['coordination_anchor'] = $anchor;
            }
        }
        $finding = [
            'value'=>ai_encrypt_secret(json_encode($analysis,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),
            'priority'=>match ($analysis['priority']) {'high'=>2,'normal'=>1,default=>0},
            'state'=>$analysis['state'],
            'coordination_changed'=>$coordinationChanged,
        ];
    } catch (Throwable $error) {
        $terminal = in_array($error->getCode(),[404,422],true);
        $failure = agent_email_running_report_failure_v1($error);
        if (function_exists('agent_runtime_v125_trace')) {
            agent_runtime_v125_trace('worker.email_running_report.analysis_failed',['reason'=>$failure,'error_class'=>get_class($error)]);
        }
    }

    // Top of Mind reads this saved finding. Analysis never writes a Chat turn.
    $recordFailure = agent_email_running_report_failure_ready_v1($pdo);
    return agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$row,$claim,$revision,$finding,$terminal,$failure,$recordFailure): bool {
        $scope = agent_email_running_report_scope_v1($pdo,$row,true);
        if ($scope === null || !hash_equals($revision,$scope['policy']['revision'])) return false;
        $now = agent_commerce_time_pa2();
        if ($finding !== null) {
            $update = $pdo->prepare('UPDATE agent_email_running_report_v1 SET lease_key=NULL,due_at=NULL,failures=0,
                finding_enc=?,finding_priority=CASE WHEN importance=? THEN 2 ELSE ? END,
                finding_state=?,analyzed_at=?'.($recordFailure?',failure_reason=NULL':'').'
                WHERE thread_key=? AND lease_key=? AND due_at>?');
            $update->execute([$finding['value'],VP3_GMAIL_OWNER_DIGEST_IMPORTANCE_V1,$finding['priority'],$finding['state'],
                $now,$row['thread_key'],$claim,$now]);
        } else {
            $failures = $terminal ? 4 : (int)$row['failures']+1;
            $retry = $failures < 4 ? gmdate('Y-m-d H:i:s',time()+300*(2**($failures-1))) : null;
            $update = $pdo->prepare('UPDATE agent_email_running_report_v1 SET lease_key=NULL,due_at=?,failures=?'.($recordFailure?',failure_reason=?':'').'
                WHERE thread_key=? AND lease_key=? AND due_at>?');
            $update->execute([$retry,$failures,...($recordFailure?[$failure]:[]),$row['thread_key'],$claim,$now]);
        }
        if ($update->rowCount() !== 1) return false;
        if ($finding !== null && $finding['coordination_changed']) {
            // Each claim advances exact source evidence even if its summary is
            // unchanged. Routine new messages leave the scan cadence alone.
            require_once __DIR__.'/agent-coordination-source-change-v1.php';
            vp3_coordination_source_changed_v1($pdo,$scope['owner']);
        }
        return true;
    });
}

/** One metered thread per worker pass, independent of Inbox Review cursor progress. */
function agent_email_running_report_sweep_v1(PDO $pdo, ?callable $http=null, ?callable $model=null, ?int $ownerId=null, ?string $policyRevision=null): int
{
    if ($pdo->inTransaction() || !agent_email_running_report_ready_v1($pdo)) return 0;
    $query = $pdo->prepare("SELECT r.* FROM agent_email_running_report_v1 r
        JOIN users u ON u.id=r.owner_user_id AND u.is_active=1
        JOIN agent_connection_gmail_inbox_v1 p ON p.owner_user_id=r.owner_user_id AND p.connection_id=r.connection_id AND p.enabled=1
        JOIN agent_connections_v1 c ON c.id=r.connection_id AND c.owner_user_id=r.owner_user_id
            AND c.authorization_key=p.authorization_key AND c.status='connected' AND c.read_enabled=1
        WHERE r.due_at<=?".($ownerId === null ? "" : " AND r.owner_user_id=?")." ORDER BY r.importance DESC,r.due_at LIMIT 10");
    $params = [agent_commerce_time_pa2()];
    if ($ownerId !== null) $params[] = $ownerId;
    $query->execute($params);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (agent_email_running_report_process_v1($pdo,$row,$http,$model,$policyRevision)) return 1;
    }
    return 0;
}

function agent_email_running_report_view_v1(PDO $pdo, array $scope): ?array
{
    $policy = $scope['policy'];
    if (!agent_email_running_report_enabled_v1($policy) || !agent_email_running_report_ready_v1($pdo)) return null;
    $bound = agent_email_matters_policy_scope_v1($policy);
    if ($bound === null) return null;
    $where = ' FROM agent_email_running_report_v1 WHERE owner_user_id=? AND connection_id=? AND grant_key=?';
    $counts = $pdo->prepare('SELECT SUM(CASE WHEN due_at IS NOT NULL THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN failures>=4 THEN 1 ELSE 0 END) AS unavailable'.$where);
    $counts->execute($bound);
    $status = $counts->fetch(PDO::FETCH_ASSOC);
    $query = $pdo->prepare('SELECT thread_key,finding_enc,due_at,failures,importance'.$where.'
        AND finding_enc IS NOT NULL ORDER BY CASE WHEN finding_state=\'open\' THEN 0 ELSE 1 END,
        finding_priority DESC,analyzed_at DESC,thread_key LIMIT 20');
    $query->execute($bound);
    $findings = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $finding = json_decode(ai_decrypt_secret($row['finding_enc']),true,8);
        if (!is_array($finding)) continue;
        if ((int)$row['importance'] === VP3_GMAIL_OWNER_DIGEST_IMPORTANCE_V1) $finding['priority'] = 'high';
        $findings[] = array_diff_key($finding,['evidence_message_id'=>true,'material_observation'=>true,'coordination_anchor'=>true])+[
            'id'=>$row['thread_key'],'updating'=>$row['due_at'] !== null || (int)$row['failures'] >= 4,
        ];
    }
    $report = ['pending'=>(int)$status['pending'],'unavailable'=>(int)$status['unavailable'],'findings'=>$findings];
    $summary = agent_email_running_report_summary_v1($findings);
    if ($summary !== '') $report['summary'] = $summary;
    return $report;
}

/** Explain why the highest-ranked current findings matter using their saved analyses. */
function agent_email_running_report_summary_v1(array $findings): string
{
    $reasons = [];
    $keyFor = static fn(string $text): string => mb_strtolower(preg_replace('/[.!?…。！？\s]+$/u','',$text) ?? '');
    foreach ($findings as $finding) {
        $category = $finding['category'] ?? '';
        $state = $finding['state'] ?? '';
        if (!empty($finding['updating']) || $category === 'likely_junk'
            || !in_array($state,['open','resolved','informational'],true)
            || ($state !== 'open' && in_array($category,['newsletter','automated'],true))) continue;
        $reason = agent_email_matters_metadata_v1($finding['importance_reason'] ?? null,320);
        $summary = $keyFor(agent_email_matters_metadata_v1($finding['summary'] ?? null,600));
        $key = $keyFor($reason);
        if ($key === '' || $summary === '' || $key === $summary || isset($reasons[$key])) continue;
        $reasons[$key] = preg_match('/[.!?…。！？]$/u',$reason) === 1 ? $reason : $reason.'.';
        if (count($reasons) === 2) break;
    }
    return implode(' ',$reasons);
}

/** Keep Top of Mind focused on the specific open findings the specialist forwarded. */
function agent_email_running_report_handoffs_v1(array $report): array
{
    $handoffs = ['needs_attention'=>[],'agent_work'=>[]];
    foreach ($report['findings'] as $finding) {
        $target = $finding['handoff'] ?? 'none';
        if (!isset($handoffs[$target]) || count($handoffs[$target]) >= 3
            || $finding['state'] !== 'open' || $finding['updating']) continue;
        $handoffs[$target][] = $finding;
    }
    return $handoffs;
}
