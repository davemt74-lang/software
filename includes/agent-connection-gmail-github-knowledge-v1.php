<?php
declare(strict_types=1);

require_once __DIR__.'/knowledge.php';
require_once __DIR__.'/agent-commerce-recovery-pa2.php';

/** File one verified notification inside the caller's current, owner-locked inbox transaction. */
function agent_connection_gmail_github_file_v1(PDO $pdo, array $user, int $folderId, string $messageKey, array $record): array
{
    if (!$pdo->inTransaction() || db() !== $pdo) {
        throw new LogicException('GitHub Knowledge filing needs the current inbox transaction.');
    }
    if (!personal_knowledge_available($user) || !personal_capability_has_v242('personal_knowledge.manage',$user)) {
        throw new RuntimeException('Private Knowledge filing is unavailable for this account.',403);
    }
    $folder = personal_knowledge_folder($pdo,$user,$folderId,true);
    if (!$folder) throw new RuntimeException('The selected private Knowledge folder is unavailable.',409);

    $repository = $record['repository']??null;
    $status = $record['status']??null;
    $receivedAt = $record['received_at']??null;
    $sourceUrl = $record['url']??null;
    $validSource = $repository==='' && $sourceUrl==='';
    if (is_string($repository) && is_string($sourceUrl)
        && preg_match('~\A[a-z0-9](?:[a-z0-9-]{0,37}[a-z0-9])?/(?!\.{1,2}\z)[a-z0-9_.-]{1,100}\z~iD',$repository)) {
        $validSource = preg_match('~\Ahttps://github\.com/'.preg_quote($repository,'~').'/actions(?:/runs/[0-9]{1,30})?\z~iD',$sourceUrl)===1;
    }
    if (!preg_match('/\A[a-f0-9]{64}\z/D',$messageKey) || ($record['from']??null)!=='notifications@github.com'
        || !$validSource
        || !in_array($status,['success','failure','cancelled','neutral','unknown'],true)
        || agent_commerce_date_pa2($receivedAt)<1) {
        throw new InvalidArgumentException('GitHub notification source metadata is invalid.');
    }

    $key = 'gmail-github-notification:'.$messageKey;
    $existing = personal_knowledge_note_by_key($pdo,$user,$key,true);
    if ($existing) {
        $knowledgeId = (int)$existing['id'];
        $savedFolderId = (int)($existing['folder_id']??0);
        $savedFolder = $savedFolderId===$folderId ? $folder : personal_knowledge_folder($pdo,$user,$savedFolderId);
    } else {
        // The canonical indexing helper can install its table. Keep DDL out of this transaction.
        if (function_exists('shared_knowledge_index_schema_ready_v236') && !shared_knowledge_index_schema_ready_v236($pdo)) {
            throw new RuntimeException('Private Knowledge indexing requires Database Upgrade.',503);
        }
        $subject = is_string($record['subject']??null) ? trim(preg_replace('/[\p{C}\s]+/u',' ',$record['subject'])??'') : '';
        $repoLabel = $repository!=='' ? $repository : 'Repository unavailable';
        $body = "Source: Gmail · GitHub Actions notification\nFrom: notifications@github.com\n"
            ."Repository: $repoLabel\nStatus reported by email: $status\nReceived (UTC): $receivedAt";
        if ($sourceUrl!=='') $body .= "\nGitHub: $sourceUrl";
        if ($subject!=='') $body .= "\nSubject: ".mb_substr($subject,0,240,'UTF-8');
        $knowledgeId = personal_knowledge_store($user,$key,$repoLabel.' · GitHub Actions · '.$status,$body,
            'Source: Gmail · GitHub Actions · '.$repoLabel,$folderId);
        $savedFolder = $folder;
    }
    return [
        'knowledge_id'=>$knowledgeId,
        'folder_id'=>(int)($savedFolder['id']??0),
        'folder_name'=>(string)($savedFolder['folder_name']??''),
        'view_url'=>url('/knowledge.php?edit='.$knowledgeId.'#knowledge-form'),
        'created'=>$existing===null,
    ];
}
