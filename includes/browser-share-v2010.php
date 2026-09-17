<?php
declare(strict_types=1);

/**
 * VP3 v20.10 Browser Share backend.
 *
 * Browser Share is an immutable, source-aware object linked to the canonical
 * human-message ledger. Captured browser content is plain text, never executable
 * HTML, and is not copied into generic activity/audit storage.
 */
const VP3_BROWSER_SHARE_V2010 = 'browser-share-v2010-20260917';
const VP3_BROWSER_SHARE_SELECTED_MAX_BYTES_V2010 = 32768;
const VP3_BROWSER_SHARE_NOTE_MAX_BYTES_V2010 = 4096;
const VP3_BROWSER_SHARE_TITLE_MAX_CHARS_V2010 = 512;
const VP3_BROWSER_SHARE_URL_MAX_BYTES_V2010 = 2048;
const VP3_BROWSER_SHARE_HOURLY_LIMIT_V2010 = 300;

final class VP3BrowserShareExceptionV2010 extends RuntimeException
{
    public function __construct(
        public readonly string $apiCode,
        public readonly int $httpStatus,
        string $message
    ) {
        parent::__construct($message);
    }
}

function vp3_browser_share_schema_ready_v2010(?PDO $pdo=null): bool
{
    $pdo ??= db();
    return (bool)$pdo
        && vp3_extension_schema_ready_v2000($pdo)
        && vp3_human_messaging_v370_schema_ready($pdo)
        && table_exists('browser_shares_v2010')
        && table_exists('human_message_browser_shares_v2010')
        && table_exists('browser_share_idempotency_v2010');
}

