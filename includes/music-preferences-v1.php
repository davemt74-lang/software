<?php
declare(strict_types=1);

require_once __DIR__.'/music-history-v1.php';

const VP3_MUSIC_PREFERENCES_PROTOCOL_V1='vp3_music_preferences_v1';
const VP3_MUSIC_PREFERENCES_HISTORY_LIMIT_V1=200;
const VP3_MUSIC_PREFERENCES_WINDOW_DAYS_V1=30;

/** Explicit owner choices and deliberate Next receipts; never another listening ledger. */
function vp3_music_preferences_schema_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Run the music preferences upgrade outside a transaction.');
    $sqlite=agent_commerce_sqlite_pa2($pdo);$text=$sqlite?'TEXT':'LONGTEXT';
    $tail=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS music_preferences_v1 (
        preference_id CHAR(32) NOT NULL PRIMARY KEY,owner_user_id INT UNSIGNED NOT NULL,
        source VARCHAR(8) NOT NULL,source_key CHAR(64) NOT NULL,native_track_id BIGINT NULL,
        preference VARCHAR(8) NOT NULL,revision BIGINT NOT NULL,origin_json {$text} NOT NULL,
        consent_at BIGINT NOT NULL,created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL,
        UNIQUE(owner_user_id,source,source_key),
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ){$tail}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS music_preference_requests_v1 (
        owner_user_id INT UNSIGNED NOT NULL,request_key CHAR(64) NOT NULL,
        agent_id BIGINT UNSIGNED NOT NULL,conversation_id BIGINT UNSIGNED NOT NULL,
        request_hash CHAR(64) NOT NULL,chat_hash CHAR(64) NOT NULL DEFAULT '',preference_id CHAR(32) NULL,operation VARCHAR(8) NOT NULL,
        created_at BIGINT NOT NULL,PRIMARY KEY(owner_user_id,request_key),
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ){$tail}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS music_deliberate_skips_v1 (
        owner_user_id INT UNSIGNED NOT NULL,receipt_kind CHAR(1) NOT NULL,receipt_id VARCHAR(40) NOT NULL,
        source VARCHAR(8) NOT NULL,source_key CHAR(64) NOT NULL,
        agent_id BIGINT UNSIGNED NOT NULL,conversation_id BIGINT UNSIGNED NOT NULL,
        consent_at BIGINT NOT NULL,created_at BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,receipt_kind,receipt_id),
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ){$tail}");
}

function vp3_music_preferences_ready_v1(PDO $pdo): bool
{
    return vp3_music_history_columns_v1($pdo,'music_preferences_v1','preference_id,owner_user_id,source,source_key,native_track_id,preference,revision,origin_json,consent_at,created_at,updated_at')
        &&vp3_music_history_columns_v1($pdo,'music_preference_requests_v1','owner_user_id,request_key,agent_id,conversation_id,request_hash,chat_hash,preference_id,operation,created_at')
        &&vp3_music_history_columns_v1($pdo,'music_deliberate_skips_v1','owner_user_id,receipt_kind,receipt_id,source,source_key,agent_id,conversation_id,consent_at,created_at');
}

function vp3_music_preferences_scope_v1(PDO $pdo,array $user,int $agent,int $cid=0): array
{
    if(function_exists('db')&&db()!==$pdo)throw new RuntimeException('Music preferences account changed.',403);
    if($cid>0&&function_exists('current_user')){
        $viewer=current_user();
        if(!$viewer||(int)($viewer['id']??0)!==(int)($user['id']??0))throw new RuntimeException('Music preferences account changed.',403);
    }
    $scope=vp3_agent_activity_scope_v1($pdo,$user,$agent,$cid);
    if(!vp3_media_playback_history_allowed_v1($pdo,$scope['user'],$scope['principal'])){
        throw new RuntimeException('This Agent cannot use your music preferences or listening history.',403);
    }
    return $scope+['owner_user_id'=>(int)$scope['user']['id']];
}

function vp3_music_preferences_require_v1(PDO $pdo): void
{
    if(!vp3_music_preferences_ready_v1($pdo))throw new RuntimeException('Run Database Upgrade to enable music preferences.',503);
}

function vp3_music_preferences_fields_v1(array $value,array $fields): void
{
    if(array_diff(array_keys($value),$fields))throw new RuntimeException('Unknown music preference field.',422);
}

function vp3_music_preferences_source_key_v1(string $source,string $id): string
{
    if(($source==='vp3'&&preg_match('/\A[1-9][0-9]{0,15}\z/D',$id)===1)
        ||($source==='youtube'&&preg_match('/\A[A-Za-z0-9_-]{11}\z/D',$id)===1))return hash('sha256',$id);
    throw new RuntimeException('Choose an exact VP3 or YouTube item.',422);
}

function vp3_music_preferences_reference_v1(array $row): string{return 'mp1.'.$row['preference_id'];}

