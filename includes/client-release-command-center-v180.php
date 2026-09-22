<?php
declare(strict_types=1);

const VP3_CLIENT_RELEASE_COMMAND_CENTER_V180 = 'client-release-command-center-v180-20260922';

function client_release_command_center_product_label_v180(string $product): string
{
    return $product==='browser_companion'?'Browser Companion':($product==='homeserver'?'HomeServer':ucwords(str_replace('_',' ',$product)));
}

function client_release_command_center_state_label_v180(string $state): string
{
    return ucwords(str_replace('_',' ',trim($state)));
}

function client_release_command_center_index_by_release_v180(array $rows): array
{
    $out=[];
    foreach($rows as $row){
        if(!is_array($row))continue;
        $product=(string)($row['product']??'');
        $releaseId=(int)($row['release_id']??0);
        if($product!==''&&$releaseId>0)$out[$product][$releaseId]=$row;
    }
    return $out;
}

function client_release_command_center_summary_index_v180(array $summary): array
{
    $out=[];
    foreach(['browser_companion','homeserver'] as $product){
        foreach((array)($summary[$product]??[]) as $row){
            if(!is_array($row))continue;
            $releaseId=(int)($row['release_id']??0);
            if($releaseId>0)$out[$product][$releaseId]=$row;
        }
    }
    return $out;
}

function client_release_command_center_active_incidents_v180(PDO $pdo): array
{
    return array_values(array_filter(
        client_release_incident_list_v130($pdo,100),
        static fn($row)=>is_array($row)&&(string)($row['status']??'')!=='resolved'
    ));
}

function client_release_command_center_release_rows_v180(
    PDO $pdo,
    array $healthSummary,
    array $riskSummary,
    array $readinessSummary,
    array $incidents,
    array $automation
): array {
    $health=client_release_command_center_summary_index_v180($healthSummary);
    $risk=client_release_command_center_summary_index_v180($riskSummary);
    $ready=client_release_command_center_summary_index_v180($readinessSummary);
    $incidentByRelease=[];
    foreach($incidents as $incident){
        $product=(string)($incident['product']??'');$releaseId=(int)($incident['release_id']??0);
        if($product!==''&&$releaseId>0&&!isset($incidentByRelease[$product][$releaseId]))$incidentByRelease[$product][$releaseId]=$incident;
    }
    $proposalByRelease=[];
    foreach((array)($automation['pending']??[]) as $proposal){
        $product=(string)($proposal['product']??'');$releaseId=(int)($proposal['release_id']??0);
        if($product!==''&&$releaseId>0&&!isset($proposalByRelease[$product][$releaseId]))$proposalByRelease[$product][$releaseId]=$proposal;
    }

    $rows=[];
    foreach(['browser_companion','homeserver'] as $product){
        $seen=0;
        foreach(client_release_admin_rollouts_v110($pdo,$product) as $release){
            $rollout=(array)($release['_rollout']??[]);
            $state=(string)($rollout['lifecycle_state']??'draft');
            if(in_array($state,['superseded','withdrawn'],true))continue;
            $releaseId=(int)$release['id'];
            $h=(array)($health[$product][$releaseId]??[]);
            $r=(array)($risk[$product][$releaseId]??[]);
            $rd=(array)($ready[$product][$releaseId]??[]);
            $incident=(array)($incidentByRelease[$product][$releaseId]??[]);
            $proposal=(array)($proposalByRelease[$product][$releaseId]??[]);
            $rows[]=[
                'product'=>$product,
                'product_label'=>client_release_command_center_product_label_v180($product),
                'release_id'=>$releaseId,
                'version'=>(string)($release['version']??''),
                'channel'=>(string)($release['channel']??'stable'),
                'lifecycle_state'=>$state,
                'rollout_percent'=>(int)($rollout['rollout_percent']??0),
                'health_status'=>(string)($h['health_status']??'unknown'),
                'health_recommendation'=>(string)($h['recommendation']??'manual_validation'),
                'risk_level'=>(string)($r['risk_level']??'unknown'),
                'risk_score'=>(int)($r['risk_score']??0),
                'readiness_state'=>(string)($rd['state']??'incomplete'),
                'readiness_reason'=>(string)($rd['state_reason']??''),
                'incident_status'=>(string)($incident['status']??''),
                'incident_id'=>(int)($incident['id']??0),
                'proposal_id'=>(int)($proposal['id']??0),
                'proposal_type'=>(string)($proposal['proposal_type']??''),
                'proposal_status'=>(string)($proposal['proposal_status']??''),
                'updated_at'=>(string)($rollout['updated_at']??$release['updated_at']??$release['created_at']??''),
            ];
            $seen++;
            if($seen>=6)break;
        }
    }
    usort($rows,static function(array $a,array $b): int{
        $priority=['canary'=>5,'limited'=>4,'testing'=>3,'general_availability'=>2,'paused'=>1,'draft'=>0];
        $ap=$priority[$a['lifecycle_state']]??0;$bp=$priority[$b['lifecycle_state']]??0;
        if($ap!==$bp)return $bp<=>$ap;
        return strcmp((string)$b['updated_at'],(string)$a['updated_at']);
    });
    return $rows;
}

