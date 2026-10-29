<?php
declare(strict_types=1);

function agent_voice_preferences_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_agent_voice_preferences (
      user_id INT UNSIGNED NOT NULL PRIMARY KEY,
      system_voice_id VARCHAR(128) NOT NULL DEFAULT '',
      system_voice_name VARCHAR(120) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_agent_voice_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function agent_voice_preferences_get(PDO $pdo, int $userId): array
{
    $defaults = ['voice_id'=>'', 'voice_name'=>''];
    if ($userId < 1 || !table_exists('user_agent_voice_preferences')) return $defaults;
    $stmt = $pdo->prepare('SELECT system_voice_id,system_voice_name FROM user_agent_voice_preferences WHERE user_id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) return $defaults;
    return ['voice_id'=>(string)$row['system_voice_id'], 'voice_name'=>(string)$row['system_voice_name']];
}

/** Only provider-returned default voices may be assigned. No shared-account clones. */
function agent_voice_default_catalog(): array
{
    [$key] = ai_elevenlabs_credential();
    if ($key === '' || !function_exists('curl_init')) throw new RuntimeException('ElevenLabs voice selection is unavailable. Ask your administrator to check the provider connection.');
    $cacheKey = hash('sha256', $key);
    $cached = $_SESSION['vp3_default_voice_catalog'] ?? [];
    if (($cached['key'] ?? '') === $cacheKey && (int)($cached['expires'] ?? 0) > time()) return $cached['voices'];
    $voices = []; $token = '';
    for ($page=0; $page<5; $page++) {
        $query = ['voice_type'=>'default', 'page_size'=>100, 'include_total_count'=>'false'];
        if ($token !== '') $query['next_page_token'] = $token;
        $curl = curl_init('https://api.elevenlabs.io/v2/voices?' . http_build_query($query));
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>8,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_HTTPHEADER=>['xi-api-key: '.$key, 'Accept: application/json']]);
        $body = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        if ($status !== 200 || !is_string($body)) throw new RuntimeException('Could not load ElevenLabs system voices. The provider key needs voices_read access. Your saved voice has not changed.');
        $data = json_decode($body, true);
        if (!is_array($data['voices'] ?? null)) throw new RuntimeException('ElevenLabs returned an invalid voice list. Try again.');
        foreach ($data['voices'] as $voice) {
            $id = (string)($voice['voice_id'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/', $id)) continue;
            $voices[$id] = ['voice_id'=>$id, 'name'=>mb_substr(trim((string)($voice['name'] ?? 'System voice')),0,120)];
        }
        if (empty($data['has_more'])) break;
        $next = (string)($data['next_page_token'] ?? '');
        if ($next === '' || $next === $token || $page === 4) throw new RuntimeException('The system voice list could not be completed. Try again.');
        $token = $next;
    }
    $voices = array_values($voices);
    usort($voices, static fn($a,$b)=>strcasecmp($a['name'],$b['name']));
    $_SESSION['vp3_default_voice_catalog'] = ['key'=>$cacheKey, 'expires'=>time()+300, 'voices'=>$voices];
    return $voices;
}

function agent_voice_preferences_save(PDO $pdo, int $userId, string $voiceId, array $catalog): array
{
    if ($userId < 1) throw new RuntimeException('Sign in to choose a voice.');
    $name = '';
    if ($voiceId !== '') {
        $voice = null;
        foreach ($catalog as $row) if (($row['voice_id'] ?? '') === $voiceId) { $voice = $row; break; }
        if (!$voice) throw new RuntimeException('Choose an available ElevenLabs system voice.');
        $name = (string)$voice['name'];
    }
    agent_voice_preferences_schema($pdo);
    $stmt = $pdo->prepare('INSERT INTO user_agent_voice_preferences (user_id,system_voice_id,system_voice_name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE system_voice_id=VALUES(system_voice_id),system_voice_name=VALUES(system_voice_name),updated_at=NOW()');
    $stmt->execute([$userId,$voiceId,$name]);
    return agent_voice_preferences_get($pdo,$userId);
}
