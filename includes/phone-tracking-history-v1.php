<?php
declare(strict_types=1);

/** The live marker and each retained sample have separate lifetimes. */
function vp3_phone_history_schema_v1(PDO $pdo, string $options): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $indexes = $mysql ? ', INDEX vp3_phone_history_expiry (expires_at),
        INDEX vp3_phone_device_history_expiry (owner_user_id,device_id,expires_at)' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_history_points_v1 (
        owner_user_id INT UNSIGNED NOT NULL, device_id CHAR(32) NOT NULL, fix_at BIGINT NOT NULL,
        generation BIGINT NOT NULL, history_version BIGINT NOT NULL, expires_at BIGINT NOT NULL,
        latitude DOUBLE NOT NULL, longitude DOUBLE NOT NULL, accuracy DOUBLE NOT NULL,
        PRIMARY KEY(owner_user_id,device_id,fix_at),
        FOREIGN KEY(owner_user_id,device_id) REFERENCES vp3_phone_devices_v1(owner_user_id,id) ON DELETE CASCADE
        $indexes
    )".$options);
    if (!$mysql) {
        $pdo->exec('CREATE INDEX IF NOT EXISTS vp3_phone_history_expiry ON vp3_phone_history_points_v1(expires_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS vp3_phone_device_history_expiry ON vp3_phone_history_points_v1(owner_user_id,device_id,expires_at)');
    }
}

function vp3_phone_history_end_v1(array &$row): void
{
    if ($row['history_state'] === 'off') return;
    $row['history_state'] = 'off';
    $row['history_retention_days'] = 0;
    $row['history_accepted_at'] = null;
    $row['history_version'] = (int)$row['history_version'] + 1;
}

function vp3_phone_history_transition_v1(array &$row, string $action, array $input, int $now): void
{
    $version = $input['history_version'] ?? null;
    if (!is_int($version) || $version < 1) throw new RuntimeException('A history revision is required.',422);
    if ($version !== (int)$row['history_version']) throw new RuntimeException('History settings changed. Refresh this phone.',409);
    if ($action === 'delete_history' || $action === 'stop_history' || $action === 'decline_history') {
        if ($action === 'decline_history' && $row['history_state'] !== 'requested') {
            throw new RuntimeException('There is no pending history request.',409);
        }
        vp3_phone_history_end_v1($row);
        return;
    }
    if (!$row['allow_location']) throw new RuntimeException('Location is not permitted for this phone.',403);
    if (!in_array($row['location_state'],['waiting','live'],true)) {
        throw new RuntimeException('Start sharing location on this phone before requesting location history.',409);
    }
    if ($action === 'request_history') {
        $days = $input['retention_days'] ?? null;
        if (!in_array($days,[1,7,30],true)) throw new RuntimeException('Select 1, 7 or 30 days for history.',422);
        if ($row['history_state'] !== 'off' && (int)$row['history_retention_days'] === $days) return;
        $row['history_state'] = 'requested';
        $row['history_retention_days'] = $days;
        $row['history_accepted_at'] = null;
    } else {
        if ($action !== 'accept_history' || $row['history_state'] !== 'requested') {
            throw new RuntimeException('There is no pending history request.',409);
        }
        $row['history_state'] = 'active';
        $row['history_accepted_at'] = $now;
    }
    $row['history_version'] = $version + 1;
}

function vp3_phone_history_delete_v1(PDO $pdo, array $row): void
{
    vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_history_points_v1 WHERE owner_user_id=? AND device_id=?',
        [$row['owner_user_id'],$row['id']]);
}

function vp3_phone_history_expire_device_v1(PDO $pdo, int $owner, string $id, int $now): void
{
    vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_history_points_v1 WHERE owner_user_id=? AND device_id=? AND expires_at<=?',
        [$owner,$id,$now]);
}

