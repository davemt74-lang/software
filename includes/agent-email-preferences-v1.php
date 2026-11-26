<?php
declare(strict_types=1);

require_once __DIR__.'/agent-email-preferences-schema-v1.php';
require_once __DIR__.'/agent-connection-email-chat-v1.php';
require_once __DIR__.'/agent-brain-v82.php';

const AGENT_EMAIL_PREFERENCES_CONTRACT_V1='vp3.email-preferences.v1';

function agent_email_preferences_hash_v1(mixed $value): string
{
    return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

function agent_email_preferences_text_hash_v1(array $payload): string
{
    return agent_email_preferences_hash_v1(['subject'=>$payload['subject'],'body'=>$payload['body']]);
}

function agent_email_preferences_binding_v1(array $draft,array $payload): string
{
    return agent_email_preferences_hash_v1([(int)$draft['owner_user_id'],(int)$draft['agent_id'],
        (int)$draft['conversation_id'],$draft['id'],(int)$draft['connection_id'],$draft['authorization_key'],
        $draft['account_email'],$draft['recipient'],$payload['reply']??null,$payload['outbound_followup']??null]);
}

function agent_email_preferences_capture_row_v1(PDO $pdo,string $draft,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM agent_email_preference_captures_v1 WHERE draft_id=?'.($lock?agent_commerce_lock_pa2($pdo):''));
    $q->execute([$draft]);$row=$q->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function agent_email_preferences_proposal_row_v1(PDO $pdo,int $owner,string $id,bool $lock=false): ?array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D',$id)) throw new DomainException('Choose a saved writing preference.',422);
    $q=$pdo->prepare('SELECT * FROM agent_email_preference_proposals_v1 WHERE id=? AND owner_user_id=?'.($lock?agent_commerce_lock_pa2($pdo):''));
    $q->execute([$id,$owner]);$row=$q->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

/** Called only inside a successful native generated-draft insert/CAS transaction. */
function agent_email_preferences_capture_generated_v1(PDO $pdo,array $savedDraft,string $generationKey): void
{
    if (!agent_email_preferences_ready_v1($pdo)) return;
    if (!$pdo->inTransaction()) throw new LogicException('Capture generated email inside its native write transaction.');
    if ($generationKey==='' || strlen($generationKey)>240) throw new InvalidArgumentException('Use the native email generation attempt.');
    $draft=agent_connection_email_chat_draft_v1($pdo,(int)$savedDraft['owner_user_id'],(int)$savedDraft['agent_id'],
        (int)$savedDraft['conversation_id'],(string)$savedDraft['id'],true);
    if ($draft['status']!=='prepared' || !hash_equals((string)$draft['message_enc'],(string)$savedDraft['message_enc'])) {
        throw new DomainException('The generated email changed before its provenance was saved.',409);
    }
    $payload=agent_connection_email_chat_payload_v1($draft);
    if (isset($payload['auto_revision']) || isset($payload['write']) || !isset($payload['soul_version'],$payload['soul_revision'])
        || trim($payload['body'])==='') return;
    $generation=hash('sha256',$generationKey);$text=agent_email_preferences_text_hash_v1($payload);
    $binding=agent_email_preferences_binding_v1($draft,$payload);
    $capture=agent_email_preferences_capture_row_v1($pdo,$draft['id'],true);
    if ($capture!==null && hash_equals($capture['generation_key'],$generation)) {
        if (!hash_equals($capture['generated_hash'],$text) || !hash_equals($capture['binding_hash'],$binding)) {
            throw new DomainException('The email generation attempt changed.',409);
        }
        return;
    }
    $id=substr(agent_email_preferences_hash_v1(['email-preference-capture-v1',$draft['id'],$generation,$text,$binding]),0,32);
    $encrypted=ai_encrypt_secret(json_encode(['subject'=>$payload['subject'],'body'=>$payload['body']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    $values=[$id,(int)$draft['owner_user_id'],(int)$draft['agent_id'],(int)$draft['conversation_id'],$generation,$binding,
        $text,$encrypted,(int)$payload['soul_version'],$payload['soul_revision'],agent_connection_email_chat_review_hash_v1($draft,false),time()];
    if ($capture===null) {
        $pdo->prepare('INSERT INTO agent_email_preference_captures_v1
            (capture_id,owner_user_id,agent_id,conversation_id,generation_key,binding_hash,generated_hash,generated_enc,
            generated_soul_version,generated_soul_revision,current_revision,generated_epoch,draft_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([...$values,$draft['id']]);
    } else {
        $pdo->prepare('UPDATE agent_email_preference_captures_v1 SET capture_id=?,owner_user_id=?,agent_id=?,conversation_id=?,
            generation_key=?,binding_hash=?,generated_hash=?,generated_enc=?,generated_soul_version=?,generated_soul_revision=?,
            current_revision=?,generated_epoch=?,edited_hash=NULL,edited_epoch=0,revision=revision+1 WHERE draft_id=?')
            ->execute([...$values,$draft['id']]);
    }
}

/** Ordinary saves record provenance only. They never prepare a suggestion or change the Email Soul. */
function agent_email_preferences_capture_edit_v1(PDO $pdo,array $before,array $after): void
{
    if (!agent_email_preferences_ready_v1($pdo)) return;
    if (!$pdo->inTransaction()) throw new LogicException('Capture owner edits inside the native email save transaction.');
    if (($before['id']??null)!==($after['id']??null)) throw new LogicException('Use the same native saved email.');
    $draft=agent_connection_email_chat_draft_v1($pdo,(int)$after['owner_user_id'],(int)$after['agent_id'],
        (int)$after['conversation_id'],(string)$after['id'],true);
    if ($draft['status']!=='prepared' || !hash_equals($draft['message_enc'],(string)$after['message_enc'])) {
        throw new DomainException('The saved email changed before its edit was recorded.',409);
    }
    $capture=agent_email_preferences_capture_row_v1($pdo,$draft['id'],true);
    if ($capture===null) return;
    $payload=agent_connection_email_chat_payload_v1($draft);
    if (isset($payload['write']) || isset($payload['auto_revision']) || !hash_equals($capture['current_revision'],agent_connection_email_chat_review_hash_v1($before,false))) return;
    $binding=agent_email_preferences_binding_v1($draft,$payload);$text=agent_email_preferences_text_hash_v1($payload);
    $edited=hash_equals($capture['binding_hash'],$binding) && !hash_equals($capture['generated_hash'],$text)?$text:null;
    $pdo->prepare('UPDATE agent_email_preference_captures_v1 SET current_revision=?,edited_hash=?,edited_epoch=?,revision=revision+1
        WHERE draft_id=? AND capture_id=? AND current_revision=?')->execute([
            agent_connection_email_chat_review_hash_v1($draft,false),$edited,$edited===null?0:time(),
            $draft['id'],$capture['capture_id'],$capture['current_revision']]);
}

/** Each public operation owns fresh short transactions; no old repeatable-read snapshot authorizes a dispatch. */
function agent_email_preferences_outside_v1(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('Open writing preferences outside a caller transaction.');
}

function agent_email_preferences_scope_v1(PDO $pdo,array $user,array $principal,int $conversation,string $draftId,bool $lock=false): array
{
    $owner=(int)($user['id']??0);
    $agent=($principal['kind']??'system')==='user_agent'?max(0,(int)($principal['agent_id']??0)):0;
    // Owner -> Gmail connection -> Agent/Chat -> draft -> capture. In
    // particular, never hold Chat/draft while waiting for the connection:
    // ordinary compose_save holds that connection before editing those rows.
    $lockedUser=null;
    if ($lock) {
        $q=$pdo->prepare('SELECT * FROM users WHERE id=? AND is_active=1'.agent_commerce_lock_pa2($pdo));$q->execute([$owner]);
        $lockedUser=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($lockedUser)) throw new DomainException('Your account access changed.',403);
    }
    $live=agent_connection_email_chat_authority_v1($pdo,$user,$principal,$conversation);
    if ($lockedUser!==null && array_intersect_key($lockedUser,array_flip(['id','role','is_active','password_hash']))
        !==array_intersect_key($live,array_flip(['id','role','is_active','password_hash']))) {
        throw new DomainException('Your current account authority changed.',403);
    }
    $locator=agent_connection_email_chat_draft_v1($pdo,(int)$live['id'],$agent,$conversation,$draftId);
    $locatedPayload=agent_connection_email_chat_payload_v1($locator);
    $capability=isset($locatedPayload['reply']) || isset($locatedPayload['outbound_followup'])?'reply':'send';
    $connection=agent_connections_require_grant_v1($pdo,(int)$live['id'],(int)$locator['connection_id'],$capability,$locator['authorization_key']);
    if ($lock) {
        if ($agent>0) {
            $q=$pdo->prepare('SELECT id FROM user_agents WHERE id=? AND owner_user_id=? AND is_active=1'.agent_commerce_lock_pa2($pdo));
            $q->execute([$agent,$owner]);
            if (!$q->fetchColumn()) throw new DomainException('The selected Agent is no longer available.',403);
        }
        $agentClause=$agent>0?'user_agent_id=?':'user_agent_id IS NULL';
        $q=$pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? AND '.$agentClause.agent_commerce_lock_pa2($pdo));
        $q->execute($agent>0?[$conversation,$owner,$agent]:[$conversation,$owner]);
        if (!$q->fetchColumn()) throw new DomainException('The private Agent Chat changed.',403);
    }
    $draft=$lock?agent_connection_email_chat_draft_v1($pdo,(int)$live['id'],$agent,$conversation,$draftId,true):$locator;
    if ((int)$draft['connection_id']!==(int)$locator['connection_id'] || !hash_equals($draft['authorization_key'],$locator['authorization_key'])
        || !hash_equals($draft['account_email'],$locator['account_email'])) throw new DomainException('The Gmail draft connection changed.',409);
    $payload=agent_connection_email_chat_payload_v1($draft);
    $required=isset($payload['reply']) || isset($payload['outbound_followup'])?'reply':'send';
    if ($required!==$capability) $connection=agent_connections_require_grant_v1($pdo,(int)$live['id'],(int)$draft['connection_id'],$required,$draft['authorization_key']);
    if (!hash_equals($connection['account_email'],$draft['account_email']) || !agent_connection_gmail_ready_v1()) {
        throw new DomainException('The Gmail account or provider configuration changed.',409);
    }
    $capture=agent_email_preferences_ready_v1($pdo)?agent_email_preferences_capture_row_v1($pdo,$draftId,$lock):null;
    $binding=agent_email_preferences_binding_v1($draft,$payload);$text=agent_email_preferences_text_hash_v1($payload);
    $editRevision=agent_connection_email_chat_review_hash_v1($draft,false);$reason=null;
    if ($capture===null) $reason='generated_baseline_unavailable';
    elseif ($draft['status']!=='prepared' || isset($payload['write']) || isset($payload['auto_revision'])) $reason='draft_unavailable';
    elseif ((int)$capture['owner_user_id']!==(int)$live['id'] || (int)$capture['agent_id']!==$agent
        || (int)$capture['conversation_id']!==$conversation || !hash_equals($capture['binding_hash'],$binding)) $reason='source_changed';
    elseif (!is_string($capture['edited_hash']) || !hash_equals($capture['edited_hash'],$text)
        || !hash_equals($capture['current_revision'],$editRevision) || (int)$capture['edited_epoch']<1
        || hash_equals($capture['generated_hash'],$text)) $reason='owner_edit_required';
    $revision=$capture===null?null:agent_email_preferences_hash_v1([$capture['capture_id'],$binding,$text]);
    $context=agent_email_preferences_hash_v1([(int)$live['id'],(string)($live['password_hash']??''),$agent,$conversation,
        (int)$connection['id'],$connection['authorization_key'],$connection['client_fingerprint'],$connection['account_email']]);
    return ['user'=>$live,'draft'=>$draft,'payload'=>$payload,'connection'=>$connection,'capture'=>$capture,'binding'=>$binding,
        'text_hash'=>$text,'edit_revision'=>$editRevision,'capture_revision'=>$revision,'context_hash'=>$context,
        'eligible'=>$reason===null,'reason'=>$reason,'soul'=>agent_brain_soul_profile_v1($live,'email')];
}

function agent_email_preferences_source_current_v1(array $scope,array $proposal): bool
{
    return $scope['eligible'] && $scope['capture']!==null
        && hash_equals($proposal['capture_id'],$scope['capture']['capture_id'])
        && hash_equals($proposal['edited_hash'],$scope['text_hash'])
        && hash_equals($proposal['binding_hash'],$scope['binding'])
        && hash_equals($proposal['context_hash'],$scope['context_hash']);
}

function agent_email_preferences_proposal_for_scope_v1(PDO $pdo,array $scope,bool $lock=false): ?array
{
    $capture=$scope['capture'];if ($capture===null) return null;
    $q=$pdo->prepare('SELECT * FROM agent_email_preference_proposals_v1 WHERE owner_user_id=? AND draft_id=?
        AND capture_id=? AND edited_hash=?'.($lock?agent_commerce_lock_pa2($pdo):''));
    $q->execute([(int)$scope['user']['id'],$scope['draft']['id'],$capture['capture_id'],$scope['text_hash']]);
    $row=$q->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
}

function agent_email_preferences_plain_v1(mixed $value): string
{
    if (!is_string($value) || !mb_check_encoding($value,'UTF-8')) throw new DomainException('Enter a writing preference.',422);
    $value=trim($value);
    if ($value==='' || mb_strlen($value)>600 || preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u',$value)) {
        throw new DomainException('Use one plain paragraph of up to 600 characters for the writing preference.',422);
    }
    return $value;
}

/** Never expose captured mail text, credentials or publication content through the preference status. */
function agent_email_preferences_public_v1(array $scope,?array $proposal,bool $installed=true): array
{
    $public=null;$reason=$scope['reason'];
    if ($proposal!==null) {
        $current=agent_email_preferences_source_current_v1($scope,$proposal);
        $encrypted=in_array($proposal['state'],['publishing','accepted','conflict'],true)
            && is_string($proposal['publish_preference_enc'])?$proposal['publish_preference_enc']:$proposal['preference_enc'];
        $text=is_string($encrypted)?ai_decrypt_secret($encrypted):'';
        $reason=$current?$proposal['reason']:'source_changed';
        $public=['id'=>$proposal['id'],'revision'=>(int)$proposal['revision'],'state'=>$proposal['state'],
            'preference'=>$text,'category'=>$proposal['category'],'reason'=>$reason,
            'accepted_version'=>$proposal['accepted_version']===null?null:(int)$proposal['accepted_version']];
    }
    return ['draft_id'=>$scope['draft']['id'],
        'eligible'=>$installed && $scope['eligible'] && $proposal===null,
        'reason'=>!$installed?'not_installed':$reason,
        'capture_id'=>$scope['capture']['capture_id']??null,'edit_revision'=>$scope['edit_revision'],
        'soul_version'=>(int)$scope['soul']['version'],'soul_revision'=>$scope['soul']['revision'],'proposal'=>$public];
}

function agent_email_preferences_live_v1(Closure $current): void
{
    if ($current()===false) throw new DomainException('Your private email access changed.',403);
}

function agent_email_preferences_input_id_v1(array $input,string $key,int $length=32): string
{
    $value=$input[$key]??null;
    if (!is_string($value) || !preg_match('/\A[a-f0-9]{'.$length.'}\z/D',$value)) {
        throw new DomainException('Open the current saved writing preference.',422);
    }
    return $value;
}

/** Reconcile only immutable publication receipts. This never writes a Soul or dispatches a model. */
function agent_email_preferences_reconcile_v1(PDO $pdo,array $user,array $row): array
{
    $state=$row['state'];$reason=$row['reason'];$now=time();$acceptedVersion=null;$acceptedRevision=null;
    if (in_array($state,['preparing','dispatched'],true) && (int)$row['lease_epoch']<=$now) {
        $reason=$state==='dispatched'?'dispatch_uncertain':'preparation_interrupted';$state='failed';
    } elseif ($state==='publishing') {
        $version=(int)$row['publish_version'];
        $files=agent_brain_soul_files_v1(agent_brain_soul_directory_v1($user,'email'));
        if ($version>0 && isset($files[$version])) {
            $content=agent_brain_soul_read_v1($files[$version]);
            if (is_string($row['publish_content_hash']) && hash_equals($row['publish_content_hash'],hash('sha256',$content))) {
                $state='accepted';$reason=null;$acceptedVersion=$version;
                $acceptedRevision=hash('sha256',(int)$user['id']."\0email\0".$version."\0".$content);
            } else {$state='conflict';$reason='publication_conflict';}
        } elseif ((int)$row['lease_epoch']<=$now) {
            $soul=agent_brain_soul_profile_v1($user,'email');
            if (is_string($row['publish_base_revision']) && hash_equals($row['publish_base_revision'],$soul['revision'])) {
                $reason='publication_unavailable';
            } else {$state='conflict';$reason='publication_conflict';}
        }
    }
    if ($state!==$row['state'] || $reason!==$row['reason']) {
        $pdo->prepare('UPDATE agent_email_preference_proposals_v1 SET state=?,reason=?,accepted_version=?,accepted_revision=?,
            revision=revision+1,updated_epoch=? WHERE id=? AND owner_user_id=? AND revision=? AND state=?')
            ->execute([$state,$reason,$acceptedVersion,$acceptedRevision,$now,$row['id'],(int)$user['id'],(int)$row['revision'],$row['state']]);
        $row=agent_email_preferences_proposal_row_v1($pdo,(int)$user['id'],$row['id'])??$row;
    }
    return $row;
}

function agent_email_preferences_status_v1(PDO $pdo,array $user,array $principal,int $conversation,string $draftId,?Closure $current=null): array
{
    agent_email_preferences_outside_v1($pdo);
    if ($current!==null) agent_email_preferences_live_v1($current);
    $installed=agent_email_preferences_ready_v1($pdo);
    $scope=agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_preferences_scope_v1($pdo,$user,$principal,$conversation,$draftId,true));
    $proposal=$installed?agent_email_preferences_proposal_for_scope_v1($pdo,$scope):null;
    if ($proposal!==null) $proposal=agent_email_preferences_reconcile_v1($pdo,$scope['user'],$proposal);
    if ($current!==null) agent_email_preferences_live_v1($current);
    $scope=agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_preferences_scope_v1($pdo,$user,$principal,$conversation,$draftId,true));
    $proposal=$installed?agent_email_preferences_proposal_for_scope_v1($pdo,$scope):null;
    return agent_email_preferences_public_v1($scope,$proposal,$installed);
}

function agent_email_preferences_failure_v1(Throwable $error,bool $dispatched): string
{
    if ($error instanceof DomainException || $error instanceof AgentConnectionExceptionV1) {
        return in_array((int)$error->getCode(),[401,403,419],true)?'authority_changed':'source_changed';
    }
    return $dispatched?'dispatch_uncertain':'model_unavailable';
}

function agent_email_preferences_prepare_v1(PDO $pdo,array $user,array $principal,int $conversation,array $input,
    Closure $current,?callable $inference=null): array
{
    agent_email_preferences_outside_v1($pdo);agent_email_preferences_live_v1($current);
    if (!agent_email_preferences_ready_v1($pdo)) throw new DomainException('Run Database Upgrade before remembering email preferences.',503);
    $draftId=agent_email_preferences_input_id_v1($input,'draft_id');
    $captureId=agent_email_preferences_input_id_v1($input,'capture_id');
    $editRevision=agent_email_preferences_input_id_v1($input,'edit_revision',64);
    $claim=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$principal,$conversation,$draftId,$captureId,$editRevision):array{
        $scope=agent_email_preferences_scope_v1($pdo,$user,$principal,$conversation,$draftId,true);
        if (!$scope['eligible'] || !hash_equals($captureId,$scope['capture']['capture_id'])
            || !hash_equals($editRevision,$scope['edit_revision'])) throw new DomainException('Save and reopen the current owner-edited draft.',409);
        $existing=agent_email_preferences_proposal_for_scope_v1($pdo,$scope,true);
        if ($existing!==null) return ['scope'=>$scope,'proposal'=>$existing,'claimed'=>false];
        $id=bin2hex(random_bytes(16));$attempt=bin2hex(random_bytes(16));$now=time();
        $pdo->prepare('INSERT INTO agent_email_preference_proposals_v1
            (id,owner_user_id,agent_id,conversation_id,draft_id,capture_id,edited_hash,binding_hash,context_hash,source_revision,
            soul_version,soul_revision,state,attempt_key,lease_epoch,created_epoch,updated_epoch)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,(int)$scope['user']['id'],(int)$scope['draft']['agent_id'],
                $conversation,$draftId,$captureId,$scope['text_hash'],$scope['binding'],$scope['context_hash'],$scope['capture_revision'],
                (int)$scope['soul']['version'],$scope['soul']['revision'],'preparing',$attempt,$now+90,$now,$now]);
        return ['scope'=>$scope,'proposal'=>agent_email_preferences_proposal_row_v1($pdo,(int)$scope['user']['id'],$id,true),'claimed'=>true];
    });
    $scope=$claim['scope'];$proposal=$claim['proposal'];
    if (!$claim['claimed']) {
        return agent_email_preferences_status_v1($pdo,$user,$principal,$conversation,$draftId,$current);
    }
    $dispatched=false;$reason=null;$result=null;
    try {
        $before=json_decode(ai_decrypt_secret($scope['capture']['generated_enc']),true,8,JSON_THROW_ON_ERROR);
        if (!is_array($before) || !is_string($before['subject']??null) || !is_string($before['body']??null)
            || !hash_equals($scope['capture']['generated_hash'],agent_email_preferences_text_hash_v1($before))) {
            throw new DomainException('The generated email provenance is unavailable.',409);
        }
        $prompt=json_encode(['email_soul'=>$scope['soul']['content'],
            'generated_text'=>['subject'=>$before['subject'],'body'=>$before['body']],
            'owner_edited_text'=>['subject'=>$scope['payload']['subject'],'body'=>$scope['payload']['body']]],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $beforeDispatch=static function()use($pdo,$user,$principal,$conversation,$draftId,$proposal,$current,&$dispatched):void{
            if ($dispatched) throw new DomainException('This writing-preference preparation was already dispatched.',409);
            agent_email_preferences_live_v1($current);
            agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$principal,$conversation,$draftId,$proposal):void{
                $live=agent_email_preferences_scope_v1($pdo,$user,$principal,$conversation,$draftId,true);
                $row=agent_email_preferences_proposal_row_v1($pdo,(int)$user['id'],$proposal['id'],true);
                if ($row===null || $row['state']!=='preparing' || !hash_equals($proposal['attempt_key'],$row['attempt_key'])
                    || (int)$row['lease_epoch']<=time() || !agent_email_preferences_source_current_v1($live,$row)
                    || !hash_equals($row['soul_revision'],$live['soul']['revision'])) {
                    throw new DomainException('This saved email or its writing preferences changed.',409);
                }
                $pdo->prepare("UPDATE agent_email_preference_proposals_v1 SET state='dispatched',dispatched_epoch=?,
                    revision=revision+1,updated_epoch=? WHERE id=? AND state='preparing' AND attempt_key=?")
                    ->execute([time(),time(),$row['id'],$row['attempt_key']]);
            });
            $dispatched=true;
        };
        $protocol='vp3.email-preference-suggestion.v1';
        $job=['background'=>false,'email_preference_suggestion'=>true,'conversation_id'=>$conversation,
            'job_key'=>'email-preference:'.$proposal['id']];
        if ($inference===null) {
            require_once __DIR__.'/agent-initiative-model-v1.php';
            $answer=vp3_initiative_infer_v1($pdo,$scope['user'],(int)$scope['draft']['agent_id'],$prompt,$protocol,true,$beforeDispatch,$job);
        } else $answer=$inference($prompt,$protocol,$beforeDispatch,$job,true);
        if (!$dispatched) throw new DomainException('The writing-preference dispatch was not authorized.',409);
        try {$value=vp3_tool_json_object_v1($answer,['category','preference'],10000);}
        catch (Throwable) {$value=[];$reason='invalid_response';}
        if (!array_key_exists('category',$value) || !is_string($value['preference']??null)) {$reason='invalid_response';}
        elseif ($value['category']===null && trim($value['preference'])==='') $result=['category'=>null,'preference'=>''];
        elseif (!in_array($value['category'],['tone','length','greeting','signoff','formatting','word_choice'],true)) $reason='invalid_response';
        else {
            try {$preference=agent_email_preferences_plain_v1($value['preference']);}
            catch (DomainException) {$preference='';$reason='invalid_response';}
            if ($reason===null && preg_match('~(?:[\p{N}\p{Sc}]|https?://|www\.|[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.[\p{L}]{2,})~iu',$preference)) $reason='unsafe_suggestion';
            if ($reason===null) $result=['category'=>$value['category'],'preference'=>$preference];
        }
        agent_email_preferences_live_v1($current);
        agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$principal,$conversation,$draftId,$proposal,$result,&$reason):void{
            $live=agent_email_preferences_scope_v1($pdo,$user,$principal,$conversation,$draftId,true);
            $row=agent_email_preferences_proposal_row_v1($pdo,(int)$user['id'],$proposal['id'],true);
            if ($row===null || $row['state']!=='dispatched' || !hash_equals($row['attempt_key'],$proposal['attempt_key'])) return;
            if (!agent_email_preferences_source_current_v1($live,$row)) $reason='source_changed';
            elseif (!hash_equals($row['soul_revision'],$live['soul']['revision'])) $reason='soul_changed';
            $state=$reason!==null?'failed':($result['category']===null?'no_style_change':'ready');
            $storedReason=$state==='no_style_change'?'no_style_change':$reason;
            $pdo->prepare('UPDATE agent_email_preference_proposals_v1 SET state=?,category=?,preference_enc=?,reason=?,
                finished_epoch=?,lease_epoch=0,revision=revision+1,updated_epoch=? WHERE id=? AND state=\'dispatched\' AND attempt_key=?')
                ->execute([$state,$reason===null?$result['category']:null,
                    $reason===null?ai_encrypt_secret($result['preference']):null,$storedReason,time(),time(),$row['id'],$row['attempt_key']]);
        });
    } catch (Throwable $error) {
        $reason=agent_email_preferences_failure_v1($error,$dispatched);
        $pdo->prepare("UPDATE agent_email_preference_proposals_v1 SET state='failed',reason=?,finished_epoch=?,lease_epoch=0,
            revision=revision+1,updated_epoch=? WHERE id=? AND attempt_key=? AND state IN ('preparing','dispatched')")
            ->execute([$reason,time(),time(),$proposal['id'],$proposal['attempt_key']]);
        if ($error instanceof PDOException) throw $error;
    }
    return agent_email_preferences_status_v1($pdo,$user,$principal,$conversation,$draftId,$current);
}

function agent_email_preferences_requested_v1(PDO $pdo,array $scope,array $input,bool $lock=false): array
{
    $id=agent_email_preferences_input_id_v1($input,'proposal_id');
    $row=agent_email_preferences_proposal_row_v1($pdo,(int)$scope['user']['id'],$id,$lock);
    if ($row===null || $row['draft_id']!==$scope['draft']['id'] || (int)$row['agent_id']!==(int)$scope['draft']['agent_id']
        || (int)$row['conversation_id']!==(int)$scope['draft']['conversation_id']) {
        throw new DomainException('This writing preference is unavailable in the selected private Chat.',404);
    }
    return $row;
}

function agent_email_preferences_expected_v1(array $row,array $input): void
{
    if (!is_int($input['proposal_revision']??null) || $input['proposal_revision']<1
        || (int)$row['revision']!==$input['proposal_revision']) {
        throw new DomainException('This writing preference changed. Refresh its current status.',409);
    }
}

function agent_email_preferences_accept_v1(PDO $pdo,array $user,array $principal,int $conversation,array $input,Closure $current): array
{
    agent_email_preferences_outside_v1($pdo);agent_email_preferences_live_v1($current);
    if (!agent_email_preferences_ready_v1($pdo)) throw new DomainException('Writing preference storage is unavailable.',503);
    $draftId=agent_email_preferences_input_id_v1($input,'draft_id');
    $scope=agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_preferences_scope_v1($pdo,$user,$principal,$conversation,$draftId,true));
    $row=agent_email_preferences_requested_v1($pdo,$scope,$input);
    $row=agent_email_preferences_reconcile_v1($pdo,$scope['user'],$row);
    if ($row['state']==='accepted') {
        agent_email_preferences_live_v1($current);
        $scope=agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_preferences_scope_v1($pdo,$user,$principal,$conversation,$draftId,true));
        return agent_email_preferences_public_v1($scope,$row);
    }
    agent_email_preferences_expected_v1($row,$input);
    if (!agent_email_preferences_source_current_v1($scope,$row)) throw new DomainException('This saved email changed. Review its current edit.',409);
    $preference=agent_email_preferences_plain_v1($input['preference']??null);
    $soulRevision=agent_email_preferences_input_id_v1($input,'soul_revision',64);
    if (!hash_equals($scope['soul']['revision'],$soulRevision)) throw new DomainException('The Email Soul changed. Review its current version.',409);
    $retry=$row['state']==='publishing' && $row['reason']==='publication_unavailable' && (int)$row['lease_epoch']<=time();
    if ($row['state']!=='ready' && !$retry) {
        if ($row['state']==='publishing') {
            agent_email_preferences_live_v1($current);
            $scope=agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_preferences_scope_v1($pdo,$user,$principal,$conversation,$draftId,true));
            return agent_email_preferences_public_v1($scope,$row);
        }
        throw new DomainException('This writing preference is not ready to save.',409);
    }
    if ($retry && (!is_string($row['publish_preference_enc']) || !hash_equals(ai_decrypt_secret($row['publish_preference_enc']),$preference)
        || !is_string($row['publish_base_revision']) || !hash_equals($row['publish_base_revision'],$soulRevision))) {
        throw new DomainException('Retry only the exact preference and Email Soul version already reviewed.',409);
    }
    $content=trim($scope['soul']['content'])."\n\n## Remembered email writing preference\n\n".$preference."\n";
    $publishTransaction=false;
    $beforePublish=static function(array $soul,int $version,string $published)use($pdo,$user,$principal,$conversation,$draftId,
        $row,$input,$preference,$soulRevision,$current,$retry,&$publishTransaction):void{
        agent_email_preferences_live_v1($current);
        $intended=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$principal,$conversation,$draftId,$row,$input,$preference,
            $soulRevision,$soul,$version,$published,$retry):array{
            $live=agent_email_preferences_scope_v1($pdo,$user,$principal,$conversation,$draftId,true);
            $saved=agent_email_preferences_requested_v1($pdo,$live,$input,true);
            agent_email_preferences_expected_v1($saved,$input);
            if (!agent_email_preferences_source_current_v1($live,$saved) || !hash_equals($soul['revision'],$soulRevision)
                || !hash_equals($live['soul']['revision'],$soulRevision) || (int)$soul['version']+1!==$version
                || $saved['state']!==($retry?'publishing':'ready')) {
                throw new DomainException('The reviewed email or Email Soul changed before saving.',409);
            }
            $hash=hash('sha256',$published);
            if ($retry && ((int)$saved['publish_version']!==$version || !is_string($saved['publish_content_hash'])
                || !hash_equals($saved['publish_content_hash'],$hash))) {
                throw new DomainException('The pending writing preference publication changed.',409);
            }
            $pdo->prepare("UPDATE agent_email_preference_proposals_v1 SET state='publishing',reason='publication_pending',
                publish_base_revision=?,publish_version=?,publish_content_hash=?,publish_preference_enc=?,lease_epoch=?,
                revision=revision+1,updated_epoch=? WHERE id=? AND revision=? AND state=?")
                ->execute([$soulRevision,$version,$hash,ai_encrypt_secret($preference),time()+90,time(),
                    $saved['id'],(int)$saved['revision'],$saved['state']]);
            return agent_email_preferences_proposal_row_v1($pdo,(int)$live['user']['id'],$saved['id'],true);
        });
        // The committed intent survives process loss. Refresh the actual
        // session outside SQL locks, then keep fresh native authority locked
        // across only the local immutable-file publication, never inference.
        agent_email_preferences_live_v1($current);
        $pdo->beginTransaction();$publishTransaction=true;
        $live=agent_email_preferences_scope_v1($pdo,$user,$principal,$conversation,$draftId,true);
        agent_email_preferences_live_v1($current);
        $saved=agent_email_preferences_proposal_row_v1($pdo,(int)$live['user']['id'],$intended['id'],true);
        if ($saved===null || $saved['state']!=='publishing' || (int)$saved['revision']!==(int)$intended['revision']
            || !agent_email_preferences_source_current_v1($live,$saved)
            || !hash_equals($soulRevision,$live['soul']['revision'])
            || !is_string($saved['publish_base_revision']) || !hash_equals($soulRevision,$saved['publish_base_revision'])
            || (int)$saved['publish_version']!==$version || !is_string($saved['publish_content_hash'])
            || !hash_equals($saved['publish_content_hash'],hash('sha256',$published))) {
            throw new DomainException('The reviewed email or private access changed before publication.',409);
        }
    };
    try {
        agent_brain_soul_update_v1($scope['user'],'email',$soulRevision,'save',$content,$beforePublish);
        if ($publishTransaction) {$pdo->commit();$publishTransaction=false;}
    } catch (Throwable $error) {
        if ($publishTransaction && $pdo->inTransaction()) $pdo->rollBack();
        $publishTransaction=false;
        $saved=agent_email_preferences_proposal_row_v1($pdo,(int)$scope['user']['id'],$row['id']);
        if ($saved!==null && $saved['state']==='publishing') {
            // The writer returned and released its lock. Reconciliation checks
            // the immutable file; an absent file permits an explicit exact retry.
            $pdo->prepare("UPDATE agent_email_preference_proposals_v1 SET lease_epoch=0 WHERE id=? AND state='publishing' AND revision=?")
                ->execute([$saved['id'],(int)$saved['revision']]);
            $saved['lease_epoch']=0;
            $row=agent_email_preferences_reconcile_v1($pdo,$scope['user'],$saved);
        } else throw $error;
        if ($error instanceof PDOException) throw $error;
    }
    $scope=agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_preferences_scope_v1($pdo,$user,$principal,$conversation,$draftId,true));
    $row=agent_email_preferences_proposal_row_v1($pdo,(int)$scope['user']['id'],$row['id'])??$row;
    $row=agent_email_preferences_reconcile_v1($pdo,$scope['user'],$row);
    agent_email_preferences_live_v1($current);
    $scope=agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_preferences_scope_v1($pdo,$user,$principal,$conversation,$draftId,true));
    return agent_email_preferences_public_v1($scope,$row);
}

