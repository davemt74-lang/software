<?php
declare(strict_types=1);

require_once __DIR__.'/agent-operator-auto-call-schema-v1.php';

/**
 * This release stores owner authorization preferences only. No job admission,
 * dialing, carrier transport, or claim of called-party consent exists here.
 * A future executor must independently enforce the policy and called-party
 * consent before dispatch, recording, or transcription.
 */
function vp3_operator_auto_call_defaults_v1(): array
{
    return ['timezone'=>'UTC','window_start'=>'09:00','window_end'=>'17:00',
        'max_calls_per_day'=>1,'min_gap_minutes'=>1440,'max_duration_seconds'=>600];
}

function vp3_operator_auto_call_settings_v1(array $input): array
{
    $timezone=$input['timezone']??null;
    $start=$input['window_start']??null;$end=$input['window_end']??null;
    $validZone=is_string($timezone)&&strlen($timezone)<=80
        &&in_array($timezone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true);
    if (!$validZone||!is_string($start)||!is_string($end)
        ||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$start)
        ||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$end)
        ||strcmp($start,$end)>=0) {
        vp3_phone_workspace_error_v1('operator_invalid_policy',422,'Choose a valid time zone and same-day calling window.');
    }
    $out=['timezone'=>$timezone,'window_start'=>$start,'window_end'=>$end];
    foreach (['max_calls_per_day'=>[1,3],'min_gap_minutes'=>[60,10080],'max_duration_seconds'=>[60,900]] as $key=>[$min,$max]) {
        $value=$input[$key]??null;
        if (!is_int($value)||$value<$min||$value>$max)
            vp3_phone_workspace_error_v1('operator_invalid_policy',422,'Choose calling limits within the permitted ranges.');
        $out[$key]=$value;
    }
    if ($out['max_duration_seconds']%60!==0)
        vp3_phone_workspace_error_v1('operator_invalid_policy',422,'Choose a whole-minute call duration.');
    return $out;
}

/** Read only the exact saved primary destination; never guess a local number. */
function vp3_operator_auto_call_contact_v1(PDO $pdo,array $scope,int $id,bool $lock=false): array
{
    $q=$pdo->prepare("SELECT id,public_id,phone,status FROM crm_contacts WHERE owner_user_id=? AND id=?"
        .($lock?vp3_phone_workspace_lock_v1($pdo):''));
    $q->execute([$scope['owner_user_id'],$id]);$contact=$q->fetch(PDO::FETCH_ASSOC);
    if (!$contact||$contact['status']==='archived'||(string)$contact['public_id']==='')
        vp3_phone_workspace_error_v1('operator_contact_unavailable',404,'This saved contact is unavailable.');
    $contact['destination_e164']=vp3_phone_caller_contacts_e164_v1((string)$contact['phone']);
    return $contact;
}

