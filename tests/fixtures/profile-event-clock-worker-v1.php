<?php
declare(strict_types=1);

require __DIR__.'/profile-event-clock-v1.php';
try {
    $operation = json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
    $pdo = pclock_connect_v1($operation['config']);
    require ($operation['helper_path'] ?? dirname(__DIR__,2).'/includes/profile-event-clock-v1.php');
    $gate = $operation['gate'];
    file_put_contents($gate.'.started','1');
    $mode = $operation['mode'] ?? '';
    if ($mode === 'install') {
        $pdo->beforeSql = static function(string $sql) use ($gate): void {
            if (str_starts_with($sql,'UPDATE profile_events SET clock_legacy_token_v1=')) file_put_contents($gate.'.mark_started','1');
        };
        $pdo->afterSql = static function(string $sql) use ($gate,$operation): void {
            if (!str_starts_with($sql,'UPDATE profile_events SET clock_legacy_token_v1=')) return;
            file_put_contents($gate.'.marked','1');
            if (!empty($operation['pause_after_mark'])) pclock_wait_file_v1($gate.'.release');
        };
        if (!empty($operation['wait_before_install'])) pclock_wait_file_v1($gate.'.release');
        vp3_profile_event_clock_install_v1($pdo);
        $result = vp3_profile_event_clock_status_v1($pdo,true);
    } elseif ($mode === 'insert') {
        if (!empty($operation['transaction'])) $pdo->beginTransaction();
        $pdo->beforeExecute = static function(string $sql) use ($gate): void {
            if (str_starts_with($sql,'INSERT INTO profile_events ')) file_put_contents($gate.'.executing','1');
        };
        file_put_contents($gate.'.inserting','1');
        pclock_event_v1($pdo,(int)$operation['id'],$operation['extra'] ?? []);
        file_put_contents($gate.'.inserted','1');
        if (!empty($operation['transaction'])) {
            pclock_wait_file_v1($gate.'.release');
            if (!empty($operation['rollback'])) $pdo->rollBack(); else $pdo->commit();
        }
        $result = ['row'=>pclock_row_v1($pdo,(int)$operation['id'])];
    } elseif ($mode === 'status') {
        $result = vp3_profile_event_clock_status_v1($pdo,true);
    } else throw new RuntimeException('Unknown clock worker operation.');
    echo json_encode($result,JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR,get_class($error).': '.$error->getMessage());
    exit(1);
}
