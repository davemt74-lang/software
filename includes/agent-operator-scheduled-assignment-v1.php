<?php
declare(strict_types=1);

function vp3_operator_schedule_assignment_ready_v1(PDO $pdo): bool
{
    try{
        $pdo->query('SELECT owner_user_id,event_id,start_epoch,started_epoch,state,provider_sid,destination_hash FROM phone_operator_carrier_attempts_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,event_id,contact_id,agent_id,number_id,conversation_id,chat_agent_id,event_revision,destination_hash,line_binding,enabled,revision FROM phone_operator_scheduled_assignments_v1 WHERE 1=0');
        $pdo->query('SELECT next_due_epoch FROM phone_operator_scheduled_assignments_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,event_id,start_epoch,end_epoch,assignment_revision,state,reason,observed_epoch,contact_id,conversation_id,chat_agent_id,chat_notice_after FROM phone_operator_occurrences_v1 WHERE 1=0');
        return true;
    }catch(PDOException){return false;}
}

function vp3_operator_schedule_assignment_install_v1(PDO $pdo,string $suffix): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_operator_scheduled_assignments_v1 (
        owner_user_id BIGINT NOT NULL,event_id BIGINT NOT NULL,contact_id BIGINT NOT NULL,
        agent_id BIGINT NOT NULL,number_id BIGINT NOT NULL,conversation_id BIGINT NOT NULL,chat_agent_id BIGINT NOT NULL,
        event_revision CHAR(64) NOT NULL,destination_hash CHAR(64) NOT NULL,line_binding CHAR(64) NOT NULL,
        enabled SMALLINT NOT NULL,revision BIGINT NOT NULL,next_due_epoch BIGINT NOT NULL DEFAULT 0,PRIMARY KEY(owner_user_id,event_id)
    )".$suffix);
    require_once __DIR__.'/schema-metadata.php';
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_operator_carrier_attempts_v1 (
        owner_user_id BIGINT NOT NULL,event_id BIGINT NOT NULL,start_epoch BIGINT NOT NULL,
        started_epoch BIGINT NOT NULL,state VARCHAR(16) NOT NULL,provider_sid VARCHAR(40) NULL UNIQUE,
        PRIMARY KEY(owner_user_id,event_id,start_epoch)
    )".$suffix);
    if(!schema_metadata_column_exists($pdo,'phone_operator_carrier_attempts_v1','destination_hash')){
        try{$pdo->exec("ALTER TABLE phone_operator_carrier_attempts_v1 ADD COLUMN destination_hash CHAR(64) NOT NULL DEFAULT ''");}
        catch(PDOException $e){if(!schema_metadata_column_exists($pdo,'phone_operator_carrier_attempts_v1','destination_hash'))throw $e;}
    }
    foreach([
        'call_public_id'=>'CHAR(32) NULL',
        'recording_sid'=>'VARCHAR(40) NULL',
        'recording_state'=>"VARCHAR(20) NOT NULL DEFAULT 'none'",
        'recording_policy_json'=>'TEXT NULL',
        'recording_started_epoch'=>'BIGINT NOT NULL DEFAULT 0',
    ] as $column=>$definition){
        if(schema_metadata_column_exists($pdo,'phone_operator_carrier_attempts_v1',$column))continue;
        try{$pdo->exec('ALTER TABLE phone_operator_carrier_attempts_v1 ADD COLUMN '.$column.' '.$definition);}
        catch(PDOException $e){if(!schema_metadata_column_exists($pdo,'phone_operator_carrier_attempts_v1',$column))throw $e;}
    }
    if(!schema_metadata_column_exists($pdo,'phone_operator_scheduled_assignments_v1','next_due_epoch')){
        try{$pdo->exec('ALTER TABLE phone_operator_scheduled_assignments_v1 ADD COLUMN next_due_epoch BIGINT NOT NULL DEFAULT 0');}
        catch(PDOException $e){if(!schema_metadata_column_exists($pdo,'phone_operator_scheduled_assignments_v1','next_due_epoch'))throw $e;}
        // Historical assignments remain visible and require a fresh owner review to activate their clock.
        $pdo->exec("UPDATE phone_operator_scheduled_assignments_v1 SET event_revision='',revision=revision+1 WHERE enabled=1");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_operator_occurrences_v1(
        owner_user_id BIGINT NOT NULL,event_id BIGINT NOT NULL,start_epoch BIGINT NOT NULL,end_epoch BIGINT NOT NULL,
        assignment_revision BIGINT NOT NULL,state VARCHAR(16) NOT NULL,reason VARCHAR(48) NOT NULL,observed_epoch BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,event_id,start_epoch)
    )".$suffix);
    // Keep the original private Chat binding after a series is reassigned or cancelled.
    foreach(['contact_id','conversation_id','chat_agent_id'] as $column){
        if(schema_metadata_column_exists($pdo,'phone_operator_occurrences_v1',$column))continue;
        try{$pdo->exec("ALTER TABLE phone_operator_occurrences_v1 ADD COLUMN ".$column." BIGINT NOT NULL DEFAULT 0");}
        catch(PDOException $e){if(!schema_metadata_column_exists($pdo,'phone_operator_occurrences_v1',$column))throw $e;}
    }
    if(!schema_metadata_column_exists($pdo,'phone_operator_occurrences_v1','chat_notice_after')){
        try{$pdo->exec('ALTER TABLE phone_operator_occurrences_v1 ADD COLUMN chat_notice_after BIGINT NOT NULL DEFAULT 0');}
        catch(PDOException $e){if(!schema_metadata_column_exists($pdo,'phone_operator_occurrences_v1','chat_notice_after'))throw $e;}
    }
    // Historical rows can only be attributed when the exact original assignment still exists.
    $match='a.owner_user_id=phone_operator_occurrences_v1.owner_user_id AND a.event_id=phone_operator_occurrences_v1.event_id
        AND a.revision=phone_operator_occurrences_v1.assignment_revision AND a.conversation_id>0';
    $sets=[];
    foreach(['contact_id','conversation_id','chat_agent_id'] as $column){
        $sets[]=$column.'=(SELECT a.'.$column.' FROM phone_operator_scheduled_assignments_v1 a WHERE '.$match.')';
    }
    $pdo->exec('UPDATE phone_operator_occurrences_v1 SET '.implode(',',$sets).' WHERE conversation_id=0 AND EXISTS
        (SELECT 1 FROM phone_operator_scheduled_assignments_v1 a WHERE '.$match.')');
}

