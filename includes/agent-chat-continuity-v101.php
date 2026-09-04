<?php
declare(strict_types=1);

function agent_chat_v101_latest_conversation_id(PDO $pdo, int $userId): int
{
    if ($userId < 1 || !table_exists('chat_conversations') || !table_exists('chat_messages')) return 0;
    try {
        if (table_exists('agent_activity_state')) {
            // Editor surfaces retain the linked Agent Chat conversation in
            // details_json, so a Studio round-trip does not lose continuity.
            $activity=$pdo->prepare("SELECT details_json FROM agent_activity_state WHERE user_id=? AND last_heartbeat_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) LIMIT 1");
            $activity->execute([$userId]);$details=json_decode((string)($activity->fetchColumn()?:''),true);$activeId=max(0,(int)($details['conversation_id']??0));
            if($activeId>0){$owned=$pdo->prepare('SELECT c.id FROM chat_conversations c INNER JOIN chat_messages m ON m.conversation_id=c.id WHERE c.id=? AND c.user_id=? LIMIT 1');$owned->execute([$activeId,$userId]);$found=(int)($owned->fetchColumn()?:0);if($found>0)return $found;}
        }
        $stmt=$pdo->prepare(
            'SELECT c.id
             FROM chat_conversations c
             INNER JOIN chat_messages m ON m.conversation_id=c.id
             WHERE c.user_id=?
             GROUP BY c.id
             ORDER BY MAX(m.id) DESC,c.updated_at DESC,c.id DESC
             LIMIT 1'
        );
        $stmt->execute([$userId]);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function agent_chat_v101_update_conversation(PDO $pdo, int $userId, ?array $user = null): int
{
    $conversationId=agent_chat_v101_latest_conversation_id($pdo,$userId);
    if ($conversationId > 0) return $conversationId;
    $workspaceId=artist_workspace_v181_scope_id($user);
    $stmt=$pdo->prepare('INSERT INTO chat_conversations (user_id,artist_workspace_id,title,created_at,updated_at) VALUES (?,?,?,NOW(),NOW())');
    $stmt->execute([$userId,$workspaceId?:null,'Production updates']);
    return (int)$pdo->lastInsertId();
}

function agent_chat_v101_append_ecosystem_message(array $recipient, string $message, array $context=[]): int
{
    $pdo=db();$userId=(int)($recipient['id']??0);
    if (!$pdo || $userId < 1 || !table_exists('chat_messages')) return 0;
    $conversationId=agent_chat_v101_update_conversation($pdo,$userId,$recipient);
    $contextJson=json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $stmt=$pdo->prepare('INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json,created_at) VALUES (?,NULL,?,?,?,NOW())');
    $stmt->execute([$conversationId,'assistant',$message,is_string($contextJson)?$contextJson:'{}']);
    $messageId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$conversationId,$userId]);
    if (function_exists('agent_brain_archive_and_parse')) {
        agent_brain_archive_and_parse($recipient,$conversationId,$messageId,'assistant',$message,'text');
    }
    return $conversationId;
}

function agent_chat_v101_first_name(array $user): string
{
    $name=trim((string)($user['display_name']??''));
    if ($name==='') return 'there';
    $parts=preg_split('/\s+/u',$name)?:[];
    return mb_substr((string)($parts[0]??$name),0,80);
}

function agent_chat_v101_return_since(array $user): string
{
    $pdo=db();$userId=(int)($user['id']??0);
    if (!$pdo || $userId < 1) return date('Y-m-d H:i:s',time()-86400);
    if (table_exists('agent_activity_events')) {
        try {
            $stmt=$pdo->prepare(
                "SELECT created_at
                 FROM agent_activity_events
                 WHERE user_id=?
                   AND (activity_state='logged_out' OR (activity_state='idle' AND reason<>'pagehide'))
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$userId]);
            $value=(string)($stmt->fetchColumn()?:'');
            if ($value!=='') return $value;
        } catch (Throwable $e) {}
    }
    // A pagehide can mean an ordinary in-app navigation, so it is tracked as
    // activity but must not erase the meaningful away window used by briefings.
    return date('Y-m-d H:i:s',time()-86400);
}

function agent_chat_v118_relationship_depth(array $user): int
{
    $pdo=db();$uid=(int)($user['id']??0);
    if(!$pdo||$uid<1||!table_exists('chat_conversations')||!table_exists('chat_messages'))return 0;
    try{
        $stmt=$pdo->prepare("SELECT COUNT(DISTINCT c.id) FROM chat_conversations c INNER JOIN chat_messages m ON m.conversation_id=c.id WHERE c.user_id=? AND m.role='user'");
        $stmt->execute([$uid]);
        return max(0,(int)$stmt->fetchColumn());
    }catch(Throwable $e){return 0;}
}

function agent_chat_v118_pick(array $choices,string $seed): string
{
    if(!$choices)return '';
    $hash=hash('sha256',$seed);
    $index=hexdec(substr($hash,0,8))%count($choices);
    return (string)$choices[$index];
}