function client_release_command_center_attention_v180(
    array $releaseRows,
    array $incidents,
    array $fleetSummary,
    array $automation
): array {
    $items=[];
    $add=static function(string $severity,string $type,string $title,string $detail,string $href,array $context=[]) use (&$items): void {
        $items[]=array_merge([
            'severity'=>$severity,'type'=>$type,'title'=>$title,'detail'=>$detail,'href'=>$href
        ],$context);
    };

    foreach($incidents as $incident){
        $product=(string)($incident['product']??'');
        $version=(string)(($incident['_affected_release']['version']??'')?:('#'.(int)($incident['release_id']??0)));
        $add('critical','incident',
            client_release_command_center_product_label_v180($product).' incident · v'.$version,
            client_release_command_center_state_label_v180((string)($incident['status']??'active')).' — '.trim((string)($incident['summary']??$incident['reason']??'Operator recovery controls are active.')),
            '/admin/homeserver.php#release-incidents',
            ['product'=>$product,'release_id'=>(int)($incident['release_id']??0),'incident_id'=>(int)($incident['id']??0)]
        );
    }

    foreach($releaseRows as $row){
        $label=$row['product_label'].' v'.$row['version'];
        if(in_array($row['health_status'],['critical','hold'],true)){
            $add($row['health_status']==='critical'?'critical':'high','health',
                $label.' · '.client_release_command_center_state_label_v180($row['health_status']),
                client_release_health_recommendation_label_v120($row['health_recommendation']),
                '/admin/homeserver.php#release-health',$row
            );
        }
        if(in_array($row['readiness_state'],['blocked','stale','unsigned'],true)
            &&!in_array($row['lifecycle_state'],['general_availability'],true)){
            $add($row['readiness_state']==='blocked'?'high':'action','readiness',
                $label.' · preflight '.client_release_command_center_state_label_v180($row['readiness_state']),
                $row['readiness_reason']!==''?$row['readiness_reason']:'Release readiness needs operator review.',
                '/admin/homeserver.php#release-readiness',$row
            );
        }
        if(in_array($row['risk_level'],['high','critical'],true)){
            $add($row['risk_level']==='critical'?'high':'action','risk',
                $label.' · '.$row['risk_level'].' risk',
                'v1.40 risk score '.$row['risk_score'].' requires conservative rollout controls or explicit review.',
                '/admin/homeserver.php#release-risk',$row
            );
        }
        if($row['proposal_id']>0&&in_array($row['proposal_status'],['pending','deferred'],true)){
            $add('action','automation',
                $label.' · automation decision',
                client_release_command_center_state_label_v180($row['proposal_type']).' proposal #'.$row['proposal_id'].' is awaiting operator action.',
                '/admin/homeserver.php#release-automation',$row
            );
        }
    }

    foreach((array)($automation['holds']??[]) as $hold){
        $add('high','automation_hold',
            client_release_command_center_product_label_v180((string)$hold['product']).' automation hold',
            (string)($hold['hold_reason']??'Automation progression is held.'),
            '/admin/homeserver.php#release-automation',
            ['product'=>(string)$hold['product'],'release_id'=>(int)($hold['release_id']??0),'campaign_id'=>(int)($hold['campaign_id']??0)]
        );
    }

    foreach(['browser_companion','homeserver'] as $product){
        $counts=(array)($fleetSummary[$product]??[]);
        $unsupported=(int)($counts['unsupported']??0);
        $drift=(int)($counts['drift']??0);
        $stale=(int)($counts['stale']??0);
        if($unsupported>0)$add('high','fleet',
            client_release_command_center_product_label_v180($product).' unsupported clients',
            $unsupported.' client'.($unsupported===1?' is':'s are').' outside current support policy.',
            '/admin/homeserver.php#fleet-maintenance',['product'=>$product]
        );
        if($drift>0)$add('action','fleet',
            client_release_command_center_product_label_v180($product).' compatibility drift',
            $drift.' client'.($drift===1?' has':'s have').' cross-client compatibility drift.',
            '/admin/homeserver.php#fleet-maintenance',['product'=>$product]
        );
        if($stale>0)$add('watch','fleet',
            client_release_command_center_product_label_v180($product).' stale telemetry',
            $stale.' client'.($stale===1?' has':'s have').' exceeded the configured stale-client threshold.',
            '/admin/homeserver.php#fleet-maintenance',['product'=>$product]
        );
    }

    $rank=['critical'=>4,'high'=>3,'action'=>2,'watch'=>1];
    usort($items,static fn($a,$b)=>(($rank[$b['severity']]??0)<=>($rank[$a['severity']]??0)));
    return array_slice($items,0,40);
}

