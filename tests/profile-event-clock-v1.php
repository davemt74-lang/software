<?php
declare(strict_types=1);

require __DIR__.'/fixtures/profile-event-clock-v1.php';
require dirname(__DIR__).'/includes/profile-event-clock-v1.php';

$config = pclock_config_v1();
register_shutdown_function(static fn()=>pclock_cleanup_v1($config));
$pdo = pclock_connect_v1($config);
$checks = 0;
$nativeChecks = 0;
$originalZone = date_default_timezone_get();

function pclock_check_v1(bool $ok,string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']++;
    echo 'PASS '.$label.PHP_EOL;
}
function pclock_reject_v1(callable $call,string $label): void
{
    try {$call();} catch (Throwable $error) {pclock_check_v1(true,$label);return;}
    throw new RuntimeException($label.' unexpectedly succeeded');
}
function pclock_native_v1(PDO $pdo,string $key,array $metadata=[]): ?array
{
    return profile_event_create($pdo,1,['id'=>11,'visitor_user_id'=>2],'profile_view',10,null,$metadata,$key);
}
function pclock_insert_count_v1(ProfileEventClockPDOV1 $pdo): int
{
    return count(array_filter($pdo->trace,static fn(array $entry): bool =>
        $entry[0] === 'execute' && str_starts_with($entry[1],'INSERT INTO profile_events ')));
}
function pclock_release_v1(array $child): void {file_put_contents($child['gate'].'.release','1');}

