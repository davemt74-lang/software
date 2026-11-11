<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-initial-scan-v1.php';

function initial_scan_check(bool $passed, string $label): void
{
    if (!$passed) throw new RuntimeException($label);
    echo 'PASS '.$label.PHP_EOL;
}

function initial_scan_list(array $ids, ?string $next=null): array
{
    $data = ['messages'=>array_map(static fn(string $id): array => ['id'=>$id],$ids)];
    if ($next!==null) $data['nextPageToken']=$next;
    return ['status'=>200,'json'=>$data];
}

function initial_scan_message(string $id, int $date, array $labels=['INBOX'], string $from='Sender <sender@example.test>', string $reply=''): array
{
    $headers = ['From'=>$from,'Cc'=>'review@example.test','Subject'=>$id,'In-Reply-To'=>$reply];
    return ['status'=>200,'json'=>['id'=>$id,'internalDate'=>(string)$date,'labelIds'=>$labels,'snippet'=>'SNIPPET_SENTINEL',
        'payload'=>['headers'=>array_map(static fn(string $name,string $value): array=>['name'=>$name,'value'=>$value],array_keys($headers),$headers),
            'body'=>['data'=>'BODY_SENTINEL']]]];
}

initial_scan_check(agent_connection_gmail_subject_priority_signals_v1('URGENT · Past-due · Action required · urgent · Important')
    === ['urgent','past due','action required','important'], 'subject signals match complete keywords and phrases once');
initial_scan_check(agent_connection_gmail_subject_priority_signals_v1("Not urgent, non-important, no immediate action required, isn’t yet past due") === []
    && agent_connection_gmail_subject_priority_signals_v1('Not urgent or important') === []
    && agent_connection_gmail_subject_priority_signals_v1('Unimportant and urgently, action requirements, past dues') === []
    && agent_connection_gmail_subject_priority_signals_v1('Not urgent, but action required') === ['action required'],
    'subject signals ignore word fragments and direct negation without hiding unrelated positive phrases');

$start=1794337890123;
$lower=$start-7*86400000;
$policy=['enabled_at_ms'=>$start,'authorization_key'=>str_repeat('a',64)];
$key=static fn(string $id): string => agent_connection_gmail_inbox_key_v1($policy,'message:'.$id);
$queue=[];
$messages=[];
$queries=[];
$reads=[];
$afterRead=null;
$http=static function(string $method,string $url,array $headers,?array $body,bool $form)
    use (&$queue,&$messages,&$queries,&$reads,&$afterRead): array {
    if ($method!=='GET' || $body!==null || $form || !in_array('Authorization: Bearer initial-scan-fixture',$headers,true)
        || !str_starts_with($url,VP3_GMAIL_API_V1.'/messages')) throw new LogicException('Unexpected provider request.');
    $path=(string)parse_url($url,PHP_URL_PATH);
    parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
    if (str_ends_with($path,'/messages')) {
        $queries[]=$query;
        if (!$queue) throw new LogicException('Unexpected list request.');
        return array_shift($queue);
    }
    if (($query['format']??'')!=='metadata' || ($query['fields']??'')!=='id,internalDate,labelIds,payload(headers)'
        || !str_contains($url,'metadataHeaders=From&metadataHeaders=Cc&metadataHeaders=Subject&metadataHeaders=In-Reply-To')) {
        throw new LogicException('Initial review requested non-approved message data.');
    }
    $id=basename($path);
    $reads[]=$id;
    if ($afterRead!==null) $afterRead();
    return $messages[$id]??['status'=>404,'json'=>[]];
};
$scan=static fn(int $days=7,string $page='',int $scanned=0,int $pages=0,?callable $current=null,?callable $seen=null): array =>
    agent_connection_gmail_inbox_initial_scan_v1($policy,'initial-scan-fixture',$start,$days,$page,$scanned,$pages,$http,
        $current??static fn(): bool=>true,$seen??static fn(): bool=>false);

