<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD']??'') !== 'POST') throw new DomainException('Use POST for email review.',405);
    $raw = file_get_contents('php://input', false, null, 0, 50001);
    if (!is_string($raw) || strlen($raw)>50000) throw new DomainException('Email review request is too large.',413);
    $input = json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input)
        || array_diff(array_keys($input),['action','owner_key','agent_id','conversation_id','draft_id','csrf_token','confirmed','review_token','watch_replies','subject','body','sender','enabled','watchlist_key','instruction','message_id','item_ref'])) {
        throw new DomainException('Choose an email review action.',422);
    }
    $user = current_user();
    if (!$user || !has_permission('chat.access',$user)) throw new DomainException('Sign in to review email in Agent Chat.',403);
    if (($input['owner_key']??null) !== (string)$user['id']) throw new DomainException('The Chat account changed. Refresh the page.',409);
    if (!is_string($input['csrf_token']??null) || !hash_equals(csrf_token(),$input['csrf_token'])) {
        throw new DomainException('Refresh Chat before reviewing email.',419);
    }
    $agent = $input['agent_id']??null;
    $conversation = $input['conversation_id']??null;
    if (!is_int($agent) || $agent < 0 || !is_int($conversation) || $conversation < 1) {
        throw new DomainException('Open the original Agent Chat to review this email.',422);
    }
    $pdo = db();
    if (!$pdo) throw new DomainException('Email review storage is unavailable.',503);
    if (in_array($input['action']??null,['sender_watchlist','sender_watch'],true)) {
        $change = $input['action'] === 'sender_watch';
        $fields = ['action','owner_key','agent_id','conversation_id','csrf_token'];
        if ($change) $fields = [...$fields,'sender','enabled','watchlist_key'];
        if (array_diff(array_keys($input),$fields) || ($change && (!is_string($input['sender']??null)
            || !is_bool($input['enabled']??null) || !is_string($input['watchlist_key']??null)))) {
            throw new DomainException('Choose a valid Gmail watch action.',422);
        }
        require_once dirname(__DIR__).'/includes/agent-connection-email-followup-v1.php';
        session_write_close();
        $watchlist = agent_connection_email_sender_watchlist_v1($pdo,(int)$user['id'],$agent,$conversation,
            $input['sender']??null,$input['enabled']??null,$input['watchlist_key']??null);
        echo json_encode(['ok'=>true,'watchlist'=>$watchlist],JSON_THROW_ON_ERROR);
        exit;
    }
    if (array_intersect(array_keys($input),['sender','enabled','watchlist_key'])) {
        throw new DomainException('Choose an email review action.',422);
    }
    if (in_array($input['action']??null,['open_followup','open_search'],true)) {
        $followup = $input['action'] === 'open_followup';
        $fields = ['action','owner_key','agent_id','conversation_id','csrf_token',$followup?'message_id':'item_ref'];
        if (array_diff(array_keys($input),$fields) || ($followup && (!is_int($input['message_id']??null) || $input['message_id'] < 1))
            || (!$followup && !is_string($input['item_ref']??null))) {
            throw new DomainException('Choose an exact Gmail message.',422);
        }
        $activeAgent = vp3_agent_chat_resolve_agent_v380($pdo,$user,$agent);
        $principal = vp3_agent_chat_principal_v380($user,$activeAgent);
        session_write_close();
        $live = agent_connection_email_chat_authority_v1($pdo,$user,$principal,$conversation);
        if ($followup) {
            $owner = (int)$live['id'];
            $item = agent_connection_email_followup_message_v1($pdo,$owner,$agent,$conversation,$input['message_id']);
            $message = agent_connection_email_followup_open_v1($pdo,$owner,$agent,$conversation,$input['message_id']);
            $connection = agent_connection_email_chat_record_v1($owner,$item['connection_id'],'read',$item['authorization_key']);
            $message['can_reply'] = !empty($connection['permissions']['send']);
        } else $message = agent_connection_email_chat_open_search_v1($pdo,$live,$principal,$conversation,$input['item_ref']);
        echo json_encode(['ok'=>true,'message'=>$message],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    if (array_intersect(array_keys($input),['message_id','item_ref'])) {
        throw new DomainException('Choose an email review action.',422);
    }
    if (($input['action']??null) === 'specialist_status') {
        if (array_diff(array_keys($input),['action','owner_key','agent_id','conversation_id','csrf_token'])) {
            throw new DomainException('Choose a valid Gmail status request.',422);
        }
        require_once dirname(__DIR__).'/includes/agent-connection-email-followup-v1.php';
        session_write_close();
        $activeAgent = vp3_agent_chat_resolve_agent_v380($pdo,$user,$agent);
        $principal = vp3_agent_chat_principal_v380($user,$activeAgent);
        $specialist = agent_connection_email_chat_writing_status_v1($pdo,$user,$principal,$conversation)
            ?? agent_connection_email_followup_specialist_v1($pdo,(int)$user['id'],$agent,$conversation);
        echo json_encode(['ok'=>true,'specialist'=>$specialist], JSON_THROW_ON_ERROR);
        exit;
    }
    $key = $_SESSION['agent_connection_email_review_v1'] ??= bin2hex(random_bytes(32));
    $activeAgent = vp3_agent_chat_resolve_agent_v380($pdo,$user,$agent);
    $principal = vp3_agent_chat_principal_v380($user,$activeAgent);
    session_write_close();
    if (($input['action']??null) === 'advance_write') ignore_user_abort(true);
    $draft = agent_connection_email_chat_action_v1($pdo,$user,$principal,$conversation,$input,$key);
    echo json_encode(['ok'=>true,'draft'=>$draft], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    $status = (int)$error->getCode();
    if ($error instanceof JsonException || $error instanceof InvalidArgumentException) $status = 422;
    if ($error instanceof PDOException || !in_array($status,[400,401,403,404,405,409,413,419,422,429,502,503],true)) $status = 503;
    http_response_code($status);
    $message = ($error instanceof DomainException || $error instanceof AgentConnectionExceptionV1) ? $error->getMessage() : match ($status) {
        403,404 => 'Open the original private Agent Chat to review this email.',
        409 => 'The email review or send status changed. Reopen the review to check it.',
        422 => 'Choose a valid email review action.',
        default => 'The email outcome could not be verified. Open the review again before sending another email.',
    };
    echo json_encode(['ok'=>false,'error'=>$message], JSON_INVALID_UTF8_SUBSTITUTE);
}
