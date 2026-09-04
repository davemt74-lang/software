<?php
declare(strict_types=1);

function user_roles(): array
{
    return [
        'fan' => 'Fan',
        'artist' => 'Artist',
        'manager' => 'Manager',
        'producer' => 'Producer',
        'supervisor' => 'Supervisor',
        'investor' => 'Investor',
        'admin' => 'Admin',
    ];
}

function valid_role(string $role): bool
{
    return array_key_exists($role, user_roles());
}

function role_label(string $role): string
{
    return user_roles()[$role] ?? ucfirst($role);
}

function user_account_types_for_user_id(int $userId, string $fallbackRole = ''): array
{
    $roles = [];

    if ($fallbackRole !== '' && valid_role($fallbackRole)) {
        $roles[] = $fallbackRole;
    }

    $pdo = db();
    if (
        $pdo
        && $userId > 0
        && table_exists('user_account_types')
        && column_exists('user_account_types', 'assigned_explicitly_at')
    ) {
        try {
            $stmt = $pdo->prepare(
                'SELECT role
                 FROM user_account_types
                 WHERE user_id=?
                   AND (role=? OR assigned_explicitly_at IS NOT NULL)
                 ORDER BY role ASC'
            );
            $stmt->execute([$userId, $fallbackRole]);
            foreach ($stmt->fetchAll() as $row) {
                $role = (string)($row['role'] ?? '');
                if (valid_role($role)) {
                    $roles[] = $role;
                }
            }
        } catch (Throwable $e) {
            // Fail closed to users.role while an older install is upgraded.
        }
    }

    return array_values(array_unique($roles));
}

function user_roles_for_user(?array $user = null): array
{
    $user ??= current_user();
    if (!$user) {
        return [];
    }

    $primaryRole = (string)($user['role'] ?? '');
    if (!empty($user['id'])) {
        // Persisted identity is authoritative. Never trust a roles array copied
        // into a session, notification payload, or other long-lived structure.
        return user_account_types_for_user_id((int)$user['id'], $primaryRole);
    }

    $roles = valid_role($primaryRole) ? [$primaryRole] : [];
    return array_values(array_unique($roles));
}

function user_has_role(string $role, ?array $user = null): bool
{
    return valid_role($role) && in_array($role, user_roles_for_user($user), true);
}

function user_role_labels(?array $user = null): array
{
    return array_map(
        static fn(string $role): string => role_label($role),
        user_roles_for_user($user)
    );
}

function sync_user_account_types(PDO $pdo, int $userId, array $roles, string $primaryRole): void
{
    if ($userId < 1 || !valid_role($primaryRole)) {
        throw new RuntimeException('A valid primary account type is required.');
    }

    if (!table_exists('user_account_types')) {
        throw new RuntimeException('Multi-role account storage is unavailable. Run the Stonefellow v104 upgrade first.');
    }

    $cleanRoles = [];
    foreach ($roles as $role) {
        $role = trim((string)$role);
        if ($role !== '' && valid_role($role)) {
            $cleanRoles[] = $role;
        }
    }
    $cleanRoles[] = $primaryRole;
    $cleanRoles = array_values(array_unique($cleanRoles));

    if (!$cleanRoles) {
        throw new RuntimeException('Select at least one account type.');
    }

    $delete = $pdo->prepare('DELETE FROM user_account_types WHERE user_id=?');
    $delete->execute([$userId]);

    $supportsExplicitAssignments = column_exists('user_account_types', 'assigned_explicitly_at');
    $insert = $pdo->prepare($supportsExplicitAssignments
        ? 'INSERT INTO user_account_types (user_id,role,assigned_explicitly_at) VALUES (?,?,NOW())'
        : 'INSERT INTO user_account_types (user_id,role) VALUES (?,?)');
    foreach ($cleanRoles as $role) {
        $insert->execute([$userId, $role]);
    }

    $primary = $pdo->prepare('UPDATE users SET role=? WHERE id=?');
    $primary->execute([$primaryRole, $userId]);

    if ((int)($_SESSION['user_id'] ?? 0) === $userId && function_exists('reset_current_user_cache')) {
        reset_current_user_cache();
    }
}

