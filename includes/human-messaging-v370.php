<?php
declare(strict_types=1);

/**
 * VP3 v3.70 canonical human messaging lifecycle.
 *
 * Human DMs, Message Requests and Team General all persist in the human_* domain.
 * Agent Chat and Profile Agent remain separate. Legacy Team Chat is migrated and
 * adapted onto this service rather than retaining a parallel message store.
 */
const VP3_HUMAN_MESSAGING_V370='vp3-human-messaging-v370-20260910';

function vp3_human_messaging_v370_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && vp3_social_schema_ready_v320($pdo)
        && table_exists('human_message_legacy_links_v370')
        && table_exists('human_conversation_reads_v370');
}

function vp3_human_messaging_v370_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_human_messaging_v370_schema_ready($pdo))return;
    if($pdo->inTransaction())throw new RuntimeException('Human messaging schema must be installed before starting a messaging transaction.');
    if(!vp3_social_schema_ready_v320($pdo))vp3_social_ensure_schema_v320($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS human_message_legacy_links_v370 (
      source_type VARCHAR(40) NOT NULL,
      source_id BIGINT UNSIGNED NOT NULL,
      human_message_id BIGINT UNSIGNED NOT NULL,
      migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (source_type,source_id),
      UNIQUE KEY uq_human_legacy_message (human_message_id),
      CONSTRAINT fk_human_legacy_message FOREIGN KEY (human_message_id) REFERENCES human_messages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Read state is deliberately independent from conversation membership. Team
    // General authorization always derives from the live Team relationship.
    $pdo->exec("CREATE TABLE IF NOT EXISTS human_conversation_reads_v370 (
      conversation_id BIGINT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      last_read_message_id BIGINT UNSIGNED NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (conversation_id,user_id),
      INDEX idx_human_reads_user (user_id,updated_at,conversation_id),
      CONSTRAINT fk_human_reads_conversation FOREIGN KEY (conversation_id) REFERENCES human_conversations(id) ON DELETE CASCADE,
      CONSTRAINT fk_human_reads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_human_reads_message FOREIGN KEY (last_read_message_id) REFERENCES human_messages(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_human_messaging_v370_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo||!vp3_human_messaging_v370_schema_ready($pdo))return false;
    return !function_exists('setting')||(string)setting('human_messaging_v370_migrated','')===VP3_HUMAN_MESSAGING_V370;
}

function vp3_human_pair_v370(int $a,int $b): array
{
    if($a<1||$b<1||$a===$b)throw new RuntimeException('Choose another VP3 member.');
    return $a<$b?[$a,$b]:[$b,$a];
}

function vp3_human_lock_users_v370(PDO $pdo,int $a,int $b,bool $requireActive=true): array
{
    [$low,$high]=vp3_human_pair_v370($a,$b);
    $stmt=$pdo->prepare('SELECT id,is_active,display_name,avatar_path,role FROM users WHERE id IN (?,?) ORDER BY id FOR UPDATE');
    $stmt->execute([$low,$high]);
    $rows=$stmt->fetchAll()?:[];
    if(count($rows)!==2)throw new RuntimeException('One of these VP3 members is unavailable.');
    $byId=[];
    foreach($rows as $row){
        $byId[(int)$row['id']]=$row;
        if($requireActive&&empty($row['is_active']))throw new RuntimeException('One of these VP3 members is unavailable.');
    }
    return $byId;
}

function vp3_human_lock_owner_v370(PDO $pdo,int $ownerId): array
{
    if($ownerId<1)throw new RuntimeException('Team workspace is unavailable.');
    $stmt=$pdo->prepare('SELECT id,is_active FROM users WHERE id=? LIMIT 1 FOR UPDATE');
    $stmt->execute([$ownerId]);
    $row=$stmt->fetch();
    if(!$row||empty($row['is_active']))throw new RuntimeException('Team workspace is unavailable.');
    return $row;
}

function vp3_human_blocked_v370(PDO $pdo,int $a,int $b): bool
{
    if($a<1||$b<1)return true;
    $stmt=$pdo->prepare('SELECT 1 FROM user_blocks WHERE (blocker_user_id=? AND blocked_user_id=?) OR (blocker_user_id=? AND blocked_user_id=?) LIMIT 1');
    $stmt->execute([$a,$b,$b,$a]);
    return (bool)$stmt->fetchColumn();
}

function vp3_human_shared_workspace_v370(PDO $pdo,int $a,int $b): bool
{
    if($a<1||$b<1||$a===$b||!table_exists('artist_team_members'))return false;
    $stmt=$pdo->prepare("SELECT 1 WHERE
      EXISTS(SELECT 1 FROM artist_team_members x WHERE x.artist_user_id=? AND x.member_user_id=?)
      OR EXISTS(SELECT 1 FROM artist_team_members x WHERE x.artist_user_id=? AND x.member_user_id=?)
      OR EXISTS(
        SELECT 1 FROM artist_team_members a1
        INNER JOIN artist_team_members a2 ON a2.artist_user_id=a1.artist_user_id
        WHERE a1.member_user_id=? AND a2.member_user_id=?
      )
      LIMIT 1");
    $stmt->execute([$a,$b,$b,$a,$a,$b]);
    return (bool)$stmt->fetchColumn();
}

function vp3_human_friends_v370(PDO $pdo,int $a,int $b): bool
{
    [$low,$high]=vp3_human_pair_v370($a,$b);
    $stmt=$pdo->prepare("SELECT 1 FROM user_friendships WHERE user_low_id=? AND user_high_id=? AND status='accepted' LIMIT 1");
    $stmt->execute([$low,$high]);
    return (bool)$stmt->fetchColumn();
}

function vp3_human_following_v370(PDO $pdo,int $follower,int $followed): bool
{
    $stmt=$pdo->prepare('SELECT 1 FROM user_follows WHERE follower_user_id=? AND followed_user_id=? LIMIT 1');
    $stmt->execute([$follower,$followed]);
    return (bool)$stmt->fetchColumn();
}

/** Resolve current DM permission without schema DDL; safe inside transactions. */
function vp3_human_dm_route_v370(PDO $pdo,int $senderId,int $recipientId): string
{
    if($senderId<1||$recipientId<1||$senderId===$recipientId)return 'deny';
    if(vp3_human_blocked_v370($pdo,$senderId,$recipientId))return 'deny';
    if(vp3_human_shared_workspace_v370($pdo,$senderId,$recipientId)||vp3_human_friends_v370($pdo,$senderId,$recipientId))return 'direct';

    $stmt=$pdo->prepare('SELECT message_policy FROM user_social_settings WHERE user_id=? LIMIT 1');
    $stmt->execute([$recipientId]);
    $policy=(string)($stmt->fetchColumn()?:'friends');

    if($policy==='following'&&vp3_human_following_v370($pdo,$recipientId,$senderId))return 'direct';
    if($policy==='followers'&&vp3_human_following_v370($pdo,$senderId,$recipientId))return 'request';
    return $policy==='anyone'?'request':'deny';
}

function vp3_human_conversation_v370(PDO $pdo,int $conversationId,bool $forUpdate=false): ?array
{
    if($conversationId<1)return null;
    $sql='SELECT * FROM human_conversations WHERE id=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$conversationId]);
    $row=$stmt->fetch();
    return $row?:null;
}

function vp3_human_direct_existing_v370(PDO $pdo,int $a,int $b,bool $forUpdate=false): ?array
{
    [$low,$high]=vp3_human_pair_v370($a,$b);
    $sql="SELECT * FROM human_conversations WHERE conversation_type='direct' AND direct_user_low_id=? AND direct_user_high_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$low,$high]);
    $row=$stmt->fetch();
    return $row?:null;
}

function vp3_human_direct_conversation_locked_v370(PDO $pdo,int $a,int $b,int $createdBy): array
{
    [$low,$high]=vp3_human_pair_v370($a,$b);
    $row=vp3_human_direct_existing_v370($pdo,$low,$high,true);
    if(!$row){
        try{
            $pdo->prepare("INSERT INTO human_conversations (conversation_type,direct_user_low_id,direct_user_high_id,created_by_user_id) VALUES ('direct',?,?,?)")
                ->execute([$low,$high,$createdBy]);
        }catch(Throwable $e){
            // A concurrent creator may have won the unique direct-pair insert.
        }
        $row=vp3_human_direct_existing_v370($pdo,$low,$high,true);
    }
    if(!$row)throw new RuntimeException('Conversation could not be created.');

    $member=$pdo->prepare("INSERT INTO human_conversation_members (conversation_id,user_id,member_role,left_at) VALUES (?,?,'member',NULL)
        ON DUPLICATE KEY UPDATE left_at=NULL,updated_at=NOW()");
    $member->execute([(int)$row['id'],$low]);
    $member->execute([(int)$row['id'],$high]);
    return $row;
}

function vp3_human_request_v370(PDO $pdo,int $conversationId,bool $forUpdate=false): ?array
{
    if($conversationId<1)return null;
    $sql='SELECT * FROM human_message_requests WHERE conversation_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$conversationId]);
    $row=$stmt->fetch();
    return $row?:null;
}

function vp3_human_direct_other_v370(array $conversation,int $userId): int
{
    $low=(int)($conversation['direct_user_low_id']??0);
    $high=(int)($conversation['direct_user_high_id']??0);
    return $userId===$low?$high:($userId===$high?$low:0);
}

function vp3_human_team_authorized_v370(PDO $pdo,int $ownerId,int $userId): bool
{
    if($ownerId<1||$userId<1)return false;
    $stmt=$pdo->prepare('SELECT is_active FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    if(!(bool)$stmt->fetchColumn())return false;
    if($ownerId===$userId)return true;
    if(!table_exists('artist_team_members'))return false;
    $stmt=$pdo->prepare('SELECT 1 FROM artist_team_members WHERE artist_user_id=? AND member_user_id=? LIMIT 1');
    $stmt->execute([$ownerId,$userId]);
    return (bool)$stmt->fetchColumn();
}

function vp3_human_can_access_v370(PDO $pdo,array $conversation,int $userId): bool
{
    if($userId<1)return false;
    $type=(string)($conversation['conversation_type']??'');
    if($type==='team_general'){
        // Team General ignores human_conversation_members by design. Authorization
        // is always derived from current active workspace membership.
        return vp3_human_team_authorized_v370($pdo,(int)($conversation['workspace_owner_user_id']??0),$userId);
    }
    if($type==='direct'){
        $other=vp3_human_direct_other_v370($conversation,$userId);
        if($other<1||vp3_human_blocked_v370($pdo,$userId,$other))return false;
    }
    $stmt=$pdo->prepare('SELECT 1 FROM human_conversation_members WHERE conversation_id=? AND user_id=? AND left_at IS NULL LIMIT 1');
    $stmt->execute([(int)$conversation['id'],$userId]);
    return (bool)$stmt->fetchColumn();
}

function vp3_human_insert_message_v370(PDO $pdo,array $conversation,int $senderId,string $body,?string $createdAt=null): array
{
    $body=trim($body);
    if($body==='')throw new RuntimeException('Message cannot be empty.');
    if(mb_strlen($body)>4000)throw new RuntimeException('Message is too long.');

    if($createdAt!==null){
        $stmt=$pdo->prepare('INSERT INTO human_messages (conversation_id,sender_user_id,body,created_at) VALUES (?,?,?,?)');
        $stmt->execute([(int)$conversation['id'],$senderId,$body,$createdAt]);
    }else{
        $stmt=$pdo->prepare('INSERT INTO human_messages (conversation_id,sender_user_id,body) VALUES (?,?,?)');
        $stmt->execute([(int)$conversation['id'],$senderId,$body]);
    }
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE human_conversations SET updated_at=GREATEST(updated_at,COALESCE(?,NOW())) WHERE id=?')
        ->execute([$createdAt,(int)$conversation['id']]);

    // Deliberately do not copy message bodies to generic activity/audit storage.
    return [
        'id'=>$id,
        'conversation_id'=>(int)$conversation['id'],
        'sender_user_id'=>$senderId,
        'body'=>$body,
        'created_at'=>$createdAt??date('Y-m-d H:i:s'),
    ];
}

function vp3_human_start_direct_v370(PDO $pdo,int $senderId,int $recipientId,string $initialMessage=''): array
{
    vp3_human_messaging_v370_ensure_schema($pdo);
    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        // User rows are the serialization boundary for this pair. Every direct
        // lifecycle path locks users in numeric order before conversation/request.
        vp3_human_lock_users_v370($pdo,$senderId,$recipientId,true);
        $route=vp3_human_dm_route_v370($pdo,$senderId,$recipientId);
        if($route==='deny')throw new RuntimeException('This member is not accepting messages from you.');

        $conversation=vp3_human_direct_conversation_locked_v370($pdo,$senderId,$recipientId,$senderId);
        $cid=(int)$conversation['id'];
        $request=vp3_human_request_v370($pdo,$cid,true);

        if($route==='request'){
            if($request&&$request['status']==='declined')throw new RuntimeException('This message request was declined.');
            if(!$request){
                $pdo->prepare("INSERT INTO human_message_requests (conversation_id,requester_user_id,recipient_user_id,status) VALUES (?,?,?,'pending')")
                    ->execute([$cid,$senderId,$recipientId]);
                $request=vp3_human_request_v370($pdo,$cid,true);
            }
            if($request&&(int)$request['requester_user_id']!==$senderId)throw new RuntimeException('This conversation already has a request in the other direction.');
        }elseif($request&&in_array((string)$request['status'],['pending','declined'],true)){
            $pdo->prepare("UPDATE human_message_requests SET status='accepted',resolved_at=NOW(),updated_at=NOW() WHERE conversation_id=?")
                ->execute([$cid]);
            $request=vp3_human_request_v370($pdo,$cid,true);
        }

        $message=null;
        if(trim($initialMessage)!==''){
            if($request&&$request['status']==='pending'&&(int)($request['initial_message_id']??0)>0){
                throw new RuntimeException('Your message request is pending. You can send another message after it is accepted.');
            }
            $message=vp3_human_insert_message_v370($pdo,$conversation,$senderId,$initialMessage);
            if($request&&$request['status']==='pending'){
                $pdo->prepare('UPDATE human_message_requests SET initial_message_id=?,updated_at=NOW() WHERE conversation_id=? AND initial_message_id IS NULL')
                    ->execute([(int)$message['id'],$cid]);
            }
        }

        if($owns)$pdo->commit();
        return ['conversation'=>$conversation,'route'=>$route,'message'=>$message];
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function vp3_human_send_message_v370(PDO $pdo,int $conversationId,int $senderId,string $body): array
{
    vp3_human_messaging_v370_ensure_schema($pdo);
    $body=trim($body);
    if($body==='')throw new RuntimeException('Message cannot be empty.');
    if(mb_strlen($body)>4000)throw new RuntimeException('Message is too long.');

    // Preview only to establish the correct lock order. Authorization is repeated
    // after the serialized rows are locked.
    $preview=vp3_human_conversation_v370($pdo,$conversationId);
    if(!$preview)throw new RuntimeException('Conversation is not available.');
    $type=(string)($preview['conversation_type']??'');
    $other=$type==='direct'?vp3_human_direct_other_v370($preview,$senderId):0;
    $owner=$type==='team_general'?(int)($preview['workspace_owner_user_id']??0):0;
    if($type==='direct'&&$other<1)throw new RuntimeException('Conversation is not available.');

    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        if($type==='direct')vp3_human_lock_users_v370($pdo,$senderId,$other,true);
        if($type==='team_general')vp3_human_lock_owner_v370($pdo,$owner);

        $conversation=vp3_human_conversation_v370($pdo,$conversationId,true);
        if(!$conversation||!vp3_human_can_access_v370($pdo,$conversation,$senderId))throw new RuntimeException('Conversation is not available.');

        $request=null;
        if((string)$conversation['conversation_type']==='direct'){
            $other=vp3_human_direct_other_v370($conversation,$senderId);
            if(vp3_human_blocked_v370($pdo,$senderId,$other))throw new RuntimeException('This conversation is no longer available.');
            $request=vp3_human_request_v370($pdo,$conversationId,true);

            if($request&&$request['status']==='pending'){
                if((int)$request['requester_user_id']!==$senderId)throw new RuntimeException('Accept the message request before replying.');
                if((int)($request['initial_message_id']??0)>0)throw new RuntimeException('Your message request is pending. You can send another message after it is accepted.');
            }elseif($request&&$request['status']==='declined'){
                if(vp3_human_dm_route_v370($pdo,$senderId,$other)!=='direct')throw new RuntimeException('This message request was declined.');
                $pdo->prepare("UPDATE human_message_requests SET status='accepted',resolved_at=NOW(),updated_at=NOW() WHERE conversation_id=?")
                    ->execute([$conversationId]);
                $request=vp3_human_request_v370($pdo,$conversationId,true);
            }elseif(!$request){
                $route=vp3_human_dm_route_v370($pdo,$senderId,$other);
                if($route==='deny')throw new RuntimeException('This member is not accepting messages from you.');
                if($route==='request'){
                    $pdo->prepare("INSERT INTO human_message_requests (conversation_id,requester_user_id,recipient_user_id,status) VALUES (?,?,?,'pending')")
                        ->execute([$conversationId,$senderId,$other]);
                    $request=vp3_human_request_v370($pdo,$conversationId,true);
                }
            }
        }

        $message=vp3_human_insert_message_v370($pdo,$conversation,$senderId,$body);
        if($request&&$request['status']==='pending'&&(int)($request['initial_message_id']??0)<1){
            $pdo->prepare('UPDATE human_message_requests SET initial_message_id=?,updated_at=NOW() WHERE conversation_id=? AND initial_message_id IS NULL')
                ->execute([(int)$message['id'],$conversationId]);
        }

        if($owns)$pdo->commit();
        return $message;
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function vp3_human_resolve_request_v370(PDO $pdo,int $conversationId,int $recipientId,bool $accept): void
{
    vp3_human_messaging_v370_ensure_schema($pdo);
    $preview=vp3_human_request_v370($pdo,$conversationId);
    if(!$preview||(int)$preview['recipient_user_id']!==$recipientId||(string)$preview['status']!=='pending'){
        throw new RuntimeException('Message request is not available.');
    }
    $requester=(int)$preview['requester_user_id'];

    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        vp3_human_lock_users_v370($pdo,$requester,$recipientId,true);
        $request=vp3_human_request_v370($pdo,$conversationId,true);
        if(!$request||(int)$request['requester_user_id']!==$requester||(int)$request['recipient_user_id']!==$recipientId||(string)$request['status']!=='pending'){
            throw new RuntimeException('Message request changed before it could be resolved.');
        }
        if(vp3_human_blocked_v370($pdo,$requester,$recipientId))throw new RuntimeException('This message request is no longer available.');
        if($accept&&(int)($request['initial_message_id']??0)<1)throw new RuntimeException('Message request has no message to accept.');

        $status=$accept?'accepted':'declined';
        $stmt=$pdo->prepare("UPDATE human_message_requests SET status=?,resolved_at=NOW(),updated_at=NOW()
            WHERE conversation_id=? AND requester_user_id=? AND recipient_user_id=? AND status='pending'");
        $stmt->execute([$status,$conversationId,$requester,$recipientId]);
        if($stmt->rowCount()!==1)throw new RuntimeException('Message request changed before it could be resolved.');

        if($owns)$pdo->commit();
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function vp3_human_team_general_v370(PDO $pdo,int $workspaceOwnerId,int $actorId): array
{
    vp3_human_messaging_v370_ensure_schema($pdo);
    if($workspaceOwnerId<1||$actorId<1)throw new RuntimeException('Choose a Team workspace.');

    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        vp3_human_lock_owner_v370($pdo,$workspaceOwnerId);
        if(!vp3_human_team_authorized_v370($pdo,$workspaceOwnerId,$actorId))throw new RuntimeException('You are not an active member of that workspace.');

        $stmt=$pdo->prepare("SELECT * FROM human_conversations WHERE conversation_type='team_general' AND workspace_owner_user_id=? LIMIT 1 FOR UPDATE");
        $stmt->execute([$workspaceOwnerId]);
        $conversation=$stmt->fetch();
        if(!$conversation){
            try{
                $pdo->prepare("INSERT INTO human_conversations (conversation_type,workspace_owner_user_id,title,created_by_user_id) VALUES ('team_general',?,'General',?)")
                    ->execute([$workspaceOwnerId,$workspaceOwnerId]);
            }catch(Throwable $e){
                // A concurrent creator may have won the unique Team General insert.
            }
            $stmt->execute([$workspaceOwnerId]);
            $conversation=$stmt->fetch();
        }
        if(!$conversation)throw new RuntimeException('Team General could not be created.');

        // No human_conversation_members row is created here. Team General access is
        // always the current Team ledger/projection, never copied participant state.
        if($owns)$pdo->commit();
        return $conversation;
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function vp3_human_messages_v370(PDO $pdo,int $conversationId,int $userId,int $afterId=0,int $limit=120): array
{
    $conversation=vp3_human_conversation_v370($pdo,$conversationId);
    if(!$conversation||!vp3_human_can_access_v370($pdo,$conversation,$userId))throw new RuntimeException('Conversation is not available.');
    $limit=max(1,min(200,$limit));
    $stmt=$pdo->prepare("SELECT m.id,m.conversation_id,m.sender_user_id,
        CASE WHEN m.deleted_at IS NULL THEN m.body ELSE '' END body,
        m.reply_to_message_id,m.edited_at,m.deleted_at,m.created_at,u.display_name,u.avatar_path
        FROM human_messages m
        INNER JOIN users u ON u.id=m.sender_user_id
        WHERE m.conversation_id=? AND m.id>?
        ORDER BY m.id ASC LIMIT {$limit}");
    $stmt->execute([$conversationId,max(0,$afterId)]);
    return $stmt->fetchAll()?:[];
}

function vp3_human_read_cursor_v370(PDO $pdo,int $conversationId,int $userId): int
{
    $stmt=$pdo->prepare('SELECT COALESCE(last_read_message_id,0) FROM human_conversation_reads_v370 WHERE conversation_id=? AND user_id=? LIMIT 1');
    $stmt->execute([$conversationId,$userId]);
    return (int)$stmt->fetchColumn();
}

function vp3_human_mark_read_v370(PDO $pdo,int $conversationId,int $userId,int $throughMessageId=0): int
{
    vp3_human_messaging_v370_ensure_schema($pdo);
    $conversation=vp3_human_conversation_v370($pdo,$conversationId);
    if(!$conversation||!vp3_human_can_access_v370($pdo,$conversation,$userId))throw new RuntimeException('Conversation is not available.');

    if($throughMessageId>0){
        $stmt=$pdo->prepare('SELECT id FROM human_messages WHERE id=? AND conversation_id=? LIMIT 1');
        $stmt->execute([$throughMessageId,$conversationId]);
        $last=(int)$stmt->fetchColumn();
        if($last<1)throw new RuntimeException('Message is not part of this conversation.');
    }else{
        $stmt=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM human_messages WHERE conversation_id=?');
        $stmt->execute([$conversationId]);
        $last=(int)$stmt->fetchColumn();
    }

    $stmt=$pdo->prepare("INSERT INTO human_conversation_reads_v370 (conversation_id,user_id,last_read_message_id)
        VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE last_read_message_id=GREATEST(COALESCE(last_read_message_id,0),VALUES(last_read_message_id)),updated_at=NOW()");
    $stmt->execute([$conversationId,$userId,$last]);
    return max($last,vp3_human_read_cursor_v370($pdo,$conversationId,$userId));
}

function vp3_human_unread_count_v370(PDO $pdo,int $conversationId,int $userId): int
{
    $stmt=$pdo->prepare("SELECT COUNT(*)
        FROM human_messages m
        LEFT JOIN human_conversation_reads_v370 r ON r.conversation_id=m.conversation_id AND r.user_id=?
        WHERE m.conversation_id=? AND m.sender_user_id<>? AND m.deleted_at IS NULL
          AND m.id>COALESCE(r.last_read_message_id,0)");
    $stmt->execute([$userId,$conversationId,$userId]);
    return (int)$stmt->fetchColumn();
}

function vp3_human_team_workspaces_v370(PDO $pdo,int $userId): array
{
    if($userId<1)return [];
    $out=[];
    if(table_exists('artist_workspaces_v181')){
        $stmt=$pdo->prepare('SELECT aw.artist_user_id owner_user_id,aw.workspace_name
            FROM artist_workspaces_v181 aw
            INNER JOIN users u ON u.id=aw.artist_user_id AND u.is_active=1
            WHERE aw.artist_user_id=?');
        $stmt->execute([$userId]);
        foreach($stmt->fetchAll()?:[] as $row)$out[(int)$row['owner_user_id']]=$row;
    }
    if(table_exists('artist_team_members')){
        if(table_exists('artist_workspaces_v181')){
            $stmt=$pdo->prepare("SELECT atm.artist_user_id owner_user_id,COALESCE(NULLIF(aw.workspace_name,''),u.display_name) workspace_name
                FROM artist_team_members atm
                INNER JOIN users u ON u.id=atm.artist_user_id AND u.is_active=1
                LEFT JOIN artist_workspaces_v181 aw ON aw.artist_user_id=atm.artist_user_id
                WHERE atm.member_user_id=?");
        }else{
            $stmt=$pdo->prepare('SELECT atm.artist_user_id owner_user_id,u.display_name workspace_name
                FROM artist_team_members atm
                INNER JOIN users u ON u.id=atm.artist_user_id AND u.is_active=1
                WHERE atm.member_user_id=?');
        }
        $stmt->execute([$userId]);
        foreach($stmt->fetchAll()?:[] as $row)$out[(int)$row['owner_user_id']]=$row;
    }
    return array_values($out);
}

function vp3_human_inbox_v370(PDO $pdo,int $userId): array
{
    vp3_human_messaging_v370_ensure_schema($pdo);
    $teams=[];
    foreach(vp3_human_team_workspaces_v370($pdo,$userId) as $workspace){
        try{
            $conversation=vp3_human_team_general_v370($pdo,(int)$workspace['owner_user_id'],$userId);
            $teams[]=[
                'conversation_id'=>(int)$conversation['id'],
                'workspace_owner_user_id'=>(int)$workspace['owner_user_id'],
                'title'=>(string)$workspace['workspace_name'].' · General',
                'unread_count'=>vp3_human_unread_count_v370($pdo,(int)$conversation['id'],$userId),
            ];
        }catch(Throwable $e){}
    }

    $stmt=$pdo->prepare("SELECT c.id,c.conversation_type,c.title,c.direct_user_low_id,c.direct_user_high_id,c.updated_at,
      CASE WHEN c.direct_user_low_id=? THEN c.direct_user_high_id ELSE c.direct_user_low_id END other_user_id,
      u.display_name other_name,u.avatar_path other_avatar,
      r.status request_status,r.requester_user_id,r.recipient_user_id,
      (SELECT CASE WHEN lm.deleted_at IS NULL THEN lm.body ELSE '' END
        FROM human_messages lm WHERE lm.conversation_id=c.id ORDER BY lm.id DESC LIMIT 1) last_message,
      (SELECT created_at FROM human_messages lm WHERE lm.conversation_id=c.id ORDER BY lm.id DESC LIMIT 1) last_message_at,
      (SELECT COUNT(*) FROM human_messages um
        LEFT JOIN human_conversation_reads_v370 rr ON rr.conversation_id=um.conversation_id AND rr.user_id=?
        WHERE um.conversation_id=c.id AND um.sender_user_id<>? AND um.deleted_at IS NULL
          AND um.id>COALESCE(rr.last_read_message_id,0)) unread_count
      FROM human_conversations c
      INNER JOIN human_conversation_members cm ON cm.conversation_id=c.id AND cm.user_id=? AND cm.left_at IS NULL
      LEFT JOIN users u ON u.id=CASE WHEN c.direct_user_low_id=? THEN c.direct_user_high_id ELSE c.direct_user_low_id END
      LEFT JOIN human_message_requests r ON r.conversation_id=c.id
      WHERE c.conversation_type IN ('direct','group')
        AND NOT (r.status='pending' AND r.recipient_user_id=?)
        AND (r.status IS NULL OR r.status<>'declined' OR r.requester_user_id=?)
      ORDER BY COALESCE(last_message_at,c.updated_at) DESC,c.id DESC LIMIT 100");
    $stmt->execute([$userId,$userId,$userId,$userId,$userId,$userId,$userId]);

    $conversations=[];
    foreach($stmt->fetchAll()?:[] as $row){
        if((string)$row['conversation_type']==='direct'&&vp3_human_blocked_v370($pdo,$userId,(int)$row['other_user_id']))continue;
        $conversations[]=$row;
    }

    $requestStmt=$pdo->prepare("SELECT r.conversation_id,r.requester_user_id,r.recipient_user_id,r.status,r.created_at,
        u.display_name requester_name,u.avatar_path requester_avatar,
        CASE WHEN m.deleted_at IS NULL THEN m.body ELSE '' END initial_message
        FROM human_message_requests r
        INNER JOIN users u ON u.id=r.requester_user_id AND u.is_active=1
        LEFT JOIN human_messages m ON m.id=r.initial_message_id
        WHERE r.recipient_user_id=? AND r.status='pending'
        ORDER BY r.created_at DESC");
    $requestStmt->execute([$userId]);

    $requests=[];
    foreach($requestStmt->fetchAll()?:[] as $row){
        if(!vp3_human_blocked_v370($pdo,$userId,(int)$row['requester_user_id']))$requests[]=$row;
    }
    return ['teams'=>$teams,'conversations'=>$conversations,'requests'=>$requests];
}

function vp3_human_migration_conversation_v370(PDO $pdo,int $a,int $b,int $createdBy): array
{
    vp3_human_lock_users_v370($pdo,$a,$b,false);
    return vp3_human_direct_conversation_locked_v370($pdo,$a,$b,$createdBy);
}

function vp3_human_migrate_existing_read_state_v370(PDO $pdo): void
{
    if(!table_exists('human_conversation_members'))return;
    $pdo->exec("INSERT INTO human_conversation_reads_v370 (conversation_id,user_id,last_read_message_id,updated_at)
        SELECT conversation_id,user_id,last_read_message_id,updated_at
        FROM human_conversation_members
        WHERE last_read_message_id IS NOT NULL
        ON DUPLICATE KEY UPDATE
          last_read_message_id=GREATEST(COALESCE(human_conversation_reads_v370.last_read_message_id,0),VALUES(last_read_message_id)),
          updated_at=GREATEST(human_conversation_reads_v370.updated_at,VALUES(updated_at))");
}

/** Idempotently preserve historical Team DMs in the canonical human message ledger. */
function vp3_human_messaging_v370_migrate_legacy(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_human_messaging_v370_ensure_schema($pdo);
    vp3_human_migrate_existing_read_state_v370($pdo);

    if(table_exists('team_direct_messages')){
        $rows=$pdo->query('SELECT id,sender_user_id,recipient_user_id,message_text,created_at,read_at FROM team_direct_messages ORDER BY id ASC')->fetchAll()?:[];
        foreach($rows as $legacy){
            $sourceId=(int)($legacy['id']??0);
            $senderId=(int)($legacy['sender_user_id']??0);
            $recipientId=(int)($legacy['recipient_user_id']??0);
            if($sourceId<1||$senderId<1||$recipientId<1||$senderId===$recipientId)continue;

            $check=$pdo->prepare("SELECT human_message_id FROM human_message_legacy_links_v370 WHERE source_type='team_direct_messages' AND source_id=? LIMIT 1");
            $check->execute([$sourceId]);
            if($check->fetchColumn())continue;

            $owns=!$pdo->inTransaction();
            if($owns)$pdo->beginTransaction();
            try{
                $conversation=vp3_human_migration_conversation_v370($pdo,$senderId,$recipientId,$senderId);
                $check=$pdo->prepare("SELECT human_message_id FROM human_message_legacy_links_v370 WHERE source_type='team_direct_messages' AND source_id=? LIMIT 1 FOR UPDATE");
                $check->execute([$sourceId]);
                if($check->fetchColumn()){
                    if($owns)$pdo->commit();
                    continue;
                }

                $message=vp3_human_insert_message_v370(
                    $pdo,
                    $conversation,
                    $senderId,
                    (string)($legacy['message_text']??''),
                    (string)($legacy['created_at']??date('Y-m-d H:i:s'))
                );
                $messageId=(int)$message['id'];

                $pdo->prepare("INSERT INTO human_message_legacy_links_v370 (source_type,source_id,human_message_id)
                    VALUES ('team_direct_messages',?,?)")->execute([$sourceId,$messageId]);

                if(!empty($legacy['read_at'])){
                    $stmt=$pdo->prepare("INSERT INTO human_conversation_reads_v370 (conversation_id,user_id,last_read_message_id,updated_at)
                        VALUES (?,?,?,?)
                        ON DUPLICATE KEY UPDATE
                          last_read_message_id=GREATEST(COALESCE(last_read_message_id,0),VALUES(last_read_message_id)),
                          updated_at=GREATEST(updated_at,VALUES(updated_at))");
                    $stmt->execute([(int)$conversation['id'],$recipientId,$messageId,(string)$legacy['read_at']]);
                }

                if($owns)$pdo->commit();
            }catch(Throwable $e){
                if($owns&&$pdo->inTransaction())$pdo->rollBack();
                throw $e;
            }
        }
    }

    if(function_exists('save_setting'))save_setting('human_messaging_v370_migrated',VP3_HUMAN_MESSAGING_V370);
}
