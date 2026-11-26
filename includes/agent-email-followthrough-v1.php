<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-email-followup-v1.php';
require_once __DIR__.'/agent-connection-email-chat-v1.php';

/** Response deadlines are separate from provider polling, claims and watch retention. */
function agent_email_followthrough_install_v1(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('Install Gmail follow-through outside transactions.');
    $sqlite=agent_commerce_sqlite_pa2($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_email_followthrough_v1 (
        id CHAR(32) PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL, operation_key VARCHAR(190) NOT NULL,
        connection_id BIGINT UNSIGNED NOT NULL, authorization_key VARCHAR(190) NOT NULL,
        client_fingerprint CHAR(64) NOT NULL, account_email VARCHAR(254) NOT NULL,
        agent_id BIGINT UNSIGNED NOT NULL, conversation_id BIGINT UNSIGNED NOT NULL,
        origin_draft_id CHAR(32) NOT NULL, user_message_id BIGINT UNSIGNED NOT NULL,
        deadline_at DATETIME NOT NULL, time_zone VARCHAR(80) NOT NULL, priority VARCHAR(10) NOT NULL,
        prepare_if_overdue SMALLINT NOT NULL DEFAULT 0, revision BIGINT UNSIGNED NOT NULL DEFAULT 1, source_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
        state_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
        outcome VARCHAR(16) NOT NULL DEFAULT 'waiting', paused SMALLINT NOT NULL DEFAULT 0,
        check_state VARCHAR(16) NOT NULL DEFAULT 'never_checked', check_result VARCHAR(12) NULL,
        checked_at DATETIME NULL, evidence_digest CHAR(64) NULL, reply_message_id VARCHAR(190) NULL,
        reply_chat_message_id BIGINT UNSIGNED NULL,
        draft_attempt_key CHAR(32) NULL, draft_attempt_revision BIGINT UNSIGNED NULL,
        draft_attempt_state VARCHAR(16) NULL, draft_attempt_reason VARCHAR(40) NULL, draft_attempt_lease DATETIME NULL,
        generated_draft_id CHAR(32) NULL, generated_revision BIGINT UNSIGNED NULL,
        followup_operation_key VARCHAR(190) NULL, followup_sent_message_id VARCHAR(190) NULL,
        followup_sent_at BIGINT UNSIGNED NULL, followup_draft_id CHAR(32) NULL,
        active_operation_key VARCHAR(190) NULL, active_draft_id CHAR(32) NULL,
        awaiting_new_deadline SMALLINT NOT NULL DEFAULT 0,
        notice_revision BIGINT UNSIGNED NULL, notice_stage VARCHAR(24) NULL, notice_message_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        UNIQUE(owner_user_id,operation_key)".($sqlite ? ')' : ",
        INDEX idx_email_followthrough_scope_v1(owner_user_id,agent_id,conversation_id,outcome,deadline_at),
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin"));
}

function agent_email_followthrough_ready_v1(PDO $pdo): bool
{
    try {$pdo->query('SELECT id,owner_user_id,operation_key,connection_id,authorization_key,client_fingerprint,account_email,
        agent_id,conversation_id,origin_draft_id,user_message_id,deadline_at,time_zone,priority,prepare_if_overdue,revision,source_revision,state_version,
        outcome,paused,check_state,check_result,checked_at,evidence_digest,reply_message_id,reply_chat_message_id,
        draft_attempt_key,draft_attempt_revision,draft_attempt_state,draft_attempt_reason,draft_attempt_lease,generated_draft_id,generated_revision,
        followup_operation_key,followup_sent_message_id,followup_sent_at,followup_draft_id,active_operation_key,active_draft_id,awaiting_new_deadline,
        notice_revision,notice_stage,notice_message_id,created_at,updated_at FROM agent_email_followthrough_v1 WHERE 1=0');return true;}
    catch (PDOException) {return false;}
}

function agent_email_followthrough_settings_v1(mixed $value,bool $future=true): array
{
    if (!is_array($value) || array_diff(array_keys($value),['enabled','deadline_at','time_zone','prepare_if_overdue','priority'])
        || !is_bool($value['enabled']??null) || !is_bool($value['prepare_if_overdue']??null)
        || !is_string($value['time_zone']??null) || strlen($value['time_zone'])>80
        || !in_array($value['time_zone'],DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true)
        || !in_array($value['priority']??null,['low','normal','high'],true)) {
        throw new DomainException('Choose valid Gmail follow-up settings.',422);
    }
    if (!$value['enabled']) {
        if (($value['deadline_at']??null)!==null || $value['prepare_if_overdue']) throw new DomainException('Turn on a deadline before preparing a follow-up.',422);
        return ['enabled'=>false,'deadline_at'=>null,'time_zone'=>$value['time_zone'],'prepare_if_overdue'=>false,'priority'=>$value['priority']];
    }
    $raw=$value['deadline_at']??null;
    if (!is_string($raw) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)\z/D',$raw)) {
        throw new DomainException('Choose an exact response deadline and timezone.',422);
    }
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',str_replace('+00:00','Z',$raw),new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d\TH:i:s\Z')!==str_replace('+00:00','Z',$raw)
        || ($future && ($date->getTimestamp()<=time() || $date->getTimestamp()>time()+30*86400))) {
        throw new DomainException('Choose a future response deadline within 30 days.',422);
    }
    return ['enabled'=>true,'deadline_at'=>$date->format('Y-m-d\TH:i:s\Z'),'time_zone'=>$value['time_zone'],
        'prepare_if_overdue'=>$value['prepare_if_overdue'],'priority'=>$value['priority']];
}

function agent_email_followthrough_iso_v1(?string $at): ?string
{
    return $at===null?null:str_replace(' ','T',$at).'Z';
}

function agent_email_followthrough_authority_v1(PDO $pdo,array $user,array $principal,int $conversation): array
{
    try {return agent_connection_email_chat_authority_v1($pdo,$user,$principal,$conversation);}
    catch (RuntimeException $error) {
        if ($error instanceof PDOException || $error->getCode()!==0) throw $error;
        throw new DomainException('This private Agent Chat is no longer available.',403,$error);
    }
}

function agent_email_followthrough_for_watch_v1(PDO $pdo,array $watch,bool $lock=false): ?array
{
    if (!agent_email_followthrough_ready_v1($pdo) || ($watch['sender_after_ms']??null)!==null) return null;
    $q=$pdo->prepare('SELECT * FROM agent_email_followthrough_v1 WHERE owner_user_id=? AND (operation_key=? OR active_operation_key=?)'
        .($lock?agent_commerce_lock_pa2($pdo):''));
    $q->execute([$watch['owner_user_id'],$watch['operation_key'],$watch['operation_key']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    foreach (['owner_user_id','agent_id','conversation_id','connection_id'] as $field)
        if ((int)$row[$field] !== (int)$watch[$field]) throw new DomainException('This Gmail follow-up changed.',409);
    $active=$row['active_operation_key']??$row['operation_key'];
    $draft=$row['active_draft_id']??$row['origin_draft_id'];
    if ($active!==$watch['operation_key']) return null;
    if ($row['authorization_key']!==$watch['authorization_key'] || $row['account_email']!==$watch['account_email']
        || $draft!==$watch['draft_id']) throw new DomainException('This Gmail follow-up changed.',409);
    return $row;
}

/** Exact current authority, usable by both worker commits and the reviewed-send boundary. */
function agent_email_followthrough_binding_current_v1(PDO $pdo,int $owner,int $agent,int $conversation,array $binding,
    bool $lock=false,bool $requireActive=true): array
{
    if (!is_string($binding['followthrough_id']??null) || !preg_match('/\A[a-f0-9]{32}\z/D',$binding['followthrough_id'])
        || !agent_email_followthrough_ready_v1($pdo)) throw new DomainException('This Gmail follow-up is unavailable.',404);
    $q=$pdo->prepare('SELECT * FROM agent_email_followthrough_v1 WHERE id=? AND owner_user_id=? AND agent_id=? AND conversation_id=?');
    $q->execute([$binding['followthrough_id'],$owner,$agent,$conversation]);$first=$q->fetch(PDO::FETCH_ASSOC);
    if (!$first) throw new DomainException('Open the original private Chat for this Gmail follow-up.',404);
    // Connection -> owner/Agent/Chat -> native watch -> deadline is the shared lock order.
    $connection=agent_connections_require_grant_v1($pdo,$owner,(int)$first['connection_id'],'followups',$first['authorization_key']);
    $user=initiative_work_product_scope_v1($pdo,$owner,['agent_id'=>$agent,'conversation_id'=>$conversation],$lock?agent_commerce_lock_pa2($pdo):'');
    if ($connection['account_email']!==$first['account_email'] || $connection['client_fingerprint']!==$first['client_fingerprint']
        || !agent_connection_gmail_ready_v1()) throw new DomainException('Gmail access changed. Refresh this follow-up.',409);
    $q=$pdo->prepare('SELECT * FROM agent_connection_email_followups_v1 WHERE owner_user_id=? AND operation_key=?'
        .($lock?agent_commerce_lock_pa2($pdo):''));$q->execute([$owner,$first['active_operation_key']??$first['operation_key']]);$watch=$q->fetch(PDO::FETCH_ASSOC);
    if (!$watch || !agent_connection_email_followup_origin_v1($pdo,$watch,$lock)) throw new DomainException('The original Gmail watch changed.',409);
    $row=agent_email_followthrough_for_watch_v1($pdo,$watch,$lock);
    if (!$row || $row['id']!==$first['id'] || $row['client_fingerprint']!==$connection['client_fingerprint']) throw new DomainException('This Gmail follow-up changed.',409);
    $proof=$pdo->prepare("SELECT 1 FROM agent_connection_email_drafts_v1 d JOIN agent_connection_sends_v1 s
        ON s.owner_user_id=d.owner_user_id AND s.connection_id=d.connection_id AND s.authorization_key=d.authorization_key
        WHERE d.id=? AND d.owner_user_id=? AND d.agent_id=? AND d.conversation_id=? AND d.status='sent'
        AND d.sent_message_id=? AND s.operation_key=? AND s.status='sent' AND s.provider_message_id=d.sent_message_id
        AND s.provider_thread_id=? AND s.sent_at>0");
    $proof->execute([$watch['draft_id'],$owner,$agent,$conversation,$watch['sent_message_id'],$watch['operation_key'],$watch['thread_id']]);
    if (!$proof->fetchColumn()) throw new DomainException('The exact Gmail send receipt is unavailable.',409);
    foreach (['operation_key'=>'operation_key','message_id'=>'sent_message_id','thread_id'=>'thread_id'] as $given=>$field)
        if (isset($binding[$given]) && $binding[$given]!==$watch[$field]) throw new DomainException('The original Gmail message changed.',409);
    $q=$pdo->prepare("SELECT id FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'"
        .($lock?agent_commerce_lock_pa2($pdo):''));$q->execute([(int)$row['user_message_id'],$conversation,$owner]);
    if (!$q->fetchColumn()) throw new DomainException('The original deadline request is unavailable.',403);
    if ($requireActive && ((int)($binding['revision']??0)!==(int)$row['source_revision'] || $row['paused']
        || $row['outcome']!=='overdue' || $row['awaiting_new_deadline'] || $row['check_state']!=='verified' || $row['check_result']!=='no_reply'
        || $row['checked_at']===null || $row['checked_at']<$row['deadline_at'] || $watch['status']!=='waiting'
        || $watch['expires_at']<=agent_commerce_time_pa2())) throw new DomainException('This follow-up needs a current deadline and thread check.',409);
    $principal=['kind'=>$agent>0?'user_agent':'system','agent_id'=>$agent,'owner_user_id'=>$owner,'viewer_user_id'=>$owner];
    return ['record'=>$row,'row'=>$row,'watch'=>$watch,'connection'=>$connection,'user'=>$user,'principal'=>$principal];
}

function agent_email_followthrough_current_v1(PDO $pdo,array $expected,string $claim,bool $lock=false): array
{
    $current=agent_email_followthrough_binding_current_v1($pdo,(int)$expected['owner_user_id'],(int)$expected['agent_id'],
        (int)$expected['conversation_id'],['followthrough_id'=>$expected['id'],'revision'=>(int)$expected['source_revision']],$lock);
    $watch=$current['watch'];
    if ($claim==='' || $watch['claim_key']!==$claim || $watch['next_check_at']<=agent_commerce_time_pa2()
        || (int)$current['record']['revision']!==(int)$expected['revision']
        || $current['record']['deadline_at']!==$expected['deadline_at'] || (int)$current['record']['user_message_id']!==(int)$expected['user_message_id']) {
        throw new DomainException('The Gmail follow-up claim changed.',409);
    }
    return $current;
}

function agent_email_followthrough_extend_claim_v1(PDO $pdo,array $row,string $claim,int $seconds=240): array
{
    if (!$pdo->inTransaction() || $seconds<120 || $seconds>240) throw new LogicException('Extend a current Gmail claim inside its transaction.');
    $current=agent_email_followthrough_current_v1($pdo,$row,$claim,true);
    $until=min(gmdate('Y-m-d H:i:s',time()+$seconds),$current['watch']['expires_at']);
    $pdo->prepare('UPDATE agent_connection_email_followups_v1 SET next_check_at=? WHERE owner_user_id=? AND operation_key=? AND claim_key=?')
        ->execute([$until,$row['owner_user_id'],$current['watch']['operation_key'],$claim]);
    $current['watch']['next_check_at']=$until;return $current;
}

/** Called only after the canonical ledger and native watch have verified this send. */
function agent_email_followthrough_register_v1(PDO $pdo,array $draft,array $receipt,array $settings): ?array
{
    if (!agent_email_followthrough_ready_v1($pdo) || empty($settings['enabled'])) return null;
    if (!$pdo->inTransaction()) throw new LogicException('Register response deadlines with the verified send.');
    $config=agent_email_followthrough_settings_v1(array_intersect_key($settings,array_flip(['enabled','deadline_at','time_zone','prepare_if_overdue','priority'])),false);
    $owner=(int)$draft['owner_user_id'];$operation=agent_connection_email_chat_operation_key_v1($draft['id']);
    $q=$pdo->prepare("SELECT 1 FROM agent_connection_sends_v1 WHERE owner_user_id=? AND connection_id=? AND authorization_key=?
        AND operation_key=? AND status='sent' AND provider_message_id=? AND provider_thread_id=? AND sent_at=?");
    $q->execute([$owner,$draft['connection_id'],$draft['authorization_key'],$operation,$receipt['message_id']??'',
        $receipt['thread_id']??'',(int)($receipt['sent_at']??0)]);
    if (!$q->fetchColumn() || ($receipt['operation_key']??'')!==$operation || ($receipt['from']??'')!==$draft['account_email']
        || ($receipt['to']??'')!==$draft['recipient']) throw new DomainException('The original send receipt changed.',409);
    $q=$pdo->prepare('SELECT * FROM agent_connection_email_followups_v1 WHERE owner_user_id=? AND operation_key=?'.agent_commerce_lock_pa2($pdo));
    $q->execute([$owner,$operation]);$watch=$q->fetch(PDO::FETCH_ASSOC);
    if (!$watch || $watch['status']!=='waiting' || $watch['draft_id']!==$draft['id'] || $watch['sent_message_id']!==($receipt['message_id']??'')
        || $watch['thread_id']!==($receipt['thread_id']??'') || (int)$watch['agent_id']!==(int)$draft['agent_id']
        || (int)$watch['conversation_id']!==(int)$draft['conversation_id'] || ($watch['sender_after_ms']??null)!==null
        || $watch['authorization_key']!==$draft['authorization_key'] || (int)$watch['connection_id']!==(int)$draft['connection_id']
        || $watch['account_email']!==$draft['account_email'] || $watch['recipient_email']!==$draft['recipient']) {
        throw new DomainException('The verified Gmail reply watch is unavailable.',409);
    }
    $existing=agent_email_followthrough_for_watch_v1($pdo,$watch,true);if ($existing) return $existing;
    $deadline=str_replace(['T','Z'],[' ',''],$config['deadline_at']);
    $marker=$settings['user_message_id']??null;
    if (!is_int($marker) || $marker<1 || $marker===(int)$draft['user_message_id'] || $deadline>$watch['expires_at']
        || $deadline<=gmdate('Y-m-d H:i:s',(int)$receipt['sent_at'])) {
        throw new DomainException('Choose a deadline within this thread watch and its original owner request.',422);
    }
    $connection=agent_connections_require_grant_v1($pdo,$owner,(int)$draft['connection_id'],'followups',$draft['authorization_key']);
    if (!agent_connection_email_followup_origin_v1($pdo,$watch,true)) throw new DomainException('The original private Chat changed.',403);
    $q=$pdo->prepare("SELECT id FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'".agent_commerce_lock_pa2($pdo));
    $q->execute([$marker,$watch['conversation_id'],$owner]);if (!$q->fetchColumn()) throw new DomainException('The deadline request is unavailable.',403);
    $id=bin2hex(random_bytes(16));$now=agent_commerce_time_pa2();
    $pdo->prepare('INSERT INTO agent_email_followthrough_v1 (id,owner_user_id,operation_key,connection_id,authorization_key,client_fingerprint,account_email,
        agent_id,conversation_id,origin_draft_id,user_message_id,deadline_at,time_zone,priority,prepare_if_overdue,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$owner,$operation,$connection['id'],$connection['authorization_key'],
        $connection['client_fingerprint'],$connection['account_email'],$draft['agent_id'],$draft['conversation_id'],$draft['id'],$marker,
        $deadline,$config['time_zone'],$config['priority'],(int)$config['prepare_if_overdue'],$now,$now]);
    return agent_email_followthrough_for_watch_v1($pdo,$watch);
}

/** Null matching is not absence evidence: an exact, well-ordered sent anchor is mandatory. */
function agent_email_followthrough_evidence_v1(array $watch,array $messages,?int $checkedAt=null): array
{
    $checkedAt??=time();$base=['state'=>'unavailable','result'=>null,'checked_at'=>gmdate(DATE_ATOM,$checkedAt),'digest'=>null,'anchor'=>null,'reply'=>null];
    if (($watch['sender_after_ms']??null)!==null || !$messages || !array_is_list($messages) || count($messages)>500) return $base;
    $seen=[];$anchor=null;$last=0;
    foreach ($messages as $message) {
        if (!is_array($message) || agent_connection_gmail_id_v1($message['message_id']??null)===''
            || ($message['thread_id']??null)!==($watch['thread_id']??null) || isset($seen[$message['message_id']])
            || !is_int($message['received_at_ms']??null) || $message['received_at_ms']<1
            || $message['received_at_ms']>($checkedAt+1)*1000 || $message['received_at_ms']<$last
            || !is_array($message['labels']??null) || !array_is_list($message['labels'])) return $base;
        foreach (['from','to','rfc_message_id','in_reply_to','references','authentication_results'] as $field)
            if (!is_string($message[$field]??null)) return $base;
        $seen[$message['message_id']]=true;$last=$message['received_at_ms'];
        if ($message['message_id']===($watch['sent_message_id']??null)) $anchor=$message;
    }
    if (!$anchor || !in_array('SENT',$anchor['labels'],true) || array_intersect(['DRAFT','SPAM','TRASH'],$anchor['labels'])
        || agent_connection_gmail_address_v1($anchor['from'])!==($watch['account_email']??null)
        || agent_connection_gmail_address_v1($anchor['to'])!==($watch['recipient_email']??null)
        || !agent_connection_gmail_reply_rfc_id_v1($anchor['rfc_message_id'])) return $base;
    $base['anchor']=$anchor;$base['digest']=hash('sha256',json_encode($messages,JSON_THROW_ON_ERROR));
    $reply=agent_connection_email_followup_match_v1($watch,$messages);
    if ($reply!==null) return array_replace($base,['state'=>'verified','result'=>'reply','reply'=>$reply]);
    foreach ($messages as $candidate) {
        if ($candidate['message_id']===$anchor['message_id'] || $candidate['received_at_ms']<$anchor['received_at_ms']) continue;
        // Even an unmatched received message or a later manual send makes an automatic nudge unsafe.
        if (array_intersect(['DRAFT','SPAM','TRASH'],$candidate['labels'])) continue;
        return array_replace($base,['state'=>'ambiguous']);
    }
    return array_replace($base,['state'=>'verified','result'=>'no_reply']);
}

/** Commit only under the same native watch claim; provider work happened outside the transaction. */
function agent_email_followthrough_observe_v1(PDO $pdo,array $watch,string $claim,array $evidence,?callable $http=null,bool $prepare=true): ?array
{
    $expected=agent_email_followthrough_for_watch_v1($pdo,$watch);if (!$expected) return null;
    $row=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$watch,$claim,$evidence,$expected):?array {
        $current=agent_email_followthrough_binding_current_v1($pdo,(int)$expected['owner_user_id'],(int)$expected['agent_id'],
            (int)$expected['conversation_id'],['followthrough_id'=>$expected['id']],true,false);
        $row=$current['record'];$live=$current['watch'];$now=agent_commerce_time_pa2();
        if ((int)$row['revision']!==(int)$expected['revision'] || $row['paused'] || $row['outcome']==='resolved'
            || $live['status']!=='waiting' || $live['claim_key']!==$claim || $live['next_check_at']<=$now || $live['expires_at']<=$now) return null;
        $state=$evidence['state']??'unavailable';$result=$evidence['result']??null;
        if (!in_array($state,['verified','ambiguous','unavailable'],true) || !in_array($result,[null,'reply','no_reply'],true)
            || ($state==='verified')!==in_array($result,['reply','no_reply'],true)) throw new LogicException('Invalid Gmail follow-up evidence.');
        $checked=$now;$checkTime=$evidence['checked_at']??null;
        if ($state!=='unavailable' || $checkTime!==null) {
            if (!is_string($checkTime) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)\z/D',$checkTime)) {
                throw new DomainException('The Gmail check timestamp could not be verified.',409);
            }
            $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',str_replace('+00:00','Z',$checkTime),new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d\TH:i:s\Z')!==str_replace('+00:00','Z',$checkTime) || $date->format('Y-m-d H:i:s')>$now) {
                throw new DomainException('The Gmail check timestamp could not be verified.',409);
            }
            $checked=$date->format('Y-m-d H:i:s');
        }
        $outcome=$result==='reply'?'replied':($row['outcome']==='replied'?'replied':($result==='no_reply'&&!$row['awaiting_new_deadline']&&$row['deadline_at']<=$checked?'overdue':'waiting'));
        $pdo->prepare('UPDATE agent_email_followthrough_v1 SET state_version=state_version+1,outcome=?,check_state=?,check_result=?,checked_at=?,evidence_digest=?,reply_message_id=?,updated_at=? WHERE id=? AND revision=?')
            ->execute([$outcome,$state,$result,$checked,$evidence['digest']??null,$evidence['reply']['message_id']??$row['reply_message_id'],$now,$row['id'],$row['revision']]);
        return agent_email_followthrough_for_watch_v1($pdo,$live);
    });
    if ($prepare && $row && $row['outcome']==='overdue' && $row['check_state']==='verified' && $row['check_result']==='no_reply') {
        $file=__DIR__.'/agent-email-followthrough-draft-v1.php';if (is_file($file)) require_once $file;
        try {
            if ($row['prepare_if_overdue'] && function_exists('agent_email_followthrough_prepare_v1'))
                agent_email_followthrough_prepare_v1($pdo,$row,$watch,$evidence,$claim,$http);
            if (function_exists('agent_email_followthrough_notice_v1')) agent_email_followthrough_notice_v1($pdo,$row,$watch,$claim);
        } catch (Throwable $error) {
            if (function_exists('agent_runtime_v125_trace')) agent_runtime_v125_trace('gmail.followthrough.preparation_unavailable',['error_class'=>get_class($error)]);
        }
    }
    return $row;
}

