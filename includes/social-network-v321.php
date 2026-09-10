<?php
declare(strict_types=1);

/** v321 policy/query refinements layered over the v320 relationship/message model. */
const VP3_SOCIAL_NETWORK_V321 = 'vp3-social-network-v321-20260909';

function vp3_social_team_workspaces_for_user_v321(PDO $pdo,int $userId): array
{
    if($userId<1)return [];$workspaces=[];
    if(table_exists('artist_workspaces_v181')){
        $stmt=$pdo->prepare('SELECT aw.artist_user_id owner_user_id,aw.workspace_name FROM artist_workspaces_v181 aw INNER JOIN users u ON u.id=aw.artist_user_id AND u.is_active=1 WHERE aw.artist_user_id=?');
        $stmt->execute([$userId]);foreach($stmt->fetchAll()?:[] as $row)$workspaces[(int)$row['owner_user_id']]=$row;
    }
    if(table_exists('artist_team_members')){
        if(table_exists('artist_workspaces_v181')){
            $stmt=$pdo->prepare("SELECT atm.artist_user_id owner_user_id,COALESCE(NULLIF(aw.workspace_name,''),u.display_name) workspace_name FROM artist_team_members atm INNER JOIN users u ON u.id=atm.artist_user_id AND u.is_active=1 LEFT JOIN artist_workspaces_v181 aw ON aw.artist_user_id=atm.artist_user_id WHERE atm.member_user_id=?");
        }else{
            $stmt=$pdo->prepare('SELECT atm.artist_user_id owner_user_id,u.display_name workspace_name FROM artist_team_members atm INNER JOIN users u ON u.id=atm.artist_user_id AND u.is_active=1 WHERE atm.member_user_id=?');
        }
        $stmt->execute([$userId]);foreach($stmt->fetchAll()?:[] as $row)$workspaces[(int)$row['owner_user_id']]=$row;
    }
    return array_values($workspaces);
}