function agent_email_preferences_dismiss_v1(PDO $pdo,array $user,array $principal,int $conversation,array $input,Closure $current): array
{
    agent_email_preferences_outside_v1($pdo);agent_email_preferences_live_v1($current);
    if (!agent_email_preferences_ready_v1($pdo)) throw new DomainException('Writing preference storage is unavailable.',503);
    $draftId=agent_email_preferences_input_id_v1($input,'draft_id');
    $result=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$principal,$conversation,$draftId,$input):array{
        $scope=agent_email_preferences_scope_v1($pdo,$user,$principal,$conversation,$draftId,true);
        $row=agent_email_preferences_requested_v1($pdo,$scope,$input,true);
        if ($row['state']==='dismissed') return [$scope,$row];
        agent_email_preferences_expected_v1($row,$input);
        if (in_array($row['state'],['publishing','accepted'],true)) {
            throw new DomainException('Inspect the current Email Soul to change an already published preference.',409);
        }
        $pdo->prepare("UPDATE agent_email_preference_proposals_v1 SET state='dismissed',reason='dismissed',lease_epoch=0,
            revision=revision+1,updated_epoch=? WHERE id=? AND revision=?")
            ->execute([time(),$row['id'],(int)$row['revision']]);
        return [$scope,agent_email_preferences_proposal_row_v1($pdo,(int)$scope['user']['id'],$row['id'],true)];
    });
    agent_email_preferences_live_v1($current);
    $scope=agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_preferences_scope_v1($pdo,$user,$principal,$conversation,$draftId,true));
    return agent_email_preferences_public_v1($scope,$result[1]);
}
