<?php
declare(strict_types=1);

require __DIR__.'/cloud-hosting-v230-mysql.php';
require dirname(__DIR__).'/includes/cloud-hosting-health-v240.php';

$healthSite=vp3_cloud_hosting_site_v100((int)$freshSite['id'],1,$testPdo);
if(!$healthSite)throw new RuntimeException('Health fixture site missing.');

$healthState=[
    'health_state'=>'recovering',
    'policy'=>[
        'enabled'=>true,'interval_seconds'=>60,'failure_threshold'=>3,
        'max_recovery_attempts'=>2,'cooldown_seconds'=>300,
        'auto_reactivate'=>true,'auto_rollback'=>true,'auto_restore'=>false,
    ],
];

$healthRemote=function(int $userId,string $operation,array $payload) use (&$healthState):array{
    if($userId!==1)throw new RuntimeException('Wrong health owner.');
    if($operation==='hosting.diagnostics.summary'){
        return [
            'contract'=>'vp3.hosting.diagnostics.v1','cloud_site_id'=>(string)$payload['cloud_site_id'],
            'window_minutes'=>60,'requests_total'=>45,'client_error_total'=>1,'server_error_total'=>4,
            'php_failure_total'=>2,'slow_request_total'=>2,'average_duration_ms'=>80.1,'p95_duration_ms'=>650.4,
            'max_duration_ms'=>1300,'response_bytes_total'=>1000,'storage_bytes'=>100000,'sqlite_bytes'=>20000,
            'sqlite'=>['healthy'=>true],'runtime'=>['state'=>'active','serving_ready'=>false,'php_cgi_available'=>true],
            'route'=>['configured'=>true,'ready'=>true,'hostname'=>'ui.sites.example.com','tls_state'=>'active'],
            'last_deploy'=>['release_id'=>'release_aaaaaaaaaaaaaaaaaaaaaaaa','app_version'=>'2.4.0'],
            'issues'=>['runtime_not_ready','recent_5xx'],'recent'=>[],'authoritative_source'=>'homeserver',
        ];
    }
    if($operation==='hosting.health.status'){
        return [
            'contract'=>'vp3.hosting.health-recovery.v1',
            'cloud_site_id'=>(string)$payload['cloud_site_id'],
            'health_state'=>$healthState['health_state'],
            'policy'=>$healthState['policy'],
            'incident'=>[
                'event_type'=>'hosting.health.incident.recovering','state'=>'recovering','created_at'=>'2026-09-29T18:20:00+00:00',
                'details'=>['incident_id'=>'hostinc_123456789012345678901234','issues'=>['runtime_not_ready']],
            ],
            'history'=>[
                ['event_type'=>'hosting.health.recovery.action','state'=>'succeeded','created_at'=>'2026-09-29T18:21:00+00:00','details'=>['incident_id'=>'hostinc_123456789012345678901234','action'=>'rollback','status'=>'succeeded']],
            ],
            'homeserver_authoritative'=>true,
        ];
    }
    if($operation==='hosting.health.check'){
        return ['contract'=>'vp3.hosting.health-recovery.v1','health_state'=>$healthState['health_state'],'cloud_site_id'=>(string)$payload['cloud_site_id']];
    }
    if($operation==='hosting.health.policy.update'){
        $healthState['policy']=array_merge($healthState['policy'],(array)$payload['policy']);
        return $healthState['policy']+['contract'=>'vp3.hosting.health-recovery.v1','cloud_site_id'=>(string)$payload['cloud_site_id']];
    }
    throw new RuntimeException('Unexpected health operation '.$operation);
};

$health=vp3_cloud_hosting_health_v240_summary($healthSite,$healthRemote,$testPdo);
if($health['health_state']!=='recovering')throw new RuntimeException('Health state mismatch.');
if(empty($health['policy']['auto_rollback'])||!empty($health['policy']['auto_restore']))throw new RuntimeException('Health recovery defaults mismatch.');
if(($health['history'][0]['action']??'')!=='rollback')throw new RuntimeException('Recovery history action missing.');
if(empty($health['homeserver_authoritative']))throw new RuntimeException('Health authority marker missing.');

$check=vp3_cloud_hosting_health_v240_check($healthSite,false,$healthRemote,$testPdo);
if(($check['health_state']??'')!=='recovering')throw new RuntimeException('Manual health check failed.');

try{
    vp3_cloud_hosting_ui_v140_execute($owner,'health.policy',[
        'site_id'=>(int)$healthSite['id'],'enabled'=>'1','auto_reactivate'=>'1','auto_rollback'=>'1',
        'auto_restore'=>'0','interval_seconds'=>120,'failure_threshold'=>2,'max_recovery_attempts'=>3,'cooldown_seconds'=>300,
    ],null,$healthRemote,null,null,$testPdo);
    throw new RuntimeException('Health policy update bypassed consequential confirmation.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Health policy update bypassed consequential confirmation.')throw $e;
    if(!str_contains($e->getMessage(),'Confirm this consequential'))throw $e;
}

$updated=vp3_cloud_hosting_ui_v140_execute($owner,'health.policy',[
    'site_id'=>(int)$healthSite['id'],'confirmed'=>'1','enabled'=>'1','auto_reactivate'=>'1','auto_rollback'=>'1',
    'auto_restore'=>'0','interval_seconds'=>120,'failure_threshold'=>2,'max_recovery_attempts'=>3,'cooldown_seconds'=>300,
],null,$healthRemote,null,null,$testPdo);
if((int)($updated['result']['failure_threshold']??0)!==2)throw new RuntimeException('Health policy threshold update failed.');
if((int)($updated['result']['max_recovery_attempts']??0)!==3)throw new RuntimeException('Health recovery attempt policy update failed.');

$agent=vp3_cloud_hosting_agent_v130_query('why is ui.sites.example.com down?',$owner,902,$healthRemote,null,$testPdo);
if(empty($agent['handled'])||empty($agent['hosting_plan']['read_only']))throw new RuntimeException('Agent health explanation was not read-only.');
if(!str_contains((string)$agent['answer'],'Automated health: recovering'))throw new RuntimeException('Agent omitted automated health state.');
if(!str_contains((string)$agent['answer'],'Last automatic recovery action: rollback (succeeded)'))throw new RuntimeException('Agent omitted recovery action history.');

$cap=vp3_cloud_hosting_health_v240_capability();
if(($cap['runtime_recovery_authority']??'')!=='homeserver'||($cap['desired_state_authority']??'')!=='cloud')throw new RuntimeException('Health authority boundary is invalid.');
if(!empty($cap['automatic_restore_default']))throw new RuntimeException('Automatic restore must remain disabled by default.');

echo "Cloud Hosting V2 Section 4 health recovery MySQL integration: PASS\n";
