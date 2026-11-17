<?php
declare(strict_types=1);

require_once __DIR__.'/connected-stoneward-v1.php';
require_once __DIR__.'/connected-sites-reports-v1.php';

/** A Stonefellow report row exists only when the owner enables this domain for Media suggestions. */
function vp3_stoneward_media_rows_v1(PDO $pdo,int $owner,?string $id=null,bool $lock=false): array
{
    if (!vp3_connected_sites_report_ready_v1($pdo) || !vp3_stoneward_schema_ready_v1($pdo)) return [];
    $query = $pdo->prepare("SELECT r.connection_id,r.checked_at,r.verified_at,r.state,r.findings_json,r.grant_revision,
            c.instance_id,c.remote_connection_id,c.origin,c.token_ciphertext
        FROM vp3_connected_site_reports_v1 r JOIN vp3_stoneward_connections_v1 c
            ON c.owner_user_id=r.owner_user_id AND c.id=r.connection_id
        WHERE r.owner_user_id=? AND r.provider='stoneward' AND r.location_id=0"
        .($id===null?'':' AND r.connection_id=?').($lock?agent_commerce_lock_pa2($pdo):''));
    $query->execute($id===null?[$owner]:[$owner,$id]);
    $rows = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $saved = json_decode($row['findings_json'],true,8);
        $nonce = $saved['consent_nonce'] ?? null;
        if (!is_string($nonce) || !preg_match('/\A[a-f0-9]{32}\z/D',$nonce)
            || !hash_equals($row['grant_revision'],vp3_stoneward_media_grant_v1($row,$nonce))) continue;
        $row['saved'] = $saved;
        $rows[$row['connection_id']] = $row;
    }
    return $rows;
}

function vp3_stoneward_media_grant_v1(array $connection,string $nonce): string
{
    return hash('sha256',json_encode([
        $connection['instance_id'],$connection['remote_connection_id'],$connection['origin'],
        $connection['token_ciphertext'],$nonce,
    ],JSON_THROW_ON_ERROR));
}

function vp3_stoneward_media_lock_owner_v1(PDO $pdo,int $owner): void
{
    $query = $pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.agent_commerce_lock_pa2($pdo));
    $query->execute([$owner]);
    if (!$query->fetchColumn()) throw new RuntimeException('Stonefellow account access changed.',403);
}

