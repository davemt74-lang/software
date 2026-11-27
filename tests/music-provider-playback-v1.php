<?php
declare(strict_types=1);

/** Real database provider acceptance, shared unchanged with native PDO MySQL. */
if(!defined('STONEFELLOW_ROOT'))define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-provider-playback-'.bin2hex(random_bytes(6)));
require_once __DIR__.'/fixtures/music-listening-v1.php';
require_once dirname(__DIR__).'/includes/music-provider-v1.php';
$config=['site'=>['base_url'=>'https://vp3.example.com','base_path'=>'']];

function provider_playback_test_equal(mixed $actual,mixed $expected,string $label): void
{
    $same=is_array($actual)&&is_array($expected)?music_provider_v1_json($actual)===music_provider_v1_json($expected):$actual===$expected;
    if(!$same)throw new RuntimeException($label.' failed.');
}

function provider_playback_test_reject(callable $call,int $status,string $label,?string $code=null): void
{
    try{$call();}catch(MusicProviderV1Exception $error){
        provider_playback_test_equal($error->httpStatus,$status,$label.' status');
        if($code!==null)provider_playback_test_equal($error->publicCode,$code,$label.' code');return;
    }
    throw new RuntimeException($label.' unexpectedly succeeded.');
}

function provider_playback_test_owner(string $action,array $input,int $now,int $userId=11,array $context=[]): array
{
    $GLOBALS['musicSongTestUserId']=$userId;
    return music_provider_v1_owner_action(db(),current_user(),['protocol'=>'vp3_music_provider_v1','action'=>$action,'workspace_id'=>1]+$input,['now_ms'=>$now]+$context);
}

function provider_playback_test_site(int $clock,array $recordings,string $origin='https://artist.example.com',int $userId=11,string $environment='production'): array
{
    $created=provider_playback_test_owner('site.create',['request_id'=>music_song_metadata_v1_uuid(),'origin'=>$origin,'label'=>'Provider acceptance','environment'=>$environment,'credential'=>'vmp1_'.bin2hex(random_bytes(32))],$clock,$userId)['site'];
    $verified=provider_playback_test_owner('site.verify',['site_id'=>$created['site_id'],'request_id'=>music_song_metadata_v1_uuid(),'expected_revision'=>$created['revision']],$clock,$userId,['verify_request'=>static fn()=> $created['verification']['response']])['site'];
    return provider_playback_test_owner('selection.update',['site_id'=>$verified['site_id'],'request_id'=>music_song_metadata_v1_uuid(),'expected_selection_revision'=>$verified['selection_revision'],'recording_ids'=>$recordings],$clock,$userId)['site'];
}

function provider_playback_test_begin(array $site,string $recording,array $changes=[]): array
{
    return array_replace(['protocol'=>'vp3_music_provider_v1','action'=>'playback.begin','site_id'=>$site['site_id'],'recording_id'=>$recording,
        'play_id'=>music_song_metadata_v1_uuid(),'playback_key'=>'mps1_'.bin2hex(random_bytes(32)),
        'source_url'=>$site['origin'].'/music.php?access_token=PRIVATE-QUERY#PRIVATE-FRAGMENT','source_surface'=>'player',
        'position_ms'=>0,'playback_rate'=>1],$changes);
}

function provider_playback_test_event(array $begin,array $receipt,int $seq,array $changes=[]): array
{
    return array_replace(['protocol'=>'vp3_music_provider_v1','action'=>'playback.events','site_id'=>$begin['site_id'],'session_id'=>$receipt['session_id'],
        'event_id'=>music_song_metadata_v1_uuid(),'seq'=>$seq,'event_type'=>'resume','state'=>'playing',
        'position_ms'=>0,'elapsed_ms'=>0,'cumulative_played_ms'=>0,'playback_rate'=>1],$changes);
}

function provider_playback_test_call(array $input,int $clock,?array $begin=null,array $context=[]): array
{
    return music_provider_v1_playback_action(db(),$input,array_replace(['now_ms'=>$clock,'playback_key'=>$begin['playback_key']??null],$context));
}

function provider_playback_test_ticket(array $receipt): string
{
    parse_str((string)parse_url($receipt['media']['url'],PHP_URL_QUERY),$query);return $query['ticket'];
}

