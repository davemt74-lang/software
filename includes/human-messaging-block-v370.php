<?php
declare(strict_types=1);

/**
 * Block/unblock is part of the v3.70 human-messaging authorization boundary.
 * It uses the same stable user-row lock as direct-message mutation so a block
 * cannot race a send and leave one last message after the block took effect.
 */
function vp3_human_set_block_v370(PDO $pdo,int $actorId,int $targetId,bool $blocked): void
{
    vp3_human_messaging_v370_ensure_schema($pdo);
    vp3_human_pair_v370($actorId,$targetId);

    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        vp3_human_lock_users_v370($pdo,$actorId,$targetId,true);
        [$low,$high]=vp3_human_pair_v370($actorId,$targetId);

        if($blocked){
            $pdo->prepare('INSERT IGNORE INTO user_blocks (blocker_user_id,blocked_user_id) VALUES (?,?)')
                ->execute([$actorId,$targetId]);
            $pdo->prepare('DELETE FROM user_follows WHERE (follower_user_id=? AND followed_user_id=?) OR (follower_user_id=? AND followed_user_id=?)')
                ->execute([$actorId,$targetId,$targetId,$actorId]);
            $pdo->prepare("UPDATE user_friendships SET status='declined',accepted_at=NULL,updated_at=NOW() WHERE user_low_id=? AND user_high_id=?")
                ->execute([$low,$high]);

            // Resolve any still-pending request between this pair. Conversation and
            // message history remain durable, but the blocked surface is closed.
            $conversation=vp3_human_direct_existing_v370($pdo,$actorId,$targetId,true);
            if($conversation){
                $pdo->prepare("UPDATE human_message_requests SET status='declined',resolved_at=NOW(),updated_at=NOW() WHERE conversation_id=? AND status='pending'")
                    ->execute([(int)$conversation['id']]);
            }
        }else{
            // Unblocking never recreates follows, friendship, or accepted request
            // state. Those relationships must be established explicitly again.
            $pdo->prepare('DELETE FROM user_blocks WHERE blocker_user_id=? AND blocked_user_id=?')
                ->execute([$actorId,$targetId]);
        }

        if($owns)$pdo->commit();
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
