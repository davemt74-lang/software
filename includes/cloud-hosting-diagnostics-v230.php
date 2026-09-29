<?php
declare(strict_types=1);

/**
 * Cloud Hosting V2 Section 3 — read-only HomeServer diagnostics.
 *
 * HomeServer remains authoritative for runtime traffic, failures, storage,
 * SQLite and release execution. Cloud combines that observed state with its
 * canonical DNS/TLS desired-state records for owner UI and Agent explanations.
 */

const VP3_CLOUD_HOSTING_DIAGNOSTICS_V230='cloud-hosting-diagnostics-v230';

function vp3_cloud_hosting_diagnostics_v230_summary(
    array $site,
    int $windowMinutes=60,
    int $recentLimit=30,
    ?callable $remote=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $siteId=(int)($site['id']??0);$userId=(int)($site['user_id']??0);
    if($siteId<1||$userId<1)throw new RuntimeException('A valid hosted site is required.');
    $fresh=vp3_cloud_hosting_site_v100($siteId,$userId,$pdo);
    if($fresh===null)throw new RuntimeException('Hosted site could not be loaded.');
    $fresh=vp3_cloud_hosting_v120_ensure_homeserver_binding($fresh,$pdo);

    $window=max(1,min(1440,$windowMinutes));
    $recent=max(0,min(100,$recentLimit));
    $raw=vp3_cloud_hosting_v120_remote($userId,'hosting.diagnostics.summary',[
        'cloud_site_id'=>(string)$fresh['site_key'],
        'window_minutes'=>$window,
        'recent_limit'=>$recent,
    ],$remote);

    $safeInt=static fn($value):int=>max(0,(int)$value);
    $safeFloat=static fn($value):float=>max(0.0,(float)$value);
    $runtime=(array)($raw['runtime']??[]);
    $route=(array)($raw['route']??[]);
    $sqlite=(array)($raw['sqlite']??[]);
    $lastDeploy=(array)($raw['last_deploy']??[]);
    $releaseId=trim((string)($lastDeploy['release_id']??''));
    if($releaseId!==''&&!preg_match('/^release_[0-9a-f]{24}$/',$releaseId))$releaseId='';

    $rows=[];
    foreach(array_slice((array)($raw['recent']??[]),0,$recent) as $row){
        if(!is_array($row))continue;
        $path=(string)($row['path']??'/');
        $path=preg_replace('/[?#].*$/','',$path)??'/';
        $path=mb_substr($path,0,240);
        $rows[]=[
            'source'=>in_array((string)($row['source']??''),['public','preview','health'],true)?(string)$row['source']:'',
            'method'=>mb_substr(preg_replace('/[^A-Z]/','',strtoupper((string)($row['method']??'GET')))??'GET',0,12),
            'path'=>$path!==''?$path:'/',
            'status'=>max(100,min(599,(int)($row['status']??200))),
            'duration_ms'=>$safeFloat($row['duration_ms']??0),
            'response_bytes'=>$safeInt($row['response_bytes']??0),
            'error_class'=>mb_substr(preg_replace('/[^A-Za-z0-9._:-]+/','_',strtolower((string)($row['error_class']??'')))??'',0,80),
            'created_at'=>mb_substr((string)($row['created_at']??''),0,80),
        ];
    }

    $cloudRoute=vp3_cloud_hosting_v110_route_for_site($siteId,$pdo);
    $cert=vp3_cloud_hosting_v120_edge_certificate($siteId,$pdo);
    $sync=vp3_cloud_hosting_ui_v140_sync($siteId,$pdo);

    $result=[
        'contract'=>'vp3.cloud-hosting-diagnostics.v1',
        'site_id'=>$siteId,
        'site_key'=>(string)$fresh['site_key'],
        'window_minutes'=>$window,
        'requests_total'=>$safeInt($raw['requests_total']??0),
        'client_error_total'=>$safeInt($raw['client_error_total']??0),
        'server_error_total'=>$safeInt($raw['server_error_total']??0),
        'php_failure_total'=>$safeInt($raw['php_failure_total']??0),
        'slow_request_total'=>$safeInt($raw['slow_request_total']??0),
        'average_duration_ms'=>$safeFloat($raw['average_duration_ms']??0),
        'p95_duration_ms'=>$safeFloat($raw['p95_duration_ms']??0),
        'max_duration_ms'=>$safeFloat($raw['max_duration_ms']??0),
        'response_bytes_total'=>$safeInt($raw['response_bytes_total']??0),
        'storage_bytes'=>$safeInt($raw['storage_bytes']??0),
        'sqlite_bytes'=>$safeInt($raw['sqlite_bytes']??0),
        'sqlite_healthy'=>!empty($sqlite['healthy']),
        'runtime_state'=>mb_substr((string)($runtime['state']??$fresh['observed_state']??''),0,30),
        'runtime_kind'=>mb_substr((string)($runtime['kind']??$fresh['runtime_kind']??''),0,20),
        'serving_ready'=>!empty($runtime['serving_ready']),
        'php_cgi_available'=>!empty($runtime['php_cgi_available']),
        'runtime_scheduler'=>[
            'inflight'=>$safeInt($runtime['scheduler']['inflight']??0),
            'rejected'=>$safeInt($runtime['scheduler']['rejected']??0),
            'failed'=>$safeInt($runtime['scheduler']['failed']??0),
        ],
        'homeserver_route_ready'=>!empty($route['ready']),
        'cloud_dns_state'=>(string)($cloudRoute['dns_state']??'not_provisioned'),
        'cloud_tls_state'=>(string)($cert['tls_state']??$cloudRoute['tls_state']??'pending'),
        'cloud_observed_state'=>(string)($fresh['observed_state']??'pending'),
        'cloud_desired_state'=>(string)($fresh['desired_state']??'configured'),
        'revision_current'=>$sync?((int)($sync['remote_revision']??0)>=(int)$fresh['desired_revision']):false,
        'last_deploy'=>$releaseId!==''?[
            'release_id'=>$releaseId,
            'app_version'=>mb_substr((string)($lastDeploy['app_version']??''),0,120),
            'runtime'=>mb_substr((string)($lastDeploy['runtime']??''),0,20),
            'created_at'=>mb_substr((string)($lastDeploy['created_at']??''),0,80),
        ]:null,
        'recent'=>$rows,
        'issues'=>[],
        'authoritative_runtime'=>'homeserver',
        'authoritative_desired_state'=>'cloud',
    ];
    $result['issues']=vp3_cloud_hosting_diagnostics_v230_issues($result);
    return $result;
}

