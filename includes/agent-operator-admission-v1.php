<?php
declare(strict_types=1);
require_once __DIR__.'/agent-operator-admission-schema-v1.php';
require_once __DIR__.'/agent-operator-calendar-v1.php';

const VP3_OPERATOR_CONSENT_TTL_V1=604800;
const VP3_OPERATOR_CALL_PURPOSES_V1=[
    'follow_up'=>'Agreed follow-up conversation',
    'appointment'=>'Confirm an existing appointment',
    'requested_check_in'=>'Recipient-requested check-in',
];

/** This release has no callable autonomous executor, not even for passed preflight. */
function vp3_operator_admission_execution_enabled_v1(): bool {return false;}

function vp3_operator_admission_context_v1(PDO $pdo,array $scope,array $input,bool $lock=false): array
{
    if($scope['conversation_id']<1||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))
        vp3_phone_workspace_error_v1('operator_private_chat_required',403,'Open your saved private Chat.');
    if(!vp3_operator_admission_schema_ready_v1($pdo)||!vp3_operator_auto_call_schema_ready_v1($pdo))
        vp3_phone_workspace_error_v1('operator_upgrade_required',503,'Install the Operator readiness upgrade.');
    $contactId=vp3_phone_workspace_id_v1($input['contact_id']??null);
    $lineId=vp3_phone_workspace_id_v1($input['number_id']??null);
    $revision=vp3_phone_workspace_id_v1($input['expected_revision']??null);
    $contact=vp3_operator_auto_call_contact_v1($pdo,$scope,$contactId,$lock);
    $line=vp3_phone_workspace_number_v1($pdo,$scope,$lineId,false,$lock);
    $policy=vp3_operator_auto_call_row_v1($pdo,$scope,$contactId,(int)$line['agent_id'],$lock);
    if(!$policy||(int)$policy['revision']!==$revision)
        vp3_phone_workspace_error_v1('operator_policy_changed',409,'Review the current contact permission first.');
    $destination=$contact['destination_e164'];$destinationValid=preg_match('/^\+[1-9][0-9]{7,14}$/D',$destination)===1&&$destination!==$line['phone_e164'];
    $recording=null;
    if(function_exists('vp3_phone_recording_schema_ready_v1')&&vp3_phone_recording_schema_ready_v1($pdo))
        $recording=vp3_phone_recording_policy_v1($pdo,$scope['user'],(int)$line['agent_id']);
    $binding=hash('sha256',json_encode([
        (int)$scope['owner_user_id'],(int)$contact['id'],(string)$contact['public_id'],(int)$line['agent_id'],
        (int)$line['id'],(string)$line['phone_e164'],(string)$line['provider_sid'],$destination,
        $revision,(int)($recording['revision']??0),
    ],JSON_THROW_ON_ERROR));
    return ['contact'=>$contact,'line'=>$line,'policy'=>$policy,'recording'=>$recording,'binding'=>$binding,
        'destination_valid'=>$destinationValid,'destination_hash'=>hash('sha256',$destination)];
}
function vp3_operator_consent_row_v1(PDO $pdo,array $scope,array $bound,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM phone_operator_consent_reviews_v1 WHERE owner_user_id=? AND contact_id=? AND agent_id=?'.($lock?vp3_phone_workspace_lock_v1($pdo):''));
    $q->execute([$scope['owner_user_id'],(int)$bound['contact']['id'],(int)$bound['line']['agent_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row?:null;
}
function vp3_operator_recipient_opted_out_v1(PDO $pdo,int $owner,string $hash): bool
{
    $q=$pdo->prepare('SELECT 1 FROM phone_operator_recipient_optouts_v1 WHERE owner_user_id=? AND destination_hash=?');
    $q->execute([$owner,$hash]);$found=(bool)$q->fetchColumn();$q->closeCursor();return $found;
}
function vp3_operator_consent_state_v1(?array $row,array $bound,bool $withdrawn,int $now): string
{
    if($withdrawn)return 'withdrawn';
    if(!$row)return 'not_recorded';
    if(!(bool)$row['enabled'])return 'cleared';
    if((int)$row['expires_epoch']<=$now)return 'expired';
    if((int)$row['attested_epoch']>$now||!hash_equals($row['binding_hash'],$bound['binding'])
        ||!isset(VP3_OPERATOR_CALL_PURPOSES_V1[$row['purpose']]))return 'review_required';
    return 'owner_attested';
}
function vp3_operator_admission_policy_block_v1(PDO $pdo,array $scope,array $bound): string
{
    if(!$bound['destination_valid']||!(bool)$bound['policy']['enabled']||!vp3_operator_auto_call_binding_v1($bound['policy'],$bound['contact'],$bound['line']))
        return 'Current contact and assigned line permission required.';
    return vp3_operator_auto_call_prerequisites_v1($pdo,$scope,$bound['line']);
}
/** Local window checks include the entire possible call, not just its start. */
function vp3_operator_admission_window_v1(array $settings,int $now): bool
{
    $zone=new DateTimeZone($settings['timezone']);
    $a=(new DateTimeImmutable('@'.$now))->setTimezone($zone);
    $b=(new DateTimeImmutable('@'.($now+$settings['max_duration_seconds'])))->setTimezone($zone);
    return $a->format('Y-m-d')===$b->format('Y-m-d')&&$a->getOffset()===$b->getOffset()
        &&strcmp($a->format('H:i:s'),$settings['window_start'].':00')>=0
        &&strcmp($b->format('H:i:s'),$settings['window_end'].':00')<=0;
}
function vp3_operator_admission_history_v1(PDO $pdo,array $scope,array $bound,array $settings,int $now,?string $answeredSid=null): array
{
    $local=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone($settings['timezone']));
    $day=$local->setTime(0,0)->getTimestamp();
    $epoch='CASE WHEN intent_consumed_epoch>0 THEN intent_consumed_epoch ELSE created_epoch END';
    // Across ALL owner's Agents and saved-contact aliases, not just selected line.
    // A CASE expression has no column affinity in SQLite. Bind epoch
    // boundaries as integers rather than PDO execute-array strings so the
    // comparison is numeric on both SQLite and MySQL. Future receipts must
    // still block through latest, but do not count as attempts already made.
    $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN ($epoch)>=? AND ($epoch)<=? THEN 1 ELSE 0 END),0) AS today,
        COALESCE(MAX($epoch),0) AS latest FROM phone_browser_calls_v1 WHERE owner_user_id=? AND direction='outbound'
        AND peer_e164=? AND provider_call_sid IS NOT NULL AND provider_call_sid<>'' AND provider_call_sid<>?");
    $q->bindValue(1,$day,PDO::PARAM_INT);
    $q->bindValue(2,$now,PDO::PARAM_INT);
    $q->bindValue(3,(int)$scope['owner_user_id'],PDO::PARAM_INT);
    $q->bindValue(4,$bound['contact']['destination_e164'],PDO::PARAM_STR);
    $q->bindValue(5,$answeredSid??'',PDO::PARAM_STR);
    $q->execute();$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    $latest=(int)$r['latest'];$today=(int)$r['today'];
    // Count the committed reservation even if the carrier response was lost. Older reservations
    // without a destination apply conservatively until their daily cap and spacing have elapsed.
    $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN started_epoch BETWEEN ? AND ? THEN 1 ELSE 0 END),0) AS today,
        COALESCE(MAX(started_epoch),0) AS latest FROM phone_operator_carrier_attempts_v1 a
        WHERE owner_user_id=? AND (destination_hash=? OR destination_hash='')
        AND (provider_sid IS NULL OR provider_sid<>?)
        AND (provider_sid IS NULL OR destination_hash='' OR NOT EXISTS
            (SELECT 1 FROM phone_browser_calls_v1 c WHERE c.provider_call_sid=a.provider_sid))");
    $q->execute([$day,$now,$scope['owner_user_id'],hash('sha256',$bound['contact']['destination_e164']),$answeredSid??'']);
    $scheduled=$q->fetch(PDO::FETCH_ASSOC);
    $today+=(int)$scheduled['today'];
    $latest=max($latest,(int)$scheduled['latest']);
    return ['today'=>$today,'last_attempt_epoch'=>$latest,'within_limit'=>$latest<=$now&&$today<$settings['max_calls_per_day'],
        'gap_ok'=>$latest===0||($latest<=$now&&$now-$latest>=$settings['min_gap_minutes']*60)];
}
function vp3_operator_admission_idle_v1(PDO $pdo,int $owner,int $now,?string $answeredSid=null): bool
{
    $q=$pdo->prepare("SELECT 1 FROM phone_browser_calls_v1 WHERE owner_user_id=? AND
        ((state IN ('dialing','ringing','answered') AND (transport_kind<>'operator_scheduled' OR intent_expires_epoch>?))
        OR (state='prepared' AND intent_expires_epoch>=?)) AND (provider_call_sid IS NULL OR provider_call_sid<>?) LIMIT 1");
    $q->execute([$owner,$now,$now,$answeredSid??'']);$busy=(bool)$q->fetchColumn();$q->closeCursor();if($busy)return false;
    if(table_exists('phone_voice_calls_v130')){
        $q=$pdo->prepare("SELECT 1 FROM phone_voice_calls_v130 WHERE owner_user_id=? AND state IN ('conversation_active','streaming_active') LIMIT 1");
        $q->execute([$owner]);$busy=(bool)$q->fetchColumn();$q->closeCursor();
    }
    if($busy)return false;
    $q=$pdo->prepare("SELECT 1 FROM phone_operator_carrier_attempts_v1 a JOIN phone_operator_occurrences_v1 o
        ON o.owner_user_id=a.owner_user_id AND o.event_id=a.event_id AND o.start_epoch=a.start_epoch
        WHERE a.owner_user_id=? AND o.state IN ('submitting','submitted','uncertain') AND o.end_epoch>?
        AND (a.provider_sid IS NULL OR a.provider_sid<>?) LIMIT 1");
    $q->execute([$owner,$now,$answeredSid??'']);
    return !$q->fetchColumn();
}
/** Read only the holder's choices for the current CRM pairing and exact destination. */
function vp3_operator_holder_modes_v1(PDO $pdo,int $owner,int $contact,string $destination,int $now):array
{
    $none=['scheduled'=>false,'spontaneous'=>false];
    if($destination==='')return $none;
    try{
        $q=$pdo->prepare("SELECT p.agent_call_consent,p.holder_proactive
            FROM crm_contacts c JOIN vp3_phone_devices_v1 d ON d.owner_user_id=c.owner_user_id AND d.id=c.connected_phone_id
            JOIN vp3_phone_communication_profiles_v1 p ON p.owner_user_id=d.owner_user_id AND p.device_id=d.id
            WHERE c.owner_user_id=? AND c.id=? AND c.status<>'archived' AND d.device_status='approved'
            AND d.expires_at>? AND p.phone_e164=? AND p.phone_revision>0 AND p.owner_calls=1 AND p.holder_calls=1");
        $q->execute([$owner,$contact,$now,$destination]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)return $none;
        $modes=(int)$row['agent_call_consent'];
        return ['scheduled'=>(bool)($modes&1),'spontaneous'=>(bool)($modes&2)&&(bool)$row['holder_proactive']];
    }catch(PDOException){return $none;}
}
function vp3_operator_admission_projection_v1(PDO $pdo,array $scope,array $bound,int $now): array
{
    $row=vp3_operator_consent_row_v1($pdo,$scope,$bound);
    $withdrawn=$bound['destination_valid']&&vp3_operator_recipient_opted_out_v1($pdo,$scope['owner_user_id'],$bound['destination_hash']);
    $consent=vp3_operator_consent_state_v1($row,$bound,$withdrawn,$now);
    try{$block=vp3_operator_admission_policy_block_v1($pdo,$scope,$bound);}catch(Throwable){$block='Calling prerequisites could not be verified.';}
    $settings=vp3_operator_auto_call_defaults_v1();
    foreach($settings as $key=>$default)$settings[$key]=is_int($default)?(int)$bound['policy'][$key]:(string)$bound['policy'][$key];
    $gates=[];
    $add=static function(string $id,string $status,string $detail)use(&$gates):void{$gates[]=['id'=>$id,'status'=>$status,'detail'=>$detail];};
    $add('owner_policy',$block===''?'pass':'blocked',$block===''?'Current owner authorization and recording/transcription prerequisites.':$block);
    $add('recipient_review',$consent==='owner_attested'?'pass':'blocked',$consent==='owner_attested'?'Owner attestation is current; independent recipient consent is not verified.':'Current agreement has not been attested, or permission was withdrawn.');
    $history=['today'=>0,'last_attempt_epoch'=>0];$valid=false;
    try{$settings=vp3_operator_auto_call_settings_v1($settings);$valid=true;}catch(VP3PhoneWorkspaceException){}
    $add('calling_window',$valid&&vp3_operator_admission_window_v1($settings,$now)?'pass':'blocked','Check the full permitted call duration in the saved local time zone.');
    try{
        if(!$valid||!$bound['destination_valid'])throw new RuntimeException('Unverified call history.');
        $history=vp3_operator_admission_history_v1($pdo,$scope,$bound,$settings,$now);
        $add('daily_limit',$history['within_limit']?'pass':'blocked','Known browser call attempts to this destination across all assigned Agents today.');
        $add('minimum_gap',$history['gap_ok']?'pass':'blocked','Minimum time since the most recent known outbound attempt.');
    }catch(Throwable){
        $add('daily_limit','unverified','Call history could not be evaluated.');
        $add('minimum_gap','unverified','Call history could not be evaluated.');
    }
    try{$add('phone_idle',vp3_operator_admission_idle_v1($pdo,$scope['owner_user_id'],$now)?'pass':'blocked','Existing unsettled phone calls block a new autonomous attempt.');}
    catch(Throwable){$add('phone_idle','unverified','Existing phone sessions could not be verified.');}
    try{
        $free=$valid&&vp3_operator_calendar_slot_free_v1($now,$settings['max_duration_seconds'],
            vp3_operator_calendar_conflicts_v1($pdo,$scope,$now,$now+$settings['max_duration_seconds']));
        $add('cloud_calendar',$free?'pass':'blocked','Owner Cloud Calendar, recurring events and available booking projection only.');
    }catch(Throwable){$add('cloud_calendar','unverified','Cloud Calendar availability could not be confirmed.');}
    $localPass=!array_filter($gates,static fn(array $gate):bool=>$gate['status']!=='pass');
    $add('recipient_verification','unverified','Independent recipient agreement and applicable calling/recording requirements are not verified by this release.');
    $add('spend_and_dispatch','off','No autonomous carrier transport, paid reservation or call execution exists in this release.');
    return ['contact_id'=>(int)$bound['contact']['id'],'number_id'=>(int)$bound['line']['id'],'policy_revision'=>(int)$bound['policy']['revision'],
        'consent_revision'=>(int)($row['revision']??0),'consent_state'=>$consent,'purpose'=>$row['purpose']??'',
        'attested_at'=>$row?gmdate(DATE_ATOM,(int)$row['attested_epoch']):null,'expires_at'=>$row?gmdate(DATE_ATOM,(int)$row['expires_epoch']):null,
        'destination_e164'=>$bound['contact']['destination_e164'],'line_e164'=>$bound['line']['phone_e164'],
        'can_attest'=>$block===''&&!$withdrawn,'can_clear'=>$row!==null&&(bool)$row['enabled'],'can_withdraw'=>$bound['destination_valid']&&!$withdrawn,
        'holder_modes'=>vp3_operator_holder_modes_v1($pdo,$scope['owner_user_id'],(int)$bound['contact']['id'],$bound['contact']['destination_e164'],$now),
        'known_attempts_today'=>$history['today'],'local_checks_pass'=>$localPass,'checked_at'=>gmdate(DATE_ATOM,$now),
        'execution_enabled'=>vp3_operator_admission_execution_enabled_v1(),'gates'=>$gates];
}
function vp3_operator_admission_preview_v1(PDO $pdo,array $scope,array $input,int $now): array
{
    if(array_diff(array_keys($input),['action','agent_id','conversation_id','contact_id','number_id','expected_revision']))
        vp3_phone_workspace_error_v1('operator_admission_request',422,'Choose a current saved contact permission.');
    return ['readiness'=>vp3_operator_admission_projection_v1($pdo,$scope,vp3_operator_admission_context_v1($pdo,$scope,$input),$now)];
}
/** Explicit owner review or withdrawal; never creates a call/job/calendar event. */
function vp3_operator_consent_save_v1(PDO $pdo,array $scope,array $input,int $now): array
{
    if(array_diff(array_keys($input),['action','csrf_token','agent_id','conversation_id','contact_id','number_id','expected_revision',
        'expected_consent_revision','expected_destination','expected_line','decision','purpose','recipient_agreement_attested'])
        ||!in_array($input['decision']??null,['attest','clear','withdraw'],true)
        ||!is_int($input['expected_consent_revision']??null)||$input['expected_consent_revision']<0
        ||!is_string($input['expected_destination']??null)||!is_string($input['expected_line']??null))
        vp3_phone_workspace_error_v1('operator_admission_request',422,'Choose an explicit consent review decision.');
    $decision=$input['decision'];
    if($decision==='attest'&&(($input['recipient_agreement_attested']??null)!==true
        ||!is_string($input['purpose']??null)||!isset(VP3_OPERATOR_CALL_PURPOSES_V1[$input['purpose']])))
        vp3_phone_workspace_error_v1('operator_recipient_review_required',422,'Confirm affirmative recipient agreement for the chosen purpose.');
    vp3_phone_workspace_atomic_v1($pdo,static function()use($pdo,$scope,$input,$decision,$now):void{
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=?'.vp3_phone_workspace_lock_v1($pdo));
        $lock->execute([$scope['owner_user_id']]);$owner=$lock->fetchColumn();$lock->closeCursor();
        if(!$owner)vp3_phone_workspace_error_v1();
        $scope=vp3_phone_workspace_scope_v1($pdo,$scope['user'],$input);
        $bound=vp3_operator_admission_context_v1($pdo,$scope,$input,true);
        if(!hash_equals($bound['contact']['destination_e164'],$input['expected_destination'])||!hash_equals((string)$bound['line']['phone_e164'],$input['expected_line']))
            vp3_phone_workspace_error_v1('operator_binding_changed',409,'The destination or assigned line changed. Review again.');
        $row=vp3_operator_consent_row_v1($pdo,$scope,$bound,true);$revision=(int)($row['revision']??0);
        if($revision!==$input['expected_consent_revision']||$revision>=9007199254740990)
            vp3_phone_workspace_error_v1('operator_consent_changed',409,'Consent review changed. Refresh before saving.');
        $keys=[$scope['owner_user_id'],(int)$bound['contact']['id'],(int)$bound['line']['agent_id']];
        if($decision==='attest'){
            if(vp3_operator_admission_policy_block_v1($pdo,$scope,$bound)!==''
                ||vp3_operator_recipient_opted_out_v1($pdo,$scope['owner_user_id'],$bound['destination_hash']))
                vp3_phone_workspace_error_v1('operator_recipient_blocked',409,'Current permission or recipient opt-out prevents attestation.');
            if($row){
                $q=$pdo->prepare('UPDATE phone_operator_consent_reviews_v1 SET enabled=1,revision=revision+1,binding_hash=?,purpose=?,attested_epoch=?,expires_epoch=? WHERE owner_user_id=? AND contact_id=? AND agent_id=? AND revision=?');
                $q->execute([$bound['binding'],$input['purpose'],$now,$now+VP3_OPERATOR_CONSENT_TTL_V1,...$keys,$revision]);
                if($q->rowCount()!==1)vp3_phone_workspace_error_v1('operator_consent_changed',409,'Consent review changed.');
            }else $pdo->prepare('INSERT INTO phone_operator_consent_reviews_v1(owner_user_id,contact_id,agent_id,revision,enabled,binding_hash,purpose,attested_epoch,expires_epoch) VALUES(?,?,?,1,1,?,?,?,?)')
                ->execute([...$keys,$bound['binding'],$input['purpose'],$now,$now+VP3_OPERATOR_CONSENT_TTL_V1]);
        }else{
            if($decision==='withdraw'){
                if(!$bound['destination_valid'])vp3_phone_workspace_error_v1('operator_binding_changed',409,'Verify the recipient destination first.');
                if(!vp3_operator_recipient_opted_out_v1($pdo,$scope['owner_user_id'],$bound['destination_hash']))
                    $pdo->prepare('INSERT INTO phone_operator_recipient_optouts_v1(owner_user_id,destination_hash,withdrawn_epoch) VALUES(?,?,?)')
                        ->execute([$scope['owner_user_id'],$bound['destination_hash'],$now]);
            }
            if($row&&(bool)$row['enabled']){
                $q=$pdo->prepare('UPDATE phone_operator_consent_reviews_v1 SET enabled=0,revision=revision+1 WHERE owner_user_id=? AND contact_id=? AND agent_id=? AND revision=?');
                $q->execute([...$keys,$revision]);if($q->rowCount()!==1)vp3_phone_workspace_error_v1('operator_consent_changed',409,'Consent review changed.');
            }
        }
    });
    // Consent removal is durable before any optional readiness projection.
    // A failed projection cannot roll back a recipient withdrawal.
    $fresh=vp3_phone_workspace_scope_v1($pdo,$scope['user'],$input);
    return ['readiness'=>vp3_operator_admission_projection_v1($pdo,$fresh,vp3_operator_admission_context_v1($pdo,$fresh,$input),$now)];
}
