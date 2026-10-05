<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$pdo = db();
$type = trim((string)($_GET['type'] ?? 'photo'));
$id = (int)($_GET['id'] ?? 0);
$user = current_user();
if($type==='album'&&$id>=1000000000){$type='artist_album';$id-=1000000000;}

if (!$pdo || $id < 1) {
    http_response_code(404);
    exit('Image not found.');
}

$isArtistWorkspaceAsset = false;
if ($type === 'artist_photo') {
    $table = 'artist_catalog_photos_v181';
    $managePermission = 'photos.manage';
    $pathColumn = 'image_path';
    $baseDir = 'photos';
    $isArtistWorkspaceAsset = true;
} elseif ($type === 'artist_merch') {
    $table = 'artist_catalog_merch_v181';
    $managePermission = 'merch.manage';
    $pathColumn = 'image_path';
    $baseDir = 'merch';
    $isArtistWorkspaceAsset = true;
} elseif ($type === 'artist_album') {
    $table = 'artist_catalog_albums_v181';
    $managePermission = 'albums.manage';
    $pathColumn = 'cover_path';
    $baseDir = 'albums';
    $isArtistWorkspaceAsset = true;
} elseif ($type === 'merch') {
    $table = 'merch_items';
    $managePermission = 'merch.manage';
    $pathColumn = 'image_path';
    $baseDir = 'merch';
} elseif ($type === 'album') {
    $table = 'albums';
    $managePermission = 'albums.manage';
    $pathColumn = 'cover_path';
    $baseDir = 'albums';
} elseif ($type === 'post') {
    $table = 'artist_posts';
    $managePermission = 'posts.manage';
    $pathColumn = 'image_path';
    $baseDir = 'posts';
} else {
    $table = 'photos';
    $managePermission = 'photos.manage';
    $pathColumn = 'image_path';
    $baseDir = 'photos';
}

$workspaceSelect = $isArtistWorkspaceAsset ? ',workspace_id' : '';
try {
    $stmt = $pdo->prepare(
        "SELECT id,{$pathColumn} AS image_path,visibility,is_published{$workspaceSelect}
         FROM {$table}
         WHERE id=?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $item = $stmt->fetch();
} catch (Throwable $e) {
    $item = false;
}

if (!$item) {
    http_response_code(404);
    exit('Image not found.');
}

$canManage=has_permission($managePermission,$user);
if($isArtistWorkspaceAsset){
    $capability=match($type){'artist_photo'=>'media','artist_album'=>'albums',default=>'tracks'};
    $canManage=music_workspace_resources_v330_can_manage($pdo,(int)($item['workspace_id']??0),$capability,$user);
}
if(in_array($type,['album','artist_album'],true)){
    $catalogId=$type==='artist_album'?$id:0;
    if($type==='album'){$lookup=$pdo->prepare('SELECT id FROM artist_catalog_albums_v181 WHERE source_album_id=? LIMIT 1');$lookup->execute([$id]);$catalogId=(int)$lookup->fetchColumn();}
    if($catalogId>0){$cover=artist_music_v185_public_cover($pdo,'album',$catalogId,$user);if($cover){require_once __DIR__.'/includes/music-media.php';$mime=music_media_mime($cover['path'],'cover');if($mime)music_media_send($cover['path'],$mime);}}
}
$canView =
    $canManage ||
    (
        (int)$item['is_published'] === 1 &&
        can_view_visibility((string)$item['visibility'], $user)
    );

if (!$canView) {
    http_response_code(404);
    exit('Image not found.');
}

$path = trim((string)$item['image_path']);
$absolute = null;

if ($type === 'artist_photo') {
    $absolute = artist_media_v182_resolve_stored_photo((int)($item['workspace_id'] ?? 0), $path);
} else {
    $prefix = '/uploads/' . $baseDir . '/';
    if ($path !== '' && str_starts_with($path, $prefix)) {
        $base = realpath(STONEFELLOW_ROOT . '/uploads/' . $baseDir);
        $candidate = realpath(STONEFELLOW_ROOT . '/' . ltrim($path, '/'));
        if ($base && $candidate && is_file($candidate) && str_starts_with($candidate, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            $absolute = $candidate;
        }
    }
}

if (!$absolute || !is_file($absolute)) {
    http_response_code(404);
    exit('Image not found.');
}

$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = finfo_file($finfo, $absolute);
        if (is_string($detected) && $detected !== '') $mime = $detected;
        finfo_close($finfo);
    }
}
if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
    $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
    $mime = match ($extension) {
        'jpg','jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
}
if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
    http_response_code(415);
    exit('Unsupported image.');
}
$size = filesize($absolute);
if ($size === false || $size < 1) {
    http_response_code(404);
    exit('Image not found.');
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache');
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
if(($_SERVER['REQUEST_METHOD']??'GET')==='HEAD')exit;
readfile($absolute);
exit;