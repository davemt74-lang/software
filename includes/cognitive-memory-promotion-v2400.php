<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.00 — Memory Promotion & Episodic Consolidation.
 *
 * Uses Cognitive Memory v5.70 as the episodic/reference-first layer and the
 * existing agent_memory_items Brain store as the only durable semantic memory.
 * This file records promotion receipts/decisions; it is not a second Brain.
 */
const VP3_COGNITIVE_MEMORY_PROMOTION_V2400='vp3-cognitive-memory-promotion-v2400-20260922';
const VP3_COGNITIVE_MEMORY_PROMOTION_SCAN_LIMIT_V2400=180;
const VP3_COGNITIVE_MEMORY_PROMOTION_RECUR_THRESHOLD_V2400=3;
const VP3_COGNITIVE_MEMORY_PROMOTION_RECUR_DAYS_V2400=30;

function vp3_cognitive_memory_promotion_schema_ready_v2400(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && table_exists('cognitive_memory_promotion_receipts_v2400')
        && column_exists('cognitive_memory_promotion_receipts_v2400','source_event_id')
        && column_exists('cognitive_memory_promotion_receipts_v2400','decision')
        && column_exists('cognitive_memory_promotion_receipts_v2400','promoted_memory_id');
}

function vp3_cognitive_memory_promotion_ensure_schema_v2400(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!table_exists('users'))throw new RuntimeException('VP3 users must exist before Cognitive Memory Promotion.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_memory_promotion_receipts_v2400 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      source_event_id BIGINT UNSIGNED NOT NULL,
      source_event_uuid CHAR(36) NOT NULL DEFAULT '',
      source_kind VARCHAR(80) NOT NULL DEFAULT '',
      event_type VARCHAR(120) NOT NULL DEFAULT '',
      object_type VARCHAR(80) NOT NULL DEFAULT '',
      object_id VARCHAR(190) NOT NULL DEFAULT '',
      object_scope VARCHAR(40) NOT NULL DEFAULT 'personal',
      policy_class VARCHAR(32) NOT NULL DEFAULT 'transient',
      salience_score DECIMAL(5,4) NOT NULL DEFAULT 0,
      recurrence_count INT UNSIGNED NOT NULL DEFAULT 0,
      decision VARCHAR(24) NOT NULL DEFAULT 'evaluating',
      reason_code VARCHAR(80) NOT NULL DEFAULT '',
      occurrence_key CHAR(64) NOT NULL DEFAULT '',
      promotion_subject VARCHAR(190) NOT NULL DEFAULT '',
      promoted_memory_id BIGINT UNSIGNED NULL,
      evaluated_at DATETIME NULL,
      promoted_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_cognitive_promotion_event_v2400 (owner_user_id,agent_namespace,source_event_id),
      INDEX idx_cognitive_promotion_owner_v2400 (owner_user_id,agent_namespace,decision,updated_at,id),
      INDEX idx_cognitive_promotion_object_v2400 (owner_user_id,object_type,object_id,event_type,created_at),
      CONSTRAINT fk_cognitive_promotion_owner_v2400 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_cognitive_memory_promotion_policy_v2400(string $eventType): array
{
    $eventType=strtolower(trim($eventType));
    $durable=[
        'profile.booking_converted'=>[.96,'conversion_terminal'],
        'profile.product_converted'=>[.96,'conversion_terminal'],
        'booking.completed'=>[.90,'booking_terminal'],
        'booking.no_show'=>[.84,'booking_terminal_exception'],
        'order.paid'=>[.97,'commerce_terminal'],
        'refund.completed'=>[.91,'commerce_terminal'],
        'refund.failed'=>[.91,'commerce_exception'],
        'conversion.attributed'=>[.95,'attribution_terminal'],
        'research.report_published'=>[.87,'research_publication'],
        'browser.transaction_completed'=>[.86,'transaction_terminal'],
    ];
    if(isset($durable[$eventType]))return [
        'class'=>'durable','salience'=>$durable[$eventType][0],'reason'=>$durable[$eventType][1],'threshold'=>1,
    ];

    $recurring=[
        'relationship.risk_changed'=>[.76,'relationship_risk_pattern'],
        'relationship.opportunity_changed'=>[.72,'relationship_opportunity_pattern'],
        'booking.cancelled'=>[.73,'booking_cancellation_pattern'],
        'booking.rescheduled'=>[.68,'booking_reschedule_pattern'],
        'refund.requested'=>[.70,'refund_pattern'],
        'browser.transaction_changed'=>[.68,'transaction_change_pattern'],
    ];
    if(isset($recurring[$eventType]))return [
        'class'=>'recurring','salience'=>$recurring[$eventType][0],'reason'=>$recurring[$eventType][1],
        'threshold'=>VP3_COGNITIVE_MEMORY_PROMOTION_RECUR_THRESHOLD_V2400,
    ];

    $episodic=[
        'booking.confirmed','order.payment_received','order.expired','research.finding_status_changed',
        'team.member_joined','team.member_left','homeserver.connected','homeserver.disconnected',
        'analytics.signal_detected','referral.attributed','appointment.payment_changed',
    ];
    if(in_array($eventType,$episodic,true))return [
        'class'=>'episodic','salience'=>.58,'reason'=>'episodic_only','threshold'=>PHP_INT_MAX,
    ];

    return ['class'=>'transient','salience'=>.20,'reason'=>'routine_state','threshold'=>PHP_INT_MAX];
}

function vp3_cognitive_memory_promotion_label_v2400(string $eventType): string
{
    return [
        'profile.booking_converted'=>'A Profile Agent interaction converted into a booking.',
        'profile.product_converted'=>'A Profile Agent interaction converted into a product purchase.',
        'booking.completed'=>'A booking reached completed status.',
        'booking.no_show'=>'A booking ended as a no-show.',
        'booking.cancelled'=>'Booking cancellations have become a recurring pattern.',
        'booking.rescheduled'=>'Booking reschedules have become a recurring pattern.',
        'order.paid'=>'A commerce order reached paid status.',
        'refund.requested'=>'Refund requests have become a recurring pattern.',
        'refund.completed'=>'A refund completed.',
        'refund.failed'=>'A refund failed and may need follow-up.',
        'relationship.risk_changed'=>'Relationship risk changes have become a recurring pattern.',
        'relationship.opportunity_changed'=>'Relationship opportunity changes have become a recurring pattern.',
        'conversion.attributed'=>'A referral was attributed to a conversion.',
        'research.report_published'=>'A Research report was published.',
        'browser.transaction_completed'=>'A tracked browser transaction reached a terminal completed state.',
        'browser.transaction_changed'=>'Tracked browser transactions have shown repeated meaningful changes.',
    ][$eventType]??'A meaningful cross-system event became durable Agent memory.';
}

function vp3_cognitive_memory_promotion_outcome_v2400(string $eventType): string
{
    if(in_array($eventType,['booking.completed','order.paid','refund.completed','conversion.attributed','profile.booking_converted','profile.product_converted','research.report_published','browser.transaction_completed'],true))return 'successful';
    if(in_array($eventType,['booking.no_show','refund.failed'],true))return 'unsuccessful';
    if(in_array($eventType,['booking.cancelled'],true))return 'resolved';
    return '';
}

function vp3_cognitive_memory_promotion_event_refs_v2400(array $event): array
{
    $payload=is_array($event['payload']??null)?$event['payload']:[];
    $out=[];
    foreach((array)($payload['object_refs']??[]) as $ref){
        if(!is_array($ref))continue;
        $type=trim((string)($ref['type']??''));$id=trim((string)($ref['id']??''));$scope=trim((string)($ref['scope']??'personal'));
        if($type===''||$id==='')continue;
        $out[]=['type'=>$type,'id'=>$id,'scope'=>$scope?:'personal'];
        if(count($out)>=16)break;
    }
    return $out;
}

function vp3_cognitive_memory_promotion_primary_ref_v2400(PDO $pdo,array $user,string $namespace,array $event): ?array
{
    foreach(vp3_cognitive_memory_promotion_event_refs_v2400($event) as $ref){
        try{
            $ref=vp3_cognitive_validate_object_ref_v500($ref,true);
            if(vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$ref,'read'))return $ref;
        }catch(Throwable $e){}
    }
    return null;
}