function vp3_browser_share_ensure_schema_v2010(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_browser_share_schema_ready_v2010($pdo))return;
    if($pdo->inTransaction())throw new RuntimeException('Browser Share schema must be installed before starting a share transaction.');

    if(!vp3_extension_schema_ready_v2000($pdo))vp3_extension_ensure_schema_v2000($pdo);
    if(!vp3_human_messaging_v370_schema_ready($pdo))vp3_human_messaging_v370_ensure_schema($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_shares_v2010 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      sender_user_id INT UNSIGNED NOT NULL,
      device_id BIGINT UNSIGNED NULL,
      share_type VARCHAR(24) NOT NULL,
      source_url VARCHAR(2048) NOT NULL,
      canonical_url VARCHAR(2048) NOT NULL,
      source_title VARCHAR(512) NOT NULL DEFAULT '',
      source_domain VARCHAR(253) NOT NULL,
      selected_text MEDIUMTEXT NOT NULL,
      user_note TEXT NOT NULL,
      captured_at DATETIME NOT NULL,
      snapshot_hash CHAR(64) NOT NULL,
      dedupe_fingerprint CHAR(64) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      UNIQUE KEY uq_browser_share_public (public_id),
      INDEX idx_browser_share_sender (sender_user_id,created_at,id),
      INDEX idx_browser_share_device (device_id,created_at,id),
      INDEX idx_browser_share_dedupe (sender_user_id,dedupe_fingerprint,created_at),
      CONSTRAINT fk_browser_share_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_share_device FOREIGN KEY (device_id) REFERENCES extension_devices_v2000(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS human_message_browser_shares_v2010 (
      human_message_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      browser_share_id BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_human_message_browser_share (browser_share_id),
      CONSTRAINT fk_human_message_browser_share_message FOREIGN KEY (human_message_id) REFERENCES human_messages(id) ON DELETE CASCADE,
      CONSTRAINT fk_human_message_browser_share_share FOREIGN KEY (browser_share_id) REFERENCES browser_shares_v2010(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_share_idempotency_v2010 (
      device_id BIGINT UNSIGNED NOT NULL,
      idempotency_key CHAR(36) NOT NULL,
      operation VARCHAR(40) NOT NULL DEFAULT 'browser_share.create',
      browser_share_id BIGINT UNSIGNED NULL,
      human_message_id BIGINT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      expires_at DATETIME NOT NULL,
      PRIMARY KEY (device_id,idempotency_key),
      INDEX idx_browser_share_idempotency_expiry (expires_at,device_id),
      CONSTRAINT fk_browser_share_idempotency_device FOREIGN KEY (device_id) REFERENCES extension_devices_v2000(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_share_idempotency_share FOREIGN KEY (browser_share_id) REFERENCES browser_shares_v2010(id) ON DELETE SET NULL,
      CONSTRAINT fk_browser_share_idempotency_message FOREIGN KEY (human_message_id) REFERENCES human_messages(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_browser_share_require_ready_v2010(?PDO $pdo=null): PDO
{
    $pdo ??= db();
    if(!$pdo || !vp3_browser_share_schema_ready_v2010($pdo) || !vp3_human_messaging_v370_ready($pdo)){
        throw new VP3BrowserShareExceptionV2010('service_unavailable',503,'VP3 Browser Share is not ready. Run the database upgrade.');
    }
    return $pdo;
}

function vp3_browser_share_require_capability_v2010(array $session,string $capability): void
{
    if(!vp3_extension_session_has_capability_v2000($session,$capability)){
        throw new VP3BrowserShareExceptionV2010('capability_denied',403,'This browser connection does not have permission for that action.');
    }
}

function vp3_browser_share_device_db_id_v2010(PDO $pdo,array $session): int
{
    $publicId=(string)($session['device_id']??'');
    $userId=(int)($session['user_id']??0);
    if(!vp3_extension_valid_uuid_v2000($publicId)||$userId<1){
        throw new VP3BrowserShareExceptionV2010('authentication_required',401,'Browser Companion authentication is required.');
    }
    $stmt=$pdo->prepare("SELECT id FROM extension_devices_v2000 WHERE public_id=? AND user_id=? AND device_status='active' AND revoked_at IS NULL LIMIT 1");
    $stmt->execute([$publicId,$userId]);
    $id=(int)($stmt->fetchColumn()?:0);
    if($id<1)throw new VP3BrowserShareExceptionV2010('device_revoked',401,'This browser connection is no longer active.');
    return $id;
}

function vp3_browser_share_scrub_query_v2010(string $query): string
{
    if($query==='')return '';
    $sensitive=array_fill_keys([
        'access_token','auth','authorization','code','id_token','jwt','oauth_token',
        'password','passwd','refresh_token','secret','session','session_id','sid',
        'api_key','apikey','signature','sig','token',
    ],true);
    $safe=[];
    foreach(explode('&',$query) as $pair){
        if($pair==='')continue;
        $rawKey=explode('=',$pair,2)[0];
        $key=strtolower(trim(rawurldecode(str_replace('+',' ',$rawKey))));
        if($key!==''&&isset($sensitive[$key]))continue;
        $safe[]=$pair;
    }
    return implode('&',$safe);
}

function vp3_browser_share_validate_url_v2010(mixed $value): array
{
    if(!is_scalar($value))throw new VP3BrowserShareExceptionV2010('invalid_request',422,'A valid source URL is required.');
    $url=trim((string)$value);
    if($url===''||strlen($url)>VP3_BROWSER_SHARE_URL_MAX_BYTES_V2010||preg_match('/[\x00-\x1F\x7F]/',$url)){
        throw new VP3BrowserShareExceptionV2010('invalid_request',422,'A valid source URL is required.');
    }
    $parts=parse_url($url);
    if(!is_array($parts))throw new VP3BrowserShareExceptionV2010('invalid_request',422,'A valid source URL is required.');
    $scheme=strtolower((string)($parts['scheme']??''));
    $host=strtolower(rtrim((string)($parts['host']??''),'.'));
    if(!in_array($scheme,['http','https'],true)||$host===''||strlen($host)>253||isset($parts['user'])||isset($parts['pass'])){
        throw new VP3BrowserShareExceptionV2010('unsupported_page',422,'Only normal HTTP and HTTPS pages can be shared.');
    }
    if(isset($parts['port'])&&((int)$parts['port']<1||(int)$parts['port']>65535)){
        throw new VP3BrowserShareExceptionV2010('invalid_request',422,'Source URL port is invalid.');
    }

    // Fragments and common credential/token query values are deliberately not
    // persisted. Browser Share stores provenance but should not preserve auth
    // state embedded in a URL.
    $authority=$host;
    if(str_contains($host,':')&&!str_starts_with($host,'['))$authority='['.$host.']';
    if(isset($parts['port']))$authority.=':'.(int)$parts['port'];
    $normalized=$scheme.'://'.$authority.(string)($parts['path']??'');
    $query=vp3_browser_share_scrub_query_v2010((string)($parts['query']??''));
    if($query!=='')$normalized.='?'.$query;
    if(strlen($normalized)>VP3_BROWSER_SHARE_URL_MAX_BYTES_V2010)throw new VP3BrowserShareExceptionV2010('invalid_request',422,'Source URL is too long.');
    return ['url'=>$normalized,'domain'=>$host];
}

function vp3_browser_share_capture_time_v2010(mixed $value): string
{
    if(!is_scalar($value)||trim((string)$value)==='')return gmdate('Y-m-d H:i:s');
    try{
        $date=new DateTimeImmutable((string)$value);
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }catch(Throwable $e){
        throw new VP3BrowserShareExceptionV2010('invalid_request',422,'Capture time is invalid.');
    }
}

function vp3_browser_share_validate_capture_v2010(array $input): array
{
    $type=trim((string)($input['share_type']??''));
    if($type!=='selection')throw new VP3BrowserShareExceptionV2010('unsupported_share_type',422,'This Browser Companion version currently supports highlighted text shares.');

    $source=is_array($input['source']??null)?$input['source']:[];
    $snapshot=is_array($input['snapshot']??null)?$input['snapshot']:[];
    $message=is_array($input['message']??null)?$input['message']:[];

    $sourceInfo=vp3_browser_share_validate_url_v2010($source['url']??'');
    $canonicalRaw=trim((string)($source['canonical_url']??''));
    $canonical=$canonicalRaw!==''?vp3_browser_share_validate_url_v2010($canonicalRaw):$sourceInfo;

    $title=trim((string)($source['title']??''));
    if(mb_strlen($title)>VP3_BROWSER_SHARE_TITLE_MAX_CHARS_V2010)throw new VP3BrowserShareExceptionV2010('invalid_request',422,'Source title is too long.');
    if(str_contains($title,"\0"))throw new VP3BrowserShareExceptionV2010('invalid_request',422,'Source title is invalid.');

    $selection=(string)($snapshot['selected_text']??'');
    if(trim($selection)==='')throw new VP3BrowserShareExceptionV2010('invalid_request',422,'Highlighted text is required.');
    if(strlen($selection)>VP3_BROWSER_SHARE_SELECTED_MAX_BYTES_V2010)throw new VP3BrowserShareExceptionV2010('payload_too_large',413,'Highlighted text is too large to share.');
    if(str_contains($selection,"\0"))throw new VP3BrowserShareExceptionV2010('invalid_request',422,'Highlighted text contains unsupported characters.');

    $note=(string)($message['note']??'');
    if(strlen($note)>VP3_BROWSER_SHARE_NOTE_MAX_BYTES_V2010)throw new VP3BrowserShareExceptionV2010('payload_too_large',413,'Share note is too large.');
    if(str_contains($note,"\0"))throw new VP3BrowserShareExceptionV2010('invalid_request',422,'Share note contains unsupported characters.');

    $capturedAt=vp3_browser_share_capture_time_v2010($snapshot['captured_at']??null);
    $snapshotPayload=[
        'share_type'=>$type,
        'source_url'=>$sourceInfo['url'],
        'canonical_url'=>$canonical['url'],
        'source_title'=>$title,
        'source_domain'=>$sourceInfo['domain'],
        'selected_text'=>$selection,
        'captured_at'=>$capturedAt,
    ];
    $encoded=json_encode($snapshotPayload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($encoded))throw new VP3BrowserShareExceptionV2010('invalid_request',422,'Browser Share could not be encoded.');

    $normalizedSelection=preg_replace('/\s+/u',' ',trim($selection))??trim($selection);
    $dedupe=hash('sha256',$type."\n".strtolower($canonical['url'])."\n".$normalizedSelection);

    return $snapshotPayload+[
        'user_note'=>$note,
        'snapshot_hash'=>hash('sha256',$encoded),
        'dedupe_fingerprint'=>$dedupe,
    ];
}

function vp3_browser_share_fallback_body_v2010(array $capture): string
{
    $title=trim((string)($capture['source_title']??''));
    $domain=(string)($capture['source_domain']??'');
    $url=(string)($capture['source_url']??'');
    $label=$title!==''?$title:$domain;
    return mb_substr("Shared from the web".($label!==''?": ".$label:'')."\n".$url,0,3900);
}

function vp3_browser_share_conversation_sendable_v2010(PDO $pdo,array $conversation,int $userId): bool
{
    if(!vp3_human_can_access_v370($pdo,$conversation,$userId))return false;
    if((string)($conversation['conversation_type']??'')!=='direct')return true;

    $other=vp3_human_direct_other_v370($conversation,$userId);
    if($other<1||vp3_human_blocked_v370($pdo,$userId,$other))return false;
    $request=vp3_human_request_v370($pdo,(int)$conversation['id']);
    if($request){
        $status=(string)($request['status']??'');
        if($status==='pending'){
            return (int)($request['requester_user_id']??0)===$userId
                && (int)($request['initial_message_id']??0)<1;
        }
        if($status==='declined')return vp3_human_dm_route_v370($pdo,$userId,$other)==='direct';
        return $status==='accepted';
    }
    return vp3_human_dm_route_v370($pdo,$userId,$other)!=='deny';
}

function vp3_browser_share_destination_conversation_v2010(PDO $pdo,int $userId,array $destination): array
{
    $kind=trim((string)($destination['kind']??''));
    $id=(int)($destination['id']??0);
    if($id<1)throw new VP3BrowserShareExceptionV2010('destination_not_found',404,'Share destination was not found.');

    if($kind==='team_general'){
        try{return vp3_human_team_general_v370($pdo,$id,$userId);}
        catch(Throwable $e){throw new VP3BrowserShareExceptionV2010('destination_denied',403,'You cannot share to that Team workspace.');}
    }
    if($kind==='conversation'){
        // Do not lock the conversation here. Canonical Human Messaging owns the
        // user/workspace -> conversation lock order and will revalidate on send.
        $conversation=vp3_human_conversation_v370($pdo,$id,false);
        if(!$conversation||!vp3_browser_share_conversation_sendable_v2010($pdo,$conversation,$userId)){
            throw new VP3BrowserShareExceptionV2010('destination_denied',403,'You cannot share to that conversation.');
        }
        return $conversation;
    }
    throw new VP3BrowserShareExceptionV2010('invalid_request',422,'Choose a valid share destination.');
}

function vp3_browser_share_destinations_v2010(PDO $pdo,int $userId): array
{
    vp3_browser_share_require_ready_v2010($pdo);
    if($userId<1)throw new VP3BrowserShareExceptionV2010('authentication_required',401,'Browser Companion authentication is required.');

    $teams=[];
    foreach(vp3_human_team_workspaces_v370($pdo,$userId) as $workspace){
        $ownerId=(int)($workspace['owner_user_id']??0);
        if($ownerId<1||!vp3_human_team_authorized_v370($pdo,$ownerId,$userId))continue;
        $teams[]=['kind'=>'team_general','id'=>$ownerId,'name'=>(string)($workspace['workspace_name']??'Team').' · General'];
    }

    $stmt=$pdo->prepare("SELECT c.id,c.conversation_type,c.title,c.direct_user_low_id,c.direct_user_high_id,c.workspace_owner_user_id,c.updated_at,
      CASE WHEN c.direct_user_low_id=? THEN c.direct_user_high_id ELSE c.direct_user_low_id END other_user_id,
      u.display_name other_name
      FROM human_conversations c
      INNER JOIN human_conversation_members cm ON cm.conversation_id=c.id AND cm.user_id=? AND cm.left_at IS NULL
      LEFT JOIN users u ON u.id=CASE WHEN c.direct_user_low_id=? THEN c.direct_user_high_id ELSE c.direct_user_low_id END
      WHERE c.conversation_type IN ('direct','group')
      ORDER BY c.updated_at DESC,c.id DESC LIMIT 50");
    $stmt->execute([$userId,$userId,$userId]);
    $conversations=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $conversation=vp3_human_conversation_v370($pdo,(int)$row['id']);
        if(!$conversation||!vp3_browser_share_conversation_sendable_v2010($pdo,$conversation,$userId))continue;
        $type=(string)$row['conversation_type'];
        $name=$type==='direct'?(string)($row['other_name']??'Conversation'):(string)($row['title']??'Conversation');
        $conversations[]=['kind'=>'conversation','id'=>(int)$row['id'],'name'=>$name,'conversation_type'=>$type];
    }

    return ['recent'=>array_slice($conversations,0,10),'teams'=>$teams,'conversations'=>$conversations];
}

function vp3_browser_share_existing_result_v2010(PDO $pdo,int $deviceDbId,string $key): ?array
{
    $stmt=$pdo->prepare("SELECT i.browser_share_id,i.human_message_id,s.public_id,s.share_type,s.snapshot_hash,m.conversation_id
      FROM browser_share_idempotency_v2010 i
      LEFT JOIN browser_shares_v2010 s ON s.id=i.browser_share_id
      LEFT JOIN human_messages m ON m.id=i.human_message_id
      WHERE i.device_id=? AND i.idempotency_key=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$deviceDbId,$key]);
    $row=$stmt->fetch();
    if(!$row||(int)($row['browser_share_id']??0)<1||(int)($row['human_message_id']??0)<1)return null;
    return [
        'browser_share'=>['id'=>(string)$row['public_id'],'type'=>(string)$row['share_type'],'snapshot_hash'=>(string)$row['snapshot_hash']],
        'chat_message'=>['id'=>(int)$row['human_message_id'],'conversation_id'=>(int)$row['conversation_id']],
        'idempotent_replay'=>true,
    ];
}

function vp3_browser_share_enforce_rate_v2010(PDO $pdo,int $deviceDbId): void
{
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM browser_shares_v2010 WHERE device_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)');
    $stmt->execute([$deviceDbId]);
    if((int)$stmt->fetchColumn()>=VP3_BROWSER_SHARE_HOURLY_LIMIT_V2010){
        throw new VP3BrowserShareExceptionV2010('rate_limited',429,'This browser has created too many shares recently. Try again later.');
    }
}

function vp3_browser_share_create_v2010(PDO $pdo,array $session,array $input,string $idempotencyKey): array
{
    vp3_browser_share_require_ready_v2010($pdo);
    vp3_browser_share_require_capability_v2010($session,'team.share.create');
    if(!vp3_extension_valid_uuid_v2000($idempotencyKey))throw new VP3BrowserShareExceptionV2010('invalid_request',422,'A valid X-VP3-Idempotency-Key is required.');

    $userId=(int)($session['user_id']??0);
    if($userId<1)throw new VP3BrowserShareExceptionV2010('authentication_required',401,'Browser Companion authentication is required.');
    $deviceDbId=vp3_browser_share_device_db_id_v2010($pdo,$session);
    $capture=vp3_browser_share_validate_capture_v2010($input);
    $destination=is_array($input['destination']??null)?$input['destination']:[];
    vp3_browser_share_enforce_rate_v2010($pdo,$deviceDbId);

    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $pdo->prepare("DELETE FROM browser_share_idempotency_v2010 WHERE device_id=? AND idempotency_key=? AND browser_share_id IS NULL AND human_message_id IS NULL AND expires_at<=NOW()")
            ->execute([$deviceDbId,$idempotencyKey]);
        $pdo->prepare("INSERT IGNORE INTO browser_share_idempotency_v2010 (device_id,idempotency_key,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 7 DAY))")
            ->execute([$deviceDbId,$idempotencyKey]);

        $existing=vp3_browser_share_existing_result_v2010($pdo,$deviceDbId,$idempotencyKey);
        if($existing){
            if($owns)$pdo->commit();
            return $existing;
        }

        $conversation=vp3_browser_share_destination_conversation_v2010($pdo,$userId,$destination);
        try{
            // This canonical helper revalidates permissions and owns the established
            // user/workspace -> conversation locking order. Because our transaction
            // is already open, its message insert participates in this transaction.
            $message=vp3_human_send_message_v370($pdo,(int)$conversation['id'],$userId,vp3_browser_share_fallback_body_v2010($capture));
        }catch(PDOException $e){
            throw $e;
        }catch(Throwable $e){
            throw new VP3BrowserShareExceptionV2010('destination_denied',403,'This Browser Share cannot be posted to that conversation.');
        }

        $publicId=vp3_extension_uuid_v2000();
        $stmt=$pdo->prepare("INSERT INTO browser_shares_v2010
          (public_id,sender_user_id,device_id,share_type,source_url,canonical_url,source_title,source_domain,selected_text,user_note,captured_at,snapshot_hash,dedupe_fingerprint)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $publicId,$userId,$deviceDbId,$capture['share_type'],$capture['source_url'],$capture['canonical_url'],$capture['source_title'],$capture['source_domain'],
            $capture['selected_text'],$capture['user_note'],$capture['captured_at'],$capture['snapshot_hash'],$capture['dedupe_fingerprint'],
        ]);
        $shareDbId=(int)$pdo->lastInsertId();
        $messageId=(int)$message['id'];
        $pdo->prepare('INSERT INTO human_message_browser_shares_v2010 (human_message_id,browser_share_id) VALUES (?,?)')->execute([$messageId,$shareDbId]);
        $pdo->prepare('UPDATE browser_share_idempotency_v2010 SET browser_share_id=?,human_message_id=? WHERE device_id=? AND idempotency_key=?')
            ->execute([$shareDbId,$messageId,$deviceDbId,$idempotencyKey]);

        if($owns)$pdo->commit();
        return [
            'browser_share'=>['id'=>$publicId,'type'=>$capture['share_type'],'snapshot_hash'=>$capture['snapshot_hash']],
            'chat_message'=>['id'=>$messageId,'conversation_id'=>(int)$conversation['id']],
            'idempotent_replay'=>false,
        ];
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        if($e instanceof VP3BrowserShareExceptionV2010)throw $e;
        throw new VP3BrowserShareExceptionV2010('service_unavailable',503,'Browser Share could not be created.');
    }
}

function vp3_browser_share_for_message_v2010(PDO $pdo,int $messageId,int $userId): ?array
{
    vp3_browser_share_require_ready_v2010($pdo);
    if($messageId<1||$userId<1)return null;
    $stmt=$pdo->prepare("SELECT s.*,l.human_message_id,m.conversation_id,m.deleted_at message_deleted_at
      FROM human_message_browser_shares_v2010 l
      INNER JOIN browser_shares_v2010 s ON s.id=l.browser_share_id
      INNER JOIN human_messages m ON m.id=l.human_message_id
      WHERE l.human_message_id=? AND s.deleted_at IS NULL LIMIT 1");
    $stmt->execute([$messageId]);
    $row=$stmt->fetch();
    if(!$row||!empty($row['message_deleted_at']))return null;
    $conversation=vp3_human_conversation_v370($pdo,(int)$row['conversation_id']);
    if(!$conversation||!vp3_human_can_access_v370($pdo,$conversation,$userId))return null;
    return $row;
}

function vp3_browser_share_by_public_id_v2010(PDO $pdo,string $publicId,int $userId): ?array
{
    vp3_browser_share_require_ready_v2010($pdo);
    if(!vp3_extension_valid_uuid_v2000($publicId)||$userId<1)return null;
    $stmt=$pdo->prepare("SELECT l.human_message_id FROM browser_shares_v2010 s
      INNER JOIN human_message_browser_shares_v2010 l ON l.browser_share_id=s.id
      WHERE s.public_id=? AND s.deleted_at IS NULL LIMIT 1");
    $stmt->execute([$publicId]);
    $messageId=(int)($stmt->fetchColumn()?:0);
    return $messageId>0?vp3_browser_share_for_message_v2010($pdo,$messageId,$userId):null;
}

function vp3_browser_share_recent_duplicate_v2010(PDO $pdo,int $userId,string $dedupeFingerprint,int $withinDays=30): ?array
{
    if($userId<1||!preg_match('/^[a-f0-9]{64}$/',$dedupeFingerprint))return null;
    $withinDays=max(1,min(90,$withinDays));
    $stmt=$pdo->prepare("SELECT public_id,source_title,source_domain,created_at FROM browser_shares_v2010
      WHERE sender_user_id=? AND dedupe_fingerprint=? AND deleted_at IS NULL AND created_at>=DATE_SUB(NOW(),INTERVAL {$withinDays} DAY)
      ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId,$dedupeFingerprint]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}
