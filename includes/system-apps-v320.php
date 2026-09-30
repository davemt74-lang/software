<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v310.php';

const VP3_SYSTEM_APPS_V320='vp3-download-manager-v320-20260930';

function vp3_download_manager_status_v320(int $userId,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_app_control_invoke_v290($userId,'vp3.download-manager','downloads.status',[],false,$remote);
    $result=is_array($payload['result']??null)?$payload['result']:$payload;
    return [
      'contract'=>'vp3.download-manager-cloud.v1',
      'active'=>(int)($result['active']??0),
      'queued'=>(int)($result['queued']??0),
      'paused'=>(int)($result['paused']??0),
      'failed'=>(int)($result['failed']??0),
      'completed'=>(int)($result['completed']??0),
      'bytes_downloaded'=>(int)($result['bytes_downloaded']??0),
      'destinations'=>(int)($result['destinations']??0),
      'homeserver_execution_authority'=>true,
      'cloud_execution_authority'=>false,
    ];
}

function vp3_download_manager_list_v320(int $userId,string $status='',int $limit=20,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_app_control_invoke_v290($userId,'vp3.download-manager','downloads.list',[
      'status'=>mb_substr(trim($status),0,40),
      'limit'=>max(1,min(100,$limit)),
    ],false,$remote);
    $result=is_array($payload['result']??null)?$payload['result']:$payload;
    $items=[];
    foreach((array)($result['downloads']??[]) as $row){
        if(!is_array($row))continue;
        $items[]=[
          'download_id'=>(string)($row['download_id']??''),
          'display_url'=>(string)($row['display_url']??''),
          'status'=>(string)($row['status']??''),
          'progress'=>isset($row['progress'])?(float)$row['progress']:null,
          'eta_seconds'=>isset($row['eta_seconds'])?(int)$row['eta_seconds']:null,
          'final_filename'=>(string)($row['final_filename']??''),
          'error'=>(string)($row['error']??''),
        ];
    }
    return [
      'contract'=>'vp3.download-list-cloud.v1',
      'downloads'=>$items,
      'count'=>count($items),
      'homeserver_execution_authority'=>true,
      'source_urls_exposed'=>false,
      'filesystem_paths_exposed'=>false,
    ];
}

function vp3_download_manager_brain_context_v320(int $userId,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_app_control_invoke_v290($userId,'vp3.download-manager','downloads.brain-context',[
      'limit'=>8,
    ],false,$remote);
    $result=is_array($payload['result']??null)?$payload['result']:$payload;
    return [
      'contract'=>'vp3.download-manager.brain-cloud.v1',
      'summary'=>is_array($result['summary']??null)?$result['summary']:[],
      'attention'=>array_values(array_filter((array)($result['attention']??[]),'is_array')),
      'recent'=>array_values(array_filter((array)($result['recent']??[]),'is_array')),
      'source_urls_exposed'=>false,
      'filesystem_paths_exposed'=>false,
      'homeserver_execution_authority'=>true,
    ];
}

function vp3_download_manager_agent_query_v320(
    string $query,array $user,int $conversationId=0,?callable $remote=null
): array {
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!preg_match('/\b(?:download manager|downloads?|download queue|downloading)\b/i',$query))return $empty;
    $uid=(int)($user['id']??0);if($uid<1)return $empty;
    try{
        $status=vp3_download_manager_status_v320($uid,$remote);
        $context=vp3_download_manager_brain_context_v320($uid,$remote);
        $list=vp3_download_manager_list_v320($uid,'',8,$remote);
    }catch(Throwable $e){return $empty;}
    $answer='Download Manager has '.$status['active'].' active, '.$status['queued'].' queued, '
      .$status['paused'].' paused, '.$status['failed'].' failed, and '.$status['completed'].' completed download'
      .($status['completed']===1?'':'s').'. ';
    if(!empty($context['attention'])){
        $answer.='There '.(count($context['attention'])===1?'is ':'are ').count($context['attention'])
          .' download'.(count($context['attention'])===1?'':'s').' needing attention. ';
    }
    if($list['count']>0){
        $first=$list['downloads'][0];
        $label=$first['final_filename']!==''?$first['final_filename']:$first['display_url'];
        $answer.='Most recent: '.$label.' — '.$first['status'].'.';
    }
    if(preg_match('/\b(?:download|add|start|pause|resume|cancel|retry|priority|destination|bandwidth|limit|schedule)\b/i',$query)){
        $answer.=' Changes are governed by the HomeServer Agent control/approval path; Cloud does not execute downloads directly.';
    }
    if(function_exists('agent_tool_log'))agent_tool_log($user,'homeserver.downloads.read',$query,'success',[
      'active'=>$status['active'],'queued'=>$status['queued'],'failed'=>$status['failed']
    ],$conversationId);
    return [
      'handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],
      'actions'=>[['type'=>'open_url','label'=>'Open Download Manager','url'=>url('/apps.php')]],
      'sources'=>[['source'=>'homeserver:download-manager','title'=>'VP3 Download Manager']],
      'download_manager'=>$status,'download_brain_context'=>$context,'download_list'=>$list,
    ];
}

function vp3_system_apps_capability_v320(): array
{
    return array_replace(vp3_system_apps_capability_v310(),[
      'download_manager_contract'=>'vp3.download-manager-cloud.v1',
      'download_manager_status'=>true,
      'download_manager_list'=>true,
      'download_manager_agent_chat'=>true,
      'download_manager_agent_brain_context'=>true,
      'download_manager_cloud_execution'=>false,
      'download_manager_homeserver_execution'=>true,
      'download_manager_source_urls_exposed'=>false,
      'download_manager_filesystem_paths_exposed'=>false,
    ]);
}
