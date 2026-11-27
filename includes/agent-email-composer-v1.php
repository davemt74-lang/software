<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-email-chat-v1.php';
require_once __DIR__.'/agent-email-attachments-v1.php';
require_once __DIR__.'/agent-email-review-queue-v1.php';
require_once __DIR__.'/agent-email-followthrough-source-v1.php';

/** Saved drafts and versioned organic proposals have no retention deadline; send approvals still expire. */
function agent_email_composer_live_v1(array $draft, int $now): bool
{
    return (int)$draft['expires_at'] === 0 || (int)$draft['expires_at'] > $now;
}

function agent_email_composer_text_v1(mixed $value, int $limit, bool $multiline=false): string
{
    if (!is_string($value) || !mb_check_encoding($value,'UTF-8')) throw new DomainException('Enter valid email text.',422);
    $value = str_replace(["\r\n","\r"],"\n",trim($value));
    if (mb_strlen($value) > $limit || preg_match($multiline ? '/[\x00-\x08\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/',$value)) {
        throw new DomainException('Use a subject of up to 200 characters and a message of up to 6,000 characters.',422);
    }
    return $value;
}

function agent_email_composer_recipient_v1(mixed $value): string
{
    $value = agent_email_composer_text_v1($value,254);
    if ($value !== '' && !filter_var($value,FILTER_VALIDATE_EMAIL)) throw new DomainException('Enter one valid recipient email address.',422);
    return $value;
}

function agent_email_composer_complete_v1(array $draft, array $payload): bool
{
    try {
        if (agent_email_composer_recipient_v1($draft['recipient']) === '') return false;
        if (!isset($payload['reply']) && !isset($payload['outbound_followup']) && agent_email_composer_text_v1($payload['subject'],200) === '') return false;
        return agent_email_composer_text_v1($payload['body'],6000,true) !== '';
    } catch (DomainException $error) { return false; }
}

function agent_email_composer_validate_v1(array $draft, array &$payload): void
{
    if (isset($payload['composer'])) {
        $saved = $payload['composer'];
        if (!is_array($saved) || !is_int($saved['saved_at'] ?? null) || $saved['saved_at'] < 1
            || (int)$draft['expires_at'] !== 0) {
            throw new DomainException('The saved email draft is unavailable.',503);
        }
        if (($saved['version'] ?? null) === 1) {
            if (array_keys($saved) !== ['version','saved_at']) throw new DomainException('The saved email draft is unavailable.',503);
        } elseif (in_array($saved['version'] ?? null,[2,3],true)) {
            $followup = $saved['version'] === 3;
            if (array_keys($saved) !== ['version','saved_at','created_at','origin','source']
                || !is_int($saved['created_at']) || $saved['created_at'] < 1 || $saved['created_at'] > $saved['saved_at']
                || $saved['origin'] !== ($followup ? 'outbound_followup' : 'organic_reply')
                || !isset($payload[$followup ? 'outbound_followup' : 'reply']) || isset($payload['auto_revision'])
                || !is_array($saved['source'])) {
                throw new DomainException('The saved email proposal is unavailable.',503);
            }
            agent_email_review_queue_source_metadata_v1($saved['source']);
        } else throw new DomainException('The saved email lifecycle is unavailable.',503);
    } elseif ((int)$draft['expires_at'] === 0) {
        throw new DomainException('The saved email lifecycle is unavailable.',503);
    }
    if (array_key_exists('attachments',$payload)) {
        if (!is_array($payload['attachments'])) throw new DomainException('The saved email attachments are unavailable.',503);
        $payload['attachments'] = agent_email_attachments_manifest_v1($payload['attachments']);
    }
    if (array_key_exists('outbound_followup',$payload)) {
        if (!is_array($payload['outbound_followup']) || isset($payload['reply']) || isset($payload['auto_revision'])
            || ($payload['composer']['version'] ?? null) !== 3) {
            throw new DomainException('The saved follow-up source is unavailable.',503);
        }
        $payload['outbound_followup'] = agent_email_followthrough_binding_v1($payload['outbound_followup']);
    }
    if (array_key_exists('followthrough',$payload)) {
        require_once __DIR__.'/agent-email-followthrough-v1.php';
        $saved = $payload['followthrough'];
        if (!is_array($saved) || ($saved['version'] ?? null) !== 1 || !is_int($saved['user_message_id'] ?? null)
            || $saved['user_message_id'] < 1 || count($saved)!==7 || empty($saved['enabled'])) {
            throw new DomainException('The saved response deadline is unavailable.',503);
        }
        $settings = agent_email_followthrough_settings_v1(array_diff_key($saved,array_flip(['version','user_message_id'])),false);
        $payload['followthrough'] = $settings + ['version'=>1,'user_message_id'=>$saved['user_message_id']];
    }
}

