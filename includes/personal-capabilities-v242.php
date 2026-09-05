<?php
declare(strict_types=1);

const STONEFELLOW_PERSONAL_CAPABILITIES_V242 = 'personal-capabilities-v242-20260905';
const STONEFELLOW_PERSONAL_CAPABILITIES_SEED = 'personal_capabilities_seed_v242';

/**
 * Personal account capabilities are intentionally separate from system/shared
 * content permissions. A role controls whether the feature is available; the
 * signed-in user id controls which private records the feature may touch.
 */
function personal_capability_catalog_v242(): array
{
    return [
        'agent_brain.access' => [
            'label' => 'Personal Agent Brain',
            'description' => 'Use the signed-in member\'s private Agent Brain memory and history.',
            'category' => 'Personal AI',
            'sort_order' => 17,
        ],
        'personal_knowledge.access' => [
            'label' => 'Personal Knowledge',
            'description' => 'Allow the signed-in member and their private agent to retrieve their own Knowledge Base.',
            'category' => 'Personal AI',
            'sort_order' => 18,
        ],
        'personal_knowledge.manage' => [
            'label' => 'Manage Personal Knowledge',
            'description' => 'Create, edit, upload and delete only the signed-in member\'s personal knowledge.',
            'category' => 'Personal AI',
            'sort_order' => 19,
        ],
        'profile_agent.access' => [
            'label' => 'Profile Agent',
            'description' => 'Manage the signed-in member\'s profile, Profile Agent settings and visitor inbox.',
            'category' => 'Personal AI',
            'sort_order' => 20,
        ],
        'profile_chat.access' => [
            'label' => 'Profile Agent Chat',
            'description' => 'Allow visitors to start conversations with the signed-in member\'s enabled Profile Agent.',
            'category' => 'Personal AI',
            'sort_order' => 21,
        ],
        'voice_profile.access' => [
            'label' => 'Voice Profile',
            'description' => 'Manage the signed-in member\'s private voice identity and voice clone.',
            'category' => 'Personal AI',
            'sort_order' => 22,
        ],
    ];
}

function personal_capability_default_roles_v242(): array
{
    $all = array_keys(user_roles());
    return [
        'agent_brain.access' => $all,
        'personal_knowledge.access' => $all,
        'personal_knowledge.manage' => $all,
        'profile_agent.access' => $all,
        'profile_chat.access' => $all,
        'voice_profile.access' => $all,
    ];
}

function personal_capability_seeded_v242(): bool
{
    return (string)setting(STONEFELLOW_PERSONAL_CAPABILITIES_SEED, '') === '1';
}