function agent_email_followthrough_public_v1(PDO $pdo,array $row): array
{
    $current=agent_email_followthrough_binding_current_v1($pdo,(int)$row['owner_user_id'],(int)$row['agent_id'],(int)$row['conversation_id'],
        ['followthrough_id'=>$row['id']],false,false);$row=$current['record'];$watch=$current['watch'];$connection=$current['connection'];
    $subject='';
    $draft=agent_connection_email_chat_draft_v1($pdo,(int)$row['owner_user_id'],(int)$row['agent_id'],(int)$row['conversation_id'],$row['origin_draft_id']);
    try {$subject=mb_substr(agent_connection_email_chat_payload_v1($draft)['subject'],0,200);} catch (DomainException) {}
    $open=!in_array($row['outcome'],['replied','resolved'],true);$paused=(bool)$row['paused'];
    $monitoring=in_array($watch['status'],['waiting','paused'],true) && $watch['expires_at']>agent_commerce_time_pa2()
        && (!$row['awaiting_new_deadline'] || $row['active_operation_key']!==null);
    $replyTarget=null;$check=['state'=>$row['check_state'],'result'=>$row['check_result'],'checked_at'=>agent_email_followthrough_iso_v1($row['checked_at'])];
    if ((int)$row['reply_chat_message_id']>0) {
        $replyId=(int)$row['reply_chat_message_id'];
        $proof=agent_connection_email_followup_delivered_v1($pdo,$connection,(int)$row['agent_id'],(int)$row['conversation_id'],[$replyId]);
        if (($proof[$replyId]['message_id']??null)===$row['reply_message_id'] && ($proof[$replyId]['kind']??null)==='thread'
            && ($proof[$replyId]['sender']??null)===$watch['recipient_email']) {
            $replyTarget=['agent_id'=>(int)$row['agent_id'],'conversation_id'=>(int)$row['conversation_id'],'message_id'=>$replyId];
        } elseif ($row['check_result']==='reply') {
            $check=['state'=>'unavailable','result'=>null,'checked_at'=>$check['checked_at']];
        }
    }
    $target=static fn(string $id):array=>['agent_id'=>(int)$row['agent_id'],'conversation_id'=>(int)$row['conversation_id'],'draft_id'=>$id];
    $result=['followthrough_ref'=>$row['id'],'revision'=>(int)$row['revision'],'state_version'=>(int)$row['state_version'],'mailbox_revision'=>hash('sha256',json_encode(['vp3-gmail-mailbox-v1',
            (int)$connection['id'],$connection['authorization_key'],$connection['client_fingerprint'],$connection['account_email']],JSON_THROW_ON_ERROR)),
        'subject'=>$subject,'recipient'=>$watch['recipient_email'],'agent_id'=>(int)$row['agent_id'],'conversation_id'=>(int)$row['conversation_id'],
        'outcome'=>$row['outcome'],'paused'=>$paused,'awaiting_new_deadline'=>(bool)$row['awaiting_new_deadline'],
        'followup_watch_registered'=>$row['followup_operation_key']!==null && $row['active_operation_key']===$row['followup_operation_key']
            && in_array($watch['status'],['waiting','paused','delivered'],true) && $watch['expires_at']>agent_commerce_time_pa2(),
        'deadline_at'=>agent_email_followthrough_iso_v1($row['deadline_at']),
        'time_zone'=>$row['time_zone'],'prepare_if_overdue'=>(bool)$row['prepare_if_overdue'],'priority'=>$row['priority'],
        'check'=>$check,
        'draft_target'=>$row['generated_draft_id']!==null?$target($row['generated_draft_id']):null,
        'reply_target'=>$replyTarget,
        'capabilities'=>['change_deadline'=>$open&&$monitoring,'pause'=>$open&&$monitoring&&!$paused,
            'resume'=>$open&&$monitoring&&$paused,'resolve'=>$row['outcome']!=='resolved','check'=>$open&&$monitoring&&!$paused]];
    $status=__DIR__.'/agent-email-followthrough-status-v1.php';
    if (is_file($status)) {
        require_once $status;
        $result['preparation']=agent_email_followthrough_preparation_v1($pdo,$row);
    }
    $last=agent_email_followthrough_binding_current_v1($pdo,(int)$row['owner_user_id'],(int)$row['agent_id'],(int)$row['conversation_id'],
        ['followthrough_id'=>$row['id']],false,false);
    if ((int)$last['record']['state_version']!==(int)$row['state_version']
        || $last['connection']['authorization_key']!==$connection['authorization_key']
        || $last['connection']['account_email']!==$connection['account_email'] || $last['connection']['client_fingerprint']!==$connection['client_fingerprint']) {
        throw new DomainException('This Gmail follow-up changed. Refresh it.',409);
    }
    // Metadata projection has no write, poll, model or approval side effects.
    return $result;
}