function vp3_cognitive_memory_promotion_claim_v2400(PDO $pdo,int $uid,string $namespace,array $event,array $policy,?array $ref): ?array
{
    $id=(int)($event['id']??0);if($uid<1||$id<1)return null;
    $stmt=$pdo->prepare("INSERT IGNORE INTO cognitive_memory_promotion_receipts_v2400
      (owner_user_id,agent_namespace,source_event_id,source_event_uuid,source_kind,event_type,object_type,object_id,object_scope,policy_class,salience_score,decision,reason_code)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,'evaluating',?)");
    $stmt->execute([
        $uid,$namespace,$id,(string)($event['event_uuid']??''),(string)($event['source']??''),(string)($event['event_type']??''),
        (string)($ref['type']??''),(string)($ref['id']??''),(string)($ref['scope']??'personal'),
        (string)$policy['class'],(float)$policy['salience'],(string)$policy['reason'],
    ]);
    if($stmt->rowCount()<1)return null;
    $rid=(int)$pdo->lastInsertId();
    $get=$pdo->prepare('SELECT * FROM cognitive_memory_promotion_receipts_v2400 WHERE id=? LIMIT 1');$get->execute([$rid]);
    return $get->fetch()?:null;
}

function vp3_cognitive_memory_promotion_recurrence_v2400(PDO $pdo,int $uid,string $namespace,string $eventType,array $ref): int
{
    $crossObject=in_array($eventType,['booking.cancelled','booking.rescheduled','refund.requested'],true);
    if($crossObject){
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM cognitive_memory_promotion_receipts_v2400
          WHERE owner_user_id=? AND agent_namespace=? AND event_type=? AND object_type=? AND object_scope=?
            AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".VP3_COGNITIVE_MEMORY_PROMOTION_RECUR_DAYS_V2400." DAY)");
        $stmt->execute([$uid,$namespace,$eventType,(string)$ref['type'],(string)$ref['scope']]);
    }else{
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM cognitive_memory_promotion_receipts_v2400
          WHERE owner_user_id=? AND agent_namespace=? AND event_type=? AND object_type=? AND object_id=? AND object_scope=?
            AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".VP3_COGNITIVE_MEMORY_PROMOTION_RECUR_DAYS_V2400." DAY)");
        $stmt->execute([$uid,$namespace,$eventType,(string)$ref['type'],(string)$ref['id'],(string)$ref['scope']]);
    }
    return max(1,(int)$stmt->fetchColumn());
}

