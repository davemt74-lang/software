<?php
declare(strict_types=1);

/** Only recognize natural continuations when the browser points to the offer it displayed. */
function agent_connection_email_offer_request_v1(string $query, mixed $messageId): ?array
{
    if (!is_int($messageId) || $messageId < 1) return null;
    $query = trim($query);
    $command = rtrim($query,'.?!');
    if (preg_match('/\A(?:yes(?: please|, please|,? go ahead)?|sure|please do|go ahead)\z/iu',$command)) {
        return ['message_id'=>$messageId,'intent'=>'accept','instruction'=>''];
    }
    if (preg_match('/\A(?:please )?(?:open|read) (?:it|(?:that|the|this) (?:urgent )?(?:email|message))(?: (to me|aloud|out loud))?\z/iu',$command,$match)) {
        return ['message_id'=>$messageId,'intent'=>'read','instruction'=>'','read_aloud'=>!empty($match[1])];
    }
    if (preg_match('/\A(?:please )?(?:draft (?:a |the )?reply|draft it|reply to (?:it|that email))(?:\s*:\s*([^\p{Cc}\p{Zl}\p{Zp}]{1,600}))?\z/iu',$query,$match)
        || preg_match('/\A(?:please )?(?:draft (?:a |the )?reply|draft it|reply to (?:it|that email))\z/iu',$command,$match)) {
        return ['message_id'=>$messageId,'intent'=>'draft','instruction'=>trim($match[1] ?? '')];
    }
    if (preg_match('/\A(?:please )?(tell (?:him|her|them) [^\p{Cc}\p{Zl}\p{Zp}]{1,570})\z/iu',$query,$match)) {
        return ['message_id'=>$messageId,'intent'=>'draft','instruction'=>$match[1]];
    }
    return null;
}

function agent_connection_email_offer_resolve_v1(PDO $pdo,array $user,array $principal,int $conversation,int $userMessage,array $request): array
{
    $owner = (int)$user['id'];
    $agent = (int)($principal['agent_id'] ?? 0);
    $anchor = $request['message_id'];
    $prior = $pdo->prepare("SELECT id,role,user_id,context_json FROM chat_messages
        WHERE conversation_id=? AND id<? ORDER BY id DESC LIMIT 1");
    $stale = static fn(): DomainException => new DomainException(
        'That email offer is no longer current. Open the exact Gmail item to continue.',409);
    $turn = $pdo->prepare("SELECT 1 FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");
    $turn->execute([$userMessage,$conversation,$owner]);
    if (!$turn->fetchColumn()) throw $stale();
    $prior->execute([$conversation,$userMessage]);
    $previous = $prior->fetch(PDO::FETCH_ASSOC);
    if (!$previous || (int)$previous['id'] !== $anchor || $previous['role'] !== 'assistant' || $previous['user_id'] !== null) {
        throw $stale();
    }
    $context = json_decode((string)$previous['context_json'],true,24);
    if (!is_array($context)) throw $stale();
    $reply = $context['gmail_reply_offer_v1'] ?? null;
    $offerMessage = $anchor;
    if ($reply !== null) {
        if (!is_array($reply) || !is_int($reply['offer_message_id'] ?? null)
            || !is_int($context['chat_user_message_id'] ?? null)
            || $reply['offer_message_id'] >= $context['chat_user_message_id']
            || $context['chat_user_message_id'] >= $anchor) throw $stale();
        $turn->execute([$context['chat_user_message_id'],$conversation,$owner]);
        if (!$turn->fetchColumn()) throw $stale();
        $offerMessage = $reply['offer_message_id'];
        $prior->execute([$conversation,$context['chat_user_message_id']]);
        $original = $prior->fetch(PDO::FETCH_ASSOC);
        if (!$original || (int)$original['id'] !== $offerMessage || $original['role'] !== 'assistant' || $original['user_id'] !== null) {
            throw $stale();
        }
        $context = json_decode((string)$original['context_json'],true,24);
        if (!is_array($context)) throw $stale();
    }
    if (($context['source'] ?? null) !== 'agent_connection_gmail_inbox_update') throw $stale();
    require_once __DIR__.'/agent-connection-gmail-inbox-initial-v1.php';
    $scope = agent_connection_gmail_inbox_initial_scope_v1($pdo,$user);
    if ($scope['state'] !== 'ready' || !agent_connection_gmail_inbox_chat_target_v1($pdo,$scope,$agent,$conversation)) throw $stale();
    $view = agent_connection_gmail_inbox_update_history_v1($pdo,$scope,$agent,$conversation,$context,$offerMessage);
    $item = $view === null ? null : agent_connection_gmail_inbox_urgent_offer_v1($view);
    if ($item === null) throw $stale();
    if ($reply !== null && in_array($request['intent'],['accept','draft'],true)) {
        return ['operation'=>'reply','mode'=>'draft','item_ref'=>$item['item_ref'],'instruction'=>$request['instruction']];
    }
    if ($request['intent'] === 'draft') throw $stale();
    return ['operation'=>'read_offer','item_ref'=>$item['item_ref'],'offer_message_id'=>$offerMessage,
        'read_aloud'=>!empty($request['read_aloud'])];
}
