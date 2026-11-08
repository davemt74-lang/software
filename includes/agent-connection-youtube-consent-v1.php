<?php
declare(strict_types=1);

const VP3_YOUTUBE_CONSENT_POLICY_V1 = '2026-11-08';

function agent_connection_youtube_consent_schema_v1(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $pdo->exec('CREATE TABLE IF NOT EXISTS agent_connection_youtube_consent_v1 (
        owner_user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        policy_version VARCHAR(20) NOT NULL,
        accepted_at BIGINT UNSIGNED NOT NULL,
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )'.($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
}

function agent_connection_youtube_consent_ready_v1(PDO $pdo): bool
{
    try {
        return $pdo->query('SELECT owner_user_id,policy_version,accepted_at FROM agent_connection_youtube_consent_v1 WHERE 1=0') !== false;
    } catch (PDOException $e) {
        return false;
    }
}

function agent_connection_youtube_consent_v1(PDO $pdo, int $ownerId): bool
{
    if ($ownerId < 1) return false;
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM agent_connection_youtube_consent_v1 WHERE owner_user_id=? AND policy_version=?');
        return $stmt && $stmt->execute([$ownerId, VP3_YOUTUBE_CONSENT_POLICY_V1]) && $stmt->fetchColumn() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

/** Only the authenticated member action may enable the skill for their account. */
function agent_connection_youtube_set_consent_v1(PDO $pdo, int $ownerId, bool $enabled): void
{
    if ($ownerId < 1) throw new InvalidArgumentException('A VP3 account is required.');
    if (!$enabled) {
        $stmt = $pdo->prepare('DELETE FROM agent_connection_youtube_consent_v1 WHERE owner_user_id=?');
        $stmt->execute([$ownerId]);
        return;
    }

    $update = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? ' ON CONFLICT(owner_user_id) DO UPDATE SET policy_version=excluded.policy_version, accepted_at=excluded.accepted_at'
        : ' ON DUPLICATE KEY UPDATE policy_version=VALUES(policy_version), accepted_at=VALUES(accepted_at)';
    $stmt = $pdo->prepare('INSERT INTO agent_connection_youtube_consent_v1 (owner_user_id,policy_version,accepted_at) VALUES (?,?,?)'.$update);
    $stmt->execute([$ownerId, VP3_YOUTUBE_CONSENT_POLICY_V1, time()]);
}
