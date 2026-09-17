<?php
declare(strict_types=1);

/**
 * VP3 v20.40 Browser Share rich-media authority.
 *
 * Browser Share remains the canonical message/share object. This layer stores
 * private media attachments and source-media references keyed to that share.
 * Authorization is always re-resolved through Browser Share before metadata or
 * bytes are returned.
 */
const VP3_BROWSER_SHARE_MEDIA_V2040 = 'browser-share-media-v2040-20260917';
const VP3_BROWSER_SHARE_MEDIA_SCREENSHOT_MAX_V2040 = 8 * 1024 * 1024;
const VP3_BROWSER_SHARE_MEDIA_COMMENTARY_MAX_V2040 = 16 * 1024 * 1024;
const VP3_BROWSER_SHARE_MEDIA_METADATA_MAX_V2040 = 16384;
const VP3_BROWSER_SHARE_MEDIA_CLIP_MAX_SECONDS_V2040 = 90.0;

require_once __DIR__.'/browser-share-v2010.php';

final class VP3BrowserShareMediaExceptionV2040 extends RuntimeException
{
    public function __construct(
        public readonly string $apiCode,
        public readonly int $httpStatus,
        string $message
    ) {
        parent::__construct($message);
    }
}

function vp3_browser_share_media_schema_ready_v2040(?PDO $pdo=null): bool
{
    $pdo ??= db();
    return (bool)$pdo
        && vp3_browser_share_schema_ready_v2010($pdo)
        && table_exists('browser_share_media_v2040')
        && table_exists('browser_share_media_jobs_v2040');
}

function vp3_browser_share_media_ensure_schema_v2040(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_browser_share_media_schema_ready_v2040($pdo))return;
    if($pdo->inTransaction())throw new RuntimeException('Browser Share media schema must be installed before starting a transaction.');
    if(!vp3_browser_share_schema_ready_v2010($pdo))vp3_browser_share_ensure_schema_v2010($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_share_media_v2040 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      browser_share_id BIGINT UNSIGNED NOT NULL,
      uploader_user_id INT UNSIGNED NOT NULL,
      media_kind VARCHAR(32) NOT NULL,
      mime_type VARCHAR(120) NOT NULL DEFAULT '',
      byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
      sha256 CHAR(64) NOT NULL DEFAULT '',
      original_name VARCHAR(255) NOT NULL DEFAULT '',
      storage_key VARCHAR(255) NULL,
      metadata_json TEXT NOT NULL,
      media_status VARCHAR(24) NOT NULL DEFAULT 'ready',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      UNIQUE KEY uq_browser_share_media_public (public_id),
      INDEX idx_browser_share_media_share (browser_share_id,created_at,id),
      INDEX idx_browser_share_media_user (uploader_user_id,created_at,id),
      CONSTRAINT fk_browser_share_media_share FOREIGN KEY (browser_share_id) REFERENCES browser_shares_v2010(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_share_media_user FOREIGN KEY (uploader_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_share_media_jobs_v2040 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      media_id BIGINT UNSIGNED NOT NULL,
      job_type VARCHAR(40) NOT NULL,
      job_status VARCHAR(24) NOT NULL DEFAULT 'queued',
      attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      locked_at DATETIME NULL,
      finished_at DATETIME NULL,
      last_error VARCHAR(1000) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_browser_share_media_jobs_queue (job_status,available_at,id),
      CONSTRAINT fk_browser_share_media_job_media FOREIGN KEY (media_id) REFERENCES browser_share_media_v2040(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_browser_share_media_require_ready_v2040(?PDO $pdo=null): PDO
{
    $pdo ??= db();
    if(!$pdo || !vp3_browser_share_media_schema_ready_v2040($pdo)){
        throw new VP3BrowserShareMediaExceptionV2040('service_unavailable',503,'Browser Share rich media is not ready. Run the database upgrade.');
    }
    return $pdo;
}

function vp3_browser_share_media_uuid_v2040(): string
{
    $hex=bin2hex(random_bytes(16));
    return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&0x3)|0x8).substr($hex,17,3).'-'.substr($hex,20,12);
}

function vp3_browser_share_media_storage_root_v2040(): string
{
    global $config;
    $configured=trim((string)($config['uploads']['browser_share_private_root']??''));
    $root=$configured!==''?$configured:dirname(STONEFELLOW_ROOT).DIRECTORY_SEPARATOR.'vp3-private'.DIRECTORY_SEPARATOR.'browser-share-media';
    return rtrim($root,"/\\");
}

function vp3_browser_share_media_storage_path_v2040(string $storageKey,bool $create=false): string
{
    if(!preg_match('/^[a-f0-9]{2}\/[a-f0-9]{2}\/[a-f0-9]{32}$/',$storageKey)){
        throw new VP3BrowserShareMediaExceptionV2040('invalid_media',404,'Media file was not found.');
    }
    $root=vp3_browser_share_media_storage_root_v2040();
    $path=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$storageKey);
    if($create){
        $dir=dirname($path);
        if(!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)){
            throw new VP3BrowserShareMediaExceptionV2040('storage_unavailable',503,'Private media storage is unavailable.');
        }
    }
    return $path;
}

