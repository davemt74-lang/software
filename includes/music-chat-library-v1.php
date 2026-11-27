<?php
declare(strict_types=1);

require_once __DIR__.'/agent-media-playback-v1.php';
require_once __DIR__.'/agent-connection-youtube-playback-v1.php';
require_once __DIR__.'/agent-commerce-recovery-pa2.php';

/** Private music selections are references, never a second listening ledger. */
const VP3_MUSIC_CHAT_PROTOCOL_V1='vp3_music_chat_v1';
const VP3_MUSIC_CHAT_LIMIT_V1=25;
const VP3_MUSIC_CHAT_PLAYLIST_LIMIT_V1=100;
const VP3_MUSIC_CHAT_RESULT_TTL_V1=604800;

final class MusicChatUpgradeRequiredV1 extends RuntimeException
{
    public function __construct(){parent::__construct('Music requests need the application upgrade.',503);}
}

function vp3_music_chat_schema_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Run the music upgrade outside a transaction.');
    $text=agent_commerce_sqlite_pa2($pdo)?'TEXT':'LONGTEXT';
    $tail=agent_commerce_sqlite_pa2($pdo)?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS music_chat_results_v1 (
        result_id CHAR(32) NOT NULL PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL,
        agent_id BIGINT UNSIGNED NOT NULL, conversation_id BIGINT UNSIGNED NOT NULL,
        request_key CHAR(64) NOT NULL, request_hash CHAR(64) NOT NULL, revision CHAR(64) NOT NULL,
        items_json {$text} NOT NULL, meta_json {$text} NOT NULL,
        created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL,
        assistant_message_id BIGINT UNSIGNED NULL, playlist_id CHAR(32) NULL, playlist_changed TINYINT NULL, youtube_playlist_id CHAR(32) NULL,
        UNIQUE(owner_user_id,conversation_id,request_key),
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ){$tail}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS music_chat_playlists_v1 (
        playlist_id CHAR(32) NOT NULL PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL,
        agent_id BIGINT UNSIGNED NOT NULL, conversation_id BIGINT UNSIGNED NOT NULL,
        source_result_id CHAR(32) NOT NULL, request_key CHAR(64) NOT NULL, request_hash CHAR(64) NOT NULL,
        title VARCHAR(200) NOT NULL, revision BIGINT NOT NULL, items_json {$text} NOT NULL,
        created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
        UNIQUE(owner_user_id,request_key),
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ){$tail}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS music_chat_play_actions_v1 (
        action_id CHAR(32) NOT NULL PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL,
        result_id CHAR(32) NOT NULL UNIQUE, item_id CHAR(32) NOT NULL, playlist_revision BIGINT NULL,
        expires_at BIGINT NOT NULL, claimed_at BIGINT NULL,
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(result_id) REFERENCES music_chat_results_v1(result_id) ON DELETE CASCADE
    ){$tail}");
    if(!agent_commerce_recovery_column_pa2($pdo,'music_chat_results_v1','playlist_changed')){
        $pdo->exec('ALTER TABLE music_chat_results_v1 ADD COLUMN playlist_changed TINYINT NULL');
    }
    // An earlier prerelease schema may lack the immutable creation receipt.
    // Leave its unknown hash blank rather than infer it from a later renamed title.
    if(!agent_commerce_recovery_column_pa2($pdo,'music_chat_playlists_v1','request_hash')){
        $pdo->exec("ALTER TABLE music_chat_playlists_v1 ADD COLUMN request_hash CHAR(64) NOT NULL DEFAULT ''");
    }
    foreach([
        ['music_chat_results_v1','music_chat_results_recent_v1','owner_user_id,agent_id,conversation_id,created_at'],
        ['music_chat_results_v1','music_chat_results_expiry_v1','expires_at'],
        ['music_chat_playlists_v1','music_chat_playlists_recent_v1','owner_user_id,updated_at'],
    ] as [$table,$index,$columns]){
        if(agent_commerce_sqlite_pa2($pdo))$pdo->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table}({$columns})");
        else{
            $q=$pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            $q->execute([$table,$index]);
            if(!$q->fetchColumn())$pdo->exec("CREATE INDEX {$index} ON {$table}({$columns})");
        }
    }
}

function vp3_music_chat_ready_v1(PDO $pdo): bool
{
    try{
        $pdo->query('SELECT result_id,revision,items_json,meta_json,assistant_message_id,playlist_changed,youtube_playlist_id FROM music_chat_results_v1 WHERE 1=0');
        $pdo->query('SELECT playlist_id,revision,items_json,request_hash FROM music_chat_playlists_v1 WHERE 1=0');
        $pdo->query('SELECT action_id,item_id,playlist_revision,claimed_at FROM music_chat_play_actions_v1 WHERE 1=0');
        return true;
    }catch(PDOException){return false;}
}

function vp3_music_chat_scope_v1(PDO $pdo,array $user,int $agent,int $cid,bool $lock=false): array
{
    if($cid<1||$agent<0||(function_exists('db')&&db()!==$pdo))throw new RuntimeException('Open your private Agent Chat.',403);
    // Acquire the common owner lock before the first transactional snapshot read.
    // SQLite needs a writer reservation; MySQL uses current locking reads.
    if($lock){
        if(!$pdo->inTransaction())throw new LogicException('Music mutations require a transaction.');
        $owner=(int)($user['id']??0);
        if(agent_commerce_sqlite_pa2($pdo)){
            $pdo->prepare('UPDATE users SET id=id WHERE id=? AND is_active=1')->execute([$owner]);
        }
        $q=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.agent_commerce_lock_pa2($pdo));
        $q->execute([$owner]);
        if(!$q->fetchColumn())throw new RuntimeException('Account access changed.',403);
        if($agent>0){
            $q=$pdo->prepare('SELECT id FROM user_agents WHERE id=? AND owner_user_id=? AND is_active=1'.agent_commerce_lock_pa2($pdo));
            $q->execute([$agent,$owner]);
            if(!$q->fetchColumn())throw new RuntimeException('Agent access changed.',403);
        }
        $q=$pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? AND '
            .($agent>0?'user_agent_id=?':'user_agent_id IS NULL').agent_commerce_lock_pa2($pdo));
        $q->execute([$cid,$owner,...($agent>0?[$agent]:[])]);
        if(!$q->fetchColumn())throw new RuntimeException('Conversation access changed.',403);
    }
    if(function_exists('current_user')){
        $viewer=current_user();
        if(!$viewer||(int)($viewer['id']??0)!==(int)($user['id']??0))throw new RuntimeException('Account access changed.',403);
    }
    try{[$live,$principal]=vp3_media_playback_user_v1($pdo,$user,$agent,$cid);}
    catch(RuntimeException $e){throw new RuntimeException('Music access changed. Reopen your private Agent Chat.',403,$e);}
    if(!vp3_music_chat_ready_v1($pdo))throw new MusicChatUpgradeRequiredV1();
    return ['user'=>$live,'principal'=>$principal,'owner_user_id'=>(int)$live['id'],'agent_id'=>$agent,'conversation_id'=>$cid];
}

function vp3_music_chat_scope_fields_v1(array $scope): array
{
    return array_intersect_key($scope,array_flip(['owner_user_id','agent_id','conversation_id']));
}

function vp3_music_chat_text_v1(mixed $value,int $limit=200): string
{
    if(!is_string($value))return '';
    return mb_substr(trim(preg_replace('/[\p{Cc}\p{Cf}<>]+/u',' ',strip_tags($value))??''),0,$limit);
}

function vp3_music_chat_id_v1(mixed $value): string
{
    if(!is_string($value)||preg_match('/\A[a-f0-9]{32}\z/D',$value)!==1)throw new RuntimeException('Choose a saved music selection.',422);
    return $value;
}