function provider_playback_test_session(string $id): array
{
    $q=db()->prepare('SELECT * FROM music_provider_sessions_v1 WHERE session_id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC);
}

function provider_playback_test_fault(string $table): void
{
    if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')db()->exec("CREATE TRIGGER provider_test_failure BEFORE INSERT ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture receipt failure'");
    else db()->exec("CREATE TRIGGER provider_test_failure BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT,'fixture receipt failure'); END");
}

function provider_playback_test_run(): void
{
    $pdo=db();$clock=1800000000000;
    music_listening_test_seed();music_song_metadata_v1_install($pdo);
    $legacy=$pdo->query('SELECT * FROM track_play_sessions')->fetchAll(PDO::FETCH_ASSOC);
    $uninstalled=music_listening_test_snapshot();
    provider_playback_test_reject(static fn()=>music_provider_v1_playback_action($pdo,['protocol'=>'vp3_music_provider_v1','action'=>'playback.begin']),503,'Uninstalled requests cannot run DDL');
    provider_playback_test_equal(music_listening_test_snapshot(),$uninstalled,'Uninstalled request wrote data');
    music_provider_v1_install($pdo);$installed=music_listening_test_snapshot();
    music_provider_v1_install($pdo);provider_playback_test_equal(music_listening_test_snapshot(),$installed,'Provider install changed existing identity on rerun');
    music_provider_v1_report($pdo);provider_playback_test_equal(music_listening_test_snapshot(),$installed,'Operational report wrote rows');
    $recordings=$pdo->query('SELECT catalog_track_id,recording_id FROM music_recordings_v1')->fetchAll(PDO::FETCH_KEY_PAIR);
    $site=provider_playback_test_site($clock,[$recordings[10],$recordings[11]]);
    $begin=provider_playback_test_begin($site,$recordings[10]);
    $before=music_listening_test_snapshot();
    $pdo->beginTransaction();
    provider_playback_test_reject(static fn()=>provider_playback_test_call($begin,$clock),409,'New grant inside caller transaction','transaction_boundary_required');
    provider_playback_test_equal($pdo->inTransaction(),true,'Rejected new grant ended caller transaction');$pdo->rollBack();
    provider_playback_test_equal(music_listening_test_snapshot(),$before,'Rejected nested begin mutated rows');
    provider_playback_test_reject(static fn()=>provider_playback_test_call($begin,$clock,null,['origin'=>'https://evil.example.com']),403,'Foreign origin');
    provider_playback_test_equal(music_listening_test_snapshot(),$before,'Rejected origin created evidence');
    provider_playback_test_reject(static fn()=>provider_playback_test_call(array_replace($begin,['recording_id'=>$recordings[20]]),$clock),404,'Foreign workspace private recording');
    provider_playback_test_reject(static fn()=>provider_playback_test_call(array_replace($begin,['playback_key'=>'vmp1_'.str_repeat('a',64)]),$clock),404,'Management credential is not visitor authority');
    provider_playback_test_reject(static fn()=>provider_playback_test_call(array_replace($begin,['muted'=>null]),$clock),422,'Null observation flag');
    $start=provider_playback_test_call($begin,$clock,null,['origin'=>$site['origin']]);
    provider_playback_test_equal($start['accepted_played_ms'],0,'Allocation accrued listening');
    provider_playback_test_equal($start['started'],false,'Allocation counted a start');
    provider_playback_test_equal($start['duration_ms'],null,'WAV/catalog duration promoted into authoritative completion');
    provider_playback_test_equal($start['completed'],null,'Unknown duration created completion');
    provider_playback_test_equal($start['source']['url'],$site['origin'].'/music.php','Source query/fragment leaked');
    provider_playback_test_equal($start['source']['page_verified'],false,'Reported page promoted into independent proof');
    provider_playback_test_equal($start['source_page_profile'],'public_music_pages_v2','New begin receipt lacks source policy marker');
    provider_playback_test_equal($start['coverage']['listener_identity'],'anonymous_session_only','Provider invented fan identity');
    $snapshot=music_listening_test_snapshot();
    provider_playback_test_equal(provider_playback_test_call($begin,$clock+10),$start,'Begin retry changed receipt');
    provider_playback_test_equal(provider_playback_test_call(array_replace($begin,['source_url'=>$site['origin'].'/music.php?another=redacted']),$clock+10),$start,'Redacted query changed logical attribution');
    provider_playback_test_equal(music_listening_test_snapshot(),$snapshot,'Begin retry created rows');
    provider_playback_test_reject(static fn()=>provider_playback_test_call(array_replace($begin,['position_ms'=>1]),$clock+10),409,'Changed begin reused play');
    provider_playback_test_reject(static fn()=>provider_playback_test_call(array_replace($begin,['playback_key'=>'mps1_'.str_repeat('b',64)]),$clock+10),409,'Wrong begin key fetched receipt');

    $ticket=provider_playback_test_ticket($start);$bytes=(string)file_get_contents(STONEFELLOW_ROOT.'/uploads/artist-music/1/legacy.wav');
    $head=music_provider_v1_media($pdo,$ticket,['now_ms'=>$clock+20,'method'=>'HEAD','range'=>'bytes=20-30']);
    provider_playback_test_equal($head['range'],['status'=>200,'start'=>0,'end'=>strlen($bytes)-1,'length'=>strlen($bytes)],'HEAD incorrectly applied Range');
    fclose($head['handle']);music_provider_v1_delivery_record($pdo,$head,'HEAD',200,0,$clock+20);
    $headSnapshot=music_listening_test_snapshot();music_provider_v1_delivery_record($pdo,$head,'HEAD',200,0,$clock+21);
    provider_playback_test_equal(music_listening_test_snapshot(),$headSnapshot,'Delivery retry repeated metering');
    $range=music_provider_v1_media($pdo,$ticket,['now_ms'=>$clock+30,'method'=>'GET','range'=>'bytes=-20']);
    provider_playback_test_equal($range['range']['start'],strlen($bytes)-20,'Suffix range start');
    fseek($range['handle'],$range['range']['start']);provider_playback_test_equal(stream_get_contents($range['handle']),substr($bytes,-20),'Suffix snapshot bytes');
    fclose($range['handle']);music_provider_v1_delivery_record($pdo,$range,'GET',206,20,$clock+30);
    provider_playback_test_reject(static fn()=>music_provider_v1_delivery_record($pdo,$range,'GET',206,19,$clock+31),409,'Conflicting delivery accounting');
    $mediaSession=provider_playback_test_session($start['session_id']);
    provider_playback_test_equal((int)$mediaSession['accepted_played_ms'],0,'Byte delivery became listening');
    provider_playback_test_equal($mediaSession['started_ms'],null,'Byte delivery became a started play');
    provider_playback_test_equal((int)$mediaSession['emitted_bytes'],20,'Server-emitted bytes metering');
    provider_playback_test_equal((int)$mediaSession['reserved_bytes'],20,'Transfer reservation metering');
    foreach(['bytes=1-2,4-5','bytes=999999999999999999999999-','bytes=-0','items=0-1'] as $invalid){
        $descriptor=music_provider_v1_media($pdo,$ticket,['now_ms'=>$clock+35,'range'=>$invalid]);
        provider_playback_test_equal($descriptor['range']['status'],416,'Invalid/multiple range');fclose($descriptor['handle']);
        music_provider_v1_delivery_record($pdo,$descriptor,'GET',416,0,$clock+35);
    }
    $full=music_provider_v1_media($pdo,$ticket,['now_ms'=>$clock+40,'range'=>'bytes=10-20','if_range'=>'"different-version"']);
    provider_playback_test_equal($full['range']['status'],200,'Mismatched If-Range must return full pinned body');fclose($full['handle']);
    music_provider_v1_delivery_record($pdo,$full,'GET',200,0,$clock+40);
    $partial=music_provider_v1_media($pdo,$ticket,['now_ms'=>$clock+40,'range'=>'bytes=10-','if_range'=>$start['media']['etag']]);
    provider_playback_test_equal($partial['range']['status'],206,'Strong matching If-Range');fclose($partial['handle']);music_provider_v1_delivery_record($pdo,$partial,'GET',206,0,$clock+40);
    $immutable=music_provider_v1_media($pdo,$ticket,['now_ms'=>$clock+50]);
    file_put_contents(STONEFELLOW_ROOT.'/uploads/artist-music/1/legacy.wav',str_repeat('X',strlen($bytes)));
    provider_playback_test_equal(stream_get_contents($immutable['handle']),$bytes,'In-place mutation changed already validated response');
    fclose($immutable['handle']);music_provider_v1_delivery_record($pdo,$immutable,'GET',200,strlen($bytes),$clock+50);
    $beforeMismatch=music_listening_test_snapshot();
    provider_playback_test_reject(static fn()=>music_provider_v1_media($pdo,$ticket,['now_ms'=>$clock+60]),404,'Changed bytes under old asset');
    provider_playback_test_equal(music_listening_test_snapshot(),$beforeMismatch,'Rejected asset mutated metering');
    file_put_contents(STONEFELLOW_ROOT.'/uploads/artist-music/1/legacy.wav',$bytes);
    provider_playback_test_reject(static fn()=>music_provider_v1_media($pdo,substr($ticket,0,-1).($ticket[-1]==='a'?'b':'a'),['now_ms'=>$clock+60]),404,'Tampered media ticket');
    provider_playback_test_reject(static fn()=>music_provider_v1_media($pdo,$ticket,['now_ms'=>$clock+60,'origin'=>'https://evil.example.com']),403,'Media foreign origin');

    $resume=provider_playback_test_event($begin,$start,1);
    $playing=provider_playback_test_call($resume,$clock+100,$begin);
    provider_playback_test_equal($playing['started'],true,'Accepted playing boundary did not record start');
    provider_playback_test_equal($playing['accepted_played_ms'],0,'Resume itself accrued time');
    $heartbeat=provider_playback_test_event($begin,$start,2,['event_type'=>'heartbeat','elapsed_ms'=>10100,'cumulative_played_ms'=>10000,'position_ms'=>10000]);
    $listened=provider_playback_test_call($heartbeat,$clock+10100,$begin);
    provider_playback_test_equal($listened['accepted_played_ms'],10000,'Ten accepted seconds');
    provider_playback_test_equal($listened['qualified_play'],true,'Legacy engagement threshold');
    provider_playback_test_equal($listened['coverage']['delivery'],'server_emitted_bytes','Delivery evidence not distinguished');
    $afterEvent=music_listening_test_snapshot();
    provider_playback_test_equal(provider_playback_test_call($heartbeat,$clock+10200,$begin),$listened,'Event retry changed receipt');
    provider_playback_test_equal(music_listening_test_snapshot(),$afterEvent,'Event retry repeated listening');
    provider_playback_test_reject(static fn()=>provider_playback_test_call(array_replace($heartbeat,['position_ms'=>11000]),$clock+10200,$begin),409,'Conflicting event');
    provider_playback_test_reject(static fn()=>provider_playback_test_call(provider_playback_test_event($begin,$start,4),$clock+10200,$begin),409,'Sequence gap');
    provider_playback_test_reject(static fn()=>provider_playback_test_call(array_replace($heartbeat,['site_id'=>music_song_metadata_v1_uuid()]),$clock+10200,$begin),403,'Mismatched site/session CORS authority');
    provider_playback_test_reject(static fn()=>provider_playback_test_call($heartbeat,$clock+10200,['playback_key'=>'vmp1_'.str_repeat('c',64)]),404,'Management key used as event credential');

    $pause=provider_playback_test_event($begin,$start,3,['event_type'=>'pause','state'=>'paused','elapsed_ms'=>12100,'cumulative_played_ms'=>12000,'position_ms'=>12000]);
    provider_playback_test_fault('music_provider_receipts_v1');$beforeFailure=music_listening_test_snapshot();
    try{provider_playback_test_call($pause,$clock+12100,$begin);throw new RuntimeException('Receipt failure did not roll back.');}catch(PDOException){}
    provider_playback_test_equal(music_listening_test_snapshot(),$beforeFailure,'Failed receipt left accepted listening');
    $pdo->beginTransaction();$pdo->exec("UPDATE users SET display_name='Outer transaction' WHERE id=11");
    try{provider_playback_test_call($pause,$clock+12100,$begin);throw new RuntimeException('Nested receipt failure did not roll back.');}catch(PDOException){}
    provider_playback_test_equal($pdo->inTransaction(),true,'Provider failure lost caller transaction');
    provider_playback_test_equal($pdo->query('SELECT display_name FROM users WHERE id=11')->fetchColumn(),'Outer transaction','Provider rollback lost unrelated caller write');
    $pdo->rollBack();$pdo->exec('DROP TRIGGER provider_test_failure');
    $paused=provider_playback_test_call($pause,$clock+12100,$begin);
    provider_playback_test_equal($paused['accepted_played_ms'],12000,'Final active pause interval');
    $resume2=provider_playback_test_event($begin,$start,4,['elapsed_ms'=>72100,'cumulative_played_ms'=>12000,'position_ms'=>12000]);
    provider_playback_test_call($resume2,$clock+72100,$begin);
    $bank=provider_playback_test_event($begin,$start,5,['event_type'=>'heartbeat','elapsed_ms'=>73100,'cumulative_played_ms'=>72000,'position_ms'=>13000]);
    $bounded=provider_playback_test_call($bank,$clock+73100,$begin);
    provider_playback_test_equal($bounded['accepted_played_ms'],13000,'Paused time funded future playback');
    provider_playback_test_equal($bounded['coverage']['partial'],true,'Clipped time lacked partial coverage');
    $seek=provider_playback_test_event($begin,$start,6,['event_type'=>'seek','elapsed_ms'=>74100,'cumulative_played_ms'=>73000,'position_ms'=>90000]);
    provider_playback_test_equal(provider_playback_test_call($seek,$clock+74100,$begin)['accepted_played_ms'],13000,'Seek added skipped position as time');
    $ended=provider_playback_test_event($begin,$start,7,['event_type'=>'ended','state'=>'ended','elapsed_ms'=>74100,'cumulative_played_ms'=>73000,'position_ms'=>90000]);
    $endReceipt=provider_playback_test_call($ended,$clock+74100,$begin);
    provider_playback_test_equal($endReceipt['completed'],null,'Natural end supplied unknown completion');
    provider_playback_test_equal($endReceipt['natural_ended'],true,'Natural end lost diagnostic');
    provider_playback_test_equal(provider_playback_test_call($ended,$clock+74200,$begin),$endReceipt,'Terminal event retry');
    provider_playback_test_reject(static fn()=>provider_playback_test_call(provider_playback_test_event($begin,$start,8),$clock+74300,$begin),410,'Closed play resumed');
    provider_playback_test_reject(static fn()=>music_provider_v1_media($pdo,$ticket,['now_ms'=>$clock+74300]),410,'Closed session media');

    // A delayed resume cannot credit the elapsed pause as a preceding segment.
    $lateBegin=provider_playback_test_begin($site,$recordings[10]);$late=provider_playback_test_call($lateBegin,$clock);
    provider_playback_test_call(provider_playback_test_event($lateBegin,$late,1),$clock,$lateBegin);
    provider_playback_test_call(provider_playback_test_event($lateBegin,$late,2,['event_type'=>'pause','state'=>'paused','elapsed_ms'=>1000,'cumulative_played_ms'=>1000,'position_ms'=>1000]),$clock+1000,$lateBegin);
    provider_playback_test_call(provider_playback_test_event($lateBegin,$late,3,['elapsed_ms'=>2000,'cumulative_played_ms'=>1000,'position_ms'=>1000]),$clock+80000,$lateBegin);
    $backdated=provider_playback_test_call(provider_playback_test_event($lateBegin,$late,4,['event_type'=>'heartbeat','elapsed_ms'=>30000,'cumulative_played_ms'=>29000,'position_ms'=>29000]),$clock+80000,$lateBegin);
    provider_playback_test_equal($backdated['accepted_played_ms'],1000,'Backdated resume banked server pause');
    provider_playback_test_equal($backdated['coverage']['partial'],true,'Backdated clipping concealed');
    $lateRenew=['protocol'=>'vp3_music_provider_v1','action'=>'playback.renew','site_id'=>$site['site_id'],'session_id'=>$late['session_id'],'request_id'=>music_song_metadata_v1_uuid()];
    $continued=provider_playback_test_call($lateRenew,$clock+80001,$lateBegin);
    provider_playback_test_equal($continued['accepted_played_ms'],1000,'Renewal reset acknowledged time');
    provider_playback_test_equal($continued['accepted_seq'],4,'Renewal reset event sequence');
    provider_playback_test_equal(provider_playback_test_session($late['session_id'])['state'],'playing','Renewal changed playback state');
    $boundedBegin=provider_playback_test_begin($site,$recordings[10]);$boundedPlay=provider_playback_test_call($boundedBegin,$clock);
    provider_playback_test_call(provider_playback_test_event($boundedBegin,$boundedPlay,1),$clock,$boundedBegin);
    $overInterval=provider_playback_test_call(provider_playback_test_event($boundedBegin,$boundedPlay,2,['event_type'=>'heartbeat','elapsed_ms'=>60000,'cumulative_played_ms'=>60000,'position_ms'=>60000]),$clock+60000,$boundedBegin);
    provider_playback_test_equal($overInterval['accepted_played_ms'],30000,'Single-event elapsed bound');
    provider_playback_test_equal($overInterval['coverage']['partial'],true,'Single-event clipping concealed');

    $pinBegin=provider_playback_test_begin($site,$recordings[10]);$pin=provider_playback_test_call($pinBegin,$clock);
    $pinTicket=provider_playback_test_ticket($pin);
    $pdo->exec("UPDATE artist_catalog_tracks_v181 SET audio_path='/uploads/artist-music/1/pending.wav' WHERE id=10");
    provider_playback_test_reject(static fn()=>provider_playback_test_call(provider_playback_test_begin($site,$recordings[10]),$clock+100),404,'Fresh play used unready current rendition');
    $oldWhilePending=music_provider_v1_media($pdo,$pinTicket,['now_ms'=>$clock+100]);
    provider_playback_test_equal(stream_get_contents($oldWhilePending['handle']),$bytes,'Pending replacement broke old pinned rendition');
    fclose($oldWhilePending['handle']);music_provider_v1_delivery_record($pdo,$oldWhilePending,'GET',200,strlen($bytes),$clock+100);
    $pdo->exec("UPDATE artist_catalog_tracks_v181 SET audio_path='/uploads/artist-music/1/legacy.wav' WHERE id=10");
    $replacementPath='/uploads/artist-music/1/replacement.wav';$replacement=substr_replace($bytes,"\x01\x00",44,2);
    file_put_contents(STONEFELLOW_ROOT.$replacementPath,$replacement);
    $newAsset=music_song_metadata_v1_uuid();
    $pdo->prepare('INSERT INTO music_assets_v1 (asset_id,workspace_id,recording_id,stored_path,source_fingerprint,sha256,byte_size,created_at) VALUES (?,1,?,?,?,?,?,CURRENT_TIMESTAMP)')->execute([$newAsset,$recordings[10],$replacementPath,hash('sha256','replacement'),hash('sha256',$replacement),strlen($replacement)]);
    $pdo->prepare('UPDATE artist_catalog_tracks_v181 SET audio_path=? WHERE id=10')->execute([$replacementPath]);
    $pdo->prepare('UPDATE tracks SET audio_path=? WHERE id=42')->execute([$replacementPath]);
    $pdo->prepare('UPDATE music_recordings_v1 SET current_asset_id=? WHERE catalog_track_id=10')->execute([$newAsset]);
    $old=music_provider_v1_media($pdo,$pinTicket,['now_ms'=>$clock+1000]);provider_playback_test_equal(stream_get_contents($old['handle']),$bytes,'Replacement spliced active asset');fclose($old['handle']);music_provider_v1_delivery_record($pdo,$old,'GET',200,strlen($bytes),$clock+1000);
    $renew=['protocol'=>'vp3_music_provider_v1','action'=>'playback.renew','site_id'=>$site['site_id'],'session_id'=>$pin['session_id'],'request_id'=>music_song_metadata_v1_uuid()];
    $renewed=provider_playback_test_call($renew,$clock+300001,$pinBegin);
    provider_playback_test_equal($renewed['session_id'],$pin['session_id'],'Renewal changed logical session');
    provider_playback_test_equal($renewed['asset_id'],$pin['asset_id'],'Renewal rebound replacement asset');
    provider_playback_test_equal($renewed['source'],$pin['source'],'Renewal replaced initiating source');
    provider_playback_test_equal($renewed['accepted_seq'],0,'Renewal created a listening event');
    $renewSnapshot=music_listening_test_snapshot();provider_playback_test_equal(provider_playback_test_call($renew,$clock+300002,$pinBegin),$renewed,'Renewal retry changed media ticket');
    provider_playback_test_equal(music_listening_test_snapshot(),$renewSnapshot,'Renewal retry wrote duplicate receipt');
    provider_playback_test_reject(static fn()=>music_provider_v1_media($pdo,$pinTicket,['now_ms'=>$clock+300001]),410,'Expired media ticket');
    $fresh=music_provider_v1_media($pdo,provider_playback_test_ticket($renewed),['now_ms'=>$clock+300002]);provider_playback_test_equal(stream_get_contents($fresh['handle']),$bytes,'Renewed pinned media changed bytes');fclose($fresh['handle']);music_provider_v1_delivery_record($pdo,$fresh,'GET',200,strlen($bytes),$clock+300002);
    $newBegin=provider_playback_test_begin($site,$recordings[10]);$newPlay=provider_playback_test_call($newBegin,$clock+300002);provider_playback_test_equal($newPlay['asset_id'],$newAsset,'New playback ignored replacement');
    $newMedia=music_provider_v1_media($pdo,provider_playback_test_ticket($newPlay),['now_ms'=>$clock+300002]);provider_playback_test_equal(stream_get_contents($newMedia['handle']),$replacement,'New playback did not receive replacement bytes');fclose($newMedia['handle']);music_provider_v1_delivery_record($pdo,$newMedia,'GET',200,strlen($replacement),$clock+300002);

    // Newly recognized Stoneward pages are still reported context, not proof.
    foreach(['/track/public-song','/track/vp3_ABC-123/','/release/album-2026','/release/album/','/stonefellow-v120.php'] as $path){
        $pageBegin=provider_playback_test_begin($site,$recordings[10],['source_url'=>$site['origin'].$path.'?token=PRIVATE-QUERY#PRIVATE-FRAGMENT']);
        $pageReply=provider_playback_test_call($pageBegin,$clock+300010);
        provider_playback_test_equal($pageReply['source']['url'],$site['origin'].$path,'Public catalog page unavailable');
        provider_playback_test_equal($pageReply['source']['reason'],'details_redacted','Public page private details retained');
        provider_playback_test_equal($pageReply['source']['page_verified'],false,'Public route became verified page');
        provider_playback_test_equal(provider_playback_test_call($pageBegin,$clock+300011),$pageReply,'Public page exact replay changed receipt');
        provider_playback_test_reject(static fn()=>provider_playback_test_call(array_replace($pageBegin,['source_url'=>$site['origin'].'/track/different-song']),$clock+300012),409,'Changed public page repurposed a play');
    }
    // An old provider's omitted-page receipt stays immutable across the
    // additive route upgrade, including an acknowledgement lost at deployment.
    $upgradeBegin=provider_playback_test_begin($site,$recordings[10],['source_url'=>$site['origin'].'/track/previously-omitted']);
    $upgradeReceipt=provider_playback_test_call($upgradeBegin,$clock+300010);
    $oldSource=$upgradeReceipt['source'];$oldSource['url']=null;$oldSource['reason']='restricted_path';
    $oldReceipt=$upgradeReceipt;$oldReceipt['source']=$oldSource;unset($oldReceipt['source_page_profile']);
    $oldHash=hash('sha256',music_provider_v1_json(['recording_id'=>$recordings[10],'position_ms'=>0,'playback_rate'=>1.0,'muted'=>false,'loop'=>false,'source'=>$oldSource]));
    $pdo->prepare('UPDATE music_provider_sessions_v1 SET source_json=?,begin_hash=?,begin_receipt_json=? WHERE session_id=?')
        ->execute([music_provider_v1_json($oldSource),$oldHash,music_provider_v1_json($oldReceipt),$upgradeReceipt['session_id']]);
    $beforeUpgradeRetry=music_listening_test_snapshot();
    provider_playback_test_equal(provider_playback_test_call($upgradeBegin,$clock+300011),$oldReceipt,'Upgrade broke old omitted-page receipt replay');
    provider_playback_test_equal(music_listening_test_snapshot(),$beforeUpgradeRetry,'Upgrade replay rewrote historical source');
    provider_playback_test_reject(static fn()=>provider_playback_test_call(array_replace($upgradeBegin,['position_ms'=>1]),$clock+300012),409,'Upgrade exception accepted changed position');
    provider_playback_test_reject(static fn()=>provider_playback_test_call(array_replace($upgradeBegin,['playback_key'=>'mps1_'.str_repeat('f',64)]),$clock+300012),409,'Upgrade exception accepted a foreign visitor key');
    provider_playback_test_reject(static fn()=>provider_playback_test_call(array_replace($upgradeBegin,['source_url'=>$site['origin'].'/music.php']),$clock+300012),409,'Upgrade exception accepted a previously supported page');
    $newOmittedBegin=provider_playback_test_begin($site,$recordings[10],['source_url'=>$site['origin'].'/unsupported-page']);
    $newOmitted=provider_playback_test_call($newOmittedBegin,$clock+300010);
    provider_playback_test_equal($newOmitted['source_page_profile'],'public_music_pages_v2','New omitted-page receipt lacks policy marker');
    provider_playback_test_equal($newOmitted['source']['reason'],'restricted_path','New unsupported page must remain omitted');
    provider_playback_test_reject(static fn()=>provider_playback_test_call(array_replace($newOmittedBegin,['source_url'=>$site['origin'].'/track/new-public-page']),$clock+300012),409,'New v2 omitted-page session incorrectly used historical hash exception');

    // Provider snapshots do not expose unsafe paths, secrets, contacts or history.
    foreach([$site['origin'].'/account/PRIVATE-PATH/token','https://person:PRIVATE-PASSWORD@artist.example.com/music.php',$site['origin'].'/%2e%2e/private/credential','https://foreign.example.com/music.php',
        $site['origin'].'/track/a%2fb',$site['origin'].'/track/%2e%2e/music',$site['origin'].'/track/../music',
        $site['origin'].'/release/a.b',$site['origin'].'/track/a/secret',$site['origin'].'/track/'.str_repeat('a',161),
        $site['origin'].'/track/',$site['origin'].'/release/',$site['origin'].'/private/../music',
        $site['origin'].'/track/\\song',$site['origin'].'/track/song'."\n"] as $unsafe){
        $attempt=provider_playback_test_begin($site,$recordings[10],['source_url'=>$unsafe]);$reply=provider_playback_test_call($attempt,$clock+300010);
        provider_playback_test_equal($reply['source']['url'],null,'Unsafe source page retained');
        if(str_contains(json_encode($reply),'PRIVATE-'))throw new RuntimeException('Private source details leaked into receipt.');
    }
    $anonymous=provider_playback_test_call(provider_playback_test_begin($site,$recordings[10],['source_url'=>'']),$clock+300020);
    provider_playback_test_equal($anonymous['source']['reason'],'unavailable','Absent referrer/page blocked or invented');
    $mp3Begin=provider_playback_test_begin($site,$recordings[11]);$mp3=provider_playback_test_call($mp3Begin,$clock+300030);
    if(!is_int($mp3['duration_ms'])||$mp3['duration_ms']<1)throw new RuntimeException('Pinned MP3 duration was not server-probed.');
    provider_playback_test_equal($mp3['coverage']['duration'],'server_mp3_probe','MP3 duration provenance');
    provider_playback_test_call(provider_playback_test_event($mp3Begin,$mp3,1),$clock+300030,$mp3Begin);
    $completionTime=(int)ceil($mp3['duration_ms']*0.8);
    $mp3Completion=provider_playback_test_call(provider_playback_test_event($mp3Begin,$mp3,2,['event_type'=>'heartbeat','elapsed_ms'=>$completionTime,'cumulative_played_ms'=>$completionTime,'position_ms'=>$completionTime]),$clock+300030+$completionTime,$mp3Begin);
    provider_playback_test_equal($mp3Completion['completed'],true,'Exact pinned duration did not supply versioned completion');

    // Failed delivery admission is atomic too, including request and byte budgets.
    provider_playback_test_fault('music_provider_delivery_v1');$beforeFailure=music_listening_test_snapshot();
    try{music_provider_v1_media($pdo,provider_playback_test_ticket($newPlay),['now_ms'=>$clock+300040]);throw new RuntimeException('Delivery failure did not surface.');}catch(PDOException){}
    provider_playback_test_equal(music_listening_test_snapshot(),$beforeFailure,'Failed delivery left reserved usage');$pdo->exec('DROP TRIGGER provider_test_failure');
    $pdo->prepare('UPDATE music_provider_sessions_v1 SET media_requests=? WHERE session_id=?')->execute([MUSIC_PROVIDER_V1_MAX_MEDIA_REQUESTS,$newPlay['session_id']]);
    provider_playback_test_reject(static fn()=>music_provider_v1_media($pdo,provider_playback_test_ticket($newPlay),['now_ms'=>$clock+300040]),429,'Media request bound');
    $pdo->prepare('UPDATE music_provider_sessions_v1 SET media_requests=0,reserved_bytes=? WHERE session_id=?')->execute([MUSIC_PROVIDER_V1_MAX_RESERVED_BYTES,$newPlay['session_id']]);
    provider_playback_test_reject(static fn()=>music_provider_v1_media($pdo,provider_playback_test_ticket($newPlay),['now_ms'=>$clock+300040,'range'=>'bytes=0-0']),429,'Reserved media byte bound');
    $pdo->prepare('UPDATE music_provider_sessions_v1 SET last_seq=? WHERE session_id=?')->execute([MUSIC_PROVIDER_V1_MAX_EVENTS,$newPlay['session_id']]);
    provider_playback_test_reject(static fn()=>provider_playback_test_call(provider_playback_test_event($newBegin,$newPlay,MUSIC_PROVIDER_V1_MAX_EVENTS+1),$clock+300040,$newBegin),429,'Event receipt bound');
    provider_playback_test_reject(static fn()=>provider_playback_test_call($renew,$clock+MUSIC_PROVIDER_V1_SESSION_MS,$pinBegin),410,'Session expiry');

    // Current publication and creator authority are checked even for saved receipts.
    $pdo->exec('UPDATE tracks SET is_published=0 WHERE id=42');
    provider_playback_test_reject(static fn()=>provider_playback_test_call($renew,$clock+300050,$pinBegin),404,'Unpublished source retry');
    $pdo->exec('UPDATE tracks SET is_published=1 WHERE id=42');
    $pdo->exec('UPDATE users SET is_active=0 WHERE id=11');
    provider_playback_test_reject(static fn()=>provider_playback_test_call($renew,$clock+300050,$pinBegin),403,'Inactive artist retry');
    $pdo->exec('UPDATE users SET is_active=1 WHERE id=11');
    $managerSite=provider_playback_test_site($clock+300060,[$recordings[10]],'https://manager.example.com',12,'staging');
    $managerBegin=provider_playback_test_begin($managerSite,$recordings[10]);$managerPlay=provider_playback_test_call($managerBegin,$clock+300060);
    provider_playback_test_equal($managerPlay['coverage']['listen_class'],'staging_test','Staging traffic labeled audience');
    $pdo->exec('DELETE FROM artist_team_members WHERE member_user_id=12');
    provider_playback_test_reject(static fn()=>music_provider_v1_media($pdo,provider_playback_test_ticket($managerPlay),['now_ms'=>$clock+300070]),403,'Removed manager retained media');
    $GLOBALS['musicSongTestUserId']=11;

    $removed=provider_playback_test_owner('selection.update',['site_id'=>$site['site_id'],'request_id'=>music_song_metadata_v1_uuid(),'expected_selection_revision'=>$site['selection_revision'],'recording_ids'=>[$recordings[11]]],$clock+300080)['site'];
    $readded=provider_playback_test_owner('selection.update',['site_id'=>$site['site_id'],'request_id'=>music_song_metadata_v1_uuid(),'expected_selection_revision'=>$removed['selection_revision'],'recording_ids'=>[$recordings[10],$recordings[11]]],$clock+300090)['site'];
    provider_playback_test_reject(static fn()=>provider_playback_test_call($renew,$clock+300100,$pinBegin),404,'Removed/readded selection revived old session');
    $withdrawBegin=provider_playback_test_begin($readded,$recordings[10]);$withdraw=provider_playback_test_call($withdrawBegin,$clock+300110);
    $pdo->beginTransaction();music_provider_v1_withdraw_recording($pdo,1,10);$pdo->rollBack();
    $stillActive=music_provider_v1_ticket_context($pdo,provider_playback_test_ticket($withdraw),['now_ms'=>$clock+300110]);provider_playback_test_equal($stillActive['session']['state'],'pending','Failed catalog transaction permanently revoked plays');
    $pdo->beginTransaction();music_provider_v1_withdraw_recording($pdo,1,10);$pdo->commit();
    provider_playback_test_equal(provider_playback_test_session($start['session_id'])['state'],'ended','Withdrawal rewrote completed historical state');
    provider_playback_test_reject(static fn()=>music_provider_v1_ticket_context($pdo,provider_playback_test_ticket($withdraw),['now_ms'=>$clock+300120]),404,'Withdrawal left old tickets valid');
    provider_playback_test_reject(static fn()=>provider_playback_test_call($withdrawBegin,$clock+300120),404,'Withdrawal exact retry revived old play');
    $revokedBegin=provider_playback_test_begin($readded,$recordings[11]);$revoked=provider_playback_test_call($revokedBegin,$clock+300130);
    provider_playback_test_owner('site.revoke',['site_id'=>$site['site_id'],'request_id'=>music_song_metadata_v1_uuid(),'expected_revision'=>$readded['revision']],$clock+300140);
    provider_playback_test_reject(static fn()=>music_provider_v1_media($pdo,provider_playback_test_ticket($revoked),['now_ms'=>$clock+300150]),403,'Revoked site retained delivery');
    provider_playback_test_equal($pdo->query('SELECT * FROM track_play_sessions')->fetchAll(PDO::FETCH_ASSOC),$legacy,'External listening altered personal history');
    provider_playback_test_equal((int)$pdo->query('SELECT COUNT(*) FROM playlist_tracks')->fetchColumn(),1,'Provider changed personal playlists');
    provider_playback_test_equal((int)$pdo->query('SELECT COUNT(*) FROM track_favorites')->fetchColumn(),1,'Provider changed personal favorites');
}

if(!defined('MUSIC_PROVIDER_PLAYBACK_TEST_LIBRARY_ONLY')){
    try{
        provider_playback_test_run();
        echo "MUSIC_PROVIDER_PLAYBACK_V1=PASS real SQLite additive schema, exact anonymous begin/event/renew receipts, native-history isolation, timing/seek/pause bounds, pinned snapshot bytes and replacement, GET/HEAD/Range/If-Range, independent usage and quotas, source privacy, publication/role/selection/site revocation, actual receipt/delivery rollback and savepoints\n";
    }finally{music_listening_test_clean_files(STONEFELLOW_ROOT);}
}
