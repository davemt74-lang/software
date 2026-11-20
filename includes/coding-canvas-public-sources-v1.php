<?php
declare(strict_types=1);

/**
 * Resolve the owner from the verified Canvas publication or private Canvas row.
 * The adapter accepts no visitor identity, filter, destination, or mutation.
 */
function vp3_coding_canvas_public_command_data_v1(PDO $pdo,int $ownerId,string $command): array
{
    $unavailable=['status'=>'unavailable','rows'=>[]];
    $read=match($command){
        'public-products'=>'vp3_coding_canvas_public_source_products_v1',
        'public-events'=>'vp3_coding_canvas_public_source_events_v1',
        'public-booking'=>'vp3_coding_canvas_public_source_booking_v1',
        'public-campaigns'=>'vp3_coding_canvas_public_source_campaigns_v1',
        'public-rewards'=>'vp3_coding_canvas_public_source_rewards_v1',
        'public-media'=>'vp3_coding_canvas_public_source_media_v1',
        default=>null,
    };
    if($ownerId<1||$read===null)return $unavailable;
    if(!function_exists('profile_agent_schema_ready'))require_once __DIR__.'/profile-agent.php';
    require_once __DIR__.'/coding-canvas-public-marketing-v1.php';
    try{
        if(!profile_agent_schema_ready($pdo))return $unavailable;
        $profile=vp3_coding_canvas_public_source_profile_v1($pdo,$ownerId);
        if(!$profile)return $unavailable;
        $rows=$read($pdo,$profile);
        if(vp3_coding_canvas_public_source_profile_v1($pdo,$ownerId)!==$profile)return $unavailable;
        return ['status'=>'ready','rows'=>$rows];
    }catch(PDOException $error){
        return $unavailable;
    }
}

function vp3_coding_canvas_public_source_profile_v1(PDO $pdo,int $ownerId): ?array
{
    $profile=profile_for_user($pdo,$ownerId);
    $username=profile_username_normalize((string)($profile['username']??''));
    if(empty($profile['is_public'])||empty($profile['is_active'])||strlen($username)<3||!profile_username_valid($username)){
        return null;
    }
    return ['user_id'=>$ownerId,'username'=>$username];
}

function vp3_coding_canvas_public_source_products_v1(PDO $pdo,array $profile): array
{
    require_once __DIR__.'/agent-commerce-v800.php';
    require_once __DIR__.'/profile-commerce-v900.php';
    $rows=[];
    foreach(profile_commerce_products_for_profile_v900($pdo,$profile,true,40) as $product){
        $id=$product['slug'];
        if(isset($rows[$id]))continue;
        $currency=strtolower($product['currency']);
        $rows[$id]=[
            'id'=>$id,
            'title'=>vp3_coding_canvas_public_source_text_v1($product['title'],140),
            'description'=>vp3_coding_canvas_public_source_text_v1($product['description'],400),
            'price_label'=>vp3_coding_canvas_public_source_text_v1($product['price_label'],100),
            'price_cents'=>$product['price_cents'],
            'currency'=>preg_match('/\A[a-z]{3}\z/D',$currency)?$currency:'',
            'url'=>$product['product_url'],
        ];
        if(count($rows)>=24)break;
    }
    return array_values($rows);
}

function vp3_coding_canvas_public_source_events_v1(PDO $pdo,array $profile): array
{
    require_once __DIR__.'/artist-workspace-v181.php';
    require_once __DIR__.'/artist-shows-v184.php';
    $workspace=profile_public_artist_workspace($pdo,$profile['user_id']);
    $workspaceId=(int)($workspace['id']??0);
    if($workspaceId<1)return [];
    $now=time();
    $rows=[];
    foreach(artist_workspace_v181_public_records('shows',null,80,$workspaceId) as $show){
        $startsAt=strtotime((string)($show['show_date']??''));
        if($startsAt===false||$startsAt<$now)continue;
        $name=trim((string)($show['event_name']??''));
        $venue=trim((string)($show['venue']??''));
        $place=array_filter([$venue,trim((string)($show['city']??'')),trim((string)($show['region']??''))],static fn(string $part): bool=>$part!=='');
        $status=(string)($show['show_status']??'scheduled');
        $rows[]=[
            'id'=>'show-'.(int)$show['id'],
            'title'=>vp3_coding_canvas_public_source_text_v1($name!==''?$name:$venue,140),
            'description'=>vp3_coding_canvas_public_source_text_v1($show['notes']??'',400),
            'starts_at'=>date(DATE_ATOM,$startsAt),
            'location'=>vp3_coding_canvas_public_source_text_v1(implode(', ',$place),160),
            'status'=>isset(artist_shows_v184_statuses()[$status])?$status:'scheduled',
            'url'=>profile_public_url($profile['username']),
        ];
        if(count($rows)>=24)break;
    }
    return $rows;
}

function vp3_coding_canvas_public_source_booking_v1(PDO $pdo,array $profile): array
{
    require_once __DIR__.'/agent-scheduling-v430.php';
    require_once __DIR__.'/agent-scheduling-public-v450.php';
    if(!agent_scheduling_schema_ready_v430($pdo))return [];
    $schedule=agent_scheduling_public_schedule_v450($pdo,$profile['user_id']);
    if(!$schedule)return [];
    $rows=[];
    foreach(agent_scheduling_public_events_v450($pdo,(int)$schedule['id']) as $event){
        $slug=agent_scheduling_slug_v430((string)($event['slug']??''));
        if($slug===''||isset($rows[$slug]))continue;
        $rows[$slug]=[
            'id'=>$slug,
            'title'=>vp3_coding_canvas_public_source_text_v1($event['title']??'',140),
            'description'=>vp3_coding_canvas_public_source_text_v1($event['description']??'',400),
            'duration_minutes'=>(int)($event['duration_minutes']??0),
            'timezone'=>vp3_coding_canvas_public_source_text_v1($schedule['timezone']??'',64),
            'url'=>agent_scheduling_public_booking_url_v450($profile['username'],$slug),
        ];
        if(count($rows)>=24)break;
    }
    return array_values($rows);
}

function vp3_coding_canvas_public_source_text_v1(mixed $value,int $limit): string
{
    if(!is_string($value))return '';
    $value=preg_replace('/[\p{Cc}\p{Cf}\p{Z}\s]+/u',' ',mb_scrub($value,'UTF-8'))??'';
    return mb_substr(trim($value),0,$limit,'UTF-8');
}