function agent_email_followthrough_request_v1(PDO $pdo,array $user,array $principal,int $conversation,array $input): array
{
    agent_email_followthrough_authority_v1($pdo,$user,$principal,$conversation);
    $ref=$input['followthrough_ref']??null;$revision=$input['expected_revision']??null;
    if (!is_string($ref) || !preg_match('/\A[a-f0-9]{32}\z/D',$ref) || !is_int($revision) || $revision<1) throw new DomainException('Choose a current Gmail follow-up.',422);
    $current=agent_email_followthrough_binding_current_v1($pdo,(int)$user['id'],(int)($principal['agent_id']??0),$conversation,['followthrough_id'=>$ref],false,false);
    if ((int)$current['record']['revision']!==$revision) throw new DomainException('This Gmail follow-up changed. Refresh it.',409);
    return $current;
}

/** A short-lived private browsing reference; it cannot authorize a Gmail send or control. */
function agent_email_followthrough_cursor_v1(string $key,array $scope,array $after,int $expires): string
{
    if ($key==='') throw new LogicException('A private Gmail follow-up session is required.');
    $body=rtrim(strtr(base64_encode(ai_encrypt_secret(json_encode(['version'=>1,'scope'=>$scope,'after'=>$after,'expires'=>$expires],
        JSON_THROW_ON_ERROR))),'+/','-_'),'=');
    $cursor=$body.'.'.hash_hmac('sha256','vp3-gmail-followthrough-page-v1:'.$body,$key);
    if (strlen($cursor)>2048) throw new LogicException('The Gmail follow-up cursor exceeded its bound.');
    return $cursor;
}

