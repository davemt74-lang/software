<?php
declare(strict_types=1);

require_once __DIR__.'/agent-commerce-recovery-pa2.php';

function agent_connection_gmail_github_actions_voice_install_v1(PDO $pdo): void
{
    $sqlite = agent_commerce_sqlite_pa2($pdo);
    $owner = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_connection_gmail_github_voice_v1 (
        owner_user_id $owner PRIMARY KEY, generation BIGINT NOT NULL, through_id BIGINT NOT NULL,
        succeeded INT NOT NULL, failed INT NOT NULL, pending_since DATETIME NOT NULL,
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE)"
        .($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'));
}

function agent_connection_gmail_github_actions_voice_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT generation,through_id,succeeded,failed,pending_since FROM agent_connection_gmail_github_voice_v1 WHERE 1=0');
        return true;
    } catch (PDOException) { return false; }
}

function agent_connection_gmail_github_actions_notice_generation_v1(array $policy): int
{
    $binding = implode(':',[$policy['connection_id'],$policy['authorization_key'],$policy['folder_id'],$policy['enabled_at_ms']]);
    return 1 + (int)hexdec(substr(hash('sha256',$binding),0,13));
}

function agent_connection_gmail_github_actions_notice_current_v1(PDO $pdo, array $user, bool $lock = false): ?int
{
    $owner = (int)($user['id'] ?? 0);
    if ($owner < 1) return null;
    try {
        $enabled = $pdo->prepare('SELECT 1 FROM agent_connection_gmail_github_v1 WHERE owner_user_id=? AND enabled=1');
        $enabled->execute([$owner]);
        if (!$enabled->fetchColumn()) return null;
        require_once __DIR__.'/agent-connection-gmail-github-settings-v1.php';
        $connection = agent_connections_find_v1($pdo,$owner,'gmail',$lock && !agent_commerce_sqlite_pa2($pdo));
        if (!agent_connection_gmail_github_authority_v1($user,$connection)) return null;
        $policy = agent_connection_gmail_github_policy_v1($pdo,$owner,$lock);
        if (empty($policy['enabled']) || (int)$policy['connection_id'] !== (int)$connection['id']
            || !hash_equals($policy['authorization_key'],$connection['authorization_key'])
            || !agent_connection_gmail_ready_v1()
            || !hash_equals($connection['client_fingerprint'],agent_connections_client_fingerprint_v1())
            || !agent_connection_gmail_github_folder_v1($pdo,$owner,(int)$policy['folder_id'],$lock)) return null;
        return agent_connection_gmail_github_actions_notice_generation_v1($policy);
    } catch (Throwable) { return null; }
}

