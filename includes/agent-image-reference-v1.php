<?php
declare(strict_types=1);

require_once __DIR__.'/agent-image-generation-v1.php';

/** Read only an owned private raster; callers never supply a remote image URL. */
function vp3_image_reference_source_v1(PDO $pdo,array $user,int $assetId,bool $lock=false): array
{
    $owner=(int)($user['id']??0);
    if($owner<1||$assetId<1)throw new InvalidArgumentException('Choose an image from your private Media Library.');
    $query=$pdo->prepare('SELECT * FROM user_media_assets WHERE id=? AND user_id=?'.
        ($lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
    $query->execute([$assetId,$owner]);$asset=$query->fetch(PDO::FETCH_ASSOC);
    if(!$asset||$asset['media_type']!=='photo'||!in_array($asset['mime_type'],['image/jpeg','image/png','image/webp'],true)){
        throw new DomainException('The selected base image is unavailable in your private Media Library.',404);
    }
    $base=realpath(media_studio_private_root());$root=realpath(media_studio_user_root($owner));
    $path=realpath(STONEFELLOW_ROOT.'/'.ltrim((string)$asset['file_path'],'/'));
    if(!$base||$root!==$base.DIRECTORY_SEPARATOR.$owner||!is_string($path)
        ||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)||!is_file($path)){
        throw new DomainException('The selected base image file is unavailable.',409);
    }
    $bytes=@file_get_contents($path,false,null,0,4*1024*1024+1);
    if(!is_string($bytes)||$bytes===''||strlen($bytes)>4*1024*1024||strlen($bytes)!==(int)$asset['file_size']){
        throw new DomainException('Choose a complete JPEG, PNG or WebP base image up to 4 MB.',422);
    }
    $image=@getimagesizefromstring($bytes);
    if(!$image||($image['mime']??'')!==$asset['mime_type']||min($image[0],$image[1])<1
        ||max($image[0],$image[1])>4096||$image[0]*$image[1]>16777216){
        throw new DomainException('The base image must be a valid JPEG, PNG or WebP of at most 4096 pixels per side.',422);
    }
    if($asset['mime_type']==='image/png'){
        try{vp3_image_png_check_v1($bytes);}catch(RuntimeException $error){
            throw new DomainException('The PNG base image is incomplete or damaged.',422,$error);
        }
    }
    $digest=hash('sha256',$bytes);
    $revision=hash('sha256',json_encode([$owner,$assetId,$asset['file_path'],$asset['mime_type'],
        (int)$asset['file_size'],(string)($asset['updated_at']??''),$digest],JSON_THROW_ON_ERROR));
    return ['asset_id'=>$assetId,'sha256'=>$digest,'revision'=>$revision,'mime_type'=>$asset['mime_type'],
        'width'=>$image[0],'height'=>$image[1],'title'=>(string)$asset['title'],'bytes'=>$bytes];
}

/** Validate an attachment before dispatching the conversational request; sends no pixels. */
function vp3_image_reference_validate_v1(PDO $pdo,array $user,array $principal,array $context,int $assetId): array
{
    $user=vp3_image_scope_v1($pdo,$user,$principal,$context);
    $source=vp3_image_reference_source_v1($pdo,$user,$assetId,$pdo->inTransaction());
    unset($source['bytes']);return $source;
}

function vp3_image_reference_cloud_v1(PDO $pdo,array $user,int $agentId): void
{
    if(!function_exists('vp3_agent_runtime_plan_v420'))require_once __DIR__.'/agent-runtime-routing-v420.php';
    $route=vp3_agent_runtime_plan_v420($pdo,$user,$agentId,'chat');
    if(($route['route']??null)!=='vp3_cloud'||empty($route['cloud']['ready'])||!empty($route['blocked'])){
        throw new DomainException('Base-image generation needs this Agent’s permitted VP3 Cloud route.',503);
    }
}

/** Recheck the frozen source and current route before dispatch and atomic publication. */
function vp3_image_reference_job_source_v1(PDO $pdo,array $user,array $row,array $config,bool $lock=false,bool $pixels=false): ?string
{
    $reference=$config['reference']??null;
    if(($config['mode']??null)!=='chat_reference'||(int)($row['user_message_id']??0)<1
        ||!is_array($reference)||!is_int($reference['asset_id']??null)||$reference['asset_id']<1
        ||!is_string($reference['sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/',$reference['sha256'])
        ||!is_string($reference['revision']??null)||!preg_match('/\A[a-f0-9]{64}\z/',$reference['revision'])){
        throw new RuntimeException('image_reference_changed');
    }
    $user=vp3_image_authority_v1($pdo,$user,$row);
    vp3_image_reference_cloud_v1($pdo,$user,(int)$row['agent_id']);
    $source=vp3_image_reference_source_v1($pdo,$user,$reference['asset_id'],$lock);
    if(!hash_equals($reference['sha256'],$source['sha256'])||!hash_equals($reference['revision'],$source['revision'])){
        throw new RuntimeException('image_reference_changed');
    }
    return $pixels?'data:'.$source['mime_type'].';base64,'.base64_encode($source['bytes']):null;
}
