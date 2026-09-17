<?php
declare(strict_types=1);

/**
 * VP3 v20.11 Browser Share security/correctness hardening.
 *
 * v20.10 owns the Browser Share data model. This layer tightens runtime policy
 * without adding a second collaboration store: live extension authorization,
 * request-bound idempotency, capture-time integrity, lifecycle cleanup, and
 * metadata-only server audit events.
 */
const VP3_BROWSER_SHARE_HARDENING_V2011 = 'browser-share-hardening-v2011-20260917';
const VP3_BROWSER_SHARE_CAPTURE_MAX_AGE_SECONDS_V2011 = 86400;
const VP3_BROWSER_SHARE_CAPTURE_FUTURE_SKEW_SECONDS_V2011 = 300;

function vp3_browser_share_require_capability_v2011(array $session,string $capability): void
{
    if(!vp3_extension_session_has_capability_v2001($session,$capability)){
        throw new VP3BrowserShareExceptionV2010('capability_denied',403,'This browser connection does not have permission for that action.');
    }
}

function vp3_browser_share_validate_capture_v2011(array $input): array
{
    $capture=vp3_browser_share_validate_capture_v2010($input);
    $zone=new DateTimeZone('UTC');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$capture['captured_at'],$zone);
    if(!$date){
        throw new VP3BrowserShareExceptionV2010('invalid_request',422,'Capture time is invalid.');
    }

    $captured=$date->getTimestamp();
    $now=time();
    if($captured>$now+VP3_BROWSER_SHARE_CAPTURE_FUTURE_SKEW_SECONDS_V2011){
        throw new VP3BrowserShareExceptionV2010('invalid_request',422,'Capture time is too far in the future.');
    }
    if($captured<$now-VP3_BROWSER_SHARE_CAPTURE_MAX_AGE_SECONDS_V2011){
        throw new VP3BrowserShareExceptionV2010('stale_capture',422,'This browser capture is too old to share. Capture it again from the current page.');
    }
    return $capture;
}

function vp3_browser_share_destination_descriptor_v2011(array $destination): array
{
    $kind=trim((string)($destination['kind']??''));
    $id=(int)($destination['id']??0);
    if(!in_array($kind,['team_general','conversation'],true)||$id<1){
        throw new VP3BrowserShareExceptionV2010('invalid_request',422,'Choose a valid share destination.');
    }
    return ['kind'=>$kind,'id'=>$id];
}

/**
 * The existing operation column is 40 bytes. Prefix + 144 bits of SHA-256 fits
 * exactly, binding an idempotency key to the normalized payload without storing
 * captured text, URL or note in the retry ledger.
 */
function vp3_browser_share_request_operation_v2011(array $capture,array $destination): string
{
    $payload=[
        'schema_version'=>1,
        'share_type'=>(string)$capture['share_type'],
        'snapshot_hash'=>(string)$capture['snapshot_hash'],
        'note_hash'=>hash('sha256',(string)$capture['user_note']),
        'destination'=>vp3_browser_share_destination_descriptor_v2011($destination),
    ];
    $encoded=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($encoded)){
        throw new VP3BrowserShareExceptionV2010('invalid_request',422,'Browser Share request could not be encoded.');
    }
    return 'bs1:'.substr(hash('sha256',$encoded),0,36);
}

