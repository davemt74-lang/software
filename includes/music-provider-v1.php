<?php
declare(strict_types=1);

/** ML2B: explicit site grants and anonymous provider evidence, never a payment ledger. */
const MUSIC_PROVIDER_V1_PROTOCOL = 'vp3_music_provider_v1';
const MUSIC_PROVIDER_V1_SCHEMA = 'music-provider-v1-20261126';

final class MusicProviderV1Exception extends RuntimeException
{
    public function __construct(public string $publicCode, public int $httpStatus, string $message, public array $details = [])
    { parent::__construct($message, $httpStatus); }
}

function music_provider_v1_error(string $code, int $status, string $message, array $details = []): never
{ throw new MusicProviderV1Exception($code, $status, $message, $details); }

function music_provider_v1_json(mixed $value): string
{
    if (is_array($value)) {
        if (!array_is_list($value)) ksort($value);
        foreach ($value as &$item) if (is_array($item)) $item=json_decode(music_provider_v1_json($item),true,32,JSON_THROW_ON_ERROR);
        unset($item);
    }
    return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
}

function music_provider_v1_uuid(mixed $value): string
{
    if (!is_string($value)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$value))
        music_provider_v1_error('invalid_request',422,'Use a valid resource or request identifier.');
    return strtolower($value);
}

function music_provider_v1_now(array $context = []): int
{
    $now=$context['now_ms']??(int)floor(microtime(true)*1000);
    if (!is_int($now)||$now<1) music_provider_v1_error('invalid_request',422,'The request clock is unavailable.');
    return $now;
}

/** Canonical configuration only. A visitor's Host header never defines provider identity. */
function music_provider_v1_base_url(): string
{
    global $config;
    $base=rtrim((string)($config['site']['base_url']??''),'/');
    $parts=parse_url($base);
    if (!$parts||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])
        ||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])
        ||preg_match('/[\x00-\x20\x7f\\\\]/',$base))
        music_provider_v1_error('provider_unconfigured',503,'Set the canonical HTTPS site URL before enabling music streaming.');
    $path=rtrim((string)($parts['path']??''),'/');
    $configured=rtrim((string)($config['site']['base_path']??''),'/');
    if ($configured!==''&&$configured[0]!=='/') $configured='/'.$configured;
    if ($path===''&&$configured!=='') $path=$configured;
    elseif ($configured!==''&&$path!==$configured)
        music_provider_v1_error('provider_unconfigured',503,'The canonical site URL and application path must agree.');
    if ($path!==''&&!preg_match('#^/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+$#D',$path))
        music_provider_v1_error('provider_unconfigured',503,'The canonical application path is unsupported.');
    $port=(int)($parts['port']??443);
    return 'https://'.strtolower($parts['host']).($port===443?'':':'.$port).$path;
}

function music_provider_v1_columns(PDO $pdo, string $table): array
{
    if (!preg_match('/^music_provider_[a-z0-9_]+$/D',$table)) return [];
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')
        return array_column($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name');
    $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]); return $q->fetchAll(PDO::FETCH_COLUMN);
}

function music_provider_v1_unique_keys(PDO $pdo, string $table): array
{
    if (!preg_match('/^music_provider_[a-z0-9_]+$/D',$table)) return [];
    $keys=[];
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
        $primary=[];
        foreach ($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC) as $column)
            if ((int)$column['pk']>0) $primary[(int)$column['pk']]=$column['name'];
        if ($primary) {ksort($primary);$keys[]=array_values($primary);}
        foreach ($pdo->query('PRAGMA index_list('.$table.')')->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if ((int)$index['unique']!==1||!empty($index['partial'])||!preg_match('/^[a-zA-Z0-9_]+$/D',$index['name'])) continue;
            $keys[]=array_column($pdo->query('PRAGMA index_info('.$index['name'].')')->fetchAll(PDO::FETCH_ASSOC),'name');
        }
    } else {
        $q=$pdo->prepare('SELECT INDEX_NAME,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND NON_UNIQUE=0 ORDER BY INDEX_NAME,SEQ_IN_INDEX');
        $q->execute([$table]);$indexes=[];$partial=[];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $indexes[$row['INDEX_NAME']][]=$row['COLUMN_NAME'];
            if ($row['SUB_PART']!==null) $partial[$row['INDEX_NAME']]=true;
        }
        foreach ($indexes as $name=>$columns) if (!isset($partial[$name])) $keys[]=$columns;
    }
    return $keys;
}

