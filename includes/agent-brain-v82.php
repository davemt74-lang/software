<?php
declare(strict_types=1);

function agent_brain_default_soul_path(): string
{
    return STONEFELLOW_ROOT . '/SOUL.md';
}

function agent_brain_private_root(): string
{
    return STONEFELLOW_ROOT . '/private/agent-brains';
}

function agent_brain_user_dir(int $userId): string
{
    return agent_brain_private_root() . '/' . max(0, $userId);
}

function agent_brain_user_soul_path(int $userId): string
{
    return agent_brain_user_dir($userId) . '/SOUL.md';
}

function agent_brain_default_soul(): string
{
    $path = agent_brain_default_soul_path();
    if (is_file($path)) {
        $text = file_get_contents($path);
        if (is_string($text) && trim($text) !== '') {
            return $text;
        }
    }

    return "# Stonefellow Agent Soul\n\nYou are the user's personal Stonefellow agent. Be conversational, direct, useful and honest. Use only information and tools the current user is authorized to access. Never claim an action happened unless Stonefellow confirms it.\n";
}

function agent_brain_ensure_user_soul(array $user): string
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) {
        return '';
    }

    $dir = agent_brain_user_dir($userId);
    $path = agent_brain_user_soul_path($userId);

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            return '';
        }
        @chmod($dir, 0700);
    }

    if (!is_file($path)) {
        if (file_put_contents($path, agent_brain_default_soul(), LOCK_EX) === false) {
            return '';
        }
        @chmod($path, 0600);
    }

    return $path;
}

function agent_brain_soul(array $user): string
{
    $path = agent_brain_ensure_user_soul($user);
    if ($path === '' || !is_file($path)) {
        return agent_brain_default_soul();
    }

    $text = file_get_contents($path);
    return is_string($text) && trim($text) !== ''
        ? $text
        : agent_brain_default_soul();
}

