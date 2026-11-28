<?php
declare(strict_types=1);

/** Saved analyses are private to one live source, owner, Agent and Chat. */
function gmail_email_capture_history_v1(PDO $pdo,array $user,array $principal,int $chat,array $source,?string $cursor,
    Closure $current,?callable $http=null): array
{
    gmail_email_capture_read_v1($pdo,$user,$principal,$chat,$source,$current,$http);
    if (!gmail_email_capture_ready_v1($pdo)) throw new DomainException('Run Database Upgrade to reopen saved email analyses.',503);
    $owner=(int)$user['id'];$agent=(int)($principal['agent_id']??0);$hash=gmail_email_capture_source_hash_v1($source,$agent,$chat);
    $args=[$owner,$agent,$chat,$hash];$where='';
    if ($cursor!==null) {
        if (strlen($cursor)>2000||!preg_match('/\A(?:[a-f0-9]{2})+\z/D',$cursor)) throw new DomainException('Refresh the saved analysis list.',422);
        $c=json_decode(ai_decrypt_secret(hex2bin($cursor)),true,8);
        if (!is_array($c)||array_keys($c)!==['owner','agent','chat','source','epoch','id','expires']||$c['owner']!==$owner||$c['agent']!==$agent||$c['chat']!==$chat
            ||$c['source']!==$hash||!is_int($c['epoch'])||$c['epoch']<1||!is_string($c['id'])||!preg_match('/\A[a-f0-9]{32}\z/D',$c['id'])
            ||!is_int($c['expires'])||$c['expires']<time()||$c['expires']>time()+900) throw new DomainException('Refresh the saved analysis list.',422);
        $where=' AND (created_epoch<? OR (created_epoch=? AND id<?))';array_push($args,$c['epoch'],$c['epoch'],$c['id']);
    }
    $q=$pdo->prepare('SELECT * FROM agent_gmail_captures_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=? AND source_hash=?'.$where.' ORDER BY created_epoch DESC,id DESC LIMIT 11');
    $q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$items=[];
    foreach (array_slice($rows,0,10) as $row) {
        $value=gmail_email_capture_public_v1(gmail_email_capture_expire_v1($pdo,$row));
        $items[]=['id'=>$value['id'],'state'=>$value['state'],'reason'=>$value['reason'],'mode'=>$value['mode'],'created_at'=>$value['created_at'],
            'excerpt'=>mb_substr($value['selected_text'],0,160),'summary'=>mb_substr((string)($value['result']['summary']??''),0,240),
            'received_response_retained'=>$value['received_response_retained']];
    }
    $next=null;
    if (count($rows)>10) {$last=$rows[9];$next=bin2hex(ai_encrypt_secret(json_encode(['owner'=>$owner,'agent'=>$agent,'chat'=>$chat,'source'=>$hash,
        'epoch'=>(int)$last['created_epoch'],'id'=>$last['id'],'expires'=>time()+900],JSON_THROW_ON_ERROR)));}
    if ($current()===false) throw new DomainException('Private email access changed.',403);
    return ['items'=>$items,'next_cursor'=>$next,'actions'=>gmail_email_capture_actions_v1($pdo,$user,$principal,$chat,$source)];
}

function gmail_email_capture_expire_v1(PDO $pdo,array $row): array
{
    if (in_array($row['state'],['preparing','dispatched'],true)&&(int)$row['lease_epoch']<=time()) {
        // Read the actual locked state, not an earlier preparing snapshot.
        $pdo->prepare("UPDATE agent_gmail_captures_v1 SET reason=CASE WHEN state='preparing' THEN 'not_dispatched' ELSE 'interrupted' END,state='failed',updated_epoch=? WHERE id=? AND owner_user_id=? AND agent_id=? AND conversation_id=? AND state IN ('preparing','dispatched') AND lease_epoch<=?")
            ->execute([time(),$row['id'],$row['owner_user_id'],$row['agent_id'],$row['conversation_id'],time()]);
        return gmail_email_capture_row_v1($pdo,(int)$row['owner_user_id'],(int)$row['agent_id'],(int)$row['conversation_id'],$row['id'])??$row;
    }
    return $row;
}

