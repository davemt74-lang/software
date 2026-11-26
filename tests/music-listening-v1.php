<?php
declare(strict_types=1);

/** Real SQLite service acceptance; the same cases also run with native MySQL. */
if (!defined('STONEFELLOW_ROOT')) define('STONEFELLOW_ROOT', sys_get_temp_dir() . '/vp3-listening-unit-' . bin2hex(random_bytes(6)));
require_once __DIR__ . '/fixtures/music-listening-v1.php';

function music_listening_test_equal(mixed $actual, mixed $expected, string $message): void
{
    $sort = static function (mixed $value) use (&$sort): mixed {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as &$entry) $entry = $sort($entry);
        return $value;
    };
    if ($sort($actual) !== $sort($expected)) throw new RuntimeException($message . ': ' . json_encode(['expected'=>$expected,'actual'=>$actual], JSON_PARTIAL_OUTPUT_ON_ERROR));
}

function music_listening_test_rejects(callable $operation, int $status, string $message, ?string $code = null): MusicListeningV1Exception
{
    try { $operation(); }
    catch (MusicListeningV1Exception $error) {
        music_listening_test_equal($error->httpStatus, $status, $message . ' status');
        if ($code !== null) music_listening_test_equal($error->publicCode, $code, $message . ' code');
        return $error;
    }
    throw new RuntimeException($message . ': request unexpectedly succeeded');
}

function music_listening_test_start(array $changes = []): array
{
    return array_replace([
        'protocol'=>'native_listening_v1', 'action'=>'start', 'listener_user_id'=>11,
        'track_id'=>42, 'play_id'=>music_song_metadata_v1_uuid(), 'position_ms'=>0, 'playback_rate'=>1,
        'source'=>'player', 'source_url'=>'https://vp3.example.test/chat.php?secret=PRIVATE-QUERY#PRIVATE-FRAGMENT',
    ], $changes);
}

function music_listening_test_event(array $start, array $receipt, int $sequence, array $changes = []): array
{
    return array_replace([
        'protocol'=>$start['protocol'], 'listener_user_id'=>$start['listener_user_id'], 'track_id'=>$start['track_id'],
        'play_id'=>$start['play_id'], 'session_token'=>$receipt['session_token'], 'event_id'=>music_song_metadata_v1_uuid(),
        'seq'=>$sequence, 'cumulative_played_ms'=>0, 'elapsed_ms'=>0, 'position_ms'=>0, 'playback_rate'=>1,
        'action'=>'resume', 'state'=>'playing',
    ], $changes);
}

function music_listening_test_call(array $input, int $clock, int $userId = 11, array $context = []): array
{
    $GLOBALS['musicSongTestUserId'] = $userId;
    return music_listening_v1_handle(db(), current_user(), $input, array_replace([
        'listener_hash'=>str_repeat('c',64), 'now_ms'=>$clock,
        'origin'=>'https://vp3.example.test', 'referrer'=>'https://vp3.example.test/chat.php?private=PRIVATE-REFERRER',
        'user_agent'=>'Native listening acceptance fixture',
    ], $context));
}

function music_listening_test_native(int $id): array
{
    $statement = db()->prepare('SELECT * FROM track_play_sessions WHERE id=?');
    $statement->execute([$id]);
    return $statement->fetch();
}

function music_listening_test_trigger(string $table): void
{
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        db()->exec("CREATE TRIGGER music_listening_test_failure BEFORE INSERT ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture receipt failure'");
    } else {
        db()->exec("CREATE TRIGGER music_listening_test_failure BEFORE INSERT ON $table BEGIN SELECT RAISE(ABORT,'fixture receipt failure'); END");
    }
}

function music_listening_test_no_credit(callable $operation, int $before, string $message): void
{
    // An impossible measurement may be rejected or explicitly clipped. It must
    // never become positive accepted listening time in either case.
    try {
        $receipt = $operation();
        music_listening_test_equal($receipt['accepted_played_ms'], $before, $message);
    } catch (MusicListeningV1Exception $error) {
        if (!in_array($error->httpStatus, [409, 422], true)) throw $error;
    }
}

