<?php
declare(strict_types=1);

require_once __DIR__.'/agent-commerce-recovery-pa2.php';
require_once __DIR__.'/agent-public-research-plan-v1.php';
require_once __DIR__.'/music-media.php';

function vp3_native_music_research_grant_v1(PDO $pdo,int $owner): string
{
    if($owner<1)return '';
    $q=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $q->execute(['native_music_research_owner_'.$owner]);
    $value=$q->fetchColumn();
    return is_string($value)&&preg_match('/\A[a-f0-9]{64}\z/D',$value)?$value:'';
}

function vp3_native_music_research_save_v1(PDO $pdo,int $owner,bool $enabled): void
{
    if($owner<1)throw new InvalidArgumentException('Invalid owner.');
    agent_commerce_atomic_pa2($pdo,static function()use($pdo,$owner,$enabled):void{
        $q=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.agent_commerce_lock_pa2($pdo));
        $q->execute([$owner]);
        if(!$q->fetchColumn())throw new DomainException('Account access changed.');
        $key='native_music_research_owner_'.$owner;
        if(!$enabled){
            $pdo->prepare('DELETE FROM settings WHERE setting_key=?')->execute([$key]);
        }elseif(vp3_native_music_research_grant_v1($pdo,$owner)===''){
            $upsert=agent_commerce_sqlite_pa2($pdo)
                ? 'ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value'
                : 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)';
            $pdo->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (?,?) '.$upsert)
                ->execute([$key,bin2hex(random_bytes(32))]);
        }
    });
}

/** Collapse one listening sitting and overlapping devices into a single episode. */
function vp3_initiative_native_music_episodes_v1(array $plays): array
{
    $episodes=[];
    foreach($plays as $play){
        $index=count($episodes)-1;
        $start=$play['started_at'];$end=$play['ended_at'];
        if($index<0||$start-$episodes[$index]['end']>=900){
            $episodes[]=['id'=>$play['id'],'end'=>$end,'seconds'=>$play['listened_seconds']];
        }else{
            $episodes[$index]['end']=max($episodes[$index]['end'],$end);
            $episodes[$index]['seconds']=max($episodes[$index]['seconds'],$play['listened_seconds']);
        }
    }
    return $episodes;
}