function vp3_social_inbox_v321(PDO $pdo,int $userId): array
{
    vp3_social_ensure_schema_v320($pdo);$teams=[];
    foreach(vp3_social_team_workspaces_for_user_v321($pdo,$userId) as $workspace){
        try{$conversation=vp3_social_team_general_v320($pdo,(int)$workspace['owner_user_id'],$userId);$teams[]=['conversation_id'=>(int)$conversation['id'],'workspace_owner_user_id'=>(int)$workspace['owner_user_id'],'title'=>(string)$workspace['workspace_name'].' · General'];}catch(Throwable $e){}
    }
    $stmt=$pdo->prepare("SELECT c.id,c.conversation_type,c.title,c.direct_user_low_id,c.direct_user_high_id,c.updated_at,
      CASE WHEN c.direct_user_low_id=? THEN c.direct_user_high_id ELSE c.direct_user_low_id END other_user_id,
      u.display_name other_name,u.avatar_path other_avatar,
      r.status request_status,r.requester_user_id,r.recipient_user_id,
      (SELECT body FROM human_messages lm WHERE lm.conversation_id=c.id AND lm.deleted_at IS NULL ORDER BY lm.id DESC LIMIT 1) last_message,
      (SELECT created_at FROM human_messages lm WHERE lm.conversation_id=c.id ORDER BY lm.id DESC LIMIT 1) last_message_at
      FROM human_conversations c
      INNER JOIN human_conversation_members cm ON cm.conversation_id=c.id AND cm.user_id=? AND cm.left_at IS NULL
      LEFT JOIN users u ON u.id=CASE WHEN c.direct_user_low_id=? THEN c.direct_user_high_id ELSE c.direct_user_low_id END
      LEFT JOIN human_message_requests r ON r.conversation_id=c.id
      WHERE c.conversation_type IN ('direct','group')
        AND NOT (r.status='pending' AND r.recipient_user_id=?)
        AND (r.status IS NULL OR r.status<>'declined' OR r.requester_user_id=?)
      ORDER BY COALESCE(last_message_at,c.updated_at) DESC,c.id DESC LIMIT 100");
    $stmt->execute([$userId,$userId,$userId,$userId,$userId]);$conversations=$stmt->fetchAll()?:[];
    $requestStmt=$pdo->prepare("SELECT r.conversation_id,r.requester_user_id,r.recipient_user_id,r.status,r.created_at,u.display_name requester_name,u.avatar_path requester_avatar,m.body initial_message FROM human_message_requests r INNER JOIN users u ON u.id=r.requester_user_id LEFT JOIN human_messages m ON m.id=r.initial_message_id WHERE r.recipient_user_id=? AND r.status='pending' ORDER BY r.created_at DESC");
    $requestStmt->execute([$userId]);$requests=$requestStmt->fetchAll()?:[];
    return ['teams'=>$teams,'conversations'=>$conversations,'requests'=>$requests];
}

function vp3_social_direct_other_user_v321(array $conversation,int $userId): int
{
    $low=(int)($conversation['direct_user_low_id']??0);$high=(int)($conversation['direct_user_high_id']??0);
    if($userId===$low)return $high;if($userId===$high)return $low;return 0;
}

function vp3_social_send_message_v321(PDO $pdo,int $conversationId,int $senderId,string $body): array
{
    $conversation=vp3_social_conversation_v320($pdo,$conversationId);
    if(!$conversation)throw new RuntimeException('Conversation is not available.');
    if((string)($conversation['conversation_type']??'')==='direct'){
        $other=vp3_social_direct_other_user_v321($conversation,$senderId);if($other<1)throw new RuntimeException('Conversation is not available.');
        if(vp3_social_blocked_v320($pdo,$senderId,$other))throw new RuntimeException('This conversation is no longer available.');
        $request=vp3_social_request_v320($pdo,$conversationId);
        if($request&&$request['status']==='accepted')return vp3_social_send_message_v320($pdo,$conversationId,$senderId,$body);
        $route=vp3_social_dm_route_v320($pdo,$senderId,$other);
        if($request&&$request['status']==='declined'){
            if($route!=='direct')throw new RuntimeException('This message request was declined.');
            $pdo->prepare("UPDATE human_message_requests SET status='accepted',resolved_at=NOW(),updated_at=NOW() WHERE conversation_id=?")->execute([$conversationId]);
        }elseif(!$request){
            if($route==='deny')throw new RuntimeException('This member is not accepting messages from you.');
            if($route==='request')$pdo->prepare("INSERT INTO human_message_requests (conversation_id,requester_user_id,recipient_user_id,status) VALUES (?,?,?,'pending')")->execute([$conversationId,$senderId,$other]);
        }elseif($request['status']==='pending'&&(int)$request['requester_user_id']!==$senderId){
            throw new RuntimeException('Accept the message request before replying.');
        }
    }
    return vp3_social_send_message_v320($pdo,$conversationId,$senderId,$body);
}

function vp3_social_start_direct_v321(PDO $pdo,int $senderId,int $recipientId,string $initialMessage=''): array
{
    $route=vp3_social_dm_route_v320($pdo,$senderId,$recipientId);if($route==='deny')throw new RuntimeException('This member is not accepting messages from you.');
    $conversation=vp3_social_direct_conversation_v320($pdo,$senderId,$recipientId,$senderId);$cid=(int)$conversation['id'];$request=vp3_social_request_v320($pdo,$cid);
    if($route==='request'){
        if($request&&$request['status']==='declined')throw new RuntimeException('This message request was declined.');
        if(!$request)$pdo->prepare("INSERT INTO human_message_requests (conversation_id,requester_user_id,recipient_user_id,status) VALUES (?,?,?,'pending')")->execute([$cid,$senderId,$recipientId]);
    }elseif($request&&in_array((string)$request['status'],['pending','declined'],true)){
        $pdo->prepare("UPDATE human_message_requests SET status='accepted',resolved_at=NOW(),updated_at=NOW() WHERE conversation_id=?")->execute([$cid]);
    }
    $message=null;if(trim($initialMessage)!=='')$message=vp3_social_send_message_v321($pdo,$cid,$senderId,$initialMessage);
    return ['conversation'=>$conversation,'route'=>$route,'message'=>$message];
}
