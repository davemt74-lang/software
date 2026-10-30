<?php
declare(strict_types=1);

/** PH3A. Provider-neutral inbox storage with a Twilio-signed inbound adapter. */

function vp3_phone_sms_schema_ready_v110(): bool
{
    return table_exists('phone_sms_threads_v110') && table_exists('phone_sms_messages_v110')
        && table_exists('phone_sms_contacts_v110');
}

function vp3_phone_sms_ensure_schema_v110(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS phone_sms_threads_v110 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_user_id BIGINT UNSIGNED NOT NULL,
      phone_number_id BIGINT UNSIGNED NOT NULL,
      agent_id BIGINT UNSIGNED NOT NULL,
      contact_e164 VARCHAR(20) NOT NULL,
      last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      unread_count INT UNSIGNED NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_phone_sms_thread (phone_number_id,contact_e164),
      KEY idx_phone_sms_owner_recent (owner_user_id,last_message_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS phone_sms_messages_v110 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      thread_id BIGINT UNSIGNED NOT NULL,
      owner_user_id BIGINT UNSIGNED NOT NULL,
      provider VARCHAR(24) NOT NULL,
      provider_message_sid VARCHAR(40) NOT NULL,
      direction VARCHAR(12) NOT NULL DEFAULT \'inbound\',
      body TEXT NOT NULL,
      delivery_status VARCHAR(24) NOT NULL DEFAULT \'received\',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_phone_sms_provider_message (provider,provider_message_sid),
      KEY idx_phone_sms_thread_time (thread_id,id),
      KEY idx_phone_sms_owner (owner_user_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS phone_sms_contacts_v110 (
      phone_number_id BIGINT UNSIGNED NOT NULL,
      contact_e164 VARCHAR(20) NOT NULL,
      carrier_opted_out TINYINT(1) NOT NULL DEFAULT 0,
      last_keyword VARCHAR(12) NOT NULL DEFAULT \'\',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (phone_number_id,contact_e164)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function vp3_phone_sms_twilio_signature_v110(string $url,array $params,string $token): string
{
    ksort($params,SORT_STRING);
    $data=$url;
    foreach ($params as $key=>$value) {
        if (!is_string($key) || is_array($value) || is_object($value)) return '';
        $data.=$key.(string)$value;
    }
    return base64_encode(hash_hmac('sha1',$data,$token,true));
}

function vp3_phone_sms_verify_twilio_v110(string $url,array $params,string $signature,string $token): bool
{
    if ($url===''||$token===''||$signature==='') return false;
    $expected=vp3_phone_sms_twilio_signature_v110($url,$params,$token);
    return $expected!=='' && hash_equals($expected,$signature);
}

function vp3_phone_sms_callback_url_v110(): string
{
    return trim((string)setting('phone_sms_webhook_url_v110',''));
}

function vp3_phone_sms_validate_callback_url_v110(string $url): string
{
    $url=trim($url);$parts=parse_url($url);
    if (!is_array($parts)||($parts['scheme']??'')!=='https'||empty($parts['host'])
        ||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])
        ||isset($parts['query'])||($parts['path']??'')!=='/api/phone-sms-inbound-v110.php') {
        throw new InvalidArgumentException('Use the public HTTPS Cloud URL ending /api/phone-sms-inbound-v110.php, with no query or fragment.');
    }
    return $url;
}

function vp3_phone_sms_activate_v110(PDO $pdo,int $numberId): void
{
    $stmt=$pdo->prepare('SELECT * FROM phone_cloud_numbers_v100 WHERE id=? AND provider=\'twilio\' LIMIT 1');
    $stmt->execute([$numberId]);$number=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$number||empty($number['owner_user_id'])||empty($number['agent_id']))
        throw new RuntimeException('Assign the number to an active account agent first.');
    $ownerId=(int)$number['owner_user_id'];$agentId=(int)$number['agent_id'];
    if (!vp3_phone_agent_owned_v100($pdo,$ownerId,$agentId)||!vp3_phone_package_v100($pdo,$ownerId)['sms'])
        throw new RuntimeException('The owner needs an active agent and a package including SMS.');
    $callback=vp3_phone_sms_validate_callback_url_v110(vp3_phone_sms_callback_url_v110());
    $sid=(string)$number['provider_sid'];
    if (!preg_match('/^PN[a-f0-9]{32}$/iD',$sid)) throw new RuntimeException('Invalid carrier number identifier.');
    $accountSid=vp3_phone_twilio_credentials_v100()[0];
    // Idempotent nonbillable number-configuration update. Twilio must confirm
    // it before the local inbox is enabled for this number.
    vp3_phone_twilio_http_v100('POST','/2010-04-01/Accounts/'.$accountSid.'/IncomingPhoneNumbers/'.$sid.'.json',[
        'SmsUrl'=>$callback,'SmsMethod'=>'POST']);
    $pdo->prepare('UPDATE phone_cloud_numbers_v100 SET sms_enabled=1 WHERE id=? AND owner_user_id=?')
        ->execute([$numberId,$ownerId]);
}

function vp3_phone_sms_ingest_v110(PDO $pdo,array $form,string $signature,string $callbackUrl,
    string $token,string $accountSid): array
{
    if (!vp3_phone_sms_verify_twilio_v110($callbackUrl,$form,$signature,$token)) {
        throw new RuntimeException('Invalid provider signature.');
    }
    $receivedSid=$form['AccountSid']??null;
    if (!is_string($receivedSid)||!hash_equals($accountSid,$receivedSid)) {
        throw new RuntimeException('Provider account mismatch.');
    }
    $messageSid=$form['MessageSid']??null;
    if (!is_string($messageSid)||!preg_match('/^SM[a-f0-9]{32}$/iD',$messageSid)) {
        throw new InvalidArgumentException('Unsupported message identifier.');
    }
    $from=$form['From']??null;$to=$form['To']??null;$body=$form['Body']??null;
    if (!is_string($from)||!is_string($to)||!is_string($body)) {
        throw new InvalidArgumentException('Unsupported message payload.');
    }
    $from=vp3_phone_e164_v100($from);$to=vp3_phone_e164_v100($to);
    // PH3A only accepts text; media is not downloaded or sent to an AI.
    if (isset($form['NumMedia']) && (int)$form['NumMedia']>0) {
        return ['accepted'=>false,'reason'=>'media_not_enabled'];
    }
    if (strlen($body)>6400 || (function_exists('mb_strlen')&&mb_strlen($body,'UTF-8')>1600)) {
        return ['accepted'=>false,'reason'=>'message_too_large'];
    }
    $numberStmt=$pdo->prepare('SELECT id,owner_user_id,agent_id,sms_enabled
      FROM phone_cloud_numbers_v100 WHERE provider=\'twilio\' AND phone_e164=? LIMIT 1');
    $numberStmt->execute([$to]);$number=$numberStmt->fetch(PDO::FETCH_ASSOC);
    if (!$number || empty($number['owner_user_id']) || empty($number['agent_id']) || (int)$number['sms_enabled']!==1) {
        return ['accepted'=>false,'reason'=>'number_inactive'];
    }
    $ownerId=(int)$number['owner_user_id'];$agentId=(int)$number['agent_id'];$numberId=(int)$number['id'];
    $package=vp3_phone_package_v100($pdo,$ownerId);
    if (!$package['sms']||!vp3_phone_agent_owned_v100($pdo,$ownerId,$agentId)) {
        return ['accepted'=>false,'reason'=>'account_ineligible'];
    }
    $pdo->beginTransaction();
    try {
        $duplicate=$pdo->prepare('SELECT id FROM phone_sms_messages_v110 WHERE provider=\'twilio\' AND provider_message_sid=?');
        $duplicate->execute([$messageSid]);
        if ($duplicate->fetchColumn()) {
            $pdo->commit();
            return ['accepted'=>true,'duplicate'=>true];
        }
        $thread=$pdo->prepare('SELECT id FROM phone_sms_threads_v110 WHERE phone_number_id=? AND contact_e164=? LIMIT 1');
        $thread->execute([$numberId,$from]);$threadId=(int)$thread->fetchColumn();
        if ($threadId<1) {
            try {
                $pdo->prepare('INSERT INTO phone_sms_threads_v110 (owner_user_id,phone_number_id,agent_id,contact_e164)
                  VALUES (?,?,?,?)')->execute([$ownerId,$numberId,$agentId,$from]);
                $threadId=(int)$pdo->lastInsertId();
            } catch(PDOException $error) {
                if ((string)$error->getCode()!=='23000') throw $error;
                $thread->execute([$numberId,$from]);$threadId=(int)$thread->fetchColumn();
                if ($threadId<1) throw $error;
            }
        }
        $pdo->prepare('INSERT INTO phone_sms_messages_v110
          (thread_id,owner_user_id,provider,provider_message_sid,direction,body,delivery_status)
          VALUES (?,?,\'twilio\',?,\'inbound\',?,\'received\')')
          ->execute([$threadId,$ownerId,$messageSid,$body]);
        $pdo->prepare('UPDATE phone_sms_threads_v110
          SET last_message_at=CURRENT_TIMESTAMP,unread_count=unread_count+1,agent_id=? WHERE id=? AND owner_user_id=?')
          ->execute([$agentId,$threadId,$ownerId]);
        $keyword=strtoupper(trim($body));
        if (in_array($keyword,['STOP','STOPALL','UNSUBSCRIBE','CANCEL','END','QUIT','START','UNSTOP'],true)) {
            $optout=in_array($keyword,['START','UNSTOP'],true)?0:1;
            $updated=$pdo->prepare('UPDATE phone_sms_contacts_v110 SET carrier_opted_out=?,
              last_keyword=?,updated_at=CURRENT_TIMESTAMP WHERE phone_number_id=? AND contact_e164=?');
            $updated->execute([$optout,$keyword,$numberId,$from]);
            if ($updated->rowCount()<1) {
                $exists=$pdo->prepare('SELECT phone_number_id FROM phone_sms_contacts_v110
                  WHERE phone_number_id=? AND contact_e164=?');
                $exists->execute([$numberId,$from]);
                if (!$exists->fetchColumn()) $pdo->prepare('INSERT INTO phone_sms_contacts_v110
                  (phone_number_id,contact_e164,carrier_opted_out,last_keyword) VALUES (?,?,?,?)')
                  ->execute([$numberId,$from,$optout,$keyword]);
            }
        }
        $pdo->commit();
        return ['accepted'=>true,'duplicate'=>false,'owner_user_id'=>$ownerId,'thread_id'=>$threadId];
    } catch(PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((string)$error->getCode()==='23000') return ['accepted'=>true,'duplicate'=>true];
        throw $error;
    } catch(Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function vp3_phone_sms_threads_v110(PDO $pdo,int $ownerId): array
{
    $query=$pdo->prepare('SELECT t.id,t.phone_number_id,t.agent_id,t.contact_e164,t.unread_count,t.last_message_at,
      n.phone_e164,a.display_name AS agent_name
      FROM phone_sms_threads_v110 t
      INNER JOIN phone_cloud_numbers_v100 n ON n.id=t.phone_number_id AND n.owner_user_id=t.owner_user_id
      LEFT JOIN user_agents a ON a.id=t.agent_id AND a.owner_user_id=t.owner_user_id
      WHERE t.owner_user_id=? ORDER BY t.last_message_at DESC,t.id DESC LIMIT 100');
    $query->execute([$ownerId]);return $query->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function vp3_phone_sms_messages_v110(PDO $pdo,int $ownerId,int $threadId): array
{
    $check=$pdo->prepare('SELECT id FROM phone_sms_threads_v110 WHERE id=? AND owner_user_id=?');
    $check->execute([$threadId,$ownerId]);
    if (!$check->fetchColumn()) throw new RuntimeException('Conversation not found.');
    $query=$pdo->prepare('SELECT id,direction,body,delivery_status,created_at
      FROM phone_sms_messages_v110 WHERE thread_id=? AND owner_user_id=? ORDER BY id DESC LIMIT 100');
    $query->execute([$threadId,$ownerId]);return array_reverse($query->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_phone_sms_mark_read_v110(PDO $pdo,int $ownerId,int $threadId): void
{
    $stmt=$pdo->prepare('UPDATE phone_sms_threads_v110 SET unread_count=0 WHERE id=? AND owner_user_id=?');
    $stmt->execute([$threadId,$ownerId]);
    if ($stmt->rowCount()<1) {
        $check=$pdo->prepare('SELECT id FROM phone_sms_threads_v110 WHERE id=? AND owner_user_id=?');
        $check->execute([$threadId,$ownerId]);
        if (!$check->fetchColumn()) throw new RuntimeException('Conversation not found.');
    }
}