function agent_email_composer_public_v1(array $draft, array $payload, array $public): array
{
    // Unlike the short-lived review token, this fingerprint changes only when
    // the persisted approved snapshot changes. It carries no plaintext content.
    $public['edit_revision'] = agent_connection_email_chat_review_hash_v1($draft,false);
    $privateAvailable = empty($public['connection_changed']) || empty($public['is_reply']);
    $public['attachments'] = $privateAvailable ? agent_email_attachments_public_v1($payload['attachments'] ?? []) : [];
    $public['saved_at'] = isset($payload['composer']) ? gmdate(DATE_ATOM,$payload['composer']['saved_at']) : null;
    $public['persistent'] = isset($payload['composer']);
    $public['can_edit'] = empty($public['connection_changed']) && isset($public['review_token'])
        && in_array($public['status'],['prepared','write_failed'],true) && !isset($payload['auto_revision']);
    $public['can_send'] = $public['can_edit'] && $public['status'] === 'prepared'
        && agent_email_composer_complete_v1($draft,$payload);
    $public['is_followup'] = isset($payload['outbound_followup']);
    $public['followthrough'] = isset($payload['followthrough'])
        ? array_diff_key($payload['followthrough'],array_flip(['version','user_message_id']))
        : ['enabled'=>false,'deadline_at'=>null,'time_zone'=>'UTC','prepare_if_overdue'=>false,'priority'=>'normal'];
    $public['followthrough_record'] = null;
    if (isset($payload['outbound_followup']) && in_array($public['status'],['prepared','write_failed'],true)) {
        try {
            require_once __DIR__.'/agent-email-followthrough-v1.php';
            agent_email_followthrough_binding_current_v1(db(),(int)$draft['owner_user_id'],(int)$draft['agent_id'],
                (int)$draft['conversation_id'],$payload['outbound_followup']);
        } catch (DomainException|AgentConnectionExceptionV1 $error) {
            $public['can_send'] = false;
            $public['followthrough_unavailable'] = true;
        }
    }
    if ($draft['status'] === 'sent' && !empty($draft['watch_registered'])) {
        $public['followthrough_record'] = agent_email_composer_followthrough_record_v1($draft);
    }
    $readable = agent_email_review_queue_source_allowed_v1($draft,$payload);
    if ((isset($payload['reply']) || isset($payload['outbound_followup'])) && !isset($payload['auto_revision']) && !$readable) {
        // The read grant may have changed after the base projection's check.
        // Do not retain that earlier envelope merely because the source button
        // is now disabled. A revoked read also invalidates any send approval.
        $public = array_replace($public,['from'=>'','to'=>'','subject'=>'','body'=>'','message_id'=>null,
            'attachments'=>[],'connection_changed'=>true,'can_edit'=>false,'can_send'=>false]);
        unset($public['review_token'],$public['watch_review_token'],$public['can_review_latest']);
    }
    $public['can_open_source'] = $readable;
    $public['source'] = agent_email_review_queue_source_public_v1($draft,$payload,$readable);
    return $public;
}

