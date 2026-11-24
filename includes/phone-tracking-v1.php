<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';
require_once __DIR__.'/extension-device-auth-v2000.php';
require_once __DIR__.'/schema-metadata.php';
require_once __DIR__.'/phone-tracking-history-v1.php';
require_once __DIR__.'/phone-tracking-places-v1.php';
require_once __DIR__.'/phone-tracking-arrivals-v1.php';
require_once __DIR__.'/phone-camera-v1.php';
require_once __DIR__.'/phone-communication-profile-v1.php';
require_once __DIR__.'/phone-information-exchange-v1.php';
require_once __DIR__.'/phone-remote-dialer-v1.php';
require_once __DIR__.'/phone-managed-ui-v1.php';
require_once __DIR__.'/phone-handoff-v1.php';
require_once __DIR__.'/phone-push-subscriptions-v1.php';
require_once __DIR__.'/phone-push-delivery-v1.php';
require_once __DIR__.'/phone-push-events-v1.php';

const VP3_PHONE_INVITE_SECONDS_V1 = 300;
const VP3_PHONE_FRESH_SECONDS_V1 = 60;
const VP3_PHONE_IDLE_DAYS_V1 = 30;
const VP3_PHONE_MAX_DAYS_V1 = 90;

/** Explicit installation only. Live requests do not create or alter tables. */
function vp3_phone_schema_v1(PDO $pdo): void
{
    if (vp3_phone_transaction_active_v1($pdo)) throw new LogicException('Install Phone Tracking outside a transaction.');
    $options = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    // The same defaults apply to new and already-paired phones.
    $preferences = [
        'update_interval_seconds' => 'SMALLINT NOT NULL DEFAULT 5',
        'accuracy_mode' => "VARCHAR(12) NOT NULL DEFAULT 'high'",
        'preferences_version' => 'BIGINT NOT NULL DEFAULT 1',
        'history_state' => "VARCHAR(12) NOT NULL DEFAULT 'off'",
        'history_retention_days' => 'SMALLINT NOT NULL DEFAULT 0',
        'history_version' => 'BIGINT NOT NULL DEFAULT 1',
        'history_accepted_at' => 'BIGINT NULL',
        'holder_can_save_places' => 'SMALLINT NOT NULL DEFAULT 0',
        'holder_place_version' => 'BIGINT NOT NULL DEFAULT 1',
        'places_generation' => 'BIGINT NULL',
        'place_permission_version' => 'BIGINT NOT NULL DEFAULT 1',
    ];
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_invites_v1 (
        invite_hash CHAR(64) NOT NULL PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL,
        expires_at BIGINT NOT NULL, claimed_device_id CHAR(32) NULL,
        UNIQUE (owner_user_id, invite_hash),
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$options);
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_devices_v1 (
        id CHAR(32) NOT NULL PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE, device_name VARCHAR(48) NOT NULL,
        device_role VARCHAR(12) NOT NULL, device_status VARCHAR(12) NOT NULL,
        allow_location SMALLINT NOT NULL DEFAULT 0, generation BIGINT NOT NULL,
        location_state VARCHAR(12) NOT NULL, latitude DOUBLE NULL, longitude DOUBLE NULL,
        accuracy DOUBLE NULL, fix_at BIGINT NULL, first_fix_at BIGINT NULL,
        started_at BIGINT NULL, created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL,
        update_interval_seconds {$preferences['update_interval_seconds']}, accuracy_mode {$preferences['accuracy_mode']},
        preferences_version {$preferences['preferences_version']},
        history_state {$preferences['history_state']}, history_retention_days {$preferences['history_retention_days']},
        history_version {$preferences['history_version']}, history_accepted_at {$preferences['history_accepted_at']},
        holder_can_save_places {$preferences['holder_can_save_places']}, holder_place_version {$preferences['holder_place_version']},
        places_generation {$preferences['places_generation']}, place_permission_version {$preferences['place_permission_version']},
        last_seen_at BIGINT NOT NULL, UNIQUE (owner_user_id, id),
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$options);
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_requests_v1 (
        owner_user_id INT UNSIGNED NOT NULL, request_hash CHAR(64) NOT NULL,
        signature CHAR(64) NOT NULL, device_id CHAR(32) NULL, expires_at BIGINT NOT NULL,
        created_at BIGINT NOT NULL, PRIMARY KEY (owner_user_id, request_hash),
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$options);
    foreach ($preferences as $column => $definition) {
        if (schema_metadata_column_exists($pdo,'vp3_phone_devices_v1',$column)) continue;
        try {
            $pdo->exec("ALTER TABLE vp3_phone_devices_v1 ADD COLUMN $column $definition");
        } catch (PDOException $error) {
            // Another installer may have added it after the metadata check.
            $native = (int)($error->errorInfo[1] ?? 0);
            $duplicate = $native === 1060
                || ($native === 1 && str_contains(strtolower($error->getMessage()),'duplicate column name:'));
            if (!$duplicate || !schema_metadata_column_exists($pdo,'vp3_phone_devices_v1',$column)) throw $error;
        }
    }
    vp3_phone_history_schema_v1($pdo,$options);
    vp3_phone_places_schema_v1($pdo);
    vp3_phone_arrival_schema_v1($pdo,$options);
    vp3_phone_camera_schema_v1($pdo,$options);
    vp3_phone_communication_install_v1($pdo,$options);
    vp3_phone_information_install_v1($pdo,$options);
    vp3_phone_file_install_v1($pdo,$options);
    vp3_phone_dialer_install_v1($pdo,$options);
    vp3_phone_handoff_install_v1($pdo,$options);
    vp3_phone_push_install_v1($pdo,$options);
    vp3_phone_push_delivery_install_v1($pdo,$options);
}