function active_admin_user_count(PDO $pdo, int $excludeUserId = 0): int
{
    if (table_exists('user_account_types')) {
        $sql =
            "SELECT COUNT(DISTINCT u.id)
             FROM users u
             LEFT JOIN user_account_types uat
               ON uat.user_id=u.id AND uat.role='admin'
             WHERE u.is_active=1
               AND (u.role='admin' OR uat.role='admin')";
        $params = [];
        if ($excludeUserId > 0) {
            $sql .= ' AND u.id<>?';
            $params[] = $excludeUserId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    $sql = "SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1";
    $params = [];
    if ($excludeUserId > 0) {
        $sql .= ' AND id<>?';
        $params[] = $excludeUserId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function team_chat_role_allowed(?array $user = null): bool
{
    $user = $user ?? current_user();

    if (!$user) {
        return false;
    }

    return (bool)array_intersect(
        user_roles_for_user($user),
        ['artist', 'manager', 'producer', 'supervisor']
    );
}

function permission_catalog(): array
{
    return [
        'account.access' => [
            'label' => 'Account Dashboard',
            'description' => 'Access the signed-in user account dashboard.',
            'category' => 'Account',
            'sort_order' => 10,
        ],
        'chat.access' => [
            'label' => 'Stonefellow Chat',
            'description' => 'Use the conversational interface and role-scoped database search.',
            'category' => 'Account',
            'sort_order' => 15,
        ],
        'artist_listening.access' => [
            'label' => 'Artist Listening / My Recordings',
            'description' => 'Open private transcription sessions and the My Recordings workspace from Agent Chat.',
            'category' => 'Account',
            'sort_order' => 16,
        ],
        'knowledge.access' => [
            'label' => 'Knowledge Base Access',
            'description' => 'Allow chat to retrieve knowledge-base content available to the user.',
            'category' => 'Account',
            'sort_order' => 18,
        ],
        'knowledge.manage' => [
            'label' => 'Manage Knowledge Base',
            'description' => 'Upload, edit, publish and delete knowledge-base files and notes.',
            'category' => 'Content',
            'sort_order' => 75,
        ],
        'investor.access' => [
            'label' => 'Investor Area',
            'description' => 'Access private investor content.',
            'category' => 'Account',
            'sort_order' => 20,
        ],
        'admin.access' => [
            'label' => 'Admin Dashboard',
            'description' => 'Enter the Stonefellow administration area.',
            'category' => 'Administration',
            'sort_order' => 30,
        ],
        'producer.access' => [
            'label' => 'Producer Workspace',
            'description' => 'Open tracks specifically shared with this producer and work in Stem Studio.',
            'category' => 'Administration',
            'sort_order' => 32,
        ],
        'team.manage' => [
            'label' => 'Manage Artist Team',
            'description' => 'Create and manage the Artist workspace Manager and Producer accounts.',
            'category' => 'Administration',
            'sort_order' => 34,
        ],
        'listening.view' => [
            'label' => 'Listening Analytics',
            'description' => 'View detailed media play and listening statistics.',
            'category' => 'Analytics',
            'sort_order' => 35,
        ],
        'track_notes.manage' => [
            'label' => 'Shared Production Notes',
            'description' => 'Add and manage song and REGION notes shared with authorized collaborators in Agent Chat.',
            'category' => 'Content',
            'sort_order' => 38,
        ],
        'tracks.manage' => [
            'label' => 'Manage Tracks',
            'description' => 'Add, edit, publish, upload and delete music tracks.',
            'category' => 'Content',
            'sort_order' => 40,
        ],
        'albums.manage' => [
            'label' => 'Manage Albums',
            'description' => 'Create albums and assign tracks to album collections.',
            'category' => 'Content',
            'sort_order' => 45,
        ],
        'shows.manage' => [
            'label' => 'Manage Shows',
            'description' => 'Add, edit, publish and delete show dates.',
            'category' => 'Content',
            'sort_order' => 50,
        ],
        'photos.manage' => [
            'label' => 'Manage Photos',
            'description' => 'Upload, edit, publish and delete Stonefellow photo-library images.',
            'category' => 'Content',
            'sort_order' => 52,
        ],
        'merch.manage' => [
            'label' => 'Manage Merch',
            'description' => 'Add, edit, publish and delete Stonefellow merchandise items.',
            'category' => 'Content',
            'sort_order' => 54,
        ],
        'posts.manage' => [
            'label' => 'Manage Artist Posts',
            'description' => 'Create, edit, publish and delete Stonefellow artist updates and posts.',
            'category' => 'Content',
            'sort_order' => 56,
        ],
        'messages.manage' => [
            'label' => 'Manage Messages',
            'description' => 'Read and delete contact-form messages.',
            'category' => 'Content',
            'sort_order' => 60,
        ],
        'profile.manage' => [
            'label' => 'Manage Artist Profile',
            'description' => 'Edit artist bio, site copy, email and external links.',
            'category' => 'Content',
            'sort_order' => 70,
        ],
        'users.manage' => [
            'label' => 'Manage Users',
            'description' => 'Create, edit, activate, deactivate and delete user accounts.',
            'category' => 'Security',
            'sort_order' => 80,
        ],
        'ai.manage' => [
            'label' => 'Manage AI / API Settings',
            'description' => 'Configure OpenAI and Claude API providers, models and encrypted API credentials.',
            'category' => 'Security',
            'sort_order' => 85,
        ],
        'permissions.manage' => [
            'label' => 'Manage Permissions',
            'description' => 'Change the permissions assigned to each account type.',
            'category' => 'Security',
            'sort_order' => 90,
        ],
    ];
}

function default_role_permissions(): array
{
    return [
        'fan' => [
            'account.access',
            'chat.access',
            'knowledge.access',
        ],
        'artist' => [
            'account.access',
            'chat.access',
            'artist_listening.access',
            'admin.access',
            'team.manage',
            'listening.view',
            'track_notes.manage',
            'tracks.manage',
            'albums.manage',
            'shows.manage',
            'photos.manage',
            'merch.manage',
            'posts.manage',
            'messages.manage',
            'profile.manage',
            'knowledge.access',
            'knowledge.manage',
        ],
        'manager' => [
            'account.access',
            'chat.access',
            'artist_listening.access',
            'admin.access',
            'listening.view',
            'tracks.manage',
            'albums.manage',
            'shows.manage',
            'photos.manage',
            'merch.manage',
            'posts.manage',
            'profile.manage',
            'knowledge.access',
            'knowledge.manage',
        ],
        'producer' => [
            'account.access',
            'chat.access',
            'artist_listening.access',
            'admin.access',
            'producer.access',
        ],
        'supervisor' => [
            'account.access',
            'admin.access',
            'artist_listening.access',
            'listening.view',
            'track_notes.manage',
            'tracks.manage',
            'albums.manage',
            'shows.manage',
            'photos.manage',
            'merch.manage',
            'posts.manage',
            'messages.manage',
            'profile.manage',
            'chat.access',
            'knowledge.access',
            'knowledge.manage',
        ],
        'investor' => [
            'account.access',
            'chat.access',
            'investor.access',
        ],
        'admin' => array_keys(permission_catalog()),
    ];
}

function visibility_options(): array
{
    return [
        'public' => 'Public — Everyone',
        'members' => 'All Signed-In Users',
        'fan' => 'Fans Only',
        'artist' => 'Artists Only',
        'manager' => 'Managers Only',
        'producer' => 'Producers Only',
        'supervisor' => 'Supervisors Only',
        'investor' => 'Investors Only',
        'admin' => 'Admins Only',
    ];
}

function valid_visibility(string $visibility): bool
{
    return array_key_exists($visibility, visibility_options());
}

function permissions_schema_ready(): bool
{
    $pdo = db();
    if (!$pdo) {
        return false;
    }

    try {
        $pdo->query('SELECT 1 FROM permissions LIMIT 1');
        $pdo->query('SELECT 1 FROM role_permissions LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function column_exists(string $table, string $column): bool
{
    $pdo = db();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function table_exists(string $table): bool
{
    $pdo = db();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function access_schema_ready(): bool
{
    return permissions_schema_ready()
        && column_exists('users', 'is_active')
        && column_exists('users', 'last_login_at')
        && column_exists('users', 'avatar_path')
        && table_exists('user_account_types')
        && column_exists('tracks', 'visibility')
        && column_exists('tracks', 'owner_user_id')
        && column_exists('tracks', 'producer_user_id')
        && column_exists('tracks', 'album_id')
        && column_exists('tracks', 'credits')
        && table_exists('albums')
        && table_exists('playlists')
        && table_exists('playlist_tracks')
        && table_exists('track_favorites')
        && table_exists('album_favorites')
        && table_exists('playlist_favorites')
        && table_exists('show_reminders')
        && table_exists('artist_posts')
        && column_exists('merch_items', 'album_id')
        && column_exists('merch_items', 'track_id')
        && table_exists('knowledge_items')
        && table_exists('knowledge_chunks')
        && table_exists('chat_conversations')
        && table_exists('chat_messages')
        && column_exists('tracks', 'lyrics')
        && column_exists('knowledge_items', 'track_id')
        && table_exists('track_notes')
        && column_exists('track_notes','region_start_seconds')
        && column_exists('track_notes','region_end_seconds')
        && table_exists('track_play_sessions')
        && table_exists('track_play_events')
        && column_exists('track_play_sessions', 'source_context')
        && column_exists('tracks', 'genre')
        && column_exists('tracks', 'mood')
        && column_exists('tracks', 'energy')
        && column_exists('tracks', 'keywords')
        && column_exists('contact_messages', 'status')
        && table_exists('notifications')
        && table_exists('track_projects')
        && column_exists('track_projects', 'master_plugin_chain_json')
        && table_exists('track_stems')
        && column_exists('track_stems', 'plugin_chain_json')
        && table_exists('stem_mix_saves')
        && table_exists('photos')
        && table_exists('merch_items')
        && table_exists('team_user_presence')
        && table_exists('team_direct_messages')
        && table_exists('agent_chat_archive')
        && table_exists('agent_memory_items')
        && table_exists('agent_tool_history')
        && table_exists('agent_studio_sessions')
        && table_exists('agent_studio_history')
        && table_exists('booking_agent_research')
        && table_exists('booking_agent_opportunities')
        && column_exists('shows','owner_user_id')
        && column_exists('track_play_sessions','listener_city');
}

function ensure_access_schema(): void
{
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_account_types (
            user_id INT UNSIGNED NOT NULL,
            role VARCHAR(30) NOT NULL,
            assigned_explicitly_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, role),
            INDEX idx_user_account_types_role (role, user_id),
            CONSTRAINT fk_user_account_types_user
              FOREIGN KEY (user_id) REFERENCES users(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (!column_exists('user_account_types', 'assigned_explicitly_at')) {
        $pdo->exec(
            "ALTER TABLE user_account_types
             ADD COLUMN assigned_explicitly_at DATETIME NULL AFTER role"
        );
    }
    $pdo->exec(
        "INSERT IGNORE INTO user_account_types (user_id,role)
         SELECT id,role FROM users WHERE role<>''"
    );
    $pdo->exec(
        "UPDATE user_account_types uat
         INNER JOIN users u ON u.id=uat.user_id AND u.role=uat.role
         SET uat.assigned_explicitly_at=COALESCE(uat.assigned_explicitly_at,NOW())"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS team_user_presence (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            page_key VARCHAR(60) NOT NULL DEFAULT '',
            context_label VARCHAR(190) NOT NULL DEFAULT '',
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_team_presence_seen (last_seen_at),
            CONSTRAINT fk_team_presence_user
              FOREIGN KEY (user_id) REFERENCES users(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS team_direct_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sender_user_id INT UNSIGNED NOT NULL,
            recipient_user_id INT UNSIGNED NOT NULL,
            message_text TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            INDEX idx_team_dm_recipient_read (recipient_user_id, read_at, id),
            INDEX idx_team_dm_sender_recipient (sender_user_id, recipient_user_id, id),
            INDEX idx_team_dm_recipient_sender (recipient_user_id, sender_user_id, id),
            CONSTRAINT fk_team_dm_sender
              FOREIGN KEY (sender_user_id) REFERENCES users(id)
              ON DELETE CASCADE,
            CONSTRAINT fk_team_dm_recipient
              FOREIGN KEY (recipient_user_id) REFERENCES users(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS agent_chat_archive (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            conversation_id BIGINT UNSIGNED NULL,
            source_message_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL,
            input_mode VARCHAR(20) NOT NULL DEFAULT 'text',
            message_text LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_agent_archive_source (user_id, source_message_id),
            INDEX idx_agent_archive_user_created (user_id, created_at, id),
            INDEX idx_agent_archive_conversation (user_id, conversation_id, id),
            CONSTRAINT fk_agent_archive_user
              FOREIGN KEY (user_id) REFERENCES users(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS agent_memory_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            memory_type VARCHAR(40) NOT NULL,
            subject VARCHAR(190) NOT NULL,
            memory_text TEXT NOT NULL,
            memory_hash CHAR(40) NOT NULL,
            source_archive_id BIGINT UNSIGNED NULL,
            confidence DECIMAL(4,3) NOT NULL DEFAULT 0.750,
            occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            metadata_json LONGTEXT NULL,
            UNIQUE KEY uq_agent_memory_hash (user_id, memory_hash),
            INDEX idx_agent_memory_type (user_id, memory_type, is_active, last_seen_at),
            INDEX idx_agent_memory_occurrence (user_id, occurrence_count, last_seen_at),
            CONSTRAINT fk_agent_memory_user
              FOREIGN KEY (user_id) REFERENCES users(id)
              ON DELETE CASCADE,
            CONSTRAINT fk_agent_memory_archive
              FOREIGN KEY (source_archive_id) REFERENCES agent_chat_archive(id)
              ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (table_exists('shows') && !column_exists('shows','owner_user_id')) {
        $pdo->exec("ALTER TABLE shows ADD COLUMN owner_user_id INT UNSIGNED NULL AFTER id");
        try { $pdo->exec("ALTER TABLE shows ADD INDEX idx_shows_owner_date (owner_user_id,show_date)"); } catch (Throwable $e) {}
    }
    if (table_exists('track_play_sessions') && !column_exists('track_play_sessions','listener_city')) {
        $pdo->exec("ALTER TABLE track_play_sessions ADD COLUMN listener_city VARCHAR(120) NOT NULL DEFAULT '', ADD COLUMN listener_region VARCHAR(120) NOT NULL DEFAULT '', ADD COLUMN listener_country VARCHAR(80) NOT NULL DEFAULT '', ADD COLUMN listener_latitude DECIMAL(9,6) NULL, ADD COLUMN listener_longitude DECIMAL(9,6) NULL");
        try { $pdo->exec("ALTER TABLE track_play_sessions ADD INDEX idx_play_location (listener_country,listener_region,listener_city,started_at)"); } catch (Throwable $e) {}
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_tool_history (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,conversation_id BIGINT UNSIGNED NULL,tool_key VARCHAR(80) NOT NULL,request_text TEXT NOT NULL,status VARCHAR(30) NOT NULL,result_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_agent_tool_user_created(user_id,created_at,id),INDEX idx_agent_tool_key(tool_key,created_at),CONSTRAINT fk_agent_tool_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_studio_sessions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,track_id INT UNSIGNED NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'active',started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_agent_studio_user(user_id,last_activity_at),INDEX idx_agent_studio_track(track_id,last_activity_at),CONSTRAINT fk_agent_studio_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,CONSTRAINT fk_agent_studio_track FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_studio_history (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,session_id BIGINT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,role VARCHAR(20) NOT NULL,message_text TEXT NOT NULL,command_json LONGTEXT NULL,status VARCHAR(30) NOT NULL DEFAULT 'complete',result_text TEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_agent_studio_history(session_id,id),CONSTRAINT fk_agent_studio_history_session FOREIGN KEY(session_id) REFERENCES agent_studio_sessions(id) ON DELETE CASCADE,CONSTRAINT fk_agent_studio_history_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS booking_agent_research (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,query_text VARCHAR(500) NOT NULL,market_label VARCHAR(190) NOT NULL DEFAULT '',result_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_booking_research_user(user_id,created_at,id),CONSTRAINT fk_booking_research_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS booking_agent_opportunities (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,title VARCHAR(190) NOT NULL,venue VARCHAR(190) NOT NULL DEFAULT '',city VARCHAR(120) NOT NULL DEFAULT '',region VARCHAR(120) NOT NULL DEFAULT '',source_url VARCHAR(700) NOT NULL DEFAULT '',status VARCHAR(30) NOT NULL DEFAULT 'lead',notes TEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_booking_opportunity_user_status(user_id,status,updated_at),CONSTRAINT fk_booking_opportunity_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!column_exists('users', 'is_active')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role");
    }

    if (!column_exists('users', 'last_login_at')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER is_active");
    }

    if (!column_exists('users', 'avatar_path')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN avatar_path VARCHAR(500) NOT NULL DEFAULT '' AFTER role");
    }

    if (!column_exists('tracks', 'visibility')) {
        $pdo->exec("ALTER TABLE tracks ADD COLUMN visibility VARCHAR(30) NOT NULL DEFAULT 'public' AFTER is_published");
        $pdo->exec("ALTER TABLE tracks ADD INDEX idx_tracks_visibility (visibility)");
    }

    if (!column_exists('tracks', 'owner_user_id')) {
        $pdo->exec("ALTER TABLE tracks ADD COLUMN owner_user_id INT UNSIGNED NULL AFTER id");
        $pdo->exec("ALTER TABLE tracks ADD INDEX idx_tracks_owner_updated (owner_user_id, updated_at)");

        try {
            $pdo->exec(
                "ALTER TABLE tracks
                 ADD CONSTRAINT fk_tracks_owner
                 FOREIGN KEY (owner_user_id) REFERENCES users(id)
                 ON DELETE SET NULL"
            );
        } catch (Throwable $e) {
            // Some hosts may already have an equivalent FK under another name.
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS albums (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(190) NOT NULL,
            release_date DATE NULL,
            description TEXT NOT NULL,
            cover_path VARCHAR(500) NOT NULL DEFAULT '',
            visibility VARCHAR(30) NOT NULL DEFAULT 'members',
            sort_order INT NOT NULL DEFAULT 0,
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_albums_published_sort (is_published, sort_order, id),
            INDEX idx_albums_visibility (visibility),
            INDEX idx_albums_creator (created_by_user_id),
            CONSTRAINT fk_albums_creator
              FOREIGN KEY (created_by_user_id) REFERENCES users(id)
              ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS playlists (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_user_id INT UNSIGNED NOT NULL,
            title VARCHAR(190) NOT NULL,
            description TEXT NOT NULL,
            visibility VARCHAR(30) NOT NULL DEFAULT 'members',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_playlists_owner_updated (owner_user_id, updated_at),
            INDEX idx_playlists_visibility (visibility),
            CONSTRAINT fk_playlists_owner
              FOREIGN KEY (owner_user_id) REFERENCES users(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS playlist_tracks (
            playlist_id INT UNSIGNED NOT NULL,
            track_id INT UNSIGNED NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (playlist_id, track_id),
            INDEX idx_playlist_tracks_sort (playlist_id, sort_order, track_id),
            CONSTRAINT fk_playlist_tracks_playlist
              FOREIGN KEY (playlist_id) REFERENCES playlists(id)
              ON DELETE CASCADE,
            CONSTRAINT fk_playlist_tracks_track
              FOREIGN KEY (track_id) REFERENCES tracks(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS track_favorites (
            user_id INT UNSIGNED NOT NULL,
            track_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, track_id),
            INDEX idx_track_favorites_track_created (track_id, created_at),
            INDEX idx_track_favorites_user_created (user_id, created_at),
            CONSTRAINT fk_track_favorites_user
              FOREIGN KEY (user_id) REFERENCES users(id)
              ON DELETE CASCADE,
            CONSTRAINT fk_track_favorites_track
              FOREIGN KEY (track_id) REFERENCES tracks(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS album_favorites (
            user_id INT UNSIGNED NOT NULL,
            album_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, album_id),
            INDEX idx_album_favorites_album_created (album_id, created_at),
            CONSTRAINT fk_album_favorites_user
              FOREIGN KEY (user_id) REFERENCES users(id)
              ON DELETE CASCADE,
            CONSTRAINT fk_album_favorites_album
              FOREIGN KEY (album_id) REFERENCES albums(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS playlist_favorites (
            user_id INT UNSIGNED NOT NULL,
            playlist_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, playlist_id),
            INDEX idx_playlist_favorites_playlist_created (playlist_id, created_at),
            CONSTRAINT fk_playlist_favorites_user
              FOREIGN KEY (user_id) REFERENCES users(id)
              ON DELETE CASCADE,
            CONSTRAINT fk_playlist_favorites_playlist
              FOREIGN KEY (playlist_id) REFERENCES playlists(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS show_reminders (
            user_id INT UNSIGNED NOT NULL,
            show_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reminded_at DATETIME NULL,
            PRIMARY KEY (user_id, show_id),
            INDEX idx_show_reminders_show (show_id, created_at),
            CONSTRAINT fk_show_reminders_user
              FOREIGN KEY (user_id) REFERENCES users(id)
              ON DELETE CASCADE,
            CONSTRAINT fk_show_reminders_show
              FOREIGN KEY (show_id) REFERENCES shows(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS artist_posts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(190) NOT NULL,
            body TEXT NOT NULL,
            post_type VARCHAR(30) NOT NULL DEFAULT 'update',
            image_path VARCHAR(500) NOT NULL DEFAULT '',
            media_url VARCHAR(500) NOT NULL DEFAULT '',
            visibility VARCHAR(30) NOT NULL DEFAULT 'members',
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            published_at DATETIME NULL,
            created_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_artist_posts_published (is_published, published_at, id),
            INDEX idx_artist_posts_visibility (visibility),
            CONSTRAINT fk_artist_posts_creator
              FOREIGN KEY (created_by_user_id) REFERENCES users(id)
              ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS merch_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(190) NOT NULL,
            description TEXT NOT NULL,
            price_cents INT UNSIGNED NOT NULL DEFAULT 0,
            product_url VARCHAR(500) NOT NULL DEFAULT '',
            image_path VARCHAR(500) NOT NULL DEFAULT '',
            visibility VARCHAR(30) NOT NULL DEFAULT 'members',
            sort_order INT NOT NULL DEFAULT 0,
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_merch_published_sort (is_published, sort_order, id),
            INDEX idx_merch_visibility (visibility),
            INDEX idx_merch_creator (created_by_user_id),
            CONSTRAINT fk_merch_creator
              FOREIGN KEY (created_by_user_id) REFERENCES users(id)
              ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!column_exists('merch_items', 'album_id')) {
        $pdo->exec(
            "ALTER TABLE merch_items
             ADD COLUMN album_id INT UNSIGNED NULL AFTER image_path,
             ADD INDEX idx_merch_album (album_id)"
        );

        try {
            $pdo->exec(
                "ALTER TABLE merch_items
                 ADD CONSTRAINT fk_merch_album
                 FOREIGN KEY (album_id) REFERENCES albums(id)
                 ON DELETE SET NULL"
            );
        } catch (Throwable $e) {}
    }

    if (!column_exists('merch_items', 'track_id')) {
        $pdo->exec(
            "ALTER TABLE merch_items
             ADD COLUMN track_id INT UNSIGNED NULL AFTER album_id,
             ADD INDEX idx_merch_track (track_id)"
        );

        try {
            $pdo->exec(
                "ALTER TABLE merch_items
                 ADD CONSTRAINT fk_merch_track
                 FOREIGN KEY (track_id) REFERENCES tracks(id)
                 ON DELETE SET NULL"
            );
        } catch (Throwable $e) {}
    }

    if (!column_exists('tracks', 'album_id')) {
        $pdo->exec(
            "ALTER TABLE tracks
             ADD COLUMN album_id INT UNSIGNED NULL AFTER producer_user_id"
        );
        $pdo->exec(
            "ALTER TABLE tracks
             ADD INDEX idx_tracks_album_sort (album_id, sort_order, id)"
        );

        try {
            $pdo->exec(
                "ALTER TABLE tracks
                 ADD CONSTRAINT fk_tracks_album
                 FOREIGN KEY (album_id) REFERENCES albums(id)
                 ON DELETE SET NULL"
            );
        } catch (Throwable $e) {
            // Equivalent FK may already exist under another name.
        }
    }

    if (!column_exists('tracks', 'credits')) {
        $pdo->exec(
            "ALTER TABLE tracks
             ADD COLUMN credits TEXT NULL AFTER lyrics"
        );
    }

    if (!column_exists('tracks', 'producer_user_id')) {
        $pdo->exec("ALTER TABLE tracks ADD COLUMN producer_user_id INT UNSIGNED NULL AFTER owner_user_id");
        $pdo->exec("ALTER TABLE tracks ADD INDEX idx_tracks_producer_updated (producer_user_id, updated_at)");

        try {
            $pdo->exec(
                "ALTER TABLE tracks
                 ADD CONSTRAINT fk_tracks_producer
                 FOREIGN KEY (producer_user_id) REFERENCES users(id)
                 ON DELETE SET NULL"
            );
        } catch (Throwable $e) {
            // Some hosts may already have an equivalent FK under another name.
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS permissions (
            permission_key VARCHAR(100) PRIMARY KEY,
            label VARCHAR(190) NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT '',
            category VARCHAR(100) NOT NULL DEFAULT 'General',
            sort_order INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS role_permissions (
            role VARCHAR(30) NOT NULL,
            permission_key VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (role, permission_key),
            CONSTRAINT fk_role_permissions_permission
              FOREIGN KEY (permission_key) REFERENCES permissions(permission_key)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS knowledge_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(190) NOT NULL,
            description TEXT NOT NULL,
            file_name VARCHAR(255) NOT NULL DEFAULT '',
            file_path VARCHAR(500) NOT NULL DEFAULT '',
            file_type VARCHAR(50) NOT NULL DEFAULT 'text',
            mime_type VARCHAR(120) NOT NULL DEFAULT '',
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            content_text LONGTEXT NOT NULL,
            visibility VARCHAR(30) NOT NULL DEFAULT 'members',
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_kb_published_visibility (is_published, visibility),
            INDEX idx_kb_creator (created_by_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS knowledge_chunks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            knowledge_id INT UNSIGNED NOT NULL,
            chunk_index INT NOT NULL DEFAULT 0,
            chunk_text TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_kb_chunks_item (knowledge_id, chunk_index),
            FULLTEXT KEY ft_kb_chunk (chunk_text),
            CONSTRAINT fk_knowledge_chunks_item
              FOREIGN KEY (knowledge_id) REFERENCES knowledge_items(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS chat_conversations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(190) NOT NULL DEFAULT 'New chat',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_chat_conversations_user (user_id, updated_at),
            CONSTRAINT fk_chat_conversations_user
              FOREIGN KEY (user_id) REFERENCES users(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS chat_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NULL,
            role VARCHAR(20) NOT NULL,
            message LONGTEXT NOT NULL,
            context_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_chat_messages_conversation (conversation_id, id),
            CONSTRAINT fk_chat_messages_conversation
              FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!column_exists('tracks', 'lyrics')) {
        $pdo->exec("ALTER TABLE tracks ADD COLUMN lyrics LONGTEXT NOT NULL AFTER duration");
    }

    if (!column_exists('knowledge_items', 'track_id')) {
        $pdo->exec("ALTER TABLE knowledge_items ADD COLUMN track_id INT UNSIGNED NULL AFTER id");
        $pdo->exec("ALTER TABLE knowledge_items ADD INDEX idx_knowledge_track (track_id)");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS track_notes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            track_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            note TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_track_notes_track (track_id, created_at),
            INDEX idx_track_notes_user (user_id),
            CONSTRAINT fk_track_notes_track
              FOREIGN KEY (track_id) REFERENCES tracks(id)
              ON DELETE CASCADE,
            CONSTRAINT fk_track_notes_user
              FOREIGN KEY (user_id) REFERENCES users(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!column_exists('track_notes','region_start_seconds')) {
        $pdo->exec("ALTER TABLE track_notes ADD COLUMN region_start_seconds DECIMAL(12,4) NULL AFTER note");
    }
    if (!column_exists('track_notes','region_end_seconds')) {
        $pdo->exec("ALTER TABLE track_notes ADD COLUMN region_end_seconds DECIMAL(12,4) NULL AFTER region_start_seconds");
    }
    try { $pdo->exec("ALTER TABLE track_notes ADD INDEX idx_track_notes_region (track_id,region_start_seconds,created_at)"); } catch (Throwable $e) {}

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS track_play_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_token CHAR(64) NOT NULL UNIQUE,
            track_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NULL,
            listener_hash CHAR(64) NOT NULL,
            device_type VARCHAR(30) NOT NULL DEFAULT 'unknown',
            referrer_host VARCHAR(190) NOT NULL DEFAULT '',
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_event_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ended_at DATETIME NULL,
            listened_seconds DECIMAL(12,2) NOT NULL DEFAULT 0,
            last_position_seconds DECIMAL(12,2) NOT NULL DEFAULT 0,
            max_position_seconds DECIMAL(12,2) NOT NULL DEFAULT 0,
            duration_seconds DECIMAL(12,2) NOT NULL DEFAULT 0,
            completion_percent DECIMAL(7,2) NOT NULL DEFAULT 0,
            qualified_play TINYINT(1) NOT NULL DEFAULT 0,
            completed TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_play_track_started (track_id, started_at),
            INDEX idx_play_user_started (user_id, started_at),
            INDEX idx_play_listener_started (listener_hash, started_at),
            CONSTRAINT fk_play_session_track
              FOREIGN KEY (track_id) REFERENCES tracks(id)
              ON DELETE CASCADE,
            CONSTRAINT fk_play_session_user
              FOREIGN KEY (user_id) REFERENCES users(id)
              ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS track_play_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(30) NOT NULL,
            position_seconds DECIMAL(12,2) NOT NULL DEFAULT 0,
            listened_delta_seconds DECIMAL(8,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_play_events_session (session_id, id),
            INDEX idx_play_events_type_time (event_type, created_at),
            CONSTRAINT fk_play_events_session
              FOREIGN KEY (session_id) REFERENCES track_play_sessions(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!column_exists('track_play_sessions', 'source_context')) {
        $pdo->exec("ALTER TABLE track_play_sessions ADD COLUMN source_context VARCHAR(30) NOT NULL DEFAULT 'player' AFTER referrer_host");
    }

    if (!column_exists('tracks', 'description')) {
        $pdo->exec("ALTER TABLE tracks ADD COLUMN description TEXT NOT NULL AFTER lyrics");
    }
    if (!column_exists('tracks', 'genre')) {
        $pdo->exec("ALTER TABLE tracks ADD COLUMN genre VARCHAR(255) NOT NULL DEFAULT '' AFTER description");
    }
    if (!column_exists('tracks', 'mood')) {
        $pdo->exec("ALTER TABLE tracks ADD COLUMN mood VARCHAR(255) NOT NULL DEFAULT '' AFTER genre");
    }
    if (!column_exists('tracks', 'energy')) {
        $pdo->exec("ALTER TABLE tracks ADD COLUMN energy VARCHAR(30) NOT NULL DEFAULT '' AFTER mood");
    }
    if (!column_exists('tracks', 'tempo_bpm')) {
        $pdo->exec("ALTER TABLE tracks ADD COLUMN tempo_bpm SMALLINT UNSIGNED NULL AFTER energy");
    }
    if (!column_exists('tracks', 'keywords')) {
        $pdo->exec("ALTER TABLE tracks ADD COLUMN keywords VARCHAR(500) NOT NULL DEFAULT '' AFTER tempo_bpm");
    }

    if (!column_exists('contact_messages', 'status')) {
        $pdo->exec("ALTER TABLE contact_messages ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'new' AFTER is_read");
    }
    if (!column_exists('contact_messages', 'assigned_user_id')) {
        $pdo->exec("ALTER TABLE contact_messages ADD COLUMN assigned_user_id INT UNSIGNED NULL AFTER status");
    }
    if (!column_exists('contact_messages', 'admin_notes')) {
        $pdo->exec("ALTER TABLE contact_messages ADD COLUMN admin_notes TEXT NULL AFTER assigned_user_id");
    }
    if (!column_exists('contact_messages', 'updated_at')) {
        $pdo->exec("ALTER TABLE contact_messages ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS photos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(190) NOT NULL,
            caption TEXT NOT NULL,
            alt_text VARCHAR(255) NOT NULL DEFAULT '',
            image_path VARCHAR(500) NOT NULL,
            visibility VARCHAR(30) NOT NULL DEFAULT 'members',
            sort_order INT NOT NULL DEFAULT 0,
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_photos_published_sort (is_published, sort_order, id),
            INDEX idx_photos_visibility (visibility),
            INDEX idx_photos_creator (created_by_user_id),
            CONSTRAINT fk_photos_creator
              FOREIGN KEY (created_by_user_id) REFERENCES users(id)
              ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS merch_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(190) NOT NULL,
            description TEXT NOT NULL,
            price_cents INT UNSIGNED NOT NULL DEFAULT 0,
            product_url VARCHAR(500) NOT NULL DEFAULT '',
            image_path VARCHAR(500) NOT NULL DEFAULT '',
            visibility VARCHAR(30) NOT NULL DEFAULT 'members',
            sort_order INT NOT NULL DEFAULT 0,
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_merch_published_sort (is_published, sort_order, id),
            INDEX idx_merch_visibility (visibility),
            INDEX idx_merch_creator (created_by_user_id),
            CONSTRAINT fk_merch_creator
              FOREIGN KEY (created_by_user_id) REFERENCES users(id)
              ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'general',
            title VARCHAR(190) NOT NULL,
            body VARCHAR(500) NOT NULL DEFAULT '',
            target_url VARCHAR(500) NOT NULL DEFAULT '',
            source_type VARCHAR(80) NOT NULL DEFAULT '',
            source_id BIGINT UNSIGNED NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            INDEX idx_notifications_user_read_created (user_id, is_read, created_at),
            INDEX idx_notifications_source (source_type, source_id),
            CONSTRAINT fk_notifications_user
              FOREIGN KEY (user_id) REFERENCES users(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS track_projects (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            track_id INT UNSIGNED NOT NULL UNIQUE,
            project_name VARCHAR(190) NOT NULL DEFAULT '',
            source_zip_name VARCHAR(255) NOT NULL DEFAULT '',
            rpp_file_name VARCHAR(255) NOT NULL DEFAULT '',
            rpp_file_path VARCHAR(500) NOT NULL DEFAULT '',
            tempo_bpm DECIMAL(7,2) NULL,
            time_signature VARCHAR(20) NOT NULL DEFAULT '',
            duration_measures INT UNSIGNED NULL,
            project_sample_rate INT UNSIGNED NULL,
            media_sample_rate INT UNSIGNED NULL,
            project_start_seconds DECIMAL(12,4) NOT NULL DEFAULT 0,
            imported_by_user_id INT UNSIGNED NULL,
            imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_track_projects_imported (imported_at),
            CONSTRAINT fk_track_projects_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE,
            CONSTRAINT fk_track_projects_user FOREIGN KEY (imported_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS track_stems (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            track_id INT UNSIGNED NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            stem_name VARCHAR(190) NOT NULL,
            stem_role VARCHAR(80) NOT NULL DEFAULT 'Other',
            source_track_name VARCHAR(190) NOT NULL DEFAULT '',
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            channels TINYINT UNSIGNED NOT NULL DEFAULT 0,
            sample_rate INT UNSIGNED NOT NULL DEFAULT 0,
            bit_depth SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            duration_seconds DECIMAL(12,4) NOT NULL DEFAULT 0,
            start_offset_seconds DECIMAL(12,4) NOT NULL DEFAULT 0,
            rpp_track_guid VARCHAR(80) NOT NULL DEFAULT '',
            rpp_volume DECIMAL(12,6) NOT NULL DEFAULT 1,
            rpp_pan DECIMAL(8,6) NOT NULL DEFAULT 0,
            rpp_fx_summary VARCHAR(1000) NOT NULL DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_track_stems_track_order (track_id,is_active,sort_order,id),
            INDEX idx_track_stems_role (track_id,stem_role),
            CONSTRAINT fk_track_stems_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE,
            CONSTRAINT fk_track_stems_project FOREIGN KEY (project_id) REFERENCES track_projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!column_exists('track_projects', 'master_plugin_chain_json')) {
        $pdo->exec("ALTER TABLE track_projects ADD COLUMN master_plugin_chain_json LONGTEXT NULL AFTER project_start_seconds");
    }

    if (!column_exists('track_projects', 'duration_measures')) {
        $pdo->exec("ALTER TABLE track_projects ADD COLUMN duration_measures INT UNSIGNED NULL AFTER time_signature");
    }

    if (!column_exists('track_stems', 'plugin_chain_json')) {
        $pdo->exec("ALTER TABLE track_stems ADD COLUMN plugin_chain_json LONGTEXT NULL AFTER rpp_fx_summary");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stem_mix_saves (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            track_id INT UNSIGNED NOT NULL,
            mix_name VARCHAR(120) NOT NULL DEFAULT 'My Mix',
            mix_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_stem_mix_user_track (user_id,track_id,updated_at),
            CONSTRAINT fk_stem_mix_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_stem_mix_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // v13 message/notification backfill.
    try {
        $pdo->exec(
            "UPDATE contact_messages
             SET status='open'
             WHERE is_read=1 AND status='new'"
        );

        if (function_exists('create_notification_for_permission')) {
            $unreadMessages = $pdo->query(
                "SELECT id,name,topic
                 FROM contact_messages
                 WHERE is_read=0
                 ORDER BY created_at ASC"
            )->fetchAll();

            foreach ($unreadMessages as $message) {
                create_notification_for_permission(
                    'messages.manage',
                    'contact_message',
                    'New contact message',
                    (string)$message['name'] . ' — ' . (string)$message['topic'],
                    url('/admin/messages.php?view=' . (int)$message['id']),
                    'contact_message',
                    (int)$message['id']
                );
            }
        }
    } catch (Throwable $e) {
        error_log('Stonefellow v13 message backfill failed: ' . $e->getMessage());
    }

    seed_permission_catalog();
}

function seed_permission_catalog(): void
{
    $pdo = db();
    if (!$pdo) {
        return;
    }

    $catalog = permission_catalog();
    $stmt = $pdo->prepare(
        'INSERT INTO permissions (permission_key, label, description, category, sort_order)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           label = VALUES(label),
           description = VALUES(description),
           category = VALUES(category),
           sort_order = VALUES(sort_order)'
    );

    foreach ($catalog as $key => $permission) {
        $stmt->execute([
            $key,
            $permission['label'],
            $permission['description'],
            $permission['category'],
            $permission['sort_order'],
        ]);
    }

    $existing = (int)$pdo->query('SELECT COUNT(*) FROM role_permissions')->fetchColumn();
    if ($existing === 0) {
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role, permission_key) VALUES (?, ?)'
        );
        foreach (default_role_permissions() as $role => $permissions) {
            foreach ($permissions as $permission) {
                $insert->execute([$role, $permission]);
            }
        }
    }

    // Never re-apply historical defaults here. This function is called from
    // Admin > Permissions, where role_permissions is the source of truth.
    // Defaults are installed only when the matrix is completely empty above.
    $adminInsert = $pdo->prepare(
        'INSERT IGNORE INTO role_permissions (role, permission_key) VALUES (?, ?)'
    );
    foreach (array_keys($catalog) as $permission) {
        $adminInsert->execute(['admin', $permission]);
    }

}

function role_has_permission(string $role, string $permission): bool
{
    if ($role === 'admin') {
        return true;
    }

    if (!isset(permission_catalog()[$permission])) {
        return false;
    }

    $pdo = db();
    if ($pdo && permissions_schema_ready()) {
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM role_permissions WHERE role = ? AND permission_key = ? LIMIT 1'
            );
            $stmt->execute([$role, $permission]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            // Fall through to safe defaults while upgrading an older install.
        }
    }

    return in_array($permission, default_role_permissions()[$role] ?? [], true);
}

function has_permission(string $permission, ?array $user = null): bool
{
    $user ??= current_user();
    if (!$user) {
        return false;
    }

    foreach (user_roles_for_user($user) as $role) {
        if (role_has_permission($role, $permission)) {
            return true;
        }
    }

    return false;
}

function has_any_permission(array $permissions, ?array $user = null): bool
{
    foreach ($permissions as $permission) {
        if (is_string($permission) && has_permission($permission, $user)) {
            return true;
        }
    }
    return false;
}

function require_permission(string $permission): void
{
    if (!is_logged_in()) {
        flash('error', 'Please sign in to continue.');
        redirect(url('/login.php'));
    }

    if (!has_permission($permission)) {
        http_response_code(403);
        $pageTitle = 'Access Denied | Stonefellow';
        $pageDescription = 'You do not have permission to access this Stonefellow feature.';
        $activePage = '';
        require STONEFELLOW_ROOT . '/includes/header.php';
        echo '<main><section class="section" style="padding-top:130px"><div class="wrap" style="max-width:760px"><div class="card"><p class="section-kicker">403</p><h2>Access Denied</h2><p style="color:#aaa095;line-height:1.7">Your account does not have permission to access this feature.</p><a class="btn" href="' . e(url('/account.php')) . '">Back to Account</a></div></div></section></main>';
        require STONEFELLOW_ROOT . '/includes/footer.php';
        exit;
    }
}

function can_manage_track_production(array $track, ?array $user = null): bool
{
    $user ??= current_user();

    if (!$user) {
        return false;
    }

    if (has_permission('tracks.manage', $user)) {
        return true;
    }

    if (!has_permission('producer.access', $user)) {
        return false;
    }

    $userId = (int)($user['id'] ?? 0);
    $producerUserId = (int)($track['producer_user_id'] ?? 0);

    return $userId > 0
        && $producerUserId > 0
        && $userId === $producerUserId;
}

function can_manage_track_production_id(int $trackId, ?array $user = null): bool
{
    $pdo = db();

    if (!$pdo || $trackId < 1) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT id,owner_user_id,producer_user_id,visibility
             FROM tracks
             WHERE id=?
             LIMIT 1'
        );
        $stmt->execute([$trackId]);
        $track = $stmt->fetch();

        return $track
            ? can_manage_track_production($track, $user)
            : false;
    } catch (Throwable $e) {
        return false;
    }
}

function can_view_visibility(string $visibility, ?array $user = null): bool
{
    $visibility = valid_visibility($visibility) ? $visibility : 'public';

    if ($visibility === 'public') {
        return true;
    }

    $user ??= current_user();
    if (!$user) {
        return false;
    }

    if (user_has_role('admin', $user)) {
        return true;
    }

    if ($visibility === 'members') {
        return true;
    }

    return user_has_role($visibility, $user);
}

function can_view_track(array $track, ?array $user = null): bool
{
    $user ??= current_user();

    if ($user && can_manage_track_production($track, $user)) {
        return true;
    }

    return can_view_visibility(
        (string)($track['visibility'] ?? 'public'),
        $user
    );
}
