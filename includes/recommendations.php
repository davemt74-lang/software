<?php
declare(strict_types=1);

function track_metadata_text(array $track): string
{
    return trim(implode(' ', [
        (string)($track['title'] ?? ''),
        (string)($track['album'] ?? ''),
        (string)($track['description'] ?? ''),
        (string)($track['genre'] ?? ''),
        (string)($track['mood'] ?? ''),
        (string)($track['energy'] ?? ''),
        (string)($track['keywords'] ?? ''),
        (string)($track['lyrics'] ?? ''),
    ]));
}

function track_query_terms(string $query): array
{
    $terms = preg_split('/[^\pL\pN]+/u', mb_strtolower($query)) ?: [];
    $terms = array_values(array_filter(
        $terms,
        static fn(string $term): bool => mb_strlen($term) >= 2
    ));

    return array_slice(array_values(array_unique($terms)), 0, 20);
}

function track_mood_aliases(): array
{
    return [
        'chill' => ['chill','relax','relaxed','mellow','calm','easy','soft','laidback','laid-back'],
        'sad' => ['sad','melancholy','heartbreak','heartbroken','blue','lonely','reflective'],
        'happy' => ['happy','bright','joy','joyful','uplifting','positive','feelgood','feel-good'],
        'energetic' => ['energetic','energy','upbeat','fast','driving','workout','party','pump'],
        'romantic' => ['romantic','love','intimate','date','affection','warm'],
        'dark' => ['dark','moody','brooding','night','late-night','late','gritty'],
        'acoustic' => ['acoustic','stripped','unplugged','intimate','guitar'],
        'road' => ['road','drive','driving','highway','travel','backroad','backroads'],
    ];
}

function track_detect_moods(string $query): array
{
    $query = mb_strtolower($query);
    $detected = [];

    foreach (track_mood_aliases() as $mood => $aliases) {
        foreach ($aliases as $alias) {
            if (str_contains($query, $alias)) {
                $detected[] = $mood;
                break;
            }
        }
    }

    return array_values(array_unique($detected));
}

function track_is_music_request(string $query): bool
{
    $query = mb_strtolower($query);
    foreach ([
        'song','songs','track','tracks','music','listen','play','playlist',
        'recommend','suggest','mood','next song','next track','what should i hear'
    ] as $needle) {
        if (str_contains($query, $needle)) {
            return true;
        }
    }
    return false;
}

function track_is_playlist_request(string $query): bool
{
    $query = mb_strtolower($query);
    return str_contains($query, 'playlist')
        || str_contains($query, 'mix')
        || str_contains($query, 'set of songs')
        || count(track_detect_moods($query)) > 0;
}

function track_is_next_request(string $query): bool
{
    $query = mb_strtolower($query);
    return str_contains($query, 'next song')
        || str_contains($query, 'next track')
        || str_contains($query, 'what next')
        || str_contains($query, 'play next')
        || str_contains($query, 'suggest the next')
        || str_contains($query, 'recommend the next');
}

function track_available_for_recommendation(?array $user = null): array
{
    $pdo = db();

    if (!$pdo) {
        return array_values(array_filter(fallback_tracks(), static function(array $track) use ($user): bool {
            return can_view_track($track, $user);
        }));
    }

    try {
        $stmt = $pdo->query(
            'SELECT id,title,album,duration,lyrics,description,genre,mood,energy,tempo_bpm,keywords,
                    audio_path,cover_path,visibility,is_published,sort_order
             FROM tracks
             WHERE is_published=1
             ORDER BY sort_order,id'
        );
        $tracks = [];

        foreach ($stmt->fetchAll() as $track) {
            if (can_view_track($track, $user)) {
                $tracks[] = $track;
            }
        }

        return $tracks;
    } catch (Throwable $e) {
        return [];
    }
}

function track_recent_ids_for_user(?array $user, int $limit = 8): array
{
    $pdo = db();
    if (!$pdo || !$user || !table_exists('track_play_sessions')) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT track_id
             FROM track_play_sessions
             WHERE user_id=?
             ORDER BY started_at DESC
             LIMIT ' . max(1, min(30, $limit))
        );
        $stmt->execute([(int)$user['id']]);
        return array_map('intval', array_column($stmt->fetchAll(), 'track_id'));
    } catch (Throwable $e) {
        return [];
    }
}

