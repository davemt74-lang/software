<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/phone-workspace-v1.php';

function vp3_agent_phone_shell_ready_v1(PDO $pdo): bool
{
    try{$pdo->query('SELECT owner_user_id,agent_id,conversation_id FROM phone_browser_contexts_v1 WHERE 1=0');return true;}
    catch(PDOException){return false;}
}

/** Explicit upgrade only; a page read never creates storage or a conversation. */
function vp3_agent_phone_shell_install_v1(PDO $pdo): void
{
    if(vp3_phone_transaction_active_v1($pdo))throw new LogicException('Upgrade Phone outside a transaction.');
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec('CREATE TABLE IF NOT EXISTS phone_browser_contexts_v1(owner_user_id BIGINT NOT NULL,agent_id BIGINT NOT NULL,conversation_id BIGINT NOT NULL,PRIMARY KEY(owner_user_id,agent_id))'.$suffix);
}

/** Durable owner + Agent context, independent of the currently rendered page. */
function vp3_agent_phone_shell_context_v1(PDO $pdo,array $user,int $aid,bool $create=false): array
{
    $scope=vp3_phone_workspace_scope_v1($pdo,$user,['agent_id'=>$aid]);
    if(!vp3_agent_phone_shell_ready_v1($pdo))throw new RuntimeException('Run Database Upgrade to enable calls across pages.',503);
    $resolve=static function()use($pdo,$scope,$user,$aid,$create):array{
        if($create){$q=$pdo->prepare('SELECT id FROM users WHERE id=?'.vp3_phone_workspace_lock_v1($pdo));$q->execute([$scope['owner_user_id']]);
            $scope=vp3_phone_workspace_scope_v1($pdo,$user,['agent_id'=>$aid]);}
        $q=$pdo->prepare('SELECT conversation_id FROM phone_browser_contexts_v1 WHERE owner_user_id=? AND agent_id=?');$q->execute([$scope['owner_user_id'],$aid]);$cid=(int)$q->fetchColumn();
        $agent=$aid>0?vp3_agent_chat_resolve_agent_v380($pdo,$scope['user'],$aid):null;
        if($cid>0&&!vp3_agent_chat_conversation_v380($pdo,$cid,$scope['owner_user_id'],$agent))$cid=0;
        if(!$cid&&$create){
            $conversation=vp3_agent_chat_create_conversation_v380($pdo,$scope['user'],$agent,'Phone');$cid=(int)$conversation['id'];
            $pdo->prepare('DELETE FROM phone_browser_contexts_v1 WHERE owner_user_id=? AND agent_id=?')->execute([$scope['owner_user_id'],$aid]);
            $pdo->prepare('INSERT INTO phone_browser_contexts_v1(owner_user_id,agent_id,conversation_id) VALUES(?,?,?)')->execute([$scope['owner_user_id'],$aid,$cid]);
        }
        return ['owner_user_id'=>$scope['owner_user_id'],'agent_id'=>$aid,'conversation_id'=>$cid];
    };
    return $create?vp3_phone_transaction_v1($pdo,$resolve):$resolve();
}

/** Consume native phone receipts and save their Chat cards + generic owner notice
 * together. Unread notices survive a lost HTTP response or a page navigation. */