function vp3_phone_schema_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT i.invite_hash,i.claimed_device_id,d.token_hash,d.started_at,d.first_fix_at,
            d.update_interval_seconds,d.accuracy_mode,d.preferences_version,
            d.holder_can_save_places,d.holder_place_version,d.history_state,d.history_retention_days,d.history_version,d.history_accepted_at,d.places_generation,d.place_permission_version,
            r.request_hash,r.signature,h.owner_user_id,h.device_id,h.fix_at,h.expires_at,h.history_version,h.generation,
            h.latitude,h.longitude,h.accuracy FROM vp3_phone_invites_v1 i, vp3_phone_devices_v1 d,
            vp3_phone_requests_v1 r,vp3_phone_history_points_v1 h WHERE 1=0');
        return vp3_phone_places_ready_v1($pdo) && vp3_phone_arrival_ready_v1($pdo);
    } catch (PDOException) {
        return false;
    }
}

function vp3_phone_sql_v1(PDO $pdo, string $sql, array $params): PDOStatement
{
    $query = $pdo->prepare($sql);
    $query->execute($params);
    return $query;
}

function vp3_phone_lock_v1(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
}

function vp3_phone_authorized_owner_v1(PDO $pdo, int $owner, bool $lock): ?string
{
    $row = vp3_phone_sql_v1($pdo, 'SELECT id,display_name,is_active FROM users WHERE id=?'
        .($lock ? vp3_phone_lock_v1($pdo) : ''), [$owner])->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['is_active'] !== 1 || !function_exists('has_permission')
        || !has_permission('account.access',$row) || !has_permission('chat.access',$row)) return null;
    return trim((string)$row['display_name']) ?: 'VP3 account owner';
}

function vp3_phone_account_v1(PDO $pdo, int $owner, bool $lock): string
{
    return vp3_phone_authorized_owner_v1($pdo,$owner,$lock) ?? throw new RuntimeException('Phone connection unavailable.',403);
}

function vp3_phone_token_v1(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function vp3_phone_secret_valid_v1(mixed $value): bool
{
    return is_string($value) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) === 1;
}

