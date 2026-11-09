<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-gmail-inbox-v1.php';
require_once __DIR__.'/agent-connection-gmail-github-settings-v1.php';

function agent_connection_gmail_github_receipts_install_v1(PDO $pdo): void
{
    $sqlite = agent_commerce_sqlite_pa2($pdo);
    $pdo->exec('CREATE TABLE IF NOT EXISTS agent_connection_gmail_github_receipts_v1 (
        owner_user_id INT UNSIGNED NOT NULL,
        message_key CHAR(64) NOT NULL,
        PRIMARY KEY (owner_user_id,message_key),
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )'.($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'));
}

function agent_connection_gmail_github_receipts_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT message_key FROM agent_connection_gmail_github_receipts_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function agent_connection_gmail_github_receipts_clear_v1(PDO $pdo, int $owner): void
{
    if (!agent_connection_gmail_github_receipts_ready_v1($pdo)) return;
    $pdo->prepare('DELETE FROM agent_connection_gmail_github_receipts_v1 WHERE owner_user_id=?')->execute([$owner]);
}

function agent_connection_gmail_github_headers_v1(array $headers): array
{
    $allowed = ['from','cc','subject','list-id','x-github-reason','authentication-results'];
    $result = [];
    foreach ($headers as $header) {
        if (!is_array($header) || !is_string($header['name']??null) || !is_string($header['value']??null)) continue;
        $name = strtolower($header['name']);
        if (!in_array($name,$allowed,true)) continue;
        $value = preg_replace('/\r?\n[ \t]+/',' ',$header['value']);
        $result[$name][] = is_string($value) && strlen($value)<=8192 && !preg_match('/[\x00-\x08\x0a-\x1f\x7f]/',$value)
            ? trim($value) : '';
    }
    return $result;
}

/** Accept Gmail's own aligned result; duplicate Gmail assertions are ambiguous. */
function agent_connection_gmail_github_authenticated_v1(array $values): bool
{
    if (in_array('',$values,true)) return false;
    $gmail = array_values(array_filter($values,static fn(string $value): bool =>
        preg_match('/\Amx\.google\.com(?:[ \t]+[0-9]+)?[ \t]*;/iD',$value)===1));
    if (count($gmail)!==1) return false;
    // RFC 8601 comments may contain semicolons and method-like text. Remove
    // them before checking any result or identity property.
    $auth = preg_replace('~\((?:[^()\\\\]++|\\\\.|(?R))*\)~','',$gmail[0]);
    if (!is_string($auth)) return false;
    $auth = preg_replace('~"(?:[^"\\\\]++|\\\\.)*"~','',$auth);
    if (!is_string($auth) || strpbrk($auth,'()"\\')!==false) return false;
    $dkim = false;
    $dmarc = [];
    foreach (array_slice(explode(';',$auth),1) as $method) {
        $method = trim($method);
        if (preg_match('/\Adkim=pass(?:[ \t]|$)/iD',$method)
            && preg_match('/(?:^|[ \t])header\.(?:d=(?:[a-z0-9-]+\.)*github\.com|i=[a-z0-9._+-]*@(?:[a-z0-9-]+\.)*github\.com)(?=[ \t]|$)/iD',$method)) {
            $dkim = true;
        }
        if (preg_match('/\Admarc=([a-z]+)(?:[ \t]|$)/iD',$method,$match)) {
            $dmarc[] = strtolower($match[1])==='pass'
                && preg_match('/(?:^|[ \t])header\.from=github\.com(?=[ \t]|$)/iD',$method)===1;
        }
    }
    return $dkim && $dmarc === [true];
}

