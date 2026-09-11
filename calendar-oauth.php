<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_permission('account.access');

$pdo=db();$user=current_user();
if(!$pdo||!$user)redirect(url('/login.php'));
if(!agent_calendar_sync_schema_ready_v500($pdo))redirect(url('/upgrade.php'));

function calendar_oauth_redirect_v500(int $scheduleId,string $saved='',string $error=''): never
{
    $query=['schedule'=>max(0,$scheduleId)];
    if($saved!=='')$query['saved']=$saved;
    if($error!=='')$query['calendar_error']=$error;
    redirect(url('/scheduling.php?'.http_build_query($query,'','&',PHP_QUERY_RFC3986).'#calendars'));
}

try{
    $action=trim((string)($_GET['action']??''));
    if($action==='start'){
        $provider=strtolower(trim((string)($_GET['provider']??'')));
        $scheduleId=max(0,(int)($_GET['schedule']??0));
        if(!in_array($provider,['google','microsoft'],true))throw new RuntimeException('Choose Google Calendar or Microsoft Outlook.');
        if($scheduleId<1||!agent_scheduling_schedule_v430($pdo,(int)$user['id'],$scheduleId))throw new RuntimeException('Schedule not found.');
        redirect(agent_calendar_sync_oauth_url_v500($user,$provider,$scheduleId));
    }

    $stateToken=trim((string)($_GET['state']??''));
    if($stateToken==='')throw new RuntimeException('Calendar connection state is missing.');
    $state=agent_calendar_sync_oauth_state_take_v500($user,$stateToken);
    $provider=(string)$state['provider'];$scheduleId=(int)$state['schedule_id'];
    if(!empty($_GET['error']))throw new RuntimeException('Calendar connection was not approved: '.mb_strimwidth((string)($_GET['error_description']??$_GET['error']),0,500,'…'));
    $code=trim((string)($_GET['code']??''));if($code==='')throw new RuntimeException('Calendar provider did not return an authorization code.');
    $token=agent_calendar_sync_exchange_code_v500($provider,$code);
    $account=agent_calendar_sync_account_v500($pdo,$provider,$token);
    $connection=agent_calendar_sync_store_connection_v500($pdo,$user,$provider,$token,$account,$scheduleId);
    $sync=agent_calendar_sync_now_v500($pdo,$connection,true);
    agent_tool_log($user,'calendar.connect',$provider,!empty($sync['ok'])?'success':'connected_with_sync_error',['connection_id'=>(int)$connection['id'],'schedule_id'=>$scheduleId,'provider'=>$provider]);
    $message=agent_calendar_sync_provider_label_v500($provider).' connected.'.(!empty($sync['ok'])?' Busy time and VP3 bookings are synchronized.':' Connection saved; sync will retry automatically.');
    calendar_oauth_redirect_v500($scheduleId,$message);
}catch(Throwable $e){
    $scheduleId=max(0,(int)($_GET['schedule']??0));
    if(isset($state)&&is_array($state))$scheduleId=(int)($state['schedule_id']??$scheduleId);
    calendar_oauth_redirect_v500($scheduleId,'',mb_strimwidth($e->getMessage(),0,700,'…'));
}
