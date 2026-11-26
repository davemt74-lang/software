<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-gmail-v1.php';

/** Outbound follow-ups have their own binding; received-reply validation is unchanged. */
function agent_email_followthrough_binding_v1(array $binding): array
{
    $keys = ['version','followthrough_id','revision','operation_key','message_id','thread_id','in_reply_to','references'];
    if (count($binding) !== count($keys) || array_diff(array_keys($binding),$keys)
        || ($binding['version'] ?? null) !== 1 || !is_string($binding['followthrough_id'] ?? null)
        || !preg_match('/\A[a-f0-9]{32}\z/D',$binding['followthrough_id'])
        || !is_int($binding['revision'] ?? null) || $binding['revision'] < 1
        || !is_string($binding['operation_key'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D',$binding['operation_key'])) {
        throw new DomainException('The saved email follow-up source is unavailable.',409);
    }
    $reply = agent_connection_gmail_reply_binding_v1(array_intersect_key($binding,
        array_flip(['message_id','thread_id','in_reply_to','references'])));
    return ['version'=>1,'followthrough_id'=>$binding['followthrough_id'],'revision'=>$binding['revision'],
        'operation_key'=>$binding['operation_key']] + $reply;
}

/** This is MIME linkage only. It never grants the inbound-reply capability. */
function agent_email_followthrough_mime_reply_v1(array $binding): array
{
    return array_intersect_key(agent_email_followthrough_binding_v1($binding),
        array_flip(['message_id','thread_id','in_reply_to','references']));
}

function agent_email_followthrough_address_v1(string $address): string
{
    $pattern = <<<'PATTERN'
/\A(?:"(?:[^"\\]|\\.)*"|[^,;:<>"\\]*)[ \t]*<([^<>]+)>\z/D
PATTERN;
    if (preg_match($pattern,$address,$match)) $address = $match[1];
    return agent_connection_gmail_sender_v1($address);
}

/** Parse one actual SENT message, keeping its external To rather than replying to our own From. */
function agent_email_followthrough_original_v1(array $message,string $mailbox,string $recipient,
    array $identity,bool $withBody=false): array
{
    $mail = agent_connection_gmail_message_v1($message);
    if ($mail['message_id'] !== ($identity['message_id'] ?? null) || $mail['thread_id'] !== ($identity['thread_id'] ?? null)
        || !in_array('SENT',$mail['labels'],true) || array_intersect(['DRAFT','SPAM','TRASH'],$mail['labels'])
        || $mail['received_at_ms'] < 1) {
        throw new DomainException('The original sent email could not be verified.',409);
    }
    $headers = [];
    foreach ((array)($message['payload']['headers'] ?? []) as $header) {
        if (!is_array($header) || !is_string($header['name'] ?? null)) continue;
        $name = strtolower($header['name']);
        if (!in_array($name,['from','to','subject','message-id','in-reply-to','references'],true)) continue;
        $value = $header['value'] ?? null;
        if (isset($headers[$name]) || !is_string($value) || !mb_check_encoding($value,'UTF-8') || strlen($value)>16000) {
            throw new DomainException('The original sent email headers are ambiguous.',409);
        }
        $value = preg_replace('/\r?\n[ \t]+/',' ',$value);
        if (preg_match('/[\x00-\x08\x0a-\x1f\x7f]/',$value)) {
            throw new DomainException('The original sent email headers could not be verified.',409);
        }
        $headers[$name] = trim($value);
    }
    if (agent_email_followthrough_address_v1($headers['from'] ?? '') !== $mailbox
        || agent_email_followthrough_address_v1($headers['to'] ?? '') !== $recipient || $recipient === $mailbox) {
        throw new DomainException('The original sent email recipient changed.',409);
    }
    $subject = mb_decode_mimeheader($headers['subject'] ?? '');
    if (!mb_check_encoding($subject,'UTF-8') || strlen($subject)>980 || preg_match('/[\x00-\x1f\x7f]/',$subject)) {
        throw new DomainException('The original sent email subject could not be verified.',409);
    }
    $parent = $headers['message-id'] ?? '';
    $chain = $headers['references'] ?? '';
    if ($chain === '' && agent_connection_gmail_reply_rfc_id_v1($headers['in-reply-to'] ?? '')) $chain=$headers['in-reply-to'];
    $refs = $chain === '' ? [] : preg_split('/[ \t]+/',$chain);
    $binding = agent_email_followthrough_binding_v1([
        'version'=>1,'followthrough_id'=>$identity['followthrough_id'],'revision'=>$identity['revision'],
        'operation_key'=>$identity['operation_key'],'message_id'=>$mail['message_id'],'thread_id'=>$mail['thread_id'],
        'in_reply_to'=>$parent,'references'=>[...$refs,$parent],
    ]);
    $out = ['to'=>$recipient,'subject'=>$subject,'outbound_followup'=>$binding];
    if ($withBody) $out['body']=mb_strcut(agent_connection_gmail_read_message_v1($message)['body'],0,4000,'UTF-8');
    return $out;
}

