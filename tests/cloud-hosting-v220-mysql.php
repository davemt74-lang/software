<?php
declare(strict_types=1);

require __DIR__.'/cloud-hosting-v140-mysql.php';
require dirname(__DIR__).'/includes/cloud-hosting-releases-v220.php';
require dirname(__DIR__).'/includes/cloud-hosting-agent-v130.php';

vp3_cloud_hosting_agent_v130_ensure_schema($testPdo);

$freshSite=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
if(!$freshSite)throw new RuntimeException('V1 Hosting fixture site missing.');

$r1='release_111111111111111111111111';
$r2='release_222222222222222222222222';
$r3='release_333333333333333333333333';
$r4='release_444444444444444444444444';

$v2state=[
    'active'=>$r4,
    'previous'=>$r3,
    'rows'=>[
        ['release_id'=>$r4,'app_version'=>'2.2.4','runtime'=>'static','package_sha256'=>str_repeat('4',64),'created_at'=>'2026-09-29T17:00:00+00:00','active'=>true,'previous'=>false],
        ['release_id'=>$r3,'app_version'=>'2.2.3','runtime'=>'static','package_sha256'=>str_repeat('3',64),'created_at'=>'2026-09-29T16:00:00+00:00','active'=>false,'previous'=>true],
        ['release_id'=>$r2,'app_version'=>'2.2.2','runtime'=>'static','package_sha256'=>str_repeat('2',64),'created_at'=>'2026-09-29T15:00:00+00:00','active'=>false,'previous'=>false],
        ['release_id'=>$r1,'app_version'=>'2.2.1','runtime'=>'static','package_sha256'=>str_repeat('1',64),'created_at'=>'2026-09-29T14:00:00+00:00','active'=>false,'previous'=>false],
    ],
    'promote_ack'=>[],
    'prune_ack'=>[],
];

$v2remote=function(int $userId,string $operation,array $payload) use (&$v2state):array {
    if($userId!==1)throw new RuntimeException('Wrong HomeServer owner.');
    if($operation==='hosting.entitlements.reconcile'){
        return ['revision'=>(int)$payload['revision'],'reconcile_result'=>'applied'];
    }
    if($operation==='hosting.site.reconcile'){
        return [
            'site_id'=>'local-release-history',
            'revision'=>(int)$payload['revision'],
            'desired_state'=>(string)$payload['desired_state'],
            'observed_state'=>(string)$payload['desired_state'],
            'active_release_id'=>$v2state['active'],
            'public_routing'=>false,
            'reconcile_result'=>'applied',
        ];
    }
    if($operation==='hosting.route.reconcile'){
        return [
            'revision'=>(int)$payload['revision'],
            'hostname'=>(string)$payload['hostname'],
            'desired_state'=>(string)$payload['desired_state'],
            'route_ready'=>$payload['desired_state']==='active',
            'route_token'=>'V2_ROUTE_TOKEN_SHOULD_NOT_LEAK',
        ];
    }
    if($operation==='hosting.deployment.releases'){
        $rows=[];
        foreach($v2state['rows'] as $row){
            $row['active']=$row['release_id']===$v2state['active'];
            $row['previous']=$row['release_id']===$v2state['previous'];
            $rows[]=$row;
        }
        return [
            'contract'=>'vp3.hosting.cloud-deployment.v1',
            'active_release_id'=>$v2state['active'],
            'previous_release_id'=>$v2state['previous'],
            'releases'=>$rows,
        ];
    }
    if($operation==='hosting.deployment.promote'){
        $key=(string)$payload['request_key'];$release=(string)$payload['release_id'];
        if(isset($v2state['promote_ack'][$key])){
            $saved=$v2state['promote_ack'][$key];
            if($saved['release_id']!==$release)throw new RuntimeException('Remote promotion key conflict.');
            return $saved;
        }
        $known=false;
        foreach($v2state['rows'] as $row)if($row['release_id']===$release)$known=true;
        if(!$known)throw new RuntimeException('Release not retained.');
        $previous=$v2state['active'];
        $v2state['previous']=$previous;
        $v2state['active']=$release;
        $result=[
            'state'=>'applied','release_id'=>$release,'previous_release_id'=>$previous,
            'pre_promote_recovery_id'=>'recovery_v220_1',
            'recovery'=>['latest_verified'=>true],
            'sqlite'=>['healthy'=>true],
        ];
        $v2state['promote_ack'][$key]=$result;
        return $result;
    }
    if($operation==='hosting.deployment.prune'){
        $key=(string)$payload['request_key'];$keep=max(2,min(50,(int)$payload['keep']));
        if(isset($v2state['prune_ack'][$key])){
            $saved=$v2state['prune_ack'][$key];
            if((int)$saved['keep']!==$keep)throw new RuntimeException('Remote retention key conflict.');
            return $saved;
        }
        $protected=[$v2state['active']=>true,$v2state['previous']=>true];
        $keepIds=[];
        foreach(array_slice($v2state['rows'],0,$keep) as $row)$keepIds[$row['release_id']]=true;
        foreach($protected as $id=>$yes)if($id!=='')$keepIds[$id]=true;
        $deleted=[];$rows=[];
        foreach($v2state['rows'] as $row){
            if(isset($keepIds[$row['release_id']]))$rows[]=$row;
            else $deleted[]=$row['release_id'];
        }
        $v2state['rows']=$rows;
        $result=[
            'state'=>'applied','keep'=>$keep,'deleted_release_ids'=>$deleted,
            'active_release_id'=>$v2state['active'],'previous_release_id'=>$v2state['previous'],
            'releases'=>$rows,
        ];
        $v2state['prune_ack'][$key]=$result;
        return $result;
    }
    if($operation==='hosting.dashboard'){
        return ['sites'=>[]];
    }
    throw new RuntimeException('Unexpected V2 HomeServer operation '.$operation);
};

