<?php
declare(strict_types=1);

/** Installer-only migration. Source reads and model execution never run DDL. */
function homeserver_transient_schema_ensure_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('The privacy schema upgrade cannot run inside a transaction.',503);
    require_once __DIR__.'/homeserver-https-relay-v1300.php';
    if(!homeserver_https_v1300_transient_ready($pdo)){
        try{$pdo->exec('ALTER TABLE homeserver_https_requests ADD COLUMN transient_context TINYINT NOT NULL DEFAULT 0');}
        catch(PDOException $error){
            // Another administrator may have completed the same additive step.
            if(!homeserver_https_v1300_transient_ready($pdo))throw new RuntimeException('The transient inference schema could not be installed.',503,$error);
        }
    }
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_homeserver_https_transient_expiry ON homeserver_https_requests(transient_context,expires_at,id)');
    }elseif($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $index=$pdo->query("SHOW INDEX FROM homeserver_https_requests WHERE Key_name='idx_homeserver_https_transient_expiry'")->fetch(PDO::FETCH_ASSOC);
        if(!$index){
            try{$pdo->exec('CREATE INDEX idx_homeserver_https_transient_expiry ON homeserver_https_requests(transient_context,expires_at,id)');}
            catch(PDOException $error){
                if(!$pdo->query("SHOW INDEX FROM homeserver_https_requests WHERE Key_name='idx_homeserver_https_transient_expiry'")->fetch(PDO::FETCH_ASSOC))throw $error;
            }
        }
    }else throw new RuntimeException('The privacy schema upgrade is unavailable for this database.',503);
}