function vp3_music_preferences_row_v1(PDO $pdo,array $scope,string $reference): array
{
    if(!preg_match('/\Amp1\.([a-f0-9]{32})\z/D',$reference,$match))throw new RuntimeException('Choose a saved music preference.',422);
    $q=$pdo->prepare('SELECT * FROM music_preferences_v1 WHERE preference_id=? AND owner_user_id=?');
    $q->execute([$match[1],$scope['owner_user_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('This music preference is unavailable.',404);
    return $row;
}

/** Current cached source metadata only. No refresh, provider request, copied title, or preference write. */
function vp3_music_preferences_youtube_v1(PDO $pdo,array $scope,array $sources,string $key,?int $message=null,?string $child=null): ?array
{
    if(!$sources['youtube'])return null;
    $columns='m.id AS message_id,m.role AS message_role,m.user_id AS message_user_id,m.context_json,c.id AS conversation_id,c.user_id AS conversation_owner,c.user_agent_id AS source_agent_id';
    $queries=[];$now=time();
    if($sources['ordinary']&&$child===null){
        $queries[]=['SELECT r.payload_json,r.expires_at,'.$columns.' FROM agent_connection_youtube_results_v1 r
            JOIN chat_messages m ON m.id=r.message_id JOIN chat_conversations c ON c.id=m.conversation_id
            WHERE c.user_id=? AND r.expires_at>?'.($message===null?'':' AND m.id=?').' ORDER BY m.id DESC',
            [$scope['owner_user_id'],$now,...($message===null?[]:[$message])],false];
    }
    if($sources['playlist']&&$message===null){
        $queries[]=['SELECT l.videos_json AS payload_json,l.cached_until AS expires_at,'.$columns.' FROM vp3_youtube_saved_playlists_v1 l
            JOIN chat_messages m ON m.id=l.source_message_id JOIN chat_conversations c ON c.id=m.conversation_id
            WHERE l.owner_user_id=? AND l.cached_until>?'.($child===null?'':' AND l.playlist_id=?').' ORDER BY l.saved_at DESC',
            [$scope['owner_user_id'],$now,...($child===null?[]:[$child])],true];
    }
    foreach($queries as [$sql,$args,$playlist]){
        $q=$pdo->prepare($sql);$q->execute($args);
        while($row=$q->fetch(PDO::FETCH_ASSOC)){
            if(!vp3_music_history_youtube_context_v1($pdo,$scope,$row))continue;
            $payload=vp3_music_chat_json_v1($row['payload_json']);
            if(!$playlist&&isset($payload['expires_at'])&&(int)$payload['expires_at']<=$now)continue;
            foreach(($playlist?$payload:($payload['videos']??[])) as $raw){
                $id=is_array($raw)?($raw['video_id']??null):null;
                if(!is_string($id)||!hash_equals($key,hash('sha256',$id)))continue;
                try{$video=vp3_music_chat_video_v1($raw,$id);}catch(RuntimeException){continue;}
                return ['source'=>'youtube','source_id'=>$id,'title'=>$video['title'],'artist'=>$video['channel_title'],
                    'metadata_available'=>true,'expires_at'=>(int)$row['expires_at']];
            }
        }
    }
    return null;
}

function vp3_music_preferences_metadata_v1(PDO $pdo,array $scope,array $sources,string $source,string $key,?int $native): ?array
{
    if($source==='youtube')return vp3_music_preferences_youtube_v1($pdo,$scope,$sources,$key);
    if($source!=='vp3'||$native===null||!hash_equals($key,hash('sha256',(string)$native)))return null;
    $track=music_media_track($native,$scope['user']);
    if(!$track||!can_view_track($track,$scope['user']))return null;
    $details=vp3_media_track_details_v1($pdo,$track);
    return ['source'=>'vp3','source_id'=>(string)$native,'title'=>$details['title'],'artist'=>$details['artist'],'metadata_available'=>true];
}

/** Resolve an exact currently displayed identity, without resolving or starting playback. */
function vp3_music_preferences_selection_v1(PDO $pdo,array $user,int $agent,int $cid,array $selection): array
{
    if($cid<1)throw new RuntimeException('Open your private Agent Chat.',403);
    $scope=vp3_music_preferences_scope_v1($pdo,$user,$agent,$cid);vp3_music_preferences_require_v1($pdo);
    $sources=vp3_music_history_sources_v1($pdo,$scope);$kind=$selection['kind']??null;
    if($kind==='preference'){
        vp3_music_preferences_fields_v1($selection,['kind','reference']);
        if(!is_string($selection['reference']??null))throw new RuntimeException('Choose a saved music preference.',422);
        $row=vp3_music_preferences_row_v1($pdo,$scope,$selection['reference']);
        $item=($row['source']!=='youtube'||(int)$row['consent_at']===$sources['consent_at'])
            ?vp3_music_preferences_metadata_v1($pdo,$scope,$sources,$row['source'],$row['source_key'],$row['native_track_id']===null?null:(int)$row['native_track_id']):null;
        return ['scope'=>$scope,'sources'=>$sources,'selection'=>$selection,'row'=>$row,'item'=>$item,
            'source'=>$row['source'],'source_key'=>$row['source_key'],'native_track_id'=>$row['native_track_id']===null?null:(int)$row['native_track_id']];
    }
    if($kind==='history'){
        vp3_music_preferences_fields_v1($selection,['kind','source_message_id','revision','entry_ref']);
        if(!is_int($selection['source_message_id']??null)||!is_string($selection['revision']??null)||!is_string($selection['entry_ref']??null))throw new RuntimeException('Choose an exact history entry.',422);
        require_once __DIR__.'/music-history-chat-v1.php';
        $snapshot=vp3_music_history_chat_context_v1($pdo,$user,$agent,$cid,$selection['source_message_id'],$selection['revision']);
        if(!in_array($selection['entry_ref'],$snapshot['entry_refs'],true))throw new RuntimeException('Choose an entry from the displayed history page.',409);
        $item=vp3_music_history_entry_v1($pdo,$user,$agent,$cid,$selection['entry_ref']);
        if(empty($item['metadata_available']))throw new RuntimeException('This history item is unavailable.',409);
    }elseif($kind==='result'||$kind==='playlist'){
        vp3_music_preferences_fields_v1($selection,['kind',$kind.'_id','revision','index']);
        $index=$selection['index']??null;
        if(!is_int($index)||$index<0||$index>=VP3_MUSIC_CHAT_LIMIT_V1||!is_string($selection[$kind.'_id']??null))throw new RuntimeException('Choose an exact displayed music item.',422);
        $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid);
        if($kind==='result'){
            $result=vp3_music_chat_result_row_v1($pdo,$scope,$selection['result_id']);
            if($result['playlist_id']!==null||!is_string($selection['revision']??null)
                ||!hash_equals($result['revision'],$selection['revision'])||(int)$result['assistant_message_id']<1)throw new RuntimeException('The music selection changed. Refresh it.',409);
            vp3_music_chat_history_selection_v1($pdo,$scope,$result);
            $items=vp3_music_chat_json_v1($result['items_json']);$ref=$items[$index]??null;
            if(!is_array($ref))throw new RuntimeException('Choose an exact displayed music item.',422);
            if($ref['source']==='youtube')$item=vp3_music_preferences_youtube_v1($pdo,$scope,$sources,hash('sha256',$ref['source_id']),(int)$result['assistant_message_id']);
            else{$item=vp3_music_chat_result_item_v1($pdo,$scope,$ref);if(empty($item['available']))$item=null;}
        }else{
            $playlist=vp3_music_chat_playlist_row_v1($pdo,$scope,$selection['playlist_id']);
            if(!is_int($selection['revision']??null)||(int)$playlist['revision']!==$selection['revision'])throw new RuntimeException('The private playlist changed. Refresh it.',409);
            $items=vp3_music_chat_json_v1($playlist['items_json']);$ref=$items[$index]??null;
            if(!is_array($ref))throw new RuntimeException('Choose an exact displayed playlist item.',422);
            $item=$ref['source']==='youtube'
                ?vp3_music_preferences_youtube_v1($pdo,$scope,$sources,(string)($ref['source_key']??''),null,(string)($ref['youtube_playlist_id']??''))
                :vp3_music_chat_native_item_v1($pdo,$scope,(string)$ref['source_id']);
        }
        if(!$item)throw new RuntimeException('This music item is unavailable. Refresh the source.',409);
    }else throw new RuntimeException('Choose one exact music selection.',422);
    $source=$item['source'];$id=(string)$item['source_id'];$key=vp3_music_preferences_source_key_v1($source,$id);
    $q=$pdo->prepare('SELECT * FROM music_preferences_v1 WHERE owner_user_id=? AND source=? AND source_key=?');
    $q->execute([$scope['owner_user_id'],$source,$key]);$row=$q->fetch(PDO::FETCH_ASSOC)?:null;
    return ['scope'=>$scope,'sources'=>$sources,'selection'=>$selection,'row'=>$row,'item'=>$item,
        'source'=>$source,'source_key'=>$key,'native_track_id'=>$source==='vp3'?(int)$id:null];
}

function vp3_music_preferences_state_v1(array $selected): array
{
    $row=$selected['row'];$item=$selected['item'];$preference=$row['preference']??'clear';
    if($selected['source']==='youtube'&&$row!==null&&(int)$row['consent_at']!==$selected['sources']['consent_at'])$preference='clear';
    $value=$preference==='clear'?'neutral':$preference;
    return ['protocol'=>VP3_MUSIC_PREFERENCES_PROTOCOL_V1]+vp3_music_chat_scope_fields_v1($selected['scope'])+[
        'reference'=>$row?vp3_music_preferences_reference_v1($row):null,'source'=>$selected['source'],'source_id'=>$item['source_id']??'',
        'title'=>$item['title']??'Unavailable music item','artist'=>$item['artist']??'','metadata_available'=>$item!==null,
        'preference'=>$value,'has_saved_preference'=>$row!==null&&$row['preference']!=='clear','revision'=>(int)($row['revision']??0),
        'reason'=>match($value){'like'=>'You explicitly liked this item.','dislike'=>'You explicitly disliked this item.',default=>'No explicit preference is saved for this item.'}];
}

function vp3_music_preferences_read_v1(PDO $pdo,array $user,int $agent,int $cid,array $selection): array
{
    $initial=vp3_music_preferences_selection_v1($pdo,$user,$agent,$cid,$selection);
    $selected=vp3_music_preferences_selection_v1($pdo,$user,$agent,$cid,$selection);
    $fresh=vp3_music_preferences_scope_v1($pdo,$user,$agent,$cid);
    if($initial['sources']!==$selected['sources']||vp3_music_history_sources_v1($pdo,$fresh)!==$selected['sources'])throw new RuntimeException('Music source settings changed. Refresh this choice.',409);
    return vp3_music_preferences_state_v1($selected);
}

function vp3_music_preferences_request_v1(string $request): string
{
    if(strlen($request)<8||strlen($request)>128||preg_match('/\A[A-Za-z0-9._:-]+\z/D',$request)!==1)throw new RuntimeException('Use a stable music preference request identity.',422);
    return hash('sha256',$request);
}

function vp3_music_preferences_request_hash_v1(int $agent,int $cid,string $operation,array $data): string
{
    $sort=static function(array $value)use(&$sort):array{if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as &$item)if(is_array($item))$item=$sort($item);return $value;};
    return hash('sha256',json_encode([$agent,$cid,$operation,$sort($data)],JSON_THROW_ON_ERROR));
}

function vp3_music_preferences_prior_v1(PDO $pdo,array $scope,string $key,string $hash,?string $chatHash=null): ?array
{
    $q=$pdo->prepare('SELECT * FROM music_preference_requests_v1 WHERE owner_user_id=? AND request_key=?');$q->execute([$scope['owner_user_id'],$key]);
    $prior=$q->fetch(PDO::FETCH_ASSOC)?:null;
    if($prior&&(!hash_equals($prior['request_hash'],$hash)
        &&!($chatHash!==null&&hash_equals($prior['chat_hash'],$chatHash)&&(int)$prior['agent_id']===$scope['agent_id']&&(int)$prior['conversation_id']===$scope['conversation_id'])))throw new RuntimeException('This request identity was used for another music action.',409);
    return $prior;
}

function vp3_music_preferences_receipt_v1(PDO $pdo,array $scope,string $key,string $hash,string $operation,?string $preference,?string $chatHash=null): void
{
    $pdo->prepare('INSERT INTO music_preference_requests_v1(owner_user_id,request_key,agent_id,conversation_id,request_hash,chat_hash,preference_id,operation,created_at) VALUES(?,?,?,?,?,?,?,?,?)')
        ->execute([$scope['owner_user_id'],$key,$scope['agent_id'],$scope['conversation_id'],$hash,$chatHash??'',$preference,$operation,time()]);
}

function vp3_music_preferences_set_v1(PDO $pdo,array $user,int $agent,int $cid,array $selection,string $preference,int $expectedRevision,string $requestId,?string $chatHash=null): array
{
    if(!in_array($preference,['like','dislike','clear'],true)||$expectedRevision<0||$expectedRevision>9007199254740990)throw new RuntimeException('Choose Like, Dislike, or Clear with the current revision.',422);
    if($chatHash!==null&&!preg_match('/\A[a-f0-9]{64}\z/D',$chatHash))throw new RuntimeException('Invalid music preference Chat request.',422);
    vp3_music_preferences_require_v1($pdo);$key=vp3_music_preferences_request_v1($requestId);
    $hash=vp3_music_preferences_request_hash_v1($agent,$cid,'set',[$selection,$preference,$expectedRevision]);
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$agent,$cid,$selection,$preference,$expectedRevision,$key,$hash,$chatHash):array{
        vp3_music_chat_scope_v1($pdo,$user,$agent,$cid,true);$scope=vp3_music_preferences_scope_v1($pdo,$user,$agent,$cid);
        if($prior=vp3_music_preferences_prior_v1($pdo,$scope,$key,$hash,$chatHash)){
            $current=vp3_music_preferences_selection_v1($pdo,$user,$agent,$cid,['kind'=>'preference','reference'=>'mp1.'.$prior['preference_id']]);
            return ['state'=>vp3_music_preferences_state_v1($current),'replayed'=>true];
        }
        $selected=vp3_music_preferences_selection_v1($pdo,$user,$agent,$cid,$selection);$row=$selected['row'];
        if($preference!=='clear'&&$selected['item']===null)throw new RuntimeException('This music item is unavailable. You can clear the saved choice.',409);
        if((int)($row['revision']??0)!==$expectedRevision)throw new RuntimeException('This preference changed. Refresh it before choosing again.',409);
        $id=$row['preference_id']??bin2hex(random_bytes(16));$now=time();
        $consent=$selected['source']==='youtube'?$selected['sources']['consent_at']:0;
        $origin=json_encode(['agent_id'=>$agent,'conversation_id'=>$cid,'selection'=>$selection],JSON_THROW_ON_ERROR);
        if($row){
            $q=$pdo->prepare('UPDATE music_preferences_v1 SET preference=?,revision=revision+1,origin_json=?,consent_at=?,updated_at=? WHERE preference_id=? AND owner_user_id=? AND revision=?');
            $q->execute([$preference,$origin,$consent,$now,$id,$scope['owner_user_id'],$expectedRevision]);
            if($q->rowCount()!==1)throw new RuntimeException('This preference changed. Refresh it.',409);
        }else{
            $pdo->prepare('INSERT INTO music_preferences_v1(preference_id,owner_user_id,source,source_key,native_track_id,preference,revision,origin_json,consent_at,created_at,updated_at) VALUES(?,?,?,?,?,?,1,?,?,?,?)')
                ->execute([$id,$scope['owner_user_id'],$selected['source'],$selected['source_key'],$selected['native_track_id'],$preference,$origin,$consent,$now,$now]);
        }
        // Source changes must roll back a new positive/negative choice; Clear remains available to its owner.
        if($preference!=='clear'){
            $fresh=vp3_music_preferences_selection_v1($pdo,$user,$agent,$cid,$selection);
            if(!$fresh['item']||$fresh['source_key']!==$selected['source_key']||$fresh['sources']!==$selected['sources'])throw new RuntimeException('Music source access changed while saving.',409);
        }
        vp3_music_preferences_scope_v1($pdo,$user,$agent,$cid);
        vp3_music_preferences_receipt_v1($pdo,$scope,$key,$hash,'set',$id,$chatHash);
        $current=vp3_music_preferences_selection_v1($pdo,$user,$agent,$cid,['kind'=>'preference','reference'=>'mp1.'.$id]);
        return ['state'=>vp3_music_preferences_state_v1($current),'replayed'=>false];
    });
}