function vp3_music_chat_json_v1(mixed $json,int $max=131072): array
{
    if(!is_string($json)||strlen($json)>$max)throw new RuntimeException('Music selection is unavailable.',503);
    try{$value=json_decode($json,true,24,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new RuntimeException('Music selection is unavailable.',503,$e);}
    if(!is_array($value))throw new RuntimeException('Music selection is unavailable.',503);
    return $value;
}

function vp3_music_chat_youtube_allowed_v1(PDO $pdo,array $scope): bool
{
    return agent_connection_youtube_enabled_v1()&&agent_connection_youtube_consent_ready_v1($pdo)
        &&agent_connection_youtube_consent_v1($pdo,$scope['owner_user_id']);
}

/** Only a current verified provider result can enter this internal service. HTTP never accepts videos. */
function vp3_music_chat_video_v1(array $raw,string $id): array
{
    if(preg_match('/\A[A-Za-z0-9_-]{11}\z/D',$id)!==1||($raw['video_id']??null)!==$id
        ||($raw['made_for_kids']??null)!==false)throw new RuntimeException('YouTube selection is unavailable.',422);
    $title=vp3_music_chat_text_v1($raw['title']??'',200);
    if($title==='')throw new RuntimeException('YouTube selection is unavailable.',422);
    $video=['video_id'=>$id,'title'=>$title,'channel_title'=>vp3_music_chat_text_v1($raw['channel_title']??'',120),
        'thumbnail_url'=>'','made_for_kids'=>$raw['made_for_kids']];
    $cover=$raw['thumbnail_url']??'';
    if(is_string($cover)&&preg_match('~\Ahttps://(?:i\.ytimg\.com|img\.youtube\.com)/(?:vi|vi_webp)/'.preg_quote($id,'~').'/[A-Za-z0-9_]{1,32}\.(?:jpg|webp)\z~D',$cover))$video['thumbnail_url']=$cover;
    if(is_bool($raw['music_category']??null))$video['music_category']=$raw['music_category'];
    if(is_array($raw['public_context']??null)){
        $c=$raw['public_context'];
        $video['public_context']=['title'=>vp3_music_chat_text_v1($c['title']??$title,160),
            'channel'=>vp3_music_chat_text_v1($c['channel']??$video['channel_title'],90),
            'description'=>vp3_music_chat_text_v1($c['description']??'',500),'music'=>($c['music']??false)===true];
    }
    return $video;
}

/** Playback eligibility is separate from the stricter existing history capture policy. */
function vp3_music_chat_find_video_v1(array $payload,string $id): ?array
{
    foreach($payload['videos']??[] as $video){
        if(is_array($video)&&($video['video_id']??null)===$id&&($video['made_for_kids']??null)===false){
            return vp3_music_chat_video_v1($video,$id);
        }
    }
    return null;
}

function vp3_music_chat_native_item_v1(PDO $pdo,array $scope,string $id,?string $revision=null): ?array
{
    if(preg_match('/\A[1-9][0-9]{0,15}\z/D',$id)!==1||(int)$id>9007199254740991)return null;
    $track=vp3_media_playable_track_v1($pdo,$scope['user'],(int)$id,true);
    if(!$track)return null;
    $current=vp3_media_track_revision_v1($scope['owner_user_id'],$scope['agent_id'],$track);
    if($revision!==null&&!hash_equals($current,$revision))return null;
    $details=vp3_media_track_details_v1($pdo,$track);
    $payload=track_media_payload($track);
    return ['source'=>'vp3','source_id'=>$id,'title'=>$details['title'],'artist'=>$details['artist'],
        'album'=>$details['album'],'duration'=>vp3_music_chat_text_v1($track['duration']??'',40),
        'cover'=>is_string($payload['cover']??null)?$payload['cover']:'','native_revision'=>$current];
}

function vp3_music_chat_items_normalize_v1(PDO $pdo,array $scope,array $items): array
{
    if(!array_is_list($items)||count($items)>VP3_MUSIC_CHAT_LIMIT_V1)throw new RuntimeException('Choose at most 25 songs.',422);
    $out=[];$seen=[];$now=time();
    foreach($items as $item){
        if(!is_array($item)||!in_array($item['source']??null,['vp3','youtube'],true))throw new RuntimeException('Choose a VP3 or YouTube song.',422);
        $source=$item['source'];$id=$item['source_id']??null;
        if(is_int($id))$id=(string)$id;
        if(!is_string($id)||isset($seen[$source.':'.$id]))throw new RuntimeException('A selection contains duplicate or invalid songs.',422);
        if($source==='vp3'){
            $row=vp3_music_chat_native_item_v1($pdo,$scope,$id,is_string($item['native_revision']??null)?$item['native_revision']:null);
            if(!$row)throw new RuntimeException('A VP3 song changed or is no longer available.',409);
        }else{
            if(!vp3_music_chat_youtube_allowed_v1($pdo,$scope))throw new RuntimeException('YouTube access changed.',403);
            $expires=$item['expires_at']??null;
            if(!is_int($expires)||$expires<=$now||$expires>$now+VP3_MUSIC_CHAT_RESULT_TTL_V1)throw new RuntimeException('YouTube result expired. Search again.',409);
            $video=vp3_music_chat_video_v1(is_array($item['youtube_video']??null)?$item['youtube_video']:[],$id);
            $row=['source'=>'youtube','source_id'=>$id,'title'=>$video['title'],'artist'=>$video['channel_title'],
                'album'=>'','duration'=>vp3_music_chat_text_v1($item['duration']??'',40),'cover'=>$video['thumbnail_url'],
                'youtube_video'=>$video,'expires_at'=>$expires];
        }
        $row['item_id']=bin2hex(random_bytes(16));$out[]=$row;$seen[$source.':'.$id]=true;
    }
    return $out;
}

function vp3_music_chat_meta_v1(array $meta): array
{
    $intent=$meta['intent']??'show';$source=$meta['source']??'both';$count=$meta['requested_count']??1;
    if(!is_string($intent)||preg_match('/\A[a-z_]{1,32}\z/D',$intent)!==1||!in_array($source,['vp3','youtube','both'],true)
        ||!is_int($count)||$count<1||$count>VP3_MUSIC_CHAT_LIMIT_V1)throw new RuntimeException('Invalid music request.',422);
    $coverage=is_array($meta['coverage']??null)?$meta['coverage']:[];
    if(strlen(json_encode($coverage,JSON_THROW_ON_ERROR))>6000)throw new RuntimeException('Music coverage is too large.',422);
    $coverage=vp3_music_chat_coverage_v1($coverage);
    $status=in_array($meta['status']??null,['ready','no_matches','ambiguous','partial','unavailable'],true)?$meta['status']:'ready';
    $selection=$meta['selection_index']??0;
    if(!is_int($selection)||$selection<0||$selection>=VP3_MUSIC_CHAT_LIMIT_V1)$selection=0;
    $existing=$meta['existing_playlist_id']??null;$playlistRevision=$meta['expected_playlist_revision']??null;
    if($existing!==null){
        $existing=vp3_music_chat_id_v1($existing);
        if(!is_int($playlistRevision)||$playlistRevision<1||($meta['create_playlist']??false)===true)throw new RuntimeException('Choose the current saved playlist.',422);
    }
    $target=$meta['target_playlist_id']??null;$targetRevision=$meta['expected_target_revision']??null;
    if($target!==null){
        $target=vp3_music_chat_id_v1($target);
        if($intent!=='add_to_playlist'||$count!==1||$existing!==null||!is_int($targetRevision)||$targetRevision<1
            ||($meta['create_playlist']??false)===true||($meta['autoplay']??false)===true)throw new RuntimeException('Choose one song and the current target playlist.',422);
    }elseif($intent==='add_to_playlist')throw new RuntimeException('Choose the target saved playlist.',422);
    return ['intent'=>$intent,'source'=>$source,'requested_count'=>$count,'query'=>vp3_music_chat_text_v1($meta['query']??'',200),
        'title'=>vp3_music_chat_text_v1($meta['title']??'',200),'coverage'=>$coverage,
        'status'=>$status,'selection_index'=>$selection,
        'existing_playlist_id'=>$existing,'expected_playlist_revision'=>$existing===null?null:$playlistRevision,
        'target_playlist_id'=>$target,'expected_target_revision'=>$target===null?null:$targetRevision,
        'create_playlist'=>($meta['create_playlist']??false)===true,'autoplay'=>($meta['autoplay']??false)===true];
}

/** Coverage may contain source status/counters, never arbitrary provider fields or credentials. */
function vp3_music_chat_coverage_v1(array $coverage,int $depth=0): array
{
    if($depth>2)return [];
    $out=[];
    foreach($coverage as $key=>$value){
        if(is_int($key)&&is_array($value)&&count($out)<2){$out[]=vp3_music_chat_coverage_v1($value,$depth+1);continue;}
        if(!in_array($key,['vp3','youtube','sources','source','status','reason','requested_count','returned_count','shortfall','unavailable_count','eligible_count','available','count'],true))continue;
        if(is_array($value))$out[$key]=vp3_music_chat_coverage_v1($value,$depth+1);
        elseif(is_string($value))$out[$key]=vp3_music_chat_text_v1($value,160);
        elseif(is_int($value)||is_bool($value))$out[$key]=$value;
    }
    return $out;
}

function vp3_music_chat_result_row_v1(PDO $pdo,array $scope,string $id,bool $lock=false,bool $savedReference=false): array
{
    $id=vp3_music_chat_id_v1($id);
    $q=$pdo->prepare('SELECT * FROM music_chat_results_v1 WHERE result_id=? AND owner_user_id=? AND agent_id=? AND conversation_id=?'.($lock?agent_commerce_lock_pa2($pdo):''));
    $q->execute([$id,$scope['owner_user_id'],$scope['agent_id'],$scope['conversation_id']]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Music selection is unavailable in this Chat.',404);
    if((int)$row['expires_at']<=time()&&!($savedReference&&$row['playlist_id']!==null))throw new RuntimeException('This selection expired. Ask the Agent to search again.',410);
    return $row;
}

function vp3_music_chat_result_projection_v1(PDO $pdo,array $scope,array $row,bool $replayed=false): array
{
    $meta=vp3_music_chat_json_v1($row['meta_json'],16384);$items=[];$unavailable=0;
    foreach(vp3_music_chat_json_v1($row['items_json']) as $stored){
        $item=vp3_music_chat_result_item_v1($pdo,$scope,$stored);
        if(!$item['available'])++$unavailable;
        $items[]=$item;
    }
    $out=['protocol'=>VP3_MUSIC_CHAT_PROTOCOL_V1,'version'=>1]+vp3_music_chat_scope_fields_v1($scope)+[
        'result_id'=>$row['result_id'],'revision'=>$row['revision'],'intent'=>$meta['intent'],'source'=>$meta['source'],
        'requested_count'=>$meta['requested_count'],'query'=>$meta['query'],'items'=>$items,
        'status'=>$meta['status']??'ready','selection_index'=>$meta['selection_index']??0,
        'coverage'=>array_replace($meta['coverage'],['requested_count'=>$meta['requested_count'],'returned_count'=>count($items),
            'shortfall'=>max(0,$meta['requested_count']-count($items)),'unavailable_count'=>$unavailable]),
        'replayed'=>$replayed,'bound'=>(int)$row['assistant_message_id']>0,
        'source_message_id'=>(int)$row['assistant_message_id']];
    if(($row['playlist_changed']??null)!==null)$out['playlist_changed']=(bool)$row['playlist_changed'];
    if($row['playlist_id']!==null){
        $q=$pdo->prepare('SELECT * FROM music_chat_playlists_v1 WHERE playlist_id=? AND owner_user_id=?');
        $q->execute([$row['playlist_id'],$scope['owner_user_id']]);
        if($playlist=$q->fetch(PDO::FETCH_ASSOC)){
            // A saved card is a live private list: display its current order and
            // revision as one projection, never stale result indices with a new revision.
            $current=vp3_music_chat_playlist_projection_v1($pdo,$scope,$playlist);
            foreach(['playlist','items','source'] as $field)$out[$field]=$current[$field];
            $out['coverage']=array_replace($meta['coverage'],$current['coverage'],[
                'requested_count'=>$meta['requested_count'],
                'shortfall'=>max(0,$meta['requested_count']-count($current['items']))]);
        }else throw new RuntimeException('That private playlist is unavailable.',404);
    }
    return $out;
}

function vp3_music_chat_unavailable_item_v1(array $item,string $reason): array
{
    return ['item_id'=>$item['item_id'],'source'=>$item['source'],'source_id'=>'',
        'title'=>$item['source']==='youtube'?'Unavailable YouTube selection':'Unavailable VP3 song',
        'artist'=>'','album'=>'','duration'=>'','cover'=>'','available'=>false,'reason'=>$reason];
}

function vp3_music_chat_public_item_v1(array $item): array
{
    return array_intersect_key($item,array_flip(['item_id','source','source_id','title','artist','album','duration','cover']))+['available'=>true];
}

function vp3_music_chat_result_item_v1(PDO $pdo,array $scope,array $item): array
{
    if($item['source']==='vp3'){
        $fresh=vp3_music_chat_native_item_v1($pdo,$scope,$item['source_id'],$item['native_revision']??null);
        return $fresh?vp3_music_chat_public_item_v1($fresh+['item_id'=>$item['item_id']]):vp3_music_chat_unavailable_item_v1($item,'track_changed');
    }
    if(!vp3_music_chat_youtube_allowed_v1($pdo,$scope))return vp3_music_chat_unavailable_item_v1($item,'source_unavailable');
    if((int)($item['expires_at']??0)<=time()||!isset($item['youtube_video']))return vp3_music_chat_unavailable_item_v1($item,'expired');
    return vp3_music_chat_public_item_v1($item);
}

function vp3_music_chat_result_store_v1(PDO $pdo,array $user,int $agent,int $cid,string $requestKey,string $requestHash,array $items,array $meta=[]): array
{
    $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
    if($requestKey===''||strlen($requestKey)>256||preg_match('/[\x00-\x1f\x7f]/',$requestKey)
        ||preg_match('/\A[a-f0-9]{64}\z/D',$requestHash)!==1)throw new RuntimeException('A valid music request identity is required.',422);
    $key=hash('sha256',$requestKey);$meta=vp3_music_chat_meta_v1($meta);
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$agent,$cid,$scope,$key,$requestHash,$items,$meta):array{
        $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid,true);
        $q=$pdo->prepare('SELECT * FROM music_chat_results_v1 WHERE owner_user_id=? AND conversation_id=? AND request_key=?');
        $q->execute([$scope['owner_user_id'],$cid,$key]);$existing=$q->fetch(PDO::FETCH_ASSOC);
        if($existing){
            if((int)$existing['agent_id']!==$agent||!hash_equals($existing['request_hash'],$requestHash))throw new RuntimeException('Music request identity was reused for a different request.',409);
            if((int)$existing['expires_at']<=time())throw new RuntimeException('This music request expired. Send a new request.',410);
            return vp3_music_chat_result_projection_v1($pdo,$scope,$existing,true);
        }
        $normalized=vp3_music_chat_items_normalize_v1($pdo,$scope,$items);
        foreach($normalized as $item)if($meta['source']!=='both'&&$meta['source']!==$item['source'])throw new RuntimeException('The selection does not match the requested music source.',422);
        if(count($normalized)>$meta['requested_count'])throw new RuntimeException('The music selection exceeds the requested number of songs.',422);
        if($meta['existing_playlist_id']!==null){
            $saved=vp3_music_chat_playlist_row_v1($pdo,$scope,$meta['existing_playlist_id'],true);
            if((int)$saved['revision']!==$meta['expected_playlist_revision'])throw new RuntimeException('Playlist changed. Open it again before playing.',409);
            $refs=vp3_music_chat_json_v1($saved['items_json']);
            if(count($refs)!==count($normalized))throw new RuntimeException('Playlist selection changed.',409);
            foreach($refs as $index=>$ref){
                $item=$normalized[$index];
                if($ref['source']!==$item['source']||($ref['source']==='vp3'
                    ?$ref['source_id']!==$item['source_id']:($ref['source_key']??'')!==hash('sha256',$item['source_id'])))throw new RuntimeException('Playlist selection changed.',409);
                $normalized[$index]['item_id']=$ref['item_id'];
            }
        }
        if($meta['target_playlist_id']!==null){
            $target=vp3_music_chat_playlist_row_v1($pdo,$scope,$meta['target_playlist_id'],true);
            if((int)$target['revision']!==$meta['expected_target_revision'])throw new RuntimeException('Playlist changed before adding the song. Open it again.',409);
            if(count($normalized)!==1)throw new RuntimeException('Choose one song to add to the playlist.',422);
        }
        $id=bin2hex(random_bytes(16));$now=time();$expires=$now+VP3_MUSIC_CHAT_RESULT_TTL_V1;
        foreach($normalized as $item)if($item['source']==='youtube')$expires=min($expires,$item['expires_at']);
        $encoded=json_encode($normalized,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $metaJson=json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $revision=hash('sha256',$id.'|'.$requestHash.'|'.$encoded.'|'.$metaJson);
        $pdo->prepare('INSERT INTO music_chat_results_v1(result_id,owner_user_id,agent_id,conversation_id,request_key,request_hash,revision,items_json,meta_json,created_at,expires_at,playlist_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,$scope['owner_user_id'],$agent,$cid,$key,$requestHash,$revision,$encoded,$metaJson,$now,$expires,$meta['existing_playlist_id']]);
        vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
        return vp3_music_chat_result_projection_v1($pdo,$scope,vp3_music_chat_result_row_v1($pdo,$scope,$id));
    });
}

/** Replay the exact request before source search or quota consumption. */
function vp3_music_chat_result_request_v1(PDO $pdo,array $user,int $agent,int $cid,string $requestKey,string $requestHash): ?array
{
    $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
    if($requestKey===''||strlen($requestKey)>256||preg_match('/[\x00-\x1f\x7f]/',$requestKey)
        ||preg_match('/\A[a-f0-9]{64}\z/D',$requestHash)!==1)throw new RuntimeException('A valid music request identity is required.',422);
    $q=$pdo->prepare('SELECT * FROM music_chat_results_v1 WHERE owner_user_id=? AND conversation_id=? AND request_key=?');
    $q->execute([$scope['owner_user_id'],$cid,hash('sha256',$requestKey)]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    if((int)$row['agent_id']!==$agent||!hash_equals($row['request_hash'],$requestHash))throw new RuntimeException('Music request identity was reused for a different request.',409);
    if((int)$row['expires_at']<=time())throw new RuntimeException('This music request expired. Send a new request.',410);
    $out=vp3_music_chat_result_projection_v1($pdo,$scope,$row,true);
    vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
    return $out;
}

/** Bind an expiring result to the actual saved assistant turn before announcing a saved playlist. */
function vp3_music_chat_result_bind_v1(PDO $pdo,array $user,int $agent,int $cid,string $id,int $messageId): array
{
    if($messageId<1)throw new RuntimeException('A saved Chat reply is required.',422);
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$agent,$cid,$id,$messageId):array{
        $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid,true);
        $row=vp3_music_chat_result_row_v1($pdo,$scope,$id,true);
        if((int)$row['assistant_message_id']>0)return vp3_music_chat_result_projection_v1($pdo,$scope,$row,true);
        $q=$pdo->prepare("SELECT id FROM chat_messages WHERE id=? AND conversation_id=? AND role='assistant'");
        $q->execute([$messageId,$cid]);
        if(!$q->fetchColumn())throw new RuntimeException('Music reply binding changed.',409);
        $items=vp3_music_chat_json_v1($row['items_json']);$meta=vp3_music_chat_json_v1($row['meta_json']);$videos=[];$expires=(int)$row['expires_at'];
        if(($meta['existing_playlist_id']??null)!==null){
            $saved=vp3_music_chat_playlist_row_v1($pdo,$scope,$meta['existing_playlist_id'],true);
            if((int)$saved['revision']!==$meta['expected_playlist_revision'])throw new RuntimeException('Playlist changed before playback. Open it again.',409);
        }
        if(($meta['target_playlist_id']??null)!==null){
            $target=vp3_music_chat_playlist_row_v1($pdo,$scope,$meta['target_playlist_id'],true);
            if((int)$target['revision']!==$meta['expected_target_revision'])throw new RuntimeException('Playlist changed before adding the song. Open it again.',409);
        }
        foreach($items as $item){
            if(!vp3_music_chat_result_item_v1($pdo,$scope,$item)['available'])throw new RuntimeException('A selected song changed before saving. Search again.',409);
            if($item['source']==='youtube'){$videos[]=$item['youtube_video'];$expires=min($expires,$item['expires_at']);}
        }
        if($videos){
            if(!agent_connection_youtube_results_ready_v1($pdo))throw new RuntimeException('YouTube storage needs the application upgrade.',503);
            $payload=['query'=>$meta['query']?:$meta['title'],'owner_topic'=>$meta['title']?:$meta['query'],
                'suggestion_kind'=>$meta['create_playlist']?'playlist':'music','music_suggestion'=>true,
                'expires_at'=>$expires,'videos'=>$videos];
            $q=$pdo->prepare('SELECT payload_json FROM agent_connection_youtube_results_v1 WHERE message_id=?');$q->execute([$messageId]);
            $prior=$q->fetchColumn();
            if($prior!==false)throw new RuntimeException('This reply already contains another YouTube result.',409);
            agent_connection_youtube_results_save_v1($pdo,$scope['owner_user_id'],$cid,$messageId,$payload);
            $q->execute([$messageId]);
            if($q->fetchColumn()===false)throw new RuntimeException('YouTube result could not be saved.',503);
        }
        $pdo->prepare('UPDATE music_chat_results_v1 SET assistant_message_id=? WHERE result_id=? AND assistant_message_id IS NULL')->execute([$messageId,$id]);
        $row['assistant_message_id']=$messageId;
        if($meta['create_playlist']&&$items){
            $title=$meta['title']?:($meta['query']?:'My music playlist');
            vp3_music_chat_playlist_create_locked_v1($pdo,$scope,$row,$title,'chat:'.$row['result_id']);
            $row=vp3_music_chat_result_row_v1($pdo,$scope,$id,true);
        }
        if(($meta['target_playlist_id']??null)!==null){
            // Binding, the exact selected source receipt and the native list edit
            // commit together. A replay sees the already-bound result and cannot
            // add twice, even if the playlist has changed since this request.
            $updated=vp3_music_chat_playlist_add_v1($pdo,$user,$agent,$cid,$meta['target_playlist_id'],$meta['expected_target_revision'],$id,0);
            $changed=$updated['playlist']['revision']>$meta['expected_target_revision'];
            $pdo->prepare('UPDATE music_chat_results_v1 SET playlist_id=?,playlist_changed=? WHERE result_id=?')->execute([$meta['target_playlist_id'],(int)$changed,$id]);
            $row=vp3_music_chat_result_row_v1($pdo,$scope,$id,true);
        }
        $out=vp3_music_chat_result_projection_v1($pdo,$scope,$row);
        if($meta['autoplay']&&$items){
            $action=bin2hex(random_bytes(16));$until=time()+300;$selection=min($meta['selection_index']??0,count($items)-1);
            $pdo->prepare('INSERT INTO music_chat_play_actions_v1(action_id,owner_user_id,result_id,item_id,playlist_revision,expires_at) VALUES(?,?,?,?,?,?)')
                ->execute([$action,$scope['owner_user_id'],$id,$items[$selection]['item_id'],$out['playlist']['revision']??null,$until]);
            $out['autoplay']=['action_id'=>$action,'index'=>$selection,'expires_at'=>$until];
        }
        vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
        return $out;
    });
}

