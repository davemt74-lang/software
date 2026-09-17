<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/browser-share-media-v2040.php';

if(PHP_SAPI!=='cli'){
    http_response_code(404);
    exit;
}

$pdo=vp3_browser_share_media_require_ready_v2040(db());
$limit=max(1,min(50,(int)($argv[1]??10)));
$processed=0;

while($processed<$limit){
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->query("SELECT id,media_id,job_type FROM browser_share_media_jobs_v2040 WHERE job_status='queued' AND available_at<=UTC_TIMESTAMP() ORDER BY id ASC LIMIT 1 FOR UPDATE");
        $job=$stmt?$stmt->fetch(PDO::FETCH_ASSOC):false;
        if(!$job){$pdo->commit();break;}
        $claim=$pdo->prepare("UPDATE browser_share_media_jobs_v2040 SET job_status='processing',attempts=attempts+1,locked_at=UTC_TIMESTAMP() WHERE id=? AND job_status='queued'");
        $claim->execute([(int)$job['id']]);
        if($claim->rowCount()!==1){$pdo->rollBack();continue;}
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }

    $jobId=(int)$job['id'];
    $mediaId=(int)$job['media_id'];
    try{
        $stmt=$pdo->prepare("SELECT id,media_kind,mime_type,storage_key,metadata_json FROM browser_share_media_v2040 WHERE id=? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$mediaId]);
        $media=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$media)throw new RuntimeException('Media record no longer exists.');
        $key=(string)($media['storage_key']??'');
        if($key==='')throw new RuntimeException('Media record has no private storage key.');
        $path=vp3_browser_share_media_storage_path_v2040($key,false);
        if(!is_file($path))throw new RuntimeException('Private media file is missing.');
        $metadata=json_decode((string)($media['metadata_json']??'{}'),true);
        if(!is_array($metadata))$metadata=[];

        if((string)$media['media_kind']==='screenshot'){
            $info=@getimagesize($path);
            if(!is_array($info)||empty($info[0])||empty($info[1]))throw new RuntimeException('Screenshot failed image inspection.');
            $metadata['width']=(int)$info[0];
            $metadata['height']=(int)$info[1];
        }

        $encoded=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($encoded))$encoded='{}';
        $pdo->beginTransaction();
        $update=$pdo->prepare("UPDATE browser_share_media_v2040 SET metadata_json=?,media_status='ready' WHERE id=?");
        $update->execute([$encoded,$mediaId]);
        $finish=$pdo->prepare("UPDATE browser_share_media_jobs_v2040 SET job_status='done',finished_at=UTC_TIMESTAMP(),last_error='' WHERE id=?");
        $finish->execute([$jobId]);
        $pdo->commit();
        $processed++;
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $message=mb_substr($e->getMessage(),0,1000);
        $pdo->beginTransaction();
        $fail=$pdo->prepare("UPDATE browser_share_media_jobs_v2040 SET job_status=IF(attempts>=3,'failed','queued'),available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),finished_at=IF(attempts>=3,UTC_TIMESTAMP(),NULL),last_error=? WHERE id=?");
        $fail->execute([$message,$jobId]);
        $mediaFail=$pdo->prepare("UPDATE browser_share_media_v2040 SET media_status=IF((SELECT attempts FROM browser_share_media_jobs_v2040 WHERE id=?)>=3,'failed','processing') WHERE id=?");
        $mediaFail->execute([$jobId,$mediaId]);
        $pdo->commit();
        fwrite(STDERR,"Browser Share media job {$jobId}: {$message}\n");
        $processed++;
    }
}

fwrite(STDOUT,"Processed {$processed} Browser Share media job(s).\n");
