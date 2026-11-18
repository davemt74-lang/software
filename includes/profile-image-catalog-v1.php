<?php
declare(strict_types=1);

require_once __DIR__.'/media-collections-v1.php';
require_once __DIR__.'/media-studio-v86.php';
require_once __DIR__.'/agent-image-reference-v1.php';

const VP3_PROFILE_IMAGE_CATALOG_PRICE_MAX_V1 = 99999999;
const VP3_PROFILE_IMAGE_CATALOG_REFERENCE_BYTES_V1 = 4194304;

/** Database Upgrade only. An ordinary collection membership is never a public image-use grant. */
function vp3_profile_image_catalog_install_v1(PDO $pdo): void
{
    if (!vp3_media_collections_ready_v1($pdo)) throw new RuntimeException('Install Media collections before Profile image settings.', 503);
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_image_settings_v1 (
        owner_user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        enabled INTEGER NOT NULL DEFAULT 0,
        daily_limit INTEGER NOT NULL DEFAULT 10,
        visitor_daily_limit INTEGER NOT NULL DEFAULT 3,
        revision BIGINT NOT NULL DEFAULT 1,
        CONSTRAINT fk_profile_image_settings_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$tail);
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_image_catalogs_v1 (
        owner_user_id INT UNSIGNED NOT NULL,
        collection_id BIGINT UNSIGNED NOT NULL,
        approved INTEGER NOT NULL DEFAULT 0,
        style_instructions TEXT NOT NULL,
        default_price_cents INTEGER NOT NULL DEFAULT 0,
        revision BIGINT NOT NULL DEFAULT 1,
        PRIMARY KEY (owner_user_id,collection_id),
        CONSTRAINT fk_profile_image_catalog_collection FOREIGN KEY (owner_user_id,collection_id)
            REFERENCES vp3_media_collections_v1(owner_user_id,id) ON DELETE CASCADE
    )".$tail);
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_image_catalog_refs_v1 (
        owner_user_id INT UNSIGNED NOT NULL,
        collection_id BIGINT UNSIGNED NOT NULL,
        asset_id BIGINT UNSIGNED NOT NULL,
        sequence_no INTEGER NOT NULL,
        price_override_cents INTEGER NULL,
        source_sha256 CHAR(64) NOT NULL,
        source_revision CHAR(64) NOT NULL,
        mime_type VARCHAR(40) NOT NULL,
        byte_size BIGINT NOT NULL,
        width INTEGER NOT NULL,
        height INTEGER NOT NULL,
        PRIMARY KEY (owner_user_id,collection_id,asset_id),
        CONSTRAINT uq_profile_image_catalog_sequence UNIQUE (owner_user_id,collection_id,sequence_no),
        CONSTRAINT fk_profile_image_reference_catalog FOREIGN KEY (owner_user_id,collection_id)
            REFERENCES profile_image_catalogs_v1(owner_user_id,collection_id) ON DELETE CASCADE,
        CONSTRAINT fk_profile_image_reference_asset FOREIGN KEY (owner_user_id,asset_id)
            REFERENCES user_media_assets(user_id,id) ON DELETE CASCADE,
        CONSTRAINT fk_profile_image_reference_membership FOREIGN KEY (owner_user_id,collection_id,asset_id)
            REFERENCES vp3_media_collection_assets_v1(owner_user_id,collection_id,asset_id) ON DELETE CASCADE
    )".$tail);
}