function agent_brain_save_soul(array $user, string $content): void
{
    $content = trim(str_replace("\r\n", "\n", $content));
    if (mb_strlen($content) < 20) {
        throw new RuntimeException('SOUL.md needs at least a short identity or personality description.');
    }
    if (mb_strlen($content) > 24000) {
        throw new RuntimeException('SOUL.md is limited to 24,000 characters.');
    }

    $path = agent_brain_ensure_user_soul($user);
    if ($path === '') {
        throw new RuntimeException('Could not create the private agent soul file.');
    }

    if (file_put_contents($path, $content . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not save SOUL.md.');
    }
    @chmod($path, 0600);
}

function agent_brain_reset_soul(array $user): string
{
    $content = agent_brain_default_soul();
    agent_brain_save_soul($user, $content);
    return $content;
}

function agent_brain_schema_ready(): bool
{
    return table_exists('agent_chat_archive')
        && table_exists('agent_memory_items');
}

function agent_brain_tools(array $user): array
{
    $tools = [];

    if (has_permission('account.access', $user)) {
        $tools[] = ['key'=>'agent_brain','label'=>'Agent Brain','description'=>'Review memory, recurring themes, tools and your private SOUL.md.','kind'=>'memory','url'=>url('/account.php#agent-brain')];
    }

    if (has_permission('chat.access', $user)) {
        array_push($tools,
            ['key'=>'agent_chat','label'=>'Agent Chat','description'=>'Ask questions and work with the Stonefellow agent.','kind'=>'conversation','url'=>url('/chat.php')],
            ['key'=>'voice_chat','label'=>'Voice Chat','description'=>'Talk with the agent; transcripts enter the same chat and memory history.','kind'=>'conversation','url'=>url('/chat.php')],
            ['key'=>'player','label'=>'Player','description'=>'Play the Stonefellow song catalog available to this account.','kind'=>'media','url'=>url('/chat.php?view=player')],
            ['key'=>'saved_songs','label'=>'Saved Songs','description'=>'Open the user’s saved Stonefellow song catalog.','kind'=>'media','url'=>url('/chat.php?view=saved')],
            ['key'=>'shows','label'=>'Shows','description'=>'Review Stonefellow show and event information.','kind'=>'calendar','url'=>url('/chat.php?view=shows')],
            ['key'=>'music_search','label'=>'Playable Music Search','description'=>'Return playable songs and production stems directly in Agent Chat, including parent-song and Stem Studio actions.','kind'=>'media','url'=>url('/chat.php')],
            ['key'=>'camera_capture','label'=>'Camera + Photo','description'=>'Open one or more browser-visible camera feeds and capture photos inside Agent Chat.','kind'=>'media','url'=>url('/chat.php?media=camera')],
            ['key'=>'video_capture','label'=>'Video Recording','description'=>'Record video from a selected browser-visible camera with microphone audio and save it to the private user media library.','kind'=>'media','url'=>url('/chat.php?media=camera')],
            ['key'=>'voice_recorder','label'=>'Voice Recorder','description'=>'Make a saved standalone audio recording. This is separate from conversational voice transcription.','kind'=>'media','url'=>url('/chat.php')],
            ['key'=>'media_library','label'=>'User Media Library','description'=>'Private user-owned photos, videos and audio recordings captured or uploaded through Stonefellow.','kind'=>'library','url'=>url('/video-editor.php')],
            ['key'=>'video_editor','label'=>'Video Editor','description'=>'Create and edit video projects with timeline drag/trim/split/layer/fade tools, media-library audio, and auditable AI editing.','kind'=>'production','url'=>url('/video-editor.php')]
        );
    }

    if (permission_v105_has('playlists.manage', $user)) {
        $tools[] = ['key'=>'playlists','label'=>'Playlists','description'=>'Create, edit and play playlists available to this account.','kind'=>'library','url'=>url('/chat.php?view=playlists')];
    }

    if (has_permission('artist_listening.access', $user)) {
        $tools[] = ['key'=>'artist_listening','label'=>'My Recordings','description'=>'Capture, transcribe and manage private Artist Listening sessions.','kind'=>'recording','url'=>url('/artist-listening.php')];
    }

    if (booking_agent_available($user)) {
        $tools[] = ['key'=>'booking_agent','label'=>'Booking Agent','description'=>'Use Agent Chat to track shows, rank listener-density markets and research current venue/booking opportunities on the public web.','kind'=>'research','url'=>url('/chat.php')];
    }

    if (has_permission('tracks.manage', $user)) {
        $tools[] = ['key'=>'tracks_manage','label'=>'Track Manager','description'=>'Create and edit tracks and their song metadata.','kind'=>'create','url'=>url('/admin/tracks.php')];
    }

    if (has_permission('tracks.manage', $user) || has_permission('track_notes.manage', $user) || has_permission('producer.access', $user)) {
        $tools[] = ['key'=>'stem_studio','label'=>'Stem Studio','description'=>'Open production projects, stems, mixes and native plugin chains.','kind'=>'production','url'=>url('/admin/stems.php')];
        $tools[] = ['key'=>'studio_agent','label'=>'Studio Agent','description'=>'Create and edit the current Stem Studio session with auditable AI commands for mixer, routing, plugins, automation, recording and persistent project history.','kind'=>'production','url'=>url('/admin/stems.php')];
    }

    if (has_permission('albums.manage', $user)) {
        $tools[] = ['key'=>'albums_manage','label'=>'Albums','description'=>'Create and manage Stonefellow albums and track assignments.','kind'=>'create','url'=>url('/admin/albums.php')];
    }

    if (has_permission('shows.manage', $user)) {
        $tools[] = ['key'=>'shows_manage','label'=>'Show Manager','description'=>'Create and update show dates and ticket information.','kind'=>'create','url'=>url('/admin/shows.php')];
    }

    if (has_permission('knowledge.access', $user)) {
        $tools[] = ['key'=>'knowledge','label'=>'Knowledge Base','description'=>'Search knowledge files and notes available to this account.','kind'=>'knowledge','url'=>has_permission('knowledge.manage', $user) ? url('/admin/knowledge.php') : url('/chat.php')];
    }

    if (has_permission('photos.manage', $user)) {
        $tools[] = ['key'=>'photos','label'=>'Photos','description'=>'Manage Stonefellow photos and visual-library metadata.','kind'=>'create','url'=>url('/admin/photos.php')];
    }

    if (has_permission('posts.manage', $user)) {
        $tools[] = ['key'=>'posts','label'=>'Posts','description'=>'Create and publish Stonefellow artist updates.','kind'=>'create','url'=>url('/admin/posts.php')];
    }

    if (has_permission('merch.manage', $user)) {
        $tools[] = ['key'=>'merch','label'=>'Merch','description'=>'Create and manage Stonefellow merchandise cards and links.','kind'=>'create','url'=>url('/admin/merch.php')];
    }

    if (has_permission('listening.view', $user)) {
        $tools[] = ['key'=>'listening_analytics','label'=>'Listening Analytics','description'=>'Review Stonefellow listening activity and engagement.','kind'=>'analytics','url'=>url('/admin/listening.php')];
    }

    if (has_permission('admin.access', $user) && function_exists('team_chat_role_allowed') && team_chat_role_allowed($user)) {
        $tools[] = ['key'=>'team_chat','label'=>'Online Team Chat','description'=>'Message online managers, producers and supervisors.','kind'=>'communication','url'=>url('/chat.php')];
    }

    return $tools;
}

function agent_brain_tool_prompt(array $user): string
{
    return implode("\n", array_map(
        static fn(array $tool): string => '- ' . $tool['label'] . ': ' . $tool['description'],
        agent_brain_tools($user)
    ));
}

function agent_brain_normalize(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^\pL\pN._-]+/u', ' ', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function agent_brain_archive_message(array $user, int $conversationId, int $sourceMessageId, string $role, string $message, string $inputMode = 'text', ?string $createdAt = null): int
{
    if (!agent_brain_schema_ready()) {
        return 0;
    }

    $pdo = db();
    $userId = (int)($user['id'] ?? 0);
    if (!$pdo || $userId < 1 || $sourceMessageId < 1 || trim($message) === '') {
        return 0;
    }

    $role = in_array($role, ['user', 'assistant'], true) ? $role : 'user';
    $inputMode = $inputMode === 'voice' ? 'voice' : 'text';
    $createdAt = $createdAt ?: date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO agent_chat_archive
         (user_id,conversation_id,source_message_id,role,input_mode,message_text,created_at,archived_at)
         VALUES (?,?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE
           id=LAST_INSERT_ID(id),conversation_id=VALUES(conversation_id),role=VALUES(role),input_mode=VALUES(input_mode),message_text=VALUES(message_text),created_at=VALUES(created_at),archived_at=NOW()'
    );
    $stmt->execute([$userId,max(0,$conversationId),$sourceMessageId,$role,$inputMode,$message,$createdAt]);
    return (int)$pdo->lastInsertId();
}

function agent_brain_store_memory(array $user, string $type, string $subject, string $memoryText, int $archiveId, float $confidence = 0.75, array $metadata = [], ?string $seenAt = null): int
{
    if (!agent_brain_schema_ready()) {
        return 0;
    }

    $pdo = db();
    $userId = (int)($user['id'] ?? 0);
    $type = mb_substr(agent_brain_normalize($type), 0, 40);
    $subject = trim(mb_substr($subject, 0, 190));
    $memoryText = trim(mb_substr($memoryText, 0, 2000));
    if (!$pdo || $userId < 1 || $type === '' || $subject === '' || $memoryText === '') {
        return 0;
    }

    $fingerprint = in_array($type, ['preference','decision','commitment'], true)
        ? $type . '|' . agent_brain_normalize($memoryText)
        : $type . '|' . agent_brain_normalize($subject);
    $hash = sha1($fingerprint);
    $seenAt = $seenAt ?: date('Y-m-d H:i:s');
    $metadataJson = $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

    $stmt = $pdo->prepare(
        'INSERT INTO agent_memory_items
         (user_id,memory_type,subject,memory_text,memory_hash,source_archive_id,confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json)
         VALUES (?,?,?,?,?,?,?,1,?,?,1,?)
         ON DUPLICATE KEY UPDATE
           id=LAST_INSERT_ID(id),memory_text=VALUES(memory_text),source_archive_id=VALUES(source_archive_id),confidence=GREATEST(confidence,VALUES(confidence)),occurrence_count=occurrence_count+1,last_seen_at=GREATEST(last_seen_at,VALUES(last_seen_at)),is_active=1,metadata_json=COALESCE(VALUES(metadata_json),metadata_json)'
    );
    $stmt->execute([$userId,$type,$subject,$memoryText,$hash,$archiveId > 0 ? $archiveId : null,max(0.0,min(1.0,$confidence)),$seenAt,$seenAt,$metadataJson]);
    return (int)$pdo->lastInsertId();
}

function agent_brain_sentences(string $text): array
{
    return array_values(array_filter(
        preg_split('/(?<=[.!?])\s+|\R+/u', trim($text)) ?: [],
        static fn(string $part): bool => mb_strlen(trim($part)) >= 4
    ));
}

function agent_brain_extract_files(string $text): array
{
    preg_match_all('/(?<![\w.-])(?:[A-Za-z]:[\\\\\/][^\s<>:"|?*]+|(?:\.\.?[\\\\\/])?[^\s<>:"|?*\\\\\/]+\.(?:rpp|wav|mp3|m4a|ogg|flac|zip|pdf|docx?|xlsx?|csv|txt|md|json|php|js|css|html?|png|jpe?g|webp))(?![\w.-])/iu', $text, $matches);
    return array_values(array_unique(array_map('trim', $matches[0] ?? [])));
}

function agent_brain_extract_dates(string $text): array
{
    $patterns = [
        '/\b\d{4}-\d{1,2}-\d{1,2}\b/u',
        '/\b\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}\b/u',
        '/\b(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{4})?\b/iu',
        '/\b(?:today|tomorrow|tonight|next\s+(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|week|month)|this\s+(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|week|month)|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b/iu',
    ];
    $found = [];
    foreach ($patterns as $pattern) {
        preg_match_all($pattern, $text, $matches);
        foreach ($matches[0] ?? [] as $match) {
            $found[] = trim($match);
        }
    }
    return array_values(array_unique($found));
}

function agent_brain_theme_terms(string $text): array
{
    $stop = array_flip([
        'the','and','that','this','with','from','have','has','had','you','your','our','for','are','was','were','will','would','should','could','can','cant','not','but','about','into','then','than','when','where','what','who','why','how','they','them','their','there','here','just','also','need','want','like','make','made','more','some','any','all','get','got','use','using','used','add','now','new','user','users','stonefellow','agent','chat','please','lets','let','we','i','me','my','it','its','to','of','in','on','at','as','is','be','or','a','an'
    ]);
    $words = preg_split('/[^\pL\pN_-]+/u', mb_strtolower($text)) ?: [];
    $counts = [];
    foreach ($words as $word) {
        $word = trim($word);
        if (mb_strlen($word) < 4 || isset($stop[$word]) || is_numeric($word)) {
            continue;
        }
        $counts[$word] = ($counts[$word] ?? 0) + 1;
    }
    arsort($counts);
    return array_slice(array_keys($counts), 0, 6);
}

function agent_brain_extract_memories(array $user, int $archiveId, string $text, ?string $seenAt = null): void
{
    if (!agent_brain_schema_ready() || trim($text) === '') {
        return;
    }

    foreach (agent_brain_extract_files($text) as $file) {
        agent_brain_store_memory($user, 'file', $file, 'Referenced file: ' . $file, $archiveId, 0.96, ['file'=>$file], $seenAt);
    }

    foreach (agent_brain_extract_dates($text) as $dateText) {
        $timestamp = strtotime($dateText);
        $metadata = ['literal'=>$dateText];
        if ($timestamp !== false) {
            $metadata['normalized_date'] = date('Y-m-d', $timestamp);
        }
        agent_brain_store_memory($user, 'date', $dateText, 'Referenced date: ' . $dateText, $archiveId, 0.86, $metadata, $seenAt);
    }

    foreach (agent_brain_sentences($text) as $sentence) {
        $lower = mb_strtolower($sentence);
        if (preg_match('/\b(?:i prefer|i like|i love|i hate|i dislike|my preference|i usually|i always)\b/u', $lower)) {
            agent_brain_store_memory($user, 'preference', mb_strimwidth($sentence, 0, 120, '…'), $sentence, $archiveId, 0.88, [], $seenAt);
        }
        if (preg_match('/\b(?:we decided|i decided|decision is|we are going to|we will use|use the|keep the|remove the)\b/u', $lower)) {
            agent_brain_store_memory($user, 'decision', mb_strimwidth($sentence, 0, 120, '…'), $sentence, $archiveId, 0.78, [], $seenAt);
        }
        if (preg_match('/\b(?:remind me|deadline|due|need to|have to|must|by (?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)|promise|follow up)\b/u', $lower)) {
            agent_brain_store_memory($user, 'commitment', mb_strimwidth($sentence, 0, 120, '…'), $sentence, $archiveId, 0.80, [], $seenAt);
        }
    }

    foreach (agent_brain_theme_terms($text) as $theme) {
        agent_brain_store_memory($user, 'theme', $theme, 'Recurring conversation theme: ' . $theme, $archiveId, 0.62, [], $seenAt);
    }
}

function agent_brain_archive_and_parse(array $user, int $conversationId, int $sourceMessageId, string $role, string $message, string $inputMode = 'text', ?string $createdAt = null): int
{
    $archiveId = agent_brain_archive_message($user, $conversationId, $sourceMessageId, $role, $message, $inputMode, $createdAt);
    if ($archiveId > 0 && $role === 'user') {
        agent_brain_extract_memories($user, $archiveId, $message, $createdAt);
    }
    return $archiveId;
}

function agent_brain_backfill_user(array $user, int $limit = 500): int
{
    if (!agent_brain_schema_ready()) {
        return 0;
    }
    $pdo = db();
    $userId = (int)($user['id'] ?? 0);
    if (!$pdo || $userId < 1) {
        return 0;
    }

    $limit = max(1, min(2000, $limit));
    $sql = "SELECT m.id,m.conversation_id,m.role,m.message,m.created_at
            FROM chat_messages m
            JOIN chat_conversations c ON c.id=m.conversation_id
            WHERE c.user_id=?
            ORDER BY m.id DESC
            LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = array_reverse($stmt->fetchAll());
    $count = 0;
    foreach ($rows as $row) {
        $id = agent_brain_archive_and_parse(
            $user,
            (int)$row['conversation_id'],
            (int)$row['id'],
            (string)$row['role'],
            (string)$row['message'],
            'text',
            (string)$row['created_at']
        );
        if ($id > 0) {
            $count++;
        }
    }
    return $count;
}

function agent_brain_context(array $user, string $query, int $limit = 10): array
{
    if (!agent_brain_schema_ready()) {
        return [];
    }
    $pdo = db();
    $userId = (int)($user['id'] ?? 0);
    if (!$pdo || $userId < 1) {
        return [];
    }

    $terms = array_values(array_filter(
        preg_split('/[^\pL\pN._-]+/u', agent_brain_normalize($query)) ?: [],
        static fn(string $term): bool => mb_strlen($term) >= 3
    ));
    $terms = array_slice(array_unique($terms), 0, 6);
    $params = [$userId];
    $where = 'user_id=? AND is_active=1';
    if ($terms) {
        $parts = [];
        foreach ($terms as $term) {
            $parts[] = '(subject LIKE ? OR memory_text LIKE ?)';
            $like = '%' . $term . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $where .= ' AND (' . implode(' OR ', $parts) . ')';
    }
    $limit = max(1, min(30, $limit));
    $stmt = $pdo->prepare(
        "SELECT memory_type,subject,memory_text,occurrence_count,last_seen_at,confidence
         FROM agent_memory_items
         WHERE {$where}
         ORDER BY occurrence_count DESC,last_seen_at DESC,id DESC
         LIMIT {$limit}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (!$rows && $terms) {
        $stmt = $pdo->prepare(
            "SELECT memory_type,subject,memory_text,occurrence_count,last_seen_at,confidence
             FROM agent_memory_items
             WHERE user_id=? AND is_active=1
             ORDER BY last_seen_at DESC,id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
    }

    return array_map(static function(array $row): array {
        return [
            'source'=>'agent-memory:' . (string)$row['memory_type'],
            'title'=>(string)$row['subject'],
            'text'=>(string)$row['memory_text'] . ' (seen ' . (int)$row['occurrence_count'] . ' time' . ((int)$row['occurrence_count'] === 1 ? '' : 's') . '; last ' . (string)$row['last_seen_at'] . ')',
        ];
    }, $rows);
}

function agent_brain_summary(array $user): array
{
    $summary = ['archive_count'=>0,'memory_count'=>0,'themes'=>[],'dates'=>[],'files'=>[],'recent'=>[]];
    if (!agent_brain_schema_ready()) {
        return $summary;
    }
    $pdo = db();
    $userId = (int)($user['id'] ?? 0);
    if (!$pdo || $userId < 1) {
        return $summary;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM agent_chat_archive WHERE user_id=?');
    $stmt->execute([$userId]);
    $summary['archive_count'] = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM agent_memory_items WHERE user_id=? AND is_active=1');
    $stmt->execute([$userId]);
    $summary['memory_count'] = (int)$stmt->fetchColumn();

    foreach (['theme'=>'themes','date'=>'dates','file'=>'files'] as $type=>$key) {
        $stmt = $pdo->prepare(
            'SELECT subject,memory_text,occurrence_count,last_seen_at
             FROM agent_memory_items
             WHERE user_id=? AND memory_type=? AND is_active=1
             ORDER BY occurrence_count DESC,last_seen_at DESC
             LIMIT 10'
        );
        $stmt->execute([$userId,$type]);
        $summary[$key] = $stmt->fetchAll();
    }

    $stmt = $pdo->prepare(
        "SELECT memory_type,subject,memory_text,occurrence_count,last_seen_at
         FROM agent_memory_items
         WHERE user_id=? AND is_active=1 AND memory_type NOT IN ('theme','date','file')
         ORDER BY last_seen_at DESC,id DESC LIMIT 12"
    );
    $stmt->execute([$userId]);
    $summary['recent'] = $stmt->fetchAll();
    return $summary;
}
