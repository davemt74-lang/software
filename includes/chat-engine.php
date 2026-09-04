<?php
declare(strict_types=1);

function chat_is_production_query(string $query): bool
{
    $query = mb_strtolower($query);

    foreach ([
        'stem',
        'stems',
        'reaper',
        'mix',
        'mixing',
        'recording',
        'take',
        'takes',
        'vocal',
        'instrumental',
        'drums',
        'bass',
        'guitar',
        'sample rate',
        'bit depth',
        'channel',
        'fx',
        'plugin'
    ] as $needle) {
        if (str_contains($query, $needle)) {
            return true;
        }
    }

    return false;
}

function chat_track_summary_text(
    array $track,
    string $query
): string {
    $parts = [];
    $album = trim(
        (string)($track['album'] ?? '')
    );
    $duration = trim(
        (string)($track['duration'] ?? '')
    );
    $genre = trim(
        (string)($track['genre'] ?? '')
    );
    $mood = trim(
        (string)($track['mood'] ?? '')
    );
    $energy = trim(
        (string)($track['energy'] ?? '')
    );
    $tempo = (int)(
        $track['tempo_bpm'] ?? 0
    );
    $description = trim(
        (string)($track['description'] ?? '')
    );

    if ($album !== '') {
        $parts[] = $album;
    }

    if ($duration !== '') {
        $parts[] = $duration;
    }

    if ($genre !== '') {
        $parts[] = $genre;
    }

    if ($mood !== '') {
        $parts[] = $mood;
    }

    if ($energy !== '') {
        $parts[] = ucfirst($energy)
            . ' energy';
    }

    if ($tempo > 0) {
        $parts[] = $tempo . ' BPM';
    }

    $text =
        (string)($track['title'] ?? 'Track');

    if ($parts) {
        $text .= ' — '
            . implode(' · ', $parts);
    }

    if ($description !== '') {
        $text .= '. ' . $description;
    }

    $queryLower =
        mb_strtolower($query);

    if (
        (
            str_contains($queryLower, 'lyric') ||
            str_contains($queryLower, 'words')
        ) &&
        trim(
            (string)($track['lyrics'] ?? '')
        ) !== ''
    ) {
        $text .= ' Lyrics: '
            . trim(
                (string)$track['lyrics']
            );
    }

    return $text;
}

