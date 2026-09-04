<?php
declare(strict_types=1);

function artist_music_v185_schema_ready(): bool
{
    return table_exists('artist_catalog_tracks_v181')
        && column_exists('artist_catalog_tracks_v181','album_id')
        && column_exists('artist_catalog_tracks_v181','description')
        && column_exists('artist_catalog_tracks_v181','genre')
        && column_exists('artist_catalog_tracks_v181','duration_seconds')
        && column_exists('artist_catalog_tracks_v181','track_number')
        && column_exists('artist_catalog_tracks_v181','cover_photo_id')
        && table_exists('artist_catalog_albums_v181')
        && column_exists('artist_catalog_albums_v181','cover_photo_id')
        && column_exists('artist_catalog_albums_v181','sort_order');
}

function artist_music_v185_ensure_schema(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo) throw new RuntimeException('Database connection is unavailable.');
    artist_workspace_v181_ensure_schema($pdo);
    $trackColumns=[
        'album_id'=>'BIGINT UNSIGNED NULL AFTER album',
        'description'=>"TEXT NOT NULL AFTER album_id",
        'genre'=>"VARCHAR(120) NOT NULL DEFAULT '' AFTER description",
        'duration_seconds'=>'INT UNSIGNED NULL AFTER genre',
        'track_number'=>'INT UNSIGNED NOT NULL DEFAULT 0 AFTER duration_seconds',
        'cover_photo_id'=>'BIGINT UNSIGNED NULL AFTER cover_path',
    ];
    foreach($trackColumns as $column=>$definition){if(!column_exists('artist_catalog_tracks_v181',$column))$pdo->exec("ALTER TABLE artist_catalog_tracks_v181 ADD COLUMN {$column} {$definition}");}
    if(!column_exists('artist_catalog_albums_v181','cover_photo_id'))$pdo->exec('ALTER TABLE artist_catalog_albums_v181 ADD COLUMN cover_photo_id BIGINT UNSIGNED NULL AFTER cover_path');
    if(!column_exists('artist_catalog_albums_v181','sort_order'))$pdo->exec('ALTER TABLE artist_catalog_albums_v181 ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER visibility');
    if(!artist_workspace_v181_index_exists($pdo,'artist_catalog_tracks_v181','idx_artist_track_album_v185'))$pdo->exec('ALTER TABLE artist_catalog_tracks_v181 ADD INDEX idx_artist_track_album_v185 (workspace_id,album_id,track_number,id)');
}