function vp3_browser_share_media_share_row_v2040(PDO $pdo,string $publicId,int $userId): array
{
    $publicId=trim($publicId);
    if($publicId===''||$userId<1)throw new VP3BrowserShareMediaExceptionV2040('browser_share_not_found',404,'Browser Share was not found.');
    $row=vp3_browser_share_by_public_id_v2010($pdo,$publicId,$userId);
    if(!is_array($row))throw new VP3BrowserShareMediaExceptionV2040('browser_share_not_found',404,'Browser Share is unavailable or access was revoked.');
    return $row;
}

function vp3_browser_share_media_validate_metadata_v2040(mixed $raw,string $kind): array
{
    if(is_string($raw)){
        if(strlen($raw)>VP3_BROWSER_SHARE_MEDIA_METADATA_MAX_V2040)throw new VP3BrowserShareMediaExceptionV2040('payload_too_large',413,'Media metadata is too large.');
        $raw=json_decode($raw,true);
    }
    $input=is_array($raw)?$raw:[];
    $allowed=['width','height','device_pixel_ratio','x','y','duration_seconds','start_seconds','end_seconds','source_media_url','source_media_title','source_media_kind'];
    $out=[];
    foreach($allowed as $key){
        if(!array_key_exists($key,$input))continue;
        $value=$input[$key];
        if(in_array($key,['width','height'],true)){
            $value=max(0,min(20000,(int)$value));
        }elseif(in_array($key,['device_pixel_ratio','x','y','duration_seconds','start_seconds','end_seconds'],true)){
            $value=round(max(0,(float)$value),3);
        }elseif($key==='source_media_url'){
            try{$value=(string)vp3_browser_share_validate_url_v2010($value)['url'];}
            catch(Throwable $e){continue;}
        }else{
            $value=mb_substr(trim((string)$value),0,512);
        }
        $out[$key]=$value;
    }
    if(in_array($kind,['youtube_clip','audio_reference','video_reference'],true)){
        $start=(float)($out['start_seconds']??0);
        $end=(float)($out['end_seconds']??$start);
        if($end<$start)$end=$start;
        if($end-$start>VP3_BROWSER_SHARE_MEDIA_CLIP_MAX_SECONDS_V2040)$end=$start+VP3_BROWSER_SHARE_MEDIA_CLIP_MAX_SECONDS_V2040;
        $out['start_seconds']=round($start,3);
        $out['end_seconds']=round($end,3);
    }
    $encoded=json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($encoded)||strlen($encoded)>VP3_BROWSER_SHARE_MEDIA_METADATA_MAX_V2040){
        throw new VP3BrowserShareMediaExceptionV2040('payload_too_large',413,'Media metadata is too large.');
    }
    return $out;
}

