<?php
declare(strict_types=1);

/**
 * VP3 Browser Companion v22.40 — Transaction & Submission Safety.
 *
 * This layer does not replace v22.10 Controlled Web Interaction. v22.10
 * authorizes the submit control; v22.40 binds the user's final approval to
 * the exact locally-reviewed form state by hash before Chrome may dispatch
 * the submission. Raw field values never leave Chrome through this runtime.
 */
const VP3_BROWSER_TRANSACTION_V2240='browser-transaction-safety-v2240-20260920';
const VP3_BROWSER_TRANSACTION_PERMIT_SECONDS_V2240=60;
const VP3_BROWSER_TRANSACTION_MAX_FIELDS_V2240=80;
const VP3_BROWSER_TRANSACTION_DUPLICATE_WINDOW_SECONDS_V2240=600;

require_once __DIR__.'/browser-web-interaction-v2210.php';

function vp3_browser_transaction_schema_ready_v2240(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && table_exists('browser_submission_intents_v2240')
        && vp3_browser_web_schema_ready_v2210($pdo);
}

function vp3_browser_transaction_ensure_schema_v2240(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_browser_web_ensure_schema_v2210($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_submission_intents_v2240 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      public_id CHAR(36) NOT NULL,
      runtime_session_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      web_interaction_public_id CHAR(36) NOT NULL,
      domain VARCHAR(190) NOT NULL,
      page_fingerprint CHAR(64) NOT NULL,
      form_fingerprint CHAR(64) NOT NULL,
      submit_fingerprint CHAR(64) NOT NULL,
      review_hash CHAR(64) NOT NULL,
      duplicate_key CHAR(64) NOT NULL,
      submission_kind VARCHAR(32) NOT NULL DEFAULT 'form_submission',
      consequence_level VARCHAR(16) NOT NULL DEFAULT 'medium',
      consequence_flags_json TEXT NULL,
      field_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      sensitive_field_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      manual_only TINYINT(1) NOT NULL DEFAULT 0,
      status VARCHAR(32) NOT NULL DEFAULT 'approval_pending',
      approved_at DATETIME NULL,
      permit_hash CHAR(64) NULL,
      permit_expires_at DATETIME NULL,
      claimed_at DATETIME NULL,
      dispatched_at DATETIME NULL,
      verified_at DATETIME NULL,
      failed_at DATETIME NULL,
      cancelled_at DATETIME NULL,
      result_code VARCHAR(80) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_browser_submission_public_v2240 (public_id),
      INDEX idx_browser_submission_runtime_v2240 (runtime_session_id,status,id),
      INDEX idx_browser_submission_owner_v2240 (owner_user_id,created_at,id),
      INDEX idx_browser_submission_duplicate_v2240 (duplicate_key,status,created_at),
      CONSTRAINT fk_browser_submission_runtime_v2240 FOREIGN KEY (runtime_session_id) REFERENCES browser_agent_runtime_sessions_v2200(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_submission_owner_v2240 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_browser_transaction_sha_v2240(mixed $value): string
{
    $hash=strtolower(trim((string)$value));
    return preg_match('/^[a-f0-9]{64}$/',$hash)?$hash:'';
}

function vp3_browser_transaction_text_v2240(mixed $value,int $max=1200): string
{
    $text=preg_replace('/\s+/u',' ',trim((string)$value))??'';
    return mb_strimwidth($text,0,max,'');
}

function vp3_browser_transaction_flags_v2240(string $semantic): array
{
    $s=mb_strtolower($semantic);
    $flags=[];
    $tests=[
        'financial'=>'/\\b(?:purchase|buy|checkout|pay|payment|order|charge|subscribe|subscription)\\b/u',
        'transfer'=>'/\\b(?:wire|bank transfer|transfer funds|send money|crypto|cryptocurrency|withdraw)\\b/u',
        'booking'=>'/\\b(?:book|booking|reserve|reservation|appointment|ticket)\\b/u',
        'communication'=>'/\\b(?:send|message|email|invite|share)\\b/u',
        'publishing'=>'/\\b(?:publish|post|submit for review|make public)\\b/u',
        'application'=>'/\\b(?:application|apply|enroll|registration|register)\\b/u',
        'account_change'=>'/\\b(?:save changes|update account|create account|change plan|cancel subscription|unsubscribe)\\b/u',
        'destructive'=>'/\\b(?:delete|remove|destroy|close account|terminate account)\\b/u',
        'agreement'=>'/\\b(?:sign|signature|accept|agree|terms|consent|authorize)\\b/u',
    ];
    foreach($tests as $key=>$pattern)if(preg_match($pattern,$s))$flags[]=$key;
    if(!$flags)$flags[]='external_write';
    return array_values(array_unique($flags));
}

function vp3_browser_transaction_kind_v2240(array $flags): string
{
    foreach(['transfer','financial','booking','application','publishing','communication','account_change','agreement','destructive'] as $key){
        if(in_array($key,$flags,true))return $key;
    }
    return 'form_submission';
}

function vp3_browser_transaction_manual_only_v2240(array $flags,string $semantic): bool
{
    if(array_intersect($flags,['transfer','destructive']))return true;
    $s=mb_strtolower($semantic);
    return (bool)preg_match('/\b(?:wire transfer|bank transfer|transfer funds|send money|crypto|cryptocurrency|delete account|close account|terminate account|legal signature|notarize)\b/u',$s);
}

function vp3_browser_transaction_runtime_v2240(PDO $pdo,array $user,string $namespace,string $runtimePublicId): array
{
    $runtime=vp3_browser_web_runtime_v2210($pdo,$user,$namespace,$runtimePublicId);
    $actions=vp3_browser_delegation_json_array_v2190($runtime['allowed_actions_json']??'');
    if(!in_array('web_submit',$actions,true)||!in_array('transaction_submit',$actions,true)){
        throw new RuntimeException('Final submission is outside the approved Browser delegation.');
    }
    if(vp3_browser_runtime_risk_rank_v2200((string)$runtime['risk_budget'])<vp3_browser_runtime_risk_rank_v2200('medium')){
        throw new RuntimeException('Final submission requires a medium Browser delegation risk budget.');
    }
    return $runtime;
}

function vp3_browser_transaction_row_v2240(PDO $pdo,array $runtime,string $publicId,bool $lock=false): ?array
{
    if(!preg_match('/^[a-f0-9-]{36}$/i',$publicId))return null;
    $sql="SELECT * FROM browser_submission_intents_v2240
      WHERE public_id=? AND runtime_session_id=? AND owner_user_id=? LIMIT 1".($lock?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$publicId,(int)$runtime['id'],(int)$runtime['owner_user_id']]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_transaction_flags_json_v2240(mixed $json): array
{
    $data=is_array($json)?$json:json_decode((string)$json,true);
    return is_array($data)?array_values(array_filter(array_map('strval',$data))):[];
}

function vp3_browser_transaction_public_v2240(array $row): array
{
    return [
        'intent_id'=>(string)$row['public_id'],
        'web_interaction_id'=>(string)$row['web_interaction_public_id'],
        'domain'=>(string)$row['domain'],
        'submission_kind'=>(string)$row['submission_kind'],
        'consequence_level'=>(string)$row['consequence_level'],
        'consequence_flags'=>vp3_browser_transaction_flags_json_v2240($row['consequence_flags_json']??'[]'),
        'field_count'=>(int)$row['field_count'],
        'sensitive_field_count'=>(int)$row['sensitive_field_count'],
        'manual_only'=>!empty($row['manual_only']),
        'status'=>(string)$row['status'],
        'result_code'=>(string)$row['result_code'],
        'approved_at'=>(string)($row['approved_at']??''),
        'claimed_at'=>(string)($row['claimed_at']??''),
        'dispatched_at'=>(string)($row['dispatched_at']??''),
        'verified_at'=>(string)($row['verified_at']??''),
        'failed_at'=>(string)($row['failed_at']??''),
        'cancelled_at'=>(string)($row['cancelled_at']??''),
        'created_at'=>(string)$row['created_at'],
        'updated_at'=>(string)$row['updated_at'],
    ];
}

function vp3_browser_transaction_notify_v2240(array $runtime,array $row,string $kind): void
{
    if(!function_exists('create_notification'))return;
    $uid=(int)$runtime['owner_user_id'];$runId=(int)$runtime['workflow_run_id'];$sourceId=(int)($row['id']??0);
    if($uid<1||$runId<1||$sourceId<1)return;
    if($kind==='approval'){
        create_notification($uid,'browser_submission_approval_required','Browser Agent needs final submission approval',
            'Review the exact current form state in Chrome before authorizing the external submission.',
            '/agent-workflows.php?id='.$runId,'browser_submission_intent',$sourceId);
    }elseif($kind==='failed'){
        create_notification($uid,'browser_submission_needs_attention','Browser submission needs attention',
            'The final submission stopped or could not be verified. Review the receipt before trying again.',
            '/agent-workflows.php?id='.$runId,'browser_submission_intent',$sourceId);
    }
}

function vp3_browser_transaction_preview_v2240(
    PDO $pdo,array $user,string $namespace,array $session,string $runtimePublicId,array $input
): array {
    $runtime=vp3_browser_transaction_runtime_v2240($pdo,$user,$namespace,$runtimePublicId);
    $domain=vp3_browser_web_domain_v2210($input['domain']??'');
    if(!vp3_browser_web_allowed_domain_v2210($runtime,$domain))throw new RuntimeException('This domain is outside the approved Browser delegation.');

    $page=vp3_browser_transaction_sha_v2240($input['page_fingerprint']??'');
    $form=vp3_browser_transaction_sha_v2240($input['form_fingerprint']??'');
    $submit=vp3_browser_transaction_sha_v2240($input['submit_fingerprint']??'');
    $review=vp3_browser_transaction_sha_v2240($input['review_hash']??'');
    if($page===''||$form===''||$submit===''||$review==='')throw new InvalidArgumentException('Submission review fingerprints are required.');

    $webId=trim((string)($input['web_interaction_id']??''));
    $web=vp3_browser_web_row_v2210($pdo,$runtime,$webId,true);
    if(!$web||(string)$web['action_key']!=='submit')throw new RuntimeException('A v22.10 submit checkpoint is required before final submission review.');
    if((string)$web['status']!=='approval_pending')throw new RuntimeException('The underlying submit checkpoint is no longer waiting for approval.');
    if((string)$web['domain']!==$domain||!hash_equals((string)$web['page_fingerprint'],$page)||!hash_equals((string)$web['element_fingerprint'],$submit)){
        throw new RuntimeException('The v22.10 submit checkpoint no longer matches this page. Rescan and preview again.');
    }

    $fieldCount=max(0,min(VP3_BROWSER_TRANSACTION_MAX_FIELDS_V2240,(int)($input['field_count']??0)));
    if($fieldCount<1)throw new InvalidArgumentException('The reviewed form has no submit fields.');
    $sensitive=max(0,min($fieldCount,(int)($input['sensitive_field_count']??0)));
    $semantic=vp3_browser_transaction_text_v2240($input['semantic_text']??'',2000);
    $flags=vp3_browser_transaction_flags_v2240($semantic);
    $manual=vp3_browser_transaction_manual_only_v2240($flags,$semantic);
    $kind=vp3_browser_transaction_kind_v2240($flags);
    $level=$manual?'high':'medium';
    $duplicate=hash('sha256',(int)$runtime['id'].'|'.$domain.'|'.$form.'|'.$submit.'|'.$review);

    $pdo->prepare("UPDATE browser_submission_intents_v2240
      SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
      WHERE runtime_session_id=? AND owner_user_id=? AND web_interaction_public_id=? AND status='approval_pending'")
      ->execute([(int)$runtime['id'],(int)$runtime['owner_user_id'],$webId]);

    $public=vp3_extension_uuid_v2000();
    $stmt=$pdo->prepare("INSERT INTO browser_submission_intents_v2240
      (public_id,runtime_session_id,owner_user_id,web_interaction_public_id,domain,page_fingerprint,form_fingerprint,submit_fingerprint,review_hash,duplicate_key,submission_kind,consequence_level,consequence_flags_json,field_count,sensitive_field_count,manual_only,status)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'approval_pending')");
    $stmt->execute([
        $public,(int)$runtime['id'],(int)$runtime['owner_user_id'],$webId,$domain,$page,$form,$submit,$review,$duplicate,
        $kind,$level,json_encode($flags,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$fieldCount,$sensitive,$manual?1:0
    ]);
    $row=vp3_browser_transaction_row_v2240($pdo,$runtime,$public);
    if(!$row)throw new RuntimeException('Final submission review could not be created.');
    vp3_browser_runtime_event_v2200($pdo,$runtime,'submission_review_created',
        'Final external submission review created. Raw field values remained local in Chrome.',
        'transaction.submit',null,'approval_pending',['reason'=>$kind]);
    vp3_browser_transaction_notify_v2240($runtime,$row,'approval');
    return [
        'intent'=>vp3_browser_transaction_public_v2240($row),
        'review_contract'=>[
            'review_hash'=>$review,'form_fingerprint'=>$form,'submit_fingerprint'=>$submit,
            'page_fingerprint'=>$page,'acknowledgement'=>'I reviewed the current form values and authorize this exact external submission.',
            'permit_seconds'=>VP3_BROWSER_TRANSACTION_PERMIT_SECONDS_V2240,
            'manual_only'=>$manual,
        ],
    ];
}

function vp3_browser_transaction_approve_v2240(
    PDO $pdo,array $user,string $namespace,string $runtimePublicId,string $intentId,string $reviewHash,string $ack
): array {
    $runtime=vp3_browser_transaction_runtime_v2240($pdo,$user,$namespace,$runtimePublicId);
    $row=vp3_browser_transaction_row_v2240($pdo,$runtime,$intentId,true);
    if(!$row)throw new RuntimeException('Final submission review was not found.');
    if((string)$row['status']!=='approval_pending')throw new RuntimeException('This final submission review is no longer waiting for approval.');
    if(!hash_equals((string)$row['review_hash'],vp3_browser_transaction_sha_v2240($reviewHash)))throw new RuntimeException('The reviewed form state changed. Review it again.');
    if($ack!=='reviewed_exact_submission')throw new InvalidArgumentException('Explicit final-submission acknowledgement is required.');
    if(!empty($row['manual_only']))throw new RuntimeException('This high-impact submission is manual-only. VP3 will not dispatch it.');

    $web=vp3_browser_web_row_v2210($pdo,$runtime,(string)$row['web_interaction_public_id'],true);
    if(!$web||(string)$web['status']!=='approval_pending')throw new RuntimeException('The underlying v22.10 submit checkpoint changed. Review the form again.');
    $pdo->prepare("UPDATE browser_web_interactions_v2210 SET status='approved',confirmed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
      WHERE id=? AND owner_user_id=?")->execute([(int)$web['id'],(int)$runtime['owner_user_id']]);
    $pdo->prepare("UPDATE browser_submission_intents_v2240 SET status='approved',approved_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
      WHERE id=?")->execute([(int)$row['id']]);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'submission_approved',
        'User approved the exact locally-reviewed form state for one final external submission.',
        'transaction.submit',null,'approved');
    $fresh=vp3_browser_transaction_row_v2240($pdo,$runtime,$intentId);
    return ['intent'=>vp3_browser_transaction_public_v2240($fresh?:$row)];
}

function vp3_browser_transaction_claim_v2240(
    PDO $pdo,array $user,string $namespace,array $session,string $runtimePublicId,string $intentId,array $input
): array {
    $runtime=vp3_browser_transaction_runtime_v2240($pdo,$user,$namespace,$runtimePublicId);
    $row=vp3_browser_transaction_row_v2240($pdo,$runtime,$intentId,true);
    if(!$row)throw new RuntimeException('Final submission review was not found.');
    if((string)$row['status']!=='approved')throw new RuntimeException('This final submission is not approved for dispatch.');
    if(!empty($row['manual_only']))throw new RuntimeException('This submission is manual-only.');

    foreach(['page_fingerprint','form_fingerprint','submit_fingerprint','review_hash'] as $key){
        $actual=vp3_browser_transaction_sha_v2240($input[$key]??'');
        if($actual===''||!hash_equals((string)$row[$key],$actual)){
            $pdo->prepare("UPDATE browser_submission_intents_v2240 SET status='failed',result_code='review_state_changed',failed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")
                ->execute([(int)$row['id']]);
            throw new RuntimeException('The page or reviewed form changed after approval. Review the current submission again.');
        }
    }
    $domain=vp3_browser_web_domain_v2210($input['domain']??'');
    if($domain!==(string)$row['domain'])throw new RuntimeException('The active domain changed after approval.');

    $dup=$pdo->prepare("SELECT public_id FROM browser_submission_intents_v2240
      WHERE owner_user_id=? AND duplicate_key=? AND id<>? AND status IN ('executing','completed')
        AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".VP3_BROWSER_TRANSACTION_DUPLICATE_WINDOW_SECONDS_V2240." SECOND)
      ORDER BY id DESC LIMIT 1");
    $dup->execute([(int)$runtime['owner_user_id'],(string)$row['duplicate_key'],(int)$row['id']]);
    if((string)($dup->fetchColumn()?:'')!=='')throw new RuntimeException('Duplicate final submission blocked. Review the prior receipt before submitting again.');

    $token=bin2hex(random_bytes(24));$hash=hash('sha256',$token);
    $pdo->prepare("UPDATE browser_submission_intents_v2240
      SET status='executing',permit_hash=?,permit_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ".VP3_BROWSER_TRANSACTION_PERMIT_SECONDS_V2240." SECOND),claimed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
      WHERE id=?")->execute([$hash,(int)$row['id']]);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'submission_permit_claimed',
        'Chrome claimed a one-time final-submission permit bound to the reviewed form hash.',
        'transaction.submit',null,'executing');
    return [
        'permit_token'=>$token,
        'contract'=>[
            'intent_id'=>(string)$row['public_id'],
            'domain'=>(string)$row['domain'],
            'page_fingerprint'=>(string)$row['page_fingerprint'],
            'form_fingerprint'=>(string)$row['form_fingerprint'],
            'submit_fingerprint'=>(string)$row['submit_fingerprint'],
            'review_hash'=>(string)$row['review_hash'],
            'permit_seconds'=>VP3_BROWSER_TRANSACTION_PERMIT_SECONDS_V2240,
        ],
    ];
}

function vp3_browser_transaction_complete_v2240(
    PDO $pdo,array $user,string $namespace,string $runtimePublicId,string $intentId,string $permitToken,array $input
): array {
    $runtime=vp3_browser_transaction_runtime_v2240($pdo,$user,$namespace,$runtimePublicId);
    $row=vp3_browser_transaction_row_v2240($pdo,$runtime,$intentId,true);
    if(!$row)throw new RuntimeException('Final submission review was not found.');
    if((string)$row['status']!=='executing')throw new RuntimeException('This final submission is not executing.');
    if(empty($row['permit_hash'])||empty($row['permit_expires_at'])||strtotime((string)$row['permit_expires_at'])<time()){
        throw new RuntimeException('The final-submission permit expired.');
    }
    if(!hash_equals((string)$row['permit_hash'],hash('sha256',$permitToken)))throw new RuntimeException('The final-submission permit is invalid.');

    $dispatched=!empty($input['dispatched']);
    $verified=!empty($input['verified']);
    $code=preg_replace('/[^a-z0-9_\-]/','',strtolower(trim((string)($input['result_code']??($verified?'submission_dispatched':'submission_failed')))));
    $code=mb_strimwidth($code?:($verified?'submission_dispatched':'submission_failed'),0,80,'');
    $status=$dispatched?'completed':'failed';
    $pdo->prepare("UPDATE browser_submission_intents_v2240 SET status=?,permit_hash=NULL,permit_expires_at=NULL,result_code=?,
      dispatched_at=CASE WHEN ?=1 THEN UTC_TIMESTAMP() ELSE dispatched_at END,
      verified_at=CASE WHEN ?=1 THEN UTC_TIMESTAMP() ELSE verified_at END,
      failed_at=CASE WHEN ?=0 THEN UTC_TIMESTAMP() ELSE failed_at END,updated_at=UTC_TIMESTAMP()
      WHERE id=?")->execute([$status,$code,$dispatched?1:0,$verified?1:0,$dispatched?1:0,(int)$row['id']]);

    $web=vp3_browser_web_row_v2210($pdo,$runtime,(string)$row['web_interaction_public_id'],true);
    if($web){
        $pdo->prepare("UPDATE browser_web_interactions_v2210 SET status=?,verified=?,result_code=?,permit_hash=NULL,permit_expires_at=NULL,
          verified_at=CASE WHEN ?=1 THEN UTC_TIMESTAMP() ELSE verified_at END,
          failed_at=CASE WHEN ?=0 THEN UTC_TIMESTAMP() ELSE failed_at END,updated_at=UTC_TIMESTAMP()
          WHERE id=? AND owner_user_id=?")
          ->execute([$status,$dispatched?1:0,'v2240_'.$code,$dispatched?1:0,$dispatched?1:0,(int)$web['id'],(int)$runtime['owner_user_id']]);
    }
    if($dispatched){
        $pdo->prepare("UPDATE browser_agent_runtime_sessions_v2200 SET last_verified_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")
            ->execute([(int)$runtime['id'],(int)$runtime['owner_user_id']]);
    }
    vp3_browser_runtime_event_v2200($pdo,$runtime,$dispatched?'submission_dispatched':'submission_failed',
        $dispatched?'The approved external submission was dispatched once. The receipt does not claim downstream business success.':'The approved external submission was not dispatched.',
        'transaction.submit',null,$code);
    if(!$dispatched)vp3_browser_transaction_notify_v2240($runtime,$row,'failed');
    $fresh=vp3_browser_transaction_row_v2240($pdo,$runtime,$intentId);
    return ['intent'=>vp3_browser_transaction_public_v2240($fresh?:$row)];
}

function vp3_browser_transaction_cancel_v2240(PDO $pdo,array $user,string $namespace,string $runtimePublicId,string $intentId): array
{
    $runtime=vp3_browser_transaction_runtime_v2240($pdo,$user,$namespace,$runtimePublicId);
    $row=vp3_browser_transaction_row_v2240($pdo,$runtime,$intentId,true);
    if(!$row)throw new RuntimeException('Final submission review was not found.');
    if(!in_array((string)$row['status'],['approval_pending','approved'],true))throw new RuntimeException('This final submission review can no longer be cancelled.');
    $pdo->prepare("UPDATE browser_submission_intents_v2240 SET status='cancelled',permit_hash=NULL,permit_expires_at=NULL,cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")
        ->execute([(int)$row['id']]);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'submission_cancelled','Final external submission review cancelled.','transaction.submit',null,'cancelled');
    $fresh=vp3_browser_transaction_row_v2240($pdo,$runtime,$intentId);
    return ['intent'=>vp3_browser_transaction_public_v2240($fresh?:$row)];
}

function vp3_browser_transaction_list_v2240(PDO $pdo,array $runtime,int $limit=30): array
{
    $limit=max(1,min(30,$limit));
    $stmt=$pdo->prepare("SELECT * FROM browser_submission_intents_v2240 WHERE runtime_session_id=? AND owner_user_id=? ORDER BY id DESC LIMIT ".$limit);
    $stmt->execute([(int)$runtime['id'],(int)$runtime['owner_user_id']]);
    return array_map('vp3_browser_transaction_public_v2240',$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_browser_transaction_for_workflow_v2240(PDO $pdo,int $uid,int $workflowRunId): array
{
    if($uid<1||$workflowRunId<1||!vp3_browser_transaction_schema_ready_v2240($pdo))return ['count'=>0,'completed'=>0,'failed'=>0,'manual_only'=>0,'intents'=>[]];
    $stmt=$pdo->prepare("SELECT s.* FROM browser_submission_intents_v2240 s
      INNER JOIN browser_agent_runtime_sessions_v2200 r ON r.id=s.runtime_session_id
      WHERE s.owner_user_id=? AND r.owner_user_id=? AND r.workflow_run_id=? ORDER BY s.id DESC LIMIT 40");
    $stmt->execute([$uid,$uid,$workflowRunId]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $completed=0;$failed=0;$manual=0;
    foreach($rows as $row){
        if((string)$row['status']==='completed')$completed++;
        if((string)$row['status']==='failed')$failed++;
        if(!empty($row['manual_only']))$manual++;
    }
    return ['count'=>count($rows),'completed'=>$completed,'failed'=>$failed,'manual_only'=>$manual,'intents'=>array_map('vp3_browser_transaction_public_v2240',$rows)];
}