function vp3_cognitive_memory_promotion_episode_v2400(PDO $pdo,array $user,string $namespace,array $event,array $ref): string
{
    if(!function_exists('vp3_cognitive_memory_schema_ready_v570')||!vp3_cognitive_memory_schema_ready_v570($pdo))return '';
    $uid=(int)$user['id'];$source=(string)($event['source']??'domain_event');$type=(string)$ref['type'];
    $threadKey=vp3_cognitive_memory_thread_key_v570('object_continuity','',$type,(string)$ref['id'],(string)$ref['scope']);
    $thread=vp3_cognitive_memory_thread_v570($pdo,$uid,$namespace,'object_continuity',$threadKey,$source,$type);
    if(!$thread)return '';
    $uuid=(string)($event['event_uuid']??'');$occurrenceKey=hash('sha256','domain-event|'.$uuid);
    $fingerprint=hash('sha256','event|'.(string)($event['source']??'').'|'.(string)($event['event_type']??'').'|'.(string)($event['external_event_id']??'').'|'.$uuid);
    vp3_cognitive_memory_occurrence_v570(
        $pdo,$thread,$uid,'domain_event',$source,$ref,
        (string)($event['occurred_at']?:$event['received_at']?:gmdate('Y-m-d H:i:s')),
        $occurrenceKey,'event:'.(string)$event['event_type'],$fingerprint,
        vp3_cognitive_memory_promotion_outcome_v2400((string)$event['event_type']),
        'agent_event',$uuid
    );
    return $occurrenceKey;
}

