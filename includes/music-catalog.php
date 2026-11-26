<?php
declare(strict_types=1);

/** Serialize the catalog and its production backing in one workspace transaction. */
function music_catalog_transaction(PDO $pdo,int $workspaceId,callable $write): mixed
{
    if($workspaceId<1)throw new RuntimeException('Music Workspace is required.',422);
    $owned=!$pdo->inTransaction();
    static $sequence=0;
    $savepoint='music_catalog_'.(++$sequence);
    if($owned)$pdo->beginTransaction();else $pdo->exec('SAVEPOINT '.$savepoint);
    try{
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $stmt=$pdo->prepare('SELECT id FROM artist_workspaces_v181 WHERE id=?'.$lock);
        $stmt->execute([$workspaceId]);
        if(!$stmt->fetchColumn())throw new RuntimeException('Music Workspace is unavailable.',404);
        $result=$write();
        if($owned)$pdo->commit();else $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
        return $result;
    }catch(Throwable $e){
        if($pdo->inTransaction()){
            if($owned)$pdo->rollBack();
            else{$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}
        }
        throw $e;
    }
}

function music_catalog_revision(array $row): string
{
    return hash('sha256',json_encode($row,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}

/** Stable request content: transport fields and upload filenames are not identity. */
function music_catalog_request_hash(string $kind,string $action,array $input,array $files): string
{
    $fields=['id','title','album_id','description','genre','duration_seconds','duration','track_number','cover_photo_id','visibility','is_published','release_date','sort_order','credits','mood','energy','tempo_bpm','keywords','revision','metadata_revision','music_metadata'];
    $content=['kind'=>$kind,'action'=>$action,'fields'=>array_intersect_key($input,array_flip($fields)),'files'=>[]];
    foreach(['audio_file','cover_file'] as $name){
        $file=$files[$name]??[];
        if((int)($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;
        $tmp=(string)($file['tmp_name']??'');
        if((int)($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK||!is_uploaded_file($tmp))throw new RuntimeException('The upload did not complete. Choose the file again.',422);
        $content['files'][$name]=hash_file('sha256',$tmp);
    }
    $sort=static function(array $value)use(&$sort):array{
        if(!array_is_list($value))ksort($value,SORT_STRING);
        foreach($value as $key=>$item)if(is_array($item))$value[$key]=$sort($item);
        return $value;
    };
    return hash('sha256',json_encode($sort($content),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}

/** Require both catalog and structured-metadata versions for an existing song. */
function music_catalog_check_song_revision(PDO $pdo,int $workspaceId,array $existing,array $input): void
{
    if(!isset($input['revision'])||!hash_equals(music_catalog_revision($existing),(string)$input['revision']))throw new RuntimeException('This song changed or the edit version is missing. Reload the song before saving; your changes have not been applied.',409);
    $metadata=music_song_metadata_v1_row($pdo,$workspaceId,(int)$existing['id'],true);
    $current=(int)($metadata['revision']??0);
    if(!isset($input['metadata_revision'])||!preg_match('/^\d{1,10}$/',(string)$input['metadata_revision'])||(int)$input['metadata_revision']!==$current)throw new RuntimeException('The song metadata changed. Reload the song before saving; your changes have not been applied.',409);
}

/** Shared catalog/metadata writer for Add Track, the artist CMS and workspace. */
function music_catalog_write(PDO $pdo,int $workspaceId,array $user,string $kind,string $action,array $input,array $files=[]): int
{
    if(!in_array($kind,['track','album'],true)||!in_array($action,['save','delete','restore'],true)||($kind!=='track'&&$action==='restore'))throw new RuntimeException('Unknown music action.',422);
    $capability=$kind==='track'?'tracks':'albums';
    if(!music_workspace_resources_v330_can_manage($pdo,$workspaceId,$capability,$user))throw new RuntimeException('Catalog management is not available to your workspace role.',403);
    if($kind==='track'&&(!function_exists('music_song_metadata_v1_schema_ready')||!music_song_metadata_v1_schema_ready($pdo)))throw new RuntimeException('Install the Music Metadata database upgrade before saving songs.',503);
    foreach(['id','title','description','genre','visibility','album_id','cover_photo_id','track_number','duration_seconds','duration','revision','metadata_revision','request_key','is_published','release_date','sort_order','credits','mood','energy','tempo_bpm','keywords'] as $key){
        if(isset($input[$key])&&!is_scalar($input[$key]))throw new RuntimeException('Choose a valid value for '.$key.'.',422);
    }
    if(isset($input['id'])&&!preg_match('/^\d{1,10}$/',(string)$input['id']))throw new RuntimeException('Choose a valid song or album.',422);
    $id=max(0,(int)($input['id']??0));
    $title=trim((string)($input['title']??''));$description=trim((string)($input['description']??''));
    $genre=trim((string)($input['genre']??''));$visibility=(string)($input['visibility']??'members');
    $requestKey=$kind==='track'?trim((string)($input['request_key']??'')):'';
    if($requestKey!==''&&!preg_match('/^[A-Za-z0-9_-]{16,64}$/',$requestKey))throw new RuntimeException('The save request is invalid. Reload the form and try again.',422);
    if($kind==='track'&&$action==='save'&&isset($input['music_metadata'])&&$id===0&&$requestKey==='')throw new RuntimeException('The save request is missing. Reload the form and try again.',422);
    if($action==='save'){
        if($title===''||mb_strlen($title)>190)throw new RuntimeException('Choose a title of 1 to 190 characters.',422);
        if(mb_strlen($genre)>120||strlen($description)>65535)throw new RuntimeException('Music description or genre is too long.',422);
        if(!valid_visibility($visibility))throw new RuntimeException('Choose a valid visibility group.',422);
        foreach(['track_number','duration_seconds','album_id','cover_photo_id'] as $key){
            if(isset($input[$key])&&(string)$input[$key]!==''&&(!preg_match('/^\d{1,10}$/',(string)$input[$key])||(float)$input[$key]>4294967295))throw new RuntimeException('Choose a valid '.$key.'.',422);
        }
        // Keep the established Add Track duration field compatible with the catalog.
        $duration=trim((string)($input['duration']??''));
        if($kind==='track'&&$duration!==''&&empty($input['duration_seconds'])){
            if(!preg_match('/^(\d{1,5}):([0-5]\d)$/',$duration,$parts))throw new RuntimeException('Use minutes:seconds for duration, for example 3:45.',422);
            $input['duration_seconds']=(int)$parts[1]*60+(int)$parts[2];
        }
        foreach(['credits'=>65535,'mood'=>255,'keywords'=>500] as $key=>$max){
            if(isset($input[$key])&&strlen((string)$input[$key])>$max)throw new RuntimeException(ucfirst($key).' is too long.',422);
        }
        if(isset($input['energy'])&&!in_array((string)$input['energy'],['','low','medium','high'],true))throw new RuntimeException('Select a valid energy level.',422);
        if(isset($input['tempo_bpm'])&&(string)$input['tempo_bpm']!==''&&(!preg_match('/^\d{1,3}$/',(string)$input['tempo_bpm'])||(int)$input['tempo_bpm']>300))throw new RuntimeException('Tempo must be between 0 and 300 BPM.',422);
        if(isset($input['sort_order'])&&!preg_match('/^-?\d{1,9}$/',(string)$input['sort_order']))throw new RuntimeException('Choose a valid sort order.',422);
    }
    if($pdo->inTransaction())foreach(['audio_file','cover_file'] as $field){
        if((int)($files[$field]['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)throw new RuntimeException('Save an audio or artwork upload in its own transaction so failed saves can clean up the files.',409);
    }
    $payloadHash=$requestKey!==''?music_catalog_request_hash($kind,$action,$input,$files):'';
    $newAudio='';$newCover='';$replayed=false;
    try{
        if($kind==='track'&&$action==='save'){
            $newAudio=artist_music_v185_store_audio($files['audio_file']??[],$workspaceId);
            $newCover=artist_media_v182_store_photo($files['cover_file']??[],$workspaceId);
        }
        $result=music_catalog_transaction($pdo,$workspaceId,function()use($pdo,$workspaceId,$user,$kind,$action,$input,$id,$title,$description,$genre,$visibility,$newAudio,$newCover,$requestKey,$payloadHash,&$replayed):int{
            // Current membership is checked under the workspace write lock, including replays.
            if(!music_workspace_resources_v330_can_manage($pdo,$workspaceId,$kind==='track'?'tracks':'albums',$user))throw new RuntimeException('Workspace access changed.',403);
            if($requestKey!==''){
                $receipt=$pdo->prepare('SELECT payload_hash,catalog_track_id FROM music_catalog_requests_v1 WHERE workspace_id=? AND user_id=? AND request_key=?');
                $receipt->execute([$workspaceId,(int)$user['id'],$requestKey]);$saved=$receipt->fetch(PDO::FETCH_ASSOC);
                if($saved){
                    if(!hash_equals((string)$saved['payload_hash'],$payloadHash))throw new RuntimeException('This save request already completed with different content. Reload the song to begin a new edit.',409);
                    if(!artist_music_v185_track($pdo,$workspaceId,(int)$saved['catalog_track_id']))throw new RuntimeException('The previously saved song is no longer available.',409);
                    $replayed=true;return (int)$saved['catalog_track_id'];
                }
            }
            $existing=$id>0?($kind==='track'?artist_music_v185_track($pdo,$workspaceId,$id):artist_music_v185_album($pdo,$workspaceId,$id)):null;
            if($id>0&&!$existing)throw new RuntimeException('Music item was not found in this workspace.',404);
            if($existing){
                if($kind==='track')music_catalog_check_song_revision($pdo,$workspaceId,$existing,$input);
                elseif(isset($input['revision'])&&!hash_equals(music_catalog_revision($existing),(string)$input['revision']))throw new RuntimeException('This item changed. Reload before saving or removing it.',409);
            }
            $sourceId=(int)($existing[$kind==='track'?'source_track_id':'source_album_id']??0);
            if($kind==='track'&&$sourceId>0){
                $source=$pdo->prepare('SELECT workspace_id FROM tracks WHERE id=?');$source->execute([$sourceId]);$sourceWorkspace=$source->fetchColumn();
                if($sourceWorkspace===false||(int)$sourceWorkspace!==$workspaceId)throw new RuntimeException('This legacy song needs its workspace mapping reviewed before editing.',409);
            }
            if($action==='delete'||$action==='restore'){
                if(!$existing)throw new RuntimeException('Music item was not found.',404);
                if($kind==='track'){
                    // Archive retains catalog, favorites, playlists, history and asset identities.
                    if($action==='restore')music_song_metadata_v1_restore($pdo,$workspaceId,$id,$user);
                    else music_song_metadata_v1_archive($pdo,$workspaceId,$id,$user);
                }else{
                    $pdo->prepare("UPDATE artist_catalog_tracks_v181 SET album_id=NULL,album='' WHERE workspace_id=? AND album_id=?")->execute([$workspaceId,$id]);
                    if($sourceId>0){
                        $pdo->prepare("UPDATE tracks SET album_id=NULL,album='' WHERE workspace_id=? AND album_id=?")->execute([$workspaceId,$sourceId]);
                        $pdo->prepare('UPDATE albums SET is_published=0 WHERE id=? AND workspace_id=?')->execute([$sourceId,$workspaceId]);
                    }
                    $pdo->prepare('DELETE FROM artist_catalog_albums_v181 WHERE id=? AND workspace_id=?')->execute([$id,$workspaceId]);
                }
            }else{
                $photoId=artist_music_v185_validate_photo($pdo,$workspaceId,max(0,(int)($input['cover_photo_id']??$existing['cover_photo_id']??0)));
                $published=!empty($input['is_published'])?1:0;
                if($newCover!==''){
                    $pdo->prepare('INSERT INTO artist_catalog_photos_v181 (workspace_id,title,image_path,caption,alt_text,visibility,is_published) VALUES (?,?,?,?,?,?,?)')->execute([$workspaceId,$title.' cover',$newCover,'',$title.' cover',$visibility,$published]);
                    $photoId=(int)$pdo->lastInsertId();
                }
                if($kind==='track'){
                    $albumId=artist_music_v185_validate_album($pdo,$workspaceId,max(0,(int)($input['album_id']??0)));
                    $album=$albumId>0?artist_music_v185_album($pdo,$workspaceId,$albumId):null;
                    $audio=$newAudio!==''?$newAudio:(string)($existing['audio_path']??'');
                    if($published&&$audio==='')throw new RuntimeException('Add an audio file before publishing. You can save a draft now.',422);
                    if($published){
                        $candidate=array_replace($existing??[],['workspace_id'=>$workspaceId,'audio_path'=>$audio]);
                        $path=artist_music_v185_resolve_audio($pdo,$candidate);
                        if(!$path||!is_readable($path)||filesize($path)<1)throw new RuntimeException('The listening audio is missing or unreadable. Replace the file before publishing, or save an unpublished draft.',422);
                    }
                    $values=[$title,(string)($album['title']??''),$albumId?:null,$description,$genre,max(0,(int)($input['duration_seconds']??0))?:null,max(0,(int)($input['track_number']??0)),$audio,$photoId?:null,$visibility,$published];
                    if($id>0){$pdo->prepare('UPDATE artist_catalog_tracks_v181 SET title=?,album=?,album_id=?,description=?,genre=?,duration_seconds=?,track_number=?,audio_path=?,cover_photo_id=?,visibility=?,is_published=? WHERE id=? AND workspace_id=?')->execute([...$values,$id,$workspaceId]);}
                    else{$pdo->prepare('INSERT INTO artist_catalog_tracks_v181 (workspace_id,title,album,album_id,description,genre,duration_seconds,track_number,audio_path,cover_photo_id,visibility,is_published) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$workspaceId,...$values]);$id=(int)$pdo->lastInsertId();}
                    $backing=music_workspace_resources_v330_ensure_production_track($pdo,$workspaceId,$id,$user);
                    // Existing production descriptions survive when an entry point omits them.
                    $extra=[];$params=[];
                    foreach(['credits','mood','energy','tempo_bpm','keywords','sort_order'] as $key){
                        if(!array_key_exists($key,$input))continue;
                        $extra[]=$key.'=?';
                        $params[]=match($key){'tempo_bpm'=>(int)$input[$key]?:null,'sort_order'=>(int)$input[$key],default=>trim((string)$input[$key])};
                    }
                    if($extra)$pdo->prepare('UPDATE tracks SET '.implode(',',$extra).' WHERE id=? AND workspace_id=?')->execute([...$params,(int)$backing['id'],$workspaceId]);
                    $track=artist_music_v185_track($pdo,$workspaceId,$id);
                    music_song_metadata_v1_save($pdo,$workspaceId,$user,$track,$input);
                }else{
                    $release=trim((string)($input['release_date']??''));
                    if($release!==''){$date=DateTime::createFromFormat('!Y-m-d',$release);if(!$date||$date->format('Y-m-d')!==$release)throw new RuntimeException('Choose a valid release date.',422);}
                    $values=[$title,$release?:null,$description,$photoId?:null,(int)($input['sort_order']??$existing['sort_order']??0),$visibility,$published];
                    if($id>0)$pdo->prepare('UPDATE artist_catalog_albums_v181 SET title=?,release_date=?,description=?,cover_photo_id=?,sort_order=?,visibility=?,is_published=? WHERE id=? AND workspace_id=?')->execute([...$values,$id,$workspaceId]);
                    else{$pdo->prepare('INSERT INTO artist_catalog_albums_v181 (workspace_id,title,release_date,description,cover_photo_id,sort_order,visibility,is_published) VALUES (?,?,?,?,?,?,?,?)')->execute([$workspaceId,...$values]);$id=(int)$pdo->lastInsertId();}
                    $pdo->prepare('UPDATE artist_catalog_tracks_v181 SET album=? WHERE workspace_id=? AND album_id=?')->execute([$title,$workspaceId,$id]);
                    $sourceAlbumId=music_workspace_resources_v330_ensure_source_album($pdo,$workspaceId,$id);
                    $pdo->prepare('UPDATE tracks SET album=? WHERE workspace_id=? AND album_id=?')->execute([$title,$workspaceId,$sourceAlbumId]);
                }
            }
            if($requestKey!=='')$pdo->prepare('INSERT INTO music_catalog_requests_v1 (workspace_id,user_id,request_key,payload_hash,catalog_track_id) VALUES (?,?,?,?,?)')->execute([$workspaceId,(int)$user['id'],$requestKey,$payloadHash,$id]);
            return $id;
        });
    }catch(Throwable $e){
        if($newAudio!=='')artist_music_v185_delete_owned_audio($workspaceId,$newAudio);
        if($newCover!=='')artist_media_v182_delete_owned_photo($workspaceId,$newCover);
        throw $e;
    }
    if($replayed){
        if($newAudio!=='')artist_music_v185_delete_owned_audio($workspaceId,$newAudio);
        if($newCover!=='')artist_media_v182_delete_owned_photo($workspaceId,$newCover);
    }
    if(!$replayed&&$kind==='track'&&$action==='save'&&$id===0&&!empty($input['is_published'])&&!$pdo->inTransaction()&&function_exists('player_notify_new_release')){
        $track=artist_music_v185_track($pdo,$workspaceId,$result);
        try{player_notify_new_release('track',(int)$track['source_track_id'],$title,url('/chat.php?view=player'));}
        catch(Throwable $e){error_log('Music release notification failed: '.$e->getMessage());}
    }
    // Superseded audio stays attached to its immutable asset version for recovery.
    return $result;
}

/** Uniform published read model for Player cards and protected media. */
function music_catalog_player_track(array $track,?int $playerId=null): array
{
    $catalogId=(int)($track['artist_track_id']??$track['id']??0);
    $track['artist_track_id']=$catalogId;
    $track['_native_album_id']=(int)($track['album_id']??0);
    if($playerId!==null)$track['id']=$playerId;
    $seconds=max(0,(int)($track['duration_seconds']??0));
    if(empty($track['duration'])&&$seconds>0)$track['duration']=sprintf('%d:%02d',intdiv($seconds,60),$seconds%60);
    foreach(['lyrics','description','genre','mood','energy','keywords','duration'] as $key)$track[$key]??='';
    $track['tempo_bpm']??=null;
    return $track;
}

function music_catalog_playlist_tracks(PDO $pdo,int $playlistId,array $user,?array $visibleTracks=null): array
{
    $rows=[];
    $owner=$pdo->prepare('SELECT owner_user_id FROM playlists WHERE id=?');$owner->execute([$playlistId]);
    $owned=(int)$owner->fetchColumn()===(int)($user['id']??0)&&(int)($user['id']??0)>0;
    $stmt=$pdo->prepare('SELECT track_id AS player_id,sort_order,added_at FROM playlist_tracks WHERE playlist_id=?');$stmt->execute([$playlistId]);
    $refs=$stmt->fetchAll();
    if(table_exists('artist_workspace_playlist_tracks_v181')){
        $stmt=$pdo->prepare('SELECT artist_track_id,sort_order,added_at FROM artist_workspace_playlist_tracks_v181 WHERE playlist_id=?');$stmt->execute([$playlistId]);
        foreach($stmt->fetchAll() as $ref){$ref['player_id']=1000000000+(int)$ref['artist_track_id'];$refs[]=$ref;}
    }
    usort($refs,static fn(array $a,array $b):int=>[(int)$a['sort_order'],(string)$a['added_at'],(int)$a['player_id']]<=>[(int)$b['sort_order'],(string)$b['added_at'],(int)$b['player_id']]);
    $known=$visibleTracks??[];
    foreach($visibleTracks??[] as $track){
        $artistId=(int)($track['artist_track_id']??0);
        if($artistId>0){$alias=$track;$alias['id']=1000000000+$artistId;$known[$alias['id']]=$alias;}
    }
    foreach($refs as $ref){
        $id=(int)$ref['player_id'];$track=$known[$id]??get_track_by_id($id);
        // Only the playlist owner may see their authorized production drafts.
        // Sharing a playlist never publishes those songs to other viewers.
        if(!$track&&$owned&&function_exists('music_media_track'))$track=music_media_track($id,$user);
        if($track&&($owned||(int)($track['is_published']??1)===1)&&can_view_track($track,$user))$rows[]=$track;
    }
    return $rows;
}

function music_catalog_player_id(array $trackMap,int $artistTrackId): int
{
    foreach($trackMap as $id=>$track){if((int)($track['artist_track_id']??0)===$artistTrackId)return (int)$id;}
    return 1000000000+$artistTrackId;
}

function music_catalog_delete_photo(PDO $pdo,int $workspaceId,int $photoId): string
{
    return music_catalog_transaction($pdo,$workspaceId,function()use($pdo,$workspaceId,$photoId):string{
        $photo=artist_media_v182_photo($pdo,$workspaceId,$photoId);
        if(!$photo)throw new RuntimeException('Photo was removed from this workspace.');
        foreach(['artist_catalog_tracks_v181'=>'cover_photo_id','artist_catalog_albums_v181'=>'cover_photo_id','artist_posts_v181'=>'image_photo_id'] as $table=>$column){
            if(!table_exists($table)||!column_exists($table,$column))continue;
            $stmt=$pdo->prepare("SELECT 1 FROM {$table} WHERE workspace_id=? AND {$column}=? LIMIT 1");$stmt->execute([$workspaceId,$photoId]);
            if($stmt->fetchColumn())throw new RuntimeException('This photo is in use. Replace its music/post artwork before removing it.');
        }
        $pdo->prepare('DELETE FROM artist_catalog_photos_v181 WHERE id=? AND workspace_id=?')->execute([$photoId,$workspaceId]);
        return (string)$photo['image_path'];
    });
}

function music_catalog_albums(PDO $pdo,array $user,array $trackMap,array $favorites=[]): array
{
    $result=[];$sources=[];
    if(table_exists('albums')){
        foreach($pdo->query('SELECT * FROM albums WHERE is_published=1 ORDER BY sort_order,title,id')->fetchAll() as $album){
            if(!can_view_visibility((string)$album['visibility'],$user))continue;
            $id=(int)$album['id'];$album['tracks']=array_values(array_filter($trackMap,static fn(array $t):bool=>(int)($t['album_id']??0)===$id&&empty($t['_native_album_id'])));
            $album['favorite']=isset($favorites[$id]);$result[$id]=$album;$sources[$id]=true;
        }
    }
    if(artist_workspace_v181_schema_ready($pdo))foreach(artist_workspace_v181_public_records('albums',$user,500) as $catalog){
        $cid=(int)$catalog['id'];$sourceId=(int)($catalog['source_album_id']??0);$id=$sourceId>0?$sourceId:1000000000+$cid;
        if(isset($result[$id])){
            $result[$id]['artist_album_id']=$cid;
            if((int)($catalog['cover_photo_id']??0)>0)$result[$id]['cover_path']='catalog-art';
            continue;
        }
        // An unpublished source must not be resurrected by an old catalog shadow.
        if($sourceId>0){$s=$pdo->prepare('SELECT is_published FROM albums WHERE id=?');$s->execute([$sourceId]);$published=$s->fetchColumn();if($published!==false&&(int)$published!==1)continue;}
        $catalog['id']=$id;$catalog['artist_album_id']=$cid;
        $catalog['tracks']=array_values(array_filter($trackMap,static fn(array $t):bool=>(int)($t['workspace_id']??0)===(int)$catalog['workspace_id']&&(int)($t['_native_album_id']??$t['album_id']??0)===$cid));
        $catalog['favorite']=isset($favorites[$id]);
        if((int)($catalog['cover_photo_id']??0)>0)$catalog['cover_path']='catalog-art';
        $result[$id]=$catalog;
    }
    return array_values($result);
}

/** Resolve historical reserved Player IDs onto the real track FK used by analytics. */
function music_catalog_playback_track_id(PDO $pdo,array $track): int
{
    $id=(int)$track['id'];if($id<1000000000)return $id;
    $workspaceId=(int)($track['workspace_id']??0);$catalogId=(int)($track['artist_track_id']??($id-1000000000));
    return music_catalog_transaction($pdo,$workspaceId,function()use($pdo,$workspaceId,$catalogId):int{
        $catalog=artist_music_v185_track($pdo,$workspaceId,$catalogId);
        if(!$catalog||(int)$catalog['is_published']!==1)throw new RuntimeException('Track is unavailable.');
        $sourceId=(int)($catalog['source_track_id']??0);
        if($sourceId>0){$stmt=$pdo->prepare('SELECT id FROM tracks WHERE id=? AND workspace_id=? AND is_published=1');$stmt->execute([$sourceId,$workspaceId]);if($stmt->fetchColumn())return $sourceId;}
        $stmt=$pdo->prepare('SELECT u.* FROM users u INNER JOIN artist_workspaces_v181 w ON w.artist_user_id=u.id WHERE w.id=? AND u.is_active=1');$stmt->execute([$workspaceId]);$owner=$stmt->fetch();
        if(!$owner)throw new RuntimeException('Workspace owner is unavailable.');
        $source=music_workspace_resources_v330_ensure_production_track($pdo,$workspaceId,$catalogId,$owner);
        return (int)$source['id'];
    });
}
