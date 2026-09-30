<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v190.php';
require_once __DIR__.'/cloud-hosting-v120.php';

const VP3_SYSTEM_APPS_V200='system-app-release-lifecycle-v200-20260930';

function vp3_system_apps_release_metadata_v200(array $app): array
{
    $meta=vp3_system_apps_json_v100((string)($app['metadata_json']??''));
    return [
      'release_channel'=>(string)($meta['release_channel']??'stable'),
      'release_notes'=>is_array($meta['release_notes']??null)?array_values(array_map('strval',$meta['release_notes'])):[],
      'compatibility'=>[
        'min_homeserver_version'=>$meta['min_homeserver_version']??null,
        'max_homeserver_version'=>$meta['max_homeserver_version']??null,
      ],
    ];
}

function vp3_system_apps_release_status_v200(
    int $userId,string $appKey,?callable $remote=null,?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $app=vp3_system_apps_owned_row_v110($userId,$appKey,$pdo);
    $response=vp3_system_apps_remote_v110($userId,'apps.system.release.status',[
      'app_key'=>(string)$app['homeserver_catalog_key'],
    ],$remote);
    if((string)($response['app_key']??'')!==''&&(string)$response['app_key']!==(string)$app['homeserver_catalog_key']){
        throw new RuntimeException('HomeServer returned mismatched System App release identity.');
    }
    $cloud=vp3_system_apps_release_metadata_v200($app);
    $available=(string)($response['available_version']??'');
    $channel=(string)($response['release_channel']??'');
    $sha=strtolower(trim((string)($response['package_sha256']??'')));
    if($available===''||$available!==(string)$app['current_version']){
        throw new RuntimeException('HomeServer System App release does not match the Cloud catalog version.');
    }
    if($channel===''||$channel!==$cloud['release_channel']){
        throw new RuntimeException('HomeServer System App release channel does not match the Cloud catalog.');
    }
    if(!preg_match('/^[a-f0-9]{64}$/',$sha)){
        throw new RuntimeException('HomeServer System App release is missing a valid package hash.');
    }
    return [
      'contract'=>'vp3.system-app-release.v1',
      'app_key'=>(string)$app['app_key'],
      'cloud_version'=>(string)$app['current_version'],
      'release_channel'=>$cloud['release_channel'],
      'release_notes'=>$cloud['release_notes'],
      'compatibility'=>$response['compatibility']??$cloud['compatibility'],
      'package_sha256'=>$sha,
      'integrity'=>$response['integrity']??['algorithm'=>'sha256','package_sha256'=>$sha],
      'runtime'=>is_array($response['runtime']??null)?$response['runtime']:[],
      'rollback_available'=>!empty($response['rollback_available']),
      'homeserver'=>$response,
    ];
}