function agent_email_followthrough_cursor_open_v1(string $key,array $scope,string $cursor,int $now): array
{
    $invalid=static fn()=>new DomainException('Refresh the Gmail follow-up list to continue.',409);
    if ($key==='' || strlen($cursor)>2048 || !preg_match('/\A([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D',$cursor,$parts)
        || !hash_equals(hash_hmac('sha256','vp3-gmail-followthrough-page-v1:'.$parts[1],$key),$parts[2])) throw $invalid();
    $body=base64_decode(strtr($parts[1],'-_','+/'),true);
    $value=$body===false?null:json_decode(ai_decrypt_secret($body),true);
    if (!is_array($value) || array_keys($value)!==['version','scope','after','expires'] || $value['version']!==1
        || $value['scope']!==$scope || !is_int($value['expires']) || $value['expires']<=$now || $value['expires']>$now+900
        || !is_array($value['after']) || !array_is_list($value['after']) || count($value['after'])!==2
        || !is_string($value['after'][0]) || !preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D',$value['after'][0])
        || !is_string($value['after'][1]) || !preg_match('/\A[a-f0-9]{32}\z/D',$value['after'][1])) throw $invalid();
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value['after'][0],new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d H:i:s')!==$value['after'][0]) throw $invalid();
    return $value;
}