function vp3_operator_auto_call_row_v1(PDO $pdo,array $scope,int $contactId,int $agentId,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM phone_operator_contact_policies_v1 WHERE owner_user_id=? AND contact_id=? AND agent_id=?'
        .($lock?vp3_phone_workspace_lock_v1($pdo):''));
    $q->execute([$scope['owner_user_id'],$contactId,$agentId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    return $row?:null;
}

function vp3_operator_auto_call_binding_v1(array $row,array $contact,array $line): bool
{
    return (string)$row['contact_public_id']===(string)$contact['public_id']
        && (string)$row['destination_e164']!=='' && (string)$row['destination_e164']===$contact['destination_e164']
        && (int)$row['number_id']===(int)$line['id'] && (int)$row['agent_id']===(int)$line['agent_id']
        && (string)$row['line_e164']===(string)$line['phone_e164']
        && hash_equals((string)$row['provider_sid_hash'],hash('sha256',(string)$line['provider_sid']));
}

/** Readiness for storing a permission, not for placing a call. No provider calls. */
function vp3_operator_auto_call_prerequisites_v1(PDO $pdo,array $scope,array $line): string
{
    if (!vp3_phone_package_v100($pdo,$scope['owner_user_id'])['voice']||empty($line['voice_enabled']))
        return 'The selected line requires active voice permission.';
    require_once __DIR__.'/phone-browser-v1.php';
    $policy=vp3_phone_browser_policy_v1($pdo,$line);
    if (empty($policy['browser_enabled'])||empty($policy['recording_enabled']))
        return 'An administrator must approve calling and recording for this assigned line.';
    if (!preg_match('/^\+[1-9][0-9]{7,14}$/D',(string)$line['phone_e164'])
        || !preg_match('/^PN[a-f0-9]{32}$/iD',(string)$line['provider_sid']))
        return 'The selected carrier line could not be verified.';
    if (!has_permission('artist_listening.access',$scope['user'])
        || !subscription_has_entitlement($scope['user'],'main_ai.access'))
        return 'Recording and transcription permissions are required.';
    if (!function_exists('vp3_phone_recording_schema_ready_v1')||!vp3_phone_recording_schema_ready_v1($pdo))
        return 'Install the phone recording pipeline before enabling Auto Call.';
    $processing=vp3_phone_recording_policy_v1($pdo,$scope['user'],(int)$line['agent_id']);
    if (empty($processing['enabled'])||empty($processing['retain_audio'])||empty($processing['transcribe']))
        return 'Enable saved recordings and automatic transcription in After calls.';
    return '';
}

function vp3_operator_auto_call_public_v1(?array $row,array $contact,array $line,string $block): array
{
    $saved=$row!==null&&(bool)$row['enabled'];
    $matches=$saved&&vp3_operator_auto_call_binding_v1($row,$contact,$line);
    $destination=(string)$contact['destination_e164'];
    if ($destination===''||$destination===(string)$line['phone_e164'])
        $block='A different, valid international primary contact number is required.';
    $settings=vp3_operator_auto_call_defaults_v1();
    if ($row!==null) {
        foreach ($settings as $key=>$default) $settings[$key]=is_int($default)?(int)$row[$key]:(string)$row[$key];
    }
    return ['contact_id'=>(int)$contact['id'],'agent_id'=>(int)$line['agent_id'],'number_id'=>(int)$line['id'],
        'line_e164'=>(string)$line['phone_e164'],'destination_e164'=>$destination,'revision'=>(int)($row['revision']??0),
        'enabled'=>$saved,'state'=>!$saved?'off':(!$matches?'needs_review':($block!==''?'blocked':'saved')),
        'can_enable'=>$block==='','reason'=>$block,'settings'=>$settings,
        'authorized_line_e164'=>$saved?(string)$row['line_e164']:'',
        'authorized_destination_e164'=>$saved?(string)$row['destination_e164']:'',
        'recording_required'=>true,'transcription_required'=>true,'execution_enabled'=>false];
}

/** Read the visible contact book in two bounded queries. Never creates storage. */
function vp3_operator_auto_call_book_v1(PDO $pdo,array $scope,int $numberId,array $contacts): array
{
    $unavailable=['available'=>false,'execution_enabled'=>false,'policies'=>[]];
    if ($numberId<1||!vp3_operator_auto_call_schema_ready_v1($pdo)
        || (function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)) return $unavailable;
    try {
        $line=vp3_phone_workspace_number_v1($pdo,$scope,$numberId);
        $block=vp3_operator_auto_call_prerequisites_v1($pdo,$scope,$line);
        $ids=array_values(array_unique(array_map('intval',array_column(array_slice($contacts,0,250),'id'))));
        $out=[];
        if ($ids) {
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $q=$pdo->prepare("SELECT id,public_id,phone,status FROM crm_contacts WHERE owner_user_id=? AND status<>'archived' AND id IN ($marks)");
            $q->execute([$scope['owner_user_id'],...$ids]);$owned=$q->fetchAll(PDO::FETCH_ASSOC);
            $q=$pdo->prepare("SELECT * FROM phone_operator_contact_policies_v1 WHERE owner_user_id=? AND agent_id=? AND contact_id IN ($marks)");
            $q->execute([$scope['owner_user_id'],(int)$line['agent_id'],...$ids]);$saved=array_column($q->fetchAll(PDO::FETCH_ASSOC),null,'contact_id');
            foreach ($owned as $contact) {
                if ((string)$contact['public_id']==='') continue;
                $contact['destination_e164']=vp3_phone_caller_contacts_e164_v1((string)$contact['phone']);
                $out[]=vp3_operator_auto_call_public_v1($saved[$contact['id']]??null,$contact,$line,$block);
            }
        }
        return ['available'=>true,'execution_enabled'=>false,'policies'=>$out];
    } catch (Throwable $error) {return $unavailable;}
}

function vp3_operator_auto_call_save_v1(PDO $pdo,array $scope,array $input): array
{
    $allowed=['action','csrf_token','agent_id','conversation_id','contact_id','number_id','expected_revision',
        'enabled','expected_destination','expected_line','settings'];
    if (array_diff(array_keys($input),$allowed)||!is_bool($input['enabled']??null))
        vp3_phone_workspace_error_v1('operator_invalid_policy',422,'Choose a supported contact authorization.');
    if ($scope['conversation_id']<1||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))
        vp3_phone_workspace_error_v1('operator_private_chat_required',403,'Open your saved private Agent Chat.');
    if (!vp3_operator_auto_call_schema_ready_v1($pdo))
        vp3_phone_workspace_error_v1('operator_upgrade_required',503,'Run the Cloud database upgrade to save Auto Call permissions.');
    $contactId=vp3_phone_workspace_id_v1($input['contact_id']??null);
    $numberId=vp3_phone_workspace_id_v1($input['number_id']??null);
    $revision=$input['expected_revision']??null;
    if (!is_int($revision)||$revision<0||$revision>=9007199254740991)
        vp3_phone_workspace_error_v1('operator_invalid_policy',422,'Choose the current permission revision.');
    $enabled=$input['enabled'];
    $settings=$enabled&&is_array($input['settings']??null)&&!array_is_list($input['settings'])
        ?vp3_operator_auto_call_settings_v1($input['settings']):null;
    if ($enabled&&($settings===null||array_diff(array_keys($input['settings']),array_keys($settings))
        ||!is_string($input['expected_destination']??null)||!is_string($input['expected_line']??null)))
        vp3_phone_workspace_error_v1('operator_invalid_policy',422,'Review the exact destination and calling settings.');
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$input,$contactId,$numberId,$revision,$enabled,$settings):array{
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=?'.vp3_phone_workspace_lock_v1($pdo));
        $lock->execute([$scope['owner_user_id']]);if(!$lock->fetchColumn())vp3_phone_workspace_error_v1();
        $scope=vp3_phone_workspace_scope_v1($pdo,$scope['user'],$input);
        $contact=vp3_operator_auto_call_contact_v1($pdo,$scope,$contactId,true);
        // Disabling does not depend on voice or recording settings.
        $line=vp3_phone_workspace_number_v1($pdo,$scope,$numberId,false,true);
        $agentId=(int)$line['agent_id'];
        $row=vp3_operator_auto_call_row_v1($pdo,$scope,$contactId,$agentId,true);
        if ((int)($row['revision']??0)!==$revision)
            vp3_phone_workspace_error_v1('operator_policy_changed',409,'The authorization changed. Reopen its current settings.');
        $block='';
        if ($enabled) {
            $block=vp3_operator_auto_call_prerequisites_v1($pdo,$scope,$line);
            if ($block!=='') vp3_phone_workspace_error_v1('operator_prerequisite',409,$block);
            if ($contact['destination_e164']===''||$contact['destination_e164']===$line['phone_e164']
                ||!hash_equals($contact['destination_e164'],$input['expected_destination'])
                ||!hash_equals((string)$line['phone_e164'],$input['expected_line']))
                vp3_phone_workspace_error_v1('operator_binding_changed',409,'The contact or line changed. Review the exact numbers again.');
        }
        $now=time();$owner=$scope['owner_user_id'];
        if (!$enabled) {
            if ($row&&(bool)$row['enabled']) {
                $q=$pdo->prepare('UPDATE phone_operator_contact_policies_v1 SET enabled=0,revision=revision+1,updated_epoch=? WHERE owner_user_id=? AND contact_id=? AND agent_id=? AND revision=?');
                $q->execute([$now,$owner,$contactId,$agentId,$revision]);
                if ($q->rowCount()!==1)vp3_phone_workspace_error_v1('operator_policy_changed',409,'The authorization changed.');
            }
        } else {
            $values=[(string)$contact['public_id'],$numberId,(string)$line['phone_e164'],hash('sha256',(string)$line['provider_sid']),
                $contact['destination_e164'],...array_values($settings)];
            if ($row===null) {
                $q=$pdo->prepare('INSERT INTO phone_operator_contact_policies_v1(owner_user_id,contact_id,agent_id,contact_public_id,number_id,line_e164,provider_sid_hash,destination_e164,timezone,window_start,window_end,max_calls_per_day,min_gap_minutes,max_duration_seconds,enabled,revision,created_epoch,updated_epoch) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,1,?,?)');
                $q->execute([$owner,$contactId,$agentId,...$values,$now,$now]);
            } else {
                $q=$pdo->prepare('UPDATE phone_operator_contact_policies_v1 SET contact_public_id=?,number_id=?,line_e164=?,provider_sid_hash=?,destination_e164=?,timezone=?,window_start=?,window_end=?,max_calls_per_day=?,min_gap_minutes=?,max_duration_seconds=?,enabled=1,revision=revision+1,updated_epoch=? WHERE owner_user_id=? AND contact_id=? AND agent_id=? AND revision=?');
                $q->execute([...$values,$now,$owner,$contactId,$agentId,$revision]);
                if ($q->rowCount()!==1)vp3_phone_workspace_error_v1('operator_policy_changed',409,'The authorization changed.');
            }
        }
        $fresh=vp3_operator_auto_call_row_v1($pdo,$scope,$contactId,$agentId);
        if (!$enabled) {
            try {$block=vp3_operator_auto_call_prerequisites_v1($pdo,$scope,$line);}
            catch (Throwable $error) {$block='Calling prerequisites could not be verified.';}
        }
        return ['policy'=>vp3_operator_auto_call_public_v1($fresh,$contact,$line,$block)];
    });
}
