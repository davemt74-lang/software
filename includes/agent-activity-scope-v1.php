<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/agent-capability-chat-v1.php';
require_once __DIR__.'/participant-updates-v1.php';
if (!function_exists('knowledge_retrieval_v162_owner_session')) require_once __DIR__.'/knowledge-retrieval-v162.php';

function vp3_agent_activity_text_v1(mixed $value, int $limit = 240): string
{
    return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]|\s+/u', ' ', strip_tags(is_scalar($value) ? (string)$value : '')) ?? ''), 0, $limit);
}

function vp3_agent_activity_scope_v1(PDO $pdo, array $user, int $agentId, int $conversationId): array
{
    if ($agentId < 0 || $conversationId < 0 || (int)($user['id'] ?? 0) < 1 || vp3_participant_scope_v1() !== null) {
        throw new RuntimeException('Private Agent Chat access is required.', 403);
    }
    try {
        $agent = vp3_agent_chat_resolve_agent_v380($pdo, $user, $agentId);
    } catch (PDOException $error) {
        throw $error;
    } catch (RuntimeException) {
        throw new RuntimeException('This Agent is unavailable.', 403);
    }
    $principal = vp3_agent_chat_principal_v380($user, $agent);
    $live = vp3_capability_live_user_v1($pdo, $user, $principal, ['conversation_id'=>$conversationId], null);
    if (!has_permission('account.access', $live) || !knowledge_retrieval_v162_owner_session($live, $principal)) {
        throw new RuntimeException('Private Agent Chat access changed.', 403);
    }
    return ['user'=>$live, 'principal'=>$principal, 'agent'=>$agent, 'agent_id'=>$agentId, 'conversation_id'=>$conversationId];
}

function vp3_agent_activity_chat_href_v1(int $agentId, int $conversationId = 0): string
{
    return url('/chat.php?agent='.($agentId > 0 ? $agentId : 'system').($conversationId > 0 ? '&conversation_id='.$conversationId : ''));
}