function agent_email_followthrough_get_v1(PDO $pdo,array $user,array $principal,int $conversation,array $input,string $key): array
{
    agent_email_followthrough_authority_v1($pdo,$user,$principal,$conversation);
    if (!agent_email_followthrough_ready_v1($pdo)) throw new DomainException('Run Database Upgrade for Gmail follow-through.',503);
    if (array_keys($input)!==['followthrough_ref'] || !is_string($input['followthrough_ref'])
        || !preg_match('/\A[a-f0-9]{32}\z/D',$input['followthrough_ref'])) throw new DomainException('Choose a saved Gmail follow-up.',422);
    $scope=agent_email_followthrough_binding_current_v1($pdo,(int)$user['id'],(int)($principal['agent_id']??0),$conversation,
        ['followthrough_id'=>$input['followthrough_ref']],false,false);
    $item=agent_email_followthrough_public_v1($pdo,$scope['record']);
    agent_email_followthrough_authority_v1($pdo,$user,$principal,$conversation);
    $last=agent_email_followthrough_binding_current_v1($pdo,(int)$user['id'],(int)($principal['agent_id']??0),$conversation,
        ['followthrough_id'=>$input['followthrough_ref']],false,false);
    if ((int)$last['record']['state_version']!==$item['state_version'] || $last['connection']['authorization_key']!==$scope['connection']['authorization_key']
        || $last['connection']['account_email']!==$scope['connection']['account_email'] || $last['connection']['client_fingerprint']!==$scope['connection']['client_fingerprint']) {
        throw new DomainException('This Gmail follow-up changed. Open it again.',409);
    }
    return $item;
}

