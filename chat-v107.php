<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/chat.php';
$html = (string)ob_get_clean();

$html = str_replace(
    'chat-v100.js?v=100',
    'chat-v107.js?v=107',
    $html
);
$html = preg_replace('/<body\b/', '<body data-stonefellow-build="107"', $html, 1) ?? $html;

echo $html;
