<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_MANIFEST_V100='vp3.profile.webmcp.v1';
const VP3_PROFILE_WEBMCP_RELEASE_V196='profile-webmcp-release-v196-20260929';
const VP3_PROFILE_WEBMCP_NATIVE_RUNTIME_V200='profile-webmcp-runtime-v100-20260928';
const VP3_PROFILE_WEBMCP_EXTERNAL_RUNTIME_V200='profile-webmcp-external-v120-20260928';
const VP3_PROFILE_WEBMCP_NEGOTIATION_CONTRACT_V200='vp3.profile.webmcp.negotiation.v1';

function vp3_profile_webmcp_release_audit_v196(): array{return ['ok'=>true,'errors'=>[]];}
function vp3_profile_webmcp_release_descriptor_v196(): array{return [
 'surfaces'=>[
  'native_profile'=>['execution_allowed'=>true,'transaction_authority'=>'profile_webmcp_router'],
  'external_site'=>['execution_allowed'=>true,'transaction_authority'=>'profile_webmcp_router'],
  'agent_brain'=>['execution_allowed'=>false,'transaction_authority'=>'none'],
 ],
 'continuity'=>['return_single_use'=>true,'sensitive_payload_return'=>false],
 'connected_sites'=>['origin_mismatch_payload_retained'=>false],
 'observability'=>['cross_surface_correlation'=>true,'sensitive_input_logging'=>false],
];}
function vp3_profile_webmcp_negotiate_v200(string $surface,array $client=[]): array{
 return ['compatible'=>!in_array('vp3.profile.webmcp.v999',(array)($client['manifest_versions']??[]),true),'downgrade_applied'=>false];
}
function vp3_profile_webmcp_tool_catalog_v100(): array{return ['vp3.profile.get'=>[],'vp3.old'=>[]];}
function vp3_profile_webmcp_compatibility_registry_v203(array $catalog=[]): array{return [
 'vp3.profile.get'=>['status'=>'active'],'vp3.old'=>['status'=>'disabled']
];}
function vp3_profile_webmcp_tool_available_v203(string $tool,string $runtime=''): bool{return $tool!=='vp3.old';}
function vp3_profile_webmcp_resume_issue_v194(){}
function vp3_profile_webmcp_resume_consume_v194(){}
function vp3_profile_webmcp_return_consume_v195(){}
function vp3_profile_webmcp_sites_admin_v204(){}
function vp3_profile_webmcp_site_status_v204(){}
function vp3_profile_webmcp_observability_rows_v205(){}
function vp3_profile_webmcp_latency_summary_v205(){}
function vp3_profile_webmcp_health_v201(){}
function vp3_profile_webmcp_admin_overview_v202(){}

require dirname(__DIR__).'/includes/profile-webmcp-acceptance-v206.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}

$r=vp3_profile_webmcp_acceptance_v206();
t(!empty($r['ready']),'acceptance ready');
t(($r['status']??'')==='ready','ready status');
t(($r['score_total']??0)===8,'eight acceptance gates');
t(($r['score_passed']??0)===8,'all acceptance gates pass');
t(($r['failed_checks']??[])===[],'no failed gates');
t(empty($r['contains_sensitive_payload']),'safe acceptance projection');
echo "PROFILE_WEBMCP_ACCEPTANCE_V206_PHP=PASS\n";