function vp3_music_chat_result_read_v1(PDO $pdo,array $user,int $agent,int $cid,string $id): array
{
    $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
    $out=vp3_music_chat_result_projection_v1($pdo,$scope,vp3_music_chat_result_row_v1($pdo,$scope,$id));
    vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
    return $out;
}

function vp3_music_chat_result_latest_v1(PDO $pdo,array $user,int $agent,int $cid): ?array
{
    $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
    $q=$pdo->prepare("SELECT id,context_json FROM chat_messages WHERE conversation_id=? AND role='assistant' ORDER BY id DESC LIMIT 1");
    $q->execute([$cid]);$message=$q->fetch(PDO::FETCH_ASSOC);
    if(!$message)return null;
    $context=json_decode((string)($message['context_json']??''),true);
    $reference=is_array($context)?($context['music_chat_v1']['result_id']??$context['music_chat_followup_v1']['result_id']??null):null;
    $query='SELECT * FROM music_chat_results_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=? AND assistant_message_id IS NOT NULL AND ';
    $q=$pdo->prepare($query.(is_string($reference)?'result_id=?':'assistant_message_id=?'));
    $q->execute([$scope['owner_user_id'],$agent,$cid,is_string($reference)?$reference:(int)$message['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if($row&&(int)$row['expires_at']<=time()){
        $out=$row['playlist_id']!==null?vp3_music_chat_playlist_get_v1($pdo,$user,$agent,$cid,$row['playlist_id'])
            +['result_id'=>$row['result_id'],'source_message_id'=>(int)$row['assistant_message_id'],'bound'=>true]:null;
    }else $out=$row?vp3_music_chat_result_projection_v1($pdo,$scope,$row):null;
    vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
    return $out;
}

/** Internal selected references; no new ranking and no client-supplied source metadata. */
function vp3_music_chat_result_items_v1(PDO $pdo,array $user,int $agent,int $cid,string $id): array
{
    $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);$row=vp3_music_chat_result_row_v1($pdo,$scope,$id,false,true);$items=[];
    if((int)$row['assistant_message_id']<1)throw new RuntimeException('The music selection has not been saved to Chat.',409);
    if($row['playlist_id']!==null)return vp3_music_chat_playlist_items_v1($pdo,$user,$agent,$cid,$row['playlist_id']);
    foreach(vp3_music_chat_json_v1($row['items_json']) as $item){
        if(!vp3_music_chat_result_item_v1($pdo,$scope,$item)['available'])throw new RuntimeException('That selected song changed. Search again.',409);
        $items[]=$item;
    }
    return $items;
}

function vp3_music_chat_result_item_reference_v1(PDO $pdo,array $user,int $agent,int $cid,string $id,int $index): array
{
    $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);$row=vp3_music_chat_result_row_v1($pdo,$scope,$id,false,true);
    if($row['playlist_id']!==null)return vp3_music_chat_playlist_items_v1($pdo,$user,$agent,$cid,$row['playlist_id'],null,[$index])[0];
    $items=vp3_music_chat_json_v1($row['items_json']);
    if((int)$row['assistant_message_id']<1||$index<0||!isset($items[$index]))throw new RuntimeException('Choose a saved song result.',422);
    if(!vp3_music_chat_result_item_v1($pdo,$scope,$items[$index])['available'])throw new RuntimeException('That selected song changed. Search again.',409);
    vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
    return $items[$index];
}

