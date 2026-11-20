<?php
declare(strict_types=1);

/** Only the documented public rows reach a guest Canvas and its link broker. */
function vp3_public_canvas_source_v1(string $command, array $source, string $basePath, ?string $approvedMediaProfileUrl = null): array
{
    $fields = match ($command) {
        'public-products' => ['price_label','price_cents','currency'],
        'public-events' => ['starts_at','location','status'],
        'public-booking' => ['duration_minutes','timezone'],
        'public-campaigns' => ['merchant_name','cta_label','starts_at','ends_at'],
        'public-rewards' => ['value_label','campaign_title'],
        'public-media' => ['collection'],
    };
    if (($source['status'] ?? null) !== 'ready' || !is_array($source['rows'] ?? null)) return ['status'=>'unavailable','rows'=>[]];
    $keys = array_fill_keys(['id','title','description','url',...$fields], true);
    $suffix = match ($command) {
        'public-products' => '/product/[a-z0-9-]{1,80}',
        'public-events' => '',
        'public-booking' => '/book/[a-z0-9-]{1,80}',
        'public-campaigns', 'public-rewards', 'public-media' => '',
    };
    $route = in_array($command,['public-campaigns','public-rewards'],true)
        ? '~\A/campaign/[a-z0-9-]{1,80}\z~D'
        : '~\A/[a-z0-9][a-z0-9._-]{1,58}[a-z0-9]'.$suffix.'\z~D';
    $rows = [];
    foreach (array_slice($source['rows'], 0, 24) as $record) {
        if (!is_array($record) || !is_string($record['id'] ?? null) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $record['id'])) continue;
        $row = array_intersect_key($record, $keys);
        if (!is_string($row['url'] ?? null) || !str_starts_with($row['url'], $basePath.'/')
            || !preg_match($route, substr($row['url'], strlen($basePath)))
            || ($command === 'public-media' && ($approvedMediaProfileUrl === null || $row['url'] !== $approvedMediaProfileUrl))) {
            unset($row['url']);
        }
        $rows[] = $row;
    }
    return ['status'=>'ready','rows'=>$rows];
}

/** The verified publication is server-only. Never forward owner pins or private Canvas state. */
function vp3_public_canvas_payload_v1(PDO $pdo, array $release): array
{
    $basePath = rtrim(url('/'), '/');
    $sources = [];
    $commands = array_intersect(['public-products','public-events','public-booking','public-campaigns','public-rewards','public-media'], $release['commands']);
    if ($commands) {
        require_once __DIR__.'/coding-canvas-public-sources-v1.php';
        $approvedMediaProfileUrl = null;
        if (in_array('public-media', $commands, true)) {
            require_once __DIR__.'/profile-agent.php';
            $verifiedProfile = vp3_coding_canvas_public_source_profile_v1($pdo, (int)$release['owner_user_id']);
            if ($verifiedProfile !== null) $approvedMediaProfileUrl = profile_public_url($verifiedProfile['username']);
        }
        foreach ($commands as $command) {
            $sources[$command] = vp3_public_canvas_source_v1($command,
                vp3_coding_canvas_public_command_data_v1($pdo, $release['owner_user_id'], $command), $basePath,
                $command === 'public-media' ? $approvedMediaProfileUrl : null);
        }
    }
    return ['artifact'=>$release['artifact'],'sources'=>$sources,'basePath'=>$basePath];
}