function agent_connection_gmail_github_parse_v1(array $metadata): ?array
{
    $headers = agent_connection_gmail_github_headers_v1($metadata['headers']);
    $from = $headers['from']??[];
    if (count($from)!==1 || agent_connection_gmail_inbox_mailboxes_v1($from[0])!==['notifications@github.com']
        || !agent_connection_gmail_github_authenticated_v1($headers['authentication-results']??[])) return null;
    foreach (['cc','subject','list-id','x-github-reason'] as $name) {
        if (count($headers[$name]??[])>1) return null;
    }
    $reason = $headers['x-github-reason'][0]??null;
    $cc = agent_connection_gmail_inbox_mailboxes_v1($headers['cc'][0]??null);
    if ($reason !== null ? strtolower($reason)!=='ci_activity' : !in_array('ci_activity@noreply.github.com',$cc,true)) return null;

    $owner = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?';
    $repo = '[A-Za-z0-9_.-]{1,100}';
    $verifiedSubject = trim(preg_replace('/[\p{C}\s]+/u',' ',$headers['subject'][0]??'')??'');
    $subject = $verifiedSubject;
    $list = $headers['list-id'][0]??'';
    $listRepo = '';
    if (preg_match('~\A('.$owner.')/('.$repo.')[ \t]+<([^<>]+)>\z~D',$list,$parts)
        && strcasecmp($parts[3],$parts[2].'.'.$parts[1].'.github.com')===0 && !in_array($parts[2],['.','..'],true)) {
        $listRepo = $parts[1].'/'.$parts[2];
    }
    $subjectRepo = '';
    if (preg_match('~\A\[('.$owner.')/('.$repo.')\][ \t]*~D',$subject,$parts) && !in_array($parts[2],['.','..'],true)) {
        $subjectRepo = $parts[1].'/'.$parts[2];
        $subject = substr($subject,strlen($parts[0]));
    }
    $repository = $listRepo !== '' ? $listRepo : $subjectRepo;
    $status = 'unknown';
    if ($listRepo !== '' && $subjectRepo !== '' && strcasecmp($listRepo,$subjectRepo)!==0) {
        $repository = '';
    } elseif (preg_match('/\ARun[ \t]+(failed|succeeded|successful|cancelled|canceled|neutral)[ \t]*:/iD',$subject,$parts)) {
        $status = match (strtolower($parts[1])) {
            'failed'=>'failure', 'succeeded','successful'=>'success', 'cancelled','canceled'=>'cancelled', default=>'neutral',
        };
    }
    $receivedAt = (string)($metadata['received_at_ms']??'');
    if (!preg_match('/\A[1-9][0-9]{0,12}\z/D',$receivedAt)) return null;
    return [
        'repository'=>$repository,
        'status'=>$status,
        'received_at'=>gmdate('Y-m-d H:i:s',intdiv((int)$receivedAt,1000)),
        'url'=>$repository === '' ? '' : 'https://github.com/'.$repository.'/actions',
        'subject'=>mb_substr($verifiedSubject,0,240,'UTF-8'),
        'from'=>'notifications@github.com',
    ];
}

/** Other inbox mail receives only a transient exact-From check. */
function agent_connection_gmail_github_message_v1(
    string $access, string $id, int $enabledAt, callable $claimCurrent, ?callable $http
): ?array {
    $candidate = agent_connection_gmail_inbox_metadata_v1($access,$id,$enabledAt,['From'],$http);
    $from = $candidate === null ? [] : (agent_connection_gmail_github_headers_v1($candidate['headers'])['from']??[]);
    if (count($from)!==1 || agent_connection_gmail_inbox_mailboxes_v1($from[0])!==['notifications@github.com'] || !$claimCurrent()) return null;
    $metadata = agent_connection_gmail_inbox_metadata_v1($access,$id,$enabledAt,
        ['From','Cc','Subject','List-Id','X-GitHub-Reason','Authentication-Results'],$http);
    return $metadata === null ? null : agent_connection_gmail_github_parse_v1($metadata);
}

