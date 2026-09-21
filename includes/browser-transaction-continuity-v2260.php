<?php
declare(strict_types=1);

/**
 * VP3 Browser Companion v22.60 — Transaction Continuity & Follow-Through.
 *
 * v22.60 turns a verified v22.50 transaction outcome into a durable,
 * reference-only lifecycle. Return-page recognition uses user-owned
 * domain + hashed transaction references. Raw page text, raw URLs and
 * raw confirmation/order/booking identifiers are never persisted.
 *
 * Observation is read-only. Follow-through is proposal-first. Any new
 * consequential external write must start a fresh v22.40 review.
 */
const VP3_BROWSER_CONTINUITY_V2260='browser-transaction-continuity-v2260-20260921';
const VP3_BROWSER_CONTINUITY_MAX_REFERENCE_CANDIDATES_V2260=12;
const VP3_BROWSER_CONTINUITY_MAX_EVENTS_V2260=80;

require_once __DIR__.'/browser-transaction-outcome-v2250.php';

function vp3_browser_continuity_schema_ready_v2260(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && table_exists('browser_transaction_continuities_v2260')
        && table_exists('browser_transaction_continuity_events_v2260')
        && table_exists('browser_transaction_followthrough_proposals_v2260')
        && vp3_browser_outcome_schema_ready_v2250($pdo);
}

