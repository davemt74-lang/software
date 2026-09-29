<?php
declare(strict_types=1);

class VP3WebMCPExternalTestPDO extends PDO { public function __construct(){} }
const VP3_PROFILE_WEBMCP_MANIFEST_V100='vp3.profile.webmcp.v1';
$GLOBALS['vp3_ext_profile']=[
    'user_id'=>123,'username'=>'demo','display_name'=>'Demo','bio'=>'Public','is_active'=>1,'is_public'=>1,
    'profile_agent_greeting'=>'Hello','email'=>'private@example.com'
];
$GLOBALS['vp3_ext_agent_enabled']=true;

function vp3_radar_external_origin_allowed(string $registered,string $host): bool {
    $registered=strtolower(trim($registered));$host=strtolower(trim($host));
    return $host===$registered||str_ends_with($host,'.'.$registered);
}
function profile_for_user(PDO $pdo,int $owner,bool $create=false): ?array { return $GLOBALS['vp3_ext_profile']; }
function profile_user_row(PDO $pdo,int $owner): ?array { return ['id'=>$owner,'display_name'=>'Owner']; }
function personal_capability_has_v242(string $cap,array $user): bool { return $GLOBALS['vp3_ext_agent_enabled']; }
function profile_active_agent(PDO $pdo,array $profile): ?array { return $GLOBALS['vp3_ext_agent_enabled']?['id'=>77,'display_name'=>'Profile Agent','instructions'=>'private']:null; }
function system_agent_name(): string { return 'Stonefellow'; }
function vp3_profile_webmcp_capabilities_v100(PDO $pdo,array $profile,?array $viewer): array {
    return ['profile'=>true,'profile_agent'=>$GLOBALS['vp3_ext_agent_enabled'],'booking'=>true,'commerce'=>true,'campaigns'=>true,'rewards'=>false,'social'=>false,'messaging'=>false];
}
function vp3_profile_webmcp_tool_catalog_v100(): array {
    return [
      'vp3.profile.capabilities.get'=>['capability'=>'profile'],
      'vp3.profile.get'=>['capability'=>'profile'],
      'vp3.intent.resolve'=>['capability'=>'profile'],
      'vp3.agent.get'=>['capability'=>'profile_agent'],
      'vp3.agent.chat.start'=>['capability'=>'profile_agent'],
      'vp3.agent.conversation.get'=>['capability'=>'profile_agent'],
      'vp3.agent.message.send'=>['capability'=>'profile_agent'],
      'vp3.agent.owner_handoff.request'=>['capability'=>'profile_agent'],
      'vp3.booking.options.list'=>['capability'=>'booking'],
    ];
}
function url(string $path): string { return 'https://vp3.example'.$path; }

require dirname(__DIR__).'/includes/profile-webmcp-external-v120.php';

function t(bool $value,string $message): void { if(!$value){fwrite(STDERR,"FAIL: {$message}\n");exit(1);} }
$pdo=new VP3WebMCPExternalTestPDO();
$property=['id'=>44,'owner_user_id'=>123,'domain'=>'example.com','public_key'=>str_repeat('a',40),'is_active'=>1];

t(vp3_profile_webmcp_external_origin_v120($property,'https://example.com')==='https://example.com','exact origin');
t(vp3_profile_webmcp_external_origin_v120($property,'https://shop.example.com:8443')==='https://shop.example.com:8443','subdomain origin');
foreach(['https://evil-example.com','javascript://example.com','https://user:pass@example.com'] as $bad){
    try{vp3_profile_webmcp_external_origin_v120($property,$bad);t(false,'bad origin must fail '.$bad);}
    catch(RuntimeException $e){t(true,'bad origin denied');}
}

$profile=vp3_profile_webmcp_external_profile_v120($pdo,$property);
t($profile['username']==='demo','profile lookup');
$agent=vp3_profile_webmcp_external_agent_v120($pdo,$profile);
t(($agent['id']??0)===77,'agent projection');
t(!array_key_exists('instructions',$agent),'agent instructions must not leak');

$manifest=vp3_profile_webmcp_external_manifest_v120($pdo,$property,$profile);
t($manifest['surface']==='external_site','external surface');
t($manifest['property_id']===44,'property binding');
t($manifest['external']['read_only']===true,'read-only surface');
t($manifest['external']['stateful_profile_agent']===false,'stateful agent disabled');
t($manifest['session']['authenticated']===false,'external runtime is anonymous');
t($manifest['allowed_tools']===['vp3.agent.get','vp3.intent.resolve','vp3.profile.capabilities.get','vp3.profile.get'],'external tool allowlist');
t(!in_array('vp3.agent.message.send',$manifest['allowed_tools'],true),'stateful agent tool omitted');
t(!in_array('vp3.booking.options.list',$manifest['allowed_tools'],true),'future booking tool omitted');

$GLOBALS['vp3_ext_agent_enabled']=false;
$manifestNoAgent=vp3_profile_webmcp_external_manifest_v120($pdo,$property,$profile);
t(!in_array('vp3.agent.get',$manifestNoAgent['allowed_tools'],true),'agent tool follows entitlement/state');
$GLOBALS['vp3_ext_agent_enabled']=true;

$state=['sites'=>[
    ['id'=>44,'owner_user_id'=>123,'domain'=>'example.com','public_key'=>str_repeat('a',40),'is_active'=>1],
    ['id'=>45,'owner_user_id'=>123,'domain'=>'paused.example.com','public_key'=>str_repeat('b',40),'is_active'=>0],
]];
$enriched=vp3_profile_webmcp_external_enrich_site_state_v120($pdo,['id'=>123],$state);
t($enriched['sites'][0]['webmcp_enabled']===true,'active site WebMCP enabled');
t($enriched['sites'][0]['webmcp_tool_count']===8,'active site tool count including public Agent chat');
t($enriched['sites'][0]['webmcp_chat_enabled']===true,'active site reports Agent chat enabled');
t($enriched['sites'][1]['webmcp_enabled']===false,'paused site WebMCP disabled');
t($enriched['sites'][1]['webmcp_tool_count']===0,'paused site has no tools');

$GLOBALS['vp3_ext_profile']['is_public']=0;
try{vp3_profile_webmcp_external_profile_v120($pdo,$property);t(false,'private profile must fail');}
catch(RuntimeException $e){t(true,'private profile denied');}

echo "PROFILE_WEBMCP_EXTERNAL_V120_PHP=PASS\n";