$messages=[
    'first_bound'=>initial_scan_message('first_bound',$lower),
    'too_old'=>initial_scan_message('too_old',$lower-1),
    'end_bound'=>initial_scan_message('end_bound',$start),
    'archived'=>initial_scan_message('archived',$start-1,[]),
    'multi_sender'=>initial_scan_message('multi_sender',$start-1,['INBOX'],'first@example.test, second@example.test'),
    'before_end'=>initial_scan_message('before_end',$start-1,['INBOX','IMPORTANT'],'sender@example.test','<previous@example.test>'),
    'sent'=>initial_scan_message('sent',$start-1,['INBOX','SENT']),
    'draft'=>initial_scan_message('draft',$start-1,['INBOX','DRAFT']),
];
$opaque='opaque+/=';
$queue=[initial_scan_list(['first_bound','too_old','end_bound','archived','multi_sender'],$opaque),
    initial_scan_list(['first_bound','before_end','deleted','sent','draft'])];
$first=$scan();
$expectedQuery=['q'=>'after:'.(intdiv($lower,1000)-1).' before:'.(intdiv($start,1000)+1),
    'labelIds'=>'INBOX','includeSpamTrash'=>'false','maxResults'=>'5','fields'=>'messages(id),nextPageToken'];
initial_scan_check($queries===[$expectedQuery] && $first['status']==='checked' && $first['more'] && !$first['truncated']
    && $first['page']===$opaque && $first['scanned']===5 && $first['pages']===1 && count($reads)===5,
    'one bounded Inbox page uses frozen epoch bounds, metadata only, and no unread filter');
initial_scan_check(array_filter($first['messages'])===[$key('first_bound')=>[
    'message_id'=>'first_bound','from'=>'sender@example.test','cc'=>'review@example.test','subject'=>'first_bound','important'=>false,'reply'=>false]],
    'exact inclusive lower bound includes an already-read arrival; older, start-bound, archived, and invalid sender are excluded');
$scannedMessage = $first['messages'][$key('first_bound')];
foreach ([null,'bad/id'] as $invalidId) {
    $item = $scannedMessage;
    if ($invalidId === null) unset($item['message_id']);
    else $item['message_id'] = $invalidId;
    $rejected = false;
    try { agent_connection_gmail_inbox_saved_item_v1($item); }
    catch (UnexpectedValueException) { $rejected = true; }
    initial_scan_check($rejected,'a '.($invalidId === null ? 'missing' : 'malformed').' provider ID cannot become a saved review item');
}
$second=$scan(7,$first['page'],$first['scanned'],$first['pages'],null,static fn(string $id): bool=>array_key_exists($id,$first['messages']));
initial_scan_check($queries[1]===$expectedQuery+['pageToken'=>$opaque] && !$second['more'] && !$second['truncated']
    && $second['scanned']===10 && $second['pages']===2 && $second['page']==='' && count($reads)===9,
    'opaque continuation preserves exact query and run-local deduplication');
$received=array_filter($second['messages']);
initial_scan_check(array_keys($received)===[$key('before_end')] && $received[$key('before_end')]['important']
    && $received[$key('before_end')]['reply'] && !str_contains(json_encode([$first,$second],JSON_THROW_ON_ERROR),'SENTINEL'),
    'strict upper bound accepts preceding millisecond and retains only approved review data, excluding deleted/sent/draft mail');

foreach ([1,3,14,30] as $days) {
    $queue=[['status'=>200,'json'=>[]]];
    $empty=$scan($days);
    $last=$queries[array_key_last($queries)];
    initial_scan_check($last['q']==='after:'.(intdiv($start-$days*86400000,1000)-1).' before:'.(intdiv($start,1000)+1)
        && !$empty['more'] && !$empty['truncated'] && $empty['messages']===[],
        $days.'-day selectable lookback and empty mailbox result');
}
$queue=[initial_scan_list(['first_bound','first_bound'])];
$before=count($reads);
$duplicate=$scan();
initial_scan_check(count($reads)===$before+1 && $duplicate['scanned']===2 && count($duplicate['messages'])===1,
    'duplicate references consume the candidate budget without another header read');

