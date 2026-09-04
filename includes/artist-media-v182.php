<?php
declare(strict_types=1);

function artist_media_v182_schema_ready(): bool
{
    return table_exists('artist_catalog_photos_v181')
        && column_exists('artist_catalog_photos_v181','caption')
        && column_exists('artist_catalog_photos_v181','alt_text')
        && column_exists('artist_catalog_photos_v181','sort_order');
}

function artist_media_v182_ensure_schema(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo) throw new RuntimeException('Database connection is unavailable.');
    artist_workspace_v181_ensure_schema($pdo);
    if(!column_exists('artist_catalog_photos_v181','caption')) $pdo->exec("ALTER TABLE artist_catalog_photos_v181 ADD COLUMN caption TEXT NULL AFTER title");
    if(!column_exists('artist_catalog_photos_v181','alt_text')) $pdo->exec("ALTER TABLE artist_catalog_photos_v181 ADD COLUMN alt_text VARCHAR(255) NOT NULL DEFAULT '' AFTER caption");
    if(!column_exists('artist_catalog_photos_v181','sort_order')) $pdo->exec("ALTER TABLE artist_catalog_photos_v181 ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER visibility");
}

function artist_media_v182_root(): string
{
    return STONEFELLOW_ROOT.'/uploads/artist-media';
}

function artist_media_v182_workspace_photo_dir(int $workspaceId): string
{
    return artist_media_v182_root().'/'.$workspaceId.'/photos';
}

function artist_media_v182_store_photo(array $file,int $workspaceId): string
{
    if($workspaceId<1) throw new RuntimeException('Artist workspace is required.');
    $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
    if($error===UPLOAD_ERR_NO_FILE) return '';
    if($error!==UPLOAD_ERR_OK) throw new RuntimeException('Photo upload failed.');
    global $config;
    $max=max(1,(int)($config['uploads']['max_image_bytes']??(8*1024*1024)));
    $size=(int)($file['size']??0);
    if($size<1 || $size>$max) throw new RuntimeException('Photo is larger than the configured image upload limit.');
    $tmp=(string)($file['tmp_name']??'');
    if($tmp==='' || !is_uploaded_file($tmp)) throw new RuntimeException('Invalid photo upload.');
    $info=@getimagesize($tmp);
    $mime=is_array($info)?strtolower((string)($info['mime']??'')):'';
    $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if(!isset($extensions[$mime])) throw new RuntimeException('Use a JPG, PNG, or WebP image.');
    $dir=artist_media_v182_workspace_photo_dir($workspaceId);
    if(!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) throw new RuntimeException('Artist media directory could not be created.');
    $root=artist_media_v182_root();
    if(!is_dir($root) && !mkdir($root,0750,true) && !is_dir($root)) throw new RuntimeException('Artist media storage is unavailable.');
    if(!is_file($root.'/.htaccess')) @file_put_contents($root.'/.htaccess',"Require all denied\nDeny from all\n");
    $name=bin2hex(random_bytes(18)).'.'.$extensions[$mime];
    $target=$dir.'/'.$name;
    if(!move_uploaded_file($tmp,$target)) throw new RuntimeException('Photo could not be saved.');
    @chmod($target,0640);
    return '/uploads/artist-media/'.$workspaceId.'/photos/'.$name;
}

function artist_media_v182_resolve_stored_photo(int $workspaceId,string $publicPath): ?string
{
    if($workspaceId<1 || $publicPath==='') return null;
    $publicPath=str_replace('\\','/',$publicPath);
    $workspacePrefix='/uploads/artist-media/'.$workspaceId.'/photos/';
    if(str_starts_with($publicPath,$workspacePrefix)){
        $base=realpath(artist_media_v182_workspace_photo_dir($workspaceId));
    } elseif(str_starts_with($publicPath,'/uploads/photos/')) {
        // Read-only compatibility for v181 rows created before artist-scoped media storage.
        $base=realpath(STONEFELLOW_ROOT.'/uploads/photos');
    } else return null;
    $absolute=realpath(STONEFELLOW_ROOT.'/'.ltrim($publicPath,'/'));
    if(!$base || !$absolute || !is_file($absolute)) return null;
    if(!str_starts_with($absolute,rtrim($base,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) return null;
    return $absolute;
}

function artist_media_v182_photo(PDO $pdo,int $workspaceId,int $photoId): ?array
{
    if($workspaceId<1 || $photoId<1) return null;
    $stmt=$pdo->prepare('SELECT * FROM artist_catalog_photos_v181 WHERE id=? AND workspace_id=? LIMIT 1');
    $stmt->execute([$photoId,$workspaceId]);
    return $stmt->fetch()?:null;
}

function artist_media_v182_resolve_photo_file(PDO $pdo,int $workspaceId,int $photoId): ?string
{
    $row=artist_media_v182_photo($pdo,$workspaceId,$photoId);
    return $row?artist_media_v182_resolve_stored_photo($workspaceId,(string)$row['image_path']):null;
}

function artist_media_v182_delete_owned_photo(int $workspaceId,string $publicPath): void
{
    $prefix='/uploads/artist-media/'.$workspaceId.'/photos/';
    if(!str_starts_with(str_replace('\\','/',$publicPath),$prefix)) return;
    $path=artist_media_v182_resolve_stored_photo($workspaceId,$publicPath);
    if($path) @unlink($path);
}

function artist_media_v182_copy_photo_to_profile(PDO $pdo,int $workspaceId,int $photoId,string $kind): string
{
    if(!in_array($kind,['profile','cover'],true)) throw new RuntimeException('Invalid profile image type.');
    $source=artist_media_v182_resolve_photo_file($pdo,$workspaceId,$photoId);
    if(!$source) throw new RuntimeException('That photo is not available in your artist workspace.');
    $info=@getimagesize($source);
    $mime=is_array($info)?(string)($info['mime']??''):'';
    $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if(!isset($extensions[$mime])) throw new RuntimeException('Choose a JPG, PNG, or WebP photo.');
    $dir=artist_workspace_v181_profile_image_root().'/'.$workspaceId;
    if(!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) throw new RuntimeException('Profile image storage is unavailable.');
    $filename=$kind.'-media-'.bin2hex(random_bytes(16)).'.'.$extensions[$mime];
    $target=$dir.'/'.$filename;
    if(!copy($source,$target)) throw new RuntimeException('Selected photo could not be copied.');
    @chmod($target,0640);
    return 'uploads/artist-profiles/'.$workspaceId.'/'.$filename;
}

function artist_media_v182_picker(PDO $pdo,int $workspaceId,int $limit=120): array
{
    $limit=max(1,min(250,$limit));
    $stmt=$pdo->prepare("SELECT id,title,caption,alt_text,image_path,visibility,is_published,sort_order,updated_at FROM artist_catalog_photos_v181 WHERE workspace_id=? ORDER BY sort_order ASC,updated_at DESC,id DESC LIMIT {$limit}");
    $stmt->execute([$workspaceId]);
    return $stmt->fetchAll()?:[];
}
