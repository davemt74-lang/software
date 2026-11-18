<?php
declare(strict_types=1);

/**
 * Forward-only recorded time for the native Profile event ledger.
 *
 * Loading this file and reading status never install storage. Only Setup /
 * Database Upgrade calls install. Legacy markers describe the rows actually
 * touched by that activation transaction; they do not estimate a UTC timestamp.
 */
const VP3_PROFILE_EVENT_CLOCK_CONTRACT_V1 = 'vp3.profile-event-clock.v1';

function vp3_profile_event_clock_millis_v1(mixed $value): ?int
{
    if (is_int($value)) return $value > 0 ? $value : null;
    if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,18}\z/D',$value)) return null;
    $number = (int)$value;
    return $number > 0 && (string)$number === $value ? $number : null;
}

function vp3_profile_event_clock_now_v1(): int
{
    $now = (int)floor(microtime(true) * 1000);
    return vp3_profile_event_clock_millis_v1($now)
        ?? throw new RuntimeException('Profile event recorded time is unavailable.');
}

function vp3_profile_event_clock_missing_v1(PDOException $error): bool
{
    $state = (string)($error->errorInfo[0] ?? $error->getCode());
    $native = (int)($error->errorInfo[1] ?? 0);
    return in_array($state,['42S02','42S22'],true)
        || ($native === 1 && (bool)preg_match('/no such (?:table|column):/i',$error->getMessage()));
}

function vp3_profile_event_clock_retryable_v1(PDOException $error): bool
{
    return (string)($error->errorInfo[0] ?? $error->getCode()) === '40001'
        || in_array((int)($error->errorInfo[1] ?? 0),[1205,1213],true);
}

/** A failed read is not permission to try a different INSERT after a write. */
function vp3_profile_event_clock_columns_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT recorded_at_utc_ms,clock_legacy_token_v1 FROM profile_events WHERE 1=0');
        return true;
    } catch (PDOException $error) {
        if (vp3_profile_event_clock_missing_v1($error)) return false;
        throw $error;
    }
}

/** Both real native writers must declare support; this helper alone is not proof. */
function vp3_profile_event_clock_writers_v1(): ?string
{
    // Definition-only modules. An already loaded old writer must remain visibly
    // unsupported instead of being redeclared or inferred from this helper.
    if (!function_exists('profile_event_create')) require_once __DIR__.'/profile-agent.php';
    if (!function_exists('profile_conversion_outcome_record_v179')) require_once __DIR__.'/profile-conversion-outcomes-v179.php';
    foreach (['VP3_PROFILE_EVENT_CREATE_CLOCK_CONTRACT_V1','VP3_PROFILE_CONVERSION_CLOCK_CONTRACT_V1'] as $constant) {
        if (!defined($constant) || constant($constant) !== VP3_PROFILE_EVENT_CLOCK_CONTRACT_V1) return null;
    }
    return VP3_PROFILE_EVENT_CLOCK_CONTRACT_V1;
}

/**
 * $current performs a current locking read on native MySQL when a caller is
 * already inside a repeatable-read snapshot. It never changes a row.
 */
function vp3_profile_event_clock_status_v1(PDO $pdo,bool $current=false): array
{
    $out = ['contract'=>VP3_PROFILE_EVENT_CLOCK_CONTRACT_V1,'availability'=>'unconfigured',
        'columns_available'=>false,'writer_contract'=>vp3_profile_event_clock_writers_v1(),'activation'=>null];
    try {
        if (!vp3_profile_event_clock_columns_v1($pdo)) return $out;
        $out['columns_available'] = true;
        try {
            $suffix = $current && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' LOCK IN SHARE MODE' : '';
            $rows = $pdo->query('SELECT id,contract,token,activated_at_utc_ms FROM profile_event_clock_activation_v1 ORDER BY id LIMIT 2'.$suffix)
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $error) {
            if (vp3_profile_event_clock_missing_v1($error)) return $out;
            throw $error;
        }
        if (!$rows) return $out;
        if (count($rows) !== 1 || (int)$rows[0]['id'] !== 1
            || $rows[0]['contract'] !== VP3_PROFILE_EVENT_CLOCK_CONTRACT_V1) {
            $out['availability'] = 'unavailable';
            return $out;
        }
        $row = $rows[0];
        if ($row['token'] === null && $row['activated_at_utc_ms'] === null) return $out;
        $at = vp3_profile_event_clock_millis_v1($row['activated_at_utc_ms']);
        if (!is_string($row['token']) || !preg_match('/\A[a-f0-9]{32}\z/D',$row['token']) || $at === null) {
            $out['availability'] = 'unavailable';
            return $out;
        }
        $out['activation'] = ['token'=>$row['token'],'activated_at_utc_ms'=>$at];
        if ($out['writer_contract'] !== null) $out['availability'] = 'ready';
        return $out;
    } catch (PDOException $error) {
        // A deadlock can roll back the caller's transaction. Preserve the exact
        // exception for the existing transaction retry boundary.
        if (vp3_profile_event_clock_retryable_v1($error)) throw $error;
        $out['availability'] = 'unavailable';
        return $out;
    }
}

function vp3_profile_event_clock_ready_v1(PDO $pdo): bool
{
    return vp3_profile_event_clock_status_v1($pdo)['availability'] === 'ready';
}

