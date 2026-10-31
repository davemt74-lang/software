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
        'workflows.read'=>['inspect'],
        'workflows.manage'=>['approve','pause','pause_request','resume','cancel','retry','reschedule','priority'],
        'scheduling.read'=>['list','availability'],
        'scheduling.manage'=>['prepare','create','reschedule','cancel','hold_for_payment'],
        'team_scheduling.read'=>['list','availability'],
        'team_scheduling.manage'=>['prepare','create','cancel'],
        'phone.numbers.read'=>['list'],
        'phone.sms.read'=>['list','read'],
        'transcripts.highlight'=>['highlight','review_item','item_action'],
        'transcripts.analyze'=>['run_analysis','run_research'],
        'transcripts.read'=>['list','read','search','clarify','interpret','read_analysis','read_research'],
        'capabilities.inspect'=>['inspect'],
        'reminders.manage'=>['clarify','create','list','update','complete','snooze','dismiss','cancel','delete','interpret'],
        'calendar.read'=>['list'],
        'calendar.manage'=>['create','update','cancel','prepare'],
    ];
    $cap=$value['capability']??null;$op=$value['operation']??null;$status=$value['status']??null;
    if(!is_string($cap)||!isset($ops[$cap])||!in_array($op,$ops[$cap],true)||!in_array($status,['read_complete','no_matches','completed','clarification_required','blocked','failed'],true))return null;
    $reason=$value['reason']??'';
    if(!in_array($reason,['','upgrade_required','provider_unavailable','invalid_plan','execution_failed','permission_denied','confirmation_required','cancelled_by_user','subscription_required','outcome_unverified','setup_required'],true))return null;
    $at=$value['checked_at']??null;
    if(!is_string($at)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$at)||strtotime($at)===false)return null;
    foreach(['record_id','count'] as $key)if(!is_int($value[$key]??null)||$value[$key]<0)return null;
    // A read, clarification or failed operation can never be a successful mutation.
    $write=($cap==='workflows.manage')||(in_array($cap,['scheduling.manage','team_scheduling.manage'],true)&&$op!=='prepare')||($cap==='transcripts.highlight'&&in_array($op,['highlight','review_item','item_action'],true))||($cap==='transcripts.analyze'&&in_array($op,['run_analysis','run_research'],true))||($cap==='reminders.manage'&&in_array($op,['create','update','complete','snooze','dismiss','cancel','delete'],true))||($cap==='calendar.manage'&&in_array($op,['create','update','cancel'],true));
    if($status==='completed'&&(!$write||$reason!==''))return null;
    if(in_array($status,['read_complete','no_matches'],true)&&$reason!=='')return null;
    return ['contract'=>'vp3.tool-receipt.v1','capability'=>$cap,'operation'=>$op,'status'=>$status,'reason'=>$reason,
        'record_id'=>$value['record_id'],'count'=>$value['count'],'checked_at'=>$at,'changes_applied'=>$status==='completed'&&$write];
}

function vp3_tool_last_receipt_v1(PDO $pdo,array $user,array $context): ?array
{
    $cid=max(0,(int)($context['conversation_id']??0));$uid=(int)($user['id']??0);$aid=max(0,(int)($context['user_agent_id']??0));
    if($cid<1||$uid<1)return null;
    $q=$pdo->prepare("SELECT m.id,m.context_json FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id WHERE c.id=? AND c.user_id=? AND ".($aid>0?'c.user_agent_id=?':'c.user_agent_id IS NULL')." AND m.role='assistant' ORDER BY m.id DESC LIMIT 1");
    $q->execute($aid>0?[$cid,$uid,$aid]:[$cid,$uid]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    $pending=$pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE conversation_id=? AND role='user' AND id>?");$pending->execute([$cid,(int)$row['id']]);
    if((int)$pending->fetchColumn()>1)return null;
    try{$data=json_decode((string)$row['context_json'],true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){return null;}
    $receipt=vp3_tool_receipt_normalize_v1($data['tool_receipt']??null);
    return $receipt?['message_id'=>(int)$row['id'],'receipt'=>$receipt]:null;
}

function vp3_tool_diagnosis_v1(?array $last): string
{
    if(!$last)return 'The preceding response has no verified tool receipt. I cannot confirm an action or diagnose its failure from the response text alone. Ask me to check the relevant capability or record.';
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
        'outcome_unverified'=>'Effects may already have been saved. Check the current record before repeating the request.',
    ][$r['reason']]??'';
    if($r['reason']==='outcome_unverified')$label='the executor did not verify the final outcome';
    $detail=$r['capability']==='workflows.manage'&&$r['status']==='completed'?' This verifies the queue/control request, not completion of the underlying job.':'';
    if($r['operation']==='pause_request'&&$r['status']==='completed')$detail.=' The pause was requested; the current leased action may still be finishing.';
    if($r['operation']==='hold_for_payment'&&$r['status']==='completed')$detail.=' Only a provisional hold was saved. Payment is unverified and the appointment is not confirmed.';
    return 'For the preceding turn, '.$r['capability'].' / '.$r['operation'].': '.$label.'.'.($reason!==''?' '.$reason:'').$detail
        .' Recorded at '.$r['checked_at'].'. This is the recorded result of that turn; current access and record state may have changed.';
}
