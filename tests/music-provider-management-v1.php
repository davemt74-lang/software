<?php
declare(strict_types=1);

/** Real catalog/authority/SQL execution. The only origin-network adapter is explicit. */
if(!defined('STONEFELLOW_ROOT'))define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-provider-management-'.bin2hex(random_bytes(6)));
require_once __DIR__.'/fixtures/music-listening-v1.php';
require_once dirname(__DIR__).'/includes/music-provider-v1.php';
$config=['site'=>['base_url'=>'https://vp3.example.test','base_path'=>'']];

function music_provider_management_test_equal(mixed $actual,mixed $expected,string $message): void
{
    if(music_provider_v1_json($actual)!==music_provider_v1_json($expected))throw new RuntimeException($message.': '.json_encode(['actual'=>$actual,'expected'=>$expected]));
}
function music_provider_management_test_rejects(callable $work,int $status,string $message,?string $code=null): void
{
    try{$work();}catch(MusicProviderV1Exception $e){
        music_provider_management_test_equal($e->httpStatus,$status,$message.' status');
        if($code!==null)music_provider_management_test_equal($e->publicCode,$code,$message.' code');
        return;
    }
    throw new RuntimeException($message.': request unexpectedly succeeded');
}
function music_provider_management_test_owner(array $input,int $userId=11,array $context=[]): array
{
    $GLOBALS['musicSongTestUserId']=$userId;
    return music_provider_v1_owner_action(db(),['id'=>$userId],['protocol'=>'vp3_music_provider_v1','workspace_id'=>1]+$input,
        array_replace(['now_ms'=>1800000000000],$context));
}
function music_provider_management_test_create(array $changes=[]): array
{
    return array_replace(['action'=>'site.create','request_id'=>music_song_metadata_v1_uuid(),'origin'=>'https://artist.example.test',
        'label'=>'Artist website','environment'=>'production','credential'=>'vmp1_'.bin2hex(random_bytes(32))],$changes);
}
function music_provider_management_test_backend(array $site,array $input,int $now=1800000000000): array
{
    return music_provider_v1_backend_action(db(),$site,['protocol'=>'vp3_music_provider_v1']+$input,['now_ms'=>$now]);
}