function vp3_cognitive_memory_promotion_write_brain_v2400(
    PDO $pdo,array $user,array $event,array $ref,array $policy,int $recurrence
): array {
    if(!function_exists('agent_brain_schema_ready')||!agent_brain_schema_ready()
        ||!function_exists('agent_brain_v122_memory_hash')
        ||!function_exists('vp3_agent_memory_scope_hash_v410')
        ||!function_exists('vp3_agent_memory_scope_provenance_v410')){
        return ['memory_id'=>0,'subject'=>''];
    }
    $uid=(int)($user['id']??0);if($uid<1)return ['memory_id'=>0,'subject'=>''];
    $eventType=(string)$event['event_type'];
    $crossObject=in_array($eventType,['booking.cancelled','booking.rescheduled','refund.requested'],true);
    $identity=$crossObject?'pattern':substr(hash('sha256',(string)$ref['id']),0,24);
    $subject=mb_strimwidth('cognitive-promotion:'.$eventType.':'.$ref['type'].':'.$identity,0,190,'');
    $text=vp3_cognitive_memory_promotion_label_v2400($eventType);
    $metadata=[
        'source'=>'cognitive_memory_promotion_v2400',
        'event_type'=>$eventType,
        'event_source'=>(string)($event['source']??''),
        'object_ref'=>$ref,
        'last_event_uuid'=>(string)($event['event_uuid']??''),
        'recurrence_count'=>$recurrence,
        'policy_class'=>(string)$policy['class'],
        'promotion_reason'=>(string)$policy['reason'],
        'salience_score'=>(float)$policy['salience'],
        'raw_event_payload_copied'=>false,
        'build'=>VP3_COGNITIVE_MEMORY_PROMOTION_V2400,
    ];
    // Domain promotion is owner/system memory. Do not inherit whichever named
    // Agent happens to be the active chat scope when the cognitive loop runs.
    $hash=vp3_agent_memory_scope_hash_v410(0,agent_brain_v122_memory_hash('cognitive_episode',$subject));
    $metadata=vp3_agent_memory_scope_provenance_v410($metadata,0,0,0);
    $json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $confidence=max(.65,min(.99,(float)$policy['salience']));
    $stmt=$pdo->prepare(
        'INSERT INTO agent_memory_items
         (user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,memory_scope_version,source_archive_id,confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json)
         VALUES (?,NULL,?,?,?,?,410,NULL,?, ?,NOW(),NOW(),1,?)
         ON DUPLICATE KEY UPDATE
           id=LAST_INSERT_ID(id),memory_text=VALUES(memory_text),confidence=VALUES(confidence),
           occurrence_count=GREATEST(occurrence_count,VALUES(occurrence_count)),last_seen_at=NOW(),
           is_active=1,metadata_json=VALUES(metadata_json),memory_scope_version=410'
    );
    $stmt->execute([$uid,'cognitive_episode',$subject,$text,$hash,$confidence,max(1,$recurrence),is_string($json)?$json:'{}']);
    $memoryId=(int)$pdo->lastInsertId();
    if($memoryId<1){
        $q=$pdo->prepare('SELECT id FROM agent_memory_items WHERE user_id=? AND user_agent_id IS NULL AND memory_hash=? LIMIT 1');
        $q->execute([$uid,$hash]);$memoryId=(int)$q->fetchColumn();
    }
    return ['memory_id'=>$memoryId,'subject'=>$subject];
}

