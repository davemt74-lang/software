<?php
declare(strict_types=1);

require_once __DIR__.'/music-chat-library-v1.php';
require_once dirname(__DIR__,2).'/includes/music-listening-v1.php';
require_once dirname(__DIR__,2).'/includes/stem-mp3-metadata-v1.php';
require_once dirname(__DIR__,2).'/includes/music-history-v1.php';

/** Add only disposable fixture prerequisites; production history reads never install these tables. */
function music_history_fixture_schema_v1(PDO $pdo): void
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $id=$mysql?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $session=$mysql?'BIGINT UNSIGNED':'INTEGER';
    $tables=[
        'track_play_sessions'=>"id {$id},session_token CHAR(64) NOT NULL UNIQUE,track_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NULL,
            listener_hash CHAR(64) NOT NULL,device_type VARCHAR(30) DEFAULT 'unknown',referrer_host VARCHAR(190) DEFAULT '',
            source_context VARCHAR(30) DEFAULT 'player',duration_seconds DECIMAL(12,2) DEFAULT 0,last_position_seconds DECIMAL(12,2) DEFAULT 0,
            max_position_seconds DECIMAL(12,2) DEFAULT 0,started_at DATETIME DEFAULT CURRENT_TIMESTAMP,last_event_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            ended_at DATETIME NULL,listened_seconds DECIMAL(12,2) DEFAULT 0,completion_percent DECIMAL(7,2) DEFAULT 0,
            qualified_play INTEGER DEFAULT 0,completed INTEGER DEFAULT 0,
            FOREIGN KEY(track_id) REFERENCES tracks(id),FOREIGN KEY(user_id) REFERENCES users(id)",
        'track_play_events'=>"id {$id},session_id {$session} NOT NULL,event_type VARCHAR(30),position_seconds DECIMAL(12,2) DEFAULT 0,
            listened_delta_seconds DECIMAL(12,2) DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(session_id) REFERENCES track_play_sessions(id)",
        'user_data_policies'=>"id {$id},owner_user_id INT UNSIGNED NOT NULL,resource_type VARCHAR(60),resource_id VARCHAR(100),
            audience_scope VARCHAR(30),owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER",
        'user_agent_data_rules'=>'agent_id BIGINT UNSIGNED NOT NULL,resource_type VARCHAR(60),resource_id VARCHAR(100),access_mode VARCHAR(20),PRIMARY KEY(agent_id,resource_type,resource_id)',
        'artist_catalog_tracks_v181'=>'id INT UNSIGNED NOT NULL PRIMARY KEY,workspace_id INT UNSIGNED NOT NULL,source_track_id INT UNSIGNED NOT NULL,
            audio_path VARCHAR(255),title VARCHAR(190),is_published INTEGER DEFAULT 1,visibility VARCHAR(30) DEFAULT \'public\'',
        'music_recordings_v1'=>'recording_id CHAR(36) NOT NULL PRIMARY KEY,workspace_id INT UNSIGNED NOT NULL,catalog_track_id INT UNSIGNED NOT NULL,
            source_track_id INT UNSIGNED NOT NULL,current_asset_id CHAR(36)',
        'music_assets_v1'=>'asset_id CHAR(36) NOT NULL PRIMARY KEY,recording_id CHAR(36) NOT NULL,workspace_id INT UNSIGNED NOT NULL,stored_path VARCHAR(255)',
    ];
    foreach($tables as $table=>$columns)$pdo->exec('CREATE TABLE IF NOT EXISTS '.$table.' ('.$columns.')'.$tail);
    music_listening_v1_install($pdo);
}

