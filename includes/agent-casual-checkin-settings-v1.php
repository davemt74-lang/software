<?php
declare(strict_types=1);

/** A missing preference enables only the idle Media invitation. */
function vp3_casual_checkin_preference_v1(PDO $pdo, int $owner): ?bool
{
    if ($owner < 1) return false;
    $read = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $read->execute(['casual_checkin_owner_'.$owner]);
    $setting=$read->fetchColumn();
    return $setting===false?null:(string)$setting==='1';
}

function vp3_casual_checkin_save_v1(PDO $pdo, int $owner, ?bool $enabled): void
{
    if ($owner < 1) throw new InvalidArgumentException('Check-in settings require an owner.');
    require_once __DIR__.'/agent-commerce-recovery-pa2.php';
    agent_commerce_atomic_pa2($pdo, static function () use ($pdo, $owner, $enabled): void {
        $active = $pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.agent_commerce_lock_pa2($pdo));
        $active->execute([$owner]);
        if ($active->fetchColumn() === false) throw new RuntimeException('Check-in settings access changed.', 403);
        $key = 'casual_checkin_owner_'.$owner;
        $pdo->prepare('DELETE FROM settings WHERE setting_key=?')->execute([$key]);
        if ($enabled!==null) $pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?)')->execute([$key, $enabled?'1':'0']);
    });
}
