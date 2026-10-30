<?php
declare(strict_types=1);

/** PH3B: reviewable, one-shot, owner-approved outgoing SMS. */

function vp3_phone_sms_outbound_ready_v120(): bool
{
    return table_exists('phone_sms_outbox_v120');
}

function vp3_phone_sms_outbound_ensure_schema_v120(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS phone_sms_outbox_v120 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_user_id BIGINT UNSIGNED NOT NULL,
      phone_number_id BIGINT UNSIGNED NOT NULL,
      agent_id BIGINT UNSIGNED NOT NULL,
      recipient_e164 VARCHAR(20) NOT NULL,
      body TEXT NOT NULL,
      consent_source VARCHAR(250) NOT NULL,
      status VARCHAR(24) NOT NULL DEFAULT \'draft\',
      provider VARCHAR(24) NOT NULL DEFAULT \'twilio\',
      provider_message_sid VARCHAR(40) NULL,
      authorized_by_user_id BIGINT UNSIGNED NULL,
      authorized_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_sms_outbox_provider_sid (provider,provider_message_sid),
      KEY idx_sms_outbox_owner_month (owner_user_id,status,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function vp3_phone_sms_live_enabled_v120(): bool
{
    return (string)setting('phone_sms_outbound_live_v120','0')==='1';
}

function vp3_phone_sms_status_url_v120(): string
{
    return trim((string)setting('phone_sms_status_url_v120',''));
}

function vp3_phone_sms_validate_status_url_v120(string $url): string
{
    $url=trim($url);$parts=parse_url($url);
    if (!is_array($parts)||($parts['scheme']??'')!=='https'||empty($parts['host'])
        ||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])
        ||($parts['path']??'')!=='/api/phone-sms-status-v120.php')
        throw new InvalidArgumentException('Use a public HTTPS URL ending /api/phone-sms-status-v120.php.');
    return $url;
}

function vp3_phone_sms_sendable_number_v120(PDO $pdo,int $ownerId,int $numberId): array
{
    $stmt=$pdo->prepare('SELECT id,owner_user_id,agent_id,phone_e164,provider,provider_sid,sms_enabled
      FROM phone_cloud_numbers_v100 WHERE id=? AND owner_user_id=? LIMIT 1');
    $stmt->execute([$numberId,$ownerId]);$number=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$number||$number['provider']!=='twilio'||(int)$number['sms_enabled']!==1
        ||!vp3_phone_agent_owned_v100($pdo,$ownerId,(int)$number['agent_id']))
        throw new RuntimeException('Choose an active, SMS-enabled phone number in your account.');
    $package=vp3_phone_package_v100($pdo,$ownerId);
    if (!$package['sms']) throw new RuntimeException('Your subscription does not include SMS.');
    return $number;
}

function vp3_phone_sms_monthly_quota_v120(PDO $pdo,int $ownerId): array
{
    $stmt=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');$stmt->execute([$ownerId]);$user=$stmt->fetch();
    $included=$user && subscription_has_entitlement($user,'phone.sms.monthly');
    $limit=$included?subscription_entitlement_limit($user,'phone.sms.monthly',0):0;
    // Independent carrier-cost fuse, including accounts whose packages grant
    // unlimited capability. Defaults to 100 until the admin chooses otherwise.
    $platformCap=max(1,min(100000,(int)setting('phone_sms_account_monthly_cap_v120','100')));
    $limit=$limit===null?$platformCap:min((int)$limit,$platformCap);
    $first=gmdate('Y-m-01 00:00:00');
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM phone_sms_outbox_v120
      WHERE owner_user_id=? AND created_at>=? AND status IN (\'sending\',\'sent\',\'delivered\',\'undelivered\',\'review_required\')');
    $stmt->execute([$ownerId,$first]);$used=(int)$stmt->fetchColumn();
    return ['limit'=>$limit,'used'=>$used,'remaining'=>max(0,$limit-$used)];
}

