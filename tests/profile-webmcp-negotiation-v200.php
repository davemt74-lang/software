<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_MANIFEST_V100='vp3.profile.webmcp.v1';
const VP3_PROFILE_WEBMCP_RELEASE_V196='profile-webmcp-release-v196-20260929';
require dirname(__DIR__).'/includes/profile-webmcp-negotiation-v200.php';

function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}

$legacy=vp3_profile_webmcp_negotiate_v200('native_profile',[]);
t(!empty($legacy['compatible']),'legacy v1 compatible');
t(($legacy['mode']??'')==='legacy_v1','legacy mode');
t(empty($legacy['downgrade_applied']),'legacy cannot downgrade protection');

$exact=vp3_profile_webmcp_negotiate_v200('native_profile',[
    'manifest_versions'=>['vp3.profile.webmcp.v1'],
    'release_versions'=>['profile-webmcp-release-v196-20260929'],
    'runtime_build'=>'profile-webmcp-runtime-v100-20260928',
    'negotiation_contract'=>'vp3.profile.webmcp.negotiation.v1',
]);
t(!empty($exact['compatible']),'native exact compatible');
t(($exact['mode']??'')==='negotiated','negotiated mode');

$external=vp3_profile_webmcp_negotiate_v200('external_site',[
    'manifest_versions'=>'vp3.profile.webmcp.v1',
    'release_versions'=>'profile-webmcp-release-v196-20260929',
    'runtime_build'=>'profile-webmcp-external-v120-20260928',
]);
t(!empty($external['compatible']),'external exact compatible');

$bad=vp3_profile_webmcp_negotiate_v200('native_profile',['manifest_versions'=>['vp3.profile.webmcp.v99']]);
t(empty($bad['compatible']),'unsupported manifest rejected');
t(($bad['reason']??'')==='manifest_version_unsupported','manifest reason');
t(($bad['consequential_protection']??'')==='explicit_confirmation_and_idempotency_required','protection remains required');

$badRuntime=vp3_profile_webmcp_negotiate_v200('external_site',['runtime_build'=>'old-build']);
t(empty($badRuntime['compatible']),'unsupported runtime rejected');

echo "PROFILE_WEBMCP_NEGOTIATION_V200_PHP=PASS\n";
