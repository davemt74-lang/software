<?php
declare(strict_types=1);

/** Public browser calls retain their own visitor authority beside canonical Phone receipts. */
const VP3_PROFILE_BROWSER_PHONE_V1='vp3.profile-browser-phone.v1';
function vp3_profile_browser_phone_ready_v1(PDO $pdo): bool
{
    try{
        $pdo->query('SELECT public_id,owner_user_id,profile_agent_id,phone_agent_id,phone_number_id,phone_e164,phone_revision,profile_session_id,visitor_user_id,conversation_id,credential_hash,visitor_auth_revision,session_fingerprint,client_request_id,identity,intent_hash,config_fingerprint,state,route_kind,provider_account_sid,provider_call_sid,response_twiml,owner_answered_ms,duration_seconds,created_ms,expires_ms,updated_ms,ended_ms,end_requested_ms,last_reconciled_ms,receipt_message_id FROM profile_browser_phone_calls_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,credential_hash,client_request_id,created_ms FROM profile_browser_phone_cancellations_v1 WHERE 1=0');return true;
    }catch(PDOException){return false;}
}
function vp3_profile_browser_phone_install_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Install Profile browser calls outside a transaction.');
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_browser_phone_calls_v1 (
        public_id CHAR(32) PRIMARY KEY,owner_user_id BIGINT NOT NULL,profile_agent_id BIGINT NOT NULL,phone_agent_id BIGINT NOT NULL,
        phone_number_id BIGINT NOT NULL,phone_e164 VARCHAR(20) NOT NULL,phone_revision BIGINT NOT NULL,
        profile_session_id BIGINT NOT NULL,visitor_user_id BIGINT NOT NULL,conversation_id BIGINT NOT NULL,
        credential_hash CHAR(64) NOT NULL,visitor_auth_revision CHAR(64) NOT NULL DEFAULT '',session_fingerprint CHAR(64) NOT NULL,
        client_request_id CHAR(32) NOT NULL,identity VARCHAR(80) NOT NULL UNIQUE,intent_hash CHAR(64) NOT NULL UNIQUE,config_fingerprint CHAR(64) NOT NULL,
        state VARCHAR(20) NOT NULL DEFAULT 'prepared',route_kind VARCHAR(16) NOT NULL DEFAULT 'pending',provider_account_sid VARCHAR(40) NOT NULL DEFAULT '',provider_call_sid VARCHAR(40) NULL UNIQUE,response_twiml TEXT NOT NULL,
        owner_answered_ms BIGINT NULL,duration_seconds INTEGER NOT NULL DEFAULT 0,created_ms BIGINT NOT NULL,expires_ms BIGINT NOT NULL,
        updated_ms BIGINT NOT NULL,ended_ms BIGINT NULL,end_requested_ms BIGINT NULL,last_reconciled_ms BIGINT NOT NULL DEFAULT 0,receipt_message_id BIGINT NULL,
        UNIQUE(owner_user_id,credential_hash,client_request_id)
    )".$tail);
    $pdo->exec('CREATE TABLE IF NOT EXISTS profile_browser_phone_cancellations_v1(owner_user_id BIGINT NOT NULL,credential_hash CHAR(64) NOT NULL,client_request_id CHAR(32) NOT NULL,created_ms BIGINT NOT NULL,PRIMARY KEY(owner_user_id,credential_hash,client_request_id))'.$tail);
    foreach(['provider_account_sid'=>"VARCHAR(40) NOT NULL DEFAULT ''",'last_reconciled_ms'=>'BIGINT NOT NULL DEFAULT 0'] as $column=>$definition){
        try{$pdo->query('SELECT '.$column.' FROM profile_browser_phone_calls_v1 WHERE 1=0');}catch(PDOException){$pdo->exec('ALTER TABLE profile_browser_phone_calls_v1 ADD COLUMN '.$column.' '.$definition);}
    }
    foreach(['idx_profile_browser_phone_owner_v1'=>'owner_user_id,state,created_ms','idx_profile_browser_phone_conversation_v1'=>'owner_user_id,conversation_id,created_ms'] as $name=>$columns){
        if(!$mysql)$pdo->exec('CREATE INDEX IF NOT EXISTS '.$name.' ON profile_browser_phone_calls_v1('.$columns.')');
        else{$q=$pdo->prepare('SHOW INDEX FROM profile_browser_phone_calls_v1 WHERE Key_name=?');$q->execute([$name]);if(!$q->fetch())$pdo->exec('CREATE INDEX '.$name.' ON profile_browser_phone_calls_v1('.$columns.')');}
    }
    $name='idx_profile_browser_phone_cancel_owner_v1';
    if(!$mysql)$pdo->exec('CREATE INDEX IF NOT EXISTS '.$name.' ON profile_browser_phone_cancellations_v1(owner_user_id,created_ms)');
    else{$q=$pdo->prepare('SHOW INDEX FROM profile_browser_phone_cancellations_v1 WHERE Key_name=?');$q->execute([$name]);if(!$q->fetch())$pdo->exec('CREATE INDEX '.$name.' ON profile_browser_phone_cancellations_v1(owner_user_id,created_ms)');}
}
function vp3_profile_browser_phone_missing_v1(PDO $pdo,PDOException $error): bool
{
    return (string)$error->getCode()==='42S02'||($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'&&str_contains($error->getMessage(),'no such table: profile_browser_phone_calls_v1'));
}
function vp3_profile_browser_phone_find_sid_v1(PDO $pdo,string $sid): ?array
{
    try{$q=$pdo->prepare('SELECT * FROM profile_browser_phone_calls_v1 WHERE provider_call_sid=?');$q->execute([$sid]);return $q->fetch(PDO::FETCH_ASSOC)?:null;}
    catch(PDOException $e){if(vp3_profile_browser_phone_missing_v1($pdo,$e))return null;throw $e;}
}
function vp3_profile_browser_phone_active_v1(PDO $pdo,int $owner,int $cid=0): bool
{
    try{
        $now=(int)floor(microtime(true)*1000);$where=$cid>0?' AND conversation_id=?':'';
        $q=$pdo->prepare("SELECT public_id FROM profile_browser_phone_calls_v1 WHERE owner_user_id=? AND ((state='prepared' AND expires_ms>?) OR state IN ('dialing','ringing','answered'))".$where.' LIMIT 1');
        $q->execute($cid>0?[$owner,$now,$cid]:[$owner,$now]);return(bool)$q->fetchColumn();
    }catch(PDOException $e){if(vp3_profile_browser_phone_missing_v1($pdo,$e))return false;throw $e;}
}

/** Call only after owner/public authorization; historical cleanup needs no publication grant. */
function vp3_profile_browser_phone_reconcile_owner_v1(PDO $pdo,int $owner,?callable $carrier=null): void
{
    if($owner<1||$pdo->inTransaction()||!vp3_profile_browser_phone_ready_v1($pdo))return;
    $before=(int)floor(microtime(true)*1000)-30000;
    $q=$pdo->prepare("SELECT * FROM profile_browser_phone_calls_v1 WHERE owner_user_id=? AND state IN ('dialing','ringing','answered') AND created_ms<=? AND last_reconciled_ms<=? ORDER BY created_ms LIMIT 1");
    $q->execute([$owner,$before,$before]);$call=$q->fetch(PDO::FETCH_ASSOC);if(!$call)return;
    require_once __DIR__.'/profile-browser-phone-v1.php';
    if(vp3_phone_workspace_schema_ready_v1($pdo))vp3_profile_browser_phone_reconcile_v1($pdo,$call,$carrier);
}
