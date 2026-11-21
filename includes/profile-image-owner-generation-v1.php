<?php
declare(strict_types=1);

/**
 * Private owner Profile image generation: a chosen public-free catalog image is
 * a SOURCE, never a public visitor turn or permission to send visitor messages.
 * The image job remains the canonical Media Library job/credit/asset workflow.
 */
function vp3_profile_owner_image_selection_v1(mixed $input): array
{
    if(!is_array($input) || array_is_list($input) ||
       array_diff(array_keys($input),['username','catalog_id','catalog_revision','image_id']) ||
       count($input)!==4)throw new InvalidArgumentException('Select one reference image from your Profile collection.');
    $name=$input['username']??null;
    if(!is_string($name) || !preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/iD',$name))
        throw new InvalidArgumentException('Reload the Profile before choosing an image.');
    foreach(['catalog_id','catalog_revision','image_id'] as $field)
        if(!is_int($input[$field]??null) || $input[$field]<1 || $input[$field]>9007199254740991)
            throw new InvalidArgumentException('Choose a current approved image in your collection.');
    return ['username'=>$name,'catalog_id'=>$input['catalog_id'],
        'catalog_revision'=>$input['catalog_revision'],'image_id'=>$input['image_id']];
}

function vp3_profile_owner_image_frozen_source_v1(PDO $pdo,array $owner,array $selection,bool $lock=false): array
{
    if(!function_exists('vp3_profile_image_catalog_public_reference_v1'))require_once __DIR__.'/profile-image-catalog-v1.php';
    $selection=vp3_profile_owner_image_selection_v1($selection);
    $uid=(int)($owner['id']??0);
    $profile=profile_by_username($pdo,$selection['username']);
    if($uid<1||!is_array($profile)||(int)($profile['user_id']??0)!==$uid||empty($profile['is_active']))
        throw new DomainException('You can only generate from your own active Profile collection.',403);
    $grant=vp3_profile_image_catalog_public_reference_v1($pdo,$uid,$selection['catalog_id'],
        $selection['image_id'],$selection['catalog_revision']);
    $source=vp3_image_reference_source_v1($pdo,$owner,$selection['image_id'],$lock);
    if((int)$grant['byte_size']!==strlen($source['bytes']) ||
       !hash_equals((string)$grant['source_sha256'],$source['sha256']) ||
       !hash_equals((string)$grant['source_revision'],$source['revision']) ||
       $grant['mime_type']!==$source['mime_type'])
        throw new DomainException('This reference image changed. Refresh the collection and select it again.',409);
    unset($source['bytes']);
    return $source;
}

function vp3_profile_owner_image_job_source_v1(PDO $pdo,array $user,array $row,array $config,bool $lock=false,bool $pixels=false): ?string
{
    if(($config['mode']??null)!=='profile_owner_reference' ||
       !vp3_image_workspace_row_v1($row) ||
       !is_array($config['profile_reference']??null) ||
       !is_array($config['reference']??null))
        throw new RuntimeException('image_reference_changed');
    $saved=$config['reference'];
    try{
        $user=vp3_image_authority_v1($pdo,$user,$row);
        $fresh=vp3_profile_owner_image_frozen_source_v1($pdo,$user,$config['profile_reference'],$lock);
        if(($saved['asset_id']??null)!==($fresh['asset_id']??null) ||
           !hash_equals((string)($saved['sha256']??''),(string)$fresh['sha256']) ||
           !hash_equals((string)($saved['revision']??''),(string)$fresh['revision']) ||
           ($saved['generated_output']??null)!==($fresh['generated_output']??null))
            throw new RuntimeException('image_reference_changed');
        if(!$pixels)return null;
        $source=vp3_image_reference_source_v1($pdo,$user,(int)$fresh['asset_id'],$lock);
        if(!hash_equals($fresh['sha256'],$source['sha256']) ||
           !hash_equals($fresh['revision'],$source['revision']))
            throw new RuntimeException('image_reference_changed');
        return 'data:'.$source['mime_type'].';base64,'.base64_encode($source['bytes']);
    }catch(Throwable $e){
        if($e instanceof PDOException)throw $e;
        throw new RuntimeException('image_reference_changed',0,$e);
    }
}
