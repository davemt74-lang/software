<?php
declare(strict_types=1);

// Real MySQL, private Chat authority, encryption and grant validation. The
// provider is a bounded in-process HTTP fixture; no mailbox or model is called.
require_once dirname(__DIR__).'/includes/agent-email-sent-v1.php';
require_once dirname(__DIR__).'/includes/agent-capability-chat-v1.php';
require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__).'/includes/participant-updates-v1.php';

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
function sent_check(bool $ok,string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['sent_checks']=($GLOBALS['sent_checks']??0)+1;
    echo 'PASS '.$label.PHP_EOL;
}
function sent_reject(int $status,callable $work,string $label): void
{
    try { $work(); }
    catch (RuntimeException|DomainException $error) {
        if ($error instanceof PDOException || (int)$error->getCode()!==$status) throw $error;
        sent_check(true,$label);
        return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}
function sent_mail(string $id,array $replace=[]): array
{
    return array_replace_recursive([
        'id'=>$id,'threadId'=>'thread-'.$id,'labelIds'=>['SENT'],'internalDate'=>(string)(time()*1000-120000),
        'snippet'=>'A bounded Sent preview.','payload'=>['mimeType'=>'text/plain',
            'headers'=>[['name'=>'From','value'=>'Owner Alias <alias@example.test>'],['name'=>'To','value'=>'Client <client@example.test>'],
                ['name'=>'Subject','value'=>'Sent café '.$id]],
            'body'=>['data'=>rtrim(strtr(base64_encode('PRIVATE SENT BODY '.$id),'+/','-_'),'=')]],
    ],$replace);
}
function sent_provider(array $pages,array $messages,?callable $after=null,array $errors=[]): callable
{
    return static function(string $method,string $url,array $headers,?array $body,bool $form) use ($pages,$messages,$after,$errors): array {
        if ($method!=='GET' || $body!==null || $form || !str_starts_with($url,VP3_GMAIL_API_V1.'/messages')) {
            throw new RuntimeException('Sent browsing must use only Gmail message GETs.');
        }
        if (!in_array('Authorization: Bearer sent-owner-access',$headers,true)) throw new RuntimeException('Unexpected fixture mailbox token.');
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        $path=(string)parse_url($url,PHP_URL_PATH);
        $GLOBALS['sent_http'][]=['method'=>$method,'path'=>$path,'query'=>$query];
        if (str_ends_with($path,'/messages')) {
            if (($query['labelIds']??null)!=='SENT' || ($query['includeSpamTrash']??null)!=='false'
                || !preg_match('/\Abefore:[1-9][0-9]*\z/D',$query['q']??'') || (int)($query['maxResults']??0)<1
                || (int)$query['maxResults']>20 || ($query['fields']??null)!=='messages(id,threadId),nextPageToken') {
                throw new RuntimeException('The Sent list must be bounded and constrained to SENT.');
            }
            $key=$query['pageToken']??'';
            if (isset($errors['page:'.$key])) $result=['status'=>$errors['page:'.$key],'json'=>['error'=>'fixture']];
            else {
                if (!array_key_exists($key,$pages)) throw new RuntimeException('Unexpected fixture page.');
                $result=['status'=>200,'json'=>$pages[$key]];
            }
        } else {
            $id=basename($path);
            if (!in_array($query['format']??null,['metadata','full'],true)) throw new RuntimeException('Unknown Sent detail format.');
            if (isset($errors[$id])) $result=['status'=>$errors[$id],'json'=>['error'=>'fixture']];
            elseif (!isset($messages[$id])) $result=['status'=>404,'json'=>['error'=>'fixture']];
            else $result=['status'=>200,'json'=>$messages[$id]];
        }
        if ($after) $after($path,$query,count($GLOBALS['sent_http']));
        return $result;
    };
}
function sent_read_state(PDO $pdo): array
{
    $state=[];
    foreach (['chat_messages','agent_connection_sends_v1','agent_connection_email_drafts_v1','agent_connection_email_followups_v1'] as $table) {
        $state[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    }
    return $state;
}

$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');
if (!str_starts_with($dsn,'mysql:')) throw new RuntimeException('Native MySQL is required for the Sent fixture.');
$login=getenv('VP3_TEST_MYSQL_USER')?:'root';
$password=(string)getenv('VP3_TEST_MYSQL_PASS');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO($dsn,$login,$password,$options);
$database='vp3_email_sent_'.bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$fixtureRoot=sys_get_temp_dir().'/vp3-email-sent-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$fixtureRoot);
mkdir($fixtureRoot,0700);
$config=['connections'=>['gmail'=>['client_id'=>'sent-fixture.apps.googleusercontent.com','client_secret'=>str_repeat('x',24),
    'redirect_uri'=>'https://sent-fixture.example.test/connection-oauth.php']]];

try {
    $pdo=new PDO(preg_replace('/;dbname=[^;]+/','',$dsn).';dbname='.$database,$login,$password,$options);
    $pdo->exec("CREATE TABLE users (id INT UNSIGNED PRIMARY KEY,is_active INT NOT NULL); INSERT INTO users VALUES (1,1),(2,1);
        CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NOT NULL);
        CREATE TABLE user_agents (id BIGINT UNSIGNED PRIMARY KEY,owner_user_id INT UNSIGNED NOT NULL,
            display_name VARCHAR(100) NOT NULL DEFAULT 'Fixture Agent',is_active INT NOT NULL);
        INSERT INTO user_agents (id,owner_user_id,is_active) VALUES (7,1,1),(8,1,1),(9,2,1);
        CREATE TABLE chat_conversations (id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED NOT NULL,user_agent_id BIGINT UNSIGNED NULL,
            title VARCHAR(190) NOT NULL DEFAULT '',updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
        INSERT INTO chat_conversations (id,user_id,user_agent_id) VALUES (11,1,7),(12,1,7),(13,1,8),(14,1,NULL),(21,2,9);
        CREATE TABLE chat_messages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversation_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NULL,role VARCHAR(20),message TEXT,context_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    agent_connections_install_v1($pdo);
    $connection=$pdo->prepare("INSERT INTO agent_connections_v1 (id,owner_user_id,provider,external_account_id,account_email,status,
        read_enabled,send_enabled,followups_enabled,authorization_key,client_fingerprint,access_token_enc,refresh_token_enc,access_expires_at)
        VALUES (?,?,'gmail',?,?,'connected',1,0,0,?,?,?,'',?)");
    foreach ([[7,1,'a','owner'],[9,2,'b','other']] as [$id,$ownerId,$letter,$mailbox]) {
        $connection->execute([$id,$ownerId,'sent-fixture-'.$mailbox,$mailbox.'@example.test',str_repeat($letter,64),
            agent_connections_client_fingerprint_v1(),ai_encrypt_secret('sent-'.$mailbox.'-access'),time()+86400]);
    }
    $owner=['id'=>1,'is_active'=>1];$other=['id'=>2,'is_active'=>1];
    $principal=vp3_agent_chat_principal_v380($owner,vp3_agent_chat_resolve_agent_v380($pdo,$owner,7));
    $otherAgent=vp3_agent_chat_principal_v380($owner,vp3_agent_chat_resolve_agent_v380($pdo,$owner,8));
    $otherPrincipal=vp3_agent_chat_principal_v380($other,vp3_agent_chat_resolve_agent_v380($pdo,$other,9));
    $key='private-sent-fixture-session';
    $a=sent_mail('provider-sent-a');$b=sent_mail('provider-sent-b');$c=sent_mail('provider-sent-c');
    $messages=array_column([$a,$b,$c],null,'id');
    $reference=static fn(array $mail): array=>['id'=>$mail['id'],'threadId'=>$mail['threadId']];
    $pages=[''=>['messages'=>[$reference($a),$reference($b)],'nextPageToken'=>'opaque-gmail-page-two','resultSizeEstimate'=>900],
        'opaque-gmail-page-two'=>['messages'=>[$reference($c)]]];
    $http=sent_provider($pages,$messages);
    $GLOBALS['sent_http']=[];
    $list=static fn(array $options=[],?callable $provider=null,int $chat=11): array=>agent_email_sent_list_v1(
        $pdo,$owner,$principal,$chat,$key,$options,$provider??$http);
    $open=static fn(string $ref,?callable $provider=null,int $chat=11): array=>agent_email_sent_open_v1(
        $pdo,$owner,$principal,$chat,$key,$ref,$provider??$http);
    $initial=sent_read_state($pdo);
    $first=$list(['limit'=>2]);$cursor=$first['next_cursor'];$ref=$first['items'][0]['item_ref'];
    sent_check(count($first['items'])===2 && $first['has_more'] && is_string($cursor)
        && $first['scope']==='gmail_sent' && $first['account_email']==='owner@example.test',
        'the real SENT page includes mailbox messages without a VP3 draft or send ledger entry');
    sent_check($first['items'][0]['to']==='Client <client@example.test>' && $first['items'][0]['from']==='Owner Alias <alias@example.test>'
        && str_contains($first['items'][0]['subject'],'café') && $first['items'][0]['sent_at']===gmdate(DATE_ATOM,intdiv((int)$a['internalDate'],1000)),
        'Sent headers preserve Unicode, recipients, aliases and provider timestamps');
    sent_check(count($GLOBALS['sent_http'])===3 && $GLOBALS['sent_http'][0]['query']['maxResults']==='2',
        'a page uses one bounded list plus one metadata GET per listed message');
    $json=json_encode($first,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    // Subjects are intentionally user-visible, so remove the fixture identifier
    // from those headers before checking opaque transport references themselves.
    $opaque=json_encode([$cursor,$ref,$first['mailbox_revision']],JSON_THROW_ON_ERROR);
    sent_check(!str_contains($opaque,'provider-sent-a') && !str_contains($opaque,'opaque-gmail-page-two')
        && !str_contains($opaque,str_repeat('a',64)) && !str_contains($json,'PRIVATE SENT BODY')
        && !str_contains($json,'sent-owner-access') && !isset($first['total'],$first['resultSizeEstimate']),
        'Sent references conceal IDs, page tokens and grants; metadata omits bodies and exact-total claims');
    $cipher=base64_decode(strtr(explode('.',$ref)[0],'-_','+/'),true);
    sent_check(is_string($cipher) && (str_starts_with($cipher,'sbx1:') || str_starts_with($cipher,'gcm1:')),
        'an item reference contains authenticated ciphertext rather than a readable payload');
    $second=$list(['limit'=>2,'cursor'=>$cursor]);
    sent_check(count($second['items'])===1 && !$second['has_more'] && $second['next_cursor']===null
        && $second['mailbox_revision']===$first['mailbox_revision']
        && $GLOBALS['sent_http'][3]['query']['q']===$GLOBALS['sent_http'][0]['query']['q'],
        'next-page reads retain the same mailbox revision and frozen before-time boundary');
    $opened=$open($ref);
    sent_check($opened['body']==='PRIVATE SENT BODY provider-sent-a' && $opened['to']===$first['items'][0]['to']
        && $opened['sent_at']===$first['items'][0]['sent_at'] && $opened['can_reply']===false
        && $opened['mailbox_revision']===$first['mailbox_revision'] && !isset($opened['review_token'],$opened['draft_id'],$opened['message_id']),
        'opening an exact Sent message returns readonly body and matching mailbox identity without draft/send authority');
    sent_check(sent_read_state($pdo)===$initial,'Sent list and open do not write Chat, drafts, send claims or reply watches');
    sent_check($first['mailbox_revision']===hash('sha256',json_encode(['vp3-gmail-mailbox-v1',7,str_repeat('a',64),
        agent_connections_client_fingerprint_v1(),'owner@example.test'],JSON_THROW_ON_ERROR)),
        'Sent and the provider-free workspace share the same opaque mailbox revision');

    $scope=agent_email_sent_scope_v1($pdo,$owner,$principal,11);
    $beforeCalls=count($GLOBALS['sent_http']);
    $tampered=($ref[0]==='A'?'B':'A').substr($ref,1);
    sent_reject(409,static fn()=>$open($tampered),'tampered encrypted item references cannot make provider reads');
    sent_reject(409,static fn()=>$open($cursor),'a page cursor cannot be used as a Sent message reference');
    sent_reject(409,static fn()=>$list(['limit'=>2,'cursor'=>$ref]),'a message reference cannot be used as a page cursor');
    sent_reject(409,static fn()=>$list(['limit'=>3,'cursor'=>$cursor]),'a page cursor cannot change its bounded page size');
    sent_reject(409,static fn()=>agent_email_sent_open_v1($pdo,$owner,$principal,11,'another-session',$ref,$http),
        'a Sent reference cannot be replayed into another browser session');
    sent_reject(409,static fn()=>agent_email_sent_list_v1($pdo,$owner,$principal,11,'another-session',
        ['limit'=>2,'cursor'=>$cursor],$http),'a Sent page cursor cannot be replayed into another browser session');
    sent_reject(409,static fn()=>$list(['limit'=>2,'cursor'=>$cursor],null,12),
        'a retained Next page cannot be read after changing private Chats');
    sent_reject(409,static fn()=>$open($ref,null,12),'a Sent reference cannot move into another private Chat');
    sent_reject(409,static fn()=>agent_email_sent_open_v1($pdo,$owner,$otherAgent,13,$key,$ref,$http),
        'a Sent reference cannot move into another Agent namespace');
    sent_reject(409,static fn()=>agent_email_sent_open_v1($pdo,$other,$otherPrincipal,21,$key,$ref,$http),
        'a Sent reference cannot move into another mailbox owner');
    $expired=agent_email_sent_token_v1($key,'item',$scope,['id'=>$a['id'],'thread'=>$a['threadId']],time()-1);
    $future=agent_email_sent_token_v1($key,'item',$scope,['id'=>$a['id'],'thread'=>$a['threadId']],time()+AGENT_EMAIL_SENT_TOKEN_TTL_V1+100);
    sent_reject(409,static fn()=>$open($expired),'expired Sent references require a fresh list');
    sent_reject(409,static fn()=>$open($future),'overlong reference lifetimes cannot extend the browsing session');
    sent_reject(409,static fn()=>$open(str_repeat('a',4001)),'oversized item references are bounded before decryption');
    sent_reject(409,static fn()=>$list(['cursor'=>str_repeat('a',24001)]),'oversized page references are bounded before decryption');
    sent_reject(403,static fn()=>$list([],null,13),'the starting Chat must belong to the selected Agent');
    sent_reject(403,static fn()=>agent_email_sent_list_v1($pdo,$owner,array_replace($principal,['viewer_user_id'=>2]),11,$key,[],$http),
        'a delegated viewer cannot browse the owner Sent mailbox');
    $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1,'agent'=>7,'participant'=>99];
    try { sent_reject(403,static fn()=>$list(),'a participant task cannot read private Sent mail'); }
    finally { unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']); }
    sent_check(count($GLOBALS['sent_http'])===$beforeCalls,'invalid scope, session and references cause no provider access');
    foreach ([['limit'=>0],['limit'=>21],['limit'=>'10'],['cursor'=>''],['query'=>'anything']] as $invalid) {
        sent_reject(422,static fn()=>$list($invalid),'invalid or unbounded Sent list inputs are rejected');
    }

    $empty=$list([],sent_provider([''=>[]],[]));
    sent_check($empty['items']===[] && !$empty['has_more'] && $empty['next_cursor']===null,
        'an empty real Gmail Sent mailbox is represented without a fabricated count');
    $missing=$list(['limit'=>2],sent_provider($pages,$messages,null,[$a['id']=>404]));
    sent_check(count($missing['items'])===1 && $missing['has_more'],
        'a message deleted between list and metadata is skipped without losing the next page');
    $removed=$a;$removed['labelIds']=['INBOX'];
    $filtered=$list(['limit'=>2],sent_provider($pages,array_replace($messages,[$a['id']=>$removed])));
    sent_check(count($filtered['items'])===1,'metadata must still have SENT even when the list had contained the item');
    foreach (['DRAFT','SPAM','TRASH'] as $label) {
        $wrong=$a;$wrong['labelIds'][]=$label;
        $filtered=$list(['limit'=>2],sent_provider($pages,array_replace($messages,[$a['id']=>$wrong])));
        sent_check(count($filtered['items'])===1,'SENT combined with '.$label.' cannot enter the ordinary Sent view');
    }
    sent_reject(409,static fn()=>$open($ref,sent_provider($pages,array_replace($messages,[$a['id']=>$removed]))),
        'opening rechecks SENT membership instead of trusting the earlier list');
    sent_reject(429,static fn()=>$list(['limit'=>2],sent_provider($pages,$messages,null,[$b['id']=>429])),
        'a partial-page rate limit fails coherently instead of returning a misleading completed page');
    sent_reject(503,static fn()=>$open($ref,sent_provider($pages,$messages,null,[$a['id']=>503])),
        'a failed provider body read stays an explicit unavailable response');
    sent_reject(409,static fn()=>$list(['limit'=>2,'cursor'=>$cursor],sent_provider($pages,$messages,null,['page:opaque-gmail-page-two'=>400])),
        'an expired provider page token asks for refresh rather than restarting or silently skipping pages');
    foreach ([['messages'=>'bad'],['messages'=>[$reference($a),$reference($b),$reference($c)]],
        ['messages'=>[$reference($a),$reference($a)]],['messages'=>[['id'=>'bad/id','threadId'=>'x']]],
        ['messages'=>[['id'=>$a['id']]]],['messages'=>[],'nextPageToken'=>"bad\npage"],
        ['messages'=>[],'nextPageToken'=>str_repeat('a',4097)]] as $malformed) {
        sent_reject(502,static fn()=>$list(['limit'=>2],sent_provider([''=>$malformed],$messages)),
            'malformed, duplicated or oversized provider pages cannot produce Sent results');
    }
    sent_reject(502,static fn()=>$list(['limit'=>2,'cursor'=>$cursor],sent_provider(
        ['opaque-gmail-page-two'=>['messages'=>[],'nextPageToken'=>'opaque-gmail-page-two']],$messages)),
        'a repeated provider page token cannot create an endless Next loop');
    $wrongThread=$a;$wrongThread['threadId']='other-thread';
    sent_reject(502,static fn()=>$open($ref,sent_provider($pages,array_replace($messages,[$a['id']=>$wrongThread]))),
        'an exact item reference cannot open another provider thread');
    $wrongId=$a;$wrongId['id']='another-provider-id';
    sent_reject(502,static fn()=>$open($ref,sent_provider($pages,array_replace($messages,[$a['id']=>$wrongId]))),
        'a provider response with a different message ID is rejected');
    $wrongDate=$a;$wrongDate['internalDate']='not-a-date';
    sent_reject(502,static fn()=>$list(['limit'=>2],sent_provider($pages,array_replace($messages,[$a['id']=>$wrongDate]))),
        'invalid provider dates cannot be presented as verified send times');

    $many=[];for($index=0;$index<20;$index++) { $mail=sent_mail('bounded-'.$index);$many[$mail['id']]=$mail; }
    $GLOBALS['sent_http']=[];
    $bounded=$list(['limit'=>20],sent_provider([''=>['messages'=>array_values(array_map($reference,$many))]],$many));
    sent_check(count($bounded['items'])===20 && count($GLOBALS['sent_http'])===21,
        'the maximum page makes exactly one list and at most twenty detail reads');
    $longPage=str_repeat('\\',4096);
    $longProvider=sent_provider([''=>['messages'=>[$reference($a)],'nextPageToken'=>$longPage],$longPage=>[]],$messages);
    $longFirst=$list(['limit'=>1],$longProvider);
    sent_check(strlen($longFirst['next_cursor'])<=24000 && strlen($longFirst['items'][0]['item_ref'])<=4000,
        'maximum provider page tokens remain within the browser and API opaque-reference bounds');
    $longSecond=$list(['limit'=>1,'cursor'=>$longFirst['next_cursor']],$longProvider);
    sent_check(!$longSecond['has_more'] && $longSecond['items']===[],
        'an encrypted maximum-length provider token survives exact paging without truncation');

    $html=$a;$html['payload']['mimeType']='text/html';$html['payload']['body']['data']=rtrim(strtr(base64_encode(
        '<style>.sent-note{color:#14548f}</style><div class="sent-note"><h2>Styled sent café</h2><p>Saved formatting.</p>'
        .'<script>window.privateLeak=true</script><img src="https://sender.example.test/tracker.png"></div>'),'+/','-_'),'=');
    $display=$open($ref,sent_provider($pages,array_replace($messages,[$a['id']=>$html])));
    sent_check(isset($display['display']) && str_contains($display['display']['html'],'.sent-note')
        && str_contains(html_entity_decode($display['display']['html'],ENT_QUOTES|ENT_HTML5,'UTF-8'),'Styled sent café')
        && !str_contains($display['display']['html'],'window.privateLeak')
        && $display['display']['remote_images']===1 && !str_contains($display['body'],'window.privateLeak'),
        'Sent opens through the existing safe HTML/CSS reader with remote-image consent and a clean plain fallback');
    sent_check(sent_read_state($pdo)===$initial,'Sent formatting and pagination still leave send/draft/Chat/watch storage unchanged');

    $grant=str_repeat('a',64);
    $restore=static function() use ($pdo,$grant,&$config): void {
        $config['connections']['gmail']['client_id']='sent-fixture.apps.googleusercontent.com';
        $pdo->prepare("UPDATE agent_connections_v1 SET account_email='owner@example.test',authorization_key=?,client_fingerprint=?,
            status='connected',read_enabled=1,send_enabled=0,followups_enabled=0,access_token_enc=?,refresh_token_enc='',access_expires_at=? WHERE id=7")
            ->execute([$grant,agent_connections_client_fingerprint_v1(),ai_encrypt_secret('sent-owner-access'),time()+86400]);
        $pdo->exec('UPDATE users SET is_active=1 WHERE id=1; UPDATE user_agents SET is_active=1 WHERE id=7;
            UPDATE chat_conversations SET user_id=1,user_agent_id=7 WHERE id=11');
    };
    $beforeCalls=count($GLOBALS['sent_http']);
    $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=0 WHERE id=7');
    sent_reject(403,static fn()=>$list(),'disabled read permission blocks Sent even though the mailbox is connected');$restore();
    $config['connections']['gmail']['client_id']='replaced-app.apps.googleusercontent.com';
    sent_reject(401,static fn()=>$list(),'a replacement OAuth app cannot reuse the old mailbox grant');$restore();
    $pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
    sent_reject(403,static fn()=>$open($ref),'a disabled owner cannot open a retained item reference');$restore();
    $pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');
    sent_reject(403,static fn()=>$list(),'a disabled Agent cannot browse Sent');$restore();
    $pdo->prepare('UPDATE agent_connections_v1 SET authorization_key=? WHERE id=7')->execute([str_repeat('c',64)]);
    sent_reject(409,static fn()=>$list(['limit'=>2,'cursor'=>$cursor]),'reconnected grants invalidate retained Sent cursors');
    sent_reject(409,static fn()=>$open($ref),'reconnected grants invalidate retained Sent message references');$restore();
    sent_check(count($GLOBALS['sent_http'])===$beforeCalls,'revoked owners, Agents, read grants and OAuth clients are blocked before provider work');

    $GLOBALS['sent_http']=[];
    $afterList=sent_provider($pages,$messages,static function(string $path) use($pdo): void {
        if (str_ends_with($path,'/messages')) $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=0 WHERE id=7');
    });
    sent_reject(409,static fn()=>$list(['limit'=>2],$afterList),'read revocation during listing prevents all metadata reads');
    sent_check(count($GLOBALS['sent_http'])===1,'a revoked list cannot continue fetching private message headers');$restore();
    $GLOBALS['sent_http']=[];
    $afterMetadata=sent_provider($pages,$messages,static function(string $path) use($pdo,$b): void {
        if (basename($path)===$b['id']) $pdo->prepare('UPDATE agent_connections_v1 SET authorization_key=? WHERE id=7')->execute([str_repeat('d',64)]);
    });
    sent_reject(409,static fn()=>$list(['limit'=>2],$afterMetadata),'grant rotation after the final metadata GET discards the whole projected page');
    sent_check(count($GLOBALS['sent_http'])===3,'the final metadata race uses only the bounded listed requests');$restore();
    $afterBody=sent_provider($pages,array_replace($messages,[$a['id']=>$html]),static function(string $path,array $query) use($pdo): void {
        if (($query['format']??'')==='full') $pdo->exec("UPDATE agent_connections_v1 SET account_email='changed@example.test' WHERE id=7");
    });
    sent_reject(409,static fn()=>$open($ref,$afterBody),'a mailbox replacement during full-body retrieval cannot release its HTML or text');$restore();
    $afterClient=sent_provider($pages,$messages,static function() use(&$config): void {
        $config['connections']['gmail']['client_id']='replaced-app.apps.googleusercontent.com';
    });
    sent_reject(409,static fn()=>$open($ref,$afterClient),'OAuth client changes during a message read invalidate its result');$restore();
    $afterChat=sent_provider($pages,$messages,static function() use($pdo): void {
        $pdo->exec('UPDATE chat_conversations SET user_id=2,user_agent_id=9 WHERE id=11');
    });
    sent_reject(403,static fn()=>$open($ref,$afterChat),'a reassigned private Chat cannot receive a late Sent body');$restore();

    $pdo->prepare('UPDATE agent_connections_v1 SET access_expires_at=0,refresh_token_enc=? WHERE id=7')
        ->execute([ai_encrypt_secret('sent-refresh-fixture')]);
    $refreshCalls=[];
    $refresh=static function(string $method,string $url,array $headers,?array $body,bool $form) use(&$refreshCalls,&$config): array {
        $refreshCalls[]=[$method,$url];
        if ($method!=='POST' || $url!=='https://oauth2.googleapis.com/token' || !$form) throw new RuntimeException('Only the existing token refresh is allowed here.');
        $config['connections']['gmail']['client_id']='replaced-app.apps.googleusercontent.com';
        return ['status'=>200,'json'=>['access_token'=>'sent-owner-access','expires_in'=>3600,'token_type'=>'Bearer']];
    };
    sent_reject(409,static fn()=>$list([],$refresh),'OAuth replacement during token refresh is caught before the Sent list GET');
    sent_check(count($refreshCalls)===1,'refresh races cannot dispatch a mailbox read under the old OAuth context');$restore();
    sent_check(sent_read_state($pdo)===$initial,'all read races preserve send, draft, Chat and follow-up records');
    echo 'EMAIL_SENT=PASS ('.($GLOBALS['sent_checks']??0)." assertions, mysql)\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    foreach (['ai-key.php','ai-key.php.lock'] as $file) if (is_file($fixtureRoot.'/private/'.$file)) unlink($fixtureRoot.'/private/'.$file);
    if (is_dir($fixtureRoot.'/private')) rmdir($fixtureRoot.'/private');
    rmdir($fixtureRoot);
}