/** Installer-only, safe when another installer has just added the same column. */
function vp3_profile_event_clock_add_column_v1(PDO $pdo,string $column,string $definition): void
{
    if (!in_array($column,['recorded_at_utc_ms','clock_legacy_token_v1'],true)) {
        throw new LogicException('Invalid Profile event clock column.');
    }
    try {
        $pdo->query('SELECT '.$column.' FROM profile_events WHERE 1=0');
        return;
    } catch (PDOException $error) {
        if (!vp3_profile_event_clock_missing_v1($error)) throw $error;
    }
    try {
        $pdo->exec('ALTER TABLE profile_events ADD COLUMN '.$column.' '.$definition);
    } catch (PDOException $error) {
        $native = (int)($error->errorInfo[1] ?? 0);
        $duplicate = $native === 1060
            || ($native === 1 && str_contains(strtolower($error->getMessage()),'duplicate column name:'));
        if (!$duplicate) throw $error;
        $pdo->query('SELECT '.$column.' FROM profile_events WHERE 1=0');
    }
}

function vp3_profile_event_clock_index_v1(PDO $pdo): void
{
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    if ($sqlite) {
        $rows = $pdo->query('PRAGMA index_list(profile_events)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) if ($row['name'] === 'idx_profile_event_recorded_v1') return;
    } else {
        $rows = $pdo->query("SHOW INDEX FROM profile_events WHERE Key_name='idx_profile_event_recorded_v1'")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) return;
    }
    try {
        $pdo->exec('CREATE INDEX idx_profile_event_recorded_v1 ON profile_events(owner_user_id,recorded_at_utc_ms,id)');
    } catch (PDOException $error) {
        $native = (int)($error->errorInfo[1] ?? 0);
        if ($native !== 1061 && !($native === 1 && str_contains(strtolower($error->getMessage()),'already exists'))) throw $error;
    }
}

/**
 * Explicit migration only. DDL precedes the transaction. The singleton row
 * serializes installers using ordinary row locks, without admin/table locks.
 * Marking actual rows and completing activation commit or roll back together.
 */
function vp3_profile_event_clock_install_v1(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new RuntimeException('Install the Profile event clock outside an existing transaction.');
    if (!in_array($pdo->getAttribute(PDO::ATTR_DRIVER_NAME),['sqlite','mysql'],true)) {
        throw new RuntimeException('Profile event clock storage is unsupported.');
    }
    $pdo->query('SELECT id,owner_user_id FROM profile_events WHERE 1=0');
    vp3_profile_event_clock_add_column_v1($pdo,'recorded_at_utc_ms','BIGINT NULL');
    vp3_profile_event_clock_add_column_v1($pdo,'clock_legacy_token_v1','CHAR(32) NULL');
    vp3_profile_event_clock_index_v1($pdo);
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec('CREATE TABLE IF NOT EXISTS profile_event_clock_activation_v1 (
        id SMALLINT NOT NULL PRIMARY KEY,
        contract VARCHAR(64) NOT NULL,
        token CHAR(32) NULL,
        activated_at_utc_ms BIGINT NULL
    )'.$tail);
    $pdo->beginTransaction();
    try {
        // The no-op duplicate update acquires one exclusive row lock on MySQL.
        // INSERT IGNORE would take shared duplicate locks and can deadlock when
        // two retries both subsequently upgrade to FOR UPDATE.
        $insert = ($sqlite ? 'INSERT OR IGNORE' : 'INSERT')
            .' INTO profile_event_clock_activation_v1 (id,contract,token,activated_at_utc_ms) VALUES (1,?,NULL,NULL)'
            .($sqlite ? '' : ' ON DUPLICATE KEY UPDATE id=id');
        $pdo->prepare($insert)->execute([VP3_PROFILE_EVENT_CLOCK_CONTRACT_V1]);
        $suffix = $sqlite ? '' : ' FOR UPDATE';
        $rows = $pdo->query('SELECT id,contract,token,activated_at_utc_ms FROM profile_event_clock_activation_v1 ORDER BY id LIMIT 2'.$suffix)
            ->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || (int)$rows[0]['id'] !== 1
            || $rows[0]['contract'] !== VP3_PROFILE_EVENT_CLOCK_CONTRACT_V1) {
            throw new RuntimeException('Profile event clock activation could not be verified.');
        }
        $row = $rows[0];
        if ($row['token'] !== null || $row['activated_at_utc_ms'] !== null) {
            if (!is_string($row['token']) || !preg_match('/\A[a-f0-9]{32}\z/D',$row['token'])
                || vp3_profile_event_clock_millis_v1($row['activated_at_utc_ms']) === null) {
                throw new RuntimeException('Profile event clock activation could not be verified.');
            }
            // A successful retry never tags any post-activation NULL-clock gap.
            $pdo->commit();
            return;
        }
        $token = bin2hex(random_bytes(16));
        $pdo->prepare('UPDATE profile_events SET clock_legacy_token_v1=? WHERE recorded_at_utc_ms IS NULL AND clock_legacy_token_v1 IS NULL')
            ->execute([$token]);
        $at = vp3_profile_event_clock_now_v1();
        $finish = $pdo->prepare('UPDATE profile_event_clock_activation_v1 SET token=?,activated_at_utc_ms=? WHERE id=1 AND token IS NULL AND activated_at_utc_ms IS NULL');
        $finish->execute([$token,$at]);
        if ($finish->rowCount() !== 1) throw new RuntimeException('Profile event clock activation changed.');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