function vp3_cloud_hosting_diagnostics_v230_issues(array $d): array
{
    $issues=[];
    if((string)($d['cloud_desired_state']??'')==='active'&&(string)($d['cloud_observed_state']??'')!=='active')$issues[]='HomeServer has not reached the Cloud desired active state.';
    if(empty($d['revision_current']))$issues[]='Cloud and HomeServer hosting revisions are not current.';
    if((string)($d['cloud_dns_state']??'')!=='verified')$issues[]='DNS is not verified.';
    if(!in_array((string)($d['cloud_tls_state']??''),['active','renewing'],true))$issues[]='Cloud-edge TLS is not active.';
    if((string)($d['cloud_desired_state']??'')==='active'&&empty($d['serving_ready']))$issues[]='HomeServer runtime is not ready to serve the active site.';
    if(empty($d['sqlite_healthy']))$issues[]='The hosted SQLite database health check is failing.';
    if((int)($d['server_error_total']??0)>0)$issues[]='Recent requests include server errors (5xx).';
    if((int)($d['php_failure_total']??0)>0)$issues[]='Recent requests include PHP runtime failures.';
    if((int)($d['slow_request_total']??0)>0)$issues[]='Recent requests exceeded the slow-request threshold.';
    return array_values(array_unique($issues));
}

function vp3_cloud_hosting_diagnostics_v230_explanation(array $d): string
{
    $issues=(array)($d['issues']??[]);
    if(!$issues)return 'I do not see a current hosting fault in DNS, TLS, reconciliation, runtime, SQLite, or recent server-error telemetry.';
    return implode("\n",array_map(static fn($issue)=>'• '.(string)$issue,$issues));
}

function vp3_cloud_hosting_diagnostics_v230_capability(): array
{
    return [
        'contract'=>'vp3.cloud-hosting-diagnostics.v1',
        'homeserver_request_counts'=>true,
        'http_4xx_5xx'=>true,
        'php_failures'=>true,
        'slow_requests'=>true,
        'duration_percentiles'=>true,
        'storage_sqlite_usage'=>true,
        'last_deploy'=>true,
        'route_tls_health'=>true,
        'bounded_recent_log'=>true,
        'agent_explanations'=>true,
        'read_only'=>true,
        'homeserver_authoritative_runtime'=>true,
        'cloud_authoritative_desired_state'=>true,
        'request_query_strings_retained'=>false,
        'request_bodies_retained'=>false,
        'authorization_headers_retained'=>false,
    ];
}
