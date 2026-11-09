<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-notice-v1.php';
require_once __DIR__.'/agent-commerce-recovery-pa2.php';

const VP3_YOUTUBE_CONSENT_POLICY_V1 = '2026-11-08';

function vp3_youtube_artist_research_enabled_v1(PDO $pdo, int $ownerId): bool
{
    if ($ownerId < 1) return false;
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
        $stmt->execute(['youtube_artist_research_owner_'.$ownerId]);
        return $stmt->fetchColumn() === '1';
    } catch (PDOException $error) {
        return false;
    }
}

function vp3_youtube_artist_research_save_v1(PDO $pdo, int $ownerId, bool $enabled): void
{
    if ($ownerId < 1) throw new InvalidArgumentException('A VP3 account is required.');
    agent_commerce_atomic_pa2($pdo, static function () use ($pdo, $ownerId, $enabled): void {
        $owner = $pdo->prepare('SELECT id FROM users WHERE id=?'.agent_commerce_lock_pa2($pdo));
        $owner->execute([$ownerId]);
        if (!$owner->fetchColumn() || !agent_connection_youtube_consent_v1($pdo,$ownerId)) {
            throw new DomainException('Enable the YouTube skill first.');
        }
        $key = 'youtube_artist_research_owner_'.$ownerId;
        if (!$enabled) {
            $pdo->prepare('DELETE FROM settings WHERE setting_key=?')->execute([$key]);
            return;
        }
        $upsert = agent_commerce_sqlite_pa2($pdo)
            ? 'ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value'
            : 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)';
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,'1') {$upsert}")->execute([$key]);
    });
}

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
function agent_connection_youtube_set_consent_v1(PDO $pdo, int $ownerId, bool $enabled, bool $recordPlayback = false): void
{
    if ($ownerId < 1) throw new InvalidArgumentException('A VP3 account is required.');
    agent_commerce_atomic_pa2($pdo, static function () use ($pdo, $ownerId, $enabled, $recordPlayback): void {
        $owner = $pdo->prepare('SELECT id FROM users WHERE id=?'.agent_commerce_lock_pa2($pdo));
        $owner->execute([$ownerId]);
        if (!$owner->fetchColumn()) throw new InvalidArgumentException('A VP3 account is required.');
        if (!$enabled) {
            $pdo->prepare('DELETE FROM agent_connection_youtube_consent_v1 WHERE owner_user_id=?')->execute([$ownerId]);
            $pdo->prepare('DELETE FROM settings WHERE setting_key=?')->execute(['youtube_artist_research_owner_'.$ownerId]);
            return;
        }
        if (agent_connection_youtube_consent_v1($pdo, $ownerId)) return;

        $update = agent_commerce_sqlite_pa2($pdo)
            ? ' ON CONFLICT(owner_user_id) DO UPDATE SET policy_version=excluded.policy_version, accepted_at=excluded.accepted_at'
            : ' ON DUPLICATE KEY UPDATE policy_version=VALUES(policy_version), accepted_at=VALUES(accepted_at)';
        $stmt = $pdo->prepare('INSERT INTO agent_connection_youtube_consent_v1 (owner_user_id,policy_version,accepted_at) VALUES (?,?,?)'.$update);
        $stmt->execute([$ownerId, VP3_YOUTUBE_CONSENT_POLICY_V1, time()]);
        if ($recordPlayback) {
            require_once __DIR__.'/agent-connection-youtube-playback-v1.php';
            if (!vp3_youtube_playback_ready_v1($pdo)) throw new DomainException('Playback history unavailable.');
            vp3_youtube_history_change_v1($pdo,$ownerId,'enable_history');
        }
        agent_connection_notice_record_v1($ownerId, 'youtube');
    });
}