function vp3_stoneward_media_forget_v1(PDO $pdo,int $owner,string $id): void
{
    if (!vp3_connected_sites_report_ready_v1($pdo)) return;
    $query = $pdo->prepare("DELETE FROM vp3_connected_site_reports_v1
        WHERE owner_user_id=? AND provider='stoneward' AND connection_id=? AND location_id=0");
    $query->execute([$owner,$id]);
    if ($query->rowCount()) vp3_coordination_source_changed_v1($pdo,$owner,sourceAlreadyCommitted:true);
}

function vp3_stoneward_media_consent_v1(PDO $pdo,array $user,string $id,bool $enabled): void
{
    $owner = vp3_connected_sites_report_owner_v1($user);
    if (!vp3_connected_sites_report_ready_v1($pdo)) throw new RuntimeException('Run VP3 Database Upgrade for Media suggestions.',503);
    agent_commerce_atomic_pa2($pdo,static function()use($pdo,$owner,$id,$enabled):void {
        vp3_stoneward_media_lock_owner_v1($pdo,$owner);
        $connection = vp3_stoneward_connection_v1($pdo,$owner,$id);
        if (!$enabled) {
            vp3_stoneward_media_forget_v1($pdo,$owner,$id);
            return;
        }
        if (vp3_stoneward_media_rows_v1($pdo,$owner,$id,true)) return;
        vp3_stoneward_media_forget_v1($pdo,$owner,$id);
        $nonce = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO vp3_connected_site_reports_v1
            (owner_user_id,provider,connection_id,location_id,grant_revision,checked_at,verified_at,state,tickets_checked,findings_json)
            VALUES (?,'stoneward',?,0,?,0,NULL,'unchecked',NULL,?)")
            ->execute([$owner,$id,vp3_stoneward_media_grant_v1($connection,$nonce),json_encode(['consent_nonce'=>$nonce],JSON_THROW_ON_ERROR)]);
        vp3_coordination_source_changed_v1($pdo,$owner);
    });
}

function vp3_stoneward_media_due_v1(PDO $pdo,array $user,DateTimeImmutable $now): array
{
    $owner = vp3_connected_sites_report_owner_v1($user);
    $targets = [];
    foreach (vp3_stoneward_media_rows_v1($pdo,$owner) as $row) {
        $checked = (int)$row['checked_at'];
        $interval = in_array($row['state'],['authorization_required','on_demand'],true)
            ?3600:VP3_CONNECTED_SITES_CHECK_INTERVAL_V1;
        if ($checked > $now->getTimestamp()-$interval) continue;
        $targets[] = ['provider'=>'stoneward','connection_id'=>$row['connection_id'],'location_id'=>0,
            'checked_at'=>$checked,'grant_revision'=>$row['grant_revision']];
    }
    return $targets;
}

/** Ignore quick replays from the same listening session, including across midnight. */
function vp3_stoneward_media_repeat_v1(array $plays): ?array
{
    $tracks = [];
    foreach ($plays as $play) {
        if ($play['listenedSeconds'] < 30) continue;
        $id = $play['trackId'];
        if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,119}\z/D',$id)) continue;
        $tracks[$id] ??= ['id'=>$id,'times'=>[]];
        $tracks[$id]['times'][] = strtotime($play['playedAt']);
    }
    foreach ($tracks as &$track) {
        sort($track['times'],SORT_NUMERIC);
        $sessions = 0;
        $previous = 0;
        foreach ($track['times'] as $playedAt) {
            if ($playedAt-$previous<6*3600) continue;
            ++$sessions;
            $previous = $playedAt;
        }
        $track = ['id'=>$track['id'],'sessions'=>$sessions,'latest'=>max($track['times'])];
    }
    unset($track);
    $eligible = array_filter($tracks,static fn(array $track):bool=>$track['sessions']>=2);
    uasort($eligible,static fn(array $left,array $right):int=>
        [$right['sessions'],$right['latest'],$right['id']] <=> [$left['sessions'],$left['latest'],$left['id']]);
    return $eligible ? reset($eligible) : null;
}

/** Keep only the most recent actual play for each track from this verified site response. */
function vp3_stoneward_media_cache_recent_v1(array $plays): array
{
    usort($plays,static fn(array $a,array $b):int=>strcmp($b['playedAt'],$a['playedAt']));
    $recent=[];$seen=[];
    foreach($plays as $play){
        if($play['listenedSeconds']<=0||isset($seen[$play['trackId']]))continue;
        $seen[$play['trackId']]=true;
        $recent[]=['title'=>$play['title'],'artist'=>$play['artist'],'played_at'=>$play['playedAt']];
        if(count($recent)===8)break;
    }
    return $recent;
}