function vp3_browser_share_cleanup_retention_v2011(PDO $pdo): void
{
    // Retry reservations are operational metadata only and have a seven-day TTL.
    $pdo->exec('DELETE FROM browser_share_idempotency_v2010 WHERE expires_at<=NOW()');

    // Browser Share content follows the canonical message lifecycle. A soft-
    // deleted message immediately makes the immutable capture unavailable and
    // scrubs the captured content while retaining non-content integrity hashes.
    $pdo->exec("UPDATE browser_shares_v2010 s
      INNER JOIN human_message_browser_shares_v2010 l ON l.browser_share_id=s.id
      INNER JOIN human_messages m ON m.id=l.human_message_id
      SET s.source_url='',s.canonical_url='',s.source_title='',s.source_domain='',
          s.selected_text='',s.user_note='',s.deleted_at=COALESCE(s.deleted_at,m.deleted_at,NOW())
      WHERE s.deleted_at IS NULL AND m.deleted_at IS NOT NULL");

    // A hard-deleted canonical message cascades its link row. Do not leave the
    // source snapshot orphaned indefinitely if that happens.
    $pdo->exec("UPDATE browser_shares_v2010 s
      LEFT JOIN human_message_browser_shares_v2010 l ON l.browser_share_id=s.id
      SET s.source_url='',s.canonical_url='',s.source_title='',s.source_domain='',
          s.selected_text='',s.user_note='',s.deleted_at=COALESCE(s.deleted_at,NOW())
      WHERE s.deleted_at IS NULL AND l.browser_share_id IS NULL");
}

function vp3_browser_share_existing_result_v2011(PDO $pdo,int $deviceDbId,string $key,string $operation): ?array
{
    $stmt=$pdo->prepare("SELECT i.operation,i.browser_share_id,i.human_message_id,s.public_id,s.share_type,s.snapshot_hash,m.conversation_id
      FROM browser_share_idempotency_v2010 i
      LEFT JOIN browser_shares_v2010 s ON s.id=i.browser_share_id
      LEFT JOIN human_messages m ON m.id=i.human_message_id
      WHERE i.device_id=? AND i.idempotency_key=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$deviceDbId,$key]);
    $row=$stmt->fetch();
    if(!$row)return null;

    $stored=(string)($row['operation']??'');
    if($stored===''||!hash_equals($operation,$stored)){
        throw new VP3BrowserShareExceptionV2010(
            'idempotency_conflict',
            409,
            'That idempotency key was already used for a different Browser Share request.'
        );
    }

    if((int)($row['browser_share_id']??0)<1||(int)($row['human_message_id']??0)<1)return null;
    return [
        'browser_share'=>[
            'id'=>(string)$row['public_id'],
            'type'=>(string)$row['share_type'],
            'snapshot_hash'=>(string)$row['snapshot_hash'],
        ],
        'chat_message'=>[
            'id'=>(int)$row['human_message_id'],
            'conversation_id'=>(int)$row['conversation_id'],
        ],
        'idempotent_replay'=>true,
    ];
}

function vp3_browser_share_create_v2011(PDO $pdo,array $session,array $input,string $idempotencyKey): array
{
    vp3_browser_share_require_ready_v2010($pdo);
    vp3_browser_share_require_capability_v2011($session,'team.share.create');
    if(!vp3_extension_valid_uuid_v2000($idempotencyKey)){
        throw new VP3BrowserShareExceptionV2010('invalid_request',422,'A valid X-VP3-Idempotency-Key is required.');
    }

    $userId=(int)($session['user_id']??0);
    if($userId<1){
        throw new VP3BrowserShareExceptionV2010('authentication_required',401,'Browser Companion authentication is required.');
    }

    $deviceDbId=vp3_browser_share_device_db_id_v2010($pdo,$session);
    $capture=vp3_browser_share_validate_capture_v2011($input);
    $destination=vp3_browser_share_destination_descriptor_v2011(
        is_array($input['destination']??null)?$input['destination']:[]
    );
    $operation=vp3_browser_share_request_operation_v2011($capture,$destination);

    // Cleanup is intentionally outside the create transaction. It never creates
    // schema and never copies captured content into another persistence domain.
    vp3_browser_share_cleanup_retention_v2011($pdo);

    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        // Once the seven-day retry window expires the key may be reused. A live
        // key remains bound to exactly one normalized request.
        $pdo->prepare('DELETE FROM browser_share_idempotency_v2010 WHERE device_id=? AND idempotency_key=? AND expires_at<=NOW()')
            ->execute([$deviceDbId,$idempotencyKey]);
        $pdo->prepare("INSERT IGNORE INTO browser_share_idempotency_v2010
          (device_id,idempotency_key,operation,expires_at)
          VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 7 DAY))")
            ->execute([$deviceDbId,$idempotencyKey,$operation]);

        $existing=vp3_browser_share_existing_result_v2011($pdo,$deviceDbId,$idempotencyKey,$operation);
        if($existing){
            if($owns)$pdo->commit();
            return $existing;
        }

        vp3_browser_share_enforce_rate_v2010($pdo,$deviceDbId);
        $conversation=vp3_browser_share_destination_conversation_v2010($pdo,$userId,$destination);
        try{
            $message=vp3_human_send_message_v370(
                $pdo,
                (int)$conversation['id'],
                $userId,
                vp3_browser_share_fallback_body_v2010($capture)
            );
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
            $publicId,$userId,$deviceDbId,$capture['share_type'],$capture['source_url'],$capture['canonical_url'],
            $capture['source_title'],$capture['source_domain'],$capture['selected_text'],$capture['user_note'],
            $capture['captured_at'],$capture['snapshot_hash'],$capture['dedupe_fingerprint'],
        ]);
        $shareDbId=(int)$pdo->lastInsertId();
        $messageId=(int)$message['id'];
        $pdo->prepare('INSERT INTO human_message_browser_shares_v2010 (human_message_id,browser_share_id) VALUES (?,?)')
            ->execute([$messageId,$shareDbId]);
        $updated=$pdo->prepare("UPDATE browser_share_idempotency_v2010
          SET browser_share_id=?,human_message_id=?
          WHERE device_id=? AND idempotency_key=? AND operation=?");
        $updated->execute([$shareDbId,$messageId,$deviceDbId,$idempotencyKey,$operation]);
        if($updated->rowCount()!==1){
            throw new VP3BrowserShareExceptionV2010('service_unavailable',503,'Browser Share retry state could not be finalized.');
        }

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

function vp3_browser_share_destinations_v2011(PDO $pdo,array $session): array
{
    vp3_browser_share_require_ready_v2010($pdo);
    vp3_browser_share_require_capability_v2011($session,'team.destinations.read');
    $userId=(int)($session['user_id']??0);
    if($userId<1){
        throw new VP3BrowserShareExceptionV2010('authentication_required',401,'Browser Companion authentication is required.');
    }
    vp3_browser_share_cleanup_retention_v2011($pdo);
    return vp3_browser_share_destinations_v2010($pdo,$userId);
}

/**
 * Security audit event for server logs. Never include source URL/title/domain,
 * selected text, the user note, request body, bearer token or device credential.
 */
function vp3_browser_share_audit_event_v2011(string $event,array $session,array $result): void
{
    $payload=[
        'event'=>substr(trim($event),0,40),
        'user_id'=>(int)($session['user_id']??0),
        'device_id'=>(string)($session['device_id']??''),
        'browser_share_id'=>(string)($result['browser_share']['id']??''),
        'human_message_id'=>(int)($result['chat_message']['id']??0),
        'conversation_id'=>(int)($result['chat_message']['conversation_id']??0),
        'idempotent_replay'=>(bool)($result['idempotent_replay']??false),
        'at'=>gmdate(DATE_ATOM),
    ];
    error_log('VP3_BROWSER_SHARE_AUDIT '.json_encode($payload,JSON_UNESCAPED_SLASHES));
}
