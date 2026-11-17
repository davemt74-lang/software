<?php
declare(strict_types=1);

const VP3_MEDIA_COLLECTION_NAME_MAX_V1 = 100;

/** Database Upgrade only. Membership carries the owner in both foreign keys. */
function vp3_media_collections_install_v1(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($mysql) {
        $assetKey = $pdo->query("SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_media_assets'
                AND INDEX_NAME='uq_user_media_owner_id' LIMIT 1");
        if ($assetKey->fetchColumn() === false) {
            $pdo->exec('ALTER TABLE user_media_assets ADD UNIQUE KEY uq_user_media_owner_id (user_id,id)');
        }
    } else {
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_user_media_owner_id ON user_media_assets (user_id,id)');
    }
    $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT' : 'INTEGER';
    $options = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $assetIndex = $mysql ? 'INDEX idx_media_collection_assets_owner_asset (owner_user_id,asset_id),' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_media_collections_v1 (
        id $id PRIMARY KEY,
        owner_user_id INT UNSIGNED NOT NULL,
        name VARCHAR(100) NOT NULL,
        name_key CHAR(64) NOT NULL,
        CONSTRAINT uq_media_collection_owner_id UNIQUE (owner_user_id,id),
        CONSTRAINT uq_media_collection_owner_name UNIQUE (owner_user_id,name_key),
        CONSTRAINT fk_media_collection_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$options);
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_media_collection_assets_v1 (
        owner_user_id INT UNSIGNED NOT NULL,
        collection_id BIGINT UNSIGNED NOT NULL,
        asset_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (owner_user_id,collection_id,asset_id),
        $assetIndex
        CONSTRAINT fk_media_collection_asset_collection FOREIGN KEY (owner_user_id,collection_id)
            REFERENCES vp3_media_collections_v1(owner_user_id,id) ON DELETE CASCADE,
        CONSTRAINT fk_media_collection_asset_owner FOREIGN KEY (owner_user_id,asset_id)
            REFERENCES user_media_assets(user_id,id) ON DELETE CASCADE
    )".$options);
    if (!$mysql) {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_media_collection_assets_owner_asset
            ON vp3_media_collection_assets_v1 (owner_user_id,asset_id)');
    }
}

function vp3_media_collections_ready_v1(PDO $pdo): bool
{
    try {
        return $pdo->query('SELECT c.id,c.owner_user_id,c.name,c.name_key,m.owner_user_id,m.collection_id,m.asset_id
            FROM vp3_media_collections_v1 c JOIN vp3_media_collection_assets_v1 m
                ON m.owner_user_id=c.owner_user_id AND m.collection_id=c.id WHERE 1=0') !== false;
    } catch (PDOException) {
        return false;
    }
}

function vp3_media_collections_list_v1(PDO $pdo, int $owner): array
{
    $query = $pdo->prepare('SELECT c.id,c.name,COUNT(m.asset_id) AS asset_count
        FROM vp3_media_collections_v1 c LEFT JOIN vp3_media_collection_assets_v1 m
            ON m.owner_user_id=c.owner_user_id AND m.collection_id=c.id
        WHERE c.owner_user_id=? GROUP BY c.id,c.name ORDER BY c.name,c.id');
    $query->execute([$owner]);
    return array_map('vp3_media_collection_view_v1', $query->fetchAll(PDO::FETCH_ASSOC));
}

function vp3_media_collection_require_v1(PDO $pdo, int $owner, int $id): array
{
    $query = $pdo->prepare('SELECT c.id,c.name,COUNT(m.asset_id) AS asset_count
        FROM vp3_media_collections_v1 c LEFT JOIN vp3_media_collection_assets_v1 m
            ON m.owner_user_id=c.owner_user_id AND m.collection_id=c.id
        WHERE c.owner_user_id=? AND c.id=? GROUP BY c.id,c.name');
    $query->execute([$owner,$id]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new DomainException('Media collection not found.',404);
    return vp3_media_collection_view_v1($row);
}

function vp3_media_collection_view_v1(array $row): array
{
    return ['id'=>(int)$row['id'],'name'=>$row['name'],'count'=>(int)$row['asset_count']];
}

function vp3_media_collection_name_v1(string $name): array
{
    $name = preg_replace('/\A\s+|\s+\z/u','',$name);
    if ($name === null || $name === '' || mb_strlen($name,'UTF-8') > VP3_MEDIA_COLLECTION_NAME_MAX_V1
        || preg_match('/\p{Cc}/u',$name)) {
        throw new DomainException('Enter a collection name between 1 and 100 characters.',422);
    }
    return [$name,hash('sha256',mb_convert_case($name,MB_CASE_FOLD,'UTF-8'))];
}

function vp3_media_collection_save_name_v1(PDOStatement $query, array $params): void
{
    try {
        $query->execute($params);
    } catch (PDOException $error) {
        $details = $error->errorInfo ?? [];
        if (in_array((int)($details[1] ?? 0),[1062,2067],true)
            || ((int)($details[1] ?? 0) === 19 && str_contains($details[2] ?? '','UNIQUE constraint failed:'))) {
            throw new DomainException('A media collection with this name already exists.',409,$error);
        }
        throw $error;
    }
}

function vp3_media_collection_create_v1(PDO $pdo, int $owner, string $name): array
{
    if ($owner < 1) throw new DomainException('Media collection owner not found.',404);
    [$name,$key] = vp3_media_collection_name_v1($name);
    $query = $pdo->prepare('INSERT INTO vp3_media_collections_v1 (owner_user_id,name,name_key) VALUES (?,?,?)');
    vp3_media_collection_save_name_v1($query,[$owner,$name,$key]);
    return ['id'=>(int)$pdo->lastInsertId(),'name'=>$name,'count'=>0];
}

function vp3_media_collection_rename_v1(PDO $pdo, int $owner, int $id, string $name): array
{
    [$name,$key] = vp3_media_collection_name_v1($name);
    $query = $pdo->prepare('UPDATE vp3_media_collections_v1 SET name=?,name_key=? WHERE owner_user_id=? AND id=?');
    vp3_media_collection_save_name_v1($query,[$name,$key,$owner,$id]);
    return vp3_media_collection_require_v1($pdo,$owner,$id);
}

function vp3_media_collection_delete_v1(PDO $pdo, int $owner, int $id): void
{
    $query = $pdo->prepare('DELETE FROM vp3_media_collections_v1 WHERE owner_user_id=? AND id=?');
    $query->execute([$owner,$id]);
    if ($query->rowCount() === 0) throw new DomainException('Media collection not found.',404);
}

function vp3_media_collection_set_asset_v1(PDO $pdo, int $owner, int $collection, int $asset, bool $member): void
{
    if ($member) {
        $onDuplicate = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'ON DUPLICATE KEY UPDATE asset_id=vp3_media_collection_assets_v1.asset_id'
            : 'ON CONFLICT (owner_user_id,collection_id,asset_id) DO NOTHING';
        $query = $pdo->prepare('INSERT INTO vp3_media_collection_assets_v1 (owner_user_id,collection_id,asset_id)
            SELECT c.owner_user_id,c.id,a.id FROM vp3_media_collections_v1 c
            JOIN user_media_assets a ON a.user_id=c.owner_user_id AND a.id=?
            WHERE c.owner_user_id=? AND c.id=? '.$onDuplicate);
        $query->execute([$asset,$owner,$collection]);
    } else {
        $query = $pdo->prepare('DELETE FROM vp3_media_collection_assets_v1
            WHERE owner_user_id=? AND collection_id=? AND asset_id=?');
        $query->execute([$owner,$collection,$asset]);
    }
    if ($query->rowCount() !== 0) return;
    // An unchanged membership is successful only when both records belong to this owner.
    vp3_media_collection_require_v1($pdo,$owner,$collection);
    $assetQuery = $pdo->prepare('SELECT 1 FROM user_media_assets WHERE user_id=? AND id=?');
    $assetQuery->execute([$owner,$asset]);
    if ($assetQuery->fetchColumn() === false) throw new DomainException('Media asset not found.',404);
}