/** Receipt links contain native IDs only. Never copy a second calendar/task payload. */
function gmail_email_capture_action_receipt_v1(PDO $pdo,array $user,array $principal,array $row): array
{
    $owner=(int)$user['id'];$id=(int)$row['record_id'];$kind=$row['kind'];$native=null;$detail='';$href=null;
    if ($kind==='calendar'&&has_permission('account.access',$user)) {
        $native=user_calendar_event_v1300($pdo,$owner,$id);
        if ($native) {$detail=$native['start_at_utc'].' UTC · '.$native['timezone'];$href=url('/calendar-event.php?id='.$id);}
    } elseif ($kind==='reminder') {
        $q=$pdo->prepare('SELECT * FROM agent_reminders_v1 WHERE id=? AND owner_user_id=? AND conversation_id=?');$q->execute([$id,$owner,(int)$row['conversation_id']]);$native=$q->fetch(PDO::FETCH_ASSOC)?:null;
        if ($native) $detail=$native['start_local'].' · '.$native['timezone'];
    } elseif ($kind==='task') {
        require_once __DIR__.'/agent-task-lists-v1.php';
        try {vp3_task_list_authorize_v1($pdo,$user,$principal,(int)$row['conversation_id']);$native=vp3_task_list_existing_task_v1($pdo,$user,$principal,$id);}
        catch (RuntimeException|DomainException) {$native=null;}
        if ($native) {
            $q=$pdo->prepare('SELECT memory_text,metadata_json FROM agent_memory_items WHERE id=? AND user_id=?');$q->execute([$id,$owner]);$data=$q->fetch(PDO::FETCH_ASSOC);
            $meta=json_decode((string)($data['metadata_json']??''),true);$native['title']=$native['subject'];$native['status']=$meta['task_status']??'open';$detail=mb_substr((string)($data['memory_text']??''),0,6000);
        }
    } elseif ($kind==='chat') {
        $q=$pdo->prepare("SELECT message FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");$q->execute([$id,(int)$row['conversation_id'],$owner]);$body=$q->fetchColumn();
        if ($body!==false) {$native=['title'=>'Finding shared in Chat','status'=>'saved'];$detail=mb_substr($body,0,10000);$href=url('/chat.php?agent_id='.(int)$row['agent_id'].'&conversation_id='.(int)$row['conversation_id']);}
    }
    return ['id'=>$row['id'],'kind'=>$kind,'record_id'=>$id,'available'=>$native!==null,'status'=>$native['status']??'unavailable',
        'title'=>$native['title']??'Saved action unavailable','detail'=>$detail,'href'=>$href,'created_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$row['created_epoch'])];
}

function gmail_email_capture_actions_v1(PDO $pdo,array $user,array $principal,int $chat,array $source,?string $capture=null): array
{
    $args=[(int)$user['id'],(int)($principal['agent_id']??0),$chat,gmail_email_capture_source_hash_v1($source,(int)($principal['agent_id']??0),$chat)];
    if ($capture!==null) $args[]=$capture;
    $q=$pdo->prepare('SELECT * FROM agent_gmail_capture_actions_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=? AND source_hash=?'.($capture!==null?' AND capture_id=?':'').' ORDER BY created_epoch DESC,id DESC LIMIT 40');
    $q->execute($args);return array_map(static fn(array $r):array=>gmail_email_capture_action_receipt_v1($pdo,$user,$principal,$r),$q->fetchAll(PDO::FETCH_ASSOC));
}

function gmail_email_capture_action_capture_v1(PDO $pdo,array $user,array $principal,int $chat,array $source,array $input): string
{
    if (!array_key_exists('capture_id',$input)) return '';
    $id=$input['capture_id'];if (!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)) throw new DomainException('Choose a saved email analysis.',422);
    $row=gmail_email_capture_row_v1($pdo,(int)$user['id'],(int)($principal['agent_id']??0),$chat,$id);
    if (!$row||!hash_equals($row['source_hash'],gmail_email_capture_source_hash_v1($source,(int)($principal['agent_id']??0),$chat))) throw new DomainException('This analysis belongs to another email or Chat.',409);
    return $id;
}

function gmail_email_capture_record_action_v1(PDO $pdo,array $user,array $principal,int $chat,array $source,string $capture,string $effect,string $kind,int $record): array
{
    if (!$pdo->inTransaction()||$record<1||!in_array($kind,['calendar','reminder','task','chat'],true)) throw new LogicException('Record the native action inside its owner transaction.');
    $id=gmail_email_capture_hash_v1([$effect,$capture]);
    $q=$pdo->prepare('SELECT * FROM agent_gmail_capture_actions_v1 WHERE id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $row=['id'=>$id,'owner_user_id'=>(int)$user['id'],'agent_id'=>(int)($principal['agent_id']??0),'conversation_id'=>$chat,
            'source_hash'=>gmail_email_capture_source_hash_v1($source,(int)($principal['agent_id']??0),$chat),'effect_key'=>$effect,'capture_id'=>$capture,'kind'=>$kind,'record_id'=>$record,'created_epoch'=>time()];
        $pdo->prepare('INSERT INTO agent_gmail_capture_actions_v1(id,owner_user_id,agent_id,conversation_id,source_hash,effect_key,capture_id,kind,record_id,created_epoch) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute(array_values($row));
    }
    return gmail_email_capture_action_receipt_v1($pdo,$user,$principal,$row);
}

