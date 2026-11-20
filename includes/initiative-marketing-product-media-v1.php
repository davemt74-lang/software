<?php
declare(strict_types=1);

/**
 * Owner-private product and photo selections for the existing Marketing Workbench.
 * This read exposes only allowlisted titles and IDs; no private files, tokens,
 * credentials or generated assets. All creation remains with the canonical tool.
 */
function vp3_marketing_product_media_choices_v1(PDO $pdo,int $owner,array $scope): array
{
    if($owner<1 || (int)($scope['user']['id']??0)!==$owner
        || !function_exists('has_permission') || !has_permission('account.access',$scope['user'])) {
        throw new DomainException('Marketing owner access is required.',403);
    }
    if(!function_exists('vp3_coordination_marketing_owner_v1')) {
        require_once __DIR__.'/agent-coordination-marketing-v1.php';
    }
    if(vp3_coordination_marketing_owner_v1($pdo,$scope)!==$owner) {
        throw new DomainException('Marketing access changed. Reopen Chat.',403);
    }
    if(!function_exists('initiative_work_marketing_products_v1')) {
        require_once __DIR__.'/initiative-work-marketing-v1.php';
    }
    // Each canonical source independently verifies the owner, Agent and saved Chat.
    $productRows=initiative_work_marketing_products_v1($pdo,$owner,$scope);
    $mediaRows=initiative_work_marketing_sources_v1($pdo,$owner,$scope);
    $products=[];$sources=[];$seenProducts=[];$seenSources=[];
    foreach($productRows as $row) {
        if(!is_array($row))continue;
        $id=(int)($row['id']??0);
        if($id<1||$id>9007199254740991||isset($seenProducts[$id]))continue;
        $name=trim((string)($row['title']??''));
        if($name==='')$name='Product #'.$id;
        $products[]=['id'=>$id,'title'=>mb_substr($name,0,150)];
        $seenProducts[$id]=true;
        if(count($products)>=100)break;
    }
    foreach($mediaRows as $row) {
        if(!is_array($row))continue;
        $id=$row['source_id']??null;
        if(!is_string($id)||!preg_match('/\A[1-9][0-9]{0,18}\z/D',$id)
            ||(string)(int)$id!==$id||isset($seenSources[$id]))continue;
        $title=trim((string)($row['source_title']??''));
        if($title==='')$title='Saved photo';
        $sources[]=['id'=>$id,'title'=>mb_substr($title,0,150)];
        $seenSources[$id]=true;
        if(count($sources)>=50)break;
    }
    // Final authority fence. No product/media details survive account, Agent or
    // Chat permission changes while the canonical readers were working.
    initiative_work_product_scope_v1($pdo,$owner,$scope);
    if(vp3_coordination_marketing_owner_v1($pdo,$scope)!==$owner) {
        throw new DomainException('Marketing access changed. Reopen Chat.',403);
    }
    return ['selection'=>[
        'products'=>$products,'media'=>$sources,
        'coverage'=>'ready',
        'action'=>'private_marketing_draft',
        'will_publish'=>false,
    ]];
}
