<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
function profile_public_url(string $username): string { return '/'.rawurlencode($username); }
require dirname(__DIR__) . '/includes/profile-webmcp-v100.php';

function t(bool $value,string $message): void { if(!$value){fwrite(STDERR,"FAIL: {$message}\n");exit(1);} }

$catalog=vp3_profile_webmcp_tool_catalog_v100();
t(count($catalog)===3,'foundation catalog must expose exactly three tools');
foreach($catalog as $name=>$tool){
    t(($tool['annotations']['readOnlyHint']??false)===true,$name.' must be read-only in Section 1');
    t(($tool['annotations']['consequentialHint']??true)===false,$name.' must not be consequential');
}

$profile=[
  'username'=>'demo','display_name'=>'Demo User','bio'=>'Public bio','tagline'=>'Hello','role'=>'user',
  'email'=>'private@example.com','contact_email'=>'private-contact@example.com','profile_agent_instructions'=>'secret',
  'website_url'=>'https://example.com','instagram_url'=>'javascript:alert(1)'
];
$public=vp3_profile_webmcp_public_profile_v100($profile);
t(($public['username']??'')==='demo','public username');
t(!array_key_exists('email',$public),'private email must not project');
t(!array_key_exists('contact_email',$public),'private contact email must not project');
t(!array_key_exists('profile_agent_instructions',$public),'agent instructions must not project');
t(isset($public['links']['website']),'https website link must project');
t(!isset($public['links']['instagram']),'unsafe URL must fail closed');

$manifest=[
 'capabilities'=>['profile'=>true,'profile_agent'=>true,'booking'=>true,'commerce'=>false,'campaigns'=>true,'rewards'=>false,'social'=>false,'messaging'=>false],
 'allowed_tools'=>array_keys($catalog)
];
$booking=vp3_profile_webmcp_resolve_intent_v100('Book a consultation next Tuesday',$manifest);
t(in_array('booking',$booking['recommended_capabilities'],true),'booking intent must resolve');
t(($booking['execution_performed']??true)===false,'resolver must never execute');
$commerce=vp3_profile_webmcp_resolve_intent_v100('I want to buy this product',$manifest);
t(!in_array('commerce',$commerce['recommended_capabilities'],true),'disabled capability must not resolve');
$unknown=vp3_profile_webmcp_resolve_intent_v100('Tell me something',$manifest);
t(in_array('profile_agent',$unknown['recommended_capabilities'],true),'default intent should prefer available Profile Agent');

t(vp3_profile_webmcp_native_origin_allowed_v100('', 'example.com')===true,'missing Origin is allowed for native same-origin navigation');
t(vp3_profile_webmcp_native_origin_allowed_v100('https://example.com','example.com:443')===true,'matching origin host');
t(vp3_profile_webmcp_native_origin_allowed_v100('https://evil.example','example.com')===false,'foreign origin denied');

$proof=vp3_profile_webmcp_session_proof_v100(123);
t(strlen($proof)===64,'session proof entropy/shape');
t(vp3_profile_webmcp_session_proof_valid_v100(123,$proof),'session proof validation');
t(!vp3_profile_webmcp_session_proof_valid_v100(124,$proof),'session proof is owner-bound');

echo "PROFILE_WEBMCP_V100_PHP=PASS\n";