function track_last_listened(?array $user): ?array
{
    $pdo = db();

    if (!$pdo || !$user || !table_exists('track_play_sessions')) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT t.*
             FROM track_play_sessions s
             JOIN tracks t ON t.id=s.track_id
             WHERE s.user_id=?
             ORDER BY s.started_at DESC
             LIMIT 1'
        );
        $stmt->execute([(int)$user['id']]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function track_token_set(string $value): array
{
    return array_values(array_unique(array_filter(
        preg_split('/[,\s\/|;]+/u', mb_strtolower($value)) ?: []
    )));
}

function track_similarity_score(array $candidate, array $reference): int
{
    $score = 0;

    foreach (['genre'=>5,'mood'=>6,'energy'=>3,'keywords'=>2] as $field => $weight) {
        $a = track_token_set((string)($candidate[$field] ?? ''));
        $b = track_token_set((string)($reference[$field] ?? ''));
        $score += count(array_intersect($a, $b)) * $weight;
    }

    $tempoA = (int)($candidate['tempo_bpm'] ?? 0);
    $tempoB = (int)($reference['tempo_bpm'] ?? 0);
    if ($tempoA > 0 && $tempoB > 0) {
        $difference = abs($tempoA - $tempoB);
        if ($difference <= 10) $score += 4;
        elseif ($difference <= 20) $score += 2;
    }

    return $score;
}

function track_recommendations(
    string $query,
    ?array $user = null,
    int $limit = 5
): array {
    $tracks = track_available_for_recommendation($user);
    if (!$tracks) {
        return [];
    }

    $queryLower = mb_strtolower(trim($query));
    $terms = track_query_terms($query);
    $moods = track_detect_moods($query);
    $recentIds = track_recent_ids_for_user($user, 10);
    $lastTrack = track_last_listened($user);
    $isNext = track_is_next_request($query);
    $isPlaylist = track_is_playlist_request($query);

    $scored = [];

    foreach ($tracks as $track) {
        $haystack = mb_strtolower(track_metadata_text($track));
        $score = 0;

        foreach ($terms as $term) {
            $occurrences = substr_count($haystack, $term);
            $score += min(4, $occurrences);

            if (str_contains(mb_strtolower((string)$track['title']), $term)) {
                $score += 7;
            }
            if (str_contains(mb_strtolower((string)$track['mood']), $term)) {
                $score += 6;
            }
            if (str_contains(mb_strtolower((string)$track['genre']), $term)) {
                $score += 5;
            }
            if (str_contains(mb_strtolower((string)$track['keywords']), $term)) {
                $score += 4;
            }
        }

        foreach ($moods as $mood) {
            $metadata = mb_strtolower(
                (string)$track['mood'] . ' ' .
                (string)$track['energy'] . ' ' .
                (string)$track['keywords'] . ' ' .
                (string)$track['genre']
            );
            if (str_contains($metadata, $mood)) {
                $score += 12;
            }

            foreach (track_mood_aliases()[$mood] ?? [] as $alias) {
                if (str_contains($metadata, $alias)) {
                    $score += 5;
                }
            }
        }

        if ($isNext && $lastTrack && (int)$lastTrack['id'] !== (int)$track['id']) {
            $score += track_similarity_score($track, $lastTrack);
        }

        if (in_array((int)$track['id'], $recentIds, true)) {
            $score -= $isPlaylist ? 1 : 5;
        }

        if ($lastTrack && (int)$lastTrack['id'] === (int)$track['id'] && $isNext) {
            $score -= 50;
        }

        if ($score <= 0 && track_is_music_request($query)) {
            $score = max(1, 5 - (int)($track['sort_order'] ?? 0));
        }

        $track['_score'] = $score;
        $scored[] = $track;
    }

    usort($scored, static function(array $a, array $b): int {
        $byScore = ((int)$b['_score'] <=> (int)$a['_score']);
        if ($byScore !== 0) return $byScore;
        return ((int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0));
    });

    $result = [];
    foreach ($scored as $track) {
        if ((int)$track['_score'] < 1 && !track_is_music_request($query)) {
            continue;
        }

        unset($track['_score']);
        $result[] = $track;

        if (count($result) >= max(1, min(10, $limit))) {
            break;
        }
    }

    return $result;
}

function track_media_payload(array $track): array
{
    $id = (int)$track['id'];

    return [
        'id' => $id,
        'title' => (string)$track['title'],
        'album' => (string)$track['album'],
        'duration' => (string)($track['duration'] ?? ''),
        'description' => (string)($track['description'] ?? ''),
        'genre' => (string)($track['genre'] ?? ''),
        'mood' => (string)($track['mood'] ?? ''),
        'energy' => (string)($track['energy'] ?? ''),
        'tempo_bpm' => (int)($track['tempo_bpm'] ?? 0),
        'keywords' => (string)($track['keywords'] ?? ''),
        'audio' => url('/media.php?track=' . $id . '&type=audio'),
        'cover' => url('/media.php?track=' . $id . '&type=cover'),
        'detail' => url('/track.php?id=' . $id),
    ];
}

function track_media_suggestions(
    string $query,
    ?array $user = null,
    int $limit = 5
): array {
    if (!track_is_music_request($query) && !track_detect_moods($query)) {
        return [];
    }

    return array_map(
        'track_media_payload',
        track_recommendations($query, $user, $limit)
    );
}

function track_playlist_title(string $query): string
{
    $moods = track_detect_moods($query);

    if ($moods) {
        return ucwords(implode(' + ', $moods)) . ' Stonefellow Mix';
    }

    if (track_is_next_request($query)) {
        return 'Recommended Next';
    }

    return 'Stonefellow Picks';
}


function track_media_from_answer(
    string $answer,
    ?array $user = null,
    int $limit = 6
): array {
    $answerLower = mb_strtolower($answer);
    $matches = [];

    foreach (track_available_for_recommendation($user) as $track) {
        $title = trim((string)($track['title'] ?? ''));
        if ($title === '') {
            continue;
        }

        if (str_contains($answerLower, mb_strtolower($title))) {
            $matches[] = track_media_payload($track);
        }

        if (count($matches) >= max(1, min(10, $limit))) {
            break;
        }
    }

    return $matches;
}

function track_merge_media_suggestions(array ...$groups): array
{
    $merged = [];
    $seen = [];

    foreach ($groups as $group) {
        foreach ($group as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $merged[] = $item;
        }
    }

    return $merged;
}
