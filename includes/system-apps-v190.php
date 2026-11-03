<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v180.php';

const VP3_SYSTEM_APPS_V190='system-app-agent-action-ux-v190-20260930';

function vp3_system_apps_action_card_v190(?array $plan): ?array
{
    if(!$plan)return null;
    $status='prepared';
    if(!empty($plan['completed']))$status=($plan['result']['verified']??null)===true&&empty($plan['result']['reconcile_pending'])&&empty($plan['result']['rolled_back'])?'completed':'failed';
    elseif((string)($plan['status']??'')==='failed'||!empty($plan['error']))$status='failed';

    $type=(string)($plan['action_type']??'');
    $appKey=(string)($plan['app_key']??'');
    $preview=is_array($plan['preview']??null)?$plan['preview']:[];
    $result=is_array($plan['result']??null)?$plan['result']:[];
    $snapshot=is_array($result['system_apps']??null)?$result['system_apps']:[];
    $reconcilePending=!empty($result['reconcile_pending']);
    $vrssRecorded=$type==='vrss.invoke'&&!empty($plan['completed']);
    $outcomeLabel='';
    if($vrssRecorded){
        $result=vp3_vrss_saved_outcome_v1($result);
        $status=$result['verified']?'recorded':'failed';
        $outcomeLabel=vp3_vrss_outcome_label_v1($result['vrss_outcome']??null);
    }

    $labels=[
      'ownership.acquire'=>'Add app',
      'install'=>'Install app',
      'update.verify'=>'Verified update',
      'release.rollback'=>'Rollback release',
      'permission.set'=>'Change permission',
      'hosting.bind'=>'Assign Hosting',
      'hosting.unbind'=>'Remove Hosting',
      'reconcile'=>'Refresh apps',
      'vrss.invoke'=>'VRSS Feed action',
    ];
    $title=$labels[$type]??'System App action';
    $appName=trim((string)($preview['app']??''));
    if($appName==='')$appName=$appKey!==''?$appKey:'System Apps';

    $summary='';
    if($vrssRecorded){
        $summary=vp3_vrss_action_text_v1($result,!empty($plan['idempotent_replay']));
    }elseif($status==='prepared'){
        $summary='Review this action, then confirm it. Nothing changes until you confirm.';
    }elseif($status==='completed'){
        $summary=$reconcilePending
          ?'The Cloud action was processed; reconciliation is still pending and the final outcome is unverified.'
          :'The action completed successfully.';
    }else{
        $summary='The requested final outcome was not verified. Effects may already exist; inspect Apps before preparing another request.';
        if(!empty($result['rolled_back']))$summary.=' The update rolled back; the requested new release is not active.';
    }

    $details=[];
    foreach([
      'App'=>$appName,
      'Target'=>$preview['target']??null,
      'Hosting site'=>$preview['hosting_site']??null,
      'Hostname'=>$preview['hostname']??null,
      'Operation'=>$preview['operation']??null,
      'Version'=>$preview['catalog_version']??$preview['to_version']??null,
      'Previous release'=>$preview['previous_release_id']??null,
      'Site ID'=>$preview['site_id']??null,
      'Channel'=>$preview['release_channel']??null,
      'Permission'=>$preview['permission']??null,
      'Risk'=>$preview['risk']??null,
      'New state'=>$preview['new_state']??(array_key_exists('allowed',$preview)?($preview['allowed']?'allowed':'denied'):null),
      'Scope'=>$preview['scope']??null,
    ] as $label=>$value){
        if($value!==null&&trim((string)$value)!=='')$details[]=['label'=>$label,'value'=>(string)$value];
    }

    $actions=[];
    $code=strtoupper(trim((string)($plan['confirmation_code']??'')));
    if($status==='prepared'&&preg_match('/^[A-Z2-9]{8}$/',$code)){
        $actions[]=[
          'type'=>'prompt',
          'label'=>'Confirm',
          'prompt'=>'confirm app '.$code,
          'kind'=>'primary',
        ];
        $actions[]=[
          'type'=>'open_url',
          'label'=>'Review Apps',
          'url'=>url('/apps.php'),
          'kind'=>'secondary',
        ];
    }elseif(in_array($status,['completed','recorded'],true)){
        $actions[]=['type'=>'open_url','label'=>'Open Apps','url'=>url('/apps.php'),'kind'=>'primary'];
        if(in_array($type,['hosting.bind','hosting.unbind'],true)){
            $actions[]=['type'=>'open_url','label'=>'Manage Hosting','url'=>url('/hosting.php'),'kind'=>'secondary'];
        }
        if($snapshot){
            foreach((array)($snapshot['apps']??[]) as $app){
                if($appKey!==''&&(string)($app['app_key']??'')!==$appKey)continue;
                $publicUrl=trim((string)($app['public_url']??''));
                if($publicUrl!=='')$actions[]=[
                  'type'=>'open_url','label'=>'Open hosted app','url'=>$publicUrl,
                  'system_app_key'=>(string)($app['app_key']??$appKey),'kind'=>'secondary'
                ];
                if($appKey!=='')break;
            }
        }
    }else{
        $actions[]=['type'=>'open_url','label'=>'Open Apps','url'=>url('/apps.php'),'kind'=>'secondary'];
    }

    return [
      'contract'=>'vp3.system-app-action-card.v1',
      'status'=>$status,
      'outcome_label'=>$outcomeLabel,
      'title'=>$title,
      'summary'=>$summary,
      'action_type'=>$type,
      'action_id'=>(string)($plan['action_id']??''),
      'app_key'=>$appKey,
      'details'=>$details,
      'confirmation_code'=>$status==='prepared'?$code:'',
      'expires_in_seconds'=>(int)($plan['expires_in_seconds']??0),
      'reconcile_pending'=>$reconcilePending,
      'idempotent_replay'=>!empty($plan['idempotent_replay']),
      'error'=>mb_substr((string)($plan['error']??''),0,500),
      'actions'=>$actions,
    ];
}

function vp3_system_apps_capability_v190(): array
{
    return array_replace(vp3_system_apps_capability_v180(),[
      'agent_action_ux_contract'=>'vp3.system-app-action-card.v1',
      'persistent_action_cards'=>true,
      'button_confirmation'=>true,
      'action_progress_states'=>true,
      'reconcile_pending_ux'=>true,
      'post_action_navigation'=>true,
      'typed_confirmation_fallback'=>true,
    ]);
}