function vp3_system_apps_release_reconcile_hosting_v200(
    int $userId,array $app,?callable $remote=null,?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $binding=vp3_system_apps_hosting_projection_v120($userId,(int)$app['id'],$pdo);
    if(empty($binding['bound'])||(int)($binding['site_id']??0)<1){
        return ['bound'=>false,'reconcile'=>null,'pending'=>false];
    }
    $siteId=(int)$binding['site_id'];
    $pdo->prepare("UPDATE cloud_hosting_sites
      SET desired_revision=desired_revision+1,last_error_code='',last_error_message=''
      WHERE id=? AND user_id=?")->execute([$siteId,$userId]);
    $site=vp3_cloud_hosting_site_v100($siteId,$userId,$pdo);
    if(!$site)throw new RuntimeException('Bound Hosting site could not be loaded after System App release change.');
    try{
        $sync=vp3_cloud_hosting_v120_reconcile_site($site,$remote,$pdo);
        return [
          'bound'=>true,
          'site_id'=>$siteId,
          'hostname'=>$binding['hostname']??null,
          'public_url'=>$binding['public_url']??null,
          'reconcile'=>$sync,
          'pending'=>false,
        ];
    }catch(Throwable $e){
        return [
          'bound'=>true,
          'site_id'=>$siteId,
          'hostname'=>$binding['hostname']??null,
          'public_url'=>$binding['public_url']??null,
          'reconcile'=>null,
          'pending'=>true,
          'warning'=>mb_substr($e->getMessage(),0,500),
        ];
    }
}

function vp3_system_apps_release_update_v200(
    int $userId,string $appKey,?callable $remote=null,?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $app=vp3_system_apps_owned_row_v110($userId,$appKey,$pdo);
    $before=vp3_system_apps_install_projection_v110($userId,(int)$app['id'],$pdo);
    if(empty($before['installed']))throw new RuntimeException('Install this System App before updating it.');
    $release=vp3_system_apps_release_status_v200($userId,$appKey,$remote,$pdo);
    vp3_system_apps_event_v110($userId,(int)$app['id'],'app.release.update_requested',[
      'from_version'=>$before['installed_version']??null,
      'to_version'=>$release['cloud_version'],
      'release_channel'=>$release['release_channel'],
      'package_sha256'=>$release['package_sha256'],
    ],$pdo);
    try{
        $response=vp3_system_apps_remote_v110($userId,'apps.system.install',[
          'app_key'=>(string)$app['homeserver_catalog_key'],
          'expected_version'=>$release['cloud_version'],
          'expected_sha256'=>$release['package_sha256'],
          'release_channel'=>$release['release_channel'],
        ],$remote);
        $projection=vp3_system_apps_store_remote_v110($userId,$app,$response,true,$pdo);
    }catch(Throwable $e){
        vp3_system_apps_store_error_v110($userId,$app,$e->getMessage(),'release_update_failed',$pdo);
        vp3_system_apps_event_v110($userId,(int)$app['id'],'app.release.update_failed',[
          'to_version'=>$release['cloud_version'],'error'=>mb_substr($e->getMessage(),0,300),
        ],$pdo);
        throw $e;
    }

    $rolledBack=!empty($response['rolled_back']);
    $verification=is_array($response['verification']??null)?$response['verification']:[];
    $event=$rolledBack?'app.release.auto_rolled_back':'app.release.updated';
    vp3_system_apps_event_v110($userId,(int)$app['id'],$event,[
      'from_version'=>$before['installed_version']??null,
      'installed_version'=>$projection['installed_version']??null,
      'release_channel'=>$release['release_channel'],
      'package_sha256'=>$projection['package_sha256']??$release['package_sha256'],
      'verification'=>$verification,
      'rollback'=>$response['rollback']??null,
      'error'=>(string)($response['error']??''),
    ],$pdo);

    $hosting=vp3_system_apps_release_reconcile_hosting_v200($userId,$app,$remote,$pdo);
    vp3_system_apps_event_v110($userId,(int)$app['id'],'app.release.hosting_reconciled',[
      'site_id'=>$hosting['site_id']??null,
      'hostname'=>$hosting['hostname']??null,
      'pending'=>!empty($hosting['pending']),
      'warning'=>$hosting['warning']??null,
    ],$pdo);

    return [
      'contract'=>'vp3.system-app-release-operation.v1',
      'operation'=>'update',
      'app_key'=>(string)$app['app_key'],
      'changed'=>!empty($response['changed']),
      'rolled_back'=>$rolledBack,
      'release'=>$release,
      'verification'=>$verification,
      'homeserver'=>$projection,
      'hosting'=>$hosting,
      'reconcile_pending'=>!empty($hosting['pending']),
    ];
}

function vp3_system_apps_release_rollback_v200(
    int $userId,string $appKey,string $reason='owner_requested',?callable $remote=null,?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $app=vp3_system_apps_owned_row_v110($userId,$appKey,$pdo);
    $release=vp3_system_apps_release_status_v200($userId,$appKey,$remote,$pdo);
    if(empty($release['rollback_available']))throw new RuntimeException('No previous System App release is available for rollback.');
    $active=(string)($release['runtime']['active_release_id']??'');
    if($active==='')throw new RuntimeException('HomeServer did not return an active System App release.');

    $response=vp3_system_apps_remote_v110($userId,'apps.system.rollback',[
      'app_key'=>(string)$app['homeserver_catalog_key'],
      'expected_active_release_id'=>$active,
      'reason'=>$reason,
    ],$remote);
    $status=vp3_system_apps_remote_v110($userId,'apps.system.status',[
      'app_key'=>(string)$app['homeserver_catalog_key'],
    ],$remote);
    $projection=vp3_system_apps_store_remote_v110($userId,$app,$status,true,$pdo);
    vp3_system_apps_event_v110($userId,(int)$app['id'],'app.release.rolled_back',[
      'reason'=>$reason,
      'from_release_id'=>$active,
      'rollback'=>$response['rollback']??null,
      'installed_version'=>$projection['installed_version']??null,
    ],$pdo);

    $hosting=vp3_system_apps_release_reconcile_hosting_v200($userId,$app,$remote,$pdo);
    vp3_system_apps_event_v110($userId,(int)$app['id'],'app.release.hosting_reconciled',[
      'site_id'=>$hosting['site_id']??null,
      'hostname'=>$hosting['hostname']??null,
      'pending'=>!empty($hosting['pending']),
      'warning'=>$hosting['warning']??null,
    ],$pdo);

    return [
      'contract'=>'vp3.system-app-release-operation.v1',
      'operation'=>'rollback',
      'app_key'=>(string)$app['app_key'],
      'changed'=>!empty($response['changed']),
      'rollback'=>$response['rollback']??null,
      'homeserver'=>$projection,
      'hosting'=>$hosting,
      'reconcile_pending'=>!empty($hosting['pending']),
    ];
}

function vp3_system_apps_catalog_v200(?array $user=null,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $catalog=vp3_system_apps_catalog_v140($user,$pdo);
    foreach($catalog['apps'] as &$app){
        $app['release']=vp3_system_apps_release_metadata_v200($app);
    }unset($app);
    $catalog['release_contract']='vp3.system-app-release.v1';
    return $catalog;
}

function vp3_system_apps_capability_v200(): array
{
    return array_replace(vp3_system_apps_capability_v190(),[
      'release_contract'=>'vp3.system-app-release.v1',
      'cloud_release_authority'=>true,
      'homeserver_activation_authority'=>true,
      'release_channels'=>true,
      'release_notes'=>true,
      'compatibility_gates'=>true,
      'sha256_release_integrity'=>true,
      'post_update_verification'=>true,
      'protected_release_rollback'=>true,
      'hosting_subdomain_post_release_reconcile'=>true,
      'release_auto_update'=>false,
    ]);
}