function music_provider_v1_schema_statements(string $driver): array
{
    if (!in_array($driver,['sqlite','mysql'],true)) music_provider_v1_error('schema_unavailable',503,'This database is unsupported.');
    $tail=$driver==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    return array_merge([
        'CREATE TABLE IF NOT EXISTS music_provider_settings_v1 (id INTEGER NOT NULL PRIMARY KEY, installation_id CHAR(36) NOT NULL UNIQUE, signing_key CHAR(64) NOT NULL, created_ms BIGINT NOT NULL)'.$tail,
    ],music_provider_v1_management_schema_statements($driver),music_provider_v1_playback_schema_statements($driver));
}

/** Inspect the actual unique and foreign-key constraints, not just table names. */
function music_provider_v1_constraints_ready(PDO $pdo): bool
{
    foreach (music_provider_v1_schema_statements((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) as $sql) {
        if (!preg_match('/CREATE TABLE IF NOT EXISTS (music_provider_[a-z0-9_]+)/',$sql,$table)) continue;
        $name=$table[1];$expected=[];
        preg_match_all('/(?:PRIMARY KEY|UNIQUE(?: KEY\s+[a-z0-9_]+)?)\s*\(([^)]+)\)/i',$sql,$groups);
        foreach ($groups[1] as $group) $expected[]=array_map('trim',explode(',',$group));
        preg_match_all('/(?:^|[,(])\s*([a-z0-9_]+)\s+(?:[A-Z]+(?:\([^)]*\))?)(?:\s+NOT NULL)?\s+(PRIMARY KEY|UNIQUE)\b/i',$sql,$inline,PREG_SET_ORDER);
        foreach ($inline as $entry) $expected[]=[$entry[1]];
        $actual=music_provider_v1_unique_keys($pdo,$name);
        foreach ($expected as $columns) if (!in_array($columns,$actual,true)) return false;
        preg_match_all('/FOREIGN KEY\s*\(([^)]+)\)\s*REFERENCES\s+([a-z0-9_]+)\s*\(([^)]+)\)\s*ON DELETE\s+(CASCADE|RESTRICT)/i',$sql,$foreign,PREG_SET_ORDER);
        foreach ($foreign as $key) {
            $from=trim($key[1]);$parent=$key[2];$to=trim($key[3]);$rule=strtoupper($key[4]);$found=false;
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
                foreach ($pdo->query('PRAGMA foreign_key_list('.$name.')')->fetchAll(PDO::FETCH_ASSOC) as $row)
                    if ($row['table']===$parent&&$row['from']===$from&&$row['to']===$to&&strtoupper($row['on_delete'])===$rule) $found=true;
            } else {
                $q=$pdo->prepare('SELECT k.COLUMN_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME=?');
                $q->execute([$name,$parent]);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row)
                    if ($row['COLUMN_NAME']===$from&&$row['REFERENCED_COLUMN_NAME']===$to&&$row['DELETE_RULE']===$rule) $found=true;
            }
            if (!$found) return false;
        }
    }
    return true;
}

function music_provider_v1_schema_ready(PDO $pdo): bool
{
    try {
        $required=['music_provider_settings_v1'=>['id','installation_id','signing_key','created_ms']]
            +music_provider_v1_management_required_columns()+music_provider_v1_playback_required_columns();
        foreach ($required as $table=>$columns) if (array_diff($columns,music_provider_v1_columns($pdo,$table))) return false;
        if (!music_provider_v1_constraints_ready($pdo)||!music_song_metadata_v1_schema_ready($pdo)) return false;
        $row=$pdo->query('SELECT installation_id,signing_key FROM music_provider_settings_v1 WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        return is_array($row)&&preg_match('/^[a-f0-9-]{36}$/D',$row['installation_id'])===1&&preg_match('/^[a-f0-9]{64}$/D',$row['signing_key'])===1;
    } catch (Throwable) {return false;}
}

function music_provider_v1_require_schema(PDO $pdo): void
{
    // A web request has one connection. Repeated nested authority checks do not
    // need to rescan information_schema; explicit reports still inspect it fully.
    static $verified;
    $verified??=new WeakMap();
    if (!isset($verified[$pdo])) {
        if (!music_provider_v1_schema_ready($pdo)) music_provider_v1_error('schema_unavailable',503,'Install the Music Provider database upgrade before using connected streaming.');
        $verified[$pdo]=true;
    }
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'&&(int)$pdo->query('PRAGMA foreign_keys')->fetchColumn()!==1) {
        if ($pdo->inTransaction()) music_provider_v1_error('schema_unavailable',503,'Enable database integrity before starting a transaction.');
        $pdo->exec('PRAGMA foreign_keys=ON');
        if ((int)$pdo->query('PRAGMA foreign_keys')->fetchColumn()!==1) music_provider_v1_error('schema_unavailable',503,'Database integrity is unavailable.');
    }
}