function agent_email_followthrough_list_v1(PDO $pdo,array $user,array $principal,int $conversation,string $key,array $input=[]): array
{
    agent_email_followthrough_authority_v1($pdo,$user,$principal,$conversation);
    $limit=$input['limit']??10;$filter=$input['filter']??'open';$cursor=$input['cursor']??null;
    if (array_diff(array_keys($input),['limit','filter','cursor']) || !is_int($limit) || $limit<1 || $limit>20 || !in_array($filter,['open','all'],true)
        || ($cursor!==null && (!is_string($cursor) || $cursor===''))) throw new DomainException('Choose a valid follow-up view.',422);
    if (!agent_email_followthrough_ready_v1($pdo)) throw new DomainException('Run Database Upgrade for Gmail follow-through.',503);
    $owner=(int)$user['id'];$agent=(int)($principal['agent_id']??0);
    $connection=agent_connections_find_v1($pdo,$owner,'gmail');if (!$connection) throw new DomainException('Connect Gmail to view follow-ups.',401);
    $connection=agent_connections_require_grant_v1($pdo,$owner,(int)$connection['id'],'followups',$connection['authorization_key']);
    $mailboxRevision=hash('sha256',json_encode(['vp3-gmail-mailbox-v1',(int)$connection['id'],$connection['authorization_key'],$connection['client_fingerprint'],$connection['account_email']],JSON_THROW_ON_ERROR));
    $cursorScope=[$owner,$agent,$conversation,$mailboxRevision,$filter,$limit];$now=time();
    $paging=$cursor===null?null:agent_email_followthrough_cursor_open_v1($key,$cursorScope,$cursor,$now);
    $joined=" FROM agent_email_followthrough_v1 f
        JOIN agent_connection_email_followups_v1 w ON w.owner_user_id=f.owner_user_id AND w.operation_key=COALESCE(f.active_operation_key,f.operation_key)
        AND w.connection_id=f.connection_id AND w.authorization_key=f.authorization_key AND w.account_email=f.account_email
        AND w.agent_id=f.agent_id AND w.conversation_id=f.conversation_id AND w.sender_after_ms IS NULL
        JOIN agent_connection_email_drafts_v1 d ON d.id=COALESCE(f.active_draft_id,f.origin_draft_id) AND d.id=w.draft_id
        AND d.owner_user_id=f.owner_user_id AND d.agent_id=f.agent_id AND d.conversation_id=f.conversation_id
        AND d.connection_id=f.connection_id AND d.authorization_key=f.authorization_key AND d.status='sent' AND d.sent_message_id=w.sent_message_id
        JOIN agent_connection_sends_v1 s ON s.owner_user_id=f.owner_user_id AND s.connection_id=f.connection_id AND s.authorization_key=f.authorization_key
        AND s.operation_key=w.operation_key AND s.status='sent' AND s.provider_message_id=w.sent_message_id AND s.provider_thread_id=w.thread_id AND s.sent_at>0
        JOIN chat_messages m ON m.id=f.user_message_id AND m.conversation_id=f.conversation_id AND m.user_id=f.owner_user_id AND m.role='user'
        WHERE f.owner_user_id=? AND f.agent_id=? AND f.conversation_id=? AND f.connection_id=? AND f.authorization_key=?";
    $where=$filter==='open'?" AND f.outcome NOT IN ('replied','resolved')":'';
    $params=[$owner,$agent,$conversation,$connection['id'],$connection['authorization_key']];
    if ($paging!==null) {
        $where.=' AND (f.deadline_at>? OR (f.deadline_at=? AND f.id>?))';
        $params=[...$params,$paging['after'][0],$paging['after'][0],$paging['after'][1]];
    }
    $q=$pdo->prepare('SELECT f.*'.$joined.$where.' ORDER BY f.deadline_at,f.id LIMIT '.($limit+1));
    $q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $items=[];foreach (array_slice($rows,0,$limit) as $row) $items[]=agent_email_followthrough_public_v1($pdo,$row);
    $q=$pdo->prepare('SELECT f.outcome,f.paused,COUNT(*) AS total'.$joined.' GROUP BY f.outcome,f.paused');
    $q->execute([$owner,$agent,$conversation,$connection['id'],$connection['authorization_key']]);
    $counts=array_fill_keys(['waiting','overdue','replied','resolved','paused'],0);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $count) {if (isset($counts[$count['outcome']])) $counts[$count['outcome']]+=(int)$count['total'];if ($count['paused']) $counts['paused']+=(int)$count['total'];}
    agent_email_followthrough_authority_v1($pdo,$user,$principal,$conversation);
    $last=agent_connections_require_grant_v1($pdo,$owner,(int)$connection['id'],'followups',$connection['authorization_key']);
    if ($last['account_email']!==$connection['account_email'] || $last['client_fingerprint']!==$connection['client_fingerprint']) throw new DomainException('Gmail access changed. Refresh follow-ups.',409);
    $more=count($rows)>$limit;$lastRow=$more?$rows[$limit-1]:null;
    $next=$lastRow!==null?agent_email_followthrough_cursor_v1($key,$cursorScope,[$lastRow['deadline_at'],$lastRow['id']],$paging['expires']??$now+900):null;
    return ['state'=>'ready','scope'=>'current_private_chat','coverage'=>'tracked_sends_in_this_private_chat','items'=>$items,'counts'=>$counts,
        'mailbox_revision'=>$mailboxRevision,'next_cursor'=>$next,'has_more'=>$more,'checked_at'=>gmdate(DATE_ATOM)];
}

