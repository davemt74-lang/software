<?php
declare(strict_types=1);
// Only public Profile discovery/session infrastructure is substituted in this gateway-focused test.
const VP3_PROFILE_WEBMCP_MAX_BODY_V100=16384;
const VP3_PROFILE_WEBMCP_MANIFEST_V100='vp3.profile.webmcp.v1';
function vp3_profile_webmcp_session_proof_valid_v100(int $owner,string $proof): bool{return $owner===1&&hash_equals(str_repeat('a',64),$proof);}
function vp3_profile_webmcp_native_origin_allowed_v100(string $origin,string $host): bool{return $origin==='http://'.$host;}
function vp3_profile_webmcp_rate_limit_v100(int $owner): bool{return true;}
function vp3_profile_webmcp_client_versions_v200(array $input): array{return [];}
function vp3_profile_webmcp_negotiate_v200(string $surface,array $client): array{return ['compatible'=>true,'mode'=>'fixture'];}
function vp3_profile_webmcp_tool_catalog_v100(): array{return ['vp3.profile.get'=>[],'vp3.booking.prepare'=>[],'vp3.booking.confirm'=>[]];}
function vp3_profile_webmcp_manifest_v100(PDO $pdo,array $profile,?array $viewer,array $options=[]): array{return ['allowed_tools'=>array_keys(vp3_profile_webmcp_tool_catalog_v100())];}
