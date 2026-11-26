<?php
declare(strict_types=1);
require_once __DIR__.'/agent-operator-scheduled-assignment-v1.php';

function vp3_operator_outreach_diagnostics_ready_v1(PDO $pdo):bool
{
    try{$pdo->query('SELECT decision,decision_epoch FROM phone_operator_agendas_v1 WHERE 1=0');return true;}
    catch(PDOException){return false;}
}

function vp3_operator_outreach_last_review_v1(PDO $pdo,array $scope):?array
{
    if(empty($scope['conversation_id'])||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))return null;
    try{
        $q=$pdo->prepare('SELECT decision,decision_epoch FROM phone_operator_agendas_v1 WHERE owner_user_id=? AND conversation_id=?
            AND chat_agent_id=? AND enabled=1 AND decision_epoch>0 AND decision_epoch=checked_epoch ORDER BY decision_epoch DESC,contact_id LIMIT 1');
        $q->execute([$scope['owner_user_id'],$scope['conversation_id'],$scope['agent_id']]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        return $row?vp3_operator_outreach_decision_v1($row['decision'],(int)$row['decision_epoch']):null;
    }catch(PDOException){return null;}
}

/** Only fixed decision codes may enter app diagnostics. */
function vp3_operator_outreach_decision_v1(string $state,int $now):array
{
    $reasons=['prepared','owner_permission','recipient_permission','no_reason','already_planned','contact_changed','route_or_recording_changed',
        'calling_window','call_frequency','phone_busy','calendar_conflict','chat_unavailable','carrier_unavailable','access_changed','changed','error'];
    return ['reason'=>in_array($state,$reasons,true)?$state:'error','at'=>gmdate(DATE_ATOM,$now)];
}

function vp3_operator_outreach_schema_ready_v1(PDO $pdo):bool
{
    try{
        $pdo->query('SELECT owner_user_id,contact_id,conversation_id,chat_agent_id,number_id,destination_hash,topics,questions,review_missed,enabled,revision,checked_epoch FROM phone_operator_agendas_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,contact_id,reason_hash,event_id,agenda_revision,purpose,missed_event_id FROM phone_operator_outreach_uses_v1 WHERE 1=0');
        return true;
    }catch(PDOException){return false;}
}

/** Owner attestation is NOT independent recipient verification or a dial grant. */
function vp3_operator_admission_schema_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT owner_user_id,contact_id,agent_id,revision,enabled,binding_hash,purpose,attested_epoch,expires_epoch FROM phone_operator_consent_reviews_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,destination_hash,withdrawn_epoch FROM phone_operator_recipient_optouts_v1 WHERE 1=0');
        return vp3_operator_schedule_assignment_ready_v1($pdo);
    }catch(PDOException){return false;}
}
function vp3_operator_admission_install_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Install Operator readiness storage outside a transaction.');
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_operator_consent_reviews_v1 (
        owner_user_id BIGINT NOT NULL,contact_id BIGINT NOT NULL,agent_id BIGINT NOT NULL,revision BIGINT NOT NULL,
        enabled SMALLINT NOT NULL DEFAULT 0,binding_hash CHAR(64) NOT NULL,purpose VARCHAR(32) NOT NULL,
        attested_epoch BIGINT NOT NULL,expires_epoch BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,contact_id,agent_id)
    )".$suffix);
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_operator_recipient_optouts_v1 (
        owner_user_id BIGINT NOT NULL,destination_hash CHAR(64) NOT NULL,withdrawn_epoch BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,destination_hash)
    )".$suffix);
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_operator_agendas_v1 (
        owner_user_id BIGINT NOT NULL,contact_id BIGINT NOT NULL,conversation_id BIGINT NOT NULL,chat_agent_id BIGINT NOT NULL,
        number_id BIGINT NOT NULL,destination_hash CHAR(64) NOT NULL,topics TEXT NOT NULL,questions TEXT NOT NULL,review_missed SMALLINT NOT NULL,
        enabled SMALLINT NOT NULL,revision BIGINT NOT NULL,checked_epoch BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,contact_id,conversation_id,chat_agent_id)
    )".$suffix);
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_operator_outreach_uses_v1 (
        owner_user_id BIGINT NOT NULL,contact_id BIGINT NOT NULL,reason_hash CHAR(64) NOT NULL,event_id BIGINT NOT NULL,
        agenda_revision BIGINT NOT NULL,purpose VARCHAR(32) NOT NULL,missed_event_id BIGINT NOT NULL,PRIMARY KEY(owner_user_id,contact_id,reason_hash),UNIQUE(owner_user_id,event_id)
    )".$suffix);
    require_once __DIR__.'/schema-metadata.php';
    foreach(['decision'=>"VARCHAR(32) NOT NULL DEFAULT ''",'decision_epoch'=>'BIGINT NOT NULL DEFAULT 0'] as $column=>$definition){
        if(!schema_metadata_column_exists($pdo,'phone_operator_agendas_v1',$column)){
            try{$pdo->exec('ALTER TABLE phone_operator_agendas_v1 ADD COLUMN '.$column.' '.$definition);}
            catch(PDOException $e){if(!schema_metadata_column_exists($pdo,'phone_operator_agendas_v1',$column))throw $e;}
        }
    }
    vp3_operator_schedule_assignment_install_v1($pdo,$suffix);
    if(!vp3_operator_admission_schema_ready_v1($pdo))throw new RuntimeException('Operator readiness storage could not be installed.');
}
