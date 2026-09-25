<?php
declare(strict_types=1);

/**
 * HomeServer v2.4 Section 1 — federated data authority and canonical identity.
 *
 * Native records remain authoritative in their native store. This layer keeps
 * only account-scoped federation metadata: canonical IDs, observed mirrors,
 * revisions and per-dataset cursors. It never copies a mirror back into a
 * native table.
 */
const VP3_HOMESERVER_FEDERATED_DATA_V240='vp3-homeserver-federated-data-v240-20260925';
const VP3_HOMESERVER_FEDERATED_DATA_VERSION='2.4';

function homeserver_federated_v240_datasets(): array
{
    return ['memory','knowledge','contacts','tasks','notifications','profile_context'];
}

function homeserver_federated_v240_sources(): array
{
    return ['vp3_cloud','homeserver'];
}

function homeserver_federated_v240_ensure_schema(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_federated_records (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      authority_source VARCHAR(40) NOT NULL,
      dataset VARCHAR(60) NOT NULL,
      authority_key VARCHAR(180) NOT NULL,
      canonical_id CHAR(45) NOT NULL,
      observed_source VARCHAR(40) NOT NULL,
      record_hash CHAR(64) NOT NULL DEFAULT '',
      source_updated_at VARCHAR(80) NULL,
      tombstoned TINYINT(1) NOT NULL DEFAULT 0,
      first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_hs_federated_authority (user_id,authority_source,dataset,authority_key,observed_source),
      UNIQUE KEY uq_hs_federated_canonical (user_id,canonical_id,observed_source),
      INDEX idx_hs_federated_dataset (user_id,dataset,authority_source,last_seen_at),
      CONSTRAINT fk_hs_federated_records_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_federated_cursors (
      user_id INT UNSIGNED NOT NULL,
      peer_source VARCHAR(40) NOT NULL,
      dataset VARCHAR(60) NOT NULL,
      revision VARCHAR(128) NOT NULL DEFAULT '',
      cursor VARCHAR(256) NOT NULL DEFAULT '',
      last_sync_at DATETIME NULL,
      last_success_at DATETIME NULL,
      last_error VARCHAR(500) NOT NULL DEFAULT '',
      PRIMARY KEY(user_id,peer_source,dataset),
      CONSTRAINT fk_hs_federated_cursors_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function homeserver_federated_v240_schema_ready(): bool
{
    return function_exists('table_exists')
      && table_exists('homeserver_federated_records')
      && table_exists('homeserver_federated_cursors');
}

function homeserver_federated_v240_text(mixed $value,int $max): string
{
    return mb_strimwidth(trim(preg_replace('/s+/u',' ',(string)$value)??(string)$value),0,$max,'');
}

function homeserver_federated_v240_source(string $source): string
{
    $source=strtolower(homeserver_federated_v240_text($source,40));
    if(!in_array($source,homeserver_federated_v240_sources(),true))throw new RuntimeException('Unknown federated data authority source.');
    return $source;
}

function homeserver_federated_v240_dataset(string $dataset): string
{
    $dataset=strtolower(homeserver_federated_v240_text($dataset,60));
    if(!in_array($dataset,homeserver_federated_v240_datasets(),true))throw new RuntimeException('Unknown federated data dataset.');
    return $dataset;
}

function homeserver_federated_v240_canonical_id(string $authoritySource,string $dataset,mixed $authorityKey): string
{
    $source=homeserver_federated_v240_source($authoritySource);
    $name=homeserver_federated_v240_dataset($dataset);
    $key=homeserver_federated_v240_text($authorityKey,180);
    if($key==='')throw new RuntimeException('Federated data authority key is required.');
    return 'fd24_'.substr(hash('sha256',$source.'|'.$name.'|'.$key),0,40);
}

function homeserver_federated_v240_revision(string $title,string $content,?string $updatedAt=null): string
{
    $body=[
      'title'=>homeserver_federated_v240_text($title,240),
      'content'=>homeserver_federated_v240_text($content,6000),
      'updated_at'=>homeserver_federated_v240_text($updatedAt??'',80),
    ];
    return hash('sha256',json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}');
}

function homeserver_federated_v240_envelope(
    string $authoritySource,string $dataset,mixed $authorityKey,
    string $title='',string $content='',?string $updatedAt=null
): array {
    $source=homeserver_federated_v240_source($authoritySource);
    $name=homeserver_federated_v240_dataset($dataset);
    $key=homeserver_federated_v240_text($authorityKey,180);
    $title=homeserver_federated_v240_text($title,240);
    $content=homeserver_federated_v240_text($content,6000);
    $updated=homeserver_federated_v240_text($updatedAt??'',80);
    return [
      'federation_version'=>VP3_HOMESERVER_FEDERATED_DATA_VERSION,
      'canonical_id'=>homeserver_federated_v240_canonical_id($source,$name,$key),
      'authority_source'=>$source,
      'authority_key'=>$key,
      'dataset'=>$name,
      'title'=>$title,
      'content'=>$content,
      'updated_at'=>$updated!==''?$updated:null,
      'record_revision'=>homeserver_federated_v240_revision($title,$content,$updated),
      'mirror_only'=>$source!=='vp3_cloud',
    ];
}

function homeserver_federated_v240_normalize(array $record,string $defaultSource,string $dataset,int $index=0): array
{
    $source=homeserver_federated_v240_source((string)($record['authority_source']??$defaultSource));
    $name=homeserver_federated_v240_dataset($dataset);
    $key=homeserver_federated_v240_text($record['authority_key']??$record['key']??$record['id']??($name.':'.$index),180);
    $item=homeserver_federated_v240_envelope(
      $source,$name,$key,
      (string)($record['title']??$record['subject']??$record['name']??ucfirst($name)),
      (string)($record['content']??$record['text']??$record['body']??$record['description']??$record['notes']??''),
      (string)($record['updated_at']??$record['last_seen_at']??$record['created_at']??'')
    );
    $supplied=homeserver_federated_v240_text($record['canonical_id']??'',80);
    if($supplied!==''&&!hash_equals($item['canonical_id'],$supplied))throw new RuntimeException('Federated record canonical identity does not match its authority tuple.');
    return $item;
}

function homeserver_federated_v240_observe(int $userId,array $record,string $observedSource='vp3_cloud'): array
{
    if($userId<1)throw new RuntimeException('A signed-in user is required.');
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $source=homeserver_federated_v240_source((string)($record['authority_source']??''));
    $dataset=homeserver_federated_v240_dataset((string)($record['dataset']??''));
    $observed=homeserver_federated_v240_source($observedSource);
    $key=homeserver_federated_v240_text($record['authority_key']??'',180);
    $canonical=homeserver_federated_v240_canonical_id($source,$dataset,$key);
    $supplied=homeserver_federated_v240_text($record['canonical_id']??'',80);
    if($supplied!==''&&!hash_equals($canonical,$supplied))throw new RuntimeException('Federated record identity is invalid.');
    $revision=homeserver_federated_v240_text($record['record_revision']??'',64);
    if($revision==='')$revision=homeserver_federated_v240_revision((string)($record['title']??''),(string)($record['content']??''),(string)($record['updated_at']??''));
    $pdo->prepare("INSERT INTO homeserver_federated_records
      (user_id,authority_source,dataset,authority_key,canonical_id,observed_source,record_hash,source_updated_at,tombstoned,last_seen_at)
      VALUES (?,?,?,?,?,?,?,?,0,UTC_TIMESTAMP())
      ON DUPLICATE KEY UPDATE canonical_id=VALUES(canonical_id),record_hash=VALUES(record_hash),
        source_updated_at=VALUES(source_updated_at),tombstoned=0,last_seen_at=UTC_TIMESTAMP()")
      ->execute([$userId,$source,$dataset,$key,$canonical,$observed,$revision,homeserver_federated_v240_text($record['updated_at']??'',80)?:null]);
    return ['canonical_id'=>$canonical,'record_revision'=>$revision,'observed_source'=>$observed];
}

function homeserver_federated_v240_cursor(
    int $userId,string $peerSource,string $dataset,string $revision='',string $cursor='',bool $success=true,string $error=''
): void {
    $pdo=db();if(!$pdo||$userId<1)return;
    $peer=homeserver_federated_v240_source($peerSource);
    $name=homeserver_federated_v240_dataset($dataset);
    $pdo->prepare("INSERT INTO homeserver_federated_cursors
      (user_id,peer_source,dataset,revision,cursor,last_sync_at,last_success_at,last_error)
      VALUES (?,?,?,?,?,UTC_TIMESTAMP(),".($success?'UTC_TIMESTAMP()':'NULL').",?)
      ON DUPLICATE KEY UPDATE revision=VALUES(revision),cursor=VALUES(cursor),last_sync_at=UTC_TIMESTAMP(),
        last_success_at=".($success?'UTC_TIMESTAMP()':'last_success_at').",last_error=VALUES(last_error)")
      ->execute([$userId,$peer,$name,homeserver_federated_v240_text($revision,128),homeserver_federated_v240_text($cursor,256),homeserver_federated_v240_text($error,500)]);
}

function homeserver_federated_v240_observe_snapshot(int $userId,array $snapshot,string $observedSource): array
{
    $datasets=is_array($snapshot['datasets']??null)?$snapshot['datasets']:[];
    $authority=homeserver_federated_v240_source((string)($snapshot['authoritative_source']??($observedSource==='homeserver'?'homeserver':'vp3_cloud')));
    $revision=homeserver_federated_v240_text($snapshot['revision']??'',128);
    $counts=[];
    foreach(homeserver_federated_v240_datasets() as $dataset){
        $rows=is_array($datasets[$dataset]??null)?$datasets[$dataset]:[];
        $count=0;
        foreach($rows as $index=>$row){
            if(!is_array($row))continue;
            $item=homeserver_federated_v240_normalize($row,$authority,$dataset,(int)$index);
            homeserver_federated_v240_observe($userId,$item,$observedSource);
            $count++;
        }
        $counts[$dataset]=$count;
        homeserver_federated_v240_cursor($userId,$authority,$dataset,$revision,'',true,'');
    }
    return ['version'=>VP3_HOMESERVER_FEDERATED_DATA_VERSION,'observed'=>$counts];
}

function homeserver_federated_v240_registry(int $userId): array
{
    $pdo=db();$links=0;$cursors=[];
    if($pdo&&$userId>0&&homeserver_federated_v240_schema_ready()){
        $s=$pdo->prepare('SELECT COUNT(*) FROM homeserver_federated_records WHERE user_id=?');$s->execute([$userId]);$links=(int)$s->fetchColumn();
        $s=$pdo->prepare('SELECT peer_source,dataset,revision,cursor,last_sync_at,last_success_at,last_error FROM homeserver_federated_cursors WHERE user_id=? ORDER BY peer_source,dataset');$s->execute([$userId]);$cursors=$s->fetchAll()?:[];
    }
    return [
      'version'=>VP3_HOMESERVER_FEDERATED_DATA_VERSION,
      'mode'=>'native_authority_mirrored_continuity',
      'account_scoped_identity'=>true,
      'datasets'=>homeserver_federated_v240_datasets(),
      'sources'=>[
        ['source_id'=>'vp3_cloud','source_type'=>'cloud','native_writable'=>true,'remote_mirror_only'=>true],
        ['source_id'=>'homeserver','source_type'=>'local','native_writable'=>true,'remote_mirror_only'=>true],
      ],
      'rules'=>[
        'native_source_remains_authoritative'=>true,
        'remote_records_are_mirrors'=>true,
        'no_cross_database_id_writes'=>true,
        'canonical_identity'=>'sha256(authority_source|dataset|authority_key)',
        'conflict_resolution'=>'authority_wins',
      ],
      'mirror_link_count'=>$links,
      'sync_cursors'=>$cursors,
    ];
}


function homeserver_federated_v240_remote_registry(int $userId): ?array
{
    if($userId<1||!function_exists('homeserver_execution_v220_can_route')||!function_exists('homeserver_execution_v220_execute'))return null;
    if(!homeserver_execution_v220_can_route($userId,'federation.registry'))return null;
    try{
        $remote=homeserver_execution_v220_execute($userId,'federation.registry',[]);
        if(!is_array($remote)||(string)($remote['version']??'')!==VP3_HOMESERVER_FEDERATED_DATA_VERSION)return null;
        $rules=is_array($remote['rules']??null)?$remote['rules']:[];
        if(empty($rules['native_source_remains_authoritative'])||empty($rules['remote_records_are_mirrors'])||empty($rules['no_cross_database_id_writes']))return null;
        return $remote;
    }catch(Throwable $e){
        return null;
    }
}

function homeserver_federated_v240_status(int $userId): array
{
    return [
      'version'=>VP3_HOMESERVER_FEDERATED_DATA_VERSION,
      'cloud'=>homeserver_federated_v240_registry($userId),
      'homeserver'=>homeserver_federated_v240_remote_registry($userId),
    ];
}