/** Durable Chat stores only an opaque owner-scoped reference, not provider cache fields or autoplay. */
function vp3_music_chat_result_reference_v1(array $payload): array
{
    $reference=array_intersect_key($payload,array_flip(['protocol','version','owner_user_id','agent_id','conversation_id','result_id','revision']));
    if(is_array($payload['playlist']??null))$reference['playlist']=array_intersect_key($payload['playlist'],array_flip(['id','revision','visibility']));
    return $reference;
}

function vp3_music_chat_youtube_child_v1(PDO $pdo,array $scope,array $row): ?string
{
    $items=vp3_music_chat_json_v1($row['items_json']);
    if(!array_filter($items,static fn(array $i):bool=>$i['source']==='youtube'))return null;
    if(!vp3_music_chat_youtube_allowed_v1($pdo,$scope)||!vp3_youtube_playlists_ready_v1($pdo))throw new RuntimeException('YouTube playlists are unavailable.',503);
    if($row['youtube_playlist_id']!==null){
        if(!vp3_youtube_playlist_row_v1($pdo,$scope['owner_user_id'],$row['youtube_playlist_id']))throw new RuntimeException('Saved YouTube selections changed.',409);
        return $row['youtube_playlist_id'];
    }
    $q=$pdo->prepare('SELECT playlist_id FROM vp3_youtube_saved_playlists_v1 WHERE owner_user_id=? AND source_message_id=?');
    $q->execute([$scope['owner_user_id'],(int)$row['assistant_message_id']]);$existing=$q->fetchColumn();
    if($existing!==false)$child=(string)$existing;
    else{
        $q=$pdo->prepare('SELECT payload_json,expires_at FROM agent_connection_youtube_results_v1 WHERE message_id=? AND expires_at>?');
        $q->execute([(int)$row['assistant_message_id'],time()]);$source=$q->fetch(PDO::FETCH_ASSOC);
        if(!$source)throw new RuntimeException('YouTube result expired. Search again.',409);
        $payload=vp3_music_chat_json_v1($source['payload_json']);$videos=[];
        foreach($items as $item)if($item['source']==='youtube'){
            $video=vp3_music_chat_find_video_v1($payload,$item['source_id']);
            if(!$video)throw new RuntimeException('Saved YouTube selection changed.',409);
            $videos[]=$video;
        }
        $q=$pdo->prepare('SELECT COUNT(*) FROM vp3_youtube_saved_playlists_v1 WHERE owner_user_id=?');$q->execute([$scope['owner_user_id']]);
        if((int)$q->fetchColumn()>=VP3_YOUTUBE_PLAYLIST_LIMIT_V1)throw new RuntimeException('Your saved YouTube playlist limit has been reached.',409);
        $child=bin2hex(random_bytes(16));$meta=vp3_music_chat_json_v1($row['meta_json']);$until=(int)$source['expires_at'];
        $pdo->prepare('INSERT INTO vp3_youtube_saved_playlists_v1(owner_user_id,playlist_id,source_message_id,title,ordered_ids_json,videos_json,saved_at,cached_until,refresh_at,references_until) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([$scope['owner_user_id'],$child,(int)$row['assistant_message_id'],$meta['title']?:'My music playlist',
                json_encode(array_column($videos,'video_id'),JSON_THROW_ON_ERROR),json_encode($videos,JSON_THROW_ON_ERROR),time(),$until,$until+14*86400,$until+23*86400]);
    }
    $pdo->prepare('UPDATE music_chat_results_v1 SET youtube_playlist_id=? WHERE result_id=?')->execute([$child,$row['result_id']]);
    return $child;
}

