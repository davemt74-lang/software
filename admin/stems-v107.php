<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/stems.php';
$html = (string)ob_get_clean();

$replacements = [
    'admin/stems-v79.js?v=101' => 'admin/stems-v107.js?v=107',
    'editor-agent-v91.css?v=97' => 'editor-agent-v107.css?v=107',
    'admin/stems-v91.css?v=101' => 'admin/stems-v107.css?v=107',
    'admin/stems-v92.css?v=92' => 'admin/stems-extra-v107.css?v=107',
    'admin/stem-live-recording-v87.css?v=88' => 'admin/stem-live-recording-v107.css?v=107',
    'admin/stem-live-recording-v87.js?v=88' => 'admin/stem-live-recording-v107.js?v=107',
    'editor-voice-barge-v89.js?v=89' => 'editor-voice-barge-v107.js?v=107',
    'admin/stem-metronome-v91.js?v=97' => 'admin/stem-metronome-v107.js?v=107',
    'admin/stem-agent-v91.js?v=91' => 'admin/stem-agent-v107.js?v=107',
];

$html = str_replace(
    array_keys($replacements),
    array_values($replacements),
    $html
);
$html = preg_replace('/<body\b/', '<body data-stonefellow-build="107"', $html, 1) ?? $html;
$guard = '<script src="' . e(url('/admin/stem-studio-guard-v107.js?v=107')) . '"></script>';
$html = str_replace('</body>', $guard . "\n</body>", $html);

echo $html;