function artist_music_v185_owned_path(int $workspaceId,string $storedPath): ?string
{
    $prefix='/uploads/artist-music/'.$workspaceId.'/';
    if($workspaceId<1||$storedPath===''||!str_starts_with($storedPath,$prefix))return null;
    $base=realpath(STONEFELLOW_ROOT.'/uploads/artist-music/'.$workspaceId);$path=realpath(STONEFELLOW_ROOT.'/'.ltrim($storedPath,'/'));
    if(!$base||!$path||!is_file($path)||!str_starts_with($path,rtrim($base,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))return null;
    return $path;
}

function artist_music_v185_store_audio(array $upload,int $workspaceId): string
{
    if(!$upload||(int)($upload['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return '';
    if((int)($upload['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($upload['tmp_name']??'')))throw new RuntimeException('Audio upload failed.');
    global $config;
    $max=max(1,(int)($config['uploads']['max_artist_audio_bytes']??(256*1024*1024)));$size=(int)($upload['size']??0);
    if($size<1||$size>$max)throw new RuntimeException('Audio file exceeds the configured artist upload limit.');
    $name=(string)($upload['name']??'');$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$allowedExt=['mp3','m4a','wav','ogg'];
    if(!in_array($ext,$allowedExt,true))throw new RuntimeException('Choose an MP3, M4A, WAV, or OGG file.');
    $allowedMime=['audio/mpeg','audio/mp4','audio/x-m4a','audio/wav','audio/x-wav','audio/vnd.wave','audio/ogg','application/ogg'];
    if(function_exists('finfo_open')){
        $f=finfo_open(FILEINFO_MIME_TYPE);
        if($f){$det=finfo_file($f,(string)$upload['tmp_name']);finfo_close($f);$mime=is_string($det)?strtolower(trim($det)):'';if($mime===''||!in_array($mime,$allowedMime,true))throw new RuntimeException('The uploaded file is not recognized as supported audio.');}
    }
    $dir=STONEFELLOW_ROOT.'/uploads/artist-music/'.$workspaceId;
    if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('Artist audio storage is unavailable.');
    $root=STONEFELLOW_ROOT.'/uploads/artist-music';if(!is_file($root.'/.htaccess'))@file_put_contents($root.'/.htaccess',"Require all denied\nDeny from all\n");
    $filename='track-'.bin2hex(random_bytes(16)).'.'.$ext;$target=$dir.DIRECTORY_SEPARATOR.$filename;
    if(!move_uploaded_file((string)$upload['tmp_name'],$target))throw new RuntimeException('Audio file could not be stored.');
    @chmod($target,0640);return '/uploads/artist-music/'.$workspaceId.'/'.$filename;
}

function artist_music_v185_delete_owned_audio(int $workspaceId,string $storedPath): void{$path=artist_music_v185_owned_path($workspaceId,$storedPath);if($path)@unlink($path);}

function artist_music_v185_album(PDO $pdo,int $workspaceId,int $id): ?array
{
    if($workspaceId<1||$id<1)return null;$stmt=$pdo->prepare('SELECT * FROM artist_catalog_albums_v181 WHERE id=? AND workspace_id=? LIMIT 1');$stmt->execute([$id,$workspaceId]);return $stmt->fetch()?:null;
}
function artist_music_v185_track(PDO $pdo,int $workspaceId,int $id): ?array
{
    if($workspaceId<1||$id<1)return null;$stmt=$pdo->prepare('SELECT * FROM artist_catalog_tracks_v181 WHERE id=? AND workspace_id=? LIMIT 1');$stmt->execute([$id,$workspaceId]);return $stmt->fetch()?:null;
}
function artist_music_v185_validate_photo(PDO $pdo,int $workspaceId,int $photoId): int
{
    if($photoId<1)return 0;$stmt=$pdo->prepare('SELECT 1 FROM artist_catalog_photos_v181 WHERE id=? AND workspace_id=? LIMIT 1');$stmt->execute([$photoId,$workspaceId]);if(!$stmt->fetchColumn())throw new RuntimeException('Choose a cover image from your own Media Library.');return $photoId;
}
function artist_music_v185_validate_album(PDO $pdo,int $workspaceId,int $albumId): int
{
    if($albumId<1)return 0;if(!artist_music_v185_album($pdo,$workspaceId,$albumId))throw new RuntimeException('Choose an album from your artist workspace.');return $albumId;
}
function artist_music_v185_albums(PDO $pdo,int $workspaceId,bool $includeDrafts=true): array
{
    $sql='SELECT a.*,(SELECT COUNT(*) FROM artist_catalog_tracks_v181 t WHERE t.workspace_id=a.workspace_id AND t.album_id=a.id) AS track_count FROM artist_catalog_albums_v181 a WHERE a.workspace_id=?';if(!$includeDrafts)$sql.=' AND a.is_published=1';$sql.=' ORDER BY a.sort_order ASC,a.release_date DESC,a.id DESC';$stmt=$pdo->prepare($sql);$stmt->execute([$workspaceId]);return $stmt->fetchAll()?:[];
}
function artist_music_v185_tracks(PDO $pdo,int $workspaceId,bool $includeDrafts=true): array
{
    $sql='SELECT t.*,a.title AS album_title,a.cover_photo_id AS album_cover_photo_id FROM artist_catalog_tracks_v181 t LEFT JOIN artist_catalog_albums_v181 a ON a.id=t.album_id AND a.workspace_id=t.workspace_id WHERE t.workspace_id=?';if(!$includeDrafts)$sql.=' AND t.is_published=1';$sql.=' ORDER BY COALESCE(a.sort_order,999999),COALESCE(t.album_id,999999999),t.track_number ASC,t.updated_at DESC,t.id DESC';$stmt=$pdo->prepare($sql);$stmt->execute([$workspaceId]);return $stmt->fetchAll()?:[];
}
function artist_music_v185_can_view(array $row,?array $viewer): bool
{
    if((int)($row['is_published']??0)!==1)return false;return can_view_visibility((string)($row['visibility']??'members'),$viewer);
}
function artist_music_v185_public_track(PDO $pdo,int $trackId,?array $viewer): ?array
{
    $stmt=$pdo->prepare('SELECT t.*,w.artist_user_id FROM artist_catalog_tracks_v181 t INNER JOIN artist_workspaces_v181 w ON w.id=t.workspace_id WHERE t.id=? LIMIT 1');$stmt->execute([$trackId]);$row=$stmt->fetch();if(!$row)return null;$isOwner=$viewer&&(int)($viewer['id']??0)===(int)$row['artist_user_id']&&user_has_role('artist',$viewer);if(!$isOwner&&!artist_music_v185_can_view($row,$viewer))return null;return $row;
}
function artist_music_v185_public_cover(PDO $pdo,string $kind,int $id,?array $viewer): ?array
{
    $stmt=$pdo->prepare($kind==='album'?'SELECT a.*,w.artist_user_id FROM artist_catalog_albums_v181 a INNER JOIN artist_workspaces_v181 w ON w.id=a.workspace_id WHERE a.id=? LIMIT 1':'SELECT t.*,w.artist_user_id FROM artist_catalog_tracks_v181 t INNER JOIN artist_workspaces_v181 w ON w.id=t.workspace_id WHERE t.id=? LIMIT 1');$stmt->execute([$id]);$row=$stmt->fetch();if(!$row)return null;
    $isOwner=$viewer&&(int)($viewer['id']??0)===(int)$row['artist_user_id']&&user_has_role('artist',$viewer);if(!$isOwner&&!artist_music_v185_can_view($row,$viewer))return null;
    $photoId=(int)($row['cover_photo_id']??0);if($photoId<1&&$kind==='track'&&(int)($row['album_id']??0)>0){$a=$pdo->prepare('SELECT cover_photo_id FROM artist_catalog_albums_v181 WHERE id=? AND workspace_id=? LIMIT 1');$a->execute([(int)$row['album_id'],(int)$row['workspace_id']]);$photoId=(int)($a->fetchColumn()?:0);}if($photoId<1)return null;
    $path=artist_media_v182_resolve_photo_file($pdo,(int)$row['workspace_id'],$photoId);return $path?['path'=>$path,'workspace_id'=>(int)$row['workspace_id']]:null;
}
