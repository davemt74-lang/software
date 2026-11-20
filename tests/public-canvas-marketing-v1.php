<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/coding-canvas-commands-v1.php';
require_once __DIR__.'/../includes/coding-canvas-public-marketing-v1.php';
require_once __DIR__.'/../includes/coding-canvas-public-view-v1.php';

function url(string $path): string { return '/vp3'.$path; }
function profile_public_url(string $username): string { return '/vp3/'.$username; }
function vp3_coding_canvas_public_source_text_v1(mixed $value,int $limit): string {
    return is_string($value)?substr(trim($value),0,$limit):'';
}
function campaigns_rewards_platform_schema_ready_v100(PDO $pdo): bool { return $GLOBALS['schemaReady']; }
function campaigns_rewards_profile_campaigns_v100(PDO $pdo,int $owner,int $limit): array {
    return $GLOBALS['listed'][$owner]??[];
}
function campaigns_rewards_profile_campaigns_platform_v100(PDO $pdo,int $owner): array { return []; }
function campaigns_rewards_campaign_by_slug_v100(PDO $pdo,string $slug,bool $publicOnly=true): ?array {
    return $GLOBALS['live'][$slug]??null;
}
function campaigns_rewards_campaign_url_v100(string $slug): string { return url('/campaign/'.$slug); }
function campaigns_rewards_rewards_v100(PDO $pdo,int $campaignId,bool $publicOnly=false): array {
    return $GLOBALS['rewards'][$campaignId]??[];
}
function vp3_profile_image_catalog_public_v1(PDO $pdo,int $owner): array { return $GLOBALS['media'][$owner]??[]; }
function vp3_profile_image_catalog_public_profile_v1(PDO $pdo,array $profile): array {
    if(!$GLOBALS['mediaAllowed'])throw new DomainException('revoked',404);
    return ['profile'=>$profile];
}
function check_public(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException($label);
    $GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;
}

$checks=0;$schemaReady=true;$mediaAllowed=true;
$pdo=new PDO('sqlite::memory:');
$owner=['user_id'=>11,'username'=>'owner-public'];
$campaign=['id'=>8,'owner_user_id'=>11,'slug'=>'autumn-sale','title'=>'Autumn Sale',
    'description'=>'Save at launch','merchant_name'=>'Store','cta_label'=>'Visit','visibility'=>'profile_public'];
$listed=[11=>[['id'=>8,'slug'=>'autumn-sale'],['id'=>9,'slug'=>'hidden'],['id'=>10,'slug'=>'foreign'],['id'=>12,'slug'=>'not-public']]];
$live=['autumn-sale'=>$campaign,
    'hidden'=>$campaign+['id'=>9],
    'foreign'=>array_replace($campaign,['id'=>10,'owner_user_id'=>12,'slug'=>'foreign']),
    'not-public'=>array_replace($campaign,['id'=>12,'slug'=>'not-public','visibility'=>'unlisted'])];
$live['hidden']=array_replace($campaign,['id'=>9,'slug'=>'hidden','visibility'=>'private']);
$rewards=[8=>[['public_id'=>'123e4567-e89b-12d3-a456-426614174000','status'=>'active','title'=>'Free sample',
    'description'=>'Public offer','value_label'=>'Free'],
    ['public_id'=>'private','status'=>'inactive','title'=>'Draft reward']]];
$media=[11=>[['collection_id'=>3,'name'=>'Public styles','catalog_revision'=>2,
    'references'=>[['asset_id'=>19,'price_cents'=>0,'title'=>'Sunset'],['asset_id'=>20,'price_cents'=>99,'title'=>'Paid']]]]];
$catalog=vp3_coding_canvas_command_library_v1();
foreach(['public-campaigns','public-rewards','public-media'] as $name)check_public(($catalog[$name]['visibility']??null)==='public','public command '.$name);
check_public(vp3_coding_canvas_command_list_v1(['public-rewards','public-campaigns'])===['public-campaigns','public-rewards'],'canonical command selection');
$campaigns=vp3_coding_canvas_public_source_campaigns_v1($pdo,$owner);
check_public(count($campaigns)===1 && $campaigns[0]['id']==='campaign-autumn-sale','only live Profile campaigns');
check_public(!isset($campaigns[0]['contact_id']) && !isset($campaigns[0]['settings_json']),'campaign private data excluded');
$offers=vp3_coding_canvas_public_source_rewards_v1($pdo,$owner);
check_public(count($offers)===1 && $offers[0]['title']==='Free sample','only public active reward offers');
check_public(!isset($offers[0]['code'])&&!isset($offers[0]['credential']),'no issued rewards or credentials');
$images=vp3_coding_canvas_public_source_media_v1($pdo,$owner);
check_public(count($images)===1 && $images[0]['title']==='Sunset','approved free media only');
check_public(!isset($images[0]['asset_id'])&&!isset($images[0]['source_sha256'])&&!isset($images[0]['preview_url']),'no private media IDs, URL, or bytes');
$mediaAllowed=false;
check_public(vp3_coding_canvas_public_source_media_v1($pdo,$owner)===[],'revoked Profile image authority');
$schemaReady=false;
check_public(vp3_coding_canvas_public_source_campaigns_v1($pdo,$owner)===[],'missing campaign schema fails closed');
$schemaReady=true;
$rows=vp3_public_canvas_source_v1('public-campaigns',['status'=>'ready','rows'=>$campaigns],'/vp3');
check_public($rows['rows'][0]['url']==='/vp3/campaign/autumn-sale','canonical live campaign URL retained');
$attack=$campaigns[0];$attack['url']='https://outside.example/steal';
$bad=vp3_public_canvas_source_v1('public-campaigns',['status'=>'ready','rows'=>[$attack]],'/vp3');
check_public(!isset($bad['rows'][0]['url']),'external campaign URL removed');
$attack['url']='/vp3/campaign/autumn-sale?token=secret';
$bad=vp3_public_canvas_source_v1('public-campaigns',['status'=>'ready','rows'=>[$attack]],'/vp3');
check_public(!isset($bad['rows'][0]['url']),'query-string URL removed');
$attack=$images[0];$attack['url']='/vp3/private/library';
$bad=vp3_public_canvas_source_v1('public-media',['status'=>'ready','rows'=>[$attack]],'/vp3');
check_public(!isset($bad['rows'][0]['url']),'private media route rejected');
$attack=$images[0];$attack['url']='/vp3/owner-public';
$good=vp3_public_canvas_source_v1('public-media',['status'=>'ready','rows'=>[$attack]],'/vp3');
check_public($good['rows'][0]['url']==='/vp3/owner-public','public profile route accepted');
echo "Public Canvas marketing gate: $checks checks passed".PHP_EOL;