function agent_connection_gmail_github_actions_voice_row_v1(PDO $pdo, int $owner, bool $lock = false): ?array
{
    $query = $pdo->prepare('SELECT * FROM agent_connection_gmail_github_voice_v1 WHERE owner_user_id=?'
        .($lock ? agent_commerce_lock_pa2($pdo) : ''));
    $query->execute([$owner]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

function agent_connection_gmail_github_actions_summary_v1(int $succeeded, int $failed): string
{
    $parts = [];
    if ($failed > 0) $parts[] = $failed.' '.($failed === 1 ? 'run failed' : 'runs failed');
    if ($succeeded > 0) $parts[] = $succeeded.' '.($succeeded === 1 ? 'run succeeded' : 'runs succeeded');
    return 'GitHub Actions reports '.implode(' and ',$parts).'.';
}

/** Call only with counts for verified, newly committed Knowledge records. */
function agent_connection_gmail_github_actions_notify_v1(PDO $pdo, int $owner, array $policy, int $succeeded, int $failed): void
{
    if ($owner < 1 || ($succeeded < 1 && $failed < 1) || !table_exists('notifications')) return;
    if (!$pdo->inTransaction()) throw new LogicException('GitHub Actions notices require the worker transaction.');
    $generation = agent_connection_gmail_github_actions_notice_generation_v1($policy);
    $insert = $pdo->prepare('INSERT INTO notifications (user_id,type,title,body,target_url,source_type,source_id)
        VALUES (?,?,?,?,?,?,?)');
    $insert->execute([$owner,'agent_email_github_actions','GitHub Actions update',
        agent_connection_gmail_github_actions_summary_v1($succeeded,$failed),
        url('/knowledge.php?folder='.(int)$policy['folder_id']),'agent_connection_gmail_github',$generation]);
    $through = (int)$pdo->lastInsertId();
    if (!agent_connection_gmail_github_actions_voice_ready_v1($pdo)) return;

    $row = agent_connection_gmail_github_actions_voice_row_v1($pdo,$owner,true);
    $now = gmdate('Y-m-d H:i:s');
    $keep = $row && (int)$row['generation'] === $generation && $row['pending_since'] > gmdate('Y-m-d H:i:s',time()-86400)
        && ((int)$row['succeeded'] > 0 || (int)$row['failed'] > 0);
    $succeeded = min(1000000,$succeeded+($keep ? (int)$row['succeeded'] : 0));
    $failed = min(1000000,$failed+($keep ? (int)$row['failed'] : 0));
    $pendingSince = $keep ? $row['pending_since'] : $now;
    $sql = $row ? 'UPDATE agent_connection_gmail_github_voice_v1 SET generation=?,through_id=?,succeeded=?,failed=?,pending_since=? WHERE owner_user_id=?'
        : 'INSERT INTO agent_connection_gmail_github_voice_v1 (generation,through_id,succeeded,failed,pending_since,owner_user_id) VALUES (?,?,?,?,?,?)';
    $pdo->prepare($sql)->execute([$generation,$through,$succeeded,$failed,$pendingSince,$owner]);
}

function agent_connection_gmail_github_actions_notice_purge_v1(PDO $pdo, int $owner): void
{
    if (table_exists('notifications')) {
        $pdo->prepare("DELETE FROM notifications WHERE user_id=? AND type='agent_email_github_actions'
            AND source_type='agent_connection_gmail_github'")->execute([$owner]);
    }
    if (agent_connection_gmail_github_actions_voice_ready_v1($pdo)) {
        $pdo->prepare('DELETE FROM agent_connection_gmail_github_voice_v1 WHERE owner_user_id=?')->execute([$owner]);
    }
}

function agent_connection_gmail_github_actions_voice_offer_v1(PDO $pdo, array $user): ?array
{
    if (!function_exists('chat_settings_agent_voice_enabled_v237') || !chat_settings_agent_voice_enabled_v237($pdo,$user)
        || !agent_connection_gmail_github_actions_voice_ready_v1($pdo)) return null;
    $row = agent_connection_gmail_github_actions_voice_row_v1($pdo,(int)$user['id']);
    if (!$row || ((int)$row['succeeded'] < 1 && (int)$row['failed'] < 1)
        || $row['pending_since'] <= gmdate('Y-m-d H:i:s',time()-86400)
        || (int)$row['generation'] !== agent_connection_gmail_github_actions_notice_current_v1($pdo,$user)) return null;
    return ['generation'=>(int)$row['generation'],'through_id'=>(int)$row['through_id']];
}

function agent_connection_gmail_github_actions_voice_claim_v1(PDO $pdo, array $user, string $namespace, int $generation, int $through): ?array
{
    if (!function_exists('chat_settings_agent_voice_enabled_v237') || !agent_connection_gmail_github_actions_voice_ready_v1($pdo)) return null;
    $owner = (int)$user['id'];
    return agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$user,$namespace,$generation,$through,$owner): ?array {
        $lock = $pdo->prepare('SELECT is_active FROM users WHERE id=?'.agent_commerce_lock_pa2($pdo));
        $lock->execute([$owner]);
        if ((int)$lock->fetchColumn() !== 1 || !chat_settings_agent_voice_enabled_v237($pdo,$user)
            || $generation !== agent_connection_gmail_github_actions_notice_current_v1($pdo,$user,true)) return null;
        $row = agent_connection_gmail_github_actions_voice_row_v1($pdo,$owner,true);
        if (!$row || (int)$row['generation'] !== $generation || (int)$row['through_id'] !== $through
            || ((int)$row['succeeded'] < 1 && (int)$row['failed'] < 1)
            || $row['pending_since'] <= gmdate('Y-m-d H:i:s',time()-86400)) return null;
        $message = agent_connection_gmail_github_actions_summary_v1((int)$row['succeeded'],(int)$row['failed']);
        $key = 'gmail-github-actions:'.$through;
        $context = vp3_cognitive_presentation_context_v500($pdo,$user,['agent_voice_enabled'=>true,'voice_candidate_allowed'=>true]);
        $decision = vp3_cognitive_attention_arbitrate_v2410($pdo,$user,$namespace,[
            'key'=>$key,'source'=>'agent_connection_gmail_github','category'=>'status',
            'title'=>'GitHub Actions update','voice_safe_summary'=>$message,'presentation_recommendation'=>'voice_announce',
            'priority'=>(int)$row['failed'] > 0 ? 85 : 80,
        ],$context);
        if (empty($decision['voice'])) return null;
        // The server authorizes at most one attempt, even if playback or its HTTP response is interrupted.
        $pdo->prepare('UPDATE agent_connection_gmail_github_voice_v1 SET succeeded=0,failed=0 WHERE owner_user_id=?')->execute([$owner]);
        vp3_cognitive_attention_mark_delivered_v2410($pdo,$user,$namespace,$key);
        return ['generation'=>$generation,'through_id'=>$through,'message'=>$message];
    });
}