function vp3_browser_continuity_ensure_schema_v2260(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_browser_outcome_ensure_schema_v2250($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_transaction_continuities_v2260 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      submission_intent_public_id CHAR(36) NOT NULL,
      original_runtime_public_id CHAR(36) NOT NULL,
      workflow_run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      domain VARCHAR(190) NOT NULL,
      match_mode VARCHAR(20) NOT NULL DEFAULT 'reference',
      reference_kind VARCHAR(32) NOT NULL DEFAULT '',
      reference_hash CHAR(64) NOT NULL DEFAULT '',
      lifecycle_family VARCHAR(24) NOT NULL DEFAULT 'generic',
      lifecycle_state VARCHAR(32) NOT NULL DEFAULT 'active',
      previous_state VARCHAR(32) NOT NULL DEFAULT '',
      tracking_status VARCHAR(20) NOT NULL DEFAULT 'active',
      closure_reason VARCHAR(48) NOT NULL DEFAULT '',
      last_page_fingerprint CHAR(64) NOT NULL DEFAULT '',
      last_content_hash CHAR(64) NOT NULL DEFAULT '',
      last_observation_fingerprint CHAR(64) NOT NULL DEFAULT '',
      schedule_hash CHAR(64) NOT NULL DEFAULT '',
      amount_hash CHAR(64) NOT NULL DEFAULT '',
      last_checked_at DATETIME NULL,
      last_change_at DATETIME NULL,
      closed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_browser_continuity_public_v2260 (public_id),
      UNIQUE KEY uq_browser_continuity_intent_v2260 (owner_user_id,submission_intent_public_id),
      INDEX idx_browser_continuity_owner_domain_v2260 (owner_user_id,domain,tracking_status,updated_at),
      INDEX idx_browser_continuity_reference_v2260 (owner_user_id,domain,reference_hash,tracking_status),
      INDEX idx_browser_continuity_workflow_v2260 (owner_user_id,workflow_run_id,id),
      CONSTRAINT fk_browser_continuity_owner_v2260 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_transaction_continuity_events_v2260 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      public_id CHAR(36) NOT NULL,
      continuity_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      observation_fingerprint CHAR(64) NOT NULL,
      page_fingerprint CHAR(64) NOT NULL,
      content_hash CHAR(64) NOT NULL,
      reference_hash CHAR(64) NOT NULL DEFAULT '',
      reference_match TINYINT(1) NOT NULL DEFAULT 0,
      lifecycle_state VARCHAR(32) NOT NULL,
      prior_state VARCHAR(32) NOT NULL DEFAULT '',
      evidence_codes_json TEXT NULL,
      change_codes_json TEXT NULL,
      schedule_hash CHAR(64) NOT NULL DEFAULT '',
      amount_hash CHAR(64) NOT NULL DEFAULT '',
      terminal_observed TINYINT(1) NOT NULL DEFAULT 0,
      observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_browser_continuity_event_public_v2260 (public_id),
      UNIQUE KEY uq_browser_continuity_observation_v2260 (continuity_id,observation_fingerprint),
      INDEX idx_browser_continuity_event_owner_v2260 (owner_user_id,created_at,id),
      INDEX idx_browser_continuity_event_tracker_v2260 (continuity_id,id),
      CONSTRAINT fk_browser_continuity_event_tracker_v2260 FOREIGN KEY (continuity_id) REFERENCES browser_transaction_continuities_v2260(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_continuity_event_owner_v2260 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_transaction_followthrough_proposals_v2260 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      public_id CHAR(36) NOT NULL,
      continuity_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      event_public_id CHAR(36) NOT NULL,
      proposal_type VARCHAR(40) NOT NULL,
      reason_code VARCHAR(48) NOT NULL,
      requires_external_write TINYINT(1) NOT NULL DEFAULT 0,
      status VARCHAR(20) NOT NULL DEFAULT 'proposed',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      resolved_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uq_browser_followthrough_public_v2260 (public_id),
      UNIQUE KEY uq_browser_followthrough_event_type_v2260 (continuity_id,event_public_id,proposal_type),
      INDEX idx_browser_followthrough_owner_v2260 (owner_user_id,status,created_at,id),
      INDEX idx_browser_followthrough_tracker_v2260 (continuity_id,status,id),
      CONSTRAINT fk_browser_followthrough_tracker_v2260 FOREIGN KEY (continuity_id) REFERENCES browser_transaction_continuities_v2260(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_followthrough_owner_v2260 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_browser_continuity_sha_v2260(mixed $value): string
{
    $hash=strtolower(trim((string)$value));
    return preg_match('/^[a-f0-9]{64}$/',$hash)?$hash:'';
}

function vp3_browser_continuity_family_v2260(string $submissionKind): string
{
    return match($submissionKind){
        'financial'=>'commerce',
        'booking'=>'booking',
        'application'=>'application',
        'communication','publishing'=>'communication',
        'account_change','agreement'=>'account',
        default=>'generic',
    };
}

function vp3_browser_continuity_initial_state_v2260(string $family): string
{
    return match($family){
        'commerce'=>'ordered',
        'booking'=>'confirmed',
        'application'=>'submitted',
        'communication'=>'sent',
        'account'=>'changed',
        default=>'active',
    };
}

function vp3_browser_continuity_states_v2260(string $family): array
{
    return match($family){
        'commerce'=>['ordered','processing','shipped','out_for_delivery','delivered','exception','cancelled','returned','refunded'],
        'booking'=>['confirmed','changed','upcoming','completed','cancelled','no_show'],
        'application'=>['submitted','under_review','needs_action','approved','rejected','withdrawn'],
        'communication'=>['sent','acknowledged','replied','resolved','closed'],
        'account'=>['changed','pending','active','cancelled','closed'],
        default=>['active','pending','needs_action','completed','cancelled','failed'],
    };
}

function vp3_browser_continuity_terminal_states_v2260(string $family): array
{
    return match($family){
        'commerce'=>['delivered','cancelled','refunded'],
        'booking'=>['completed','cancelled','no_show'],
        'application'=>['approved','rejected','withdrawn'],
        'communication'=>['resolved','closed'],
        'account'=>['cancelled','closed'],
        default=>['completed','cancelled','failed'],
    };
}

function vp3_browser_continuity_evidence_codes_v2260(): array
{
    return [
        'order_confirmed','processing_phrase','shipped_phrase','out_for_delivery_phrase','delivered_phrase','exception_phrase',
        'booking_confirmed','schedule_changed_phrase','upcoming_phrase','completed_phrase','cancelled_phrase','no_show_phrase',
        'application_submitted','under_review_phrase','needs_action_phrase','approved_phrase','rejected_phrase','withdrawn_phrase',
        'sent_phrase','acknowledged_phrase','replied_phrase','resolved_phrase','closed_phrase',
        'account_active_phrase','account_changed_phrase','pending_phrase','returned_phrase','refunded_phrase',
        'reference_present','schedule_present','amount_present'
    ];
}

function vp3_browser_continuity_filter_codes_v2260(mixed $value,array $allowed): array
{
    $set=array_fill_keys($allowed,true);$out=[];
    foreach(is_array($value)?$value:[] as $item){
        $key=strtolower(trim((string)$item));
        if(isset($set[$key]))$out[$key]=true;
    }
    return array_keys($out);
}

function vp3_browser_continuity_state_candidates_v2260(mixed $value,string $family): array
{
    $allowed=array_fill_keys(vp3_browser_continuity_states_v2260($family),true);
    $out=[];
    foreach(is_array($value)?$value:[] as $item){
        if(!is_array($item))continue;
        $state=strtolower(trim((string)($item['state']??'')));
        $evidence=strtolower(trim((string)($item['evidence_code']??'')));
        if(isset($allowed[$state])&&in_array($evidence,vp3_browser_continuity_evidence_codes_v2260(),true)){
            $out[]=['state'=>$state,'evidence_code'=>$evidence];
        }
    }
    return $out;
}

function vp3_browser_continuity_reference_candidates_v2260(mixed $value): array
{
    $out=[];
    foreach(array_slice(is_array($value)?$value:[],0,VP3_BROWSER_CONTINUITY_MAX_REFERENCE_CANDIDATES_V2260) as $item){
        if(!is_array($item))continue;
        $kind=vp3_browser_outcome_reference_kind_v2250($item['kind']??'');
        $hash=vp3_browser_continuity_sha_v2260($item['hash']??'');
        if($kind!==''&&$hash!=='')$out[$kind.'|'.$hash]=['kind'=>$kind,'hash'=>$hash];
    }
    return array_values($out);
}

function vp3_browser_continuity_public_v2260(array $row): array
{
    return [
        'continuity_id'=>(string)$row['public_id'],
        'intent_id'=>(string)$row['submission_intent_public_id'],
        'domain'=>(string)$row['domain'],
        'match_mode'=>(string)$row['match_mode'],
        'reference_kind'=>(string)$row['reference_kind'],
        'reference_present'=>trim((string)$row['reference_hash'])!=='',
        'lifecycle_family'=>(string)$row['lifecycle_family'],
        'lifecycle_state'=>(string)$row['lifecycle_state'],
        'previous_state'=>(string)$row['previous_state'],
        'tracking_status'=>(string)$row['tracking_status'],
        'closure_reason'=>(string)$row['closure_reason'],
        'last_checked_at'=>(string)($row['last_checked_at']??''),
        'last_change_at'=>(string)($row['last_change_at']??''),
        'closed_at'=>(string)($row['closed_at']??''),
        'created_at'=>(string)$row['created_at'],
        'updated_at'=>(string)$row['updated_at'],
    ];
}

function vp3_browser_continuity_event_public_v2260(array $row): array
{
    $evidence=json_decode((string)($row['evidence_codes_json']??'[]'),true);
    $changes=json_decode((string)($row['change_codes_json']??'[]'),true);
    return [
        'event_id'=>(string)$row['public_id'],
        'lifecycle_state'=>(string)$row['lifecycle_state'],
        'prior_state'=>(string)$row['prior_state'],
        'evidence_codes'=>is_array($evidence)?array_values(array_map('strval',$evidence)):[],
        'change_codes'=>is_array($changes)?array_values(array_map('strval',$changes)):[],
        'reference_match'=>!empty($row['reference_match']),
        'schedule_changed'=>in_array('schedule_changed',is_array($changes)?$changes:[],true),
        'amount_changed'=>in_array('amount_changed',is_array($changes)?$changes:[],true),
        'terminal_observed'=>!empty($row['terminal_observed']),
        'observed_at'=>(string)$row['observed_at'],
    ];
}

function vp3_browser_followthrough_public_v2260(array $row): array
{
    return [
        'proposal_id'=>(string)$row['public_id'],
        'continuity_id'=>(string)($row['continuity_public_id']??''),
        'proposal_type'=>(string)$row['proposal_type'],
        'reason_code'=>(string)$row['reason_code'],
        'requires_external_write'=>!empty($row['requires_external_write']),
        'status'=>(string)$row['status'],
        'created_at'=>(string)$row['created_at'],
        'resolved_at'=>(string)($row['resolved_at']??''),
    ];
}

function vp3_browser_continuity_row_v2260(PDO $pdo,int $uid,string $publicId,bool $lock=false): ?array
{
    if($uid<1||!preg_match('/^[a-f0-9-]{36}$/i',$publicId))return null;
    $stmt=$pdo->prepare("SELECT * FROM browser_transaction_continuities_v2260 WHERE public_id=? AND owner_user_id=? LIMIT 1".($lock?' FOR UPDATE':''));
    $stmt->execute([$publicId,$uid]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_continuity_for_intent_v2260(PDO $pdo,int $uid,string $intentId): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM browser_transaction_continuities_v2260 WHERE owner_user_id=? AND submission_intent_public_id=? LIMIT 1");
    $stmt->execute([$uid,$intentId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_continuity_latest_event_v2260(PDO $pdo,int $uid,int $continuityId): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM browser_transaction_continuity_events_v2260 WHERE continuity_id=? AND owner_user_id=? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$continuityId,$uid]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_continuity_proposals_v2260(PDO $pdo,int $uid,int $continuityId,int $limit=20): array
{
    $limit=max(1,min(40,$limit));
    $stmt=$pdo->prepare("SELECT p.*,c.public_id AS continuity_public_id
      FROM browser_transaction_followthrough_proposals_v2260 p
      INNER JOIN browser_transaction_continuities_v2260 c ON c.id=p.continuity_id
      WHERE p.owner_user_id=? AND p.continuity_id=? ORDER BY p.id DESC LIMIT ".$limit);
    $stmt->execute([$uid,$continuityId]);
    return array_map('vp3_browser_followthrough_public_v2260',$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_browser_continuity_ensure_v2260(
    PDO $pdo,array $user,string $namespace,string $runtimePublicId,string $intentId
): array {
    $uid=(int)($user['id']??0);
    $runtime=vp3_browser_outcome_runtime_v2250($pdo,$user,$namespace,$runtimePublicId);
    $intent=vp3_browser_outcome_intent_v2250($pdo,$runtime,$intentId);
    if((string)$intent['status']!=='completed')throw new RuntimeException('Continuity begins only after the transaction outcome is completed.');

    $existing=vp3_browser_continuity_for_intent_v2260($pdo,$uid,$intentId);
    if($existing)return ['continuity'=>vp3_browser_continuity_public_v2260($existing),'created'=>false];

    $recovery=vp3_browser_recovery_for_intent_v2250($pdo,$runtime,$intentId);
    if($recovery&&(string)$recovery['resolution_key']==='confirmed_not_submitted'){
        throw new RuntimeException('A transaction confirmed as not submitted cannot start continuity tracking.');
    }

    $outcome=$pdo->prepare("SELECT * FROM browser_transaction_outcomes_v2250
      WHERE owner_user_id=? AND submission_intent_public_id=? AND outcome_state='confirmed'
      ORDER BY id DESC LIMIT 1");
    $outcome->execute([$uid,$intentId]);$outcomeRow=$outcome->fetch(PDO::FETCH_ASSOC);
    if(!is_array($outcomeRow)&&!($recovery&&(string)$recovery['resolution_key']==='confirmed_completed')){
        throw new RuntimeException('A confirmed v22.50 destination outcome or explicit completed resolution is required.');
    }

    if(!is_array($outcomeRow)){
        $fallback=$pdo->prepare("SELECT * FROM browser_transaction_outcomes_v2250
          WHERE owner_user_id=? AND submission_intent_public_id=? ORDER BY id DESC LIMIT 1");
        $fallback->execute([$uid,$intentId]);$outcomeRow=$fallback->fetch(PDO::FETCH_ASSOC)?:[];
    }

    $referenceKind=(string)($outcomeRow['reference_kind']??'');
    $referenceHash=vp3_browser_continuity_sha_v2260($outcomeRow['reference_hash']??'');
    $matchMode=$referenceHash!==''?'reference':'manual';
    $family=vp3_browser_continuity_family_v2260((string)$intent['submission_kind']);
    $state=vp3_browser_continuity_initial_state_v2260($family);
    $public=vp3_extension_uuid_v2000();

    $stmt=$pdo->prepare("INSERT INTO browser_transaction_continuities_v2260
      (public_id,owner_user_id,submission_intent_public_id,original_runtime_public_id,workflow_run_id,domain,match_mode,reference_kind,reference_hash,lifecycle_family,lifecycle_state,last_change_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
    $stmt->execute([
        $public,$uid,$intentId,(string)$runtime['public_id'],(int)$runtime['workflow_run_id'],(string)$intent['domain'],
        $matchMode,$referenceKind,$referenceHash,$family,$state
    ]);
    $row=vp3_browser_continuity_row_v2260($pdo,$uid,$public);
    if(!$row)throw new RuntimeException('Transaction continuity could not be created.');

    create_notification($uid,'browser_transaction_continuity_started','Transaction follow-through started',
        $matchMode==='reference'
            ?'VP3 can recognize return pages for this transaction by its hashed reference on the approved domain.'
            :'This transaction has no reusable reference fingerprint, so return-page matching remains manual.',
        '/agent-workflows.php?id='.(int)$runtime['workflow_run_id'],'browser_transaction_continuity',(int)$row['id']);

    vp3_browser_runtime_event_v2200($pdo,$runtime,'transaction_continuity_started',
        'Durable transaction continuity started. Matching stores the approved domain and hashed transaction reference only.',
        'transaction.continuity',null,'active',['reason'=>$matchMode]);

    return ['continuity'=>vp3_browser_continuity_public_v2260($row),'created'=>true];
}

function vp3_browser_continuity_match_v2260(PDO $pdo,int $uid,string $domain,array $references): array
{
    if(!$references)return [];
    $hashes=array_values(array_unique(array_map(static fn(array $x)=>(string)$x['hash'],$references)));
    if(!$hashes)return [];
    $marks=implode(',',array_fill(0,count($hashes),'?'));
    $params=array_merge([$uid,$domain],$hashes);
    $stmt=$pdo->prepare("SELECT * FROM browser_transaction_continuities_v2260
      WHERE owner_user_id=? AND domain=? AND tracking_status='active' AND match_mode='reference' AND reference_hash IN ($marks)
      ORDER BY updated_at DESC,id DESC LIMIT 4");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function vp3_browser_continuity_change_codes_v2260(array $row,string $state,string $scheduleHash,string $amountHash): array
{
    $changes=[];
    $prior=(string)$row['lifecycle_state'];
    if($state!==$prior)$changes[]='state_changed';
    if($scheduleHash!==''&&(string)$row['schedule_hash']!==''&&!hash_equals((string)$row['schedule_hash'],$scheduleHash))$changes[]='schedule_changed';
    if($amountHash!==''&&(string)$row['amount_hash']!==''&&!hash_equals((string)$row['amount_hash'],$amountHash))$changes[]='amount_changed';
    if(in_array($state,['needs_action','exception','pending'],true))$changes[]='needs_action';
    if(in_array($state,['cancelled','withdrawn','closed'],true))$changes[]='cancellation';
    if(in_array($state,['shipped','out_for_delivery','delivered'],true))$changes[]='delivery_progress';
    if(in_array($state,['approved','rejected'],true))$changes[]='decision_received';
    if($state==='replied')$changes[]='reply_received';
    return array_values(array_unique($changes));
}

function vp3_browser_continuity_create_proposals_v2260(PDO $pdo,array $row,array $event,array $changes): array
{
    $family=(string)$row['lifecycle_family'];$state=(string)$event['lifecycle_state'];
    $proposals=[];
    if($family==='booking'&&(in_array($state,['confirmed','changed','upcoming'],true)||in_array('schedule_changed',$changes,true))){
        $proposals[]=['calendar_review','booking_schedule',false];
    }
    if($family==='application'&&in_array($state,['under_review','needs_action'],true)){
        $proposals[]=['task_review',$state==='needs_action'?'application_needs_action':'application_follow_up',false];
    }
    if($family==='commerce'&&in_array($state,['shipped','out_for_delivery'],true)){
        $proposals[]=['task_review','delivery_follow_up',false];
    }
    if($family==='communication'&&$state==='replied'){
        $proposals[]=['reply_review','reply_received',true];
    }
    if(in_array('cancellation',$changes,true)||in_array($state,['rejected','exception'],true)){
        $proposals[]=['corrective_action_review',$state,true];
    }
    if(in_array($state,vp3_browser_continuity_terminal_states_v2260($family),true)){
        $proposals[]=['close_tracking','terminal_state',false];
    }

    $created=[];
    foreach($proposals as [$type,$reason,$write]){
        $public=vp3_extension_uuid_v2000();
        try{
            $stmt=$pdo->prepare("INSERT INTO browser_transaction_followthrough_proposals_v2260
              (public_id,continuity_id,owner_user_id,event_public_id,proposal_type,reason_code,requires_external_write)
              VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$public,(int)$row['id'],(int)$row['owner_user_id'],(string)$event['public_id'],$type,$reason,$write?1:0]);
        }catch(PDOException $e){
            if((string)$e->getCode()==='23000')continue;
            throw $e;
        }
        $created[]=['proposal_id'=>$public,'continuity_id'=>(string)$row['public_id'],'proposal_type'=>$type,'reason_code'=>$reason,'requires_external_write'=>$write,'status'=>'proposed','created_at'=>gmdate('Y-m-d H:i:s'),'resolved_at'=>''];
    }
    return $created;
}

function vp3_browser_continuity_notify_change_v2260(array $row,array $event,array $changes): void
{
    if(!$changes)return;
    $state=str_replace('_',' ',(string)$event['lifecycle_state']);
    $important=(bool)array_intersect($changes,['state_changed','schedule_changed','amount_changed','needs_action','cancellation','delivery_progress','decision_received','reply_received']);
    if(!$important)return;
    $summary=[];
    foreach($changes as $change)$summary[]=str_replace('_',' ',$change);
    $family=(string)$row['lifecycle_family'];
    $attention=(bool)array_intersect($changes,['needs_action','cancellation','decision_received']);
    $type=$attention?'browser_transaction_needs_attention':match($family){
        'commerce'=>'browser_transaction_order_update',
        'booking'=>'browser_transaction_booking_update',
        'communication'=>'browser_transaction_message_update',
        default=>'browser_transaction_workflow_update',
    };
    create_notification(
        (int)$row['owner_user_id'],
        $type,
        'Transaction update: '.ucwords($state),
        'VP3 recognized a return page for an existing transaction and detected '.implode(', ',$summary).'. Review the transaction before taking any external action.',
        (int)$row['workflow_run_id']>0?'/agent-workflows.php?id='.(int)$row['workflow_run_id']:'/agent-workflows.php',
        'browser_transaction_continuity',(int)$row['id']
    );
}

function vp3_browser_continuity_observe_v2260(PDO $pdo,array $user,array $input): array
{
    $uid=(int)($user['id']??0);
    $domain=vp3_browser_web_domain_v2210($input['domain']??'');
    if($uid<1||$domain==='')throw new InvalidArgumentException('Approved transaction domain is required.');
    $references=vp3_browser_continuity_reference_candidates_v2260($input['reference_candidates']??[]);
    $matches=vp3_browser_continuity_match_v2260($pdo,$uid,$domain,$references);
    if(count($matches)===0)return ['matched'=>false,'reason'=>'no_reference_match','continuities'=>vp3_browser_continuity_list_v2260($pdo,$uid,$domain)];
    if(count($matches)>1)return ['matched'=>false,'reason'=>'ambiguous_reference_match','continuities'=>[]];

    $row=$matches[0];
    $page=vp3_browser_continuity_sha_v2260($input['page_fingerprint']??'');
    $content=vp3_browser_continuity_sha_v2260($input['content_hash']??'');
    $observation=vp3_browser_continuity_sha_v2260($input['observation_fingerprint']??'');
    if($page===''||$content===''||$observation==='')throw new InvalidArgumentException('Continuity observation fingerprints are required.');

    $matchedReference='';
    foreach($references as $ref){
        if(hash_equals((string)$row['reference_hash'],(string)$ref['hash'])){$matchedReference=(string)$ref['hash'];break;}
    }
    if($matchedReference==='')return ['matched'=>false,'reason'=>'reference_changed','continuities'=>[]];

    $candidates=vp3_browser_continuity_state_candidates_v2260($input['state_candidates']??[],(string)$row['lifecycle_family']);
    $state=$candidates?(string)$candidates[0]['state']:(string)$row['lifecycle_state'];
    $evidence=vp3_browser_continuity_filter_codes_v2260(array_column($candidates,'evidence_code'),vp3_browser_continuity_evidence_codes_v2260());
    if(!in_array('reference_present',$evidence,true))$evidence[]='reference_present';
    $scheduleHash=vp3_browser_continuity_sha_v2260($input['schedule_hash']??'');
    $amountHash=vp3_browser_continuity_sha_v2260($input['amount_hash']??'');

    $existing=$pdo->prepare("SELECT * FROM browser_transaction_continuity_events_v2260 WHERE continuity_id=? AND observation_fingerprint=? LIMIT 1");
    $existing->execute([(int)$row['id'],$observation]);
    $existingRow=$existing->fetch(PDO::FETCH_ASSOC);
    if(is_array($existingRow)){
        return [
            'matched'=>true,'deduplicated'=>true,'continuity'=>vp3_browser_continuity_public_v2260($row),
            'event'=>vp3_browser_continuity_event_public_v2260($existingRow),
            'proposals'=>vp3_browser_continuity_proposals_v2260($pdo,$uid,(int)$row['id'])
        ];
    }

    $count=$pdo->prepare("SELECT COUNT(*) FROM browser_transaction_continuity_events_v2260 WHERE continuity_id=?");
    $count->execute([(int)$row['id']]);
    if((int)$count->fetchColumn()>=VP3_BROWSER_CONTINUITY_MAX_EVENTS_V2260){
        throw new RuntimeException('This transaction reached its bounded continuity event limit. Close or archive it before further tracking.');
    }

    $changes=vp3_browser_continuity_change_codes_v2260($row,$state,$scheduleHash,$amountHash);
    $terminal=in_array($state,vp3_browser_continuity_terminal_states_v2260((string)$row['lifecycle_family']),true);
    $eventPublic=vp3_extension_uuid_v2000();

    $stmt=$pdo->prepare("INSERT INTO browser_transaction_continuity_events_v2260
      (public_id,continuity_id,owner_user_id,observation_fingerprint,page_fingerprint,content_hash,reference_hash,reference_match,lifecycle_state,prior_state,evidence_codes_json,change_codes_json,schedule_hash,amount_hash,terminal_observed)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $eventPublic,(int)$row['id'],$uid,$observation,$page,$content,$matchedReference,1,$state,(string)$row['lifecycle_state'],
        json_encode($evidence,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        json_encode($changes,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        $scheduleHash,$amountHash,$terminal?1:0
    ]);

    $newTracking=$terminal?'closed':'active';
    $closure=$terminal?'terminal_state_observed':'';
    $pdo->prepare("UPDATE browser_transaction_continuities_v2260
      SET previous_state=lifecycle_state,lifecycle_state=?,tracking_status=?,closure_reason=?,
          last_page_fingerprint=?,last_content_hash=?,last_observation_fingerprint=?,
          schedule_hash=CASE WHEN ?<>'' THEN ? ELSE schedule_hash END,
          amount_hash=CASE WHEN ?<>'' THEN ? ELSE amount_hash END,
          last_checked_at=UTC_TIMESTAMP(),
          last_change_at=CASE WHEN ?=1 THEN UTC_TIMESTAMP() ELSE last_change_at END,
          closed_at=CASE WHEN ?='closed' THEN UTC_TIMESTAMP() ELSE NULL END,
          updated_at=UTC_TIMESTAMP()
      WHERE id=? AND owner_user_id=?")
      ->execute([
          $state,$newTracking,$closure,$page,$content,$observation,
          $scheduleHash,$scheduleHash,$amountHash,$amountHash,$changes?1:0,$newTracking,
          (int)$row['id'],$uid
      ]);

    $fresh=vp3_browser_continuity_row_v2260($pdo,$uid,(string)$row['public_id']);
    $event=$pdo->prepare("SELECT * FROM browser_transaction_continuity_events_v2260 WHERE public_id=? AND owner_user_id=? LIMIT 1");
    $event->execute([$eventPublic,$uid]);$eventRow=$event->fetch(PDO::FETCH_ASSOC);
    if(!$fresh||!is_array($eventRow))throw new RuntimeException('Continuity observation could not be finalized.');

    $proposals=vp3_browser_continuity_create_proposals_v2260($pdo,$fresh,$eventRow,$changes);
    vp3_browser_continuity_notify_change_v2260($fresh,$eventRow,$changes);

    $runtime=$pdo->prepare("SELECT * FROM browser_agent_runtime_sessions_v2200 WHERE public_id=? AND owner_user_id=? LIMIT 1");
    $runtime->execute([(string)$fresh['original_runtime_public_id'],$uid]);
    $runtimeRow=$runtime->fetch(PDO::FETCH_ASSOC);
    if(is_array($runtimeRow)){
        vp3_browser_runtime_event_v2200($pdo,$runtimeRow,'transaction_continuity_observed',
            $changes?'Existing transaction changed: '.implode(', ',$changes).'.':'Existing transaction return page matched with no meaningful lifecycle change.',
            'transaction.continuity',null,$state,['reason'=>$terminal?'terminal':'active']);
    }

    return [
        'matched'=>true,'deduplicated'=>false,'continuity'=>vp3_browser_continuity_public_v2260($fresh),
        'event'=>vp3_browser_continuity_event_public_v2260($eventRow),'proposals'=>$proposals
    ];
}

function vp3_browser_continuity_list_v2260(PDO $pdo,int $uid,string $domain=''): array
{
    $where="owner_user_id=?";$params=[$uid];
    if($domain!==''){$where.=" AND domain=?";$params[]=$domain;}
    $stmt=$pdo->prepare("SELECT * FROM browser_transaction_continuities_v2260 WHERE $where ORDER BY tracking_status='active' DESC,updated_at DESC,id DESC LIMIT 40");
    $stmt->execute($params);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $items=[];
    foreach($rows as $row){
        $items[]=[
            'continuity'=>vp3_browser_continuity_public_v2260($row),
            'latest_event'=>($event=vp3_browser_continuity_latest_event_v2260($pdo,$uid,(int)$row['id']))?vp3_browser_continuity_event_public_v2260($event):null,
            'proposals'=>vp3_browser_continuity_proposals_v2260($pdo,$uid,(int)$row['id'],10),
        ];
    }
    return $items;
}

function vp3_browser_continuity_close_v2260(PDO $pdo,array $user,string $continuityId,string $reason='user_closed'): array
{
    $uid=(int)($user['id']??0);$row=vp3_browser_continuity_row_v2260($pdo,$uid,$continuityId,true);
    if(!$row)throw new RuntimeException('Transaction continuity was not found.');
    if((string)$row['tracking_status']==='closed')return ['continuity'=>vp3_browser_continuity_public_v2260($row)];
    if(!in_array($reason,['user_closed','no_longer_track','completed_elsewhere'],true))$reason='user_closed';
    $pdo->prepare("UPDATE browser_transaction_continuities_v2260 SET tracking_status='closed',closure_reason=?,closed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")
        ->execute([$reason,(int)$row['id'],$uid]);
    $fresh=vp3_browser_continuity_row_v2260($pdo,$uid,$continuityId);
    return ['continuity'=>vp3_browser_continuity_public_v2260($fresh?:$row)];
}

function vp3_browser_continuity_reopen_v2260(PDO $pdo,array $user,string $continuityId): array
{
    $uid=(int)($user['id']??0);$row=vp3_browser_continuity_row_v2260($pdo,$uid,$continuityId,true);
    if(!$row)throw new RuntimeException('Transaction continuity was not found.');
    if((string)$row['match_mode']!=='reference'||trim((string)$row['reference_hash'])===''){
        throw new RuntimeException('Automatic return-page matching cannot reopen without a hashed transaction reference.');
    }
    $pdo->prepare("UPDATE browser_transaction_continuities_v2260 SET tracking_status='active',closure_reason='',closed_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")
        ->execute([(int)$row['id'],$uid]);
    $fresh=vp3_browser_continuity_row_v2260($pdo,$uid,$continuityId);
    return ['continuity'=>vp3_browser_continuity_public_v2260($fresh?:$row)];
}

function vp3_browser_followthrough_resolve_v2260(PDO $pdo,array $user,string $proposalId,string $action): array
{
    $uid=(int)($user['id']??0);
    if(!preg_match('/^[a-f0-9-]{36}$/i',$proposalId))throw new InvalidArgumentException('Follow-through proposal is invalid.');
    if(!in_array($action,['acknowledge','dismiss'],true))throw new InvalidArgumentException('Unsupported follow-through proposal action.');
    $stmt=$pdo->prepare("SELECT p.*,c.public_id AS continuity_public_id FROM browser_transaction_followthrough_proposals_v2260 p
      INNER JOIN browser_transaction_continuities_v2260 c ON c.id=p.continuity_id
      WHERE p.public_id=? AND p.owner_user_id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$proposalId,$uid]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))throw new RuntimeException('Follow-through proposal was not found.');
    if((string)$row['status']!=='proposed')return ['proposal'=>vp3_browser_followthrough_public_v2260($row)];
    $status=$action==='acknowledge'?'acknowledged':'dismissed';
    $pdo->prepare("UPDATE browser_transaction_followthrough_proposals_v2260 SET status=?,resolved_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")
        ->execute([$status,(int)$row['id'],$uid]);
    $row['status']=$status;$row['resolved_at']=gmdate('Y-m-d H:i:s');
    return ['proposal'=>vp3_browser_followthrough_public_v2260($row)];
}

function vp3_browser_continuity_for_workflow_v2260(PDO $pdo,int $uid,int $workflowRunId): array
{
    if($uid<1||$workflowRunId<1||!vp3_browser_continuity_schema_ready_v2260($pdo)){
        return ['count'=>0,'active'=>0,'closed'=>0,'changes'=>0,'proposals'=>0,'continuities'=>[],'events'=>[],'followthrough'=>[]];
    }
    $stmt=$pdo->prepare("SELECT * FROM browser_transaction_continuities_v2260 WHERE owner_user_id=? AND workflow_run_id=? ORDER BY updated_at DESC,id DESC LIMIT 40");
    $stmt->execute([$uid,$workflowRunId]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $ids=array_map(static fn(array $x)=>(int)$x['id'],$rows);
    $events=[];$proposals=[];
    if($ids){
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $ev=$pdo->prepare("SELECT * FROM browser_transaction_continuity_events_v2260 WHERE owner_user_id=? AND continuity_id IN ($marks) ORDER BY id DESC LIMIT 80");
        $ev->execute(array_merge([$uid],$ids));$events=$ev->fetchAll(PDO::FETCH_ASSOC)?:[];
        $pp=$pdo->prepare("SELECT p.*,c.public_id AS continuity_public_id FROM browser_transaction_followthrough_proposals_v2260 p INNER JOIN browser_transaction_continuities_v2260 c ON c.id=p.continuity_id WHERE p.owner_user_id=? AND p.continuity_id IN ($marks) ORDER BY p.id DESC LIMIT 60");
        $pp->execute(array_merge([$uid],$ids));$proposals=$pp->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
    $active=0;$closed=0;foreach($rows as $row){if((string)$row['tracking_status']==='active')$active++;else$closed++;}
    $changeCount=0;foreach($events as $event){$changes=json_decode((string)($event['change_codes_json']??'[]'),true);if(is_array($changes)&&$changes)$changeCount++;}
    return [
        'count'=>count($rows),'active'=>$active,'closed'=>$closed,'changes'=>$changeCount,
        'proposals'=>count(array_filter($proposals,static fn(array $x)=>(string)$x['status']==='proposed')),
        'continuities'=>array_map('vp3_browser_continuity_public_v2260',$rows),
        'events'=>array_map('vp3_browser_continuity_event_public_v2260',$events),
        'followthrough'=>array_map('vp3_browser_followthrough_public_v2260',$proposals),
    ];
}