function vp3_music_chat_playlist_refs_v1(array $items,?string $youtube): array
{
    return array_map(static fn(array $item):array=>$item['source']==='vp3'
        ?array_intersect_key($item,array_flip(['item_id','source','source_id']))
        :['item_id'=>$item['item_id'],'source'=>'youtube','source_key'=>hash('sha256',$item['source_id']),'youtube_playlist_id'=>$youtube],$items);
}

function vp3_music_chat_playlist_create_locked_v1(PDO $pdo,array $scope,array $result,string $title,string $requestKey): array
{
    if((int)$result['assistant_message_id']<1)throw new RuntimeException('The music selection has not been saved to Chat.',409);
    $title=vp3_music_chat_text_v1($title,200);
    if($title===''||$requestKey===''||strlen($requestKey)>256||preg_match('/[\x00-\x1f\x7f]/',$requestKey))throw new RuntimeException('Give the playlist a title and valid request identity.',422);
    $key=hash('sha256',$requestKey);
    $requestHash=hash('sha256',json_encode([$result['result_id'],$title],JSON_THROW_ON_ERROR));
    $q=$pdo->prepare('SELECT * FROM music_chat_playlists_v1 WHERE owner_user_id=? AND request_key=?');$q->execute([$scope['owner_user_id'],$key]);
    if($prior=$q->fetch(PDO::FETCH_ASSOC)){
        if($prior['source_result_id']!==$result['result_id']||!hash_equals((string)$prior['request_hash'],$requestHash)
            ||($result['playlist_id']!==null&&$result['playlist_id']!==$prior['playlist_id']))throw new RuntimeException('Playlist request identity changed.',409);
        return $prior;
    }
    if($result['playlist_id']!==null)throw new RuntimeException('This selection already has a saved playlist. Open the saved list.',409);
    $items=vp3_music_chat_json_v1($result['items_json']);
    if(!$items)throw new RuntimeException('Choose at least one playable song before creating a playlist.',422);
    $q=$pdo->prepare('SELECT COUNT(*) FROM music_chat_playlists_v1 WHERE owner_user_id=?');$q->execute([$scope['owner_user_id']]);
    if((int)$q->fetchColumn()>=VP3_MUSIC_CHAT_PLAYLIST_LIMIT_V1)throw new RuntimeException('Your private playlist limit has been reached. Remove an unused playlist before saving another.',409);
    foreach($items as $item)if(!vp3_music_chat_result_item_v1($pdo,$scope,$item)['available'])throw new RuntimeException('A selected song changed.',409);
    $youtube=vp3_music_chat_youtube_child_v1($pdo,$scope,$result);
    $refs=vp3_music_chat_playlist_refs_v1($items,$youtube);$id=bin2hex(random_bytes(16));$now=time();
    $pdo->prepare('INSERT INTO music_chat_playlists_v1(playlist_id,owner_user_id,agent_id,conversation_id,source_result_id,request_key,request_hash,title,revision,items_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id,$scope['owner_user_id'],$scope['agent_id'],$scope['conversation_id'],$result['result_id'],$key,$requestHash,$title,1,json_encode($refs,JSON_THROW_ON_ERROR),$now,$now]);
    $pdo->prepare('UPDATE music_chat_results_v1 SET playlist_id=? WHERE result_id=?')->execute([$id,$result['result_id']]);
    return vp3_music_chat_playlist_row_v1($pdo,$scope,$id,true);
}

function vp3_music_chat_playlist_create_v1(PDO $pdo,array $user,int $agent,int $cid,string $resultId,string $title,string $requestKey): array
{
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$agent,$cid,$resultId,$title,$requestKey):array{
        $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid,true);$result=vp3_music_chat_result_row_v1($pdo,$scope,$resultId,true);
        $row=vp3_music_chat_playlist_create_locked_v1($pdo,$scope,$result,$title,$requestKey);
        $out=vp3_music_chat_playlist_projection_v1($pdo,$scope,$row);
        vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
        return $out;
    });
}