/** The existing leased connection worker is the only proactive remote reader. */
function vp3_stoneward_media_scan_v1(PDO $pdo,array $user,DateTimeImmutable $now,array $target,
    ?callable $transport=null,?callable $commitAuthority=null): array
{
    $owner = vp3_connected_sites_report_owner_v1($user);
    if ($pdo->inTransaction()) throw new LogicException('Connected site reads must run outside a database transaction.');
    $id = $target['connection_id'];
    $before = vp3_stoneward_media_rows_v1($pdo,$owner,$id)[$id] ?? null;
    if (!vp3_stoneward_media_target_matches_v1($before,$target) || (int)$before['checked_at'] >= $now->getTimestamp()) {
        return ['state'=>'superseded'];
    }
    $saved = ['consent_nonce'=>$before['saved']['consent_nonce']];
    $state = 'ready';
    $verified = null;
    try {
        $history = vp3_stoneward_history_v1($pdo,$owner,$id,7,60,$transport);
        $verified = strtotime($history['asOf']);
        $saved['recent_listening'] = vp3_stoneward_media_cache_recent_v1($history['data']['plays']);
        $candidate = vp3_stoneward_media_repeat_v1($history['data']['plays']);
        if ($candidate !== null) {
            $asset = vp3_stoneward_playable_v1($pdo,$owner,$id,['trackId'=>$candidate['id']],$transport);
            $saved['track'] = ['id'=>$candidate['id'],'title'=>$asset['title'],'artist'=>$asset['artist'],
                'sessions'=>$candidate['sessions']];
        }
    } catch (RuntimeException $error) {
        if ($error instanceof PDOException || $error->getCode()===409) throw $error;
        if ($verified !== null && in_array($error->getCode(),[404,422,501],true)) {
            // The history is valid even when its most promising track cannot be streamed.
            $state = $error->getCode()===501?'on_demand':'ready';
        } else {
            $verified = null;
            unset($saved['recent_listening']);
            $state = $error->getCode()===403?'authorization_required':'unavailable';
        }
    }
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$owner,$id,$target,$now,$saved,$state,$verified,$commitAuthority):array {
        vp3_stoneward_media_lock_owner_v1($pdo,$owner);
        $commitAuthority?->__invoke();
        $current = vp3_stoneward_media_rows_v1($pdo,$owner,$id,true)[$id] ?? null;
        if (!vp3_stoneward_media_target_matches_v1($current,$target)) return ['state'=>'superseded'];
        $query = $pdo->prepare("UPDATE vp3_connected_site_reports_v1 SET checked_at=?,verified_at=?,state=?,findings_json=?
            WHERE owner_user_id=? AND provider='stoneward' AND connection_id=? AND location_id=0");
        $query->execute([$now->getTimestamp(),$verified,$state,json_encode($saved,JSON_THROW_ON_ERROR),$owner,$id]);
        if ($state!==$current['state'] || ($saved['track'] ?? null)!==($current['saved']['track'] ?? null)) {
            vp3_coordination_source_changed_v1($pdo,$owner);
        }
        return ['state'=>$state];
    });
}

function vp3_stoneward_media_target_matches_v1(?array $row,array $target): bool
{
    return $row !== null && $row['grant_revision']===$target['grant_revision'] && (int)$row['checked_at']===$target['checked_at'];
}

/** Report-only projection from the current, consent-bound cache. */
function vp3_stoneward_media_recent_listening_v1(PDO $pdo,array $scope,int $now): array
{
    $user=$scope['user'];$owner=(int)$user['id'];
    if(!has_permission('account.access',$user)||!has_permission('chat.access',$user)
        ||!knowledge_retrieval_v162_owner_session($user,$scope['principal'])
        ||vp3_participant_scope_v1()!==null)return [];
    $items=[];
    foreach(vp3_stoneward_media_rows_v1($pdo,$owner) as $id=>$row){
        $verified=(int)$row['verified_at'];
        if(!in_array($row['state'],['ready','on_demand'],true)
            ||$verified<$now-VP3_CONNECTED_SITES_REPORT_TTL_V1||$verified>$now+300)continue;
        $plays=$row['saved']['recent_listening']??null;
        if(!is_array($plays)||!array_is_list($plays)||count($plays)>8)continue;
        $host=parse_url($row['origin'],PHP_URL_HOST);
        foreach($plays as $play){
            $title=vp3_stoneward_text_v1($play['title']??null,200);
            $artist=vp3_stoneward_text_v1($play['artist']??null,160);
            $at=vp3_stoneward_utc_v1($play['played_at']??null)?->getTimestamp();
            if($title===''||$at===null||$at<$now-7*86400||$at>$verified||$at>$now)continue;
            $key=hash('sha256',json_encode([$id,$title,$artist,$at],JSON_THROW_ON_ERROR));
            $items[]=['id'=>'stoneward-listening:'.$key,'title'=>$title,'played_at'=>$at,'detail'=>$artist!==''?$artist.' · '.$host:$host,
                'actions'=>[['kind'=>'stoneward_history','label'=>'Open listening history','href'=>$row['origin'].'/?view=history']]];
        }
    }
    return $items;
}