function music_listening_test_run(): void
{
    $pdo = db();
    $clock = 1800000000000;
    music_listening_test_seed();
    $legacy = music_listening_test_snapshot();
    music_listening_test_equal(music_listening_v1_schema_ready($pdo), false, 'Missing upgrade must be visible');
    music_listening_test_rejects(static fn()=>music_listening_test_call(music_listening_test_start(),$clock),503,'Requests cannot lazily install tables');
    music_listening_test_equal(music_listening_test_snapshot(),$legacy,'Unupgraded request wrote legacy rows');
    music_song_metadata_v1_install($pdo);
    $beforeInstall = music_listening_test_snapshot();
    music_listening_v1_install($pdo);
    music_listening_test_equal(music_listening_v1_schema_ready($pdo),true,'Explicit install did not prepare schema');
    $afterInstall = music_listening_test_snapshot();
    foreach ($beforeInstall as $table=>$rows) music_listening_test_equal($afterInstall[$table],$rows,'Listening migration changed ' . $table);
    music_listening_v1_install($pdo);
    music_listening_test_equal(music_listening_test_snapshot(),$afterInstall,'Listening migration is not rerunnable');
    $report = music_listening_v1_report($pdo);
    music_listening_test_equal($report['counts']['legacy_unversioned_rows'],1,'Historical sessions were retroactively certified');
    music_listening_test_equal($report['counts']['sessions'],0,'Migration manufactured versioned listens');
    music_listening_test_equal(music_listening_test_snapshot(),$afterInstall,'Report wrote data');
    $pdo->beginTransaction();
    music_listening_test_rejects(static fn()=>music_listening_v1_install($pdo),409,'DDL inside caller transaction');
    music_listening_test_equal($pdo->inTransaction(),true,'Upgrade refusal lost caller transaction');
    $pdo->rollBack();

    $input = music_listening_test_start();
    $start = music_listening_test_call($input,$clock);
    music_listening_test_equal($start['state'],'pending','Start is an attempt, not playing evidence');
    music_listening_test_equal($start['accepted_played_ms'],0,'Start accrued listening');
    music_listening_test_equal($start['duration_ms'],null,'Catalog/WAV duration became authoritative');
    music_listening_test_equal($start['completed'],null,'Unknown duration became a completion assertion');
    music_listening_test_equal($start['metric_version'],'legacy_10s_80pct_v1','Legacy nonfinancial metric is not versioned');
    music_listening_test_equal($start['identity']['mapping'],'mapped','Canonical identity was not captured');
    music_listening_test_equal($start['identity']['binding'],'catalog_snapshot_unverified_delivery','Catalog snapshot was promoted to delivery proof');
    $canonical = $pdo->query('SELECT * FROM music_recordings_v1 WHERE catalog_track_id=10')->fetch();
    music_listening_test_equal($start['identity']['recording_id'],$canonical['recording_id'],'Wrong recording identity');
    music_listening_test_equal($start['identity']['asset_id'],$canonical['current_asset_id'],'Wrong asset identity');
    music_listening_test_equal($start['coverage']['listen_class'],'owner_or_manager_preview','Owner preview became audience listening');
    music_listening_test_equal($start['source']['url'],'https://vp3.example.test/chat.php','Private source details were not redacted');
    music_listening_test_equal($start['source']['provenance'],'same_origin_referrer_match','Source provenance was overstated');
    music_listening_test_equal((int)music_listening_test_native($start['session_id'])['track_id'],42,'Native history lost backing identity');
    $afterStart = music_listening_test_snapshot();
    music_listening_test_equal(music_listening_test_call($input,$clock+10),$start,'Exact start retry changed receipt');
    music_listening_test_equal(music_listening_test_snapshot(),$afterStart,'Exact start retry wrote extra rows');
    music_listening_test_rejects(static fn()=>music_listening_test_call(array_replace($input,['position_ms'=>1]),$clock+20),409,'Changed start reused idempotency key');
    music_listening_test_rejects(static fn()=>music_listening_test_call(music_listening_test_event($input,$start,1,['action'=>'heartbeat']),$clock+20),409,'Time reported before playing boundary');

    $resume = music_listening_test_event($input,$start,1);
    $resumed = music_listening_test_call($resume,$clock);
    $heartbeat = music_listening_test_event($input,$start,2,['action'=>'heartbeat','elapsed_ms'=>10000,'cumulative_played_ms'=>10000,'position_ms'=>10000]);
    $listened = music_listening_test_call($heartbeat,$clock+10000);
    music_listening_test_equal($listened['accepted_played_ms'],10000,'Ten seconds of continuous active playback were not counted once');
    music_listening_test_equal($listened['qualified_play'],true,'Legacy 10-second qualification changed');
    music_listening_test_equal($listened['completed'],null,'Unknown duration became completion evidence');
    music_listening_test_equal((float)music_listening_test_native($start['session_id'])['listened_seconds'],10.0,'Native history and receipt disagree');
    $measured = music_listening_test_snapshot();
    music_listening_test_equal(music_listening_test_call($heartbeat,$clock+11000),$listened,'Lost-ACK event retry changed receipt');
    music_listening_test_equal(music_listening_test_call($resume,$clock+11000),$resumed,'Earlier receipt changed after newer evidence');
    music_listening_test_equal(music_listening_test_snapshot(),$measured,'Event replay duplicated credit/event/activity');
    music_listening_test_rejects(static fn()=>music_listening_test_call(array_replace($heartbeat,['position_ms'=>10001]),$clock+11000),409,'Changed payload under event ID');
    music_listening_test_rejects(static fn()=>music_listening_test_call(array_replace($heartbeat,['event_id'=>music_song_metadata_v1_uuid()]),$clock+11000),409,'Sequence reused by another event');
    $gap = music_listening_test_rejects(static fn()=>music_listening_test_call(music_listening_test_event($input,$start,4),$clock+11000),409,'Out-of-order sequence','sequence_gap');
    music_listening_test_equal($gap->details['expected_seq'],3,'Gap did not identify next expected event');
    music_listening_test_equal(music_listening_test_snapshot(),$measured,'Rejected conflict/order changed state');

    // Authentication is checked on every retry, including already accepted events.
    music_listening_test_rejects(static fn()=>music_listening_test_call($heartbeat,$clock+11000,14),403,'Queued event crossed an account change');
    music_listening_test_rejects(static fn()=>music_listening_test_call($heartbeat,$clock+11000,11,['listener_hash'=>str_repeat('d',64)]),404,'Another cookie reused the listener session');
    music_listening_test_rejects(static fn()=>music_listening_test_call(array_replace($heartbeat,['session_token'=>str_repeat('e',64)]),$clock+11000),404,'Invented token');
    music_listening_test_rejects(static fn()=>music_listening_test_call(music_listening_test_start(['track_id'=>84]),$clock),404,'Foreign private recording');
    $private = music_listening_test_call(music_listening_test_start(['track_id'=>84,'listener_user_id'=>14]),$clock,14);
    music_listening_test_equal($private['identity']['workspace_id'],2,'Authorized foreign owner did not retain their workspace');
    music_listening_test_equal($private['coverage']['listen_class'],'owner_or_manager_preview','Private owner preview was not distinguished');

    $beforeInvalid = music_listening_test_snapshot();
    foreach ([['seq'=>0],['seq'=>1.5],['seq'=>'3'],['seq'=>PHP_INT_MAX],['cumulative_played_ms'=>-1],
        ['cumulative_played_ms'=>INF],['cumulative_played_ms'=>'10000'],['elapsed_ms'=>-1],['elapsed_ms'=>1.1],
        ['playback_rate'=>0],['playback_rate'=>INF],['playback_rate'=>NAN],['playback_rate'=>'1'],
        ['position_ms'=>-1],['event_id'=>"' OR 1=1 --"],['state'=>'invented'],['muted'=>1],
        ['cumulative_played_ms'=>2,'elapsed_ms'=>1],['earnings_rate'=>0.01]] as $invalid) {
        $bad = music_listening_test_event($input,$start,3,array_replace(['action'=>'heartbeat','elapsed_ms'=>10000,'cumulative_played_ms'=>10000,'position_ms'=>10000],$invalid));
        music_listening_test_rejects(static fn()=>music_listening_test_call($bad,$clock+11000),422,'Malformed telemetry');
    }
    music_listening_test_rejects(static fn()=>music_listening_test_call(music_listening_test_event($input,$start,3,['elapsed_ms'=>9000,'cumulative_played_ms'=>9000]),$clock+11000),409,'Backward cumulative counter');
    music_listening_test_equal(music_listening_test_snapshot(),$beforeInvalid,'Invalid counters/injection wrote rows');

    // Pause, stall, seek and playback rate are distinct from earned wall time.
    $timingInput = music_listening_test_start();
    $timing = music_listening_test_call($timingInput,$clock);
    music_listening_test_call(music_listening_test_event($timingInput,$timing,1),$clock);
    $steps = [
        [2,1000,'pause','paused',1000,1000,1,1000],
        [3,61000,'heartbeat','paused',1000,1000,1,1000],
        [4,62000,'resume','playing',1000,1000,1,1000],
        [5,63000,'waiting','buffering',2000,2000,1,2000],
        [6,68000,'heartbeat','buffering',2000,2000,1,2000],
        [7,69000,'seek','seeking',2000,29000,1,2000],
        [8,70000,'resume','playing',2000,29000,1,2000],
        [9,71000,'ratechange','playing',3000,30000,2,3000],
        [10,72000,'heartbeat','playing',4000,32000,2,4000],
    ];
    foreach ($steps as [$seq,$elapsed,$action,$state,$played,$position,$rate,$expected]) {
        $result = music_listening_test_call(music_listening_test_event($timingInput,$timing,$seq,[
            'action'=>$action,'state'=>$state,'elapsed_ms'=>$elapsed,'cumulative_played_ms'=>$played,'position_ms'=>$position,'playback_rate'=>$rate,
        ]),$clock+$elapsed);
        music_listening_test_equal($result['accepted_played_ms'],$expected,'Pause/stall/seek/rate transition ' . $seq);
    }
    $seekEnd = music_listening_test_call(music_listening_test_event($timingInput,$timing,11,[
        'action'=>'seek','state'=>'playing','elapsed_ms'=>73000,'cumulative_played_ms'=>5000,'position_ms'=>7200000,'playback_rate'=>2,
    ]),$clock+73000);
    music_listening_test_equal($seekEnd['accepted_played_ms'],4000,'Seek jump created listening time');
    music_listening_test_equal($seekEnd['coverage']['partial'],true,'Clipped claim was silently called complete coverage');

    // Actual server-probed MP3 duration wins over the catalog's stale 120 seconds.
    $mp3Input = music_listening_test_start(['track_id'=>43]);
    $mp3 = music_listening_test_call($mp3Input,$clock);
    music_song_test_check(abs($mp3['duration_ms']-1296)<=90,'Known synthetic MP3 duration differs from independent ffprobe oracle');
    music_listening_test_equal($mp3['coverage']['duration'],'server_mp3_probe','MP3 duration provenance lost');
    music_listening_test_call(music_listening_test_event($mp3Input,$mp3,1),$clock);
    $jumped = music_listening_test_call(music_listening_test_event($mp3Input,$mp3,2,[
        'action'=>'seek','state'=>'playing','elapsed_ms'=>100,'cumulative_played_ms'=>0,'position_ms'=>$mp3['duration_ms'],
    ]),$clock+100);
    music_listening_test_equal((float)$jumped['position_progress_percent'],100.0,'Position progress is a separate observation');
    music_listening_test_equal($jumped['completed'],false,'Seeking to the end became a completed listen');
    $ended = music_listening_test_call(music_listening_test_event($mp3Input,$mp3,3,[
        'action'=>'ended','state'=>'ended','elapsed_ms'=>200,'cumulative_played_ms'=>0,'position_ms'=>$mp3['duration_ms'],
    ]),$clock+200);
    music_listening_test_equal($ended['natural_ended'],true,'Natural end observation was not retained');
    music_listening_test_equal($ended['completed'],false,'Natural end alone became a completed listen');
    $fullInput = music_listening_test_start(['track_id'=>43]);
    $full = music_listening_test_call($fullInput,$clock);
    music_listening_test_call(music_listening_test_event($fullInput,$full,1),$clock);
    $fullEnd = music_listening_test_event($fullInput,$full,2,['action'=>'ended','state'=>'ended',
        'elapsed_ms'=>$full['duration_ms'],'cumulative_played_ms'=>$full['duration_ms'],'position_ms'=>$full['duration_ms']]);
    $complete = music_listening_test_call($fullEnd,$clock+$full['duration_ms']);
    music_listening_test_equal($complete['completed'],true,'Bounded measured MP3 completion not reflected');
    music_listening_test_equal(music_listening_test_call($fullEnd,$clock+5000),$complete,'Closed exact receipt changed');
    music_listening_test_rejects(static fn()=>music_listening_test_call(music_listening_test_event($fullInput,$full,3),$clock+5000),410,'Closed attempt accepted new event');

    // Unmapped and ambiguous sources remain explicit; client IDs are not trusted.
    $unmapped = music_listening_test_call(music_listening_test_start(['track_id'=>99]),$clock);
    music_listening_test_equal($unmapped['identity']['mapping'],'legacy_unmapped','Unmapped legacy track acquired guessed ownership');
    music_listening_test_equal($unmapped['identity']['workspace_id'],null,'Uploader identity became workspace identity');
    music_listening_test_equal($unmapped['identity']['recording_id'],null,'Unmapped recording got an invented identifier');
    music_listening_test_rejects(static fn()=>music_listening_test_call(music_listening_test_start(['recording_id'=>$canonical['recording_id']]),$clock),422,'Client injected canonical identity');

    // Replacing the current asset changes future sessions, not recorded evidence.
    $replacement = '/uploads/artist-music/1/replacement.wav';
    copy(STONEFELLOW_ROOT.'/uploads/artist-music/1/legacy.wav',STONEFELLOW_ROOT.$replacement);
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE tracks SET audio_path=? WHERE id=42')->execute([$replacement]);
    $pdo->prepare('UPDATE artist_catalog_tracks_v181 SET audio_path=? WHERE id=10')->execute([$replacement]);
    $catalog = $pdo->query('SELECT * FROM artist_catalog_tracks_v181 WHERE id=10')->fetch();
    $GLOBALS['musicSongTestUserId']=11;
    $updated = music_song_metadata_v1_save($pdo,1,current_user(),$catalog,[]);
    $pdo->commit();
    music_song_test_check($updated['asset_id']!==$start['identity']['asset_id'],'Replacement did not create new canonical asset');
    $newAsset = music_listening_test_call(music_listening_test_start(),$clock+1000);
    music_listening_test_equal($newAsset['identity']['asset_id'],$updated['asset_id'],'New attempt used obsolete current asset');
    music_listening_test_equal(music_listening_test_call($input,$clock+2000),$start,'Asset replacement rewrote original start identity');
    $oldIdentity = $pdo->prepare('SELECT recording_id,asset_id FROM music_listening_sessions_v1 WHERE session_id=?');
    $oldIdentity->execute([$start['session_id']]);
    music_listening_test_equal($oldIdentity->fetch(),['recording_id'=>$start['identity']['recording_id'],'asset_id'=>$start['identity']['asset_id']],'Old event identity was rewritten');

    // No credential/query/fragment or arbitrary private path may enter telemetry.
    foreach (['https://user:PRIVATE-PASSWORD@vp3.example.test/chat.php?x=PRIVATE-QUERY',
        'https://foreign.example.test/chat.php?x=PRIVATE-QUERY','https://vp3.example.test/private/PRIVATE-PATH',
        'https://vp3.example.test/%2fprivate/PRIVATE-PATH'] as $unsafe) {
        $source = music_listening_test_call(music_listening_test_start(['source_url'=>$unsafe]),$clock,11,['referrer'=>'']);
        music_listening_test_equal($source['source']['url'],null,'Private/foreign URL was recorded');
        music_listening_test_equal($source['source']['provenance'],'unknown','Discarded source was independently verified');
    }
    $stored = json_encode(music_listening_test_snapshot(),JSON_THROW_ON_ERROR);
    foreach (['PRIVATE-PASSWORD','PRIVATE-QUERY','PRIVATE-FRAGMENT','PRIVATE-REFERRER','PRIVATE-PATH'] as $secret) {
        music_song_test_check(!str_contains($stored,$secret),'Unredacted source data persisted: '.$secret);
    }

    // Trigger failures exercise actual DML rollback, not a mocked service result.
    $beforeFailure = music_listening_test_snapshot();
    music_listening_test_trigger('music_listening_sessions_v1');
    try {
        music_song_test_rejects(static fn()=>music_listening_test_call(music_listening_test_start(),$clock), 'Start sidecar failure unexpectedly succeeded', PDOException::class);
    } finally { $pdo->exec('DROP TRIGGER music_listening_test_failure'); }
    music_listening_test_equal(music_listening_test_snapshot(),$beforeFailure,'Start failure left native history/event/idempotency row');
    $failureInput = music_listening_test_start();
    $failure = music_listening_test_call($failureInput,$clock);
    music_listening_test_call(music_listening_test_event($failureInput,$failure,1),$clock);
    $failureEvent = music_listening_test_event($failureInput,$failure,2,['action'=>'heartbeat','elapsed_ms'=>1000,'cumulative_played_ms'=>1000,'position_ms'=>1000]);
    $beforeFailure = music_listening_test_snapshot();
    music_listening_test_trigger('music_listening_receipts_v1');
    try {
        music_song_test_rejects(static fn()=>music_listening_test_call($failureEvent,$clock+1000), 'Receipt failure unexpectedly succeeded', PDOException::class);
    } finally { $pdo->exec('DROP TRIGGER music_listening_test_failure'); }
    music_listening_test_equal(music_listening_test_snapshot(),$beforeFailure,'Receipt failure left counter/history/event changes');
    music_listening_test_equal(music_listening_test_call($failureEvent,$clock+1000)['accepted_played_ms'],1000,'Rolled-back receipt was not retryable');

    // Nested callers own their commit. An outer rollback must remove all evidence.
    $beforeNested = music_listening_test_snapshot();
    $pdo->beginTransaction();
    $nested = music_listening_test_call(music_listening_test_start(),$clock);
    music_listening_test_equal($pdo->inTransaction(),true,'Service committed caller transaction');
    $pdo->rollBack();
    music_listening_test_equal(music_listening_test_snapshot(),$beforeNested,'Outer rollback left listening rows');

    $expiresInput = music_listening_test_start();
    $expires = music_listening_test_call($expiresInput,$clock);
    $beforeExpiry = music_listening_test_snapshot();
    music_listening_test_rejects(static fn()=>music_listening_test_call(music_listening_test_event($expiresInput,$expires,1),$clock+86400000),410,'Expired session accepted event');
    music_listening_test_rejects(static fn()=>music_listening_test_call($expiresInput,$clock+86400000),410,'Expired start revived old attempt');
    music_listening_test_equal(music_listening_test_snapshot(),$beforeExpiry,'Expiry manufactured replacement history');

    // A long paused interval cannot be banked and spent as instantaneous playback.
    $bankInput = music_listening_test_start();
    $bank = music_listening_test_call($bankInput,$clock);
    music_listening_test_call(music_listening_test_event($bankInput,$bank,1),$clock);
    music_listening_test_call(music_listening_test_event($bankInput,$bank,2,['action'=>'pause','state'=>'paused','elapsed_ms'=>1000,'cumulative_played_ms'=>1000,'position_ms'=>1000]),$clock+1000);
    music_listening_test_call(music_listening_test_event($bankInput,$bank,3,['elapsed_ms'=>61000,'cumulative_played_ms'=>1000,'position_ms'=>1000]),$clock+61000);
    music_listening_test_no_credit(static fn()=>music_listening_test_call(music_listening_test_event($bankInput,$bank,4,[
        'action'=>'heartbeat','elapsed_ms'=>91000,'cumulative_played_ms'=>31000,'position_ms'=>31000,
    ]),$clock+61000),1000,'Paused wall time became an instantaneous accepted listening interval');

    $backdatedInput = music_listening_test_start();
    $backdated = music_listening_test_call($backdatedInput,$clock);
    music_listening_test_call(music_listening_test_event($backdatedInput,$backdated,1),$clock);
    music_listening_test_call(music_listening_test_event($backdatedInput,$backdated,2,[
        'action'=>'pause','state'=>'paused','elapsed_ms'=>1000,'cumulative_played_ms'=>1000,'position_ms'=>1000,
    ]),$clock+1000);
    music_listening_test_call(music_listening_test_event($backdatedInput,$backdated,3,[
        'elapsed_ms'=>1000,'cumulative_played_ms'=>1000,'position_ms'=>1000,
    ]),$clock+61000);
    $backdatedClaim = music_listening_test_call(music_listening_test_event($backdatedInput,$backdated,4,[
        'action'=>'heartbeat','elapsed_ms'=>31000,'cumulative_played_ms'=>31000,'position_ms'=>31000,
    ]),$clock+61000);
    music_listening_test_equal($backdatedClaim['accepted_played_ms'],1000,'Backdated resume borrowed earlier paused server time');
    music_listening_test_equal($backdatedClaim['coverage']['partial'],true,'Clipped backdated interval lacked partial coverage');

    // A delayed queue in one continuous playing segment retains its valid time;
    // bounding is not merely the gap between HTTP response arrivals.
    $delayedInput = music_listening_test_start(['muted'=>true,'loop'=>true]);
    $delayed = music_listening_test_call($delayedInput,$clock);
    music_listening_test_call(music_listening_test_event($delayedInput,$delayed,1),$clock);
    foreach ([2=>5000,3=>10000] as $seq=>$elapsed) {
        $delayedReceipt = music_listening_test_call(music_listening_test_event($delayedInput,$delayed,$seq,[
            'action'=>'heartbeat','elapsed_ms'=>$elapsed,'cumulative_played_ms'=>$elapsed,'position_ms'=>$elapsed,
            'observation_partial'=>true,
        ]),$clock+10000);
        music_listening_test_equal($delayedReceipt['accepted_played_ms'],$elapsed,'Delayed continuous-segment evidence was lost');
    }
    music_listening_test_equal($delayedReceipt['coverage']['partial'],true,'Client interruption diagnostic was discarded');
    music_listening_test_equal($delayedReceipt['coverage']['muted_observed'],true,'Mute observation disappeared on later events');
    music_listening_test_equal($delayedReceipt['coverage']['loop_observed'],true,'Loop observation disappeared on later events');
    music_listening_test_equal($delayedReceipt['coverage']['attention'],'not_observed','Playback was promoted to proof of human attention');

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
        $pdo->exec('PRAGMA foreign_keys=OFF');
        music_listening_test_equal(music_listening_v1_schema_ready($pdo),true,'Report confused schema shape with connection enforcement');
        music_listening_test_call(music_listening_test_start(),$clock);
        music_listening_test_equal((int)$pdo->query('PRAGMA foreign_keys')->fetchColumn(),1,'Service did not enable required SQLite FK enforcement');
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $beforeNoForeignKeys = music_listening_test_snapshot();
        $pdo->beginTransaction();
        music_listening_test_rejects(static fn()=>music_listening_test_call(music_listening_test_start(),$clock),503,'Unsafe caller transaction with FK enforcement off');
        music_listening_test_equal($pdo->inTransaction(),true,'Unsafe connection rejection committed caller transaction');
        $pdo->rollBack();
        $pdo->exec('PRAGMA foreign_keys=ON');
        music_listening_test_equal(music_listening_test_snapshot(),$beforeNoForeignKeys,'Unsafe caller transaction wrote listening data');

        // A legacy cleanup connection may not know about sidecars and may have
        // disabled SQLite cascades. Reusing its old integer ID must not let a
        // valid new token attach to the removed attempt's play UUID/identity.
        $reusedInput = music_listening_test_start();
        $removed = music_listening_test_call($reusedInput,$clock);
        music_listening_test_call(music_listening_test_event($reusedInput,$removed,1),$clock);
        $replacement = music_listening_test_native($removed['session_id']);
        $replacement['session_token'] = bin2hex(random_bytes(32));
        $pdo->exec('PRAGMA foreign_keys=OFF');
        try {
            $pdo->prepare('DELETE FROM track_play_events WHERE session_id=?')->execute([$removed['session_id']]);
            $pdo->prepare('DELETE FROM track_play_sessions WHERE id=?')->execute([$removed['session_id']]);
            $columns = array_keys($replacement);
            $pdo->prepare('INSERT INTO track_play_sessions ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($replacement));
        } finally { $pdo->exec('PRAGMA foreign_keys=ON'); }
        $beforeReused = music_listening_test_snapshot();
        music_listening_test_rejects(static fn()=>music_listening_test_call($reusedInput,$clock+1000),410,'Start replay reattached a reused history ID','session_removed');
        $reusedEvent = music_listening_test_event($reusedInput,$removed,2,[
            'session_token'=>$replacement['session_token'],'action'=>'heartbeat','elapsed_ms'=>1000,'cumulative_played_ms'=>1000,'position_ms'=>1000,
        ]);
        music_listening_test_rejects(static fn()=>music_listening_test_call($reusedEvent,$clock+1000),410,'New token attached to a removed play UUID','session_removed');
        music_listening_test_rejects(static fn()=>music_listening_test_call(array_replace($reusedEvent,['session_token'=>$removed['session_token']]),$clock+1000),404,'Removed bearer token reached reused history');
        music_listening_test_equal(music_listening_test_snapshot(),$beforeReused,'Rejected reused history ID changed receipts, counters or identity');
    }

    $report = music_listening_v1_report($pdo);
    music_listening_test_equal($report['counts']['legacy_unversioned_rows'],1,'Legacy history coverage changed during measured playback');
    music_listening_test_equal((int)$pdo->query('SELECT COUNT(*) FROM playlist_tracks WHERE playlist_id=5 AND track_id=42')->fetchColumn(),1,'Listening changed playlist membership');
    music_listening_test_equal((int)$pdo->query('SELECT COUNT(*) FROM track_favorites WHERE user_id=11 AND track_id=42')->fetchColumn(),1,'Listening changed favorites');
}

if (!defined('MUSIC_LISTENING_TEST_LIBRARY_ONLY')) {
    try {
        music_listening_test_run();
        echo "MUSIC_LISTENING_V1=PASS real SQLite migration, timing/seek/pause/rate bounds, identity/provenance, native history, exact retries, strict sequences, authority, expiry, private sources, actual rollback and asset-version preservation\n";
    } finally { music_listening_test_clean_files(STONEFELLOW_ROOT); }
}
