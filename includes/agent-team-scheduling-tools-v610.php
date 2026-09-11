<?php
declare(strict_types=1);

/**
 * VP3 Team Scheduling Agent Tools v6.10
 *
 * Team-scheduling reads and mutations for Agent Chat. Mutations are always a
 * two-turn operation and the pending approval is bound to the authenticated
 * user, conversation, selected Agent principal and Team pool.
 */
const VP3_AGENT_TEAM_SCHEDULING_TOOLS_V610='agent-team-scheduling-tools-v610-20260911';

function agent_team_scheduling_tools_empty_v610(): array
{
    return ['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
}

function agent_team_scheduling_tools_intent_v610(string $query): string
{
    $q=mb_strtolower(trim($query));if($q==='')return '';
    if(preg_match('/\b(?:venue|venues|gig|gigs|tour|touring|music booking|show booking|listener density)\b/u',$q))return '';
    $team=(bool)preg_match('/\b(?:team|round[ -]?robin|pooled|collective|group meeting|our availability|everyone|all of us)\b/u',$q);
    if(!$team)return '';
    if(preg_match('/\b(?:cancel|remove|delete)\b.*\b(?:booking|appointment|meeting)\b|\bconfirm team cancellation\b/u',$q))return 'cancel';
    if(preg_match('/\b(?:book|schedule|set up|make)\b.*\b(?:meeting|appointment|call|time|team)\b|\bteam\b.*\b(?:book|schedule)\b/u',$q))return 'create';
    if(preg_match('/\b(?:available|availability|free|open|slot|slots|when can|what times|what time)\b/u',$q))return 'availability';
    if(preg_match('/\b(?:show|list|what|upcoming|calendar)\b.*\b(?:team|booking|appointment|meeting)\b/u',$q))return 'list';
    return '';
}

function agent_team_scheduling_tools_agent_id_v610(PDO $pdo,array $user,int $conversationId): int
{
    return function_exists('agent_scheduling_tools_conversation_agent_id_v460')
        ?agent_scheduling_tools_conversation_agent_id_v460($pdo,$user,$conversationId):0;
}

function agent_team_scheduling_tools_pool_v610(PDO $pdo,array $user,string $query,int $agentId): ?array
{
    $ownerId=(int)($user['id']??0);if($ownerId<1||!agent_team_scheduling_schema_ready_v600($pdo)||!agent_team_scheduling_entitled_v600($pdo,$ownerId))return null;
    $pools=agent_team_scheduling_pool_list_v600($pdo,$ownerId,true);if(!$pools)return null;
    $q=mb_strtolower($query);$best=null;$score=0;
    foreach($pools as $pool){$candidate=0;$name=mb_strtolower(trim((string)$pool['name']));$slug=str_replace('-',' ',mb_strtolower((string)$pool['slug']));if($name!==''&&str_contains($q,$name))$candidate+=100;if($slug!==''&&str_contains($q,$slug))$candidate+=80;if($agentId>0&&(int)($pool['agent_id']??0)===$agentId)$candidate+=40;if($candidate>$score){$score=$candidate;$best=$pool;}}
    if($best)return $best;
    $bound=array_values(array_filter($pools,static fn(array $pool):bool=>$agentId>0&&(int)($pool['agent_id']??0)===$agentId));
    return count($bound)===1?$bound[0]:(count($pools)===1?$pools[0]:null);
}

function agent_team_scheduling_tools_pending_key_v610(int $userId,int $conversationId): string
{
    return 'vp3_team_scheduling_pending_'.max(0,$userId).'_'.max(0,$conversationId);
}
function agent_team_scheduling_tools_pending_v610(array $user,int $conversationId): ?array
{
    $key=agent_team_scheduling_tools_pending_key_v610((int)($user['id']??0),$conversationId);$pending=$_SESSION[$key]??null;if(!is_array($pending))return null;
    if((int)($pending['expires_at']??0)<time()){unset($_SESSION[$key]);return null;}
    if((int)($pending['user_id']??0)!==(int)($user['id']??0)||(int)($pending['conversation_id']??0)!==$conversationId){unset($_SESSION[$key]);return null;}return $pending;
}
function agent_team_scheduling_tools_clear_pending_v610(array $user,int $conversationId): void
{
    unset($_SESSION[agent_team_scheduling_tools_pending_key_v610((int)($user['id']??0),$conversationId)]);
}
function agent_team_scheduling_tools_prepare_v610(array $user,int $conversationId,int $agentId,int $poolId,string $operation,array $payload,string $summary,string $request): array
{
    if($conversationId<1)throw new RuntimeException('A live Agent Chat conversation is required before changing a Team schedule.');
    $risk=function_exists('agent_action_v124_risk')?agent_action_v124_risk(['source'=>'team_scheduling','title'=>$operation.' team booking','prompt'=>$summary]):['level'=>'medium','external_side_effect'=>true,'destructive'=>$operation==='cancel','requires_approval'=>true];
    $pending=['version'=>'v6.10','user_id'=>(int)$user['id'],'conversation_id'=>$conversationId,'agent_id'=>$agentId,'pool_id'=>$poolId,'operation'=>$operation,'payload'=>$payload,'summary'=>$summary,'request_text'=>mb_strimwidth($request,0,4000,''),'risk'=>$risk,'created_at'=>time(),'expires_at'=>time()+900,'nonce'=>bin2hex(random_bytes(16))];
    $_SESSION[agent_team_scheduling_tools_pending_key_v610((int)$user['id'],$conversationId)]=$pending;return $pending;
}
function agent_team_scheduling_tools_confirm_v610(string $query): bool
{
    return (bool)preg_match('/^(?:yes[,!. ]*)?(?:confirm|approve|go ahead|do it|yes do it)(?:\s+(?:the\s+)?team\s+(?:booking|meeting|cancellation))?[.! ]*$/iu',trim($query));
}
function agent_team_scheduling_tools_reject_v610(string $query): bool
{
    return (bool)preg_match('/^(?:no|nope|stop|never mind|nevermind|do not|don\'t|cancel that|forget it)[.! ]*$/iu',trim($query));
}

function agent_team_scheduling_tools_booking_find_v610(PDO $pdo,int $ownerId,int $poolId,string $query): array
{
    $rows=agent_team_scheduling_upcoming_v600($pdo,$ownerId,$poolId,60);if(!$rows)return ['booking'=>null,'matches'=>[]];
    if(preg_match('/(?:team\s+booking|booking|appointment)\s*#?\s*(\d{1,10})\b/i',$query,$m)){foreach($rows as $row)if((int)$row['id']===(int)$m[1])return ['booking'=>$row,'matches'=>[$row]];}
    $q=mb_strtolower($query);$scored=[];foreach($rows as $row){$score=0;foreach(['guest_name'=>35,'guest_email'=>45,'pool_name'=>20] as $field=>$weight){$v=mb_strtolower(trim((string)($row[$field]??'')));if($v!==''&&str_contains($q,$v))$score+=$weight;}if(function_exists('agent_scheduling_tools_date_v460')){$date=agent_scheduling_tools_date_v460($query,(string)($row['pool_timezone']??'UTC'));if($date!==null){try{$local=(new DateTimeImmutable((string)$row['start_at_utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(agent_scheduling_timezone_v430((string)$row['pool_timezone'])))->format('Y-m-d');$score+=$local===$date?30:-4;}catch(Throwable $e){}}}if($score>0)$scored[]=['score'=>$score,'row'=>$row];}
    usort($scored,static fn(array $a,array $b):int=>$b['score']<=>$a['score']);if(!$scored&&count($rows)===1)return ['booking'=>$rows[0],'matches'=>$rows];if(!$scored)return ['booking'=>null,'matches'=>array_slice($rows,0,5)];$top=(int)$scored[0]['score'];$matches=array_values(array_map(static fn(array $x):array=>$x['row'],array_filter($scored,static fn(array $x):bool=>(int)$x['score']===$top)));return ['booking'=>count($matches)===1?$matches[0]:null,'matches'=>array_slice($matches,0,5)];
}

function agent_team_scheduling_tools_execute_v610(PDO $pdo,array $user,int $conversationId,array $pending): array
{
    $empty=agent_team_scheduling_tools_empty_v610();$result=$empty;$result['handled']=true;$ownerId=(int)$user['id'];$agentId=(int)$pending['agent_id'];$poolId=(int)$pending['pool_id'];
    if(!agent_team_scheduling_entitled_v600($pdo,$ownerId))throw new RuntimeException('Team Scheduling is no longer included in the current workspace plan.');
    if(agent_team_scheduling_tools_agent_id_v610($pdo,$user,$conversationId)!==$agentId)throw new RuntimeException('The Agent principal changed before approval. Prepare the Team scheduling action again.');
    $pool=agent_team_scheduling_pool_v600($pdo,$ownerId,$poolId);if(!$pool||empty($pool['is_active']))throw new RuntimeException('That Team schedule is no longer active.');
    if($agentId>0&&(int)($pool['agent_id']??0)>0&&(int)$pool['agent_id']!==$agentId)throw new RuntimeException('This Booking Agent is no longer assigned to that Team schedule.');
    $payload=(array)($pending['payload']??[]);$operation=(string)$pending['operation'];
    if($operation==='create'){
        $booking=agent_team_scheduling_create_booking_v600($pdo,$pool,['start_at_utc'=>(string)$payload['start_at_utc'],'guest_timezone'=>(string)$pool['timezone'],'guest_name'=>(string)$payload['guest_name'],'guest_email'=>(string)($payload['guest_email']??''),'routing_answer'=>(string)($payload['routing_answer']??''),'created_by_agent_id'=>$agentId,'source'=>'team_agent']);
        $when=agent_scheduling_tools_slot_label_v460((string)$booking['start_at_utc'],(string)$pool['timezone']);$host=(string)($booking['host_name']??'');$result['answer']='Booked. '.(string)$pool['name'].' is set for '.$when.((string)$pool['mode']==='round_robin'&&$host!==''?' Host: '.$host.'.':'');$result['actions'][]=['type'=>'open_url','label'=>'Open Team Scheduling','url'=>url('/team-scheduling.php?pool='.$poolId.'#bookings')];
        agent_tool_log($user,'team_scheduling.book',(string)$pending['request_text'],'success',['team_booking_id'=>(int)$booking['id'],'pool_id'=>$poolId,'agent_id'=>$agentId],$conversationId);return $result;
    }
    if($operation==='cancel'){
        $booking=agent_team_scheduling_booking_v600($pdo,$ownerId,(int)($payload['booking_id']??0));if(!$booking||(int)$booking['pool_id']!==$poolId)throw new RuntimeException('That Team booking is no longer available.');
        if(!agent_team_scheduling_cancel_booking_v600($pdo,$booking))throw new RuntimeException('That Team booking could not be cancelled.');$result['answer']='Cancelled. The Team booking for '.(string)$booking['guest_name'].' was removed from every participant calendar.';$result['actions'][]=['type'=>'open_url','label'=>'Open Team Scheduling','url'=>url('/team-scheduling.php?pool='.$poolId.'#bookings')];agent_tool_log($user,'team_scheduling.cancel',(string)$pending['request_text'],'success',['team_booking_id'=>(int)$booking['id'],'pool_id'=>$poolId,'agent_id'=>$agentId],$conversationId);return $result;
    }
    throw new RuntimeException('The pending Team scheduling action is no longer supported.');
}

function agent_team_scheduling_tools_query_v610(string $query,array $user,int $conversationId=0): array
{
    $empty=agent_team_scheduling_tools_empty_v610();$pdo=db();$ownerId=(int)($user['id']??0);
    if(!$pdo||!agent_team_scheduling_schema_ready_v600($pdo)||$ownerId<1||!agent_team_scheduling_entitled_v600($pdo,$ownerId))return $empty;
    $pending=agent_team_scheduling_tools_pending_v610($user,$conversationId);
    if($pending&&agent_team_scheduling_tools_reject_v610($query)){agent_team_scheduling_tools_clear_pending_v610($user,$conversationId);$result=$empty;$result['handled']=true;$result['answer']='Okay — I did not make that Team scheduling change.';agent_tool_log($user,'team_scheduling.approval',$query,'denied',['pool_id'=>(int)$pending['pool_id']],$conversationId);return $result;}
    if($pending&&agent_team_scheduling_tools_confirm_v610($query)){try{$result=agent_team_scheduling_tools_execute_v610($pdo,$user,$conversationId,$pending);agent_team_scheduling_tools_clear_pending_v610($user,$conversationId);$result['approval']=['required'=>true,'approved'=>true,'risk'=>$pending['risk']??[],'version'=>'v6.10'];return $result;}catch(Throwable $e){agent_team_scheduling_tools_clear_pending_v610($user,$conversationId);$result=$empty;$result['handled']=true;$result['answer']='I could not complete that Team scheduling change: '.$e->getMessage();agent_tool_log($user,'team_scheduling.'.(string)$pending['operation'],$query,'failed',['error'=>$e->getMessage()],$conversationId);return $result;}}
    if(!$pending&&agent_team_scheduling_tools_confirm_v610($query)){$result=$empty;$result['handled']=true;$result['answer']='There is no pending Team scheduling change to confirm.';return $result;}

    $intent=agent_team_scheduling_tools_intent_v610($query);if($intent==='')return $empty;$agentId=agent_team_scheduling_tools_agent_id_v610($pdo,$user,$conversationId);$pool=agent_team_scheduling_tools_pool_v610($pdo,$user,$query,$agentId);$result=$empty;$result['handled']=true;
    if(!$pool){$pools=agent_team_scheduling_pool_list_v600($pdo,$ownerId,true);if(!$pools){$result['answer']='Team Scheduling is not set up yet. Open Team Scheduling to create a round-robin or collective pool.';$result['actions'][]=['type'=>'open_url','label'=>'Open Team Scheduling','url'=>url('/team-scheduling.php')];return $result;}$result['answer']="Which Team schedule do you mean?\n".implode("\n",array_map(static fn(array $p):string=>'• '.(string)$p['name'].' · '.str_replace('_',' ',(string)$p['mode']),$pools));return $result;}
    $poolId=(int)$pool['id'];$timezone=agent_scheduling_timezone_v430((string)$pool['timezone']);$result['actions'][]=['type'=>'open_url','label'=>'Open Team Scheduling','url'=>url('/team-scheduling.php?pool='.$poolId)];
    if($intent==='list'){$rows=agent_team_scheduling_upcoming_v600($pdo,$ownerId,$poolId,20);if(!$rows)$result['answer']='There are no upcoming Team bookings for '.(string)$pool['name'].'.';else{$lines=[];foreach(array_slice($rows,0,12) as $row)$lines[]='• #'.(int)$row['id'].' · '.agent_scheduling_tools_slot_label_v460((string)$row['start_at_utc'],$timezone).' · '.(string)$row['guest_name'].((string)$row['mode']==='round_robin'&&trim((string)$row['assigned_name'])!==''?' · host '.(string)$row['assigned_name']:'');$result['answer']="Upcoming Team bookings in {$timezone}:\n".implode("\n",$lines);}agent_tool_log($user,'team_scheduling.list',$query,'success',['pool_id'=>$poolId,'count'=>count($rows),'agent_id'=>$agentId],$conversationId);return $result;}
    if($intent==='availability'){$date=agent_scheduling_tools_date_v460($query,$timezone);$start=$date!==null?new DateTimeImmutable($date,new DateTimeZone($timezone)):new DateTimeImmutable('today',new DateTimeZone($timezone));$days=$date!==null?1:7;$lines=[];for($i=0;$i<$days&&count($lines)<5;$i++){$d=$start->modify('+'.$i.' days')->format('Y-m-d');$slots=agent_team_scheduling_slots_v600($pdo,$pool,$d,$query);if(!$slots)continue;$times=array_map(fn(array $slot):string=>agent_scheduling_tools_slot_label_v460((string)$slot['start_at_utc'],$timezone,'g:i A'),array_slice($slots,0,8));$lines[]='• '.(new DateTimeImmutable($d))->format('D, M j').' — '.implode(', ',$times);}$result['answer']=$lines?'Open '.str_replace('_',' ',(string)$pool['mode'])." Team times in {$timezone}:\n".implode("\n",$lines):'I do not see a Team time matching that request.';agent_tool_log($user,'team_scheduling.availability',$query,'success',['pool_id'=>$poolId,'agent_id'=>$agentId],$conversationId);return $result;}
    if($intent==='create'){$date=agent_scheduling_tools_date_v460($query,$timezone);if($date===null){$result['answer']='What date should I schedule the Team meeting?';return $result;}$time=agent_scheduling_tools_time_request_v460($query);if(!$time){$result['answer']='What time should I use? I’ll check the pooled Team availability.';return $result;}$slots=agent_team_scheduling_slots_v600($pdo,$pool,$date,$query);$picked=agent_scheduling_tools_pick_slot_v460($slots,$time);if(!empty($picked['ambiguous'])){$result['answer']='I found both AM and PM Team times. Which one do you mean?';return $result;}if(empty($picked['slot'])){$times=array_map(fn(array $s):string=>agent_scheduling_tools_slot_label_v460((string)$s['start_at_utc'],$timezone,'g:i A'),array_slice($slots,0,8));$result['answer']=$times?'That Team time is not open. Available times are: '.implode(', ',$times).'.':'There are no pooled Team openings on '.$date.'.';return $result;}$guest=agent_scheduling_tools_guest_name_v460($query);if($guest===''){$result['answer']='Who is this Team meeting with?';return $result;}$email=agent_scheduling_tools_email_v460($query);$slot=$picked['slot'];$summary=(string)$pool['name'].' with '.$guest.' on '.agent_scheduling_tools_slot_label_v460((string)$slot['start_at_utc'],$timezone).($email!==''?' · '.$email:'');$prepared=agent_team_scheduling_tools_prepare_v610($user,$conversationId,$agentId,$poolId,'create',['start_at_utc'=>(string)$slot['start_at_utc'],'guest_name'=>$guest,'guest_email'=>$email,'routing_answer'=>$query],$summary,$query);$result['answer']="I’m ready to create this Team booking:\n• {$summary}\n• Mode: ".str_replace('_',' ',(string)$pool['mode'])."\n\nReply **Confirm team booking** to approve it, or **Never mind** to stop.";$result['approval']=['required'=>true,'approved'=>false,'expires_at'=>(int)$prepared['expires_at'],'risk'=>$prepared['risk'],'version'=>'v6.10'];agent_tool_log($user,'team_scheduling.book',$query,'approval_required',['pool_id'=>$poolId,'agent_id'=>$agentId,'start_at_utc'=>(string)$slot['start_at_utc']],$conversationId);return $result;}
    if($intent==='cancel'){$found=agent_team_scheduling_tools_booking_find_v610($pdo,$ownerId,$poolId,$query);$booking=$found['booking'];if(!$booking){$choices=[];foreach((array)$found['matches'] as $row)$choices[]='• #'.(int)$row['id'].' · '.(string)$row['guest_name'].' · '.agent_scheduling_tools_slot_label_v460((string)$row['start_at_utc'],$timezone);$result['answer']=$choices?"Which Team booking should I cancel?\n".implode("\n",$choices):'I could not find a matching active Team booking.';return $result;}$summary=(string)$pool['name'].' with '.(string)$booking['guest_name'].' on '.agent_scheduling_tools_slot_label_v460((string)$booking['start_at_utc'],$timezone);$prepared=agent_team_scheduling_tools_prepare_v610($user,$conversationId,$agentId,$poolId,'cancel',['booking_id'=>(int)$booking['id']],$summary,$query);$result['answer']="I’m ready to cancel this Team booking across every participant calendar:\n• {$summary}\n\nReply **Confirm team cancellation** to approve it, or **Never mind** to keep it.";$result['approval']=['required'=>true,'approved'=>false,'expires_at'=>(int)$prepared['expires_at'],'risk'=>$prepared['risk'],'version'=>'v6.10'];agent_tool_log($user,'team_scheduling.cancel',$query,'approval_required',['team_booking_id'=>(int)$booking['id'],'pool_id'=>$poolId,'agent_id'=>$agentId],$conversationId);return $result;}
    return $empty;
}
