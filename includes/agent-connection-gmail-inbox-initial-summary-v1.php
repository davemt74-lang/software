<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-model-v1.php';
require_once __DIR__.'/ai-service-usage-v1.php';

/**
 * Summarize a bounded header sample after the owner authorizes Cloud AI.
 * The caller owns the durable summary claim and verifies it at publication.
 */
function agent_connection_gmail_inbox_initial_summary_v1(
    PDO $pdo, array $user, array $report, ?callable $beforeInference = null
): array {
    $unavailable = ['status'=>'unavailable','summary'=>'','tokens_charged'=>0];
    $owner = (int)($user['id'] ?? 0);
    if ($owner < 1 || $pdo->inTransaction()) return $unavailable;
    if ((int)($report['total'] ?? 0) < 1) return ['status'=>'no_messages','summary'=>'','tokens_charged'=>0];
    if (!vp3_ai_service_usage_ready_v1($pdo) || !is_array($report['items'] ?? null)) return $unavailable;

    $headerText = static function (mixed $value): string {
        if (!is_string($value)) return '';
        $clean = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', strip_tags($value)) ?? '';
        return mb_strcut(trim($clean), 0, 180, 'UTF-8');
    };
    $headers = [];
    foreach (array_slice($report['items'], 0, 8) as $item) {
        if (!is_array($item)) continue;
        $from = $headerText($item['from'] ?? null);
        $subject = $headerText($item['subject'] ?? null);
        if ($from === '' && $subject === '') continue;
        $category = $item['category'] ?? '';
        $headers[] = [
            'ref'=>count($headers)+1,
            'from'=>$from,
            'subject'=>$subject,
            'category'=>in_array($category,['new','important','reply'],true) ? $category : 'new',
        ];
    }
    if (!$headers) return $unavailable;

    $protocol = 'vp3.gmail-inbox-initial-summary.v1';
    $service = ['service'=>'initiative','background'=>true,'stage'=>$protocol];
    $before = static function () use ($pdo,$user,$beforeInference): bool {
        try {
            if ($beforeInference !== null && $beforeInference() === false) return false;
            $health = vp3_initiative_inference_health_v1($pdo,$user,0,true);
            return !empty($health['ready']) && ($health['route'] ?? null) === 'vp3_cloud';
        } catch (Throwable $error) {
            return false;
        }
    };
    $unverified = ['status'=>'unverified','summary'=>'','tokens_charged'=>null];
    try {
        $prompt = json_encode(['sampled_gmail_headers'=>$headers], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $result = vp3_ai_with_budget_v1(18, static fn() => ai_v100_with_service_context($service,
            static fn() => ai_generate_chat_response($prompt,[],[],$user,'main_ai',[
                'surface'=>'tool_json','protocol'=>$protocol,'before_gmail_summary'=>$before,
            ])));
        $attempts = $result['service_attempt_ids'] ?? [];
        if (!$attempts) return empty($result['ok']) ? $unavailable : $unverified;
        if (count($attempts) !== 1) return $unverified;
        $receipt = $pdo->prepare("SELECT service_status,usage_known,cloud_tokens_charged FROM ai_execution_ledger
            WHERE id=? AND user_id=? AND service_key='initiative' AND service_stage=? AND is_background=1");
        $receipt->execute([$attempts[0],$owner,$protocol]);
        $attempt = $receipt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) return $unverified;
        if ($attempt['service_status'] === 'rejected') return $unavailable;
        if (empty($attempt['usage_known'])) return $unverified;
        $unverified['tokens_charged'] = (int)$attempt['cloud_tokens_charged'];
        if (empty($result['ok']) || $attempt['service_status'] !== 'completed') return $unverified;
        $summary = agent_connection_gmail_inbox_summary_text_v1((string)($result['answer'] ?? ''),count($headers));
        return ['status'=>'ready','summary'=>$summary,'tokens_charged'=>$unverified['tokens_charged']];
    } catch (Throwable $error) {
        return $unverified;
    }
}

/** Convert verified theme selections into plain text for the saved review. */
function agent_connection_gmail_inbox_summary_text_v1(string $answer, int $headerCount): string
{
    $object = vp3_tool_json_object_v1($answer,['topics'],1800);
    $topics = $object['topics'] ?? null;
    if (!is_array($topics) || !array_is_list($topics) || count($topics)>3) {
        throw new InvalidArgumentException('Invalid Gmail summary themes.');
    }
    $labels = [];
    foreach ($topics as $topic) {
        $theme = $topic['theme'] ?? null;
        $refs = $topic['refs'] ?? null;
        if (!is_array($topic) || array_diff(array_keys($topic),['theme','refs'])
            || !is_string($theme) || !isset(VP3_GMAIL_INBOX_SUMMARY_TOPICS_V1[$theme])
            || isset($labels[$theme]) || !is_array($refs) || !array_is_list($refs)
            || !$refs || count($refs)>$headerCount) {
            throw new InvalidArgumentException('Invalid Gmail summary theme.');
        }
        foreach ($refs as $ref) {
            if (!is_int($ref) || $ref<1 || $ref>$headerCount) {
                throw new InvalidArgumentException('Invalid Gmail summary reference.');
            }
        }
        $labels[$theme] = VP3_GMAIL_INBOX_SUMMARY_TOPICS_V1[$theme];
    }
    if (!$labels) return 'The sampled subjects did not suggest a clear theme.';
    $last = array_pop($labels);
    $themes = match (count($labels)) {
        0=>$last,
        1=>reset($labels).' and '.$last,
        default=>implode(', ',$labels).', and '.$last,
    };
    return 'The sampled subjects suggest '.$themes.'.';
}