function vp3_browser_share_media_allowed_upload_v2040(string $kind,string $mime,int $bytes): void
{
    $mime=strtolower(trim(explode(';',$mime,2)[0]));
    if($kind==='screenshot'){
        if(!in_array($mime,['image/png','image/jpeg','image/webp'],true))throw new VP3BrowserShareMediaExceptionV2040('unsupported_media_type',415,'Screenshot must be PNG, JPEG, or WebP.');
        if($bytes<1||$bytes>VP3_BROWSER_SHARE_MEDIA_SCREENSHOT_MAX_V2040)throw new VP3BrowserShareMediaExceptionV2040('payload_too_large',413,'Screenshot is too large.');
        return;
    }
    if($kind==='commentary_audio'){
        if(!in_array($mime,['audio/webm','audio/ogg','audio/mp4','audio/mpeg','audio/wav','audio/x-wav'],true))throw new VP3BrowserShareMediaExceptionV2040('unsupported_media_type',415,'Commentary audio format is not supported.');
        if($bytes<1||$bytes>VP3_BROWSER_SHARE_MEDIA_COMMENTARY_MAX_V2040)throw new VP3BrowserShareMediaExceptionV2040('payload_too_large',413,'Commentary audio is too large.');
        return;
    }
    throw new VP3BrowserShareMediaExceptionV2040('unsupported_media_type',415,'This media kind cannot upload binary content.');
}

function vp3_browser_share_media_insert_v2040(PDO $pdo,array $share,int $userId,string $kind,string $mime,int $bytes,string $sha,string $name,?string $storageKey,array $metadata,string $status='ready'): array
{
    $publicId=vp3_browser_share_media_uuid_v2040();
    $encoded=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($encoded))$encoded='{}';
    $stmt=$pdo->prepare("INSERT INTO browser_share_media_v2040(public_id,browser_share_id,uploader_user_id,media_kind,mime_type,byte_size,sha256,original_name,storage_key,metadata_json,media_status) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$publicId,(int)$share['id'],$userId,$kind,$mime,$bytes,$sha,mb_substr($name,0,255),$storageKey,$encoded,$status]);
    $id=(int)$pdo->lastInsertId();
    if($id<1)throw new VP3BrowserShareMediaExceptionV2040('media_create_failed',503,'Media attachment could not be created.');
    if($storageKey!==null){
        $job=$pdo->prepare("INSERT INTO browser_share_media_jobs_v2040(media_id,job_type,job_status) VALUES(?,?,'queued')");
        $job->execute([$id,$kind==='screenshot'?'image.inspect':'audio.inspect']);
    }
    return vp3_browser_share_media_public_v2040([
        'public_id'=>$publicId,'media_kind'=>$kind,'mime_type'=>$mime,'byte_size'=>$bytes,'sha256'=>$sha,
        'original_name'=>$name,'metadata_json'=>$encoded,'media_status'=>$status,'created_at'=>gmdate('Y-m-d H:i:s')
    ]);
}

function vp3_browser_share_media_store_binary_v2040(PDO $pdo,string $browserSharePublicId,int $userId,string $kind,string $mime,string $bytes,string $name='',array $metadata=[]): array
{
    vp3_browser_share_media_require_ready_v2040($pdo);
    $share=vp3_browser_share_media_share_row_v2040($pdo,$browserSharePublicId,$userId);
    vp3_browser_share_media_allowed_upload_v2040($kind,$mime,strlen($bytes));
    $metadata=vp3_browser_share_media_validate_metadata_v2040($metadata,$kind);
    $sha=hash('sha256',$bytes);
    $random=bin2hex(random_bytes(16));
    $key=substr($random,0,2).'/'.substr($random,2,2).'/'.$random;
    $path=vp3_browser_share_media_storage_path_v2040($key,true);
    $tmp=$path.'.tmp-'.bin2hex(random_bytes(4));
    if(@file_put_contents($tmp,$bytes,LOCK_EX)!==strlen($bytes) || !@rename($tmp,$path)){
        @unlink($tmp);
        throw new VP3BrowserShareMediaExceptionV2040('storage_unavailable',503,'Private media storage could not save this attachment.');
    }
    @chmod($path,0600);
    try{
        return vp3_browser_share_media_insert_v2040($pdo,$share,$userId,$kind,strtolower(trim(explode(';',$mime,2)[0])),strlen($bytes),$sha,$name,$key,$metadata,'ready');
    }catch(Throwable $e){
        @unlink($path);
        throw $e;
    }
}

