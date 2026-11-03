<?php
declare(strict_types=1);

const STONEFELLOW_CHAT_SETTINGS_V237 = 'chat-settings-v237-pa3c-assisted-phone';

function chat_settings_agent_voice_allowed_v237(?array $user=null): bool
{
    $user??=current_user();
    if(!$user||(int)($user['id']??0)<1)return false;
    if(function_exists('user_has_role')&&user_has_role('admin',$user))return true;
    if(function_exists('subscription_schema_ready')&&subscription_schema_ready()){
        $sub=function_exists('subscription_current')?subscription_current($user):null;
        if(!$sub)return false;
        if(function_exists('subscription_has_entitlement')&&subscription_has_entitlement($user,'legacy.permissions')){
            return function_exists('personal_capability_has_v242')
                ? personal_capability_has_v242('voice_profile.access',$user)
                : true;
        }
        return function_exists('subscription_has_entitlement')
            && subscription_has_entitlement($user,'voice.access');
    }
    return function_exists('personal_capability_has_v242')
        ? personal_capability_has_v242('voice_profile.access',$user)
        : true;
}

function chat_settings_agent_voice_enabled_v237(PDO $pdo,array $user): bool
{
    if(!chat_settings_agent_voice_allowed_v237($user))return false;
    $settings=chat_settings_get_v237($pdo,(int)($user['id']??0));
    return !empty($settings['agent_voice_enabled']);
}

