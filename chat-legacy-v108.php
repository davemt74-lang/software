<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_permission('chat.access');

if (!access_schema_ready()) {
    redirect(url('/upgrade.php'));
}

$user = current_user();
$chatCanEditStems = has_permission('tracks.manage', $user) || has_permission('producer.access', $user);
agent_brain_ensure_user_soul($user);
if (agent_brain_schema_ready()) {
    $brainSummary = agent_brain_summary($user);
    if ((int)$brainSummary['archive_count'] === 0) {
        agent_brain_backfill_user($user, 500);
    }
}
$teamChatEnabled = team_chat_role_allowed($user);
$pdo = db();
$recent = [];
$chatInitialConversationId = 0;
$chatIntro = agent_chat_v101_intro($user);
$chatNotificationCount = notification_unread_count($user);
$chatNotifications = notification_recent($user, 6);
$chatTracks = get_tracks();
$chatShows = get_upcoming_shows();
$chatPhotos = [];
$chatMerch = [];
$chatAlbums = [];
$chatPlaylists = [];
$chatNewestTracks = [];
$chatPopularTracks = [];
$chatFavoriteTracks = [];
$chatFavoriteTrackIds = [];
$chatFavoriteAlbumIds = [];
$chatFavoritePlaylistIds = [];
$chatShowReminderIds = [];
$chatRecentHistory = [];
$chatForYou = [];
$chatUserStats = [];
$chatPosts = [];
$chatCreateItems = [];
$chatCanAccessAccount = has_permission('account.access', $user);
$chatCanCreatePlaylist = permission_v105_has('playlists.manage', $user);
$chatArtistProfileUrl = artist_workspace_v181_profile_url_for_user($user);

if (has_permission('tracks.manage', $user)) {
    $chatCreateItems[] = [
        'label'=>'Track',
        'meta'=>'Music + Stem Studio',
        'icon'=>'♪',
        'type'=>'track',
        'url'=>url('/admin/tracks.php?new=1#track-form'),
    ];
}

if (has_permission('albums.manage', $user)) {
    $chatCreateItems[] = [
        'label'=>'Album',
        'meta'=>'Album + track assignment',
        'icon'=>'A',
        'type'=>'album',
        'url'=>url('/admin/albums.php?new=1#album-form'),
    ];
}

if (has_permission('shows.manage', $user)) {
    $chatCreateItems[] = [
        'label'=>'Event',
        'meta'=>'Show date + tickets',
        'icon'=>'★',
        'type'=>'event',
        'url'=>url('/admin/shows.php?new=1#show-form'),
    ];
}

if (has_permission('knowledge.manage', $user)) {
    $chatCreateItems[] = [
        'label'=>'Knowledge Base',
        'meta'=>'Document + Agent knowledge',
        'icon'=>'K',
        'type'=>'knowledge',
        'url'=>url('/admin/knowledge.php?new=1#knowledge-form'),
    ];
}

if (has_permission('users.manage', $user)) {
    $chatCreateItems[] = [
        'label'=>'User',
        'meta'=>'Account + role',
        'icon'=>'U',
        'type'=>'user',
        'url'=>url('/admin/users.php?new=1#user-form'),
    ];
}

if ($chatCanCreatePlaylist) {
    $chatCreateItems[] = [
        'label'=>'Playlist',
        'meta'=>'Your tracks + listening order',
        'icon'=>'P',
        'type'=>'playlist',
        'url'=>'',
    ];
}

if (has_permission('merch.manage', $user)) {
    $chatCreateItems[] = [
        'label'=>'Merch',
        'meta'=>'Product + purchase link',
        'icon'=>'M',
        'type'=>'merch',
        'url'=>url('/admin/merch.php?new=1#merch-form'),
    ];
}

if (has_permission('posts.manage', $user)) {
    $chatCreateItems[] = [
        'label'=>'Post',
        'meta'=>'Artist update + media',
        'icon'=>'N',
        'type'=>'post',
        'url'=>url('/admin/posts.php?new=1#post-form'),
    ];
}

if (has_permission('photos.manage', $user)) {
    $chatCreateItems[] = [
        'label'=>'Photo',
        'meta'=>'Image + caption',
        'icon'=>'▣',
        'type'=>'photo',
        'url'=>url('/admin/photos.php?new=1#photo-form'),
    ];
}

if ($pdo) {
    $chatTrackMap = [];

    foreach ($chatTracks as $track) {
        $chatTrackMap[(int)$track['id']] = $track;
    }

    player_process_show_reminders($user);

    $chatRecentHistory = player_recent_history(
        $user,
        $chatTrackMap,
        10
    );

    $chatForYou = player_for_you(
        $user,
        $chatTrackMap,
        8
    );

    $chatUserStats = player_user_stats(
        $user,
        $chatTrackMap
    );

    $chatPosts = player_visible_posts(
        $user,
        12
    );

    try {
        if ($chatTrackMap) {
            $trackIds = array_keys($chatTrackMap);
            $placeholders = implode(',', array_fill(0, count($trackIds), '?'));

            $newestStmt = $pdo->prepare(
                "SELECT id
                 FROM tracks
                 WHERE id IN ({$placeholders})
                 ORDER BY created_at DESC,id DESC
                 LIMIT 6"
            );
            $newestStmt->execute($trackIds);

            foreach ($newestStmt->fetchAll(PDO::FETCH_COLUMN) as $trackId) {
                $trackId = (int)$trackId;

                if (isset($chatTrackMap[$trackId])) {
                    $chatNewestTracks[] = $chatTrackMap[$trackId];
                }
            }

            $popularStmt = $pdo->prepare(
                "SELECT
                    track_id,
                    COUNT(*) AS play_count,
                    SUM(qualified_play) AS qualified_count,
                    SUM(listened_seconds) AS listened_seconds
                 FROM track_play_sessions
                 WHERE track_id IN ({$placeholders})
                 GROUP BY track_id
                 ORDER BY
                    qualified_count DESC,
                    play_count DESC,
                    listened_seconds DESC,
                    track_id DESC
                 LIMIT 12"
            );
            $popularStmt->execute($trackIds);

            foreach ($popularStmt->fetchAll() as $row) {
                $trackId = (int)$row['track_id'];

                if (!isset($chatTrackMap[$trackId])) {
                    continue;
                }

                $track = $chatTrackMap[$trackId];
                $track['play_count'] = (int)$row['play_count'];
                $track['qualified_count'] = (int)$row['qualified_count'];
                $chatPopularTracks[] = $track;
            }

            if (!$chatPopularTracks) {
                $chatPopularTracks = array_slice($chatNewestTracks ?: $chatTracks, 0, 8);
            }
        }
    } catch (Throwable $e) {
        $chatNewestTracks = array_slice(array_reverse($chatTracks), 0, 6);
        $chatPopularTracks = array_slice($chatTracks, 0, 8);
    }

    try {
        if (table_exists('track_favorites')) {
            $favoriteStmt = $pdo->prepare(
                'SELECT track_id
                 FROM track_favorites
                 WHERE user_id=?
                 ORDER BY created_at DESC'
            );
            $favoriteStmt->execute([(int)$user['id']]);

            foreach ($favoriteStmt->fetchAll(PDO::FETCH_COLUMN) as $trackId) {
                $trackId = (int)$trackId;

                if (!isset($chatTrackMap[$trackId])) {
                    continue;
                }

                $chatFavoriteTrackIds[$trackId] = true;
                $chatFavoriteTracks[] = $chatTrackMap[$trackId];
            }
        }
        if (table_exists('artist_workspace_track_favorites_v181')) {
            $favoriteStmt=$pdo->prepare('SELECT artist_track_id FROM artist_workspace_track_favorites_v181 WHERE user_id=? ORDER BY created_at DESC');
            $favoriteStmt->execute([(int)$user['id']]);
            foreach($favoriteStmt->fetchAll(PDO::FETCH_COLUMN) as $artistTrackId){$trackId=1000000000+(int)$artistTrackId;if(isset($chatTrackMap[$trackId])){$chatFavoriteTrackIds[$trackId]=true;$chatFavoriteTracks[]=$chatTrackMap[$trackId];}}
        }
    } catch (Throwable $e) {}

    if (!$chatNewestTracks) {
        $chatNewestTracks = array_slice(array_reverse($chatTracks), 0, 6);
    }

    try {
        if (table_exists('album_favorites')) {
            $stmt = $pdo->prepare(
                'SELECT album_id
                 FROM album_favorites
                 WHERE user_id=?'
            );
            $stmt->execute([(int)$user['id']]);

            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $albumId) {
                $chatFavoriteAlbumIds[(int)$albumId] = true;
            }
        }

        if (table_exists('playlist_favorites')) {
            $stmt = $pdo->prepare(
                'SELECT playlist_id
                 FROM playlist_favorites
                 WHERE user_id=?'
            );
            $stmt->execute([(int)$user['id']]);

            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $playlistId) {
                $chatFavoritePlaylistIds[(int)$playlistId] = true;
            }
        }

        if (table_exists('show_reminders')) {
            $stmt = $pdo->prepare(
                'SELECT show_id
                 FROM show_reminders
                 WHERE user_id=?'
            );
            $stmt->execute([(int)$user['id']]);

            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $showId) {
                $chatShowReminderIds[(int)$showId] = true;
            }
        }
    } catch (Throwable $e) {}

    try {
        if (artist_workspace_v181_schema_ready($pdo)) {
            foreach (artist_workspace_v181_public_records('albums',$user,80) as $albumItem) {
                $albumTracks=[]; foreach($chatTracks as $track){if(trim((string)($track['album']??''))===trim((string)$albumItem['title']))$albumTracks[]=$track;}
                $albumItem['id']=1000000000+(int)$albumItem['id'];$albumItem['tracks']=$albumTracks;$albumItem['favorite']=false;$chatAlbums[]=$albumItem;
            }
        } elseif (table_exists('albums')) {
            $albumSql =
                'SELECT
                    id,
                    title,
                    release_date,
                    description,
                    cover_path,
                    visibility,
                    is_published
                 FROM albums'
                . (
                    has_permission('albums.manage', $user)
                        ? ''
                        : ' WHERE is_published=1'
                )
                . ' ORDER BY sort_order,title,id';

            $albumStmt = $pdo->query(
                $albumSql
            );

            foreach ($albumStmt->fetchAll() as $albumItem) {
                if (
                    !can_view_visibility(
                        (string)$albumItem['visibility'],
                        $user
                    )
                ) {
                    continue;
                }

                $albumTrackStmt = $pdo->prepare(
                    'SELECT *
                     FROM tracks
                     WHERE album_id=?
                     ORDER BY sort_order,id'
                );
                $albumTrackStmt->execute([
                    (int)$albumItem['id']
                ]);

                $albumTracks = [];

                foreach ($albumTrackStmt->fetchAll() as $albumTrack) {
                    if (can_view_track($albumTrack, $user)) {
                        $albumTracks[] = $albumTrack;
                    }
                }

                $albumItem['tracks'] = $albumTracks;
                $albumItem['favorite'] =
                    isset(
                        $chatFavoriteAlbumIds[
                            (int)$albumItem['id']
                        ]
                    );
                $chatAlbums[] = $albumItem;
            }
        }
    } catch (Throwable $e) {}

    try {
        if (
            table_exists('playlists') &&
            table_exists('playlist_tracks')
        ) {
            $playlistStmt = $pdo->prepare(
                'SELECT
                    p.id,
                    p.owner_user_id,
                    p.title,
                    p.description,
                    p.visibility,
                    p.updated_at,
                    u.display_name AS owner_name
                 FROM playlists p
                 INNER JOIN users u ON u.id=p.owner_user_id
                 WHERE p.owner_user_id=? OR p.visibility="public"
                 ORDER BY
                    (p.owner_user_id=?) DESC,
                    p.updated_at DESC,
                    p.id DESC
                 LIMIT 80'
            );
            $playlistStmt->execute([
                (int)$user['id'],
                (int)$user['id'],
            ]);

            foreach ($playlistStmt->fetchAll() as $playlist) {
                $trackStmt = $pdo->prepare(
                    'SELECT t.*
                     FROM playlist_tracks pt
                     INNER JOIN tracks t
                       ON t.id=pt.track_id
                     WHERE pt.playlist_id=?
                     ORDER BY pt.sort_order,pt.added_at,t.id'
                );
                $trackStmt->execute([
                    (int)$playlist['id']
                ]);

                $playlistTracks = [];

                foreach ($trackStmt->fetchAll() as $playlistTrack) {
                    if (can_view_track($playlistTrack, $user)) {
                        $playlistTracks[] = $playlistTrack;
                    }
                }

                if (table_exists('artist_workspace_playlist_tracks_v181')) {
                    $artistTrackStmt=$pdo->prepare('SELECT t.* FROM artist_workspace_playlist_tracks_v181 pt INNER JOIN artist_catalog_tracks_v181 t ON t.id=pt.artist_track_id WHERE pt.playlist_id=? ORDER BY pt.sort_order,pt.added_at,t.id');
                    $artistTrackStmt->execute([(int)$playlist['id']]);
                    foreach($artistTrackStmt->fetchAll() as $artistTrack){$artistTrack['id']=1000000000+(int)$artistTrack['id'];if(can_view_visibility((string)$artistTrack['visibility'],$user))$playlistTracks[]=$artistTrack;}
                }

                $playlist['tracks'] = $playlistTracks;
                $playlist['owned'] =
                    (int)$playlist['owner_user_id'] ===
                    (int)$user['id'];
                $playlist['favorite'] =
                    isset(
                        $chatFavoritePlaylistIds[
                            (int)$playlist['id']
                        ]
                    );

                $chatPlaylists[] = $playlist;
            }
        }
    } catch (Throwable $e) {}

    try {
        if (artist_workspace_v181_schema_ready($pdo)) {
            foreach(artist_workspace_v181_public_records('merch',$user,60) as $merchItem){$merchItem['id']=1000000000+(int)$merchItem['id'];$chatMerch[]=$merchItem;}
        } elseif (table_exists('merch_items')) {
            $merchStmt = $pdo->query(
                'SELECT
                    id,
                    title,
                    description,
                    price_cents,
                    product_url,
                    image_path,
                    album_id,
                    track_id,
                    visibility
                 FROM merch_items
                 WHERE is_published=1
                 ORDER BY sort_order,id DESC
                 LIMIT 60'
            );

            foreach ($merchStmt->fetchAll() as $merchItem) {
                if (
                    !can_view_visibility(
                        (string)$merchItem['visibility'],
                        $user
                    )
                ) {
                    continue;
                }

                $chatMerch[] = $merchItem;
            }
        }
    } catch (Throwable $e) {}

    try {
        if (artist_workspace_v181_schema_ready($pdo)) {
            foreach(artist_workspace_v181_public_records('photos',$user,60) as $photo){$chatPhotos[]=['src'=>url('/content-image.php?type=artist_photo&id='.(int)$photo['id']),'title'=>(string)$photo['title'],'caption'=>'','alt'=>(string)$photo['title']];}
        } elseif (table_exists('photos')) {
            $photoStmt = $pdo->query(
                'SELECT id,title,caption,alt_text,visibility
                 FROM photos
                 WHERE is_published=1
                 ORDER BY sort_order,id DESC
                 LIMIT 60'
            );

            foreach ($photoStmt->fetchAll() as $photo) {
                if (!can_view_visibility((string)$photo['visibility'], $user)) {
                    continue;
                }

                $chatPhotos[] = [
                    'src'=>url('/content-image.php?type=photo&id='.(int)$photo['id']),
                    'title'=>(string)$photo['title'],
                    'caption'=>(string)$photo['caption'],
                    'alt'=>(string)$photo['alt_text'],
                ];
            }
        }
    } catch (Throwable $e) {}

    if (!$chatPhotos) {
        $chatPhotos[] = [
            'src'=>url('/images/stonefellow-studio.png'),
            'title'=>'Stonefellow Studio',
            'caption'=>'Inside the Stonefellow recording workspace.',
            'alt'=>'Stonefellow studio',
        ];

        foreach ($chatTracks as $chatPhotoTrack) {
            $chatPhotoId = (int)($chatPhotoTrack['id'] ?? 0);

            if ($chatPhotoId < 1) {
                continue;
            }

            $chatPhotos[] = [
                'src'=>url('/media.php?track=' . $chatPhotoId . '&type=cover'),
                'title'=>(string)($chatPhotoTrack['title'] ?? 'Stonefellow'),
                'caption'=>
                    trim((string)($chatPhotoTrack['album'] ?? '')) !== ''
                        ? (string)$chatPhotoTrack['album']
                        : 'Track artwork',
                'alt'=>'',
            ];
        }
    }

    $stmt = $pdo->prepare(
        'SELECT c.id,c.title,c.updated_at,COALESCE(MAX(m.id),0) AS latest_message_id
         FROM chat_conversations c
         LEFT JOIN chat_messages m ON m.conversation_id=c.id
         WHERE c.user_id=?
         GROUP BY c.id
         ORDER BY latest_message_id DESC,c.updated_at DESC,c.id DESC LIMIT 40'
    );
    $stmt->execute([(int)$user['id']]);
    $recent = $stmt->fetchAll();

    // v101: message IDs provide a deterministic activity order even when
    // several messages share the same second-level timestamp.
    $chatInitialConversationId = agent_chat_v101_latest_conversation_id($pdo,(int)$user['id']);
}
$playerTracksPayload = [];