/** Only a user-triggered Next with an already acknowledged current play reaches this writer. */
function vp3_music_preferences_skip_v1(PDO $pdo,array $user,int $agent,int $cid,array $selection,array $receipt,string $requestId): array
{
    vp3_music_preferences_fields_v1($receipt,['kind','id']);
    if(!is_string($receipt['kind']??null)||!is_string($receipt['id']??null))throw new RuntimeException('Choose an acknowledged current play.',422);
    vp3_music_preferences_require_v1($pdo);$key=vp3_music_preferences_request_v1($requestId);
    $hash=vp3_music_preferences_request_hash_v1($agent,$cid,'skip',[$selection,$receipt]);
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$agent,$cid,$selection,$receipt,$key,$hash):array{
        vp3_music_chat_scope_v1($pdo,$user,$agent,$cid,true);$scope=vp3_music_preferences_scope_v1($pdo,$user,$agent,$cid);
        if(vp3_music_preferences_prior_v1($pdo,$scope,$key,$hash))return ['recorded'=>true,'replayed'=>true];
        $selected=vp3_music_preferences_selection_v1($pdo,$user,$agent,$cid,$selection);
        if($selected['source']==='youtube'&&!$selected['sources']['capture'])throw new RuntimeException('YouTube history capture is paused. Next was not recorded as preference evidence.',403);
        $reference=vp3_music_history_reference_v1($scope['owner_user_id'],$receipt['kind'],$receipt['id']);
        [$kind,$id]=vp3_music_history_reference_parse_v1($scope,$reference);
        $project=vp3_music_history_project_v1($pdo,$scope,$selected['sources'],$kind,$id,'UTC',time());
        $entry=$project['entry']??null;
        if(!$entry||!$entry['metadata_available']||!$selected['item']||$entry['source']!==$selected['source']
            ||$entry['source_id']!==$selected['item']['source_id'])throw new RuntimeException('The recorded play does not match this music selection.',409);
        if($kind==='n'){
            $q=$pdo->prepare('SELECT s.last_received_ms,s.natural_ended,p.started_at FROM music_listening_sessions_v1 s JOIN track_play_sessions p ON p.id=s.session_id WHERE s.session_id=? AND p.user_id=?');
            $q->execute([$id,$scope['owner_user_id']]);$play=$q->fetch(PDO::FETCH_ASSOC);
            if(!$play||(int)$play['natural_ended']!==0||(int)$play['last_received_ms']<1000*(time()-90)
                ||(int)$play['last_received_ms']>1000*(time()+2))throw new RuntimeException('The acknowledged native play is no longer current.',409);
        }else{
            $table=$kind==='y'?'vp3_youtube_playback_v1':'vp3_youtube_playlist_plays_v1';
            $q=$pdo->prepare('SELECT played_at FROM '.$table.' WHERE owner_user_id=? AND play_id=?');$q->execute([$scope['owner_user_id'],$id]);$played=(int)$q->fetchColumn();
            if($played<time()-21600||$played>time())throw new RuntimeException('The acknowledged YouTube play is no longer current.',409);
        }
        $q=$pdo->prepare('SELECT 1 FROM music_deliberate_skips_v1 WHERE owner_user_id=? AND receipt_kind=? AND receipt_id=?');$q->execute([$scope['owner_user_id'],$kind,$id]);$exists=(bool)$q->fetchColumn();
        if(!$exists)$pdo->prepare('INSERT INTO music_deliberate_skips_v1(owner_user_id,receipt_kind,receipt_id,source,source_key,agent_id,conversation_id,consent_at,created_at) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$scope['owner_user_id'],$kind,$id,$selected['source'],$selected['source_key'],$agent,$cid,$selected['source']==='youtube'?$selected['sources']['consent_at']:0,time()]);
        $fresh=vp3_music_preferences_selection_v1($pdo,$user,$agent,$cid,$selection);
        if(!$fresh['item']||$fresh['source_key']!==$selected['source_key']||$fresh['sources']!==$selected['sources'])throw new RuntimeException('Music source access changed while recording Next.',409);
        vp3_music_preferences_receipt_v1($pdo,$scope,$key,$hash,'skip',null);
        return ['recorded'=>true,'replayed'=>$exists];
    });
}