/** Optional saved state cannot turn a confirmed Gmail send into an unconfirmed send. */
function agent_email_composer_followthrough_record_v1(array $draft): ?array
{
    require_once __DIR__.'/agent-email-followthrough-v1.php';
    $pdo = db();
    if (!$pdo || !agent_email_followthrough_ready_v1($pdo)) return null;
    try {
        $q=$pdo->prepare('SELECT * FROM agent_connection_email_followups_v1 WHERE owner_user_id=? AND operation_key=?
            AND agent_id=? AND conversation_id=? AND connection_id=? AND authorization_key=? AND draft_id=?');
        $q->execute([$draft['owner_user_id'],agent_connection_email_chat_operation_key_v1($draft['id']),$draft['agent_id'],
            $draft['conversation_id'],$draft['connection_id'],$draft['authorization_key'],$draft['id']]);
        $watch=$q->fetch(PDO::FETCH_ASSOC);
        $row=$watch ? agent_email_followthrough_for_watch_v1($pdo,$watch) : null;
        return $row ? agent_email_followthrough_public_v1($pdo,$row) : null;
    } catch (DomainException|AgentConnectionExceptionV1|PDOException $error) { return null; }
}

/** The connection is checked before locking Chat, matching the existing send ledger's lock order. */
function agent_email_composer_connection_v1(PDO $pdo, array $user): array
{
    $summary = agent_connections_summary_v1((int)$user['id']);
    if (empty($summary['schema_ready']) || !table_exists('agent_connection_email_drafts_v1')) {
        throw new DomainException('Run Database Upgrade to save Gmail drafts.',503);
    }
    if (empty($summary['provider_ready']) || empty($summary['connection']['id'])) {
        throw new DomainException('Connect Gmail in Connections to compose an email.',409);
    }
    return agent_connections_require_grant_v1($pdo,(int)$user['id'],(int)$summary['connection']['id'],'send');
}

/** Idempotent composer creation. The generic Chat marker carries no recipient, body, or attachment bytes. */
function agent_email_composer_create_v1(PDO $pdo, array $user, array $principal, int $conversation, array $input,
    string $key, ?int $userMessage=null, string $instruction='', ?array $nativeReply=null): array
{
    $user = agent_connection_email_chat_authority_v1($pdo,$user,$principal,$conversation);
    $request = $input['request_id'] ?? null;
    if (!is_string($request) || !preg_match('/\A[a-f0-9]{32}\z/D',$request)) throw new DomainException('Choose a new email request.',422);
    $to = agent_email_composer_recipient_v1($input['to'] ?? '');
    $subject = agent_email_composer_text_v1($input['subject'] ?? '',200);
    $body = agent_email_composer_text_v1($input['body'] ?? '',6000,true);
    $instruction = agent_email_composer_text_v1($instruction,600,true);
    $owner = (int)$user['id'];
    $agent = (int)($principal['agent_id'] ?? 0);
    $id = substr(hash('sha256',json_encode(['vp3-email-composer-v1',$owner,$agent,$conversation,$request],JSON_THROW_ON_ERROR)),0,32);
    $draft = agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$user,$principal,$conversation,$userMessage,$owner,$agent,$id,$to,$subject,$body,$instruction,$nativeReply): array {
        agent_connection_email_chat_authority_v1($pdo,$user,$principal,$conversation);
        $connection = agent_email_composer_connection_v1($pdo,$user);
        if ($nativeReply!==null) {
            if ($instruction!=='' || (int)$connection['id']!==$nativeReply['connection_id'] || $connection['account_email']!==$nativeReply['account']) throw new DomainException('The reply connection changed.',409);
            $connection=agent_connections_require_grant_v1($pdo,$owner,$nativeReply['connection_id'],'reply',$nativeReply['grant']);
            $nativeReply['reply']=agent_connection_gmail_reply_binding_v1($nativeReply['reply']);
        }
        if (!vp3_agent_chat_conversation_v380($pdo,$conversation,$owner,$agent > 0 ? ['id'=>$agent] : null,$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')) {
            throw new DomainException('Open the original private Chat to compose an email.',403);
        }
        $existing = $pdo->prepare('SELECT * FROM agent_connection_email_drafts_v1 WHERE id=? AND owner_user_id=? AND agent_id=? AND conversation_id=?');
        $existing->execute([$id,$owner,$agent,$conversation]);
        if ($saved = $existing->fetch(PDO::FETCH_ASSOC)) return $saved;
        $now = time();
        if ($userMessage === null) {
            $context = json_encode(['source'=>'agent_email_composer','skip_brain_archive'=>true],JSON_THROW_ON_ERROR);
            $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json,created_at) VALUES (?,?,'user',?,?,?)")
                ->execute([$conversation,$owner,'Compose a new email.',$context,gmdate('Y-m-d H:i:s',$now)]);
            $userMessage = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE chat_conversations SET updated_at=? WHERE id=? AND user_id=?')
                ->execute([gmdate('Y-m-d H:i:s',$now),$conversation,$owner]);
        }
        if ($userMessage < 1) throw new DomainException('Use a saved Agent Chat to prepare an email.',422);
        $payload = ['subject'=>$subject,'body'=>$body,'attachments'=>[], 'composer'=>['version'=>1,'saved_at'=>$now]];
        if ($nativeReply!==null) $payload += ['reply'=>$nativeReply['reply'],'soul_version'=>$nativeReply['soul_version'],'soul_revision'=>$nativeReply['soul_revision']];
        $status = 'prepared';
        if ($instruction !== '') {
            $payload['write'] = agent_connection_email_chat_write_request_v1('compose_new',$instruction);
            $status = 'write_queued';
        }
        $encoded = ai_encrypt_secret(json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $pdo->prepare('INSERT INTO agent_connection_email_drafts_v1
            (id,owner_user_id,agent_id,conversation_id,user_message_id,connection_id,authorization_key,account_email,recipient,message_enc,status,expires_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,0)')->execute([$id,$owner,$agent,$conversation,$userMessage,
                (int)$connection['id'],$connection['authorization_key'],$connection['account_email'],$to,$encoded,$status]);
        $saved=agent_connection_email_chat_draft_v1($pdo,$owner,$agent,$conversation,$id);
        if ($nativeReply!==null) {
            require_once __DIR__.'/agent-email-preferences-v1.php';
            agent_email_preferences_capture_generated_v1($pdo,$saved,$nativeReply['generation_key']);
        }
        return $saved;
    });
    return agent_connection_email_chat_public_draft_v1($draft,$key,time());
}