try {
    pclock_schema_v1($pdo);
    $status = vp3_profile_event_clock_status_v1($pdo);
    pclock_check_v1($status['availability'] === 'unconfigured' && !$status['columns_available']
        && $status['activation'] === null,'missing clock columns are explicitly unconfigured');
    pclock_check_v1($status['writer_contract'] === 'vp3.profile-event-clock.v1'
        && VP3_PROFILE_EVENT_CREATE_CLOCK_CONTRACT_V1 === VP3_PROFILE_CONVERSION_CLOCK_CONTRACT_V1,
        'both actual native writer declarations establish runtime support');
    pclock_check_v1(array_filter($pdo->trace,static fn(array $entry): bool =>
        !preg_match('/\ASELECT\b/',$entry[1])) === [],'status reads do not create or alter storage');

    $legacyKey = hash('sha256','legacy-native-event');
    $legacy = pclock_native_v1($pdo,$legacyKey);
    pclock_check_v1(is_array($legacy) && !array_key_exists('recorded_at_utc_ms',$legacy),
        'the original native writer remains usable before the explicit clock upgrade');
    $legacyConversion = profile_conversion_outcome_record_v179($pdo,1,'product_converted',1001,0);
    pclock_check_v1(is_array($legacyConversion) && !array_key_exists('recorded_at_utc_ms',$legacyConversion),
        'the actual sessionless conversion writer remains usable before upgrade');
    pclock_event_v1($pdo,100);
    $pdo->exec('ALTER TABLE profile_events ADD COLUMN recorded_at_utc_ms BIGINT NULL');
    $partial = pclock_native_v1($pdo,hash('sha256','partial-columns'));
    pclock_check_v1($partial['recorded_at_utc_ms'] === null && !vp3_profile_event_clock_columns_v1($pdo),
        'a partially installed pair never advertises complete clock storage');
    $pdo->exec('ALTER TABLE profile_events ADD COLUMN clock_legacy_token_v1 CHAR(32) NULL');
    $before = vp3_profile_event_clock_now_v1();
    $preactivation = pclock_native_v1($pdo,hash('sha256','preactivation-forward-clock'));
    $after = vp3_profile_event_clock_now_v1();
    pclock_check_v1((int)$preactivation['recorded_at_utc_ms'] >= $before && (int)$preactivation['recorded_at_utc_ms'] <= $after
        && $preactivation['clock_legacy_token_v1'] === null,'available columns record UTC before activation without inventing legacy provenance');
    $status = vp3_profile_event_clock_status_v1($pdo);
    pclock_check_v1($status['columns_available'] && $status['activation'] === null && $status['availability'] === 'unconfigured',
        'columns alone do not make a complete reporting interval');
    pclock_event_v1($pdo,110,['clock_legacy_token_v1'=>str_repeat('f',32)]);
    pclock_event_v1($pdo,111,['recorded_at_utc_ms'=>-1]);
    pclock_event_v1($pdo,112,['recorded_at_utc_ms'=>$before,'clock_legacy_token_v1'=>str_repeat('e',32)]);

    $activationBefore = vp3_profile_event_clock_now_v1();
    vp3_profile_event_clock_install_v1($pdo);
    $activationAfter = vp3_profile_event_clock_now_v1();
    $active = vp3_profile_event_clock_status_v1($pdo);
    $token = $active['activation']['token'];
    pclock_check_v1($active['availability'] === 'ready' && strlen($token) === 32,
        'explicit installation completes one verified activation');
    pclock_check_v1($active['activation']['activated_at_utc_ms'] >= $activationBefore
        && $active['activation']['activated_at_utc_ms'] <= $activationAfter,'activation is recorded using the application UTC clock');
    pclock_check_v1(pclock_row_v1($pdo,(int)$legacy['id'])['recorded_at_utc_ms'] === null
        && pclock_row_v1($pdo,(int)$legacy['id'])['clock_legacy_token_v1'] === $token,
        'legacy rows receive immutable cutover membership without a UTC backfill');
    pclock_check_v1(pclock_row_v1($pdo,(int)$legacyConversion['id'])['recorded_at_utc_ms'] === null
        && pclock_row_v1($pdo,(int)$legacyConversion['id'])['clock_legacy_token_v1'] === $token,
        'legacy sessionless conversions retain unknown recorded time');
    pclock_check_v1(pclock_row_v1($pdo,(int)$preactivation['id'])['recorded_at_utc_ms'] == $preactivation['recorded_at_utc_ms']
        && pclock_row_v1($pdo,(int)$preactivation['id'])['clock_legacy_token_v1'] === null,
        'activation preserves a real forward clock already captured by an updated writer');
    pclock_check_v1(pclock_row_v1($pdo,110)['clock_legacy_token_v1'] === str_repeat('f',32)
        && (int)pclock_row_v1($pdo,111)['recorded_at_utc_ms'] === -1
        && pclock_row_v1($pdo,112)['clock_legacy_token_v1'] === str_repeat('e',32),
        'foreign markers and malformed clock combinations remain detectable gaps');

    $legacyBefore = pclock_row_v1($pdo,(int)$legacy['id']);
    pclock_check_v1(pclock_native_v1($pdo,$legacyKey) === null
        && pclock_row_v1($pdo,(int)$legacy['id']) === $legacyBefore,'native dedupe never stamps or changes an older event');
    $conversionBefore = pclock_row_v1($pdo,(int)$legacyConversion['id']);
    pclock_check_v1(profile_conversion_outcome_record_v179($pdo,1,'product_converted',1001,0) === null
        && pclock_row_v1($pdo,(int)$legacyConversion['id']) === $conversionBefore,
        'sessionless conversion dedupe leaves the original NULL clock and marker untouched');

    date_default_timezone_set('Pacific/Kiritimati');
    if ($config['driver'] === 'mysql') $pdo->exec("SET time_zone='+11:00'");
    $before = vp3_profile_event_clock_now_v1();
    $forwardKey = hash('sha256','forward-native-event');
    $forward = pclock_native_v1($pdo,$forwardKey,['recorded_at_utc_ms'=>1,'clock_legacy_token_v1'=>'client supplied','occurred_at'=>'1900-01-01']);
    $after = vp3_profile_event_clock_now_v1();
    pclock_check_v1((int)$forward['recorded_at_utc_ms'] >= $before && (int)$forward['recorded_at_utc_ms'] <= $after
        && $forward['clock_legacy_token_v1'] === null,'native recorded UTC ignores PHP timezone and client metadata timestamps');
    $conversion = profile_conversion_outcome_record_v179($pdo,1,'product_converted',1002,0,[],['recorded_at_utc_ms'=>1]);
    pclock_check_v1($conversion['profile_session_id'] === null && (int)$conversion['recorded_at_utc_ms'] >= $before
        && (int)$conversion['recorded_at_utc_ms'] <= vp3_profile_event_clock_now_v1()
        && $conversion['clock_legacy_token_v1'] === null,'the real sessionless conversion INSERT records its own current UTC time');
    $sessionConversion = profile_conversion_outcome_record_v179($pdo,1,'booking_converted',1003,11);
    pclock_check_v1((int)$sessionConversion['profile_session_id'] === 11
        && (int)$sessionConversion['recorded_at_utc_ms'] >= $before && $sessionConversion['clock_legacy_token_v1'] === null,
        'session-backed conversion uses the updated canonical event writer');
    if ($config['driver'] === 'mysql') {
        $wall = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$forward['created_at'],new DateTimeZone('UTC'));
        pclock_check_v1($wall !== false && abs($wall->getTimestamp() - intdiv((int)$forward['recorded_at_utc_ms'],1000) - 39600) < 3,
            'native MySQL wall-clock offset cannot alter the dedicated UTC recording');$nativeChecks++;
        $pdo->exec("SET time_zone='+00:00'");
    }
    date_default_timezone_set($originalZone);
    pclock_check_v1(pclock_native_v1($pdo,$forwardKey,['recorded_at_utc_ms'=>1,'clock_legacy_token_v1'=>'client supplied','occurred_at'=>'1900-01-01']) === null
        && pclock_row_v1($pdo,(int)$forward['id']) === $forward,'a forward event replay preserves its first recorded timestamp');
    pclock_check_v1(profile_event_create($pdo,1,['id'=>12,'visitor_user_id'=>1],'profile_view',10) === null,
        'the clock does not weaken the existing owner-preview exclusion');

    pclock_event_v1($pdo,7);
    $gap = pclock_row_v1($pdo,7);
    vp3_profile_event_clock_install_v1($pdo);
    pclock_check_v1(pclock_row_v1($pdo,7) === $gap && $gap['recorded_at_utc_ms'] === null && $gap['clock_legacy_token_v1'] === null,
        'a later NULL-clock row below existing IDs remains an unmarked gap after retry');
    pclock_check_v1(vp3_profile_event_clock_status_v1($pdo)['activation'] === $active['activation'],
        'successful installer retry preserves the original token and activation time');

    $pdo->trace = [];
    $pdo->beforeSql = static function(string $sql): void {
        if (!preg_match('/\ASELECT\b/',$sql)) throw new RuntimeException('A status reader attempted a write.');
    };
    $read = vp3_profile_event_clock_status_v1($pdo,true);
    $pdo->beforeSql = null;
    pclock_check_v1($read === $active && count($pdo->trace) === 2,'current clock status remains two query-only reads');
    $pdo->beforeSql = static function(string $sql): void {
        if (str_contains($sql,'FROM profile_event_clock_activation_v1')) throw new PDOException('SECRET synthetic clock read failure');
    };
    $failed = vp3_profile_event_clock_status_v1($pdo);
    $pdo->beforeSql = null;
    pclock_check_v1($failed['availability'] === 'unavailable' && $failed['activation'] === null
        && !str_contains(json_encode($failed),'SECRET'),'failed activation reads are unavailable rather than fabricated empty history');
    foreach ([['40001',0],['HY000',1205],['HY000',1213]] as [$sqlState,$nativeCode]) {
        $failure=new PDOException('SECRET retryable clock read');
        $failure->errorInfo=[$sqlState,$nativeCode,'retryable fixture failure'];
        foreach ([false,true] as $currentRead) {
            $pdo->beforeSql=static function(string $sql) use ($failure):void {
                if(str_contains($sql,'FROM profile_event_clock_activation_v1'))throw $failure;
            };
            $caught=null;
            try {vp3_profile_event_clock_status_v1($pdo,$currentRead);} catch(PDOException $error) {$caught=$error;}
            finally {$pdo->beforeSql=null;}
            pclock_check_v1($caught === $failure,'retryable clock read '.$sqlState.'/'.$nativeCode.' retains the caller transaction recovery boundary'.($currentRead?' with current proof':''));
        }
    }
    $pdo->beginTransaction();$pdo->trace = [];
    pclock_reject_v1(fn()=>vp3_profile_event_clock_install_v1($pdo),'installation refuses a caller-owned transaction');
    pclock_check_v1($pdo->inTransaction() && $pdo->trace === [],'transaction rejection performs no DDL or implicit commit');
    $pdo->rollBack();

    $pdo->trace = [];$uncertain = true;
    $pdo->afterSql = static function(string $sql) use (&$uncertain): void {
        if ($uncertain && str_starts_with($sql,'INSERT INTO profile_events ')) {
            $uncertain = false;
            throw new PDOException('SECRET native INSERT result lost');
        }
    };
    $uncertainKey = hash('sha256','uncertain-native-insert');
    pclock_reject_v1(fn()=>pclock_native_v1($pdo,$uncertainKey),'uncertain native event insertion fails its transaction');
    $pdo->afterSql = null;
    pclock_check_v1(pclock_insert_count_v1($pdo) === 1,'an uncertain native INSERT is never retried without clock columns');
    $q=$pdo->prepare('SELECT COUNT(*) FROM profile_events WHERE dedupe_key=?');$q->execute([$uncertainKey]);
    $uncertainCount=(int)$q->fetchColumn();$q->closeCursor();
    pclock_check_v1($uncertainCount === 0,'native insertion uncertainty rolls back the event and its clock together');

    $pdo->trace = [];$uncertain = true;
    $pdo->afterSql = static function(string $sql) use (&$uncertain): void {
        if ($uncertain && str_starts_with($sql,'INSERT INTO profile_events ')) {
            $uncertain = false;
            throw new PDOException('SECRET conversion INSERT response lost');
        }
    };
    $unknown = profile_conversion_outcome_record_v179($pdo,1,'product_converted',1004,0);
    $pdo->afterSql = null;
    pclock_check_v1($unknown === null && pclock_insert_count_v1($pdo) === 1,
        'sessionless conversion uncertainty neither retries its INSERT nor blocks the completed purchase');
    $key=hash('sha256','profile-conversion-outcome-v179|1|product_converted|1004');
    $q=$pdo->prepare('SELECT * FROM profile_events WHERE dedupe_key=?');$q->execute([$key]);$savedUnknown=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    pclock_check_v1(is_array($savedUnknown) && (int)$savedUnknown['recorded_at_utc_ms'] > 0,
        'a committed sessionless INSERT retains its clock even if its response was lost');
    pclock_check_v1(profile_conversion_outcome_record_v179($pdo,1,'product_converted',1004,0) === null
        && pclock_row_v1($pdo,(int)$savedUnknown['id']) === $savedUnknown,
        'retry after uncertain conversion storage preserves the first durable event');

    foreach ([[],['native'],['conversion']] as $number=>$supported) {
        $directory=$config['directory'].'/partial_'.$number;mkdir($directory,0700);
        copy(dirname(__DIR__).'/includes/profile-event-clock-v1.php',$directory.'/profile-event-clock-v1.php');
        file_put_contents($directory.'/profile-agent.php',"<?php\nfunction profile_event_create() {}\n"
            .(in_array('native',$supported,true)?"const VP3_PROFILE_EVENT_CREATE_CLOCK_CONTRACT_V1='vp3.profile-event-clock.v1';\n":''));
        file_put_contents($directory.'/profile-conversion-outcomes-v179.php',"<?php\nfunction profile_conversion_outcome_record_v179() {}\n"
            .(in_array('conversion',$supported,true)?"const VP3_PROFILE_CONVERSION_CLOCK_CONTRACT_V1='vp3.profile-event-clock.v1';\n":''));
        $child=pclock_start_v1($config,['mode'=>'status','helper_path'=>$directory.'/profile-event-clock-v1.php']);
        $partialStatus=pclock_finish_v1($child);
        pclock_check_v1($partialStatus['columns_available'] && $partialStatus['activation'] === $active['activation']
            && $partialStatus['writer_contract'] === null && $partialStatus['availability'] === 'unconfigured',
            'partial deployed writer set '.$number.' cannot certify complete post-activation coverage');
    }

    if ($config['driver'] === 'mysql') {
        $other=pclock_connect_v1($config);
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        $snapshot=vp3_profile_event_clock_status_v1($pdo);
        $replacement=str_repeat('d',32);
        $other->prepare('UPDATE profile_event_clock_activation_v1 SET token=? WHERE id=1')->execute([$replacement]);
        $old=vp3_profile_event_clock_status_v1($pdo);
        $current=vp3_profile_event_clock_status_v1($pdo,true);
        pclock_check_v1($old['activation'] === $snapshot['activation'] && $current['activation']['token'] === $replacement,
            'current activation proof bypasses an older MySQL repeatable-read snapshot');$nativeChecks++;
        $pdo->rollBack();
        $other->prepare('UPDATE profile_event_clock_activation_v1 SET token=? WHERE id=1')->execute([$token]);
        $other=null;
    }

    // An interrupted setup leaves DDL installed, but neither tagging nor activation committed.
    pclock_schema_v1($pdo);
    pclock_event_v1($pdo,20);
    $pdo->afterSql=static function(string $sql):void {
        if(str_starts_with($sql,'UPDATE profile_events SET clock_legacy_token_v1=')) throw new RuntimeException('Injected activation failure.');
    };
    pclock_reject_v1(fn()=>vp3_profile_event_clock_install_v1($pdo),'activation failure after tagging rolls back the transaction');
    $pdo->afterSql=null;
    pclock_check_v1(pclock_row_v1($pdo,20)['clock_legacy_token_v1'] === null
        && (int)$pdo->query('SELECT COUNT(*) FROM profile_event_clock_activation_v1')->fetchColumn() === 0,
        'failed setup leaves no committed marker or activation record');
    pclock_check_v1(vp3_profile_event_clock_status_v1($pdo)['availability'] === 'unconfigured',
        'interrupted setup remains honestly unconfigured');
    pclock_event_v1($pdo,21);
    vp3_profile_event_clock_install_v1($pdo);
    $recovered=vp3_profile_event_clock_status_v1($pdo);
    pclock_check_v1(pclock_row_v1($pdo,20)['clock_legacy_token_v1'] === $recovered['activation']['token']
        && pclock_row_v1($pdo,21)['clock_legacy_token_v1'] === $recovered['activation']['token'],
        'retry of an uncompleted activation tags the actual rows present at its successful cutover');

    pclock_schema_v1($pdo,true,true);
    pclock_event_v1($pdo,30);
    $pending=vp3_profile_event_clock_status_v1($pdo);
    pclock_check_v1($pending['availability'] === 'unconfigured' && $pending['activation'] === null,
        'a pending singleton never advertises an activated clock');
    $pendingEvent=pclock_native_v1($pdo,hash('sha256','pending-activation'));
    vp3_profile_event_clock_install_v1($pdo);
    pclock_check_v1((int)$pendingEvent['recorded_at_utc_ms'] > 0
        && pclock_row_v1($pdo,(int)$pendingEvent['id'])['clock_legacy_token_v1'] === null,
        'a pending activation does not stop forward recording or misclassify its new event');
    $pdo->exec('UPDATE profile_event_clock_activation_v1 SET token=NULL WHERE id=1');
    pclock_check_v1(vp3_profile_event_clock_status_v1($pdo)['availability'] === 'unavailable',
        'a malformed half-completed activation is unavailable');
    pclock_reject_v1(fn()=>vp3_profile_event_clock_install_v1($pdo),'installation does not rewrite malformed completed provenance');
    $pdo->exec("UPDATE profile_event_clock_activation_v1 SET contract='foreign-clock',activated_at_utc_ms=NULL WHERE id=1");
    pclock_check_v1(vp3_profile_event_clock_status_v1($pdo)['availability'] === 'unavailable',
        'an unknown activation contract cannot certify event time');

    // Two independent processes start from the same old schema.
    pclock_schema_v1($pdo);
    pclock_event_v1($pdo,100);
    $first=pclock_start_v1($config,['mode'=>'install','wait_before_install'=>true]);
    $second=pclock_start_v1($config,['mode'=>'install','wait_before_install'=>true]);
    pclock_wait_file_v1($first['gate'].'.started');pclock_wait_file_v1($second['gate'].'.started');
    pclock_release_v1($first);pclock_release_v1($second);
    $one=pclock_finish_v1($first);$two=pclock_finish_v1($second);
    pclock_check_v1($one['availability'] === 'ready' && $two['availability'] === 'ready'
        && $one['activation'] === $two['activation'],'concurrent independent installers converge on one immutable activation');
    pclock_check_v1((int)$pdo->query('SELECT COUNT(*) FROM profile_event_clock_activation_v1')->fetchColumn() === 1
        && pclock_row_v1($pdo,100)['clock_legacy_token_v1'] === $one['activation']['token'],
        'concurrent schema upgrades and activation preserve exact legacy membership');

    pclock_event_v1($pdo,7);
    $retryOne=pclock_start_v1($config,['mode'=>'install','wait_before_install'=>true]);
    $retryTwo=pclock_start_v1($config,['mode'=>'install','wait_before_install'=>true]);
    pclock_wait_file_v1($retryOne['gate'].'.started');pclock_wait_file_v1($retryTwo['gate'].'.started');
    pclock_release_v1($retryOne);pclock_release_v1($retryTwo);
    $retryA=pclock_finish_v1($retryOne);$retryB=pclock_finish_v1($retryTwo);
    pclock_check_v1($retryA['activation'] === $one['activation'] && $retryB['activation'] === $one['activation']
        && pclock_row_v1($pdo,7)['clock_legacy_token_v1'] === null,
        'concurrent retries neither deadlock on shared duplicate locks nor absorb a later gap');

    // Reserve then roll back a lower ID. Its later insertion must not be inferred
    // as legacy merely because a larger committed ID exists at cutover.
    pclock_schema_v1($pdo,true,true);
    pclock_event_v1($pdo,100);
    $pdo->beginTransaction();pclock_event_v1($pdo,7);$pdo->rollBack();
    $late=null;
    $pdo->afterSql=static function(string $sql) use (&$late,$config):void {
        if(!str_starts_with($sql,'UPDATE profile_events SET clock_legacy_token_v1=') || $late!==null)return;
        $late=pclock_start_v1($config,['mode'=>'insert','id'=>7]);
        pclock_wait_file_v1($late['gate'].'.executing');
        usleep(50000);
        pclock_check_v1(proc_get_status($late['process'])['running'],
            'a concurrent lower-ID insert remains blocked while cutover tagging is uncommitted');
    };
    vp3_profile_event_clock_install_v1($pdo);$pdo->afterSql=null;
    $lateResult=pclock_finish_v1($late);
    $cutover=vp3_profile_event_clock_status_v1($pdo);
    pclock_check_v1($lateResult['row']['recorded_at_utc_ms'] === null && $lateResult['row']['clock_legacy_token_v1'] === null
        && pclock_row_v1($pdo,100)['clock_legacy_token_v1'] === $cutover['activation']['token'],
        'an allocated-before and inserted-after lower ID remains an honest unclocked gap');
    vp3_profile_event_clock_install_v1($pdo);
    pclock_check_v1(pclock_row_v1($pdo,7)['clock_legacy_token_v1'] === null,
        'an installer retry cannot retroactively absorb the late lower-ID gap');

    if ($config['driver'] === 'mysql') {
        foreach ([false,true] as $rollback) {
            pclock_schema_v1($pdo,true,true);vp3_profile_event_clock_index_v1($pdo);pclock_event_v1($pdo,100);
            $writer=pclock_start_v1($config,['mode'=>'insert','id'=>7,'transaction'=>true,'rollback'=>$rollback]);
            pclock_wait_file_v1($writer['gate'].'.inserted');
            $installer=pclock_start_v1($config,['mode'=>'install']);
            pclock_wait_file_v1($installer['gate'].'.mark_started');
            pclock_check_v1(vp3_profile_event_clock_status_v1($pdo)['availability'] === 'unconfigured',
                'native activation remains pending while a lower-ID row is uncommitted');$nativeChecks++;
            pclock_release_v1($writer);pclock_finish_v1($writer);$done=pclock_finish_v1($installer);
            $row=pclock_row_v1($pdo,7);
            pclock_check_v1($rollback ? $row === null
                : ($row !== null && $row['recorded_at_utc_ms'] === null && $row['clock_legacy_token_v1'] === $done['activation']['token']),
                $rollback ? 'a rolled-back lower-ID insertion leaves no fictitious legacy member'
                    : 'a lower-ID row committed before cutover completion receives actual legacy membership');$nativeChecks++;
        }

        pclock_schema_v1($pdo);pclock_event_v1($pdo,100);
        $restricted='vp3_clock_'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(16));
        $pdo->exec('CREATE USER '.$pdo->quote($restricted)."@'%' IDENTIFIED BY ".$pdo->quote($password));
        $GLOBALS['pclock_users_v1'][]=$restricted;
        $pdo->exec('GRANT SELECT,INSERT,UPDATE,CREATE,ALTER,INDEX ON '.$config['database'].'.* TO '.$pdo->quote($restricted)."@'%'");
        $limitedConfig=array_replace($config,['user'=>$restricted,'pass'=>$password]);
        $limited=pclock_connect_v1($limitedConfig);
        $grants=implode(' ',$limited->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN));
        pclock_check_v1(!str_contains($grants,'ALL PRIVILEGES') && !str_contains($grants,'LOCK TABLES')
            && !str_contains($grants,'SUPER'),'installer fixture account has no table-lock or administrative privilege');$nativeChecks++;
        vp3_profile_event_clock_install_v1($limited);
        pclock_check_v1(vp3_profile_event_clock_status_v1($limited)['availability'] === 'ready'
            && pclock_row_v1($limited,100)['clock_legacy_token_v1'] !== null,
            'native installation succeeds using only ordinary schema and row permissions');$nativeChecks++;
        $limited=null;
    }
    pclock_check_v1(vp3_profile_event_clock_millis_v1(0) === null && vp3_profile_event_clock_millis_v1(-1) === null
        && vp3_profile_event_clock_millis_v1('1e12') === null && vp3_profile_event_clock_millis_v1('9223372036854775808') === null
        && vp3_profile_event_clock_millis_v1('1763462400000') === 1763462400000,
        'clock proof accepts only representable positive integer milliseconds');
    echo 'PROFILE_EVENT_CLOCK_V1=PASS driver='.$config['driver'].' checks='.$checks.' native_mysql_checks='.$nativeChecks." skips=0\n";
} finally {
    $pdo->beforeSql=$pdo->beforeExecute=$pdo->afterSql=null;
    if($pdo->inTransaction())$pdo->rollBack();
    date_default_timezone_set($originalZone);
}
