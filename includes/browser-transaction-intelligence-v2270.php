<?php
declare(strict_types=1);

/**
 * VP3 Browser Companion v22.70 — Transaction Intelligence & Exception Management.
 *
 * v22.70 aggregates the durable v22.60 transaction lifecycle into a bounded
 * attention system. It stores only canonical transaction facts needed for
 * follow-through: lifecycle state, normalized schedule timestamps, normalized
 * bounded financial totals, structured exception codes and canonical VP3 refs.
 *
 * Cross-transaction conflict/duplicate signals are advisory. They never prove
 * a duplicate, conflict, settlement state or external business outcome.
 *
 * Any consequential external write is proposal-only here and must start a
 * fresh v22.40 exact-form review before Chrome may submit anything.
 */
const VP3_BROWSER_INTELLIGENCE_V2270='browser-transaction-intelligence-v2270-20260921';
const VP3_BROWSER_INTELLIGENCE_MAX_ACTIVE_V2270=80;
const VP3_BROWSER_INTELLIGENCE_MAX_SCHEDULE_CANDIDATES_V2270=8;
const VP3_BROWSER_INTELLIGENCE_MAX_EXPOSURE_CANDIDATES_V2270=8;

require_once __DIR__.'/browser-transaction-continuity-v2260.php';

function vp3_browser_intelligence_schema_ready_v2270(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && table_exists('browser_transaction_intelligence_facts_v2270')
        && table_exists('browser_transaction_intelligence_cases_v2270')
        && table_exists('browser_transaction_recovery_proposals_v2270')
        && table_exists('browser_transaction_intelligence_receipts_v2270')
        && vp3_browser_continuity_schema_ready_v2260($pdo);
}

