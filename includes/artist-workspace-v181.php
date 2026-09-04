<?php
declare(strict_types=1);

/** Private artist catalog layer. Platform catalog rows remain untouched. */
function artist_workspace_v181_schema_ready(?PDO $pdo = null): bool
{
    $pdo ??= db();
    return (bool)$pdo
        && table_exists('artist_workspaces_v181')
        && column_exists('artist_workspaces_v181', 'profile_slug')
        && column_exists('artist_workspaces_v181', 'bio')
        && column_exists('artist_workspaces_v181', 'profile_image_path')
        && column_exists('artist_workspaces_v181', 'cover_image_path')
        && column_exists('artist_workspaces_v181', 'website_url')
        && column_exists('artist_workspaces_v181', 'instagram_url')
        && column_exists('artist_workspaces_v181', 'tiktok_url')
        && column_exists('artist_workspaces_v181', 'youtube_url')
        && column_exists('artist_workspaces_v181', 'spotify_url')
        && column_exists('artist_workspaces_v181', 'apple_music_url')
        && table_exists('artist_catalog_tracks_v181')
        && table_exists('artist_catalog_photos_v181')
        && table_exists('artist_catalog_merch_v181')
        && table_exists('artist_catalog_shows_v181')
        && table_exists('artist_catalog_albums_v181')
        && table_exists('artist_posts_v181')
        && table_exists('artist_release_plans_v181')
        && table_exists('artist_workspace_track_favorites_v181')
        && table_exists('artist_workspace_playlist_tracks_v181')
        && table_exists('artist_workspace_saved_shows_v181')
        && table_exists('artist_workspace_saved_photos_v181');
}

function artist_workspace_v181_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt=$pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? LIMIT 1');
    $stmt->execute([$table,$index]);
    return (bool)$stmt->fetchColumn();
}

