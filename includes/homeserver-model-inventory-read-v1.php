<?php
declare(strict_types=1);
require_once __DIR__.'/homeserver-model-inventory-v1.php';
require_once __DIR__.'/homeserver-https-relay-v1300.php';

/** Caller supplies the authenticated account ID, never an ID from request data. */
function vp3_homeserver_inventory_lock_v1(PDO $pdo,int $owner): array
{
    if(!$pdo->inTransaction()||$owner<1)throw new RuntimeException('Model inventory authorization is unavailable.',403);
    $q=$pdo->prepare('SELECT id,is_active FROM users WHERE id=? FOR UPDATE');
    $q->execute([$owner]);$user=$q->fetch(PDO::FETCH_ASSOC);
    if(!$user||(int)$user['is_active']!==1)throw new RuntimeException('Model inventory authorization is unavailable.',403);
    $q=$pdo->prepare('SELECT * FROM homeserver_https_sessions WHERE user_id=? LIMIT 1 FOR UPDATE');
    $q->execute([$owner]);$session=$q->fetch(PDO::FETCH_ASSOC);
    if(!$session||!homeserver_https_v1300_transient_session($session,$session))
        throw new RuntimeException('HomeServer is not paired.',409);
    return $session;
}

/**
 * Explicit read, no fallback, no provider execution. Use the existing queue and
 * account/session locks (same order as pair/poll/revoke). Do not publish raw
 * status or accept model inventory from a browser. No new route allowlist.
 */
function vp3_homeserver_inventory_read_v1(int $owner): array
{
    $pdo=db();
    if($owner<1||!$pdo||$pdo->inTransaction())throw new RuntimeException('Model inventory is unavailable.',503);
    $id=null;$session=null;
    try{
        $pdo->beginTransaction();
        $session=vp3_homeserver_inventory_lock_v1($pdo,$owner);
        $id=homeserver_https_v1300_queue($owner,'inference.status',['include_model_inventory'=>true]);
        $pdo->commit();

        $raw=homeserver_https_v1300_wait($id,12000);
        $pdo->beginTransaction();
        $current=vp3_homeserver_inventory_lock_v1($pdo,$owner);
        if(!homeserver_https_v1300_transient_session($session,$current))
            throw new RuntimeException('HomeServer pairing changed.',409);
        $q=$pdo->prepare("SELECT status,response_status FROM homeserver_https_requests WHERE public_id=? AND user_id=? AND device_id=? AND operation='inference.status' LIMIT 1 FOR UPDATE");
        $q->execute([$id,$owner,$session['device_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['status']!=='completed'||(int)$row['response_status']<200||(int)$row['response_status']>=300)
            throw new RuntimeException('Model inventory response is unavailable.',503);
        $view=vp3_homeserver_model_inventory_v1($raw['model_inventory']??null);
        // Delete this exact read receipt before returning private metadata.
        $q=$pdo->prepare("DELETE FROM homeserver_https_requests WHERE public_id=? AND user_id=? AND device_id=? AND operation='inference.status'");
        $q->execute([$id,$owner,$session['device_id']]);
        if($q->rowCount()!==1)throw new RuntimeException('Model inventory cleanup failed.',503);
        $pdo->commit();
        $id=null;
        return $view;
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        $code=in_array($error->getCode(),[403,409],true)?(int)$error->getCode():503;
        // Do not propagate provider responses, SQL or model metadata in errors.
        throw new RuntimeException($code===403?'Model inventory authorization changed.':($code===409?'HomeServer pairing is unavailable or changed.':'Model inventory could not be refreshed.'),$code);
    }finally{
        if($id!==null&&is_array($session)){
            // Best-effort cleanup on timeout/failure; ordinary relay retention
            // still applies if the process dies or the database is unavailable.
            try{
                $q=$pdo->prepare("DELETE FROM homeserver_https_requests WHERE public_id=? AND user_id=? AND device_id=? AND operation='inference.status'");
                $q->execute([$id,$owner,$session['device_id']]);
            }catch(Throwable $ignored){}
        }
    }
}