/** Only the existing public name tied to the exact catalog identity can leave the owner scope. */
function vp3_initiative_native_music_evidence_v1(PDO $pdo,array $scope,DateTimeImmutable $now,?array $selectedRefs,array &$coverage): array
{
    $family='native_artist_research';
    $prefix='native-artist-interest:';
    if($selectedRefs!==null&&!array_filter($selectedRefs,static fn($ref):bool=>is_string($ref)&&str_starts_with($ref,$prefix)))return [];
    $owner=(int)($scope['user']['id']??0);$agent=$scope['agent_id']??-1;$principal=$scope['principal']??[];
    $coverage[$family]='forbidden';
    if($owner<1||$agent<0||!has_permission('account.access',$scope['user'])
        ||!has_permission('chat.access',$scope['user'])
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))return [];
    try{
        $grant=vp3_native_music_research_grant_v1($pdo,$owner);
        if($grant===''||(($principal['kind']??'')==='user_agent'&&(!function_exists('user_data_policy_can_use_v236')
            ||!user_data_policy_can_use_v236($pdo,$principal,$owner,'knowledge','playback-history',false))))return [];
        $coverage[$family]='unavailable';
        if(!function_exists('db')||db()!==$pdo)return [];
        $at=$now->getTimestamp();
        $since=gmdate('Y-m-d H:i:s',$at-7*86400);$until=gmdate('Y-m-d H:i:s',$at);
        $q=$pdo->prepare("SELECT s.id,s.track_id,s.started_at,s.last_event_at,s.listened_seconds,c.workspace_id,
                artist.display_name,p.username
            FROM track_play_sessions s JOIN tracks t ON t.id=s.track_id
            JOIN artist_catalog_tracks_v181 c ON c.source_track_id=t.id AND c.workspace_id=t.workspace_id
            JOIN artist_workspaces_v181 w ON w.id=c.workspace_id AND w.artist_user_id=t.owner_user_id
            JOIN users artist ON artist.id=w.artist_user_id AND artist.is_active=1
            JOIN user_profiles p ON p.user_id=artist.id AND p.is_public=1 AND p.username<>''
            WHERE s.user_id=? AND s.source_context IN ('player','agent_player') AND s.qualified_play=1
                AND s.listened_seconds>=30 AND s.started_at>? AND s.last_event_at<=?
            ORDER BY s.started_at DESC,s.id DESC LIMIT 180");
        $q->execute([$owner,$since,$until]);
        $groups=[];$tracks=[];
        foreach(array_reverse($q->fetchAll(PDO::FETCH_ASSOC)) as $row){
            $name=trim((string)$row['display_name']);
            if(!vp3_topic_research_subject_valid_v1($name)||!preg_match('/\p{L}/u',$name))continue;
            $ref=$prefix.hash('sha256',mb_strtolower($name));
            if($selectedRefs!==null&&!in_array($ref,$selectedRefs,true))continue;
            $trackId=(int)$row['track_id'];
            $tracks[$trackId]??=music_media_track($trackId,$scope['user'])??false;
            if(!$tracks[$trackId]||!can_view_track($tracks[$trackId],$scope['user']))continue;
            $start=strtotime($row['started_at'].' UTC');$end=strtotime($row['last_event_at'].' UTC');
            if($start===false||$end===false||$end-$start<30)continue;
            $workspace=(int)$row['workspace_id'];
            $groups[$ref][$workspace]['name']=$name;
            $groups[$ref][$workspace]['username']=(string)$row['username'];
            $groups[$ref][$workspace]['plays'][]=['id'=>(int)$row['id'],'started_at'=>$start,'ended_at'=>$end,
                'listened_seconds'=>min((int)$row['listened_seconds'],$end-$start)];
        }
        if(!hash_equals($grant,vp3_native_music_research_grant_v1($pdo,$owner))){
            $coverage[$family]='forbidden';
            return [];
        }
        $coverage[$family]='ready';
        $evidence=[];
        foreach($groups as $ref=>$artists){
            if(count($artists)!==1)continue; // Identical names in two workspaces are ambiguous.
            $workspace=array_key_first($artists);$artist=$artists[$workspace];
            $episodes=vp3_initiative_native_music_episodes_v1($artist['plays']);
            $total=array_sum(array_column($episodes,'seconds'));
            $last=max(array_column($episodes,'end'));
            if(count($episodes)<2||$total<120||$last<=$at-2*86400)continue;
            // Bind the earliest qualifying episodes. Later heartbeats and plays do not create a new job.
            $ids=[];$seconds=0;
            foreach($episodes as $episode){
                $ids[]=$episode['id'];$seconds+=$episode['seconds'];
                if(count($ids)>=2&&$seconds>=120)break;
            }
            $evidence[$ref]=['ref'=>$ref,'kind'=>'artist_interest','title'=>$artist['name'],
                'detail'=>count($episodes).' separate listening sessions in VP3 Music totaling '.$total
                    .' seconds over the last seven days. Consider whether public artist research would add something useful.',
                'url'=>url('/'.rawurlencode($artist['username'])),
                'revision'=>hash('sha256',implode('|',[$owner,$agent,$workspace,$artist['name'],$artist['username'],$grant,...$ids])),
                'interest'=>['basis'=>'native_music_listening','last_signal_at'=>$last]];
        }
        uasort($evidence,static fn(array $a,array $b):int=>$b['interest']['last_signal_at']<=>$a['interest']['last_signal_at']);
        return $selectedRefs===null?array_slice($evidence,0,3,true):$evidence;
    }catch(PDOException){
        $coverage[$family]='unavailable';
        return [];
    }
}
