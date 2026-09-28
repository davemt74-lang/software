<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
function profile_public_url(string $username): string { return '/'.rawurlencode($username); }
function profile_public_media_url_v174(?string $storedPath,string $bucket): string {
    $path=trim((string)$storedPath);
    return $path===''?'':'/media/'.rawurlencode($bucket).'/'.rawurlencode(basename($path));
}
function profile_active_agent(PDO $pdo,array $profile): ?array {
    return ['id'=>77,'display_name'=>'Demo Agent','system_prompt'=>'PRIVATE'];
}
require dirname(__DIR__) . '/includes/profile-webmcp-v100.php';
require dirname(__DIR__) . '/includes/profile-webmcp-discovery-v110.php';

function t110(bool $value,string $message): void { if(!$value){fwrite(STDERR,"FAIL: {$message}\n");exit(1);} }

$catalog=vp3_profile_webmcp_tool_catalog_v100();
foreach(['vp3.profile.links.list','vp3.profile.media.get','vp3.agent.get','vp3.profile.public_state.get'] as $name){
    t110(isset($catalog[$name]),$name.' must be registered');
    t110(($catalog[$name]['annotations']['readOnlyHint']??false)===true,$name.' must remain read-only');
    t110(($catalog[$name]['annotations']['consequentialHint']??true)===false,$name.' must not be consequential');
}

$profile=[
 'username'=>'demo','website_url'=>'https://example.com','instagram_url'=>'javascript:alert(1)',
 'avatar_path'=>'private/avatars/demo.png','cover_path'=>'private/profile-covers/cover.jpg',
 'profile_agent_greeting'=>'Hello from Demo Agent','private_email'=>'private@example.com'
];
$links=vp3_profile_webmcp_links_v110($profile);
t110(count($links)===1,'only safe public link should project');
t110($links[0]['label']==='website','website label');
t110($links[0]['url']==='https://example.com','website URL');

$media=vp3_profile_webmcp_media_v110($profile);
t110(isset($media['avatar_url'])&&isset($media['cover_url']),'avatar and cover project');
t110(!str_contains(json_encode($media),'private/'),'stored private path must not project');

$pdo=new PDO('sqlite::memory:');
$agent=vp3_profile_webmcp_agent_v110($pdo,$profile);
t110(($agent['display_name']??'')==='Demo Agent','public agent identity');
t110(($agent['greeting']??'')==='Hello from Demo Agent','public greeting');
t110(!isset($agent['system_prompt']),'system prompt must not project');
t110(!isset($agent['id']),'internal agent id must not project');
t110(($agent['ai_representative']??false)===true,'AI disclosure');

$manifest=[
 'capabilities'=>[
  'profile'=>true,'profile_agent'=>true,'booking'=>true,'commerce'=>false,
  'campaigns'=>true,'rewards'=>false,'social'=>false,'messaging'=>false
 ],
 'session'=>['visitor_profile_known'=>false]
];
$state=vp3_profile_webmcp_public_state_v110($pdo,$profile,null,$manifest);
t110($state['profile_agent_available']===true,'agent availability');
t110($state['booking_available']===true,'booking availability');
t110($state['commerce_available']===false,'commerce unavailable');
t110($state['viewer_authenticated']===false,'anonymous viewer');
t110($state['viewer_identity_disclosed']===false,'identity privacy');
t110(!array_key_exists('booking_slots',$state),'no booking details in discovery state');
t110(!array_key_exists('products',$state),'no product data in discovery state');
t110(!array_key_exists('campaigns',$state),'no campaign decision data in discovery state');

echo "PROFILE_WEBMCP_V110_PHP=PASS\n";