function chat_allowed_database_context(string $query, array $user): array
{
    $pdo = db();
    if (!$pdo) {
        return [];
    }

    $queryLower = mb_strtolower(trim($query));
    $musicRequest = track_is_music_request($query);
    $productionRequest = chat_is_production_query($query);
    $terms = array_values(array_filter(
        preg_split('/[^\pL\pN]+/u', $queryLower) ?: [],
        static fn(string $term): bool => mb_strlen($term) >= 2
    ));
    $terms = array_slice(array_unique($terms), 0, 8);

    $matches = static function(string $text) use ($terms): bool {
        if (!$terms) return true;
        $text = mb_strtolower($text);
        foreach ($terms as $term) {
            if (str_contains($text, $term)) return true;
        }
        return false;
    };

    $context = [];

    // Tracks: strictly respect the existing "Who can view?" visibility setting.
    try {
        $tracks = artist_workspace_v181_schema_ready($pdo) ? get_tracks() : $pdo->query('SELECT id,title,album,duration,lyrics,description,genre,mood,energy,tempo_bpm,keywords,visibility,is_published FROM tracks WHERE is_published=1 ORDER BY sort_order,id LIMIT 250')->fetchAll();

        foreach ($tracks as $track) {
            if (!can_view_track($track, $user)) {
                continue;
            }

            $text =
                chat_track_summary_text(
                    $track,
                    $query
                );

            if (
                $matches($text) ||
                $musicRequest
            ) {
                $context[] = [
                    'source'=>'database:tracks',
                    'title'=>
                        (string)$track['title'],
                    'text'=>$text,
                ];
            }
        }
    } catch (Throwable $e) {}

    // Published Artist Profile content only; drafts and private workspace data never enter chat context.
    if (artist_workspace_v181_schema_ready($pdo)) {
        foreach (['albums','posts','merch'] as $profileKind) {
            foreach (artist_workspace_v181_public_records($profileKind, $user, 24) as $row) {
                $text='Artist profile '.$profileKind.': '.(string)($row['title']??'');
                if ($profileKind==='albums') $text.='. Release date: '.(string)($row['release_date']??'').'. '.(string)($row['description']??'');
                elseif ($profileKind==='posts') $text.='. '.(string)($row['body']??'');
                else $text.='. '.(string)($row['description']??'').'. Price: $'.number_format(((int)($row['price_cents']??0))/100,2);
                if ($matches($text) || str_contains($queryLower,'artist') || str_contains($queryLower,$profileKind)) $context[]=['source'=>'artist-profile:'.$profileKind,'title'=>(string)($row['title']??'Artist profile'),'text'=>$text];
            }
        }
    }


    // REAPER/stem metadata is only relevant when the user explicitly asks a
    // production question. A normal song/library request should never dump
    // channels, sample rates or raw REAPER metadata into the answer.
    if (
        $productionRequest &&
        has_permission('tracks.manage', $user) &&
        table_exists('track_stems')
    ) {
        try {
            $stems = $pdo->query(
                'SELECT t.title,s.stem_name,s.stem_role,s.channels,s.sample_rate,s.bit_depth,
                        s.duration_seconds,s.start_offset_seconds,s.rpp_fx_summary,
                        p.project_name,p.tempo_bpm,p.time_signature
                 FROM track_stems s
                 JOIN tracks t ON t.id=s.track_id
                 JOIN track_projects p ON p.id=s.project_id
                 WHERE s.is_active=1
                 ORDER BY t.title,s.sort_order
                 LIMIT 300'
            )->fetchAll();

            foreach ($stems as $stem) {
                $text = sprintf(
                    'Production stem for %s: %s. Role: %s. Channels: %s. Sample rate: %s Hz. Bit depth: %s. Duration: %.2f seconds. Start offset: %.2f seconds. REAPER project: %s. Tempo: %s BPM. Time signature: %s. REAPER FX: %s.',
                    $stem['title'],
                    $stem['stem_name'],
                    $stem['stem_role'],
                    $stem['channels'],
                    $stem['sample_rate'],
                    $stem['bit_depth'],
                    $stem['duration_seconds'],
                    $stem['start_offset_seconds'],
                    $stem['project_name'],
                    $stem['tempo_bpm'] ?: 'not set',
                    $stem['time_signature'] ?: 'not set',
                    $stem['rpp_fx_summary'] ?: 'none listed'
                );

                if (
                    $matches($text) ||
                    $productionRequest
                ) {
                    $context[] = [
                        'source'=>'database:track_stems',
                        'title'=>(string)$stem['title'] . ' — ' . (string)$stem['stem_name'],
                        'text'=>$text,
                    ];
                }
            }
        } catch (Throwable $e) {}
    }

    // Public/published show data.
    try {
        $shows = artist_workspace_v181_schema_ready($pdo) ? artist_workspace_v181_public_records('shows', $user, 150) : $pdo->query('SELECT show_date,venue,city,region,notes,ticket_url FROM shows WHERE is_published=1 ORDER BY show_date DESC LIMIT 150')->fetchAll();

        foreach ($shows as $show) {
            $text = sprintf(
                'Show: %s at %s, %s, %s. Notes: %s. Ticket URL: %s.',
                $show['show_date'], $show['venue'], $show['city'], $show['region'], $show['notes'], $show['ticket_url']
            );
            if (
                str_contains($queryLower, 'show') ||
                str_contains($queryLower, 'concert') ||
                str_contains($queryLower, 'live') ||
                (!$musicRequest && $matches($text))
            ) {
                $context[] = [
                    'source'=>'database:shows',
                    'title'=>(string)$show['venue'],
                    'text'=>$text
                ];
            }
        }
    } catch (Throwable $e) {}

    // Site/artist settings are readable to signed-in chat users.
    try {
        $settings = $pdo->query('SELECT setting_key,setting_value FROM settings LIMIT 300')->fetchAll();
        foreach ($settings as $setting) {
            $key = (string)$setting['setting_key'];
            if (preg_match('/(?:password|secret|api[_-]?key|token|credential|private[_-]?key|salt)/i',$key)) {
                continue;
            }
            $text = $key . ': ' . (string)$setting['setting_value'];
            if (
                str_contains($queryLower, 'bio') ||
                str_contains($queryLower, 'artist') ||
                str_contains($queryLower, 'link') ||
                str_contains($queryLower, 'website') ||
                (!$musicRequest && $matches($text))
            ) {
                $context[] = [
                    'source'=>'database:settings',
                    'title'=>$key,
                    'text'=>$text
                ];
            }
        }
    } catch (Throwable $e) {}

    // Supervisor/admin-style data is permission gated.
    if (has_permission('messages.manage', $user)) {
        try {
            $messages = $pdo->query(
                'SELECT name,email,topic,message,created_at
                 FROM contact_messages ORDER BY created_at DESC LIMIT 100'
            )->fetchAll();
            foreach ($messages as $message) {
                $text = sprintf(
                    'Contact message from %s <%s>, topic %s, sent %s: %s',
                    $message['name'], $message['email'], $message['topic'], $message['created_at'], $message['message']
                );
                if ($matches($text) || str_contains($queryLower, 'message') || str_contains($queryLower, 'contact')) {
                    $context[] = ['source'=>'database:contact_messages','title'=>(string)$message['topic'],'text'=>$text];
                }
            }
        } catch (Throwable $e) {}
    }


    if ((has_permission('track_notes.manage',$user)||has_permission('producer.access',$user)||has_permission('tracks.manage',$user)) && table_exists('track_notes')) {
        try {
            $broad=(has_permission('tracks.manage',$user)||has_permission('track_notes.manage',$user))?1:0;
            $noteSql='SELECT n.note,n.created_at,t.title,u.display_name'.(column_exists('track_notes','region_start_seconds')?',n.region_start_seconds,n.region_end_seconds':'').'
                 FROM track_notes n
                 JOIN tracks t ON t.id=n.track_id
                 JOIN users u ON u.id=n.user_id
                 WHERE (?=1 OR t.owner_user_id=? OR t.producer_user_id=?)
                 ORDER BY n.created_at DESC LIMIT 150'
            ;
            $noteStmt=$pdo->prepare($noteSql);$noteStmt->execute([$broad,(int)$user['id'],(int)$user['id']]);$notes=$noteStmt->fetchAll();

            foreach ($notes as $note) {
                $text = sprintf(
                    'Production note for %s%s by %s on %s: %s',
                    $note['title'],
                    isset($note['region_start_seconds'])&&$note['region_start_seconds']!==null?' at '.agent_chat_v101_format_time((float)$note['region_start_seconds']).'–'.agent_chat_v101_format_time((float)$note['region_end_seconds']):'',
                    $note['display_name'],
                    $note['created_at'],
                    $note['note']
                );

                if (
                    str_contains($queryLower, 'note') ||
                    str_contains($queryLower, 'supervisor') ||
                    (
                        $productionRequest &&
                        $matches($text)
                    )
                ) {
                    $context[] = [
                        'source' => 'database:track_notes',
                        'title' => (string)$note['title'],
                        'text' => $text,
                    ];
                }
            }
        } catch (Throwable $e) {}
    }

    if (has_permission('users.manage', $user)) {
        try {
            $users = $pdo->query(
                'SELECT display_name,email,role,is_active,last_login_at,created_at
                 FROM users ORDER BY display_name LIMIT 250'
            )->fetchAll();
            foreach ($users as $row) {
                $text = sprintf(
                    'User: %s <%s>. Role: %s. Active: %s. Last login: %s.',
                    $row['display_name'], $row['email'], $row['role'],
                    (int)$row['is_active'] === 1 ? 'yes' : 'no',
                    $row['last_login_at'] ?: 'never'
                );
                if ($matches($text) || str_contains($queryLower, 'user') || str_contains($queryLower, 'account')) {
                    $context[] = ['source'=>'database:users','title'=>(string)$row['display_name'],'text'=>$text];
                }
            }
        } catch (Throwable $e) {}
    }

    return array_slice($context, 0, 18);
}