function vp3_browser_share_media_create_reference_v2040(PDO $pdo,string $browserSharePublicId,int $userId,string $kind,array $metadata): array
{
    vp3_browser_share_media_require_ready_v2040($pdo);
    if(!in_array($kind,['youtube_clip','audio_reference','video_reference'],true))throw new VP3BrowserShareMediaExceptionV2040('unsupported_media_type',415,'Unsupported media reference.');
    $share=vp3_browser_share_media_share_row_v2040($pdo,$browserSharePublicId,$userId);
    $metadata=vp3_browser_share_media_validate_metadata_v2040($metadata,$kind);
    if(empty($metadata['source_media_url']))throw new VP3BrowserShareMediaExceptionV2040('invalid_request',422,'A source media URL is required.');
    return vp3_browser_share_media_insert_v2040($pdo,$share,$userId,$kind,'',0,'','',null,$metadata,'ready');
}

function vp3_browser_share_media_public_v2040(array $row): array
{
    $metadata=json_decode((string)($row['metadata_json']??'{}'),true);
    if(!is_array($metadata))$metadata=[];
    return [
        'id'=>(string)($row['public_id']??''),
        'kind'=>(string)($row['media_kind']??''),
        'mime_type'=>(string)($row['mime_type']??''),
        'byte_size'=>(int)($row['byte_size']??0),
        'sha256'=>(string)($row['sha256']??''),
        'original_name'=>(string)($row['original_name']??''),
        'status'=>(string)($row['media_status']??'ready'),
        'metadata'=>$metadata,
        'created_at'=>(string)($row['created_at']??''),
        'content_url'=>!empty($row['storage_key'])?'/api/browser-share-media-v2040.php?media_id='.rawurlencode((string)($row['public_id']??'')):'',
    ];
}

function vp3_browser_share_media_list_v2040(PDO $pdo,string $browserSharePublicId,int $userId): array
{
    vp3_browser_share_media_require_ready_v2040($pdo);
    $share=vp3_browser_share_media_share_row_v2040($pdo,$browserSharePublicId,$userId);
    $stmt=$pdo->prepare("SELECT public_id,media_kind,mime_type,byte_size,sha256,original_name,storage_key,metadata_json,media_status,created_at FROM browser_share_media_v2040 WHERE browser_share_id=? AND deleted_at IS NULL ORDER BY id ASC");
    $stmt->execute([(int)$share['id']]);
    return array_map('vp3_browser_share_media_public_v2040',$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_browser_share_media_file_v2040(PDO $pdo,string $mediaPublicId,int $userId): array
{
    vp3_browser_share_media_require_ready_v2040($pdo);
    $stmt=$pdo->prepare("SELECT m.*,s.public_id AS browser_share_public_id FROM browser_share_media_v2040 m INNER JOIN browser_shares_v2010 s ON s.id=m.browser_share_id AND s.deleted_at IS NULL WHERE m.public_id=? AND m.deleted_at IS NULL LIMIT 1");
    $stmt->execute([trim($mediaPublicId)]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new VP3BrowserShareMediaExceptionV2040('media_not_found',404,'Media file was not found.');
    vp3_browser_share_media_share_row_v2040($pdo,(string)$row['browser_share_public_id'],$userId);
    $key=(string)($row['storage_key']??'');
    if($key==='')throw new VP3BrowserShareMediaExceptionV2040('media_not_found',404,'This attachment has no private media file.');
    $path=vp3_browser_share_media_storage_path_v2040($key,false);
    if(!is_file($path))throw new VP3BrowserShareMediaExceptionV2040('media_not_found',404,'Media file was not found.');
    return ['row'=>$row,'path'=>$path];
}
