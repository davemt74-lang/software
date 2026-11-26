<?php
declare(strict_types=1);

/** PDO SQLite before PHP8.4 does not consistently report transactions opened
 * by exec('BEGIN IMMEDIATE'). Track only transactions this coordinator owns.
 * Weak keys keep connection lifetimes independent; native PDO transactions
 * remain caller-owned and continue to use PDO's own transaction state.
 */
function vp3_phone_transaction_state_v1(PDO $pdo):object
{
    static $states;
    $states??=new WeakMap();
    return $states[$pdo]??=(object)['manual'=>false];
}

function vp3_phone_transaction_active_v1(PDO $pdo):bool
{return vp3_phone_transaction_state_v1($pdo)->manual||$pdo->inTransaction();}

/** All phone mutations lock account before device/receipt. Nested helpers join
 * the owner transaction; they neither commit it nor start a deferred SQLite
 * transaction. Preserve BEGIN IMMEDIATE's exclusion of competing writers.
 */
function vp3_phone_transaction_v1(PDO $pdo,callable $work):array
{
    if(vp3_phone_transaction_active_v1($pdo))return $work();
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $state=vp3_phone_transaction_state_v1($pdo);
    // Set ownership only after BEGIN succeeds. Never roll back somebody else's
    // transaction when beginning this one fails.
    if($sqlite){$pdo->exec('BEGIN IMMEDIATE');$state->manual=true;}
    else $pdo->beginTransaction();
    try{
        $result=$work();
        if($sqlite){$pdo->exec('COMMIT');$state->manual=false;}
        else $pdo->commit();
        return $result;
    }catch(Throwable $error){
        if($sqlite){
            try{$pdo->exec('ROLLBACK');$state->manual=false;}
            catch(Throwable){
                // Preserve the original error. An unconfirmed rollback keeps
                // the connection fenced against network I/O and schema writes.
            }
        }elseif($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
}