function vp3_music_preferences_list_v1(PDO $pdo,array $user,int $agent,int $cid,array $input=[]): array
{
    vp3_music_preferences_fields_v1($input,['limit','cursor','source']);
    $scope=vp3_music_preferences_scope_v1($pdo,$user,$agent,$cid);vp3_music_preferences_require_v1($pdo);
    $limit=$input['limit']??20;$source=$input['source']??'both';$cursor=$input['cursor']??'';$after='';
    if(!is_int($limit)||$limit<1||$limit>50||!in_array($source,['both','vp3','youtube'],true)||!is_string($cursor))throw new RuntimeException('Choose valid music preference filters.',422);
    $binding=[$scope['owner_user_id'],$agent,$cid,$source,$limit];
    if($cursor!==''){
        if(!preg_match('/\Ampc1\.([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D',$cursor,$parts))throw new RuntimeException('Refresh the saved preferences list.',409);
        $secret=vp3_media_playback_secret_v1();
        if($secret===null||!hash_equals(hash_hmac('sha256','music-preferences-list|'.$parts[1],$secret),$parts[2]))throw new RuntimeException('Refresh the saved preferences list.',409);
        try{$data=json_decode(vp3_music_history_unb64_v1($parts[1]),true,8,JSON_THROW_ON_ERROR);}catch(JsonException){throw new RuntimeException('Refresh the saved preferences list.',409);}
        if(!is_array($data)||($data['binding']??null)!==$binding||!is_int($data['expires']??null)||$data['expires']<time()
            ||!is_string($data['after']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$data['after']))throw new RuntimeException('Refresh the saved preferences list.',409);
        $after=$data['after'];
    }
    $q=$pdo->prepare("SELECT * FROM music_preferences_v1 WHERE owner_user_id=? AND preference IN ('like','dislike') AND preference_id>?"
        .($source==='both'?'':' AND source=?').' ORDER BY preference_id LIMIT '.($limit+1));
    $q->execute([$scope['owner_user_id'],$after,...($source==='both'?[]:[$source])]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);$items=[];$sources=vp3_music_history_sources_v1($pdo,$scope);
    foreach($rows as $row)$items[]=vp3_music_preferences_read_v1($pdo,$user,$agent,$cid,['kind'=>'preference','reference'=>vp3_music_preferences_reference_v1($row)]);
    $items=[];
    foreach($rows as $row)$items[]=vp3_music_preferences_read_v1($pdo,$user,$agent,$cid,['kind'=>'preference','reference'=>vp3_music_preferences_reference_v1($row)]);
    $fresh=vp3_music_preferences_scope_v1($pdo,$user,$agent,$cid);$next=null;
    if(vp3_music_history_sources_v1($pdo,$fresh)!==$sources)throw new RuntimeException('Music source settings changed. Refresh the saved choices.',409);
    if($more){
        $secret=vp3_media_playback_secret_v1();if($secret===null)throw new RuntimeException('Reopen your private Chat to page through preferences.',503);
        $body=vp3_music_history_b64_v1(json_encode(['binding'=>$binding,'after'=>end($rows)['preference_id'],'expires'=>time()+3600],JSON_THROW_ON_ERROR));
        $next='mpc1.'.$body.'.'.hash_hmac('sha256','music-preferences-list|'.$body,$secret);
    }
    return ['protocol'=>VP3_MUSIC_PREFERENCES_PROTOCOL_V1]+vp3_music_chat_scope_fields_v1($scope)+['items'=>$items,'has_more'=>$more,'next_cursor'=>$next,
        'coverage'=>['summary'=>'Your current explicit choices. Unavailable source details stay hidden; you can still clear a saved choice. Refresh after changes to see newly added choices.']];
}

