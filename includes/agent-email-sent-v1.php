<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-email-chat-v1.php';

const AGENT_EMAIL_SENT_TOKEN_TTL_V1 = 900;

/** The browser sees no provider IDs, page tokens, grants, or OAuth fingerprints. */
function agent_email_sent_token_v1(string $key, string $kind, array $scope, array $data, int $expires): string
{
    if ($key === '' || !in_array($kind,['page','item'],true)) throw new LogicException('A private Sent session is required.');
    $encrypted = ai_encrypt_secret(json_encode(['version'=>1,'kind'=>$kind,'scope'=>$scope,'data'=>$data,'expires'=>$expires],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    $body = rtrim(strtr(base64_encode($encrypted),'+/','-_'),'=');
    $token = $body.'.'.hash_hmac('sha256','vp3-gmail-sent-v1:'.$body,$key);
    if (strlen($token) > ($kind==='item' ? 4000 : 24000)) throw new DomainException('Gmail returned an unavailable Sent reference.',502);
    return $token;
}

function agent_email_sent_token_open_v1(string $key, string $kind, array $scope, string $token, int $now): array
{
    if ($key === '' || strlen($token)>($kind==='item' ? 4000 : 24000) || !preg_match('/\A([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D',$token,$parts)
        || !hash_equals(hash_hmac('sha256','vp3-gmail-sent-v1:'.$parts[1],$key),$parts[2])) {
        throw new DomainException('Refresh Sent to open this message or page.',409);
    }
    $encoded = base64_decode(strtr($parts[1],'-_','+/'),true);
    $plain = $encoded === false ? '' : ai_decrypt_secret($encoded);
    $value = json_decode($plain,true);
    if (!is_array($value) || array_keys($value)!==['version','kind','scope','data','expires']
        || $value['version']!==1 || $value['kind']!==$kind || $value['scope']!==$scope || !is_array($value['data'])
        || !is_int($value['expires']) || $value['expires']<=$now || $value['expires']>$now+AGENT_EMAIL_SENT_TOKEN_TTL_V1) {
        throw new DomainException('Refresh Sent to open this message or page.',409);
    }
    return $value;
}

/** Capture the same private Chat and current read grant used by all Sent reads. */
function agent_email_sent_authority_v1(PDO $pdo, array $user, array $principal, int $conversation): array
{
    try {
        return agent_connection_email_chat_authority_v1($pdo,$user,$principal,$conversation);
    } catch (RuntimeException $error) {
        // The canonical Agent resolver uses an untyped RuntimeException when
        // the selected Agent was removed. Treat that loss as revoked access.
        if ($error instanceof PDOException || $error->getCode()!==0) throw $error;
        throw new DomainException('This private Agent Chat is no longer available.',403,$error);
    }
}

function agent_email_sent_scope_v1(PDO $pdo, array $user, array $principal, int $conversation): array
{
    $user = agent_email_sent_authority_v1($pdo,$user,$principal,$conversation);
    if (!agent_connections_schema_ready_v1($pdo)) throw new DomainException('Gmail Connections are unavailable.',503);
    $owner = (int)$user['id'];
    $agent = ($principal['kind']??'system')==='user_agent' ? (int)($principal['agent_id']??0) : 0;
    $connection = agent_connections_find_v1($pdo,$owner,'gmail');
    if (!$connection) throw new DomainException('Connect Gmail to view Sent.',401);
    $connection = agent_connections_require_grant_v1($pdo,$owner,(int)$connection['id'],'read',$connection['authorization_key']);
    if (!agent_connection_gmail_ready_v1() || agent_connection_gmail_address_v1($connection['account_email'])==='') {
        throw new DomainException('Check the connected Gmail account before viewing Sent.',409);
    }
    return [$owner,$agent,$conversation,(int)$connection['id'],$connection['authorization_key'],
        $connection['client_fingerprint'],$connection['account_email']];
}

function agent_email_sent_current_v1(PDO $pdo, array $user, array $principal, array $scope): void
{
    agent_email_sent_authority_v1($pdo,$user,$principal,$scope[2]);
    try {
        $connection = agent_connections_require_grant_v1($pdo,$scope[0],$scope[3],'read',$scope[4]);
    } catch (AgentConnectionExceptionV1 $error) {
        if (!in_array($error->getCode(),[401,403,404,409],true)) throw $error;
        throw new DomainException('Gmail access changed. Refresh Sent after checking Connections.',409,$error);
    }
    if ($connection['client_fingerprint']!==$scope[5] || $connection['account_email']!==$scope[6]
        || !agent_connection_gmail_ready_v1()) {
        throw new DomainException('The connected Gmail account changed. Refresh Sent.',409);
    }
}

function agent_email_sent_revision_v1(array $scope): string
{
    return hash('sha256',json_encode(['vp3-gmail-mailbox-v1',...array_slice($scope,3)],JSON_THROW_ON_ERROR));
}

/** Provider shape failures are upstream errors, not an unknown send outcome. */
function agent_email_sent_get_v1(string $access, string $id, bool $full, ?callable $http): array
{
    try {
        return agent_connection_gmail_get_v1($access,$id,$full,$http);
    } catch (AgentConnectionGmailExceptionV1 $error) {
        if (in_array($error->getCode(),[401,403,404,429,502,503],true)) throw $error;
        throw new DomainException('Gmail could not verify this Sent message.',502,$error);
    }
}

/** SENT is provider evidence of a sent message, never evidence of delivery or reading. */
function agent_email_sent_message_v1(array $raw, string $thread, ?int $before=null): ?array
{
    $labels = $raw['labelIds']??null;
    $date = $raw['internalDate']??null;
    if (!is_array($labels) || !array_is_list($labels) || count($labels)>100
        || array_filter($labels,static fn(mixed $label): bool => !is_string($label) || strlen($label)>190)
        || !(is_int($date) || is_string($date)) || !preg_match('/\A[1-9][0-9]{0,14}\z/D',(string)$date)
        || (int)$date>253402300799999) {
        throw new DomainException('Gmail returned unavailable Sent message details.',502);
    }
    try { $message = agent_connection_gmail_message_v1($raw); }
    catch (AgentConnectionGmailExceptionV1 $error) {
        throw new DomainException('Gmail returned unavailable Sent message details.',502,$error);
    }
    if ($message['thread_id']!==$thread) throw new DomainException('Gmail returned a different Sent thread.',502);
    if (!in_array('SENT',$labels,true) || array_intersect(['DRAFT','SPAM','TRASH'],$labels)
        || ($before!==null && $message['received_at_ms'] >= $before*1000)) return null;
    return $message;
}

function agent_email_sent_row_v1(array $message): array
{
    return ['subject'=>$message['subject'],'from'=>$message['from'],'to'=>$message['to'],
        'sent_at'=>gmdate(DATE_ATOM,intdiv($message['received_at_ms'],1000)),'snippet'=>$message['snippet']];
}

/** Fetch one bounded page of the connected Gmail mailbox, including sends made outside VP3. */
function agent_email_sent_list_v1(PDO $pdo, array $user, array $principal, int $conversation, string $key,
    array $options=[], ?callable $http=null): array
{
    $limit = $options['limit']??10;
    $cursor = $options['cursor']??null;
    if (array_diff(array_keys($options),['limit','cursor']) || !is_int($limit) || $limit<1 || $limit>20
        || ($cursor!==null && (!is_string($cursor) || $cursor===''))) {
        throw new DomainException('Choose a valid Sent page.',422);
    }
    $scope = agent_email_sent_scope_v1($pdo,$user,$principal,$conversation);
    $now = time();
    $paging = $cursor===null ? null : agent_email_sent_token_open_v1($key,'page',$scope,$cursor,$now);
    $before = $paging['data']['before']??$now+1;
    $page = $paging['data']['page']??'';
    if ($paging!==null && (array_keys($paging['data'])!==['before','page','limit'] || !is_int($before) || $before<1
        || $before>$now+1 || $paging['data']['limit']!==$limit || !is_string($page) || $page===''
        || strlen($page)>4096 || preg_match('/[^\x20-\x7e]/',$page))) {
        throw new DomainException('Refresh Sent to continue paging.',409);
    }
    $access = agent_connections_access_v1($scope[0],$scope[3],'read',$http,$scope[4]);
    agent_email_sent_current_v1($pdo,$user,$principal,$scope);
    $query = ['labelIds'=>'SENT','includeSpamTrash'=>'false','q'=>'before:'.$before,
        'maxResults'=>$limit,'fields'=>'messages(id,threadId),nextPageToken'];
    if ($page!=='') $query['pageToken']=$page;
    try {
        $listed = agent_connection_gmail_api_v1($access['access_token'],
            '/messages?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),null,$http);
    } catch (AgentConnectionGmailExceptionV1 $error) {
        if ($page!=='' && $error->getCode()===400) throw new DomainException('This Sent page expired. Refresh Sent.',409,$error);
        if (in_array($error->getCode(),[401,403,404,429,502,503],true)) throw $error;
        throw new DomainException('Gmail could not verify this Sent page.',502,$error);
    }
    agent_email_sent_current_v1($pdo,$user,$principal,$scope);
    $records = $listed['messages']??[];
    $next = $listed['nextPageToken']??'';
    if (!is_array($records) || !array_is_list($records) || count($records)>$limit || !is_string($next)
        || strlen($next)>4096 || preg_match('/[^\x20-\x7e]/',$next) || ($next!=='' && $next===$page)) {
        throw new DomainException('Gmail returned an invalid Sent page. Refresh Sent.',502);
    }
    $items = $seen = [];
    foreach ($records as $record) {
        $id = is_array($record) ? agent_connection_gmail_id_v1($record['id']??null) : '';
        $thread = is_array($record) ? agent_connection_gmail_id_v1($record['threadId']??null) : '';
        if ($id==='' || $thread==='' || isset($seen[$id])) throw new DomainException('Gmail returned an invalid Sent reference.',502);
        $seen[$id]=true;
        agent_email_sent_current_v1($pdo,$user,$principal,$scope);
        try {
            $raw = agent_email_sent_get_v1($access['access_token'],$id,false,$http);
        } catch (AgentConnectionGmailExceptionV1 $error) {
            if ($error->getCode()!==404) throw $error;
            continue; // A message can be deleted between the provider list and detail read.
        }
        agent_email_sent_current_v1($pdo,$user,$principal,$scope);
        $message = agent_email_sent_message_v1($raw,$thread,$before);
        if ($message===null) continue;
        $items[] = ['item_ref'=>agent_email_sent_token_v1($key,'item',$scope,
            ['id'=>$id,'thread'=>$thread],$now+AGENT_EMAIL_SENT_TOKEN_TTL_V1)] + agent_email_sent_row_v1($message);
    }
    agent_email_sent_current_v1($pdo,$user,$principal,$scope);
    $nextCursor = $next==='' ? null : agent_email_sent_token_v1($key,'page',$scope,
        ['before'=>$before,'page'=>$next,'limit'=>$limit],$paging['expires']??$now+AGENT_EMAIL_SENT_TOKEN_TTL_V1);
    return ['scope'=>'gmail_sent','account_email'=>$scope[6],'checked_at'=>gmdate(DATE_ATOM),
        'mailbox_revision'=>agent_email_sent_revision_v1($scope),'items'=>$items,'next_cursor'=>$nextCursor,'has_more'=>$next!=='' ];
}

/** Read the exact Sent item selected in this private Chat. This never creates a reply or send approval. */
function agent_email_sent_open_v1(PDO $pdo, array $user, array $principal, int $conversation, string $key,
    string $itemRef, ?callable $http=null): array
{
    $scope = agent_email_sent_scope_v1($pdo,$user,$principal,$conversation);
    $item = agent_email_sent_token_open_v1($key,'item',$scope,$itemRef,time())['data'];
    if (array_keys($item)!==['id','thread'] || agent_connection_gmail_id_v1($item['id'])==='' || agent_connection_gmail_id_v1($item['thread'])==='') {
        throw new DomainException('Refresh Sent to choose a current message.',409);
    }
    $access = agent_connections_access_v1($scope[0],$scope[3],'read',$http,$scope[4]);
    agent_email_sent_current_v1($pdo,$user,$principal,$scope);
    $raw = agent_email_sent_get_v1($access['access_token'],$item['id'],true,$http);
    agent_email_sent_current_v1($pdo,$user,$principal,$scope);
    $verified = agent_email_sent_message_v1($raw,$item['thread']);
    if ($verified===null) throw new DomainException('This message is no longer in Sent. Refresh the list.',409);
    $message = agent_connection_gmail_read_message_v1($raw,true);
    require_once __DIR__.'/agent-gmail-documents-v1.php';
    $documents=agent_gmail_document_source_v1($pdo,$user,$scope[3],$scope[4],$raw);
    agent_email_sent_current_v1($pdo,$user,$principal,$scope);
    $followthrough=null;
    require_once __DIR__.'/agent-email-followthrough-v1.php';
    if (agent_email_followthrough_ready_v1($pdo)) {
        try {
            $q=$pdo->prepare('SELECT * FROM agent_connection_email_followups_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=?
                AND connection_id=? AND authorization_key=? AND account_email=? AND sent_message_id=? AND thread_id=? AND sender_after_ms IS NULL LIMIT 2');
            $q->execute([$scope[0],$scope[1],$scope[2],$scope[3],$scope[4],$scope[6],$item['id'],$item['thread']]);
            $watches=$q->fetchAll(PDO::FETCH_ASSOC);
            if (count($watches)===1) {
                $record=agent_email_followthrough_for_watch_v1($pdo,$watches[0]);
                if ($record) $followthrough=agent_email_followthrough_public_v1($pdo,$record);
            }
        } catch (DomainException|AgentConnectionExceptionV1 $error) { /* Sent remains readable without follow-up access. */ }
    }
    agent_email_sent_current_v1($pdo,$user,$principal,$scope);
    return array_diff_key(agent_email_sent_row_v1($verified),['snippet'=>true])
        + ['account_email'=>$scope[6],'mailbox_revision'=>agent_email_sent_revision_v1($scope),
            'body'=>mb_substr($message['body'],0,8000),'can_reply'=>false,'followthrough_record'=>$followthrough]
        + (isset($message['display']) ? ['display'=>$message['display']] : []) + $documents;
}
