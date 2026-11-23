<?php
declare(strict_types=1);

function vp3_phone_places_schema_v1(PDO $pdo): void
{
    $options = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    // A named place belongs to the account independently of a device or retained trail.
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_places_v1 (
        owner_user_id INT UNSIGNED NOT NULL, id CHAR(32) NOT NULL,
        name VARCHAR(80) NOT NULL, name_hash CHAR(64) NOT NULL,
        latitude DOUBLE NOT NULL, longitude DOUBLE NOT NULL, accuracy DOUBLE NOT NULL,
        created_at BIGINT NOT NULL, PRIMARY KEY (owner_user_id,id),
        UNIQUE (owner_user_id,name_hash), FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$options);
}

function vp3_phone_places_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT owner_user_id,id,name,name_hash,latitude,longitude,accuracy,created_at FROM vp3_phone_places_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

/** Called on the locked device after checking its current holder credential and live generation. */
function vp3_phone_place_permission_v1(array &$row, array $input): void
{
    $allow = $input['allow_saved_places'] ?? null;
    $version = $input['place_permission_version'] ?? null;
    if (!is_bool($allow) || !is_int($version) || $version < 1) throw new RuntimeException('Check the saved places choice.', 422);
    if (!in_array($row['location_state'], ['waiting', 'live'], true)) throw new RuntimeException('Start sharing first.', 409);
    $current = (int)$row['place_permission_version'];
    $granted = (int)$row['places_generation'] === (int)$row['generation'];
    if ($version > $current || ($version !== $current && $granted !== $allow)) {
        throw new RuntimeException('The saved places choice has changed.', 409);
    }
    if ($granted === $allow) return;
    $row['places_generation'] = $allow ? (int)$row['generation'] : null;
    $row['place_permission_version'] = $current + 1;
}

function vp3_phone_place_name_v1(mixed $input): string
{
    if (!is_string($input) || !mb_check_encoding($input, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}]/u', $input)) {
        throw new RuntimeException('Choose a place name using up to 80 characters.', 422);
    }
    $name = trim(preg_replace('/\s+/u', ' ', $input));
    if ($name === '' || mb_strlen($name, 'UTF-8') > 80) throw new RuntimeException('Choose a place name using up to 80 characters.', 422);
    return $name;
}

function vp3_phone_place_public_v1(array $row): array
{
    return [
        'id'=>$row['id'], 'name'=>$row['name'], 'latitude'=>(float)$row['latitude'],
        'longitude'=>(float)$row['longitude'], 'accuracy'=>(float)$row['accuracy'], 'created_at'=>(int)$row['created_at'],
    ];
}

