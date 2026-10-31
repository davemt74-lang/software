<?php
declare(strict_types=1);

/** PH3C: provider-neutral call metadata with signed Twilio voice callbacks. */

function vp3_phone_voice_schema_ready_v130(): bool
{
    return table_exists('phone_voice_calls_v130') && table_exists('phone_voice_recordings_v130');
}

function vp3_phone_voice_ensure_schema_v130(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS phone_voice_calls_v130 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      provider VARCHAR(24) NOT NULL,
      provider_call_sid VARCHAR(40) NOT NULL,
      owner_user_id BIGINT UNSIGNED NOT NULL,
      phone_number_id BIGINT UNSIGNED NOT NULL,
      agent_id BIGINT UNSIGNED NOT NULL,
      caller_e164 VARCHAR(20) NOT NULL DEFAULT \'\',
      called_e164 VARCHAR(20) NOT NULL,
      state VARCHAR(24) NOT NULL DEFAULT \'voicemail_offered\',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_phone_voice_provider_call (provider,provider_call_sid),
      KEY idx_phone_voice_owner_recent (owner_user_id,id),
      KEY idx_phone_voice_number (phone_number_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS phone_voice_recordings_v130 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      call_id BIGINT UNSIGNED NOT NULL,
      owner_user_id BIGINT UNSIGNED NOT NULL,
      provider VARCHAR(24) NOT NULL,
      provider_recording_sid VARCHAR(40) NOT NULL,
      recording_status VARCHAR(24) NOT NULL,
      duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_phone_voice_recording (provider,provider_recording_sid),
      KEY idx_phone_voice_recording_call (call_id,id),
      KEY idx_phone_voice_recording_owner (owner_user_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function vp3_phone_voice_callback_url_v130(): string
{
    return trim((string)setting('phone_voice_webhook_url_v130',''));
}

function vp3_phone_voice_validate_url_v130(string $url): string
{
    $url=trim($url);
    $parts=parse_url($url);
    if (!is_array($parts)||($parts['scheme']??'')!=='https'||empty($parts['host'])
        ||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])
        ||isset($parts['query'])||($parts['path']??'')!=='/api/phone-voice-inbound-v130.php') {
        throw new InvalidArgumentException('Use the public HTTPS Cloud URL ending /api/phone-voice-inbound-v130.php with no query or fragment.');
    }
    return $url;
}

function vp3_phone_voice_sibling_url_v130(string $callback,string $name): string
{
    vp3_phone_voice_validate_url_v130($callback);
    if (!in_array($name,['phone-voice-complete-v130.php','phone-voice-recording-v130.php'],true)) {
        throw new InvalidArgumentException('Invalid carrier callback path.');
    }
    return substr($callback,0,-strlen('phone-voice-inbound-v130.php')).$name;
}

function vp3_phone_voice_activate_v130(PDO $pdo,int $numberId): void
{
    $q=$pdo->prepare('SELECT * FROM phone_cloud_numbers_v100 WHERE id=? AND provider=\'twilio\' LIMIT 1');
    $q->execute([$numberId]);$number=$q->fetch(PDO::FETCH_ASSOC);
    if (!$number||empty($number['owner_user_id'])||empty($number['agent_id']))
        throw new RuntimeException('Assign the number to a member and active agent first.');
    $ownerId=(int)$number['owner_user_id'];$agentId=(int)$number['agent_id'];
    $plan=vp3_phone_package_v100($pdo,$ownerId);
    if (!$plan['voice']||!vp3_phone_agent_owned_v100($pdo,$ownerId,$agentId)) {
        throw new RuntimeException('This member needs an active agent and a package including voice calls.');
    }
    $url=vp3_phone_voice_validate_url_v130(vp3_phone_voice_callback_url_v130());
    $sid=(string)$number['provider_sid'];
    if (!preg_match('/^PN[a-f0-9]{32}$/iD',$sid)) throw new RuntimeException('Invalid carrier number identifier.');
    $accountSid=vp3_phone_twilio_credentials_v100()[0];
    // Only mark locally enabled after the non-billable carrier configuration succeeds.
    vp3_phone_twilio_http_v100('POST','/2010-04-01/Accounts/'.$accountSid.'/IncomingPhoneNumbers/'.$sid.'.json',[
        'VoiceUrl'=>$url,'VoiceMethod'=>'POST']);
    $pdo->prepare('UPDATE phone_cloud_numbers_v100 SET voice_enabled=1 WHERE id=? AND owner_user_id=?')
        ->execute([$numberId,$ownerId]);
}

function vp3_phone_voice_twiml_v130(string $agentName,string $callback): string
{
    $name=trim(preg_replace('/[^\p{L}\p{N} ._-]/u','', $agentName)??'');
    $name=substr($name,0,60);
    $intro=$name!=='' ? 'You have reached the voicemail for '.$name.'. ' : 'You have reached this voicemail service. ';
    $message=$intro.'This is an automated answering service. Your message will be recorded. Please leave a message after the tone.';
    $message=htmlspecialchars($message,ENT_XML1|ENT_QUOTES,'UTF-8');
    $action=htmlspecialchars(vp3_phone_voice_sibling_url_v130($callback,'phone-voice-complete-v130.php'),ENT_XML1|ENT_QUOTES,'UTF-8');
    $status=htmlspecialchars(vp3_phone_voice_sibling_url_v130($callback,'phone-voice-recording-v130.php'),ENT_XML1|ENT_QUOTES,'UTF-8');
    return '<?xml version="1.0" encoding="UTF-8"?><Response><Say language="en-US">'.$message.'</Say>'
      .'<Record playBeep="true" maxLength="120" trim="do-not-trim" action="'.$action.'" method="POST" '
      .'recordingStatusCallback="'.$status.'" recordingStatusCallbackMethod="POST" recordingStatusCallbackEvent="completed" /></Response>';
}

function vp3_phone_voice_check_event_v130(array $form,string $signature,string $callback,
    string $token,string $accountSid): string
{
    if (!vp3_phone_sms_verify_twilio_v110($callback,$form,$signature,$token))
        throw new RuntimeException('Invalid provider signature.');
    $received=$form['AccountSid']??null;
    if (!is_string($received)||!hash_equals($accountSid,$received))
        throw new RuntimeException('Provider account mismatch.');
    $sid=$form['CallSid']??null;
    if (!is_string($sid)||!preg_match('/^CA[a-f0-9]{32}$/iD',$sid))
        throw new InvalidArgumentException('Unsupported call identifier.');
    return $sid;
}

function vp3_phone_voice_incoming_v130(PDO $pdo,array $form,string $signature,
    string $callback,string $token,string $accountSid): array
{
    $callSid=vp3_phone_voice_check_event_v130($form,$signature,$callback,$token,$accountSid);
    $to=$form['To']??null;$from=$form['From']??null;
    if (!is_string($to)||!is_string($from))
        throw new InvalidArgumentException('Unsupported call payload.');
    $to=vp3_phone_e164_v100($to);
    $caller='';
    try {$caller=vp3_phone_e164_v100($from);} catch(InvalidArgumentException) { /* private caller */ }
    if (isset($form['Direction']) && $form['Direction']!=='inbound')
        throw new InvalidArgumentException('Unsupported call direction.');
    $q=$pdo->prepare('SELECT id,owner_user_id,agent_id,voice_enabled FROM phone_cloud_numbers_v100
      WHERE provider=\'twilio\' AND phone_e164=? LIMIT 1');
    $q->execute([$to]);$number=$q->fetch(PDO::FETCH_ASSOC);
    if (!$number||!(int)$number['voice_enabled']||!(int)$number['owner_user_id']||!(int)$number['agent_id'])
        return ['accepted'=>false,'reason'=>'number_inactive'];
    $owner=(int)$number['owner_user_id'];$agent=(int)$number['agent_id'];
    if (!vp3_phone_package_v100($pdo,$owner)['voice']||!vp3_phone_agent_owned_v100($pdo,$owner,$agent))
        return ['accepted'=>false,'reason'=>'account_ineligible'];
    $q=$pdo->prepare('SELECT display_name FROM user_agents WHERE id=? AND owner_user_id=? AND is_active=1 LIMIT 1');
    $q->execute([$agent,$owner]);$agentName=(string)($q->fetchColumn()?:'');
    $pdo->beginTransaction();
    try {
        $exists=$pdo->prepare('SELECT id,owner_user_id,phone_number_id,caller_e164,called_e164
          FROM phone_voice_calls_v130 WHERE provider=\'twilio\' AND provider_call_sid=? LIMIT 1');
        $exists->execute([$callSid]);$row=$exists->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ((int)$row['owner_user_id']!==$owner||(int)$row['phone_number_id']!==(int)$number['id']
                ||(string)$row['called_e164']!==$to||(string)$row['caller_e164']!==$caller)
                throw new RuntimeException('Call receipt conflicts with number assignment.');
        } else {
            $pdo->prepare('INSERT INTO phone_voice_calls_v130
              (provider,provider_call_sid,owner_user_id,phone_number_id,agent_id,caller_e164,called_e164)
              VALUES (\'twilio\',?,?,?,?,?,?)')
              ->execute([$callSid,$owner,(int)$number['id'],$agent,$caller,$to]);
        }
        $pdo->commit();
    } catch(Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return ['accepted'=>true,'twiml'=>vp3_phone_voice_twiml_v130($agentName,$callback)];
}

function vp3_phone_voice_recording_event_v130(PDO $pdo,array $form,string $signature,
    string $callback,string $token,string $accountSid): array
{
    $sid=vp3_phone_voice_check_event_v130($form,$signature,$callback,$token,$accountSid);
    // RecordingStatusCallback carries AccountSid/CallSid/RecordingSid/status,
    // not the original To/From Voice request fields. The immutable CallSid
    // receipt binds this provider event to its original member and number.
    $recordingSid=$form['RecordingSid']??null;
    $status=$form['RecordingStatus']??null;
    if (!is_string($recordingSid)||!preg_match('/^RE[a-f0-9]{32}$/iD',$recordingSid)
        ||!is_string($status)||!in_array($status,['completed','absent','failed','processing'],true)) {
        throw new InvalidArgumentException('Unsupported recording event.');
    }
    $duration=(string)($form['RecordingDuration']??'0');
    if (!preg_match('/^[0-9]{1,5}$/D',$duration)) throw new InvalidArgumentException('Invalid recording duration.');
    $seconds=min((int)$duration,3600);
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT id,owner_user_id,caller_e164,called_e164 FROM phone_voice_calls_v130
          WHERE provider=\'twilio\' AND provider_call_sid=? LIMIT 1');
        $q->execute([$sid]);$call=$q->fetch(PDO::FETCH_ASSOC);
        if (!$call) { $pdo->commit(); return ['accepted'=>false,'reason'=>'unknown_call']; }
        $q=$pdo->prepare('SELECT id,call_id,owner_user_id,recording_status FROM phone_voice_recordings_v130
          WHERE provider=\'twilio\' AND provider_recording_sid=? LIMIT 1');
        $q->execute([$recordingSid]);$existing=$q->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ((int)$existing['call_id']!==(int)$call['id']||(int)$existing['owner_user_id']!==(int)$call['owner_user_id'])
                throw new RuntimeException('Recording receipt conflicts with original call.');
            // Final states are monotonic: delayed callbacks cannot resurrect a recording.
            if (in_array((string)$existing['recording_status'],['completed','absent','failed'],true)) {
                $pdo->commit();return ['accepted'=>true,'duplicate'=>true];
            }
            $pdo->prepare('UPDATE phone_voice_recordings_v130 SET recording_status=?,duration_seconds=? WHERE id=?')
                ->execute([$status,$seconds,(int)$existing['id']]);
        } else {
            $pdo->prepare('INSERT INTO phone_voice_recordings_v130
              (call_id,owner_user_id,provider,provider_recording_sid,recording_status,duration_seconds)
              VALUES (?,?,\'twilio\',?,?,?)')
              ->execute([(int)$call['id'],(int)$call['owner_user_id'],$recordingSid,$status,$seconds]);
        }
        if ($status==='completed')
            $pdo->prepare('UPDATE phone_voice_calls_v130 SET state=\'voicemail_ready\' WHERE id=?')
                ->execute([(int)$call['id']]);
        elseif (in_array($status,['absent','failed'],true))
            $pdo->prepare('UPDATE phone_voice_calls_v130 SET state=\'completed\' WHERE id=? AND state<>\'voicemail_ready\'')
                ->execute([(int)$call['id']]);
        $pdo->commit();
        return ['accepted'=>true,'duplicate'=>false];
    } catch(Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function vp3_phone_voice_calls_v130(PDO $pdo,int $ownerId): array
{
    $q=$pdo->prepare('SELECT c.id,c.caller_e164,c.called_e164,c.created_at,c.state,
      a.display_name AS agent_name,r.id AS voicemail_id,r.duration_seconds
      FROM phone_voice_calls_v130 c
      LEFT JOIN user_agents a ON a.id=c.agent_id AND a.owner_user_id=c.owner_user_id
      LEFT JOIN phone_voice_recordings_v130 r ON r.call_id=c.id AND r.recording_status=\'completed\'
      WHERE c.owner_user_id=? ORDER BY c.id DESC LIMIT 100');
    $q->execute([$ownerId]);
    return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
}

