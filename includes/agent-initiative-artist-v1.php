<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-youtube-chat-v1.php';
require_once __DIR__.'/agent-public-research-plan-v1.php';

/** Recent explicit artist requests provide an owner-entered research subject. */
function vp3_initiative_research_evidence_v1(PDO $pdo, array $scope, DateTimeImmutable $now, ?array $selectedRefs, array &$coverage): array
{
    $kinds=[];
    foreach(['artist','topic'] as $kind){
        if ($selectedRefs === null || array_filter($selectedRefs, static fn($ref): bool =>
            is_string($ref) && str_starts_with($ref, $kind.'-interest:'))) $kinds[$kind]=true;
    }
    if (!$kinds) return [];

    $owner = (int)$scope['user']['id'];
    $agent = (int)$scope['agent_id'];
    $allowed=$owner > 0 && $agent >= 0 && has_permission('account.access', $scope['user'])
        && (!function_exists('vp3_participant_scope_v1') || vp3_participant_scope_v1() === null)
        && agent_connection_youtube_enabled_v1() && agent_connection_youtube_consent_v1($pdo, $owner);
    foreach(array_keys($kinds) as $kind){
        if($allowed&&vp3_youtube_research_enabled_v1($pdo,$owner,$kind))continue;
        $coverage[$kind.'_research']='forbidden';
        unset($kinds[$kind]);
    }
    if (!$kinds) return [];
    if (!agent_connection_youtube_results_ready_v1($pdo)) {
        foreach($kinds as $kind=>$_)$coverage[$kind.'_research']='unavailable';
        return [];
    }

    try {
        $records = agent_connection_youtube_result_records_v1($pdo, $owner, $agent, limit:24);
        foreach($kinds as $kind=>$_)$coverage[$kind.'_research']='ready';
        $evidence = [];
        $counts=['artist'=>0,'topic'=>0];
        foreach ($records as $record) {
            $payload = $record['results'];
            $kind=($payload['suggestion_kind']??null)==='similar'?'artist':'topic';
            if(!isset($kinds[$kind])||($selectedRefs===null&&$counts[$kind]>=3))continue;
            if($kind==='artist'){
                $subject=trim(is_string($payload['taste_topic']??null)?$payload['taste_topic']:'');
                if($subject===''||mb_strlen($subject)>100||preg_match('~https?://|[<>]|\p{C}~iu',$subject))continue;
            }else{
                $subject=vp3_topic_research_youtube_subject_v1($payload);
                if($subject==='')continue;
            }
            $recorded = $record['created_at_epoch'];
            $age = $now->getTimestamp() - $recorded;
            if ($age < 0 || $age >= 86400) continue;

            $topic = hash('sha256', mb_strtolower($subject));
            $ref = $kind.'-interest:'.$topic;
            if (isset($evidence[$ref]) || ($selectedRefs !== null && !in_array($ref, $selectedRefs, true))) continue;
            $evidence[$ref] = [
                'ref'=>$ref, 'kind'=>$kind.'_interest', 'title'=>$subject,
                'detail'=>$kind==='artist'?'The owner recently asked for YouTube artists similar to this artist.'
                    :'The owner recently searched YouTube for this topic. Research can add a useful source-backed next step when warranted.',
                'url'=>url('/chat.php?agent='.($agent ?: 'system').'&conversation_id='.(int)$record['conversation_id']),
                'revision'=>hash('sha256', implode('|', [$owner, $agent, $topic, gmdate('Y-m-d',$recorded)])),
            ];
            $counts[$kind]++;
            if ($selectedRefs !== null && count($evidence)===count($selectedRefs)) break;
        }
        return $evidence;
    } catch (PDOException $error) {
        foreach($kinds as $kind=>$_)$coverage[$kind.'_research']='unavailable';
        return [];
    }
}

function vp3_initiative_research_result_v1(array $toolResult): array
{
    $output = $toolResult['output'] ?? [];
    return [
        'status'=>$output['status'] ?? '', 'reason'=>$output['reason'] ?? '',
        'answer'=>$toolResult['draft'] ?? '', 'sources'=>$toolResult['sources'] ?? [],
        'checked_at'=>$output['checked_at'] ?? '',
    ];
}