function agent_email_followthrough_update_v1(PDO $pdo,array $user,array $principal,int $conversation,array $input,string $key): array
{
    $deadlineFields=['deadline_at','time_zone','prepare_if_overdue','priority'];
    if (array_diff(array_keys($input),['followthrough_ref','expected_revision','operation',...$deadlineFields]) || !in_array($input['operation']??null,['deadline','pause','resume','resolve'],true)
        || ($input['operation']!=='deadline' && array_intersect(array_keys($input),$deadlineFields))) throw new DomainException('Choose a valid follow-up control.',422);
    $before=agent_email_followthrough_request_v1($pdo,$user,$principal,$conversation,$input);$operation=$input['operation'];
    $settings=$operation==='deadline'?agent_email_followthrough_settings_v1(['enabled'=>true]+array_intersect_key($input,array_flip($deadlineFields))):null;
    if ($settings!==null && !$settings['enabled']) throw new DomainException('Use Pause or Resolve to stop this follow-up.',422);
    $row=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$principal,$conversation,$input,$before,$operation,$settings):array {
        $current=agent_email_followthrough_binding_current_v1($pdo,(int)$user['id'],(int)($principal['agent_id']??0),$conversation,['followthrough_id'=>$input['followthrough_ref']],true,false);
        $row=$current['record'];$watch=$current['watch'];$now=agent_commerce_time_pa2();
        if ((int)$row['revision']!==$input['expected_revision'] || $row['outcome']==='resolved') throw new DomainException('This follow-up changed. Refresh it.',409);
        if ($operation!=='resolve' && $row['outcome']==='replied') throw new DomainException('A reply was already observed for this email.',409);
        if ($operation!=='resolve' && (!in_array($watch['status'],['waiting','paused'],true) || $watch['expires_at']<=$now
            || ($row['awaiting_new_deadline'] && $row['active_operation_key']===null))) {
            throw new DomainException('Reply monitoring ended for this email. You can resolve this follow-up.',409);
        }
        $paused=(bool)$row['paused'];$outcome=$row['outcome'];$marker=(int)$row['user_message_id'];$deadline=$row['deadline_at'];
        if ($operation==='pause') $paused=true;
        if ($operation==='resume') {if (!$paused) throw new DomainException('This follow-up is already active.',409);$paused=false;$outcome='waiting';}
        if ($operation==='resolve') {$paused=false;$outcome='resolved';}
        if ($operation==='deadline') {
            $deadline=str_replace(['T','Z'],[' ',''],$settings['deadline_at']);
            if ($deadline>$watch['expires_at']) throw new DomainException('Choose a deadline within this thread watch.',422);
            $context=json_encode(['source'=>'agent_email_followthrough_deadline','skip_brain_archive'=>true],JSON_THROW_ON_ERROR);
            $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json,created_at) VALUES (?,?,'user',?,?,?)")
                ->execute([$conversation,$user['id'],'Set the response deadline for this Gmail follow-up.',$context,$now]);
            $marker=(int)$pdo->lastInsertId();$paused=false;$outcome='waiting';
            $pdo->prepare('UPDATE chat_conversations SET updated_at=? WHERE id=? AND user_id=?')->execute([$now,$conversation,$user['id']]);
        }
        // Every owner control cancels in-flight work; only a new deadline marker grants another preparation attempt.
        $revision=(int)$row['revision']+1;
        $pdo->prepare("UPDATE agent_email_followthrough_v1 SET state_version=state_version+1,revision=?,source_revision=?,paused=?,outcome=?,deadline_at=?,user_message_id=?,time_zone=?,priority=?,prepare_if_overdue=?,
            check_state='never_checked',check_result=NULL,checked_at=NULL,evidence_digest=NULL,awaiting_new_deadline=?,updated_at=? WHERE id=? AND revision=?")
            ->execute([$revision,$operation==='deadline'?$revision:$row['source_revision'],(int)$paused,$outcome,$deadline,$marker,$settings['time_zone']??$row['time_zone'],$settings['priority']??$row['priority'],
                (int)($settings['prepare_if_overdue']??$row['prepare_if_overdue']),$operation==='deadline'?0:$row['awaiting_new_deadline'],$now,$row['id'],$row['revision']]);
        $status=$outcome==='resolved'?'stopped':($paused?'paused':'waiting');
        $pdo->prepare('UPDATE agent_connection_email_followups_v1 SET status=?,claim_key=NULL,next_check_at=? WHERE owner_user_id=? AND operation_key=?')
            ->execute([$status,$status==='waiting'?$now:null,$row['owner_user_id'],$watch['operation_key']]);
        // A send confirmed after a newer deadline can retain a separate exact reply watch.
        // Stopping tracking also fences that receipt-linked watch, without adopting its old source revision.
        if (($paused || $outcome==='resolved') && $row['followup_operation_key']!==null
            && $row['followup_operation_key']!==$watch['operation_key']) {
            $pdo->prepare("UPDATE agent_connection_email_followups_v1 SET status=?,claim_key=NULL,next_check_at=NULL
                WHERE owner_user_id=? AND operation_key=? AND draft_id=? AND sent_message_id=? AND thread_id=?
                AND connection_id=? AND authorization_key=? AND account_email=? AND agent_id=? AND conversation_id=?
                AND recipient_email=? AND sender_after_ms IS NULL AND status IN ('waiting','paused')")
                ->execute([$paused?'paused':'stopped',$row['owner_user_id'],$row['followup_operation_key'],$row['followup_draft_id'],
                    $row['followup_sent_message_id'],$watch['thread_id'],$row['connection_id'],$row['authorization_key'],
                    $row['account_email'],$row['agent_id'],$row['conversation_id'],$watch['recipient_email']]);
        }
        return agent_email_followthrough_for_watch_v1($pdo,$watch);
    });
    return agent_email_followthrough_public_v1($pdo,$row);
}

function agent_email_followthrough_check_v1(PDO $pdo,array $user,array $principal,int $conversation,array $input,string $key,?callable $http=null): array
{
    if (array_diff(array_keys($input),['followthrough_ref','expected_revision'])) throw new DomainException('Choose a valid follow-up check.',422);
    agent_email_followthrough_request_v1($pdo,$user,$principal,$conversation,$input);
    $claim=bin2hex(random_bytes(16));
    $watch=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$principal,$conversation,$input,$claim):array {
        $current=agent_email_followthrough_binding_current_v1($pdo,(int)$user['id'],(int)($principal['agent_id']??0),$conversation,
            ['followthrough_id'=>$input['followthrough_ref']],true,false);$row=$current['record'];$watch=$current['watch'];$now=agent_commerce_time_pa2();
        if ((int)$row['revision']!==$input['expected_revision'] || $row['paused'] || in_array($row['outcome'],['resolved','replied'],true)) throw new DomainException('This follow-up is not waiting for a check.',409);
        $q=$pdo->prepare("UPDATE agent_connection_email_followups_v1 SET claim_key=?,next_check_at=? WHERE owner_user_id=? AND operation_key=?
            AND status='waiting' AND expires_at>? AND (claim_key IS NULL OR next_check_at<=?)");
        $q->execute([$claim,gmdate('Y-m-d H:i:s',time()+120),$watch['owner_user_id'],$watch['operation_key'],$now,$now]);
        if ($q->rowCount()!==1) throw new DomainException('This Gmail thread is already being checked. Refresh shortly.',409);
        return $watch;
    });
    try {
        $access=agent_connections_access_v1((int)$watch['owner_user_id'],(int)$watch['connection_id'],'followups',$http,$watch['authorization_key']);
        $messages=agent_connection_gmail_thread_v1($access['access_token'],$watch['thread_id'],$http,true);
        $evidence=agent_email_followthrough_evidence_v1($watch,$messages);
        $observed=agent_email_followthrough_observe_v1($pdo,$watch,$claim,$evidence,$http,false);
        if ($evidence['result']==='reply') agent_connection_email_followup_deliver_v1($pdo,$watch,$claim,$evidence['reply']);
        else agent_connection_email_followup_reschedule_v1($pdo,$watch,$claim,
            $observed && $observed['outcome']==='overdue' && $observed['prepare_if_overdue'] ? 0 : 300);
    } catch (Throwable $error) {
        try {agent_email_followthrough_observe_v1($pdo,$watch,$claim,['state'=>'unavailable','result'=>null,'digest'=>null],$http,false);} catch (Throwable $ignored) {}
        agent_connection_email_followup_reschedule_v1($pdo,$watch,$claim,900);
        throw $error;
    }
    return agent_email_followthrough_public_v1($pdo,agent_email_followthrough_for_watch_v1($pdo,$watch));
}