function vp3_music_chat_playlist_row_v1(PDO $pdo,array $scope,string $id,bool $lock=false): array
{
    $q=$pdo->prepare('SELECT * FROM music_chat_playlists_v1 WHERE playlist_id=? AND owner_user_id=?'.($lock?agent_commerce_lock_pa2($pdo):''));
    $q->execute([vp3_music_chat_id_v1($id),$scope['owner_user_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('That private playlist is unavailable.',404);
    return $row;
}

function vp3_music_chat_playlist_summary_v1(array $row): array
{
    return ['id'=>$row['playlist_id'],'title'=>$row['title'],'revision'=>(int)$row['revision'],'visibility'=>'private',
        'item_count'=>count(vp3_music_chat_json_v1($row['items_json'])),'updated_at'=>(int)$row['updated_at']];
}

function vp3_music_chat_playlist_item_v1(PDO $pdo,array $scope,array $ref,array &$youtube,?callable $http=null): array
{
    if($ref['source']==='vp3'){
        $native=vp3_music_chat_native_item_v1($pdo,$scope,$ref['source_id']);
        return $native?vp3_music_chat_public_item_v1($native+['item_id'=>$ref['item_id']]):vp3_music_chat_unavailable_item_v1($ref,'track_unavailable');
    }
    if(!vp3_music_chat_youtube_allowed_v1($pdo,$scope)||empty($ref['youtube_playlist_id'])||empty($ref['source_key']))return vp3_music_chat_unavailable_item_v1($ref,'source_unavailable');
    $child=$ref['youtube_playlist_id'];
    if(!array_key_exists($child,$youtube)){
        try{$youtube[$child]=vp3_youtube_playlist_open_v1($pdo,$scope['owner_user_id'],$child,$http);}
        catch(AgentConnectionYoutubeExceptionV1){$youtube[$child]=null;}
    }
    foreach(($youtube[$child]['videos']??[]) as $video){
        $id=$video['video_id']??'';
        if(is_string($id)&&hash_equals($ref['source_key'],hash('sha256',$id))&&($video['made_for_kids']??null)===false){
            return ['item_id'=>$ref['item_id'],'source'=>'youtube','source_id'=>$id,'title'=>vp3_music_chat_text_v1($video['title']??'',200),
                'artist'=>vp3_music_chat_text_v1($video['channel_title']??'',120),'album'=>'','duration'=>'',
                'cover'=>$video['thumbnail_url']??'','available'=>true];
        }
    }
    return vp3_music_chat_unavailable_item_v1($ref,'provider_unavailable');
}

/** Complete remote refreshes before projecting metadata from any local source. */
function vp3_music_chat_playlist_sources_v1(PDO $pdo,array $scope,array $refs,?callable $http=null): array
{
    $youtube=[];
    foreach($refs as $ref){
        if($ref['source']!=='youtube'||empty($ref['youtube_playlist_id']))continue;
        $child=$ref['youtube_playlist_id'];
        if(array_key_exists($child,$youtube))continue;
        vp3_music_chat_playlist_item_v1($pdo,$scope,$ref,$youtube,$http);
        // A denied/expired source remains unavailable for this response even if
        // its settings change again; the projection must not start another wait.
        if(!array_key_exists($child,$youtube))$youtube[$child]=null;
    }
    return $youtube;
}

function vp3_music_chat_playlist_projection_v1(PDO $pdo,array $scope,array $row,?callable $http=null): array
{
    $refs=vp3_music_chat_json_v1($row['items_json']);$items=[];$unavailable=0;
    $youtube=vp3_music_chat_playlist_sources_v1($pdo,$scope,$refs,$http);
    $latest=vp3_music_chat_playlist_row_v1($pdo,$scope,$row['playlist_id']);
    if((int)$latest['revision']!==(int)$row['revision'])throw new RuntimeException('Playlist changed while loading. Open it again.',409);
    foreach($refs as $ref){
        $item=vp3_music_chat_playlist_item_v1($pdo,$scope,$ref,$youtube,$http);$items[]=$item;
        if(!$item['available'])++$unavailable;
    }
    $sources=array_values(array_unique(array_column($items,'source')));
    return ['protocol'=>VP3_MUSIC_CHAT_PROTOCOL_V1,'version'=>1]+vp3_music_chat_scope_fields_v1($scope)+[
        'revision'=>(int)$row['revision'],'intent'=>'playlist','source'=>count($sources)===1?$sources[0]:'both','requested_count'=>count($items),'items'=>$items,
        'playlist'=>vp3_music_chat_playlist_summary_v1($row),'coverage'=>['unavailable_count'=>$unavailable,'returned_count'=>count($items)]];
}

function vp3_music_chat_playlist_get_v1(PDO $pdo,array $user,int $agent,int $cid,string $id,?callable $http=null): array
{
    $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);$row=vp3_music_chat_playlist_row_v1($pdo,$scope,$id);
    $out=vp3_music_chat_playlist_projection_v1($pdo,$scope,$row,$http);
    vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
    return $out;
}

/** Case-normalized name, with an optional spoken "playlist" suffix; ambiguity never chooses a list. */
function vp3_music_chat_playlist_find_v1(PDO $pdo,array $user,int $agent,int $cid,string $title): ?array
{
    $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);$title=vp3_music_chat_text_v1($title,200);
    if($title==='')return null;
    $normalize=static fn(string $value):string=>trim(preg_replace('/\s+/u',' ',mb_strtolower($value))??'');
    $name=$normalize($title);$base=preg_replace('/\s+playlist\z/u','',$name)??$name;
    $names=array_unique([$name,$base,$base.' playlist']);
    // The owner has at most 100 private lists. Normalize in PHP so SQLite and
    // MySQL collations cannot disagree about names or hide an ambiguous pair.
    $q=$pdo->prepare('SELECT * FROM music_chat_playlists_v1 WHERE owner_user_id=? ORDER BY playlist_id LIMIT '.(VP3_MUSIC_CHAT_PLAYLIST_LIMIT_V1+1));
    $q->execute([$scope['owner_user_id']]);$rows=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $candidate){
        if(in_array($normalize($candidate['title']),$names,true))$rows[]=$candidate;
        if(count($rows)>1)break;
    }
    if(count($rows)>1)throw new RuntimeException('More than one private playlist has that name. Choose it from Saved playlists.',409);
    $out=$rows?vp3_music_chat_playlist_projection_v1($pdo,$scope,$rows[0]):null;
    vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
    return $out;
}