function agent_email_composer_list_v1(PDO $pdo, array $user, array $principal, int $conversation, string $key, array $options=[]): array
{
    return agent_email_review_queue_list_v1($pdo,$user,$principal,$conversation,$key,$options);
}

/** Save/requeue one exact revision; source-derived reply addresses and subjects cannot be redirected. */
function agent_email_composer_change_v1(PDO $pdo, array $user, array $principal, int $conversation, array $input, string $key): array
{
    $user = agent_connection_email_chat_authority_v1($pdo,$user,$principal,$conversation);
    $id = $input['draft_id'] ?? null;
    if (!is_string($id)) throw new DomainException('Choose a saved email draft.',422);
    $owner = (int)$user['id'];
    $agent = (int)($principal['agent_id'] ?? 0);
    $draft = agent_connection_email_chat_draft_v1($pdo,$owner,$agent,$conversation,$id);
    $payload = agent_connection_email_chat_payload_v1($draft);
    $status = agent_connection_email_chat_draft_status_v1($draft,$payload,time());
    if (isset($payload['auto_revision']) || !in_array($status,['prepared','write_failed'],true)) {
        throw new DomainException('This email draft can no longer be edited.',409);
    }
    if (isset($payload['reply']) && agent_connection_email_chat_answered_v1($pdo,$user,(int)$draft['connection_id'],$draft['authorization_key'],$payload['reply']['message_id'])) {
        return agent_connection_email_chat_public_draft_v1(array_replace($draft,['status'=>'answered']),$key,time());
    }
    initiative_workbench_confirm_v1($input + ['confirmed'=>true],$key,agent_connection_email_chat_review_hash_v1($draft,false),time());
    $next = 'prepared';
    $recipient = $draft['recipient'];
    $followthrough = null;
    $followthroughChanged = false;
    if ($input['action'] === 'compose_save') {
        $recipient = agent_email_composer_recipient_v1($input['to'] ?? null);
        $subject = agent_email_composer_text_v1($input['subject'] ?? null,200);
        $body = agent_email_composer_text_v1($input['body'] ?? null,6000,true);
        if ((isset($payload['reply']) || isset($payload['outbound_followup'])) && ($recipient !== $draft['recipient'] || $subject !== $payload['subject'])) {
            throw new DomainException('Keep the original Gmail reply recipient and subject.',422);
        }
        if (!is_array($input['attachment_ids'] ?? null) || !array_is_list($input['attachment_ids'])) throw new DomainException('Choose valid email attachments.',422);
        $payload['subject'] = $subject;
        $payload['body'] = $body;
        unset($payload['write']);
        if (array_key_exists('followthrough',$input)) {
            require_once __DIR__.'/agent-email-followthrough-v1.php';
            $followthrough=agent_email_followthrough_settings_v1($input['followthrough'],false);
            if ($followthrough['enabled'] && isset($payload['outbound_followup'])) {
                throw new DomainException('Send this reviewed follow-up, then change its response deadline in Sent.',422);
            }
            $previous=isset($payload['followthrough']) ? array_diff_key($payload['followthrough'],array_flip(['version','user_message_id'])) : null;
            $followthroughChanged=$followthrough!==$previous;
            if ($followthrough['enabled'] && $followthroughChanged) agent_email_followthrough_settings_v1($followthrough,true);
            if ($followthrough['enabled'] && !agent_email_followthrough_ready_v1($pdo)) {
                throw new DomainException('Run Database Upgrade before setting response deadlines.',503);
            }
        }
    } elseif ($input['action'] === 'compose_generate') {
        $instruction = agent_email_composer_text_v1($input['instruction'] ?? null,600,true);
        if ($instruction === '') throw new DomainException('Tell the Email Specialist what to write.',422);
        $kind = isset($payload['outbound_followup']) ? 'revise'
            : (isset($payload['reply']) ? (trim($payload['body']) === '' ? 'compose' : 'revise') : 'compose_new');
        $payload['write'] = agent_connection_email_chat_write_request_v1($kind,$instruction);
        $next = 'write_queued';
    } else throw new DomainException('Choose a valid email composer action.',422);
    // Keep the proposal's original source and creation time when the owner edits it.
    $payload['composer'] = array_replace($payload['composer'] ?? ['version'=>1],['saved_at'=>time()]);
    $row = agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$user,$principal,$conversation,$draft,$payload,$recipient,$next,$input,$owner,$agent,$followthrough,$followthroughChanged): array {
        agent_connection_email_chat_authority_v1($pdo,$user,$principal,$conversation);
        $connection = agent_connections_require_grant_v1($pdo,$owner,(int)$draft['connection_id'],
            isset($payload['reply']) || isset($payload['outbound_followup']) ? 'reply' : 'send',$draft['authorization_key']);
        if ($connection['account_email'] !== $draft['account_email'] || !agent_connection_gmail_ready_v1()) {
            throw new DomainException('Gmail access changed. Reopen the saved draft.',409);
        }
        if ($input['action'] === 'compose_save') $payload['attachments'] = agent_email_attachments_freeze_v1($pdo,$owner,$input['attachment_ids']);
        if ($followthrough !== null) {
            if (!$followthrough['enabled']) unset($payload['followthrough']);
            else {
                agent_connections_require_grant_v1($pdo,$owner,(int)$draft['connection_id'],'followups',$draft['authorization_key']);
                if ($followthroughChanged) {
                    // This marker records the actual saved owner control action;
                    // no background worker may manufacture one for a draft.
                    $at=gmdate('Y-m-d H:i:s');
                    $notice='Track this email for a reply by '.$followthrough['deadline_at'].' ('.$followthrough['time_zone'].').'
                        .($followthrough['prepare_if_overdue'] ? ' Prepare one follow-up draft for my review if a Gmail check verifies no reply.' : ' Notify me after verifying the overdue thread.');
                    $context=json_encode(['source'=>'agent_email_followthrough_deadline','skip_brain_archive'=>true],JSON_THROW_ON_ERROR);
                    $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json,created_at) VALUES (?,?,'user',?,?,?)")
                        ->execute([$conversation,$owner,$notice,$context,$at]);
                    $payload['followthrough']=$followthrough+['version'=>1,'user_message_id'=>(int)$pdo->lastInsertId()];
                    $pdo->prepare('UPDATE chat_conversations SET updated_at=? WHERE id=? AND user_id=?')->execute([$at,$conversation,$owner]);
                }
            }
        }
        $encoded = ai_encrypt_secret(json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $watch=isset($payload['outbound_followup'])?(int)!empty($draft['watch_replies']):0;
        $replace = $pdo->prepare('UPDATE agent_connection_email_drafts_v1 SET message_enc=?,recipient=?,status=?,expires_at=0,watch_replies=?
            WHERE id=? AND owner_user_id=? AND agent_id=? AND conversation_id=? AND status=? AND message_enc=? AND recipient=? AND expires_at=?');
        $replace->execute([$encoded,$recipient,$next,$watch,$draft['id'],$owner,$agent,$conversation,$draft['status'],$draft['message_enc'],$draft['recipient'],$draft['expires_at']]);
        if ($replace->rowCount() !== 1) throw new DomainException('This email draft changed. Open the latest saved version.',409);
        $saved=agent_connection_email_chat_draft_v1($pdo,$owner,$agent,$conversation,$draft['id']);
        if ($input['action']==='compose_save') {
            require_once __DIR__.'/agent-email-preferences-v1.php';
            agent_email_preferences_capture_edit_v1($pdo,$draft,$saved);
        }
        return $saved;
    });
    return agent_connection_email_chat_public_draft_v1($row,$key,time());
}