function chat_context(string $query, array $user): array
{
    $brainContext = [];
    $brainHistoryIntent = false;

    if (agent_brain_schema_ready()) {
        $brainHistoryIntent = function_exists('agent_brain_v99_history_intent')
            && agent_brain_v99_history_intent($query);
        $brainContext = function_exists('agent_brain_v99_context')
            ? agent_brain_v99_context($user, $query, $brainHistoryIntent ? 16 : 10)
            : agent_brain_context($user, $query, 10);
    }

    // A direct Agent Brain/history question must not be buried behind catalog
    // matches. Put the user's private Brain records first, then supplement them
    // with ordinary authorized Stonefellow data only when useful.
    $context = $brainHistoryIntent
        ? $brainContext
        : chat_allowed_database_context($query, $user);

    if (!$brainHistoryIntent) {
        foreach ($brainContext as $memory) {
            $context[] = $memory;
        }
    }

    if (agent_brain_schema_ready()) {
        $context[] = [
            'source'=>'agent:tools',
            'title'=>'Available Stonefellow tools',
            'text'=>agent_brain_tool_prompt($user),
        ];
    }

    if ($brainHistoryIntent) {
        foreach (array_slice(chat_allowed_database_context($query, $user), 0, 6) as $item) {
            $context[] = $item;
        }
    }

    if (has_permission('knowledge.access', $user)) {
        foreach (search_knowledge($query, $user, $brainHistoryIntent ? 5 : 10) as $row) {
            $context[] = [
                'source' => 'knowledge:' . (int)$row['id'],
                'title' => (string)$row['title'],
                'text' => trim((string)$row['chunk_text']) !== ''
                    ? (string)$row['chunk_text']
                    : trim((string)$row['description']),
            ];
        }
    }

    return array_slice($context, 0, $brainHistoryIntent ? 24 : 28);
}