function vp3_phone_row_v1(PDO $pdo, int $owner, string $id): array
{
    $row = vp3_phone_sql_v1($pdo, 'SELECT * FROM vp3_phone_devices_v1
        WHERE owner_user_id=? AND id=?'.vp3_phone_lock_v1($pdo), [$owner,$id])->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('This phone is unavailable.', 404);
    return $row;
}

function vp3_phone_public_v1(array $row, int $now): array
{
    $state = (string)$row['location_state'];
    $status = (string)$row['device_status'];
    if ((int)$row['expires_at'] <= $now && in_array($status, ['pending','approved'], true)) $status = 'expired';
    $canRead = $status === 'approved' && (bool)$row['allow_location'];
    if (!$canRead) $state = 'off';
    $hasFix = $canRead && $state === 'live' && $row['fix_at'] !== null;
    if ($hasFix && (int)$row['fix_at'] <= $now - VP3_PHONE_FRESH_SECONDS_V1) $state = 'stale';
    return [
        'id'=>(string)$row['id'], 'name'=>(string)$row['device_name'], 'role'=>(string)$row['device_role'],
        'status'=>$status, 'allow_location'=>$canRead, 'location_state'=>$state, 'generation'=>(int)$row['generation'],
        'update_interval_seconds'=>(int)$row['update_interval_seconds'], 'accuracy_mode'=>$row['accuracy_mode'],
        'preferences_version'=>(int)$row['preferences_version'],
        'history_state'=>$canRead ? $row['history_state'] : 'off',
        'history_retention_days'=>$canRead ? (int)$row['history_retention_days'] : 0,
        'history_version'=>(int)$row['history_version'],
        'holder_can_save_places'=>$status === 'approved' && (bool)$row['holder_can_save_places'],
        'holder_place_version'=>(int)$row['holder_place_version'],
        'allow_saved_places'=>$canRead && in_array($state,['waiting','live','stale'],true)
            && (int)$row['places_generation'] === (int)$row['generation'],
        'place_permission_version'=>(int)$row['place_permission_version'],
        'latitude'=>$hasFix ? (float)$row['latitude'] : null, 'longitude'=>$hasFix ? (float)$row['longitude'] : null,
        'accuracy'=>$hasFix ? (float)$row['accuracy'] : null, 'fix_at'=>$hasFix ? (int)$row['fix_at'] : null,
        'first_fix_at'=>$hasFix ? (int)$row['first_fix_at'] : null,
        'last_seen_at'=>(int)$row['last_seen_at'], 'expires_at'=>(int)$row['expires_at'],
        'connected'=>in_array($status, ['pending','approved'], true) && (int)$row['last_seen_at'] > $now - VP3_PHONE_FRESH_SECONDS_V1,
    ];
}

function vp3_phone_holder_public_v1(PDO $pdo, array $row, int $now): array
{
    $device=vp3_phone_public_v1($row,$now);
    $rules=$device['allow_location'] && in_array($device['location_state'],['waiting','live','stale'],true)
        ? vp3_phone_arrival_device_rules_v1($pdo,$row) : [];
    $device['arrival_rules']=array_map('vp3_phone_arrival_brief_v1',$rules);
    return $device;
}

function vp3_phone_generation_v1(array $row, array $input, bool $allowEarlier=false): void
{
    $observed = $input['generation'] ?? null;
    if (!is_int($observed) || $observed < 1) throw new RuntimeException('A positive phone generation is required.', 422);
    $current = (int)$row['generation'];
    if ($observed > $current || (!$allowEarlier && $observed !== $current)) {
        throw new RuntimeException('Phone state changed. Refresh its status.', 409);
    }
}

function vp3_phone_save_row_v1(PDO $pdo, array $row): void
{
    vp3_phone_sql_v1($pdo, 'UPDATE vp3_phone_devices_v1 SET device_role=?, device_status=?,allow_location=?,
        generation=?,location_state=?,latitude=?,longitude=?,accuracy=?,fix_at=?,first_fix_at=?,started_at=?,
        expires_at=?,last_seen_at=?,update_interval_seconds=?,accuracy_mode=?,preferences_version=?,
        history_state=?,history_retention_days=?,history_version=?,history_accepted_at=?,places_generation=?,place_permission_version=?,
        holder_can_save_places=?,holder_place_version=?
        WHERE owner_user_id=? AND id=?', [
        $row['device_role'],$row['device_status'],(int)$row['allow_location'],$row['generation'],$row['location_state'],
        $row['latitude'],$row['longitude'],$row['accuracy'],$row['fix_at'],$row['first_fix_at'],$row['started_at'],
        $row['expires_at'],$row['last_seen_at'],$row['update_interval_seconds'],$row['accuracy_mode'],
        $row['preferences_version'],$row['history_state'],$row['history_retention_days'],$row['history_version'],
        $row['history_accepted_at'],$row['places_generation'],$row['place_permission_version'],
        (int)$row['holder_can_save_places'],$row['holder_place_version'],$row['owner_user_id'],$row['id'],
    ]);
}

function vp3_phone_end_location_v1(array &$row, string $state): void
{
    vp3_phone_history_end_v1($row);
    $row['location_state'] = $state;
    $row['generation'] = (int)$row['generation'] + 1;
    foreach (['latitude','longitude','accuracy','fix_at','first_fix_at','started_at'] as $key) $row[$key] = null;
}