/** The user explicitly assigns one Calendar series. Assignment alone cannot place a call. */
function vp3_operator_schedule_assign_v1(PDO $pdo,array $scope,array $input): array
{
    require_once __DIR__.'/phone-contact-schedules-v1.php';
    $scope=vp3_phone_contact_private_v1($pdo,$scope,$input);
    if($scope['conversation_id']<1||!vp3_operator_schedule_assignment_ready_v1($pdo))
        vp3_phone_workspace_error_v1('phone_contact_schedule_unavailable',409,'Open a saved private Chat with the latest phone upgrade.');
    $contact=vp3_phone_workspace_id_v1($input['contact_id']??null);
    $id=vp3_phone_workspace_id_v1($input['event_id']??null);
    $expected=$input['assignment_revision']??null;
    $enabled=$input['enabled']??null;
    if(!is_int($expected)||$expected<0||$expected>=9007199254740991||!is_bool($enabled)||!is_string($input['expected_revision']??null))
        vp3_phone_workspace_error_v1('phone_contact_schedule_invalid',422,'Review the current scheduled call.');
    return section12_owner_transaction($pdo,$scope['owner_user_id'],function()use($pdo,$scope,$input,$contact,$id,$expected,$enabled){
        $event=vp3_phone_contact_schedule_event_v1($pdo,$scope,$contact,$id);
        $person=vp3_phone_contact_row_v1($pdo,$scope,$contact);
        $owner=$scope['owner_user_id'];
        $q=$pdo->prepare('SELECT * FROM phone_operator_scheduled_assignments_v1 WHERE owner_user_id=? AND event_id=?');
        $q->execute([$owner,$id]);$prior=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if($expected!==(int)($prior['revision']??0)||$input['expected_revision']!==section12_revision($event)||$event['status']!=='active')
            vp3_phone_workspace_error_v1('phone_contact_schedule_changed',409,'The schedule changed. Reload it before assigning the call.');
        if(!$enabled){
            if($prior&&$prior['enabled'])$pdo->prepare('UPDATE phone_operator_scheduled_assignments_v1 SET enabled=0,revision=revision+1 WHERE owner_user_id=? AND event_id=?')->execute([$owner,$id]);
            return ['assigned'=>false];
        }
        $line=vp3_phone_workspace_number_v1($pdo,$scope,vp3_phone_workspace_id_v1($input['number_id']??null),true,true);
        $destination=vp3_phone_caller_contacts_e164_v1((string)$person['phone']);
        $agent=(int)$line['agent_id'];
        if(!str_starts_with($event['source_reference'],VP3_OPERATOR_CALENDAR_PREFIX_V1.$contact.':'.$agent.':')||
            $destination===''||$destination===$line['phone_e164']||$destination!==($input['expected_destination']??null)||
            $line['phone_e164']!==($input['expected_line']??null)||trim((string)$event['description'])==='')
            vp3_phone_workspace_error_v1('phone_contact_schedule_invalid',422,'Review the contact, assigned line and call objectives before assigning this schedule.');
        $revision=section12_revision($event);
        $values=[$contact,$agent,(int)$line['id'],$scope['conversation_id'],$scope['agent_id'],$revision,hash('sha256',$destination),hash('sha256',$line['provider_sid'].'|'.$line['phone_e164'])];
        if($prior){
            $pdo->prepare('UPDATE phone_operator_scheduled_assignments_v1 SET contact_id=?,agent_id=?,number_id=?,conversation_id=?,chat_agent_id=?,event_revision=?,destination_hash=?,line_binding=?,enabled=1,revision=revision+1 WHERE owner_user_id=? AND event_id=?')->execute([...$values,$owner,$id]);
        }else{
            $pdo->prepare('INSERT INTO phone_operator_scheduled_assignments_v1(contact_id,agent_id,number_id,conversation_id,chat_agent_id,event_revision,destination_hash,line_binding,owner_user_id,event_id,enabled,revision) VALUES(?,?,?,?,?,?,?,?,?,?,1,1)')->execute([...$values,$owner,$id]);
        }
        require_once __DIR__.'/agent-operator-occurrences-v1.php';
        $next=vp3_operator_next_occurrence_v1($event,time());
        if(!$next)vp3_phone_workspace_error_v1('phone_contact_schedule_invalid',422,'There are no upcoming calls in this schedule.');
        $pdo->prepare('UPDATE phone_operator_scheduled_assignments_v1 SET next_due_epoch=? WHERE owner_user_id=? AND event_id=?')->execute([$next,$owner,$id]);
        return ['assigned'=>true];
    });
}