function music_history_fixture_native_v1(PDO $pdo,int $track,int $played,int $owner=11,float $seconds=0,?array $measured=null): int
{
    $q=$pdo->prepare('INSERT INTO track_play_sessions(session_token,track_id,user_id,listener_hash,source_context,started_at,last_event_at,
        listened_seconds,last_position_seconds,max_position_seconds,duration_seconds) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $position=($measured['position_ms']??0)/1000;
    $q->execute([bin2hex(random_bytes(32)),$track,$owner,hash('sha256','history-fixture-'.$owner),'player',gmdate('Y-m-d H:i:s',$played),
        gmdate('Y-m-d H:i:s',$played+30),$seconds,$position,$position,($measured['duration_ms']??0)/1000]);
    $id=(int)$pdo->lastInsertId();
    if($measured!==null){
        $row=$measured+['session_id'=>$id,'listener_key'=>hash('sha256','fixture-listener-'.$owner),'play_id'=>sprintf('00000000-0000-4000-8000-%012d',$id),
            'request_hash'=>hash('sha256','fixture-request-'.$id),'requested_track_id'=>$track,'workspace_id'=>14,'catalog_track_id'=>171,
            'recording_id'=>'00000000-0000-4000-8000-000000000171','asset_id'=>'00000000-0000-4000-8000-000000000271',
            'identity_status'=>'mapped','asset_binding'=>'catalog_snapshot_unverified_delivery','duration_ms'=>60000,'duration_source'=>'server_mp3_probe',
            'source_url'=>null,'source_reason'=>'fixture','source_provenance'=>'same_origin_route','source_context'=>'player','listen_class'=>'audience',
            'metric_version'=>MUSIC_LISTENING_V1_METRIC,'state'=>'paused','created_ms'=>$played*1000,'expires_ms'=>($played+86400)*1000,
            'last_received_ms'=>($played+30)*1000,'accepted_played_ms'=>(int)round($seconds*1000),'position_ms'=>20000,'natural_ended'=>0,'start_receipt_json'=>'{}'];
        $pdo->prepare('INSERT INTO music_listening_sessions_v1('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
    }
    return $id;
}

/** Can be called after the existing MC1 HTTP fixture, without redefining its adapters or queue tracks. */
function music_history_fixture_seed_v1(PDO $pdo,?int $clock=null,int $extra=0): array
{
    $clock??=time()-10;$owner=11;
    // Legitimate retained plays follow an already accepted policy. Tests which
    // advance this epoch exercise reacceptance without reviving older receipts.
    $pdo->prepare('UPDATE agent_connection_youtube_consent_v1 SET accepted_at=? WHERE owner_user_id=?')->execute([$clock-8*86400,$owner]);
    $fixtures=json_decode((string)file_get_contents(__DIR__.'/stem-mp3-metadata-v1.json'),true,512,JSON_THROW_ON_ERROR);
    $sample=base64_decode($fixtures['fixtures'][2]['base64'],true);
    if($sample===false)throw new RuntimeException('History MP3 fixture unavailable.');
    // Repeat only complete MPEG frames, without an old Xing length or interleaved ID3 tags.
    if(substr($sample,0,3)==='ID3'){
        $tag=array_map('ord',str_split(substr($sample,6,4)));$sample=substr($sample,10+($tag[0]<<21)+($tag[1]<<14)+($tag[2]<<7)+$tag[3]);
    }
    $offset=0;while(($frame=stem_mp3_frame_v1($sample,$offset))!==null&&$offset+$frame['length']<=strlen($sample))$offset+=$frame['length'];
    $sample=substr($sample,0,$offset);
    if($sample==='')throw new RuntimeException('History MP3 frames unavailable.');
    file_put_contents(STONEFELLOW_ROOT.'/history.mp3',str_repeat($sample,50));
    $q=$pdo->prepare('INSERT INTO tracks(id,owner_user_id,workspace_id,title,album,genre,mood,audio_path,duration,is_published,visibility) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([71,11,14,'History Moonlight','Night Drives','Ambient','calm','history.mp3','1:00',1,'public']);
    $pdo->exec("INSERT INTO artist_catalog_tracks_v181(id,workspace_id,source_track_id,audio_path,title) VALUES(171,14,71,'history.mp3','History Moonlight')");
    $pdo->exec("INSERT INTO music_recordings_v1 VALUES('00000000-0000-4000-8000-000000000171',14,171,71,'00000000-0000-4000-8000-000000000271')");
    $pdo->exec("INSERT INTO music_assets_v1 VALUES('00000000-0000-4000-8000-000000000271','00000000-0000-4000-8000-000000000171',14,'history.mp3')");
    $identity=music_listening_v1_identity($pdo,music_media_track(71,current_user()),current_user());
    $native=music_history_fixture_native_v1($pdo,71,$clock,11,18,['position_ms'=>22000]+$identity);
    $repeat=music_history_fixture_native_v1($pdo,71,$clock-60,11,0,['position_ms'=>0,'accepted_played_ms'=>0]+$identity);
    $legacy=music_history_fixture_native_v1($pdo,41,$clock-120,11,12.5);
    $unavailable=music_history_fixture_native_v1($pdo,43,$clock-180,11,0);
    $foreign=music_history_fixture_native_v1($pdo,71,$clock+1,22,40,['asset_id'=>null]);
    for($i=0;$i<$extra;++$i)music_history_fixture_native_v1($pdo,41,$clock-240-$i,11,$i%2?1:0);
    $video=music_chat_fixture_video_v1(6);$key='history-fixture-youtube-'.bin2hex(random_bytes(5));
    $result=vp3_music_chat_result_store_v1($pdo,current_user(),7,42,$key,hash('sha256',$key),[$video],
        ['intent'=>'playlist','source'=>'youtube','requested_count'=>1,'create_playlist'=>true,'title'=>'History private list']);
    $message=music_chat_fixture_message_v1($pdo);
    $bound=vp3_music_chat_result_bind_v1($pdo,current_user(),7,42,$result['result_id'],$message);
    $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([json_encode(['music_chat_v1'=>vp3_music_chat_result_reference_v1($bound),'skip_brain_archive'=>true],JSON_THROW_ON_ERROR),$message]);
    $q=$pdo->prepare('SELECT youtube_playlist_id FROM music_chat_results_v1 WHERE result_id=?');$q->execute([$result['result_id']]);$child=(string)$q->fetchColumn();
    $ordinary='11111111-1111-4111-8111-111111111111';$playlist='22222222-2222-4222-8222-222222222222';
    if(!vp3_youtube_history_record_v1($pdo,$owner,$message,$video['source_id'],$ordinary,str_repeat('c',32)))throw new RuntimeException('Ordinary YouTube fixture receipt rejected.');
    if(!vp3_youtube_playlist_play_v1($pdo,$owner,$child,$video['source_id'],$playlist,str_repeat('c',32),'record'))throw new RuntimeException('Child YouTube fixture receipt rejected.');
    $pdo->prepare('UPDATE vp3_youtube_playback_v1 SET played_at=? WHERE owner_user_id=? AND play_id=?')->execute([$clock-20,$owner,$ordinary]);
    $pdo->prepare('UPDATE vp3_youtube_playlist_plays_v1 SET played_at=?,position_ms=15000,position_seq=1,position_at=?,watch_seconds=10,last_watched_at=? WHERE owner_user_id=? AND play_id=?')
        ->execute([$clock-40,$clock-20,$clock-20,$owner,$playlist]);
    $pdo->prepare('INSERT INTO vp3_youtube_playhead_v1(owner_user_id,play_id,position_ms,position_seq,position_at) VALUES(?,?,12000,1,?)')->execute([$owner,$ordinary,$clock]);
    $pdo->prepare('INSERT INTO vp3_youtube_watch_v1(owner_user_id,play_id,watch_seconds,last_watched_at) VALUES(?,?,8,?)')->execute([$owner,$ordinary,$clock]);
    $pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?)')->execute(['youtube_watch_research_owner_11','1']);
    $refs=[];foreach(['native'=>$native,'repeat'=>$repeat,'legacy'=>$legacy,'unavailable'=>$unavailable] as $name=>$id)$refs[$name]=vp3_music_history_reference_v1($owner,'n',(string)$id);
    $refs['ordinary']=vp3_music_history_reference_v1($owner,'y',$ordinary);$refs['playlist']=vp3_music_history_reference_v1($owner,'p',$playlist);
    return compact('clock','native','repeat','legacy','unavailable','foreign','ordinary','playlist','child','message','refs');
}