function chat_settings_defaults_v237(): array
{
    return [
        'presence_mode' => 'online',
        'social_chat_enabled' => true,
        'sound_enabled' => true,
        'agent_voice_enabled' => true,
        'agent_assisted_phone_chat_enabled' => false,
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

function chat_settings_agent_assisted_phone_chat_schema_ready_v237(?PDO $pdo = null): bool
{
    $pdo ??= db();
    if (!$pdo) return false;
    require_once __DIR__.'/schema-metadata.php';
    return schema_metadata_column_exists($pdo, 'team_user_presence', 'agent_assisted_phone_chat_enabled');
}

/** Read only the stored opt-in. Call authority and disclosure are checked separately. */
function chat_settings_agent_assisted_phone_chat_enabled_v237(PDO $pdo, int $userId, bool $currentRead = false): bool
{
    if ($userId < 1) return false;
    $inTransaction = $currentRead && $pdo->inTransaction();
    try {
        $lock = $inTransaction && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare('SELECT agent_assisted_phone_chat_enabled FROM team_user_presence WHERE user_id=? LIMIT 1'.$lock);
        $stmt->execute([$userId]);
        return in_array($stmt->fetchColumn(), [1, '1', true], true);
    } catch (PDOException $error) {
        if ($inTransaction && !$pdo->inTransaction()) throw $error;
        return false;
    }
}

function chat_settings_agent_assisted_phone_chat_allowed_v237(PDO $pdo, array $user): bool
{
    try {
        if (!has_permission('account.access', $user) || !has_permission('chat.access', $user)) return false;
        require_once __DIR__.'/agent-observation-authority-v1.php';
        return vp3_observation_personal_permission_v1($pdo, $user, 'profile_agent.access')
            && vp3_observation_personal_permission_v1($pdo, $user, 'profile_chat.access');
    } catch (Throwable $error) {
        return false;
    }
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
    if (!chat_settings_agent_assisted_phone_chat_schema_ready_v237($pdo)) {
        $pdo->exec('ALTER TABLE team_user_presence ADD COLUMN agent_assisted_phone_chat_enabled TINYINT(1) NOT NULL DEFAULT 0');
    }
}

function chat_settings_get_v237(PDO $pdo, int $userId): array
{
    $defaults = chat_settings_defaults_v237();
    if ($userId < 1 || !chat_settings_schema_ready_v237($pdo)) {
        return $defaults;
    }

    $voiceReady = chat_settings_agent_voice_schema_ready_v237($pdo);
    $assistedPhoneReady = chat_settings_agent_assisted_phone_chat_schema_ready_v237($pdo);
    $columns = 'presence_mode,social_chat_enabled,sound_enabled'
        . ($voiceReady ? ',agent_voice_enabled' : '')
        . ($assistedPhoneReady ? ',agent_assisted_phone_chat_enabled' : '');
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
        'agent_assisted_phone_chat_enabled' => in_array($row['agent_assisted_phone_chat_enabled'] ?? null, [1, '1', true], true),
    ];
}

function chat_settings_save_v237(PDO $pdo, array $user, array $input): array
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) {
        throw new RuntimeException('Sign in to change Chat settings.');
    }

    $assistedPhoneEnabled = null;
    if (array_key_exists('agent_assisted_phone_chat_enabled', $input)) {
        $requested = $input['agent_assisted_phone_chat_enabled'];
        if (!in_array($requested, [true, false, 1, 0, '1', '0'], true)) {
            throw new RuntimeException('Choose On or Off for Agent-assisted phone chat.');
        }
        $assistedPhoneEnabled = (int)(bool)$requested;
        if ($assistedPhoneEnabled && !chat_settings_agent_assisted_phone_chat_allowed_v237($pdo, $user)) {
            throw new RuntimeException('Agent-assisted phone chat is not available for this account.');
        }
        if (!chat_settings_agent_assisted_phone_chat_schema_ready_v237($pdo)) {
            throw new RuntimeException('Agent-assisted phone chat is not ready. Run /upgrade.php.');
        }
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
    $voiceAllowed=chat_settings_agent_voice_allowed_v237($user);
    if(array_key_exists('agent_voice_enabled',$input)&&!empty($input['agent_voice_enabled'])&&!$voiceAllowed){
        throw new RuntimeException('Agent Voice is not included for this account.');
    }
    $agentVoiceEnabled = $voiceAllowed
        ? (array_key_exists('agent_voice_enabled', $input)
            ? (!empty($input['agent_voice_enabled']) ? 1 : 0)
            : (!empty($current['agent_voice_enabled']) ? 1 : 0))
        : 0;

    // Old clients and the account menu's Agent Voice control must not write this opt-in.
    $settings = [
        'presence_mode'=>$mode,
        'social_chat_enabled'=>$socialEnabled,
        'sound_enabled'=>$soundEnabled,
        'agent_voice_enabled'=>$agentVoiceEnabled,
    ];
    if ($assistedPhoneEnabled !== null) $settings['agent_assisted_phone_chat_enabled'] = $assistedPhoneEnabled;
    $names = array_keys($settings);
    $marks = implode(',', array_fill(0, count($settings), '?'));
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $upsert = $sqlite ? 'ON CONFLICT(user_id) DO UPDATE SET' : 'ON DUPLICATE KEY UPDATE';
    $updates = implode(',', array_map(static fn(string $name): string => $name.'='.($sqlite ? 'excluded.'.$name : 'VALUES('.$name.')'), $names));
    $stmt = $pdo->prepare('INSERT INTO team_user_presence (user_id,'.implode(',', $names).',last_seen_at) '
        .'VALUES (?,'.$marks.',CURRENT_TIMESTAMP) '.$upsert.' '.$updates.',updated_at=CURRENT_TIMESTAMP');
    $stmt->execute([$userId, ...array_values($settings)]);

    return chat_settings_get_v237($pdo, $userId);
}

function chat_settings_save_agent_voice_v237(PDO $pdo, array $user, bool $enabled): array
{
    if($enabled&&!chat_settings_agent_voice_allowed_v237($user))throw new RuntimeException('Agent Voice is not included for this account.');
    $current = chat_settings_get_v237($pdo, (int)($user['id'] ?? 0));
    return chat_settings_save_v237($pdo, $user, [
        'presence_mode' => (string)($current['presence_mode'] ?? 'online'),
        'social_chat_enabled' => !empty($current['social_chat_enabled']),
        'sound_enabled' => !empty($current['sound_enabled']),
        'agent_voice_enabled' => $enabled,
    ]);
}
