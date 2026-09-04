<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('admin.access');

$user = current_user();
if (!$user || !user_has_role('admin', $user)) {
    http_response_code(403);
    exit('Admin account required.');
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Stonefellow-Force-Repair: force-cache-bust-20260825-2');
}

const STONEFELLOW_FORCE_REPAIR_BUILD = 'force-cache-bust-20260825-2';
const STONEFELLOW_FORCE_REPAIR_REPO = 'bigriversocial74/band';
const STONEFELLOW_FORCE_REPAIR_REF = 'main';

$appRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$allowed = [
    'chat.php',
    'chat-v108.js',
    'admin/stems.php',
    'admin/_footer.php',
    'includes/team-chat-widget-v81.php',
    'team-chat-admin-v108.js',
    'admin/stem-studio-guard-v107.js',
    'admin/runtime-status.php',
];

function force_repair_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function force_repair_raw_url(string $path): string
{
    $parts = array_map('rawurlencode', explode('/', $path));
    return 'https://raw.githubusercontent.com/'
        . STONEFELLOW_FORCE_REPAIR_REPO . '/'
        . STONEFELLOW_FORCE_REPAIR_REF . '/'
        . implode('/', $parts);
}

function force_repair_download(string $path): array
{
    $url = force_repair_raw_url($path);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>true,
            CURLOPT_MAXREDIRS=>3,
            CURLOPT_CONNECTTIMEOUT=>8,
            CURLOPT_TIMEOUT=>20,
            CURLOPT_USERAGENT=>'Stonefellow-Force-Repair/' . STONEFELLOW_FORCE_REPAIR_BUILD,
            CURLOPT_HTTPHEADER=>['Accept: text/plain'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            return [false, '', 'GitHub HTTP ' . $status . ($error !== '' ? ' · ' . $error : '')];
        }
        return [true, (string)$body, ''];
    }

    $context = stream_context_create([
        'http'=>[
            'method'=>'GET',
            'timeout'=>20,
            'follow_location'=>1,
            'max_redirects'=>3,
            'header'=>"User-Agent: Stonefellow-Force-Repair/" . STONEFELLOW_FORCE_REPAIR_BUILD . "\r\nAccept: text/plain\r\n",
        ],
        'ssl'=>[
            'verify_peer'=>true,
            'verify_peer_name'=>true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return [false, '', 'Server could not download the approved GitHub source.'];
    }
    return [true, (string)$body, ''];
}

function force_repair_write(string $root, string $path, string $content): array
{
    $target = $root . '/' . $path;
    $directory = dirname($target);
    if (!is_dir($directory)) {
        return [false, 'Target directory does not exist: ' . $directory];
    }
    if (!is_writable($directory) || (is_file($target) && !is_writable($target))) {
        return [false, 'Target is not writable by PHP.'];
    }

    $tmp = $target . '.stonefellow-repair-' . bin2hex(random_bytes(5)) . '.tmp';
    $written = @file_put_contents($tmp, $content, LOCK_EX);
    if ($written === false || $written !== strlen($content)) {
        @unlink($tmp);
        return [false, 'Could not write complete temporary file.'];
    }

    @chmod($tmp, 0644);
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        return [false, 'Could not atomically replace target file.'];
    }

    clearstatcache(true, $target);
    if (function_exists('opcache_invalidate') && str_ends_with($path, '.php')) {
        try { @opcache_invalidate($target, true); } catch (Throwable $e) {}
    }

    return [true, ''];
}

$results = [];
$applied = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        http_response_code(419);
        exit('Session expired. Reload this page and try again.');
    }

    foreach ($allowed as $path) {
        $target = $appRoot . '/' . $path;
        $before = is_file($target) ? (string)hash_file('sha256', $target) : '';
        [$downloaded, $content, $downloadError] = force_repair_download($path);

        if (!$downloaded) {
            $results[$path] = [
                'ok'=>false,
                'before'=>$before,
                'after'=>$before,
                'message'=>$downloadError,
            ];
            continue;
        }

        $expected = hash('sha256', $content);
        [$written, $writeError] = force_repair_write($appRoot, $path, $content);
        $after = is_file($target) ? (string)hash_file('sha256', $target) : '';
        $ok = $written && hash_equals($expected, $after);

        $results[$path] = [
            'ok'=>$ok,
            'before'=>$before,
            'after'=>$after,
            'expected'=>$expected,
            'message'=>$ok ? 'Replaced and verified.' : ($writeError !== '' ? $writeError : 'Hash verification failed.'),
        ];
    }

    if (function_exists('opcache_reset')) {
        try { @opcache_reset(); } catch (Throwable $e) {}
    }
    clearstatcache(true);
    $applied = count($results) === count($allowed)
        && count(array_filter($results, static fn(array $row): bool => (bool)$row['ok'])) === count($allowed);
}