/** A confirmed send is an immutable fact, even if a newer owner control now suppresses its work. */
function agent_email_followthrough_note_sent_v1(PDO $pdo,array $draft,array $receipt): void
{
    if (!agent_email_followthrough_ready_v1($pdo)) return;
    if (!$pdo->inTransaction()) throw new LogicException('Record follow-up outcomes with the verified send.');
    $payload=agent_connection_email_chat_payload_v1($draft);$binding=$payload['outbound_followup']??null;
    if (!is_array($binding) || !is_string($binding['followthrough_id']??null)) return;
    $owner=(int)$draft['owner_user_id'];$operation=agent_connection_email_chat_operation_key_v1($draft['id']);
    $proof=$pdo->prepare("SELECT 1 FROM agent_connection_sends_v1 WHERE owner_user_id=? AND connection_id=? AND authorization_key=? AND operation_key=?
        AND status='sent' AND provider_message_id=? AND provider_thread_id=? AND sent_at=?");
    $proof->execute([$owner,$draft['connection_id'],$draft['authorization_key'],$operation,$receipt['message_id']??'',
        $receipt['thread_id']??'',(int)($receipt['sent_at']??0)]);
    if (!$proof->fetchColumn() || (int)($receipt['sent_at']??0)<1 || ($receipt['operation_key']??null)!==$operation
        || ($receipt['authorization_key']??null)!==$draft['authorization_key'] || (int)($receipt['connection_id']??0)!==(int)$draft['connection_id']
        || ($receipt['thread_id']??null)!==($binding['thread_id']??null)
        || ($receipt['from']??null)!==$draft['account_email'] || ($receipt['to']??null)!==$draft['recipient']) throw new DomainException('The follow-up send receipt changed.',409);
    $q=$pdo->prepare('SELECT * FROM agent_email_followthrough_v1 WHERE id=? AND owner_user_id=? AND agent_id=? AND conversation_id=?'.agent_commerce_lock_pa2($pdo));
    $q->execute([$binding['followthrough_id'],$owner,$draft['agent_id'],$draft['conversation_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['authorization_key']!==$draft['authorization_key'] || (int)$row['connection_id']!==(int)$draft['connection_id']
        || $row['account_email']!==$draft['account_email']) return;
    if ((int)($row['followup_sent_at']??0)>(int)$receipt['sent_at']) return;
    if ($row['followup_operation_key']===$operation && $row['followup_sent_message_id']===($receipt['message_id']??null)) return;
    // The encrypted generated draft binds its own old revision even after later deadline controls.
    if ($draft['id']!==substr(hash('sha256','vp3-gmail-followthrough-draft-v1:'.$row['id'].':'.(int)$binding['revision'].':'.(int)$draft['user_message_id']),0,32)
        && $row['generated_draft_id']!==$draft['id']) throw new DomainException('The generated follow-up draft could not be verified.',409);
    $same=(int)$row['source_revision']===(int)$binding['revision'] && (int)$row['user_message_id']===(int)$draft['user_message_id']
        && $row['generated_draft_id']===$draft['id'];
    $q=$pdo->prepare('SELECT * FROM agent_connection_email_followups_v1 WHERE owner_user_id=? AND operation_key=?'.agent_commerce_lock_pa2($pdo));
    $q->execute([$owner,$operation]);$next=$q->fetch(PDO::FETCH_ASSOC);
    $registered=$next && $next['draft_id']===$draft['id'] && $next['sent_message_id']===$receipt['message_id']
        && $next['thread_id']===$receipt['thread_id'] && $next['authorization_key']===$row['authorization_key']
        && (int)$next['connection_id']===(int)$row['connection_id'] && (int)$next['agent_id']===(int)$row['agent_id']
        && (int)$next['conversation_id']===(int)$row['conversation_id'] && $next['account_email']===$row['account_email']
        && $next['recipient_email']===$draft['recipient'] && ($next['sender_after_ms']??null)===null;
    $pdo->prepare('UPDATE agent_email_followthrough_v1 SET state_version=state_version+1,followup_operation_key=?,followup_sent_message_id=?,followup_sent_at=?,followup_draft_id=?,updated_at=? WHERE id=?')
        ->execute([$operation,$receipt['message_id'],$receipt['sent_at'],$draft['id'],agent_commerce_time_pa2(),$row['id']]);
    if ($same) {
        $old=$row['active_operation_key']??$row['operation_key'];
        $pdo->prepare("UPDATE agent_connection_email_followups_v1 SET status='stopped',claim_key=NULL,next_check_at=NULL WHERE owner_user_id=? AND operation_key=? AND status IN ('waiting','paused')")
            ->execute([$owner,$old]);
        $outcome=in_array($row['outcome'],['resolved','replied'],true)?$row['outcome']:'waiting';
        $clear=in_array($outcome,['resolved','replied'],true)?'':",check_state='never_checked',check_result=NULL,checked_at=NULL,evidence_digest=NULL,
            reply_message_id=NULL,reply_chat_message_id=NULL";
        $pdo->prepare("UPDATE agent_email_followthrough_v1 SET state_version=state_version+1,outcome=?,awaiting_new_deadline=1,
            active_operation_key=?,active_draft_id=?".$clear.' WHERE id=?')
            ->execute([$outcome,$registered?$operation:null,$registered?$draft['id']:null,$row['id']]);
    }
    if ($registered && ($row['paused'] || in_array($row['outcome'],['resolved','replied'],true))) {
        $pdo->prepare('UPDATE agent_connection_email_followups_v1 SET status=?,claim_key=NULL,next_check_at=NULL WHERE owner_user_id=? AND operation_key=?')
            ->execute([$row['paused']?'paused':'stopped',$owner,$operation]);
    }
}
