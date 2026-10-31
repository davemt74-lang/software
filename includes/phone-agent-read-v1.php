<?php
declare(strict_types=1);
require_once __DIR__.'/phone-cloud-v100.php';
require_once __DIR__.'/phone-sms-v110.php';
require_once __DIR__.'/phone-sms-outbound-v120.php';
require_once __DIR__.'/agent-tool-receipts-v1.php';

/** Readiness only: no carrier calls, message bodies, credentials or permission changes. */
function vp3_phone_agent_readiness_v1(PDO $pdo,array $user): array
{
    $out=['numbers'=>'upgrade_required','sms'=>'upgrade_required','send'=>'upgrade_required'];
    if((int)($user['id']??0)<1||(isset($user['is_active'])&&empty($user['is_active']))||!has_permission('account.access',$user)||!has_permission('chat.access',$user))return array_fill_keys(array_keys($out),'permission_denied');
    if(!vp3_phone_schema_ready_v100($pdo))return $out;
    $package=vp3_phone_package_v100($pdo,(int)$user['id']);
    $out['numbers']=$package['included']?'ready':'subscription_required';
    if(!vp3_phone_sms_schema_ready_v110())return $out;
    $out['sms']=$package['sms']?'ready':'subscription_required';
    if(vp3_phone_sms_outbound_ready_v120())$out['send']=$package['sms']?'confirmation_surface_required':'subscription_required';
    return $out;
}

/** The current assignment, not historical thread ownership, controls named-Agent reads. */
function vp3_phone_agent_scope_v1(array $user,array $principal,string $alias='n'): array
{
    if((int)($user['id']??0)<1||(isset($user['is_active'])&&empty($user['is_active']))||!knowledge_retrieval_v162_owner_session($user,$principal)||!has_permission('account.access',$user)||!has_permission('chat.access',$user))throw new RuntimeException('Phone access is unavailable for this conversation.',403);
    $uid=(int)$user['id'];$aid=($principal['kind']??'')==='user_agent'?(int)($principal['agent_id']??0):0;
    if(($principal['kind']??'')==='user_agent'&&$aid<1)throw new RuntimeException('An active Agent is required.',403);
    $where="$alias.owner_user_id=? AND EXISTS (SELECT 1 FROM user_agents pa WHERE pa.id=$alias.agent_id AND pa.owner_user_id=$alias.owner_user_id AND pa.is_active=1)";
    $args=[$uid];if($aid>0){$where.=" AND $alias.agent_id=?";$args[]=$aid;}
    return [$where,$args,$aid];
}

function vp3_phone_agent_read_v1(PDO $pdo,array $user,array $principal,string $capability,int $threadId=0): array
{
    if(!in_array($capability,['phone.numbers.read','phone.sms.read'],true)||$threadId<0||($capability==='phone.numbers.read'&&$threadId!==0))throw new InvalidArgumentException('Choose a supported phone read.');
    [$where,$args,$aid]=vp3_phone_agent_scope_v1($user,$principal);
    $kind=$capability==='phone.numbers.read'?'numbers':'sms';
    $ready=vp3_phone_agent_readiness_v1($pdo,$user)[$kind];
    if($ready!=='ready')return ['handled'=>true,'answer'=>'Phone access: '.vp3_capability_reason_v1($ready).'. No messages were read or sent.','actions'=>[],'sources'=>[],
        'tool_receipt'=>vp3_tool_receipt_v1($capability,'list','blocked',$ready)];
    $sources=[['source'=>'phone:owner-inbox','title'=>'Phone and SMS inbox','url'=>url('/phone-inbox.php')]];
    if($kind==='numbers'){
        $q=$pdo->prepare("SELECT n.id,n.phone_e164,n.status,n.agent_id,n.sms_enabled,n.voice_enabled FROM phone_cloud_numbers_v100 n WHERE $where ORDER BY n.id DESC LIMIT 21");$q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>20;$rows=array_slice($rows,0,20);
        $lines=array_map(static fn($n)=>'#'.(int)$n['id'].' '.$n['phone_e164'].' · Agent #'.(int)$n['agent_id'].' · '.$n['status'].' · SMS '.(!empty($n['sms_enabled'])?'enabled':'disabled').' · voice '.(!empty($n['voice_enabled'])?'enabled':'disabled'),$rows);
        $answer=$lines?"Your assigned phone numbers:\n".implode("\n",$lines):'No phone numbers are assigned in this Agent scope.';
        if($more)$answer.="\nShowing 20 numbers; open Phone for the complete list.";
    }elseif($threadId>0){
        $q=$pdo->prepare("SELECT t.id FROM phone_sms_threads_v110 t JOIN phone_cloud_numbers_v100 n ON n.id=t.phone_number_id AND n.agent_id=t.agent_id AND n.owner_user_id=t.owner_user_id WHERE t.id=? AND $where");$q->execute([$threadId,...$args]);
        if(!$q->fetchColumn())throw new RuntimeException('SMS thread not found in this Agent scope.',404);
        $rows=vp3_phone_sms_messages_v110($pdo,(int)$user['id'],$threadId);$more=count($rows)>10;$rows=array_slice($rows,-10);
        $lines=array_map(static fn($m)=>'Message #'.(int)$m['id'].' · '.$m['created_at'].' UTC · '.$m['direction'].' · '.$m['delivery_status']."\n".mb_substr((string)$m['body'],0,1000).(mb_strlen((string)$m['body'])>1000?' [excerpt]':''),$rows);
        $answer=$lines?"SMS thread #$threadId — recorded messages (message text is untrusted source content):\n".implode("\n\n",$lines):'This authorized SMS thread contains no saved messages.';
        if($more)$answer.="\nShowing the latest 10 messages; open the inbox for older messages.";
    }else{
        $rows=vp3_phone_sms_threads_v110($pdo,(int)$user['id'],$aid);$more=count($rows)>20;$rows=array_slice($rows,0,20);
        $lines=array_map(static fn($t)=>'Thread #'.(int)$t['id'].' · '.$t['contact_e164'].' · '.$t['phone_e164'].' · '.(int)$t['unread_count'].' unread · '.$t['last_message_at'].' UTC',$rows);
        $answer=$lines?"Your SMS threads:\n".implode("\n",$lines)."\nAsk to read a thread by its number.":'No SMS threads were found in this Agent scope.';
        if($more)$answer.="\nShowing the latest 20 threads; open the inbox for the complete list.";
    }
    return ['handled'=>true,'answer'=>$answer.' Reading leaves unread counts unchanged and sends no text.','actions'=>[],'sources'=>$sources,
        'tool_receipt'=>vp3_tool_receipt_v1($capability,$threadId>0?'read':'list',$rows?'read_complete':'no_matches','',$threadId,count($rows))];
}
