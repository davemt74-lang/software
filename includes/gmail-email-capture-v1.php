<?php
declare(strict_types=1);

require_once __DIR__.'/gmail-email-capture-schema-v1.php';
require_once __DIR__.'/gmail-email-capture-source-v1.php';
require_once __DIR__.'/gmail-email-capture-input-v1.php';
require_once __DIR__.'/agent-gmail-document-input-v1.php';
require_once __DIR__.'/user-calendar-v1300.php';
require_once __DIR__.'/agent-reminders-v1.php';

function gmail_email_capture_row_v1(PDO $pdo,int $owner,int $agent,int $chat,string $id): ?array
{
    $q=$pdo->prepare('SELECT * FROM agent_gmail_captures_v1 WHERE id=? AND owner_user_id=? AND agent_id=? AND conversation_id=?');
    $q->execute([$id,$owner,$agent,$chat]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function gmail_email_capture_public_v1(array $row): array
{
    return ['id'=>$row['id'],'state'=>$row['state'],'reason'=>$row['reason'],
        'result'=>$row['state']==='ready'?json_decode(ai_decrypt_secret($row['result_enc']),true,16,JSON_THROW_ON_ERROR):null,
        'received_text'=>$row['state']==='failed'&&$row['answer_enc']!==null?mb_substr(ai_decrypt_secret($row['answer_enc']),0,12000):'',
        'received_response_retained'=>$row['answer_enc']!==null];
}

/** A bounded foreground analysis, with exactly one consumed physical dispatch claim. */
function gmail_email_capture_analyze_v1(PDO $pdo,array $user,array $principal,int $chat,array $source,array $input,
    Closure $current,?callable $http=null,?callable $model=null): array
{
    if ($pdo->inTransaction()) throw new LogicException('Analyze email outside a transaction.');
    $mail=gmail_email_capture_read_v1($pdo,$user,$principal,$chat,$source,$current,$http);
    if (!gmail_email_capture_ready_v1($pdo)) throw new DomainException('Run Database Upgrade to enable email area analysis.',503);
    $owner=(int)$user['id'];$agent=(int)($principal['agent_id']??0);
    $sourceHash=gmail_email_capture_source_hash_v1($source,$agent,$chat);
    $request=$input['request_id']??null;
    if (!is_string($request)||!preg_match('/\A[a-f0-9]{32}\z/D',$request)) throw new DomainException('Choose a new email analysis request.',422);
    $capture=gmail_email_capture_input_v1($input);
    $hash=gmail_email_capture_hash_v1([$sourceHash,$capture['mode'],$capture['text'],hash('sha256',$capture['image'])]);
    $id=substr(gmail_email_capture_hash_v1([$owner,$agent,$chat,$request]),0,32);
    $claim=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$principal,$chat,$current,$owner,$agent,$id,$request,$sourceHash,$hash,$capture,$source,$mail):bool{
        $current();agent_connection_email_chat_authority_v1($pdo,$user,$principal,$chat);
        agent_connections_require_grant_v1($pdo,$owner,$source['connection'],'read',$source['grant']);
        $pdo->prepare('SELECT id FROM users WHERE id=?'.agent_commerce_lock_pa2($pdo))->execute([$owner]);
        $old=gmail_email_capture_row_v1($pdo,$owner,$agent,$chat,$id);
        if ($old) {
            if (!hash_equals($old['source_hash'],$sourceHash)||!hash_equals($old['input_hash'],$hash)) throw new DomainException('This analysis request belongs to a different selection.',409);
            return false;
        }
        $origin=$source;unset($origin['expires']);
        $record=['source'=>$origin,'subject'=>$mail['subject'],'from'=>$mail['from'],'mode'=>$capture['mode'],
            'selected_text'=>$capture['text'],'image_sha256'=>$capture['image']===''?null:hash('sha256',$capture['image'])];
        $pdo->prepare("INSERT INTO agent_gmail_captures_v1(id,owner_user_id,agent_id,conversation_id,request_key,source_hash,input_hash,state,capture_enc,lease_epoch,created_epoch,updated_epoch)
            VALUES(?,?,?,?,?,?,?,'preparing',?,?,?,?)")
            ->execute([$id,$owner,$agent,$chat,$request,$sourceHash,$hash,ai_encrypt_secret(json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),time()+180,(int)floor(microtime(true)*1000000),time()]);
        return true;
    });
    if (!$claim) {
        $row=gmail_email_capture_row_v1($pdo,$owner,$agent,$chat,$id);
        if (in_array($row['state'],['preparing','dispatched'],true)&&(int)$row['lease_epoch']<=time()) {
            $pdo->prepare("UPDATE agent_gmail_captures_v1 SET state='failed',reason=?,updated_epoch=? WHERE id=? AND state IN ('preparing','dispatched') AND lease_epoch<=?")->execute([$row['state']==='preparing'?'not_dispatched':'interrupted',time(),$id,time()]);
            $row=gmail_email_capture_row_v1($pdo,$owner,$agent,$chat,$id);
        }
        return gmail_email_capture_public_v1($row);
    }
    $dispatched=false;
    try {
        $route=$model===null?agent_gmail_document_cloud_route_v1($pdo,$user,$agent):null;
        $before=static function()use($pdo,$user,$principal,$chat,$source,$current,$http,$model,$route,$id,$owner,$agent,&$dispatched):bool{
            if ($dispatched) throw new DomainException('This analysis has already been dispatched.',409);
            if ($model===null) agent_gmail_document_cloud_route_v1($pdo,$user,$agent,$route);
            gmail_email_capture_read_v1($pdo,$user,$principal,$chat,$source,$current,$http);
            $q=$pdo->prepare("UPDATE agent_gmail_captures_v1 SET state='dispatched',updated_epoch=? WHERE id=? AND owner_user_id=? AND agent_id=? AND conversation_id=? AND state='preparing' AND lease_epoch>?");
            $q->execute([time(),$id,$owner,$agent,$chat,time()]);
            if ($q->rowCount()!==1) throw new DomainException('The analysis attempt is no longer current.',409);
            return $dispatched=true;
        };
        $zone=is_string($input['timezone']??null)?$input['timezone']:user_calendar_default_timezone_v1300($pdo,$user);
        try {new DateTimeZone($zone);} catch (Throwable) {throw new DomainException('Choose a valid time zone.',422);}
        $prompt=json_encode(['owner_instruction'=>'Analyze only this selected email area. Suggest dates or a product search phrase for review; perform no actions.',
            'selected_text'=>$capture['text'],'mode'=>$capture['mode'],'email'=>['subject'=>$mail['subject'],'from'=>$mail['from'],'received_at_ms'=>$mail['received_at_ms']],
            'current_date'=>(new DateTimeImmutable('now',new DateTimeZone($zone)))->format('Y-m-d'),'timezone'=>$zone],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $control=['surface'=>'tool_json','protocol'=>GMAIL_EMAIL_CAPTURE_PROTOCOL_V1,'gmail_email_capture'=>true,'gmail_capture_input'=>$capture,'before_gmail_capture_dispatch'=>$before];
        $service=['service'=>'conversation','stage'=>GMAIL_EMAIL_CAPTURE_PROTOCOL_V1,'agent_id'=>$agent,'conversation_id'=>$chat,'job_key'=>'gmail-capture:'.$id,'background'=>false];
        $images=$capture['mode']==='photo'?[$capture['image']]:[];
        if ($model) $answer=$model($prompt,$service,$control,$images);
        else {
            require_once __DIR__.'/ai-runtime-v100.php';
            $answer=ai_v100_with_service_context($service,static fn()=>ai_generate_chat_response($prompt,[],[],$user,'main_ai',$control,$images));
        }
        $received=is_string($answer['answer']??null)?$answer['answer']:'';
        // Preserve the response before validation and before any source recheck can fail.
        if ($dispatched&&$received!==''&&strlen($received)<=48000) {
            $pdo->prepare("UPDATE agent_gmail_captures_v1 SET answer_enc=?,updated_epoch=? WHERE id=? AND state='dispatched'")->execute([ai_encrypt_secret($received),time(),$id]);
        }
        if (!$dispatched||empty($answer['ok'])) throw new DomainException('The model did not return a complete analysis. Check AI usage before starting a new attempt.',503);
        $result=gmail_email_capture_result_v1($received);
        gmail_email_capture_read_v1($pdo,$user,$principal,$chat,$source,$current,$http);
        $pdo->prepare("UPDATE agent_gmail_captures_v1 SET state='ready',result_enc=?,lease_epoch=0,updated_epoch=? WHERE id=? AND state='dispatched'")
            ->execute([ai_encrypt_secret(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),time(),$id]);
    } catch (Throwable $e) {
        $reason=in_array((int)$e->getCode(),[401,403,404,409,419],true)?'source_changed':(!$dispatched?'not_dispatched':($e->getCode()===502?'invalid_response':'analysis_unavailable'));
        $pdo->prepare("UPDATE agent_gmail_captures_v1 SET state='failed',reason=?,lease_epoch=0,updated_epoch=? WHERE id=? AND state IN ('preparing','dispatched')")->execute([$reason,time(),$id]);
        throw $e;
    }
    return gmail_email_capture_public_v1(gmail_email_capture_row_v1($pdo,$owner,$agent,$chat,$id));
}

function gmail_email_capture_get_v1(PDO $pdo,array $user,array $principal,int $chat,array $source,string $id,Closure $current,?callable $http=null): array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D',$id)) throw new DomainException('Choose a saved email analysis.',422);
    gmail_email_capture_read_v1($pdo,$user,$principal,$chat,$source,$current,$http);
    if (!gmail_email_capture_ready_v1($pdo)) throw new DomainException('Run Database Upgrade to enable email area analysis.',503);
    $row=gmail_email_capture_row_v1($pdo,(int)$user['id'],(int)($principal['agent_id']??0),$chat,$id);
    if (!$row||!hash_equals($row['source_hash'],gmail_email_capture_source_hash_v1($source,(int)($principal['agent_id']??0),$chat))) throw new DomainException('This analysis belongs to another email selection.',404);
    if (in_array($row['state'],['preparing','dispatched'],true)&&(int)$row['lease_epoch']<=time()) {
        $pdo->prepare("UPDATE agent_gmail_captures_v1 SET state='failed',reason=?,updated_epoch=? WHERE id=? AND state IN ('preparing','dispatched') AND lease_epoch<=?")->execute([$row['state']==='preparing'?'not_dispatched':'interrupted',time(),$id,time()]);
        $row=gmail_email_capture_row_v1($pdo,(int)$user['id'],(int)($principal['agent_id']??0),$chat,$id);
    }
    return gmail_email_capture_public_v1($row);
}

/** Explicit reviewed fields use the existing native mutation kernels, with no inference. */
function gmail_email_capture_save_v1(PDO $pdo,array $user,array $principal,int $chat,array $source,string $action,array $input,Closure $current,?callable $http=null): array
{
    if (!in_array($action,['calendar','reminder'],true)) throw new DomainException('Choose a calendar event or reminder.',422);
    $mail=gmail_email_capture_read_v1($pdo,$user,$principal,$chat,$source,$current,$http);
    $text=gmail_email_capture_text_v1($input['text']??'',6000);
    $fields=$input['fields']??null;
    if (!is_array($fields)||array_is_list($fields)) throw new DomainException('Review the event or reminder details.',422);
    $title=gmail_email_capture_text_v1($fields['title']??'',190);
    if ($title==='') throw new DomainException('Enter an event or reminder title.',422);
    foreach ($fields as $value) if (!is_string($value)||mb_strlen($value)>1000) throw new DomainException('Enter plain-text event or reminder details.',422);
    $zone=gmail_email_capture_text_v1($fields['timezone']??'',80);
    try {new DateTimeZone($zone);} catch (Throwable) {throw new DomainException('Choose a valid time zone.',422);}
    $sourceKey=gmail_email_capture_source_hash_v1($source,(int)($principal['agent_id']??0),$chat);
    $notes='Source email: '.$mail['subject']."\nFrom: ".$mail['from']."\nGmail message: ".$mail['message_id']."\nSelected email text:\n".$text;
    $key=gmail_email_capture_hash_v1([$sourceKey,$action,$title,$zone,$fields,$text]);
    $guard=static function()use($pdo,$user,$principal,$chat,$source,$current):void{
        $current();agent_connection_email_chat_authority_v1($pdo,$user,$principal,$chat);
        $c=agent_connections_require_grant_v1($pdo,(int)$user['id'],$source['connection'],'read',$source['grant']);
        if ($c['client_fingerprint']!==$source['fingerprint']||$c['account_email']!==$source['account']) throw new DomainException('The email account changed.',409);
    };
    if ($action==='calendar') {
        if (!has_permission('account.access',$user)) throw new DomainException('Calendar access is unavailable.',403);
        if (array_diff(array_keys($fields),['title','timezone','date','start_time','end_date','end_time','location'])) throw new DomainException('Review the calendar fields.',422);
        try {user_calendar_local_range_v1300($fields,$zone);} catch (RuntimeException $e) {throw new DomainException($e->getMessage(),422,$e);}
        $event=section12_owner_transaction($pdo,(int)$user['id'],static function()use($pdo,$user,$fields,$title,$zone,$notes,$key,$guard):array{
            $guard();$event=user_calendar_create_local_event_v1300($pdo,$user,array_replace($fields,['title'=>$title,'timezone'=>$zone,'description'=>$notes]),'user',null,'gmail-capture:'.$key);
            $guard();return $event;
        });
        return ['kind'=>'calendar','id'=>(int)$event['id'],'message'=>'Calendar event saved.','source'=>['subject'=>$mail['subject'],'from'=>$mail['from']]];
    }
    if (array_diff(array_keys($fields),['title','timezone','start_local'])) throw new DomainException('Review the reminder fields.',422);
    if (!reminder_ready_v1($pdo)) throw new DomainException('Run Database Upgrade to enable reminders.',503);
    $schedule=['title'=>$title,'timezone'=>$zone,'start_local'=>$fields['start_local']??'','recurrence'=>'none','delivery'=>'both'];
    reminder_schedule_v1($schedule,reminder_now_v1());
    $origin=['connection'=>$source['connection'],'message'=>$source['message'],'thread'=>$source['thread'],'revision'=>$source['revision'],
        'subject'=>$mail['subject'],'from'=>$mail['from'],'selected_text'=>$text];
    $result=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$chat,$key,$schedule,$notes,$guard,$origin):array{
        $guard();$r=reminder_execute_locked_v1($pdo,(int)$user['id'],'cloud:'.$chat,'gmail-capture:'.$key,json_encode([$schedule,$notes],JSON_THROW_ON_ERROR),['operation'=>'create','schedule'=>$schedule],[],$chat,null,$schedule['timezone']);
        // Keep provenance in the canonical receipt, without adding a second reminder ledger.
        if (!isset($r['email_capture_source_enc'])) {
            $r['email_capture_source_enc']=ai_encrypt_secret(json_encode($origin,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            $pdo->prepare('UPDATE agent_reminder_turns_v1 SET result_json=? WHERE owner_user_id=? AND thread_key=? AND request_key=?')
                ->execute([json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),(int)$user['id'],'cloud:'.$chat,'gmail-capture:'.$key]);
        }
        $guard();return $r;
    });
    return ['kind'=>'reminder','message'=>$result['answer'],'source'=>['subject'=>$mail['subject'],'from'=>$mail['from']]];
}