$queue=[initial_scan_list(['first_bound','before_end','archived','sent','draft'],'provider-has-more')];
$before=count($reads);
$capped=$scan(7,'prior-page',45,9);
initial_scan_check(count($reads)===$before+5 && $capped['scanned']===50 && !$capped['more'] && $capped['truncated'] && $capped['page']==='',
    'candidate cap stops at fifty, explicitly reporting more provider results');
$queue=[initial_scan_list(['first_bound','before_end','archived','sent','draft'])];
$before=count($reads);
$capped=$scan(7,'prior-page',48,10);
initial_scan_check(count($reads)===$before+2 && $capped['scanned']===50 && $capped['truncated'],
    'a final provider page larger than the remaining budget is only partly read');
$queue=[initial_scan_list(['first_bound','before_end','archived','sent','draft'])];
$exact=$scan(7,'prior-page',45,9);
initial_scan_check($exact['scanned']===50 && !$exact['more'] && !$exact['truncated'],'exactly fifty complete candidates are not labeled truncated');
$queue=[initial_scan_list([],'sparse-page')];
$sparse=$scan(7,'prior-page',0,11);
initial_scan_check($sparse['pages']===12 && !$sparse['more'] && $sparse['truncated'] && $sparse['messages']===[],
    'empty continuation pages also have a fixed bound');

$before=count($queries);
$revoked=$scan(current:static fn(): bool=>false);
initial_scan_check($revoked['status']==='revoked' && count($queries)===$before,'revoked claim never makes a list request');
$live=true;
$afterRead=static function()use(&$live): void { $live=false; };
$queue=[initial_scan_list(['first_bound','before_end'],'uncommitted-page')];
$before=count($reads);
$revoked=$scan(current:static function()use(&$live): bool { return $live; });
initial_scan_check(count($reads)===$before+1 && $revoked['status']==='revoked' && $revoked['messages']===[]
    && $revoked['page']==='' && $revoked['scanned']===0 && $revoked['pages']===0,
    'revocation during a metadata read stops further requests and drops the entire uncommitted slice');
$afterRead=null;

foreach ([
    [initial_scan_list([],'prior-page'),502,'repeated continuation'],
    [initial_scan_list(['bad/id']),502,'malformed provider reference'],
    [['status'=>200,'json'=>['messages'=>null]],502,'invalid provider record list'],
    [['status'=>400,'json'=>[]],400,'expired continuation'],
] as [$response,$code,$label]) {
    $queue=[$response];
    $before=count($queries);
    $caught=null;
    try { $scan(7,'prior-page',5,1); } catch (AgentConnectionGmailExceptionV1 $error) { $caught=$error->getCode(); }
    initial_scan_check($caught===$code && count($queries)===$before+1,$label.' fails without restarting or replaying the window');
}
$caught=false;
try { $scan(2); } catch (InvalidArgumentException) { $caught=true; }
initial_scan_check($caught,'unsupported lookback fails before provider access');

$privateRoot = sys_get_temp_dir().'/vp3-gmail-scan-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$privateRoot);
mkdir($privateRoot.'/private',0700,true);
$invalidKey = $privateRoot.'/private/ai-key.php';
file_put_contents($invalidKey,'<?php return null;');
try {
    $rejected = false;
    try { agent_connection_gmail_inbox_saved_item_v1($scannedMessage); }
    catch (RuntimeException $error) { $rejected = str_contains($error->getMessage(),'encryption key'); }
    initial_scan_check($rejected,'an encryption failure cannot save an otherwise valid Gmail message as headers only');
} finally {
    unlink($invalidKey);
    rmdir($privateRoot.'/private');
    rmdir($privateRoot);
}
