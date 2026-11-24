<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

/** Small private attachments in the existing information ledger. No public file URL. */
const VP3_PHONE_FILE_MAX_V1=2097152;
const VP3_PHONE_FILE_TTL_V1=604800;
const VP3_PHONE_FILE_TYPES_V1=['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf','text/plain'=>'txt'];

function vp3_phone_file_install_v1(PDO $pdo,string $options):void
{
    if(vp3_phone_transaction_active_v1($pdo))throw new LogicException('Install phone files outside transactions.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_information_files_v1(
        owner_user_id INT UNSIGNED NOT NULL,device_id CHAR(32) NOT NULL,exchange_id CHAR(32) NOT NULL,
        mime VARCHAR(32) NOT NULL,byte_count INTEGER NOT NULL,digest CHAR(64) NOT NULL,
        data MEDIUMBLOB NOT NULL,created_epoch BIGINT NOT NULL,expires_epoch BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,device_id,exchange_id),
        FOREIGN KEY(owner_user_id,device_id,exchange_id) REFERENCES vp3_phone_information_v1(owner_user_id,device_id,exchange_id) ON DELETE CASCADE
    )".$options);
    if(!vp3_phone_file_ready_v1($pdo))throw new RuntimeException('Phone attachment upgrade incomplete.');
}
function vp3_phone_file_ready_v1(PDO $pdo):bool
{
    try{$pdo->query('SELECT owner_user_id,device_id,exchange_id,mime,byte_count,digest,data,created_epoch,expires_epoch FROM vp3_phone_information_files_v1 WHERE 1=0');return true;}
    catch(PDOException){return false;}
}
function vp3_phone_file_available_v1(PDO $pdo):bool
{return vp3_phone_file_ready_v1($pdo)&&class_exists('finfo')&&function_exists('getimagesizefromstring');}
function vp3_phone_file_request_kind_v1(string $kind):bool{return in_array($kind,['photo_request','file_request'],true);}
function vp3_phone_file_scope_v1(array $r):array
{return [(int)$r['owner_user_id'],$r['device_id'],$r['exchange_id']];}
function vp3_phone_file_parse_v1(mixed $encoded,bool $photoOnly):array
{
    vp3_phone_information_need_v1(is_string($encoded)&&strlen($encoded)>0&&strlen($encoded)<=4*(int)ceil(VP3_PHONE_FILE_MAX_V1/3),413);
    $bytes=base64_decode($encoded,true);
    vp3_phone_information_need_v1(is_string($bytes)&&strlen($bytes)>0&&strlen($bytes)<=VP3_PHONE_FILE_MAX_V1&&hash_equals(base64_encode($bytes),$encoded),422);
    vp3_phone_information_need_v1(class_exists('finfo')&&function_exists('getimagesizefromstring'),503);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    vp3_phone_information_need_v1(is_string($mime)&&isset(VP3_PHONE_FILE_TYPES_V1[$mime]),422);
    if($photoOnly)vp3_phone_information_need_v1(in_array($mime,['image/jpeg','image/png'],true),422);
    if($mime==='image/jpeg'||$mime==='image/png'){
        $size=@getimagesizefromstring($bytes);
        vp3_phone_information_need_v1(is_array($size)&&($size['mime']??'')===$mime&&$size[0]>0&&$size[1]>0
            &&$size[0]<=8192&&$size[1]<=8192&&$size[0]*$size[1]<=24000000,422);
        if($mime==='image/jpeg')vp3_phone_information_need_v1(str_starts_with($bytes,"\xFF\xD8\xFF")&&str_ends_with($bytes,"\xFF\xD9"),422);
        else vp3_phone_information_need_v1(str_starts_with($bytes,"\x89PNG\r\n\x1a\n")&&str_ends_with($bytes,"\x00\x00\x00\x00IEND\xAE\x42\x60\x82"),422);
    }elseif($mime==='application/pdf'){
        vp3_phone_information_need_v1(preg_match('/\A%PDF-[12]\.[0-9]/D',$bytes)===1&&preg_match('/%%EOF[ \t\r\n]*\z/D',$bytes)===1,422);
    }else vp3_phone_information_need_v1(preg_match('//u',$bytes)===1&&preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$bytes)!==1,422);
    return ['mime'=>$mime,'byte_count'=>strlen($bytes),'digest'=>hash('sha256',$bytes),'bytes'=>$bytes];
}
function vp3_phone_file_metadata_v1(PDO $pdo,array $scope):?array
{
    if(!vp3_phone_file_ready_v1($pdo))return null;
    $r=vp3_phone_sql_v1($pdo,'SELECT mime,byte_count,digest,expires_epoch FROM vp3_phone_information_files_v1 WHERE owner_user_id=? AND device_id=? AND exchange_id=?',$scope)->fetch(PDO::FETCH_ASSOC);
    return $r===false?null:$r;
}
function vp3_phone_file_view_v1(array $r,array $p,int $now,?array $meta):?array
{
    if(!$meta||!isset(VP3_PHONE_FILE_TYPES_V1[$meta['mime']])||(int)$meta['byte_count']<1||(int)$meta['byte_count']>VP3_PHONE_FILE_MAX_V1
        ||(int)$meta['expires_epoch']<=$now||(int)$r['profile_revision']!==(int)$p['revision'])return null;
    $state=vp3_phone_information_state_v1($r,$p,$now);
    if(($r['kind']==='file_share'&&!in_array($state,['pending','acknowledged'],true))
        ||(vp3_phone_file_request_kind_v1($r['kind'])&&$state!=='completed')
        ||($r['kind']!=='file_share'&&!vp3_phone_file_request_kind_v1($r['kind'])))return null;
    return ['filename'=>'vp3-'.substr($r['exchange_id'],0,12).'.'.VP3_PHONE_FILE_TYPES_V1[$meta['mime']],
        'mime'=>$meta['mime'],'bytes'=>(int)$meta['byte_count'],'expires_epoch'=>(int)$meta['expires_epoch']];
}
function vp3_phone_file_drop_v1(PDO $pdo,array $scope):void
{
    if(vp3_phone_file_ready_v1($pdo))vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_information_files_v1 WHERE owner_user_id=? AND device_id=? AND exchange_id=?',$scope);
}
function vp3_phone_file_fence_v1(PDO $pdo,array $profile):void
{
    if(vp3_phone_file_ready_v1($pdo))vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_information_files_v1 WHERE owner_user_id=? AND device_id=?',[(int)$profile['owner_user_id'],$profile['device_id']]);
}
/** Account lock held. No external storage, user-controlled paths, or overwrite. */
function vp3_phone_file_store_v1(PDO $pdo,array $scope,array $file,int $now):void
{
    vp3_phone_information_need_v1(vp3_phone_file_available_v1($pdo),503);
    vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_information_files_v1 WHERE owner_user_id=? AND expires_epoch<=?',[$scope[0],$now]);
    $q=vp3_phone_sql_v1($pdo,'SELECT COALESCE(SUM(byte_count),0) FROM vp3_phone_information_files_v1 WHERE owner_user_id=?',[$scope[0]]);
    vp3_phone_information_need_v1((int)$q->fetchColumn()+$file['byte_count']<=20*1024*1024,429);
    $q->closeCursor();
    $q=vp3_phone_sql_v1($pdo,'SELECT COALESCE(SUM(byte_count),0) FROM vp3_phone_information_files_v1 WHERE owner_user_id=? AND device_id=?',[$scope[0],$scope[1]]);
    vp3_phone_information_need_v1((int)$q->fetchColumn()+$file['byte_count']<=6*1024*1024,429);
    $q->closeCursor();
    $q=$pdo->prepare('INSERT INTO vp3_phone_information_files_v1(owner_user_id,device_id,exchange_id,mime,byte_count,digest,data,created_epoch,expires_epoch) VALUES(?,?,?,?,?,?,?,?,?)');
    $values=[...$scope,$file['mime'],$file['byte_count'],$file['digest'],$file['bytes'],$now,$now+VP3_PHONE_FILE_TTL_V1];
    foreach($values as $i=>$value)$q->bindValue($i+1,$value,$i===6?PDO::PARAM_LOB:(is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR));
    $q->execute();
}
function vp3_phone_file_download_v1(PDO $pdo,array $r,array $p,int $now):array
{
    $scope=vp3_phone_file_scope_v1($r);$meta=vp3_phone_file_metadata_v1($pdo,$scope);
    $view=vp3_phone_file_view_v1($r,$p,$now,$meta);
    vp3_phone_information_need_v1($view!==null,404);
    $q=vp3_phone_sql_v1($pdo,'SELECT data FROM vp3_phone_information_files_v1 WHERE owner_user_id=? AND device_id=? AND exchange_id=?',$scope);
    $raw=$q->fetchColumn();$q->closeCursor();
    if(is_resource($raw))$raw=stream_get_contents($raw,VP3_PHONE_FILE_MAX_V1+1);
    vp3_phone_information_need_v1(is_string($raw)&&strlen($raw)===$view['bytes']&&hash_equals($meta['digest'],hash('sha256',$raw)),503);
    return $view+['base64'=>base64_encode($raw),'sha256'=>$meta['digest']];
}
/** Read a bounded JSON body only on the explicitly selected upload path. */
function vp3_phone_file_http_json_v1(array $actions):array
{
    $type=strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'',2)[0]));
    if($type!=='application/json')throw new RuntimeException('Use JSON.',415);
    $limit=4*(int)ceil(VP3_PHONE_FILE_MAX_V1/3)+8192;
    if((int)($_SERVER['CONTENT_LENGTH']??0)>$limit)throw new RuntimeException('Attachment request too large.',413);
    $raw=file_get_contents('php://input',false,null,0,$limit+1);
    if(!is_string($raw)||strlen($raw)>$limit)throw new RuntimeException('Attachment request too large.',413);
    $input=json_decode($raw,true,6);
    if(!is_array($input)||!str_starts_with(ltrim($raw),'{')||!in_array($input['action']??null,$actions,true))
        throw new RuntimeException('Unknown attachment upload action.',422);
    return $input;
}