function client_release_command_center_operational_status_v180(array $attention,array $incidents): array
{
    if($incidents)return ['key'=>'incident','label'=>'Incident active','detail'=>'v1.30 recovery controls own one or more releases.'];
    $critical=count(array_filter($attention,static fn($item)=>(string)$item['severity']==='critical'));
    if($critical>0)return ['key'=>'critical','label'=>'Critical attention','detail'=>$critical.' critical release-operations item'.($critical===1?' requires':'s require').' review.'];
    $high=count(array_filter($attention,static fn($item)=>(string)$item['severity']==='high'));
    if($high>0)return ['key'=>'action_required','label'=>'Action required','detail'=>$high.' high-priority release-operations item'.($high===1?' is':'s are').' open.'];
    $action=count(array_filter($attention,static fn($item)=>(string)$item['severity']==='action'));
    if($action>0)return ['key'=>'attention','label'=>'Operator attention','detail'=>$action.' release-operations decision'.($action===1?' is':'s are').' waiting.'];
    $watch=count(array_filter($attention,static fn($item)=>(string)$item['severity']==='watch'));
    if($watch>0)return ['key'=>'watch','label'=>'Watch','detail'=>$watch.' non-blocking fleet/release condition'.($watch===1?' is':'s are').' being watched.'];
    return ['key'=>'normal','label'=>'Operating normally','detail'=>'No active release-operation blockers are visible.'];
}

function client_release_command_center_metrics_v180(
    array $intel,
    array $attention,
    array $incidents,
    array $fleetSummary,
    array $automation
): array {
    $tracked=0;
    foreach(['browser_companion','homeserver'] as $product){
        $row=(array)($intel[$product]??[]);
        $tracked+=(int)($row['active_clients']??$row['paired_clients']??0);
    }
    $unsupported=0;$stale=0;$drift=0;
    foreach(['browser_companion','homeserver'] as $product){
        $counts=(array)($fleetSummary[$product]??[]);
        $unsupported+=(int)($counts['unsupported']??0);
        $stale+=(int)($counts['stale']??0);
        $drift+=(int)($counts['drift']??0);
    }
    return [
        'tracked_clients'=>$tracked,
        'active_incidents'=>count($incidents),
        'attention_items'=>count($attention),
        'pending_automation'=>count((array)($automation['pending']??[])),
        'automation_holds'=>count((array)($automation['holds']??[])),
        'unsupported_clients'=>$unsupported,
        'stale_clients'=>$stale,
        'compatibility_drift'=>$drift,
    ];
}

function client_release_command_center_build_v180(PDO $pdo): array
{
    $intel=function_exists('client_release_intelligence_admin_summary_v100')
        ?client_release_intelligence_admin_summary_v100($pdo)
        :['browser_companion'=>[],'homeserver'=>[]];
    $health=client_release_health_admin_summary_v120($pdo);
    $risk=client_release_risk_admin_summary_v140($pdo);
    $readiness=client_release_readiness_admin_summary_v150($pdo);
    $incidents=client_release_command_center_active_incidents_v180($pdo);
    $fleet=client_fleet_summary_v160($pdo);
    $automation=client_release_automation_admin_summary_v170($pdo);
    $releaseRows=client_release_command_center_release_rows_v180($pdo,$health,$risk,$readiness,$incidents,$automation);
    $attention=client_release_command_center_attention_v180(
        $releaseRows,$incidents,(array)($fleet['summary']??[]),$automation
    );
    return [
        'status'=>client_release_command_center_operational_status_v180($attention,$incidents),
        'metrics'=>client_release_command_center_metrics_v180($intel,$attention,$incidents,(array)($fleet['summary']??[]),$automation),
        'intelligence'=>$intel,
        'releases'=>$releaseRows,
        'attention'=>$attention,
        'incidents'=>$incidents,
        'fleet'=>$fleet,
        'automation'=>$automation,
        'audit'=>client_release_audit_recent_v110($pdo,40),
        'generated_at'=>gmdate('Y-m-d H:i:s').' UTC',
    ];
}