function music_provider_v1_installation(PDO $pdo): array
{
    $row=$pdo->query('SELECT installation_id,signing_key FROM music_provider_settings_v1 WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if (!$row||!preg_match('/^[a-f0-9]{64}$/D',(string)$row['signing_key'])) music_provider_v1_error('schema_unavailable',503,'The provider identity needs its database upgrade.');
    return $row;
}
function music_provider_v1_setting(PDO $pdo): array {return music_provider_v1_installation($pdo);}

function music_provider_v1_atomic(PDO $pdo, callable $work): mixed
{
    $owned=!$pdo->inTransaction();$savepoint='music_provider_'.bin2hex(random_bytes(5));
    if ($owned) $pdo->beginTransaction(); else $pdo->exec('SAVEPOINT '.$savepoint);
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')
            $pdo->exec('UPDATE music_provider_settings_v1 SET created_ms=created_ms WHERE id=-1');
        $result=$work();
        if ($owned) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            if ($owned) $pdo->rollBack();
            else {$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}
        }
        throw $error;
    }
}

/** Additive, explicit upgrade only; no site, selection or historical play is invented. */
function music_provider_v1_install(PDO $pdo): array
{
    if ($pdo->inTransaction()) music_provider_v1_error('upgrade_transaction',409,'Run the provider upgrade outside a transaction.');
    if (!music_song_metadata_v1_schema_ready($pdo)) music_provider_v1_error('schema_unavailable',503,'Install the catalog metadata upgrade first.');
    $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver==='sqlite') $pdo->exec('PRAGMA foreign_keys=ON');
    foreach (music_provider_v1_schema_statements($driver) as $sql) $pdo->exec($sql);
    $insert=$driver==='sqlite'?'INSERT OR IGNORE INTO music_provider_settings_v1(id,installation_id,signing_key,created_ms) VALUES(1,?,?,?)'
        :'INSERT INTO music_provider_settings_v1(id,installation_id,signing_key,created_ms) VALUES(1,?,?,?) ON DUPLICATE KEY UPDATE id=id';
    $pdo->prepare($insert)->execute([music_song_metadata_v1_uuid(),bin2hex(random_bytes(32)),music_provider_v1_now()]);
    if (!music_provider_v1_schema_ready($pdo)) music_provider_v1_error('schema_unavailable',503,'The provider schema needs an integrity review.');
    return music_provider_v1_report($pdo);
}

/** Operational counts only. This never returns credentials, private identities or page URLs. */
function music_provider_v1_report(PDO $pdo): array
{
    $ready=music_provider_v1_schema_ready($pdo);
    $report=['ready'=>$ready,'protocol'=>MUSIC_PROVIDER_V1_PROTOCOL,'schema'=>MUSIC_PROVIDER_V1_SCHEMA,
        'visitor_access'=>'selected_public_recordings','personal_history_import'=>false,'royalty_accrual'=>false,
        'automatic_retention_deletion'=>false,'counts'=>[]];
    if (!$ready) return $report;
    foreach (array_keys(music_provider_v1_management_required_columns()+music_provider_v1_playback_required_columns()) as $table)
        $report['counts'][$table]=(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    return $report;
}

require_once __DIR__.'/music-provider-management-v1.php';
require_once __DIR__.'/music-provider-playback-v1.php';
