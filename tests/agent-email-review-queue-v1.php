<?php
declare(strict_types=1);

// Real native storage, encryption and authorization; Gmail is an injected,
// GET-only fixture. No live account, provider send or model service is contacted.
require_once dirname(__DIR__).'/includes/agent-email-composer-v1.php';
require_once dirname(__DIR__).'/includes/agent-email-review-queue-v1.php';
require_once dirname(__DIR__).'/includes/agent-capability-chat-v1.php';
require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__).'/includes/participant-updates-v1.php';
require_once dirname(__DIR__).'/includes/agent-tool-json-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-gmail-draft-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-work-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-scope-v1.php';
require_once dirname(__DIR__).'/includes/agent-brain-v82.php';

function db(): PDO { return $GLOBALS['pdo']; }
function setting(string $name,mixed $fallback=''): mixed { return $fallback; }
function url(string $path): string { return $path; }
function has_permission(string $permission,array $user): bool
{
    return !empty($user['is_active']) && in_array($permission,['chat.access','account.access'],true);
}
function table_exists(string $table): bool
{
    $query=db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $query->execute([$table]);
    return (bool)$query->fetchColumn();
}
function queue_check(bool $ok,string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['queue_checks']=($GLOBALS['queue_checks']??0)+1;
    echo 'PASS '.$label.PHP_EOL;
}
function queue_reject(int $status,callable $work,string $label): void
{
    try { $work(); }
    catch (RuntimeException|DomainException $error) {
        if ($error instanceof PDOException || (int)$error->getCode()!==$status) throw $error;
        queue_check(true,$label);
        return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}
function queue_counts_equal(array $actual,array $expected): bool
{
    ksort($actual);ksort($expected);
    return $actual===$expected;
}
/** Introduce one concurrent revocation after an actual native database read. */
class QueueRaceStatement extends PDOStatement
{
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed
    {
        $row=parent::fetch($mode,$cursorOrientation,$cursorOffset);
        $hook=$GLOBALS['queue_fetch_hook']??null;
        if ($row!==false && is_callable($hook)) $hook($this->queryString,$row);
        return $row;
    }
}

/** Seed persisted lifecycle states through the real encryption and schema. */
function queue_seed(PDO $pdo,array $fields=[]): array
{
    $owner=$fields['owner']??1;
    $agent=$fields['agent']??7;
    $conversation=$fields['conversation']??11;
    $created=$fields['created']??time()-86400*3;
    $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json,created_at)
        VALUES (?,?,'user','Review the saved email.','{}',?)")
        ->execute([$conversation,$owner,gmdate('Y-m-d H:i:s',$created)]);
    $origin=(int)$pdo->lastInsertId();
    $id=bin2hex(random_bytes(16));
    $payload=$fields['payload']??[
        'subject'=>'Saved fixture '.$origin,'body'=>'PRIVATE-DRAFT-BODY-'.$origin,
        'attachments'=>[],'composer'=>['version'=>1,'saved_at'=>$created],
    ];
    $encrypted=$fields['encrypted']??ai_encrypt_secret(json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    $pdo->prepare('INSERT INTO agent_connection_email_drafts_v1
        (id,owner_user_id,agent_id,conversation_id,user_message_id,connection_id,authorization_key,account_email,recipient,message_enc,status,expires_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$owner,$agent,$conversation,$origin,$owner===1?7:9,
            str_repeat($owner===1?'a':'b',64),$owner===1?'owner@example.test':'other@example.test',
            $fields['to']??'sender@example.test',$encrypted,$fields['status']??'prepared',$fields['expires']??0]);
    return agent_connection_email_chat_draft_v1($pdo,$owner,$agent,$conversation,$id);
}
function queue_state(PDO $pdo): array
{
    $tables=['agent_connection_email_drafts_v1','chat_messages','agent_connection_sends_v1','agent_connection_email_followups_v1'];
    $result=[];
    foreach ($tables as $table) {
        if (!table_exists($table)) continue;
        $rows=$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        $serialized=array_map(static fn(array $row): string=>json_encode($row,JSON_THROW_ON_ERROR),$rows);
        sort($serialized,SORT_STRING);
        $result[$table]=$serialized;
    }
    return $result;
}

$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');
if (!str_starts_with($dsn,'mysql:')) throw new RuntimeException('Native MySQL is required for the Gmail review queue fixture.');
$login=getenv('VP3_TEST_MYSQL_USER')?:'root';
$password=(string)getenv('VP3_TEST_MYSQL_PASS');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO($dsn,$login,$password,$options);
$database='vp3_email_queue_'.bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$fixtureRoot=sys_get_temp_dir().'/vp3-email-queue-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$fixtureRoot);
mkdir($fixtureRoot,0700);
$config=['connections'=>['gmail'=>['client_id'=>'queue-fixture.apps.googleusercontent.com','client_secret'=>str_repeat('x',24),
    'redirect_uri'=>'https://queue-fixture.example.test/connection-oauth.php']]];

try {
    $pdo=new PDO(preg_replace('/;dbname=[^;]+/','',$dsn).';dbname='.$database,$login,$password,$options);
    $pdo->exec("CREATE TABLE users (id INT UNSIGNED PRIMARY KEY,is_active INT NOT NULL);
        INSERT INTO users VALUES (1,1),(2,1);
        CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NOT NULL);
        CREATE TABLE user_agents (id BIGINT UNSIGNED PRIMARY KEY,owner_user_id INT UNSIGNED NOT NULL,
            display_name VARCHAR(100) NOT NULL DEFAULT 'Fixture Agent',is_active INT NOT NULL);
        INSERT INTO user_agents (id,owner_user_id,is_active) VALUES (7,1,1),(8,1,1),(9,2,1);
        CREATE TABLE chat_conversations (id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED NOT NULL,user_agent_id BIGINT UNSIGNED NULL,
            title VARCHAR(190) NOT NULL DEFAULT '',updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
        INSERT INTO chat_conversations (id,user_id,user_agent_id,title) VALUES
            (11,1,7,'First private Chat'),(12,1,7,'Second private Chat'),(13,1,8,'Other Agent Chat'),
            (14,1,NULL,'System Agent Chat'),(15,1,7,'Former private Chat'),(21,2,9,'Other owner Chat');
        CREATE TABLE chat_messages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversation_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NULL,role VARCHAR(20),message TEXT,context_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    agent_connections_install_v1($pdo);
    $connection=$pdo->prepare("INSERT INTO agent_connections_v1 (id,owner_user_id,provider,external_account_id,account_email,status,
        read_enabled,send_enabled,followups_enabled,authorization_key,client_fingerprint,access_token_enc,refresh_token_enc,access_expires_at)
        VALUES (?,?,'gmail',?,?,'connected',1,1,1,?,?,?,'',?)");
    foreach ([[7,1,'a','owner'],[9,2,'b','other']] as [$id,$ownerId,$letter,$mailbox]) {
        $connection->execute([$id,$ownerId,'fixture-'.$mailbox,$mailbox.'@example.test',str_repeat($letter,64),
            agent_connections_client_fingerprint_v1(),ai_encrypt_secret('fixture-'.$mailbox.'-access'),time()+86400]);
    }
    $owner=['id'=>1,'is_active'=>1];
    $other=['id'=>2,'is_active'=>1];
    $principal=vp3_agent_chat_principal_v380($owner,vp3_agent_chat_resolve_agent_v380($pdo,$owner,7));
    $otherAgent=vp3_agent_chat_principal_v380($owner,vp3_agent_chat_resolve_agent_v380($pdo,$owner,8));
    $otherPrincipal=vp3_agent_chat_principal_v380($other,vp3_agent_chat_resolve_agent_v380($pdo,$other,9));
    $key='private-queue-fixture-session';
    $noProvider=static fn()=>throw new RuntimeException('This local draft operation must not contact Gmail.');
    $act=static fn(int $conversation,array $input): array=>agent_connection_email_chat_action_v1(
        $pdo,$owner,$principal,$conversation,$input,$key,$noProvider);
    $list=static fn(array $options=[],int $conversation=11): array=>agent_email_composer_list_v1(
        $pdo,$owner,$principal,$conversation,$key,$options);

    $first=queue_seed($pdo);
    $second=queue_seed($pdo,['conversation'=>12]);
    $foreignAgent=queue_seed($pdo,['agent'=>8,'conversation'=>13]);
    $foreignOwner=queue_seed($pdo,['owner'=>2,'agent'=>9,'conversation'=>21]);
    $system=queue_seed($pdo,['agent'=>0,'conversation'=>14]);
    $wrongChatAgent=queue_seed($pdo,['conversation'=>13]);
    $wrongChatOwner=queue_seed($pdo,['conversation'=>21]);
    $lostChat=queue_seed($pdo,['conversation'=>15]);
    $pdo->exec('UPDATE chat_conversations SET user_id=2,user_agent_id=9 WHERE id=15');
    $legacy=$list();
    queue_check(array_column($legacy['drafts'],'id')===[$first['id']]
        && $legacy['from']==='owner@example.test' && $legacy['can_send'],
        'the legacy no-options list remains scoped to its current private Chat');
    $agentQueue=$list(['scope'=>'agent','filter'=>'all']);
    queue_check(array_column($agentQueue['drafts'],'id')===[$second['id'],$first['id']]
        && queue_counts_equal($agentQueue['counts'],['pending'=>2,'total'=>2,'sent'=>0,'discarded'=>0,'answered'=>0]),
        'an Agent queue includes both owned Chats and excludes other owners, Agents and reassigned Chats');
    $cross=$agentQueue['drafts'][0];
    queue_check($cross['conversation_id']===12 && $cross['conversation_title']==='Second private Chat'
        && $cross['target']===['agent_id'=>7,'conversation_id'=>12,'draft_id'=>$second['id']]
        && !isset($cross['body'],$cross['review_token'],$cross['watch_review_token']),
        'cross-Chat rows provide the exact navigation target without draft text or approval tokens');
    queue_reject(404,static fn()=>$act(11,['action'=>'review','draft_id'=>$second['id']]),
        'a queue navigation target does not permit review in the wrong Chat');
    $opened=$act(12,['action'=>'review','draft_id'=>$second['id']]);
    queue_check($opened['id']===$second['id'] && $opened['can_edit'] && $opened['body']==='PRIVATE-DRAFT-BODY-'.$second['user_message_id'],
        'the same owner can review after navigating to the original Agent Chat');
    $otherList=agent_email_composer_list_v1($pdo,$owner,$otherAgent,13,$key,['scope'=>'agent','filter'=>'all']);
    $ownerList=agent_email_composer_list_v1($pdo,$other,$otherPrincipal,21,$key,['scope'=>'agent','filter'=>'all']);
    queue_check(array_column($otherList['drafts'],'id')===[$foreignAgent['id']]
        && array_column($ownerList['drafts'],'id')===[$foreignOwner['id']],
        'valid alternate principals receive only their own Agent queue');
    queue_reject(403,static fn()=>$list(['scope'=>'agent'],13),'the active Chat must belong to the selected Agent');
    queue_reject(403,static fn()=>agent_email_composer_list_v1($pdo,$owner,
        array_replace($principal,['viewer_user_id'=>2]),11,$key,['scope'=>'agent']),
        'a delegated viewer cannot enumerate the owner mailbox queue');
    $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1,'agent'=>7,'participant'=>99];
    try {
        queue_reject(403,static fn()=>$list(['scope'=>'agent']),
            'a participant-scoped session cannot enumerate private Gmail drafts');
    } finally { unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']); }

    $rows=[$first,$second];
    for ($index=0;$index<58;$index++) $rows[]=queue_seed($pdo,['conversation'=>$index%2===0?11:12]);
    foreach (['sent','discarded','answered','write_queued','write_failed','sending','stale','source_check','failed'] as $status) {
        $fields=['status'=>$status,'conversation'=>12];
        if ($status==='write_queued') $fields['payload']=['subject'=>'Queued fixture','body'=>'PRIVATE-QUEUED-BODY',
            'composer'=>['version'=>1,'saved_at'=>time()-3600],
            'write'=>agent_connection_email_chat_write_request_v1('compose_new','Fixture instruction')];
        $rows[]=queue_seed($pdo,$fields);
    }
    $invalid=queue_seed($pdo,['encrypted'=>'invalid-encrypted-fixture']);$rows[]=$invalid;
    $replyBinding=['message_id'=>'source_exact','thread_id'=>'source_thread','in_reply_to'=>'<source-exact@example.test>',
        'references'=>['<source-exact@example.test>']];
    $replyPayload=['subject'=>'Original fixture subject','body'=>'PRIVATE-ORGANIC-BODY','reply'=>$replyBinding];
    $automatic=queue_seed($pdo,['payload'=>$replyPayload+['auto_revision'=>str_repeat('e',64)],'expires'=>time()+86400]);
    $rows[]=$automatic;
    $automaticSent=queue_seed($pdo,['status'=>'sent','payload'=>$replyPayload+['auto_revision'=>str_repeat('e',64)],'expires'=>time()+86400]);
    $rows[]=$automaticSent;
    $expired=queue_seed($pdo,['payload'=>$replyPayload,'expires'=>time()-3600,'created'=>time()-86400*10]);
    $rows[]=$expired;
    $allIds=array_reverse(array_column($rows,'id'));
    $expectedCounts=['pending'=>count($rows)-4,'total'=>count($rows),'sent'=>2,'discarded'=>1,'answered'=>1];
    $page=$list(['scope'=>'agent','filter'=>'all','limit'=>17,'cursor'=>null]);
    queue_check(count($page['drafts'])===17 && $page['has_more'] && is_string($page['next_cursor'])
        && queue_counts_equal($page['counts'],$expectedCounts) && $page['scope']==='agent' && $page['filter']==='all' && $page['limit']===17,
        'bounded first-page results expose exact totals beyond the old 50-row window');
    $seen=array_column($page['drafts'],'id');
    $queueJson=json_encode($page,JSON_THROW_ON_ERROR);
    $firstCursor=$page['next_cursor'];
    $newer=queue_seed($pdo,['conversation'=>12]);
    $pageCount=1;
    while ($page['has_more']) {
        if (++$pageCount>8) throw new RuntimeException('Queue pagination did not terminate.');
        $page=$list(['scope'=>'agent','filter'=>'all','limit'=>17,'cursor'=>$page['next_cursor']]);
        queue_check(queue_counts_equal($page['counts'],$expectedCounts) && count($page['drafts'])<=17,
            'page '.$pageCount.' preserves the initial high-water totals and page bound');
        $seen=[...$seen,...array_column($page['drafts'],'id')];
        $queueJson.=json_encode($page,JSON_THROW_ON_ERROR);
    }
    queue_check($seen===$allIds && count(array_unique($seen))===count($allIds) && $page['next_cursor']===null,
        'keyset pages traverse every saved row once without duplicates or concurrent insert drift');
    queue_check(!str_contains($queueJson,'PRIVATE-') && !str_contains($queueJson,'fixture-owner-access')
        && !str_contains($queueJson,str_repeat('a',64)) && !str_contains($queueJson,'source_exact'),
        'queue pages omit body text, provider source IDs, access tokens and connection grants');
    $fresh=$list(['scope'=>'agent','filter'=>'all','limit'=>50]);
    queue_check($fresh['drafts'][0]['id']===$newer['id'] && $fresh['counts']['total']===count($rows)+1,
        'refreshing starts a new queue snapshot containing later drafts');
    $pending=$list(['scope'=>'agent','filter'=>'pending','limit'=>50]);
    queue_check(queue_counts_equal($pending['counts'],$fresh['counts'])
        && !array_intersect(array_column($pending['drafts'],'stored_status'),['sent','discarded','answered']),
        'Pending drafts filters only persisted terminal outcomes while keeping complete saved-state counts');
    $mapped=array_column($fresh['drafts'],null,'id');
    queue_check(isset($mapped[$invalid['id']],$mapped[$automatic['id']],$mapped[$expired['id']])
        && $mapped[$invalid['id']]['blocked_reason']==='unavailable'
        && $mapped[$automatic['id']]['blocked_reason']==='automatic'
        && !$mapped[$automatic['id']]['can_open_source'] && !$mapped[$automatic['id']]['can_edit']
        && $mapped[$expired['id']]['status']==='expired' && $mapped[$expired['id']]['blocked_reason']==='expired'
        && $mapped[$expired['id']]['can_open_source'] && !$mapped[$expired['id']]['can_send'],
        'unavailable and automatic drafts remain content-free while expired proposals retain a read-only source action');
    $sentAuto=$mapped[$automaticSent['id']];
    queue_check($sentAuto['stored_status']==='sent' && $sentAuto['blocked_reason']==='automatic' && $sentAuto['subject']===''
        && $sentAuto['to']==='' && !$sentAuto['can_open_source'] && !$sentAuto['can_edit'],
        'a sent Auto Send receipt cannot expose automatic draft content in the manual review queue');
    queue_check($mapped[$expired['id']]['age_seconds']>=86400*10-2
        && is_string($mapped[$expired['id']]['created_at']) && is_string($mapped[$expired['id']]['updated_at']),
        'review age and timestamps describe the original saved request');
    queue_reject(422,static fn()=>$list(['scope'=>'everybody']),'unrecognized queue scope cannot broaden access');
    queue_reject(422,static fn()=>$list(['scope'=>'agent','limit'=>51]),'unbounded queue pages are rejected');
    queue_reject(422,static fn()=>$list(['scope'=>'agent','filter'=>'sent']),'unknown queue filters are rejected');
    queue_reject(409,static fn()=>$list(['scope'=>'agent','filter'=>'pending','limit'=>17,'cursor'=>$firstCursor]),
        'a continuation cursor cannot be replayed under another filter');
    queue_reject(409,static fn()=>$list(['scope'=>'chat','filter'=>'all','limit'=>17,'cursor'=>$firstCursor]),
        'an Agent-wide cursor cannot be replayed as a single-Chat cursor');
    queue_reject(409,static fn()=>agent_email_composer_list_v1($pdo,$owner,$otherAgent,13,$key,
        ['scope'=>'agent','filter'=>'all','limit'=>17,'cursor'=>$firstCursor]),'a cursor cannot move to another Agent');
    queue_reject(409,static fn()=>agent_email_composer_list_v1($pdo,$other,$otherPrincipal,21,$key,
        ['scope'=>'agent','filter'=>'all','limit'=>17,'cursor'=>$firstCursor]),'a cursor cannot move to another owner');
    queue_reject(409,static fn()=>agent_email_composer_list_v1($pdo,$owner,$principal,11,$key.'-different',
        ['scope'=>'agent','filter'=>'all','limit'=>17,'cursor'=>$firstCursor]),'a cursor is bound to the current review session');
    queue_reject(409,static fn()=>$list(['scope'=>'agent','filter'=>'all','limit'=>17,'cursor'=>$firstCursor],12),
        'navigating to another Chat starts a fresh queue browsing scope');
    $tampered=substr($firstCursor,0,-1).(str_ends_with($firstCursor,'0')?'1':'0');
    queue_reject(409,static fn()=>$list(['scope'=>'agent','filter'=>'all','cursor'=>$tampered]),
        'a modified cursor signature cannot select another saved range');
    $paging=agent_email_review_queue_cursor_open_v1($key,[1,7,11,'agent','all'],$firstCursor,time());
    $expiredCursor=agent_email_review_queue_cursor_v1($key,$paging['scope'],$paging['snapshot'],$paging['after'],time()-1);
    queue_reject(409,static fn()=>$list(['scope'=>'agent','filter'=>'all','cursor'=>$expiredCursor]),
        'a browsing cursor expires independently of retained drafts');

    $encode=static fn(string $text): string=>rtrim(strtr(base64_encode($text),'+/','-_'),'=');
    $sourceBody='PRIVATE-SOURCE-BODY: please review the original request.';
    $sourceHtml='<html><head><style>.mail{color:#234;background:url(https://tracker.example.test/pixel)}</style></head>'
        .'<body><table class="mail"><tr><td><h2>Original fixture</h2><p>'.$sourceBody.'</p></td></tr></table>'
        .'<img src="https://images.example.test/photo.png" onerror="alert(1)"><script>window.parent.evil=1</script></body></html>';
    $raw=['id'=>'source_exact','threadId'=>'source_thread','labelIds'=>['INBOX','UNREAD'],
        'internalDate'=>(string)((time()-86400)*1000),'snippet'=>$sourceBody,
        'payload'=>['mimeType'=>'multipart/alternative','headers'=>[
            ['name'=>'From','value'=>'Fixture Sender <sender@example.test>'],
            ['name'=>'To','value'=>'owner@example.test'],['name'=>'Subject','value'=>'Original fixture subject'],
            ['name'=>'Message-ID','value'=>'<source-exact@example.test>']],
            'parts'=>[['mimeType'=>'text/plain','body'=>['data'=>$encode($sourceBody)]],
                ['mimeType'=>'text/html','body'=>['data'=>$encode($sourceHtml)]]]]];
    $requests=[];
    $transport=static function(string $method,string $url,array $headers,?array $payload,bool $form) use (&$requests,&$raw): array {
        if ($method!=='GET' || $payload!==null || $form
            || parse_url($url,PHP_URL_PATH)!=='/gmail/v1/users/me/messages/source_exact') {
            throw new RuntimeException('The source action may only GET its exact bound original message.');
        }
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        $requests[]=$query['format']??'';
        return ['status'=>200,'json'=>$raw];
    };
    $source=static fn(array $draft,?callable $http=null): array=>agent_email_review_queue_source_v1(
        $pdo,$owner,$principal,(int)$draft['conversation_id'],['draft_id'=>$draft['id'],
            'edit_revision'=>agent_connection_email_chat_review_hash_v1($draft,false)],$key,$http??$transport);
    $before=queue_state($pdo);
    $requests=[];
    $read=$source($expired);
    queue_check($requests===['full'] && $read['draft_id']===$expired['id'] && $read['conversation_id']===11
        && $read['edit_revision']===agent_connection_email_chat_review_hash_v1($expired,false)
        && $read['message']['body']===$sourceBody && $read['message']['subject']==='Original fixture subject',
        'an expired proposal can read its exact original source without renewing send approval');
    $html=$read['message']['display']['html']??'';
    queue_check(str_contains($html,'<table') && str_contains($html,'Original fixture') && str_contains($html,'color:#234')
        && !str_contains($html,'<script') && !str_contains($html,'onerror') && !str_contains($html,'tracker.example.test')
        && !preg_match('/\ssrc\s*=\s*[\'\"]https?:/i',$html)
        && str_contains($html,'data-vp3-remote-src="https://images.example.test/photo.png"'),
        'source display uses the real sanitizer and leaves remote images inert');
    queue_check(queue_state($pdo)===$before && !isset($read['review_token'],$read['message']['review_token'])
        && !str_contains(json_encode($read,JSON_THROW_ON_ERROR),'PRIVATE-ORGANIC-BODY'),
        'source reading creates no Chat messages, draft revisions, send receipts or watches and grants no send token');
    $expiredReview=$act(11,['action'=>'review','draft_id'=>$expired['id']]);
    queue_check($expiredReview['status']==='expired' && !$expiredReview['can_edit'] && !$expiredReview['can_send']
        && !isset($expiredReview['review_token']) && (int)agent_connection_email_chat_draft_v1($pdo,1,7,11,$expired['id'])['expires_at']>0,
        'listing and opening a legacy proposal never rewrite its expired retention deadline');

    $sourceInput=['draft_id'=>$expired['id'],'edit_revision'=>$read['edit_revision']];
    $reads=count($requests);
    queue_reject(404,static fn()=>agent_email_review_queue_source_v1($pdo,$owner,$principal,12,$sourceInput,$key,$transport),
        'a source action cannot use an original draft from a different Chat');
    queue_reject(404,static fn()=>agent_email_review_queue_source_v1($pdo,$other,$otherPrincipal,21,$sourceInput,$key,$transport),
        'another owner cannot fetch a source even with a copied exact revision');
    queue_reject(404,static fn()=>agent_email_review_queue_source_v1($pdo,$owner,$otherAgent,13,$sourceInput,$key,$transport),
        'another Agent cannot fetch the original mailbox source');
    queue_reject(409,static fn()=>agent_email_review_queue_source_v1($pdo,$owner,$principal,11,
        array_replace($sourceInput,['edit_revision'=>str_repeat('f',64)]),$key,$transport),
        'a stale source revision is rejected before Gmail access');
    queue_reject(422,static fn()=>agent_email_review_queue_source_v1($pdo,$owner,$principal,11,
        array_replace($sourceInput,['edit_revision'=>'not-a-revision']),$key,$transport),
        'malformed source revisions are rejected');
    queue_reject(422,static fn()=>agent_email_review_queue_source_v1($pdo,$owner,$principal,11,
        ['draft_id'=>$expired['id']],$key,$transport),'a source action requires the exact current content revision');
    queue_reject(404,static fn()=>$source($first),'a newly composed email has no inferred mailbox source');
    queue_reject(404,static fn()=>$source($automatic),'Auto Send drafts do not expose source or payload through the manual queue');
    $pdo->prepare('UPDATE chat_messages SET user_id=2 WHERE id=?')->execute([$expired['user_message_id']]);
    try { queue_reject(404,static fn()=>$source($expired),'a draft without its original owner request cannot expose Gmail source text'); }
    finally { $pdo->prepare('UPDATE chat_messages SET user_id=1 WHERE id=?')->execute([$expired['user_message_id']]); }
    $pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
    try { queue_reject(403,static fn()=>$source($expired),'inactive account access is rejected before a source read'); }
    finally { $pdo->exec('UPDATE users SET is_active=1 WHERE id=1'); }
    queue_check(count($requests)===$reads,'all rejected scope and revision requests make zero provider reads');

    $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=0 WHERE id=7');
    try {
        queue_reject(409,static fn()=>$source($expired),'revoked Gmail read permission blocks an original source');
        $blocked=$list(['scope'=>'agent','filter'=>'all','limit'=>50]);
        $row=array_column($blocked['drafts'],null,'id')[$expired['id']];
        queue_check(!$row['can_open_source'] && !$row['can_edit'] && !$row['can_send']
            && $row['blocked_reason']==='connection_changed' && $row['subject']==='' && $row['to']==='',
            'a revoked reply remains a counted placeholder without private envelope or read controls');
        queue_check(count($requests)===$reads,'revocation and blocked queue listing do not contact Gmail');
    } finally { $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=1 WHERE id=7'); }
    $pdo->prepare('UPDATE agent_connections_v1 SET authorization_key=? WHERE id=7')->execute([str_repeat('c',64)]);
    try {
        queue_reject(409,static fn()=>$source($expired),'a replacement mailbox grant cannot inherit source access');
        queue_check(count($requests)===$reads,'a replaced grant is rejected before provider access');
    } finally { $pdo->prepare('UPDATE agent_connections_v1 SET authorization_key=? WHERE id=7')->execute([str_repeat('a',64)]); }
    $original=$raw;
    foreach (['recipient','subject','thread','headers'] as $change) {
        $raw=$original;
        if ($change==='recipient') $raw['payload']['headers'][]=['name'=>'Reply-To','value'=>'other@example.test'];
        elseif ($change==='subject') $raw['payload']['headers'][2]['value']='Changed source subject';
        elseif ($change==='thread') $raw['threadId']='different_thread';
        else $raw['payload']['headers'][3]['value']='<different-source@example.test>';
        queue_reject(409,static fn()=>$source($expired),'a changed original '.$change.' cannot be presented as the approved source');
    }
    $raw=$original;
    queue_check(queue_state($pdo)===$before,'source mismatch checks do not mutate the draft into a new thread or state');
    $race=static function(string $method,string $url,array $headers,?array $payload,bool $form) use ($pdo,$transport): array {
        $result=$transport($method,$url,$headers,$payload,$form);
        $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=0 WHERE id=7');
        return $result;
    };
    try { queue_reject(409,static fn()=>$source($expired,$race),'grant revocation during the provider read suppresses the fetched source'); }
    finally { $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=1 WHERE id=7'); }
    $race=static function(string $method,string $url,array $headers,?array $payload,bool $form) use ($pdo,$transport,$expired): array {
        $result=$transport($method,$url,$headers,$payload,$form);
        $pdo->prepare('UPDATE agent_connection_email_drafts_v1 SET status=\'answered\' WHERE id=?')->execute([$expired['id']]);
        return $result;
    };
    try { queue_reject(409,static fn()=>$source($expired,$race),'a concurrent lifecycle change suppresses the fetched source'); }
    finally { $pdo->prepare('UPDATE agent_connection_email_drafts_v1 SET status=\'prepared\' WHERE id=?')->execute([$expired['id']]); }
    $race=static function(string $method,string $url,array $headers,?array $payload,bool $form) use ($pdo,$transport,$expired): array {
        $result=$transport($method,$url,$headers,$payload,$form);
        $pdo->prepare('UPDATE agent_connection_email_drafts_v1 SET recipient=\'other@example.test\' WHERE id=?')->execute([$expired['id']]);
        return $result;
    };
    try { queue_reject(409,static fn()=>$source($expired,$race),'a concurrent saved snapshot change suppresses the fetched source'); }
    finally { $pdo->prepare('UPDATE agent_connection_email_drafts_v1 SET recipient=\'sender@example.test\' WHERE id=?')->execute([$expired['id']]); }
    $race=static function(string $method,string $url,array $headers,?array $payload,bool $form) use ($pdo,$transport): array {
        $result=$transport($method,$url,$headers,$payload,$form);
        $pdo->exec('UPDATE chat_conversations SET user_agent_id=8 WHERE id=11');
        return $result;
    };
    try { queue_reject(403,static fn()=>$source($expired,$race),'loss of original Chat access during the read suppresses the fetched source'); }
    finally { $pdo->exec('UPDATE chat_conversations SET user_agent_id=7 WHERE id=11'); }

    // Create an organic reply through the real coordination source and storage
    // flow. Only the inference boundary is a deterministic fixture.
    $now=time();
    $rules=agent_connection_gmail_inbox_rules_v1(['triage_enabled'=>'1','content_analysis'=>'1']);
    $pdo->prepare("INSERT INTO agent_connection_gmail_inbox_v1
        (owner_user_id,connection_id,authorization_key,enabled,rules_json,revision,history_id,enabled_at_ms,next_check_at,last_checked_at,last_status)
        VALUES(1,7,?,1,?,?,'1234',?,?,?,'checked')")
        ->execute([str_repeat('a',64),json_encode($rules,JSON_THROW_ON_ERROR),str_repeat('d',32),($now-86400)*1000,
            gmdate('Y-m-d H:i:s',$now+3600),gmdate('Y-m-d H:i:s',$now-60)]);
    $policy=agent_connection_gmail_inbox_policy_v1($pdo,1);
    $bound=agent_email_matters_policy_scope_v1($policy);
    $item=agent_email_matters_source_key_v1($policy,'triage','source_exact');
    $threadKey=agent_email_matters_source_key_v1($policy,'thread','source_thread');
    $pdo->prepare("INSERT INTO agent_email_triage_v1(triage_id,owner_user_id,connection_id,grant_key,message_id_enc,thread_id_enc,
        received_ms,sender_address,subject,context_key,purpose_key,sender_key,attention_key,group_key,reason_key,is_historical,observed_at)
        VALUES(?,?,?,?,?,?,?,'sender@example.test','Original fixture subject','unknown','request','unknown','review','active','unclassified',1,?)")
        ->execute([$item,...$bound,ai_encrypt_secret('source_exact'),ai_encrypt_secret('source_thread'),($now-3600)*1000,
            gmdate('Y-m-d H:i:s',$now-1800)]);
    $pdo->exec("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json)
        VALUES (11,1,'user','Yes, please offer Friday.','{}')");
    $origin=(int)$pdo->lastInsertId();
    $primary='gmail:'.$threadKey;
    $deadline=gmdate('Y-m-d\TH:i:s\Z',$now+86400);
    $evidence=[$primary=>['specialist'=>'gmail','summary'=>'A decision is needed before the fixture deadline.',
        'priority'=>'high','due_at'=>$deadline,'source_action'=>['type'=>'gmail_triage','item_ref'=>$item,
            'generation'=>agent_connection_gmail_inbox_notice_generation_v1($policy)]]];
    $goal=['tool'=>'coordination.review','gmail_reply_draft'=>true,'primary'=>$primary,
        'coordination_id'=>str_repeat('e',64),'sources'=>[$primary=>str_repeat('f',64)],
        'binding'=>['agent_id'=>7,'conversation_id'=>11],'followthrough'=>['reply_message_id'=>$origin],
        'gmail_reply_question'=>'Should I offer Friday?'];
    $continuation=['goal'=>['primary'=>$primary,'coordination_route'=>['action'=>'ask']],
        'continuation_owner_reply'=>'Yes, please offer Friday.'];
    $modelCalls=0;
    $infer=static function(string $json,string $protocol) use (&$modelCalls,$sourceBody): string {
        $modelCalls++;
        $prompt=json_decode($json,true,16,JSON_THROW_ON_ERROR);
        queue_check($protocol==='vp3.initiative-draft.v1' && $prompt['original_message']['excerpt']===$sourceBody
            && $prompt['owner_reply']==='Yes, please offer Friday.' && !str_contains($json,'<html>'),
            'organic preparation uses the exact owner answer and plain source at the mocked inference boundary');
        return '{"draft":"Friday works. Please confirm the time."}';
    };
    $prepared=vp3_coordination_gmail_draft_prepare_v1($pdo,$owner,$goal,$evidence,$continuation,$infer,$transport);
    agent_commerce_atomic_pa2($pdo,static fn()=>vp3_coordination_gmail_draft_store_v1($pdo,$owner,$goal,$evidence,$prepared['draft']));
    $organicId=$prepared['draft']['id'];
    $organicRow=agent_connection_email_chat_draft_v1($pdo,1,7,11,$organicId);
    $organicPayload=agent_connection_email_chat_payload_v1($organicRow);
    queue_check((int)$organicRow['expires_at']===0 && $organicPayload['composer']['version']===2
        && $organicPayload['composer']['origin']==='organic_reply' && $organicRow['status']==='prepared'
        && $organicPayload['reply']===$replyBinding && $modelCalls===1,
        'new organic review drafts receive explicit durable lifecycle metadata with their exact source binding');
    $duplicateRejected=false;
    try { agent_commerce_atomic_pa2($pdo,static fn()=>vp3_coordination_gmail_draft_store_v1($pdo,$owner,$goal,$evidence,$prepared['draft'])); }
    catch (PDOException $error) { if ($error->getCode()!=='23000') throw $error; $duplicateRejected=true; }
    queue_check($duplicateRejected && (int)$pdo->query('SELECT COUNT(*) FROM agent_connection_email_drafts_v1 WHERE user_message_id='.$origin)->fetchColumn()===1,
        'the canonical owner-request key prevents duplicate organic queue rows');
    $future=agent_connection_email_chat_public_draft_v1($organicRow,$key,time()+86400*365);
    queue_check($future['status']==='prepared' && $future['persistent'] && $future['can_edit']
        && $future['edit_revision']===agent_connection_email_chat_review_hash_v1($organicRow,false),
        'organic retention survives elapsed days without inventing a new content revision');
    $queued=$list(['scope'=>'agent','limit'=>50]);
    $organicSummary=array_column($queued['drafts'],null,'id')[$organicId];
    queue_check($organicSummary['source']['kind']==='organic_reply' && $organicSummary['can_open_source']
        && $organicSummary['source']['reason']===$evidence[$primary]['summary']
        && $organicSummary['source']['priority']==='high' && $organicSummary['source']['deadline']===$deadline
        && !array_key_exists('body',$organicSummary),
        'an organic draft exposes review context and exact source access without mailbox or draft body text');
    $beforeRead=count($requests);
    $pdo->prepare('UPDATE agent_connections_v1 SET client_fingerprint=? WHERE id=7')->execute([str_repeat('9',64)]);
    try {
        queue_reject(409,static fn()=>$source($organicRow),'an OAuth application change blocks source reads before Gmail access');
        $projection=$act(11,['action'=>'review','draft_id'=>$organicId]);
        $queue=$list(['scope'=>'agent','limit'=>50]);
        $blocked=array_column($queue['drafts'],null,'id')[$organicId];
        queue_check(!$projection['can_open_source'] && $projection['subject']==='' && $projection['source']['reason']===null
            && !$blocked['can_open_source'] && $blocked['source']['reason']===null && $blocked['to']===''
            && count($requests)===$beforeRead,
            'changed OAuth authority hides original evidence context in both the selected draft and queue');
    } finally { $pdo->prepare('UPDATE agent_connections_v1 SET client_fingerprint=? WHERE id=7')->execute([agent_connections_client_fingerprint_v1()]); }
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[QueueRaceStatement::class]);
    $revokedDuringProjection=false;
    $GLOBALS['queue_fetch_hook']=static function(string $query,mixed $row) use ($pdo,&$revokedDuringProjection): void {
        if (!str_starts_with($query,'SELECT * FROM agent_connections_v1 ') || !str_ends_with($query,' FOR UPDATE')
            || !is_array($row) || (int)$row['id']!==7) return;
        unset($GLOBALS['queue_fetch_hook']);
        $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=0 WHERE id=7');
        $revokedDuringProjection=true;
    };
    try {
        $projectionBlocked=false;
        try {
            $raced=$list(['scope'=>'agent','limit'=>1]);
            $row=$raced['drafts'][0]??[];
            $projectionBlocked=($row['id']??null)===$organicId && !empty($row['connection_changed'])
                && $row['from']==='' && $row['to']==='' && $row['subject']===''
                && !$row['can_open_source'] && !$row['can_edit'] && !$row['can_send']
                && $row['attachment_count']===0 && $row['source']['reason']===null
                && $row['source']['priority']===null && $row['source']['deadline']===null
                && !isset($row['body'],$row['review_token'],$row['watch_review_token']);
        } catch (DomainException $error) {
            if ($error->getCode()!==409) throw $error;
            $projectionBlocked=true;
        }
        queue_check($projectionBlocked,
            'a grant change between envelope and source projection cannot release stale private queue fields');
        queue_check($revokedDuringProjection,'the projection-race fixture revokes a real native grant during the page read');
    } finally {
        unset($GLOBALS['queue_fetch_hook']);
        $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=1 WHERE id=7');
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PDOStatement::class]);
    }
    $pdo->exec('UPDATE agent_connections_v1 SET send_enabled=0 WHERE id=7');
    try {
        $projection=$act(11,['action'=>'review','draft_id'=>$organicId]);
        $readOnly=$source($organicRow);
        queue_check($projection['can_open_source'] && !$projection['can_send']
            && $readOnly['message']['body']===$sourceBody,
            'revoking send permission preserves separately authorized read-only access to the original email');
    } finally { $pdo->exec('UPDATE agent_connections_v1 SET send_enabled=1 WHERE id=7'); }
    $organicReview=$act(11,['action'=>'review','draft_id'=>$organicId]);
    $organicSaved=$act(11,['action'=>'compose_save','draft_id'=>$organicId,'review_token'=>$organicReview['review_token'],
        'to'=>$organicReview['to'],'subject'=>$organicReview['subject'],'body'=>'Friday works. Thank you.','attachment_ids'=>[]]);
    $organicCurrent=agent_connection_email_chat_draft_v1($pdo,1,7,11,$organicId);
    $savedPayload=agent_connection_email_chat_payload_v1($organicCurrent);
    queue_check($savedPayload['composer']['version']===2
        && $savedPayload['composer']['created_at']===$organicPayload['composer']['created_at']
        && $savedPayload['composer']['source']===$organicPayload['composer']['source']
        && $organicSaved['edit_revision']!==$organicReview['edit_revision'],
        'saving an organic edit preserves original review context and creation time under a fresh exact revision');
    foreach (['origin','priority','deadline','created_at'] as $field) {
        $invalidPayload=$organicPayload;
        if ($field==='origin') $invalidPayload['composer']['origin']='untrusted';
        elseif ($field==='priority') $invalidPayload['composer']['source']['priority']='urgent';
        elseif ($field==='deadline') $invalidPayload['composer']['source']['deadline']='2026-02-30';
        else $invalidPayload['composer']['created_at']=$invalidPayload['composer']['saved_at']+1;
        $invalidRow=array_replace($organicRow,['message_enc'=>ai_encrypt_secret(json_encode($invalidPayload,JSON_THROW_ON_ERROR))]);
        queue_reject(503,static fn()=>agent_connection_email_chat_payload_v1($invalidRow),
            'invalid organic '.$field.' metadata cannot become an authorized saved proposal');
    }

    $review=$act(11,['action'=>'review','draft_id'=>$first['id']]);
    $expiredToken=initiative_workbench_review_token_v1($key,$review['edit_revision'],time()-1);
    queue_reject(409,static fn()=>$act(11,['action'=>'confirm','draft_id'=>$first['id'],'review_token'=>$expiredToken,
        'confirmed'=>true,'watch_replies'=>false]),'durable retention does not extend the lifetime of send approval');
    queue_check((int)$pdo->query('SELECT COUNT(*) FROM agent_connection_sends_v1')->fetchColumn()===0,
        'expired approval cannot create a send claim');
    $changed=$act(11,['action'=>'compose_save','draft_id'=>$first['id'],'review_token'=>$review['review_token'],
        'to'=>$review['to'],'subject'=>$review['subject'],'body'=>'Newer privately saved edit.','attachment_ids'=>[]]);
    queue_reject(409,static fn()=>$act(11,['action'=>'discard','draft_id'=>$first['id'],'edit_revision'=>$review['edit_revision']]),
        'an older queue revision cannot discard a newer saved edit');
    queue_reject(422,static fn()=>$act(11,['action'=>'discard','draft_id'=>$first['id'],'edit_revision'=>'invalid']),
        'a malformed discard revision is rejected');
    $survived=$act(11,['action'=>'review','draft_id'=>$first['id']]);
    queue_check($survived['status']==='prepared' && $survived['body']==='Newer privately saved edit.'
        && $survived['edit_revision']===$changed['edit_revision'],'failed stale discard leaves the newer saved snapshot intact');
    $discarded=$act(11,['action'=>'discard','draft_id'=>$first['id'],'edit_revision'=>$changed['edit_revision']]);
    $discardedRow=agent_connection_email_chat_draft_v1($pdo,1,7,11,$first['id']);
    queue_check($discarded['status']==='discarded' && $discardedRow['message_enc']==='' && !$discarded['can_edit']
        && !$discarded['can_send'],'discarding the exact current revision clears the private draft content');
    $expiredDiscard=$act(11,['action'=>'discard','draft_id'=>$expired['id'],'edit_revision'=>$read['edit_revision']]);
    queue_check($expiredDiscard['status']==='discarded','an expired proposal remains explicitly discardable');
    $legacyDiscard=$act(12,['action'=>'discard','draft_id'=>$second['id']]);
    queue_check($legacyDiscard['status']==='discarded','legacy discard callers without a revision remain compatible');
    echo 'EMAIL_REVIEW_QUEUE=PASS ('.($GLOBALS['queue_checks']??0)." assertions, mysql)\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    foreach (['ai-key.php','ai-key.php.lock'] as $file) if (is_file($fixtureRoot.'/private/'.$file)) unlink($fixtureRoot.'/private/'.$file);
    if (is_dir($fixtureRoot.'/private')) rmdir($fixtureRoot.'/private');
    rmdir($fixtureRoot);
}