function vp3_cognitive_memory_promotion_evaluate_event_v2400(PDO $pdo,array $user,string $namespace,array $event): array
{
    $uid=(int)($user['id']??0);$eventId=(int)($event['id']??0);
    if($uid<1||$eventId<1)return ['decision'=>'skipped','reason'=>'invalid_event'];
    $payload=is_array($event['payload']??null)?$event['payload']:[];
    if(empty($payload['record_only'])||empty($payload['brain_promotion_deferred']))return ['decision'=>'skipped','reason'=>'not_deferred_domain_event'];

    $eventType=(string)($event['event_type']??'');
    $policy=vp3_cognitive_memory_promotion_policy_v2400($eventType);
    $ref=vp3_cognitive_memory_promotion_primary_ref_v2400($pdo,$user,$namespace,$event);
    $receipt=vp3_cognitive_memory_promotion_claim_v2400($pdo,$uid,$namespace,$event,$policy,$ref);
    if(!$receipt)return ['decision'=>'duplicate','reason'=>'already_evaluated'];

    if(!$ref){
        $pdo->prepare("UPDATE cognitive_memory_promotion_receipts_v2400 SET decision='suppressed',reason_code='no_authorized_object_ref',evaluated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$receipt['id']]);
        return ['decision'=>'suppressed','reason'=>'no_authorized_object_ref'];
    }

    $occurrenceKey=vp3_cognitive_memory_promotion_episode_v2400($pdo,$user,$namespace,$event,$ref);
    $recurrence=vp3_cognitive_memory_promotion_recurrence_v2400($pdo,$uid,$namespace,$eventType,$ref);
    $decision='episodic';$reason=(string)$policy['reason'];$memoryId=0;$subject='';

    if((string)$policy['class']==='transient'){
        $decision='suppressed';$reason='routine_state';
    }elseif((string)$policy['class']==='durable'){
        $write=vp3_cognitive_memory_promotion_write_brain_v2400($pdo,$user,$event,$ref,$policy,$recurrence);
        $memoryId=(int)$write['memory_id'];$subject=(string)$write['subject'];
        $decision=$memoryId>0?'promoted':'episodic';$reason=$memoryId>0?(string)$policy['reason']:'brain_unavailable';
    }elseif((string)$policy['class']==='recurring'&&$recurrence>=(int)$policy['threshold']){
        $write=vp3_cognitive_memory_promotion_write_brain_v2400($pdo,$user,$event,$ref,$policy,$recurrence);
        $memoryId=(int)$write['memory_id'];$subject=(string)$write['subject'];
        $decision=$memoryId>0?'promoted':'episodic';$reason=$memoryId>0?(string)$policy['reason']:'brain_unavailable';
    }elseif((string)$policy['class']==='recurring'){
        $reason='recurrence_below_threshold';
    }

    $pdo->prepare("UPDATE cognitive_memory_promotion_receipts_v2400 SET
      recurrence_count=?,decision=?,reason_code=?,occurrence_key=?,promotion_subject=?,promoted_memory_id=?,evaluated_at=UTC_TIMESTAMP(),
      promoted_at=IF(?='promoted',UTC_TIMESTAMP(),NULL)
      WHERE id=?")
      ->execute([$recurrence,$decision,$reason,$occurrenceKey,$subject,$memoryId>0?$memoryId:null,$decision,(int)$receipt['id']]);
    return ['decision'=>$decision,'reason'=>$reason,'memory_id'=>$memoryId,'recurrence_count'=>$recurrence,'event_id'=>$eventId];
}

function vp3_cognitive_memory_promotion_scan_v2400(PDO $pdo,array $user,string $namespace='system',int $limit=VP3_COGNITIVE_MEMORY_PROMOTION_SCAN_LIMIT_V2400): array
{
    $uid=(int)($user['id']??0);if($uid<1)return ['evaluated'=>0,'promoted'=>0,'episodic'=>0,'suppressed'=>0];
    if(!vp3_cognitive_memory_promotion_schema_ready_v2400($pdo)||!agent_event_schema_ready_v1920($pdo))return ['evaluated'=>0,'promoted'=>0,'episodic'=>0,'suppressed'=>0];
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $limit=max(1,min(500,$limit));
    $stmt=$pdo->prepare("SELECT e.* FROM agent_event_inbox e
      LEFT JOIN cognitive_memory_promotion_receipts_v2400 r
        ON r.owner_user_id=e.owner_user_id AND r.agent_namespace=? AND r.source_event_id=e.id
      WHERE e.owner_user_id=? AND e.processing_status='processed' AND e.verification_status IN ('trusted','verified')
        AND e.payload_json LIKE '%\"record_only\":true%'
        AND e.payload_json LIKE '%\"brain_promotion_deferred\":true%'
        AND r.id IS NULL
      ORDER BY e.id ASC LIMIT {$limit}");
    $stmt->execute([$namespace,$uid]);$rows=$stmt->fetchAll()?:[];
    $stats=['evaluated'=>0,'promoted'=>0,'episodic'=>0,'suppressed'=>0,'duplicates'=>0,'skipped'=>0];
    foreach($rows as $row){
        $event=agent_event_public_v1920($row);
        $result=vp3_cognitive_memory_promotion_evaluate_event_v2400($pdo,$user,$namespace,$event);
        $decision=(string)($result['decision']??'skipped');
        if(isset($stats[$decision]))$stats[$decision]++;
        elseif($decision==='duplicate')$stats['duplicates']++;
        else $stats['skipped']++;
        if(!in_array($decision,['skipped','duplicate'],true))$stats['evaluated']++;
    }
    return $stats;
}

function vp3_cognitive_memory_promotion_status_v2400(PDO $pdo,array $user,string $namespace='system'): array
{
    $uid=(int)($user['id']??0);if($uid<1||!vp3_cognitive_memory_promotion_schema_ready_v2400($pdo))return ['ready'=>false];
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $stmt=$pdo->prepare("SELECT decision,COUNT(*) c FROM cognitive_memory_promotion_receipts_v2400 WHERE owner_user_id=? AND agent_namespace=? GROUP BY decision");
    $stmt->execute([$uid,$namespace]);$counts=[];
    foreach($stmt->fetchAll()?:[] as $row)$counts[(string)$row['decision']]=(int)$row['c'];
    return ['ready'=>true,'build'=>VP3_COGNITIVE_MEMORY_PROMOTION_V2400,'namespace'=>$namespace,'counts'=>$counts];
}
