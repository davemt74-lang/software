<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-initial-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new RuntimeException('POST required.', 405);
    }

    $user = current_user();
    if (!$user) throw new RuntimeException('Sign-in required.', 401);
    if ((int)($user['id'] ?? 0) < 1 || !has_permission('account.access', $user)
        || !has_permission('chat.access', $user)
        || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1() !== null)) {
        throw new RuntimeException('Owner access required.', 403);
    }

    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
    if ($contentType !== 'application/json') throw new RuntimeException('JSON required.', 415);
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if ($raw === false) throw new RuntimeException('Request could not be read.', 422);
    if (strlen($raw) > 4096) throw new RuntimeException('Request is too large.', 413);
    $body = json_decode($raw, true, 8);
    if (!is_array($body) || array_is_list($body)) throw new RuntimeException('Request object required.', 422);

    $action = $body['action'] ?? null;
    $mailboxLink=$action==='record_mailbox_link_open';
    $linkClick = $action === 'record_link_open'||$mailboxLink;
    if (!$linkClick && strlen($raw) > 2048) throw new RuntimeException('Request is too large.', 413);
    $triage = in_array($action,['triage_group','triage_correct','triage_open'],true);
    $digestAction = in_array($action,['routine_open','prioritize_item'],true);
    $chatOpen = $action === 'open_item' && array_key_exists('chat_message_id',$body);
    $exactPost = $digestAction || ($linkClick&&!$mailboxLink) || $chatOpen;
    $fields = ['action', 'owner_key', 'csrf_token'];
    if (in_array($action,['status','start','advance'],true)) $fields[] = 'include_report';
    if ($action === 'start') $fields = [...$fields, 'days', 'start_token'];
    if ($action === 'advance') $fields[] = 'run_id';
    if ($action === 'deliver') $fields = [...$fields, 'run_id', 'agent_id', 'conversation_id', 'working_attention_binding'];
    if ($action === 'deliver_updates') $fields = [...$fields, 'agent_id', 'conversation_id', 'working_attention_binding'];
    if ($action === 'analyze_recent') $fields = [...$fields, 'agent_id', 'conversation_id', 'working_attention_binding'];
    if ($action === 'check_overdue') $fields = [...$fields, 'check_ref', 'include_chat_result', 'working_attention_binding', 'agent_id', 'conversation_id'];
    if ($action === 'matter_update') $fields = [...$fields, 'matter_id', 'revision', 'decision', 'working_attention_binding'];
    if ($exactPost) $fields = [...$fields, 'agent_id', 'conversation_id', 'working_attention_binding', 'chat_message_id'];
    if ($linkClick) $fields = [...$fields, 'item_ref', 'url', 'link_token', 'link_label'];
    if ($triage) $fields = [...$fields, 'agent_id', 'conversation_id', 'working_attention_binding', 'generation'];
    if ($action === 'triage_group') $fields = [...$fields, 'group', 'cursor'];
    if ($action === 'triage_correct') $fields = [...$fields, 'triage_id', 'revision', 'attention', 'remember_sender'];
    if (in_array($action, ['status', 'advance'], true)) $fields[] = 'include_chat_result';
    if ($action === 'open_item' || $action === 'triage_open' || $digestAction) $fields[] = 'item_ref';
    if (in_array($action,['find_matches','open_match'],true)) $fields[] = 'legacy_ref';
    if ($action === 'open_match') $fields = [...$fields,'message_id','match_token'];
    if ((!$triage && !$exactPost && !in_array($action, ['status', 'start', 'advance', 'open_item', 'deliver', 'deliver_updates', 'analyze_recent', 'check_overdue', 'find_matches', 'open_match', 'matter_update','record_mailbox_link_open'], true))
        || array_diff(array_keys($body), $fields)
        || (array_key_exists('include_report', $body) && !is_bool($body['include_report']))
        || (array_key_exists('include_chat_result', $body) && !is_bool($body['include_chat_result']))
        || (!empty($body['include_report']) && !empty($body['include_chat_result']))) {
        throw new RuntimeException('Invalid review request.', 422);
    }
    if (!is_string($body['owner_key'] ?? null)) throw new RuntimeException('Owner key required.', 422);
    if ($body['owner_key'] !== (string)$user['id']) throw new RuntimeException('Owner changed.', 409);
    $token = $body['csrf_token'] ?? null;
    if (!is_string($token) || $token === '' || !hash_equals(csrf_token(), $token)) {
        throw new RuntimeException('Session expired.', 419);
    }

    $days = $body['days'] ?? null;
    $startToken = $body['start_token'] ?? null;
    if ($action === 'start' && (!in_array($days, [1, 3, 7, 14, 30], true)
        || !is_string($startToken) || !preg_match('/\A[a-f0-9]{64}\z/', $startToken))) {
        throw new RuntimeException('Invalid review window or start token.', 422);
    }
    $runId = $body['run_id'] ?? null;
    if (in_array($action,['advance','deliver'],true) && (!is_string($runId) || !preg_match('/\A[a-f0-9]{32}\z/D', $runId))) {
        throw new RuntimeException('Invalid review ID.', 422);
    }
    $checkRef = $body['check_ref'] ?? null;
    if ($action === 'check_overdue' && (($body['include_chat_result'] ?? false) !== true
        || !is_string($checkRef) || !preg_match('/\A[a-f0-9]{64}\z/D',$checkRef))) {
        throw new RuntimeException('Invalid inbox check.',422);
    }
    $checkChat = $action === 'check_overdue' && (array_key_exists('agent_id',$body) || array_key_exists('conversation_id',$body));
    if ($checkChat || $triage || $exactPost || in_array($action,['deliver','deliver_updates','analyze_recent'],true)) {
        $agentId = $body['agent_id'] ?? null;
        $conversationId = $body['conversation_id'] ?? null;
        if (!is_int($agentId) || $agentId < 0 || $agentId > 9007199254740991
            || !is_int($conversationId) || $conversationId < ($triage ? 0 : 1) || $conversationId > 9007199254740991) {
            throw new RuntimeException('Choose an exact private Agent Chat.',422);
        }
    }
    if ($triage || $exactPost || in_array($action,['deliver','deliver_updates','analyze_recent','check_overdue','matter_update'],true)) {
        $binding = $body['working_attention_binding'] ?? null;
        if (!is_string($binding) || !preg_match('/\A[a-f0-9]{64}\z/D',$binding)) {
            throw new RuntimeException('Choose an exact private Agent Chat.',422);
        }
        if (!function_exists('vp3_catch_up_page_binding_v1')) require_once dirname(__DIR__).'/includes/agent-catch-up-v1.php';
        if (!hash_equals(vp3_catch_up_page_binding_v1($user),$binding)) throw new RuntimeException('Chat session changed.',403);
    }
    $itemRef = $body['item_ref'] ?? null;
    if (($digestAction || $action==='triage_open' || ($action==='open_item'&&!$chatOpen)
        || (($linkClick || $chatOpen) && $itemRef!==null))
        && (!is_string($itemRef) || !preg_match('/\A[a-f0-9]{64}\z/D', $itemRef))) {
        throw new RuntimeException('Invalid review item.', 422);
    }
    $digestMessageId = $body['chat_message_id'] ?? null;
    if($mailboxLink){$agentId=0;$conversationId=0;$digestMessageId=0;}
    if ($exactPost && (!is_int($digestMessageId) || $digestMessageId < 1 || $digestMessageId > 9007199254740991)) {
        throw new RuntimeException('Choose an exact Gmail post.',422);
    }
    $legacyRef = $body['legacy_ref'] ?? null;
    $messageId = $body['message_id'] ?? null;
    $matchToken = $body['match_token'] ?? null;
    if (in_array($action,['find_matches','open_match'],true) && (!is_string($legacyRef) || !preg_match('/\A[a-f0-9]{64}\z/D',$legacyRef))) {
        throw new RuntimeException('Invalid older review item.',422);
    }
    if ($action === 'open_match' && (!is_string($messageId) || !preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D',$messageId)
        || !is_string($matchToken) || !preg_match('/\A[a-f0-9]{64}\z/D',$matchToken))) {
        throw new RuntimeException('Invalid Gmail match.',422);
    }

    $pdo = db();
    if (!$pdo) throw new RuntimeException('Review storage unavailable.', 503);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if ($digestAction) {
        require_once dirname(__DIR__).'/includes/agent-connection-gmail-digest-engagement-v1.php';
    }

    if ($linkClick) {
        $url = $body['url'] ?? null;
        $proof = $body['link_token'] ?? null;
        $label = $body['link_label'] ?? '';
        if($mailboxLink&&($itemRef===null||$proof===null))throw new RuntimeException('Choose a signed mailbox link.',422);
        if (!is_string($url) || $url==='' || strlen($url)>1800
            || !is_string($label) || mb_strlen($label)>160
            || ($proof!==null && (!is_string($proof) || !preg_match('/\A[a-f0-9]{64}\z/D',$proof) || $itemRef===null))
            || ($proof===null && $label!=='')) {
            throw new RuntimeException('Choose an exact Gmail link.',422);
        }
        require_once dirname(__DIR__).'/includes/agent-connection-gmail-link-engagement-v1.php';
        $saved=vp3_gmail_curiosity_record_link_v1($pdo,$user,$agentId,$conversationId,$digestMessageId,$itemRef,$url,$proof,$label);
        echo json_encode(['ok'=>true,'recorded'=>$saved],JSON_THROW_ON_ERROR);
        return;
    }

    if ($action === 'routine_open' || $chatOpen) {
        require_once dirname(__DIR__).'/includes/agent-connection-gmail-link-engagement-v1.php';
        $source=vp3_gmail_chat_link_source_v1($pdo,$user,$agentId,$conversationId,$digestMessageId,$itemRef);
        $itemRef=$source['item_ref'];
        if(!is_string($itemRef))throw new RuntimeException('The source email is unavailable.',404);
        $item=agent_connection_gmail_inbox_open_item_v1($pdo,$user,$itemRef,includeLinks:true);
        $live=vp3_gmail_chat_link_source_v1($pdo,$user,$agentId,$conversationId,$digestMessageId,$itemRef);
        $scopeRef=agent_connection_gmail_inbox_update_scope_ref_v1($source['scope']);
        if(!hash_equals($scopeRef,agent_connection_gmail_inbox_update_scope_ref_v1($live['scope']))){
            throw new RuntimeException('Gmail access changed.',403);
        }
        $result=['ok'=>true,'item'=>vp3_gmail_chat_link_tokens_v1($item+['item_ref'=>$itemRef],
            $live['scope'],$agentId,$conversationId,$digestMessageId,$itemRef)];
        require_once dirname(__DIR__).'/includes/agent-curiosity-email-activity-v1.php';
        vp3_curiosity_email_open_observe_v1($pdo,$user,$itemRef,$item,$agentId,$conversationId);
        if($action==='routine_open')$result['priority']=agent_connection_gmail_digest_prioritize_v1($pdo,$user,$agentId,$conversationId,$digestMessageId,$itemRef);
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }

    if ($digestAction) {
        $result = ['ok'=>true];
        $result['priority'] = agent_connection_gmail_digest_prioritize_v1($pdo,$user,$agentId,$conversationId,$digestMessageId,$itemRef);
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }

    if ($triage) {
        $generation = $body['generation'] ?? null;
        if (!is_int($generation) || $generation < 1 || $generation > 9007199254740991) {
            throw new RuntimeException('Choose an exact Gmail review.',422);
        }
        require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
        require_once dirname(__DIR__).'/includes/agent-email-triage-v1.php';
        try {
            $agent = vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);
        } catch (PDOException $error) {
            throw $error;
        } catch (RuntimeException $error) {
            throw new RuntimeException('Agent access changed.',403,$error);
        }
        if ($conversationId > 0 && !vp3_agent_chat_conversation_v380($pdo,$conversationId,(int)$user['id'],$agent)) {
            throw new RuntimeException('Conversation access changed.',403);
        }
        $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$user);
        if ($scope['state'] !== 'ready' || empty($scope['provider_ready'])) {
            throw new RuntimeException('Gmail review access changed.',403);
        }
        if ($scope['generation'] !== $generation) throw new RuntimeException('Gmail review changed.',409);
        if (!agent_email_triage_ready_v1($pdo)) throw new RuntimeException('Email triage storage unavailable.',503);
        if ($action === 'triage_group') {
            $group = $body['group'] ?? null;
            $cursor = $body['cursor'] ?? null;
            if (!is_string($group) || !isset(agent_email_triage_groups_v1()[$group])
                || ($cursor !== null && (!is_string($cursor) || !preg_match('/\A[a-f0-9]{64}\z/D',$cursor)))) {
                throw new RuntimeException('Choose a valid email group and page.',422);
            }
            $result = ['group'=>agent_email_triage_group_v1($pdo,$scope,$group,$cursor)];
        } elseif ($action === 'triage_open') {
            if (agent_email_triage_source_v1($pdo,$scope,$itemRef) === null) {
                throw new RuntimeException('This email is no longer in the current review.',404);
            }
            $opened = agent_connection_gmail_inbox_open_item_v1($pdo,$user,$itemRef,includeLinks:true);
            $result = ['item'=>array_intersect_key($opened,array_flip(['subject','from','body','answered_in_gmail','links']))];
        } else {
            $id = $body['triage_id'] ?? null;
            $revision = $body['revision'] ?? null;
            $attention = $body['attention'] ?? null;
            $remember = $body['remember_sender'] ?? false;
            if (!is_string($id) || !preg_match('/\A[a-f0-9]{64}\z/D',$id) || !is_int($revision) || $revision < 1
                || !in_array($attention,agent_email_triage_attention_choices_v1(),true)
                || (array_key_exists('remember_sender',$body) && !is_bool($body['remember_sender']))) {
                throw new RuntimeException('Choose an exact email classification and scope.',422);
            }
            $item = agent_email_triage_correct_v1($pdo,$user,$id,$revision,$attention,$remember);
            if ($item === null) throw new RuntimeException('The email classification changed.',409);
            $result = ['item'=>$item];
        }
        $current = agent_connection_gmail_inbox_bound_scope_v1($pdo,$user);
        if ($current['state'] !== 'ready' || empty($current['provider_ready']) || $current['generation'] !== $generation) {
            throw new RuntimeException('Gmail review access changed.',403);
        }
        if($action==='triage_open'){
            require_once dirname(__DIR__).'/includes/agent-connection-gmail-link-engagement-v1.php';
            $result['item']=vp3_gmail_chat_link_tokens_v1($result['item'],$current,0,0,0,$itemRef);
            require_once dirname(__DIR__).'/includes/agent-curiosity-email-activity-v1.php';
            vp3_curiosity_email_open_observe_v1($pdo,$user,$itemRef,$opened,$agentId,$conversationId);
        }
        echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }

    if ($action === 'matter_update') {
        $matterId = $body['matter_id'] ?? null;
        $revision = $body['revision'] ?? null;
        $decision = $body['decision'] ?? null;
        if (!is_string($matterId) || !preg_match('/\A[a-f0-9]{64}\z/D',$matterId)
            || !is_int($revision) || $revision < 1 || !in_array($decision,['snooze','dismiss','resolve','reopen'],true)) {
            throw new RuntimeException('Choose a current email matter and decision.',422);
        }
        require_once dirname(__DIR__).'/includes/agent-email-matters-v1.php';
        $wakeAt = $decision === 'snooze' ? gmdate('Y-m-d H:i:s',time()+3600) : null;
        $matter = agent_email_matters_update_v1($pdo,$user,$matterId,$revision,$decision,$wakeAt);
        if ($matter === null) throw new RuntimeException('The email matter changed.',409);
        echo json_encode(['ok'=>true,'matter'=>$matter],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }
    if ($action === 'deliver') {
        echo json_encode(['ok'=>true]+agent_connection_gmail_inbox_chat_deliver_v1($pdo,$user,$agentId,$conversationId,$runId),
            JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }
    if ($action === 'deliver_updates') {
        echo json_encode(['ok'=>true]+agent_connection_gmail_inbox_update_deliver_v1($pdo,$user,$agentId,$conversationId),
            JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }
    if ($action === 'analyze_recent') {
        agent_connection_gmail_inbox_analyze_recent_v1($pdo,$user,$agentId,$conversationId);
        echo json_encode(['ok'=>true],JSON_THROW_ON_ERROR);
        return;
    }

    if ($action === 'open_item') {
        $opened=agent_connection_gmail_inbox_open_item_v1($pdo,$user,$itemRef,includeLinks:true);
        require_once dirname(__DIR__).'/includes/agent-connection-gmail-link-engagement-v1.php';
        $opened=vp3_gmail_chat_link_tokens_v1($opened,agent_connection_gmail_inbox_bound_scope_v1($pdo,$user),0,0,0,$itemRef);
        require_once dirname(__DIR__).'/includes/agent-curiosity-email-activity-v1.php';
        vp3_curiosity_email_open_observe_v1($pdo,$user,$itemRef,$opened);
        echo json_encode(['ok'=>true,'item'=>$opened],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }
    if ($action === 'find_matches') {
        echo json_encode(['ok'=>true]+agent_connection_gmail_inbox_find_matches_v1($pdo,$user,$legacyRef),
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }
    if ($action === 'open_match') {
        echo json_encode(['ok'=>true,'item'=>agent_connection_gmail_inbox_open_match_v1($pdo,$user,$legacyRef,$messageId,$matchToken)],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }
    if ($action === 'start') agent_connection_gmail_inbox_initial_start_v1($pdo, $user, $days, $startToken);
    if ($action === 'advance') agent_connection_gmail_inbox_initial_advance_v1($pdo, $user, $runId);
    if ($action === 'check_overdue') {
        require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-status-v1.php';
        agent_connection_gmail_inbox_check_overdue_v1($pdo,$user,$checkRef,
            chatTarget:$checkChat ? ['agent_id'=>$agentId,'conversation_id'=>$conversationId] : null);
    }
    $review = agent_connection_gmail_inbox_initial_view_v1($pdo, $user, $body['include_report'] ?? false,
        includeChatResult: $body['include_chat_result'] ?? false, includeLegacyMatches: true);
    echo json_encode(['ok'=>true, 'review'=>$review], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    $status = (int)$error->getCode();
    if (!in_array($status, [401, 403, 404, 405, 409, 413, 415, 419, 422, 429, 503], true)) $status = 503;
    http_response_code($status);
    echo json_encode(['ok'=>false, 'error'=>match ($status) {
        401, 403 => 'Open Inbox Review in your signed-in account.',
        404, 409 => 'The inbox review or account changed. Refresh and try again.',
        405 => 'Use POST for Inbox Review.',
        413 => 'The review request is too large.',
        415 => 'Use a JSON review request.',
        419 => 'Your session expired. Refresh and try again.',
        422 => 'Choose a valid inbox review request.',
        429 => 'Inbox Review is busy. Try again shortly.',
        default => 'Inbox Review is temporarily unavailable.',
    }], JSON_THROW_ON_ERROR);
}
