<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/profile-public-media-v174.php';

function profile_media_fail(int $status = 404): never
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    exit;
}

$bucket = strtolower(trim((string)($_GET['bucket'] ?? '')));
$fileName = trim((string)($_GET['file'] ?? ''));

if (!in_array($bucket, ['avatars', 'profile-covers'], true)) {
    profile_media_fail();
}
if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,127}\.(?:jpe?g|png|webp)$/i', $fileName) || str_contains($fileName, '/')) {
    profile_media_fail();
}

$publicPath = profile_public_media_path_v174('/uploads/' . $bucket . '/' . $fileName, $bucket);
if ($publicPath === '') {
    profile_media_fail();
}
$pathCandidates = profile_public_media_db_candidates_v174($publicPath);
$pdo = db();
if (!$pdo || !table_exists('user_profiles')) {
    profile_media_fail();
}

if ($bucket === 'avatars') {
    $stmt = $pdo->prepare(
        'SELECT u.id AS user_id,u.is_active,COALESCE(p.is_public,0) AS is_public '
        . 'FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id '
        . 'WHERE u.avatar_path IN (?,?) LIMIT 1'
    );
} else {
    $stmt = $pdo->prepare(
        'SELECT p.user_id,u.is_active,p.is_public '
        . 'FROM user_profiles p INNER JOIN users u ON u.id=p.user_id '
        . 'WHERE p.cover_path IN (?,?) LIMIT 1'
    );
}
$stmt->execute([$pathCandidates[0] ?? '', $pathCandidates[1] ?? ($pathCandidates[0] ?? '')]);
$owner = $stmt->fetch();
if (!$owner || empty($owner['is_active'])) {
    profile_media_fail();
}

$ownerUserId = (int)$owner['user_id'];
$viewer = current_user();
$viewerId = (int)($viewer['id'] ?? 0);
$isOwner = $viewerId > 0 && $viewerId === $ownerUserId;
$isAdmin = $viewerId > 0 && has_permission('users.manage', $viewer);
$isPublic = !empty($owner['is_public']);
$identityDisclosure = false;

// A private member avatar may be shown to a profile owner only when that visitor
// explicitly disclosed profile identity in the existing visit-privacy flow.
if (!$isPublic && !$isOwner && !$isAdmin && $bucket === 'avatars' && $viewerId > 0 && table_exists('profile_visit_sessions')) {
    $disclosure = $pdo->prepare(
        'SELECT 1 FROM profile_visit_sessions '
        . 'WHERE owner_user_id=? AND visitor_user_id=? AND identity_disclosed=1 LIMIT 1'
    );
    $disclosure->execute([$viewerId, $ownerUserId]);
    $identityDisclosure = (bool)$disclosure->fetchColumn();
}

if (!$isPublic && !$isOwner && !$isAdmin && !$identityDisclosure) {
    profile_media_fail();
}

$file = profile_public_media_file_v174($publicPath, $bucket);
if ($file === '') {
    profile_media_fail();
}

$allowed = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$expectedMime = $allowed[$extension] ?? '';
if ($expectedMime === '') {
    profile_media_fail(415);
}

$detectedMime = '';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = finfo_file($finfo, $file);
        $detectedMime = is_string($detected) ? strtolower($detected) : '';
        finfo_close($finfo);
    }
}
if ($detectedMime !== '' && $detectedMime !== $expectedMime) {
    profile_media_fail(415);
}

$size = filesize($file);
if ($size === false) {
    profile_media_fail();
}

header('Content-Type: ' . $expectedMime);
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="' . ($bucket === 'avatars' ? 'profile-image.' : 'cover-image.') . $extension . '"');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
header('Cache-Control: ' . ($isPublic ? 'public, max-age=31536000, immutable' : 'private, no-store'));
readfile($file);
exit;
