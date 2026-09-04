<?php
declare(strict_types=1);

/** Stonefellow v84 executable Agent Brain tools. */
function agent_tool_history_ready(): bool
{
    return table_exists('agent_tool_history');
}

function agent_tool_log(array $user, string $toolKey, string $requestText, string $status, array $result = [], ?int $conversationId = null): void
{
    if (!agent_tool_history_ready()) return;
    $pdo = db();
    $userId = (int)($user['id'] ?? 0);
    if (!$pdo || $userId < 1) return;
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO agent_tool_history (user_id,conversation_id,tool_key,request_text,status,result_json,created_at) VALUES (?,?,?,?,?,?,NOW())'
        );
        $stmt->execute([
            $userId,
            $conversationId && $conversationId > 0 ? $conversationId : null,
            mb_substr($toolKey, 0, 80),
            mb_substr($requestText, 0, 4000),
            mb_substr($status, 0, 30),
            json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {}
}

function agent_tool_can_studio(array $track, array $user): bool
{
    return has_permission('tracks.manage', $user)
        || has_permission('track_notes.manage', $user)
        || can_manage_track_production($track, $user);
}

function agent_tool_query_terms(string $query): array
{
    $stop = array_flip([
        'show','find','search','play','open','give','tell','about','some','me','my','the','a','an','in','on','for','of','to','with',
        'line','lines','part','parts','stem','stems','track','tracks','song','songs','please','project','editor','studio',
        'bass','bassline','drum','drums','beat','beats','percussion','guitar','guitars','riff','riffs',
        'vocal','vocals','voice','voices','keys','piano','keyboard','synth','synthesizer',
        'and','or','all','production','instrument','instruments','instrumental','multitrack','multitracks','multi'
    ]);
    $terms = preg_split('/[^\pL\pN_-]+/u', mb_strtolower($query)) ?: [];
    return array_slice(array_values(array_unique(array_filter($terms, static fn(string $term): bool => mb_strlen($term) >= 2 && !isset($stop[$term])))), 0, 12);
}

function agent_tool_stem_role_map(): array
{
    return [
        'Bass'=>['bass','bassline','bass line'],
        'Drums'=>['drum','drums','beat','beats','drum kit'],
        'Percussion'=>['percussion','shaker','tambourine','conga','congas'],
        'Guitar'=>['guitar','guitars','riff','riffs'],
        'Vocal'=>['vocal','vocals','voice','voices','singer'],
        'Keys'=>['keys','piano','keyboard','keyboards'],
        'Synth'=>['synth','synths','synthesizer','synthesizers'],
    ];
}

function agent_tool_detect_stem_roles(string $query): array
{
    $q = mb_strtolower($query);
    $roles = [];
    foreach (agent_tool_stem_role_map() as $role=>$aliases) {
        foreach ($aliases as $alias) {
            if (str_contains($q, $alias)) {
                $roles[] = $role;
                break;
            }
        }
    }
    return array_values(array_unique($roles));
}

function agent_tool_detect_stem_role(string $query): string
{
    $roles = agent_tool_detect_stem_roles($query);
    return $roles[0] ?? '';
}

function agent_tool_can_search_production(array $user): bool
{
    return has_permission('tracks.manage', $user)
        || has_permission('track_notes.manage', $user)
        || has_permission('producer.access', $user)
        || in_array((string)($user['role'] ?? ''), ['manager','producer','supervisor','admin'], true);
}

function agent_tool_find_track(string $query, array $user): ?array
{
    $pdo = db();
    if (!$pdo) return null;
    $terms = agent_tool_query_terms($query);
    try {
        $rows = $pdo->query('SELECT * FROM tracks ORDER BY updated_at DESC,id DESC LIMIT 300')->fetchAll();
    } catch (Throwable $e) {
        return null;
    }
    $best = null;
    $bestScore = 0;
    foreach ($rows as $track) {
        if (!can_view_track($track, $user) && !agent_tool_can_studio($track, $user)) continue;
        $title = mb_strtolower((string)$track['title']);
        $haystack = mb_strtolower(trim(implode(' ', [
            (string)$track['title'], (string)$track['album'], (string)$track['genre'], (string)$track['mood'], (string)$track['keywords']
        ])));
        $score = 0;
        if ($title !== '' && str_contains(mb_strtolower($query), $title)) $score += 50;
        foreach ($terms as $term) {
            if (str_contains($title, $term)) $score += 12;
            elseif (str_contains($haystack, $term)) $score += 3;
        }
        if ($score > $bestScore) {
            $best = $track;
            $bestScore = $score;
        }
    }
    return $bestScore > 0 ? $best : null;
}

function agent_tool_search_stems(string $query, array $user, int $limit = 8): array
{
    $pdo = db();
    if (!$pdo || !table_exists('track_stems')) return [];
    $roles = agent_tool_detect_stem_roles($query);
    $roleLookup = array_fill_keys(array_map('mb_strtolower', $roles), true);
    $terms = agent_tool_query_terms($query);
    try {
        $rows = $pdo->query(
            "SELECT s.id AS stem_id,s.track_id,s.stem_name,s.stem_role,s.duration_seconds,s.rpp_fx_summary,
                    t.*,p.project_name,p.tempo_bpm AS project_tempo,p.time_signature
             FROM track_stems s
             INNER JOIN tracks t ON t.id=s.track_id
             LEFT JOIN track_projects p ON p.track_id=t.id
             WHERE s.is_active=1
             ORDER BY t.updated_at DESC,s.sort_order,s.id
             LIMIT 700"
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    $scored = [];
    foreach ($rows as $row) {
        if (!agent_tool_can_studio($row, $user)) continue;
        $stemRole = trim((string)($row['stem_role'] ?? ''));
        if ($roles && !isset($roleLookup[mb_strtolower($stemRole)])) continue;
        $haystack = mb_strtolower(trim(implode(' ', [
            (string)$row['stem_name'], $stemRole, (string)$row['title'], (string)$row['album'],
            (string)$row['genre'], (string)$row['mood'], (string)$row['energy'], (string)$row['keywords'],
            (string)$row['description'], (string)$row['rpp_fx_summary']
        ])));
        $score = $roles ? 25 : 1;
        foreach ($terms as $term) {
            if (str_contains(mb_strtolower((string)$row['stem_name']), $term)) $score += 12;
            elseif (str_contains(mb_strtolower((string)$row['genre']), $term)) $score += 10;
            elseif (str_contains(mb_strtolower((string)$row['keywords']), $term)) $score += 8;
            elseif (str_contains($haystack, $term)) $score += 3;
        }
        if ($terms && $score <= ($roles ? 25 : 1)) continue;
        $row['_score'] = $score;
        $scored[] = $row;
    }
    usort($scored, static fn(array $a, array $b): int => ((int)$b['_score'] <=> (int)$a['_score']));

    $out = [];
    foreach (array_slice($scored, 0, max(1, min(12, $limit))) as $row) {
        $trackId = (int)$row['track_id'];
        $out[] = [
            'kind'=>'stem',
            'stem_id'=>(int)$row['stem_id'],
            'stem_name'=>(string)$row['stem_name'],
            'role'=>(string)$row['stem_role'],
            'track_id'=>$trackId,
            'song'=>(string)$row['title'],
            'album'=>(string)($row['album'] ?: 'Stonefellow'),
            'genre'=>(string)$row['genre'],
            'mood'=>(string)$row['mood'],
            'tempo_bpm'=>(float)($row['project_tempo'] ?: $row['tempo_bpm'] ?: 0),
            'duration'=>(float)$row['duration_seconds'],
            'fx'=>(string)$row['rpp_fx_summary'],
            'audio'=>url('/stem-media-v34.php?id=' . (int)$row['stem_id']),
            'cover'=>url('/media.php?track=' . $trackId . '&type=cover'),
            'song_audio'=>url('/media.php?track=' . $trackId . '&type=audio'),
            'song_detail'=>url('/track.php?id=' . $trackId),
            'studio'=>url('/admin/stems.php?track=' . $trackId),
        ];
    }
    return $out;
}

function booking_agent_available(array $user): bool
{
    return has_permission('shows.manage', $user)
        || has_permission('listening.view', $user);
}

function booking_agent_listener_markets(array $user, int $limit = 12): array
{
    $pdo = db();
    if (!$pdo || !table_exists('track_play_sessions') || !column_exists('track_play_sessions','listener_city')) return [];
    $userId = (int)($user['id'] ?? 0);
    $global = has_permission('tracks.manage', $user) ? 1 : 0;
    try {
        $stmt = $pdo->prepare(
            "SELECT
                    CASE
                      WHEN TRIM(COALESCE(s.listener_city,''))<>'' THEN s.listener_city
                      ELSE CONCAT('Area ',ROUND(s.listener_latitude,1),', ',ROUND(s.listener_longitude,1))
                    END AS city,
                    CASE
                      WHEN TRIM(COALESCE(s.listener_city,''))<>'' THEN s.listener_region
                      ELSE ''
                    END AS region,
                    s.listener_country AS country,
                    COUNT(*) AS starts,
                    SUM(CASE WHEN s.qualified_play=1 THEN 1 ELSE 0 END) AS qualified_plays,
                    COUNT(DISTINCT s.listener_hash) AS listeners,
                    SUM(s.listened_seconds) AS listened_seconds
             FROM track_play_sessions s
             INNER JOIN tracks t ON t.id=s.track_id
             WHERE s.started_at>=DATE_SUB(NOW(),INTERVAL 90 DAY)
               AND (
                    TRIM(COALESCE(s.listener_city,''))<>''
                    OR (s.listener_latitude IS NOT NULL AND s.listener_longitude IS NOT NULL)
               )
               AND (?=1 OR t.owner_user_id=? OR t.producer_user_id=?)
             GROUP BY city,region,s.listener_country
             ORDER BY listeners DESC,qualified_plays DESC,listened_seconds DESC
             LIMIT " . max(1,min(30,$limit))
        );
        $stmt->execute([$global,$userId,$userId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function booking_agent_user_shows(array $user, int $limit = 20): array
{
    $pdo = db();
    if (!$pdo || !table_exists('shows')) return [];
    $userId = (int)($user['id'] ?? 0);
    try {
        if (column_exists('shows','owner_user_id')) {
            $stmt = $pdo->prepare(
                'SELECT * FROM shows WHERE show_date>=NOW() AND (owner_user_id=? OR owner_user_id IS NULL) ORDER BY show_date ASC LIMIT ' . max(1,min(50,$limit))
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        }
        return $pdo->query('SELECT * FROM shows WHERE show_date>=NOW() ORDER BY show_date ASC LIMIT ' . max(1,min(50,$limit)))->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function booking_agent_market_suggestions(array $user, int $limit = 8): array
{
    $markets = booking_agent_listener_markets($user, 20);
    $shows = booking_agent_user_shows($user, 50);
    $showMarkets = [];
    foreach ($shows as $show) {
        $key = mb_strtolower(trim((string)$show['city'] . '|' . (string)$show['region']));
        if ($key !== '|') $showMarkets[$key] = ($showMarkets[$key] ?? 0) + 1;
    }
    $out = [];
    foreach ($markets as $market) {
        $key = mb_strtolower(trim((string)$market['city'] . '|' . (string)$market['region']));
        $listeners = (int)$market['listeners'];
        $qualified = (int)$market['qualified_plays'];
        $recentShows = (int)($showMarkets[$key] ?? 0);
        $score = ($listeners * 5) + ($qualified * 2) - ($recentShows * 8);
        $market['score'] = $score;
        $market['recent_shows'] = $recentShows;
        $market['reason'] = $recentShows > 0
            ? 'Strong listener density; already represented in the current show calendar.'
            : 'Strong listener density with no upcoming show currently listed in this market.';
        $out[] = $market;
    }
    usort($out, static fn(array $a,array $b): int => ((int)$b['score'] <=> (int)$a['score']));
    return array_slice($out,0,max(1,min(20,$limit)));
}

function booking_agent_search_location(string $query): string
{
    if (preg_match('/\b(?:in|near|around)\s+([A-Za-z][A-Za-z .\'-]{1,80}(?:,\s*[A-Za-z]{2,30})?)/i', $query, $m)) {
        $value = trim($m[1]);
        $value = preg_replace('/\s+(?:for|that|with|where|and)\b.*$/i','',$value) ?? $value;
        return trim($value);
    }
    return '';
}

function booking_agent_web_search(string $query, int $limit = 8): array
{
    if (!function_exists('curl_init')) return [];
    $url = 'https://html.duckduckgo.com/html/?q=' . rawurlencode($query);
    $curl = curl_init($url);
    curl_setopt_array($curl,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>4,
        CURLOPT_TIMEOUT=>9,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_MAXREDIRS=>3,
        CURLOPT_USERAGENT=>'StonefellowBookingAgent/1.0 (+https://stonefellow.com)',
        CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml'],
    ]);
    $html = curl_exec($curl);
    $status = (int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($html) || $status < 200 || $status >= 400) return [];

    $results = [];
    if (class_exists('DOMDocument')) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($html);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);
        foreach ($xp->query("//a[contains(@class,'result__a')]") ?: [] as $node) {
            if (count($results) >= max(1,min(12,$limit))) break;
            $title = trim($node->textContent ?? '');
            $href = trim($node->getAttribute('href'));
            if ($title === '' || $href === '') continue;
            if (str_starts_with($href,'//')) $href='https:'.$href;
            if (str_contains($href,'duckduckgo.com/l/?')) {
                $parts=parse_url($href); parse_str((string)($parts['query']??''),$params);
                if (!empty($params['uddg'])) $href=(string)$params['uddg'];
            }
            if (!preg_match('#^https?://#i',$href)) continue;
            $results[]=['title'=>$title,'url'=>$href,'snippet'=>''];
        }
    }
    return $results;
}

function booking_agent_opportunities(array $user, int $limit = 60): array
{
    $pdo = db();
    if (!$pdo || !table_exists('booking_agent_opportunities')) return [];
    try {
        $stmt = $pdo->prepare(
            'SELECT id,title,venue,city,region,source_url,status,notes,created_at,updated_at
             FROM booking_agent_opportunities
             WHERE user_id=?
             ORDER BY FIELD(status,\'booked\',\'contacted\',\'submitted\',\'researching\',\'lead\',\'hold\',\'passed\'),updated_at DESC,id DESC
             LIMIT ' . max(1,min(200,$limit))
        );
        $stmt->execute([(int)$user['id']]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function booking_agent_research_history(array $user, int $limit = 20): array
{
    $pdo = db();
    if (!$pdo || !table_exists('booking_agent_research')) return [];
    try {
        $stmt = $pdo->prepare(
            'SELECT id,query_text,market_label,result_json,created_at
             FROM booking_agent_research
             WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT ' . max(1,min(100,$limit))
        );
        $stmt->execute([(int)$user['id']]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $decoded = json_decode((string)($row['result_json'] ?? ''), true);
            $row['result_count'] = is_array($decoded) ? count($decoded) : 0;
        }
        unset($row);
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function booking_agent_store_research(array $user, string $query, string $market, array $results): void
{
    if (!table_exists('booking_agent_research')) return;
    $pdo=db(); if(!$pdo) return;
    try {
        $stmt=$pdo->prepare('INSERT INTO booking_agent_research (user_id,query_text,market_label,result_json,created_at) VALUES (?,?,?,?,NOW())');
        $stmt->execute([(int)$user['id'],mb_substr($query,0,500),mb_substr($market,0,190),json_encode($results,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    } catch(Throwable $e) {}
}

function agent_tool_execute_query(string $query, array $user, int $conversationId = 0): array
{
    $q = mb_strtolower(trim($query));
    $empty = ['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];

    $cameraIndex = 0;
    if (preg_match('/\bcamera\s*(?:#|number)?\s*(\d{1,2})\b/i', $query, $cameraMatch)) {
        $cameraIndex = max(0, min(4, (int)$cameraMatch[1]));
    }

    $videoEditorIntent = (bool)preg_match('/\b(?:open|start|launch)\b.*\bvideo editor\b|\bvideo editor\b.*\b(?:open|start|launch)\b/i', $query);
    if ($videoEditorIntent) {
        $result=$empty;
        $result['handled']=true;
        $result['answer']='Opening your Video Editor. Your saved photos, videos, voice recordings and available Stonefellow songs can be added directly to the timeline.';
        $result['actions'][]=['type'=>'open_url','label'=>'Open Video Editor','url'=>url('/video-editor.php'),'auto'=>true];
        agent_tool_log($user,'video_editor.open',$query,'success',[], $conversationId);
        return $result;
    }

    $pushToEditor = (bool)preg_match('/\b(?:add|push|send|put)\b.*\b(?:latest|last|that|it|photo|video|recording|audio)\b.*\b(?:video )?editor\b/i', $query);
    if ($pushToEditor && media_studio_schema_ready()) {
        $types=[];
        if (preg_match('/\b(?:photo|picture|image)\b/i',$query)) $types=['photo'];
        elseif (preg_match('/\bvideo\b/i',$query)) $types=['video'];
        elseif (preg_match('/\b(?:audio|voice|recording)\b/i',$query)) $types=['audio'];
        $latest=media_studio_latest_asset($user,$types);
        $result=$empty;
        $result['handled']=true;
        if ($latest) {
            $payload=media_studio_asset_payload($latest);
            $result['answer']='Opening the latest matching media item in the Video Editor.';
            $result['actions'][]=['type'=>'open_url','label'=>'Open in Video Editor','url'=>$payload['editor_url'],'auto'=>true];
            agent_tool_log($user,'video_editor.add_latest',$query,'success',['asset_id'=>(int)$latest['id']],$conversationId);
        } else {
            $result['answer']='There is no matching saved media item in your library yet.';
            agent_tool_log($user,'video_editor.add_latest',$query,'empty',[], $conversationId);
        }
        return $result;
    }

    $photoIntent = (bool)preg_match('/\b(?:take|capture|snap|shoot)\b.*\b(?:photo|picture|image)\b|\b(?:photo|picture)\b.*\b(?:take|capture|snap|shoot)\b/i',$query);
    if ($photoIntent) {
        $result=$empty;
        $result['handled']=true;
        $result['answer']=$cameraIndex > 0
            ? 'Opening Camera ' . $cameraIndex . ' and preparing a photo capture.'
            : 'Opening your camera feeds. If only one camera is connected I will capture from it; with multiple cameras, choose the feed you want.';
        $result['actions'][]=['type'=>'media_capture','mode'=>'photo','camera_index'=>$cameraIndex,'label'=>'Open Camera / Take Photo','auto'=>true];
        agent_tool_log($user,'camera.photo',$query,'success',['camera_index'=>$cameraIndex],$conversationId);
        return $result;
    }

    $videoCaptureIntent = (bool)preg_match('/\b(?:record|capture|shoot|make|start)\b.*\bvideo\b|\bvideo recording\b/i',$query);
    if ($videoCaptureIntent) {
        $result=$empty;
        $result['handled']=true;
        $result['answer']=$cameraIndex > 0
            ? 'Opening Camera ' . $cameraIndex . ' and starting a video recording.'
            : 'Opening your camera feeds for video recording. With one camera I can start it automatically; with multiple cameras choose the feed you want.';
        $result['actions'][]=['type'=>'media_capture','mode'=>'video','camera_index'=>$cameraIndex,'label'=>'Open Camera / Record Video','auto'=>true];
        agent_tool_log($user,'camera.video',$query,'success',['camera_index'=>$cameraIndex],$conversationId);
        return $result;
    }

    $audioRecordIntent = (bool)preg_match('/\b(?:record|make|start|capture)\b.*\b(?:voice|audio|memo|recording)\b|\bvoice memo\b/i',$query);
    if ($audioRecordIntent) {
        $result=$empty;
        $result['handled']=true;
        $result['answer']='Starting a standalone voice recording. This saves the microphone audio itself in your private media library; it is separate from Agent Chat transcription.';
        $result['actions'][]=['type'=>'media_capture','mode'=>'audio','camera_index'=>0,'label'=>'Start Voice Recording','auto'=>true];
        agent_tool_log($user,'voice_recorder.start',$query,'success',[], $conversationId);
        return $result;
    }

    $cameraIntent = (bool)preg_match('/\b(?:open|show|start|use|enable)\b.*\b(?:camera|cameras|webcam|capture device)\b|\b(?:camera|cameras|webcam)\b.*\b(?:open|show|start|use|enable)\b/i',$query);
    if ($cameraIntent) {
        $result=$empty;
        $result['handled']=true;
        $result['answer']='Opening the browser-visible camera inputs available to this computer. USB cameras and HDMI capture devices can appear as separate feeds.';
        $result['actions'][]=['type'=>'media_capture','mode'=>'camera','camera_index'=>$cameraIndex,'label'=>'Open Cameras','auto'=>true];
        agent_tool_log($user,'camera.open',$query,'success',['camera_index'=>$cameraIndex],$conversationId);
        return $result;
    }

    $wantsStudio = (bool)preg_match('/\bopen\b.*\b(stem|studio|project|editor|mixer)\b|\b(stem|studio|project|editor|mixer)\b.*\bopen\b/i',$query);
    if ($wantsStudio) {
        $track = agent_tool_find_track($query,$user);
        if ($track && agent_tool_can_studio($track,$user)) {
            $result=$empty;
            $result['handled']=true;
            $result['answer']='Opening the Stem Studio project for ' . (string)$track['title'] . '.';
            $result['actions'][]=['type'=>'open_url','label'=>'Open Stem Studio','url'=>url('/admin/stems.php?track='.(int)$track['id']),'auto'=>true];
            agent_tool_log($user,'stem_studio.open',$query,'success',$result,$conversationId);
            return $result;
        }
    }

    $roles = agent_tool_detect_stem_roles($query);
    $productionSearchIntent = (bool)preg_match(
        '/\b(?:stem|stems|part|parts|instrument|instruments|instrumental|multitrack|multitracks|bass|bassline|drum|drums|beat|beats|percussion|guitar|guitars|riff|riffs|vocal|vocals|keys|piano|keyboard|synth|synths|synthesizer)\b/i',
        $query
    );
    $productionSearchVerb = (bool)preg_match('/\b(?:show|find|search|give|list|play|return|browse)\b/i', $query);
    $wantsStemSearch = agent_tool_can_search_production($user)
        && $productionSearchIntent
        && ($productionSearchVerb || $roles);

    if ($wantsStemSearch) {
        $stems = agent_tool_search_stems($query,$user,10);
        $result=$empty;
        $result['handled']=true;
        $result['stem_media']=$stems;
        $roleLabel = $roles
            ? implode(' + ', array_map('mb_strtolower', $roles))
            : 'production';
        $result['answer']=$stems
            ? 'I found ' . count($stems) . ' matching ' . $roleLabel . ' stem' . (count($stems)===1?'':'s') . '. Each result shows the parent song, lets you preview the stem, play the full song, or open the project in Stem Studio.'
            : 'I could not find matching ' . $roleLabel . ' stems in the production library available to your account.';
        agent_tool_log($user,'stem_library.search',$query,$stems?'success':'empty',['count'=>count($stems),'roles'=>$roles],$conversationId);
        return $result;
    }

    $bookingIntent = preg_match('/\b(book|booking|venue|venues|gig|gigs|tour|touring|listener density|market|markets|booking opportunit|live opportunit)\b/i',$query);
    if ($bookingIntent && booking_agent_available($user)) {
        $suggestions=booking_agent_market_suggestions($user,8);
        $shows=booking_agent_user_shows($user,12);
        $location=booking_agent_search_location($query);
        $wantsWeb=(bool)preg_match('/\b(search|find|research|opportunit|venue|venues|book)\b/i',$query);
        $web=[];
        if($wantsWeb){
            $searchMarket=$location;
            if($searchMarket==='' && $suggestions){
                $candidate=trim((string)$suggestions[0]['city'].', '.(string)$suggestions[0]['region'],', ');
                if (!str_starts_with($candidate, 'Area ')) $searchMarket=$candidate;
            }
            $webQuery=trim(($searchMarket!==''?$searchMarket.' ':'').'live music venues booking opportunities independent artists');
            if($webQuery!==''){
                $web=booking_agent_web_search($webQuery,8);
                booking_agent_store_research($user,$webQuery,$searchMarket,$web);
            }
        }
        $lines=[];
        foreach(array_slice($suggestions,0,5) as $market){
            $lines[]='• '.trim((string)$market['city'].', '.(string)$market['region'],', ').' — '.(int)$market['listeners'].' listeners · '.(int)$market['qualified_plays'].' qualified plays. '.(string)$market['reason'];
        }
        $answer="Booking Agent is active. ";
        $answer.=$lines ? "Best listener-density markets from the last 90 days:\n".implode("\n",$lines) : 'There is not enough city-level listener data yet to rank markets. Stonefellow will use coarse location data when the playback request or hosting platform provides it.';
        if($shows) $answer.="\n\nI am also tracking ".count($shows)." upcoming show".(count($shows)===1?'':'s')." for this account.";
        if($wantsWeb) $answer.=$web ? "\n\nI also found ".count($web)." current web leads. Open the research links below to evaluate availability and fit." : "\n\nThe live web search returned no accessible results on this request; ask me to retry with a different city or market.";
        $result=$empty; $result['handled']=true; $result['answer']=$answer;
        foreach($web as $item){ $result['sources'][]=['source'=>'web:booking','title'=>$item['title'],'url'=>$item['url']]; }
        agent_tool_log($user,'booking_agent.research',$query,'success',['markets'=>$suggestions,'web_count'=>count($web)],$conversationId);
        return $result;
    }

    return $empty;
}