foreach ($chatTracks as $track) {
    $trackId = (int)$track['id'];

    $playerTracksPayload[$trackId] = [
        'id'=>$trackId,
        'title'=>(string)$track['title'],
        'album'=>(string)($track['album'] ?: 'Stonefellow'),
        'album_id'=>(int)($track['album_id'] ?? 0),
        'duration'=>(string)($track['duration'] ?? ''),
        'description'=>(string)($track['description'] ?? ''),
        'credits'=>(string)($track['credits'] ?? ''),
        'genre'=>(string)($track['genre'] ?? ''),
        'mood'=>(string)($track['mood'] ?? ''),
        'energy'=>(string)($track['energy'] ?? ''),
        'tempo_bpm'=>(int)($track['tempo_bpm'] ?? 0),
        'lyrics'=>(string)($track['lyrics'] ?? ''),
        'credits'=>(string)($track['credits'] ?? ''),
        'favorite'=>isset($chatFavoriteTrackIds[$trackId]),
        'cover'=>url('/media.php?track='.$trackId.'&type=cover'),
        'audio'=>url('/media.php?track='.$trackId.'&type=audio'),
        'detail'=>url('/track.php?id='.$trackId),
        'studio'=>$chatCanEditStems
            ? url('/admin/stems.php?track='.$trackId)
            : '',
    ];
}

$playerAlbumsPayload = [];

foreach ($chatAlbums as $album) {
    $albumId = (int)$album['id'];

    $playerAlbumsPayload[$albumId] = [
        'id'=>$albumId,
        'title'=>(string)$album['title'],
        'release_date'=>(string)$album['release_date'],
        'description'=>(string)$album['description'],
        'favorite'=>(bool)($album['favorite'] ?? false),
        'cover'=>trim((string)$album['cover_path']) !== ''
            ? url('/content-image.php?type=album&id='.$albumId)
            : '',
        'track_ids'=>array_values(
            array_map(
                static fn(array $track): int => (int)$track['id'],
                (array)$album['tracks']
            )
        ),
    ];
}

$playerPlaylistsPayload = [];

foreach ($chatPlaylists as $playlist) {
    $playlistId = (int)$playlist['id'];

    $playerPlaylistsPayload[$playlistId] = [
        'id'=>$playlistId,
        'title'=>(string)$playlist['title'],
        'description'=>(string)$playlist['description'],
        'visibility'=>(string)$playlist['visibility'],
        'owner_user_id'=>(int)$playlist['owner_user_id'],
        'owner_name'=>(string)$playlist['owner_name'],
        'owned'=>(bool)$playlist['owned'],
        'favorite'=>(bool)$playlist['favorite'],
        'track_ids'=>array_values(
            array_map(
                static fn(array $track): int => (int)$track['id'],
                (array)$playlist['tracks']
            )
        ),
    ];
}

$playerMerchPayload = [];

foreach ($chatMerch as $merch) {
    $merchId = (int)$merch['id'];

    $playerMerchPayload[$merchId] = [
        'id'=>$merchId,
        'title'=>(string)$merch['title'],
        'description'=>(string)$merch['description'],
        'price'=>number_format(((int)$merch['price_cents'])/100, 2, '.', ''),
        'product_url'=>(string)$merch['product_url'],
        'album_id'=>(int)($merch['album_id'] ?? 0),
        'track_id'=>(int)($merch['track_id'] ?? 0),
        'image'=>trim((string)$merch['image_path']) !== ''
            ? url('/content-image.php?type=merch&id='.$merchId)
            : '',
    ];
}

$playerPostsPayload = [];