function vp3_phone_set_preferences_v1(array &$row, array $input): void
{
    if (!$row['allow_location']) throw new RuntimeException('Location is not permitted for this phone.',403);
    $interval = $input['update_interval_seconds'] ?? null;
    $mode = $input['accuracy_mode'] ?? null;
    $version = $input['preferences_version'] ?? null;
    if (!in_array($interval,[5,15,30],true) || !in_array($mode,['high','balanced'],true)
        || !is_int($version) || $version < 1) {
        throw new RuntimeException('Choose a supported interval and accuracy mode.',422);
    }
    if ($version !== (int)$row['preferences_version']) throw new RuntimeException('Phone preferences have changed.',409);
    if ($interval === (int)$row['update_interval_seconds'] && $mode === $row['accuracy_mode']) return;
    $row['update_interval_seconds'] = $interval;
    $row['accuracy_mode'] = $mode;
    // A change to sensor settings must never grant or invalidate location consent.
    $row['preferences_version'] = $version + 1;
}

function vp3_phone_owner_transition_v1(array &$row, string $action, array $input, int $now): void
{
    // Revocation ends this immutable pairing even if its holder just stopped GPS.
    vp3_phone_generation_v1($row,$input,allowEarlier: $action === 'revoke');
    if ((int)$row['expires_at'] <= $now) throw new RuntimeException('This phone connection expired.', 409);
    $status = $row['device_status'];
    if ($action === 'approve' || $action === 'deny') {
        if ($status !== 'pending') throw new RuntimeException('This request is no longer pending.', 409);
        if ($action === 'approve') {
            $role = $input['role'] ?? 'guest';
            $allow = $input['allow_location'] ?? true;
            if (!in_array($role,['owner','guest','device'],true) || !is_bool($allow)) {
                throw new RuntimeException('Choose a valid role and location permission.', 422);
            }
            $row['device_status'] = 'approved';
            $row['device_role'] = $role;
            $row['allow_location'] = $allow;
            $row['expires_at'] = $now + 86400 * VP3_PHONE_IDLE_DAYS_V1;
        } else {
            $row['device_status'] = 'denied';
            $row['expires_at'] = $now;
        }
        $row['generation']++;
        return;
    }
    if ($status !== 'approved') throw new RuntimeException('This phone is unavailable.', 409);
    if ($action === 'revoke') {
        vp3_phone_end_location_v1($row,'off');
        $row['device_status'] = 'revoked';
        $row['allow_location'] = false;
        $row['expires_at'] = $now;
    } elseif ($action === 'stop') {
        vp3_phone_end_location_v1($row,'off');
    } elseif ($action === 'request_start') {
        if (!$row['allow_location']) throw new RuntimeException('Location is not permitted for this phone.', 403);
        if (in_array($row['location_state'], ['off','denied'], true)) vp3_phone_end_location_v1($row,'requested');
    } elseif (in_array($action,['request_history','stop_history','delete_history'],true)) {
        vp3_phone_history_transition_v1($row,$action,$input,$now);
    } elseif ($action === 'set_holder_place_access') {
        $allow = $input['holder_can_save_places'] ?? null;
        if (!is_bool($allow)) throw new RuntimeException('Choose a saved location permission.',422);
        if (($input['holder_place_version'] ?? null) !== (int)$row['holder_place_version']) throw new RuntimeException('Saved location permission changed.',409);
        if ($allow !== (bool)$row['holder_can_save_places']) {
            $row['holder_can_save_places'] = $allow;
            $row['holder_place_version']++;
        }
    } elseif ($action === 'set_preferences') {
        vp3_phone_set_preferences_v1($row,$input);
    } else {
        throw new RuntimeException('Unknown phone action.', 422);
    }
}

