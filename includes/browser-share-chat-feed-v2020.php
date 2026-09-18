<?php
declare(strict_types=1);

/**
 * VP3 v20.20 Browser Share → Chat / Feed integration.
 *
 * This layer is intentionally read-through: Browser Share and human_messages remain
 * authoritative. No copied share payload is persisted for chat/feed rendering.
 */
const VP3_BROWSER_SHARE_CHAT_FEED_V2020 = 'browser-share-chat-feed-v2020-20260917';
const VP3_BROWSER_SHARE_CARD_TEXT_MAX_V2020 = 12000;
const VP3_BROWSER_SHARE_AGENT_TEXT_MAX_V2020 = 12000;
const VP3_BROWSER_SHARE_FEED_LIMIT_MAX_V2020 = 100;

require_once __DIR__.'/browser-share-v2010.php';
require_once __DIR__.'/agent-work-control-v173.php';
require_once __DIR__.'/agent-workflow-runs-v1400.php';

function vp3_browser_share_chat_feed_ready_v2020(?PDO $pdo=null): bool
{
    $pdo ??= db();
    return (bool)$pdo && vp3_browser_share_schema_ready_v2010($pdo) && vp3_human_messaging_v370_ready($pdo);
}

function vp3_browser_share_chat_feed_require_ready_v2020(?PDO $pdo=null): PDO
{
    $pdo ??= db();
    if(!$pdo || !vp3_browser_share_chat_feed_ready_v2020($pdo)){
        throw new RuntimeException('Browser Share chat integration is not ready. Run the current database upgrade.');
    }
    return $pdo;
}

function vp3_browser_share_safe_url_v2020(string $url): string
{
    try{
        $validated=vp3_browser_share_validate_url_v2010($url);
        return (string)$validated['url'];
    }catch(Throwable $e){
        return '';
    }
}

function vp3_browser_share_text_limit_v2020(string $text,int $limit): string
{
    $limit=max(1,$limit);
    if(mb_strlen($text)<=$limit)return $text;
    return rtrim(mb_substr($text,0,max(1,$limit-1))).'…';
}

function vp3_browser_share_public_v2020(array $row): array
{
    $url=vp3_browser_share_safe_url_v2020((string)($row['source_url']??''));
    $canonical=vp3_browser_share_safe_url_v2020((string)($row['canonical_url']??''));
    $title=trim((string)($row['source_title']??''));
    $domain=trim((string)($row['source_domain']??''));
    $selected=(string)($row['selected_text']??'');
    $note=(string)($row['user_note']??'');
    return [
        'id'=>(string)($row['public_id']??''),
        'type'=>(string)($row['share_type']??'selection'),
        'source'=>[
            'title'=>$title,
            'domain'=>$domain,
            'url'=>$url,
            'canonical_url'=>$canonical,
        ],
        'selection'=>vp3_browser_share_text_limit_v2020($selected,VP3_BROWSER_SHARE_CARD_TEXT_MAX_V2020),
        'note'=>vp3_browser_share_text_limit_v2020($note,4096),
        'captured_at'=>(string)($row['captured_at']??''),
        'created_at'=>(string)($row['created_at']??''),
        'snapshot_hash'=>(string)($row['snapshot_hash']??''),
        'message_id'=>(int)($row['human_message_id']??0),
        'conversation_id'=>(int)($row['conversation_id']??0),
        'sender_user_id'=>(int)($row['sender_user_id']??0),
    ];
}

function vp3_browser_share_resolve_v2020(PDO $pdo,string $publicId,int $userId): ?array
{
    $publicId=trim($publicId);
    $row=$userId>0?vp3_browser_share_by_public_id_v2010($pdo,$publicId,$userId):null;
    if(is_array($row))return vp3_browser_share_public_v2020($row);

    // Phase 6 publications deliberately separate feed visibility from the
    // original delivery conversation. Re-resolve that live visibility here so
    // canonical Ask VP3 / Knowledge / Task actions can operate on authorized
    // Team/Public annotations without copying the capture into a second store.
    if(function_exists('vp3_browser_source_feed_schema_ready_v2050')
        && vp3_browser_source_feed_schema_ready_v2050($pdo)
        && function_exists('vp3_browser_source_share_row_v2050')){
        $published=vp3_browser_source_share_row_v2050($pdo,$publicId);
        if(is_array($published)
            && function_exists('vp3_browser_source_share_authorized_v2050')
            && vp3_browser_source_share_authorized_v2050($pdo,$published,$userId)){
            return vp3_browser_share_public_v2020($published);
        }
    }
    return null;
}