foreach ($chatPosts as $post) {
    $postId = (int)$post['id'];

    $playerPostsPayload[$postId] = [
        'id'=>$postId,
        'title'=>(string)$post['title'],
        'body'=>(string)$post['body'],
        'post_type'=>(string)$post['post_type'],
        'media_url'=>(string)$post['media_url'],
        'published_at'=>(string)($post['published_at'] ?: $post['created_at']),
        'image'=>trim((string)$post['image_path']) !== ''
            ? url('/content-image.php?type=post&id='.$postId)
            : '',
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0b0a09">
<title>Stonefellow Chat</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=205')) ?>">
<link rel="stylesheet" href="<?= e(url('/chat-media-v86.css?v=86')) ?>">
<link rel="stylesheet" href="<?= e(url('/chat-media-v91.css?v=95')) ?>">
<link rel="stylesheet" href="<?= e(url('/chat-media-v93.css?v=93')) ?>">
<link rel="stylesheet" href="<?= e(url('/agent-proactive-v93.css?v=93')) ?>">
<link rel="stylesheet" href="<?= e(url('/chat-v97.css?v=99')) ?>">
</head>
<body>
<div class="chat-app">
  <aside class="chat-sidebar" id="chatSidebar">
    <div class="chat-sidebar-top">
      <a class="chat-brand" href="<?= e(url('/chat.php')) ?>">Stonefellow</a>
      <button class="chat-icon-button mobile-only" id="closeChatSidebar" type="button" aria-label="Close chats">×</button>
    </div>

    <div class="chat-sidebar-sections">
      <section class="chat-sidebar-nav-section" aria-label="Stonefellow workspace">
        <div class="chat-history-label">Explore</div>

        <nav class="chat-sidebar-nav">
          <button
            class="chat-sidebar-nav-link active"
            id="newChatButton"
            type="button"
            data-chat-view-target="chat"
          >
            <span>＋</span>
            <strong>New Chat</strong>
          </button>

          <button
            class="chat-sidebar-nav-link"
            type="button"
            data-chat-view-target="player"
          >
            <span>▶</span>
            <strong>Player</strong>
          </button>

          <button
            class="chat-sidebar-nav-link"
            id="chatSavedSongsNav"
            type="button"
            data-chat-view-target="saved"
            <?= $chatFavoriteTracks ? '' : 'hidden' ?>
          >
            <span>♥</span>
            <strong>Saved Songs</strong>
          </button>

          <button
            class="chat-sidebar-nav-link"
            type="button"
            data-chat-view-target="playlists"
          >
            <span>P</span>
            <strong>Playlists</strong>
          </button>

          <button
            class="chat-sidebar-nav-link"
            type="button"
            data-chat-view-target="shows"
          >
            <span>★</span>
            <strong>Shows</strong>
          </button>

          <button
            class="chat-sidebar-nav-link"
            type="button"
            data-chat-view-target="photos"
          >
            <span>▣</span>
            <strong>Photos</strong>
          </button>

          <button
            class="chat-sidebar-nav-link"
            type="button"
            data-chat-view-target="merch"
          >
            <span>M</span>
            <strong>Merch</strong>
          </button>

        </nav>
      </section>

      <section class="chat-sidebar-history-section">
        <div class="chat-history-label">Chats</div>
        <nav class="chat-history" id="chatHistory">
          <?php foreach ($recent as $conversation): ?>
            <div class="chat-history-row" data-conversation-row="<?= (int)$conversation['id'] ?>">
              <button class="chat-history-item" type="button" data-conversation-id="<?= (int)$conversation['id'] ?>">
                <span><?= e($conversation['title']) ?></span>
                <small><?= e(date('M j', strtotime((string)$conversation['updated_at']))) ?></small>
              </button>
              <button class="chat-history-delete" type="button" data-delete-conversation="<?= (int)$conversation['id'] ?>" aria-label="Delete <?= e($conversation['title']) ?>" title="Delete chat">×</button>
            </div>
          <?php endforeach; ?>
        </nav>
      </section>
    </div>
  </aside>

  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>

  <main class="chat-main">
    <header class="chat-topbar">
      <button class="chat-icon-button mobile-only" id="openChatSidebar" type="button" aria-label="Open chats">☰</button>
      <div class="chat-topbar-spacer"></div>
      <div class="chat-topbar-actions">
        <?php if ($chatCreateItems): ?>
          <div class="chat-top-menu" id="chatCreateMenu">
            <button
              class="chat-create-button"
              id="chatCreateButton"
              type="button"
              aria-label="Create content"
              aria-expanded="false"
              aria-controls="chatCreateDropdown"
            >+</button>

            <div
              class="chat-top-dropdown chat-create-dropdown"
              id="chatCreateDropdown"
              hidden
            >
              <header>
                <strong>Create</strong>
                <span>Add Stonefellow content</span>
              </header>

              <nav class="chat-create-links">
                <?php foreach ($chatCreateItems as $createItem): ?>
                  <button
                    type="button"
                    data-chat-create-type="<?= e((string)$createItem['type']) ?>"
                    data-chat-create-admin-url="<?= e((string)$createItem['url']) ?>"
                  >
                    <span class="chat-create-icon"><?= e((string)$createItem['icon']) ?></span>
                    <span>
                      <strong><?= e((string)$createItem['label']) ?></strong>
                      <small><?= e((string)$createItem['meta']) ?></small>
                    </span>
                    <span>＋</span>
                  </button>
                <?php endforeach; ?>
              </nav>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($chatCanAccessAccount): ?>
        <div class="chat-top-menu" id="chatNotificationMenu">
          <button
            class="chat-notification-link"
            id="chatNotificationButton"
            type="button"
            aria-label="Notifications"
            aria-expanded="false"
            aria-controls="chatNotificationDropdown"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
            <span
              id="chatNotificationCount"
              <?= $chatNotificationCount > 0 ? '' : 'hidden' ?>
            ><?= $chatNotificationCount > 99 ? '99+' : (int)$chatNotificationCount ?></span>
          </button>

          <div
            class="chat-top-dropdown chat-notification-dropdown"
            id="chatNotificationDropdown"
            hidden
          >
            <header>
              <strong>Notifications</strong>
              <span id="chatNotificationUnreadLabel"><?= (int)$chatNotificationCount ?> unread</span>
            </header>

            <div class="chat-notification-dropdown-list">
              <?php foreach ($chatNotifications as $notification): ?>
                <a
                  class="<?= !(int)$notification['is_read'] ? 'unread' : '' ?>"
                  href="<?= e(url('/notifications.php?open=' . (int)$notification['id'])) ?>"
                >
                  <span class="chat-dropdown-dot"></span>
                  <span>
                    <strong><?= e((string)$notification['title']) ?></strong>
                    <small><?= e((string)$notification['body']) ?></small>
                  </span>
                </a>
              <?php endforeach; ?>

              <?php if (!$chatNotifications): ?>
                <div class="chat-dropdown-empty">No notifications yet.</div>
              <?php endif; ?>
            </div>

            <a class="chat-dropdown-all" href="<?= e(url('/notifications.php')) ?>">
              View all notifications →
            </a>
          </div>
        </div>
        <?php endif; ?>

        <div class="chat-top-menu" id="chatProfileMenu">
          <button
            type="button"
            class="chat-top-avatar"
            id="chatProfileButton"
            aria-label="User menu"
            aria-expanded="false"
            aria-controls="chatProfileDropdown"
          >
            <?php if (user_avatar_url($user) !== ''): ?>
              <img src="<?= e(user_avatar_url($user)) ?>" alt="">
            <?php else: ?>
              <?= e(user_initials($user)) ?>
            <?php endif; ?>
          </button>

          <div
            class="chat-top-dropdown chat-profile-dropdown"
            id="chatProfileDropdown"
            hidden
          >
            <div class="chat-profile-summary">
              <span class="chat-avatar">
                <?php if (user_avatar_url($user) !== ''): ?>
                  <img src="<?= e(user_avatar_url($user)) ?>" alt="">
                <?php else: ?>
                  <span><?= e(user_initials($user)) ?></span>
                <?php endif; ?>
              </span>
              <div>
                <strong><?= e((string)$user['display_name']) ?></strong>
                <small><?= e(role_label((string)$user['role'])) ?></small>
              </div>
            </div>

            <nav class="chat-profile-links">
              <?php if ($chatCanAccessAccount): ?><a href="<?= e(url('/account.php')) ?>"><span>My Account</span><span>↗</span></a><?php endif; ?>
              <?php if ($chatArtistProfileUrl !== ''): ?><a href="<?= e($chatArtistProfileUrl) ?>"><span>View Artist Profile</span><span>↗</span></a><?php endif; ?>
              <?php if (has_permission('admin.access')): ?>
                <a href="<?= e(url('/admin/index.php')) ?>"><span>Admin Dashboard</span><span>↗</span></a>
              <?php endif; ?>
              <a class="logout" href="<?= e(url('/logout.php')) ?>"><span>Log Out</span><span>↗</span></a>
            </nav>
          </div>
        </div>
      </div>
    </header>

    <section class="chat-thread" id="chatThread">
      <section
        class="chat-live-updates"
        id="chatLiveUpdates"
        aria-live="polite"
        hidden
      >
        <header>
          <div>
            <span class="chat-live-dot" aria-hidden="true"></span>
            <strong>Agent Updates</strong>
          </div>
          <small id="chatLiveStatus">Live</small>
        </header>
        <div class="chat-live-update-list" id="chatLiveUpdateList"></div>
      </section>

      <section
        class="chat-canvas-view chat-player-canvas"
        data-chat-view="player"
        hidden
      >
        <header class="chat-player-page-head">
          <div>
            <span>Stonefellow</span>
            <h1>Player</h1>
            <p>Your signed-in music home: newest releases, albums, popular tracks and favorites.</p>
          </div>

          <label class="chat-player-search">
            <span>Search</span>
            <input
              id="chatPlayerSearch"
              type="search"
              placeholder="Search tracks"
              autocomplete="off"
            >
          </label>
        </header>

        <section class="chat-player-hero" aria-label="Newest tracks" data-player-queue="newest">
          <?php if ($chatNewestTracks): ?>
            <?php $heroTrack = $chatNewestTracks[0]; $heroTrackId = (int)$heroTrack['id']; ?>
            <article class="chat-player-feature">
              <img
                class="chat-player-feature-art"
                src="<?= e(url('/media.php?track='.$heroTrackId.'&type=cover')) ?>"
                alt=""
              >

              <div class="chat-player-feature-overlay"></div>

              <div class="chat-player-feature-copy">
                <span>Newest Track</span>
                <h2><?= e((string)$heroTrack['title']) ?></h2>
                <p>
                  <?= e(
                    trim((string)($heroTrack['description'] ?? '')) !== ''
                      ? (string)$heroTrack['description']
                      : (string)($heroTrack['album'] ?: 'Stonefellow')
                  ) ?>
                </p>

                <div class="chat-player-feature-actions">
                  <button
                    type="button"
                    class="chat-player-feature-play"
                    data-play-track="<?= $heroTrackId ?>"
                  >▶ Play</button>

                  <button
                    type="button"
                    class="chat-player-favorite <?= isset($chatFavoriteTrackIds[$heroTrackId]) ? 'active' : '' ?>"
                    data-favorite-track="<?= $heroTrackId ?>"
                    aria-pressed="<?= isset($chatFavoriteTrackIds[$heroTrackId]) ? 'true' : 'false' ?>"
                    title="Favorite"
                  ><?= isset($chatFavoriteTrackIds[$heroTrackId]) ? '♥' : '♡' ?></button>
                </div>

                <audio
                  class="chat-audio-player"
                  preload="metadata"
                  data-track-id="<?= $heroTrackId ?>"
                  data-player-title="<?= e((string)$heroTrack['title']) ?>"
                  data-player-album="<?= e((string)($heroTrack['album'] ?: 'Stonefellow')) ?>"
                  data-player-cover="<?= e(url('/media.php?track='.$heroTrackId.'&type=cover')) ?>"
                  data-player-detail="<?= e(url('/track.php?id='.$heroTrackId)) ?>"
                  src="<?= e(url('/media.php?track='.$heroTrackId.'&type=audio')) ?>"
                ></audio>
              </div>
            </article>

            <div class="chat-player-new-grid">
              <?php foreach (array_slice($chatNewestTracks, 1, 4) as $newTrack): ?>
                <?php $newTrackId = (int)$newTrack['id']; ?>
                <article
                  class="chat-player-new-card"
                  data-player-search-text="<?= e(mb_strtolower((string)$newTrack['title'].' '.(string)$newTrack['album'])) ?>"
                >
                  <img
                    src="<?= e(url('/media.php?track='.$newTrackId.'&type=cover')) ?>"
                    alt=""
                  >

                  <div>
                    <span>New Release</span>
                    <strong><?= e((string)$newTrack['title']) ?></strong>
                    <small><?= e((string)($newTrack['album'] ?: 'Stonefellow')) ?></small>
                  </div>

                  <button
                    type="button"
                    class="chat-player-favorite <?= isset($chatFavoriteTrackIds[$newTrackId]) ? 'active' : '' ?>"
                    data-favorite-track="<?= $newTrackId ?>"
                    aria-pressed="<?= isset($chatFavoriteTrackIds[$newTrackId]) ? 'true' : 'false' ?>"
                    title="Favorite"
                  ><?= isset($chatFavoriteTrackIds[$newTrackId]) ? '♥' : '♡' ?></button>

                  <audio
                    class="chat-audio-player"
                    preload="metadata"
                    data-track-id="<?= $newTrackId ?>"
                    data-player-title="<?= e((string)$newTrack['title']) ?>"
                    data-player-album="<?= e((string)($newTrack['album'] ?: 'Stonefellow')) ?>"
                    data-player-cover="<?= e(url('/media.php?track='.$newTrackId.'&type=cover')) ?>"
                    data-player-detail="<?= e(url('/track.php?id='.$newTrackId)) ?>"
                    src="<?= e(url('/media.php?track='.$newTrackId.'&type=audio')) ?>"
                  ></audio>
                </article>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="chat-canvas-empty">No tracks are currently available to this account.</div>
          <?php endif; ?>
        </section>

        <section class="chat-player-section" id="playerRecent" data-player-queue="recent">
          <header>
            <div>
              <span>History</span>
              <h2>Recently Played</h2>
            </div>
            <small><?= count($chatRecentHistory) ?> recent</small>
          </header>

          <div class="chat-player-recent-grid">
            <?php foreach ($chatRecentHistory as $recentTrack): ?>
              <?php
                $recentTrackId = (int)$recentTrack['id'];
                $resumeAt = max(0, (float)($recentTrack['last_position_seconds'] ?? 0));
              ?>
              <article
                class="chat-player-recent-card"
                data-player-search-text="<?= e(mb_strtolower((string)$recentTrack['title'].' '.(string)$recentTrack['album'])) ?>"
              >
                <img
                  src="<?= e(url('/media.php?track='.$recentTrackId.'&type=cover')) ?>"
                  alt=""
                >

                <div>
                  <span>Last played <?= e(date('M j · g:i A', strtotime((string)$recentTrack['last_played_at']))) ?></span>
                  <strong><?= e((string)$recentTrack['title']) ?></strong>
                  <small><?= e((string)($recentTrack['album'] ?: 'Stonefellow')) ?></small>
                </div>

                <button
                  type="button"
                  data-resume-track="<?= $recentTrackId ?>"
                  data-resume-position="<?= e((string)$resumeAt) ?>"
                ><?= $resumeAt > 5 ? 'Continue' : 'Play' ?></button>

                <audio
                  class="chat-audio-player"
                  preload="metadata"
                  data-track-id="<?= $recentTrackId ?>"
                  data-player-title="<?= e((string)$recentTrack['title']) ?>"
                  data-player-album="<?= e((string)($recentTrack['album'] ?: 'Stonefellow')) ?>"
                  data-player-cover="<?= e(url('/media.php?track='.$recentTrackId.'&type=cover')) ?>"
                  data-player-detail="<?= e(url('/track.php?id='.$recentTrackId)) ?>"
                  src="<?= e(url('/media.php?track='.$recentTrackId.'&type=audio')) ?>"
                ></audio>
              </article>
            <?php endforeach; ?>

            <?php if (!$chatRecentHistory): ?>
              <div class="chat-canvas-empty compact">Start listening and your recent tracks will appear here.</div>
            <?php endif; ?>
          </div>
        </section>

        <section class="chat-player-section" id="playerForYou" data-player-queue="for-you">
          <header>
            <div>
              <span>Personalized</span>
              <h2>For You</h2>
            </div>
            <small>Based on favorites, completed listens and repeat plays</small>
          </header>

          <div class="chat-player-for-you-grid">
            <?php foreach ($chatForYou as $forTrack): ?>
              <?php $forTrackId = (int)$forTrack['id']; ?>
              <article
                class="chat-player-for-you-card"
                data-player-search-text="<?= e(mb_strtolower((string)$forTrack['title'].' '.(string)$forTrack['album'].' '.(string)($forTrack['genre'] ?? '').' '.(string)($forTrack['mood'] ?? ''))) ?>"
              >
                <img
                  src="<?= e(url('/media.php?track='.$forTrackId.'&type=cover')) ?>"
                  alt=""
                >

                <div>
                  <strong><?= e((string)$forTrack['title']) ?></strong>
                  <span><?= e((string)($forTrack['album'] ?: 'Stonefellow')) ?></span>
                  <small>
                    <?= e(
                      trim(
                        implode(
                          ' · ',
                          array_filter([
                            (string)($forTrack['genre'] ?? ''),
                            (string)($forTrack['mood'] ?? ''),
                          ])
                        )
                      )
                    ) ?>
                  </small>
                </div>

                <button
                  type="button"
                  class="chat-player-favorite <?= isset($chatFavoriteTrackIds[$forTrackId]) ? 'active' : '' ?>"
                  data-favorite-track="<?= $forTrackId ?>"
                  aria-pressed="<?= isset($chatFavoriteTrackIds[$forTrackId]) ? 'true' : 'false' ?>"
                ><?= isset($chatFavoriteTrackIds[$forTrackId]) ? '♥' : '♡' ?></button>

                <audio
                  class="chat-audio-player"
                  preload="metadata"
                  data-track-id="<?= $forTrackId ?>"
                  data-player-title="<?= e((string)$forTrack['title']) ?>"
                  data-player-album="<?= e((string)($forTrack['album'] ?: 'Stonefellow')) ?>"
                  data-player-cover="<?= e(url('/media.php?track='.$forTrackId.'&type=cover')) ?>"
                  data-player-detail="<?= e(url('/track.php?id='.$forTrackId)) ?>"
                  src="<?= e(url('/media.php?track='.$forTrackId.'&type=audio')) ?>"
                ></audio>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="chat-player-section" id="playerAlbums">
          <header>
            <div>
              <span>Collections</span>
              <h2>Albums</h2>
            </div>

            <?php if (has_permission('albums.manage', $user)): ?>
              <button
                type="button"
                class="chat-canvas-create-shortcut"
                data-chat-create-type="album"
                data-chat-create-admin-url="<?= e(url('/admin/albums.php?new=1#album-form')) ?>"
              >+ Add Album</button>
            <?php endif; ?>
          </header>

          <div class="chat-player-album-strip">
            <?php foreach ($chatAlbums as $album): ?>
              <article
                class="chat-player-album-tile"
                data-album-open="<?= (int)$album['id'] ?>"
              >
                <?php if (trim((string)$album['cover_path']) !== ''): ?>
                  <img
                    src="<?= e(url('/content-image.php?type=album&id='.(int)$album['id'])) ?>"
                    alt=""
                  >
                <?php else: ?>
                  <div class="chat-player-album-placeholder">A</div>
                <?php endif; ?>

                <div class="chat-player-album-tile-copy">
                  <strong><?= e((string)$album['title']) ?></strong>
                  <span>
                    <?= count($album['tracks']) ?>
                    track<?= count($album['tracks']) === 1 ? '' : 's' ?>
                  </span>
                </div>

                <button
                  class="chat-player-favorite <?= !empty($album['favorite']) ? 'active' : '' ?>"
                  type="button"
                  data-album-favorite="<?= (int)$album['id'] ?>"
                  aria-pressed="<?= !empty($album['favorite']) ? 'true' : 'false' ?>"
                  title="Favorite album"
                ><?= !empty($album['favorite']) ? '♥' : '♡' ?></button>
              </article>
            <?php endforeach; ?>

            <?php if (!$chatAlbums): ?>
              <div class="chat-canvas-empty compact">No albums are currently available.</div>
            <?php endif; ?>
          </div>
        </section>

        <section class="chat-player-section" id="playerPopular" data-player-queue="popular">
          <header>
            <div>
              <span>Listening</span>
              <h2>Most Popular</h2>
            </div>
          </header>

          <div class="chat-player-ranked-list">
            <?php foreach ($chatPopularTracks as $rank => $popularTrack): ?>
              <?php $popularTrackId = (int)$popularTrack['id']; ?>
              <article
                class="chat-player-ranked-row"
                data-player-search-text="<?= e(mb_strtolower((string)$popularTrack['title'].' '.(string)$popularTrack['album'])) ?>"
              >
                <span class="chat-player-rank"><?= $rank + 1 ?></span>

                <img
                  src="<?= e(url('/media.php?track='.$popularTrackId.'&type=cover')) ?>"
                  alt=""
                >

                <div class="chat-player-ranked-copy">
                  <strong><?= e((string)$popularTrack['title']) ?></strong>
                  <span><?= e((string)($popularTrack['album'] ?: 'Stonefellow')) ?></span>
                </div>

                <small>
                  <?= isset($popularTrack['qualified_count'])
                    ? (int)$popularTrack['qualified_count'].' qualified'
                    : 'New' ?>
                </small>

                <button
                  type="button"
                  class="chat-player-favorite <?= isset($chatFavoriteTrackIds[$popularTrackId]) ? 'active' : '' ?>"
                  data-favorite-track="<?= $popularTrackId ?>"
                  aria-pressed="<?= isset($chatFavoriteTrackIds[$popularTrackId]) ? 'true' : 'false' ?>"
                  title="Favorite"
                ><?= isset($chatFavoriteTrackIds[$popularTrackId]) ? '♥' : '♡' ?></button>

                <audio
                  class="chat-audio-player"
                  preload="metadata"
                  data-track-id="<?= $popularTrackId ?>"
                  data-player-title="<?= e((string)$popularTrack['title']) ?>"
                  data-player-album="<?= e((string)($popularTrack['album'] ?: 'Stonefellow')) ?>"
                  data-player-cover="<?= e(url('/media.php?track='.$popularTrackId.'&type=cover')) ?>"
                  data-player-detail="<?= e(url('/track.php?id='.$popularTrackId)) ?>"
                  src="<?= e(url('/media.php?track='.$popularTrackId.'&type=audio')) ?>"
                ></audio>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="chat-player-section" id="playerFavorites" data-player-queue="favorites">
          <header>
            <div>
              <span>Your Library</span>
              <h2>Your Favorites</h2>
            </div>
            <small id="chatFavoriteCount"><?= count($chatFavoriteTracks) ?> saved</small>
          </header>

          <div class="chat-player-favorites-grid" id="chatFavoriteGrid">
            <?php foreach ($chatTracks as $favoriteTrack): ?>
              <?php
                $favoriteTrackId = (int)$favoriteTrack['id'];
                $isFavorite = isset($chatFavoriteTrackIds[$favoriteTrackId]);
              ?>
              <article
                class="chat-player-favorite-card"
                data-favorite-card-track="<?= $favoriteTrackId ?>"
                data-player-search-text="<?= e(mb_strtolower((string)$favoriteTrack['title'].' '.(string)$favoriteTrack['album'])) ?>"
                <?= $isFavorite ? '' : 'hidden' ?>
              >
                <img
                  src="<?= e(url('/media.php?track='.$favoriteTrackId.'&type=cover')) ?>"
                  alt=""
                >

                <div>
                  <strong><?= e((string)$favoriteTrack['title']) ?></strong>
                  <span><?= e((string)($favoriteTrack['album'] ?: 'Stonefellow')) ?></span>
                </div>

                <button
                  type="button"
                  class="chat-player-favorite active"
                  data-favorite-track="<?= $favoriteTrackId ?>"
                  aria-pressed="true"
                  title="Remove favorite"
                >♥</button>

                <audio
                  class="chat-audio-player"
                  preload="metadata"
                  data-track-id="<?= $favoriteTrackId ?>"
                  data-player-title="<?= e((string)$favoriteTrack['title']) ?>"
                  data-player-album="<?= e((string)($favoriteTrack['album'] ?: 'Stonefellow')) ?>"
                  data-player-cover="<?= e(url('/media.php?track='.$favoriteTrackId.'&type=cover')) ?>"
                  data-player-detail="<?= e(url('/track.php?id='.$favoriteTrackId)) ?>"
                  src="<?= e(url('/media.php?track='.$favoriteTrackId.'&type=audio')) ?>"
                ></audio>
              </article>
            <?php endforeach; ?>
          </div>

          <div
            class="chat-player-favorites-empty"
            id="chatFavoritesEmpty"
            <?= $chatFavoriteTracks ? 'hidden' : '' ?>
          >
            Favorite tracks with the ♡ button and they will appear here.
          </div>
        </section>

        <section class="chat-player-section" id="playerSavedCollections">
          <header>
            <div>
              <span>Your Library</span>
              <h2>Favorite Albums & Playlists</h2>
            </div>
          </header>

          <div class="chat-player-saved-collections">
            <?php foreach ($chatAlbums as $savedAlbum): ?>
              <article
                class="chat-player-saved-card"
                data-saved-album="<?= (int)$savedAlbum['id'] ?>"
                data-album-open="<?= (int)$savedAlbum['id'] ?>"
                <?= !empty($savedAlbum['favorite']) ? '' : 'hidden' ?>
              >
                <?php if (trim((string)$savedAlbum['cover_path']) !== ''): ?>
                  <img
                    src="<?= e(url('/content-image.php?type=album&id='.(int)$savedAlbum['id'])) ?>"
                    alt=""
                  >
                <?php else: ?>
                  <div class="chat-player-saved-placeholder">A</div>
                <?php endif; ?>
                <div>
                  <span>Album</span>
                  <strong><?= e((string)$savedAlbum['title']) ?></strong>
                </div>
              </article>
            <?php endforeach; ?>

            <?php foreach ($chatPlaylists as $savedPlaylist): ?>
              <article
                class="chat-player-saved-card"
                data-saved-playlist="<?= (int)$savedPlaylist['id'] ?>"
                <?= !empty($savedPlaylist['favorite']) ? '' : 'hidden' ?>
              >
                <div class="chat-player-saved-placeholder">P</div>
                <div>
                  <span>Playlist</span>
                  <strong><?= e((string)$savedPlaylist['title']) ?></strong>
                </div>
                <button
                  type="button"
                  data-play-playlist="<?= (int)$savedPlaylist['id'] ?>"
                >▶</button>
              </article>
            <?php endforeach; ?>
          </div>

          <div
            class="chat-player-saved-empty"
            id="chatSavedCollectionsEmpty"
            <?= ($chatFavoriteAlbumIds || $chatFavoritePlaylistIds) ? 'hidden' : '' ?>
          >
            Favorite an album or playlist and it will appear here.
          </div>
        </section>

        <section class="chat-player-section" id="playerListeningStats">
          <header>
            <div>
              <span>Your Listening</span>
              <h2>Listening Stats</h2>
            </div>
          </header>

          <div class="chat-player-stat-grid">
            <article>
              <strong><?= number_format(((float)($chatUserStats['listening_seconds'] ?? 0))/60,0) ?></strong>
              <span>Minutes listened</span>
            </article>
            <article>
              <strong><?= number_format((int)($chatUserStats['qualified_plays'] ?? 0)) ?></strong>
              <span>10s+ plays</span>
            </article>
            <article>
              <strong><?= number_format((int)($chatUserStats['completed_plays'] ?? 0)) ?></strong>
              <span>Completed tracks</span>
            </article>
            <article>
              <strong><?= number_format((int)($chatUserStats['tracks_played'] ?? 0)) ?></strong>
              <span>Tracks explored</span>
            </article>
            <article>
              <strong><?= number_format((int)($chatUserStats['favorites'] ?? 0)) ?></strong>
              <span>Favorites</span>
            </article>
            <article>
              <strong><?= number_format((int)($chatUserStats['playlists'] ?? 0)) ?></strong>
              <span>Playlists created</span>
            </article>
          </div>

          <div class="chat-player-stat-highlights">
            <div>
              <span>Most Played Track</span>
              <strong><?= e((string)(($chatUserStats['top_track']['title'] ?? '') ?: 'Keep listening')) ?></strong>
            </div>
            <div>
              <span>Most Played Album</span>
              <strong><?= e((string)(($chatUserStats['top_album'] ?? '') ?: 'Keep listening')) ?></strong>
            </div>
          </div>
        </section>

        <section class="chat-player-section" id="playerUpdates">
          <header>
            <div>
              <span>From Stonefellow</span>
              <h2>Updates</h2>
            </div>
            <?php if (has_permission('posts.manage', $user)): ?>
              <button
                type="button"
                class="chat-canvas-create-shortcut"
                data-chat-create-type="post"
                data-chat-create-admin-url="<?= e(url('/admin/posts.php?new=1#post-form')) ?>"
              >+ Add Post</button>
            <?php endif; ?>
          </header>

          <div class="chat-player-post-grid">
            <?php foreach ($chatPosts as $post): ?>
              <article class="chat-player-post-card">
                <?php if (trim((string)$post['image_path']) !== ''): ?>
                  <img
                    src="<?= e(url('/content-image.php?type=post&id='.(int)$post['id'])) ?>"
                    alt=""
                  >
                <?php endif; ?>

                <div>
                  <span>
                    <?= e(ucfirst((string)$post['post_type'])) ?>
                    ·
                    <?= e(date('M j', strtotime((string)($post['published_at'] ?: $post['created_at'])))) ?>
                  </span>
                  <strong><?= e((string)$post['title']) ?></strong>
                  <p><?= e((string)$post['body']) ?></p>

                  <?php if (trim((string)$post['media_url']) !== ''): ?>
                    <a
                      href="<?= e((string)$post['media_url']) ?>"
                      target="_blank"
                      rel="noopener noreferrer"
                    >Open media ↗</a>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>

            <?php if (!$chatPosts): ?>
              <div class="chat-canvas-empty compact">No artist updates have been published yet.</div>
            <?php endif; ?>
          </div>
        </section>

        <section class="chat-player-section" id="playerMerch">
          <header>
            <div>
              <span>Stonefellow Store</span>
              <h2>Merch</h2>
            </div>
          </header>

          <div class="chat-player-merch-grid">
            <?php foreach ($chatMerch as $merch): ?>
              <article
                class="chat-player-merch-card"
                data-merch-album="<?= (int)($merch['album_id'] ?? 0) ?>"
                data-merch-track="<?= (int)($merch['track_id'] ?? 0) ?>"
              >
                <?php if (trim((string)$merch['image_path']) !== ''): ?>
                  <img
                    src="<?= e(url('/content-image.php?type=merch&id='.(int)$merch['id'])) ?>"
                    alt=""
                  >
                <?php endif; ?>

                <div>
                  <span>
                    <?php if ((int)($merch['album_id'] ?? 0) > 0): ?>
                      Album merch
                    <?php elseif ((int)($merch['track_id'] ?? 0) > 0): ?>
                      Track merch
                    <?php else: ?>
                      Stonefellow merch
                    <?php endif; ?>
                  </span>
                  <strong><?= e((string)$merch['title']) ?></strong>
                  <p><?= e((string)$merch['description']) ?></p>
                  <small>$<?= number_format(((int)$merch['price_cents'])/100,2) ?></small>

                  <?php if (trim((string)$merch['product_url']) !== ''): ?>
                    <a
                      href="<?= e((string)$merch['product_url']) ?>"
                      target="_blank"
                      rel="noopener noreferrer"
                    >View Merch ↗</a>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>

            <?php if (!$chatMerch): ?>
              <div class="chat-canvas-empty compact">No merch is currently available.</div>
            <?php endif; ?>
          </div>
        </section>

        <section class="chat-player-section" id="playerAllTracks" data-player-queue="all-tracks">
          <header>
            <div>
              <span>Library</span>
              <h2>All Tracks</h2>
            </div>
            <small><?= count($chatTracks) ?> available</small>
          </header>

          <div class="chat-player-all-list">
            <?php foreach ($chatTracks as $allIndex => $allTrack): ?>
              <?php $allTrackId = (int)$allTrack['id']; ?>
              <article
                class="chat-player-all-row"
                data-player-search-text="<?= e(mb_strtolower((string)$allTrack['title'].' '.(string)$allTrack['album'].' '.(string)($allTrack['genre'] ?? '').' '.(string)($allTrack['mood'] ?? ''))) ?>"
              >
                <span><?= $allIndex + 1 ?></span>

                <img
                  src="<?= e(url('/media.php?track='.$allTrackId.'&type=cover')) ?>"
                  alt=""
                >

                <div>
                  <strong><?= e((string)$allTrack['title']) ?></strong>
                  <small><?= e((string)($allTrack['album'] ?: 'Stonefellow')) ?></small>
                </div>

                <small><?= e((string)($allTrack['duration'] ?? '')) ?></small>

                <button
                  type="button"
                  class="chat-player-favorite <?= isset($chatFavoriteTrackIds[$allTrackId]) ? 'active' : '' ?>"
                  data-favorite-track="<?= $allTrackId ?>"
                  aria-pressed="<?= isset($chatFavoriteTrackIds[$allTrackId]) ? 'true' : 'false' ?>"
                  title="Favorite"
                ><?= isset($chatFavoriteTrackIds[$allTrackId]) ? '♥' : '♡' ?></button>

                <?php if (has_permission('track_notes.manage', $user)): ?>
                  <a
                    class="chat-player-studio-link desktop-studio-only"
                    href="<?= e(url('/admin/stems.php?track='.$allTrackId)) ?>"
                  >Studio</a>
                <?php endif; ?>

                <audio
                  class="chat-audio-player"
                  preload="metadata"
                  data-track-id="<?= $allTrackId ?>"
                  data-player-title="<?= e((string)$allTrack['title']) ?>"
                  data-player-album="<?= e((string)($allTrack['album'] ?: 'Stonefellow')) ?>"
                  data-player-cover="<?= e(url('/media.php?track='.$allTrackId.'&type=cover')) ?>"
                  data-player-detail="<?= e(url('/track.php?id='.$allTrackId)) ?>"
                  src="<?= e(url('/media.php?track='.$allTrackId.'&type=audio')) ?>"
                ></audio>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      </section>

      <section
        class="chat-canvas-view chat-saved-songs-canvas"
        data-chat-view="saved"
        hidden
      >
        <header class="chat-canvas-view-head chat-saved-songs-head">
          <div>
            <span>Your Library</span>
            <h1>Saved Songs</h1>
            <p>Your personal Stonefellow song catalog. Play, review, or remove saved tracks without leaving Agent Chat.</p>
          </div>
          <small id="chatSavedSongsCount"><?= count($chatFavoriteTracks) ?> saved</small>
        </header>

        <div class="chat-saved-song-grid" id="chatSavedSongsGrid">
          <?php foreach ($chatTracks as $savedTrack): ?>
            <?php
              $savedTrackId = (int)$savedTrack['id'];
              $isSavedTrack = isset($chatFavoriteTrackIds[$savedTrackId]);
            ?>
            <article
              class="chat-saved-song-card"
              data-saved-card-track="<?= $savedTrackId ?>"
              <?= $isSavedTrack ? '' : 'hidden' ?>
            >
              <button
                class="chat-saved-song-art"
                type="button"
                data-play-track="<?= $savedTrackId ?>"
                aria-label="Play <?= e((string)$savedTrack['title']) ?>"
              >
                <img
                  src="<?= e(url('/media.php?track='.$savedTrackId.'&type=cover')) ?>"
                  alt=""
                  loading="lazy"
                >
                <span aria-hidden="true">▶</span>
              </button>

              <div class="chat-saved-song-copy">
                <strong><?= e((string)$savedTrack['title']) ?></strong>
                <span><?= e((string)($savedTrack['album'] ?: 'Stonefellow')) ?></span>
                <?php if (trim((string)($savedTrack['genre'] ?? '')) !== ''): ?>
                  <small><?= e((string)$savedTrack['genre']) ?></small>
                <?php endif; ?>
              </div>

              <div class="chat-saved-song-actions">
                <button
                  type="button"
                  class="chat-saved-song-play"
                  data-play-track="<?= $savedTrackId ?>"
                >▶ Play</button>

                <button
                  type="button"
                  class="chat-player-favorite <?= $isSavedTrack ? 'active' : '' ?>"
                  data-favorite-track="<?= $savedTrackId ?>"
                  aria-pressed="<?= $isSavedTrack ? 'true' : 'false' ?>"
                  title="<?= $isSavedTrack ? 'Remove favorite' : 'Favorite' ?>"
                ><?= $isSavedTrack ? '♥' : '♡' ?></button>

                <?php if ($chatCanEditStems): ?>
                  <a
                    class="chat-saved-song-stem-editor"
                    href="<?= e(url('/admin/stems.php?track='.$savedTrackId.'&return='.rawurlencode(url('/chat.php?view=saved')))) ?>"
                  >Stem Editor</a>
                <?php endif; ?>

                <a href="<?= e(url('/track.php?id='.$savedTrackId)) ?>">Details</a>
              </div>

              <audio
                class="chat-audio-player"
                preload="metadata"
                data-track-id="<?= $savedTrackId ?>"
                data-player-title="<?= e((string)$savedTrack['title']) ?>"
                data-player-album="<?= e((string)($savedTrack['album'] ?: 'Stonefellow')) ?>"
                data-player-cover="<?= e(url('/media.php?track='.$savedTrackId.'&type=cover')) ?>"
                data-player-detail="<?= e(url('/track.php?id='.$savedTrackId)) ?>"
                src="<?= e(url('/media.php?track='.$savedTrackId.'&type=audio')) ?>"
              ></audio>
            </article>
          <?php endforeach; ?>
        </div>

        <div
          class="chat-saved-songs-empty"
          id="chatSavedSongsEmpty"
          <?= $chatFavoriteTracks ? 'hidden' : '' ?>
        >
          <strong>No saved songs yet.</strong>
          <span>Save a song from Player and it will appear here automatically.</span>
        </div>
      </section>

      <section
        class="chat-canvas-view"
        data-chat-view="playlists"
        hidden
      >
        <header class="chat-canvas-view-head">
          <div>
            <span>Your + Shared Library</span>
            <h1>Playlists</h1>
            <p>Create your own playlists, or play public playlists shared by other Stonefellow listeners.</p>
          </div>

          <button
            class="chat-canvas-create-shortcut"
            type="button"
            data-chat-create-type="playlist"
          >+ Add Playlist</button>
        </header>

        <div class="chat-canvas-playlist-list">
          <?php foreach ($chatPlaylists as $playlist): ?>
            <article
              class="chat-canvas-playlist-card"
              data-playlist-card="<?= (int)$playlist['id'] ?>"
            >
              <header>
                <div>
                  <span>
                    <?= e($playlist['visibility'] === 'public' ? 'Public Playlist' : 'Private to Signed-In Workspace') ?>
                    · <?= e(!empty($playlist['owned']) ? 'You' : (string)$playlist['owner_name']) ?>
                  </span>
                  <strong><?= e((string)$playlist['title']) ?></strong>
                  <?php if (trim((string)$playlist['description']) !== ''): ?>
                    <p><?= e((string)$playlist['description']) ?></p>
                  <?php endif; ?>
                </div>
                <div class="chat-playlist-card-actions">
                  <small>
                    <?= count($playlist['tracks']) ?>
                    track<?= count($playlist['tracks']) === 1 ? '' : 's' ?>
                  </small>

                  <button
                    type="button"
                    data-play-playlist="<?= (int)$playlist['id'] ?>"
                  >Play All</button>

                  <button
                    type="button"
                    class="<?= !empty($playlist['favorite']) ? 'active' : '' ?>"
                    data-playlist-favorite="<?= (int)$playlist['id'] ?>"
                    aria-pressed="<?= !empty($playlist['favorite']) ? 'true' : 'false' ?>"
                  ><?= !empty($playlist['favorite']) ? '♥' : '♡' ?></button>

                  <?php if (!empty($playlist['owned'])): ?>
                    <button
                      type="button"
                      data-edit-playlist="<?= (int)$playlist['id'] ?>"
                    >Edit</button>
                  <?php else: ?>
                    <button
                      type="button"
                      data-duplicate-playlist="<?= (int)$playlist['id'] ?>"
                    >Duplicate</button>
                  <?php endif; ?>
                </div>
              </header>

              <div class="chat-audio-list">
                <?php foreach ($playlist['tracks'] as $playlistTrack): ?>
                  <?php $playlistTrackId = (int)$playlistTrack['id']; ?>
                  <article class="chat-audio-card">
                    <img
                      class="chat-audio-cover"
                      src="<?= e(url('/media.php?track='.$playlistTrackId.'&type=cover')) ?>"
                      alt=""
                    >

                    <div class="chat-audio-copy">
                      <div class="chat-audio-title-row">
                        <div>
                          <strong><?= e((string)$playlistTrack['title']) ?></strong>
                          <span><?= e((string)($playlistTrack['album'] ?: 'Stonefellow')) ?></span>
                        </div>
                      </div>

                      <audio
                        class="chat-audio-player"
                        preload="metadata"
                        data-track-id="<?= $playlistTrackId ?>"
                        data-player-title="<?= e((string)$playlistTrack['title']) ?>"
                        data-player-album="<?= e((string)($playlistTrack['album'] ?: 'Stonefellow')) ?>"
                        data-player-cover="<?= e(url('/media.php?track='.$playlistTrackId.'&type=cover')) ?>"
                        data-player-detail="<?= e(url('/track.php?id='.$playlistTrackId)) ?>"
                        src="<?= e(url('/media.php?track='.$playlistTrackId.'&type=audio')) ?>"
                      ></audio>
                    </div>
                  </article>
                <?php endforeach; ?>

                <?php if (!$playlist['tracks']): ?>
                  <div class="chat-canvas-empty">This playlist is empty.</div>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>

          <?php if (!$chatPlaylists): ?>
            <div class="chat-canvas-empty">
              You have not created a playlist yet. Use + Add Playlist to build one.
            </div>
          <?php endif; ?>
        </div>
      </section>

      <section
        class="chat-canvas-view"
        data-chat-view="shows"
        hidden
      >
        <header class="chat-canvas-view-head">
          <div>
            <span>Live</span>
            <h1>Shows</h1>
            <p>Upcoming Stonefellow performance dates displayed inside your signed-in workspace.</p>
          </div>
        </header>

        <div class="chat-canvas-show-list">
          <?php foreach ($chatShows as $chatShow): ?>
            <?php
              $chatShowDate = new DateTime((string)$chatShow['show_date']);
              $chatShowLocation = trim(
                  implode(
                      ', ',
                      array_filter([
                          (string)($chatShow['city'] ?? ''),
                          (string)($chatShow['region'] ?? ''),
                      ])
                  )
              );
            ?>
            <article class="chat-canvas-show-card">
              <time>
                <strong><?= e($chatShowDate->format('M')) ?></strong>
                <span><?= e($chatShowDate->format('j')) ?></span>
              </time>

              <div>
                <span><?= e($chatShowDate->format('D · g:i A')) ?></span>
                <strong><?= e((string)$chatShow['venue']) ?></strong>
                <p>
                  <?= e($chatShowLocation) ?>
                  <?= trim((string)($chatShow['notes'] ?? '')) !== '' ? ' · ' . e((string)$chatShow['notes']) : '' ?>
                </p>
              </div>

              <div class="chat-show-actions">
                <button
                  type="button"
                  class="<?= isset($chatShowReminderIds[(int)$chatShow['id']]) ? 'active' : '' ?>"
                  data-show-reminder="<?= (int)$chatShow['id'] ?>"
                  aria-pressed="<?= isset($chatShowReminderIds[(int)$chatShow['id']]) ? 'true' : 'false' ?>"
                ><?= isset($chatShowReminderIds[(int)$chatShow['id']]) ? 'Reminder On' : 'Remind Me' ?></button>

                <?php if (trim((string)($chatShow['ticket_url'] ?? '')) !== ''): ?>
                  <a
                    href="<?= e((string)$chatShow['ticket_url']) ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                  >Tickets ↗</a>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>

          <?php if (!$chatShows): ?>
            <div class="chat-canvas-empty">
              No upcoming shows are currently listed.
            </div>
          <?php endif; ?>
        </div>
      </section>

      <section
        class="chat-canvas-view"
        data-chat-view="photos"
        hidden
      >
        <header class="chat-canvas-view-head">
          <div>
            <span>Visual Library</span>
            <h1>Photos</h1>
            <p>Stonefellow studio imagery and track artwork available to your account.</p>
          </div>
        </header>

        <div class="chat-canvas-photo-grid">
          <?php foreach ($chatPhotos as $chatPhoto): ?>
            <figure class="chat-canvas-photo-card">
              <img src="<?= e((string)$chatPhoto['src']) ?>" alt="<?= e((string)($chatPhoto['alt'] ?? '')) ?>">
              <figcaption>
                <strong><?= e((string)$chatPhoto['title']) ?></strong>
                <span><?= e((string)$chatPhoto['caption']) ?></span>
              </figcaption>
            </figure>
          <?php endforeach; ?>
        </div>
      </section>

      <section
        class="chat-canvas-view"
        data-chat-view="merch"
        hidden
      >
        <header class="chat-canvas-view-head">
          <div>
            <span>Stonefellow Store</span>
            <h1>Merch</h1>
            <p>Stonefellow merchandise available to your account.</p>
          </div>
        </header>

        <div class="chat-canvas-merch-grid">
          <?php foreach ($chatMerch as $merchItem): ?>
            <article class="chat-canvas-merch-card">
              <?php if (trim((string)$merchItem['image_path']) !== ''): ?>
                <img
                  src="<?= e(url('/content-image.php?type=merch&id='.(int)$merchItem['id'])) ?>"
                  alt="<?= e((string)$merchItem['title']) ?>"
                >
              <?php else: ?>
                <div class="chat-canvas-merch-placeholder">S</div>
              <?php endif; ?>

              <div>
                <span>Merch</span>
                <strong><?= e((string)$merchItem['title']) ?></strong>
                <p><?= e((string)$merchItem['description']) ?></p>

                <div class="chat-canvas-merch-actions">
                  <b>$<?= number_format(((int)$merchItem['price_cents'])/100,2) ?></b>

                  <?php if (trim((string)$merchItem['product_url']) !== ''): ?>
                    <a
                      href="<?= e((string)$merchItem['product_url']) ?>"
                      target="_blank"
                      rel="noopener noreferrer"
                    >View / Buy ↗</a>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>

          <?php if (!$chatMerch): ?>
            <div class="chat-canvas-empty">
              No merch is currently available.
            </div>
          <?php endif; ?>
        </div>
      </section>

      <section
        class="chat-canvas-view"
        data-chat-view="about"
        hidden
      >
        <header class="chat-canvas-view-head">
          <div>
            <span>Stonefellow</span>
            <h1>About</h1>
            <p><?= e(setting('tagline', 'Music. Stories. Connection.')) ?></p>
          </div>
        </header>

        <div class="chat-canvas-about-grid">
          <article class="chat-canvas-about-hero">
            <img src="<?= e(url('/images/stonefellow-studio.png')) ?>" alt="">
            <div>
              <span>Artist</span>
              <h2>Stonefellow</h2>
              <p>
                <?= e(
                  setting(
                    'bio_subhead',
                    'Rock, Americana and acoustic storytelling recorded with a close, live-room feel.'
                  )
                ) ?>
              </p>
            </div>
          </article>

          <article class="chat-canvas-info-card">
            <span>Agent Chat</span>
            <h2>Your signed-in Stonefellow workspace</h2>
            <p>
              Ask about tracks, shows, artist information, available knowledge,
              listening history and account-authorized production activity.
            </p>
            <div class="chat-canvas-info-points">
              <span>Tracks and playable music</span>
              <span>Upcoming shows</span>
              <span>Producer sharing updates</span>
              <span>Supervisor listening updates</span>
              <span>Account-permitted Stonefellow knowledge</span>
            </div>
          </article>
        </div>
      </section>

      <div class="chat-welcome" id="chatWelcome">
        <div class="chat-welcome-mark">S</div>
        <h1><?= e((string)$chatIntro['greeting']) ?></h1>
        <p>I’ll keep you connected to your latest conversation, shared tracks, production work, and team notes.</p>

        <div class="chat-starters">
          <button type="button" data-prompt="What Stonefellow songs are available to me?">What music can I access?</button>
          <button type="button" data-prompt="What are the upcoming Stonefellow shows?">Upcoming shows</button>
          <button type="button" data-prompt="Summarize the Stonefellow artist bio.">Artist overview</button>
          <button type="button" data-prompt="Build me a late-night Stonefellow mood playlist.">Late-night playlist</button>
        </div>
      </div>
    </section>

    <div class="chat-composer-shell" id="chatComposerShell">
      <section
        class="chat-now-playing"
        id="chatNowPlaying"
        aria-live="polite"
        hidden
      >
        <img
          id="chatNowPlayingCover"
          class="chat-now-playing-cover"
          src=""
          alt=""
        >

        <div class="chat-now-playing-copy">
          <button
            id="chatNowPlayingQueue"
            class="chat-now-playing-queue-button"
            type="button"
            aria-label="Open Up Next queue"
          >Now Playing</button>
          <strong id="chatNowPlayingTitle">Stonefellow</strong>
          <small id="chatNowPlayingAlbum">Stonefellow</small>
        </div>

        <div class="chat-now-playing-transport">
          <button
            id="chatNowPlayingPrev"
            type="button"
            aria-label="Previous track"
          >‹</button>

          <button
            id="chatNowPlayingToggle"
            class="primary"
            type="button"
            aria-label="Play or pause"
          >▶</button>

          <button
            id="chatNowPlayingNext"
            type="button"
            aria-label="Next track"
          >›</button>
        </div>

        <div class="chat-now-playing-progress">
          <input
            id="chatNowPlayingSeek"
            type="range"
            min="0"
            max="1000"
            value="0"
            step="1"
            aria-label="Playback position"
          >
          <div>
            <span id="chatNowPlayingCurrent">0:00</span>
            <span id="chatNowPlayingDuration">0:00</span>
          </div>
        </div>

        <div class="chat-now-playing-volume-control">
          <button
            id="chatNowPlayingVolumeButton"
            class="chat-now-playing-volume-button"
            type="button"
            aria-label="Volume"
            aria-expanded="false"
            aria-controls="chatNowPlayingVolumePopover"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path class="speaker-body" d="M4 9v6h4l5 4V5L8 9H4z" />
              <path class="speaker-wave" d="M16 8.5c1.2 1 1.8 2.1 1.8 3.5S17.2 14.5 16 15.5" />
              <path class="speaker-wave" d="M18.5 6c2 1.7 3 3.7 3 6s-1 4.3-3 6" />
              <path class="speaker-slash" d="M16.5 9.5l5 5" />
              <path class="speaker-slash" d="M21.5 9.5l-5 5" />
            </svg>
          </button>

          <div
            id="chatNowPlayingVolumePopover"
            class="chat-now-playing-volume-popover"
            hidden
          >
            <input
              id="chatNowPlayingVolume"
              type="range"
              min="0"
              max="1"
              value="1"
              step=".05"
              aria-label="Player volume"
            >
          </div>
        </div>
      </section>

      <form class="chat-composer" id="chatForm">
        <textarea id="chatInput" rows="1" maxlength="6000" placeholder="Message Stonefellow..." aria-label="Message Stonefellow"></textarea>
        <button class="chat-voice-button" id="chatVoiceButton" type="button" aria-label="Start voice conversation" aria-pressed="false">◉</button>
        <button id="sendChatButton" type="submit" aria-label="Send message">↑</button>
      </form>
      <div class="chat-voice-status" id="chatVoiceStatus" hidden aria-live="polite"></div>
    </div>
  </main>
</div>

<div class="chat-player-overlay" id="chatPlayerDrawer" hidden>
  <button class="chat-player-overlay-backdrop" type="button" data-close-player-drawer aria-label="Close"></button>
  <aside class="chat-player-drawer" role="dialog" aria-modal="true" aria-labelledby="chatPlayerDrawerTitle">
    <header>
      <div>
        <span id="chatPlayerDrawerKicker">Player</span>
        <h2 id="chatPlayerDrawerTitle">Details</h2>
      </div>
      <button type="button" data-close-player-drawer aria-label="Close">×</button>
    </header>
    <div class="chat-player-drawer-body" id="chatPlayerDrawerBody"></div>
  </aside>
</div>

<div class="chat-player-overlay" id="chatQueueDrawer" hidden>
  <button class="chat-player-overlay-backdrop" type="button" data-close-queue aria-label="Close queue"></button>
  <aside class="chat-player-drawer chat-queue-drawer" role="dialog" aria-modal="true" aria-labelledby="chatQueueTitle">
    <header>
      <div>
        <span>Up Next</span>
        <h2 id="chatQueueTitle">Queue</h2>
      </div>
      <div class="chat-drawer-header-actions">
        <button type="button" id="chatQueueClear">Clear</button>
        <button type="button" data-close-queue aria-label="Close">×</button>
      </div>
    </header>
    <div class="chat-player-drawer-body" id="chatQueueList"></div>
  </aside>
</div>

<div class="chat-track-action-menu" id="chatTrackActionMenu" hidden>
  <button type="button" data-track-action="play-next">Play Next</button>
  <button type="button" data-track-action="add-queue">Add to Queue</button>
  <button type="button" data-track-action="playlist">Add to Playlist</button>
  <button type="button" data-track-action="favorite">Favorite</button>
  <button type="button" data-track-action="album">View Album</button>
  <button type="button" data-track-action="info">Track Info</button>
  <button type="button" data-track-action="lyrics">Lyrics</button>
  <a class="desktop-studio-only" id="chatTrackStudioAction" href="#">Stem Studio</a>
</div>

<div class="chat-player-overlay" id="chatPlaylistEditor" hidden>
  <button class="chat-player-overlay-backdrop" type="button" data-close-playlist-editor aria-label="Close playlist editor"></button>
  <aside class="chat-player-drawer chat-playlist-editor" role="dialog" aria-modal="true" aria-labelledby="chatPlaylistEditorTitle">
    <header>
      <div>
        <span>Your Playlist</span>
        <h2 id="chatPlaylistEditorTitle">Edit Playlist</h2>
      </div>
      <button type="button" data-close-playlist-editor aria-label="Close">×</button>
    </header>

    <form id="chatPlaylistEditorForm" class="chat-playlist-editor-form">
      <input type="hidden" id="chatPlaylistEditorId">

      <label>
        <span>Title</span>
        <input id="chatPlaylistEditorName" maxlength="190" required>
      </label>

      <label>
        <span>Visibility</span>
        <select id="chatPlaylistEditorVisibility">
          <option value="members">Signed-In Users</option>
          <option value="public">Public</option>
        </select>
      </label>

      <label class="wide">
        <span>Description</span>
        <textarea id="chatPlaylistEditorDescription" rows="4"></textarea>
      </label>

      <div class="wide">
        <span class="chat-editor-label">Tracks · drag to reorder</span>
        <div id="chatPlaylistEditorTracks" class="chat-playlist-editor-tracks"></div>
      </div>

      <div class="wide chat-playlist-editor-actions">
        <button type="submit">Save Playlist</button>
        <button type="button" id="chatPlaylistEditorPlay">Play All</button>
        <button type="button" id="chatPlaylistEditorDuplicate">Duplicate</button>
        <button type="button" id="chatPlaylistEditorShare">Share</button>
        <button type="button" class="danger" id="chatPlaylistEditorDelete">Delete</button>
      </div>
    </form>
  </aside>
</div>

<?php if ($chatCreateItems): ?>
<div
  class="chat-create-modal"
  id="chatCreateModal"
  hidden
  aria-hidden="true"
>
  <div
    class="chat-create-modal-backdrop"
    data-close-chat-create
  ></div>

  <section
    class="chat-create-modal-card"
    role="dialog"
    aria-modal="true"
    aria-labelledby="chatCreateModalTitle"
  >
    <header class="chat-create-modal-head">
      <div>
        <span id="chatCreateModalKicker">Create</span>
        <h2 id="chatCreateModalTitle">Add Content</h2>
      </div>

      <div class="chat-create-modal-head-actions">
        <a id="chatCreateAdvanced" href="#">Full Editor ↗</a>
        <button
          type="button"
          data-close-chat-create
          aria-label="Close create form"
        >×</button>
      </div>
    </header>

    <div
      class="chat-create-modal-status"
      id="chatCreateModalStatus"
      hidden
    ></div>

    <div class="chat-create-modal-body">

      <?php if (has_permission('tracks.manage', $user)): ?>
      <form
        class="chat-create-form"
        data-chat-create-form="track"
        enctype="multipart/form-data"
        hidden
      >
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="track">

        <div class="chat-create-form-intro">
          <span>Music</span>
          <h3>Add Track</h3>
          <p>Create a playable track now. Open the full editor afterward for Producer sharing and REAPER/Stem Studio setup.</p>
        </div>

        <div class="chat-create-fields">
          <label>
            <span>Title</span>
            <input name="title" maxlength="190" required>
          </label>

          <label>
            <span>Album</span>
            <select name="album_id">
              <option value="0">No managed album</option>
              <?php foreach ($chatAlbums as $albumOption): ?>
                <option value="<?= (int)$albumOption['id'] ?>">
                  <?= e((string)$albumOption['title']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <input type="hidden" name="album" value="Stonefellow">
          </label>

          <label>
            <span>Duration</span>
            <input name="duration" maxlength="20" placeholder="3:58">
          </label>

          <label>
            <span>Tempo BPM</span>
            <input name="tempo_bpm" type="number" min="1" max="300" placeholder="92">
          </label>

          <label>
            <span>Genre</span>
            <input name="genre" placeholder="Americana, rock, acoustic">
          </label>

          <label>
            <span>Mood</span>
            <input name="mood" placeholder="reflective, dark, uplifting">
          </label>

          <label>
            <span>Energy</span>
            <select name="energy">
              <option value="">Not set</option>
              <option value="low">Low</option>
              <option value="medium">Medium</option>
              <option value="high">High</option>
            </select>
          </label>

          <label>
            <span>Visibility</span>
            <select name="visibility" required>
              <?php foreach (visibility_options() as $value=>$label): ?>
                <option
                  value="<?= e($value) ?>"
                  <?= $value === 'public' ? 'selected' : '' ?>
                ><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="wide">
            <span>Description</span>
            <textarea
              name="description"
              rows="3"
              placeholder="Short description for Agent Chat and recommendations."
            ></textarea>
          </label>

          <label class="wide">
            <span>Credits</span>
            <textarea
              name="credits"
              rows="3"
              placeholder="Songwriter, producer, performers, engineers."
            ></textarea>
          </label>

          <label class="wide">
            <span>Recommendation Keywords</span>
            <input
              name="keywords"
              maxlength="500"
              placeholder="late night, road trip, heartbreak"
            >
          </label>

          <label>
            <span>Audio File</span>
            <input
              name="audio_file"
              type="file"
              accept=".mp3,.m4a,.wav,.ogg,audio/*"
              required
            >
          </label>

          <label>
            <span>Cover Image</span>
            <input
              name="cover_file"
              type="file"
              accept=".jpg,.jpeg,.png,.webp,image/*"
            >
          </label>

          <label>
            <span>Sort Order</span>
            <input name="sort_order" type="number" value="0">
          </label>
        </div>

        <label class="chat-create-check">
          <input name="is_published" type="checkbox" value="1" checked>
          <span>Published</span>
        </label>

        <div class="chat-create-form-actions">
          <button type="submit">Add Track</button>
          <button type="button" class="secondary" data-close-chat-create>Cancel</button>
        </div>
      </form>
      <?php endif; ?>

      <?php if (has_permission('albums.manage', $user)): ?>
      <form
        class="chat-create-form"
        data-chat-create-form="album"
        enctype="multipart/form-data"
        hidden
      >
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="album">

        <div class="chat-create-form-intro">
          <span>Music Collection</span>
          <h3>Add Album</h3>
          <p>Create the album and optionally assign existing tracks immediately.</p>
        </div>

        <div class="chat-create-fields">
          <label>
            <span>Album Title</span>
            <input name="title" maxlength="190" required>
          </label>

          <label>
            <span>Release Date</span>
            <input name="release_date" type="date">
          </label>

          <label>
            <span>Visibility</span>
            <select name="visibility">
              <?php foreach (visibility_options() as $value=>$label): ?>
                <option
                  value="<?= e($value) ?>"
                  <?= $value === 'members' ? 'selected' : '' ?>
                ><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            <span>Sort Order</span>
            <input name="sort_order" type="number" value="0">
          </label>

          <label class="wide">
            <span>Description</span>
            <textarea name="description" rows="4"></textarea>
          </label>

          <label class="wide">
            <span>Album Cover</span>
            <input
              name="cover_file"
              type="file"
              accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
            >
          </label>

          <div class="wide chat-create-track-picker-field">
            <span>Assign Tracks</span>
            <div class="chat-create-track-picker">
              <?php foreach ($chatTracks as $trackOption): ?>
                <label>
                  <input
                    type="checkbox"
                    name="track_ids[]"
                    value="<?= (int)$trackOption['id'] ?>"
                  >
                  <span>
                    <strong><?= e((string)$trackOption['title']) ?></strong>
                    <small><?= e((string)($trackOption['album'] ?: 'Unassigned')) ?></small>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
            <small>Selecting a track moves it into this album.</small>
          </div>
        </div>

        <label class="chat-create-check">
          <input name="is_published" type="checkbox" value="1" checked>
          <span>Published</span>
        </label>

        <div class="chat-create-form-actions">
          <button type="submit">Add Album</button>
          <button type="button" class="secondary" data-close-chat-create>Cancel</button>
        </div>
      </form>
      <?php endif; ?>

      <?php if (has_permission('shows.manage', $user)): ?>
      <form
        class="chat-create-form"
        data-chat-create-form="event"
        hidden
      >
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="event">

        <div class="chat-create-form-intro">
          <span>Live</span>
          <h3>Add Event</h3>
          <p>Add a show date without leaving Agent Chat.</p>
        </div>

        <div class="chat-create-fields">
          <label>
            <span>Date & Time</span>
            <input name="show_date" type="datetime-local" required>
          </label>

          <label>
            <span>Venue</span>
            <input name="venue" maxlength="190" required>
          </label>

          <label>
            <span>City</span>
            <input name="city" maxlength="120">
          </label>

          <label>
            <span>State / Region</span>
            <input name="region" maxlength="120">
          </label>

          <label class="wide">
            <span>Ticket URL</span>
            <input name="ticket_url" type="url" maxlength="500" placeholder="https://">
          </label>

          <label class="wide">
            <span>Notes</span>
            <textarea name="notes" rows="4"></textarea>
          </label>
        </div>

        <label class="chat-create-check">
          <input name="is_published" type="checkbox" value="1" checked>
          <span>Published</span>
        </label>

        <div class="chat-create-form-actions">
          <button type="submit">Add Event</button>
          <button type="button" class="secondary" data-close-chat-create>Cancel</button>
        </div>
      </form>
      <?php endif; ?>

      <?php if (has_permission('knowledge.manage', $user)): ?>
      <form
        class="chat-create-form"
        data-chat-create-form="knowledge"
        enctype="multipart/form-data"
        hidden
      >
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="knowledge">

        <div class="chat-create-form-intro">
          <span>Agent Knowledge</span>
          <h3>Add Knowledge</h3>
          <p>Add text, notes or a file and index it for Agent Chat.</p>
        </div>

        <div class="chat-create-fields">
          <label>
            <span>Title</span>
            <input name="title" maxlength="190" required>
          </label>

          <label>
            <span>Track</span>
            <select name="track_id">
              <option value="0">General Knowledge</option>
              <?php foreach ($chatTracks as $trackOption): ?>
                <option value="<?= (int)$trackOption['id'] ?>">
                  <?= e((string)$trackOption['title']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            <span>Visibility</span>
            <select name="visibility">
              <?php foreach (visibility_options() as $value=>$label): ?>
                <option
                  value="<?= e($value) ?>"
                  <?= $value === 'members' ? 'selected' : '' ?>
                ><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="wide">
            <span>Description</span>
            <textarea name="description" rows="3"></textarea>
          </label>

          <label class="wide">
            <span>Knowledge File</span>
            <input
              name="knowledge_file"
              type="file"
              accept=".txt,.md,.csv,.json,.html,.htm,.xml,.doc,.docx,.pdf,.mp3,.m4a,.wav,.ogg"
            >
            <small>TXT, Markdown, CSV, JSON, HTML, DOC/DOCX, PDF or audio.</small>
          </label>

          <label class="wide">
            <span>Knowledge Text / Transcript / Notes</span>
            <textarea
              name="content_text"
              rows="8"
              placeholder="Add searchable knowledge, lyrics, transcript, credits or context."
            ></textarea>
          </label>
        </div>

        <label class="chat-create-check">
          <input name="is_published" type="checkbox" value="1" checked>
          <span>Published / available to Chat</span>
        </label>

        <div class="chat-create-form-actions">
          <button type="submit">Add Knowledge</button>
          <button type="button" class="secondary" data-close-chat-create>Cancel</button>
        </div>
      </form>
      <?php endif; ?>

      <?php if (has_permission('users.manage', $user)): ?>
      <form
        class="chat-create-form"
        data-chat-create-form="user"
        enctype="multipart/form-data"
        hidden
      >
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="user">

        <div class="chat-create-form-intro">
          <span>Account</span>
          <h3>Add User</h3>
          <p>Create an account and assign its account type.</p>
        </div>

        <div class="chat-create-fields">
          <label>
            <span>Display Name</span>
            <input name="display_name" maxlength="120" required>
          </label>

          <label>
            <span>Email</span>
            <input name="email" type="email" maxlength="190" required>
          </label>

          <label>
            <span>Account Type</span>
            <select name="role" required>
              <?php foreach (user_roles() as $role=>$label): ?>
                <option value="<?= e($role) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            <span>Temporary Password</span>
            <input
              name="password"
              type="password"
              minlength="12"
              autocomplete="new-password"
              required
            >
            <small>Minimum 12 characters.</small>
          </label>

          <label class="wide">
            <span>User Photo</span>
            <input
              name="avatar_file"
              type="file"
              accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
            >
          </label>
        </div>

        <label class="chat-create-check">
          <input name="is_active" type="checkbox" value="1" checked>
          <span>Active account</span>
        </label>

        <div class="chat-create-form-actions">
          <button type="submit">Add User</button>
          <button type="button" class="secondary" data-close-chat-create>Cancel</button>
        </div>
      </form>
      <?php endif; ?>

      <?php if ($chatCanCreatePlaylist): ?>
      <form
        class="chat-create-form"
        data-chat-create-form="playlist"
        hidden
      >
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="playlist">

        <div class="chat-create-form-intro">
          <span>Your Library</span>
          <h3>Add Playlist</h3>
          <p>Create a personal playlist from tracks your account can play.</p>
        </div>

        <div class="chat-create-fields">
          <label>
            <span>Playlist Title</span>
            <input name="title" maxlength="190" required>
          </label>

          <label>
            <span>Visibility</span>
            <select name="visibility">
              <option value="members" selected>Signed-In Users</option>
              <option value="public">Public</option>
            </select>
          </label>

          <label class="wide">
            <span>Description</span>
            <textarea name="description" rows="4"></textarea>
          </label>

          <div class="wide chat-create-track-picker-field">
            <span>Tracks</span>
            <div class="chat-create-track-picker">
              <?php foreach ($chatTracks as $trackOption): ?>
                <label>
                  <input
                    type="checkbox"
                    name="track_ids[]"
                    value="<?= (int)$trackOption['id'] ?>"
                  >
                  <span>
                    <strong><?= e((string)$trackOption['title']) ?></strong>
                    <small><?= e((string)($trackOption['album'] ?: 'Stonefellow')) ?></small>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
            <small>You can create an empty playlist now and add, remove or reorder tracks later from Edit Playlist.</small>
          </div>
        </div>

        <div class="chat-create-form-actions">
          <button type="submit">Add Playlist</button>
          <button type="button" class="secondary" data-close-chat-create>Cancel</button>
        </div>
      </form>
      <?php endif; ?>

      <?php if (has_permission('merch.manage', $user)): ?>
      <form
        class="chat-create-form"
        data-chat-create-form="merch"
        enctype="multipart/form-data"
        hidden
      >
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="merch">

        <div class="chat-create-form-intro">
          <span>Store</span>
          <h3>Add Merch</h3>
          <p>Add a merch card and purchase link.</p>
        </div>

        <div class="chat-create-fields">
          <label>
            <span>Item Name</span>
            <input name="title" maxlength="190" required>
          </label>

          <label>
            <span>Price</span>
            <input name="price" type="number" min="0" step=".01" value="0.00" required>
          </label>

          <label class="wide">
            <span>Description</span>
            <textarea name="description" rows="4"></textarea>
          </label>

          <label class="wide">
            <span>Product / Checkout URL</span>
            <input name="product_url" type="url" maxlength="500" placeholder="https://">
          </label>

          <label>
            <span>Related Album</span>
            <select name="album_id">
              <option value="0">No album association</option>
              <?php foreach ($chatAlbums as $albumOption): ?>
                <option value="<?= (int)$albumOption['id'] ?>"><?= e((string)$albumOption['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            <span>Related Track</span>
            <select name="track_id">
              <option value="0">No track association</option>
              <?php foreach ($chatTracks as $trackOption): ?>
                <option value="<?= (int)$trackOption['id'] ?>"><?= e((string)$trackOption['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            <span>Visibility</span>
            <select name="visibility">
              <?php foreach (visibility_options() as $value=>$label): ?>
                <option
                  value="<?= e($value) ?>"
                  <?= $value === 'members' ? 'selected' : '' ?>
                ><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            <span>Sort Order</span>
            <input name="sort_order" type="number" value="0">
          </label>

          <label class="wide">
            <span>Merch Image</span>
            <input
              name="merch_image"
              type="file"
              accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
            >
          </label>
        </div>

        <label class="chat-create-check">
          <input name="is_published" type="checkbox" value="1" checked>
          <span>Published</span>
        </label>

        <div class="chat-create-form-actions">
          <button type="submit">Add Merch</button>
          <button type="button" class="secondary" data-close-chat-create>Cancel</button>
        </div>
      </form>
      <?php endif; ?>

      <?php if (has_permission('posts.manage', $user)): ?>
      <form
        class="chat-create-form"
        data-chat-create-form="post"
        enctype="multipart/form-data"
        hidden
      >
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="post">

        <div class="chat-create-form-intro">
          <span>Artist Updates</span>
          <h3>Add Post</h3>
          <p>Publish a studio update, release note, show announcement, photo or video.</p>
        </div>

        <div class="chat-create-fields">
          <label>
            <span>Post Title</span>
            <input name="title" maxlength="190" required>
          </label>

          <label>
            <span>Post Type</span>
            <select name="post_type">
              <option value="update">Update</option>
              <option value="studio">Studio</option>
              <option value="release">Release</option>
              <option value="show">Show</option>
              <option value="photo">Photo</option>
              <option value="video">Video</option>
            </select>
          </label>

          <label class="wide">
            <span>Post</span>
            <textarea name="body" rows="7" required></textarea>
          </label>

          <label>
            <span>Image</span>
            <input
              name="post_image"
              type="file"
              accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
            >
          </label>

          <label>
            <span>Video / Media URL</span>
            <input name="media_url" type="url" maxlength="500" placeholder="https://">
          </label>

          <label class="wide">
            <span>Visibility</span>
            <select name="visibility">
              <?php foreach (visibility_options() as $value=>$label): ?>
                <option
                  value="<?= e($value) ?>"
                  <?= $value === 'members' ? 'selected' : '' ?>
                ><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <label class="chat-create-check">
          <input name="is_published" type="checkbox" value="1" checked>
          <span>Published</span>
        </label>

        <div class="chat-create-form-actions">
          <button type="submit">Add Post</button>
          <button type="button" class="secondary" data-close-chat-create>Cancel</button>
        </div>
      </form>
      <?php endif; ?>

      <?php if (has_permission('photos.manage', $user)): ?>
      <form
        class="chat-create-form"
        data-chat-create-form="photo"
        enctype="multipart/form-data"
        hidden
      >
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="photo">

        <div class="chat-create-form-intro">
          <span>Visual Library</span>
          <h3>Add Photo</h3>
          <p>Upload a photo directly into the Stonefellow photo library.</p>
        </div>

        <div class="chat-create-fields">
          <label>
            <span>Photo Title</span>
            <input name="title" maxlength="190" required>
          </label>

          <label>
            <span>Alt Text</span>
            <input name="alt_text" maxlength="255">
          </label>

          <label class="wide">
            <span>Caption</span>
            <textarea name="caption" rows="4"></textarea>
          </label>

          <label>
            <span>Visibility</span>
            <select name="visibility">
              <?php foreach (visibility_options() as $value=>$label): ?>
                <option
                  value="<?= e($value) ?>"
                  <?= $value === 'members' ? 'selected' : '' ?>
                ><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            <span>Sort Order</span>
            <input name="sort_order" type="number" value="0">
          </label>

          <label class="wide">
            <span>Photo</span>
            <input
              name="photo_file"
              type="file"
              accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
              required
            >
          </label>
        </div>

        <label class="chat-create-check">
          <input name="is_published" type="checkbox" value="1" checked>
          <span>Published</span>
        </label>

        <div class="chat-create-form-actions">
          <button type="submit">Add Photo</button>
          <button type="button" class="secondary" data-close-chat-create>Cancel</button>
        </div>
      </form>
      <?php endif; ?>

    </div>
  </section>
</div>
<?php endif; ?>

<script>
window.STONEFELLOW_CHAT = {
  endpoint: <?= json_encode(url('/api/chat.php')) ?>,
  createEndpoint: <?= json_encode(url('/api/chat-create-v76.php')) ?>,
  playbackEndpoint: <?= json_encode(url('/api/playback.php')) ?>,
  favoriteEndpoint: <?= json_encode(url('/api/favorites-v73.php')) ?>,
  libraryEndpoint: <?= json_encode(url('/api/player-library-v76.php')) ?>,
  mediaEndpoint: <?= json_encode(url('/api/media-library-v86.php')) ?>,
  videoEditorUrl: <?= json_encode(url('/video-editor.php')) ?>,
  csrf: <?= json_encode(csrf_token()) ?>,
  initialView: <?= json_encode(trim((string)($_GET['view'] ?? 'chat'))) ?>,
  initialConversationId: <?= (int)$chatInitialConversationId ?>,
  intro: <?= json_encode($chatIntro,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>,
  userId: <?= (int)$user['id'] ?>,
  activityPollMs: 3000,
  canStemStudio: <?= (has_permission('tracks.manage', $user) || has_permission('track_notes.manage', $user) || has_permission('producer.access', $user)) ? 'true' : 'false' ?>,
  stemStudioBase: <?= json_encode(url('/admin/stems.php')) ?>
};

window.STONEFELLOW_ACTIVITY = {
  endpoint: <?= json_encode(url('/api/agent-activity-v94.php')) ?>,
  csrf: <?= json_encode(csrf_token()) ?>,
  surface: 'chat', trackId: 0, projectId: 0, taskTitle: 'Agent Chat', taskKey: 'chat'
};


window.STONEFELLOW_PLAYER = {
  tracks: <?= json_encode($playerTracksPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
  albums: <?= json_encode($playerAlbumsPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
  playlists: <?= json_encode($playerPlaylistsPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
  merch: <?= json_encode($playerMerchPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
  posts: <?= json_encode($playerPostsPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
  userId: <?= (int)$user['id'] ?>
};
</script>
<link rel="stylesheet" href="<?= e(url('/chat-v100.css?v=100')) ?>">
<script src="<?= e(url('/chat.js?v=101')) ?>"></script>
<script src="<?= e(url('/chat-v100.js?v=100')) ?>"></script>
<script src="<?= e(url('/chat-media-v86.js?v=95')) ?>"></script>
<script src="<?= e(url('/chat-media-v93.js?v=97')) ?>"></script>
<script src="<?= e(url('/agent-activity-v94.js?v=101')) ?>"></script>
<?php if ($teamChatEnabled): ?>
<?php
  $teamChatPageKey = 'agent_chat';
  $teamChatContextLabel = 'Agent Chat';
  require __DIR__ . '/includes/team-chat-widget-v81.php';
?>
<?php endif; ?>
</body>
</html>
