<?php
declare(strict_types=1);

/**
 * HomeServer v2.4 Section 7 — Disconnect/Reconnect Reconciliation.
 *
 * Native Cloud and HomeServer stores remain authoritative. This layer reconciles
 * only federation metadata after reconnect so stale remote mirrors disappear
 * without copying or overwriting native records.
 */
const VP3_HOMESERVER_RECONCILIATION_V246='vp3-homeserver-reconciliation-v246-20260926';

function homeserver_reconciliation_v246_ensure_schema(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_reconciliation_state_v246 (
      user_id INT UNSIGNED NOT NULL PRIMARY KEY,
      needs_reconciliation TINYINT(1) NOT NULL DEFAULT 1,
      last_disconnect_at DATETIME NULL,
      last_connected_at DATETIME NULL,
      last_reconciled_at DATETIME NULL,
      cloud_revision VARCHAR(128) NOT NULL DEFAULT '',
      homeserver_revision VARCHAR(128) NOT NULL DEFAULT '',
      last_run_id CHAR(32) NOT NULL DEFAULT '',
      last_error VARCHAR(500) NOT NULL DEFAULT '',
      last_summary_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_hs_reconciliation_state_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_reconciliation_runs_v246 (
      id CHAR(32) NOT NULL PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      peer_source VARCHAR(40) NOT NULL,
      observed_source VARCHAR(40) NOT NULL,
      snapshot_revision VARCHAR(128) NOT NULL DEFAULT '',
      snapshot_mode VARCHAR(20) NOT NULL,
      trigger_reason VARCHAR(120) NOT NULL DEFAULT 'exchange',
      status VARCHAR(20) NOT NULL,
      created_count INT UNSIGNED NOT NULL DEFAULT 0,
      updated_count INT UNSIGNED NOT NULL DEFAULT 0,
      restored_count INT UNSIGNED NOT NULL DEFAULT 0,
      unchanged_count INT UNSIGNED NOT NULL DEFAULT 0,
      tombstoned_count INT UNSIGNED NOT NULL DEFAULT 0,
      conflict_count INT UNSIGNED NOT NULL DEFAULT 0,
      dataset_summary_json LONGTEXT NULL,
      error VARCHAR(500) NOT NULL DEFAULT '',
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      completed_at DATETIME NULL,
      INDEX idx_hs_reconciliation_runs_user (user_id,started_at,id),
      CONSTRAINT fk_hs_reconciliation_runs_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function homeserver_reconciliation_v246_schema_ready(): bool
{
    return function_exists('table_exists')
      && table_exists('homeserver_reconciliation_state_v246')
      && table_exists('homeserver_reconciliation_runs_v246');
}

function homeserver_reconciliation_v246_text(mixed $value,int $max=500): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/u',' ',(string)$value)??(string)$value),0,$max,'');
}

function homeserver_reconciliation_v246_state(int $userId): array
{
    $pdo=db();if(!$pdo||$userId<1)return ['needs_reconciliation'=>true,'known'=>false];
    homeserver_reconciliation_v246_ensure_schema($pdo);
    $s=$pdo->prepare("SELECT * FROM homeserver_reconciliation_state_v246 WHERE user_id=? LIMIT 1");
    $s->execute([$userId]);$row=$s->fetch();
    if(!$row){
        return [
          'known'=>false,'needs_reconciliation'=>true,
          'last_disconnect_at'=>null,'last_connected_at'=>null,'last_reconciled_at'=>null,
          'cloud_revision'=>'','homeserver_revision'=>'','last_run_id'=>'','last_error'=>'',
          'last_summary'=>[],
        ];
    }
    $summary=json_decode((string)($row['last_summary_json']??''),true);if(!is_array($summary))$summary=[];
    return [
      'known'=>true,
      'needs_reconciliation'=>!empty($row['needs_reconciliation']),
      'last_disconnect_at'=>$row['last_disconnect_at']??null,
      'last_connected_at'=>$row['last_connected_at']??null,
      'last_reconciled_at'=>$row['last_reconciled_at']??null,
      'cloud_revision'=>(string)($row['cloud_revision']??''),
      'homeserver_revision'=>(string)($row['homeserver_revision']??''),
      'last_run_id'=>(string)($row['last_run_id']??''),
      'last_error'=>(string)($row['last_error']??''),
      'last_summary'=>$summary,
    ];
}

function homeserver_reconciliation_v246_mark_required(int $userId,string $reason=''): array
{
    $pdo=db();if(!$pdo||$userId<1)return ['needs_reconciliation'=>true];
    homeserver_reconciliation_v246_ensure_schema($pdo);
    $error=homeserver_reconciliation_v246_text($reason,500);
    $pdo->prepare("INSERT INTO homeserver_reconciliation_state_v246
      (user_id,needs_reconciliation,last_disconnect_at,last_error)
      VALUES (?,1,UTC_TIMESTAMP(),?)
      ON DUPLICATE KEY UPDATE needs_reconciliation=1,last_disconnect_at=UTC_TIMESTAMP(),last_error=VALUES(last_error)")
      ->execute([$userId,$error]);
    return homeserver_reconciliation_v246_state($userId);
}

function homeserver_reconciliation_v246_mark_connected(int $userId): array
{
    $pdo=db();if(!$pdo||$userId<1)return ['needs_reconciliation'=>true];
    homeserver_reconciliation_v246_ensure_schema($pdo);
    $before=homeserver_reconciliation_v246_state($userId);
    $pdo->prepare("INSERT INTO homeserver_reconciliation_state_v246
      (user_id,needs_reconciliation,last_connected_at,last_error)
      VALUES (?,1,UTC_TIMESTAMP(),'')
      ON DUPLICATE KEY UPDATE last_connected_at=UTC_TIMESTAMP(),last_error=''")
      ->execute([$userId]);
    $after=homeserver_reconciliation_v246_state($userId);
    $after['reconnected']=!empty($before['needs_reconciliation'])&&!empty($before['last_disconnect_at']);
    return $after;
}

function homeserver_reconciliation_v246_existing(
    int $userId,string $authority,string $dataset,string $key,string $observed
): ?array {
    $pdo=db();if(!$pdo)return null;
    $s=$pdo->prepare("SELECT record_hash,tombstoned FROM homeserver_federated_records
      WHERE user_id=? AND authority_source=? AND dataset=? AND authority_key=? AND observed_source=? LIMIT 1");
    $s->execute([$userId,$authority,$dataset,$key,$observed]);
    $row=$s->fetch();return $row?:null;
}

function homeserver_reconciliation_v246_reconcile_snapshot(
    int $userId,array $snapshot,string $observedSource='vp3_cloud',
    string $triggerReason='exchange',string $localRevision=''
): array {
    if($userId<1)throw new RuntimeException('A signed-in user is required.');
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    homeserver_federated_v240_ensure_schema($pdo);
    homeserver_reconciliation_v246_ensure_schema($pdo);

    $datasets=is_array($snapshot['datasets']??null)?$snapshot['datasets']:null;
    if(!is_array($datasets))throw new RuntimeException('Federated snapshot datasets are missing.');
    $authority=homeserver_federated_v240_source((string)($snapshot['authoritative_source']??'homeserver'));
    $observed=homeserver_federated_v240_source($observedSource);
    $mode=strtolower(homeserver_reconciliation_v246_text($snapshot['snapshot_mode']??'filtered',20));
    if(!in_array($mode,['full','filtered'],true))throw new RuntimeException('Federated snapshot mode must be full or filtered.');
    if($mode==='full'){
        $missing=[];
        foreach(homeserver_federated_v240_datasets() as $dataset)if(!is_array($datasets[$dataset]??null))$missing[]=$dataset;
        if($missing)throw new RuntimeException('Full reconciliation snapshot is missing datasets: '.implode(', ',$missing));
    }
    $revision=homeserver_reconciliation_v246_text($snapshot['revision']??'',128);
    $runId=bin2hex(random_bytes(16));
    $trigger=homeserver_reconciliation_v246_text($triggerReason,120)?:'exchange';
    $pdo->prepare("INSERT INTO homeserver_reconciliation_runs_v246
      (id,user_id,peer_source,observed_source,snapshot_revision,snapshot_mode,trigger_reason,status)
      VALUES (?,?,?,?,?,?,?,'running')")
      ->execute([$runId,$userId,$authority,$observed,$revision,$mode,$trigger]);

    $totals=['created'=>0,'updated'=>0,'restored'=>0,'unchanged'=>0,'tombstoned'=>0,'conflicts'=>0];
    $summaries=[];$normalized=[];

    try{
        // Prevalidate every row before touching mirrors.
        foreach(homeserver_federated_v240_datasets() as $dataset){
            if(!is_array($datasets[$dataset]??null))continue;
            $seen=[];$rows=[];
            foreach($datasets[$dataset] as $index=>$row){
                if(!is_array($row))continue;
                $item=homeserver_federated_v240_normalize($row,$authority,$dataset,(int)$index);
                if((string)$item['authority_source']!==$authority){
                    $totals['conflicts']++;
                    throw new RuntimeException('Full reconciliation snapshot mixed native authorities.');
                }
                $key=(string)$item['authority_key'];
                if(isset($seen[$key])){
                    $totals['conflicts']++;
                    throw new RuntimeException('Full reconciliation snapshot contains duplicate authority keys.');
                }
                $seen[$key]=true;$rows[]=$item;
            }
            $normalized[$dataset]=$rows;
        }

        foreach($normalized as $dataset=>$rows){
            $summary=['created'=>0,'updated'=>0,'restored'=>0,'unchanged'=>0,'tombstoned'=>0,'conflicts'=>0];
            $seen=[];
            foreach($rows as $item){
                $key=(string)$item['authority_key'];$seen[$key]=true;
                $before=homeserver_reconciliation_v246_existing($userId,$authority,$dataset,$key,$observed);
                homeserver_federated_v240_observe($userId,$item,$observed);
                if(!$before)$bucket='created';
                elseif(!empty($before['tombstoned']))$bucket='restored';
                elseif((string)($before['record_hash']??'')!==(string)($item['record_revision']??''))$bucket='updated';
                else $bucket='unchanged';
                $summary[$bucket]++;$totals[$bucket]++;
            }

            if($mode==='full'){
                $s=$pdo->prepare("SELECT authority_key FROM homeserver_federated_records
                  WHERE user_id=? AND authority_source=? AND dataset=? AND observed_source=? AND tombstoned=0");
                $s->execute([$userId,$authority,$dataset,$observed]);
                foreach($s->fetchAll()?:[] as $existing){
                    $key=homeserver_federated_v240_text($existing['authority_key']??'',180);
                    if($key!==''&&!isset($seen[$key])){
                        homeserver_federated_v240_mark_tombstone($userId,$authority,$dataset,$key,$observed);
                        $summary['tombstoned']++;$totals['tombstoned']++;
                    }
                }
            }
            $summaries[$dataset]=$summary;
            homeserver_federated_v240_cursor($userId,$authority,$dataset,$revision,'',true,'');
        }

        $summaryJson=json_encode($summaries,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}';
        $pdo->prepare("UPDATE homeserver_reconciliation_runs_v246 SET status='completed',
          created_count=?,updated_count=?,restored_count=?,unchanged_count=?,tombstoned_count=?,
          conflict_count=?,dataset_summary_json=?,completed_at=UTC_TIMESTAMP(),error='' WHERE id=?")
          ->execute([$totals['created'],$totals['updated'],$totals['restored'],$totals['unchanged'],$totals['tombstoned'],$totals['conflicts'],$summaryJson,$runId]);

        if($mode==='full'){
            $local=homeserver_reconciliation_v246_text($localRevision,128);
            $cloudRevision=$authority==='vp3_cloud'?$revision:$local;
            $homeRevision=$authority==='homeserver'?$revision:'';
            $stateSummary=[
              'created'=>$totals['created'],'updated'=>$totals['updated'],'restored'=>$totals['restored'],
              'unchanged'=>$totals['unchanged'],'tombstoned'=>$totals['tombstoned'],'conflicts'=>$totals['conflicts'],
              'datasets'=>$summaries,
            ];
            $pdo->prepare("INSERT INTO homeserver_reconciliation_state_v246
              (user_id,needs_reconciliation,last_reconciled_at,cloud_revision,homeserver_revision,last_run_id,last_error,last_summary_json)
              VALUES (?,0,UTC_TIMESTAMP(),?,?,?,'',?)
              ON DUPLICATE KEY UPDATE needs_reconciliation=0,last_reconciled_at=UTC_TIMESTAMP(),
                cloud_revision=IF(VALUES(cloud_revision)<>'',VALUES(cloud_revision),cloud_revision),
                homeserver_revision=IF(VALUES(homeserver_revision)<>'',VALUES(homeserver_revision),homeserver_revision),
                last_run_id=VALUES(last_run_id),last_error='',last_summary_json=VALUES(last_summary_json)")
              ->execute([$userId,$cloudRevision,$homeRevision,$runId,json_encode($stateSummary,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
        }

        return [
          'version'=>'2.4','contract'=>'v246','run_id'=>$runId,'peer_source'=>$authority,
          'observed_source'=>$observed,'snapshot_mode'=>$mode,'snapshot_revision'=>$revision,
          'status'=>'completed',...$totals,'datasets'=>$summaries,
        ];
    }catch(Throwable $e){
        $error=homeserver_reconciliation_v246_text($e->getMessage(),500);
        $pdo->prepare("UPDATE homeserver_reconciliation_runs_v246 SET status='failed',
          conflict_count=?,dataset_summary_json=?,error=?,completed_at=UTC_TIMESTAMP() WHERE id=?")
          ->execute([$totals['conflicts'],json_encode($summaries,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}',$error,$runId]);
        $pdo->prepare("INSERT INTO homeserver_reconciliation_state_v246
          (user_id,needs_reconciliation,last_run_id,last_error)
          VALUES (?,1,?,?)
          ON DUPLICATE KEY UPDATE needs_reconciliation=1,last_run_id=VALUES(last_run_id),last_error=VALUES(last_error)")
          ->execute([$userId,$runId,$error]);
        throw $e;
    }
}

function homeserver_reconciliation_v246_recent_runs(int $userId,int $limit=20): array
{
    $pdo=db();if(!$pdo||$userId<1)return [];
    homeserver_reconciliation_v246_ensure_schema($pdo);
    $limit=max(1,min(100,$limit));
    $s=$pdo->prepare("SELECT * FROM homeserver_reconciliation_runs_v246
      WHERE user_id=? ORDER BY started_at DESC,id DESC LIMIT ".$limit);
    $s->execute([$userId]);$out=[];
    foreach($s->fetchAll()?:[] as $row){
        $datasets=json_decode((string)($row['dataset_summary_json']??''),true);if(!is_array($datasets))$datasets=[];
        unset($row['dataset_summary_json']);$row['datasets']=$datasets;$out[]=$row;
    }
    return $out;
}

function homeserver_reconciliation_v246_diagnostics(int $userId): array
{
    return [
      'version'=>'2.4',
      'contract'=>'v246',
      'mode'=>'full_snapshot_authority_reconciliation',
      'state'=>homeserver_reconciliation_v246_state($userId),
      'recent_runs'=>homeserver_reconciliation_v246_recent_runs($userId,10),
      'rules'=>[
        'native_source_remains_authoritative'=>true,
        'filtered_snapshots_never_delete'=>true,
        'absence_tombstones_require_full_snapshot'=>true,
        'duplicate_authority_keys_rejected'=>true,
        'conflict_resolution'=>'authority_wins',
        'no_cross_database_id_writes'=>true,
      ],
    ];
}