function vp3_browser_share_for_message_public_v2020(PDO $pdo,int $messageId,int $userId): ?array
{
    $row=vp3_browser_share_for_message_v2010($pdo,$messageId,$userId);
    return is_array($row)?vp3_browser_share_public_v2020($row):null;
}

function vp3_browser_share_enrich_message_v2020(PDO $pdo,array $message,int $userId): array
{
    $messageId=(int)($message['id']??0);
    if($messageId<1||$userId<1)return $message;
    try{
        $share=vp3_browser_share_for_message_public_v2020($pdo,$messageId,$userId);
        if($share)$message['browser_share']=$share;
    }catch(Throwable $e){
        error_log('VP3 Browser Share v2020 message enrichment: '.$e->getMessage());
    }
    return $message;
}

function vp3_browser_share_enrich_messages_v2020(PDO $pdo,array $messages,int $userId): array
{
    $out=[];
    foreach($messages as $message){
        if(!is_array($message))continue;
        $out[]=vp3_browser_share_enrich_message_v2020($pdo,$message,$userId);
    }
    return $out;
}

function vp3_browser_share_feed_v2020(PDO $pdo,int $userId,int $limit=30,int $beforeMessageId=0): array
{
    vp3_browser_share_chat_feed_require_ready_v2020($pdo);
    if($userId<1)throw new RuntimeException('Sign in to view Browser Shares.');
    $limit=max(1,min(VP3_BROWSER_SHARE_FEED_LIMIT_MAX_V2020,$limit));
    $scan=max($limit*4,80);
    $where=$beforeMessageId>0?' AND m.id<?':'';
    $sql="SELECT m.id
          FROM human_message_browser_shares_v2010 l
          INNER JOIN browser_shares_v2010 s ON s.id=l.browser_share_id AND s.deleted_at IS NULL
          INNER JOIN human_messages m ON m.id=l.human_message_id AND m.deleted_at IS NULL
          WHERE 1=1{$where}
          ORDER BY m.id DESC LIMIT ".(int)$scan;
    $stmt=$pdo->prepare($sql);
    $stmt->execute($beforeMessageId>0?[$beforeMessageId]:[]);
    $items=[];
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $messageId){
        $share=vp3_browser_share_for_message_public_v2020($pdo,(int)$messageId,$userId);
        if(!$share)continue;
        $items[]=$share;
        if(count($items)>=$limit)break;
    }
    $nextBefore=count($items)>0?(int)($items[count($items)-1]['message_id']??0):0;
    return ['items'=>$items,'next_before_message_id'=>$nextBefore,'has_more'=>count($items)===$limit];
}

function vp3_browser_share_knowledge_title_v2020(array $share): string
{
    $title=trim((string)($share['source']['title']??''));
    if($title==='')$title=trim((string)($share['source']['domain']??''));
    if($title==='')$title='Browser Share';
    return vp3_browser_share_text_limit_v2020($title,190);
}

function vp3_browser_share_knowledge_body_v2020(array $share): string
{
    $parts=[];
    $selection=trim((string)($share['selection']??''));
    $note=trim((string)($share['note']??''));
    $url=vp3_browser_share_safe_url_v2020((string)($share['source']['url']??''));
    if($selection!=='')$parts[]=$selection;
    if($note!=='')$parts[]="Note:\n".$note;
    if($url!=='')$parts[]='Source: '.$url;
    return trim(implode("\n\n",$parts));
}

