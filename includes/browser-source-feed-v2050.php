<?php
declare(strict_types=1);

require_once __DIR__.'/browser-share-v2011.php';

const VP3_BROWSER_SOURCE_FEED_V2050 = 'browser-source-feed-v2050-20260917';
const VP3_BROWSER_SOURCE_FEED_LIMIT_MAX_V2050 = 50;
const VP3_BROWSER_SOURCE_COMMENT_MAX_V2050 = 4000;

function vp3_browser_source_feed_schema_ready_v2050(?PDO $pdo=null): bool
{
    $pdo ??= db();
    return (bool)$pdo
        && vp3_browser_share_schema_ready_v2010($pdo)
        && table_exists('browser_sources_v2050')
        && table_exists('browser_source_versions_v2050')
        && table_exists('browser_share_sources_v2050')
        && table_exists('browser_share_publications_v2050')
        && table_exists('browser_source_follows_v2050')
        && vp3_social_schema_ready_v320($pdo)
        && table_exists('browser_share_comments_v2050')
        && table_exists('browser_share_saves_v2050')
        && table_exists('browser_research_queue_v2050')
        && table_exists('browser_share_reads_v2050');
}

function vp3_browser_source_feed_require_ready_v2050(?PDO $pdo=null): PDO
{
    $pdo ??= db();
    if(!$pdo || !vp3_browser_source_feed_schema_ready_v2050($pdo)){
        throw new RuntimeException('Browser Source Feed is not ready. Run the current database upgrade.');
    }
    return $pdo;
}

function vp3_browser_source_uuid_v2050(): string
{
    return vp3_extension_uuid_v2000();
}

function vp3_browser_source_normalize_url_v2050(string $raw): array
{
    $validated=vp3_browser_share_validate_url_v2010($raw);
    $parts=parse_url((string)$validated['url']);
    if(!is_array($parts))throw new InvalidArgumentException('A valid source URL is required.');

    $scheme=strtolower((string)($parts['scheme']??'https'));
    $host=strtolower(rtrim((string)($parts['host']??''),'.'));
    $port=(int)($parts['port']??0);
    $authority=str_contains($host,':')&&!str_starts_with($host,'[')?'['.$host.']':$host;
    if($port>0 && !(($scheme==='https'&&$port===443)||($scheme==='http'&&$port===80)))$authority.=':'.$port;

    $path=(string)($parts['path']??'/');
    if($path==='')$path='/';
    $path=preg_replace('#/{2,}#','/',$path)??$path;
    $segments=[];
    foreach(explode('/',$path) as $segment){
        if($segment===''||$segment==='.')continue;
        if($segment==='..'){array_pop($segments);continue;}
        $segments[]=$segment;
    }
    $path='/'.implode('/',$segments);
    if(str_ends_with((string)($parts['path']??''),'/') && $path!=='/')$path.='/';

    $tracking=[
        'fbclid'=>true,'gclid'=>true,'dclid'=>true,'msclkid'=>true,'mc_cid'=>true,'mc_eid'=>true,
        '_ga'=>true,'_gl'=>true,'igshid'=>true,'vero_conv'=>true,'vero_id'=>true,
    ];
    $pairs=[];
    foreach(explode('&',(string)($parts['query']??'')) as $pair){
        if($pair==='')continue;
        [$rawKey,$rawValue]=array_pad(explode('=',$pair,2),2,'');
        $key=strtolower(trim(rawurldecode(str_replace('+',' ',$rawKey))));
        if($key===''||str_starts_with($key,'utm_')||isset($tracking[$key]))continue;
        $pairs[]=['key'=>$key,'raw_key'=>$rawKey,'raw_value'=>$rawValue,'raw'=>$rawKey.($rawValue!==''?'='.$rawValue:'')];
    }
    usort($pairs,static function(array $a,array $b): int {
        $key=strcmp((string)$a['key'],(string)$b['key']);
        return $key!==0?$key:strcmp((string)$a['raw'],(string)$b['raw']);
    });
    $query=implode('&',array_map(static fn(array $p): string=>(string)$p['raw'],$pairs));
    $normalized=$scheme.'://'.$authority.$path.($query!==''?'?'.$query:'');
    if(strlen($normalized)>VP3_BROWSER_SHARE_URL_MAX_BYTES_V2010)throw new InvalidArgumentException('Source URL is too long.');
    return [
        'normalized_url'=>$normalized,
        'url_hash'=>hash('sha256',$normalized),
        'domain'=>$host,
    ];
}

function vp3_browser_source_identity_v2050(string $url,string $canonicalUrl='',string $title=''): array
{
    $page=vp3_browser_source_normalize_url_v2050($url);
    $preferred=$page;
    if(trim($canonicalUrl)!==''){
        try{
            $canonical=vp3_browser_source_normalize_url_v2050($canonicalUrl);
            $pageHost=strtolower((string)$page['domain']);
            $canonicalHost=strtolower((string)$canonical['domain']);
            $pageComparable=preg_replace('/^www\\./','',$pageHost)??$pageHost;
            $canonicalComparable=preg_replace('/^www\\./','',$canonicalHost)??$canonicalHost;
            // A canonical tag is controlled by page content. Only same-host / www
            // aliases may change Source identity; unrelated cross-origin canonicals
            // remain metadata-only so one site cannot poison another site's feed.
            if(hash_equals($pageComparable,$canonicalComparable))$preferred=$canonical;
        }catch(Throwable $e){
            // Malformed page-controlled canonical metadata must not make a valid
            // current page unavailable to This Page.
        }
    }
    $identity=$preferred;
    $title=trim($title);
    if(mb_strlen($title)>VP3_BROWSER_SHARE_TITLE_MAX_CHARS_V2010)$title=mb_substr($title,0,VP3_BROWSER_SHARE_TITLE_MAX_CHARS_V2010);
    return $identity+[
        'canonical_url'=>$identity['normalized_url'],
        'title'=>$title,
    ];
}