function agent_connection_gmail_github_scan_v1(PDO $pdo, array $policy, array $connection, string $claim, string $access, ?callable $http): array
{
    $key = ai_master_key();
    if ($key === null || $connection['external_account_id'] === '') throw new LogicException('Gmail workflow source identity is unavailable.');
    $seen = $pdo->prepare('SELECT 1 FROM agent_connection_gmail_github_receipts_v1 WHERE owner_user_id=? AND message_key=?');
    $current = static fn(): bool => agent_connection_gmail_inbox_claim_current_v1($pdo,$policy,$claim,true)
        && agent_connection_gmail_github_folder_v1($pdo,(int)$policy['owner_user_id'],(int)$policy['folder_id'])!==null;
    return agent_connection_gmail_inbox_scan_checkpoint_v1($policy,$access,$http,$current,
        static function(string $receipt) use ($seen,$policy): bool {
            $seen->execute([$policy['owner_user_id'],$receipt]);
            return $seen->fetchColumn() !== false;
        },
        static function(string $id) use ($access,$policy,$connection,$key,$current,$http): ?array {
            $record = agent_connection_gmail_github_message_v1($access,$id,(int)$policy['enabled_at_ms'],$current,$http);
            if ($record === null) return null;
            $proof = hash_hmac('sha256',implode("\0",['gmail-github-actions',$policy['owner_user_id'],$connection['external_account_id'],$id]),$key);
            return ['proof'=>$proof,'record'=>$record];
        },5);
}

function agent_connection_gmail_github_grant_v1(array $user, ?array $connection, array $policy): bool
{
    return agent_connection_gmail_github_authority_v1($user,$connection)
        && (int)$connection['id']===(int)$policy['connection_id'] && (int)$connection['owner_user_id']===(int)$policy['owner_user_id']
        && hash_equals((string)$connection['authorization_key'],(string)$policy['authorization_key']);
}

