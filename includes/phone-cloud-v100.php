<?php
declare(strict_types=1);

/**
 * PH2 Cloud number registry and Twilio management. No telephone traffic flows
 * through this module. The PH1 gateway remains the only carrier webhook edge.
 */

function vp3_phone_schema_ready_v100(?PDO $pdo): bool
{
    return $pdo instanceof PDO && table_exists('phone_cloud_numbers_v100') && table_exists('phone_cloud_requests_v100');
}

function vp3_phone_ensure_schema_v100(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS phone_cloud_numbers_v100 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      provider VARCHAR(24) NOT NULL DEFAULT \'twilio\',
      provider_sid VARCHAR(40) NOT NULL,
      phone_e164 VARCHAR(20) NOT NULL,
      owner_user_id BIGINT UNSIGNED NULL,
      agent_id BIGINT UNSIGNED NULL,
      status VARCHAR(24) NOT NULL DEFAULT \'unconfigured\',
      sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
      voice_enabled TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_phone_provider_sid (provider, provider_sid),
      UNIQUE KEY uq_phone_provider_number (provider, phone_e164),
      KEY idx_phone_owner (owner_user_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS phone_cloud_requests_v100 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_user_id BIGINT UNSIGNED NOT NULL,
      agent_id BIGINT UNSIGNED NOT NULL,
      phone_e164 VARCHAR(20) NOT NULL,
      status VARCHAR(24) NOT NULL DEFAULT \'pending\',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_phone_requests_owner (owner_user_id, status, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function vp3_phone_e164_v100(string $number): string
{
    $number=trim($number);
    if (!preg_match('/^\+[1-9][0-9]{7,14}$/D', $number)) {
        throw new InvalidArgumentException('Choose a valid international phone number.');
    }
    return $number;
}

function vp3_phone_agent_owned_v100(PDO $pdo, int $userId, int $agentId): bool
{
    if ($userId < 1 || $agentId < 1) return false;
    $query=$pdo->prepare('SELECT id FROM user_agents WHERE id=? AND owner_user_id=? AND is_active=1 LIMIT 1');
    $query->execute([$agentId,$userId]);
    return (bool)$query->fetchColumn();
}

function vp3_phone_package_v100(PDO $pdo, int $userId): array
{
    $stmt=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');$stmt->execute([$userId]);
    $user=$stmt->fetch();
    if (!$user) return ['included'=>false,'limit'=>0,'voice'=>false,'sms'=>false];
    $included=subscription_has_entitlement($user,'phone.numbers');
    $limit=$included?subscription_entitlement_limit($user,'phone.numbers',0):0;
    return ['included'=>$included && ($limit===null || $limit>0),
        'limit'=>$limit,'voice'=>$included && subscription_has_entitlement($user,'phone.voice'),
        'sms'=>$included && subscription_has_entitlement($user,'phone.sms')];
}

function vp3_phone_quota_v100(PDO $pdo, int $userId, int $excludeNumberId=0): void
{
    $plan=vp3_phone_package_v100($pdo,$userId);
    if (!$plan['included']) throw new RuntimeException('Your current subscription does not include an agent phone number.');
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM phone_cloud_numbers_v100 WHERE owner_user_id=? AND id<>?');
    $stmt->execute([$userId,$excludeNumberId]);$assigned=(int)$stmt->fetchColumn();
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM phone_cloud_requests_v100 WHERE owner_user_id=? AND status=\'pending\'');
    $stmt->execute([$userId]);$pending=(int)$stmt->fetchColumn();
    if ($plan['limit']!==null && $assigned+$pending >= (int)$plan['limit']) {
        throw new RuntimeException('Your subscription phone number limit has been reached.');
    }
}

function vp3_phone_member_state_v100(PDO $pdo, int $userId): array
{
    if (!vp3_phone_schema_ready_v100($pdo)) return ['ready'=>false,'number'=>null,'request'=>null];
    $query=$pdo->prepare('SELECT n.id,n.phone_e164,n.status,n.sms_enabled,n.voice_enabled,n.agent_id,
       a.display_name AS agent_name FROM phone_cloud_numbers_v100 n
       LEFT JOIN user_agents a ON a.id=n.agent_id AND a.owner_user_id=n.owner_user_id
       WHERE n.owner_user_id=? ORDER BY n.id DESC LIMIT 20');
    $query->execute([$userId]);$numbers=$query->fetchAll()?:[];$number=$numbers[0]??null;
    $query=$pdo->prepare('SELECT r.id,r.phone_e164,r.status,r.agent_id,a.display_name AS agent_name
      FROM phone_cloud_requests_v100 r
      LEFT JOIN user_agents a ON a.id=r.agent_id AND a.owner_user_id=r.owner_user_id
      WHERE r.owner_user_id=? AND r.status=\'pending\' ORDER BY r.id DESC LIMIT 1');
    $query->execute([$userId]);$request=$query->fetch()?:null;
    $plan=vp3_phone_package_v100($pdo,$userId);
    // Do not infer quota from the 20-row display preview.
    $usedQuery=$pdo->prepare('SELECT COUNT(*) FROM phone_cloud_numbers_v100 WHERE owner_user_id=?');
    $usedQuery->execute([$userId]);
    $used=(int)$usedQuery->fetchColumn()+($request?1:0);
    return ['ready'=>true,'number'=>$number,'numbers'=>$numbers,'request'=>$request,
        'package'=>$plan,'can_register'=>$plan['included']&&($plan['limit']===null||$used<(int)$plan['limit'])];
}

function vp3_phone_request_number_v100(PDO $pdo, int $userId, int $agentId, string $number): void
{
    $number=vp3_phone_e164_v100($number);
    if (!vp3_phone_agent_owned_v100($pdo,$userId,$agentId)) {
        throw new RuntimeException('Choose an active agent in your own account.');
    }
    $exists=$pdo->prepare('SELECT id FROM phone_cloud_numbers_v100 WHERE phone_e164=? LIMIT 1');
    $exists->execute([$number]);
    if ($exists->fetchColumn()) throw new RuntimeException('That number is already registered.');
    $pdo->beginTransaction();
    try {
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$userId]);
        // Quota is evaluated while holding the account lock. A prior pending
        // request can be replaced without burning an additional number slot.
        $plan=vp3_phone_package_v100($pdo,$userId);
        if (!$plan['included']) throw new RuntimeException('Your subscription does not include an agent phone number.');
        $count=$pdo->prepare('SELECT COUNT(*) FROM phone_cloud_numbers_v100 WHERE owner_user_id=?');
        $count->execute([$userId]);
        if ($plan['limit']!==null && (int)$count->fetchColumn()>=(int)$plan['limit'])
            throw new RuntimeException('Your subscription phone number limit has been reached.');
        $pdo->prepare('UPDATE phone_cloud_requests_v100 SET status=\'cancelled\' WHERE owner_user_id=? AND status=\'pending\'')->execute([$userId]);
        $pdo->prepare('INSERT INTO phone_cloud_requests_v100 (owner_user_id,agent_id,phone_e164) VALUES (?,?,?)')
            ->execute([$userId,$agentId,$number]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function vp3_phone_twilio_state_v100(): array
{
    $sid=trim((string)setting('phone_twilio_account_sid',''));
    $encrypted=trim((string)setting('phone_twilio_auth_token',''));
    $state=$encrypted!=='' ? ai_encrypted_secret_state($encrypted) : 'empty';
    return ['provider'=>'twilio','configured'=>preg_match('/^AC[a-f0-9]{32}$/iD',$sid)===1 && $state==='ready',
        'account_sid'=>$sid,'credential_state'=>$state,'has_secret'=>$encrypted!=='',
        'gateway_url'=>trim((string)setting('phone_gateway_public_url',''))];
}

function vp3_phone_twilio_credentials_v100(): array
{
    $state=vp3_phone_twilio_state_v100();
    if (!$state['configured']) throw new RuntimeException('Twilio is not connected. Ask a VP3 administrator to configure it.');
    $token=ai_decrypt_secret((string)setting('phone_twilio_auth_token',''));
    if ($token==='') throw new RuntimeException('The saved Twilio credential cannot be read.');
    return [(string)$state['account_sid'],$token];
}

function vp3_phone_twilio_http_v100(string $method, string $resource, array $fields=[]): array
{
    [$sid,$token]=vp3_phone_twilio_credentials_v100();
    if (!preg_match('#^/2010-04-01/Accounts/'.preg_quote($sid,'#').'/[A-Za-z0-9/.?=&_-]+$#D',$resource)
        && $resource !== '/2010-04-01/Accounts/'.$sid.'.json') {
        throw new InvalidArgumentException('Unsupported carrier API path.');
    }
    if (!in_array($method,['GET','POST'],true) || !function_exists('curl_init')) {
        throw new RuntimeException('Carrier API transport is unavailable.');
    }
    $ch=curl_init('https://api.twilio.com'.$resource);
    if ($ch===false) throw new RuntimeException('Could not initialize the carrier connection.');
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERPWD=>$sid.':'.$token,
        CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERAGENT=>'VP3-Phone-Cloud/1.0'];
    if ($method==='POST') {
        $options[CURLOPT_POST]=true;
        $options[CURLOPT_POSTFIELDS]=http_build_query($fields,'','&',PHP_QUERY_RFC3986);
    }
    curl_setopt_array($ch,$options);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if (!is_string($raw) || $status<200 || $status>=300) {
        throw new RuntimeException('Twilio request was not confirmed (HTTP '.($status?:'unavailable').'). Review your account before retrying any purchase.');
    }
    $json=json_decode($raw,true);
    if (!is_array($json)) throw new RuntimeException('Twilio returned an invalid response.');
    return $json;
}

function vp3_phone_twilio_search_v100(string $areaCode): array
{
    $areaCode=trim($areaCode);
    if (!preg_match('/^[2-9][0-9]{2}$/D',$areaCode)) throw new InvalidArgumentException('Enter a three-digit US area code.');
    $resource='/2010-04-01/Accounts/'.vp3_phone_twilio_credentials_v100()[0]
        .'/AvailablePhoneNumbers/US/Local.json?'.http_build_query([
            'AreaCode'=>$areaCode,'SmsEnabled'=>'true','VoiceEnabled'=>'true','PageSize'=>15], '','&',PHP_QUERY_RFC3986);
    $data=vp3_phone_twilio_http_v100('GET',$resource);
    $items=[];
    foreach (array_slice((array)($data['available_phone_numbers']??[]),0,15) as $item) {
        if (!is_array($item) || empty($item['phone_number'])) continue;
        try {$number=vp3_phone_e164_v100((string)$item['phone_number']);}catch(Throwable){continue;}
        $items[]=['number'=>$number,'locality'=>(string)($item['locality']??''),'region'=>(string)($item['region']??''),
            'voice'=>!empty($item['capabilities']['voice']),'sms'=>!empty($item['capabilities']['sms'])];
    }
    return $items;
}

function vp3_phone_twilio_inventory_v100(): array
{
    $sid=vp3_phone_twilio_credentials_v100()[0];
    $data=vp3_phone_twilio_http_v100('GET','/2010-04-01/Accounts/'.$sid.'/IncomingPhoneNumbers.json?PageSize=100');
    $items=[];
    foreach ((array)($data['incoming_phone_numbers']??[]) as $item) {
        if (!is_array($item) || !preg_match('/^PN[a-f0-9]{32}$/iD',(string)($item['sid']??''))) continue;
        try {$number=vp3_phone_e164_v100((string)($item['phone_number']??''));}catch(Throwable){continue;}
        $items[]=['sid'=>(string)$item['sid'],'number'=>$number,'friendly_name'=>(string)($item['friendly_name']??'')];
    }
    return $items;
}

function vp3_phone_import_inventory_v100(PDO $pdo): int
{
    $inventory=vp3_phone_twilio_inventory_v100();
    $stmt=$pdo->prepare('INSERT INTO phone_cloud_numbers_v100 (provider,provider_sid,phone_e164)
      VALUES (\'twilio\',?,?) ON DUPLICATE KEY UPDATE phone_e164=VALUES(phone_e164)');
    foreach ($inventory as $item) $stmt->execute([$item['sid'],$item['number']]);
    return count($inventory);
}

function vp3_phone_assign_v100(PDO $pdo, int $numberId, int $userId, int $agentId): void
{
    if (!vp3_phone_agent_owned_v100($pdo,$userId,$agentId)) {
        throw new RuntimeException('The selected agent does not belong to that user.');
    }
    $pdo->beginTransaction();
    try {
        $lock=$pdo->prepare('SELECT id FROM phone_cloud_numbers_v100 WHERE id=? FOR UPDATE');
        $lock->execute([$numberId]);if (!$lock->fetchColumn()) throw new RuntimeException('Phone number not found.');
        // An administrator may reassign a number, but not bypass a user's plan.
        // The pending request for that same number is excluded from quota.
        $plan=vp3_phone_package_v100($pdo,$userId);
        if (!$plan['included']) throw new RuntimeException('That user’s subscription does not include phone numbers.');
        $count=$pdo->prepare('SELECT COUNT(*) FROM phone_cloud_numbers_v100 WHERE owner_user_id=? AND id<>?');
        $count->execute([$userId,$numberId]);
        if ($plan['limit']!==null && (int)$count->fetchColumn()>=(int)$plan['limit'])
            throw new RuntimeException('That user’s phone number allowance is full.');
        $pdo->prepare('UPDATE phone_cloud_numbers_v100 SET owner_user_id=?, agent_id=?,status=\'unconfigured\',
          sms_enabled=0,voice_enabled=0 WHERE id=?')->execute([$userId,$agentId,$numberId]);
        $pdo->prepare('UPDATE phone_cloud_requests_v100 SET status=\'fulfilled\'
          WHERE owner_user_id=? AND agent_id=? AND phone_e164=(SELECT phone_e164 FROM phone_cloud_numbers_v100 WHERE id=?) AND status=\'pending\'')
          ->execute([$userId,$agentId,$numberId]);
        $pdo->commit();
    } catch(Throwable $error) {if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function vp3_phone_purchase_request_v100(PDO $pdo, int $requestId, string $confirmation): array
{
    $query=$pdo->prepare('SELECT * FROM phone_cloud_requests_v100 WHERE id=? AND status=\'pending\' LIMIT 1');
    $query->execute([$requestId]);$request=$query->fetch();
    if (!$request) throw new RuntimeException('The pending phone request was not found.');
    $number=vp3_phone_e164_v100((string)$request['phone_e164']);
    if (!hash_equals('PURCHASE '.$number,trim($confirmation))) {
        throw new RuntimeException('Type PURCHASE followed by the exact number to authorize this billable Twilio purchase.');
    }
    if (!vp3_phone_agent_owned_v100($pdo,(int)$request['owner_user_id'],(int)$request['agent_id'])) {
        throw new RuntimeException('The requested agent is no longer active for the user.');
    }
    $plan=vp3_phone_package_v100($pdo,(int)$request['owner_user_id']);
    if (!$plan['included']) {
        throw new RuntimeException('That user’s phone subscription entitlement is no longer active.');
    }
    $count=$pdo->prepare('SELECT COUNT(*) FROM phone_cloud_numbers_v100 WHERE owner_user_id=?');
    $count->execute([(int)$request['owner_user_id']]);
    if ($plan['limit']!==null && (int)$count->fetchColumn()>=(int)$plan['limit'])
        throw new RuntimeException('The member’s package number limit is already full.');
    $exists=$pdo->prepare('SELECT id FROM phone_cloud_numbers_v100 WHERE phone_e164=? LIMIT 1');
    $exists->execute([$number]);
    if ($exists->fetchColumn()) throw new RuntimeException('This number is already registered. Import and assign it instead.');
    // Claim the approval once before any paid provider operation. If Twilio
    // times out, flag for manual review, never automatically buy twice.
    $pdo->beginTransaction();
    try {
        $claim=$pdo->prepare('UPDATE phone_cloud_requests_v100 SET status=\'purchasing\' WHERE id=? AND status=\'pending\'');
        $claim->execute([$requestId]);
        if ($claim->rowCount()!==1) throw new RuntimeException('This purchase request was already consumed.');
        $pdo->commit();
    } catch(Throwable $error) {if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    try {
        $sid=vp3_phone_twilio_credentials_v100()[0];
        $result=vp3_phone_twilio_http_v100('POST','/2010-04-01/Accounts/'.$sid.'/IncomingPhoneNumbers.json',[
            'PhoneNumber'=>$number,'FriendlyName'=>'VP3 Agent Phone']);
    } catch(Throwable $error) {
        $pdo->prepare('UPDATE phone_cloud_requests_v100 SET status=\'review_required\' WHERE id=? AND status=\'purchasing\'')->execute([$requestId]);
        throw new RuntimeException('Carrier purchase could not be confirmed. Import Twilio inventory and review before retrying.');
    }
    $providerSid=(string)($result['sid']??'');
    if (!preg_match('/^PN[a-f0-9]{32}$/iD',$providerSid)) {
        $pdo->prepare('UPDATE phone_cloud_requests_v100 SET status=\'review_required\' WHERE id=?')->execute([$requestId]);
        throw new RuntimeException('Twilio may have purchased this number but did not return a valid receipt. Import inventory before any retry.');
    }
    // The new number stays unconfigured until the PH1 phone gateway is deployed
    // and webhooks/consent have been verified. Never claim a live call channel.
    $pdo->prepare('INSERT INTO phone_cloud_numbers_v100
      (provider,provider_sid,phone_e164,owner_user_id,agent_id,status)
      VALUES (\'twilio\',?,?,?,?,\'unconfigured\')')
      ->execute([$providerSid,$number,(int)$request['owner_user_id'],(int)$request['agent_id']]);
    $pdo->prepare('UPDATE phone_cloud_requests_v100 SET status=\'fulfilled\' WHERE id=? AND status=\'purchasing\'')
      ->execute([$requestId]);
    return ['number'=>$number,'sid'=>$providerSid];
}