function vp3_browser_source_row_by_hash_v2050(PDO $pdo,string $hash): ?array
{
    if(!preg_match('/^[a-f0-9]{64}$/',$hash))return null;
    $stmt=$pdo->prepare('SELECT * FROM browser_sources_v2050 WHERE url_hash=? LIMIT 1');
    $stmt->execute([$hash]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_source_row_by_public_id_v2050(PDO $pdo,string $publicId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM browser_sources_v2050 WHERE public_id=? LIMIT 1');
    $stmt->execute([trim($publicId)]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_source_ensure_v2050(PDO $pdo,array $identity): array
{
    $hash=(string)($identity['url_hash']??'');
    $normalized=(string)($identity['normalized_url']??'');
    $title=trim((string)($identity['title']??''));
    $domain=trim((string)($identity['domain']??''));
    if(!preg_match('/^[a-f0-9]{64}$/',$hash)||$normalized===''||$domain==='')throw new InvalidArgumentException('Source identity is invalid.');

    $row=vp3_browser_source_row_by_hash_v2050($pdo,$hash);
    if(!$row){
        $publicId=vp3_browser_source_uuid_v2050();
        $stmt=$pdo->prepare("INSERT IGNORE INTO browser_sources_v2050
            (public_id,url_hash,normalized_url,canonical_url,source_domain,source_title,first_seen_at,last_seen_at)
            VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $stmt->execute([$publicId,$hash,$normalized,(string)($identity['canonical_url']??$normalized),$domain,$title]);
        $row=vp3_browser_source_row_by_hash_v2050($pdo,$hash);
    }
    if(!$row)throw new RuntimeException('Source identity could not be persisted.');
    if(!hash_equals((string)$row['url_hash'],$hash)||(string)$row['normalized_url']!==$normalized){
        throw new RuntimeException('Source identity collision detected.');
    }
    $pdo->prepare("UPDATE browser_sources_v2050
        SET canonical_url=?,source_domain=?,source_title=CASE WHEN ?<>'' THEN ? ELSE source_title END,last_seen_at=UTC_TIMESTAMP()
        WHERE id=?")
        ->execute([(string)($identity['canonical_url']??$normalized),$domain,$title,$title,(int)$row['id']]);
    return vp3_browser_source_row_by_hash_v2050($pdo,$hash)??$row;
}

function vp3_browser_source_share_row_v2050(PDO $pdo,string $publicId): ?array
{
    $stmt=$pdo->prepare("SELECT s.*,u.display_name AS sender_name,
        l.human_message_id,m.conversation_id,
        map.source_id,map.source_version_id,
        src.public_id AS source_public_id,src.normalized_url AS source_normalized_url,src.canonical_url AS source_canonical_url,
        src.current_version_id,src.source_title AS canonical_source_title,src.source_domain AS canonical_source_domain,
        ver.public_id AS source_version_public_id,ver.content_hash AS source_version_hash,ver.version_basis,
        pub.visibility,pub.team_owner_user_id,pub.published_at
      FROM browser_shares_v2010 s
      INNER JOIN users u ON u.id=s.sender_user_id
      LEFT JOIN human_message_browser_shares_v2010 l ON l.browser_share_id=s.id
      LEFT JOIN human_messages m ON m.id=l.human_message_id
      LEFT JOIN browser_share_sources_v2050 map ON map.browser_share_id=s.id
      LEFT JOIN browser_sources_v2050 src ON src.id=map.source_id
      LEFT JOIN browser_source_versions_v2050 ver ON ver.id=map.source_version_id
      LEFT JOIN browser_share_publications_v2050 pub ON pub.browser_share_id=s.id
      WHERE s.public_id=? AND s.deleted_at IS NULL LIMIT 1");
    $stmt->execute([trim($publicId)]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_source_share_row_by_id_v2050(PDO $pdo,int $shareId): ?array
{
    if($shareId<1)return null;
    $stmt=$pdo->prepare('SELECT public_id FROM browser_shares_v2010 WHERE id=? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$shareId]);
    $public=(string)($stmt->fetchColumn()?:'');
    return $public!==''?vp3_browser_source_share_row_v2050($pdo,$public):null;
}

function vp3_browser_source_share_authorized_v2050(PDO $pdo,array $row,int $viewerUserId): bool
{
    $sender=(int)($row['sender_user_id']??0);
    if($viewerUserId>0 && $sender===$viewerUserId)return true;

    $visibility=trim((string)($row['visibility']??''));
    if($visibility!==''){
        if($visibility==='public')return true;
        if($visibility==='team' && $viewerUserId>0){
            $teamOwner=(int)($row['team_owner_user_id']??0);
            return $teamOwner>0 && vp3_human_team_authorized_v370($pdo,$teamOwner,$viewerUserId);
        }
        return false;
    }

    // Legacy Phase 4/5 Browser Shares have no publication row. Preserve their
    // existing live Human Messaging authorization until explicitly published.
    if($viewerUserId<1)return false;
    try{
        return is_array(vp3_browser_share_by_public_id_v2010($pdo,(string)$row['public_id'],$viewerUserId));
    }catch(Throwable $e){
        return false;
    }
}

function vp3_browser_source_register_share_v2050(PDO $pdo,array $share,string $sourceVersionHash='',string $versionBasis='legacy_capture'): array
{
    $identity=vp3_browser_source_identity_v2050(
        (string)($share['source_url']??''),
        (string)($share['canonical_url']??''),
        (string)($share['source_title']??'')
    );
    $source=vp3_browser_source_ensure_v2050($pdo,$identity);
    $shareId=(int)($share['id']??0);
    if($shareId<1)throw new RuntimeException('Browser Share is invalid.');

    $basis=$versionBasis==='page_text_sha256'?'page_text_sha256':'legacy_capture';
    $hash=strtolower(trim($sourceVersionHash));
    if(!preg_match('/^[a-f0-9]{64}$/',$hash)){
        $hash=strtolower((string)($share['snapshot_hash']??''));
        $basis='legacy_capture';
    }
    if(!preg_match('/^[a-f0-9]{64}$/',$hash))$hash=hash('sha256',(string)$share['public_id']);

    $versionStmt=$pdo->prepare("SELECT id FROM browser_source_versions_v2050 WHERE source_id=? AND content_hash=? AND version_basis=? LIMIT 1");
    $versionStmt->execute([(int)$source['id'],$hash,$basis]);
    $versionId=(int)($versionStmt->fetchColumn()?:0);
    if($versionId<1){
        $publicId=vp3_browser_source_uuid_v2050();
        $insert=$pdo->prepare("INSERT IGNORE INTO browser_source_versions_v2050
            (public_id,source_id,content_hash,version_basis,canonical_url,source_title,captured_at,created_at)
            VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())");
        $insert->execute([
            $publicId,(int)$source['id'],$hash,$basis,(string)$identity['canonical_url'],(string)$identity['title'],
            (string)($share['captured_at']??gmdate('Y-m-d H:i:s')),
        ]);
        $versionStmt->execute([(int)$source['id'],$hash,$basis]);
        $versionId=(int)($versionStmt->fetchColumn()?:0);
    }
    if($versionId<1)throw new RuntimeException('Source version could not be persisted.');

    $pdo->prepare("INSERT INTO browser_share_sources_v2050(browser_share_id,source_id,source_version_id,created_at)
        VALUES(?,?,?,UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE source_id=VALUES(source_id),source_version_id=VALUES(source_version_id)")
        ->execute([$shareId,(int)$source['id'],$versionId]);
    $pdo->prepare('UPDATE browser_sources_v2050 SET current_version_id=?,last_seen_at=UTC_TIMESTAMP() WHERE id=?')
        ->execute([$versionId,(int)$source['id']]);

    return ['source'=>$source,'source_version_id'=>$versionId];
}

function vp3_browser_source_publish_v2050(PDO $pdo,int $userId,string $browserSharePublicId,string $visibility,int $teamOwnerUserId=0,string $sourceVersionHash=''): array
{
    vp3_browser_source_feed_require_ready_v2050($pdo);
    $row=vp3_browser_source_share_row_v2050($pdo,$browserSharePublicId);
    if(!$row || (int)$row['sender_user_id']!==$userId)throw new RuntimeException('Only the Browser Share author can publish this annotation.');

    $visibility=strtolower(trim($visibility));
    if(!in_array($visibility,['private','team','public'],true))throw new InvalidArgumentException('Choose Private, Team, or Public visibility.');
    if($visibility==='team'){
        if($teamOwnerUserId<1 || !vp3_human_team_authorized_v370($pdo,$teamOwnerUserId,$userId)){
            throw new RuntimeException('Choose a Team workspace you can access.');
        }
    }else{
        $teamOwnerUserId=0;
    }

    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $registered=vp3_browser_source_register_share_v2050(
            $pdo,
            $row,
            $sourceVersionHash,
            preg_match('/^[a-f0-9]{64}$/',strtolower(trim($sourceVersionHash)))?'page_text_sha256':'legacy_capture'
        );
        $pdo->prepare("INSERT INTO browser_share_publications_v2050
            (browser_share_id,published_by_user_id,visibility,team_owner_user_id,published_at,updated_at)
            VALUES(?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE visibility=VALUES(visibility),team_owner_user_id=VALUES(team_owner_user_id),updated_at=UTC_TIMESTAMP()")
            ->execute([(int)$row['id'],$userId,$visibility,$teamOwnerUserId>0?$teamOwnerUserId:null]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }

    $fresh=vp3_browser_source_share_row_v2050($pdo,$browserSharePublicId);
    if(!$fresh)throw new RuntimeException('Published annotation could not be reloaded.');
    if(function_exists('vp3_search_index_annotation_v2090'))vp3_search_index_annotation_v2090($pdo,(int)$fresh['id']);
    if(function_exists('vp3_search_index_source_v2090')&&(int)($fresh['source_id']??0)>0)vp3_search_index_source_v2090($pdo,(int)$fresh['source_id']);
    return vp3_browser_source_item_v2050($pdo,$fresh,$userId,true);
}

function vp3_browser_source_following_flags_v2050(PDO $pdo,int $viewerUserId,array $row): array
{
    if($viewerUserId<1)return ['saved'=>false,'in_research'=>false,'unread'=>false,'following_source'=>false,'following_user'=>false];
    $shareId=(int)$row['id'];
    $sourceId=(int)($row['source_id']??0);
    $senderId=(int)($row['sender_user_id']??0);
    $checks=[];
    foreach([
        'saved'=>['SELECT 1 FROM browser_share_saves_v2050 WHERE user_id=? AND browser_share_id=? LIMIT 1',[$viewerUserId,$shareId]],
        'in_research'=>['SELECT 1 FROM browser_research_queue_v2050 WHERE user_id=? AND browser_share_id=? LIMIT 1',[$viewerUserId,$shareId]],
        'read'=>['SELECT 1 FROM browser_share_reads_v2050 WHERE user_id=? AND browser_share_id=? LIMIT 1',[$viewerUserId,$shareId]],
        'following_source'=>['SELECT 1 FROM browser_source_follows_v2050 WHERE user_id=? AND source_id=? LIMIT 1',[$viewerUserId,$sourceId]],
        'following_user'=>['SELECT 1 FROM user_follows WHERE follower_user_id=? AND followed_user_id=? LIMIT 1',[$viewerUserId,$senderId]],
    ] as $key=>[$sql,$params]){
        if(($key==='following_source'&&$sourceId<1)||($key==='following_user'&&$senderId<1)){$checks[$key]=false;continue;}
        $stmt=$pdo->prepare($sql);$stmt->execute($params);$checks[$key]=(bool)$stmt->fetchColumn();
    }
    return [
        'saved'=>(bool)$checks['saved'],
        'in_research'=>(bool)$checks['in_research'],
        'unread'=>$senderId!==$viewerUserId && !(bool)$checks['read'],
        'following_source'=>(bool)$checks['following_source'],
        'following_user'=>$senderId!==$viewerUserId && (bool)$checks['following_user'],
    ];
}

function vp3_browser_source_comments_v2050(PDO $pdo,int $shareId,int $limit=25): array
{
    $limit=max(1,min(100,$limit));
    $stmt=$pdo->prepare("SELECT c.public_id,c.parent_comment_id,p.public_id AS parent_public_id,c.user_id,u.display_name,c.body,c.created_at,c.updated_at
      FROM browser_share_comments_v2050 c
      INNER JOIN users u ON u.id=c.user_id
      LEFT JOIN browser_share_comments_v2050 p ON p.id=c.parent_comment_id
      WHERE c.browser_share_id=? AND c.deleted_at IS NULL
      ORDER BY c.id ASC LIMIT {$limit}");
    $stmt->execute([$shareId]);
    return array_map(static function(array $row): array {
        return [
            'id'=>(string)$row['public_id'],
            'parent_id'=>(string)($row['parent_public_id']??''),
            'user'=>['id'=>(int)$row['user_id'],'name'=>(string)$row['display_name']],
            'body'=>(string)$row['body'],
            'created_at'=>(string)$row['created_at'],
            'updated_at'=>(string)$row['updated_at'],
        ];
    },$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_browser_source_media_v2050(PDO $pdo,int $shareId): array
{
    if(!vp3_browser_share_media_schema_ready_v2040($pdo))return [];
    $stmt=$pdo->prepare("SELECT public_id,media_kind,mime_type,byte_size,sha256,original_name,storage_key,metadata_json,media_status,created_at
      FROM browser_share_media_v2040 WHERE browser_share_id=? AND deleted_at IS NULL ORDER BY id ASC");
    $stmt->execute([$shareId]);
    return array_map('vp3_browser_share_media_public_v2040',$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_browser_source_public_source_v2050(PDO $pdo,array $row,int $viewerUserId=0): array
{
    $sourceId=(int)($row['source_id']??$row['id']??0);
    $followed=false;
    if($viewerUserId>0&&$sourceId>0){
        $stmt=$pdo->prepare('SELECT 1 FROM browser_source_follows_v2050 WHERE user_id=? AND source_id=? LIMIT 1');
        $stmt->execute([$viewerUserId,$sourceId]);
        $followed=(bool)$stmt->fetchColumn();
    }
    $itemContext=array_key_exists('sender_user_id',$row);
    return [
        'id'=>(string)($row['source_public_id']??$row['public_id']??''),
        'url'=>(string)($row['source_normalized_url']??$row['normalized_url']??''),
        'canonical_url'=>(string)($row['source_canonical_url']??$row['canonical_url']??''),
        'title'=>$itemContext?(string)($row['source_title']??''):(string)($row['canonical_source_title']??$row['source_title']??''),
        'domain'=>$itemContext?(string)($row['source_domain']??''):(string)($row['canonical_source_domain']??$row['source_domain']??''),
        'following'=>$followed,
        'page_url'=>url('/source.php?source='.rawurlencode((string)($row['source_public_id']??$row['public_id']??''))),
    ];
}

function vp3_browser_source_item_v2050(PDO $pdo,array $row,int $viewerUserId=0,bool $withComments=true,bool $preauthorized=false): array
{
    if(!$preauthorized&&!vp3_browser_source_share_authorized_v2050($pdo,$row,$viewerUserId))throw new RuntimeException('This annotation is not available.');
    $base=vp3_browser_share_public_v2020($row);
    $deliveryAuthorized=false;
    if($viewerUserId>0){
        try{$deliveryAuthorized=is_array(vp3_browser_share_by_public_id_v2010($pdo,(string)$row['public_id'],$viewerUserId));}
        catch(Throwable $e){$deliveryAuthorized=false;}
    }
    if(!$deliveryAuthorized){
        // Publication visibility is intentionally independent from delivery.
        // Never expose internal Human Messaging identifiers to a viewer who is
        // authorized only through a Team/Public Source Feed publication.
        $base['message_id']=0;
        $base['conversation_id']=0;
    }
    $flags=vp3_browser_source_following_flags_v2050($pdo,$viewerUserId,$row);
    $versionBasis=(string)($row['version_basis']??'');
    $currentVersion=(int)($row['current_version_id']??0);
    $itemVersion=(int)($row['source_version_id']??0);
    $sourceChanged=$versionBasis==='page_text_sha256' && $currentVersion>0 && $itemVersion>0 && $currentVersion!==$itemVersion;

    $base['sender']=['id'=>(int)$row['sender_user_id'],'name'=>(string)($row['sender_name']??'VP3 user')];
    $base['source_identity']=vp3_browser_source_public_source_v2050($pdo,$row,$viewerUserId);
    $base['publication']=[
        'visibility'=>(string)($row['visibility']??'legacy'),
        'team_id'=>(int)($row['team_owner_user_id']??0),
        'published_at'=>(string)($row['published_at']??$row['created_at']??''),
    ];
    $base['source_version']=[
        'id'=>(string)($row['source_version_public_id']??''),
        'basis'=>$versionBasis,
        'is_current'=>$itemVersion>0&&$itemVersion===$currentVersion,
        'changed'=>$sourceChanged,
        'badge'=>$sourceChanged?'Source changed':($versionBasis==='page_text_sha256'?'Source snapshot':'Captured snapshot'),
    ];
    $base['media']=vp3_browser_source_media_v2050($pdo,(int)$row['id']);
    $base['comments']=$withComments?vp3_browser_source_comments_v2050($pdo,(int)$row['id']):[];
    $countStmt=$pdo->prepare('SELECT COUNT(*) FROM browser_share_comments_v2050 WHERE browser_share_id=? AND deleted_at IS NULL');
    $countStmt->execute([(int)$row['id']]);
    $base['comment_count']=(int)$countStmt->fetchColumn();
    $base['interactions']=$flags;
    $base['annotation_url']=url('/annotation.php?id='.rawurlencode((string)$row['public_id']));
    return $base;
}

function vp3_browser_source_cursor_encode_v2050(int $shareId): string
{
    if($shareId<1)return '';
    return rtrim(strtr(base64_encode(json_encode(['i'=>$shareId],JSON_UNESCAPED_SLASHES)),'+/','-_'),'=');
}

function vp3_browser_source_cursor_decode_v2050(string $cursor): int
{
    $cursor=trim($cursor);
    if($cursor==='')return 0;
    $raw=strtr($cursor,'-_','+/');
    $pad=strlen($raw)%4;
    if($pad)$raw.=str_repeat('=',4-$pad);
    $decoded=base64_decode($raw,true);
    $data=is_string($decoded)?json_decode($decoded,true):null;
    return is_array($data)?max(0,(int)($data['i']??0)):0;
}

function vp3_browser_source_this_page_v2050(PDO $pdo,int $viewerUserId,string $url,string $canonicalUrl='',string $title='',int $limit=25,string $cursor='',string $currentPageHash=''): array
{
    vp3_browser_source_feed_require_ready_v2050($pdo);
    $identity=vp3_browser_source_identity_v2050($url,$canonicalUrl,$title);
    $currentPageHash=strtolower(trim($currentPageHash));
    if(!preg_match('/^[a-f0-9]{64}$/',$currentPageHash))$currentPageHash='';
    $source=vp3_browser_source_row_by_hash_v2050($pdo,(string)$identity['url_hash']);
    if(!$source){
        return [
            'source'=>[
                'id'=>'','url'=>(string)$identity['normalized_url'],'canonical_url'=>(string)$identity['canonical_url'],
                'title'=>(string)$identity['title'],'domain'=>(string)$identity['domain'],'following'=>false,'page_url'=>'',
            ],
            'items'=>[],'next_cursor'=>'','has_more'=>false,
        ];
    }

    $limit=max(1,min(VP3_BROWSER_SOURCE_FEED_LIMIT_MAX_V2050,$limit));
    $before=vp3_browser_source_cursor_decode_v2050($cursor);
    $scan=max(80,$limit*5);
    $sql="SELECT s.id FROM browser_share_sources_v2050 map
      INNER JOIN browser_shares_v2010 s ON s.id=map.browser_share_id AND s.deleted_at IS NULL
      WHERE map.source_id=?".($before>0?' AND s.id<?':'')." ORDER BY s.id DESC LIMIT ".$scan;
    $stmt=$pdo->prepare($sql);
    $stmt->execute($before>0?[(int)$source['id'],$before]:[(int)$source['id']]);
    $ids=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[]);
    $items=[];$lastScanned=0;$scannedCount=0;
    foreach($ids as $id){
        $scannedCount++;
        $lastScanned=$id;
        $row=vp3_browser_source_share_row_by_id_v2050($pdo,$id);
        if(!$row||!vp3_browser_source_share_authorized_v2050($pdo,$row,$viewerUserId))continue;
        $item=vp3_browser_source_item_v2050($pdo,$row,$viewerUserId,true);
        if($currentPageHash!=='' && (string)($row['version_basis']??'')==='page_text_sha256'){
            $changed=!hash_equals((string)($row['source_version_hash']??''),$currentPageHash);
            $item['source_version']['changed']=$changed;
            $item['source_version']['is_current']=!$changed;
            $item['source_version']['badge']=$changed?'Source changed':'Current page snapshot';
        }
        $items[]=$item;
        if(count($items)>=$limit)break;
    }
    $publicSource=vp3_browser_source_public_source_v2050($pdo,$source,$viewerUserId);
    if(empty($items) && empty($publicSource['following'])){
        $publicSource=[
            'id'=>'',
            'url'=>(string)$identity['normalized_url'],
            'canonical_url'=>(string)$identity['canonical_url'],
            'title'=>(string)$identity['title'],
            'domain'=>(string)$identity['domain'],
            'following'=>false,
            'page_url'=>'',
        ];
    }elseif((string)$identity['title']!==''){
        // The current viewer's live tab title is safer than mutable global Source
        // metadata and avoids leaking another user's personalized page title.
        $publicSource['title']=(string)$identity['title'];
    }
    return [
        'source'=>$publicSource,
        'items'=>$items,
        'next_cursor'=>$lastScanned>0?vp3_browser_source_cursor_encode_v2050($lastScanned):'',
        'has_more'=>$scannedCount<count($ids)||count($ids)===$scan,
    ];
}

function vp3_browser_source_following_v2050(PDO $pdo,int $viewerUserId,int $limit=20,string $cursor=''): array
{
    vp3_browser_source_feed_require_ready_v2050($pdo);
    if($viewerUserId<1)throw new RuntimeException('Sign in to view Following.');
    $limit=max(1,min(VP3_BROWSER_SOURCE_FEED_LIMIT_MAX_V2050,$limit));
    $before=vp3_browser_source_cursor_decode_v2050($cursor);
    $scan=max(100,$limit*6);
    $sql="SELECT DISTINCT s.id
      FROM browser_shares_v2010 s
      LEFT JOIN browser_share_sources_v2050 map ON map.browser_share_id=s.id
      LEFT JOIN browser_source_follows_v2050 sf ON sf.source_id=map.source_id AND sf.user_id=?
      LEFT JOIN user_follows uf ON uf.followed_user_id=s.sender_user_id AND uf.follower_user_id=?
      WHERE s.deleted_at IS NULL AND (sf.user_id IS NOT NULL OR uf.follower_user_id IS NOT NULL)".
      ($before>0?' AND s.id<?':'')." ORDER BY s.id DESC LIMIT ".$scan;
    $stmt=$pdo->prepare($sql);
    $params=[$viewerUserId,$viewerUserId];
    if($before>0)$params[]=$before;
    $stmt->execute($params);
    $ids=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[]);
    $items=[];$lastScanned=0;$scannedCount=0;
    foreach($ids as $id){
        $scannedCount++;
        $lastScanned=$id;
        $row=vp3_browser_source_share_row_by_id_v2050($pdo,$id);
        if(!$row||!vp3_browser_source_share_authorized_v2050($pdo,$row,$viewerUserId))continue;
        $items[]=vp3_browser_source_item_v2050($pdo,$row,$viewerUserId,true);
        if(count($items)>=$limit)break;
    }
    return [
        'items'=>$items,
        'next_cursor'=>$lastScanned>0?vp3_browser_source_cursor_encode_v2050($lastScanned):'',
        'has_more'=>$scannedCount<count($ids)||count($ids)===$scan,
    ];
}

function vp3_browser_source_follow_source_v2050(PDO $pdo,int $userId,string $sourcePublicId,bool $follow,string $url='',string $canonicalUrl='',string $title=''): array
{
    if($userId<1)throw new RuntimeException('Sign in to follow sources.');
    $source=$sourcePublicId!==''?vp3_browser_source_row_by_public_id_v2050($pdo,$sourcePublicId):null;
    if(!$source){
        if(trim($url)==='')throw new RuntimeException('Source was not found.');
        $source=vp3_browser_source_ensure_v2050($pdo,vp3_browser_source_identity_v2050($url,$canonicalUrl,''));
    }
    if($follow){
        $pdo->prepare('INSERT IGNORE INTO browser_source_follows_v2050(user_id,source_id,created_at) VALUES(?,?,UTC_TIMESTAMP())')
            ->execute([$userId,(int)$source['id']]);
    }else{
        $pdo->prepare('DELETE FROM browser_source_follows_v2050 WHERE user_id=? AND source_id=?')
            ->execute([$userId,(int)$source['id']]);
    }
    return ['following'=>$follow,'source'=>vp3_browser_source_public_source_v2050($pdo,$source,$userId)];
}

function vp3_browser_source_follow_user_v2050(PDO $pdo,int $userId,int $followedUserId,bool $follow): array
{
    if($userId<1||$followedUserId<1||$followedUserId===$userId)throw new InvalidArgumentException('Choose another VP3 user to follow.');
    if(!vp3_social_schema_ready_v320($pdo))throw new RuntimeException('VP3 social relationships are unavailable.');
    vp3_social_follow_v320($pdo,$userId,$followedUserId,$follow);
    if($follow&&function_exists('vp3_browser_trust_notify_follow_v2080'))vp3_browser_trust_notify_follow_v2080($pdo,$userId,$followedUserId);
    return ['following'=>vp3_social_following_v320($pdo,$userId,$followedUserId),'user_id'=>$followedUserId];
}

function vp3_browser_source_authorized_row_v2050(PDO $pdo,int $userId,string $browserSharePublicId): array
{
    $row=vp3_browser_source_share_row_v2050($pdo,$browserSharePublicId);
    if(!$row||!vp3_browser_source_share_authorized_v2050($pdo,$row,$userId))throw new RuntimeException('This annotation is not available.');
    return $row;
}

function vp3_browser_source_comment_v2050(PDO $pdo,int $userId,string $browserSharePublicId,string $body,string $parentPublicId=''): array
{
    if($userId<1)throw new RuntimeException('Sign in to comment.');
    $row=vp3_browser_source_authorized_row_v2050($pdo,$userId,$browserSharePublicId);
    $body=trim($body);
    if($body===''||strlen($body)>VP3_BROWSER_SOURCE_COMMENT_MAX_V2050||str_contains($body,"\0")){
        throw new InvalidArgumentException('Comment must be between 1 and 4,000 bytes.');
    }
    $parentId=null;
    if(trim($parentPublicId)!==''){
        $stmt=$pdo->prepare('SELECT id FROM browser_share_comments_v2050 WHERE public_id=? AND browser_share_id=? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([trim($parentPublicId),(int)$row['id']]);
        $parentId=(int)($stmt->fetchColumn()?:0);
        if($parentId<1)throw new RuntimeException('Reply target was not found.');
    }
    $publicId=vp3_browser_source_uuid_v2050();
    $stmt=$pdo->prepare("INSERT INTO browser_share_comments_v2050(public_id,browser_share_id,parent_comment_id,user_id,body,created_at,updated_at)
      VALUES(?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $stmt->execute([$publicId,(int)$row['id'],$parentId,$userId,$body]);
    if(function_exists('vp3_browser_trust_notify_comment_v2080'))vp3_browser_trust_notify_comment_v2080($pdo,$row,$userId,$publicId,trim($parentPublicId));
    if(function_exists('vp3_browser_trust_notify_comment_mentions_v2080'))vp3_browser_trust_notify_comment_mentions_v2080($pdo,$row,$userId,$body,$publicId);
    if(function_exists('vp3_search_index_annotation_v2090'))vp3_search_index_annotation_v2090($pdo,(int)$row['id']);
    return ['comments'=>vp3_browser_source_comments_v2050($pdo,(int)$row['id'],100)];
}

function vp3_browser_source_toggle_share_state_v2050(PDO $pdo,int $userId,string $browserSharePublicId,string $kind,bool $enabled): array
{
    if($userId<1)throw new RuntimeException('Sign in to use this action.');
    $row=vp3_browser_source_authorized_row_v2050($pdo,$userId,$browserSharePublicId);
    $table=$kind==='research'?'browser_research_queue_v2050':'browser_share_saves_v2050';
    if($enabled){
        $pdo->prepare("INSERT IGNORE INTO {$table}(user_id,browser_share_id,created_at) VALUES(?,?,UTC_TIMESTAMP())")
            ->execute([$userId,(int)$row['id']]);
    }else{
        $pdo->prepare("DELETE FROM {$table} WHERE user_id=? AND browser_share_id=?")
            ->execute([$userId,(int)$row['id']]);
    }
    if(function_exists('vp3_search_index_annotation_v2090'))vp3_search_index_annotation_v2090($pdo,(int)$row['id']);
    return [$kind==='research'?'in_research':'saved'=>$enabled];
}

function vp3_browser_source_mark_read_v2050(PDO $pdo,int $userId,string $browserSharePublicId): array
{
    if($userId<1)throw new RuntimeException('Sign in to mark annotations read.');
    $row=vp3_browser_source_authorized_row_v2050($pdo,$userId,$browserSharePublicId);
    $pdo->prepare("INSERT INTO browser_share_reads_v2050(user_id,browser_share_id,read_at)
      VALUES(?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE read_at=UTC_TIMESTAMP()")
      ->execute([$userId,(int)$row['id']]);
    return ['unread'=>false];
}

function vp3_browser_source_share_team_v2050(PDO $pdo,int $userId,string $browserSharePublicId,array $destination): array
{
    if($userId<1)throw new RuntimeException('Sign in to share annotations.');
    $row=vp3_browser_source_authorized_row_v2050($pdo,$userId,$browserSharePublicId);
    $conversation=vp3_browser_share_destination_conversation_v2010($pdo,$userId,$destination);
    $title=trim((string)$row['source_title']) ?: (string)$row['source_domain'];
    $link=url('/annotation.php?id='.rawurlencode((string)$row['public_id']));
    $body=mb_substr("Shared annotation".($title!==''?": ".$title:'')."\n".$link,0,3900);
    $message=vp3_human_send_message_v370($pdo,(int)$conversation['id'],$userId,$body);
    return ['message_id'=>(int)$message['id'],'conversation_id'=>(int)$conversation['id']];
}

function vp3_browser_source_backfill_v2050(PDO $pdo,int $limit=5000): int
{
    $limit=max(1,min(20000,$limit));
    $stmt=$pdo->query("SELECT s.* FROM browser_shares_v2010 s
      LEFT JOIN browser_share_sources_v2050 map ON map.browser_share_id=s.id
      WHERE s.deleted_at IS NULL AND map.browser_share_id IS NULL ORDER BY s.id ASC LIMIT {$limit}");
    $count=0;
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $share){
        try{vp3_browser_source_register_share_v2050($pdo,$share,'','legacy_capture');$count++;}
        catch(Throwable $e){error_log('VP3 Browser Source v20.50 backfill skipped share '.(int)$share['id'].': '.$e->getMessage());}
    }
    return $count;
}

function vp3_browser_source_migrate_legacy_user_follows_v2050(PDO $pdo): void
{
    if(!table_exists('browser_user_follows_v2050')||!vp3_social_schema_ready_v320($pdo))return;
    $pdo->exec("INSERT IGNORE INTO user_follows(follower_user_id,followed_user_id,created_at)
      SELECT user_id,followed_user_id,created_at FROM browser_user_follows_v2050");
}

function vp3_browser_source_feed_ensure_schema_v2050(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_social_schema_ready_v320($pdo))vp3_social_ensure_schema_v320($pdo);
    if(vp3_browser_source_feed_schema_ready_v2050($pdo)){
        vp3_browser_source_migrate_legacy_user_follows_v2050($pdo);
        vp3_browser_source_backfill_v2050($pdo,20000);
        return;
    }
    if($pdo->inTransaction())throw new RuntimeException('Browser Source Feed schema must be installed before starting a transaction.');
    if(!vp3_browser_share_schema_ready_v2010($pdo))vp3_browser_share_ensure_schema_v2010($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_sources_v2050 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      url_hash CHAR(64) NOT NULL,
      normalized_url VARCHAR(2048) NOT NULL,
      canonical_url VARCHAR(2048) NOT NULL,
      source_domain VARCHAR(253) NOT NULL,
      source_title VARCHAR(512) NOT NULL DEFAULT '',
      current_version_id BIGINT UNSIGNED NULL,
      first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_source_public (public_id),
      UNIQUE KEY uq_browser_source_hash (url_hash),
      INDEX idx_browser_source_domain (source_domain,last_seen_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_source_versions_v2050 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      source_id BIGINT UNSIGNED NOT NULL,
      content_hash CHAR(64) NOT NULL,
      version_basis VARCHAR(32) NOT NULL DEFAULT 'legacy_capture',
      canonical_url VARCHAR(2048) NOT NULL,
      source_title VARCHAR(512) NOT NULL DEFAULT '',
      captured_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_source_version_public (public_id),
      UNIQUE KEY uq_browser_source_version_hash (source_id,content_hash,version_basis),
      INDEX idx_browser_source_version_source (source_id,id),
      CONSTRAINT fk_browser_source_version_source FOREIGN KEY (source_id) REFERENCES browser_sources_v2050(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_share_sources_v2050 (
      browser_share_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      source_id BIGINT UNSIGNED NOT NULL,
      source_version_id BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_browser_share_source_source (source_id,browser_share_id),
      INDEX idx_browser_share_source_version (source_version_id,browser_share_id),
      CONSTRAINT fk_browser_share_source_share FOREIGN KEY (browser_share_id) REFERENCES browser_shares_v2010(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_share_source_source FOREIGN KEY (source_id) REFERENCES browser_sources_v2050(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_share_source_version FOREIGN KEY (source_version_id) REFERENCES browser_source_versions_v2050(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_share_publications_v2050 (
      browser_share_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      published_by_user_id INT UNSIGNED NOT NULL,
      visibility VARCHAR(16) NOT NULL DEFAULT 'private',
      team_owner_user_id INT UNSIGNED NULL,
      published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_browser_publication_visibility (visibility,published_at,browser_share_id),
      INDEX idx_browser_publication_team (team_owner_user_id,visibility,published_at),
      CONSTRAINT fk_browser_publication_share FOREIGN KEY (browser_share_id) REFERENCES browser_shares_v2010(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_publication_user FOREIGN KEY (published_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_publication_team FOREIGN KEY (team_owner_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_source_follows_v2050 (
      user_id INT UNSIGNED NOT NULL,
      source_id BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,source_id),
      INDEX idx_browser_source_follow_source (source_id,user_id),
      CONSTRAINT fk_browser_source_follow_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_source_follow_source FOREIGN KEY (source_id) REFERENCES browser_sources_v2050(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_share_comments_v2050 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      browser_share_id BIGINT UNSIGNED NOT NULL,
      parent_comment_id BIGINT UNSIGNED NULL,
      user_id INT UNSIGNED NOT NULL,
      body TEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      UNIQUE KEY uq_browser_share_comment_public (public_id),
      INDEX idx_browser_share_comment_share (browser_share_id,id),
      INDEX idx_browser_share_comment_user (user_id,created_at,id),
      CONSTRAINT fk_browser_share_comment_share FOREIGN KEY (browser_share_id) REFERENCES browser_shares_v2010(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_share_comment_parent FOREIGN KEY (parent_comment_id) REFERENCES browser_share_comments_v2050(id) ON DELETE SET NULL,
      CONSTRAINT fk_browser_share_comment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach(['browser_share_saves_v2050','browser_research_queue_v2050'] as $table){
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (
          user_id INT UNSIGNED NOT NULL,
          browser_share_id BIGINT UNSIGNED NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (user_id,browser_share_id),
          INDEX idx_{$table}_share (browser_share_id,user_id),
          CONSTRAINT fk_{$table}_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
          CONSTRAINT fk_{$table}_share FOREIGN KEY (browser_share_id) REFERENCES browser_shares_v2010(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_share_reads_v2050 (
      user_id INT UNSIGNED NOT NULL,
      browser_share_id BIGINT UNSIGNED NOT NULL,
      read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,browser_share_id),
      INDEX idx_browser_share_read_share (browser_share_id,user_id),
      CONSTRAINT fk_browser_share_read_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_share_read_share FOREIGN KEY (browser_share_id) REFERENCES browser_shares_v2010(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    vp3_browser_source_migrate_legacy_user_follows_v2050($pdo);
    vp3_browser_source_backfill_v2050($pdo,20000);
}