function agent_connection_gmail_github_finish_v1(PDO $pdo, array $policy, string $claim, array $scan): int
{
    require_once __DIR__.'/agent-connection-gmail-github-knowledge-v1.php';
    require_once __DIR__.'/agent-connection-gmail-github-notice-v1.php';
    if (!agent_commerce_sqlite_pa2($pdo)) $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    return agent_commerce_atomic_pa2($pdo,static function() use ($pdo,$policy,$claim,$scan): int {
        $owner = (int)$policy['owner_user_id'];
        $user = agent_connection_gmail_inbox_user_v1($pdo,$owner,true);
        $connection = agent_connections_find_v1($pdo,$owner,(int)$policy['connection_id'],!agent_commerce_sqlite_pa2($pdo));
        $live = agent_connection_gmail_github_policy_v1($pdo,$owner,true);
        $now = agent_commerce_time_pa2();
        if (!$live || empty($live['enabled']) || (int)$live['connection_id']!==(int)$policy['connection_id']
            || !hash_equals((string)$live['authorization_key'],(string)$policy['authorization_key'])
            || !hash_equals((string)$live['revision'],(string)$policy['revision'])
            || !hash_equals((string)$live['claim_key'],$claim) || (string)$live['next_check_at']<=$now) return 0;
        if (!agent_connection_gmail_github_grant_v1($user,$connection,$live)) {
            agent_connection_gmail_github_revoke_v1($pdo,$owner,(int)$live['connection_id'],(string)$live['authorization_key']);
            return 0;
        }
        $folderId = (int)$live['folder_id'];
        if (!agent_connection_gmail_github_folder_v1($pdo,$owner,$folderId,true)) {
            $scan = ['status'=>'destination_unavailable'];
        } elseif (!agent_connection_gmail_ready_v1()
            || !hash_equals((string)$connection['client_fingerprint'],agent_connections_client_fingerprint_v1())) {
            $scan = ['status'=>'retrying'];
        }
        $filed = 0;
        $succeeded = 0;
        $failed = 0;
        $receipt = $pdo->prepare(agent_commerce_insert_ignore_pa2($pdo)
            .' INTO agent_connection_gmail_github_receipts_v1 (owner_user_id,message_key) VALUES (?,?)');
        foreach ($scan['messages']??[] as $key=>$event) {
            $receipt->execute([$owner,$key]);
            if ($receipt->rowCount()!==1 || $event === null) continue;
            $result = agent_connection_gmail_github_file_v1($pdo,$user,$folderId,$event['proof'],$event['record']);
            if (empty($result['created'])) continue;
            $filed++;
            $succeeded += (int)($event['record']['status']==='success');
            $failed += (int)($event['record']['status']==='failure');
        }
        $status = $scan['status'];
        $next = match ($status) {
            'destination_unavailable','restart_required'=>null,
            'retrying'=>gmdate('Y-m-d H:i:s',time()+900),
            default=>gmdate('Y-m-d H:i:s',time()+(!empty($scan['more'])?0:300)),
        };
        $pdo->prepare('UPDATE agent_connection_gmail_github_v1 SET history_id=?,history_page_token=?,pending_event_id=?,
            pending_fingerprint=?,pending_offset=?,claim_key=NULL,next_check_at=?,last_checked_at=?,last_status=? WHERE owner_user_id=?')
            ->execute([$scan['cursor']??$live['history_id'],($scan['page']??$live['history_page_token'])?:null,
                $scan['pending']??$live['pending_event_id'],$scan['fingerprint']??$live['pending_fingerprint'],
                $scan['offset']??$live['pending_offset'],$next,$now,$status,$owner]);
        if ($succeeded>0 || $failed>0) agent_connection_gmail_github_actions_notify_v1($pdo,$owner,$live,$succeeded,$failed);
        return $filed;
    });
}

function agent_connection_gmail_github_sweep_v1(PDO $pdo, int $limit=5, ?callable $http=null): array
{
    $counts = ['processed'=>0,'filed'=>0];
    if ($limit<1 || $pdo->inTransaction() || !agent_connection_gmail_github_storage_ready_v1($pdo)) return $counts;
    $due = $pdo->prepare('SELECT * FROM agent_connection_gmail_github_v1 WHERE enabled=1 AND next_check_at<=?
        ORDER BY next_check_at,owner_user_id LIMIT '.min($limit,5));
    $due->execute([agent_commerce_time_pa2()]);
    $reserve = $pdo->prepare('UPDATE agent_connection_gmail_github_v1 SET claim_key=?,next_check_at=?
        WHERE owner_user_id=? AND connection_id=? AND authorization_key=? AND revision=? AND enabled=1 AND next_check_at<=?');
    foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $policy) {
        $now = agent_commerce_time_pa2();
        $claim = bin2hex(random_bytes(16));
        $reserve->execute([$claim,gmdate('Y-m-d H:i:s',time()+180),$policy['owner_user_id'],$policy['connection_id'],
            $policy['authorization_key'],$policy['revision'],$now]);
        if ($reserve->rowCount()!==1) continue;
        $counts['processed']++;
        $owner = (int)$policy['owner_user_id'];
        $connectionId = (int)$policy['connection_id'];
        $scan = ['status'=>'retrying'];
        try {
            $connection = agent_connections_find_v1($pdo,$owner,$connectionId);
            if (agent_connection_gmail_github_grant_v1(agent_connection_gmail_inbox_user_v1($pdo,$owner),$connection,$policy)
                && agent_connection_gmail_github_folder_v1($pdo,$owner,(int)$policy['folder_id'])
                && agent_connection_gmail_ready_v1()
                && hash_equals((string)$connection['client_fingerprint'],agent_connections_client_fingerprint_v1())
                && agent_connection_gmail_inbox_claim_current_v1($pdo,$policy,$claim,true)) {
                $access = agent_connections_access_v1($owner,$connectionId,'read',$http,(string)$policy['authorization_key']);
                $scan = agent_connection_gmail_github_scan_v1($pdo,$policy,$connection,$claim,$access['access_token'],$http);
            }
        } catch (Throwable $error) {
            if (function_exists('agent_runtime_v125_trace')) {
                agent_runtime_v125_trace('worker.gmail_github.poll_failed',['error_class'=>get_class($error)]);
            }
        }
        try {
            $counts['filed'] += agent_connection_gmail_github_finish_v1($pdo,$policy,$claim,$scan);
        } catch (Throwable $error) {
            if (function_exists('agent_runtime_v125_trace')) {
                agent_runtime_v125_trace('worker.gmail_github.commit_failed',['error_class'=>get_class($error)]);
            }
        }
    }
    return $counts;
}