/** Trusted current source records for an explicit saved-playlist followup. */
function vp3_music_chat_playlist_items_v1(PDO $pdo,array $user,int $agent,int $cid,string $id,?callable $http=null,?array $indexes=null): array
{
    $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);$row=vp3_music_chat_playlist_row_v1($pdo,$scope,$id);$items=[];
    $refs=vp3_music_chat_json_v1($row['items_json']);
    if($indexes!==null){
        $selected=[];
        foreach($indexes as $index){if(!is_int($index)||$index<0||!isset($refs[$index]))throw new RuntimeException('Choose a saved song result.',422);$selected[]=$refs[$index];}
        $refs=$selected;
    }
    $youtube=vp3_music_chat_playlist_sources_v1($pdo,$scope,$refs,$http);
    $latest=vp3_music_chat_playlist_row_v1($pdo,$scope,$id);
    if((int)$latest['revision']!==(int)$row['revision'])throw new RuntimeException('Playlist changed while loading. Open it again.',409);
    foreach($refs as $ref){
        $item=vp3_music_chat_playlist_item_v1($pdo,$scope,$ref,$youtube,$http);
        if(!$item['available'])throw new RuntimeException('A song in that playlist is unavailable. Open Saved playlists to review it.',409);
        if($item['source']==='vp3')$item+=vp3_music_chat_native_item_v1($pdo,$scope,$item['source_id'])??[];
        else{
            $source=$youtube[$ref['youtube_playlist_id']];
            $item['youtube_video']=vp3_music_chat_find_video_v1($source,$item['source_id']);
            $item['expires_at']=(int)$source['expires_at'];
        }
        $items[]=$item;
    }
    vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
    return $items;
}

function vp3_music_chat_playlist_list_v1(PDO $pdo,array $user,int $agent,int $cid,int $limit=25,int $offset=0): array
{
    if($limit<1||$limit>100||$offset<0||$offset>1000000)throw new RuntimeException('Invalid playlist page.',422);
    $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
    $q=$pdo->prepare('SELECT COUNT(*) FROM music_chat_playlists_v1 WHERE owner_user_id=?');$q->execute([$scope['owner_user_id']]);$total=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT * FROM music_chat_playlists_v1 WHERE owner_user_id=? ORDER BY updated_at DESC,playlist_id DESC LIMIT '.$limit.' OFFSET '.$offset);$q->execute([$scope['owner_user_id']]);
    $out=vp3_music_chat_scope_fields_v1($scope)+['playlists'=>array_map('vp3_music_chat_playlist_summary_v1',$q->fetchAll(PDO::FETCH_ASSOC)),
        'total'=>$total,'limit'=>$limit,'offset'=>$offset];
    vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
    return $out;
}

function vp3_music_chat_playlist_update_v1(PDO $pdo,array $user,int $agent,int $cid,string $id,int $revision,?string $title=null,?array $order=null): array
{
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$agent,$cid,$id,$revision,$title,$order):array{
        $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid,true);$row=vp3_music_chat_playlist_row_v1($pdo,$scope,$id,true);
        if($revision<1||(int)$row['revision']!==$revision)throw new RuntimeException('Playlist changed. Refresh before editing.',409);
        $items=vp3_music_chat_json_v1($row['items_json']);$next=$items;$name=$row['title'];
        if($title!==null){$name=vp3_music_chat_text_v1($title,200);if($name==='')throw new RuntimeException('Give the playlist a title.',422);}
        if($order!==null){
            if(!array_is_list($order)||count($order)>VP3_MUSIC_CHAT_LIMIT_V1)throw new RuntimeException('Invalid playlist order.',422);
            $map=array_column($items,null,'item_id');$next=[];$seen=[];
            foreach($order as $itemId){
                if(!is_string($itemId)||!isset($map[$itemId])||isset($seen[$itemId]))throw new RuntimeException('Playlist order contains an unknown or duplicate song.',422);
                $next[]=$map[$itemId];$seen[$itemId]=true;
            }
        }
        if($next!==$items||$name!==$row['title']){
            $pdo->prepare('UPDATE music_chat_playlists_v1 SET title=?,items_json=?,revision=revision+1,updated_at=? WHERE playlist_id=? AND owner_user_id=? AND revision=?')
                ->execute([$name,json_encode($next,JSON_THROW_ON_ERROR),time(),$id,$scope['owner_user_id'],$revision]);
        }
        $out=vp3_music_chat_playlist_projection_v1($pdo,$scope,vp3_music_chat_playlist_row_v1($pdo,$scope,$id));
        vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
        return $out;
    });
}

function vp3_music_chat_playlist_add_v1(PDO $pdo,array $user,int $agent,int $cid,string $id,int $revision,string $resultId,int $index): array
{
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$agent,$cid,$id,$revision,$resultId,$index):array{
        $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid,true);$row=vp3_music_chat_playlist_row_v1($pdo,$scope,$id,true);
        if((int)$row['revision']!==$revision)throw new RuntimeException('Playlist changed. Refresh before editing.',409);
        $result=vp3_music_chat_result_row_v1($pdo,$scope,$resultId,true);$selected=vp3_music_chat_json_v1($result['items_json']);
        if($result['playlist_id']!==null)throw new RuntimeException('Choose a standalone song result to add to a playlist.',409);
        if((int)$result['assistant_message_id']<1||$index<0||!isset($selected[$index]))throw new RuntimeException('Choose a saved song result.',422);
        $item=$selected[$index];
        if(!vp3_music_chat_result_item_v1($pdo,$scope,$item)['available'])throw new RuntimeException('Selected song is unavailable.',409);
        $refs=vp3_music_chat_json_v1($row['items_json']);
        foreach($refs as $ref)if($ref['source']===$item['source']&&($item['source']==='vp3'
            ?($ref['source_id']??null)===$item['source_id']:($ref['source_key']??null)===hash('sha256',$item['source_id']))){
                $out=vp3_music_chat_playlist_projection_v1($pdo,$scope,$row);vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);return $out;
            }
        if(count($refs)>=VP3_MUSIC_CHAT_LIMIT_V1)throw new RuntimeException('A playlist can contain up to 25 songs.',422);
        $youtube=$item['source']==='youtube'?vp3_music_chat_youtube_child_v1($pdo,$scope,$result):null;
        $item['item_id']=bin2hex(random_bytes(16));$refs[]=vp3_music_chat_playlist_refs_v1([$item],$youtube)[0];
        $pdo->prepare('UPDATE music_chat_playlists_v1 SET items_json=?,revision=revision+1,updated_at=? WHERE playlist_id=? AND owner_user_id=? AND revision=?')
            ->execute([json_encode($refs,JSON_THROW_ON_ERROR),time(),$id,$scope['owner_user_id'],$revision]);
        $out=vp3_music_chat_playlist_projection_v1($pdo,$scope,vp3_music_chat_playlist_row_v1($pdo,$scope,$id));
        vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
        return $out;
    });
}

function vp3_music_chat_playlist_remove_v1(PDO $pdo,array $user,int $agent,int $cid,string $id,int $revision): void
{
    agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$agent,$cid,$id,$revision):void{
        $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid,true);$row=vp3_music_chat_playlist_row_v1($pdo,$scope,$id,true);
        if((int)$row['revision']!==$revision)throw new RuntimeException('Playlist changed. Refresh before deleting.',409);
        $pdo->prepare('DELETE FROM music_chat_playlists_v1 WHERE playlist_id=? AND owner_user_id=? AND revision=?')->execute([$id,$scope['owner_user_id'],$revision]);
    });
}