function vp3_phone_owner_v1(PDO $pdo, int $owner, string $action, array $input): array
{
    if ($owner < 1) throw new RuntimeException('Sign in to manage phones.', 401);
    if ($action === 'state') {
        vp3_phone_account_v1($pdo,$owner,false);
        $now = time();
        $rows = vp3_phone_sql_v1($pdo, "SELECT d.* FROM vp3_phone_devices_v1 d JOIN users u ON u.id=d.owner_user_id
            WHERE u.is_active=1 AND d.owner_user_id=? AND d.device_status IN ('pending','approved')
            AND d.expires_at>? ORDER BY d.created_at DESC,d.id LIMIT 32",
            [$owner,$now])->fetchAll(PDO::FETCH_ASSOC);
        return ['devices'=>array_map(static fn(array $row): array => vp3_phone_public_v1($row,$now), $rows),
            'server_time'=>$now,'stale_seconds'=>VP3_PHONE_FRESH_SECONDS_V1];
    }
    if (!in_array($action,['invite','approve','deny','request_start','stop','revoke','set_preferences','set_holder_place_access','request_history','stop_history','delete_history'],true)) throw new RuntimeException('Unknown phone action.', 422);
    $requestId = $input['request_id'] ?? null;
    $id = $action === 'invite' ? null : ($input['device_id'] ?? null);
    if (!is_string($requestId) || !preg_match('/^[A-Za-z0-9_.:-]{12,100}$/D',$requestId)) {
        throw new RuntimeException('An operation identifier is required.', 422);
    }
    if ($action !== 'invite' && (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id))) throw new RuntimeException('Invalid phone selection.', 422);
    $hash = hash('sha256',$requestId);
    $signatureFields = [$action,$id,$input['generation'] ?? null,$input['role'] ?? null,$input['allow_location'] ?? null];
    if ($action === 'set_preferences') {
        array_push($signatureFields,$input['preferences_version'] ?? null,$input['update_interval_seconds'] ?? null,$input['accuracy_mode'] ?? null);
    }
    if (in_array($action,['request_history','stop_history','delete_history'],true)) {
        array_push($signatureFields,$input['history_version'] ?? null,$input['retention_days'] ?? null);
    }
    if ($action === 'set_holder_place_access') array_push($signatureFields,$input['holder_can_save_places'] ?? null,$input['holder_place_version'] ?? null);
    $signature = hash('sha256', json_encode($signatureFields, JSON_THROW_ON_ERROR));
    return vp3_phone_transaction_v1($pdo, static function() use ($pdo,$owner,$action,$input,$id,$hash,$signature): array {
        vp3_phone_account_v1($pdo,$owner,true);
        $now = time();
        $receipt = vp3_phone_sql_v1($pdo, 'SELECT * FROM vp3_phone_requests_v1 WHERE owner_user_id=? AND request_hash=?'.vp3_phone_lock_v1($pdo),
            [$owner,$hash])->fetch(PDO::FETCH_ASSOC);
        if ($receipt) {
            if (!hash_equals($receipt['signature'],$signature)) throw new RuntimeException('Operation identifier has already been used.',409);
            if ($action === 'invite') return ['duplicate'=>true,'expires_at'=>(int)$receipt['expires_at']];
            return ['device'=>vp3_phone_public_v1(vp3_phone_row_v1($pdo,$owner,$id),$now),'duplicate'=>true];
        }
        if ($action === 'invite') {
            vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_invites_v1 WHERE owner_user_id=? AND expires_at<=?',[$owner,$now]);
            vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_devices_v1 WHERE owner_user_id=? AND expires_at<=?',[$owner,$now]);
            vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_requests_v1 WHERE owner_user_id=? AND created_at<?',[$owner,$now-86400*90]);
            $open = vp3_phone_sql_v1($pdo,'SELECT invite_hash FROM vp3_phone_invites_v1 WHERE owner_user_id=?
                AND claimed_device_id IS NULL LIMIT 4'.vp3_phone_lock_v1($pdo),[$owner])->fetchAll(PDO::FETCH_COLUMN);
            if (count($open) >= 4) throw new RuntimeException('Four invitations are already pending.',429);
            $secret = vp3_phone_token_v1(random_bytes(32));
            $expiry = $now + VP3_PHONE_INVITE_SECONDS_V1;
            vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_invites_v1(invite_hash,owner_user_id,expires_at) VALUES(?,?,?)',
                [vp3_extension_hash_v2000($secret),$owner,$expiry]);
            $result = ['invite'=>$secret,'expires_at'=>$expiry,'duplicate'=>false];
        } else {
            $row = vp3_phone_row_v1($pdo,$owner,$id);
            $generation=(int)$row['generation'];
            vp3_phone_owner_transition_v1($row,$action,$input,$now);
            if ($generation!==(int)$row['generation']) vp3_phone_arrival_clear_v1($pdo,$row);
            if ($action === 'revoke') {vp3_phone_push_revoke_v1($pdo,$row,$now);vp3_phone_handoff_revoke_v1($pdo,$row);vp3_phone_camera_disconnect_v1($pdo,$row);vp3_phone_communication_delete_v1($pdo,$row);}
            if ($action === 'delete_history' || $action === 'revoke') vp3_phone_history_delete_v1($pdo,$row);
            vp3_phone_save_row_v1($pdo,$row);
            $expiry = $row['expires_at'];
            $result = ['device'=>vp3_phone_public_v1($row,$now),'duplicate'=>false];
        }
        vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_requests_v1(owner_user_id,request_hash,signature,device_id,expires_at,created_at)
            VALUES(?,?,?,?,?,?)',[$owner,$hash,$signature,$id,$expiry,$now]);
        return $result;
    });
}

