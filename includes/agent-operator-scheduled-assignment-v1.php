<?php
declare(strict_types=1);

function vp3_operator_schedule_assignment_ready_v1(PDO $pdo): bool
{
    try{
        $pdo->query('SELECT owner_user_id,event_id,contact_id,agent_id,number_id,conversation_id,chat_agent_id,event_revision,destination_hash,line_binding,enabled,revision FROM phone_operator_scheduled_assignments_v1 WHERE 1=0');
        return true;
    }catch(PDOException){return false;}
}

function vp3_operator_schedule_assignment_install_v1(PDO $pdo,string $suffix): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_operator_scheduled_assignments_v1 (
        owner_user_id BIGINT NOT NULL,event_id BIGINT NOT NULL,contact_id BIGINT NOT NULL,
        agent_id BIGINT NOT NULL,number_id BIGINT NOT NULL,conversation_id BIGINT NOT NULL,chat_agent_id BIGINT NOT NULL,
        event_revision CHAR(64) NOT NULL,destination_hash CHAR(64) NOT NULL,line_binding CHAR(64) NOT NULL,
        enabled SMALLINT NOT NULL,revision BIGINT NOT NULL,PRIMARY KEY(owner_user_id,event_id)
    )".$suffix);
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
        return ['assigned'=>true];
    });
}
