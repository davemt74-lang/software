<?php
declare(strict_types=1);

/** Canonical and mirrored reads commit together, including within a source transaction. */
function notification_read_transaction_v1(PDO $pdo,callable $work): mixed
{
    $joined=$pdo->inTransaction()||(function_exists('vp3_phone_transaction_active_v1')&&vp3_phone_transaction_active_v1($pdo));
    $savepoint='notification_read_'.bin2hex(random_bytes(6));
    if($joined)$pdo->exec('SAVEPOINT '.$savepoint);else $pdo->beginTransaction();
    try{
        $result=$work();
        if($joined)$pdo->exec('RELEASE SAVEPOINT '.$savepoint);else $pdo->commit();
        return $result;
    }catch(Throwable $error){
        if($joined){$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}
        elseif($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
}

/** Exact owner-scoped browser mirrors only; phone receipts and approvals are independent. */
function notification_sync_browser_reads_v1(PDO $pdo,int $owner,int $notificationId=0): void
{
    if(!table_exists('browser_notifications_v2080'))return;
    $sql="UPDATE browser_notifications_v2080 SET is_read=1,read_at=COALESCE(read_at,NOW())
        WHERE user_id=? AND id IN (SELECT source_id FROM notifications
            WHERE user_id=? AND source_type='browser_phase9' AND is_read=1";
    $params=[$owner,$owner];
    if($notificationId>0){$sql.=' AND id=?';$params[]=$notificationId;}
    $pdo->prepare($sql.')')->execute($params);
}