function vp3_profile_image_catalog_ready_v1(PDO $pdo): bool
{
    try {
        return $pdo->query('SELECT s.enabled,s.daily_limit,s.visitor_daily_limit,s.revision,
            c.collection_id,c.approved,c.style_instructions,c.default_price_cents,c.revision,
            r.asset_id,r.sequence_no,r.price_override_cents,r.source_sha256,r.source_revision,
            r.mime_type,r.byte_size,r.width,r.height
            FROM profile_image_settings_v1 s JOIN profile_image_catalogs_v1 c ON c.owner_user_id=s.owner_user_id
            JOIN profile_image_catalog_refs_v1 r ON r.owner_user_id=c.owner_user_id AND r.collection_id=c.collection_id
            WHERE 1=0') !== false && vp3_media_collections_ready_v1($pdo);
    } catch (PDOException) {
        return false;
    }
}

function vp3_profile_image_catalog_settings_v1(PDO $pdo, int $owner): array
{
    $ready = vp3_profile_image_catalog_ready_v1($pdo);
    $defaults = ['available'=>$ready, 'enabled'=>false, 'daily_limit'=>10, 'visitor_daily_limit'=>3, 'revision'=>0];
    if (!$ready || $owner < 1) return $defaults;
    $query = $pdo->prepare('SELECT enabled,daily_limit,visitor_daily_limit,revision FROM profile_image_settings_v1 WHERE owner_user_id=?'
        .vp3_profile_image_catalog_read_lock_v1($pdo));
    $query->execute([$owner]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row) return $defaults;
    return ['available'=>true, 'enabled'=>(int)$row['enabled']===1,
        'daily_limit'=>(int)$row['daily_limit'], 'visitor_daily_limit'=>(int)$row['visitor_daily_limit'],
        'revision'=>(int)$row['revision']];
}

function vp3_profile_image_catalog_live_owner_v1(PDO $pdo, int $owner): array
{
    $query = $pdo->prepare('SELECT * FROM users WHERE id=?'.vp3_profile_image_catalog_read_lock_v1($pdo));
    $query->execute([$owner]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
    if (!$user || (int)($user['is_active']??0)!==1) throw new DomainException('This Profile image service is unavailable.', 403);
    return $user;
}

/** Current reads inside dispatch/publication transactions also serialize generic Media deletion. */
function vp3_profile_image_catalog_read_lock_v1(PDO $pdo): string
{
    return $pdo->inTransaction() && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? ' FOR UPDATE' : '';
}

function vp3_profile_image_catalog_lock_v1(PDO $pdo, int $owner): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Profile image settings require an open transaction.');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {
        $query = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
        $query->execute([$owner]);
        if (!$query->fetchColumn()) throw new DomainException('Profile image owner is unavailable.', 403);
    } else {
        $pdo->prepare('UPDATE users SET id=id WHERE id=?')->execute([$owner]);
    }
}