function chat_local_answer(string $query, array $context): string
{
    if (!$context) {
        return "I couldn't find a matching Stonefellow database or knowledge-base item for that question yet. Try asking about a song, show, artist information, or something that has been added to the knowledge base.";
    }

    if (
        track_is_music_request($query) &&
        !chat_is_production_query($query)
    ) {
        $tracks = array_values(
            array_filter(
                $context,
                static fn(array $item): bool =>
                    (string)($item['source'] ?? '') ===
                    'database:tracks'
            )
        );

        if ($tracks) {
            $lines = [];

            foreach (
                array_slice($tracks, 0, 7)
                as $item
            ) {
                $text = trim(
                    preg_replace(
                        '/\s+/',
                        ' ',
                        (string)$item['text']
                    ) ?? ''
                );

                if (
                    mb_strlen($text) >
                    220
                ) {
                    $text =
                        mb_substr(
                            $text,
                            0,
                            217
                        )
                        . '...';
                }

                $lines[] =
                    '• ' . $text;
            }

            return
                "Here are the Stonefellow tracks available to your account:\n\n"
                . implode(
                    "\n",
                    $lines
                );
        }
    }

    $lines = [];

    foreach (
        array_slice($context, 0, 7)
        as $item
    ) {
        $text = trim(
            preg_replace(
                '/\s+/',
                ' ',
                (string)$item['text']
            ) ?? ''
        );

        if (
            mb_strlen($text) >
            480
        ) {
            $text =
                mb_substr(
                    $text,
                    0,
                    477
                )
                . '...';
        }

        $lines[] =
            '• '
            . (string)$item['title']
            . ': '
            . $text;
    }

    return
        "Here’s what I found in the Stonefellow data available to your account:\n\n"
        . implode(
            "\n\n",
            $lines
        );
}

function chat_remote_answer(string $query, array $history, array $context, array $user): ?string
{
    $result = ai_generate_chat_response($query, $history, $context, $user);

    if (!($result['ok'] ?? false)) {
        return null;
    }

    $answer = trim((string)($result['answer'] ?? ''));
    return $answer !== '' ? $answer : null;
}

function chat_generate_answer(string $query, array $history, array $user): array
{
    $context = chat_context($query, $user);
    $answer = chat_remote_answer($query, $history, $context, $user);
    if ($answer === null) {
        $answer = chat_local_answer($query, $context);
    }

    return ['answer'=>$answer,'context'=>$context];
}
