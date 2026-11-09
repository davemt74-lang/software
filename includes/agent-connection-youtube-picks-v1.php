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

function vp3_youtube_suggestion_topic_v1(string $message): ?string
{
    $message = trim($message);
    $message = preg_replace('/\A(?:please[,\s]+|(?:can|could|would) you\s+)+/iu', '', $message) ?? $message;
    if (preg_match('/\A\/youtube\s+(?:picks|suggest)(?:\s+(.*))?\z/isu', $message, $m)) return trim($m[1] ?? '');
    if (!preg_match('/\A(?:suggest|recommend)(?:\s+me)?\s+(.+?)\s+(?:on|from)\s+you\s*tube[.!?]*\z/isu', $message, $m)) return null;
    if (!preg_match('/\b(?:songs?|music|tracks?)\b/iu', $m[1])) return null;
    return trim(preg_replace('/\b(?:some|a few|three|3|a|me|songs?|music|tracks?)\b/iu', ' ', $m[1]) ?? $m[1]);
}

function vp3_youtube_reserve_offer_v1(PDO $pdo, int $owner, int $now): bool
{
    $stmt = $pdo->prepare('UPDATE vp3_youtube_picks_v1 SET next_offer=?
        WHERE owner_user_id=? AND proactive=1 AND next_offer<=?');
    $stmt->execute([$now+4*3600,$owner,$now]);
    return $stmt->rowCount() === 1;
}
