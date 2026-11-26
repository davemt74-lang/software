<?php
declare(strict_types=1);

/** Artist-owned provider connections. Visitor authority is never an artist login. */
const MUSIC_PROVIDER_V1_CREDENTIAL_TTL_MS = 7776000000; // 90 days; rotation requires renewed origin proof.
const MUSIC_PROVIDER_V1_MAX_SITES = 20;
const MUSIC_PROVIDER_V1_MAX_SELECTION = 500;

function music_provider_v1_management_schema_statements(string $driver): array
{
    $tail=$driver==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $text=$driver==='mysql'?'LONGTEXT':'TEXT';
    return [
        "CREATE TABLE IF NOT EXISTS music_provider_sites_v1 (
            site_id CHAR(36) NOT NULL PRIMARY KEY, workspace_id BIGINT NOT NULL, creator_user_id BIGINT NOT NULL,
            origin VARCHAR(261) NOT NULL, label VARCHAR(120) NOT NULL, environment VARCHAR(16) NOT NULL,
            revision INTEGER NOT NULL, selection_revision INTEGER NOT NULL, status VARCHAR(16) NOT NULL,
            challenge CHAR(64) NOT NULL, token_hash CHAR(64) NOT NULL, credential_expires_ms BIGINT NOT NULL,
            created_ms BIGINT NOT NULL, updated_ms BIGINT NOT NULL, verified_ms BIGINT NULL,
            UNIQUE(workspace_id,origin), UNIQUE(token_hash)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS music_provider_selections_v1 (
            site_id CHAR(36) NOT NULL, recording_id CHAR(36) NOT NULL, position INTEGER NOT NULL,
            selected_ms BIGINT NOT NULL, PRIMARY KEY(site_id,recording_id),
            FOREIGN KEY(site_id) REFERENCES music_provider_sites_v1(site_id) ON DELETE CASCADE
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS music_provider_credentials_v1 (
            token_hash CHAR(64) NOT NULL PRIMARY KEY, site_id CHAR(36) NOT NULL,
            issued_revision INTEGER NOT NULL, expires_ms BIGINT NOT NULL, created_ms BIGINT NOT NULL,
            FOREIGN KEY(site_id) REFERENCES music_provider_sites_v1(site_id) ON DELETE RESTRICT
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS music_provider_management_receipts_v1 (
            workspace_id BIGINT NOT NULL, actor_key CHAR(64) NOT NULL, request_id CHAR(36) NOT NULL,
            request_hash CHAR(64) NOT NULL, site_id CHAR(36) NOT NULL, receipt_json {$text} NOT NULL,
            PRIMARY KEY(workspace_id,actor_key,request_id)
        ){$tail}",
    ];
}

function music_provider_v1_management_required_columns(): array
{
    return [
        'music_provider_sites_v1'=>['site_id','workspace_id','creator_user_id','origin','label','environment','revision','selection_revision','status','challenge','token_hash','credential_expires_ms','created_ms','updated_ms','verified_ms'],
        'music_provider_selections_v1'=>['site_id','recording_id','position','selected_ms'],
        'music_provider_credentials_v1'=>['token_hash','site_id','issued_revision','expires_ms','created_ms'],
        'music_provider_management_receipts_v1'=>['workspace_id','actor_key','request_id','request_hash','site_id','receipt_json'],
    ];
}

function music_provider_v1_management_fields(array $input,array $fields): void
{
    if(($input['protocol']??null)!=='vp3_music_provider_v1') music_provider_v1_error('unsupported_protocol',422,'Use the supported music provider protocol.');
    if(array_diff(array_keys($input),array_merge(['protocol','action','workspace_id','site_id','csrf_token'],$fields))) {
        music_provider_v1_error('invalid_request',422,'This music provider request contains unsupported fields.');
    }
}

function music_provider_v1_management_integer(mixed $value,int $min=0,int $max=2147483647): int
{
    if(!is_int($value)||$value<$min||$value>$max) music_provider_v1_error('invalid_request',422,'Use a valid music provider identifier or revision.');
    return $value;
}

function music_provider_v1_management_text(mixed $value,int $max,bool $empty=false): string
{
    if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$value)) music_provider_v1_error('invalid_request',422,'Use valid text for this music provider field.');
    $value=trim($value);
    if((!$empty&&$value==='')||mb_strlen($value)>$max) music_provider_v1_error('invalid_request',422,'This music provider field is empty or too long.');
    return $value;
}

