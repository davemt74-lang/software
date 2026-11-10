<?php
declare(strict_types=1);

/** Only existing server executors issue these receipts. Text and model plans are never receipts. */
function vp3_tool_receipt_v1(string $capability,string $operation,string $status,string $reason='',int $recordId=0,int $count=0): array
{
    return vp3_tool_receipt_normalize_v1(['contract'=>'vp3.tool-receipt.v1','capability'=>$capability,'operation'=>$operation,
        'status'=>$status,'reason'=>$reason,'record_id'=>$recordId,'count'=>$count,'checked_at'=>gmdate('Y-m-d\TH:i:s\Z')])??throw new InvalidArgumentException('Invalid server tool receipt.');
}

function vp3_tool_receipt_normalize_v1(mixed $value): ?array
{
    if(!is_array($value)||($value['contract']??'')!=='vp3.tool-receipt.v1')return null;
    $ops=[
        'system_apps.manage'=>['prepare','confirm','navigate'],
        'system_apps.release.read'=>['inspect'],
        'apps.control.read'=>['inspect'],
        'system_apps.catalog.read'=>['list'],
        'system_apps.health.read'=>['list'],
        'system_apps.permissions.read'=>['inspect'],
        'apps.user.read'=>['list'],
        'apps.shares.read'=>['list'],
        'feeds.vrss.read'=>['list'],
        'tracky.context.read'=>['list'],
        'live.context.read'=>['read'],
        'tracky.perception.request'=>['request'],
        'radar.read'=>['list'],
        'radar.gateway.manage'=>['set_policy'],
        'analytics.read'=>['list'],
        'music.releases.read'=>['list'],
        'music.releases.create'=>['create'],
        'profile.webmcp.read'=>['inspect'],
        'profile.webmcp.handoff'=>['prepare'],
        'profile.webmcp.return.read'=>['read'],
        'apps.manager.read'=>['list'],
        'apps.workspace.read'=>['inspect'],
        'tv.history.read'=>['list'],
        'tv.preferences.read'=>['list'],
        'tv.capture.manage'=>['prepare'],
        'media.library.read'=>['status','search'],
        'music.library.read'=>['status','search'],
        'photos.library.read'=>['status','search'],
        'media.assets.read'=>['search'],
        'images.generate'=>['queue'],
        'downloads.jobs.read'=>['status','list'],
        'media.processing.read'=>['status','list'],
        'video.projects.read'=>['status','list'],
        'knowledge.search'=>['search'],
        'brain.read'=>['search'],
        'participants.setup'=>['prepare','confirm','cancel'],
        'participants.read'=>['presence'],
        'tasks.overview'=>['read'],
        'brief.attention.read'=>['read'],
        'voice.profiles.enroll'=>['prepare'],
        'navigation.open'=>['open','prepare'],
        'tasks.read'=>['list'],
        'tasks.lists'=>['show','show_lists','prepare','add','create_list','create_task','confirm','cancel'],
        'tasks.manage'=>['set_status','set_public_research'],
        'homeserver.read'=>['files','file_read','knowledge','tools','devices'],
        'workflows.read'=>['inspect'],
        'workflows.manage'=>['approve','pause','pause_request','resume','cancel','retry','reschedule','priority'],
        'scheduling.read'=>['list','availability'],
        'scheduling.manage'=>['prepare','create','reschedule','cancel','hold_for_payment'],
        'team_scheduling.read'=>['list','availability'],
        'team_scheduling.manage'=>['prepare','create','cancel'],
        'phone.numbers.read'=>['list'],
        'phone.sms.read'=>['list','read'],
        'email.read'=>['search','read','review_status'],
        'email.send'=>['prepare','send'],
        'email.followups'=>['watch_sender','stop_sender_watch'],
        'youtube.search'=>['search'],
        'youtube.history'=>['read'],
        'artist.research'=>['search','prepare'],
        'topic.research'=>['search','prepare'],
        'youtube.results'=>['read'],
        'transcripts.highlight'=>['highlight','review_item','item_action'],
        'transcripts.analyze'=>['run_analysis','run_research'],
        'transcripts.read'=>['list','read','search','clarify','interpret','read_analysis','read_research','attach','detach','summarize','discuss','continue'],
        'capabilities.inspect'=>['inspect'],
        'reminders.manage'=>['clarify','create','list','update','complete','snooze','dismiss','cancel','delete','interpret'],
        'calendar.read'=>['list'],
        'calendar.manage'=>['create','update','cancel','prepare'],
    ];
    $cap=$value['capability']??null;$op=$value['operation']??null;$status=$value['status']??null;
    if(!is_string($cap)||!isset($ops[$cap])||!in_array($op,$ops[$cap],true)||!in_array($status,['read_complete','no_matches','completed','clarification_required','blocked','failed'],true))return null;
    $reason=$value['reason']??'';
    if(!in_array($reason,['','upgrade_required','provider_unavailable','invalid_plan','execution_failed','permission_denied','confirmation_required','cancelled_by_user','subscription_required','outcome_unverified','setup_required','scope_restricted','check_failed','not_installed'],true))return null;
    $at=$value['checked_at']??null;
    if(!is_string($at)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$at)||strtotime($at)===false)return null;
    foreach(['record_id','count'] as $key)if(!is_int($value[$key]??null)||$value[$key]<0)return null;
    // A read, clarification or failed operation can never be a successful mutation.
    $write=$cap==='email.followups'
        ||($cap==='email.send'&&$op==='send')||($cap==='music.releases.create'&&$op==='create')||($cap==='tracky.perception.request'&&$op==='request')||($cap==='radar.gateway.manage'&&$op==='set_policy')||($cap==='images.generate'&&$op==='queue')||($cap==='participants.setup'&&$op==='confirm')||($cap==='tasks.lists'&&in_array($op,['add','create_list','create_task','confirm'],true))||($cap==='system_apps.manage'&&$op==='confirm')||($cap==='tasks.manage')||($cap==='workflows.manage')||(in_array($cap,['scheduling.manage','team_scheduling.manage'],true)&&$op!=='prepare')||($cap==='transcripts.highlight'&&in_array($op,['highlight','review_item','item_action'],true))||($cap==='transcripts.analyze'&&in_array($op,['run_analysis','run_research'],true))||($cap==='reminders.manage'&&in_array($op,['create','update','complete','snooze','dismiss','cancel','delete'],true))||($cap==='calendar.manage'&&in_array($op,['create','update','cancel'],true));
    if($status==='completed'&&(!$write||$reason!==''))return null;
    if(in_array($status,['read_complete','no_matches'],true)&&$reason!=='')return null;
    $extra=[];
    if(array_key_exists('vrss_outcome',$value)){
        require_once __DIR__.'/agent-vrss-outcomes-v1.php';
        $summary=vp3_vrss_outcome_summary_v1($value['vrss_outcome']);
        if($cap!=='system_apps.manage'||$op!=='confirm'||!in_array($status,['completed','read_complete'],true)||$summary===null)return null;
        $extra['vrss_outcome']=$summary;
    }
    if(array_key_exists('profile_webmcp_outcome',$value)){
        require_once __DIR__.'/agent-profile-webmcp-outcomes-v1.php';
        if($cap!=='profile.webmcp.return.read'||$op!=='read'||!is_array($value['profile_webmcp_outcome']))return null;
        try{$summary=vp3_profile_webmcp_outcome_projection_v1($value['profile_webmcp_outcome']);}catch(Throwable $e){return null;}
        if(!(($status==='read_complete'&&$reason===''&&$summary['verification']==='verified')||($status==='failed'&&$reason==='outcome_unverified'&&$summary['verification']==='unverified')))return null;
        $extra['profile_webmcp_outcome']=$summary;
    }
    return ['contract'=>'vp3.tool-receipt.v1','capability'=>$cap,'operation'=>$op,'status'=>$status,'reason'=>$reason,
        'record_id'=>$value['record_id'],'count'=>$value['count'],'checked_at'=>$at,'changes_applied'=>$status==='completed'&&$write,...$extra];
}

