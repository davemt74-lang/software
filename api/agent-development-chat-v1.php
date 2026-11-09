<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-development-v1.php';
require_once dirname(__DIR__).'/includes/agent-development-chat-draft-v1.php';
require_once dirname(__DIR__).'/includes/agent-chat-read-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function vp3_dev_chat_status_v1(array $row): array
{
    return ['id'=>(string)$row['id'],'status'=>(string)$row['status'],'mode'=>(string)$row['mode'],
        'preview_ready'=>in_array($row['status'],['staged','approved'],true) && vp3_dev_stage_current_v1($row)];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new DomainException('Use a private Chat request.',405);
    $user = current_user();
    if (!$user || !has_permission('chat.access',$user) || !vp3_dev_is_admin_v1($user)
        || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1() !== null)) {
        throw new DomainException('Open your private platform-admin Chat.',403);
    }

    $raw = file_get_contents('php://input',false,null,0,16385);
    if (!is_string($raw) || strlen($raw) > 16384) throw new DomainException('Development request is too large.',413);
    $body = json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    $action = $body['action'] ?? null;
    $fields = ['action','owner_key','csrf_token','agent_id','conversation_id'];
    if ($action === 'create') $fields = [...$fields,'request','expected_epoch'];
    if ($action === 'draft') $fields[] = 'assistant_message_id';
    if (!is_array($body) || array_is_list($body) || !in_array($action,['status','create','draft'],true)
        || array_diff(array_keys($body),$fields)) {
        throw new DomainException('Choose a valid development request.',422);
    }
    if (($body['owner_key'] ?? null) !== (string)$user['id']) throw new DomainException('The Chat account changed. Refresh this page.',409);
    if (!is_string($body['csrf_token'] ?? null) || !hash_equals(csrf_token(),$body['csrf_token'])) {
        throw new DomainException('Refresh Chat before starting development work.',419);
    }
    $agentId = $body['agent_id'] ?? null;
    $conversationId = $body['conversation_id'] ?? null;
    if (!is_int($agentId) || !is_int($conversationId)) throw new DomainException('Choose the current Agent and Chat.',422);
    vp3_chat_read_id_v1($agentId,true);
    vp3_chat_read_id_v1($conversationId,$action === 'status');
    if ($action === 'create' && (!is_string($body['request'] ?? null) || !is_int($body['expected_epoch'] ?? null)
        || $body['expected_epoch'] < 0)) {
        throw new DomainException('Enter a request and choose the current automation level.',422);
    }
    if ($action === 'draft') {
        $assistantMessageId = $body['assistant_message_id'] ?? null;
        if (!is_int($assistantMessageId)) throw new DomainException('Choose a saved Agent reply.',422);
        vp3_chat_read_id_v1($assistantMessageId);
    }

    $pdo = db();
    if (!$pdo || !vp3_dev_schema_ready_v1($pdo)) throw new DomainException('Run Database Upgrade before using Agent Development.',503);
    if ($conversationId > 0) {
        $user = vp3_chat_read_scope_v1($pdo,$user,$agentId,$conversationId);
    } else {
        try {
            vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);
        } catch (RuntimeException $error) {
            if ($error instanceof PDOException) throw $error;
            throw new DomainException('The selected Agent is no longer available.',404);
        }
    }
    if (!vp3_dev_is_admin_v1($user)) throw new DomainException('Platform-admin access changed.',403);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $owner = (int)$user['id'];
    $data = ['ok'=>true,'owner_key'=>(string)$owner,'agent_id'=>$agentId,'conversation_id'=>$conversationId];
    $control = null;
    if ($action === 'create') {
        $revision = vp3_dev_create_v1($pdo,$user,$body['request'],$body['expected_epoch'],$conversationId);
        $data['revision'] = vp3_dev_chat_status_v1($revision);
    } elseif ($action === 'draft') {
        $control = vp3_dev_control_v1($pdo,$owner);
        if ($control['mode'] === 'off') throw new DomainException('Enable an automation level in Agent Development.',409);
        $data['draft'] = vp3_dev_chat_draft_v1($pdo,$user,$agentId,$conversationId,$assistantMessageId);
    } elseif ($conversationId > 0) {
        $data['revisions'] = array_map('vp3_dev_chat_status_v1',vp3_dev_list_v1($pdo,$owner,$conversationId));
    } else {
        $data['revisions'] = [];
    }
    $control ??= vp3_dev_control_v1($pdo,$owner);
    $data['mode'] = $control['mode'];
    $data['epoch'] = (int)$control['epoch'];
    echo json_encode($data,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    $status = (int)$error->getCode();
    if ($error instanceof JsonException || $error instanceof InvalidArgumentException) $status = 422;
    if ($error instanceof PDOException || !in_array($status,[400,403,404,405,409,413,419,422,429,502,503],true)) $status = 503;
    http_response_code($status);
    $message = $error instanceof DomainException ? $error->getMessage() : match ($status) {
        403,404 => 'Open the original private platform-admin Chat.',
        409 => 'The automation level or Chat changed. Review the current state before retrying.',
        422 => 'Choose a valid development request.',
        default => 'The development request could not be verified. Check Admin Revisions before trying again.',
    };
    echo json_encode(['ok'=>false,'error'=>$message],JSON_INVALID_UTF8_SUBSTITUTE);
}
