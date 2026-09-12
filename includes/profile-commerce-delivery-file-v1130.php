<?php
declare(strict_types=1);

require_once __DIR__.'/profile-commerce-delivery-v1120.php';

/** VP3 Profile Commerce v11.30 — receipt-gated private file delivery. */
const VP3_PROFILE_COMMERCE_DELIVERY_FILE_V1130='profile-commerce-delivery-file-v1130-20260912';
const VP3_PROFILE_COMMERCE_DELIVERY_FILE_MAX_BYTES_V1130=52428800;

function profile_commerce_delivery_file_types_v1130(): array
{
    return [
        'application/pdf'=>'pdf',
        'application/zip'=>'zip',
        'text/plain'=>'txt',
        'text/csv'=>'csv',
        'application/json'=>'json',
        'image/jpeg'=>'jpg',
        'image/png'=>'png',
        'image/webp'=>'webp',
        'audio/mpeg'=>'mp3',
        'audio/wav'=>'wav',
        'audio/x-wav'=>'wav',
        'video/mp4'=>'mp4',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation'=>'pptx',
    ];
}

function profile_commerce_delivery_file_storage_root_v1130(): string
{
    return dirname(__DIR__).'/private/commerce-delivery';
}

function profile_commerce_delivery_file_dir_v1130(int $ownerUserId,int $orderId,bool $create=false): string
{
    if($ownerUserId<1||$orderId<1)throw new RuntimeException('Invalid delivery file scope.');
    $root=profile_commerce_delivery_file_storage_root_v1130();
    $dir=$root.'/'.$ownerUserId.'/'.$orderId;
    if($create&&!is_dir($dir)){
        if(!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Private delivery storage is not writable.');
        @chmod($root,0700);@chmod(dirname($dir),0700);@chmod($dir,0700);
    }
    return $dir;
}

function profile_commerce_delivery_file_metadata_v1130(array $order): ?array
{
    $meta=profile_commerce_order_metadata_v900($order);$file=$meta['customer_delivery_file']??null;if(!is_array($file))return null;
    $id=strtolower(trim((string)($file['file_id']??'')));$ext=strtolower(trim((string)($file['extension']??'')));$mime=strtolower(trim((string)($file['mime']??'')));
    if(!preg_match('/\A[a-f0-9]{48}\z/',$id)||!preg_match('/\A[a-z0-9]{1,8}\z/',$ext)||!isset(profile_commerce_delivery_file_types_v1130()[$mime]))return null;
    $expectedExt=profile_commerce_delivery_file_types_v1130()[$mime];if($expectedExt!==$ext)return null;
    $name=mb_strimwidth(trim((string)($file['original_name']??'Download')),0,180,'');if($name==='')$name='Download.'.$ext;
    $sha=strtolower(trim((string)($file['sha256']??'')));if(!preg_match('/\A[a-f0-9]{64}\z/',$sha))return null;
    return [
        'file_id'=>$id,
        'extension'=>$ext,
        'original_name'=>$name,
        'mime'=>$mime,
        'bytes'=>max(0,(int)($file['bytes']??0)),
        'sha256'=>$sha,
        'uploaded_at'=>(string)($file['uploaded_at']??''),
    ];
}

function profile_commerce_delivery_file_path_v1130(int $ownerUserId,int $orderId,array $file): string
{
    $id=(string)($file['file_id']??'');$ext=(string)($file['extension']??'');
    if(!preg_match('/\A[a-f0-9]{48}\z/',$id)||!preg_match('/\A[a-z0-9]{1,8}\z/',$ext))throw new RuntimeException('Invalid delivery file metadata.');
    return profile_commerce_delivery_file_dir_v1130($ownerUserId,$orderId,false).'/'.$id.'.'.$ext;
}

function profile_commerce_delivery_file_for_customer_v1130(array $order): ?array
{
    if(!in_array((string)($order['payment_status']??''),['paid','partially_refunded'],true))return null;
    return profile_commerce_delivery_file_metadata_v1130($order);
}

function profile_commerce_delivery_file_upload_v1130(PDO $pdo,int $ownerUserId,int $orderId,array $upload): array
{
    $order=profile_commerce_order_for_owner_v900($pdo,$ownerUserId,$orderId);if(!$order)throw new RuntimeException('Profile Commerce order not found.');
    if(!profile_commerce_delivery_eligible_v1120($order))throw new RuntimeException('Files can be delivered only for fully paid generic digital/manual Profile Commerce orders.');

    $error=(int)($upload['error']??UPLOAD_ERR_NO_FILE);if($error!==UPLOAD_ERR_OK)throw new RuntimeException($error===UPLOAD_ERR_NO_FILE?'Choose a file to upload.':'The delivery file upload failed.');
    $tmp=(string)($upload['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('The delivery upload was not accepted by PHP.');
    $bytes=(int)@filesize($tmp);if($bytes<1||$bytes>VP3_PROFILE_COMMERCE_DELIVERY_FILE_MAX_BYTES_V1130)throw new RuntimeException('Delivery files must be between 1 byte and 50 MB.');
    $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=strtolower(trim((string)$finfo->file($tmp)));$types=profile_commerce_delivery_file_types_v1130();if(!isset($types[$mime]))throw new RuntimeException('This delivery file type is not allowed.');
    $ext=$types[$mime];$name=basename(str_replace(["\0","\r","\n"],' ',(string)($upload['name']??'')));$name=mb_strimwidth(trim($name),0,180,'');if($name==='')$name='Download.'.$ext;
    $fileId=bin2hex(random_bytes(24));$dir=profile_commerce_delivery_file_dir_v1130($ownerUserId,$orderId,true);$target=$dir.'/'.$fileId.'.'.$ext;
    if(!move_uploaded_file($tmp,$target))throw new RuntimeException('The delivery file could not be moved into private storage.');
    @chmod($target,0600);$sha=(string)hash_file('sha256',$target);if(!preg_match('/\A[a-f0-9]{64}\z/',$sha)){@unlink($target);throw new RuntimeException('The delivery file could not be verified.');}

    $oldFile=null;
    try{
        $pdo->beginTransaction();
        $stmt=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE id=? AND owner_user_id=? LIMIT 1 FOR UPDATE');$stmt->execute([$orderId,$ownerUserId]);$locked=$stmt->fetch();
        if(!$locked||!profile_commerce_delivery_eligible_v1120($locked))throw new RuntimeException('Files can be delivered only for fully paid generic digital/manual Profile Commerce orders.');
        $oldFile=profile_commerce_delivery_file_metadata_v1130($locked);$meta=profile_commerce_order_metadata_v900($locked);$meta['customer_delivery_file']=[
            'file_id'=>$fileId,'extension'=>$ext,'original_name'=>$name,'mime'=>$mime,'bytes'=>$bytes,'sha256'=>$sha,'uploaded_at'=>gmdate('c'),
        ];
        $pdo->prepare('UPDATE agent_commerce_orders_v800 SET metadata_json=?,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([
            json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$orderId,$ownerUserId
        ]);
        agent_commerce_audit_v800($pdo,$orderId,$ownerUserId,(int)($locked['workspace_owner_user_id']??0)?:null,'user',$ownerUserId,null,'profile_delivery_file_saved',(string)$locked['payment_status'],(string)$locked['payment_status'],0,[
            'mime'=>$mime,'bytes'=>$bytes,'replaced'=>$oldFile!==null
        ]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();@unlink($target);throw $e;}

    if($oldFile){try{$oldPath=profile_commerce_delivery_file_path_v1130($ownerUserId,$orderId,$oldFile);if($oldPath!==$target&&is_file($oldPath))@unlink($oldPath);}catch(Throwable $e){}}
    return profile_commerce_order_for_owner_v900($pdo,$ownerUserId,$orderId)?:throw new RuntimeException('Profile Commerce order could not be reloaded.');
}

function profile_commerce_delivery_file_remove_v1130(PDO $pdo,int $ownerUserId,int $orderId): array
{
    $order=profile_commerce_order_for_owner_v900($pdo,$ownerUserId,$orderId);if(!$order)throw new RuntimeException('Profile Commerce order not found.');
    $removed=null;
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE id=? AND owner_user_id=? LIMIT 1 FOR UPDATE');$stmt->execute([$orderId,$ownerUserId]);$locked=$stmt->fetch();if(!$locked||!profile_commerce_order_is_profile_v900($locked))throw new RuntimeException('Profile Commerce order not found.');
        $removed=profile_commerce_delivery_file_metadata_v1130($locked);if(!$removed){$pdo->commit();return profile_commerce_order_for_owner_v900($pdo,$ownerUserId,$orderId)?:$order;}
        $meta=profile_commerce_order_metadata_v900($locked);unset($meta['customer_delivery_file']);
        $pdo->prepare('UPDATE agent_commerce_orders_v800 SET metadata_json=?,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([
            json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$orderId,$ownerUserId
        ]);
        agent_commerce_audit_v800($pdo,$orderId,$ownerUserId,(int)($locked['workspace_owner_user_id']??0)?:null,'user',$ownerUserId,null,'profile_delivery_file_removed',(string)$locked['payment_status'],(string)$locked['payment_status'],0,[
            'mime'=>$removed['mime'],'bytes'=>$removed['bytes']
        ]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if($removed){try{$path=profile_commerce_delivery_file_path_v1130($ownerUserId,$orderId,$removed);if(is_file($path))@unlink($path);}catch(Throwable $e){}}
    return profile_commerce_order_for_owner_v900($pdo,$ownerUserId,$orderId)?:throw new RuntimeException('Profile Commerce order could not be reloaded.');
}

function profile_commerce_delivery_file_size_label_v1130(int $bytes): string
{
    $bytes=max(0,$bytes);if($bytes>=1048576)return number_format($bytes/1048576,1).' MB';if($bytes>=1024)return number_format($bytes/1024,1).' KB';return $bytes.' B';
}