$catalog=vp3_cloud_hosting_releases_v220_catalog($freshSite,$v2remote,$testPdo);
if(count($catalog['releases'])!==4)throw new RuntimeException('Release catalog count mismatch.');
if($catalog['active_release_id']!==$r4||$catalog['previous_release_id']!==$r3)throw new RuntimeException('Release catalog active/previous mismatch.');
if(empty($catalog['releases'][0]['active'])||empty($catalog['releases'][1]['previous']))throw new RuntimeException('Release catalog flags are missing.');
if(str_contains(json_encode($catalog),'V2_ROUTE_TOKEN_SHOULD_NOT_LEAK'))throw new RuntimeException('Release catalog leaked route token.');

$siteAfterCatalog=vp3_cloud_hosting_site_v100((int)$freshSite['id'],1,$testPdo);
if(($siteAfterCatalog['active_release_id']??'')!==$r4||($siteAfterCatalog['previous_release_id']??'')!==$r3){
    throw new RuntimeException('Cloud active/previous release cache did not align to HomeServer catalog.');
}

try{
    vp3_cloud_hosting_ui_v140_execute($owner,'deployment.promote',[
        'site_id'=>(int)$freshSite['id'],'release_id'=>$r1,'request_key'=>'ui-promote-1'
    ],null,$v2remote,null,null,$testPdo);
    throw new RuntimeException('UI historical promotion bypassed confirmation.');
}catch(RuntimeException $e){
    if($e->getMessage()==='UI historical promotion bypassed confirmation.')throw $e;
    if(!str_contains($e->getMessage(),'Confirm this consequential'))throw $e;
}

$promoted=vp3_cloud_hosting_ui_v140_execute($owner,'deployment.promote',[
    'site_id'=>(int)$freshSite['id'],'release_id'=>$r1,'request_key'=>'ui-promote-1','confirmed'=>'1'
],null,$v2remote,null,null,$testPdo);
if(($promoted['site']['active_release_id']??'')!==$r1)throw new RuntimeException('UI historical release promotion failed.');
$promotionRow=vp3_cloud_hosting_v120_deployment_row((int)$freshSite['id'],'ui-promote-1',$testPdo);
if(($promotionRow['operation']??'')!=='promote'||($promotionRow['state']??'')!=='promoted')throw new RuntimeException('Promotion did not use the canonical deployment ledger.');

$promoteReplay=vp3_cloud_hosting_releases_v220_promote(
    vp3_cloud_hosting_site_v100((int)$freshSite['id'],1,$testPdo),
    $r1,'ui-promote-1',1,$v2remote,$testPdo
);
if(empty($promoteReplay['replayed']))throw new RuntimeException('Cloud promotion idempotency replay failed.');

try{
    vp3_cloud_hosting_releases_v220_promote(
        vp3_cloud_hosting_site_v100((int)$freshSite['id'],1,$testPdo),
        $r2,'ui-promote-1',1,$v2remote,$testPdo
    );
    throw new RuntimeException('Promotion idempotency key accepted a different release.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Promotion idempotency key accepted a different release.')throw $e;
}