function vp3_profile_image_catalog_atomic_v1(PDO $pdo, callable $operation): mixed
{
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        $result = $operation();
        if ($owns) $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function vp3_profile_image_catalog_integer_v1(mixed $value, int $min, int $max, string $message): int
{
    if (!is_int($value) || $value<$min || $value>$max) throw new InvalidArgumentException($message, 422);
    return $value;
}

function vp3_profile_image_catalog_row_v1(PDO $pdo, int $owner, int $collection): array
{
    $query = $pdo->prepare('SELECT c.id,c.name,p.approved,p.style_instructions,p.default_price_cents,p.revision
        FROM vp3_media_collections_v1 c LEFT JOIN profile_image_catalogs_v1 p
            ON p.owner_user_id=c.owner_user_id AND p.collection_id=c.id
        WHERE c.owner_user_id=? AND c.id=?'.vp3_profile_image_catalog_read_lock_v1($pdo));
    $query->execute([$owner,$collection]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new DomainException('Choose one of your Media collections.', 404);
    return ['id'=>(int)$row['id'], 'name'=>(string)$row['name'], 'approved'=>(int)($row['approved']??0)===1,
        'style_instructions'=>(string)($row['style_instructions']??''),
        'default_price_cents'=>(int)($row['default_price_cents']??0), 'revision'=>(int)($row['revision']??0)];
}

function vp3_profile_image_catalog_ref_rows_v1(PDO $pdo, int $owner, int $collection): array
{
    $query = $pdo->prepare('SELECT r.*,a.title,a.mime_type AS current_mime_type,a.file_size,
            a.file_path AS current_file_path,a.updated_at AS current_updated_at,
            a.media_type,a.source,m.asset_id AS member_asset_id
        FROM profile_image_catalog_refs_v1 r LEFT JOIN user_media_assets a ON a.user_id=r.owner_user_id AND a.id=r.asset_id
        LEFT JOIN vp3_media_collection_assets_v1 m ON m.owner_user_id=r.owner_user_id AND m.collection_id=r.collection_id AND m.asset_id=r.asset_id
        WHERE r.owner_user_id=? AND r.collection_id=? ORDER BY r.sequence_no,r.asset_id'.vp3_profile_image_catalog_read_lock_v1($pdo));
    $query->execute([$owner,$collection]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function vp3_profile_image_catalog_price_v1(array $row, int $default): int
{
    return $row['price_override_cents']===null ? $default : (int)$row['price_override_cents'];
}

function vp3_profile_image_catalog_asset_supported_v1(array $row): bool
{
    return ($row['media_type']??null)==='photo'
        && in_array($row['current_mime_type']??$row['mime_type']??null,['image/jpeg','image/png','image/webp'],true)
        && (int)($row['file_size']??0)>0 && (int)$row['file_size']<=VP3_PROFILE_IMAGE_CATALOG_REFERENCE_BYTES_V1;
}

/** Reject metadata changes without reading every image on each public chat poll. */
function vp3_profile_image_catalog_source_current_v1(array $row, int $owner): bool
{
    if (!vp3_profile_image_catalog_asset_supported_v1($row)) return false;
    $revision=hash('sha256',json_encode([$owner,(int)$row['asset_id'],(string)($row['current_file_path']??''),
        (string)($row['current_mime_type']??''),(int)$row['file_size'],(string)($row['current_updated_at']??''),
        (string)$row['source_sha256']],JSON_THROW_ON_ERROR));
    return hash_equals((string)$row['source_revision'],$revision);
}

function vp3_profile_image_catalog_owner_asset_v1(array $row, ?array $reference, int $default): array
{
    $id = (int)($row['id']??$row['asset_id']);
    $available = vp3_profile_image_catalog_asset_supported_v1($row)
        && (!array_key_exists('member_asset_id',$row) || (int)$row['member_asset_id']===$id);
    return ['asset_id'=>$id, 'title'=>(string)($row['title']??''),
        'mime_type'=>(string)($row['current_mime_type']??$row['mime_type']??''), 'byte_size'=>(int)($row['file_size']??0),
        'url'=>media_studio_asset_url($id), 'approved'=>$reference!==null, 'available'=>$available,
        'price_override_cents'=>$reference===null||$reference['price_override_cents']===null ? null : (int)$reference['price_override_cents'],
        'effective_price_cents'=>$reference===null ? $default : vp3_profile_image_catalog_price_v1($reference,$default)];
}

/** Owner-only metadata and existing authenticated previews. Never returns private storage paths. */
function vp3_profile_image_catalog_owner_state_v1(PDO $pdo, array $user, int $collection=0): array
{
    $owner = (int)($user['id']??0);
    vp3_profile_image_catalog_live_owner_v1($pdo,$owner);
    $settings = vp3_profile_image_catalog_settings_v1($pdo,$owner);
    $state = ['available'=>$settings['available'], 'owner_user_id'=>$owner, 'settings'=>$settings,
        'collections'=>[], 'assets'=>[], 'selected_collection_id'=>0, 'assets_truncated'=>false];
    if (!$settings['available']) return $state;
    $collections = vp3_media_collections_list_v1($pdo,$owner);
    $selectedReferences = [];
    $selectedDefault = 0;
    if ($collection===0 && $collections) $collection=(int)$collections[0]['id'];
    if ($collection>0) vp3_media_collection_require_v1($pdo,$owner,$collection);
    foreach ($collections as $entry) {
        $row = vp3_profile_image_catalog_row_v1($pdo,$owner,$entry['id']);
        $references = vp3_profile_image_catalog_ref_rows_v1($pdo,$owner,$entry['id']);
        $row['count'] = $entry['count'];
        $row['references'] = array_map(static fn(array $reference):array=>
            vp3_profile_image_catalog_owner_asset_v1($reference,$reference,$row['default_price_cents']), $references);
        $state['collections'][]=$row;
        if ($entry['id']===$collection) {
            foreach ($references as $reference) $selectedReferences[(int)$reference['asset_id']]=$reference;
            $selectedDefault=$row['default_price_cents'];
        }
    }
    if ($collection<1) return $state;
    $state['selected_collection_id']=$collection;
    $query = $pdo->prepare("SELECT a.id,a.title,a.media_type,a.mime_type,a.file_size,a.source
        FROM vp3_media_collection_assets_v1 m JOIN user_media_assets a ON a.user_id=m.owner_user_id AND a.id=m.asset_id
        WHERE m.owner_user_id=? AND m.collection_id=? AND a.media_type='photo' ORDER BY a.id DESC LIMIT 201");
    $query->execute([$owner,$collection]);
    $assets=$query->fetchAll(PDO::FETCH_ASSOC);
    $state['assets_truncated']=count($assets)>200;
    $seen=[];
    foreach (array_slice($assets,0,200) as $asset) {
        $id=(int)$asset['id'];$seen[$id]=true;
        $state['assets'][]=vp3_profile_image_catalog_owner_asset_v1($asset,$selectedReferences[$id]??null,$selectedDefault);
    }
    // Retain saved exemplars in the editor even when they fall outside this bounded library page.
    foreach ($selectedReferences as $id=>$reference) if (!isset($seen[$id])) {
        $state['assets'][]=vp3_profile_image_catalog_owner_asset_v1($reference,$reference,$selectedDefault);
    }
    return $state;
}

/** Save explicit owner settings. Prices are configuration only; this release creates no payment. */
function vp3_profile_image_catalog_save_v1(PDO $pdo, array $user, array $input): array
{
    $owner=(int)($user['id']??0);
    if (!vp3_profile_image_catalog_ready_v1($pdo)) throw new RuntimeException('Run Database Upgrade to enable Profile image settings.',503);
    $action=$input['action']??null;
    if (!in_array($action,['save_settings','save_catalog'],true)) throw new InvalidArgumentException('Choose a Profile image settings action.',422);
    $expected=vp3_profile_image_catalog_integer_v1($input['expected_revision']??null,0,PHP_INT_MAX,'Refresh these settings before saving.');
    $selected=0;
    vp3_profile_image_catalog_atomic_v1($pdo,static function()use($pdo,$user,$owner,$input,$action,$expected,&$selected):void {
        vp3_profile_image_catalog_lock_v1($pdo,$owner);
        vp3_profile_image_catalog_live_owner_v1($pdo,$owner);
        if ($action==='save_settings') {
            $settings=$input['settings']??null;
            if (!is_array($settings) || !is_bool($settings['enabled']??null)) throw new InvalidArgumentException('Choose whether visitors can edit images.',422);
            $daily=vp3_profile_image_catalog_integer_v1($settings['daily_limit']??null,1,100,'Choose a daily limit between 1 and 100 images.');
            $visitor=vp3_profile_image_catalog_integer_v1($settings['visitor_daily_limit']??null,1,20,'Choose a visitor daily limit between 1 and 20 images.');
            $current=vp3_profile_image_catalog_settings_v1($pdo,$owner);
            if ($current['revision']!==$expected) throw new DomainException('Image settings changed. Refresh before saving.',409);
            if ($expected===0) {
                $pdo->prepare('INSERT INTO profile_image_settings_v1 (owner_user_id,enabled,daily_limit,visitor_daily_limit,revision) VALUES (?,?,?,?,1)')
                    ->execute([$owner,$settings['enabled']?1:0,$daily,$visitor]);
            } else {
                $pdo->prepare('UPDATE profile_image_settings_v1 SET enabled=?,daily_limit=?,visitor_daily_limit=?,revision=revision+1 WHERE owner_user_id=?')
                    ->execute([$settings['enabled']?1:0,$daily,$visitor,$owner]);
            }
            return;
        }
        $selected=vp3_profile_image_catalog_integer_v1($input['collection_id']??null,1,PHP_INT_MAX,'Choose one of your Media collections.');
        $catalog=vp3_profile_image_catalog_row_v1($pdo,$owner,$selected);
        if ($catalog['revision']!==$expected) throw new DomainException('Catalog settings changed. Refresh before saving.',409);
        if (!is_bool($input['approved']??null)) throw new InvalidArgumentException('Choose whether this catalog is approved for visitor editing.',422);
        $instructions=$input['style_instructions']??null;
        if (!is_string($instructions) || !mb_check_encoding($instructions,'UTF-8') || mb_strlen($instructions,'UTF-8')>2000
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$instructions)) {
            throw new InvalidArgumentException('Use style instructions of up to 2,000 characters.',422);
        }
        $price=vp3_profile_image_catalog_integer_v1($input['default_price_cents']??null,0,VP3_PROFILE_IMAGE_CATALOG_PRICE_MAX_V1,'Choose a catalog price of zero or a positive amount.');
        $references=$input['references']??null;
        if (!is_array($references) || !array_is_list($references) || count($references)>3 || ($input['approved'] && !$references)) {
            throw new InvalidArgumentException('Choose one to three explicit style images before approving a catalog.',422);
        }
        $sources=[];$seen=[];$totalBytes=0;$existingReferences=[];
        foreach (vp3_profile_image_catalog_ref_rows_v1($pdo,$owner,$selected) as $existingReference) {
            $existingReferences[(int)$existingReference['asset_id']]=$existingReference;
        }
        foreach ($references as $reference) {
            if (!is_array($reference)) throw new InvalidArgumentException('Choose valid style image settings.',422);
            $id=vp3_profile_image_catalog_integer_v1($reference['asset_id']??null,1,PHP_INT_MAX,'Choose a style image from this collection.');
            if (isset($seen[$id])) throw new InvalidArgumentException('Choose each style image only once.',422);
            $seen[$id]=true;
            if (!array_key_exists('price_override_cents',$reference)) throw new InvalidArgumentException('Choose an image price or inherit the catalog price.',422);
            $override=$reference['price_override_cents'];
            if ($override!==null) vp3_profile_image_catalog_integer_v1($override,0,VP3_PROFILE_IMAGE_CATALOG_PRICE_MAX_V1,'Choose a valid image price or inherit the catalog price.');
            $query=$pdo->prepare('SELECT a.* FROM vp3_media_collection_assets_v1 m JOIN user_media_assets a
                ON a.user_id=m.owner_user_id AND a.id=m.asset_id WHERE m.owner_user_id=? AND m.collection_id=? AND m.asset_id=?');
            $query->execute([$owner,$selected,$id]);$asset=$query->fetch(PDO::FETCH_ASSOC);
            if (!$asset) throw new DomainException('A selected image no longer belongs to this collection.',404);
            if (!$input['approved'] && isset($existingReferences[$id])) {
                // Revoking an existing grant must still work after its source file changes or disappears.
                // A later approval always takes the verified-source branch and freezes fresh bytes.
                $old=$existingReferences[$id];
                $source=['asset_id'=>$id,'sha256'=>$old['source_sha256'],'revision'=>$old['source_revision'],
                    'mime_type'=>$old['mime_type'],'byte_size'=>(int)$old['byte_size'],'width'=>(int)$old['width'],'height'=>(int)$old['height']];
            } else {
                if (!vp3_profile_image_catalog_asset_supported_v1($asset)) throw new DomainException('Choose JPEG, PNG or WebP style images of up to 4 MB each.',422);
                $source=vp3_image_reference_source_v1($pdo,$user,$id,true);
                $source['byte_size']=strlen($source['bytes']);unset($source['bytes']);
            }
            $totalBytes+=$source['byte_size'];
            if ($totalBytes>VP3_PROFILE_IMAGE_CATALOG_REFERENCE_BYTES_V1) throw new DomainException('Choose up to three style images totaling 4 MB or less.',422);
            $sources[]=['source'=>$source,'price_override_cents'=>$override];
        }
        $values=[$input['approved']?1:0,trim($instructions),$price];
        if ($expected===0) {
            $pdo->prepare('INSERT INTO profile_image_catalogs_v1 (owner_user_id,collection_id,approved,style_instructions,default_price_cents,revision) VALUES (?,?,?,?,?,1)')
                ->execute([$owner,$selected,...$values]);
        } else {
            $pdo->prepare('UPDATE profile_image_catalogs_v1 SET approved=?,style_instructions=?,default_price_cents=?,revision=revision+1 WHERE owner_user_id=? AND collection_id=?')
                ->execute([...$values,$owner,$selected]);
        }
        $pdo->prepare('DELETE FROM profile_image_catalog_refs_v1 WHERE owner_user_id=? AND collection_id=?')->execute([$owner,$selected]);
        $query=$pdo->prepare('INSERT INTO profile_image_catalog_refs_v1 (owner_user_id,collection_id,asset_id,sequence_no,price_override_cents,source_sha256,source_revision,mime_type,byte_size,width,height) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($sources as $sequence=>$item) {
            $source=$item['source'];
            $query->execute([$owner,$selected,$source['asset_id'],$sequence+1,$item['price_override_cents'],$source['sha256'],
                $source['revision'],$source['mime_type'],$source['byte_size'],$source['width'],$source['height']]);
        }
    });
    return vp3_profile_image_catalog_owner_state_v1($pdo,$user,$selected);
}

/** Names and exact approved free choices only. Private owner preview URLs never cross this boundary. */
function vp3_profile_image_catalog_public_v1(PDO $pdo, int $owner): array
{
    $settings=vp3_profile_image_catalog_settings_v1($pdo,$owner);
    if (!$settings['available'] || !$settings['enabled']) return [];
    try { vp3_profile_image_catalog_live_owner_v1($pdo,$owner); } catch (DomainException) { return []; }
    $query=$pdo->prepare('SELECT p.collection_id,p.default_price_cents,p.revision,c.name FROM profile_image_catalogs_v1 p
        JOIN vp3_media_collections_v1 c ON c.owner_user_id=p.owner_user_id AND c.id=p.collection_id
        WHERE p.owner_user_id=? AND p.approved=1 ORDER BY c.name,c.id LIMIT 100');
    $query->execute([$owner]);$out=[];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $catalog) {
        $references=[];
        foreach (vp3_profile_image_catalog_ref_rows_v1($pdo,$owner,(int)$catalog['collection_id']) as $reference) {
            if ((int)$reference['member_asset_id']!==(int)$reference['asset_id'] || !vp3_profile_image_catalog_source_current_v1($reference,$owner)
                || vp3_profile_image_catalog_price_v1($reference,(int)$catalog['default_price_cents'])!==0) continue;
            $references[]=['asset_id'=>(int)$reference['asset_id'],'title'=>(string)$reference['title'],'price_cents'=>0];
        }
        if ($references) $out[]=['collection_id'=>(int)$catalog['collection_id'],'name'=>(string)$catalog['name'],
            'catalog_revision'=>(int)$catalog['revision'],'price_cents'=>0,'references'=>$references];
    }
    return $out;
}

/** Explicit public reference URLs carry no visitor conversation or private file authority. */
function vp3_profile_image_catalog_preview_urls_v1(array $catalogs, string $username): array
{
    foreach ($catalogs as &$catalog) {
        foreach ($catalog['references'] as &$reference) {
            $reference['preview_url']=url('/api/profile-agent.php').'?'.http_build_query([
                'action'=>'reference_image','username'=>$username,'catalog_id'=>$catalog['collection_id'],
                'image_id'=>$reference['asset_id'],'catalog_revision'=>$catalog['catalog_revision'],
            ],'','&',PHP_QUERY_RFC3986);
        }
        unset($reference);
    }
    unset($catalog);
    return $catalogs;
}

/** A public gallery read must not establish a visitor session or impersonate its owner. */
function vp3_profile_image_catalog_public_profile_v1(PDO $pdo, array $profile): array
{
    $owner=(int)($profile['user_id']??0);
    $fresh=$owner>0?profile_for_user($pdo,$owner,false):null;
    if (!$fresh || !hash_equals((string)($profile['username']??''),(string)($fresh['username']??''))
        || empty($fresh['is_active']) || empty($fresh['is_public']) || empty($fresh['profile_agent_enabled'])) {
        throw new DomainException('This public image collection is unavailable.',404);
    }
    $user=vp3_profile_image_catalog_live_owner_v1($pdo,$owner);
    $query=$pdo->prepare('SELECT id,owner_user_id,is_active FROM user_agents WHERE id=? AND owner_user_id=?');
    $query->execute([(int)($fresh['profile_agent_id']??0),$owner]);$agent=$query->fetch(PDO::FETCH_ASSOC);
    if (!$agent || empty($agent['is_active'])) throw new DomainException('This public image collection is unavailable.',404);
    if (!function_exists('vp3_observation_personal_permission_v1')) require_once __DIR__.'/agent-observation-authority-v1.php';
    if (!vp3_observation_personal_permission_v1($pdo,$user,'profile_agent.access')
        || !vp3_observation_personal_permission_v1($pdo,$user,'profile_chat.access')) {
        throw new DomainException('This public image collection is unavailable.',404);
    }
    return ['profile'=>$fresh,'owner'=>$user,'agent'=>$agent];
}

/** Pure reads: do not reuse catalog_resolved_v1, which acquires a writer lock for editing. */
function vp3_profile_image_catalog_public_reference_v1(PDO $pdo, int $owner, int $collection, int $asset, int $revision): array
{
    if ($collection<1 || $asset<1 || $revision<1) throw new DomainException('Reference image not found.',404);
    $settings=vp3_profile_image_catalog_settings_v1($pdo,$owner);
    if (!$settings['available'] || !$settings['enabled']) throw new DomainException('Reference image not found.',404);
    $catalog=vp3_profile_image_catalog_row_v1($pdo,$owner,$collection);
    if (!$catalog['approved'] || $catalog['revision']!==$revision) throw new DomainException('Reference image not found.',404);
    foreach (vp3_profile_image_catalog_ref_rows_v1($pdo,$owner,$collection) as $reference) {
        if ((int)$reference['asset_id']!==$asset) continue;
        if ((int)$reference['member_asset_id']!==$asset || !vp3_profile_image_catalog_source_current_v1($reference,$owner)
            || vp3_profile_image_catalog_price_v1($reference,$catalog['default_price_cents'])!==0) break;
        return $reference;
    }
    throw new DomainException('Reference image not found.',404);
}

/** Return only the exact raster that the owner approved for this live public free catalog. */
function vp3_profile_image_catalog_public_file_v1(PDO $pdo, array $profile, int $collection, int $asset, int $revision): array
{
    $authority=vp3_profile_image_catalog_public_profile_v1($pdo,$profile);$owner=(int)$authority['owner']['id'];
    $reference=vp3_profile_image_catalog_public_reference_v1($pdo,$owner,$collection,$asset,$revision);
    $source=vp3_image_reference_source_v1($pdo,$authority['owner'],$asset,false);
    if (!hash_equals((string)$reference['source_sha256'],$source['sha256'])
        || !hash_equals((string)$reference['source_revision'],$source['revision'])
        || (int)$reference['byte_size']!==strlen($source['bytes']) || $reference['mime_type']!==$source['mime_type']
        || (int)$reference['width']!==$source['width'] || (int)$reference['height']!==$source['height']) {
        throw new DomainException('Reference image not found.',404);
    }
    // Source reads can take time. Recheck current authority and the full grant before emitting pixels.
    $current=vp3_profile_image_catalog_public_profile_v1($pdo,$profile);
    if ((int)$current['agent']['id']!==(int)$authority['agent']['id']
        || vp3_profile_image_catalog_public_reference_v1($pdo,$owner,$collection,$asset,$revision)!==$reference) {
        throw new DomainException('Reference image not found.',404);
    }
    $extension=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$source['mime_type']];
    return ['bytes'=>$source['bytes'],'mime_type'=>$source['mime_type'],'filename'=>'reference-image-'.$asset.'.'.$extension];
}

/** Freeze explicit public approval, zero pricing and the actual source bytes before admission. */
function vp3_profile_image_catalog_resolved_v1(PDO $pdo, int $owner, int $collection, int $asset=0, bool $pixels=false): array
{
    if (!vp3_profile_image_catalog_ready_v1($pdo)) throw new RuntimeException('Profile image settings are unavailable.',503);
    return vp3_profile_image_catalog_atomic_v1($pdo,static function()use($pdo,$owner,$collection,$asset,$pixels):array {
        vp3_profile_image_catalog_lock_v1($pdo,$owner);
        $user=vp3_profile_image_catalog_live_owner_v1($pdo,$owner);
        $settings=vp3_profile_image_catalog_settings_v1($pdo,$owner);
        if (!$settings['enabled']) throw new DomainException('This Profile image service is unavailable.',409);
        if ($collection<1 || $asset<0) throw new InvalidArgumentException('Choose an approved free image catalog.',422);
        $catalog=vp3_profile_image_catalog_row_v1($pdo,$owner,$collection);
        if (!$catalog['approved']) throw new DomainException('This catalog is not approved for visitor image editing.',409);
        $metadata=[];$sources=[];$totalBytes=0;
        foreach (vp3_profile_image_catalog_ref_rows_v1($pdo,$owner,$collection) as $reference) {
            $id=(int)$reference['asset_id'];
            if ($asset>0 && $asset!==$id) continue;
            // Catalog mode deliberately chooses only the explicitly approved zero-priced set.
            if (vp3_profile_image_catalog_price_v1($reference,$catalog['default_price_cents'])!==0) {
                if ($asset>0) throw new DomainException('Only free images are available for visitor editing.',409);
                continue;
            }
            if ((int)$reference['member_asset_id']!==$id || !vp3_profile_image_catalog_asset_supported_v1($reference)) {
                throw new DomainException('An approved style image is no longer available. The owner must approve the catalog again.',409);
            }
            $source=vp3_image_reference_source_v1($pdo,$user,$id,true);
            $size=strlen($source['bytes']);$totalBytes+=$size;
            if (!hash_equals((string)$reference['source_sha256'],$source['sha256'])
                || !hash_equals((string)$reference['source_revision'],$source['revision'])
                || (int)$reference['byte_size']!==$size || $reference['mime_type']!==$source['mime_type']
                || (int)$reference['width']!==$source['width'] || (int)$reference['height']!==$source['height']) {
                throw new DomainException('An approved style image changed. The owner must approve the catalog again.',409);
            }
            $view=['asset_id'=>$id,'title'=>$source['title'],'mime_type'=>$source['mime_type'],'byte_size'=>$size,
                'sha256'=>$source['sha256'],'revision'=>$source['revision'],'width'=>$source['width'],'height'=>$source['height']];
            $metadata[]=$view;
            $sources[]=$pixels ? $view+['image_url'=>'data:'.$source['mime_type'].';base64,'.base64_encode($source['bytes'])] : $view;
            unset($source);
        }
        if (!$metadata || count($metadata)>3 || $totalBytes>VP3_PROFILE_IMAGE_CATALOG_REFERENCE_BYTES_V1) {
            throw new DomainException('Choose an approved free style with up to three images totaling 4 MB or less.',409);
        }
        $snapshot=['snapshot_version'=>1,'owner_user_id'=>$owner,'collection_id'=>$collection,'catalog_name'=>$catalog['name'],
            'catalog_revision'=>$catalog['revision'],'selection_asset_id'=>$asset,'instructions'=>$catalog['style_instructions'],
            'price_cents'=>0,'references'=>$metadata];
        return ['snapshot'=>$snapshot,'references'=>$sources];
    });
}

function vp3_profile_image_catalog_resolve_v1(PDO $pdo, int $owner, int $collection, int $asset=0): array
{
    return vp3_profile_image_catalog_resolved_v1($pdo,$owner,$collection,$asset)['snapshot'];
}

/** Returns verified reference metadata, with image_url data URLs only for an actual provider dispatch. */
function vp3_profile_image_catalog_recheck_v1(PDO $pdo, int $owner, array $snapshot, bool $pixels=false): array
{
    if (($snapshot['snapshot_version']??null)!==1 || ($snapshot['owner_user_id']??null)!==$owner
        || !is_int($snapshot['collection_id']??null) || !is_int($snapshot['selection_asset_id']??null)
        || ($snapshot['price_cents']??null)!==0) throw new DomainException('This approved image catalog changed.',409);
    $current=vp3_profile_image_catalog_resolved_v1($pdo,$owner,$snapshot['collection_id'],$snapshot['selection_asset_id'],$pixels);
    if ($current['snapshot']!==$snapshot) throw new DomainException('This approved image catalog changed. Start a new request.',409);
    return $current['references'];
}
