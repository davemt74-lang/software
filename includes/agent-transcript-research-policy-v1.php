<?php
declare(strict_types=1);

require_once __DIR__.'/agent-commerce-recovery-pa2.php';

/** A grant identifies one uninterrupted period of owner consent. */
function vp3_transcript_research_grant_v1(PDO $pdo, int $owner): string
{
    if ($owner < 1) return '';
    $query = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $query->execute(['transcript_research_owner_'.$owner]);
    $value = $query->fetchColumn();
    return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) ? $value : '';
}

function vp3_transcript_research_save_v1(PDO $pdo, int $owner, bool $enabled): void
{
    if ($owner < 1) throw new InvalidArgumentException('Invalid owner.');
    if (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1() !== null) {
        throw new DomainException('Only the signed-in owner can change transcript research preferences.');
    }

    agent_commerce_atomic_pa2($pdo, static function () use ($pdo, $owner, $enabled): void {
        $account = $pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.agent_commerce_lock_pa2($pdo));
        $account->execute([$owner]);
        if (!$account->fetchColumn()) throw new DomainException('Account access changed.');

        $key = 'transcript_research_owner_'.$owner;
        if (!$enabled) {
            $pdo->prepare('DELETE FROM settings WHERE setting_key=?')->execute([$key]);
            return;
        }
        if (vp3_transcript_research_grant_v1($pdo, $owner) !== '') return;

        $upsert = agent_commerce_sqlite_pa2($pdo)
            ? 'ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value'
            : 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)';
        $pdo->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (?,?) '.$upsert)
            ->execute([$key, bin2hex(random_bytes(32))]);
    });
}