function vp3_browser_share_save_knowledge_v2020(PDO $pdo,array $user,string $publicId,int $folderId=0): array
{
    $userId=(int)($user['id']??0);
    if($userId<1)throw new RuntimeException('Sign in to save Browser Shares.');
    if(!personal_capability_has_v242('personal_knowledge.access',$user)||!personal_capability_has_v242('personal_knowledge.manage',$user)){
        throw new RuntimeException('Personal Knowledge management is unavailable for this account.');
    }
    $share=vp3_browser_share_resolve_v2020($pdo,$publicId,$userId);
    if(!$share)throw new RuntimeException('This Browser Share is no longer available.');
    $folderId=max(0,$folderId);
    if($folderId>0 && function_exists('personal_knowledge_folder') && !personal_knowledge_folder($pdo,$user,$folderId)){
        throw new RuntimeException('The selected Knowledge folder is no longer available.');
    }
    $knowledgeId=personal_knowledge_store(
        $user,
        'browser-share:'.$share['id'],
        vp3_browser_share_knowledge_title_v2020($share),
        vp3_browser_share_knowledge_body_v2020($share),
        'Source: Browser Share · '.(string)($share['source']['domain']??''),
        $folderId
    );
    if($knowledgeId<1)throw new RuntimeException('My Knowledge could not save this Browser Share.');
    return [
        'knowledge_id'=>$knowledgeId,
        'view_url'=>url('/knowledge.php?'.http_build_query(['edit'=>$knowledgeId]).'#knowledge-form'),
        'browser_share'=>$share,
    ];
}

function vp3_browser_share_create_task_v2020(PDO $pdo,array $user,string $publicId): array
{
    $userId=agent_work_control_require_v173($pdo,$user);
    $share=vp3_browser_share_resolve_v2020($pdo,$publicId,$userId);
    if(!$share)throw new RuntimeException('This Browser Share is no longer available.');
    $title=vp3_browser_share_knowledge_title_v2020($share);
    $selection=vp3_browser_share_text_limit_v2020(trim((string)($share['selection']??'')),1500);
    $url=vp3_browser_share_safe_url_v2020((string)($share['source']['url']??''));
    $prompt='Review the Browser Share and decide the appropriate next action.';
    if($selection!=='')$prompt.="\n\nShared text:\n".$selection;
    if($url!=='')$prompt.="\n\nSource: ".$url;
    $priority=[
        'key'=>'browser-share:'.$share['id'],
        'suggestion_hash'=>substr((string)($share['snapshot_hash']??''),0,40),
        'title'=>'Review Browser Share: '.$title,
        'source'=>'browser_share',
        'prompt'=>$prompt,
        'reason'=>'Created explicitly from an authorized Browser Share.',
        'score'=>0.75,
        'action_id'=>'browser-share-task-'.sha1((string)$share['id']),
        'event_id'=>'browser-share-event-'.sha1((string)$share['id']),
        'risk_level'=>'low',
        'requires_approval'=>false,
    ];
    return agent_workflow_create_from_priority_v1400($pdo,$user,$priority,null);
}

function vp3_browser_share_agent_context_v2020(PDO $pdo,array $user,array $rawContext): array
{
    $publicId=trim((string)($rawContext['browser_share_id']??''));
    if($publicId==='')return [];
    $userId=(int)($user['id']??0);
    $share=vp3_browser_share_resolve_v2020($pdo,$publicId,$userId);
    if(!$share)throw new RuntimeException('This Browser Share is no longer available or you no longer have access to it.');
    $selection=vp3_browser_share_text_limit_v2020((string)($share['selection']??''),VP3_BROWSER_SHARE_AGENT_TEXT_MAX_V2020);
    return [
        'id'=>(string)$share['id'],
        'type'=>(string)$share['type'],
        'title'=>(string)($share['source']['title']??''),
        'domain'=>(string)($share['source']['domain']??''),
        'source_url'=>vp3_browser_share_safe_url_v2020((string)($share['source']['url']??'')),
        'selected_text'=>$selection,
        'note'=>vp3_browser_share_text_limit_v2020((string)($share['note']??''),2000),
        'captured_at'=>(string)($share['captured_at']??''),
        'message_id'=>(int)($share['message_id']??0),
    ];
}