function vp3_tool_last_receipt_v1(PDO $pdo,array $user,array $context): ?array
{
    $cid=max(0,(int)($context['conversation_id']??0));$uid=(int)($user['id']??0);$aid=max(0,(int)($context['user_agent_id']??0));
    if($cid<1||$uid<1)return null;
    $q=$pdo->prepare("SELECT m.id,m.context_json FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id WHERE c.id=? AND c.user_id=? AND ".($aid>0?'c.user_agent_id=?':'c.user_agent_id IS NULL')." AND m.role='assistant' ORDER BY m.id DESC LIMIT 20");
    $q->execute($aid>0?[$cid,$uid,$aid]:[$cid,$uid]);
    while($row=$q->fetch(PDO::FETCH_ASSOC)){
        $data=json_decode((string)$row['context_json'],true,32);
        if(($data['source']??'')!=='agent_initiative')break;
    }
    if(!$row)return null;
    $pending=$pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE conversation_id=? AND role='user' AND id>?");$pending->execute([$cid,(int)$row['id']]);
    if((int)$pending->fetchColumn()>1)return null;
    $receipt=vp3_tool_receipt_normalize_v1($data['tool_receipt']??null);
    if($receipt&&$receipt['capability']==='profile.webmcp.return.read'&&$receipt['status']==='read_complete'&&!isset($receipt['profile_webmcp_outcome'])){
        $receipt['status']='failed';$receipt['reason']='outcome_unverified';$receipt['count']=0;
        $receipt=vp3_tool_receipt_normalize_v1($receipt);
    }
    // Historical generic VRSS success cannot regain a final-completion claim through diagnosis.
    if($receipt&&($data['system_app_plan']['action_type']??null)==='vrss.invoke'&&$receipt['capability']==='system_apps.manage'&&$receipt['operation']==='confirm'){
        require_once __DIR__.'/agent-vrss-outcomes-v1.php';
        $safe=vp3_vrss_saved_outcome_v1($data['system_app_plan']['result']??null);
        if(!$safe['verified']||!in_array($receipt['status'],['completed','read_complete'],true)||
            (isset($receipt['vrss_outcome'])&&$receipt['vrss_outcome']!==$safe['vrss_outcome'])){
            unset($receipt['vrss_outcome']);$receipt['status']='failed';$receipt['reason']='outcome_unverified';
        }else $receipt['vrss_outcome']=$safe['vrss_outcome'];
        $receipt=vp3_tool_receipt_normalize_v1($receipt);
    }
    return $receipt?['message_id'=>(int)$row['id'],'receipt'=>$receipt]:null;
}