/** Explicit owner-reviewed finding handoff; no inference and no new Chat turn executor. */
function gmail_email_capture_finding_v1(PDO $pdo,array $user,array $principal,int $chat,array $source,string $kind,array $input,Closure $current,?callable $http=null): array
{
    if (!in_array($kind,['task','chat'],true)) throw new DomainException('Choose a task or Chat handoff.',422);
    $mail=gmail_email_capture_read_v1($pdo,$user,$principal,$chat,$source,$current,$http);
    if (!gmail_email_capture_ready_v1($pdo)) throw new DomainException('Run Database Upgrade to save email findings.',503);
    $capture=gmail_email_capture_action_capture_v1($pdo,$user,$principal,$chat,$source,$input);
    $fields=$input['fields']??null;if (!is_array($fields)||array_is_list($fields)) throw new DomainException('Review the finding before saving.',422);
    $allowed=$kind==='task'?['title','notes']:['message'];if (array_diff(array_keys($fields),$allowed)) throw new DomainException('Review the finding fields.',422);
    $text=gmail_email_capture_text_v1($input['text']??'',6000);
    $finding=gmail_email_capture_text_v1($fields[$kind==='task'?'notes':'message']??'',$kind==='task'?4000:8000);
    $title=$kind==='task'?gmail_email_capture_text_v1($fields['title']??'',180):'Finding shared in Chat';
    if ($title===''||($kind==='chat'&&$finding==='')) throw new DomainException('Enter the finding you want to save.',422);
    $owner=(int)$user['id'];$agent=(int)($principal['agent_id']??0);$hash=gmail_email_capture_source_hash_v1($source,$agent,$chat);
    $effect=gmail_email_capture_hash_v1([$hash,$kind,$title,$finding,$text]);
    if ($kind==='task') {
        require_once __DIR__.'/agent-task-lists-v1.php';
        vp3_task_list_authorize_v1($pdo,$user,$principal,$chat);
        if (!vp3_task_lists_ready_v1($pdo)) throw new DomainException('Run Database Upgrade to enable tasks.',503);
    }
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$principal,$chat,$source,$current,$capture,$kind,$title,$finding,$text,$owner,$agent,$hash,$effect,$mail):array{
        $guard=static function()use($pdo,$user,$principal,$chat,$source,$current,$owner,$kind):void{
            if ($current()===false) throw new DomainException('Private email access changed.',403);
            agent_connection_email_chat_authority_v1($pdo,$user,$principal,$chat);
            $c=agent_connections_require_grant_v1($pdo,$owner,$source['connection'],'read',$source['grant']);
            if ($c['client_fingerprint']!==$source['fingerprint']||$c['account_email']!==$source['account']) throw new DomainException('The email account changed.',409);
            if ($kind==='task') vp3_task_list_authorize_v1($pdo,$user,$principal,$chat);
        };
        $guard();
        $pdo->prepare('SELECT id FROM users WHERE id=?'.agent_commerce_lock_pa2($pdo))->execute([$owner]);
        if ($capture!=='') gmail_email_capture_action_capture_v1($pdo,$user,$principal,$chat,$source,['capture_id'=>$capture]);
        $q=$pdo->prepare('SELECT record_id FROM agent_gmail_capture_actions_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=? AND source_hash=? AND effect_key=? AND kind=? LIMIT 1');
        $q->execute([$owner,$agent,$chat,$hash,$effect,$kind]);$id=(int)$q->fetchColumn();
        if (!$id) {
            $origin=['message'=>$mail['message_id'],'thread'=>$mail['thread_id'],'subject'=>$mail['subject'],'from'=>$mail['from'],'capture_id'=>$capture,'selected_text'=>$text];
            if ($kind==='task') {
                vp3_task_list_authorize_v1($pdo,$user,$principal,$chat);
                $id=vp3_task_list_save_task_v1($pdo,$user,$principal,$title,null,'gmail-capture:'.$effect,$chat);
                $q=$pdo->prepare('SELECT metadata_json FROM agent_memory_items WHERE id=? AND user_id=?');$q->execute([$id,$owner]);$meta=json_decode((string)$q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
                $meta['source_kind']='gmail_capture';$meta['email_capture_source_enc']=ai_encrypt_secret(json_encode($origin,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));$meta['owner_notes']=$finding;
                $pdo->prepare('UPDATE agent_memory_items SET memory_text=?,metadata_json=? WHERE id=? AND user_id=?')
                    ->execute([$finding?:$title,json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$id,$owner]);
            } else {
                $message='Finding from email: '.$mail['subject']."\nFrom: ".$mail['from']."\n\n".$finding;
                $context=json_encode(['source'=>'owner_gmail_capture','gmail_capture_id'=>$capture,'email_source_enc'=>ai_encrypt_secret(json_encode($origin,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))],JSON_THROW_ON_ERROR);
                $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at) VALUES(?,?,'user',?,?,CURRENT_TIMESTAMP)")->execute([$chat,$owner,$message,$context]);$id=(int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE chat_conversations SET updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?')->execute([$chat,$owner]);
            }
        }
        $receipt=gmail_email_capture_record_action_v1($pdo,$user,$principal,$chat,$source,$capture,$effect,$kind,$id);
        $guard();
        return ['kind'=>$kind,'id'=>$id,'message'=>$kind==='task'?'Task saved.':'Finding sent to Chat.','action'=>$receipt];
    });
}