try{
    vp3_cloud_hosting_releases_v220_promote(
        vp3_cloud_hosting_site_v100((int)$freshSite['id'],1,$testPdo),
        'release_../../escape','bad-release',1,$v2remote,$testPdo
    );
    throw new RuntimeException('Cloud accepted unsafe release identifier.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Cloud accepted unsafe release identifier.')throw $e;
}

$pruned=vp3_cloud_hosting_ui_v140_execute($owner,'deployment.prune',[
    'site_id'=>(int)$freshSite['id'],'keep'=>2,'request_key'=>'ui-prune-1','confirmed'=>'1'
],null,$v2remote,null,null,$testPdo);
$pruneRow=vp3_cloud_hosting_v120_deployment_row((int)$freshSite['id'],'ui-prune-1',$testPdo);
if(($pruneRow['operation']??'')!=='prune'||($pruneRow['state']??'')!=='pruned')throw new RuntimeException('Retention did not use the canonical deployment ledger.');
$remaining=array_column((array)$pruned['result']['catalog']['releases'],'release_id');
if(!in_array($r1,$remaining,true)||!in_array($r4,$remaining,true))throw new RuntimeException('Retention lost active or previous release.');

$agentList=vp3_cloud_hosting_agent_v130_query(
    'show release history for ui.sites.example.com',
    $owner,820,$v2remote,null,$testPdo
);
if(empty($agentList['handled'])||empty($agentList['hosting_plan']['read_only']))throw new RuntimeException('Agent did not expose read-only release history.');
if(!str_contains((string)$agentList['answer'],'Retained releases'))throw new RuntimeException('Agent release-history answer is incomplete.');

$targetForAgent='';
foreach((array)$agentList['hosting_plan']['release_catalog']['releases'] as $row){
    if(empty($row['active'])){$targetForAgent=(string)$row['release_id'];break;}
}
if($targetForAgent==='')throw new RuntimeException('Agent fixture has no non-active release to promote.');

$agentPrepared=vp3_cloud_hosting_agent_v130_query(
    'promote '.$targetForAgent.' for ui.sites.example.com',
    $owner,821,$v2remote,null,$testPdo
);
$agentCode=(string)($agentPrepared['hosting_plan']['confirmation_code']??'');
if(!preg_match('/^[A-Z2-9]{8}$/',$agentCode))throw new RuntimeException('Agent historical promotion did not require confirmation.');
if(($agentPrepared['hosting_plan']['action_type']??'')!=='deployment.promote')throw new RuntimeException('Agent prepared wrong release action.');

$agentDone=vp3_cloud_hosting_agent_v130_query('confirm hosting '.$agentCode,$owner,821,$v2remote,null,$testPdo);
if(empty($agentDone['hosting_plan']['completed']))throw new RuntimeException('Agent historical promotion did not complete.');

$agentPrune=vp3_cloud_hosting_agent_v130_query(
    'prune releases keep 2 for ui.sites.example.com',
    $owner,822,$v2remote,null,$testPdo
);
if(($agentPrune['hosting_plan']['action_type']??'')!=='deployment.prune')throw new RuntimeException('Agent did not prepare release retention.');
if((int)($agentPrune['hosting_plan']['preview']['keep']??0)!==2)throw new RuntimeException('Agent retention preview count mismatch.');

$cap=vp3_cloud_hosting_releases_v220_capability();
foreach([
    'live_homeserver_release_catalog','historical_release_promotion','safe_release_retention',
    'active_previous_release_protected_by_homeserver','existing_deployment_ledger_reused',
    'consequential_confirmation_required','homeserver_authoritative_execution'
] as $key)if(empty($cap[$key]))throw new RuntimeException('Release-history capability missing '.$key);
if(!empty($cap['cloud_filesystem_access'])||!empty($cap['cloud_raw_sql'])||!empty($cap['sqlite_schema_rewind_claimed'])){
    throw new RuntimeException('Release-history authority boundary is invalid.');
}

$ledger=$testPdo->query("SELECT operation,state,response_json FROM cloud_hosting_deployments WHERE operation IN ('promote','prune') ORDER BY id")->fetchAll();
$ledgerJson=json_encode($ledger);
foreach(['V2_ROUTE_TOKEN_SHOULD_NOT_LEAK','/home/','\\\\'] as $secret){
    if($secret!==''&&str_contains((string)$ledgerJson,$secret))throw new RuntimeException('Release action ledger leaked sensitive runtime data.');
}

echo "Cloud Hosting V2 Section 2 release history MySQL integration: PASS\n";