function music_provider_v1_management_origin(mixed $value): string
{
    if(!is_string($value)) music_provider_v1_error('invalid_origin',422,'Enter a public HTTPS site origin.');
    require_once __DIR__.'/connected-site-http-v1.php';
    // Normalize an explicit default TLS port, but never permit arbitrary ports,
    // paths, credentials, fragments or query strings in an origin registration.
    $value=preg_replace('~:443(/?)$~D','$1',trim($value));
    try{return vp3_connected_site_origin_v1($value,'music provider');}
    catch(InvalidArgumentException){music_provider_v1_error('invalid_origin',422,'Enter a public HTTPS site origin without a path or alternate port.');}
}

function music_provider_v1_management_credential(mixed $value): string
{
    if(!is_string($value)||!preg_match('/^vmp1_[a-f0-9]{64}$/D',$value)) music_provider_v1_error('invalid_credential',422,'Generate a new 256-bit music site credential.');
    return hash('sha256',$value);
}

/** Reload authority; a captured user array is never current role evidence. */
function music_provider_v1_manager(PDO $pdo,int $workspaceId,int $userId): ?array
{
    $q=$pdo->prepare('SELECT * FROM users WHERE id=? AND is_active=1');$q->execute([$userId]);$user=$q->fetch(PDO::FETCH_ASSOC);
    if(!$user||!music_workspace_resources_v330_workspace_enabled($pdo,$workspaceId)
        ||!music_workspace_resources_v330_can_manage($pdo,$workspaceId,'tracks',$user)) return null;
    return $user;
}

