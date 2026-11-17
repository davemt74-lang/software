<?php
declare(strict_types=1);

require_once __DIR__.'/agent-screen-handoffs-v1.php';
require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/schema-metadata.php';

const VP3_SCREEN_CHAT_REPORT_SOURCE_V1='agent_screen_insights';
const VP3_SCREEN_CHAT_REPORT_TEXT_V1='Here’s the wrap-up from your screen share.';

function screen_chat_report_schema_v1(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS agent_screen_chat_reports_v1 (
        owner_user_id BIGINT NOT NULL, session_id CHAR(36) NOT NULL, agent_id BIGINT NOT NULL,
        conversation_id BIGINT NOT NULL, message_id BIGINT NULL,
        PRIMARY KEY(owner_user_id,session_id), UNIQUE(owner_user_id,message_id)
    )'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB':''));
    screen_insights_schema_v1($pdo);
}

function screen_chat_report_scope_v1(PDO $pdo,array $user,int $agentId,int $conversationId,bool $lock=false): bool
{
    if($conversationId<1 || $agentId<0 || !has_permission('chat.access',$user) || !personal_knowledge_available($user)
        || (function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))return false;
    try{
        $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);
    }catch(RuntimeException $error){
        if($error instanceof PDOException)throw $error;
        return false;
    }
    return vp3_agent_chat_conversation_v380($pdo,$conversationId,(int)$user['id'],$agent,
        $lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')!==null;
}

function screen_chat_report_binding_v1(PDO $pdo,array $user,string $sessionId): ?array
{
    if(!schema_metadata_table_exists($pdo,'agent_screen_chat_reports_v1'))return null;
    $query=$pdo->prepare('SELECT * FROM agent_screen_chat_reports_v1 WHERE owner_user_id=? AND session_id=?');
    $query->execute([(int)$user['id'],$sessionId]);
    return $query->fetch(PDO::FETCH_ASSOC)?:null;
}

/** Called as part of the session write, before any capture or report exists. */
function screen_chat_report_bind_v1(PDO $pdo,array $user,string $sessionId,int $agentId,int $conversationId): void
{
    if(!$pdo->inTransaction()||!screen_chat_report_scope_v1($pdo,$user,$agentId,$conversationId,true)){
        throw new RuntimeException('Choose a saved private Chat for this screen-share report.',403);
    }
    $pdo->prepare('INSERT INTO agent_screen_chat_reports_v1 (owner_user_id,session_id,agent_id,conversation_id) VALUES (?,?,?,?)')
        ->execute([(int)$user['id'],$sessionId,$agentId,$conversationId]);
}

/** The locked session row serializes publication and review/capture writes. */
function screen_chat_report_publish_v1(PDO $pdo,array $user,array $session,array $binding): ?array
{
    $conversationId=(int)$binding['conversation_id'];
    $agentId=(int)$binding['agent_id'];
    if($agentId!==(int)$session['agent_id']||!screen_chat_report_scope_v1($pdo,$user,$agentId,$conversationId,true))return null;
    $messageId=(int)$binding['message_id'];
    if($messageId>0)return ['conversation_id'=>$conversationId,'message_id'=>$messageId];
    $sessionId=$session['session_id'];
    if(!screen_insights_pool_v1($pdo,$user,$agentId,$sessionId))return null;
    $context=['source'=>VP3_SCREEN_CHAT_REPORT_SOURCE_V1,'skip_brain_archive'=>true];
    $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (?,NULL,'assistant',?,?)")
        ->execute([$conversationId,VP3_SCREEN_CHAT_REPORT_TEXT_V1,json_encode($context,JSON_THROW_ON_ERROR)]);
    $messageId=(int)$pdo->lastInsertId();
    if($messageId<1)throw new RuntimeException('The screen-share report could not be saved.',503);
    $link=$pdo->prepare('UPDATE agent_screen_chat_reports_v1 SET message_id=? WHERE owner_user_id=? AND session_id=? AND message_id IS NULL');
    $link->execute([$messageId,(int)$user['id'],$sessionId]);
    if($link->rowCount()!==1)throw new RuntimeException('The screen-share report could not be linked to its session.',503);
    $pdo->prepare('UPDATE chat_conversations SET updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?')
        ->execute([$conversationId,(int)$user['id']]);
    return ['conversation_id'=>$conversationId,'message_id'=>$messageId];
}