function artist_workspace_v181_ensure_schema(?PDO $pdo = null): void
{
    $pdo ??= db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_workspaces_v181 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        artist_user_id INT UNSIGNED NOT NULL,
        workspace_name VARCHAR(190) NOT NULL,
        profile_slug VARCHAR(190) NULL,
        bio TEXT NULL,
        profile_image_path VARCHAR(500) NOT NULL DEFAULT '',
        cover_image_path VARCHAR(500) NOT NULL DEFAULT '',
        website_url VARCHAR(500) NOT NULL DEFAULT '',
        instagram_url VARCHAR(500) NOT NULL DEFAULT '',
        tiktok_url VARCHAR(500) NOT NULL DEFAULT '',
        youtube_url VARCHAR(500) NOT NULL DEFAULT '',
        spotify_url VARCHAR(500) NOT NULL DEFAULT '',
        apple_music_url VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_artist_workspace_user (artist_user_id),
        UNIQUE KEY uq_artist_workspace_profile_slug (profile_slug),
        CONSTRAINT fk_artist_workspace_user_v181 FOREIGN KEY (artist_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $profileColumns=[
        'profile_slug'=>"VARCHAR(190) NULL AFTER workspace_name",
        'bio'=>"TEXT NULL AFTER profile_slug",
        'profile_image_path'=>"VARCHAR(500) NOT NULL DEFAULT '' AFTER bio",
        'cover_image_path'=>"VARCHAR(500) NOT NULL DEFAULT '' AFTER profile_image_path",
        'website_url'=>"VARCHAR(500) NOT NULL DEFAULT '' AFTER cover_image_path",
        'instagram_url'=>"VARCHAR(500) NOT NULL DEFAULT '' AFTER website_url",
        'tiktok_url'=>"VARCHAR(500) NOT NULL DEFAULT '' AFTER instagram_url",
        'youtube_url'=>"VARCHAR(500) NOT NULL DEFAULT '' AFTER tiktok_url",
        'spotify_url'=>"VARCHAR(500) NOT NULL DEFAULT '' AFTER youtube_url",
        'apple_music_url'=>"VARCHAR(500) NOT NULL DEFAULT '' AFTER spotify_url",
    ];
    foreach($profileColumns as $column=>$definition){
        if(!column_exists('artist_workspaces_v181',$column)) $pdo->exec("ALTER TABLE artist_workspaces_v181 ADD COLUMN {$column} {$definition}");
    }
    if(!artist_workspace_v181_index_exists($pdo,'artist_workspaces_v181','uq_artist_workspace_profile_slug')){
        $pdo->exec('ALTER TABLE artist_workspaces_v181 ADD UNIQUE KEY uq_artist_workspace_profile_slug (profile_slug)');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_catalog_tracks_v181 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        workspace_id BIGINT UNSIGNED NOT NULL,
        source_track_id INT UNSIGNED NULL,
        title VARCHAR(190) NOT NULL,
        album VARCHAR(190) NOT NULL DEFAULT '',
        audio_path VARCHAR(500) NOT NULL DEFAULT '',
        cover_path VARCHAR(500) NOT NULL DEFAULT '',
        visibility VARCHAR(30) NOT NULL DEFAULT 'members',
        is_published TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_artist_track_source (workspace_id,source_track_id),
        INDEX idx_artist_track_workspace (workspace_id,updated_at),
        CONSTRAINT fk_artist_track_workspace_v181 FOREIGN KEY (workspace_id) REFERENCES artist_workspaces_v181(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_catalog_photos_v181 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        workspace_id BIGINT UNSIGNED NOT NULL,
        source_photo_id INT UNSIGNED NULL,
        title VARCHAR(190) NOT NULL,
        image_path VARCHAR(500) NOT NULL,
        visibility VARCHAR(30) NOT NULL DEFAULT 'members',
        is_published TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_artist_photo_source (workspace_id,source_photo_id),
        INDEX idx_artist_photo_workspace (workspace_id,updated_at),
        CONSTRAINT fk_artist_photo_workspace_v181 FOREIGN KEY (workspace_id) REFERENCES artist_workspaces_v181(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_catalog_merch_v181 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        workspace_id BIGINT UNSIGNED NOT NULL,
        source_merch_id INT UNSIGNED NULL,
        title VARCHAR(190) NOT NULL,
        description TEXT NOT NULL,
        price_cents INT UNSIGNED NOT NULL DEFAULT 0,
        image_path VARCHAR(500) NOT NULL DEFAULT '',
        product_url VARCHAR(500) NOT NULL DEFAULT '',
        visibility VARCHAR(30) NOT NULL DEFAULT 'members',
        is_published TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_artist_merch_source (workspace_id,source_merch_id),
        INDEX idx_artist_merch_workspace (workspace_id,updated_at),
        CONSTRAINT fk_artist_merch_workspace_v181 FOREIGN KEY (workspace_id) REFERENCES artist_workspaces_v181(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_catalog_shows_v181 (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,workspace_id BIGINT UNSIGNED NOT NULL,source_show_id INT UNSIGNED NULL,show_date DATETIME NOT NULL,venue VARCHAR(190) NOT NULL,city VARCHAR(190) NOT NULL DEFAULT '',region VARCHAR(190) NOT NULL DEFAULT '',notes TEXT NOT NULL,ticket_url VARCHAR(500) NOT NULL DEFAULT '',is_published TINYINT(1) NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_artist_show_source (workspace_id,source_show_id),INDEX idx_artist_show_workspace (workspace_id,show_date),CONSTRAINT fk_artist_show_workspace_v181 FOREIGN KEY (workspace_id) REFERENCES artist_workspaces_v181(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_catalog_albums_v181 (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,workspace_id BIGINT UNSIGNED NOT NULL,source_album_id INT UNSIGNED NULL,title VARCHAR(190) NOT NULL,release_date DATE NULL,description TEXT NOT NULL,cover_path VARCHAR(500) NOT NULL DEFAULT '',visibility VARCHAR(30) NOT NULL DEFAULT 'members',is_published TINYINT(1) NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_artist_album_source (workspace_id,source_album_id),INDEX idx_artist_album_workspace (workspace_id,updated_at),CONSTRAINT fk_artist_album_workspace_v181 FOREIGN KEY (workspace_id) REFERENCES artist_workspaces_v181(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_posts_v181 (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,workspace_id BIGINT UNSIGNED NOT NULL,source_post_id INT UNSIGNED NULL,title VARCHAR(190) NOT NULL,body TEXT NOT NULL,post_type VARCHAR(30) NOT NULL DEFAULT 'update',image_path VARCHAR(500) NOT NULL DEFAULT '',media_url VARCHAR(500) NOT NULL DEFAULT '',visibility VARCHAR(30) NOT NULL DEFAULT 'members',is_published TINYINT(1) NOT NULL DEFAULT 0,published_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_artist_post_source (workspace_id,source_post_id),INDEX idx_artist_post_workspace (workspace_id,updated_at),CONSTRAINT fk_artist_post_workspace_v181 FOREIGN KEY (workspace_id) REFERENCES artist_workspaces_v181(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_release_plans_v181 (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,workspace_id BIGINT UNSIGNED NOT NULL,source_release_id BIGINT UNSIGNED NULL,title VARCHAR(190) NOT NULL,release_type VARCHAR(40) NOT NULL DEFAULT 'single',status VARCHAR(30) NOT NULL DEFAULT 'planning',priority VARCHAR(20) NOT NULL DEFAULT 'normal',target_date DATETIME NULL,agent_goal TEXT NULL,notes TEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_artist_release_source (workspace_id,source_release_id),INDEX idx_artist_release_workspace (workspace_id,target_date,status),CONSTRAINT fk_artist_release_workspace_v181 FOREIGN KEY (workspace_id) REFERENCES artist_workspaces_v181(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_workspace_track_favorites_v181 (user_id INT UNSIGNED NOT NULL,artist_track_id BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,artist_track_id),CONSTRAINT fk_artist_favorite_user_v181 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,CONSTRAINT fk_artist_favorite_track_v181 FOREIGN KEY (artist_track_id) REFERENCES artist_catalog_tracks_v181(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_workspace_playlist_tracks_v181 (playlist_id INT UNSIGNED NOT NULL,artist_track_id BIGINT UNSIGNED NOT NULL,sort_order INT UNSIGNED NOT NULL DEFAULT 0,added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(playlist_id,artist_track_id),CONSTRAINT fk_artist_playlist_track_playlist_v181 FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,CONSTRAINT fk_artist_playlist_track_track_v181 FOREIGN KEY (artist_track_id) REFERENCES artist_catalog_tracks_v181(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_workspace_saved_shows_v181 (user_id INT UNSIGNED NOT NULL,artist_show_id BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,artist_show_id),INDEX idx_artist_saved_show_user (user_id,created_at),CONSTRAINT fk_artist_saved_show_user_v181 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,CONSTRAINT fk_artist_saved_show_v181 FOREIGN KEY (artist_show_id) REFERENCES artist_catalog_shows_v181(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_workspace_saved_photos_v181 (user_id INT UNSIGNED NOT NULL,artist_photo_id BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,artist_photo_id),INDEX idx_artist_saved_photo_user (user_id,created_at),CONSTRAINT fk_artist_saved_photo_user_v181 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,CONSTRAINT fk_artist_saved_photo_v181 FOREIGN KEY (artist_photo_id) REFERENCES artist_catalog_photos_v181(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (table_exists('chat_conversations') && !column_exists('chat_conversations', 'artist_workspace_id')) {
        $pdo->exec('ALTER TABLE chat_conversations ADD COLUMN artist_workspace_id BIGINT UNSIGNED NULL AFTER user_id, ADD INDEX idx_chat_conversations_artist_workspace (artist_workspace_id,updated_at)');
    }
    if (table_exists('playlists') && !column_exists('playlists', 'artist_workspace_id')) {
        $pdo->exec('ALTER TABLE playlists ADD COLUMN artist_workspace_id BIGINT UNSIGNED NULL AFTER owner_user_id, ADD INDEX idx_playlists_artist_workspace (artist_workspace_id,updated_at)');
    }
}

function artist_workspace_v181_slug(string $value): string
{
    $value=strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9]+/','-',$value) ?? '';
    return substr(trim($value,'-'),0,120);
}

function artist_workspace_v181_profile_url(array $workspace): string
{
    $slug=trim((string)($workspace['profile_slug']??''));
    $query=$slug!==''?'artist='.rawurlencode($slug):'user_id='.(int)($workspace['artist_user_id']??0);
    return url('/artist-profile.php?'.$query);
}

/** Resolve an Artist account's public profile without coupling viewing to CMS permissions. */
function artist_workspace_v181_profile_url_for_user(?array $user = null): string
{
    $user ??= current_user();
    if (!$user || !user_has_role('artist', $user) || (int)($user['id'] ?? 0) < 1) return '';

    $fallback = url('/artist-profile.php?user_id=' . (int)$user['id']);
    $pdo = db();
    if (!$pdo || !artist_workspace_v181_schema_ready($pdo)) return $fallback;

    try {
        $workspace = artist_workspace_v181_lookup_public($pdo, '', (int)$user['id']);
        return $workspace ? artist_workspace_v181_profile_url($workspace) : $fallback;
    } catch (Throwable $e) {
        return $fallback;
    }
}

function artist_workspace_v181_lookup_public(PDO $pdo, string $slug='', int $artistUserId=0): ?array
{
    if($slug!==''){
        $slug=artist_workspace_v181_slug($slug);
        if($slug==='') return null;
        $stmt=$pdo->prepare('SELECT * FROM artist_workspaces_v181 WHERE profile_slug=? LIMIT 1');
        $stmt->execute([$slug]);
    } elseif($artistUserId>0) {
        $stmt=$pdo->prepare('SELECT * FROM artist_workspaces_v181 WHERE artist_user_id=? LIMIT 1');
        $stmt->execute([$artistUserId]);
    } else return null;
    return $stmt->fetch() ?: null;
}

function artist_workspace_v181_validate_external_url(string $value): string
{
    $value=trim($value);
    if($value==='') return '';
    if(!filter_var($value,FILTER_VALIDATE_URL)) throw new RuntimeException('Enter a valid external URL.');
    $scheme=strtolower((string)parse_url($value,PHP_URL_SCHEME));
    if(!in_array($scheme,['https','http'],true)) throw new RuntimeException('External links must use http or https.');
    return $value;
}

function artist_workspace_v181_profile_image_root(): string
{
    return dirname(__DIR__).'/uploads/artist-profiles';
}

function artist_workspace_v181_store_profile_image(array $file, int $workspaceId, string $kind): string
{
    if(!in_array($kind,['profile','cover'],true)) throw new RuntimeException('Unknown profile image type.');
    $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
    if($error===UPLOAD_ERR_NO_FILE) return '';
    if($error!==UPLOAD_ERR_OK) throw new RuntimeException('Image upload failed.');
    $size=(int)($file['size']??0);
    if($size<1 || $size>8*1024*1024) throw new RuntimeException('Profile images must be 8 MB or smaller.');
    $tmp=(string)($file['tmp_name']??'');
    if($tmp==='' || !is_uploaded_file($tmp)) throw new RuntimeException('Invalid image upload.');
    $info=@getimagesize($tmp);
    $mime=strtolower((string)($info['mime']??''));
    $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if(!isset($extensions[$mime])) throw new RuntimeException('Use a JPG, PNG, or WebP image.');
    $base=artist_workspace_v181_profile_image_root();
    $dir=$base.'/'.$workspaceId;
    if(!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) throw new RuntimeException('Profile image directory could not be created.');
    if(!is_file($base.'/.htaccess')) @file_put_contents($base.'/.htaccess',"Require all denied\nDeny from all\n");
    $filename=$kind.'-'.bin2hex(random_bytes(16)).'.'.$extensions[$mime];
    $target=$dir.'/'.$filename;
    if(!move_uploaded_file($tmp,$target)) throw new RuntimeException('Profile image could not be saved.');
    @chmod($target,0640);
    return 'uploads/artist-profiles/'.$workspaceId.'/'.$filename;
}

function artist_workspace_v181_owned_image_path(int $workspaceId, string $storedPath): ?string
{
    if($workspaceId<1 || $storedPath==='') return null;
    $prefix='uploads/artist-profiles/'.$workspaceId.'/';
    if(!str_starts_with(str_replace('\\','/',$storedPath),$prefix)) return null;
    $base=realpath(artist_workspace_v181_profile_image_root().'/'.$workspaceId);
    $path=realpath(dirname(__DIR__).'/'.$storedPath);
    if(!$base || !$path || !str_starts_with($path,$base.DIRECTORY_SEPARATOR) || !is_file($path)) return null;
    return $path;
}

function artist_workspace_v181_for_user(PDO $pdo, array $user): array
{
    if (!user_has_role('artist', $user)) throw new RuntimeException('Artist workspace access is required.');
    $userId=(int)($user['id']??0); if ($userId<1) throw new RuntimeException('Sign in is required.');
    $name=trim((string)($user['display_name']??'')) ?: 'Artist';
    $pdo->prepare('INSERT INTO artist_workspaces_v181 (artist_user_id,workspace_name) VALUES (?,?) ON DUPLICATE KEY UPDATE workspace_name=VALUES(workspace_name)')->execute([$userId,$name]);
    $stmt=$pdo->prepare('SELECT * FROM artist_workspaces_v181 WHERE artist_user_id=? LIMIT 1'); $stmt->execute([$userId]);
    $workspace=$stmt->fetch() ?: throw new RuntimeException('Artist workspace could not be opened.');
    if(trim((string)($workspace['profile_slug']??''))===''){
        $base=artist_workspace_v181_slug($name) ?: 'artist';
        $slug=$base;
        $n=0;
        do {
            $check=$pdo->prepare('SELECT 1 FROM artist_workspaces_v181 WHERE profile_slug=? AND id<>? LIMIT 1');
            $check->execute([$slug,(int)$workspace['id']]);
            if(!$check->fetchColumn()) break;
            $n++;
            $slug=$base.'-'.$userId.($n>1?'-'.$n:'');
        } while($n<100);
        $pdo->prepare('UPDATE artist_workspaces_v181 SET profile_slug=? WHERE id=? AND artist_user_id=?')->execute([$slug,(int)$workspace['id'],$userId]);
        $workspace['profile_slug']=$slug;
    }
    return $workspace;
}

/** Artist resources carry this private workspace key; every account remains owner-user scoped. */
function artist_workspace_v181_scope_id(?array $user = null): int
{
    $user ??= current_user();
    if (!$user || !user_has_role('artist', $user) || !artist_workspace_v181_schema_ready()) return 0;
    $pdo = db();
    if (!$pdo) return 0;
    try { return (int)(artist_workspace_v181_for_user($pdo, $user)['id'] ?? 0); } catch (Throwable $e) { return 0; }
}

/** Public read model. Profile callers pass workspaceId; aggregate callers retain legacy behavior. */
function artist_workspace_v181_public_records(string $kind, ?array $viewer = null, int $limit = 250, ?int $workspaceId = null): array
{
    $tables = ['tracks'=>'artist_catalog_tracks_v181','albums'=>'artist_catalog_albums_v181','shows'=>'artist_catalog_shows_v181','photos'=>'artist_catalog_photos_v181','merch'=>'artist_catalog_merch_v181','posts'=>'artist_posts_v181'];
    $table = $tables[$kind] ?? null; $pdo = db();
    if (!$table || !$pdo || !artist_workspace_v181_schema_ready($pdo)) return [];
    try {
        $order = $kind === 'shows' ? 'show_date ASC,id ASC' : 'updated_at DESC,id DESC';
        $limit=max(1,min(500,$limit));
        if ($workspaceId !== null && $workspaceId > 0) {
            $stmt=$pdo->prepare("SELECT * FROM {$table} WHERE is_published=1 AND workspace_id=? ORDER BY {$order} LIMIT {$limit}");
            $stmt->execute([$workspaceId]);
        } else {
            $stmt=$pdo->query("SELECT * FROM {$table} WHERE is_published=1 ORDER BY {$order} LIMIT {$limit}");
        }
        $rows = $stmt->fetchAll() ?: [];
        if ($kind === 'shows') return $rows;
        return array_values(array_filter($rows, static fn(array $row): bool => can_view_visibility((string)($row['visibility'] ?? 'members'), $viewer)));
    } catch (Throwable $e) { return []; }
}

/** A private per-account library of public artist items. Saved references never expose drafts. */
function artist_workspace_v181_saved_records(string $kind, array $user, int $limit = 120): array
{
    $map=['shows'=>['artist_workspace_saved_shows_v181','artist_show_id','artist_catalog_shows_v181'],'photos'=>['artist_workspace_saved_photos_v181','artist_photo_id','artist_catalog_photos_v181']];
    if (!isset($map[$kind]) || empty($user['id'])) return [];
    [$savedTable,$foreignKey,$catalogTable]=$map[$kind]; $pdo=db();
    if (!$pdo || !artist_workspace_v181_schema_ready($pdo)) return [];
    try {
        $limit=max(1,min(500,$limit));
        $stmt=$pdo->prepare("SELECT c.*,s.created_at AS saved_at FROM {$savedTable} s INNER JOIN {$catalogTable} c ON c.id=s.{$foreignKey} WHERE s.user_id=? AND c.is_published=1 ORDER BY s.created_at DESC LIMIT {$limit}");
        $stmt->execute([(int)$user['id']]); $rows=$stmt->fetchAll() ?: [];
        return $kind==='shows' ? $rows : array_values(array_filter($rows, static fn(array $row): bool => can_view_visibility((string)($row['visibility'] ?? 'members'), $user)));
    } catch (Throwable $e) { return []; }
}

function artist_workspace_v181_toggle_saved(string $kind, int $itemId, array $user): bool
{
    $map=['shows'=>['artist_workspace_saved_shows_v181','artist_show_id','artist_catalog_shows_v181'],'photos'=>['artist_workspace_saved_photos_v181','artist_photo_id','artist_catalog_photos_v181']];
    if (!isset($map[$kind]) || $itemId<1 || empty($user['id'])) throw new RuntimeException('A signed-in account is required.');
    [$savedTable,$foreignKey,$catalogTable]=$map[$kind]; $pdo=db();
    if (!$pdo || !artist_workspace_v181_schema_ready($pdo)) throw new RuntimeException('The artist library is not ready. Run the database upgrade.');
    $stmt=$pdo->prepare("SELECT * FROM {$catalogTable} WHERE id=? AND is_published=1 LIMIT 1"); $stmt->execute([$itemId]); $row=$stmt->fetch();
    if (!$row || ($kind!=='shows' && !can_view_visibility((string)($row['visibility']??'members'),$user))) throw new RuntimeException('This item is not available to your account.');
    $exists=$pdo->prepare("SELECT 1 FROM {$savedTable} WHERE user_id=? AND {$foreignKey}=? LIMIT 1"); $exists->execute([(int)$user['id'],$itemId]);
    if ($exists->fetchColumn()) { $pdo->prepare("DELETE FROM {$savedTable} WHERE user_id=? AND {$foreignKey}=?")->execute([(int)$user['id'],$itemId]); return false; }
    $pdo->prepare("INSERT INTO {$savedTable} (user_id,{$foreignKey}) VALUES (?,?)")->execute([(int)$user['id'],$itemId]); return true;
}

/** Artists must use the private workspace, never the shared Stonefellow editors. */
function artist_workspace_v181_guard_legacy_admin(string $collection): void
{
    $user = current_user();
    if ($user && user_has_role('artist', $user) && artist_workspace_v181_schema_ready()) redirect(url('/admin/artist.php?collection=' . rawurlencode($collection)));
}

function artist_workspace_v181_migrate_legacy(PDO $pdo, array $user): void
{
    $workspace=artist_workspace_v181_for_user($pdo,$user); $wid=(int)$workspace['id']; $uid=(int)$user['id'];
    if (table_exists('tracks')) $pdo->prepare("INSERT IGNORE INTO artist_catalog_tracks_v181 (workspace_id,source_track_id,title,album,audio_path,cover_path,visibility,is_published) SELECT ?,id,title,COALESCE(album,''),COALESCE(audio_path,''),COALESCE(cover_path,''),COALESCE(visibility,'members'),COALESCE(is_published,0) FROM tracks WHERE owner_user_id=? OR owner_user_id IS NULL")->execute([$wid,$uid]);
    if (table_exists('photos')) $pdo->prepare("INSERT IGNORE INTO artist_catalog_photos_v181 (workspace_id,source_photo_id,title,image_path,visibility,is_published) SELECT ?,id,title,image_path,COALESCE(visibility,'members'),COALESCE(is_published,0) FROM photos WHERE created_by_user_id=? OR created_by_user_id IS NULL")->execute([$wid,$uid]);
    if (table_exists('merch_items')) $pdo->prepare("INSERT IGNORE INTO artist_catalog_merch_v181 (workspace_id,source_merch_id,title,description,price_cents,image_path,product_url,visibility,is_published) SELECT ?,id,title,description,price_cents,COALESCE(image_path,''),COALESCE(product_url,''),COALESCE(visibility,'members'),COALESCE(is_published,0) FROM merch_items WHERE created_by_user_id=? OR created_by_user_id IS NULL")->execute([$wid,$uid]);
    if (table_exists('shows')) $pdo->prepare("INSERT IGNORE INTO artist_catalog_shows_v181 (workspace_id,source_show_id,show_date,venue,city,region,notes,ticket_url,is_published) SELECT ?,id,show_date,venue,COALESCE(city,''),COALESCE(region,''),COALESCE(notes,''),COALESCE(ticket_url,''),COALESCE(is_published,0) FROM shows WHERE owner_user_id=? OR owner_user_id IS NULL")->execute([$wid,$uid]);
    if (table_exists('albums')) $pdo->prepare("INSERT IGNORE INTO artist_catalog_albums_v181 (workspace_id,source_album_id,title,release_date,description,cover_path,visibility,is_published) SELECT ?,id,title,release_date,description,COALESCE(cover_path,''),COALESCE(visibility,'members'),COALESCE(is_published,0) FROM albums WHERE created_by_user_id=? OR created_by_user_id IS NULL")->execute([$wid,$uid]);
    if (table_exists('artist_posts')) $pdo->prepare("INSERT IGNORE INTO artist_posts_v181 (workspace_id,source_post_id,title,body,post_type,image_path,media_url,visibility,is_published,published_at) SELECT ?,id,title,body,post_type,COALESCE(image_path,''),COALESCE(media_url,''),COALESCE(visibility,'members'),COALESCE(is_published,0),published_at FROM artist_posts WHERE created_by_user_id=? OR created_by_user_id IS NULL")->execute([$wid,$uid]);
    if (table_exists('release_plans')) $pdo->prepare("INSERT IGNORE INTO artist_release_plans_v181 (workspace_id,source_release_id,title,release_type,status,priority,target_date,agent_goal,notes) SELECT ?,id,title,release_type,status,priority,target_date,agent_goal,notes FROM release_plans WHERE owner_user_id=?")->execute([$wid,$uid]);
    if (table_exists('chat_conversations') && column_exists('chat_conversations', 'artist_workspace_id')) $pdo->prepare('UPDATE chat_conversations SET artist_workspace_id=? WHERE user_id=? AND artist_workspace_id IS NULL')->execute([$wid,$uid]);
    if (table_exists('playlists') && column_exists('playlists', 'artist_workspace_id')) $pdo->prepare('UPDATE playlists SET artist_workspace_id=? WHERE owner_user_id=? AND artist_workspace_id IS NULL')->execute([$wid,$uid]);
}