function vp3_browser_intelligence_ensure_schema_v2270(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_browser_continuity_ensure_schema_v2260($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_transaction_intelligence_facts_v2270 (
      continuity_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      next_event_at DATETIME NULL,
      next_deadline_at DATETIME NULL,
      exposure_kind VARCHAR(32) NOT NULL DEFAULT '',
      exposure_currency CHAR(3) NOT NULL DEFAULT '',
      exposure_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
      schedule_fingerprint CHAR(64) NOT NULL DEFAULT '',
      exposure_fingerprint CHAR(64) NOT NULL DEFAULT '',
      observed_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (continuity_id),
      INDEX idx_browser_intel_fact_owner_v2270 (owner_user_id,next_deadline_at,next_event_at),
      INDEX idx_browser_intel_fact_exposure_v2270 (owner_user_id,exposure_currency,exposure_kind),
      CONSTRAINT fk_browser_intel_fact_continuity_v2270 FOREIGN KEY (continuity_id) REFERENCES browser_transaction_continuities_v2260(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_intel_fact_owner_v2270 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_transaction_intelligence_cases_v2270 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      continuity_id BIGINT UNSIGNED NOT NULL,
      workflow_run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      source_event_public_id CHAR(36) NOT NULL DEFAULT '',
      exception_type VARCHAR(48) NOT NULL,
      exception_fingerprint CHAR(64) NOT NULL,
      priority_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
      priority_band VARCHAR(16) NOT NULL DEFAULT 'low',
      consequence_level VARCHAR(16) NOT NULL DEFAULT 'medium',
      reason_codes_json TEXT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'open',
      opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      acknowledged_at DATETIME NULL,
      resolved_at DATETIME NULL,
      last_evaluated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_browser_intel_case_public_v2270 (public_id),
      UNIQUE KEY uq_browser_intel_case_fingerprint_v2270 (owner_user_id,continuity_id,exception_fingerprint),
      INDEX idx_browser_intel_case_owner_v2270 (owner_user_id,status,priority_score,updated_at),
      INDEX idx_browser_intel_case_workflow_v2270 (owner_user_id,workflow_run_id,id),
      CONSTRAINT fk_browser_intel_case_continuity_v2270 FOREIGN KEY (continuity_id) REFERENCES browser_transaction_continuities_v2260(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_intel_case_owner_v2270 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_transaction_recovery_proposals_v2270 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      case_id BIGINT UNSIGNED NOT NULL,
      continuity_id BIGINT UNSIGNED NOT NULL,
      proposal_type VARCHAR(48) NOT NULL,
      reason_code VARCHAR(48) NOT NULL,
      requires_external_write TINYINT(1) NOT NULL DEFAULT 0,
      authorization_path VARCHAR(20) NOT NULL DEFAULT 'v22.40',
      status VARCHAR(20) NOT NULL DEFAULT 'proposed',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      resolved_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uq_browser_recovery_proposal_public_v2270 (public_id),
      UNIQUE KEY uq_browser_recovery_case_type_v2270 (case_id,proposal_type),
      INDEX idx_browser_recovery_owner_v2270 (owner_user_id,status,created_at,id),
      INDEX idx_browser_recovery_continuity_v2270 (continuity_id,status,id),
      CONSTRAINT fk_browser_recovery_case_v2270 FOREIGN KEY (case_id) REFERENCES browser_transaction_intelligence_cases_v2270(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_recovery_continuity_v2270 FOREIGN KEY (continuity_id) REFERENCES browser_transaction_continuities_v2260(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_recovery_owner_v2270 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_transaction_intelligence_receipts_v2270 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      continuity_id BIGINT UNSIGNED NOT NULL,
      case_id BIGINT UNSIGNED NULL,
      receipt_key CHAR(64) NOT NULL,
      receipt_type VARCHAR(48) NOT NULL,
      summary VARCHAR(500) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_browser_intel_receipt_public_v2270 (public_id),
      UNIQUE KEY uq_browser_intel_receipt_key_v2270 (owner_user_id,receipt_key),
      INDEX idx_browser_intel_receipt_owner_v2270 (owner_user_id,created_at,id),
      INDEX idx_browser_intel_receipt_continuity_v2270 (continuity_id,id),
      CONSTRAINT fk_browser_intel_receipt_continuity_v2270 FOREIGN KEY (continuity_id) REFERENCES browser_transaction_continuities_v2260(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_intel_receipt_case_v2270 FOREIGN KEY (case_id) REFERENCES browser_transaction_intelligence_cases_v2270(id) ON DELETE SET NULL,
      CONSTRAINT fk_browser_intel_receipt_owner_v2270 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_browser_intelligence_uuid_v2270(): string
{
    return function_exists('vp3_extension_uuid_v2000')?vp3_extension_uuid_v2000():sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff),
        random_int(0,0x0fff)|0x4000,random_int(0,0x3fff)|0x8000,
        random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff)
    );
}

function vp3_browser_intelligence_json_v2270(mixed $value): array
{
    $data=is_array($value)?$value:json_decode((string)$value,true);
    return is_array($data)?$data:[];
}

function vp3_browser_intelligence_priority_band_v2270(int $score): string
{
    if($score>=85)return 'urgent';
    if($score>=70)return 'high';
    if($score>=50)return 'medium';
    return 'low';
}

function vp3_browser_intelligence_base_score_v2270(string $type): int
{
    return match($type){
        'action_required'=>82,
        'deadline_due'=>88,
        'deadline_soon'=>74,
        'fulfillment_exception'=>86,
        'cancellation_change'=>84,
        'application_rejected'=>76,
        'schedule_change'=>72,
        'financial_change'=>68,
        'potential_schedule_conflict'=>72,
        'potential_duplicate_transaction'=>56,
        'response_received'=>48,
        'stale_order'=>58,
        'application_stale'=>54,
        'response_overdue'=>60,
        'account_pending'=>58,
        default=>50,
    };
}

function vp3_browser_intelligence_score_v2270(
    string $type,string $consequenceLevel,?string $deadlineAt=null,bool $externalProposal=false
): int {
    $score=vp3_browser_intelligence_base_score_v2270($type);
    $score+=match($consequenceLevel){'critical'=>18,'high'=>12,'medium'=>5,default=>0};
    if($deadlineAt){
        $seconds=strtotime($deadlineAt)-time();
        if($seconds>=0&&$seconds<=86400)$score+=10;
        elseif($seconds>86400&&$seconds<=259200)$score+=5;
    }
    if($externalProposal)$score+=4;
    return max(0,min(100,$score));
}

function vp3_browser_intelligence_schedule_candidates_v2270(mixed $value): array
{
    $out=[];
    foreach(array_slice(is_array($value)?$value:[],0,VP3_BROWSER_INTELLIGENCE_MAX_SCHEDULE_CANDIDATES_V2270) as $item){
        if(!is_array($item))continue;
        $kind=strtolower(trim((string)($item['kind']??'event')));
        if(!in_array($kind,['event','deadline','delivery'],true))$kind='event';
        $raw=trim((string)($item['at']??''));
        $ts=strtotime($raw);
        if(!$ts)continue;
        if($ts<strtotime('-30 days')||$ts>strtotime('+3 years'))continue;
        $at=gmdate('Y-m-d H:i:s',$ts);
        $out[$kind.'|'.$at]=['kind'=>$kind,'at'=>$at];
    }
    return array_values($out);
}

function vp3_browser_intelligence_exposure_candidates_v2270(mixed $value): array
{
    $out=[];
    foreach(array_slice(is_array($value)?$value:[],0,VP3_BROWSER_INTELLIGENCE_MAX_EXPOSURE_CANDIDATES_V2270) as $item){
        if(!is_array($item))continue;
        $kind=strtolower(trim((string)($item['kind']??'current_total')));
        if(!in_array($kind,['current_total','deposit','outstanding','refund_expected','authorized_total'],true))$kind='current_total';
        $currency=strtoupper(trim((string)($item['currency']??'')));
        if(!in_array($currency,['USD','EUR','GBP'],true))continue;
        $minor=max(0,min(999999999,(int)($item['minor']??0)));
        if($minor<1)continue;
        $key=$kind.'|'.$currency.'|'.$minor;
        $out[$key]=['kind'=>$kind,'currency'=>$currency,'minor'=>$minor];
    }
    return array_values($out);
}

function vp3_browser_intelligence_fact_public_v2270(array $row): array
{
    return [
        'next_event_at'=>(string)($row['next_event_at']??''),
        'next_deadline_at'=>(string)($row['next_deadline_at']??''),
        'exposure_kind'=>(string)($row['exposure_kind']??''),
        'exposure_currency'=>(string)($row['exposure_currency']??''),
        'exposure_minor'=>(int)($row['exposure_minor']??0),
        'observed_at'=>(string)($row['observed_at']??''),
        'updated_at'=>(string)($row['updated_at']??''),
    ];
}

function vp3_browser_intelligence_case_public_v2270(array $row): array
{
    $reasons=vp3_browser_intelligence_json_v2270($row['reason_codes_json']??'[]');
    return [
        'case_id'=>(string)$row['public_id'],
        'continuity_id'=>(string)($row['continuity_public_id']??''),
        'domain'=>(string)($row['domain']??''),
        'lifecycle_family'=>(string)($row['lifecycle_family']??'generic'),
        'lifecycle_state'=>(string)($row['lifecycle_state']??'active'),
        'exception_type'=>(string)$row['exception_type'],
        'priority_score'=>(int)$row['priority_score'],
        'priority_band'=>(string)$row['priority_band'],
        'consequence_level'=>(string)$row['consequence_level'],
        'reason_codes'=>array_values(array_map('strval',$reasons)),
        'status'=>(string)$row['status'],
        'opened_at'=>(string)$row['opened_at'],
        'acknowledged_at'=>(string)($row['acknowledged_at']??''),
        'resolved_at'=>(string)($row['resolved_at']??''),
        'updated_at'=>(string)$row['updated_at'],
    ];
}

function vp3_browser_intelligence_proposal_public_v2270(array $row): array
{
    return [
        'proposal_id'=>(string)$row['public_id'],
        'case_id'=>(string)($row['case_public_id']??''),
        'continuity_id'=>(string)($row['continuity_public_id']??''),
        'proposal_type'=>(string)$row['proposal_type'],
        'reason_code'=>(string)$row['reason_code'],
        'requires_external_write'=>!empty($row['requires_external_write']),
        'authorization_path'=>(string)$row['authorization_path'],
        'status'=>(string)$row['status'],
        'created_at'=>(string)$row['created_at'],
        'resolved_at'=>(string)($row['resolved_at']??''),
    ];
}

function vp3_browser_intelligence_receipt_v2270(
    PDO $pdo,int $uid,int $continuityId,?int $caseId,string $type,string $summary,string $keyMaterial
): void {
    $receiptKey=hash('sha256',$uid.'|'.$continuityId.'|'.$type.'|'.$keyMaterial);
    try{
        $stmt=$pdo->prepare("INSERT INTO browser_transaction_intelligence_receipts_v2270
          (public_id,owner_user_id,continuity_id,case_id,receipt_key,receipt_type,summary)
          VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([
            vp3_browser_intelligence_uuid_v2270(),$uid,$continuityId,$caseId,$receiptKey,$type,
            mb_strimwidth(preg_replace('/\s+/u',' ',trim($summary))??'',0,500,'')
        ]);
    }catch(PDOException $e){
        if((string)$e->getCode()!=='23000')throw $e;
    }
}

function vp3_browser_intelligence_fact_row_v2270(PDO $pdo,int $uid,int $continuityId): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM browser_transaction_intelligence_facts_v2270 WHERE owner_user_id=? AND continuity_id=? LIMIT 1");
    $stmt->execute([$uid,$continuityId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_intelligence_ingest_facts_v2270(
    PDO $pdo,array $continuity,array $input,array $event
): ?array {
    $uid=(int)$continuity['owner_user_id'];$cid=(int)$continuity['id'];
    $schedule=vp3_browser_intelligence_schedule_candidates_v2270($input['schedule_candidates']??[]);
    $exposure=vp3_browser_intelligence_exposure_candidates_v2270($input['exposure_candidates']??[]);
    if(!$schedule&&!$exposure)return vp3_browser_intelligence_fact_row_v2270($pdo,$uid,$cid);

    $nextEvent=null;$nextDeadline=null;$now=time();
    foreach($schedule as $item){
        $ts=strtotime((string)$item['at']);
        if($ts<$now-3600)continue;
        if($item['kind']==='deadline'){
            if($nextDeadline===null||$ts<strtotime($nextDeadline))$nextDeadline=(string)$item['at'];
        }else{
            if($nextEvent===null||$ts<strtotime($nextEvent))$nextEvent=(string)$item['at'];
        }
    }

    $selected=null;
    $rank=['outstanding'=>5,'deposit'=>4,'refund_expected'=>3,'current_total'=>2,'authorized_total'=>1];
    foreach($exposure as $item){
        if($selected===null||($rank[$item['kind']]??0)>($rank[$selected['kind']]??0))$selected=$item;
    }

    $scheduleFingerprint=$schedule?hash('sha256',json_encode($schedule,JSON_UNESCAPED_SLASHES)):'';
    $exposureFingerprint=$exposure?hash('sha256',json_encode($exposure,JSON_UNESCAPED_SLASHES)):'';
    $existing=vp3_browser_intelligence_fact_row_v2270($pdo,$uid,$cid);

    if($existing){
        $stmt=$pdo->prepare("UPDATE browser_transaction_intelligence_facts_v2270 SET
          next_event_at=COALESCE(?,next_event_at),next_deadline_at=COALESCE(?,next_deadline_at),
          exposure_kind=CASE WHEN ?<>'' THEN ? ELSE exposure_kind END,
          exposure_currency=CASE WHEN ?<>'' THEN ? ELSE exposure_currency END,
          exposure_minor=CASE WHEN ?>0 THEN ? ELSE exposure_minor END,
          schedule_fingerprint=CASE WHEN ?<>'' THEN ? ELSE schedule_fingerprint END,
          exposure_fingerprint=CASE WHEN ?<>'' THEN ? ELSE exposure_fingerprint END,
          observed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
          WHERE continuity_id=? AND owner_user_id=?");
        $kind=(string)($selected['kind']??'');$currency=(string)($selected['currency']??'');$minor=(int)($selected['minor']??0);
        $stmt->execute([$nextEvent,$nextDeadline,$kind,$kind,$currency,$currency,$minor,$minor,$scheduleFingerprint,$scheduleFingerprint,$exposureFingerprint,$exposureFingerprint,$cid,$uid]);
    }else{
        $stmt=$pdo->prepare("INSERT INTO browser_transaction_intelligence_facts_v2270
          (continuity_id,owner_user_id,next_event_at,next_deadline_at,exposure_kind,exposure_currency,exposure_minor,schedule_fingerprint,exposure_fingerprint,observed_at)
          VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
        $stmt->execute([
            $cid,$uid,$nextEvent,$nextDeadline,(string)($selected['kind']??''),(string)($selected['currency']??''),
            (int)($selected['minor']??0),$scheduleFingerprint,$exposureFingerprint
        ]);
    }
    return vp3_browser_intelligence_fact_row_v2270($pdo,$uid,$cid);
}

function vp3_browser_intelligence_consequence_v2270(PDO $pdo,array $continuity): string
{
    $stmt=$pdo->prepare("SELECT consequence_level FROM browser_submission_intents_v2240 WHERE owner_user_id=? AND public_id=? LIMIT 1");
    $stmt->execute([(int)$continuity['owner_user_id'],(string)$continuity['submission_intent_public_id']]);
    $level=strtolower(trim((string)$stmt->fetchColumn()));
    return in_array($level,['low','medium','high','critical'],true)?$level:'medium';
}

function vp3_browser_intelligence_recovery_mapping_v2270(string $type): array
{
    return match($type){
        'schedule_change','potential_schedule_conflict'=>['prepare_reschedule','schedule_attention',true],
        'cancellation_change'=>['prepare_support_request','cancellation_followup',true],
        'application_rejected'=>['prepare_followup','decision_followup',true],
        'financial_change'=>['prepare_support_request','financial_change',true],
        'action_required','deadline_due','deadline_soon'=>['prepare_required_followup','required_action',true],
        'fulfillment_exception'=>['prepare_support_request','fulfillment_exception',true],
        'response_received'=>['prepare_reply','response_received',true],
        'stale_order','application_stale','response_overdue','account_pending'=>['prepare_status_request','status_followup',true],
        'potential_duplicate_transaction'=>['review_duplicate','potential_duplicate',false],
        default=>['review_transaction','transaction_attention',false],
    };
}

function vp3_browser_intelligence_create_proposal_v2270(PDO $pdo,array $caseRow,array $continuity): void
{
    [$type,$reason,$external]=vp3_browser_intelligence_recovery_mapping_v2270((string)$caseRow['exception_type']);
    try{
        $stmt=$pdo->prepare("INSERT INTO browser_transaction_recovery_proposals_v2270
          (public_id,owner_user_id,case_id,continuity_id,proposal_type,reason_code,requires_external_write,authorization_path)
          VALUES (?,?,?,?,?,?,?,'v22.40')");
        $stmt->execute([
            vp3_browser_intelligence_uuid_v2270(),(int)$caseRow['owner_user_id'],(int)$caseRow['id'],
            (int)$continuity['id'],$type,$reason,$external?1:0
        ]);
    }catch(PDOException $e){
        if((string)$e->getCode()!=='23000')throw $e;
    }
}

function vp3_browser_intelligence_notify_v2270(PDO $pdo,array $continuity,array $caseRow): void
{
    if((int)$caseRow['priority_score']<70)return;
    $label=str_replace('_',' ',(string)$caseRow['exception_type']);
    create_notification(
        (int)$continuity['owner_user_id'],
        'browser_transaction_needs_attention',
        'Transaction needs attention: '.ucwords($label),
        'VP3 prioritized an exception across your active transaction follow-through. Review the evidence and proposed next step before any external action.',
        (int)$continuity['workflow_run_id']>0?'/agent-workflows.php?id='.(int)$continuity['workflow_run_id']:'/agent-workflows.php',
        'browser_transaction_intelligence',(int)$caseRow['id']
    );
    $runtime=$pdo->prepare("SELECT * FROM browser_agent_runtime_sessions_v2200 WHERE public_id=? AND owner_user_id=? LIMIT 1");
    $runtime->execute([(string)$continuity['original_runtime_public_id'],(int)$continuity['owner_user_id']]);
    $runtimeRow=$runtime->fetch(PDO::FETCH_ASSOC);
    if(is_array($runtimeRow)){
        vp3_browser_runtime_event_v2200(
            $pdo,$runtimeRow,'transaction_exception_detected',
            'Transaction exception prioritized: '.$label.' · '.(string)$caseRow['priority_band'].' priority.',
            'transaction.intelligence',null,(string)$continuity['lifecycle_state'],['reason'=>(string)$caseRow['exception_type']]
        );
    }
}

function vp3_browser_intelligence_open_case_v2270(
    PDO $pdo,array $continuity,string $type,array $reasons,string $sourceEventId='',?array $facts=null,string $salt=''
): ?array {
    if((string)$continuity['tracking_status']!=='active')return null;
    $uid=(int)$continuity['owner_user_id'];$cid=(int)$continuity['id'];
    $consequence=vp3_browser_intelligence_consequence_v2270($pdo,$continuity);
    $deadline=(string)($facts['next_deadline_at']??'');
    [$proposalType,,$external]=vp3_browser_intelligence_recovery_mapping_v2270($type);
    $score=vp3_browser_intelligence_score_v2270($type,$consequence,$deadline,$external);
    $band=vp3_browser_intelligence_priority_band_v2270($score);
    $reasons=array_values(array_unique(array_filter(array_map(static fn($x)=>preg_replace('/[^a-z0-9_:-]/','',strtolower((string)$x))??'',$reasons))));
    $fingerprint=hash('sha256',$type.'|'.$sourceEventId.'|'.$salt.'|'.implode('|',$reasons));

    $stmt=$pdo->prepare("SELECT * FROM browser_transaction_intelligence_cases_v2270
      WHERE owner_user_id=? AND continuity_id=? AND exception_fingerprint=? LIMIT 1");
    $stmt->execute([$uid,$cid,$fingerprint]);$existing=$stmt->fetch(PDO::FETCH_ASSOC);
    if(is_array($existing)){
        if(in_array((string)$existing['status'],['open','acknowledged'],true)){
            $pdo->prepare("UPDATE browser_transaction_intelligence_cases_v2270
              SET priority_score=?,priority_band=?,reason_codes_json=?,last_evaluated_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
              WHERE id=? AND owner_user_id=?")
              ->execute([$score,$band,json_encode($reasons,JSON_UNESCAPED_SLASHES),(int)$existing['id'],$uid]);
        }
        return $existing;
    }

    $public=vp3_browser_intelligence_uuid_v2270();
    $stmt=$pdo->prepare("INSERT INTO browser_transaction_intelligence_cases_v2270
      (public_id,owner_user_id,continuity_id,workflow_run_id,source_event_public_id,exception_type,exception_fingerprint,priority_score,priority_band,consequence_level,reason_codes_json)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $public,$uid,$cid,(int)$continuity['workflow_run_id'],$sourceEventId,$type,$fingerprint,$score,$band,$consequence,
        json_encode($reasons,JSON_UNESCAPED_SLASHES)
    ]);
    $caseId=(int)$pdo->lastInsertId();
    $case=$pdo->prepare("SELECT * FROM browser_transaction_intelligence_cases_v2270 WHERE id=? AND owner_user_id=? LIMIT 1");
    $case->execute([$caseId,$uid]);$caseRow=$case->fetch(PDO::FETCH_ASSOC);
    if(!is_array($caseRow))throw new RuntimeException('Transaction intelligence case could not be created.');

    vp3_browser_intelligence_create_proposal_v2270($pdo,$caseRow,$continuity);
    vp3_browser_intelligence_receipt_v2270(
        $pdo,$uid,$cid,$caseId,'exception_opened',
        'Opened '.str_replace('_',' ',$type).' at '.$band.' priority.',$fingerprint
    );
    vp3_browser_intelligence_notify_v2270($pdo,$continuity,$caseRow);
    return $caseRow;
}

function vp3_browser_intelligence_resolve_closed_v2270(PDO $pdo,array $continuity): void
{
    if((string)$continuity['tracking_status']!=='closed')return;
    $uid=(int)$continuity['owner_user_id'];$cid=(int)$continuity['id'];
    $stmt=$pdo->prepare("SELECT * FROM browser_transaction_intelligence_cases_v2270 WHERE owner_user_id=? AND continuity_id=? AND status IN ('open','acknowledged')");
    $stmt->execute([$uid,$cid]);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $case){
        $pdo->prepare("UPDATE browser_transaction_intelligence_cases_v2270 SET status='resolved',resolved_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")
            ->execute([(int)$case['id'],$uid]);
        vp3_browser_intelligence_receipt_v2270(
            $pdo,$uid,$cid,(int)$case['id'],'exception_resolved',
            'Transaction tracker closed; active attention was removed while history was retained.',
            'closed|'.(string)$case['public_id'].'|'.(string)$continuity['closure_reason']
        );
    }
}

function vp3_browser_intelligence_evaluate_continuity_v2270(
    PDO $pdo,array $user,array $continuity,?array $event=null,array $changes=[],array $input=[]
): array {
    $uid=(int)($user['id']??0);
    if($uid<1||$uid!==(int)$continuity['owner_user_id'])throw new RuntimeException('Transaction intelligence ownership mismatch.');
    if((string)$continuity['tracking_status']==='closed'){
        vp3_browser_intelligence_resolve_closed_v2270($pdo,$continuity);
        return [];
    }
    if(!$event)$event=vp3_browser_continuity_latest_event_v2260($pdo,$uid,(int)$continuity['id']);
    if(!$event)$event=[];
    if(!$changes)$changes=array_values(array_map('strval',vp3_browser_intelligence_json_v2270($event['change_codes_json']??'[]')));
    $facts=vp3_browser_intelligence_ingest_facts_v2270($pdo,$continuity,$input,$event)??[];
    $state=(string)$continuity['lifecycle_state'];$family=(string)$continuity['lifecycle_family'];
    $eventId=(string)($event['public_id']??'');
    $signals=[];

    if($state==='needs_action')$signals[]=['action_required',['state_needs_action']];
    if($state==='exception')$signals[]=['fulfillment_exception',['state_exception']];
    if(in_array('cancellation',$changes,true))$signals[]=['cancellation_change',['cancellation_detected']];
    if($state==='rejected')$signals[]=['application_rejected',['decision_rejected']];
    if(in_array('schedule_changed',$changes,true))$signals[]=['schedule_change',['schedule_fingerprint_changed']];
    if(in_array('amount_changed',$changes,true))$signals[]=['financial_change',['amount_fingerprint_changed']];
    if(in_array('reply_received',$changes,true))$signals[]=['response_received',['reply_received']];

    $deadline=(string)($facts['next_deadline_at']??'');
    if($deadline){
        $delta=strtotime($deadline)-time();
        if($delta>=0&&$delta<=86400)$signals[]=['deadline_due',['deadline_within_24h']];
        elseif($delta>86400&&$delta<=259200)$signals[]=['deadline_soon',['deadline_within_72h']];
    }

    $anchor=(string)($continuity['last_change_at']??$continuity['created_at']??'');
    $age=$anchor?time()-strtotime($anchor):0;
    if($family==='commerce'&&in_array($state,['ordered','processing'],true)&&$age>3*86400)$signals[]=['stale_order',['no_meaningful_progress_72h']];
    if($family==='application'&&$state==='under_review'&&$age>14*86400)$signals[]=['application_stale',['under_review_14d']];
    if($family==='communication'&&$state==='sent'&&$age>5*86400)$signals[]=['response_overdue',['sent_without_response_5d']];
    if($family==='account'&&$state==='pending'&&$age>3*86400)$signals[]=['account_pending',['pending_72h']];

    $opened=[];
    foreach($signals as [$type,$reasons]){
        $case=vp3_browser_intelligence_open_case_v2270($pdo,$continuity,$type,$reasons,$eventId,$facts);
        if($case)$opened[]=$case;
    }
    return $opened;
}

function vp3_browser_intelligence_cross_signals_v2270(PDO $pdo,array $user): void
{
    $uid=(int)($user['id']??0);if($uid<1)return;
    $stmt=$pdo->prepare("SELECT c.*,f.next_event_at,f.exposure_currency,f.exposure_minor
      FROM browser_transaction_continuities_v2260 c
      LEFT JOIN browser_transaction_intelligence_facts_v2270 f ON f.continuity_id=c.id AND f.owner_user_id=c.owner_user_id
      WHERE c.owner_user_id=? AND c.tracking_status='active'
      ORDER BY c.created_at DESC,c.id DESC LIMIT ".VP3_BROWSER_INTELLIGENCE_MAX_ACTIVE_V2270);
    $stmt->execute([$uid]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];

    $bookings=array_values(array_filter($rows,static fn(array $r)=>(string)$r['lifecycle_family']==='booking'&&!empty($r['next_event_at'])));
    for($i=0;$i<count($bookings);$i++)for($j=$i+1;$j<count($bookings);$j++){
        $a=$bookings[$i];$b=$bookings[$j];
        if(abs(strtotime((string)$a['next_event_at'])-strtotime((string)$b['next_event_at']))>7200)continue;
        $pair=min((int)$a['id'],(int)$b['id']).':'.max((int)$a['id'],(int)$b['id']);
        vp3_browser_intelligence_open_case_v2270($pdo,$a,'potential_schedule_conflict',['booking_times_within_2h'],'',vp3_browser_intelligence_fact_row_v2270($pdo,$uid,(int)$a['id']),'pair:'.$pair);
        vp3_browser_intelligence_open_case_v2270($pdo,$b,'potential_schedule_conflict',['booking_times_within_2h'],'',vp3_browser_intelligence_fact_row_v2270($pdo,$uid,(int)$b['id']),'pair:'.$pair);
    }

    for($i=0;$i<count($rows);$i++)for($j=$i+1;$j<count($rows);$j++){
        $a=$rows[$i];$b=$rows[$j];
        if((string)$a['domain']!==(string)$b['domain']||(string)$a['lifecycle_family']!==(string)$b['lifecycle_family'])continue;
        if((string)$a['reference_hash']===(string)$b['reference_hash'])continue;
        $amountA=(int)($a['exposure_minor']??0);$amountB=(int)($b['exposure_minor']??0);
        $currencyA=(string)($a['exposure_currency']??'');$currencyB=(string)($b['exposure_currency']??'');
        if($amountA<1||$amountA!==$amountB||$currencyA===''||$currencyA!==$currencyB)continue;
        if(abs(strtotime((string)$a['created_at'])-strtotime((string)$b['created_at']))>21600)continue;
        $pair=min((int)$a['id'],(int)$b['id']).':'.max((int)$a['id'],(int)$b['id']);
        vp3_browser_intelligence_open_case_v2270($pdo,$a,'potential_duplicate_transaction',['same_domain_family_amount_within_6h'],'',vp3_browser_intelligence_fact_row_v2270($pdo,$uid,(int)$a['id']),'pair:'.$pair);
        vp3_browser_intelligence_open_case_v2270($pdo,$b,'potential_duplicate_transaction',['same_domain_family_amount_within_6h'],'',vp3_browser_intelligence_fact_row_v2270($pdo,$uid,(int)$b['id']),'pair:'.$pair);
    }
}

function vp3_browser_intelligence_sync_continuity_v2270(
    PDO $pdo,array $user,array $continuity,array $event,array $changes,array $input=[]
): array {
    if(!vp3_browser_intelligence_schema_ready_v2270($pdo))return ['enabled'=>false,'cases'=>[]];
    $cases=vp3_browser_intelligence_evaluate_continuity_v2270($pdo,$user,$continuity,$event,$changes,$input);
    vp3_browser_intelligence_cross_signals_v2270($pdo,$user);
    return ['enabled'=>true,'cases'=>array_map('vp3_browser_intelligence_case_public_v2270',$cases)];
}

function vp3_browser_intelligence_observe_facts_v2270(PDO $pdo,array $user,string $continuityId,array $input): array
{
    $uid=(int)($user['id']??0);
    $continuity=vp3_browser_continuity_row_v2260($pdo,$uid,$continuityId);
    if(!$continuity)throw new RuntimeException('Transaction continuity was not found.');
    if((string)$continuity['tracking_status']!=='active'){
        vp3_browser_intelligence_resolve_closed_v2270($pdo,$continuity);
        return ['continuity'=>vp3_browser_continuity_public_v2260($continuity),'cases'=>[],'fact'=>null];
    }
    $event=vp3_browser_continuity_latest_event_v2260($pdo,$uid,(int)$continuity['id']);
    if(!$event)throw new RuntimeException('A v22.60 lifecycle observation is required before transaction facts can be evaluated.');
    $changes=array_values(array_map('strval',vp3_browser_intelligence_json_v2270($event['change_codes_json']??'[]')));
    $cases=vp3_browser_intelligence_evaluate_continuity_v2270($pdo,$user,$continuity,$event,$changes,$input);
    vp3_browser_intelligence_cross_signals_v2270($pdo,$user);
    $fact=vp3_browser_intelligence_fact_row_v2270($pdo,$uid,(int)$continuity['id']);
    return [
        'continuity'=>vp3_browser_continuity_public_v2260($continuity),
        'cases'=>array_map('vp3_browser_intelligence_case_public_v2270',$cases),
        'fact'=>$fact?vp3_browser_intelligence_fact_public_v2270($fact):null,
    ];
}

function vp3_browser_intelligence_evaluate_all_v2270(PDO $pdo,array $user): void
{
    $uid=(int)($user['id']??0);if($uid<1)return;
    $stmt=$pdo->prepare("SELECT * FROM browser_transaction_continuities_v2260 WHERE owner_user_id=? ORDER BY tracking_status='active' DESC,updated_at DESC,id DESC LIMIT ".VP3_BROWSER_INTELLIGENCE_MAX_ACTIVE_V2270);
    $stmt->execute([$uid]);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $continuity){
        if((string)$continuity['tracking_status']==='closed')vp3_browser_intelligence_resolve_closed_v2270($pdo,$continuity);
        else vp3_browser_intelligence_evaluate_continuity_v2270($pdo,$user,$continuity);
    }
    vp3_browser_intelligence_cross_signals_v2270($pdo,$user);
}

function vp3_browser_intelligence_case_proposals_v2270(PDO $pdo,int $uid,array $caseIds): array
{
    if(!$caseIds)return [];
    $marks=implode(',',array_fill(0,count($caseIds),'?'));
    $stmt=$pdo->prepare("SELECT p.*,c.public_id AS case_public_id,t.public_id AS continuity_public_id
      FROM browser_transaction_recovery_proposals_v2270 p
      INNER JOIN browser_transaction_intelligence_cases_v2270 c ON c.id=p.case_id
      INNER JOIN browser_transaction_continuities_v2260 t ON t.id=p.continuity_id
      WHERE p.owner_user_id=? AND p.case_id IN ($marks) ORDER BY p.status='proposed' DESC,p.id DESC LIMIT 80");
    $stmt->execute(array_merge([$uid],$caseIds));
    return array_map('vp3_browser_intelligence_proposal_public_v2270',$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_browser_intelligence_inbox_v2270(PDO $pdo,array $user): array
{
    $uid=(int)($user['id']??0);
    $stmt=$pdo->prepare("SELECT x.*,c.public_id AS continuity_public_id,c.domain,c.lifecycle_family,c.lifecycle_state
      FROM browser_transaction_intelligence_cases_v2270 x
      INNER JOIN browser_transaction_continuities_v2260 c ON c.id=x.continuity_id
      WHERE x.owner_user_id=? AND x.status IN ('open','acknowledged') AND c.tracking_status='active'
      ORDER BY x.priority_score DESC,x.updated_at DESC,x.id DESC LIMIT 60");
    $stmt->execute([$uid]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $ids=array_map(static fn(array $r)=>(int)$r['id'],$rows);
    $proposals=vp3_browser_intelligence_case_proposals_v2270($pdo,$uid,$ids);

    $counts=['urgent'=>0,'high'=>0,'medium'=>0,'low'=>0];
    foreach($rows as $row){$band=(string)$row['priority_band'];if(isset($counts[$band]))$counts[$band]++;}

    $exposureStmt=$pdo->prepare("SELECT f.exposure_currency,SUM(f.exposure_minor) AS total_minor,COUNT(*) AS transaction_count
      FROM browser_transaction_intelligence_facts_v2270 f
      INNER JOIN browser_transaction_continuities_v2260 c ON c.id=f.continuity_id
      WHERE f.owner_user_id=? AND c.owner_user_id=? AND c.tracking_status='active' AND f.exposure_currency<>'' AND f.exposure_minor>0
      GROUP BY f.exposure_currency ORDER BY f.exposure_currency");
    $exposureStmt->execute([$uid,$uid]);$exposure=[];
    foreach($exposureStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$exposure[]=[
        'currency'=>(string)$r['exposure_currency'],'total_minor'=>(int)$r['total_minor'],'transaction_count'=>(int)$r['transaction_count']
    ];

    $closed=$pdo->prepare("SELECT x.*,c.public_id AS continuity_public_id,c.domain,c.lifecycle_family,c.lifecycle_state
      FROM browser_transaction_intelligence_cases_v2270 x
      INNER JOIN browser_transaction_continuities_v2260 c ON c.id=x.continuity_id
      WHERE x.owner_user_id=? AND x.status IN ('resolved','dismissed')
      ORDER BY COALESCE(x.resolved_at,x.updated_at) DESC,x.id DESC LIMIT 20");
    $closed->execute([$uid]);

    return [
        'active_count'=>count($rows),'priority_counts'=>$counts,'financial_exposure'=>$exposure,
        'cases'=>array_map('vp3_browser_intelligence_case_public_v2270',$rows),
        'proposals'=>$proposals,
        'recent_closed'=>array_map('vp3_browser_intelligence_case_public_v2270',$closed->fetchAll(PDO::FETCH_ASSOC)?:[]),
        'cross_transaction_signals_are_advisory'=>true,
        'external_write_requires_fresh_v2240'=>true,
    ];
}

function vp3_browser_intelligence_case_action_v2270(PDO $pdo,array $user,string $caseId,string $action): array
{
    $uid=(int)($user['id']??0);
    if(!preg_match('/^[a-f0-9-]{36}$/i',$caseId))throw new InvalidArgumentException('Transaction intelligence case is invalid.');
    if(!in_array($action,['acknowledge','dismiss'],true))throw new InvalidArgumentException('Unsupported transaction intelligence case action.');
    $stmt=$pdo->prepare("SELECT x.*,c.public_id AS continuity_public_id,c.domain,c.lifecycle_family,c.lifecycle_state
      FROM browser_transaction_intelligence_cases_v2270 x
      INNER JOIN browser_transaction_continuities_v2260 c ON c.id=x.continuity_id
      WHERE x.public_id=? AND x.owner_user_id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$caseId,$uid]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))throw new RuntimeException('Transaction intelligence case was not found.');
    if(!in_array((string)$row['status'],['open','acknowledged'],true))return ['case'=>vp3_browser_intelligence_case_public_v2270($row)];
    $status=$action==='acknowledge'?'acknowledged':'dismissed';
    $pdo->prepare("UPDATE browser_transaction_intelligence_cases_v2270
      SET status=?,acknowledged_at=CASE WHEN ?='acknowledged' THEN UTC_TIMESTAMP() ELSE acknowledged_at END,
          resolved_at=CASE WHEN ?='dismissed' THEN UTC_TIMESTAMP() ELSE resolved_at END,updated_at=UTC_TIMESTAMP()
      WHERE id=? AND owner_user_id=?")->execute([$status,$status,$status,(int)$row['id'],$uid]);
    vp3_browser_intelligence_receipt_v2270(
        $pdo,$uid,(int)$row['continuity_id'],(int)$row['id'],'exception_'.$status,
        'Transaction exception '.str_replace('_',' ',$status).'.',$caseId.'|'.$status
    );
    $row['status']=$status;
    if($status==='acknowledged')$row['acknowledged_at']=gmdate('Y-m-d H:i:s');else $row['resolved_at']=gmdate('Y-m-d H:i:s');
    return ['case'=>vp3_browser_intelligence_case_public_v2270($row)];
}

function vp3_browser_intelligence_proposal_action_v2270(PDO $pdo,array $user,string $proposalId,string $action): array
{
    $uid=(int)($user['id']??0);
    if(!preg_match('/^[a-f0-9-]{36}$/i',$proposalId))throw new InvalidArgumentException('Recovery proposal is invalid.');
    if(!in_array($action,['acknowledge','dismiss'],true))throw new InvalidArgumentException('Unsupported recovery proposal action.');
    $stmt=$pdo->prepare("SELECT p.*,x.public_id AS case_public_id,c.public_id AS continuity_public_id
      FROM browser_transaction_recovery_proposals_v2270 p
      INNER JOIN browser_transaction_intelligence_cases_v2270 x ON x.id=p.case_id
      INNER JOIN browser_transaction_continuities_v2260 c ON c.id=p.continuity_id
      WHERE p.public_id=? AND p.owner_user_id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$proposalId,$uid]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))throw new RuntimeException('Recovery proposal was not found.');
    if((string)$row['status']!=='proposed')return ['proposal'=>vp3_browser_intelligence_proposal_public_v2270($row)];
    $status=$action==='acknowledge'?'acknowledged':'dismissed';
    $pdo->prepare("UPDATE browser_transaction_recovery_proposals_v2270 SET status=?,resolved_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")
        ->execute([$status,(int)$row['id'],$uid]);
    vp3_browser_intelligence_receipt_v2270(
        $pdo,$uid,(int)$row['continuity_id'],(int)$row['case_id'],'proposal_'.$status,
        'Recovery proposal '.str_replace('_',' ',$status).'.',$proposalId.'|'.$status
    );
    $row['status']=$status;$row['resolved_at']=gmdate('Y-m-d H:i:s');
    return ['proposal'=>vp3_browser_intelligence_proposal_public_v2270($row)];
}

function vp3_browser_intelligence_for_workflow_v2270(PDO $pdo,int $uid,int $workflowRunId): array
{
    if($uid<1||$workflowRunId<1||!vp3_browser_intelligence_schema_ready_v2270($pdo)){
        return ['count'=>0,'open'=>0,'urgent'=>0,'high'=>0,'cases'=>[],'proposals'=>[],'receipts'=>[]];
    }
    $stmt=$pdo->prepare("SELECT x.*,c.public_id AS continuity_public_id,c.domain,c.lifecycle_family,c.lifecycle_state
      FROM browser_transaction_intelligence_cases_v2270 x
      INNER JOIN browser_transaction_continuities_v2260 c ON c.id=x.continuity_id
      WHERE x.owner_user_id=? AND x.workflow_run_id=? ORDER BY x.priority_score DESC,x.id DESC LIMIT 60");
    $stmt->execute([$uid,$workflowRunId]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $ids=array_map(static fn(array $r)=>(int)$r['id'],$rows);
    $proposals=vp3_browser_intelligence_case_proposals_v2270($pdo,$uid,$ids);
    $receipts=$pdo->prepare("SELECT r.receipt_type,r.summary,r.created_at FROM browser_transaction_intelligence_receipts_v2270 r
      INNER JOIN browser_transaction_continuities_v2260 c ON c.id=r.continuity_id
      WHERE r.owner_user_id=? AND c.workflow_run_id=? ORDER BY r.id DESC LIMIT 80");
    $receipts->execute([$uid,$workflowRunId]);
    return [
        'count'=>count($rows),
        'open'=>count(array_filter($rows,static fn(array $r)=>in_array((string)$r['status'],['open','acknowledged'],true))),
        'urgent'=>count(array_filter($rows,static fn(array $r)=>(string)$r['priority_band']==='urgent'&&in_array((string)$r['status'],['open','acknowledged'],true))),
        'high'=>count(array_filter($rows,static fn(array $r)=>(string)$r['priority_band']==='high'&&in_array((string)$r['status'],['open','acknowledged'],true))),
        'cases'=>array_map('vp3_browser_intelligence_case_public_v2270',$rows),
        'proposals'=>$proposals,
        'receipts'=>$receipts->fetchAll(PDO::FETCH_ASSOC)?:[],
    ];
}