function vp3_tool_diagnosis_v1(?array $last): string
{
    if(($last['receipt']['capability']??'')==='profile.webmcp.return.read'&&($last['receipt']['status']??'')==='read_complete'&&!isset($last['receipt']['profile_webmcp_outcome']))return 'The preceding Profile return has no verified outcome evidence. Check the Profile action before repeating it.';
    if(array_key_exists('profile_webmcp_outcome',$last['receipt']??[])){
        $r=vp3_tool_receipt_normalize_v1($last['receipt']);
        if(!$r)return 'The preceding Profile outcome receipt could not be verified. Check the Profile action before repeating it.';
        return vp3_profile_webmcp_outcome_text_v1($r['profile_webmcp_outcome']).' Recorded at '.$r['checked_at'].'. This is the saved result of that turn; current state may have changed.';
    }
    if(($last['receipt']['capability']??'')==='music.releases.create'&&in_array($last['receipt']['status']??'',['completed','read_complete'],true))return (($last['receipt']['status']??'')==='completed'?'The internal release plan was created and verified.':'The saved release plan was verified from the same turn; no second plan was created.').' This receipt does not establish music publication, distribution or a provider action.';
    if(($last['receipt']['capability']??'')==='profile.webmcp.handoff'&&($last['receipt']['status']??'')==='clarification_required'&&($last['receipt']['reason']??'')==='confirmation_required')return 'The Profile handoff was prepared for review. Open the signed Profile surface and complete its existing confirmation step. No booking, checkout, refund, reward or message action was executed by Chat.';
    if(($last['receipt']['capability']??'')==='tracky.perception.request'&&($last['receipt']['status']??'')==='completed')return 'The governed observation request was accepted. Check its returned observation and timestamp to establish whether fresh evidence was collected; a request receipt alone does not verify a current scene.';
    if(($last['receipt']['capability']??'')==='radar.gateway.manage'&&in_array($last['receipt']['status']??'',['completed','read_complete'],true))return (($last['receipt']['status']??'')==='completed'?'The requested Gateway policy was saved and verified.':'The requested Gateway policy was already saved; no new change was made.').' Enforcement depends on the connected site using the server-side Gateway integration. The saved-policy receipt does not establish that a particular request was blocked or allowed.';
    if(($last['receipt']['capability']??'')==='images.generate'&&($last['receipt']['status']??'')==='completed')return 'The image request was queued. Its Chat canvas card shows whether the image was generated and saved; a queue receipt alone does not confirm image completion.';
    if(!$last)return 'The preceding response has no verified tool receipt. I cannot confirm an action or diagnose its failure from the response text alone. Ask me to check the relevant capability or record.';
    if(array_key_exists('vrss_outcome',$last['receipt']??[])){
        $r=vp3_tool_receipt_normalize_v1($last['receipt']);
        if(!$r)return 'The preceding VRSS outcome receipt could not be verified. Check Feed before preparing another request.';
        $safe=['ok'=>true,'verified'=>true,'reconcile_pending'=>false,'rolled_back'=>false,'vrss_outcome'=>$r['vrss_outcome']];
        return vp3_vrss_action_text_v1($safe,$r['status']==='read_complete').' Recorded at '.$r['checked_at'].'. This is the saved outcome of that turn; current state may have changed.';
    }
    $r=$last['receipt'];$label=[
        'read_complete'=>'the read completed','no_matches'=>'the read completed with no matching records',
        'completed'=>'the requested change completed','clarification_required'=>'the executor needed clarification or confirmation; no change was completed',
        'blocked'=>'the operation was blocked; no change was completed','failed'=>'the operation failed; no change was completed',
    ][$r['status']];
    $reason=[
        'upgrade_required'=>'The database upgrade is required.','provider_unavailable'=>'The configured AI service was unavailable.',
        'invalid_plan'=>'The AI plan failed validation.','execution_failed'=>'The executor could not complete the operation.',
        'permission_denied'=>'The account did not have permission.','confirmation_required'=>'The existing confirmation step was still pending.',
        'cancelled_by_user'=>'You cancelled the pending change.',
        'subscription_required'=>'The subscription did not include this capability.',
        'setup_required'=>'An active scheduling configuration was required.',
        'scope_restricted'=>'The selected Knowledge scope does not allow that read.',
        'check_failed'=>'Readiness could not be verified.',
        'not_installed'=>'The required executor was not installed.',
        'outcome_unverified'=>'Effects may already have been saved. Check the current record before repeating the request.',
    ][$r['reason']]??'';
    if($r['reason']==='outcome_unverified')$label='the executor did not verify the final outcome';
    $detail=$r['capability']==='workflows.manage'&&$r['status']==='completed'?' This verifies the queue/control request, not completion of the underlying job.':'';
    if($r['operation']==='pause_request'&&$r['status']==='completed')$detail.=' The pause was requested; the current leased action may still be finishing.';
    if($r['operation']==='hold_for_payment'&&$r['status']==='completed')$detail.=' Only a provisional hold was saved. Payment is unverified and the appointment is not confirmed.';
    return 'For the preceding turn, '.$r['capability'].' / '.$r['operation'].': '.$label.'.'.($reason!==''?' '.$reason:'').$detail
        .' Recorded at '.$r['checked_at'].'. This is the recorded result of that turn; current access and record state may have changed.';
}