function vp3_agent_phone_shell_activity_v1(PDO $pdo,array $user): array
{
    $live=vp3_phone_workspace_live_user_v1($pdo,$user);$uid=(int)$live['id'];
    if(!vp3_phone_chat_page_v1($live)||vp3_participant_scope_v1()!==null||vp3_participant_chat_actor_v1($pdo,$live,[])!==null)throw new RuntimeException('Open your private account to review phone activity.',403);
    if(!vp3_paired_phone_ready_v1($pdo)||!vp3_phone_chat_schema_ready_v1($pdo)||!table_exists('notifications'))return [];
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$live,$uid):array{
        vp3_phone_account_v1($pdo,$uid,true);
        $threads=vp3_phone_sql_v1($pdo,"SELECT DISTINCT t.agent_id,t.conversation_id FROM vp3_paired_phone_threads_v1 t
            JOIN vp3_phone_devices_v1 d ON d.owner_user_id=t.owner_user_id AND d.id=t.device_id
            WHERE t.owner_user_id=? AND d.device_status='approved' AND d.expires_at>?
            AND (t.agent_id=0 OR EXISTS(SELECT 1 FROM user_agents a WHERE a.id=t.agent_id AND a.owner_user_id=t.owner_user_id AND a.is_active=1))
            ORDER BY t.conversation_id LIMIT 100",[$uid,time()])->fetchAll(PDO::FETCH_ASSOC);
        foreach($threads as $thread){
            $aid=(int)$thread['agent_id'];$cid=(int)$thread['conversation_id'];
            $principal=['kind'=>$aid>0?'user_agent':'system','owner_user_id'=>$uid,'agent_id'=>$aid];$input=['conversation_id'=>$cid,'agent_context'=>['user_agent_id'=>$aid]];
            try{vp3_phone_chat_authorize_v1($pdo,$live,$principal,$input,$cid);}
            catch(RuntimeException $error){if($error instanceof PDOException||$error->getCode()!==403)throw $error;continue;}
            $chatUpdates=vp3_paired_phone_pending_v1($pdo,$uid,$aid,$cid)?vp3_paired_phone_owner_updates_v1($pdo,$uid,$aid,$cid):[];
            $requestUpdates=vp3_phone_information_announcements_v1($pdo,$uid,$aid,$cid,true);
            foreach([['updates'=>$chatUpdates,'speech'=>'A connected phone sent a chat message.'],['updates'=>$requestUpdates,'speech'=>'A connected phone responded to an information request.']] as $group)foreach($group['updates'] as $update){
                $mid=(int)$update['message_id'];
                if(vp3_phone_sql_v1($pdo,"SELECT id FROM notifications WHERE user_id=? AND type='connected_phone_activity' AND source_type='phone_chat' AND source_id=?",[$uid,$mid])->fetchColumn())continue;
                $url=url('/chat.php?agent='.($aid?:'system').'&conversation_id='.$cid.'&message_id='.$mid);
                notification_create_locked($uid,'connected_phone_activity','Connected phone activity',$group['speech'],$url,'phone_chat',$mid);
                if(!vp3_phone_sql_v1($pdo,"SELECT id FROM notifications WHERE user_id=? AND type='connected_phone_activity' AND source_type='phone_chat' AND source_id=?",[$uid,$mid])->fetchColumn())throw new RuntimeException('Phone activity could not be saved.',503);
            }
        }
        $rows=vp3_phone_sql_v1($pdo,"SELECT n.id,n.body,n.source_id,m.conversation_id,COALESCE(c.user_agent_id,0) AS agent_id
            FROM notifications n JOIN chat_messages m ON m.id=n.source_id AND m.user_id=n.user_id
            JOIN chat_conversations c ON c.id=m.conversation_id AND c.user_id=n.user_id
            WHERE n.user_id=? AND n.type='connected_phone_activity' AND n.source_type='phone_chat' AND n.is_read=0
            AND (c.user_agent_id IS NULL OR EXISTS(SELECT 1 FROM user_agents a WHERE a.id=c.user_agent_id AND a.owner_user_id=c.user_id AND a.is_active=1))
            ORDER BY n.id DESC LIMIT 20",[$uid])->fetchAll(PDO::FETCH_ASSOC);
        $out=[];foreach(array_reverse($rows) as $row){
            $aid=(int)$row['agent_id'];$cid=(int)$row['conversation_id'];
            try{vp3_phone_chat_authorize_v1($pdo,$live,['kind'=>$aid>0?'user_agent':'system','owner_user_id'=>$uid,'agent_id'=>$aid],['agent_context'=>['user_agent_id'=>$aid]],$cid);}
            catch(RuntimeException $error){if($error instanceof PDOException||$error->getCode()!==403)throw $error;continue;}
            $out[]=['id'=>(int)$row['id'],'message_id'=>(int)$row['source_id'],'agent_id'=>$aid,'conversation_id'=>$cid,
                'speech'=>(string)$row['body'],'url'=>url('/notifications.php?open='.(int)$row['id'])];
        }return $out;
    });
}
