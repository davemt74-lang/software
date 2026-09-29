<?php
declare(strict_types=1);

/**
 * Cloud Hosting V2 Section 2 — release history, promotion and retention.
 *
 * HomeServer is authoritative for retained release artifacts and execution.
 * Cloud uses the existing deployment ledger for consequential action
 * idempotency and stores only the already-canonical active/previous IDs.
 */

const VP3_CLOUD_HOSTING_RELEASES_V220='cloud-hosting-releases-v220';

function vp3_cloud_hosting_releases_v220_release_id(string $releaseId): string
{
    $releaseId=trim($releaseId);
    if(!preg_match('/^release_[0-9a-f]{24}$/',$releaseId)){
        throw new RuntimeException('Hosting release identifier is invalid.');
    }
    return $releaseId;
}

function vp3_cloud_hosting_releases_v220_catalog(
    array $site,
    ?callable $remote=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $siteId=(int)($site['id']??0);
    $userId=(int)($site['user_id']??0);
    if($siteId<1||$userId<1)throw new RuntimeException('A valid hosted site is required.');
    $fresh=vp3_cloud_hosting_site_v100($siteId,$userId,$pdo);
    if($fresh===null)throw new RuntimeException('Hosted site could not be loaded.');
    $fresh=vp3_cloud_hosting_v120_ensure_homeserver_binding($fresh,$pdo);

    $result=vp3_cloud_hosting_v120_remote($userId,'hosting.deployment.releases',[
        'cloud_site_id'=>(string)$fresh['site_key'],
    ],$remote);
    $active=trim((string)($result['active_release_id']??''));
    $previous=trim((string)($result['previous_release_id']??''));
    if($active!==''&&!preg_match('/^release_[0-9a-f]{24}$/',$active))throw new RuntimeException('HomeServer returned an invalid active release identifier.');
    if($previous!==''&&!preg_match('/^release_[0-9a-f]{24}$/',$previous))throw new RuntimeException('HomeServer returned an invalid previous release identifier.');

    $releases=[];
    foreach((array)($result['releases']??[]) as $row){
        if(!is_array($row))continue;
        $releaseId=trim((string)($row['release_id']??''));
        if(!preg_match('/^release_[0-9a-f]{24}$/',$releaseId))continue;
        $sha=strtolower(trim((string)($row['package_sha256']??'')));
        if($sha!==''&&!preg_match('/^[0-9a-f]{64}$/',$sha))$sha='';
        $runtime=strtolower(trim((string)($row['runtime']??'')));
        if(!in_array($runtime,['static','php'],true))$runtime='';
        $releases[]=[
            'release_id'=>$releaseId,
            'app_version'=>mb_substr(trim((string)($row['app_version']??'')),0,120),
            'runtime'=>$runtime,
            'package_sha256'=>$sha,
            'created_at'=>mb_substr(trim((string)($row['created_at']??'')),0,80),
            'active'=>!empty($row['active'])||($active!==''&&hash_equals($active,$releaseId)),
            'previous'=>!empty($row['previous'])||($previous!==''&&hash_equals($previous,$releaseId)),
        ];
    }

    $pdo->prepare("UPDATE cloud_hosting_sites
      SET active_release_id=?,previous_release_id=?,last_error_code='',last_error_message=''
      WHERE id=? AND user_id=?")
      ->execute([$active!==''?$active:null,$previous!==''?$previous:null,$siteId,$userId]);

    return [
        'contract'=>'vp3.cloud-hosting-releases.v1',
        'active_release_id'=>$active!==''?$active:null,
        'previous_release_id'=>$previous!==''?$previous:null,
        'releases'=>$releases,
        'homeserver_authoritative'=>true,
    ];
}

function vp3_cloud_hosting_releases_v220_promote(
    array $site,
    string $releaseId,
    string $requestKey,
    ?int $actorUserId=null,
    ?callable $remote=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $siteId=(int)($site['id']??0);$userId=(int)($site['user_id']??0);
    if($siteId<1||$userId<1)throw new RuntimeException('A valid hosted site is required.');
    $releaseId=vp3_cloud_hosting_releases_v220_release_id($releaseId);
    $requestKey=trim($requestKey);
    if($requestKey===''||strlen($requestKey)>160)throw new RuntimeException('A valid promotion idempotency key is required.');

    $fresh=vp3_cloud_hosting_site_v100($siteId,$userId,$pdo);
    if($fresh===null)throw new RuntimeException('Hosted site could not be loaded.');
    vp3_cloud_hosting_v120_assert_deployment_entitled($fresh,$pdo);
    $targetHash=hash('sha256',$releaseId);
    $claim=vp3_cloud_hosting_v120_claim_deployment(
        $pdo,$siteId,$requestKey,'promote',(int)$fresh['desired_revision'],$targetHash,0,$actorUserId
    );
    $existing=(array)$claim['row'];
    if((string)$existing['operation']!=='promote'||!hash_equals((string)$existing['package_sha256'],$targetHash)){
        throw new RuntimeException('Promotion idempotency key was already used for a different release action.');
    }
    if((string)$existing['state']==='promoted')return ['replayed'=>true,'deployment'=>$existing];
    if((string)$existing['state']==='failed')throw new RuntimeException('Failed promotion requires a new idempotency key.');

    $id=(int)$existing['id'];
    try{
        $result=vp3_cloud_hosting_v120_remote($userId,'hosting.deployment.promote',[
            'cloud_site_id'=>(string)$fresh['site_key'],
            'release_id'=>$releaseId,
            'request_key'=>$requestKey,
        ],$remote);
        if((string)($result['state']??'')!=='applied')throw new RuntimeException('HomeServer did not promote the requested release.');
        $active=vp3_cloud_hosting_releases_v220_release_id((string)($result['release_id']??''));
        $previous=trim((string)($result['previous_release_id']??''));
        if($previous!==''&&!preg_match('/^release_[0-9a-f]{24}$/',$previous))throw new RuntimeException('HomeServer returned an invalid previous release identifier.');

        vp3_cloud_hosting_v120_update_deployment($pdo,$id,'promoted',$result);
        $pdo->prepare("UPDATE cloud_hosting_sites SET active_release_id=?,previous_release_id=?,last_error_code='',last_error_message='' WHERE id=?")
            ->execute([$active,$previous!==''?$previous:null,$siteId]);
        vp3_cloud_hosting_event_v100($pdo,$siteId,'deployment.promoted','promoted',(int)$fresh['desired_revision'],$actorUserId,[
            'release_id'=>$active,'previous_release_id'=>$previous,
        ]);
        $post=vp3_cloud_hosting_v120_reconcile_site(vp3_cloud_hosting_site_v100($siteId,$userId,$pdo)??$fresh,$remote,$pdo);
        return [
            'replayed'=>false,
            'deployment'=>vp3_cloud_hosting_v120_deployment_row($siteId,$requestKey,$pdo),
            'reconcile'=>$post,
        ];
    }catch(Throwable $e){
        vp3_cloud_hosting_v120_update_deployment($pdo,$id,'interrupted',[],$e->getMessage());
        throw $e;
    }
}

function vp3_cloud_hosting_releases_v220_prune(
    array $site,
    int $keep,
    string $requestKey,
    ?int $actorUserId=null,
    ?callable $remote=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $siteId=(int)($site['id']??0);$userId=(int)($site['user_id']??0);
    if($siteId<1||$userId<1)throw new RuntimeException('A valid hosted site is required.');
    $keep=max(2,min(50,$keep));
    $requestKey=trim($requestKey);
    if($requestKey===''||strlen($requestKey)>160)throw new RuntimeException('A valid retention idempotency key is required.');

    $fresh=vp3_cloud_hosting_site_v100($siteId,$userId,$pdo);
    if($fresh===null)throw new RuntimeException('Hosted site could not be loaded.');
    vp3_cloud_hosting_v120_assert_deployment_entitled($fresh,$pdo);
    $targetHash=hash('sha256','keep:'.$keep);
    $claim=vp3_cloud_hosting_v120_claim_deployment(
        $pdo,$siteId,$requestKey,'prune',(int)$fresh['desired_revision'],$targetHash,0,$actorUserId
    );
    $existing=(array)$claim['row'];
    if((string)$existing['operation']!=='prune'||!hash_equals((string)$existing['package_sha256'],$targetHash)){
        throw new RuntimeException('Retention idempotency key was already used for a different release action.');
    }
    if((string)$existing['state']==='pruned')return ['replayed'=>true,'deployment'=>$existing];
    if((string)$existing['state']==='failed')throw new RuntimeException('Failed release retention requires a new idempotency key.');

    $id=(int)$existing['id'];
    try{
        $result=vp3_cloud_hosting_v120_remote($userId,'hosting.deployment.prune',[
            'cloud_site_id'=>(string)$fresh['site_key'],
            'keep'=>$keep,
            'request_key'=>$requestKey,
        ],$remote);
        if((string)($result['state']??'')!=='applied')throw new RuntimeException('HomeServer did not apply release retention.');
        vp3_cloud_hosting_v120_update_deployment($pdo,$id,'pruned',$result);
        $active=trim((string)($result['active_release_id']??''));
        $previous=trim((string)($result['previous_release_id']??''));
        if($active!==''&&!preg_match('/^release_[0-9a-f]{24}$/',$active))throw new RuntimeException('HomeServer returned an invalid active release identifier.');
        if($previous!==''&&!preg_match('/^release_[0-9a-f]{24}$/',$previous))throw new RuntimeException('HomeServer returned an invalid previous release identifier.');
        $pdo->prepare("UPDATE cloud_hosting_sites SET active_release_id=?,previous_release_id=?,last_error_code='',last_error_message='' WHERE id=?")
            ->execute([$active!==''?$active:null,$previous!==''?$previous:null,$siteId]);
        vp3_cloud_hosting_event_v100($pdo,$siteId,'deployment.releases_pruned','pruned',(int)$fresh['desired_revision'],$actorUserId,[
            'keep'=>$keep,
            'deleted_count'=>count((array)($result['deleted_release_ids']??[])),
        ]);
        return [
            'replayed'=>false,
            'deployment'=>vp3_cloud_hosting_v120_deployment_row($siteId,$requestKey,$pdo),
            'catalog'=>vp3_cloud_hosting_releases_v220_catalog(vp3_cloud_hosting_site_v100($siteId,$userId,$pdo)??$fresh,$remote,$pdo),
        ];
    }catch(Throwable $e){
        vp3_cloud_hosting_v120_update_deployment($pdo,$id,'interrupted',[],$e->getMessage());
        throw $e;
    }
}

function vp3_cloud_hosting_releases_v220_capability(): array
{
    return [
        'contract'=>'vp3.cloud-hosting-releases.v1',
        'live_homeserver_release_catalog'=>true,
        'historical_release_promotion'=>true,
        'safe_release_retention'=>true,
        'active_previous_release_protected_by_homeserver'=>true,
        'existing_deployment_ledger_reused'=>true,
        'consequential_confirmation_required'=>true,
        'homeserver_authoritative_execution'=>true,
        'cloud_filesystem_access'=>false,
        'cloud_raw_sql'=>false,
        'sqlite_schema_rewind_claimed'=>false,
    ];
}