function music_provider_management_test_run(): void
{
    $pdo=db();$clock=1800000000000;
    music_listening_test_seed();music_song_metadata_v1_install($pdo);
    $legacy=music_listening_test_snapshot();
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_owner(['action'=>'site.list']),503,'Requests cannot install provider schema');
    music_provider_management_test_equal(music_listening_test_snapshot(),$legacy,'Unupgraded request changed data');
    music_provider_v1_install($pdo);$installed=music_listening_test_snapshot();
    foreach($legacy as $table=>$rows)music_provider_management_test_equal($installed[$table],$rows,'Provider installation changed '.$table);
    music_provider_v1_install($pdo);music_provider_management_test_equal(music_listening_test_snapshot(),$installed,'Repeated installation changed data');
    music_provider_v1_report($pdo);music_provider_management_test_equal(music_listening_test_snapshot(),$installed,'Read-only report wrote data');
    $pdo->beginTransaction();music_provider_management_test_rejects(static fn()=>music_provider_v1_install($pdo),409,'DDL inside caller transaction');
    music_provider_management_test_equal($pdo->inTransaction(),true,'Refused install lost outer transaction');$pdo->rollBack();

    $recording=$pdo->query('SELECT recording_id FROM music_recordings_v1 WHERE catalog_track_id=10')->fetchColumn();
    $second=$pdo->query('SELECT recording_id FROM music_recordings_v1 WHERE catalog_track_id=11')->fetchColumn();
    $foreign=$pdo->query('SELECT recording_id FROM music_recordings_v1 WHERE catalog_track_id=20')->fetchColumn();
    $create=music_provider_management_test_create(['origin'=>'https://ARTIST.example.test:443/']);
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_owner($create,13),403,'Producer acquired artist connection');
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_owner($create,14),403,'Foreign artist acquired workspace');
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_owner(array_replace($create,['origin'=>'http://artist.example.test'])),422,'HTTP origin accepted');
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_owner(array_replace($create,['origin'=>'https://artist.example.test:444'])),422,'Alternate TLS port accepted');
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_owner(array_replace($create,['origin'=>'https://artist.example.test/admin?token=private'])),422,'Private origin path accepted');
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_owner(array_replace($create,['origin'=>'https://127.0.0.1'])),422,'IP origin accepted');
    $created=music_provider_management_test_owner($create);$site=$created['site'];$siteId=$site['site_id'];
    music_provider_management_test_equal($site['origin'],'https://artist.example.test','Origin was not normalized');
    music_provider_management_test_equal($site['status'],'pending','Unverified origin became active');
    music_provider_management_test_equal(music_provider_management_test_owner($create),$created,'Create retry changed receipt');
    music_provider_management_test_equal((int)$pdo->query('SELECT COUNT(*) FROM music_provider_sites_v1')->fetchColumn(),1,'Create retry duplicated site');
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_owner(array_replace($create,['label'=>'Changed'])),409,'Conflicting create retry accepted');
    music_provider_management_test_rejects(static fn()=>music_provider_v1_authenticate($pdo,$create['credential'],$clock),403,'Pending credential authorized backend');
    music_provider_management_test_rejects(static fn()=>music_provider_v1_authenticate($pdo,'mps1_'.str_repeat('a',64),$clock),401,'Visitor credential authorized management');
    $stored=json_encode(music_listening_test_snapshot(),JSON_THROW_ON_ERROR);
    music_provider_management_test_equal(str_contains($stored,$create['credential']),false,'Plaintext management credential persisted');
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_owner(music_provider_management_test_create(['origin'=>'https://another.example.test','credential'=>$create['credential']])),409,'A credential was reused for a different site','credential_conflict');

    $verify=['action'=>'site.verify','site_id'=>$siteId,'request_id'=>music_song_metadata_v1_uuid(),'expected_revision'=>1];
    $beforeProof=music_listening_test_snapshot();
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_owner($verify,11,['verify_request'=>static fn()=>['ok'=>true]]),422,'Incomplete origin proof accepted');
    music_provider_management_test_equal(music_listening_test_snapshot(),$beforeProof,'Failed origin proof wrote rows');
    $proof=$site['verification']['response'];
    $verified=music_provider_management_test_owner($verify,11,['verify_request'=>static fn()=>$proof]);
    music_provider_management_test_equal($verified['site']['status'],'active','Valid exact challenge did not activate site');
    music_provider_management_test_equal($verified['site']['revision'],2,'Verification did not revise grant');
    music_provider_management_test_equal(isset($verified['site']['verification']),false,'Active site exposed proof challenge');
    music_provider_management_test_equal(music_provider_management_test_owner($verify,11,['verify_request'=>static fn()=>throw new RuntimeException('Retry must not call network')]),$verified,'Verify retry changed receipt');
    $authorized=music_provider_v1_authenticate($pdo,$create['credential'],$clock);
    $catalog=music_provider_management_test_backend($authorized,['action'=>'catalog.read']);
    music_provider_management_test_equal(count($catalog['items']),2,'Public workspace catalog missing or foreign/private rows leaked');
    music_provider_management_test_equal($catalog['recording_ids'],[],'Connection silently selected catalog');
    music_provider_management_test_equal(isset($catalog['site']['verification']),false,'Backend catalog leaked origin proof');
    $page=music_provider_management_test_backend($authorized,['action'=>'catalog.read','limit'=>1]);
    music_provider_management_test_equal(count($page['items']),1,'Catalog pagination limit ignored');
    music_provider_management_test_equal($page['next_offset'],1,'Catalog pagination continuation absent');
    music_provider_management_test_equal(count(music_provider_management_test_backend($authorized,['action'=>'catalog.read','search'=>'MP3'])['items']),1,'Catalog search was not scoped');
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_backend($authorized,['action'=>'site.rotate']),403,'Backend credential administered connection');
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_backend($authorized,['action'=>'catalog.read','workspace_id'=>2]),404,'Backend credential crossed workspace');

    $metadata=$pdo->query('SELECT metadata_json FROM music_recordings_v1 WHERE catalog_track_id=10')->fetchColumn();$metadata=json_decode($metadata,true);
    $metadata['contact_email']='PRIVATE-CONTACT@example.test';$metadata['rights_notes']='PRIVATE-BUSINESS-NOTES';
    $pdo->prepare('UPDATE music_recordings_v1 SET metadata_json=? WHERE recording_id=?')->execute([json_encode($metadata),$recording]);
    $catalog=music_provider_management_test_backend($authorized,['action'=>'catalog.read']);$wire=json_encode($catalog,JSON_THROW_ON_ERROR);
    foreach(['PRIVATE-CONTACT','PRIVATE-BUSINESS-NOTES','stored_path','audio_path','token_hash','challenge','creator_user_id','/uploads/'] as $private)music_provider_management_test_equal(str_contains($wire,$private),false,'Catalog leaked '.$private);
    $selection=['action'=>'selection.update','request_id'=>music_song_metadata_v1_uuid(),'expected_selection_revision'=>0,'recording_ids'=>[$recording]];
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_backend($authorized,array_replace($selection,['recording_ids'=>[$foreign]])),404,'Foreign recording selected');
    music_provider_management_test_equal(music_provider_v1_selection($pdo,$siteId),[],'Rejected selection wrote membership');
    $selected=music_provider_management_test_backend($authorized,$selection);
    music_provider_management_test_equal($selected['site']['selection_revision'],1,'Changed selection did not revise');
    music_provider_management_test_equal(music_provider_management_test_backend($authorized,$selection),$selected,'Selection retry changed receipt');
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_backend($authorized,array_replace($selection,['recording_ids'=>[$second]])),409,'Conflicting selection retry accepted');
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_backend($authorized,array_replace($selection,['request_id'=>music_song_metadata_v1_uuid()])),409,'Stale selection revision accepted');
    $noop=music_provider_management_test_backend($authorized,array_replace($selection,['request_id'=>music_song_metadata_v1_uuid(),'expected_selection_revision'=>1,'recording_ids'=>[$recording,$recording]]));
    music_provider_management_test_equal($noop['site']['selection_revision'],1,'Identical selected set invalidated playback');
    $manifest=music_provider_management_test_backend($authorized,['action'=>'import.manifest']);
    music_provider_management_test_equal(array_column($manifest['items'],'recording_id'),[$recording],'Import expanded unselected album sibling');
    music_provider_management_test_equal(isset($manifest['installation_id']),true,'Manifest omitted provider installation identity');

    // Artwork has its own publication authority. A public track is insufficient.
    $directory=STONEFELLOW_ROOT.'/uploads/artist-media/1/photos';if(!is_dir($directory))mkdir($directory,0700,true);
    file_put_contents($directory.'/public.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jCTsAAAAASUVORK5CYII=',true));
    $pdo->exec("INSERT INTO artist_catalog_photos_v181(id,workspace_id,image_path,alt_text,visibility,is_published) VALUES(1,1,'/uploads/artist-media/1/photos/public.png','Cover','admin',0)");
    $pdo->exec('UPDATE artist_catalog_tracks_v181 SET cover_photo_id=1 WHERE id=10');
    music_provider_management_test_equal(music_provider_v1_public_recording($pdo,$authorized,$recording)['artwork'],null,'Private photo inherited public track authority');
    $pdo->exec("UPDATE artist_catalog_photos_v181 SET visibility='public',is_published=1 WHERE id=1");
    $art=music_provider_v1_public_recording($pdo,$authorized,$recording)['artwork'];
    music_provider_management_test_equal($art['url'],'https://vp3.example.test/api/music-provider-artwork-v1.php?site_id='.$siteId.'&recording_id='.$recording,'Public artwork did not use authorized route');
    music_provider_management_test_equal(music_provider_v1_artwork($pdo,$authorized,$recording)['mime'],'image/png','Artwork MIME was not inspected');
    $pdo->exec('UPDATE artist_catalog_tracks_v181 SET cover_photo_id=NULL WHERE id=10');
    $pdo->exec('UPDATE artist_catalog_albums_v181 SET cover_photo_id=1 WHERE id=1');
    $item=music_provider_v1_public_recording($pdo,$authorized,$recording);
    music_provider_management_test_equal([$item['metadata']['album'],$item['album_id'],$item['artwork']],['',null,null],'Private album details/artwork escaped via published track');
    $pdo->exec("UPDATE artist_catalog_albums_v181 SET visibility='public',is_published=1 WHERE id=1");
    music_provider_management_test_equal(music_provider_v1_public_recording($pdo,$authorized,$recording)['artwork']['alt'],'Cover','Public album artwork not inherited');
    $pdo->exec('UPDATE artist_catalog_albums_v181 SET source_album_id=999 WHERE id=1');
    $item=music_provider_v1_public_recording($pdo,$authorized,$recording);
    music_provider_management_test_equal([$item['metadata']['album'],$item['album_id'],$item['artwork']],['',null,null],'Missing source album inherited public catalog authority');
    $pdo->exec('UPDATE artist_catalog_albums_v181 SET source_album_id=NULL WHERE id=1');

    // A currently published track cannot borrow its owner's private backing access.
    $pdo->exec("UPDATE tracks SET visibility='admin' WHERE id=42");
    $manifest=music_provider_management_test_backend($authorized,['action'=>'import.manifest']);
    music_provider_management_test_equal($manifest['items'],[['recording_id'=>$recording,'availability'=>'unavailable']],'Private backing leaked through selected manifest');
    $pdo->exec("UPDATE tracks SET visibility='public' WHERE id=42");
    $pdo->exec("UPDATE music_recordings_v1 SET archived_at='2026-01-01 00:00:00' WHERE catalog_track_id=10");
    music_provider_management_test_rejects(static fn()=>music_provider_v1_public_recording($pdo,$authorized,$recording,true),404,'Archived recording remained public');
    $pdo->exec('UPDATE music_recordings_v1 SET archived_at=NULL WHERE catalog_track_id=10');
    $pdo->exec('UPDATE music_recordings_v1 SET current_asset_id=NULL WHERE catalog_track_id=10');
    music_provider_management_test_equal(music_provider_v1_public_recording($pdo,$authorized,$recording,true)['availability'],'temporarily_unavailable','Missing current asset was promoted to ready');
    $q=$pdo->prepare('SELECT asset_id FROM music_assets_v1 WHERE recording_id=?');$q->execute([$recording]);$asset=$q->fetchColumn();
    $pdo->prepare('UPDATE music_recordings_v1 SET current_asset_id=? WHERE recording_id=?')->execute([$asset,$recording]);

    $audioPath=STONEFELLOW_ROOT.'/uploads/artist-music/1/legacy.wav';
    rename($audioPath,$audioPath.'.missing');
    try{
        $unready=music_provider_v1_public_recording($pdo,$authorized,$recording,true);
        music_provider_management_test_equal([$unready['availability'],$unready['availability_reason']],['temporarily_unavailable','audio_requires_repair'],'Missing bytes were advertised ready');
    }finally{rename($audioPath.'.missing',$audioPath);clearstatcache(true,$audioPath);}
    $legacyDirectory=STONEFELLOW_ROOT.'/audio';if(!is_dir($legacyDirectory))mkdir($legacyDirectory,0700,true);
    copy($audioPath,$legacyDirectory.'/old.wav');
    $pdo->prepare('UPDATE music_assets_v1 SET stored_path=? WHERE asset_id=?')->execute(['/audio/old.wav',$asset]);
    $pdo->exec("UPDATE artist_catalog_tracks_v181 SET audio_path='/audio/old.wav' WHERE id=10");
    $pdo->exec("UPDATE tracks SET audio_path='/audio/old.wav' WHERE id=42");
    music_provider_management_test_equal(music_provider_v1_public_recording($pdo,$authorized,$recording,true)['availability'],'temporarily_unavailable','Unsupported legacy delivery path was advertised ready');
    $pdo->prepare('UPDATE music_assets_v1 SET stored_path=? WHERE asset_id=?')->execute(['/uploads/artist-music/1/legacy.wav',$asset]);
    $pdo->exec("UPDATE artist_catalog_tracks_v181 SET audio_path='/uploads/artist-music/1/legacy.wav' WHERE id=10");
    $pdo->exec("UPDATE tracks SET audio_path='/uploads/artist-music/1/legacy.wav' WHERE id=42");
    music_provider_management_test_equal(music_provider_v1_public_recording($pdo,$authorized,$recording,true)['availability'],'ready','Supported upload failed readiness');

    // Real SQL failure proves selection replacement and receipt are one transaction.
    $beforeRollback=music_listening_test_snapshot();
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $pdo->exec($mysql?"CREATE TRIGGER music_provider_management_failure BEFORE INSERT ON music_provider_management_receipts_v1 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture receipt failure'"
        :"CREATE TRIGGER music_provider_management_failure BEFORE INSERT ON music_provider_management_receipts_v1 BEGIN SELECT RAISE(ABORT,'fixture receipt failure'); END");
    $failed=false;
    try{music_provider_management_test_backend($authorized,array_replace($selection,['request_id'=>music_song_metadata_v1_uuid(),'expected_selection_revision'=>1,'recording_ids'=>[$second]]));}catch(PDOException){$failed=true;}
    $pdo->exec('DROP TRIGGER music_provider_management_failure');
    music_provider_management_test_equal($failed,true,'Injected receipt failure did not execute');
    music_provider_management_test_equal(music_listening_test_snapshot(),$beforeRollback,'Failed receipt left selection or revision changes');

    $pdo->beginTransaction();
    $nested=music_provider_management_test_backend($authorized,array_replace($selection,['request_id'=>music_song_metadata_v1_uuid(),'expected_selection_revision'=>1,'recording_ids'=>[$second]]));
    music_provider_management_test_equal($pdo->inTransaction(),true,'Nested provider write committed outer transaction');
    music_provider_management_test_equal($nested['recording_ids'],[$second],'Nested selection did not execute');
    $pdo->rollBack();
    music_provider_management_test_equal(music_listening_test_snapshot(),$beforeRollback,'Outer rollback failed to restore provider selection/receipt');

    // Reconnection keeps the stable site/selection but invalidates credential and origin proof.
    $rotate=['action'=>'site.rotate','site_id'=>$siteId,'request_id'=>music_song_metadata_v1_uuid(),'expected_revision'=>2,'credential'=>'vmp1_'.bin2hex(random_bytes(32))];
    $rotated=music_provider_management_test_owner($rotate,12);$site=$rotated['site'];
    music_provider_management_test_equal($site['site_id'],$siteId,'Rotation changed site identity');
    music_provider_management_test_equal(music_provider_v1_selection($pdo,$siteId),[$recording],'Rotation expanded or discarded selection');
    music_provider_management_test_rejects(static fn()=>music_provider_v1_authenticate($pdo,$create['credential'],$clock),401,'Rotated old credential remained valid');
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_backend($authorized,['action'=>'catalog.read']),403,'Captured authorization bypassed rotated pending state');
    music_provider_management_test_rejects(static fn()=>music_provider_v1_authenticate($pdo,$rotate['credential'],$clock),403,'Rotation skipped new origin proof');
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_owner(array_replace($rotate,['request_id'=>music_song_metadata_v1_uuid(),'expected_revision'=>3,'credential'=>$create['credential']]),12),409,'Rotation recycled a previously revoked secret','credential_conflict');
    $proof=$site['verification']['response'];
    $site=music_provider_management_test_owner(['action'=>'site.verify','site_id'=>$siteId,'request_id'=>music_song_metadata_v1_uuid(),'expected_revision'=>3],12,['verify_request'=>static fn()=>$proof])['site'];
    $managerConnection=music_provider_v1_authenticate($pdo,$rotate['credential'],$clock);
    $pdo->exec('DELETE FROM artist_team_members WHERE member_user_id=12');
    music_provider_management_test_rejects(static fn()=>music_provider_v1_authenticate($pdo,$rotate['credential'],$clock),403,'Removed creator membership left connection active');
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_backend($managerConnection,['action'=>'import.manifest']),403,'Stale manager site row bypassed current authority');
    $pdo->exec("INSERT INTO artist_team_members VALUES(11,12,'manager')");
    $pdo->exec('UPDATE users SET is_active=0 WHERE id=12');
    music_provider_management_test_rejects(static fn()=>music_provider_v1_authorize_site($pdo,$siteId,$clock),403,'Inactive creator retained authority');
    $pdo->exec('UPDATE users SET is_active=1 WHERE id=12');
    $pdo->exec('UPDATE users SET enabled=0 WHERE id=11');
    music_provider_management_test_rejects(static fn()=>music_provider_v1_authorize_site($pdo,$siteId,$clock),403,'Disabled workspace retained authority');
    $pdo->exec('UPDATE users SET enabled=1 WHERE id=11');
    music_provider_management_test_rejects(static fn()=>music_provider_v1_authorize_site($pdo,$siteId,$clock+MUSIC_PROVIDER_V1_CREDENTIAL_TTL_MS),403,'Expired credential retained authority');

    $change=['action'=>'site.change_origin','site_id'=>$siteId,'request_id'=>music_song_metadata_v1_uuid(),'expected_revision'=>4,
        'origin'=>'https://new-artist.example.test','credential'=>'vmp1_'.bin2hex(random_bytes(32))];
    $changed=music_provider_management_test_owner($change);$site=$changed['site'];
    music_provider_management_test_equal($site['origin'],'https://new-artist.example.test','Origin change failed');
    music_provider_management_test_equal($site['status'],'pending','Origin change carried old verification');
    music_provider_management_test_rejects(static fn()=>music_provider_management_test_owner(['action'=>'site.verify','site_id'=>$siteId,'request_id'=>music_song_metadata_v1_uuid(),'expected_revision'=>5],11,['verify_request'=>static fn()=>$proof]),422,'Old origin challenge verified new origin');
    $proof=$site['verification']['response'];
    $site=music_provider_management_test_owner(['action'=>'site.verify','site_id'=>$siteId,'request_id'=>music_song_metadata_v1_uuid(),'expected_revision'=>5],11,['verify_request'=>static fn()=>$proof])['site'];
    $revoke=['action'=>'site.revoke','site_id'=>$siteId,'request_id'=>music_song_metadata_v1_uuid(),'expected_revision'=>6];
    $revoked=music_provider_management_test_owner($revoke);
    music_provider_management_test_equal($revoked['site']['status'],'revoked','Revoke failed');
    music_provider_management_test_equal(music_provider_management_test_owner($revoke),$revoked,'Revoke retry changed receipt');
    music_provider_management_test_rejects(static fn()=>music_provider_v1_authenticate($pdo,$change['credential'],$clock),403,'Revoked credential remained active');
    music_provider_management_test_equal(music_provider_v1_selection($pdo,$siteId),[$recording],'Revocation deleted import identity mapping');
    music_provider_management_test_equal((int)$pdo->query('SELECT COUNT(*) FROM track_play_sessions')->fetchColumn(),1,'Management created personal/audience listens');
    music_provider_management_test_equal((int)$pdo->query('SELECT COUNT(*) FROM track_favorites')->fetchColumn(),1,'Management changed user favorites');
}

if(!defined('MUSIC_PROVIDER_MANAGEMENT_TEST_LIBRARY_ONLY')){
    try{music_provider_management_test_run();echo "MUSIC_PROVIDER_MANAGEMENT_V1=PASS real SQLite migration/report, current workspace/creator authority, exact verified origins, hashed credentials/rotation, explicit catalog selection, immutable receipts/conflicts, private projections and transaction rollback\n";}
    finally{music_listening_test_clean_files(STONEFELLOW_ROOT);}
}