function vp3_phone_history_cap_retention_v1(PDO $pdo, array $row, int $now): void
{
    // A shorter consent period also shortens existing records. A longer period never extends them.
    $lifetime = (int)$row['history_retention_days'] * 86400;
    vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_history_points_v1 SET expires_at=fix_at+?
        WHERE owner_user_id=? AND device_id=? AND expires_at>fix_at+?',
        [$lifetime,$row['owner_user_id'],$row['id'],$lifetime]);
    vp3_phone_history_expire_device_v1($pdo,(int)$row['owner_user_id'],$row['id'],$now);
}

function vp3_phone_history_record_v1(PDO $pdo, array $row, array $input, int $now): void
{
    // A live-only client, a queued sample from before consent, or a sample captured
    // in a previous consent revision is never promoted into a trail.
    $at = (int)$row['fix_at'];
    if ($row['history_state'] !== 'active' || ($input['history_version'] ?? null) !== (int)$row['history_version']
        || $row['history_accepted_at'] === null || $at <= (int)$row['history_accepted_at']) return;
    $owner = (int)$row['owner_user_id'];
    $id = $row['id'];
    vp3_phone_history_expire_device_v1($pdo,$owner,$id,$now);
    // The caller holds the account/device lock. A sensor may report the same
    // second across adjacent GPS sessions. Keep the original point once.
    if (vp3_phone_sql_v1($pdo,'SELECT 1 FROM vp3_phone_history_points_v1 WHERE owner_user_id=? AND device_id=? AND fix_at=?',
        [$owner,$id,$at])->fetchColumn()) return;
    vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_history_points_v1
        (owner_user_id,device_id,fix_at,generation,history_version,expires_at,latitude,longitude,accuracy)
        VALUES (?,?,?,?,?,?,?,?,?)',
        [$owner,$id,$at,$row['generation'],$row['history_version'],$at+(int)$row['history_retention_days']*86400,
         $row['latitude'],$row['longitude'],$row['accuracy']]);
}

function vp3_phone_history_read_v1(PDO $pdo, int $owner, string $id, ?int $before=null): array
{
    if (!preg_match('/^[a-f0-9]{32}$/D',$id) || ($before !== null && $before <= 0)) {
        throw new RuntimeException('Invalid history selection.',422);
    }
    return vp3_phone_transaction_v1($pdo,static function() use ($pdo,$owner,$id,$before): array {
        vp3_phone_account_v1($pdo,$owner,true);
        $row = vp3_phone_row_v1($pdo,$owner,$id);
        $now = time();
        if ($row['device_status'] !== 'approved' || !$row['allow_location'] || (int)$row['expires_at'] <= $now) {
            throw new RuntimeException('This phone is unavailable.',403);
        }
        vp3_phone_history_expire_device_v1($pdo,$owner,$id,$now);
        $parameters = [$owner,$id,$now];
        if ($before !== null) $parameters[] = $before;
        $rows = vp3_phone_sql_v1($pdo,'SELECT latitude,longitude,accuracy,fix_at,expires_at,generation,history_version
            FROM vp3_phone_history_points_v1 WHERE owner_user_id=? AND device_id=? AND expires_at>?'
            .($before === null ? '' : ' AND fix_at<?').' ORDER BY fix_at DESC LIMIT 201',$parameters)->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > 200;
        if ($more) array_pop($rows);
        $points = [];
        foreach (array_reverse($rows) as $point) {
            $points[] = ['latitude'=>(float)$point['latitude'],'longitude'=>(float)$point['longitude'],
                'accuracy'=>(float)$point['accuracy'],'fix_at'=>(int)$point['fix_at'],'expires_at'=>(int)$point['expires_at'],
                'generation'=>(int)$point['generation'],'history_version'=>(int)$point['history_version']];
        }
        return ['points'=>$points,'next_before'=>$more ? $points[0]['fix_at'] : null,'server_time'=>$now];
    });
}

/** Global Cloud worker sweep. Index the cutoff so dormant phones are purged without polling them. */
function vp3_phone_history_sweep_v1(PDO $pdo, int $now): int
{
    $stmt = $pdo->prepare('DELETE FROM vp3_phone_history_points_v1 WHERE expires_at<=?');
    $stmt->execute([$now]);
    return $stmt->rowCount();
}
