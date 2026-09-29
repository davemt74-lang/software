<?php
declare(strict_types=1);

require __DIR__.'/cloud-hosting-v220-mysql.php';
require dirname(__DIR__).'/includes/cloud-hosting-diagnostics-v230.php';

$diagSite=vp3_cloud_hosting_site_v100((int)$freshSite['id'],1,$testPdo);
if(!$diagSite)throw new RuntimeException('Diagnostics fixture site missing.');

$diagRemote=function(int $userId,string $operation,array $payload):array{
    if($userId!==1)throw new RuntimeException('Wrong diagnostics owner.');
    if($operation==='hosting.diagnostics.summary'){
        return [
            'contract'=>'vp3.hosting.diagnostics.v1',
            'cloud_site_id'=>(string)$payload['cloud_site_id'],
            'window_minutes'=>(int)$payload['window_minutes'],
            'requests_total'=>120,
            'client_error_total'=>4,
            'server_error_total'=>2,
            'php_failure_total'=>1,
            'slow_request_total'=>3,
            'average_duration_ms'=>41.5,
            'p95_duration_ms'=>190.2,
            'max_duration_ms'=>1400,
            'response_bytes_total'=>55000,
            'storage_bytes'=>2048000,
            'sqlite_bytes'=>262144,
            'sqlite'=>['healthy'=>true,'integrity'=>'ok'],
            'runtime'=>['state'=>'active','serving_ready'=>true,'php_cgi_available'=>true,'scheduler'=>[]],
            'route'=>['configured'=>true,'ready'=>true,'hostname'=>'ui.sites.example.com','tls_state'=>'active'],
            'last_deploy'=>['release_id'=>'release_aaaaaaaaaaaaaaaaaaaaaaaa','app_version'=>'2.3.0','created_at'=>'2026-09-29T18:00:00+00:00'],
            'issues'=>['recent_5xx','recent_php_failures'],
            'recent'=>[
                ['source'=>'public','method'=>'GET','path'=>'/checkout.php?token=SHOULD_NOT_LEAK','status'=>502,'duration_ms'=>1200,'response_bytes'=>0,'error_class'=>'php.runtime','created_at'=>'2026-09-29T18:01:00+00:00'],
            ],
            'authoritative_source'=>'homeserver',
        ];
    }
    throw new RuntimeException('Unexpected diagnostics operation '.$operation);
};

$summary=vp3_cloud_hosting_diagnostics_v230_summary($diagSite,60,12,$diagRemote,$testPdo);
if($summary['requests_total']!==120||$summary['server_error_total']!==2||$summary['php_failure_total']!==1)throw new RuntimeException('Diagnostics metrics mismatch.');
if(abs((float)$summary['p95_duration_ms']-190.2)>0.01)throw new RuntimeException('Diagnostics p95 mismatch.');
if(($summary['recent'][0]['path']??'')!=='/checkout.php')throw new RuntimeException('Diagnostics query string was not removed.');
if(str_contains(json_encode($summary),'SHOULD_NOT_LEAK'))throw new RuntimeException('Diagnostics leaked a query-string secret.');
if(($summary['authoritative_runtime']??'')!=='homeserver'||($summary['authoritative_desired_state']??'')!=='cloud')throw new RuntimeException('Diagnostics authority marker missing.');

$explanation=vp3_cloud_hosting_diagnostics_v230_explanation($summary);
if(!str_contains($explanation,'5xx')||!str_contains($explanation,'PHP'))throw new RuntimeException('Diagnostics explanation is incomplete.');

$agentStatus=vp3_cloud_hosting_agent_v130_query('why is ui.sites.example.com down?',$owner,901,$diagRemote,null,$testPdo);
if(empty($agentStatus['handled'])||empty($agentStatus['hosting_plan']['read_only']))throw new RuntimeException('Agent diagnostics intent was not read-only.');
if(!str_contains((string)$agentStatus['answer'],'Automated health: unknown'))throw new RuntimeException('Agent diagnostics answer omitted health state.');
if(!str_contains((string)$agentStatus['answer'],'5xx'))throw new RuntimeException('Agent diagnostics answer omitted runtime explanation.');
if(str_contains(json_encode($agentStatus),'SHOULD_NOT_LEAK'))throw new RuntimeException('Agent diagnostics leaked a secret.');

$cap=vp3_cloud_hosting_diagnostics_v230_capability();
if(empty($cap['homeserver_authoritative_runtime'])||!empty($cap['request_query_strings_retained'])||!empty($cap['request_bodies_retained'])||!empty($cap['authorization_headers_retained']))throw new RuntimeException('Diagnostics capability boundary is invalid.');

echo "Cloud Hosting V2 Section 3 diagnostics MySQL integration: PASS\n";
