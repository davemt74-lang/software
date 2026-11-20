<?php
declare(strict_types=1);

/** Reuse canonical live public campaign and Profile publication authority. Never read CRM, decisions or issued rewards. */
function vp3_coding_canvas_public_source_live_campaigns_v1(PDO $pdo,array $profile): array
{
    if(!function_exists('campaigns_rewards_profile_campaigns_v100'))require_once __DIR__.'/campaigns-rewards-v100.php';
    if(!function_exists('campaigns_rewards_profile_campaigns_platform_v100'))require_once __DIR__.'/campaigns-rewards-domain-v100.php';
    if(!function_exists('campaigns_rewards_platform_schema_ready_v100')
        || !campaigns_rewards_platform_schema_ready_v100($pdo))return [];
    $rows=[];
    foreach(campaigns_rewards_profile_campaigns_v100($pdo,(int)$profile['user_id'],24) as $listed){
        $slug=(string)($listed['slug']??'');
        if(!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D',$slug)||strlen($slug)>80)continue;
        $live=campaigns_rewards_campaign_by_slug_v100($pdo,$slug,true);
        if(!$live||(int)($live['id']??0)!==(int)($listed['id']??0)
            ||(int)($live['owner_user_id']??0)!==(int)$profile['user_id']
            ||(string)($live['visibility']??'')!=='profile_public')continue;
        $rows[]=$live;
        if(count($rows)>=24)break;
    }
    return $rows;
}

function vp3_coding_canvas_public_source_campaigns_v1(PDO $pdo,array $profile): array
{
    $rows=[];
    foreach(vp3_coding_canvas_public_source_live_campaigns_v1($pdo,$profile) as $campaign){
        $slug=(string)$campaign['slug'];
        $rows[]=[
            'id'=>'campaign-'.$slug,
            'title'=>vp3_coding_canvas_public_source_text_v1($campaign['title']??$campaign['name']??'',140),
            'description'=>vp3_coding_canvas_public_source_text_v1($campaign['description']??'',400),
            'merchant_name'=>vp3_coding_canvas_public_source_text_v1($campaign['merchant_name']??'',140),
            'cta_label'=>vp3_coding_canvas_public_source_text_v1($campaign['cta_label']??'View campaign',90),
            'starts_at'=>vp3_coding_canvas_public_source_text_v1($campaign['starts_at']??'',40),
            'ends_at'=>vp3_coding_canvas_public_source_text_v1($campaign['ends_at']??'',40),
            'url'=>campaigns_rewards_campaign_url_v100($slug),
        ];
    }
    return $rows;
}

/** Only active public reward product snapshots attached to currently public Profile campaigns. */
function vp3_coding_canvas_public_source_rewards_v1(PDO $pdo,array $profile): array
{
    $rows=[];
    foreach(vp3_coding_canvas_public_source_live_campaigns_v1($pdo,$profile) as $campaign){
        $slug=(string)$campaign['slug'];
        foreach(campaigns_rewards_rewards_v100($pdo,(int)$campaign['id'],true) as $reward){
            if(($reward['status']??'')!=='active')continue;
            $publicId=(string)($reward['public_id']??'');
            if(!preg_match('/\A[a-zA-Z0-9-]{1,64}\z/D',$publicId))continue;
            $rows[]=[
                'id'=>'reward-'.substr(hash('sha256',$slug.':'.$publicId),0,32),
                'title'=>vp3_coding_canvas_public_source_text_v1($reward['title']??$reward['name']??'',140),
                'description'=>vp3_coding_canvas_public_source_text_v1($reward['description']??'',400),
                'value_label'=>vp3_coding_canvas_public_source_text_v1($reward['value_label']??'',90),
                'campaign_title'=>vp3_coding_canvas_public_source_text_v1($campaign['title']??$campaign['name']??'',140),
                'url'=>campaigns_rewards_campaign_url_v100($slug),
            ];
            if(count($rows)>=24)break 2;
        }
    }
    return $rows;
}

/** Only owner-approved zero-price Profile images. This read never returns a private file or image token. */
function vp3_coding_canvas_public_source_media_v1(PDO $pdo,array $profile): array
{
    if(!function_exists('vp3_profile_image_catalog_public_v1'))require_once __DIR__.'/profile-image-catalog-v1.php';
    try{
        vp3_profile_image_catalog_public_profile_v1($pdo,$profile);
        $catalogs=vp3_profile_image_catalog_public_v1($pdo,(int)$profile['user_id']);
    }catch(DomainException $error){
        return [];
    }
    $rows=[];
    foreach($catalogs as $catalog){
        foreach((array)($catalog['references']??[]) as $image){
            if(!is_array($image)||!is_int($image['asset_id']??null)
                ||(int)($image['price_cents']??-1)!==0)continue;
            $rows[]=[
                'id'=>'media-'.substr(hash('sha256',(int)$catalog['collection_id'].':'.$image['asset_id'].':'.(int)$catalog['catalog_revision']),0,32),
                'title'=>vp3_coding_canvas_public_source_text_v1($image['title']??'',140),
                'description'=>'Approved public Profile image',
                'collection'=>vp3_coding_canvas_public_source_text_v1($catalog['name']??'',140),
                'url'=>profile_public_url($profile['username']),
            ];
            if(count($rows)>=24)break 2;
        }
    }
    return $rows;
}