/** Read bounded factual evidence through MC2's existing authorized projection, including worker cid=0. */
function vp3_music_preferences_snapshot_v1(PDO $pdo,array $user,int $agent,int $cid=0,array $candidates=[],array $verifiedProviderItems=[]): array
{
    $scope=vp3_music_preferences_scope_v1($pdo,$user,$agent,$cid);$ready=vp3_music_preferences_ready_v1($pdo);
    $coverage=['window_days'=>VP3_MUSIC_PREFERENCES_WINDOW_DAYS_V1,'history_limit'=>VP3_MUSIC_PREFERENCES_HISTORY_LIMIT_V1,
        'history_truncated'=>false,'history_candidates_scanned'=>0,
        'summary'=>'Current explicit choices and up to 200 retained playback receipts from the last 30 days. Starts, measured listening and deliberate Next are separate evidence. Declined suggestions, unplayed items and playback failures are not dislikes.'];
    if(!$ready)return ['protocol'=>VP3_MUSIC_PREFERENCES_PROTOCOL_V1,'ready'=>false,'revision'=>'unavailable','items'=>[],'coverage'=>$coverage+['status'=>'upgrade_required']];
    $sources=vp3_music_history_sources_v1($pdo,$scope);$now=time();$wanted=[];$verified=[];$prefs=[];
    // Only the existing MC1 server provider boundary supplies this envelope.
    // HTTP preference endpoints never accept provider items or this argument.
    foreach($verifiedProviderItems as $candidate){
        if(!is_array($candidate)||($candidate['source']??null)!=='youtube'||!is_string($candidate['source_id']??null)
            ||!is_int($candidate['expires_at']??null)||$candidate['expires_at']<=$now||$candidate['expires_at']>$now+VP3_MUSIC_CHAT_RESULT_TTL_V1
            ||!is_array($candidate['youtube_video']??null))throw new RuntimeException('Current provider recommendation evidence is invalid.',422);
        $video=vp3_music_chat_video_v1($candidate['youtube_video'],$candidate['source_id']);
        $key=vp3_music_preferences_source_key_v1('youtube',$candidate['source_id']);
        $verified['youtube:'.$key]=['source'=>'youtube','source_id'=>$candidate['source_id'],'title'=>$video['title'],
            'artist'=>$video['channel_title'],'metadata_available'=>true,'expires_at'=>$candidate['expires_at']];
    }
    $q=$pdo->prepare('SELECT * FROM music_preferences_v1 WHERE owner_user_id=? ORDER BY preference_id');$q->execute([$scope['owner_user_id']]);
    while($row=$q->fetch(PDO::FETCH_ASSOC))$prefs[$row['source'].':'.$row['source_key']]=$row;
    foreach($candidates as $candidate){
        if(!is_array($candidate)||!is_string($candidate['source']??null)||!is_scalar($candidate['source_id']??null))throw new RuntimeException('Invalid music recommendation identity.',422);
        $source=$candidate['source'];$id=(string)$candidate['source_id'];$key=vp3_music_preferences_source_key_v1($source,$id);$map=$source.':'.$key;
        $wanted[$map]=[$source,$key,$source==='vp3'?(int)$id:null];
    }
    if(!$candidates)foreach($prefs as $map=>$row){
        if($row['preference']==='clear'||($row['source']==='youtube'&&(int)$row['consent_at']!==$sources['consent_at']))continue;
        $wanted[$map]=[$row['source'],$row['source_key'],$row['native_track_id']===null?null:(int)$row['native_track_id']];
    }
    $max=0;if($sources['native']){$q=$pdo->prepare('SELECT MAX(id) FROM track_play_sessions WHERE user_id=?');$q->execute([$scope['owner_user_id']]);$max=(int)$q->fetchColumn();}
    $filter=['start'=>$now-VP3_MUSIC_PREFERENCES_WINDOW_DAYS_V1*86400,'end'=>$now,'filters'=>['source'=>'both']];
    $page=['as_of'=>$now,'native_max'=>$max];$after=null;$references=[];
    while(true){
        $rows=vp3_music_history_candidates_v1($pdo,$scope,$sources,$filter,$page,$after);
        foreach($rows as $row){
            if(count($references)===VP3_MUSIC_PREFERENCES_HISTORY_LIMIT_V1){$coverage['history_truncated']=true;break 2;}
            $after=[(int)$row['sort_at'],(int)$row['sort_kind'],(string)$row['sort_id']];$kind=match($after[1]){1=>'n',2=>'y',3=>'p'};
            $id=$kind==='n'?ltrim($after[2],'0'):$after[2];$references[]=[$kind,$id];
        }
        if(count($rows)<VP3_MUSIC_HISTORY_CHUNK_V1)break;
    }
    $coverage['history_candidates_scanned']=count($references);
    // Project twice: source/context changes during an earlier lookup cannot leave stale metadata in the final answer.
    $build=static function()use($pdo,$user,$agent,$cid,$sources,$wanted,$prefs,$references,$candidates,$verified):array{
        $scope=vp3_music_preferences_scope_v1($pdo,$user,$agent,$cid);
        if(vp3_music_history_sources_v1($pdo,$scope)!==$sources)throw new RuntimeException('Music source settings changed. Refresh preferences.',409);
        $identities=$wanted;$evidence=[];
        foreach($references as [$kind,$id]){
            $data=vp3_music_history_project_v1($pdo,$scope,$sources,$kind,$id,'UTC',time());$entry=$data['entry']??null;
            if(!$entry||!$entry['metadata_available']||$entry['source_id']==='')continue;
            $key=vp3_music_preferences_source_key_v1($entry['source'],$entry['source_id']);$map=$entry['source'].':'.$key;
            if(!$candidates)$identities[$map]=[$entry['source'],$key,$entry['source']==='vp3'?(int)$entry['source_id']:null];
            if(!isset($identities[$map]))continue;
            $evidence[$map]??=['play_count'=>0,'measured_plays'=>0,'measured_seconds'=>0.0,'deliberate_skips'=>0];
            ++$evidence[$map]['play_count'];
            if(in_array($entry['measurement'],['native_receipts','youtube_watch_receipts'],true)){
                $seconds=(float)($entry['listened_seconds']??0);$evidence[$map]['measured_seconds']+=$seconds;
                if($seconds>=10)++$evidence[$map]['measured_plays'];
            }
            $q=$pdo->prepare('SELECT source_key,consent_at FROM music_deliberate_skips_v1 WHERE owner_user_id=? AND receipt_kind=? AND receipt_id=?');$q->execute([$scope['owner_user_id'],$kind,$id]);$skip=$q->fetch(PDO::FETCH_ASSOC);
            if($skip&&hash_equals($key,$skip['source_key'])&&($entry['source']!=='youtube'||(int)$skip['consent_at']===$sources['consent_at']))++$evidence[$map]['deliberate_skips'];
        }
        $items=[];
        foreach($identities as $map=>[$source,$key,$native]){
            $item=isset($verified[$map])&&$sources['youtube']&&$verified[$map]['expires_at']>time()
                ?$verified[$map]:vp3_music_preferences_metadata_v1($pdo,$scope,$sources,$source,$key,$native);if(!$item)continue;
            $row=$prefs[$map]??null;$preference=$row['preference']??'clear';
            if($source==='youtube'&&$row!==null&&(int)$row['consent_at']!==$sources['consent_at'])$preference='clear';
            $preference=$preference==='clear'?'neutral':$preference;
            $facts=$evidence[$map]??['play_count'=>0,'measured_plays'=>0,'measured_seconds'=>0.0,'deliberate_skips'=>0];
            $reason=match($preference){'like'=>'You explicitly liked this item.','dislike'=>'You explicitly disliked this item.',
                default=>$facts['measured_plays']>1?'You have repeated measured listening for this item.':($facts['measured_plays']===1?'You have measured listening for this item.':'No explicit preference is saved for this item.')};
            if($facts['deliberate_skips']>0)$reason.=' You deliberately chose Next during '.$facts['deliberate_skips'].' recorded play'.($facts['deliberate_skips']===1?'':'s').'; this is not a dislike.';
            if($facts['play_count']>0)$reason.=' Listening evidence covers up to 200 retained receipts from the last 30 days.';
            $items[$source.':'.$item['source_id']]=$item+['preference'=>$preference,'preference_revision'=>(int)($row['revision']??0),'reason'=>$reason]+$facts;
        }
        ksort($items,SORT_STRING);return $items;
    };
    $build();$items=$build();$fresh=vp3_music_preferences_scope_v1($pdo,$user,$agent,$cid);
    if(vp3_music_history_sources_v1($pdo,$fresh)!==$sources)throw new RuntimeException('Music source settings changed. Refresh preferences.',409);
    $q=$pdo->prepare('SELECT * FROM music_preferences_v1 WHERE owner_user_id=? ORDER BY preference_id');$q->execute([$scope['owner_user_id']]);$currentPreferences=[];
    while($row=$q->fetch(PDO::FETCH_ASSOC))$currentPreferences[$row['source'].':'.$row['source_key']]=$row;
    if($currentPreferences!==$prefs)throw new RuntimeException('Music preferences changed during this read. Refresh them.',409);
    $revision=hash('sha256',json_encode([$scope['owner_user_id'],$agent,$cid,$sources,$items],JSON_THROW_ON_ERROR));
    return ['protocol'=>VP3_MUSIC_PREFERENCES_PROTOCOL_V1,'ready'=>true,'revision'=>$revision,'items'=>$items,'coverage'=>$coverage];
}
