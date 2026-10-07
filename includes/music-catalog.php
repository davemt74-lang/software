<?php
declare(strict_types=1);

/** Serialize the catalog and its production backing in one workspace transaction. */
function music_catalog_transaction(PDO $pdo,int $workspaceId,callable $write): mixed
{
    if($workspaceId<1)throw new RuntimeException('Music Workspace is required.');
    $owned=!$pdo->inTransaction();
    if($owned)$pdo->beginTransaction();
    try{
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $stmt=$pdo->prepare('SELECT id FROM artist_workspaces_v181 WHERE id=?'.$lock);
        $stmt->execute([$workspaceId]);
        if(!$stmt->fetchColumn())throw new RuntimeException('Music Workspace is unavailable.');
        $result=$write();
        if($owned)$pdo->commit();
        return $result;
    }catch(Throwable $e){if($owned&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function music_catalog_revision(array $row): string
{
    return hash('sha256',json_encode($row,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}

/** Shared writer for the artist CMS and the workspace library. */
function music_catalog_write(PDO $pdo,int $workspaceId,array $user,string $kind,string $action,array $input,array $files=[]): int
{
    if(!in_array($kind,['track','album'],true)||!in_array($action,['save','delete'],true))throw new RuntimeException('Unknown music action.');
    $capability=$kind==='track'?'tracks':'albums';
    if(!music_workspace_resources_v330_can_manage($pdo,$workspaceId,$capability,$user))throw new RuntimeException('Catalog management is not available to your workspace role.');
    $id=max(0,(int)($input['id']??0));
    $title=trim((string)($input['title']??''));$description=trim((string)($input['description']??''));
    $genre=trim((string)($input['genre']??''));$visibility=(string)($input['visibility']??'members');
    if($action==='save'){
        if($title===''||mb_strlen($title)>190)throw new RuntimeException('Choose a title of 1 to 190 characters.');
        if(mb_strlen($genre)>120||strlen($description)>65535)throw new RuntimeException('Music description or genre is too long.');
        if(!valid_visibility($visibility))throw new RuntimeException('Choose a valid visibility group.');
        foreach(['track_number','duration_seconds'] as $key){if(isset($input[$key])&&(!is_scalar($input[$key])||!preg_match('/^\d{1,10}$/',(string)$input[$key])||(float)$input[$key]>4294967295))throw new RuntimeException('Choose a valid '.$key.'.');}
    }
    $newAudio=$kind==='track'&&$action==='save'?artist_music_v185_store_audio($files['audio_file']??[],$workspaceId):'';
    $oldAudio='';
    try{
        $result=music_catalog_transaction($pdo,$workspaceId,function()use($pdo,$workspaceId,$user,$kind,$action,$input,$id,$title,$description,$genre,$visibility,$newAudio,&$oldAudio):int{
            // Recheck current workspace authority after acquiring the write lock.
            if(!music_workspace_resources_v330_can_manage($pdo,$workspaceId,$kind==='track'?'tracks':'albums',$user))throw new RuntimeException('Workspace access changed.');
            $existing=$id>0?($kind==='track'?artist_music_v185_track($pdo,$workspaceId,$id):artist_music_v185_album($pdo,$workspaceId,$id)):null;
            if($id>0&&!$existing)throw new RuntimeException('Music item was removed from this workspace.');
            if($existing&&isset($input['revision'])&&!hash_equals(music_catalog_revision($existing),(string)$input['revision']))throw new RuntimeException('This item changed. Reload before saving or removing it.');
            $sourceId=(int)($existing[$kind==='track'?'source_track_id':'source_album_id']??0);
            $oldAudio=(string)($existing['audio_path']??'');
            if($action==='delete'){
                if(!$existing)throw new RuntimeException('Music item was not found.');
                if($kind==='track'){
                    $pdo->prepare('DELETE FROM artist_catalog_tracks_v181 WHERE id=? AND workspace_id=?')->execute([$id,$workspaceId]);
                    if($sourceId>0)$pdo->prepare('UPDATE tracks SET is_published=0 WHERE id=? AND workspace_id=?')->execute([$sourceId,$workspaceId]);
                }else{
                    $pdo->prepare("UPDATE artist_catalog_tracks_v181 SET album_id=NULL,album='' WHERE workspace_id=? AND album_id=?")->execute([$workspaceId,$id]);
                    if($sourceId>0){
                        $pdo->prepare("UPDATE tracks SET album_id=NULL,album='' WHERE workspace_id=? AND album_id=?")->execute([$workspaceId,$sourceId]);
                        $pdo->prepare('UPDATE albums SET is_published=0 WHERE id=? AND workspace_id=?')->execute([$sourceId,$workspaceId]);
                    }
                    $pdo->prepare('DELETE FROM artist_catalog_albums_v181 WHERE id=? AND workspace_id=?')->execute([$id,$workspaceId]);
                }
                return $id;
            }
            $photoId=artist_music_v185_validate_photo($pdo,$workspaceId,max(0,(int)($input['cover_photo_id']??$existing['cover_photo_id']??0)));
            $published=!empty($input['is_published'])?1:0;
            if($kind==='track'){
                $albumId=artist_music_v185_validate_album($pdo,$workspaceId,max(0,(int)($input['album_id']??0)));
                $album=$albumId>0?artist_music_v185_album($pdo,$workspaceId,$albumId):null;
                $audio=$newAudio!==''?$newAudio:$oldAudio;
                if($audio==='')throw new RuntimeException('Upload an MP3, M4A, WAV, or OGG audio file.');
                $values=[$title,(string)($album['title']??''),$albumId?:null,$description,$genre,max(0,(int)($input['duration_seconds']??0))?:null,max(0,(int)($input['track_number']??0)),$audio,$photoId?:null,$visibility,$published];
                if($id>0){$pdo->prepare('UPDATE artist_catalog_tracks_v181 SET title=?,album=?,album_id=?,description=?,genre=?,duration_seconds=?,track_number=?,audio_path=?,cover_photo_id=?,visibility=?,is_published=? WHERE id=? AND workspace_id=?')->execute([...$values,$id,$workspaceId]);}
                else{$pdo->prepare('INSERT INTO artist_catalog_tracks_v181 (workspace_id,title,album,album_id,description,genre,duration_seconds,track_number,audio_path,cover_photo_id,visibility,is_published) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$workspaceId,...$values]);$id=(int)$pdo->lastInsertId();}
                // Eager backing gives new releases a real FK-safe Player identity.
                music_workspace_resources_v330_ensure_production_track($pdo,$workspaceId,$id,$user);
            }else{
                $release=trim((string)($input['release_date']??''));
                if($release!==''){$date=DateTime::createFromFormat('!Y-m-d',$release);if(!$date||$date->format('Y-m-d')!==$release)throw new RuntimeException('Choose a valid release date.');}
                $values=[$title,$release?:null,$description,$photoId?:null,(int)($input['sort_order']??$existing['sort_order']??0),$visibility,$published];
                if($id>0)$pdo->prepare('UPDATE artist_catalog_albums_v181 SET title=?,release_date=?,description=?,cover_photo_id=?,sort_order=?,visibility=?,is_published=? WHERE id=? AND workspace_id=?')->execute([...$values,$id,$workspaceId]);
                else{$pdo->prepare('INSERT INTO artist_catalog_albums_v181 (workspace_id,title,release_date,description,cover_photo_id,sort_order,visibility,is_published) VALUES (?,?,?,?,?,?,?,?)')->execute([$workspaceId,...$values]);$id=(int)$pdo->lastInsertId();}
                $pdo->prepare('UPDATE artist_catalog_tracks_v181 SET album=? WHERE workspace_id=? AND album_id=?')->execute([$title,$workspaceId,$id]);
                $sourceAlbumId=music_workspace_resources_v330_ensure_source_album($pdo,$workspaceId,$id);
                $pdo->prepare('UPDATE tracks SET album=? WHERE workspace_id=? AND album_id=?')->execute([$title,$workspaceId,$sourceAlbumId]);
            }
            return $id;
        });
    }catch(Throwable $e){if($newAudio!=='')artist_music_v185_delete_owned_audio($workspaceId,$newAudio);throw $e;}
    if(($action==='delete'||$newAudio!=='')&&$oldAudio!=='')artist_music_v185_delete_owned_audio($workspaceId,$oldAudio);
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
    foreach($refs as $ref){$id=(int)$ref['player_id'];$track=$known[$id]??get_track_by_id($id);if($track&&can_view_track($track,$user))$rows[]=$track;}
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