function vp3_phone_sms_draft_v120(PDO $pdo,int $ownerId,int $numberId,string $recipient,
    string $body,string $consentSource): int
{
    vp3_phone_sms_sendable_number_v120($pdo,$ownerId,$numberId);
    $recipient=vp3_phone_e164_v100($recipient);$body=trim($body);$consentSource=trim($consentSource);
    if ($body===''||strlen($body)>3200 || (function_exists('mb_strlen')&&mb_strlen($body,'UTF-8')>800))
        throw new InvalidArgumentException('Text must contain 1–800 characters.');
    if (strlen($consentSource)<8||strlen($consentSource)>250)
        throw new InvalidArgumentException('Record how this recipient consented (8–250 characters).');
    $stmt=$pdo->prepare('INSERT INTO phone_sms_outbox_v120
      (owner_user_id,phone_number_id,agent_id,recipient_e164,body,consent_source)
      SELECT ?,n.id,n.agent_id,?,?,? FROM phone_cloud_numbers_v100 n
      WHERE n.id=? AND n.owner_user_id=? AND n.sms_enabled=1');
    $stmt->execute([$ownerId,$recipient,$body,$consentSource,$numberId,$ownerId]);
    if ($stmt->rowCount()!==1) throw new RuntimeException('Phone number is no longer available.');
    return (int)$pdo->lastInsertId();
}

function vp3_phone_sms_draft_owned_v120(PDO $pdo,int $ownerId,int $draftId): ?array
{
    $stmt=$pdo->prepare('SELECT o.*,n.phone_e164 FROM phone_sms_outbox_v120 o
      INNER JOIN phone_cloud_numbers_v100 n ON n.id=o.phone_number_id AND n.owner_user_id=o.owner_user_id
      WHERE o.id=? AND o.owner_user_id=? LIMIT 1');
    $stmt->execute([$draftId,$ownerId]);return $stmt->fetch(PDO::FETCH_ASSOC)?:null;
}

function vp3_phone_sms_authorize_send_v120(PDO $pdo,int $ownerId,int $draftId,string $confirmation,
    ?callable $carrierSend=null): array
{
    if (!vp3_phone_sms_live_enabled_v120()) throw new RuntimeException('Outbound SMS has not been activated by an administrator.');
    $statusCallback=vp3_phone_sms_validate_status_url_v120(vp3_phone_sms_status_url_v120());
    $pdo->beginTransaction();
    try {
        $lockSql='SELECT id FROM users WHERE id=?';
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite') $lockSql.=' FOR UPDATE';
        $lock=$pdo->prepare($lockSql);$lock->execute([$ownerId]);
        if (!$lock->fetchColumn()) throw new RuntimeException('Account not found.');
        $draft=vp3_phone_sms_draft_owned_v120($pdo,$ownerId,$draftId);
        if (!$draft||$draft['status']!=='draft') throw new RuntimeException('Draft was already sent or is unavailable.');
        $phrase='SEND '.$draft['recipient_e164'];
        if (!hash_equals($phrase,trim($confirmation)))
            throw new RuntimeException('Type '.$phrase.' to authorize this exact text.');
        vp3_phone_sms_sendable_number_v120($pdo,$ownerId,(int)$draft['phone_number_id']);
        $stop=$pdo->prepare('SELECT carrier_opted_out FROM phone_sms_contacts_v110
          WHERE phone_number_id=? AND contact_e164=?');
        $stop->execute([(int)$draft['phone_number_id'],(string)$draft['recipient_e164']]);
        if ((int)$stop->fetchColumn()===1) throw new RuntimeException('This recipient has opted out of texts.');
        $quota=vp3_phone_sms_monthly_quota_v120($pdo,$ownerId);
        if ($quota['remaining']!==null && $quota['remaining']<1)
            throw new RuntimeException('Your monthly SMS package allowance is exhausted.');
        $claim=$pdo->prepare('UPDATE phone_sms_outbox_v120
          SET status=\'sending\',authorized_by_user_id=?,authorized_at=CURRENT_TIMESTAMP
          WHERE id=? AND owner_user_id=? AND status=\'draft\'');
        $claim->execute([$ownerId,$draftId,$ownerId]);
        if ($claim->rowCount()!==1) throw new RuntimeException('The draft was already consumed.');
        $pdo->commit();
    } catch(Throwable $error) {if($pdo->inTransaction())$pdo->rollBack();throw $error;}

    // Never retry an ambiguous provider operation: an HTTP timeout could mean
    // Twilio sent the message. A human must reconcile the provider receipt.
    try {
        if ($carrierSend!==null) {
            $response=$carrierSend((string)$draft['phone_e164'],(string)$draft['recipient_e164'],
                (string)$draft['body'],$statusCallback);
        } else {
            $sid=vp3_phone_twilio_credentials_v100()[0];
            $response=vp3_phone_twilio_http_v100('POST','/2010-04-01/Accounts/'.$sid.'/Messages.json',[
                'From'=>$draft['phone_e164'],'To'=>$draft['recipient_e164'],
                'Body'=>$draft['body'],'StatusCallback'=>$statusCallback]);
        }
    } catch(Throwable $error) {
        $pdo->prepare('UPDATE phone_sms_outbox_v120 SET status=\'review_required\'
          WHERE id=? AND owner_user_id=? AND status=\'sending\'')->execute([$draftId,$ownerId]);
        throw new RuntimeException('Carrier delivery could not be confirmed. Review Twilio before any new send.');
    }
    $sid=(string)($response['sid']??'');
    if (!preg_match('/^SM[a-f0-9]{32}$/iD',$sid)) {
        $pdo->prepare('UPDATE phone_sms_outbox_v120 SET status=\'review_required\' WHERE id=?')
            ->execute([$draftId]);
        throw new RuntimeException('Twilio may have sent the text but returned no valid receipt. Review before retrying.');
    }
    try {
        $pdo->beginTransaction();
        $stmt=$pdo->prepare('SELECT id FROM phone_sms_threads_v110 WHERE phone_number_id=? AND contact_e164=?');
        $stmt->execute([(int)$draft['phone_number_id'],(string)$draft['recipient_e164']]);
        $threadId=(int)$stmt->fetchColumn();
        if (!$threadId) {
            $pdo->prepare('INSERT INTO phone_sms_threads_v110
              (owner_user_id,phone_number_id,agent_id,contact_e164,unread_count)
              VALUES (?,?,?,?,0)')->execute([$ownerId,(int)$draft['phone_number_id'],
                (int)$draft['agent_id'],(string)$draft['recipient_e164']]);
            $threadId=(int)$pdo->lastInsertId();
        }
        $pdo->prepare('INSERT INTO phone_sms_messages_v110
          (thread_id,owner_user_id,provider,provider_message_sid,direction,body,delivery_status)
          VALUES (?,?,\'twilio\',?,\'outbound\',?,\'sent\')')
          ->execute([$threadId,$ownerId,$sid,(string)$draft['body']]);
        $pdo->prepare('UPDATE phone_sms_threads_v110 SET last_message_at=CURRENT_TIMESTAMP WHERE id=?')
          ->execute([$threadId]);
        $pdo->prepare('UPDATE phone_sms_outbox_v120 SET status=\'sent\',provider_message_sid=?
          WHERE id=? AND owner_user_id=? AND status=\'sending\'')->execute([$sid,$draftId,$ownerId]);
        $pdo->commit();
    } catch(Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->prepare('UPDATE phone_sms_outbox_v120 SET status=\'review_required\',provider_message_sid=?
          WHERE id=? AND owner_user_id=? AND status=\'sending\'')->execute([$sid,$draftId,$ownerId]);
        throw new RuntimeException('Twilio accepted the text, but local history needs manual reconciliation. Never resend blindly.');
    }
    return ['sent'=>true,'provider_message_sid'=>$sid,'thread_id'=>$threadId];
}

function vp3_phone_sms_delivery_event_v120(PDO $pdo,array $form,string $signature,string $url,
    string $token,string $accountSid): bool
{
    if (!vp3_phone_sms_verify_twilio_v110($url,$form,$signature,$token))
        throw new RuntimeException('Invalid provider signature.');
    if (!is_string($form['AccountSid']??null)||!hash_equals($accountSid,$form['AccountSid']))
        throw new RuntimeException('Provider account mismatch.');
    $sid=$form['MessageSid']??'';$status=strtolower((string)($form['MessageStatus']??$form['SmsStatus']??''));
    if (!is_string($sid)||!preg_match('/^SM[a-f0-9]{32}$/iD',$sid)) return false;
    if (!in_array($status,['queued','sending','sent','delivered','undelivered','failed'],true)) return false;
    $outbox=$pdo->prepare('SELECT id,owner_user_id,status FROM phone_sms_outbox_v120
      WHERE provider=\'twilio\' AND provider_message_sid=? LIMIT 1');
    $outbox->execute([$sid]);$row=$outbox->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    // Ignore provider callbacks that would regress a terminal delivery state.
    if (in_array($row['status'],['delivered','undelivered','failed'],true)) return true;
    if ($status==='queued'||$status==='sending') return true;
    $pdo->prepare('UPDATE phone_sms_outbox_v120 SET status=? WHERE id=? AND owner_user_id=?')
        ->execute([$status,(int)$row['id'],(int)$row['owner_user_id']]);
    $pdo->prepare('UPDATE phone_sms_messages_v110 SET delivery_status=?
      WHERE provider=\'twilio\' AND provider_message_sid=? AND owner_user_id=?')
      ->execute([$status,$sid,(int)$row['owner_user_id']]);
    return true;
}
