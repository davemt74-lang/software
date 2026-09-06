<?php
declare(strict_types=1);

const STONEFELLOW_CHAT_SETTINGS_V237 = 'chat-settings-v237-20260905';

function chat_settings_defaults_v237(): array
{
    return [
        'presence_mode' => 'online',
        'social_chat_enabled' => true,
        'sound_enabled' => true,
        'agent_voice_enabled' => true,
    ];
}

function chat_settings_schema_ready_v237(?PDO $pdo = null): bool
{
    $pdo ??= db();
    return (bool)$pdo
        && table_exists('team_user_presence')
        && column_exists('team_user_presence', 'presence_mode')
        && column_exists('team_user_presence', 'social_chat_enabled')
        && column_exists('team_user_presence', 'sound_enabled');
}

function chat_settings_agent_voice_schema_ready_v237(?PDO $pdo = null): bool
{
    $pdo ??= db();
    return (bool)$pdo
        && table_exists('team_user_presence')
        && column_exists('team_user_presence', 'agent_voice_enabled');
}

function chat_settings_ensure_schema_v237(?PDO $pdo = null): void
{
    $pdo ??= db();
    if (!$pdo) {
        throw new RuntimeException('Database connection is unavailable.');
    }
    if (!table_exists('team_user_presence')) {
        throw new RuntimeException('Team Chat storage is unavailable. Run the Stonefellow database upgrade first.');
    }

    if (!column_exists('team_user_presence', 'presence_mode')) {
        $pdo->exec("ALTER TABLE team_user_presence ADD COLUMN presence_mode VARCHAR(16) NOT NULL DEFAULT 'online' AFTER context_label");
    }
    if (!column_exists('team_user_presence', 'social_chat_enabled')) {
        $pdo->exec("ALTER TABLE team_user_presence ADD COLUMN social_chat_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER presence_mode");
    }
    if (!column_exists('team_user_presence', 'sound_enabled')) {
        $pdo->exec("ALTER TABLE team_user_presence ADD COLUMN sound_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER social_chat_enabled");
    }
    if (!column_exists('team_user_presence', 'agent_voice_enabled')) {
        $pdo->exec("ALTER TABLE team_user_presence ADD COLUMN agent_voice_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER sound_enabled");
    }
}

function chat_settings_get_v237(PDO $pdo, int $userId): array
{
    $defaults = chat_settings_defaults_v237();
    if ($userId < 1 || !chat_settings_schema_ready_v237($pdo)) {
        return $defaults;
    }

    $voiceReady = chat_settings_agent_voice_schema_ready_v237($pdo);
    $columns = 'presence_mode,social_chat_enabled,sound_enabled'
        . ($voiceReady ? ',agent_voice_enabled' : '');
    $stmt = $pdo->prepare(
        'SELECT ' . $columns . '
         FROM team_user_presence
         WHERE user_id=?
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return $defaults;
    }

    $mode = strtolower(trim((string)($row['presence_mode'] ?? 'online')));
    if (!in_array($mode, ['online', 'offline'], true)) {
        $mode = 'online';
    }

    return [
        'presence_mode' => $mode,
        'social_chat_enabled' => (bool)($row['social_chat_enabled'] ?? true),
        'sound_enabled' => (bool)($row['sound_enabled'] ?? true),
        'agent_voice_enabled' => $voiceReady
            ? (bool)($row['agent_voice_enabled'] ?? true)
            : true,
    ];
}

function chat_settings_save_v237(PDO $pdo, array $user, array $input): array
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) {
        throw new RuntimeException('Sign in to change Chat settings.');
    }

    if (!chat_settings_schema_ready_v237($pdo) || !chat_settings_agent_voice_schema_ready_v237($pdo)) {
        chat_settings_ensure_schema_v237($pdo);
    }

    $current = chat_settings_get_v237($pdo, $userId);
    $mode = strtolower(trim((string)($input['presence_mode'] ?? $current['presence_mode'] ?? 'online')));
    if (!in_array($mode, ['online', 'offline'], true)) {
        throw new RuntimeException('Choose Online or Offline.');
    }

    $socialEnabled = array_key_exists('social_chat_enabled', $input)
        ? (!empty($input['social_chat_enabled']) ? 1 : 0)
        : (!empty($current['social_chat_enabled']) ? 1 : 0);
    $soundEnabled = array_key_exists('sound_enabled', $input)
        ? (!empty($input['sound_enabled']) ? 1 : 0)
        : (!empty($current['sound_enabled']) ? 1 : 0);
    $agentVoiceEnabled = array_key_exists('agent_voice_enabled', $input)
        ? (!empty($input['agent_voice_enabled']) ? 1 : 0)
        : (!empty($current['agent_voice_enabled']) ? 1 : 0);

    $stmt = $pdo->prepare(
        'INSERT INTO team_user_presence
            (user_id,presence_mode,social_chat_enabled,sound_enabled,agent_voice_enabled,last_seen_at)
         VALUES (?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE
            presence_mode=VALUES(presence_mode),
            social_chat_enabled=VALUES(social_chat_enabled),
            sound_enabled=VALUES(sound_enabled),
            agent_voice_enabled=VALUES(agent_voice_enabled),
            updated_at=NOW()'
    );
    $stmt->execute([$userId, $mode, $socialEnabled, $soundEnabled, $agentVoiceEnabled]);

    return chat_settings_get_v237($pdo, $userId);
}

function chat_settings_save_agent_voice_v237(PDO $pdo, array $user, bool $enabled): array
{
    $current = chat_settings_get_v237($pdo, (int)($user['id'] ?? 0));
    return chat_settings_save_v237($pdo, $user, [
        'presence_mode' => (string)($current['presence_mode'] ?? 'online'),
        'social_chat_enabled' => !empty($current['social_chat_enabled']),
        'sound_enabled' => !empty($current['sound_enabled']),
        'agent_voice_enabled' => $enabled,
    ]);
}