/** Exact source and complete thread recheck. No body or timestamp is treated as action authority. */
function agent_email_followthrough_source_v1(PDO $pdo,array $record,array $watch,?callable $http=null,bool $withBody=true): array
{
    require_once __DIR__.'/agent-email-followthrough-v1.php';
    $identity = ['followthrough_id'=>$record['id'],'revision'=>(int)($record['source_revision'] ?? $record['revision']),
        'operation_key'=>$watch['operation_key'],'message_id'=>$watch['sent_message_id'],'thread_id'=>$watch['thread_id']];
    $scope = [(int)$watch['owner_user_id'],(int)$watch['agent_id'],(int)$watch['conversation_id']];
    agent_email_followthrough_binding_current_v1($pdo,...[...$scope,$identity]);
    $access = agent_connections_access_v1($scope[0],(int)$watch['connection_id'],'followups',$http,$watch['authorization_key']);
    $raw = agent_connection_gmail_get_v1($access['access_token'],$watch['sent_message_id'],$withBody,$http,true);
    $source = agent_email_followthrough_original_v1($raw,$watch['account_email'],$watch['recipient_email'],$identity,$withBody);
    $evidence = agent_email_followthrough_evidence_v1($watch,
        agent_connection_gmail_thread_v1($access['access_token'],$watch['thread_id'],$http,true));
    if (($evidence['state'] ?? null) !== 'verified' || ($evidence['result'] ?? null) !== 'no_reply') {
        throw new DomainException('The email thread changed. Check it before preparing a follow-up.',409);
    }
    agent_email_followthrough_binding_current_v1($pdo,...[...$scope,$source['outbound_followup']]);
    return $source;
}

/** Used only before the first POST. A pending send reconciles its immutable receipt instead. */
function agent_email_followthrough_send_source_v1(PDO $pdo,int $owner,int $connectionId,string $grant,
    string $token,string $mailbox,string $to,string $subject,array $binding,?callable $http=null): ?string
{
    require_once __DIR__.'/agent-email-followthrough-v1.php';
    $binding = agent_email_followthrough_binding_v1($binding);
    $q = $pdo->prepare('SELECT * FROM agent_connection_email_followups_v1 WHERE owner_user_id=? AND operation_key=?');
    $q->execute([$owner,$binding['operation_key']]);
    $watch = $q->fetch(PDO::FETCH_ASSOC);
    if (!$watch || (int)$watch['connection_id'] !== $connectionId || $watch['authorization_key'] !== $grant
        || $watch['account_email'] !== $mailbox || $watch['recipient_email'] !== $to) {
        throw new DomainException('The original email follow-up changed. Review it again.',409);
    }
    agent_email_followthrough_binding_current_v1($pdo,$owner,(int)$watch['agent_id'],(int)$watch['conversation_id'],$binding,true);
    $raw = agent_connection_gmail_get_v1($token,$binding['message_id'],false,$http,true);
    $source = agent_email_followthrough_original_v1($raw,$mailbox,$to,$binding);
    if ($source['outbound_followup'] !== $binding || $source['subject'] !== $subject) {
        throw new DomainException('The original sent email changed. Review it again.',409);
    }
    $evidence = agent_email_followthrough_evidence_v1($watch,
        agent_connection_gmail_thread_v1($token,$binding['thread_id'],$http,true));
    if (($evidence['state'] ?? null) !== 'verified') return 'source_check';
    if (($evidence['result'] ?? null) !== 'no_reply') return 'stale';
    return null;
}