function screen_session_end_v1(PDO $pdo,array $user,string $sessionId,bool $publishReport=true): array
{
    $pdo->beginTransaction();
    try{
        $session=screen_session_v1($pdo,$user,$sessionId,true);
        if($session['status']==='active'){
            $pdo->prepare("UPDATE agent_screen_sessions_v1 SET status='ended',updated_at=? WHERE owner_user_id=? AND session_id=? AND status='active'")
                ->execute([gmdate('Y-m-d\TH:i:s\Z'),(int)$user['id'],$session['session_id']]);
        }
        $binding=$publishReport?screen_chat_report_binding_v1($pdo,$user,$session['session_id']):null;
        $report=$binding===null?null:screen_chat_report_publish_v1($pdo,$user,$session,$binding);
        $pdo->commit();
        return ['ended'=>true]+($report!==null?['report'=>$report]:[]);
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
}

/** A previous session chooses a destination only on an explicit owner request. */
function screen_chat_report_share_v1(PDO $pdo,array $user,string $sessionId,int $conversationId): array
{
    $session=screen_session_v1($pdo,$user,$sessionId);
    if(!screen_chat_report_scope_v1($pdo,$user,(int)$session['agent_id'],$conversationId)){
        throw new RuntimeException('Choose a saved private Chat for this Agent.',403);
    }
    screen_chat_report_schema_v1($pdo);
    $pdo->beginTransaction();
    try{
        $session=screen_session_v1($pdo,$user,$sessionId,true);
        if($session['status']!=='ended')throw new RuntimeException('End screen sharing before posting its wrap-up.',409);
        $binding=screen_chat_report_binding_v1($pdo,$user,$session['session_id']);
        if($binding===null){
            screen_chat_report_bind_v1($pdo,$user,$session['session_id'],(int)$session['agent_id'],$conversationId);
            $binding=['agent_id'=>$session['agent_id'],'conversation_id'=>$conversationId,'message_id'=>null];
        }elseif((int)$binding['conversation_id']!==$conversationId){
            throw new RuntimeException('This report belongs to the Chat where the share started.',409);
        }
        $report=screen_chat_report_publish_v1($pdo,$user,$session,$binding);
        if($report===null)throw new RuntimeException('There are no current captures to report in this Chat.',409);
        $pdo->commit();
        return ['report'=>$report];
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
}

/** Screenshots and capture text stay in their authoritative session. */
function screen_chat_report_projection_v1(PDO $pdo,array $user,int $agentId,int $conversationId,int $messageId): array
{
    $missing=['report'=>null,'answer'=>'This screen-share wrap-up is no longer available.'];
    if($messageId<1||!schema_metadata_table_exists($pdo,'agent_screen_chat_reports_v1')
        ||!screen_chat_report_scope_v1($pdo,$user,$agentId,$conversationId))return $missing;
    $owner=(int)$user['id'];
    $query=$pdo->prepare("SELECT b.session_id FROM agent_screen_chat_reports_v1 b
        JOIN agent_screen_sessions_v1 s ON s.owner_user_id=b.owner_user_id AND s.session_id=b.session_id
            AND s.agent_id=b.agent_id AND s.status='ended'
        JOIN chat_messages m ON m.id=b.message_id AND m.conversation_id=b.conversation_id AND m.role='assistant' AND m.user_id IS NULL
        WHERE b.owner_user_id=? AND b.agent_id=? AND b.conversation_id=? AND b.message_id=?");
    $query->execute([$owner,$agentId,$conversationId,$messageId]);
    $sessionId=$query->fetchColumn();
    if(!is_string($sessionId)||$sessionId==='')return $missing;
    $rows=screen_insights_pool_v1($pdo,$user,$agentId,$sessionId);
    if(!$rows)return ['report'=>null,'answer'=>'There are no current observations left in this screen-share wrap-up.'];

    $count=count($rows);
    $answer=VP3_SCREEN_CHAT_REPORT_TEXT_V1.' There '.($count===1?'is 1 capture':'are '.$count.' captures').' to review.';
    $highlights=[];
    foreach($rows as $row){
        $summary=$row['body']['observation']['summary']??null;
        if(!is_string($summary))continue;
        $summary=mb_substr(trim(preg_replace('/[\s\p{C}]+/u',' ',$summary)??''),0,360);
        if($summary==='')continue;
        $highlights[$summary]=true;
        if(count($highlights)>=3)break;
    }
    if($highlights)$answer.="\n\n• ".implode("\n• ",array_keys($highlights));

    $now=time();
    $wrap=screen_insights_wrapup_card_v1($sessionId,$rows);
    $suggestions=array_values(array_filter(screen_insights_derive_v1($rows,$now),static fn(array $card):bool=>$card['kind']!=='wrapup'));
    $cards=array_slice(screen_insights_visible_cards_v1($pdo,$user,$agentId,[$wrap,...$suggestions],$now),0,3);
    $handoffs=[];
    $query=$pdo->prepare('SELECT * FROM agent_screen_handoffs_v1 WHERE owner_user_id=? AND agent_id=? ORDER BY updated_at DESC LIMIT 20');
    $query->execute([$owner,$agentId]);
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
        $refs=json_decode((string)$row['refs_json'],true);
        if(!is_array($refs)||!$refs||array_filter($refs,static fn($ref):bool=>($ref['session_id']??null)!==$sessionId))continue;
        try{$handoffs[]=screen_handoff_view_v1($pdo,$user,$row);}catch(Throwable $error){}
    }
    return ['answer'=>$answer,'report'=>['owner_user_id'=>$owner,'agent_id'=>$agentId,'conversation_id'=>$conversationId,
        'message_id'=>$messageId,'session_id'=>$sessionId,'cards'=>$cards,'handoffs'=>$handoffs]];
}
