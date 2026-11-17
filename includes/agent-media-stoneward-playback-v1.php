<?php
declare(strict_types=1);

require_once __DIR__.'/connected-stoneward-v1.php';
require_once __DIR__.'/agent-media-playback-v1.php';
require_once __DIR__.'/agent-commerce-recovery-pa2.php';

const VP3_STONEWARD_PLAYBACK_TTL_V1 = 86400;
const VP3_STONEWARD_RESUME_AGE_V1 = 172800;

function vp3_stoneward_playback_schema_v1(PDO $pdo): void
{
    $sqlite=agent_commerce_sqlite_pa2($pdo);
    $indexes=$sqlite?'':', INDEX sw_playback_recent_v1 (owner_user_id,last_event_at),
        INDEX sw_playback_offer_v1 (owner_user_id,conversation_id,offer_message_id,offer_reply_message_id)';
    $options=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_stoneward_playbacks_v1 (
        id CHAR(32) NOT NULL PRIMARY KEY,
        owner_user_id INT UNSIGNED NOT NULL,
        agent_id BIGINT UNSIGNED NOT NULL,
        conversation_id BIGINT UNSIGNED NOT NULL,
        assistant_message_id BIGINT UNSIGNED NOT NULL,
        offer_message_id BIGINT UNSIGNED NOT NULL,
        offer_reply_message_id BIGINT UNSIGNED NOT NULL,
        connection_id CHAR(32) NOT NULL,
        connection_revision CHAR(64) NOT NULL,
        track_id VARCHAR(120) NOT NULL,
        title VARCHAR(180) NOT NULL,
        artist VARCHAR(160) NOT NULL,
        started_at BIGINT NOT NULL,
        last_event_at BIGINT NOT NULL,
        last_sequence INT UNSIGNED NOT NULL,
        last_event VARCHAR(8) NOT NULL,
        position_seconds DECIMAL(12,3) NOT NULL,
        duration_seconds DECIMAL(12,3) NOT NULL,
        listened_seconds DECIMAL(12,3) NOT NULL,
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (connection_id) REFERENCES vp3_stoneward_connections_v1(id) ON DELETE CASCADE,
        FOREIGN KEY (assistant_message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
        {$indexes}
    )".$options);
    if($sqlite){
        $pdo->exec('CREATE INDEX IF NOT EXISTS sw_playback_recent_v1 ON vp3_stoneward_playbacks_v1(owner_user_id,last_event_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS sw_playback_offer_v1 ON vp3_stoneward_playbacks_v1(owner_user_id,conversation_id,offer_message_id,offer_reply_message_id)');
    }
}

function vp3_stoneward_playback_ready_v1(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    try{return $pdo->query('SELECT id,connection_revision,last_sequence FROM vp3_stoneward_playbacks_v1 WHERE 1=0')!==false;}
    catch(PDOException $error){return false;}
}

function vp3_stoneward_playback_connection_revision_v1(array $row): string
{
    return hash('sha256',json_encode([
        (int)$row['owner_user_id'],$row['id'],$row['instance_id'],$row['remote_connection_id'],$row['origin'],$row['token_ciphertext'],
    ],JSON_THROW_ON_ERROR));
}

function vp3_stoneward_playback_message_v1(PDO $pdo,int $cid,int $messageId,bool $lock=false): ?array
{
    $query=$pdo->prepare("SELECT context_json FROM chat_messages
        WHERE conversation_id=? AND id=? AND user_id IS NULL AND role='assistant'".($lock?agent_commerce_lock_pa2($pdo):''));
    $query->execute([$cid,$messageId]);
    $row=$query->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    $context=json_decode((string)($row['context_json']??''),true,32);
    return is_array($context)?$context:[];
}

/** Bind playback to the exact saved owner reply, not a matching title. */
function vp3_stoneward_playback_offer_reply_v1(PDO $pdo,array $action,array $reply,bool $lock=false): int
{
    $outcome=$reply['initiative_followthrough']??[];
    $replyId=$reply['chat_user_message_id']??null;
    if(($reply['media_offer_decision_v1']['kind']??null)!=='stoneward'
        ||($reply['media_offer_decision_v1']['connection_id']??null)!==$action['connection']||!is_int($replyId)||$replyId<1
        ||($outcome['reply_message_id']??null)!==$replyId||($outcome['message_id']??null)!==$action['offer']
        ||($outcome['owner_user_id']??null)!==$action['owner']||($outcome['agent_id']??null)!==$action['agent']
        ||($outcome['conversation_id']??null)!==$action['chat']||($outcome['state']??null)!=='owner_resolved'){
        return 0;
    }
    $offer=vp3_stoneward_playback_message_v1($pdo,$action['chat'],$action['offer'],$lock);
    $state=$offer['initiative_followthrough_state']??[];
    $evidence=$offer['initiative_output']['evidence_revisions']??[];
    $prefix='media:stoneward:'.$action['connection'].':';
    $trackRef=hash('sha256',$action['track']);
    if(($offer['source']??null)!=='agent_initiative'||($state['state']??null)!=='owner_resolved'
        ||($state['last_reply_message_id']??null)!==$replyId||($state['reply_ref']??null)!==($outcome['reply_ref']??null)
        ||($state['revision']??null)!==($outcome['revision']??null)
        ||!is_array($evidence)||count($evidence)!==1||!in_array(array_key_first($evidence),[$prefix.$trackRef,$prefix.'resume-'.$trackRef],true))return 0;
    $query=$pdo->prepare("SELECT 1 FROM chat_messages WHERE id=? AND conversation_id=? AND role='user' AND user_id=?");
    $query->execute([$replyId,$action['chat'],$action['owner']]);
    return $query->fetchColumn()!==false ? $replyId : 0;
}

function vp3_stoneward_playback_direct_v1(array $reply,string $connection): bool
{
    $actions=$reply['connected_site_context_v1']['actions']??null;
    return ($reply['connected_site_provider_v1']??null)==='stoneward'
        &&($reply['connected_site_context_v1']['provider']??null)==='stoneward'
        &&($reply['connected_site_context_v1']['connectionId']??null)===$connection
        &&in_array($actions,[['listening.track.play'],['listening.track.queue']],true);
}

function vp3_stoneward_playback_signature_v1(string $payload): ?string
{
    $master=ai_master_key();
    return $master===null ? null : hash_hmac('sha256','vp3-stoneward-playback-v1|'.$payload,$master);
}

/** Called only after the verified audio action is saved in the owner's Chat. */
function vp3_stoneward_playback_ticket_v1(PDO $pdo,array $user,array $principal,int $cid,int $assistantMessageId,
    string $connectionId,array $verifiedPlayback,?int $offerMessageId=null): ?string
{
    if($cid<1||$assistantMessageId<1||($offerMessageId!==null&&($offerMessageId<1||$offerMessageId>=$assistantMessageId))
        ||!in_array($principal['kind']??null,['system','user_agent'],true)||!vp3_stoneward_playback_ready_v1($pdo))return null;
    try{
        $agent=(int)($principal['agent_id']??0);
        [$live,$canonical]=vp3_media_playback_user_v1($pdo,$user,$agent,$cid);
        if($canonical['kind']!==$principal['kind']||!vp3_media_playback_history_allowed_v1($pdo,$live,$canonical))return null;
        $owner=(int)$live['id'];
        $connection=vp3_stoneward_connection_v1($pdo,$owner,$connectionId);
        $reply=vp3_stoneward_playback_message_v1($pdo,$cid,$assistantMessageId);
        $url=$verifiedPlayback['url']??null;
        $prefix=$connection['origin'].'/api/stoneward-vp3-audio.php?track_id=';
        $track=is_string($url)&&str_starts_with($url,$prefix)?substr($url,strlen($prefix)):'';
        $title=vp3_stoneward_text_v1($verifiedPlayback['title']??null,180);
        $artist=vp3_stoneward_text_v1($verifiedPlayback['artist']??null,160);
        if($reply===null||$title===''||($verifiedPlayback['origin']??null)!==$connection['origin']
            ||($verifiedPlayback['mime']??null)!=='audio/mpeg'||!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,119}\z/D',$track)){
            return null;
        }
        $action=['id'=>bin2hex(random_bytes(16)),'owner'=>$owner,'agent'=>$agent,'chat'=>$cid,
            'assistant'=>$assistantMessageId,'offer'=>$offerMessageId??0,'offer_reply'=>0,'connection'=>$connectionId,
            'connection_revision'=>vp3_stoneward_playback_connection_revision_v1($connection),'track'=>$track,
            'title'=>$title,'artist'=>$artist,'issued'=>time()];
        if($offerMessageId!==null){
            $action['offer_reply']=vp3_stoneward_playback_offer_reply_v1($pdo,$action,$reply);
            if($action['offer_reply']<1)return null;
        }elseif(!vp3_stoneward_playback_direct_v1($reply,$connectionId))return null;
        $payload=rtrim(strtr(base64_encode(json_encode($action,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),'+/','-_'),'=');
        $signature=vp3_stoneward_playback_signature_v1($payload);
        return $signature===null?null:$payload.'.'.$signature;
    }catch(RuntimeException $error){
        // Existing streamed tracks still play when the tracking upgrade is unavailable.
        return null;
    }
}

function vp3_stoneward_playback_read_ticket_v1(mixed $ticket,int $owner,int $now): array
{
    if(!is_string($ticket)||!preg_match('/\A([A-Za-z0-9_-]{1,4096})\.([a-f0-9]{64})\z/D',$ticket,$match)){
        throw new RuntimeException('This playback card could not be verified.',403);
    }
    $signature=vp3_stoneward_playback_signature_v1($match[1]);
    if($signature===null||!hash_equals($signature,$match[2]))throw new RuntimeException('This playback card could not be verified.',403);
    $raw=base64_decode(strtr($match[1],'-_','+/'),true);
    $action=is_string($raw)?json_decode($raw,true,8):null;
    if(!is_array($action)||($action['owner']??null)!==$owner||!is_int($action['issued']??null)||$action['issued']>$now+5){
        throw new RuntimeException('This playback card belongs to a different or changed session.',403);
    }
    if($action['issued']<$now-VP3_STONEWARD_PLAYBACK_TTL_V1)throw new RuntimeException('This playback card expired. Ask for the track again.',409);
    return $action;
}

function vp3_stoneward_playback_event_v1(PDO $pdo,array $user,array $input,?int $now=null): array
{
    if(!vp3_stoneward_playback_ready_v1($pdo))throw new RuntimeException('Run Database Upgrade for Stonefellow playback history.',503);
    $now??=time();
    $owner=(int)($user['id']??0);
    $action=vp3_stoneward_playback_read_ticket_v1($input['ticket']??null,$owner,$now);
    $sequence=$input['sequence']??null;
    $event=$input['event']??null;
    $position=$input['position_seconds']??null;
    $duration=$input['duration_seconds']??null;
    if(!is_int($sequence)||$sequence<1||$sequence>1000000||!in_array($event,['playing','progress','pause','ended'],true)
        ||(!is_int($position)&&!is_float($position))||(!is_int($duration)&&!is_float($duration))
        ||!is_finite($position)||!is_finite($duration)||$duration<0.001||$duration>86400||$position<0||$position>$duration+1
        ||($event==='ended'&&$position<$duration-2)){
        throw new RuntimeException('The Player sent an invalid position or event.',422);
    }
    $duration=round((float)$duration,3);
    $position=round(min((float)$position,$duration),3);

    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$action,$owner,$now,$sequence,$event,$position,$duration):array{
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.agent_commerce_lock_pa2($pdo));
        $lock->execute([$owner]);
        if(!$lock->fetchColumn())throw new RuntimeException('Your playback access changed.',403);
        [$live,$principal]=vp3_media_playback_user_v1($pdo,$user,$action['agent'],$action['chat']);
        if(!vp3_media_playback_history_allowed_v1($pdo,$live,$principal))throw new RuntimeException('Listening history access changed.',403);
        $connection=vp3_stoneward_connection_v1($pdo,$owner,$action['connection']);
        if(!hash_equals($action['connection_revision'],vp3_stoneward_playback_connection_revision_v1($connection))){
            throw new RuntimeException('This artist connection changed.',409);
        }
        $reply=vp3_stoneward_playback_message_v1($pdo,$action['chat'],$action['assistant'],true);
        if($reply===null||($action['offer']>0
            ? vp3_stoneward_playback_offer_reply_v1($pdo,$action,$reply,true)!==$action['offer_reply']
            : !vp3_stoneward_playback_direct_v1($reply,$action['connection']))){
            throw new RuntimeException('This playback request is no longer available.',409);
        }
        $query=$pdo->prepare('SELECT last_sequence,last_event_at,last_event,position_seconds,listened_seconds
            FROM vp3_stoneward_playbacks_v1 WHERE id=? AND owner_user_id=?'.agent_commerce_lock_pa2($pdo));
        $query->execute([$action['id'],$owner]);
        $row=$query->fetch(PDO::FETCH_ASSOC);
        $result=['ok'=>true,'played'=>true,'sequence'=>$row ? max($sequence,(int)$row['last_sequence']) : $sequence]
            +($action['offer']>0?['offer_message_id'=>$action['offer']]:[]);
        if($row&&(int)$row['last_sequence']>=$sequence)return $result;
        if(!$row){
            if($event!=='playing')throw new RuntimeException('Confirm that the track started before saving a position.',409);
            $pdo->prepare('INSERT INTO vp3_stoneward_playbacks_v1
                (id,owner_user_id,agent_id,conversation_id,assistant_message_id,offer_message_id,offer_reply_message_id,
                    connection_id,connection_revision,track_id,title,artist,started_at,last_event_at,last_sequence,last_event,
                    position_seconds,duration_seconds,listened_seconds)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)')
                ->execute([$action['id'],$owner,$action['agent'],$action['chat'],$action['assistant'],$action['offer'],
                    $action['offer_reply'],$action['connection'],$action['connection_revision'],$action['track'],$action['title'],
                    $action['artist'],$now,$now,$sequence,$event,$position,$duration]);
            return $result;
        }
        if($row['last_event']==='ended'&&$event!=='playing')throw new RuntimeException('The track ended. Start playback again before saving another position.',409);
        $elapsed=max(0,$now-(int)$row['last_event_at']);
        $advance=$position-(float)$row['position_seconds'];
        $listened=(float)$row['listened_seconds'];
        if(in_array($row['last_event'],['playing','progress'],true)&&$advance>0&&$advance<=$elapsed+1.5){
            $listened+=min($advance,(float)$elapsed,20.0);
        }
        $pdo->prepare('UPDATE vp3_stoneward_playbacks_v1 SET last_event_at=?,last_sequence=?,last_event=?,
            position_seconds=?,duration_seconds=?,listened_seconds=? WHERE id=? AND owner_user_id=?')
            ->execute([max($now,(int)$row['last_event_at']),$sequence,$event,$position,$duration,min($listened,86400.0),$action['id'],$owner]);
        if($event==='pause'&&$row['last_event']!=='pause'&&vp3_stoneward_playback_resumable_position_v1($position,$duration,$listened)){
            require_once __DIR__.'/agent-coordination-source-change-v1.php';
            vp3_coordination_source_changed_v1($pdo,$owner);
        }
        return $result;
    });
}

function vp3_stoneward_playback_resumable_position_v1(float $position,float $duration,float $listened): bool
{
    return $position>=10&&$duration-$position>=15&&$position/$duration<0.95&&$listened>=10;
}

/** Consult only the latest connected track in the current owner's listening history. */
function vp3_stoneward_playback_resume_v1(
    PDO $pdo,array $user,array $principal,?string $connectionId=null,?int $now=null,?array $eligibleConnectionIds=null
): ?array
{
    if(!vp3_stoneward_playback_ready_v1($pdo)||!in_array($principal['kind']??null,['system','user_agent'],true))return null;
    $now??=time();
    [$live,$canonical]=vp3_media_playback_user_v1($pdo,$user,(int)($principal['agent_id']??0));
    if($principal['kind']!==$canonical['kind']||!vp3_media_playback_history_allowed_v1($pdo,$live,$canonical))return null;
    if($connectionId!==null&&!preg_match('/\A[a-f0-9]{32}\z/D',$connectionId))return null;
    $owner=(int)$live['id'];
    $connectionIds=$connectionId===null?$eligibleConnectionIds:[$connectionId];
    if($connectionIds===[])return null;
    $connectionScope=$connectionIds===null?'':' AND p.connection_id IN ('.implode(',',array_fill(0,count($connectionIds),'?')).')';
    $sql="SELECT p.*,c.origin FROM vp3_stoneward_playbacks_v1 p
        JOIN vp3_stoneward_connections_v1 c ON c.id=p.connection_id AND c.owner_user_id=p.owner_user_id
        WHERE p.owner_user_id=? AND p.last_event_at BETWEEN ? AND ?".$connectionScope."
        ORDER BY p.last_event_at DESC,p.started_at DESC LIMIT 1";
    $query=$pdo->prepare($sql);
    $query->execute([$owner,$now-VP3_STONEWARD_RESUME_AGE_V1,$now,...($connectionIds??[])]);
    $row=$query->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    $position=(float)$row['position_seconds'];
    $duration=(float)$row['duration_seconds'];
    $at=(int)$row['last_event_at'];
    if($row['last_event']==='ended'||($row['last_event']!=='pause'&&$at>$now-35)
        ||!vp3_stoneward_playback_resumable_position_v1($position,$duration,(float)$row['listened_seconds']))return null;
    $site=vp3_stoneward_connection_v1($pdo,$owner,$row['connection_id']);
    if(!hash_equals($row['connection_revision'],vp3_stoneward_playback_connection_revision_v1($site)))return null;
    return ['connection_id'=>$row['connection_id'],'track_id'=>$row['track_id'],'origin'=>$row['origin'],
        'title'=>$row['title'],'artist'=>$row['artist'],'position_seconds'=>$position,'duration_seconds'=>$duration,
        'played_at'=>gmdate(DATE_ATOM,$at),'revision'=>hash('sha256',json_encode([$row['id'],$row['last_sequence'],$position,
            $duration,$row['connection_revision']],JSON_THROW_ON_ERROR))];
}

/** Receipt projection is separate from the original immutable Initiative decision. */
function vp3_stoneward_playback_offer_origin_v1(array $origin): bool
{
    $primary=$origin['goal']['primary']??null;
    return ($origin['goal']['coordination_route']['specialist']??null)==='media'
        &&is_string($primary)&&preg_match('/\Amedia:stoneward:[a-f0-9]{32}:(?:resume-)?[a-f0-9]{64}\z/D',$primary)===1;
}

function vp3_stoneward_playback_offer_played_v1(PDO $pdo,array $origin): bool
{
    $state=$origin['state']??[];
    $reply=$state['last_reply_message_id']??null;
    if(!vp3_stoneward_playback_offer_origin_v1($origin)
        ||($state['state']??null)!=='owner_resolved'||!is_int($reply)||$reply<1
        ||!vp3_stoneward_playback_ready_v1($pdo)
        ||!vp3_media_playback_history_allowed_v1($pdo,$origin['user'],$origin['principal']))return false;
    $query=$pdo->prepare("SELECT p.connection_revision,p.track_id,c.id,c.owner_user_id,c.origin,c.instance_id,c.remote_connection_id,c.token_ciphertext
        FROM vp3_stoneward_playbacks_v1 p
        JOIN vp3_stoneward_connections_v1 c ON c.id=p.connection_id AND c.owner_user_id=p.owner_user_id
        WHERE p.owner_user_id=? AND p.agent_id=? AND p.conversation_id=? AND p.offer_message_id=? AND p.offer_reply_message_id=?
        ORDER BY p.started_at DESC LIMIT 1");
    $query->execute([(int)$origin['user']['id'],(int)$origin['agent_id'],(int)$origin['conversation_id'],(int)$origin['message']['id'],$reply]);
    $row=$query->fetch(PDO::FETCH_ASSOC);
    if(!$row||!hash_equals($row['connection_revision'],vp3_stoneward_playback_connection_revision_v1($row)))return false;
    $ref=$origin['goal']['primary'];
    $prefix='media:stoneward:'.$row['id'].':';
    $trackRef=hash('sha256',$row['track_id']);
    if(!in_array($ref,[$prefix.$trackRef,$prefix.'resume-'.$trackRef],true))return false;
    require_once __DIR__.'/agent-media-stoneward-v1.php';
    $consent=vp3_stoneward_media_rows_v1($pdo,(int)$origin['user']['id'],$row['id'])[$row['id']]??null;
    if($consent===null||($consent['state']??null)==='authorization_required')return false;
    return ($origin['goal']['sources'][$ref]??null)===vp3_coordination_source_hash_v1([$ref,$consent['grant_revision'],$row['track_id']]);
}

/** A consumed resume no longer has a current playhead, but retains its exact authorized receipt. */
function vp3_stoneward_playback_resumed_offer_played_v1(PDO $pdo,array $origin): bool
{
    $ref=$origin['goal']['primary']??null;
    return is_string($ref)&&preg_match('/\Amedia:stoneward:[a-f0-9]{32}:resume-[a-f0-9]{64}\z/D',$ref)===1
        &&vp3_stoneward_playback_offer_played_v1($pdo,$origin);
}
