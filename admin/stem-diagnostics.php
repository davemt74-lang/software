<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('tracks.manage');

$checks = [];

$checks[] = stem_diag_row(
    'Stem Importer Build',
    'v30 Browser Extraction + Fatal Recursion Fix',
    true,
    'Track/Edit should display “Stem Importer v30 · Browser Extraction”. The primary endpoint is /api/stem-direct-v30.php; the server never opens the ZIP.'
);

function stem_diag_row(string $name, string $value, bool $ok, string $note = ''): array
{
    return [
        'name'=>$name,
        'value'=>$value,
        'ok'=>$ok,
        'note'=>$note,
    ];
}

$checks[] = stem_diag_row(
    'PHP Version',
    PHP_VERSION,
    version_compare(PHP_VERSION, '8.1.0', '>='),
    'Stonefellow currently targets PHP 8.1+.'
);

$checks[] = stem_diag_row(
    'Primary ZIP Processing',
    'Browser-side',
    true,
    'v25 reads/decompresses the REAPER ZIP in the browser and uploads only the extracted RPP and stems.'
);

$zipBackend = stem_zip_backend();
$zipBackendLabels = [
    'php-native'=>'Stonefellow native ZIP reader',
    'cli-unzip'=>'Server unzip utility',
    'phardata'=>'PHP PharData',
    'ziparchive'=>'PHP ZipArchive fallback',
];

$checks[] = stem_diag_row(
    'ZIP Extraction Backend',
    $zipBackendLabels[$zipBackend] ?? 'Unavailable',
    $zipBackend !== '',
    $zipBackend === 'php-native'
        ? 'Preferred: Stonefellow reads the ZIP central directory itself and streams only the selected RPP/stems. No ZipArchive, exec(), or PharData call is required.'
        : ($zipBackend === 'cli-unzip'
            ? 'ZIP listing/extraction runs through the server unzip utility.'
            : ($zipBackend === 'phardata'
                ? 'PharData will inspect/extract only the RPP and selected stems.'
                : ($zipBackend === 'ziparchive'
                    ? 'ZipArchive is the final fallback on this server.'
                    : 'No supported ZIP reader is available.')))
);

$checks[] = stem_diag_row(
    'PHP ZipArchive',
    class_exists('ZipArchive') ? 'Installed' : 'Not installed',
    true,
    'Informational only. v22 no longer requires ZipArchive when another backend is available.'
);

$memoryLimit = (string)ini_get('memory_limit');
$checks[] = stem_diag_row(
    'PHP Memory Limit',
    $memoryLimit !== '' ? $memoryLimit : 'Unknown',
    true,
    'The phased importer does not load whole audio files into PHP memory.'
);

$execution = (string)ini_get('max_execution_time');
$checks[] = stem_diag_row(
    'PHP Execution Limit',
    $execution !== '' ? $execution . ' sec' : 'Unknown',
    true,
    'v23 performs ZIP assembly and native selective extraction across many short requests.'
);

$postMax = (string)ini_get('post_max_size');
$uploadMax = (string)ini_get('upload_max_filesize');

$checks[] = stem_diag_row(
    'POST / Upload Limits',
    'post_max_size=' . $postMax . ' · upload_max_filesize=' . $uploadMax,
    true,
    'Each browser upload request is only about ' .
      number_format(stem_chunk_bytes() / 1024 / 1024, 0) . ' MB.'
);

$stemsDir = STONEFELLOW_ROOT . '/uploads/stems';
$projectsDir = STONEFELLOW_ROOT . '/uploads/projects';
$tempDir = STONEFELLOW_ROOT . '/private/stem-upload-chunks';

foreach ([
    'Stem Storage'=>$stemsDir,
    'Project Storage'=>$projectsDir,
    'Temporary Import Storage'=>$tempDir,
] as $label=>$dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $checks[] = stem_diag_row(
        $label,
        $dir,
        is_dir($dir) && is_writable($dir),
        is_writable($dir) ? 'Writable.' : 'PHP cannot write here.'
    );
}

$free = @disk_free_space(STONEFELLOW_ROOT);
$total = @disk_total_space(STONEFELLOW_ROOT);

$freeText = is_numeric($free)
    ? number_format((float)$free / 1024 / 1024, 0) . ' MB free'
    : 'Unknown';

if (is_numeric($free) && is_numeric($total) && (float)$total > 0) {
    $freeText .= ' of ' .
        number_format((float)$total / 1024 / 1024, 0) .
        ' MB';
}

$checks[] = stem_diag_row(
    'Disk Space',
    $freeText,
    !is_numeric($free) || (float)$free > 128 * 1024 * 1024,
    'MP3 stem packages are recommended when hosting space is limited.'
);

$pdo = db();
$checks[] = stem_diag_row(
    'track_projects table',
    table_exists('track_projects') ? 'Ready' : 'Missing',
    table_exists('track_projects'),
    'Created by the v14 database upgrade.'
);
$checks[] = stem_diag_row(
    'track_stems table',
    table_exists('track_stems') ? 'Ready' : 'Missing',
    table_exists('track_stems'),
    'Created by the v14 database upgrade.'
);

$writeTestDir = STONEFELLOW_ROOT . '/private/stem-upload-chunks';
$writeTestPath = $writeTestDir . '/diag-' . bin2hex(random_bytes(4)) . '.tmp';
$writeOk = @file_put_contents($writeTestPath, 'stonefellow') !== false;
if ($writeOk) {
    @unlink($writeTestPath);
}

$checks[] = stem_diag_row(
    'Temporary File Write Test',
    $writeOk ? 'Passed' : 'Failed',
    $writeOk,
    'Confirms PHP can create and remove importer files.'
);

$logPath = STONEFELLOW_ROOT . '/private/stem-import.log';
$logLines = [];

if (is_file($logPath)) {
    $allLines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $logLines = array_slice($allLines, -80);
}

$allOkay = !array_filter(
    $checks,
    static fn(array $check): bool => !$check['ok']
);

$adminTitle = 'Stem Diagnostics';
$adminActive = 'tracks';
require __DIR__ . '/_header.php';
?>
<div class="panel">
  <div class="content-library-heading">
    <div>
      <span class="status"><?= $allOkay ? 'Environment Ready' : 'Action Needed' ?></span>
      <h2>Stem Import Diagnostics</h2>
      <p class="muted">Checks the server requirements used by the REAPER / MP3 stem importer.</p>
    </div>
    <a class="btn" href="<?= e(url('/admin/tracks.php')) ?>">Tracks</a>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Check</th><th>Result</th><th>Status</th><th>Notes</th></tr>
      </thead>
      <tbody>
      <?php foreach ($checks as $check): ?>
        <tr>
          <td><strong><?= e($check['name']) ?></strong></td>
          <td><?= e($check['value']) ?></td>
          <td>
            <span class="status"><?= $check['ok'] ? 'OK' : 'Problem' ?></span>
          </td>
          <td class="muted"><?= e($check['note']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel">
  <div class="content-form-heading">
    <div>
      <span class="status">Server Log</span>
      <h2>Recent Stem Import Log</h2>
    </div>
  </div>

  <?php if ($logLines): ?>
    <pre class="stem-diagnostic-log"><?= e(implode("\n", $logLines)) ?></pre>
  <?php else: ?>
    <p class="muted">No importer log entries have been recorded yet.</p>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
