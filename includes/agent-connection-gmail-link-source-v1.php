<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-gmail-inbox-initial-v1.php';

function agent_connection_gmail_link_url_v1(string $url): bool
{
    if (strlen($url)>1800 || !preg_match('~\Ahttps?://~iD',$url)
        || preg_match('~[\s\p{C}<>\x5c\x22\x27\x60]~u',$url) !== 0
        || !filter_var($url,FILTER_VALIDATE_URL)) return false;
    $parts = parse_url($url);
    return is_string($parts['host'] ?? null) && !isset($parts['user']) && !isset($parts['pass']);
}

/** Compare a complete displayed URL, including its path, query and fragment. */
function agent_connection_gmail_link_urls_v1(string $text): array
{
    $pattern = <<<'REGEX'
~(?<![\p{L}\p{N}_./:%@=?&#-])https?://[^\s\p{C}<>"'`“”‘’]+~iu
REGEX;
    if (!preg_match_all($pattern,$text,$matches)) return [];
    $urls = [];
    foreach ($matches[0] as $match) {
        if (str_ends_with($match,'...') || str_ends_with($match,'…')) continue;
        $url = rtrim($match,'.!,;');
        foreach ([')'=>'(',']'=>'[','}'=>'{'] as $end=>$start) {
            while (str_ends_with($url,$end) && substr_count($url,$end)>substr_count($url,$start)) {
                $url = rtrim(substr($url,0,-1),'.!,;');
            }
        }
        if (agent_connection_gmail_link_url_v1($url)) $urls[$url] = true;
    }
    return array_keys($urls);
}

/** Extract link descriptors from the exact provider message already opened by its owner. */
function agent_connection_gmail_link_message_urls_v1(array $providerMessage): array
{
    $limit = 20;
    $plain = $html = [];
    agent_connection_gmail_body_parts_v1((array)($providerMessage['payload'] ?? []),$plain,$html);
    $links = [];
    if (class_exists('DOMDocument')) {
        foreach ($html as $part) {
            if (strlen($part)>150000) continue;
            $document = new DOMDocument();
            if (!$document->loadHTML('<?xml encoding="UTF-8">'.$part,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)) continue;
            foreach ($document->getElementsByTagName('a') as $anchor) {
                $url = trim($anchor->getAttribute('href'));
                if (isset($links[$url]) || !agent_connection_gmail_link_url_v1($url)) continue;
                $label = trim(preg_replace('/[\s\p{C}]+/u',' ',$anchor->textContent) ?? '');
                $links[$url] = ['url'=>$url,'label'=>mb_substr($label !== '' ? $label : $url,0,160,'UTF-8')];
                if (count($links)===$limit) return array_values($links);
            }
        }
    }
    $message = agent_connection_gmail_read_message_v1($providerMessage);
    foreach ([$message['subject'],mb_substr($message['body'],0,6000,'UTF-8')] as $field) {
        foreach (agent_connection_gmail_link_urls_v1($field) as $url) {
            $links[$url] ??= ['url'=>$url,'label'=>$url];
            if (count($links)===$limit) return array_values($links);
        }
    }
    return array_values($links);
}

/** Resolve only private, current Gmail content actually projected for the saved Chat message. */
function vp3_gmail_chat_link_source_v1(PDO $pdo,array $user,int $agentId,int $conversationId,
    int $chatMessageId,?string $itemRef): array
{
    $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$user);
    if ($scope['state'] !== 'ready' || !$scope['provider_ready'] || $scope['generation'] === null
        || !agent_connection_gmail_inbox_chat_target_v1($pdo,$scope,$agentId,$conversationId)) {
        throw new RuntimeException('A private Gmail Chat is required.',403);
    }
    $query = $pdo->prepare("SELECT message,context_json FROM chat_messages
        WHERE id=? AND conversation_id=? AND user_id IS NULL AND role='assistant'");
    $query->execute([$chatMessageId,$conversationId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    $context = json_decode((string)($row['context_json'] ?? ''),true,32);
    $source = $context['source'] ?? null;

    if ($source === 'agent_connection_gmail_inbox_update' && $itemRef !== null) {
        $view = agent_connection_gmail_inbox_update_history_v1($pdo,$scope,$agentId,$conversationId,$context,$chatMessageId);
        foreach ($view['review']['items'] ?? [] as $item) {
            if (($item['item_ref'] ?? null) !== $itemRef || agent_connection_gmail_inbox_ignored_v1($item,$scope['policy']['rules'])) continue;
            return ['scope'=>$scope,'item_ref'=>$itemRef,'kind'=>!empty($view['routine_digest']) ? 'digest' : 'inbox_update',
                'fields'=>[$item['subject'],(string)($item['preview'] ?? '')]];
        }
    } elseif ($source === 'agent_initiative' && ($context['initiative_output']['type'] ?? null) === 'initiative_coordination') {
        require_once __DIR__.'/agent-initiative-followthrough-v1.php';
        require_once __DIR__.'/agent-coordination-work-v1.php';
        $principal = vp3_initiative_principal_v1($pdo,$user,$agentId);
        $origin = vp3_initiative_followthrough_origin_load_v1($pdo,$user,$principal,$conversationId,$chatMessageId);
        $goal = $origin['goal'];
        if (($goal['tool'] ?? null) === 'coordination.review'
            && str_starts_with((string)($goal['primary'] ?? ''),'gmail:')
            && ($origin['state']['coordination_source_changed'] ?? false) !== true) {
            $evidence = vp3_coordination_result_evidence_v1($pdo,$origin['user'],$goal['binding'],$goal,$origin['result']['initiative_tool_result']);
            $action = $evidence[$goal['primary']]['source_action'] ?? [];
            $ref = $action['item_ref'] ?? null;
            if (($action['type'] ?? null) === 'gmail_triage' && is_string($ref) && ($itemRef === null || $ref === $itemRef)
                && ($action['generation'] ?? null) === $scope['generation']) {
                return ['scope'=>$scope,'item_ref'=>$ref,'kind'=>'coordination','fields'=>[(string)$origin['message']['message']]];
            }
        }
    } elseif ($source === 'agent_connection_gmail_content' && $itemRef === null
        && $row['message'] === VP3_GMAIL_CONTENT_CHAT_MARKER_V1 && is_array($context['gmail_content_chat_v1'] ?? null)) {
        $text = agent_connection_gmail_content_chat_text_v1($pdo,$scope,$agentId,$conversationId,$context['gmail_content_chat_v1']);
        if ($text !== null) return ['scope'=>$scope,'item_ref'=>null,'kind'=>'legacy','fields'=>[$text]];
    }
    throw new RuntimeException('The Gmail Chat source is no longer available.',404);
}

function agent_connection_gmail_link_source_v1(PDO $pdo,array $user,int $agentId,int $conversationId,
    int $chatMessageId,?string $itemRef,string $url): array
{
    if (!agent_connection_gmail_link_url_v1($url)) throw new RuntimeException('Choose a valid Gmail link.',422);
    $source = vp3_gmail_chat_link_source_v1($pdo,$user,$agentId,$conversationId,$chatMessageId,$itemRef);
    foreach ($source['fields'] as $field) {
        if (in_array($url,agent_connection_gmail_link_urls_v1($field),true)) return $source+['url'=>$url];
    }
    throw new RuntimeException('The link does not match the saved Gmail message.',404);
}