/** New-mail inference sees only explicitly saved text and the owner's direction, never Chat context or file bytes. */
function agent_email_composer_write_v1(PDO $pdo, array $user, int $agent, int $conversation, string $attempt,
    array $payload, Closure $current, ?callable $model, ?array &$soulUsed=null): array
{
    if ($model === null) {
        $route = vp3_agent_runtime_plan_v420($pdo,$user,$agent,'chat');
        if (($route['route'] ?? '') !== 'vp3_cloud' || empty($route['cloud']['ready'])) return [];
        $model = static fn(string $data,array $service,array $control): array => ai_v100_with_service_context($service,
            static fn() => ai_generate_chat_response($data,[],[],$user,'main_ai',$control));
    }
    require_once __DIR__.'/agent-brain-v82.php';
    $soul = agent_brain_soul_profile_v1($user,'email');
    $soulUsed = ['soul_version'=>$soul['version'],'soul_revision'=>$soul['revision']];
    $data = json_encode(['owner_instruction'=>$payload['write']['instruction'],'email_soul'=>$soul['content'],
        'current_draft'=>['subject'=>$payload['subject'],'body'=>$payload['body']]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $dispatched = false;
    $before = static function () use ($current,&$dispatched): bool {
        if ($dispatched || !$current()) return false;
        $dispatched = true;
        return true;
    };
    $protocol = 'vp3.email-draft.v1';
    $result = $model($data,['service'=>'conversation','stage'=>$protocol,'agent_id'=>$agent,'conversation_id'=>$conversation,'job_key'=>'gmail-compose:'.$attempt],
        ['surface'=>'tool_json','protocol'=>$protocol,'before_gmail_reply_dispatch'=>$before]);
    if (empty($result['ok'])) return [];
    $value = vp3_tool_json_object_v1((string)($result['answer'] ?? ''),['subject','body'],45000);
    return ['subject'=>agent_connection_email_chat_text_v1($value['subject'] ?? null,200),
        'body'=>agent_connection_email_chat_text_v1($value['body'] ?? null,6000,true)];
}
