<?php
declare(strict_types=1);

/** Isolated authentication/legacy schema adapters; real catalog, provider and analytics services. */
require_once __DIR__.'/music-listening-v1.php';
require_once dirname(__DIR__,2).'/includes/music-analytics-v1.php';

// Explicit authentication adapter for aggregate HTTP/browser fixtures. It has
// no artist-management grant; a separate suite loads real permissions.php.
$GLOBALS['musicSongTestPermissionResolver']=static function(string $permission,?array $user): bool {
    if(!$user)return false;
    if($permission==='chat.access')return true;
    if($permission!=='listening.view')return false;
    $q=db()->prepare('SELECT role FROM users WHERE id=? AND is_active=1');$q->execute([(int)($user['id']??0)]);
    $role=$q->fetchColumn();if($role===false)return false;
    if($role==='admin')return true;
    $q=db()->prepare('SELECT 1 FROM role_permissions WHERE role=? AND permission_key=?');$q->execute([$role,$permission]);
    return (bool)$q->fetchColumn();
};
if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
    db()->sqliteCreateFunction('CONCAT',static fn(...$values):string=>implode('',array_map(static fn($value)=>(string)$value,$values)));
}

function music_analytics_test_native(int $trackId,int $userId,int $clock,int $played=0,bool $resume=true): array
{
    $pdo=db();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$userId]);$user=$q->fetch(PDO::FETCH_ASSOC);
    $context=['now_ms'=>$clock,'origin'=>'https://vp3.example.test','referrer'=>'https://vp3.example.test/player.php?PRIVATE-REFERRER',
        'listener_hash'=>hash('sha256','analytics-fixture-'.$userId),'user_agent'=>'Analytics acceptance'];
    $start=['protocol'=>'native_listening_v1','action'=>'start','listener_user_id'=>$userId,'track_id'=>$trackId,
        'play_id'=>music_song_metadata_v1_uuid(),'position_ms'=>0,'playback_rate'=>1,'source'=>'player',
        'source_url'=>'https://vp3.example.test/player.php?PRIVATE-NATIVE-QUERY#PRIVATE-FRAGMENT'];
    $receipt=music_listening_v1_handle($pdo,$user,$start,$context);
    if($resume){
        $event=['protocol'=>'native_listening_v1','listener_user_id'=>$userId,'track_id'=>$trackId,'play_id'=>$start['play_id'],
            'session_token'=>$receipt['session_token'],'event_id'=>music_song_metadata_v1_uuid(),'seq'=>1,'action'=>'resume','state'=>'playing',
            'position_ms'=>0,'playback_rate'=>1,'elapsed_ms'=>0,'cumulative_played_ms'=>0];
        music_listening_v1_handle($pdo,$user,$event,$context);
        if($played){
            $event=array_replace($event,['event_id'=>music_song_metadata_v1_uuid(),'seq'=>2,'action'=>'ended','state'=>'ended',
                'position_ms'=>$played,'elapsed_ms'=>$played,'cumulative_played_ms'=>$played]);
            $receipt=music_listening_v1_handle($pdo,$user,$event,array_replace($context,['now_ms'=>$clock+$played]));
            // Replay is real production receipt recovery, not another listening row.
            music_listening_v1_handle($pdo,$user,$event,array_replace($context,['now_ms'=>$clock+$played+1]));
        }
    }
    return $receipt;
}

function music_analytics_test_site(int $workspaceId,int $userId,string $origin,string $environment,array $recordings,int $clock): array
{
    $base=['protocol'=>'vp3_music_provider_v1','workspace_id'=>$workspaceId];$context=['now_ms'=>$clock];
    $created=music_provider_v1_owner_action(db(),['id'=>$userId],$base+['action'=>'site.create','request_id'=>music_song_metadata_v1_uuid(),
        'origin'=>$origin,'label'=>$environment==='staging'?'Staging artist site':'Artist website','environment'=>$environment,
        'credential'=>'vmp1_'.bin2hex(random_bytes(32))],$context);
    $site=$created['site'];$proof=$site['verification']['response'];
    $verified=music_provider_v1_owner_action(db(),['id'=>$userId],$base+['action'=>'site.verify','site_id'=>$site['site_id'],
        'expected_revision'=>1,'request_id'=>music_song_metadata_v1_uuid()],$context+['verify_request'=>static fn()=>$proof]);
    $selected=music_provider_v1_owner_action(db(),['id'=>$userId],$base+['action'=>'selection.update','site_id'=>$site['site_id'],
        'request_id'=>music_song_metadata_v1_uuid(),'expected_selection_revision'=>0,'recording_ids'=>$recordings],$context);
    return ['site_id'=>$site['site_id'],'origin'=>$origin,'selection'=>$selected,'verified'=>$verified];
}