/** A local exact read powers the specialist, Top of Mind and playback acceptance. */
function vp3_stoneward_media_sources_v1(PDO $pdo,array $scope,?array $refs,DateTimeImmutable $now): array
{
    $items = [];
    $coverage = 'unconfigured';
    $user = $scope['user'];
    $owner = (int)($user['id'] ?? 0);
    if ($owner<1 || !has_permission('account.access',$user) || !has_permission('chat.access',$user)
        || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1()!==null)
        || !knowledge_retrieval_v162_owner_session($user,$scope['principal'] ?? [])) {
        return ['items'=>$items,'coverage'=>$coverage];
    }
    $selected = $refs===null?null:array_values(array_filter(array_map(static function(string $ref):?string {
        return preg_match('/\Amedia:stoneward:([a-f0-9]{32}):(?:resume-)?[a-f0-9]{64}\z/D',$ref,$match)?$match[1]:null;
    },$refs)));
    if ($selected===[]) return ['items'=>$items,'coverage'=>$coverage];
    $rows = $selected!==null && count($selected)===1?vp3_stoneward_media_rows_v1($pdo,$owner,$selected[0])
        :vp3_stoneward_media_rows_v1($pdo,$owner);
    if($selected!==null)$rows=array_intersect_key($rows,array_fill_keys($selected,true));
    if ($rows) $coverage = 'ready';
    if($rows&&($refs===null||preg_grep('/\Amedia:stoneward:[a-f0-9]{32}:resume-/D',$refs))){
        require_once __DIR__.'/agent-media-stoneward-playback-v1.php';
        $resume=vp3_stoneward_playback_resume_v1($pdo,$user,$scope['principal'],
            now:$now->getTimestamp(),eligibleConnectionIds:array_keys($rows));
        if($resume!==null&&isset($rows[$resume['connection_id']])){
            $id=$resume['connection_id'];
            $ref='media:stoneward:'.$id.':resume-'.hash('sha256',$resume['track_id']);
            if($refs===null||in_array($ref,$refs,true)){
                $position=(int)$resume['position_seconds'];
                $positionLabel=intdiv($position,60).':'.str_pad((string)($position%60),2,'0',STR_PAD_LEFT);
                $items[$ref]=vp3_coordination_source_item_v1($ref,'media',$resume['title'],
                    'You left “'.$resume['title'].'” at '.$positionLabel.' on '.parse_url($resume['origin'],PHP_URL_HOST).'.',
                    'Continue from '.$positionLabel.'.','Want to pick up where you left off?',
                    $resume['origin'].'/?view=history','ready','low',[$rows[$id]['grant_revision'],$resume['track_id']])
                    +['kind'=>'stoneward_resume','trigger'=>'resume','stoneward_connection_id'=>$id,
                        'stoneward_track_id'=>$resume['track_id'],'stoneward_resume_revision'=>$resume['revision']];
            }
        }
    }
    foreach ($rows as $id=>$row) {
        if ($row['state']==='on_demand') continue;
        if (vp3_connected_sites_report_state_v1($row,$now->getTimestamp())!=='ready') {
            $coverage = 'limited';
            continue;
        }
        $track = $row['saved']['track'] ?? null;
        if ($track===null) continue;
        $ref = 'media:stoneward:'.$id.':'.hash('sha256',$track['id']);
        if ($refs!==null && !in_array($ref,$refs,true)) continue;
        $song = '“'.$track['title'].'”'.($track['artist']!==''?' by '.$track['artist']:'');
        $host = (string)parse_url($row['origin'],PHP_URL_HOST);
        $summary = 'You listened to '.$song.' in '.$track['sessions'].' separate sessions over the past week on '.$host.'.';
        $items[$ref] = vp3_coordination_source_item_v1($ref,'media',$track['title'],$summary,
            'Play this track or queue related songs from Stonefellow.','Want to hear it again or queue a few related songs?',$row['origin'].'/?view=history','ready','low',
            [$row['grant_revision'],$track['id']])+['kind'=>'stoneward_track','trigger'=>'stoneward_repeat',
                'stoneward_connection_id'=>$id,'stoneward_track_id'=>$track['id']];
    }
    return ['items'=>$items,'coverage'=>$coverage];
}
