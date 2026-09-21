<?php
declare(strict_types=1);

/**
 * VP3 Browser Companion v22.50 — Transaction Outcome Verification & Recovery.
 *
 * v22.40 proves exactly what was authorized and whether dispatch was observed.
 * v22.50 performs bounded, read-only destination verification after dispatch.
 * Raw page text and raw confirmation/reference values remain local in Chrome.
 * No recovery path automatically resubmits an external transaction.
 */
const VP3_BROWSER_OUTCOME_V2250='browser-transaction-outcome-v2250-20260921';
const VP3_BROWSER_OUTCOME_MAX_OBSERVATIONS_V2250=12;

require_once __DIR__.'/browser-transaction-safety-v2240.php';

function vp3_browser_outcome_schema_ready_v2250(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && table_exists('browser_transaction_outcomes_v2250')
        && table_exists('browser_transaction_recoveries_v2250')
        && vp3_browser_transaction_schema_ready_v2240($pdo);
}

function vp3_browser_outcome_ensure_schema_v2250(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_browser_transaction_ensure_schema_v2240($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_transaction_outcomes_v2250 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      public_id CHAR(36) NOT NULL,
      runtime_session_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      submission_intent_public_id CHAR(36) NOT NULL,
      domain VARCHAR(190) NOT NULL,
      observed_domain_hash CHAR(64) NOT NULL DEFAULT '',
      page_fingerprint CHAR(64) NOT NULL,
      content_hash CHAR(64) NOT NULL,
      observation_fingerprint CHAR(64) NOT NULL,
      outcome_state VARCHAR(24) NOT NULL DEFAULT 'ambiguous',
      evidence_strength VARCHAR(16) NOT NULL DEFAULT 'weak',
      evidence_codes_json TEXT NULL,
      reference_kind VARCHAR(32) NOT NULL DEFAULT '',
      reference_hash CHAR(64) NOT NULL DEFAULT '',
      observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_browser_outcome_public_v2250 (public_id),
      UNIQUE KEY uq_browser_outcome_observation_v2250 (owner_user_id,submission_intent_public_id,observation_fingerprint),
      INDEX idx_browser_outcome_runtime_v2250 (runtime_session_id,id),
      INDEX idx_browser_outcome_intent_v2250 (owner_user_id,submission_intent_public_id,id),
      CONSTRAINT fk_browser_outcome_runtime_v2250 FOREIGN KEY (runtime_session_id) REFERENCES browser_agent_runtime_sessions_v2200(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_outcome_owner_v2250 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_transaction_recoveries_v2250 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      public_id CHAR(36) NOT NULL,
      runtime_session_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      submission_intent_public_id CHAR(36) NOT NULL,
      resolution_key VARCHAR(40) NOT NULL,
      prior_submission_status VARCHAR(32) NOT NULL,
      prior_outcome_state VARCHAR(24) NOT NULL DEFAULT '',
      retry_allowed TINYINT(1) NOT NULL DEFAULT 0,
      guard_released TINYINT(1) NOT NULL DEFAULT 0,
      acknowledgement_code VARCHAR(64) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_browser_recovery_public_v2250 (public_id),
      UNIQUE KEY uq_browser_recovery_intent_v2250 (owner_user_id,submission_intent_public_id),
      INDEX idx_browser_recovery_runtime_v2250 (runtime_session_id,id),
      CONSTRAINT fk_browser_recovery_runtime_v2250 FOREIGN KEY (runtime_session_id) REFERENCES browser_agent_runtime_sessions_v2200(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_recovery_owner_v2250 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_browser_outcome_sha_v2250(mixed $value): string
{
    $hash=strtolower(trim((string)$value));
    return preg_match('/^[a-f0-9]{64}$/',$hash)?$hash:'';
}

function vp3_browser_outcome_states_v2250(): array
{
    return ['confirmed','pending','rejected','ambiguous','external_redirect'];
}

function vp3_browser_outcome_evidence_codes_v2250(): array
{
    return [
        'confirmation_heading','confirmation_phrase','confirmation_url_hint','reference_present',
        'receipt_keyword','form_absent','status_region','pending_phrase','rejection_phrase',
        'error_role','domain_changed_after_dispatch'
    ];
}

function vp3_browser_outcome_evidence_v2250(mixed $value): array
{
    $allowed=array_fill_keys(vp3_browser_outcome_evidence_codes_v2250(),true);
    $items=is_array($value)?$value:[];
    $out=[];
    foreach($items as $item){
        $key=strtolower(trim((string)$item));
        if(isset($allowed[$key]))$out[$key]=true;
    }
    return array_keys($out);
}

function vp3_browser_outcome_reference_kind_v2250(mixed $value): string
{
    $kind=strtolower(trim((string)$value));
    return in_array($kind,['order','confirmation','booking','reservation','application','reference','receipt','ticket'],true)?$kind:'';
}

function vp3_browser_outcome_validate_v2250(string $state,string $strength,array $evidence,bool $domainMatches): void
{
    if(!in_array($state,vp3_browser_outcome_states_v2250(),true))throw new InvalidArgumentException('Unsupported destination outcome state.');
    if(!in_array($strength,['strong','moderate','weak'],true))throw new InvalidArgumentException('Unsupported destination evidence strength.');

    if(!$domainMatches){
        if($state!=='external_redirect'||!in_array('domain_changed_after_dispatch',$evidence,true)){
            throw new RuntimeException('Cross-domain destination pages are not inspected by v22.50.');
        }
        return;
    }
    if($state==='external_redirect')throw new InvalidArgumentException('External redirect state requires a changed destination domain.');

    if($state==='confirmed'){
        $explicit=array_intersect($evidence,['confirmation_heading','confirmation_phrase']);
        $auxiliary=array_intersect($evidence,['confirmation_url_hint','reference_present','receipt_keyword','form_absent','status_region']);
        if($strength!=='strong'||count($explicit)<1||count($auxiliary)<1){
            throw new RuntimeException('Destination confirmation requires an explicit confirmation message plus an independent local signal.');
        }
    }elseif($state==='pending'&&!in_array('pending_phrase',$evidence,true)){
        throw new RuntimeException('Pending outcome requires an explicit pending signal.');
    }elseif($state==='rejected'&&!array_intersect($evidence,['rejection_phrase','error_role'])){
        throw new RuntimeException('Rejected outcome requires an explicit rejection or error signal.');
    }
}

function vp3_browser_outcome_runtime_v2250(PDO $pdo,array $user,string $namespace,string $runtimePublicId): array
{
    $uid=(int)($user['id']??0);
    $runtime=vp3_browser_runtime_row_v2200($pdo,$uid,$namespace,$runtimePublicId);
    if(!$runtime)throw new RuntimeException('Browser Agent Runtime session was not found.');
    $actions=vp3_browser_delegation_json_array_v2190($runtime['allowed_actions_json']??'');
    if(!in_array('transaction_submit',$actions,true)){
        throw new RuntimeException('Transaction outcome recovery is outside the original Browser delegation.');
    }
    return $runtime;
}

function vp3_browser_outcome_intent_v2250(PDO $pdo,array $runtime,string $intentId,bool $lock=false): array
{
    $row=vp3_browser_transaction_row_v2240($pdo,$runtime,$intentId,$lock);
    if(!$row)throw new RuntimeException('Transaction submission intent was not found.');
    return $row;
}

function vp3_browser_outcome_row_v2250(PDO $pdo,array $runtime,string $publicId): ?array
{
    if(!preg_match('/^[a-f0-9-]{36}$/i',$publicId))return null;
    $stmt=$pdo->prepare("SELECT * FROM browser_transaction_outcomes_v2250
      WHERE public_id=? AND runtime_session_id=? AND owner_user_id=? LIMIT 1");
    $stmt->execute([$publicId,(int)$runtime['id'],(int)$runtime['owner_user_id']]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_outcome_public_v2250(array $row): array
{
    $evidence=json_decode((string)($row['evidence_codes_json']??'[]'),true);
    return [
        'outcome_id'=>(string)$row['public_id'],
        'intent_id'=>(string)$row['submission_intent_public_id'],
        'domain'=>(string)$row['domain'],
        'outcome_state'=>(string)$row['outcome_state'],
        'evidence_strength'=>(string)$row['evidence_strength'],
        'evidence_codes'=>is_array($evidence)?array_values(array_map('strval',$evidence)):[],
        'reference_kind'=>(string)$row['reference_kind'],
        'reference_present'=>trim((string)$row['reference_hash'])!=='',
        'observed_at'=>(string)$row['observed_at'],
    ];
}

function vp3_browser_recovery_public_v2250(array $row): array
{
    return [
        'recovery_id'=>(string)$row['public_id'],
        'intent_id'=>(string)$row['submission_intent_public_id'],
        'resolution_key'=>(string)$row['resolution_key'],
        'prior_submission_status'=>(string)$row['prior_submission_status'],
        'prior_outcome_state'=>(string)$row['prior_outcome_state'],
        'retry_allowed'=>!empty($row['retry_allowed']),
        'guard_released'=>!empty($row['guard_released']),
        'created_at'=>(string)$row['created_at'],
    ];
}

function vp3_browser_outcome_latest_v2250(PDO $pdo,array $runtime,string $intentId): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM browser_transaction_outcomes_v2250
      WHERE runtime_session_id=? AND owner_user_id=? AND submission_intent_public_id=?
      ORDER BY id DESC LIMIT 1");
    $stmt->execute([(int)$runtime['id'],(int)$runtime['owner_user_id'],$intentId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_recovery_for_intent_v2250(PDO $pdo,array $runtime,string $intentId): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM browser_transaction_recoveries_v2250
      WHERE runtime_session_id=? AND owner_user_id=? AND submission_intent_public_id=? LIMIT 1");
    $stmt->execute([(int)$runtime['id'],(int)$runtime['owner_user_id'],$intentId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_outcome_options_v2250(array $intent,?array $recovery): array
{
    if($recovery)return ['recheck'=>false,'confirm_completed'=>false,'confirm_not_submitted'=>false,'retry_allowed'=>!empty($recovery['retry_allowed'])];
    $status=(string)$intent['status'];
    return [
        'recheck'=>in_array($status,['completed','uncertain'],true),
        'confirm_completed'=>in_array($status,['completed','uncertain'],true),
        'confirm_not_submitted'=>$status==='uncertain',
        'retry_allowed'=>false,
    ];
}

function vp3_browser_outcome_notify_v2250(array $runtime,array $intent,string $kind): void
{
    if(!function_exists('create_notification'))return;
    $uid=(int)$runtime['owner_user_id'];$runId=(int)$runtime['workflow_run_id'];$sourceId=(int)($intent['id']??0);
    if($uid<1||$runId<1||$sourceId<1)return;

    if($kind==='confirmed'){
        create_notification($uid,'browser_transaction_outcome_confirmed','Transaction destination confirmation found',
            'VP3 observed strong destination confirmation signals for the reviewed submission. This confirms the destination page state, not settlement or fulfillment.',
            '/agent-workflows.php?id='.$runId,'browser_submission_intent',$sourceId);
    }elseif($kind==='needs_review'){
        create_notification($uid,'browser_transaction_outcome_review','Transaction outcome needs review',
            'The destination is pending, rejected, ambiguous, or redirected. Do not retry until you review and resolve the outcome.',
            '/agent-workflows.php?id='.$runId,'browser_submission_intent',$sourceId);
    }
}

function vp3_browser_outcome_observe_v2250(
    PDO $pdo,array $user,string $namespace,string $runtimePublicId,string $intentId,array $input
): array {
    $runtime=vp3_browser_outcome_runtime_v2250($pdo,$user,$namespace,$runtimePublicId);
    $intent=vp3_browser_outcome_intent_v2250($pdo,$runtime,$intentId,true);
    if(!in_array((string)$intent['status'],['completed','uncertain'],true)){
        throw new RuntimeException('Outcome verification is available only after a dispatched submission.');
    }

    $countStmt=$pdo->prepare("SELECT COUNT(*) FROM browser_transaction_outcomes_v2250
      WHERE runtime_session_id=? AND owner_user_id=? AND submission_intent_public_id=?");
    $countStmt->execute([(int)$runtime['id'],(int)$runtime['owner_user_id'],$intentId]);
    if((int)$countStmt->fetchColumn()>=VP3_BROWSER_OUTCOME_MAX_OBSERVATIONS_V2250){
        throw new RuntimeException('This transaction reached its bounded destination recheck limit.');
    }

    $domainMatches=!empty($input['domain_matches']);
    $domain=(string)$intent['domain'];
    $observedDomainHash=vp3_browser_outcome_sha_v2250($input['observed_domain_hash']??'');
    if($domainMatches){
        $reported=vp3_browser_web_domain_v2210($input['domain']??'');
        if($reported!==$domain)throw new RuntimeException('The destination domain does not match the submitted transaction.');
        $observedDomainHash='';
    }elseif($observedDomainHash===''){
        throw new InvalidArgumentException('Changed destination domain fingerprint is required.');
    }

    $page=vp3_browser_outcome_sha_v2250($input['page_fingerprint']??'');
    $content=vp3_browser_outcome_sha_v2250($input['content_hash']??'');
    $observation=vp3_browser_outcome_sha_v2250($input['observation_fingerprint']??'');
    if($page===''||$content===''||$observation==='')throw new InvalidArgumentException('Destination verification fingerprints are required.');

    $state=strtolower(trim((string)($input['outcome_state']??'ambiguous')));
    $strength=strtolower(trim((string)($input['evidence_strength']??'weak')));
    $evidence=vp3_browser_outcome_evidence_v2250($input['evidence_codes']??[]);
    vp3_browser_outcome_validate_v2250($state,$strength,$evidence,$domainMatches);

    $referenceKind=vp3_browser_outcome_reference_kind_v2250($input['reference_kind']??'');
    $referenceHash=vp3_browser_outcome_sha_v2250($input['reference_hash']??'');
    if($referenceKind===''&&$referenceHash!=='')throw new InvalidArgumentException('Reference kind is required when a reference fingerprint is present.');
    if($referenceKind!==''&&$referenceHash==='')$referenceKind='';

    $existing=$pdo->prepare("SELECT * FROM browser_transaction_outcomes_v2250
      WHERE owner_user_id=? AND submission_intent_public_id=? AND observation_fingerprint=? LIMIT 1");
    $existing->execute([(int)$runtime['owner_user_id'],$intentId,$observation]);
    $existingRow=$existing->fetch(PDO::FETCH_ASSOC);
    if(is_array($existingRow)){
        $recovery=vp3_browser_recovery_for_intent_v2250($pdo,$runtime,$intentId);
        return [
            'outcome'=>vp3_browser_outcome_public_v2250($existingRow),
            'intent'=>vp3_browser_transaction_public_v2240($intent),
            'recovery'=>$recovery?vp3_browser_recovery_public_v2250($recovery):null,
            'recovery_options'=>vp3_browser_outcome_options_v2250($intent,$recovery),
            'deduplicated'=>true,
        ];
    }

    $public=vp3_extension_uuid_v2000();
    $stmt=$pdo->prepare("INSERT INTO browser_transaction_outcomes_v2250
      (public_id,runtime_session_id,owner_user_id,submission_intent_public_id,domain,observed_domain_hash,page_fingerprint,content_hash,observation_fingerprint,outcome_state,evidence_strength,evidence_codes_json,reference_kind,reference_hash)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $public,(int)$runtime['id'],(int)$runtime['owner_user_id'],$intentId,$domain,$observedDomainHash,$page,$content,$observation,
        $state,$strength,json_encode($evidence,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$referenceKind,$referenceHash
    ]);

    if($state==='confirmed'&&(string)$intent['status']==='uncertain'){
        $pdo->prepare("UPDATE browser_submission_intents_v2240
          SET status='completed',result_code='v2250_destination_confirmation_observed',verified_at=COALESCE(verified_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP()
          WHERE id=? AND owner_user_id=? AND status='uncertain'")
          ->execute([(int)$intent['id'],(int)$runtime['owner_user_id']]);
        $intent=vp3_browser_outcome_intent_v2250($pdo,$runtime,$intentId);
    }elseif((string)$intent['status']==='uncertain'){
        $code='v2250_destination_'.$state;
        $pdo->prepare("UPDATE browser_submission_intents_v2240 SET result_code=?,updated_at=UTC_TIMESTAMP()
          WHERE id=? AND owner_user_id=? AND status='uncertain'")
          ->execute([$code,(int)$intent['id'],(int)$runtime['owner_user_id']]);
        $intent=vp3_browser_outcome_intent_v2250($pdo,$runtime,$intentId);
    }

    $row=vp3_browser_outcome_row_v2250($pdo,$runtime,$public);
    if(!$row)throw new RuntimeException('Destination outcome receipt could not be created.');

    vp3_browser_runtime_event_v2200($pdo,$runtime,'transaction_outcome_observed',
        $state==='confirmed'
            ?'Strong destination confirmation signals were observed after the approved submission. This does not prove settlement or fulfillment.'
            :'Destination outcome requires review before any retry.',
        'transaction.outcome',null,$state,['reason'=>$strength]);

    vp3_browser_outcome_notify_v2250($runtime,$intent,$state==='confirmed'?'confirmed':'needs_review');
    $recovery=vp3_browser_recovery_for_intent_v2250($pdo,$runtime,$intentId);
    return [
        'outcome'=>vp3_browser_outcome_public_v2250($row),
        'intent'=>vp3_browser_transaction_public_v2240($intent),
        'recovery'=>$recovery?vp3_browser_recovery_public_v2250($recovery):null,
        'recovery_options'=>vp3_browser_outcome_options_v2250($intent,$recovery),
        'deduplicated'=>false,
    ];
}

function vp3_browser_outcome_resolve_v2250(
    PDO $pdo,array $user,string $namespace,string $runtimePublicId,string $intentId,string $resolution,string $ack
): array {
    $runtime=vp3_browser_outcome_runtime_v2250($pdo,$user,$namespace,$runtimePublicId);
    $started=!$pdo->inTransaction();
    if($started)$pdo->beginTransaction();

    try{
        $intent=vp3_browser_outcome_intent_v2250($pdo,$runtime,$intentId,true);
        if(!in_array((string)$intent['status'],['completed','uncertain'],true)){
            throw new RuntimeException('This transaction outcome can no longer be resolved from Browser Companion.');
        }
        if(vp3_browser_recovery_for_intent_v2250($pdo,$runtime,$intentId)){
            throw new RuntimeException('This transaction already has a final recovery resolution.');
        }

        $latest=vp3_browser_outcome_latest_v2250($pdo,$runtime,$intentId);
        $priorOutcome=$latest?(string)$latest['outcome_state']:'';

        $retryAllowed=false;$guardReleased=false;
        if($resolution==='confirmed_completed'){
            if($ack!=='reviewed_destination_completed')throw new InvalidArgumentException('Explicit completed-outcome acknowledgement is required.');
            if((string)$intent['status']==='uncertain'){
                $pdo->prepare("UPDATE browser_submission_intents_v2240
                  SET status='completed',result_code='v2250_user_confirmed_completed',verified_at=COALESCE(verified_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP()
                  WHERE id=? AND owner_user_id=? AND status='uncertain'")
                  ->execute([(int)$intent['id'],(int)$runtime['owner_user_id']]);
            }
        }elseif($resolution==='confirmed_not_submitted'){
            if($ack!=='reviewed_destination_not_submitted')throw new InvalidArgumentException('Explicit no-submission acknowledgement is required.');
            if((string)$intent['status']!=='uncertain')throw new RuntimeException('Retry can be unlocked only for an unresolved uncertain dispatch.');
            $guardKey=hash('sha256',(int)$runtime['owner_user_id'].'|'.(string)$intent['duplicate_key']);
            $pdo->prepare("DELETE FROM browser_submission_dispatch_guards_v2240 WHERE guard_key=? AND intent_public_id=?")
                ->execute([$guardKey,$intentId]);
            $guardReleased=true;$retryAllowed=true;
            $pdo->prepare("UPDATE browser_submission_intents_v2240
              SET status='failed',result_code='v2250_user_confirmed_not_submitted',failed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
              WHERE id=? AND owner_user_id=? AND status='uncertain'")
              ->execute([(int)$intent['id'],(int)$runtime['owner_user_id']]);
        }else{
            throw new InvalidArgumentException('Unsupported transaction recovery resolution.');
        }

        $public=vp3_extension_uuid_v2000();
        $stmt=$pdo->prepare("INSERT INTO browser_transaction_recoveries_v2250
          (public_id,runtime_session_id,owner_user_id,submission_intent_public_id,resolution_key,prior_submission_status,prior_outcome_state,retry_allowed,guard_released,acknowledgement_code)
          VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $public,(int)$runtime['id'],(int)$runtime['owner_user_id'],$intentId,$resolution,(string)$intent['status'],$priorOutcome,
            $retryAllowed?1:0,$guardReleased?1:0,$ack
        ]);
        if($started)$pdo->commit();
    }catch(Throwable $e){
        if($started&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }

    $intent=vp3_browser_outcome_intent_v2250($pdo,$runtime,$intentId);
    $recovery=vp3_browser_recovery_for_intent_v2250($pdo,$runtime,$intentId);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'transaction_outcome_resolved',
        $resolution==='confirmed_not_submitted'
            ?'User confirmed no submission occurred. Duplicate guard released; any retry requires a fresh v22.40 exact-form review.'
            :'User confirmed the destination completed the intended submission. This does not assert settlement or fulfillment.',
        'transaction.outcome',null,$resolution);

    return [
        'intent'=>vp3_browser_transaction_public_v2240($intent),
        'recovery'=>$recovery?vp3_browser_recovery_public_v2250($recovery):null,
        'recovery_options'=>vp3_browser_outcome_options_v2250($intent,$recovery),
    ];
}

function vp3_browser_outcome_list_v2250(PDO $pdo,array $runtime,int $limit=40): array
{
    $limit=max(1,min(40,$limit));
    $stmt=$pdo->prepare("SELECT * FROM browser_transaction_outcomes_v2250
      WHERE runtime_session_id=? AND owner_user_id=? ORDER BY id DESC LIMIT ".$limit);
    $stmt->execute([(int)$runtime['id'],(int)$runtime['owner_user_id']]);
    $outcomes=array_map('vp3_browser_outcome_public_v2250',$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);

    $rec=$pdo->prepare("SELECT * FROM browser_transaction_recoveries_v2250
      WHERE runtime_session_id=? AND owner_user_id=? ORDER BY id DESC LIMIT ".$limit);
    $rec->execute([(int)$runtime['id'],(int)$runtime['owner_user_id']]);
    $recoveries=array_map('vp3_browser_recovery_public_v2250',$rec->fetchAll(PDO::FETCH_ASSOC)?:[]);

    $latest=null;
    if($outcomes){
        $intentId=(string)($outcomes[0]['intent_id']??'');
        if($intentId!==''){
            $intent=vp3_browser_transaction_row_v2240($pdo,$runtime,$intentId,false);
            $recovery=vp3_browser_recovery_for_intent_v2250($pdo,$runtime,$intentId);
            if($intent){
                $latest=[
                    'outcome'=>$outcomes[0],
                    'intent'=>vp3_browser_transaction_public_v2240($intent),
                    'recovery'=>$recovery?vp3_browser_recovery_public_v2250($recovery):null,
                    'recovery_options'=>vp3_browser_outcome_options_v2250($intent,$recovery),
                ];
            }
        }
    }
    return ['outcomes'=>$outcomes,'recoveries'=>$recoveries,'latest'=>$latest];
}

function vp3_browser_outcome_for_workflow_v2250(PDO $pdo,int $uid,int $workflowRunId): array
{
    if($uid<1||$workflowRunId<1||!vp3_browser_outcome_schema_ready_v2250($pdo)){
        return ['count'=>0,'confirmed'=>0,'pending'=>0,'rejected'=>0,'ambiguous'=>0,'external_redirect'=>0,'resolved'=>0,'retry_allowed'=>0,'outcomes'=>[],'recoveries'=>[]];
    }
    $stmt=$pdo->prepare("SELECT o.* FROM browser_transaction_outcomes_v2250 o
      INNER JOIN browser_agent_runtime_sessions_v2200 r ON r.id=o.runtime_session_id
      WHERE o.owner_user_id=? AND r.owner_user_id=? AND r.workflow_run_id=?
      ORDER BY o.id DESC LIMIT 50");
    $stmt->execute([$uid,$uid,$workflowRunId]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $counts=['confirmed'=>0,'pending'=>0,'rejected'=>0,'ambiguous'=>0,'external_redirect'=>0];
    foreach($rows as $row){
        $state=(string)$row['outcome_state'];
        if(isset($counts[$state]))$counts[$state]++;
    }

    $rec=$pdo->prepare("SELECT x.* FROM browser_transaction_recoveries_v2250 x
      INNER JOIN browser_agent_runtime_sessions_v2200 r ON r.id=x.runtime_session_id
      WHERE x.owner_user_id=? AND r.owner_user_id=? AND r.workflow_run_id=?
      ORDER BY x.id DESC LIMIT 30");
    $rec->execute([$uid,$uid,$workflowRunId]);
    $recoveryRows=$rec->fetchAll(PDO::FETCH_ASSOC)?:[];
    $retry=0;foreach($recoveryRows as $row)if(!empty($row['retry_allowed']))$retry++;

    return [
        'count'=>count($rows),
        'confirmed'=>$counts['confirmed'],'pending'=>$counts['pending'],'rejected'=>$counts['rejected'],
        'ambiguous'=>$counts['ambiguous'],'external_redirect'=>$counts['external_redirect'],
        'resolved'=>count($recoveryRows),'retry_allowed'=>$retry,
        'outcomes'=>array_map('vp3_browser_outcome_public_v2250',$rows),
        'recoveries'=>array_map('vp3_browser_recovery_public_v2250',$recoveryRows),
    ];
}