$current = [];
foreach ($allowed as $path) {
    $target = $appRoot . '/' . $path;
    $current[$path] = [
        'exists'=>is_file($target),
        'sha256'=>is_file($target) ? (string)hash_file('sha256', $target) : '',
        'mtime'=>is_file($target) ? (int)filemtime($target) : 0,
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Force Runtime Repair | Stonefellow</title>
<style>
body{margin:0;background:#f4f4f4;color:#161616;font:14px/1.45 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1120px;margin:auto;padding:28px}.card{background:#fff;border:1px solid #ddd;border-radius:12px;padding:20px;margin-bottom:18px;overflow:auto}h1,h2{margin:0 0 12px}.ok{color:#087a2f}.bad{color:#b42318}.muted{color:#666}button{border:0;border-radius:9px;background:#111;color:#fff;padding:12px 16px;font-weight:800;cursor:pointer}table{border-collapse:collapse;width:100%;min-width:850px}th,td{text-align:left;padding:9px;border-bottom:1px solid #e7e7e7;vertical-align:top}code{font:12px/1.4 ui-monospace,SFMono-Regular,Consolas,monospace;overflow-wrap:anywhere}.result{font-weight:800}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Stonefellow Force Runtime Repair</h1>
    <p><strong>Build:</strong> <code><?= force_repair_h(STONEFELLOW_FORCE_REPAIR_BUILD) ?></code></p>
    <p><strong>Real app root:</strong> <code><?= force_repair_h($appRoot) ?></code></p>
    <p>This page exists specifically because the current ZIP deployment is adding new files but leaving some existing files stale. It downloads only the fixed allowlisted Stonefellow files from <code><?= force_repair_h(STONEFELLOW_FORCE_REPAIR_REPO) ?>/main</code>, atomically replaces the stale local copies, verifies their SHA-256 hashes, and clears PHP OPcache.</p>
    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
      <p class="result <?= $applied ? 'ok' : 'bad' ?>"><?= $applied ? 'Repair completed and every file hash verified.' : 'Repair did not fully complete. See the file results below.' ?></p>
    <?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <button type="submit">Apply Hard Runtime Repair</button>
    </form>
    <p class="muted">No database rows, uploads, API keys, account settings, or microphone permissions are changed.</p>
  </div>

  <div class="card">
    <h2>Critical files</h2>
    <table>
      <thead><tr><th>File</th><th>Current disk state</th><th>Repair result</th></tr></thead>
      <tbody>
      <?php foreach ($allowed as $path): $state=$current[$path]; $row=$results[$path] ?? null; ?>
        <tr>
          <td><strong><?= force_repair_h($path) ?></strong></td>
          <td class="<?= $state['exists'] ? 'ok' : 'bad' ?>">
            <?= $state['exists'] ? 'present' : 'missing' ?><br>
            <code><?= force_repair_h($state['sha256']) ?></code><br>
            <span class="muted"><?= $state['mtime'] ? force_repair_h(date('c', $state['mtime'])) : '—' ?></span>
          </td>
          <td class="<?= $row ? ($row['ok'] ? 'ok' : 'bad') : 'muted' ?>">
            <?php if ($row): ?>
              <strong><?= $row['ok'] ? 'PASS' : 'FAIL' ?></strong> · <?= force_repair_h($row['message']) ?><br>
              <span class="muted">before</span> <code><?= force_repair_h($row['before']) ?></code><br>
              <span class="muted">after</span> <code><?= force_repair_h($row['after']) ?></code>
            <?php else: ?>
              Not run yet.
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