/** Resolve stored identities through the existing adapters. This never records a listen. */
function vp3_music_chat_resolve_v1(PDO $pdo,array $user,int $agent,int $cid,array $input,?callable $http=null): array
{
    $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);$index=$input['index']??null;
    if(!is_int($index)||$index<0||$index>=VP3_MUSIC_CHAT_LIMIT_V1)throw new RuntimeException('Choose a song from the selection.',422);
    $hasResult=is_string($input['result_id']??null);$hasPlaylist=is_string($input['playlist_id']??null);
    if($hasResult===$hasPlaylist)throw new RuntimeException('Choose one saved selection.',422);
    $result=null;$playlist=null;$youtubeChild=null;$messageId=0;
    if($hasResult){
        $result=vp3_music_chat_result_row_v1($pdo,$scope,$input['result_id']);
        if($result['playlist_id']!==null)throw new RuntimeException('Use the current saved playlist to play this selection.',409);
        if(!is_string($input['revision']??null)||!hash_equals($result['revision'],$input['revision'])||(int)$result['assistant_message_id']<1)throw new RuntimeException('Music selection changed.',409);
        $stored=vp3_music_chat_json_v1($result['items_json']);
        if(!isset($stored[$index]))throw new RuntimeException('Choose a song from the selection.',422);
        $ref=$stored[$index];$item=vp3_music_chat_result_item_v1($pdo,$scope,$ref);$messageId=(int)$result['assistant_message_id'];
    }else{
        $playlist=vp3_music_chat_playlist_row_v1($pdo,$scope,$input['playlist_id']);
        if(!is_int($input['revision']??null)||(int)$playlist['revision']!==$input['revision'])throw new RuntimeException('Playlist changed. Refresh before playing.',409);
        $stored=vp3_music_chat_json_v1($playlist['items_json']);
        if(!isset($stored[$index]))throw new RuntimeException('Choose a song from the playlist.',422);
        $ref=$stored[$index];$cache=[];$item=vp3_music_chat_playlist_item_v1($pdo,$scope,$ref,$cache,$http);
        $youtubeChild=$ref['youtube_playlist_id']??null;
    }
    if(!$item['available'])throw new RuntimeException('This song is unavailable. Refresh the selection or choose another.',409);
    $out=vp3_music_chat_scope_fields_v1($scope)+['item'=>$item];
    if($item['source']==='vp3'){
        $native=vp3_music_chat_native_item_v1($pdo,$scope,$item['source_id'],$ref['native_revision']??null);
        if(!$native)throw new RuntimeException('VP3 song changed before playback.',409);
        $out['media_playback']=vp3_media_playback_action_v1($scope['user'],$agent,$cid,'play',[
            'track_id'=>(int)$native['source_id'],'revision'=>$native['native_revision']]);
    }else{
        if($youtubeChild!==null){
            $source=vp3_youtube_playlist_open_v1($pdo,$scope['owner_user_id'],$youtubeChild,$http);
            $child=vp3_youtube_playlist_row_v1($pdo,$scope['owner_user_id'],$youtubeChild);
            $messageId=(int)($child['source_message_id']??0);
            $video=$source?vp3_music_chat_find_video_v1($source,$item['source_id']):null;
            $expires=(int)($source['expires_at']??0);
        }else{
            $q=$pdo->prepare('SELECT payload_json,expires_at FROM agent_connection_youtube_results_v1 WHERE message_id=? AND expires_at>?');$q->execute([$messageId,time()]);$row=$q->fetch(PDO::FETCH_ASSOC);
            $source=$row?vp3_music_chat_json_v1($row['payload_json']):null;
            $video=$source?vp3_music_chat_find_video_v1($source,$item['source_id']):null;$expires=(int)($row['expires_at']??0);
        }
        if(!$video||!is_bool($video['made_for_kids']??null)||$expires<=time())throw new RuntimeException('YouTube selection expired. Refresh the playlist or search again.',409);
        $out['youtube_results']=['query'=>$item['title'],'expires_at'=>$expires,'videos'=>[$video],'music_suggestion'=>true];
        $out['source_message_id']=$messageId;
        if($youtubeChild!==null)$out['youtube_playlist_id']=$youtubeChild;
    }
    $automatic=$input['auto_action_id']??null;
    if($automatic!==null){
        $automatic=vp3_music_chat_id_v1($automatic);
        $claimed=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$agent,$cid,$scope,$automatic,$result,$playlist,$item):bool{
            vp3_music_chat_scope_v1($pdo,$user,$agent,$cid,true);
            if($playlist!==null){
                $latest=vp3_music_chat_playlist_row_v1($pdo,$scope,$playlist['playlist_id'],true);
                if((int)$latest['revision']!==(int)$playlist['revision'])throw new RuntimeException('Playlist changed before playback. Refresh it.',409);
            }
            if($item['source']==='vp3'&&!vp3_music_chat_native_item_v1($pdo,$scope,$item['source_id']))throw new RuntimeException('VP3 song changed before playback.',409);
            if($item['source']==='youtube'&&!vp3_music_chat_youtube_allowed_v1($pdo,$scope))throw new RuntimeException('YouTube access changed.',403);
            $q=$pdo->prepare('SELECT a.*,r.agent_id,r.conversation_id,r.playlist_id FROM music_chat_play_actions_v1 a JOIN music_chat_results_v1 r ON r.result_id=a.result_id WHERE a.action_id=? AND a.owner_user_id=?'.agent_commerce_lock_pa2($pdo));
            $q->execute([$automatic,$scope['owner_user_id']]);$action=$q->fetch(PDO::FETCH_ASSOC);
            if(!$action||(int)$action['agent_id']!==$agent||(int)$action['conversation_id']!==$cid
                ||$action['item_id']!==$item['item_id']||($result!==null?$action['result_id']!==$result['result_id']:$action['playlist_id']!==$playlist['playlist_id']))throw new RuntimeException('Playback request does not match this selection.',409);
            if($action['claimed_at']!==null)return false;
            if($action['playlist_revision']!==null){
                $latest=vp3_music_chat_playlist_row_v1($pdo,$scope,$action['playlist_id'],true);
                if((int)$latest['revision']!==(int)$action['playlist_revision'])throw new RuntimeException('Playlist changed before automatic playback. Use Play to start.',409);
            }
            if((int)$action['expires_at']<=time())throw new RuntimeException('Automatic playback expired. Use Play to start.',409);
            $q=$pdo->prepare('UPDATE music_chat_play_actions_v1 SET claimed_at=? WHERE action_id=? AND claimed_at IS NULL');$q->execute([time(),$automatic]);
            return $q->rowCount()===1;
        });
        if(!$claimed)return vp3_music_chat_scope_fields_v1($scope)+['already_claimed'=>true];
    }
    // Provider refresh can outlive a concurrent edit/removal. Recheck the
    // exact saved version before returning any manual or automatic playback.
    if($playlist!==null){
        $latest=vp3_music_chat_playlist_row_v1($pdo,$scope,$playlist['playlist_id']);
        if((int)$latest['revision']!==(int)$playlist['revision'])throw new RuntimeException('Playlist changed before playback. Refresh it.',409);
    }elseif($result!==null){
        $latest=vp3_music_chat_result_row_v1($pdo,$scope,$result['result_id']);
        if(!hash_equals($latest['revision'],$result['revision'])||(int)$latest['assistant_message_id']!==(int)$result['assistant_message_id'])throw new RuntimeException('Music selection changed before playback.',409);
    }
    if($item['source']==='youtube'&&!vp3_music_chat_youtube_allowed_v1($pdo,$scope))throw new RuntimeException('YouTube access changed.',403);
    vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
    return $out;
}

/** Worker-only bounded expiry; loading or viewing a report does not migrate or delete data. */
function vp3_music_chat_cleanup_v1(PDO $pdo,int $limit=100): int
{
    if(!vp3_music_chat_ready_v1($pdo))return 0;$limit=max(1,min(1000,$limit));$now=time();$changed=0;
    $q=$pdo->prepare("SELECT result_id FROM music_chat_results_v1 WHERE expires_at<=? AND (items_json<>'[]' OR meta_json<>'{}') ORDER BY expires_at LIMIT ".$limit);$q->execute([$now]);
    // Retain an opaque idempotency tombstone: an old request can never create another playlist.
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
        $pdo->prepare("UPDATE music_chat_results_v1 SET items_json='[]',meta_json='{}' WHERE result_id=? AND expires_at<=?")->execute([$id,$now]);++$changed;
    }
    $pdo->prepare('DELETE FROM music_chat_play_actions_v1 WHERE expires_at<=?')->execute([$now]);
    return $changed;
}
