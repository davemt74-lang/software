<?php
declare(strict_types=1);

/**
 * VP3 human relationship + messaging foundation.
 *
 * This domain is intentionally separate from Agent Chat and Profile Agent visitor
 * conversations. Team authorization is derived from the current workspace
 * relationship; social authorization is derived from follows/friends/blocks and
 * the recipient's privacy settings.
 */
const VP3_SOCIAL_NETWORK_V320 = 'vp3-social-network-v320-20260909';

function vp3_social_message_policies_v320(): array
{
    return [
        'friends' => 'Friends only',
        'following' => 'People I follow',
        'followers' => 'Followers',
        'anyone' => 'Anyone',
    ];
}

function vp3_social_schema_ready_v320(?PDO $pdo=null): bool
{
    $pdo ??= db();
    if(!$pdo)return false;
    foreach([
        'user_social_settings','user_follows','user_friendships','user_blocks',
        'human_conversations','human_conversation_members','human_messages','human_message_requests',
    ] as $table)if(!table_exists($table))return false;
    return true;
}

function vp3_social_ensure_schema_v320(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_social_settings (
      user_id INT UNSIGNED NOT NULL PRIMARY KEY,
      message_policy VARCHAR(24) NOT NULL DEFAULT 'friends',
      allow_friend_requests TINYINT(1) NOT NULL DEFAULT 1,
      show_followers TINYINT(1) NOT NULL DEFAULT 1,
      show_following TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_social_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_follows (
      follower_user_id INT UNSIGNED NOT NULL,
      followed_user_id INT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (follower_user_id,followed_user_id),
      INDEX idx_user_follows_followed (followed_user_id,created_at,follower_user_id),
      CONSTRAINT fk_user_follow_follower FOREIGN KEY (follower_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_user_follow_followed FOREIGN KEY (followed_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_friendships (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_low_id INT UNSIGNED NOT NULL,
      user_high_id INT UNSIGNED NOT NULL,
      requested_by_user_id INT UNSIGNED NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'pending',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      accepted_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_user_friendship_pair (user_low_id,user_high_id),
      INDEX idx_user_friendship_requester (requested_by_user_id,status,updated_at),
      CONSTRAINT fk_user_friendship_low FOREIGN KEY (user_low_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_user_friendship_high FOREIGN KEY (user_high_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_user_friendship_requester FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_blocks (
      blocker_user_id INT UNSIGNED NOT NULL,
      blocked_user_id INT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (blocker_user_id,blocked_user_id),
      INDEX idx_user_blocks_blocked (blocked_user_id,blocker_user_id),
      CONSTRAINT fk_user_block_blocker FOREIGN KEY (blocker_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_user_block_blocked FOREIGN KEY (blocked_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS human_conversations (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      conversation_type VARCHAR(24) NOT NULL,
      workspace_owner_user_id INT UNSIGNED NULL,
      direct_user_low_id INT UNSIGNED NULL,
      direct_user_high_id INT UNSIGNED NULL,
      title VARCHAR(190) NOT NULL DEFAULT '',
      created_by_user_id INT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_human_direct_pair (conversation_type,direct_user_low_id,direct_user_high_id),
      UNIQUE KEY uq_human_team_general (conversation_type,workspace_owner_user_id),
      INDEX idx_human_conversation_workspace (workspace_owner_user_id,conversation_type,updated_at),
      CONSTRAINT fk_human_conversation_workspace_owner FOREIGN KEY (workspace_owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_human_conversation_direct_low FOREIGN KEY (direct_user_low_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_human_conversation_direct_high FOREIGN KEY (direct_user_high_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_human_conversation_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS human_conversation_members (
      conversation_id BIGINT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      member_role VARCHAR(24) NOT NULL DEFAULT 'member',
      joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      left_at DATETIME NULL,
      muted_until DATETIME NULL,
      last_read_message_id BIGINT UNSIGNED NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (conversation_id,user_id),
      INDEX idx_human_member_user (user_id,left_at,conversation_id),
      CONSTRAINT fk_human_member_conversation FOREIGN KEY (conversation_id) REFERENCES human_conversations(id) ON DELETE CASCADE,
      CONSTRAINT fk_human_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS human_messages (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      conversation_id BIGINT UNSIGNED NOT NULL,
      sender_user_id INT UNSIGNED NOT NULL,
      body TEXT NOT NULL,
      reply_to_message_id BIGINT UNSIGNED NULL,
      edited_at DATETIME NULL,
      deleted_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_human_message_conversation (conversation_id,id),
      INDEX idx_human_message_sender (sender_user_id,created_at,id),
      CONSTRAINT fk_human_message_conversation FOREIGN KEY (conversation_id) REFERENCES human_conversations(id) ON DELETE CASCADE,
      CONSTRAINT fk_human_message_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_human_message_reply FOREIGN KEY (reply_to_message_id) REFERENCES human_messages(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS human_message_requests (
      conversation_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      requester_user_id INT UNSIGNED NOT NULL,
      recipient_user_id INT UNSIGNED NOT NULL,
      initial_message_id BIGINT UNSIGNED NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'pending',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      resolved_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_human_request_recipient (recipient_user_id,status,created_at),
      CONSTRAINT fk_human_request_conversation FOREIGN KEY (conversation_id) REFERENCES human_conversations(id) ON DELETE CASCADE,
      CONSTRAINT fk_human_request_requester FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_human_request_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_human_request_initial FOREIGN KEY (initial_message_id) REFERENCES human_messages(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_social_settings_v320(PDO $pdo,int $userId): array
{
    $defaults=['message_policy'=>'friends','allow_friend_requests'=>true,'show_followers'=>true,'show_following'=>true];
    if($userId<1)return $defaults;
    vp3_social_ensure_schema_v320($pdo);
    $stmt=$pdo->prepare('SELECT message_policy,allow_friend_requests,show_followers,show_following FROM user_social_settings WHERE user_id=? LIMIT 1');
    $stmt->execute([$userId]);$row=$stmt->fetch();if(!$row)return $defaults;
    $policy=(string)($row['message_policy']??'friends');if(!isset(vp3_social_message_policies_v320()[$policy]))$policy='friends';
    return ['message_policy'=>$policy,'allow_friend_requests'=>(bool)$row['allow_friend_requests'],'show_followers'=>(bool)$row['show_followers'],'show_following'=>(bool)$row['show_following']];
}

function vp3_social_save_settings_v320(PDO $pdo,int $userId,array $input): array
{
    if($userId<1)throw new RuntimeException('Sign in to update social settings.');
    vp3_social_ensure_schema_v320($pdo);
    $policy=(string)($input['message_policy']??'friends');if(!isset(vp3_social_message_policies_v320()[$policy]))throw new RuntimeException('Choose a valid messaging policy.');
    $friend=!empty($input['allow_friend_requests'])?1:0;$followers=!empty($input['show_followers'])?1:0;$following=!empty($input['show_following'])?1:0;
    $stmt=$pdo->prepare('INSERT INTO user_social_settings (user_id,message_policy,allow_friend_requests,show_followers,show_following) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE message_policy=VALUES(message_policy),allow_friend_requests=VALUES(allow_friend_requests),show_followers=VALUES(show_followers),show_following=VALUES(show_following)');
    $stmt->execute([$userId,$policy,$friend,$followers,$following]);
    return vp3_social_settings_v320($pdo,$userId);
}

function vp3_social_pair_v320(int $a,int $b): array
{
    if($a<1||$b<1||$a===$b)throw new RuntimeException('Choose another VP3 member.');
    return $a<$b?[$a,$b]:[$b,$a];
}

function vp3_social_user_active_v320(PDO $pdo,int $userId): bool
{
    if($userId<1)return false;$stmt=$pdo->prepare('SELECT 1 FROM users WHERE id=? AND is_active=1 LIMIT 1');$stmt->execute([$userId]);return (bool)$stmt->fetchColumn();
}

function vp3_social_blocked_v320(PDO $pdo,int $a,int $b): bool
{
    if($a<1||$b<1)return true;vp3_social_ensure_schema_v320($pdo);
    $stmt=$pdo->prepare('SELECT 1 FROM user_blocks WHERE (blocker_user_id=? AND blocked_user_id=?) OR (blocker_user_id=? AND blocked_user_id=?) LIMIT 1');
    $stmt->execute([$a,$b,$b,$a]);return (bool)$stmt->fetchColumn();
}

function vp3_social_following_v320(PDO $pdo,int $follower,int $followed): bool
{
    if($follower<1||$followed<1)return false;vp3_social_ensure_schema_v320($pdo);
    $stmt=$pdo->prepare('SELECT 1 FROM user_follows WHERE follower_user_id=? AND followed_user_id=? LIMIT 1');$stmt->execute([$follower,$followed]);return (bool)$stmt->fetchColumn();
}

function vp3_social_friendship_v320(PDO $pdo,int $a,int $b): ?array
{
    [$low,$high]=vp3_social_pair_v320($a,$b);vp3_social_ensure_schema_v320($pdo);
    $stmt=$pdo->prepare('SELECT * FROM user_friendships WHERE user_low_id=? AND user_high_id=? LIMIT 1');$stmt->execute([$low,$high]);$row=$stmt->fetch();return $row?:null;
}

function vp3_social_friends_v320(PDO $pdo,int $a,int $b): bool
{
    try{$row=vp3_social_friendship_v320($pdo,$a,$b);return $row&&$row['status']==='accepted';}catch(Throwable $e){return false;}
}

function vp3_social_shared_workspace_v320(PDO $pdo,int $a,int $b): bool
{
    if($a<1||$b<1||$a===$b||!table_exists('artist_team_members'))return false;
    $sql="SELECT 1 FROM (SELECT ? AS user_id, ? AS peer_id) pair WHERE EXISTS(SELECT 1 FROM artist_team_members x WHERE x.artist_user_id=pair.user_id AND x.member_user_id=pair.peer_id) OR EXISTS(SELECT 1 FROM artist_team_members x WHERE x.artist_user_id=pair.peer_id AND x.member_user_id=pair.user_id) OR EXISTS(SELECT 1 FROM artist_team_members a1 INNER JOIN artist_team_members a2 ON a2.artist_user_id=a1.artist_user_id WHERE a1.member_user_id=pair.user_id AND a2.member_user_id=pair.peer_id) LIMIT 1";
    $stmt=$pdo->prepare($sql);$stmt->execute([$a,$b]);return (bool)$stmt->fetchColumn();
}

function vp3_social_dm_route_v320(PDO $pdo,int $senderId,int $recipientId): string
{
    if(!vp3_social_user_active_v320($pdo,$senderId)||!vp3_social_user_active_v320($pdo,$recipientId)||$senderId===$recipientId)return 'deny';
    if(vp3_social_blocked_v320($pdo,$senderId,$recipientId))return 'deny';
    if(vp3_social_shared_workspace_v320($pdo,$senderId,$recipientId)||vp3_social_friends_v320($pdo,$senderId,$recipientId))return 'direct';
    $settings=vp3_social_settings_v320($pdo,$recipientId);$policy=(string)$settings['message_policy'];
    if($policy==='following'&&vp3_social_following_v320($pdo,$recipientId,$senderId))return 'direct';
    if($policy==='followers'&&vp3_social_following_v320($pdo,$senderId,$recipientId))return 'request';
    if($policy==='anyone')return 'request';
    return 'deny';
}

function vp3_social_direct_conversation_v320(PDO $pdo,int $a,int $b,int $createdBy=0): array
{
    [$low,$high]=vp3_social_pair_v320($a,$b);vp3_social_ensure_schema_v320($pdo);
    $stmt=$pdo->prepare("SELECT * FROM human_conversations WHERE conversation_type='direct' AND direct_user_low_id=? AND direct_user_high_id=? LIMIT 1");$stmt->execute([$low,$high]);$row=$stmt->fetch();if($row)return $row;
    $creator=$createdBy>0?$createdBy:$a;
    try{$pdo->prepare("INSERT INTO human_conversations (conversation_type,direct_user_low_id,direct_user_high_id,created_by_user_id) VALUES ('direct',?,?,?)")->execute([$low,$high,$creator]);}catch(Throwable $e){}
    $stmt->execute([$low,$high]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('Conversation could not be created.');
    foreach([$low,$high] as $uid)$pdo->prepare("INSERT INTO human_conversation_members (conversation_id,user_id,member_role) VALUES (?,?,'member') ON DUPLICATE KEY UPDATE left_at=NULL")->execute([(int)$row['id'],$uid]);
    return $row;
}

function vp3_social_team_general_v320(PDO $pdo,int $workspaceOwnerId,int $actorId): array
{
    if($workspaceOwnerId<1||$actorId<1)throw new RuntimeException('Choose a Team workspace.');
    $authorized=$actorId===$workspaceOwnerId;
    if(!$authorized&&table_exists('artist_team_members')){$stmt=$pdo->prepare('SELECT 1 FROM artist_team_members WHERE artist_user_id=? AND member_user_id=? LIMIT 1');$stmt->execute([$workspaceOwnerId,$actorId]);$authorized=(bool)$stmt->fetchColumn();}
    if(!$authorized)throw new RuntimeException('You are not an active member of that workspace.');
    vp3_social_ensure_schema_v320($pdo);
    $stmt=$pdo->prepare("SELECT * FROM human_conversations WHERE conversation_type='team_general' AND workspace_owner_user_id=? LIMIT 1");$stmt->execute([$workspaceOwnerId]);$row=$stmt->fetch();
    if(!$row){try{$pdo->prepare("INSERT INTO human_conversations (conversation_type,workspace_owner_user_id,title,created_by_user_id) VALUES ('team_general',?,'General',?)")->execute([$workspaceOwnerId,$workspaceOwnerId]);}catch(Throwable $e){}$stmt->execute([$workspaceOwnerId]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('Team General could not be created.');}
    return $row;
}

function vp3_social_conversation_v320(PDO $pdo,int $conversationId): ?array
{
    if($conversationId<1)return null;vp3_social_ensure_schema_v320($pdo);$stmt=$pdo->prepare('SELECT * FROM human_conversations WHERE id=? LIMIT 1');$stmt->execute([$conversationId]);$row=$stmt->fetch();return $row?:null;
}

function vp3_social_can_access_conversation_v320(PDO $pdo,array $conversation,int $userId): bool
{
    if($userId<1)return false;$type=(string)($conversation['conversation_type']??'');
    if($type==='team_general'){$owner=(int)($conversation['workspace_owner_user_id']??0);if($owner<1)return false;if($owner===$userId)return vp3_social_user_active_v320($pdo,$userId);if(!table_exists('artist_team_members'))return false;$stmt=$pdo->prepare('SELECT 1 FROM artist_team_members atm INNER JOIN users u ON u.id=atm.member_user_id AND u.is_active=1 WHERE atm.artist_user_id=? AND atm.member_user_id=? LIMIT 1');$stmt->execute([$owner,$userId]);return (bool)$stmt->fetchColumn();}
    $stmt=$pdo->prepare('SELECT 1 FROM human_conversation_members WHERE conversation_id=? AND user_id=? AND left_at IS NULL LIMIT 1');$stmt->execute([(int)$conversation['id'],$userId]);if(!$stmt->fetchColumn())return false;
    if($type==='direct'){$other=(int)$conversation['direct_user_low_id']===$userId?(int)$conversation['direct_user_high_id']:(int)$conversation['direct_user_low_id'];return !vp3_social_blocked_v320($pdo,$userId,$other);}return true;
}

function vp3_social_request_v320(PDO $pdo,int $conversationId): ?array{$stmt=$pdo->prepare('SELECT * FROM human_message_requests WHERE conversation_id=? LIMIT 1');$stmt->execute([$conversationId]);$row=$stmt->fetch();return $row?:null;}

function vp3_social_start_direct_v320(PDO $pdo,int $senderId,int $recipientId,string $initialMessage=''): array
{
    $route=vp3_social_dm_route_v320($pdo,$senderId,$recipientId);if($route==='deny')throw new RuntimeException('This member is not accepting messages from you.');
    $conversation=vp3_social_direct_conversation_v320($pdo,$senderId,$recipientId,$senderId);$cid=(int)$conversation['id'];$request=vp3_social_request_v320($pdo,$cid);
    if($route==='request'){if($request&&$request['status']==='declined')throw new RuntimeException('This message request was declined.');if(!$request)$pdo->prepare("INSERT INTO human_message_requests (conversation_id,requester_user_id,recipient_user_id,status) VALUES (?,?,?,'pending')")->execute([$cid,$senderId,$recipientId]);}
    elseif($request&&$request['status']==='pending')$pdo->prepare("UPDATE human_message_requests SET status='accepted',resolved_at=NOW() WHERE conversation_id=?")->execute([$cid]);
    $message=null;if(trim($initialMessage)!=='')$message=vp3_social_send_message_v320($pdo,$cid,$senderId,$initialMessage);return ['conversation'=>$conversation,'route'=>$route,'message'=>$message];
}

function vp3_social_send_message_v320(PDO $pdo,int $conversationId,int $senderId,string $body): array
{
    $body=trim($body);if($body==='')throw new RuntimeException('Message cannot be empty.');if(mb_strlen($body)>4000)throw new RuntimeException('Message is too long.');
    $conversation=vp3_social_conversation_v320($pdo,$conversationId);if(!$conversation||!vp3_social_can_access_conversation_v320($pdo,$conversation,$senderId))throw new RuntimeException('Conversation is not available.');$request=vp3_social_request_v320($pdo,$conversationId);
    if($request&&$request['status']==='pending'){if((int)$request['requester_user_id']!==$senderId)throw new RuntimeException('Accept the message request before replying.');if((int)($request['initial_message_id']??0)>0)throw new RuntimeException('Your message request is pending. You can send another message after it is accepted.');}elseif($request&&$request['status']==='declined')throw new RuntimeException('This message request was declined.');
    $stmt=$pdo->prepare('INSERT INTO human_messages (conversation_id,sender_user_id,body) VALUES (?,?,?)');$stmt->execute([$conversationId,$senderId,$body]);$id=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE human_conversations SET updated_at=NOW() WHERE id=?')->execute([$conversationId]);if($request&&$request['status']==='pending'&&(int)($request['initial_message_id']??0)<1)$pdo->prepare('UPDATE human_message_requests SET initial_message_id=? WHERE conversation_id=?')->execute([$id,$conversationId]);return ['id'=>$id,'conversation_id'=>$conversationId,'sender_user_id'=>$senderId,'body'=>$body,'created_at'=>date('Y-m-d H:i:s')];
}

function vp3_social_resolve_request_v320(PDO $pdo,int $conversationId,int $recipientId,bool $accept): void
{
    $request=vp3_social_request_v320($pdo,$conversationId);if(!$request||(int)$request['recipient_user_id']!==$recipientId||$request['status']!=='pending')throw new RuntimeException('Message request is not available.');$status=$accept?'accepted':'declined';$pdo->prepare('UPDATE human_message_requests SET status=?,resolved_at=NOW() WHERE conversation_id=? AND recipient_user_id=?')->execute([$status,$conversationId,$recipientId]);
}

function vp3_social_messages_v320(PDO $pdo,int $conversationId,int $userId,int $afterId=0,int $limit=120): array
{
    $conversation=vp3_social_conversation_v320($pdo,$conversationId);if(!$conversation||!vp3_social_can_access_conversation_v320($pdo,$conversation,$userId))throw new RuntimeException('Conversation is not available.');$limit=max(1,min(200,$limit));$stmt=$pdo->prepare("SELECT m.id,m.conversation_id,m.sender_user_id,m.body,m.reply_to_message_id,m.edited_at,m.deleted_at,m.created_at,u.display_name,u.avatar_path FROM human_messages m INNER JOIN users u ON u.id=m.sender_user_id WHERE m.conversation_id=? AND m.id>? ORDER BY m.id ASC LIMIT {$limit}");$stmt->execute([$conversationId,max(0,$afterId)]);return $stmt->fetchAll()?:[];
}

function vp3_social_follow_v320(PDO $pdo,int $actorId,int $targetId,bool $follow): void
{
    vp3_social_pair_v320($actorId,$targetId);if(vp3_social_blocked_v320($pdo,$actorId,$targetId))throw new RuntimeException('This relationship is unavailable.');if($follow)$pdo->prepare('INSERT IGNORE INTO user_follows (follower_user_id,followed_user_id) VALUES (?,?)')->execute([$actorId,$targetId]);else $pdo->prepare('DELETE FROM user_follows WHERE follower_user_id=? AND followed_user_id=?')->execute([$actorId,$targetId]);
}

function vp3_social_friend_request_v320(PDO $pdo,int $actorId,int $targetId): void
{
    [$low,$high]=vp3_social_pair_v320($actorId,$targetId);if(vp3_social_blocked_v320($pdo,$actorId,$targetId))throw new RuntimeException('This relationship is unavailable.');$settings=vp3_social_settings_v320($pdo,$targetId);if(empty($settings['allow_friend_requests']))throw new RuntimeException('This member is not accepting friend requests.');$row=vp3_social_friendship_v320($pdo,$actorId,$targetId);if($row&&$row['status']==='accepted')return;if($row&&$row['status']==='pending')throw new RuntimeException('A friend request is already pending.');$pdo->prepare("INSERT INTO user_friendships (user_low_id,user_high_id,requested_by_user_id,status,accepted_at) VALUES (?,?,?,'pending',NULL) ON DUPLICATE KEY UPDATE requested_by_user_id=VALUES(requested_by_user_id),status='pending',accepted_at=NULL,updated_at=NOW()")->execute([$low,$high,$actorId]);
}

function vp3_social_friend_accept_v320(PDO $pdo,int $actorId,int $otherId): void
{
    $row=vp3_social_friendship_v320($pdo,$actorId,$otherId);if(!$row||$row['status']!=='pending'||(int)$row['requested_by_user_id']===$actorId)throw new RuntimeException('Friend request is not available.');$pdo->prepare("UPDATE user_friendships SET status='accepted',accepted_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$row['id']]);
}

function vp3_social_block_v320(PDO $pdo,int $actorId,int $targetId,bool $blocked): void
{
    vp3_social_pair_v320($actorId,$targetId);vp3_social_ensure_schema_v320($pdo);if($blocked){$pdo->prepare('INSERT IGNORE INTO user_blocks (blocker_user_id,blocked_user_id) VALUES (?,?)')->execute([$actorId,$targetId]);$pdo->prepare('DELETE FROM user_follows WHERE (follower_user_id=? AND followed_user_id=?) OR (follower_user_id=? AND followed_user_id=?)')->execute([$actorId,$targetId,$targetId,$actorId]);[$low,$high]=vp3_social_pair_v320($actorId,$targetId);$pdo->prepare("UPDATE user_friendships SET status='declined',accepted_at=NULL WHERE user_low_id=? AND user_high_id=?")->execute([$low,$high]);}else $pdo->prepare('DELETE FROM user_blocks WHERE blocker_user_id=? AND blocked_user_id=?')->execute([$actorId,$targetId]);
}

function vp3_social_relationship_state_v320(PDO $pdo,int $viewerId,int $targetId): array
{
    $friend=null;try{$friend=vp3_social_friendship_v320($pdo,$viewerId,$targetId);}catch(Throwable $e){}return ['following'=>vp3_social_following_v320($pdo,$viewerId,$targetId),'followed_by'=>vp3_social_following_v320($pdo,$targetId,$viewerId),'friendship_status'=>(string)($friend['status']??''),'friend_requested_by'=>(int)($friend['requested_by_user_id']??0),'blocked'=>vp3_social_blocked_v320($pdo,$viewerId,$targetId),'shared_workspace'=>vp3_social_shared_workspace_v320($pdo,$viewerId,$targetId),'dm_route'=>vp3_social_dm_route_v320($pdo,$viewerId,$targetId)];
}

function vp3_social_team_workspaces_for_user_v320(PDO $pdo,int $userId): array
{
    if($userId<1)return [];$workspaces=[];if(table_exists('artist_workspaces_v181')){$stmt=$pdo->prepare('SELECT aw.artist_user_id owner_user_id,aw.workspace_name FROM artist_workspaces_v181 aw INNER JOIN users u ON u.id=aw.artist_user_id AND u.is_active=1 WHERE aw.artist_user_id=?');$stmt->execute([$userId]);foreach($stmt->fetchAll()?:[] as $row)$workspaces[(int)$row['owner_user_id']]=$row;}if(table_exists('artist_team_members')){$stmt=$pdo->prepare("SELECT atm.artist_user_id owner_user_id,COALESCE(NULLIF(aw.workspace_name,''),u.display_name) workspace_name FROM artist_team_members atm INNER JOIN users u ON u.id=atm.artist_user_id AND u.is_active=1 LEFT JOIN artist_workspaces_v181 aw ON aw.artist_user_id=atm.artist_user_id WHERE atm.member_user_id=?");$stmt->execute([$userId]);foreach($stmt->fetchAll()?:[] as $row)$workspaces[(int)$row['owner_user_id']]=$row;}return array_values($workspaces);
}

function vp3_social_inbox_v320(PDO $pdo,int $userId): array
{
    vp3_social_ensure_schema_v320($pdo);$teams=[];foreach(vp3_social_team_workspaces_for_user_v320($pdo,$userId) as $workspace){try{$conversation=vp3_social_team_general_v320($pdo,(int)$workspace['owner_user_id'],$userId);$teams[]=['conversation_id'=>(int)$conversation['id'],'workspace_owner_user_id'=>(int)$workspace['owner_user_id'],'title'=>(string)$workspace['workspace_name'].' · General'];}catch(Throwable $e){}}
    $stmt=$pdo->prepare("SELECT c.id,c.conversation_type,c.title,c.direct_user_low_id,c.direct_user_high_id,c.updated_at,CASE WHEN c.direct_user_low_id=? THEN c.direct_user_high_id ELSE c.direct_user_low_id END other_user_id,u.display_name other_name,u.avatar_path other_avatar,(SELECT body FROM human_messages lm WHERE lm.conversation_id=c.id AND lm.deleted_at IS NULL ORDER BY lm.id DESC LIMIT 1) last_message,(SELECT created_at FROM human_messages lm WHERE lm.conversation_id=c.id ORDER BY lm.id DESC LIMIT 1) last_message_at FROM human_conversations c INNER JOIN human_conversation_members cm ON cm.conversation_id=c.id AND cm.user_id=? AND cm.left_at IS NULL LEFT JOIN users u ON u.id=CASE WHEN c.direct_user_low_id=? THEN c.direct_user_high_id ELSE c.direct_user_low_id END WHERE c.conversation_type IN ('direct','group') ORDER BY COALESCE(last_message_at,c.updated_at) DESC,c.id DESC LIMIT 100");$stmt->execute([$userId,$userId,$userId]);$conversations=$stmt->fetchAll()?:[];
    $requestStmt=$pdo->prepare("SELECT r.conversation_id,r.requester_user_id,r.created_at,u.display_name requester_name,u.avatar_path requester_avatar,m.body initial_message FROM human_message_requests r INNER JOIN users u ON u.id=r.requester_user_id LEFT JOIN human_messages m ON m.id=r.initial_message_id WHERE r.recipient_user_id=? AND r.status='pending' ORDER BY r.created_at DESC");$requestStmt->execute([$userId]);$requests=$requestStmt->fetchAll()?:[];return ['teams'=>$teams,'conversations'=>$conversations,'requests'=>$requests];
}