function vp3_phone_claim_v1(PDO $pdo, array $input): array
{
    $invite = $input['invite'] ?? null;
    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    $key = $input['claim_key'] ?? null;
    if (!vp3_phone_secret_valid_v1($invite)) throw new RuntimeException('Invitation unavailable.',403);
    if ($name === '' || !mb_check_encoding($name,'UTF-8') || mb_strlen($name)>48 || preg_match('/[\p{Cc}\p{Cf}]/u',$name)) {
        throw new RuntimeException('Name your phone using up to 48 characters.',422);
    }
    if ($key !== null && (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D',$key))) throw new RuntimeException('Invalid claim.',422);
    $hash = vp3_extension_hash_v2000($invite);
    // The hint only establishes lock order. Re-check the entire row under lock.
    $owner = (int)vp3_phone_sql_v1($pdo,'SELECT owner_user_id FROM vp3_phone_invites_v1 WHERE invite_hash=?',[$hash])->fetchColumn();
    if (!$owner) throw new RuntimeException('Invitation unavailable.',403);
    return vp3_phone_transaction_v1($pdo,static function() use ($pdo,$owner,$hash,$invite,$key,$name): array {
        $ownerName = vp3_phone_account_v1($pdo,$owner,true);
        $now = time();
        $inv = vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_invites_v1 WHERE invite_hash=? AND owner_user_id=?'
            .vp3_phone_lock_v1($pdo),[$hash,$owner])->fetch(PDO::FETCH_ASSOC);
        if (!$inv || (int)$inv['expires_at'] <= $now) throw new RuntimeException('Invitation unavailable.',403);
        // Only the original claimant holding both secrets can recover a lost acknowledgement.
        $token = vp3_phone_token_v1($key === null ? random_bytes(32) : hash_hmac('sha256','vp3/phone/claim/'.$key,$invite,true));
        $tokenHash = vp3_extension_hash_v2000($token);
        if ($inv['claimed_device_id'] !== null) {
            $row = vp3_phone_row_v1($pdo,$owner,$inv['claimed_device_id']);
            if ($key === null || !hash_equals($row['token_hash'],$tokenHash) || $row['device_name'] !== $name ||
                !in_array($row['device_status'],['pending','approved'],true) || (int)$row['expires_at'] <= $now) {
                throw new RuntimeException('Invitation unavailable.',403);
            }
            return ['token'=>$token,'owner_name'=>$ownerName,'device'=>vp3_phone_holder_public_v1($pdo,$row,$now)];
        }
        $active = vp3_phone_sql_v1($pdo,"SELECT id FROM vp3_phone_devices_v1 WHERE owner_user_id=?
            AND device_status IN ('pending','approved') AND expires_at>? LIMIT 32".vp3_phone_lock_v1($pdo),[$owner,$now])->fetchAll(PDO::FETCH_COLUMN);
        if (count($active) >= 32) throw new RuntimeException('Disconnect an unused phone before pairing another.',429);
        $id = str_replace('-','',vp3_extension_uuid_v2000());
        vp3_phone_sql_v1($pdo,"INSERT INTO vp3_phone_devices_v1
            (id,owner_user_id,token_hash,device_name,device_role,device_status,allow_location,generation,location_state,created_at,expires_at,last_seen_at)
            VALUES(?,?,?,?,'guest','pending',0,1,'off',?,?,?)",[$id,$owner,$tokenHash,$name,$now,$now+VP3_PHONE_INVITE_SECONDS_V1,$now]);
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_invites_v1 SET claimed_device_id=? WHERE invite_hash=?',[$id,$hash]);
        return ['token'=>$token,'owner_name'=>$ownerName,'device'=>vp3_phone_holder_public_v1($pdo,vp3_phone_row_v1($pdo,$owner,$id),$now)];
    });
}

function vp3_phone_number_v1(mixed $value, float $min, float $max): float
{
    if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < $min || $value > $max) {
        throw new RuntimeException('Invalid GPS reading.',422);
    }
    return (float)$value;
}

function vp3_phone_fix_v1(array &$row, array $input, int $now): void
{
    if (!in_array($row['location_state'],['waiting','live'],true) || $row['started_at'] === null) {
        throw new RuntimeException('Start sharing on this phone first.',409);
    }
    $at = $input['fix_at'] ?? null;
    if (!is_int($at) || $at <= $now - VP3_PHONE_FRESH_SECONDS_V1 || $at > $now + 5 ||
        $at < (int)$row['started_at']) throw new RuntimeException('Waiting for a fresh location.',422);
    if ($row['fix_at'] !== null && $at < (int)$row['fix_at']) throw new RuntimeException('A more recent location is already available.',409);
    $lat = vp3_phone_number_v1($input['latitude'] ?? null,-90,90);
    $lon = vp3_phone_number_v1($input['longitude'] ?? null,-180,180);
    $acc = vp3_phone_number_v1($input['accuracy'] ?? null,0,40075000);
    if ($row['fix_at'] !== null && $at === (int)$row['fix_at']) return;
    $row['latitude']=$lat;
    $row['longitude']=$lon;
    $row['accuracy']=$acc;
    $row['fix_at']=$at;
    $row['first_fix_at'] ??= $at;
    $row['location_state']='live';
}

function vp3_phone_device_transition_v1(array &$row, string $action, array $input, int $now): void
{
    if (!is_string($input['device_id'] ?? null) || !hash_equals($row['id'],$input['device_id'])) {
        throw new RuntimeException('Phone identity changed. Refresh this page.',403);
    }
    vp3_phone_generation_v1($row,$input);
    if ($action === 'disconnect') {
        vp3_phone_end_location_v1($row,'off');
        $row['device_status']='revoked';
        $row['allow_location']=false;
        $row['expires_at']=$now;
        return;
    }
    if ($row['device_status'] !== 'approved' || !$row['allow_location']) throw new RuntimeException('Location is not authorized for this phone.',403);
    if ($action === 'start') {
        if (in_array($row['location_state'],['waiting','live'],true)) return;
        if ($row['location_state'] !== 'requested') $row['generation']++;
        $row['location_state']='waiting';
        $row['started_at']=$now;
    } elseif ($action === 'stop' || $action === 'denied') {
        vp3_phone_end_location_v1($row,$action === 'denied' ? 'denied' : 'off');
    } elseif (in_array($action,['accept_history','decline_history','stop_history'],true)) {
        vp3_phone_history_transition_v1($row,$action,$input,$now);
    } elseif ($action === 'set_place_permission') {
        vp3_phone_place_permission_v1($row,$input);
    } elseif ($action === 'fix') {
        vp3_phone_fix_v1($row,$input,$now);
    } else {
        throw new RuntimeException('Unknown phone action.',422);
    }
}

function vp3_phone_device_v1(PDO $pdo, string $token, string $action, array $input): array
{
    if ($action === 'claim') return vp3_phone_claim_v1($pdo,$input);
    if (!vp3_phone_secret_valid_v1($token)) throw new RuntimeException('Phone connection unavailable.',403);
    $hash = vp3_extension_hash_v2000($token);
    $hint = vp3_phone_sql_v1($pdo,'SELECT id,owner_user_id FROM vp3_phone_devices_v1 WHERE token_hash=?',[$hash])->fetch(PDO::FETCH_ASSOC);
    if (!$hint) throw new RuntimeException('Phone connection unavailable.',403);
    $result = vp3_phone_transaction_v1($pdo,static function() use ($pdo,$action,$input,$hint,$hash): array {
        $owner = (int)$hint['owner_user_id'];
        $ownerName = vp3_phone_authorized_owner_v1($pdo,$owner,true);
        $now = time();
        $row = vp3_phone_row_v1($pdo,$owner,$hint['id']);
        if (!hash_equals($row['token_hash'],$hash) || !in_array($row['device_status'],['approved','pending'],true)
            || (int)$row['expires_at'] <= $now) throw new RuntimeException('Phone connection unavailable.',403);
        if ($ownerName === null) {
            // The holder may have pressed Stop while offline. A lost account grant
            // must not leave coordinates or consent that reappear after restoration.
            if ($action === 'disconnect' && ($input['device_id'] ?? null) === $row['id']
                && ($input['generation'] ?? null) === (int)$row['generation']) {
                vp3_phone_device_transition_v1($row,$action,$input,$now);
                vp3_phone_save_row_v1($pdo,$row);
            } elseif ($row['location_state'] !== 'off' || $row['history_state'] !== 'off') {
                vp3_phone_end_location_v1($row,'off');
                vp3_phone_save_row_v1($pdo,$row);
            }
            vp3_phone_history_delete_v1($pdo,$row);
            vp3_phone_arrival_clear_v1($pdo,$row);
            vp3_phone_camera_disconnect_v1($pdo,$row);
            vp3_phone_communication_delete_v1($pdo,$row);
            vp3_phone_handoff_revoke_v1($pdo,$row);
            vp3_phone_push_revoke_v1($pdo,$row,$now);
            return [];
        }
        $receipt = [];
        $touch = (int)$row['last_seen_at'] < $now - 10;
        if ($action !== 'status') {
            $previousFix = $row['fix_at'];
            $generation=(int)$row['generation'];
            if (in_array($action,['push_register','push_disable'],true)) {
                $receipt=vp3_phone_push_holder_v1($pdo,$row,$action,$input,$now);
            } elseif (in_array($action,['push_test','push_resolve'],true)) {
                $receipt=vp3_phone_push_delivery_holder_v1($pdo,$row,$action,$input,$now);
            } elseif (in_array($action,['handoff_offer','handoff_cancel'],true)) {
                $receipt=vp3_phone_handoff_holder_v1($pdo,$row,$action,$input,$now);
            } elseif ($action === 'save_communication_profile') {
                vp3_phone_communication_require_v1(is_string($input['device_id']??null)&&hash_equals($row['id'],$input['device_id']),403);
                vp3_phone_communication_save_v1($pdo,$row,$input,$now,true);
            } elseif (in_array($action,['information_list','information_accept','information_decline','information_ack','information_reply','information_attach_file','information_download'],true)) {
                $receipt=['information'=>vp3_phone_information_action_v1($pdo,$row,$action,$input,$now,true)];
            } elseif (in_array($action,['dialer_preferences','dialer_claim','dialer_handled','dialer_decline'],true)) {
                $receipt=vp3_phone_dialer_action_v1($pdo,$row,$action,$input,$now,true);
            } elseif ($action === 'managed_recording_withdraw') {
                $receipt=vp3_managed_ui_withdraw_holder_v1($pdo,$row,$input,$now);
            } elseif ($action === 'native_dialthrough_issue') {
                require_once __DIR__.'/phone-native-dialthrough-v1.php';
                $receipt=vp3_native_dialthrough_issue_v1($pdo,$row,$input,$now);
            } elseif ($action === 'managed_respond') {
                $receipt=vp3_managed_ui_holder_action_v1($pdo,$row,$input,$now);
            } elseif ($action === 'holder_save_place') {
                $receipt = vp3_phone_holder_save_place_v1($pdo,$row,$input,$now);
            } elseif (in_array($action,['accept_arrival','decline_arrival','stop_arrival'],true)) {
                vp3_phone_arrival_holder_v1($pdo,$row,$action,$input,$now);
            } else {
                vp3_phone_device_transition_v1($row,$action,$input,$now);
            }
            if ($generation!==(int)$row['generation']) vp3_phone_arrival_clear_v1($pdo,$row);
            if ($action === 'disconnect') {vp3_phone_push_revoke_v1($pdo,$row,$now);vp3_phone_handoff_revoke_v1($pdo,$row);vp3_phone_camera_disconnect_v1($pdo,$row);vp3_phone_communication_delete_v1($pdo,$row);}
            if ($action === 'fix' && ($previousFix === null || (int)$previousFix !== (int)$row['fix_at'])) {
                vp3_phone_history_record_v1($pdo,$row,$input,$now);
                vp3_phone_arrival_fix_v1($pdo,$row,$input,$now);
            } elseif ($action === 'accept_history') {
                vp3_phone_history_cap_retention_v1($pdo,$row,$now);
            } elseif ($action === 'disconnect') {
                vp3_phone_history_delete_v1($pdo,$row);
            }
        }
        if ($action !== 'status' || $touch) {
            if ($row['device_status'] === 'approved') {
                $row['expires_at'] = min($now + VP3_PHONE_IDLE_DAYS_V1*86400,(int)$row['created_at'] + VP3_PHONE_MAX_DAYS_V1*86400);
            }
            $row['last_seen_at']=$now;
            vp3_phone_save_row_v1($pdo,$row);
        }
        $public=vp3_phone_holder_public_v1($pdo,$row,$now);
        $public['communication']=vp3_phone_communication_project_v1($pdo,$row,$now,true);
        $public['dialer']=vp3_phone_dialer_view_v1($pdo,$row,$now,true);
        $public['managed_callback']=vp3_managed_ui_projection_v1($pdo,$row,$now,true);
        $public['handoff']=vp3_phone_handoff_view_v1($pdo,$row,$now);
        $public['push']=vp3_phone_push_view_v1($pdo,$row,$now);
        return ['device'=>$public,'owner_name'=>$ownerName] + $receipt;
    });
    // Return no owner or device data, after committing the withdrawal.
    return $result ?: throw new RuntimeException('Phone connection unavailable.',403);
}
