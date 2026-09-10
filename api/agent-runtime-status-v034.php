<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$user = current_user();
if (!$user || !has_permission('chat.access', $user)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chat access is unavailable for this account.']);
    exit;
}

$pdo = db();
$userId = (int)$user['id'];
$latest = null;
$monthCloudTokens = 0;
$monthRequests = 0;
$monthLocalRequests = 0;
$monthCostMicros = 0;
$unknownCost = 0;

if ($pdo && function_exists('ai_usage_accounting_v032_schema_ready') && ai_usage_accounting_v032_schema_ready($pdo)) {
    try {
        $routeReady = function_exists('ai_usage_accounting_v032_route_schema_ready')
            && ai_usage_accounting_v032_route_schema_ready($pdo);
        $routeColumns = $routeReady
            ? 'runtime_version,requested_route,attempted_route,actual_route,route_reason,fallback_reason,'
            : "'' runtime_version,'' requested_route,'' attempted_route,'' actual_route,'' route_reason,'' fallback_reason,";
        $stmt = $pdo->prepare('SELECT '.$routeColumns.'source,provider,model,fallback_used,failure_class,latency_ms,created_at FROM ai_execution_ledger WHERE user_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId]);
        $latest = $stmt->fetch() ?: null;

        $month = $pdo->prepare("SELECT COUNT(*) requests,COALESCE(SUM(cloud_tokens_charged),0) cloud_tokens,COALESCE(SUM(source='homeserver_local'),0) local_requests,COALESCE(SUM(estimated_cost_micros),0) cost_micros,COALESCE(SUM(estimated_cost_micros IS NULL),0) unknown_cost FROM ai_execution_ledger WHERE user_id=? AND created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')");
        $month->execute([$userId]);
        $row = $month->fetch() ?: [];
        $monthCloudTokens = (int)($row['cloud_tokens'] ?? 0);
        $monthRequests = (int)($row['requests'] ?? 0);
        $monthLocalRequests = (int)($row['local_requests'] ?? 0);
        $monthCostMicros = (int)($row['cost_micros'] ?? 0);
        $unknownCost = (int)($row['unknown_cost'] ?? 0);
    } catch (Throwable $e) {
        $latest = null;
    }
}

$source = (string)($latest['source'] ?? '');
$actualRoute = (string)($latest['actual_route'] ?? '');
$sourceLabel = match ($actualRoute) {
    'homeserver_vp3_cloud' => 'VP3 Cloud via HomeServer',
    'homeserver_user_provider' => 'Connected Provider via HomeServer',
    default => match ($source) {
        'homeserver_local' => 'HomeServer Local',
        'user_provider' => 'Connected Provider',
        'vp3_cloud' => 'VP3 Cloud',
        'vp3_tool' => 'VP3 Tool',
        'vp3_retrieval' => 'VP3 Retrieval',
        default => 'No AI run yet',
    },
};

$costLabel = function_exists('ai_usage_accounting_v032_format_aggregate_cost')
    ? ai_usage_accounting_v032_format_aggregate_cost($monthCostMicros, $unknownCost)
    : ($unknownCost > 0 ? '—' : '$' . number_format($monthCostMicros / 1000000, 2));

echo json_encode([
    'ok' => true,
    'latest' => [
        'runtime_version' => (string)($latest['runtime_version'] ?? ''),
        'requested_route' => (string)($latest['requested_route'] ?? ''),
        'attempted_route' => (string)($latest['attempted_route'] ?? ''),
        'actual_route' => $actualRoute,
        'route_reason' => (string)($latest['route_reason'] ?? ''),
        'fallback_reason' => (string)($latest['fallback_reason'] ?? ''),
        'source' => $source,
        'source_label' => $sourceLabel,
        'provider' => (string)($latest['provider'] ?? ''),
        'model' => (string)($latest['model'] ?? ''),
        'fallback_used' => !empty($latest['fallback_used']),
        'failure_class' => (string)($latest['failure_class'] ?? 'none'),
        'latency_ms' => (int)($latest['latency_ms'] ?? 0),
        'created_at' => (string)($latest['created_at'] ?? ''),
    ],
    'month' => [
        'requests' => $monthRequests,
        'cloud_tokens' => $monthCloudTokens,
        'local_requests' => $monthLocalRequests,
        'estimated_cost' => $costLabel,
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
