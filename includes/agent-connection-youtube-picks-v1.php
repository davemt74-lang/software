<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-youtube-consent-v1.php';

function vp3_youtube_picks_install_v1(PDO $pdo): void
{
    $engine = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $pdo->exec('CREATE TABLE IF NOT EXISTS vp3_youtube_picks_v1 (
        owner_user_id INT UNSIGNED PRIMARY KEY, taste VARCHAR(120) NOT NULL DEFAULT \'\',
        proactive INT NOT NULL DEFAULT 0, next_offer BIGINT UNSIGNED NOT NULL DEFAULT 0,
        FOREIGN KEY (owner_user_id) REFERENCES agent_connection_youtube_consent_v1(owner_user_id) ON DELETE CASCADE
    )'.$engine);
}

function vp3_youtube_picks_ready_v1(PDO $pdo): bool
{
    try {
        return $pdo->query('SELECT taste,proactive,next_offer FROM vp3_youtube_picks_v1 WHERE 1=0') !== false;
    } catch (PDOException) {
        return false;
    }
}

function vp3_youtube_picks_settings_v1(PDO $pdo, int $owner): array
{
    $stmt = $pdo->prepare('SELECT taste,proactive,next_offer FROM vp3_youtube_picks_v1 WHERE owner_user_id=?');
    $stmt->execute([$owner]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['taste'=>'','proactive'=>0,'next_offer'=>0];
}

function vp3_youtube_picks_save_v1(PDO $pdo, int $owner, string $taste, ?bool $proactive): void
{
    $taste = trim($taste);
    if (strlen($taste)>480 || !preg_match('/\A[^\p{C}]{0,120}\z/u', $taste)) throw new DomainException('Invalid music taste.');
    $update = agent_commerce_sqlite_pa2($pdo)
        ? ' ON CONFLICT(owner_user_id) DO UPDATE SET taste=excluded.taste'.($proactive === null ? '' : ',proactive=excluded.proactive')
        : ' ON DUPLICATE KEY UPDATE taste=VALUES(taste)'.($proactive === null ? '' : ',proactive=VALUES(proactive)');
    $pdo->prepare('INSERT INTO vp3_youtube_picks_v1 (owner_user_id,taste,proactive) VALUES (?,?,?)'.$update)
        ->execute([$owner,$taste,(int)$proactive]);
}

function vp3_youtube_suggestion_plan_v1(string $message): ?array
{
    $message = preg_replace('/\A(?:please[,\s]+|(?:can|could|would) you\s+)+/iu', '', trim($message)) ?? '';
    $message = rtrim($message, '.!?');
    if (preg_match('/\A\/youtube\s+(picks|suggest|playlist|similar)(?:\s+(.*))?\z/isu', $message, $m)) {
        $kind = strtolower($m[1]);
        return ['kind'=>in_array($kind,['picks','suggest'],true) ? 'music' : $kind, 'topic'=>trim($m[2] ?? '')];
    }
    if (preg_match('/\A(?:make|create|build|draft)(?:\s+me)?\s+(?:a\s+)?you\s*tube\s+playlist(?:\s+(?:of|for|with)\s+(.+))?\z/isu', $message, $m)) {
        return ['kind'=>'playlist','topic'=>trim($m[1] ?? '')];
    }
    if (!preg_match('/\A(.+?)\s+(?:on|from)\s+you\s*tube\z/isu', $message, $m)) return null;
    $request = $m[1];
    if (preg_match('/\A(?:make|create|build|draft)(?:\s+me)?\s+(?:a\s+)?playlist(?:\s+(?:of|for|with)\s+(.+))?\z/isu', $request, $m)) {
        return ['kind'=>'playlist','topic'=>trim($m[1] ?? '')];
    }
    if (preg_match('/\A(?:(?:suggest|recommend|find|show|research)(?:\s+me)?\s+)?(?:some\s+)?artists\s+(?:similar to|like)\s+(.+)\z/isu', $request, $m)) {
        return ['kind'=>'similar','topic'=>trim($m[1])];
    }
    if (!preg_match('/\A(?:suggest|recommend)(?:\s+me)?\s+(.+)\z/isu', $request, $m)
        || !preg_match('/\b(songs?|music|tracks?|artists?)\b/iu', $m[1], $noun)) return null;
    $topic = preg_replace('/\b(?:some|a few|three|3|a|me|songs?|music|tracks?|artists?)\b/iu', ' ', $m[1]) ?? '';
    return ['kind'=>str_starts_with(strtolower($noun[1]),'artist') ? 'artists' : 'music',
        'topic'=>trim(preg_replace('/\s+/u',' ',$topic) ?? '')];
}

function vp3_youtube_reserve_offer_v1(PDO $pdo, int $owner, int $now): bool
{
    $stmt = $pdo->prepare('UPDATE vp3_youtube_picks_v1 SET next_offer=?
        WHERE owner_user_id=? AND proactive=1 AND next_offer<=?');
    $stmt->execute([$now+4*3600,$owner,$now]);
    return $stmt->rowCount() === 1;
}