function vp3_phone_create_place_v1(
    PDO $pdo, int $owner, string $deviceId, array $input, string $placeId, string $name, int $now
): void {
    $row = vp3_phone_row_v1($pdo, $owner, $deviceId);
    vp3_phone_generation_v1($row, $input);
    $phone = vp3_phone_public_v1($row, $now);
    if (!$phone['allow_saved_places']) throw new RuntimeException('Enable Allow saved places on this phone while sharing.', 403);
    if ($phone['location_state'] !== 'live' || !isset($row['latitude'], $row['longitude'], $row['accuracy'], $phone['fix_at'])
        || $phone['fix_at'] > $now + 5) {
        throw new RuntimeException('Wait for a fresh location from this phone.', 409);
    }
    $nameHash = hash('sha256', mb_strtolower($name, 'UTF-8'));
    if (vp3_phone_sql_v1($pdo, 'SELECT id FROM vp3_phone_places_v1 WHERE owner_user_id=? AND name_hash=?', [$owner, $nameHash])->fetchColumn() !== false) {
        throw new RuntimeException('A saved place already uses that name.', 409);
    }
    vp3_phone_sql_v1($pdo, 'INSERT INTO vp3_phone_places_v1(owner_user_id,id,name,name_hash,latitude,longitude,accuracy,created_at)
        VALUES(?,?,?,?,?,?,?,?)', [$owner, $placeId, $name, $nameHash, $phone['latitude'], $phone['longitude'], $phone['accuracy'], $now]);
}

function vp3_phone_places_owner_v1(PDO $pdo, int $owner, string $action, array $input = []): array
{
    if ($owner < 1) throw new RuntimeException('Sign in to manage saved places.', 401);
    if ($action === 'places') {
        return vp3_phone_transaction_v1($pdo, static function () use ($pdo, $owner): array {
            vp3_phone_account_v1($pdo, $owner, true);
            $rows = vp3_phone_sql_v1($pdo, 'SELECT * FROM vp3_phone_places_v1 WHERE owner_user_id=? ORDER BY created_at DESC,id', [$owner]);
            return ['places'=>array_map('vp3_phone_place_public_v1', $rows->fetchAll(PDO::FETCH_ASSOC)), 'server_time'=>time()];
        });
    }
    if (!in_array($action, ['save_place', 'delete_place'], true)) throw new RuntimeException('Unknown saved place action.', 422);
    $request = $input['request_id'] ?? null;
    if (!is_string($request) || !preg_match('/^[a-zA-Z0-9_.:-]{12,100}$/D', $request)) throw new RuntimeException('An operation identifier is required.', 422);
    $save = $action === 'save_place';
    $id = $input[$save ? 'device_id' : 'place_id'] ?? null;
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) throw new RuntimeException('Choose a phone or saved place.', 422);
    $name = $save ? vp3_phone_place_name_v1($input['name'] ?? null) : null;
    $requestHash = hash('sha256', $request);
    $signature = hash('sha256', json_encode([$action, $id, $name, $save ? ($input['generation'] ?? null) : null], JSON_THROW_ON_ERROR));
    // Stable per-account ID associates the creation receipt with this one place, even after deletion.
    $placeId = $save ? substr(hash('sha256', "vp3-place-v1/$owner/$requestHash"), 0, 32) : $id;
    return vp3_phone_transaction_v1($pdo, static function () use ($pdo, $owner, $save, $id, $name, $input, $requestHash, $signature, $placeId): array {
        vp3_phone_account_v1($pdo, $owner, true);
        $now = time();
        $receipt = vp3_phone_sql_v1($pdo, 'SELECT signature FROM vp3_phone_requests_v1 WHERE owner_user_id=? AND request_hash=?'
            .vp3_phone_lock_v1($pdo), [$owner, $requestHash])->fetchColumn();
        if ($receipt !== false && !hash_equals((string)$receipt, $signature)) throw new RuntimeException('This operation identifier has already been used.', 409);
        $duplicate = $receipt !== false;
        if ($save) {
            if (!$duplicate) {
                vp3_phone_create_place_v1($pdo, $owner, $id, $input, $placeId, $name, $now);
            }
            $place = vp3_phone_sql_v1($pdo, 'SELECT * FROM vp3_phone_places_v1 WHERE owner_user_id=? AND id=?', [$owner, $placeId])->fetch(PDO::FETCH_ASSOC);
            if (!$place) throw new RuntimeException('This saved place was deleted.', 409);
            $result = ['place'=>vp3_phone_place_public_v1($place),'duplicate'=>$duplicate];
        } else {
            if (!$duplicate && vp3_phone_sql_v1($pdo, 'DELETE FROM vp3_phone_places_v1 WHERE owner_user_id=? AND id=?', [$owner, $placeId])->rowCount() !== 1) {
                throw new RuntimeException('Saved place unavailable.', 404);
            }
            $result = ['deleted'=>true,'duplicate'=>$duplicate];
        }
        if (!$duplicate) {
            vp3_phone_sql_v1($pdo, 'INSERT INTO vp3_phone_requests_v1(owner_user_id,request_hash,signature,device_id,expires_at,created_at)
                VALUES(?,?,?,?,?,?)', [$owner, $requestHash, $signature, $save ? $id : null, $now + VP3_PHONE_MAX_DAYS_V1 * 86400, $now]);
        }
        return $result;
    });
}