function music_analytics_test_provider(array $site,string $recording,int $clock,int $played=0,bool $resume=true): array
{
    $key='mps1_'.bin2hex(random_bytes(32));
    $context=['now_ms'=>$clock,'origin'=>$site['origin']];
    $begin=['protocol'=>'vp3_music_provider_v1','action'=>'playback.begin','site_id'=>$site['site_id'],'recording_id'=>$recording,
        'play_id'=>music_song_metadata_v1_uuid(),'playback_key'=>$key,'source_surface'=>'player',
        'source_url'=>$site['origin'].'/track/public-fixture?PRIVATE-PROVIDER-QUERY#PRIVATE-FRAGMENT','position_ms'=>0,'playback_rate'=>1];
    $receipt=music_provider_v1_playback_action(db(),$begin,$context);
    if($resume){
        $event=['protocol'=>'vp3_music_provider_v1','action'=>'playback.events','site_id'=>$site['site_id'],'session_id'=>$receipt['session_id'],
            'event_id'=>music_song_metadata_v1_uuid(),'seq'=>1,'event_type'=>'resume','state'=>'playing','position_ms'=>0,'elapsed_ms'=>0,
            'cumulative_played_ms'=>0,'playback_rate'=>1];
        music_provider_v1_playback_action(db(),$event,$context+['playback_key'=>$key]);
        if($played){
            $event=array_replace($event,['event_id'=>music_song_metadata_v1_uuid(),'seq'=>2,'event_type'=>'ended','state'=>'ended',
                'position_ms'=>$played,'elapsed_ms'=>$played,'cumulative_played_ms'=>$played]);
            $receipt=music_provider_v1_playback_action(db(),$event,array_replace($context,['now_ms'=>$clock+$played,'playback_key'=>$key]));
            music_provider_v1_playback_action(db(),$event,array_replace($context,['now_ms'=>$clock+$played+1,'playback_key'=>$key]));
        }
    }
    return $receipt;
}

function music_analytics_test_seed(?int $now=null): array
{
    global $config;
    $config=['site'=>['base_url'=>'https://vp3.example.test','base_path'=>''],'uploads'=>[]];
    $now??=(int)floor(microtime(true)*1000);
    $clock=(new DateTimeImmutable('@'.intdiv($now,1000)))->setTimezone(new DateTimeZone('UTC'))->setTime(12,0)->modify('-2 days')->getTimestamp()*1000;
    $pdo=db();music_listening_test_seed();
    $pdo->exec('CREATE TABLE role_permissions (role VARCHAR(32),permission_key VARCHAR(120),PRIMARY KEY(role,permission_key))');
    $pdo->exec("INSERT INTO role_permissions VALUES ('supervisor','listening.view')");
    $pdo->exec("INSERT INTO users VALUES (91,'supervisor',1,0,'Listening administrator')");
    $pdo->exec("INSERT INTO users VALUES (16,'customer',1,0,'PRIVATE LISTENER'),(17,'customer',0,1,'Inactive artist')");
    music_song_metadata_v1_install($pdo);music_listening_v1_install($pdo);music_provider_v1_install($pdo);
    $recordings=$pdo->query('SELECT catalog_track_id,recording_id FROM music_recordings_v1')->fetchAll(PDO::FETCH_KEY_PAIR);
    $native=music_analytics_test_native(42,16,$clock,20000);
    $preview=music_analytics_test_native(42,11,$clock+60000,15000);
    $pending=music_analytics_test_native(43,16,$now-60000,0,false);
    // Fixed known-duration analytical case; media-probe behavior belongs to ML2.
    $pdo->prepare('UPDATE music_listening_sessions_v1 SET duration_ms=25000,duration_source=?,clipped=1 WHERE session_id=?')->execute(['fixture_known_duration',$native['session_id']]);
    $pdo->prepare('UPDATE track_play_sessions SET started_at=? WHERE id=7')->execute([gmdate('Y-m-d H:i:s',intdiv($clock,1000))]);
    $production=music_analytics_test_site(1,11,'https://artist.example.test','production',[$recordings[10],$recordings[11]],$clock-1000);
    $staging=music_analytics_test_site(1,11,'https://staging.example.test','staging',[$recordings[10]],$clock-1000);
    $provider=music_analytics_test_provider($production,$recordings[10],$clock+120000,20000);
    $providerPending=music_analytics_test_provider($production,$recordings[10],$now-30000,0,false);
    $stage=music_analytics_test_provider($staging,$recordings[10],$clock+180000,10000);
    $pdo->prepare('UPDATE music_provider_sessions_v1 SET duration_ms=25000,emitted_bytes=2048,reserved_bytes=4096,media_requests=3 WHERE session_id=?')->execute([$provider['session_id']]);
    $delivery=$pdo->prepare('INSERT INTO music_provider_delivery_v1 (delivery_id,session_id,method,response_status,planned_bytes,reserved_bytes,emitted_bytes,created_ms,finished_ms) VALUES (?,?,?,?,?,?,?,?,?)');
    $delivery->execute([music_song_metadata_v1_uuid(),$provider['session_id'],'GET',200,2048,2048,2048,$clock+120000,$clock+120005]);
    $delivery->execute([music_song_metadata_v1_uuid(),$provider['session_id'],'GET',206,2048,2048,null,$clock+120010,null]);
    $delivery->execute([music_song_metadata_v1_uuid(),$provider['session_id'],'HEAD',200,0,0,0,$clock+120020,$clock+120021]);
    $pdo->prepare('UPDATE music_provider_sessions_v1 SET duration_ms=NULL,muted_seen=1,loop_seen=1 WHERE session_id=?')->execute([$stage['session_id']]);
    $foreign=music_analytics_test_native(84,14,$clock+240000,10000);
    // Secret metadata and listener identities must never enter aggregate output.
    $q=$pdo->prepare('SELECT metadata_json FROM music_recordings_v1 WHERE recording_id=?');$q->execute([$recordings[10]]);$metadata=json_decode($q->fetchColumn(),true);
    $metadata['contact_email']='PRIVATE-CONTACT@example.test';$metadata['rights_notes']='PRIVATE-RIGHTS-NOTES';
    $pdo->prepare('UPDATE music_recordings_v1 SET metadata_json=? WHERE recording_id=?')->execute([json_encode($metadata),$recordings[10]]);
    return ['now_ms'=>$now,'clock_ms'=>$clock,'recordings'=>$recordings,'native'=>$native,'preview'=>$preview,'pending'=>$pending,
        'provider'=>$provider,'provider_pending'=>$providerPending,'stage'=>$stage,'production'=>$production,'staging'=>$staging,'foreign'=>$foreign];
}
