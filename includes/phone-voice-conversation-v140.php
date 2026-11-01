<?php
declare(strict_types=1);

/**
 * PH3F: Cloud-hosted, turn-based voice conversations.
 * Carrier callbacks prove the call, never the caller's VP3 account identity.
 * No owner chat, Agent Brain, tools, HomeServer, or private knowledge is exposed.
 */
function vp3_phone_voice_ready_v140(): bool
{
    return table_exists('phone_voice_modes_v140') && table_exists('phone_voice_turns_v140');
}

function vp3_phone_voice_ensure_v140(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_voice_modes_v140 (
      phone_number_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      owner_user_id BIGINT UNSIGNED NOT NULL,
      agent_id BIGINT UNSIGNED NOT NULL,
      mode VARCHAR(24) NOT NULL DEFAULT 'voicemail',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_phone_voice_modes_owner (owner_user_id,phone_number_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_voice_turns_v140 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      call_sid VARCHAR(40) NOT NULL,
      step_number TINYINT UNSIGNED NOT NULL,
      owner_user_id BIGINT UNSIGNED NOT NULL,
      agent_id BIGINT UNSIGNED NOT NULL,
      speech_hash CHAR(64) NOT NULL,
      speech_text TEXT NOT NULL,
      response_text TEXT NULL,
      state VARCHAR(16) NOT NULL DEFAULT 'processing',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_phone_voice_turn_step (call_sid,step_number),
      KEY idx_phone_voice_turn_owner (owner_user_id,call_sid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** An explicit per-number consent survives neither transfer nor agent change. */
function vp3_phone_voice_live_config_v140(PDO $pdo,array $route): bool
{
    if (!vp3_phone_voice_ready_v140()) return false;
    $q=$pdo->prepare("SELECT mode FROM phone_voice_modes_v140
      WHERE phone_number_id=? AND owner_user_id=? AND agent_id=? LIMIT 1");
    $q->execute([(int)$route['phone_number_id'],(int)$route['owner_user_id'],(int)$route['agent_id']]);
    return $q->fetchColumn()==='conversation';
}

function vp3_phone_voice_set_mode_v140(PDO $pdo,int $numberId,bool $enabled): void
{
    if (!vp3_phone_voice_ready_v140()) throw new RuntimeException('Run the PH3F database upgrade first.');
    $q=$pdo->prepare("SELECT owner_user_id,agent_id,voice_enabled FROM phone_cloud_numbers_v100
      WHERE id=? AND provider='twilio' LIMIT 1");
    $q->execute([$numberId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Phone number not found.');
    $owner=(int)$row['owner_user_id'];$agent=(int)$row['agent_id'];
    if ($enabled) {
        if (!(int)$row['voice_enabled']||$owner<1||$agent<1
            ||!vp3_phone_package_v100($pdo,$owner)['voice']
            ||!vp3_phone_agent_owned_v100($pdo,$owner,$agent))
            throw new RuntimeException('Activate inbound voice and an eligible agent before enabling conversation.');
        if (!function_exists('ai_active_provider')||ai_active_provider()==='local'
            ||ai_provider_configuration_failure(ai_active_provider())!==null)
            throw new RuntimeException('Configure an online AI provider before enabling live conversation.');
    }
    // A mode change invalidates all currently active sessions immediately.
    $pdo->prepare("UPDATE phone_voice_calls_v130 SET state='completed'
      WHERE phone_number_id=? AND state IN ('conversation_active','streaming_active')")->execute([$numberId]);
    $pdo->prepare('DELETE FROM phone_voice_modes_v140 WHERE phone_number_id=?')->execute([$numberId]);
    if (table_exists('phone_voice_stream_routes_v150'))
        $pdo->prepare('DELETE FROM phone_voice_stream_routes_v150 WHERE phone_number_id=?')->execute([$numberId]);
    if ($enabled) $pdo->prepare("INSERT INTO phone_voice_modes_v140
      (phone_number_id,owner_user_id,agent_id,mode) VALUES (?,?,?,'conversation')")
      ->execute([$numberId,$owner,$agent]);
}

function vp3_phone_voice_turn_url_v140(string $callback,int $step): string
{
    if ($step<1||$step>8) throw new InvalidArgumentException('Invalid conversation step.');
    return vp3_phone_voice_sibling_url_v130($callback,'phone-voice-turn-v140.php').'?step='.$step;
}

function vp3_phone_voice_say_v140(string $spoken): string
{
    $spoken=trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u',' ', $spoken)??'');
    return '<Say language="en-US">'.htmlspecialchars($spoken,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</Say>';
}

function vp3_phone_voice_gather_v140(string $agentName,string $callback,string $spoken,int $step): string
{
    $action=htmlspecialchars(vp3_phone_voice_turn_url_v140($callback,$step),ENT_XML1|ENT_QUOTES,'UTF-8');
    return '<?xml version="1.0" encoding="UTF-8"?><Response>'
        .'<Gather input="speech" action="'.$action.'" method="POST" timeout="5" speechTimeout="auto" actionOnEmptyResult="true">'
        .vp3_phone_voice_say_v140($spoken).'</Gather><Hangup/></Response>';
}

function vp3_phone_voice_end_v140(string $spoken): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><Response>'.vp3_phone_voice_say_v140($spoken).'<Hangup/></Response>';
}

/** Reuses Cloud inference and token charging, with an isolated phone-only prompt. */
function vp3_phone_voice_ai_v140(PDO $pdo,int $ownerId,int $agentId,string $speech,array $history): array
{
    $q=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');$q->execute([$ownerId]);
    $user=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT display_name FROM user_agents WHERE id=? AND owner_user_id=? AND is_active=1 LIMIT 1');
    $q->execute([$agentId,$ownerId]);$name=$q->fetchColumn();
    if (!$user||!$name) return ['ok'=>false];
    return ai_generate_chat_response($speech,$history,[],$user,'phone_voice',[
        'surface'=>'phone_voice','phone_agent_name'=>(string)$name,'phone_max_output_tokens'=>220,
    ]);
}

function vp3_phone_voice_turn_v140(PDO $pdo,array $form,string $signature,string $callback,
    string $token,string $accountSid,int $step,?callable $generator=null): array
{
    // The Twilio signature includes the *exact* step query string.
    $signedUrl=vp3_phone_voice_turn_url_v140($callback,$step);
    $sid=vp3_phone_voice_check_event_v130($form,$signature,$signedUrl,$token,$accountSid);
    $route=vp3_phone_voice_current_route_v131($pdo,$sid,1800,['conversation_active']);
    if (!$route['accepted']||!vp3_phone_voice_live_config_v140($pdo,$route))
        return ['accepted'=>false,'reason'=>'call_not_authorized'];
    $owner=(int)$route['owner_user_id'];$agent=(int)$route['agent_id'];
    if (isset($form['SpeechResult'])&&!is_string($form['SpeechResult']))
        throw new InvalidArgumentException('Invalid carrier speech text.');
    $speech=trim((string)($form['SpeechResult']??''));
    if (mb_strlen($speech)>1200) $speech=mb_substr($speech,0,1200);
    // Empty speech is a Twilio timeout, not consent to keep charging tokens.
    if ($speech==='') {
        $pdo->prepare("UPDATE phone_voice_calls_v130 SET state='voicemail_fallback'
          WHERE provider='twilio' AND provider_call_sid=? AND state='conversation_active'")->execute([$sid]);
        return ['accepted'=>true,'twiml'=>vp3_phone_voice_twiml_v130('', $callback),'fallback'=>'voicemail'];
    }
    if (preg_match('/^\s*(?:goodbye|bye|hang up|end call|stop call)[.! ]*$/iu',$speech)) {
        $pdo->prepare("UPDATE phone_voice_calls_v130 SET state='completed'
          WHERE provider='twilio' AND provider_call_sid=? AND state='conversation_active'")->execute([$sid]);
        return ['accepted'=>true,'twiml'=>vp3_phone_voice_end_v140('Thanks for calling. Goodbye.')];
    }
    $hash=hash('sha256',$speech);
    $q=$pdo->prepare('SELECT step_number,owner_user_id,agent_id,speech_hash,speech_text,response_text,state
      FROM phone_voice_turns_v140 WHERE call_sid=? ORDER BY step_number DESC LIMIT 1');
    $q->execute([$sid]);$latest=$q->fetch(PDO::FETCH_ASSOC);
    $last=$latest?(int)$latest['step_number']:0;
    if ($step>$last+1||$step<$last)
        return ['accepted'=>false,'reason'=>'step_out_of_sequence'];
    if ($step===$last) {
        if ((int)$latest['owner_user_id']!==$owner||(int)$latest['agent_id']!==$agent
            ||!hash_equals((string)$latest['speech_hash'],$hash))
            return ['accepted'=>false,'reason'=>'turn_replay_conflict'];
        if ((string)$latest['state']!=='ready')
            return ['accepted'=>true,'twiml'=>vp3_phone_voice_end_v140('The assistant is still processing. Please call back.')];
        $reply=(string)$latest['response_text'];
    } else {
        $q=$pdo->prepare("INSERT INTO phone_voice_turns_v140
          (call_sid,step_number,owner_user_id,agent_id,speech_hash,speech_text,state)
          VALUES (?,?,?,?,?,?,'processing')");
        try {$q->execute([$sid,$step,$owner,$agent,$hash,$speech]);}
        catch(PDOException $e) {
            // Concurrent duplicate Twilio delivery must never invoke the model twice.
            return ['accepted'=>true,'twiml'=>vp3_phone_voice_end_v140('The assistant is processing. Please call back.')];
        }
        $prior=$pdo->prepare("SELECT speech_text,response_text FROM phone_voice_turns_v140
          WHERE call_sid=? AND step_number<? AND state='ready'
          ORDER BY step_number DESC LIMIT 4");
        $prior->execute([$sid,$step]);$history=[];
        foreach(array_reverse($prior->fetchAll(PDO::FETCH_ASSOC)) as $item) {
            $history[]=['role'=>'user','message'=>(string)$item['speech_text']];
            $history[]=['role'=>'assistant','message'=>(string)$item['response_text']];
        }
        try {$result=$generator?$generator($speech,$history,$route):vp3_phone_voice_ai_v140($pdo,$owner,$agent,$speech,$history);}
        catch(Throwable $error) {error_log('PH3F phone model failure: '.get_class($error));$result=['ok'=>false];}
        if (empty($result['ok'])||trim((string)($result['answer']??''))==='') {
            $pdo->prepare("UPDATE phone_voice_turns_v140 SET state='failed' WHERE call_sid=? AND step_number=?")
                ->execute([$sid,$step]);
            $pdo->prepare("UPDATE phone_voice_calls_v130 SET state='voicemail_fallback'
              WHERE provider='twilio' AND provider_call_sid=? AND state='conversation_active'")->execute([$sid]);
            return ['accepted'=>true,'twiml'=>vp3_phone_voice_twiml_v130('', $callback),'fallback'=>'model_unavailable'];
        }
        $reply=mb_strimwidth(trim((string)$result['answer']),0,650,'…');
        $pdo->prepare("UPDATE phone_voice_turns_v140 SET response_text=?,state='ready'
          WHERE call_sid=? AND step_number=? AND state='processing'")->execute([$reply,$sid,$step]);
    }
    // Recheck tenant after the network inference: a number can be transferred mid-turn.
    $live=vp3_phone_voice_current_route_v131($pdo,$sid,1800,['conversation_active']);
    if (!$live['accepted']||!vp3_phone_voice_live_config_v140($pdo,$live)
        ||(int)$live['owner_user_id']!==$owner||(int)$live['agent_id']!==$agent)
        return ['accepted'=>false,'reason'=>'call_revoked'];
    if ($step===8) {
        $pdo->prepare("UPDATE phone_voice_calls_v130 SET state='completed'
          WHERE provider='twilio' AND provider_call_sid=? AND state='conversation_active'")->execute([$sid]);
        return ['accepted'=>true,'twiml'=>vp3_phone_voice_end_v140($reply.' Thank you for calling.')];
    }
    return ['accepted'=>true,'twiml'=>vp3_phone_voice_gather_v140('',$callback,$reply,$step+1)];
}
