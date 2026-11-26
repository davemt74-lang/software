<?php
declare(strict_types=1);

require_once __DIR__.'/agent-activity-scope-v1.php';

/** The command host has already verified the current app declaration and its separate grant. */
function vp3_coding_canvas_private_source_v1(PDO $pdo,array $authorizedUser,int $agent,int $cid,string $command): array
{
    if(!in_array($command,['music','calendar','products'],true))throw new InvalidArgumentException('Choose a private Canvas command.',422);
    if($cid<1)throw new RuntimeException('Open this Canvas in a private saved Chat.',403);
    $scope=vp3_agent_activity_scope_v1($pdo,$authorizedUser,$agent,$cid);
    if(!has_permission('chat.access',$scope['user']))throw new RuntimeException('Private Agent Chat access changed.',403);
    $owner=(int)$scope['user']['id'];
    try{
        return match($command){
            'music'=>vp3_coding_canvas_private_music_v1($pdo,$scope),
            'calendar'=>vp3_coding_canvas_private_calendar_v1($pdo,$owner,new DateTimeImmutable('now',new DateTimeZone('UTC'))),
            'products'=>vp3_coding_canvas_private_products_v1($pdo,$owner),
        };
    }catch(PDOException $error){
        // App code receives an availability result without a SQL or storage error.
        return ['status'=>'unavailable','rows'=>[]];
    }
}

