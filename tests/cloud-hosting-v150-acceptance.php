<?php
declare(strict_types=1);

function homeserver_vp3_connection(int $userId): ?array
{
    return ['user_id'=>$userId,'status'=>'connected'];
}

require __DIR__.'/cloud-hosting-v140-mysql.php';
require dirname(__DIR__).'/includes/cloud-hosting-agent-v130.php';
require dirname(__DIR__).'/includes/cloud-hosting-release-v150.php';

vp3_cloud_hosting_agent_v130_ensure_schema($testPdo);

$readiness=vp3_cloud_hosting_v150_readiness($owner,$testPdo);
if(empty($readiness['platform']['release_ready']))throw new RuntimeException('Cloud Hosting V1 platform is not release-ready.');
if(empty($readiness['account']['ready']))throw new RuntimeException('Cloud Hosting V1 account readiness failed.');
if((int)$readiness['account']['site_count']!==1)throw new RuntimeException('Release readiness site count mismatch.');
if(($readiness['versions']['member_ui']??'')!==VP3_CLOUD_HOSTING_UI_V140)throw new RuntimeException('Release version map is incomplete.');

$agentList=vp3_cloud_hosting_agent_v130_query('show my hosted sites',$owner,501,$remote,null,$testPdo);
if(empty($agentList['handled'])||!str_contains((string)$agentList['answer'],'UI Site'))throw new RuntimeException('End-to-end Agent Hosting read failed.');

$suspend=vp3_cloud_hosting_agent_v130_query('suspend hosted site "UI Site"',$owner,501,$remote,null,$testPdo);
$code=(string)($suspend['hosting_plan']['confirmation_code']??'');
if(!preg_match('/^[A-Z2-9]{8}$/',$code))throw new RuntimeException('End-to-end Agent Hosting confirmation was not prepared.');
$suspendDone=vp3_cloud_hosting_agent_v130_query('confirm hosting '.$code,$owner,501,$remote,null,$testPdo);
if(empty($suspendDone['hosting_plan']['completed']))throw new RuntimeException('End-to-end Agent Hosting suspend did not complete.');
$suspended=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
if(($suspended['desired_state']??'')!=='suspended')throw new RuntimeException('Agent Hosting suspend did not reach canonical desired state.');

$reactivated=vp3_cloud_hosting_ui_v140_execute($owner,'site.activate',[
    'site_id'=>(int)$site['id'],'confirmed'=>'1'
],null,$remote,null,null,$testPdo);
if(($reactivated['site']['desired_state']??'')!=='active')throw new RuntimeException('Member Hosting UI could not reactivate after Agent control.');

$beforeDowngrade=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
$releaseBefore=(string)($beforeDowngrade['active_release_id']??'');
$countBefore=vp3_cloud_hosting_count_sites_v100(1,$testPdo);
$entitlements['hosting.access']['enabled']=false;

$downgraded=vp3_cloud_hosting_v150_readiness($owner,$testPdo);
if(!empty($downgraded['account']['hosting_entitled']))throw new RuntimeException('Package downgrade was not reflected in release readiness.');
if(!empty($downgraded['account']['ready']))throw new RuntimeException('Downgraded account incorrectly remained Hosting-ready.');
if(empty($downgraded['platform']['release_ready']))throw new RuntimeException('Account downgrade incorrectly changed platform readiness.');

try{
    vp3_cloud_hosting_ui_v140_execute($owner,'site.create',[
        'display_name'=>'Blocked After Downgrade','runtime_kind'=>'static','request_key'=>'downgrade-create'
    ],null,null,null,null,$testPdo);
    throw new RuntimeException('Package downgrade did not block new hosted-site creation.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Package downgrade did not block new hosted-site creation.')throw $e;
    if(!str_contains($e->getMessage(),'does not include Cloud Hosting'))throw $e;
}

try{
    vp3_cloud_hosting_ui_v140_execute($owner,'deployment.deploy',[
        'site_id'=>(int)$site['id'],'request_key'=>'downgrade-deploy','confirmed'=>'1'
    ],$bytes,$remote,null,null,$testPdo);
    throw new RuntimeException('Package downgrade did not block new deployment.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Package downgrade did not block new deployment.')throw $e;
    if(!str_contains($e->getMessage(),'does not currently include Cloud Hosting'))throw $e;
}

$afterDowngrade=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
if(vp3_cloud_hosting_count_sites_v100(1,$testPdo)!==$countBefore)throw new RuntimeException('Package downgrade deleted hosted-site data.');
if((string)($afterDowngrade['active_release_id']??'')!==$releaseBefore)throw new RuntimeException('Package downgrade changed the active HomeServer release.');

$otherReadiness=vp3_cloud_hosting_v150_readiness($other,$testPdo);
if((int)$otherReadiness['account']['site_count']!==0)throw new RuntimeException('Release readiness leaked another user’s hosted sites.');

$entitlements['hosting.access']['enabled']=true;
$restored=vp3_cloud_hosting_v150_readiness($owner,$testPdo);
if(empty($restored['account']['ready']))throw new RuntimeException('Restored Hosting entitlement did not recover account readiness.');

$cap=vp3_cloud_hosting_v150_public_capability();
foreach([
    'control_plane','package_entitlements','cpanel_dns','cloud_edge_tls','homeserver_reconciliation',
    'chunked_deployment','deployment_resume','rollback','agent_chat_controls','member_hosting_ui',
    'server_confirmed_consequential_ui_actions','agent_confirmed_consequential_actions',
    'secret_redaction','non_destructive_package_downgrade','cloud_is_desired_state_authority',
    'homeserver_is_execution_authority'
] as $key){
    if(empty($cap[$key]))throw new RuntimeException('Release capability missing '.$key);
}
if(!empty($cap['parallel_hosting_engine']))throw new RuntimeException('Release contract advertises a parallel Hosting engine.');

$public=json_encode([$readiness,$downgraded,$restored,$agentList],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
foreach(['CPANEL_SECRET_TOKEN','SECRET_ROUTE_TOKEN'] as $secret){
    if(str_contains((string)$public,$secret))throw new RuntimeException('Cloud Hosting V1 release projection leaked a secret.');
}

echo "Cloud Hosting V1 Section 6 end-to-end acceptance: PASS\n";