function music_provider_v1_site(PDO $pdo,string $siteId,bool $lock=false): ?array
{
    $siteId=music_provider_v1_uuid($siteId);
    $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $q=$pdo->prepare('SELECT * FROM music_provider_sites_v1 WHERE site_id=?'.$suffix);$q->execute([$siteId]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

/** Call this before each visitor request, including media reads and exact retries. */
function music_provider_v1_authorize_site(PDO $pdo,string $siteId,int $nowMs,bool $lock=false): array
{
    $site=music_provider_v1_site($pdo,$siteId,$lock);
    if(!$site||$site['status']!=='active'||$site['verified_ms']===null
        ||$nowMs<(int)$site['created_ms']||$nowMs>=(int)$site['credential_expires_ms']
        ||!music_provider_v1_manager($pdo,(int)$site['workspace_id'],(int)$site['creator_user_id'])) {
        music_provider_v1_error('connection_inactive',403,'This music connection is unavailable.');
    }
    $site['_authorized_at_ms']=$nowMs;
    return $site;
}

function music_provider_v1_authenticate(PDO $pdo,string $token,int $nowMs): array
{
    if(!preg_match('/^vmp1_[a-f0-9]{64}$/D',$token)) music_provider_v1_error('invalid_credential',401,'The music site credential is unavailable.');
    $q=$pdo->prepare('SELECT site_id,token_hash FROM music_provider_sites_v1 WHERE token_hash=?');$q->execute([hash('sha256',$token)]);$match=$q->fetch(PDO::FETCH_ASSOC);
    if(!$match||!hash_equals((string)$match['token_hash'],hash('sha256',$token))) music_provider_v1_error('invalid_credential',401,'The music site credential is unavailable.');
    return music_provider_v1_authorize_site($pdo,(string)$match['site_id'],$nowMs);
}

function music_provider_v1_site_projection(array $site,bool $owner=false): array
{
    $result=['site_id'=>$site['site_id'],'workspace_id'=>(int)$site['workspace_id'],'origin'=>$site['origin'],
        'label'=>$site['label'],'environment'=>$site['environment'],'revision'=>(int)$site['revision'],
        'selection_revision'=>(int)$site['selection_revision'],'status'=>$site['status'],
        'credential_expires_ms'=>(int)$site['credential_expires_ms'],'created_ms'=>(int)$site['created_ms'],
        'updated_ms'=>(int)$site['updated_ms'],'verified_ms'=>$site['verified_ms']===null?null:(int)$site['verified_ms']];
    if($owner&&$site['status']==='pending') $result['verification']=[
        'url'=>$site['origin'].'/api/vp3-music-site.php',
        'response'=>['ok'=>true,'protocol'=>'vp3_music_site_v1','site_id'=>$site['site_id'],'origin'=>$site['origin'],'challenge'=>$site['challenge']],
    ];
    return $result;
}

function music_provider_v1_selection(PDO $pdo,string $siteId): array
{
    $q=$pdo->prepare('SELECT recording_id FROM music_provider_selections_v1 WHERE site_id=? ORDER BY recording_id');$q->execute([$siteId]);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}

function music_provider_v1_management_receipt(PDO $pdo,int $workspaceId,string $actor,string $request,string $hash): ?array
{
    $q=$pdo->prepare('SELECT request_hash,receipt_json FROM music_provider_management_receipts_v1 WHERE workspace_id=? AND actor_key=? AND request_id=?');
    $q->execute([$workspaceId,$actor,$request]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row) return null;
    if(!hash_equals((string)$row['request_hash'],$hash)) music_provider_v1_error('request_conflict',409,'This request identifier already completed with different content.');
    return json_decode((string)$row['receipt_json'],true,32,JSON_THROW_ON_ERROR);
}

function music_provider_v1_management_save_receipt(PDO $pdo,int $workspaceId,string $actor,string $request,string $hash,string $siteId,array $response): array
{
    $pdo->prepare('INSERT INTO music_provider_management_receipts_v1(workspace_id,actor_key,request_id,request_hash,site_id,receipt_json) VALUES(?,?,?,?,?,?)')
        ->execute([$workspaceId,$actor,$request,$hash,$siteId,music_provider_v1_json($response)]);
    return $response;
}

/** Artwork is exported only through an explicit public photo and parent chain. */
function music_provider_v1_public_album(PDO $pdo,int $workspaceId,int $albumId): ?array
{
    if($albumId<1)return null;
    $q=$pdo->prepare('SELECT * FROM artist_catalog_albums_v181 WHERE id=? AND workspace_id=? AND is_published=1 AND visibility=?');
    $q->execute([$albumId,$workspaceId,'public']);$album=$q->fetch(PDO::FETCH_ASSOC);
    if(!$album)return null;
    if((int)($album['source_album_id']??0)>0){
        $q=$pdo->prepare('SELECT id FROM albums WHERE id=? AND workspace_id=? AND is_published=1 AND visibility=?');
        $q->execute([(int)$album['source_album_id'],$workspaceId,'public']);if(!$q->fetchColumn())return null;
    }
    return $album;
}

function music_provider_v1_artwork_for_catalog(PDO $pdo,array $catalog): ?array
{
    $workspaceId=(int)$catalog['workspace_id'];$photoId=(int)($catalog['cover_photo_id']??0);
    if($photoId<1&&(int)($catalog['album_id']??0)>0){
        $album=music_provider_v1_public_album($pdo,$workspaceId,(int)$catalog['album_id']);
        if(!$album)return null;
        $photoId=(int)($album['cover_photo_id']??0);
    }
    if($photoId<1)return null;
    $q=$pdo->prepare('SELECT image_path,alt_text FROM artist_catalog_photos_v181 WHERE id=? AND workspace_id=? AND is_published=1 AND visibility=?');
    $q->execute([$photoId,$workspaceId,'public']);$photo=$q->fetch(PDO::FETCH_ASSOC);if(!$photo)return null;
    $path=artist_media_v182_resolve_stored_photo($workspaceId,(string)$photo['image_path']);
    if(!$path||!is_readable($path))return null;
    $size=filesize($path);if($size===false||$size<1||$size>16777216)return null;
    $mime=music_media_mime($path,'cover');if(!$mime)return null;
    return ['path'=>$path,'mime'=>$mime,'size'=>$size,'alt'=>(string)($photo['alt_text']??'')];
}

/** Safe selected public projection. Current audio readiness is separate from old-session authority. */
function music_provider_v1_public_recording(PDO $pdo,array $site,string $recordingId,bool $requireSelected=true): array
{
    $recordingId=music_provider_v1_uuid($recordingId);$workspaceId=(int)$site['workspace_id'];
    if($requireSelected){
        $q=$pdo->prepare('SELECT 1 FROM music_provider_selections_v1 WHERE site_id=? AND recording_id=?');$q->execute([$site['site_id'],$recordingId]);
        if(!$q->fetchColumn())music_provider_v1_error('unavailable',404,'This music is unavailable.');
    }
    $q=$pdo->prepare('SELECT * FROM music_recordings_v1 WHERE recording_id=? AND workspace_id=?');$q->execute([$recordingId,$workspaceId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||!empty($row['archived_at']))music_provider_v1_error('unavailable',404,'This music is unavailable.');
    $catalog=music_song_metadata_v1_catalog($pdo,$workspaceId,(int)$row['catalog_track_id']);
    $public=$catalog?music_song_metadata_v1_public($pdo,$workspaceId,(int)$row['catalog_track_id'],null):null;
    if(!$catalog||!$public||(string)$catalog['visibility']!=='public')music_provider_v1_error('unavailable',404,'This music is unavailable.');
    $work=music_song_metadata_v1_work_row($pdo,$workspaceId,(string)$row['work_id']);
    if(!$work)music_provider_v1_error('unavailable',404,'This music is unavailable.');
    $q=$pdo->prepare('SELECT asset_id,stored_path,sha256,byte_size FROM music_assets_v1 WHERE asset_id=? AND workspace_id=? AND recording_id=?');
    $q->execute([$row['current_asset_id'],$workspaceId,$recordingId]);$asset=$q->fetch(PDO::FETCH_ASSOC);
    $ready=$asset&&preg_match('/^[a-f0-9]{64}$/D',(string)$asset['sha256'])
        &&(int)$asset['byte_size']>0&&(int)$asset['byte_size']<=67108864&&(string)$asset['stored_path']===(string)$catalog['audio_path'];
    if($ready){
        // Match the initial provider delivery profile. Legacy /audio paths may
        // remain usable by the native player, but need the normal file repair
        // upload before the external provider advertises them as ready.
        $path=music_media_local_path((string)$asset['stored_path']);$uploadRoot=realpath(STONEFELLOW_ROOT.'/uploads');
        if($path)clearstatcache(true,$path);
        $ready=$path&&$uploadRoot&&str_starts_with($path,rtrim($uploadRoot,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            &&filesize($path)===(int)$asset['byte_size'];
    }
    $albumId=(int)($catalog['album_id']??0);$album=music_provider_v1_public_album($pdo,$workspaceId,$albumId);
    // A published song does not publish the draft/private release it was filed in.
    if($albumId>0)$public['album']=$album?(string)$album['title']:'';
    $art=music_provider_v1_artwork_for_catalog($pdo,$catalog);
    $revision=hash('sha256',music_provider_v1_json(array_intersect_key($catalog,array_flip(['id','title','album','album_id','description','genre','duration_seconds','track_number','cover_photo_id','visibility','is_published']))));
    return ['recording_id'=>$recordingId,'work_id'=>$row['work_id'],'catalog_track_id'=>(int)$row['catalog_track_id'],
        'asset_id'=>$row['current_asset_id'],'metadata_revision'=>(int)$row['revision'],'work_revision'=>(int)$work['revision'],
        'catalog_revision'=>$revision,'metadata'=>$public,'album_id'=>$album?(int)$album['id']:null,
        'track_number'=>(int)($catalog['track_number']??0),'duration_hint_seconds'=>(int)($catalog['duration_seconds']??0)?:null,
        'availability'=>$ready?'ready':'temporarily_unavailable','availability_reason'=>$ready?null:'audio_requires_repair',
        'artwork'=>$art?['url'=>music_provider_v1_base_url().'/api/music-provider-artwork-v1.php?site_id='.rawurlencode((string)$site['site_id']).'&recording_id='.rawurlencode($recordingId),'alt'=>$art['alt']]:null];
}

/** Internal media descriptor; callers must first authorize the current site. */
function music_provider_v1_artwork(PDO $pdo,array $site,string $recordingId): ?array
{
    $public=music_provider_v1_public_recording($pdo,$site,$recordingId,true);
    $catalog=music_song_metadata_v1_catalog($pdo,(int)$site['workspace_id'],(int)$public['catalog_track_id']);
    return $catalog?music_provider_v1_artwork_for_catalog($pdo,$catalog):null;
}

function music_provider_v1_catalog(PDO $pdo,array $site,array $input,bool $manifest=false): array
{
    $offset=music_provider_v1_management_integer($input['offset']??0,0,100000);
    $limit=music_provider_v1_management_integer($input['limit']??50,1,50);
    $search=music_provider_v1_management_text($input['search']??'',100,true);
    $selected=music_provider_v1_selection($pdo,(string)$site['site_id']);$items=[];
    if($manifest){
        $ids=array_slice($selected,$offset,$limit);$next=$offset+count($ids)<count($selected)?$offset+count($ids):null;
        foreach($ids as $id){
            try{$items[]=music_provider_v1_public_recording($pdo,$site,$id,true);}
            catch(MusicProviderV1Exception $e){if($e->publicCode!=='unavailable')throw $e;$items[]=['recording_id'=>$id,'availability'=>'unavailable'];}
        }
    }else{
        $params=[(int)$site['workspace_id'],'public'];
        $where=' WHERE r.workspace_id=? AND r.archived_at IS NULL AND t.is_published=1 AND t.visibility=?';
        if($search!==''){$where.=" AND LOWER(t.title) LIKE ? ESCAPE '!'";$params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($search)).'%';}
        $q=$pdo->prepare('SELECT r.recording_id FROM music_recordings_v1 r JOIN artist_catalog_tracks_v181 t ON t.id=r.catalog_track_id AND t.workspace_id=r.workspace_id'.$where.' ORDER BY t.album_id,t.track_number,t.title,r.recording_id LIMIT '.($limit+1).' OFFSET '.$offset);
        $q->execute($params);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$next=count($ids)>$limit?$offset+$limit:null;
        foreach(array_slice($ids,0,$limit) as $id){
            try{$item=music_provider_v1_public_recording($pdo,$site,$id,false);$item['selected']=in_array($id,$selected,true);$items[]=$item;}
            catch(MusicProviderV1Exception $e){if($e->publicCode!=='unavailable')throw $e;}
        }
    }
    return ['ok'=>true,'installation_id'=>music_provider_v1_installation($pdo)['installation_id'],
        'site'=>music_provider_v1_site_projection($site),'items'=>$items,'recording_ids'=>$selected,'next_offset'=>$next];
}

function music_provider_v1_update_selection(PDO $pdo,array $site,array $input,string $actor,int $now): array
{
    $request=music_provider_v1_uuid($input['request_id']??null);
    $expected=music_provider_v1_management_integer($input['expected_selection_revision']??null);
    $ids=$input['recording_ids']??null;
    if(!is_array($ids)||!array_is_list($ids)||count($ids)>MUSIC_PROVIDER_V1_MAX_SELECTION)music_provider_v1_error('invalid_selection',422,'Choose up to 500 recordings explicitly.');
    $ids=array_map('music_provider_v1_uuid',$ids);$ids=array_values(array_unique($ids));sort($ids,SORT_STRING);
    $hash=hash('sha256',music_provider_v1_json(['action'=>'selection.update','site_id'=>$site['site_id'],'expected_selection_revision'=>$expected,'recording_ids'=>$ids]));
    $workspaceId=(int)$site['workspace_id'];
    $prior=music_provider_v1_management_receipt($pdo,$workspaceId,$actor,$request,$hash);
    if($prior)return $prior;
    if($expected!==(int)$site['selection_revision'])music_provider_v1_error('selection_conflict',409,'The selected songs changed. Refresh before saving.');
    foreach($ids as $id){$item=music_provider_v1_public_recording($pdo,$site,$id,false);if($item['availability']!=='ready')music_provider_v1_error('unavailable',404,'A selected recording is unavailable for public streaming.');}
    if(music_provider_v1_selection($pdo,(string)$site['site_id'])!==$ids){
        $pdo->prepare('DELETE FROM music_provider_selections_v1 WHERE site_id=?')->execute([$site['site_id']]);
        $q=$pdo->prepare('INSERT INTO music_provider_selections_v1(site_id,recording_id,position,selected_ms) VALUES(?,?,?,?)');
        foreach($ids as $position=>$id)$q->execute([$site['site_id'],$id,$position,$now]);
        $site['selection_revision']=(int)$site['selection_revision']+1;$site['updated_ms']=$now;
        $pdo->prepare('UPDATE music_provider_sites_v1 SET selection_revision=?,updated_ms=? WHERE site_id=?')->execute([$site['selection_revision'],$now,$site['site_id']]);
    }
    return music_provider_v1_management_save_receipt($pdo,$workspaceId,$actor,$request,$hash,(string)$site['site_id'],[
        'ok'=>true,'site'=>music_provider_v1_site_projection($site),'recording_ids'=>$ids]);
}

/** Signed-in owner/manager flow. The HTTP controller enforces current login and CSRF. */
function music_provider_v1_owner_action(PDO $pdo,array $user,array $input,array $context=[]): array
{
    music_provider_v1_require_schema($pdo);
    $workspaceId=music_provider_v1_management_integer($input['workspace_id']??null,1);
    $userId=(int)($user['id']??0);$now=music_provider_v1_now($context);
    if(!music_provider_v1_manager($pdo,$workspaceId,$userId))music_provider_v1_error('forbidden',403,'Music site management is unavailable to this workspace role.');
    $action=$input['action']??null;$actor=hash('sha256','owner:'.$userId);
    $fields=match($action){
        'site.list'=>[],
        'site.create'=>['request_id','origin','label','environment','credential'],
        'site.verify','site.revoke'=>['request_id','expected_revision'],
        'site.rotate'=>['request_id','expected_revision','credential'],
        'site.change_origin'=>['request_id','expected_revision','credential','origin'],
        'catalog.read','import.manifest'=>['offset','limit','search'],
        'selection.update'=>['request_id','expected_selection_revision','recording_ids'],
        default=>music_provider_v1_error('unsupported_action',422,'Choose a supported music site action.'),
    };
    music_provider_v1_management_fields($input,$fields);
    if($action==='site.list'){
        $q=$pdo->prepare('SELECT * FROM music_provider_sites_v1 WHERE workspace_id=? ORDER BY created_ms,site_id');$q->execute([$workspaceId]);
        return ['ok'=>true,'sites'=>array_map(static fn(array $row)=>music_provider_v1_site_projection($row,true),$q->fetchAll(PDO::FETCH_ASSOC))];
    }
    $site=null;$siteId='';
    if($action!=='site.create'){
        $siteId=music_provider_v1_uuid($input['site_id']??null);$site=music_provider_v1_site($pdo,$siteId);
        if(!$site||(int)$site['workspace_id']!==$workspaceId)music_provider_v1_error('unavailable',404,'This music site is unavailable.');
    }
    if(in_array($action,['catalog.read','import.manifest'],true))return music_provider_v1_catalog($pdo,$site,$input,$action==='import.manifest');
    $prepared=[];$request=music_provider_v1_uuid($input['request_id']??null);
    if($action!=='selection.update'){
        if($action==='site.create'||$action==='site.change_origin')$prepared['origin']=music_provider_v1_management_origin($input['origin']??null);
        if(in_array($action,['site.create','site.rotate','site.change_origin'],true))$prepared['token_hash']=music_provider_v1_management_credential($input['credential']??null);
        if($action==='site.create'){
            $prepared['label']=music_provider_v1_management_text($input['label']??null,120);
            $environment=$input['environment']??'production';
            if(!in_array($environment,['production','staging'],true))music_provider_v1_error('invalid_request',422,'Choose production or staging for this site.');
            $prepared['environment']=$environment;
        }else $prepared['expected_revision']=music_provider_v1_management_integer($input['expected_revision']??null,1);
    }
    $hash=hash('sha256',music_provider_v1_json(['action'=>$action,'site_id'=>$siteId,'prepared'=>$prepared]));
    $proof=null;
    // Network verification occurs before the short write transaction. The exact
    // site revision/challenge is checked again under the site lock before commit.
    if($action==='site.verify'&&!music_provider_v1_management_receipt($pdo,$workspaceId,$actor,$request,$hash)){
        if($site['status']!=='pending'||(int)$site['revision']!==$prepared['expected_revision'])music_provider_v1_error('site_conflict',409,'Refresh the current site verification details.');
        $requestSite=$context['verify_request']??null;
        if($requestSite!==null&&!is_callable($requestSite))throw new LogicException('Invalid verification transport.');
        try{
            require_once __DIR__.'/connected-site-http-v1.php';
            $requestSite??=vp3_connected_site_transport_v1((string)$site['origin'],'/api/vp3-music-site.php','music provider');
            $response=$requestSite();
        }catch(Throwable){music_provider_v1_error('verification_unavailable',502,'The site verification endpoint could not be read securely.');}
        if(!is_array($response)||($response['ok']??null)!==true||($response['protocol']??null)!=='vp3_music_site_v1'
            ||($response['site_id']??null)!==$siteId||($response['origin']??null)!==$site['origin']
            ||!is_string($response['challenge']??null)||!hash_equals((string)$site['challenge'],$response['challenge'])) {
            music_provider_v1_error('verification_failed',422,'The site did not return its current origin verification challenge.');
        }
        $proof=['challenge'=>$site['challenge'],'revision'=>(int)$site['revision']];
    }
    return music_provider_v1_atomic($pdo,function()use($pdo,$workspaceId,$userId,$action,$actor,$now,$input,$prepared,$request,$hash,$siteId,$proof):array{
        // Workspace locking also serializes initial registration with catalog writes.
        $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT id FROM artist_workspaces_v181 WHERE id=?'.$suffix);$q->execute([$workspaceId]);
        if(!$q->fetchColumn()||!music_provider_v1_manager($pdo,$workspaceId,$userId))music_provider_v1_error('forbidden',403,'Music workspace authority changed.');
        $site=$action==='site.create'?null:music_provider_v1_site($pdo,$siteId,true);
        if($action!=='site.create'&&(!$site||(int)$site['workspace_id']!==$workspaceId))music_provider_v1_error('unavailable',404,'This music site is unavailable.');
        if($action==='selection.update')return music_provider_v1_update_selection($pdo,$site,$input,$actor,$now);
        $prior=music_provider_v1_management_receipt($pdo,$workspaceId,$actor,$request,$hash);if($prior)return $prior;
        if($action==='site.create'){
            $q=$pdo->prepare('SELECT COUNT(*) FROM music_provider_sites_v1 WHERE workspace_id=?');$q->execute([$workspaceId]);
            if((int)$q->fetchColumn()>=MUSIC_PROVIDER_V1_MAX_SITES)music_provider_v1_error('site_limit',429,'This workspace reached its site registration limit.',['retryable'=>false]);
        }elseif((int)$site['revision']!==$prepared['expected_revision'])music_provider_v1_error('site_conflict',409,'This site connection changed. Refresh before saving.');
        if(isset($prepared['origin'])){
            $q=$pdo->prepare('SELECT site_id FROM music_provider_sites_v1 WHERE workspace_id=? AND origin=?');$q->execute([$workspaceId,$prepared['origin']]);$existing=$q->fetchColumn();
            if($existing&&$existing!==$siteId)music_provider_v1_error('site_conflict',409,'This site origin is already registered in the workspace.');
        }
        if(isset($prepared['token_hash'])){
            $q=$pdo->prepare('SELECT site_id FROM music_provider_credentials_v1 WHERE token_hash=?');$q->execute([$prepared['token_hash']]);
            if($q->fetchColumn())music_provider_v1_error('credential_conflict',409,'Generate a fresh credential for this site operation.');
        }
        if($action==='site.create'){
            $site=['site_id'=>music_song_metadata_v1_uuid(),'workspace_id'=>$workspaceId,'creator_user_id'=>$userId,
                'origin'=>$prepared['origin'],'label'=>$prepared['label'],'environment'=>$prepared['environment'],
                'revision'=>1,'selection_revision'=>0,'status'=>'pending','challenge'=>bin2hex(random_bytes(32)),
                'token_hash'=>$prepared['token_hash'],'credential_expires_ms'=>$now+MUSIC_PROVIDER_V1_CREDENTIAL_TTL_MS,
                'created_ms'=>$now,'updated_ms'=>$now,'verified_ms'=>null];
            $keys=array_keys($site);$pdo->prepare('INSERT INTO music_provider_sites_v1('.implode(',',$keys).') VALUES('.implode(',',array_fill(0,count($keys),'?')).')')->execute(array_values($site));
        }else{
            $site['revision']=(int)$site['revision']+1;$site['updated_ms']=$now;
            if($action==='site.verify'){
                if(!$proof||$site['status']!=='pending'||(int)$site['revision']!==$proof['revision']+1||!hash_equals($site['challenge'],$proof['challenge']))music_provider_v1_error('site_conflict',409,'The verification challenge changed. Refresh and verify again.');
                if($now>=(int)$site['credential_expires_ms']||!music_provider_v1_manager($pdo,$workspaceId,(int)$site['creator_user_id']))music_provider_v1_error('connection_inactive',403,'Rotate the connection credential with a current workspace manager before verifying.');
                $site['status']='active';$site['verified_ms']=$now;
            }else{
                $site['status']=$action==='site.revoke'?'revoked':'pending';$site['verified_ms']=null;$site['challenge']=bin2hex(random_bytes(32));
                if(isset($prepared['token_hash'])){$site['token_hash']=$prepared['token_hash'];$site['credential_expires_ms']=$now+MUSIC_PROVIDER_V1_CREDENTIAL_TTL_MS;$site['creator_user_id']=$userId;}
                if(isset($prepared['origin']))$site['origin']=$prepared['origin'];
            }
            $keys=['creator_user_id','origin','revision','status','challenge','token_hash','credential_expires_ms','updated_ms','verified_ms'];
            $values=array_map(static fn(string $key)=>$site[$key],$keys);$values[]=$site['site_id'];
            $pdo->prepare('UPDATE music_provider_sites_v1 SET '.implode(',',array_map(static fn(string $key)=>$key.'=?',$keys)).' WHERE site_id=?')->execute($values);
        }
        if(isset($prepared['token_hash'])){
            // A rotated/revoked secret can never be recycled into a new grant.
            $pdo->prepare('INSERT INTO music_provider_credentials_v1(token_hash,site_id,issued_revision,expires_ms,created_ms) VALUES(?,?,?,?,?)')
                ->execute([$prepared['token_hash'],$site['site_id'],$site['revision'],$site['credential_expires_ms'],$now]);
        }
        return music_provider_v1_management_save_receipt($pdo,$workspaceId,$actor,$request,$hash,(string)$site['site_id'],['ok'=>true,'site'=>music_provider_v1_site_projection($site,true)]);
    });
}

/** The backend credential can select public music; it cannot administer a site or edit a song. */
function music_provider_v1_backend_action(PDO $pdo,array $site,array $input,array $context=[]): array
{
    music_provider_v1_require_schema($pdo);$now=music_provider_v1_now($context);$action=$input['action']??null;
    $fields=match($action){
        'catalog.read','import.manifest'=>['offset','limit','search'],
        'selection.update'=>['request_id','expected_selection_revision','recording_ids'],
        default=>music_provider_v1_error('unsupported_action',403,'This site credential does not allow that action.'),
    };
    music_provider_v1_management_fields($input,$fields);
    if(isset($input['site_id'])&&$input['site_id']!==$site['site_id'])music_provider_v1_error('unavailable',404,'This music site is unavailable.');
    if(isset($input['workspace_id'])&&$input['workspace_id']!==(int)$site['workspace_id'])music_provider_v1_error('unavailable',404,'This music workspace is unavailable.');
    return music_provider_v1_atomic($pdo,function()use($pdo,$site,$input,$action,$now):array{
        $current=music_provider_v1_authorize_site($pdo,(string)$site['site_id'],$now,true);
        if((int)$current['revision']!==(int)$site['revision']||!hash_equals((string)$current['token_hash'],(string)$site['token_hash']))music_provider_v1_error('invalid_credential',401,'This music site credential changed.');
        if($action==='selection.update')return music_provider_v1_update_selection($pdo,$current,$input,hash('sha256','site:'.$current['site_id'].':'.$current['revision']),$now);
        return music_provider_v1_catalog($pdo,$current,$input,$action==='import.manifest');
    });
}