function agent_chat_v118_priority(array $item): int
{
    if(isset($item['priority']))return (int)$item['priority'];
    return match((string)($item['type']??'')){
        'team_message'=>165,
        'message_assignment'=>160,
        'stem_work_update'=>126,
        default=>138,
    };
}

function agent_chat_v101_intro(array $user): array
{
    $pdo=db();$userId=(int)($user['id']??0);$first=agent_chat_v101_first_name($user);$since=agent_chat_v101_return_since($user);$updates=[];
    if ($pdo && $userId > 0 && table_exists('notifications')) {
        try {
            $stmt=$pdo->prepare(
                'SELECT id,type,title,body,target_url,created_at
                 FROM notifications
                 WHERE user_id=? AND created_at>? AND is_read=0
                 ORDER BY id DESC LIMIT 8'
            );
            $stmt->execute([$userId,$since]);
            $updates=$stmt->fetchAll();
        } catch (Throwable $e) {}
    }
    if ($pdo && $userId > 0 && table_exists('agent_edit_events') && table_exists('tracks')) {
        try {
            $broad=(has_permission('tracks.manage',$user)||has_permission('track_notes.manage',$user))?1:0;
            $stmt=$pdo->prepare(
                "SELECT e.id,e.action_key,e.created_at,t.id AS track_id,t.title,u.display_name
                 FROM agent_edit_events e
                 INNER JOIN tracks t ON t.id=e.project_id
                 INNER JOIN users u ON u.id=e.user_id
                 WHERE e.editor_kind='stem' AND e.user_id<>? AND e.created_at>?
                   AND (?=1 OR t.owner_user_id=? OR t.producer_user_id=?)
                 ORDER BY e.id DESC LIMIT 5"
            );
            $stmt->execute([$userId,$since,$broad,$userId,$userId]);
            foreach ($stmt->fetchAll() as $edit) {
                $updates[]=[
                    'id'=>'edit-'.(int)$edit['id'],
                    'type'=>'stem_work_update',
                    'title'=>(string)$edit['title'].' was updated',
                    'body'=>(string)$edit['display_name'].' made a '.str_replace('_',' ',(string)$edit['action_key']).' change in Stem Studio.',
                    'target_url'=>url('/admin/stems.php?track='.(int)$edit['track_id']),
                    'created_at'=>(string)$edit['created_at'],
                ];
            }
        } catch (Throwable $e) {}
    }
    if ($pdo && $userId > 0 && table_exists('team_direct_messages')) {
        try {
            $stmt=$pdo->prepare(
                'SELECT dm.id,dm.message_text,dm.created_at,u.display_name
                 FROM team_direct_messages dm INNER JOIN users u ON u.id=dm.sender_user_id
                 WHERE dm.recipient_user_id=? AND dm.read_at IS NULL AND dm.created_at>?
                 ORDER BY dm.id DESC LIMIT 5'
            );
            $stmt->execute([$userId,$since]);
            foreach($stmt->fetchAll() as $message){$updates[]=['id'=>'team-'.(int)$message['id'],'type'=>'team_message','title'=>'Message from '.(string)$message['display_name'],'body'=>mb_strimwidth((string)$message['message_text'],0,300,'…'),'target_url'=>url('/chat.php'),'created_at'=>(string)$message['created_at']];}
        } catch (Throwable $e) {}
    }
    if ($pdo && $userId > 0 && table_exists('artist_transcript_sessions_v172') && table_exists('artist_transcript_segments_v172')) {
        try {
            $stmt=$pdo->prepare(
                "SELECT s.id,s.title,s.duration_ms,s.stopped_at,
                        (SELECT COUNT(*) FROM artist_transcript_segments_v172 g
                         WHERE g.session_id=s.id AND g.segment_type='transcript' AND TRIM(g.transcript_text)<>'') AS transcript_count
                 FROM artist_transcript_sessions_v172 s
                 WHERE s.created_by_user_id=? AND s.status='draft' AND s.stopped_at>?
                   AND EXISTS (
                       SELECT 1 FROM artist_transcript_segments_v172 g
                       WHERE g.session_id=s.id AND g.segment_type='transcript' AND TRIM(g.transcript_text)<>''
                   )
                 ORDER BY s.stopped_at DESC,s.id DESC LIMIT 4"
            );
            $stmt->execute([$userId,$since]);
            foreach($stmt->fetchAll() as $recording){
                $recordingId=(int)$recording['id'];
                $title=trim((string)$recording['title'])?:('Recording #'.$recordingId);
                $turns=max(1,(int)$recording['transcript_count']);
                $updates[]=[
                    'id'=>'artist-transcription-'.$recordingId,
                    'type'=>'artist_transcription',
                    'title'=>$title.' is ready',
                    'body'=>'Artist Listening captured '.$turns.' transcript turn'.($turns===1?'':'s').'. Open the recording to review or edit it.',
                    'target_url'=>url('/artist-listening.php?session='.$recordingId),
                    'created_at'=>(string)$recording['stopped_at'],
                    'priority'=>124,
                ];
            }
        } catch (Throwable $e) {}
    }

    $missedCount=count($updates);
    $opportunities=function_exists('agent_ecosystem_v118_scan')?agent_ecosystem_v118_scan($user,$since):[];
    $opportunityCount=count($opportunities);
    $meaningfulCount=$missedCount+$opportunityCount;

    // The away duration defines how far Stonefellow scans back. It does not
    // directly make the greeting longer. The actual accumulated work does.
    $combined=array_merge($updates,$opportunities);
    usort($combined,static function(array $a,array $b):int{
        $priority=agent_chat_v118_priority($b)<=>agent_chat_v118_priority($a);
        if($priority!==0)return $priority;
        return strcmp((string)($b['created_at']??''),(string)($a['created_at']??''));
    });
    $combined=array_slice($combined,0,8);

    $hour=(int)date('G');$daypart=$hour<12?'Good morning':($hour<18?'Good afternoon':'Good evening');
    $depth=agent_chat_v118_relationship_depth($user);
    $introduce=$depth<3;
    $seed=$userId.'|'.$since.'|'.$missedCount.'|'.$opportunityCount.'|'.$depth;

    if($introduce){
        $opening=agent_chat_v118_pick([
            $daypart.', '.$first.'. I’m Stonefellow, your personal studio agent.',
            'Hello '.$first.'. I’m Stonefellow, your personal studio agent.',
            $daypart.', '.$first.'. Stonefellow here — your personal studio agent.',
        ],$seed.'|intro');
        $opening.=' I’m here to help you stay organized, keep your projects moving, and work through the to-do list so you can focus on the music.';
    }else{
        $opening=agent_chat_v118_pick([
            $daypart.', '.$first.'.',
            'Welcome back, '.$first.'.',
            'Hey '.$first.'.',
            $first.', good to see you.',
            $daypart.', '.$first.'. I’ve been keeping an eye on things.',
        ],$seed.'|return');
    }

    if($meaningfulCount===0){
        $summary=agent_chat_v118_pick([
            ' You’re all caught up. I don’t see anything that needs your attention right now.',
            ' Everything looks quiet right now. There’s nothing important waiting on you.',
            ' You’re caught up. I’ll keep watching for the next useful thing to move forward.',
            ' Nothing meaningful piled up while you were away. I’ll keep an eye on the studio and release work.',
        ],$seed.'|zero');
    }elseif($meaningfulCount===1){
        if($opportunityCount===1&&$missedCount===0){
            $summary=' I found one useful next move worth looking at.';
        }elseif($missedCount===1&&$opportunityCount===0){
            $summary=' One meaningful thing changed while you were away.';
        }else{
            $summary=' I found one thing worth your attention.';
        }
    }elseif($meaningfulCount<=3){
        $parts=[];
        if($missedCount>0)$parts[]=$missedCount.' update'.($missedCount===1?'':'s');
        if($opportunityCount>0)$parts[]=$opportunityCount.' useful next move'.($opportunityCount===1?'':'s');
        $summary=' I found '.implode(' and ',$parts).' worth your attention.';
    }else{
        $parts=[];
        if($missedCount>0)$parts[]=$missedCount.' update'.($missedCount===1?'':'s');
        if($opportunityCount>0)$parts[]=$opportunityCount.' proactive opportunit'.($opportunityCount===1?'y':'ies');
        $summary=' There’s been some movement while you were away. I found '.implode(' and ',$parts).'. I pulled the highest-value items to the top so we can decide what to move first.';
    }

    $tokenItems=array_map(static function(array $item):array{
        $isOpportunity=(string)($item['type']??'')==='opportunity';
        return [
            (string)($item['type']??''),
            (string)($item['id']??$item['key']??''),
            $isOpportunity?'':(string)($item['created_at']??''),
        ];
    },$combined);
    $itemToken=json_encode($tokenItems,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

    return [
        'first_name'=>$first,
        'greeting'=>$opening.$summary,
        'updates'=>$combined,
        'since'=>$since,
        'missed_count'=>$missedCount,
        'opportunity_count'=>$opportunityCount,
        'relationship_depth'=>$depth,
        'introduced'=>$introduce,
        'token'=>hash('sha256',$userId.'|'.(string)($user['last_login_at']??'').'|'.$since.'|'.(string)$itemToken),
    ];
}

function agent_chat_v101_note_recipients(array $track, array $author): array
{
    $pdo=db();if(!$pdo)return [];$authorId=(int)($author['id']??0);
    $rows=$pdo->query("SELECT id,display_name,email,role,avatar_path,is_active,last_login_at FROM users WHERE is_active=1 ORDER BY id")->fetchAll();$out=[];
    foreach($rows as $row){$id=(int)$row['id'];$canCollaborate=has_permission('track_notes.manage',$row)||can_manage_track_production($track,$row);if(has_permission('chat.access',$row)&&($id===$authorId||$canCollaborate))$out[$id]=$row;}
    return array_values($out);
}

function agent_chat_v101_format_time(float $seconds): string
{
    $seconds=max(0,$seconds);return sprintf('%d:%02d',(int)floor($seconds/60),(int)floor(fmod($seconds,60)));
}
