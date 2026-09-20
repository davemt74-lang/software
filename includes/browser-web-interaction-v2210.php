<?php
declare(strict_types=1);

/**
 * VP3 Browser Companion v22.10 — Controlled Web Interaction Runtime.
 *
 * Extends v22.00 with bounded, semantic DOM interaction proposals and
 * one-time execution permits. Raw DOM, selectors and typed values are never
 * persisted. The v21.90 delegation remains the authority envelope.
 */
const VP3_BROWSER_WEB_INTERACTION_V2210='browser-web-interaction-v2210-20260920';
const VP3_BROWSER_WEB_MAX_ELEMENTS_V2210=80;
const VP3_BROWSER_WEB_MAX_INTERACTIONS_V2210=24;
const VP3_BROWSER_WEB_MAX_VALUE_LENGTH_V2210=4000;
const VP3_BROWSER_WEB_PERMIT_SECONDS_V2210=90;

require_once __DIR__.'/browser-agent-runtime-v2200.php';

function vp3_browser_web_schema_ready_v2210(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        &&table_exists('browser_web_interactions_v2210')
        &&vp3_browser_runtime_schema_ready_v2200($pdo);
}

function vp3_browser_web_ensure_schema_v2210(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_browser_runtime_ensure_schema_v2200($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_web_interactions_v2210 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      runtime_session_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      action_key VARCHAR(40) NOT NULL,
      delegation_action_key VARCHAR(50) NOT NULL,
      domain VARCHAR(190) NOT NULL,
      page_fingerprint CHAR(64) NOT NULL,
      dom_fingerprint CHAR(64) NOT NULL,
      element_fingerprint CHAR(64) NOT NULL,
      element_kind VARCHAR(40) NOT NULL DEFAULT '',
      semantic_hash CHAR(64) NOT NULL,
      target_host VARCHAR(190) NOT NULL DEFAULT '',
      risk_level VARCHAR(16) NOT NULL DEFAULT 'low',
      requires_checkpoint TINYINT(1) NOT NULL DEFAULT 0,
      value_length INT UNSIGNED NOT NULL DEFAULT 0,
      status VARCHAR(32) NOT NULL DEFAULT 'proposed',
      permit_hash CHAR(64) NULL,
      permit_expires_at DATETIME NULL,
      confirmed_at DATETIME NULL,
      claimed_at DATETIME NULL,
      verified_at DATETIME NULL,
      failed_at DATETIME NULL,
      cancelled_at DATETIME NULL,
      verified TINYINT(1) NOT NULL DEFAULT 0,
      result_code VARCHAR(80) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_web_public_v2210 (public_id),
      INDEX idx_browser_web_runtime_v2210 (runtime_session_id,status,id),
      INDEX idx_browser_web_owner_v2210 (owner_user_id,created_at,id),
      CONSTRAINT fk_browser_web_runtime_v2210 FOREIGN KEY (runtime_session_id) REFERENCES browser_agent_runtime_sessions_v2200(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_web_owner_v2210 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_browser_web_actions_v2210(): array
{
    return [
        'click'=>[
            'label'=>'Click control','delegation_action'=>'web_click','risk_level'=>'low',
            'verification_mode'=>'dom_or_state','requires_checkpoint'=>false,
            'kinds'=>['button','input','control'],
        ],
        'focus'=>[
            'label'=>'Focus field','delegation_action'=>'web_focus','risk_level'=>'low',
            'verification_mode'=>'active_element','requires_checkpoint'=>false,
            'kinds'=>['input','textarea','select','button','control'],
        ],
        'type'=>[
            'label'=>'Type into field','delegation_action'=>'web_type','risk_level'=>'medium',
            'verification_mode'=>'field_value','requires_checkpoint'=>false,
            'kinds'=>['input','textarea'],
        ],
        'clear'=>[
            'label'=>'Clear field','delegation_action'=>'web_clear','risk_level'=>'medium',
            'verification_mode'=>'field_value','requires_checkpoint'=>false,
            'kinds'=>['input','textarea'],
        ],
        'select'=>[
            'label'=>'Select option','delegation_action'=>'web_select','risk_level'=>'medium',
            'verification_mode'=>'selected_option','requires_checkpoint'=>false,
            'kinds'=>['select'],
        ],
        'toggle'=>[
            'label'=>'Toggle control','delegation_action'=>'web_toggle','risk_level'=>'medium',
            'verification_mode'=>'checked_state','requires_checkpoint'=>false,
            'kinds'=>['checkbox','radio'],
        ],
        'scroll'=>[
            'label'=>'Scroll to control','delegation_action'=>'web_scroll','risk_level'=>'low',
            'verification_mode'=>'viewport','requires_checkpoint'=>false,
            'kinds'=>['link','button','input','textarea','select','checkbox','radio','control'],
        ],
        'open_link'=>[
            'label'=>'Open link','delegation_action'=>'web_open_link','risk_level'=>'low',
            'verification_mode'=>'same_domain_navigation','requires_checkpoint'=>false,
            'kinds'=>['link'],
        ],
        'submit'=>[
            'label'=>'Submit form','delegation_action'=>'web_submit','risk_level'=>'medium',
            'verification_mode'=>'submission','requires_checkpoint'=>true,
            'kinds'=>['button','input'],
        ],
    ];
}

function vp3_browser_web_public_actions_v2210(): array
{
    $out=[];
    foreach(vp3_browser_web_actions_v2210() as $key=>$row)$out[]=[
        'key'=>$key,'label'=>(string)$row['label'],'delegation_action'=>(string)$row['delegation_action'],
        'risk_level'=>(string)$row['risk_level'],'verification_mode'=>(string)$row['verification_mode'],
        'requires_checkpoint'=>(bool)$row['requires_checkpoint'],'kinds'=>(array)$row['kinds'],
    ];
    return $out;
}

function vp3_browser_web_action_v2210(string $key): ?array
{
    $actions=vp3_browser_web_actions_v2210();
    return $actions[$key]??null;
}

function vp3_browser_web_sha_v2210(mixed $value): string
{
    $hash=strtolower(trim((string)$value));
    return preg_match('/^[a-f0-9]{64}$/',$hash)?$hash:'';
}

function vp3_browser_web_domain_v2210(mixed $value): string
{
    return vp3_browser_delegation_domain_v2190((string)$value);
}

function vp3_browser_web_semantic_v2210(array $element): string
{
    $parts=[];
    foreach(['label','text','name','placeholder','aria_label','autocomplete','input_type','role','tag'] as $key){
        $value=trim((string)($element[$key]??''));
        if($value!=='')$parts[]=$value;
    }
    return mb_strtolower(mb_strimwidth(implode(' ',$parts),0,1200,''));
}

function vp3_browser_web_sensitive_v2210(array $element): bool
{
    $type=strtolower(trim((string)($element['input_type']??'')));
    $autocomplete=strtolower(trim((string)($element['autocomplete']??'')));
    $semantic=vp3_browser_web_semantic_v2210($element);
    if($type==='password')return true;
    if((bool)preg_match('/(?:current-password|new-password|one-time-code|cc-(?:number|csc|exp|name)|transaction-|webauthn)/',$autocomplete))return true;
    return (bool)preg_match('/\b(?:password|passcode|pin|security code|verification code|one[- ]time|otp|2fa|mfa|credit card|card number|cvv|cvc|social security|ssn|access token|api key|secret key|private key|medical|diagnosis|prescription|insurance member|bank account|routing number)\b/u',$semantic);
}

function vp3_browser_web_dangerous_v2210(array $element,string $action): bool
{
    if($action==='submit'||!empty($element['submit_like'])||!empty($element['dangerous']))return true;
    $semantic=vp3_browser_web_semantic_v2210($element);
    return (bool)preg_match('/\b(?:submit|send|publish|post|delete|remove|destroy|purchase|buy|order|checkout|pay|book|reserve|confirm|transfer|wire|sign|accept|agree|save changes|update account|create account|close account|cancel subscription|unsubscribe|invite|share)\b/u',$semantic);
}

function vp3_browser_web_kind_v2210(array $element): string
{
    $kind=strtolower(trim((string)($element['kind']??'')));
    $allowed=['link','button','input','textarea','select','checkbox','radio','control'];
    return in_array($kind,$allowed,true)?$kind:'control';
}

function vp3_browser_web_runtime_v2210(PDO $pdo,array $user,string $namespace,string $runtimePublicId): array
{
    $uid=(int)($user['id']??0);
    $runtime=vp3_browser_runtime_row_v2200($pdo,$uid,$namespace,$runtimePublicId);
    if(!$runtime)throw new RuntimeException('Browser Agent Runtime session was not found.');
    if(strtotime((string)$runtime['expires_at'])<time())throw new RuntimeException('Browser Agent Runtime authority has expired.');
    if((string)$runtime['status']==='paused')throw new RuntimeException('Resume the Browser Agent Runtime before interacting with this page.');
    if((string)$runtime['status']==='checkpoint')throw new RuntimeException('Complete the current Browser Runtime checkpoint before interacting with this page.');
    if(in_array((string)$runtime['status'],['completed','cancelled','expired'],true))throw new RuntimeException('This Browser Agent Runtime is no longer active.');
    return $runtime;
}

function vp3_browser_web_allowed_domain_v2210(array $runtime,string $domain): bool
{
    if($domain==='')return false;
    return in_array($domain,vp3_browser_delegation_json_array_v2190($runtime['allowed_domains_json']??''),true);
}

function vp3_browser_web_max_interactions_v2210(array $runtime): int
{
    return max(4,min(VP3_BROWSER_WEB_MAX_INTERACTIONS_V2210,max(1,(int)($runtime['max_steps']??1))*4));
}

function vp3_browser_web_expire_permits_v2210(PDO $pdo,array $runtime): void
{
    $pdo->prepare("UPDATE browser_web_interactions_v2210
      SET status='failed',result_code='permit_expired',permit_hash=NULL,permit_expires_at=NULL,failed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
      WHERE runtime_session_id=? AND owner_user_id=? AND status='executing' AND permit_expires_at IS NOT NULL AND permit_expires_at<UTC_TIMESTAMP()")
      ->execute([(int)$runtime['id'],(int)$runtime['owner_user_id']]);
}

function vp3_browser_web_used_interactions_v2210(PDO $pdo,array $runtime): int
{
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM browser_web_interactions_v2210
      WHERE runtime_session_id=? AND owner_user_id=? AND status NOT IN ('cancelled','proposed','approval_pending','approved')");
    $stmt->execute([(int)$runtime['id'],(int)$runtime['owner_user_id']]);
    return (int)$stmt->fetchColumn();
}

function vp3_browser_web_remaining_v2210(PDO $pdo,array $runtime): int
{
    vp3_browser_web_expire_permits_v2210($pdo,$runtime);
    return max(0,vp3_browser_web_max_interactions_v2210($runtime)-vp3_browser_web_used_interactions_v2210($pdo,$runtime));
}

function vp3_browser_web_authority_v2210(array $runtime,array $session,string $domain,array $action): void
{
    if(!vp3_browser_web_allowed_domain_v2210($runtime,$domain))throw new RuntimeException('This domain is outside the approved Browser delegation.');
    $allowed=vp3_browser_delegation_json_array_v2190($runtime['allowed_actions_json']??'');
    if(!in_array((string)$action['delegation_action'],$allowed,true))throw new RuntimeException('That Web interaction skill is outside the approved Browser delegation.');
    $caps=array_fill_keys((array)($session['capabilities']??[]),true);
    if(!isset($caps['agent.message']))throw new RuntimeException('Browser interaction authority is no longer available for this account.');
}

function vp3_browser_web_row_v2210(PDO $pdo,array $runtime,string $publicId,bool $lock=false): ?array
{
    if(!preg_match('/^[a-f0-9-]{36}$/i',$publicId))return null;
    $sql="SELECT * FROM browser_web_interactions_v2210
      WHERE public_id=? AND runtime_session_id=? AND owner_user_id=? LIMIT 1".($lock?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$publicId,(int)$runtime['id'],(int)$runtime['owner_user_id']]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_web_public_v2210(array $row): array
{
    $meta=vp3_browser_web_action_v2210((string)$row['action_key'])??[];
    return [
        'interaction_id'=>(string)$row['public_id'],'action_key'=>(string)$row['action_key'],
        'label'=>(string)($meta['label']??str_replace('_',' ',(string)$row['action_key'])),
        'domain'=>(string)$row['domain'],'element_kind'=>(string)$row['element_kind'],
        'target_host'=>(string)$row['target_host'],'risk_level'=>(string)$row['risk_level'],
        'requires_checkpoint'=>!empty($row['requires_checkpoint']),'value_length'=>(int)$row['value_length'],
        'status'=>(string)$row['status'],'verified'=>!empty($row['verified']),
        'result_code'=>(string)$row['result_code'],'confirmed_at'=>(string)($row['confirmed_at']??''),
        'claimed_at'=>(string)($row['claimed_at']??''),'verified_at'=>(string)($row['verified_at']??''),
        'failed_at'=>(string)($row['failed_at']??''),'cancelled_at'=>(string)($row['cancelled_at']??''),
        'created_at'=>(string)$row['created_at'],'updated_at'=>(string)$row['updated_at'],
    ];
}

function vp3_browser_web_list_v2210(PDO $pdo,array $runtime,int $limit=30): array
{
    $limit=max(1,min(30,$limit));
    $stmt=$pdo->prepare("SELECT * FROM browser_web_interactions_v2210
      WHERE runtime_session_id=? AND owner_user_id=? ORDER BY id DESC LIMIT ".$limit);
    $stmt->execute([(int)$runtime['id'],(int)$runtime['owner_user_id']]);
    return array_map('vp3_browser_web_public_v2210',$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_browser_web_observe_v2210(PDO $pdo,array $user,string $namespace,array $session,string $runtimePublicId,array $input): array
{
    $runtime=vp3_browser_web_runtime_v2210($pdo,$user,$namespace,$runtimePublicId);
    $domain=vp3_browser_web_domain_v2210($input['domain']??'');
    if(!vp3_browser_web_allowed_domain_v2210($runtime,$domain))throw new RuntimeException('This page is outside the approved Browser delegation.');
    $page=vp3_browser_web_sha_v2210($input['page_fingerprint']??'');
    $dom=vp3_browser_web_sha_v2210($input['dom_fingerprint']??'');
    if($page===''||$dom==='')throw new InvalidArgumentException('Browser page fingerprints are required.');
    $count=max(0,min(VP3_BROWSER_WEB_MAX_ELEMENTS_V2210,(int)($input['element_count']??0)));
    $epoch=max(0,(int)($input['mutation_epoch']??0));
    vp3_browser_runtime_event_v2200($pdo,$runtime,'dom_observed','Controlled Web Runtime observed '.$count.' interactive controls without persisting raw DOM.','web.observe',null,'observed',[
        'reason'=>'mutation_epoch_'.$epoch
    ]);
    return [
        'domain'=>$domain,'element_count'=>$count,'mutation_epoch'=>$epoch,
        'remaining_interactions'=>vp3_browser_web_remaining_v2210($pdo,$runtime),
        'max_interactions'=>vp3_browser_web_max_interactions_v2210($runtime),
        'page_fingerprint'=>$page,'dom_fingerprint'=>$dom,
    ];
}

function vp3_browser_web_preview_v2210(PDO $pdo,array $user,string $namespace,array $session,string $runtimePublicId,array $input): array
{
    $runtime=vp3_browser_web_runtime_v2210($pdo,$user,$namespace,$runtimePublicId);
    if(vp3_browser_web_remaining_v2210($pdo,$runtime)<1)throw new RuntimeException('This Browser Runtime reached its bounded Web interaction limit.');
    $actionKey=strtolower(trim((string)($input['action_key']??'')));
    $action=vp3_browser_web_action_v2210($actionKey);
    if(!$action)throw new InvalidArgumentException('Unknown Web interaction skill.');
    $domain=vp3_browser_web_domain_v2210($input['domain']??'');
    vp3_browser_web_authority_v2210($runtime,$session,$domain,$action);

    $page=vp3_browser_web_sha_v2210($input['page_fingerprint']??'');
    $dom=vp3_browser_web_sha_v2210($input['dom_fingerprint']??'');
    $elementFp=vp3_browser_web_sha_v2210($input['element_fingerprint']??'');
    if($page===''||$dom===''||$elementFp==='')throw new InvalidArgumentException('Web interaction fingerprints are required.');

    $element=is_array($input['element']??null)?$input['element']:[];
    $kind=vp3_browser_web_kind_v2210($element);
    if(!in_array($kind,(array)$action['kinds'],true))throw new RuntimeException('That interaction is not registered for this type of control.');
    if(vp3_browser_web_sensitive_v2210($element)&&in_array($actionKey,['type','clear','select','toggle','submit'],true)){
        throw new RuntimeException('Sensitive fields require manual entry and cannot be changed by Browser Runtime.');
    }

    $targetHost=vp3_browser_web_domain_v2210($input['target_host']??'');
    if($actionKey==='open_link'){
        if($kind!=='link'||$targetHost==='')throw new RuntimeException('Open Link requires a normal HTTP(S) link.');
        if($targetHost!==$domain)throw new RuntimeException('v22.10 Open Link is limited to the current approved domain. Multi-site navigation belongs to the next runtime phase.');
    }else $targetHost='';

    $danger=vp3_browser_web_dangerous_v2210($element,$actionKey);
    $risk=(string)$action['risk_level'];
    if($danger&&vp3_browser_runtime_risk_rank_v2200($risk)<vp3_browser_runtime_risk_rank_v2200('medium'))$risk='medium';
    if(vp3_browser_runtime_risk_rank_v2200($risk)>vp3_browser_runtime_risk_rank_v2200((string)$runtime['risk_budget'])){
        throw new RuntimeException('That interaction exceeds the approved Browser delegation risk budget.');
    }
    $checkpoint=!empty($action['requires_checkpoint'])||$danger;
    $valueLength=max(0,min(VP3_BROWSER_WEB_MAX_VALUE_LENGTH_V2210,(int)($input['value_length']??0)));
    if($actionKey==='type'&&$valueLength<1)throw new InvalidArgumentException('Type requires a non-empty value.');
    if($actionKey!=='type'&&$actionKey!=='select'&&$actionKey!=='toggle')$valueLength=0;

    $semantic=vp3_browser_web_semantic_v2210($element);
    $semanticHash=hash('sha256',$semantic);
    $public=vp3_extension_uuid_v2000();
    $status=$checkpoint?'approval_pending':'proposed';
    $stmt=$pdo->prepare("INSERT INTO browser_web_interactions_v2210
      (public_id,runtime_session_id,owner_user_id,action_key,delegation_action_key,domain,page_fingerprint,dom_fingerprint,element_fingerprint,element_kind,semantic_hash,target_host,risk_level,requires_checkpoint,value_length,status)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $public,(int)$runtime['id'],(int)$runtime['owner_user_id'],$actionKey,(string)$action['delegation_action'],
        $domain,$page,$dom,$elementFp,$kind,$semanticHash,$targetHost,$risk,$checkpoint?1:0,$valueLength,$status
    ]);
    $row=vp3_browser_web_row_v2210($pdo,$runtime,$public);
    if(!$row)throw new RuntimeException('Web interaction proposal could not be created.');
    vp3_browser_runtime_event_v2200($pdo,$runtime,'interaction_proposed','Controlled Web interaction proposed: '.(string)$action['label'].'.','web.'.$actionKey,null,$checkpoint?'checkpoint':'proposed',[
        'verification_mode'=>(string)$action['verification_mode']
    ]);
    if($checkpoint)vp3_browser_runtime_event_v2200($pdo,$runtime,'interaction_checkpoint','This Web interaction requires explicit confirmation before Chrome can claim an execution permit.','web.'.$actionKey,null,'checkpoint',[
        'verification_mode'=>(string)$action['verification_mode']
    ]);
    return [
        'proposal'=>vp3_browser_web_public_v2210($row),
        'verification_mode'=>(string)$action['verification_mode'],
        'remaining_interactions'=>vp3_browser_web_remaining_v2210($pdo,$runtime),
    ];
}

function vp3_browser_web_confirm_v2210(PDO $pdo,array $user,string $namespace,string $runtimePublicId,string $publicId): array
{
    $runtime=vp3_browser_web_runtime_v2210($pdo,$user,$namespace,$runtimePublicId);
    $row=vp3_browser_web_row_v2210($pdo,$runtime,$publicId,true);
    if(!$row)throw new RuntimeException('Web interaction proposal was not found.');
    if((string)$row['status']!=='approval_pending')throw new RuntimeException('This Web interaction is not waiting for confirmation.');
    $pdo->prepare("UPDATE browser_web_interactions_v2210 SET status='approved',confirmed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")
        ->execute([(int)$row['id'],(int)$runtime['owner_user_id']]);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'interaction_confirmed','User explicitly confirmed the controlled Web interaction.','web.'.(string)$row['action_key'],null,'approved');
    $fresh=vp3_browser_web_row_v2210($pdo,$runtime,$publicId);
    return ['proposal'=>vp3_browser_web_public_v2210($fresh?:$row)];
}

function vp3_browser_web_claim_v2210(PDO $pdo,array $user,string $namespace,array $session,string $runtimePublicId,string $publicId,array $input): array
{
    $runtime=vp3_browser_web_runtime_v2210($pdo,$user,$namespace,$runtimePublicId);
    if(vp3_browser_web_remaining_v2210($pdo,$runtime)<1)throw new RuntimeException('This Browser Runtime reached its bounded Web interaction limit.');
    $row=vp3_browser_web_row_v2210($pdo,$runtime,$publicId,true);
    if(!$row)throw new RuntimeException('Web interaction proposal was not found.');
    $action=vp3_browser_web_action_v2210((string)$row['action_key']);
    if(!$action)throw new RuntimeException('Web interaction skill is no longer registered.');
    vp3_browser_web_authority_v2210($runtime,$session,(string)$row['domain'],$action);

    $expectedStatus=!empty($row['requires_checkpoint'])?'approved':'proposed';
    if((string)$row['status']!==$expectedStatus)throw new RuntimeException('This Web interaction is not ready to execute.');
    $domain=vp3_browser_web_domain_v2210($input['domain']??'');
    $page=vp3_browser_web_sha_v2210($input['page_fingerprint']??'');
    if($domain!==(string)$row['domain']||$page===''||!hash_equals((string)$row['page_fingerprint'],$page)){
        throw new RuntimeException('The active page changed. Scan the page again before executing this interaction.');
    }

    $token=bin2hex(random_bytes(24));$hash=hash('sha256',$token);
    $pdo->prepare("UPDATE browser_web_interactions_v2210
      SET status='executing',permit_hash=?,permit_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ".VP3_BROWSER_WEB_PERMIT_SECONDS_V2210." SECOND),claimed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
      WHERE id=? AND owner_user_id=?")->execute([$hash,(int)$row['id'],(int)$runtime['owner_user_id']]);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'interaction_started','Chrome claimed a one-time controlled Web interaction permit.','web.'.(string)$row['action_key'],null,'executing',[
        'verification_mode'=>(string)$action['verification_mode']
    ]);
    return [
        'permit_token'=>$token,
        'contract'=>[
            'interaction_id'=>(string)$row['public_id'],'action_key'=>(string)$row['action_key'],
            'domain'=>(string)$row['domain'],'page_fingerprint'=>(string)$row['page_fingerprint'],
            'element_fingerprint'=>(string)$row['element_fingerprint'],'target_host'=>(string)$row['target_host'],
            'risk_level'=>(string)$row['risk_level'],'requires_checkpoint'=>!empty($row['requires_checkpoint']),
            'value_length'=>(int)$row['value_length'],'verification_mode'=>(string)$action['verification_mode'],
        ],
    ];
}

function vp3_browser_web_complete_v2210(PDO $pdo,array $user,string $namespace,string $runtimePublicId,string $publicId,string $permitToken,array $input): array
{
    $runtime=vp3_browser_web_runtime_v2210($pdo,$user,$namespace,$runtimePublicId);
    $row=vp3_browser_web_row_v2210($pdo,$runtime,$publicId,true);
    if(!$row)throw new RuntimeException('Web interaction proposal was not found.');
    if((string)$row['status']!=='executing')throw new RuntimeException('This Web interaction is not executing.');
    if(empty($row['permit_hash'])||empty($row['permit_expires_at'])||strtotime((string)$row['permit_expires_at'])<time())throw new RuntimeException('The Web interaction permit expired.');
    if(!hash_equals((string)$row['permit_hash'],hash('sha256',$permitToken)))throw new RuntimeException('The Web interaction permit is invalid.');

    $verified=!empty($input['verified']);
    $code=preg_replace('/[^a-z0-9_\-]/','',strtolower(trim((string)($input['result_code']??($verified?'verified':'unverified')))));
    $code=mb_strimwidth($code?:($verified?'verified':'unverified'),0,80,'');
    $status=$verified?'completed':'failed';
    $pdo->prepare("UPDATE browser_web_interactions_v2210
      SET status=?,verified=?,result_code=?,permit_hash=NULL,permit_expires_at=NULL,
          verified_at=CASE WHEN ?=1 THEN UTC_TIMESTAMP() ELSE verified_at END,
          failed_at=CASE WHEN ?=0 THEN UTC_TIMESTAMP() ELSE failed_at END,
          updated_at=UTC_TIMESTAMP()
      WHERE id=? AND owner_user_id=?")->execute([
        $status,$verified?1:0,$code,$verified?1:0,$verified?1:0,(int)$row['id'],(int)$runtime['owner_user_id']
    ]);
    if($verified){
        $pdo->prepare("UPDATE browser_agent_runtime_sessions_v2200 SET last_verified_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")
            ->execute([(int)$runtime['id'],(int)$runtime['owner_user_id']]);
    }
    vp3_browser_runtime_event_v2200($pdo,$runtime,$verified?'interaction_verified':'interaction_failed',$verified?'Controlled Web interaction completed and its expected state was verified.':'Controlled Web interaction could not verify its expected state.','web.'.(string)$row['action_key'],null,$code);
    $fresh=vp3_browser_web_row_v2210($pdo,$runtime,$publicId);
    return [
        'proposal'=>vp3_browser_web_public_v2210($fresh?:$row),
        'remaining_interactions'=>vp3_browser_web_remaining_v2210($pdo,$runtime),
    ];
}

function vp3_browser_web_for_workflow_v2210(PDO $pdo,int $uid,int $workflowRunId): array
{
    if($uid<1||$workflowRunId<1||!vp3_browser_web_schema_ready_v2210($pdo))return ['count'=>0,'verified'=>0,'failed'=>0,'checkpointed'=>0,'interactions'=>[]];
    $stmt=$pdo->prepare("SELECT i.* FROM browser_web_interactions_v2210 i
      INNER JOIN browser_agent_runtime_sessions_v2200 r ON r.id=i.runtime_session_id
      WHERE i.owner_user_id=? AND r.owner_user_id=? AND r.workflow_run_id=?
      ORDER BY i.id DESC LIMIT 40");
    $stmt->execute([$uid,$uid,$workflowRunId]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $verified=0;$failed=0;$checkpointed=0;
    foreach($rows as $row){
        if(!empty($row['verified']))$verified++;
        if((string)$row['status']==='failed')$failed++;
        if(!empty($row['requires_checkpoint']))$checkpointed++;
    }
    return [
        'count'=>count($rows),'verified'=>$verified,'failed'=>$failed,'checkpointed'=>$checkpointed,
        'interactions'=>array_map('vp3_browser_web_public_v2210',$rows),
    ];
}

function vp3_browser_web_cancel_v2210(PDO $pdo,array $user,string $namespace,string $runtimePublicId,string $publicId): array
{
    $runtime=vp3_browser_web_runtime_v2210($pdo,$user,$namespace,$runtimePublicId);
    $row=vp3_browser_web_row_v2210($pdo,$runtime,$publicId,true);
    if(!$row)throw new RuntimeException('Web interaction proposal was not found.');
    if(!in_array((string)$row['status'],['proposed','approval_pending','approved'],true))throw new RuntimeException('This Web interaction can no longer be cancelled.');
    $pdo->prepare("UPDATE browser_web_interactions_v2210 SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),permit_hash=NULL,permit_expires_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")
        ->execute([(int)$row['id'],(int)$runtime['owner_user_id']]);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'interaction_cancelled','Controlled Web interaction proposal cancelled.','web.'.(string)$row['action_key'],null,'cancelled');
    $fresh=vp3_browser_web_row_v2210($pdo,$runtime,$publicId);
    return ['proposal'=>vp3_browser_web_public_v2210($fresh?:$row)];
}