function personal_capability_has_v242(string $permission, ?array $user = null): bool
{
    $user ??= current_user();
    $catalog = personal_capability_catalog_v242();
    if (!$user || !isset($catalog[$permission])) {
        return false;
    }
    if (user_has_role('admin', $user)) {
        return true;
    }

    $roles = user_roles_for_user($user);
    if (!$roles) {
        return false;
    }

    $pdo = db();
    if ($pdo && permissions_schema_ready() && personal_capability_seeded_v242()) {
        try {
            $marks = implode(',', array_fill(0, count($roles), '?'));
            $stmt = $pdo->prepare(
                "SELECT 1 FROM role_permissions
                 WHERE permission_key=? AND role IN ($marks)
                 LIMIT 1"
            );
            $stmt->execute([$permission, ...$roles]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            // Fall through to safe rollout defaults during a partial upgrade.
        }
    }

    $defaults = personal_capability_default_roles_v242();
    foreach ($roles as $role) {
        if (in_array($role, $defaults[$permission] ?? [], true)) {
            return true;
        }
    }
    return false;
}

function personal_capability_admin_catalog_v242(array $base): array
{
    // Clarify the two global/shared legacy permissions without changing their
    // stable database keys.
    if (isset($base['knowledge.access'])) {
        $base['knowledge.access']['label'] = 'Shared / System Knowledge Access';
        $base['knowledge.access']['description'] = 'Allow Agent Chat to retrieve published system or explicitly shared knowledge available to this account type.';
    }
    if (isset($base['knowledge.manage'])) {
        $base['knowledge.manage']['label'] = 'Manage Shared / System Knowledge';
        $base['knowledge.manage']['description'] = 'Manage the system/shared Knowledge Base. Personal member knowledge is managed separately.';
    }
    if (isset($base['artist_listening.access'])) {
        $base['artist_listening.access']['label'] = 'Transcriptions / My Recordings';
        $base['artist_listening.access']['description'] = 'Open the signed-in member\'s private recordings, transcripts and transcription workspace.';
    }
    return $base + personal_capability_catalog_v242();
}

function personal_capability_seed_v242(): void
{
    static $attempted = false;
    if ($attempted) return;
    $attempted = true;

    $pdo = db();
    if (!$pdo || !permissions_schema_ready()) return;

    $catalog = personal_capability_catalog_v242();
    $defaults = personal_capability_default_roles_v242();
    $alreadySeeded = personal_capability_seeded_v242();

    $pdo->beginTransaction();
    try {
        $upsert = $pdo->prepare(
            'INSERT INTO permissions (permission_key,label,description,category,sort_order)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               label=VALUES(label),description=VALUES(description),category=VALUES(category),sort_order=VALUES(sort_order)'
        );
        foreach ($catalog as $key => $permission) {
            $upsert->execute([$key, $permission['label'], $permission['description'], $permission['category'], $permission['sort_order']]);
        }

        // Seed defaults only once. After the marker exists, Admin > Permissions
        // is authoritative and an administrator may remove any role assignment.
        if (!$alreadySeeded) {
            $insert = $pdo->prepare('INSERT IGNORE INTO role_permissions (role,permission_key) VALUES (?,?)');
            foreach ($defaults as $permission => $roles) {
                foreach ($roles as $role) {
                    $insert->execute([$role, $permission]);
                }
            }
        }
        $pdo->commit();
        if (!$alreadySeeded) {
            save_setting(STONEFELLOW_PERSONAL_CAPABILITIES_SEED, '1');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function personal_capability_schema_ready_v242(?PDO $pdo = null): bool
{
    $pdo ??= db();
    return (bool)$pdo
        && table_exists('knowledge_items')
        && column_exists('knowledge_items', 'knowledge_scope')
        && table_exists('user_profiles')
        && column_exists('user_profiles', 'tagline')
        && column_exists('user_profiles', 'contact_email')
        && column_exists('user_profiles', 'tidal_url')
        && column_exists('user_profiles', 'facebook_url');
}

function personal_capability_ensure_schema_v242(?PDO $pdo = null): void
{
    $pdo ??= db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');

    if (table_exists('knowledge_items') && !column_exists('knowledge_items', 'knowledge_scope')) {
        // Existing rows predate personal Knowledge UI and therefore belong to
        // the legacy system/shared library. New personal records opt in below.
        $pdo->exec("ALTER TABLE knowledge_items ADD COLUMN knowledge_scope VARCHAR(20) NOT NULL DEFAULT 'system' AFTER created_by_user_id");
        try { $pdo->exec('ALTER TABLE knowledge_items ADD INDEX idx_kb_scope_owner (knowledge_scope,created_by_user_id,updated_at)'); } catch (Throwable $e) {}
    }
    if (table_exists('knowledge_items') && column_exists('knowledge_items', 'knowledge_scope')) {
        $pdo->exec("UPDATE knowledge_items SET knowledge_scope='personal' WHERE file_type='personal_note' AND created_by_user_id IS NOT NULL");
    }

    if (table_exists('user_profiles')) {
        $columns = [
            'tagline' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'bio_subhead' => "VARCHAR(500) NOT NULL DEFAULT ''",
            'genre' => "VARCHAR(190) NOT NULL DEFAULT ''",
            'focus' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'contact_email' => "VARCHAR(190) NOT NULL DEFAULT ''",
            'player_description' => "VARCHAR(500) NOT NULL DEFAULT ''",
            'artist_bio' => 'TEXT NULL',
            'tidal_url' => "VARCHAR(500) NOT NULL DEFAULT ''",
            'facebook_url' => "VARCHAR(500) NOT NULL DEFAULT ''",
        ];
        foreach ($columns as $name => $definition) {
            if (!column_exists('user_profiles', $name)) {
                $pdo->exec("ALTER TABLE user_profiles ADD COLUMN {$name} {$definition}");
            }
        }
    }
}

function personal_profile_save_v242(PDO $pdo, array $user, array $input): array
{
    $profile = profile_save($pdo, $user, $input);
    if (!personal_capability_schema_ready_v242($pdo)) {
        personal_capability_ensure_schema_v242($pdo);
    }
    $uid = (int)($user['id'] ?? 0);
    if ($uid < 1) throw new RuntimeException('Sign in to edit your profile.');

    $text = static fn(string $key, int $max): string => mb_strimwidth(trim((string)($input[$key] ?? '')), 0, $max, '…');
    $contact = trim((string)($input['contact_email'] ?? ''));
    if ($contact !== '' && !filter_var($contact, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid profile contact email.');
    }
    $tidal = profile_safe_external_url((string)($input['tidal_url'] ?? ''));
    $facebook = profile_safe_external_url((string)($input['facebook_url'] ?? ''));

    $stmt = $pdo->prepare(
        'UPDATE user_profiles
         SET tagline=?,bio_subhead=?,genre=?,focus=?,contact_email=?,player_description=?,artist_bio=?,tidal_url=?,facebook_url=?
         WHERE user_id=?'
    );
    $stmt->execute([
        $text('tagline', 255),
        $text('bio_subhead', 500),
        $text('genre', 190),
        $text('focus', 255),
        mb_strimwidth($contact, 0, 190, ''),
        $text('player_description', 500),
        $text('artist_bio', 12000),
        $tidal,
        $facebook,
        $uid,
    ]);
    return profile_for_user($pdo, $uid, false) ?: $profile;
}

function personal_profile_migrate_legacy_artist_v242(PDO $pdo, array $user): void
{
    if (!user_has_role('artist', $user) || !personal_capability_schema_ready_v242($pdo)) return;
    $uid = (int)($user['id'] ?? 0);
    if ($uid < 1) return;
    $profile = profile_for_user($pdo, $uid, true) ?: [];
    $hasPersonal = trim((string)($profile['tagline'] ?? '')) !== ''
        || trim((string)($profile['artist_bio'] ?? '')) !== ''
        || trim((string)($profile['genre'] ?? '')) !== '';
    if ($hasPersonal) return;

    $defaults = default_links();
    $pdo->prepare(
        'UPDATE user_profiles SET tagline=?,bio_subhead=?,genre=?,focus=?,contact_email=?,player_description=?,artist_bio=?,spotify_url=CASE WHEN spotify_url="" THEN ? ELSE spotify_url END,apple_music_url=CASE WHEN apple_music_url="" THEN ? ELSE apple_music_url END,tidal_url=?,youtube_url=CASE WHEN youtube_url="" THEN ? ELSE youtube_url END,instagram_url=CASE WHEN instagram_url="" THEN ? ELSE instagram_url END,facebook_url=? WHERE user_id=?'
    )->execute([
        (string)setting('tagline', ''),
        (string)setting('bio_subhead', ''),
        (string)setting('genre', ''),
        (string)setting('focus', ''),
        (string)setting('contact_email', ''),
        (string)setting('player_description', ''),
        (string)setting('artist_bio', ''),
        (string)setting('link_spotify', $defaults['spotify'] ?? ''),
        (string)setting('link_apple_music', $defaults['apple_music'] ?? ''),
        (string)setting('link_tidal', $defaults['tidal'] ?? ''),
        (string)setting('link_youtube', $defaults['youtube'] ?? ''),
        (string)setting('link_instagram', $defaults['instagram'] ?? ''),
        (string)setting('link_facebook', $defaults['facebook'] ?? ''),
        $uid,
    ]);
}
