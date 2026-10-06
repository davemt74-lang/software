<?php
declare(strict_types=1);
$settings=[];
function site_config(string $key,$fallback=null){global $settings;return $settings[$key]??$fallback;}
require __DIR__.'/../includes/extension-device-token-v2100.php';
require __DIR__.'/../includes/extension-device-auth-v2001.php';
function check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
$manifest=json_decode((string)file_get_contents(__DIR__.'/../browser-companion/manifest.json'),true);
$expected=strtr(substr(hash('sha256',base64_decode($manifest['key'],true)),0,32),'0123456789abcdef','abcdefghijklmnop');
$id=vp3_extension_bundled_chrome_id_v2100();
check($id===$expected && strlen($id)===32,'Stable ID must derive from the bundled public key');
$callback='https://'.$id.'.chromiumapp.org/vp3-connect';
check(vp3_extension_redirect_uri_valid_v2100($callback),'Bundled callback works without site configuration');
check(vp3_extension_origin_allowed_v2001('chrome-extension://'.$id),'CORS admits the same bundled origin');
foreach([
    'http://'.$id.'.chromiumapp.org/vp3-connect',
    'https://'.$id.'.chromiumapp.org.evil.test/vp3-connect',
    'https://'.$id.'.chromiumapp.org/other',
    $callback.'?code=bad',$callback.'#fragment',
    'https://user@'.$id.'.chromiumapp.org/vp3-connect',
    'https://'.$id.'.chromiumapp.org:443/vp3-connect',
    'https://'.str_repeat('a',32).'.chromiumapp.org/vp3-connect',
] as $url)check(!vp3_extension_redirect_uri_valid_v2100($url),'Reject callback '.$url);
foreach(['chrome-extension://'.str_repeat('a',32),'https://evil.test','null','chrome-extension://'.$id.'.evil.test'] as $origin){
    check(!vp3_extension_origin_allowed_v2001($origin),'Unlisted/malformed origins stay blocked');
}
$legacy=str_repeat('b',32);$settings=['extension_allowed_origins'=>'chrome-extension://'.$legacy];
check(vp3_extension_redirect_uri_valid_v2100('https://'.$legacy.'.chromiumapp.org/vp3-connect'),'Configured legacy ID remains valid');
check(vp3_extension_origin_allowed_v2001('chrome-extension://'.$legacy),'Legacy CORS remains valid');
$settings=['extension_allow_unlisted_chrome_origins'=>true];
check(vp3_extension_redirect_uri_valid_v2100('https://'.str_repeat('a',32).'.chromiumapp.org/vp3-connect'),'Explicit development override retained');
check(!vp3_extension_redirect_uri_valid_v2100('https://evil.test/vp3-connect'),'Override never allows arbitrary redirect hosts');
echo "PASS: stable bundled callback/CORS; malicious redirects and unlisted IDs blocked; legacy configuration retained.\n";
