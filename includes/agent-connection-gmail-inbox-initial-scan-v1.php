<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-gmail-inbox-v1.php';

const VP3_GMAIL_INBOX_INITIAL_CANDIDATE_LIMIT_V1 = 50;
const VP3_GMAIL_INBOX_INITIAL_PAGE_SIZE_V1 = 5;
const VP3_GMAIL_INBOX_INITIAL_PAGE_LIMIT_V1 = 12;

function agent_connection_gmail_inbox_initial_token_v1(mixed $token): bool
{
    return is_string($token) && strlen($token)<=4096 && !preg_match('/[\x00-\x1f\x7f]/',$token);
}

/** One list page and at most five metadata reads. The caller owns the claim and atomic checkpoint commit. */
function agent_connection_gmail_inbox_initial_scan_v1(
    array $policy, string $access, int $startedAtMs, int $days, string $page, int $scanned, int $pages,
    ?callable $http, callable $claimCurrent, callable $alreadySeen
): array {
    if (!in_array($days,[1,3,7,14,30],true) || $startedAtMs<$days*86400000+1000
        || !agent_connection_gmail_inbox_initial_token_v1($page) || ($page==='')!==($pages===0)
        || $scanned<0 || $scanned>=VP3_GMAIL_INBOX_INITIAL_CANDIDATE_LIMIT_V1
        || $pages<0 || $pages>=VP3_GMAIL_INBOX_INITIAL_PAGE_LIMIT_V1
        || $scanned>$pages*VP3_GMAIL_INBOX_INITIAL_PAGE_SIZE_V1) {
        throw new InvalidArgumentException('Invalid initial Inbox review checkpoint.');
    }
    $revoked = ['status'=>'revoked','page'=>$page,'scanned'=>$scanned,'pages'=>$pages,'messages'=>[],'more'=>true,'truncated'=>false];
    if (!$claimCurrent()) return $revoked;

    $lowerMs = $startedAtMs-$days*86400000;
    // Gmail query timestamps use seconds. Search a superset, then enforce the exact
    // frozen millisecond window and current Inbox membership on each metadata read.
    $after = intdiv($lowerMs,1000)-1;
    $before = intdiv($startedAtMs,1000)+(int)($startedAtMs%1000!==0);
    $parameters = [
        'q'=>'after:'.$after.' before:'.$before,
        'labelIds'=>'INBOX', 'includeSpamTrash'=>'false',
        'maxResults'=>VP3_GMAIL_INBOX_INITIAL_PAGE_SIZE_V1, 'fields'=>'messages(id),nextPageToken',
    ];
    if ($page!=='') $parameters['pageToken'] = $page;
    $listed = agent_connection_gmail_api_v1($access,'/messages?'.http_build_query($parameters,'','&',PHP_QUERY_RFC3986),null,$http);
    $records = array_key_exists('messages',$listed) ? $listed['messages'] : [];
    $next = array_key_exists('nextPageToken',$listed) ? $listed['nextPageToken'] : '';
    if (!is_array($records) || !array_is_list($records) || count($records)>VP3_GMAIL_INBOX_INITIAL_PAGE_SIZE_V1
        || !agent_connection_gmail_inbox_initial_token_v1($next) || ($next!=='' && $next===$page)) {
        throw new AgentConnectionGmailExceptionV1('Gmail returned an invalid initial Inbox page.',502);
    }
    $ids = [];
    foreach ($records as $record) {
        $id = is_array($record) ? agent_connection_gmail_id_v1($record['id']??null) : '';
        if ($id==='') throw new AgentConnectionGmailExceptionV1('Gmail returned an invalid initial Inbox message reference.',502);
        $ids[] = $id;
    }

    $limit = min(count($ids),VP3_GMAIL_INBOX_INITIAL_CANDIDATE_LIMIT_V1-$scanned);
    $messages = [];
    foreach (array_slice($ids,0,$limit) as $id) {
        $key = agent_connection_gmail_inbox_key_v1($policy,'message:'.$id);
        if (array_key_exists($key,$messages) || $alreadySeen($key)) continue;
        if (!$claimCurrent()) return $revoked;
        $metadata = agent_connection_gmail_inbox_metadata_v1($access,$id,$lowerMs-1,['From','Cc','Subject','In-Reply-To'],$http,$startedAtMs);
        $messages[$key] = $metadata===null ? null : agent_connection_gmail_inbox_review_message_v1($metadata);
    }
    if (!$claimCurrent()) return $revoked;

    $scanned += $limit;
    $pages++;
    $more = $next!=='' && $scanned<VP3_GMAIL_INBOX_INITIAL_CANDIDATE_LIMIT_V1 && $pages<VP3_GMAIL_INBOX_INITIAL_PAGE_LIMIT_V1;
    return ['status'=>'checked','page'=>$more?$next:'','scanned'=>$scanned,'pages'=>$pages,'messages'=>$messages,
        'more'=>$more,'truncated'=>!$more && ($next!=='' || $limit<count($ids))];
}