function vp3_coding_canvas_private_products_v1(PDO $pdo,int $owner): array
{
    $query=$pdo->prepare('SELECT id,title,description,product_type,price_cents,currency,is_active
        FROM agent_commerce_products_v800 WHERE owner_user_id=? ORDER BY id DESC LIMIT 31');
    $query->execute([$owner]);
    $rows=$query->fetchAll(PDO::FETCH_ASSOC);
    return ['status'=>count($rows)>30?'limited':'ready','rows'=>array_map(static fn(array $row):array=>[
        'id'=>(int)$row['id'],
        'title'=>vp3_agent_activity_text_v1($row['title'],190),
        'description'=>vp3_agent_activity_text_v1($row['description'],600),
        'product_type'=>vp3_agent_activity_text_v1($row['product_type'],32),
        'price_cents'=>max(0,(int)$row['price_cents']),
        'currency'=>strtolower(vp3_agent_activity_text_v1($row['currency'],3)),
        'is_active'=>(int)$row['is_active']===1,
    ],array_slice($rows,0,30))];
}

function vp3_coding_canvas_private_calendar_v1(PDO $pdo,int $owner,DateTimeImmutable $now): array
{
    require_once __DIR__.'/user-calendar-v1300.php';
    $from=$now->format('Y-m-d H:i:s');
    $to=$now->modify('+30 days')->format('Y-m-d H:i:s');
    $hasRecurrence=column_exists('user_calendar_events','recurrence');
    $recurrenceColumn=$hasRecurrence?'recurrence':"'none' AS recurrence";
    $distinctAnchor=$hasRecurrence?"AND recurrence='none'":'';
    $query=$pdo->prepare("SELECT id,title,start_at_utc,end_at_utc,timezone,all_day,$recurrenceColumn
        FROM user_calendar_events WHERE owner_user_id=? AND status='active' AND start_at_utc<? AND end_at_utc>?
        $distinctAnchor ORDER BY start_at_utc,id LIMIT 41");
    $query->execute([$owner,$to,$from]);
    $events=$query->fetchAll(PDO::FETCH_ASSOC);
    $limited=count($events)>40;
    $events=array_slice($events,0,40);
    if($hasRecurrence){
        $seriesQuery=$pdo->prepare("SELECT id,title,start_at_utc,end_at_utc,timezone,all_day,recurrence
            FROM user_calendar_events WHERE owner_user_id=? AND status='active' AND recurrence IN ('daily','weekly') AND start_at_utc<?
            ORDER BY start_at_utc DESC,id DESC LIMIT 41");
        $seriesQuery->execute([$owner,$to]);
        $series=$seriesQuery->fetchAll(PDO::FETCH_ASSOC);
        $limited=$limited||count($series)>40;
        foreach(array_slice($series,0,40) as $seriesRow){
            try{
                foreach(user_calendar_occurrences_v1340($seriesRow,$from,$to) as $event)$events[]=$event;
            }catch(RuntimeException $error){
                // A malformed legacy series must not blank the owner's other events.
                $limited=true;
            }
        }
    }
    usort($events,static fn(array $left,array $right):int=>strcmp($left['start_at_utc'],$right['start_at_utc'])
        ?: ((int)$left['id']<=>(int)$right['id']));
    return ['status'=>$limited||count($events)>40?'limited':'ready','rows'=>array_map(static fn(array $event):array=>[
        'id'=>$event['occurrence_id']??'event:'.(int)$event['id'],
        'event_id'=>(int)$event['id'],
        'title'=>vp3_agent_activity_text_v1($event['title'],190),
        'start_at_utc'=>(string)$event['start_at_utc'],
        'end_at_utc'=>(string)$event['end_at_utc'],
        'timezone'=>user_calendar_timezone_v1300((string)$event['timezone']),
        'all_day'=>(int)$event['all_day']===1,
        'recurrence'=>in_array($event['recurrence'],['daily','weekly'],true)?$event['recurrence']:'none',
    ],array_slice($events,0,40))];
}

function vp3_coding_canvas_private_music_v1(PDO $pdo,array $scope): array
{
    require_once __DIR__.'/agent-media-playback-v1.php';
    $owner=(int)$scope['user']['id'];
    $platform=table_exists('tracks');
    $artist=table_exists('artist_catalog_tracks_v181')&&table_exists('artist_workspaces_v181');
    if(!$platform&&!$artist)return ['status'=>'unavailable','rows'=>[]];

    $rows=[];
    $limited=false;
    $hasWorkspaceId=$platform&&column_exists('tracks','workspace_id');
    // Team catalogs do not become the owner's by association with a user ID.
    $ownedSql='t.owner_user_id=?';
    if($hasWorkspaceId){
        $ownerWorkspace=$artist?' OR EXISTS (SELECT 1 FROM artist_workspaces_v181 w
            WHERE w.id=t.workspace_id AND w.artist_user_id=t.owner_user_id)':'';
        $ownedSql.=" AND (COALESCE(t.workspace_id,0)=0$ownerWorkspace)";
    }
    if($platform&&table_exists('track_play_sessions')&&vp3_media_playback_history_allowed_v1($pdo,$scope['user'],$scope['principal'])){
        $history=$pdo->prepare("SELECT t.id,t.title,t.album,t.genre,t.is_published,t.visibility,
            p.last_event_at,p.last_position_seconds,p.duration_seconds
            FROM track_play_sessions p JOIN tracks t ON t.id=p.track_id
            WHERE p.user_id=? AND p.last_event_at>=? AND (($ownedSql) OR (t.is_published=1 AND t.visibility='public'))
            ORDER BY p.last_event_at DESC,p.id DESC LIMIT 31");
        $history->execute([$owner,gmdate('Y-m-d H:i:s',time()-90*86400),$owner]);
        $plays=$history->fetchAll(PDO::FETCH_ASSOC);
        $limited=count($plays)>30;
        foreach($plays as $play){
            $id='track:'.(int)$play['id'];
            if(isset($rows[$id]))continue;
            if(count($rows)===10){
                $limited=true;
                break;
            }
            $rows[$id]=vp3_coding_canvas_private_track_v1($play,$id,(int)$play['id'])+[
                'played_at'=>(string)$play['last_event_at'],
                'position_seconds'=>max(0,(float)$play['last_position_seconds']),
                'duration_seconds'=>max(0,(float)$play['duration_seconds']),
            ];
        }
    }
    $catalog=[];
    if($platform){
        $native=$pdo->prepare("SELECT t.id,t.title,t.album,t.genre,t.is_published,t.visibility,t.updated_at
            FROM tracks t WHERE $ownedSql ORDER BY t.updated_at DESC,t.id DESC LIMIT 21");
        $native->execute([$owner]);
        $catalog=$native->fetchAll(PDO::FETCH_ASSOC);
        $limited=$limited||count($catalog)>20;
    }
    if($artist){
        $excludeShadow='';
        if($platform&&column_exists('artist_catalog_tracks_v181','source_track_id')){
            $workspace=$hasWorkspaceId?'AND (COALESCE(n.workspace_id,0)=0 OR n.workspace_id=w.id)':'';
            $excludeShadow="AND NOT EXISTS (SELECT 1 FROM tracks n
                WHERE n.id=a.source_track_id AND n.owner_user_id=w.artist_user_id $workspace)";
        }
        $artistQuery=$pdo->prepare("SELECT a.id,a.title,a.album,'' AS genre,a.is_published,a.visibility,a.updated_at
            FROM artist_catalog_tracks_v181 a JOIN artist_workspaces_v181 w ON w.id=a.workspace_id
            WHERE w.artist_user_id=? $excludeShadow ORDER BY a.updated_at DESC,a.id DESC LIMIT 21");
        $artistQuery->execute([$owner]);
        $artistRows=$artistQuery->fetchAll(PDO::FETCH_ASSOC);
        $limited=$limited||count($artistRows)>20;
        foreach($artistRows as $row)$catalog[]=$row+['artist_catalog'=>true];
    }
    usort($catalog,static fn(array $left,array $right):int=>strcmp($right['updated_at'],$left['updated_at'])
        ?: ((int)$right['id']<=>(int)$left['id']));
    $limited=$limited||count($catalog)>20;
    foreach(array_slice($catalog,0,20) as $track){
        $isArtist=isset($track['artist_catalog']);
        $id=($isArtist?'artist:':'track:').(int)$track['id'];
        $rows[$id]??=vp3_coding_canvas_private_track_v1($track,$id,$isArtist?null:(int)$track['id']);
    }
    return ['status'=>$limited?'limited':'ready','rows'=>array_values($rows)];
}

function vp3_coding_canvas_private_track_v1(array $track,string $id,?int $trackId): array
{
    return ['id'=>$id,'track_id'=>$trackId,
        'title'=>vp3_agent_activity_text_v1($track['title'],190),
        'album'=>vp3_agent_activity_text_v1($track['album'],190),
        'genre'=>vp3_agent_activity_text_v1($track['genre'],120),
        'is_published'=>(int)$track['is_published']===1,
        'visibility'=>vp3_agent_activity_text_v1($track['visibility'],30)];
}
