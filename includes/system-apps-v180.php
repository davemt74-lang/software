<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v170.php';

const VP3_SYSTEM_APPS_V180='system-app-proactive-operations-v180-20260930';

function vp3_system_apps_proactive_candidate_v180(
    string $appKey,string $title,string $reason,string $prompt,string $source,
    string $url,float $confidence,float $recency
): array {
    return [
      'hash'=>sha1('system-apps-v180|'.$appKey.'|'.$source.'|'.$title),
      'key'=>'system-app:'.$appKey.':'.$source,
      'title'=>$title,
      'prompt'=>$prompt,
      'reason'=>$reason,
      'source'=>$source,
      'url'=>$url,
      '_confidence'=>max(0.2,min(1.0,$confidence)),
      '_recency'=>max(0.15,min(1.0,$recency)),
      '_occurred_at'=>gmdate('Y-m-d H:i:s'),
    ];
}

function vp3_system_apps_proactive_candidates_v180(PDO $pdo,array $user): array
{
    $uid=(int)($user['id']??0);if($uid<1)return [];
    $health=vp3_system_apps_health_snapshot_v170($user,$pdo);
    $out=[];$offlineAdded=false;
    foreach((array)($health['apps']??[]) as $app){
        $key=(string)$app['app_key'];$name=(string)$app['name'];
        $state=(string)($app['health_state']??'ready');
        $issues=array_values(array_filter(array_map('strval',(array)($app['health_issues']??[]))));
        $appsUrl=url('/apps.php');

        if($state==='issue'){
            $out[]=vp3_system_apps_proactive_candidate_v180(
              $key,
              'Diagnose '.$name,
              $issues?implode(' ',array_slice($issues,0,2)):$name.' needs attention.',
              'Diagnose '.$name.' using its canonical System App and Hosting state, explain the problem, and prepare the safest next action if a change is needed.',
              'system_apps_health',$appsUrl,0.98,0.98
            );
            continue;
        }

        if(!empty($app['update_available'])){
            $out[]=vp3_system_apps_proactive_candidate_v180(
              $key,
              'Update '.$name,
              $name.' has a newer System App version available for its HomeServer installation.',
              'Review the available update for '.$name.' and, if it is appropriate, prepare the governed System App update/verification action for my confirmation.',
              'system_apps_update',$appsUrl,0.94,0.96
            );
        }

        if($state==='offline'&&!$offlineAdded){
            $offlineAdded=true;
            $out[]=vp3_system_apps_proactive_candidate_v180(
              'homeserver',
              'Restore System App visibility',
              'HomeServer is offline, so installed System App runtime health cannot be verified.',
              'Check my HomeServer connection and System App reconciliation state, then help me restore visibility without changing permissions automatically.',
              'system_apps_connection',$appsUrl,0.96,0.96
            );
        }

        if($state==='inactive'&&!empty($app['installed'])){
            $out[]=vp3_system_apps_proactive_candidate_v180(
              $key,
              'Review inactive '.$name,
              $name.' is installed on HomeServer but is not currently running.',
              'Review why '.$name.' is installed but not running. Diagnose first and prepare a safe next action only if one is needed.',
              'system_apps_runtime',$appsUrl,0.86,0.84
            );
        }

        $hosting=(array)($app['hosting_health']??[]);
        if(!empty($app['hosted'])&&$hosting){
            $hostingIssues=[];
            if((string)($hosting['desired_state']??'')==='active'&&(string)($hosting['observed_state']??'')!=='active')$hostingIssues[]='runtime state is not active';
            if(!in_array((string)($hosting['route_state']??''),['active','ready'],true))$hostingIssues[]='public route is not active';
            if(!in_array((string)($hosting['tls_state']??''),['active','renewing'],true))$hostingIssues[]='TLS is not active';
            if($hostingIssues){
                $out[]=vp3_system_apps_proactive_candidate_v180(
                  $key,
                  'Review Hosting for '.$name,
                  $name.' Hosting needs attention: '.implode(', ',$hostingIssues).'.',
                  'Diagnose the Hosting state for '.$name.', explain the route/TLS/runtime mismatch, and prepare a reconciliation action only if it is needed.',
                  'system_apps_hosting',url('/hosting.php'),0.95,0.94
                );
            }
        }
    }
    return $out;
}

function vp3_system_apps_activity_presentation_v180(array $row,array $meta=[]): array
{
    $type=(string)($row['event_type']??'app.updated');
    $name=(string)($row['app_name']??'System App');
    $title='System App updated';$body=$name.' changed state.';$target=url('/apps.php');

    if($type==='app.update.available'){
        $title='App update available';
        $body=$name.' has an update available. Open Apps to review it, or ask Agent Chat to prepare the governed update action.';
    }elseif($type==='app.update.cleared'){
        $title='App is current';
        $body=$name.' is current again.';
    }elseif($type==='app.health.problem'){
        $title='App needs attention';
        $body=$name.' reported a runtime or reconciliation problem.';
        if(!empty($meta['error']))$body.=' '.mb_substr((string)$meta['error'],0,220);
        $body.=' Open Apps for status or ask Agent Chat to diagnose it.';
    }elseif($type==='app.health.recovered'){
        $title='App recovered';
        $body=$name.' recovered from its previous problem.';
    }elseif($type==='app.runtime.state_changed'){
        $title='App runtime changed';
        $from=trim((string)($meta['from']??''));$to=trim((string)($meta['to']??''));
        $body=$name.' runtime changed'.($from!==''&&$to!==''?' from '.$from.' to '.$to:'').'.';
    }elseif($type==='app.install.failed'){
        $title='App installation needs attention';
        $body=$name.' could not be installed on HomeServer.';
        if(!empty($meta['error']))$body.=' '.mb_substr((string)$meta['error'],0,220);
        $body.=' Ask Agent Chat to diagnose the failure before retrying.';
    }elseif($type==='app.install.completed'){
        $title='App installed';$body=$name.' is installed on HomeServer.';
    }elseif($type==='app.hosting.bound'){
        $title='App Hosting assigned';$body=$name.' was assigned to a Hosting site. Open Hosting to review its public route.';$target=url('/hosting.php');
    }elseif($type==='app.hosting.unbound'){
        $title='App Hosting removed';$body=$name.' was removed from its Hosting site.';$target=url('/hosting.php');
    }elseif($type==='app.ownership.acquired'){
        $title='App added';$body=$name.' was added to your VP3 Apps.';
    }elseif($type==='app.ownership.revoked'){
        $title='App access removed';$body=$name.' ownership was revoked.';
    }elseif($type==='app.install.requested'){
        $title='App installation requested';$body=$name.' installation was requested.';
    }elseif($type==='app.runtime.deactivated'){
        $title='App deactivated';$body=$name.' runtime was stopped on HomeServer.';
    }

    return ['title'=>$title,'body'=>$body,'target_url'=>$target];
}

function vp3_system_apps_capability_v180(): array
{
    return array_replace(vp3_system_apps_capability_v170(),[
      'proactive_contract'=>'vp3.system-app-proactive.v1',
      'cognitive_proactive_candidates'=>true,
      'proactive_health_recommendations'=>true,
      'proactive_update_recommendations'=>true,
      'proactive_hosting_recommendations'=>true,
      'activity_action_guidance'=>true,
      'proactive_auto_execution'=>false,
      'proactive_consequential_actions_require_confirmation'=>true,
    ]);
}
