<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-youtube-chat-v1.php';

/** Recent explicit artist requests provide an owner-entered research subject. */
function vp3_initiative_artist_evidence_v1(PDO $pdo, array $scope, DateTimeImmutable $now, ?array $selectedRefs, array &$coverage): array
{
    if ($selectedRefs !== null && !array_filter($selectedRefs, static fn($ref): bool =>
        is_string($ref) && str_starts_with($ref, 'artist-interest:'))) return [];

    $owner = (int)$scope['user']['id'];
    $agent = (int)$scope['agent_id'];
    if ($owner < 1 || $agent < 0 || !has_permission('account.access', $scope['user'])
        || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1() !== null)
        || !agent_connection_youtube_enabled_v1() || !agent_connection_youtube_consent_v1($pdo, $owner)
        || !vp3_youtube_artist_research_enabled_v1($pdo, $owner)) {
        $coverage['artist_research'] = 'forbidden';
        return [];
    }
    if (!agent_connection_youtube_results_ready_v1($pdo)) {
        $coverage['artist_research'] = 'unavailable';
        return [];
    }

    try {
        $records = agent_connection_youtube_result_records_v1($pdo, $owner, $agent, limit:24);
        $coverage['artist_research'] = 'ready';
        $evidence = [];
        foreach ($records as $record) {
            $payload = $record['results'];
            $artist = trim(is_string($payload['taste_topic'] ?? null) ? $payload['taste_topic'] : '');
            if (($payload['suggestion_kind'] ?? null) !== 'similar' || $artist === '' || mb_strlen($artist) > 100
                || preg_match('~https?://|[<>]|\p{C}~iu', $artist)) continue;
            $recorded = $record['created_at_epoch'];
            $age = $now->getTimestamp() - $recorded;
            if ($age < 0 || $age >= 86400) continue;

            $topic = hash('sha256', mb_strtolower($artist));
            $ref = 'artist-interest:'.$topic;
            if (isset($evidence[$ref]) || ($selectedRefs !== null && !in_array($ref, $selectedRefs, true))) continue;
            $evidence[$ref] = [
                'ref'=>$ref, 'kind'=>'artist_interest', 'title'=>$artist,
                'detail'=>'The owner recently asked for YouTube artists similar to this artist.',
                'url'=>url('/chat.php?agent='.($agent ?: 'system').'&conversation_id='.(int)$record['conversation_id']),
                'revision'=>hash('sha256', implode('|', [$owner, $agent, $topic, gmdate('Y-m-d',$recorded)])),
            ];
            if ($selectedRefs === null && count($evidence) >= 3) break;
        }
        return $evidence;
    } catch (PDOException $error) {
        $coverage['artist_research'] = 'unavailable';
        return [];
    }
}

function vp3_initiative_artist_result_v1(array $toolResult): array
{
    $output = $toolResult['output'] ?? [];
    return [
        'status'=>$output['status'] ?? '', 'reason'=>$output['reason'] ?? '',
        'answer'=>$toolResult['draft'] ?? '', 'sources'=>$toolResult['sources'] ?? [],
        'checked_at'=>$output['checked_at'] ?? '',
    ];
}
